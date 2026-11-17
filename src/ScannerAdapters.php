<?php
/**
 * Registry of scanner adapters (filter wp_pass_captain_scanner_adapters_v1).
 *
 * A scanner adapter lets an add-on handle codes that are not Pass Captain tickets in the core scanners
 * (web scanner, mobile API, everything that goes through Checkin::perform()/preview()), e.g. codes of
 * an external access system or membership cards:
 *
 *     add_filter( 'wp_pass_captain_scanner_adapters_v1', function ( $adapters ) {
 *         $adapters['my_cards'] = array(
 *             'label'      => 'Mitgliedskarten',
 *             'recognizes' => fn ( $code ) => str_starts_with( $code, 'MC-' ),
 *             'check_in'   => fn ( $code, $context ) => array( 'status' => 'checked_in', 'label' => '…' ),
 *             'preview'    => fn ( $code, $context ) => array( 'status' => 'valid' ), // optional
 *             'capability' => 'wpcap_checkin',                                         // optional
 *         );
 *         return $adapters;
 *     } );
 *
 * Core tickets always win: an adapter is only asked for codes that are no Pass Captain ticket. The
 * first adapter (in filter order) whose `recognizes` returns true handles the code. Callbacks return an
 * array (`status` required) or a WP_Error; exceptions become a WP_Error. The result carries `adapter`.
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ScannerAdapters {
	/**
	 * Valid registered adapters.
	 *
	 * @return array<string,array{label:string,recognizes:callable,check_in:callable,preview:callable|null,capability:string}>
	 */
	public static function all() {
		$adapters = apply_filters( 'wp_pass_captain_scanner_adapters_v1', array() );
		if ( ! is_array( $adapters ) ) { return array(); }
		$out = array();
		foreach ( $adapters as $id => $def ) {
			$id = sanitize_key( (string) $id );
			if ( '' === $id || 'core' === $id || ! is_array( $def ) || ! isset( $def['recognizes'], $def['check_in'] ) || ! is_callable( $def['recognizes'] ) || ! is_callable( $def['check_in'] ) ) { continue; }
			$out[ $id ] = array(
				'label'      => (string) ( $def['label'] ?? $id ),
				'recognizes' => $def['recognizes'],
				'check_in'   => $def['check_in'],
				'preview'    => isset( $def['preview'] ) && is_callable( $def['preview'] ) ? $def['preview'] : null,
				'capability' => (string) ( $def['capability'] ?? 'wpcap_checkin' ),
			);
		}
		return $out;
	}

	/**
	 * Adapter id responsible for a code, or '' (no adapter registered, code is a core ticket or no
	 * adapter recognizes it).
	 */
	public static function for_code( $code ) {
		$adapters = self::all();
		if ( ! $adapters || ! is_scalar( $code ) || '' === trim( (string) $code ) ) { return ''; }
		// Signed Pass Captain QR codes are never handed to adapters, not even when the signature is invalid.
		if ( Security\SignedQr::is_signed( $code ) ) { return ''; }
		if ( ! is_wp_error( Checkin::lookup( (string) $code, null, false ) ) ) { return ''; }
		foreach ( $adapters as $id => $adapter ) {
			try {
				if ( true === (bool) call_user_func( $adapter['recognizes'], (string) $code ) ) { return $id; }
			} catch ( \Throwable $e ) {
				continue;
			}
		}
		return '';
	}

	/** Runs the adapter's check-in. */
	public static function check_in( $id, $code, array $context = array() ) {
		return self::run( $id, 'check_in', $code, $context );
	}

	/** Runs the adapter's preview (without preview callback: a neutral "recognized" answer). */
	public static function preview( $id, $code, array $context = array() ) {
		return self::run( $id, 'preview', $code, $context );
	}

	private static function run( $id, $what, $code, array $context ) {
		$adapters = self::all();
		if ( ! isset( $adapters[ $id ] ) ) {
			return new \WP_Error( 'wpcap_ticket_not_found', __( 'Ticket nicht gefunden.', 'pass-captain' ), array( 'status' => 404, 'result' => 'unknown' ) );
		}
		$adapter = $adapters[ $id ];
		if ( ! current_user_can( $adapter['capability'] ) ) {
			return new \WP_Error( 'wpcap_scanner_adapter_forbidden', __( 'Keine Berechtigung für diesen Code.', 'pass-captain' ), array( 'status' => 403, 'adapter' => $id ) );
		}
		if ( 'preview' === $what && null === $adapter['preview'] ) {
			return array( 'status' => 'recognized', 'adapter' => $id, 'label' => $adapter['label'] );
		}
		try {
			$result = call_user_func( $adapter[ $what ], (string) $code, $context );
		} catch ( \Throwable $e ) {
			Log::warning( 'scanner_adapter_error', sprintf( 'scanner adapter %s (%s) threw: %s', $id, $what, $e->getMessage() ), array( 'details' => array( 'adapter' => $id, 'step' => $what ) ) );
			$result = null;
		}
		if ( is_wp_error( $result ) ) {
			return new \WP_Error( $result->get_error_code(), $result->get_error_message(), array_merge( array( 'status' => 409 ), (array) $result->get_error_data(), array( 'adapter' => $id ) ) );
		}
		if ( ! is_array( $result ) || empty( $result['status'] ) ) {
			return new \WP_Error( 'wpcap_scanner_adapter_failed', __( 'Der Code konnte nicht geprüft werden. Bitte erneut scannen.', 'pass-captain' ), array( 'status' => 503, 'adapter' => $id ) );
		}
		return array_merge( $result, array( 'adapter' => $id ) );
	}
}

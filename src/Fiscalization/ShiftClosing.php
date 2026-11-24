<?php
/**
 * Connects the box office Z report with the fiscalization module (Phase 3: "Z-Bericht-Hooks mit der
 * Fiskalisierung verbinden").
 *
 * - Action `wpcap_boxoffice_shift_closed`: only with the box office setting "boxoffice_fiscal_closing"
 *   (default off, so nothing changes for existing installations). Every legal seller of the shift (the
 *   sellers of its fiscal receipts plus the sellers with an active production POS profile for the shift's
 *   location/register) is closed at its enabled provider when the adapter implements
 *   ClosingAdapterInterface: one call per (Z report, seller) with a stable idempotency key, no retry. The
 *   outcome is appended to the fiscal event log as event "shift_closing" (document_id 0, order_id 0) with
 *   status submitted, failed, unsupported or unavailable; a failure is also logged (support log and audit log).
 *   The shift itself is already closed and stays closed.
 * - Filter `wpcap_boxoffice_zreport_html_extra`: section "Fiskalisierung" per seller with provider,
 *   confirmed/open receipts of the shift, closing status, closing reference and signature. Shifts without
 *   fiscal receipts or closing events get no section (unchanged Z report).
 *
 * @package PassCaptain
 */
namespace PassCaptain\Fiscalization;

use PassCaptain\Audit;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class ShiftClosing {
	const EVENT = 'shift_closing';

	public static function register() {
		add_action( 'wpcap_boxoffice_shift_closed', array( __CLASS__, 'on_shift_closed' ), 10, 3 );
		add_filter( 'wpcap_boxoffice_zreport_html_extra', array( __CLASS__, 'zreport_html' ), 10, 2 );
	}

	/** Whether a closed shift triggers the provider closing (box office setting, default off). */
	public static function enabled() {
		return (bool) Settings::get( 'boxoffice_fiscal_closing', 0 );
	}

	/**
	 * Fiscal receipts of a shift per seller: seller_id => [provider_id, submitted, open, gross, tax, keys].
	 *
	 * @return array<int,array{provider_id:string,submitted:int,open:int,gross:int,tax:int,keys:string[]}>
	 */
	public static function shift_documents( $shift_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT d.seller_id,d.provider_id,d.status,d.document_key,d.gross_minor,d.tax_minor FROM %i d INNER JOIN %i s ON s.order_id=d.order_id WHERE s.session_id=%d AND s.order_id>0 ORDER BY d.document_id LIMIT 5000', $p . 'wpcap_fiscal_documents', $p . 'wpcap_boxoffice_sales', absint( $shift_id ) ) );
		$out = array();
		foreach ( (array) $rows as $row ) {
			$seller_id = (int) $row->seller_id;
			if ( ! isset( $out[ $seller_id ] ) ) { $out[ $seller_id ] = array( 'provider_id' => (string) $row->provider_id, 'submitted' => 0, 'open' => 0, 'gross' => 0, 'tax' => 0, 'keys' => array() ); }
			if ( 'submitted' === $row->status ) {
				++$out[ $seller_id ]['submitted'];
				$out[ $seller_id ]['gross'] += (int) $row->gross_minor;
				$out[ $seller_id ]['tax'] += (int) $row->tax_minor;
				$out[ $seller_id ]['keys'][] = (string) $row->document_key;
			} else {
				++$out[ $seller_id ]['open'];
			}
		}
		return $out;
	}

	/** Sellers with an active production POS profile for the shift's location/register (in effect now). */
	private static function profile_sellers( $shift ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = \PassCaptain\Clock::mysql();
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT fp.seller_id FROM %i fp INNER JOIN %i s ON s.seller_id=fp.seller_id AND s.status='active' AND s.country_code=fp.country_code WHERE fp.channel='pos' AND fp.environment='production' AND fp.status='active' AND fp.location_id=%d AND fp.register_id=%s AND (fp.effective_from IS NULL OR fp.effective_from<=%s) AND (fp.effective_until IS NULL OR fp.effective_until>=%s)", $p . 'wpcap_fiscal_profiles', $p . 'wpcap_legal_sellers', (int) ( $shift->location_id ?? 0 ), (string) ( $shift->fiscal_register_id ?? '' ), $now, $now ) );
		return array_map( 'intval', (array) $ids );
	}

	/** Stable idempotency key of the closing of one Z report for one seller. */
	public static function idempotency_key( $z, $seller_id ) {
		return hash( 'sha256', 'wpcap-fiscal-closing-v1:' . (int) $z->z_id . ':' . (string) $z->hash . ':' . absint( $seller_id ) );
	}

	/** Closing events of a Z report, newest per seller: seller_id => [status, provider_id, metadata]. */
	public static function events_for( $z_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT provider_id,event_status,metadata FROM %i WHERE document_id=0 AND event_type=%s AND metadata LIKE %s ORDER BY event_id', $wpdb->prefix . 'wpcap_fiscal_events', self::EVENT, '%' . $wpdb->esc_like( '"z_id":' . absint( $z_id ) . ',' ) . '%' ) );
		$out = array();
		foreach ( (array) $rows as $row ) {
			$meta = json_decode( (string) $row->metadata, true );
			if ( ! is_array( $meta ) || (int) ( $meta['z_id'] ?? 0 ) !== absint( $z_id ) ) { continue; }
			$out[ (int) ( $meta['seller_id'] ?? 0 ) ] = array( 'status' => (string) $row->event_status, 'provider_id' => (string) $row->provider_id, 'metadata' => $meta );
		}
		return $out;
	}

	private static function record( $provider_id, $status, array $metadata ) {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'wpcap_fiscal_events', array(
			'document_id' => 0, 'order_id' => 0, 'provider_id' => sanitize_key( (string) $provider_id ),
			'event_type' => self::EVENT, 'event_status' => sanitize_key( $status ),
			'metadata' => wp_json_encode( $metadata ), 'created_at' => \PassCaptain\Clock::mysql(),
		), array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' ) );
	}

	/** Callback of `wpcap_boxoffice_shift_closed`: provider closing per seller (see the class comment). */
	public static function on_shift_closed( $z_id, $z = null, $shift = null ) {
		self::close( $z_id, $z, $shift );
	}

	/**
	 * Provider closing of one Z report per seller (only with the setting on).
	 *
	 * @return array<int,string> seller_id => recorded status (empty when disabled or nothing to close).
	 */
	public static function close( $z_id, $z, $shift ) {
		if ( ! self::enabled() || ! is_object( $z ) || ! is_object( $shift ) ) { return array(); }
		global $wpdb;
		$p = $wpdb->prefix;
		$documents = self::shift_documents( (int) $shift->session_id );
		$seller_ids = array_values( array_unique( array_merge( array_keys( $documents ), self::profile_sellers( $shift ) ) ) );
		$done = self::events_for( (int) $z_id );
		$totals = is_array( $z->totals_data ?? null ) ? $z->totals_data : array();
		$results = array();
		foreach ( $seller_ids as $seller_id ) {
			// A repeated hook (e.g. a re-fired action) never closes a seller twice.
			if ( 'submitted' === ( $done[ $seller_id ]['status'] ?? '' ) ) { continue; }
			$docs = $documents[ $seller_id ] ?? array( 'provider_id' => '', 'submitted' => 0, 'open' => 0, 'gross' => 0, 'tax' => 0, 'keys' => array() );
			$key = self::idempotency_key( $z, $seller_id );
			$meta = array( 'z_id' => (int) $z_id, 'z_number' => (int) $z->z_number, 'shift_id' => (int) $shift->session_id, 'register_id' => (int) $z->register_id, 'seller_id' => (int) $seller_id, 'idempotency_key' => $key, 'receipts_submitted' => (int) $docs['submitted'], 'receipts_open' => (int) $docs['open'] );
			$seller = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE seller_id=%d AND status='active'", $p . 'wpcap_legal_sellers', $seller_id ), ARRAY_A );
			$config = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE seller_id=%d AND enabled=1', $p . 'wpcap_fiscal_configs', $seller_id ), ARRAY_A );
			$provider_id = $config ? (string) $config['provider_id'] : (string) $docs['provider_id'];
			$adapter = $config ? Registry::get( $provider_id ) : null;
			if ( ! $seller || ! $adapter || ! in_array( strtoupper( (string) $seller['country_code'] ), array_map( 'strtoupper', (array) $adapter->countries() ), true ) ) {
				self::record( $provider_id, 'unavailable', $meta );
				$results[ $seller_id ] = 'unavailable';
				continue;
			}
			if ( ! $adapter instanceof ClosingAdapterInterface ) {
				self::record( $provider_id, 'unsupported', $meta );
				$results[ $seller_id ] = 'unsupported';
				continue;
			}
			$closing = array(
				'idempotency_key' => $key,
				'z_id' => (int) $z_id, 'z_number' => (int) $z->z_number, 'z_hash' => (string) $z->hash,
				'register_id' => (int) $z->register_id, 'shift_id' => (int) $shift->session_id,
				'location_id' => (int) ( $shift->location_id ?? 0 ), 'fiscal_register_id' => (string) ( $shift->fiscal_register_id ?? '' ),
				'currency' => (string) $z->currency, 'opened_at' => (string) $z->opened_at, 'closed_at' => (string) $z->closed_at,
				'net_total_minor' => (int) $z->net_total_minor, 'grand_total_minor' => (int) $z->grand_total_minor,
				'methods' => (array) ( $totals['methods'] ?? array() ), 'vat' => (array) ( $totals['vat'] ?? array() ), 'counts' => (array) ( $totals['counts'] ?? array() ),
				'seller_gross_minor' => (int) $docs['gross'], 'seller_tax_minor' => (int) $docs['tax'],
				'documents' => array( 'submitted' => (int) $docs['submitted'], 'open' => (int) $docs['open'] ),
				'document_keys' => $docs['keys'],
			);
			$configuration = $config['configuration'] ? (array) json_decode( (string) $config['configuration'], true ) : array();
			$configuration = Credentials::with_credentials( $configuration, $seller_id, $provider_id );
			try {
				$result = $adapter->close_shift( $closing, $seller, $configuration );
			} catch ( \Throwable $e ) {
				$result = new \WP_Error( 'provider_exception', '' );
			}
			$reference = is_array( $result ) ? sanitize_text_field( (string) ( $result['reference'] ?? '' ) ) : '';
			if ( is_wp_error( $result ) || '' === $reference ) {
				$meta['error_code'] = is_wp_error( $result ) ? sanitize_key( (string) $result->get_error_code() ) : 'closing_reference_missing';
				self::record( $provider_id, 'failed', $meta );
				// Log writes the audit entry (action fiscal_shift_closing_failed) as well.
				\PassCaptain\Log::error( 'fiscal_shift_closing_failed', sprintf( 'Fiscal closing of Z report %d for seller %d failed (%s); check the provider and close manually.', (int) $z_id, (int) $seller_id, $meta['error_code'] ), array( 'object_type' => 'pos_session', 'object_id' => (int) $shift->session_id, 'details' => array( 'provider_id' => $provider_id, 'error_code' => $meta['error_code'] ) ) );
				$results[ $seller_id ] = 'failed';
				continue;
			}
			$meta['reference'] = mb_substr( $reference, 0, 191 );
			$meta['signature'] = mb_substr( sanitize_text_field( (string) ( $result['signature'] ?? '' ) ), 0, 1024 );
			$fields = array();
			foreach ( array_slice( (array) ( $result['fields'] ?? array() ), 0, 20, true ) as $label => $value ) {
				if ( is_scalar( $value ) ) { $fields[ mb_substr( sanitize_text_field( (string) $label ), 0, 60 ) ] = mb_substr( sanitize_text_field( (string) $value ), 0, 255 ); }
			}
			$meta['fields'] = $fields;
			self::record( $provider_id, 'submitted', $meta );
			Audit::record( 'fiscal_shift_closing_submitted', 'pos_session', (int) $shift->session_id, array( 'z_id' => (int) $z_id, 'seller_id' => (int) $seller_id, 'provider_id' => $provider_id, 'reference' => $meta['reference'] ) );
			$results[ $seller_id ] = 'submitted';
		}
		return $results;
	}

	/** Label of a closing status in the Z report. */
	public static function status_label( $status, array $meta = array() ) {
		switch ( $status ) {
			case 'submitted':
				return __( 'Übermittelt', 'pass-captain' );
			case 'failed':
				/* translators: %s: error code */
				return sprintf( __( 'Fehlgeschlagen (%s)', 'pass-captain' ), (string) ( $meta['error_code'] ?? '' ) );
			case 'unsupported':
				return __( 'Vom Anbieter nicht unterstützt', 'pass-captain' );
			case 'unavailable':
				return __( 'Anbieter nicht verfügbar', 'pass-captain' );
			default:
				return __( 'Nicht angefordert', 'pass-captain' );
		}
	}

	/** Callback of `wpcap_boxoffice_zreport_html_extra`: section "Fiskalisierung" (escaped here). */
	public static function zreport_html( $html, $z ) {
		$html = (string) $html;
		if ( ! is_object( $z ) || empty( $z->z_id ) ) { return $html; }
		global $wpdb;
		$documents = self::shift_documents( (int) ( $z->shift_id ?? 0 ) );
		$events = self::events_for( (int) $z->z_id );
		$seller_ids = array_values( array_unique( array_merge( array_keys( $documents ), array_keys( $events ) ) ) );
		if ( ! $seller_ids ) { return $html; }
		sort( $seller_ids );
		$out = '<h2>' . esc_html__( 'Fiskalisierung', 'pass-captain' ) . '</h2>';
		foreach ( $seller_ids as $seller_id ) {
			$name = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT legal_name FROM %i WHERE seller_id=%d', $wpdb->prefix . 'wpcap_legal_sellers', $seller_id ) );
			$event = $events[ $seller_id ] ?? null;
			$meta = $event ? (array) $event['metadata'] : array();
			$provider = $event && '' !== $event['provider_id'] ? $event['provider_id'] : (string) ( $documents[ $seller_id ]['provider_id'] ?? '' );
			$rows = array(
				array( __( 'Rechtsträger', 'pass-captain' ), ( '' !== $name ? $name . ' ' : '' ) . '#' . (int) $seller_id ),
				array( __( 'Anbieter', 'pass-captain' ), '' !== $provider ? $provider : '–' ),
				/* translators: 1: confirmed receipts, 2: open receipts */
				array( __( 'Fiskalbelege dieser Schicht', 'pass-captain' ), sprintf( __( '%1$d bestätigt, %2$d offen', 'pass-captain' ), (int) ( $documents[ $seller_id ]['submitted'] ?? 0 ), (int) ( $documents[ $seller_id ]['open'] ?? 0 ) ) ),
				array( __( 'Kassenabschluss beim Anbieter', 'pass-captain' ), self::status_label( $event ? $event['status'] : '', $meta ) ),
			);
			if ( ! empty( $meta['reference'] ) ) { $rows[] = array( __( 'Abschluss-Referenz', 'pass-captain' ), (string) $meta['reference'] ); }
			if ( ! empty( $meta['signature'] ) ) { $rows[] = array( __( 'Signatur', 'pass-captain' ), (string) $meta['signature'] ); }
			foreach ( (array) ( $meta['fields'] ?? array() ) as $label => $value ) { $rows[] = array( (string) $label, (string) $value ); }
			$out .= '<table class="wpcap-z-fiscal">';
			foreach ( $rows as $row ) {
				$out .= '<tr><td>' . esc_html( $row[0] ) . '</td><td class="n' . ( strlen( $row[1] ) > 40 ? ' small' : '' ) . '">' . esc_html( $row[1] ) . '</td></tr>';
			}
			$out .= '</table>';
		}
		return $html . $out;
	}
}

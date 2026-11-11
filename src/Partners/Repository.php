<?php
/**
 * Partners (organisers/resellers): master data, commission/fee model, linked events and linked
 * WordPress users. An event belongs to at most one partner (primary key on event_id), so sales are
 * never settled twice.
 */
namespace PassCaptain\Partners;

use PassCaptain\Audit;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Repository {
	/** Refund handling of platform fees: reverse commission and per-ticket fee, keep the fixed fee, keep both. */
	const REFUND_POLICIES = array( 'reverse_all', 'keep_fixed_fee', 'keep_all' );
	const STATUSES = array( 'active', 'inactive' );

	public static function refund_policy_labels() {
		return array(
			'reverse_all' => __( 'Provision und Ticketgebühr werden bei Erstattungen storniert', 'wp-pass-captain' ),
			'keep_fixed_fee' => __( 'Provision wird storniert, Ticketgebühr bleibt bestehen', 'wp-pass-captain' ),
			'keep_all' => __( 'Provision und Ticketgebühr bleiben bei Erstattungen bestehen', 'wp-pass-captain' ),
		);
	}

	public static function get( $partner_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_partners WHERE partner_id=%d", absint( $partner_id ) ) );
	}

	public static function all() {
		global $wpdb;
		return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wpcap_partners ORDER BY name, partner_id" );
	}

	/**
	 * Creates or updates a partner.
	 *
	 * @param array $data name, contact_name, contact_email, contact_phone, billing_address, vat_id, payout_details,
	 *                    currency, commission_bp, fee_per_ticket_minor, fee_tax_rate_bp, refund_policy,
	 *                    settlement_start (Y-m-d|''), status, notes.
	 * @return int|\WP_Error Partner id.
	 */
	public static function save( array $data, $partner_id = 0 ) {
		global $wpdb;
		$partner_id = absint( $partner_id );
		$existing = $partner_id ? self::get( $partner_id ) : null;
		if ( $partner_id && ! $existing ) { return new \WP_Error( 'wpcap_partner_missing', __( 'Veranstalter nicht gefunden.', 'wp-pass-captain' ), array( 'status' => 404 ) ); }
		$name = substr( sanitize_text_field( (string) ( $data['name'] ?? '' ) ), 0, 190 );
		if ( '' === $name ) { return new \WP_Error( 'wpcap_partner_name', __( 'Bitte einen Namen angeben.', 'wp-pass-captain' ), array( 'status' => 400 ) ); }
		$email = sanitize_email( (string) ( $data['contact_email'] ?? '' ) );
		if ( '' !== $email && ! is_email( $email ) ) { return new \WP_Error( 'wpcap_partner_email', __( 'Die Kontakt-E-Mail-Adresse ist ungültig.', 'wp-pass-captain' ), array( 'status' => 400 ) ); }
		$currency = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) ( $data['currency'] ?? 'EUR' ) ) );
		if ( 3 !== strlen( $currency ) ) { return new \WP_Error( 'wpcap_partner_currency', __( 'Die Währung muss ein dreistelliger ISO-Code sein.', 'wp-pass-captain' ), array( 'status' => 400 ) ); }
		$commission = (int) ( $data['commission_bp'] ?? 0 );
		$fee_tax = (int) ( $data['fee_tax_rate_bp'] ?? 0 );
		if ( $commission < 0 || $commission > 10000 || $fee_tax < 0 || $fee_tax > 10000 ) { return new \WP_Error( 'wpcap_partner_percent', __( 'Prozentwerte müssen zwischen 0 und 100 liegen.', 'wp-pass-captain' ), array( 'status' => 400 ) ); }
		$fee = (int) ( $data['fee_per_ticket_minor'] ?? 0 );
		if ( $fee < 0 ) { return new \WP_Error( 'wpcap_partner_fee', __( 'Die Ticketgebühr darf nicht negativ sein.', 'wp-pass-captain' ), array( 'status' => 400 ) ); }
		$start = (string) ( $data['settlement_start'] ?? '' );
		if ( '' !== $start && ! \PassCaptain\Reports\Data::valid_date( $start ) ) { return new \WP_Error( 'wpcap_partner_start', __( 'Ungültiges Abrechnungsbeginn-Datum.', 'wp-pass-captain' ), array( 'status' => 400 ) ); }
		$now = current_time( 'mysql', true );
		$row = array(
			'name' => $name,
			'contact_name' => substr( sanitize_text_field( (string) ( $data['contact_name'] ?? '' ) ), 0, 190 ),
			'contact_email' => $email,
			'contact_phone' => substr( sanitize_text_field( (string) ( $data['contact_phone'] ?? '' ) ), 0, 64 ),
			'billing_address' => substr( sanitize_textarea_field( (string) ( $data['billing_address'] ?? '' ) ), 0, 2000 ),
			'vat_id' => substr( sanitize_text_field( (string) ( $data['vat_id'] ?? '' ) ), 0, 64 ),
			'payout_details' => substr( sanitize_textarea_field( (string) ( $data['payout_details'] ?? '' ) ), 0, 2000 ),
			'currency' => $currency,
			'commission_bp' => $commission,
			'fee_per_ticket_minor' => $fee,
			'fee_tax_rate_bp' => $fee_tax,
			'refund_policy' => in_array( $data['refund_policy'] ?? '', self::REFUND_POLICIES, true ) ? $data['refund_policy'] : 'reverse_all',
			'settlement_start' => '' !== $start ? $start : ( $existing ? $existing->settlement_start : gmdate( 'Y-m-01' ) ),
			'status' => in_array( $data['status'] ?? '', self::STATUSES, true ) ? $data['status'] : 'active',
			'notes' => substr( sanitize_textarea_field( (string) ( $data['notes'] ?? '' ) ), 0, 5000 ),
			'updated_at' => $now,
		);
		if ( $existing ) {
			$ok = $wpdb->update( "{$wpdb->prefix}wpcap_partners", $row, array( 'partner_id' => $partner_id ) );
		} else {
			$row['created_at'] = $now;
			$ok = $wpdb->insert( "{$wpdb->prefix}wpcap_partners", $row );
			$partner_id = (int) $wpdb->insert_id;
		}
		if ( false === $ok ) { return new \WP_Error( 'wpcap_partner_save', __( 'Veranstalter konnte nicht gespeichert werden.', 'wp-pass-captain' ), array( 'status' => 500 ) ); }
		Audit::record( $existing ? 'partner_updated' : 'partner_created', 'partner', $partner_id, array( 'commission_bp' => $commission, 'fee_per_ticket_minor' => $fee, 'refund_policy' => $row['refund_policy'], 'status' => $row['status'] ) );
		return $partner_id;
	}

	/** Event ids linked to the partner. */
	public static function event_ids( $partner_id ) {
		global $wpdb;
		return array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT event_id FROM {$wpdb->prefix}wpcap_partner_events WHERE partner_id=%d ORDER BY event_id", absint( $partner_id ) ) ) );
	}

	/** Partner id an event belongs to (0 = none). */
	public static function partner_of_event( $event_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT partner_id FROM {$wpdb->prefix}wpcap_partner_events WHERE event_id=%d", absint( $event_id ) ) );
	}

	/**
	 * Replaces the partner's events. Events already linked to another partner are not moved.
	 *
	 * @return array|\WP_Error ['event_ids' => linked, 'skipped' => ids owned by other partners]
	 */
	public static function set_events( $partner_id, array $event_ids ) {
		global $wpdb;
		$partner_id = absint( $partner_id );
		if ( ! self::get( $partner_id ) ) { return new \WP_Error( 'wpcap_partner_missing', __( 'Veranstalter nicht gefunden.', 'wp-pass-captain' ), array( 'status' => 404 ) ); }
		$event_ids = array_values( array_unique( array_filter( array_map( 'absint', $event_ids ) ) ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wpcap_partner_events WHERE partner_id=%d", $partner_id ) );
		$linked = array();
		$skipped = array();
		foreach ( $event_ids as $event_id ) {
			if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->prefix}wpcap_events WHERE event_id=%d", $event_id ) ) ) { continue; }
			$owner = self::partner_of_event( $event_id );
			if ( $owner && $owner !== $partner_id ) { $skipped[] = $event_id; continue; }
			if ( false !== $wpdb->insert( "{$wpdb->prefix}wpcap_partner_events", array( 'event_id' => $event_id, 'partner_id' => $partner_id ) ) ) { $linked[] = $event_id; }
		}
		Audit::record( 'partner_events_set', 'partner', $partner_id, array( 'event_ids' => $linked, 'skipped' => $skipped ) );
		return array( 'event_ids' => $linked, 'skipped' => $skipped );
	}

	public static function user_ids( $partner_id ) {
		global $wpdb;
		return array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}wpcap_partner_users WHERE partner_id=%d ORDER BY user_id", absint( $partner_id ) ) ) );
	}

	/** Replaces the WordPress users that may view the partner's settlements. */
	public static function set_users( $partner_id, array $user_ids ) {
		global $wpdb;
		$partner_id = absint( $partner_id );
		$user_ids = array_values( array_unique( array_filter( array_map( 'absint', $user_ids ) ) ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wpcap_partner_users WHERE partner_id=%d", $partner_id ) );
		foreach ( $user_ids as $user_id ) {
			$wpdb->insert( "{$wpdb->prefix}wpcap_partner_users", array( 'partner_id' => $partner_id, 'user_id' => $user_id ) );
		}
		Audit::record( 'partner_users_set', 'partner', $partner_id, array( 'user_ids' => $user_ids ) );
		return $user_ids;
	}

	/** Partner ids the user is linked to. */
	public static function partners_of_user( $user_id ) {
		global $wpdb;
		if ( ! $user_id ) { return array(); }
		return array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT partner_id FROM {$wpdb->prefix}wpcap_partner_users WHERE user_id=%d", absint( $user_id ) ) ) );
	}

	/** Resolves "12, alice, bob@example.com" to user ids (unknown entries are ignored). */
	public static function resolve_users( $input ) {
		$out = array();
		foreach ( preg_split( '/[\s,;]+/', (string) $input, -1, PREG_SPLIT_NO_EMPTY ) as $token ) {
			if ( ctype_digit( $token ) ) { $out[] = (int) $token; continue; }
			if ( ! function_exists( 'get_user_by' ) ) { continue; }
			$user = get_user_by( is_email( $token ) ? 'email' : 'login', $token );
			if ( $user ) { $out[] = (int) $user->ID; }
		}
		return array_values( array_unique( array_filter( $out ) ) );
	}
}

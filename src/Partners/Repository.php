<?php
/**
 * Partners (organisers/resellers): master data, commission/fee model, linked events and linked
 * WordPress users. An event belongs to at most one partner (primary key on event_id), so sales are
 * never settled twice.
 */
namespace PassCaptain\Partners;

use PassCaptain\Audit;
use PassCaptain\Security\Vault;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Repository {
	/** Refund handling of platform fees: reverse commission and per-ticket fee, keep the fixed fee, keep both. */
	const REFUND_POLICIES = array( 'reverse_all', 'keep_fixed_fee', 'keep_all' );
	const STATUSES = array( 'active', 'inactive' );

	public static function refund_policy_labels() {
		return array(
			'reverse_all' => __( 'Provision und Ticketgebühr werden bei Erstattungen storniert', 'pass-captain' ),
			'keep_fixed_fee' => __( 'Provision wird storniert, Ticketgebühr bleibt bestehen', 'pass-captain' ),
			'keep_all' => __( 'Provision und Ticketgebühr bleiben bei Erstattungen bestehen', 'pass-captain' ),
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
	 *                    settlement_start (Y-m-d|''), status, notes; sales model and seller data (missing keys keep
	 *                    the stored value on update): sales_model, legal_name, country, tax_number, vat_id_checked_at,
	 *                    invoice_prefix, small_business, small_business_note.
	 * @return int|\WP_Error Partner id.
	 */
	public static function save( array $data, $partner_id = 0 ) {
		global $wpdb;
		$partner_id = absint( $partner_id );
		$existing = $partner_id ? self::get( $partner_id ) : null;
		if ( $partner_id && ! $existing ) { return new \WP_Error( 'wpcap_partner_missing', __( 'Veranstalter nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
		$name = substr( sanitize_text_field( (string) ( $data['name'] ?? '' ) ), 0, 190 );
		if ( '' === $name ) { return new \WP_Error( 'wpcap_partner_name', __( 'Bitte einen Namen angeben.', 'pass-captain' ), array( 'status' => 400 ) ); }
		$email = sanitize_email( (string) ( $data['contact_email'] ?? '' ) );
		if ( '' !== $email && ! is_email( $email ) ) { return new \WP_Error( 'wpcap_partner_email', __( 'Die Kontakt-E-Mail-Adresse ist ungültig.', 'pass-captain' ), array( 'status' => 400 ) ); }
		$currency = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) ( $data['currency'] ?? 'EUR' ) ) );
		if ( 3 !== strlen( $currency ) ) { return new \WP_Error( 'wpcap_partner_currency', __( 'Die Währung muss ein dreistelliger ISO-Code sein.', 'pass-captain' ), array( 'status' => 400 ) ); }
		$commission = (int) ( $data['commission_bp'] ?? 0 );
		$fee_tax = (int) ( $data['fee_tax_rate_bp'] ?? 0 );
		if ( $commission < 0 || $commission > 10000 || $fee_tax < 0 || $fee_tax > 10000 ) { return new \WP_Error( 'wpcap_partner_percent', __( 'Prozentwerte müssen zwischen 0 und 100 liegen.', 'pass-captain' ), array( 'status' => 400 ) ); }
		$fee = (int) ( $data['fee_per_ticket_minor'] ?? 0 );
		if ( $fee < 0 ) { return new \WP_Error( 'wpcap_partner_fee', __( 'Die Ticketgebühr darf nicht negativ sein.', 'pass-captain' ), array( 'status' => 400 ) ); }
		$start = (string) ( $data['settlement_start'] ?? '' );
		if ( '' !== $start && ! \PassCaptain\Reports\Data::valid_date( $start ) ) { return new \WP_Error( 'wpcap_partner_start', __( 'Ungültiges Abrechnungsbeginn-Datum.', 'pass-captain' ), array( 'status' => 400 ) ); }
		$seller = self::seller_fields( $data, $existing, $partner_id );
		if ( is_wp_error( $seller ) ) { return $seller; }
		$now = current_time( 'mysql', true );
		$row = array(
			'name' => $name,
			'contact_name' => substr( sanitize_text_field( (string) ( $data['contact_name'] ?? '' ) ), 0, 190 ),
			'contact_email' => $email,
			'contact_phone' => substr( sanitize_text_field( (string) ( $data['contact_phone'] ?? '' ) ), 0, 64 ),
			'billing_address' => substr( sanitize_textarea_field( (string) ( $data['billing_address'] ?? '' ) ), 0, 2000 ),
			'vat_id' => substr( sanitize_text_field( (string) ( $data['vat_id'] ?? '' ) ), 0, 64 ),
			'currency' => $currency,
			'commission_bp' => $commission,
			'fee_per_ticket_minor' => $fee,
			'fee_tax_rate_bp' => $fee_tax,
			'refund_policy' => in_array( $data['refund_policy'] ?? '', self::REFUND_POLICIES, true ) ? $data['refund_policy'] : 'reverse_all',
			'settlement_start' => '' !== $start ? $start : ( $existing ? $existing->settlement_start : gmdate( 'Y-m-01' ) ),
			'status' => in_array( $data['status'] ?? '', self::STATUSES, true ) ? $data['status'] : 'active',
			'notes' => substr( sanitize_textarea_field( (string) ( $data['notes'] ?? '' ) ), 0, 5000 ),
			'updated_at' => $now,
		) + $seller;
		// Payout details (IBAN etc.) are encrypted at rest, bound to the partner row; a missing key keeps
		// the stored value, an empty string clears it.
		$payout = array_key_exists( 'payout_details', $data ) ? substr( sanitize_textarea_field( (string) $data['payout_details'] ), 0, 2000 ) : null;
		if ( $existing ) {
			if ( null !== $payout ) { $row['payout_details'] = self::seal_payout( $payout, $partner_id ); }
			$ok = $wpdb->update( "{$wpdb->prefix}wpcap_partners", $row, array( 'partner_id' => $partner_id ) );
		} else {
			$row['created_at'] = $now;
			$row['payout_details'] = ''; // The ciphertext is bound to the partner id, known after the insert.
			$ok = $wpdb->insert( "{$wpdb->prefix}wpcap_partners", $row );
			$partner_id = (int) $wpdb->insert_id;
			if ( false !== $ok && null !== $payout && '' !== $payout ) {
				$ok = $wpdb->update( "{$wpdb->prefix}wpcap_partners", array( 'payout_details' => self::seal_payout( $payout, $partner_id ) ), array( 'partner_id' => $partner_id ) );
				if ( false === $ok ) { $wpdb->delete( "{$wpdb->prefix}wpcap_partners", array( 'partner_id' => $partner_id ) ); }
			}
		}
		if ( false === $ok ) { return new \WP_Error( 'wpcap_partner_save', __( 'Veranstalter konnte nicht gespeichert werden.', 'pass-captain' ), array( 'status' => 500 ) ); }
		Audit::record( $existing ? 'partner_updated' : 'partner_created', 'partner', $partner_id, array( 'commission_bp' => $commission, 'fee_per_ticket_minor' => $fee, 'refund_policy' => $row['refund_policy'], 'status' => $row['status'], 'sales_model' => $row['sales_model'], 'sales_model_changed' => $existing && SalesModel::model_of( $existing ) !== $row['sales_model'] ) );
		return $partner_id;
	}

	/* ------------------------------------------------------------------ Payout details (encrypted) */

	/** Vault context of a partner's payout details (bound to the row). */
	public static function payout_context( $partner_id ) {
		return 'partner_payout:' . absint( $partner_id );
	}

	/** Vault context of the payout details copied into a finalized settlement's partner snapshot. */
	public static function snapshot_payout_context( $settlement_id ) {
		return 'partner_settlement_payout:' . absint( $settlement_id );
	}

	/** Ciphertext of payout details for a partner row ('' stays ''). */
	private static function seal_payout( $plaintext, $partner_id ) {
		return '' === (string) $plaintext ? '' : Vault::encrypt( (string) $plaintext, self::payout_context( $partner_id ) );
	}

	/**
	 * Decrypted payout details of a partner row (operator use only: admin form hint, settlement export
	 * for finance). Null when the stored value cannot be decrypted (key lost or changed).
	 *
	 * @param object|null $partner Partner row.
	 * @return string|null
	 */
	public static function payout_details( $partner ) {
		if ( ! $partner ) { return ''; }
		return Vault::decrypt( (string) ( $partner->payout_details ?? '' ), self::payout_context( (int) $partner->partner_id ) );
	}

	/**
	 * Masked payout details for display: only the last 4 characters of the IBAN (or, without an
	 * IBAN, of the account text), e.g. "•••• 3000". Short values show no characters.
	 */
	public static function mask_payout( $plaintext ) {
		$plaintext = (string) $plaintext;
		if ( '' === trim( $plaintext ) ) { return ''; }
		// IBAN: country code, check digits, 11–30 characters, optionally grouped by single spaces.
		if ( preg_match( '/\b[A-Z]{2}\d{2}(?: ?[A-Z0-9]){11,30}\b/', strtoupper( $plaintext ), $m ) ) {
			$account = str_replace( ' ', '', $m[0] );
		} else {
			$account = (string) preg_replace( '/\s+/', '', $plaintext );
		}
		return strlen( $account ) >= 8 ? '•••• ' . substr( $account, -4 ) : '••••';
	}

	/**
	 * Encrypts payout details still stored in plaintext (partners and the partner snapshots of
	 * finalized settlements) and re-encrypts values only readable with the previous key. Idempotent;
	 * runs with Security\Vault::migrate() (plugin upgrade, admin requests until done).
	 *
	 * @return int Number of values (re-)encrypted.
	 */
	public static function migrate_payout_details() {
		global $wpdb;
		$p = $wpdb->prefix;
		if ( ! Vault::available() || ! self::table_present( 'wpcap_partners' ) ) { return 0; }
		$count = 0;
		foreach ( (array) $wpdb->get_results( "SELECT partner_id,payout_details FROM {$p}wpcap_partners WHERE payout_details<>''" ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			$context = self::payout_context( (int) $row->partner_id );
			$plain = self::plain_for_migration( (string) $row->payout_details, $context );
			if ( null === $plain ) { continue; }
			$sealed = Vault::encrypt( $plain, $context );
			if ( ! Vault::is_encrypted( $sealed ) ) { break; } // No key: nothing can be encrypted.
			if ( false !== $wpdb->update( "{$p}wpcap_partners", array( 'payout_details' => $sealed ), array( 'partner_id' => (int) $row->partner_id ) ) ) { ++$count; }
		}
		if ( ! self::table_present( 'wpcap_partner_settlements' ) ) { return $count; }
		foreach ( (array) $wpdb->get_results( "SELECT settlement_id,partner_snapshot FROM {$p}wpcap_partner_settlements WHERE partner_snapshot LIKE '%payout_details%'" ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			$snapshot = json_decode( (string) $row->partner_snapshot, true );
			$stored = is_array( $snapshot ) ? (string) ( $snapshot['partner']['payout_details'] ?? '' ) : '';
			if ( '' === $stored ) { continue; }
			$context = self::snapshot_payout_context( (int) $row->settlement_id );
			$plain = self::plain_for_migration( $stored, $context );
			if ( null === $plain ) { continue; }
			$sealed = Vault::encrypt( $plain, $context );
			if ( ! Vault::is_encrypted( $sealed ) ) { break; }
			$snapshot['partner']['payout_details'] = $sealed;
			if ( false !== $wpdb->update( "{$p}wpcap_partner_settlements", array( 'partner_snapshot' => wp_json_encode( $snapshot ) ), array( 'settlement_id' => (int) $row->settlement_id ) ) ) { ++$count; }
		}
		return $count;
	}

	/** Whether a plugin table exists (uncached: also right after the schema upgrade created it). */
	private static function table_present( $suffix ) {
		global $wpdb;
		$table = $wpdb->prefix . $suffix;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', addcslashes( $table, '_%\\' ) ) );
	}

	/** Plaintext when a stored value must be (re-)encrypted; null when it is fine or unreadable. */
	private static function plain_for_migration( $stored, $context ) {
		if ( ! Vault::is_encrypted( $stored ) ) { return $stored; }
		if ( null !== Vault::decrypt( $stored, $context ) && Vault::needs_reencrypt_value( $stored, $context ) ) { return Vault::decrypt( $stored, $context ); }
		return null;
	}

	/**
	 * Sales model and seller fields (validated). Keys missing in $data keep the stored value.
	 *
	 * @return array|\WP_Error
	 */
	private static function seller_fields( array $data, $existing, $partner_id ) {
		$pick = static function ( $key, $default ) use ( $data, $existing ) {
			if ( array_key_exists( $key, $data ) ) { return $data[ $key ]; }
			return $existing && isset( $existing->$key ) ? $existing->$key : $default;
		};
		$model = (string) $pick( 'sales_model', SalesModel::OWN_NAME );
		if ( ! in_array( $model, SalesModel::MODELS, true ) ) { return new \WP_Error( 'wpcap_partner_sales_model', __( 'Unbekanntes Verkaufsmodell.', 'pass-captain' ), array( 'status' => 400 ) ); }
		$country_raw = trim( (string) $pick( 'country', '' ) );
		$country = SalesModel::country( $country_raw );
		if ( '' !== $country_raw && '' === $country ) { return new \WP_Error( 'wpcap_partner_country', __( 'Das Land muss als zweistelliger ISO-Code angegeben werden (z. B. DE, AT).', 'pass-captain' ), array( 'status' => 400 ) ); }
		$prefix_raw = trim( (string) $pick( 'invoice_prefix', '' ) );
		$prefix = preg_replace( '/[^A-Za-z0-9]/', '', $prefix_raw );
		if ( $prefix !== $prefix_raw || strlen( $prefix ) > 10 ) { return new \WP_Error( 'wpcap_partner_prefix', __( 'Das Rechnungsnummern-Präfix darf nur Buchstaben und Ziffern enthalten (höchstens 10 Zeichen).', 'pass-captain' ), array( 'status' => 400 ) ); }
		if ( '' !== $prefix ) {
			global $wpdb;
			$reserved = array( (string) \PassCaptain\Settings::get( 'invoice_prefix' ), (string) \PassCaptain\Settings::get( 'credit_note_prefix' ), (string) ( \PassCaptain\Settings::get( 'partners' )['settlement_prefix'] ?? 'PA' ), (string) ( \PassCaptain\Settings::get( 'partners' )['self_billing_prefix'] ?? 'GU' ) );
			$taken = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_partners WHERE invoice_prefix=%s AND partner_id<>%d", $prefix, absint( $partner_id ) ) );
			if ( $taken || in_array( strtoupper( $prefix ), array_map( 'strtoupper', $reserved ), true ) || preg_match( '/^V\d+$/i', $prefix ) ) {
				return new \WP_Error( 'wpcap_partner_prefix_taken', __( 'Dieses Rechnungsnummern-Präfix wird bereits verwendet (Plattform oder anderer Veranstalter). Jeder Veranstalter braucht einen eigenen Nummernkreis.', 'pass-captain' ), array( 'status' => 400 ) );
			}
		}
		$checked = trim( (string) $pick( 'vat_id_checked_at', '' ) );
		$checked = substr( $checked, 0, 10 );
		if ( '' !== $checked && ! \PassCaptain\Reports\Data::valid_date( $checked ) ) { return new \WP_Error( 'wpcap_partner_vat_checked', __( 'Ungültiges Datum der USt-IdNr.-Prüfung.', 'pass-captain' ), array( 'status' => 400 ) ); }
		return array(
			'sales_model' => $model,
			'legal_name' => substr( sanitize_text_field( (string) $pick( 'legal_name', '' ) ), 0, 190 ),
			'country' => $country,
			'tax_number' => substr( sanitize_text_field( (string) $pick( 'tax_number', '' ) ), 0, 64 ),
			'vat_id_checked_at' => '' !== $checked ? $checked : null,
			'invoice_prefix' => $prefix,
			'small_business' => (int) (bool) $pick( 'small_business', 0 ),
			'small_business_note' => substr( sanitize_text_field( (string) $pick( 'small_business_note', '' ) ), 0, 255 ),
		);
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
		if ( ! self::get( $partner_id ) ) { return new \WP_Error( 'wpcap_partner_missing', __( 'Veranstalter nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
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

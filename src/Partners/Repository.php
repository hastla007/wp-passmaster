<?php
/**
 * Partners (organisers/resellers): master data, commission/fee model, linked events and linked
 * WordPress users. An event belongs to at most one partner at a time (primary key on event_id);
 * wpcap_partner_event_history keeps every assignment with its validity period, so a sale and its
 * refunds belong to the organizer assigned when the order was created (A448). Every item is settled
 * at most once across all partners (unique source_key, see Settlements).
 */
namespace PassCaptain\Partners;

use PassCaptain\Audit;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Repository {
	/** Refund handling of platform fees: reverse commission and per-ticket fee, keep the fixed fee, keep both. */
	const REFUND_POLICIES = array( 'reverse_all', 'keep_fixed_fee', 'keep_all' );
	const STATUSES = array( 'active', 'inactive' );
	/** organizer: owns events (settlements); reseller: sells other organizers' events via the API. */
	const ROLE_ORGANIZER = 'organizer';
	const ROLE_RESELLER = 'reseller';
	const ROLES = array( self::ROLE_ORGANIZER, self::ROLE_RESELLER );
	/** Upper bound of the hold time of API reservations (minutes). */
	const MAX_HOLD_MINUTES = 60;

	public static function role_labels() {
		return array(
			self::ROLE_ORGANIZER => __( 'Veranstalter (eigene Events)', 'pass-captain' ),
			self::ROLE_RESELLER => __( 'Wiederverkäufer (verkauft Events anderer Veranstalter)', 'pass-captain' ),
		);
	}

	/** Role of a partner row (unknown/missing = organizer). */
	public static function role_of( $partner ) {
		$role = is_object( $partner ) ? (string) ( $partner->role ?? '' ) : '';
		return in_array( $role, self::ROLES, true ) ? $role : self::ROLE_ORGANIZER;
	}

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
	 *                    invoice_prefix, small_business, small_business_note; API sales (missing keys keep the
	 *                    stored value): role, sales_channel, hold_minutes (1–60), cancel_window_hours,
	 *                    anonymous_buyer, reseller_commission_bp, reseller_fee_minor.
	 * @return int|\WP_Error Partner id.
	 */
	/** Payout details for display: IBANs masked (last four characters), other text unchanged. */
	public static function masked_payout( $text ) {
		return (string) preg_replace_callback( '/\b[A-Z]{2}\d{2}(?: ?[A-Z0-9]{2,4}){3,8}\b/i', static function ( $m ) {
			return \PassCaptain\PayoutAccount::mask( strtoupper( preg_replace( '/\s+/', '', $m[0] ) ) );
		}, (string) $text );
	}

	/**
	 * Personal data exporter (wp_privacy_personal_data_exporters): partners whose contact person has
	 * this e-mail address – contact name, e-mail, phone and the organisation (payout details masked).
	 */
	public static function privacy_export( $email_address, $page = 1 ) {
		global $wpdb;
		$email = sanitize_email( (string) $email_address );
		$data = array();
		if ( '' !== $email && \PassCaptain\Privacy::has_table( 'wpcap_partners' ) ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT partner_id,name,contact_name,contact_email,contact_phone,payout_details,created_at FROM {$wpdb->prefix}wpcap_partners WHERE contact_email=%s ORDER BY partner_id", $email ) ) as $row ) {
				$data[] = array( 'group_id' => 'pass-captain-partners', 'group_label' => __( 'Pass Captain Veranstalter (Ansprechperson)', 'pass-captain' ), 'item_id' => 'partner-' . (int) $row->partner_id, 'data' => array(
					array( 'name' => __( 'Organisation', 'pass-captain' ), 'value' => $row->name ),
					array( 'name' => __( 'Ansprechperson', 'pass-captain' ), 'value' => $row->contact_name ),
					array( 'name' => __( 'Kontakt-E-Mail', 'pass-captain' ), 'value' => $row->contact_email ),
					array( 'name' => __( 'Telefon', 'pass-captain' ), 'value' => $row->contact_phone ),
					array( 'name' => __( 'Auszahlung (maskiert)', 'pass-captain' ), 'value' => self::masked_payout( $row->payout_details ) ),
					array( 'name' => __( 'Angelegt (UTC)', 'pass-captain' ), 'value' => $row->created_at ),
				) );
			}
		}
		return array( 'data' => $data, 'done' => true );
	}

	/**
	 * Personal data eraser: removes the contact person (name, e-mail, phone) from partners. The
	 * organisation, its payout details and finalised statements (snapshots, retention duty) stay.
	 */
	public static function privacy_erase( $email_address, $page = 1 ) {
		global $wpdb;
		$email = sanitize_email( (string) $email_address );
		$removed = 0;
		$retained = false;
		if ( '' !== $email && \PassCaptain\Privacy::has_table( 'wpcap_partners' ) ) {
			$removed = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_partners SET contact_name='',contact_email='',contact_phone='',updated_at=%s WHERE contact_email=%s", current_time( 'mysql', true ), $email ) );
			$retained = $removed > 0 && \PassCaptain\Privacy::has_table( 'wpcap_partner_settlements' ) && (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->prefix}wpcap_partner_settlements WHERE partner_snapshot LIKE %s LIMIT 1", '%' . addcslashes( $email, '_%\\' ) . '%' ) );
		}
		return array(
			'items_removed' => $removed > 0,
			'items_retained' => $retained,
			'messages' => $retained ? array( __( 'Abgeschlossene Veranstalter-Abrechnungen enthalten die Kontaktdaten als unveränderlichen Beleg (Aufbewahrungspflicht) und bleiben bestehen.', 'pass-captain' ) ) : array(),
			'done' => true,
		);
	}

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
		$sales = self::sales_fields( $data, $existing );
		if ( is_wp_error( $sales ) ) { return $sales; }
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
		) + $seller + $sales;
		if ( $existing ) {
			$ok = $wpdb->update( "{$wpdb->prefix}wpcap_partners", $row, array( 'partner_id' => $partner_id ) );
		} else {
			$row['created_at'] = $now;
			$ok = $wpdb->insert( "{$wpdb->prefix}wpcap_partners", $row );
			$partner_id = (int) $wpdb->insert_id;
		}
		if ( false === $ok ) { return new \WP_Error( 'wpcap_partner_save', __( 'Veranstalter konnte nicht gespeichert werden.', 'pass-captain' ), array( 'status' => 500 ) ); }
		Audit::record( $existing ? 'partner_updated' : 'partner_created', 'partner', $partner_id, array( 'commission_bp' => $commission, 'fee_per_ticket_minor' => $fee, 'refund_policy' => $row['refund_policy'], 'status' => $row['status'], 'sales_model' => $row['sales_model'], 'sales_model_changed' => $existing && SalesModel::model_of( $existing ) !== $row['sales_model'], 'role' => $row['role'], 'hold_minutes' => $row['hold_minutes'], 'cancel_window_hours' => $row['cancel_window_hours'], 'anonymous_buyer' => $row['anonymous_buyer'], 'reseller_commission_bp' => $row['reseller_commission_bp'], 'reseller_fee_minor' => $row['reseller_fee_minor'] ) );
		return $partner_id;
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

	/**
	 * Partner API sales settings (validated). Keys missing in $data keep the stored value.
	 *
	 * @return array|\WP_Error
	 */
	private static function sales_fields( array $data, $existing ) {
		$pick = static function ( $key, $default ) use ( $data, $existing ) {
			if ( array_key_exists( $key, $data ) ) { return $data[ $key ]; }
			return $existing && isset( $existing->$key ) ? $existing->$key : $default;
		};
		$role = (string) $pick( 'role', self::ROLE_ORGANIZER );
		if ( ! in_array( $role, self::ROLES, true ) ) { return new \WP_Error( 'wpcap_partner_role', __( 'Unbekannte Rolle.', 'pass-captain' ), array( 'status' => 400 ) ); }
		$hold = (int) $pick( 'hold_minutes', 15 );
		if ( $hold < 1 || $hold > self::MAX_HOLD_MINUTES ) {
			/* translators: %d: maximum minutes */
			return new \WP_Error( 'wpcap_partner_hold', sprintf( __( 'Die Reservierungsdauer muss zwischen 1 und %d Minuten liegen.', 'pass-captain' ), self::MAX_HOLD_MINUTES ), array( 'status' => 400 ) );
		}
		$window = (int) $pick( 'cancel_window_hours', 0 );
		if ( $window < 0 || $window > 8760 ) { return new \WP_Error( 'wpcap_partner_cancel_window', __( 'Das Stornofenster muss zwischen 0 und 8760 Stunden liegen.', 'pass-captain' ), array( 'status' => 400 ) ); }
		$commission = (int) $pick( 'reseller_commission_bp', 0 );
		if ( $commission < 0 || $commission > 10000 ) { return new \WP_Error( 'wpcap_partner_percent', __( 'Prozentwerte müssen zwischen 0 und 100 liegen.', 'pass-captain' ), array( 'status' => 400 ) ); }
		$fee = (int) $pick( 'reseller_fee_minor', 0 );
		if ( $fee < 0 ) { return new \WP_Error( 'wpcap_partner_fee', __( 'Die Ticketgebühr darf nicht negativ sein.', 'pass-captain' ), array( 'status' => 400 ) ); }
		return array(
			'role' => $role,
			'sales_channel' => substr( sanitize_text_field( (string) $pick( 'sales_channel', '' ) ), 0, 64 ),
			'hold_minutes' => $hold,
			'cancel_window_hours' => $window,
			'anonymous_buyer' => (int) (bool) $pick( 'anonymous_buyer', 0 ),
			'reseller_commission_bp' => $commission,
			'reseller_fee_minor' => $fee,
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

	/** Number of linked events per partner (partner_id => count), one query for the partner list. */
	public static function event_counts() {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_results( "SELECT partner_id, COUNT(*) AS n FROM {$wpdb->prefix}wpcap_partner_events GROUP BY partner_id" ) as $row ) {
			$out[ (int) $row->partner_id ] = (int) $row->n;
		}
		return $out;
	}

	/**
	 * Replaces the partner's events with the given list (programmatic API). Only the difference is
	 * written (update_events()); events already linked to another partner are not moved.
	 *
	 * @return array|\WP_Error ['event_ids' => linked, 'skipped' => ids owned by other partners, 'added' => ids, 'removed' => ids]
	 */
	public static function set_events( $partner_id, array $event_ids ) {
		$event_ids = array_values( array_unique( array_filter( array_map( 'absint', $event_ids ) ) ) );
		$current = self::event_ids( $partner_id );
		return self::update_events( $partner_id, array_diff( $event_ids, $current ), array_diff( $current, $event_ids ) );
	}

	/**
	 * Links and unlinks events of a partner (A447): only the given events change, every other link
	 * stays untouched – a form that shows only part of the events can never drop the others. Events
	 * linked to another partner are skipped (unlink them there first). Every change is written to the
	 * assignment history (A448), so sales stay with the organizer assigned at sale time.
	 *
	 * @param int[] $add    Event ids to link.
	 * @param int[] $remove Event ids to unlink (only links of this partner).
	 * @return array|\WP_Error ['event_ids' => all linked ids, 'skipped' => ids owned by other partners, 'added' => ids, 'removed' => ids]
	 */
	public static function update_events( $partner_id, array $add, array $remove = array() ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$partner_id = absint( $partner_id );
		if ( ! self::get( $partner_id ) ) { return new \WP_Error( 'wpcap_partner_missing', __( 'Veranstalter nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
		$remove = array_values( array_unique( array_filter( array_map( 'absint', $remove ) ) ) );
		$add = array_values( array_diff( array_unique( array_filter( array_map( 'absint', $add ) ) ), $remove ) );
		$removed = array();
		if ( $remove ) {
			$in = implode( ',', $remove );
			$linked = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT event_id FROM {$p}wpcap_partner_events WHERE partner_id=%d AND event_id IN ($in)", $partner_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
			foreach ( $linked as $event_id ) {
				if ( $wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_partner_events WHERE event_id=%d AND partner_id=%d", $event_id, $partner_id ) ) ) {
					self::history_close( $event_id, $partner_id );
					$removed[] = $event_id;
				}
			}
		}
		$added = array();
		$skipped = array();
		if ( $add ) {
			$in = implode( ',', $add );
			$existing = array_map( 'absint', $wpdb->get_col( "SELECT event_id FROM {$p}wpcap_events WHERE event_id IN ($in)" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
			$owners = array();
			foreach ( (array) $wpdb->get_results( "SELECT event_id, partner_id FROM {$p}wpcap_partner_events WHERE event_id IN ($in)" ) as $row ) { $owners[ (int) $row->event_id ] = (int) $row->partner_id; } // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
			foreach ( $add as $event_id ) {
				if ( ! in_array( $event_id, $existing, true ) ) { continue; }
				$owner = $owners[ $event_id ] ?? 0;
				if ( $owner === $partner_id ) { continue; }
				if ( $owner ) { $skipped[] = $event_id; continue; }
				// The primary key on event_id rejects a concurrent link by another partner.
				if ( 1 === (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$p}wpcap_partner_events (event_id,partner_id) VALUES (%d,%d)", $event_id, $partner_id ) ) ) {
					self::history_open( $event_id, $partner_id );
					$added[] = $event_id;
				} else {
					$skipped[] = $event_id;
				}
			}
		}
		if ( $added || $removed || $skipped ) {
			Audit::record( 'partner_events_set', 'partner', $partner_id, array( 'added' => $added, 'removed' => $removed, 'skipped' => $skipped ) );
		}
		return array( 'event_ids' => self::event_ids( $partner_id ), 'skipped' => $skipped, 'added' => $added, 'removed' => $removed );
	}

	/* ------------------------------------------------------------------ Assignment history (A448) */

	/** Whether the assignment history table exists (schema 3.10.8). */
	public static function history_ready() {
		return \PassCaptain\Privacy::has_table( 'wpcap_partner_event_history' );
	}

	/**
	 * Opens a history row for a new link. The first assignment of an event is valid since always
	 * (earlier sales belong to the first organizer, bounded by its settlement start); a later one
	 * starts where the previous assignment ended, so sales in an unassigned gap go to the next organizer.
	 */
	private static function history_open( $event_id, $partner_id ) {
		global $wpdb;
		if ( ! self::history_ready() ) { return; }
		$table = $wpdb->prefix . 'wpcap_partner_event_history';
		$now = current_time( 'mysql', true );
		// A stale open row (link removed outside this class) ends now.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET valid_to=%s WHERE event_id=%d AND valid_to IS NULL", $now, $event_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
		$last = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(valid_to) FROM {$table} WHERE event_id=%d", $event_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
		$row = array( 'event_id' => $event_id, 'partner_id' => $partner_id, 'created_by' => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0, 'created_at' => $now );
		if ( $last ) { $row['valid_from'] = (string) $last; }
		$wpdb->insert( $table, $row );
	}

	/** Ends the open history row of a removed link (a link from before the history gets one since always). */
	private static function history_close( $event_id, $partner_id ) {
		global $wpdb;
		if ( ! self::history_ready() ) { return; }
		$table = $wpdb->prefix . 'wpcap_partner_event_history';
		$now = current_time( 'mysql', true );
		$updated = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET valid_to=%s WHERE event_id=%d AND partner_id=%d AND valid_to IS NULL", $now, $event_id, $partner_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
		if ( ! $updated && ! $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE event_id=%d LIMIT 1", $event_id ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
			$wpdb->insert( $table, array( 'event_id' => $event_id, 'partner_id' => $partner_id, 'valid_to' => $now, 'created_by' => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0, 'created_at' => $now ) );
		}
	}

	/**
	 * Assignment periods per event: event_id => list of ['partner_id', 'from' (''=since always), 'to' (''=open)],
	 * oldest first. Events without history rows fall back to their current link (valid since always).
	 *
	 * @param int[] $event_ids
	 * @return array<int,array<int,array{partner_id:int,from:string,to:string}>>
	 */
	public static function assignments( array $event_ids ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$event_ids = array_values( array_unique( array_filter( array_map( 'absint', $event_ids ) ) ) );
		$out = array();
		foreach ( array_chunk( $event_ids, 500 ) as $chunk ) {
			$in = implode( ',', $chunk );
			if ( self::history_ready() ) {
				foreach ( (array) $wpdb->get_results( "SELECT event_id,partner_id,valid_from,valid_to FROM {$p}wpcap_partner_event_history WHERE event_id IN ($in) ORDER BY event_id, valid_from IS NOT NULL, valid_from, history_id" ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
					$out[ (int) $row->event_id ][] = array( 'partner_id' => (int) $row->partner_id, 'from' => (string) $row->valid_from, 'to' => (string) $row->valid_to );
				}
			}
			foreach ( (array) $wpdb->get_results( "SELECT event_id,partner_id FROM {$p}wpcap_partner_events WHERE event_id IN ($in)" ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
				if ( ! isset( $out[ (int) $row->event_id ] ) ) { $out[ (int) $row->event_id ] = array( array( 'partner_id' => (int) $row->partner_id, 'from' => '', 'to' => '' ) ); }
			}
		}
		return $out;
	}

	/**
	 * Partner assigned at a point in time (UTC 'Y-m-d H:i:s') according to the assignments() periods
	 * of one event; 0 = none. A period covers [from, to).
	 */
	public static function partner_at( array $periods, $at ) {
		$at = (string) $at;
		$found = 0;
		foreach ( $periods as $period ) {
			if ( ( '' === $period['from'] || $period['from'] <= $at ) && ( '' === $period['to'] || $at < $period['to'] ) ) { $found = (int) $period['partner_id']; }
		}
		return $found;
	}

	/**
	 * Events a partner's settlement must look at: currently linked, linked at any time (history) and
	 * events of items it already settled (refunds of those are corrections for it).
	 *
	 * @return int[]
	 */
	public static function settlement_event_ids( $partner_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$partner_id = absint( $partner_id );
		$ids = self::event_ids( $partner_id );
		if ( self::history_ready() ) {
			$ids = array_merge( $ids, $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT event_id FROM {$p}wpcap_partner_event_history WHERE partner_id=%d", $partner_id ) ) );
		}
		$ids = array_merge( $ids, $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT event_id FROM {$p}wpcap_partner_settlement_lines WHERE partner_id=%d AND source_key IS NOT NULL AND event_id>0", $partner_id ) ) );
		$ids = array_values( array_unique( array_map( 'absint', $ids ) ) );
		sort( $ids );
		return $ids;
	}

	/** Assignment history of a partner (newest first) with event names, for the admin screen. */
	public static function history_of_partner( $partner_id, $limit = 50 ) {
		global $wpdb;
		if ( ! self::history_ready() ) { return array(); }
		$p = $wpdb->prefix;
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT h.*, e.name AS event_name FROM {$p}wpcap_partner_event_history h LEFT JOIN {$p}wpcap_events e ON e.event_id=h.event_id WHERE h.partner_id=%d ORDER BY h.history_id DESC LIMIT %d", absint( $partner_id ), max( 1, (int) $limit ) ) );
	}

	/* ------------------------------------------------------------------ Event picker (A447) */

	/**
	 * One page of the partner's linked events with names (one query plus a count).
	 *
	 * @return array{rows:object[],total:int}
	 */
	public static function linked_events_page( $partner_id, $page = 1, $per_page = 50 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$partner_id = absint( $partner_id );
		$per_page = max( 1, (int) $per_page );
		$offset = ( max( 1, (int) $page ) - 1 ) * $per_page;
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_partner_events WHERE partner_id=%d", $partner_id ) );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT pe.event_id, COALESCE(e.name,'') AS name FROM {$p}wpcap_partner_events pe LEFT JOIN {$p}wpcap_events e ON e.event_id=pe.event_id WHERE pe.partner_id=%d ORDER BY e.name, pe.event_id LIMIT %d OFFSET %d", $partner_id, $per_page, $offset ) );
		return array( 'rows' => $rows, 'total' => $total );
	}

	/**
	 * Events matching a search (name contains the term, or the event id) with their current partner
	 * (owner_id, 0 = none) in one query: at most $limit rows and whether more exist.
	 *
	 * @return array{rows:object[],more:bool}
	 */
	public static function search_events( $term, $limit = 50 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$term = trim( (string) $term );
		$limit = max( 1, (int) $limit );
		$where = '1=1';
		$order = 'e.name, e.event_id';
		if ( '' !== $term ) {
			$like = '%' . addcslashes( $term, '_%\\' ) . '%';
			$where = ctype_digit( $term ) ? $wpdb->prepare( '(e.event_id=%d OR e.name LIKE %s)', (int) $term, $like ) : $wpdb->prepare( 'e.name LIKE %s', $like );
			// An exact ID match comes first.
			$order = ctype_digit( $term ) ? $wpdb->prepare( '(e.event_id=%d) DESC, ', (int) $term ) . $order : $order;
		}
		$rows = (array) $wpdb->get_results( "SELECT e.event_id, e.name, COALESCE(pe.partner_id,0) AS owner_id FROM {$p}wpcap_events e LEFT JOIN {$p}wpcap_partner_events pe ON pe.event_id=e.event_id WHERE $where ORDER BY $order LIMIT " . ( $limit + 1 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prepared fragments, integer limit.
		return array( 'rows' => array_slice( $rows, 0, $limit ), 'more' => count( $rows ) > $limit );
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

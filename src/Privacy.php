<?php
/**
 * WordPress personal data exporter and eraser integration.
 *
 * Retention (automatic deletion) lives in Privacy\Retention, the privacy policy suggestion in
 * Privacy\PolicyText. Financial records (order amounts, items, invoices, credit notes, refunds)
 * are kept for the statutory retention periods; see the eraser message.
 */
namespace PassCaptain;

use PassCaptain\Privacy\PolicyText;
use PassCaptain\Privacy\Retention;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Privacy {
	/** Domain of all placeholder addresses (erased buyers, box office sales without e-mail). */
	const PLACEHOLDER_DOMAIN = '@invalid.example';
	/** Single event: deletes Apple Wallet device registrations of erased tickets after the revocation push. */
	const WALLET_PURGE_HOOK = 'wpcap_privacy_purge_wallet_registrations';
	/** Erased orders per eraser page. */
	const BATCH = 50;

	public static function register() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'admin_init', array( PolicyText::class, 'add' ) );
		add_action( Retention::HOOK, array( Retention::class, 'run' ) );
		add_action( 'init', array( Retention::class, 'schedule' ) );
		add_action( self::WALLET_PURGE_HOOK, array( __CLASS__, 'purge_wallet_registrations' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'scanner_context' ), 30 );
	}

	public static function register_exporter( $exporters ) {
		$exporters['wp-pass-captain-orders'] = array( 'exporter_friendly_name' => __( 'Pass Captain Bestellungen und Tickets', 'pass-captain' ), 'callback' => array( __CLASS__, 'export' ) );
		return $exporters;
	}

	public static function register_eraser( $erasers ) {
		$erasers['wp-pass-captain-orders'] = array( 'eraser_friendly_name' => __( 'Pass Captain Bestellungen und Tickets', 'pass-captain' ), 'callback' => array( __CLASS__, 'erase' ) );
		return $erasers;
	}

	/* ------------------------------------------------------------------ Helpers */

	/** True for addresses the plugin wrote itself as a placeholder (erased or no e-mail given). */
	public static function is_placeholder_email( $email ) {
		$email = strtolower( trim( (string) $email ) );
		return '' !== $email && self::PLACEHOLDER_DOMAIN === substr( $email, -strlen( self::PLACEHOLDER_DOMAIN ) );
	}

	/** Address for output to third parties: null for empty and placeholder addresses. */
	public static function public_email( $email ) {
		$email = trim( (string) $email );
		return '' === $email || self::is_placeholder_email( $email ) ? null : $email;
	}

	/**
	 * Deterministic replacement for an erased buyer address of one order. The format is relied on by
	 * other erasers (e.g. SMS), so they still find the order after this eraser ran.
	 */
	public static function replacement_email( $email, $order_id ) {
		return 'deleted+' . substr( hash( 'sha256', $email . '|' . (int) $order_id ), 0, 24 ) . self::PLACEHOLDER_DOMAIN;
	}

	private static function random_hash() {
		return hash( 'sha256', wp_generate_password( 64, true, true ) . random_int( 0, PHP_INT_MAX ) );
	}

	/** Whether a plugin table exists (module tables can be removed through the wpcap_modules filter). */
	public static function has_table( $suffix ) {
		static $cache = array();
		global $wpdb;
		$table = $wpdb->prefix . $suffix;
		if ( ! isset( $cache[ $table ] ) ) {
			$cache[ $table ] = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		}
		return $cache[ $table ];
	}

	private static function in_list( array $ids ) {
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		return $ids ? implode( ',', $ids ) : '0';
	}

	/**
	 * Scanner: keyed per-user owner id for the offline queue in localStorage (never the raw user ID),
	 * shorter queue lifetime. See assets/scanner.js.
	 */
	public static function scanner_context() {
		if ( ! function_exists( 'wp_script_is' ) || ! wp_script_is( 'wpcap-scanner', 'registered' ) ) { return; }
		$data = array(
			'owner' => self::scanner_owner( get_current_user_id() ),
			'maxAgeHours' => self::scanner_queue_hours(),
			'i18n' => array(
				/* translators: %d: number of discarded scans */
				'expired' => sprintf( __( '%%d Offline-Scan(s) älter als %d Stunden wurden ohne Upload verworfen.', 'pass-captain' ), self::scanner_queue_hours() ),
			),
		);
		wp_add_inline_script( 'wpcap-scanner', 'window.WPCAP_SCANNER_PRIVACY=' . wp_json_encode( $data ) . ';', 'before' );
	}

	public static function scanner_owner( $user_id ) {
		return substr( hash_hmac( 'sha256', 'scanner-queue|' . (int) $user_id, wp_salt( 'auth' ) ), 0, 20 );
	}

	/** Hours offline scans may stay in the browser (filter wpcap_scanner_queue_max_hours, 1–168, default 24). */
	public static function scanner_queue_hours() {
		return max( 1, min( 168, (int) apply_filters( 'wpcap_scanner_queue_max_hours', 24 ) ) );
	}

	/* ------------------------------------------------------------------ Export */

	public static function export( $email_address, $page = 1 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$email = sanitize_email( $email_address );
		$offset = max( 0, ( absint( $page ) - 1 ) * 50 );
		$data = array();
		if ( '' === $email ) { return array( 'data' => array(), 'done' => true ); }

		// Orders bought with this address. Attendee-only matches export just their own tickets (below),
		// never the buyer's order.
		$orders = $wpdb->get_results( $wpdb->prepare( 'SELECT order_id,public_id,status,payment_status,currency,total_minor,subtotal_minor,discount_minor,price_snapshot,attendee_data,billing,customer_user_id,created_at FROM %i WHERE customer_email=%s ORDER BY order_id ASC LIMIT 50 OFFSET %d', $p . 'wpcap_orders', $email, $offset ), ARRAY_A );
		foreach ( $orders as $order ) {
			$items = $wpdb->get_results( $wpdb->prepare( 'SELECT tt.name,oi.quantity,oi.unit_price_minor,oi.line_total_minor FROM %i oi INNER JOIN %i tt ON tt.ticket_type_id=oi.ticket_type_id WHERE oi.order_id=%d', $p . 'wpcap_order_items', $p . 'wpcap_ticket_types', $order['order_id'] ), ARRAY_A );
			$tickets = $wpdb->get_results( $wpdb->prepare( 'SELECT t.public_id,t.attendee_email,ta.attendee_name,ta.attendee_email AS assigned_email,t.status,t.transfer_count FROM %i t LEFT JOIN %i ta ON ta.ticket_id=t.ticket_id WHERE t.order_id=%d ORDER BY t.ticket_id', $p . 'wpcap_tickets', $p . 'wpcap_ticket_assignments', $order['order_id'] ), ARRAY_A );
			$rows = array(
				array( 'name' => __( 'Bestellreferenz', 'pass-captain' ), 'value' => $order['public_id'] ),
				array( 'name' => __( 'Status', 'pass-captain' ), 'value' => $order['status'] . ' / ' . $order['payment_status'] ),
				array( 'name' => __( 'Gesamtbetrag in kleinster Währungseinheit', 'pass-captain' ), 'value' => $order['total_minor'] . ' ' . $order['currency'] ),
				array( 'name' => __( 'Zwischensumme und Rabatt in kleinster Währungseinheit', 'pass-captain' ), 'value' => $order['subtotal_minor'] . ' / ' . $order['discount_minor'] . ' ' . $order['currency'] ),
				array( 'name' => __( 'Preis-Snapshot', 'pass-captain' ), 'value' => $order['price_snapshot'] ),
				array( 'name' => __( 'Erstellt (UTC)', 'pass-captain' ), 'value' => $order['created_at'] ),
				array( 'name' => __( 'Positionen', 'pass-captain' ), 'value' => wp_json_encode( $items ) ),
				array( 'name' => __( 'Tickets und Teilnehmerdaten', 'pass-captain' ), 'value' => wp_json_encode( $tickets ) ),
			);
			if ( (int) $order['customer_user_id'] ) {
				$rows[] = array( 'name' => __( 'Verknüpftes Benutzerkonto (ID)', 'pass-captain' ), 'value' => (string) (int) $order['customer_user_id'] );
			}
			if ( ! empty( $order['attendee_data'] ) ) {
				$rows[] = array( 'name' => __( 'Noch nicht zugeordnete Teilnehmerangaben', 'pass-captain' ), 'value' => $order['attendee_data'] );
			}
			$refunds = $wpdb->get_results( $wpdb->prepare( 'SELECT amount_minor,currency,status,reason,created_at FROM %i WHERE order_id=%d ORDER BY refund_id', $p . 'wpcap_refunds', $order['order_id'] ), ARRAY_A );
			if ( $refunds ) {
				$rows[] = array( 'name' => __( 'Erstattungen (Betrag, Status, Grund, Datum UTC)', 'pass-captain' ), 'value' => wp_json_encode( $refunds ) );
			}
			$billing = Billing::for_order( (object) $order );
			if ( $billing ) {
				$rows[] = array( 'name' => __( 'Rechnungsdaten (Firma, Name, Anschrift, UID, Referenz)', 'pass-captain' ), 'value' => wp_json_encode( $billing ) );
			}
			$invoice = $wpdb->get_row( $wpdb->prepare( 'SELECT invoice_number,issued_at,status,buyer_snapshot FROM %i WHERE order_id=%d', $p . 'wpcap_invoices', $order['order_id'] ), ARRAY_A );
			if ( $invoice ) {
				$rows[] = array( 'name' => __( 'Rechnung (Nummer, Datum UTC, Status)', 'pass-captain' ), 'value' => $invoice['invoice_number'] . ' / ' . $invoice['issued_at'] . ' / ' . $invoice['status'] );
				$rows[] = array( 'name' => __( 'Käuferangaben auf der Rechnung', 'pass-captain' ), 'value' => $invoice['buyer_snapshot'] );
			}
			$data[] = array( 'group_id' => 'wp-pass-captain-orders', 'group_label' => __( 'Pass Captain Bestellungen', 'pass-captain' ), 'item_id' => 'order-' . $order['public_id'], 'data' => $rows );
		}

		// Every ticket of this person: bought by them or assigned to them as attendee.
		$tickets = $wpdb->get_results( $wpdb->prepare(
			'SELECT DISTINCT t.ticket_id,t.public_id,t.status,t.attendee_email,ta.attendee_name,ta.attendee_email AS assigned_email,e.name AS event_name,o.starts_at,ord.customer_email
			FROM %i t INNER JOIN %i ord ON ord.order_id=t.order_id
			INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id
			INNER JOIN %i e ON e.event_id=o.event_id LEFT JOIN %i ta ON ta.ticket_id=t.ticket_id
			WHERE ord.customer_email=%s OR t.attendee_email=%s OR ta.attendee_email=%s ORDER BY t.ticket_id ASC LIMIT 50 OFFSET %d', $p . 'wpcap_tickets', $p . 'wpcap_orders', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $p . 'wpcap_events', $p . 'wpcap_ticket_assignments',
			$email, $email, $email, $offset
		) );
		foreach ( $tickets as $ticket ) { $data[] = self::export_ticket( $ticket, $email ); }

		$passes = $wpdb->get_results( $wpdb->prepare( 'SELECT public_id,valid_from,valid_until,status FROM %i WHERE member_email=%s ORDER BY pass_id LIMIT 50 OFFSET %d', $p . 'wpcap_member_passes', $email, $offset ), ARRAY_A );
		foreach ( $passes as $pass ) {
			$data[] = array( 'group_id' => 'wp-pass-captain-orders', 'group_label' => __( 'Pass Captain Pässe', 'pass-captain' ), 'item_id' => 'pass-' . $pass['public_id'], 'data' => array(
				array( 'name' => __( 'Passreferenz', 'pass-captain' ), 'value' => $pass['public_id'] ),
				array( 'name' => __( 'Gültig von/bis (UTC)', 'pass-captain' ), 'value' => $pass['valid_from'] . ' / ' . $pass['valid_until'] ),
				array( 'name' => __( 'Status', 'pass-captain' ), 'value' => $pass['status'] ),
			) );
		}
		$waitlist = $wpdb->get_results( $wpdb->prepare( 'SELECT event_id,ticket_type_id,position,status,created_at,offer_expires_at FROM %i WHERE email=%s ORDER BY waitlist_id LIMIT 50 OFFSET %d', $p . 'wpcap_waitlist', $email, $offset ), ARRAY_A );
		foreach ( $waitlist as $entry ) {
			$data[] = array( 'group_id' => 'wp-pass-captain-orders', 'group_label' => __( 'Pass Captain Warteliste', 'pass-captain' ), 'item_id' => 'waitlist-' . $entry['event_id'] . '-' . $entry['ticket_type_id'], 'data' => array(
				array( 'name' => __( 'Event', 'pass-captain' ), 'value' => $entry['event_id'] ),
				array( 'name' => __( 'Ticketart', 'pass-captain' ), 'value' => $entry['ticket_type_id'] ),
				array( 'name' => __( 'Position und Status', 'pass-captain' ), 'value' => $entry['position'] . ' / ' . $entry['status'] ),
				array( 'name' => __( 'Erstellt / Angebotsablauf', 'pass-captain' ), 'value' => $entry['created_at'] . ' / ' . ( $entry['offer_expires_at'] ?? '' ) ),
			) );
		}
		$bookings = $wpdb->get_results( $wpdb->prepare( 'SELECT b.booking_id,b.quantity,b.status,b.cancel_reason,b.created_at,s.starts_at,s.ends_at,r.name AS resource_name FROM %i b LEFT JOIN %i s ON s.slot_id=b.slot_id LEFT JOIN %i r ON r.resource_id=s.resource_id WHERE b.customer_email=%s ORDER BY b.booking_id LIMIT 50 OFFSET %d', $p . 'wpcap_service_bookings', $p . 'wpcap_service_slots', $p . 'wpcap_resources', $email, $offset ), ARRAY_A );
		foreach ( $bookings as $booking ) {
			$data[] = array( 'group_id' => 'wp-pass-captain-orders', 'group_label' => __( 'Pass Captain Terminbuchungen', 'pass-captain' ), 'item_id' => 'booking-' . $booking['booking_id'], 'data' => array(
				array( 'name' => __( 'Ressource', 'pass-captain' ), 'value' => (string) $booking['resource_name'] ),
				array( 'name' => __( 'Termin von/bis (UTC)', 'pass-captain' ), 'value' => $booking['starts_at'] . ' / ' . $booking['ends_at'] ),
				array( 'name' => __( 'Anzahl und Status', 'pass-captain' ), 'value' => $booking['quantity'] . ' / ' . $booking['status'] . ( '' !== (string) $booking['cancel_reason'] ? ' (' . $booking['cancel_reason'] . ')' : '' ) ),
				array( 'name' => __( 'Erstellt (UTC)', 'pass-captain' ), 'value' => $booking['created_at'] ),
			) );
		}
		// E-mails queued for or sent to this address (content is not kept after 30 days, see Retention).
		$mails = $wpdb->get_results( $wpdb->prepare( 'SELECT message_id,template_key,status,send_at,created_at FROM %i WHERE recipient=%s ORDER BY message_id LIMIT 50 OFFSET %d', $p . 'wpcap_message_queue', $email, $offset ), ARRAY_A );
		foreach ( $mails as $mail ) {
			$data[] = array( 'group_id' => 'wp-pass-captain-orders', 'group_label' => __( 'Pass Captain E-Mail-Benachrichtigungen', 'pass-captain' ), 'item_id' => 'message-' . (int) $mail['message_id'], 'data' => array(
				array( 'name' => __( 'Vorlage', 'pass-captain' ), 'value' => $mail['template_key'] ),
				array( 'name' => __( 'Status', 'pass-captain' ), 'value' => $mail['status'] ),
				array( 'name' => __( 'Geplant / erstellt (UTC)', 'pass-captain' ), 'value' => $mail['send_at'] . ' / ' . $mail['created_at'] ),
			) );
		}
		if ( 1 === max( 1, absint( $page ) ) ) {
			foreach ( self::business_contacts( $email ) as $contact ) { $data[] = $contact; }
		}
		$slot_waits = $wpdb->get_results( $wpdb->prepare( 'SELECT w.entry_id,w.quantity,w.position,w.status,w.created_at,s.starts_at,r.name AS resource_name FROM %i w LEFT JOIN %i s ON s.slot_id=w.slot_id LEFT JOIN %i r ON r.resource_id=s.resource_id WHERE w.email=%s ORDER BY w.entry_id LIMIT 50 OFFSET %d', $p . 'wpcap_service_waitlist', $p . 'wpcap_service_slots', $p . 'wpcap_resources', $email, $offset ), ARRAY_A );
		foreach ( (array) $slot_waits as $entry ) {
			$data[] = array( 'group_id' => 'wp-pass-captain-orders', 'group_label' => __( 'Pass Captain Warteliste Termine', 'pass-captain' ), 'item_id' => 'service-waitlist-' . $entry['entry_id'], 'data' => array(
				array( 'name' => __( 'Ressource / Termin (UTC)', 'pass-captain' ), 'value' => (string) $entry['resource_name'] . ' / ' . $entry['starts_at'] ),
				array( 'name' => __( 'Plätze, Position und Status', 'pass-captain' ), 'value' => $entry['quantity'] . ' / ' . $entry['position'] . ' / ' . $entry['status'] ),
				array( 'name' => __( 'Erstellt (UTC)', 'pass-captain' ), 'value' => $entry['created_at'] ),
			) );
		}
		$done = count( $orders ) < 50 && count( $tickets ) < 50 && count( $passes ) < 50 && count( $waitlist ) < 50 && count( $bookings ) < 50 && count( (array) $slot_waits ) < 50 && count( (array) $mails ) < 50;
		return array( 'data' => $data, 'done' => $done );
	}

	/**
	 * Partner and seller contact details stored with this address (business contact person).
	 *
	 * @return array[] Export items.
	 */
	private static function business_contacts( $email ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$out = array();
		if ( self::has_table( 'wpcap_partners' ) ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT partner_id,name,contact_name,contact_email,contact_phone FROM %i WHERE contact_email=%s ORDER BY partner_id', $p . 'wpcap_partners', $email ) ) as $row ) {
				$out[] = array( 'group_id' => 'wp-pass-captain-contacts', 'group_label' => __( 'Pass Captain Ansprechpartner', 'pass-captain' ), 'item_id' => 'partner-' . (int) $row->partner_id, 'data' => array(
					array( 'name' => __( 'Partner', 'pass-captain' ), 'value' => (string) $row->name ),
					array( 'name' => __( 'Name, E-Mail, Telefon', 'pass-captain' ), 'value' => trim( $row->contact_name . ' / ' . $row->contact_email . ' / ' . $row->contact_phone, ' /' ) ),
				) );
			}
		}
		if ( self::has_table( 'wpcap_legal_sellers' ) ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT seller_id,legal_name,contact_email FROM %i WHERE contact_email=%s ORDER BY seller_id', $p . 'wpcap_legal_sellers', $email ) ) as $row ) {
				$out[] = array( 'group_id' => 'wp-pass-captain-contacts', 'group_label' => __( 'Pass Captain Ansprechpartner', 'pass-captain' ), 'item_id' => 'seller-' . (int) $row->seller_id, 'data' => array(
					array( 'name' => __( 'Verkäufer', 'pass-captain' ), 'value' => (string) $row->legal_name ),
					array( 'name' => __( 'Kontakt-E-Mail', 'pass-captain' ), 'value' => (string) $row->contact_email ),
				) );
			}
		}
		return $out;
	}

	/** Removes this address (and the partner contact person's name/phone) from partner and seller records. */
	private static function erase_business_contacts( $email ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$count = 0;
		if ( self::has_table( 'wpcap_partners' ) ) {
			$count += (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET contact_name='',contact_email='',contact_phone='',updated_at=%s WHERE contact_email=%s", $p . 'wpcap_partners', current_time( 'mysql', true ), $email ) );
		}
		if ( self::has_table( 'wpcap_legal_sellers' ) ) {
			$count += (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET contact_email='',updated_at=%s WHERE contact_email=%s", $p . 'wpcap_legal_sellers', current_time( 'mysql', true ), $email ) );
		}
		return $count;
	}

	/** One ticket with attendee name, answers, check-in history and wallet passes. */
	private static function export_ticket( $ticket, $email ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$id = (int) $ticket->ticket_id;
		$rows = array(
			array( 'name' => __( 'Ticketreferenz', 'pass-captain' ), 'value' => $ticket->public_id ),
			array( 'name' => __( 'Event und Beginn (UTC)', 'pass-captain' ), 'value' => $ticket->event_name . ' / ' . $ticket->starts_at ),
			array( 'name' => __( 'Status', 'pass-captain' ), 'value' => $ticket->status ),
			array( 'name' => __( 'Rolle', 'pass-captain' ), 'value' => 0 === strcasecmp( (string) $ticket->customer_email, $email ) ? __( 'Käufer', 'pass-captain' ) : __( 'Teilnehmer', 'pass-captain' ) ),
		);
		$attendee_email = '' !== (string) $ticket->assigned_email ? $ticket->assigned_email : $ticket->attendee_email;
		if ( '' !== (string) $ticket->attendee_name ) { $rows[] = array( 'name' => __( 'Name des Teilnehmers', 'pass-captain' ), 'value' => $ticket->attendee_name ); }
		if ( '' !== (string) $attendee_email ) { $rows[] = array( 'name' => __( 'E-Mail des Teilnehmers', 'pass-captain' ), 'value' => $attendee_email ); }
		// Answers the buyer typed in (checkout, order portal) belong to the buyer's export as well. Consent
		// answers of another holder are that person's declaration (given through the attendee link or
		// recorded for them): the buyer only gets consents whose subject is the buyer (Art. 15(4) GDPR).
		$is_buyer = 0 === strcasecmp( (string) $ticket->customer_email, $email );
		$other_holder = $is_buyer && '' !== (string) $attendee_email && 0 !== strcasecmp( (string) $attendee_email, $email );
		$own_consents = array();
		if ( $other_holder && self::has_table( 'wpcap_consents' ) ) {
			$own_consents = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( 'SELECT field_id FROM %i WHERE ticket_id=%d AND subject_email=%s', $p . 'wpcap_consents', $id, $email ) ) );
		}
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT a.field_id,a.answer,f.label,f.purpose,f.field_type FROM %i a LEFT JOIN %i f ON f.field_id=a.field_id WHERE a.ticket_id=%d ORDER BY a.field_id', $p . 'wpcap_participant_answers', $p . 'wpcap_participant_fields', $id ) ) as $answer ) {
			if ( $other_holder && 'consent' === (string) $answer->field_type && ! in_array( (int) $answer->field_id, $own_consents, true ) ) { continue; }
			$label = '' !== (string) $answer->label ? $answer->label : __( 'Teilnehmerangabe', 'pass-captain' );
			$rows[] = array( 'name' => $label . ( '' !== (string) $answer->purpose ? ' (' . $answer->purpose . ')' : '' ), 'value' => $answer->answer );
		}
		$checkins = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT created_at,scanned_at,result,source FROM %i WHERE ticket_id=%d ORDER BY checkin_id LIMIT 500', $p . 'wpcap_checkins', $id ) ) as $c ) {
			$checkins[] = array( 'time_utc' => $c->scanned_at ?: $c->created_at, 'result' => $c->result, 'source' => $c->source );
		}
		if ( self::has_table( 'wpcap_admission_entries' ) ) {
			$areas = self::has_table( 'wpcap_admission_areas' );
			$sql = $areas
				? "SELECT ae.entered_at,ae.direction,ae.undone,ar.name AS area FROM {$p}wpcap_admission_entries ae LEFT JOIN {$p}wpcap_admission_areas ar ON ar.area_id=ae.area_id WHERE ae.ticket_id=%d ORDER BY ae.entry_id LIMIT 500"
				: "SELECT ae.entered_at,ae.direction,ae.undone,'' AS area FROM {$p}wpcap_admission_entries ae WHERE ae.ticket_id=%d ORDER BY ae.entry_id LIMIT 500";
			foreach ( $wpdb->get_results( $wpdb->prepare( $sql, $id ) ) as $e ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- static SQL above.
				$checkins[] = array( 'time_utc' => $e->entered_at, 'direction' => $e->direction, 'area' => (string) $e->area, 'undone' => (bool) $e->undone );
			}
		}
		if ( $checkins ) { $rows[] = array( 'name' => __( 'Einlassprotokoll (Zeit UTC, Bereich)', 'pass-captain' ), 'value' => wp_json_encode( $checkins ) ); }
		$wallet = $wpdb->get_results( $wpdb->prepare( 'SELECT provider,serial_number,status,updated_at FROM %i WHERE ticket_id=%d ORDER BY wallet_pass_id', $p . 'wpcap_wallet_passes', $id ), ARRAY_A );
		if ( $wallet ) {
			if ( self::has_table( 'wpcap_wallet_registrations' ) ) {
				foreach ( $wallet as $i => $w ) {
					$wallet[ $i ]['registered_devices'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE serial_number=%s', $p . 'wpcap_wallet_registrations', $w['serial_number'] ) );
				}
			}
			$rows[] = array( 'name' => __( 'Wallet-Pässe', 'pass-captain' ), 'value' => wp_json_encode( $wallet ) );
		}
		return array( 'group_id' => 'wp-pass-captain-tickets', 'group_label' => __( 'Pass Captain Tickets', 'pass-captain' ), 'item_id' => 'ticket-' . $ticket->public_id, 'data' => $rows );
	}

	/* ------------------------------------------------------------------ Erasure */

	public static function erase( $email_address, $page = 1 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$email = sanitize_email( $email_address );
		if ( '' === $email ) { return array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true ); }
		$now = current_time( 'mysql', true );
		$removed = 0;
		$messages = array();

		// 1) Attendee-only matches: remove this person's data from other buyers' tickets. The tickets
		// stay valid for the buyer. Rows are rewritten, so every batch starts at OFFSET 0.
		$attendee_ids = array_map( 'absint', $wpdb->get_col( $wpdb->prepare(
			'SELECT DISTINCT t.ticket_id FROM %i t INNER JOIN %i ord ON ord.order_id=t.order_id LEFT JOIN %i ta ON ta.ticket_id=t.ticket_id WHERE (t.attendee_email=%s OR ta.attendee_email=%s) AND ord.customer_email<>%s ORDER BY t.ticket_id LIMIT 200', $p . 'wpcap_tickets', $p . 'wpcap_orders', $p . 'wpcap_ticket_assignments',
			$email, $email, $email
		) ) );
		if ( $attendee_ids ) {
			$upcoming = self::upcoming_count( $attendee_ids );
			$in = self::in_list( $attendee_ids );
			$removed += (int) $wpdb->query( "UPDATE {$p}wpcap_tickets SET attendee_email='' WHERE ticket_id IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
			$removed += (int) $wpdb->query( "UPDATE {$p}wpcap_ticket_assignments SET attendee_email='',attendee_name='' WHERE ticket_id IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			// Wallet passes carry the name: Google objects are updated, Apple devices get a push and fetch
			// the nameless pass; their registrations are deleted after that push (WALLET_PURGE_HOOK).
			self::wallet_resync( $attendee_ids );
			$removed += self::erase_ticket_details( $attendee_ids, false );
			if ( $upcoming ) {
				/* translators: %d: number of tickets */
				$messages[] = sprintf( __( 'Hinweis: %d Ticket(s) anderer Käufer für bevorstehende Termine bleiben gültig; nur Name, E-Mail und Angaben der Person als Teilnehmer wurden entfernt.', 'pass-captain' ), $upcoming );
			}
		}

		// 2) Orders bought with this address.
		// Orders already deferred (tickets for upcoming dates) are skipped, so every batch makes progress.
		$skip = self::deferred() ? ' AND order_id NOT IN (' . self::in_list( array_map( 'absint', array_keys( self::deferred() ) ) ) . ')' : '';
		$orders = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT order_id FROM {$p}wpcap_orders WHERE customer_email=%s{$skip} ORDER BY order_id ASC LIMIT %d", $email, self::BATCH ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		$invoices = 0;
		$upcoming = 0;
		$billing_erased = 0;
		$deferred = 0;
		foreach ( $orders as $order_id ) {
			$replacement = self::replacement_email( $email, $order_id );
			$ticket_ids = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( 'SELECT ticket_id FROM %i WHERE order_id=%d', $p . 'wpcap_tickets', $order_id ) ) );
			// Art. 17(3)(b) GDPR: tickets for upcoming dates are still to be performed. The order is anonymised
			// automatically after its last date (hourly run) instead of revoking tickets people will show at the door.
			if ( self::upcoming_count( $ticket_ids ) && apply_filters( 'wpcap_privacy_defer_upcoming', true, $order_id ) ) {
				self::defer( $order_id, $replacement );
				++$deferred;
				continue;
			}
			$upcoming += self::upcoming_count( $ticket_ids );
			$result = self::anonymize_order( $order_id, $replacement, $email );
			$invoices += $result['invoices'];
			$billing_erased += $result['billing'];
			++$removed;
		}
		if ( $deferred ) {
			wp_mail(
				get_option( 'admin_email' ),
				__( 'Pass Captain: Löschanfrage zurückgestellt', 'pass-captain' ),
				/* translators: %d: number of orders */
				sprintf( __( 'Eine Löschanfrage betrifft %d Bestellung(en) mit Tickets für kommende Termine. Die Tickets bleiben bis nach dem letzten Termin gültig; danach werden die Bestellungen automatisch anonymisiert.', 'pass-captain' ), $deferred )
			);
		}

		// 3) Records keyed by the address only.
		$hash = substr( hash( 'sha256', $email ), 0, 24 );
		$bookings = (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET customer_email=%s,manage_token_hash='' WHERE customer_email=%s", $p . 'wpcap_service_bookings', 'deleted+' . $hash . self::PLACEHOLDER_DOMAIN, $email ) );
		$messages_removed = (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET recipient=%s,payload='{}',status=IF(status IN ('queued','sending'),'cancelled',status),locked_at=NULL,last_error='privacy_erasure' WHERE recipient=%s", $p . 'wpcap_message_queue', 'deleted+' . $hash . self::PLACEHOLDER_DOMAIN, $email ) );
		list( $passes_removed, $passes_deferred ) = self::erase_member_passes( $email, 'deleted+' . $hash . self::PLACEHOLDER_DOMAIN );
		$waitlist_removed = $wpdb->update( $p . 'wpcap_waitlist', array( 'email' => 'deleted+' . $hash . self::PLACEHOLDER_DOMAIN, 'email_hash' => self::random_hash(), 'offer_token_hash' => '', 'manage_token_hash' => '', 'status' => 'removed', 'updated_at' => $now ), array( 'email' => $email ), array( '%s', '%s', '%s', '%s', '%s', '%s' ), array( '%s' ) );
		$slot_waits_removed = (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET email=%s,email_hash=SHA2(CONCAT(entry_id,'|',RAND()),256),offer_token_hash='',manage_token_hash='',status=IF(status IN ('waiting','offered'),'removed',status),updated_at=%s WHERE email=%s", $p . 'wpcap_service_waitlist', 'deleted+' . $hash . self::PLACEHOLDER_DOMAIN, $now, $email ) );
		$contacts_removed = self::erase_business_contacts( $email );
		$removed += $bookings + $messages_removed + (int) $passes_removed + (int) $waitlist_removed + $slot_waits_removed + $contacts_removed;
		if ( $passes_deferred ) {
			/* translators: %d: number of member passes */
			$messages[] = sprintf( __( '%d gültige(r) Mitgliedspass/-pässe bleiben zur Vertragserfüllung (Art. 17 Abs. 3 DSGVO) bis zum Ende der Gültigkeit nutzbar und werden danach automatisch anonymisiert.', 'pass-captain' ), $passes_deferred );
		}

		$retained = (bool) $orders || $passes_deferred > 0;
		if ( $deferred ) {
			/* translators: %d: number of orders */
			$messages[] = sprintf( __( '%d Bestellung(en) mit Tickets für bevorstehende Termine werden zur Vertragserfüllung (Art. 17 Abs. 3 DSGVO) bis nach dem letzten Termin aufbewahrt und danach automatisch anonymisiert; die Tickets bleiben gültig.', 'pass-captain' ), $deferred );
		}
		if ( $orders ) {
			$messages[] = __( 'Bestellbeträge, Positionen, Steuern, Zahlungs- und Erstattungsreferenzen bleiben wegen gesetzlicher Aufbewahrungspflichten (Art. 17 Abs. 3 lit. b DSGVO) erhalten. Entfernt bzw. anonymisiert wurden: Käufer-E-Mail in Bestellungen, Ticketzugänge, Teilnehmernamen, -E-Mails und -angaben, Offline-Scannerdaten, Wallet-Registrierungen, Terminbuchungen, Wartelisteneinträge, Passzugänge und geplante Nachrichten.', 'pass-captain' );
		} elseif ( $removed ) {
			$messages[] = __( 'Teilnehmerdaten, Wartelisteneinträge, Passzugänge, Terminbuchungen bzw. geplante Nachrichten dieser Person wurden entfernt oder anonymisiert.', 'pass-captain' );
		}
		if ( $invoices ) {
			/* translators: %d: number of invoices */
			$messages[] = sprintf( __( '%d Rechnung(en) bleiben unverändert gespeichert, einschließlich der darauf angegebenen Käuferangaben (E-Mail-Adresse): Rechnungen unterliegen der gesetzlichen Aufbewahrungspflicht (7 Jahre nach § 132 BAO, 10 Jahre nach § 147 AO/§ 257 HGB; Art. 17 Abs. 3 lit. b DSGVO). Die Käuferangaben auf Rechnungen wurden nicht entfernt.', 'pass-captain' ), $invoices );
		}
		if ( $billing_erased ) {
			$messages[] = __( 'Die Rechnungsdaten der Bestellungen (Firma, Name, Anschrift, UID, Referenz) wurden entfernt. Bereits ausgestellte Rechnungen behalten die darauf angegebenen Empfängerangaben (gesetzliche Aufbewahrungspflicht, Art. 17 Abs. 3 lit. b DSGVO).', 'pass-captain' );
		}
		if ( $upcoming ) {
			/* translators: %d: number of tickets */
			$messages[] = sprintf( __( 'Achtung: %d Ticket(s) für bevorstehende Termine wurden durch die Löschung ungültig. Bitte klären Sie mit der Person eine Erstattung oder Stornierung; diese wird nicht automatisch ausgelöst.', 'pass-captain' ), $upcoming );
		}
		return array(
			'items_removed' => $removed > 0,
			'items_retained' => $retained,
			'messages' => $messages,
			'done' => count( $orders ) < self::BATCH && count( $attendee_ids ) < 200,
		);
	}

	/**
	 * Anonymises one order: buyer email, access link, tickets (revoked through the core service), attendee
	 * data, participant answers and billing data. Invoices keep their own snapshot (retention duty).
	 *
	 * @return int[] ['invoices' => n, 'billing' => n]
	 */
	private static function anonymize_order( $order_id, $replacement, $email ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = current_time( 'mysql', true );
		$invoices = 0;
		$billing_erased = 0;
		$ticket_ids = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( 'SELECT ticket_id FROM %i WHERE order_id=%d', $p . 'wpcap_tickets', $order_id ) ) );
		// Valid tickets are revoked through the core service so wallet passes, seats, offline
		// packages (status in the next snapshot) and webhooks learn about it.
		$revoked = Orders::revoke_ids( $ticket_ids );
		foreach ( $ticket_ids as $ticket_id ) {
			// token_hash is UNIQUE: one random value per ticket.
			$wpdb->update( $p . 'wpcap_tickets', array( 'token_hash' => self::random_hash(), 'attendee_email' => '' ), array( 'ticket_id' => $ticket_id ), array( '%s', '%s' ), array( '%d' ) );
		}
		if ( $ticket_ids ) {
			$in = self::in_list( $ticket_ids );
			$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_ticket_assignments SET buyer_email=%s,attendee_email='',attendee_name='' WHERE ticket_id IN ($in)", $replacement ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$details = self::erase_ticket_details( $ticket_ids, false );
			// Tickets that were not revoked (checked in, past dates) still carry the name on wallet passes.
			self::wallet_resync( $ticket_ids );
		} else {
			$details = 0;
		}
		$wpdb->update( $p . 'wpcap_orders', array( 'customer_email' => $replacement, 'customer_user_id' => 0, 'access_token_hash' => self::random_hash(), 'access_expires_at' => null, 'attendee_data' => null, 'updated_at' => $now ), array( 'order_id' => $order_id ), array( '%s', '%d', '%s', '%s', '%s', '%s' ), array( '%d' ) );
		// Billing data (name, company, address, VAT ID) of the order; issued invoices keep their own
		// snapshot (retention duty, Art. 17 Abs. 3 lit. b DSGVO). Separate statement: the column may be
		// missing while a deferred schema upgrade is pending.
		$billing_erased += (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET billing=NULL WHERE order_id=%d AND billing IS NOT NULL', $p . 'wpcap_orders', $order_id ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET customer_email=%s WHERE order_id=%d AND customer_email=%s', $p . 'wpcap_service_bookings', $replacement, $order_id, $email ) );
		$invoices += (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE order_id=%d', $p . 'wpcap_invoices', $order_id ) );
		// Fired after anonymisation, so listeners (webhooks with personal data) see no address.
		Orders::fire_revoked( $revoked, 'privacy_erasure' );
		Audit::record( 'privacy_erasure', 'order', $order_id, array( 'tickets' => count( $ticket_ids ), 'revoked' => count( $revoked ), 'details' => $details ) );
		return array( 'invoices' => $invoices, 'billing' => $billing_erased );
	}

	const DEFERRED_OPTION = 'wpcap_deferred_erasures';

	/** Deferred erasures: order id => replacement email. */
	public static function deferred() {
		$list = get_option( self::DEFERRED_OPTION, array() );
		return is_array( $list ) ? $list : array();
	}

	private static function defer( $order_id, $replacement ) {
		$list = self::deferred();
		if ( isset( $list[ $order_id ] ) ) { return; }
		$list[ $order_id ] = $replacement;
		update_option( self::DEFERRED_OPTION, $list, false );
		Audit::record( 'privacy_erasure_deferred', 'order', $order_id );
	}

	/** Deferred member pass erasures: pass id => replacement email. */
	const DEFERRED_PASSES_OPTION = 'wpcap_deferred_pass_erasures';

	public static function deferred_passes() {
		$list = get_option( self::DEFERRED_PASSES_OPTION, array() );
		return is_array( $list ) ? $list : array();
	}

	/**
	 * Erasure of member passes, consistent with orders: a pass that is still valid (active, valid_until
	 * in the future) is part of a contract still being performed and stays usable until it ends
	 * (filter wpcap_privacy_defer_upcoming, $defer, 0, 'member_pass', $pass_id); it is anonymised by
	 * process_deferred() afterwards. Other passes are anonymised and their code invalidated now.
	 *
	 * @return int[] [anonymised, deferred]
	 */
	private static function erase_member_passes( $email, $replacement ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = current_time( 'mysql', true );
		$removed = 0;
		$deferred = 0;
		$list = self::deferred_passes();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT pass_id,status,valid_until FROM %i WHERE member_email=%s ORDER BY pass_id', $p . 'wpcap_member_passes', $email ) ) as $pass ) {
			$pass_id = (int) $pass->pass_id;
			if ( 'active' === $pass->status && (string) $pass->valid_until > $now && apply_filters( 'wpcap_privacy_defer_upcoming', true, 0, 'member_pass', $pass_id ) ) {
				if ( ! isset( $list[ $pass_id ] ) ) {
					$list[ $pass_id ] = $replacement;
					Audit::record( 'privacy_erasure_deferred', 'member_pass', $pass_id );
				}
				++$deferred;
				continue;
			}
			$removed += (int) self::anonymize_pass( $pass_id, $replacement );
		}
		update_option( self::DEFERRED_PASSES_OPTION, $list, false );
		return array( $removed, $deferred );
	}

	private static function anonymize_pass( $pass_id, $replacement ) {
		global $wpdb;
		return $wpdb->update( $wpdb->prefix . 'wpcap_member_passes', array( 'member_email' => $replacement, 'token_hash' => self::random_hash(), 'status' => 'revoked' ), array( 'pass_id' => (int) $pass_id ), array( '%s', '%s', '%s' ), array( '%d' ) );
	}

	/**
	 * Brings wallet passes of these tickets up to date after personal data was removed (attendee
	 * name): Google objects are patched without the holder name, Apple devices get a push and fetch
	 * the nameless pass. Returns the number of tickets queued for an update.
	 */
	public static function wallet_resync( array $ticket_ids ) {
		$ticket_ids = array_values( array_filter( array_map( 'absint', $ticket_ids ) ) );
		if ( ! $ticket_ids || ! self::has_table( 'wpcap_wallet_state' ) || ! class_exists( '\PassCaptain\WalletPasses\Service' ) ) { return 0; }
		global $wpdb;
		$with_state = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( 'SELECT ticket_id FROM %i WHERE ticket_id IN (' . implode( ',', array_fill( 0, count( $ticket_ids ), '%d' ) ) . ')', array_merge( array( $wpdb->prefix . 'wpcap_wallet_state' ), $ticket_ids ) ) ) );
		$changed = array();
		foreach ( $with_state as $ticket_id ) {
			if ( WalletPasses\Service::sync_ticket( $ticket_id ) ) { $changed[] = $ticket_id; }
		}
		if ( $changed ) { WalletPasses\Service::queue( $changed ); }
		return count( $changed );
	}

	/** Hourly: anonymises deferred orders whose dates have passed (or whose tickets are no longer valid). */
	public static function process_deferred() {
		global $wpdb;
		$passes = self::deferred_passes();
		if ( $passes ) {
			$now = current_time( 'mysql', true );
			foreach ( $passes as $pass_id => $replacement ) {
				$pass = $wpdb->get_row( $wpdb->prepare( 'SELECT status,valid_until FROM %i WHERE pass_id=%d', $wpdb->prefix . 'wpcap_member_passes', (int) $pass_id ) );
				if ( $pass && 'active' === $pass->status && (string) $pass->valid_until > $now ) { continue; }
				if ( $pass ) { self::anonymize_pass( (int) $pass_id, (string) $replacement ); }
				unset( $passes[ $pass_id ] );
			}
			update_option( self::DEFERRED_PASSES_OPTION, $passes, false );
		}
		$list = self::deferred();
		$changed = false;
		foreach ( $list as $order_id => $replacement ) {
			$order = $wpdb->get_row( $wpdb->prepare( 'SELECT customer_email FROM %i WHERE order_id=%d', $wpdb->prefix . 'wpcap_orders', (int) $order_id ) );
			$ticket_ids = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( 'SELECT ticket_id FROM %i WHERE order_id=%d', $wpdb->prefix . 'wpcap_tickets', (int) $order_id ) ) );
			if ( $order && self::upcoming_count( $ticket_ids ) ) { continue; }
			if ( $order ) { self::anonymize_order( (int) $order_id, (string) $replacement, (string) $order->customer_email ); }
			unset( $list[ $order_id ] );
			$changed = true;
		}
		if ( $changed ) { update_option( self::DEFERRED_OPTION, $list, false ); }
	}

	/** Valid tickets whose occurrence has not ended yet. */
	private static function upcoming_count( array $ticket_ids ) {
		if ( ! $ticket_ids ) { return 0; }
		global $wpdb;
		$p = $wpdb->prefix;
		$in = self::in_list( $ticket_ids );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE t.ticket_id IN ($in) AND t.status='valid' AND o.status<>'cancelled' AND COALESCE(o.ends_at,o.starts_at)>=%s", current_time( 'mysql', true ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Participant answers, offline package names and wallet device registrations of the tickets.
	 *
	 * @param bool $registrations_now Delete Apple device registrations right away (ticket stays valid)
	 *                                instead of after the revocation push (WALLET_PURGE_HOOK).
	 */
	private static function erase_ticket_details( array $ticket_ids, $registrations_now ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$in = self::in_list( $ticket_ids );
		$count = (int) $wpdb->query( "DELETE FROM {$p}wpcap_participant_answers WHERE ticket_id IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( self::has_table( 'wpcap_offline_entries' ) ) {
			$count += (int) $wpdb->query( "UPDATE {$p}wpcap_offline_entries SET attendee_name='' WHERE ticket_id IN ($in) AND attendee_name<>''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		if ( $registrations_now ) {
			$count += self::purge_wallet_registrations( $ticket_ids );
		} elseif ( function_exists( 'wp_schedule_single_event' ) ) {
			// Apple devices still need the push for the revoked pass; drop their registration afterwards.
			wp_schedule_single_event( time() + HOUR_IN_SECONDS, self::WALLET_PURGE_HOOK, array( array_values( array_map( 'absint', $ticket_ids ) ) ) );
		}
		return $count;
	}

	/** Deletes Apple Wallet device registrations (device library ID, push token) of the tickets. */
	public static function purge_wallet_registrations( $ticket_ids ) {
		if ( ! self::has_table( 'wpcap_wallet_registrations' ) ) { return 0; }
		global $wpdb;
		$p = $wpdb->prefix;
		$in = self::in_list( (array) $ticket_ids );
		return (int) $wpdb->query( "DELETE r FROM {$p}wpcap_wallet_registrations r INNER JOIN {$p}wpcap_wallet_passes w ON w.serial_number=r.serial_number WHERE w.ticket_id IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}

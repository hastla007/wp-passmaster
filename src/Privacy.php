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
		Privacy\AccessLog::register();
	}

	public static function register_exporter( $exporters ) {
		$exporters['pass-captain-orders'] = array( 'exporter_friendly_name' => __( 'Pass Captain Bestellungen und Tickets', 'pass-captain' ), 'callback' => array( __CLASS__, 'export' ) );
		return $exporters;
	}

	public static function register_eraser( $erasers ) {
		$erasers['pass-captain-orders'] = array( 'eraser_friendly_name' => __( 'Pass Captain Bestellungen und Tickets', 'pass-captain' ), 'callback' => array( __CLASS__, 'erase' ) );
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
				'expired' => sprintf( _n( '%%d Offline-Scan(s) älter als %d Stunde wurden ohne Upload verworfen.', '%%d Offline-Scan(s) älter als %d Stunden wurden ohne Upload verworfen.', self::scanner_queue_hours(), 'pass-captain' ), self::scanner_queue_hours() ),
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
		$orders = $wpdb->get_results( $wpdb->prepare( "SELECT order_id,public_id,status,payment_status,currency,total_minor,subtotal_minor,discount_minor,price_snapshot,attendee_data,billing,created_at FROM {$p}wpcap_orders WHERE customer_email=%s ORDER BY order_id ASC LIMIT 50 OFFSET %d", $email, $offset ), ARRAY_A );
		foreach ( $orders as $order ) {
			$items = $wpdb->get_results( $wpdb->prepare( "SELECT tt.name,oi.quantity,oi.unit_price_minor,oi.line_total_minor FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id WHERE oi.order_id=%d", $order['order_id'] ), ARRAY_A );
			$tickets = $wpdb->get_results( $wpdb->prepare( "SELECT t.public_id,t.attendee_email,ta.attendee_name,ta.attendee_email AS assigned_email,t.status,t.transfer_count FROM {$p}wpcap_tickets t LEFT JOIN {$p}wpcap_ticket_assignments ta ON ta.ticket_id=t.ticket_id WHERE t.order_id=%d ORDER BY t.ticket_id", $order['order_id'] ), ARRAY_A );
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
			if ( ! empty( $order['attendee_data'] ) ) {
				$rows[] = array( 'name' => __( 'Noch nicht zugeordnete Teilnehmerangaben', 'pass-captain' ), 'value' => $order['attendee_data'] );
			}
			$payout = PayoutAccount::get( (object) array( 'order_id' => (int) $order['order_id'] ) );
			if ( $payout ) {
				$rows[] = array( 'name' => __( 'Bankverbindung für Erstattungen', 'pass-captain' ), 'value' => $payout['iban'] . ' / ' . $payout['holder'] );
			}
			$billing = Billing::for_order( (object) $order );
			if ( $billing ) {
				$rows[] = array( 'name' => __( 'Rechnungsdaten (Firma, Name, Anschrift, UID, Referenz)', 'pass-captain' ), 'value' => wp_json_encode( $billing ) );
			}
			$invoice = $wpdb->get_row( $wpdb->prepare( "SELECT invoice_number,issued_at,status,buyer_snapshot FROM {$p}wpcap_invoices WHERE order_id=%d", $order['order_id'] ), ARRAY_A );
			if ( self::has_table( 'wpcap_partner_orders' ) ) {
				$sale = $wpdb->get_row( $wpdb->prepare( "SELECT po.mode,po.channel,po.external_reference,po.confirmed_at,po.cancelled_at,po.created_at,pa.name AS partner_name FROM {$p}wpcap_partner_orders po LEFT JOIN {$p}wpcap_partners pa ON pa.partner_id=po.partner_id WHERE po.order_id=%d", $order['order_id'] ), ARRAY_A );
				if ( $sale ) {
					$rows[] = array( 'name' => __( 'Verkauft über Veranstalter/Wiederverkäufer (Name, Kanal, Referenz, Modus, bestätigt/storniert UTC)', 'pass-captain' ), 'value' => wp_json_encode( $sale ) );
				}
			}
			if ( $invoice ) {
				$rows[] = array( 'name' => __( 'Rechnung (Nummer, Datum UTC, Status)', 'pass-captain' ), 'value' => $invoice['invoice_number'] . ' / ' . $invoice['issued_at'] . ' / ' . $invoice['status'] );
				$rows[] = array( 'name' => __( 'Käuferangaben auf der Rechnung', 'pass-captain' ), 'value' => $invoice['buyer_snapshot'] );
			}
			$data[] = array( 'group_id' => 'pass-captain-orders', 'group_label' => __( 'Pass Captain Bestellungen', 'pass-captain' ), 'item_id' => 'order-' . $order['public_id'], 'data' => $rows );
		}

		// Every ticket of this person: bought by them or assigned to them as attendee.
		$tickets = $wpdb->get_results( $wpdb->prepare(
			"SELECT DISTINCT t.ticket_id,t.public_id,t.status,t.attendee_email,ta.attendee_name,ta.attendee_email AS assigned_email,e.name AS event_name,o.starts_at,ord.customer_email
			FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id
			INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id
			INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id LEFT JOIN {$p}wpcap_ticket_assignments ta ON ta.ticket_id=t.ticket_id
			WHERE ord.customer_email=%s OR t.attendee_email=%s OR ta.attendee_email=%s ORDER BY t.ticket_id ASC LIMIT 50 OFFSET %d",
			$email, $email, $email, $offset
		) );
		foreach ( $tickets as $ticket ) { $data[] = self::export_ticket( $ticket, $email ); }

		$passes = $wpdb->get_results( $wpdb->prepare( "SELECT public_id,valid_from,valid_until,status FROM {$p}wpcap_member_passes WHERE member_email=%s ORDER BY pass_id LIMIT 50 OFFSET %d", $email, $offset ), ARRAY_A );
		foreach ( $passes as $pass ) {
			$data[] = array( 'group_id' => 'pass-captain-orders', 'group_label' => __( 'Pass Captain Pässe', 'pass-captain' ), 'item_id' => 'pass-' . $pass['public_id'], 'data' => array(
				array( 'name' => __( 'Passreferenz', 'pass-captain' ), 'value' => $pass['public_id'] ),
				array( 'name' => __( 'Gültig von/bis (UTC)', 'pass-captain' ), 'value' => $pass['valid_from'] . ' / ' . $pass['valid_until'] ),
				array( 'name' => __( 'Status', 'pass-captain' ), 'value' => $pass['status'] ),
			) );
		}
		$waitlist = $wpdb->get_results( $wpdb->prepare( "SELECT w.event_id,w.occurrence_id,w.ticket_type_id,w.quantity,w.position,w.status,w.created_at,w.offered_at,w.offer_expires_at,w.order_id,e.name AS event_name,o.starts_at,ord.public_id AS offer_order FROM {$p}wpcap_waitlist w LEFT JOIN {$p}wpcap_events e ON e.event_id=w.event_id LEFT JOIN {$p}wpcap_occurrences o ON o.occurrence_id=w.occurrence_id LEFT JOIN {$p}wpcap_orders ord ON ord.order_id=w.order_id WHERE w.email=%s ORDER BY w.waitlist_id LIMIT 50 OFFSET %d", $email, $offset ), ARRAY_A );
		foreach ( $waitlist as $entry ) {
			$data[] = array( 'group_id' => 'pass-captain-orders', 'group_label' => __( 'Pass Captain Warteliste', 'pass-captain' ), 'item_id' => 'waitlist-' . $entry['event_id'] . '-' . $entry['ticket_type_id'], 'data' => array(
				array( 'name' => __( 'Event', 'pass-captain' ), 'value' => ( '' !== (string) $entry['event_name'] ? $entry['event_name'] . ' ' : '' ) . '(#' . $entry['event_id'] . ')' ),
				array( 'name' => __( 'Termin (Beginn UTC)', 'pass-captain' ), 'value' => (int) $entry['occurrence_id'] ? $entry['starts_at'] . ' (#' . $entry['occurrence_id'] . ')' : __( 'alle Termine', 'pass-captain' ) ),
				array( 'name' => __( 'Ticketart', 'pass-captain' ), 'value' => $entry['ticket_type_id'] ),
				array( 'name' => __( 'Anzahl', 'pass-captain' ), 'value' => $entry['quantity'] ),
				array( 'name' => __( 'Position und Status', 'pass-captain' ), 'value' => $entry['position'] . ' / ' . $entry['status'] ),
				array( 'name' => __( 'Erstellt / Angebot / Angebotsablauf (UTC)', 'pass-captain' ), 'value' => $entry['created_at'] . ' / ' . ( $entry['offered_at'] ?? '' ) . ' / ' . ( $entry['offer_expires_at'] ?? '' ) ),
				array( 'name' => __( 'Angebotsbestellung', 'pass-captain' ), 'value' => (string) ( $entry['offer_order'] ?? '' ) ),
			) );
		}
		$bookings = $wpdb->get_results( $wpdb->prepare( "SELECT b.booking_id,b.quantity,b.status,b.created_at,s.starts_at,s.ends_at,r.name AS resource_name FROM {$p}wpcap_service_bookings b LEFT JOIN {$p}wpcap_service_slots s ON s.slot_id=b.slot_id LEFT JOIN {$p}wpcap_resources r ON r.resource_id=s.resource_id WHERE b.customer_email=%s ORDER BY b.booking_id LIMIT 50 OFFSET %d", $email, $offset ), ARRAY_A );
		foreach ( $bookings as $booking ) {
			$data[] = array( 'group_id' => 'pass-captain-orders', 'group_label' => __( 'Pass Captain Terminbuchungen', 'pass-captain' ), 'item_id' => 'booking-' . $booking['booking_id'], 'data' => array(
				array( 'name' => __( 'Ressource', 'pass-captain' ), 'value' => (string) $booking['resource_name'] ),
				array( 'name' => __( 'Termin von/bis (UTC)', 'pass-captain' ), 'value' => $booking['starts_at'] . ' / ' . $booking['ends_at'] ),
				array( 'name' => __( 'Anzahl und Status', 'pass-captain' ), 'value' => $booking['quantity'] . ' / ' . $booking['status'] ),
				array( 'name' => __( 'Erstellt (UTC)', 'pass-captain' ), 'value' => $booking['created_at'] ),
			) );
		}
		$done = count( $orders ) < 50 && count( $tickets ) < 50 && count( $passes ) < 50 && count( $waitlist ) < 50 && count( $bookings ) < 50;
		return array( 'data' => $data, 'done' => $done );
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
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT a.answer,f.label,f.purpose FROM {$p}wpcap_participant_answers a LEFT JOIN {$p}wpcap_participant_fields f ON f.field_id=a.field_id WHERE a.ticket_id=%d ORDER BY a.field_id", $id ) ) as $answer ) {
			$label = '' !== (string) $answer->label ? $answer->label : __( 'Teilnehmerangabe', 'pass-captain' );
			$rows[] = array( 'name' => $label . ( '' !== (string) $answer->purpose ? ' (' . $answer->purpose . ')' : '' ), 'value' => $answer->answer );
		}
		// Consent records (A116): every grant/withdrawal with text version, source and time.
		$consents = Consents::export_value( $id );
		if ( $consents ) { $rows[] = array( 'name' => __( 'Einwilligungen (Verlauf, Zeit UTC)', 'pass-captain' ), 'value' => wp_json_encode( $consents ) ); }
		$checkins = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT created_at,scanned_at,result,source FROM {$p}wpcap_checkins WHERE ticket_id=%d ORDER BY checkin_id LIMIT 500", $id ) ) as $c ) {
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
		if ( self::has_table( 'wpcap_ticket_exchanges' ) ) {
			$exchanges = $wpdb->get_results( $wpdb->prepare( "SELECT x.status,x.channel,x.currency,x.difference_minor,x.fee_minor,x.refund_minor,x.due_minor,x.created_at,x.completed_at,tf.name AS from_type,tt2.name AS to_type,IF(x.old_ticket_id=%d,'old','new') AS role FROM {$p}wpcap_ticket_exchanges x LEFT JOIN {$p}wpcap_ticket_types tf ON tf.ticket_type_id=x.from_type_id LEFT JOIN {$p}wpcap_ticket_types tt2 ON tt2.ticket_type_id=x.to_type_id WHERE x.old_ticket_id=%d OR x.new_ticket_id=%d ORDER BY x.exchange_id", $id, $id, $id ), ARRAY_A );
			if ( $exchanges ) { $rows[] = array( 'name' => __( 'Umtausch (von/nach, Differenz, Gebühr, Status, Zeit UTC)', 'pass-captain' ), 'value' => wp_json_encode( $exchanges ) ); }
		}
		$wallet = $wpdb->get_results( $wpdb->prepare( "SELECT provider,serial_number,status,updated_at FROM {$p}wpcap_wallet_passes WHERE ticket_id=%d ORDER BY wallet_pass_id", $id ), ARRAY_A );
		if ( $wallet ) {
			if ( self::has_table( 'wpcap_wallet_registrations' ) ) {
				foreach ( $wallet as $i => $w ) {
					$wallet[ $i ]['registered_devices'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_wallet_registrations WHERE serial_number=%s", $w['serial_number'] ) );
				}
			}
			$rows[] = array( 'name' => __( 'Wallet-Pässe', 'pass-captain' ), 'value' => wp_json_encode( $wallet ) );
		}
		return array( 'group_id' => 'pass-captain-tickets', 'group_label' => __( 'Pass Captain Tickets', 'pass-captain' ), 'item_id' => 'ticket-' . $ticket->public_id, 'data' => $rows );
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
			"SELECT DISTINCT t.ticket_id FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id LEFT JOIN {$p}wpcap_ticket_assignments ta ON ta.ticket_id=t.ticket_id WHERE (t.attendee_email=%s OR ta.attendee_email=%s) AND ord.customer_email<>%s ORDER BY t.ticket_id LIMIT 200",
			$email, $email, $email
		) ) );
		if ( $attendee_ids ) {
			$upcoming = self::upcoming_count( $attendee_ids );
			$in = self::in_list( $attendee_ids );
			$removed += (int) $wpdb->query( "UPDATE {$p}wpcap_tickets SET attendee_email='' WHERE ticket_id IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
			$removed += (int) $wpdb->query( "UPDATE {$p}wpcap_ticket_assignments SET attendee_email='',attendee_name='' WHERE ticket_id IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$removed += self::erase_ticket_details( $attendee_ids, true );
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
			$ticket_ids = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=%d", $order_id ) ) );
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
		$bookings = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_service_bookings SET customer_email=%s WHERE customer_email=%s", 'deleted+' . $hash . self::PLACEHOLDER_DOMAIN, $email ) );
		$messages_removed = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_message_queue SET recipient=%s,payload='{}',status=IF(status IN ('queued','sending'),'cancelled',status),locked_at=NULL,last_error='privacy_erasure' WHERE recipient=%s", 'deleted+' . $hash . self::PLACEHOLDER_DOMAIN, $email ) );
		$passes_removed = $wpdb->update( $p . 'wpcap_member_passes', array( 'member_email' => 'deleted+' . $hash . self::PLACEHOLDER_DOMAIN, 'token_hash' => self::random_hash(), 'status' => 'revoked' ), array( 'member_email' => $email ), array( '%s', '%s', '%s' ), array( '%s' ) );
		// Running offers hold places through a pending placeholder order: release them right away.
		foreach ( array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( "SELECT w.order_id FROM {$p}wpcap_waitlist w INNER JOIN {$p}wpcap_orders o ON o.order_id=w.order_id WHERE w.email=%s AND w.status='offered' AND w.order_id>0 AND o.payment_status='pending'", $email ) ) ) as $offer_order ) {
			Orders::cancel_order( $offer_order, 'privacy_erasure' );
		}
		$waitlist_removed = $wpdb->update( $p . 'wpcap_waitlist', array( 'email' => 'deleted+' . $hash . self::PLACEHOLDER_DOMAIN, 'email_hash' => self::random_hash(), 'offer_token_hash' => '', 'unsubscribe_token_hash' => '', 'status' => 'removed', 'updated_at' => $now ), array( 'email' => $email ), array( '%s', '%s', '%s', '%s', '%s', '%s' ), array( '%s' ) );
		$removed += $bookings + $messages_removed + (int) $passes_removed + (int) $waitlist_removed;

		$retained = (bool) $orders;
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
		$ticket_ids = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=%d", $order_id ) ) );
		// Valid tickets are revoked through the core service so wallet passes, seats, offline
		// packages (status in the next snapshot) and webhooks learn about it.
		// Through Orders::revoke_tickets() per ticket type: revoked units also leave the capacity
		// (refunded_quantity, counters, wpcap_capacity_released) – relevant when the filter
		// wpcap_privacy_defer_upcoming erases orders with upcoming dates right away.
		$revoked = array();
		$valid_by_type = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT ticket_id,ticket_type_id FROM {$p}wpcap_tickets WHERE order_id=%d AND status='valid'", $order_id ) ) as $row ) {
			$valid_by_type[ (int) $row->ticket_type_id ][] = (int) $row->ticket_id;
		}
		foreach ( $valid_by_type as $type_id => $ids ) {
			Orders::revoke_tickets( $order_id, $type_id, count( $ids ), 'privacy_erasure' );
			$revoked = array_merge( $revoked, array_map( 'intval', $wpdb->get_col( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE ticket_id IN (" . implode( ',', $ids ) . ") AND status='revoked'" ) ) );
		}
		foreach ( $ticket_ids as $ticket_id ) {
			// token_hash is UNIQUE: one random value per ticket.
			$wpdb->update( $p . 'wpcap_tickets', array( 'token_hash' => self::random_hash(), 'attendee_email' => '' ), array( 'ticket_id' => $ticket_id ), array( '%s', '%s' ), array( '%d' ) );
		}
		if ( $ticket_ids ) {
			$in = self::in_list( $ticket_ids );
			$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_ticket_assignments SET buyer_email=%s,attendee_email='',attendee_name='' WHERE ticket_id IN ($in)", $replacement ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$details = self::erase_ticket_details( $ticket_ids, false );
		} else {
			$details = 0;
		}
		$wpdb->update( $p . 'wpcap_orders', array( 'customer_email' => $replacement, 'access_token_hash' => self::random_hash(), 'access_expires_at' => null, 'attendee_data' => null, 'updated_at' => $now ), array( 'order_id' => $order_id ), array( '%s', '%s', '%s', '%s', '%s' ), array( '%d' ) );
		// Billing data (name, company, address, VAT ID) of the order; issued invoices keep their own
		// snapshot (retention duty, Art. 17 Abs. 3 lit. b DSGVO). Separate statement: the column may be
		// missing while a deferred schema upgrade is pending.
		$billing_erased += (int) $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_orders SET billing=NULL WHERE order_id=%d AND billing IS NOT NULL", $order_id ) );
		if ( PayoutAccount::ready() ) {
			// Bank account for refunds (IBAN, holder).
			$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_orders SET payout_account=NULL WHERE order_id=%d", $order_id ) );
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_service_bookings SET customer_email=%s WHERE order_id=%d AND customer_email=%s", $replacement, $order_id, $email ) );
		$invoices += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_invoices WHERE order_id=%d", $order_id ) );
		// Fired after anonymisation, so listeners (webhooks with personal data) see no address.
		// wpcap_ticket_revoked already fired per ticket inside Orders::revoke_tickets().
		Audit::record( 'privacy_erasure', 'order', $order_id, array( 'tickets' => count( $ticket_ids ), 'revoked' => count( $revoked ), 'details' => $details ) );
		/** An order was anonymised (privacy erasure): modules drop data they keep per order. */
		do_action( 'wpcap_privacy_order_anonymized', (int) $order_id );
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

	/** Hourly: anonymises deferred orders whose dates have passed (or whose tickets are no longer valid). */
	public static function process_deferred() {
		global $wpdb;
		$list = self::deferred();
		$changed = false;
		foreach ( $list as $order_id => $replacement ) {
			$order = $wpdb->get_row( $wpdb->prepare( "SELECT customer_email FROM {$wpdb->prefix}wpcap_orders WHERE order_id=%d", (int) $order_id ) );
			$ticket_ids = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM {$wpdb->prefix}wpcap_tickets WHERE order_id=%d", (int) $order_id ) ) );
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
	 * Participant answers, consent person references, offline package names and wallet device
	 * registrations of the tickets.
	 *
	 * @param bool $registrations_now Delete Apple device registrations right away (ticket stays valid)
	 *                                instead of after the revocation push (WALLET_PURGE_HOOK).
	 */
	private static function erase_ticket_details( array $ticket_ids, $registrations_now ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$in = self::in_list( $ticket_ids );
		$count = (int) $wpdb->query( "DELETE FROM {$p}wpcap_participant_answers WHERE ticket_id IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// Consents end; their proof records keep no person reference (docs/BETREIBERPFLICHTEN.md).
		$count += Consents::anonymize_tickets( $ticket_ids );
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

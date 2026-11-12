<?php
/** WordPress personal data exporter and eraser integration. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Privacy {
	public static function register_exporter( $exporters ) {
		$exporters['wp-pass-captain-orders'] = array( 'exporter_friendly_name' => __( 'WP Pass Captain Bestellungen und Tickets', 'wp-pass-captain' ), 'callback' => array( __CLASS__, 'export' ) );
		return $exporters;
	}

	public static function register_eraser( $erasers ) {
		$erasers['wp-pass-captain-orders'] = array( 'eraser_friendly_name' => __( 'WP Pass Captain Bestellungen und Tickets', 'wp-pass-captain' ), 'callback' => array( __CLASS__, 'erase' ) );
		return $erasers;
	}

	public static function export( $email_address, $page = 1 ) {
		global $wpdb;
		$orders = $wpdb->get_results( $wpdb->prepare( "SELECT order_id,public_id,customer_email,status,payment_status,currency,total_minor,subtotal_minor,discount_minor,price_snapshot,created_at FROM {$wpdb->prefix}wpcap_orders WHERE customer_email=%s OR order_id IN (SELECT DISTINCT order_id FROM {$wpdb->prefix}wpcap_tickets WHERE attendee_email=%s) ORDER BY order_id ASC LIMIT 50 OFFSET %d", sanitize_email( $email_address ), sanitize_email( $email_address ), max( 0, ( absint( $page ) - 1 ) * 50 ) ), ARRAY_A );
		$data = array();
		foreach ( $orders as $order ) {
			$items = $wpdb->get_results( $wpdb->prepare( "SELECT tt.name,oi.quantity,oi.unit_price_minor,oi.line_total_minor FROM {$wpdb->prefix}wpcap_order_items oi INNER JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id WHERE oi.order_id=%d", $order['order_id'] ), ARRAY_A );
			// Buyers see all tickets of their order; attendees only their own tickets.
			$is_buyer = strtolower( (string) $order['customer_email'] ) === strtolower( sanitize_email( $email_address ) );
			$tickets = $wpdb->get_results( $wpdb->prepare(
				"SELECT t.ticket_id,t.public_id,t.attendee_email,COALESCE(a.attendee_name,'') AS attendee_name,t.status,t.transfer_count FROM {$wpdb->prefix}wpcap_tickets t LEFT JOIN {$wpdb->prefix}wpcap_ticket_assignments a ON a.ticket_id=t.ticket_id WHERE t.order_id=%d" . ( $is_buyer ? '' : ' AND (t.attendee_email=%s OR a.attendee_email=%s)' ),
				$is_buyer ? array( $order['order_id'] ) : array( $order['order_id'], sanitize_email( $email_address ), sanitize_email( $email_address ) )
			), ARRAY_A );
			foreach ( $tickets as &$ticket ) {
				// Participant answers (may contain sensitive data such as dietary or health information), with their labels.
				$ticket['answers'] = $wpdb->get_results( $wpdb->prepare( "SELECT f.label,pa.answer FROM {$wpdb->prefix}wpcap_participant_answers pa LEFT JOIN {$wpdb->prefix}wpcap_participant_fields f ON f.field_id=pa.field_id WHERE pa.ticket_id=%d ORDER BY pa.field_id", (int) $ticket['ticket_id'] ), ARRAY_A );
				unset( $ticket['ticket_id'] );
			}
			unset( $ticket );
			$data[] = array( 'group_id' => 'wp-pass-captain-orders', 'group_label' => __( 'WP Pass Captain Bestellungen', 'wp-pass-captain' ), 'item_id' => 'order-' . $order['public_id'], 'data' => array(
				array( 'name' => __( 'Bestellreferenz', 'wp-pass-captain' ), 'value' => $order['public_id'] ),
				array( 'name' => __( 'Status', 'wp-pass-captain' ), 'value' => $order['status'] . ' / ' . $order['payment_status'] ),
				array( 'name' => __( 'Gesamtbetrag in kleinster Währungseinheit', 'wp-pass-captain' ), 'value' => $order['total_minor'] . ' ' . $order['currency'] ),
				array( 'name' => __( 'Zwischensumme und Rabatt in kleinster Währungseinheit', 'wp-pass-captain' ), 'value' => $order['subtotal_minor'] . ' / ' . $order['discount_minor'] . ' ' . $order['currency'] ),
				array( 'name' => __( 'Preis-Snapshot', 'wp-pass-captain' ), 'value' => $order['price_snapshot'] ),
				array( 'name' => __( 'Erstellt (UTC)', 'wp-pass-captain' ), 'value' => $order['created_at'] ),
				array( 'name' => __( 'Positionen', 'wp-pass-captain' ), 'value' => wp_json_encode( $items ) ),
				array( 'name' => __( 'Tickets und Teilnehmerdaten', 'wp-pass-captain' ), 'value' => wp_json_encode( $tickets, JSON_UNESCAPED_UNICODE ) ),
			) );
		}
		$passes = $wpdb->get_results( $wpdb->prepare( "SELECT public_id,valid_from,valid_until,status FROM {$wpdb->prefix}wpcap_member_passes WHERE member_email=%s ORDER BY pass_id LIMIT 50 OFFSET %d", sanitize_email( $email_address ), max( 0, ( absint( $page ) - 1 ) * 50 ) ), ARRAY_A );
		foreach ( $passes as $pass ) {
			$data[] = array( 'group_id' => 'wp-pass-captain-orders', 'group_label' => __( 'WP Pass Captain Pässe', 'wp-pass-captain' ), 'item_id' => 'pass-' . $pass['public_id'], 'data' => array(
				array( 'name' => __( 'Passreferenz', 'wp-pass-captain' ), 'value' => $pass['public_id'] ),
				array( 'name' => __( 'Gültig von/bis (UTC)', 'wp-pass-captain' ), 'value' => $pass['valid_from'] . ' / ' . $pass['valid_until'] ),
				array( 'name' => __( 'Status', 'wp-pass-captain' ), 'value' => $pass['status'] ),
			) );
		}
		$waitlist = $wpdb->get_results( $wpdb->prepare( "SELECT event_id,ticket_type_id,position,status,created_at,offer_expires_at FROM {$wpdb->prefix}wpcap_waitlist WHERE email=%s ORDER BY waitlist_id LIMIT 50 OFFSET %d", sanitize_email( $email_address ), max( 0, ( absint( $page ) - 1 ) * 50 ) ), ARRAY_A );
		foreach ( $waitlist as $entry ) {
			$data[] = array( 'group_id' => 'wp-pass-captain-orders', 'group_label' => __( 'WP Pass Captain Warteliste', 'wp-pass-captain' ), 'item_id' => 'waitlist-' . $entry['event_id'] . '-' . $entry['ticket_type_id'], 'data' => array(
				array( 'name' => __( 'Event', 'wp-pass-captain' ), 'value' => $entry['event_id'] ),
				array( 'name' => __( 'Ticketart', 'wp-pass-captain' ), 'value' => $entry['ticket_type_id'] ),
				array( 'name' => __( 'Position und Status', 'wp-pass-captain' ), 'value' => $entry['position'] . ' / ' . $entry['status'] ),
				array( 'name' => __( 'Erstellt / Angebotsablauf', 'wp-pass-captain' ), 'value' => $entry['created_at'] . ' / ' . ( $entry['offer_expires_at'] ?? '' ) ),
			) );
		}
		return array( 'data' => $data, 'done' => count( $orders ) < 50 && count( $passes ) < 50 && count( $waitlist ) < 50 );
	}

	public static function erase( $email_address, $page = 1 ) {
		global $wpdb;
		$email = sanitize_email( $email_address );
		// Rows are rewritten below, so every batch starts at OFFSET 0 (the matching set shrinks).
		// Orders already deferred (tickets for upcoming dates) are skipped, so the set still shrinks.
		$deferred = self::deferred();
		$skip = $deferred ? ' AND order_id NOT IN (' . implode( ',', array_map( 'absint', array_keys( $deferred ) ) ) . ')' : '';
		$orders = $wpdb->get_col( $wpdb->prepare( "SELECT order_id FROM {$wpdb->prefix}wpcap_orders WHERE customer_email=%s{$skip} ORDER BY order_id ASC LIMIT 50", $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer ids.
		$removed = 0;
		$retained = array();
		// Attendee-only matches: remove this person's data from other buyers' tickets without
		// touching the buyer's order or invalidating the tickets.
		$attendee_tickets = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM {$wpdb->prefix}wpcap_tickets WHERE attendee_email=%s UNION SELECT ticket_id FROM {$wpdb->prefix}wpcap_ticket_assignments WHERE attendee_email=%s", $email, $email ) ) );
		if ( $attendee_tickets ) {
			$removed += (int) $wpdb->query( "DELETE FROM {$wpdb->prefix}wpcap_participant_answers WHERE ticket_id IN (" . implode( ',', $attendee_tickets ) . ')' );
		}
		$attendee_rows = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_tickets SET attendee_email='' WHERE attendee_email=%s", $email ) );
		$assignment_rows = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_ticket_assignments SET attendee_email='',attendee_name='' WHERE attendee_email=%s", $email ) );
		$removed += (int) $attendee_rows + (int) $assignment_rows;
		foreach ( $orders as $order_id ) {
			$replacement = 'deleted+' . substr( hash( 'sha256', $email . '|' . $order_id ), 0, 24 ) . '@invalid.example';
			// Art. 17(3)(b) GDPR: tickets for upcoming dates are still being performed. The order is anonymised
			// automatically after its last date instead of revoking tickets people will show at the door.
			if ( self::has_upcoming_tickets( (int) $order_id ) ) {
				self::defer( (int) $order_id, $replacement );
				$retained[] = (int) $order_id;
				continue;
			}
			self::anonymize_order( (int) $order_id, $replacement );
			++$removed;
		}
		if ( $retained ) {
			wp_mail(
				get_option( 'admin_email' ),
				__( 'WP Pass Captain: Löschanfrage zurückgestellt', 'wp-pass-captain' ),
				/* translators: %d: number of orders */
				sprintf( __( 'Eine Löschanfrage betrifft %d Bestellung(en) mit Tickets für kommende Termine. Die Tickets bleiben bis nach dem letzten Termin gültig; danach werden die Bestellungen automatisch anonymisiert.', 'wp-pass-captain' ), count( $retained ) )
			);
		}
		$passes_removed = self::erase_passes_and_waitlist( $email );
		$messages = array( __( 'Finanzielle Bestellpositionen bleiben für gesetzliche Aufbewahrungspflichten erhalten; Käufer-E-Mail, Teilnehmerdaten, Wartelisteneinträge und Passzugänge wurden entfernt.', 'wp-pass-captain' ) );
		if ( $retained ) {
			$messages[] = __( 'Bestellungen mit Tickets für kommende Termine werden zur Vertragserfüllung bis nach dem letzten Termin aufbewahrt und danach automatisch anonymisiert.', 'wp-pass-captain' );
		}
		return array( 'items_removed' => $removed > 0 || $passes_removed > 0, 'items_retained' => $removed > 0 || $passes_removed > 0 || (bool) $retained, 'messages' => $messages, 'done' => count( $orders ) < 50 );
	}

	/** Anonymises one order: buyer email, access link, tickets, attendee data and participant answers. */
	private static function anonymize_order( $order_id, $replacement ) {
		global $wpdb;
		$order_id = absint( $order_id );
		$wpdb->update( $wpdb->prefix . 'wpcap_orders', array( 'customer_email' => $replacement, 'access_token_hash' => hash( 'sha256', wp_generate_password( 64, true, true ) ), 'access_expires_at' => null, 'updated_at' => current_time( 'mysql', true ) ), array( 'order_id' => absint( $order_id ) ), array( '%s', '%s', '%s', '%s' ), array( '%d' ) );
		$wpdb->update( $wpdb->prefix . 'wpcap_tickets', array( 'status' => 'revoked', 'token_hash' => hash( 'sha256', wp_generate_password( 64, true, true ) ), 'attendee_email' => '' ), array( 'order_id' => absint( $order_id ) ), array( '%s', '%s', '%s' ), array( '%d' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wpcap_participant_answers WHERE ticket_id IN (SELECT ticket_id FROM {$wpdb->prefix}wpcap_tickets WHERE order_id=%d)", absint( $order_id ) ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_ticket_assignments SET buyer_email=%s,attendee_email='',attendee_name='' WHERE ticket_id IN (SELECT ticket_id FROM {$wpdb->prefix}wpcap_tickets WHERE order_id=%d)", $replacement, absint( $order_id ) ) );
		Audit::record( 'privacy_erasure', 'order', absint( $order_id ) );
	}

	/** Member passes and waitlist entries of an email. @return int Rows changed. */
	private static function erase_passes_and_waitlist( $email ) {
		global $wpdb;
		$passes_removed = $wpdb->update( $wpdb->prefix . 'wpcap_member_passes', array( 'member_email' => 'deleted+' . substr( hash( 'sha256', $email ), 0, 24 ) . '@invalid.example', 'token_hash' => hash( 'sha256', wp_generate_password( 64, true, true ) ), 'status' => 'revoked' ), array( 'member_email' => $email ), array( '%s', '%s', '%s' ), array( '%s' ) );
		$waitlist_removed = $wpdb->update( $wpdb->prefix . 'wpcap_waitlist', array( 'email' => 'deleted+' . substr( hash( 'sha256', $email ), 0, 24 ) . '@invalid.example', 'email_hash' => hash( 'sha256', wp_generate_password( 64, true, true ) ), 'offer_token_hash' => '', 'status' => 'removed', 'updated_at' => current_time( 'mysql', true ) ), array( 'email' => $email ), array( '%s', '%s', '%s', '%s', '%s' ), array( '%s' ) );
		return (int) $passes_removed + (int) $waitlist_removed;
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

	/** Whether an order still has valid tickets for a date that has not ended (plus one day for late entry). */
	public static function has_upcoming_tickets( $order_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE t.order_id=%d AND t.status='valid' AND o.status<>'cancelled' AND COALESCE(o.ends_at,o.starts_at) > %s",
			absint( $order_id ),
			gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
		) );
	}

	/** Hourly: anonymises deferred orders whose last date has passed (or whose tickets are no longer valid). */
	public static function process_deferred() {
		$list = self::deferred();
		$changed = false;
		foreach ( $list as $order_id => $replacement ) {
			if ( self::has_upcoming_tickets( (int) $order_id ) ) { continue; }
			self::anonymize_order( (int) $order_id, (string) $replacement );
			unset( $list[ $order_id ] );
			$changed = true;
		}
		if ( $changed ) { update_option( self::DEFERRED_OPTION, $list, false ); }
	}
}

<?php
/** Order, inventory hold and ticket issuance services. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Orders {
	const HOLD_MINUTES = 10;

	public static function create( $payload ) {
		global $wpdb;
		$email = sanitize_email( $payload['email'] ?? '' );
		$lines = $payload['items'] ?? array();
		if ( ! is_email( $email ) || ! is_array( $lines ) || empty( $lines ) || count( $lines ) > 10 ) {
			return new \WP_Error( 'wpcap_invalid_checkout', __( 'Bitte eine gültige E-Mail-Adresse und Tickets auswählen.', 'wp-pass-captain' ), array( 'status' => 400 ) );
		}

		$key = sanitize_text_field( $payload['idempotency_key'] ?? '' );
		if ( '' === $key ) {
			$key = wp_generate_uuid4();
		}
		$key_hash = hash( 'sha256', $key );
		$orders_table = $wpdb->prefix . 'wpcap_orders';
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT order_id,public_id,status FROM $orders_table WHERE idempotency_key = %s", $key_hash ) );
		if ( $existing ) {
			return array( 'order_id' => (int) $existing->order_id, 'public_id' => $existing->public_id, 'status' => $existing->status, 'reused' => true );
		}

		$clean_lines = array();
		foreach ( $lines as $line ) {
			$type_id = absint( $line['ticket_type_id'] ?? 0 );
			$quantity = absint( $line['quantity'] ?? 0 );
			if ( ! $type_id || $quantity < 1 || $quantity > 10 ) {
				return new \WP_Error( 'wpcap_invalid_item', __( 'Eine Ticketposition ist ungültig.', 'wp-pass-captain' ), array( 'status' => 400 ) );
			}
			$clean_lines[ $type_id ] = ( $clean_lines[ $type_id ] ?? 0 ) + $quantity;
		}

		// Keep anonymous checkout abuse bounded without retaining raw IP addresses.
		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$rate_key = 'wpcap_checkout_' . hash( 'sha256', $ip . '|' . strtolower( $email ) );
		$count = (int) get_transient( $rate_key );
		if ( $count >= 8 ) {
			return new \WP_Error( 'wpcap_rate_limited', __( 'Bitte warte kurz und versuche es erneut.', 'wp-pass-captain' ), array( 'status' => 429 ) );
		}
		set_transient( $rate_key, $count + 1, HOUR_IN_SECONDS );

		$types_table = $wpdb->prefix . 'wpcap_ticket_types';
		$occ_table = $wpdb->prefix . 'wpcap_occurrences';
		$reservations_table = $wpdb->prefix . 'wpcap_reservations';
		$tickets_table = $wpdb->prefix . 'wpcap_tickets';
		$items_table = $wpdb->prefix . 'wpcap_order_items';
		$event_table = $wpdb->prefix . 'wpcap_events';
		$now = current_time( 'mysql', true );
		$wpdb->query( 'START TRANSACTION' );
		try {
			ksort( $clean_lines, SORT_NUMERIC );
			$selected = array();
			$currency = '';
			$total = 0;
			foreach ( $clean_lines as $type_id => $quantity ) {
				$type = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $types_table WHERE ticket_type_id = %d FOR UPDATE", $type_id ) );
				if ( ! $type || 'active' !== $type->status || $quantity > (int) $type->max_per_order ) {
					throw new \RuntimeException( 'ticket_type_unavailable' );
				}
				$occurrence = $wpdb->get_row( $wpdb->prepare(
					"SELECT o.*,e.name AS event_name,e.status AS event_status FROM $occ_table o INNER JOIN $event_table e ON e.event_id=o.event_id WHERE o.occurrence_id=%d FOR UPDATE",
					$type->occurrence_id
				) );
				if ( ! $occurrence || 'published' !== $occurrence->event_status || 'scheduled' !== $occurrence->status || strtotime( $occurrence->starts_at . ' UTC' ) < time() ) {
					throw new \RuntimeException( 'occurrence_unavailable' );
				}
				if ( ( $type->sales_start && $type->sales_start > $now ) || ( $type->sales_end && $type->sales_end < $now ) ) {
					throw new \RuntimeException( 'sales_closed' );
				}
				if ( '' === $currency ) {
					$currency = $type->currency;
				} elseif ( $currency !== $type->currency ) {
					throw new \RuntimeException( 'mixed_currency' );
				}
				$wpdb->query( $wpdb->prepare( "UPDATE $reservations_table SET status='expired' WHERE ticket_type_id=%d AND status='active' AND expires_at <= %s", $type_id, $now ) );
				$sold = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(oi.quantity),0) FROM $items_table oi INNER JOIN $orders_table ord ON ord.order_id=oi.order_id WHERE oi.ticket_type_id=%d AND ord.payment_status='paid' AND ord.status <> 'refunded'", $type_id ) );
				$held = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(quantity),0) FROM $reservations_table WHERE ticket_type_id=%d AND status='active' AND expires_at > %s", $type_id, $now ) );
				if ( (int) $type->capacity > 0 && $sold + $held + $quantity > (int) $type->capacity ) {
					throw new \RuntimeException( 'sold_out' );
				}
				if ( (int) $occurrence->capacity > 0 ) {
					$occ_sold = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(oi.quantity),0) FROM $items_table oi INNER JOIN $types_table tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN $orders_table ord ON ord.order_id=oi.order_id WHERE tt.occurrence_id=%d AND ord.payment_status='paid' AND ord.status <> 'refunded'", $occurrence->occurrence_id ) );
					$occ_held = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(r.quantity),0) FROM $reservations_table r INNER JOIN $types_table tt ON tt.ticket_type_id=r.ticket_type_id WHERE tt.occurrence_id=%d AND r.status='active' AND r.expires_at > %s", $occurrence->occurrence_id, $now ) );
					if ( $occ_sold + $occ_held + $quantity > (int) $occurrence->capacity ) {
						throw new \RuntimeException( 'occurrence_sold_out' );
					}
				}
				$line_total = (int) $type->price_minor * $quantity;
				$total += $line_total;
				$selected[] = array( 'type' => $type, 'quantity' => $quantity, 'line_total' => $line_total );
			}

			$public_id = bin2hex( random_bytes( 16 ) );
			$inserted = $wpdb->insert( $orders_table, array(
				'public_id' => $public_id,
				'idempotency_key' => $key_hash,
				'customer_email' => $email,
				'status' => $total ? 'pending_payment' : 'complete',
				'payment_status' => $total ? 'pending' : 'paid',
				'currency' => $currency,
				'total_minor' => $total,
				'created_at' => $now,
				'updated_at' => $now,
			), array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ) );
			if ( ! $inserted ) {
				throw new \RuntimeException( 'order_insert_failed' );
			}
			$order_id = (int) $wpdb->insert_id;
			foreach ( $selected as $line ) {
				$wpdb->insert( $items_table, array(
					'order_id' => $order_id,
					'ticket_type_id' => (int) $line['type']->ticket_type_id,
					'quantity' => (int) $line['quantity'],
					'unit_price_minor' => (int) $line['type']->price_minor,
					'line_total_minor' => (int) $line['line_total'],
				), array( '%d', '%d', '%d', '%d', '%d' ) );
				if ( 0 !== $wpdb->last_error ) {
					throw new \RuntimeException( 'order_item_insert_failed' );
				}
				if ( $total ) {
					$wpdb->insert( $reservations_table, array(
						'order_id' => $order_id,
						'ticket_type_id' => (int) $line['type']->ticket_type_id,
						'quantity' => (int) $line['quantity'],
						'expires_at' => gmdate( 'Y-m-d H:i:s', time() + self::HOLD_MINUTES * MINUTE_IN_SECONDS ),
						'status' => 'active',
					), array( '%d', '%d', '%d', '%s', '%s' ) );
					if ( 0 !== $wpdb->last_error ) {
						throw new \RuntimeException( 'reservation_insert_failed' );
					}
				}
			}
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			$messages = array(
				'ticket_type_unavailable' => __( 'Diese Ticketart ist nicht verfügbar.', 'wp-pass-captain' ),
				'occurrence_unavailable' => __( 'Dieser Termin ist nicht mehr verfügbar.', 'wp-pass-captain' ),
				'sales_closed' => __( 'Der Verkauf für diese Ticketart ist geschlossen.', 'wp-pass-captain' ),
				'mixed_currency' => __( 'Tickets mit unterschiedlichen Währungen können nicht gemeinsam bestellt werden.', 'wp-pass-captain' ),
				'sold_out' => __( 'Für diese Ticketart sind nicht mehr genügend Tickets verfügbar.', 'wp-pass-captain' ),
				'occurrence_sold_out' => __( 'Für diesen Termin sind nicht mehr genügend Plätze verfügbar.', 'wp-pass-captain' ),
			);
			$code = $error->getMessage();
			$message = $messages[ $code ] ?? __( 'Die Bestellung konnte nicht angelegt werden.', 'wp-pass-captain' );
			return new \WP_Error( 'wpcap_checkout_failed', $message, array( 'status' => 409 ) );
		}

		if ( 0 === $total ) {
			self::issue_and_email( $order_id );
		}
		Audit::record( 'order_created', 'order', $order_id, array( 'total_minor' => $total, 'currency' => $currency ) );
		return array( 'order_id' => $order_id, 'public_id' => $public_id, 'status' => $total ? 'pending_payment' : 'complete', 'total_minor' => $total, 'currency' => $currency, 'hold_minutes' => $total ? self::HOLD_MINUTES : 0 );
	}

	public static function confirm_manual_payment( $order_id ) {
		global $wpdb;
		$orders = $wpdb->prefix . 'wpcap_orders';
		$wpdb->query( 'START TRANSACTION' );
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $orders WHERE order_id=%d FOR UPDATE", absint( $order_id ) ) );
		if ( ! $order || 'paid' === $order->payment_status || 'refunded' === $order->status ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_order_state', __( 'Der Bestellstatus erlaubt diese Aktion nicht.', 'wp-pass-captain' ) );
		}
		$expires = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(expires_at) FROM {$wpdb->prefix}wpcap_reservations WHERE order_id=%d AND status='active'", $order_id ) );
		if ( ! $expires || $expires <= current_time( 'mysql', true ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_hold_expired', __( 'Die Reservierung ist abgelaufen; bitte erstelle eine neue Bestellung.', 'wp-pass-captain' ) );
		}
		$wpdb->update( $orders, array( 'status' => 'complete', 'payment_status' => 'paid', 'updated_at' => current_time( 'mysql', true ) ), array( 'order_id' => $order_id ), array( '%s', '%s', '%s' ), array( '%d' ) );
		$wpdb->update( $wpdb->prefix . 'wpcap_reservations', array( 'status' => 'converted' ), array( 'order_id' => $order_id, 'status' => 'active' ), array( '%s' ), array( '%d', '%s' ) );
		$wpdb->query( 'COMMIT' );
		self::issue_and_email( $order_id );
		Audit::record( 'manual_payment_confirmed', 'order', $order_id );
		return true;
	}

	public static function expire_holds() {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$reservations = $wpdb->prefix . 'wpcap_reservations';
		$orders = $wpdb->prefix . 'wpcap_orders';
		$wpdb->query( $wpdb->prepare( "UPDATE $reservations SET status='expired' WHERE status='active' AND expires_at <= %s", $now ) );
		$wpdb->query( $wpdb->prepare(
			"UPDATE $orders SET status='expired',payment_status='expired',updated_at=%s WHERE payment_status='pending' AND NOT EXISTS (SELECT 1 FROM $reservations r WHERE r.order_id=$orders.order_id AND r.status='active' AND r.expires_at > %s)",
			$now,
			$now
		) );
	}

	private static function issue_and_email( $order_id ) {
		global $wpdb;
		$orders = $wpdb->prefix . 'wpcap_orders';
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $orders WHERE order_id=%d", $order_id ) );
		if ( ! $order || 'paid' !== $order->payment_status ) {
			return;
		}
		$lines = $wpdb->get_results( $wpdb->prepare( "SELECT oi.*,tt.name AS ticket_name,o.event_id,e.name AS event_name,o.starts_at,o.timezone,o.location FROM {$wpdb->prefix}wpcap_order_items oi INNER JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$wpdb->prefix}wpcap_events e ON e.event_id=o.event_id WHERE oi.order_id=%d", $order_id ) );
		$issued = array();
		foreach ( $lines as $line ) {
			$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_tickets WHERE order_id=%d AND ticket_type_id=%d", $order_id, $line->ticket_type_id ) );
			for ( $i = $existing; $i < (int) $line->quantity; $i++ ) {
				$token = bin2hex( random_bytes( 32 ) );
				$public_id = bin2hex( random_bytes( 16 ) );
				$wpdb->insert( $wpdb->prefix . 'wpcap_tickets', array(
					'public_id' => $public_id,
					'order_id' => $order_id,
					'ticket_type_id' => (int) $line->ticket_type_id,
					'token_hash' => hash( 'sha256', $token ),
					'status' => 'valid',
					'issued_at' => current_time( 'mysql', true ),
				), array( '%s', '%d', '%d', '%s', '%s', '%s' ) );
				if ( $wpdb->insert_id ) {
					$issued[] = array( 'token' => $token, 'event' => $line->event_name, 'ticket' => $line->ticket_name, 'starts_at' => $line->starts_at, 'timezone' => $line->timezone, 'location' => $line->location );
				}
			}
		}
		if ( $issued ) {
			$body = __( 'Deine Tickets für WP Pass Captain:', 'wp-pass-captain' ) . "\n\n";
			foreach ( $issued as $ticket ) {
				$body .= sprintf( "%s — %s\n%s\n%s\n\n", $ticket['event'], $ticket['ticket'], mysql2date( 'd.m.Y H:i', $ticket['starts_at'], true ) . ' ' . $ticket['timezone'], $ticket['location'] );
				$ticket_url = add_query_arg( 'wpcap_ticket', $ticket['token'], home_url( '/' ) );
				$body .= __( 'Ticket öffnen oder drucken:', 'wp-pass-captain' ) . ' ' . esc_url_raw( $ticket_url ) . "\n";
				$body .= __( 'Ticket-Code:', 'wp-pass-captain' ) . ' ' . $ticket['token'] . "\n\n";
			}
			wp_mail( $order->customer_email, __( 'Deine Tickets', 'wp-pass-captain' ), $body );
		}
	}

	public static function resend_tickets( $order_id ) {
		global $wpdb;
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_orders WHERE order_id=%d", absint( $order_id ) ) );
		if ( ! $order || 'paid' !== $order->payment_status ) {
			return new \WP_Error( 'wpcap_order_unpaid', __( 'Tickets können erst nach bestätigter Zahlung erneut gesendet werden.', 'wp-pass-captain' ) );
		}
		$tickets = $wpdb->get_results( $wpdb->prepare(
			"SELECT t.ticket_id,t.status,tt.name AS ticket_name,e.name AS event_name,o.starts_at,o.timezone,o.location FROM {$wpdb->prefix}wpcap_tickets t INNER JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$wpdb->prefix}wpcap_events e ON e.event_id=o.event_id WHERE t.order_id=%d AND t.status IN ('valid','checked_in') ORDER BY t.ticket_id",
			$order_id
		) );
		if ( ! $tickets ) {
			return new \WP_Error( 'wpcap_no_resendable_tickets', __( 'Keine gültigen Tickets für den erneuten Versand gefunden.', 'wp-pass-captain' ) );
		}
		$body = __( 'Deine Tickets für WP Pass Captain:', 'wp-pass-captain' ) . "\n\n";
		foreach ( $tickets as $ticket ) {
			$token = bin2hex( random_bytes( 32 ) );
			$updated = $wpdb->update( $wpdb->prefix . 'wpcap_tickets', array( 'token_hash' => hash( 'sha256', $token ) ), array( 'ticket_id' => (int) $ticket->ticket_id, 'status' => $ticket->status ), array( '%s' ), array( '%d', '%s' ) );
			if ( false === $updated ) {
				return new \WP_Error( 'wpcap_ticket_rotation_failed', __( 'Ticketcode konnte nicht erneuert werden.', 'wp-pass-captain' ) );
			}
			$ticket_url = add_query_arg( 'wpcap_ticket', $token, home_url( '/' ) );
			$body .= sprintf( "%s — %s\n%s %s\n%s\n%s\n\n", $ticket->event_name, $ticket->ticket_name, mysql2date( 'd.m.Y H:i', $ticket->starts_at, true ), $ticket->timezone, $ticket->location, esc_url_raw( $ticket_url ) );
		}
		if ( ! wp_mail( $order->customer_email, __( 'Deine Tickets', 'wp-pass-captain' ), $body ) ) {
			return new \WP_Error( 'wpcap_mail_failed', __( 'E-Mail konnte nicht zugestellt werden. Die Ticketcodes wurden erneuert; bitte versuche den Versand erneut.', 'wp-pass-captain' ) );
		}
		Audit::record( 'tickets_resent', 'order', $order_id, array( 'ticket_count' => count( $tickets ) ) );
		return true;
	}
}

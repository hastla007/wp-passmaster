<?php
/** Order, inventory hold and ticket issuance services. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Orders {
	const HOLD_MINUTES = 10;

	/**
	 * Creates an order with inventory holds.
	 *
	 * @param array $payload  Customer input: email, items, idempotency_key, campaign_codes.
	 * @param array $internal Trusted options for adapters/staff only – never fill from a request:
	 *                        external (bool), unit_prices (type_id => minor), currency, hold_minutes,
	 *                        source, key_namespace (separates idempotency keys per channel),
	 *                        skip_rate_limit (bool).
	 */
	public static function create( $payload, array $internal = array() ) {
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
		$namespace = sanitize_key( $internal['key_namespace'] ?? '' );
		// Channel keys use a keyed hash so a public caller can never claim a POS/WooCommerce key.
		$key_hash = '' === $namespace ? hash( 'sha256', $key ) : hash_hmac( 'sha256', $key, 'wpcap-channel-' . $namespace );
		// Trusted adapter options (e.g. WooCommerce): the external system owns price and payment.
		$external = ! empty( $internal['external'] );
		$hold_minutes = $external ? max( 1, absint( $internal['hold_minutes'] ?? self::HOLD_MINUTES ) ) : self::HOLD_MINUTES;
		$unit_prices = ( $external && is_array( $internal['unit_prices'] ?? null ) ) ? array_map( 'absint', $internal['unit_prices'] ) : array();
		$external_currency = $external ? strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) ( $internal['currency'] ?? '' ) ) ) : '';
		$skip_rate_limit = $external || ! empty( $internal['skip_rate_limit'] );
		$orders_table = $wpdb->prefix . 'wpcap_orders';
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT order_id,public_id,status,payment_status FROM $orders_table WHERE idempotency_key = %s", $key_hash ) );
		if ( $existing ) {
			return array( 'order_id' => (int) $existing->order_id, 'public_id' => $existing->public_id, 'status' => $existing->status, 'payment_status' => $existing->payment_status, 'reused' => true );
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
		if ( ! $skip_rate_limit ) {
			if ( $count >= 8 ) {
				return new \WP_Error( 'wpcap_rate_limited', __( 'Bitte warte kurz und versuche es erneut.', 'wp-pass-captain' ), array( 'status' => 429 ) );
			}
			set_transient( $rate_key, $count + 1, HOUR_IN_SECONDS );
		}

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
				if ( $external && 3 === strlen( $external_currency ) ) {
					$currency = $external_currency;
				} elseif ( '' === $currency ) {
					$currency = $type->currency;
				} elseif ( $currency !== $type->currency ) {
					throw new \RuntimeException( 'mixed_currency' );
				}
				$wpdb->query( $wpdb->prepare( "UPDATE $reservations_table SET status='expired' WHERE ticket_type_id=%d AND status='active' AND expires_at <= %s", $type_id, $now ) );
				$capacity_error = self::capacity_error( $type, $occurrence, $quantity );
				if ( $capacity_error ) {
					throw new \RuntimeException( $capacity_error );
				}
				$unit_price = array_key_exists( $type_id, $unit_prices ) ? (int) $unit_prices[ $type_id ] : (int) $type->price_minor;
				$type->price_minor = $unit_price;
				$line_total = $unit_price * $quantity;
				$total += $line_total;
				$selected[] = array( 'type' => $type, 'event_id' => (int) $occurrence->event_id, 'quantity' => $quantity, 'line_total' => $line_total );
			}
			$subtotal = $total;
			$campaign_codes = $external ? array() : ( $payload['campaign_codes'] ?? array() );
			if ( ! is_array( $campaign_codes ) ) { throw new \RuntimeException( 'campaign_invalid' ); }
			$adjustments = Campaigns::calculate( $selected, $campaign_codes, $currency );
			if ( is_wp_error( $adjustments ) ) { throw new \RuntimeException( 'campaign_invalid' ); }
			$discount = array_sum( array_map( static function ( $adjustment ) { return (int) $adjustment['amount_minor']; }, $adjustments ) );
			$total = max( 0, $subtotal - $discount );
			$price_snapshot = wp_json_encode( array( 'currency' => $currency, 'subtotal_minor' => $subtotal, 'discount_minor' => $discount, 'total_minor' => $total, 'campaigns' => array_map( static function ( $adjustment ) { return array( 'code' => $adjustment['campaign']->code, 'amount_minor' => (int) $adjustment['amount_minor'] ); }, $adjustments ) ) );

			$public_id = bin2hex( random_bytes( 16 ) );
			$access_token = bin2hex( random_bytes( 32 ) );
			$inserted = $wpdb->insert( $orders_table, array(
				'public_id' => $public_id,
				'idempotency_key' => $key_hash,
				'customer_email' => $email,
				'access_token_hash' => hash( 'sha256', $access_token ),
				'access_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ),
				'status' => ( $total || $external ) ? 'pending_payment' : 'complete',
				'payment_status' => ( $total || $external ) ? 'pending' : 'paid',
				'currency' => $currency,
				'total_minor' => $total,
				'subtotal_minor' => $subtotal,
				'discount_minor' => $discount,
				'price_snapshot' => $price_snapshot,
				'created_at' => $now,
				'updated_at' => $now,
			), array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s' ) );
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
				if ( '' !== $wpdb->last_error ) {
					throw new \RuntimeException( 'order_item_insert_failed' );
				}
				if ( $total || $external ) {
					$wpdb->insert( $reservations_table, array(
						'order_id' => $order_id,
						'ticket_type_id' => (int) $line['type']->ticket_type_id,
						'quantity' => (int) $line['quantity'],
						'expires_at' => gmdate( 'Y-m-d H:i:s', time() + $hold_minutes * MINUTE_IN_SECONDS ),
						'status' => 'active',
					), array( '%d', '%d', '%d', '%s', '%s' ) );
					if ( '' !== $wpdb->last_error ) {
						throw new \RuntimeException( 'reservation_insert_failed' );
					}
				}
			}
			$redeemed = Campaigns::redeem( $adjustments, $order_id );
			if ( is_wp_error( $redeemed ) ) { throw new \RuntimeException( 'campaign_redeem_failed' ); }
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
				'campaign_invalid' => __( 'Ein Kampagnencode ist ungültig oder passt nicht zur Bestellung.', 'wp-pass-captain' ),
				'campaign_redeem_failed' => __( 'Der Rabatt konnte nicht sicher auf die Bestellung angewendet werden.', 'wp-pass-captain' ),
			);
			$code = $error->getMessage();
			$message = $messages[ $code ] ?? __( 'Die Bestellung konnte nicht angelegt werden.', 'wp-pass-captain' );
			return new \WP_Error( 'wpcap_checkout_failed', $message, array( 'status' => 409 ) );
		}

		$pending = $total || $external;
		if ( ! $pending ) {
			Campaigns::commit_for_order( $order_id );
			self::issue_and_email( $order_id );
		}
		if ( ! $external ) {
			self::send_order_access_link( $email, $public_id, $access_token );
		}
		Audit::record( 'order_created', 'order', $order_id, array( 'total_minor' => $total, 'currency' => $currency, 'source' => sanitize_key( $internal['source'] ?? 'native' ) ) );
		return array( 'order_id' => $order_id, 'public_id' => $public_id, 'status' => $pending ? 'pending_payment' : 'complete', 'payment_status' => $pending ? 'pending' : 'paid', 'subtotal_minor' => $subtotal, 'discount_minor' => $discount, 'total_minor' => $total, 'currency' => $currency, 'hold_minutes' => $pending ? $hold_minutes : 0 );
	}

	/**
	 * Returns an error code if $quantity more units of $type would exceed type or occurrence capacity.
	 * Callers must hold row locks on the ticket type and occurrence. Capacity 0 means unlimited.
	 */
	private static function capacity_error( $type, $occurrence, $quantity, $exclude_order_id = 0 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = current_time( 'mysql', true );
		$exclude_order_id = absint( $exclude_order_id );
		if ( (int) $type->capacity > 0 ) {
			$sold = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(oi.quantity),0) FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_orders ord ON ord.order_id=oi.order_id WHERE oi.ticket_type_id=%d AND ord.payment_status='paid' AND ord.status <> 'refunded' AND ord.order_id <> %d", $type->ticket_type_id, $exclude_order_id ) );
			$held = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(quantity),0) FROM {$p}wpcap_reservations WHERE ticket_type_id=%d AND status='active' AND expires_at > %s AND order_id <> %d", $type->ticket_type_id, $now, $exclude_order_id ) );
			if ( $sold + $held + $quantity > (int) $type->capacity ) {
				return 'sold_out';
			}
		}
		if ( (int) $occurrence->capacity > 0 ) {
			$occ_sold = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(oi.quantity),0) FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_orders ord ON ord.order_id=oi.order_id WHERE tt.occurrence_id=%d AND ord.payment_status='paid' AND ord.status <> 'refunded' AND ord.order_id <> %d", $occurrence->occurrence_id, $exclude_order_id ) );
			$occ_held = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(r.quantity),0) FROM {$p}wpcap_reservations r INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=r.ticket_type_id WHERE tt.occurrence_id=%d AND r.status='active' AND r.expires_at > %s AND r.order_id <> %d", $occurrence->occurrence_id, $now, $exclude_order_id ) );
			if ( $occ_sold + $occ_held + $quantity > (int) $occurrence->capacity ) {
				return 'occurrence_sold_out';
			}
		}
		return '';
	}

	/**
	 * Remaining sellable units for a ticket type (non-locking, for display/cart validation).
	 *
	 * @return int|null Null when neither the type nor the occurrence is limited.
	 */
	public static function availability( $ticket_type_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$type = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d", absint( $ticket_type_id ) ) );
		if ( ! $type ) { return 0; }
		$occurrence = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_occurrences WHERE occurrence_id=%d", $type->occurrence_id ) );
		if ( ! $occurrence ) { return 0; }
		$limits = array();
		$now = current_time( 'mysql', true );
		if ( (int) $type->capacity > 0 ) {
			$used = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(oi.quantity),0) FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_orders ord ON ord.order_id=oi.order_id WHERE oi.ticket_type_id=%d AND ord.payment_status='paid' AND ord.status <> 'refunded'", $type->ticket_type_id ) )
				+ (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(quantity),0) FROM {$p}wpcap_reservations WHERE ticket_type_id=%d AND status='active' AND expires_at > %s", $type->ticket_type_id, $now ) );
			$limits[] = (int) $type->capacity - $used;
		}
		if ( (int) $occurrence->capacity > 0 ) {
			$used = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(oi.quantity),0) FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_orders ord ON ord.order_id=oi.order_id WHERE tt.occurrence_id=%d AND ord.payment_status='paid' AND ord.status <> 'refunded'", $occurrence->occurrence_id ) )
				+ (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(r.quantity),0) FROM {$p}wpcap_reservations r INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=r.ticket_type_id WHERE tt.occurrence_id=%d AND r.status='active' AND r.expires_at > %s", $occurrence->occurrence_id, $now ) );
			$limits[] = (int) $occurrence->capacity - $used;
		}
		return $limits ? max( 0, min( $limits ) ) : null;
	}

	/**
	 * Confirms a payment reported by an external system (e.g. WooCommerce). Idempotent.
	 *
	 * Active holds are converted. If the hold has lapsed, capacity is re-checked under row locks
	 * (late payment recovery). If it is no longer available, the order is flagged `needs_review`
	 * with payment status `paid_unallocated` – no tickets are issued and nothing is overbooked.
	 *
	 * @return true|\WP_Error
	 */
	public static function confirm_external_payment( $order_id, $reference = '' ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order_id = absint( $order_id );
		$now = current_time( 'mysql', true );
		$wpdb->query( 'START TRANSACTION' );
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_orders WHERE order_id=%d FOR UPDATE", $order_id ) );
		if ( ! $order ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_order_missing', __( 'Bestellung nicht gefunden.', 'wp-pass-captain' ) );
		}
		if ( 'paid' === $order->payment_status ) {
			$wpdb->query( 'COMMIT' );
			self::issue_and_email( $order_id );
			return true;
		}
		if ( in_array( $order->status, array( 'refunded', 'cancelled' ), true ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_order_state', __( 'Der Bestellstatus erlaubt diese Aktion nicht.', 'wp-pass-captain' ) );
		}
		$active = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_reservations WHERE order_id=%d AND status='active' AND expires_at > %s", $order_id, $now ) );
		$lines = $wpdb->get_results( $wpdb->prepare( "SELECT ticket_type_id, SUM(quantity) AS quantity FROM {$p}wpcap_order_items WHERE order_id=%d GROUP BY ticket_type_id ORDER BY ticket_type_id", $order_id ) );
		$recovered = false;
		if ( ! $active ) {
			// Late payment: lock occurrences first, then ticket types (stable order), and re-check capacity.
			$type_ids = array_map( static function ( $line ) { return (int) $line->ticket_type_id; }, $lines );
			$ids_sql = implode( ',', $type_ids ? $type_ids : array( 0 ) );
			$occ_ids = $wpdb->get_col( "SELECT DISTINCT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id IN ($ids_sql) ORDER BY occurrence_id" );
			$occurrences = array();
			foreach ( $occ_ids as $occ_id ) {
				$occurrences[ (int) $occ_id ] = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_occurrences WHERE occurrence_id=%d FOR UPDATE", $occ_id ) );
			}
			$error = '';
			$per_occurrence = array();
			foreach ( $lines as $line ) {
				$type = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d FOR UPDATE", $line->ticket_type_id ) );
				$occurrence = $type ? ( $occurrences[ (int) $type->occurrence_id ] ?? null ) : null;
				if ( ! $type || ! $occurrence ) { $error = 'ticket_type_unavailable'; break; }
				$check_occurrence = clone $occurrence;
				$check_occurrence->capacity = 0; // Occurrence capacity is checked below for the summed quantity.
				$error = self::capacity_error( $type, $check_occurrence, (int) $line->quantity, $order_id );
				if ( $error ) { break; }
				$per_occurrence[ (int) $occurrence->occurrence_id ] = ( $per_occurrence[ (int) $occurrence->occurrence_id ] ?? 0 ) + (int) $line->quantity;
			}
			if ( ! $error ) {
				foreach ( $per_occurrence as $occ_id => $quantity ) {
					$unlimited_type = (object) array( 'ticket_type_id' => 0, 'capacity' => 0 );
					$error = self::capacity_error( $unlimited_type, $occurrences[ $occ_id ], $quantity, $order_id );
					if ( $error ) { break; }
				}
			}
			if ( $error ) {
				$wpdb->update( "{$p}wpcap_orders", array( 'status' => 'needs_review', 'payment_status' => 'paid_unallocated', 'updated_at' => $now ), array( 'order_id' => $order_id ), array( '%s', '%s', '%s' ), array( '%d' ) );
				$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_reservations SET status='expired' WHERE order_id=%d AND status='active'", $order_id ) );
				$wpdb->query( 'COMMIT' );
				Audit::record( 'payment_unallocated', 'order', $order_id, array( 'reason' => $error, 'reference' => sanitize_text_field( $reference ) ) );
				wp_mail( get_option( 'admin_email' ), __( 'WP Pass Captain: Bezahlte Bestellung ohne freies Kontingent', 'wp-pass-captain' ), sprintf( __( 'Die Zahlung für Bestellung %1$s (%2$s) ist nach Ablauf der Reservierung eingegangen und es ist kein Kontingent mehr frei. Es wurden keine Tickets ausgestellt. Bitte manuell klären (Platz freigeben oder erstatten).', 'wp-pass-captain' ), $order->public_id, sanitize_text_field( $reference ) ) );
				return new \WP_Error( 'wpcap_needs_review', __( 'Die Reservierung war abgelaufen und es ist kein Kontingent mehr frei. Die Bestellung muss manuell geklärt werden.', 'wp-pass-captain' ) );
			}
			$recovered = true;
		}
		$wpdb->update( "{$p}wpcap_orders", array( 'status' => 'complete', 'payment_status' => 'paid', 'updated_at' => $now ), array( 'order_id' => $order_id ), array( '%s', '%s', '%s' ), array( '%d' ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_reservations SET status='converted' WHERE order_id=%d AND status IN ('active','expired','released')", $order_id ) );
		Campaigns::commit_for_order( $order_id );
		$wpdb->query( 'COMMIT' );
		Audit::record( 'external_payment_confirmed', 'order', $order_id, array( 'reference' => sanitize_text_field( $reference ), 'late_recovery' => $recovered ) );
		self::issue_and_email( $order_id );
		return true;
	}

	/** Extends active holds of a pending order (e.g. WooCommerce "on-hold" bank transfers). */
	public static function extend_hold( $order_id, $minutes ) {
		global $wpdb;
		$until = gmdate( 'Y-m-d H:i:s', time() + max( 1, absint( $minutes ) ) * MINUTE_IN_SECONDS );
		return $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_reservations SET expires_at=GREATEST(expires_at,%s) WHERE order_id=%d AND status='active' AND expires_at > %s", $until, absint( $order_id ), current_time( 'mysql', true ) ) );
	}

	/**
	 * Cancels an order: releases holds and campaign reservations; for paid orders all still valid
	 * tickets are revoked (checked-in tickets stay as historical record). Idempotent.
	 */
	public static function cancel_order( $order_id, $reason = '' ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order_id = absint( $order_id );
		$now = current_time( 'mysql', true );
		$wpdb->query( 'START TRANSACTION' );
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_orders WHERE order_id=%d FOR UPDATE", $order_id ) );
		if ( ! $order || in_array( $order->status, array( 'cancelled', 'refunded' ), true ) ) {
			$wpdb->query( 'COMMIT' );
			return true;
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_reservations SET status='released' WHERE order_id=%d AND status='active'", $order_id ) );
		$revoked = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_tickets SET status='revoked' WHERE order_id=%d AND status='valid'", $order_id ) );
		$wpdb->update( "{$p}wpcap_orders", array( 'status' => 'cancelled', 'payment_status' => 'cancelled', 'updated_at' => $now ), array( 'order_id' => $order_id ), array( '%s', '%s', '%s' ), array( '%d' ) );
		$wpdb->query( 'COMMIT' );
		Campaigns::release_for_order( $order_id );
		Audit::record( 'order_cancelled', 'order', $order_id, array( 'reason' => sanitize_text_field( $reason ), 'revoked_tickets' => $revoked ) );
		return true;
	}

	/**
	 * Revokes up to $count still valid tickets of a ticket type (newest first). Checked-in tickets
	 * are never revoked. Returns the number of revoked tickets.
	 */
	public static function revoke_tickets( $order_id, $ticket_type_id, $count, $reason = '' ) {
		global $wpdb;
		$count = absint( $count );
		if ( ! $count ) { return 0; }
		$revoked = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_tickets SET status='revoked' WHERE order_id=%d AND ticket_type_id=%d AND status='valid' ORDER BY ticket_id DESC LIMIT %d", absint( $order_id ), absint( $ticket_type_id ), $count ) );
		if ( $revoked ) {
			Audit::record( 'tickets_revoked', 'order', absint( $order_id ), array( 'ticket_type_id' => absint( $ticket_type_id ), 'count' => $revoked, 'reason' => sanitize_text_field( $reason ) ) );
		}
		return $revoked;
	}

	/** Marks an order as fully refunded and revokes all still valid tickets. Idempotent. */
	public static function mark_refunded( $order_id, $reason = '' ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order_id = absint( $order_id );
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM {$p}wpcap_orders WHERE order_id=%d", $order_id ) );
		if ( ! $order || 'refunded' === $order->status ) { return true; }
		$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_reservations SET status='released' WHERE order_id=%d AND status='active'", $order_id ) );
		$revoked = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_tickets SET status='revoked' WHERE order_id=%d AND status='valid'", $order_id ) );
		$wpdb->update( "{$p}wpcap_orders", array( 'status' => 'refunded', 'payment_status' => 'refunded', 'updated_at' => current_time( 'mysql', true ) ), array( 'order_id' => $order_id ), array( '%s', '%s', '%s' ), array( '%d' ) );
		Audit::record( 'order_refunded', 'order', $order_id, array( 'reason' => sanitize_text_field( $reason ), 'revoked_tickets' => $revoked ) );
		return true;
	}

	/** Issues a new personal order access link (old link becomes invalid) and emails it. */
	public static function rotate_access_link( $order_id ) {
		global $wpdb;
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT order_id,public_id,customer_email FROM {$wpdb->prefix}wpcap_orders WHERE order_id=%d", absint( $order_id ) ) );
		if ( ! $order ) { return false; }
		$token = bin2hex( random_bytes( 32 ) );
		$wpdb->update( $wpdb->prefix . 'wpcap_orders', array( 'access_token_hash' => hash( 'sha256', $token ), 'access_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ) ), array( 'order_id' => (int) $order->order_id ), array( '%s', '%s' ), array( '%d' ) );
		return self::send_order_access_link( $order->customer_email, $order->public_id, $token );
	}

	/** Returns tickets of an order with event context (no tokens). */
	public static function tickets_for_order( $order_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_results( $wpdb->prepare( "SELECT t.ticket_id,t.public_id,t.status,t.ticket_type_id,tt.name AS ticket_name,e.name AS event_name,o.starts_at,o.timezone,o.location FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE t.order_id=%d ORDER BY t.ticket_id", absint( $order_id ) ) );
	}

	/** Returns an order row. */
	public static function get( $order_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_orders WHERE order_id=%d", absint( $order_id ) ) );
	}

	public static function send_order_access_link( $email, $public_id, $access_token ) {
		$url = add_query_arg( array( 'wpcap_order' => $public_id, 'wpcap_access' => $access_token ), home_url( '/' ) );
		$body = __( 'Über diesen persönlichen Link kannst du den Status deiner WP Pass Captain Bestellung ansehen und Tickets verwalten:', 'wp-pass-captain' ) . "\n\n" . esc_url_raw( $url ) . "\n\n" . __( 'Bewahre diesen Link sicher auf und teile ihn nicht.', 'wp-pass-captain' );
		return wp_mail( sanitize_email( $email ), __( 'Deine Bestellung bei WP Pass Captain', 'wp-pass-captain' ), $body );
	}

	public static function transfer_ticket( $public_id, $access_token, $ticket_public_id, $recipient_email ) {
		global $wpdb;
		$public_id = sanitize_text_field( $public_id );
		$access_token = strtolower( preg_replace( '/[^a-f0-9]/', '', (string) $access_token ) );
		$recipient_email = sanitize_email( $recipient_email );
		if ( 32 !== strlen( $public_id ) || 64 !== strlen( $access_token ) || ! is_email( $recipient_email ) ) {
			return new \WP_Error( 'wpcap_transfer_invalid', __( 'Die Übertragungsdaten sind ungültig.', 'wp-pass-captain' ), array( 'status' => 400 ) );
		}
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_orders WHERE public_id=%s", $public_id ) );
		if ( ! $order || ! $order->access_expires_at || $order->access_expires_at <= current_time( 'mysql', true ) || ! hash_equals( (string) $order->access_token_hash, hash( 'sha256', $access_token ) ) ) {
			return new \WP_Error( 'wpcap_order_access_denied', __( 'Der Bestellzugriff ist ungültig.', 'wp-pass-captain' ), array( 'status' => 403 ) );
		}
		if ( 'paid' !== $order->payment_status ) {
			return new \WP_Error( 'wpcap_order_not_paid', __( 'Tickets können erst nach bestätigter Zahlung übertragen werden.', 'wp-pass-captain' ), array( 'status' => 409 ) );
		}
		$tickets_table = $wpdb->prefix . 'wpcap_tickets';
		$wpdb->query( 'START TRANSACTION' );
		$ticket = $wpdb->get_row( $wpdb->prepare(
			"SELECT t.ticket_id,t.status,t.transfer_count,tt.name AS ticket_name,e.name AS event_name,o.starts_at,o.timezone,o.location FROM $tickets_table t INNER JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$wpdb->prefix}wpcap_events e ON e.event_id=o.event_id WHERE t.public_id=%s AND t.order_id=%d FOR UPDATE",
			sanitize_text_field( $ticket_public_id ),
			$order->order_id
		) );
		if ( ! $ticket || 'valid' !== $ticket->status || (int) $ticket->transfer_count >= 1 || strtotime( $ticket->starts_at . ' UTC' ) <= time() ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_transfer_not_allowed', __( 'Dieses Ticket kann nicht mehr übertragen werden.', 'wp-pass-captain' ), array( 'status' => 409 ) );
		}
		$token = bin2hex( random_bytes( 32 ) );
		$updated = $wpdb->update( $tickets_table, array(
			'token_hash' => hash( 'sha256', $token ),
			'attendee_email' => $recipient_email,
			'transfer_count' => (int) $ticket->transfer_count + 1,
		), array( 'ticket_id' => (int) $ticket->ticket_id, 'status' => 'valid', 'transfer_count' => (int) $ticket->transfer_count ), array( '%s', '%s', '%d' ), array( '%d', '%s', '%d' ) );
		if ( false === $updated ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_transfer_failed', __( 'Die Ticketübertragung konnte nicht gespeichert werden.', 'wp-pass-captain' ), array( 'status' => 500 ) );
		}
		$wpdb->query( 'COMMIT' );
		$ticket_url = add_query_arg( 'wpcap_ticket', $token, home_url( '/' ) );
		$body = sprintf( "%s — %s\n%s %s\n%s\n\n%s\n", $ticket->event_name, $ticket->ticket_name, mysql2date( 'd.m.Y H:i', $ticket->starts_at, true ), $ticket->timezone, $ticket->location, esc_url_raw( $ticket_url ) );
		if ( ! wp_mail( $recipient_email, __( 'Ein Ticket wurde an dich übertragen', 'wp-pass-captain' ), $body ) ) {
			return new \WP_Error( 'wpcap_transfer_mail_failed', __( 'Die Ticketübertragung wurde gespeichert, aber die E-Mail konnte nicht zugestellt werden. Bitte kontaktiere den Veranstalter für einen erneuten Versand.', 'wp-pass-captain' ), array( 'status' => 502 ) );
		}
		wp_mail( $order->customer_email, __( 'Ticketübertragung abgeschlossen', 'wp-pass-captain' ), sprintf( __( 'Dein Ticket für %s wurde an %s übertragen. Der bisherige Ticketcode ist ungültig.', 'wp-pass-captain' ), $ticket->event_name, $recipient_email ) );
		Audit::record( 'ticket_transferred', 'ticket', $ticket->ticket_id, array( 'order_id' => (int) $order->order_id ) );
		return array( 'status' => 'transferred' );
	}

	public static function cancel_event( $event_id ) {
		global $wpdb;
		$event_id = absint( $event_id );
		$event = $wpdb->get_row( $wpdb->prepare( "SELECT event_id,name,status FROM {$wpdb->prefix}wpcap_events WHERE event_id=%d", $event_id ) );
		if ( ! $event || 'published' !== $event->status ) {
			return new \WP_Error( 'wpcap_event_not_cancellable', __( 'Dieses Event kann nicht abgesagt werden.', 'wp-pass-captain' ) );
		}
		$wpdb->query( 'START TRANSACTION' );
		$wpdb->update( $wpdb->prefix . 'wpcap_events', array( 'status' => 'cancelled', 'updated_at' => current_time( 'mysql', true ) ), array( 'event_id' => $event_id, 'status' => 'published' ), array( '%s', '%s' ), array( '%d', '%s' ) );
		$wpdb->update( $wpdb->prefix . 'wpcap_occurrences', array( 'status' => 'cancelled' ), array( 'event_id' => $event_id ), array( '%s' ), array( '%d' ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_tickets t INNER JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id SET t.status='revoked' WHERE o.event_id=%d AND t.status='valid'", $event_id ) );
		$order_ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT oi.order_id FROM {$wpdb->prefix}wpcap_order_items oi INNER JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE o.event_id=%d", $event_id ) );
		foreach ( $order_ids as $order_id ) {
			$wpdb->update( $wpdb->prefix . 'wpcap_reservations', array( 'status' => 'released' ), array( 'order_id' => absint( $order_id ), 'status' => 'active' ), array( '%s' ), array( '%d', '%s' ) );
			$wpdb->update( $wpdb->prefix . 'wpcap_orders', array( 'status' => 'cancelled', 'payment_status' => 'cancelled', 'updated_at' => current_time( 'mysql', true ) ), array( 'order_id' => absint( $order_id ), 'payment_status' => 'pending' ), array( '%s', '%s', '%s' ), array( '%d', '%s' ) );
			Campaigns::release_for_order( $order_id );
		}
		$wpdb->query( 'COMMIT' );
		$emails = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT COALESCE(NULLIF(t.attendee_email,''),ord.customer_email) FROM {$wpdb->prefix}wpcap_orders ord INNER JOIN {$wpdb->prefix}wpcap_order_items oi ON oi.order_id=ord.order_id INNER JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id LEFT JOIN {$wpdb->prefix}wpcap_tickets t ON t.order_id=ord.order_id AND t.ticket_type_id=tt.ticket_type_id WHERE o.event_id=%d",
			$event_id
		) );
		$message = sprintf( __( 'Das Event „%s“ wurde abgesagt. Bitte kontaktiere den Veranstalter für Informationen zu den nächsten Schritten. Diese Nachricht ist keine Bestätigung einer Erstattung.', 'wp-pass-captain' ), $event->name );
		foreach ( $emails as $email ) {
			if ( is_email( $email ) ) { wp_mail( $email, sprintf( __( 'Eventabsage: %s', 'wp-pass-captain' ), $event->name ), $message ); }
		}
		Audit::record( 'event_cancelled', 'event', $event_id, array( 'notified_count' => count( $emails ) ) );
		return array( 'status' => 'cancelled', 'notified_count' => count( $emails ) );
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
		Campaigns::commit_for_order( $order_id );
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
		$expired_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ord.order_id FROM $orders ord WHERE ord.payment_status='pending' AND NOT EXISTS (SELECT 1 FROM $reservations r WHERE r.order_id=ord.order_id AND r.status='active' AND r.expires_at>%s)", $now ) );
		$wpdb->query( $wpdb->prepare( "UPDATE $reservations SET status='expired' WHERE status='active' AND expires_at <= %s", $now ) );
		$wpdb->query( $wpdb->prepare(
			"UPDATE $orders SET status='expired',payment_status='expired',updated_at=%s WHERE payment_status='pending' AND NOT EXISTS (SELECT 1 FROM $reservations r WHERE r.order_id=$orders.order_id AND r.status='active' AND r.expires_at > %s)",
			$now,
			$now
		) );
		foreach ( $expired_ids as $expired_id ) { Campaigns::release_for_order( $expired_id ); }
		return true;
	}

	public static function issue_and_email( $order_id ) {
		global $wpdb;
		$orders = $wpdb->prefix . 'wpcap_orders';
		// Serialise issuance per order so concurrent payment callbacks cannot create duplicates.
		$wpdb->query( 'START TRANSACTION' );
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $orders WHERE order_id=%d FOR UPDATE", $order_id ) );
		if ( ! $order || 'paid' !== $order->payment_status ) {
			$wpdb->query( 'COMMIT' );
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
		$wpdb->query( 'COMMIT' );
		if ( $issued ) {
			do_action( 'wpcap_tickets_issued', (int) $order_id, count( $issued ) );
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
			"SELECT t.ticket_id,t.status,t.attendee_email,tt.name AS ticket_name,e.name AS event_name,o.starts_at,o.timezone,o.location FROM {$wpdb->prefix}wpcap_tickets t INNER JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$wpdb->prefix}wpcap_events e ON e.event_id=o.event_id WHERE t.order_id=%d AND t.status IN ('valid','checked_in') ORDER BY t.ticket_id",
			$order_id
		) );
		if ( ! $tickets ) {
			return new \WP_Error( 'wpcap_no_resendable_tickets', __( 'Keine gültigen Tickets für den erneuten Versand gefunden.', 'wp-pass-captain' ) );
		}
		$messages = array();
		foreach ( $tickets as $ticket ) {
			$token = bin2hex( random_bytes( 32 ) );
			$updated = $wpdb->update( $wpdb->prefix . 'wpcap_tickets', array( 'token_hash' => hash( 'sha256', $token ) ), array( 'ticket_id' => (int) $ticket->ticket_id, 'status' => $ticket->status ), array( '%s' ), array( '%d', '%s' ) );
			if ( false === $updated ) {
				return new \WP_Error( 'wpcap_ticket_rotation_failed', __( 'Ticketcode konnte nicht erneuert werden.', 'wp-pass-captain' ) );
			}
			$ticket_url = add_query_arg( 'wpcap_ticket', $token, home_url( '/' ) );
			$recipient = is_email( $ticket->attendee_email ) ? $ticket->attendee_email : $order->customer_email;
			$messages[ $recipient ] = ( $messages[ $recipient ] ?? __( 'Deine Tickets für WP Pass Captain:', 'wp-pass-captain' ) . "\n\n" ) . sprintf( "%s — %s\n%s %s\n%s\n%s\n\n", $ticket->event_name, $ticket->ticket_name, mysql2date( 'd.m.Y H:i', $ticket->starts_at, true ), $ticket->timezone, $ticket->location, esc_url_raw( $ticket_url ) );
		}
		$sent = true;
		foreach ( $messages as $recipient => $body ) {
			$sent = wp_mail( $recipient, __( 'Deine Tickets', 'wp-pass-captain' ), $body ) && $sent;
		}
		if ( ! $sent ) {
			return new \WP_Error( 'wpcap_mail_failed', __( 'Mindestens eine E-Mail konnte nicht zugestellt werden. Ticketcodes wurden erneuert; bitte versuche den Versand erneut.', 'wp-pass-captain' ) );
		}
		Audit::record( 'tickets_resent', 'order', $order_id, array( 'ticket_count' => count( $tickets ) ) );
		return true;
	}
}

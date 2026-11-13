<?php
/** Order, inventory hold and ticket issuance services. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Orders {
	const HOLD_MINUTES = 10;

	/**
	 * Creates an order with inventory holds.
	 *
	 * @param array $payload  Customer input: email, items, idempotency_key, campaign_codes,
	 *                        attendees (list of {ticket_type_id, name, email, answers{field_key:value}}),
	 *                        accept_terms (bool), billing ({enabled, company, first_name, last_name,
	 *                        street, postcode, city, country, vat_id, po_number}; see Billing::validate()).
	 * @param array $internal Trusted options for adapters/staff only – never fill from a request:
	 *                        external (bool), unit_prices / line_totals / line_taxes (type_id => minor),
	 *                        currency, hold_minutes, source, payment_provider, key_namespace
	 *                        (separates idempotency keys per channel), skip_rate_limit (bool),
	 *                        billing (external only: billing data already validated by the adapter).
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
		$hold_minutes = max( 1, absint( $internal['hold_minutes'] ?? Settings::get( 'hold_minutes' ) ?? self::HOLD_MINUTES ) );
		$line_taxes = ( $external && is_array( $internal['line_taxes'] ?? null ) ) ? array_map( 'absint', $internal['line_taxes'] ) : array();
		$payment_provider = sanitize_key( $internal['payment_provider'] ?? ( $external ? ( $internal['source'] ?? 'external' ) : 'manual' ) );
		if ( ! $external && Settings::get( 'require_terms' ) && empty( $payload['accept_terms'] ) ) {
			return new \WP_Error( 'wpcap_terms_required', __( 'Bitte bestätige die Bedingungen und Verbraucherinformationen.', 'wp-pass-captain' ), array( 'status' => 400 ) );
		}
		$unit_prices = ( $external && is_array( $internal['unit_prices'] ?? null ) ) ? array_map( 'absint', $internal['unit_prices'] ) : array();
		// Exact gross line totals from the external system (unit prices may not divide evenly).
		$line_totals = ( $external && is_array( $internal['line_totals'] ?? null ) ) ? array_map( 'absint', $internal['line_totals'] ) : array();
		$external_currency = $external ? strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) ( $internal['currency'] ?? '' ) ) ) : '';
		$skip_rate_limit = $external || ! empty( $internal['skip_rate_limit'] );
		$orders_table = $wpdb->prefix . 'wpcap_orders';
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT order_id,public_id,status,payment_status FROM $orders_table WHERE idempotency_key = %s", $key_hash ) );
		if ( $existing ) {
			return array( 'order_id' => (int) $existing->order_id, 'public_id' => $existing->public_id, 'status' => $existing->status, 'payment_status' => $existing->payment_status, 'reused' => true );
		}

		// The per-type limit is the ticket type's max_per_order (checked under lock below); this is
		// only an absolute safety cap against absurd quantities before any row is locked.
		$line_cap = max( 1, (int) apply_filters( 'wpcap_max_line_quantity', 100 ) );
		$clean_lines = array();
		foreach ( $lines as $line ) {
			$type_id = absint( $line['ticket_type_id'] ?? 0 );
			$quantity = absint( $line['quantity'] ?? 0 );
			if ( ! $type_id || $quantity < 1 || $quantity > $line_cap ) {
				return new \WP_Error( 'wpcap_invalid_item', __( 'Eine Ticketposition ist ungültig.', 'wp-pass-captain' ), array( 'status' => 400 ) );
			}
			$clean_lines[ $type_id ] = ( $clean_lines[ $type_id ] ?? 0 ) + $quantity;
			if ( $clean_lines[ $type_id ] > $line_cap ) {
				return new \WP_Error( 'wpcap_invalid_item', __( 'Eine Ticketposition ist ungültig.', 'wp-pass-captain' ), array( 'status' => 400 ) );
			}
		}
		$attendees = self::validate_attendees( $clean_lines, $payload['attendees'] ?? array() );
		if ( is_wp_error( $attendees ) ) { return $attendees; }
		// Buyer billing data (company/address/VAT ID). Trusted adapters (e.g. WooCommerce) pass data that
		// their own checkout already validated as $internal['billing']; customer input is validated here.
		$billing = ( $external && is_array( $internal['billing'] ?? null ) ) ? Billing::sanitize( $internal['billing'] ) : Billing::validate( $payload['billing'] ?? null );
		if ( is_wp_error( $billing ) ) { return $billing; }
		$billing = $billing && ! Billing::is_empty( $billing ) ? $billing : array();

		// Keep anonymous checkout abuse bounded without retaining raw IP addresses.
		$ip = self::client_ip();
		$rate_key = 'wpcap_checkout_' . self::rate_hash( $ip . '|' . strtolower( $email ) );
		$count = (int) get_transient( $rate_key );
		if ( ! $skip_rate_limit ) {
			// Per IP+email and per IP alone, so rotating email addresses cannot bypass the limit.
			$ip_key = 'wpcap_checkout_ip_' . self::rate_hash( $ip );
			$ip_count = (int) get_transient( $ip_key );
			if ( $count >= 8 || $ip_count >= (int) apply_filters( 'wpcap_checkout_ip_limit', 30 ) ) {
				return new \WP_Error( 'wpcap_rate_limited', __( 'Bitte warte kurz und versuche es erneut.', 'wp-pass-captain' ), array( 'status' => 429 ) );
			}
			set_transient( $rate_key, $count + 1, HOUR_IN_SECONDS );
			set_transient( $ip_key, $ip_count + 1, HOUR_IN_SECONDS );
		}

		$types_table = $wpdb->prefix . 'wpcap_ticket_types';
		$occ_table = $wpdb->prefix . 'wpcap_occurrences';
		$reservations_table = $wpdb->prefix . 'wpcap_reservations';
		$tickets_table = $wpdb->prefix . 'wpcap_tickets';
		$items_table = $wpdb->prefix . 'wpcap_order_items';
		$event_table = $wpdb->prefix . 'wpcap_events';
		$now = current_time( 'mysql', true );
		$door_sale = ! empty( $internal['allow_door_sales'] ) || 'pos' === $namespace;
		$custom_error = null;
		$wpdb->query( 'START TRANSACTION' );
		try {
			ksort( $clean_lines, SORT_NUMERIC );
			// Lock phase (locking reads only, so the transaction's consistent snapshot is taken after
			// every lock is held): ticket types by id, then their occurrences by id, then module
			// resources via `wpcap_reserve_capacity`. Late payment recovery uses the same order.
			$types = array();
			foreach ( $clean_lines as $type_id => $quantity ) {
				$type = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $types_table WHERE ticket_type_id = %d FOR UPDATE", $type_id ) );
				if ( ! $type || 'active' !== $type->status ) {
					throw new \RuntimeException( 'ticket_type_unavailable' );
				}
				if ( $quantity > (int) $type->max_per_order ) {
					throw new \RuntimeException( 'quantity_limit' );
				}
				$types[ $type_id ] = $type;
			}
			$occurrence_ids = array_unique( array_map( static function ( $type ) { return (int) $type->occurrence_id; }, $types ) );
			sort( $occurrence_ids, SORT_NUMERIC );
			$occurrences = array();
			foreach ( $occurrence_ids as $occurrence_id ) {
				$occurrences[ $occurrence_id ] = $wpdb->get_row( $wpdb->prepare(
					"SELECT o.*,e.name AS event_name,e.status AS event_status FROM $occ_table o INNER JOIN $event_table e ON e.event_id=o.event_id WHERE o.occurrence_id=%d FOR UPDATE",
					$occurrence_id
				) );
			}
			$currency = '';
			foreach ( $types as $type_id => $type ) {
				$occurrence = $occurrences[ (int) $type->occurrence_id ];
				// Door sales (box office) may sell after the start and outside the online sales window,
				// but never for an occurrence that has ended.
				$ended = $occurrence && ( $occurrence->ends_at ? $occurrence->ends_at : gmdate( 'Y-m-d H:i:s', strtotime( $occurrence->starts_at . ' UTC' ) + 6 * HOUR_IN_SECONDS ) ) < $now;
				$started = $occurrence && strtotime( $occurrence->starts_at . ' UTC' ) < time();
				if ( ! $occurrence || 'published' !== $occurrence->event_status || 'scheduled' !== $occurrence->status || ( $door_sale ? $ended : $started ) ) {
					throw new \RuntimeException( 'occurrence_unavailable' );
				}
				if ( ! $door_sale && ( ( $type->sales_start && $type->sales_start > $now ) || ( $type->sales_end && $type->sales_end < $now ) ) ) {
					throw new \RuntimeException( 'sales_closed' );
				}
				if ( $external && 3 === strlen( $external_currency ) ) {
					$currency = $external_currency;
				} elseif ( '' === $currency ) {
					$currency = $type->currency;
				} elseif ( $currency !== $type->currency ) {
					throw new \RuntimeException( 'mixed_currency' );
				}
			}
			$capacity_context = array( 'phase' => 'create', 'order_id' => 0, 'types' => $types, 'occurrences' => $occurrences, 'now' => $now, 'source' => sanitize_key( $internal['source'] ?? ( '' !== $namespace ? $namespace : 'native' ) ) );
			$reserve_error = self::reserve_capacity( $clean_lines, $capacity_context );
			if ( $reserve_error ) {
				$custom_error = $reserve_error;
				throw new \RuntimeException( $reserve_error->get_error_code() );
			}

			$selected = array();
			$total = 0;
			$price_rules = array();
			$occurrence_demand = array();
			foreach ( $clean_lines as $type_id => $quantity ) {
				$type = $types[ $type_id ];
				$occurrence = $occurrences[ (int) $type->occurrence_id ];
				// Expired holds are ignored by the capacity check (expires_at > now) and marked by the
				// cleanup job; updating them here took gap locks that deadlocked concurrent checkouts.
				// Every statement is checked: after a deadlock or lock wait timeout MariaDB rolls the
				// transaction back (releasing the capacity locks) and later statements would autocommit.
				// Several ticket types of one occurrence: its capacity is checked for their sum.
				$occurrence_demand[ (int) $occurrence->occurrence_id ] = ( $occurrence_demand[ (int) $occurrence->occurrence_id ] ?? 0 ) + $quantity;
				$capacity_error = self::capacity_error( $type, $occurrence, $quantity, 0, $occurrence_demand[ (int) $occurrence->occurrence_id ] );
				if ( $capacity_error ) {
					throw new \RuntimeException( $capacity_error );
				}
				// Price precedence: an explicit trusted unit price (adapters/staff, e.g. WooCommerce or
				// free box office sales) wins; otherwise the stored price passes through the
				// server-side `wpcap_ticket_unit_price` filter. Client input never reaches either.
				if ( array_key_exists( $type_id, $unit_prices ) ) {
					$unit_price = (int) $unit_prices[ $type_id ];
				} else {
					$unit_price = self::unit_price( $type, $occurrence, $quantity, array( 'now' => $now, 'source' => $capacity_context['source'], 'phase' => 'create' ) );
					if ( $unit_price !== (int) $type->price_minor ) {
						$price_rules[ $type_id ] = array( 'base_minor' => (int) $type->price_minor, 'unit_minor' => $unit_price );
					}
				}
				$type->price_minor = $unit_price;
				$line_total = array_key_exists( $type_id, $line_totals ) ? (int) $line_totals[ $type_id ] : $unit_price * $quantity;
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
			if ( ! $external && ! $billing && Billing::required_for( $total, $currency ) ) { throw new \RuntimeException( 'billing_required' ); }
			// Each campaign discount sits on the lines it applies to (Campaigns::calculate()); VAT per
			// line on the discounted gross amount. The line discount is stored with the line.
			$tax_total = 0;
			$line_discounts = Campaigns::line_discounts( $adjustments, count( $selected ) );
			foreach ( $selected as $index => $line ) {
				$line_discount = min( (int) $line['line_total'], max( 0, (int) ( $line_discounts[ $index ] ?? 0 ) ) );
				$selected[ $index ]['discount_minor'] = $line_discount;
				$rate = (int) ( $line['type']->tax_rate_bp ?? 0 );
				$type_id_for_tax = (int) $line['type']->ticket_type_id;
				$selected[ $index ]['tax_rate_bp'] = $rate;
				$selected[ $index ]['tax_minor'] = array_key_exists( $type_id_for_tax, $line_taxes ) ? (int) $line_taxes[ $type_id_for_tax ] : self::gross_tax( $line['line_total'] - $line_discount, $rate );
				$tax_total += $selected[ $index ]['tax_minor'];
			}
			$snapshot = array( 'currency' => $currency, 'subtotal_minor' => $subtotal, 'discount_minor' => $discount, 'total_minor' => $total, 'campaigns' => array_map( static function ( $adjustment ) { return array( 'code' => $adjustment['campaign']->code, 'amount_minor' => (int) $adjustment['amount_minor'] ); }, $adjustments ) );
			if ( $price_rules ) {
				// Lines whose unit price was changed by `wpcap_ticket_unit_price` (e.g. series price tiers).
				$snapshot['unit_price_adjustments'] = $price_rules;
			}
			$price_snapshot = wp_json_encode( $snapshot );

			if ( false === self::in_transaction() ) { throw new \RuntimeException( 'db_busy' ); }
			$public_id = bin2hex( random_bytes( 16 ) );
			$access_token = bin2hex( random_bytes( 32 ) );
			$order_row = array(
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
				'tax_minor' => $tax_total,
				'payment_provider' => $payment_provider,
				'terms_version' => $external ? '' : Settings::terms_version(),
				'locale' => function_exists( 'determine_locale' ) ? determine_locale() : '',
				'attendee_data' => $attendees ? wp_json_encode( $attendees ) : null,
				'created_at' => $now,
				'updated_at' => $now,
			);
			// Only written when given, so checkouts keep working while a deferred schema upgrade is pending.
			if ( $billing ) { $order_row['billing'] = wp_json_encode( $billing ); }
			$inserted = $wpdb->insert( $orders_table, $order_row );
			if ( ! $inserted ) {
				throw new \RuntimeException( 'order_insert_failed' );
			}
			$order_id = (int) $wpdb->insert_id;
			// Capacity counters (see CapacityCounters): the new lines/holds are inserted with their
			// counted units and the counters change in this transaction, under the capacity locks.
			$counted = CapacityCounters::maintained();
			$pending_order = $total || $external;
			$deltas = array();
			$occurrence_of = array();
			foreach ( $selected as $line ) {
				$type_id = (int) $line['type']->ticket_type_id;
				$item = array(
					'order_id' => $order_id,
					'ticket_type_id' => $type_id,
					'quantity' => (int) $line['quantity'],
					'unit_price_minor' => (int) $line['type']->price_minor,
					'line_total_minor' => (int) $line['line_total'],
					'tax_rate_bp' => (int) $line['tax_rate_bp'],
					'tax_minor' => (int) $line['tax_minor'],
				);
				if ( $counted ) { $item['counted_qty'] = $pending_order ? 0 : (int) $line['quantity']; }
				if ( OrderLines::ready() ) { $item['discount_minor'] = (int) ( $line['discount_minor'] ?? 0 ); }
				$wpdb->insert( $items_table, $item );
				if ( '' !== $wpdb->last_error ) {
					throw new \RuntimeException( 'order_item_insert_failed' );
				}
				if ( $pending_order ) {
					$hold = array(
						'order_id' => $order_id,
						'ticket_type_id' => $type_id,
						'quantity' => (int) $line['quantity'],
						'expires_at' => gmdate( 'Y-m-d H:i:s', time() + $hold_minutes * MINUTE_IN_SECONDS ),
						'status' => 'active',
					);
					if ( $counted ) { $hold['counted_qty'] = (int) $line['quantity']; }
					$wpdb->insert( $reservations_table, $hold );
					if ( '' !== $wpdb->last_error ) {
						throw new \RuntimeException( 'reservation_insert_failed' );
					}
				}
				$unit = $pending_order ? 'held' : 'sold';
				$deltas[ $type_id ][ $unit ] = ( $deltas[ $type_id ][ $unit ] ?? 0 ) + (int) $line['quantity'];
				$occurrence_of[ $type_id ] = (int) $line['type']->occurrence_id;
			}
			if ( $counted && ! CapacityCounters::add( $deltas, $occurrence_of ) ) {
				throw new \RuntimeException( 'db_busy' );
			}
			/**
			 * Inside the order transaction (rows of ticket types/occurrences are locked). Modules may
			 * reserve further resources here (e.g. seats). Throw a \RuntimeException with a code to abort;
			 * map the code to a message via `wpcap_checkout_error_messages`.
			 */
			do_action( 'wpcap_order_creating', $order_id, $selected, $payload, $internal );
			$redeemed = Campaigns::redeem( $adjustments, $order_id );
			if ( is_wp_error( $redeemed ) ) { throw new \RuntimeException( 'campaign_redeem_failed' ); }
			if ( false === self::in_transaction() ) { throw new \RuntimeException( 'db_busy' ); }
			if ( false === $wpdb->query( 'COMMIT' ) ) { throw new \RuntimeException( 'db_busy' ); }
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			$messages = array(
				'ticket_type_unavailable' => __( 'Diese Ticketart ist nicht verfügbar.', 'wp-pass-captain' ),
				'quantity_limit' => __( 'Für diese Ticketart sind pro Bestellung nicht so viele Tickets möglich.', 'wp-pass-captain' ),
				'db_busy' => __( 'Gerade ist sehr viel los. Bitte versuche es in einem Moment erneut.', 'wp-pass-captain' ),
				'occurrence_unavailable' => __( 'Dieser Termin ist nicht mehr verfügbar.', 'wp-pass-captain' ),
				'sales_closed' => __( 'Der Verkauf für diese Ticketart ist geschlossen.', 'wp-pass-captain' ),
				'mixed_currency' => __( 'Tickets mit unterschiedlichen Währungen können nicht gemeinsam bestellt werden.', 'wp-pass-captain' ),
				'sold_out' => __( 'Für diese Ticketart sind nicht mehr genügend Tickets verfügbar.', 'wp-pass-captain' ),
				'occurrence_sold_out' => __( 'Für diesen Termin sind nicht mehr genügend Plätze verfügbar.', 'wp-pass-captain' ),
				'campaign_invalid' => __( 'Ein Kampagnencode ist ungültig oder passt nicht zur Bestellung.', 'wp-pass-captain' ),
				'campaign_redeem_failed' => __( 'Der Rabatt konnte nicht sicher auf die Bestellung angewendet werden.', 'wp-pass-captain' ),
			);
			$code = $error->getMessage();
			if ( 'order_insert_failed' === $code ) {
				// A concurrent request with the same idempotency key won the unique index.
				$existing = $wpdb->get_row( $wpdb->prepare( "SELECT order_id,public_id,status,payment_status FROM $orders_table WHERE idempotency_key = %s", $key_hash ) );
				if ( $existing ) {
					return array( 'order_id' => (int) $existing->order_id, 'public_id' => $existing->public_id, 'status' => $existing->status, 'payment_status' => $existing->payment_status, 'reused' => true );
				}
			}
			if ( 'billing_required' === $code ) {
				return new \WP_Error( 'wpcap_billing_required', __( 'Für diesen Bestellbetrag brauchen wir für die Rechnung deinen Namen und deine Anschrift. Bitte aktiviere „Ich brauche eine Rechnung auf Firma/Adresse“ und fülle die Felder aus.', 'wp-pass-captain' ), array( 'status' => 400, 'field' => 'billing_enabled' ) );
			}
			$messages = apply_filters( 'wpcap_checkout_error_messages', $messages );
			$fallback = $custom_error instanceof \WP_Error && '' !== $custom_error->get_error_message() ? $custom_error->get_error_message() : __( 'Die Bestellung konnte nicht angelegt werden.', 'wp-pass-captain' );
			$message = $messages[ $code ] ?? $fallback;
			return new \WP_Error( 'wpcap_checkout_failed', $message, array( 'status' => 'db_busy' === $code ? 503 : 409, 'retryable' => 'db_busy' === $code ) );
		}

		$pending = $total || $external;
		if ( ! $pending ) {
			Campaigns::commit_for_order( $order_id );
			// Same order as for paid orders (confirm_external_payment): wpcap_order_paid before the
			// issuance (e.g. seat recovery), wpcap_tickets_issued after it.
			do_action( 'wpcap_order_paid', $order_id );
			self::issue_and_email( $order_id );
		}
		if ( ! $external ) {
			self::send_order_access_link( $email, $public_id, $access_token );
		}
		Audit::record( 'order_created', 'order', $order_id, array( 'total_minor' => $total, 'currency' => $currency, 'source' => sanitize_key( $internal['source'] ?? 'native' ) ) );
		return array( 'order_id' => $order_id, 'public_id' => $public_id, 'status' => $pending ? 'pending_payment' : 'complete', 'payment_status' => $pending ? 'pending' : 'paid', 'subtotal_minor' => $subtotal, 'discount_minor' => $discount, 'total_minor' => $total, 'currency' => $currency, 'hold_minutes' => $pending ? $hold_minutes : 0 );
	}

	/** Stores attendee name/e-mail and custom field answers for one ticket. */
	private static function store_attendee( $ticket_id, $order, array $attendee ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = current_time( 'mysql', true );
		$name = sanitize_text_field( $attendee['name'] ?? '' );
		$email = sanitize_email( $attendee['email'] ?? '' );
		if ( $name || is_email( $email ) ) {
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$p}wpcap_ticket_assignments (ticket_id,buyer_email,attendee_email,attendee_name,status,created_at) VALUES (%d,%s,%s,%s,'assigned',%s) ON DUPLICATE KEY UPDATE attendee_email=VALUES(attendee_email),attendee_name=VALUES(attendee_name)", $ticket_id, $order->customer_email, is_email( $email ) ? $email : '', $name, $now ) );
			if ( is_email( $email ) ) {
				$wpdb->update( "{$p}wpcap_tickets", array( 'attendee_email' => $email ), array( 'ticket_id' => $ticket_id ) );
			}
		}
		foreach ( (array) ( $attendee['answers'] ?? array() ) as $field_id => $value ) {
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$p}wpcap_participant_answers (ticket_id,field_id,answer,created_at,updated_at) VALUES (%d,%d,%s,%s,%s) ON DUPLICATE KEY UPDATE answer=VALUES(answer),updated_at=VALUES(updated_at)", $ticket_id, absint( $field_id ), (string) $value, $now, $now ) );
		}
	}

	/**
	 * Revokes the given tickets if still valid and returns the IDs actually changed.
	 * Callers fire wpcap_ticket_revoked via fire_revoked() after their transaction commits.
	 */
	public static function revoke_ids( array $ticket_ids ) {
		global $wpdb;
		$changed = array();
		foreach ( array_unique( array_map( 'absint', $ticket_ids ) ) as $id ) {
			if ( $id && $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_tickets SET status='revoked' WHERE ticket_id=%d AND status='valid'", $id ) ) ) {
				$changed[] = $id;
			}
		}
		return $changed;
	}

	/**
	 * Client IP used for the anonymous checkout rate limit. Defaults to REMOTE_ADDR.
	 *
	 * Behind a reverse proxy / CDN (e.g. Cloudflare) REMOTE_ADDR is the proxy, so every buyer would
	 * share one limit. Sites that trust a proxy header can resolve the real client via the filter
	 * `wpcap_client_ip` – only when REMOTE_ADDR is the trusted proxy, otherwise the header is forgeable:
	 *
	 *     add_filter( 'wpcap_client_ip', function ( $ip, $server ) {
	 *         $trusted = array( '203.0.113.10' ); // Proxy addresses.
	 *         if ( in_array( $ip, $trusted, true ) && ! empty( $server['HTTP_CF_CONNECTING_IP'] ) ) {
	 *             return $server['HTTP_CF_CONNECTING_IP'];
	 *         }
	 *         return $ip;
	 *     }, 10, 2 );
	 *
	 * Invalid return values fall back to REMOTE_ADDR.
	 */
	public static function client_ip() {
		$remote = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$ip = trim( (string) apply_filters( 'wpcap_client_ip', $remote, $_SERVER ) );
		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : $remote;
	}

	/** Keyed hash for rate-limit transient keys, so stored keys cannot be reversed to IP addresses. */
	public static function rate_hash( $value ) {
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'nonce' ) : 'wpcap-rate';
		return hash_hmac( 'sha256', (string) $value, 'wpcap-checkout|' . $salt );
	}

	/** Fires wpcap_ticket_revoked for each ticket (seats, wallet passes, webhooks react to it). */
	public static function fire_revoked( array $ticket_ids, $reason = '' ) {
		foreach ( $ticket_ids as $id ) { do_action( 'wpcap_ticket_revoked', (int) $id, (string) $reason ); }
	}

	/** VAT contained in a gross amount, rounded half up: gross * rate / (10000 + rate). */
	public static function gross_tax( $gross_minor, $rate_bp ) {
		$gross_minor = max( 0, (int) $gross_minor );
		$rate_bp = max( 0, (int) $rate_bp );
		if ( ! $gross_minor || ! $rate_bp ) { return 0; }
		$denominator = 10000 + $rate_bp;
		return intdiv( 2 * $gross_minor * $rate_bp + $denominator, 2 * $denominator );
	}

	/**
	 * Validates attendee data against the participant fields configured per ticket type.
	 * Only configured fields are kept (data minimisation); required fields must be filled for every ticket.
	 *
	 * @return array|\WP_Error Normalised list [{ticket_type_id, name, email, answers{field_id:value}}].
	 */
	public static function validate_attendees( array $lines, $attendees ) {
		global $wpdb;
		$attendees = is_array( $attendees ) ? array_values( $attendees ) : array();
		$out = array();
		foreach ( $lines as $type_id => $quantity ) {
			$fields = $wpdb->get_results( $wpdb->prepare( "SELECT field_id,field_key,label,field_type,required FROM {$wpdb->prefix}wpcap_participant_fields WHERE ticket_type_id=%d AND status='active' ORDER BY position,field_id", $type_id ) );
			$given = array_values( array_filter( $attendees, static function ( $a ) use ( $type_id ) { return is_array( $a ) && absint( $a['ticket_type_id'] ?? 0 ) === (int) $type_id; } ) );
			if ( count( $given ) > $quantity ) {
				return new \WP_Error( 'wpcap_attendees_invalid', __( 'Es wurden mehr Teilnehmende angegeben als Tickets bestellt.', 'wp-pass-captain' ), array( 'status' => 400 ) );
			}
			$needs_all = (bool) array_filter( $fields, static function ( $f ) { return (int) $f->required; } );
			for ( $i = 0; $i < $quantity; $i++ ) {
				$entry = $given[ $i ] ?? null;
				if ( ! $entry && ! $needs_all ) { continue; }
				$entry = is_array( $entry ) ? $entry : array();
				$answers = is_array( $entry['answers'] ?? null ) ? $entry['answers'] : array();
				$clean = array();
				foreach ( $fields as $field ) {
					$value = $answers[ $field->field_key ] ?? '';
					$value = is_scalar( $value ) ? trim( (string) $value ) : '';
					if ( 'email' === $field->field_type ) { $value = sanitize_email( $value ); }
					elseif ( 'textarea' === $field->field_type ) { $value = sanitize_textarea_field( $value ); }
					elseif ( 'checkbox' === $field->field_type ) { $value = $value && '0' !== $value ? '1' : ''; }
					else { $value = sanitize_text_field( $value ); }
					if ( (int) $field->required && '' === $value ) {
						/* translators: %s: field label */
						return new \WP_Error( 'wpcap_attendee_field_required', sprintf( __( 'Bitte „%s“ für jedes Ticket ausfüllen.', 'wp-pass-captain' ), $field->label ), array( 'status' => 400 ) );
					}
					if ( 'email' === $field->field_type && '' !== $value && ! is_email( $value ) ) {
						/* translators: %s: field label */
						return new \WP_Error( 'wpcap_attendee_field_invalid', sprintf( __( '„%s“ ist keine gültige E-Mail-Adresse.', 'wp-pass-captain' ), $field->label ), array( 'status' => 400 ) );
					}
					if ( '' !== $value ) { $clean[ (int) $field->field_id ] = mb_substr( $value, 0, 2000 ); }
				}
				$email = sanitize_email( $entry['email'] ?? '' );
				$out[] = array(
					'ticket_type_id' => (int) $type_id,
					'name' => mb_substr( sanitize_text_field( $entry['name'] ?? '' ), 0, 190 ),
					'email' => is_email( $email ) ? $email : '',
					'answers' => $clean,
				);
			}
		}
		return $out;
	}

	/**
	 * Whether a module error is transient (deadlock, lock wait timeout): code db_busy/wpcap_db_busy,
	 * `retryable` in its data, or HTTP status 503.
	 */
	public static function is_retryable_error( \WP_Error $error ) {
		$data = (array) $error->get_error_data();
		return in_array( $error->get_error_code(), array( 'db_busy', 'wpcap_db_busy' ), true ) || ! empty( $data['retryable'] ) || 503 === (int) ( $data['status'] ?? 0 );
	}

	/** Rolls back a payment confirmation that hit a deadlock/lock timeout; callers retry (webhooks get a 5xx). */
	private static function payment_busy() {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' );
		return new \WP_Error( 'wpcap_db_busy', __( 'Die Zahlung konnte gerade nicht verbucht werden. Bitte erneut versuchen.', 'wp-pass-captain' ), array( 'status' => 503, 'retryable' => true ) );
	}

	/**
	 * Whether the connection is still inside a transaction (MariaDB @@in_transaction). False after
	 * the server rolled the transaction back (deadlock, lock wait timeout); null if unknown (MySQL).
	 *
	 * MySQL has no such variable: querying it there only writes "Unknown system variable" to the
	 * error log on every order, so the server type is checked once per request instead.
	 */
	private static function in_transaction() {
		global $wpdb;
		static $tracked = null;
		if ( null === $tracked ) { $tracked = self::server_tracks_transactions( (string) $wpdb->get_var( 'SELECT VERSION()' ) ); }
		if ( ! $tracked ) { return null; }
		$value = $wpdb->get_var( 'SELECT @@in_transaction' );
		if ( '' !== $wpdb->last_error || null === $value ) { return null; }
		return 1 === (int) $value;
	}

	/** MariaDB reports its transaction state in @@in_transaction; MySQL does not. */
	private static function server_tracks_transactions( $version ) {
		return false !== stripos( (string) $version, 'mariadb' );
	}

	/**
	 * Returns an error code if $quantity more units of $type (and $occurrence_quantity more units of
	 * the occurrence, default $quantity) would exceed type or occurrence capacity; '' when they fit.
	 * Callers must hold row locks on the ticket type and occurrence, and pass rows read under those
	 * locks after any counter change in the same transaction. Capacity 0 means unlimited.
	 *
	 * With capacity counters (CapacityCounters::enabled()) usage is sold_qty + held_qty of the
	 * locked rows minus active holds that already lapsed (indexed range, small); without them the
	 * paid lines and holds are summed (upgrade fallback).
	 */
	private static function capacity_error( $type, $occurrence, $quantity, $exclude_order_id = 0, $occurrence_quantity = null ) {
		$occurrence_quantity = null === $occurrence_quantity ? $quantity : (int) $occurrence_quantity;
		if ( CapacityCounters::enabled() && isset( $occurrence->sold_qty, $occurrence->held_qty ) && ( (int) $type->capacity <= 0 || isset( $type->sold_qty, $type->held_qty ) ) ) {
			return self::capacity_error_counted( $type, $occurrence, (int) $quantity, absint( $exclude_order_id ), $occurrence_quantity );
		}
		return self::capacity_error_summed( $type, $occurrence, (int) $quantity, absint( $exclude_order_id ), $occurrence_quantity );
	}

	/** capacity_error() from the locked counters. */
	private static function capacity_error_counted( $type, $occurrence, $quantity, $exclude_order_id, $occurrence_quantity ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = current_time( 'mysql', true );
		if ( (int) $type->capacity > 0 ) {
			// Holds still marked active but already lapsed count in held_qty until the cleanup.
			$lapsed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(counted_qty),0) FROM {$p}wpcap_reservations WHERE ticket_type_id=%d AND status='active' AND expires_at <= %s", $type->ticket_type_id, $now ) );
			if ( '' !== $wpdb->last_error ) { return 'db_busy'; }
			$own = 0;
			if ( $exclude_order_id ) {
				$own = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(counted_qty),0) FROM {$p}wpcap_order_items WHERE order_id=%d AND ticket_type_id=%d", $exclude_order_id, $type->ticket_type_id ) )
					+ (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(counted_qty),0) FROM {$p}wpcap_reservations WHERE order_id=%d AND ticket_type_id=%d AND status='active' AND expires_at > %s", $exclude_order_id, $type->ticket_type_id, $now ) );
				if ( '' !== $wpdb->last_error ) { return 'db_busy'; }
			}
			if ( (int) $type->sold_qty + (int) $type->held_qty - $lapsed - $own + $quantity > (int) $type->capacity ) {
				return 'sold_out';
			}
		}
		if ( (int) $occurrence->capacity > 0 ) {
			$lapsed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(r.counted_qty),0) FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_reservations r ON r.ticket_type_id=tt.ticket_type_id AND r.status='active' AND r.expires_at <= %s WHERE tt.occurrence_id=%d", $now, $occurrence->occurrence_id ) );
			if ( '' !== $wpdb->last_error ) { return 'db_busy'; }
			$own = 0;
			if ( $exclude_order_id ) {
				$own = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(oi.counted_qty),0) FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id WHERE oi.order_id=%d AND tt.occurrence_id=%d", $exclude_order_id, $occurrence->occurrence_id ) )
					+ (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(r.counted_qty),0) FROM {$p}wpcap_reservations r INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=r.ticket_type_id WHERE r.order_id=%d AND tt.occurrence_id=%d AND r.status='active' AND r.expires_at > %s", $exclude_order_id, $occurrence->occurrence_id, $now ) );
				if ( '' !== $wpdb->last_error ) { return 'db_busy'; }
			}
			if ( (int) $occurrence->sold_qty + (int) $occurrence->held_qty - $lapsed - $own + $occurrence_quantity > (int) $occurrence->capacity ) {
				return 'occurrence_sold_out';
			}
		}
		return '';
	}

	/** capacity_error() by summing paid lines and active holds (used until the counters are verified). */
	private static function capacity_error_summed( $type, $occurrence, $quantity, $exclude_order_id, $occurrence_quantity ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = current_time( 'mysql', true );
		if ( (int) $type->capacity > 0 ) {
			$sold = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(oi.quantity - oi.refunded_quantity),0) FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_orders ord ON ord.order_id=oi.order_id WHERE oi.ticket_type_id=%d AND ord.payment_status='paid' AND ord.status <> 'refunded' AND ord.order_id <> %d", $type->ticket_type_id, $exclude_order_id ) );
			if ( '' !== $wpdb->last_error ) { return 'db_busy'; }
			$held = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(quantity),0) FROM {$p}wpcap_reservations WHERE ticket_type_id=%d AND status='active' AND expires_at > %s AND order_id <> %d", $type->ticket_type_id, $now, $exclude_order_id ) );
			if ( '' !== $wpdb->last_error ) { return 'db_busy'; }
			if ( $sold + $held + $quantity > (int) $type->capacity ) {
				return 'sold_out';
			}
		}
		if ( (int) $occurrence->capacity > 0 ) {
			$occ_sold = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(oi.quantity - oi.refunded_quantity),0) FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_orders ord ON ord.order_id=oi.order_id WHERE tt.occurrence_id=%d AND ord.payment_status='paid' AND ord.status <> 'refunded' AND ord.order_id <> %d", $occurrence->occurrence_id, $exclude_order_id ) );
			if ( '' !== $wpdb->last_error ) { return 'db_busy'; }
			$occ_held = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(r.quantity),0) FROM {$p}wpcap_reservations r INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=r.ticket_type_id WHERE tt.occurrence_id=%d AND r.status='active' AND r.expires_at > %s AND r.order_id <> %d", $occurrence->occurrence_id, $now, $exclude_order_id ) );
			if ( '' !== $wpdb->last_error ) { return 'db_busy'; }
			if ( $occ_sold + $occ_held + $occurrence_quantity > (int) $occurrence->capacity ) {
				return 'occurrence_sold_out';
			}
		}
		return '';
	}

	/**
	 * Effective gross unit price of a ticket type, computed on the server only.
	 *
	 * The stored price passes through `wpcap_ticket_unit_price` (e.g. series price tiers). Used by
	 * Orders::create() for every line without a trusted `unit_prices` override, and for display
	 * (event page, box office) so customers see the price they will be charged.
	 *
	 * @param object|int $type       Ticket type row or id.
	 * @param object|null $occurrence Occurrence row (loaded when omitted).
	 * @param int        $quantity   Units of this type in the order (1 for display).
	 * @param array      $context    Optional: now (UTC Y-m-d H:i:s), source, phase ('create'|'display').
	 * @return int Price in minor units (never negative).
	 */
	public static function unit_price( $type, $occurrence = null, $quantity = 1, array $context = array() ) {
		global $wpdb;
		if ( ! is_object( $type ) ) {
			$type = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_ticket_types WHERE ticket_type_id=%d", absint( $type ) ) );
			if ( ! $type ) { return 0; }
		}
		if ( ! $occurrence && isset( $type->occurrence_id ) ) {
			$occurrence = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_occurrences WHERE occurrence_id=%d", $type->occurrence_id ) );
		}
		$context = array_merge( array( 'phase' => 'display', 'source' => 'native' ), $context );
		$context['occurrence'] = $occurrence;
		$context['quantity'] = max( 1, absint( $quantity ) );
		$context['now'] = isset( $context['now'] ) ? (string) $context['now'] : current_time( 'mysql', true );
		/**
		 * Filters the gross unit price (minor units) of a ticket type. Server-side only; trusted
		 * `unit_prices` overrides of adapters (WooCommerce, free box office sales) bypass it.
		 * Within Orders::create() it runs inside the order transaction after the capacity check.
		 *
		 * @param int    $price_minor Stored price of the ticket type.
		 * @param object $type        Ticket type row.
		 * @param array  $context     occurrence (row), quantity, now (UTC), phase, source.
		 */
		$price = apply_filters( 'wpcap_ticket_unit_price', (int) $type->price_minor, $type, $context );
		return max( 0, (int) $price );
	}

	/**
	 * Public label of the price currently applied by `wpcap_ticket_unit_price` (e.g. "Frühbucher"),
	 * via the `wpcap_ticket_price_label` filter; '' when the stored price applies.
	 */
	public static function price_label( $type, $occurrence = null, array $context = array() ) {
		global $wpdb;
		if ( ! $occurrence && isset( $type->occurrence_id ) ) {
			$occurrence = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_occurrences WHERE occurrence_id=%d", $type->occurrence_id ) );
		}
		$context = array_merge( array( 'phase' => 'display', 'source' => 'native', 'quantity' => 1, 'now' => current_time( 'mysql', true ) ), $context, array( 'occurrence' => $occurrence ) );
		return (string) apply_filters( 'wpcap_ticket_price_label', '', $type, $context );
	}

	/**
	 * Runs the `wpcap_reserve_capacity` filter inside an order/recovery transaction. Ticket types and
	 * occurrences are already locked (FOR UPDATE) and no consistent read has happened yet, so a module
	 * that locks its own rows here and only then reads usage sees every committed reservation.
	 *
	 * @param array $demand  ticket_type_id => quantity.
	 * @param array $context phase ('create'|'recover'), order_id (excluded from usage; 0 on create),
	 *                       types (locked rows by id), occurrences (locked rows by id), now, source.
	 * @return \WP_Error|null
	 */
	private static function reserve_capacity( array $demand, array $context ) {
		/**
		 * Check/lock additional shared capacity (e.g. quota pools across occurrences). Return a
		 * WP_Error to reject; its code is mapped through `wpcap_checkout_error_messages`, else its
		 * message is shown. Lock resource rows FOR UPDATE in a stable order (e.g. by id) before
		 * reading usage, and use only locking reads before that.
		 */
		$result = apply_filters( 'wpcap_reserve_capacity', true, $demand, $context );
		return $result instanceof \WP_Error ? $result : null;
	}

	/**
	 * Capacity check for a ticket exchange (see Exchange\Service). Call inside the caller's
	 * transaction after the target ticket type and occurrence rows are locked FOR UPDATE, with only
	 * locking reads before (like Orders::create()). Runs `wpcap_reserve_capacity` with phase
	 * 'exchange' (series pools, allocations, …) and then the core type/occurrence capacity check for
	 * $quantity more units of $type. Units the caller already released in the same transaction
	 * (e.g. the exchanged ticket's line) are not counted.
	 *
	 * @param object $type       Locked target ticket type row.
	 * @param object $occurrence Locked target occurrence row.
	 * @param int    $quantity   Units needed (default 1).
	 * @param array  $context    Extra context for the filter (exchange, source, now); phase is always 'exchange'.
	 * @return \WP_Error|null
	 */
	public static function reserve_exchange_capacity( $type, $occurrence, $quantity = 1, array $context = array() ) {
		$quantity = max( 1, absint( $quantity ) );
		$context = array_merge(
			array(
				'order_id' => 0,
				'types' => array( (int) $type->ticket_type_id => $type ),
				'occurrences' => array( (int) $occurrence->occurrence_id => $occurrence ),
				'now' => current_time( 'mysql', true ),
				'source' => 'exchange',
			),
			$context,
			array( 'phase' => 'exchange' )
		);
		$error = self::reserve_capacity( array( (int) $type->ticket_type_id => $quantity ), $context );
		if ( $error ) { return $error; }
		// Units the caller released in this transaction leave the counters first (the exchanged
		// line of context.exchange.order_id); then the locked rows are read again with fresh counters.
		$code = '';
		$exchange = (array) ( $context['exchange'] ?? array() );
		if ( CapacityCounters::maintained() ) {
			if ( ! empty( $exchange['order_id'] ) && false === CapacityCounters::sync_order( (int) $exchange['order_id'], array_filter( array( (int) ( $exchange['from_type_id'] ?? 0 ), (int) $type->ticket_type_id ) ) ) ) {
				$code = 'db_busy';
			}
			global $wpdb;
			$type = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_ticket_types WHERE ticket_type_id=%d FOR UPDATE", $type->ticket_type_id ) ) ?: $type;
			$occurrence = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_occurrences WHERE occurrence_id=%d FOR UPDATE", $occurrence->occurrence_id ) ) ?: $occurrence;
		}
		$code = '' !== $code ? $code : self::capacity_error( $type, $occurrence, $quantity );
		if ( '' === $code ) { return null; }
		$messages = apply_filters( 'wpcap_checkout_error_messages', array(
			'db_busy' => __( 'Gerade ist sehr viel los. Bitte versuche es in einem Moment erneut.', 'wp-pass-captain' ),
			'sold_out' => __( 'Für diese Ticketart sind nicht mehr genügend Tickets verfügbar.', 'wp-pass-captain' ),
			'occurrence_sold_out' => __( 'Für diesen Termin sind nicht mehr genügend Plätze verfügbar.', 'wp-pass-captain' ),
		) );
		return new \WP_Error( $code, (string) ( $messages[ $code ] ?? $code ), array( 'status' => 'db_busy' === $code ? 503 : 409, 'retryable' => 'db_busy' === $code ) );
	}

	/** Whether the connection is still inside a transaction (false after a server-side rollback, null if unknown). */
	public static function transaction_alive() {
		return self::in_transaction();
	}

	/**
	 * Fires `wpcap_capacity_released` after inventory of an order stopped counting (hold expired,
	 * order cancelled/refunded, tickets revoked, payment unallocated). Core capacity and pools are
	 * derived from orders and holds, so this is informational for modules with own counters/caches.
	 */
	private static function capacity_released( $order_id, $reason, array $details = array() ) {
		do_action( 'wpcap_capacity_released', (int) $order_id, (string) $reason, $details );
	}

	/** Per-request availability memo: ticket_type_id => remaining (int|null). */
	private static $availability_memo = array();
	/** Whether this request wrote to plugin tables (then the object cache is not read any more). */
	private static $wrote = false;
	private static $query_hooked = false;

	/**
	 * Forgets memoised availability. Runs on every write to a plugin table of this request (filter
	 * `query`), when capacity counters change and on demand (e.g. after module data changed).
	 */
	public static function flush_availability_cache() {
		self::$availability_memo = array();
		self::$wrote = true;
		/** Request caches of modules (holds, passes, seat links, limits) are stale after a write. */
		do_action( 'wpcap_availability_flushed' );
	}

	/** Filter `query`: a write to a plugin table (or a rollback) invalidates the availability memo. */
	public static function note_query( $query ) {
		if ( is_string( $query ) && preg_match( '/^\s*(?:(?:INSERT|UPDATE|DELETE|REPLACE)\b[^;]*wpcap_|ROLLBACK)/is', $query ) ) {
			self::flush_availability_cache();
		}
		return $query;
	}

	/**
	 * Remaining sellable units for a ticket type (non-locking, for display/cart validation).
	 * Never used for capacity decisions (those run in capacity_error() under row locks).
	 *
	 * Memoised per request (invalidated by any write to a plugin table in this request) and kept
	 * for a few seconds in the object cache (filter `wpcap_availability_cache_ttl`, default 10 s,
	 * 0 disables; only read by requests that have not written to plugin tables).
	 *
	 * @return int|null Null when neither the type nor the occurrence is limited.
	 */
	public static function availability( $ticket_type_id ) {
		$ticket_type_id = absint( $ticket_type_id );
		$result = self::availability_many( array( $ticket_type_id ) );
		return array_key_exists( $ticket_type_id, $result ) ? $result[ $ticket_type_id ] : 0;
	}

	/**
	 * availability() for many ticket types with a constant number of queries (listings, REST).
	 *
	 * @param int[] $ticket_type_ids Ticket type ids.
	 * @param array $rows            Optional preloaded rows: types (id => full row), occurrences (id => full row).
	 * @return array ticket_type_id => int|null (0 for unknown types).
	 */
	public static function availability_many( array $ticket_type_ids, array $rows = array() ) {
		global $wpdb;
		if ( ! self::$query_hooked && function_exists( 'add_filter' ) ) {
			add_filter( 'query', array( __CLASS__, 'note_query' ) );
			self::$query_hooked = true;
		}
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ticket_type_ids ) ) ) );
		$out = array();
		$missing = array();
		foreach ( $ids as $id ) {
			if ( array_key_exists( $id, self::$availability_memo ) ) { $out[ $id ] = self::$availability_memo[ $id ]; } else { $missing[] = $id; }
		}
		if ( ! $missing ) {
			return $out;
		}
		$ttl = max( 0, min( 60, (int) apply_filters( 'wpcap_availability_cache_ttl', 10 ) ) );
		$use_cache = $ttl > 0 && function_exists( 'wp_cache_get' );
		$cache_key = static function ( $id ) use ( $wpdb ) { return 'availability:' . $wpdb->prefix . $id; };
		if ( $use_cache && ! self::$wrote ) {
			foreach ( $missing as $i => $id ) {
				$found = false;
				$cached = wp_cache_get( $cache_key( $id ), 'wpcap', false, $found );
				if ( $found && is_array( $cached ) && array_key_exists( 'v', $cached ) ) {
					$out[ $id ] = self::$availability_memo[ $id ] = $cached['v'];
					unset( $missing[ $i ] );
				}
			}
		}
		if ( ! $missing ) {
			return $out;
		}
		foreach ( self::compute_availability( array_values( $missing ), $rows ) as $id => $value ) {
			$out[ $id ] = self::$availability_memo[ $id ] = $value;
			if ( $use_cache ) { wp_cache_set( $cache_key( $id ), array( 'v' => $value ), 'wpcap', $ttl ); }
		}
		return $out;
	}

	/** Uncached availability of ticket types (see availability_many()). */
	private static function compute_availability( array $ids, array $rows ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = current_time( 'mysql', true );
		$types = array();
		$need = array();
		foreach ( $ids as $id ) {
			if ( isset( $rows['types'][ $id ] ) && is_object( $rows['types'][ $id ] ) && isset( $rows['types'][ $id ]->status ) ) { $types[ $id ] = $rows['types'][ $id ]; } else { $need[] = $id; }
		}
		if ( $need ) {
			foreach ( (array) $wpdb->get_results( "SELECT * FROM {$p}wpcap_ticket_types WHERE ticket_type_id IN (" . implode( ',', $need ) . ')' ) as $row ) { $types[ (int) $row->ticket_type_id ] = $row; }
		}
		$occurrences = array();
		$need = array();
		foreach ( $types as $type ) {
			$occ_id = (int) $type->occurrence_id;
			if ( isset( $rows['occurrences'][ $occ_id ] ) && is_object( $rows['occurrences'][ $occ_id ] ) && isset( $rows['occurrences'][ $occ_id ]->status ) ) { $occurrences[ $occ_id ] = $rows['occurrences'][ $occ_id ]; } else { $need[ $occ_id ] = $occ_id; }
		}
		if ( $need ) {
			foreach ( (array) $wpdb->get_results( "SELECT * FROM {$p}wpcap_occurrences WHERE occurrence_id IN (" . implode( ',', array_map( 'intval', $need ) ) . ')' ) as $row ) { $occurrences[ (int) $row->occurrence_id ] = $row; }
		}
		$limited_types = array();
		$limited_occ = array();
		foreach ( $types as $id => $type ) {
			if ( (int) $type->capacity > 0 ) { $limited_types[] = (int) $id; }
			$occ = $occurrences[ (int) $type->occurrence_id ] ?? null;
			if ( $occ && (int) $occ->capacity > 0 ) { $limited_occ[ (int) $occ->occurrence_id ] = (int) $occ->occurrence_id; }
		}
		$type_used = array();
		$occ_used = array();
		$grouped = static function ( $sql ) use ( $wpdb ) {
			$map = array();
			foreach ( (array) $wpdb->get_results( $sql ) as $row ) {
				$row = array_values( (array) $row );
				$map[ (int) $row[0] ] = (int) $row[1];
			}
			return $map;
		};
		$counted = CapacityCounters::enabled() && ( ! $types || isset( reset( $types )->sold_qty ) );
		if ( $limited_types ) {
			$in = implode( ',', $limited_types );
			if ( $counted ) {
				// Counters minus holds that are still marked active but already lapsed.
				$lapsed = $grouped( $wpdb->prepare( "SELECT ticket_type_id,SUM(counted_qty) FROM {$p}wpcap_reservations WHERE ticket_type_id IN ($in) AND status='active' AND expires_at <= %s GROUP BY ticket_type_id", $now ) );
				foreach ( $limited_types as $id ) { $type_used[ $id ] = (int) $types[ $id ]->sold_qty + (int) $types[ $id ]->held_qty - ( $lapsed[ $id ] ?? 0 ); }
			} else {
				$sold = $grouped( "SELECT oi.ticket_type_id,SUM(oi.quantity - oi.refunded_quantity) FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_orders ord ON ord.order_id=oi.order_id WHERE oi.ticket_type_id IN ($in) AND ord.payment_status='paid' AND ord.status <> 'refunded' GROUP BY oi.ticket_type_id" );
				$held = $grouped( $wpdb->prepare( "SELECT ticket_type_id,SUM(quantity) FROM {$p}wpcap_reservations WHERE ticket_type_id IN ($in) AND status='active' AND expires_at > %s GROUP BY ticket_type_id", $now ) );
				foreach ( $limited_types as $id ) { $type_used[ $id ] = ( $sold[ $id ] ?? 0 ) + ( $held[ $id ] ?? 0 ); }
			}
		}
		if ( $limited_occ ) {
			$in = implode( ',', $limited_occ );
			if ( $counted ) {
				$lapsed = $grouped( $wpdb->prepare( "SELECT tt.occurrence_id,SUM(r.counted_qty) FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_reservations r ON r.ticket_type_id=tt.ticket_type_id AND r.status='active' AND r.expires_at <= %s WHERE tt.occurrence_id IN ($in) GROUP BY tt.occurrence_id", $now ) );
				foreach ( $limited_occ as $occ_id ) { $occ_used[ $occ_id ] = (int) $occurrences[ $occ_id ]->sold_qty + (int) $occurrences[ $occ_id ]->held_qty - ( $lapsed[ $occ_id ] ?? 0 ); }
			} else {
				$sold = $grouped( "SELECT tt.occurrence_id,SUM(oi.quantity - oi.refunded_quantity) FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_orders ord ON ord.order_id=oi.order_id WHERE tt.occurrence_id IN ($in) AND ord.payment_status='paid' AND ord.status <> 'refunded' GROUP BY tt.occurrence_id" );
				$held = $grouped( $wpdb->prepare( "SELECT tt.occurrence_id,SUM(r.quantity) FROM {$p}wpcap_reservations r INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=r.ticket_type_id WHERE tt.occurrence_id IN ($in) AND r.status='active' AND r.expires_at > %s GROUP BY tt.occurrence_id", $now ) );
				foreach ( $limited_occ as $occ_id ) { $occ_used[ $occ_id ] = ( $sold[ $occ_id ] ?? 0 ) + ( $held[ $occ_id ] ?? 0 ); }
			}
		}
		$out = array();
		foreach ( $ids as $id ) {
			$type = $types[ $id ] ?? null;
			$occurrence = $type ? ( $occurrences[ (int) $type->occurrence_id ] ?? null ) : null;
			if ( ! $type || ! $occurrence ) { $out[ $id ] = 0; continue; }
			$limits = array();
			if ( (int) $type->capacity > 0 ) { $limits[] = (int) $type->capacity - $type_used[ $id ]; }
			if ( (int) $occurrence->capacity > 0 ) { $limits[] = (int) $occurrence->capacity - $occ_used[ (int) $occurrence->occurrence_id ]; }
			$remaining = $limits ? max( 0, min( $limits ) ) : null;
			/**
			 * Remaining units after module limits (e.g. series quota pools). Return null for unlimited
			 * or a smaller non-negative int; never more than the core value.
			 */
			$filtered = apply_filters( 'wpcap_ticket_availability', $remaining, $type, $occurrence );
			if ( null === $filtered || ! is_numeric( $filtered ) ) { $out[ $id ] = $remaining; continue; }
			$filtered = max( 0, (int) $filtered );
			$out[ $id ] = null === $remaining ? $filtered : min( $remaining, $filtered );
		}
		return $out;
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
		// Ticket types of a pending order never change, so they can be read before the transaction;
		// they are locked right after the order row (order → types → occurrences, by id).
		$type_ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT ticket_type_id FROM {$p}wpcap_order_items WHERE order_id=%d ORDER BY ticket_type_id", $order_id ) ) );
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
		// Capacity locks first (the paid units enter the sold counters in this transaction, and a
		// late payment re-checks capacity): ticket types, then occurrences (same order as
		// Orders::create()). Locking reads only until every capacity lock is held (fresh snapshot
		// for the re-check below).
		$locked_types = array();
		foreach ( $type_ids as $type_id ) {
			$locked_types[ $type_id ] = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d FOR UPDATE", $type_id ) );
		}
		$occ_ids = array_unique( array_map( static function ( $type ) { return $type ? (int) $type->occurrence_id : 0; }, $locked_types ) );
		sort( $occ_ids, SORT_NUMERIC );
		$occurrences = array();
		foreach ( $occ_ids as $occ_id ) {
			if ( $occ_id ) { $occurrences[ $occ_id ] = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_occurrences WHERE occurrence_id=%d FOR UPDATE", $occ_id ) ); }
		}
		if ( '' !== $wpdb->last_error ) { return self::payment_busy(); }
		$active = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_reservations WHERE order_id=%d AND status='active' AND expires_at > %s LOCK IN SHARE MODE", $order_id, $now ) );
		$active_error = $wpdb->last_error;
		$lines = $wpdb->get_results( $wpdb->prepare( "SELECT ticket_type_id, SUM(quantity) AS quantity FROM {$p}wpcap_order_items WHERE order_id=%d GROUP BY ticket_type_id ORDER BY ticket_type_id LOCK IN SHARE MODE", $order_id ) );
		if ( '' !== $active_error || '' !== $wpdb->last_error ) { return self::payment_busy(); }
		$recovered = false;
		if ( ! $active ) {
			// Late payment: module resources (`wpcap_reserve_capacity`), then re-check capacity.
			foreach ( $lines as $line ) {
				if ( ! array_key_exists( (int) $line->ticket_type_id, $locked_types ) ) {
					$locked_types[ (int) $line->ticket_type_id ] = null; // Not seen before the transaction: treated as unavailable.
				}
			}
			$demand = array();
			foreach ( $lines as $line ) { $demand[ (int) $line->ticket_type_id ] = (int) $line->quantity; }
			$reserve_error = in_array( null, $locked_types, true ) ? null : self::reserve_capacity( $demand, array( 'phase' => 'recover', 'order_id' => $order_id, 'types' => $locked_types, 'occurrences' => array_filter( $occurrences ), 'now' => $now, 'source' => (string) $order->payment_provider ) );
			$error = '';
			$per_occurrence = array();
			foreach ( $lines as $line ) {
				$type = $locked_types[ (int) $line->ticket_type_id ] ?? null;
				$occurrence = $type ? ( $occurrences[ (int) $type->occurrence_id ] ?? null ) : null;
				if ( ! $type || ! $occurrence ) { $error = 'ticket_type_unavailable'; break; }
				$check_occurrence = clone $occurrence;
				$check_occurrence->capacity = 0; // Occurrence capacity is checked below for the summed quantity.
				$error = self::capacity_error( $type, $check_occurrence, (int) $line->quantity, $order_id );
				if ( $error ) { break; }
				$per_occurrence[ (int) $occurrence->occurrence_id ] = ( $per_occurrence[ (int) $occurrence->occurrence_id ] ?? 0 ) + (int) $line->quantity;
			}
			// A transient lock problem (deadlock, lock wait timeout) is no "sold out": roll back so the
			// payment provider redelivers the webhook (5xx) instead of flagging a paid order for review.
			if ( 'db_busy' === $error || ( $reserve_error && self::is_retryable_error( $reserve_error ) ) ) { return self::payment_busy(); }
			if ( ! $error && $reserve_error ) {
				$error = $reserve_error->get_error_code();
			}
			if ( ! $error ) {
				foreach ( $per_occurrence as $occ_id => $quantity ) {
					$unlimited_type = (object) array( 'ticket_type_id' => 0, 'capacity' => 0 );
					$error = self::capacity_error( $unlimited_type, $occurrences[ $occ_id ], $quantity, $order_id );
					if ( $error ) { break; }
				}
			}
			if ( 'db_busy' === $error ) { return self::payment_busy(); }
			if ( $error ) {
				$wpdb->update( "{$p}wpcap_orders", array( 'status' => 'needs_review', 'payment_status' => 'paid_unallocated', 'updated_at' => $now ), array( 'order_id' => $order_id ), array( '%s', '%s', '%s' ), array( '%d' ) );
				$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_reservations SET status='expired' WHERE order_id=%d AND status='active'", $order_id ) );
				CapacityCounters::sync_order( $order_id );
				$wpdb->query( 'COMMIT' );
				self::flush_availability_cache();
				self::capacity_released( $order_id, 'payment_unallocated' );
				Audit::record( 'payment_unallocated', 'order', $order_id, array( 'reason' => $error, 'reference' => sanitize_text_field( $reference ) ) );
				wp_mail( get_option( 'admin_email' ), __( 'WP Pass Captain: Bezahlte Bestellung ohne freies Kontingent', 'wp-pass-captain' ), sprintf( __( 'Die Zahlung für Bestellung %1$s (%2$s) ist nach Ablauf der Reservierung eingegangen und es ist kein Kontingent mehr frei. Es wurden keine Tickets ausgestellt. Bitte manuell klären (Platz freigeben oder erstatten).', 'wp-pass-captain' ), $order->public_id, sanitize_text_field( $reference ) ) );
				return new \WP_Error( 'wpcap_needs_review', __( 'Die Reservierung war abgelaufen und es ist kein Kontingent mehr frei. Die Bestellung muss manuell geklärt werden.', 'wp-pass-captain' ) );
			}
			$recovered = true;
		}
		// Checked like Orders::create(): after a server-side rollback the remaining statements would
		// autocommit without the capacity re-check's locks.
		if ( false === $wpdb->update( "{$p}wpcap_orders", array( 'status' => 'complete', 'payment_status' => 'paid', 'updated_at' => $now ), array( 'order_id' => $order_id ), array( '%s', '%s', '%s' ), array( '%d' ) ) ) { return self::payment_busy(); }
		if ( false === $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_reservations SET status='converted' WHERE order_id=%d AND status IN ('active','expired','released')", $order_id ) ) ) { return self::payment_busy(); }
		// Holds leave held_qty, the paid lines enter sold_qty – atomically with the status change.
		if ( false === CapacityCounters::sync_order( $order_id ) ) { return self::payment_busy(); }
		Campaigns::commit_for_order( $order_id );
		if ( false === self::in_transaction() || false === $wpdb->query( 'COMMIT' ) ) { return self::payment_busy(); }
		Audit::record( 'external_payment_confirmed', 'order', $order_id, array( 'reference' => sanitize_text_field( $reference ), 'late_recovery' => $recovered ) );
		do_action( 'wpcap_order_paid', $order_id );
		self::issue_and_email( $order_id );
		return true;
	}

	/**
	 * Hold for a bank transfer / manual payment: `manual_hold_hours`, but never beyond the start of
	 * the earliest occurrence of the given ticket types (seats are not blocked past the start, and
	 * no payment arrives after the event). Null when the start is closer than `manual_cutoff_hours`
	 * (or already passed): bank transfer is then not offered.
	 *
	 * @param int[] $type_ids Ticket types of the order.
	 * @return int|null Minutes.
	 */
	public static function manual_hold_minutes( array $type_ids ) {
		global $wpdb;
		$minutes = max( 1, (int) Settings::get( 'manual_hold_hours' ) ) * 60;
		$type_ids = array_filter( array_map( 'absint', $type_ids ) );
		if ( ! $type_ids ) { return $minutes; }
		$start = $wpdb->get_var( "SELECT MIN(o.starts_at) FROM {$wpdb->prefix}wpcap_ticket_types tt INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id IN (" . implode( ',', $type_ids ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		return self::manual_hold_until( $start ? strtotime( $start . ' UTC' ) : 0, $minutes );
	}

	/** manual_hold_minutes() for a known start timestamp (0 = unknown: no cap). */
	public static function manual_hold_until( $start_ts, $minutes = null ) {
		$minutes = null === $minutes ? max( 1, (int) Settings::get( 'manual_hold_hours' ) ) * 60 : (int) $minutes;
		if ( ! $start_ts ) { return $minutes; }
		$left = (int) floor( ( (int) $start_ts - time() ) / 60 );
		$cutoff = max( 0, (int) Settings::get( 'manual_cutoff_hours' ) ) * 60;
		if ( $left < 1 || $left < $cutoff ) { return null; }
		return max( 1, min( $minutes, $left ) );
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
		$revoked_ids = self::revoke_ids( $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=%d AND status='valid' FOR UPDATE", $order_id ) ) );
		$revoked = count( $revoked_ids );
		$wpdb->update( "{$p}wpcap_orders", array( 'status' => 'cancelled', 'payment_status' => 'cancelled', 'updated_at' => $now ), array( 'order_id' => $order_id ), array( '%s', '%s', '%s' ), array( '%d' ) );
		$wpdb->query( 'COMMIT' );
		// Released units leave the counters right after the commit (until then they only over-count).
		CapacityCounters::sync_order_now( $order_id );
		Campaigns::release_for_order( $order_id );
		self::capacity_released( $order_id, 'cancelled', array( 'reason' => (string) $reason ) );
		Audit::record( 'order_cancelled', 'order', $order_id, array( 'reason' => sanitize_text_field( $reason ), 'revoked_tickets' => $revoked ) );
		self::fire_revoked( $revoked_ids, 'order_cancelled' );
		do_action( 'wpcap_order_cancelled', $order_id );
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
		$revoked_ids = self::revoke_ids( $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM {$wpdb->prefix}wpcap_tickets WHERE order_id=%d AND ticket_type_id=%d AND status='valid' ORDER BY ticket_id DESC LIMIT %d", absint( $order_id ), absint( $ticket_type_id ), $count ) ) );
		$revoked = count( $revoked_ids );
		self::fire_revoked( $revoked_ids, $reason );
		if ( $revoked ) {
			// Revoked units no longer consume capacity.
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_order_items SET refunded_quantity=LEAST(quantity,refunded_quantity+%d) WHERE order_id=%d AND ticket_type_id=%d ORDER BY item_id LIMIT 1", $revoked, absint( $order_id ), absint( $ticket_type_id ) ) );
			CapacityCounters::sync_order_now( $order_id );
			self::capacity_released( $order_id, 'tickets_revoked', array( 'ticket_type_id' => absint( $ticket_type_id ), 'quantity' => $revoked, 'reason' => (string) $reason ) );
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
		$revoked_ids = self::revoke_ids( $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=%d AND status='valid'", $order_id ) ) );
		$revoked = count( $revoked_ids );
		$wpdb->update( "{$p}wpcap_orders", array( 'status' => 'refunded', 'payment_status' => 'refunded', 'updated_at' => current_time( 'mysql', true ) ), array( 'order_id' => $order_id ), array( '%s', '%s', '%s' ), array( '%d' ) );
		CapacityCounters::sync_order_now( $order_id );
		self::capacity_released( $order_id, 'refunded', array( 'reason' => (string) $reason ) );
		Audit::record( 'order_refunded', 'order', $order_id, array( 'reason' => sanitize_text_field( $reason ), 'revoked_tickets' => $revoked ) );
		self::fire_revoked( $revoked_ids, $reason ?: 'refunded' );
		/** Order fully refunded (native refund, WooCommerce or provider dashboard). */
		do_action( 'wpcap_order_refunded', $order_id, (string) $reason );
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

	/** Order items with ticket names. */
	public static function items_for_order( $order_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_results( $wpdb->prepare( "SELECT oi.*,tt.name,e.name AS event_name FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE oi.order_id=%d ORDER BY oi.item_id", absint( $order_id ) ) );
	}

	/**
	 * What was ordered, for the buyer (portal, order mail): one row per line with event, ticket type,
	 * date, quantity and gross after discount (lines as paid, see OrderLines).
	 *
	 * @return array[] event, ticket, when, quantity, gross_minor, currency.
	 */
	public static function summary_lines( $order_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order = self::get( $order_id );
		if ( ! $order ) { return array(); }
		$lines = $wpdb->get_results( $wpdb->prepare( 'SELECT oi.item_id,oi.ticket_type_id,' . OrderLines::paid_columns() . ",tt.name,e.name AS event_name,o.starts_at,o.timezone FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE oi.order_id=%d ORDER BY oi.item_id", absint( $order_id ) ) );
		$lines = array_values( array_filter( (array) $lines, static function ( $l ) { return ! OrderLines::is_moved_in( $l ); } ) );
		$after = OrderLines::after_discount( $lines, (int) $order->discount_minor );
		$out = array();
		foreach ( $lines as $i => $line ) {
			if ( (int) $line->quantity < 1 && (int) $after[ $i ] < 1 ) { continue; }
			$out[] = array(
				'event' => (string) $line->event_name,
				'ticket' => (string) $line->name,
				'when' => self::format_start( $line->starts_at, $line->timezone ),
				'quantity' => (int) $line->quantity,
				'gross_minor' => (int) $after[ $i ],
				'currency' => (string) $order->currency,
			);
		}
		return $out;
	}

	/** Stores the provider's payment reference (session/order id). */
	public static function set_provider_reference( $order_id, $reference ) {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'wpcap_orders', array( 'provider_reference' => substr( sanitize_text_field( $reference ), 0, 191 ) ), array( 'order_id' => absint( $order_id ) ) );
	}

	/**
	 * Buyer billing data of an order (row or id): company, first_name, last_name, street, postcode,
	 * city, country, vat_id, po_number. Empty array when the buyer gave none.
	 */
	public static function billing( $order ) {
		return Billing::for_order( $order );
	}

	/** Returns an order row. */
	public static function get( $order_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_orders WHERE order_id=%d", absint( $order_id ) ) );
	}

	public static function send_order_access_link( $email, $public_id, $access_token, $locale = null ) {
		global $wpdb;
		$url = add_query_arg( array( 'wpcap_order' => $public_id, 'wpcap_access' => $access_token ), home_url( '/' ) );
		if ( null === $locale ) {
			$locale = (string) $wpdb->get_var( $wpdb->prepare( "SELECT locale FROM {$wpdb->prefix}wpcap_orders WHERE public_id=%s", (string) $public_id ) );
		}
		return Mail\Mailer::send( 'order_access', sanitize_email( $email ), array( 'order_id' => (string) $public_id, 'access_link' => Mail\Mailer::link( $url ) ), array(), (string) $locale );
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
		// A holder change by assignment counts against the same limit as a transfer.
		if ( method_exists( TicketAssignments::class, 'holder_changes' ) && TicketAssignments::holder_changes( (int) $ticket->ticket_id ) >= (int) apply_filters( 'wpcap_max_holder_changes', 1, (int) $ticket->ticket_id ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_transfer_holder_changed', __( 'Für dieses Ticket wurde der Inhaber bereits geändert; eine weitere Übertragung ist nicht möglich.', 'wp-pass-captain' ), array( 'status' => 409 ) );
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
		// The previous holder's name and custom answers must not stay on the transferred ticket.
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_ticket_assignments SET attendee_name='',attendee_email=%s WHERE ticket_id=%d", $recipient_email, (int) $ticket->ticket_id ) );
		$wpdb->delete( $wpdb->prefix . 'wpcap_participant_answers', array( 'ticket_id' => (int) $ticket->ticket_id ) );
		$wpdb->query( 'COMMIT' );
		/** A ticket received a new code; the old one is invalid. $token is plaintext – never store it. */
		do_action( 'wpcap_ticket_code_rotated', (int) $ticket->ticket_id, $token, 'transfer' );
		$ticket_url = add_query_arg( 'wpcap_ticket', $token, home_url( '/' ) );
		$vars = array(
			'order_id' => (string) $order->public_id,
			'event_name' => (string) $ticket->event_name,
			'ticket_name' => (string) $ticket->ticket_name,
			'event_date' => self::format_start( $ticket->starts_at, $ticket->timezone ),
			'event_location' => (string) $ticket->location,
			'ticket_link' => Mail\Mailer::link( $ticket_url ),
			'ticket_extras' => Mail\Blocks::extra( (string) apply_filters( 'wpcap_ticket_code_mail_section', '', (int) $ticket->ticket_id ) ),
			'recipient_email' => $recipient_email,
		);
		// The recipient's language is unknown; the order's locale is the best available guess.
		if ( ! Mail\Mailer::send( 'ticket_transfer_recipient', $recipient_email, $vars, array(), (string) $order->locale ) ) {
			return new \WP_Error( 'wpcap_transfer_mail_failed', __( 'Die Ticketübertragung wurde gespeichert, aber die E-Mail konnte nicht zugestellt werden. Bitte kontaktiere den Veranstalter für einen erneuten Versand.', 'wp-pass-captain' ), array( 'status' => 502 ) );
		}
		Mail\Mailer::send( 'ticket_transfer_sender', $order->customer_email, $vars, array(), (string) $order->locale );
		Audit::record( 'ticket_transferred', 'ticket', $ticket->ticket_id, array( 'order_id' => (int) $order->order_id ) );
		return array( 'status' => 'transferred' );
	}

	/**
	 * Cancels a published event. Every occurrence that is not cancelled yet goes through the regular
	 * occurrence cancellation (`wpcap_occurrence_cancelled` → OccurrenceCancellation): open orders
	 * are cancelled, paid native orders are refunded when `auto_refund_cancelled` is on, remaining
	 * tickets are revoked and module listeners (SMS, wallet passes, webhooks, reminders) run.
	 * Buyers get one "event cancelled" mail instead of one mail per occurrence. Tickets revoked
	 * this way stay refundable (see refundable_tickets()).
	 *
	 * @return array|\WP_Error {status, notified_count, occurrences, cancelled_orders, refunds, refund_errors, revoked, auto_refund}
	 */
	public static function cancel_event( $event_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$event_id = absint( $event_id );
		$event = $wpdb->get_row( $wpdb->prepare( "SELECT event_id,name,status FROM {$p}wpcap_events WHERE event_id=%d", $event_id ) );
		if ( ! $event || 'published' !== $event->status ) {
			return new \WP_Error( 'wpcap_event_not_cancellable', __( 'Dieses Event kann nicht abgesagt werden.', 'wp-pass-captain' ) );
		}
		$order_ids = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT oi.order_id FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE o.event_id=%d", $event_id ) ) );
		// Only buyers with a paid or open order are notified, not abandoned/expired/refunded attempts.
		// Read before the cancellation changes order and ticket states.
		$notify_ids = $order_ids ? array_map( 'absint', $wpdb->get_col( "SELECT order_id FROM {$p}wpcap_orders WHERE order_id IN (" . implode( ',', $order_ids ) . ") AND payment_status IN ('paid','pending','paid_unallocated')" ) ) : array();
		$pending_before = $order_ids ? array_map( 'absint', $wpdb->get_col( "SELECT order_id FROM {$p}wpcap_orders WHERE order_id IN (" . implode( ',', $order_ids ) . ") AND payment_status='pending'" ) ) : array();
		$recipients = ! $notify_ids ? array() : $wpdb->get_results( $wpdb->prepare(
			"SELECT COALESCE(NULLIF(t.attendee_email,''),ord.customer_email) AS email,MAX(ord.locale) AS locale FROM {$p}wpcap_orders ord INNER JOIN {$p}wpcap_order_items oi ON oi.order_id=ord.order_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id LEFT JOIN {$p}wpcap_tickets t ON t.order_id=ord.order_id AND t.ticket_type_id=tt.ticket_type_id WHERE o.event_id=%d AND ord.order_id IN (" . implode( ',', $notify_ids ) . ") GROUP BY email",
			$event_id
		) );

		$wpdb->query( 'START TRANSACTION' );
		$changed = $wpdb->update( "{$p}wpcap_events", array( 'status' => 'cancelled', 'updated_at' => current_time( 'mysql', true ) ), array( 'event_id' => $event_id, 'status' => 'published' ), array( '%s', '%s' ), array( '%d', '%s' ) );
		if ( 1 !== (int) $changed ) {
			// A concurrent request cancelled (or changed) the event first.
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_event_not_cancellable', __( 'Dieses Event kann nicht abgesagt werden.', 'wp-pass-captain' ) );
		}
		$occurrence_ids = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT occurrence_id FROM {$p}wpcap_occurrences WHERE event_id=%d AND status<>'cancelled' ORDER BY occurrence_id FOR UPDATE", $event_id ) ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_occurrences SET status='cancelled' WHERE event_id=%d", $event_id ) );
		$wpdb->query( 'COMMIT' );

		$summary = array( 'cancelled_orders' => 0, 'refunds' => 0, 'refund_errors' => 0, 'revoked' => 0 );
		OccurrenceCancellation::begin_event_batch();
		try {
			foreach ( $occurrence_ids as $occurrence_id ) {
				$issued = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=%d AND t.status IN ('valid','checked_in')", $occurrence_id ) );
				Audit::record( 'occurrence_cancelled', 'occurrence', $occurrence_id, array( 'event_id' => $event_id, 'issued_tickets' => $issued, 'via' => 'event_cancelled' ) );
				do_action( 'wpcap_occurrence_cancelled', $occurrence_id, $issued );
				foreach ( array_keys( $summary ) as $key ) { $summary[ $key ] += (int) ( OccurrenceCancellation::last_summary( $occurrence_id )[ $key ] ?? 0 ); }
			}
		} finally {
			OccurrenceCancellation::end_event_batch();
		}

		// Safety net when the occurrence handler is unhooked: open orders and still valid tickets.
		$still_pending = $order_ids ? array_map( 'absint', $wpdb->get_col( "SELECT order_id FROM {$p}wpcap_orders WHERE order_id IN (" . implode( ',', $order_ids ) . ") AND payment_status='pending'" ) ) : array();
		foreach ( $still_pending as $order_id ) {
			self::cancel_order( $order_id, 'event_cancelled' );
			++$summary['cancelled_orders'];
		}
		$revoked_ids = self::revoke_ids( $wpdb->get_col( $wpdb->prepare( "SELECT t.ticket_id FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE o.event_id=%d AND t.status='valid'", $event_id ) ) );
		$summary['revoked'] += count( $revoked_ids );
		self::fire_revoked( $revoked_ids, 'event_cancelled' );

		$emails = array();
		foreach ( $recipients as $recipient ) {
			if ( is_email( $recipient->email ) && Mail\Mailer::send( 'event_cancelled', $recipient->email, array( 'event_name' => (string) $event->name ), array(), (string) $recipient->locale ) ) {
				$emails[] = $recipient->email;
			}
		}
		// Open orders already reported their release through cancel_order().
		foreach ( array_diff( $order_ids, $pending_before ) as $released_order_id ) { self::capacity_released( $released_order_id, 'event_cancelled' ); }
		$result = array_merge( $summary, array(
			'status' => 'cancelled',
			'notified_count' => count( $emails ),
			'occurrences' => count( $occurrence_ids ),
			'auto_refund' => (bool) Settings::get( 'auto_refund_cancelled' ),
		) );
		Audit::record( 'event_cancelled', 'event', $event_id, $result );
		return $result;
	}

	/**
	 * Tickets of an order that can still be refunded natively: not refunded yet, and either valid,
	 * checked in, or revoked because their occurrence or event was cancelled (the buyer paid for an
	 * event that did not take place). Tickets revoked for other reasons are not refundable here.
	 *
	 * @return object[] Rows {ticket_id, ticket_type_id}, ordered by ticket_id.
	 */
	public static function refundable_tickets( $order_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT t.ticket_id,t.ticket_type_id FROM {$p}wpcap_tickets t LEFT JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id LEFT JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id LEFT JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE t.order_id=%d AND t.refunded=0 AND (t.status IN ('valid','checked_in') OR (t.status='revoked' AND (o.status='cancelled' OR e.status='cancelled'))) ORDER BY t.ticket_id",
			absint( $order_id )
		) );
		return (array) apply_filters( 'wpcap_refundable_tickets', $rows, absint( $order_id ) );
	}

	/**
	 * Staff confirmation of a manual payment (bank transfer, box office).
	 *
	 * Manual payments usually arrive after the short checkout hold has lapsed, so this uses the same
	 * locked capacity re-check as external payments instead of rejecting expired holds: the order is
	 * confirmed if capacity is still available, otherwise flagged `needs_review`.
	 */
	public static function confirm_manual_payment( $order_id ) {
		$order = self::get( $order_id );
		if ( ! $order || 'paid' === $order->payment_status || in_array( $order->status, array( 'refunded', 'cancelled' ), true ) ) {
			return new \WP_Error( 'wpcap_order_state', __( 'Der Bestellstatus erlaubt diese Aktion nicht.', 'wp-pass-captain' ) );
		}
		$result = self::confirm_external_payment( $order_id, 'manual:user-' . get_current_user_id() );
		if ( ! is_wp_error( $result ) ) {
			Audit::record( 'manual_payment_confirmed', 'order', absint( $order_id ) );
		}
		return $result;
	}

	/**
	 * Expires unpaid orders whose holds have lapsed (cron `wpcap_cleanup_reservations`).
	 *
	 * Each order is expired by its own conditional UPDATE (still pending, no active hold), and the
	 * side effects (campaign release, capacity released, wpcap_order_cancelled) only run when that
	 * UPDATE changed the row – an order paid between selection and update is left alone.
	 * At most $limit orders per run (filter `wpcap_expire_holds_batch`); the next run continues.
	 *
	 * @param int $limit Batch size (cron passes no argument).
	 * @return int Number of expired orders.
	 */
	public static function expire_holds( $limit = 200 ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$reservations = $wpdb->prefix . 'wpcap_reservations';
		$orders = $wpdb->prefix . 'wpcap_orders';
		$limit = max( 1, (int) apply_filters( 'wpcap_expire_holds_batch', absint( $limit ) ?: 200 ) );
		// Orders whose holds lapse now: their held units leave the counters after the update.
		$lapsed_orders = CapacityCounters::maintained() ? array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT order_id FROM $reservations WHERE status='active' AND expires_at <= %s ORDER BY order_id", $now ) ) ) : array();
		$wpdb->query( $wpdb->prepare( "UPDATE $reservations SET status='expired' WHERE status='active' AND expires_at <= %s", $now ) );
		foreach ( $lapsed_orders as $lapsed_order_id ) { CapacityCounters::sync_order_now( $lapsed_order_id ); }
		$candidates = $wpdb->get_col( $wpdb->prepare(
			"SELECT ord.order_id FROM $orders ord WHERE ord.payment_status='pending' AND NOT EXISTS (SELECT 1 FROM $reservations r WHERE r.order_id=ord.order_id AND r.status='active' AND r.expires_at>%s) ORDER BY ord.order_id LIMIT %d",
			$now,
			$limit
		) );
		$expired = 0;
		foreach ( array_map( 'absint', $candidates ) as $order_id ) {
			$changed = $wpdb->query( $wpdb->prepare(
				"UPDATE $orders SET status='expired',payment_status='expired',updated_at=%s WHERE order_id=%d AND payment_status='pending' AND NOT EXISTS (SELECT 1 FROM $reservations r WHERE r.order_id=%d AND r.status='active' AND r.expires_at > %s)",
				$now,
				$order_id,
				$order_id,
				$now
			) );
			if ( 1 !== (int) $changed ) { continue; }
			++$expired;
			Campaigns::release_for_order( $order_id );
			self::capacity_released( $order_id, 'expired' );
			do_action( 'wpcap_order_cancelled', $order_id );
		}
		return $expired;
	}

	public static function issue_and_email( $order_id ) {
		global $wpdb;
		$orders = $wpdb->prefix . 'wpcap_orders';
		// Serialise issuance per order so concurrent payment callbacks cannot create duplicates.
		$wpdb->query( 'START TRANSACTION' );
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $orders WHERE order_id=%d FOR UPDATE", $order_id ) );
		if ( '' !== $wpdb->last_error ) {
			$wpdb->query( 'ROLLBACK' );
			return self::issue_failed( $order_id, 'lock' );
		}
		if ( ! $order || 'paid' !== $order->payment_status ) {
			$wpdb->query( 'COMMIT' );
			return;
		}
		$lines = $wpdb->get_results( $wpdb->prepare( "SELECT oi.*,tt.name AS ticket_name,tt.occurrence_id,o.event_id,e.name AS event_name,o.starts_at,o.timezone,o.location FROM {$wpdb->prefix}wpcap_order_items oi INNER JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$wpdb->prefix}wpcap_events e ON e.event_id=o.event_id WHERE oi.order_id=%d", $order_id ) );
		/**
		 * Order lines that receive tickets. Modules may drop lines that are no admission of their own,
		 * e.g. the supplementary payment order of a ticket exchange (Exchange\Service issues that ticket).
		 *
		 * @param object[] $lines Order item rows with ticket/occurrence/event columns.
		 * @param object   $order Locked order row.
		 */
		$lines = (array) apply_filters( 'wpcap_issue_order_lines', $lines, $order );
		$issued = array();
		// Attendee data collected at checkout, applied once to the issued tickets.
		$pending_attendees = $order->attendee_data ? (array) json_decode( $order->attendee_data, true ) : array();
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
				if ( '' !== $wpdb->last_error || ! $wpdb->insert_id ) {
					// Nothing of this issuance is kept (the attendee data stays on the order for a retry).
					$wpdb->query( 'ROLLBACK' );
					return self::issue_failed( $order_id, 'insert' );
				}
				if ( $wpdb->insert_id ) {
					$ticket_id = (int) $wpdb->insert_id;
					foreach ( $pending_attendees as $index => $attendee ) {
						if ( (int) ( $attendee['ticket_type_id'] ?? 0 ) !== (int) $line->ticket_type_id ) { continue; }
						unset( $pending_attendees[ $index ] );
						self::store_attendee( $ticket_id, $order, $attendee );
						break;
					}
					/** Inside the issuance transaction, e.g. to assign a seat or area. Do not store $token. */
					do_action( 'wpcap_ticket_created', $ticket_id, $order, $line, $i );
					$issued[] = array( 'ticket_id' => $ticket_id, 'ticket_type_id' => (int) $line->ticket_type_id, 'occurrence_id' => (int) ( $line->occurrence_id ?? 0 ), 'token' => $token, 'event' => $line->event_name, 'ticket' => $line->ticket_name, 'starts_at' => $line->starts_at, 'timezone' => $line->timezone, 'location' => $line->location );
				}
			}
		}
		if ( $issued && $order->attendee_data ) {
			// Personal data now lives with the tickets; drop the order-level copy (data minimisation).
			if ( false === $wpdb->update( $orders, array( 'attendee_data' => null ), array( 'order_id' => (int) $order_id ) ) ) {
				$wpdb->query( 'ROLLBACK' );
				return self::issue_failed( $order_id, 'attendee_data' );
			}
		}
		// A failed COMMIT (or a transaction the server rolled back) must not send tickets that do not exist.
		if ( false === self::in_transaction() || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return self::issue_failed( $order_id, 'commit' );
		}
		if ( $issued ) {
			do_action( 'wpcap_tickets_issued', (int) $order_id, count( $issued ) );
			$entries = array_map( static function ( $ticket ) {
				return array( 'event' => $ticket['event'], 'ticket' => $ticket['ticket'], 'when' => self::format_start( $ticket['starts_at'], $ticket['timezone'] ), 'location' => $ticket['location'], 'url' => add_query_arg( 'wpcap_ticket', $ticket['token'], home_url( '/' ) ), 'token' => $ticket['token'] );
			}, $issued );
			$message = Mail\Mailer::compose( 'tickets', self::ticket_mail_vars( $order, $entries, static function () use ( $entries ) { return Mail\Blocks::issued_tickets( $entries ); } ), (string) $order->locale );
			$attachments = array();
			if ( apply_filters( 'wpcap_attach_ticket_pdf', true, (int) $order_id ) ) {
				$pdf_tickets = array_map( static function ( $ticket ) {
					return array( 'event' => $ticket['event'], 'ticket' => $ticket['ticket'], 'when' => self::format_start( $ticket['starts_at'], $ticket['timezone'] ), 'location' => $ticket['location'], 'seat' => (string) apply_filters( 'wpcap_ticket_seat_label', '', (int) $ticket['ticket_id'], $ticket ), 'token' => $ticket['token'] );
				}, $issued );
				// The PDF holds plaintext ticket codes: private directory (0700) and file (0600) with a
				// random name in the shared temp dir, removed right after delivery.
				$pdf_dir = self::private_temp_dir();
				if ( $pdf_dir ) {
					$file = $pdf_dir . '/tickets-' . $order->public_id . '.pdf';
					if ( false !== file_put_contents( $file, TicketPdf::render( $pdf_tickets, array( 'title' => get_bloginfo( 'name' ), 'footer' => __( 'Dieses Ticket ist nur einmal gültig. Code nicht weitergeben.', 'wp-pass-captain' ) ) ) ) ) {
						@chmod( $file, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
						$attachments[] = $file;
					}
				}
			}
			$mail = array( 'attachments' => $attachments );
			try {
				// Legacy filter: receives and may change subject/body/attachments of the rendered template.
				$mail = apply_filters( 'wpcap_ticket_mail', array( 'subject' => $message['subject'], 'body' => $message['body'], 'attachments' => $attachments ), $order, $issued );
				$mail = is_array( $mail ) ? $mail : array( 'attachments' => $attachments );
				Mail\Mailer::deliver( $order->customer_email, Mail\Mailer::apply_filtered( $message, $mail ) );
			} finally {
				$attachments = array_unique( array_merge( $attachments, (array) ( $mail['attachments'] ?? array() ) ) );
				foreach ( $attachments as $file ) { wp_delete_file( $file ); }
				if ( ! empty( $pdf_dir ) && is_dir( $pdf_dir ) ) { @rmdir( $pdf_dir ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	}

	/**
	 * Ticket issuance hit a database error: nothing was issued, the order stays paid with its
	 * attendee data, and a later call (webhook retry, resend, "Zuteilung erneut versuchen") issues it.
	 */
	private static function issue_failed( $order_id, $step ) {
		Audit::record( 'ticket_issue_failed', 'order', absint( $order_id ), array( 'step' => (string) $step ) );
		return new \WP_Error( 'wpcap_db_busy', __( 'Die Tickets konnten gerade nicht ausgestellt werden. Bitte erneut versuchen.', 'wp-pass-captain' ), array( 'status' => 503, 'retryable' => true ) );
	}

	/** Creates a fresh directory (0700, random name) in the temp dir; null on failure. */
	private static function private_temp_dir() {
		$base = rtrim( (string) get_temp_dir(), '/\\' );
		for ( $i = 0; $i < 3; $i++ ) {
			$dir = $base . '/wpcap-' . bin2hex( random_bytes( 12 ) );
			$old_umask = umask( 0077 );
			$made = @mkdir( $dir, 0700 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an existing name is retried.
			umask( $old_umask );
			if ( $made ) {
				@chmod( $dir, 0700 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return $dir;
			}
		}
		return null;
	}

	/**
	 * Placeholder values shared by the ticket and resend mails.
	 *
	 * @param object   $order   Order row.
	 * @param array    $entries Ticket entries (event, ticket, when, location, url).
	 * @param callable $list    Builds the {ticket_list} block (runs in the recipient locale).
	 */
	private static function ticket_mail_vars( $order, array $entries, $list ) {
		$first = $entries[0] ?? array();
		return array(
			'order_id' => (string) $order->public_id,
			'customer_email' => (string) $order->customer_email,
			'event_name' => (string) ( $first['event'] ?? '' ),
			'event_date' => (string) ( $first['when'] ?? '' ),
			'event_location' => (string) ( $first['location'] ?? '' ),
			'ticket_count' => count( $entries ),
			'ticket_list' => $list,
			'ticket_links' => Mail\Blocks::links( array_column( $entries, 'url' ) ),
		);
	}

	/** Event start in the event's own timezone; starts_at is stored in UTC. */
	public static function format_start( $starts_at, $timezone ) {
		$tz = (string) $timezone;
		try { $zone = new \DateTimeZone( '' !== $tz ? $tz : 'UTC' ); } catch ( \Exception $e ) { $zone = new \DateTimeZone( 'UTC' ); $tz = 'UTC'; }
		$ts = strtotime( $starts_at . ' UTC' );
		return false === $ts ? (string) $starts_at : wp_date( 'd.m.Y H:i', $ts, $zone ) . ( '' !== $tz ? ' ' . $tz : '' );
	}

	/** Name used in customer communication (site title, falls back to the host name). */
	public static function site_name() {
		$name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		return (string) apply_filters( 'wpcap_sender_name', '' !== trim( $name ) ? $name : (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	public static function resend_tickets( $order_id ) {
		global $wpdb;
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_orders WHERE order_id=%d", absint( $order_id ) ) );
		if ( ! $order || 'paid' !== $order->payment_status ) {
			return new \WP_Error( 'wpcap_order_unpaid', __( 'Tickets können erst nach bestätigter Zahlung erneut gesendet werden.', 'wp-pass-captain' ) );
		}
		$tickets = $wpdb->get_results( $wpdb->prepare(
			"SELECT t.ticket_id,t.status,t.attendee_email,tt.name AS ticket_name,e.name AS event_name,o.starts_at,o.timezone,o.location FROM {$wpdb->prefix}wpcap_tickets t INNER JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$wpdb->prefix}wpcap_events e ON e.event_id=o.event_id WHERE t.order_id=%d AND t.status='valid' ORDER BY t.ticket_id",
			$order_id
		) );
		// Codes are stored hashed, so a resend has to rotate them. Checked-in tickets are skipped:
		// they are used, and rotating them would only invalidate the holder's admission history link.
		if ( ! $tickets ) {
			return new \WP_Error( 'wpcap_no_resendable_tickets', __( 'Keine gültigen Tickets für den erneuten Versand gefunden.', 'wp-pass-captain' ) );
		}
		$entries = array();
		foreach ( $tickets as $ticket ) {
			$token = bin2hex( random_bytes( 32 ) );
			$updated = $wpdb->update( $wpdb->prefix . 'wpcap_tickets', array( 'token_hash' => hash( 'sha256', $token ) ), array( 'ticket_id' => (int) $ticket->ticket_id, 'status' => $ticket->status ), array( '%s' ), array( '%d', '%s' ) );
			if ( false === $updated ) {
				return new \WP_Error( 'wpcap_ticket_rotation_failed', __( 'Ticketcode konnte nicht erneuert werden.', 'wp-pass-captain' ) );
			}
			do_action( 'wpcap_ticket_code_rotated', (int) $ticket->ticket_id, $token, 'resend' );
			$recipient = is_email( $ticket->attendee_email ) ? $ticket->attendee_email : $order->customer_email;
			$entries[ $recipient ][] = array( 'event' => $ticket->event_name, 'ticket' => $ticket->ticket_name, 'when' => self::format_start( $ticket->starts_at, $ticket->timezone ), 'location' => $ticket->location, 'url' => add_query_arg( 'wpcap_ticket', $token, home_url( '/' ) ), 'extra' => (string) apply_filters( 'wpcap_ticket_code_mail_section', '', (int) $ticket->ticket_id ) );
		}
		$sent = true;
		foreach ( $entries as $recipient => $list ) {
			$vars = self::ticket_mail_vars( $order, $list, static function () use ( $list ) { return Mail\Blocks::code_tickets( $list ); } );
			$sent = Mail\Mailer::send( 'tickets_resend', $recipient, $vars, array(), (string) $order->locale ) && $sent;
		}
		if ( ! $sent ) {
			return new \WP_Error( 'wpcap_mail_failed', __( 'Mindestens eine E-Mail konnte nicht zugestellt werden. Ticketcodes wurden erneuert; bitte versuche den Versand erneut.', 'wp-pass-captain' ) );
		}
		Audit::record( 'tickets_resent', 'order', $order_id, array( 'ticket_count' => count( $tickets ) ) );
		return true;
	}

	/**
	 * Reissues a single valid ticket: rotates only its code (the old QR code stops working) and mails
	 * the new link to the ticket holder (attendee e-mail, else the buyer).
	 *
	 * @param int   $ticket_id Ticket ID.
	 * @param array $context   reason (string, audit), notify (bool, default true).
	 * @return array|\WP_Error {ticket_id, recipient, mailed}
	 */
	public static function reissue_ticket( $ticket_id, array $context = array() ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$ticket_id = absint( $ticket_id );
		$wpdb->query( 'START TRANSACTION' );
		$ticket = $wpdb->get_row( $wpdb->prepare(
			"SELECT t.ticket_id,t.order_id,t.status,t.attendee_email,tt.name AS ticket_name,e.event_id,e.name AS event_name,o.starts_at,o.timezone,o.location FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE t.ticket_id=%d FOR UPDATE",
			$ticket_id
		) );
		$order = $ticket ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_orders WHERE order_id=%d", (int) $ticket->order_id ) ) : null;
		if ( ! $ticket || ! $order ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_ticket_missing', __( 'Ticket nicht gefunden.', 'wp-pass-captain' ), array( 'status' => 404 ) );
		}
		if ( 'valid' !== $ticket->status || 'paid' !== $order->payment_status ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_ticket_not_reissuable', __( 'Nur gültige, nicht eingecheckte Tickets bezahlter Bestellungen können neu ausgestellt werden.', 'wp-pass-captain' ), array( 'status' => 409 ) );
		}
		$token = bin2hex( random_bytes( 32 ) );
		$updated = $wpdb->update( "{$p}wpcap_tickets", array( 'token_hash' => hash( 'sha256', $token ) ), array( 'ticket_id' => $ticket_id, 'status' => 'valid' ), array( '%s' ), array( '%d', '%s' ) );
		if ( 1 !== (int) $updated ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_ticket_rotation_failed', __( 'Ticketcode konnte nicht erneuert werden.', 'wp-pass-captain' ), array( 'status' => 500 ) );
		}
		$wpdb->query( 'COMMIT' );
		/** A ticket received a new code; the old one is invalid. $token is plaintext – never store it. */
		do_action( 'wpcap_ticket_code_rotated', $ticket_id, $token, 'reissue' );
		$recipient = is_email( $ticket->attendee_email ) ? $ticket->attendee_email : $order->customer_email;
		$mailed = false;
		if ( ( $context['notify'] ?? true ) && is_email( $recipient ) ) {
			$list = array( array( 'event' => $ticket->event_name, 'ticket' => $ticket->ticket_name, 'when' => self::format_start( $ticket->starts_at, $ticket->timezone ), 'location' => $ticket->location, 'url' => add_query_arg( 'wpcap_ticket', $token, home_url( '/' ) ), 'extra' => (string) apply_filters( 'wpcap_ticket_code_mail_section', '', $ticket_id ) ) );
			$vars = self::ticket_mail_vars( $order, $list, static function () use ( $list ) { return Mail\Blocks::code_tickets( $list ); } );
			$mailed = (bool) Mail\Mailer::send( 'tickets_resend', $recipient, $vars, array(), (string) $order->locale );
		}
		Audit::record( 'ticket_reissued', 'ticket', $ticket_id, array( 'order_id' => (int) $order->order_id, 'event_id' => (int) $ticket->event_id, 'reason' => sanitize_text_field( (string) ( $context['reason'] ?? '' ) ), 'mailed' => $mailed ) );
		return array( 'ticket_id' => $ticket_id, 'recipient' => $recipient, 'mailed' => $mailed );
	}
}

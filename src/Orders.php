<?php
/** Order, inventory hold and ticket issuance services. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Orders {
	const HOLD_MINUTES = 10;
	/** Length of the e-mail columns (customer_email, attendee_email: varchar(190)). */
	const EMAIL_MAX_LENGTH = 190;

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
	 *                        billing (external only: billing data already validated by the adapter),
	 *                        skip_buyer_limits (bool; door sales are never limited, see Sales\BuyerLimits),
	 *                        attendees_deferred (external only: tickets without any attendee entry may be
	 *                        issued although their type has required fields; the buyer completes them in
	 *                        the order portal – WooCommerce orders created in the admin or over REST).
	 *
	 * Deadlocks and lock wait timeouts (busy database) are retried internally up to DB_RETRIES times
	 * with a short random pause (the idempotency key makes a retry safe); after that the result is a
	 * retryable 503 with `retry_after`. The whole call runs with a short innodb_lock_wait_timeout
	 * (DbSession::with_short_lock_wait()).
	 */
	public static function create( $payload, array $internal = array() ) {
		return DbSession::with_short_lock_wait( static function () use ( $payload, $internal ) {
			for ( $attempt = 0; ; ++$attempt ) {
				$result = self::create_attempt( $payload, $internal, $attempt );
				$data = $result instanceof \WP_Error ? (array) $result->get_error_data() : array();
				if ( empty( $data['db_busy'] ) || $attempt >= self::DB_RETRIES ) { return $result; }
				usleep( random_int( 30, 150 ) * 1000 * ( $attempt + 1 ) ); // Jitter, so the competing transactions do not collide again.
			}
		} );
	}

	/** Internal retries of a checkout transaction that hit a deadlock or lock wait timeout. */
	const DB_RETRIES = 2;

	/** Seconds a client should wait before retrying a request that met a busy database (Retry-After). */
	const RETRY_AFTER_SECONDS = 2;

	/** One checkout attempt (see create()); $attempt > 0 is an internal retry after a busy database. */
	private static function create_attempt( $payload, array $internal, $attempt ) {
		global $wpdb;
		$email = is_scalar( $payload['email'] ?? '' ) ? sanitize_email( (string) ( $payload['email'] ?? '' ) ) : '';
		$lines = $payload['items'] ?? array();
		if ( ! is_email( $email ) || ! is_array( $lines ) || empty( $lines ) || count( $lines ) > 10 ) {
			return new \WP_Error( 'wpcap_invalid_checkout', __( 'Bitte eine gültige E-Mail-Adresse und Tickets auswählen.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		// customer_email is varchar(190): a longer address passes is_email() but would fail the order insert.
		if ( strlen( $email ) > self::EMAIL_MAX_LENGTH ) {
			return self::email_too_long( 'email' );
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
			return new \WP_Error( 'wpcap_terms_required', __( 'Bitte bestätige die Bedingungen und Verbraucherinformationen.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$unit_prices = ( $external && is_array( $internal['unit_prices'] ?? null ) ) ? array_map( 'absint', $internal['unit_prices'] ) : array();
		// Exact gross line totals from the external system (unit prices may not divide evenly).
		$line_totals = ( $external && is_array( $internal['line_totals'] ?? null ) ) ? array_map( 'absint', $internal['line_totals'] ) : array();
		$external_currency = $external ? strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) ( $internal['currency'] ?? '' ) ) ) : '';
		// An internal retry was already counted (and admitted by the checkout gate) as one request.
		$skip_rate_limit = $external || ! empty( $internal['skip_rate_limit'] ) || $attempt > 0;
		$orders_table = $wpdb->prefix . 'wpcap_orders';
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE idempotency_key = %s', $orders_table, $key_hash ) );
		if ( $existing ) {
			return self::reused_result( $existing );
		}

		// The per-type limit is the ticket type's max_per_order (checked under lock below); this is
		// only an absolute safety cap against absurd quantities before any row is locked.
		$line_cap = max( 1, (int) apply_filters( 'wpcap_max_line_quantity', 100 ) );
		$clean_lines = array();
		foreach ( $lines as $line ) {
			// Strict: "-2", 1.5, arrays or non-array lines are rejected (absint() would turn -2 into 2).
			$type_id = is_array( $line ) ? self::positive_int( $line['ticket_type_id'] ?? null ) : 0;
			$quantity = is_array( $line ) ? self::positive_int( $line['quantity'] ?? null ) : 0;
			if ( ! $type_id || $quantity < 1 || $quantity > $line_cap ) {
				return new \WP_Error( 'wpcap_invalid_item', __( 'Eine Ticketposition ist ungültig.', 'pass-captain' ), array( 'status' => 400 ) );
			}
			$clean_lines[ $type_id ] = ( $clean_lines[ $type_id ] ?? 0 ) + $quantity;
			if ( $clean_lines[ $type_id ] > $line_cap ) {
				return new \WP_Error( 'wpcap_invalid_item', __( 'Eine Ticketposition ist ungültig.', 'pass-captain' ), array( 'status' => 400 ) );
			}
		}
		// Add-on gate (e.g. virtual waiting room) for public checkouts only: not for channels with their own
		// namespace (box office, waitlist offers) or trusted adapters (WooCommerce, vouchers). Sales\CheckoutGate.
		if ( ! $external && '' === $namespace && 0 === $attempt ) {
			$gate = Sales\CheckoutGate::check( array( 'source' => $internal['source'] ?? 'native', 'phase' => 'checkout', 'items' => $clean_lines, 'email' => $email, 'idempotency_key' => $key, 'request' => $internal['request'] ?? null ) );
			if ( is_wp_error( $gate ) ) { return $gate; }
		}
		$attendees = self::validate_attendees( $clean_lines, $payload['attendees'] ?? array(), $external && ! empty( $internal['attendees_deferred'] ) );
		if ( is_wp_error( $attendees ) ) { return $attendees; }
		// Buyer billing data (company/address/VAT ID). Trusted adapters (e.g. WooCommerce) pass data that
		// their own checkout already validated as $internal['billing']; customer input is validated here.
		$billing = ( $external && is_array( $internal['billing'] ?? null ) ) ? Billing::sanitize( $internal['billing'] ) : Billing::validate( $payload['billing'] ?? null );
		if ( is_wp_error( $billing ) ) { return $billing; }
		$billing = $billing && ! Billing::is_empty( $billing ) ? $billing : array();

		// Keep anonymous checkout abuse bounded without retaining raw IP addresses: per client network (IPv6 /64)
		// and per network + normalised e-mail; a checkout that creates no order is taken back (Sales\CheckoutThrottle).
		$throttle = null;
		if ( ! $skip_rate_limit ) {
			$throttle = Sales\CheckoutThrottle::check( $email, $hold_minutes );
			if ( is_wp_error( $throttle ) ) { return $throttle; }
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
		// Logged-in customer of a native checkout (buyer limits per account; never staff/adapter orders).
		$customer_user_id = ( $external || $door_sale || '' !== $namespace || ! empty( $internal['source'] ) ) ? 0 : absint( get_current_user_id() );
		// Buyer limits across orders (Sales\BuyerLimits): orders of the same buyer are serialised by a
		// lock taken before the transaction starts, so its snapshot contains all their committed orders.
		$check_buyer_limits = ! $door_sale && empty( $internal['skip_buyer_limits'] ) && Sales\BuyerLimits::relevant( array_keys( $clean_lines ) );
		$buyer_locks = array();
		if ( $check_buyer_limits ) {
			$buyer_locks = Sales\BuyerLimits::acquire( $email, $customer_user_id );
			if ( false === $buyer_locks ) {
				Sales\CheckoutThrottle::refund( $throttle );
				return new \WP_Error( 'wpcap_checkout_failed', __( 'Gerade ist sehr viel los. Bitte versuche es in einem Moment erneut.', 'pass-captain' ), array( 'status' => 503, 'retryable' => true, 'retry_after' => self::RETRY_AFTER_SECONDS ) );
			}
		}
		self::begin();
		try {
			ksort( $clean_lines, SORT_NUMERIC );
			// Lock phase (locking reads only, so the transaction's consistent snapshot is taken after
			// every lock is held): ticket types by id, then their occurrences by id, then module
			// resources via `wpcap_reserve_capacity`. Late payment recovery uses the same order.
			$types = array();
			foreach ( $clean_lines as $type_id => $quantity ) {
				$type = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE ticket_type_id = %d FOR UPDATE', $types_table, $type_id ) );
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
					'SELECT o.*,e.name AS event_name,e.status AS event_status FROM %i o INNER JOIN %i e ON e.event_id=o.event_id WHERE o.occurrence_id=%d FOR UPDATE', $occ_table, $event_table,
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
			if ( $check_buyer_limits ) {
				$limit_error = Sales\BuyerLimits::check( $clean_lines, $types, $occurrences, $email, $customer_user_id, $now );
				if ( $limit_error ) {
					$custom_error = $limit_error;
					throw new \RuntimeException( 'buyer_limit' );
				}
			}

			$selected = array();
			$total = 0;
			$price_rules = array();
			foreach ( $clean_lines as $type_id => $quantity ) {
				$type = $types[ $type_id ];
				$occurrence = $occurrences[ (int) $type->occurrence_id ];
				// Expired holds are ignored by the capacity check (expires_at > now) and marked by the
				// cleanup job; updating them here took gap locks that deadlocked concurrent checkouts.
				// Every statement is checked: after a deadlock or lock wait timeout MariaDB rolls the
				// transaction back (releasing the capacity locks) and later statements would autocommit.
				$capacity_error = self::capacity_error( $type, $occurrence, $quantity );
				if ( $capacity_error ) {
					throw new \RuntimeException( $capacity_error );
				}
				// Price precedence: an explicit trusted unit price (adapters/staff, e.g. WooCommerce or
				// free box office sales) wins; otherwise the stored price passes through the
				// server-side `wpcap_ticket_unit_price` filter. Client input never reaches either.
				$base_price = (int) $type->price_minor;
				$price_rule = null;
				if ( array_key_exists( $type_id, $unit_prices ) ) {
					$unit_price = (int) $unit_prices[ $type_id ];
				} else {
					$unit_price = self::unit_price( $type, $occurrence, $quantity, array( 'now' => $now, 'source' => $capacity_context['source'], 'phase' => 'create' ) );
					if ( $unit_price !== (int) $type->price_minor ) {
						$price_rules[ $type_id ] = array( 'base_minor' => (int) $type->price_minor, 'unit_minor' => $unit_price );
					}
					// Automatic price rule (Sales\PriceRules) frozen with the line: rule, explanation, prices.
					$price_rule = Sales\PriceRules::applied( $type_id );
				}
				// Versioned tax profile (Sales\TaxProfiles) unless a trusted adapter passed the line tax.
				$tax_profile = array_key_exists( $type_id, $line_taxes ) ? null : Sales\TaxProfiles::for_line( $type, $occurrence, $now );
				$type->price_minor = $unit_price;
				$line_total = array_key_exists( $type_id, $line_totals ) ? (int) $line_totals[ $type_id ] : $unit_price * $quantity;
				$total += $line_total;
				$selected[] = array( 'type' => $type, 'event_id' => (int) $occurrence->event_id, 'quantity' => $quantity, 'line_total' => $line_total, 'base_minor' => $base_price, 'price_rule' => $price_rule, 'tax_profile' => $tax_profile );
			}
			$subtotal = $total;
			$campaign_codes = $external ? array() : ( $payload['campaign_codes'] ?? array() );
			if ( ! is_array( $campaign_codes ) ) { throw new \RuntimeException( 'campaign_invalid' ); }
			$adjustments = Campaigns::calculate( $selected, $campaign_codes, $currency );
			if ( is_wp_error( $adjustments ) ) {
				// Stacking rules (combinable, maximum discount) explain themselves; other errors stay generic.
				if ( in_array( $adjustments->get_error_code(), array( 'wpcap_campaign_not_combinable', 'wpcap_campaign_max_discount' ), true ) ) {
					$custom_error = $adjustments;
					throw new \RuntimeException( $adjustments->get_error_code() );
				}
				throw new \RuntimeException( 'campaign_invalid' );
			}
			$discount = array_sum( array_map( static function ( $adjustment ) { return (int) $adjustment['amount_minor']; }, $adjustments ) );
			$total = max( 0, $subtotal - $discount );
			if ( ! $external && ! $billing && Billing::required_for( $total, $currency ) ) { throw new \RuntimeException( 'billing_required' ); }
			// Allocate each campaign's discount to the lines it applies to (proportionally, exact to the
			// minor unit) and compute VAT per line on the discounted gross amount. The per-line discounts
			// are kept in the price snapshot so invoices and refunds use the same split.
			$line_discounts = self::line_discounts( $selected, $adjustments );
			$tax_total = 0;
			foreach ( $selected as $index => $line ) {
				$line_discount = $line_discounts[ $index ];
				$rate = $line['tax_profile'] ? (int) $line['tax_profile']['rate_bp'] : (int) ( $line['type']->tax_rate_bp ?? 0 );
				$type_id_for_tax = (int) $line['type']->ticket_type_id;
				$selected[ $index ]['tax_rate_bp'] = $rate;
				$selected[ $index ]['discount_minor'] = (int) $line_discount;
				$selected[ $index ]['tax_minor'] = array_key_exists( $type_id_for_tax, $line_taxes ) ? (int) $line_taxes[ $type_id_for_tax ] : self::gross_tax( $line['line_total'] - $line_discount, $rate );
				$tax_total += $selected[ $index ]['tax_minor'];
			}
			$snapshot = array( 'currency' => $currency, 'subtotal_minor' => $subtotal, 'discount_minor' => $discount, 'total_minor' => $total, 'campaigns' => array_map( static function ( $adjustment ) { return array( 'code' => $adjustment['campaign']->code, 'amount_minor' => (int) $adjustment['amount_minor'] ); }, $adjustments ) );
			if ( $discount ) {
				$snapshot['line_discounts'] = array();
				foreach ( $selected as $index => $line ) { $snapshot['line_discounts'][ (int) $line['type']->ticket_type_id ] = (int) $line_discounts[ $index ]; }
			}
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
				'locale' => Localization::order_locale( $payload['locale'] ?? '' ), // Buyer's explicit language choice (W9) or request locale.
				'attendee_data' => $attendees ? wp_json_encode( $attendees ) : null,
				'created_at' => $now,
				'updated_at' => $now,
			);
			// Only written when given, so checkouts keep working while a deferred schema upgrade is pending.
			if ( $billing ) { $order_row['billing'] = wp_json_encode( $billing ); }
			if ( $customer_user_id ) { $order_row['customer_user_id'] = $customer_user_id; }
			// Fingerprint of the attempt (Orders::request_fingerprint()); a retry with the same key is compared against it.
			// Not while a deferred schema upgrade (DB 3.12.0) is still pending: the column may not exist yet.
			if ( ! empty( $internal['request_fingerprint'] ) && preg_match( '/^[a-f0-9]{64}$/', (string) $internal['request_fingerprint'] ) && version_compare( strtok( (string) get_option( 'wpcap_db_version' ), '+' ), '3.12.0', '>=' ) ) { $order_row['request_fingerprint'] = (string) $internal['request_fingerprint']; }
			$inserted = $wpdb->insert( $orders_table, $order_row );
			if ( ! $inserted ) {
				throw new \RuntimeException( 'order_insert_failed' );
			}
			$order_id = (int) $wpdb->insert_id;
			foreach ( $selected as $line ) {
				$item_row = array(
					'order_id' => $order_id,
					'ticket_type_id' => (int) $line['type']->ticket_type_id,
					'quantity' => (int) $line['quantity'],
					'unit_price_minor' => (int) $line['type']->price_minor,
					'line_total_minor' => (int) $line['line_total'],
					'tax_rate_bp' => (int) $line['tax_rate_bp'],
					'tax_minor' => (int) $line['tax_minor'],
				);
				// Line snapshot: applied tax profile version and price rule (written only when present).
				$line_snapshot = array_filter( array( 'tax' => $line['tax_profile']['snapshot'] ?? null, 'price' => $line['price_rule'] ) );
				if ( $line['tax_profile'] ) { $item_row['tax_profile_id'] = (int) $line['tax_profile']['tax_profile_id']; }
				if ( $line_snapshot ) { $item_row['line_snapshot'] = wp_json_encode( $line_snapshot ); }
				$wpdb->insert( $items_table, $item_row );
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
			// The server error is gone after ROLLBACK: classify it first (deadlock/lock wait timeout = busy).
			$db_error = (string) $wpdb->last_error;
			$wpdb->query( 'ROLLBACK' );
			Sales\BuyerLimits::release( $buyer_locks );
			Sales\CheckoutThrottle::refund( $throttle ); // No order: sold-out and other failed attempts do not use up the limit.
			$messages = array(
				'ticket_type_unavailable' => __( 'Diese Ticketart ist nicht verfügbar.', 'pass-captain' ),
				'quantity_limit' => __( 'Für diese Ticketart sind pro Bestellung nicht so viele Tickets möglich.', 'pass-captain' ),
				'db_busy' => __( 'Gerade ist sehr viel los. Bitte versuche es in einem Moment erneut.', 'pass-captain' ),
				'occurrence_unavailable' => __( 'Dieser Termin ist nicht mehr verfügbar.', 'pass-captain' ),
				'sales_closed' => __( 'Der Verkauf für diese Ticketart ist geschlossen.', 'pass-captain' ),
				'mixed_currency' => __( 'Tickets mit unterschiedlichen Währungen können nicht gemeinsam bestellt werden.', 'pass-captain' ),
				'sold_out' => __( 'Für diese Ticketart sind nicht mehr genügend Tickets verfügbar.', 'pass-captain' ),
				'occurrence_sold_out' => __( 'Für diesen Termin sind nicht mehr genügend Plätze verfügbar.', 'pass-captain' ),
				'campaign_invalid' => __( 'Ein Kampagnencode ist ungültig oder passt nicht zur Bestellung.', 'pass-captain' ),
				'campaign_redeem_failed' => __( 'Der Rabatt konnte nicht sicher auf die Bestellung angewendet werden.', 'pass-captain' ),
			);
			$code = $error->getMessage();
			if ( 'order_insert_failed' === $code ) {
				// A concurrent request with the same idempotency key won the unique index.
				$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE idempotency_key = %s', $orders_table, $key_hash ) );
				if ( $existing ) {
					return self::reused_result( $existing );
				}
			}
			if ( 'billing_required' === $code ) {
				return new \WP_Error( 'wpcap_billing_required', __( 'Für diesen Bestellbetrag brauchen wir für die Rechnung deinen Namen und deine Anschrift. Bitte aktiviere „Ich brauche eine Rechnung auf Firma/Adresse“ und fülle die Felder aus.', 'pass-captain' ), array( 'status' => 400, 'field' => 'billing_enabled' ) );
			}
			$messages = apply_filters( 'wpcap_checkout_error_messages', $messages );
			$fallback = $custom_error instanceof \WP_Error && '' !== $custom_error->get_error_message() ? $custom_error->get_error_message() : __( 'Die Bestellung konnte nicht angelegt werden.', 'pass-captain' );
			if ( 'db_busy' === $code || DbSession::is_busy_error( $db_error ) ) {
				// Deadlock victim or lock wait timeout: nothing was written; create() retries, then the client.
				return new \WP_Error( 'wpcap_checkout_failed', $messages['db_busy'] ?? __( 'Gerade ist sehr viel los. Bitte versuche es in einem Moment erneut.', 'pass-captain' ), array( 'status' => 503, 'retryable' => true, 'retry_after' => self::RETRY_AFTER_SECONDS, 'db_busy' => true ) );
			}
			$message = $messages[ $code ] ?? $fallback;
			return new \WP_Error( 'wpcap_checkout_failed', $message, array( 'status' => 409, 'retryable' => false ) );
		}
		Sales\BuyerLimits::release( $buyer_locks );

		$pending = $total || $external;
		if ( $throttle ) { Sales\CheckoutThrottle::record( $throttle, $pending ); }
		if ( ! $pending ) {
			Campaigns::commit_for_order( $order_id );
			self::issue_and_email( $order_id );
			do_action( 'wpcap_order_paid', $order_id );
		}
		if ( ! $external ) {
			self::send_order_access_link( $email, $public_id, $access_token );
		}
		Audit::record( 'order_created', 'order', $order_id, array( 'total_minor' => $total, 'currency' => $currency, 'source' => sanitize_key( $internal['source'] ?? 'native' ) ) );
		return array( 'order_id' => $order_id, 'public_id' => $public_id, 'status' => $pending ? 'pending_payment' : 'complete', 'payment_status' => $pending ? 'pending' : 'paid', 'subtotal_minor' => $subtotal, 'discount_minor' => $discount, 'total_minor' => $total, 'currency' => $currency, 'hold_minutes' => $pending ? $hold_minutes : 0 );
	}

	/** Result of create() for a request whose idempotency key already has an order. */
	private static function reused_result( $existing ) {
		return array(
			'order_id' => (int) $existing->order_id,
			'public_id' => $existing->public_id,
			'status' => $existing->status,
			'payment_status' => $existing->payment_status,
			'payment_provider' => (string) $existing->payment_provider,
			'request_fingerprint' => (string) ( $existing->request_fingerprint ?? '' ),
			'reused' => true,
		);
	}

	/**
	 * Fingerprint of a public checkout attempt: payment provider, normalized items (ticket type =>
	 * summed quantity), e-mail (lowercase), discount codes (uppercase, sorted) and voucher code. A retry
	 * with the same idempotency key must carry the same fingerprint (Rest\Controller::checkout()).
	 *
	 * @param array  $payload  Checkout payload (email, items, campaign_codes, voucher_code).
	 * @param string $provider Payment provider id.
	 * @return string sha256 hex.
	 */
	public static function request_fingerprint( array $payload, $provider ) {
		$items = array();
		foreach ( is_array( $payload['items'] ?? null ) ? $payload['items'] : array() as $line ) {
			$type_id = is_array( $line ) ? self::positive_int( $line['ticket_type_id'] ?? null ) : 0;
			$quantity = is_array( $line ) ? self::positive_int( $line['quantity'] ?? null ) : 0;
			if ( $type_id && $quantity ) { $items[ $type_id ] = ( $items[ $type_id ] ?? 0 ) + $quantity; }
		}
		ksort( $items, SORT_NUMERIC );
		$codes = array();
		foreach ( is_array( $payload['campaign_codes'] ?? null ) ? $payload['campaign_codes'] : array() as $code ) {
			$code = is_scalar( $code ) ? strtoupper( trim( sanitize_text_field( (string) $code ) ) ) : '';
			if ( '' !== $code ) { $codes[] = $code; }
		}
		$codes = array_values( array_unique( $codes ) );
		sort( $codes, SORT_STRING );
		$email = is_scalar( $payload['email'] ?? '' ) ? strtolower( sanitize_email( (string) ( $payload['email'] ?? '' ) ) ) : '';
		$voucher = is_scalar( $payload['voucher_code'] ?? '' ) ? strtoupper( trim( sanitize_text_field( (string) ( $payload['voucher_code'] ?? '' ) ) ) ) : '';
		return hash( 'sha256', (string) wp_json_encode( array( 'v' => 1, 'provider' => sanitize_key( (string) $provider ), 'items' => $items, 'email' => $email, 'codes' => $codes, 'voucher' => $voucher ) ) );
	}

	/**
	 * Splits $total over $amounts proportionally, exact to the minor unit: cumulative rounding, so the
	 * parts always add up to min($total, sum) and no part exceeds its amount (also for tiny lines).
	 *
	 * @param int[] $amounts Keyed amounts (minor units, >= 0).
	 * @return int[] Parts with the same keys.
	 */
	public static function allocate( array $amounts, $total ) {
		$sum = array_sum( array_map( 'intval', $amounts ) );
		$total = max( 0, min( (int) $total, $sum ) );
		$parts = array_fill_keys( array_keys( $amounts ), 0 );
		if ( ! $sum || ! $total ) { return $parts; }
		$running = 0;
		$allocated = 0;
		foreach ( $amounts as $key => $amount ) {
			$running += (int) $amount;
			$target = intdiv( $total * $running, $sum );
			$parts[ $key ] = $target - $allocated;
			$allocated = $target;
		}
		return $parts;
	}

	/**
	 * Per-line discount of an order (same index as $selected): the sum of the per-line parts
	 * Campaigns::calculate() split every campaign into (the split its amounts were computed with).
	 */
	private static function line_discounts( array $selected, array $adjustments ) {
		$discounts = array_fill_keys( array_keys( $selected ), 0 );
		foreach ( $adjustments as $adjustment ) {
			foreach ( (array) ( $adjustment['lines'] ?? array() ) as $index => $part ) {
				if ( isset( $discounts[ $index ] ) ) { $discounts[ $index ] += (int) $part; }
			}
		}
		return $discounts;
	}

	/** Per-ticket-type discounts stored with the order (null for orders before they were recorded). */
	public static function snapshot_line_discounts( $order ) {
		$snapshot = json_decode( (string) ( $order->price_snapshot ?? '' ), true );
		return is_array( $snapshot ) && isset( $snapshot['line_discounts'] ) && is_array( $snapshot['line_discounts'] ) ? array_map( 'intval', $snapshot['line_discounts'] ) : null;
	}

	/**
	 * Stores attendee name/e-mail and custom field answers for one ticket.
	 *
	 * @return bool False when a write failed (the caller rolls the issuance back).
	 */
	private static function store_attendee( $ticket_id, $order, array $attendee ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = current_time( 'mysql', true );
		$name = sanitize_text_field( $attendee['name'] ?? '' );
		$email = sanitize_email( $attendee['email'] ?? '' );
		if ( $name || is_email( $email ) ) {
			if ( false === $wpdb->query( $wpdb->prepare( "INSERT INTO %i (ticket_id,buyer_email,attendee_email,attendee_name,status,created_at) VALUES (%d,%s,%s,%s,'assigned',%s) ON DUPLICATE KEY UPDATE attendee_email=VALUES(attendee_email),attendee_name=VALUES(attendee_name)", $p . 'wpcap_ticket_assignments', $ticket_id, $order->customer_email, is_email( $email ) ? $email : '', $name, $now ) ) ) {
				return false;
			}
			if ( is_email( $email ) && false === $wpdb->update( "{$p}wpcap_tickets", array( 'attendee_email' => $email ), array( 'ticket_id' => $ticket_id ) ) ) {
				return false;
			}
		}
		foreach ( (array) ( $attendee['answers'] ?? array() ) as $field_id => $value ) {
			if ( false === $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (ticket_id,field_id,answer,created_at,updated_at) VALUES (%d,%d,%s,%s,%s) ON DUPLICATE KEY UPDATE answer=VALUES(answer),updated_at=VALUES(updated_at)', $p . 'wpcap_participant_answers', $ticket_id, absint( $field_id ), (string) $value, $now, $now ) ) ) {
				return false;
			}
		}
		return true;
	}

	/** Tickets per bulk revoke statement (IN list size). */
	const REVOKE_CHUNK = 500;

	/**
	 * Revokes the given tickets if still valid and returns the IDs actually changed.
	 * Callers fire wpcap_ticket_revoked via fire_revoked() after their transaction commits.
	 *
	 * One UPDATE per chunk of REVOKE_CHUNK tickets (not one per ticket): the still valid IDs are read,
	 * then revoked with the same condition. If the row count differs (a concurrent request changed
	 * some of them in between), the changed set is re-read so no ID is reported that stayed valid.
	 */
	public static function revoke_ids( array $ticket_ids ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcap_tickets';
		$changed = array();
		foreach ( array_chunk( array_values( array_filter( array_unique( array_map( 'absint', $ticket_ids ) ) ) ), self::REVOKE_CHUNK ) as $chunk ) {
			$in = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- generated placeholders.
			$valid = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM %i WHERE ticket_id IN ($in) AND status='valid' ORDER BY ticket_id", array_merge( array( $table ), $chunk ) ) ) );
			if ( ! $valid ) { continue; }
			$in = implode( ',', array_fill( 0, count( $valid ), '%d' ) );
			$result = $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='revoked' WHERE ticket_id IN ($in) AND status='valid'", array_merge( array( $table ), $valid ) ) );
			if ( false === $result || ! $result ) { continue; }
			if ( (int) $result !== count( $valid ) ) {
				// Race: keep only IDs that are revoked now (a few may have been revoked by the other request).
				$valid = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM %i WHERE ticket_id IN ($in) AND status='revoked' ORDER BY ticket_id", array_merge( array( $table ), $valid ) ) ) );
			}
			// phpcs:enable
			$changed = array_merge( $changed, $valid );
		}
		return $changed;
	}

	/**
	 * Like revoke_ids(), but reports database errors: returns the changed IDs, or false as soon as
	 * an UPDATE fails (the caller rolls its transaction back). Callers lock the rows FOR UPDATE first.
	 *
	 * @return int[]|false
	 */
	private static function revoke_ids_checked( array $ticket_ids ) {
		global $wpdb;
		$changed = array();
		foreach ( array_unique( array_map( 'absint', $ticket_ids ) ) as $id ) {
			if ( ! $id ) { continue; }
			$result = $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='revoked' WHERE ticket_id=%d AND status='valid'", $wpdb->prefix . 'wpcap_tickets', $id ) );
			if ( false === $result ) { return false; }
			if ( $result ) { $changed[] = $id; }
		}
		return $changed;
	}

	/**
	 * Rolls back a state change that hit a database error (deadlock, lock wait timeout, lost
	 * connection) and returns a retryable error; webhooks answer it with a 5xx so the provider retries.
	 */
	private static function state_busy( $order_id, $step ) {
		global $wpdb;
		$error = (string) $wpdb->last_error;
		$wpdb->query( 'ROLLBACK' );
		Audit::record( 'order_state_db_error', 'order', absint( $order_id ), array( 'step' => sanitize_key( $step ), 'error' => substr( $error, 0, 200 ) ) );
		return new \WP_Error( 'wpcap_db_busy', __( 'Die Bestellung konnte gerade nicht aktualisiert werden. Bitte erneut versuchen.', 'pass-captain' ), array( 'status' => 503, 'retryable' => true, 'step' => (string) $step ) );
	}

	/**
	 * Client IP used for the anonymous checkout rate limit. Defaults to REMOTE_ADDR; behind a proxy
	 * configure the setting "trusted_proxies" (Einstellungen → Sicherheit). The filter below still works.
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
		// Trusted proxies are configured centrally (setting trusted_proxies, see RateLimit::client_ip()).
		return RateLimit::client_ip();
	}

	/** 400 error for an e-mail address longer than the database columns allow (EMAIL_MAX_LENGTH). */
	private static function email_too_long( $field ) {
		return new \WP_Error(
			'wpcap_email_too_long',
			/* translators: %d: maximum number of characters */
			sprintf( __( 'Die E-Mail-Adresse ist zu lang (höchstens %d Zeichen).', 'pass-captain' ), self::EMAIL_MAX_LENGTH ),
			array( 'status' => 400, 'field' => $field )
		);
	}

	/** Positive integer from an int or a digit-only string; 0 for anything else (negative, float, array). */
	public static function positive_int( $value ) {
		if ( is_int( $value ) ) { return $value > 0 ? $value : 0; }
		if ( is_string( $value ) && preg_match( '/^\s*[0-9]{1,9}\s*$/', $value ) ) { return (int) $value; }
		return 0;
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
	 * @param bool $allow_missing Tickets without any attendee entry are accepted even with required fields
	 *                            (trusted adapters only; entries that are given are still validated).
	 * @return array|\WP_Error Normalised list [{ticket_type_id, name, email, answers{field_id:value}}].
	 */
	public static function validate_attendees( array $lines, $attendees, $allow_missing = false ) {
		global $wpdb;
		$attendees = is_array( $attendees ) ? array_values( $attendees ) : array();
		$out = array();
		foreach ( $lines as $type_id => $quantity ) {
			$fields = $wpdb->get_results( $wpdb->prepare( "SELECT field_id,field_key,label,field_type,required FROM %i WHERE ticket_type_id=%d AND status='active' ORDER BY position,field_id", $wpdb->prefix . 'wpcap_participant_fields', $type_id ) );
			$given = array_values( array_filter( $attendees, static function ( $a ) use ( $type_id ) { return is_array( $a ) && absint( $a['ticket_type_id'] ?? 0 ) === (int) $type_id; } ) );
			if ( count( $given ) > $quantity ) {
				return new \WP_Error( 'wpcap_attendees_invalid', __( 'Es wurden mehr Teilnehmende angegeben als Tickets bestellt.', 'pass-captain' ), array( 'status' => 400 ) );
			}
			$needs_all = (bool) array_filter( $fields, static function ( $f ) { return (int) $f->required; } );
			for ( $i = 0; $i < $quantity; $i++ ) {
				$entry = $given[ $i ] ?? null;
				if ( ! $entry && ( ! $needs_all || $allow_missing ) ) { continue; }
				$entry = is_array( $entry ) ? $entry : array();
				$answers = is_array( $entry['answers'] ?? null ) ? $entry['answers'] : array();
				$clean = array();
				foreach ( $fields as $field ) {
					$value = $answers[ $field->field_key ] ?? '';
					$value = is_scalar( $value ) ? trim( (string) $value ) : '';
					if ( 'email' === $field->field_type ) { $value = sanitize_email( $value ); }
					elseif ( 'textarea' === $field->field_type ) { $value = sanitize_textarea_field( $value ); }
					elseif ( 'checkbox' === $field->field_type || 'consent' === $field->field_type ) { $value = $value && '0' !== $value ? '1' : ''; }
					else { $value = sanitize_text_field( $value ); }
					if ( (int) $field->required && '' === $value ) {
						/* translators: %s: field label */
						return new \WP_Error( 'wpcap_attendee_field_required', sprintf( __( 'Bitte „%s“ für jedes Ticket ausfüllen.', 'pass-captain' ), $field->label ), array( 'status' => 400 ) );
					}
					if ( 'email' === $field->field_type && '' !== $value && ! is_email( $value ) ) {
						/* translators: %s: field label */
						return new \WP_Error( 'wpcap_attendee_field_invalid', sprintf( __( '„%s“ ist keine gültige E-Mail-Adresse.', 'pass-captain' ), $field->label ), array( 'status' => 400 ) );
					}
					if ( '' !== $value ) { $clean[ (int) $field->field_id ] = mb_substr( $value, 0, 2000 ); }
				}
				$email = sanitize_email( $entry['email'] ?? '' );
				// tickets.attendee_email / ticket_assignments.attendee_email are varchar(190); refused here,
				// because a failing write at ticket issuance would roll back an already paid order.
				if ( strlen( $email ) > self::EMAIL_MAX_LENGTH ) {
					return self::email_too_long( 'attendees.email' );
				}
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
	 * Returns an error code if $quantity more units of $type would exceed type or occurrence capacity.
	 * Callers must hold row locks on the ticket type and occurrence. Capacity 0 means unlimited.
	 */
	/** Rolls back a payment confirmation that hit a deadlock/lock timeout; callers retry (webhooks get a 5xx). */
	private static function payment_busy() {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' );
		return new \WP_Error( 'wpcap_db_busy', __( 'Die Zahlung konnte gerade nicht verbucht werden. Bitte erneut versuchen.', 'pass-captain' ), array( 'status' => 503, 'retryable' => true, 'retry_after' => self::RETRY_AFTER_SECONDS ) );
	}

	/**
	 * Whether the connection is still inside a transaction (MariaDB @@in_transaction). False after
	 * the server rolled the transaction back (deadlock, lock wait timeout); null if unknown (MySQL).
	 *
	 * MySQL has no such variable: querying it there only writes "Unknown system variable" to the
	 * error log on every order, so the server type is checked once per request instead.
	 */
	/** @var bool|null Whether the server reports @@in_transaction (MariaDB); null = not asked yet. */
	private static $tracks_transactions = null;

	/** @var bool|null Whether the transaction marker table is usable on this connection; null = not set up. */
	private static $marker_ready = null;

	/** @var bool Whether the current transaction wrote its marker row (MySQL path). */
	private static $marker_active = false;

	/**
	 * Starts a transaction. On servers without @@in_transaction (MySQL) a marker row is written into a
	 * connection-local InnoDB temporary table inside the transaction: a server-side rollback (deadlock
	 * victim) removes it, so in_transaction() can tell that the transaction is gone.
	 *
	 * Every transaction runs under REPEATABLE READ, whatever the session default is (DbSession): the
	 * capacity checks rely on one consistent snapshot taken after the row locks. Under READ COMMITTED a
	 * payment committing between two reads would be missing from both sums (overselling).
	 *
	 * @return int|bool Result of START TRANSACTION (false on error).
	 */
	private static function begin() {
		global $wpdb;
		self::$marker_active = false;
		$marker = self::uses_marker() ? self::marker_table() : '';
		if ( '' !== $marker ) {
			// Outside the transaction (autocommit): clear the marker of the previous transaction. If that fails
			// (lost connection, temporary table gone), the table is set up again before the next transaction.
			$cleared = $wpdb->query( "DELETE FROM {$marker}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix, no input.
			if ( false === $cleared ) { self::$marker_ready = null; $marker = ''; }
		}
		// Right before START TRANSACTION: SET TRANSACTION applies to the next transaction only (an
		// autocommit statement in between, like the marker DELETE above, would consume it).
		DbSession::repeatable_read();
		$started = $wpdb->query( 'START TRANSACTION' );
		if ( false !== $started && '' !== $marker ) {
			// Only this transaction depends on the marker: a failed write leaves in_transaction() undecided (null).
			self::$marker_active = false !== $wpdb->query( "INSERT INTO {$marker} (id) VALUES (1)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix, no input.
		}
		return $started;
	}

	/** True when the transaction state must be tracked with the marker table instead of @@in_transaction. */
	private static function uses_marker() {
		global $wpdb;
		if ( null === self::$tracks_transactions ) {
			$tracks = self::server_tracks_transactions( (string) $wpdb->get_var( 'SELECT VERSION()' ) );
			/** Test seam: force the marker path (false) or the @@in_transaction path (true). */
			self::$tracks_transactions = (bool) apply_filters( 'wpcap_db_tracks_in_transaction', $tracks );
		}
		return ! self::$tracks_transactions;
	}

	/** Name of the connection-local marker table ('' when it can't be created, e.g. no CREATE TEMPORARY TABLES privilege). */
	private static function marker_table() {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcap_trx_marker';
		if ( null === self::$marker_ready ) {
			// CREATE TEMPORARY TABLE does not commit implicitly; it is visible only to this connection.
			$created = false !== $wpdb->query( $wpdb->prepare( 'CREATE TEMPORARY TABLE IF NOT EXISTS %i (id tinyint unsigned NOT NULL) ENGINE=InnoDB', $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- connection-local temporary marker table, name from $wpdb->prefix.
			if ( $created ) {
				self::$marker_ready = true;
			} elseif ( false !== stripos( (string) $wpdb->last_error, 'denied' ) ) {
				// No CREATE TEMPORARY TABLES privilege: stop trying for this request. Transient errors (lost
				// connection, lock timeout) are retried with the next transaction.
				self::$marker_ready = false;
			}
		}
		return self::$marker_ready ? $table : '';
	}

	/**
	 * Whether the transaction started with begin() is still open: true/false, or null when the server
	 * can't tell (then callers rely on the checked statements and COMMIT alone).
	 */
	private static function in_transaction() {
		global $wpdb;
		if ( self::uses_marker() ) {
			$marker = ( self::$marker_ready && self::$marker_active ) ? $wpdb->prefix . 'wpcap_trx_marker' : '';
			if ( '' === $marker ) { return null; }
			$value = $wpdb->get_var( "SELECT COUNT(*) FROM {$marker}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix, no input.
			if ( '' !== $wpdb->last_error || null === $value ) { return null; }
			return (int) $value > 0;
		}
		$value = $wpdb->get_var( 'SELECT @@in_transaction' );
		if ( '' !== $wpdb->last_error || null === $value ) { return null; }
		return 1 === (int) $value;
	}

	/** Resets the cached server detection (tests). */
	public static function reset_transaction_tracking() {
		self::$tracks_transactions = null;
		self::$marker_ready = null;
		self::$marker_active = false;
	}

	/** MariaDB reports its transaction state in @@in_transaction; MySQL does not. */
	private static function server_tracks_transactions( $version ) {
		return false !== stripos( (string) $version, 'mariadb' );
	}

	private static function capacity_error( $type, $occurrence, $quantity, $exclude_order_id = 0 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = current_time( 'mysql', true );
		$exclude_order_id = absint( $exclude_order_id );
		// Sold and held units in ONE statement each: two statements could straddle a payment that moves
		// units from held to sold (under READ COMMITTED they would be missing from both sums).
		if ( (int) $type->capacity > 0 ) {
			$used = $wpdb->get_var( $wpdb->prepare(
				"SELECT (SELECT COALESCE(SUM(oi.quantity - oi.refunded_quantity),0) FROM %i oi INNER JOIN %i ord ON ord.order_id=oi.order_id WHERE oi.ticket_type_id=%d AND ord.payment_status='paid' AND ord.status <> 'refunded' AND ord.order_id <> %d) + (SELECT COALESCE(SUM(quantity),0) FROM %i WHERE ticket_type_id=%d AND status='active' AND expires_at > %s AND order_id <> %d)",
				$p . 'wpcap_order_items', $p . 'wpcap_orders', $type->ticket_type_id, $exclude_order_id,
				$p . 'wpcap_reservations', $type->ticket_type_id, $now, $exclude_order_id
			) );
			if ( '' !== $wpdb->last_error || null === $used ) { return 'db_busy'; }
			if ( (int) $used + $quantity > (int) $type->capacity ) {
				return 'sold_out';
			}
		}
		if ( (int) $occurrence->capacity > 0 ) {
			$occ_used = $wpdb->get_var( $wpdb->prepare(
				"SELECT (SELECT COALESCE(SUM(oi.quantity - oi.refunded_quantity),0) FROM %i oi INNER JOIN %i tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN %i ord ON ord.order_id=oi.order_id WHERE tt.occurrence_id=%d AND ord.payment_status='paid' AND ord.status <> 'refunded' AND ord.order_id <> %d) + (SELECT COALESCE(SUM(r.quantity),0) FROM %i r INNER JOIN %i tt ON tt.ticket_type_id=r.ticket_type_id WHERE tt.occurrence_id=%d AND r.status='active' AND r.expires_at > %s AND r.order_id <> %d)",
				$p . 'wpcap_order_items', $p . 'wpcap_ticket_types', $p . 'wpcap_orders', $occurrence->occurrence_id, $exclude_order_id,
				$p . 'wpcap_reservations', $p . 'wpcap_ticket_types', $occurrence->occurrence_id, $now, $exclude_order_id
			) );
			if ( '' !== $wpdb->last_error || null === $occ_used ) { return 'db_busy'; }
			if ( (int) $occ_used + $quantity > (int) $occurrence->capacity ) {
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
			$type = DisplayBatch::type( $type ) ?? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE ticket_type_id=%d', $wpdb->prefix . 'wpcap_ticket_types', absint( $type ) ) );
			if ( ! $type ) { return 0; }
		}
		if ( ! $occurrence && isset( $type->occurrence_id ) ) {
			$occurrence = self::occurrence_row( $type->occurrence_id );
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
		if ( ! $occurrence && isset( $type->occurrence_id ) ) {
			$occurrence = self::occurrence_row( $type->occurrence_id );
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
	 * Fires `wpcap_capacity_released` after inventory of an order stopped counting (hold expired,
	 * order cancelled/refunded, tickets revoked, payment unallocated). Core capacity and pools are
	 * derived from orders and holds, so this is informational for modules with own counters/caches.
	 */
	private static function capacity_released( $order_id, $reason, array $details = array() ) {
		do_action( 'wpcap_capacity_released', (int) $order_id, (string) $reason, $details );
	}

	/**
	 * Remaining sellable units for a ticket type (non-locking, for display/cart validation).
	 *
	 * @return int|null Null when neither the type nor the occurrence is limited.
	 */
	public static function availability( $ticket_type_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$batched = DisplayBatch::type( $ticket_type_id );
		if ( $batched ) {
			// Display batch (event page, GET /events): same values from grouped queries.
			$occurrence = DisplayBatch::occurrence( $batched->occurrence_id );
			if ( ! $occurrence ) { return 0; }
			$usage = DisplayBatch::usage();
			$limits = array();
			if ( (int) $batched->capacity > 0 ) {
				$id = (int) $batched->ticket_type_id;
				$limits[] = (int) $batched->capacity - ( ( $usage['type_sold'][ $id ] ?? 0 ) + ( $usage['type_held'][ $id ] ?? 0 ) );
			}
			if ( (int) $occurrence->capacity > 0 ) {
				$id = (int) $occurrence->occurrence_id;
				$limits[] = (int) $occurrence->capacity - ( ( $usage['occ_sold'][ $id ] ?? 0 ) + ( $usage['occ_held'][ $id ] ?? 0 ) );
			}
			return self::filtered_availability( $limits ? max( 0, min( $limits ) ) : null, $batched, $occurrence );
		}
		$type = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE ticket_type_id=%d', $p . 'wpcap_ticket_types', absint( $ticket_type_id ) ) );
		if ( ! $type ) { return 0; }
		$occurrence = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE occurrence_id=%d', $p . 'wpcap_occurrences', $type->occurrence_id ) );
		if ( ! $occurrence ) { return 0; }
		$limits = array();
		$now = current_time( 'mysql', true );
		if ( (int) $type->capacity > 0 ) {
			// One statement (one snapshot) for sold + held, like capacity_error().
			$used = (int) $wpdb->get_var( $wpdb->prepare( "SELECT (SELECT COALESCE(SUM(oi.quantity - oi.refunded_quantity),0) FROM %i oi INNER JOIN %i ord ON ord.order_id=oi.order_id WHERE oi.ticket_type_id=%d AND ord.payment_status='paid' AND ord.status <> 'refunded') + (SELECT COALESCE(SUM(quantity),0) FROM %i WHERE ticket_type_id=%d AND status='active' AND expires_at > %s)", $p . 'wpcap_order_items', $p . 'wpcap_orders', $type->ticket_type_id, $p . 'wpcap_reservations', $type->ticket_type_id, $now ) );
			$limits[] = (int) $type->capacity - $used;
		}
		if ( (int) $occurrence->capacity > 0 ) {
			$used = (int) $wpdb->get_var( $wpdb->prepare( "SELECT (SELECT COALESCE(SUM(oi.quantity - oi.refunded_quantity),0) FROM %i oi INNER JOIN %i tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN %i ord ON ord.order_id=oi.order_id WHERE tt.occurrence_id=%d AND ord.payment_status='paid' AND ord.status <> 'refunded') + (SELECT COALESCE(SUM(r.quantity),0) FROM %i r INNER JOIN %i tt ON tt.ticket_type_id=r.ticket_type_id WHERE tt.occurrence_id=%d AND r.status='active' AND r.expires_at > %s)", $p . 'wpcap_order_items', $p . 'wpcap_ticket_types', $p . 'wpcap_orders', $occurrence->occurrence_id, $p . 'wpcap_reservations', $p . 'wpcap_ticket_types', $occurrence->occurrence_id, $now ) );
			$limits[] = (int) $occurrence->capacity - $used;
		}
		return self::filtered_availability( $limits ? max( 0, min( $limits ) ) : null, $type, $occurrence );
	}

	/** Applies `wpcap_ticket_availability` to the core remaining units (never more than core). */
	private static function filtered_availability( $remaining, $type, $occurrence ) {
		/**
		 * Remaining units after module limits (e.g. series quota pools). Return null for unlimited
		 * or a smaller non-negative int; never more than the core value.
		 */
		$filtered = apply_filters( 'wpcap_ticket_availability', $remaining, $type, $occurrence );
		if ( null === $filtered || ! is_numeric( $filtered ) ) { return $remaining; }
		$filtered = max( 0, (int) $filtered );
		return null === $remaining ? $filtered : min( $remaining, $filtered );
	}

	/** Occurrence row (SELECT *), from the display batch when preloaded. */
	private static function occurrence_row( $occurrence_id ) {
		global $wpdb;
		return DisplayBatch::occurrence( $occurrence_id ) ?? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE occurrence_id=%d', $wpdb->prefix . 'wpcap_occurrences', $occurrence_id ) );
	}

	/**
	 * Confirms a payment reported by an external system (e.g. WooCommerce). Idempotent.
	 *
	 * Active holds are converted. If the hold has lapsed, capacity is re-checked under row locks
	 * (late payment recovery). If it is no longer available, the order is flagged `needs_review`
	 * with payment status `paid_unallocated` – no tickets are issued and nothing is overbooked.
	 *
	 * Buyer limits (Sales\BuyerLimits) are re-checked for such a late payment as well: the lapsed hold
	 * no longer counted against the buyer, who may have bought up to the limit in the meantime. If the
	 * payment would push the buyer past a limit, the order goes the same `needs_review` way (admin
	 * alert; the operator refunds it or raises the limit and confirms again). The buyer locks are taken
	 * before the transaction, in the same order as Orders::create().
	 *
	 * @return true|\WP_Error
	 */
	public static function confirm_external_payment( $order_id, $reference = '' ) {
		return DbSession::with_short_lock_wait( static function () use ( $order_id, $reference ) {
			$order_id = absint( $order_id );
			$buyer = self::late_payment_buyer( $order_id );
			$locks = array();
			if ( $buyer ) {
				$locks = Sales\BuyerLimits::acquire( $buyer->customer_email, (int) $buyer->customer_user_id );
				if ( false === $locks ) { return self::payment_busy(); }
			}
			try {
				return self::confirm_payment_locked( $order_id, $reference, (bool) $buyer );
			} finally {
				if ( $locks ) { Sales\BuyerLimits::release( $locks ); }
			}
		} );
	}

	/**
	 * Order row (e-mail, customer id) when a payment confirmation may need the buyer-limit re-check:
	 * not paid yet, not closed, no door sale, and an active buyer limit covers one of its ticket types.
	 * Unlocked pre-read; the decision is repeated under the row lock.
	 */
	private static function late_payment_buyer( $order_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order = $wpdb->get_row( $wpdb->prepare( 'SELECT order_id,customer_email,customer_user_id,status,payment_status,payment_provider FROM %i WHERE order_id=%d', $p . 'wpcap_orders', $order_id ) );
		if ( ! $order || 'paid' === $order->payment_status || in_array( $order->status, array( 'refunded', 'cancelled' ), true ) || in_array( (string) $order->payment_provider, array( 'pos', 'boxoffice' ), true ) ) { return null; }
		$types = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT ticket_type_id FROM %i WHERE order_id=%d', $p . 'wpcap_order_items', $order_id ) ) );
		return $types && Sales\BuyerLimits::relevant( $types ) ? $order : null;
	}

	/**
	 * confirm_external_payment() with the buyer locks held (when $check_limits).
	 *
	 * Lock order (as in Orders::create(), so payments, checkouts and the hold cleanup cannot deadlock
	 * each other): the order lines and reservation ids are read before the transaction (they never
	 * change after the order was created). Then the order row; a late payment then locks ticket types,
	 * occurrences, module resources (`wpcap_reserve_capacity`: pools, seats) and finally the order's
	 * reservations, an on-time payment only its reservations. Reservations are
	 * locked by primary key FOR UPDATE (record locks only: no gap locks on the order_id index, no
	 * shared lock that is upgraded later). If an on-time confirmation finds that the hold lapsed
	 * meanwhile, it starts over as a late payment.
	 */
	private static function confirm_payment_locked( $order_id, $reference, $check_limits ) {
		global $wpdb;
		$p = $wpdb->prefix;
		// Autocommit reads (no snapshot yet): the late re-check must take its snapshot after its locks.
		$lines = $wpdb->get_results( $wpdb->prepare( 'SELECT ticket_type_id, SUM(quantity) AS quantity FROM %i WHERE order_id=%d GROUP BY ticket_type_id ORDER BY ticket_type_id', $p . 'wpcap_order_items', $order_id ) );
		if ( '' !== $wpdb->last_error ) { return self::payment_busy(); }
		$hold_ids = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT reservation_id FROM %i WHERE order_id=%d ORDER BY reservation_id', $p . 'wpcap_reservations', $order_id ) ) );
		if ( '' !== $wpdb->last_error ) { return self::payment_busy(); }
		$late = ! self::has_active_hold( $order_id );
		$result = self::confirm_payment_transaction( $order_id, $reference, $check_limits, (array) $lines, $hold_ids, $late );
		if ( 'hold_lapsed' === $result ) {
			// The hold expired between the pre-read and the lock: take the type/occurrence locks first.
			$result = self::confirm_payment_transaction( $order_id, $reference, $check_limits, (array) $lines, $hold_ids, true );
		}
		return 'hold_lapsed' === $result ? self::payment_busy() : $result;
	}

	/** Whether the order still has an unexpired active hold (non-locking read). */
	public static function has_active_hold( $order_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE order_id=%d AND status='active' AND expires_at > %s", $wpdb->prefix . 'wpcap_reservations', absint( $order_id ), current_time( 'mysql', true ) ) ) > 0;
	}

	/**
	 * Locks reservations by primary key (FOR UPDATE): record locks only.
	 *
	 * @return array|false Reservation rows (reservation_id, status, expires_at) or false on a database error.
	 */
	private static function lock_holds( array $hold_ids ) {
		global $wpdb;
		$hold_ids = array_values( array_filter( array_map( 'absint', $hold_ids ) ) );
		if ( ! $hold_ids ) { return array(); }
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT reservation_id,status,expires_at FROM %i WHERE reservation_id IN (' . implode( ',', array_fill( 0, count( $hold_ids ), '%d' ) ) . ') ORDER BY reservation_id FOR UPDATE', array_merge( array( $wpdb->prefix . 'wpcap_reservations' ), $hold_ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer placeholders.
		return '' !== $wpdb->last_error ? false : (array) $rows;
	}

	/** Sets the status of reservations (by primary key) that are in one of $from; false on a database error. */
	private static function set_hold_status( array $hold_ids, $status, array $from ) {
		global $wpdb;
		$hold_ids = array_values( array_filter( array_map( 'absint', $hold_ids ) ) );
		if ( ! $hold_ids ) { return 0; }
		return $wpdb->query( $wpdb->prepare( 'UPDATE %i SET status=%s WHERE reservation_id IN (' . implode( ',', array_fill( 0, count( $hold_ids ), '%d' ) ) . ') AND status IN (' . implode( ',', array_fill( 0, count( $from ), '%s' ) ) . ')', array_merge( array( $wpdb->prefix . 'wpcap_reservations', (string) $status ), $hold_ids, array_values( $from ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- generated placeholders.
	}

	/**
	 * Sales state a late payment needs (ticket type active, occurrence and event not cancelled, the
	 * occurrence not over). Without it the paid order goes to needs_review instead of issuing tickets
	 * for a cancelled or past occurrence or a deactivated ticket type.
	 */
	private static function sale_still_open( $type, $occurrence, $now ) {
		if ( ! $type || ! $occurrence || 'active' !== $type->status || 'scheduled' !== $occurrence->status || 'cancelled' === (string) ( $occurrence->event_status ?? '' ) ) { return false; }
		$ends = $occurrence->ends_at ? $occurrence->ends_at : gmdate( 'Y-m-d H:i:s', strtotime( $occurrence->starts_at . ' UTC' ) + 6 * HOUR_IN_SECONDS );
		return $ends > $now;
	}

	/**
	 * One confirmation transaction (see confirm_payment_locked()).
	 *
	 * @return true|\WP_Error|string 'hold_lapsed' when an on-time confirmation found no active hold any more.
	 */
	private static function confirm_payment_transaction( $order_id, $reference, $check_limits, array $lines, array $hold_ids, $late ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = current_time( 'mysql', true );
		// Without a transaction the status update below would autocommit and the in_transaction()
		// check before COMMIT would then roll back nothing: a paid order without tickets.
		if ( false === self::begin() ) { return self::payment_busy(); }
		$order = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d FOR UPDATE', $p . 'wpcap_orders', $order_id ) );
		if ( '' !== $wpdb->last_error ) { return self::payment_busy(); }
		if ( ! $order ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_order_missing', __( 'Bestellung nicht gefunden.', 'pass-captain' ) );
		}
		if ( 'paid' === $order->payment_status ) {
			$wpdb->query( 'COMMIT' );
			self::issue_and_email( $order_id );
			return true;
		}
		if ( in_array( $order->status, array( 'refunded', 'cancelled' ), true ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_order_state', __( 'Der Bestellstatus erlaubt diese Aktion nicht.', 'pass-captain' ) );
		}
		$recovered = false;
		if ( $late ) {
			// Late payment: lock ticket types, then occurrences, with the same statements and in the same
			// order as Orders::create(), then module resources (`wpcap_reserve_capacity`: pools, seats), then
			// the order's reservations. Locking reads only until every capacity lock is held (fresh snapshot
			// for the re-check below). A checkout holding these locks never locks existing order rows (seat
			// claims are released without shared locks, see Seating\Reservations::release_stale()).
			$locked_types = array();
			foreach ( $lines as $line ) {
				$locked_types[ (int) $line->ticket_type_id ] = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE ticket_type_id=%d FOR UPDATE', $p . 'wpcap_ticket_types', $line->ticket_type_id ) );
				if ( '' !== $wpdb->last_error ) { return self::payment_busy(); }
			}
			$occ_ids = array_unique( array_map( static function ( $type ) { return $type ? (int) $type->occurrence_id : 0; }, $locked_types ) );
			sort( $occ_ids, SORT_NUMERIC );
			$occurrences = array();
			foreach ( $occ_ids as $occ_id ) {
				if ( ! $occ_id ) { continue; }
				$occurrences[ $occ_id ] = $wpdb->get_row( $wpdb->prepare( 'SELECT o.*,e.name AS event_name,e.status AS event_status FROM %i o INNER JOIN %i e ON e.event_id=o.event_id WHERE o.occurrence_id=%d FOR UPDATE', $p . 'wpcap_occurrences', $p . 'wpcap_events', $occ_id ) );
				if ( '' !== $wpdb->last_error ) { return self::payment_busy(); }
			}
			$demand = array();
			foreach ( $lines as $line ) { $demand[ (int) $line->ticket_type_id ] = (int) $line->quantity; }
			$error = '';
			foreach ( $lines as $line ) {
				$type = $locked_types[ (int) $line->ticket_type_id ] ?? null;
				if ( ! $type || ! ( $occurrences[ (int) $type->occurrence_id ] ?? null ) ) { $error = 'ticket_type_unavailable'; break; }
				if ( ! self::sale_still_open( $type, $occurrences[ (int) $type->occurrence_id ], $now ) ) { $error = 'sale_closed'; break; }
			}
			// Module resources may write (e.g. seats claimed again): undone if the re-check fails below.
			if ( false === $wpdb->query( 'SAVEPOINT wpcap_recover' ) ) { return self::payment_busy(); }
			$reserve_error = $error ? null : self::reserve_capacity( $demand, array( 'phase' => 'recover', 'order_id' => $order_id, 'types' => $locked_types, 'occurrences' => array_filter( $occurrences ), 'now' => $now, 'source' => (string) $order->payment_provider ) );
			if ( $reserve_error && 'db_busy' === $reserve_error->get_error_code() ) { return self::payment_busy(); }
			if ( false === self::lock_holds( $hold_ids ) ) { return self::payment_busy(); }
			$per_occurrence = array();
			foreach ( $error ? array() : $lines as $line ) {
				$type = $locked_types[ (int) $line->ticket_type_id ];
				$occurrence = $occurrences[ (int) $type->occurrence_id ];
				$check_occurrence = clone $occurrence;
				$check_occurrence->capacity = 0; // Occurrence capacity is checked below for the summed quantity.
				$error = self::capacity_error( $type, $check_occurrence, (int) $line->quantity, $order_id );
				if ( $error ) { break; }
				$per_occurrence[ (int) $occurrence->occurrence_id ] = ( $per_occurrence[ (int) $occurrence->occurrence_id ] ?? 0 ) + (int) $line->quantity;
			}
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
			if ( ! $error && $check_limits ) {
				// The lapsed hold no longer counted against the buyer: re-check the limits (buyer locks held).
				$limit_error = Sales\BuyerLimits::check( $demand, $locked_types, array_filter( $occurrences ), (string) $order->customer_email, (int) $order->customer_user_id, $now, $order_id );
				if ( $limit_error ) { $error = 'buyer_limit'; }
			}
			if ( ! $error ) {
				// The lapsed hold also gave its discount codes back: take them again only below the usage limit (PAY-1).
				$retaken = Campaigns::retake_for_order( $order_id );
				if ( is_wp_error( $retaken ) ) {
					if ( 'wpcap_db_busy' === $retaken->get_error_code() ) { return self::payment_busy(); }
					$error = 'campaign_exhausted';
				}
			}
			if ( $error ) {
				return self::payment_unallocated( $order, $error, $reference, $hold_ids, $now );
			}
			$recovered = true;
		} else {
			$holds = self::lock_holds( $hold_ids );
			if ( false === $holds ) { return self::payment_busy(); }
			$active = array_filter( $holds, static function ( $hold ) use ( $now ) { return 'active' === $hold->status && $hold->expires_at > $now; } );
			if ( ! $active ) {
				$wpdb->query( 'ROLLBACK' );
				return 'hold_lapsed';
			}
		}
		// Checked like Orders::create(): after a server-side rollback the remaining statements would
		// autocommit without the capacity re-check's locks.
		if ( false === $wpdb->update( "{$p}wpcap_orders", array( 'status' => 'complete', 'payment_status' => 'paid', 'updated_at' => $now ), array( 'order_id' => $order_id ), array( '%s', '%s', '%s' ), array( '%d' ) ) ) { return self::payment_busy(); }
		if ( false === self::set_hold_status( $hold_ids, 'converted', array( 'active', 'expired', 'released' ) ) ) { return self::payment_busy(); }
		Campaigns::commit_for_order( $order_id );
		if ( false === self::in_transaction() || false === $wpdb->query( 'COMMIT' ) ) { return self::payment_busy(); }
		Audit::record( 'external_payment_confirmed', 'order', $order_id, array( 'reference' => sanitize_text_field( $reference ), 'late_recovery' => $recovered ) );
		do_action( 'wpcap_order_paid', $order_id );
		self::issue_and_email( $order_id );
		return true;
	}

	/**
	 * Late payment that cannot be allocated: undoes module claims of the recovery (savepoint), flags
	 * the order needs_review / paid_unallocated, commits and alerts the admin. needs_review is a final
	 * answer (webhooks acknowledge it), so it is persisted before anything is reported.
	 *
	 * @return \WP_Error wpcap_needs_review (or wpcap_db_busy when it could not be stored).
	 */
	private static function payment_unallocated( $order, $error, $reference, array $hold_ids, $now ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order_id = (int) $order->order_id;
		if ( false === $wpdb->query( 'ROLLBACK TO SAVEPOINT wpcap_recover' ) ) { return self::payment_busy(); }
		if ( false === $wpdb->update( "{$p}wpcap_orders", array( 'status' => 'needs_review', 'payment_status' => 'paid_unallocated', 'updated_at' => $now ), array( 'order_id' => $order_id ), array( '%s', '%s', '%s' ), array( '%d' ) ) ) { return self::payment_busy(); }
		if ( false === self::set_hold_status( $hold_ids, 'expired', array( 'active' ) ) ) { return self::payment_busy(); }
		if ( false === self::in_transaction() || false === $wpdb->query( 'COMMIT' ) ) { return self::payment_busy(); }
		self::capacity_released( $order_id, 'payment_unallocated' );
		Audit::record( 'payment_unallocated', 'order', $order_id, array( 'reason' => $error, 'reference' => sanitize_text_field( $reference ) ) );
		$reference = sanitize_text_field( $reference );
		if ( 'buyer_limit' === $error ) {
			wp_mail( get_option( 'admin_email' ), __( 'Pass Captain: Bezahlte Bestellung über dem Käuferlimit', 'pass-captain' ), sprintf( /* translators: 1: order number, 2: payment reference */ __( 'Die Zahlung für Bestellung %1$s (%2$s) ist nach Ablauf der Reservierung eingegangen. Mit dieser Bestellung würde die Person ein Käuferlimit überschreiten. Es wurden keine Tickets ausgestellt. Bitte manuell klären (erstatten oder Limit anpassen und die Zahlung erneut bestätigen).', 'pass-captain' ), $order->public_id, $reference ) );
			return new \WP_Error( 'wpcap_needs_review', __( 'Die Reservierung war abgelaufen und die Bestellung würde ein Käuferlimit überschreiten. Die Bestellung muss manuell geklärt werden.', 'pass-captain' ), array( 'reason' => 'buyer_limit' ) );
		}
		if ( 'campaign_exhausted' === $error ) {
			wp_mail( get_option( 'admin_email' ), __( 'Pass Captain: Bezahlte Bestellung mit ausgeschöpftem Rabattcode', 'pass-captain' ), sprintf( /* translators: 1: order number, 2: payment reference */ __( 'Die Zahlung für Bestellung %1$s (%2$s) ist nach Ablauf der Reservierung eingegangen. Der verwendete Rabattcode ist inzwischen ausgeschöpft (Nutzungslimit erreicht). Es wurden keine Tickets ausgestellt. Bitte manuell klären (erstatten, Differenz nachfordern oder das Limit anpassen und die Zahlung erneut bestätigen).', 'pass-captain' ), $order->public_id, $reference ) );
			return new \WP_Error( 'wpcap_needs_review', __( 'Die Reservierung war abgelaufen und der Rabattcode ist inzwischen ausgeschöpft. Die Bestellung muss manuell geklärt werden.', 'pass-captain' ), array( 'reason' => 'campaign_exhausted' ) );
		}
		if ( in_array( $error, array( 'sale_closed', 'ticket_type_unavailable' ), true ) ) {
			wp_mail( get_option( 'admin_email' ), __( 'Pass Captain: Zahlung für geschlossenen Verkauf', 'pass-captain' ), sprintf( /* translators: 1: order number, 2: payment reference */ __( 'Die Zahlung für Bestellung %1$s (%2$s) ist nach Ablauf der Reservierung eingegangen. Der Termin ist inzwischen abgesagt oder vorbei bzw. die Ticketart ist nicht mehr im Verkauf. Es wurden keine Tickets ausgestellt. Bitte die Zahlung erstatten oder die Bestellung manuell klären.', 'pass-captain' ), $order->public_id, $reference ) );
			return new \WP_Error( 'wpcap_needs_review', __( 'Die Reservierung war abgelaufen und der Termin bzw. die Ticketart ist nicht mehr im Verkauf. Die Bestellung muss manuell geklärt werden.', 'pass-captain' ), array( 'reason' => 'sale_closed' ) );
		}
		if ( 'seat_sold_out' === $error ) {
			wp_mail( get_option( 'admin_email' ), __( 'Pass Captain: Bezahlte Bestellung ohne freien Sitzplatz', 'pass-captain' ), sprintf( /* translators: 1: order number, 2: payment reference */ __( 'Die Zahlung für Bestellung %1$s (%2$s) ist nach Ablauf der Reservierung eingegangen; die Sitzplätze sind inzwischen vergeben und es sind keine passenden Plätze mehr frei. Es wurden keine Tickets ausgestellt. Bitte manuell klären (Platz freigeben oder erstatten).', 'pass-captain' ), $order->public_id, $reference ) );
			return new \WP_Error( 'wpcap_needs_review', __( 'Die Reservierung war abgelaufen und es sind keine passenden Sitzplätze mehr frei. Die Bestellung muss manuell geklärt werden.', 'pass-captain' ), array( 'reason' => 'seat_sold_out' ) );
		}
		wp_mail( get_option( 'admin_email' ), __( 'Pass Captain: Bezahlte Bestellung ohne freies Kontingent', 'pass-captain' ), sprintf( /* translators: 1: order number, 2: payment reference */ __( 'Die Zahlung für Bestellung %1$s (%2$s) ist nach Ablauf der Reservierung eingegangen und es ist kein Kontingent mehr frei. Es wurden keine Tickets ausgestellt. Bitte manuell klären (Platz freigeben oder erstatten).', 'pass-captain' ), $order->public_id, $reference ) );
		return new \WP_Error( 'wpcap_needs_review', __( 'Die Reservierung war abgelaufen und es ist kein Kontingent mehr frei. Die Bestellung muss manuell geklärt werden.', 'pass-captain' ) );
	}

	/** Extends active holds of a pending order (e.g. WooCommerce "on-hold" bank transfers). */
	public static function extend_hold( $order_id, $minutes ) {
		global $wpdb;
		$until = gmdate( 'Y-m-d H:i:s', time() + max( 1, absint( $minutes ) ) * MINUTE_IN_SECONDS );
		return $wpdb->query( $wpdb->prepare( "UPDATE %i SET expires_at=GREATEST(expires_at,%s) WHERE order_id=%d AND status='active' AND expires_at > %s", $wpdb->prefix . 'wpcap_reservations', $until, absint( $order_id ), current_time( 'mysql', true ) ) );
	}

	/**
	 * Cancels an order: releases holds and campaign reservations; for paid orders all still valid
	 * tickets are revoked (checked-in tickets stay as historical record). Idempotent.
	 *
	 * Every write and the commit are checked: on a database error the transaction is rolled back
	 * (nothing changed, no side effects fired) and a retryable WP_Error (`wpcap_db_busy`) is returned,
	 * so a webhook answers 5xx and the provider retries.
	 *
	 * With $only_unpaid the order is only cancelled while it was never paid (payment status `pending`
	 * or `expired`, checked under the row lock); otherwise nothing changes and `wpcap_order_paid_state`
	 * is returned (used for provider refund events of unpaid orders, which must not cancel a payment
	 * that was booked in the meantime).
	 *
	 * @return true|\WP_Error
	 */
	public static function cancel_order( $order_id, $reason = '', $only_unpaid = false ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order_id = absint( $order_id );
		$now = current_time( 'mysql', true );
		if ( false === self::begin() ) { return self::state_busy( $order_id, 'cancel_start' ); }
		$order = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d FOR UPDATE', $p . 'wpcap_orders', $order_id ) );
		if ( '' !== $wpdb->last_error ) { return self::state_busy( $order_id, 'cancel_lock' ); }
		if ( ! $order || in_array( $order->status, array( 'cancelled', 'refunded' ), true ) ) {
			$wpdb->query( 'COMMIT' );
			return true;
		}
		if ( $only_unpaid && ! in_array( $order->payment_status, array( 'pending', 'expired' ), true ) ) {
			$wpdb->query( 'COMMIT' );
			return new \WP_Error( 'wpcap_order_paid_state', __( 'Der Bestellstatus erlaubt diese Aktion nicht.', 'pass-captain' ), array( 'payment_status' => (string) $order->payment_status ) );
		}
		if ( false === $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='released' WHERE order_id=%d AND status='active'", $p . 'wpcap_reservations', $order_id ) ) ) {
			return self::state_busy( $order_id, 'cancel_release' );
		}
		$valid_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM %i WHERE order_id=%d AND status='valid' FOR UPDATE", $p . 'wpcap_tickets', $order_id ) );
		if ( '' !== $wpdb->last_error ) { return self::state_busy( $order_id, 'cancel_tickets' ); }
		$revoked_ids = self::revoke_ids_checked( $valid_ids );
		if ( false === $revoked_ids ) { return self::state_busy( $order_id, 'cancel_revoke' ); }
		$revoked = count( $revoked_ids );
		if ( 1 !== (int) $wpdb->update( "{$p}wpcap_orders", array( 'status' => 'cancelled', 'payment_status' => 'cancelled', 'updated_at' => $now ), array( 'order_id' => $order_id ), array( '%s', '%s', '%s' ), array( '%d' ) ) ) {
			return self::state_busy( $order_id, 'cancel_order' );
		}
		if ( false === self::in_transaction() || false === $wpdb->query( 'COMMIT' ) ) { return self::state_busy( $order_id, 'cancel_commit' ); }
		Campaigns::release_for_order( $order_id );
		self::capacity_released( $order_id, 'cancelled', array( 'reason' => (string) $reason ) );
		Audit::record( 'order_cancelled', 'order', $order_id, array( 'reason' => sanitize_text_field( $reason ), 'revoked_tickets' => $revoked ) );
		self::fire_revoked( $revoked_ids, 'order_cancelled' );
		do_action( 'wpcap_order_cancelled', $order_id, (string) $reason, (string) $order->payment_status );
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
		$revoked_ids = self::revoke_ids( $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM %i WHERE order_id=%d AND ticket_type_id=%d AND status='valid' ORDER BY ticket_id DESC LIMIT %d", $wpdb->prefix . 'wpcap_tickets', absint( $order_id ), absint( $ticket_type_id ), $count ) ) );
		$revoked = count( $revoked_ids );
		self::fire_revoked( $revoked_ids, $reason );
		if ( $revoked ) {
			// Revoked units no longer consume capacity.
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET refunded_quantity=LEAST(quantity,refunded_quantity+%d) WHERE order_id=%d AND ticket_type_id=%d ORDER BY item_id LIMIT 1', $wpdb->prefix . 'wpcap_order_items', $revoked, absint( $order_id ), absint( $ticket_type_id ) ) );
			self::capacity_released( $order_id, 'tickets_revoked', array( 'ticket_type_id' => absint( $ticket_type_id ), 'quantity' => $revoked, 'reason' => (string) $reason ) );
			Audit::record( 'tickets_revoked', 'order', absint( $order_id ), array( 'ticket_type_id' => absint( $ticket_type_id ), 'count' => $revoked, 'reason' => sanitize_text_field( $reason ) ) );
		}
		return $revoked;
	}

	/**
	 * Marks an order as fully refunded and revokes all still valid tickets. Idempotent: an order that
	 * is already refunded is left alone, so repeated provider events change nothing.
	 *
	 * Runs in its own transaction with the order row locked; every write and the commit are checked.
	 * On a database error nothing is changed and a retryable WP_Error (`wpcap_db_busy`) is returned.
	 * Callers must not hold an open transaction (START TRANSACTION would commit it implicitly).
	 *
	 * @return true|\WP_Error
	 */
	public static function mark_refunded( $order_id, $reason = '' ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order_id = absint( $order_id );
		if ( false === self::begin() ) { return self::state_busy( $order_id, 'refund_start' ); }
		$order = $wpdb->get_row( $wpdb->prepare( 'SELECT status FROM %i WHERE order_id=%d FOR UPDATE', $p . 'wpcap_orders', $order_id ) );
		if ( '' !== $wpdb->last_error ) { return self::state_busy( $order_id, 'refund_lock' ); }
		if ( ! $order || 'refunded' === $order->status ) {
			$wpdb->query( 'COMMIT' );
			return true;
		}
		if ( false === $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='released' WHERE order_id=%d AND status='active'", $p . 'wpcap_reservations', $order_id ) ) ) {
			return self::state_busy( $order_id, 'refund_release' );
		}
		$valid_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM %i WHERE order_id=%d AND status='valid' FOR UPDATE", $p . 'wpcap_tickets', $order_id ) );
		if ( '' !== $wpdb->last_error ) { return self::state_busy( $order_id, 'refund_tickets' ); }
		$revoked_ids = self::revoke_ids_checked( $valid_ids );
		if ( false === $revoked_ids ) { return self::state_busy( $order_id, 'refund_revoke' ); }
		$revoked = count( $revoked_ids );
		if ( 1 !== (int) $wpdb->update( "{$p}wpcap_orders", array( 'status' => 'refunded', 'payment_status' => 'refunded', 'updated_at' => current_time( 'mysql', true ) ), array( 'order_id' => $order_id ), array( '%s', '%s', '%s' ), array( '%d' ) ) ) {
			return self::state_busy( $order_id, 'refund_order' );
		}
		if ( false === self::in_transaction() || false === $wpdb->query( 'COMMIT' ) ) { return self::state_busy( $order_id, 'refund_commit' ); }
		self::capacity_released( $order_id, 'refunded', array( 'reason' => (string) $reason ) );
		Audit::record( 'order_refunded', 'order', $order_id, array( 'reason' => sanitize_text_field( $reason ), 'revoked_tickets' => $revoked ) );
		self::fire_revoked( $revoked_ids, $reason ?: 'refunded' );
		/** Order fully refunded (native refund, WooCommerce or provider dashboard). */
		do_action( 'wpcap_order_refunded', $order_id, (string) $reason );
		return true;
	}

	/** Issues a new personal order access link (old link becomes invalid) and emails it (Orders\PortalAccess). */
	public static function rotate_access_link( $order_id ) {
		return Orders\PortalAccess::rotate( $order_id );
	}

	/** Returns tickets of an order with event context (no tokens). */
	public static function tickets_for_order( $order_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT t.ticket_id,t.public_id,t.status,t.ticket_type_id,tt.name AS ticket_name,e.name AS event_name,o.starts_at,o.timezone,o.location FROM %i t INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id INNER JOIN %i e ON e.event_id=o.event_id WHERE t.order_id=%d ORDER BY t.ticket_id', $p . 'wpcap_tickets', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $p . 'wpcap_events', absint( $order_id ) ) );
	}

	/** Order items with ticket names. */
	public static function items_for_order( $order_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT oi.*,tt.name,e.name AS event_name FROM %i oi INNER JOIN %i tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id INNER JOIN %i e ON e.event_id=o.event_id WHERE oi.order_id=%d ORDER BY oi.item_id', $p . 'wpcap_order_items', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $p . 'wpcap_events', absint( $order_id ) ) );
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
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d', $wpdb->prefix . 'wpcap_orders', absint( $order_id ) ) );
	}

	/** Mails the personal order access link (Orders\PortalAccess). */
	public static function send_order_access_link( $email, $public_id, $access_token, $locale = null ) {
		return Orders\PortalAccess::send( $email, $public_id, $access_token, $locale );
	}

	public static function transfer_ticket( $public_id, $access_token, $ticket_public_id, $recipient_email ) {
		global $wpdb;
		$public_id = sanitize_text_field( $public_id );
		$access_token = Security\Input::hex( $access_token );
		$recipient_email = sanitize_email( $recipient_email );
		if ( 32 !== strlen( $public_id ) || 64 !== strlen( $access_token ) || ! is_email( $recipient_email ) ) {
			return new \WP_Error( 'wpcap_transfer_invalid', __( 'Die Übertragungsdaten sind ungültig.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		if ( strlen( $recipient_email ) > self::EMAIL_MAX_LENGTH ) {
			return self::email_too_long( 'recipient_email' );
		}
		$order = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE public_id=%s', $wpdb->prefix . 'wpcap_orders', $public_id ) );
		if ( ! $order || ! $order->access_expires_at || $order->access_expires_at <= current_time( 'mysql', true ) || ! hash_equals( (string) $order->access_token_hash, hash( 'sha256', $access_token ) ) ) {
			return new \WP_Error( 'wpcap_order_access_denied', __( 'Der Bestellzugriff ist ungültig.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		if ( 'paid' !== $order->payment_status ) {
			return new \WP_Error( 'wpcap_order_not_paid', __( 'Tickets können erst nach bestätigter Zahlung übertragen werden.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$tickets_table = $wpdb->prefix . 'wpcap_tickets';
		self::begin();
		$ticket = $wpdb->get_row( $wpdb->prepare(
			'SELECT t.ticket_id,t.status,t.transfer_count,tt.name AS ticket_name,e.name AS event_name,o.starts_at,o.timezone,o.location FROM %i t INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id INNER JOIN %i e ON e.event_id=o.event_id WHERE t.public_id=%s AND t.order_id=%d FOR UPDATE', $tickets_table, $wpdb->prefix . 'wpcap_ticket_types', $wpdb->prefix . 'wpcap_occurrences', $wpdb->prefix . 'wpcap_events',
			sanitize_text_field( $ticket_public_id ),
			$order->order_id
		) );
		if ( ! $ticket || 'valid' !== $ticket->status || (int) $ticket->transfer_count >= 1 || strtotime( $ticket->starts_at . ' UTC' ) <= time() ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_transfer_not_allowed', __( 'Dieses Ticket kann nicht mehr übertragen werden.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		// A holder change by assignment counts against the same limit as a transfer.
		if ( method_exists( TicketAssignments::class, 'holder_changes' ) && TicketAssignments::holder_changes( (int) $ticket->ticket_id ) >= (int) apply_filters( 'wpcap_max_holder_changes', 1, (int) $ticket->ticket_id ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_transfer_holder_changed', __( 'Für dieses Ticket wurde der Inhaber bereits geändert; eine weitere Übertragung ist nicht möglich.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$token = bin2hex( random_bytes( 32 ) );
		$updated = $wpdb->update( $tickets_table, array(
			'token_hash' => hash( 'sha256', $token ),
			'attendee_email' => $recipient_email,
			'transfer_count' => (int) $ticket->transfer_count + 1,
		), array( 'ticket_id' => (int) $ticket->ticket_id, 'status' => 'valid', 'transfer_count' => (int) $ticket->transfer_count ), array( '%s', '%s', '%d' ), array( '%d', '%s', '%d' ) );
		if ( false === $updated ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_transfer_failed', __( 'Die Ticketübertragung konnte nicht gespeichert werden.', 'pass-captain' ), array( 'status' => 500 ) );
		}
		// The previous holder's name and custom answers must not stay on the transferred ticket.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET attendee_name='',attendee_email=%s WHERE ticket_id=%d", $wpdb->prefix . 'wpcap_ticket_assignments', $recipient_email, (int) $ticket->ticket_id ) );
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
			return new \WP_Error( 'wpcap_transfer_mail_failed', __( 'Die Ticketübertragung wurde gespeichert, aber die E-Mail konnte nicht zugestellt werden. Bitte kontaktiere den Veranstalter für einen erneuten Versand.', 'pass-captain' ), array( 'status' => 502 ) );
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
		$event = $wpdb->get_row( $wpdb->prepare( 'SELECT event_id,name,status FROM %i WHERE event_id=%d', $p . 'wpcap_events', $event_id ) );
		if ( ! $event || 'published' !== $event->status ) {
			return new \WP_Error( 'wpcap_event_not_cancellable', __( 'Dieses Event kann nicht abgesagt werden.', 'pass-captain' ) );
		}
		$order_ids = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT oi.order_id FROM %i oi INNER JOIN %i tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id WHERE o.event_id=%d', $p . 'wpcap_order_items', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $event_id ) ) );
		// Only buyers with a paid or open order are notified, not abandoned/expired/refunded attempts.
		// Read before the cancellation changes order and ticket states.
		$notify_ids = $order_ids ? array_map( 'absint', $wpdb->get_col( $wpdb->prepare( 'SELECT order_id FROM %i WHERE order_id IN (' . implode( ',', array_fill( 0, count( $order_ids ), '%d' ) ) . ") AND payment_status IN ('paid','pending','paid_unallocated')", array_merge( array( $p . 'wpcap_orders' ), $order_ids ) ) ) ) : array();
		$pending_before = $order_ids ? array_map( 'absint', $wpdb->get_col( $wpdb->prepare( 'SELECT order_id FROM %i WHERE order_id IN (' . implode( ',', array_fill( 0, count( $order_ids ), '%d' ) ) . ") AND payment_status='pending'", array_merge( array( $p . 'wpcap_orders' ), $order_ids ) ) ) ) : array();
		$recipients = ! $notify_ids ? array() : $wpdb->get_results( $wpdb->prepare(
			"SELECT COALESCE(NULLIF(t.attendee_email,''),ord.customer_email) AS email,MAX(ord.locale) AS locale FROM %i ord INNER JOIN %i oi ON oi.order_id=ord.order_id INNER JOIN %i tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id LEFT JOIN %i t ON t.order_id=ord.order_id AND t.ticket_type_id=tt.ticket_type_id WHERE o.event_id=%d AND ord.order_id IN (" . implode( ',', array_fill( 0, count( $notify_ids ), '%d' ) ) . ') GROUP BY email',
			array_merge(
				array(
					$p . 'wpcap_orders',
					$p . 'wpcap_order_items',
					$p . 'wpcap_ticket_types',
					$p . 'wpcap_occurrences',
					$p . 'wpcap_tickets',
					$event_id,
				),
				$notify_ids
			)
		) );

		self::begin();
		$changed = $wpdb->update( "{$p}wpcap_events", array( 'status' => 'cancelled', 'updated_at' => current_time( 'mysql', true ) ), array( 'event_id' => $event_id, 'status' => 'published' ), array( '%s', '%s' ), array( '%d', '%s' ) );
		if ( 1 !== (int) $changed ) {
			// A concurrent request cancelled (or changed) the event first.
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_event_not_cancellable', __( 'Dieses Event kann nicht abgesagt werden.', 'pass-captain' ) );
		}
		$occurrence_ids = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT occurrence_id FROM %i WHERE event_id=%d AND status<>'cancelled' ORDER BY occurrence_id FOR UPDATE", $p . 'wpcap_occurrences', $event_id ) ) );
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET status='cancelled' WHERE event_id=%d", $p . 'wpcap_occurrences', $event_id ) );
		$wpdb->query( 'COMMIT' );

		$summary = array( 'cancelled_orders' => 0, 'refunds' => 0, 'refund_errors' => 0, 'revoked' => 0 );
		OccurrenceCancellation::begin_event_batch();
		try {
			foreach ( $occurrence_ids as $occurrence_id ) {
				$issued = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i t INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=%d AND t.status IN ('valid','checked_in')", $p . 'wpcap_tickets', $p . 'wpcap_ticket_types', $occurrence_id ) );
				Audit::record( 'occurrence_cancelled', 'occurrence', $occurrence_id, array( 'event_id' => $event_id, 'issued_tickets' => $issued, 'via' => 'event_cancelled' ) );
				do_action( 'wpcap_occurrence_cancelled', $occurrence_id, $issued );
				foreach ( array_keys( $summary ) as $key ) { $summary[ $key ] += (int) ( OccurrenceCancellation::last_summary( $occurrence_id )[ $key ] ?? 0 ); }
			}
		} finally {
			OccurrenceCancellation::end_event_batch();
		}

		// Safety net when the occurrence handler is unhooked: open orders and still valid tickets.
		$still_pending = $order_ids ? array_map( 'absint', $wpdb->get_col( $wpdb->prepare( 'SELECT order_id FROM %i WHERE order_id IN (' . implode( ',', array_fill( 0, count( $order_ids ), '%d' ) ) . ") AND payment_status='pending'", array_merge( array( $p . 'wpcap_orders' ), $order_ids ) ) ) ) : array();
		foreach ( $still_pending as $order_id ) {
			self::cancel_order( $order_id, 'event_cancelled' );
			++$summary['cancelled_orders'];
		}
		$revoked_ids = self::revoke_ids( $wpdb->get_col( $wpdb->prepare( "SELECT t.ticket_id FROM %i t INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id WHERE o.event_id=%d AND t.status='valid'", $p . 'wpcap_tickets', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $event_id ) ) );
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
		do_action( 'wpcap_event_cancelled', $event_id );
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
			"SELECT t.ticket_id,t.ticket_type_id FROM %i t LEFT JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id LEFT JOIN %i o ON o.occurrence_id=tt.occurrence_id LEFT JOIN %i e ON e.event_id=o.event_id WHERE t.order_id=%d AND t.refunded=0 AND (t.status IN ('valid','checked_in') OR (t.status='revoked' AND (o.status='cancelled' OR e.status='cancelled'))) ORDER BY t.ticket_id", $p . 'wpcap_tickets', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $p . 'wpcap_events',
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
			return new \WP_Error( 'wpcap_order_state', __( 'Der Bestellstatus erlaubt diese Aktion nicht.', 'pass-captain' ) );
		}
		$result = self::confirm_external_payment( $order_id, 'manual:user-' . get_current_user_id() );
		if ( ! is_wp_error( $result ) ) {
			Audit::record( 'manual_payment_confirmed', 'order', absint( $order_id ) );
		}
		return $result;
	}

	/**
	 * Expires unpaid orders whose holds have lapsed (cron `wpcap_cleanup_reservations` hourly and the
	 * 5-minute worker `wpcap_process_jobs`, see expire_holds_worker()).
	 *
	 * Each order is expired in its own short transaction with the same lock order as the payment
	 * confirmation: order row FOR UPDATE, then its reservations by primary key FOR UPDATE (no bulk
	 * UPDATE over the reservation indexes, whose gap locks deadlocked checkouts and late payments).
	 * The side effects (campaign release, capacity released, wpcap_order_cancelled) only run for orders
	 * this call expired – an order paid between selection and lock is left alone. Lapsed holds of
	 * orders that are no longer pending are marked by id afterwards. Database errors are logged and the
	 * order is retried by the next run.
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
		self::$last_expire_batch_full = false;
		$candidates = $wpdb->get_col( $wpdb->prepare(
			"SELECT ord.order_id FROM %i ord WHERE ord.payment_status='pending' AND NOT EXISTS (SELECT 1 FROM %i r WHERE r.order_id=ord.order_id AND r.status='active' AND r.expires_at>%s) ORDER BY ord.order_id LIMIT %d", $orders, $reservations,
			$now,
			$limit
		) );
		if ( '' !== $wpdb->last_error ) {
			Log::warning( 'expire_holds_failed', 'Expired holds could not be selected; the next run retries.', array( 'step' => 'select' ) );
			return 0;
		}
		self::$last_expire_batch_full = count( $candidates ) >= $limit;
		$expired = 0;
		$failed = array();
		foreach ( array_map( 'absint', $candidates ) as $order_id ) {
			$result = self::expire_order( $order_id, $now );
			if ( is_string( $result ) ) {
				$failed[ $order_id ] = $result;
				continue;
			}
			if ( ! $result ) { continue; }
			++$expired;
			Campaigns::release_for_order( $order_id );
			self::capacity_released( $order_id, 'expired' );
			do_action( 'wpcap_order_cancelled', $order_id );
		}
		// Lapsed holds of orders that are not pending any more (they no longer count anyway): by id.
		$stale = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( "SELECT r.reservation_id FROM %i r INNER JOIN %i ord ON ord.order_id=r.order_id WHERE r.status='active' AND r.expires_at<=%s AND ord.payment_status<>'pending' ORDER BY r.reservation_id LIMIT %d", $reservations, $orders, $now, $limit ) ) );
		if ( $stale && false === $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='expired' WHERE reservation_id IN (" . implode( ',', array_fill( 0, count( $stale ), '%d' ) ) . ") AND status='active' AND expires_at<=%s", array_merge( array( $reservations ), $stale, array( $now ) ) ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer placeholders.
			$failed[0] = 'stale_holds';
		}
		if ( $failed ) {
			Log::warning( 'expire_holds_failed', 'Some lapsed holds could not be expired (database busy); the next run retries.', array( 'orders' => array_slice( array_keys( $failed ), 0, 20 ), 'steps' => array_values( array_unique( $failed ) ) ) );
		}
		return $expired;
	}

	/** @var bool Whether the last expire_holds() run selected a full batch (more may be waiting). */
	private static $last_expire_batch_full = false;

	/**
	 * Expires one pending order whose holds lapsed (own transaction: order row, then its reservations).
	 *
	 * @return bool|string True when expired, false when there was nothing to do (paid, still held,
	 *                     gone), or the failed step on a database error (nothing changed).
	 */
	private static function expire_order( $order_id, $now ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$hold_ids = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT reservation_id FROM %i WHERE order_id=%d ORDER BY reservation_id', $p . 'wpcap_reservations', $order_id ) ) );
		if ( '' !== $wpdb->last_error ) { return 'read'; }
		if ( false === self::begin() ) { return 'start'; }
		$fail = static function ( $step ) use ( $wpdb ) {
			$wpdb->query( 'ROLLBACK' );
			return $step;
		};
		$order = $wpdb->get_row( $wpdb->prepare( 'SELECT order_id,payment_status FROM %i WHERE order_id=%d FOR UPDATE', $p . 'wpcap_orders', $order_id ) );
		if ( '' !== $wpdb->last_error ) { return $fail( 'lock_order' ); }
		if ( ! $order || 'pending' !== $order->payment_status ) {
			$wpdb->query( 'COMMIT' );
			return false;
		}
		$holds = self::lock_holds( $hold_ids );
		if ( false === $holds ) { return $fail( 'lock_holds' ); }
		foreach ( $holds as $hold ) {
			if ( 'active' === $hold->status && $hold->expires_at > $now ) {
				$wpdb->query( 'COMMIT' ); // Extended meanwhile (e.g. delayed payment method).
				return false;
			}
		}
		if ( false === self::set_hold_status( $hold_ids, 'expired', array( 'active' ) ) ) { return $fail( 'expire_holds' ); }
		if ( 1 !== (int) $wpdb->update( "{$p}wpcap_orders", array( 'status' => 'expired', 'payment_status' => 'expired', 'updated_at' => $now ), array( 'order_id' => $order_id ), array( '%s', '%s', '%s' ), array( '%d' ) ) ) { return $fail( 'expire_order' ); }
		if ( false === self::in_transaction() || false === $wpdb->query( 'COMMIT' ) ) { return $fail( 'commit' ); }
		return true;
	}

	/**
	 * 5-minute worker (`wpcap_process_jobs`): expires lapsed holds in batches within half of the shared
	 * cron time budget, so limited discount codes and capacity come back within minutes instead of
	 * only with the hourly cleanup. A full last batch asks for an immediate follow-up run.
	 *
	 * @return int Number of expired orders.
	 */
	public static function expire_holds_worker() {
		$deadline = min( Jobs\TimeBudget::deadline(), microtime( true ) + Jobs\TimeBudget::seconds() / 2 );
		$total = 0;
		do {
			$total += self::expire_holds();
			$more = self::$last_expire_batch_full;
		} while ( $more && ! Jobs\TimeBudget::exhausted( $deadline ) );
		if ( $more ) { Jobs\TimeBudget::continue_later( 'wpcap_process_jobs' ); }
		return $total;
	}

	/** Action callback of `wpcap_process_jobs` (no return value): expire_holds_worker(). */
	public static function process_expired_holds() {
		self::expire_holds_worker();
	}

	/** Job type of the ticket delivery outbox (see issue_and_email()). */
	const DELIVERY_JOB = 'ticket_delivery';

	/** Job type that retries a ticket issuance which hit a database error. */
	const ISSUE_JOB = 'ticket_issue';

	/** Minutes before the outbox job of an inline delivery becomes due (covers a crash after commit). */
	const DELIVERY_GRACE_MINUTES = 10;

	/** True while a queued issuance retry runs, so its failure does not queue yet another job. */
	private static $issuing_from_job = false;

	/** Whether the job handler is registered. */
	private static $registered = false;

	/**
	 * Registers the job handler for issuance retries and the ticket delivery outbox. Called from
	 * Plugin::run() and when this file is loaded, so the handler is present in every cron
	 * request that runs Jobs::process_due().
	 */
	public static function register() {
		if ( self::$registered || ! function_exists( 'add_filter' ) ) { return; }
		self::$registered = true;
		add_filter( 'wp_pass_captain_process_job_v1', array( __CLASS__, 'handle_job' ), 10, 3 );
	}

	/** Filter wp_pass_captain_process_job_v1: issuance retries and ticket delivery retries. */
	public static function handle_job( $handled, $type, $payload = array() ) {
		$order_id = absint( ( (array) $payload )['order_id'] ?? 0 );
		if ( self::ISSUE_JOB === $type ) {
			self::$issuing_from_job = true;
			try {
				return true === self::issue_and_email( $order_id );
			} finally {
				self::$issuing_from_job = false;
			}
		}
		if ( self::DELIVERY_JOB === $type ) {
			return self::retry_delivery( $order_id, array_map( 'absint', (array) ( ( (array) $payload )['ticket_ids'] ?? array() ) ) );
		}
		return $handled;
	}

	/**
	 * Issues the missing tickets of a paid order and mails them to the buyer. Idempotent.
	 *
	 * Issuance: tickets, attendee assignments/answers and the cleanup of the order-level attendee
	 * copy are written in one transaction with the order row locked, and every write as well as the
	 * transaction start and commit is checked. On a database error everything is rolled back, a
	 * `ticket_issue` job retries the issuance later and a retryable WP_Error (`wpcap_issue_failed`)
	 * is returned (webhooks answer 5xx). The order-level attendee data is only removed once every
	 * collected attendee has been stored on a ticket in this transaction.
	 *
	 * Delivery (outbox): the same transaction queues a `ticket_delivery` job for the new tickets,
	 * due after DELIVERY_GRACE_MINUTES. A successful inline mail completes it; a failed mail (or a
	 * request that dies after the commit) leaves it to the job queue, which retries with backoff and
	 * dead-letters after the last attempt. The job row is the persisted delivery status
	 * (see delivery_status(), shown on the admin order page).
	 *
	 * Retry design: ticket codes are only stored hashed, so a retry cannot send the original codes.
	 * It goes through the resend path instead (rotate_and_send()): the still valid tickets of the
	 * failed delivery get new codes under row locks and the new links are mailed to the buyer before
	 * the rotation commits. The codes of the failed mail never reached anybody, so rotating them is
	 * safe; a mail failure during the retry rolls the rotation back.
	 *
	 * @return true|\WP_Error True when nothing was missing or the issuance committed; a failed mail
	 *                        does not make this fail (the outbox retries it).
	 */
	public static function issue_and_email( $order_id ) {
		// Fiscal modules submit every seller-specific receipt before tickets can be issued. A blocked order is
		// not an issuance failure: the fiscal module retries and calls this again once its receipts are confirmed.
		if ( ! apply_filters( 'wpcap_order_fiscal_ready', true, absint( $order_id ) ) ) { return true; }
		global $wpdb;
		$order_id = absint( $order_id );
		$orders = $wpdb->prefix . 'wpcap_orders';
		// Serialise issuance per order so concurrent payment callbacks cannot create duplicates.
		if ( false === self::begin() ) { return self::issue_failed( $order_id, 'start' ); }
		$order = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d FOR UPDATE', $orders, $order_id ) );
		if ( '' !== $wpdb->last_error ) { return self::issue_failed( $order_id, 'lock' ); }
		if ( ! $order || 'paid' !== $order->payment_status ) {
			$wpdb->query( 'COMMIT' );
			return true;
		}
		$lines = $wpdb->get_results( $wpdb->prepare( 'SELECT oi.*,tt.name AS ticket_name,tt.occurrence_id,o.event_id,e.name AS event_name,o.starts_at,o.timezone,o.location FROM %i oi INNER JOIN %i tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id INNER JOIN %i e ON e.event_id=o.event_id WHERE oi.order_id=%d', $wpdb->prefix . 'wpcap_order_items', $wpdb->prefix . 'wpcap_ticket_types', $wpdb->prefix . 'wpcap_occurrences', $wpdb->prefix . 'wpcap_events', $order_id ) );
		if ( '' !== $wpdb->last_error ) { return self::issue_failed( $order_id, 'lines' ); }
		$issued = array();
		// Attendee data collected at checkout, applied once to the issued tickets.
		$pending_attendees = $order->attendee_data ? (array) json_decode( $order->attendee_data, true ) : array();
		foreach ( $lines as $line ) {
			$existing = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE order_id=%d AND ticket_type_id=%d', $wpdb->prefix . 'wpcap_tickets', $order_id, $line->ticket_type_id ) );
			if ( '' !== $wpdb->last_error ) { return self::issue_failed( $order_id, 'count' ); }
			for ( $i = $existing; $i < (int) $line->quantity; $i++ ) {
				$token = bin2hex( random_bytes( 32 ) );
				$public_id = bin2hex( random_bytes( 16 ) );
				$inserted = $wpdb->insert( $wpdb->prefix . 'wpcap_tickets', array(
					'public_id' => $public_id,
					'order_id' => $order_id,
					'ticket_type_id' => (int) $line->ticket_type_id,
					'token_hash' => hash( 'sha256', $token ),
					'status' => 'valid',
					'issued_at' => current_time( 'mysql', true ),
				), array( '%s', '%d', '%d', '%s', '%s', '%s' ) );
				$ticket_id = (int) $wpdb->insert_id;
				if ( ! $inserted || ! $ticket_id ) { return self::issue_failed( $order_id, 'ticket_insert' ); }
				foreach ( $pending_attendees as $index => $attendee ) {
					if ( (int) ( $attendee['ticket_type_id'] ?? 0 ) !== (int) $line->ticket_type_id ) { continue; }
					if ( ! self::store_attendee( $ticket_id, $order, (array) $attendee ) ) { return self::issue_failed( $order_id, 'attendee' ); }
					unset( $pending_attendees[ $index ] );
					break;
				}
				/** Inside the issuance transaction, e.g. to assign a seat or area. Do not store $token. */
				do_action( 'wpcap_ticket_created', $ticket_id, $order, $line, $i );
				// A deadlock inside a hook rolls the whole transaction back; further inserts would autocommit
				// and leave a partial issuance behind.
				if ( false === self::in_transaction() ) { return self::issue_failed( $order_id, 'ticket_hook' ); }
				$issued[] = array( 'ticket_id' => $ticket_id, 'ticket_type_id' => (int) $line->ticket_type_id, 'occurrence_id' => (int) ( $line->occurrence_id ?? 0 ), 'token' => $token, 'event' => $line->event_name, 'ticket' => $line->ticket_name, 'starts_at' => $line->starts_at, 'timezone' => $line->timezone, 'location' => $line->location );
			}
		}
		if ( $issued && $order->attendee_data && ! $pending_attendees ) {
			// Every collected attendee is stored with a ticket (each write checked above): drop the
			// order-level copy (data minimisation). Unassigned entries keep the copy for a later run.
			if ( false === $wpdb->update( $orders, array( 'attendee_data' => null ), array( 'order_id' => $order_id ) ) ) { return self::issue_failed( $order_id, 'attendee_cleanup' ); }
		}
		$delivery_key = '';
		if ( $issued ) {
			$delivery_key = 'ticket-delivery-' . $order_id . '-' . bin2hex( random_bytes( 8 ) );
			// order_id first: delivery_status() finds the job by its payload prefix.
			$payload = array( 'order_id' => $order_id, 'ticket_ids' => array_map( 'intval', array_column( $issued, 'ticket_id' ) ) );
			if ( true !== Jobs::enqueue( $delivery_key, self::DELIVERY_JOB, $payload, gmdate( 'Y-m-d H:i:s', time() + self::DELIVERY_GRACE_MINUTES * MINUTE_IN_SECONDS ) ) ) {
				return self::issue_failed( $order_id, 'outbox' );
			}
		}
		if ( false === self::in_transaction() || false === $wpdb->query( 'COMMIT' ) ) { return self::issue_failed( $order_id, 'commit' ); }
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
					if ( TempFiles::put( $file, TicketPdf::render( $pdf_tickets, array( 'title' => get_bloginfo( 'name' ), 'footer' => __( 'Dieses Ticket ist nur einmal gültig. Code nicht weitergeben.', 'pass-captain' ) ) ) ) ) {
						$attachments[] = $file;
					}
				}
			}
			$mail = array( 'attachments' => $attachments );
			$delivered = false;
			try {
				// Legacy filter: receives and may change subject/body/attachments of the rendered template.
				$mail = apply_filters( 'wpcap_ticket_mail', array( 'subject' => $message['subject'], 'body' => $message['body'], 'attachments' => $attachments ), $order, $issued );
				$mail = is_array( $mail ) ? $mail : array( 'attachments' => $attachments );
				$delivered = Mail\Mailer::deliver( $order->customer_email, Mail\Mailer::apply_filtered( $message, $mail ) );
			} finally {
				$attachments = array_unique( array_merge( $attachments, (array) ( $mail['attachments'] ?? array() ) ) );
				foreach ( $attachments as $file ) { wp_delete_file( $file ); }
				if ( ! empty( $pdf_dir ) ) { TempFiles::remove_dir( $pdf_dir ); }
			}
			self::finish_delivery( $delivery_key, $order_id, (bool) $delivered, array_map( 'intval', array_column( $issued, 'ticket_id' ) ) );
		}
		return true;
	}

	/**
	 * Rolls back a failed issuance, queues a retry job (unless this already is one) and returns a
	 * retryable error.
	 */
	private static function issue_failed( $order_id, $step ) {
		global $wpdb;
		$error = (string) $wpdb->last_error;
		$wpdb->query( 'ROLLBACK' );
		Audit::record( 'ticket_issue_failed', 'order', $order_id, array( 'step' => sanitize_key( $step ), 'error' => substr( $error, 0, 200 ) ) );
		if ( ! self::$issuing_from_job && $order_id ) {
			// One retry job per order and minute; the job queue retries it with backoff.
			Jobs::enqueue( 'ticket-issue-' . $order_id . '-' . gmdate( 'YmdHi' ), self::ISSUE_JOB, array( 'order_id' => $order_id ), gmdate( 'Y-m-d H:i:s', time() + 2 * MINUTE_IN_SECONDS ) );
		}
		return new \WP_Error( 'wpcap_issue_failed', __( 'Die Tickets konnten gerade nicht ausgestellt werden. Bitte erneut versuchen.', 'pass-captain' ), array( 'status' => 503, 'retryable' => true, 'step' => (string) $step ) );
	}

	/**
	 * Records the result of the inline ticket mail on its outbox job: completed when sent, otherwise
	 * attempt counted, error noted and due again in five minutes (Jobs::process_due() retries it).
	 *
	 * Double-mail guard: after a successful mail a "delivered" marker (option, separate from the job row)
	 * is written before the job is completed, and the completion is retried briefly. Should the job
	 * still stay queued (e.g. lock timeouts on the queue table), retry_delivery() finds the marker and
	 * completes the job without sending a second mail with rotated codes.
	 *
	 * @param int[] $ticket_ids Tickets of this delivery.
	 */
	private static function finish_delivery( $key, $order_id, $sent, array $ticket_ids ) {
		global $wpdb;
		if ( '' === $key ) { return; }
		$table = $wpdb->prefix . 'wpcap_jobs';
		$now = current_time( 'mysql', true );
		if ( $sent ) {
			$marker = self::delivery_marker( $order_id, $ticket_ids );
			update_option( $marker, time(), false );
			$completed = Jobs::update_with_retry( $table, array( 'status' => 'complete', 'attempts' => 1, 'locked_at' => null, 'last_error' => null, 'updated_at' => $now ), array( 'idempotency_key' => hash( 'sha256', $key ), 'status' => 'queued' ), array( '%s', '%d', '%s', '%s', '%s' ), array( '%s', '%s' ) );
			if ( $completed ) {
				delete_option( $marker );
			}
			return;
		}
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET attempts=attempts+1,run_after=%s,last_error=%s,updated_at=%s WHERE idempotency_key=%s AND status='queued'", $table, gmdate( 'Y-m-d H:i:s', time() + 5 * MINUTE_IN_SECONDS ), 'Ticket mail could not be delivered.', $now, hash( 'sha256', $key ) ) );
		Audit::record( 'ticket_delivery_failed', 'order', absint( $order_id ), array( 'ticket_count' => count( $ticket_ids ), 'retry' => 'scheduled' ) );
	}

	/** Option name of the "delivered" marker of one ticket delivery (order + its tickets). */
	public static function delivery_marker( $order_id, array $ticket_ids ) {
		$ids = array_values( array_unique( array_map( 'absint', $ticket_ids ) ) );
		sort( $ids );
		return 'wpcap_delivered_' . absint( $order_id ) . '_' . substr( hash( 'sha256', implode( ',', $ids ) ), 0, 16 );
	}

	/**
	 * Outbox job: delivers tickets whose mail failed by rotating their codes and mailing new links to
	 * the buyer (see issue_and_email()). Returns false to let the job queue retry.
	 */
	private static function retry_delivery( $order_id, array $ticket_ids ) {
		global $wpdb;
		$marker = self::delivery_marker( $order_id, $ticket_ids );
		if ( $ticket_ids && get_option( $marker ) ) {
			// The inline mail was sent; only completing the job had failed. No second mail.
			delete_option( $marker );
			Audit::record( 'ticket_delivery_retry_skipped', 'order', absint( $order_id ), array( 'ticket_count' => count( $ticket_ids ), 'reason' => 'already_delivered' ) );
			return true;
		}
		$order = $order_id ? self::get( $order_id ) : null;
		if ( '' !== $wpdb->last_error ) { return false; }
		if ( ! $order || 'paid' !== $order->payment_status || ! $ticket_ids ) {
			return true; // Nothing deliverable any more (cancelled, refunded).
		}
		$result = self::rotate_and_send( $order, $ticket_ids, 'delivery_retry', true );
		if ( is_wp_error( $result ) ) {
			// No valid ticket left (revoked, checked in, transferred with its own mail): done.
			return 'wpcap_no_resendable_tickets' === $result->get_error_code();
		}
		Audit::record( 'tickets_delivered_retry', 'order', $order_id, array( 'ticket_count' => (int) $result ) );
		return true;
	}

	/**
	 * Safety net for issuances whose retry job could not be queued (e.g. the database connection was
	 * lost after the payment committed): issues the missing tickets of paid orders. Idempotent (the
	 * issuance locks the order row and only creates what is missing); runs with the job queue (cron).
	 *
	 * @return int Number of orders whose tickets were issued.
	 */
	public static function reconcile_issuance( $limit = 20 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		// Orders of the last 30 days only (index paid_created), so the sweep stays cheap on large sites.
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT ord.order_id FROM %i ord WHERE ord.payment_status='paid' AND ord.created_at>=%s AND ord.status='complete' AND (SELECT COALESCE(SUM(oi.quantity),0) FROM %i oi WHERE oi.order_id=ord.order_id) > (SELECT COUNT(*) FROM %i t WHERE t.order_id=ord.order_id) ORDER BY ord.order_id LIMIT %d", $p . 'wpcap_orders',
			gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ), $p . 'wpcap_order_items', $p . 'wpcap_tickets',
			max( 1, absint( $limit ) )
		) );
		$done = 0;
		foreach ( (array) $ids as $order_id ) {
			self::$issuing_from_job = true; // No extra retry job: the next sweep retries.
			try {
				if ( true === self::issue_and_email( (int) $order_id ) && 0 === self::missing_tickets( (int) $order_id ) ) { ++$done; }
			} finally {
				self::$issuing_from_job = false;
			}
		}
		return $done;
	}

	/**
	 * Number of tickets a paid order should have but does not (issuance failed or is still pending).
	 *
	 * @return int|null Null on a database error.
	 */
	public static function missing_tickets( $order_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$missing = $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(GREATEST(0, oi.qty - COALESCE(t.n,0))),0) FROM (SELECT ticket_type_id, SUM(quantity) AS qty FROM %i WHERE order_id=%d GROUP BY ticket_type_id) oi LEFT JOIN (SELECT ticket_type_id, COUNT(*) AS n FROM %i WHERE order_id=%d GROUP BY ticket_type_id) t ON t.ticket_type_id=oi.ticket_type_id', $p . 'wpcap_order_items', absint( $order_id ), $p . 'wpcap_tickets', absint( $order_id ) ) );
		return '' !== $wpdb->last_error || null === $missing ? null : (int) $missing;
	}

	/**
	 * Delivery status of the latest ticket mail of an order, from its outbox job.
	 *
	 * @return array|null {status: sent|pending|retrying|failed, attempts, last_error, next_attempt}; null when unknown.
	 */
	public static function delivery_status( $order_id ) {
		global $wpdb;
		$like = '{"order_id":' . absint( $order_id ) . ',%';
		$job = $wpdb->get_row( $wpdb->prepare( 'SELECT status,attempts,last_error,run_after FROM %i WHERE job_type=%s AND payload LIKE %s ORDER BY job_id DESC LIMIT 1', $wpdb->prefix . 'wpcap_jobs', self::DELIVERY_JOB, $like ) );
		if ( ! $job ) { return null; }
		$map = array( 'complete' => 'sent', 'dead_letter' => 'failed' );
		$status = $map[ $job->status ] ?? ( (int) $job->attempts > 0 ? 'retrying' : 'pending' );
		return array( 'status' => $status, 'attempts' => (int) $job->attempts, 'last_error' => (string) $job->last_error, 'next_attempt' => 'retrying' === $status ? (string) $job->run_after : '' );
	}

	/** Creates a fresh directory (0700, random name) in the temp dir; null on failure. */
	private static function private_temp_dir() {
		return TempFiles::private_dir();
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

	/**
	 * Resends all valid tickets of a paid order with new codes, one mail per holder (attendee e-mail,
	 * else the buyer). Checked-in tickets are skipped: they are used, and rotating them would only
	 * invalidate the holder's admission history link.
	 *
	 * Rotation and mail run per recipient in rotate_and_send(): row locks serialise concurrent
	 * resends, each code is replaced by a compare-and-set on its previous hash, and the rotation only
	 * commits after the mail was accepted. A failed mail or database error rolls that recipient's
	 * rotation back, so no old code is invalidated without the new one being sent.
	 *
	 * @return true|\WP_Error
	 */
	public static function resend_tickets( $order_id ) {
		global $wpdb;
		$order_id = absint( $order_id );
		$order = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d', $wpdb->prefix . 'wpcap_orders', $order_id ) );
		if ( ! $order || 'paid' !== $order->payment_status ) {
			return new \WP_Error( 'wpcap_order_unpaid', __( 'Tickets können erst nach bestätigter Zahlung erneut gesendet werden.', 'pass-captain' ) );
		}
		$result = self::rotate_and_send( $order, null, 'resend', false );
		if ( is_wp_error( $result ) ) { return $result; }
		Audit::record( 'tickets_resent', 'order', $order_id, array( 'ticket_count' => (int) $result ) );
		return true;
	}

	/**
	 * Rotates the codes of valid tickets of an order and mails the new links, per recipient.
	 *
	 * @param object     $order      Order row (paid).
	 * @param int[]|null $ticket_ids Restrict to these tickets; null = all valid tickets of the order.
	 * @param string     $reason     Passed to wpcap_ticket_code_rotated (resend, delivery_retry).
	 * @param bool       $to_buyer   Mail every ticket to the buyer (initial delivery) instead of its holder.
	 * @return int|\WP_Error Number of tickets rotated and mailed. On a partial failure the error
	 *                       carries `sent` (tickets mailed to other recipients); the failed
	 *                       recipient's codes are unchanged.
	 */
	private static function rotate_and_send( $order, $ticket_ids, $reason, $to_buyer ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$filter = '';
		if ( null !== $ticket_ids ) {
			$ticket_ids = array_values( array_filter( array_map( 'absint', (array) $ticket_ids ) ) );
			$filter = $ticket_ids ? ' AND ticket_id IN (' . implode( ',', $ticket_ids ) . ')' : ' AND 1=0';
		}
		$candidates = $wpdb->get_results( $wpdb->prepare( "SELECT ticket_id,attendee_email FROM {$p}wpcap_tickets WHERE order_id=%d AND status='valid'{$filter} ORDER BY ticket_id", (int) $order->order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		if ( '' !== $wpdb->last_error ) {
			return new \WP_Error( 'wpcap_ticket_rotation_failed', __( 'Ticketcode konnte nicht erneuert werden.', 'pass-captain' ), array( 'status' => 503, 'retryable' => true ) );
		}
		if ( ! $candidates ) {
			return new \WP_Error( 'wpcap_no_resendable_tickets', __( 'Keine gültigen Tickets für den erneuten Versand gefunden.', 'pass-captain' ) );
		}
		$groups = array();
		foreach ( $candidates as $ticket ) {
			$groups[ self::code_recipient( $order, $ticket, $to_buyer ) ][] = (int) $ticket->ticket_id;
		}
		$sent = 0;
		$error = null;
		foreach ( $groups as $recipient => $ids ) {
			$result = self::rotate_and_send_group( $order, (string) $recipient, $ids, $reason, $to_buyer );
			if ( is_wp_error( $result ) ) {
				$error = $error ?: $result;
				continue;
			}
			$sent += $result;
		}
		if ( $error ) {
			$data = (array) $error->get_error_data();
			$data['sent'] = $sent;
			return new \WP_Error( $error->get_error_code(), $error->get_error_message(), $data );
		}
		return $sent;
	}

	/** Recipient of a ticket's code mail: the holder (attendee e-mail), else the buyer. */
	private static function code_recipient( $order, $ticket, $to_buyer ) {
		return ! $to_buyer && is_email( $ticket->attendee_email ) ? (string) $ticket->attendee_email : (string) $order->customer_email;
	}

	/**
	 * One recipient of rotate_and_send(): locks the ticket rows, rotates each code by a conditional
	 * UPDATE on its previous hash (exactly one row, else rollback), mails the new links and commits
	 * only after the mail was accepted. wpcap_ticket_code_rotated fires inside the transaction so
	 * listeners (wallet passes) can add their links to the mail; their writes roll back with it.
	 *
	 * @return int|\WP_Error Number of rotated and mailed tickets (0 when none is left for this recipient).
	 */
	private static function rotate_and_send_group( $order, $recipient, array $ids, $reason, $to_buyer ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$fail = static function ( $code, $message, $step ) use ( $wpdb ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( $code, $message, array( 'status' => 503, 'retryable' => true, 'step' => $step ) );
		};
		$rotation_failed = __( 'Ticketcode konnte nicht erneuert werden.', 'pass-captain' );
		if ( false === self::begin() ) { return $fail( 'wpcap_ticket_rotation_failed', $rotation_failed, 'start' ); }
		// Only the ticket rows are locked (not ticket types/occurrences), so checkouts are not blocked while the mail is sent.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ticket_id,token_hash,attendee_email FROM %i WHERE order_id=%d AND status='valid' AND ticket_id IN (" . implode( ',', array_fill( 0, count( array_map( 'absint', $ids ) ), '%d' ) ) . ') ORDER BY ticket_id FOR UPDATE', array_merge( array( $p . 'wpcap_tickets', (int) $order->order_id ), array_map( 'absint', $ids ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		if ( '' !== $wpdb->last_error ) { return $fail( 'wpcap_ticket_rotation_failed', $rotation_failed, 'lock' ); }
		$rotated = array();
		foreach ( $rows as $row ) {
			// The holder changed in the meantime (transfer/assignment mailed its own new code): skip.
			if ( self::code_recipient( $order, $row, $to_buyer ) !== $recipient ) { continue; }
			$token = bin2hex( random_bytes( 32 ) );
			$updated = $wpdb->query( $wpdb->prepare( "UPDATE %i SET token_hash=%s WHERE ticket_id=%d AND status='valid' AND token_hash=%s", $p . 'wpcap_tickets', hash( 'sha256', $token ), (int) $row->ticket_id, (string) $row->token_hash ) );
			if ( 1 !== (int) $updated ) { return $fail( 'wpcap_ticket_rotation_failed', $rotation_failed, 'rotate' ); }
			$rotated[ (int) $row->ticket_id ] = $token;
		}
		if ( ! $rotated ) {
			$wpdb->query( 'COMMIT' );
			return 0;
		}
		$info = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT t.ticket_id,tt.name AS ticket_name,e.name AS event_name,o.starts_at,o.timezone,o.location FROM %i t INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id INNER JOIN %i e ON e.event_id=o.event_id WHERE t.ticket_id IN (' . implode( ',', array_fill( 0, count( array_keys( $rotated ) ), '%d' ) ) . ') ORDER BY t.ticket_id', array_merge( array( $p . 'wpcap_tickets', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $p . 'wpcap_events' ), array_keys( $rotated ) ) ) ) as $row ) {
			$info[ (int) $row->ticket_id ] = $row;
		}
		if ( '' !== $wpdb->last_error || count( $info ) !== count( $rotated ) ) { return $fail( 'wpcap_ticket_rotation_failed', $rotation_failed, 'read' ); }
		$entries = array();
		foreach ( $rotated as $ticket_id => $token ) {
			/** A ticket received a new code; the old one is invalid. $token is plaintext – never store it. */
			do_action( 'wpcap_ticket_code_rotated', $ticket_id, $token, $reason );
			$ticket = $info[ $ticket_id ];
			$entries[] = array( 'event' => $ticket->event_name, 'ticket' => $ticket->ticket_name, 'when' => self::format_start( $ticket->starts_at, $ticket->timezone ), 'location' => $ticket->location, 'url' => add_query_arg( 'wpcap_ticket', $token, home_url( '/' ) ), 'extra' => (string) apply_filters( 'wpcap_ticket_code_mail_section', '', $ticket_id ) );
		}
		$vars = self::ticket_mail_vars( $order, $entries, static function () use ( $entries ) { return Mail\Blocks::code_tickets( $entries ); } );
		if ( ! Mail\Mailer::send( 'tickets_resend', $recipient, $vars, array(), (string) $order->locale ) ) {
			return $fail( 'wpcap_mail_failed', __( 'Mindestens eine E-Mail konnte nicht zugestellt werden. Die bisherigen Ticketcodes bleiben gültig; bitte versuche den Versand erneut.', 'pass-captain' ), 'mail' );
		}
		if ( false === self::in_transaction() || false === $wpdb->query( 'COMMIT' ) ) {
			// The mail went out but its codes were not stored: the old codes stay valid; a retry sends working ones.
			return $fail( 'wpcap_ticket_rotation_failed', $rotation_failed, 'commit' );
		}
		return count( $rotated );
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
		self::begin();
		$ticket = $wpdb->get_row( $wpdb->prepare(
			'SELECT t.ticket_id,t.order_id,t.status,t.attendee_email,tt.name AS ticket_name,e.event_id,e.name AS event_name,o.starts_at,o.timezone,o.location FROM %i t INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id INNER JOIN %i e ON e.event_id=o.event_id WHERE t.ticket_id=%d FOR UPDATE', $p . 'wpcap_tickets', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $p . 'wpcap_events',
			$ticket_id
		) );
		$order = $ticket ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d', $p . 'wpcap_orders', (int) $ticket->order_id ) ) : null;
		if ( ! $ticket || ! $order ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_ticket_missing', __( 'Ticket nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) );
		}
		if ( 'valid' !== $ticket->status || 'paid' !== $order->payment_status ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_ticket_not_reissuable', __( 'Nur gültige, nicht eingecheckte Tickets bezahlter Bestellungen können neu ausgestellt werden.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$token = bin2hex( random_bytes( 32 ) );
		$updated = $wpdb->update( "{$p}wpcap_tickets", array( 'token_hash' => hash( 'sha256', $token ) ), array( 'ticket_id' => $ticket_id, 'status' => 'valid' ), array( '%s' ), array( '%d', '%s' ) );
		if ( 1 !== (int) $updated ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_ticket_rotation_failed', __( 'Ticketcode konnte nicht erneuert werden.', 'pass-captain' ), array( 'status' => 500 ) );
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

// Job handler (issuance retries, ticket delivery outbox); see Orders::register().
Orders::register();

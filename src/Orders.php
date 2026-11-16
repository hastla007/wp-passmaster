<?php
/** Order, inventory hold and ticket issuance services. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Orders {
	const HOLD_MINUTES = 10;
	/** Length of the e-mail columns (customer_email, attendee_email: varchar(190)). */
	const EMAIL_MAX_LENGTH = 190;

	/**
	 * Minimum hold for hosted payment pages (Stripe, Mollie, PayPal). Stripe Checkout Sessions live
	 * at least 30 minutes and end exactly with the hold (A577), so the hold is one minute longer.
	 */
	const HOSTED_HOLD_MINUTES = 31;

	/** Assumed duration of an occurrence without end time (hours); A606: Occurrence::DEFAULT_DURATION_HOURS. */
	const DEFAULT_OCCURRENCE_HOURS = Occurrence::DEFAULT_DURATION_HOURS;

	/**
	 * End of an occurrence (UTC, Y-m-d H:i:s). A606: delegates to Occurrence::effective_end() (ends_at,
	 * else start + the configured default duration; SQL: Occurrence::end_sql()), the one definition of
	 * "over" for sales, listings, scanners, wallet passes, calendar files and retention.
	 *
	 * @param object $occurrence Row with starts_at and ends_at.
	 */
	public static function occurrence_end( $occurrence ) {
		return Occurrence::effective_end( $occurrence );
	}

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
	 *                        defer_attendees (bool: required participant fields are not enforced yet –
	 *                        waitlist offers collect them on acceptance, Waitlists::accept_offer()).
	 *                        comp (bool; staff only, see Sales\Comps): complimentary tickets / guest list –
	 *                        price 0, payment provider "comp", no campaigns, terms, rate limit, checkout gate,
	 *                        buyer limits, max-per-order or online sales window, attendee data may follow in
	 *                        the portal; capacity (types, occurrence, modules) applies as for every order.
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
		$payload = is_array( $payload ) ? $payload : array();
		$email = Http::email( $payload['email'] ?? '' );
		$lines = $payload['items'] ?? array();
		if ( Http::email_too_long( $email ) ) {
			// A493: refused at the entrance (400) instead of failing at the varchar(190) column (503 db_busy).
			return new \WP_Error( 'wpcap_invalid_checkout', self::email_too_long_message(), array( 'status' => 400, 'reason' => 'email_too_long', 'field' => 'email' ) );
		}
		if ( ! is_email( $email ) || ! is_array( $lines ) || empty( $lines ) || count( $lines ) > 10 ) {
			return new \WP_Error( 'wpcap_invalid_checkout', __( 'Bitte eine gültige E-Mail-Adresse und Tickets auswählen.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		// customer_email is varchar(190): a longer address passes is_email() but would fail the order insert.
		if ( strlen( $email ) > self::EMAIL_MAX_LENGTH ) {
			return self::email_too_long( 'email' );
		}

		$key = \PassCaptain\Text::cut( sanitize_text_field( Http::str( $payload['idempotency_key'] ?? '' ) ), 191 );
		if ( '' === $key ) {
			$key = wp_generate_uuid4();
		}
		$namespace = sanitize_key( $internal['key_namespace'] ?? '' );
		// Channel keys use a keyed hash so a public caller can never claim a POS/WooCommerce key.
		$key_hash = '' === $namespace ? hash( 'sha256', $key ) : hash_hmac( 'sha256', $key, 'wpcap-channel-' . $namespace );
		// Trusted adapter options (e.g. WooCommerce): the external system owns price and payment.
		$external = ! empty( $internal['external'] );
		$comp = ! $external && ! empty( $internal['comp'] );
		if ( $comp ) {
			$internal['payment_provider'] = 'comp';
			$internal['source'] = 'comp';
			$internal['skip_rate_limit'] = true;
			$internal['skip_buyer_limits'] = true;
			$internal['allow_door_sales'] = true;
			$payload['campaign_codes'] = array();
			// Own keyed idempotency namespace: a public caller can never claim a guest-list key.
			$namespace = 'comp';
			$key_hash = hash_hmac( 'sha256', $key, 'wpcap-channel-comp' );
		}
		$hold_minutes = max( 1, absint( $internal['hold_minutes'] ?? Settings::get( 'hold_minutes' ) ?? self::HOLD_MINUTES ) );
		$line_taxes = ( $external && is_array( $internal['line_taxes'] ?? null ) ) ? array_map( 'absint', $internal['line_taxes'] ) : array();
		$payment_provider = sanitize_key( $internal['payment_provider'] ?? ( $external ? ( $internal['source'] ?? 'external' ) : 'manual' ) );
		if ( ! $external && ! $comp && Settings::get( 'require_terms' ) && ! Http::accepted( $payload['accept_terms'] ?? null ) ) {
			return new \WP_Error( 'wpcap_terms_required', __( 'Bitte bestätige die Bedingungen und Verbraucherinformationen.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		// Buyer language: the locale the checkout form was rendered in (legal texts, mails), else the request locale.
		// W9: an explicit buyer language is only taken when the site offers it (Localization::order_locale()).
		$order_locale = Mail\Mailer::normalize_locale( Localization::order_locale( is_string( $payload['locale'] ?? null ) ? $payload['locale'] : '' ) );
		if ( '' === $order_locale ) { $order_locale = function_exists( 'determine_locale' ) ? (string) determine_locale() : ''; }
		$terms_version = $external ? '' : Settings::terms_version( $order_locale );
		$unit_prices = ( $external && is_array( $internal['unit_prices'] ?? null ) ) ? array_map( 'absint', $internal['unit_prices'] ) : array();
		// Exact gross line totals from the external system (unit prices may not divide evenly).
		$line_totals = ( $external && is_array( $internal['line_totals'] ?? null ) ) ? array_map( 'absint', $internal['line_totals'] ) : array();
		$external_currency = $external ? strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) ( $internal['currency'] ?? '' ) ) ) : '';
		// An internal retry was already counted (and admitted by the checkout gate) as one request.
		$skip_rate_limit = $external || ! empty( $internal['skip_rate_limit'] ) || $attempt > 0;
		// A576: unit prices the buyer was shown (type_id => minor units). Only an upper bound: a price that
		// rose since the page was rendered is refused (409 price_changed); a lower one is charged as is.
		$expected_prices = array();
		if ( ! $external && is_array( $payload['expected_prices'] ?? null ) ) {
			foreach ( $payload['expected_prices'] as $expected_type => $expected_minor ) {
				$expected_type = Http::positive_int( $expected_type );
				if ( $expected_type && ( is_int( $expected_minor ) || ( is_string( $expected_minor ) && ctype_digit( $expected_minor ) ) ) ) {
					$expected_prices[ $expected_type ] = (int) $expected_minor;
				}
			}
		}
		$changed_prices = array();
		$orders_table = $wpdb->prefix . 'wpcap_orders';
		// A420: a public checkout key belongs to exactly one cart (schema 3.10.25; '' before the upgrade).
		$request_hash = ! $external && '' === $namespace && Log::has_column( 'wpcap_orders', 'request_hash' ) ? self::request_hash( $email, $lines, $payload ) : '';
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE idempotency_key = %s', $orders_table, $key_hash ) );
		if ( '' !== $wpdb->last_error ) {
			// Unknown whether an order with this key exists: never create a second one.
			return Errors::busy();
		}
		if ( $existing ) {
			if ( '' !== $request_hash && '' !== (string) ( $existing->request_hash ?? '' ) && ! hash_equals( (string) $existing->request_hash, $request_hash ) ) {
				return self::key_reused_error();
			}
			return self::reused_result( $existing );
		}
		// The form names the version it showed: accepting texts that changed meanwhile is no valid consent.
		// data.terms_version is the current version; the checkout script then reloads the texts in place
		// (GET /checkout/options), because a cached page would show the old ones again (A457).
		$shown_version = is_string( $payload['terms_version'] ?? null ) ? $payload['terms_version'] : '';
		if ( ! $external && Settings::get( 'require_terms' ) && '' !== $shown_version && $shown_version !== $terms_version ) {
			return new \WP_Error( 'wpcap_terms_changed', __( 'Die Bedingungen wurden inzwischen geändert. Bitte lade die Seite neu und bestätige die aktuelle Fassung.', 'pass-captain' ), array( 'status' => 409, 'terms_version' => $terms_version, 'locale' => $order_locale ) );
		}
		// A568: the wording behind the stored version stays provable (archive, append-only).
		if ( '' !== $terms_version ) { LegalVersions::archive( $order_locale ); }

		// The per-type limit is the ticket type's max_per_order (checked under lock below); this is
		// only an absolute safety cap against absurd quantities before any row is locked.
		$line_cap = max( 1, (int) apply_filters( 'wpcap_max_line_quantity', 100 ) );
		$clean_lines = array();
		$shown_state = array();
		foreach ( $lines as $line ) {
			// Strict: booleans, negatives, fractions and arrays are invalid (absint() would turn -2 into 2).
			$type_id = is_array( $line ) ? Http::positive_int( $line['ticket_type_id'] ?? 0 ) : 0;
			$quantity = is_array( $line ) ? Http::positive_int( $line['quantity'] ?? 0 ) : 0;
			if ( ! $type_id || $quantity < 1 || $quantity > $line_cap ) {
				return new \WP_Error( 'wpcap_invalid_item', __( 'Eine Ticketposition ist ungültig.', 'pass-captain' ), array( 'status' => 400 ) );
			}
			$clean_lines[ $type_id ] = ( $clean_lines[ $type_id ] ?? 0 ) + $quantity;
			if ( $clean_lines[ $type_id ] > $line_cap ) {
				return new \WP_Error( 'wpcap_invalid_item', __( 'Eine Ticketposition ist ungültig.', 'pass-captain' ), array( 'status' => 400 ) );
			}
			// A560: the state the buyer saw (occurrence revision, displayed unit price); optional.
			if ( ! $external ) {
				foreach ( array( 'occurrence_revision' => 'revision', 'expected_price_minor' => 'price' ) as $field => $slot ) {
					if ( isset( $line[ $field ] ) && is_scalar( $line[ $field ] ) && preg_match( '/^\d{1,12}$/', (string) $line[ $field ] ) ) { $shown_state[ $type_id ][ $slot ] = (int) $line[ $field ]; }
				}
			}
		}
		// Add-on gate (e.g. virtual waiting room) for public checkouts only: not for channels with their own
		// namespace (box office, waitlist offers) or trusted adapters (WooCommerce, vouchers). Sales\CheckoutGate.
		if ( ! $external && '' === $namespace && 0 === $attempt ) {
			$gate = Sales\CheckoutGate::check( array( 'source' => $internal['source'] ?? 'native', 'phase' => 'checkout', 'items' => $clean_lines, 'email' => $email, 'idempotency_key' => $key, 'request' => $internal['request'] ?? null ) );
			if ( is_wp_error( $gate ) ) { return $gate; }
		}
		// A552: "same as buyer" per ticket takes the buyer address and the billing name (if given).
		$buyer_billing = is_array( $internal['billing'] ?? null ) ? $internal['billing'] : ( is_array( $payload['billing'] ?? null ) ? $payload['billing'] : array() );
		$buyer = array( 'email' => $email, 'name' => trim( Http::str( $buyer_billing['first_name'] ?? '' ) . ' ' . Http::str( $buyer_billing['last_name'] ?? '' ) ) );
		$attendees = self::validate_attendees(
			$clean_lines,
			$payload['attendees'] ?? array(),
			array(
				'defer_required' => ! empty( $internal['defer_attendees'] ),
				'allow_missing' => $comp || ( $external && ! empty( $internal['attendees_deferred'] ) ),
				'skip_required' => $comp,
				'buyer' => $buyer,
				'strict' => ! empty( $internal['strict_attendees'] ),
			)
		);
		if ( is_wp_error( $attendees ) ) { return $attendees; }
		// Consent proof (A116): text version shown, time, source, locale; recorded at issuance.
		$consent_source = Consents::source_for_channel( $internal['source'] ?? $namespace );
		$attendees = Consents::annotate( $attendees, $consent_source, $order_locale, 'checkout' === $consent_source && ! $external ? Consents::ip_hash( self::client_ip() ) : '' );
		// Buyer billing data (company/address/VAT ID). Trusted adapters (e.g. WooCommerce) pass data that
		// their own checkout already validated as $internal['billing']; customer input is validated here.
		$billing = ( $external && is_array( $internal['billing'] ?? null ) ) ? Billing::sanitize( $internal['billing'] ) : Billing::validate( $payload['billing'] ?? null );
		if ( is_wp_error( $billing ) ) { return $billing; }
		$billing = $billing && ! Billing::is_empty( $billing ) ? $billing : array();
		// A331: own checkout rules of add-ons/modules, before anything is locked or written.
		$veto = self::checkout_veto( $email, $clean_lines, $payload, $internal, $namespace, $external );
		if ( is_wp_error( $veto ) ) { return $veto; }

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
		$now = Clock::mysql();
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
		$entered_codes = Sales\Visibility::input_codes( is_array( $payload['campaign_codes'] ?? null ) ? $payload['campaign_codes'] : array() );
		$public_checkout = ! $external && '' === $namespace;
		$commit_unknown = false;
		self::begin();
		try {
			ksort( $clean_lines, SORT_NUMERIC );
			// Lock phase (locking reads only, so the transaction's consistent snapshot is taken after
			// every lock is held): ticket types by id, then their occurrences by id, then module
			// resources via `wpcap_reserve_capacity`. Late payment recovery uses the same order.
			$types = array();
			foreach ( $clean_lines as $type_id => $quantity ) {
				$type = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE ticket_type_id = %d FOR UPDATE', $types_table, $type_id ) );
				// A failed lock (deadlock, lock wait timeout, lost connection) is no "unavailable".
				if ( '' !== $wpdb->last_error ) { throw new \RuntimeException( 'db_busy' ); }
				if ( ! $type || 'active' !== $type->status ) {
					throw new \RuntimeException( 'ticket_type_unavailable' );
				}
				if ( ! $comp && $quantity > (int) $type->max_per_order ) {
					throw new \RuntimeException( 'quantity_limit' );
				}
				$types[ $type_id ] = $type;
			}
			// Hidden / code-only ticket types (Sales\Visibility): the public checkout needs an unlock code; other
			// channels are trusted. The type's own code list is checked here (no query in the lock phase); a
			// type unlocked by a campaign code is checked after Campaigns::calculate() (locked campaign rows).
			$locked_types = array();
			if ( $public_checkout ) {
				foreach ( $types as $type_id => $type ) {
					$visibility = Sales\Visibility::of( $type );
					if ( 'hidden' === $visibility ) { throw new \RuntimeException( 'ticket_type_unavailable' ); }
					if ( 'code' === $visibility && ! array_intersect( $entered_codes, Sales\Visibility::codes( $type ) ) ) { $locked_types[ $type_id ] = true; }
				}
			}
			$occurrence_ids = array_unique( array_map( static function ( $type ) { return (int) $type->occurrence_id; }, $types ) );
			sort( $occurrence_ids, SORT_NUMERIC );
			$occurrences = array();
			foreach ( $occurrence_ids as $occurrence_id ) {
				$occurrences[ $occurrence_id ] = $wpdb->get_row( $wpdb->prepare(
					'SELECT o.*,e.name AS event_name,e.status AS event_status FROM %i o INNER JOIN %i e ON e.event_id=o.event_id WHERE o.occurrence_id=%d FOR UPDATE', $occ_table, $event_table,
					$occurrence_id
				) );
				if ( '' !== $wpdb->last_error ) { throw new \RuntimeException( 'db_busy' ); }
			}
			$currency = '';
			foreach ( $types as $type_id => $type ) {
				$occurrence = $occurrences[ (int) $type->occurrence_id ];
				// Door sales (box office) may sell after the start and outside the online sales window,
				// but never for an occurrence that has ended. Online sales end at the start, or – with a
				// sales_end after the start (multi-day occurrences) – at that sales_end, never after the
				// effective end (Occurrence, A535).
				$closed = $occurrence && ( $door_sale ? Occurrence::is_over( $occurrence ) : Occurrence::online_sales_over( $occurrence, $type ) );
				if ( ! $occurrence || 'published' !== $occurrence->event_status || 'scheduled' !== $occurrence->status || $closed ) {
					throw new \RuntimeException( 'occurrence_unavailable' );
				}
				if ( ! $door_sale && ( ( $type->sales_start && $type->sales_start > $now ) || ( $type->sales_end && $type->sales_end < $now ) ) ) {
					throw new \RuntimeException( 'sales_closed' );
				}
				if ( isset( $shown_state[ $type_id ] ) ) {
					// A560: checked under the occurrence lock, so a reschedule cannot slip in between.
					$custom_error = self::shown_state_error( $shown_state[ $type_id ], $type, $occurrence, $now );
					if ( $custom_error ) { throw new \RuntimeException( (string) $custom_error->get_error_data()['reason'] ); }
				}
				if ( $external && 3 === strlen( $external_currency ) ) {
					$currency = $external_currency;
				} elseif ( '' === $currency ) {
					$currency = $type->currency;
				} elseif ( $currency !== $type->currency ) {
					throw new \RuntimeException( 'mixed_currency' );
				}
			}
			$capacity_context = array( 'phase' => 'create', 'order_id' => 0, 'types' => $types, 'occurrences' => $occurrences, 'now' => $now, 'source' => sanitize_key( $internal['source'] ?? ( '' !== $namespace ? $namespace : 'native' ) ), 'email' => $email, 'user_id' => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0, 'internal' => $internal );
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
				$base_price = (int) $type->price_minor;
				$price_rule = null;
				if ( $comp ) {
					$unit_price = 0;
				} elseif ( array_key_exists( $type_id, $unit_prices ) ) {
					$unit_price = (int) $unit_prices[ $type_id ];
				} else {
					$unit_price = self::unit_price( $type, $occurrence, $quantity, array( 'now' => $now, 'source' => $capacity_context['source'], 'phase' => 'create' ) );
					if ( $unit_price !== (int) $type->price_minor ) {
						$price_rules[ $type_id ] = array( 'base_minor' => (int) $type->price_minor, 'unit_minor' => $unit_price );
					}
					// Automatic price rule (Sales\PriceRules) frozen with the line: rule, explanation, prices.
					$price_rule = Sales\PriceRules::applied( $type_id );
					// A576: never charge more than the buyer was shown (early bird ended, demand tier, cached page).
					if ( isset( $expected_prices[ $type_id ] ) && $unit_price > $expected_prices[ $type_id ] ) {
						$changed_prices[ $type_id ] = array( 'shown_minor' => $expected_prices[ $type_id ], 'unit_minor' => $unit_price, 'formatted' => Frontend\Format::money( $unit_price, $type->currency ), 'name' => (string) $type->name );
					}
				}
				// Versioned tax profile (Sales\TaxProfiles) unless a trusted adapter passed the line tax.
				$tax_profile = ( $comp || array_key_exists( $type_id, $line_taxes ) ) ? null : Sales\TaxProfiles::for_line( $type, $occurrence, $now );
				$type->price_minor = $unit_price;
				$line_total = array_key_exists( $type_id, $line_totals ) ? (int) $line_totals[ $type_id ] : $unit_price * $quantity;
				$total += $line_total;
				$selected[] = array( 'type' => $type, 'event_id' => (int) $occurrence->event_id, 'quantity' => $quantity, 'line_total' => $line_total, 'base_minor' => $base_price, 'price_rule' => $price_rule, 'tax_profile' => $tax_profile );
			}
			if ( $changed_prices ) {
				$custom_error = self::price_changed_error( $changed_prices );
				throw new \RuntimeException( 'price_changed' );
			}
			$subtotal = $total;
			$campaign_codes = $external ? array() : ( $payload['campaign_codes'] ?? array() );
			if ( ! is_array( $campaign_codes ) ) { throw new \RuntimeException( 'campaign_invalid' ); }
			// Pure unlock codes (code list of a selected type) are no discount codes.
			$unlock_only = $public_checkout ? Sales\Visibility::unlock_only_codes( array_keys( $occurrences ), $entered_codes ) : array();
			if ( $unlock_only ) {
				$campaign_codes = array_values( array_filter( $campaign_codes, static function ( $code ) use ( $unlock_only ) { return ! in_array( Campaigns::normalize_code( $code ), $unlock_only, true ); } ) );
			}
			// Automatic campaigns (no code) only for orders that pay the core price, never for adapters or comps.
			$adjustments = Campaigns::calculate( $selected, $campaign_codes, $currency, array( 'auto' => ! $external && ! $comp, 'email' => $email ) );
			if ( is_wp_error( $adjustments ) ) {
				// Stacking rules (combinable, maximum discount, minimum quantity, per-buyer limit) explain themselves; other errors stay generic.
				if ( in_array( $adjustments->get_error_code(), array( 'wpcap_campaign_not_combinable', 'wpcap_campaign_max_discount', 'wpcap_campaign_min_quantity', 'wpcap_campaign_buyer_limit' ), true ) ) {
					$custom_error = $adjustments;
					throw new \RuntimeException( $adjustments->get_error_code() );
				}
				throw new \RuntimeException( 'campaign_invalid' );
			}
			foreach ( $adjustments as $adjustment ) {
				if ( empty( $adjustment['auto'] ) ) { unset( $locked_types[ (int) $adjustment['campaign']->ticket_type_id ] ); }
			}
			if ( $locked_types ) { throw new \RuntimeException( 'ticket_type_unavailable' ); }
			$discount = array_sum( array_map( static function ( $adjustment ) { return (int) $adjustment['amount_minor']; }, $adjustments ) );
			$total = max( 0, $subtotal - $discount );
			// Trusted channels (box office "invoice", partner API) ask for the same rule via require_billing.
			if ( ( ! $external || ! empty( $internal['require_billing'] ) ) && ! $billing && Billing::required_for( $total, $currency ) ) { throw new \RuntimeException( 'billing_required' ); }
			// A634: unpaid bank transfer orders of the public checkout (payment method chosen by the buyer, no
			// trusted channel) hold their units for days; together they may hold at most a share of each
			// ticket type and date. Checked under the type/occurrence locks above, so concurrent checkouts
			// cannot exceed it together. Staff channels (admin holds, box office, partners) are not limited.
			if ( 'manual' === $payment_provider && isset( $internal['payment_provider'] ) && ! $external && ! $comp && ! $skip_rate_limit && '' === $namespace && $total > 0 ) {
				$manual_error = self::manual_hold_error( $clean_lines, $types, $occurrences, $now );
				if ( '' !== $manual_error ) { throw new \RuntimeException( $manual_error ); }
			}
			// Allocate each campaign's discount to the lines it applies to (proportionally, exact to the
			// minor unit; Campaigns::calculate()) and compute VAT per line on the discounted gross amount.
			// The per-line discounts are kept in the price snapshot and on the order line (A007), so
			// invoices and refunds use the same split.
			$line_discounts = self::line_discounts( $selected, $adjustments );
			$tax_total = 0;
			foreach ( $selected as $index => $line ) {
				$line_discount = min( (int) $line['line_total'], max( 0, (int) ( $line_discounts[ $index ] ?? 0 ) ) );
				$selected[ $index ]['discount_minor'] = $line_discount;
				$type_id_for_tax = (int) $line['type']->ticket_type_id;
				// A115: rate from the active country tax profile (venue country, tax class, sale date) or
				// the ticket type's own rate; a VAT amount fixed by the channel keeps the type's rate.
				$tax_occurrence = $occurrences[ (int) $line['type']->occurrence_id ] ?? null;
				// A versioned profile of Sales\TaxProfiles (event country, date of supply) wins; it is mirrored
				// into the Tax\Profiles line columns so both views of the line agree.
				if ( array_key_exists( $type_id_for_tax, $line_taxes ) ) {
					$tax_info = array( 'rate_bp' => (int) ( $line['type']->tax_rate_bp ?? 0 ), 'source' => Tax\Profiles::SOURCE_EXTERNAL, 'profile_id' => 0, 'version' => '', 'country' => Tax\Profiles::venue_country( $tax_occurrence ) );
				} elseif ( $line['tax_profile'] ) {
					$tax_info = array( 'rate_bp' => (int) $line['tax_profile']['rate_bp'], 'source' => Tax\Profiles::SOURCE_PROFILE, 'profile_id' => (int) $line['tax_profile']['tax_profile_id'], 'version' => (string) ( $line['tax_profile']['snapshot']['version'] ?? '' ), 'country' => (string) ( $line['tax_profile']['snapshot']['country'] ?? Tax\Profiles::venue_country( $tax_occurrence ) ) );
				} else {
					$tax_info = Tax\Profiles::resolve( $line['type'], $tax_occurrence, $now, $capacity_context['source'] );
				}
				$rate = (int) $tax_info['rate_bp'];
				$selected[ $index ]['tax_rate_bp'] = $rate;
				$selected[ $index ]['tax_info'] = $tax_info;
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
				'access_expires_at' => gmdate( 'Y-m-d H:i:s', Clock::now() + 30 * DAY_IN_SECONDS ),
				'status' => ( $total || $external ) ? 'pending_payment' : 'complete',
				'payment_status' => ( $total || $external ) ? 'pending' : 'paid',
				'currency' => $currency,
				'total_minor' => $total,
				'subtotal_minor' => $subtotal,
				'discount_minor' => $discount,
				'price_snapshot' => $price_snapshot,
				'tax_minor' => $tax_total,
				'payment_provider' => $payment_provider,
				'terms_version' => $comp ? '' : $terms_version,
				'locale' => $order_locale, // Buyer's explicit language choice (W9) or request locale.
				'attendee_data' => $attendees ? wp_json_encode( $attendees ) : null,
				'created_at' => $now,
				'updated_at' => $now,
			);
			// Only written when given, so checkouts keep working while a deferred schema upgrade is pending.
			if ( $billing ) { $order_row['billing'] = wp_json_encode( $billing ); }
			if ( $customer_user_id ) { $order_row['customer_user_id'] = $customer_user_id; }
			// Marketing attribution of public checkouts (assets/tracking.js; Privacy\Retention clears it).
			$attribution = $public_checkout ? self::attribution( $payload['attribution'] ?? null ) : '';
			if ( '' !== $attribution ) { $order_row['attribution'] = $attribution; }
			// Optional buyer name (checkout field "Dein Name"); only once the column exists (DB 3.17.5).
			$buyer_name = is_scalar( $payload['buyer_name'] ?? '' ) ? mb_substr( sanitize_text_field( (string) ( $payload['buyer_name'] ?? '' ) ), 0, 190 ) : '';
			if ( '' !== $buyer_name && self::schema_at_least( '3.17.5' ) ) { $order_row['customer_name'] = $buyer_name; }
			// Fingerprint of the attempt (Orders::request_fingerprint()); a retry with the same key is compared against it.
			// Not while a deferred schema upgrade (DB 3.12.0) is still pending: the column may not exist yet.
			if ( ! empty( $internal['request_fingerprint'] ) && preg_match( '/^[a-f0-9]{64}$/', (string) $internal['request_fingerprint'] ) && version_compare( strtok( (string) get_option( 'wpcap_db_version' ), '+' ), '3.12.0', '>=' ) ) { $order_row['request_fingerprint'] = (string) $internal['request_fingerprint']; }
			if ( '' !== $request_hash ) { $order_row['request_hash'] = $request_hash; }
			$inserted = $wpdb->insert( $orders_table, $order_row );
			if ( ! $inserted ) {
				// Only a duplicate idempotency key is a lost race; anything else is a transient error.
				throw new \RuntimeException( false !== stripos( (string) $wpdb->last_error, 'duplicate' ) ? 'order_insert_failed' : 'db_busy' );
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
				// Line snapshot: applied tax profile version and price rule (written only when present).
				$line_snapshot = array_filter( array( 'tax' => $line['tax_profile']['snapshot'] ?? null, 'price' => $line['price_rule'] ) );
				if ( $counted ) { $item['counted_qty'] = $pending_order ? 0 : (int) $line['quantity']; }
				if ( OrderLines::ready() ) { $item['discount_minor'] = (int) ( $line['discount_minor'] ?? 0 ); }
				$item += Tax\Profiles::line_columns( $line['tax_info'] );
				if ( $line['tax_profile'] ) { $item['tax_profile_id'] = (int) $line['tax_profile']['tax_profile_id']; }
				if ( $line_snapshot ) { $item['line_snapshot'] = wp_json_encode( $line_snapshot ); }
				$wpdb->insert( $items_table, $item );
				if ( '' !== $wpdb->last_error ) {
					throw new \RuntimeException( 'db_busy' );
				}
				if ( $pending_order ) {
					$hold = array(
						'order_id' => $order_id,
						'ticket_type_id' => $type_id,
						'quantity' => (int) $line['quantity'],
						'expires_at' => gmdate( 'Y-m-d H:i:s', Clock::now() + $hold_minutes * MINUTE_IN_SECONDS ),
						'status' => 'active',
					);
					if ( $counted ) { $hold['counted_qty'] = (int) $line['quantity']; }
					$wpdb->insert( $reservations_table, $hold );
					if ( '' !== $wpdb->last_error ) {
						throw new \RuntimeException( 'db_busy' );
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
			if ( is_wp_error( $redeemed ) ) { throw new \RuntimeException( 'wpcap_campaign_use_failed' === $redeemed->get_error_code() ? 'db_busy' : 'campaign_redeem_failed' ); }
			// A failure from here on may hide a commit (reply lost, reconnect): checked below. A
			// "successful" COMMIT that wpdb re-ran on a new session after a reconnect committed
			// nothing, so the order row is looked up once (primary key).
			if ( false === self::in_transaction() || false === $wpdb->query( 'COMMIT' ) || ! self::order_committed( $order_id, $key_hash ) ) {
				$commit_unknown = true;
				throw new \RuntimeException( 'db_busy' );
			}
		} catch ( \Throwable $error ) {
			// The server error is gone after ROLLBACK: classify it first (deadlock/lock wait timeout = busy;
			// support log, A322).
			$db_error = (string) $wpdb->last_error;
			// After a lost connection wpdb reconnects and runs the remaining statements outside the
			// transaction (autocommit) while the earlier ones were rolled back (A183). If the order row
			// exists, the connection was lost before its insert, so every later write (each one checked)
			// was committed: the order is complete. Otherwise the rows written after the loss are removed.
			$lost = ! empty( $order_id ) && false === self::in_transaction();
			$wpdb->query( 'ROLLBACK' );
			if ( ! ( $lost && $commit_unknown && self::order_committed( $order_id, $key_hash ) ) ) {
				Sales\BuyerLimits::release( $buyer_locks );
				Sales\CheckoutThrottle::refund( $throttle ); // No order: sold-out and other failed attempts do not use up the limit.
				if ( $lost ) { self::discard_lost_order( $order_id, $key_hash, $occurrence_ids ?? array() ); }
				return self::checkout_error( $error->getMessage(), $custom_error, $key_hash, $error, array( 'db_error' => $db_error, 'lost_connection' => $lost, 'commit_unknown' => $commit_unknown, 'source' => sanitize_key( $internal['source'] ?? ( '' !== $namespace ? $namespace : 'native' ) ), 'provider' => sanitize_key( (string) ( $internal['payment_provider'] ?? '' ) ), 'ticket_type_ids' => array_slice( array_keys( $clean_lines ), 0, 20 ), 'request_hash' => $request_hash ) );
			}
		}
		Sales\BuyerLimits::release( $buyer_locks );

		$pending = $total || $external;
		if ( $throttle ) { Sales\CheckoutThrottle::record( $throttle, $pending ); }
		/**
		 * A331: the order is committed (fires once per new order, never for a reused idempotency key),
		 * before the payment of a free order and before any mail. Handlers must not throw.
		 */
		do_action( 'wpcap_order_created', $order_id, array( 'source' => sanitize_key( $internal['source'] ?? ( '' !== $namespace ? $namespace : 'native' ) ), 'external' => $external, 'pending_payment' => (bool) $pending, 'total_minor' => (int) $total, 'currency' => (string) $currency ) );
		$issued = null;
		if ( ! $pending ) {
			Campaigns::commit_for_order( $order_id );
			// Same order as for paid orders (confirm_external_payment): wpcap_order_paid before the
			// issuance (e.g. seat recovery), wpcap_tickets_issued after it.
			do_action( 'wpcap_order_paid', $order_id );
			$issued = self::issue_and_email( $order_id );
		}
		if ( ! $external ) {
			self::send_order_access_link( $email, $public_id, $access_token );
		}
		Audit::record( 'order_created', 'order', $order_id, array( 'total_minor' => $total, 'currency' => $currency, 'source' => sanitize_key( $internal['source'] ?? 'native' ) ) );
		if ( is_wp_error( $issued ) ) {
			// The free order exists, its tickets do not yet: a retry with the same key issues them.
			return $issued;
		}
		return array( 'order_id' => $order_id, 'public_id' => $public_id, 'status' => $pending ? 'pending_payment' : 'complete', 'payment_status' => $pending ? 'pending' : 'paid', 'subtotal_minor' => $subtotal, 'discount_minor' => $discount, 'total_minor' => $total, 'currency' => $currency, 'hold_minutes' => $pending ? $hold_minutes : 0 );
	}

	/**
	 * Attribution from the checkout payload ({source, medium, campaign, ref}) as "source=…;medium=…":
	 * known keys only, values reduced to [a-z0-9_.-] and 64 characters (no personal data, no URLs).
	 */
	public static function attribution( $input ) {
		if ( ! is_array( $input ) ) { return ''; }
		$parts = array();
		foreach ( array( 'source', 'medium', 'campaign', 'ref' ) as $key ) {
			$value = is_scalar( $input[ $key ] ?? null ) ? substr( (string) preg_replace( '/[^a-z0-9_.\-]/', '', strtolower( (string) $input[ $key ] ) ), 0, 64 ) : '';
			if ( '' !== $value ) { $parts[] = $key . '=' . $value; }
		}
		return implode( ';', $parts );
	}

	/**
	 * Result of create() for a request whose idempotency key already has an order. A paid order whose
	 * issuance failed earlier (database error after the payment was booked) gets its missing tickets now.
	 */
	private static function reused_result( $existing ) {
		if ( 'paid' === $existing->payment_status ) {
			$issued = self::issue_and_email( (int) $existing->order_id );
			if ( is_wp_error( $issued ) ) { return $issued; }
		}
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
	 * Filter wpcap_checkout_validate (A331): own checkout rules (e.g. of an add-on) before the order
	 * transaction. Returning a WP_Error refuses the checkout with that error (status 400 unless the
	 * error names one); any other value lets it pass. Not called for a reused idempotency key.
	 *
	 * @param array $lines Ticket type id => quantity.
	 * @return true|\WP_Error
	 */
	private static function checkout_veto( $email, array $lines, array $payload, array $internal, $namespace, $external ) {
		$context = array(
			'email' => (string) $email,
			'items' => $lines,
			'payload' => $payload,
			'source' => sanitize_key( $internal['source'] ?? ( '' !== $namespace ? $namespace : 'native' ) ),
			'external' => (bool) $external,
		);
		$result = apply_filters( 'wpcap_checkout_validate', true, $context );
		if ( ! is_wp_error( $result ) ) { return true; }
		$data = $result->get_error_data();
		if ( is_array( $data ) && ! empty( $data['status'] ) ) { return $result; }
		return new \WP_Error( $result->get_error_code(), $result->get_error_message(), array_merge( is_array( $data ) ? $data : array(), array( 'status' => 400 ) ) );
	}

	/** Stores attendee name/e-mail and custom field answers for one ticket. */
	private static function store_attendee( $ticket_id, $order, array $attendee ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = Clock::mysql();
		// Stored attendee data of orders from before the entrance checks (A492/A493) may hold values that do
		// not fit the columns: they are dropped/shortened instead of blocking the issuance for good.
		$name = mb_substr( sanitize_text_field( Http::str( $attendee['name'] ?? '' ) ), 0, 190 );
		$email = Http::email( $attendee['email'] ?? '' );
		$email = Http::valid_email( $email ) ? $email : '';
		if ( $name || '' !== $email ) {
			if ( false === $wpdb->query( $wpdb->prepare( "INSERT INTO {$p}wpcap_ticket_assignments (ticket_id,buyer_email,attendee_email,attendee_name,status,created_at) VALUES (%d,%s,%s,%s,'assigned',%s) ON DUPLICATE KEY UPDATE attendee_email=VALUES(attendee_email),attendee_name=VALUES(attendee_name)", $ticket_id, $order->customer_email, $email, $name, $now ) ) ) { return false; }
			if ( '' !== $email && false === $wpdb->update( "{$p}wpcap_tickets", array( 'attendee_email' => $email ), array( 'ticket_id' => $ticket_id ) ) ) {
				return false;
			}
		}
		foreach ( (array) ( $attendee['answers'] ?? array() ) as $field_id => $value ) {
			if ( ! ParticipantFields::save_answer( $ticket_id, $field_id, (string) $value, $now ) ) { return false; }
		}
		Consents::record_checkout( $ticket_id, $order, $attendee );
		return '' === $wpdb->last_error;
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
	/**
	 * Tickets of the list that were used for entry: checked in now, or – for re-entry, multi-entry
	 * and pass tickets that stay "valid" – a check-in that was not undone, or an admission entry.
	 *
	 * @param int[] $ticket_ids Ticket IDs.
	 * @return int[] Used ticket IDs.
	 */
	public static function used_ticket_ids( array $ticket_ids ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ticket_ids ) ) ) );
		if ( ! $ids ) { return array(); }
		$in = implode( ',', $ids );
		$used = array_map( 'intval', $wpdb->get_col( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE ticket_id IN ($in) AND status='checked_in'" ) );
		$used = array_merge( $used, array_map( 'intval', $wpdb->get_col( "SELECT ticket_id FROM {$p}wpcap_checkins WHERE ticket_id IN ($in) AND result IN ('checked_in','undone') GROUP BY ticket_id HAVING SUM(result='checked_in') > SUM(result='undone')" ) ) );
		if ( Privacy::has_table( 'wpcap_admission_entries' ) ) {
			$used = array_merge( $used, array_map( 'intval', $wpdb->get_col( "SELECT DISTINCT ticket_id FROM {$p}wpcap_admission_entries WHERE ticket_id IN ($in) AND direction='in' AND undone=0" ) ) );
		}
		return array_values( array_intersect( $ids, array_unique( $used ) ) );
	}

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
	 * Client IP used for rate limits and consent records: ClientIp::get() – REMOTE_ADDR, or the forwarded
	 * client when REMOTE_ADDR is a trusted proxy (settings `trusted_proxies` / `client_ip_header`).
	 *
	 * The filter `wpcap_client_ip` can still override the result (custom setups); only resolve a header
	 * when REMOTE_ADDR is the trusted proxy, otherwise the header is forgeable:
	 *
	 *     add_filter( 'wpcap_client_ip', function ( $ip, $server ) {
	 *         $trusted = array( '203.0.113.10' ); // Proxy addresses.
	 *         if ( in_array( $ip, $trusted, true ) && ! empty( $server['HTTP_CF_CONNECTING_IP'] ) ) {
	 *             return $server['HTTP_CF_CONNECTING_IP'];
	 *         }
	 *         return $ip;
	 *     }, 10, 2 );
	 *
	 * Invalid return values fall back to the ClientIp result.
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

	/** Whether the stored schema version is at least $version (columns of a deferred upgrade may be missing). */
	public static function schema_at_least( $version ) {
		$stored = (string) get_option( 'wpcap_db_version', '' );
		return '' !== $stored && version_compare( (string) strtok( $stored, '+' ), (string) $version, '>=' );
	}

	/**
	 * Validates attendee data against the participant fields configured per ticket type.
	 * Only configured fields are kept (data minimisation); required fields must be filled for every ticket.
	 *
	 * Consent fields (A116) are checkboxes: a required consent must be ticked ('1') for every ticket.
	 * With $options['skip_consent'] consent fields are neither checked nor returned (order portal
	 * corrections; consents are granted/withdrawn there through Consents). With
	 * $options['defer_required'] required fields may stay empty (waitlist offer orders: the data is
	 * collected and fully validated when the offer is accepted, A536).
	 * $options['allow_missing']: tickets without any attendee entry are accepted even with required
	 * fields (trusted adapters only; entries that are given are still validated).
	 * $options['skip_required']: given entries may leave required fields empty (complimentary tickets:
	 * staff only know the guest's name; the guest completes the rest in the portal).
	 * For backwards compatibility the third/fourth parameters may also be the booleans
	 * ($allow_missing, $skip_required) of the earlier signature.
	 *
	 * Field types, choices, order-level fields and versions: ParticipantFields (A680). Ticket types
	 * with "attendee name required" need a name on every ticket (A552; $options['skip_name'] for
	 * corrections of answers alone); the name mode of a ticket type (attendee_name: off, optional,
	 * required, DB 3.17.5) is honoured as well ("off": the name is neither asked nor stored). An entry
	 * with `same_as_buyer` takes name/e-mail from $options['buyer'] (['email', 'name']). With
	 * $options['strict'] (A681) entries of other ticket types and unknown or deactivated field keys are
	 * refused instead of dropped.
	 *
	 * Errors carry which attendee is meant (A551): `ticket_type_id`, `position` (0-based n-th ticket
	 * of that type), `index` (0-based position in the submitted list, when that entry was sent) and
	 * `field_key` (participant field) in the error data.
	 *
	 * @param array      $lines         ticket_type_id => quantity.
	 * @param mixed      $attendees     Submitted list.
	 * @param array|bool $options       Options (see above) or the legacy $allow_missing flag.
	 * @param bool       $skip_required Legacy flag (only with a boolean third parameter).
	 * @return array|\WP_Error Normalised list [{ticket_type_id, name, email, answers{field_id:value}}].
	 */
	public static function validate_attendees( array $lines, $attendees, $options = array(), $skip_required = false ) {
		global $wpdb;
		if ( ! is_array( $options ) ) {
			$options = array( 'allow_missing' => (bool) $options, 'skip_required' => (bool) $skip_required );
		}
		$attendees = is_array( $attendees ) ? array_values( $attendees ) : array();
		$out = array();
		$skip_consent = ! empty( $options['skip_consent'] ) && Consents::ready();
		$defer_required = ! empty( $options['defer_required'] );
		$allow_missing = ! empty( $options['allow_missing'] );
		$skip_required_fields = $defer_required || ! empty( $options['skip_required'] );
		// A681: strict channels (WooCommerce) refuse what they cannot store instead of dropping it silently.
		$strict = ! empty( $options['strict'] );
		$buyer = is_array( $options['buyer'] ?? null ) ? $options['buyer'] : array();
		$name_modes = self::schema_at_least( '3.17.5' ); // attendee_name column of ticket types (DB 3.17.5).
		$known_types = array_map( 'intval', array_keys( $lines ) );
		if ( $strict ) {
			foreach ( $attendees as $index => $entry ) {
				if ( ! is_array( $entry ) || ! in_array( absint( $entry['ticket_type_id'] ?? 0 ), $known_types, true ) ) {
					return new \WP_Error( 'wpcap_attendees_invalid', __( 'Angaben zu Teilnehmenden passen nicht zu den bestellten Tickets.', 'pass-captain' ), array( 'status' => 400, 'index' => (int) $index, 'reason' => 'unknown_ticket_type' ) );
				}
			}
		}
		foreach ( $lines as $type_id => $quantity ) {
			$fields = $wpdb->get_results( $wpdb->prepare( 'SELECT ' . ParticipantFields::columns() . ",status FROM {$wpdb->prefix}wpcap_participant_fields WHERE ticket_type_id=%d" . ( $strict ? '' : " AND status='active'" ) . ( $skip_consent ? ' AND consent=0' : '' ) . ' ORDER BY position,field_id', $type_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- static column list.
			// Inactive fields are only read to tell "no longer offered" from "unknown" (A681).
			$inactive = array();
			// A683: fields locked for self-service are neither validated nor returned (stored value stays).
			$locked = array_map( 'strval', (array) ( $options['locked'] ?? array() ) );
			foreach ( $fields as $k => $field ) {
				if ( 'active' !== $field->status ) { $inactive[] = (string) $field->field_key; unset( $fields[ $k ] ); }
				elseif ( in_array( (string) $field->field_key, $locked, true ) ) { unset( $fields[ $k ] ); }
			}
			$fields = array_values( $fields );
			// Name per ticket: off (not asked, not stored), optional (default) or required (ticket type option).
			$name_mode = $name_modes ? self::attendee_name_mode( $wpdb->get_var( $wpdb->prepare( 'SELECT attendee_name FROM %i WHERE ticket_type_id=%d', $wpdb->prefix . 'wpcap_ticket_types', $type_id ) ) ) : 'optional';
			// Corrections of answers alone (ticket page, assignment) leave the name as it is: skip_name.
			$name_required = ! $defer_required && empty( $options['skip_name'] ) && ( 'required' === $name_mode || ParticipantFields::name_required( $type_id ) );
			$given = array_filter( $attendees, static function ( $a ) use ( $type_id ) { return is_array( $a ) && absint( $a['ticket_type_id'] ?? 0 ) === (int) $type_id; } );
			// Position of each entry in the submitted list: error data names the attendee (A551).
			$given_index = array_keys( $given );
			$given = array_values( $given );
			$where = static function ( $i, $field = null ) use ( $type_id, $given_index ) {
				$data = array( 'status' => 400, 'ticket_type_id' => (int) $type_id, 'position' => (int) $i );
				if ( isset( $given_index[ $i ] ) ) { $data['index'] = (int) $given_index[ $i ]; }
				if ( $field ) { $data['field_key'] = is_object( $field ) ? (string) $field->field_key : (string) $field; }
				return $data;
			};
			if ( count( $given ) > $quantity ) {
				return new \WP_Error( 'wpcap_attendees_invalid', __( 'Es wurden mehr Teilnehmende angegeben als Tickets bestellt.', 'pass-captain' ), $where( (int) $quantity ) );
			}
			if ( $strict ) {
				$keys = array_map( static function ( $f ) { return (string) $f->field_key; }, $fields );
				foreach ( $given as $i => $entry ) {
					$sent = is_array( $entry['answers'] ?? null ) ? array_keys( $entry['answers'] ) : array();
					foreach ( array_diff( array_map( 'strval', $sent ), $keys ) as $unknown ) {
						$closed = in_array( $unknown, $inactive, true );
						return new \WP_Error(
							'wpcap_attendee_field_unknown',
							$closed ? __( 'Ein Teilnehmerfeld wird nicht mehr abgefragt. Bitte lade die Seite neu und prüfe die Angaben.', 'pass-captain' ) : __( 'Die Angaben zu Teilnehmenden enthalten ein unbekanntes Feld. Bitte lade die Seite neu.', 'pass-captain' ),
							$where( $i, sanitize_key( $unknown ) ) + array( 'reason' => $closed ? 'field_inactive' : 'field_unknown' )
						);
					}
				}
			}
			// A680: order-level fields are asked once per order and ticket type (first entry of the type).
			$order_fields = array_values( array_filter( $fields, static function ( $f ) { return 'order' === ParticipantFields::scope( $f ); } ) );
			$ticket_fields = array_values( array_filter( $fields, static function ( $f ) { return 'order' !== ParticipantFields::scope( $f ); } ) );
			$order_clean = array();
			$first = is_array( $given[0]['answers'] ?? null ) ? $given[0]['answers'] : array();
			foreach ( $order_fields as $field ) {
				list( $value, $error ) = self::normalize_answer( $field, $first[ $field->field_key ] ?? '' );
				if ( $error ) { return new \WP_Error( 'wpcap_attendee_field_invalid', ParticipantFields::invalid_message( $field ), $where( 0, $field ) ); }
				if ( (int) $field->required && '' === $value && ! $skip_required_fields && ( $given || ! $allow_missing ) ) {
					/* translators: %s: field label */
					return new \WP_Error( 'wpcap_attendee_field_required', sprintf( __( 'Bitte „%s“ für die Bestellung ausfüllen.', 'pass-captain' ), $field->label ), $where( 0, $field ) );
				}
				if ( '' !== $value ) { $order_clean[ (int) $field->field_id ] = mb_substr( $value, 0, 2000 ); }
			}
			$needs_all = ! $defer_required && ! $allow_missing && ( $name_required || (bool) array_filter( $ticket_fields, static function ( $f ) { return (int) $f->required; } ) );
			for ( $i = 0; $i < $quantity; $i++ ) {
				$entry = $given[ $i ] ?? null;
				if ( ! $entry && ! $needs_all && ! $order_clean ) { continue; }
				$present = (bool) $entry;
				$entry = is_array( $entry ) ? $entry : array();
				$answers = is_array( $entry['answers'] ?? null ) ? $entry['answers'] : array();
				$clean = array();
				foreach ( $ticket_fields as $field ) {
					// A498: sanitize_email() turns "kein-mail" into "" – an entered but invalid value must not pass as empty.
					list( $value, $error ) = self::normalize_answer( $field, $answers[ $field->field_key ] ?? '' );
					if ( $error ) {
						return new \WP_Error( 'wpcap_attendee_field_invalid', ParticipantFields::invalid_message( $field ), $where( $i, $field ) );
					}
					if ( (int) $field->required && '' === $value && ! $skip_required_fields && ( $present || ! $allow_missing ) ) {
						/* translators: %s: field label */
						return new \WP_Error( 'wpcap_attendee_field_required', sprintf( __( 'Bitte „%s“ für jedes Ticket ausfüllen.', 'pass-captain' ), $field->label ), $where( $i, $field ) );
					}
					if ( '' !== $value ) { $clean[ (int) $field->field_id ] = mb_substr( $value, 0, 2000 ); }
				}
				// A552: "same as buyer" takes the buyer's address (and billing name) for this ticket.
				$same = ! empty( $entry['same_as_buyer'] ) && $buyer;
				$name = 'off' === $name_mode ? '' : mb_substr( sanitize_text_field( Http::str( $entry['name'] ?? '' ) ), 0, 190 );
				$raw_email = $entry['email'] ?? '';
				if ( $same ) {
					if ( '' === $name && 'off' !== $name_mode ) { $name = mb_substr( sanitize_text_field( Http::str( $buyer['name'] ?? '' ) ), 0, 190 ); }
					if ( '' === trim( Http::str( $raw_email ) ) ) { $raw_email = $buyer['email'] ?? ''; }
				}
				if ( $name_required && '' === $name && ( $present || ! $allow_missing ) ) {
					return new \WP_Error( 'wpcap_attendee_field_required', __( 'Bitte den Namen für jedes Ticket angeben.', 'pass-captain' ), $where( $i, '__name' ) );
				}
				$email = Http::email( $raw_email );
				if ( Http::email_too_long( $email ) ) {
					// A493: a valid but overlong address would fail at the tickets/assignments column at issuance.
					return new \WP_Error( 'wpcap_attendees_invalid', self::email_too_long_message(), $where( $i ) + array( 'reason' => 'email_too_long' ) );
				}
				// A599: an entered but invalid address is refused (it was dropped silently, so the ticket
				// went to the buyer instead of the attendee). field_key "__email" names the field of the block.
				if ( false === Http::optional_email( $entry['email'] ?? '' ) ) {
					$label = __( 'E-Mail der Teilnehmer:in', 'pass-captain' );
					/* translators: %s: field label */
					$message = sprintf( __( '„%s“ ist keine gültige E-Mail-Adresse.', 'pass-captain' ), $label );
					return new \WP_Error( 'wpcap_attendee_field_invalid', $message, $where( $i, (object) array( 'field_key' => '__email' ) ) + array( 'reason' => 'email_invalid' ) );
				}
				$out[] = array(
					'ticket_type_id' => (int) $type_id,
					'name' => $name,
					'email' => is_email( $email ) ? $email : '',
					'answers' => $clean + $order_clean,
				);
			}
		}
		return $out;
	}

	/**
	 * One answer normalised for its field ([value, error], see ParticipantFields::normalize()). Field types
	 * that only the newline-option variant knows ("radio", "consent"; choices one per line in `options`)
	 * are validated by clean_answer(), everything else by ParticipantFields.
	 */
	private static function normalize_answer( $field, $value ) {
		$type = (string) ( $field->field_type ?? '' );
		$json_options = in_array( $type, array( 'select', 'multiselect' ), true ) && is_array( json_decode( (string) ( $field->options ?? '' ), true ) );
		if ( in_array( $type, array( 'radio', 'consent' ), true ) || ( 'select' === $type && ! $json_options && '' !== (string) ( $field->options ?? '' ) ) ) {
			$clean = self::clean_answer( $field, $value );
			return null === $clean ? array( '', 'invalid' ) : array( $clean, '' );
		}
		return ParticipantFields::normalize( $field, $value );
	}

	/** Normalised attendee-name mode of a ticket type: off, optional (default) or required. */
	public static function attendee_name_mode( $value ) {
		return in_array( (string) $value, array( 'off', 'optional', 'required' ), true ) ? (string) $value : 'optional';
	}

	/**
	 * Choices of a select/radio participant field (one per line in the options column).
	 *
	 * @return string[]
	 */
	public static function field_options( $field ) {
		$raw = is_object( $field ) ? ( $field->options ?? '' ) : ( $field['options'] ?? '' );
		// Two stored formats: a JSON list (ParticipantFields, A680) or one choice per line.
		$list = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		$choices = is_array( $list ) ? array_map( 'strval', $list ) : (array) preg_split( '/\R/', (string) $raw );
		return array_values( array_unique( array_filter( array_map( 'trim', $choices ), static function ( $choice ) { return '' !== $choice; } ) ) );
	}

	/**
	 * Sanitised answer for one participant field; null when the value is not valid for the field type
	 * (e-mail, choice outside the options, impossible date, phone number with other characters).
	 */
	public static function clean_answer( $field, $value ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		switch ( (string) $field->field_type ) {
			case 'email':
				$value = sanitize_email( $value );
				return '' === $value || is_email( $value ) ? $value : null;
			case 'textarea':
				return sanitize_textarea_field( $value );
			case 'checkbox':
			case 'consent':
				return $value && '0' !== $value ? '1' : '';
			case 'select':
			case 'radio':
				$value = sanitize_text_field( $value );
				return '' === $value || in_array( $value, self::field_options( $field ), true ) ? $value : null;
			case 'date':
				if ( '' === $value ) { return ''; }
				$date = \DateTime::createFromFormat( '!Y-m-d', $value );
				return $date && $date->format( 'Y-m-d' ) === $value ? $value : null;
			case 'tel':
				if ( '' === $value ) { return ''; }
				return preg_match( '/^\+?[0-9 ()\/.\-]{3,40}$/', $value ) && preg_match_all( '/[0-9]/', $value ) >= 3 ? $value : null;
			default:
				return sanitize_text_field( $value );
		}
	}

	/**
	 * Whether a database error means the value does not fit the column (too long, invalid characters:
	 * MySQL/MariaDB strict mode or wpdb's own field check) rather than a busy or lost database (A493).
	 */
	public static function is_data_error( $db_error ) {
		return 1 === preg_match( '/Data too long|Incorrect string value|Processing the values? for the following fields? failed|contains invalid data/i', (string) $db_error );
	}

	/** Message for a valid e-mail address that is longer than the e-mail columns allow (A493). */
	public static function email_too_long_message() {
		/* translators: %d: maximum number of characters */
		return sprintf( __( 'Diese E-Mail-Adresse ist zu lang. Bitte verwende eine Adresse mit höchstens %d Zeichen.', 'pass-captain' ), Http::EMAIL_MAX );
	}

	/**
	 * A420: fingerprint of a public checkout request – e-mail, ticket lines (type => quantity, summed
	 * and sorted), campaign and voucher codes. A retry with the same idempotency key must send the
	 * same content; another cart under the same key gets 409 instead of the first order.
	 *
	 * @param string $email   Sanitised e-mail.
	 * @param mixed  $lines   Raw `items`.
	 * @param array  $payload Customer payload.
	 *
	 * Stored in wpcap_orders.request_hash and compared inside create(); independent of the provider-aware
	 * request_fingerprint() that Rest\Controller compares (column request_fingerprint).
	 */
	public static function request_hash( $email, $lines, array $payload ) {
		$items = array();
		foreach ( is_array( $lines ) ? $lines : array() as $line ) {
			$type_id = is_array( $line ) ? Http::positive_int( $line['ticket_type_id'] ?? 0 ) : 0;
			if ( $type_id ) { $items[ $type_id ] = ( $items[ $type_id ] ?? 0 ) + Http::positive_int( $line['quantity'] ?? 0 ); }
		}
		ksort( $items );
		$codes = array();
		foreach ( is_array( $payload['campaign_codes'] ?? null ) ? $payload['campaign_codes'] : array() as $code ) {
			$code = strtoupper( trim( sanitize_text_field( Http::str( $code ) ) ) );
			if ( '' !== $code ) { $codes[ $code ] = true; }
		}
		ksort( $codes );
		$voucher = strtoupper( trim( sanitize_text_field( Http::str( $payload['voucher_code'] ?? '' ) ) ) );
		return hash( 'sha256', (string) wp_json_encode( array( strtolower( (string) $email ), $items, array_keys( $codes ), $voucher ) ) );
	}

	/** A420: 409 for an idempotency key that already belongs to another cart; nothing was booked. */
	private static function key_reused_error() {
		return new \WP_Error( 'wpcap_idempotency_conflict', __( 'Dieser Bestellversuch gehört zu einem anderen Warenkorb. Bitte lade die Seite neu und bestelle erneut.', 'pass-captain' ), array( 'status' => 409, 'reason' => 'idempotency_key_reused' ) );
	}

	/**
	 * A576: 409 for a checkout whose unit price rose above the price shown in the form. data.prices
	 * (type_id => {shown_minor, unit_minor, formatted}) lets the form show the new price; nothing was booked.
	 *
	 * @param array $changed type_id => {shown_minor, unit_minor, formatted, name}.
	 */
	private static function price_changed_error( array $changed ) {
		$names = array();
		$prices = array();
		foreach ( $changed as $type_id => $row ) {
			/* translators: 1: ticket type, 2: new price */
			$names[] = sprintf( __( '„%1$s“ jetzt %2$s', 'pass-captain' ), $row['name'], $row['formatted'] );
			$prices[ (int) $type_id ] = array( 'shown_minor' => (int) $row['shown_minor'], 'unit_minor' => (int) $row['unit_minor'], 'formatted' => (string) $row['formatted'] );
		}
		/* translators: %s: list of ticket types with their new price */
		$message = sprintf( __( 'Der Preis hat sich seit dem Laden der Seite geändert (%s). Bitte prüfe die Summe und bestelle erneut.', 'pass-captain' ), implode( ', ', $names ) );
		return new \WP_Error( 'wpcap_price_changed', $message, array( 'status' => 409, 'reason' => 'price_changed', 'field' => 'items', 'prices' => $prices ) );
	}

	/**
	 * A560: compares what the buyer saw (items[].occurrence_revision, items[].expected_price_minor = the
	 * displayed unit price) with the current state. A mismatch refuses the checkout (409, reason
	 * `occurrence_changed` or `price_changed`) with the current state, so the form can show it and
	 * the buyer confirms by submitting again. Callers that send neither field are not bound.
	 *
	 * @param array  $shown      revision?, price?.
	 * @param object $type       Ticket type row (locked).
	 * @param object $occurrence Occurrence row (locked).
	 * @return \WP_Error|null
	 */
	private static function shown_state_error( array $shown, $type, $occurrence, $now ) {
		if ( isset( $shown['revision'] ) && Calendar\Revisions::ready() && (int) $shown['revision'] !== (int) ( $occurrence->revision ?? 0 ) ) {
			$state = self::shown_state( $occurrence, $now );
			$where = $state['label'] . ( '' !== $state['location'] ? ', ' . $state['location'] : '' );
			/* translators: %s: new date and time (and location) of the occurrence */
			return new \WP_Error( 'wpcap_occurrence_changed', sprintf( __( 'Der Termin wurde inzwischen geändert – jetzt: %s. Bitte prüfe die Angaben und sende die Bestellung erneut ab.', 'pass-captain' ), $where ), array( 'status' => 409, 'reason' => 'occurrence_changed', 'state' => $state ) );
		}
		if ( isset( $shown['price'] ) ) {
			$price = self::unit_price( $type, $occurrence, 1, array( 'now' => $now ) );
			if ( $price !== (int) $shown['price'] ) {
				$state = self::shown_state( $occurrence, $now );
				/* translators: 1: ticket type name, 2: new price */
				return new \WP_Error( 'wpcap_price_changed', sprintf( __( 'Der Preis für „%1$s“ hat sich inzwischen geändert – jetzt %2$s. Bitte prüfe die Angaben und sende die Bestellung erneut ab.', 'pass-captain' ), (string) $type->name, Frontend\Format::money( $price, $type->currency ) ), array( 'status' => 409, 'reason' => 'price_changed', 'state' => $state ) );
			}
		}
		return null;
	}

	/**
	 * Current public state of an occurrence for the checkout form (A560): revision, start (ISO 8601
	 * with offset and as display label), location and the displayed unit price of each active type.
	 */
	public static function shown_state( $occurrence, $now = null ) {
		global $wpdb;
		$now = null === $now ? Clock::mysql() : (string) $now;
		$prices = array();
		$formatted = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_ticket_types WHERE occurrence_id=%d AND status='active' ORDER BY ticket_type_id", (int) $occurrence->occurrence_id ) ) as $row ) {
			$prices[ (int) $row->ticket_type_id ] = self::unit_price( $row, $occurrence, 1, array( 'now' => $now ) );
			$formatted[ (int) $row->ticket_type_id ] = Frontend\Format::money( $prices[ (int) $row->ticket_type_id ], $row->currency );
		}
		return array(
			'occurrence_id' => (int) $occurrence->occurrence_id,
			'revision' => (int) ( $occurrence->revision ?? 0 ),
			'starts_at' => Occurrence::iso( $occurrence->starts_at, $occurrence->timezone ),
			'label' => Frontend\Format::event_start( $occurrence->starts_at, $occurrence->timezone ),
			'location' => (string) $occurrence->location,
			'prices' => $prices,
			'prices_formatted' => $formatted,
		);
	}

	/**
	 * Error of a checkout that was rolled back; db_busy is retryable (503).
	 *
	 * Support log (A322): an unexpected failure (exception that is no checkout outcome, unknown
	 * abort code) is logged as an error with class, code, file:line and the last database error;
	 * the buyer sees the neutral message with the reference ("Fehler-ID", data.reference). A busy
	 * database is logged as a warning with the database error; ordinary outcomes (sold out, sales
	 * closed, …) only in debug mode.
	 *
	 * @param \Throwable|null $exception Cause.
	 * @param array           $context   Support context (db_error, source, provider, ticket types).
	 */
	private static function checkout_error( $code, $custom_error, $key_hash, $exception = null, array $context = array() ) {
		global $wpdb;
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
			'manual_hold_limit' => __( 'Per Überweisung sind für diesen Termin gerade keine weiteren Tickets reservierbar. Bitte wähle eine andere Zahlungsart oder eine kleinere Anzahl.', 'pass-captain' ),
		);
		if ( 'order_insert_failed' === $code ) {
			// A concurrent request with the same idempotency key won the unique index.
			$request_hash = (string) ( $context['request_hash'] ?? '' );
			$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE idempotency_key = %s', $wpdb->prefix . 'wpcap_orders', $key_hash ) );
			if ( $existing ) {
				// A420: the parallel winner of this key may have booked another cart.
				if ( '' !== $request_hash && '' !== (string) ( $existing->request_hash ?? '' ) && ! hash_equals( (string) $existing->request_hash, $request_hash ) ) { return self::key_reused_error(); }
				return self::reused_result( $existing );
			}
			$code = 'db_busy';
		}
		if ( 'price_changed' === $code && $custom_error instanceof \WP_Error ) {
			return $custom_error;
		}
		if ( 'db_busy' !== $code && DbSession::is_busy_error( (string) ( $context['db_error'] ?? '' ) ) ) {
			// Deadlock victim or lock wait timeout behind another abort code: nothing was written, retryable.
			$code = 'db_busy';
		}
		if ( 'db_busy' === $code && self::is_data_error( (string) ( $context['db_error'] ?? '' ) ) ) {
			// A493: a value the columns cannot hold (too long, invalid characters) fails the same way on
			// every retry – no "try again" (503), but a final 400 that is logged as an unexpected error
			// (the entrance checks should have caught it).
			$reference = Log::error( 'checkout', 'Checkout aborted: input does not fit the schema', array( 'reason' => 'invalid_data' ) + $context );
			$message = __( 'Eine Angabe ist zu lang oder enthält ungültige Zeichen. Bitte prüfe deine Eingaben.', 'pass-captain' );
			return new \WP_Error( 'wpcap_checkout_failed', $message, array_filter( array( 'status' => 400, 'retryable' => false, 'reason' => 'invalid_data', 'reference' => $reference ), static fn( $v ) => '' !== $v ) );
		}
		if ( 'billing_required' === $code ) {
			return new \WP_Error( 'wpcap_billing_required', __( 'Für diesen Bestellbetrag brauchen wir für die Rechnung deinen Namen und deine Anschrift. Bitte aktiviere „Ich brauche eine Rechnung auf Firma/Adresse“ und fülle die Felder aus.', 'pass-captain' ), array( 'status' => 400, 'field' => 'billing_enabled' ) );
		}
		$messages = apply_filters( 'wpcap_checkout_error_messages', $messages );
		$custom = $custom_error instanceof \WP_Error && '' !== $custom_error->get_error_message();
		$fallback = $custom ? $custom_error->get_error_message() : __( 'Die Bestellung konnte nicht angelegt werden.', 'pass-captain' );
		$message = $messages[ $code ] ?? $fallback;
		// `reason`: machine-readable cause (sold_out, occurrence_sold_out, sales_closed, …) – the message is translated.
		$data = array( 'status' => 'db_busy' === $code ? 503 : 409, 'retryable' => 'db_busy' === $code, 'reason' => isset( $messages[ $code ] ) ? $code : 'checkout_failed' );
		if ( 'db_busy' === $code ) {
			// create() retries a busy database internally (db_busy) before the client sees the 503.
			$data['db_busy'] = true;
			$data['retry_after'] = self::RETRY_AFTER_SECONDS;
		}
		$custom_data = $custom ? $custom_error->get_error_data() : null;
		if ( is_array( $custom_data ) && isset( $custom_data['state'] ) && ( $custom_data['reason'] ?? '' ) === $code ) {
			// A560: the changed occurrence/price state travels with the refusal.
			$data['reason'] = $code;
			$data['state'] = $custom_data['state'];
		}
		// A known outcome is a RuntimeException with a mapped code or a module's own error (custom_error).
		$expected = ( isset( $messages[ $code ] ) || $custom ) && ( ! $exception || $exception instanceof \RuntimeException );
		// The abort code of an unexpected exception is its message: never stored as such (may hold data).
		$reason = $expected || ( $exception instanceof \RuntimeException && preg_match( '/^[a-z0-9_]{1,64}$/', (string) $code ) ) ? (string) $code : 'unexpected_exception';
		$context = array( 'reason' => $reason ) + array_filter( $context, static fn( $v ) => '' !== $v && false !== $v && array() !== $v );
		if ( ! $expected ) {
			$reference = $exception instanceof \Throwable ? Log::exception( 'checkout', $exception, $context ) : Log::error( 'checkout', 'Checkout aborted: ' . $reason, $context );
			if ( '' !== $reference ) {
				/* translators: 1: error message, 2: error reference for support */
				$message = sprintf( __( '%1$s Bitte versuche es erneut. Falls es wieder nicht klappt, nenne uns bitte die Fehler-ID %2$s.', 'pass-captain' ), $message, $reference );
				$data['reference'] = $reference;
			}
		} elseif ( 'db_busy' === $code ) {
			Log::warning( 'checkout', 'Checkout rolled back: database busy', $context );
		} else {
			Log::debug( 'checkout', 'Checkout refused: ' . $code, $context );
		}
		return new \WP_Error( 'wpcap_checkout_failed', $message, $data );
	}

	/** Whether the order row of a checkout whose COMMIT result is unknown exists (with this checkout's key). */
	private static function order_committed( $order_id, $key_hash ) {
		global $wpdb;
		$sql = $wpdb->prepare( "SELECT order_id FROM {$wpdb->prefix}wpcap_orders WHERE order_id=%d AND idempotency_key=%s", absint( $order_id ), $key_hash );
		$found = $wpdb->get_var( $sql );
		if ( '' !== $wpdb->last_error ) { $found = $wpdb->get_var( $sql ); } // Once more on the reconnected session.
		return null !== $found;
	}

	/**
	 * Removes what a checkout wrote after its transaction was lost (A183): the connection dropped
	 * and wpdb reconnected, so the statements from then on ran with autocommit while the earlier
	 * ones were rolled back. Order, lines and holds of $order_id (only an order that carries this
	 * checkout's key) are deleted, reserved campaign uses released, modules informed through
	 * `wpcap_capacity_released` (reason checkout_aborted) and the capacity counters of the
	 * occurrences rebuilt from the source tables. If this fails too, the holds lapse on their own
	 * and the counter reconciliation repairs the counters.
	 */
	private static function discard_lost_order( $order_id, $key_hash, array $occurrence_ids ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order_id = absint( $order_id );
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT order_id,idempotency_key FROM {$p}wpcap_orders WHERE order_id=%d", $order_id ) );
		if ( '' !== $wpdb->last_error || ( $order && ! hash_equals( (string) $order->idempotency_key, (string) $key_hash ) ) ) { return false; }
		Campaigns::release_for_order( $order_id );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_reservations WHERE order_id=%d", $order_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_order_items WHERE order_id=%d", $order_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_orders WHERE order_id=%d", $order_id ) );
		self::capacity_released( $order_id, 'checkout_aborted' );
		if ( CapacityCounters::maintained() ) {
			foreach ( $occurrence_ids as $occurrence_id ) { CapacityCounters::rebuild_occurrence( (int) $occurrence_id ); }
		}
		self::flush_availability_cache();
		Audit::record( 'checkout_aborted', 'order', $order_id, array( 'reason' => 'transaction_lost' ) );
		return true;
	}

	/**
	 * Whether a module error is transient (deadlock, lock wait timeout): code db_busy/wpcap_db_busy,
	 * `retryable` in its data, or HTTP status 503.
	 */
	public static function is_retryable_error( \WP_Error $error ) {
		return Errors::is_busy( $error );
	}

	/** Rolls back a payment confirmation that hit a deadlock/lock timeout; callers retry (webhooks get a 5xx). */
	private static function payment_busy( $order_id = 0 ) {
		global $wpdb;
		$lost = false === self::in_transaction();
		$wpdb->query( 'ROLLBACK' );
		// Statements after a lost connection may have changed counters without their rows (A183).
		if ( $lost && $order_id ) { CapacityCounters::repair_order( $order_id ); }
		return Errors::busy( __( 'Die Zahlung konnte gerade nicht verbucht werden. Bitte erneut versuchen.', 'pass-captain' ), 'wpcap_db_busy', array( 'retry_after' => self::RETRY_AFTER_SECONDS ) );
	}

	/**
	 * Whether the connection is still inside a transaction. False after the server rolled the
	 * transaction back (deadlock victim, lock wait timeout with innodb_rollback_on_timeout); null if
	 * the state cannot be determined.
	 *
	 * MariaDB reports it in @@in_transaction. MySQL has no such variable (querying it would only write
	 * "Unknown system variable" to the error log), so there a savepoint probe is used: a savepoint is
	 * only kept inside a transaction, so SAVEPOINT + RELEASE SAVEPOINT succeeds inside one and RELEASE
	 * fails ("SAVEPOINT does not exist") once the server has ended the transaction. The probe changes
	 * no data and errors are suppressed (no error-log line per order).
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
			if ( '' !== $marker ) {
				$value = $wpdb->get_var( "SELECT COUNT(*) FROM {$marker}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix, no input.
				if ( '' === $wpdb->last_error && null !== $value ) { return (int) $value > 0; }
			}
			// No marker for this transaction (no CREATE TEMPORARY TABLES privilege, failed write): savepoint probe.
			return self::savepoint_probe();
		}
		$value = $wpdb->get_var( 'SELECT @@in_transaction' );
		if ( '' === $wpdb->last_error && null !== $value ) { return 1 === (int) $value; }
		return self::savepoint_probe();
	}

	/** Savepoint probe (see in_transaction()): true inside a transaction, false outside, null if unknown. */
	private static function savepoint_probe() {
		global $wpdb;
		$suppress = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
		$set = $wpdb->query( 'SAVEPOINT wpcap_tx_probe' );
		$released = false !== $set ? $wpdb->query( 'RELEASE SAVEPOINT wpcap_tx_probe' ) : false;
		if ( null !== $suppress ) { $wpdb->suppress_errors( $suppress ); }
		if ( false === $set ) { return null; }
		return false !== $released;
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

	/**
	 * Returns an error code if $quantity more units of $type (and $occurrence_quantity more units of
	 * the occurrence, default $quantity) would exceed type or occurrence capacity; '' when they fit.
	 * Callers must hold row locks on the ticket type and occurrence, and pass rows read under those
	 * locks after any counter change in the same transaction. Capacity 0 means unlimited.
	 *
	 * With capacity counters (CapacityCounters::enabled()) usage is sold_qty + held_qty of the
	 * locked rows minus holds that still count but no longer hold (range counted_qty>0 on the index
	 * counted_type (ticket_type_id,counted_qty), A429: only the few holds that still count, never the
	 * reservation history); without them the paid lines and holds are summed (upgrade fallback).
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
		$now = Clock::mysql();
		if ( (int) $type->capacity > 0 ) {
			// Holds that no longer hold (lapsed while still "active", or already expired/released/
			// converted but not yet synced) still count in held_qty until the counters catch up.
			$lapsed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(counted_qty),0) FROM {$p}wpcap_reservations WHERE ticket_type_id=%d AND counted_qty>0 AND (status<>'active' OR expires_at <= %s)", $type->ticket_type_id, $now ) );
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
			// The occurrence's types first, then one range per type on counted_type (ticket_type_id,
			// counted_qty): a join would read every historical hold of each type (A429).
			$occ_types = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ticket_type_id FROM {$p}wpcap_ticket_types WHERE occurrence_id=%d", $occurrence->occurrence_id ) ) );
			if ( '' !== $wpdb->last_error ) { return 'db_busy'; }
			$lapsed = $occ_types ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(counted_qty),0) FROM {$p}wpcap_reservations WHERE ticket_type_id IN (" . implode( ',', $occ_types ) . ") AND counted_qty>0 AND (status<>'active' OR expires_at <= %s)", $now ) ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
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
		$now = Clock::mysql();
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
			if ( (int) $occ_used + $occurrence_quantity > (int) $occurrence->capacity ) {
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
		$context['now'] = isset( $context['now'] ) ? (string) $context['now'] : Clock::mysql();
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
		$context = array_merge( array( 'phase' => 'display', 'source' => 'native', 'quantity' => 1, 'now' => Clock::mysql() ), $context, array( 'occurrence' => $occurrence ) );
		return (string) apply_filters( 'wpcap_ticket_price_label', '', $type, $context );
	}

	/**
	 * Runs the `wpcap_reserve_capacity` filter inside an order/recovery transaction. Ticket types and
	 * occurrences are already locked (FOR UPDATE) and no consistent read has happened yet, so a module
	 * that locks its own rows here and only then reads usage sees every committed reservation.
	 *
	 * @param array $demand  ticket_type_id => quantity.
	 * @param array $context phase ('create'|'recover'|'exchange'), order_id (excluded from usage; 0 on
	 *                       create), types (locked rows by id), occurrences (locked rows by id), now,
	 *                       source, email (buyer e-mail), user_id (current user, 0 for guests/cron),
	 *                       internal (trusted channel options of Orders::create(); empty on recover).
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
				'now' => Clock::mysql(),
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
			'db_busy' => __( 'Gerade ist sehr viel los. Bitte versuche es in einem Moment erneut.', 'pass-captain' ),
			'sold_out' => __( 'Für diese Ticketart sind nicht mehr genügend Tickets verfügbar.', 'pass-captain' ),
			'occurrence_sold_out' => __( 'Für diesen Termin sind nicht mehr genügend Plätze verfügbar.', 'pass-captain' ),
		) );
		return new \WP_Error( $code, (string) ( $messages[ $code ] ?? $code ), array( 'status' => 'db_busy' === $code ? 503 : 409, 'retryable' => 'db_busy' === $code ) );
	}

	/**
	 * Whether the connection is still inside a transaction (false after a server-side rollback, null if
	 * unknown). For callers that started the transaction themselves (START TRANSACTION, not begin()), so
	 * the marker table of begin() is never consulted: @@in_transaction on MariaDB, else the savepoint probe.
	 */
	public static function transaction_alive() {
		global $wpdb;
		if ( ! self::uses_marker() ) {
			$value = $wpdb->get_var( 'SELECT @@in_transaction' );
			if ( '' === $wpdb->last_error && null !== $value ) { return 1 === (int) $value; }
		}
		return self::savepoint_probe();
	}

	/**
	 * Fires `wpcap_capacity_released` after inventory of an order stopped counting (hold expired,
	 * order cancelled/refunded, tickets revoked, payment unallocated). Core capacity and pools are
	 * derived from orders and holds, so this is informational for modules with own counters/caches.
	 */
	private static function capacity_released( $order_id, $reason, array $details = array() ) {
		do_action( 'wpcap_capacity_released', (int) $order_id, (string) $reason, $details );
	}

	/** Per-request availability memo: ticket_type_id => remaining (int|null), of the site $memo_prefix. */
	private static $availability_memo = array();
	/** Table prefix the memo belongs to (multisite: switch_to_blog() changes it within a request). */
	private static $memo_prefix = null;
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

	/**
	 * Action `switch_blog` (and any other prefix change): the memo and the request caches of the
	 * modules belong to the previous site. Does not count as a write (the object cache stays usable;
	 * its keys contain the prefix).
	 */
	public static function site_switched() {
		global $wpdb;
		self::$availability_memo = array();
		self::$memo_prefix = (string) $wpdb->prefix;
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
		if ( self::$memo_prefix !== (string) $wpdb->prefix ) { self::site_switched(); }
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
		$now = Clock::mysql();
		$types = array();
		$need = array();
		foreach ( $ids as $id ) {
			// Preloaded rows, else the display batch (event page, GET /events), else one grouped query.
			$batched = isset( $rows['types'][ $id ] ) ? null : DisplayBatch::type( $id );
			if ( isset( $rows['types'][ $id ] ) && is_object( $rows['types'][ $id ] ) && isset( $rows['types'][ $id ]->status ) ) { $types[ $id ] = $rows['types'][ $id ]; } elseif ( $batched && isset( $batched->status ) ) { $types[ $id ] = $batched; } else { $need[] = $id; }
		}
		if ( $need ) {
			foreach ( (array) $wpdb->get_results( "SELECT * FROM {$p}wpcap_ticket_types WHERE ticket_type_id IN (" . implode( ',', $need ) . ')' ) as $row ) { $types[ (int) $row->ticket_type_id ] = $row; }
		}
		$occurrences = array();
		$need = array();
		foreach ( $types as $type ) {
			$occ_id = (int) $type->occurrence_id;
			$batched = isset( $rows['occurrences'][ $occ_id ] ) ? null : DisplayBatch::occurrence( $occ_id );
			if ( isset( $rows['occurrences'][ $occ_id ] ) && is_object( $rows['occurrences'][ $occ_id ] ) && isset( $rows['occurrences'][ $occ_id ]->status ) ) { $occurrences[ $occ_id ] = $rows['occurrences'][ $occ_id ]; } elseif ( $batched && isset( $batched->status ) ) { $occurrences[ $occ_id ] = $batched; } else { $need[ $occ_id ] = $occ_id; }
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

	/** Occurrence row (SELECT *), from the display batch when preloaded. */
	private static function occurrence_row( $occurrence_id ) {
		global $wpdb;
		return DisplayBatch::occurrence( $occurrence_id ) ?? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE occurrence_id=%d', $wpdb->prefix . 'wpcap_occurrences', $occurrence_id ) );
	}

	/**
	 * Confirms a payment reported by an external system (e.g. WooCommerce). Idempotent.
	 *
	 * Active holds are converted. If the hold has lapsed (late payment recovery), everything that may
	 * have changed since is checked again under row locks: occurrence and event status, the
	 * occurrence end, module resources, capacity, discount code limits and `wpcap_order_recovering`
	 * (buyer limits). If any check fails, the order is flagged `needs_review` with payment status
	 * `paid_unallocated` – no tickets are issued, nothing is overbooked, the admin is alerted and,
	 * with the setting `late_payment_auto_refund`, the payment is refunded automatically.
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
		$order_id = absint( $order_id );
		/**
		 * Before a payment is confirmed, outside any transaction: modules prepare what the late payment
		 * re-check needs and cannot do under row locks, e.g. Voucher Captain reserves a voucher again whose
		 * reservation was released when the hold lapsed (A632). Runs for every confirmation (also repeated
		 * ones); handlers must be idempotent and check the order state themselves.
		 *
		 * @param int $order_id Order.
		 */
		do_action( 'wpcap_before_payment_confirm', $order_id );
		return DbSession::with_short_lock_wait( static function () use ( $order_id, $reference ) {
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
	 * change after the order was created). Then the order row, ticket types and occurrences (the paid
	 * units enter the capacity counters); a late payment then locks module resources
	 * (`wpcap_reserve_capacity`: pools, seats), and finally the order's reservations. Reservations are
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
	 * One confirmation transaction (see confirm_payment_locked()).
	 *
	 * Lock order: order row, ticket types and occurrences by id (as in Orders::create(); the paid units
	 * enter the sold counters in this transaction), then – for a late payment – module resources
	 * (`wpcap_reserve_capacity`), then the order's reservations by primary key.
	 *
	 * @return true|\WP_Error|string 'hold_lapsed' when an on-time confirmation found no active hold any more.
	 */
	private static function confirm_payment_transaction( $order_id, $reference, $check_limits, array $lines, array $hold_ids, $late ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order_id = absint( $order_id );
		$now = Clock::mysql();
		// Without a transaction the status update below would autocommit and the in_transaction()
		// check before COMMIT would then roll back nothing: a paid order without tickets (A183).
		if ( false === self::begin() ) { return self::payment_busy( $order_id ); }
		$order = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d FOR UPDATE', $p . 'wpcap_orders', $order_id ) );
		if ( '' !== $wpdb->last_error ) { return self::payment_busy( $order_id ); }
		if ( ! $order ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_order_missing', __( 'Bestellung nicht gefunden.', 'pass-captain' ) );
		}
		if ( 'paid' === $order->payment_status ) {
			$wpdb->query( 'ROLLBACK' ); // Nothing written; the issuance locks the order itself.
			// A repeated confirmation (webhook redelivery after an error) completes what is missing:
			// counters of an order whose booking ran partly outside its transaction, and tickets.
			CapacityCounters::sync_order_now( $order_id );
			$issued = self::issue_and_email( $order_id );
			return is_wp_error( $issued ) ? $issued : true;
		}
		if ( in_array( $order->status, array( 'refunded', 'cancelled' ), true ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_order_state', __( 'Der Bestellstatus erlaubt diese Aktion nicht.', 'pass-captain' ) );
		}
		// Capacity locks: ticket types, then occurrences, with the same statements and in the same order as
		// Orders::create(). Locking reads only until every capacity lock is held (fresh snapshot for the
		// re-check below). A checkout holding these locks never locks existing order rows (seat claims are
		// released without shared locks, see Seating\Reservations::release_stale()).
		$locked_types = array();
		foreach ( $lines as $line ) {
			$locked_types[ (int) $line->ticket_type_id ] = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE ticket_type_id=%d FOR UPDATE', $p . 'wpcap_ticket_types', $line->ticket_type_id ) );
			if ( '' !== $wpdb->last_error ) { return self::payment_busy( $order_id ); }
		}
		$occ_ids = array_unique( array_map( static function ( $type ) { return $type ? (int) $type->occurrence_id : 0; }, $locked_types ) );
		sort( $occ_ids, SORT_NUMERIC );
		$occurrences = array();
		foreach ( $occ_ids as $occ_id ) {
			if ( ! $occ_id ) { continue; }
			$occurrences[ $occ_id ] = $wpdb->get_row( $wpdb->prepare( 'SELECT o.*,e.name AS event_name,e.status AS event_status FROM %i o INNER JOIN %i e ON e.event_id=o.event_id WHERE o.occurrence_id=%d FOR UPDATE', $p . 'wpcap_occurrences', $p . 'wpcap_events', $occ_id ) );
			if ( '' !== $wpdb->last_error ) { return self::payment_busy( $order_id ); }
		}
		$recovered = false;
		if ( $late ) {
			// Late payment: everything that may have changed since the hold lapsed is checked again:
			// ticket type, occurrence/event status and end (A273), module resources (`wpcap_reserve_capacity`),
			// capacity, buyer limits (Sales\BuyerLimits, buyer locks held), discount code limits (A353,
			// PAY-1) and `wpcap_order_recovering` (e.g. allocation buyer limits, A483).
			$demand = array();
			foreach ( $lines as $line ) { $demand[ (int) $line->ticket_type_id ] = (int) $line->quantity; }
			$recover_context = array( 'phase' => 'recover', 'order_id' => $order_id, 'types' => $locked_types, 'occurrences' => array_filter( $occurrences ), 'now' => $now, 'source' => (string) $order->payment_provider, 'email' => (string) $order->customer_email, 'user_id' => (int) ( $order->customer_user_id ?? 0 ), 'internal' => array() );
			$error = self::recovery_error( $lines, $locked_types, $occurrences, $now );
			if ( '' === $error ) {
				foreach ( $lines as $line ) {
					$type = $locked_types[ (int) $line->ticket_type_id ] ?? null;
					if ( ! $type || 'active' !== (string) $type->status ) { $error = 'ticket_type_unavailable'; break; }
				}
			}
			// Module resources may write (e.g. seats claimed again): undone if the re-check fails below.
			if ( false === $wpdb->query( 'SAVEPOINT wpcap_recover' ) ) { return self::payment_busy( $order_id ); }
			$reserve_error = '' === $error ? self::reserve_capacity( $demand, $recover_context ) : null;
			// A transient lock problem (deadlock, lock wait timeout) is no "sold out": roll back so the
			// payment provider redelivers the webhook (5xx) instead of flagging a paid order for review.
			if ( $reserve_error && ( 'db_busy' === $reserve_error->get_error_code() || self::is_retryable_error( $reserve_error ) ) ) { return self::payment_busy( $order_id ); }
			if ( false === self::lock_holds( $hold_ids ) ) { return self::payment_busy( $order_id ); }
			$per_occurrence = array();
			foreach ( '' !== $error ? array() : $lines as $line ) {
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
			if ( 'db_busy' === $error ) { return self::payment_busy( $order_id ); }
			if ( ! $error && $check_limits ) {
				// The lapsed hold no longer counted against the buyer: re-check the limits (buyer locks held).
				$limit_error = Sales\BuyerLimits::check( $demand, $locked_types, array_filter( $occurrences ), (string) $order->customer_email, (int) ( $order->customer_user_id ?? 0 ), $now, $order_id );
				if ( $limit_error ) { $error = 'buyer_limit'; }
			}
			if ( ! $error ) {
				// Discount codes released when the hold lapsed are redeemed again under the campaign lock,
				// within their usage limit (A353, PAY-1).
				$campaign_error = Campaigns::recover_for_order( $order_id );
				if ( is_wp_error( $campaign_error ) ) {
					if ( self::is_retryable_error( $campaign_error ) ) { return self::payment_busy( $order_id ); }
					$error = $campaign_error->get_error_code();
				}
			}
			if ( ! $error ) {
				/**
				 * Late payment recovery, inside the transaction after capacity and discount codes (like
				 * `wpcap_order_creating` on checkout): modules re-check per-order rules that may have
				 * changed since the hold lapsed (e.g. buyer limits) under their own row locks. Return a
				 * WP_Error to flag the order for review instead of issuing tickets (a busy error –
				 * Errors::busy() – rolls back so the provider redelivers).
				 *
				 * @param null|\WP_Error $result   Null to continue.
				 * @param int            $order_id Order.
				 * @param int[]          $demand   ticket_type_id => quantity of the order.
				 * @param array          $context  As for `wpcap_reserve_capacity` (phase 'recover').
				 */
				$recovering = apply_filters( 'wpcap_order_recovering', null, $order_id, $demand, $recover_context );
				if ( $recovering instanceof \WP_Error ) {
					if ( self::is_retryable_error( $recovering ) ) { return self::payment_busy( $order_id ); }
					$error = $recovering->get_error_code();
				}
			}
			if ( $error ) {
				return self::payment_unallocated( $order, $error, $reference, $hold_ids, $now );
			}
			$recovered = true;
		} else {
			$holds = self::lock_holds( $hold_ids );
			if ( false === $holds ) { return self::payment_busy( $order_id ); }
			$active = array_filter( $holds, static function ( $hold ) use ( $now ) { return 'active' === $hold->status && $hold->expires_at > $now; } );
			if ( ! $active ) {
				$wpdb->query( 'ROLLBACK' );
				return 'hold_lapsed';
			}
		}
		// Checked like Orders::create(): after a server-side rollback the remaining statements would
		// autocommit without the capacity re-check's locks. Guarded like every order transition (StateMachine).
		if ( 1 !== StateMachine::transition( 'order', "{$p}wpcap_orders", 'order_id', $order_id, 'complete', array( 'payment_status' => 'paid', 'updated_at' => $now ) ) ) { return self::payment_busy( $order_id ); }
		if ( false === self::set_hold_status( $hold_ids, 'converted', array( 'active', 'expired', 'released' ) ) ) { return self::payment_busy( $order_id ); }
		// Holds leave held_qty, the paid lines enter sold_qty – atomically with the status change.
		if ( false === CapacityCounters::sync_order( $order_id ) ) { return self::payment_busy( $order_id ); }
		if ( false === Campaigns::commit_for_order( $order_id ) ) { return self::payment_busy( $order_id ); }
		if ( ! self::commit_probe( $order_id, 'paid' ) ) { return self::payment_busy( $order_id ); }
		self::flush_availability_cache();
		Audit::record( 'external_payment_confirmed', 'order', $order_id, array( 'reference' => sanitize_text_field( $reference ), 'late_recovery' => $recovered ) );
		do_action( 'wpcap_order_paid', $order_id );
		// Paid but not yet issued (database error): the caller retries (webhooks get a 5xx and are
		// redelivered), and the repeated confirmation issues the tickets.
		$issued = self::issue_and_email( $order_id );
		return is_wp_error( $issued ) ? $issued : true;
	}

	/**
	 * Late payment that cannot be allocated: undoes module claims of the recovery (savepoint), flags
	 * the order needs_review / paid_unallocated (StateMachine), expires the holds, syncs the capacity
	 * counters, gives reserved discount codes back, commits and then alerts the admin (and queues the
	 * automatic refund with `late_payment_auto_refund`, see late_payment_unallocated()). needs_review is
	 * a final answer (webhooks acknowledge it), so it is persisted before anything is reported; when a
	 * step is not durable the provider redelivers (A183).
	 *
	 * The returned error's `reason` is 'sale_closed' for a closed sale (detail in `detail`: ticket type
	 * unavailable, occurrence cancelled/ended), otherwise the cause (buyer_limit, campaign_exhausted,
	 * seat_sold_out, sold_out, voucher_released, allocation_buyer_limit, …).
	 *
	 * @return \WP_Error wpcap_needs_review (or wpcap_db_busy when it could not be stored).
	 */
	private static function payment_unallocated( $order, $error, $reference, array $hold_ids, $now ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order_id = (int) $order->order_id;
		if ( false === $wpdb->query( 'ROLLBACK TO SAVEPOINT wpcap_recover' ) ) { return self::payment_busy( $order_id ); }
		// A repeated flag of a needs_review order changes nothing (StateMachine returns 0, not false).
		if ( false === StateMachine::transition( 'order', "{$p}wpcap_orders", 'order_id', $order_id, 'needs_review', array( 'payment_status' => 'paid_unallocated', 'updated_at' => $now ) )
			|| false === self::set_hold_status( $hold_ids, 'expired', array( 'active' ) )
			|| false === CapacityCounters::sync_order( $order_id )
			|| false === Campaigns::release_for_order( $order_id ) ) {
			return self::payment_busy( $order_id );
		}
		if ( ! self::commit_probe( $order_id, 'paid_unallocated' ) ) { return self::payment_busy( $order_id ); }
		self::flush_availability_cache();
		self::capacity_released( $order_id, 'payment_unallocated' );
		self::late_payment_unallocated( $order, $error, $reference );
		$closed = in_array( $error, array( 'sale_closed', 'ticket_type_unavailable', 'occurrence_unavailable', 'occurrence_ended' ), true );
		$data = array( 'reason' => $closed ? 'sale_closed' : (string) $error );
		if ( $closed ) { $data['detail'] = (string) $error; }
		return new \WP_Error( 'wpcap_needs_review', __( 'Die Reservierung war abgelaufen und die Tickets können nicht mehr ausgestellt werden. Die Bestellung muss manuell geklärt werden.', 'pass-captain' ) . ' ' . self::late_payment_reason( $error ), $data );
	}

	/**
	 * Late payment re-check of the order lines against the locked rows: ticket type and occurrence
	 * still there, occurrence `scheduled`, event `published` and the occurrence not ended
	 * (occurrence_end()). Returns an error code or ''.
	 *
	 * @param object[] $lines        ticket_type_id, quantity.
	 * @param array    $types        Locked ticket types by id (null = missing).
	 * @param array    $occurrences  Locked occurrences (with event_status) by id.
	 * @param string   $now          UTC now.
	 */
	private static function recovery_error( array $lines, array $types, array $occurrences, $now ) {
		foreach ( $lines as $line ) {
			$type = $types[ (int) $line->ticket_type_id ] ?? null;
			$occurrence = $type ? ( $occurrences[ (int) $type->occurrence_id ] ?? null ) : null;
			if ( ! $type || ! $occurrence ) { return 'ticket_type_unavailable'; }
			if ( 'scheduled' !== (string) $occurrence->status || 'published' !== (string) ( $occurrence->event_status ?? '' ) ) { return 'occurrence_unavailable'; }
			if ( self::occurrence_end( $occurrence ) < $now ) { return 'occurrence_ended'; }
		}
		return '';
	}

	/** Admin text for the reason a late payment could not be allocated. */
	public static function late_payment_reason( $code ) {
		switch ( (string) $code ) {
			case 'occurrence_unavailable':
				return __( 'Der Termin oder das Event wurde inzwischen abgesagt bzw. ist nicht mehr im Verkauf.', 'pass-captain' );
			case 'occurrence_ended':
				return __( 'Der Termin ist bereits vorbei.', 'pass-captain' );
			case 'ticket_type_unavailable':
				return __( 'Die Ticketart ist nicht mehr vorhanden.', 'pass-captain' );
			case 'campaign_exhausted':
				return __( 'Das Nutzungslimit des Rabattcodes ist inzwischen ausgeschöpft.', 'pass-captain' );
			case 'allocation_buyer_limit':
			case 'buyer_limit':
				return __( 'Die maximale Anzahl an Tickets pro Person wäre überschritten.', 'pass-captain' );
			case 'sale_closed':
				return __( 'Der Termin ist inzwischen abgesagt oder vorbei bzw. die Ticketart ist nicht mehr im Verkauf.', 'pass-captain' );
			case 'seat_sold_out':
				return __( 'Die Sitzplätze sind inzwischen vergeben und es sind keine passenden Plätze mehr frei.', 'pass-captain' );
			case 'voucher_released':
				return __( 'Der Gutschein der Bestellung wurde mit Ablauf der Reservierung freigegeben und konnte nicht erneut reserviert werden; die Zahlung deckt nur den Betrag nach Gutscheinabzug.', 'pass-captain' );
		}
		return __( 'Es ist kein Kontingent mehr frei.', 'pass-captain' );
	}

	/**
	 * After a late payment was flagged `needs_review`: audit entry, admin alert and – with the setting
	 * `late_payment_auto_refund` – an automatic full refund through the payment provider (job
	 * `late_payment_refund`, retried until it succeeds).
	 */
	private static function late_payment_unallocated( $order, $reason, $reference ) {
		$auto = self::schedule_late_refund( $order, 'payment_unallocated' );
		Audit::record( 'payment_unallocated', 'order', (int) $order->order_id, array( 'reason' => (string) $reason, 'reference' => sanitize_text_field( $reference ), 'auto_refund' => $auto ) );
		$text = sprintf(
			/* translators: 1: order number, 2: payment reference, 3: reason */
			__( 'Die Zahlung für Bestellung %1$s (Referenz %2$s) ist nach Ablauf der Reservierung eingegangen. Es wurden keine Tickets ausgestellt: %3$s', 'pass-captain' ),
			$order->public_id,
			sanitize_text_field( $reference ),
			self::late_payment_reason( $reason )
		);
		$text .= "\n\n" . ( $auto
			? __( 'Die Zahlung wird automatisch erstattet.', 'pass-captain' )
			: __( 'Bitte manuell klären (Zuteilung erneut versuchen oder erstatten).', 'pass-captain' ) );
		// A337: straight to the order (also listed under Bestellungen with the status filter "Prüfung nötig").
		if ( function_exists( 'admin_url' ) ) { $text .= "\n\n" . Admin\OrdersPage::order_url( (int) $order->order_id ); }
		wp_mail( get_option( 'admin_email' ), __( 'Pass Captain: Bezahlte Bestellung ohne Tickets', 'pass-captain' ), $text );
	}

	/**
	 * Queues the automatic refund of a payment that issued no tickets (late payment flagged for review,
	 * payment for a cancelled order) when `late_payment_auto_refund` is enabled and the provider can
	 * refund (not WooCommerce: refunds there happen in WooCommerce).
	 *
	 * @param object $order Order row.
	 * @param string $why   Audit reason.
	 * @return bool Whether a refund was queued.
	 */
	public static function schedule_late_refund( $order, $why ) {
		if ( ! Settings::get( 'late_payment_auto_refund' ) || (int) $order->total_minor < 1 || 'woocommerce' === (string) $order->payment_provider ) { return false; }
		$gateway = Payments\Registry::get( (string) $order->payment_provider );
		if ( ! $gateway || ! $gateway->supports_refunds() ) { return false; }
		return true === Jobs::enqueue( 'late-payment-refund|' . (int) $order->order_id, 'late_payment_refund', array( 'order_id' => (int) $order->order_id, 'reason' => sanitize_key( $why ) ) );
	}

	/**
	 * Job `late_payment_refund`: refunds the full payment of an order that issued no tickets
	 * (`needs_review`/`paid_unallocated` or `cancelled`) and marks it refunded. The provider call
	 * uses a fixed idempotency key, so a repeated run never pays out twice. An order that was
	 * allocated or refunded meanwhile is left alone.
	 *
	 * @return bool True when done (or nothing to do), false to retry.
	 */
	private static function run_late_refund( $order_id, $reason ) {
		global $wpdb;
		$order = self::get( $order_id );
		if ( ! $order ) { return '' === $wpdb->last_error ? true : self::job_error( false, 'wpcap_db_busy' ); }
		$open = ( 'needs_review' === $order->status && 'paid_unallocated' === $order->payment_status ) || 'cancelled' === $order->status;
		if ( ! $open || (int) $order->total_minor < 1 ) { return true; }
		$gateway = Payments\Registry::get( (string) $order->payment_provider );
		if ( ! $gateway || ! $gateway->supports_refunds() ) { return true; }
		$result = $gateway->refund( (array) $order, (int) $order->total_minor, 'wpcap-late-refund-' . (int) $order_id );
		if ( is_wp_error( $result ) ) {
			Audit::record( 'late_payment_refund_failed', 'order', (int) $order_id, array( 'error' => $result->get_error_code() ) );
			return $result; // The provider's reason becomes the job's last_error (A325).
		}
		$marked = self::mark_refunded( $order_id, 'late_payment_auto_refund' );
		if ( true !== $marked ) { return self::job_error( $marked, 'wpcap_refund_mark_failed' ); } // mark_refunded() queued its own repair; the provider call is idempotent.
		// No tickets, no refund row: the order total is booked as refunded, so the provider's refund
		// webhook finds nothing left to book.
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_orders SET refunded_minor=total_minor WHERE order_id=%d AND status='refunded'", (int) $order_id ) );
		Audit::record( 'late_payment_refunded', 'order', (int) $order_id, array( 'reason' => sanitize_key( $reason ), 'provider_reference' => \PassCaptain\Text::cut( sanitize_text_field( (string) $result ), 191 ), 'amount_minor' => (int) $order->total_minor ) );
		return true;
	}

	/**
	 * Closes a paid order that issued no tickets (`needs_review`/`paid_unallocated`, A358) by refunding
	 * the payment in full – the admin action next to "Zuteilung erneut versuchen":
	 *
	 * - 'provider': refund through the payment provider with the fixed key of the automatic late
	 *   refund (`wpcap-late-refund-{id}`), so this action and the job `late_payment_refund` never pay
	 *   out twice;
	 * - 'external': the money was returned outside the plugin (bank transfer, provider dashboard
	 *   without webhook); nothing is sent to the provider.
	 *
	 * Then the order is marked refunded (refunded amount = total, no refund row: no tickets, no
	 * invoice line was issued for them). WooCommerce orders are refunded in WooCommerce.
	 *
	 * @param int    $order_id Order.
	 * @param string $mode     'provider' or 'external'.
	 * @param string $note     Reason / note (audit).
	 * @return true|\WP_Error
	 */
	public static function settle_unallocated( $order_id, $mode, $note = '' ) {
		global $wpdb;
		$order_id = absint( $order_id );
		$order = self::get( $order_id );
		if ( ! $order && '' !== $wpdb->last_error ) { return Errors::busy(); }
		if ( ! $order || 'needs_review' !== $order->status || 'paid_unallocated' !== $order->payment_status ) {
			return new \WP_Error( 'wpcap_order_state', __( 'Der Bestellstatus erlaubt diese Aktion nicht.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		if ( 'woocommerce' === (string) $order->payment_provider ) {
			return new \WP_Error( 'wpcap_refund_woocommerce', __( 'WooCommerce-Bestellungen bitte in WooCommerce erstatten; die Tickets werden automatisch widerrufen.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$reference = '';
		if ( 'provider' === $mode ) {
			$gateway = Payments\Registry::get( (string) $order->payment_provider );
			if ( ! $gateway || ! $gateway->supports_refunds() || (int) $order->total_minor < 1 ) {
				return new \WP_Error( 'wpcap_refund_unsupported', __( 'Diese Zahlungsart kann nicht automatisch erstatten. Bitte die Zahlung außerhalb des Plugins zurückzahlen und „Extern erstattet – abschließen“ verwenden.', 'pass-captain' ), array( 'status' => 409 ) );
			}
			$result = $gateway->refund( (array) $order, (int) $order->total_minor, 'wpcap-late-refund-' . $order_id );
			if ( is_wp_error( $result ) ) {
				Audit::record( 'unallocated_refund_failed', 'order', $order_id, array( 'error' => $result->get_error_code() ) );
				return $result;
			}
			$reference = \PassCaptain\Text::cut( sanitize_text_field( (string) $result ), 191 );
		} elseif ( 'external' !== $mode ) {
			return new \WP_Error( 'wpcap_refund_mode', __( 'Unbekannte Art der Erstattung.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$marked = self::mark_refunded( $order_id, 'provider' === $mode ? 'unallocated_refunded' : 'unallocated_refunded_externally' );
		if ( true !== $marked ) { return $marked; } // mark_refunded() queued its own repair; a repeated provider call is idempotent.
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_orders SET refunded_minor=total_minor WHERE order_id=%d AND status='refunded'", $order_id ) );
		Audit::record( 'unallocated_settled', 'order', $order_id, array( 'mode' => 'provider' === $mode ? 'provider' : 'external', 'provider_reference' => $reference, 'amount_minor' => (int) $order->total_minor, 'note' => \PassCaptain\Text::cut( sanitize_text_field( (string) $note ), 255 ) ) );
		return true;
	}

	/**
	 * Commits a payment booking and tells whether it is durable (A183). The order update is the
	 * booking's first write: when the connection was lost and wpdb reconnected, every statement from
	 * the lost one on ran with autocommit and the earlier ones were rolled back. So the order's
	 * payment status after the commit attempt decides: $status means everything was committed;
	 * anything else means the order update was rolled back (a COMMIT that wpdb re-ran on a new
	 * session commits nothing) – then payment_busy() repairs the counters.
	 */
	private static function commit_probe( $order_id, $status ) {
		global $wpdb;
		if ( false === self::in_transaction() || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
		}
		$sql = $wpdb->prepare( "SELECT payment_status FROM {$wpdb->prefix}wpcap_orders WHERE order_id=%d", absint( $order_id ) );
		$now = $wpdb->get_var( $sql );
		if ( '' !== $wpdb->last_error ) { $now = $wpdb->get_var( $sql ); } // Once more on the reconnected session.
		return '' === $wpdb->last_error && $status === $now;
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

	/**
	 * Upper bound for units held by unpaid bank transfer orders (A634), per ticket type and per date with a
	 * capacity: `manual_hold_max_percent` of the capacity (0 = no bound), but never fewer than
	 * `wpcap_manual_hold_min_units` units (default 10), so small events still sell by bank transfer.
	 *
	 * @param int $capacity Capacity (> 0).
	 * @return int|null Null when there is no bound.
	 */
	public static function manual_hold_cap( $capacity ) {
		$percent = min( 100, max( 0, (int) Settings::get( 'manual_hold_max_percent' ) ) );
		if ( $percent < 1 || $percent >= 100 || (int) $capacity < 1 ) { return null; }
		$floor = max( 1, (int) apply_filters( 'wpcap_manual_hold_min_units', 10 ) );
		return max( $floor, (int) ceil( (int) $capacity * $percent / 100 ) );
	}

	/**
	 * Checks the new manual order's lines against manual_hold_cap() (inside Orders::create(), with the
	 * ticket types and occurrences locked). Returns 'manual_hold_limit', 'db_busy' or ''.
	 *
	 * @param int[]    $lines       ticket_type_id => quantity.
	 * @param object[] $types       Locked ticket types by id.
	 * @param object[] $occurrences Locked occurrences by id.
	 * @param string   $now         UTC now.
	 */
	private static function manual_hold_error( array $lines, array $types, array $occurrences, $now ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$held_sql = "SELECT COALESCE(SUM(r.quantity),0) FROM {$p}wpcap_reservations r INNER JOIN {$p}wpcap_orders o ON o.order_id=r.order_id INNER JOIN {$p}wpcap_ticket_types t ON t.ticket_type_id=r.ticket_type_id WHERE r.status='active' AND r.expires_at > %s AND o.payment_provider='manual' AND o.payment_status='pending' AND ";
		$per_occurrence = array();
		foreach ( $lines as $type_id => $quantity ) {
			$type = $types[ $type_id ];
			$occ_id = (int) $type->occurrence_id;
			$per_occurrence[ $occ_id ] = ( $per_occurrence[ $occ_id ] ?? 0 ) + (int) $quantity;
			$cap = self::manual_hold_cap( (int) $type->capacity );
			if ( null === $cap ) { continue; }
			$held = (int) $wpdb->get_var( $wpdb->prepare( $held_sql . 'r.ticket_type_id=%d', $now, (int) $type_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- static SQL with placeholders.
			if ( '' !== $wpdb->last_error ) { return 'db_busy'; }
			if ( $held + (int) $quantity > $cap ) { return 'manual_hold_limit'; }
		}
		foreach ( $per_occurrence as $occ_id => $quantity ) {
			$occurrence = $occurrences[ $occ_id ] ?? null;
			$cap = $occurrence ? self::manual_hold_cap( (int) $occurrence->capacity ) : null;
			if ( null === $cap ) { continue; }
			$held = (int) $wpdb->get_var( $wpdb->prepare( $held_sql . 't.occurrence_id=%d', $now, (int) $occ_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- static SQL with placeholders.
			if ( '' !== $wpdb->last_error ) { return 'db_busy'; }
			if ( $held + (int) $quantity > $cap ) { return 'manual_hold_limit'; }
		}
		return '';
	}

	/** manual_hold_minutes() for a known start timestamp (0 = unknown: no cap). */
	public static function manual_hold_until( $start_ts, $minutes = null ) {
		$minutes = null === $minutes ? max( 1, (int) Settings::get( 'manual_hold_hours' ) ) * 60 : (int) $minutes;
		if ( ! $start_ts ) { return $minutes; }
		$left = (int) floor( ( (int) $start_ts - Clock::now() ) / 60 );
		$cutoff = max( 0, (int) Settings::get( 'manual_cutoff_hours' ) ) * 60;
		if ( $left < 1 || $left < $cutoff ) { return null; }
		return max( 1, min( $minutes, $left ) );
	}

	/** Extends active holds of a pending order (e.g. WooCommerce "on-hold" bank transfers). */
	public static function extend_hold( $order_id, $minutes ) {
		return self::extend_hold_to( $order_id, Clock::now() + max( 1, absint( $minutes ) ) * MINUTE_IN_SECONDS );
	}

	/** Extends the active, unexpired holds of an order to at least $until_ts (never shortens). */
	public static function extend_hold_to( $order_id, $until_ts ) {
		global $wpdb;
		$until = gmdate( 'Y-m-d H:i:s', (int) $until_ts );
		return $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_reservations SET expires_at=GREATEST(expires_at,%s) WHERE order_id=%d AND status='active' AND expires_at > %s", $until, absint( $order_id ), Clock::mysql() ) );
	}

	/** End of the order's hold (earliest active, unexpired hold) as timestamp; 0 without one. */
	public static function hold_end( $order_id ) {
		global $wpdb;
		$end = $wpdb->get_var( $wpdb->prepare( "SELECT MIN(expires_at) FROM {$wpdb->prefix}wpcap_reservations WHERE order_id=%d AND status='active' AND expires_at > %s", absint( $order_id ), Clock::mysql() ) );
		return $end ? (int) strtotime( $end . ' UTC' ) : 0;
	}

	/**
	 * Delayed payment methods (Stripe asynchronous methods such as SEPA Direct Debit, Mollie bank
	 * transfer) confirm days after the buyer finished the payment page. When the buyer chose one,
	 * the hold is extended like a bank transfer hold (`manual_hold_hours`, at most until the start,
	 * not within `manual_cutoff_hours`; see manual_hold_minutes()), so the payment does not arrive
	 * after the hold (A577).
	 *
	 * @return int|null New hold end (timestamp), or null when the start is too close (no extension:
	 *                  a later payment goes through the late payment re-check).
	 */
	public static function extend_hold_for_delayed_payment( $order_id ) {
		$type_ids = array_map( static function ( $item ) { return (int) $item->ticket_type_id; }, self::items_for_order( $order_id ) );
		$minutes = self::manual_hold_minutes( $type_ids );
		if ( null === $minutes ) { return null; }
		$until = Clock::now() + $minutes * MINUTE_IN_SECONDS;
		self::extend_hold_to( $order_id, $until );
		Audit::record( 'hold_extended_delayed_payment', 'order', absint( $order_id ), array( 'minutes' => $minutes ) );
		return max( $until, self::hold_end( $order_id ) );
	}

	/**
	 * Cancels an order: releases holds and campaign reservations; for paid orders all still valid
	 * tickets are revoked (checked-in tickets stay as historical record). Idempotent. Every database
	 * step and the commit are checked: on an error the transaction is rolled back (nothing changed, no
	 * side effects fired) and a retryable WP_Error (`wpcap_db_busy`) is returned, so a webhook is
	 * redelivered instead of being recorded as processed.
	 *
	 * The third parameter guards the cancellation by the payment status read under the row lock:
	 * - string[] (A267 style): cancel only while the payment status is one of these; otherwise WP_Error
	 *   `wpcap_order_state` (e.g. a "payment failed" event that lost the race against the confirmation);
	 * - true (only unpaid): cancel only while the order was never paid (payment status `pending` or
	 *   `expired`); otherwise WP_Error `wpcap_order_paid_state` (provider refund events of unpaid orders
	 *   must not cancel a payment that was booked in the meantime).
	 *
	 * @param int           $order_id            Order.
	 * @param string        $reason              Audit reason.
	 * @param string[]|bool $only_payment_status Optional guard (see above).
	 * @return true|\WP_Error
	 */
	public static function cancel_order( $order_id, $reason = '', $only_payment_status = array() ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order_id = absint( $order_id );
		$now = Clock::mysql();
		$only_unpaid = true === $only_payment_status;
		$only_payment_status = $only_unpaid ? array( 'pending', 'expired' ) : array_map( 'strval', (array) $only_payment_status );
		$busy = static function ( $step, $order = null ) use ( $wpdb, $order_id, $reason ) {
			$error = (string) $wpdb->last_error;
			$wpdb->query( 'ROLLBACK' );
			Log::error( 'order_state_db_error', 'Order state change hit a database error.', array( 'object_type' => 'order', 'object_id' => $order_id, 'details' => array( 'step' => sanitize_key( $step ), 'error' => substr( $error, 0, 200 ) ) ) );
			Audit::record( 'order_cancel_failed', 'order', $order_id, array( 'step' => sanitize_key( $step ) ) );
			if ( $order ) {
				// Safety net for callers that cannot retry themselves (hooks, sweeps): a job repeats the
				// cancellation, but only while the payment status is still the one seen here.
				Jobs::enqueue( 'order-cancel-retry|' . $order_id . '|' . $order->payment_status, 'order_cancel_retry', array( 'order_id' => $order_id, 'reason' => (string) $reason, 'payment_status' => (string) $order->payment_status ), gmdate( 'Y-m-d H:i:s', Clock::now() + 2 * MINUTE_IN_SECONDS ) );
			}
			return Errors::busy( __( 'Die Bestellung konnte gerade nicht storniert werden. Bitte erneut versuchen.', 'pass-captain' ), 'wpcap_db_busy', array( 'step' => (string) $step ) );
		};
		if ( false === self::begin() ) {
			return $busy( 'cancel_start' );
		}
		$order = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d FOR UPDATE', $p . 'wpcap_orders', $order_id ) );
		if ( '' !== (string) $wpdb->last_error ) {
			return $busy( 'cancel_lock' );
		}
		if ( ! $order || in_array( $order->status, array( 'cancelled', 'refunded' ), true ) ) {
			$wpdb->query( 'COMMIT' );
			return true;
		}
		if ( $only_payment_status && ! in_array( (string) $order->payment_status, $only_payment_status, true ) ) {
			// E.g. a "payment failed" event that lost the race against the confirmation: nothing to cancel.
			$wpdb->query( 'COMMIT' );
			return new \WP_Error( $only_unpaid ? 'wpcap_order_paid_state' : 'wpcap_order_state', __( 'Der Bestellstatus erlaubt diese Aktion nicht.', 'pass-captain' ), array( 'status' => 409, 'payment_status' => (string) $order->payment_status ) );
		}
		if ( false === $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='released' WHERE order_id=%d AND status='active'", $p . 'wpcap_reservations', $order_id ) ) ) {
			return $busy( 'cancel_release', $order );
		}
		$revoked_ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM %i WHERE order_id=%d AND status='valid' FOR UPDATE", $p . 'wpcap_tickets', $order_id ) ) );
		if ( '' !== $wpdb->last_error ) { return $busy( 'cancel_tickets', $order ); }
		if ( $revoked_ids && false === $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='revoked' WHERE order_id=%d AND status='valid'", $p . 'wpcap_tickets', $order_id ) ) ) {
			return $busy( 'cancel_revoke', $order );
		}
		// The whole order stops counting; every ticket records that its unit is released (CapacityRelease).
		if ( ! CapacityRelease::mark_order( $order_id ) ) {
			return $busy( 'cancel_release_units', $order );
		}
		$revoked = count( $revoked_ids );
		$updated = StateMachine::transition( 'order', "{$p}wpcap_orders", 'order_id', $order_id, 'cancelled', array( 'payment_status' => 'cancelled', 'updated_at' => $now ) );
		if ( 1 !== (int) $updated ) {
			return $busy( 'cancel_order', $order );
		}
		if ( false === self::in_transaction() || false === $wpdb->query( 'COMMIT' ) ) {
			return $busy( 'cancel_commit', $order );
		}
		// Released units leave the counters right after the commit (until then they only over-count).
		CapacityCounters::sync_order_now( $order_id );
		Campaigns::release_for_order( $order_id );
		self::capacity_released( $order_id, 'cancelled', array( 'reason' => (string) $reason ) );
		Audit::record( 'order_cancelled', 'order', $order_id, array( 'reason' => sanitize_text_field( $reason ), 'revoked_tickets' => $revoked ) );
		self::fire_revoked( $revoked_ids, 'order_cancelled' );
		do_action( 'wpcap_order_cancelled', $order_id, (string) $reason, (string) $order->payment_status );
		return true;
	}

	/**
	 * Revokes up to $count still valid tickets of a ticket type (newest first). Checked-in tickets
	 * are never revoked. Their units are released through CapacityRelease (once per ticket) and
	 * announced with `wpcap_capacity_released` (tickets_revoked, type, quantity). Returns the number
	 * of revoked tickets.
	 */
	public static function revoke_tickets( $order_id, $ticket_type_id, $count, $reason = '' ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order_id = absint( $order_id );
		$count = absint( $count );
		if ( ! $count ) { return 0; }
		// Inside a caller's transaction (MariaDB reports it) the revoke joins it.
		$nested = true === self::transaction_alive();
		if ( ! $nested && false === self::begin() ) { return 0; }
		$wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM {$p}wpcap_orders WHERE order_id=%d FOR UPDATE", $order_id ) );
		$candidates = '' === $wpdb->last_error ? $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=%d AND ticket_type_id=%d AND status='valid' ORDER BY ticket_id DESC LIMIT %d FOR UPDATE", $order_id, absint( $ticket_type_id ), $count ) ) : array();
		$revoked_ids = '' === $wpdb->last_error ? self::revoke_ids( $candidates ) : array();
		// Revoked units no longer consume capacity.
		$released = $revoked_ids ? CapacityRelease::release_tickets( $order_id, $revoked_ids ) : array();
		if ( false === $released || false === self::transaction_alive() || ( ! $nested && false === $wpdb->query( 'COMMIT' ) ) ) {
			if ( ! $nested ) { $wpdb->query( 'ROLLBACK' ); }
			return 0;
		}
		$revoked = count( $revoked_ids );
		self::fire_revoked( $revoked_ids, $reason );
		if ( $revoked ) {
			CapacityCounters::sync_order_now( $order_id );
			CapacityRelease::announce( $order_id, $released, 'tickets_revoked', array( 'reason' => (string) $reason ) );
			Audit::record( 'tickets_revoked', 'order', $order_id, array( 'ticket_type_id' => absint( $ticket_type_id ), 'count' => $revoked, 'reason' => sanitize_text_field( $reason ) ) );
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
		// The money is already back with the buyer: a failure is repaired by a job, never dropped.
		$busy = static function ( $step ) use ( $wpdb, $order_id, $reason ) {
			$error = (string) $wpdb->last_error;
			$wpdb->query( 'ROLLBACK' );
			Log::error( 'order_state_db_error', 'Order state change hit a database error.', array( 'object_type' => 'order', 'object_id' => $order_id, 'details' => array( 'step' => sanitize_key( $step ), 'error' => substr( $error, 0, 200 ) ) ) );
			Audit::record( 'order_refund_mark_failed', 'order', $order_id, array( 'step' => sanitize_key( $step ) ) );
			Jobs::enqueue( 'order-refund-mark-retry|' . $order_id, 'order_refund_mark_retry', array( 'order_id' => $order_id, 'reason' => (string) $reason ), gmdate( 'Y-m-d H:i:s', Clock::now() + 2 * MINUTE_IN_SECONDS ) );
			return Errors::busy( __( 'Die Bestellung konnte gerade nicht als erstattet markiert werden; das wird automatisch nachgeholt.', 'pass-captain' ), 'wpcap_db_busy', array( 'step' => (string) $step ) );
		};
		if ( false === self::begin() ) { return $busy( 'refund_start' ); }
		$order = $wpdb->get_row( $wpdb->prepare( 'SELECT status FROM %i WHERE order_id=%d FOR UPDATE', $p . 'wpcap_orders', $order_id ) );
		if ( '' !== (string) $wpdb->last_error ) { return $busy( 'refund_lock' ); }
		if ( ! $order || 'refunded' === $order->status ) { $wpdb->query( 'COMMIT' ); return true; }
		if ( ! StateMachine::allowed( 'order', $order->status, 'refunded' ) ) {
			// Never paid (pending_payment, expired): there is nothing to refund. Final, no retry job.
			$wpdb->query( 'COMMIT' );
			Audit::record( 'order_refund_mark_rejected', 'order', $order_id, array( 'status' => (string) $order->status, 'reason' => sanitize_text_field( $reason ) ) );
			return new \WP_Error( 'wpcap_order_state', __( 'Der Bestellstatus erlaubt diese Aktion nicht.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		if ( false === $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='released' WHERE order_id=%d AND status='active'", $p . 'wpcap_reservations', $order_id ) ) ) { return $busy( 'refund_release' ); }
		$revoked_ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM %i WHERE order_id=%d AND status='valid' FOR UPDATE", $p . 'wpcap_tickets', $order_id ) ) );
		if ( '' !== $wpdb->last_error ) { return $busy( 'refund_tickets' ); }
		if ( $revoked_ids && false === $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='revoked' WHERE order_id=%d AND status='valid'", $p . 'wpcap_tickets', $order_id ) ) ) { return $busy( 'refund_revoke' ); }
		if ( ! CapacityRelease::mark_order( $order_id ) ) { return $busy( 'refund_release_units' ); }
		$revoked = count( $revoked_ids );
		$updated = StateMachine::transition( 'order', "{$p}wpcap_orders", 'order_id', $order_id, 'refunded', array( 'payment_status' => 'refunded', 'updated_at' => Clock::mysql() ) );
		if ( 1 !== (int) $updated ) { return $busy( 'refund_order' ); }
		if ( false === self::in_transaction() || false === $wpdb->query( 'COMMIT' ) ) { return $busy( 'refund_commit' ); }
		CapacityCounters::sync_order_now( $order_id );
		self::capacity_released( $order_id, 'refunded', array( 'reason' => (string) $reason ) );
		Audit::record( 'order_refunded', 'order', $order_id, array( 'reason' => sanitize_text_field( $reason ), 'revoked_tickets' => $revoked ) );
		self::fire_revoked( $revoked_ids, $reason ?: 'refunded' );
		/** Order fully refunded (native refund, WooCommerce or provider dashboard). */
		do_action( 'wpcap_order_refunded', $order_id, (string) $reason );
		return true;
	}

	/** Mail variables that carry a ticket code (link, help code/extras); never for a previous holder (A652). */
	const CODE_MAIL_VARS = array( 'ticket_link', 'ticket_links', 'ticket_extras', 'help_code' );

	/** $vars without the variables that carry the (new) ticket code, for mails to a previous holder (A652). */
	public static function without_code_vars( array $vars ) {
		return array_diff_key( $vars, array_flip( self::CODE_MAIL_VARS ) );
	}

	/** Days a pending order link (A631) can be opened before it lapses; the current link stays meanwhile. */
	const PENDING_LINK_DAYS = 7;

	/**
	 * Issues a new personal order access link and emails it.
	 *
	 * @param int  $order_id     Order.
	 * @param bool $keep_current A631: the current link stays valid until the new one is opened for the
	 *                           first time (requests by anyone holding a ticket code, e.g. a photographed
	 *                           QR code, must not lock the buyer out). Default: the old link ends now.
	 */
	public static function rotate_access_link( $order_id, $keep_current = false ) {
		global $wpdb;
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT order_id,public_id,customer_email FROM {$wpdb->prefix}wpcap_orders WHERE order_id=%d", absint( $order_id ) ) );
		if ( ! $order ) { return false; }
		$token = bin2hex( random_bytes( 32 ) );
		if ( $keep_current && self::pending_link_ready() ) {
			$wpdb->update( $wpdb->prefix . 'wpcap_orders', array( 'access_pending_hash' => hash( 'sha256', $token ), 'access_pending_expires_at' => gmdate( 'Y-m-d H:i:s', Clock::now() + self::PENDING_LINK_DAYS * DAY_IN_SECONDS ) ), array( 'order_id' => (int) $order->order_id ), array( '%s', '%s' ), array( '%d' ) );
		} else {
			$fields = array( 'access_token_hash' => hash( 'sha256', $token ), 'access_expires_at' => gmdate( 'Y-m-d H:i:s', Clock::now() + 30 * DAY_IN_SECONDS ) );
			if ( self::pending_link_ready() ) { $fields += array( 'access_pending_hash' => '', 'access_pending_expires_at' => null ); }
			$wpdb->update( $wpdb->prefix . 'wpcap_orders', $fields, array( 'order_id' => (int) $order->order_id ), null, array( '%d' ) );
		}
		return self::send_order_access_link( $order->customer_email, $order->public_id, $token );
	}

	/** Drops a pending order link (A631), e.g. when the order is anonymised. */
	public static function clear_pending_link( $order_id ) {
		if ( ! self::pending_link_ready() ) { return; }
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_orders SET access_pending_hash='',access_pending_expires_at=NULL WHERE order_id=%d", absint( $order_id ) ) );
	}

	/** Whether the pending-link columns of schema 3.10.25 exist (A631). */
	public static function pending_link_ready() {
		return Log::has_column( 'wpcap_orders', 'access_pending_hash' );
	}

	/**
	 * Whether $token opens the order portal of $order (row with order_id, access_token_hash,
	 * access_expires_at): the current link while it has not expired, or a pending link (A631) – which
	 * then becomes the current one, so the previous link ends with the first use of the new one.
	 *
	 * @param object|array|null $order Order row.
	 * @param mixed             $token Access token from the link or request.
	 */
	public static function access_matches( $order, $token ) {
		$order = is_array( $order ) ? (object) $order : $order;
		$token = strtolower( preg_replace( '/[^a-f0-9]/', '', Http::str( $token ) ) );
		if ( ! is_object( $order ) || 64 !== strlen( $token ) ) { return false; }
		$hash = hash( 'sha256', $token );
		$now = Clock::mysql();
		if ( ! empty( $order->access_expires_at ) && (string) $order->access_expires_at > $now && hash_equals( (string) ( $order->access_token_hash ?? '' ), $hash ) ) { return true; }
		if ( empty( $order->order_id ) || ! self::pending_link_ready() ) { return false; }
		global $wpdb;
		$table = $wpdb->prefix . 'wpcap_orders';
		$pending = $wpdb->get_row( $wpdb->prepare( "SELECT access_pending_hash,access_pending_expires_at FROM $table WHERE order_id=%d", (int) $order->order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		if ( ! $pending || '' === (string) $pending->access_pending_hash || ! $pending->access_pending_expires_at || (string) $pending->access_pending_expires_at <= $now || ! hash_equals( (string) $pending->access_pending_hash, $hash ) ) { return false; }
		$expires = gmdate( 'Y-m-d H:i:s', Clock::now() + 30 * DAY_IN_SECONDS );
		// Promoted once (parallel first clicks: the condition on the pending hash lets one of them write).
		$wpdb->query( $wpdb->prepare( "UPDATE $table SET access_token_hash=%s,access_expires_at=%s,access_pending_hash='',access_pending_expires_at=NULL WHERE order_id=%d AND access_pending_hash=%s", $hash, $expires, (int) $order->order_id, $hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$current = (string) $wpdb->get_var( $wpdb->prepare( "SELECT access_token_hash FROM $table WHERE order_id=%d", (int) $order->order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		if ( ! hash_equals( $current, $hash ) ) { return false; }
		$order->access_token_hash = $hash;
		$order->access_expires_at = $expires;
		Audit::record( 'order_link_activated', 'order', (int) $order->order_id, array() );
		return true;
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
		$wpdb->update( $wpdb->prefix . 'wpcap_orders', array( 'provider_reference' => \PassCaptain\Text::cut( sanitize_text_field( $reference ), 191 ) ), array( 'order_id' => absint( $order_id ) ) );
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
		global $wpdb;
		$url = add_query_arg( array( 'wpcap_order' => $public_id, 'wpcap_access' => $access_token ), home_url( '/' ) );
		if ( null === $locale ) {
			$locale = (string) $wpdb->get_var( $wpdb->prepare( "SELECT locale FROM {$wpdb->prefix}wpcap_orders WHERE public_id=%s", (string) $public_id ) );
		}
		// Order of the mail for the support log/order history (A328): one primary-key-free lookup by public id.
		$order_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM {$wpdb->prefix}wpcap_orders WHERE public_id=%s", (string) $public_id ) );
		return Mail\Mailer::send( 'order_access', sanitize_email( $email ), array( 'order_id' => (string) $public_id, 'access_link' => Mail\Mailer::link( $url ) ), array(), (string) $locale, $order_id );
	}

	public static function transfer_ticket( $public_id, $access_token, $ticket_public_id, $recipient_email ) {
		global $wpdb;
		$public_id = sanitize_text_field( Http::str( $public_id ) );
		$access_token = Security\Input::hex( Http::str( $access_token ) );
		$recipient_email = Http::email( $recipient_email );
		if ( Http::email_too_long( $recipient_email ) ) {
			return new \WP_Error( 'wpcap_transfer_invalid', self::email_too_long_message(), array( 'status' => 400, 'reason' => 'email_too_long', 'field' => 'recipient_email' ) );
		}
		if ( 32 !== strlen( $public_id ) || 64 !== strlen( $access_token ) || ! is_email( $recipient_email ) ) {
			return new \WP_Error( 'wpcap_transfer_invalid', __( 'Die Übertragungsdaten sind ungültig.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$order = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE public_id=%s', $wpdb->prefix . 'wpcap_orders', $public_id ) );
		if ( ! self::access_matches( $order, $access_token ) ) {
			return new \WP_Error( 'wpcap_order_access_denied', __( 'Der Bestellzugriff ist ungültig.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		if ( 'paid' !== $order->payment_status ) {
			return new \WP_Error( 'wpcap_order_not_paid', __( 'Tickets können erst nach bestätigter Zahlung übertragen werden.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$tickets_table = $wpdb->prefix . 'wpcap_tickets';
		self::begin();
		// Order row first (same lock order as refunds): a refund request cannot slip in between.
		$wpdb->get_var( $wpdb->prepare( 'SELECT order_id FROM %i WHERE order_id=%d FOR UPDATE', $wpdb->prefix . 'wpcap_orders', (int) $order->order_id ) );
		$ticket = $wpdb->get_row( $wpdb->prepare(
			'SELECT t.ticket_id,t.status,t.transfer_count,tt.name AS ticket_name,e.name AS event_name,o.starts_at,o.timezone,o.location FROM %i t INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id INNER JOIN %i e ON e.event_id=o.event_id WHERE t.public_id=%s AND t.order_id=%d FOR UPDATE', $tickets_table, $wpdb->prefix . 'wpcap_ticket_types', $wpdb->prefix . 'wpcap_occurrences', $wpdb->prefix . 'wpcap_events',
			sanitize_text_field( $ticket_public_id ),
			$order->order_id
		) );
		if ( ! $ticket || 'valid' !== $ticket->status || (int) $ticket->transfer_count >= 1 || strtotime( $ticket->starts_at . ' UTC' ) <= Clock::now() ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_transfer_not_allowed', __( 'Dieses Ticket kann nicht mehr übertragen werden.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		// A537: while a refund of the ticket is pending or running, it stays with the buyer.
		if ( false !== Refunds::ticket_has_open_refund( (int) $order->order_id, (int) $ticket->ticket_id ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_ticket_refund_open', __( 'Für dieses Ticket läuft eine Erstattung oder sie wartet auf Freigabe. Es kann bis zur Entscheidung nicht übertragen, umgetauscht oder widerrufen werden.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		// A holder change by assignment counts against the same limit as a transfer.
		if ( method_exists( TicketAssignments::class, 'holder_changes' ) && TicketAssignments::holder_changes( (int) $ticket->ticket_id ) >= (int) apply_filters( 'wpcap_max_holder_changes', 1, (int) $ticket->ticket_id ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_transfer_holder_changed', __( 'Für dieses Ticket wurde der Inhaber bereits geändert; eine weitere Übertragung ist nicht möglich.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		// A593: the transfer rotates the code – not once admission has begun or the code is on offline scanners.
		$locked = Admission\CodeRotationLock::reason( (int) $ticket->ticket_id );
		if ( '' !== $locked ) {
			$wpdb->query( 'ROLLBACK' );
			return Admission\CodeRotationLock::error( $locked );
		}
		$previous_holder = Consents::holder_email_of( (int) $ticket->ticket_id );
		$token = bin2hex( random_bytes( 32 ) );
		CodeHistory::replacing( (int) $ticket->ticket_id, 'transfer' ); // A543: the old code says "transferred".
		$set = array(
			'token_hash' => hash( 'sha256', $token ),
			'attendee_email' => $recipient_email,
			'transfer_count' => (int) $ticket->transfer_count + 1,
		) + ( TicketCodes::holder_ready() ? array( 'code_with_attendee' => 1 ) : array() ); // A581.
		$updated = $wpdb->update( $tickets_table, $set, array( 'ticket_id' => (int) $ticket->ticket_id, 'status' => 'valid', 'transfer_count' => (int) $ticket->transfer_count ), array_slice( array( '%s', '%s', '%d', '%d' ), 0, count( $set ) ), array( '%d', '%s', '%d' ) );
		if ( false === $updated ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_transfer_failed', __( 'Die Ticketübertragung konnte nicht gespeichert werden.', 'pass-captain' ), array( 'status' => 500 ) );
		}
		// The previous holder's name and custom answers must not stay on the transferred ticket.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET attendee_name='',attendee_email=%s WHERE ticket_id=%d", $wpdb->prefix . 'wpcap_ticket_assignments', $recipient_email, (int) $ticket->ticket_id ) );
		$wpdb->delete( $wpdb->prefix . 'wpcap_participant_answers', array( 'ticket_id' => (int) $ticket->ticket_id ) );
		$wpdb->query( 'COMMIT' );
		// The previous holder's consents end with the ticket (logged, hooks fire).
		Consents::end_for_ticket( (int) $ticket->ticket_id, 'transfer', $previous_holder );
		/** A ticket received a new code; the old one is invalid. $token is plaintext – never store it. */
		do_action( 'wpcap_ticket_code_rotated', (int) $ticket->ticket_id, $token, 'transfer' );
		/** A674: another person holds the ticket now (after the commit; no code, no addresses). */
		do_action( 'wpcap_ticket_transferred', (int) $ticket->ticket_id, array( 'order_id' => (int) $order->order_id, 'source' => 'portal', 'holder_changes' => (int) $ticket->transfer_count + 1 + TicketAssignments::holder_changes( (int) $ticket->ticket_id ) ) );
		$ticket_url = add_query_arg( 'wpcap_ticket', $token, home_url( '/' ) );
		$vars = array(
			'order_id' => (string) $order->public_id,
			'event_name' => (string) $ticket->event_name,
			'ticket_name' => (string) $ticket->ticket_name,
			'event_date' => self::format_start( $ticket->starts_at, $ticket->timezone ),
			'event_location' => (string) $ticket->location,
			'ticket_link' => Mail\Mailer::link( $ticket_url ),
			'ticket_extras' => Mail\Blocks::extra( self::code_mail_section( (int) $ticket->ticket_id, (string) $order->locale ) ),
			'recipient_email' => $recipient_email,
		);
		// The recipient's language is unknown; the order's locale is the best available guess.
		if ( ! Mail\Mailer::send( 'ticket_transfer_recipient', $recipient_email, $vars, array(), (string) $order->locale, (int) $order->order_id ) ) {
			return new \WP_Error( 'wpcap_transfer_mail_failed', __( 'Die Ticketübertragung wurde gespeichert, aber die E-Mail konnte nicht zugestellt werden. Bitte kontaktiere den Veranstalter für einen erneuten Versand.', 'pass-captain' ), array( 'status' => 502 ) );
		}
		// A652: the previous holder learns that the ticket moved – never the new code (link, help code).
		Mail\Mailer::send( 'ticket_transfer_sender', $order->customer_email, self::without_code_vars( $vars ), array(), (string) $order->locale, (int) $order->order_id );
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
	public static function cancel_event( $event_id, $reason = '' ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$started = microtime( true );
		$event_id = absint( $event_id );
		$event = $wpdb->get_row( $wpdb->prepare( 'SELECT event_id,name,status FROM %i WHERE event_id=%d', $p . 'wpcap_events', $event_id ) );
		if ( ! $event || 'published' !== $event->status ) {
			return new \WP_Error( 'wpcap_event_not_cancellable', __( 'Dieses Event kann nicht abgesagt werden.', 'pass-captain' ) );
		}
		$order_ids = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT oi.order_id FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE o.event_id=%d", $event_id ) ) );
		$pending_before = $order_ids ? array_map( 'absint', $wpdb->get_col( "SELECT order_id FROM {$p}wpcap_orders WHERE order_id IN (" . implode( ',', $order_ids ) . ") AND payment_status IN ('pending','expired')" ) ) : array();

		self::begin();
		$changed = $wpdb->update( "{$p}wpcap_events", array( 'status' => 'cancelled', 'updated_at' => Clock::mysql() ), array( 'event_id' => $event_id, 'status' => 'published' ), array( '%s', '%s' ), array( '%d', '%s' ) );
		if ( 1 !== (int) $changed ) {
			// A concurrent request cancelled (or changed) the event first.
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_event_not_cancellable', __( 'Dieses Event kann nicht abgesagt werden.', 'pass-captain' ) );
		}
		$occurrence_ids = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT occurrence_id FROM {$p}wpcap_occurrences WHERE event_id=%d AND status<>'cancelled' ORDER BY occurrence_id FOR UPDATE", $event_id ) ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_occurrences SET status='cancelled' WHERE event_id=%d", $event_id ) );
		Calendar\Revisions::bump_many( $occurrence_ids ); // A140: SEQUENCE + 1 for STATUS:CANCELLED.
		$wpdb->query( 'COMMIT' );
		PageCache::event_changed( $event_id, 'event' ); // A446.

		// A428: the consequences run as a resumable run (BulkRuns): event mails are queued first (while
		// the orders are unchanged), then every occurrence is cancelled in its own run, refunds run as
		// jobs, and the work that does not fit into this request continues in the background.
		$run = BulkRuns::start( 'event_cancel', $event_id, 'event_cancel|' . $event_id, array(
			'event_name' => (string) $event->name,
			'occurrence_ids' => $occurrence_ids,
			'pending_before' => $pending_before,
			'auto_refund' => (bool) Settings::get( 'auto_refund_cancelled' ),
		) );
		Audit::record( 'event_cancelled', 'event', $event_id, array( 'occurrences' => count( $occurrence_ids ), 'run_id' => (int) $run->run_id ) + ( '' !== (string) $reason ? array( 'reason' => sanitize_text_field( (string) $reason ) ) : array() ) );
		/** A674: the event is cancelled (committed); its occurrences follow in the run (wpcap_occurrence_cancelled each). */
		do_action( 'wpcap_event_cancelled', $event_id, array( 'occurrence_ids' => $occurrence_ids, 'run_id' => (int) $run->run_id ) );
		return self::event_cancellation_result( BulkRuns::drive( $run ) );
	}

	/**
	 * Continues the cancellation of an already cancelled event (A428: "a second click resumes"):
	 * works on its run, or starts the run if the request that cancelled the event died before.
	 *
	 * @return array|\WP_Error Same keys as cancel_event() plus resumed.
	 */
	public static function resume_event_cancellation( $event_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$event_id = absint( $event_id );
		$event = $wpdb->get_row( $wpdb->prepare( "SELECT event_id,name,status FROM {$p}wpcap_events WHERE event_id=%d", $event_id ) );
		if ( ! $event || 'cancelled' !== $event->status ) {
			return new \WP_Error( 'wpcap_event_not_cancelled', __( 'Dieses Event ist nicht abgesagt.', 'pass-captain' ) );
		}
		$run = BulkRuns::event_run( $event_id );
		if ( ! $run ) {
			$pending = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT ord.order_id FROM {$p}wpcap_orders ord INNER JOIN {$p}wpcap_order_items oi ON oi.order_id=ord.order_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE o.event_id=%d AND ord.payment_status='pending'", $event_id ) ) );
			$run = BulkRuns::start( 'event_cancel', $event_id, 'event_cancel|' . $event_id, array(
				'event_name' => (string) $event->name,
				'occurrence_ids' => array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT occurrence_id FROM {$p}wpcap_occurrences WHERE event_id=%d ORDER BY occurrence_id", $event_id ) ) ),
				'pending_before' => $pending,
				'auto_refund' => (bool) Settings::get( 'auto_refund_cancelled' ),
			) );
		}
		return self::event_cancellation_result( BulkRuns::drive( $run ) ) + array( 'resumed' => true );
	}

	/** Result array of an event cancellation run. */
	private static function event_cancellation_result( $run ) {
		$summary = BulkRuns::summary( $run );
		return array(
			'cancelled_orders' => (int) ( $summary['cancelled_orders'] ?? 0 ),
			'refunds' => (int) ( $summary['refunds'] ?? 0 ),
			'refund_errors' => (int) ( $summary['refund_errors'] ?? 0 ),
			'revoked' => (int) ( $summary['revoked'] ?? 0 ),
			'status' => 'cancelled',
			'notified_count' => (int) ( $summary['notified'] ?? 0 ),
			'occurrences' => count( (array) ( $run->data['occurrence_ids'] ?? array() ) ),
			'auto_refund' => ! empty( $run->data['auto_refund'] ),
			'run_id' => (int) $run->run_id,
			'complete' => ! empty( $summary['complete'] ),
		);
	}

	/**
	 * Tickets of an order that can still be refunded natively: not refunded yet, and either valid,
	 * checked in, or revoked because their occurrence or event was cancelled (the buyer paid for an
	 * event that did not take place). Tickets revoked for other reasons are not refundable here.
	 *
	 * @param int       $order_id Order.
	 * @param bool|null $failed   Set to true when a read failed (the list is then incomplete, A183).
	 * @return object[] Rows {ticket_id, ticket_type_id}, ordered by ticket_id.
	 */
	public static function refundable_tickets( $order_id, &$failed = null ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT t.ticket_id,t.ticket_type_id FROM %i t LEFT JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id LEFT JOIN %i o ON o.occurrence_id=tt.occurrence_id LEFT JOIN %i e ON e.event_id=o.event_id WHERE t.order_id=%d AND t.refunded=0 AND (t.status IN ('valid','checked_in') OR (t.status='revoked' AND (o.status='cancelled' OR e.status='cancelled'))) ORDER BY t.ticket_id", $p . 'wpcap_tickets', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $p . 'wpcap_events',
			absint( $order_id )
		) );
		$failed = '' !== $wpdb->last_error;
		$rows = (array) apply_filters( 'wpcap_refundable_tickets', $rows, absint( $order_id ) );
		$failed = $failed || '' !== $wpdb->last_error;
		return $rows;
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
		if ( $order && 'paid' === $order->payment_status ) {
			// Booked earlier, but its issuance may have failed (database error): complete it.
			CapacityCounters::sync_order_now( (int) $order_id );
			self::issue_and_email( (int) $order_id );
		}
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
		$now = Clock::mysql();
		$reservations = $wpdb->prefix . 'wpcap_reservations';
		$orders = $wpdb->prefix . 'wpcap_orders';
		$limit = max( 1, (int) apply_filters( 'wpcap_expire_holds_batch', absint( $limit ) ?: 200 ) );
		self::$last_expire_batch_full = false;
		$candidates = $wpdb->get_col( $wpdb->prepare(
			"SELECT ord.order_id FROM %i ord WHERE ord.payment_status='pending' AND " . StateMachine::source_sql( 'order', 'expired', 'ord.status' ) . " AND NOT EXISTS (SELECT 1 FROM %i r WHERE r.order_id=ord.order_id AND r.status='active' AND r.expires_at>%s) ORDER BY ord.order_id LIMIT %d", $orders, $reservations, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- static state list.
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
			// The lapsed holds leave held_qty (capacity counters) right after the commit.
			CapacityCounters::sync_order_now( $order_id );
			++$expired;
			Campaigns::release_for_order( $order_id );
			self::capacity_released( $order_id, 'expired' );
			do_action( 'wpcap_order_cancelled', $order_id );
		}
		// Lapsed holds of orders that are not pending any more (they no longer count anyway): by id.
		$stale = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( "SELECT r.reservation_id FROM %i r INNER JOIN %i ord ON ord.order_id=r.order_id WHERE r.status='active' AND r.expires_at<=%s AND ord.payment_status<>'pending' ORDER BY r.reservation_id LIMIT %d", $reservations, $orders, $now, $limit ) ) );
		$stale_orders = ( $stale && CapacityCounters::maintained() ) ? array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT order_id FROM %i WHERE reservation_id IN (' . implode( ',', array_fill( 0, count( $stale ), '%d' ) ) . ') ORDER BY order_id', array_merge( array( $reservations ), $stale ) ) ) ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer placeholders.
		if ( $stale && false === $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='expired' WHERE reservation_id IN (" . implode( ',', array_fill( 0, count( $stale ), '%d' ) ) . ") AND status='active' AND expires_at<=%s", array_merge( array( $reservations ), $stale, array( $now ) ) ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer placeholders.
			$failed[0] = 'stale_holds';
		} else {
			// Their held units leave the counters (they over-counted until now).
			foreach ( $stale_orders as $stale_order_id ) { CapacityCounters::sync_order_now( $stale_order_id ); }
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
		// Guarded by the state machine (pending_payment → expired); another state is left alone.
		$moved = StateMachine::transition( 'order', "{$p}wpcap_orders", 'order_id', $order_id, 'expired', array( 'payment_status' => 'expired', 'updated_at' => $now ) );
		if ( false === $moved ) { return $fail( 'expire_order' ); }
		if ( 1 !== (int) $moved ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
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

	/**
	 * Tickets per order up to which the ticket mail (PDF, wallet, SMTP) is sent inside the request that
	 * confirmed the payment (filter wp_pass_captain_ticket_mail_inline_max_v1). Larger orders – and every order
	 * confirmed by a payment webhook – are delivered by the outbox job right away instead.
	 */
	const INLINE_DELIVERY_MAX = 10;

	/** Depth of defer_ticket_mail() calls (payment webhooks): ticket mails go to the job queue. */
	private static $defer_mail = 0;

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
		// Same callback and priority as in Plugin::run(): WordPress registers it once.
		add_filter( 'wpcap_process_job', array( __CLASS__, 'handle_job' ), 10, 4 );
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
		// A deadlock or lock wait timeout (e.g. with a seat claim of a parallel checkout) rolls the issuance
		// back; it is retried here up to DB_RETRIES times before the job queue takes over.
		for ( $attempt = 0; ; ++$attempt ) {
			$result = self::issue_and_email_attempt( $order_id );
			$data = $result instanceof \WP_Error ? (array) $result->get_error_data() : array();
			if ( empty( $data['db_busy'] ) || $attempt >= self::DB_RETRIES ) { return $result; }
			usleep( random_int( 30, 150 ) * 1000 * ( $attempt + 1 ) );
		}
	}

	/** One issuance attempt (see issue_and_email()). */
	private static function issue_and_email_attempt( $order_id ) {
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
			$wpdb->query( 'ROLLBACK' ); // Nothing written.
			return true;
		}
		$lines = $wpdb->get_results( $wpdb->prepare( 'SELECT oi.*,tt.name AS ticket_name,tt.occurrence_id,o.event_id,e.name AS event_name,o.starts_at,o.timezone,o.location FROM %i oi INNER JOIN %i tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id INNER JOIN %i e ON e.event_id=o.event_id WHERE oi.order_id=%d', $wpdb->prefix . 'wpcap_order_items', $wpdb->prefix . 'wpcap_ticket_types', $wpdb->prefix . 'wpcap_occurrences', $wpdb->prefix . 'wpcap_events', $order_id ) );
		if ( '' !== $wpdb->last_error ) { return self::issue_failed( $order_id, 'lines' ); }
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
			$existing = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE order_id=%d AND ticket_type_id=%d', $wpdb->prefix . 'wpcap_tickets', $order_id, $line->ticket_type_id ) );
			// An unknown count would issue the tickets a second time.
			if ( '' !== $wpdb->last_error ) { return self::issue_aborted( $order, $issued, 'count' ); }
			for ( $i = $existing; $i < (int) $line->quantity; $i++ ) {
				$token = bin2hex( random_bytes( 32 ) );
				$public_id = bin2hex( random_bytes( 16 ) );
				$inserted = $wpdb->insert( $wpdb->prefix . 'wpcap_tickets', array(
					'public_id' => $public_id,
					'order_id' => $order_id,
					'ticket_type_id' => (int) $line->ticket_type_id,
					'token_hash' => hash( 'sha256', $token ),
					'status' => 'valid',
					'issued_at' => Clock::mysql(),
				), array( '%s', '%d', '%d', '%s', '%s', '%s' ) );
				$ticket_id = (int) $wpdb->insert_id;
				if ( ! $inserted || '' !== $wpdb->last_error || ! $ticket_id ) {
					// Nothing of this issuance is kept (the attendee data stays on the order for a retry).
					return self::issue_aborted( $order, $issued, 'ticket_insert' );
				}
				$issued[] = array( 'ticket_id' => $ticket_id, 'ticket_type_id' => (int) $line->ticket_type_id, 'occurrence_id' => (int) ( $line->occurrence_id ?? 0 ), 'token' => $token, 'event' => $line->event_name, 'ticket' => $line->ticket_name, 'starts_at' => $line->starts_at, 'timezone' => $line->timezone, 'location' => $line->location );
				foreach ( $pending_attendees as $index => $attendee ) {
					if ( (int) ( $attendee['ticket_type_id'] ?? 0 ) !== (int) $line->ticket_type_id ) { continue; }
					unset( $pending_attendees[ $index ] );
					if ( ! self::store_attendee( $ticket_id, $order, (array) $attendee ) ) { return self::issue_aborted( $order, $issued, 'attendee' ); }
					$issued[ count( $issued ) - 1 ]['attendee'] = sanitize_text_field( $attendee['name'] ?? '' ); // Name on the PDF (A330).
					break;
				}
				/** Inside the issuance transaction, e.g. to assign a seat or area. Do not store $token. */
				do_action( 'wpcap_ticket_created', $ticket_id, $order, $line, $i );
				// A deadlock inside a hook rolls the whole transaction back; further inserts would autocommit
				// and leave a partial issuance behind.
				if ( false === self::in_transaction() ) { return self::issue_aborted( $order, $issued, 'ticket_hook' ); }
			}
		}
		if ( $issued && $order->attendee_data && ! $pending_attendees ) {
			// Every collected attendee is stored with a ticket (each write checked above): drop the
			// order-level copy (data minimisation). Unassigned entries keep the copy for a later run.
			if ( false === $wpdb->update( $orders, array( 'attendee_data' => null ), array( 'order_id' => $order_id ) ) ) { return self::issue_aborted( $order, $issued, 'attendee_cleanup' ); }
		}
		$delivery_key = '';
		$deferred = $issued && self::defers_delivery( $order_id, count( $issued ) );
		if ( $issued ) {
			$delivery_key = 'ticket-delivery-' . $order_id . '-' . bin2hex( random_bytes( 8 ) );
			// order_id first: delivery_status() finds the job by its payload prefix. hashes: the code hash
			// of each ticket at issuance – the outbox only delivers tickets whose code is still this one
			// (a transfer, assignment or resend in between already mailed its own new code).
			$payload = array(
				'order_id' => $order_id,
				'ticket_ids' => array_map( 'intval', array_column( $issued, 'ticket_id' ) ),
				'hashes' => array_combine( array_map( 'strval', array_column( $issued, 'ticket_id' ) ), array_map( static function ( $t ) { return hash( 'sha256', $t['token'] ); }, $issued ) ),
				'initial' => $deferred ? 1 : 0,
			);
			// Deferred delivery: due right away; otherwise only after the grace period (covers a crash after commit).
			$due = $deferred ? Clock::mysql() : gmdate( 'Y-m-d H:i:s', Clock::now() + self::DELIVERY_GRACE_MINUTES * MINUTE_IN_SECONDS );
			if ( true !== Jobs::enqueue( $delivery_key, self::DELIVERY_JOB, $payload, $due ) ) {
				return self::issue_aborted( $order, $issued, 'outbox' );
			}
		}
		// A failed COMMIT (or a transaction the server rolled back) must not send tickets that do not
		// exist. The first ticket of this run tells whether the issuance is durable (A183): after a
		// lost connection wpdb reconnects and runs the rest with autocommit – if that ticket exists,
		// everything from its insert on was committed; if not, nothing before the loss was, and a
		// COMMIT that wpdb re-ran on the new session committed nothing.
		$commit_ok = false !== self::in_transaction() && false !== $wpdb->query( 'COMMIT' );
		if ( ! $commit_ok ) { $wpdb->query( 'ROLLBACK' ); }
		if ( $issued ) {
			$probe = $wpdb->prepare( 'SELECT ticket_id FROM %i WHERE ticket_id=%d', $wpdb->prefix . 'wpcap_tickets', $issued[0]['ticket_id'] );
			$first = $wpdb->get_var( $probe );
			if ( '' !== $wpdb->last_error ) { $first = $wpdb->get_var( $probe ); } // Once more on the reconnected session.
			$commit_ok = null !== $first && '' === $wpdb->last_error;
		}
		if ( ! $commit_ok ) {
			return self::issue_aborted( $order, $issued, 'commit' );
		}
		// Consent grants recorded with the tickets: hooks only now that they are committed.
		Consents::flush_pending( (int) $order_id );
		if ( $issued ) {
			self::flush_availability_cache();
			// Short help code for staff when the QR code cannot be read (stored only as keyed hash).
			foreach ( $issued as $index => $ticket ) { $issued[ $index ]['help_code'] = HelpCode::for_ticket( $ticket['ticket_id'], $ticket['token'] ); }
			do_action( 'wpcap_tickets_issued', (int) $order_id, count( $issued ) );
			// A682: the ticket mail names, per ticket, the attendee data that is still missing.
			$missing_data = ParticipantFields::missing( array_column( $issued, 'ticket_id' ) );
			if ( $missing_data ) {
				Audit::record( 'attendee_data_missing', 'order', (int) $order_id, array( 'tickets' => count( $missing_data ) ) );
				/** A682: tickets were issued with missing attendee data (ticket_id => labels). */
				do_action( 'wpcap_attendee_data_missing', (int) $order_id, $missing_data );
				foreach ( $issued as $index => $ticket ) { $issued[ $index ]['missing'] = $missing_data[ (int) $ticket['ticket_id'] ] ?? array(); }
			}
			if ( $deferred ) {
				// Large order or payment webhook: no PDF/wallet/SMTP work in this request. The outbox job
				// (due now) mails new codes for the still unchanged tickets; the codes created here were
				// never shown to anybody and are never stored in plaintext (not even encrypted).
				Jobs\TimeBudget::continue_later( 'wpcap_process_jobs' );
				Audit::record( 'ticket_delivery_deferred', 'order', $order_id, array( 'ticket_count' => count( $issued ), 'webhook' => self::$defer_mail > 0 ) );
				return true;
			}
			$delivered = self::send_ticket_mail( $order, $issued );
			if ( ! $delivered ) {
				Audit::record( 'ticket_mail_failed', 'order', (int) $order_id, array( 'tickets' => count( $issued ) ) );
			}
			self::finish_delivery( $delivery_key, $order_id, (bool) $delivered, array_map( 'intval', array_column( $issued, 'ticket_id' ) ) );
		}
		return true;
	}

	/**
	 * Runs $callback (e.g. applying a payment webhook) with ticket mails deferred to the job queue: the
	 * provider gets its answer without waiting for PDF rendering, wallet signing and SMTP.
	 *
	 * @return mixed The callback's result.
	 */
	public static function defer_ticket_mail( callable $callback ) {
		++self::$defer_mail;
		try {
			return $callback();
		} finally {
			--self::$defer_mail;
		}
	}

	/** Whether the ticket mail of a fresh issuance goes to the outbox job instead of the request. */
	private static function defers_delivery( $order_id, $ticket_count ) {
		if ( self::$defer_mail > 0 ) { return true; }
		$max = (int) apply_filters( 'wp_pass_captain_ticket_mail_inline_max_v1', self::INLINE_DELIVERY_MAX, (int) $order_id );
		return $ticket_count > max( 0, $max );
	}

	/**
	 * Renders and sends the ticket mail (type "tickets") to the buyer: ticket list (shortened when the
	 * PDF holds every ticket; per ticket the help code and – A682 – missing attendee data), PDF
	 * attachment (Mail\TicketAttachment), legacy filter wpcap_ticket_mail (seats, calendar file,
	 * invoice, wallet links; A474: run in the buyer's locale). Temporary files are released afterwards.
	 * No mail with plaintext codes goes to a placeholder address (sale without buyer e-mail).
	 *
	 * @param object $order  Order row.
	 * @param array  $issued Items with ticket_id, token (plaintext), event, ticket, starts_at, timezone, location[, help_code, missing].
	 * @return bool Whether the mail was accepted.
	 */
	private static function send_ticket_mail( $order, array $issued ) {
		$entries = array_map( static function ( $ticket ) {
			return array( 'missing' => (array) ( $ticket['missing'] ?? array() ), 'event' => $ticket['event'], 'ticket' => $ticket['ticket'], 'when' => self::format_start( $ticket['starts_at'], $ticket['timezone'] ), 'location' => $ticket['location'], 'url' => add_query_arg( 'wpcap_ticket', $ticket['token'], home_url( '/' ) ), 'token' => $ticket['token'], 'help_code' => (string) ( $ticket['help_code'] ?? '' ) );
		}, $issued );
		$placeholder = Mail\Mailer::is_placeholder( (string) $order->customer_email );
		$pdf = $placeholder ? array( 'files' => array(), 'dir' => '', 'notice' => '' ) : Mail\TicketAttachment::create( $order, $issued );
		list( $listed, $omitted ) = Mail\TicketAttachment::shorten( $entries, (int) $order->order_id, $pdf['files'] && '' === (string) ( $pdf['notice'] ?? '' ) ); // A580: a partial PDF set keeps the full list.
		$message = Mail\Mailer::compose( 'tickets', self::ticket_mail_vars( $order, $entries, static function () use ( $listed, $omitted, $pdf ) { return self::with_note( Mail\Blocks::issued_tickets( $listed ), $omitted, $pdf['notice'] ); } ), (string) $order->locale );
		if ( ! $message ) {
			Mail\TicketAttachment::remove( $pdf );
			return false;
		}
		$attachments = $pdf['files'];
		$mail = array( 'attachments' => $attachments );
		try {
			// Legacy filter: receives and may change subject/body/attachments of the rendered template.
			// A474: runs in the buyer's locale, so added texts and attachments (wallet, invoice, .ics,
			// seats) match the language of the mail instead of the request's language.
			$filtered = array( 'subject' => $message['subject'], 'body' => $message['body'], 'attachments' => $attachments );
			$mail = Mail\Mailer::with_locale( (string) $order->locale, static function () use ( $filtered, $order, $issued ) {
				return apply_filters( 'wpcap_ticket_mail', $filtered, $order, $issued );
			} );
			$mail = is_array( $mail ) ? $mail : array( 'attachments' => $attachments );
			return (bool) Mail\Mailer::deliver( $order->customer_email, array( 'order_id' => (int) $order->order_id ) + Mail\Mailer::apply_filtered( $message, $mail ) );
		} finally {
			Mail\TicketAttachment::remove( $pdf, (array) ( $mail['attachments'] ?? array() ) );
		}
	}

	/** A ticket list block followed by the "… and N more tickets" note (Mail\TicketAttachment). */
	private static function with_note( array $block, $omitted, $notice = '' ) {
		$note = Mail\TicketAttachment::omitted_note( (int) $omitted );
		$big = Mail\TicketAttachment::notice_block( $notice );
		return Mail\Mailer::block( (string) $block['text'] . $note['text'] . $big['text'], (string) $block['html'] . $note['html'] . $big['html'] );
	}

	/**
	 * Ticket issuance hit a database error: everything is rolled back, nothing was issued, the order
	 * stays paid with its attendee data. A `ticket_issue` job retries it later (unless this already is
	 * a retry; the job queue retries with backoff) and a retryable error is returned (webhooks answer
	 * 5xx, a later call – webhook retry, resend, "Zuteilung erneut versuchen" – issues it as well).
	 * A deadlock/lock wait timeout (or a server rollback inside a creation hook) is flagged db_busy and
	 * retried inline by issue_and_email() first.
	 */
	private static function issue_failed( $order_id, $step ) {
		global $wpdb;
		$error = (string) $wpdb->last_error;
		// Busy: deadlock/lock wait timeout, or the server rolled the transaction back inside a creation hook
		// (detected by in_transaction(); the error text may be gone by then). Retried inline.
		$busy = DbSession::is_busy_error( $error ) || 'ticket_hook' === (string) $step;
		$wpdb->query( 'ROLLBACK' );
		Log::error( 'ticket_issue_failed', 'Ticket issuance failed and was rolled back; a retry is queued.', array( 'object_type' => 'order', 'object_id' => $order_id, 'details' => array( 'step' => sanitize_key( $step ), 'error' => substr( $error, 0, 200 ) ) ) );
		Audit::record( 'ticket_issue_failed', 'order', absint( $order_id ), array( 'step' => (string) $step ) );
		if ( ! self::$issuing_from_job && $order_id ) {
			// One retry job per order and minute; the job queue retries it with backoff.
			Jobs::enqueue( 'ticket-issue-' . $order_id . '-' . gmdate( 'YmdHi', Clock::now() ), self::ISSUE_JOB, array( 'order_id' => $order_id ), gmdate( 'Y-m-d H:i:s', Clock::now() + 2 * MINUTE_IN_SECONDS ) );
		}
		return new \WP_Error( 'wpcap_issue_failed', __( 'Die Tickets konnten gerade nicht ausgestellt werden. Bitte erneut versuchen.', 'pass-captain' ), array( 'status' => 503, 'retryable' => true, 'step' => (string) $step, 'db_busy' => $busy ) );
	}

	/**
	 * Rolls back a failed issuance. If the connection was lost on the way (wpdb reconnected, later
	 * statements autocommitted), the tickets of this run that were written anyway are removed again
	 * with their attendee rows and consent records (their codes were never mailed), the order's
	 * attendee data is restored and `wpcap_tickets_discarded` lets modules undo their rows (e.g. seat
	 * assignments). A retry then issues the full set once.
	 *
	 * @param object $order  Order row read under the lock (attendee_data as before the run).
	 * @param array  $issued Tickets inserted in this run ({ticket_id, …}).
	 * @param string $step   Failed step (audit).
	 */
	private static function issue_aborted( $order, array $issued, $step ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$error = (string) $wpdb->last_error;
		$lost = $issued && false === self::in_transaction();
		$wpdb->query( 'ROLLBACK' );
		Consents::discard_pending();
		$ids = array_map( static function ( $ticket ) { return (int) $ticket['ticket_id']; }, $issued );
		if ( $lost ) {
			$list = implode( ',', $ids );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_ticket_assignments WHERE ticket_id IN ($list) AND buyer_email=%s", (string) $order->customer_email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
			$wpdb->query( "DELETE FROM {$p}wpcap_participant_answers WHERE ticket_id IN ($list)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_consent_log WHERE ticket_id IN ($list) AND order_id=%d", (int) $order->order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_tickets WHERE ticket_id IN ($list) AND order_id=%d", (int) $order->order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
			if ( $order->attendee_data ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_orders SET attendee_data=%s WHERE order_id=%d AND attendee_data IS NULL", (string) $order->attendee_data, (int) $order->order_id ) );
			}
			/**
			 * Tickets of a failed issuance were removed again (the connection was lost mid-way and the
			 * rest of the issuance ran outside its transaction). Undo rows written in wpcap_ticket_created.
			 *
			 * @param int[] $ticket_ids Removed ticket IDs.
			 * @param int   $order_id   Order.
			 */
			do_action( 'wpcap_tickets_discarded', $ids, (int) $order->order_id );
		}
		// issue_failed() classifies the original database error (the cleanup above may have reset it).
		$wpdb->last_error = $error;
		return self::issue_failed( (int) $order->order_id, $step );
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
		$now = Clock::mysql();
		if ( $sent ) {
			$marker = self::delivery_marker( $order_id, $ticket_ids );
			update_option( $marker, time(), false );
			$completed = Jobs::update_with_retry( $table, array( 'status' => 'complete', 'attempts' => 1, 'locked_at' => null, 'last_error' => null, 'updated_at' => $now ), array( 'idempotency_key' => hash( 'sha256', $key ), 'status' => 'queued' ), array( '%s', '%d', '%s', '%s', '%s' ), array( '%s', '%s' ) );
			if ( $completed ) {
				delete_option( $marker );
			}
			return;
		}
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET attempts=attempts+1,run_after=%s,last_error=%s,updated_at=%s WHERE idempotency_key=%s AND status='queued'", $table, gmdate( 'Y-m-d H:i:s', Clock::now() + 5 * MINUTE_IN_SECONDS ), 'Ticket mail could not be delivered.', $now, hash( 'sha256', $key ) ) );
		Log::warning( 'ticket_delivery_failed', 'Ticket mail could not be delivered; the outbox retries it.', array( 'object_type' => 'order', 'object_id' => absint( $order_id ), 'details' => array( 'ticket_count' => count( $ticket_ids ), 'retry' => 'scheduled' ) ) );
	}

	/** Option name of the "delivered" marker of one ticket delivery (order + its tickets). */
	public static function delivery_marker( $order_id, array $ticket_ids ) {
		$ids = array_values( array_unique( array_map( 'absint', $ticket_ids ) ) );
		sort( $ids );
		return 'wpcap_delivered_' . absint( $order_id ) . '_' . substr( hash( 'sha256', implode( ',', $ids ) ), 0, 16 );
	}

	/**
	 * Outbox job: delivers tickets whose mail failed (or was deferred, see defer_ticket_mail()) by
	 * rotating their codes and mailing new links to the buyer, as the ticket mail with PDF (see
	 * issue_and_email()). Returns false to let the job queue retry.
	 *
	 * Only tickets whose code is still the one of the issuance are delivered (payload "hashes"): a
	 * transfer, a participant assignment or a resend in between already mailed a new code to the
	 * holder, and a retry must not take that ticket back to the buyer. Jobs without hashes (queued by
	 * older versions) skip transferred tickets and tickets with a holder change instead.
	 *
	 * @param array $payload Job payload (ticket_ids, hashes, initial).
	 */
	private static function retry_delivery( $order_id, array $ticket_ids, array $payload = array() ) {
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
		$hashes = array();
		foreach ( (array) ( $payload['hashes'] ?? array() ) as $id => $hash ) { $hashes[ absint( $id ) ] = (string) $hash; }
		if ( ! $hashes ) {
			$changed = self::holder_changed_ids( $ticket_ids );
			if ( null === $changed ) { return false; }
			$ticket_ids = array_values( array_diff( $ticket_ids, $changed ) );
			if ( ! $ticket_ids ) { return true; }
		}
		$reason = empty( $payload['initial'] ) ? 'delivery_retry' : 'delivery';
		$result = self::rotate_and_send( $order, $ticket_ids, $reason, true, array( 'hashes' => $hashes, 'mail' => 'tickets' ) );
		if ( is_wp_error( $result ) ) {
			// No valid ticket left (revoked, checked in, transferred with its own mail): done.
			return 'wpcap_no_resendable_tickets' === $result->get_error_code();
		}
		Audit::record( 'delivery' === $reason ? 'tickets_delivered' : 'tickets_delivered_retry', 'order', $order_id, array( 'ticket_count' => (int) $result ) );
		return true;
	}

	/**
	 * Tickets that changed hands after issuance (transfer, or a holder change by participant
	 * assignment): their holder got a mail with a new code of their own.
	 *
	 * @return int[]|null Ticket ids; null on a database error.
	 */
	private static function holder_changed_ids( array $ticket_ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', $ticket_ids ) ) );
		if ( ! $ids ) { return array(); }
		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$transferred = $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM %i WHERE transfer_count>0 AND ticket_id IN ($in)", array_merge( array( $wpdb->prefix . 'wpcap_tickets' ), $ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders.
		if ( '' !== $wpdb->last_error ) { return null; }
		$assigned = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT object_id FROM %i WHERE object_type='ticket' AND action='ticket_holder_changed' AND object_id IN ($in)", array_merge( array( $wpdb->prefix . 'wpcap_audit_logs' ), $ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders.
		if ( '' !== $wpdb->last_error ) { return null; }
		return array_values( array_unique( array_map( 'intval', array_merge( (array) $transferred, (array) $assigned ) ) ) );
	}

	/**
	 * Safety net for issuances whose retry job could not be queued (e.g. the database connection was
	 * lost after the payment committed): issues the missing tickets of paid orders. Idempotent (the
	 * issuance locks the order row and only creates what is missing); runs with the job queue (cron).
	 * See also sweep_unissued() (A343: paid orders without any ticket get a retry job and are reported).
	 *
	 * @return int Number of orders whose tickets were issued.
	 */
	public static function reconcile_issuance( $limit = 20 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		// Orders of the last 30 days only (index paid_created), so the sweep stays cheap on large sites.
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT ord.order_id FROM %i ord WHERE ord.payment_status='paid' AND ord.created_at>=%s AND ord.status='complete' AND (SELECT COALESCE(SUM(oi.quantity),0) FROM %i oi WHERE oi.order_id=ord.order_id) > (SELECT COUNT(*) FROM %i t WHERE t.order_id=ord.order_id) ORDER BY ord.order_id LIMIT %d", $p . 'wpcap_orders',
			gmdate( 'Y-m-d H:i:s', Clock::now() - 30 * DAY_IN_SECONDS ), $p . 'wpcap_order_items', $p . 'wpcap_tickets',
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
	 * Job handler (filter `wpcap_process_job`, legacy wp_pass_captain_process_job_v1): issuance retries
	 * (`ticket_issue`, `ticket_issue_retry`), the ticket delivery outbox (`ticket_delivery`), repairs
	 * (refund mark, cancellation), the automatic late refund and the retry of a failed resend mail
	 * (`ticket_mail_retry`). All are idempotent: a repeated run after a worker died between the side
	 * effect and the "complete" mark (at-least-once, see Jobs) changes nothing more.
	 */
	public static function handle_job( $handled, $type, $payload = array(), $job_id = 0 ) {
		$payload = (array) $payload;
		// A failed run returns the WP_Error of the attempt: its code and text become the job's
		// last_error, so a dead letter says why (A325).
		if ( self::ISSUE_JOB === $type || 'ticket_issue_retry' === $type ) {
			$order_id = absint( $payload['order_id'] ?? 0 );
			$order = self::get( $order_id );
			if ( ! $order || 'paid' !== $order->payment_status ) { return true; } // Nothing (more) to issue.
			self::$issuing_from_job = true;
			try {
				$issued = self::issue_and_email( $order_id );
			} finally {
				self::$issuing_from_job = false;
			}
			return is_wp_error( $issued ) ? $issued : true;
		}
		if ( self::DELIVERY_JOB === $type ) {
			return self::retry_delivery( absint( $payload['order_id'] ?? 0 ), array_map( 'absint', (array) ( $payload['ticket_ids'] ?? array() ) ), $payload );
		}
		if ( 'order_refund_mark_retry' === $type ) {
			$marked = self::mark_refunded( absint( $payload['order_id'] ?? 0 ), (string) ( $payload['reason'] ?? '' ) );
			// A refused transition is final (never paid): acknowledge instead of retrying into the dead letter.
			if ( true === $marked || ( is_wp_error( $marked ) && 'wpcap_order_state' === $marked->get_error_code() ) ) { return true; }
			return self::job_error( $marked, 'wpcap_refund_mark_failed' );
		}
		if ( 'order_cancel_retry' === $type ) {
			$order = self::get( absint( $payload['order_id'] ?? 0 ) );
			// Paid, refunded or cancelled meanwhile: the retry must not override that.
			if ( ! $order || (string) $order->payment_status !== (string) ( $payload['payment_status'] ?? '' ) ) { return true; }
			$cancelled = self::cancel_order( (int) $order->order_id, (string) ( $payload['reason'] ?? '' ), array( (string) $order->payment_status ) );
			if ( is_wp_error( $cancelled ) && 'wpcap_order_state' === $cancelled->get_error_code() ) { return true; }
			return true === $cancelled ? true : self::job_error( $cancelled, 'wpcap_order_cancel_failed' );
		}
		if ( 'late_payment_refund' === $type ) {
			return self::run_late_refund( absint( $payload['order_id'] ?? 0 ), (string) ( $payload['reason'] ?? '' ) );
		}
		if ( 'ticket_mail_retry' !== $type ) { return $handled; }
		$order_id = absint( $payload['order_id'] ?? 0 );
		$order = self::get( $order_id );
		if ( ! $order || 'paid' !== $order->payment_status ) { return true; } // Nothing (more) to send.
		if ( $job_id && self::tickets_resent_since_job( $order_id, (int) $job_id ) ) {
			// The tickets went out with working codes after this job was queued (an earlier run of it
			// whose worker died before the "complete" mark, or a manual resend): no second mail.
			return true;
		}
		// A588: the codes of the failed mail are known (stored at issuance/rotation): deliver them as they
		// are instead of invalidating codes that may have reached some recipients.
		$result = self::resend_tickets( $order_id, array( 'from_job' => true, 'skip_transferred' => ! empty( $payload['skip_transferred'] ), 'only_unassigned' => ! empty( $payload['only_unassigned'] ), 'keep_codes' => true ) );
		if ( is_wp_error( $result ) && 'wpcap_no_resendable_tickets' === $result->get_error_code() ) { return true; }
		return true === $result ? true : self::job_error( $result, 'wpcap_mail_failed' );
	}

	/** The WP_Error of a failed job step, or a generic one with $code when the step gave no reason. */
	private static function job_error( $result, $code ) {
		if ( $result instanceof \WP_Error ) { return $result; }
		global $wpdb;
		/* translators: %s: database error ('' when none) */
		return new \WP_Error( $code, trim( sprintf( __( 'Der Schritt ist ohne Fehlerangabe fehlgeschlagen. %s', 'pass-captain' ), (string) $wpdb->last_error ) ) );
	}

	/** Whether a resend of the order's tickets succeeded since the job was queued (audit `tickets_resent`). */
	private static function tickets_resent_since_job( $order_id, $job_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$queued = $wpdb->get_var( $wpdb->prepare( "SELECT created_at FROM {$p}wpcap_jobs WHERE job_id=%d", absint( $job_id ) ) );
		if ( ! $queued ) { return false; }
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT audit_id FROM {$p}wpcap_audit_logs WHERE action='tickets_resent' AND object_type='order' AND object_id=%d AND created_at>=%s LIMIT 1", absint( $order_id ), $queued ) );
	}

	/** Seconds a paid order may be without tickets before the sweep queues an issuance (A343). */
	const UNISSUED_GRACE = 600;

	/** Paid orders older than this are left to the Doctor check (no automatic mail of old tickets). */
	const UNISSUED_LOOKBACK = 2 * DAY_IN_SECONDS;

	/**
	 * Cron (wpcap_cleanup_reservations): paid orders that still have no ticket some minutes after the
	 * payment get a `ticket_issue_retry` job (A343). Covers every way a payment is confirmed (webhook,
	 * return page, WooCommerce, partner sale, manual confirmation) when the issuance after the
	 * committed payment never ran – process killed, timeout, failed job enqueue – or its retries ended
	 * as dead letter. One job per order (fixed key): a job that issues nothing (e.g. an exchange
	 * supplement, whose ticket lives on the original order) is not queued again. Orders paid more than
	 * two days ago are only reported (Doctor check `paid_orders_without_tickets`).
	 *
	 * @param int $limit Orders per run.
	 * @return int Jobs queued.
	 */
	public static function sweep_unissued( $limit = 50 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = Clock::now();
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT o.order_id FROM {$p}wpcap_orders o WHERE o.payment_status='paid' AND o.status='complete' AND o.updated_at < %s AND o.updated_at >= %s AND EXISTS (SELECT 1 FROM {$p}wpcap_order_items oi WHERE oi.order_id=o.order_id AND oi.quantity > oi.refunded_quantity) AND NOT EXISTS (SELECT 1 FROM {$p}wpcap_tickets t WHERE t.order_id=o.order_id) ORDER BY o.order_id LIMIT %d",
			gmdate( 'Y-m-d H:i:s', $now - self::UNISSUED_GRACE ),
			gmdate( 'Y-m-d H:i:s', $now - self::UNISSUED_LOOKBACK ),
			max( 1, absint( $limit ) )
		) );
		$queued = 0;
		foreach ( array_map( 'intval', (array) $ids ) as $order_id ) {
			$before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_jobs WHERE idempotency_key=%s", hash( 'sha256', 'ticket-issue-sweep|' . $order_id ) ) );
			if ( $before ) { continue; } // Already swept once: the Doctor check reports what is still open.
			if ( true === Jobs::enqueue( 'ticket-issue-sweep|' . $order_id, 'ticket_issue_retry', array( 'order_id' => $order_id ) ) ) {
				Audit::record( 'ticket_issue_swept', 'order', $order_id, array() );
				++$queued;
			}
		}
		return $queued;
	}

	/**
	 * Hands the attachment files of a sent mail to the cleanup: A626 keeps them until the hourly
	 * sweep (Mail\TempFiles, mail queue plugins send later); with a TTL of 0 they go at once.
	 *
	 * @param string[] $files Attachment paths.
	 * @param string   $dir   Private directory of the ticket PDFs ('' = none).
	 */
	public static function discard_attachments( array $files, $dir = '' ) {
		Mail\TempFiles::release( $files, '' !== (string) $dir ? array( (string) $dir ) : array() );
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

	/**
	 * Placeholder values shared by the ticket and resend mails.
	 *
	 * @param object   $order   Order row.
	 * @param array    $entries Ticket entries (event, ticket, when, location, url).
	 * @param callable $list    Builds the {ticket_list} block (runs in the recipient locale).
	 */
	public static function ticket_mail_vars( $order, array $entries, $list ) {
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
			// A568: the legal texts of the version this order was concluded under (built only when used).
			'contract_texts' => static function () use ( $order ) { return LegalVersions::mail_block( $order ); },
		);
	}

	/**
	 * Extra mail section of a ticket with a new code (filter wpcap_ticket_code_mail_section, e.g. wallet
	 * links), rendered in the mail's locale (A474).
	 */
	public static function code_mail_section( $ticket_id, $locale = '' ) {
		return (string) Mail\Mailer::with_locale( (string) $locale, static function () use ( $ticket_id ) {
			return apply_filters( 'wpcap_ticket_code_mail_section', '', (int) $ticket_id );
		} );
	}

	/** Tickets per attached ticket PDF (A580). */
	public static function ticket_pdf_chunk_size() {
		/**
		 * Tickets per PDF attachment of the ticket mail; larger orders get several PDFs (A580).
		 *
		 * @param int $size Default 50 (about 1 MB).
		 */
		return max( 1, (int) apply_filters( 'wpcap_ticket_pdf_chunk_size', 50 ) );
	}

	/**
	 * Attachment budget of a ticket mail in bytes (A580). Measured: ~21 KB PDF and ~10–20 KB .pkpass
	 * per ticket, base64 adds a third; 7 MB raw stays below the 10 MB limit of many mailboxes.
	 */
	public static function attachment_budget( $order = null ) {
		/**
		 * Maximum total size (bytes, before encoding) of the ticket mail's attachments (A580).
		 *
		 * @param int         $bytes Default 7 MB.
		 * @param object|null $order Order row.
		 */
		return max( 0, (int) apply_filters( 'wpcap_ticket_mail_attachment_budget', 7 * 1024 * 1024, $order ) );
	}

	/**
	 * A621: entry of the ticket PDF for one ticket (array with event, ticket, starts_at, timezone,
	 * location, ticket_id, occurrence_id, ticket_type_id, token, help_code, attendee).
	 */
	public static function pdf_ticket_entry( array $ticket ) {
		return array( 'event' => $ticket['event'], 'ticket' => $ticket['ticket'], 'when' => self::format_start( $ticket['starts_at'], $ticket['timezone'] ), 'location' => $ticket['location'], 'seat' => (string) apply_filters( 'wpcap_ticket_seat_label', '', (int) $ticket['ticket_id'], $ticket ), 'attendee' => (string) ( $ticket['attendee'] ?? '' ), 'token' => $ticket['token'], 'help_code' => (string) ( $ticket['help_code'] ?? '' ), 'ticket_id' => (int) $ticket['ticket_id'], 'occurrence_id' => (int) $ticket['occurrence_id'], 'ticket_type_id' => (int) $ticket['ticket_type_id'], 'starts_at' => $ticket['starts_at'], 'timezone' => $ticket['timezone'] );
	}

	/**
	 * A621: the ticket PDF attachments of a mail – the same builder for the ticket mail, the resend
	 * and its retry. The PDF holds plaintext ticket codes: private directory (0700) and files (0600)
	 * with random names in the shared temp dir. A580: large orders are split into several PDFs,
	 * attached while they fit the mail's attachment budget. Nothing for a placeholder address or when
	 * `wpcap_attach_ticket_pdf` is false.
	 *
	 * @param object $order       Order row.
	 * @param string $recipient   Mail recipient.
	 * @param array  $pdf_tickets pdf_ticket_entry() rows.
	 * @return array{files: string[], covered: int, dir: string}
	 */
	public static function ticket_pdf_attachments( $order, $recipient, array $pdf_tickets ) {
		$out = array( 'files' => array(), 'covered' => 0, 'dir' => '' );
		if ( ! $pdf_tickets || Mail\Mailer::is_placeholder( (string) $recipient ) || ! apply_filters( 'wpcap_attach_ticket_pdf', true, (int) $order->order_id ) ) { return $out; }
		$pdf_dir = self::private_temp_dir();
		if ( ! $pdf_dir ) { return $out; }
		$out['dir'] = $pdf_dir;
		$chunks = array_chunk( $pdf_tickets, self::ticket_pdf_chunk_size() );
		$budget = self::attachment_budget( $order );
		$used = 0;
		foreach ( $chunks as $index => $chunk ) {
			// A474: the PDF speaks the buyer's language, like the mail itself.
			$bytes = Mail\Mailer::with_locale( (string) $order->locale, static function () use ( $chunk ) {
				return TicketPdf::render( $chunk, array( 'title' => get_bloginfo( 'name' ), 'footer' => __( 'Dieses Ticket ist nur einmal gültig. Code nicht weitergeben.', 'pass-captain' ) ) );
			} );
			if ( $index > 0 && $used + strlen( $bytes ) > $budget ) { break; }
			$file = $pdf_dir . '/tickets-' . $order->public_id . ( count( $chunks ) > 1 ? '-' . ( $index + 1 ) : '' ) . '.pdf';
			if ( false === file_put_contents( $file, $bytes ) ) { break; }
			@chmod( $file, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$out['files'][] = $file;
			$used += strlen( $bytes );
			$out['covered'] += count( $chunk );
		}
		return $out;
	}

	/** Event start in the event's own timezone; starts_at is stored in UTC. */
	public static function format_start( $starts_at, $timezone ) {
		$tz = (string) $timezone;
		try { $zone = new \DateTimeZone( '' !== $tz ? $tz : 'UTC' ); } catch ( \Exception $e ) { $zone = new \DateTimeZone( 'UTC' ); $tz = 'UTC'; }
		$ts = strtotime( $starts_at . ' UTC' );
		return false === $ts ? (string) $starts_at : wp_date( Frontend\Format::datetime_format(), $ts, $zone ) . ( '' !== $tz ? ' ' . $tz : '' );
	}

	/** Name used in customer communication (site title, falls back to the host name). */
	public static function site_name() {
		$name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		return (string) apply_filters( 'wpcap_sender_name', '' !== trim( $name ) ? $name : (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * Resends valid tickets of a paid order, one mail per holder (attendee e-mail, else the buyer).
	 * Checked-in tickets are skipped: they are used, and rotating them would only invalidate the
	 * holder's admission history link.
	 *
	 * Rotation and mail run per recipient in rotate_and_send(): the new codes are prepared without
	 * locks, mailed (with the ticket PDF) and only then stored by a short compare-and-set transaction
	 * on each previous hash. A failed mail changes nothing, so no old code is invalidated without the
	 * new one being sent; no row lock is held while SMTP or wallet signing runs. A failed mail
	 * schedules a `ticket_mail_retry` job (A042) that keeps the scope of this resend (A335).
	 *
	 * A588: with keep_codes, a ticket whose current code is known (TicketCodes, the wallet module;
	 * filter wpcap_ticket_known_code, see PortalSelfService::current_code()) is sent again with that
	 * same code – nothing already delivered, printed or added to a wallet becomes invalid; only tickets
	 * without a known code get a new one. The retry job always keeps codes.
	 *
	 * @param int   $order_id Order ID.
	 * @param array $options  only_unassigned (bool): only tickets the buyer holds (no own participant
	 *                        e-mail, not transferred) – the portal offers this so a buyer does not
	 *                        invalidate tickets already passed on to other people;
	 *                        skip_transferred (bool): tickets passed on to someone else (transfer) keep
	 *                        their code (A068, buyer self-service);
	 *                        self_service (bool, A593): tickets whose admission has begun or whose code is
	 *                        on offline scanners (Admission\CodeRotationLock) are left alone;
	 *                        keep_codes (bool, A588, see above);
	 *                        request_key (string, A533): one resend per form submission (double click);
	 *                        from_job (bool, internal: the retry job reschedules itself).
	 * @return true|\WP_Error
	 */
	public static function resend_tickets( $order_id, array $options = array() ) {
		global $wpdb;
		$order_id = absint( $order_id );
		$busy = static function () { return Errors::busy( __( 'Die Tickets konnten gerade nicht erneuert werden. Bitte erneut versuchen.', 'pass-captain' ) ); };
		$order = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d', $wpdb->prefix . 'wpcap_orders', $order_id ) );
		if ( ! $order && '' !== $wpdb->last_error ) { return $busy(); }
		if ( ! $order || 'paid' !== $order->payment_status ) {
			return new \WP_Error( 'wpcap_order_unpaid', __( 'Tickets können erst nach bestätigter Zahlung erneut gesendet werden.', 'pass-captain' ) );
		}
		$request_marker = '';
		$request_key = preg_replace( '/[^A-Za-z0-9\-]/', '', (string) ( $options['request_key'] ?? '' ) );
		if ( '' !== $request_key ) {
			// A533: one resend per form submission. Checked and recorded under the order lock, so a double
			// click (two requests) cannot rotate the codes twice; a failed resend frees the key again.
			$marker = hash( 'sha256', 'resend|' . $order_id . '|' . $request_key );
			if ( false === self::begin() ) { return $busy(); }
			$wpdb->get_var( $wpdb->prepare( 'SELECT order_id FROM %i WHERE order_id=%d FOR UPDATE', $wpdb->prefix . 'wpcap_orders', $order_id ) );
			$seen = '' === $wpdb->last_error ? $wpdb->get_row( $wpdb->prepare( "SELECT SUM(action='tickets_resend_request') AS requested,SUM(action='tickets_resend_request_failed') AS failed FROM %i WHERE object_type='order' AND object_id=%d AND action IN ('tickets_resend_request','tickets_resend_request_failed') AND details LIKE %s", $wpdb->prefix . 'wpcap_audit_logs', $order_id, '%' . $wpdb->esc_like( $marker ) . '%' ) ) : null;
			if ( null === $seen || '' !== $wpdb->last_error ) {
				$wpdb->query( 'ROLLBACK' );
				return $busy();
			}
			if ( (int) $seen->requested > (int) $seen->failed ) {
				$wpdb->query( 'COMMIT' );
				return new \WP_Error( 'wpcap_resend_duplicate', __( 'Dieses Formular wurde bereits abgeschickt; die Tickets wurden schon erneut gesendet. Für einen weiteren Versand die Seite neu laden.', 'pass-captain' ), array( 'status' => 409 ) );
			}
			Audit::record( 'tickets_resend_request', 'order', $order_id, array( 'request' => $marker ) );
			if ( '' !== $wpdb->last_error || false === self::in_transaction() || false === $wpdb->query( 'COMMIT' ) ) {
				$wpdb->query( 'ROLLBACK' );
				return $busy();
			}
			$request_marker = $marker;
		}
		$scope = array(
			'only_unassigned' => ! empty( $options['only_unassigned'] ),
			'skip_transferred' => ! empty( $options['skip_transferred'] ),
			'self_service' => ! empty( $options['self_service'] ),
			'keep_codes' => ! empty( $options['keep_codes'] ),
		);
		$result = self::rotate_and_send( $order, null, 'resend', false, $scope );
		if ( is_wp_error( $result ) ) {
			if ( '' !== $request_marker ) { Audit::record( 'tickets_resend_request_failed', 'order', $order_id, array( 'request' => $request_marker ) ); }
			if ( 'wpcap_mail_failed' === $result->get_error_code() ) {
				Audit::record( 'ticket_mail_failed', 'order', $order_id, array( 'resend' => true, 'sent' => (int) ( $result->get_error_data()['sent'] ?? 0 ) ) );
				if ( empty( $options['from_job'] ) ) {
					// A335: the retry keeps the scope of this resend, so it never rotates the codes of tickets
					// passed on to someone else when this resend left them alone. A588: it sends known codes again.
					Jobs::enqueue( 'ticket-mail-retry|' . $order_id . '|' . Clock::now(), 'ticket_mail_retry', self::mail_retry_payload( $order_id, $scope['skip_transferred'], $scope['only_unassigned'] ), gmdate( 'Y-m-d H:i:s', Clock::now() + 5 * MINUTE_IN_SECONDS ) );
				}
			}
			return $result;
		}
		Audit::record( 'tickets_resent', 'order', $order_id, array( 'ticket_count' => (int) $result ) + array_filter( array( 'only_unassigned' => $scope['only_unassigned'], 'skip_transferred' => $scope['skip_transferred'], 'kept_codes' => self::$last_kept_codes ) ) );
		return true;
	}

	/** Tickets of the last rotate_and_send() that were mailed with their known code (A588). */
	private static $last_kept_codes = 0;

	/**
	 * Rotates the codes of valid tickets of an order and mails the new links, per recipient.
	 *
	 * @param object     $order      Order row (paid).
	 * @param int[]|null $ticket_ids Restrict to these tickets; null = all valid tickets of the order.
	 * @param string     $reason     Passed to wpcap_ticket_code_rotated (resend, delivery, delivery_retry).
	 * @param bool       $to_buyer   Mail every ticket to the buyer (initial delivery) instead of its holder.
	 * @param array      $options    hashes (ticket_id => expected current code hash; others are skipped),
	 *                               mail ('tickets' = ticket mail of the first delivery, default
	 *                               'tickets_resend'), only_unassigned, skip_transferred, self_service,
	 *                               keep_codes (bool, see resend_tickets()).
	 * @return int|\WP_Error Number of tickets mailed (rotated or with their kept code). On a partial
	 *                       failure the error carries `sent` (tickets mailed to other recipients); the
	 *                       failed recipient's codes are unchanged.
	 */
	private static function rotate_and_send( $order, $ticket_ids, $reason, $to_buyer, array $options = array() ) {
		global $wpdb;
		$p = $wpdb->prefix;
		self::$last_kept_codes = 0;
		$filter = '';
		if ( null !== $ticket_ids ) {
			$ticket_ids = array_values( array_filter( array_map( 'absint', (array) $ticket_ids ) ) );
			$filter = $ticket_ids ? ' AND ticket_id IN (' . implode( ',', $ticket_ids ) . ')' : ' AND 1=0';
		}
		$candidates = $wpdb->get_results( $wpdb->prepare( "SELECT ticket_id,attendee_email,transfer_count,token_hash FROM {$p}wpcap_tickets WHERE order_id=%d AND status='valid'{$filter} ORDER BY ticket_id", (int) $order->order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		if ( '' !== $wpdb->last_error ) {
			return new \WP_Error( 'wpcap_ticket_rotation_failed', __( 'Ticketcode konnte nicht erneuert werden.', 'pass-captain' ), array( 'status' => 503, 'retryable' => true ) );
		}
		$hashes = (array) ( $options['hashes'] ?? array() );
		$candidates = array_values( array_filter( (array) $candidates, static function ( $ticket ) use ( $order, $hashes, $options ) {
			if ( $hashes && ( ! isset( $hashes[ (int) $ticket->ticket_id ] ) || ! hash_equals( (string) $hashes[ (int) $ticket->ticket_id ], (string) $ticket->token_hash ) ) ) {
				return false; // Code changed since issuance: its holder already got a newer one.
			}
			if ( ! empty( $options['skip_transferred'] ) && (int) $ticket->transfer_count > 0 ) {
				return false; // Passed on to someone else: keeps its code (A068).
			}
			if ( ! empty( $options['only_unassigned'] ) ) {
				return 0 === (int) $ticket->transfer_count && ( ! is_email( $ticket->attendee_email ) || 0 === strcasecmp( (string) $ticket->attendee_email, (string) $order->customer_email ) );
			}
			return true;
		} ) );
		if ( $candidates && ! empty( $options['self_service'] ) ) {
			// A593: the buyer's own resend leaves tickets alone whose admission has begun or whose code
			// is on offline scanners (Admission\CodeRotationLock); staff resends are not limited.
			$locked = '';
			$candidates = array_values( array_filter( $candidates, static function ( $ticket ) use ( &$locked ) {
				$why = Admission\CodeRotationLock::reason( (int) $ticket->ticket_id );
				if ( '' !== $why && '' === $locked ) { $locked = $why; }
				return '' === $why;
			} ) );
			if ( ! $candidates && '' !== $locked ) { return Admission\CodeRotationLock::error( $locked ); }
		}
		if ( ! $candidates ) {
			return new \WP_Error( 'wpcap_no_resendable_tickets', __( 'Keine gültigen Tickets für den erneuten Versand gefunden.', 'pass-captain' ) );
		}
		$groups = array();
		foreach ( $candidates as $ticket ) {
			$groups[ self::code_recipient( $order, $ticket, $to_buyer ) ][ (int) $ticket->ticket_id ] = (string) $ticket->token_hash;
		}
		$sent = 0;
		$error = null;
		foreach ( $groups as $recipient => $expected ) {
			$result = self::rotate_and_send_group( $order, (string) $recipient, $expected, $reason, $to_buyer, (string) ( $options['mail'] ?? 'tickets_resend' ), ! empty( $options['keep_codes'] ) );
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
	 * One recipient of rotate_and_send(), optimistic:
	 * 1. prepare: read the tickets (no lock), keep those whose code is still the expected one and whose
	 *    holder is still this recipient, create new codes (with keep_codes: the known current code);
	 * 2. mail the links (ticket PDF attached up to the size limit, A621 attachments of modules) –
	 *    nothing is stored yet, so a failed mail leaves every old code valid;
	 * 3. store: one short transaction with a compare-and-set per ticket
	 *    (UPDATE … WHERE ticket_id=? AND status='valid' AND token_hash=<old>). A ticket checked in or
	 *    rotated by someone else in the meantime is skipped (its old state wins; the link in this
	 *    mail stays dead); a database error rolls back and the old codes stay valid. The replaced code
	 *    is recorded (CodeHistory, A543) and who received the code (code_with_attendee, A581);
	 * 4. after the commit: wpcap_ticket_code_rotated per ticket (wallet passes of the new generation,
	 *    outside any row lock). For resend mails, wallet links produced by it follow in a second
	 *    short mail (the first mail went out before the codes existed in the database).
	 *
	 * @param array  $expected   ticket_id => code hash read by the caller.
	 * @param string $mail       tickets (first delivery: ticket mail incl. wpcap_ticket_mail filter) or tickets_resend.
	 * @param bool   $keep_codes A588: send known current codes again instead of new ones.
	 * @return int|\WP_Error Number of mailed tickets (0 when none is left for this recipient).
	 */
	private static function rotate_and_send_group( $order, $recipient, array $expected, $reason, $to_buyer, $mail = 'tickets_resend', $keep_codes = false ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$rotation_failed = __( 'Ticketcode konnte nicht erneuert werden.', 'pass-captain' );
		$retry = static function ( $code, $message, $step ) { return new \WP_Error( $code, $message, array( 'status' => 503, 'retryable' => true, 'step' => $step ) ); };
		$ids = array_map( 'absint', array_keys( $expected ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT t.ticket_id,t.ticket_type_id,t.token_hash,t.attendee_email,COALESCE(ta.attendee_name,'') AS attendee_name,tt.occurrence_id,tt.name AS ticket_name,e.name AS event_name,o.starts_at,o.timezone,o.location FROM %i t LEFT JOIN %i ta ON ta.ticket_id=t.ticket_id INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id INNER JOIN %i e ON e.event_id=o.event_id WHERE t.order_id=%d AND t.status='valid' AND t.ticket_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY t.ticket_id', array_merge( array( $p . 'wpcap_tickets', $p . 'wpcap_ticket_assignments', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $p . 'wpcap_events', (int) $order->order_id ), $ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders.
		if ( '' !== $wpdb->last_error ) { return $retry( 'wpcap_ticket_rotation_failed', $rotation_failed, 'read' ); }
		$plan = array();
		foreach ( (array) $rows as $row ) {
			// The code or the holder changed meanwhile (transfer/assignment/resend mailed its own new code): skip.
			if ( ! hash_equals( (string) $expected[ (int) $row->ticket_id ], (string) $row->token_hash ) || self::code_recipient( $order, $row, $to_buyer ) !== $recipient ) { continue; }
			$known = $keep_codes ? PortalSelfService::current_code( (int) $row->ticket_id ) : '';
			$plan[ (int) $row->ticket_id ] = array( 'old' => (string) $row->token_hash, 'token' => '' !== $known ? $known : bin2hex( random_bytes( 32 ) ), 'keep' => '' !== $known, 'row' => $row );
		}
		if ( ! $plan ) { return 0; }
		$items = array();
		foreach ( $plan as $ticket_id => $step ) {
			$row = $step['row'];
			$items[] = array( 'ticket_id' => $ticket_id, 'ticket_type_id' => (int) $row->ticket_type_id, 'occurrence_id' => (int) $row->occurrence_id, 'token' => $step['token'], 'event' => $row->event_name, 'ticket' => $row->ticket_name, 'starts_at' => $row->starts_at, 'timezone' => $row->timezone, 'location' => $row->location, 'attendee' => (string) $row->attendee_name, 'help_code' => HelpCode::for_ticket( $ticket_id, $step['token'] ) );
		}
		if ( 'tickets' === $mail ) {
			$sent = self::send_ticket_mail( $order, $items );
		} else {
			$sent = self::send_code_mail( $order, $recipient, $items, false );
		}
		if ( ! $sent ) {
			return $retry( 'wpcap_mail_failed', __( 'Mindestens eine E-Mail konnte nicht zugestellt werden. Die bisherigen Ticketcodes bleiben gültig; der Versand wird automatisch wiederholt.', 'pass-captain' ), 'mail' );
		}
		// Short compare-and-set transaction: only now do the new codes replace the old ones.
		if ( false === self::begin() ) { return $retry( 'wpcap_ticket_rotation_failed', $rotation_failed, 'start' ); }
		$holder_ready = TicketCodes::holder_ready();
		$holder = TicketCodes::to_attendee( $recipient, (string) $order->customer_email );
		$rotated = array();
		$kept = 0;
		foreach ( $plan as $ticket_id => $step ) {
			$set = $step['keep'] ? '' : $wpdb->prepare( 'token_hash=%s', hash( 'sha256', $step['token'] ) );
			if ( $holder_ready ) { $set .= ( '' !== $set ? ',' : '' ) . $wpdb->prepare( 'code_with_attendee=%d', $holder ); }
			if ( '' === $set ) { ++$kept; continue; }
			$updated = $wpdb->query( $wpdb->prepare( "UPDATE %i SET {$set} WHERE ticket_id=%d AND status='valid' AND token_hash=%s", $p . 'wpcap_tickets', $ticket_id, $step['old'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prepared assignments.
			if ( false === $updated ) {
				// The mail went out but its codes were not stored: the old codes stay valid; a retry sends working ones.
				$wpdb->query( 'ROLLBACK' );
				return $retry( 'wpcap_ticket_rotation_failed', $rotation_failed, 'rotate' );
			}
			if ( $step['keep'] ) { ++$kept; continue; }
			if ( 1 === (int) $updated ) {
				CodeHistory::replaced( $ticket_id, $step['old'], 'resend' === $reason ? 'resend' : 'other' ); // A543.
				$rotated[ $ticket_id ] = $step['token'];
			}
		}
		if ( false === self::in_transaction() || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $retry( 'wpcap_ticket_rotation_failed', $rotation_failed, 'commit' );
		}
		self::$last_kept_codes += $kept;
		if ( count( $rotated ) + $kept < count( $plan ) ) {
			Log::warning( 'ticket_rotation_conflict', 'Tickets changed while their new codes were mailed; their previous state was kept.', array( 'object_type' => 'order', 'object_id' => (int) $order->order_id, 'details' => array( 'tickets' => array_values( array_diff( array_keys( $plan ), array_keys( $rotated ) ) ), 'reason' => sanitize_key( $reason ) ) ) );
		}
		foreach ( $rotated as $ticket_id => $token ) {
			/** A ticket received a new code; the old one is invalid. $token is plaintext – never store it. */
			do_action( 'wpcap_ticket_code_rotated', $ticket_id, $token, $reason );
		}
		if ( 'tickets' !== $mail && $rotated ) {
			// Wallet links (and other sections) for the new codes exist only now: send them in a short second mail.
			$extra = array_values( array_filter( $items, static function ( $item ) use ( $rotated ) { return isset( $rotated[ $item['ticket_id'] ] ); } ) );
			self::send_code_mail( $order, $recipient, $extra, true );
		}
		return count( $rotated ) + $kept;
	}

	/**
	 * Mail with ticket links (type tickets_resend), ticket PDF attached up to the size limit, plus the
	 * attachments modules add through `wpcap_ticket_resend_attachments` (A621: .ics, .pkpass).
	 *
	 * @param array $items       Items with ticket_id, token, event, ticket, starts_at, timezone, location
	 *                           (and ticket_type_id, occurrence_id, attendee, help_code).
	 * @param bool  $extras_only Only tickets with a section from wpcap_ticket_code_mail_section (wallet
	 *                           links after the rotation); nothing is sent when there is none.
	 * @return bool Whether the mail was accepted (true when $extras_only found nothing to send).
	 */
	private static function send_code_mail( $order, $recipient, array $items, $extras_only ) {
		$entries = array();
		foreach ( $items as $item ) {
			$extra = $extras_only ? self::code_mail_section( (int) $item['ticket_id'], (string) $order->locale ) : '';
			if ( $extras_only && '' === trim( $extra ) ) { continue; }
			$entries[] = array( 'event' => $item['event'], 'ticket' => $item['ticket'], 'when' => self::format_start( $item['starts_at'], $item['timezone'] ), 'location' => $item['location'], 'url' => add_query_arg( 'wpcap_ticket', $item['token'], home_url( '/' ) ), 'extra' => $extra );
		}
		if ( ! $entries ) { return true; }
		$placeholder = Mail\Mailer::is_placeholder( (string) $recipient );
		$pdf = ( $extras_only || $placeholder ) ? array( 'files' => array(), 'dir' => '', 'notice' => '' ) : Mail\TicketAttachment::create( $order, $items );
		$files = $pdf['files'];
		if ( ! $extras_only && ! $placeholder ) {
			$pdf_tickets = array_map( array( __CLASS__, 'pdf_ticket_entry' ), $items );
			$files = Mail\Mailer::with_locale( (string) $order->locale, static function () use ( $pdf, $order, $pdf_tickets ) {
				/**
				 * Attachments of a resent ticket mail (A621) besides the ticket PDF: .ics files, .pkpass files.
				 *
				 * @param string[] $files   Attachment paths so far.
				 * @param object   $order   Order row.
				 * @param array[]  $tickets Tickets of this mail (ticket_id, occurrence_id, token, event, ticket …).
				 */
				return apply_filters( 'wpcap_ticket_resend_attachments', $pdf['files'], $order, $pdf_tickets );
			} );
			$files = array_values( array_filter( (array) $files, 'is_string' ) );
		}
		list( $listed, $omitted ) = Mail\TicketAttachment::shorten( $entries, (int) $order->order_id, $pdf['files'] && '' === (string) ( $pdf['notice'] ?? '' ) ); // A580: a partial PDF set keeps the full list.
		$vars = self::ticket_mail_vars( $order, $entries, static function () use ( $listed, $omitted, $pdf ) { return self::with_note( Mail\Blocks::code_tickets( $listed ), $omitted, (string) ( $pdf['notice'] ?? '' ) ); } );
		try {
			return (bool) Mail\Mailer::send( 'tickets_resend', $recipient, $vars, $files, (string) $order->locale, (int) $order->order_id );
		} finally {
			Mail\TicketAttachment::remove( $pdf, $files );
		}
	}

	/**
	 * Payload of a ticket_mail_retry job. A resend that left transferred tickets alone (A335) or only
	 * covered the buyer's own tickets keeps that scope in its retry; the plain form {order_id} is the
	 * payload of a full resend.
	 */
	public static function mail_retry_payload( $order_id, $skip_transferred = false, $only_unassigned = false ) {
		return array( 'order_id' => absint( $order_id ) ) + ( $skip_transferred ? array( 'skip_transferred' => true ) : array() ) + ( $only_unassigned ? array( 'only_unassigned' => true ) : array() );
	}

	/**
	 * Valid ticket counts of an order for the admin resend form (A335): all valid tickets and those
	 * passed on to another person (transfer), which keep their code unless the resend includes them.
	 *
	 * @return array{valid:int,transferred:int}
	 */
	public static function resend_counts( $order_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS valid_count,COALESCE(SUM(transfer_count>0),0) AS transferred FROM {$wpdb->prefix}wpcap_tickets WHERE order_id=%d AND status='valid'", absint( $order_id ) ) );
		return array( 'valid' => $row ? (int) $row->valid_count : 0, 'transferred' => $row ? (int) $row->transferred : 0 );
	}

	/**
	 * Reissues a single valid ticket: rotates only its code (the old QR code stops working) and mails
	 * the new link to the ticket holder (attendee e-mail, else the buyer), with the ticket PDF.
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
		CodeHistory::replacing( $ticket_id, 'reissue' ); // A543
		$recipient = is_email( $ticket->attendee_email ) ? $ticket->attendee_email : $order->customer_email;
		// A581: the holder of the new code is whoever receives it (no mail: the staff hands it out, the
		// attendee address decides as before).
		$set = array( 'token_hash' => hash( 'sha256', $token ) ) + ( TicketCodes::holder_ready() ? array( 'code_with_attendee' => TicketCodes::to_attendee( $recipient, $order->customer_email ) ) : array() );
		$updated = $wpdb->update( "{$p}wpcap_tickets", $set, array( 'ticket_id' => $ticket_id, 'status' => 'valid' ), count( $set ) > 1 ? array( '%s', '%d' ) : array( '%s' ), array( '%d', '%s' ) );
		if ( 1 !== (int) $updated ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_ticket_rotation_failed', __( 'Ticketcode konnte nicht erneuert werden.', 'pass-captain' ), array( 'status' => 500 ) );
		}
		$wpdb->query( 'COMMIT' );
		/** A ticket received a new code; the old one is invalid. $token is plaintext – never store it. */
		do_action( 'wpcap_ticket_code_rotated', $ticket_id, $token, 'reissue' );
		$mailed = false;
		if ( ( $context['notify'] ?? true ) && is_email( $recipient ) ) {
			$list = array( array( 'event' => $ticket->event_name, 'ticket' => $ticket->ticket_name, 'when' => self::format_start( $ticket->starts_at, $ticket->timezone ), 'location' => $ticket->location, 'url' => add_query_arg( 'wpcap_ticket', $token, home_url( '/' ) ), 'extra' => self::code_mail_section( (int) $ticket_id, (string) $order->locale ) ) );
			$vars = self::ticket_mail_vars( $order, $list, static function () use ( $list ) { return Mail\Blocks::code_tickets( $list ); } );
			$pdf = Mail\TicketAttachment::create( $order, array( array( 'ticket_id' => $ticket_id, 'token' => $token, 'event' => $ticket->event_name, 'ticket' => $ticket->ticket_name, 'starts_at' => $ticket->starts_at, 'timezone' => $ticket->timezone, 'location' => $ticket->location ) ) );
			try {
				$mailed = (bool) Mail\Mailer::send( 'tickets_resend', $recipient, $vars, $pdf['files'], (string) $order->locale, (int) $order->order_id );
			} finally {
				Mail\TicketAttachment::remove( $pdf );
			}
		}
		Audit::record( 'ticket_reissued', 'ticket', $ticket_id, array( 'order_id' => (int) $order->order_id, 'event_id' => (int) $ticket->event_id, 'reason' => sanitize_text_field( (string) ( $context['reason'] ?? '' ) ), 'mailed' => $mailed ) );
		return array( 'ticket_id' => $ticket_id, 'recipient' => $recipient, 'mailed' => $mailed );
	}
}

// Job handler (issuance retries, ticket delivery outbox); see Orders::register().
Orders::register();

<?php
/**
 * Order, inventory hold and ticket issuance services.
 *
 * Facade with stable static signatures (add-on contract). Cohesive parts live in @internal classes under
 * src/Orders/ and are reached only through thin delegators here: PortalAccess (order portal link),
 * CheckoutState (phases of create_attempt()), Availability (availability memo/cache/computation),
 * Attendees (attendee validation and storage), Capacity (capacity check under row locks), Holds (bank transfer
 * holds and hold deadlines), Delivery (ticket mail placeholders, PDF attachments, delivery status), Transaction
 * (begin/in_transaction tracking, capacity release hook), Payment (payment confirmation, late payment recovery),
 * Cancellation (order cancellation, refund marking, event cancellation), Refunding (ticket revocation, refundable
 * tickets, refund of paid orders without tickets), Expiry (expiry of lapsed holds), Issuance (ticket issuance,
 * delivery outbox, job handler, issuance sweeps) and Resend (resending and reissuing tickets).
 */
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
		// A401: the phases of a checkout attempt, in the former order; Orders\CheckoutState carries the values between them.
		$s = self::checkout_state( $payload, $internal, $attempt );
		if ( ! $s instanceof Orders\CheckoutState ) { return $s; }
		// Checks before the transaction: the first one that has a result (error or reused order) ends the attempt.
		$early = self::checkout_existing_order( $s )
			?? self::checkout_clean_lines( $s )
			?? self::checkout_age_confirmation( $s )
			?? self::checkout_buyer_checks( $s )
			?? self::checkout_prepare_transaction( $s );
		if ( null !== $early ) { return $early; }
		self::begin();
		try {
			self::checkout_lock_rows( $s );
			self::checkout_reserve( $s );
			self::checkout_price_lines( $s );
			self::checkout_apply_campaigns( $s );
			self::checkout_apply_taxes( $s );
			self::checkout_insert_order( $s );
			self::checkout_insert_lines( $s );
			self::checkout_commit( $s );
		} catch ( \Throwable $error ) {
			$failed = self::checkout_failed( $s, $error );
			if ( null !== $failed ) { return $failed; }
		}
		Sales\BuyerLimits::release( $s->buyer_locks );
		return self::checkout_completed( $s );
	}

	/** A401: create_attempt() part: request input, idempotency key, channel options, legal texts and trusted prices. Returns the state, or a WP_Error to return as is. */
	private static function checkout_state( $payload, array $internal, $attempt ) {
		$s = new Orders\CheckoutState();
		$s->payload = $payload;
		$s->internal = $internal;
		$s->attempt = $attempt;
		$s->payload = is_array( $s->payload ) ? $s->payload : array();
		$s->email = Http::email( $s->payload['email'] ?? '' );
		$s->lines = $s->payload['items'] ?? array();
		if ( Http::email_too_long( $s->email ) ) {
			// A493: refused at the entrance (400) instead of failing at the varchar(190) column (503 db_busy).
			return new \WP_Error( 'wpcap_invalid_checkout', self::email_too_long_message(), array( 'status' => 400, 'reason' => 'email_too_long', 'field' => 'email' ) );
		}
		if ( ! is_email( $s->email ) || ! is_array( $s->lines ) || empty( $s->lines ) || count( $s->lines ) > self::max_lines() ) {
			return new \WP_Error( 'wpcap_invalid_checkout', __( 'Bitte eine gültige E-Mail-Adresse und Tickets auswählen.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		// customer_email is varchar(190): a longer address passes is_email() but would fail the order insert.
		if ( strlen( $s->email ) > self::EMAIL_MAX_LENGTH ) {
			return self::email_too_long( 'email' );
		}

		$s->key = \PassCaptain\Text::cut( sanitize_text_field( Http::str( $s->payload['idempotency_key'] ?? '' ) ), 191 );
		if ( '' === $s->key ) {
			$s->key = wp_generate_uuid4();
		}
		$s->namespace = sanitize_key( $s->internal['key_namespace'] ?? '' );
		// Channel keys use a keyed hash so a public caller can never claim a POS/WooCommerce key.
		$s->key_hash = '' === $s->namespace ? hash( 'sha256', $s->key ) : hash_hmac( 'sha256', $s->key, 'wpcap-channel-' . $s->namespace );
		// Trusted adapter options (e.g. WooCommerce): the external system owns price and payment.
		$s->external = ! empty( $s->internal['external'] );
		$s->comp = ! $s->external && ! empty( $s->internal['comp'] );
		if ( $s->comp ) {
			$s->internal['payment_provider'] = 'comp';
			$s->internal['source'] = 'comp';
			$s->internal['skip_rate_limit'] = true;
			$s->internal['skip_buyer_limits'] = true;
			$s->internal['allow_door_sales'] = true;
			$s->payload['campaign_codes'] = array();
			// Own keyed idempotency namespace: a public caller can never claim a guest-list key.
			$s->namespace = 'comp';
			$s->key_hash = hash_hmac( 'sha256', $s->key, 'wpcap-channel-comp' );
		}
		$s->hold_minutes = max( 1, absint( $s->internal['hold_minutes'] ?? Settings::get( 'hold_minutes' ) ?? self::HOLD_MINUTES ) );
		$s->line_taxes = ( $s->external && is_array( $s->internal['line_taxes'] ?? null ) ) ? array_map( 'absint', $s->internal['line_taxes'] ) : array();
		$s->payment_provider = sanitize_key( $s->internal['payment_provider'] ?? ( $s->external ? ( $s->internal['source'] ?? 'external' ) : 'manual' ) );
		if ( ! $s->external && ! $s->comp && Settings::get( 'require_terms' ) && ! Http::accepted( $s->payload['accept_terms'] ?? null ) ) {
			return new \WP_Error( 'wpcap_terms_required', __( 'Bitte bestätige die Bedingungen und Verbraucherinformationen.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		// Buyer language: the locale the checkout form was rendered in (legal texts, mails), else the request locale.
		// W9: an explicit buyer language is only taken when the site offers it (Localization::order_locale()).
		// A165: the legal texts are those of the language the form was shown in ("locale"); the buyer may
		// choose another language for e-mails, tickets and the portal ("buyer_locale", Frontend\Language).
		$s->legal_locale = Mail\Mailer::normalize_locale( Localization::order_locale( is_string( $s->payload['locale'] ?? null ) ? $s->payload['locale'] : '' ) );
		if ( '' === $s->legal_locale ) { $s->legal_locale = function_exists( 'determine_locale' ) ? (string) determine_locale() : ''; }
		$s->order_locale = $s->external ? $s->legal_locale : Mail\Mailer::normalize_locale( Frontend\Language::order_locale( $s->payload ) );
		if ( '' === $s->order_locale ) { $s->order_locale = $s->legal_locale; }
		$s->terms_version = $s->external ? '' : Settings::terms_version( $s->legal_locale );
		$s->unit_prices = ( $s->external && is_array( $s->internal['unit_prices'] ?? null ) ) ? array_map( 'absint', $s->internal['unit_prices'] ) : array();
		// Exact gross line totals from the external system (unit prices may not divide evenly).
		$s->line_totals = ( $s->external && is_array( $s->internal['line_totals'] ?? null ) ) ? array_map( 'absint', $s->internal['line_totals'] ) : array();
		$s->external_currency = $s->external ? strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) ( $s->internal['currency'] ?? '' ) ) ) : '';
		// An internal retry was already counted (and admitted by the checkout gate) as one request.
		$s->skip_rate_limit = $s->external || ! empty( $s->internal['skip_rate_limit'] ) || $s->attempt > 0;
		// A576: unit prices the buyer was shown (type_id => minor units). Only an upper bound: a price that
		// rose since the page was rendered is refused (409 price_changed); a lower one is charged as is.
		$s->expected_prices = array();
		if ( ! $s->external && is_array( $s->payload['expected_prices'] ?? null ) ) {
			foreach ( $s->payload['expected_prices'] as $expected_type => $expected_minor ) {
				$expected_type = Http::positive_int( $expected_type );
				if ( $expected_type && ( is_int( $expected_minor ) || ( is_string( $expected_minor ) && ctype_digit( $expected_minor ) ) ) ) {
					$s->expected_prices[ $expected_type ] = (int) $expected_minor;
				}
			}
		}
		$s->changed_prices = array();
		return $s;
	}

	/** A401: create_attempt() part: an order that already has this idempotency key, and the shown version of the legal texts. Returns the result to return, or null to go on. */
	private static function checkout_existing_order( Orders\CheckoutState $s ) {
		global $wpdb;
		$orders_table = $wpdb->prefix . 'wpcap_orders';
		// A420: a public checkout key belongs to exactly one cart (schema 3.10.25; '' before the upgrade).
		$s->request_hash = ! $s->external && '' === $s->namespace && Log::has_column( 'wpcap_orders', 'request_hash' ) ? self::request_hash( $s->email, $s->lines, $s->payload ) : '';
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE idempotency_key = %s', $orders_table, $s->key_hash ) );
		if ( '' !== $wpdb->last_error ) {
			// Unknown whether an order with this key exists: never create a second one.
			return Errors::busy();
		}
		if ( $existing ) {
			if ( '' !== $s->request_hash && '' !== (string) ( $existing->request_hash ?? '' ) && ! hash_equals( (string) $existing->request_hash, $s->request_hash ) ) {
				return self::key_reused_error();
			}
			return self::reused_result( $existing );
		}
		// The form names the version it showed: accepting texts that changed meanwhile is no valid consent.
		// data.terms_version is the current version; the checkout script then reloads the texts in place
		// (GET /checkout/options), because a cached page would show the old ones again (A457).
		$shown_version = is_string( $s->payload['terms_version'] ?? null ) ? $s->payload['terms_version'] : '';
		if ( ! $s->external && Settings::get( 'require_terms' ) && '' !== $shown_version && $shown_version !== $s->terms_version ) {
			return new \WP_Error( 'wpcap_terms_changed', __( 'Die Bedingungen wurden inzwischen geändert. Bitte lade die Seite neu und bestätige die aktuelle Fassung.', 'pass-captain' ), array( 'status' => 409, 'terms_version' => $s->terms_version, 'locale' => $s->legal_locale ) );
		}
		// A568: the wording behind the stored version stays provable (archive, append-only).
		if ( '' !== $s->terms_version ) { LegalVersions::archive( $s->legal_locale ); }
		return null;
	}

	/** A401: create_attempt() part: ticket lines (quantities summed per type) and the state the buyer saw. WP_Error, or null to go on. */
	private static function checkout_clean_lines( Orders\CheckoutState $s ) {
		// The per-type limit is the ticket type's max_per_order (checked under lock below); this is
		// only an absolute safety cap against absurd quantities before any row is locked.
		$line_cap = self::max_line_quantity();
		$s->clean_lines = array();
		$s->shown_state = array();
		foreach ( $s->lines as $line ) {
			// Strict: booleans, negatives, fractions and arrays are invalid (absint() would turn -2 into 2).
			$type_id = is_array( $line ) ? Http::positive_int( $line['ticket_type_id'] ?? 0 ) : 0;
			$quantity = is_array( $line ) ? Http::positive_int( $line['quantity'] ?? 0 ) : 0;
			if ( ! $type_id || $quantity < 1 || $quantity > $line_cap ) {
				return new \WP_Error( 'wpcap_invalid_item', __( 'Eine Ticketposition ist ungültig.', 'pass-captain' ), array( 'status' => 400 ) );
			}
			$s->clean_lines[ $type_id ] = ( $s->clean_lines[ $type_id ] ?? 0 ) + $quantity;
			if ( $s->clean_lines[ $type_id ] > $line_cap ) {
				return new \WP_Error( 'wpcap_invalid_item', __( 'Eine Ticketposition ist ungültig.', 'pass-captain' ), array( 'status' => 400 ) );
			}
			// A560: the state the buyer saw (occurrence revision, displayed unit price); optional.
			if ( ! $s->external ) {
				foreach ( array( 'occurrence_revision' => 'revision', 'expected_price_minor' => 'price' ) as $field => $slot ) {
					if ( isset( $line[ $field ] ) && is_scalar( $line[ $field ] ) && preg_match( '/^\d{1,12}$/', (string) $line[ $field ] ) ) { $s->shown_state[ $type_id ][ $slot ] = (int) $line[ $field ]; }
				}
			}
		}
		return null;
	}

	/** A401: create_attempt() part: checkout gate, attendees, consents, billing data and add-on vetoes. WP_Error, or null to go on. */
	/**
	 * SET-PC-18: with the setting min_age_checkbox the buyer of the own online checkout confirms the minimum
	 * age when a line has one (payload confirm_age). Channels with their own namespace (box office, waitlist
	 * offers), trusted adapters and guest lists are not asked. WP_Error, or null to go on.
	 */
	private static function checkout_age_confirmation( Orders\CheckoutState $s ) {
		if ( $s->external || $s->comp || '' !== $s->namespace ) { return null; }
		$min_age = AdmissionChecks::confirmation_age( array_keys( $s->clean_lines ) );
		if ( $min_age < 1 || Http::accepted( $s->payload['confirm_age'] ?? null ) ) { return null; }
		/* translators: %d: minimum age in years */
		return new \WP_Error( 'wpcap_age_confirmation_required', sprintf( __( 'Bitte bestätige, dass alle Ticketinhaber mindestens %d Jahre alt sind.', 'pass-captain' ), $min_age ), array( 'status' => 400, 'min_age' => $min_age ) );
	}

	private static function checkout_buyer_checks( Orders\CheckoutState $s ) {
		// Add-on gate (e.g. virtual waiting room) for public checkouts only: not for channels with their own
		// namespace (box office, waitlist offers) or trusted adapters (WooCommerce, vouchers). Sales\CheckoutGate.
		if ( ! $s->external && '' === $s->namespace && 0 === $s->attempt ) {
			$gate = Sales\CheckoutGate::check( array( 'source' => $s->internal['source'] ?? 'native', 'phase' => 'checkout', 'items' => $s->clean_lines, 'email' => $s->email, 'idempotency_key' => $s->key, 'request' => $s->internal['request'] ?? null ) );
			if ( is_wp_error( $gate ) ) { return $gate; }
		}
		// A552: "same as buyer" per ticket takes the buyer address and the billing name (if given).
		$buyer_billing = is_array( $s->internal['billing'] ?? null ) ? $s->internal['billing'] : ( is_array( $s->payload['billing'] ?? null ) ? $s->payload['billing'] : array() );
		$buyer = array( 'email' => $s->email, 'name' => trim( Http::str( $buyer_billing['first_name'] ?? '' ) . ' ' . Http::str( $buyer_billing['last_name'] ?? '' ) ) );
		$s->attendees = self::validate_attendees(
			$s->clean_lines,
			$s->payload['attendees'] ?? array(),
			array(
				'defer_required' => ! empty( $s->internal['defer_attendees'] ),
				'allow_missing' => $s->comp || ( $s->external && ! empty( $s->internal['attendees_deferred'] ) ),
				'skip_required' => $s->comp,
				'buyer' => $buyer,
				'strict' => ! empty( $s->internal['strict_attendees'] ),
			)
		);
		if ( is_wp_error( $s->attendees ) ) { return $s->attendees; }
		// Consent proof (A116): text version shown, time, source, locale; recorded at issuance.
		$consent_source = Consents::source_for_channel( $s->internal['source'] ?? $s->namespace );
		$s->attendees = Consents::annotate( $s->attendees, $consent_source, $s->legal_locale, 'checkout' === $consent_source && ! $s->external ? Consents::ip_hash( self::client_ip() ) : '' );
		// Buyer billing data (company/address/VAT ID). Trusted adapters (e.g. WooCommerce) pass data that
		// their own checkout already validated as $s->internal['billing']; customer input is validated here.
		$s->billing = ( $s->external && is_array( $s->internal['billing'] ?? null ) ) ? Billing::sanitize( $s->internal['billing'] ) : Billing::validate( $s->payload['billing'] ?? null );
		if ( is_wp_error( $s->billing ) ) { return $s->billing; }
		$s->billing = $s->billing && ! Billing::is_empty( $s->billing ) ? $s->billing : array();
		// A331: own checkout rules of add-ons/modules, before anything is locked or written.
		$veto = self::checkout_veto( $s->email, $s->clean_lines, $s->payload, $s->internal, $s->namespace, $s->external );
		if ( is_wp_error( $veto ) ) { return $veto; }
		return null;
	}

	/** A401: create_attempt() part: rate limit, buyer limit locks and channel flags, before the transaction starts. WP_Error, or null to go on. */
	private static function checkout_prepare_transaction( Orders\CheckoutState $s ) {
		// Keep anonymous checkout abuse bounded without retaining raw IP addresses: per client network (IPv6 /64)
		// and per network + normalised e-mail; a checkout that creates no order is taken back (Sales\CheckoutThrottle).
		$s->throttle = null;
		if ( ! $s->skip_rate_limit ) {
			$s->throttle = Sales\CheckoutThrottle::check( $s->email, $s->hold_minutes );
			if ( is_wp_error( $s->throttle ) ) { return $s->throttle; }
		}
		$s->now = Clock::mysql();
		$s->door_sale = ! empty( $s->internal['allow_door_sales'] ) || 'pos' === $s->namespace;
		$s->custom_error = null;
		// Logged-in customer of a native checkout (buyer limits per account; never staff/adapter orders).
		$s->customer_user_id = ( $s->external || $s->door_sale || '' !== $s->namespace || ! empty( $s->internal['source'] ) ) ? 0 : absint( get_current_user_id() );
		// Buyer limits across orders (Sales\BuyerLimits): orders of the same buyer are serialised by a
		// lock taken before the transaction starts, so its snapshot contains all their committed orders.
		$s->check_buyer_limits = ! $s->door_sale && empty( $s->internal['skip_buyer_limits'] ) && Sales\BuyerLimits::relevant( array_keys( $s->clean_lines ) );
		$s->buyer_locks = array();
		if ( $s->check_buyer_limits ) {
			$s->buyer_locks = Sales\BuyerLimits::acquire( $s->email, $s->customer_user_id );
			if ( false === $s->buyer_locks ) {
				Sales\CheckoutThrottle::refund( $s->throttle );
				return new \WP_Error( 'wpcap_checkout_failed', __( 'Gerade ist sehr viel los. Bitte versuche es in einem Moment erneut.', 'pass-captain' ), array( 'status' => 503, 'retryable' => true, 'retry_after' => self::RETRY_AFTER_SECONDS ) );
			}
		}
		$s->entered_codes = Sales\Visibility::input_codes( is_array( $s->payload['campaign_codes'] ?? null ) ? $s->payload['campaign_codes'] : array() );
		$s->public_checkout = ! $s->external && '' === $s->namespace;
		// A425: the service fee applies to the own online ticket checkout only (no staff, adapter, comp,
		// waitlist or service booking channel). The column check runs before the transaction starts.
		$s->fee_channel = $s->public_checkout && ! $s->comp && ! $s->door_sale && empty( $s->internal['source'] ) && empty( $s->internal['skip_rate_limit'] ) && ServiceFee::ready();
		$s->service_fee = null;
		$s->commit_unknown = false;
		return null;
	}

	/** A401: create_attempt() part (transaction): locks the ticket types and their dates and checks that they are on sale. */
	private static function checkout_lock_rows( Orders\CheckoutState $s ) {
		global $wpdb;
		$types_table = $wpdb->prefix . 'wpcap_ticket_types';
		$occ_table = $wpdb->prefix . 'wpcap_occurrences';
		$event_table = $wpdb->prefix . 'wpcap_events';
		ksort( $s->clean_lines, SORT_NUMERIC );
		// Lock phase (locking reads only, so the transaction's consistent snapshot is taken after
		// every lock is held): ticket types by id, then their occurrences by id, then module
		// resources via `wpcap_reserve_capacity`. Late payment recovery uses the same order.
		$s->types = array();
		foreach ( $s->clean_lines as $type_id => $quantity ) {
			$type = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE ticket_type_id = %d FOR UPDATE', $types_table, $type_id ) );
			// A failed lock (deadlock, lock wait timeout, lost connection) is no "unavailable".
			if ( '' !== $wpdb->last_error ) { throw new \RuntimeException( 'db_busy' ); }
			if ( ! $type || 'active' !== $type->status ) {
				throw new \RuntimeException( 'ticket_type_unavailable' );
			}
			if ( ! $s->comp && $quantity > (int) $type->max_per_order ) {
				throw new \RuntimeException( 'quantity_limit' );
			}
			$s->types[ $type_id ] = $type;
		}
		// Add-on products (Sales\Addons) only with a main ticket of the same occurrence, at most n per ticket.
		$addon_error = Sales\Addons::checkout_error( $s->clean_lines, $s->types );
		if ( '' !== $addon_error ) { throw new \RuntimeException( $addon_error ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- error code, caught in create_attempt().
		// Hidden / code-only ticket types (Sales\Visibility): the public checkout needs an unlock code; other
		// channels are trusted. The type's own code list is checked here (no query in the lock phase); a
		// type unlocked by a campaign code is checked after Campaigns::calculate() (locked campaign rows).
		$s->locked_types = array();
		if ( $s->public_checkout ) {
			foreach ( $s->types as $type_id => $type ) {
				$visibility = Sales\Visibility::of( $type );
				if ( 'hidden' === $visibility ) { throw new \RuntimeException( 'ticket_type_unavailable' ); }
				if ( 'code' === $visibility && ! array_intersect( $s->entered_codes, Sales\Visibility::codes( $type ) ) ) { $s->locked_types[ $type_id ] = true; }
			}
		}
		$s->occurrence_ids = array_unique( array_map( static function ( $type ) { return (int) $type->occurrence_id; }, $s->types ) );
		sort( $s->occurrence_ids, SORT_NUMERIC );
		$s->occurrences = array();
		foreach ( $s->occurrence_ids as $occurrence_id ) {
			$s->occurrences[ $occurrence_id ] = $wpdb->get_row( $wpdb->prepare(
				'SELECT o.*,e.name AS event_name,e.status AS event_status FROM %i o INNER JOIN %i e ON e.event_id=o.event_id WHERE o.occurrence_id=%d FOR UPDATE', $occ_table, $event_table,
				$occurrence_id
			) );
			if ( '' !== $wpdb->last_error ) { throw new \RuntimeException( 'db_busy' ); }
		}
		$s->currency = '';
		foreach ( $s->types as $type_id => $type ) {
			$occurrence = $s->occurrences[ (int) $type->occurrence_id ];
			// Door sales (box office) may sell after the start and outside the online sales window,
			// but never for an occurrence that has ended. Online sales end at the start, or – with a
			// sales_end after the start (multi-day occurrences) – at that sales_end, never after the
			// effective end (Occurrence, A535).
			$closed = $occurrence && ( $s->door_sale ? Occurrence::is_over( $occurrence ) : Occurrence::online_sales_over( $occurrence, $type ) );
			if ( ! $occurrence || 'published' !== $occurrence->event_status || 'scheduled' !== $occurrence->status || $closed ) {
				throw new \RuntimeException( 'occurrence_unavailable' );
			}
			if ( ! $s->door_sale && ( ( $type->sales_start && $type->sales_start > $s->now ) || ( $type->sales_end && $type->sales_end < $s->now ) ) ) {
				throw new \RuntimeException( 'sales_closed' );
			}
			if ( isset( $s->shown_state[ $type_id ] ) ) {
				// A560: checked under the occurrence lock, so a reschedule cannot slip in between.
				$s->custom_error = self::shown_state_error( $s->shown_state[ $type_id ], $type, $occurrence, $s->now );
				if ( $s->custom_error ) { throw new \RuntimeException( (string) $s->custom_error->get_error_data()['reason'] ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- error code, caught in create_attempt().
			}
			if ( $s->external && 3 === strlen( $s->external_currency ) ) {
				$s->currency = $s->external_currency;
			} elseif ( '' === $s->currency ) {
				$s->currency = $type->currency;
			} elseif ( $s->currency !== $type->currency ) {
				throw new \RuntimeException( 'mixed_currency' );
			}
		}
	}

	/** A401: create_attempt() part (transaction): module capacity (`wpcap_reserve_capacity`) and buyer limits. */
	private static function checkout_reserve( Orders\CheckoutState $s ) {
		$s->capacity_context = array( 'phase' => 'create', 'order_id' => 0, 'types' => $s->types, 'occurrences' => $s->occurrences, 'now' => $s->now, 'source' => sanitize_key( $s->internal['source'] ?? ( '' !== $s->namespace ? $s->namespace : 'native' ) ), 'email' => $s->email, 'user_id' => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0, 'internal' => $s->internal );
		$reserve_error = self::reserve_capacity( $s->clean_lines, $s->capacity_context );
		if ( $reserve_error ) {
			$s->custom_error = $reserve_error;
			throw new \RuntimeException( $reserve_error->get_error_code() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- error code, caught in create_attempt().
		}
		if ( $s->check_buyer_limits ) {
			$limit_error = Sales\BuyerLimits::check( $s->clean_lines, $s->types, $s->occurrences, $s->email, $s->customer_user_id, $s->now );
			if ( $limit_error ) {
				$s->custom_error = $limit_error;
				throw new \RuntimeException( 'buyer_limit' );
			}
		}
	}

	/** A401: create_attempt() part (transaction): capacity per line and unit prices (trusted price, price filter and rules, shown prices). */
	private static function checkout_price_lines( Orders\CheckoutState $s ) {
		$s->selected = array();
		$s->total = 0;
		$s->price_rules = array();
		$occurrence_demand = array();
		foreach ( $s->clean_lines as $type_id => $quantity ) {
			$type = $s->types[ $type_id ];
			$occurrence = $s->occurrences[ (int) $type->occurrence_id ];
			// Expired holds are ignored by the capacity check (expires_at > now) and marked by the
			// cleanup job; updating them here took gap locks that deadlocked concurrent checkouts.
			// Every statement is checked: after a deadlock or lock wait timeout MariaDB rolls the
			// transaction back (releasing the capacity locks) and later statements would autocommit.
			// Several ticket types of one occurrence: its capacity is checked for their sum (add-ons use no seats).
			$occurrence_demand[ (int) $occurrence->occurrence_id ] = ( $occurrence_demand[ (int) $occurrence->occurrence_id ] ?? 0 ) + ( Sales\Addons::is_addon( $type ) ? 0 : $quantity );
			$capacity_error = self::capacity_error( $type, $occurrence, $quantity, 0, $occurrence_demand[ (int) $occurrence->occurrence_id ] );
			if ( $capacity_error ) {
				throw new \RuntimeException( $capacity_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- error code, caught in create_attempt().
			}
			// Price precedence: an explicit trusted unit price (adapters/staff, e.g. WooCommerce or
			// free box office sales) wins; otherwise the stored price passes through the
			// server-side `wpcap_ticket_unit_price` filter. Client input never reaches either.
			$base_price = (int) $type->price_minor;
			$price_rule = null;
			if ( $s->comp ) {
				$unit_price = 0;
			} elseif ( array_key_exists( $type_id, $s->unit_prices ) ) {
				$unit_price = (int) $s->unit_prices[ $type_id ];
			} else {
				$unit_price = self::unit_price( $type, $occurrence, $quantity, array( 'now' => $s->now, 'source' => $s->capacity_context['source'], 'phase' => 'create' ) );
				if ( $unit_price !== (int) $type->price_minor ) {
					$s->price_rules[ $type_id ] = array( 'base_minor' => (int) $type->price_minor, 'unit_minor' => $unit_price );
				}
				// Automatic price rule (Sales\PriceRules) frozen with the line: rule, explanation, prices.
				$price_rule = Sales\PriceRules::applied( $type_id );
				// A576: never charge more than the buyer was shown (early bird ended, demand tier, cached page).
				if ( isset( $s->expected_prices[ $type_id ] ) && $unit_price > $s->expected_prices[ $type_id ] ) {
					$s->changed_prices[ $type_id ] = array( 'shown_minor' => $s->expected_prices[ $type_id ], 'unit_minor' => $unit_price, 'formatted' => Frontend\Format::money( ServiceFee::shown_unit( $type, $unit_price ), $type->currency ), 'name' => (string) $type->name );
				}
			}
			// Versioned tax profile (Sales\TaxProfiles) unless a trusted adapter passed the line tax.
			$tax_profile = ( $s->comp || array_key_exists( $type_id, $s->line_taxes ) ) ? null : Sales\TaxProfiles::for_line( $type, $occurrence, $s->now );
			$type->price_minor = $unit_price;
			$line_total = array_key_exists( $type_id, $s->line_totals ) ? (int) $s->line_totals[ $type_id ] : $unit_price * $quantity;
			$s->total += $line_total;
			$s->selected[] = array( 'type' => $type, 'event_id' => (int) $occurrence->event_id, 'quantity' => $quantity, 'line_total' => $line_total, 'base_minor' => $base_price, 'price_rule' => $price_rule, 'tax_profile' => $tax_profile );
		}
		if ( $s->changed_prices ) {
			$s->custom_error = self::price_changed_error( $s->changed_prices );
			throw new \RuntimeException( 'price_changed' );
		}
	}

	/** A401: create_attempt() part (transaction): campaigns and unlock codes, discount, billing requirement, free-only and bank transfer hold limits. */
	private static function checkout_apply_campaigns( Orders\CheckoutState $s ) {
		$s->subtotal = $s->total;
		$campaign_codes = $s->external ? array() : ( $s->payload['campaign_codes'] ?? array() );
		if ( ! is_array( $campaign_codes ) ) { throw new \RuntimeException( 'campaign_invalid' ); }
		// Pure unlock codes (code list of a selected type) are no discount codes.
		$unlock_only = $s->public_checkout ? Sales\Visibility::unlock_only_codes( array_keys( $s->occurrences ), $s->entered_codes ) : array();
		if ( $unlock_only ) {
			$campaign_codes = array_values( array_filter( $campaign_codes, static function ( $code ) use ( $unlock_only ) { return ! in_array( Campaigns::normalize_code( $code ), $unlock_only, true ); } ) );
		}
		// Automatic campaigns (no code) only for orders that pay the core price, never for adapters or comps.
		$s->adjustments = Campaigns::calculate( $s->selected, $campaign_codes, $s->currency, array( 'auto' => ! $s->external && ! $s->comp, 'email' => $s->email ) );
		if ( is_wp_error( $s->adjustments ) ) {
			// Stacking rules (combinable, maximum discount, minimum quantity, per-buyer limit) explain themselves; other errors stay generic.
			if ( in_array( $s->adjustments->get_error_code(), array( 'wpcap_campaign_not_combinable', 'wpcap_campaign_max_discount', 'wpcap_campaign_min_quantity', 'wpcap_campaign_buyer_limit' ), true ) ) {
				$s->custom_error = $s->adjustments;
				throw new \RuntimeException( $s->adjustments->get_error_code() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- error code, caught in create_attempt().
			}
			throw new \RuntimeException( 'campaign_invalid' );
		}
		foreach ( $s->adjustments as $adjustment ) {
			if ( empty( $adjustment['auto'] ) ) { unset( $s->locked_types[ (int) $adjustment['campaign']->ticket_type_id ] ); }
		}
		if ( $s->locked_types ) { throw new \RuntimeException( 'ticket_type_unavailable' ); }
		$s->discount = array_sum( array_map( static function ( $adjustment ) { return (int) $adjustment['amount_minor']; }, $s->adjustments ) );
		$s->total = max( 0, $s->subtotal - $s->discount );
		// Trusted channels (box office "invoice", partner API) ask for the same rule via require_billing.
		if ( ( ! $s->external || ! empty( $s->internal['require_billing'] ) ) && ! $s->billing && Billing::required_for( $s->total, $s->currency ) ) { throw new \RuntimeException( 'billing_required' ); }
		// Free order without a payment method (REST checkout, free_only): refused as soon as anything is to pay (time-dependent price).
		if ( ! empty( $s->internal['free_only'] ) && $s->total > 0 ) {
			$s->custom_error = new \WP_Error( 'wpcap_provider_unavailable', __( 'Diese Zahlungsart ist nicht verfügbar.', 'pass-captain' ), array( 'status' => 400, 'field' => 'provider' ) );
			throw new \RuntimeException( 'provider_unavailable' );
		}
		// A634: unpaid bank transfer orders of the public checkout (payment method chosen by the buyer, no
		// trusted channel) hold their units for days; together they may hold at most a share of each
		// ticket type and date. Checked under the type/occurrence locks above, so concurrent checkouts
		// cannot exceed it together. Staff channels (admin holds, box office, partners) are not limited.
		if ( 'manual' === $s->payment_provider && isset( $s->internal['payment_provider'] ) && ! $s->external && ! $s->comp && ! $s->skip_rate_limit && '' === $s->namespace && $s->total > 0 ) {
			$manual_error = self::manual_hold_error( $s->clean_lines, $s->types, $s->occurrences, $s->now );
			if ( '' !== $manual_error ) { throw new \RuntimeException( $manual_error ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- error code, caught in create_attempt().
		}
	}

	/** A401: create_attempt() part (transaction): discount split, VAT per line and the service fee. */
	private static function checkout_apply_taxes( Orders\CheckoutState $s ) {
		// Allocate each campaign's discount to the lines it applies to (proportionally, exact to the
		// minor unit; Campaigns::calculate()) and compute VAT per line on the discounted gross amount.
		// The per-line discounts are kept in the price snapshot and on the order line (A007), so
		// invoices and refunds use the same split.
		$s->line_discounts = self::line_discounts( $s->selected, $s->adjustments );
		$s->tax_total = 0;
		foreach ( $s->selected as $index => $line ) {
			$line_discount = min( (int) $line['line_total'], max( 0, (int) ( $s->line_discounts[ $index ] ?? 0 ) ) );
			$s->selected[ $index ]['discount_minor'] = $line_discount;
			$type_id_for_tax = (int) $line['type']->ticket_type_id;
			// A115: rate from the active country tax profile (venue country, tax class, sale date) or
			// the ticket type's own rate; a VAT amount fixed by the channel keeps the type's rate.
			$tax_occurrence = $s->occurrences[ (int) $line['type']->occurrence_id ] ?? null;
			// A versioned profile of Sales\TaxProfiles (event country, date of supply) wins; it is mirrored
			// into the Tax\Profiles line columns so both views of the line agree.
			if ( array_key_exists( $type_id_for_tax, $s->line_taxes ) ) {
				$tax_info = array( 'rate_bp' => (int) ( $line['type']->tax_rate_bp ?? 0 ), 'source' => Tax\Profiles::SOURCE_EXTERNAL, 'profile_id' => 0, 'version' => '', 'country' => Tax\Profiles::venue_country( $tax_occurrence ) );
			} elseif ( $line['tax_profile'] ) {
				$tax_info = array( 'rate_bp' => (int) $line['tax_profile']['rate_bp'], 'source' => Tax\Profiles::SOURCE_PROFILE, 'profile_id' => (int) $line['tax_profile']['tax_profile_id'], 'version' => (string) ( $line['tax_profile']['snapshot']['version'] ?? '' ), 'country' => (string) ( $line['tax_profile']['snapshot']['country'] ?? Tax\Profiles::venue_country( $tax_occurrence ) ) );
			} else {
				$tax_info = Tax\Profiles::resolve( $line['type'], $tax_occurrence, $s->now, $s->capacity_context['source'] );
			}
			$rate = (int) $tax_info['rate_bp'];
			$s->selected[ $index ]['tax_rate_bp'] = $rate;
			$s->selected[ $index ]['tax_info'] = $tax_info;
			$s->selected[ $index ]['tax_minor'] = array_key_exists( $type_id_for_tax, $s->line_taxes ) ? (int) $s->line_taxes[ $type_id_for_tax ] : self::gross_tax( $line['line_total'] - $line_discount, $rate );
			$s->tax_total += $s->selected[ $index ]['tax_minor'];
		}
		// A425: presale/service fee (off by default): own order lines with the VAT rate of their ticket
		// line, never discounted; the order subtotal and total include it, the snapshot freezes it.
		$s->service_fee = $s->fee_channel ? ServiceFee::for_order( $s->selected, $s->total, array( 'currency' => $s->currency, 'email' => $s->email, 'occurrences' => $s->occurrences, 'now' => $s->now, 'source' => (string) $s->capacity_context['source'] ) ) : null;
		if ( $s->service_fee ) {
			$s->subtotal += (int) $s->service_fee['total_minor'];
			$s->total += (int) $s->service_fee['total_minor'];
			$s->tax_total += (int) $s->service_fee['tax_minor'];
			if ( ! $s->billing && Billing::required_for( $s->total, $s->currency ) ) { throw new \RuntimeException( 'billing_required' ); }
		}
		// A small-business seller (platform setting or agent organizer) must not sell with VAT: such an
		// order could never be invoiced (Partners\SalesModel::validate_agent_invoice(), § 14c UStG). Orders
		// of external systems (WooCommerce, adapters) are already paid there and keep their tax.
		if ( ! $s->external ) {
			$conflict = Partners\SalesModel::checkout_vat_conflict( $s->selected, $s->service_fee ? (int) $s->service_fee['tax_minor'] : 0 );
			if ( $conflict ) {
				Log::warning( 'checkout', 'Checkout refused: VAT on tickets of a small-business seller', array( 'seller' => $conflict['seller'], 'ticket_type_ids' => array_slice( $conflict['ticket_type_ids'], 0, 20 ) ) );
				throw new \RuntimeException( 'small_business_vat' );
			}
		}
	}

	/** A401: create_attempt() part (transaction): JSON price snapshot of the order. */
	private static function checkout_price_snapshot( Orders\CheckoutState $s ) {
		$snapshot = array( 'currency' => $s->currency, 'subtotal_minor' => $s->subtotal, 'discount_minor' => $s->discount, 'total_minor' => $s->total, 'campaigns' => array_map( static function ( $adjustment ) { return array( 'code' => $adjustment['campaign']->code, 'amount_minor' => (int) $adjustment['amount_minor'] ); }, $s->adjustments ) );
		if ( $s->discount ) {
			$snapshot['line_discounts'] = array();
			foreach ( $s->selected as $index => $line ) { $snapshot['line_discounts'][ (int) $line['type']->ticket_type_id ] = (int) $s->line_discounts[ $index ]; }
		}
		if ( $s->price_rules ) {
			// Lines whose unit price was changed by `wpcap_ticket_unit_price` (e.g. series price tiers).
			$snapshot['unit_price_adjustments'] = $s->price_rules;
		}
		if ( $s->service_fee ) { $snapshot['service_fee'] = $s->service_fee['snapshot']; }
		return wp_json_encode( $snapshot );
	}

	/** A401: create_attempt() part (transaction): inserts the order row. */
	private static function checkout_insert_order( Orders\CheckoutState $s ) {
		global $wpdb;
		$orders_table = $wpdb->prefix . 'wpcap_orders';
		$price_snapshot = self::checkout_price_snapshot( $s );

		if ( false === self::in_transaction() ) { throw new \RuntimeException( 'db_busy' ); }
		$s->public_id = bin2hex( random_bytes( 16 ) );
		$s->access_token = bin2hex( random_bytes( 32 ) );
		$order_row = array(
			'public_id' => $s->public_id,
			'idempotency_key' => $s->key_hash,
			'customer_email' => $s->email,
			'access_token_hash' => hash( 'sha256', $s->access_token ),
			'access_expires_at' => gmdate( 'Y-m-d H:i:s', Clock::now() + 30 * DAY_IN_SECONDS ),
			'status' => ( $s->total || $s->external ) ? 'pending_payment' : 'complete',
			'payment_status' => ( $s->total || $s->external ) ? 'pending' : 'paid',
			'currency' => $s->currency,
			'total_minor' => $s->total,
			'subtotal_minor' => $s->subtotal,
			'discount_minor' => $s->discount,
			'price_snapshot' => $price_snapshot,
			'tax_minor' => $s->tax_total,
			'payment_provider' => $s->payment_provider,
			'terms_version' => $s->comp ? '' : $s->terms_version,
			'locale' => $s->order_locale, // Buyer's explicit language choice (W9) or request locale.
			'attendee_data' => $s->attendees ? wp_json_encode( $s->attendees ) : null,
			'created_at' => $s->now,
			'updated_at' => $s->now,
		);
		// Only written when given, so checkouts keep working while a deferred schema upgrade is pending.
		if ( $s->billing ) { $order_row['billing'] = wp_json_encode( $s->billing ); }
		if ( $s->customer_user_id ) { $order_row['customer_user_id'] = $s->customer_user_id; }
		// Marketing attribution of public checkouts (assets/tracking.js; Privacy\Retention clears it).
		$attribution = $s->public_checkout ? self::attribution( $s->payload['attribution'] ?? null ) : '';
		if ( '' !== $attribution ) { $order_row['attribution'] = $attribution; }
		// Optional buyer name (checkout field "Dein Name"); only once the column exists (DB 3.17.5).
		$buyer_name = is_scalar( $s->payload['buyer_name'] ?? '' ) ? mb_substr( sanitize_text_field( (string) ( $s->payload['buyer_name'] ?? '' ) ), 0, 190 ) : '';
		if ( '' !== $buyer_name && self::schema_at_least( '3.17.5' ) ) { $order_row['customer_name'] = $buyer_name; }
		// Fingerprint of the attempt (Orders::request_fingerprint()); a retry with the same key is compared against it.
		// Not while a deferred schema upgrade (DB 3.12.0) is still pending: the column may not exist yet.
		if ( ! empty( $s->internal['request_fingerprint'] ) && preg_match( '/^[a-f0-9]{64}$/', (string) $s->internal['request_fingerprint'] ) && version_compare( strtok( (string) get_option( 'wpcap_db_version' ), '+' ), '3.12.0', '>=' ) ) { $order_row['request_fingerprint'] = (string) $s->internal['request_fingerprint']; }
		if ( '' !== $s->request_hash ) { $order_row['request_hash'] = $s->request_hash; }
		// A613: a free order is paid when it is created (column of schema 3.18.1; deferred upgrades omit it).
		if ( ! ( $s->total || $s->external ) && self::schema_at_least( '3.18.1' ) ) { $order_row['paid_at'] = $s->now; }
		$inserted = $wpdb->insert( $orders_table, $order_row );
		if ( ! $inserted ) {
			// Only a duplicate idempotency key is a lost race; anything else is a transient error.
			throw new \RuntimeException( false !== stripos( (string) $wpdb->last_error, 'duplicate' ) ? 'order_insert_failed' : 'db_busy' );
		}
		$s->order_id = (int) $wpdb->insert_id;
	}

	/** A401: create_attempt() part (transaction): order lines, holds, service fee lines and capacity counters. */
	private static function checkout_insert_lines( Orders\CheckoutState $s ) {
		global $wpdb;
		$reservations_table = $wpdb->prefix . 'wpcap_reservations';
		$items_table = $wpdb->prefix . 'wpcap_order_items';
		// Capacity counters (see CapacityCounters): the new lines/holds are inserted with their
		// counted units and the counters change in this transaction, under the capacity locks.
		$counted = CapacityCounters::maintained();
		$pending_order = $s->total || $s->external;
		$deltas = array();
		$occurrence_of = array();
		foreach ( $s->selected as $line ) {
			$type_id = (int) $line['type']->ticket_type_id;
			$item = array(
				'order_id' => $s->order_id,
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
					'order_id' => $s->order_id,
					'ticket_type_id' => $type_id,
					'quantity' => (int) $line['quantity'],
					'expires_at' => gmdate( 'Y-m-d H:i:s', Clock::now() + $s->hold_minutes * MINUTE_IN_SECONDS ),
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
		foreach ( $s->service_fee ? $s->service_fee['lines'] : array() as $fee_line ) {
			// A425: fee line next to its ticket line: quantity 0 = no admission and no inventory.
			$parent = $s->selected[ $fee_line['index'] ];
			$item = array(
				'order_id' => $s->order_id,
				'ticket_type_id' => (int) $parent['type']->ticket_type_id,
				'quantity' => 0,
				'unit_price_minor' => (int) $fee_line['unit_minor'],
				'line_total_minor' => (int) $fee_line['total_minor'],
				'tax_rate_bp' => (int) $fee_line['rate_bp'],
				'tax_minor' => (int) $fee_line['tax_minor'],
				'discount_minor' => 0,
				'fee_kind' => (string) $fee_line['kind'],
			);
			if ( $counted ) { $item['counted_qty'] = 0; }
			// Tax like the ticket line, or the fee's own tax profile (setting service_fee_tax).
			$fee_tax_profile = array_key_exists( 'tax_info', $fee_line ) ? $fee_line['tax_profile'] : $parent['tax_profile'];
			$item += Tax\Profiles::line_columns( array_key_exists( 'tax_info', $fee_line ) ? $fee_line['tax_info'] : $parent['tax_info'] );
			if ( $fee_tax_profile ) {
				$item['tax_profile_id'] = (int) $fee_tax_profile['tax_profile_id'];
				if ( array_key_exists( 'tax_info', $fee_line ) && ! empty( $fee_tax_profile['snapshot'] ) ) { $item['line_snapshot'] = wp_json_encode( array( 'tax' => $fee_tax_profile['snapshot'] ) ); }
			}
			$wpdb->insert( $items_table, $item );
			if ( '' !== $wpdb->last_error ) { throw new \RuntimeException( 'db_busy' ); }
		}
		if ( $counted && ! CapacityCounters::add( $deltas, $occurrence_of ) ) {
			throw new \RuntimeException( 'db_busy' );
		}
	}

	/** A401: create_attempt() part (transaction): `wpcap_order_creating`, campaign redemption and COMMIT. */
	private static function checkout_commit( Orders\CheckoutState $s ) {
		global $wpdb;
		/**
		 * Inside the order transaction (rows of ticket types/occurrences are locked). Modules may
		 * reserve further resources here (e.g. seats). Throw a \RuntimeException with a code to abort;
		 * map the code to a message via `wpcap_checkout_error_messages`.
		 */
		do_action( 'wpcap_order_creating', $s->order_id, $s->selected, $s->payload, $s->internal );
		$redeemed = Campaigns::redeem( $s->adjustments, $s->order_id );
		if ( is_wp_error( $redeemed ) ) { throw new \RuntimeException( 'wpcap_campaign_use_failed' === $redeemed->get_error_code() ? 'db_busy' : 'campaign_redeem_failed' ); }
		// A failure from here on may hide a commit (reply lost, reconnect): checked below. A
		// "successful" COMMIT that wpdb re-ran on a new session after a reconnect committed
		// nothing, so the order row is looked up once (primary key).
		if ( false === self::in_transaction() || false === $wpdb->query( 'COMMIT' ) || ! self::order_committed( $s->order_id, $s->key_hash ) ) {
			$s->commit_unknown = true;
			throw new \RuntimeException( 'db_busy' );
		}
	}

	/** A401: create_attempt() part: a failed transaction is rolled back and classified. The error to return, or null when the order was committed after all (lost connection). */
	private static function checkout_failed( Orders\CheckoutState $s, \Throwable $error ) {
		global $wpdb;
		// The server error is gone after ROLLBACK: classify it first (deadlock/lock wait timeout = busy;
		// support log, A322).
		$db_error = (string) $wpdb->last_error;
		// After a lost connection wpdb reconnects and runs the remaining statements outside the
		// transaction (autocommit) while the earlier ones were rolled back (A183). If the order row
		// exists, the connection was lost before its insert, so every later write (each one checked)
		// was committed: the order is complete. Otherwise the rows written after the loss are removed.
		$lost = ! empty( $s->order_id ) && false === self::in_transaction();
		$wpdb->query( 'ROLLBACK' );
		if ( ! ( $lost && $s->commit_unknown && self::order_committed( $s->order_id, $s->key_hash ) ) ) {
			Sales\BuyerLimits::release( $s->buyer_locks );
			Sales\CheckoutThrottle::refund( $s->throttle ); // No order: sold-out and other failed attempts do not use up the limit.
			if ( $lost ) { self::discard_lost_order( $s->order_id, $s->key_hash, $s->occurrence_ids ?? array() ); }
			return self::checkout_error( $error->getMessage(), $s->custom_error, $s->key_hash, $error, array( 'db_error' => $db_error, 'lost_connection' => $lost, 'commit_unknown' => $s->commit_unknown, 'source' => sanitize_key( $s->internal['source'] ?? ( '' !== $s->namespace ? $s->namespace : 'native' ) ), 'provider' => sanitize_key( (string) ( $s->internal['payment_provider'] ?? '' ) ), 'ticket_type_ids' => array_slice( array_keys( $s->clean_lines ), 0, 20 ), 'request_hash' => $s->request_hash ) );
		}
		return null;
	}

	/** A401: create_attempt() part: the committed order (`wpcap_order_created`, free order issuance, access link, audit) and the result. */
	private static function checkout_completed( Orders\CheckoutState $s ) {
		global $wpdb;
		$pending = $s->total || $s->external;
		if ( $s->throttle ) { Sales\CheckoutThrottle::record( $s->throttle, $pending ); }
		/**
		 * A331: the order is committed (fires once per new order, never for a reused idempotency key),
		 * before the payment of a free order and before any mail. Handlers must not throw.
		 */
		do_action( 'wpcap_order_created', $s->order_id, array( 'source' => sanitize_key( $s->internal['source'] ?? ( '' !== $s->namespace ? $s->namespace : 'native' ) ), 'external' => $s->external, 'pending_payment' => (bool) $pending, 'total_minor' => (int) $s->total, 'currency' => (string) $s->currency ) );
		$issued = null;
		if ( ! $pending ) {
			Campaigns::commit_for_order( $s->order_id );
			// Same order as for paid orders (confirm_external_payment): wpcap_order_paid before the
			// issuance (e.g. seat recovery), wpcap_tickets_issued after it.
			do_action( 'wpcap_order_paid', $s->order_id );
			$issued = self::issue_and_email( $s->order_id );
		}
		// A424: the link lasts until some time after the order's last date, not only 30 days from the purchase.
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_orders SET access_expires_at=%s WHERE order_id=%d AND access_expires_at<%s", self::access_until( $s->order_id ), $s->order_id, self::access_until( $s->order_id ) ) );
		if ( ! $s->external ) {
			self::send_order_access_link( $s->email, $s->public_id, $s->access_token );
		}
		Audit::record( 'order_created', 'order', $s->order_id, array( 'total_minor' => $s->total, 'currency' => $s->currency, 'source' => sanitize_key( $s->internal['source'] ?? 'native' ) ) );
		if ( is_wp_error( $issued ) ) {
			// The free order exists, its tickets do not yet: a retry with the same key issues them.
			return $issued;
		}
		return array( 'order_id' => $s->order_id, 'public_id' => $s->public_id, 'status' => $pending ? 'pending_payment' : 'complete', 'payment_status' => $pending ? 'pending' : 'paid', 'subtotal_minor' => $s->subtotal, 'discount_minor' => $s->discount, 'service_fee_minor' => $s->service_fee ? (int) $s->service_fee['total_minor'] : 0, 'total_minor' => $s->total, 'currency' => $s->currency, 'hold_minutes' => $pending ? $s->hold_minutes : 0 );
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
		return Orders\Attendees::validate( $lines, $attendees, $options, $skip_required );
	}

	/** Normalised attendee-name mode of a ticket type: off, optional (default) or required. */
	public static function attendee_name_mode( $value ) {
		return Orders\Attendees::name_mode( $value );
	}

	/**
	 * Choices of a select/radio participant field (one per line in the options column).
	 *
	 * @return string[]
	 */
	public static function field_options( $field ) {
		return Orders\Attendees::field_options( $field );
	}

	/**
	 * Sanitised answer for one participant field; null when the value is not valid for the field type
	 * (e-mail, choice outside the options, impossible date, phone number with other characters).
	 */
	public static function clean_answer( $field, $value ) {
		return Orders\Attendees::clean_answer( $field, $value );
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
				return new \WP_Error( 'wpcap_price_changed', sprintf( __( 'Der Preis für „%1$s“ hat sich inzwischen geändert – jetzt %2$s. Bitte prüfe die Angaben und sende die Bestellung erneut ab.', 'pass-captain' ), (string) $type->name, Frontend\Format::money( ServiceFee::shown_unit( $type, $price ), $type->currency ) ), array( 'status' => 409, 'reason' => 'price_changed', 'state' => $state ) );
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
			// A425: the displayed price includes the per-ticket service fee (prices[] stays the ticket price).
			$formatted[ (int) $row->ticket_type_id ] = Frontend\Format::money( ServiceFee::shown_unit( $row, $prices[ (int) $row->ticket_type_id ] ), $row->currency );
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
			'small_business_vat' => __( 'Diese Tickets können gerade nicht verkauft werden, weil die Steuerangaben der Ticketart noch geprüft werden müssen. Bitte versuche es später erneut oder wende dich an den Veranstalter.', 'pass-captain' ),
			'manual_event_hold_limit' => __( 'Per Überweisung sind für diese Veranstaltung gerade keine weiteren Tickets reservierbar, weil schon viele Überweisungen offen sind. Bitte wähle eine andere Zahlungsart oder eine kleinere Anzahl.', 'pass-captain' ),
			'addon_requires_ticket' => __( 'Zusatzprodukte kannst du nur zusammen mit mindestens einem Ticket für denselben Termin bestellen.', 'pass-captain' ),
			'addon_limit' => __( 'Für dieses Zusatzprodukt sind pro Ticket nicht so viele Stück möglich.', 'pass-captain' ),
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

	/** Starts a transaction (REPEATABLE READ, marker row on MySQL): Orders\Transaction::begin(). @return int|bool */
	private static function begin() {
		return Orders\Transaction::begin();
	}

	/** Whether the transaction started with begin() is still open (true/false, null = unknown): Orders\Transaction::in_transaction(). */
	private static function in_transaction() {
		return Orders\Transaction::in_transaction();
	}

	/** Resets the cached server detection (tests). Orders\Transaction::reset_transaction_tracking(). */
	public static function reset_transaction_tracking() {
		Orders\Transaction::reset_transaction_tracking();
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
		return Orders\Capacity::error( $type, $occurrence, $quantity, $exclude_order_id, $occurrence_quantity );
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

	/** Runs `wpcap_reserve_capacity` (module capacity: pools, seats, …): Orders\Capacity::reserve(). @return \WP_Error|null */
	private static function reserve_capacity( array $demand, array $context ) {
		return Orders\Capacity::reserve( $demand, $context );
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

	/** Whether a caller-started transaction is still open (false after a server-side rollback, null if unknown). Orders\Transaction::transaction_alive(). */
	public static function transaction_alive() {
		return Orders\Transaction::transaction_alive();
	}

	/** Fires `wpcap_capacity_released` after inventory of an order stopped counting. Orders\Transaction::capacity_released(). */
	private static function capacity_released( $order_id, $reason, array $details = array() ) {
		Orders\Transaction::capacity_released( $order_id, $reason, $details );
	}

	/**
	 * Forgets memoised availability. Runs on every write to a plugin table of this request (filter
	 * `query`), when capacity counters change and on demand (e.g. after module data changed).
	 */
	public static function flush_availability_cache() {
		Orders\Availability::flush();
	}

	/**
	 * Action `switch_blog` (and any other prefix change): the memo and the request caches of the
	 * modules belong to the previous site. Does not count as a write (the object cache stays usable;
	 * its keys contain the prefix).
	 */
	public static function site_switched() {
		Orders\Availability::site_switched();
	}

	/** Filter `query`: a write to a plugin table (or a rollback) invalidates the availability memo. */
	public static function note_query( $query ) {
		return Orders\Availability::note_query( $query );
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
		return Orders\Availability::of( $ticket_type_id );
	}

	/**
	 * availability() for many ticket types with a constant number of queries (listings, REST).
	 *
	 * @param int[] $ticket_type_ids Ticket type ids.
	 * @param array $rows            Optional preloaded rows: types (id => full row), occurrences (id => full row).
	 * @return array ticket_type_id => int|null (0 for unknown types).
	 */
	public static function availability_many( array $ticket_type_ids, array $rows = array() ) {
		return Orders\Availability::many( $ticket_type_ids, $rows );
	}

	/** Occurrence row (SELECT *), from the display batch when preloaded. */
	private static function occurrence_row( $occurrence_id ) {
		global $wpdb;
		return DisplayBatch::occurrence( $occurrence_id ) ?? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE occurrence_id=%d', $wpdb->prefix . 'wpcap_occurrences', $occurrence_id ) );
	}

	/**
	 * Confirms a payment reported by an external system (e.g. WooCommerce). Idempotent; a lapsed hold is
	 * re-checked under row locks (late payment recovery) or flagged `needs_review`. Orders\Payment::confirm_external_payment().
	 *
	 * @return true|\WP_Error
	 */
	public static function confirm_external_payment( $order_id, $reference = '' ) {
		return Orders\Payment::confirm_external_payment( $order_id, $reference );
	}

	/** Whether the order still has an unexpired active hold (non-locking read). */
	public static function has_active_hold( $order_id ) {
		return Orders\Holds::active( $order_id );
	}

	/** Admin text for the reason a late payment could not be allocated. Orders\Payment::late_payment_reason(). */
	public static function late_payment_reason( $code ) {
		return Orders\Payment::late_payment_reason( $code );
	}

	/**
	 * Queues the automatic refund of a payment that issued no tickets (`late_payment_auto_refund`).
	 * Orders\Payment::schedule_late_refund().
	 *
	 * @param object $order Order row.
	 * @param string $why   Audit reason.
	 * @return bool Whether a refund was queued.
	 */
	public static function schedule_late_refund( $order, $why ) {
		return Orders\Payment::schedule_late_refund( $order, $why );
	}

	/** Closes a paid order without tickets (`needs_review`/`paid_unallocated`) by a full refund (provider or external). Orders\Refunding::settle_unallocated(). */
	public static function settle_unallocated( $order_id, $mode, $note = '' ) {
		return Orders\Refunding::settle_unallocated( $order_id, $mode, $note );
	}

	/** Hold minutes for a bank transfer / manual payment (Orders\Holds::minutes()); null when not offered. */
	public static function manual_hold_minutes( array $type_ids ) {
		return Orders\Holds::minutes( $type_ids );
	}

	/** Bound of units held by unpaid bank transfer orders for a capacity (A634, Orders\Holds::cap()). */
	public static function manual_hold_cap( $capacity ) {
		return Orders\Holds::cap( $capacity );
	}

	/** Manual hold bounds of a new manual order (Orders\Holds::error()): error code or ''. */
	private static function manual_hold_error( array $lines, array $types, array $occurrences, $now ) {
		return Orders\Holds::error( $lines, $types, $occurrences, $now );
	}

	/** Absolute bound of tickets held by unpaid bank transfer orders per event (0 = no bound). */
	public static function manual_event_hold_cap() {
		return Orders\Holds::event_cap();
	}

	/** Tickets held by unpaid bank transfer orders for an event or one date (Orders\Holds::held_for_event()). */
	public static function manual_held_for_event( $event_id, $now = null, $occurrence_id = 0 ) {
		return Orders\Holds::held_for_event( $event_id, $now, $occurrence_id );
	}

	/** Whether bank transfer holds of a date are exhausted (Orders\Holds::exhausted()). */
	public static function manual_holds_exhausted( $event_id, $occurrence_id ) {
		return Orders\Holds::exhausted( $event_id, $occurrence_id );
	}

	/** manual_hold_minutes() for a known start timestamp (Orders\Holds::until()). */
	public static function manual_hold_until( $start_ts, $minutes = null ) {
		return Orders\Holds::until( $start_ts, $minutes );
	}

	/** Extends active holds of a pending order (e.g. WooCommerce "on-hold" bank transfers). */
	public static function extend_hold( $order_id, $minutes ) {
		return Orders\Holds::extend( $order_id, $minutes );
	}

	/** Extends the active, unexpired holds of an order to at least $until_ts (never shortens). */
	public static function extend_hold_to( $order_id, $until_ts ) {
		return Orders\Holds::extend_to( $order_id, $until_ts );
	}

	/** End of the order's hold (earliest active, unexpired hold) as timestamp; 0 without one. */
	public static function hold_end( $order_id ) {
		return Orders\Holds::end( $order_id );
	}

	/** Extends the hold for a delayed payment method (A577, Orders\Holds::extend_for_delayed_payment()). */
	public static function extend_hold_for_delayed_payment( $order_id ) {
		return Orders\Holds::extend_for_delayed_payment( $order_id );
	}

	/**
	 * Cancels an order (holds and campaign reservations released, valid tickets revoked). Idempotent;
	 * a database error rolls back and returns a retryable `wpcap_db_busy`. Orders\Cancellation::cancel_order().
	 *
	 * @param int           $order_id            Order.
	 * @param string        $reason              Audit reason.
	 * @param string[]|bool $only_payment_status Optional guard: allowed payment statuses, or true for "only unpaid".
	 * @return true|\WP_Error
	 */
	public static function cancel_order( $order_id, $reason = '', $only_payment_status = array() ) {
		return Orders\Cancellation::cancel_order( $order_id, $reason, $only_payment_status );
	}

	/** Revokes up to $count still valid tickets of a ticket type (newest first, checked-in tickets never); returns the number revoked. Orders\Refunding::revoke_tickets(). */
	public static function revoke_tickets( $order_id, $ticket_type_id, $count, $reason = '' ) {
		return Orders\Refunding::revoke_tickets( $order_id, $ticket_type_id, $count, $reason );
	}

	/**
	 * Marks an order as fully refunded and revokes all still valid tickets. Idempotent; on a database error
	 * a repair job is queued and a retryable `wpcap_db_busy` returned. Orders\Cancellation::mark_refunded().
	 *
	 * @return true|\WP_Error
	 */
	public static function mark_refunded( $order_id, $reason = '' ) {
		return Orders\Cancellation::mark_refunded( $order_id, $reason );
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
	 * Maximum number of order lines (distinct ticket types) in one checkout; filter `wpcap_max_lines`
	 * (default 10, 1 to 100). Add-ons such as the Pass Pro write API read their limit from here.
	 */
	public static function max_lines() {
		return max( 1, min( 100, (int) apply_filters( 'wpcap_max_lines', 10 ) ) );
	}

	/**
	 * Absolute cap of the quantity of one order line (checked before any row is locked): setting
	 * max_line_quantity (SET-PC-20, default 100, 1–1000), filter wpcap_max_line_quantity keeps precedence.
	 */
	public static function max_line_quantity() {
		return max( 1, (int) apply_filters( 'wpcap_max_line_quantity', max( 1, min( 1000, (int) Settings::get( 'max_line_quantity', 100 ) ) ) ) );
	}

	/**
	 * End of the order portal access (A424): setting order_access_days (SET-PC-20, default 30; filter `wpcap_order_access_days`) days from now, but at least as
	 * many days after the end of the order's last date, so the link still works at and after the event.
	 *
	 * @param int $order_id Order (0 = only the days from now).
	 * @return string UTC datetime.
	 */
	public static function access_until( $order_id = 0 ) {
		global $wpdb;
		$days = max( 1, min( 3650, (int) apply_filters( 'wpcap_order_access_days', max( 1, min( 3650, (int) Settings::get( 'order_access_days', 30 ) ) ) ) ) ); // SET-PC-20.
		$until = Clock::now() + $days * DAY_IN_SECONDS;
		if ( absint( $order_id ) ) {
			$last = (string) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(COALESCE(o.ends_at,o.starts_at)) FROM {$wpdb->prefix}wpcap_order_items oi INNER JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE oi.order_id=%d", absint( $order_id ) ) );
			$ts = '' !== $last ? strtotime( $last . ' UTC' ) : false;
			if ( false !== $ts ) { $until = max( $until, $ts + $days * DAY_IN_SECONDS ); }
		}
		return gmdate( 'Y-m-d H:i:s', $until );
	}

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
		$token = self::issue_access_token( (int) $order->order_id, $keep_current );
		return '' !== $token && self::send_order_access_link( $order->customer_email, $order->public_id, $token );
	}

	/**
	 * Issues a new access token for an order without mailing it (rotate_access_link() and callers that
	 * send several links in one mail). Returns the plain token ('' when the order does not exist).
	 *
	 * @param int  $order_id     Order.
	 * @param bool $keep_current Store it as pending link (A631) instead of replacing the current one.
	 */
	public static function issue_access_token( $order_id, $keep_current = false ) {
		global $wpdb;
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT order_id FROM {$wpdb->prefix}wpcap_orders WHERE order_id=%d", absint( $order_id ) ) );
		if ( ! $order ) { return ''; }
		$token = bin2hex( random_bytes( 32 ) );
		if ( $keep_current && self::pending_link_ready() ) {
			$wpdb->update( $wpdb->prefix . 'wpcap_orders', array( 'access_pending_hash' => hash( 'sha256', $token ), 'access_pending_expires_at' => gmdate( 'Y-m-d H:i:s', Clock::now() + self::PENDING_LINK_DAYS * DAY_IN_SECONDS ) ), array( 'order_id' => (int) $order->order_id ), array( '%s', '%s' ), array( '%d' ) );
		} else {
			$fields = array( 'access_token_hash' => hash( 'sha256', $token ), 'access_expires_at' => self::access_until( (int) $order->order_id ) );
			if ( self::pending_link_ready() ) { $fields += array( 'access_pending_hash' => '', 'access_pending_expires_at' => null ); }
			$wpdb->update( $wpdb->prefix . 'wpcap_orders', $fields, array( 'order_id' => (int) $order->order_id ), null, array( '%d' ) );
		}
		return $token;
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
		$expires = self::access_until( (int) $order->order_id );
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
		$lines = $wpdb->get_results( $wpdb->prepare( 'SELECT oi.item_id,oi.ticket_type_id,oi.unit_price_minor,' . ( ServiceFee::ready() ? 'oi.fee_kind,' : '' ) . OrderLines::paid_columns() . ",tt.name" . Sales\Addons::column_sql( 'tt' ) . ",e.name AS event_name,o.starts_at,o.timezone FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE oi.order_id=%d ORDER BY oi.item_id", absint( $order_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed column lists built from literals.
		$lines = array_values( array_filter( (array) $lines, static function ( $l ) { return ! OrderLines::is_moved_in( $l ); } ) );
		$after = OrderLines::after_discount( $lines, (int) $order->discount_minor );
		$out = array();
		foreach ( $lines as $i => $line ) {
			if ( (int) $line->quantity < 1 && (int) $after[ $i ] < 1 ) { continue; }
			$fee = ServiceFee::is_fee_line( $line );
			$out[] = array(
				'event' => (string) $line->event_name,
				// A425: a service fee line is named as such and counts the tickets it covers.
				'ticket' => $fee ? ServiceFee::label( $line, (string) $line->name ) : ( Sales\Addons::is_addon( $line ) ? Sales\Addons::line_label( (string) $line->name ) : (string) $line->name ),
				'when' => self::format_start( $line->starts_at, $line->timezone ),
				'quantity' => $fee ? ServiceFee::covered( $line ) : (int) $line->quantity,
				'gross_minor' => (int) $after[ $i ],
				'currency' => (string) $order->currency,
				'fee' => $fee,
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
		if ( method_exists( TicketAssignments::class, 'holder_changes' ) && TicketAssignments::holder_changes( (int) $ticket->ticket_id ) >= TicketAssignments::max_holder_changes( (int) $ticket->ticket_id ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_transfer_holder_changed', __( 'Für dieses Ticket wurde der Inhaber bereits geändert; eine weitere Übertragung ist nicht möglich.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		// A593: the transfer rotates the code – not once admission has begun or the code is on offline scanners.
		$locked = Admission\CodeRotationLock::reason( (int) $ticket->ticket_id );
		if ( '' !== $locked ) {
			$wpdb->query( 'ROLLBACK' );
			return Admission\CodeRotationLock::error( $locked );
		}
		$moved = self::move_ticket( $order, $ticket, $recipient_email, array( 'source' => 'portal' ) );
		if ( is_wp_error( $moved ) ) { return $moved; }
		if ( ! $moved['mailed'] ) {
			return new \WP_Error( 'wpcap_transfer_mail_failed', __( 'Die Ticketübertragung wurde gespeichert, aber die E-Mail konnte nicht zugestellt werden. Bitte kontaktiere den Veranstalter für einen erneuten Versand.', 'pass-captain' ), array( 'status' => 502 ) );
		}
		return array( 'status' => 'transferred' );
	}

	/**
	 * PASS-FE-03: staff change the holder of a ticket to another person (admin "Inhaber ändern"). Same
	 * effect as a buyer transfer in the order portal (move_ticket()): new code (the previous holder's
	 * link and wallet pass stop working), counted as a transfer (the buyer's order link can no longer
	 * re-target the ticket), the previous holder's name, answers and consents end, `wpcap_ticket_code_rotated`
	 * and `wpcap_ticket_transferred` (source "admin") fire. Unlike the portal, staff are not bound to the
	 * holder-change limit or the start of the date; open refunds and the code rotation lock (admission begun,
	 * offline scanners) still refuse. The caller checks permissions.
	 *
	 * @param int    $ticket_id Ticket.
	 * @param string $email     New holder address (required, must differ from the current holder).
	 * @param string $name      New attendee name (required).
	 * @param array  $context   reason (required), notify (bool, default true: the new holder gets the ticket, the buyer a notice).
	 * @return array|\WP_Error {status, ticket_id, mailed}
	 */
	public static function change_ticket_holder( $ticket_id, $email, $name, array $context = array() ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$ticket_id = absint( $ticket_id );
		$email = Http::email( $email );
		$name = Text::limit( sanitize_text_field( Http::str( $name ) ), TicketAssignments::NAME_MAX );
		$reason = sanitize_text_field( (string) ( $context['reason'] ?? '' ) );
		if ( '' === $reason ) {
			return new \WP_Error( 'wpcap_holder_change_reason', __( 'Bitte einen Grund für den Inhaberwechsel angeben.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		if ( Http::email_too_long( $email ) ) {
			return new \WP_Error( 'wpcap_holder_change_invalid', self::email_too_long_message(), array( 'status' => 400, 'field' => 'attendee_email' ) );
		}
		if ( ! is_email( $email ) || '' === $name ) {
			return new \WP_Error( 'wpcap_holder_change_invalid', __( 'Bitte Name und eine gültige E-Mail-Adresse der neuen Person angeben.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$order_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT order_id FROM %i WHERE ticket_id=%d', $p . 'wpcap_tickets', $ticket_id ) );
		self::begin();
		// Same lock order as the portal transfer and refunds: order row first, then the ticket.
		$order = $order_id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d FOR UPDATE', $p . 'wpcap_orders', $order_id ) ) : null;
		$ticket = $order ? $wpdb->get_row( $wpdb->prepare(
			'SELECT t.ticket_id,t.status,t.attendee_email,t.transfer_count,tt.name AS ticket_name,e.name AS event_name,o.starts_at,o.timezone,o.location FROM %i t INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id INNER JOIN %i e ON e.event_id=o.event_id WHERE t.ticket_id=%d AND t.order_id=%d FOR UPDATE', $p . 'wpcap_tickets', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $p . 'wpcap_events',
			$ticket_id,
			(int) $order->order_id
		) ) : null;
		if ( ! $order || ! $ticket ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_ticket_missing', __( 'Ticket nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) );
		}
		// transfer_count is a tinyint: a ticket moved 255 times cannot be counted any further.
		if ( 'valid' !== $ticket->status || 'paid' !== $order->payment_status || (int) $ticket->transfer_count >= 255 ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_holder_change_not_allowed', __( 'Nur gültige, nicht eingecheckte Tickets bezahlter Bestellungen können an eine andere Person gehen.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$holder = is_email( (string) $ticket->attendee_email ) ? (string) $ticket->attendee_email : (string) $order->customer_email;
		if ( 0 === strcasecmp( $holder, $email ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_holder_change_same', __( 'Das ist bereits die Adresse der Inhaber:in. Für Tippfehler derselben Person „Teilnehmerdaten korrigieren“ verwenden.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		// A537: while a refund of the ticket is pending or running, it stays with its holder.
		$open_refund = Refunds::ticket_has_open_refund( (int) $order->order_id, (int) $ticket->ticket_id );
		if ( false !== $open_refund ) {
			$wpdb->query( 'ROLLBACK' );
			return null === $open_refund
				? Errors::busy( __( 'Der Inhaber konnte gerade nicht geändert werden. Bitte erneut versuchen.', 'pass-captain' ) )
				: new \WP_Error( 'wpcap_ticket_refund_open', __( 'Für dieses Ticket läuft eine Erstattung oder sie wartet auf Freigabe. Es kann bis zur Entscheidung nicht übertragen, umgetauscht oder widerrufen werden.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		// A593: the change rotates the code – not once admission has begun or the code is on offline scanners.
		$locked = Admission\CodeRotationLock::reason( (int) $ticket->ticket_id );
		if ( '' !== $locked ) {
			$wpdb->query( 'ROLLBACK' );
			return Admission\CodeRotationLock::error( $locked );
		}
		return self::move_ticket( $order, $ticket, $email, array( 'source' => 'admin', 'attendee_name' => $name, 'notify' => (bool) ( $context['notify'] ?? true ), 'reason' => $reason ) );
	}

	/**
	 * Moves a ticket to another holder (portal transfer and admin holder change): new code, transfer
	 * counted, the previous holder's name, answers and consents end, hooks fire after the commit, mails.
	 * Call inside the transaction that holds the order and ticket row locks (checks done); it commits, or
	 * rolls back on a failed write.
	 *
	 * @param object $order           Order row.
	 * @param object $ticket          Locked ticket row (ticket_id, transfer_count, event_name, ticket_name, starts_at, timezone, location).
	 * @param string $recipient_email New holder.
	 * @param array  $opts            source (portal|admin), attendee_name (admin: the new name; portal: cleared),
	 *                                notify (bool, default true), reason (admin audit).
	 * @return array|\WP_Error {status: transferred, ticket_id, mailed}
	 */
	private static function move_ticket( $order, $ticket, $recipient_email, array $opts ) {
		global $wpdb;
		$tickets_table = $wpdb->prefix . 'wpcap_tickets';
		$admin = 'admin' === ( $opts['source'] ?? '' );
		$notify = (bool) ( $opts['notify'] ?? true );
		$previous_holder = Consents::holder_email_of( (int) $ticket->ticket_id );
		$token = bin2hex( random_bytes( 32 ) );
		CodeHistory::replacing( (int) $ticket->ticket_id, 'transfer' ); // A543: the old code says "transferred".
		// A581: the portal recipient is never the buyer; staff may move a ticket back to the buyer.
		$with_attendee = $admin ? TicketCodes::to_attendee( $recipient_email, (string) $order->customer_email ) : 1;
		$set = array(
			'token_hash' => hash( 'sha256', $token ),
			'attendee_email' => $recipient_email,
			'transfer_count' => (int) $ticket->transfer_count + 1,
		) + ( TicketCodes::holder_ready() ? array( 'code_with_attendee' => $with_attendee ) : array() ); // A581.
		$updated = $wpdb->update( $tickets_table, $set, array( 'ticket_id' => (int) $ticket->ticket_id, 'status' => 'valid', 'transfer_count' => (int) $ticket->transfer_count ), array_slice( array( '%s', '%s', '%d', '%d' ), 0, count( $set ) ), array( '%d', '%s', '%d' ) );
		if ( false === $updated ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_transfer_failed', __( 'Die Ticketübertragung konnte nicht gespeichert werden.', 'pass-captain' ), array( 'status' => 500 ) );
		}
		// The previous holder's name and custom answers must not stay on the transferred ticket; staff set the new name.
		if ( $admin ) {
			$wpdb->query( $wpdb->prepare( "INSERT INTO %i (ticket_id,buyer_email,attendee_email,attendee_name,status,created_at) VALUES (%d,%s,%s,%s,'assigned',%s) ON DUPLICATE KEY UPDATE attendee_email=VALUES(attendee_email),attendee_name=VALUES(attendee_name)", $wpdb->prefix . 'wpcap_ticket_assignments', (int) $ticket->ticket_id, (string) $order->customer_email, $recipient_email, (string) ( $opts['attendee_name'] ?? '' ), Clock::mysql() ) );
		} else {
			$wpdb->query( $wpdb->prepare( "UPDATE %i SET attendee_name='',attendee_email=%s WHERE ticket_id=%d", $wpdb->prefix . 'wpcap_ticket_assignments', $recipient_email, (int) $ticket->ticket_id ) );
		}
		$wpdb->delete( $wpdb->prefix . 'wpcap_participant_answers', array( 'ticket_id' => (int) $ticket->ticket_id ) );
		if ( $admin ) { TicketAssignments::touch( array( (int) $ticket->ticket_id ) ); } // A574: the name lives outside wpcap_tickets.
		$wpdb->query( 'COMMIT' );
		// The previous holder's consents end with the ticket (logged, hooks fire).
		Consents::end_for_ticket( (int) $ticket->ticket_id, 'transfer', $previous_holder );
		/** A ticket received a new code; the old one is invalid. $token is plaintext – never store it. */
		do_action( 'wpcap_ticket_code_rotated', (int) $ticket->ticket_id, $token, 'transfer' );
		/** A674: another person holds the ticket now (after the commit; no code, no addresses). */
		do_action( 'wpcap_ticket_transferred', (int) $ticket->ticket_id, array( 'order_id' => (int) $order->order_id, 'source' => $admin ? 'admin' : 'portal', 'holder_changes' => (int) $ticket->transfer_count + 1 + TicketAssignments::holder_changes( (int) $ticket->ticket_id ) ) );
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
		$audit = array( 'order_id' => (int) $order->order_id ) + ( $admin ? array( 'source' => 'admin', 'reason' => (string) ( $opts['reason'] ?? '' ), 'notified' => $notify ) : array() );
		$result = array( 'status' => 'transferred', 'ticket_id' => (int) $ticket->ticket_id, 'mailed' => false );
		if ( ! $notify ) {
			Audit::record( 'ticket_transferred', 'ticket', $ticket->ticket_id, $audit );
			return $result;
		}
		// The recipient's language is unknown; the order's locale is the best available guess.
		if ( ! Mail\Mailer::send( 'ticket_transfer_recipient', $recipient_email, $vars, array(), (string) $order->locale, (int) $order->order_id ) ) {
			if ( $admin ) { Audit::record( 'ticket_transferred', 'ticket', $ticket->ticket_id, $audit + array( 'mailed' => false ) ); }
			return $result;
		}
		// A652: the previous holder learns that the ticket moved – never the new code (link, help code).
		Mail\Mailer::send( 'ticket_transfer_sender', $order->customer_email, self::without_code_vars( $vars ), array(), (string) $order->locale, (int) $order->order_id );
		Audit::record( 'ticket_transferred', 'ticket', $ticket->ticket_id, $audit );
		$result['mailed'] = true;
		return $result;
	}

	/**
	 * Cancels a published event (every occurrence, as a resumable run). Orders\Cancellation::cancel_event().
	 *
	 * @return array|\WP_Error {status, notified_count, occurrences, cancelled_orders, refunds, refund_errors, revoked, auto_refund}
	 */
	public static function cancel_event( $event_id, $reason = '' ) {
		return Orders\Cancellation::cancel_event( $event_id, $reason );
	}

	/**
	 * Continues the cancellation of an already cancelled event (A428). Orders\Cancellation::resume_event_cancellation().
	 *
	 * @return array|\WP_Error Same keys as cancel_event() plus resumed.
	 */
	public static function resume_event_cancellation( $event_id ) {
		return Orders\Cancellation::resume_event_cancellation( $event_id );
	}

	/** Tickets of an order that can still be refunded ({ticket_id, ticket_type_id}); $failed is set when a read failed. Orders\Refunding::refundable_tickets(). */
	public static function refundable_tickets( $order_id, &$failed = null ) {
		return Orders\Refunding::refundable_tickets( $order_id, $failed );
	}

	/** Staff confirmation of a manual payment (bank transfer, box office). Orders\Payment::confirm_manual_payment(). */
	public static function confirm_manual_payment( $order_id ) {
		return Orders\Payment::confirm_manual_payment( $order_id );
	}

	/** Expires unpaid orders whose holds lapsed (at most $limit, optionally only $only); returns the number expired. Orders\Expiry::expire_holds(). */
	public static function expire_holds( $limit = 200, array $only = array() ) {
		return Orders\Expiry::expire_holds( $limit, $only );
	}

	/** 5-minute worker: expires lapsed holds in batches within the cron time budget. Orders\Expiry::expire_holds_worker(). */
	public static function expire_holds_worker() {
		return Orders\Expiry::expire_holds_worker();
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

	/**
	 * Tickets per order up to which the ticket mail (PDF, wallet, SMTP) is sent inside the request that
	 * confirmed the payment (filter wp_pass_captain_ticket_mail_inline_max_v1). Larger orders – and every order
	 * confirmed by a payment webhook – are delivered by the outbox job right away instead.
	 */
	const INLINE_DELIVERY_MAX = 10;

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

	/** Issues the missing tickets of a paid order and mails them (outbox job, retries); idempotent. Orders\Issuance::issue_and_email(). */
	public static function issue_and_email( $order_id ) {
		return Orders\Issuance::issue_and_email( $order_id );
	}

	/** Runs $callback with ticket mails deferred to the job queue (payment webhooks). Orders\Issuance::defer_ticket_mail(). */
	public static function defer_ticket_mail( callable $callback ) {
		return Orders\Issuance::defer_ticket_mail( $callback );
	}

	/** Option name of the "delivered" marker of one ticket delivery (order + its tickets). Orders\Issuance::delivery_marker(). */
	public static function delivery_marker( $order_id, array $ticket_ids ) {
		return Orders\Issuance::delivery_marker( $order_id, $ticket_ids );
	}

	/** Safety net: issues the missing tickets of recently paid orders right away. Orders\Issuance::reconcile_issuance(). */
	public static function reconcile_issuance( $limit = 20 ) {
		return Orders\Issuance::reconcile_issuance( $limit );
	}

	/** Job handler (filter `wpcap_process_job`): issuance retries, delivery outbox, repairs, late refund, resend mail retry. Orders\Issuance::handle_job(). */
	public static function handle_job( $handled, $type, $payload = array(), $job_id = 0 ) {
		return Orders\Issuance::handle_job( $handled, $type, $payload, $job_id );
	}

	/** Seconds a paid order may be without tickets before the sweep queues an issuance (A343). */
	const UNISSUED_GRACE = 600;

	/** Paid orders older than this are left to the Doctor check (no automatic mail of old tickets). */
	const UNISSUED_LOOKBACK = 2 * DAY_IN_SECONDS;

	/** Cron: queues the issuance of paid orders that still have no ticket (A343). Orders\Issuance::sweep_unissued(). */
	public static function sweep_unissued( $limit = 50 ) {
		return Orders\Issuance::sweep_unissued( $limit );
	}

	/** Hands the attachment files of a sent mail to the cleanup (A626). Orders\Delivery::discard_attachments(). */
	public static function discard_attachments( array $files, $dir = '' ) {
		Orders\Delivery::discard_attachments( $files, $dir );
	}

	/** SQL join (alias oi) that leaves out lines of add-on products; '' before schema 3.18.5. Orders\Delivery::addon_free_join(). */
	public static function addon_free_join() {
		return Orders\Delivery::addon_free_join();
	}

	/** Tickets a paid order should have but does not; null on a database error. Orders\Delivery::missing_tickets(). */
	public static function missing_tickets( $order_id ) {
		return Orders\Delivery::missing_tickets( $order_id );
	}

	/** Delivery status of the latest ticket mail of an order (outbox job); null when unknown. Orders\Delivery::delivery_status(). */
	public static function delivery_status( $order_id ) {
		return Orders\Delivery::delivery_status( $order_id );
	}

	/** Placeholder values shared by the ticket and resend mails. Orders\Delivery::ticket_mail_vars(). */
	public static function ticket_mail_vars( $order, array $entries, $list ) {
		return Orders\Delivery::ticket_mail_vars( $order, $entries, $list );
	}

	/** Extra mail section of a ticket with a new code, in the mail's locale (A474). Orders\Delivery::code_mail_section(). */
	public static function code_mail_section( $ticket_id, $locale = '' ) {
		return Orders\Delivery::code_mail_section( $ticket_id, $locale );
	}

	/** Tickets per attached ticket PDF (A580). Orders\Delivery::ticket_pdf_chunk_size(). */
	public static function ticket_pdf_chunk_size() {
		return Orders\Delivery::ticket_pdf_chunk_size();
	}

	/** Attachment budget of a ticket mail in bytes (A580). Orders\Delivery::attachment_budget(). */
	public static function attachment_budget( $order = null ) {
		return Orders\Delivery::attachment_budget( $order );
	}

	/** A621: entry of the ticket PDF for one ticket. Orders\Delivery::pdf_ticket_entry(). */
	public static function pdf_ticket_entry( array $ticket ) {
		return Orders\Delivery::pdf_ticket_entry( $ticket );
	}

	/** A621/A580: the ticket PDF attachments of a mail ({files, covered, dir}). Orders\Delivery::ticket_pdf_attachments(). */
	public static function ticket_pdf_attachments( $order, $recipient, array $pdf_tickets ) {
		return Orders\Delivery::ticket_pdf_attachments( $order, $recipient, $pdf_tickets );
	}

	/** Event start in the event's own timezone; starts_at is stored in UTC. Orders\Delivery::format_start(). */
	public static function format_start( $starts_at, $timezone ) {
		return Orders\Delivery::format_start( $starts_at, $timezone );
	}

	/** Name used in customer communication (site title, falls back to the host name). Orders\Delivery::site_name(). */
	public static function site_name() {
		return Orders\Delivery::site_name();
	}

	/** Resends the valid tickets of a paid order, one mail per holder (codes rotated or kept, A588). Orders\Resend::resend_tickets(). */
	public static function resend_tickets( $order_id, array $options = array() ) {
		return Orders\Resend::resend_tickets( $order_id, $options );
	}

	/** Payload of a ticket_mail_retry job (keeps the scope of the resend, A335). Orders\Resend::mail_retry_payload(). */
	public static function mail_retry_payload( $order_id, $skip_transferred = false, $only_unassigned = false ) {
		return Orders\Resend::mail_retry_payload( $order_id, $skip_transferred, $only_unassigned );
	}

	/** Valid and transferred ticket counts of an order for the admin resend form (A335). Orders\Resend::resend_counts(). */
	public static function resend_counts( $order_id ) {
		return Orders\Resend::resend_counts( $order_id );
	}

	/** Reissues a single valid ticket (new code) and mails the link to its holder. Orders\Resend::reissue_ticket(). */
	public static function reissue_ticket( $ticket_id, array $context = array() ) {
		return Orders\Resend::reissue_ticket( $ticket_id, $context );
	}
}

// Job handler (issuance retries, ticket delivery outbox); see Orders::register().
Orders::register();

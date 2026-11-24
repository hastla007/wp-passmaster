<?php
/** Transaction-safe discount campaigns for checkout. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class Campaigns {
	/**
	 * Whether a code entered in the single checkout code field is a discount campaign (any status, so an expired
	 * campaign still gets the campaign's own error message instead of "unknown voucher").
	 */
	public static function is_code( $code ) {
		global $wpdb;
		$code = self::normalize_code( $code );
		if ( '' === $code ) { return false; }
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE code=%s LIMIT 1', $wpdb->prefix . 'wpcap_campaigns', $code ) );
	}

	/**
	 * Validate and calculate the campaign codes of an order (A117 stacking rules).
	 * The caller must already be inside the order transaction (unless $context['lock'] is false).
	 *
	 * - At most Settings `campaign_max_codes` codes (1–5, default 3).
	 * - A campaign with stacking "exclusive" (or combinable = 0) is only valid alone.
	 * - Settings `campaign_stacking`: "cumulative" (default) applies the codes one after another on what
	 *   is left, ordered by priority (smaller number first); "best" applies only the code with the
	 *   highest discount (on a tie the one with the lower priority value), the others are ignored.
	 * - Filter `wpcap_campaign_stacking` ( string $mode, array $campaigns, array $selected ) can change
	 *   the mode per order.
	 * - Every code is split over its lines proportionally to what is left of them (Orders::allocate()),
	 *   exactly as stored in price_snapshot.line_discounts and on the order line (A007, PAY-5).
	 * - with_price_rules = 0 ignores lines whose price came from an automatic price rule (line key
	 *   price_rule); max_discount_minor caps the discount of one campaign (0 = no cap); the setting
	 *   max_total_discount_bp caps the total reduction of the order (price rule reductions plus all
	 *   campaign discounts) as a share of the order value at stored prices; later codes are reduced first.
	 * - min_quantity: the lines the campaign applies to must hold at least that many tickets;
	 *   per_buyer_limit: uses per buyer e-mail (reserved or committed orders; 0 = no limit).
	 * - auto_apply campaigns need no code: with $context['auto'] the matching active ones are added
	 *   after the explicit codes (at most three, priority order); one that does not match, is not
	 *   combinable with the codes or would yield no discount is skipped silently.
	 *
	 * @param array      $selected Lines: type, event_id, quantity, line_total, optional base_minor and price_rule.
	 * @param array      $codes    Entered codes.
	 * @param string     $currency Currency.
	 * @param array|bool $context  auto (bool), email (buyer), lock (bool, default true: row locks; false for a
	 *                             non-binding preview). A boolean is the legacy $lock parameter.
	 * @return array|\WP_Error Adjustments: campaign, amount_minor, lines (index of $selected => part of
	 *                         amount_minor; the parts add up to amount_minor), optional capped, auto.
	 */
	public static function calculate( array $selected, array $codes, $currency, $context = array() ) {
		$context = is_array( $context ) ? $context : array( 'lock' => (bool) $context );
		$lock = ! array_key_exists( 'lock', $context ) || ! empty( $context['lock'] );
		global $wpdb;
		$codes = array_values( array_unique( array_filter( array_map( array( __CLASS__, 'normalize_code' ), $codes ) ) ) );
		sort( $codes, SORT_STRING );
		$max_codes = max( 1, min( 5, (int) Settings::get( 'campaign_max_codes', 3 ) ) );
		if ( count( $codes ) > $max_codes ) {
			return new \WP_Error( 'wpcap_campaign_limit', 1 === $max_codes ? __( 'Pro Bestellung kann nur ein Rabattcode verwendet werden.', 'pass-captain' ) : sprintf( /* translators: %d: maximum number of discount codes */ __( 'Es können höchstens %d Rabattcodes verwendet werden.', 'pass-captain' ), $max_codes ), array( 'status' => 400 ) );
		}
		$campaigns = array();
		$table = $wpdb->prefix . 'wpcap_campaigns';
		$now = Clock::mysql();
		foreach ( $codes as $code ) {
			$campaign = $wpdb->get_row( $wpdb->prepare( "SELECT *,discount_value AS value FROM %i WHERE code=%s AND status='active'" . ( $lock ? ' FOR UPDATE' : '' ), $table, $code ) );
			if ( ! $campaign || ( $campaign->starts_at && $campaign->starts_at > $now ) || ( $campaign->ends_at && $campaign->ends_at < $now ) || ( $campaign->usage_limit > 0 && (int) $campaign->used_count >= (int) $campaign->usage_limit ) || strtoupper( $campaign->currency ) !== strtoupper( $currency ) ) {
				return new \WP_Error( 'wpcap_campaign_unavailable', __( 'Ein Kampagnencode ist ungültig, abgelaufen oder nicht mehr verfügbar.', 'pass-captain' ), array( 'status' => 409 ) );
			}
			$rule_error = self::rule_error( $campaign, $selected, (string) ( $context['email'] ?? '' ) );
			if ( $rule_error ) { return $rule_error; }
			$campaigns[] = $campaign;
		}
		if ( ! empty( $context['auto'] ) ) {
			$campaigns = array_merge( $campaigns, self::auto_campaigns( $campaigns, $selected, $currency, (string) ( $context['email'] ?? '' ), $now ) );
		}
		if ( count( $campaigns ) > 1 ) {
			foreach ( $campaigns as $campaign ) {
				if ( ! (int) ( $campaign->combinable ?? 1 ) ) {
					/* translators: %s: campaign code */
					return new \WP_Error( 'wpcap_campaign_not_combinable', sprintf( __( 'Der Code %s kann nicht mit anderen Codes kombiniert werden.', 'pass-captain' ), $campaign->code ), array( 'status' => 409 ) );
				}
			}
		}
		usort( $campaigns, static function ( $a, $b ) { $priority = (int) $a->priority <=> (int) $b->priority; return $priority ?: ( (int) $a->campaign_id <=> (int) $b->campaign_id ); } );
		if ( count( $campaigns ) > 1 ) {
			foreach ( $campaigns as $campaign ) {
				if ( 'exclusive' === ( $campaign->stacking ?? 'combinable' ) ) {
					/* translators: %s: discount code */
					return new \WP_Error( 'wpcap_campaign_not_combinable', sprintf( __( 'Der Rabattcode %s kann nicht mit anderen Codes kombiniert werden.', 'pass-captain' ), $campaign->code ), array( 'status' => 409 ) );
				}
			}
			$mode = (string) apply_filters( 'wpcap_campaign_stacking', 'best' === Settings::get( 'campaign_stacking', 'cumulative' ) ? 'best' : 'cumulative', $campaigns, $selected );
			if ( 'best' === $mode ) {
				$best = null;
				$best_amount = -1;
				foreach ( $campaigns as $campaign ) {
					$single = self::calculate_sequence( $selected, array( $campaign ) );
					if ( is_wp_error( $single ) ) { continue; }
					$amount = (int) $single[0]['amount_minor'];
					if ( $amount > $best_amount ) { $best = $single; $best_amount = $amount; }
				}
				return $best ?? new \WP_Error( 'wpcap_campaign_no_match', __( 'Ein Kampagnencode passt nicht zu den ausgewählten Tickets.', 'pass-captain' ), array( 'status' => 409 ) );
			}
		}
		return self::calculate_sequence( $selected, $campaigns );
	}

	/**
	 * Applies the campaigns one after another on what is left of the eligible lines.
	 *
	 * @return array|\WP_Error
	 */
	private static function calculate_sequence( array $selected, array $campaigns ) {
		$adjustments = array();
		$remaining = array_map( static function ( $line ) { return (int) $line['line_total']; }, $selected );
		foreach ( $campaigns as $campaign ) {
			$eligible = self::eligible( $campaign, $selected, $remaining );
			$sum = array_sum( $eligible );
			$discount = 'percent' === $campaign->kind ? (int) floor( $sum * min( 10000, (int) $campaign->value ) / 10000 ) : min( $sum, (int) $campaign->value );
			if ( (int) ( $campaign->max_discount_minor ?? 0 ) > 0 ) { $discount = min( $discount, (int) $campaign->max_discount_minor ); }
			if ( $discount < 1 && ! empty( $campaign->auto_applied ) ) { continue; }
			if ( $discount < 1 ) { return new \WP_Error( 'wpcap_campaign_no_match', __( 'Ein Kampagnencode passt nicht zu den ausgewählten Tickets.', 'pass-captain' ), array( 'status' => 409 ) ); }
			// The same proportional split that is stored with the order (Orders::allocate()), so the next
			// code sees exactly what is left of each line.
			foreach ( Orders::allocate( $eligible, $discount ) as $index => $part ) { $remaining[ $index ] -= $part; }
			$adjustments[] = array( 'campaign' => $campaign, 'amount_minor' => $discount ) + ( empty( $campaign->auto_applied ) ? array() : array( 'auto' => true ) );
		}
		$cap_bp = min( 10000, absint( Settings::get( 'max_total_discount_bp' ) ) );
		if ( $cap_bp > 0 && $adjustments ) {
			$base = 0;
			$rule_reduction = 0;
			foreach ( $selected as $line ) {
				$line_base = isset( $line['base_minor'] ) ? (int) $line['base_minor'] * (int) $line['quantity'] : (int) $line['line_total'];
				$base += $line_base;
				$rule_reduction += max( 0, $line_base - (int) $line['line_total'] );
			}
			$allowed = max( 0, intdiv( $base * $cap_bp, 10000 ) - $rule_reduction );
			$excess = array_sum( array_map( static function ( $a ) { return (int) $a['amount_minor']; }, $adjustments ) ) - $allowed;
			for ( $i = count( $adjustments ) - 1; $i >= 0 && $excess > 0; $i-- ) {
				$cut = min( $excess, (int) $adjustments[ $i ]['amount_minor'] );
				$adjustments[ $i ]['amount_minor'] -= $cut;
				$adjustments[ $i ]['capped'] = true;
				$excess -= $cut;
				if ( $adjustments[ $i ]['amount_minor'] < 1 ) {
					/* translators: %s: campaign code */
					return new \WP_Error( 'wpcap_campaign_max_discount', sprintf( __( 'Der Code %s kann nicht angewendet werden, weil der höchstmögliche Rabatt für diese Bestellung bereits erreicht ist.', 'pass-captain' ), $adjustments[ $i ]['campaign']->code ), array( 'status' => 409 ) );
				}
			}
		}
		// Final per-line split of the (possibly capped) amounts – the one the order stores in
		// price_snapshot.line_discounts and computes line VAT with (PAY-5: one source of truth).
		$remaining = array_map( static function ( $line ) { return (int) $line['line_total']; }, $selected );
		foreach ( $adjustments as $n => $adjustment ) {
			$parts = Orders::allocate( self::eligible( $adjustment['campaign'], $selected, $remaining ), (int) $adjustment['amount_minor'] );
			foreach ( $parts as $index => $part ) { $remaining[ $index ] -= $part; }
			$adjustments[ $n ]['lines'] = $parts;
		}
		return $adjustments;
	}

	/** Code as stored: upper case letters, digits, "_" and "-" only ('' for non-scalars). */
	public static function normalize_code( $code ) {
		return is_scalar( $code ) ? strtoupper( preg_replace( '/[^A-Z0-9_-]/', '', strtoupper( sanitize_text_field( (string) $code ) ) ) ) : '';
	}

	/** Tickets on the lines a campaign applies to. */
	private static function eligible_quantity( $campaign, array $selected ) {
		$quantity = 0;
		foreach ( array_keys( self::eligible( $campaign, $selected, array_map( static function ( $line ) { return (int) $line['line_total']; }, $selected ) ) ) as $index ) {
			$quantity += (int) $selected[ $index ]['quantity'];
		}
		return $quantity;
	}

	/**
	 * Minimum quantity and per-buyer limit of a campaign (null = fine). The buyer's earlier uses are read
	 * with a locking read after the campaign row lock, so parallel orders of the same buyer see each other.
	 */
	private static function rule_error( $campaign, array $selected, $email ) {
		global $wpdb;
		$min = (int) ( $campaign->min_quantity ?? 0 );
		if ( $min > 1 && self::eligible_quantity( $campaign, $selected ) < $min ) {
			/* translators: 1: campaign code, 2: minimum number of tickets */
			return new \WP_Error( 'wpcap_campaign_min_quantity', sprintf( __( 'Der Code %1$s gilt ab %2$d passenden Tickets.', 'pass-captain' ), $campaign->code, $min ), array( 'status' => 409 ) );
		}
		$limit = (int) ( $campaign->per_buyer_limit ?? 0 );
		if ( $limit > 0 ) {
			$used = '' === $email ? 0 : (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i u INNER JOIN %i o ON o.order_id=u.order_id WHERE u.campaign_id=%d AND u.status IN ('reserved','committed') AND o.customer_email=%s LOCK IN SHARE MODE", $wpdb->prefix . 'wpcap_campaign_uses', $wpdb->prefix . 'wpcap_orders', (int) $campaign->campaign_id, $email ) );
			if ( $used >= $limit ) {
				/* translators: %s: campaign code */
				return new \WP_Error( 'wpcap_campaign_buyer_limit', sprintf( __( 'Der Code %s wurde für diese E-Mail-Adresse bereits so oft wie erlaubt eingelöst.', 'pass-captain' ), $campaign->code ), array( 'status' => 409 ) );
			}
		}
		return null;
	}

	/**
	 * Active auto_apply campaigns matching the order, after the explicit codes (rows locked).
	 *
	 * @param object[] $explicit Campaigns from codes.
	 * @return object[]
	 */
	private static function auto_campaigns( array $explicit, array $selected, $currency, $email, $now ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcap_campaigns';
		foreach ( $explicit as $campaign ) {
			if ( ! (int) ( $campaign->combinable ?? 1 ) ) { return array(); }
		}
		$ids = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( "SELECT campaign_id FROM %i WHERE auto_apply=1 AND status='active' AND currency=%s AND (starts_at IS NULL OR starts_at<=%s) AND (ends_at IS NULL OR ends_at>=%s) AND (usage_limit=0 OR used_count<usage_limit) ORDER BY priority,campaign_id LIMIT 20", $table, strtoupper( (string) $currency ), $now, $now ) ) );
		if ( '' !== (string) $wpdb->last_error ) { return array(); } // Columns missing before the schema upgrade.
		$taken = array_map( static function ( $c ) { return (int) $c->campaign_id; }, $explicit );
		$out = array();
		foreach ( $ids as $id ) {
			if ( count( $out ) >= 3 || in_array( $id, $taken, true ) ) { continue; }
			$campaign = $wpdb->get_row( $wpdb->prepare( "SELECT *,discount_value AS value FROM %i WHERE campaign_id=%d AND status='active' FOR UPDATE", $table, $id ) );
			if ( ! $campaign || ( (int) $campaign->usage_limit > 0 && (int) $campaign->used_count >= (int) $campaign->usage_limit ) ) { continue; }
			if ( ! array_sum( self::eligible( $campaign, $selected, array_map( static function ( $line ) { return (int) $line['line_total']; }, $selected ) ) ) ) { continue; }
			if ( self::rule_error( $campaign, $selected, $email ) ) { continue; }
			if ( ! (int) ( $campaign->combinable ?? 1 ) && ( $explicit || $out ) ) { continue; }
			if ( $out && ! (int) ( $out[0]->combinable ?? 1 ) ) { break; }
			$campaign->auto_applied = true;
			$out[] = $campaign;
		}
		return $out;
	}

	/**
	 * Lines a campaign applies to (ticket type / event restriction, with_price_rules) with what is
	 * left of them: index => remaining amount.
	 */
	private static function eligible( $campaign, array $selected, array $remaining ) {
		$out = array();
		foreach ( $selected as $index => $line ) {
			$type_match = ! (int) $campaign->ticket_type_id || (int) $campaign->ticket_type_id === (int) $line['type']->ticket_type_id;
			$event_match = ! (int) $campaign->event_id || (int) $campaign->event_id === (int) $line['event_id'];
			$rule_ok = (int) ( $campaign->with_price_rules ?? 1 ) || empty( $line['price_rule'] );
			if ( $type_match && $event_match && $rule_ok ) { $out[ $index ] = max( 0, (int) $remaining[ $index ] ); }
		}
		return $out;
	}

	/**
	 * Discount per selected line (index => minor units) of calculate() adjustments.
	 *
	 * @param array $adjustments Result of calculate().
	 * @param int   $count       Number of selected lines.
	 * @return int[]
	 */
	public static function line_discounts( array $adjustments, $count ) {
		$out = array_fill( 0, max( 0, (int) $count ), 0 );
		foreach ( $adjustments as $adjustment ) {
			foreach ( (array) ( $adjustment['lines'] ?? array() ) as $index => $amount ) {
				$out[ (int) $index ] = ( $out[ (int) $index ] ?? 0 ) + (int) $amount;
			}
		}
		return $out;
	}

	/** Persist applied codes and atomically consume their limits in the caller transaction. */
	public static function redeem( array $adjustments, $order_id ) {
		global $wpdb;
		$campaigns = $wpdb->prefix . 'wpcap_campaigns';
		$uses = $wpdb->prefix . 'wpcap_campaign_uses';
		foreach ( $adjustments as $adjustment ) {
			$campaign = $adjustment['campaign'];
			$inserted = $wpdb->insert( $uses, array( 'campaign_id' => (int) $campaign->campaign_id, 'order_id' => (int) $order_id, 'code' => $campaign->code, 'discount_minor' => (int) $adjustment['amount_minor'], 'status' => 'reserved', 'created_at' => Clock::mysql() ), array( '%d', '%d', '%s', '%d', '%s', '%s' ) );
			if ( false === $inserted ) { return new \WP_Error( 'wpcap_campaign_use_failed', __( 'Kampagnennutzung konnte nicht gespeichert werden.', 'pass-captain' ) ); }
			$updated = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET used_count=used_count+1 WHERE campaign_id=%d AND (usage_limit=0 OR used_count<usage_limit)', $campaigns, $campaign->campaign_id ) );
			if ( 1 !== $updated ) { return new \WP_Error( 'wpcap_campaign_exhausted', __( 'Das Kampagnenlimit wurde während des Checkouts erreicht.', 'pass-captain' ) ); }
		}
		return true;
	}

	public static function commit_for_order( $order_id ) {
		global $wpdb;
		return $wpdb->update( $wpdb->prefix . 'wpcap_campaign_uses', array( 'status' => 'committed' ), array( 'order_id' => absint( $order_id ), 'status' => 'reserved' ), array( '%s' ), array( '%d', '%s' ) );
	}

	/**
	 * Late payment (A353, PAY-1): discount codes the order released when its hold lapsed are redeemed
	 * again in the caller's transaction – campaign rows locked FOR UPDATE by id, then the usage limit is
	 * checked atomically (a single-use code cannot be redeemed by any number of expired orders). Uses
	 * still `reserved` keep their unit. Call after the capacity locks (lock order of Orders::create():
	 * ticket types, occurrences, modules, campaigns).
	 *
	 * @return true|\WP_Error `campaign_exhausted` when a limit is used up meanwhile; Errors::busy() on a database error.
	 */
	public static function recover_for_order( $order_id ) {
		global $wpdb;
		$uses = $wpdb->prefix . 'wpcap_campaign_uses';
		$campaigns = $wpdb->prefix . 'wpcap_campaigns';
		$order_id = absint( $order_id );
		// Locking read: a concurrent release (expire_holds) waits for this transaction.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT campaign_id,status FROM $uses WHERE order_id=%d ORDER BY campaign_id FOR UPDATE", $order_id ) );
		if ( '' !== $wpdb->last_error ) { return Errors::busy(); }
		foreach ( (array) $rows as $row ) {
			if ( 'released' !== $row->status ) { continue; }
			$wpdb->get_var( $wpdb->prepare( "SELECT campaign_id FROM $campaigns WHERE campaign_id=%d FOR UPDATE", (int) $row->campaign_id ) );
			if ( '' !== $wpdb->last_error ) { return Errors::busy(); }
			$taken = $wpdb->query( $wpdb->prepare( "UPDATE $campaigns SET used_count=used_count+1 WHERE campaign_id=%d AND (usage_limit=0 OR used_count<usage_limit)", (int) $row->campaign_id ) );
			if ( false === $taken ) { return Errors::busy(); }
			if ( 1 !== (int) $taken ) {
				return new \WP_Error( 'campaign_exhausted', __( 'Das Nutzungslimit des Rabattcodes ist ausgeschöpft.', 'pass-captain' ), array( 'status' => 409, 'campaign_id' => (int) $row->campaign_id ) );
			}
			$back = $wpdb->query( $wpdb->prepare( "UPDATE $uses SET status='reserved' WHERE campaign_id=%d AND order_id=%d AND status='released'", (int) $row->campaign_id, $order_id ) );
			if ( 1 !== (int) $back ) { return Errors::busy(); }
		}
		return true;
	}

	/**
	 * Legacy name of recover_for_order(); a used-up limit is reported as `wpcap_campaign_exhausted`.
	 *
	 * @return true|\WP_Error
	 */
	public static function retake_for_order( $order_id ) {
		$result = self::recover_for_order( $order_id );
		if ( is_wp_error( $result ) && 'campaign_exhausted' === $result->get_error_code() ) {
			return new \WP_Error( 'wpcap_campaign_exhausted', __( 'Das Kampagnenlimit wurde während des Checkouts erreicht.', 'pass-captain' ), (array) $result->get_error_data() );
		}
		return $result;
	}

	public static function release_for_order( $order_id ) {
		global $wpdb;
		$uses = $wpdb->prefix . 'wpcap_campaign_uses';
		$campaigns = $wpdb->prefix . 'wpcap_campaigns';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT campaign_id FROM %i WHERE order_id=%d AND status='reserved'", $uses, absint( $order_id ) ) );
		foreach ( $rows as $row ) {
			$released = $wpdb->update( $uses, array( 'status' => 'released' ), array( 'campaign_id' => (int) $row->campaign_id, 'order_id' => absint( $order_id ), 'status' => 'reserved' ), array( '%s' ), array( '%d', '%d', '%s' ) );
			if ( 1 === $released ) { $wpdb->query( $wpdb->prepare( 'UPDATE %i SET used_count=IF(used_count>0,used_count-1,0) WHERE campaign_id=%d', $campaigns, $row->campaign_id ) ); }
		}
		return true;
	}

	/**
	 * A616: one validity bound of a code given through the API, as UTC "Y-m-d H:i:s" (null = open).
	 * Accepted: ISO 8601 with offset or Z (converted to UTC), a local date and time "Y-m-d H:i[:s]"
	 * (also with "T") in $timezone, or a date "Y-m-d" – the start of that day for starts_at, its end
	 * (23:59:59 local) for ends_at. Anything else is an error, never a silent "unlimited".
	 *
	 * @param mixed  $value    Raw value.
	 * @param bool   $end      true for ends_at.
	 * @param string $timezone Time zone of local values (site time zone).
	 * @return string|null|\WP_Error
	 */
	public static function window_bound( $value, $end, $timezone ) {
		$invalid = new \WP_Error( 'wpcap_campaign_dates', __( 'Gültig ab/bis ist kein gültiger Zeitpunkt (JJJJ-MM-TT, JJJJ-MM-TT HH:MM in Ortszeit oder ISO 8601 mit Zeitzone).', 'pass-captain' ), array( 'status' => 400, 'field' => $end ? 'ends_at' : 'starts_at' ) );
		if ( null !== $value && ! is_string( $value ) ) { return $invalid; }
		$value = trim( (string) $value );
		if ( '' === $value ) { return null; }
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) { $value .= $end ? ' 23:59:59' : ' 00:00:00'; }
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}(?::\d{2})?)(?:\.\d+)?(Z|[+-]\d{2}:?\d{2})$/i', $value, $m ) ) {
			$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i' . ( 8 === strlen( $m[2] ) ? ':s' : '' ) . 'P', $m[1] . ' ' . $m[2] . ( 'Z' === strtoupper( $m[3] ) ? '+00:00' : substr( $m[3], 0, 3 ) . ':' . substr( $m[3], -2 ) ) );
			$errors = \DateTimeImmutable::getLastErrors();
			if ( ! $date || ( is_array( $errors ) && ( $errors['warning_count'] || $errors['error_count'] ) ) ) { return $invalid; }
			return $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		}
		if ( Admin\Forms::is_valid_timezone( $timezone ) ) {
			$utc = Admin\Forms::local_to_utc( $value, $timezone );
			return null === $utc ? $invalid : $utc;
		}
		// Site with a fixed UTC offset (wp_timezone_string() returns "+01:00"): no DST to consider.
		$local = str_replace( 'T', ' ', $value );
		try {
			$zone = new \DateTimeZone( '' !== (string) $timezone ? (string) $timezone : 'UTC' );
		} catch ( \Exception $e ) {
			$zone = new \DateTimeZone( 'UTC' );
		}
		$date = preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $local, $m ) ? \DateTimeImmutable::createFromFormat( '!Y-m-d H:i' . ( isset( $m[1] ) ? ':s' : '' ), $local, $zone ) : false;
		$errors = \DateTimeImmutable::getLastErrors();
		if ( ! $date || ( is_array( $errors ) && ( $errors['warning_count'] || $errors['error_count'] ) ) ) { return $invalid; }
		return $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/** Failed code choices (code taken) per person and hour before 429 (A650; filter `wpcap_campaign_code_conflict_limit`). */
	const CODE_CONFLICT_LIMIT = 10;

	/**
	 * A650: neutral refusal of a code that cannot be used (taken – possibly by another organiser),
	 * limited per person and hour; administrators (all codes visible anyway) are not limited.
	 */
	private static function code_unavailable( $code ) {
		$user = (int) get_current_user_id();
		if ( ! current_user_can( 'manage_options' ) ) {
			$limit = max( 1, (int) apply_filters( 'wpcap_campaign_code_conflict_limit', self::CODE_CONFLICT_LIMIT ) );
			if ( ! RateLimit::allow( 'campaign-code-conflict|' . $user, $limit, HOUR_IN_SECONDS ) ) {
				return new \WP_Error( 'wpcap_rate_limited', __( 'Zu viele Versuche. Bitte versuche es später erneut.', 'pass-captain' ), array( 'status' => 429 ) );
			}
			Audit::record( 'campaign_code_conflict', 'user', $user, array( 'code_hash' => substr( hash( 'sha256', (string) $code ), 0, 16 ) ) );
		}
		return new \WP_Error( 'wpcap_campaign_code_unavailable', __( 'Dieser Code kann nicht verwendet werden. Bitte wähle einen anderen Code.', 'pass-captain' ), array( 'status' => 400, 'field' => 'code' ) );
	}

	/**
	 * Creates a code. starts_at/ends_at are UTC "Y-m-d H:i:s" (or empty for open); callers convert
	 * local input first (admin form: Forms::local_to_utc(), REST: window_bound(), A616).
	 */
	public static function create( array $data ) {
		global $wpdb;
		$code = strtoupper( preg_replace( '/[^A-Z0-9_-]/', '', strtoupper( sanitize_text_field( $data['code'] ?? '' ) ) ) );
		$kind = sanitize_key( $data['kind'] ?? '' );
		$value = absint( $data['value'] ?? 0 );
		$currency = strtoupper( sanitize_text_field( $data['currency'] ?? Localization::default_currency() ) ); // SET-PC-08
		if ( strlen( $code ) < 3 || strlen( $code ) > 32 || ! in_array( $kind, array( 'percent', 'fixed' ), true ) || ! preg_match( '/^[A-Z]{3}$/', $currency ) || ! $value || ( 'percent' === $kind && $value > 10000 ) ) {
			return new \WP_Error( 'wpcap_campaign_invalid', __( 'Kampagnencode, Währung oder Rabattwert ist ungültig.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		// A616: starts_at/ends_at are UTC "Y-m-d H:i:s" here (callers convert local input first).
		$window = array();
		foreach ( array( 'starts_at', 'ends_at' ) as $bound ) {
			$raw = trim( Http::str( $data[ $bound ] ?? '' ) );
			$parsed = '' === $raw ? false : \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $raw, new \DateTimeZone( 'UTC' ) );
			if ( '' !== $raw && ( ! $parsed || $parsed->format( 'Y-m-d H:i:s' ) !== $raw ) ) {
				return new \WP_Error( 'wpcap_campaign_dates', __( 'Gültig ab/bis ist kein gültiger Zeitpunkt (JJJJ-MM-TT, JJJJ-MM-TT HH:MM in Ortszeit oder ISO 8601 mit Zeitzone).', 'pass-captain' ), array( 'status' => 400, 'field' => $bound ) );
			}
			$window[ $bound ] = '' === $raw ? null : $raw;
		}
		if ( $window['starts_at'] && $window['ends_at'] && $window['ends_at'] <= $window['starts_at'] ) {
			return new \WP_Error( 'wpcap_campaign_window', __( 'Das Ende des Gültigkeitszeitraums muss nach dem Beginn liegen.', 'pass-captain' ), array( 'status' => 400, 'field' => 'ends_at' ) );
		}
		// A650: codes are unique across all events, so "taken" tells a scoped organiser that a code of
		// another organiser exists. The answer is neutral, failed attempts are limited per person and
		// logged, so codes of foreign events cannot be found by trying.
		$taken = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT campaign_id FROM %i WHERE code=%s', $wpdb->prefix . 'wpcap_campaigns', $code ) );
		if ( $taken ) { return self::code_unavailable( $code ); }
		$inserted = $wpdb->insert( $wpdb->prefix . 'wpcap_campaigns', array(
			'event_id' => absint( $data['event_id'] ?? 0 ), 'ticket_type_id' => absint( $data['ticket_type_id'] ?? 0 ), 'code' => $code,
			'name' => sanitize_text_field( $data['name'] ?? $code ), 'kind' => $kind, 'discount_value' => $value, 'currency' => $currency,
			'usage_limit' => absint( $data['usage_limit'] ?? 0 ), 'used_count' => 0, 'priority' => min( 1000, absint( $data['priority'] ?? 100 ) ),
			'starts_at' => $window['starts_at'], 'ends_at' => $window['ends_at'],
			'status' => 'active', 'created_at' => Clock::mysql(),
		) + self::stacking_values( $data ) );
		if ( false === $inserted ) {
			// A parallel request took the code (unique index): the same neutral answer.
			if ( false !== stripos( (string) $wpdb->last_error, 'duplicate' ) ) { return self::code_unavailable( $code ); }
			return new \WP_Error( 'wpcap_campaign_save', __( 'Kampagne konnte nicht angelegt werden.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$campaign_id = (int) $wpdb->insert_id; // Before the audit insert changes insert_id.
		self::flush();
		Audit::record( 'campaign_created', 'campaign', $campaign_id, array( 'code' => $code, 'kind' => $kind, 'stacking' => self::is_exclusive( $data ) ? 'exclusive' : 'combinable' ) );
		return array( 'campaign_id' => $campaign_id, 'code' => $code );
	}


	/**
	 * Validity window from input: starts_at/ends_at as "Y-m-d H:i:s" (UTC) or ISO 8601 with offset
	 * (see DateInput); the admin form converts its local times to UTC before. Keys missing in $data
	 * fall back to $campaign (update) or null.
	 *
	 * @return array|\WP_Error array( 'starts_at' => ?string, 'ends_at' => ?string ) in UTC.
	 */
	private static function window( array $data, $campaign = null ) {
		$parsed = DateInput::fields( $data, array( 'starts_at', 'ends_at' ) );
		if ( is_wp_error( $parsed ) ) { return $parsed; }
		$window = array(
			'starts_at' => array_key_exists( 'starts_at', $parsed ) ? $parsed['starts_at'] : ( $campaign && $campaign->starts_at ? (string) $campaign->starts_at : null ),
			'ends_at' => array_key_exists( 'ends_at', $parsed ) ? $parsed['ends_at'] : ( $campaign && $campaign->ends_at ? (string) $campaign->ends_at : null ),
		);
		if ( $window['starts_at'] && $window['ends_at'] && $window['ends_at'] <= $window['starts_at'] ) {
			return new \WP_Error( 'wpcap_campaign_window', __( 'Das Ende des Gültigkeitszeitraums muss nach dem Beginn liegen.', 'pass-captain' ), array( 'status' => 400, 'field' => 'ends_at' ) );
		}
		return $window;
	}

	/**
	 * Stacking columns from input (only keys that are given, so writes keep working before the
	 * schema upgrade): combinable / stacking, with_price_rules, auto_apply (0/1), max_discount_minor,
	 * min_quantity, per_buyer_limit.
	 */
	private static function stacking_values( array $data, $campaign = null ) {
		$out = array();
		foreach ( array( 'combinable', 'with_price_rules', 'auto_apply' ) as $key ) {
			if ( array_key_exists( $key, $data ) ) { $out[ $key ] = empty( $data[ $key ] ) ? 0 : 1; }
		}
		// Two names of one rule (A117 `stacking` exclusive|combinable, W8 `combinable` 0|1): both columns are written.
		if ( array_key_exists( 'stacking', $data ) || array_key_exists( 'combinable', $data ) || null === $campaign ) {
			$exclusive = self::is_exclusive( $data );
			$out['stacking'] = $exclusive ? 'exclusive' : 'combinable';
			$out['combinable'] = $exclusive ? 0 : 1;
		}
		if ( array_key_exists( 'max_discount_minor', $data ) ) { $out['max_discount_minor'] = absint( $data['max_discount_minor'] ); }
		if ( array_key_exists( 'min_quantity', $data ) ) { $out['min_quantity'] = min( 1000, absint( $data['min_quantity'] ) ); }
		if ( array_key_exists( 'per_buyer_limit', $data ) ) { $out['per_buyer_limit'] = min( 1000, absint( $data['per_buyer_limit'] ) ); }
		return $out;
	}

	/** Whether input names an exclusive code: stacking "exclusive" or combinable = 0. */
	private static function is_exclusive( array $data ) {
		if ( array_key_exists( 'stacking', $data ) ) { return 'exclusive' === (string) $data['stacking']; }
		return array_key_exists( 'combinable', $data ) && empty( $data['combinable'] );
	}

	/** Characters of generated codes (no 0/O, 1/I/L). */
	const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
	/** Codes per generator run. */
	const MAX_BATCH = 5000;

	/**
	 * Generates single-use codes for a campaign (code batch): every code is an own campaign row with the
	 * settings of the template campaign, usage limit 1, no auto apply and batch_id = template id, so the
	 * checkout, limits and reports treat it like any code.
	 *
	 * @return array|\WP_Error {created, codes[]}
	 */
	public static function generate_codes( $campaign_id, $count, $prefix = '' ) {
		global $wpdb;
		$template = self::get( $campaign_id );
		if ( ! $template ) { return new \WP_Error( 'wpcap_campaign_missing', __( 'Kampagne nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
		if ( (int) ( $template->batch_id ?? 0 ) ) { return new \WP_Error( 'wpcap_campaign_batch', __( 'Einzelcodes können nur aus der Vorlagen-Kampagne erzeugt werden.', 'pass-captain' ), array( 'status' => 400 ) ); }
		$count = absint( $count );
		if ( $count < 1 || $count > self::MAX_BATCH ) {
			/* translators: %d: maximum number */
			return new \WP_Error( 'wpcap_campaign_batch_count', sprintf( __( 'Bitte zwischen 1 und %d Codes erzeugen.', 'pass-captain' ), self::MAX_BATCH ), array( 'status' => 400 ) );
		}
		$prefix = substr( self::normalize_code( $prefix ), 0, 12 );
		$prefix = '' === $prefix ? '' : rtrim( $prefix, '-' ) . '-';
		$table = $wpdb->prefix . 'wpcap_campaigns';
		$now = Clock::mysql();
		$columns = array( 'event_id', 'ticket_type_id', 'code', 'name', 'kind', 'discount_value', 'currency', 'usage_limit', 'used_count', 'priority', 'starts_at', 'ends_at', 'status', 'combinable', 'with_price_rules', 'max_discount_minor', 'min_quantity', 'auto_apply', 'per_buyer_limit', 'batch_id', 'created_at' );
		$codes = array();
		$attempts = 0;
		$have = 0;
		while ( $have < $count && $attempts < 20 ) {
			++$attempts;
			$chunk = array();
			for ( $i = 0, $want = min( 500, $count - $have ); $i < $want; $i++ ) {
				$random = '';
				for ( $j = 0; $j < 10; $j++ ) { $random .= self::CODE_ALPHABET[ random_int( 0, strlen( self::CODE_ALPHABET ) - 1 ) ]; }
				$chunk[ $prefix . $random ] = true;
			}
			$chunk = array_keys( $chunk );
			$values = array();
			$args = array( $table );
			foreach ( $chunk as $code ) {
				$values[] = '(%d,%d,%s,%s,%s,%d,%s,1,0,%d,' . ( $template->starts_at ? '%s' : 'NULL' ) . ',' . ( $template->ends_at ? '%s' : 'NULL' ) . ",'active',%d,%d,%d,%d,0,%d,%d,%s)";
				array_push( $args, (int) $template->event_id, (int) $template->ticket_type_id, $code, (string) $template->name, (string) $template->kind, (int) $template->discount_value, (string) $template->currency, (int) $template->priority );
				if ( $template->starts_at ) { $args[] = (string) $template->starts_at; }
				if ( $template->ends_at ) { $args[] = (string) $template->ends_at; }
				array_push( $args, (int) $template->combinable, (int) $template->with_price_rules, (int) $template->max_discount_minor, (int) ( $template->min_quantity ?? 0 ), (int) ( $template->per_buyer_limit ?? 0 ), (int) $template->campaign_id, $now );
			}
			// INSERT IGNORE: a (rare) collision with an existing code is skipped and generated again.
			$sql = 'INSERT IGNORE INTO %i (' . implode( ',', $columns ) . ') VALUES ' . implode( ',', $values );
			if ( false === $wpdb->query( $wpdb->prepare( $sql, $args ) ) ) { break; } // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- generated placeholders.
			$in = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$stored = $wpdb->get_col( $wpdb->prepare( "SELECT code FROM %i WHERE batch_id=%d AND code IN ($in)", array_merge( array( $table, (int) $template->campaign_id ), $chunk ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- generated placeholders, replacements as one array.
			$codes = array_merge( $codes, array_diff( (array) $stored, $codes ) );
			$have = count( $codes );
		}
		Audit::record( 'campaign_codes_generated', 'campaign', (int) $template->campaign_id, array( 'count' => count( $codes ) ) );
		return array( 'created' => count( $codes ), 'codes' => $codes );
	}

	/** Rows (header + code, status, used) of the generated codes of a template campaign for Reports\Scope::send_csv(). */
	public static function batch_rows( $campaign_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT code,status,used_count,usage_limit FROM %i WHERE batch_id=%d ORDER BY campaign_id', $wpdb->prefix . 'wpcap_campaigns', absint( $campaign_id ) ) );
		$out = array( array( 'code', 'status', 'used' ) );
		foreach ( (array) $rows as $row ) {
			$out[] = array( (string) $row->code, (string) $row->status, (int) $row->used_count >= max( 1, (int) $row->usage_limit ) ? 'yes' : 'no' );
		}
		return $out;
	}

	/**
	 * Updates a campaign. Code, kind and currency stay fixed once the code was used; the usage
	 * limit cannot drop below the uses so far (0 = unlimited).
	 *
	 * @return array|\WP_Error
	 */
	public static function update( $campaign_id, array $data ) {
		global $wpdb;
		$campaign = self::get( $campaign_id );
		if ( ! $campaign ) { return new \WP_Error( 'wpcap_campaign_missing', __( 'Kampagne nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
		$used = (int) $campaign->used_count > 0 || (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE campaign_id=%d', $wpdb->prefix . 'wpcap_campaign_uses', (int) $campaign->campaign_id ) ) > 0;
		$code = strtoupper( preg_replace( '/[^A-Z0-9_-]/', '', strtoupper( sanitize_text_field( $data['code'] ?? $campaign->code ) ) ) );
		$kind = sanitize_key( $data['kind'] ?? $campaign->kind );
		$value = absint( $data['value'] ?? $campaign->discount_value );
		$currency = strtoupper( sanitize_text_field( $data['currency'] ?? $campaign->currency ) );
		if ( strlen( $code ) < 3 || strlen( $code ) > 32 || ! in_array( $kind, array( 'percent', 'fixed' ), true ) || ! preg_match( '/^[A-Z]{3}$/', $currency ) || ! $value || ( 'percent' === $kind && $value > 10000 ) ) {
			return new \WP_Error( 'wpcap_campaign_invalid', __( 'Kampagnencode, Währung oder Rabattwert ist ungültig.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		if ( $used && ( $code !== $campaign->code || $kind !== $campaign->kind || $currency !== strtoupper( $campaign->currency ) ) ) {
			return new \WP_Error( 'wpcap_campaign_used', __( 'Code, Rabattart und Währung können nach der ersten Einlösung nicht mehr geändert werden.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$limit = absint( $data['usage_limit'] ?? $campaign->usage_limit );
		if ( $limit > 0 && $limit < (int) $campaign->used_count ) {
			return new \WP_Error( 'wpcap_campaign_limit_low', __( 'Das Nutzungslimit darf nicht unter der Zahl der bisherigen Einlösungen liegen.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$window = self::window( $data, $campaign );
		if ( is_wp_error( $window ) ) { return $window; }
		$starts = $window['starts_at'];
		$ends = $window['ends_at'];
		$status = in_array( (string) ( $data['status'] ?? $campaign->status ), array( 'active', 'inactive' ), true ) ? (string) ( $data['status'] ?? $campaign->status ) : $campaign->status;
		$values = array(
			'event_id' => absint( $data['event_id'] ?? $campaign->event_id ), 'ticket_type_id' => absint( $data['ticket_type_id'] ?? $campaign->ticket_type_id ), 'code' => $code,
			'name' => sanitize_text_field( $data['name'] ?? $campaign->name ) ?: $code, 'kind' => $kind, 'discount_value' => $value, 'currency' => $currency,
			'usage_limit' => $limit, 'priority' => min( 1000, absint( $data['priority'] ?? $campaign->priority ) ), 'starts_at' => $starts, 'ends_at' => $ends, 'status' => $status,
		) + self::stacking_values( $data, $campaign );
		$updated = $wpdb->update( $wpdb->prefix . 'wpcap_campaigns', $values, array( 'campaign_id' => (int) $campaign->campaign_id ) );
		if ( false === $updated ) { return new \WP_Error( 'wpcap_campaign_save', __( 'Kampagne konnte nicht gespeichert werden (Code bereits vergeben?).', 'pass-captain' ), array( 'status' => 409 ) ); }
		$changed = array();
		foreach ( $values as $key => $new ) {
			if ( (string) $new !== (string) $campaign->$key ) { $changed[ $key ] = $new; }
		}
		self::flush();
		Audit::record( 'campaign_updated', 'campaign', (int) $campaign->campaign_id, array( 'changed' => $changed ) );
		return array( 'campaign_id' => (int) $campaign->campaign_id, 'code' => $code );
	}

	/* ------------------------------------------------------------------ Administration */

	/** Per-request cache: currency => event ids with an active code (0 = all events). */
	private static $active = array();

	/**
	 * Whether an active, usable code exists for the event (or for all events) in the currency.
	 * One query per currency and request (event listings call it per card).
	 */
	public static function has_active( $event_id, $currency ) {
		global $wpdb;
		$currency = strtoupper( (string) $currency );
		if ( ! isset( self::$active[ $currency ] ) ) {
			$now = Clock::mysql();
			self::$active[ $currency ] = array_flip( array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT event_id FROM {$wpdb->prefix}wpcap_campaigns WHERE status='active' AND currency=%s AND (starts_at IS NULL OR starts_at<=%s) AND (ends_at IS NULL OR ends_at>=%s) AND (usage_limit=0 OR used_count<usage_limit)",
				$currency,
				$now,
				$now
			) ) ) );
		}
		return isset( self::$active[ $currency ][0] ) || isset( self::$active[ $currency ][ absint( $event_id ) ] );
	}

	/** Clears the has_active() cache (after changes; tests). */
	public static function flush() {
		self::$active = array();
	}

	/** One campaign row. */
	public static function get( $campaign_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_campaigns WHERE campaign_id=%d", absint( $campaign_id ) ) );
	}

	/**
	 * Campaigns with usage statistics (committed orders and discount), newest first.
	 *
	 * @param int[]|null $event_ids Scope (null = all; campaigns for all events only then).
	 */
	public static function all( $event_ids = null, $limit = 200 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$scope = null === $event_ids ? '' : ( $event_ids ? ' AND c.event_id IN (' . implode( ',', array_map( 'absint', $event_ids ) ) . ')' : ' AND 1=0' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT c.*,e.name AS event_name,tt.name AS ticket_name,(SELECT COUNT(*) FROM {$p}wpcap_campaign_uses u WHERE u.campaign_id=c.campaign_id AND u.status='committed') AS orders_committed,(SELECT COALESCE(SUM(u.discount_minor),0) FROM {$p}wpcap_campaign_uses u WHERE u.campaign_id=c.campaign_id AND u.status='committed') AS discount_committed FROM {$p}wpcap_campaigns c LEFT JOIN {$p}wpcap_events e ON e.event_id=c.event_id LEFT JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=c.ticket_type_id WHERE 1=1{$scope} ORDER BY c.campaign_id DESC LIMIT %d", max( 1, (int) $limit ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
	}

	/** Activates or deactivates a code (orders keep their discount). @return true|\WP_Error */
	public static function set_status( $campaign_id, $status ) {
		global $wpdb;
		if ( ! in_array( $status, array( 'active', 'inactive' ), true ) ) { return new \WP_Error( 'wpcap_campaign_status', __( 'Ungültiger Status.', 'pass-captain' ), array( 'status' => 400 ) ); }
		if ( ! self::get( $campaign_id ) ) { return new \WP_Error( 'wpcap_campaign_missing', __( 'Kampagne nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
		$updated = $wpdb->update( $wpdb->prefix . 'wpcap_campaigns', array( 'status' => $status ), array( 'campaign_id' => absint( $campaign_id ) ), array( '%s' ), array( '%d' ) );
		if ( false === $updated ) { return new \WP_Error( 'wpcap_campaign_save', __( 'Kampagne konnte nicht gespeichert werden.', 'pass-captain' ), array( 'status' => 500 ) ); }
		self::flush();
		Audit::record( 'campaign_status', 'campaign', absint( $campaign_id ), array( 'status' => $status ) );
		Audit::record( 'active' === $status ? 'campaign_activated' : 'campaign_deactivated', 'campaign', absint( $campaign_id ), array() );
		return array( 'campaign_id' => absint( $campaign_id ), 'status' => $status );
	}

	/**
	 * Non-binding discount preview for the checkout (no locks, nothing reserved). The checkout
	 * recalculates and may still reject the code (e.g. limit reached meanwhile).
	 *
	 * @param array  $items ticket_type_id, quantity.
	 * @param string $code  Discount code.
	 * @return array|\WP_Error subtotal_minor, discount_minor, total_minor, currency.
	 */
	public static function preview( array $items, $code ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$selected = array();
		$currency = '';
		$subtotal = 0;
		foreach ( array_slice( $items, 0, 20 ) as $item ) {
			$type_id = absint( is_array( $item ) ? ( $item['ticket_type_id'] ?? 0 ) : 0 );
			$qty = min( 100, Http::positive_int( is_array( $item ) ? ( $item['quantity'] ?? 0 ) : 0 ) ); // A421: -2 is no quantity.
			if ( ! $type_id || ! $qty ) { continue; }
			$type = $wpdb->get_row( $wpdb->prepare( "SELECT tt.*,o.event_id FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE tt.ticket_type_id=%d AND tt.status='active' AND e.status='published'", $type_id ) );
			if ( ! $type ) { continue; }
			$occurrence = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_occurrences WHERE occurrence_id=%d", (int) $type->occurrence_id ) );
			$unit = Orders::unit_price( $type, $occurrence, $qty );
			$currency = $currency ?: strtoupper( (string) $type->currency );
			$line_total = $unit * $qty;
			$subtotal += $line_total;
			$selected[] = array( 'type' => $type, 'event_id' => (int) $type->event_id, 'quantity' => $qty, 'line_total' => $line_total );
		}
		if ( ! $selected ) { return new \WP_Error( 'wpcap_campaign_preview', __( 'Bitte wähle mindestens ein Ticket.', 'pass-captain' ), array( 'status' => 400 ) ); }
		$adjustments = self::calculate( $selected, array( (string) $code ), $currency, false );
		if ( is_wp_error( $adjustments ) ) {
			return new \WP_Error( 'wpcap_campaign_invalid', __( 'Der Rabattcode ist ungültig oder passt nicht zu den ausgewählten Tickets.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$discount = array_sum( array_map( static function ( $a ) { return (int) $a['amount_minor']; }, $adjustments ) );
		return array( 'subtotal_minor' => $subtotal, 'discount_minor' => $discount, 'total_minor' => max( 0, $subtotal - $discount ), 'currency' => $currency );
	}
}

<?php
/**
 * SMS costs: price per segment and destination country, cost per message, monthly budget.
 *
 * Every accepted message gets a cost in the configured currency (sms.currency): the price the
 * provider reported (send response or status callback) when it is in that currency (or the filter
 * wpcap_sms_convert_price converts it), otherwise segments × configured price for the destination
 * prefix (sms.prices, fallback sms.price_default). Without any price the cost stays empty.
 *
 * The monthly budget (sms.budget_monthly, calendar month in UTC) sums these costs. At
 * sms.budget_warn_percent a warning goes out once per month (audit log, admin e-mail,
 * action wpcap_sms_budget_warning); with sms.budget_hard_stop a message whose estimate would exceed
 * the budget is not sent but held (status "held") until an administrator releases it.
 */
namespace PassCaptain\Sms;

use PassCaptain\Audit;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Costs {
	/** Option remembering which budget notices were sent this month ("Y-m|warn,stop"). */
	const NOTICE_OPTION = 'wpcap_sms_budget_notices';

	private static function settings() {
		return (array) Settings::get( 'sms' );
	}

	/** Accounting currency (ISO 4217). */
	public static function currency() {
		$c = strtoupper( (string) ( self::settings()['currency'] ?? 'EUR' ) );
		return preg_match( '/^[A-Z]{3}$/', $c ) ? $c : 'EUR';
	}

	/** Parses a decimal amount ("0,075", "0.075", "-0.0075") to a non-negative float or null. */
	public static function parse_amount( $raw ) {
		if ( null === $raw || is_array( $raw ) || is_object( $raw ) ) { return null; }
		$raw = str_replace( ',', '.', trim( (string) $raw ) );
		if ( '' === $raw || ! is_numeric( $raw ) ) { return null; }
		return abs( (float) $raw );
	}

	/** Formats an amount for the decimal(12,5) columns. */
	public static function format( $amount ) {
		return number_format( (float) $amount, 5, '.', '' );
	}

	/**
	 * Configured prices per segment: prefix => price, longest prefix first.
	 * Setting sms.prices: one "+49 0.075" (or "+49=0,075") per line.
	 */
	public static function prices() {
		$out = array();
		foreach ( preg_split( '/[\r\n;]+/', (string) ( self::settings()['prices'] ?? '' ) ) as $line ) {
			if ( preg_match( '/^\s*(\+[1-9]\d{0,5})\s*[=:\s]\s*([0-9]+(?:[.,][0-9]+)?)\s*$/', $line, $m ) ) {
				$out[ $m[1] ] = (float) str_replace( ',', '.', $m[2] );
			}
		}
		uksort( $out, static function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } );
		return $out;
	}

	/** Sanitises the price table input (invalid lines are dropped). */
	public static function sanitize_prices( $raw ) {
		$lines = array();
		foreach ( preg_split( '/[\r\n;]+/', (string) $raw ) as $line ) {
			if ( preg_match( '/^\s*(\+[1-9]\d{0,5})\s*[=:\s]\s*([0-9]+(?:[.,][0-9]+)?)\s*$/', $line, $m ) ) {
				$lines[ $m[1] ] = $m[1] . ' ' . str_replace( ',', '.', $m[2] );
			}
		}
		return implode( "\n", $lines );
	}

	/** Destination country prefix of a number (price table first, then allowed prefixes). */
	public static function country_of( $e164 ) {
		$e164 = (string) $e164;
		$prefixes = array_merge( array_keys( self::prices() ), Service::allowed_prefixes() );
		usort( $prefixes, static function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } );
		foreach ( $prefixes as $prefix ) {
			if ( 0 === strpos( $e164, $prefix ) ) { return $prefix; }
		}
		return '';
	}

	/** Configured price per segment for a number, or null when none applies. */
	public static function price_per_segment( $e164 ) {
		foreach ( self::prices() as $prefix => $price ) {
			if ( 0 === strpos( (string) $e164, (string) $prefix ) ) { return $price; }
		}
		return self::parse_amount( self::settings()['price_default'] ?? '' );
	}

	/** Estimated cost (configured prices) or null when no price applies. */
	public static function estimate( $e164, $segments ) {
		$price = self::price_per_segment( $e164 );
		return null === $price ? null : round( $price * max( 1, (int) $segments ), 5 );
	}

	/**
	 * Provider-reported price in the accounting currency, or null (no price, or another currency
	 * the filter wpcap_sms_convert_price does not convert).
	 */
	public static function provider_amount( $price, $currency = '' ) {
		$amount = self::parse_amount( $price );
		if ( null === $amount ) { return null; }
		$currency = strtoupper( (string) $currency );
		if ( '' === $currency || self::currency() === $currency ) { return $amount; }
		$converted = apply_filters( 'wpcap_sms_convert_price', null, $amount, $currency, self::currency() );
		return is_numeric( $converted ) ? abs( (float) $converted ) : null;
	}

	/* ------------------------------------------------------------------ Budget */

	/** Monthly budget in the accounting currency (0 = no limit). */
	public static function budget() {
		return (float) ( self::parse_amount( self::settings()['budget_monthly'] ?? '' ) ?? 0 );
	}

	public static function warn_percent() {
		$p = (int) ( self::settings()['budget_warn_percent'] ?? 80 );
		return max( 1, min( 100, $p ) );
	}

	public static function hard_stop() {
		return ! empty( self::settings()['budget_hard_stop'] ?? 1 );
	}

	/** First second of the UTC month of a timestamp, and of the following month. */
	private static function month_bounds( $month = '' ) {
		$start = preg_match( '/^\d{4}-\d{2}$/', (string) $month ) ? $month . '-01 00:00:00' : gmdate( 'Y-m-01 00:00:00' );
		$next = gmdate( 'Y-m-01 00:00:00', strtotime( $start . ' UTC +1 month' ) );
		return array( $start, $next );
	}

	/** Costs of a month (default: current UTC month) in the accounting currency. */
	public static function month_spent( $month = '' ) {
		global $wpdb;
		if ( ! Service::ready() ) { return 0.0; }
		list( $start, $next ) = self::month_bounds( $month );
		return (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(cost),0) FROM {$wpdb->prefix}wpcap_sms_messages WHERE sent_at>=%s AND sent_at<%s AND cost IS NOT NULL AND cost_currency=%s", $start, $next, self::currency() ) );
	}

	/**
	 * Whether a message with this estimate may be sent. Without a budget, without hard stop or
	 * without a known price the message is allowed.
	 */
	public static function allows( $estimate ) {
		$budget = self::budget();
		if ( $budget <= 0 || ! self::hard_stop() || null === $estimate ) { return true; }
		return self::month_spent() + (float) $estimate <= $budget + 0.000001;
	}

	/** Budget status of the current month. */
	public static function status() {
		$budget = self::budget();
		$spent = self::month_spent();
		$percent = $budget > 0 ? (int) floor( $spent / $budget * 100 ) : 0;
		return array(
			'month' => gmdate( 'Y-m' ),
			'currency' => self::currency(),
			'budget' => $budget,
			'spent' => $spent,
			'percent' => $percent,
			'warning' => $budget > 0 && $percent >= self::warn_percent(),
			'exhausted' => $budget > 0 && $spent >= $budget,
		);
	}

	/** Sends a budget notice once per month ('warn' or 'stop'). Returns true when it was sent now. */
	private static function notify_once( $level, array $status ) {
		$stored = (string) get_option( self::NOTICE_OPTION, '' );
		$parts = explode( '|', $stored, 2 );
		$sent = $status['month'] === $parts[0] && isset( $parts[1] ) ? explode( ',', $parts[1] ) : array();
		if ( in_array( $level, $sent, true ) ) { return false; }
		$sent[] = $level;
		update_option( self::NOTICE_OPTION, $status['month'] . '|' . implode( ',', $sent ), false );
		Audit::record( 'stop' === $level ? 'sms_budget_stop' : 'sms_budget_warning', 'sms', 0, array( 'month' => $status['month'], 'spent' => self::format( $status['spent'] ), 'budget' => self::format( $status['budget'] ), 'currency' => $status['currency'] ) );
		$amounts = sprintf( '%s / %s %s', number_format( $status['spent'], 2, ',', '.' ), number_format( $status['budget'], 2, ',', '.' ), $status['currency'] );
		if ( 'stop' === $level ) {
			$subject = __( 'Pass Captain: SMS-Budget ausgeschöpft', 'pass-captain' );
			/* translators: 1: month (YYYY-MM), 2: spent / budget with currency */
			$body = sprintf( __( 'Das SMS-Monatsbudget für %1$s ist ausgeschöpft (%2$s). Weitere SMS werden zurückgehalten, bis das Budget erhöht und die zurückgehaltenen SMS unter „SMS“ freigegeben werden.', 'pass-captain' ), $status['month'], $amounts );
		} else {
			$subject = __( 'Pass Captain: SMS-Budget fast erreicht', 'pass-captain' );
			/* translators: 1: month (YYYY-MM), 2: spent / budget with currency, 3: percent */
			$body = sprintf( __( 'Die SMS-Kosten für %1$s haben %3$d %% des Monatsbudgets erreicht (%2$s).', 'pass-captain' ), $status['month'], $amounts, $status['percent'] );
		}
		$to = get_option( 'admin_email' );
		if ( is_string( $to ) && '' !== $to && is_email( $to ) ) { wp_mail( $to, $subject, $body ); }
		do_action( 'wpcap_sms_budget_warning', $level, $status );
		return true;
	}

	/** After a cost was recorded: warning at the threshold (once per month). */
	public static function after_cost() {
		$status = self::status();
		if ( $status['warning'] ) { return self::notify_once( 'warn', $status ); }
		return false;
	}

	/** A message was held by the hard stop: notice once per month. */
	public static function stopped() {
		return self::notify_once( 'stop', self::status() );
	}

	/* ------------------------------------------------------------------ Report */

	/**
	 * Monthly report (newest first): month, currency, messages, segments, cost, cost reported by the
	 * provider, messages without price.
	 */
	public static function report( $months = 12 ) {
		global $wpdb;
		if ( ! Service::ready() ) { return array(); }
		$since = gmdate( 'Y-m-01 00:00:00', strtotime( gmdate( 'Y-m-01' ) . ' UTC -' . max( 0, (int) $months - 1 ) . ' months' ) );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE_FORMAT(sent_at,'%%Y-%%m') AS month, cost_currency AS currency, COUNT(*) AS messages, SUM(segments) AS segments, COALESCE(SUM(cost),0) AS cost, COALESCE(SUM(CASE WHEN cost_source='provider' THEN cost END),0) AS provider_cost, SUM(cost IS NULL) AS unpriced FROM {$wpdb->prefix}wpcap_sms_messages WHERE sent_at>=%s GROUP BY month, cost_currency ORDER BY month DESC, cost_currency",
			$since
		) );
	}

	/** Costs of a month by destination country and purpose. */
	public static function breakdown( $month = '' ) {
		global $wpdb;
		if ( ! Service::ready() ) { return array(); }
		list( $start, $next ) = self::month_bounds( $month );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT country, purpose, cost_currency AS currency, COUNT(*) AS messages, SUM(segments) AS segments, COALESCE(SUM(cost),0) AS cost FROM {$wpdb->prefix}wpcap_sms_messages WHERE sent_at>=%s AND sent_at<%s GROUP BY country, purpose, cost_currency ORDER BY cost DESC",
			$start,
			$next
		) );
	}
}

<?php
/** Customer-facing formatting helpers shared by the checkout, the order portal and mails. */
namespace PassCaptain\Frontend;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Format {
	/** Currency symbols for the common checkout currencies (others fall back to the ISO code). */
	const SYMBOLS = array( 'EUR' => '€', 'USD' => '$', 'GBP' => '£', 'CHF' => 'CHF', 'CZK' => 'Kč', 'PLN' => 'zł', 'HUF' => 'Ft', 'SEK' => 'kr', 'DKK' => 'kr', 'NOK' => 'kr' );

	public static function symbol( $currency ) {
		$currency = strtoupper( (string) $currency );
		$symbols = (array) apply_filters( 'wpcap_currency_symbols', self::SYMBOLS );
		return (string) ( $symbols[ $currency ] ?? $currency );
	}

	/** "1.234,50 €" ("1.500 ¥", "12,345 KWD"): Money::number() plus currency symbol (the checkout script formats the same way). */
	public static function money( $minor, $currency ) {
		return \PassCaptain\Money::number( $minor, $currency ) . ' ' . self::symbol( $currency );
	}

	/** Number format and symbols for assets/checkout.js, so prices look the same in PHP and JS. */
	public static function js_config() {
		global $wp_locale;
		$format = isset( $wp_locale->number_format ) && is_array( $wp_locale->number_format ) ? $wp_locale->number_format : array();
		return array(
			'decimal' => (string) ( $format['decimal_point'] ?? ',' ),
			'thousands' => (string) ( $format['thousands_sep'] ?? '.' ),
			'symbols' => (array) apply_filters( 'wpcap_currency_symbols', self::SYMBOLS ),
			// Minor unit decimals per offered currency (0 JPY, 2 EUR, 3 KWD).
			'decimals' => array_combine( \PassCaptain\Localization::currencies(), array_map( array( \PassCaptain\Money::class, 'decimals' ), \PassCaptain\Localization::currencies() ) ),
		);
	}

	/**
	 * Short human-readable order number ("3F2A-9C1E") derived from the public id. Used as payment
	 * reference; the admin order search accepts it (prefix match on the public id).
	 */
	public static function order_reference( $public_id ) {
		$hex = strtoupper( substr( preg_replace( '/[^a-f0-9]/i', '', (string) $public_id ), 0, 8 ) );
		return strlen( $hex ) < 8 ? $hex : substr( $hex, 0, 4 ) . '-' . substr( $hex, 4, 4 );
	}

	/** "m***@example.com" – enough to recognise the address without disclosing it. */
	public static function mask_email( $email ) {
		$email = (string) $email;
		$at = strrpos( $email, '@' );
		if ( false === $at || $at < 1 ) { return '***'; }
		return substr( $email, 0, 1 ) . '***' . substr( $email, $at );
	}

	/** Date and time in the site's configured formats (falls back to d.m.Y H:i). */
	public static function datetime_format() {
		$date = (string) get_option( 'date_format' );
		$time = (string) get_option( 'time_format' );
		return ( '' !== $date ? $date : 'd.m.Y' ) . ' ' . ( '' !== $time ? $time : 'H:i' );
	}

	/** Site timezone (WordPress setting), UTC when unavailable. */
	public static function site_timezone() {
		if ( function_exists( 'wp_timezone' ) ) { return wp_timezone(); }
		$name = (string) get_option( 'timezone_string' );
		try { return new \DateTimeZone( '' !== $name ? $name : 'UTC' ); } catch ( \Exception $e ) { return new \DateTimeZone( 'UTC' ); }
	}

	/**
	 * Event start in the event's timezone with the site's date/time format. The timezone name is only
	 * appended when the event timezone differs from the site timezone at that moment.
	 */
	public static function event_start( $starts_at_utc, $timezone ) {
		$ts = strtotime( $starts_at_utc . ' UTC' );
		if ( false === $ts ) { return (string) $starts_at_utc; }
		try { $zone = new \DateTimeZone( '' !== (string) $timezone ? (string) $timezone : 'UTC' ); } catch ( \Exception $e ) { $zone = new \DateTimeZone( 'UTC' ); }
		$text = wp_date( self::datetime_format(), $ts, $zone );
		$moment = new \DateTimeImmutable( '@' . $ts );
		$site = self::site_timezone();
		if ( $zone->getName() !== $site->getName() && $zone->getOffset( $moment ) !== $site->getOffset( $moment ) ) {
			$text .= ' (' . $zone->getName() . ')';
		}
		return $text;
	}

	/** UTC timestamp string to a local date/time in the site timezone. */
	public static function site_datetime( $utc ) {
		$ts = strtotime( $utc . ' UTC' );
		return false === $ts ? (string) $utc : wp_date( self::datetime_format(), $ts, self::site_timezone() );
	}
}

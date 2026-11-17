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

	/**
	 * Price of a ticket type for buyers (A409): "kostenlos" for 0, else money(). The checkout script
	 * shows the same (WPCAP_CHECKOUT.i18n.free).
	 */
	public static function price( $minor, $currency ) {
		return 0 === (int) $minor ? __( 'kostenlos', 'pass-captain' ) : self::money( $minor, $currency );
	}

	/** Whether every given ticket type is free (price_minor 0) – the card then says "Kostenlos anmelden" (A409). */
	public static function all_free( array $types ) {
		if ( ! $types ) { return false; }
		foreach ( $types as $type ) {
			if ( (int) $type->price_minor > 0 ) { return false; }
		}
		return true;
	}

	/** "1.234,50 €" ("1.500 ¥", "12,345 KWD"): Money::number() plus currency symbol (the checkout script formats the same way). */
	public static function money( $minor, $currency ) {
		return \PassCaptain\Money::number( $minor, $currency ) . ' ' . self::symbol( $currency );
	}

	/**
	 * Attributes for the <html> element of standalone pages (print views, receipts, ticket page,
	 * order portal, A320): lang="…" in BCP 47 form of the current locale (determine_locale(), i.e.
	 * the admin's user language in the back end, the site language in the front end) and dir="rtl"
	 * for right-to-left languages. Already escaped.
	 */
	public static function html_lang_attributes() {
		$locale = function_exists( 'determine_locale' ) ? (string) determine_locale() : '';
		$lang = str_replace( '_', '-', '' !== $locale ? $locale : 'de_DE' );
		$rtl = function_exists( 'is_rtl' ) && is_rtl();
		return 'lang="' . esc_attr( $lang ) . '"' . ( $rtl ? ' dir="rtl"' : '' );
	}

	/** Currency => minor unit decimals for the checkout script (Money::decimals()). */
	public static function currency_decimals() {
		$codes = array_merge( \PassCaptain\Localization::currencies(), \PassCaptain\Money::ZERO_DECIMALS, \PassCaptain\Money::THREE_DECIMALS );
		$codes = array_values( array_unique( array_map( 'strval', $codes ) ) );
		return array_combine( $codes, array_map( array( \PassCaptain\Money::class, 'decimals' ), $codes ) );
	}

	/** Number format and symbols for assets/checkout.js, so prices look the same in PHP and JS. */
	public static function js_config() {
		global $wp_locale;
		$format = isset( $wp_locale->number_format ) && is_array( $wp_locale->number_format ) ? $wp_locale->number_format : array();
		return array(
			'decimal' => (string) ( $format['decimal_point'] ?? ',' ),
			'thousands' => (string) ( $format['thousands_sep'] ?? '.' ),
			'symbols' => (array) apply_filters( 'wpcap_currency_symbols', self::SYMBOLS ),
			// Minor unit decimals (0 JPY, 2 EUR, 3 KWD) of every offered currency and every currency whose minor
			// unit is not 1/100, so a price in such a currency is formatted right even if it is not offered.
			'decimals' => self::currency_decimals(),
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

	/**
	 * A384: date format for mails, tickets and documents. The site's setting (Settings → General) in the
	 * site language; while a mail or document is built in another language (Mailer::with_locale() with
	 * the recipient's locale), the plugin catalogue's format for that language, because the site's
	 * format belongs to the site language ("j. F Y" reads wrong in an English mail).
	 */
	public static function date_format() {
		if ( self::foreign_locale() ) {
			/* translators: PHP date() format of dates in mails, tickets and documents of this language, e.g. "m/d/Y" or "j F Y" */
			$format = _x( 'd.m.Y', 'date format', 'pass-captain' );
		} else {
			$format = (string) get_option( 'date_format' );
		}
		/**
		 * Date format (PHP date()) of mails, tickets and documents (A384).
		 *
		 * @param string $format Format.
		 * @param string $locale Locale of the mail/document being built ('' = site language).
		 */
		$format = (string) apply_filters( 'wpcap_date_format', '' !== $format ? $format : 'd.m.Y', \PassCaptain\Mail\Mailer::active_locale() );
		return '' !== $format ? $format : 'd.m.Y';
	}

	/** A384: time format for mails, tickets and documents (see date_format()). */
	public static function time_format() {
		if ( self::foreign_locale() ) {
			/* translators: PHP date() format of times in mails, tickets and documents of this language, e.g. "g:i a" or "H:i" */
			$format = _x( 'H:i', 'time format', 'pass-captain' );
		} else {
			$format = (string) get_option( 'time_format' );
		}
		/**
		 * Time format (PHP date()) of mails, tickets and documents (A384).
		 *
		 * @param string $format Format.
		 * @param string $locale Locale of the mail/document being built ('' = site language).
		 */
		$format = (string) apply_filters( 'wpcap_time_format', '' !== $format ? $format : 'H:i', \PassCaptain\Mail\Mailer::active_locale() );
		return '' !== $format ? $format : 'H:i';
	}

	/** Date and time in the formats of date_format()/time_format() (falls back to d.m.Y H:i). */
	public static function datetime_format() {
		return self::date_format() . ' ' . self::time_format();
	}

	/** Whether a mail/document is being built in a language other than the site's (A384). */
	private static function foreign_locale() {
		$active = \PassCaptain\Mail\Mailer::active_locale();
		return '' !== $active && $active !== \PassCaptain\Mail\Mailer::site_locale();
	}

	/**
	 * A384: a UTC datetime ("Y-m-d H:i:s") in $timezone with the format of $kind ('datetime', 'date' or
	 * 'time'); month and weekday names in the active language (wp_date()). '' for an empty value.
	 *
	 * @param string|null              $utc      UTC datetime.
	 * @param string|\DateTimeZone|null $timezone Zone name or object (default: site time zone).
	 * @param string                   $kind     datetime|date|time.
	 */
	public static function local( $utc, $timezone = null, $kind = 'datetime' ) {
		if ( null === $utc || '' === (string) $utc ) { return ''; }
		$ts = strtotime( $utc . ' UTC' );
		if ( false === $ts ) { return (string) $utc; }
		if ( $timezone instanceof \DateTimeZone ) {
			$zone = $timezone;
		} elseif ( null === $timezone || '' === (string) $timezone ) {
			$zone = self::site_timezone();
		} else {
			try { $zone = new \DateTimeZone( (string) $timezone ); } catch ( \Exception $e ) { $zone = new \DateTimeZone( 'UTC' ); }
		}
		$format = 'date' === $kind ? self::date_format() : ( 'time' === $kind ? self::time_format() : self::datetime_format() );
		return wp_date( $format, $ts, $zone );
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

	/**
	 * A608: a deadline (payment due, offer or confirmation expiry) for mails and pages: date and time
	 * in the event's time zone with that zone named ("24.12.2026 20:00 (Europe/Vienna)"), so it reads
	 * the same way as the event date of the same mail. Without an event zone the site zone is used.
	 *
	 * @param string|int $utc      UTC datetime or Unix timestamp.
	 * @param string     $timezone Event time zone ('' = site time zone).
	 */
	public static function deadline( $utc, $timezone = '' ) {
		$ts = is_int( $utc ) ? $utc : strtotime( (string) $utc . ' UTC' );
		if ( false === $ts || '' === (string) $utc ) { return ''; }
		$zone = null;
		if ( '' !== (string) $timezone ) {
			try { $zone = new \DateTimeZone( (string) $timezone ); } catch ( \Exception $e ) { $zone = null; }
		}
		$zone = $zone ? $zone : self::site_timezone();
		$name = $zone->getName();
		// Offsets such as "+01:00" (site setting without a city) are shown as UTC offset.
		$label = preg_match( '/^[+-]\d{2}:\d{2}$/', $name ) ? 'UTC' . $name : $name;
		/* translators: 1: date and time, 2: time zone (e.g. Europe/Vienna) */
		return sprintf( __( '%1$s (%2$s)', 'pass-captain' ), wp_date( self::datetime_format(), (int) $ts, $zone ), $label );
	}

	/** UTC timestamp string to a local date/time in the site timezone. */
	public static function site_datetime( $utc ) {
		$ts = strtotime( $utc . ' UTC' );
		return false === $ts ? (string) $utc : wp_date( self::datetime_format(), $ts, self::site_timezone() );
	}
}

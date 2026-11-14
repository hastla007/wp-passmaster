<?php
/** Currency and locale helpers; tax and legal rules stay country-specific. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Localization {
	public static function currencies() {
		$currencies = apply_filters( 'wp_pass_captain_currencies', array( 'EUR', 'USD', 'CHF', 'GBP', 'CAD', 'AUD' ) );
		if ( ! is_array( $currencies ) ) { return array( 'EUR' ); }
		$currencies = array_unique( array_filter( array_map( static function ( $code ) { $code = strtoupper( sanitize_text_field( $code ) ); return preg_match( '/^[A-Z]{3}$/', $code ) ? $code : ''; }, $currencies ) ) );
		return $currencies ?: array( 'EUR' );
	}

	public static function current_locale() { return determine_locale(); }

	/** Native names of the locales the plugin ships translations for. */
	const LOCALE_NAMES = array(
		'de_DE' => 'Deutsch',
		'en_US' => 'English',
		'es_ES' => 'Español',
		'fr_FR' => 'Français',
		'it_IT' => 'Italiano',
		'nl_NL' => 'Nederlands',
	);

	/**
	 * Locales a buyer can choose (source language de_DE plus every shipped catalogue in languages/),
	 * locale => native name. Filter wpcap_buyer_locales.
	 *
	 * @return array<string,string>
	 */
	public static function supported_locales() {
		$locales = array( 'de_DE' => self::LOCALE_NAMES['de_DE'] );
		foreach ( (array) glob( WPCAP_PATH . 'languages/pass-captain-*.mo' ) as $file ) {
			if ( preg_match( '/pass-captain-([a-z]{2,3}_[A-Z]{2})\.mo$/', (string) $file, $m ) ) {
				$locales[ $m[1] ] = self::LOCALE_NAMES[ $m[1] ] ?? $m[1];
			}
		}
		$filtered = apply_filters( 'wpcap_buyer_locales', $locales );
		return is_array( $filtered ) && $filtered ? $filtered : $locales;
	}

	/** Supported locale or '' (accepts de_DE, de-DE, de). */
	public static function normalize_buyer_locale( $requested ) {
		$requested = is_scalar( $requested ) ? str_replace( '-', '_', trim( (string) $requested ) ) : '';
		if ( '' === $requested ) { return ''; }
		$supported = self::supported_locales();
		if ( isset( $supported[ $requested ] ) ) { return $requested; }
		foreach ( array_keys( $supported ) as $locale ) {
			if ( 0 === strcasecmp( substr( (string) $locale, 0, 2 ), substr( $requested, 0, 2 ) ) && 2 === strlen( $requested ) ) { return (string) $locale; }
		}
		return '';
	}

	/** Locale stored on a new order: the buyer's explicit choice when supported, else the request locale. */
	public static function order_locale( $requested = '' ) {
		$chosen = self::normalize_buyer_locale( $requested );
		if ( '' !== $chosen ) { return $chosen; }
		return function_exists( 'determine_locale' ) ? (string) determine_locale() : '';
	}
}

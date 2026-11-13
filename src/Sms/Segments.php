<?php
/**
 * SMS segment counting (GSM 03.38).
 *
 * A text that only uses the GSM-7 default alphabet and its extension table is sent as GSM-7:
 * 160 septets in a single SMS, 153 per part of a concatenated SMS (7 septets go to the UDH).
 * Extension characters (€ [ ] { } \ ^ ~ | and form feed) take two septets (escape + char) and are
 * never split across two parts. Any other character switches the whole message to UCS-2:
 * 70 UTF-16 code units in a single SMS, 67 per part; surrogate pairs (emoji) are not split.
 */
namespace PassCaptain\Sms;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Segments {
	const GSM_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
	const GSM_EXTENSION = "^{}\\[~]|€\f";

	const GSM_SINGLE = 160;
	const GSM_PART = 153;
	const UCS_SINGLE = 70;
	const UCS_PART = 67;

	/** @var array<string,int>|null Character => septets. */
	private static $gsm = null;

	private static function gsm_map() {
		if ( null === self::$gsm ) {
			self::$gsm = array();
			foreach ( mb_str_split( self::GSM_BASIC, 1, 'UTF-8' ) as $char ) { self::$gsm[ $char ] = 1; }
			foreach ( mb_str_split( self::GSM_EXTENSION, 1, 'UTF-8' ) as $char ) { self::$gsm[ $char ] = 2; }
		}
		return self::$gsm;
	}

	/** Whether the text can be sent as GSM-7. */
	public static function is_gsm( $text ) {
		$map = self::gsm_map();
		foreach ( mb_str_split( (string) $text, 1, 'UTF-8' ) as $char ) {
			if ( ! isset( $map[ $char ] ) ) { return false; }
		}
		return true;
	}

	/**
	 * Encoding and segment count of a message text.
	 *
	 * @return array{encoding:string,units:int,segments:int,per_segment:int}
	 *         encoding 'gsm7' or 'ucs2'; units = septets (GSM-7) or UTF-16 code units (UCS-2).
	 */
	public static function count( $text ) {
		$text = (string) $text;
		$chars = '' === $text ? array() : mb_str_split( $text, 1, 'UTF-8' );
		$gsm = self::is_gsm( $text );
		$map = self::gsm_map();
		$weights = array();
		foreach ( $chars as $char ) {
			// UCS-2 counts UTF-16 code units: characters outside the BMP are a surrogate pair.
			$weights[] = $gsm ? $map[ $char ] : ( strlen( $char ) >= 4 ? 2 : 1 );
		}
		$units = array_sum( $weights );
		$single = $gsm ? self::GSM_SINGLE : self::UCS_SINGLE;
		$part = $gsm ? self::GSM_PART : self::UCS_PART;
		if ( 0 === $units ) {
			$segments = 0;
		} elseif ( $units <= $single ) {
			$segments = 1;
		} else {
			// Fill parts without splitting escape sequences or surrogate pairs.
			$segments = 1;
			$used = 0;
			foreach ( $weights as $weight ) {
				if ( $used + $weight > $part ) {
					++$segments;
					$used = 0;
				}
				$used += $weight;
			}
		}
		return array(
			'encoding' => $gsm ? 'gsm7' : 'ucs2',
			'units' => (int) $units,
			'segments' => (int) $segments,
			'per_segment' => $segments > 1 ? $part : $single,
		);
	}
}

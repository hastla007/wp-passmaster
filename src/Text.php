<?php
/**
 * UTF-8-safe shortening of text before it is stored or shown (A478).
 *
 * Text columns are sized in characters (varchar(255) with utf8mb4 holds 255 characters), and a
 * byte-wise substr() can split a multi-byte character: the cut text is no valid UTF-8 any more,
 * wp_json_encode() then returns false (empty audit details), esc_html() an empty string, and a
 * strict-mode database rejects the whole write ("Incorrect string value"). Everything that
 * shortens human-readable text therefore goes through cut(); tests/utf8-substr-check.php keeps
 * byte-wise substr() away from such text.
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class Text {
	/**
	 * At most $max characters of a text (A494); like cut(), but '' for arrays, objects and null.
	 *
	 * @param mixed $text Text.
	 * @param int   $max  Maximum number of characters.
	 */
	public static function limit( $text, $max ) {
		return self::cut( is_scalar( $text ) ? (string) $text : '', $max );
	}

	/**
	 * At most $length characters of $text, never a broken character. Invalid UTF-8 in the input
	 * (e.g. an earlier byte-wise cut) is replaced first, so the result is always valid UTF-8.
	 *
	 * @param mixed $text   Text (cast to string).
	 * @param int   $length Maximum number of characters.
	 */
	public static function cut( $text, $length ) {
		$text = (string) $text;
		$length = max( 0, (int) $length );
		if ( ! mb_check_encoding( $text, 'UTF-8' ) ) { $text = mb_scrub( $text, 'UTF-8' ); }
		return mb_strlen( $text, 'UTF-8' ) > $length ? mb_substr( $text, 0, $length, 'UTF-8' ) : $text;
	}

	/** Like cut(), with "…" appended when the text was shortened (display only). */
	public static function ellipsis( $text, $length ) {
		$text = (string) $text;
		$cut = self::cut( $text, $length );
		return mb_strlen( $cut, 'UTF-8' ) < mb_strlen( mb_scrub( $text, 'UTF-8' ), 'UTF-8' ) ? $cut . '…' : $cut;
	}

	/**
	 * Plain text of a stored value for outputs that are not HTML (A497): CSV, iCalendar, PDF, wallet
	 * passes, text mails and subjects. sanitize_text_field() stores a lone "<" as "&lt;" (and names may
	 * carry other entities such as "&amp;"); HTML output shows them correctly (esc_html() does not
	 * encode existing entities twice), plain-text output must decode them. Only complete entities
	 * ("&lt;", "&#60;") are decoded – "a&b" or a URL with "&lang=de" stays as it is.
	 *
	 * @param mixed $text Stored text ('' for arrays, objects and null).
	 */
	public static function plain( $text ) {
		$text = is_scalar( $text ) ? (string) $text : '';
		return false === strpos( $text, '&' ) ? $text : html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}

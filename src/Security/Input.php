<?php
/**
 * Type-safe readers for request values. Query/body parameters can be arrays (`?token[]=x`, JSON
 * bodies); casting them to string raises "Array to string conversion" and yields "Array". These
 * helpers return an empty string for anything that is not a scalar.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Security;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Input {
	/** Scalar value as string, '' for arrays, objects and null. */
	public static function text( $value ) {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/** Hex characters (a-f, 0-9) of a scalar value, as used for secret tokens/codes; '' for non-scalars. */
	public static function hex( $value ) {
		return strtolower( (string) preg_replace( '/[^a-f0-9]/', '', self::text( $value ) ) );
	}
}

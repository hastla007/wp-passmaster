<?php
/** Minimal RS256 JSON Web Token encoder. */
namespace PassCaptain\WalletPasses;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Jwt {
	public static function b64url( $bytes ) { return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); }

	public static function b64url_decode( $text ) { return base64_decode( strtr( (string) $text, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( (string) $text ) % 4 ) % 4 ) ); }

	public static function sign( array $claims, $private_key_pem ) {
		$input = self::b64url( (string) wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) ) . '.' . self::b64url( (string) wp_json_encode( $claims ) );
		$signature = '';
		if ( ! openssl_sign( $input, $signature, $private_key_pem, OPENSSL_ALGO_SHA256 ) ) {
			throw new \RuntimeException( 'wallet_jwt_sign_failed' );
		}
		return $input . '.' . self::b64url( $signature );
	}
}

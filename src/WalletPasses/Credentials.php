<?php
/**
 * Loads signing material. Every value may be a PEM string or a file path; Apple certificate and
 * key may also come together as a .p12/.pfx file (WPCAP_APPLE_PASS_CERT) with the key password.
 *
 * Sources (via Settings::secret): constants WPCAP_APPLE_PASS_CERT, WPCAP_APPLE_PASS_KEY,
 * WPCAP_APPLE_PASS_KEY_PASSWORD, WPCAP_APPLE_WWDR_CERT, WPCAP_GOOGLE_WALLET_SERVICE_ACCOUNT,
 * then environment variables of the same name, then the plugin settings.
 */
namespace PassCaptain\WalletPasses;

use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Credentials {
	/** Reads a PEM/JSON value or the file it points to. */
	public static function material( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) { return ''; }
		if ( false !== strpos( $value, '-----BEGIN' ) || '{' === $value[0] ) { return $value; }
		if ( strlen( $value ) < 4096 && is_readable( $value ) && is_file( $value ) ) { return (string) file_get_contents( $value ); }
		return '';
	}

	/**
	 * Apple pass signing credentials.
	 *
	 * An expired pass certificate or WWDR certificate counts as "not configured" (A585): Apple rejects
	 * such passes, so none are issued and the admin is warned (Health) instead of mailing broken passes.
	 *
	 * @param bool $check_expiry false: also return expired material (status page shows the dates).
	 * @return array{cert:string,key:string,password:string,wwdr:string}|\WP_Error
	 */
	public static function apple( $check_expiry = true ) {
		$cert_source = trim( \PassCaptain\Secrets::get( 'apple_pass_cert' ) );
		$password = \PassCaptain\Secrets::get( 'apple_pass_key_password' );
		$cert = '';
		$key = '';
		if ( preg_match( '/\.(p12|pfx)$/i', $cert_source ) && is_readable( $cert_source ) ) {
			$bundle = array();
			if ( openssl_pkcs12_read( (string) file_get_contents( $cert_source ), $bundle, $password ) ) {
				$cert = (string) $bundle['cert'];
				$key = (string) $bundle['pkey'];
				$password = '';
			}
		} else {
			$cert = self::material( $cert_source );
			$key = self::material( \PassCaptain\Secrets::get( 'apple_pass_key' ) );
		}
		$wwdr = self::material( \PassCaptain\Secrets::get( 'apple_wwdr_cert' ) );
		if ( '' === $cert || '' === $key || '' === $wwdr ) {
			return new \WP_Error( 'wpcap_wallet_apple_credentials', __( 'Apple-Zertifikat, privater Schlüssel oder WWDR-Zertifikat fehlen.', 'pass-captain' ) );
		}
		if ( ! openssl_x509_read( $cert ) || ! openssl_x509_read( $wwdr ) ) {
			return new \WP_Error( 'wpcap_wallet_apple_cert_invalid', __( 'Ein Apple-Zertifikat konnte nicht gelesen werden.', 'pass-captain' ) );
		}
		$pkey = openssl_pkey_get_private( $key, $password );
		if ( ! $pkey || ! openssl_x509_check_private_key( $cert, $pkey ) ) {
			return new \WP_Error( 'wpcap_wallet_apple_key_invalid', __( 'Der private Schlüssel passt nicht zum Pass-Type-ID-Zertifikat oder das Passwort ist falsch.', 'pass-captain' ) );
		}
		if ( $check_expiry ) {
			foreach ( array( 'cert' => $cert, 'wwdr' => $wwdr ) as $which => $pem ) {
				$info = self::describe_cert( $pem );
				if ( $info && $info['valid_to'] <= time() ) {
					return new \WP_Error(
						'wpcap_wallet_apple_cert_expired',
						'cert' === $which
							/* translators: %s: expiry date */
							? sprintf( __( 'Das Pass-Type-ID-Zertifikat ist am %s abgelaufen. Apple Wallet ist deaktiviert, bis ein neues Zertifikat hinterlegt ist.', 'pass-captain' ), gmdate( 'd.m.Y', $info['valid_to'] ) )
							/* translators: %s: expiry date */
							: sprintf( __( 'Das Apple-WWDR-Zwischenzertifikat ist am %s abgelaufen. Apple Wallet ist deaktiviert, bis ein aktuelles WWDR-Zertifikat hinterlegt ist.', 'pass-captain' ), gmdate( 'd.m.Y', $info['valid_to'] ) )
					);
				}
			}
		}
		return array( 'cert' => $cert, 'key' => $key, 'password' => (string) $password, 'wwdr' => $wwdr );
	}

	/** Days a certificate expiry is announced in advance (Site Health, admin notice). */
	const EXPIRY_WARNING_DAYS = 30;

	/**
	 * Google service account (JSON key file contents).
	 *
	 * @return array{client_email:string,private_key:string,token_uri:string}|\WP_Error
	 */
	public static function google() {
		$json = json_decode( self::material( \PassCaptain\Secrets::get( 'google_wallet_service_account' ) ), true );
		if ( ! is_array( $json ) || empty( $json['client_email'] ) || empty( $json['private_key'] ) ) {
			return new \WP_Error( 'wpcap_wallet_google_credentials', __( 'Das Google-Servicekonto (JSON) fehlt oder ist ungültig.', 'pass-captain' ) );
		}
		if ( ! openssl_pkey_get_private( $json['private_key'] ) ) {
			return new \WP_Error( 'wpcap_wallet_google_key', __( 'Der private Schlüssel des Google-Servicekontos ist ungültig.', 'pass-captain' ) );
		}
		return array( 'client_email' => (string) $json['client_email'], 'private_key' => (string) $json['private_key'], 'token_uri' => (string) ( $json['token_uri'] ?? 'https://oauth2.googleapis.com/token' ) );
	}

	/** Subject + validity of a certificate for the status page. */
	public static function describe_cert( $pem ) {
		$info = $pem ? openssl_x509_parse( $pem ) : false;
		if ( ! $info ) { return null; }
		return array(
			'subject' => (string) ( $info['subject']['CN'] ?? '' ),
			'uid' => (string) ( $info['subject']['UID'] ?? '' ),
			'team' => (string) ( $info['subject']['OU'] ?? '' ),
			'valid_from' => (int) $info['validFrom_time_t'],
			'valid_to' => (int) $info['validTo_time_t'],
		);
	}
}

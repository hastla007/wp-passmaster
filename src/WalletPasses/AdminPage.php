<?php
/** "Wallet" admin screen: configuration, status (certificate validity), test pass download, device log. */
namespace PassCaptain\WalletPasses;

use PassCaptain\Admin\Forms;
use PassCaptain\Audit;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class AdminPage {
	const SLUG = 'wpcap-wallet';
	const CAP = 'manage_options';
	const SECRETS = array( 'apple_pass_cert', 'apple_pass_key', 'apple_pass_key_password', 'apple_wwdr_cert', 'google_wallet_service_account' );

	/** Where a secret comes from: 'constant', 'env', 'settings' or ''. */
	public static function secret_source( $key ) {
		$constant = 'WPCAP_' . strtoupper( $key );
		if ( defined( $constant ) && '' !== (string) constant( $constant ) ) { return 'constant'; }
		if ( is_string( getenv( $constant ) ) && '' !== getenv( $constant ) ) { return 'env'; }
		return '' !== (string) Settings::get( $key ) ? 'settings' : '';
	}

	/** Validates and stores the form. */
	public static function save( array $in ) {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		$values = array(
			'wallet_apple_enabled' => (int) Forms::flag( $in, 'wallet_apple_enabled' ),
			'wallet_google_enabled' => (int) Forms::flag( $in, 'wallet_google_enabled' ),
			'wallet_apple_delivery' => Forms::choice( $in, 'wallet_apple_delivery', array( 'attachment', 'link' ), 'attachment' ),
			'wallet_organization_name' => Forms::text( $in, 'wallet_organization_name', 64 ),
			'wallet_apple_icon_path' => Forms::text( $in, 'wallet_apple_icon_path', 500 ),
			'wallet_apple_logo_path' => Forms::text( $in, 'wallet_apple_logo_path', 500 ),
		);
		$pass_type = Forms::text( $in, 'wallet_apple_pass_type_id', 190 );
		if ( '' !== $pass_type && ! preg_match( '/^pass\.[A-Za-z0-9.\-]+$/', $pass_type ) ) {
			return Forms::error( 'wpcap_wallet_pass_type', __( 'Die Pass Type ID muss mit "pass." beginnen.', 'pass-captain' ) );
		}
		$team = strtoupper( Forms::text( $in, 'wallet_apple_team_id', 10 ) );
		if ( '' !== $team && ! preg_match( '/^[A-Z0-9]{10}$/', $team ) ) {
			return Forms::error( 'wpcap_wallet_team', __( 'Die Team-ID besteht aus 10 Zeichen.', 'pass-captain' ) );
		}
		$issuer = Forms::text( $in, 'wallet_google_issuer_id', 32 );
		if ( '' !== $issuer && ! ctype_digit( $issuer ) ) {
			return Forms::error( 'wpcap_wallet_issuer', __( 'Die Google-Aussteller-ID ist numerisch.', 'pass-captain' ) );
		}
		$host = esc_url_raw( Forms::text( $in, 'wallet_apns_host', 190 ) );
		if ( ! in_array( $host, array( 'https://api.push.apple.com', 'https://api.sandbox.push.apple.com' ), true ) ) { $host = 'https://api.push.apple.com'; }
		$values += array( 'wallet_apple_pass_type_id' => $pass_type, 'wallet_apple_team_id' => $team, 'wallet_google_issuer_id' => $issuer, 'wallet_apns_host' => $host );
		foreach ( array( 'wallet_background_color' => '#1d2327', 'wallet_foreground_color' => '#ffffff', 'wallet_label_color' => '#c3c4c7' ) as $key => $default ) {
			$color = strtolower( Forms::text( $in, $key, 7 ) );
			$values[ $key ] = preg_match( '/^#[0-9a-f]{6}$/', $color ) ? $color : $default;
		}
		foreach ( self::SECRETS as $key ) {
			if ( in_array( self::secret_source( $key ), array( 'constant', 'env' ), true ) ) { continue; }
			if ( Forms::flag( $in, $key . '_clear' ) ) { $values[ $key ] = ''; continue; }
			$value = trim( str_replace( "\r\n", "\n", (string) ( $in[ $key ] ?? '' ) ) );
			if ( '' !== $value ) { $values[ $key ] = substr( $value, 0, 20000 ); }
		}
		Settings::update( $values );
		Audit::record( 'wallet_settings_saved', 'settings', 0, array( 'apple' => $values['wallet_apple_enabled'], 'google' => $values['wallet_google_enabled'] ) );
		return array( 'saved' => true );
	}

	/** Configuration status for display. */
	public static function status() {
		$apple = Credentials::apple();
		$google = Credentials::google();
		$status = array(
			'extensions' => array(
				'openssl' => extension_loaded( 'openssl' ),
				'sodium' => function_exists( 'sodium_crypto_secretbox' ),
				'zip' => class_exists( '\ZipArchive' ),
				'gd' => function_exists( 'imagecreatetruecolor' ),
				'curl_http2' => function_exists( 'curl_version' ) && defined( 'CURL_VERSION_HTTP2' ) && ( curl_version()['features'] & CURL_VERSION_HTTP2 ),
			),
			'apple' => array( 'enabled' => (bool) Settings::get( 'wallet_apple_enabled' ), 'configured' => ( new AppleProvider() )->is_configured(), 'error' => is_wp_error( $apple ) ? $apple->get_error_message() : '', 'cert' => null, 'wwdr' => null ),
			'google' => array( 'enabled' => (bool) Settings::get( 'wallet_google_enabled' ), 'configured' => ( new GoogleProvider() )->is_configured(), 'error' => is_wp_error( $google ) ? $google->get_error_message() : '', 'account' => is_array( $google ) ? $google['client_email'] : '' ),
		);
		if ( is_array( $apple ) ) {
			$status['apple']['cert'] = Credentials::describe_cert( $apple['cert'] );
			$status['apple']['wwdr'] = Credentials::describe_cert( $apple['wwdr'] );
			$uid = (string) ( $status['apple']['cert']['uid'] ?? '' );
			if ( '' !== $uid && $uid !== Settings::get( 'wallet_apple_pass_type_id' ) ) {
				/* translators: %s: pass type id from the certificate */
				$status['apple']['error'] = sprintf( __( 'Das Zertifikat gehört zur Pass Type ID %s.', 'pass-captain' ), $uid );
			}
		}
		return $status;
	}

	/** Sample pass with placeholder data (no web service, so it never updates). */
	public static function test_pass() {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		$credentials = Credentials::apple();
		if ( is_wp_error( $credentials ) ) { return $credentials; }
		$provider = new AppleProvider();
		$ticket = array(
			'ticket_id' => 0, 'public_id' => 'test-' . bin2hex( random_bytes( 8 ) ), 'event_name' => __( 'Testveranstaltung', 'pass-captain' ), 'ticket_name' => __( 'Standard', 'pass-captain' ),
			'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS ), 'ends_at' => null, 'timezone' => function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'UTC', 'location' => __( 'Testort', 'pass-captain' ),
			'seat' => '', 'attendee_name' => '', 'voided' => false, 'code' => 'TEST-' . bin2hex( random_bytes( 6 ) ), 'auth_token' => null, 'occurrence_status' => 'scheduled', 'event_status' => 'published',
		);
		try {
			return $provider->bundle( $provider->pass_json( $ticket ), $credentials );
		} catch ( \Throwable $e ) {
			return Forms::error( 'wpcap_wallet_test_pass', __( 'Der Test-Pass konnte nicht signiert werden.', 'pass-captain' ) . ' (' . $e->getMessage() . ')', 500 );
		}
	}

	public static function handle_save() {
		Forms::verify( 'wpcap_wallet_save' );
		Forms::finish( self::save( Forms::post() ), __( 'Wallet-Einstellungen gespeichert.', 'pass-captain' ), Forms::page_url( self::SLUG ) );
	}

	public static function handle_test_pass() {
		Forms::verify( 'wpcap_wallet_test_pass' );
		$bytes = self::test_pass();
		if ( is_wp_error( $bytes ) ) { Forms::finish( $bytes, '', Forms::page_url( self::SLUG ) ); }
		nocache_headers();
		header( 'Content-Type: application/vnd.apple.pkpass' );
		header( 'Content-Disposition: attachment; filename="test.pkpass"' );
		header( 'Content-Length: ' . strlen( $bytes ) );
		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput -- binary pkpass.
		exit;
	}

	private static function yes_no( $flag ) {
		return $flag ? '<span style="color:#008a20">' . esc_html__( 'ja', 'pass-captain' ) . '</span>' : '<span style="color:#d63638">' . esc_html__( 'nein', 'pass-captain' ) . '</span>';
	}

	private static function cert_row( $label, $cert ) {
		echo '<tr><th>' . esc_html( $label ) . '</th><td>';
		if ( ! $cert ) { echo '—'; } else {
			$expired = $cert['valid_to'] < time();
			$soon = ! $expired && $cert['valid_to'] < time() + 30 * DAY_IN_SECONDS;
			echo esc_html( $cert['subject'] ) . '<br>' . esc_html( sprintf( /* translators: 1: from date, 2: to date */ __( 'gültig von %1$s bis %2$s', 'pass-captain' ), gmdate( 'd.m.Y', $cert['valid_from'] ), gmdate( 'd.m.Y', $cert['valid_to'] ) ) );
			if ( $expired || $soon ) { echo ' <strong style="color:#d63638">' . esc_html( $expired ? __( 'abgelaufen', 'pass-captain' ) : __( 'läuft bald ab', 'pass-captain' ) ) . '</strong>'; }
		}
		echo '</td></tr>';
	}

	private static function text_row( $key, $label, $value, $help = '' ) {
		echo '<tr><th><label for="wpcap-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><input class="regular-text" id="wpcap-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '">' . ( $help ? '<p class="description">' . esc_html( $help ) . '</p>' : '' ) . '</td></tr>';
	}

	private static function secret_row( $key, $label ) {
		$source = self::secret_source( $key );
		echo '<tr><th><label for="wpcap-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		if ( in_array( $source, array( 'constant', 'env' ), true ) ) {
			/* translators: %s: constant name */
			echo esc_html( sprintf( __( 'Gesetzt über %s.', 'pass-captain' ), 'WPCAP_' . strtoupper( $key ) ) );
		} else {
			echo '<textarea class="large-text code" rows="3" id="wpcap-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" placeholder="' . esc_attr( 'settings' === $source ? __( 'gespeichert – leer lassen, um den Wert zu behalten', 'pass-captain' ) : __( 'Dateipfad oder PEM/JSON-Inhalt', 'pass-captain' ) ) . '"></textarea>';
			if ( 'settings' === $source ) { echo '<label><input type="checkbox" name="' . esc_attr( $key ) . '_clear" value="1"> ' . esc_html__( 'Wert entfernen', 'pass-captain' ) . '</label>'; }
			/* translators: %s: constant name */
			echo '<p class="description">' . esc_html( sprintf( __( 'Empfohlen: Konstante %s in wp-config.php.', 'pass-captain' ), 'WPCAP_' . strtoupper( $key ) ) ) . '</p>';
		}
		echo '</td></tr>';
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		$s = Settings::all();
		$status = self::status();
		echo '<div class="wrap wpcap-admin"><h1>' . esc_html__( 'Wallet', 'pass-captain' ) . '</h1>';
		Forms::render_notices();
		echo '<h2>' . esc_html__( 'Status', 'pass-captain' ) . '</h2><table class="widefat striped" style="max-width:900px"><tbody>';
		foreach ( $status['extensions'] as $ext => $ok ) { echo '<tr><th>' . esc_html( $ext ) . '</th><td>' . self::yes_no( $ok ) . '</td></tr>'; } // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in yes_no.
		echo '<tr><th>' . esc_html__( 'Apple Wallet einsatzbereit', 'pass-captain' ) . '</th><td>' . self::yes_no( $status['apple']['configured'] ) . ( $status['apple']['error'] ? ' ' . esc_html( $status['apple']['error'] ) : '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		self::cert_row( __( 'Pass-Type-ID-Zertifikat', 'pass-captain' ), $status['apple']['cert'] );
		self::cert_row( __( 'Apple WWDR-Zertifikat', 'pass-captain' ), $status['apple']['wwdr'] );
		echo '<tr><th>' . esc_html__( 'Web-Service-URL', 'pass-captain' ) . '</th><td><code>' . esc_html( AppleProvider::web_service_url() ) . '</code></td></tr>';
		echo '<tr><th>' . esc_html__( 'Google Wallet einsatzbereit', 'pass-captain' ) . '</th><td>' . self::yes_no( $status['google']['configured'] ) . ( $status['google']['error'] ? ' ' . esc_html( $status['google']['error'] ) : '' ) . ( $status['google']['account'] ? ' <code>' . esc_html( $status['google']['account'] ) . '</code>' : '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</tbody></table>';
		if ( is_array( Credentials::apple() ) ) {
			Forms::open_form( 'wpcap_wallet_test_pass' );
			echo '<p><button class="button">' . esc_html__( 'Test-Pass herunterladen', 'pass-captain' ) . '</button></p></form>';
		}
		Forms::open_form( 'wpcap_wallet_save' );
		echo '<h2>' . esc_html__( 'Allgemein', 'pass-captain' ) . '</h2><table class="form-table"><tbody>';
		self::text_row( 'wallet_organization_name', __( 'Organisation auf dem Pass', 'pass-captain' ), $s['wallet_organization_name'], __( 'Leer: Name der Website.', 'pass-captain' ) );
		self::text_row( 'wallet_background_color', __( 'Hintergrundfarbe', 'pass-captain' ), $s['wallet_background_color'] );
		self::text_row( 'wallet_foreground_color', __( 'Textfarbe', 'pass-captain' ), $s['wallet_foreground_color'] );
		self::text_row( 'wallet_label_color', __( 'Beschriftungsfarbe', 'pass-captain' ), $s['wallet_label_color'] );
		echo '</tbody></table><h2>' . esc_html__( 'Apple Wallet', 'pass-captain' ) . '</h2><table class="form-table"><tbody>';
		echo '<tr><th>' . esc_html__( 'Aktiv', 'pass-captain' ) . '</th><td><label><input type="checkbox" name="wallet_apple_enabled" value="1"' . checked( (int) $s['wallet_apple_enabled'], 1, false ) . '> ' . esc_html__( '.pkpass für neue Tickets erzeugen', 'pass-captain' ) . '</label></td></tr>';
		self::text_row( 'wallet_apple_pass_type_id', __( 'Pass Type ID', 'pass-captain' ), $s['wallet_apple_pass_type_id'], 'pass.com.example.tickets' );
		self::text_row( 'wallet_apple_team_id', __( 'Team-ID', 'pass-captain' ), $s['wallet_apple_team_id'] );
		echo '<tr><th>' . esc_html__( 'Versand', 'pass-captain' ) . '</th><td>';
		Forms::select( 'wallet_apple_delivery', array( 'attachment' => __( 'Als Anhang (.pkpass)', 'pass-captain' ), 'link' => __( 'Als Download-Link', 'pass-captain' ) ), $s['wallet_apple_delivery'] );
		echo '</td></tr><tr><th>' . esc_html__( 'APNs', 'pass-captain' ) . '</th><td>';
		Forms::select( 'wallet_apns_host', array( 'https://api.push.apple.com' => __( 'Produktion', 'pass-captain' ), 'https://api.sandbox.push.apple.com' => __( 'Sandbox', 'pass-captain' ) ), $s['wallet_apns_host'] );
		echo '</td></tr>';
		self::text_row( 'wallet_apple_icon_path', __( 'Eigenes Icon (PNG-Pfad, 29×29)', 'pass-captain' ), $s['wallet_apple_icon_path'], __( 'Optional; …@2x.png daneben wird mitverwendet. Ohne Angabe wird ein Icon erzeugt.', 'pass-captain' ) );
		self::text_row( 'wallet_apple_logo_path', __( 'Eigenes Logo (PNG-Pfad, max. 160×50)', 'pass-captain' ), $s['wallet_apple_logo_path'] );
		self::secret_row( 'apple_pass_cert', __( 'Pass-Type-ID-Zertifikat (PEM oder .p12)', 'pass-captain' ) );
		self::secret_row( 'apple_pass_key', __( 'Privater Schlüssel (PEM)', 'pass-captain' ) );
		self::secret_row( 'apple_pass_key_password', __( 'Schlüssel-Passwort', 'pass-captain' ) );
		self::secret_row( 'apple_wwdr_cert', __( 'Apple WWDR-Zwischenzertifikat (PEM)', 'pass-captain' ) );
		echo '</tbody></table><h2>' . esc_html__( 'Google Wallet', 'pass-captain' ) . '</h2><table class="form-table"><tbody>';
		echo '<tr><th>' . esc_html__( 'Aktiv', 'pass-captain' ) . '</th><td><label><input type="checkbox" name="wallet_google_enabled" value="1"' . checked( (int) $s['wallet_google_enabled'], 1, false ) . '> ' . esc_html__( '„In Google Wallet speichern“-Link versenden', 'pass-captain' ) . '</label></td></tr>';
		self::text_row( 'wallet_google_issuer_id', __( 'Aussteller-ID (Issuer ID)', 'pass-captain' ), $s['wallet_google_issuer_id'] );
		self::secret_row( 'google_wallet_service_account', __( 'Servicekonto-Schlüssel (JSON)', 'pass-captain' ) );
		echo '</tbody></table>';
		submit_button( __( 'Speichern', 'pass-captain' ) );
		echo '</form>';
		$logs = array(
			Service::LOG_OPTION => __( 'Protokoll', 'pass-captain' ),
			Service::DEVICE_LOG_OPTION => __( 'Meldungen von Geräten (ungeprüft)', 'pass-captain' ),
		);
		foreach ( $logs as $option => $title ) {
			$log = get_option( $option, array() );
			if ( ! is_array( $log ) || ! $log ) { continue; }
			echo '<h2>' . esc_html( $title ) . '</h2><table class="widefat striped" style="max-width:900px"><tbody>';
			foreach ( array_reverse( $log ) as $entry ) {
				echo '<tr><td>' . esc_html( gmdate( 'd.m.Y H:i', (int) $entry['time'] ) ) . ' UTC</td><td>' . esc_html( $entry['code'] ) . '</td><td>' . esc_html( $entry['message'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}
}

<?php
/**
 * W5: secrets vault (encryption at rest, migration, key loss) and "secrets never leak" checks:
 * wpcap_settings, REST responses, admin HTML, audit log, WP_Error messages, offline scanner
 * package and wallet pass archive are searched for the plaintext secret strings.
 * Included from run.php (runs after the module tests, so their fakes and fixtures exist).
 */
// phpcs:ignoreFile
use PassCaptain\Settings;
use PassCaptain\Security\Vault;
use PassCaptain\Security\Redact;

echo "W5 security: secrets vault\n";
global $wpdb;
$p = $wpdb->prefix;
$w5_raw_before = get_option( Settings::OPTION, array() );

/** Keys of $secrets whose plaintext occurs in $haystack. */
function w5_leaks( $haystack, array $secrets ) {
	$haystack = is_string( $haystack ) ? $haystack : (string) json_encode( $haystack );
	$found = array();
	foreach ( $secrets as $key => $value ) {
		$probe = strlen( $value ) > 24 ? substr( $value, 4, 20 ) : $value; // Inner part: survives JSON escaping and prefixes.
		if ( '' !== $value && ( false !== strpos( $haystack, $value ) || false !== strpos( $haystack, $probe ) ) ) { $found[] = $key; }
	}
	return $found;
}
function w5_raw_settings() { return (string) json_encode( get_option( Settings::OPTION, array() ) ) . serialize( get_option( Settings::OPTION, array() ) ); }

$w5_tag = substr( bin2hex( random_bytes( 8 ) ), 0, 12 );
$w5_secrets = array(
	'stripe_secret_key' => 'sk_test_W5' . $w5_tag . 'StripeKey1234',
	'stripe_webhook_secret' => 'whsec_W5' . $w5_tag . 'Webhook5678',
	'paypal_client_secret' => 'PPW5' . $w5_tag . 'ClientSecret9012',
	'mollie_api_key' => 'test_W5' . $w5_tag . 'MollieKeyAbcdefgh',
	'twilio_auth_token' => 'tw' . $w5_tag . 'TwilioAuthToken42',
	'sms_http_secret' => 'Bearer W5' . $w5_tag . 'SmsGatewaySecret',
);

/* ---- Vault primitives ---- */
$c1 = Vault::encrypt( 'hello-secret-value', 'stripe_secret_key' );
$c2 = Vault::encrypt( 'hello-secret-value', 'stripe_secret_key' );
check( 'vault: values are stored as pcv1: ciphertext with a fresh nonce', Vault::available() && 0 === strpos( $c1, 'pcv1:' ) && $c1 !== $c2 && false === strpos( $c1, 'hello-secret' ) );
check( 'vault: round trip', 'hello-secret-value' === Vault::decrypt( $c1, 'stripe_secret_key' ) );
check( 'vault: ciphertext is bound to its setting (copying into another field does not decrypt)', null === Vault::decrypt( $c1, 'mollie_api_key' ) );
$tampered = substr( $c1, 0, -4 ) . ( 'AAAA' === substr( $c1, -4 ) ? 'BBBB' : 'AAAA' );
check( 'vault: tampered ciphertext is rejected', null === Vault::decrypt( $tampered, 'stripe_secret_key' ) );
check( 'vault: empty and already encrypted values are not double-encrypted', '' === Vault::encrypt( '', 'x' ) && $c1 === Vault::encrypt( $c1, 'stripe_secret_key' ) );
check( 'vault: without dedicated key the key is derived from the wp-config salts (documented warning)', 'salts' === Vault::key_source() && 'warning' === Vault::key_notice()[0] );

/* ---- Settings::update / secret() ---- */
Settings::update( $w5_secrets );
$raw = w5_raw_settings();
check( 'settings: no secret is stored in plaintext in wpcap_settings after save', array() === w5_leaks( $raw, $w5_secrets ) );
$all_encrypted = true;
foreach ( $w5_secrets as $key => $value ) { $all_encrypted = $all_encrypted && Vault::is_encrypted( get_option( Settings::OPTION )[ $key ] ?? '' ) && $value === Settings::secret( $key ); }
check( 'settings: secret() decrypts transparently, get() only sees ciphertext', $all_encrypted && Vault::is_encrypted( Settings::get( 'stripe_secret_key' ) ) );
\PassCaptain\Admin\SettingsPage::save( array( 'stripe_secret_key' => 'sk_test_W5viaForm' . $w5_tag . 'Abcdefgh' ) );
check( 'settings page save stores the secret encrypted', false === strpos( w5_raw_settings(), 'sk_test_W5viaForm' ) && 'sk_test_W5viaForm' . $w5_tag . 'Abcdefgh' === Settings::secret( 'stripe_secret_key' ) );
\PassCaptain\Admin\SettingsPage::save( array( 'stripe_secret_key' => '' ) );
check( 'empty secret field keeps the stored value', 'sk_test_W5viaForm' . $w5_tag . 'Abcdefgh' === Settings::secret( 'stripe_secret_key' ) );
Settings::update( array( 'stripe_secret_key' => $w5_secrets['stripe_secret_key'] ) );

/* ---- Migration of legacy plaintext ---- */
$legacy = get_option( Settings::OPTION );
$legacy['paypal_client_secret'] = $w5_secrets['paypal_client_secret'];
$legacy['twilio_auth_token'] = $w5_secrets['twilio_auth_token'];
update_option( Settings::OPTION, $legacy );
Settings::flush();
check( 'legacy plaintext values still work before the migration', $w5_secrets['paypal_client_secret'] === Settings::secret( 'paypal_client_secret' ) );
$wpdb->query( $wpdb->prepare( "INSERT INTO {$p}wpcap_webhook_subscriptions (url,description,events,secret,include_pii,status,consecutive_failures,created_at,updated_at) VALUES ('https://w5.hooks.example/x','w5','order.paid',%s,0,'disabled',0,%s,%s)", 'whsec_W5legacy' . $w5_tag . 'abcdef', gmdate( 'Y-m-d H:i:s' ), gmdate( 'Y-m-d H:i:s' ) ) );
$w5_legacy_hook = (int) $wpdb->insert_id;
$m1 = Vault::migrate();
$after_first = get_option( Settings::OPTION );
$m2 = Vault::migrate();
check( "migration encrypts plaintext secrets once (settings {$m1['settings']}, webhooks {$m1['webhooks']})", 2 === $m1['settings'] && $m1['webhooks'] >= 1 && array() === w5_leaks( w5_raw_settings(), $w5_secrets ) );
check( 'migration is idempotent', 0 === $m2['settings'] && 0 === $m2['webhooks'] && $after_first === get_option( Settings::OPTION ) );
$hook_raw = (string) $wpdb->get_var( "SELECT secret FROM {$p}wpcap_webhook_subscriptions WHERE subscription_id=$w5_legacy_hook" );
check( 'webhook signing secrets are encrypted at rest and decrypted for signing', Vault::is_encrypted( $hook_raw ) && 'whsec_W5legacy' . $w5_tag . 'abcdef' === \PassCaptain\Webhooks\Dispatcher::subscription( $w5_legacy_hook )->secret );
$created = \PassCaptain\Webhooks\Dispatcher::create_subscription( 'https://w5-new.hooks.example/in', array( 'order.paid' ), false, 'w5' );
$created_raw = (string) $wpdb->get_var( "SELECT secret FROM {$p}wpcap_webhook_subscriptions WHERE subscription_id=" . (int) $created['subscription_id'] );
check( 'new webhook secrets are stored encrypted (plaintext returned once)', 0 === strpos( $created['secret'], 'whsec_' ) && Vault::is_encrypted( $created_raw ) && false === strpos( $created_raw, $created['secret'] ) );
check( 'wpcap_settings carries the migration marker', Vault::VERSION === (int) get_option( Vault::MIGRATED_OPTION ) );

/* ---- Constants / env vars keep overriding ---- */
putenv( 'WPCAP_STRIPE_SECRET_KEY=sk_test_fromEnvironment123' );
check( 'environment variable overrides the stored secret', 'sk_test_fromEnvironment123' === Settings::secret( 'stripe_secret_key' ) && 'override' === Settings::secret_status( 'stripe_secret_key' )['state'] );
putenv( 'WPCAP_STRIPE_SECRET_KEY' );
check( 'without override the stored secret is used again', $w5_secrets['stripe_secret_key'] === Settings::secret( 'stripe_secret_key' ) );

/* ---- Dedicated key and rotation ---- */
putenv( 'PASS_CAPTAIN_SECRET_KEY=' . str_repeat( 'ab', 32 ) );
Vault::reset();
check( 'PASS_CAPTAIN_SECRET_KEY (env) is preferred', 'env' === Vault::key_source() && 'success' === Vault::key_notice()[0] );
check( 'values encrypted with the salt key stay readable after switching to a dedicated key', $w5_secrets['mollie_api_key'] === Settings::secret( 'mollie_api_key' ) );
$re = Vault::migrate();
putenv( 'PASS_CAPTAIN_SECRET_KEY' );
Vault::reset();
check( "migration re-encrypts with the new key ({$re['settings']} values)", $re['settings'] >= count( $w5_secrets ) && null === Vault::decrypt( get_option( Settings::OPTION )['mollie_api_key'], 'mollie_api_key' ) );
putenv( 'PASS_CAPTAIN_SECRET_KEY=' . str_repeat( 'ab', 32 ) );
Vault::reset();
check( 're-encrypted values decrypt with the dedicated key', $w5_secrets['mollie_api_key'] === Settings::secret( 'mollie_api_key' ) );

/* ---- Key loss ---- */
Vault::set_keys_for_tests( random_bytes( 32 ) );
$lost = Settings::secret( 'mollie_api_key' );
$broken = Vault::undecryptable_settings();
ob_start(); Vault::render_notices(); $notice = ob_get_clean();
ob_start(); echo \PassCaptain\Admin\SettingsPage::secret_input( 'stripe_secret_key' ); $lost_field = ob_get_clean();
check( 'key loss: undecryptable secret is treated as empty', '' === $lost && 'undecryptable' === Settings::secret_status( 'mollie_api_key' )['state'] );
check( 'key loss: admin notice asks to re-enter the affected secrets', in_array( 'mollie_api_key', $broken, true ) && false !== strpos( $notice, 'erneut eingeben' ) && false !== strpos( $notice, 'mollie_api_key' ) );
check( 'key loss: settings field says so and offers replacement', false !== strpos( $lost_field, 'nicht entschlüsselt' ) && false !== strpos( $lost_field, 'stripe_secret_key__clear' ) );
check( 'key loss: payment gateway counts as not configured instead of using garbage', ! ( new \PassCaptain\Mollie\MollieGateway() )->is_available( 'EUR' ) );
$m3 = Vault::migrate();
check( 'key loss: migration leaves undecryptable values untouched', 0 === $m3['settings'] );
Vault::reset();
check( 'key restored: secrets readable again', $w5_secrets['mollie_api_key'] === Settings::secret( 'mollie_api_key' ) );

/* ---- Leak checks ---- */
echo "W5 security: secrets never appear in plaintext\n";
$w5_all = $w5_secrets + array( 'webhook_secret' => $created['secret'] );

// Admin HTML.
$w5_html = '';
$w5_render_errors = array();
foreach ( array(
	'settings' => array( \PassCaptain\Admin\SettingsPage::class, 'render' ),
	'sms' => array( \PassCaptain\Modules\Sms::class, 'render_admin' ),
	'mollie' => array( \PassCaptain\Modules\Mollie::class, 'render_admin' ),
	'wallet' => array( \PassCaptain\WalletPasses\AdminPage::class, 'render' ),
	'webhooks' => array( \PassCaptain\Modules\Webhooks::class, 'render_admin' ),
) as $name => $callback ) {
	ob_start();
	try { call_user_func( $callback ); } catch ( Throwable $e ) { $w5_render_errors[] = $name . ': ' . $e->getMessage(); }
	$w5_html .= ob_get_clean();
}
check( 'admin screens render (settings, SMS, Mollie, Wallet, Webhooks)' . ( $w5_render_errors ? ' – ' . implode( '; ', $w5_render_errors ) : '' ), ! $w5_render_errors && strlen( $w5_html ) > 1000 );
check( 'admin HTML contains no plaintext secret and no ciphertext', array() === w5_leaks( $w5_html, $w5_all ) && false === strpos( $w5_html, 'pcv1:' ) );
check( 'settings page shows "gespeichert ••••" with the last four characters', false !== strpos( $w5_html, 'gespeichert ••••' . substr( $w5_secrets['stripe_secret_key'], -4 ) ) );
// One-time reveal of a webhook secret after creation.
set_transient( 'wpcap_webhook_reveal_' . get_current_user_id(), Vault::encrypt( json_encode( array( (int) $created['subscription_id'], $created['secret'] ) ), 'webhook_reveal' ) );
ob_start(); \PassCaptain\Modules\Webhooks::render_admin(); $first = ob_get_clean();
ob_start(); \PassCaptain\Modules\Webhooks::render_admin(); $second = ob_get_clean();
check( 'webhook secret is shown exactly once after create/rotate', false !== strpos( $first, $created['secret'] ) && false === strpos( $second, $created['secret'] ) );

// Audit log.
\PassCaptain\Audit::record( 'w5_probe', 'test', 0, array( 'api_key' => $w5_secrets['mollie_api_key'], 'message' => 'Provider said: invalid key ' . $w5_secrets['stripe_secret_key'], 'email' => 'jane.doe@example.com', 'ticket' => str_repeat( 'ab', 32 ), 'nested' => array( 'client_secret' => 'x', 'note' => 'Bearer abcdefghijklmnop' ) ) );
$probe = (string) $wpdb->get_var( "SELECT details FROM {$p}wpcap_audit_logs WHERE action='w5_probe' ORDER BY audit_id DESC LIMIT 1" );
check( 'audit details are redacted (keys, embedded secrets, e-mail, ticket code, bearer token)', array() === w5_leaks( $probe, $w5_all ) && false === strpos( $probe, 'jane.doe@' ) && false !== strpos( $probe, 'j***@example.com' ) && false === strpos( $probe, str_repeat( 'ab', 32 ) ) && false === strpos( $probe, 'abcdefghijklmnop' ) );
$audit_all = implode( "\n", $wpdb->get_col( "SELECT details FROM {$p}wpcap_audit_logs" ) );
check( 'no audit row of the whole run contains a configured secret', array() === w5_leaks( $audit_all, $w5_all ) );

// WP_Error messages from providers.
http_reset();
http_push( array( 'error' => array( 'message' => 'Invalid API Key provided: ' . $w5_secrets['stripe_secret_key'] ) ), 401, 'api.stripe.com' );
PassCaptain\Settings::update( array( 'enabled_providers' => array( 'manual', 'stripe' ) ) );
$w5_type = make_type( 5, 10, 1500 );
$req = new WP_REST_Request( 'POST', '/wp-pass-captain/v1/checkout' );
$req->set_body( json_encode( array( 'email' => 'w5-leak@example.com', 'items' => array( array( 'ticket_type_id' => $w5_type, 'quantity' => 1 ) ), 'idempotency_key' => 'w5-leak-1', 'provider' => 'stripe' ) ) );
$GLOBALS['wpcap_test_transients'] = array();
$res = \PassCaptain\Rest\Controller::checkout( $req );
$err_text = is_wp_error( $res ) ? $res->get_error_message() . json_encode( $res->get_error_data() ) : json_encode( $res );
check( 'provider error echoing the API key is redacted in the REST error', is_wp_error( $res ) && array() === w5_leaks( $err_text, $w5_all ) && false !== strpos( $err_text, 'Invalid API Key' ) );
$e = Redact::error( new WP_Error( 'x', 'failed with ' . $w5_secrets['twilio_auth_token'] . ' for jane@example.com', array( 'status' => 502, 'token' => 'abc', 'raw' => 'secret=' . $w5_secrets['sms_http_secret'] ) ) );
check( 'Redact::error removes secrets from message and data, keeps status', array() === w5_leaks( $e->get_error_message() . json_encode( $e->get_error_data() ), $w5_all ) && 502 === $e->get_error_data()['status'] && false === strpos( $e->get_error_message(), 'jane@' ) );

// REST responses of every registered route (admin, empty request): no secrets in data or errors.
if ( ! class_exists( 'WP_REST_Server' ) ) { class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; const EDITABLE = 'POST, PUT, PATCH'; const DELETABLE = 'DELETE'; const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE'; } }
if ( ! function_exists( 'is_user_logged_in' ) ) { function is_user_logged_in() { return get_current_user_id() > 0; } }
$GLOBALS['wpcap_test_routes'] = array();
\PassCaptain\Rest\Controller::register_routes();
foreach ( array( array( \PassCaptain\Mobile\Api::class, 'routes' ), array( \PassCaptain\Admission\Rest::class, 'routes' ), array( \PassCaptain\OfflineScanner\Rest::class, 'routes' ), array( \PassCaptain\Partners\Api::class, 'register_routes' ), array( \PassCaptain\Seating\Rest::class, 'register_routes' ), array( \PassCaptain\WalletPasses\WebService::class, 'routes' ), array( \PassCaptain\Modules\Sms::class, 'register_routes' ) ) as $registrar ) { call_user_func( $registrar ); }
$rest_out = '';
$rest_calls = 0;
foreach ( $GLOBALS['wpcap_test_routes'] as $route => $args ) {
	$endpoints = isset( $args['callback'] ) ? array( $args ) : $args;
	foreach ( $endpoints as $endpoint ) {
		if ( ! is_array( $endpoint ) || empty( $endpoint['callback'] ) ) { continue; }
		$r = new WP_REST_Request( 'GET', $route );
		foreach ( array( 'event_id' => 1, 'occurrence_id' => 1, 'provider' => 'stripe', 'public_id' => str_repeat( 'a', 32 ) ) as $k => $v ) { $r->set_param( $k, $v ); }
		ob_start();
		try {
			$out = call_user_func( $endpoint['callback'], $r );
			$rest_out .= is_wp_error( $out ) ? $out->get_error_message() . json_encode( $out->get_error_data() ) : json_encode( $out instanceof WP_REST_Response ? $out->get_data() : $out );
			++$rest_calls;
		} catch ( Throwable $e ) { $rest_out .= $e->getMessage(); }
		$rest_out .= ob_get_clean();
	}
}
check( "REST responses of all routes contain no secret ($rest_calls calls)", $rest_calls > 40 && array() === w5_leaks( $rest_out, $w5_all ) && false === strpos( $rest_out, 'pcv1:' ) );

// Offline scanner package.
$w5_dev = $wpdb->get_row( "SELECT * FROM {$p}wpcap_scanner_devices ORDER BY device_id DESC LIMIT 1" );
$w5_occ = $wpdb->get_row( $wpdb->prepare( "SELECT o.*,e.name AS event_name,e.status AS event_status FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE o.occurrence_id=(SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d)", $w5_type ) );
$package = $w5_dev && $w5_occ ? \PassCaptain\OfflineScanner\Package::build( $w5_dev, $w5_occ ) : null;
check( 'offline scanner package contains no secret', is_array( $package ) && ! empty( $package['signature'] ) && array() === w5_leaks( json_encode( $package ) . base64_decode( (string) $package['payload'] ), $w5_all ) );

// Wallet pass archive: own test PKI (CA + pass certificate, password protected key) stored as settings.
$w5_dir = sys_get_temp_dir() . '/w5-wallet-' . getmypid();
@mkdir( $w5_dir );
file_put_contents( $w5_dir . '/openssl.cnf', "[req]\ndistinguished_name=dn\n[dn]\n[v3_ca]\nbasicConstraints=critical,CA:true\nkeyUsage=critical,keyCertSign,cRLSign\n[usr]\nbasicConstraints=CA:false\nkeyUsage=critical,digitalSignature\n" );
$w5_cfg = array( 'config' => $w5_dir . '/openssl.cnf', 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA );
$w5_ca_key = openssl_pkey_new( $w5_cfg );
$w5_ca = openssl_csr_sign( openssl_csr_new( array( 'commonName' => 'W5 CA' ), $w5_ca_key, $w5_cfg ), null, $w5_ca_key, 30, $w5_cfg + array( 'x509_extensions' => 'v3_ca' ), 1 );
$w5_key = openssl_pkey_new( $w5_cfg );
$w5_cert = openssl_csr_sign( openssl_csr_new( array( 'commonName' => 'Pass Type ID: pass.test.wpcap', 'UID' => 'pass.test.wpcap', 'organizationalUnitName' => 'ABCDE12345' ), $w5_key, $w5_cfg ), $w5_ca, $w5_ca_key, 30, $w5_cfg + array( 'x509_extensions' => 'usr' ), 2 );
openssl_x509_export( $w5_ca, $w5_ca_pem );
openssl_x509_export( $w5_cert, $w5_cert_pem );
$w5_key_pass = 'W5-' . $w5_tag . '-keypass';
openssl_pkey_export( $w5_key, $w5_key_pem, $w5_key_pass, $w5_cfg );
Settings::update( array( 'wallet_apple_enabled' => 1, 'wallet_apple_pass_type_id' => 'pass.test.wpcap', 'wallet_apple_team_id' => 'ABCDE12345', 'apple_pass_cert' => $w5_cert_pem, 'apple_pass_key' => $w5_key_pem, 'apple_pass_key_password' => $w5_key_pass, 'apple_wwdr_cert' => $w5_ca_pem ) );
array_map( 'unlink', glob( $w5_dir . '/*' ) );
@rmdir( $w5_dir );
$w5_wallet_secrets = $w5_all;
foreach ( array( 'apple_pass_key', 'google_wallet_service_account' ) as $wk ) {
	$material = (string) Settings::secret( $wk );
	if ( preg_match( '/-----BEGIN [A-Z ]*PRIVATE KEY-----\s*(\S{40})/', $material, $mm ) ) { $w5_wallet_secrets[ $wk ] = $mm[1]; }
	if ( preg_match( '/PRIVATE KEY-----\\\\n(\S{40})/', $material, $mm ) ) { $w5_wallet_secrets[ $wk . '_json' ] = $mm[1]; }
}
$w5_pw = (string) Settings::secret( 'apple_pass_key_password' );
unset( $w5_wallet_secrets['apple_pass_key_password'] );
if ( '' !== $w5_pw ) { $w5_wallet_secrets['apple_pass_key_password'] = $w5_pw; }
$pass = \PassCaptain\WalletPasses\AdminPage::test_pass();
$pass_files = is_string( $pass ) && function_exists( 'wpw_unzip' ) ? wpw_unzip( $pass ) : array();
check( 'wallet pass archive contains no private key, key password or provider secret', is_string( $pass ) && $pass_files && array() === w5_leaks( $pass . implode( '', $pass_files ), $w5_wallet_secrets ) );

/* ---- Restore ---- */
putenv( 'PASS_CAPTAIN_SECRET_KEY' );
Vault::reset();
update_option( Settings::OPTION, $w5_raw_before );
Settings::flush();
Redact::reset();
http_reset();

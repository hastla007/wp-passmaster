<?php
/**
 * A191: provider secrets live in an encrypted credential store (PassCaptain\Secrets), never in
 * plaintext in wpcap_settings: migration, key from WPCAP_SECRETS_KEY or the salts, rotation with
 * WPCAP_SECRETS_KEY_PREVIOUS, fail closed on undecryptable entries.
 * A192: automated proof that secrets saved through the admin forms never appear in plaintext in
 * options, audit logs, admin HTML, diagnostics, privacy exports or error messages.
 * Included from run.php (uses check(), make_type()).
 */
// phpcs:ignoreFile
use PassCaptain\Secrets;
use PassCaptain\Settings;
use PassCaptain\Payments\Registry;

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_denied_caps'] = array();

// Other tests continue after this file: everything is restored at the end.
$a191_snapshot = array(
	'secrets' => get_option( Secrets::OPTION, array() ),
	'settings' => get_option( Settings::OPTION, array() ),
	'version' => get_option( Secrets::VERSION_OPTION, 0 ),
);
/** Sets/unsets environment variables (constants cannot be redefined within one test process). */
function a191_env( array $vars ) {
	foreach ( $vars as $name => $value ) { putenv( null === $value ? $name : "$name=$value" ); }
}
a191_env( array( 'WPCAP_SECRETS_KEY' => null, 'WPCAP_SECRETS_KEY_PREVIOUS' => null, 'WPCAP_STRIPE_SECRET_KEY' => null, 'WPCAP_STRIPE_WEBHOOK_SECRET' => null ) );

echo "A191: migration of plaintext secrets\n";
update_option( Secrets::OPTION, array() );
update_option( Secrets::VERSION_OPTION, 0 );
$legacy = get_option( Settings::OPTION, array() );
$legacy['stripe_secret_key'] = 'sk_' . 'live_LEGACYplaintext0001A1b2';
$legacy['stripe_webhook_secret'] = 'whsec_LEGACYplaintext0002';
$legacy['paypal_client_secret'] = '';
$legacy['hold_minutes'] = 11;
update_option( Settings::OPTION, $legacy );
Settings::flush();
check( 'before migration: settings never expose the plaintext, Secrets reads it transitionally', '' === Settings::get( 'stripe_secret_key' ) && ! isset( Settings::all()['stripe_secret_key'][1] ) && 'sk_' . 'live_LEGACYplaintext0001A1b2' === Secrets::get( 'stripe_secret_key' ) && Secrets::migration_pending() );
$ran = Secrets::maybe_upgrade( 'frontend' );
$raw = get_option( Settings::OPTION );
$vault = get_option( Secrets::OPTION );
check( 'migration moves plaintext into the vault and removes all secret keys from wpcap_settings', $ran && ! array_intersect_key( $raw, array_flip( Secrets::KEYS ) ) && 11 === (int) $raw['hold_minutes'] && isset( $vault['stripe_secret_key'], $vault['stripe_webhook_secret'] ) && ! isset( $vault['paypal_client_secret'] ) );
check( 'vault entries are sealed (v1:kid:box) and contain no plaintext', 0 === strpos( $vault['stripe_secret_key'], 'v1:' ) && false === strpos( serialize( $vault ), 'LEGACYplaintext' ) && false === strpos( serialize( $raw ), 'LEGACYplaintext' ) );
check( 'migrated values are readable through the central accessor', 'sk_' . 'live_LEGACYplaintext0001A1b2' === Secrets::get( 'stripe_secret_key' ) && 'whsec_LEGACYplaintext0002' === Secrets::get( 'stripe_webhook_secret' ) && 1 === (int) get_option( Secrets::VERSION_OPTION ) );
$migrated_audit = $wpdb->get_var( "SELECT details FROM {$p}wpcap_audit_logs WHERE action='secrets_migrated' ORDER BY audit_id DESC LIMIT 1" );
check( 'migration is audited with key names only', is_string( $migrated_audit ) && false !== strpos( $migrated_audit, 'stripe_secret_key' ) && false === strpos( $migrated_audit, 'LEGACY' ) );
check( 'migration is idempotent (nothing pending, second run is a no-op)', ! Secrets::migration_pending() && false === Secrets::maybe_upgrade( 'admin' ) && $vault === get_option( Secrets::OPTION ) );
// Plaintext written again later (e.g. older plugin version after a downgrade) is newer and replaces the vault entry.
$raw['stripe_secret_key'] = 'sk_' . 'live_DOWNGRADEvalue000003';
update_option( Settings::OPTION, $raw );
Secrets::maybe_upgrade( 'frontend' );
check( 'plaintext reappearing in wpcap_settings is migrated again and removed', 'sk_' . 'live_DOWNGRADEvalue000003' === Secrets::get( 'stripe_secret_key' ) && ! isset( get_option( Settings::OPTION )['stripe_secret_key'] ) );
$installer_src = file_get_contents( WPCAP_PATH . 'src/Installer.php' ) . file_get_contents( WPCAP_PATH . 'src/Plugin.php' );
check( 'migration hooked into install() and the request-time upgrade (behind run_locked)', false !== strpos( $installer_src, 'Secrets::migrate()' ) && false !== strpos( $installer_src, 'Secrets::maybe_upgrade(' ) && false !== strpos( file_get_contents( WPCAP_PATH . 'src/Secrets.php' ), 'Installer::run_locked(' ) );

echo "A191: Settings::update routes secrets into the vault\n";
Settings::update( array( 'stripe_secret_key' => 'sk_' . 'test_ROUTED0000000000004', 'paypal_client_secret' => 'pp_ROUTED_secret_0005', 'hold_minutes' => 12 ) );
$raw = get_option( Settings::OPTION );
check( 'Settings::update never writes secrets to wpcap_settings', ! array_intersect_key( $raw, array_flip( Secrets::KEYS ) ) && 12 === (int) Settings::get( 'hold_minutes' ) && 'sk_' . 'test_ROUTED0000000000004' === Secrets::get( 'stripe_secret_key' ) && 'pp_ROUTED_secret_0005' === Settings::secret( 'paypal_client_secret' ) );
check( 'Settings::get/all return only the empty default for secrets', '' === Settings::get( 'stripe_secret_key' ) && '' === Settings::all()['paypal_client_secret'] );
Settings::update( array( 'paypal_client_secret' => '' ) );
check( 'empty value removes the vault entry', ! isset( get_option( Secrets::OPTION )['paypal_client_secret'] ) && '' === Secrets::get( 'paypal_client_secret' ) && '' === Secrets::source( 'paypal_client_secret' ) );
a191_env( array( 'WPCAP_STRIPE_SECRET_KEY' => 'sk_' . 'test_FROMENV' ) );
check( 'constant/environment variable still overrides the vault', 'sk_' . 'test_FROMENV' === Secrets::get( 'stripe_secret_key' ) && 'env' === Secrets::source( 'stripe_secret_key' ) );
a191_env( array( 'WPCAP_STRIPE_SECRET_KEY' => null ) );
Settings::update( array( 'paypal_client_id' => 'client-a191' ) );
check( 'non-secret identifiers stay in the settings (constant > env > option lookup)', 'client-a191' === Settings::secret( 'paypal_client_id' ) && 'client-a191' === get_option( Settings::OPTION )['paypal_client_id'] && ! Secrets::is_secret( 'paypal_client_id' ) );

echo "A191: integrity\n";
$vault = get_option( Secrets::OPTION );
$tampered = $vault;
$tampered['stripe_secret_key'] = substr( $vault['stripe_secret_key'], 0, -6 ) . ( 'AAAA' === substr( $vault['stripe_secret_key'], -6, 4 ) ? 'BBBB' : 'AAAA' ) . substr( $vault['stripe_secret_key'], -2 );
update_option( Secrets::OPTION, $tampered );
check( 'a tampered box is rejected (fail closed)', '' === Secrets::get( 'stripe_secret_key' ) && 'undecryptable' === Secrets::source( 'stripe_secret_key' ) && false === Secrets::stored( 'stripe_secret_key' ) );
$swapped = $vault;
$swapped['stripe_secret_key'] = $vault['stripe_webhook_secret'];
update_option( Secrets::OPTION, $swapped );
check( 'a box copied to another name is rejected (name is bound into the box)', '' === Secrets::get( 'stripe_secret_key' ) );
update_option( Secrets::OPTION, $vault );
check( 'restored box decrypts again', 'sk_' . 'test_ROUTED0000000000004' === Secrets::get( 'stripe_secret_key' ) );

echo "A191: key from WPCAP_SECRETS_KEY, rotation and fail closed\n";
check( 'without WPCAP_SECRETS_KEY the key is derived from the salts', 'salts' === Secrets::key_source() && ! Secrets::rewrap_pending() );
ob_start(); Secrets::admin_notice(); $notice = ob_get_clean();
check( 'admin notice recommends the dedicated constant', false !== strpos( $notice, 'WPCAP_SECRETS_KEY' ) && false !== strpos( $notice, 'notice-warning' ) && false === strpos( $notice, 'notice-error' ) );
a191_env( array( 'WPCAP_SECRETS_KEY' => 'first-dedicated-key-0123456789abcdef' ) );
check( 'switching from salts to WPCAP_SECRETS_KEY keeps entries readable and marks them for re-encryption', 'constant' === Secrets::key_source() && 'sk_' . 'test_ROUTED0000000000004' === Secrets::get( 'stripe_secret_key' ) && Secrets::rewrap_pending() );
check( 'rewrap is not run on frontend requests', false === Secrets::maybe_upgrade( 'frontend' ) && Secrets::rewrap_pending() );
$before = get_option( Secrets::OPTION );
Secrets::maybe_upgrade( 'admin' );
$after = get_option( Secrets::OPTION );
check( 'admin request re-encrypts with the dedicated key', ! Secrets::rewrap_pending() && $before['stripe_secret_key'] !== $after['stripe_secret_key'] && 'sk_' . 'test_ROUTED0000000000004' === Secrets::get( 'stripe_secret_key' ) && 'whsec_LEGACYplaintext0002' === Secrets::get( 'stripe_webhook_secret' ) );
ob_start(); Secrets::admin_notice(); $notice = ob_get_clean();
check( 'no salt warning once the dedicated key is set', '' === trim( $notice ) );
// Rotation: new key, old one as WPCAP_SECRETS_KEY_PREVIOUS.
a191_env( array( 'WPCAP_SECRETS_KEY' => 'second-dedicated-key-fedcba9876543210', 'WPCAP_SECRETS_KEY_PREVIOUS' => 'first-dedicated-key-0123456789abcdef' ) );
check( 'rotation: entries stay readable with the previous key', 'sk_' . 'test_ROUTED0000000000004' === Secrets::get( 'stripe_secret_key' ) && Secrets::rewrap_pending() );
$rewrap = Secrets::rewrap();
check( 'rotation: rewrap re-encrypts every entry with the new key', in_array( 'stripe_secret_key', $rewrap['rewrapped'], true ) && ! $rewrap['failed'] && ! Secrets::rewrap_pending() );
a191_env( array( 'WPCAP_SECRETS_KEY_PREVIOUS' => null ) );
check( 'rotation: readable without the previous key afterwards', 'sk_' . 'test_ROUTED0000000000004' === Secrets::get( 'stripe_secret_key' ) );
$rewrap_audit = $wpdb->get_var( "SELECT details FROM {$p}wpcap_audit_logs WHERE action='secrets_rewrapped' ORDER BY audit_id DESC LIMIT 1" );
check( 'rewrap is audited with key names only', is_string( $rewrap_audit ) && false === strpos( $rewrap_audit, 'ROUTED' ) );

// Key lost / changed without WPCAP_SECRETS_KEY_PREVIOUS.
Settings::update( array( 'stripe_webhook_secret' => 'whsec_FAILCLOSED_0006', 'enabled_providers' => array( 'manual', 'stripe' ) ) );
Registry::reset();
check( 'gateway available with readable secrets', Registry::get( 'stripe' )->is_available( 'EUR' ) );
a191_env( array( 'WPCAP_SECRETS_KEY' => 'unrelated-wrong-key-000000000000000000' ) );
Registry::reset();
$stripe = Registry::get( 'stripe' );
check( 'fail closed: wrong key makes the gateway "not configured"', '' === Secrets::get( 'stripe_secret_key' ) && ! $stripe->is_available( 'EUR' ) && in_array( 'stripe_secret_key', Secrets::undecryptable(), true ) );
$a191_body = json_encode( array( 'id' => 'evt_a191', 'type' => 'checkout.session.completed', 'data' => array( 'object' => array() ) ) );
$a191_t = time();
$a191_req = new WP_REST_Request( 'POST', '/pass-captain/v1/payments/stripe/webhook' );
$a191_req->set_body( $a191_body );
$a191_req->set_header( 'Stripe-Signature', "t=$a191_t,v1=" . hash_hmac( 'sha256', $a191_t . '.' . $a191_body, 'whsec_FAILCLOSED_0006' ) );
$a191_verified = $stripe->verify_webhook( $a191_req );
check( 'fail closed: webhooks signed with the (unreadable) secret are rejected', is_wp_error( $a191_verified ) && 'wpcap_stripe_signature' === $a191_verified->get_error_code() );
ob_start(); Secrets::admin_notice(); $notice = ob_get_clean();
check( 'fail closed: admin notice names the affected settings, no values', false !== strpos( $notice, 'notice-error' ) && false !== strpos( $notice, 'Stripe Secret Key' ) && false !== strpos( $notice, 'WPCAP_SECRETS_KEY_PREVIOUS' ) && false === strpos( $notice, 'ROUTED' ) && false === strpos( $notice, 'FAILCLOSED' ) );
check( 'fail closed: field status asks for re-entry, no hint', false !== strpos( Secrets::status_text( 'stripe_secret_key' ), 'nicht entschlüsselbar' ) && '' === Secrets::hint( 'stripe_secret_key' ) );
$a191_rewrap = Secrets::rewrap();
check( 'fail closed: rewrap leaves undecryptable entries untouched', in_array( 'stripe_secret_key', $a191_rewrap['failed'], true ) && ! in_array( 'stripe_secret_key', $a191_rewrap['rewrapped'], true ) );
// Re-entry through the admin form repairs it.
// (The form resets unrelated fields to their submitted values; wpcap_settings is restored at the end.)
PassCaptain\Admin\SettingsPage::save( array( 'enabled_providers' => array( 'manual', 'stripe' ), 'stripe_secret_key' => 'sk_' . 'test_REENTERED00000000007', 'stripe_webhook_secret' => 'whsec_REENTERED_0008' ) );
Registry::reset();
check( 're-entry after key loss makes the gateway available again', 'sk_' . 'test_REENTERED00000000007' === Secrets::get( 'stripe_secret_key' ) && Registry::get( 'stripe' )->is_available( 'EUR' ) && ! in_array( 'stripe_secret_key', Secrets::undecryptable(), true ) );
a191_env( array( 'WPCAP_SECRETS_KEY' => null ) );

/* =============================================================================================
 * A192: no plaintext anywhere
 * =========================================================================================== */
echo "A192: secrets saved through the admin forms never appear in plaintext\n";
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); } }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . ltrim( $p, '/' ); } }
if ( ! function_exists( 'checked' ) ) { function checked( $a, $b = true, $echo = true ) { $r = $a == $b ? ' checked="checked"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'selected' ) ) { function selected( $a, $b = true, $echo = true ) { $r = $a == $b ? ' selected="selected"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'esc_textarea' ) ) { function esc_textarea( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce" value="n">'; } }
if ( ! function_exists( 'submit_button' ) ) { function submit_button( $t = '', $type = 'primary', $name = 'submit', $wrap = true ) { echo '<button>' . esc_html( $t ) . '</button>'; } }
if ( ! function_exists( 'register_rest_route' ) ) { function register_rest_route( $ns, $route, $args ) { $GLOBALS['wpcap_test_routes'][ $ns . $route ] = $args; return true; } }

// Start from an empty vault sealed with a dedicated WPCAP_SECRETS_KEY (the salt key is only a
// fallback; with a dedicated key, losing it must make the entries unreadable).
a191_env( array( 'WPCAP_SECRETS_KEY' => 'a192-original-dedicated-key-0000000000', 'WPCAP_SECRETS_KEY_PREVIOUS' => null ) );
update_option( Secrets::OPTION, array() );
$a192_log = tempnam( sys_get_temp_dir(), 'a192log' );
$a192_old_log = ini_set( 'error_log', $a192_log );
$a192_audit_from = (int) $wpdb->get_var( "SELECT COALESCE(MAX(audit_id),0) FROM {$p}wpcap_audit_logs" );
$a192_secrets = array(
	'stripe_secret_key' => 'sk_' . 'live_A192stripeSECRET000001',
	'stripe_webhook_secret' => 'whsec_A192webhookSECRET0002',
	'paypal_client_secret' => 'PayPal_A192_clientSECRET_0003',
	'mollie_api_key' => 'test_A192mollieSECRETkey00000004',
	'twilio_auth_token' => 'TwilioA192SECRETtoken00000005',
	'sms_http_secret' => 'Bearer HttpA192SECRET0006',
	'apple_pass_key' => "-----BEGIN PRIVATE KEY-----\nA192appleKEYSECRET0007\n-----END PRIVATE KEY-----",
	'apple_pass_key_password' => 'WalletA192pwSECRET0008',
	'google_wallet_service_account' => '{"client_email":"x@y.iam.gserviceaccount.com","private_key":"A192googleSECRET0009"}',
);
// Distinctive fragments: none may appear anywhere (also not partially encoded in JSON/HTML).
$a192_needles = array( 'A192stripeSECRET', 'A192webhookSECRET', 'A192_clientSECRET', 'A192mollieSECRET', 'TwilioA192SECRET', 'HttpA192SECRET', 'A192appleKEYSECRET', 'WalletA192pwSECRET', 'A192googleSECRET' );
$a192_leaks = static function ( $haystack ) use ( $a192_needles ) {
	$haystack = is_string( $haystack ) ? $haystack : serialize( $haystack );
	return array_values( array_filter( $a192_needles, static fn( $n ) => false !== strpos( $haystack, $n ) ) );
};

$a192_saved = PassCaptain\Admin\SettingsPage::save( array(
	'enabled_providers' => array( 'manual', 'stripe', 'paypal', 'mollie' ), 'stripe_mode' => 'live', 'paypal_mode' => 'live', 'paypal_client_id' => 'A192-client-id', 'paypal_webhook_id' => 'WH-A192',
	'stripe_secret_key' => $a192_secrets['stripe_secret_key'], 'stripe_webhook_secret' => $a192_secrets['stripe_webhook_secret'], 'paypal_client_secret' => $a192_secrets['paypal_client_secret'],
) );
$a192_mollie = PassCaptain\Modules\Mollie::save( array( 'mollie_api_key' => $a192_secrets['mollie_api_key'], 'methods' => array( 'ideal' ) ) );
$a192_sms = PassCaptain\Modules\Sms::save( array( 'provider' => 'twilio', 'twilio_account_sid' => 'AC' . str_repeat( 'a1', 16 ), 'twilio_auth_token' => $a192_secrets['twilio_auth_token'], 'sms_http_secret' => $a192_secrets['sms_http_secret'], 'twilio_from' => '+4915100000000', 'default_country_code' => '+49', 'allowed_prefixes' => '+49' ) );
$a192_wallet = PassCaptain\WalletPasses\AdminPage::save( array( 'wallet_apple_enabled' => '1', 'wallet_google_enabled' => '1', 'apple_pass_key' => $a192_secrets['apple_pass_key'], 'apple_pass_key_password' => $a192_secrets['apple_pass_key_password'], 'google_wallet_service_account' => $a192_secrets['google_wallet_service_account'] ) );
check( 'all four admin forms saved', ! is_wp_error( $a192_saved ) && ! is_wp_error( $a192_mollie ) && ! is_wp_error( $a192_sms ) && ! is_wp_error( $a192_wallet ) );
$a192_ok = true;
foreach ( $a192_secrets as $key => $value ) { $a192_ok = $a192_ok && $value === Secrets::get( $key ) && 'vault' === Secrets::source( $key ); }
check( 'every saved secret is readable through Secrets::get() (stored in the vault)', $a192_ok );
check( 'form handler result carries no secret values', ! $a192_leaks( array( $a192_saved, $a192_mollie, $a192_sms, $a192_wallet ) ) && in_array( 'stripe_secret_key', $a192_saved['saved']['secrets_updated'], true ) );
check( 'not in get_option( wpcap_settings ) (serialized)', ! $a192_leaks( get_option( Settings::OPTION ) ) && ! array_intersect_key( get_option( Settings::OPTION ), $a192_secrets ) );
check( 'not in the vault option itself (only sealed boxes)', ! $a192_leaks( get_option( Secrets::OPTION ) ) );
check( 'not in any option or transient', ! $a192_leaks( $GLOBALS['wpcap_test_options'] ) && ! $a192_leaks( $GLOBALS['wpcap_test_transients'] ) );
check( 'not in Settings::all() / Settings::get()', ! $a192_leaks( Settings::all() ) && '' === Settings::get( 'mollie_api_key' ) );
$a192_audit = $wpdb->get_col( $wpdb->prepare( "SELECT details FROM {$p}wpcap_audit_logs WHERE audit_id>%d", $a192_audit_from ) );
check( 'not in audit logs (only key names are recorded)', $a192_audit && ! $a192_leaks( implode( "\n", $a192_audit ) ) && false !== strpos( implode( "\n", $a192_audit ), 'stripe_secret_key' ) );

echo "A192: admin HTML shows only the masked status\n";
$a192_render = static function ( callable $render ) {
	ob_start();
	try { $render(); } catch ( Throwable $e ) { echo 'EXCEPTION ' . $e->getMessage(); }
	return ob_get_clean();
};
// The settings screen has tabs (A099): render every tab and every payment provider section.
$a192_settings_all = static function () use ( $a192_render ) {
	$html = '';
	foreach ( array( array( 'general', '' ), array( 'admission', '' ), array( 'payments', '' ), array( 'payments', 'manual' ), array( 'payments', 'stripe' ), array( 'payments', 'paypal' ), array( 'legal', '' ), array( 'invoices', '' ) ) as list( $tab, $section ) ) {
		$_GET['tab'] = $tab; $_GET['section'] = $section;
		$html .= $a192_render( array( PassCaptain\Admin\SettingsPage::class, 'render' ) );
	}
	unset( $_GET['tab'], $_GET['section'] );
	return $html;
};
$a192_html = array(
	'settings' => $a192_settings_all(),
	'mollie' => $a192_render( array( PassCaptain\Modules\Mollie::class, 'render_admin' ) ),
	'sms' => $a192_render( array( PassCaptain\Modules\Sms::class, 'render_admin' ) ),
	'wallet' => $a192_render( array( PassCaptain\WalletPasses\AdminPage::class, 'render' ) ),
);
check( 'admin pages render', ! array_filter( $a192_html, static fn( $h ) => false !== strpos( $h, 'EXCEPTION' ) || '' === $h ) );
check( 'no secret in settings, Mollie, SMS or Wallet admin HTML', ! $a192_leaks( implode( "\n", $a192_html ) ) );
check( 'settings page shows "endet auf" with the last four characters and a delete checkbox', false !== strpos( $a192_html['settings'], 'endet auf …0001' ) && false !== strpos( $a192_html['settings'], 'name="stripe_secret_key__clear"' ) && false !== strpos( $a192_html['settings'], 'Gespeicherten Wert löschen' ) && 1 === preg_match( '/name="stripe_secret_key"[^>]*value=""/', $a192_html['settings'] ) );
check( 'module pages show the masked status too', false !== strpos( $a192_html['mollie'], 'endet auf …0004' ) && false !== strpos( $a192_html['sms'], 'endet auf …0005' ) && false !== strpos( $a192_html['wallet'], 'name="apple_pass_key_clear"' ) );

echo "A192: diagnostics, REST, privacy export, errors\n";
$a192_debug = Secrets::debug_information( array() );
check( 'Site Health info lists the source of each secret, no values', isset( $a192_debug['pass-captain-secrets']['fields']['stripe_secret_key'] ) && ! $a192_leaks( $a192_debug ) );
check( 'wallet status (diagnostics) contains no secret', ! $a192_leaks( json_encode( PassCaptain\WalletPasses\AdminPage::status() ) ) );
// Every REST route of the plugin (source scan: register_rest_route( ns, '/route' ... )).
$a192_routes = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WPCAP_PATH . 'src', FilesystemIterator::SKIP_DOTS ) ) as $a192_file ) {
	if ( '.php' !== substr( (string) $a192_file, -4 ) ) { continue; }
	preg_match_all( "#register_rest_route\(\s*[^,]+,\s*'([^']+)'#", (string) file_get_contents( (string) $a192_file ), $a192_m );
	$a192_routes = array_merge( $a192_routes, $a192_m[1] );
}
check( 'no REST route exposes settings or secrets', count( $a192_routes ) > 20 && ! preg_grep( '#/(settings|secrets?|credentials|diagnostics?)\b#i', $a192_routes ) );
$a192_rest_src = '';
foreach ( array( 'Rest/Controller.php', 'Mobile/Api.php' ) as $a192_rel ) { $a192_rest_src .= (string) @file_get_contents( WPCAP_PATH . 'src/' . $a192_rel ); }
check( 'REST controllers never return Settings::all() or the vault', '' !== $a192_rest_src && false === strpos( $a192_rest_src, 'Settings::all()' ) && false === strpos( $a192_rest_src, 'wpcap_secrets' ) );
$a192_type = make_type( 10, 5, 2500 );
$a192_order_res = PassCaptain\Orders::create( array( 'email' => 'a192@example.com', 'items' => array( array( 'ticket_type_id' => $a192_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a192-order' ), array( 'payment_provider' => 'stripe', 'hold_minutes' => 30 ) );
$a192_order = (array) PassCaptain\Orders::get( $a192_order_res['order_id'] );
check( 'privacy export contains no secret', ! $a192_leaks( json_encode( PassCaptain\Privacy::export( 'a192@example.com' ) ) ) );
Registry::reset();
http_reset();
http_push( array( 'error' => array( 'message' => 'Invalid API Key provided: sk_live_****0001' ) ), 401, '/v1/checkout/sessions' );
$a192_stripe_err = Registry::get( 'stripe' )->create_payment( $a192_order, array_map( static fn( $row ) => (array) $row, PassCaptain\Orders::items_for_order( $a192_order['order_id'] ) ) );
$a192_auth = http_requests( '/v1/checkout/sessions' )[0]['headers']['Authorization'] ?? '';
check( 'Stripe API error: secret was sent only in the Authorization header, not in the error', is_wp_error( $a192_stripe_err ) && 'Bearer ' . $a192_secrets['stripe_secret_key'] === $a192_auth && ! $a192_leaks( $a192_stripe_err->get_error_message() . json_encode( $a192_stripe_err->get_error_data() ) ) );
http_reset();
$a192_stripe_http = Registry::get( 'stripe' )->create_payment( $a192_order, array() );
check( 'Stripe transport error contains no secret', is_wp_error( $a192_stripe_http ) && ! $a192_leaks( $a192_stripe_http->get_error_message() ) );
http_push( array( 'status' => 401, 'title' => 'Unauthorized Request', 'detail' => 'Missing authentication, or failed to authenticate' ), 401 );
$a192_mollie_err = ( new PassCaptain\Mollie\MollieGateway() )->create_payment( $a192_order, array() );
check( 'Mollie API error contains no secret', is_wp_error( $a192_mollie_err ) && ! $a192_leaks( $a192_mollie_err->get_error_message() ) );
$a192_bad_key = PassCaptain\Modules\Mollie::save( array( 'mollie_api_key' => 'sk_' . 'live_A192mollieSECRETwrongFormat' ) );
check( 'validation error for a rejected key does not echo it', is_wp_error( $a192_bad_key ) && ! $a192_leaks( $a192_bad_key->get_error_message() ) && $a192_secrets['mollie_api_key'] === Secrets::get( 'mollie_api_key' ) );
$a192_order_dump = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}wpcap_orders WHERE order_id=%d", $a192_order['order_id'] ), ARRAY_A );
check( 'failed payment attempts leave no secret on the order', ! $a192_leaks( json_encode( $a192_order_dump ) ) );

echo "A192: decryption failure\n";
a191_env( array( 'WPCAP_SECRETS_KEY' => 'a192-lost-key-replacement-000000000000' ) );
Registry::reset();
$a192_twilio = new PassCaptain\Sms\TwilioProvider();
$a192_apple = PassCaptain\WalletPasses\Credentials::apple();
check( 'after a key change every provider counts as not configured', ! Registry::get( 'stripe' )->is_available( 'EUR' ) && ! Registry::get( 'paypal' )->is_available( 'EUR' ) && ! ( new PassCaptain\Mollie\MollieGateway() )->is_available( 'EUR' ) && ! $a192_twilio->is_configured() && is_wp_error( $a192_apple ) );
check( 'every vault entry is reported as undecryptable', ! array_diff( array_keys( $a192_secrets ), Secrets::undecryptable() ) );
http_reset();
$a192_unconfigured = Registry::get( 'stripe' )->create_payment( $a192_order, array() );
check( 'no API request is made without a readable key; error names no value', is_wp_error( $a192_unconfigured ) && ! http_requests() && ! $a192_leaks( $a192_unconfigured->get_error_message() ) );
ob_start(); Secrets::admin_notice(); $a192_notice = ob_get_clean();
$a192_html_failed = $a192_settings_all();
check( 'notice and form show the failure without values', false !== strpos( $a192_notice, 'notice-error' ) && false !== strpos( $a192_html_failed, 'nicht entschlüsselbar' ) && ! $a192_leaks( $a192_notice . $a192_html_failed ) );
check( 'Site Health info reports "nicht entschlüsselbar"', false !== strpos( json_encode( Secrets::debug_information( array() ), JSON_UNESCAPED_UNICODE ), 'nicht entschlüsselbar' ) );
a191_env( array( 'WPCAP_SECRETS_KEY' => 'a192-original-dedicated-key-0000000000' ) );
check( 'with the original key everything is readable again (nothing was destroyed)', $a192_secrets['stripe_secret_key'] === Secrets::get( 'stripe_secret_key' ) && ! Secrets::undecryptable() );

ini_set( 'error_log', false === $a192_old_log ? '' : $a192_old_log );
$a192_logged = (string) @file_get_contents( $a192_log );
@unlink( $a192_log );
check( 'nothing secret was written to the PHP error log', ! $a192_leaks( $a192_logged ) );
// Whole-database scan: no plugin table holds a secret (orders, jobs, audit, queues, ...).
$a192_db_leaks = array();
foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', addcslashes( $p, '_%\\' ) . '%' ) ) as $a192_table ) {
	foreach ( (array) $wpdb->get_results( "SELECT * FROM `$a192_table`", ARRAY_A ) as $a192_row ) {
		if ( $a192_leaks( json_encode( $a192_row ) ) ) { $a192_db_leaks[] = $a192_table; break; }
	}
}
check( 'no database table contains a secret in plaintext', ! $a192_db_leaks );

/* ---- restore ---- */
a191_env( array( 'WPCAP_SECRETS_KEY' => null, 'WPCAP_SECRETS_KEY_PREVIOUS' => null ) );
update_option( Secrets::OPTION, $a191_snapshot['secrets'] );
update_option( Settings::OPTION, $a191_snapshot['settings'] );
update_option( Secrets::VERSION_OPTION, $a191_snapshot['version'] ?: Secrets::VERSION );
Settings::flush();
Registry::reset();

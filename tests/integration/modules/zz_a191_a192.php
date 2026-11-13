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
$a191_req = new WP_REST_Request( 'POST', '/wp-pass-captain/v1/payments/stripe/webhook' );
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

/* ---- restore ---- */
a191_env( array( 'WPCAP_SECRETS_KEY' => null, 'WPCAP_SECRETS_KEY_PREVIOUS' => null ) );
update_option( Secrets::OPTION, $a191_snapshot['secrets'] );
update_option( Settings::OPTION, $a191_snapshot['settings'] );
update_option( Secrets::VERSION_OPTION, $a191_snapshot['version'] ?: Secrets::VERSION );
Settings::flush();
Registry::reset();

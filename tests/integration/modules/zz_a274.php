<?php
/**
 * A274: the Stripe mode (test/live) is enforced – a key of the other mode makes Stripe unavailable,
 * the save reports it, webhooks of the other mode have no effect, the test mode is visible in the
 * admin and in the checkout titles, and legacy installs with a live key are aligned on upgrade.
 *
 * Included from run.php (uses check(), make_type(), tickets() and the HTTP/REST fakes of payments.php).
 */
// phpcs:ignoreFile
use PassCaptain\Admin\SettingsPage;
use PassCaptain\Installer;
use PassCaptain\Orders;
use PassCaptain\Payments\PayPalGateway;
use PassCaptain\Payments\Registry;
use PassCaptain\Payments\StripeGateway;
use PassCaptain\Payments\Webhooks;
use PassCaptain\Secrets;
use PassCaptain\Settings;

if ( ! function_exists( 'rest_ensure_response' ) ) { function rest_ensure_response( $r ) { return $r; } }

global $wpdb;
$p = $wpdb->prefix;

if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr_e' ) ) { function esc_attr_e( $t, $d = null ) { echo esc_attr( $t ); } }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . ltrim( $p, '/' ); } }
if ( ! function_exists( 'checked' ) ) { function checked( $a, $b = true, $echo = true ) { $r = $a == $b ? ' checked="checked"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'selected' ) ) { function selected( $a, $b = true, $echo = true ) { $r = $a == $b ? ' selected="selected"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'esc_textarea' ) ) { function esc_textarea( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce" value="n">'; } }
if ( ! function_exists( 'submit_button' ) ) { function submit_button( $t = '', $type = 'primary', $name = 'submit', $wrap = true ) { echo '<button>' . esc_html( $t ) . '</button>'; } }

echo "A274: Stripe mode and key must fit\n";
$a274_before = array( 'stripe_mode' => Settings::get( 'stripe_mode' ), 'stripe_secret_key' => Secrets::get( 'stripe_secret_key' ), 'stripe_webhook_secret' => Secrets::get( 'stripe_webhook_secret' ), 'paypal_mode' => Settings::get( 'paypal_mode' ), 'enabled_providers' => Settings::get( 'enabled_providers' ) );
Settings::update( array( 'stripe_mode' => 'live', 'stripe_secret_key' => 'sk_test_a274', 'stripe_webhook_secret' => 'whsec_test', 'enabled_providers' => array( 'manual', 'stripe' ) ) );
Registry::reset();
$a274_gw = new StripeGateway();
check( 'A274: "Live" with a test key: Stripe is not available (no tickets without a real payment)', ! $a274_gw->is_available( 'EUR' ) && ! isset( Registry::enabled( 'EUR' )['stripe'] ) );
check( 'A274: the mismatch is explained for the admin', false !== strpos( StripeGateway::mode_problem(), 'sk_test_' ) );
Settings::update( array( 'stripe_mode' => 'test' ) );
check( 'A274: "Test" with a test key is available', $a274_gw->is_available( 'EUR' ) && '' === StripeGateway::mode_problem() );
Settings::update( array( 'stripe_secret_key' => 'sk_live_a274' ) );
check( 'A274: "Test" with a live key is not available either', ! $a274_gw->is_available( 'EUR' ) && '' !== StripeGateway::mode_problem() );
Settings::update( array( 'stripe_mode' => 'live', 'stripe_secret_key' => 'pk_live_a274' ) );
check( 'A274: a publishable key is no secret key', ! $a274_gw->is_available( 'EUR' ) && false !== strpos( StripeGateway::mode_problem(), 'kein Secret Key' ) );
Settings::update( array( 'stripe_secret_key' => 'rk_live_a274' ) );
check( 'A274: a restricted live key fits "Live"', $a274_gw->is_available( 'EUR' ) );

$a274_saved = SettingsPage::save( array( 'wpcap_tab' => 'payments', 'wpcap_section' => 'stripe', 'stripe_mode' => 'live', 'stripe_secret_key' => 'sk_test_a274save' ) );
check( 'A274: saving "Live" with a test key reports the mismatch (and never echoes the key)', ! is_wp_error( $a274_saved ) && 1 === count( array_filter( $a274_saved['errors'], static fn( $e ) => false !== strpos( $e, 'Testschlüssel' ) ) ) && false === strpos( implode( ' ', $a274_saved['errors'] ), 'a274save' ) );
$a274_saved = SettingsPage::save( array( 'wpcap_tab' => 'payments', 'wpcap_section' => 'stripe', 'stripe_mode' => 'test' ) );
check( 'A274: switching the mode to fit the stored key saves without error', ! is_wp_error( $a274_saved ) && ! $a274_saved['errors'] && $a274_gw->is_available( 'EUR' ) );

echo "A274: webhooks of the other mode have no effect\n";
Settings::update( array( 'stripe_mode' => 'live', 'stripe_secret_key' => 'sk_live_a274' ) );
http_reset();
$a274_o = provider_order( 'stripe', make_type( 10, 10, 2000 ), 1, 'a274-live' );
$a274_test_event = stripe_session_event( 'evt_a274_test', 'checkout.session.completed', $a274_o, array( 'id' => 'cs_a274', 'payment_intent' => 'pi_a274' ) );
$a274_test_event['livemode'] = false;
$a274_r = Webhooks::handle( stripe_request( $a274_test_event ) );
check( 'A274: a test event at a live shop is acknowledged but confirms nothing', ! is_wp_error( $a274_r ) && 'pending' === Orders::get( $a274_o['order_id'] )->payment_status && ! tickets( $a274_o['order_id'] ) );
check( 'A274: the mismatch is audited', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='stripe_webhook_mode_mismatch'" ) > 0 );
$a274_live_event = stripe_session_event( 'evt_a274_live', 'checkout.session.completed', $a274_o, array( 'id' => 'cs_a274', 'payment_intent' => 'pi_a274' ) );
$a274_live_event['livemode'] = true;
Webhooks::handle( stripe_request( $a274_live_event ) );
check( 'A274: a live event at a live shop confirms the payment', 'paid' === Orders::get( $a274_o['order_id'] )->payment_status && 1 === count( tickets( $a274_o['order_id'], 'valid' ) ) );

echo "A274: test mode is visible\n";
check( 'A274: live Stripe title has no test mode hint', false === strpos( $a274_gw->title(), 'Testmodus' ) );
Settings::update( array( 'stripe_mode' => 'test', 'stripe_secret_key' => 'sk_test_a274' ) );
check( 'A274: test mode Stripe title says "Testmodus" (checkout)', false !== strpos( $a274_gw->title(), 'Testmodus' ) );
Settings::update( array( 'paypal_mode' => 'sandbox' ) );
$a274_pp = new PayPalGateway();
check( 'A274: PayPal defaults to the sandbox and says so', 'sandbox' === Settings::defaults()['paypal_mode'] && false !== strpos( $a274_pp->title(), 'Sandbox' ) );
Settings::update( array( 'paypal_mode' => 'live' ) );
check( 'A274: live PayPal title is plain', 'PayPal' === $a274_pp->title() );
ob_start();
SettingsPage::render( 'payments', 'stripe' );
$a274_html = ob_get_clean();
check( 'A274: the Stripe settings show the test mode notice', false !== strpos( $a274_html, 'Testmodus aktiv' ) );
Settings::update( array( 'stripe_mode' => 'live' ) );
ob_start();
SettingsPage::render( 'payments', 'stripe' );
$a274_html = ob_get_clean();
check( 'A274: the Stripe settings show the key/mode mismatch', false !== strpos( $a274_html, 'notice-error' ) && false === strpos( $a274_html, 'Testmodus aktiv' ) );

echo "A274: upgrade aligns legacy installs\n";
Settings::update( array( 'stripe_mode' => 'test', 'stripe_secret_key' => 'sk_live_a274' ) );
check( 'A274: fresh install: nothing aligned', false === Installer::align_legacy_stripe_mode( '' ) && 'test' === Settings::get( 'stripe_mode' ) );
check( 'A274: from 3.10.15 on nothing is aligned', false === Installer::align_legacy_stripe_mode( '3.10.15' ) && 'test' === Settings::get( 'stripe_mode' ) );
check( 'A274: upgrade with a live key and the untouched default switches to "live"', true === Installer::align_legacy_stripe_mode( '3.10.9+1.0' ) && 'live' === Settings::get( 'stripe_mode' ) && $a274_gw->is_available( 'EUR' ) );
Settings::update( array( 'stripe_mode' => 'live', 'stripe_secret_key' => 'sk_test_a274' ) );
check( 'A274: a test key with "live" is left alone (shown as error instead)', false === Installer::align_legacy_stripe_mode( '3.10.9' ) && 'live' === Settings::get( 'stripe_mode' ) && ! $a274_gw->is_available( 'EUR' ) );

Settings::update( $a274_before );
Registry::reset();
http_reset();

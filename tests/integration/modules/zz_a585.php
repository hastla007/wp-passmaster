<?php
/**
 * A585: an expired Apple certificate is "not configured" (no broken passes), and administrators
 * are warned 30 days ahead (Site Health + notice on every admin screen). Included from run.php.
 */
// phpcs:ignoreFile
use PassCaptain\Orders;
use PassCaptain\WalletPasses\AdminPage;
use PassCaptain\WalletPasses\AppleProvider;
use PassCaptain\WalletPasses\Credentials;
use PassCaptain\WalletPasses\Health;
use PassCaptain\WalletPasses\Service;

require_once __DIR__ . '/r7/wallet_fixture.php';
echo "A585: expired / expiring Apple certificates\n";
$a585_keys = static function () { return array_column( Health::issues(), 'status', 'key' ); };

// Expiring in 10 days: still usable, warned everywhere.
wpr7_wallet_enable( 10 );
check( 'A585: certificate valid for 10 more days is still configured', ( new AppleProvider() )->is_configured() && is_array( Credentials::apple() ) );
check( 'A585: expiring certificate is a Site Health recommendation', 'recommended' === ( $a585_keys()['apple_cert_expiring'] ?? '' ) );
$_GET['page'] = 'wpcap-orders';
ob_start(); Health::admin_notice(); $a585_notice = ob_get_clean();
check( 'A585: expiry warning shown on other admin screens too (30 days ahead)', false !== strpos( $a585_notice, 'läuft am' ) && false !== strpos( $a585_notice, 'notice-warning' ) );

// Expired: Apple counts as not configured, no .pkpass with an invalid signature is mailed.
wpr7_wallet_enable( -5 );
$a585_err = Credentials::apple();
check( 'A585: expired certificate → credentials error, Apple not configured', is_wp_error( $a585_err ) && 'wpcap_wallet_apple_cert_expired' === $a585_err->get_error_code() && ! ( new AppleProvider() )->is_configured() && null === Service::provider( 'apple' ) );
check( 'A585: expired certificate is critical in Site Health', 'critical' === ( $a585_keys()['apple_cert_expired'] ?? '' ) && 'critical' === Health::test()['status'] );
$a585_status = AdminPage::status();
check( 'A585: Wallet status page still shows the certificate dates with the error', ! $a585_status['apple']['configured'] && $a585_status['apple']['cert'] && $a585_status['apple']['cert']['valid_to'] < time() && false !== strpos( $a585_status['apple']['error'], 'abgelaufen' ) );
$a585_type = make_type( 10, 10, 0 );
http_reset();
Orders::create( array( 'email' => 'a585@example.com', 'items' => array( array( 'ticket_type_id' => $a585_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a585-1' ) );
$a585_mail = wpr7_ticket_mail( 'a585@example.com' );
check( 'A585: ticket mail carries no .pkpass while the certificate is expired (Google link still)', ! array_filter( array_keys( $a585_mail['files'] ?? array() ), static fn( $n ) => str_ends_with( $n, '.pkpass' ) ) && false === strpos( $a585_mail['body'], 'Apple Wallet' ) && false !== strpos( $a585_mail['body'], 'Google Wallet' ) );
unset( $_GET['page'] );
wpr7_wallet_disable();

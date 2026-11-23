<?php
/**
 * A490: after an exchange the new ticket gets wallet passes and the exchange mail carries the
 * wallet links; the old ticket's passes are voided. Included from run.php.
 */
// phpcs:ignoreFile
use PassCaptain\Exchange\Policy;
use PassCaptain\Exchange\Service as Exchange;
use PassCaptain\Orders;
use PassCaptain\WalletPasses\Service;
use PassCaptain\WalletPasses\Store;

require_once __DIR__ . '/r7/wallet_fixture.php';
echo "A490: wallet passes after a ticket exchange\n";
global $wpdb;
$p = $wpdb->prefix;
PassCaptain\Modules\Exchange::register();
Policy::save_global( Policy::defaults() );
$a490 = wpr7_wallet_enable();
$a490_from = make_type( 10, 10, 0 );
$a490_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a490_from" );
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $a490_occ, 'name' => 'Loge', 'price_minor' => 0, 'currency' => 'EUR', 'capacity' => 10, 'max_per_order' => 10, 'status' => 'active' ) );
$a490_to = (int) $wpdb->insert_id;
http_reset();
$a490_r = Orders::create( array( 'email' => 'a490@example.com', 'items' => array( array( 'ticket_type_id' => $a490_from, 'quantity' => 1 ) ), 'idempotency_key' => 'a490-1' ) );
$a490_old = (int) tickets( $a490_r['order_id'] )[0]->ticket_id;
Service::flush();
check( 'A490 setup: old ticket has wallet passes', 2 === count( Store::passes( $a490_old ) ) );
$GLOBALS['wpcap_test_mails'] = array();
http_reset();
for ( $i = 0; $i < 6; $i++ ) { http_push( array(), 200, 'walletobjects' ); }
$a490_x = Exchange::request( $a490_old, $a490_to, array( 'channel' => 'admin', 'idempotency_key' => 'a490-x' ) );
Service::flush();
$a490_new = is_wp_error( $a490_x ) ? 0 : (int) $a490_x['new_ticket_id'];
$a490_mail = array_values( array_filter( $GLOBALS['wpcap_test_mails'], static fn( $m ) => false !== strpos( (string) $m['subject'], 'umgetauscht' ) ) )[0] ?? array( 'body' => '' );
check( 'A490: exchange completed', $a490_new > 0 );
check( 'A490: new ticket has Apple and Google passes with its own code', 2 === count( Store::passes( $a490_new ) ) && Store::state( $a490_new ) && (string) Store::state( $a490_new )->code_hash === (string) $wpdb->get_var( "SELECT token_hash FROM {$p}wpcap_tickets WHERE ticket_id=$a490_new" ) );
check( 'A490: exchange mail carries the wallet links of the new ticket', false !== strpos( $a490_mail['body'], '/wallet/apple/download/' . $wpdb->get_var( "SELECT public_id FROM {$p}wpcap_tickets WHERE ticket_id=$a490_new" ) ) && false !== strpos( $a490_mail['body'], '/wallet/google/save?t=' ) );
$a490_pj = wpr7_served_pass( $a490_new );
check( 'A490: new pass shows the new ticket type and a barcode', 'Loge' === ( array_column( $a490_pj['eventTicket']['auxiliaryFields'] ?? array(), 'value', 'key' )['category'] ?? '' ) && ! empty( $a490_pj['barcodes'] ) );
check( 'A490: old ticket passes are voided', 'voided' === Store::passes( $a490_old, 'apple' )[0]->status && true === Service::ticket( $a490_old )['voided'] );
check( 'A490: a repeated hook call is idempotent', array() === Service::on_code_issued( $a490_new, 'x' ) );
wpr7_wallet_disable();

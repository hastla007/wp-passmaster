<?php
/**
 * A484: the buyer limit could be bypassed with plus addresses (name+1@domain, name+2@domain …) on
 * every domain other than Gmail; Gmail dots were only normalised with a filter. Now plus tags are
 * stripped for all domains and Gmail dots ignored (both settings, default on); existing buyer rows
 * are merged into the new keys.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Allocations\BuyerLimits;
use PassCaptain\Modules\Allocations;
use PassCaptain\Orders;
use PassCaptain\Settings;

Allocations::register();
global $wpdb;
$p = $wpdb->prefix;
$a484_cfg = Allocations::config();
$a484_user = $GLOBALS['wpcap_test_user_id'] ?? null;
$GLOBALS['wpcap_test_user_id'] = 0;

function a484_type() {
	global $wpdb;
	$p = $wpdb->prefix;
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'Limitkonzert', 'slug' => 'a484' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now ) );
	$event = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 7 * 86400 ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => 0, 'status' => 'scheduled' ) );
	$occ = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $occ, 'name' => 'Stehplatz', 'price_minor' => 0, 'currency' => 'EUR', 'capacity' => 0, 'max_per_order' => 10, 'status' => 'active' ) );
	return array( (int) $wpdb->insert_id, $event );
}
function a484_buy( $type, $qty, $email ) {
	$GLOBALS['wpcap_test_transients'] = array();
	return Orders::create( array( 'email' => $email, 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => 'a484-' . uniqid( '', true ) ), array( 'skip_rate_limit' => true ) );
}

echo "A484: plus addresses and Gmail dots count as one buyer\n";
check( 'A484: +tag is stripped for any domain', 'max@example.org' === BuyerLimits::normalize_email( 'Max+Tickets@Example.org' ) );
check( 'A484: Gmail dots and googlemail.com are normalised by default (no filter needed)', 'vorname@gmail.com' === BuyerLimits::normalize_email( 'Vor.Name+x@googlemail.com' ) );
check( 'A484: a local part starting with "+" is kept', '+x@example.org' === BuyerLimits::normalize_email( '+x@example.org' ) );
list( $a484_t, $a484_e ) = a484_type();
BuyerLimits::set( $a484_e, 0, 2 );
BuyerLimits::flush();
$a484_1 = a484_buy( $a484_t, 2, 'fan@example.org' );
$a484_2 = a484_buy( $a484_t, 1, 'fan+2@example.org' );
$a484_3 = a484_buy( $a484_t, 1, 'FAN+zweitkonto@Example.org' );
check( 'A484: name+2@domain is refused once name@domain reached the limit', ! is_wp_error( $a484_1 ) && is_wp_error( $a484_2 ) && is_wp_error( $a484_3 ) );
$a484_g1 = a484_buy( $a484_t, 2, 'jane.doe@gmail.com' );
$a484_g2 = a484_buy( $a484_t, 1, 'j.a.n.e.d.o.e@gmail.com' );
check( 'A484: Gmail dot variants are one buyer', ! is_wp_error( $a484_g1 ) && is_wp_error( $a484_g2 ) );

Settings::update( array( 'allocations' => array_merge( $a484_cfg, array( 'buyer_limit_strip_plus' => 0, 'buyer_limit_gmail_dots' => 0 ) ) ) );
check( 'A484: both rules can be switched off', 'max+tickets@example.org' === BuyerLimits::normalize_email( 'Max+Tickets@Example.org' ) && 'vor.name@googlemail.com' === BuyerLimits::normalize_email( 'Vor.Name@googlemail.com' ) );
// Rows written under the old keys (switched off) are merged into the new keys when the rule is switched on.
list( $a484_mt, $a484_me ) = a484_type();
BuyerLimits::set( $a484_me, 0, 2 );
BuyerLimits::flush();
$a484_m1 = a484_buy( $a484_mt, 2, 'alt+1@example.org' );
$GLOBALS['wpcap_test_options']['wpcap_alloc_key_version'] = BuyerLimits::KEY_VERSION;
PassCaptain\Allocations\AdminPage::save_settings( array( 'auto_release_hours' => (string) $a484_cfg['auto_release_hours'], 'buyer_limit_by_user' => '1', 'buyer_limit_strip_plus' => '1', 'buyer_limit_gmail_dots' => '1' ) );
check( 'A484: switching the rule on merges the existing orders into the new buyer key', ! is_wp_error( $a484_m1 ) && 2 === BuyerLimits::count_for( $a484_me, 'alt@example.org' ) && is_wp_error( a484_buy( $a484_mt, 1, 'alt@example.org' ) ) );
check( 'A484: key version is current after the merge', BuyerLimits::KEY_VERSION === (int) get_option( 'wpcap_alloc_key_version' ) );
$a484_erase = BuyerLimits::privacy_erase( 'alt+1@example.org' );
check( 'A484: the eraser also removes rows of the address as typed', 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . PassCaptain\Allocations\Holds::table( 'buyers' ) . ' WHERE event_id=%d', $a484_me ) ) );

Settings::update( array( 'allocations' => $a484_cfg ) );
$GLOBALS['wpcap_test_user_id'] = $a484_user;

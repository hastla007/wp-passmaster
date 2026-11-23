<?php
/**
 * R7 (audit round 2, expert #12): campaign extensions (minimum quantity, code batch generator with CSV
 * rows) and hidden / code-only ticket types (Sales\Visibility, checked by the server in Orders::create()).
 * Money invariants of automatic/group discounts: zzzzz_y3_money_invariants.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Campaigns as R7cCampaigns;
use PassCaptain\Orders as R7cOrders;
use PassCaptain\Sales\Comps as R7cComps;
use PassCaptain\Sales\Visibility as R7cVisibility;

global $wpdb;
$p = $wpdb->prefix;
wpcap_test_reset_transients();
$r7c_sfx = strtoupper( substr( md5( microtime() ), 0, 6 ) );
$r7c_order = static function ( $type, $qty, $codes, $key ) {
	return R7cOrders::create( array( 'email' => 'r7c-' . $key . '@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'campaign_codes' => $codes, 'idempotency_key' => 'r7c-' . $key . microtime() ), array( 'skip_rate_limit' => true ) );
};

echo "R7 campaigns: minimum quantity and code batches\n";
$r7c_type = make_type( 100, 10, 1000 );
R7cCampaigns::create( array( 'code' => 'GRP' . $r7c_sfx, 'kind' => 'percent', 'value' => 2000, 'currency' => 'EUR', 'min_quantity' => 5, 'ticket_type_id' => $r7c_type ) );
$r7c_few = $r7c_order( $r7c_type, 4, array( 'GRP' . $r7c_sfx ), 'few' );
check( 'explicit group code below minimum quantity is refused with an explanation', is_wp_error( $r7c_few ) && false !== strpos( $r7c_few->get_error_message(), '5' ) );
$r7c_many = $r7c_order( $r7c_type, 5, array( 'GRP' . $r7c_sfx ), 'many' );
check( 'group code from 5 tickets: 20 % off', ! is_wp_error( $r7c_many ) && 1000 === (int) $r7c_many['discount_minor'] );

$r7c_tpl = R7cCampaigns::create( array( 'code' => 'MAIL' . $r7c_sfx, 'kind' => 'fixed', 'value' => 300, 'currency' => 'EUR', 'usage_limit' => 0 ) );
$r7c_batch = R7cCampaigns::generate_codes( $r7c_tpl['campaign_id'], 25, 'nl' );
check( 'generator creates 25 unique single-use codes with prefix', ! is_wp_error( $r7c_batch ) && 25 === $r7c_batch['created'] && 25 === count( array_unique( $r7c_batch['codes'] ) ) && 0 === strpos( $r7c_batch['codes'][0], 'NL-' ) );
$r7c_rows = R7cCampaigns::batch_rows( $r7c_tpl['campaign_id'] );
check( 'CSV rows: header + 25 codes, none used yet', 26 === count( $r7c_rows ) && array( 'code', 'status', 'used' ) === $r7c_rows[0] && 'no' === $r7c_rows[1][2] );
$r7c_code = is_wp_error( $r7c_batch ) ? '' : $r7c_batch['codes'][0];
$r7c_use = $r7c_order( $r7c_type, 1, array( $r7c_code ), 'b1' );
check( 'a generated code gives the template discount', ! is_wp_error( $r7c_use ) && 300 === (int) $r7c_use['discount_minor'] );
check( 'a generated code is single use', is_wp_error( $r7c_order( $r7c_type, 1, array( $r7c_code ), 'b2' ) ) );
check( 'generated codes do not clutter the campaign list (batch_id)', 1 === count( array_filter( PassCaptain\Admin\CampaignsPage::campaigns(), static fn( $c ) => 0 === strpos( $c->code, 'MAIL' . $r7c_sfx ) || 0 === strpos( $c->code, 'NL-' ) ) ) );
check( 'count out of range is refused', is_wp_error( R7cCampaigns::generate_codes( $r7c_tpl['campaign_id'], 0 ) ) && is_wp_error( R7cCampaigns::generate_codes( $r7c_tpl['campaign_id'], R7cCampaigns::MAX_BATCH + 1 ) ) );

echo "R7 visibility: hidden and code-only ticket types are checked by the server\n";
$r7c_public = make_type( 20, 10, 2000 );
$r7c_occ = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT occurrence_id FROM %i WHERE ticket_type_id=%d', $p . 'wpcap_ticket_types', $r7c_public ) );
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $r7c_occ, 'name' => 'Presale', 'price_minor' => 1500, 'currency' => 'EUR', 'capacity' => 20, 'max_per_order' => 10, 'status' => 'active', 'visibility' => 'code', 'access_codes' => "EARLY{$r7c_sfx}\nFANS{$r7c_sfx}" ) );
$r7c_code_type = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $r7c_occ, 'name' => 'Presse', 'price_minor' => 0, 'currency' => 'EUR', 'capacity' => 20, 'max_per_order' => 10, 'status' => 'active', 'visibility' => 'hidden' ) );
$r7c_hidden = (int) $wpdb->insert_id;
check( 'code-only type without code is refused', is_wp_error( $r7c_order( $r7c_code_type, 1, array(), 'v1' ) ) );
check( 'code-only type with a wrong code is refused', is_wp_error( $r7c_order( $r7c_code_type, 1, array( 'NOPE' . $r7c_sfx ), 'v2' ) ) );
$r7c_ok = $r7c_order( $r7c_code_type, 1, array( strtolower( 'early' . $r7c_sfx ) ), 'v3' );
check( 'code-only type with a list code is sold at its price (pure unlock code, no discount error)', ! is_wp_error( $r7c_ok ) && 1500 === (int) $r7c_ok['total_minor'] && 0 === (int) $r7c_ok['discount_minor'] );
$r7c_pub = $r7c_order( $r7c_public, 1, array( 'FANS' . $r7c_sfx ), 'v3b' );
check( 'the unlock code does not break an order of only public types of that date', ! is_wp_error( $r7c_pub ) && 2000 === (int) $r7c_pub['total_minor'] );
R7cCampaigns::create( array( 'code' => 'PRESS' . $r7c_sfx, 'kind' => 'percent', 'value' => 1000, 'currency' => 'EUR', 'ticket_type_id' => $r7c_code_type ) );
$r7c_camp = $r7c_order( $r7c_code_type, 2, array( 'PRESS' . $r7c_sfx ), 'v4' );
check( 'a campaign scoped to the type unlocks it and discounts it', ! is_wp_error( $r7c_camp ) && 300 === (int) $r7c_camp['discount_minor'] );
check( 'hidden type is never sold in the public checkout, even with a code', is_wp_error( $r7c_order( $r7c_hidden, 1, array( 'EARLY' . $r7c_sfx ), 'v5' ) ) );
$r7c_comp = R7cComps::issue( array( 'ticket_type_id' => $r7c_hidden, 'recipient_email' => 'press@example.com', 'quantity' => 1, 'batch_key' => 'r7c' . $r7c_sfx ) );
check( 'hidden type can be issued as complimentary ticket', ! is_wp_error( $r7c_comp ) && 1 === $r7c_comp['issued'] );
$r7c_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE occurrence_id=%d', $p . 'wpcap_ticket_types', $r7c_occ ) );
$r7c_names = static fn( $rows ) => array_map( static fn( $r ) => $r->name, $rows );
check( 'listing: only public types without code', array( 'Standard' ) === $r7c_names( R7cVisibility::filter_listing( $r7c_rows ) ) );
check( 'listing: code unlocks the presale type, hidden stays hidden', array( 'Standard', 'Presale' ) === $r7c_names( R7cVisibility::filter_listing( $r7c_rows, 'fans' . $r7c_sfx ) ) );
check( 'listing: scoped campaign code unlocks too', array( 'Standard', 'Presale' ) === $r7c_names( R7cVisibility::filter_listing( $r7c_rows, 'PRESS' . $r7c_sfx ) ) );

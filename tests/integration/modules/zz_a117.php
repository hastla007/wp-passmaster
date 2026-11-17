<?php
/**
 * A117: configurable stacking rules for discount codes (max codes, cumulative vs. best, exclusive codes).
 */
use PassCaptain\Campaigns;
use PassCaptain\Orders;
use PassCaptain\Settings;

echo "A117: discount code stacking rules\n";
global $wpdb;
$a117_type = ex_type( ex_occ( ex_event( 'Stapelregeln' ) ), 'Stapel', 10000, 0, 2000 );
$a117_sel = array( array( 'type' => (object) array( 'ticket_type_id' => $a117_type ), 'event_id' => 0, 'line_total' => 10000 ) );
// Line event_id is only used for event-scoped campaigns; these are global.
Campaigns::create( array( 'code' => 'A117P10', 'kind' => 'percent', 'value' => 1000, 'currency' => 'EUR', 'priority' => 10 ) );
Campaigns::create( array( 'code' => 'A117F25', 'kind' => 'fixed', 'value' => 2500, 'currency' => 'EUR', 'priority' => 20 ) );
Campaigns::create( array( 'code' => 'A117P20', 'kind' => 'percent', 'value' => 2000, 'currency' => 'EUR', 'priority' => 30 ) );
Campaigns::create( array( 'code' => 'A117F05', 'kind' => 'fixed', 'value' => 500, 'currency' => 'EUR', 'priority' => 40 ) );
Campaigns::create( array( 'code' => 'A117SOLO', 'kind' => 'percent', 'value' => 5000, 'currency' => 'EUR', 'stacking' => 'exclusive' ) );
$a117_sum = static fn( $adj ) => is_wp_error( $adj ) ? $adj->get_error_code() : array_sum( array_map( static fn( $a ) => (int) $a['amount_minor'], $adj ) );

Settings::update( array( 'campaign_max_codes' => 3, 'campaign_stacking' => 'cumulative' ) );
check( 'default: cumulative – 10 % then 25,00 on the rest (1000 + 2500)', 3500 === $a117_sum( Campaigns::calculate( $a117_sel, array( 'A117P10', 'A117F25' ), 'EUR', false ) ) );
check( 'default limit 3: a fourth code is refused', 'wpcap_campaign_limit' === $a117_sum( Campaigns::calculate( $a117_sel, array( 'A117P10', 'A117F25', 'A117P20', 'A117F05' ), 'EUR', false ) ) );
Settings::update( array( 'campaign_max_codes' => 4 ) );
check( 'raised limit 4: four codes allowed', is_int( $a117_sum( Campaigns::calculate( $a117_sel, array( 'A117P10', 'A117F25', 'A117P20', 'A117F05' ), 'EUR', false ) ) ) );
Settings::update( array( 'campaign_max_codes' => 1 ) );
$a117_one = Campaigns::calculate( $a117_sel, array( 'A117P10', 'A117F25' ), 'EUR', false );
check( 'limit 1: second code refused with a singular message', is_wp_error( $a117_one ) && 'wpcap_campaign_limit' === $a117_one->get_error_code() && false !== strpos( $a117_one->get_error_message(), 'nur ein Rabattcode' ) );
check( 'limit 1: one code still works', 1000 === $a117_sum( Campaigns::calculate( $a117_sel, array( 'A117P10' ), 'EUR', false ) ) );

Settings::update( array( 'campaign_max_codes' => 3, 'campaign_stacking' => 'best' ) );
$a117_best = Campaigns::calculate( $a117_sel, array( 'A117P10', 'A117F25', 'A117P20' ), 'EUR', false );
check( 'best: only the highest discount applies (25,00 beats 10 % and 20 %)', ! is_wp_error( $a117_best ) && 1 === count( $a117_best ) && 'A117F25' === $a117_best[0]['campaign']->code && 2500 === (int) $a117_best[0]['amount_minor'] );
check( 'best: per-line split is kept for the applied code', array( 0 => 2500 ) === $a117_best[0]['lines'] );

Settings::update( array( 'campaign_stacking' => 'cumulative' ) );
check( 'exclusive code alone works (50 %)', 5000 === $a117_sum( Campaigns::calculate( $a117_sel, array( 'A117SOLO' ), 'EUR', false ) ) );
$a117_ex = Campaigns::calculate( $a117_sel, array( 'A117SOLO', 'A117P10' ), 'EUR', false );
check( 'exclusive code with another code is refused and named', is_wp_error( $a117_ex ) && 'wpcap_campaign_not_combinable' === $a117_ex->get_error_code() && false !== strpos( $a117_ex->get_error_message(), 'A117SOLO' ) );
Settings::update( array( 'campaign_stacking' => 'best' ) );
check( 'exclusive also refused in best mode (rule, not optimisation)', 'wpcap_campaign_not_combinable' === $a117_sum( Campaigns::calculate( $a117_sel, array( 'A117SOLO', 'A117P10' ), 'EUR', false ) ) );
Settings::update( array( 'campaign_stacking' => 'cumulative' ) );

$a117_filter = static fn() => 'best';
add_filter( 'wpcap_campaign_stacking', $a117_filter );
check( 'filter wpcap_campaign_stacking switches the mode per order', 2500 === $a117_sum( Campaigns::calculate( $a117_sel, array( 'A117P10', 'A117F25' ), 'EUR', false ) ) );
remove_filter( 'wpcap_campaign_stacking', $a117_filter );

// Real checkout: best mode books only the applied code (usage counted once).
Settings::update( array( 'campaign_stacking' => 'best' ) );
$GLOBALS['wpcap_test_transients'] = array();
$a117_o = Orders::create( array( 'email' => 'a117@example.com', 'items' => array( array( 'ticket_type_id' => $a117_type, 'quantity' => 1 ) ), 'campaign_codes' => array( 'A117P10', 'A117F25' ), 'accept_terms' => true, 'idempotency_key' => 'a117-' . uniqid() ) );
$a117_uses = $wpdb->get_col( $wpdb->prepare( "SELECT code FROM {$wpdb->prefix}wpcap_campaign_uses WHERE order_id=%d", is_array( $a117_o ) ? $a117_o['order_id'] : 0 ) );
check( 'checkout in best mode: total 75,00, only the applied code is recorded and counted', is_array( $a117_o ) && 7500 === (int) Orders::get( $a117_o['order_id'] )->total_minor && array( 'A117F25' ) === $a117_uses && 0 === (int) Campaigns::get( (int) $wpdb->get_var( "SELECT campaign_id FROM {$wpdb->prefix}wpcap_campaigns WHERE code='A117P10'" ) )->used_count );
Settings::update( array( 'campaign_stacking' => 'cumulative', 'campaign_max_codes' => 3 ) );

check( 'campaign stores its stacking flag', 'exclusive' === Campaigns::get( (int) $wpdb->get_var( "SELECT campaign_id FROM {$wpdb->prefix}wpcap_campaigns WHERE code='A117SOLO'" ) )->stacking );

<?php
/**
 * R7 (audit round 2, expert #9): the series generator copies everything that hangs off a template
 * ticket type – participant fields, fiscal seller assignment, admission policy and areas, type- and
 * occurrence-scoped price rules and buyer limits (TicketTypeCopier).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Series\Generator as R7aGenerator;
use PassCaptain\TicketTypeCopier as R7aCopier;

global $wpdb;
$p = $wpdb->prefix;
R7aCopier::reset_cache();

echo "R7 series: generated occurrences copy ticket type dependents\n";
$r7a_type = make_type( 50, 10, 2000 );
$r7a_tpl = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT occurrence_id FROM %i WHERE ticket_type_id=%d', $p . 'wpcap_ticket_types', $r7a_type ) );
$r7a_event = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT event_id FROM %i WHERE occurrence_id=%d', $p . 'wpcap_occurrences', $r7a_tpl ) );
$r7a_now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $r7a_type, 'field_key' => 'diet', 'label' => 'Ernährung', 'field_type' => 'text', 'required' => 1, 'position' => 1, 'status' => 'active' ) );
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $r7a_type, 'field_key' => 'photo', 'label' => 'Fotos', 'field_type' => 'consent', 'required' => 0, 'position' => 2, 'status' => 'active', 'consent_text' => 'Ich bin einverstanden.', 'consent_version' => 'v1' ) );
$wpdb->insert( "{$p}wpcap_ticket_sellers", array( 'ticket_type_id' => $r7a_type, 'seller_id' => 77, 'seller_mode' => 'organizer_seller', 'updated_at' => $r7a_now ) );
$wpdb->insert( "{$p}wpcap_admission_policies", array( 'ticket_type_id' => $r7a_type, 'policy' => 'multi', 'max_entries' => 3, 'updated_at' => $r7a_now ) );
$wpdb->insert( "{$p}wpcap_admission_type_areas", array( 'ticket_type_id' => $r7a_type, 'area_id' => 991 ) );
$wpdb->insert( "{$p}wpcap_price_rules", array( 'event_id' => $r7a_event, 'occurrence_id' => $r7a_tpl, 'ticket_type_id' => $r7a_type, 'label' => 'Früh', 'kind' => 'early_bird', 'price_mode' => 'fixed', 'amount' => 1500, 'status' => 'active', 'created_at' => $r7a_now, 'updated_at' => $r7a_now ) );
$wpdb->insert( "{$p}wpcap_price_rules", array( 'event_id' => $r7a_event, 'occurrence_id' => 0, 'ticket_type_id' => 0, 'label' => 'Eventweit', 'kind' => 'early_bird', 'price_mode' => 'fixed', 'amount' => 1800, 'status' => 'active', 'created_at' => $r7a_now, 'updated_at' => $r7a_now ) );
$wpdb->insert( "{$p}wpcap_buyer_limits", array( 'event_id' => $r7a_event, 'occurrence_id' => $r7a_tpl, 'ticket_type_id' => 0, 'max_quantity' => 4, 'match_by' => 'both', 'status' => 'active', 'created_at' => $r7a_now, 'updated_at' => $r7a_now ) );

$r7a_dates = array();
for ( $i = 1; $i <= 3; $i++ ) { $r7a_dates[] = gmdate( 'Y-m-d', time() + ( 40 + $i ) * DAY_IN_SECONDS ); }
$r7a_r = R7aGenerator::create( array( 'event_id' => $r7a_event, 'template_occurrence_id' => $r7a_tpl, 'freq' => 'dates', 'dates' => implode( "\n", $r7a_dates ), 'time' => '19:00', 'duration_minutes' => 60, 'timezone' => 'Europe/Vienna', 'name' => 'r7a' ) );
check( 'series with template creates 3 occurrences', ! is_wp_error( $r7a_r ) && 3 === count( $r7a_r['created'] ) );
$r7a_occ = is_wp_error( $r7a_r ) ? 0 : (int) $r7a_r['created'][0];
$r7a_new_type = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT ticket_type_id FROM %i WHERE occurrence_id=%d', $p . 'wpcap_ticket_types', $r7a_occ ) );
$r7a_fields = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE ticket_type_id=%d ORDER BY position', $p . 'wpcap_participant_fields', $r7a_new_type ) );
check( 'new type gets both participant fields (required flag and consent text kept)', 2 === count( $r7a_fields ) && 'diet' === $r7a_fields[0]->field_key && 1 === (int) $r7a_fields[0]->required && 'Ich bin einverstanden.' === $r7a_fields[1]->consent_text && 'v1' === $r7a_fields[1]->consent_version );
check( 'all three occurrences have fields (6 rows)', 6 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i f INNER JOIN %i t ON t.ticket_type_id=f.ticket_type_id INNER JOIN %i so ON so.occurrence_id=t.occurrence_id WHERE so.series_id=%d', $p . 'wpcap_participant_fields', $p . 'wpcap_ticket_types', $p . 'wpcap_series_occurrences', is_wp_error( $r7a_r ) ? 0 : $r7a_r['series_id'] ) ) );
check( 'seller assignment copied', 77 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT seller_id FROM %i WHERE ticket_type_id=%d', $p . 'wpcap_ticket_sellers', $r7a_new_type ) ) );
check( 'admission policy and area copied', 'multi' === $wpdb->get_var( $wpdb->prepare( 'SELECT policy FROM %i WHERE ticket_type_id=%d', $p . 'wpcap_admission_policies', $r7a_new_type ) ) && 991 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT area_id FROM %i WHERE ticket_type_id=%d', $p . 'wpcap_admission_type_areas', $r7a_new_type ) ) );
$r7a_rule = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE ticket_type_id=%d', $p . 'wpcap_price_rules', $r7a_new_type ) );
check( 'type-bound price rule copied to the new type and occurrence', $r7a_rule && $r7a_occ === (int) $r7a_rule->occurrence_id && 1500 === (int) $r7a_rule->amount );
check( 'event-wide price rule is not duplicated', 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE event_id=%d AND label='Eventweit'", $p . 'wpcap_price_rules', $r7a_event ) ) );
check( 'occurrence-scoped buyer limit copied', 4 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT max_quantity FROM %i WHERE occurrence_id=%d', $p . 'wpcap_buyer_limits', $r7a_occ ) ) );
$r7a_again = R7aGenerator::create( array( 'event_id' => $r7a_event, 'template_occurrence_id' => $r7a_tpl, 'freq' => 'dates', 'dates' => implode( "\n", $r7a_dates ), 'time' => '19:00', 'duration_minutes' => 60, 'timezone' => 'Europe/Vienna', 'name' => 'r7a' ) );
check( 'rerun is idempotent (no extra fields)', ! is_wp_error( $r7a_again ) && 0 === count( $r7a_again['created'] ) && 2 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ticket_type_id=%d', $p . 'wpcap_participant_fields', $r7a_new_type ) ) );

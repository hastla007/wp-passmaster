<?php
/**
 * Service pass rules, part D: service rules are separate from event ticket rules – slot-free vs
 * slot-bound, single vs multi use, rule expiry – and a pass cannot cross from events to services or
 * back unless a rule has scope "any".
 *
 * Included from run.php after zzzz_services_a_rules.php (svc_* helpers).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Memberships;
use PassCaptain\ServiceBookings;
use PassCaptain\ServiceResources;

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 1;

echo "Service passes vs event passes\n";
list( $pp_event, $pp_type ) = svc_event( 2000 );
// Event entry with a pass only on the day of a scheduled date inside the admission window (A482):
// a second date of the event starting in 30 minutes carries the entry ticket type.
$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $pp_event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 1800 ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => 0, 'status' => 'scheduled' ) );
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => (int) $wpdb->insert_id, 'name' => 'Eintritt', 'price_minor' => 2000, 'currency' => 'EUR', 'capacity' => 100, 'max_per_order' => 10, 'status' => 'active' ) );
$pp_entry_type = (int) $wpdb->insert_id;
$pp_free_res = svc_resource( $pp_event, array( 'name' => 'Freies Training', 'capacity' => 10 ) );
$pp_slot_res = svc_resource( $pp_event, array( 'name' => 'Segelkurs', 'capacity' => 1, 'ticket_type_id' => $pp_type ) );
$pp_other_res = svc_resource( $pp_event, array( 'name' => 'Kajak', 'capacity' => 5 ) );
$from = gmdate( 'Y-m-d H:i:s', time() - 86400 );
$until = gmdate( 'Y-m-d H:i:s', time() + 200 * 86400 );
$event_pass = Memberships::issue( 'event-member@svc.test', $from, $until, array( array( 'event_id' => $pp_event, 'uses_total' => 0, 'uses_per_day' => 5 ) ) );
$service_pass = Memberships::issue( 'service-member@svc.test', $from, $until, array(
	array( 'scope' => 'service', 'resource_id' => $pp_free_res, 'slot_mode' => 'slot_free', 'uses_total' => 3, 'uses_per_day' => 1 ),
	array( 'scope' => 'service', 'resource_id' => $pp_slot_res, 'slot_mode' => 'slot_bound', 'uses_total' => 1, 'uses_per_day' => 1 ),
) );
$any_pass = Memberships::issue( 'any-member@svc.test', $from, $until, array( array( 'scope' => 'any', 'event_id' => 0, 'resource_id' => 0, 'slot_mode' => 'slot_free', 'uses_total' => 0, 'uses_per_day' => 10 ) ) );
$rules = Memberships::rules( $service_pass['pass_id'] );
check( 'service rules store scope, resource, slot mode; event fields are not used', 2 === count( $rules ) && 'service' === $rules[0]->scope && (int) $pp_free_res === (int) $rules[0]->resource_id && 'slot_bound' === $rules[1]->slot_mode && 0 === (int) $rules[0]->event_id && 'event' === Memberships::rules( $event_pass['pass_id'] )[0]->scope );
$e_on_service = Memberships::redeem_service( $event_pass['token'], $pp_free_res, 'pp-1' );
$s_on_event = Memberships::redeem( $service_pass['token'], $pp_entry_type, 'pp-2' );
check( 'an event pass cannot be used for a service resource', is_wp_error( $e_on_service ) && 'wpcap_pass_scope' === $e_on_service->get_error_code() );
check( 'a service pass cannot be used for event entry', is_wp_error( $s_on_event ) && 'wpcap_pass_scope' === $s_on_event->get_error_code() );
check( 'the event pass still works for event entry', 'redeemed' === ( Memberships::redeem( $event_pass['token'], $pp_entry_type, 'pp-3' )['status'] ?? '' ) );
check( 'scope "any" works for both when configured', 'redeemed' === ( Memberships::redeem( $any_pass['token'], $pp_entry_type, 'pp-4' )['status'] ?? '' ) && 'redeemed' === ( Memberships::redeem_service( $any_pass['token'], $pp_other_res, 'pp-5' )['status'] ?? '' ) );
// Slot-free: direct use, per-day and total quota, idempotency.
$sf1 = Memberships::redeem_service( $service_pass['token'], $pp_free_res, 'pp-free-1' );
$sf1b = Memberships::redeem_service( $service_pass['token'], $pp_free_res, 'pp-free-1' );
$sf2 = Memberships::redeem_service( $service_pass['token'], $pp_free_res, 'pp-free-2' );
check( 'slot-free rule: used directly without booking; idempotent per key; daily quota enforced', 'redeemed' === ( $sf1['status'] ?? '' ) && 0 === $sf1['booking_id'] && 'already_redeemed' === ( $sf1b['status'] ?? '' ) && is_wp_error( $sf2 ) && 'wpcap_pass_quota' === $sf2->get_error_code() );
check( 'a pass rule for one resource does not open another resource', is_wp_error( Memberships::redeem_service( $service_pass['token'], $pp_other_res, 'pp-free-3' ) ) );
// Slot-bound: needs a slot, books it (capacity), single use.
$pp_slot = svc_slot( $pp_slot_res, 60, 60, 1 );
$pp_slot2 = svc_slot( $pp_slot_res, 90, 60, 1 );
$no_slot = Memberships::redeem_service( $service_pass['token'], $pp_slot_res, 'pp-bound-0' );
check( 'slot-bound rule refuses a use without slot (and slot-free use is refused with a slot)', is_wp_error( $no_slot ) && is_wp_error( Memberships::redeem_service( $service_pass['token'], $pp_free_res, 'pp-free-4', $pp_slot ) ) );
svc_reset_limits();
$bound = Memberships::redeem_service( $service_pass['token'], $pp_slot_res, 'pp-bound-1', $pp_slot );
$bound_row = ServiceBookings::get( $bound['booking_id'] ?? 0 );
check( 'slot-bound rule books a confirmed place without payment (amount 0), linked to the pass use', $bound_row && 'confirmed' === $bound_row->status && 0 === (int) $bound_row->amount_minor && (int) $bound_row->pass_use_id > 0 && 'service-member@svc.test' === $bound_row->customer_email && 1 === ServiceResources::booked_quantity( $pp_slot ) );
$single = Memberships::redeem_service( $service_pass['token'], $pp_slot_res, 'pp-bound-2', $pp_slot2 );
check( 'single-use rule: a second use is refused', is_wp_error( $single ) && 'wpcap_pass_quota' === $single->get_error_code() );
ServiceBookings::cancel( $bound['booking_id'], array( 'actor' => 'customer' ) );
svc_reset_limits();
$again = Memberships::redeem_service( $service_pass['token'], $pp_slot_res, 'pp-bound-3', $pp_slot2 );
check( 'cancelling a pass booking in time gives the use back', 'redeemed' === ( $again['status'] ?? '' ) && 'cancelled' === svc_status( $bound['booking_id'] ) );
$multi = Memberships::issue( 'multi@svc.test', $from, $until, array( array( 'scope' => 'service', 'resource_id' => $pp_slot_res, 'slot_mode' => 'slot_bound', 'uses_total' => 5, 'uses_per_day' => 5 ) ) );
svc_reset_limits();
$full = Memberships::redeem_service( $multi['token'], $pp_slot_res, 'pp-multi-1', $pp_slot2 );
$uses = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_pass_uses WHERE pass_id=" . (int) $multi['pass_id'] );
check( 'multi-use pass on a full slot: booking refused and the use is not consumed', is_wp_error( $full ) && 'wpcap_slot_full' === $full->get_error_code() && 0 === $uses );
// Expiry: rule-level and pass-level.
$expiring = Memberships::issue( 'expiring@svc.test', $from, $until, array( array( 'scope' => 'service', 'resource_id' => $pp_free_res, 'slot_mode' => 'slot_free', 'uses_total' => 0, 'uses_per_day' => 5, 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ) ) );
$expired_pass = Memberships::issue( 'expired@svc.test', gmdate( 'Y-m-d H:i:s', time() - 10 * 86400 ), gmdate( 'Y-m-d H:i:s', time() - 86400 ), array( array( 'scope' => 'service', 'resource_id' => 0, 'slot_mode' => 'slot_free' ) ) );
check( 'an expired rule (before the pass ends) and an expired pass are refused', is_wp_error( Memberships::redeem_service( $expiring['token'], $pp_free_res, 'pp-exp-1' ) ) && is_wp_error( Memberships::redeem_service( $expired_pass['token'], $pp_free_res, 'pp-exp-2' ) ) && 'wpcap_pass_inactive' === Memberships::redeem_service( $expired_pass['token'], $pp_free_res, 'pp-exp-3' )->get_error_code() );
$added = Memberships::save_rule( $event_pass['pass_id'], 0, array( 'scope' => 'service', 'resource_id' => $pp_other_res, 'slot_mode' => 'slot_free', 'uses_total' => 2 ) );
check( 'a service rule can be added to an existing pass (audited with its scope)', is_int( $added ) && 'redeemed' === ( Memberships::redeem_service( $event_pass['token'], $pp_other_res, 'pp-add-1' )['status'] ?? '' ) && str_contains( (string) svc_audit( 'member_pass_rule_added' )->details, '"scope":"service"' ) );
$mp_issue = PassCaptain\Admin\MembershipsPage::admin_action( array( 'do' => 'issue', 'email' => 'admin-service@svc.test', 'valid_from' => gmdate( 'Y-m-d\TH:i', time() - 86400 ), 'valid_until' => gmdate( 'Y-m-d\TH:i', time() + 90 * 86400 ), 'scope' => 'service', 'resource_id' => $pp_slot_res, 'slot_mode' => 'slot_bound', 'uses_total' => '1', 'uses_per_day' => '1' ) );
$mp_rule = is_array( $mp_issue ) && ! empty( $mp_issue['pass_id'] ) ? Memberships::rules( $mp_issue['pass_id'] )[0] : null;
check( 'admin screen issues a service pass with resource and slot binding', $mp_rule && 'service' === $mp_rule->scope && (int) $pp_slot_res === (int) $mp_rule->resource_id && 'slot_bound' === $mp_rule->slot_mode && 1 === (int) $mp_rule->uses_total );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$mp_all = PassCaptain\Admin\MembershipsPage::admin_action( array( 'do' => 'issue', 'email' => 'x-service@svc.test', 'valid_from' => gmdate( 'Y-m-d\TH:i', time() - 86400 ), 'valid_until' => gmdate( 'Y-m-d\TH:i', time() + 90 * 86400 ), 'scope' => 'service', 'resource_id' => '0' ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'a service rule for all resources needs an administrator', is_wp_error( $mp_all ) && 403 === $mp_all->get_error_data()['status'] );

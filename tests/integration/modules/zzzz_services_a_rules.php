<?php
/**
 * Service bookings (docs/SERVICE-BOOKINGS.md), part A: duration/buffers and slot collisions,
 * booking rules (cutoff, opening window, per-customer limit, no-show block), organizer (partner)
 * scope of resources and the admin screen actions for rules.
 *
 * Included from run.php (module glob); uses check() and make_type() and the svc_* helpers of
 * lib/fixtures_modules.php. Registers the Services module hooks for the rest of the run.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\ServicesPage;
use PassCaptain\ServiceBookings;
use PassCaptain\ServiceResources;

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 1;
$_SERVER['REMOTE_ADDR'] = '198.51.100.77';
if ( ! class_exists( 'WP_REST_Server' ) ) { class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; } }
if ( ! function_exists( 'register_rest_route' ) ) { function register_rest_route( $ns, $route, $args ) { $GLOBALS['wpcap_test_routes'][ $ns . $route ] = $args; return true; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce" value="n">'; } }
PassCaptain\Settings::update( array( 'refund_approval_threshold' => 0, 'require_terms' => 0 ) );
PassCaptain\Modules\Services::register();
PassCaptain\ServiceBookingFlow::register(); // As at plugin load (Plugin::boot): checkout link of service bookings (A135).

echo "Service bookings: duration, buffers and slot collisions\n";
list( $sa_event, $sa_type ) = svc_event();
$sa_res = svc_resource( $sa_event, array( 'buffer_before' => 15, 'buffer_after' => 30, 'default_duration' => 90 ) );
$sa_row = ServiceResources::resource( $sa_res );
check( 'resource stores buffers and default duration; rules default when not given', 15 === (int) $sa_row->buffer_before && 30 === (int) $sa_row->buffer_after && 90 === (int) $sa_row->default_duration && ServiceResources::default_rules() === ServiceResources::rules( $sa_row ) );
$bad_duration = ServiceResources::save_resource( array( 'event_id' => $sa_event, 'name' => 'X', 'default_duration' => 2 ) );
check( 'default duration outside 5..1440 minutes is rejected', is_wp_error( $bad_duration ) && 'wpcap_resource_duration' === $bad_duration->get_error_code() );
$s1 = svc_slot( $sa_res, 72, 60 );
$overlap = svc_slot( $sa_res, 72.5, 60 );
$in_buffer = svc_slot( $sa_res, 73 + 0.25, 60 ); // starts 15 min after s1 ends; needs 30 + 15 buffer.
$after_buffer = svc_slot( $sa_res, 73 + 0.75, 60 ); // starts 45 min after s1 ends = buffer_after + buffer_before.
check( 'direct overlap and overlap inside the buffers are rejected (409), a slot after the buffers is accepted', is_int( $s1 ) && is_wp_error( $overlap ) && 'wpcap_service_slot_overlap' === $overlap->get_error_code() && 409 === $overlap->get_error_data()['status'] && is_wp_error( $in_buffer ) && is_int( $after_buffer ) );
$before_buffer = svc_slot( $sa_res, 72 - 1.5, 60 ); // ends 30 min before s1 starts: needs 45.
check( 'buffer also applies before an existing slot', is_wp_error( $before_buffer ) );
$other_res = svc_resource( $sa_event );
check( 'other resources are independent', is_int( svc_slot( $other_res, 72, 60 ) ) );
$s1_row = ServiceResources::slot( $s1 );
$move = ServiceResources::update_slot( $s1, gmdate( 'Y-m-d H:i:s', strtotime( $s1_row->starts_at . ' UTC' ) + 3600 ), gmdate( 'Y-m-d H:i:s', strtotime( $s1_row->ends_at . ' UTC' ) + 3600 ), 2, 'open' );
check( 'moving a slot into another slot\'s buffer is rejected', is_wp_error( $move ) && 'wpcap_service_slot_overlap' === $move->get_error_code() );
$closed_ok = ServiceResources::update_slot( $s1, gmdate( 'Y-m-d H:i:s', strtotime( $s1_row->starts_at . ' UTC' ) + 3600 ), gmdate( 'Y-m-d H:i:s', strtotime( $s1_row->ends_at . ' UTC' ) + 3600 ), 2, 'closed' );
check( 'a closed slot without bookings may overlap (it is not bookable)', ! is_wp_error( $closed_ok ) );
ServiceResources::update_slot( $s1, $s1_row->starts_at, $s1_row->ends_at, 2, 'open' );
$gen_day = gmdate( 'Y-m-d', time() + 20 * 86400 );
$gen = ServiceResources::generate_weekly( $sa_res, array( 'start_date' => $gen_day, 'until_date' => $gen_day, 'weekdays' => array( 1, 2, 3, 4, 5, 6, 7 ), 'time' => '10:00' ) );
$gen_row = is_array( $gen ) && $gen['created'] ? ServiceResources::slot( $gen['created'][0] ) : null;
check( 'weekly generator falls back to the default duration', $gen_row && 90 * 60 === strtotime( $gen_row->ends_at ) - strtotime( $gen_row->starts_at ) );
$gen_overlap = ServiceResources::generate_weekly( $sa_res, array( 'start_date' => $gen_day, 'until_date' => $gen_day, 'weekdays' => array( 1, 2, 3, 4, 5, 6, 7 ), 'time' => '10:30', 'duration' => 30 ) );
check( 'generator skips slots that collide incl. buffers', is_array( $gen_overlap ) && 0 === count( $gen_overlap['created'] ) && 1 === (int) $gen_overlap['skipped'] );
// Legacy overlapping slots (created before the check): booking one blocks the other.
$wpdb->insert( "{$p}wpcap_service_slots", array( 'resource_id' => $sa_res, 'starts_at' => $s1_row->starts_at, 'ends_at' => $s1_row->ends_at, 'capacity' => 5, 'status' => 'open' ) );
$legacy = (int) $wpdb->insert_id;
$b_first = svc_book( $s1, 'first@svc.test' );
$b_legacy = svc_book( $legacy, 'second@svc.test' );
check( 'booking a slot that overlaps a booked slot of the same resource is rejected', ! is_wp_error( $b_first ) && is_wp_error( $b_legacy ) && 'wpcap_slot_resource_busy' === $b_legacy->get_error_code() );
$wpdb->delete( "{$p}wpcap_service_slots", array( 'slot_id' => $legacy ) );
$rest_res = new WP_REST_Request( 'POST' );
$rest_res->set_param( 'resource_id', $sa_res );
$rest_res->set_body( wp_json_encode( array( 'starts_at' => $s1_row->starts_at . ' UTC', 'ends_at' => $s1_row->ends_at . ' UTC' ) ) );
$rest_overlap = PassCaptain\Rest\Controller::create_service_slot( $rest_res );
check( 'REST slot creation uses the same collision check', is_wp_error( $rest_overlap ) && 'wpcap_service_slot_overlap' === $rest_overlap->get_error_code() );

echo "Service bookings: booking rules\n";
list( $sr_event ) = svc_event();
$sr_res = svc_resource( $sr_event, array( 'capacity' => 10, 'rules' => array( 'cutoff_minutes' => 180, 'opens_days' => 30, 'max_per_customer' => 3, 'max_period' => 'day' ) ) );
$rules = ServiceResources::rules( ServiceResources::resource( $sr_res ) );
check( 'rules are stored and merged with defaults', 180 === $rules['cutoff_minutes'] && 30 === $rules['opens_days'] && 3 === $rules['max_per_customer'] && 1440 === $rules['cancel_deadline_minutes'] );
$clamped = ServiceResources::sanitize_rules( array( 'cutoff_minutes' => -5, 'max_period' => 'year', 'price_policy' => 'free_for_all', 'confirmation_hours' => 0 ) );
check( 'invalid rule values are clamped or reset to defaults', 0 === $clamped['cutoff_minutes'] && 'day' === $clamped['max_period'] && 'same_price' === $clamped['price_policy'] && 1 === $clamped['confirmation_hours'] );
$late = svc_slot( $sr_res, 2, 30 );
$late_booking = svc_book( $late, 'rule@svc.test' );
check( 'Buchungsschluss: booking 2h before start with a 3h cutoff is refused', is_wp_error( $late_booking ) && 'wpcap_booking_cutoff' === $late_booking->get_error_code() );
$admin_late = svc_book( $late, 'rule@svc.test', 1, array( 'actor' => 'admin' ) );
check( 'staff bookings are not bound by the cutoff', ! is_wp_error( $admin_late ) );
$far = svc_slot( $sr_res, 40 * 24, 30 );
$far_booking = svc_book( $far, 'rule@svc.test' );
check( 'Vorlauf: booking 40 days ahead with a 30-day window is refused', is_wp_error( $far_booking ) && 'wpcap_booking_too_early' === $far_booking->get_error_code() );
$day_a = svc_slot_at( $sr_res, 5, '10:00', 30 );
$day_b = svc_slot_at( $sr_res, 5, '12:00', 30 );
$ok_two = svc_book( $day_a, 'Limit@svc.test', 2 );
$over = svc_book( $day_b, 'limit@svc.test', 2 );
$ok_one = svc_book( $day_b, 'limit@svc.test', 1 );
check( 'per-customer limit per day counts across slots of the resource (case-insensitive e-mail)', ! is_wp_error( $ok_two ) && is_wp_error( $over ) && 'wpcap_booking_customer_limit' === $over->get_error_code() && ! is_wp_error( $ok_one ) );
$other_day = svc_slot_at( $sr_res, 7, '10:00', 30 );
check( 'the limit resets on another day', ! is_wp_error( svc_book( $other_day, 'limit@svc.test', 3 ) ) );
// Expired holds do not count.
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_service_bookings SET expires_at=%s WHERE booking_id=%d", gmdate( 'Y-m-d H:i:s', time() - 60 ), (int) $ok_two['booking_id'] ) );
check( 'expired holds free the customer quota', ! is_wp_error( svc_book( $day_b, 'limit@svc.test', 2 ) ) );
$week_res = svc_resource( $sr_event, array( 'capacity' => 10, 'rules' => array( 'cutoff_minutes' => 0, 'max_per_customer' => 1, 'max_period' => 'total' ) ) );
$w1 = svc_slot( $week_res, 50, 30 );
$w2 = svc_slot( $week_res, 24 * 9, 30 );
check( 'period "total" limits all upcoming bookings', ! is_wp_error( svc_book( $w1, 'total@svc.test' ) ) && is_wp_error( svc_book( $w2, 'total@svc.test' ) ) );
$idem_key = 'svc-idem-' . uniqid();
svc_reset_limits();
$i1 = ServiceBookings::reserve( $w2, 'idem@svc.test', 1, 0, 10, $idem_key );
$i2 = ServiceBookings::reserve( $w2, 'idem@svc.test', 1, 0, 10, $idem_key );
$i3 = ServiceBookings::reserve( $w2, 'idem@svc.test', 2, 0, 10, $idem_key );
check( 'reserve is idempotent per key and rejects a reused key with other data; manage token only on creation', ! is_wp_error( $i1 ) && 64 === strlen( $i1['manage_token'] ) && ! empty( $i2['reused'] ) && $i1['booking_id'] === $i2['booking_id'] && ! isset( $i2['manage_token'] ) && is_wp_error( $i3 ) );
$full_slot = svc_slot( $week_res, 24 * 10, 30, 1 );
svc_book( $full_slot, 'a@svc.test' );
$full = svc_book( $full_slot, 'b@svc.test' );
check( 'full slot reports the waitlist option', is_wp_error( $full ) && 'wpcap_slot_full' === $full->get_error_code() && true === $full->get_error_data()['waitlist'] );

echo "Service bookings: organizer (partner) scope\n";
$sp_partner = PassCaptain\Partners\Repository::save( array( 'name' => 'Segelschule Nord', 'contact_email' => 'nord@svc.test', 'currency' => 'EUR' ) );
$sp_other = PassCaptain\Partners\Repository::save( array( 'name' => 'Kanuverleih Süd', 'currency' => 'EUR' ) );
list( $sp_event ) = svc_event();
PassCaptain\Partners\Repository::set_events( $sp_partner, array( $sp_event ) );
$sp_res = svc_resource( $sp_event );
$sp_res_other = svc_resource( $sp_event, array( 'partner_id' => $sp_other ) );
check( 'a new resource belongs to the event organizer by default; an explicit organizer is kept', (int) $sp_partner === (int) ServiceResources::resource( $sp_res )->partner_id && (int) $sp_other === (int) ServiceResources::resource( $sp_res_other )->partner_id );
check( 'unknown organizer is rejected', is_wp_error( ServiceResources::save_resource( array( 'event_id' => $sp_event, 'name' => 'X', 'partner_id' => 999999 ) ) ) );
// A user linked to the partner, without event staff assignment.
$wpdb->query( "DELETE FROM {$p}wpcap_event_staff WHERE user_id=4242" );
PassCaptain\Partners\Repository::set_users( $sp_partner, array( 4242 ) );
$GLOBALS['wpcap_test_user_id'] = 4242;
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_events' ); // Organizer user without event staff rights.
$sp_can_own = ServiceResources::can_manage( ServiceResources::resource( $sp_res ) );
$sp_can_other = ServiceResources::can_manage( ServiceResources::resource( $sp_res_other ) );
$sp_list = array_map( static fn( $r ) => (int) $r->resource_id, ServiceResources::resources( array(), 0, ServiceResources::user_partner_ids() ) );
$sp_edit_other = ServicesPage::admin_action( array( 'do' => 'save_resource', 'id' => $sp_res_other, 'name' => 'Gekapert' ) );
$sp_edit_own = ServicesPage::admin_action( array( 'do' => 'save_resource', 'id' => $sp_res, 'name' => 'Jolle 1', 'capacity' => 3, 'partner_id' => $sp_other ) );
$sp_slot_own = ServicesPage::admin_action( array( 'do' => 'create_slot', 'resource_id' => $sp_res, 'starts_at' => gmdate( 'Y-m-d\T10:00', time() + 30 * 86400 ), 'ends_at' => gmdate( 'Y-m-d\T11:00', time() + 30 * 86400 ) ) );
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'organizer users manage only their organizer\'s resources (list, edit, slots)', $sp_can_own && ! $sp_can_other && in_array( $sp_res, $sp_list, true ) && ! in_array( $sp_res_other, $sp_list, true ) && is_wp_error( $sp_edit_other ) && 403 === $sp_edit_other->get_error_data()['status'] && ! is_wp_error( $sp_edit_own ) && is_array( $sp_slot_own ) );
check( 'organizer users cannot move a resource to another organizer', (int) $sp_partner === (int) ServiceResources::resource( $sp_res )->partner_id && 'Jolle 1' === ServiceResources::resource( $sp_res )->name );
$sp_move = ServicesPage::admin_action( array( 'do' => 'save_resource', 'id' => $sp_res, 'name' => 'Jolle 1', 'partner_id' => $sp_other ) );
check( 'staff can reassign the organizer; audited', ! is_wp_error( $sp_move ) && (int) $sp_other === (int) ServiceResources::resource( $sp_res )->partner_id && svc_audit( 'service_resource_partner_changed', $sp_res ) );

echo "Service bookings: admin screen rule fields\n";
list( $sx_event, $sx_type ) = svc_event( 4500 );
$sx = ServicesPage::admin_action( array( 'do' => 'save_resource', 'event_id' => $sx_event, 'name' => 'Kurs', 'capacity' => '6', 'default_duration' => '120', 'buffer_before' => '10', 'buffer_after' => '20', 'ticket_type_id' => $sx_type, 'rules_present' => '1', 'rule_cutoff_minutes' => '30', 'rule_opens_days' => '60', 'rule_max_per_customer' => '2', 'rule_max_period' => 'week', 'rule_rebook_deadline_hours' => '12', 'rule_cancel_deadline_hours' => '48', 'rule_price_policy' => 'refund_difference', 'rule_requires_confirmation' => '1', 'rule_confirmation_hours' => '6', 'rule_noshow_policy' => 'forfeit', 'rule_noshow_fee' => '15,50', 'rule_noshow_block_after' => '2', 'rule_waitlist_offer_minutes' => '45' ) );
$sx_row = ServiceResources::resource( $sx['resource_id'] ?? 0 );
$sx_rules = ServiceResources::rules( $sx_row );
check( 'resource form saves price, duration, buffers and all rules (hours → minutes, money → minor units)', $sx_row && (int) $sx_type === (int) $sx_row->ticket_type_id && 120 === (int) $sx_row->default_duration && 20 === (int) $sx_row->buffer_after && 720 === $sx_rules['rebook_deadline_minutes'] && 2880 === $sx_rules['cancel_deadline_minutes'] && 'week' === $sx_rules['max_period'] && 1 === $sx_rules['requires_confirmation'] && 1550 === $sx_rules['noshow_fee_minor'] && 'forfeit' === $sx_rules['noshow_policy'] && 0 === $sx_rules['waitlist_enabled'] && 45 === $sx_rules['waitlist_offer_minutes'] );
check( 'price comes from the linked ticket type', array( 4500, 'EUR' ) === ServiceResources::price_of( $sx_row ) );
list( $foreign_event, $foreign_type ) = svc_event( 100 );
check( 'a ticket type of another event cannot be the price', is_wp_error( ServicesPage::admin_action( array( 'do' => 'save_resource', 'id' => $sx_row->resource_id, 'name' => 'Kurs', 'ticket_type_id' => $foreign_type ) ) ) );
ob_start();
$_GET = array( 'resource_id' => (string) $sx_row->resource_id );
ServicesPage::render();
$_GET = array();
$sx_html = (string) ob_get_clean();
check( 'resource screen renders the rule fields with labels', str_contains( $sx_html, 'Buchungsschluss (Minuten vor Beginn)' ) && str_contains( $sx_html, 'for="wpcap-rule-noshow"' ) && str_contains( $sx_html, 'name="rule_cancel_deadline_hours"' ) && str_contains( $sx_html, 'Puffer nachher' ) );

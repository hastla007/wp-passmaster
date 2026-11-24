<?php
/**
 * Manual payment (bank transfer) cannot block an event: absolute bound per event for unpaid holds
 * (manual_hold_max_event_tickets), bank transfer not offered once the bound is reached, bot check of the
 * public checkout form (Security\FormGuard), admin bulk release of open bank transfers
 * (Admin\ManualHoldsRelease).
 */
// phpcs:ignoreFile

require_once dirname( __DIR__ ) . '/lib/fixtures_shared.php';
use PassCaptain\Admin\ManualHoldsRelease;
use PassCaptain\Orders;
use PassCaptain\PublicEvents;
use PassCaptain\Rest\Controller;
use PassCaptain\Security\FormGuard;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
$mh_saved = array();
foreach ( array( 'manual_hold_max_percent', 'manual_hold_max_event_tickets', 'enabled_providers', 'manual_instructions', 'require_terms', 'checkout_bot_check', 'checkout_min_fill_seconds', 'stripe_secret_key', 'stripe_webhook_secret', 'stripe_mode' ) as $mh_key ) { $mh_saved[ $mh_key ] = Settings::get( $mh_key ); }

function mh_order( $type, $qty, $provider = 'manual' ) {
	wpcap_test_reset_transients();
	return Orders::create( array( 'email' => 'mh-' . uniqid() . '@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => 'mh-' . uniqid() ), array( 'payment_provider' => $provider, 'hold_minutes' => 72 * 60 ) );
}
function mh_reason( $result ) { return is_wp_error( $result ) ? (string) ( $result->get_error_data()['reason'] ?? $result->get_error_code() ) : 'ok'; }
/** Second date of the event of ticket type $type, with its own ticket type. */
function mh_second_type( $type ) {
	global $wpdb;
	$p = $wpdb->prefix;
	$event = (int) $wpdb->get_var( $wpdb->prepare( "SELECT o.event_id FROM {$p}wpcap_ticket_types t INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=t.occurrence_id WHERE t.ticket_type_id=%d", $type ) );
	$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 9 * 86400 ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => 0, 'status' => 'scheduled' ) );
	$occ = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $occ, 'name' => 'Standard', 'price_minor' => 1000, 'currency' => 'EUR', 'capacity' => 0, 'max_per_order' => 10, 'status' => 'active' ) );
	return array( (int) $wpdb->insert_id, $event, $occ );
}
function mh_event_of( $type ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( "SELECT o.event_id,o.occurrence_id,o.starts_at FROM {$wpdb->prefix}wpcap_ticket_types t INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=t.occurrence_id WHERE t.ticket_type_id=%d", $type ) );
}

echo "Manual holds: absolute bound per event\n";
Settings::update( array( 'manual_hold_max_percent' => 0 ) );
check( 'MH-a1: the event bound is off by default (0, former behaviour)', 0 === (int) Settings::defaults()['manual_hold_max_event_tickets'] && 0 === Orders::manual_event_hold_cap() );
$mh_t = make_type( 0, 20, 1000 );
check( 'MH-a2: without the bound many unpaid manual tickets are possible', 'ok' === mh_reason( mh_order( $mh_t, 8 ) ) && 'ok' === mh_reason( mh_order( $mh_t, 8 ) ) );

Settings::update( array( 'manual_hold_max_event_tickets' => 5 ) );
$mh_a = make_type( 0, 20, 1000 );
list( $mh_b, $mh_event, $mh_occ_b ) = mh_second_type( $mh_a );
$mh_first = mh_order( $mh_a, 3 );
$mh_second = mh_order( $mh_b, 2 );
$mh_over = mh_order( $mh_a, 1 );
check( 'MH-a3: unpaid manual orders hold at most the bound over all dates of the event', ! is_wp_error( $mh_first ) && ! is_wp_error( $mh_second ) && 'manual_event_hold_limit' === mh_reason( $mh_over ) && 409 === ( $mh_over->get_error_data()['status'] ?? 0 ) );
check( 'MH-a4: the refusal tells the buyer to choose another payment method', false !== strpos( (string) $mh_over->get_error_message(), 'andere Zahlungsart' ) );
check( 'MH-a5: other payment methods keep working', 'ok' === mh_reason( mh_order( $mh_a, 2, 'stripe' ) ) );
check( 'MH-a6: held tickets of the event are counted', 5 === Orders::manual_held_for_event( $mh_event ) && Orders::manual_holds_exhausted( $mh_event, $mh_occ_b ) );
$mh_other = make_type( 0, 20, 1000 );
check( 'MH-a7: the bound applies per event (another event is not affected)', 'ok' === mh_reason( mh_order( $mh_other, 3 ) ) );

// Not offered on the form once the bound is reached (with another method available).
Settings::update( array( 'enabled_providers' => array( 'manual', 'stripe' ), 'manual_instructions' => 'Bitte überweisen.', 'stripe_secret_key' => 'sk_test_mh', 'stripe_webhook_secret' => 'whsec_mh', 'stripe_mode' => 'test' ) );
$mh_row = mh_event_of( $mh_a );
$mh_card = array_keys( PublicEvents::card_providers( $mh_row, 'EUR' ) );
$mh_free_row = mh_event_of( $mh_other );
$mh_card_free = array_keys( PublicEvents::card_providers( $mh_free_row, 'EUR' ) );
check( 'MH-a8: bank transfer is not offered for an exhausted event, the other method stays' . ' (' . implode( ',', $mh_card ) . ' / ' . implode( ',', $mh_card_free ) . ')', ! in_array( 'manual', $mh_card, true ) && in_array( 'stripe', $mh_card, true ) && in_array( 'manual', $mh_card_free, true ) );
Settings::update( array( 'enabled_providers' => array( 'manual' ) ) );
check( 'MH-a9: bank transfer as the only method stays visible (the checkout refuses with the message)', array( 'manual' ) === array_keys( PublicEvents::card_providers( $mh_row, 'EUR' ) ) );

// A lapsed hold no longer counts.
expire_holds_of( (int) $mh_first['order_id'] );
check( 'MH-a10: a lapsed hold frees the bound again', 'ok' === mh_reason( mh_order( $mh_a, 3 ) ) );
Settings::update( array( 'manual_hold_max_event_tickets' => 0 ) );

echo "Manual holds: bot check of the public checkout form\n";
Settings::update( array( 'enabled_providers' => array( 'manual' ), 'manual_instructions' => 'Bitte überweisen.', 'require_terms' => 0, 'manual_hold_max_percent' => 0 ) );
$mh_g = make_type( 0, 20, 1000 );
$mh_req = static function ( array $extra ) use ( $mh_g ) {
	wpcap_test_reset_transients();
	return Controller::checkout( p1u_request( array( 'email' => 'mh-guard-' . uniqid() . '@example.com', 'items' => array( array( 'ticket_type_id' => $mh_g, 'quantity' => 1 ) ), 'provider' => 'manual', 'idempotency_key' => 'mh-guard-' . uniqid() . uniqid() ) + $extra ) );
};
check( 'MH-b1: the bot check is on by default with 3 seconds', 1 === (int) Settings::defaults()['checkout_bot_check'] && 3 === (int) Settings::defaults()['checkout_min_fill_seconds'] );
Settings::update( array( 'checkout_bot_check' => 1, 'checkout_min_fill_seconds' => 3 ) );
$mh_field = FormGuard::field( 'wpcap-f1' );
check( 'MH-b2: the form carries a hidden, non-focusable trap field', false !== strpos( $mh_field, 'name="wpcap_hp"' ) && false !== strpos( $mh_field, 'hidden' ) && false !== strpos( $mh_field, 'tabindex="-1"' ) && false !== strpos( $mh_field, 'autocomplete="off"' ) );
$mh_trap = $mh_req( array( 'form_guard' => array( 'hp' => 'http://spam.example', 'ms' => 20000 ) ) );
check( 'MH-b3: a filled trap field is refused', is_wp_error( $mh_trap ) && 'wpcap_form_guard' === $mh_trap->get_error_code() && 400 === ( $mh_trap->get_error_data()['status'] ?? 0 ) );
$mh_fast = $mh_req( array( 'form_guard' => array( 'hp' => '', 'ms' => 400 ) ) );
check( 'MH-b4: a form sent faster than the minimum gets a hint (not a lockout)', is_wp_error( $mh_fast ) && 'wpcap_form_too_fast' === $mh_fast->get_error_code() && false !== strpos( $mh_fast->get_error_message(), 'noch einmal' ) );
$mh_human = $mh_req( array( 'form_guard' => array( 'hp' => '', 'ms' => 3500 ) ) );
check( 'MH-b5: a normal submit passes (also right after a too-fast one)', ! is_wp_error( $mh_human ) && 201 === $mh_human->get_status() );
$mh_api = $mh_req( array() );
check( 'MH-b6: REST API clients without the form marker are not affected', ! is_wp_error( $mh_api ) );
$mh_internal = Controller::checkout( p1u_request( array( 'email' => 'mh-internal@example.com', 'items' => array( array( 'ticket_type_id' => $mh_g, 'quantity' => 1 ) ), 'provider' => 'manual', 'form_guard' => array( 'hp' => 'x', 'ms' => 0 ), 'idempotency_key' => 'mh-internal-' . uniqid() . uniqid() ) ), array( 'manual_start_ts' => time() + 5 * 86400 ) );
check( 'MH-b7: internal callers (with context) are not checked', ! is_wp_error( $mh_internal ) || ! in_array( $mh_internal->get_error_code(), array( 'wpcap_form_guard', 'wpcap_form_too_fast' ), true ) );
Settings::update( array( 'checkout_min_fill_seconds' => 0 ) );
check( 'MH-b8: 0 seconds checks the trap field only', ! is_wp_error( $mh_req( array( 'form_guard' => array( 'hp' => '', 'ms' => 0 ) ) ) ) && is_wp_error( $mh_req( array( 'form_guard' => array( 'hp' => 'x', 'ms' => 0 ) ) ) ) );
Settings::update( array( 'checkout_bot_check' => 0 ) );
check( 'MH-b9: switched off, nothing is checked and no trap field is rendered', ! is_wp_error( $mh_req( array( 'form_guard' => array( 'hp' => 'x', 'ms' => 0 ) ) ) ) && '' === FormGuard::field( 'wpcap-f1' ) );
Settings::update( array( 'checkout_bot_check' => 1, 'checkout_min_fill_seconds' => 3 ) );
$mh_template = (string) file_get_contents( WPCAP_PATH . 'templates/event-card.php' );
$mh_js = (string) file_get_contents( WPCAP_PATH . 'assets/checkout.js' );
check( 'MH-b10: the block/shortcode form renders the trap field and checkout.js sends form_guard only with it', false !== strpos( $mh_template, 'FormGuard::field( $wpcap_form_id )' ) && false !== strpos( $mh_js, "querySelector('[name=\"wpcap_hp\"]')" ) && false !== strpos( $mh_js, 'if (trap) body.form_guard' ) );
check( 'MH-b11: the trap field is hidden by the stylesheet as well', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'assets/public.css' ), '.wpcap-hp{display:none!important}' ) );

echo "Manual holds: admin bulk release\n";
Settings::update( array( 'manual_hold_max_percent' => 0 ) );
$mh_r = make_type( 0, 20, 1000 );
$mh_r_event = (int) mh_event_of( $mh_r )->event_id;
$mh_old1 = mh_order( $mh_r, 2 );
$mh_old2 = mh_order( $mh_r, 1 );
$mh_new = mh_order( $mh_r, 1 );
$mh_paid = mh_order( $mh_r, 1 );
$mh_elsewhere = mh_order( make_type( 0, 20, 1000 ), 1 );
$mh_back = gmdate( 'Y-m-d H:i:s', time() - 30 * HOUR_IN_SECONDS );
foreach ( array( $mh_old1, $mh_old2, $mh_paid, $mh_elsewhere ) as $mh_o ) { $wpdb->update( "{$p}wpcap_orders", array( 'created_at' => $mh_back ), array( 'order_id' => $mh_o['order_id'] ) ); }
$mh_list = array_map( static function ( $row ) { return (int) $row['order_id']; }, ManualHoldsRelease::candidates( $mh_r_event, 24 ) );
sort( $mh_list );
$mh_expect = array( (int) $mh_old1['order_id'], (int) $mh_old2['order_id'], (int) $mh_paid['order_id'] );
sort( $mh_expect );
check( 'MH-c1: the list shows open manual orders of the event older than X hours', $mh_expect === $mh_list );
Orders::confirm_manual_payment( (int) $mh_paid['order_id'] );

$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$mh_denied = ManualHoldsRelease::admin_action( array( 'ids' => $mh_list, 'event_id' => $mh_r_event, 'min_hours' => 24, 'confirm' => 1 ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'MH-c2: without manage_options nothing is released', is_wp_error( $mh_denied ) && 403 === ( $mh_denied->get_error_data()['status'] ?? 0 ) && 'pending' === Orders::get( $mh_old1['order_id'] )->payment_status );
$mh_unconfirmed = ManualHoldsRelease::admin_action( array( 'ids' => $mh_list, 'event_id' => $mh_r_event, 'min_hours' => 24 ) );
check( 'MH-c3: without confirmation nothing is released', is_wp_error( $mh_unconfirmed ) && 'pending' === Orders::get( $mh_old1['order_id'] )->payment_status );

$mh_mails = count( $GLOBALS['wpcap_test_mails'] ?? array() );
$mh_cancel_events = array();
$mh_listener = static function ( $order_id ) use ( &$mh_cancel_events ) { $mh_cancel_events[] = (int) $order_id; };
add_action( 'wpcap_order_cancelled', $mh_listener, 99, 1 );
// The posted ids include the new order (not in the list: too young) and an order of another event.
$mh_result = ManualHoldsRelease::admin_action( array( 'ids' => array_merge( $mh_list, array( (int) $mh_new['order_id'], (int) $mh_elsewhere['order_id'] ) ), 'event_id' => $mh_r_event, 'min_hours' => 24, 'confirm' => 1 ) );
remove_action( 'wpcap_order_cancelled', $mh_listener, 99 );
$mh_o1 = Orders::get( $mh_old1['order_id'] );
check( 'MH-c4: the shown open orders are released through the expiry path', is_array( $mh_result ) && 2 === $mh_result['released'] && 'expired' === $mh_o1->payment_status && 'expired' === Orders::get( $mh_old2['order_id'] )->payment_status );
check( 'MH-c5: their holds are freed', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_reservations WHERE order_id IN (%d,%d) AND status='active'", $mh_old1['order_id'], $mh_old2['order_id'] ) ) && 1 === Orders::manual_held_for_event( $mh_r_event ) );
check( 'MH-c6: paid, too young and other-event orders are left alone', 'paid' === Orders::get( $mh_paid['order_id'] )->payment_status && 'pending' === Orders::get( $mh_new['order_id'] )->payment_status && 'pending' === Orders::get( $mh_elsewhere['order_id'] )->payment_status && 3 === $mh_result['skipped'] );
check( 'MH-c7: the normal expiry hooks run, no extra buyer mail', in_array( (int) $mh_old1['order_id'], $mh_cancel_events, true ) && count( $GLOBALS['wpcap_test_mails'] ?? array() ) === $mh_mails );
$mh_audit = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='manual_hold_released' AND object_id IN (%d,%d)", $mh_old1['order_id'], $mh_old2['order_id'] ) );
$mh_audit_run = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='manual_holds_bulk_release' AND object_id=%d", $mh_r_event ) );
check( 'MH-c8: every release and the run are in the audit log', 2 === $mh_audit && 1 === $mh_audit_run );
$mh_handlers = \PassCaptain\Admin\StatusPage::handlers();
check( 'MH-c9: the admin-post action is registered and checks the capability before the nonce', isset( $mh_handlers[ ManualHoldsRelease::ACTION ] ) && preg_match( '/function handle\(\) \{\s*if \( ! current_user_can\( self::CAP \) \)[^\n]*\n\s*Forms::verify/', (string) file_get_contents( WPCAP_PATH . 'src/Admin/ManualHoldsRelease.php' ) ) );

// Clean up: open orders of this module end, settings back.
foreach ( array( $mh_new, $mh_elsewhere ) as $mh_o ) { if ( is_array( $mh_o ) && ! empty( $mh_o['order_id'] ) ) { Orders::cancel_order( $mh_o['order_id'], 'test_cleanup' ); } }
Settings::update( $mh_saved );
wpcap_test_reset_transients();

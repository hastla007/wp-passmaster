<?php
/**
 * A273, A353, A483, A355, A577: the late payment path (payment after the hold lapsed) re-checks
 * occurrence/event status and end, discount code limits and buyer limits; occurrence cancellation
 * also cancels expired orders; optional automatic refund; GDPR erasure settles open payments first;
 * the Stripe session ends with the hold and delayed methods get a longer hold.
 */

// phpcs:ignoreFile

use PassCaptain\Allocations\BuyerLimits;
use PassCaptain\Campaigns;
use PassCaptain\Jobs;
use PassCaptain\Modules\Allocations;
use PassCaptain\Mollie\MollieGateway;
use PassCaptain\OccurrenceCancellation;
use PassCaptain\Orders;
use PassCaptain\Payments\Registry;
use PassCaptain\Privacy;
use PassCaptain\Settings;

if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . ltrim( $p, '/' ); } }
echo "A273/A353/A483/A355/A577: late payment re-checks\n";
global $wpdb;
$p = $wpdb->prefix;
$wpp2_user_before = $GLOBALS['wpcap_test_user_id'] ?? null;
$wpp2_settings_before = array( 'enabled_providers' => Settings::get( 'enabled_providers' ), 'mollie' => Settings::get( 'mollie' ), 'mollie_api_key' => Settings::get( 'mollie_api_key' ) );
$GLOBALS['wpcap_test_user_id'] = 0;

/** Event + occurrence (+ type); returns [type_id, occurrence_id, event_id]. */
function wpp2_type( $starts_offset = 7 * 86400, $ends_offset = null, $capacity = 50, $price = 2500 ) {
	global $wpdb;
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( "{$wpdb->prefix}wpcap_events", array( 'name' => 'Spätzahlung', 'slug' => 'wpp2' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now ) );
	$event = (int) $wpdb->insert_id;
	$row = array( 'event_id' => $event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + $starts_offset ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => 0, 'status' => 'scheduled' );
	if ( null !== $ends_offset ) { $row['ends_at'] = gmdate( 'Y-m-d H:i:s', time() + $ends_offset ); }
	$wpdb->insert( "{$wpdb->prefix}wpcap_occurrences", $row );
	$occ = (int) $wpdb->insert_id;
	$wpdb->insert( "{$wpdb->prefix}wpcap_ticket_types", array( 'occurrence_id' => $occ, 'name' => 'Standard', 'price_minor' => $price, 'currency' => 'EUR', 'capacity' => $capacity, 'max_per_order' => 10, 'status' => 'active' ) );
	return array( (int) $wpdb->insert_id, $occ, $event );
}
/** Pending order (provider stripe) with a lapsed hold. */
function wpp2_lapsed( $type, $qty = 1, $email = 'late@example.com', array $extra = array() ) {
	$GLOBALS['wpcap_test_transients'] = array();
	$r = Orders::create( array_merge( array( 'email' => $email, 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => 'wpp2-' . uniqid( '', true ) ), $extra ), array( 'payment_provider' => 'stripe', 'hold_minutes' => 31 ) );
	if ( is_wp_error( $r ) ) { return $r; }
	expire_holds_of( $r['order_id'] );
	return (int) $r['order_id'];
}
function wpp2_audit( $action, $order_id ) {
	global $wpdb;
	$raw = $wpdb->get_var( $wpdb->prepare( "SELECT details FROM {$wpdb->prefix}wpcap_audit_logs WHERE action=%s AND object_id=%d ORDER BY audit_id DESC LIMIT 1", $action, $order_id ) );
	return null === $raw ? null : (array) json_decode( (string) $raw, true );
}
function wpp2_state( $order_id ) {
	$o = Orders::get( $order_id );
	return $o ? $o->status . '/' . $o->payment_status : '';
}

Settings::update( array( 'stripe_mode' => 'test', 'stripe_secret_key' => 'sk_test_123', 'stripe_webhook_secret' => 'whsec_test', 'enabled_providers' => array( 'manual', 'stripe' ), 'late_payment_auto_refund' => 0 ) );
Registry::reset();
$wpp2_stripe = Registry::get( 'stripe' );

/* ---------------------------------------------------------------- A273 */
echo "A273: occurrence cancelled or ended before a late payment\n";
// Proof t1: hold lapses, the order expires, then the occurrence is cancelled.
list( $wpp2_t1, $wpp2_o1 ) = wpp2_type();
$wpp2_a = wpp2_lapsed( $wpp2_t1, 2, 'cancel-a@example.com' );
Orders::expire_holds();
check( 'precondition: order expired after the hold lapsed', 'expired/expired' === wpp2_state( $wpp2_a ) );
$wpdb->update( "{$p}wpcap_occurrences", array( 'status' => 'cancelled' ), array( 'occurrence_id' => $wpp2_o1 ) );
$wpp2_sum = OccurrenceCancellation::handle( $wpp2_o1 );
check( 'occurrence cancellation also cancels expired orders', 'cancelled/cancelled' === wpp2_state( $wpp2_a ) && $wpp2_sum['cancelled_orders'] >= 1 );
$wpp2_r = Orders::confirm_external_payment( $wpp2_a, 'stripe:late' );
check( 'late payment for it issues no tickets', is_wp_error( $wpp2_r ) && 'wpcap_order_state' === $wpp2_r->get_error_code() && ! tickets( $wpp2_a ) );

// Status changed while the order was still pending (cleanup not run yet).
list( $wpp2_t2, $wpp2_o2 ) = wpp2_type();
$wpp2_b = wpp2_lapsed( $wpp2_t2, 1, 'cancel-b@example.com' );
$wpdb->update( "{$p}wpcap_occurrences", array( 'status' => 'cancelled' ), array( 'occurrence_id' => $wpp2_o2 ) );
$GLOBALS['wpcap_test_mails'] = array();
$wpp2_r = Orders::confirm_external_payment( $wpp2_b, 'stripe:evt-b' );
$wpp2_mail = end( $GLOBALS['wpcap_test_mails'] );
check( 'cancelled occurrence: needs_review/paid_unallocated, no tickets', is_wp_error( $wpp2_r ) && 'wpcap_needs_review' === $wpp2_r->get_error_code() && 'needs_review/paid_unallocated' === wpp2_state( $wpp2_b ) && ! tickets( $wpp2_b ) );
check( 'reason recorded and admin alerted with it', 'occurrence_unavailable' === ( wpp2_audit( 'payment_unallocated', $wpp2_b )['reason'] ?? '' ) && $wpp2_mail && false !== strpos( (string) $wpp2_mail['body'], 'abgesagt' ) );

list( $wpp2_t3, , $wpp2_e3 ) = wpp2_type();
$wpp2_c = wpp2_lapsed( $wpp2_t3 );
$wpdb->update( "{$p}wpcap_events", array( 'status' => 'cancelled' ), array( 'event_id' => $wpp2_e3 ) );
$wpp2_r = Orders::confirm_external_payment( $wpp2_c, 'stripe:evt-c' );
check( 'cancelled event: needs_review, no tickets', is_wp_error( $wpp2_r ) && 'needs_review/paid_unallocated' === wpp2_state( $wpp2_c ) && ! tickets( $wpp2_c ) );

// End: COALESCE(ends_at, starts_at + 6 h).
list( $wpp2_t4, $wpp2_o4 ) = wpp2_type();
$wpp2_d = wpp2_lapsed( $wpp2_t4 );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() - 7 * 3600 ), 'ends_at' => null ), array( 'occurrence_id' => $wpp2_o4 ) );
$wpp2_r = Orders::confirm_external_payment( $wpp2_d, 'stripe:evt-d' );
check( 'occurrence without end, started 7 h ago: ended (start + 6 h), needs_review', is_wp_error( $wpp2_r ) && 'needs_review/paid_unallocated' === wpp2_state( $wpp2_d ) && 'occurrence_ended' === ( wpp2_audit( 'payment_unallocated', $wpp2_d )['reason'] ?? '' ) );
list( $wpp2_t5, $wpp2_o5 ) = wpp2_type();
$wpp2_e = wpp2_lapsed( $wpp2_t5 );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * 86400 ), 'ends_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ) ), array( 'occurrence_id' => $wpp2_o5 ) );
check( 'multi-day occurrence still running (ends_at in the future): late payment issues tickets', true === Orders::confirm_external_payment( $wpp2_e, 'stripe:evt-e' ) && 1 === count( tickets( $wpp2_e, 'valid' ) ) );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * 86400 ), 'ends_at' => gmdate( 'Y-m-d H:i:s', time() - 86400 ) ), array( 'occurrence_id' => $wpp2_o5 ) );
$wpp2_f = Orders::create( array( 'email' => 'x@example.com', 'items' => array( array( 'ticket_type_id' => $wpp2_t5, 'quantity' => 1 ) ), 'idempotency_key' => 'wpp2-f' ), array( 'payment_provider' => 'stripe' ) );
check( 'Orders::occurrence_end(): ends_at, else start + 6 h', '2030-01-01 18:00:00' === Orders::occurrence_end( (object) array( 'starts_at' => '2030-01-01 12:00:00', 'ends_at' => null ) ) && '2030-01-02 01:00:00' === Orders::occurrence_end( (object) array( 'starts_at' => '2030-01-01 12:00:00', 'ends_at' => '2030-01-02 01:00:00' ) ) && is_wp_error( $wpp2_f ) );

// Automatic refund (option).
echo "A273: automatic refund of an unallocated late payment\n";
Settings::update( array( 'late_payment_auto_refund' => 1 ) );
list( $wpp2_t6, $wpp2_o6 ) = wpp2_type();
$wpp2_g = wpp2_lapsed( $wpp2_t6, 2, 'refund-g@example.com' );
Orders::set_provider_reference( $wpp2_g, 'pi_wpp2late' );
$wpdb->update( "{$p}wpcap_occurrences", array( 'status' => 'cancelled' ), array( 'occurrence_id' => $wpp2_o6 ) );
$GLOBALS['wpcap_test_mails'] = array();
Orders::confirm_external_payment( $wpp2_g, 'stripe:evt-g' );
$wpp2_job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_jobs WHERE idempotency_key=%s", hash( 'sha256', 'late-payment-refund|' . $wpp2_g ) ) );
$wpp2_mail = end( $GLOBALS['wpcap_test_mails'] );
check( 'refund job queued and announced in the admin alert', $wpp2_job && 'late_payment_refund' === $wpp2_job->job_type && $wpp2_mail && false !== strpos( (string) $wpp2_mail['body'], 'automatisch erstattet' ) && true === ( wpp2_audit( 'payment_unallocated', $wpp2_g )['auto_refund'] ?? null ) );
add_filter( 'wpcap_process_job', array( Orders::class, 'handle_job' ), 10, 4 ); // As in Plugin::register().
http_reset();
http_push( array( 'error' => array( 'message' => 'temporarily unavailable' ) ), 500, '/v1/refunds' );
$wpdb->update( "{$p}wpcap_jobs", array( 'run_after' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'job_id' => $wpp2_job->job_id ) );
Jobs::process_due( 20 );
check( 'provider failure: order stays for review, job retried later', 'needs_review/paid_unallocated' === wpp2_state( $wpp2_g ) && null !== wpp2_audit( 'late_payment_refund_failed', $wpp2_g ) && 'queued' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$p}wpcap_jobs WHERE job_id=%d", $wpp2_job->job_id ) ) );
http_reset();
http_push( array( 'id' => 're_wpp2late', 'status' => 'succeeded' ), 200, '/v1/refunds' );
$wpdb->update( "{$p}wpcap_jobs", array( 'run_after' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'job_id' => $wpp2_job->job_id ) );
Jobs::process_due( 20 );
remove_filter( 'wpcap_process_job', array( Orders::class, 'handle_job' ), 10 );
$wpp2_req = http_requests( '/v1/refunds' )[0] ?? array( 'body' => '', 'headers' => array() );
$wpp2_body = form_body( $wpp2_req );
$wpp2_order = Orders::get( $wpp2_g );
check( 'full amount refunded with a fixed idempotency key', '5000' === ( $wpp2_body['amount'] ?? '' ) && 'pi_wpp2late' === ( $wpp2_body['payment_intent'] ?? '' ) && 'wpcap-late-refund-' . $wpp2_g === ( $wpp2_req['headers']['Idempotency-Key'] ?? '' ) );
check( 'order refunded, refunded amount booked, no tickets', 'refunded/refunded' === wpp2_state( $wpp2_g ) && (int) $wpp2_order->refunded_minor === (int) $wpp2_order->total_minor && ! tickets( $wpp2_g ) && 're_wpp2late' === ( wpp2_audit( 'late_payment_refunded', $wpp2_g )['provider_reference'] ?? '' ) );
$wpp2_refund_event = array( 'id' => 'evt_wpp2_refund', 'type' => 'charge.refunded', 'data' => array( 'object' => array( 'id' => 'ch_wpp2', 'object' => 'charge', 'currency' => 'eur', 'refunded' => true, 'amount_refunded' => 5000, 'payment_intent' => 'pi_wpp2late', 'metadata' => array( 'wpcap_order' => $wpp2_order->public_id ) ) ) );
$wpp2_res = stripe_deliver( $wpp2_stripe, $wpp2_refund_event );
check( 'the provider refund webhook books nothing twice', 'refunded' === $wpp2_res && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_refunds WHERE order_id=%d", $wpp2_g ) ) );

// Payment for an order cancelled with its occurrence (webhook path) with the option.
list( $wpp2_t7, $wpp2_o7 ) = wpp2_type();
$wpp2_h = wpp2_lapsed( $wpp2_t7, 1, 'closed-h@example.com' );
Orders::set_provider_reference( $wpp2_h, 'cs_test_' . Orders::get( $wpp2_h )->public_id );
Orders::expire_holds();
$wpdb->update( "{$p}wpcap_occurrences", array( 'status' => 'cancelled' ), array( 'occurrence_id' => $wpp2_o7 ) );
OccurrenceCancellation::handle( $wpp2_o7 );
$wpp2_res = stripe_deliver( $wpp2_stripe, stripe_session_event( 'evt_wpp2_h', 'checkout.session.completed', (array) Orders::get( $wpp2_h ) ) );
check( 'payment for the cancelled order: no tickets, refund queued', 'paid_for_closed_order' === $wpp2_res && ! tickets( $wpp2_h ) && (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_jobs WHERE idempotency_key=%s", hash( 'sha256', 'late-payment-refund|' . $wpp2_h ) ) ) );
Settings::update( array( 'late_payment_auto_refund' => 0 ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_jobs WHERE idempotency_key=%s", hash( 'sha256', 'late-payment-refund|' . $wpp2_h ) ) );
list( $wpp2_t8, $wpp2_o8 ) = wpp2_type();
$wpp2_i = wpp2_lapsed( $wpp2_t8 );
$wpdb->update( "{$p}wpcap_occurrences", array( 'status' => 'cancelled' ), array( 'occurrence_id' => $wpp2_o8 ) );
Orders::confirm_external_payment( $wpp2_i, 'stripe:evt-i' );
check( 'without the option no refund is queued', false === ( wpp2_audit( 'payment_unallocated', $wpp2_i )['auto_refund'] ?? null ) && ! $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_jobs WHERE idempotency_key=%s", hash( 'sha256', 'late-payment-refund|' . $wpp2_i ) ) ) );

/* ---------------------------------------------------------------- A353 */
echo "A353: discount code limit on a late payment\n";
list( $wpp2_ct ) = wpp2_type();
Campaigns::create( array( 'code' => 'WPP2ONCE', 'kind' => 'percent', 'value' => 5000, 'currency' => 'EUR', 'usage_limit' => 1 ) );
$wpp2_cid = $GLOBALS['wpp2_cid'] = (int) $wpdb->get_var( "SELECT campaign_id FROM {$p}wpcap_campaigns WHERE code='WPP2ONCE'" );
$wpp2_ca = wpp2_lapsed( $wpp2_ct, 1, 'code-a@example.com', array( 'campaign_codes' => array( 'WPP2ONCE' ) ) );
Orders::expire_holds();
$wpp2_use = static fn( $o ) => (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$p}wpcap_campaign_uses WHERE order_id=%d", $o ) );
$wpp2_used = static fn() => (int) $wpdb->get_var( $wpdb->prepare( "SELECT used_count FROM {$p}wpcap_campaigns WHERE campaign_id=%d", $GLOBALS['wpp2_cid'] ) );
check( 'precondition: expiry released the code', 'released' === $wpp2_use( $wpp2_ca ) && 0 === $wpp2_used() );
$GLOBALS['wpcap_test_transients'] = array();
$wpp2_cb = Orders::create( array( 'email' => 'code-b@example.com', 'campaign_codes' => array( 'WPP2ONCE' ), 'items' => array( array( 'ticket_type_id' => $wpp2_ct, 'quantity' => 1 ) ), 'idempotency_key' => 'wpp2-cb' ), array( 'payment_provider' => 'stripe', 'hold_minutes' => 31 ) );
Orders::confirm_external_payment( $wpp2_cb['order_id'], 'stripe:cb' );
$wpp2_r = Orders::confirm_external_payment( $wpp2_ca, 'stripe:ca-late' );
check( 'code used up by another buyer: late payment flagged for review, no tickets', is_wp_error( $wpp2_r ) && 'needs_review/paid_unallocated' === wpp2_state( $wpp2_ca ) && ! tickets( $wpp2_ca ) && 'campaign_exhausted' === ( wpp2_audit( 'payment_unallocated', $wpp2_ca )['reason'] ?? '' ) );
check( 'usage limit kept: used once, by the other order', 1 === $wpp2_used() && 'committed' === $wpp2_use( $wpp2_cb['order_id'] ) && 'released' === $wpp2_use( $wpp2_ca ) );

Campaigns::create( array( 'code' => 'WPP2TWICE', 'kind' => 'fixed', 'value' => 500, 'currency' => 'EUR', 'usage_limit' => 2 ) );
$wpp2_cid = $GLOBALS['wpp2_cid'] = (int) $wpdb->get_var( "SELECT campaign_id FROM {$p}wpcap_campaigns WHERE code='WPP2TWICE'" );
$wpp2_cc = wpp2_lapsed( $wpp2_ct, 1, 'code-c@example.com', array( 'campaign_codes' => array( 'WPP2TWICE' ) ) );
Orders::expire_holds();
check( 'code still available: late payment redeems it again and issues tickets', true === Orders::confirm_external_payment( $wpp2_cc, 'stripe:cc' ) && 'committed' === $wpp2_use( $wpp2_cc ) && 1 === $wpp2_used() && 1 === count( tickets( $wpp2_cc, 'valid' ) ) );
// Hold lapsed but the cleanup has not run (code still reserved): a failed re-check gives the unit back.
list( $wpp2_ct2, $wpp2_co2 ) = wpp2_type();
$wpp2_cd = wpp2_lapsed( $wpp2_ct2, 1, 'code-d@example.com', array( 'campaign_codes' => array( 'WPP2TWICE' ) ) );
check( 'precondition: code reserved by the lapsed order', 'reserved' === $wpp2_use( $wpp2_cd ) && 2 === $wpp2_used() );
$wpdb->update( "{$p}wpcap_occurrences", array( 'status' => 'cancelled' ), array( 'occurrence_id' => $wpp2_co2 ) );
Orders::confirm_external_payment( $wpp2_cd, 'stripe:cd' );
check( 'flagged order releases its reserved code', 'needs_review/paid_unallocated' === wpp2_state( $wpp2_cd ) && 'released' === $wpp2_use( $wpp2_cd ) && 1 === $wpp2_used() );

/* ---------------------------------------------------------------- A483 */
echo "A483: buyer limit on a late payment\n";
Allocations::register();
list( $wpp2_bt, , $wpp2_be ) = wpp2_type( 7 * 86400, null, 100 );
BuyerLimits::set( $wpp2_be, 0, 2 );
BuyerLimits::flush();
$wpp2_ba = wpp2_lapsed( $wpp2_bt, 2, 'Fan@Example.com' );
$GLOBALS['wpcap_test_transients'] = array();
$wpp2_bb = Orders::create( array( 'email' => 'fan@example.com', 'items' => array( array( 'ticket_type_id' => $wpp2_bt, 'quantity' => 2 ) ), 'idempotency_key' => 'wpp2-bb' ), array( 'payment_provider' => 'stripe', 'hold_minutes' => 31 ) );
check( 'precondition: second order of the buyer accepted after the first hold lapsed', ! is_wp_error( $wpp2_bb ) && true === Orders::confirm_external_payment( $wpp2_bb['order_id'], 'stripe:bb' ) );
$wpp2_r = Orders::confirm_external_payment( $wpp2_ba, 'stripe:ba-late' );
$wpp2_valid = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_orders o ON o.order_id=t.order_id WHERE LOWER(o.customer_email)='fan@example.com' AND t.status='valid'" );
check( 'late payment over the buyer limit flagged for review (limit 2 kept)', is_wp_error( $wpp2_r ) && 'needs_review/paid_unallocated' === wpp2_state( $wpp2_ba ) && 'allocation_buyer_limit' === ( wpp2_audit( 'payment_unallocated', $wpp2_ba )['reason'] ?? '' ) && 2 === $wpp2_valid );
BuyerLimits::set( $wpp2_be, 0, 4 );
BuyerLimits::flush();
$wpp2_bc = wpp2_lapsed( $wpp2_bt, 2, 'fan@example.com' );
check( 'within the limit the late payment issues tickets and counts again', true === Orders::confirm_external_payment( $wpp2_bc, 'stripe:bc' ) && 2 === count( tickets( $wpp2_bc, 'valid' ) ) && 4 === BuyerLimits::count_for( $wpp2_be, 'fan@example.com' ) );
$GLOBALS['wpcap_test_transients'] = array();
$wpp2_bd = Orders::create( array( 'email' => 'fan@example.com', 'items' => array( array( 'ticket_type_id' => $wpp2_bt, 'quantity' => 1 ) ), 'idempotency_key' => 'wpp2-bd' ), array( 'payment_provider' => 'stripe', 'hold_minutes' => 31 ) );
check( 'the recovered order counts for later checkouts', is_wp_error( $wpp2_bd ) && 'allocation_buyer_limit' === ( $wpp2_bd->get_error_data()['reason'] ?? '' ) );
BuyerLimits::set( $wpp2_be, 0, 0 );
BuyerLimits::flush();

/* ---------------------------------------------------------------- A355 */
echo "A355: privacy erasure of a buyer with an open payment\n";
list( $wpp2_pt ) = wpp2_type();
$GLOBALS['wpcap_test_transients'] = array();
$wpp2_pa = Orders::create( array( 'email' => 'erase-me@example.com', 'items' => array( array( 'ticket_type_id' => $wpp2_pt, 'quantity' => 2 ) ), 'idempotency_key' => 'wpp2-pa' ), array( 'payment_provider' => 'stripe', 'hold_minutes' => 31 ) );
$wpp2_pb = wpp2_lapsed( $wpp2_pt, 1, 'erase-me@example.com' );
Orders::expire_holds();
$wpp2_pc = wpp2_lapsed( $wpp2_pt, 1, 'erase-me@example.com' );
list( $wpp2_pt2, $wpp2_po2 ) = wpp2_type();
$wpp2_pd = wpp2_lapsed( $wpp2_pt2, 1, 'erase-me@example.com' );
$wpdb->update( "{$p}wpcap_occurrences", array( 'status' => 'cancelled' ), array( 'occurrence_id' => $wpp2_po2 ) );
Orders::confirm_external_payment( $wpp2_pd, 'stripe:pd' );
check( 'precondition: pending, expired and paid_unallocated orders', 'pending_payment/pending' === wpp2_state( $wpp2_pa['order_id'] ) && 'expired/expired' === wpp2_state( $wpp2_pb ) && 'needs_review/paid_unallocated' === wpp2_state( $wpp2_pd ) );
$GLOBALS['wpcap_test_mails'] = array();
$wpp2_er = Privacy::erase( 'erase-me@example.com' );
$wpp2_active = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_reservations WHERE order_id=%d AND status='active'", $wpp2_pa['order_id'] ) );
check( 'open orders cancelled (holds released) and anonymised', 'cancelled/cancelled' === wpp2_state( $wpp2_pa['order_id'] ) && 'cancelled/cancelled' === wpp2_state( $wpp2_pb ) && 'cancelled/cancelled' === wpp2_state( $wpp2_pc ) && 0 === $wpp2_active && Privacy::is_placeholder_email( Orders::get( $wpp2_pa['order_id'] )->customer_email ) );
$wpp2_r = Orders::confirm_external_payment( $wpp2_pa['order_id'], 'stripe:after-erasure' );
check( 'a payment after the erasure issues no tickets for the placeholder', is_wp_error( $wpp2_r ) && ! tickets( $wpp2_pa['order_id'] ) && ! array_filter( $GLOBALS['wpcap_test_mails'], static fn( $m ) => false !== strpos( (string) ( is_array( $m['to'] ) ? implode( ',', $m['to'] ) : $m['to'] ), 'invalid.example' ) ) );
check( 'paid order awaiting review is deferred, not anonymised, and reported', 'erase-me@example.com' === Orders::get( $wpp2_pd )->customer_email && isset( Privacy::deferred()[ $wpp2_pd ] ) && (bool) array_filter( (array) $wpp2_er['messages'], static fn( $m ) => false !== strpos( $m, 'Zahlungsklärung' ) ) );
Privacy::process_deferred();
check( 'still open: stays deferred', 'erase-me@example.com' === Orders::get( $wpp2_pd )->customer_email && isset( Privacy::deferred()[ $wpp2_pd ] ) );
Orders::mark_refunded( $wpp2_pd, 'test' );
Privacy::process_deferred();
check( 'settled (refunded): anonymised by the deferred run', Privacy::is_placeholder_email( Orders::get( $wpp2_pd )->customer_email ) && ! isset( Privacy::deferred()[ $wpp2_pd ] ) );

/* ---------------------------------------------------------------- A577 */
echo "A577: Stripe session ends with the hold; delayed methods\n";
$wpp2_hold = static fn( $o ) => (int) strtotime( $wpdb->get_var( $wpdb->prepare( "SELECT MIN(expires_at) FROM {$p}wpcap_reservations WHERE order_id=%d AND status='active'", $o ) ) . ' UTC' );
$wpp2_session = static function ( $order_id ) use ( $wpp2_stripe ) {
	http_reset();
	http_push( array( 'id' => 'cs_test_wpp2_' . $order_id, 'url' => 'https://checkout.stripe.com/c/pay/x', 'status' => 'open' ), 200, '/v1/checkout/sessions' );
	$wpp2_stripe->create_payment( (array) Orders::get( $order_id ), order_items_array( $order_id ) );
	return form_body( http_requests( 'checkout/sessions' )[0] ?? array( 'body' => '' ) );
};
check( 'hosted payment pages hold at least 31 minutes', 31 === Orders::HOSTED_HOLD_MINUTES );
list( $wpp2_st ) = wpp2_type();
$GLOBALS['wpcap_test_transients'] = array();
$wpp2_s1 = Orders::create( array( 'email' => 's1@example.com', 'items' => array( array( 'ticket_type_id' => $wpp2_st, 'quantity' => 1 ) ), 'idempotency_key' => 'wpp2-s1' ), array( 'payment_provider' => 'stripe', 'hold_minutes' => Orders::HOSTED_HOLD_MINUTES ) );
$wpp2_f = $wpp2_session( $wpp2_s1['order_id'] );
check( 'session expires exactly with the hold (31 min), never later (proof t7)', (int) $wpp2_f['expires_at'] === $wpp2_hold( $wpp2_s1['order_id'] ) && (int) $wpp2_f['expires_at'] >= time() + 1800 );
$GLOBALS['wpcap_test_transients'] = array();
$wpp2_s2 = Orders::create( array( 'email' => 's2@example.com', 'items' => array( array( 'ticket_type_id' => $wpp2_st, 'quantity' => 1 ) ), 'idempotency_key' => 'wpp2-s2' ), array( 'payment_provider' => 'stripe', 'hold_minutes' => 10 ) );
$wpp2_f = $wpp2_session( $wpp2_s2['order_id'] );
check( 'shorter hold (10 min) is extended to the Stripe minimum: session and hold end together', (int) $wpp2_f['expires_at'] === $wpp2_hold( $wpp2_s2['order_id'] ) && (int) $wpp2_f['expires_at'] >= time() + 1800 );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_reservations SET expires_at=%s WHERE order_id=%d", gmdate( 'Y-m-d H:i:s', time() + 3 * 86400 ), $wpp2_s2['order_id'] ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_orders SET provider_reference='' WHERE order_id=%d", $wpp2_s2['order_id'] ) );
$wpp2_f = $wpp2_session( $wpp2_s2['order_id'] );
check( 'hold longer than a day: session capped below 24 h (ends before the hold)', (int) $wpp2_f['expires_at'] <= time() + 86400 && (int) $wpp2_f['expires_at'] < $wpp2_hold( $wpp2_s2['order_id'] ) );

// Stripe delayed method: checkout.session.completed with payment_status unpaid.
$wpp2_s3 = Orders::create( array( 'email' => 's3@example.com', 'items' => array( array( 'ticket_type_id' => $wpp2_st, 'quantity' => 1 ) ), 'idempotency_key' => 'wpp2-s3' ), array( 'payment_provider' => 'stripe', 'hold_minutes' => 31 ) );
$wpp2_s3o = (array) Orders::get( $wpp2_s3['order_id'] );
Orders::set_provider_reference( $wpp2_s3['order_id'], 'cs_test_' . $wpp2_s3o['public_id'] );
$wpp2_res = stripe_deliver( $wpp2_stripe, stripe_session_event( 'evt_wpp2_s3_done', 'checkout.session.completed', $wpp2_s3o, array( 'payment_status' => 'unpaid' ) ) );
$wpp2_expect = time() + (int) Settings::get( 'manual_hold_hours' ) * 3600;
check( 'SEPA etc.: hold extended like a bank transfer hold', 'hold_extended' === $wpp2_res && abs( $wpp2_hold( $wpp2_s3['order_id'] ) - $wpp2_expect ) < 120 && 'pending' === Orders::get( $wpp2_s3['order_id'] )->payment_status );
$wpp2_res = stripe_deliver( $wpp2_stripe, stripe_session_event( 'evt_wpp2_s3_paid', 'checkout.session.async_payment_succeeded', $wpp2_s3o ) );
check( 'async success two days later still finds the hold: paid, tickets', 'paid' === $wpp2_res && 1 === count( tickets( $wpp2_s3['order_id'], 'valid' ) ) && false === wpp2_audit( 'external_payment_confirmed', $wpp2_s3['order_id'] )['late_recovery'] );
list( $wpp2_st2 ) = wpp2_type( 20 );
$wpp2_s4 = Orders::create( array( 'email' => 's4@example.com', 'items' => array( array( 'ticket_type_id' => $wpp2_st2, 'quantity' => 1 ) ), 'idempotency_key' => 'wpp2-s4' ), array( 'payment_provider' => 'stripe', 'hold_minutes' => 31 ) );
$wpp2_s4o = (array) Orders::get( $wpp2_s4['order_id'] );
$wpp2_before = $wpp2_hold( $wpp2_s4['order_id'] );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 20 ) ), array( 'occurrence_id' => (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$wpp2_st2" ) ) );
$wpp2_res = stripe_deliver( $wpp2_stripe, stripe_session_event( 'evt_wpp2_s4_done', 'checkout.session.completed', $wpp2_s4o, array( 'payment_status' => 'unpaid' ) ) );
check( 'start too close: hold not extended (a later payment is re-checked)', 'hold_not_extended' === $wpp2_res && $wpp2_before === $wpp2_hold( $wpp2_s4['order_id'] ) );

// Mollie bank transfer.
echo "A577: Mollie bank transfer only with a long hold\n";
PassCaptain\Modules\Mollie::register();
Settings::update( array( 'mollie_api_key' => 'test_' . str_repeat( 'Ab1', 10 ), 'enabled_providers' => array( 'manual', 'stripe', 'mollie' ), 'mollie' => array( 'methods' => array( 'ideal', 'banktransfer' ), 'billing_country' => '' ) ) );
Registry::reset();
$wpp2_mollie = Registry::get( 'mollie' );
$wpp2_methods = array( '_embedded' => array( 'methods' => array( array( 'id' => 'ideal', 'description' => 'iDEAL' ), array( 'id' => 'banktransfer', 'description' => 'Überweisung' ) ) ) );
$wpp2_mpay = static function ( $order_id, $id ) use ( $wpp2_mollie, $wpp2_methods ) {
	$GLOBALS['wpcap_test_transients'] = array();
	http_reset();
	http_push( $wpp2_methods, 200, '/v2/methods' );
	$order = (array) Orders::get( $order_id );
	http_push( array( 'id' => $id, 'status' => 'open', 'amount' => array( 'value' => '25.00', 'currency' => 'EUR' ), 'metadata' => array( 'order' => $order['public_id'] ), '_links' => array( 'checkout' => array( 'href' => 'https://www.mollie.com/checkout/x' ) ) ), 201, '/v2/payments' );
	$pay = $wpp2_mollie->create_payment( $order, order_items_array( $order_id ) );
	$req = http_requests( '/v2/payments' )[0] ?? array( 'body' => '' );
	return array( $pay, (array) json_decode( (string) $req['body'], true ) );
};
$GLOBALS['wpcap_test_transients'] = array();
$wpp2_m1 = Orders::create( array( 'email' => 'm1@example.com', 'items' => array( array( 'ticket_type_id' => $wpp2_st, 'quantity' => 1 ) ), 'idempotency_key' => 'wpp2-m1' ), array( 'payment_provider' => 'mollie', 'hold_minutes' => 31 ) );
$wpp2_m1_hold = $wpp2_hold( $wpp2_m1['order_id'] );
list( $wpp2_pay, $wpp2_body ) = $wpp2_mpay( $wpp2_m1['order_id'], 'tr_wpp2m1' );
check( 'no explicit choice: bank transfer left out of the method list, hold unchanged', ! is_wp_error( $wpp2_pay ) && array( 'ideal' ) === ( $wpp2_body['method'] ?? null ) && ! isset( $wpp2_body['dueDate'] ) && $wpp2_m1_hold === $wpp2_hold( $wpp2_m1['order_id'] ) );
$GLOBALS['wpcap_test_transients'] = array();
$wpp2_m2 = Orders::create( array( 'email' => 'm2@example.com', 'items' => array( array( 'ticket_type_id' => $wpp2_st, 'quantity' => 1 ) ), 'idempotency_key' => 'wpp2-m2', 'mollie_method' => 'banktransfer' ), array( 'payment_provider' => 'mollie', 'hold_minutes' => 31 ) );
list( $wpp2_pay, $wpp2_body ) = $wpp2_mpay( $wpp2_m2['order_id'], 'tr_wpp2m2' );
$wpp2_m2_hold = $wpp2_hold( $wpp2_m2['order_id'] );
check( 'bank transfer chosen: hold extended like a bank transfer hold, due date not after it', ! is_wp_error( $wpp2_pay ) && 'banktransfer' === ( $wpp2_body['method'] ?? '' ) && abs( $wpp2_m2_hold - $wpp2_expect ) < 120 && ( $wpp2_body['dueDate'] ?? '' ) === gmdate( 'Y-m-d', $wpp2_m2_hold ) );
// Bank transfer chosen, but the start moved close meanwhile (no bank transfer hold possible).
list( $wpp2_st3, $wpp2_so3 ) = wpp2_type();
$GLOBALS['wpcap_test_transients'] = array();
$wpp2_m3 = Orders::create( array( 'email' => 'm3@example.com', 'items' => array( array( 'ticket_type_id' => $wpp2_st3, 'quantity' => 1 ) ), 'idempotency_key' => 'wpp2-m3', 'mollie_method' => 'banktransfer' ), array( 'payment_provider' => 'mollie', 'hold_minutes' => 31 ) );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 20 ) ), array( 'occurrence_id' => $wpp2_so3 ) );
$wpp2_m3_hold = $wpp2_hold( $wpp2_m3['order_id'] );
list( $wpp2_pay, $wpp2_body ) = $wpp2_mpay( $wpp2_m3['order_id'], 'tr_wpp2m3' );
check( 'bank transfer chosen right before the start: other methods only, hold unchanged', ! is_wp_error( $wpp2_pay ) && array( 'ideal' ) === ( $wpp2_body['method'] ?? null ) && $wpp2_m3_hold === $wpp2_hold( $wpp2_m3['order_id'] ) );
Settings::update( $wpp2_settings_before );
Registry::reset();

// Leave nothing pending that later files could trip over.
foreach ( array( $wpp2_s1['order_id'], $wpp2_s2['order_id'], $wpp2_s4['order_id'], $wpp2_m1['order_id'], $wpp2_m2['order_id'], $wpp2_m3['order_id'] ) as $wpp2_open ) { Orders::cancel_order( $wpp2_open, 'test_cleanup' ); }
if ( null === $wpp2_user_before ) { unset( $GLOBALS['wpcap_test_user_id'] ); } else { $GLOBALS['wpcap_test_user_id'] = $wpp2_user_before; }

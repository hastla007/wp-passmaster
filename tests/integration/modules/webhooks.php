<?php
/**
 * Outgoing webhooks: subscriptions (https only), event fan-out, versioned payload without PII
 * (opt-in), HMAC signature, retries with exponential backoff, max attempts, auto-disable after
 * repeated failures, manual redelivery and the admin actions.
 */

// phpcs:ignoreFile

use PassCaptain\Modules\Webhooks;
use PassCaptain\Orders;
use PassCaptain\Webhooks\Dispatcher;

Webhooks::register();
global $wpdb;
$p = $wpdb->prefix;
$wpdb->query( "DELETE FROM {$p}wpcap_webhook_deliveries" );
$wpdb->query( "DELETE FROM {$p}wpcap_webhook_subscriptions" );

function wh_deliveries( $sub ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_webhook_deliveries WHERE subscription_id=%d ORDER BY delivery_id", $sub ) );
}
function wh_due_now() {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_webhook_deliveries SET next_attempt_at=%s WHERE status='pending'", gmdate( 'Y-m-d H:i:s', time() - 1 ) ) );
}

echo "Webhooks: subscriptions\n";
check( 'http URLs are rejected', is_wp_error( Dispatcher::create_subscription( 'http://hooks.example/x', array( 'order.paid' ) ) ) );
check( 'local and private hosts are rejected', ! Dispatcher::valid_url( 'https://localhost/x' ) && ! Dispatcher::valid_url( 'https://10.0.0.5/x' ) && ! Dispatcher::valid_url( 'https://user@hooks.example/x' ) && Dispatcher::valid_url( 'https://hooks.example/x' ) );
check( 'at least one known event is required', is_wp_error( Dispatcher::create_subscription( 'https://hooks.example/x', array( 'order.deleted' ) ) ) );
$a = Dispatcher::create_subscription( 'https://a.hooks.example/in', array( 'order.paid', 'ticket.issued', 'bogus' ) );
$b = Dispatcher::create_subscription( 'https://b.hooks.example/in', array( 'order.paid' ), true );
check( 'subscription created with whsec_ secret and filtered events', ! is_wp_error( $a ) && 0 === strpos( $a['secret'], 'whsec_' ) && 'order.paid,ticket.issued' === Dispatcher::subscription( $a['subscription_id'] )->events );

echo "Webhooks: events and payloads\n";
$type = make_type( 30, 10, 0 );
$GLOBALS['wpcap_test_transients'] = array();
$r = Orders::create( array( 'email' => 'wh-buyer@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 2 ) ), 'idempotency_key' => 'wh-1' ) );
$da = wh_deliveries( $a['subscription_id'] );
$db = wh_deliveries( $b['subscription_id'] );
$types_a = array_map( static fn( $d ) => $d->event_type, $da );
sort( $types_a );
check( 'paid order fans out to subscribed events only', array( 'order.paid', 'ticket.issued', 'ticket.issued' ) === $types_a && 1 === count( $db ) && 'order.paid' === $db[0]->event_type );
$pa = json_decode( $da[0]->payload, true );
$order = Orders::get( $r['order_id'] );
check( 'payload is versioned with id, type, created and data.object', '2026-11-01' === $pa['api_version'] && 0 === strpos( $pa['id'], 'evt_' ) && is_int( $pa['created'] ) && isset( $pa['data']['object']['object'] ) );
$paid_a = array_values( array_filter( $da, static fn( $d ) => 'order.paid' === $d->event_type ) )[0];
$obj = json_decode( $paid_a->payload, true )['data']['object'];
check( 'order object: public id and status, no internal PII', $order->public_id === $obj['id'] && 'paid' === $obj['payment_status'] && 2 === $obj['ticket_count'] && 'EUR' === $obj['currency'] );
$no_pii = true;
foreach ( $da as $d ) { $no_pii = $no_pii && false === strpos( $d->payload, '@' ) && false === strpos( $d->payload, 'wh-buyer' ); }
check( 'no e-mail addresses in payloads by default', $no_pii );
check( 'opt-in subscription receives the buyer e-mail', 'wh-buyer@example.com' === ( json_decode( $db[0]->payload, true )['data']['object']['customer_email'] ?? '' ) );
$ticket_obj = json_decode( array_values( array_filter( $da, static fn( $d ) => 'ticket.issued' === $d->event_type ) )[0]->payload, true )['data']['object'];
check( 'ticket object references public ids only', 'ticket' === $ticket_obj['object'] && $order->public_id === $ticket_obj['order_id'] && 32 === strlen( $ticket_obj['id'] ) && ! isset( $ticket_obj['token'] ) );
do_action( 'wpcap_order_paid', $r['order_id'] );
do_action( 'wpcap_tickets_issued', $r['order_id'], 2 );
check( 'repeated hooks do not duplicate deliveries (event id dedupe)', 3 === count( wh_deliveries( $a['subscription_id'] ) ) );
check( 'same event has the same id across subscriptions', $obj && json_decode( $paid_a->payload, true )['id'] === json_decode( $db[0]->payload, true )['id'] );

echo "Webhooks: signed delivery\n";
http_reset();
for ( $i = 0; $i < 4; $i++ ) { http_push( array( 'ok' => true ), 200, 'hooks.example' ); }
$done = Dispatcher::process();
$reqs = http_requests( 'hooks.example' );
$valid = 4 === count( $reqs );
foreach ( $reqs as $req ) {
	$secret = false !== strpos( $req['url'], 'a.hooks' ) ? $a['secret'] : $b['secret'];
	$valid = $valid && Dispatcher::verify( $req['body'], $req['headers']['X-WPCAP-Signature'], $secret ) && ! Dispatcher::verify( $req['body'], $req['headers']['X-WPCAP-Signature'], 'whsec_other' ) && ! Dispatcher::verify( $req['body'] . ' ', $req['headers']['X-WPCAP-Signature'], $secret );
}
check( 'all deliveries sent with a valid HMAC-SHA256 signature over timestamp + body', 4 === $done && $valid );
$h = $reqs[0]['headers'];
check( 'event headers, JSON content type, short timeout, no redirects', 'application/json' === $h['Content-Type'] && isset( $h['X-WPCAP-Event'], $h['X-WPCAP-Event-Id'], $h['X-WPCAP-Delivery'] ) && 10 === $reqs[0]['timeout'] && 1 === preg_match( '/^t=\d+,v1=[0-9a-f]{64}$/', $h['X-WPCAP-Signature'] ) );
check( 'body is sent exactly as stored', $reqs[0]['body'] === $wpdb->get_var( $wpdb->prepare( "SELECT payload FROM {$p}wpcap_webhook_deliveries WHERE delivery_id=%d", (int) $h['X-WPCAP-Delivery'] ) ) );
$st = $wpdb->get_row( "SELECT COUNT(*) AS n, SUM(status='succeeded') AS ok, MIN(response_code) AS code FROM {$p}wpcap_webhook_deliveries" );
check( 'deliveries logged as succeeded with response code', 4 === (int) $st->n && 4 === (int) $st->ok && 200 === (int) $st->code );
check( 'stale signatures are rejected', ! Dispatcher::verify( 'x', Dispatcher::sign( 'x', 'k', time() - 400 ), 'k' ) && Dispatcher::verify( 'x', Dispatcher::sign( 'x', 'k', time() ), 'k' ) );

echo "Webhooks: retries, backoff, max attempts, auto-disable\n";
check( 'exponential backoff capped at 6 h', 60 === Dispatcher::backoff( 1 ) && 120 === Dispatcher::backoff( 2 ) && 480 === Dispatcher::backoff( 4 ) && 21600 === Dispatcher::backoff( 20 ) );
Dispatcher::set_status( $a['subscription_id'], false );
Dispatcher::set_status( $b['subscription_id'], false );
$c = Dispatcher::create_subscription( 'https://c.hooks.example/in', array( 'occurrence.cancelled', 'occurrence.rescheduled' ) );
$occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$type" );
Webhooks::occurrence_cancelled( $occ, 2 );
$dc = wh_deliveries( $c['subscription_id'] );
$oc = json_decode( $dc[0]->payload, true )['data']['object'];
check( 'occurrence.cancelled payload with UTC start', 1 === count( $dc ) && $occ === $oc['id'] && 1 === preg_match( '/Z$/', $oc['starts_at'] ) && 2 === $oc['issued_tickets'] );
http_reset();
http_push( array( 'error' => 'down' ), 500, 'c.hooks' );
$before = time();
Dispatcher::process();
$d1 = wh_deliveries( $c['subscription_id'] )[0];
$delay = strtotime( $d1->next_attempt_at . ' UTC' ) - $before;
check( 'failed attempt is retried later (60 s backoff) and logged', 'pending' === $d1->status && 1 === (int) $d1->attempts && 500 === (int) $d1->response_code && 'HTTP 500' === $d1->last_error && $delay >= 59 && $delay <= 62 );
http_reset();
Dispatcher::process();
check( 'not retried before next_attempt_at', ! http_requests( 'c.hooks' ) );
wh_due_now();
Dispatcher::process();
$d1 = wh_deliveries( $c['subscription_id'] )[0];
check( 'network error counts as failed attempt (code 0)', 2 === (int) $d1->attempts && 0 === (int) $d1->response_code && 'pending' === $d1->status );
$GLOBALS['wpcap_test_filters']['wpcap_webhooks_max_attempts'] = 3;
wh_due_now();
http_push( array(), 503, 'c.hooks' );
Dispatcher::process();
$d1 = wh_deliveries( $c['subscription_id'] )[0];
check( 'delivery fails permanently after max attempts', 'failed' === $d1->status && 3 === (int) $d1->attempts );
unset( $GLOBALS['wpcap_test_filters']['wpcap_webhooks_max_attempts'] );
check( 'redeliver queues it again with a fresh budget', true === Dispatcher::redeliver( $d1->delivery_id ) && 'pending' === wh_deliveries( $c['subscription_id'] )[0]->status && 0 === (int) wh_deliveries( $c['subscription_id'] )[0]->attempts );
http_reset();
http_push( array(), 204, 'c.hooks' );
Dispatcher::process();
check( 'redelivered event succeeds and resets the failure counter', 'succeeded' === wh_deliveries( $c['subscription_id'] )[0]->status && 0 === (int) Dispatcher::subscription( $c['subscription_id'] )->consecutive_failures );

$GLOBALS['wpcap_test_filters']['wpcap_webhooks_disable_after'] = 3;
Webhooks::occurrence_rescheduled( $occ, '2030-01-01 10:00:00', '2030-01-02 10:00:00' );
Webhooks::occurrence_rescheduled( $occ, '2030-01-02 10:00:00', '2030-01-03 10:00:00' );
$GLOBALS['wpcap_test_mails'] = array();
for ( $i = 0; $i < 3; $i++ ) {
	wh_due_now();
	http_reset();
	http_push( array(), 500, 'c.hooks' );
	http_push( array(), 500, 'c.hooks' );
	Dispatcher::process( 1 );
}
$sub = Dispatcher::subscription( $c['subscription_id'] );
check( 'subscription disabled after repeated consecutive failures', 'disabled' === $sub->status && 'failures' === $sub->disabled_reason && 3 === (int) $sub->consecutive_failures );
check( 'admin is notified', (bool) array_filter( $GLOBALS['wpcap_test_mails'], static fn( $m ) => false !== strpos( $m['subject'], 'Webhook deaktiviert' ) ) );
wh_due_now();
http_reset();
Dispatcher::process();
check( 'no deliveries to a disabled subscription', ! http_requests( 'c.hooks' ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_webhooks_disable_after'] );
check( 'events for disabled subscriptions are not queued', 0 === Dispatcher::emit( 'occurrence.cancelled', 'x-new', array( 'id' => 1 ) ) );
Dispatcher::set_status( $c['subscription_id'], true );
wh_due_now();
http_reset();
http_push( array(), 200, 'c.hooks' );
http_push( array(), 200, 'c.hooks' );
Dispatcher::process();
check( 're-enabling resumes pending deliveries', 2 === count( http_requests( 'c.hooks' ) ) );

echo "Webhooks: cron hook and admin actions\n";
$hooks = $GLOBALS['wpcap_test_hooks']['wpcap_process_jobs'] ?? array();
$registered = false;
foreach ( $hooks as $callbacks ) { foreach ( $callbacks as $cb ) { $registered = $registered || array( Dispatcher::class, 'process' ) === $cb[0]; } }
check( 'queue processed on the wpcap_process_jobs cron', $registered );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
check( 'admin actions require manage_options', is_wp_error( Webhooks::admin_action( array( 'do' => 'create', 'url' => 'https://d.hooks.example/in', 'events' => array( 'order.paid' ) ) ) ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
$new = Webhooks::admin_action( array( 'do' => 'create', 'url' => 'https://d.hooks.example/in', 'events' => array( 'order.refunded', 'ticket.revoked' ), 'include_pii' => '1' ) );
check( 'admin creates a subscription', ! is_wp_error( $new ) && 1 === (int) Dispatcher::subscription( $new['subscription_id'] )->include_pii );
$old_secret = $new['secret'];
$rot = Webhooks::admin_action( array( 'do' => 'rotate', 'id' => $new['subscription_id'] ) );
check( 'secret rotation', ! is_wp_error( $rot ) && $rot['secret'] !== $old_secret && $rot['secret'] === Dispatcher::subscription( $new['subscription_id'] )->secret );
$tid = (int) tickets( $r['order_id'] )[0]->ticket_id;
do_action( 'wpcap_ticket_revoked', $tid, 'Fraud Verdacht!' );
$rev = wh_deliveries( $new['subscription_id'] );
$ro = json_decode( $rev[0]->payload ?? '{}', true )['data']['object'] ?? array();
check( 'ticket.revoked event with sanitized reason', 1 === count( $rev ) && 'ticket.revoked' === $rev[0]->event_type && 'fraudverdacht' === $ro['reason'] );
check( 'disable/enable and unknown ids', ! is_wp_error( Webhooks::admin_action( array( 'do' => 'disable', 'id' => $new['subscription_id'] ) ) ) && 'disabled' === Dispatcher::subscription( $new['subscription_id'] )->status && is_wp_error( Webhooks::admin_action( array( 'do' => 'disable', 'id' => 999999 ) ) ) && is_wp_error( Webhooks::admin_action( array( 'do' => 'redeliver', 'id' => 999999 ) ) ) );
check( 'delete removes subscription and its log', ! is_wp_error( Webhooks::admin_action( array( 'do' => 'delete', 'id' => $new['subscription_id'] ) ) ) && null === Dispatcher::subscription( $new['subscription_id'] ) && ! wh_deliveries( $new['subscription_id'] ) );
echo "Webhooks: personal data retention\n";
$pii_logged = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_webhook_deliveries WHERE subscription_id=%d AND payload LIKE %s", $b['subscription_id'], '%wh-buyer@example.com%' ) );
$bd = wh_deliveries( $b['subscription_id'] )[0];
check( 'e-mail removed from the payload after the final delivery', 0 === $pii_logged && 'succeeded' === $bd->status && 1 === (int) $bd->pii_purged && ! isset( json_decode( $bd->payload, true )['data']['object']['customer_email'] ) );
Dispatcher::set_status( $b['subscription_id'], true );
check( 'redelivery rebuilds opt-in data from current records', true === Dispatcher::redeliver( $bd->delivery_id ) && 'wh-buyer@example.com' === ( json_decode( wh_deliveries( $b['subscription_id'] )[0]->payload, true )['data']['object']['customer_email'] ?? '' ) );
check( 'privacy eraser removes the e-mail from pending payloads', 1 === Dispatcher::erase_email( 'wh-buyer@example.com' ) && false === strpos( wh_deliveries( $b['subscription_id'] )[0]->payload, 'wh-buyer@example.com' ) );
// The buyer's event is over (erasure is not deferred for upcoming dates).
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_ticket_types tt ON tt.occurrence_id=o.occurrence_id INNER JOIN {$p}wpcap_tickets t ON t.ticket_type_id=tt.ticket_type_id INNER JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id SET o.starts_at=%s,o.ends_at=NULL WHERE ord.customer_email=%s", gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ), 'wh-buyer@example.com' ) );
PassCaptain\Privacy::erase( 'wh-buyer@example.com' );
Dispatcher::redeliver( $bd->delivery_id );
check( 'redelivery after erasure sends no (anonymised) address', ! isset( json_decode( wh_deliveries( $b['subscription_id'] )[0]->payload, true )['data']['object']['customer_email'] ) );
$GLOBALS['wpcap_test_filters']['wpcap_webhooks_max_attempts'] = 1;
http_reset();
http_push( array(), 500, 'b.hooks' );
Dispatcher::set_status( $c['subscription_id'], false );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_webhook_deliveries SET payload=%s WHERE delivery_id=%d", str_replace( '"ticket_count"', '"customer_email":"x@example.com","ticket_count"', wh_deliveries( $b['subscription_id'] )[0]->payload ), $bd->delivery_id ) );
Dispatcher::process();
unset( $GLOBALS['wpcap_test_filters']['wpcap_webhooks_max_attempts'] );
$bd = wh_deliveries( $b['subscription_id'] )[0];
check( 'e-mail also removed after the final failed attempt', 'failed' === $bd->status && false === strpos( $bd->payload, 'x@example.com' ) && 1 === (int) $bd->pii_purged );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_webhook_deliveries SET updated_at=%s WHERE status IN ('succeeded','failed')", gmdate( 'Y-m-d H:i:s', time() - 31 * 86400 ) ) );
$pending_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_webhook_deliveries WHERE status='pending'" );
check( 'final deliveries older than the retention period are purged', Dispatcher::purge_old() > 0 && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_webhook_deliveries WHERE status IN ('succeeded','failed')" ) && $pending_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_webhook_deliveries WHERE status='pending'" ) );
check( 'retention setting is bounded', array( 'retention_days' => 365 ) === Webhooks::admin_action( array( 'do' => 'settings', 'retention_days' => '9999' ) ) && 365 === Dispatcher::retention_days() );
Webhooks::admin_action( array( 'do' => 'settings', 'retention_days' => '30' ) );
$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );
check( 'webhook eraser registered', isset( $erasers['wpcap-webhooks'] ) );
// Leave no active subscriptions for later test files.
$wpdb->query( "UPDATE {$p}wpcap_webhook_subscriptions SET status='disabled'" );
http_reset();

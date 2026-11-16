<?php
/**
 * X4: concrete connectors – HTTP/JSON connector (validation, Vault secret, event selection, field
 * mapping, PII opt-in, job-queue delivery with retries, idempotency, delivery log, test send, manual
 * retry, admin permissions) and tokenized iCalendar subscription feeds (scopes, reschedule/cancel
 * updates via SEQUENCE, token revocation and rotation).
 */
// phpcs:ignoreFile
use PassCaptain\Integrations\CalendarFeeds;
use PassCaptain\Integrations\ConnectorEvents;
use PassCaptain\Integrations\ConnectorsPage;
use PassCaptain\Integrations\HttpJson\Endpoints;
use PassCaptain\Jobs;

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
unset( $GLOBALS['wpcap_test_user_can'] );
wpcap_test_reset_transients();
\PassCaptain\Security\Vault::set_keys_for_tests( str_repeat( "\x43", 32 ) );
PassCaptain\Modules\Connectors::register();
ConnectorEvents::register();
update_option( 'wpcap_enabled_connectors', array() );
$GLOBALS['wpcap_test_http_queue'] = array();
$GLOBALS['wpcap_test_http_requests'] = array();
$x4c_run = static function () use ( $wpdb, $p ) {
	$wpdb->query( "UPDATE {$p}wpcap_jobs SET run_after='2000-01-01 00:00:00' WHERE job_type='connector_delivery' AND status='queued'" );
	Jobs::process_due( 200 );
};
$x4c_requests = static function ( $host ) {
	return array_values( array_filter( $GLOBALS['wpcap_test_http_requests'], static fn( $r ) => false !== strpos( $r['url'], $host ) ) );
};

echo "X4: HTTP/JSON connector\n";
$bad = Endpoints::save( array( 'name' => 'Intern', 'url' => 'http://crm.example/hook', 'events' => array( 'order_paid' ) ) );
check( 'http target is refused (https only)', is_wp_error( $bad ) && 'wpcap_outbound_https_required' === $bad->get_error_code() );
$GLOBALS['wpcap_test_dns']['crm-internal.example'] = array( '10.0.0.5' );
$bad = Endpoints::save( array( 'name' => 'Intern', 'url' => 'https://crm-internal.example/hook', 'events' => array( 'order_paid' ) ) );
check( 'private target address is refused (SSRF)', is_wp_error( $bad ) && 'wpcap_outbound_private_target' === $bad->get_error_code() );
$bad = Endpoints::save( array( 'name' => 'X', 'url' => 'https://crm.example/hook', 'events' => array( 'order_paid' ), 'auth_header' => 'Host' ) );
check( 'reserved auth header name is refused', is_wp_error( $bad ) && 'wpcap_connector_header' === $bad->get_error_code() );
check( 'at least one event is required', is_wp_error( Endpoints::save( array( 'name' => 'X', 'url' => 'https://crm.example/hook', 'events' => array( 'nope' ) ) ) ) );

$mapping = array(
	array( 'source' => 'order.id', 'key' => 'orderId' ),
	array( 'source' => 'order.total_minor', 'key' => 'amount.cents' ),
	array( 'source' => 'order.currency', 'key' => 'amount.currency' ),
	array( 'source' => 'order.customer_email', 'key' => 'customer.email' ),
	array( 'source' => 'event.name', 'key' => 'eventName' ),
	array( 'source' => 'ticket.id', 'key' => 'ticketId' ),
	array( 'source' => 'attendee.name', 'key' => 'attendee' ),
	array( 'source' => 'bogus.field', 'key' => 'x' ),
	array( 'source' => 'order.status', 'key' => 'bad key!' ),
	array( 'source' => 'order.status', 'key' => 'orderId' ),
);
$saved = Endpoints::save( array( 'name' => 'CRM', 'url' => 'https://crm.example/hook', 'auth_header' => 'Authorization', 'secret' => 'Bearer x4-crm-secret-123456', 'events' => array( 'order_paid', 'ticket_checked_in' ), 'mapping' => $mapping, 'include_pii' => 0 ) );
$eid = is_array( $saved ) ? (int) $saved['endpoint_id'] : 0;
$ep = Endpoints::get( $eid );
check( 'endpoint saved and enabled as connector http_json_{id}', $eid && in_array( 'http_json_' . $eid, get_option( 'wpcap_enabled_connectors' ), true ) && in_array( 'http_json_' . $eid, ConnectorEvents::enabled_keys(), true ) );
check( 'mapping: unknown sources, invalid and duplicate keys are dropped', 7 === count( $ep->mapping ) );
check( 'save reports that PII fields are dropped without opt-in', true === $saved['pii_dropped'] );
$raw = $wpdb->get_var( "SELECT secret FROM {$p}wpcap_connector_endpoints WHERE endpoint_id=$eid" );
check( 'auth header value is stored Vault-encrypted', 0 === strpos( (string) $raw, 'pcv1:' ) && false === strpos( (string) $raw, 'x4-crm-secret' ) && 'Bearer x4-crm-secret-123456' === Endpoints::secret( $ep ) );
Endpoints::save( array( 'name' => 'CRM', 'url' => 'https://crm.example/hook', 'secret' => '', 'events' => array( 'order_paid', 'ticket_checked_in' ), 'mapping' => $mapping ), $eid );
check( 'saving with an empty secret keeps the stored one', 'Bearer x4-crm-secret-123456' === Endpoints::secret( Endpoints::get( $eid ) ) );

// Delivery through the job queue.
$type = make_type( 50, 10, 1500 );
$GLOBALS['wpcap_test_http_queue'][] = array( 'match' => 'crm.example', 'code' => 202, 'body' => '' );
$oid = admin_paid_order( $type, 2, 'x4-conn@example.com' );
$wpdb->insert( "{$p}wpcap_ticket_assignments", array( 'ticket_id' => (int) $wpdb->get_var( "SELECT MIN(ticket_id) FROM {$p}wpcap_tickets WHERE order_id=$oid" ), 'buyer_email' => 'x4-conn@example.com', 'attendee_email' => 'alex@example.com', 'attendee_name' => 'Alex Muster', 'status' => 'assigned', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$x4c_run();
$reqs = $x4c_requests( 'crm.example' );
check( 'only the selected event is sent (order_paid, not tickets_issued)', 1 === count( $reqs ) );
$body = json_decode( $reqs[0]['body'] ?? '', true );
$order_public = (string) $wpdb->get_var( "SELECT public_id FROM {$p}wpcap_orders WHERE order_id=$oid" );
check( 'body envelope: contract version, event, idempotency key', 1 === $body['contract_version'] && 'order_paid' === $body['event'] && 64 === strlen( $body['idempotency_key'] ) && $body['idempotency_key'] === $reqs[0]['headers']['Idempotency-Key'] );
check( 'mapping: chosen keys incl. nesting and tickets[]', $order_public === $body['data']['orderId'] && 3000 === $body['data']['amount']['cents'] && 'EUR' === $body['data']['amount']['currency'] && 'Konzert' === $body['data']['eventName'] && 2 === count( $body['data']['tickets'] ) && isset( $body['data']['tickets'][0]['ticketId'] ) );
check( 'PII without opt-in: neither e-mail nor attendee name are sent', ! isset( $body['data']['customer'] ) && ! array_key_exists( 'attendee', $body['data']['tickets'][0] ) && false === strpos( $reqs[0]['body'], 'x4-conn@example.com' ) && false === strpos( $reqs[0]['body'], 'Alex Muster' ) );
check( 'auth header and event header are sent', 'Bearer x4-crm-secret-123456' === ( $reqs[0]['headers']['Authorization'] ?? '' ) && 'order_paid' === ( $reqs[0]['headers']['X-PassCaptain-Event'] ?? '' ) );
$log = $wpdb->get_row( "SELECT * FROM {$p}wpcap_connector_deliveries WHERE endpoint_id=$eid AND event='order_paid'" );
check( 'delivery log: delivered, one attempt, HTTP status, payload without PII', $log && 'delivered' === $log->status && 1 === (int) $log->attempts && 202 === (int) $log->response_code && false === strpos( (string) $log->payload, 'x4-conn@example.com' ) );

// PII opt-in.
Endpoints::save( array( 'name' => 'CRM', 'url' => 'https://crm.example/hook', 'events' => array( 'order_paid', 'ticket_checked_in' ), 'mapping' => $mapping, 'include_pii' => 1 ), $eid );
$data = Endpoints::map( Endpoints::get( $eid ), 'order_paid', array( 'order_id' => $oid ) );
check( 'PII opt-in: e-mail and attendee name are mapped', 'x4-conn@example.com' === $data['customer']['email'] && in_array( 'Alex Muster', array_column( $data['tickets'], 'attendee' ), true ) );
$plain = Endpoints::map( (object) array( 'endpoint_id' => $eid, 'mapping' => array(), 'include_pii' => 1 ), 'order_paid', array( 'order_id' => $oid, 'status' => 'paid' ) );
check( 'without mapping the core payload (identifiers only) is sent', array( 'order_id' => $oid, 'status' => 'paid' ) === $plain );

// Retries and idempotency.
$GLOBALS['wpcap_test_http_requests'] = array();
$tid = (int) $wpdb->get_var( "SELECT MIN(ticket_id) FROM {$p}wpcap_tickets WHERE order_id=$oid" );
$GLOBALS['wpcap_test_http_queue'][] = array( 'match' => 'crm.example', 'code' => 500, 'body' => 'down' );
do_action( 'wpcap_ticket_checked_in', $tid, array( 'source' => 'web', 'idempotency_key' => 'x4c-scan-1' ) );
$x4c_run();
$log = $wpdb->get_row( "SELECT * FROM {$p}wpcap_connector_deliveries WHERE endpoint_id=$eid AND event='ticket_checked_in'" );
$job = $wpdb->get_row( "SELECT status,attempts FROM {$p}wpcap_jobs WHERE job_type='connector_delivery' AND payload LIKE '%x4%' ORDER BY job_id DESC LIMIT 1" );
check( 'HTTP 500: logged as retrying with the status code, job stays queued', $log && 'retrying' === $log->status && 500 === (int) $log->response_code && 1 === (int) $log->attempts );
$x4c_fail = static function () { return new WP_Error( 'http_request_failed', 'timeout' ); };
add_filter( 'pre_http_request', $x4c_fail );
$x4c_run();
remove_filter( 'pre_http_request', $x4c_fail );
$log = $wpdb->get_row( "SELECT * FROM {$p}wpcap_connector_deliveries WHERE endpoint_id=$eid AND event='ticket_checked_in'" );
check( 'transport error: retried again (2 attempts)', 'retrying' === $log->status && 2 === (int) $log->attempts );
$GLOBALS['wpcap_test_http_queue'][] = array( 'match' => 'crm.example', 'code' => 200, 'body' => '' );
$x4c_run();
$log = $wpdb->get_row( "SELECT * FROM {$p}wpcap_connector_deliveries WHERE endpoint_id=$eid AND event='ticket_checked_in'" );
$reqs = $x4c_requests( 'crm.example' );
$keys = array_unique( array_map( static fn( $r ) => $r['headers']['Idempotency-Key'] ?? '', $reqs ) );
check( 'third attempt delivered; every attempt carried the same idempotency key', 'delivered' === $log->status && 3 === (int) $log->attempts && 2 === count( $reqs ) && 1 === count( $keys ) );
$check_in_body = json_decode( end( $reqs )['body'], true );
check( 'check-in event: ticket fields of the scanned ticket only', 1 === count( $check_in_body['data']['tickets'] ) && 'Alex Muster' === $check_in_body['data']['tickets'][0]['attendee'] );
// The same delivery again (e.g. job re-run after a crash): no second request.
$GLOBALS['wpcap_test_http_requests'] = array();
$again = Endpoints::deliver( Endpoints::get( $eid ), 'ticket_checked_in', json_decode( $log->payload, true ), $log->idempotency_key );
check( 'idempotent: an already delivered key is acknowledged without a request', true === $again && array() === $x4c_requests( 'crm.example' ) );
$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_jobs WHERE job_type='connector_delivery'" );
ConnectorEvents::ticket_checked_in( $tid, array( 'source' => 'web', 'idempotency_key' => 'x4c-scan-1' ) );
check( 'the same domain event is queued only once per endpoint', $n === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_jobs WHERE job_type='connector_delivery'" ) );

// Attempts exhausted → failed; manual retry.
$wpdb->insert( "{$p}wpcap_connector_deliveries", array( 'endpoint_id' => $eid, 'event' => 'order_paid', 'idempotency_key' => hash( 'sha256', 'x4c-manual' ), 'payload' => wp_json_encode( array( 'event' => 'order_paid', 'data' => array( 'order_id' => $oid ) ) ), 'status' => 'retrying', 'attempts' => Jobs::MAX_ATTEMPTS - 1, 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$manual = (int) $wpdb->insert_id;
$GLOBALS['wpcap_test_http_queue'][] = array( 'match' => 'crm.example', 'code' => 503, 'body' => '' );
$r = ConnectorsPage::action( array( 'do' => 'retry', 'id' => $manual ) );
check( 'last attempt fails: status failed', is_wp_error( $r ) && 'failed' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_connector_deliveries WHERE delivery_id=$manual" ) );
$GLOBALS['wpcap_test_http_queue'][] = array( 'match' => 'crm.example', 'code' => 204, 'body' => '' );
$r = ConnectorsPage::action( array( 'do' => 'retry', 'id' => $manual ) );
check( 'manual retry from the log delivers with the same key', is_array( $r ) && 'delivered' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_connector_deliveries WHERE delivery_id=$manual" ) && hash( 'sha256', 'x4c-manual' ) === ( end( $GLOBALS['wpcap_test_http_requests'] )['headers']['Idempotency-Key'] ?? '' ) );

// Test send.
$GLOBALS['wpcap_test_http_requests'] = array();
$GLOBALS['wpcap_test_http_queue'][] = array( 'match' => 'crm.example', 'code' => 200, 'body' => '' );
$r = ConnectorsPage::action( array( 'do' => 'test', 'id' => $eid ) );
$test_body = json_decode( $x4c_requests( 'crm.example' )[0]['body'] ?? '', true );
check( 'test send: synthetic event, logged, no stored data', is_array( $r ) && 200 === $r['response_code'] && 'test' === $test_body['event'] && true === $test_body['data']['test'] && false === strpos( wp_json_encode( $test_body ), 'x4-conn@example.com' ) && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_connector_deliveries WHERE endpoint_id=$eid AND event='test'" ) );
$GLOBALS['wpcap_test_dns']['crm.example'] = array( '127.0.0.1' );
$r = Endpoints::test_send( $eid );
unset( $GLOBALS['wpcap_test_dns']['crm.example'] );
check( 'outbound HTTP is SSRF-checked at send time (DNS now private)', is_wp_error( $r ) && 'wpcap_outbound_private_target' === $r->get_error_code() );

// Admin permissions and disable/delete.
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$r = ConnectorsPage::action( array( 'do' => 'delete', 'id' => $eid ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'admin actions require manage_options', is_wp_error( $r ) && Endpoints::get( $eid ) );
ConnectorsPage::action( array( 'do' => 'disable', 'id' => $eid ) );
check( 'disabled endpoint is removed from the enabled connectors', ! in_array( 'http_json_' . $eid, (array) get_option( 'wpcap_enabled_connectors' ), true ) );
ConnectorsPage::action( array( 'do' => 'delete', 'id' => $eid ) );
check( 'delete removes endpoint and log', null === Endpoints::get( $eid ) && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_connector_deliveries WHERE endpoint_id=$eid" ) );
if ( function_exists( 'esc_url' ) && function_exists( 'submit_button' ) && function_exists( 'wp_nonce_field' ) && function_exists( 'checked' ) ) { // Admin fakes of the admin screen tests.
	Endpoints::save( array( 'name' => 'Render', 'url' => 'https://crm.example/hook', 'secret' => 'Bearer x4-render-secret-987654', 'events' => array( 'order_paid' ), 'mapping' => $mapping ) );
	wpcap_test_reset_transients();
	ob_start();
	try { ConnectorsPage::render(); } catch ( Throwable $e ) { echo 'EXCEPTION ' . $e->getMessage(); }
	$html = (string) ob_get_clean();
	check( 'admin page renders connectors, mapping and feeds without secrets', false !== strpos( $html, 'HTTP/JSON-Connectors' ) && false !== strpos( $html, 'mapping_source[]' ) && false !== strpos( $html, 'Kalender-Abos' ) && false === strpos( $html, 'x4-render-secret' ) && false === strpos( $html, 'EXCEPTION' ) );
}

echo "X4: calendar subscription feeds\n";
$etype = make_type( 20, 10, 0 );
$occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$etype" );
$event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$occ" );
$wpdb->update( "{$p}wpcap_events", array( 'name' => 'X4 Feed Konzert' ), array( 'event_id' => $event ) );
$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 14 * 86400 ), 'timezone' => 'Europe/Vienna', 'location' => 'Graz', 'capacity' => 0, 'status' => 'scheduled' ) );
$occ2 = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'X4 Entwurf', 'slug' => 'x4-draft-' . uniqid(), 'description' => '', 'status' => 'draft', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$draft = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $draft, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 3 * 86400 ), 'timezone' => 'UTC', 'location' => '', 'capacity' => 0, 'status' => 'scheduled' ) );
check( 'unknown scope or event is refused', is_wp_error( CalendarFeeds::create( 'bogus' ) ) && is_wp_error( CalendarFeeds::create( 'event', 999999 ) ) );
$feed = ConnectorsPage::action( array( 'do' => 'feed_create', 'scope' => 'event', 'event_id' => $event, 'label' => 'Presse' ) );
check( 'feed created: 64-hex token, only its hash stored', is_array( $feed ) && preg_match( '/^[a-f0-9]{64}$/', $feed['token'] ) && hash( 'sha256', $feed['token'] ) === $wpdb->get_var( "SELECT token_hash FROM {$p}wpcap_calendar_feeds WHERE feed_id=" . (int) $feed['feed_id'] ) && false !== strpos( $feed['url'], 'wpcap_ics=feed' ) );
$ics = CalendarFeeds::render( $feed['token'] );
check( 'event feed: both occurrences as VEVENT with stable UID, SEQUENCE 0', 2 === substr_count( (string) $ics, 'BEGIN:VEVENT' ) && false !== strpos( $ics, 'UID:wpcap-occurrence-' . $occ . '@' ) && false !== strpos( $ics, 'SEQUENCE:0' ) && false !== strpos( $ics, 'X-WR-CALNAME:Presse' ) && false !== strpos( $ics, 'REFRESH-INTERVAL' ) );
check( 'feed contains no buyer data and no draft events', false === strpos( $ics, '@example.com' ) && false === strpos( $ics, 'X4 Entwurf' ) );
$all = CalendarFeeds::create( 'all', 0, '' );
check( 'site-wide feed includes the event but never drafts', false !== strpos( (string) CalendarFeeds::render( $all['token'] ), 'X4 Feed Konzert' ) && false === strpos( (string) CalendarFeeds::render( $all['token'] ), 'X4 Entwurf' ) );

// Reschedule and cancel update the entries.
$new_start = gmdate( 'Y-m-d H:i:s', time() + 8 * 86400 );
$old_start = $wpdb->get_var( "SELECT starts_at FROM {$p}wpcap_occurrences WHERE occurrence_id=$occ" );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => $new_start ), array( 'occurrence_id' => $occ ) );
do_action( 'wpcap_occurrence_rescheduled', $occ, $old_start, $new_start );
$ics = CalendarFeeds::render( $feed['token'] );
$vevent = static function ( $ics, $id ) { return preg_match( '/BEGIN:VEVENT\r\nUID:wpcap-occurrence-' . $id . '@.*?END:VEVENT/s', (string) $ics, $m ) ? $m[0] : ''; };
check( 'reschedule: new DTSTART and SEQUENCE raised once', false !== strpos( $vevent( $ics, $occ ), 'DTSTART:' . gmdate( 'Ymd\THis\Z', strtotime( $new_start . ' UTC' ) ) ) && false !== strpos( $vevent( $ics, $occ ), 'SEQUENCE:1' ) && false !== strpos( $vevent( $ics, $occ ), 'LAST-MODIFIED:' ) );
$wpdb->update( "{$p}wpcap_occurrences", array( 'location' => 'Linz' ), array( 'occurrence_id' => $occ2 ) );
$ics = CalendarFeeds::render( $feed['token'] );
check( 'change without hook (location) is detected at render: SEQUENCE raised', false !== strpos( $vevent( $ics, $occ2 ), 'SEQUENCE:1' ) && false !== strpos( $vevent( $ics, $occ2 ), 'LOCATION:Linz' ) );
$wpdb->update( "{$p}wpcap_occurrences", array( 'status' => 'cancelled' ), array( 'occurrence_id' => $occ ) );
do_action( 'wpcap_occurrence_cancelled', $occ, 0 );
$ics = CalendarFeeds::render( $feed['token'] );
check( 'cancel: STATUS:CANCELLED and SEQUENCE 2', false !== strpos( $vevent( $ics, $occ ), 'STATUS:CANCELLED' ) && false !== strpos( $vevent( $ics, $occ ), 'SEQUENCE:2' ) );
$ics_again = CalendarFeeds::render( $feed['token'] );
check( 'unchanged data: sequence stays (no bump per request)', false !== strpos( $vevent( $ics_again, $occ ), 'SEQUENCE:2' ) );

// Organizer (partner) feed.
$partner = \PassCaptain\Partners\Repository::save( array( 'name' => 'X4 Veranstalter', 'currency' => 'EUR' ) );
\PassCaptain\Partners\Repository::set_events( $partner, array( $event ) );
$pf = CalendarFeeds::create( 'partner', $partner, '' );
check( 'organizer feed: events of the partner', is_array( $pf ) && 2 === substr_count( (string) CalendarFeeds::render( $pf['token'] ), 'BEGIN:VEVENT' ) );

// Revocation and rotation.
$r = ConnectorsPage::action( array( 'do' => 'feed_rotate', 'id' => $feed['feed_id'] ) );
check( 'rotate: old token invalid, new token works', is_array( $r ) && null === CalendarFeeds::render( $feed['token'] ) && null !== CalendarFeeds::render( $r['token'] ) );
ConnectorsPage::action( array( 'do' => 'feed_revoke', 'id' => $feed['feed_id'] ) );
check( 'revoke: the token answers no feed any more', null === CalendarFeeds::render( $r['token'] ) && null === CalendarFeeds::find( $r['token'] ) );
check( 'malformed and unknown tokens: no feed', null === CalendarFeeds::render( 'abc' ) && null === CalendarFeeds::render( str_repeat( 'f', 64 ) ) );
check( 'a revoked feed cannot be rotated back to life', is_wp_error( CalendarFeeds::rotate( $feed['feed_id'] ) ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$r = ConnectorsPage::action( array( 'do' => 'feed_revoke', 'id' => $all['feed_id'] ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'feed administration requires manage_options', is_wp_error( $r ) && null !== CalendarFeeds::find( $all['token'] ) );

update_option( 'wpcap_enabled_connectors', array() );
$GLOBALS['wpcap_test_http_queue'] = array();

<?php
/**
 * Z16-04 and Z16-05: documentation truth for the mobile API and for the events the core emits.
 *
 * 1. Every `/app/…` REST route registered in the code is listed in docs/MOBILE-API.md, and every
 *    route listed there exists.
 * 2. docs/SUITE-EVENTS.md lists exactly the connector events and webhook types of the code, and
 *    every PHP action named there is fired by the core.
 * 3. End to end (purchase, issuance, check-in, refund): no connector payload and no webhook payload
 *    contains an e-mail address, an attendee name, a participant answer, a ticket code or its hash.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Checkin;
use PassCaptain\Integrations\ConnectorEvents;
use PassCaptain\Integrations\ConnectorInterface;
use PassCaptain\Jobs;
use PassCaptain\Modules\Webhooks;
use PassCaptain\Orders;
use PassCaptain\Refunds;
use PassCaptain\Webhooks\Dispatcher;

global $wpdb;
$p = $wpdb->prefix;
$z16d_root = dirname( __DIR__, 3 );

/** All PHP sources below src/ as path => content. */
function z16d_sources( $root ) {
	$out = array();
	$it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		if ( 'php' === $file->getExtension() ) { $out[ $file->getPathname() ] = (string) file_get_contents( $file->getPathname() ); }
	}
	return $out;
}
/** Backticked names in the first column of the Markdown table below the heading that starts with $heading. */
function z16d_first_column( $doc, $heading, $column = 0 ) {
	$start = strpos( $doc, "\n## " . $heading );
	if ( false === $start ) { return array(); }
	$end     = strpos( $doc, "\n## ", $start + 4 );
	$section = false === $end ? substr( $doc, $start ) : substr( $doc, $start, $end - $start );
	$names   = array();
	foreach ( explode( "\n", $section ) as $line ) {
		if ( 0 !== strpos( $line, '| ' ) ) { continue; }
		$cells = array_map( 'trim', explode( '|', trim( $line, '|' ) ) );
		if ( preg_match_all( '/`([a-z0-9_.]+)`/', (string) ( $cells[ $column ] ?? '' ), $m ) ) { $names = array_merge( $names, $m[1] ); }
	}
	$names = array_values( array_unique( $names ) );
	sort( $names );
	return $names;
}

$z16d_src = z16d_sources( $z16d_root );

echo "Z16: docs/MOBILE-API.md lists the routes of the code\n";
$z16d_routes = array();
foreach ( $z16d_src as $code ) {
	if ( preg_match_all( "~register_rest_route\(\s*[A-Za-z:\\\\]+NS,\s*'(/app/[^']+)'~", $code, $m ) ) {
		foreach ( $m[1] as $route ) { $z16d_routes[] = preg_replace( '~\(\?P<[a-z_]+>[^)]*\)~', '{id}', $route ); }
	}
}
$z16d_routes = array_values( array_unique( $z16d_routes ) );
sort( $z16d_routes );
$z16d_mobile = (string) file_get_contents( $z16d_root . '/docs/MOBILE-API.md' );
preg_match_all( '~^\| (?:GET|POST|PUT|PATCH|DELETE) \| `(/app/[^`]+)` \|~m', $z16d_mobile, $m );
$z16d_documented = array_values( array_unique( $m[1] ) );
sort( $z16d_documented );
check( 'Z16: the code registers the app routes (sanity: ' . count( $z16d_routes ) . ' found, at least 15)', count( $z16d_routes ) >= 15 && in_array( '/app/scan/check-in', $z16d_routes, true ) && in_array( '/app/offline/package', $z16d_routes, true ) );
check( 'Z16: every registered /app/ route is in the endpoint table of docs/MOBILE-API.md (missing: ' . implode( ', ', array_diff( $z16d_routes, $z16d_documented ) ) . ')', array() === array_diff( $z16d_routes, $z16d_documented ) );
check( 'Z16: the endpoint table names no route that does not exist (unknown: ' . implode( ', ', array_diff( $z16d_documented, $z16d_routes ) ) . ')', array() === array_diff( $z16d_documented, $z16d_routes ) );
check( 'Z16: the mobile API document no longer announces the shipped offline package as planned', false === strpos( $z16d_mobile, 'Geplant (nicht umgesetzt)' ) );
check( 'Z16: the mobile API document names the api_version of the code (' . \PassCaptain\Mobile\Api::VERSION . ')', false !== strpos( $z16d_mobile, '`' . \PassCaptain\Mobile\Api::VERSION . '`' ) );

echo "Z16: docs/SUITE-EVENTS.md matches the code\n";
$z16d_doc = (string) file_get_contents( $z16d_root . '/docs/SUITE-EVENTS.md' );
preg_match_all( "~self::queue\(\s*'([a-z_]+)'~", (string) file_get_contents( $z16d_root . '/src/Integrations/ConnectorEvents.php' ), $m );
$z16d_connector_code = array_values( array_unique( $m[1] ) );
sort( $z16d_connector_code );
$z16d_connector_doc = z16d_first_column( $z16d_doc, 'Connector-Ereignisse' );
check( 'Z16: the connector events of the document are those of the code (' . implode( ', ', $z16d_connector_code ) . ')', count( $z16d_connector_code ) >= 8 && $z16d_connector_code === $z16d_connector_doc );
preg_match_all( "~Dispatcher::emit\(\s*'([a-z_.]+)'~", (string) file_get_contents( $z16d_root . '/src/Modules/Webhooks.php' ), $m );
$z16d_webhook_code = array_values( array_unique( $m[1] ) );
sort( $z16d_webhook_code );
$z16d_webhook_list = Dispatcher::EVENTS;
sort( $z16d_webhook_list );
$z16d_webhook_doc = z16d_first_column( $z16d_doc, 'Ausgehende Webhooks' );
check( 'Z16: the webhook types of the document are those the code can subscribe to and emits', $z16d_webhook_list === $z16d_webhook_code && $z16d_webhook_list === $z16d_webhook_doc );
$z16d_actions = z16d_first_column( $z16d_doc, 'PHP-Actions', 1 );
$z16d_all     = implode( "\n", $z16d_src );
$z16d_unfired = array();
foreach ( $z16d_actions as $action ) {
	if ( ! preg_match( "~do_action\(\s*'" . preg_quote( $action, '~' ) . "'~", $z16d_all ) ) { $z16d_unfired[] = $action; }
}
check( 'Z16: every PHP action of the document is fired by the core (' . count( $z16d_actions ) . ' actions; not fired: ' . implode( ', ', $z16d_unfired ) . ')', count( $z16d_actions ) >= 15 && array() === $z16d_unfired );
$z16d_bridges = (string) file_get_contents( $z16d_root . '/src/Integrations/ConnectorEvents.php' ) . (string) file_get_contents( $z16d_root . '/src/Modules/Webhooks.php' );
// Merged line (A674): check-out and event cancellation have webhooks (ticket.checked_out, event.cancelled) but no
// connector event; code rotation has neither – as docs/SUITE-EVENTS.md says.
$z16d_connector_src = (string) file_get_contents( $z16d_root . '/src/Integrations/ConnectorEvents.php' );
check( 'Z16: as documented, check-out, event cancellation and code rotation have no connector event and no webhook', false === strpos( $z16d_connector_src, 'wpcap_ticket_checked_out' ) && false === strpos( $z16d_connector_src, 'wpcap_event_cancelled' ) && false === strpos( $z16d_bridges, 'wpcap_ticket_code_rotated' ) && false !== strpos( $z16d_doc, '`wpcap_ticket_code_rotated`' ) );

echo "Z16: no personal data and no codes in connector and webhook messages (purchase to refund)\n";
final class Z16_Probe_Connector implements ConnectorInterface {
	/** @var array<int,array{event:string,json:string}> */
	public array $received = array();
	public function key() { return 'z16probe'; }
	public function version() { return 1; }
	public function capabilities() { return array( 'events' ); }
	public function deliver( $event_name, array $payload, $idempotency_key ) {
		$this->received[] = array( 'event' => (string) $event_name, 'json' => (string) wp_json_encode( $payload ) . '|' . (string) $idempotency_key );
		return true;
	}
}
ConnectorEvents::register();
Webhooks::register();
$z16d_probe     = new Z16_Probe_Connector();
$z16d_filter    = static function ( $list ) use ( $z16d_probe ) { $list[] = $z16d_probe; return $list; };
$z16d_enabled   = get_option( 'wpcap_enabled_connectors', array() );
add_filter( 'wp_pass_captain_connectors_v1', $z16d_filter );
update_option( 'wpcap_enabled_connectors', array_values( array_unique( array_merge( (array) $z16d_enabled, array( 'z16probe' ) ) ) ) );
$z16d_sub = Dispatcher::create_subscription( 'https://z16.hooks.example/in', Dispatcher::EVENTS );

$z16d_type = make_type( 20, 10, 1500 );
// Doors are open: the default entry window only admits close to the start.
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_ticket_types tt ON tt.occurrence_id=o.occurrence_id SET o.starts_at=%s WHERE tt.ticket_type_id=%d", gmdate( 'Y-m-d H:i:s', time() + 1800 ), $z16d_type ) );
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $z16d_type, 'field_key' => 'allergie', 'label' => 'Allergie', 'field_type' => 'text', 'required' => 1, 'purpose' => 'Verpflegung', 'status' => 'active' ) );
wpcap_test_reset_transients();
$z16d_mails = count( $GLOBALS['wpcap_test_mails'] );
$z16d_order = Orders::create( array(
	'email'           => 'z16-kaeuferin@example.com',
	'items'           => array( array( 'ticket_type_id' => $z16d_type, 'quantity' => 1 ) ),
	'attendees'       => array( array( 'ticket_type_id' => $z16d_type, 'name' => 'Zita Zeugin', 'email' => 'z16-gast@example.com', 'answers' => array( 'allergie' => 'Erdnuss-Z16' ) ) ),
	'idempotency_key' => 'z16d-' . uniqid( '', true ),
) );
$z16d_order_id = is_wp_error( $z16d_order ) ? 0 : (int) $z16d_order['order_id'];
if ( $z16d_order_id ) { Orders::confirm_manual_payment( $z16d_order_id ); }
$z16d_code = '';
foreach ( array_slice( $GLOBALS['wpcap_test_mails'], $z16d_mails ) as $mail ) {
	if ( preg_match( '/wpcap_ticket=([a-f0-9]{64})/', (string) $mail['body'], $mm ) ) { $z16d_code = $mm[1]; }
}
$z16d_answer = (string) $wpdb->get_var( $wpdb->prepare( "SELECT a.answer FROM {$p}wpcap_participant_answers a INNER JOIN {$p}wpcap_tickets t ON t.ticket_id=a.ticket_id WHERE t.order_id=%d", $z16d_order_id ) );
check( 'Z16: the scenario order is paid, carries the attendee answer and its ticket code was mailed', $z16d_order_id > 0 && 'paid' === Orders::get( $z16d_order_id )->payment_status && 'Erdnuss-Z16' === $z16d_answer && 64 === strlen( $z16d_code ) );
$z16d_checkin = Checkin::perform( $z16d_code, array( 'idempotency_key' => 'z16d-scan-' . uniqid(), 'source' => 'web' ) );
// The ticket was checked in: staff refund it with a documented exception and a second approval (merged policy, A131).
$z16d_refund  = Refunds::request( $z16d_order_id, array(), 'Z16 Erstattung', 'z16d-refund-' . uniqid(), array( 'used_override' => 'Z16 Kulanz nach Einlass' ) );
if ( is_array( $z16d_refund ) && 'pending_approval' === ( $z16d_refund['status'] ?? '' ) ) {
	$z16d_user = $GLOBALS['wpcap_test_user_id'] ?? 1;
	$GLOBALS['wpcap_test_user_id'] = 2;
	Refunds::approve( (int) $z16d_refund['refund_id'] );
	$GLOBALS['wpcap_test_user_id'] = $z16d_user;
	$z16d_refund = Refunds::get( (int) $z16d_refund['refund_id'] );
}
check( 'Z16: check-in and refund of the scenario succeed', is_array( $z16d_checkin ) && 'checked_in' === $z16d_checkin['status'] && is_array( $z16d_refund ) && 'completed' === $z16d_refund['status'] );
$wpdb->query( "UPDATE {$p}wpcap_jobs SET run_after='2000-01-01 00:00:00' WHERE job_type='connector_delivery' AND status='queued'" );
Jobs::process_due( 200 );

$z16d_connector_events = array_values( array_unique( array_column( $z16d_probe->received, 'event' ) ) );
sort( $z16d_connector_events );
$z16d_deliveries = is_wp_error( $z16d_sub ) ? array() : $wpdb->get_results( $wpdb->prepare( "SELECT event_type,payload FROM {$p}wpcap_webhook_deliveries WHERE subscription_id=%d", (int) $z16d_sub['subscription_id'] ) );
$z16d_webhook_events = array_values( array_unique( array_map( static fn( $d ) => (string) $d->event_type, $z16d_deliveries ) ) );
sort( $z16d_webhook_events );
check( 'Z16: the connector received the events of the whole flow (' . implode( ', ', $z16d_connector_events ) . ')', array() === array_diff( array( 'order_paid', 'tickets_issued', 'ticket_checked_in', 'refund_completed' ), $z16d_connector_events ) );
check( 'Z16: the webhook subscription received the events of the whole flow (' . implode( ', ', $z16d_webhook_events ) . ')', array() === array_diff( array( 'order.paid', 'ticket.issued', 'ticket.checked_in', 'order.refunded' ), $z16d_webhook_events ) );

$z16d_secrets = array(
	'buyer e-mail'       => 'z16-kaeuferin@example.com',
	'attendee e-mail'    => 'z16-gast@example.com',
	'attendee name'      => 'Zita Zeugin',
	'attendee last name' => 'Zeugin',
	'participant answer' => 'Erdnuss-Z16',
	'ticket code'        => $z16d_code,
	'ticket code hash'   => hash( 'sha256', $z16d_code ),
);
$z16d_messages = array_merge( array_column( $z16d_probe->received, 'json' ), array_map( static fn( $d ) => (string) $d->payload, $z16d_deliveries ) );
$z16d_leaks    = array();
foreach ( $z16d_messages as $message ) {
	foreach ( $z16d_secrets as $label => $secret ) {
		if ( '' !== $secret && false !== stripos( $message, $secret ) ) { $z16d_leaks[ $label ] = true; }
	}
	if ( false !== strpos( $message, '@' ) ) { $z16d_leaks['an e-mail address'] = true; }
}
check( 'Z16: ' . count( $z16d_messages ) . ' connector and webhook messages contain no e-mail address, name, answer, ticket code or code hash (found: ' . implode( ', ', array_keys( $z16d_leaks ) ) . ')', count( $z16d_messages ) >= 9 && array() === $z16d_leaks );

remove_filter( 'wp_pass_captain_connectors_v1', $z16d_filter );
update_option( 'wpcap_enabled_connectors', $z16d_enabled );
if ( ! is_wp_error( $z16d_sub ) ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_webhook_deliveries WHERE subscription_id=%d", (int) $z16d_sub['subscription_id'] ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_webhook_subscriptions WHERE subscription_id=%d", (int) $z16d_sub['subscription_id'] ) );
}

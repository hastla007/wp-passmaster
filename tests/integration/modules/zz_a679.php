<?php
/**
 * A679: contract fixtures and end-to-end runs of the workflow contract with a stub workflow plugin –
 * workflow missing / deactivated / of another major version, PII redaction (also of fields a filter
 * injects), duplicates (repeated hooks, at-least-once redelivery), replay and pull, a provider-side
 * refusal (e.g. its own pro entitlement missing or expired), multisite site id, and the commands
 * against tests/contracts/workflow_commands.json. Included from run.php.
 *
 * Not covered here: HPOS vs. classic WooCommerce storage – the test runtime has no WooCommerce order
 * tables; the outbox only sees core orders (WooCommerce orders become core orders, docs/WOOCOMMERCE.md).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Checkin;
use PassCaptain\Orders;
use PassCaptain\Workflow\Commands;
use PassCaptain\Workflow\Contract;
use PassCaptain\Workflow\Outbox;

require_once dirname( __DIR__, 2 ) . '/contracts/shape.php';

global $wpdb;
$x6e_hooks_before = $GLOBALS['wpcap_test_hooks'];
$x6e_filters_before = $GLOBALS['wpcap_test_filters'];
unset( $GLOBALS['wpcap_test_filters'][ Contract::FILTER ] );
PassCaptain\Workflow\Events::register();
$x6e_t = Outbox::table();

/** Stub of a workflow plugin: idempotent by envelope id, records what it got. */
final class X6e_Workflow_Stub {
	public $received = array();
	public $seen = array();
	public $fail_next = 0;
	public $refuse = false;
	public $version = '1.0.0';
	public function contract_version() { return $this->version; }
	public function receive( array $envelope ) {
		$this->received[] = $envelope;
		if ( $this->refuse ) { return new WP_Error( 'wpcap_workflow_rejected', 'pro entitlement missing', array( 'retryable' => false ) ); }
		if ( $this->fail_next > 0 ) { --$this->fail_next; return new WP_Error( 'wpcap_workflow_temporary', 'busy' ); }
		$this->seen[ $envelope['id'] ] = ( $this->seen[ $envelope['id'] ] ?? 0 ) + 1;
		return true;
	}
}
$x6e_due = static function () use ( $wpdb, $x6e_t ) { $wpdb->query( $wpdb->prepare( "UPDATE $x6e_t SET next_attempt_at=%s WHERE status='pending'", gmdate( 'Y-m-d H:i:s', time() - 60 ) ) ); };
$x6e_mine = static function ( array $envs, array $subjects ) { return array_values( array_filter( $envs, static fn( $e ) => in_array( $e['subject']['id'], $subjects, true ) ) ); };

echo "A679: workflow contract end to end with a stub workflow plugin\n";
// 1. Workflow missing: the core flows run, events wait.
$wpdb->query( "UPDATE $x6e_t SET status='delivered' WHERE status IN ('pending','failed')" ); // Earlier modules' events are not part of this run.
unset( $GLOBALS['wpcap_test_filters']['wpcap_workflow_event_data'] ); // A fixed test value would bypass the hook.
add_filter( 'wpcap_workflow_event_data', static function ( $data ) { return $data + array( 'customer_email' => 'leak@example.com', 'attendee_name' => 'Leak', 'token' => str_repeat( 'f', 64 ), 'answers' => array( 'x' => 'y' ), 'note' => 'unknown' ); }, 10, 2 );
$GLOBALS['wpcap_test_multisite'] = true;
$GLOBALS['wpcap_test_blog_id'] = 3;
$x6e_type = make_type( 10, 10, 1800 );
$x6e_o = Orders::create( array( 'email' => 'x6e@example.com', 'items' => array( array( 'ticket_type_id' => $x6e_type, 'quantity' => 2 ) ), 'attendees' => array( array( 'ticket_type_id' => $x6e_type, 'name' => 'Ida Sample', 'email' => 'ida@example.com' ) ), 'idempotency_key' => 'x6e-' . uniqid() ) );
$x6e_paid = Orders::confirm_manual_payment( $x6e_o['order_id'] );
$x6e_codes = ticket_code_for( $x6e_o['order_id'] );
$x6e_tickets = tickets( $x6e_o['order_id'] );
$x6e_check = Checkin::perform( $x6e_codes[0], array( 'idempotency_key' => 'x6e-ci-' . uniqid() ) );
unset( $GLOBALS['wpcap_test_multisite'], $GLOBALS['wpcap_test_blog_id'] );
$x6e_order = Orders::get( $x6e_o['order_id'] );
$x6e_subjects = array_merge( array( (string) $x6e_order->public_id ), array_map( static fn( $t ) => (string) $t->public_id, $x6e_tickets ) );
$x6e_pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $x6e_t WHERE contract='workflow' AND status='pending' AND subject_id IN ('" . implode( "','", $x6e_subjects ) . "')" );
check( 'A679: workflow missing – purchase, payment and admission work, events wait in the outbox', ! is_wp_error( $x6e_paid ) && ! is_wp_error( $x6e_check ) && $x6e_pending >= 5 && 0 === Outbox::deliver() && 'missing' === ( get_option( Contract::status_option() )['state'] ?? '' ) );

// 2. Contract major: a provider of version 2 is never called.
$x6e_stub = new X6e_Workflow_Stub();
$x6e_stub->version = '2.0.0';
$GLOBALS['wpcap_test_filters'][ Contract::FILTER ] = $x6e_stub;
check( 'A679: a workflow plugin of another major version is never called (incompatible)', 0 === Outbox::deliver() && array() === $x6e_stub->received && 'incompatible' === get_option( Contract::status_option() )['state'] );

// 3. Installed later (compatible): everything arrives once, in order, redacted, matching the fixture.
$x6e_stub->version = '1.2.0';
$x6e_stub->fail_next = 1;
Outbox::deliver();
$x6e_due();
Outbox::deliver();
$x6e_got = $x6e_mine( $x6e_stub->received, $x6e_subjects );
$x6e_unique = array_unique( array_column( $x6e_got, 'id' ) );
check( 'A679: a plugin installed later receives every waiting event', count( $x6e_unique ) === $x6e_pending );
check( 'A679: at-least-once – a temporary failure is redelivered (attempt 2) and the plugin keeps each event once', 1 === max( $x6e_stub->seen ) && (bool) array_filter( $x6e_got, static fn( $e ) => 2 === $e['attempt'] ) );
$x6e_seq = array_column( array_values( array_filter( $x6e_got, static fn( $e ) => 1 === $e['attempt'] ) ), 'sequence' );
$x6e_sorted = $x6e_seq;
sort( $x6e_sorted );
check( 'A679: first deliveries arrive in sequence order', $x6e_seq && $x6e_seq === $x6e_sorted );
$x6e_blob = wp_json_encode( $x6e_got );
check( 'A679: PII redaction – nothing a filter injects (address, name, token, answers, unknown field) reaches the plugin', false === strpos( $x6e_blob, '@' ) && false === strpos( $x6e_blob, 'Leak' ) && false === strpos( $x6e_blob, 'Ida' ) && false === strpos( $x6e_blob, '"note"' ) && ! preg_match( '/[a-f0-9]{64}/', $x6e_blob ) && false === strpos( $x6e_blob, $x6e_codes[0] ) );
$x6e_fx = wpcap_contract_fixture( 'workflow' );
$x6e_viol = array();
foreach ( $x6e_got as $x6e_env ) { $x6e_viol = array_merge( $x6e_viol, wpcap_contract_violations( $x6e_fx['endpoints'][ $x6e_env['type'] ]['request'] ?? array( 'missing' => 'string' ), $x6e_env, $x6e_env['type'] ) ); }
check( 'A679: every delivered envelope matches tests/contracts/workflow.json' . ( $x6e_viol ? ' – ' . implode( '; ', array_slice( $x6e_viol, 0, 3 ) ) : '' ), ! $x6e_viol );
check( 'A679: multisite – envelopes name the site they come from', (bool) $x6e_got && ! array_filter( $x6e_got, static fn( $e ) => 3 !== $e['source']['site_id'] ) );

// 4. Duplicates: repeated hooks record nothing new, nothing is delivered twice.
$x6e_before = count( $x6e_stub->received );
do_action( 'wpcap_order_paid', (int) $x6e_o['order_id'] );
do_action( 'wpcap_tickets_issued', (int) $x6e_o['order_id'], 2 );
Outbox::deliver();
check( 'A679: repeated order/ticket hooks (provider retries) are not delivered again', count( $x6e_stub->received ) === $x6e_before );

// 5. Replay and pull.
$x6e_first = (int) min( array_column( $x6e_got, 'sequence' ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$x6e_forbidden = Outbox::replay( $x6e_first );
$GLOBALS['wpcap_test_denied_caps'] = array();
$x6e_n = Outbox::replay( $x6e_first );
Outbox::deliver();
$x6e_replayed = array_slice( $x6e_stub->received, $x6e_before );
check( 'A679: replay needs the capability, queues the events again and marks them replay=true with the same ids', is_wp_error( $x6e_forbidden ) && $x6e_n >= count( $x6e_unique ) && $x6e_replayed && ! array_filter( $x6e_replayed, static fn( $e ) => true !== $e['replay'] ) && ! array_diff( $x6e_unique, array_column( $x6e_replayed, 'id' ) ) );
$x6e_pull = Outbox::since( $x6e_first - 1, 500, array( 'order.paid' ) );
check( 'A679: a plugin can pull events after a sequence (since), filtered by type', (bool) $x6e_pull && ! array_filter( $x6e_pull, static fn( $e ) => 'order.paid' !== $e['type'] ) );

// 6. Provider refuses (e.g. its own pro entitlement is missing or expired): final, core unaffected.
$x6e_stub->refuse = true;
$x6e_o2 = Orders::create( array( 'email' => 'x6e2@example.com', 'items' => array( array( 'ticket_type_id' => $x6e_type, 'quantity' => 1 ) ), 'idempotency_key' => 'x6e2-' . uniqid() ) );
$x6e_paid2 = Orders::confirm_manual_payment( $x6e_o2['order_id'] );
Outbox::deliver();
$x6e_pub2 = (string) Orders::get( $x6e_o2['order_id'] )->public_id;
check( 'A679: a refusal of the plugin (entitlement on its side) is final and does not affect the core', ! is_wp_error( $x6e_paid2 ) && 'paid' === Orders::get( $x6e_o2['order_id'] )->payment_status && 'failed' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $x6e_t WHERE event_type='order.paid' AND subject_id=%s", $x6e_pub2 ) ) );
$x6e_stub->refuse = false;

// 7. Deactivated: no provider, nothing delivered, events wait.
unset( $GLOBALS['wpcap_test_filters'][ Contract::FILTER ] );
$x6e_before = count( $x6e_stub->received );
Orders::resend_tickets( $x6e_o['order_id'], array( 'keep_codes' => true ) );
$x6e_r = Outbox::record( 'occurrence.changed', 'x6e-off|' . uniqid(), '1', array( 'occurrence_id' => 1, 'changed_fields' => array( 'location' ) ) );
check( 'A679: workflow deactivated – nothing is delivered, the new event waits', 0 === Outbox::deliver() && count( $x6e_stub->received ) === $x6e_before && 'pending' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $x6e_t WHERE event_id=%s", (string) $x6e_r ) ) );

// 8. Commands against their fixture.
$x6e_cfx = wpcap_contract_fixture( 'workflow_commands' );
check( 'A679: tests/contracts/workflow_commands.json covers every command with its allowed arguments', array_keys( Commands::COMMANDS ) === array_keys( (array) ( $x6e_cfx['endpoints'] ?? array() ) ) && ! array_filter( Commands::COMMANDS, static fn( $def, $c ) => array_keys( $def['args'] ) !== array_map( static fn( $k ) => ltrim( $k, '?' ), array_keys( $x6e_cfx['endpoints'][ $c ]['request'] ?? array() ) ), ARRAY_FILTER_USE_BOTH ) );
$x6e_cmd = Commands::execute( 'mail.resend', array( 'order_id' => (string) $x6e_order->public_id ), 'x6e-cmd-' . substr( md5( uniqid() ), 0, 12 ) );
check( 'A679: a command answer matches its fixture shape', is_array( $x6e_cmd ) && array() === wpcap_contract_violations( $x6e_cfx['endpoints']['mail.resend']['response'], $x6e_cmd ) );

$wpdb->query( "UPDATE $x6e_t SET status='delivered' WHERE status IN ('pending','failed')" );
$GLOBALS['wpcap_test_hooks'] = $x6e_hooks_before;
$GLOBALS['wpcap_test_filters'] = $x6e_filters_before;

<?php
// phpcs:ignoreFile
/**
 * Splitting the Orders class (TODO "Gott-Klasse Orders"): availability, attendee handling and the core capacity
 * check live in @internal classes under src/Orders/; Orders keeps thin delegators with the former signatures.
 * Behaviour is covered by the existing order/checkout modules (orders, finance, p3_performance, …); this module
 * only pins the facade shape and the delegation.
 */

use PassCaptain\Orders;

echo "Orders split: facade delegators and @internal parts\n";

$os_sig = static function ( $class, $method ) {
	$r = new ReflectionMethod( $class, $method );
	return array(
		$r->isPublic() ? 'public' : ( $r->isPrivate() ? 'private' : 'protected' ),
		$r->isStatic(),
		array_map( static fn( $p ) => $p->getName() . ( $p->isDefaultValueAvailable() ? '=' . var_export( $p->getDefaultValue(), true ) : '' ), $r->getParameters() ),
	);
};
$os_expected = array(
	'availability'             => array( 'public', true, array( 'ticket_type_id' ) ),
	'availability_many'        => array( 'public', true, array( 'ticket_type_ids', "rows=array (\n)" ) ),
	'flush_availability_cache' => array( 'public', true, array() ),
	'site_switched'            => array( 'public', true, array() ),
	'note_query'               => array( 'public', true, array( 'query' ) ),
	'validate_attendees'       => array( 'public', true, array( 'lines', 'attendees', "options=array (\n)", 'skip_required=false' ) ),
	'attendee_name_mode'       => array( 'public', true, array( 'value' ) ),
	'field_options'            => array( 'public', true, array( 'field' ) ),
	'clean_answer'             => array( 'public', true, array( 'field', 'value' ) ),
	'capacity_error'           => array( 'private', true, array( 'type', 'occurrence', 'quantity', 'exclude_order_id=0', 'occurrence_quantity=NULL' ) ),
);
// store_attendee() was a private delegator for the issuance only; it moved with it (p15: Orders\Issuance calls Orders\Attendees::store()).
check( 'Orders split: the private store_attendee() delegator is gone with the issuance (no unused private method on Orders)', ! method_exists( Orders::class, 'store_attendee' ) );
$os_bad = array();
foreach ( $os_expected as $os_m => $os_want ) {
	if ( ! method_exists( Orders::class, $os_m ) || $os_sig( Orders::class, $os_m ) !== $os_want ) { $os_bad[] = $os_m; continue; }
	$os_r = new ReflectionMethod( Orders::class, $os_m );
	if ( $os_r->getEndLine() - $os_r->getStartLine() > 3 ) { $os_bad[] = $os_m . ' (not a thin delegator)'; }
}
check( 'Orders split: the facade keeps every moved method with the former visibility and signature, as a thin delegator' . ( $os_bad ? ': ' . implode( ', ', $os_bad ) : '' ), ! $os_bad );

$os_parts = array( Orders\Availability::class, Orders\Attendees::class, Orders\Capacity::class );
$os_bad_parts = array_values( array_filter( $os_parts, static function ( $c ) { $r = new ReflectionClass( $c ); return ! $r->isFinal() || false === strpos( (string) $r->getDocComment(), '@internal' ); } ) );
check( 'Orders split: Availability, Attendees and Capacity are final and @internal', ! $os_bad_parts );

// The query filter keeps its callback identity (listeners and other tests remove/restore Orders::note_query).
Orders::availability( 0 );
$os_query_cbs = array();
foreach ( $GLOBALS['wpcap_test_hooks']['query'] ?? array() as $os_entries ) { foreach ( $os_entries as $os_entry ) { $os_query_cbs[] = $os_entry[0]; } }
check( 'Orders split: the availability memo hooks the query filter with Orders::note_query (unchanged callback)', in_array( array( Orders::class, 'note_query' ), $os_query_cbs, true ) && ! in_array( array( Orders\Availability::class, 'note_query' ), $os_query_cbs, true ) );

// Delegation returns the same values as before (pure helpers, no DB).
check( 'Orders split: attendee_name_mode() and clean_answer() answer as before', 'optional' === Orders::attendee_name_mode( 'x' ) && 'off' === Orders::attendee_name_mode( 'off' ) && null === Orders::clean_answer( (object) array( 'field_type' => 'radio', 'options' => "A\nB" ), 'C' ) && 'B' === Orders::clean_answer( (object) array( 'field_type' => 'radio', 'options' => "A\nB" ), 'B' ) );
check( 'Orders split: field_options() reads both stored formats', array( 'A', 'B' ) === Orders::field_options( (object) array( 'options' => '["A","B","A"]' ) ) && array( 'A', 'B' ) === Orders::field_options( array( 'options' => "A\r\n\nB" ) ) );

// Second step (p13): transactions, payment confirmation and cancellation.
echo "Orders split: Transaction, Payment and Cancellation\n";
$os2_expected = array(
	'begin'                       => array( 'private', true, array() ),
	'in_transaction'              => array( 'private', true, array() ),
	'capacity_released'           => array( 'private', true, array( 'order_id', 'reason', "details=array (\n)" ) ),
	'transaction_alive'           => array( 'public', true, array() ),
	'reset_transaction_tracking'  => array( 'public', true, array() ),
	'reserve_capacity'            => array( 'private', true, array( 'demand', 'context' ) ),
	'confirm_external_payment'    => array( 'public', true, array( 'order_id', "reference=''" ) ),
	'confirm_manual_payment'      => array( 'public', true, array( 'order_id' ) ),
	'late_payment_reason'         => array( 'public', true, array( 'code' ) ),
	'schedule_late_refund'        => array( 'public', true, array( 'order', 'why' ) ),
	'cancel_order'                => array( 'public', true, array( 'order_id', "reason=''", "only_payment_status=array (\n)" ) ),
	'mark_refunded'               => array( 'public', true, array( 'order_id', "reason=''" ) ),
	'cancel_event'                => array( 'public', true, array( 'event_id', "reason=''" ) ),
	'resume_event_cancellation'   => array( 'public', true, array( 'event_id' ) ),
);
$os2_bad = array();
foreach ( $os2_expected as $os_m => $os_want ) {
	if ( ! method_exists( Orders::class, $os_m ) || $os_sig( Orders::class, $os_m ) !== $os_want ) { $os2_bad[] = $os_m; continue; }
	$os_r = new ReflectionMethod( Orders::class, $os_m );
	if ( $os_r->getEndLine() - $os_r->getStartLine() > 3 ) { $os2_bad[] = $os_m . ' (not a thin delegator)'; }
}
check( 'Orders split (2): payment, cancellation and transaction methods stay on the facade with visibility and signature, as thin delegators' . ( $os2_bad ? ': ' . implode( ', ', $os2_bad ) : '' ), ! $os2_bad );

$os2_parts = array(
	Orders\Transaction::class  => array( 'begin', 'capacity_released', 'in_transaction', 'reset_transaction_tracking', 'transaction_alive' ),
	Orders\Payment::class      => array( 'confirm_external_payment', 'confirm_manual_payment', 'late_payment_reason', 'schedule_late_refund' ),
	Orders\Cancellation::class => array( 'cancel_event', 'cancel_order', 'mark_refunded', 'resume_event_cancellation' ),
);
$os2_bad_parts = array();
foreach ( $os2_parts as $os_c => $os_public ) {
	$os_rc = new ReflectionClass( $os_c );
	$os_have = array_map( static fn( $m ) => $m->getName(), $os_rc->getMethods( ReflectionMethod::IS_PUBLIC ) );
	sort( $os_have );
	if ( ! $os_rc->isFinal() || false === strpos( (string) $os_rc->getDocComment(), '@internal' ) || $os_have !== $os_public ) { $os2_bad_parts[] = $os_c . ' (' . implode( ',', $os_have ) . ')'; }
}
check( 'Orders split (2): Transaction, Payment and Cancellation are final, @internal and expose only the delegated methods' . ( $os2_bad_parts ? ': ' . implode( '; ', $os2_bad_parts ) : '' ), ! $os2_bad_parts );
$os2_cap = new ReflectionMethod( Orders\Capacity::class, 'reserve' );
check( 'Orders split (2): the wpcap_reserve_capacity call lives in Orders\\Capacity::reserve() (public static, used by checkout, exchange and late payments)', $os2_cap->isPublic() && $os2_cap->isStatic() );

// One transaction state: the tracking properties moved to Orders\Transaction, and the facade reset clears them.
$os2_orders = new ReflectionClass( Orders::class );
check( 'Orders split (2): Orders keeps no transaction tracking state of its own', ! $os2_orders->hasProperty( 'tracks_transactions' ) && ! $os2_orders->hasProperty( 'marker_ready' ) && ! $os2_orders->hasProperty( 'marker_active' ) );
$os2_prop = new ReflectionProperty( Orders\Transaction::class, 'tracks_transactions' );
$os2_prop->setAccessible( true );
Orders::transaction_alive(); // Detects the server once.
$os2_detected = null !== $os2_prop->getValue();
Orders::reset_transaction_tracking();
check( 'Orders split (2): Orders::reset_transaction_tracking() resets the detection in Orders\\Transaction', $os2_detected && null === $os2_prop->getValue() );

// The private facade transaction helpers reach the same state as the part.
$os2_begin = new ReflectionMethod( Orders::class, 'begin' );
$os2_begin->setAccessible( true );
$os2_in = new ReflectionMethod( Orders::class, 'in_transaction' );
$os2_in->setAccessible( true );
$os2_started = $os2_begin->invoke( null );
$os2_inside = $os2_in->invoke( null );
$os2_inside_part = Orders\Transaction::in_transaction();
$wpdb->query( 'ROLLBACK' );
$os2_after = Orders\Transaction::in_transaction();
check( 'Orders split (2): Orders::begin()/in_transaction() and Orders\\Transaction share one transaction state', false !== $os2_started && true === $os2_inside && true === $os2_inside_part && false === $os2_after && false === Orders::transaction_alive() );
$os2_seen = array();
$os2_hook = static function ( $order_id, $reason ) use ( &$os2_seen ) { $os2_seen[] = array( $order_id, $reason ); };
add_action( 'wpcap_capacity_released', $os2_hook, 10, 2 );
$os2_rel = new ReflectionMethod( Orders::class, 'capacity_released' );
$os2_rel->setAccessible( true );
$os2_rel->invoke( null, 0, 'p13_split_probe' );
remove_action( 'wpcap_capacity_released', $os2_hook, 10 );
check( 'Orders split (2): Orders::capacity_released() still fires wpcap_capacity_released (through Orders\\Transaction)', array( array( 0, 'p13_split_probe' ) ) === $os2_seen );

// Delegation answers as before (no order/event with id 0).
check( 'Orders split (2): late_payment_reason() gives the same texts through the facade and the part', Orders::late_payment_reason( 'buyer_limit' ) === Orders\Payment::late_payment_reason( 'allocation_buyer_limit' ) && Orders::late_payment_reason( 'nope' ) === Orders\Payment::late_payment_reason( '' ) && Orders::late_payment_reason( 'occurrence_ended' ) !== Orders::late_payment_reason( 'nope' ) );
$os2_missing = Orders::confirm_external_payment( 0, 'p13' );
$os2_manual = Orders::confirm_manual_payment( 0 );
check( 'Orders split (2): payment confirmation of a missing order fails as before (wpcap_order_missing / wpcap_order_state)', is_wp_error( $os2_missing ) && 'wpcap_order_missing' === $os2_missing->get_error_code() && is_wp_error( $os2_manual ) && 'wpcap_order_state' === $os2_manual->get_error_code() );
check( 'Orders split (2): schedule_late_refund() queues nothing for an order without amount', false === Orders::schedule_late_refund( (object) array( 'order_id' => 0, 'total_minor' => 0, 'payment_provider' => 'stripe' ), 'p13' ) );
$os2_event = Orders::cancel_event( 0 );
$os2_resume = Orders::resume_event_cancellation( 0 );
check( 'Orders split (2): cancellation of a missing order/event answers as before', true === Orders::cancel_order( 0, 'p13' ) && true === Orders::mark_refunded( 0, 'p13' ) && is_wp_error( $os2_event ) && 'wpcap_event_not_cancellable' === $os2_event->get_error_code() && is_wp_error( $os2_resume ) && 'wpcap_event_not_cancelled' === $os2_resume->get_error_code() );

// The moved bodies are gone from Orders.php.
$os2_src = (string) file_get_contents( WPCAP_PATH . 'src/Orders.php' );
check( 'Orders split (2): no transaction marker, savepoint or event cancellation run left in Orders.php', false === strpos( $os2_src, 'wpcap_trx_marker' ) && false === strpos( $os2_src, 'SAVEPOINT' ) && false === strpos( $os2_src, 'START TRANSACTION' ) && false === strpos( $os2_src, 'BulkRuns::' ) && false === strpos( $os2_src, "apply_filters( 'wpcap_reserve_capacity'" ) );

// Third step (p15): revocation/refunds, hold expiry, issuance/delivery and resend.
echo "Orders split: Refunding, Expiry, Issuance and Resend\n";
$os3_expected = array(
	'revoke_tickets'      => array( 'public', true, array( 'order_id', 'ticket_type_id', 'count', "reason=''" ) ),
	'settle_unallocated'  => array( 'public', true, array( 'order_id', 'mode', "note=''" ) ),
	'refundable_tickets'  => array( 'public', true, array( 'order_id', 'failed=NULL' ) ),
	'expire_holds'        => array( 'public', true, array( 'limit=200', "only=array (\n)" ) ),
	'expire_holds_worker' => array( 'public', true, array() ),
	'issue_and_email'     => array( 'public', true, array( 'order_id' ) ),
	'defer_ticket_mail'   => array( 'public', true, array( 'callback' ) ),
	'delivery_marker'     => array( 'public', true, array( 'order_id', 'ticket_ids' ) ),
	'reconcile_issuance'  => array( 'public', true, array( 'limit=20' ) ),
	'handle_job'          => array( 'public', true, array( 'handled', 'type', "payload=array (\n)", 'job_id=0' ) ),
	'sweep_unissued'      => array( 'public', true, array( 'limit=50' ) ),
	'resend_tickets'      => array( 'public', true, array( 'order_id', "options=array (\n)" ) ),
	'mail_retry_payload'  => array( 'public', true, array( 'order_id', 'skip_transferred=false', 'only_unassigned=false' ) ),
	'resend_counts'       => array( 'public', true, array( 'order_id' ) ),
	'reissue_ticket'      => array( 'public', true, array( 'ticket_id', "context=array (\n)" ) ),
);
$os3_bad = array();
foreach ( $os3_expected as $os_m => $os_want ) {
	if ( ! method_exists( Orders::class, $os_m ) || $os_sig( Orders::class, $os_m ) !== $os_want ) { $os3_bad[] = $os_m; continue; }
	$os_r = new ReflectionMethod( Orders::class, $os_m );
	if ( $os_r->getEndLine() - $os_r->getStartLine() > 3 ) { $os3_bad[] = $os_m . ' (not a thin delegator)'; }
}
$os3_ref = ( new ReflectionMethod( Orders::class, 'refundable_tickets' ) )->getParameters()[1];
$os3_cb = ( new ReflectionMethod( Orders::class, 'defer_ticket_mail' ) )->getParameters()[0];
check( 'Orders split (3): revocation, refund, expiry, issuance and resend methods stay on the facade with visibility and signature, as thin delegators' . ( $os3_bad ? ': ' . implode( ', ', $os3_bad ) : '' ), ! $os3_bad && $os3_ref->isPassedByReference() && 'callable' === (string) $os3_cb->getType() );
// Private helpers moved with their code (no private delegators without callers left behind).
$os3_gone = array_values( array_filter( array( 'run_late_refund', 'expire_order', 'issue_and_email_attempt', 'defers_delivery', 'send_ticket_mail', 'with_note', 'issue_failed', 'issue_aborted', 'finish_delivery', 'retry_delivery', 'holder_changed_ids', 'job_error', 'tickets_resent_since_job', 'queue_sweep_job', 'rotate_and_send', 'code_recipient', 'rotate_and_send_group', 'send_code_mail' ), static fn( $m ) => method_exists( Orders::class, $m ) ) );
check( 'Orders split (3): the private issuance, delivery, resend and expiry helpers left Orders' . ( $os3_gone ? ': ' . implode( ', ', $os3_gone ) : '' ), ! $os3_gone );

$os3_parts = array(
	Orders\Refunding::class => array( 'refundable_tickets', 'revoke_tickets', 'run_late_refund', 'settle_unallocated' ),
	Orders\Expiry::class    => array( 'expire_holds', 'expire_holds_worker' ),
	Orders\Issuance::class  => array( 'defer_ticket_mail', 'delivery_marker', 'handle_job', 'issue_and_email', 'job_error', 'reconcile_issuance', 'send_ticket_mail', 'sweep_unissued', 'with_note' ),
	Orders\Resend::class    => array( 'mail_retry_payload', 'reissue_ticket', 'resend_counts', 'resend_tickets', 'rotate_and_send' ),
);
$os3_bad_parts = array();
foreach ( $os3_parts as $os_c => $os_public ) {
	$os_rc = new ReflectionClass( $os_c );
	$os_have = array_map( static fn( $m ) => $m->getName(), $os_rc->getMethods( ReflectionMethod::IS_PUBLIC ) );
	sort( $os_have );
	if ( ! $os_rc->isFinal() || false === strpos( (string) $os_rc->getDocComment(), '@internal' ) || $os_have !== $os_public ) { $os3_bad_parts[] = $os_c . ' (' . implode( ',', $os_have ) . ')'; }
}
check( 'Orders split (3): Refunding, Expiry, Issuance and Resend are final, @internal and expose only the delegated methods (plus the documented sibling helpers)' . ( $os3_bad_parts ? ': ' . implode( '; ', $os3_bad_parts ) : '' ), ! $os3_bad_parts );

// The state moved with the code: Orders keeps only the handler registration flag.
$os3_props = array_map( static fn( $p ) => $p->getName(), ( new ReflectionClass( Orders::class ) )->getProperties( ReflectionProperty::IS_STATIC ) );
check( 'Orders split (3): the expiry, issuance and resend state lives in the parts (Orders keeps no issuing_from_job, defer_mail, last_kept_codes, last_expire_batch_full)', ! array_intersect( array( 'issuing_from_job', 'defer_mail', 'last_kept_codes', 'last_expire_batch_full' ), $os3_props ) && property_exists( Orders\Issuance::class, 'defer_mail' ) && property_exists( Orders\Issuance::class, 'issuing_from_job' ) && property_exists( Orders\Resend::class, 'last_kept_codes' ) && property_exists( Orders\Expiry::class, 'last_expire_batch_full' ) );
// The job types stay constants of the facade (add-ons and the job queue use them).
check( 'Orders split (3): job types and delays stay Orders constants', 'ticket_delivery' === Orders::DELIVERY_JOB && 'ticket_issue' === Orders::ISSUE_JOB && 10 === Orders::DELIVERY_GRACE_MINUTES && 10 === Orders::INLINE_DELIVERY_MAX && 600 === Orders::UNISSUED_GRACE && 2 * DAY_IN_SECONDS === Orders::UNISSUED_LOOKBACK );

// The job handler keeps its callback identity (other code removes/restores Orders::handle_job).
Orders::register();
$os3_job_cbs = array();
foreach ( $GLOBALS['wpcap_test_hooks']['wpcap_process_job'] ?? array() as $os_entries ) { foreach ( $os_entries as $os_entry ) { $os3_job_cbs[] = $os_entry[0]; } }
check( 'Orders split (3): wpcap_process_job is hooked with Orders::handle_job (unchanged callback), not the part', in_array( array( Orders::class, 'handle_job' ), $os3_job_cbs, true ) && ! in_array( array( Orders\Issuance::class, 'handle_job' ), $os3_job_cbs, true ) );
check( 'Orders split (3): handle_job() passes unknown job types through unchanged', 'p15-untouched' === Orders::handle_job( 'p15-untouched', 'p15_unknown_job' ) && false === Orders::handle_job( false, 'p15_unknown_job', array( 'order_id' => 0 ) ) );

// Delegation answers as before (pure helpers; no order with id 0).
check( 'Orders split (3): delivery_marker() and mail_retry_payload() answer as before (facade = part)', Orders::delivery_marker( 7, array( 3, 1, 3 ) ) === Orders\Issuance::delivery_marker( 7, array( 1, 3 ) ) && 0 === strpos( Orders::delivery_marker( 7, array( 1 ) ), 'wpcap_delivered_7_' ) && array( 'order_id' => 5, 'skip_transferred' => true ) === Orders::mail_retry_payload( '5', true ) && array( 'order_id' => 5, 'only_unassigned' => true ) === Orders::mail_retry_payload( 5, false, true ) && array( 'order_id' => 5 ) === Orders::mail_retry_payload( 5 ) );
$os3_depth = new ReflectionProperty( Orders\Issuance::class, 'defer_mail' );
$os3_depth->setAccessible( true );
$os3_inside = null;
$os3_result = Orders::defer_ticket_mail( static function () use ( $os3_depth, &$os3_inside ) { $os3_inside = $os3_depth->getValue(); return 'p15-result'; } );
check( 'Orders split (3): defer_ticket_mail() returns the callback result and defers only while it runs', 'p15-result' === $os3_result && 1 === $os3_inside && 0 === $os3_depth->getValue() );
$os3_failed = null;
$os3_rows = Orders::refundable_tickets( 0, $os3_failed );
check( 'Orders split (3): refundable_tickets() passes $failed by reference through the facade', array() === $os3_rows && false === $os3_failed );
$os3_settle = Orders::settle_unallocated( 0, 'provider' );
$os3_resend = Orders::resend_tickets( 0 );
$os3_reissue = Orders::reissue_ticket( 0, array( 'notify' => false ) );
check( 'Orders split (3): revocation, settlement, resend and reissue of a missing order/ticket answer as before', 0 === Orders::revoke_tickets( 0, 0, 0 ) && 0 === Orders::revoke_tickets( 0, 0, 1 ) && is_wp_error( $os3_settle ) && 'wpcap_order_state' === $os3_settle->get_error_code() && is_wp_error( $os3_resend ) && 'wpcap_order_unpaid' === $os3_resend->get_error_code() && is_wp_error( $os3_reissue ) && 'wpcap_ticket_missing' === $os3_reissue->get_error_code() && array( 'valid' => 0, 'transferred' => 0 ) === Orders::resend_counts( 0 ) && true === Orders::issue_and_email( 0 ) );
$os3_late = Orders::handle_job( false, 'late_payment_refund', array( 'order_id' => 0 ) );
check( 'Orders split (3): the late refund job of a missing order is done (Orders\\Refunding::run_late_refund through the handler)', true === $os3_late );

// The moved bodies are gone from Orders.php.
$os3_src = (string) file_get_contents( WPCAP_PATH . 'src/Orders.php' );
check( 'Orders split (3): no issuance, delivery, resend, expiry or late refund code left in Orders.php', false === strpos( $os3_src, 'wpcap-late-refund-' ) && false === strpos( $os3_src, "'wpcap_ticket_code_rotated', $ticket_id, $token, 'reissue'" ) && false === strpos( $os3_src, 'ticket-issue-sweep|' ) && false === strpos( $os3_src, "'wpcap_expire_holds_batch'" ) && false === strpos( $os3_src, "'wpcap_issue_order_lines'" ) && false === strpos( $os3_src, "'wpcap_refundable_tickets'" ) && false === strpos( $os3_src, "CodeHistory::replacing( $ticket_id, 'reissue' )" ) && false === strpos( $os3_src, "'wpcap_ticket_resend_attachments'" ) );

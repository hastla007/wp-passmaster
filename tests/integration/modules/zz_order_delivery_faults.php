<?php
/**
 * Audit findings (main @ 14522c7): payment webhooks, cancellation/refund, ticket issuance, ticket
 * delivery and bulk resend under database and mail failures (fault injection).
 *
 * Included from run.php (module glob); uses check() and make_type().
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Payments\Webhooks;

/**
 * $wpdb stand-in that forwards to the real test connection but fails selected statements.
 * Rules: pattern (regex on the SQL), skip (matches let through first), times, silent (0 rows instead of false).
 */
final class Orders_Fault_WPDB {
	public array $rules = array();
	public function __construct( public object $inner ) {}
	public function fail( $pattern, $skip = 0, $times = 1, $silent = false ) { $this->rules[] = compact( 'pattern', 'skip', 'times', 'silent' ); return $this; }
	public function __get( $name ) { return $this->inner->$name ?? null; }
	public function __set( $name, $value ) { $this->inner->$name = $value; }
	public function __isset( $name ) { return isset( $this->inner->$name ); }
	public function __call( $name, $args ) { return $this->inner->$name( ...$args ); }
	private function intercept( $sql ) {
		foreach ( $this->rules as $i => $rule ) {
			if ( $rule['times'] <= 0 || ! preg_match( $rule['pattern'], (string) $sql ) ) { continue; }
			if ( $rule['skip'] > 0 ) { $this->rules[ $i ]['skip']--; return null; }
			$this->rules[ $i ]['times']--;
			if ( $rule['silent'] ) { $this->inner->last_error = ''; return 0; }
			$this->inner->last_error = 'Injected fault';
			return false;
		}
		return null;
	}
	public function prepare( $query, ...$args ) { return $this->inner->prepare( $query, ...$args ); }
	public function query( $sql ) { $r = $this->intercept( $sql ); return null === $r ? $this->inner->query( $sql ) : $r; }
	public function get_results( $sql, $output = OBJECT ) { return null === $this->intercept( $sql ) ? $this->inner->get_results( $sql, $output ) : array(); }
	public function get_row( $sql, $output = OBJECT ) { return null === $this->intercept( $sql ) ? $this->inner->get_row( $sql, $output ) : null; }
	public function get_col( $sql ) { return null === $this->intercept( $sql ) ? $this->inner->get_col( $sql ) : array(); }
	public function get_var( $sql ) { return null === $this->intercept( $sql ) ? $this->inner->get_var( $sql ) : null; }
	private static function pairs( array $data, $glue ) { $o = array(); foreach ( $data as $k => $v ) { $o[] = null === $v ? "$k=NULL" : "$k='$v'"; } return implode( $glue, $o ); }
	public function insert( $table, $data, $format = null ) {
		$r = $this->intercept( "INSERT INTO $table (" . implode( ',', array_keys( $data ) ) . ')' );
		return null === $r ? $this->inner->insert( $table, $data, $format ) : $r;
	}
	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$r = $this->intercept( "UPDATE $table SET " . self::pairs( $data, ',' ) . ' WHERE ' . self::pairs( $where, ' AND ' ) );
		return null === $r ? $this->inner->update( $table, $data, $where, $format, $where_format ) : $r;
	}
	public function delete( $table, $where, $format = null ) {
		$r = $this->intercept( "DELETE FROM $table WHERE " . self::pairs( $where, ' AND ' ) );
		return null === $r ? $this->inner->delete( $table, $where, $format ) : $r;
	}
}

global $wpdb;
$odf_real = $wpdb;
$p = $wpdb->prefix;
$odf_faults = static function () use ( $odf_real ) { $GLOBALS['wpdb'] = new Orders_Fault_WPDB( $odf_real ); return $GLOBALS['wpdb']; };
$odf_restore = static function () use ( $odf_real ) { $GLOBALS['wpdb'] = $odf_real; $odf_real->query( 'ROLLBACK' ); };

/** Pending native order (manual provider) for $qty tickets; returns the create() result. */
function odf_order( $type, $qty = 1, array $extra = array() ) {
	static $n = 0;
	$n++;
	return Orders::create( array_merge( array( 'email' => "odf$n@example.com", 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => 'odf-' . $n . '-' . uniqid() ), $extra ), array( 'skip_rate_limit' => true ) );
}
function odf_event( $order, $type, $id ) {
	return array( 'event_id' => $id, 'type' => $type, 'order_public_id' => $order['public_id'], 'amount_minor' => (int) $order['total_minor'], 'currency' => 'EUR' );
}
function odf_claim( $id ) {
	global $wpdb;
	return $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}wpcap_payment_events WHERE provider='manual' AND provider_event_id=%s", $id ) );
}
function odf_hashes( $order_id ) {
	global $wpdb;
	return $wpdb->get_col( $wpdb->prepare( "SELECT token_hash FROM {$wpdb->prefix}wpcap_tickets WHERE order_id=%d ORDER BY ticket_id", $order_id ) );
}
function odf_mails_to( $email ) { return count( array_filter( $GLOBALS['wpcap_test_mails'], static fn( $m ) => $m['to'] === $email ) ); }
function odf_delivery_job( $order_id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_jobs WHERE job_type='ticket_delivery' AND payload LIKE %s ORDER BY job_id DESC LIMIT 1", '{"order_id":' . (int) $order_id . ',%' ) );
}
function odf_audit( $action, $order_id ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_audit_logs WHERE action=%s AND object_type='order' AND object_id=%d", $action, $order_id ) );
}

echo "Audit: webhook claim distinguishes duplicate from database error\n";
$type = make_type( 50, 10, 1000 );
$o = odf_order( $type, 1 );
$faults = $odf_faults();
$faults->fail( '/^INSERT IGNORE INTO \S+wpcap_payment_events/' );
$r = Webhooks::process( 'manual', odf_event( $o, 'paid', 'odf-claim-1' ) );
$odf_restore();
check( 'claim INSERT error is a retryable WP_Error, not "duplicate"', is_wp_error( $r ) && 'wpcap_webhook_db_error' === $r->get_error_code() && ! empty( $r->get_error_data()['retryable'] ) );
check( 'claim error leaves no event row and the order unpaid', null === odf_claim( 'odf-claim-1' ) && 'pending' === Orders::get( $o['order_id'] )->payment_status );
$faults = $odf_faults();
$faults->fail( '/^DELETE FROM \S+wpcap_payment_events/' );
$r = Webhooks::process( 'manual', odf_event( $o, 'paid', 'odf-claim-1' ) );
$odf_restore();
check( 'stale-claim takeover DELETE error is retryable', is_wp_error( $r ) && null === odf_claim( 'odf-claim-1' ) );
$r = Webhooks::process( 'manual', odf_event( $o, 'paid', 'odf-claim-1' ) );
check( 'provider retry after the error is processed', 'paid' === $r && 'processed' === odf_claim( 'odf-claim-1' ) && 1 === count( Orders::tickets_for_order( $o['order_id'] ) ) );
check( 'real redelivery is still acknowledged as duplicate', 'duplicate' === Webhooks::process( 'manual', odf_event( $o, 'paid', 'odf-claim-1' ) ) );
if ( class_exists( 'WP_REST_Request' ) && class_exists( 'PassCaptain\\Payments\\Registry' ) ) {
	// handle() maps every WP_Error of process() to HTTP 500 (provider retries).
	check( 'handle() maps process errors to status 500', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Payments/Webhooks.php' ), "array( 'status' => 500 )" ) );
}

echo "Audit: cancel_order() rolls back on database errors; webhook retried\n";
$cancel_steps = array(
	'transaction start' => array( '/^START TRANSACTION/', 0, false ),
	'order lock' => array( '/FROM \S+wpcap_orders WHERE order_id=\d+ FOR UPDATE/', 0, false ),
	'reservation release' => array( '/^UPDATE \S+wpcap_reservations SET status=.released./', 0, false ),
	'order status update' => array( "/^UPDATE \S+wpcap_orders SET status='cancelled'/", 0, false ),
	'order status update (0 rows)' => array( "/^UPDATE \S+wpcap_orders SET status='cancelled'/", 0, true ),
	'commit' => array( '/^COMMIT/', 0, false ),
);
foreach ( $cancel_steps as $label => list( $pattern, $skip, $silent ) ) {
	$o = odf_order( $type, 2 );
	$event_id = 'odf-fail-' . md5( $label );
	$faults = $odf_faults();
	$faults->fail( $pattern, $skip, 1, $silent );
	$r = Webhooks::process( 'manual', odf_event( $o, 'failed', $event_id ) );
	$odf_restore();
	$order = Orders::get( $o['order_id'] );
	$active = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_reservations WHERE order_id=" . (int) $o['order_id'] . " AND status='active'" );
	check( "cancel fault ($label): webhook returns a retryable error", is_wp_error( $r ) );
	check( "cancel fault ($label): order and holds unchanged, claim released", 'pending' === $order->payment_status && 'pending_payment' === $order->status && 1 === $active && null === odf_claim( $event_id ) );
	$r = Webhooks::process( 'manual', odf_event( $o, 'failed', $event_id ) );
	$order = Orders::get( $o['order_id'] );
	check( "cancel fault ($label): retry cancels and marks processed", 'cancelled' === $r && 'cancelled' === $order->status && 'processed' === odf_claim( $event_id ) && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_reservations WHERE order_id=" . (int) $o['order_id'] . " AND status='active'" ) );
}
// Revocation inside cancel_order(): paid order with tickets.
$o = odf_order( $type, 2 );
Orders::confirm_external_payment( $o['order_id'], 'odf' );
$faults = $odf_faults();
$faults->fail( "/^UPDATE \S+wpcap_tickets SET status='revoked'/", 1 );
$r = Orders::cancel_order( $o['order_id'], 'odf' );
$odf_restore();
check( 'cancel: failed ticket revocation rolls back every revocation', is_wp_error( $r ) && 2 === count( tickets( $o['order_id'], 'valid' ) ) && 'paid' === Orders::get( $o['order_id'] )->payment_status );
check( 'cancel: no cancellation side effects after rollback', 0 === odf_audit( 'order_cancelled', $o['order_id'] ) );
check( 'cancel: retry revokes and cancels', true === Orders::cancel_order( $o['order_id'], 'odf' ) && 2 === count( tickets( $o['order_id'], 'revoked' ) ) && 'cancelled' === Orders::get( $o['order_id'] )->status );

echo "Audit: mark_refunded() rolls back on database errors; refund webhook idempotent\n";
$refund_steps = array(
	'transaction start' => array( '/^START TRANSACTION/', 0, false ),
	'reservation release' => array( '/^UPDATE \S+wpcap_reservations SET status=.released./', 0, false ),
	'ticket revocation' => array( "/^UPDATE \S+wpcap_tickets SET status='revoked'/", 1, false ),
	'order status update' => array( "/^UPDATE \S+wpcap_orders SET status='refunded'/", 0, false ),
	'commit' => array( '/^COMMIT/', 0, false ),
);
foreach ( $refund_steps as $label => list( $pattern, $skip, $silent ) ) {
	$o = odf_order( $type, 2 );
	Orders::confirm_external_payment( $o['order_id'], 'odf' );
	$event_id = 'odf-refund-' . md5( $label );
	$faults = $odf_faults();
	$faults->fail( $pattern, $skip, 1, $silent );
	$r = Webhooks::process( 'manual', odf_event( $o, 'refunded', $event_id ) );
	$odf_restore();
	check( "refund fault ($label): retryable error, claim released", is_wp_error( $r ) && null === odf_claim( $event_id ) );
	check( "refund fault ($label): order and tickets unchanged", 'paid' === Orders::get( $o['order_id'] )->payment_status && 2 === count( tickets( $o['order_id'], 'valid' ) ) && 0 === odf_audit( 'order_refunded', $o['order_id'] ) );
	$r = Webhooks::process( 'manual', odf_event( $o, 'refunded', $event_id ) );
	check( "refund fault ($label): retry refunds and revokes", 'refunded' === $r && 'refunded' === Orders::get( $o['order_id'] )->status && 2 === count( tickets( $o['order_id'], 'revoked' ) ) );
}
$r2 = Webhooks::process( 'manual', odf_event( $o, 'refunded', 'odf-refund-repeat' ) );
check( 'repeated refund event (new id) is idempotent', 'refunded' === $r2 && 1 === odf_audit( 'order_refunded', $o['order_id'] ) && true === Orders::mark_refunded( $o['order_id'], 'again' ) && 1 === odf_audit( 'order_refunded', $o['order_id'] ) );

echo "Audit: ticket issuance checks every write and keeps attendee data until stored\n";
$atype = make_type( 50, 10, 1500 );
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $atype, 'field_key' => 'diet', 'label' => 'Essen', 'field_type' => 'text', 'required' => 0, 'status' => 'active' ) );
$attendees = array(
	array( 'ticket_type_id' => $atype, 'name' => 'Anna A', 'email' => 'anna@example.com', 'answers' => array( 'diet' => 'vegan' ) ),
	array( 'ticket_type_id' => $atype, 'name' => 'Ben B', 'email' => 'ben@example.com', 'answers' => array( 'diet' => 'none' ) ),
);
$issue_steps = array(
	'ticket insert' => array( '/^INSERT INTO \S+wpcap_tickets /', 1 ),
	'attendee assignment' => array( '/^INSERT INTO \S+wpcap_ticket_assignments/', 1 ),
	'attendee answer' => array( '/^INSERT INTO \S+wpcap_participant_answers/', 1 ),
	'ticket attendee e-mail' => array( "/^UPDATE \S+wpcap_tickets SET attendee_email=/", 1 ),
	'attendee data cleanup' => array( '/^UPDATE \S+wpcap_orders SET attendee_data=NULL/', 0 ),
	'delivery outbox' => array( '/^INSERT INTO \S+wpcap_jobs \(idempotency_key,job_type/', 0 ),
	'commit' => array( '/^COMMIT/', 1 ), // The first COMMIT is the payment confirmation.
);
foreach ( $issue_steps as $label => list( $pattern, $skip ) ) {
	$o = odf_order( $atype, 2, array( 'attendees' => $attendees ) );
	$event_id = 'odf-issue-' . md5( $label );
	$faults = $odf_faults();
	$faults->fail( $pattern, $skip );
	$r = Webhooks::process( 'manual', odf_event( $o, 'paid', $event_id ) );
	$odf_restore();
	$order = Orders::get( $o['order_id'] );
	$assignments = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_ticket_assignments a JOIN {$p}wpcap_tickets t ON t.ticket_id=a.ticket_id WHERE t.order_id=" . (int) $o['order_id'] );
	check( "issue fault ($label): payment booked, webhook answers a retryable error", is_wp_error( $r ) && 'paid' === $order->payment_status && null === odf_claim( $event_id ) );
	check( "issue fault ($label): fully rolled back, attendee data kept", 0 === count( Orders::tickets_for_order( $o['order_id'] ) ) && 0 === $assignments && null !== $order->attendee_data && null === odf_delivery_job( $o['order_id'] ) );
	check( "issue fault ($label): issuance retry job queued", 1 <= (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_jobs WHERE job_type='ticket_issue' AND payload=%s", wp_json_encode( array( 'order_id' => (int) $o['order_id'] ) ) ) ) );
	$r = Webhooks::process( 'manual', odf_event( $o, 'paid', $event_id ) );
	$order = Orders::get( $o['order_id'] );
	$answers = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_participant_answers a JOIN {$p}wpcap_tickets t ON t.ticket_id=a.ticket_id WHERE t.order_id=" . (int) $o['order_id'] );
	check( "issue fault ($label): redelivery issues tickets with attendees, then drops the order copy", 'already_paid' === $r && 2 === count( Orders::tickets_for_order( $o['order_id'] ) ) && 2 === $answers && null === $order->attendee_data && 'processed' === odf_claim( $event_id ) );
}
// The queued issuance job issues the tickets without any webhook redelivery.
$o = odf_order( $atype, 2, array( 'attendees' => $attendees ) );
$faults = $odf_faults();
$faults->fail( '/^INSERT INTO \S+wpcap_tickets /', 0 );
Orders::confirm_external_payment( $o['order_id'], 'odf' );
$odf_restore();
check( 'failed issuance leaves a paid order without tickets', 0 === count( Orders::tickets_for_order( $o['order_id'] ) ) && 2 === Orders::missing_tickets( $o['order_id'] ) );
check( 'ticket_issue job handler issues the tickets', true === Orders::handle_job( false, 'ticket_issue', array( 'order_id' => (int) $o['order_id'] ) ) && 2 === count( Orders::tickets_for_order( $o['order_id'] ) ) && 0 === Orders::missing_tickets( $o['order_id'] ) );
// Unassigned attendee entries keep the order-level copy.
$o = odf_order( $atype, 2, array( 'attendees' => $attendees ) );
$wpdb->update( "{$p}wpcap_orders", array( 'attendee_data' => wp_json_encode( array_merge( $attendees, array( array( 'ticket_type_id' => 999999, 'name' => 'X', 'email' => '', 'answers' => array() ) ) ) ) ), array( 'order_id' => $o['order_id'] ) );
Orders::confirm_external_payment( $o['order_id'], 'odf' );
check( 'attendee copy is kept while an entry could not be assigned', 2 === count( Orders::tickets_for_order( $o['order_id'] ) ) && null !== Orders::get( $o['order_id'] )->attendee_data );

echo "Audit: ticket delivery status and automatic retry\n";
$o = odf_order( $type, 2 );
$buyer = Orders::get( $o['order_id'] )->customer_email;
$GLOBALS['wpcap_test_filters']['wpcap_mail'] = false; // Mailer::deliver() fails.
Orders::confirm_external_payment( $o['order_id'], 'odf' );
unset( $GLOBALS['wpcap_test_filters']['wpcap_mail'] );
$status = Orders::delivery_status( $o['order_id'] );
$job = odf_delivery_job( $o['order_id'] );
check( 'failed ticket mail is persisted as retrying with one attempt', 2 === count( tickets( $o['order_id'], 'valid' ) ) && $status && 'retrying' === $status['status'] && 1 === $status['attempts'] && 'queued' === $job->status );
check( 'failed delivery is audited', 1 === odf_audit( 'ticket_delivery_failed', $o['order_id'] ) );
$before = odf_hashes( $o['order_id'] );
$payload = json_decode( $job->payload, true );
$GLOBALS['wpcap_test_filters']['wpcap_mail'] = false;
$handled = Orders::handle_job( false, 'ticket_delivery', $payload );
unset( $GLOBALS['wpcap_test_filters']['wpcap_mail'] );
check( 'retry with failing mail is not acknowledged and keeps the codes', false === $handled && $before === odf_hashes( $o['order_id'] ) );
$mails = odf_mails_to( $buyer );
$handled = Orders::handle_job( false, 'ticket_delivery', $payload );
$after = odf_hashes( $o['order_id'] );
check( 'retry rotates the codes and mails the buyer', true === $handled && odf_mails_to( $buyer ) === $mails + 1 && 2 === count( array_diff( $after, $before ) ) );
$wpdb->update( "{$p}wpcap_jobs", array( 'status' => 'complete' ), array( 'job_id' => (int) $job->job_id ) );
check( 'completed outbox job reads as sent', 'sent' === Orders::delivery_status( $o['order_id'] )['status'] );
$wpdb->update( "{$p}wpcap_jobs", array( 'status' => 'dead_letter' ), array( 'job_id' => (int) $job->job_id ) );
check( 'dead-lettered outbox job reads as failed', 'failed' === Orders::delivery_status( $o['order_id'] )['status'] );
// Successful inline delivery completes the outbox job.
$o = odf_order( $type, 1 );
Orders::confirm_external_payment( $o['order_id'], 'odf' );
$job = odf_delivery_job( $o['order_id'] );
check( 'successful inline delivery completes the outbox job', $job && 'complete' === $job->status && 1 === (int) $job->attempts && 'sent' === Orders::delivery_status( $o['order_id'] )['status'] );
// Crash after the commit (exception while mailing): the outbox job stays queued for the retry.
$o = odf_order( $type, 1 );
$crash = static function ( $mail ) { throw new RuntimeException( 'crash' ); };
add_filter( 'wpcap_ticket_mail', $crash );
$thrown = false;
try { Orders::confirm_external_payment( $o['order_id'], 'odf' ); } catch ( RuntimeException $e ) { $thrown = true; }
remove_filter( 'wpcap_ticket_mail', $crash );
$job = odf_delivery_job( $o['order_id'] );
check( 'crash after commit leaves a due-later outbox job', $thrown && 1 === count( tickets( $o['order_id'], 'valid' ) ) && 'queued' === $job->status && 'pending' === Orders::delivery_status( $o['order_id'] )['status'] && $job->run_after > gmdate( 'Y-m-d H:i:s' ) );
check( 'outbox retry delivers after the crash', true === Orders::handle_job( false, 'ticket_delivery', json_decode( $job->payload, true ) ) );
// Revoked tickets need no delivery: the job completes without mail.
Orders::cancel_order( $o['order_id'], 'odf' );
$mails = count( $GLOBALS['wpcap_test_mails'] );
check( 'outbox job for revoked tickets completes without mail', true === Orders::handle_job( false, 'ticket_delivery', json_decode( $job->payload, true ) ) && count( $GLOBALS['wpcap_test_mails'] ) === $mails );

echo "Audit: bulk resend is serialised, checks rowcounts and never invalidates codes without a mail\n";
$o = odf_order( $type, 2 );
Orders::confirm_external_payment( $o['order_id'], 'odf' );
$ids = $wpdb->get_col( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=" . (int) $o['order_id'] . ' ORDER BY ticket_id' );
$wpdb->update( "{$p}wpcap_tickets", array( 'attendee_email' => 'holder@example.com' ), array( 'ticket_id' => (int) $ids[1] ) );
$buyer = Orders::get( $o['order_id'] )->customer_email;
$before = odf_hashes( $o['order_id'] );
$GLOBALS['wpcap_test_filters']['wpcap_mail'] = false;
$r = Orders::resend_tickets( $o['order_id'] );
unset( $GLOBALS['wpcap_test_filters']['wpcap_mail'] );
check( 'resend with failing mail returns an error and keeps every old code', is_wp_error( $r ) && 'wpcap_mail_failed' === $r->get_error_code() && $before === odf_hashes( $o['order_id'] ) );
$faults = $odf_faults();
$faults->fail( '/^UPDATE \S+wpcap_tickets SET token_hash=/', 1, 1, true ); // Second recipient's rotation affects 0 rows.
$mails_buyer = odf_mails_to( $buyer );
$mails_holder = odf_mails_to( 'holder@example.com' );
$r = Orders::resend_tickets( $o['order_id'] );
$odf_restore();
$after = odf_hashes( $o['order_id'] );
check( 'rotation affecting 0 rows is an error', is_wp_error( $r ) && 'wpcap_ticket_rotation_failed' === $r->get_error_code() && 1 === (int) ( $r->get_error_data()['sent'] ?? -1 ) );
check( 'partial failure: first recipient rotated and mailed, second unchanged and not mailed', $after[0] !== $before[0] && $after[1] === $before[1] && odf_mails_to( $buyer ) === $mails_buyer + 1 && odf_mails_to( 'holder@example.com' ) === $mails_holder );
$faults = $odf_faults();
$faults->fail( '/^COMMIT/' );
$mid = odf_hashes( $o['order_id'] );
$r = Orders::resend_tickets( $o['order_id'] );
$odf_restore();
check( 'failed commit after the mail rolls the rotation back', is_wp_error( $r ) && $mid[0] === odf_hashes( $o['order_id'] )[0] );
$r = Orders::resend_tickets( $o['order_id'] );
$final = odf_hashes( $o['order_id'] );
check( 'resend succeeds after the failures and rotates every code', true === $r && $final[0] !== $mid[0] && $final[1] !== $mid[1] );
// A rotation always compares against the hash read under the row lock (stale hash = 0 rows = error).
$faults = $odf_faults();
$faults->fail( '/FROM \S+wpcap_tickets WHERE order_id=\d+ AND status=.valid. AND ticket_id IN \([0-9,]+\) ORDER BY ticket_id FOR UPDATE/' );
$r = Orders::resend_tickets( $o['order_id'] );
$odf_restore();
check( 'row lock failure aborts the resend without changes', is_wp_error( $r ) && $final === odf_hashes( $o['order_id'] ) );
check( 'resend still rejects unpaid orders', is_wp_error( Orders::resend_tickets( odf_order( $type, 1 )['order_id'] ) ) );

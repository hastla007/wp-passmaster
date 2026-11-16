<?php
/**
 * A671: E2E matrix ticket purchase → admission → review request with a stub review plugin, including
 * the exclusions – refund (before and after admission), cancelled date, no-show, opt-out (the
 * plugin's own list), duplicates (repeated scans, hooks, replay), transfer, missing plugin installed
 * later. Acceptance: a confirmed experience produces at most one allowed request; purchase, dispatch
 * and generic events produce none. Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Checkin;
use PassCaptain\Orders;
use PassCaptain\Review\ContactResolver;
use PassCaptain\Review\Contract as ReviewContract;
use PassCaptain\Review\Events as ReviewEvents;
use PassCaptain\Workflow\Outbox;

global $wpdb;
$p = $wpdb->prefix;
$x6m2_hooks_before = $GLOBALS['wpcap_test_hooks'];
$x6m2_filters_before = $GLOBALS['wpcap_test_filters'];
unset( $GLOBALS['wpcap_test_filters'][ ReviewContract::FILTER ] );
ReviewEvents::register();
PassCaptain\Workflow\Events::register();

/**
 * Stub review plugin: queues completed experiences, drops excluded/cancelled ones, sends at most one
 * invitation per experience and recipient – through the contact resolver – unless opted out.
 */
final class X6m2_Review_Stub {
	public $queue = array();
	public $sent = array();
	public $skipped = array();
	public $opt_out = array();
	public $types = array();
	public function contract_version() { return '1.0.0'; }
	public function receive( array $env ) {
		$this->types[] = $env['type'];
		$d = $env['data'];
		if ( 'experience_completed' === $env['type'] ) {
			$this->queue[ $d['participation_id'] ] = array( 'participation_id' => $d['participation_id'], 'recipient_ref' => $d['recipient_ref'], 'experience_id' => $d['experience_id'] );
		} elseif ( 'experience_excluded' === $env['type'] ) {
			unset( $this->queue[ $d['participation_id'] ] );
		} elseif ( 'experience_cancelled' === $env['type'] ) {
			foreach ( $this->queue as $id => $q ) {
				if ( in_array( (int) substr( $q['experience_id'], 11 ), (array) $d['occurrence_ids'], true ) ) { unset( $this->queue[ $id ] ); }
			}
		}
		return true;
	}
	/** The plugin's own sending run (after its delay). */
	public function flush() {
		foreach ( $this->queue as $id => $q ) {
			unset( $this->queue[ $id ] );
			$key = $q['experience_id'] . '|' . $q['recipient_ref'];
			if ( isset( $this->sent[ $key ] ) ) { $this->skipped[ $id ] = 'duplicate'; continue; }
			$contact = ContactResolver::resolve( $q['participation_id'], $q['recipient_ref'], array( 'source' => 'stub-review' ) );
			if ( is_wp_error( $contact ) ) { $this->skipped[ $id ] = $contact->get_error_code(); continue; }
			if ( in_array( $contact['email'], $this->opt_out, true ) ) { $this->skipped[ $id ] = 'opt_out'; continue; }
			$this->sent[ $key ] = array( 'participation_id' => $id, 'email' => $contact['email'] );
		}
	}
	public function sent_to( $participation_id ) {
		return count( array_filter( $this->sent, static fn( $s ) => $s['participation_id'] === $participation_id ) );
	}
}

echo "A671: E2E purchase → admission → review request, with exclusions\n";
$x6m2_type = make_type( 20, 10, 3000 );
$x6m2_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$x6m2_type" );
$x6m2_type_b = make_type( 5, 10, 3000 );
$x6m2_occ_b = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$x6m2_type_b" );
$x6m2_attendees = array();
foreach ( range( 0, 6 ) as $i ) { $x6m2_attendees[] = array( 'ticket_type_id' => $x6m2_type, 'name' => 'Gast ' . $i, 'email' => 'gast' . $i . '@example.com' ); }
$x6m2_o = Orders::create( array( 'email' => 'x6m2@example.com', 'items' => array( array( 'ticket_type_id' => $x6m2_type, 'quantity' => 7 ), array( 'ticket_type_id' => $x6m2_type_b, 'quantity' => 1 ) ), 'attendees' => $x6m2_attendees, 'idempotency_key' => 'x6m2-' . uniqid() ) );
Orders::confirm_manual_payment( $x6m2_o['order_id'] );
$x6m2_codes = ticket_code_for( $x6m2_o['order_id'] );
$x6m2_t = tickets( $x6m2_o['order_id'] );
$x6m2_code = static function ( $ticket ) use ( $x6m2_codes, $wpdb, $p ) { $h = (string) $wpdb->get_var( "SELECT token_hash FROM {$p}wpcap_tickets WHERE ticket_id=" . (int) $ticket->ticket_id ); foreach ( $x6m2_codes as $c ) { if ( hash( 'sha256', $c ) === $h ) { return $c; } } return ''; };
$x6m2_in = static function ( $ticket ) use ( $x6m2_code ) { return Checkin::perform( $x6m2_code( $ticket ), array( 'idempotency_key' => 'x6m2-' . uniqid( '', true ) ) ); };
list( $t_ok, $t_dup, $t_ref_before, $t_ref_after, $t_noshow, $t_optout, $t_transfer, $t_cancel ) = $x6m2_t;
check( 'A671 fixture: 8 tickets on two dates', 8 === count( $x6m2_t ) && 8 === count( $x6m2_codes ) && $x6m2_occ_b === (int) $wpdb->get_var( "SELECT tt.occurrence_id FROM {$p}wpcap_ticket_types tt WHERE tt.ticket_type_id=" . (int) $t_cancel->ticket_type_id ) );

// Plugin missing during purchase, admission and exclusions.
PassCaptain\Settings::update( array( 'refund_approval_threshold' => 0 ) );
PassCaptain\Refunds::request( (int) $x6m2_o['order_id'], array( (int) $t_ref_before->ticket_id ), 'vorher', 'x6m2-rb-' . uniqid() );
$x6m2_in( $t_ok );
$x6m2_in( $t_dup );
$x6m2_in( $t_dup ); // second scan: already checked in
do_action( 'wpcap_ticket_checked_in', (int) $t_dup->ticket_id, array( 'checkin_id' => 777001 ) ); // repeated hook
$x6m2_in( $t_ref_after );
// Goodwill refund of a used ticket (approver override, A537 flow).
$x6m2_ra = PassCaptain\Refunds::request( (int) $x6m2_o['order_id'], array( (int) $t_ref_after->ticket_id ), 'nachher', 'x6m2-ra-' . uniqid(), array( 'used_override' => 'Kulanz nach Beschwerde' ) );
if ( ! is_wp_error( $x6m2_ra ) && 'pending_approval' === ( $x6m2_ra['status'] ?? '' ) ) {
	$x6m2_user = $GLOBALS['wpcap_test_user_id'] ?? 1;
	$GLOBALS['wpcap_test_user_id'] = 2; // Four-eyes: another approver.
	PassCaptain\Refunds::approve( (int) $x6m2_ra['refund_id'] );
	$GLOBALS['wpcap_test_user_id'] = $x6m2_user;
}
check( 'A671 fixture: a used ticket is refunded with the approver override', 1 === (int) $wpdb->get_var( "SELECT refunded FROM {$p}wpcap_tickets WHERE ticket_id=" . (int) $t_ref_after->ticket_id ) );
$x6m2_in( $t_optout );
// Transfer before admission: the new holder attends.
$wpdb->query( "UPDATE {$p}wpcap_tickets SET transfer_count=transfer_count+1 WHERE ticket_id=" . (int) $t_transfer->ticket_id );
$wpdb->query( $wpdb->prepare( "INSERT INTO {$p}wpcap_ticket_assignments (ticket_id,buyer_email,attendee_email,attendee_name,status,created_at) VALUES (%d,'x6m2@example.com','neu@example.com','Neue Person','assigned',%s) ON DUPLICATE KEY UPDATE attendee_email='neu@example.com',attendee_name='Neue Person'", (int) $t_transfer->ticket_id, gmdate( 'Y-m-d H:i:s' ) ) );
do_action( 'wpcap_ticket_transferred', (int) $t_transfer->ticket_id, array( 'source' => 'portal' ) );
$x6m2_in( $t_transfer );
// Second date cancelled before it took place.
do_action( 'wpcap_occurrence_cancelled', $x6m2_occ_b, 1 );
$wpdb->update( "{$p}wpcap_occurrences", array( 'status' => 'cancelled' ), array( 'occurrence_id' => $x6m2_occ_b ) );
$x6m2_in( $t_cancel );
// The first date ended: no-show scan.
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ), 'ends_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS + 2 * HOUR_IN_SECONDS ) ), array( 'occurrence_id' => $x6m2_occ ) );
ReviewEvents::scan_no_shows();
check( 'A671: without a review plugin nothing is delivered, the events wait', 0 === Outbox::deliver() && (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Outbox::table() . " WHERE contract='review' AND status='pending'" ) > 0 );

// The review plugin is installed later and catches up.
$x6m2_stub = new X6m2_Review_Stub();
$x6m2_stub->opt_out = array( 'gast5@example.com' );
$GLOBALS['wpcap_test_filters'][ ReviewContract::FILTER ] = $x6m2_stub;
Outbox::deliver();
$x6m2_stub->flush();
$x6m2_sent = static fn( $t ) => $x6m2_stub->sent_to( (string) $t->public_id );
check( 'A671: an attended experience produces exactly one request (to the attendee contact)', 1 === $x6m2_sent( $t_ok ) && 'gast0@example.com' === array_values( array_filter( $x6m2_stub->sent, static fn( $s ) => $s['participation_id'] === (string) $t_ok->public_id ) )[0]['email'] );
check( 'A671: duplicate scans and repeated hooks still produce one request', 1 === $x6m2_sent( $t_dup ) );
check( 'A671: refund before admission – no request', 0 === $x6m2_sent( $t_ref_before ) );
check( 'A671: refund after admission – no request (excluded before the plugin sent it)', 0 === $x6m2_sent( $t_ref_after ) );
check( 'A671: no-show – no request', 0 === $x6m2_sent( $t_noshow ) && in_array( 'experience_excluded', $x6m2_stub->types, true ) );
check( 'A671: opt-out (the plugin\'s own list) – no request, although the resolver allowed it', 0 === $x6m2_sent( $t_optout ) && 'opt_out' === ( $x6m2_stub->skipped[ (string) $t_optout->public_id ] ?? '' ) );
check( 'A671: transferred before admission – the request goes to the new holder', 1 === $x6m2_sent( $t_transfer ) && in_array( 'neu@example.com', array_column( $x6m2_stub->sent, 'email' ), true ) );
check( 'A671: cancelled date – no request (experience_cancelled once, no completion)', 0 === $x6m2_sent( $t_cancel ) && 1 === count( array_keys( $x6m2_stub->types, 'experience_cancelled', true ) ) );
check( 'A671: acceptance – exactly the attended, not excluded experiences got one request each', 3 === count( $x6m2_stub->sent ) );

// Replay and a second delivery do not lead to a second request.
Outbox::replay( 0, 'review' );
Outbox::deliver();
$x6m2_stub->flush();
check( 'A671: replay of all review events – still one request per experience and recipient', 3 === count( $x6m2_stub->sent ) );
// The core never sends review events for purchase, dispatch or generic events.
check( 'A671: only the three participation event types reach the plugin', ! array_diff( array_unique( $x6m2_stub->types ), array( 'experience_completed', 'experience_excluded', 'experience_cancelled' ) ) );
check( 'A671: every resolver call of the plugin was audited without the contact', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='review_contact_resolved' AND details LIKE '%@%'" ) && (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='review_contact_resolved' AND details LIKE '%stub-review%'" ) >= 4 );

$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS ), 'ends_at' => null ), array( 'occurrence_id' => $x6m2_occ ) );
$wpdb->query( 'UPDATE ' . Outbox::table() . " SET status='delivered' WHERE contract='review' AND status<>'delivered'" );
$GLOBALS['wpcap_test_hooks'] = $x6m2_hooks_before;
$GLOBALS['wpcap_test_filters'] = $x6m2_filters_before;

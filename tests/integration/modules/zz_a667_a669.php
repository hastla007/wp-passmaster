<?php
/**
 * A667/A669: review participation contract v1 – experience_completed at the first valid admission
 * (never at purchase or dispatch), language, no personal data, a new completion only after an undo;
 * corrections and exclusions (undo, refund, revocation, transfer, no-show) per participation and
 * cancellation once on event level – all idempotent, in the shared outbox (column contract).
 * Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Checkin;
use PassCaptain\Orders;
use PassCaptain\Review\Contract as ReviewContract;
use PassCaptain\Review\Events as ReviewEvents;
use PassCaptain\Suite\Outbox;

require_once dirname( __DIR__, 2 ) . '/contracts/shape.php';

global $wpdb;
$p = $wpdb->prefix;
$x6r_hooks_before = $GLOBALS['wpcap_test_hooks'];
$x6r_filters_before = $GLOBALS['wpcap_test_filters'];
ReviewEvents::register();
unset( $GLOBALS['wpcap_test_filters'][ ReviewContract::FILTER ] );
$x6r_t = Outbox::table();
$x6r_events = static function ( $subject, $type = null ) use ( $wpdb, $x6r_t ) {
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $x6r_t WHERE contract='review' AND subject_id=%s" . ( $type ? ' AND event_type=%s' : ' AND %s=%s' ) . ' ORDER BY outbox_id', $subject, $type ? $type : 'x', $type ? $type : 'x' ) );
	return array_map( static fn( $r ) => json_decode( $r->payload, true ), (array) $rows );
};

echo "A667: review participation contract v1\n";
check( 'A667: contract "review" 1.0.0 is listed with the other contracts', '1.0.0' === PassCaptain\Contracts::version( 'review' ) && Outbox::multi_ready() );
check( 'A667: the contract has experience_completed, experience_excluded and experience_cancelled with schema versions', array( 'experience_completed', 'experience_excluded', 'experience_cancelled' ) === array_keys( ReviewContract::EVENTS ) && '1.0' === ReviewContract::schema_version( 'experience_completed' ) );
check( 'A667: no allowlisted field is a forbidden (code/PII) key', ! array_filter( ReviewContract::OBJECTS, static fn( $o ) => array_intersect( array_keys( $o ), ReviewContract::FORBIDDEN ) ) && ! array_filter( ReviewContract::EVENTS, static fn( $d ) => array_intersect( array_keys( $d[2] ), ReviewContract::FORBIDDEN ) ) );
check( 'A667: the resolver capability is its own and no plugin role grants it', 'wpcap_resolve_review_contacts' === ReviewContract::RESOLVER_CAPABILITY && ! in_array( ReviewContract::RESOLVER_CAPABILITY, PassCaptain\Installer::CAPABILITIES, true ) );

$x6r_type = make_type( 10, 10, 2500 );
$x6r_o = Orders::create( array( 'email' => 'x6r-buyer@example.com', 'locale' => 'en_US', 'items' => array( array( 'ticket_type_id' => $x6r_type, 'quantity' => 4 ) ), 'attendees' => array( array( 'ticket_type_id' => $x6r_type, 'name' => 'Ada Lovelace', 'email' => 'ada@example.com' ) ), 'idempotency_key' => 'x6r-' . uniqid() ) );
Orders::confirm_manual_payment( $x6r_o['order_id'] );
$x6r_codes = ticket_code_for( $x6r_o['order_id'] );
$x6r_tickets = tickets( $x6r_o['order_id'] );
$x6r_pub = array_map( static fn( $t ) => (string) $t->public_id, $x6r_tickets );
$x6r_review_rows = static function () use ( $wpdb, $x6r_t, $x6r_pub ) { return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $x6r_t WHERE contract='review' AND subject_id IN ('" . implode( "','", $x6r_pub ) . "')" ); };
check( 'A667: purchase, payment and ticket dispatch create no review event', 4 === count( $x6r_codes ) && 0 === $x6r_review_rows() );

$x6r_by_code = array();
foreach ( $x6r_codes as $x6r_c ) { $x6r_by_code[ (string) $wpdb->get_var( $wpdb->prepare( "SELECT public_id FROM {$p}wpcap_tickets WHERE token_hash=%s", hash( 'sha256', $x6r_c ) ) ) ] = $x6r_c; }
$x6r_code = static function ( $i ) use ( $x6r_pub, $x6r_by_code ) { return $x6r_by_code[ $x6r_pub[ $i ] ]; };
$x6r_r = Checkin::perform( $x6r_code( 0 ), array( 'idempotency_key' => 'x6r-ci-' . uniqid(), 'source' => 'app' ) );
$x6r_done = $x6r_events( $x6r_pub[0], 'experience_completed' );
$x6r_env = $x6r_done[0] ?? array();
check( 'A667: the first valid admission records experience_completed once', ! is_wp_error( $x6r_r ) && 1 === count( $x6r_done ) );
check( 'A667: the envelope names experience, participation, time, method, language – and contract review 1.0.0', 'review' === ( $x6r_env['contract'] ?? '' ) && '1.0.0' === ( $x6r_env['contract_version'] ?? '' ) && 'occurrence:' . (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$x6r_type" ) === ( $x6r_env['data']['experience_id'] ?? '' ) && $x6r_pub[0] === ( $x6r_env['data']['participation_id'] ?? '' ) && 'attended' === ( $x6r_env['data']['outcome'] ?? '' ) && 'check_in' === ( $x6r_env['data']['method'] ?? '' ) && 'app' === ( $x6r_env['data']['source'] ?? '' ) && 'en_US' === ( $x6r_env['data']['locale'] ?? '' ) && preg_match( '/^rcp_[a-f0-9]{32}$/', (string) ( $x6r_env['data']['recipient_ref'] ?? '' ) ) && preg_match( '/^\d{4}-\d\d-\d\dT/', (string) ( $x6r_env['data']['completed_at'] ?? '' ) ) );
$x6r_json = (string) wp_json_encode( $x6r_env );
check( 'A667: no e-mail, name or ticket code in the envelope', false === strpos( $x6r_json, '@' ) && false === stripos( $x6r_json, 'Lovelace' ) && false === strpos( $x6r_json, $x6r_code( 0 ) ) && ! preg_match( '/[a-f0-9]{64}/', $x6r_json ) );
do_action( 'wpcap_ticket_checked_in', (int) $x6r_tickets[0]->ticket_id, array( 'checkin_id' => 999999, 'source' => 'web' ) );
check( 'A667: further admissions or repeated hooks do not complete again', 1 === count( $x6r_events( $x6r_pub[0], 'experience_completed' ) ) );

echo "A669: corrections and exclusions\n";
PassCaptain\Admin\TicketsPage::undo_checkin( array( 'ticket_id' => (int) $x6r_tickets[0]->ticket_id, 'reason' => 'Fehlscan' ) );
$x6r_ex = $x6r_events( $x6r_pub[0], 'experience_excluded' );
check( 'A669: undoing the only admission excludes the participation (check_in_undone)', 1 === count( $x6r_ex ) && 'check_in_undone' === ( $x6r_ex[0]['data']['reason'] ?? '' ) && 'excluded' === ( $x6r_ex[0]['data']['outcome'] ?? '' ) );
check( 'A669: after an undo the experience is no longer completed', ! ReviewEvents::completed( (int) $x6r_tickets[0]->ticket_id ) );
Checkin::perform( $x6r_code( 0 ), array( 'idempotency_key' => 'x6r-ci2-' . uniqid() ) );
check( 'A667: a new admission after the undo completes again (new cycle, new event id)', 2 === count( $x6r_events( $x6r_pub[0], 'experience_completed' ) ) && ReviewEvents::completed( (int) $x6r_tickets[0]->ticket_id ) && 2 === count( array_unique( array_column( $x6r_events( $x6r_pub[0], 'experience_completed' ), 'id' ) ) ) );

// Refund of ticket 1 (admitted) and revocation: excluded, idempotent.
PassCaptain\Settings::update( array( 'refund_approval_threshold' => 0 ) );
$x6r_ref = PassCaptain\Refunds::request( (int) $x6r_o['order_id'], array( (int) $x6r_tickets[1]->ticket_id ), 'x6r', 'x6r-rf-' . uniqid() );
$x6r_ref_ex = $x6r_events( $x6r_pub[1], 'experience_excluded' );
check( 'A669: a refunded ticket is excluded (refunded) and its revocation once more (revoked), each once', ! is_wp_error( $x6r_ref ) && in_array( 'refunded', array_map( static fn( $e ) => $e['data']['reason'], $x6r_ref_ex ), true ) && count( $x6r_ref_ex ) === count( array_unique( array_map( static fn( $e ) => $e['data']['reason'], $x6r_ref_ex ) ) ) );
do_action( 'wpcap_refund_completed', is_array( $x6r_ref ) ? (int) ( $x6r_ref['refund_id'] ?? 0 ) : 0, (int) $x6r_o['order_id'] );
check( 'A669: a repeated refund hook records nothing new', count( $x6r_ref_ex ) === count( $x6r_events( $x6r_pub[1], 'experience_excluded' ) ) );
$wpdb->update( "{$p}wpcap_tickets", array( 'status' => 'valid' ), array( 'ticket_id' => (int) $x6r_tickets[1]->ticket_id ) );
do_action( 'wpcap_ticket_checked_in', (int) $x6r_tickets[1]->ticket_id, array( 'checkin_id' => 999998 ) );
check( 'A667: a refunded ticket never completes', 0 === count( $x6r_events( $x6r_pub[1], 'experience_completed' ) ) );

// Transfer after admission: the previous recipient reference is invalidated.
$x6r_before = $x6r_events( $x6r_pub[0], 'experience_completed' );
$x6r_old_ref = end( $x6r_before )['data']['recipient_ref'];
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_tickets SET transfer_count=transfer_count+1 WHERE ticket_id=%d", (int) $x6r_tickets[0]->ticket_id ) );
do_action( 'wpcap_ticket_transferred', (int) $x6r_tickets[0]->ticket_id, array( 'source' => 'portal', 'holder_changes' => 1 ) );
do_action( 'wpcap_ticket_transferred', (int) $x6r_tickets[0]->ticket_id, array( 'source' => 'portal', 'holder_changes' => 1 ) );
$x6r_tr = array_values( array_filter( $x6r_events( $x6r_pub[0], 'experience_excluded' ), static fn( $e ) => 'transferred' === $e['data']['reason'] ) );
check( 'A669: a transfer excludes the previous holder once, naming the outdated recipient_ref; the new ref differs', 1 === count( $x6r_tr ) && $x6r_old_ref === $x6r_tr[0]['data']['previous_recipient_ref'] && $x6r_old_ref !== $x6r_tr[0]['data']['recipient_ref'] && $x6r_tr[0]['data']['recipient_ref'] === ReviewEvents::recipient_ref( (int) $x6r_tickets[0]->ticket_id ) );

// No-show: date ended, ticket 2 and 3 never admitted.
$x6r_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$x6r_type" );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ), 'ends_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS + 3 * HOUR_IN_SECONDS ) ), array( 'occurrence_id' => $x6r_occ ) );
$x6r_n = ReviewEvents::scan_no_shows();
$x6r_n2 = ReviewEvents::scan_no_shows();
$x6r_ns = static fn( $i ) => count( array_filter( $x6r_events( $x6r_pub[ $i ], 'experience_excluded' ), static fn( $e ) => 'no_show' === $e['data']['reason'] ) );
check( 'A669: the cron excludes valid event tickets without admission after the date ended (no_show), once', $x6r_n >= 2 && 0 === $x6r_n2 && 1 === $x6r_ns( 2 ) && 1 === $x6r_ns( 3 ) && 0 === $x6r_ns( 0 ) && 0 === $x6r_ns( 1 ) );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ), 'ends_at' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) ), array( 'occurrence_id' => $x6r_occ ) );

// Cancellation on event level: one event, not one per ticket.
$x6r_event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$x6r_occ" );
do_action( 'wpcap_occurrence_cancelled', $x6r_occ, 4 );
do_action( 'wpcap_occurrence_cancelled', $x6r_occ, 4 );
do_action( 'wpcap_event_cancelled', $x6r_event, array() );
$x6r_c1 = $x6r_events( 'occurrence:' . $x6r_occ, 'experience_cancelled' );
$x6r_c2 = $x6r_events( 'event:' . $x6r_event, 'experience_cancelled' );
check( 'A669: a cancelled date and a cancelled event are one event each on experience level (idempotent)', 1 === count( $x6r_c1 ) && 'occurrence' === $x6r_c1[0]['data']['scope'] && array( $x6r_occ ) === $x6r_c1[0]['data']['occurrence_ids'] && 1 === count( $x6r_c2 ) && 'event' === $x6r_c2[0]['data']['scope'] && $x6r_event === $x6r_c2[0]['data']['event_id'] );
$wpdb->update( "{$p}wpcap_occurrences", array( 'status' => 'cancelled' ), array( 'occurrence_id' => $x6r_occ ) );
$wpdb->update( "{$p}wpcap_tickets", array( 'status' => 'valid' ), array( 'ticket_id' => (int) $x6r_tickets[3]->ticket_id ) );
do_action( 'wpcap_ticket_checked_in', (int) $x6r_tickets[3]->ticket_id, array( 'checkin_id' => 999997 ) );
check( 'A667: no completion for a cancelled date', 0 === count( $x6r_events( $x6r_pub[3], 'experience_completed' ) ) );

// Every outbox row belongs to the review contract (no workflow contract any more, PC-7).
$x6r_wf_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $x6r_t WHERE contract<>'review' AND subject_id IN ('" . implode( "','", $x6r_pub ) . "')" );
$x6r_rv_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $x6r_t WHERE contract='review' AND event_type NOT LIKE 'experience%'" );
check( 'A667: the outbox only records review events (no workflow event for the same tickets, PC-7)', 0 === $x6r_wf_rows && 0 === $x6r_rv_rows );
$x6r_fx = wpcap_contract_fixture( 'review' );
$x6r_fx_ok = array_keys( ReviewContract::EVENTS ) === array_keys( (array) ( $x6r_fx['endpoints'] ?? array() ) );
foreach ( (array) ( $x6r_fx['endpoints'] ?? array() ) as $x6r_type_name => $x6r_ep ) {
	$x6r_fx_ok = $x6r_fx_ok && ReviewContract::schema_version( $x6r_type_name ) === $x6r_ep['schema_version'] && ! array_diff( array_keys( $x6r_ep['request']['data'] ), array_keys( ReviewContract::fields( $x6r_type_name ) ) );
}
check( 'A667: the fixture tests/contracts/review.json covers every event type and no field of it was removed', $x6r_fx_ok );
$x6r_env['sequence'] = 1; $x6r_env['replay'] = false;
check( 'A667: a recorded envelope matches the fixture shape', array() === wpcap_contract_violations( $x6r_fx['endpoints']['experience_completed']['request'] ?? array(), wpcap_contract_json( $x6r_env ) ) );
$GLOBALS['wpcap_test_hooks'] = $x6r_hooks_before;
$GLOBALS['wpcap_test_filters'] = $x6r_filters_before;

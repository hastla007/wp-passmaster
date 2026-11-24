<?php
/**
 * A668: contact resolver for review invitations – own capability, only for completed experiences of
 * the current holder, every call audited without the contact itself. Review events carry no e-mail
 * or name. Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Checkin;
use PassCaptain\Orders;
use PassCaptain\Review\ContactResolver;
use PassCaptain\Review\Contract as ReviewContract;
use PassCaptain\Review\Events as ReviewEvents;

global $wpdb;
$p = $wpdb->prefix;
$x6c_hooks_before = $GLOBALS['wpcap_test_hooks'];
ReviewEvents::register();

echo "A668: contact resolver with its own capability and audit\n";
$x6c_type = make_type( 10, 10, 1500 );
// Merged checkout: the order language must be one of the offered mail languages (de_AT is not, en_US is).
$x6c_o = Orders::create( array( 'email' => 'x6c-buyer@example.com', 'locale' => 'en_US', 'items' => array( array( 'ticket_type_id' => $x6c_type, 'quantity' => 3 ) ), 'attendees' => array( array( 'ticket_type_id' => $x6c_type, 'name' => 'Grete Gast', 'email' => 'grete@example.com' ) ), 'idempotency_key' => 'x6c-' . uniqid() ) );
Orders::confirm_manual_payment( $x6c_o['order_id'] );
$x6c_codes = ticket_code_for( $x6c_o['order_id'] );
$x6c_tickets = tickets( $x6c_o['order_id'] );
$x6c_code_of = static function ( $ticket ) use ( $x6c_codes ) { foreach ( $x6c_codes as $c ) { if ( hash( 'sha256', $c ) === $GLOBALS['wpdb']->get_var( 'SELECT token_hash FROM ' . $GLOBALS['wpdb']->prefix . 'wpcap_tickets WHERE ticket_id=' . (int) $ticket->ticket_id ) ) { return $c; } } return ''; };
foreach ( array( 0, 1 ) as $i ) { Checkin::perform( $x6c_code_of( $x6c_tickets[ $i ] ), array( 'idempotency_key' => 'x6c-ci-' . $i . uniqid() ) ); }
$x6c_ref = static fn( $t ) => ReviewEvents::recipient_ref( (int) $t->ticket_id );
$x6c_audits = static function () use ( $wpdb, $p ) { return $wpdb->get_col( "SELECT details FROM {$p}wpcap_audit_logs WHERE action='review_contact_resolved' ORDER BY audit_id" ); };
$x6c_audit_before = count( $x6c_audits() );

$x6c_r = ContactResolver::resolve( $x6c_tickets[0]->public_id, $x6c_ref( $x6c_tickets[0] ), array( 'source' => 'review-captain' ) );
check( 'A668: the resolver returns the contact of an admitted participant (attendee address, name, order language)', is_array( $x6c_r ) && 'grete@example.com' === $x6c_r['email'] && 'Grete Gast' === $x6c_r['name'] && 'en_US' === $x6c_r['locale'] && $x6c_tickets[0]->public_id === $x6c_r['participation_id'] );
$x6c_r1 = ContactResolver::resolve( $x6c_tickets[1]->public_id, $x6c_ref( $x6c_tickets[1] ) );
check( 'A668: without an attendee address the buyer address is used', is_array( $x6c_r1 ) && 'x6c-buyer@example.com' === $x6c_r1['email'] );

$GLOBALS['wpcap_test_denied_caps'] = array( ReviewContract::RESOLVER_CAPABILITY );
$x6c_denied = ContactResolver::resolve( $x6c_tickets[0]->public_id, $x6c_ref( $x6c_tickets[0] ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'A668: without the own capability wpcap_resolve_review_contacts nothing is returned (403)', is_wp_error( $x6c_denied ) && 'wpcap_review_forbidden' === $x6c_denied->get_error_code() && 403 === $x6c_denied->get_error_data()['status'] );

$x6c_nc = ContactResolver::resolve( $x6c_tickets[2]->public_id, $x6c_ref( $x6c_tickets[2] ) );
check( 'A668: a participant without admission is not eligible (not_completed)', is_wp_error( $x6c_nc ) && 'wpcap_review_not_eligible' === $x6c_nc->get_error_code() && 'not_completed' === $x6c_nc->get_error_data()['reason'] );
$x6c_old = ContactResolver::resolve( $x6c_tickets[0]->public_id, 'rcp_' . str_repeat( '0', 32 ) );
check( 'A668: an outdated recipient_ref (other holder) is refused', is_wp_error( $x6c_old ) && 'wpcap_review_recipient_changed' === $x6c_old->get_error_code() );
check( 'A668: an unknown participation is refused', 'wpcap_review_not_found' === ContactResolver::resolve( str_repeat( 'f', 32 ), 'rcp_x' )->get_error_code() );
PassCaptain\Admin\TicketsPage::undo_checkin( array( 'ticket_id' => (int) $x6c_tickets[1]->ticket_id, 'reason' => 'Fehlscan' ) );
$x6c_undone = ContactResolver::resolve( $x6c_tickets[1]->public_id, $x6c_ref( $x6c_tickets[1] ) );
check( 'A668: after the admission was undone no contact is given', is_wp_error( $x6c_undone ) && 'not_completed' === $x6c_undone->get_error_data()['reason'] );
$wpdb->update( "{$p}wpcap_tickets", array( 'refunded' => 1 ), array( 'ticket_id' => (int) $x6c_tickets[0]->ticket_id ) );
$x6c_ref_r = ContactResolver::resolve( $x6c_tickets[0]->public_id, $x6c_ref( $x6c_tickets[0] ) );
check( 'A668: a refunded ticket is not eligible (refunded)', is_wp_error( $x6c_ref_r ) && 'refunded' === $x6c_ref_r->get_error_data()['reason'] );
$wpdb->update( "{$p}wpcap_tickets", array( 'refunded' => 0 ), array( 'ticket_id' => (int) $x6c_tickets[0]->ticket_id ) );

$x6c_new = array_slice( $x6c_audits(), $x6c_audit_before );
$x6c_results = array_map( static fn( $d ) => json_decode( $d, true )['result'] ?? '', $x6c_new );
check( 'A668: every call is audited with its result – never with the contact', 8 === count( $x6c_new ) && in_array( 'ok', $x6c_results, true ) && in_array( 'forbidden', $x6c_results, true ) && in_array( 'recipient_changed', $x6c_results, true ) && ! array_filter( $x6c_new, static fn( $d ) => false !== strpos( $d, '@' ) || false !== stripos( $d, 'Grete' ) ) );
check( 'A668: the review events themselves never contain the contact', 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . PassCaptain\Suite\Outbox::table() . " WHERE contract='review' AND (payload LIKE '%@%' OR payload LIKE '%Grete%')" ) );
$GLOBALS['wpcap_test_hooks'] = $x6c_hooks_before;

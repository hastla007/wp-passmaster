<?php
/**
 * A574: the partner API's "since" filter for tickets selects tickets changed since then (revocation,
 * refund, transfer, attendee data) via wpcap_tickets.updated_at (schema 3.10.18), and the scope
 * attendees:details adds attendee names and field answers (no consent fields). Included from run.php
 * (uses check(), make_type(), tickets(); WP_REST_Request from payments.php).
 */
// phpcs:ignoreFile
use PassCaptain\Orders;
use PassCaptain\Partners\Api as PartnerApi;
use PassCaptain\Partners\Keys as PartnerKeys;
use PassCaptain\Partners\Repository as Partners;
use PassCaptain\Reports\Scope;

echo "A574: partner API ticket changes and attendee details\n";
global $wpdb;
$p = $wpdb->prefix;
if ( ! function_exists( 'register_rest_route' ) ) { function register_rest_route( $ns, $route, $args ) { $GLOBALS['wpcap_test_routes'][ $ns . $route ] = $args; return true; } }
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_transients'] = array();
$_SERVER['REMOTE_ADDR'] = '203.0.113.74';
PassCaptain\ServiceBookings::flush_schema_cache();

$a574_call = static function ( $token, array $params ) {
	$req = new WP_REST_Request( 'GET' );
	$req->set_header( 'Authorization', 'Bearer ' . $token );
	foreach ( $params as $k => $v ) { $req->set_param( $k, $v ); }
	$auth = call_user_func( PartnerApi::permission( 'tickets:read' ), $req );
	if ( true !== $auth ) { return $auth; }
	$res = PartnerApi::tickets( $req );
	return $res instanceof WP_REST_Response ? $res->get_data() : $res;
};

check( 'A574: schema 3.10.18 column wpcap_tickets.updated_at exists', PassCaptain\ServiceBookings::has_column( 'wpcap_tickets', 'updated_at' ) && version_compare( PassCaptain\Installer::DB_VERSION, '3.10.18', '>=' ) );

$a574_type = make_type( 20, 10, 0 );
$a574_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a574_type" );
$a574_event = Scope::occurrence_event( $a574_occ );
$a574_partner = Partners::save( array( 'name' => 'A574 Veranstalter', 'currency' => 'EUR', 'settlement_start' => gmdate( 'Y-m-01' ) ) );
Partners::set_events( $a574_partner, array( $a574_event ) );
$a574_key = PartnerKeys::issue( $a574_partner, array( 'tickets:read', 'attendees:details' ) );
$a574_plain = PartnerKeys::issue( $a574_partner, array( 'tickets:read' ) );
check( 'A574 fixture: keys issued, attendees:details is a known scope', is_array( $a574_key ) && in_array( 'attendees:details', $a574_key['scopes'], true ) && isset( PartnerKeys::scope_labels()['attendees:details'] ) );

$a574_o = Orders::create( array( 'email' => 'a574@example.com', 'items' => array( array( 'ticket_type_id' => $a574_type, 'quantity' => 3 ) ), 'idempotency_key' => 'a574-' . uniqid() ) );
$a574_t = tickets( $a574_o['order_id'] );
// Issued an hour ago, unchanged since.
$a574_hour = time() - 3600;
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_tickets SET issued_at=%s,updated_at=FROM_UNIXTIME(%d) WHERE order_id=%d", gmdate( 'Y-m-d H:i:s', $a574_hour ), $a574_hour, $a574_o['order_id'] ) );
$a574_since = gmdate( 'Y-m-d\TH:i:s\Z', time() - 600 );
$a574_r0 = $a574_call( $a574_key['key'], array( 'event_id' => $a574_event, 'since' => $a574_since ) );
check( 'A574: nothing changed in the last 10 minutes → empty "since" page', is_array( $a574_r0 ) && 0 === $a574_r0['total'] );

// Revocation, refund flag and attendee correction after the issue time.
PassCaptain\Admin\TicketsPage::revoke_ticket( array( 'ticket_id' => (int) $a574_t[0]->ticket_id, 'reason' => 'A574' ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_tickets SET refunded=1 WHERE ticket_id=%d", (int) $a574_t[1]->ticket_id ) );
$a574_corr = PassCaptain\Admin\TicketsPage::correct_attendee( array( 'ticket_id' => (int) $a574_t[2]->ticket_id, 'attendee_name' => 'Erika Muster', 'attendee_email' => '', 'reason' => 'Name nachgetragen' ) );
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $a574_type, 'field_key' => 'company', 'label' => 'Firma', 'field_type' => 'text', 'required' => 0, 'status' => 'active' ) );
$a574_f1 = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $a574_type, 'field_key' => 'newsletter', 'label' => 'Newsletter', 'field_type' => 'checkbox', 'required' => 0, 'consent' => 1, 'status' => 'active' ) );
$a574_f2 = (int) $wpdb->insert_id;
$a574_now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( "{$p}wpcap_participant_answers", array( 'ticket_id' => (int) $a574_t[2]->ticket_id, 'field_id' => $a574_f1, 'answer' => 'Muster AG', 'created_at' => $a574_now, 'updated_at' => $a574_now ) );
$wpdb->insert( "{$p}wpcap_participant_answers", array( 'ticket_id' => (int) $a574_t[2]->ticket_id, 'field_id' => $a574_f2, 'answer' => '1', 'created_at' => $a574_now, 'updated_at' => $a574_now ) );

$a574_r1 = $a574_call( $a574_key['key'], array( 'event_id' => $a574_event, 'since' => $a574_since ) );
$a574_by = is_array( $a574_r1 ) ? array_column( $a574_r1['tickets'], null, 'id' ) : array();
check( 'A574: "since" returns revoked, refunded and corrected tickets', is_array( $a574_r1 ) && 3 === $a574_r1['total'] && 'revoked' === ( $a574_by[ $a574_t[0]->public_id ]['status'] ?? '' ) && true === ( $a574_by[ $a574_t[1]->public_id ]['refunded'] ?? null ) && isset( $a574_by[ $a574_t[2]->public_id ] ) && ! is_wp_error( $a574_corr ) );
$a574_row = $a574_by[ $a574_t[2]->public_id ] ?? array();
check( 'A574: each ticket carries updated_at (ISO 8601, after "since")', isset( $a574_row['updated_at'] ) && strtotime( $a574_row['updated_at'] ) >= strtotime( $a574_since ) );
check( 'A574: attendees:details adds the attendee name and answers, without consent fields', 'Erika Muster' === ( $a574_row['attendee_name'] ?? null ) && array( array( 'key' => 'company', 'label' => 'Firma', 'answer' => 'Muster AG' ) ) === ( $a574_row['answers'] ?? null ) && array_key_exists( 'attendee_name', $a574_by[ $a574_t[0]->public_id ] ?? array() ) && null === $a574_by[ $a574_t[0]->public_id ]['attendee_name'] );
$a574_r2 = $a574_call( $a574_plain['key'], array( 'event_id' => $a574_event, 'since' => $a574_since ) );
check( 'A574: without attendees:details no names or answers', is_array( $a574_r2 ) && 3 === $a574_r2['total'] && ! array_key_exists( 'attendee_name', $a574_r2['tickets'][0] ) && ! array_key_exists( 'answers', $a574_r2['tickets'][0] ) );

// Upgrade backfill: existing tickets start at their issue time, not at the upgrade time.
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_tickets SET updated_at=CURRENT_TIMESTAMP WHERE order_id=%d", $a574_o['order_id'] ) );
PassCaptain\Installer::backfill_ticket_updated_at( '3.10.17' );
$a574_after = (int) $wpdb->get_var( $wpdb->prepare( "SELECT UNIX_TIMESTAMP(updated_at) FROM {$p}wpcap_tickets WHERE ticket_id=%d", (int) $a574_t[2]->ticket_id ) );
check( 'A574: upgrade sets updated_at of existing tickets to their issue time (UTC)', abs( $a574_after - $a574_hour ) <= 1 && false === PassCaptain\Installer::backfill_ticket_updated_at( '3.10.18' ) );

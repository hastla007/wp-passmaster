<?php
/**
 * A709: public, versioned read function ticket → order, event, occurrence without personal data
 * (wpcap_get_ticket(), PassCaptain\Api\Tickets, contract ticket_read 1.0.0).
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

require_once WPCAP_PATH . 'src/functions.php';
echo "A709: ticket read model for suite plugins\n";
global $wpdb;
$p = $wpdb->prefix;
$a709_row = $wpdb->get_row( "SELECT t.ticket_id,t.public_id,t.order_id FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id ORDER BY t.ticket_id DESC LIMIT 1" );
check( 'A709: fixture ticket exists', (bool) $a709_row );
if ( $a709_row ) {
	$a709_by_id = wpcap_get_ticket( (int) $a709_row->ticket_id );
	$a709_by_public = wpcap_get_ticket( (string) $a709_row->public_id );
	check( 'A709: lookup by id and by public id give the same read model', is_array( $a709_by_id ) && $a709_by_id === $a709_by_public );
	check( 'A709: contract version and the four parts', '1.0.0' === ( $a709_by_id['contract_version'] ?? '' ) && array( 'contract_version', 'ticket', 'order', 'event', 'occurrence' ) === array_keys( $a709_by_id ) );
	check( 'A709: ticket → order, event and occurrence ids', (int) $a709_row->order_id === $a709_by_id['order']['id'] && (string) $a709_row->public_id === $a709_by_id['ticket']['public_id'] && $a709_by_id['event']['id'] > 0 && $a709_by_id['occurrence']['id'] > 0 && is_bool( $a709_by_id['ticket']['checked_in'] ) && is_bool( $a709_by_id['ticket']['refunded'] ) );
	$a709_json = (string) wp_json_encode( $a709_by_id );
	$a709_secret = $wpdb->get_row( $wpdb->prepare( "SELECT t.token_hash,t.attendee_email,o.customer_email FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_orders o ON o.order_id=t.order_id WHERE t.ticket_id=%d", (int) $a709_row->ticket_id ) );
	check( 'A709: no personal data, codes or token hashes', false === strpos( $a709_json, '@' ) && ( '' === (string) $a709_secret->token_hash || false === strpos( $a709_json, (string) $a709_secret->token_hash ) ) && false === stripos( $a709_json, 'email' ) && false === stripos( $a709_json, 'token' ) );
	check( 'A709: dates are ISO 8601 UTC', null === $a709_by_id['occurrence']['starts_at'] || 1 === preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', (string) $a709_by_id['occurrence']['starts_at'] ) );
}
check( 'A709: unknown tickets give null', null === wpcap_get_ticket( 999999999 ) && null === wpcap_get_ticket( 'no-such-ticket' ) && null === wpcap_get_ticket( 0 ) );
check( 'A709: contract ticket_read is registered', '1.0.0' === \PassCaptain\Contracts::version( 'ticket_read' ) );

<?php
/**
 * A650: order lists of scoped staff show only orders whose events are all in their scope (no e-mail,
 * total or foreign event name of mixed orders), and a taken discount code is answered neutrally and
 * limited per person (no oracle for codes of other organisers).
 */
// phpcs:ignoreFile
use PassCaptain\Admin\Queries;
use PassCaptain\Campaigns;
use PassCaptain\Orders;

global $wpdb;
$p = $wpdb->prefix;
echo "A650: order list scope and code oracle\n";
$GLOBALS['wpcap_test_transients'] = array();
$x1_own_type = make_type( 10, 10, 0 );
$x1_foreign_type = make_type( 10, 10, 0 );
$x1_event_of = static function ( $type ) use ( $wpdb, $p ) { return (int) $wpdb->get_var( $wpdb->prepare( "SELECT o.event_id FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=%d", $type ) ); };
$x1_own_event = $x1_event_of( $x1_own_type );
$wpdb->update( "{$p}wpcap_events", array( 'name' => 'X1 Fremdes Event' ), array( 'event_id' => $x1_event_of( $x1_foreign_type ) ) );
$x1_only = Orders::create( array( 'email' => 'x1-a650-only@example.com', 'items' => array( array( 'ticket_type_id' => $x1_own_type, 'quantity' => 1 ) ), 'idempotency_key' => 'x1-a650-only-' . uniqid() ) );
$x1_mixed = Orders::create( array( 'email' => 'x1-a650-mixed@example.com', 'items' => array( array( 'ticket_type_id' => $x1_own_type, 'quantity' => 1 ), array( 'ticket_type_id' => $x1_foreign_type, 'quantity' => 1 ) ), 'idempotency_key' => 'x1-a650-mixed-' . uniqid() ) );
$x1_list = Queries::search_orders( array( 'search' => 'x1-a650' ), array( $x1_own_event ), 1, 50 );
$x1_ids = array_map( static fn( $r ) => (int) $r->order_id, $x1_list['rows'] );
check( 'A650: an order entirely in scope is listed', in_array( (int) $x1_only['order_id'], $x1_ids, true ) );
check( 'A650: an order with tickets of a foreign event is not listed (no e-mail, total, foreign event name)', ! in_array( (int) $x1_mixed['order_id'], $x1_ids, true ) && 1 === (int) $x1_list['total'] && ! array_filter( $x1_list['rows'], static fn( $r ) => false !== strpos( (string) $r->event_names, 'X1 Fremdes Event' ) ) );
$x1_all = Queries::search_orders( array( 'search' => 'x1-a650' ), null, 1, 50 );
check( 'A650: without scope (administrators) both orders are listed', 2 === (int) $x1_all['total'] );
check( 'A650: the scope matches can_access_order() (all events) and is shared by the refund and review lists', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Admin/Queries.php' ), "return self::order_scope_sql( \$event_ids, 'r.order_id' );" ) );

// Code oracle.
$x1_code = 'X1A650' . strtoupper( substr( uniqid(), -4 ) );
Campaigns::create( array( 'code' => $x1_code, 'kind' => 'percent', 'value' => 1000, 'currency' => 'EUR', 'event_id' => $x1_event_of( $x1_foreign_type ) ) );
$GLOBALS['wpcap_test_user_id'] = 650;
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$x1_taken = Campaigns::create( array( 'code' => $x1_code, 'kind' => 'percent', 'value' => 500, 'currency' => 'EUR', 'event_id' => $x1_own_event ) );
check( 'A650: a taken code is refused neutrally (400 wpcap_campaign_code_unavailable, no 409 conflict)', is_wp_error( $x1_taken ) && 'wpcap_campaign_code_unavailable' === $x1_taken->get_error_code() && 400 === $x1_taken->get_error_data()['status'] && false === stripos( $x1_taken->get_error_message(), 'existiert' ) );
$x1_last = null;
for ( $i = 0; $i < Campaigns::CODE_CONFLICT_LIMIT + 1; $i++ ) { $x1_last = Campaigns::create( array( 'code' => $x1_code, 'kind' => 'percent', 'value' => 500, 'currency' => 'EUR', 'event_id' => $x1_own_event ) ); }
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 1;
check( 'A650: trying taken codes is limited per person (429) and logged', is_wp_error( $x1_last ) && 429 === $x1_last->get_error_data()['status'] && (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='campaign_code_conflict' AND object_id=650" ) >= Campaigns::CODE_CONFLICT_LIMIT );
$GLOBALS['wpcap_test_transients'] = array();

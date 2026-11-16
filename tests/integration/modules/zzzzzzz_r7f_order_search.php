<?php
/**
 * R7 (audit round 2, expert #9): order search by attendee name, billing name/company and ticket id
 * prefix; order CSV export with the buyer-facing order number (Format::order_reference()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\OrdersPage as R7fOrdersPage;
use PassCaptain\Admin\Queries as R7fQueries;
use PassCaptain\Orders as R7fOrders;

global $wpdb;
$p = $wpdb->prefix;
wpcap_test_reset_transients();

echo "R7 order search: names, billing, ticket id; CSV export\n";
$r7f_type = make_type( 20, 10, 0 );
$r7f_sfx = strtolower( substr( md5( microtime() ), 0, 6 ) );
$r7f = R7fOrders::create( array( 'email' => 'r7f-' . $r7f_sfx . '@example.com', 'items' => array( array( 'ticket_type_id' => $r7f_type, 'quantity' => 1 ) ), 'attendees' => array( array( 'ticket_type_id' => $r7f_type, 'name' => 'Zoë Ränkelmann' . $r7f_sfx ) ), 'billing' => array( 'enabled' => true, 'company' => 'Kühne Bühnen ' . $r7f_sfx, 'first_name' => 'Jürgen', 'last_name' => 'Öztürk' . $r7f_sfx, 'street' => 'Weg 1', 'postcode' => '1010', 'city' => 'Wien', 'country' => 'AT' ), 'idempotency_key' => 'r7f-' . $r7f_sfx ), array( 'skip_rate_limit' => true ) );
$r7f_id = is_wp_error( $r7f ) ? 0 : (int) $r7f['order_id'];
$r7f_found = static function ( $search ) use ( $r7f_id ) {
	$res = R7fQueries::search_orders( array( 'search' => $search ), null, 1, 50 );
	return in_array( $r7f_id, array_map( static fn( $r ) => (int) $r->order_id, $res['rows'] ), true );
};
check( 'test order created', $r7f_id > 0 );
check( 'search by attendee name prefix (umlauts)', $r7f_found( 'Zoë Ränkelmann' . $r7f_sfx ) && $r7f_found( 'zoë ränkel' ) );
check( 'search by billing last name and company prefix', $r7f_found( 'Öztürk' . $r7f_sfx ) && $r7f_found( 'Kühne Bühnen ' . $r7f_sfx ) );
$r7f_ticket = $r7f_id ? R7fOrders::tickets_for_order( $r7f_id )[0]->public_id : '';
check( 'search by ticket id prefix', '' !== $r7f_ticket && $r7f_found( substr( $r7f_ticket, 0, 10 ) ) );
check( 'search by short order number keeps working', $r7f_found( PassCaptain\Frontend\Format::order_reference( R7fOrders::get( $r7f_id )->public_id ) ) );
check( 'two characters do not search names (no accidental full scans)', ! $r7f_found( 'Zo' ) );
$r7f_rows = R7fOrdersPage::csv_rows( array( 'search' => 'r7f-' . $r7f_sfx . '@example.com' ) );
check( 'CSV: header + order row with the buyer-facing number first', 2 === count( $r7f_rows ) && PassCaptain\Frontend\Format::order_reference( R7fOrders::get( $r7f_id )->public_id ) === $r7f_rows[1][0] && 'r7f-' . $r7f_sfx . '@example.com' === $r7f_rows[1][6] && '0.00' === $r7f_rows[1][7] );
check( 'order list and detail use Format::order_reference() as the primary number', 3 <= substr_count( (string) file_get_contents( WPCAP_PATH . 'src/Admin/OrdersPage.php' ), 'Format::order_reference(' ) );

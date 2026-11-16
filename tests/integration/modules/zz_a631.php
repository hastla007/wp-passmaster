<?php
/**
 * A631: "send me my order link" on the ticket page (anyone with the ticket code, e.g. a photographed
 * QR code) no longer invalidates the buyer's current order link: the new link is pending until it is
 * opened for the first time, then it replaces the old one. Rotations by the buyer/staff still end
 * the old link at once; anonymisation drops a pending link.
 */
// phpcs:ignoreFile
use PassCaptain\Orders;
use PassCaptain\PortalSelfService;

global $wpdb;
$p = $wpdb->prefix;
echo "A631: order link request keeps the current link\n";
$GLOBALS['wpcap_test_transients'] = array();
$x1_type = make_type( 10, 10, 0 );
$x1_o = Orders::create( array( 'email' => 'x1-a631@example.com', 'items' => array( array( 'ticket_type_id' => $x1_type, 'quantity' => 1 ) ), 'idempotency_key' => 'x1-a631-' . uniqid() ) );
$x1_oid = (int) $x1_o['order_id'];
$x1_code = bin2hex( random_bytes( 32 ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_tickets SET token_hash=%s WHERE order_id=%d", hash( 'sha256', $x1_code ), $x1_oid ) );
$x1_old = bin2hex( random_bytes( 32 ) );
$wpdb->update( "{$p}wpcap_orders", array( 'access_token_hash' => hash( 'sha256', $x1_old ), 'access_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ) ), array( 'order_id' => $x1_oid ) );
$x1_row = static function () use ( $wpdb, $p, $x1_oid ) { return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_orders WHERE order_id=%d", $x1_oid ) ); };
$x1_new_token = static function ( $before ) {
	$mails = array_slice( $GLOBALS['wpcap_test_mails'], $before );
	foreach ( array_reverse( $mails ) as $m ) { if ( preg_match( '/wpcap_access=([a-f0-9]{64})/', (string) $m['body'], $mm ) ) { return $mm[1]; } }
	return '';
};

$x1_before = count( $GLOBALS['wpcap_test_mails'] );
$x1_sent = PortalSelfService::send_order_link( $x1_code );
$x1_new = $x1_new_token( $x1_before );
check( 'A631: the ticket page sends a new link; the current link still opens the portal', ! is_wp_error( $x1_sent ) && 64 === strlen( $x1_new ) && Orders::access_matches( $x1_row(), $x1_old ) );
check( 'A631: the new link is stored as pending, not as the current one', hash( 'sha256', $x1_new ) === (string) $x1_row()->access_pending_hash && hash( 'sha256', $x1_old ) === (string) $x1_row()->access_token_hash );
check( 'A631: opening the new link works and makes it the current one', Orders::access_matches( $x1_row(), $x1_new ) && hash( 'sha256', $x1_new ) === (string) $x1_row()->access_token_hash && '' === (string) $x1_row()->access_pending_hash );
check( 'A631: after that the old link no longer opens the portal', ! Orders::access_matches( $x1_row(), $x1_old ) && Orders::access_matches( $x1_row(), $x1_new ) );

// Expired pending link: ignored, current link unchanged.
$x1_before = count( $GLOBALS['wpcap_test_mails'] );
PortalSelfService::send_order_link( $x1_code );
$x1_late = $x1_new_token( $x1_before );
$wpdb->update( "{$p}wpcap_orders", array( 'access_pending_expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'order_id' => $x1_oid ) );
check( 'A631: an expired pending link does not open the portal and changes nothing', '' !== $x1_late && ! Orders::access_matches( $x1_row(), $x1_late ) && Orders::access_matches( $x1_row(), $x1_new ) );

// REST and portal checks go through the same rule.
$x1_before = count( $GLOBALS['wpcap_test_mails'] );
$GLOBALS['wpcap_test_transients'] = array();
PortalSelfService::send_order_link( $x1_code );
$x1_rest_token = $x1_new_token( $x1_before );
$req = new WP_REST_Request( 'GET', '/pass-captain/v1/orders/' . $x1_row()->public_id );
$req['public_id'] = $x1_row()->public_id;
$req->set_header( 'X-WPCAP-Access-Token', $x1_rest_token );
$x1_rest = PassCaptain\Rest\Controller::order_status( $req );
check( 'A631: GET /orders/{id} accepts the pending link (and activates it)', $x1_rest instanceof WP_REST_Response && ! isset( $x1_rest->get_data()['order_id'] ) && hash( 'sha256', $x1_rest_token ) === (string) $x1_row()->access_token_hash );

// A rotation by the buyer/staff (no keep_current) still ends the old link at once and drops a pending one.
PortalSelfService::send_order_link( $x1_code );
Orders::rotate_access_link( $x1_oid );
check( 'A631: a normal rotation ends the current link immediately and clears a pending link', ! Orders::access_matches( $x1_row(), $x1_rest_token ) && '' === (string) $x1_row()->access_pending_hash );
$wpdb->update( "{$p}wpcap_orders", array( 'access_pending_hash' => hash( 'sha256', 'x' ), 'access_pending_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ), array( 'order_id' => $x1_oid ) );
Orders::clear_pending_link( $x1_oid );
check( 'A631: clear_pending_link() (anonymisation) drops a pending link', '' === (string) $x1_row()->access_pending_hash && null === $x1_row()->access_pending_expires_at );
$x1_src = (string) file_get_contents( WPCAP_PATH . 'src/Privacy.php' ) . (string) file_get_contents( WPCAP_PATH . 'src/Privacy/Retention.php' );
// Merged: every anonymisation that replaces the access token (eraser + each retention path) also clears the pending link.
check( 'A631: the eraser and the retention anonymisation clear pending links', substr_count( $x1_src, 'Orders::clear_pending_link(' ) >= 3 && substr_count( $x1_src, 'Orders::clear_pending_link(' ) === substr_count( $x1_src, "'access_token_hash' =>" ) );
$GLOBALS['wpcap_test_transients'] = array();

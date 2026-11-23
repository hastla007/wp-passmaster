<?php
/**
 * A498: length/type contracts between public inputs and the schema, tested against WordPress'
 * behaviour instead of simplified stubs:
 * - the test runtime uses WordPress' own sanitisers (tests/integration/wp-sanitize.php); with a WordPress
 *   download they are compared with the real functions (tests/integration/sanitize-parity.php),
 * - Test_WPDB refuses insert/update values that are invalid UTF-8 or longer than their column, like
 *   wpdb::process_fields(), and statements with invalid UTF-8 like wpdb::query() – on every SQL mode,
 * - a fuzz/boundary run over every public entrance (checkout, transfer, assignment, waitlist, service
 *   booking, cancellation request, billing data, campaign preview) with type confusion, overlong
 *   values, invalid UTF-8, 4-byte characters at the 190 boundary, lone "<", entities and percent
 *   octets: no PHP error, no 5xx, and no value that the schema cannot hold reaches the database.
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Http;

global $wpdb;
$p = $wpdb->prefix;

echo "A498: sanitisers of the test runtime are WordPress' own\n";
$a498_wp = getenv( 'WPCAP_TEST_WP_DIR' ) ?: dirname( __DIR__, 3 ) . '/tests/upgrade/.wordpress';
if ( is_readable( $a498_wp . '/wp-includes/formatting.php' ) ) {
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/sanitize-parity.php' ) . ' 2>&1', $a498_out, $a498_code );
	check( 'A498: wp-sanitize.php equals the real WordPress functions on the fuzz corpus (sanitize-parity.php)' . ( $a498_code ? "\n" . implode( "\n", array_slice( $a498_out, 0, 20 ) ) : '' ), 0 === $a498_code );
} else {
	echo "  skip parity with a real WordPress (no download at $a498_wp; CI runs tests/integration/sanitize-parity.php after tests/upgrade)\n";
}
check( 'A498: WordPress semantics: lone "<" is stored as &lt;, invalid UTF-8 and percent octets are dropped, sanitize_email() of "kein-mail" is ""', 'Kids &lt;3 Party' === sanitize_text_field( 'Kids <3 Party' ) && '' === sanitize_text_field( "Caf\xC3" ) && 'Rabatt' === sanitize_text_field( '%41Rabatt' ) && '' === sanitize_email( 'kein-mail' ) && false === is_email( 'kein-mail' ) );
$a498_type_error = false;
try { sanitize_email( array( 'a@example.com' ) ); } catch ( TypeError $e ) { $a498_type_error = true; }
check( 'A498: sanitize_email() of an array is a TypeError like on PHP 8', $a498_type_error );
check( 'A498: Http::optional_email(): "" for nothing, false for entered-but-invalid ("kein-mail", too long), the address otherwise', '' === Http::optional_email( '  ' ) && false === Http::optional_email( 'kein-mail' ) && false === Http::optional_email( str_repeat( 'a', 60 ) . '@' . str_repeat( 'b', 60 ) . '.' . str_repeat( 'c', 60 ) . '.example.com' ) && 'a@example.com' === Http::optional_email( ' a@example.com ' ) && '' === Http::optional_email( array( 'x' ) ) );

echo "A498: the test database refuses what wpdb refuses\n";
$a498_now = gmdate( 'Y-m-d H:i:s' );
$a498_ok = $wpdb->insert( "{$p}wpcap_events", array( 'name' => str_repeat( 'Ä', 190 ), 'slug' => 'a498-' . uniqid(), 'description' => '', 'status' => 'draft', 'created_at' => $a498_now, 'updated_at' => $a498_now ) );
$a498_long = $wpdb->insert( "{$p}wpcap_events", array( 'name' => str_repeat( 'Ä', 191 ), 'slug' => 'a498-' . uniqid(), 'description' => '', 'status' => 'draft', 'created_at' => $a498_now, 'updated_at' => $a498_now ) );
$a498_long_error = $wpdb->last_error;
$a498_bad = $wpdb->insert( "{$p}wpcap_events", array( 'name' => "Caf\xC3", 'slug' => 'a498-' . uniqid(), 'description' => '', 'status' => 'draft', 'created_at' => $a498_now, 'updated_at' => $a498_now ) );
check( 'A498: insert(): 190 characters fit varchar(190), 191 or invalid UTF-8 fail with wpdb\'s message', 1 === $a498_ok && false === $a498_long && false === $a498_bad && false !== strpos( $a498_long_error, 'Processing the value for the following field failed: name' ) );
$a498_id = (int) $wpdb->get_var( "SELECT MAX(event_id) FROM {$p}wpcap_events" );
check( 'A498: update() checks the same way; a raw statement with invalid UTF-8 is not sent', false === $wpdb->update( "{$p}wpcap_events", array( 'name' => str_repeat( 'x', 191 ) ), array( 'event_id' => $a498_id ) ) && false === $wpdb->query( "UPDATE {$p}wpcap_events SET name='Caf\xC3' WHERE event_id=" . $a498_id ) && false !== strpos( $wpdb->last_error, 'invalid data' ) && 190 === mb_strlen( (string) $wpdb->get_var( "SELECT name FROM {$p}wpcap_events WHERE event_id=" . $a498_id ) ) );

echo "A498: fuzz and boundary values on every public entrance\n";
/** Values every text field gets. */
function a498_values() {
	$emoji189 = str_repeat( 'a', 189 ) . '😀';
	return array(
		'array' => array( 'x' => array( 'y' ) ), 'list' => array( 'a@example.com' ), 'int' => 12, 'negative' => -3, 'float' => 1.5, 'true' => true, 'false' => false, 'null' => null,
		'empty' => '', 'blank' => "  \t ", 'lt' => 'Kids <3 Party', 'tag' => '<b>x</b><script>y</script>', 'entity' => '&lt;&amp;&#61;', 'percent' => '%41%zz',
		'invalid_utf8' => "Caf\xC3 \xFF", 'emoji_190' => $emoji189, 'chars_191' => str_repeat( 'ä', 191 ), 'chars_300' => str_repeat( 'Ж', 300 ), 'bytes_70000' => str_repeat( 'é', 35000 ),
		'email_191' => str_repeat( 'a', 60 ) . '@' . str_repeat( 'b', 60 ) . '.' . str_repeat( 'c', 60 ) . '.exampl.com', 'email_ok' => 'fuzz@example.com', 'no_email' => 'kein-mail',
	);
}
/** Runs one entrance call: [ok, why] – ok when no Throwable/warning, no 5xx and no field refused by the test database. */
function a498_probe( callable $fn ) {
	$issues = array();
	$GLOBALS['wpcap_test_field_errors'] = array();
	$GLOBALS['wpcap_test_transients'] = array();
	set_error_handler( static function ( $no, $str, $file, $line ) use ( &$issues ) { $issues[] = $str . ' @' . basename( $file ) . ':' . $line; return true; } );
	ob_start();
	try {
		$r = $fn();
	} catch ( \Throwable $e ) {
		$r = null;
		$issues[] = get_class( $e ) . ': ' . $e->getMessage() . ' @' . basename( $e->getFile() ) . ':' . $e->getLine();
	}
	ob_end_clean();
	restore_error_handler();
	if ( $r instanceof WP_REST_Response ) { $status = (int) $r->get_status(); }
	elseif ( is_wp_error( $r ) ) { $status = (int) ( ( (array) $r->get_error_data() )['status'] ?? 400 ); }
	else { $status = 200; }
	if ( $status >= 500 ) { $issues[] = 'status ' . $status . ( is_wp_error( $r ) ? ' ' . $r->get_error_code() : '' ); }
	foreach ( $GLOBALS['wpcap_test_field_errors'] as $field ) { $issues[] = 'schema: ' . $field; }
	unset( $GLOBALS['wpcap_test_field_errors'] );
	return $issues;
}
$a498_failures = array();
$a498_entrances = array();
$a498_record = static function ( $entrance, $label, array $issues ) use ( &$a498_failures, &$a498_entrances ) {
	$a498_entrances[ $entrance ] = true;
	if ( $issues ) { $a498_failures[ $entrance ][] = $label . ': ' . implode( '; ', array_unique( $issues ) ); }
};

$a498_settings = get_option( PassCaptain\Settings::OPTION, array() );
PassCaptain\Settings::update( array( 'refund_approval_threshold' => 0, 'portal_refund_request_hours' => 1 ) );
$a498_free = make_type( 500, 10, 0 );
$a498_paid = make_type( 500, 10, 1500 );
$a498_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=" . (int) $a498_free );
$a498_event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=" . $a498_occ );
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $a498_free, 'field_key' => 'firma', 'label' => 'Firma', 'field_type' => 'text', 'required' => 0, 'status' => 'active' ) );
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $a498_free, 'field_key' => 'mail2', 'label' => 'Zweite E-Mail', 'field_type' => 'email', 'required' => 0, 'status' => 'active' ) );
$a498_access = static function ( $type ) {
	global $wpdb;
	$key = 'a498-' . bin2hex( random_bytes( 6 ) );
	$o = Orders::create( array( 'email' => $key . '@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => $key ) );
	if ( 'pending' === $o['payment_status'] ) { Orders::confirm_manual_payment( $o['order_id'] ); }
	$token = bin2hex( random_bytes( 32 ) );
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_orders SET access_token_hash=%s,access_expires_at=%s WHERE order_id=%d", hash( 'sha256', $token ), gmdate( 'Y-m-d H:i:s', time() + 86400 ), $o['order_id'] ) );
	return array( Orders::get( $o['order_id'] ), $token, (string) $wpdb->get_var( $wpdb->prepare( "SELECT public_id FROM {$wpdb->prefix}wpcap_tickets WHERE order_id=%d", $o['order_id'] ) ) );
};
$a498_rest = static function ( $method, array $params, $body = null ) {
	$req = new WP_REST_Request( 'POST' );
	foreach ( $params as $k => $v ) { $req->set_param( $k, $v ); }
	if ( null !== $body ) { $req->set_body( (string) json_encode( $body, JSON_INVALID_UTF8_IGNORE ) ); }
	return PassCaptain\Rest\Controller::$method( $req );
};

foreach ( a498_values() as $label => $v ) {
	$key = 'a498-' . $label . '-' . bin2hex( random_bytes( 4 ) );
	// Checkout (core and REST): buyer, attendees (name, e-mail, answers), key, locale, codes.
	$a498_record( 'checkout email', $label, a498_probe( static fn() => Orders::create( array( 'email' => $v, 'items' => array( array( 'ticket_type_id' => $a498_free, 'quantity' => 1 ) ), 'idempotency_key' => $key . 'e' ) ) ) );
	$a498_record( 'checkout attendee', $label, a498_probe( static fn() => Orders::create( array( 'email' => 'fuzz@example.com', 'items' => array( array( 'ticket_type_id' => $a498_free, 'quantity' => 1 ) ), 'idempotency_key' => $key . 'a', 'attendees' => array( array( 'ticket_type_id' => $a498_free, 'name' => $v, 'email' => $v, 'answers' => array( 'firma' => $v, 'mail2' => $v ) ) ) ) ) ) );
	$a498_record( 'checkout key/locale/codes', $label, a498_probe( static fn() => Orders::create( array( 'email' => 'fuzz@example.com', 'items' => array( array( 'ticket_type_id' => $a498_free, 'quantity' => 1 ) ), 'idempotency_key' => $v, 'locale' => $v, 'campaign_codes' => array( $v ), 'voucher_code' => $v ) ) ) );
	$a498_record( 'REST checkout', $label, a498_probe( static fn() => $a498_rest( 'checkout', array(), array( 'email' => $v, 'items' => array( array( 'ticket_type_id' => $v, 'quantity' => $v ) ), 'provider' => $v, 'code' => $v, 'idempotency_key' => $key . 'r', 'attendees' => $v, 'billing' => $v ) ) ) );
	$a498_record( 'REST campaign preview', $label, a498_probe( static fn() => $a498_rest( 'preview_campaign', array(), array( 'code' => $v, 'items' => array( array( 'ticket_type_id' => $a498_paid, 'quantity' => 1 ) ) ) ) ) );
	// Order portal: transfer, assignment, cancellation request, billing data.
	list( $order, $token, $ticket ) = $a498_access( $a498_paid );
	$a498_record( 'transfer', $label, a498_probe( static fn() => Orders::transfer_ticket( $order->public_id, $token, $ticket, $v ) ) );
	$a498_record( 'transfer (ids)', $label, a498_probe( static fn() => Orders::transfer_ticket( $v, $v, $v, 'x@example.com' ) ) );
	$a498_record( 'assignment', $label, a498_probe( static fn() => PassCaptain\TicketAssignments::assign( $order->public_id, $token, $ticket, $v, $v, is_array( $v ) ? $v : null ) ) );
	$a498_record( 'cancellation request', $label, a498_probe( static fn() => PassCaptain\PortalSelfService::request_refund( $order->public_id, $token, array( 'ticket_public_ids' => array( $ticket ), 'idempotency_key' => 'a498-portal-' . bin2hex( random_bytes( 8 ) ), 'reason' => $v ) ) ) );
	$a498_record( 'cancellation request (key/ids)', $label, a498_probe( static fn() => PassCaptain\PortalSelfService::request_refund( $order->public_id, $token, array( 'ticket_public_ids' => array( $v, $ticket ), 'idempotency_key' => $v ) ) ) );
	$a498_record( 'billing data', $label, a498_probe( static fn() => PassCaptain\Billing::update_for_order( $order, array( 'enabled' => true, 'company' => $v, 'name' => $v, 'address' => $v, 'postcode' => $v, 'city' => $v, 'country' => $v, 'vat_id' => $v ) ) ) );
	// Waitlist and service booking.
	$a498_record( 'waitlist', $label, a498_probe( static fn() => PassCaptain\Waitlists::join( $a498_event, $a498_occ, $a498_paid, $v, $v ) ) );
	$a498_record( 'service booking', $label, a498_probe( static fn() => PassCaptain\ServiceBookings::reserve( 999999, $v, $v, 0, 10, $v ) ) );
}
$a498_summary = array();
foreach ( $a498_failures as $entrance => $lines ) { $a498_summary[] = $entrance . ' → ' . implode( ' | ', array_slice( $lines, 0, 3 ) ); }
check( 'A498: ' . count( a498_values() ) . ' fuzz values × ' . count( $a498_entrances ) . ' entrances: no PHP error, no 5xx, nothing longer than the schema reaches the database' . ( $a498_summary ? "\n       " . implode( "\n       ", $a498_summary ) : '' ), ! $a498_failures );
update_option( PassCaptain\Settings::OPTION, $a498_settings );

<?php
/**
 * A478: text is shortened by characters, never byte-wise. A byte-wise substr() split multi-byte
 * characters: audit details became empty and strict-mode writes failed (refund reason).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Text;
use PassCaptain\Refunds;
use PassCaptain\Orders;
use PassCaptain\Admin\AuditLogPage;

global $wpdb;
$p = $wpdb->prefix;

echo "A478: UTF-8 safe truncation\n";
$a478_long = str_repeat( 'ä', 300 );
$a478_cut = Text::cut( $a478_long, 255 );
check( 'A478: Text::cut keeps 255 characters of a 2-byte text (substr kept 127.5)', 255 === mb_strlen( $a478_cut, 'UTF-8' ) && mb_check_encoding( $a478_cut, 'UTF-8' ) );
check( 'A478: Text::cut repairs invalid UTF-8 (an earlier byte cut)', mb_check_encoding( Text::cut( substr( 'Grüße', 0, 3 ), 10 ), 'UTF-8' ) );
check( 'A478: Text::cut leaves short text unchanged', 'Grüße' === Text::cut( 'Grüße', 5 ) && '' === Text::cut( null, 5 ) );
check( 'A478: Text::ellipsis marks shortened text only', 'ää…' === Text::ellipsis( 'äää', 2 ) && 'äää' === Text::ellipsis( 'äää', 3 ) );

// Audit details on the audit page: 400 characters of JSON with umlauts stay readable.
$a478_details = AuditLogPage::details_text( wp_json_encode( array( 'reason' => str_repeat( 'ö', 500 ) ) ) );
check( 'A478: audit details are cut by characters and stay valid UTF-8', mb_check_encoding( $a478_details, 'UTF-8' ) && '' !== esc_html( $a478_details ) && 401 === mb_strlen( $a478_details, 'UTF-8' ) );

// A refund reason of 300 umlauts: the byte cut produced an invalid string the database rejected.
$wpdb->query( "SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'" );
$GLOBALS['wpcap_test_transients'] = array();
$a478_type = make_type( 5, 10, 2000 );
$a478_order = Orders::create( array( 'email' => 'a478@example.com', 'items' => array( array( 'ticket_type_id' => $a478_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a478-' . uniqid() ) );
if ( ! is_wp_error( $a478_order ) && 'paid' !== $a478_order['payment_status'] ) { Orders::confirm_external_payment( $a478_order['order_id'], 'test' ); }
$a478_order_id = is_wp_error( $a478_order ) ? 0 : (int) $a478_order['order_id'];
$a478_tickets = array_map( static fn( $t ) => (int) $t->ticket_id, tickets( $a478_order_id, 'valid' ) );
$a478_refund = Refunds::request( $a478_order_id, $a478_tickets, 'Kulanz' . $a478_long, 'a478-refund', array( 'system' => true, 'require_approval' => true ) );
$a478_row = is_array( $a478_refund ) ? $wpdb->get_row( $wpdb->prepare( "SELECT reason FROM {$p}wpcap_refunds WHERE refund_id=%d", (int) $a478_refund['refund_id'] ) ) : null;
check( 'A478: a refund with a long umlaut reason is stored under strict SQL mode', $a478_row && 255 === mb_strlen( (string) $a478_row->reason, 'UTF-8' ) && 0 === strpos( (string) $a478_row->reason, 'Kulanzä' ) );
if ( is_array( $a478_refund ) ) { Refunds::reject( (int) $a478_refund['refund_id'], str_repeat( 'ü', 300 ) ); }
$a478_rejected = is_array( $a478_refund ) ? $wpdb->get_row( $wpdb->prepare( "SELECT status,error_message FROM {$p}wpcap_refunds WHERE refund_id=%d", (int) $a478_refund['refund_id'] ) ) : null;
check( 'A478: rejecting with a long umlaut note is stored too', $a478_rejected && 'rejected' === $a478_rejected->status && 255 === mb_strlen( (string) $a478_rejected->error_message, 'UTF-8' ) );
$wpdb->query( "SET SESSION sql_mode=DEFAULT" );

// CI lint: no byte-wise substr() on text left in src/.
$a478_out = array();
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__, 2 ) . '/utf8-substr-check.php' ) . ' --self-test 2>&1', $a478_out, $a478_rc );
check( 'A478: tests/utf8-substr-check.php passes (self-test and src/)' . ( $a478_rc ? ' – ' . implode( ' | ', array_slice( $a478_out, 0, 4 ) ) : '' ), 0 === $a478_rc );

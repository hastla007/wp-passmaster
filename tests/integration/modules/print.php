<?php
/**
 * Integration tests for the print module (src/Printing, src/Modules/PrintTemplates.php):
 * PDF layouts and placeholders, per-event templates, batch print, attendee list scoping, CSV injection,
 * invoice/credit note PDFs and the ticket mail attachment.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Printing\AttendeeList;
use PassCaptain\Printing\InvoicePdf;
use PassCaptain\Printing\Page as PrintPage;
use PassCaptain\Printing\PdfWriter;
use PassCaptain\Printing\Templates;
use PassCaptain\Reports\Scope;
use PassCaptain\Settings;

PassCaptain\Modules\PrintTemplates::register();
if ( is_readable( dirname( __DIR__, 3 ) . '/vendor/autoload.php' ) ) { require_once dirname( __DIR__, 3 ) . '/vendor/autoload.php'; }

global $wpdb;
$p = $wpdb->prefix;

if ( ! function_exists( 'wpp_pdf_ok' ) ) {
	/** Structural PDF check: header, EOF, xref offset and every object offset, page count. */
	function wpp_pdf_ok( $pdf, $pages = null ) {
		if ( ! is_string( $pdf ) || ! str_starts_with( $pdf, '%PDF-1.4' ) || ! preg_match( '/startxref\n(\d+)\n%%EOF$/', $pdf, $m ) ) { return false; }
		$xref = substr( $pdf, (int) $m[1] );
		if ( 0 !== strpos( $xref, 'xref' ) || ! preg_match_all( '/^(\d{10}) 00000 n $/m', $xref, $offsets ) ) { return false; }
		foreach ( $offsets[1] as $i => $offset ) {
			if ( 0 !== strpos( substr( $pdf, (int) $offset ), ( $i + 1 ) . ' 0 obj' ) ) { return false; }
		}
		if ( ! preg_match_all( '/<< \/Length (\d+) >>\nstream\n/', $pdf, $streams, PREG_OFFSET_CAPTURE ) ) { return false; }
		foreach ( $streams[1] as $i => $len ) {
			$start = $streams[0][ $i ][1] + strlen( $streams[0][ $i ][0] );
			if ( "\nendstream" !== substr( $pdf, $start + (int) $len[0], 10 ) ) { return false; }
		}
		return null === $pages || $pages === preg_match_all( '#/Type /Page /Parent#', $pdf );
	}
}

echo "Print: layouts and placeholders\n";
$item = array( 'event' => 'Gala (Wien)', 'date' => '24.12.2026 19:30', 'location' => 'Saal', 'ticket_category' => 'VIP', 'attendee_name' => 'Ada Lovelace', 'seat_label' => '', 'order' => 'abc', 'qr' => str_repeat( 'a', 64 ) );
check( 'placeholders are replaced, {qr} removed from text, separators trimmed', 'Ada Lovelace – VIP' === Templates::fill( '{attendee_name} – {ticket_category} {qr}', $item ) && '' === Templates::fill( '{seat_label}', $item ) && 'VIP' === Templates::fill( '{ticket_category} · {seat_label}', $item ) && '{unknown}' === Templates::fill( '{unknown}', $item ) );
$parsed = Templates::parse( "*18|{event}\n9|x\n{qr}\nplain" );
check( 'template lines parse size, bold and QR', 18.0 === $parsed[0]['size'] && $parsed[0]['bold'] && ! $parsed[1]['bold'] && $parsed[2]['qr'] && 12.0 === $parsed[3]['size'] );
$ticket_pdf = Templates::render( 'ticket', array( $item, $item, $item ) );
check( 'ticket layout: valid PDF, one A4 page per ticket, escaped text', wpp_pdf_ok( $ticket_pdf, 3 ) && false !== strpos( $ticket_pdf, '(Gala \\(Wien\\)) Tj' ) && false !== strpos( $ticket_pdf, '/MediaBox [0 0 595.28 841.89]' ) );
check( 'QR code is drawn when the encoder is installed', ! class_exists( '\\BaconQrCode\\Encoder\\Encoder' ) || substr_count( $ticket_pdf, 're f' ) > 600 );
$badge = Templates::render( 'badge', array( $item ) );
check( 'badge layout is A6', wpp_pdf_ok( $badge, 1 ) && false !== strpos( $badge, '/MediaBox [0 0 297.64 419.53]' ) && false !== strpos( $badge, '(Ada Lovelace) Tj' ) );
$tags = Templates::render( 'nametag', array_fill( 0, 12, $item ) );
check( 'name tags: 10 per A4 page (12 tags = 2 pages) with cut marks', wpp_pdf_ok( $tags, 2 ) && 12 === substr_count( $tags, ' re S' ) && 12 === substr_count( $tags, '(Ada Lovelace) Tj' ) );
$band = Templates::render( 'wristband', array( $item, $item ) );
check( 'wristband layout is 250 x 25 mm', wpp_pdf_ok( $band, 2 ) && false !== strpos( $band, '/MediaBox [0 0 708.66 70.87]' ) );
$long = Templates::render( 'nametag', array( array_merge( $item, array( 'attendee_name' => str_repeat( 'Wolfeschlegelsteinhausen ', 6 ) ) ) ) );
check( 'long names are shortened to fit the label', wpp_pdf_ok( $long, 1 ) && false !== strpos( $long, '...) Tj' ) );
check( 'umlauts are encoded for Helvetica (WinAnsi)', false !== strpos( PdfWriter::escape( 'Müller' ), "M\xFCller" ) );
check( 'an empty batch still yields a valid PDF', wpp_pdf_ok( Templates::render( 'badge', array() ), 1 ) );

echo "Print: per-event templates and batch print\n";
$pt = make_type( 10, 10, 0 );
$pocc = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$pt" );
$pevent = Scope::occurrence_event( $pocc );
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $pt, 'field_key' => 'firma', 'label' => 'Firma', 'field_type' => 'text', 'required' => 0, 'status' => 'active' ) );
$GLOBALS['wpcap_test_transients'] = array();
$porder = Orders::create( array( 'email' => 'buyer-print@example.com', 'items' => array( array( 'ticket_type_id' => $pt, 'quantity' => 3 ) ), 'idempotency_key' => 'wpp-' . uniqid(), 'attendees' => array(
	array( 'ticket_type_id' => $pt, 'name' => '=HYPERLINK("http://evil")', 'email' => 'ada@example.com', 'answers' => array( 'firma' => '+cmd|calc' ) ),
	array( 'ticket_type_id' => $pt, 'name' => 'Ada Lovelace', 'answers' => array( 'firma' => 'ACME' ) ),
) ) );
$ptickets = tickets( $porder['order_id'] );
$wpdb->query( "UPDATE {$p}wpcap_tickets SET status='checked_in' WHERE ticket_id=" . (int) $ptickets[2]->ticket_id );
$tid = Templates::save_template( 0, 'Konferenz-Badge', 'badge', "*18|Hallo {attendee_name}\n10|{seat_label}\n{qr}" );
check( 'invalid templates are rejected', is_wp_error( Templates::save_template( 0, '', 'badge', 'x' ) ) && is_wp_error( Templates::save_template( 0, 'X', 'poster', 'x' ) ) );
check( 'template of another layout cannot be assigned', is_wp_error( Templates::assign( $pevent, 'ticket', $tid ) ) );
Templates::assign( $pevent, 'badge', $tid );
add_filter( 'wpcap_ticket_seat_label', static function ( $label, $ticket_id ) use ( $ptickets ) { return (int) $ticket_id === (int) $ptickets[1]->ticket_id ? 'Reihe 3, Platz 7' : $label; }, 20, 2 );
$batch = PrintPage::batch_request( $pocc, 'badge', false );
check( 'batch print uses the event template incl. seat label for all non-revoked tickets', ! is_wp_error( $batch ) && wpp_pdf_ok( $batch['pdf'], 3 ) && false !== strpos( $batch['pdf'], '(Hallo Ada Lovelace) Tj' ) && false !== strpos( $batch['pdf'], '(Reihe 3, Platz 7) Tj' ) );
check( 'without new codes no QR code is printed and codes stay unchanged', ( ! class_exists( '\\BaconQrCode\\Encoder\\Encoder' ) || substr_count( $batch['pdf'], 're f' ) < 10 ) );
check( 'other events keep the default layout', Templates::default_body( 'badge' ) === Templates::body_for_event( $pevent + 999, 'badge' ) );
$hashes = $wpdb->get_col( "SELECT token_hash FROM {$p}wpcap_tickets WHERE order_id=" . (int) $porder['order_id'] . ' ORDER BY ticket_id' );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_orders' );
$no_codes = PrintPage::batch_request( $pocc, 'ticket', true );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_orders', 'wpcap_manage_events' );
$no_print = PrintPage::batch_request( $pocc, 'badge', false );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'printing needs event/order management; new codes need order management', is_wp_error( $no_codes ) && is_wp_error( $no_print ) && $hashes === $wpdb->get_col( "SELECT token_hash FROM {$p}wpcap_tickets WHERE order_id=" . (int) $porder['order_id'] . ' ORDER BY ticket_id' ) );
$with_codes = PrintPage::batch_request( $pocc, 'ticket', true );
$after = $wpdb->get_col( "SELECT token_hash FROM {$p}wpcap_tickets WHERE order_id=" . (int) $porder['order_id'] . ' ORDER BY ticket_id' );
check( 'tickets with QR: new codes for valid tickets only, checked-in tickets untouched', ! is_wp_error( $with_codes ) && wpp_pdf_ok( $with_codes['pdf'], 2 ) && $after[0] !== $hashes[0] && $after[1] !== $hashes[1] && $after[2] === $hashes[2] );

echo "Print: attendee lists\n";
$full = AttendeeList::rows( $pocc, 'full' );
$basic = AttendeeList::rows( $pocc, 'basic' );
$flat_basic = implode( '|', array_merge( ...$basic ) );
check( 'event managers get the full list incl. e-mail, order and answers', 'Firma' === end( $full[0] ) && 4 === count( $full ) && in_array( 'ada@example.com', array_column( $full, 4 ), true ) && in_array( 'ACME', array_column( $full, 7 ), true ) );
check( 'check-in staff only gets name, ticket type and status', 3 === count( $basic[0] ) && false === strpos( $flat_basic, '@' ) && false === strpos( $flat_basic, 'ACME' ) );
check( 'access level follows capability', 'full' === AttendeeList::access_level( $pocc ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_events' );
$lvl_basic = AttendeeList::access_level( $pocc );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_events', 'wpcap_checkin', 'wpcap_manage_orders' );
$lvl_none = AttendeeList::access_level( $pocc );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$lvl_unassigned = AttendeeList::access_level( $pocc );
$wpdb->insert( "{$p}wpcap_event_staff", array( 'event_id' => $pevent, 'user_id' => 1, 'capability' => 'wpcap_checkin' ) );
$lvl_staff = AttendeeList::access_level( $pocc );
$wpdb->query( "DELETE FROM {$p}wpcap_event_staff WHERE event_id=$pevent AND user_id=1" );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'attendee list is scoped per event and role', 'basic' === $lvl_basic && '' === $lvl_none && '' === $lvl_unassigned && 'basic' === $lvl_staff );
$csv = Scope::csv( $full );
check( 'CSV export neutralises formulas (CSV injection)', false !== strpos( $csv, "\"'=HYPERLINK(\"\"http://evil\"\")\"" ) && false !== strpos( $csv, "'+cmd|calc" ) && str_starts_with( $csv, "\xEF\xBB\xBF" ) );
check( 'CSV cells: formulas prefixed, plain numbers kept', "'@SUM(A1)" === Scope::csv_cell( '@SUM(A1)' ) && "'-1+2" === Scope::csv_cell( '-1+2' ) && '-12.50' === Scope::csv_cell( '-12.50' ) && "' =1" === Scope::csv_cell( ' =1' ) );
$html = AttendeeList::html( $pocc, 'basic' );
check( 'print view escapes names', false !== strpos( $html, '=HYPERLINK(&quot;http://evil&quot;)' ) && false === strpos( $html, 'ada@example.com' ) );

echo "Print: invoice PDF\n";
$it = make_type( 10, 10, 1190 );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET tax_rate_bp=1900 WHERE ticket_type_id=$it" );
Settings::update( array( 'seller_name' => 'Veranstalter GmbH', 'seller_address' => "Ring 1\n1010 Wien", 'seller_vat_id' => 'ATU12345678' ) );
$GLOBALS['wpcap_test_transients'] = array();
$mails_before = count( $GLOBALS['wpcap_test_mails'] );
$io = Orders::create( array( 'email' => 'rechnung@example.com', 'items' => array( array( 'ticket_type_id' => $it, 'quantity' => 2 ) ), 'idempotency_key' => 'wpp-inv-' . uniqid() ) );
$access_mail = array_values( array_filter( array_slice( $GLOBALS['wpcap_test_mails'], $mails_before ), static fn( $m ) => 'rechnung@example.com' === $m['to'] ) )[0] ?? array( 'body' => '' );
preg_match( '/wpcap_access=([a-f0-9]{64})/', $access_mail['body'], $tm );
Orders::confirm_manual_payment( $io['order_id'] );
$admin = InvoicePdf::admin_pdf( $io['order_id'] );
$number = $wpdb->get_var( "SELECT invoice_number FROM {$p}wpcap_invoices WHERE order_id=" . (int) $io['order_id'] );
check( 'admin download issues the invoice and returns a valid PDF with its content', ! is_wp_error( $admin ) && wpp_pdf_ok( $admin['pdf'], 1 ) && false !== strpos( $admin['pdf'], '(Rechnung ' . $number . ') Tj' ) && false !== strpos( $admin['pdf'], '(Umsatzsteuer 19,00 %) Tj' ) && false !== strpos( $admin['pdf'], '(23,80 EUR) Tj' ) && false !== strpos( $admin['pdf'], '(Veranstalter GmbH) Tj' ) && 'rechnung-' . $number . '.pdf' === $admin['filename'] );
$refund = PassCaptain\Refunds::request( $io['order_id'], array( (int) tickets( $io['order_id'] )[0]->ticket_id ), 'Test', 'wpp-cn' );
$cn = PassCaptain\Invoices::credit_note_for_refund( $refund['refund_id'] );
$with_cn = InvoicePdf::render( (int) $wpdb->get_var( "SELECT invoice_id FROM {$p}wpcap_invoices WHERE order_id=" . (int) $io['order_id'] ) );
$only_cn = InvoicePdf::admin_pdf( $io['order_id'], (int) $cn['credit_note_id'] );
check( 'credit notes get their own page; single credit note download', wpp_pdf_ok( $with_cn, 2 ) && false !== strpos( $with_cn, '(Gutschrift ' . $cn['credit_note_number'] . ') Tj' ) && ! is_wp_error( $only_cn ) && wpp_pdf_ok( $only_cn['pdf'], 1 ) && false !== strpos( $only_cn['pdf'], '(-11,90 EUR) Tj' ) && str_starts_with( $only_cn['filename'], 'gutschrift-' ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options', 'wpcap_view_finance', 'wpcap_manage_orders' );
$forbidden = InvoicePdf::admin_pdf( $io['order_id'] );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options', 'wpcap_view_finance' );
$order_manager = InvoicePdf::admin_pdf( $io['order_id'] );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'admin download requires finance or scoped order management', is_wp_error( $forbidden ) && 403 === $forbidden->get_error_data()['status'] && ! is_wp_error( $order_manager ) );
$public_id = Orders::get( $io['order_id'] )->public_id;
$customer = InvoicePdf::customer_pdf( $public_id, $tm[1] ?? '' );
$wrong = InvoicePdf::customer_pdf( $public_id, str_repeat( 'a', 64 ) );
$other = InvoicePdf::customer_pdf( $porder['public_id'], $tm[1] ?? '' );
check( 'buyer download works only with the order access token', ! is_wp_error( $customer ) && wpp_pdf_ok( $customer['pdf'] ) && is_wp_error( $wrong ) && is_wp_error( $other ) );

Settings::update( array( 'print_attach_invoice_pdf' => 1, 'auto_invoice' => 1 ) );
$GLOBALS['wpcap_test_transients'] = array();
$ao = Orders::create( array( 'email' => 'anhang@example.com', 'items' => array( array( 'ticket_type_id' => $it, 'quantity' => 1 ) ), 'idempotency_key' => 'wpp-att-' . uniqid() ) );
$mails_before = count( $GLOBALS['wpcap_test_mails'] );
Orders::confirm_manual_payment( $ao['order_id'] );
$ticket_mail = array_values( array_filter( array_slice( $GLOBALS['wpcap_test_mails'], $mails_before ), static fn( $m ) => 'Deine Tickets' === $m['subject'] ) )[0] ?? array( 'files' => array() );
$inv_files = array_filter( $ticket_mail['files'], static fn( $name ) => str_starts_with( $name, 'rechnung-' ), ARRAY_FILTER_USE_KEY );
check( 'invoice PDF is attached to the ticket mail when enabled', 1 === count( $inv_files ) && wpp_pdf_ok( reset( $inv_files ), 1 ) );
check( 'temporary invoice PDF is removed after sending', ! glob( sys_get_temp_dir() . '/rechnung-*.pdf' ) );
Settings::update( array( 'print_attach_invoice_pdf' => 0, 'auto_invoice' => 0 ) );
$mails_before = count( $GLOBALS['wpcap_test_mails'] );
$GLOBALS['wpcap_test_transients'] = array();
$no = Orders::create( array( 'email' => 'ohne@example.com', 'items' => array( array( 'ticket_type_id' => $it, 'quantity' => 1 ) ), 'idempotency_key' => 'wpp-noatt-' . uniqid() ) );
Orders::confirm_manual_payment( $no['order_id'] );
$plain = array_values( array_filter( array_slice( $GLOBALS['wpcap_test_mails'], $mails_before ), static fn( $m ) => 'Deine Tickets' === $m['subject'] ) )[0] ?? array( 'files' => array() );
check( 'no invoice attachment when the setting is off', ! array_filter( array_keys( $plain['files'] ), static fn( $n ) => str_starts_with( $n, 'rechnung-' ) ) );

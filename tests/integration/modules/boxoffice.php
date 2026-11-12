<?php
/**
 * Integration tests for the box office module (src/BoxOffice, src/Modules/BoxOffice.php).
 * Included from run.php after the core tests; uses check(), make_type(), tickets().
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\BoxOffice\Page as PosPage;
use PassCaptain\BoxOffice\Service as Pos;
use PassCaptain\Orders;
use PassCaptain\Settings;

PassCaptain\Modules\BoxOffice::register();
PassCaptain\Payments\Registry::reset();

if ( ! function_exists( 'nl2br' ) ) { function nl2br( $s ) { return str_replace( "\n", "<br>\n", (string) $s ); } }

global $wpdb;
$p = $wpdb->prefix;

if ( ! function_exists( 'wpb_occurrence_of' ) ) {
	function wpb_occurrence_of( $type_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT occurrence_id FROM {$wpdb->prefix}wpcap_ticket_types WHERE ticket_type_id=" . (int) $type_id );
	}
	function wpb_event_of_type( $type_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT o.event_id FROM {$wpdb->prefix}wpcap_ticket_types tt JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=" . (int) $type_id );
	}
	function wpb_add_type( $occurrence_id, $name, $price, $tax_bp, $capacity ) {
		global $wpdb;
		$wpdb->insert( "{$wpdb->prefix}wpcap_ticket_types", array( 'occurrence_id' => $occurrence_id, 'name' => $name, 'price_minor' => $price, 'currency' => 'EUR', 'capacity' => $capacity, 'max_per_order' => 10, 'tax_rate_bp' => $tax_bp, 'status' => 'active' ) );
		return (int) $wpdb->insert_id;
	}
	function wpb_key() { return 'test-' . bin2hex( random_bytes( 10 ) ); }
	function wpb_seq( $number ) { return (int) substr( (string) $number, -6 ); }
}

echo "Box office: sessions and sales\n";
// Start from a clean state for user 1 (earlier runs in the same DB prefix are reinstalled by the bootstrap).
$type_a = make_type( 5, 10, 1190 );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET tax_rate_bp=1900, name='Erwachsene' WHERE ticket_type_id=$type_a" );
$occ = wpb_occurrence_of( $type_a );
$type_b = wpb_add_type( $occ, 'Ermäßigt', 2140, 700, 0 );

$no_session = Pos::sell( array( 'occurrence_id' => $occ, 'quantities' => array( $type_a => 1 ), 'method' => 'cash', 'form_key' => wpb_key() ) );
check( 'sale without open cash session is rejected', is_wp_error( $no_session ) && 'wpcap_pos_no_session' === $no_session->get_error_code() );
$session = Pos::open_session( 10000, 'EUR', '', 23, 'BOX-OFFICE-2' );
$again = Pos::open_session( 5000 );
check( 'one open session per cashier (opening twice returns it)', ! is_wp_error( $session ) && (int) $session->session_id === (int) $again->session_id && 10000 === (int) $again->opening_float_minor );
check( 'POS session retains its location and register assignment', 23 === (int) $again->location_id && 'BOX-OFFICE-2' === $again->register_id );

$key = wpb_key();
$mails_before = count( $GLOBALS['wpcap_test_mails'] );
$sale = Pos::sell( array( 'occurrence_id' => $occ, 'quantities' => array( $type_a => 2, $type_b => 1 ), 'names' => array( $type_a => array( 'Ada Lovelace', '' ) ), 'method' => 'cash', 'tendered_minor' => 10000, 'form_key' => $key ) );
$s1 = is_wp_error( $sale ) ? null : $sale['sale'];
$o1 = $s1 ? Orders::get( $s1->order_id ) : null;
check( 'cash sale is booked as paid order with provider pos_cash', $s1 && 'paid' === $o1->payment_status && 'pos_cash' === $o1->payment_provider && 'completed' === $s1->status );
check( 'sale total and VAT come from the ticket types', $s1 && 4520 === (int) $s1->total_minor && 520 === (int) $s1->tax_minor );
check( 'tickets are issued immediately with attendee names', $s1 && 3 === count( tickets( $s1->order_id, 'valid' ) ) && 'Ada Lovelace' === $wpdb->get_var( "SELECT a.attendee_name FROM {$p}wpcap_ticket_assignments a JOIN {$p}wpcap_tickets t ON t.ticket_id=a.ticket_id WHERE t.order_id=" . (int) $s1->order_id ) );
check( 'without buyer e-mail a reserved placeholder address is used', $o1 && Pos::is_placeholder_email( $o1->customer_email ) && str_starts_with( $o1->customer_email, 'boxoffice+' ) );
check( 'mails to the placeholder are suppressed via pre_wp_mail', true === Pos::suppress_placeholder_mail( null, array( 'to' => $o1->customer_email ) ) && null === Pos::suppress_placeholder_mail( null, array( 'to' => 'real@example.com' ) ) );
check( 'receipt number uses the BON series', $s1 && 1 === preg_match( '/^BON-\d{4}-\d{6}$/', $s1->receipt_number ) );
$replay = Pos::sell( array( 'occurrence_id' => $occ, 'quantities' => array( $type_a => 2, $type_b => 1 ), 'method' => 'cash', 'form_key' => $key ) );
check( 'resubmitting the same form key returns the same sale (idempotent)', ! is_wp_error( $replay ) && $replay['reused'] && (int) $replay['sale']->sale_id === (int) $s1->sale_id && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_boxoffice_sales WHERE form_key_hash='" . $s1->form_key_hash . "'" ) && 3 === count( tickets( $s1->order_id ) ) );

$receipt = Pos::receipt( $s1->sale_id );
check( 'receipt has VAT per rate, payment method, cashier and change', 380 === $receipt['vat'][1900]['tax'] && 2000 === $receipt['vat'][1900]['net'] && 140 === $receipt['vat'][700]['tax'] && 'Bar' === $receipt['method_label'] && 5480 === $receipt['change_minor'] && '' !== $receipt['cashier'] && 2 === count( $receipt['items'] ) );
$html = Pos::receipt_html( $s1->sale_id );
check( 'receipt print view is 80 mm and contains number and total', false !== strpos( $html, 'size:80mm' ) && false !== strpos( $html, $s1->receipt_number ) && false !== strpos( $html, '45,20 EUR' ) );

echo "Box office: payment methods\n";
$card = Pos::sell( array( 'occurrence_id' => $occ, 'quantities' => array( $type_b => 1 ), 'method' => 'card', 'reference' => 'TERM-4711', 'form_key' => wpb_key() ) );
$oc = Orders::get( $card['sale']->order_id );
check( 'card payment stores provider and terminal reference', 'pos_card' === $oc->payment_provider && 'TERM-4711' === $oc->provider_reference && 'paid' === $oc->payment_status );
$free = Pos::sell( array( 'occurrence_id' => $occ, 'quantities' => array( $type_a => 1 ), 'method' => 'free', 'form_key' => wpb_key() ) );
$of = Orders::get( $free['sale']->order_id );
check( 'complimentary tickets cost nothing but are issued', 'pos_free' === $of->payment_provider && 0 === (int) $of->total_minor && 0 === (int) $of->tax_minor && 1 === count( tickets( $of->order_id, 'valid' ) ) );
$noemail = Pos::sell( array( 'occurrence_id' => $occ, 'quantities' => array( $type_b => 1 ), 'method' => 'invoice', 'form_key' => wpb_key() ) );
check( 'payment on invoice requires the buyer e-mail', is_wp_error( $noemail ) && 'wpcap_pos_email' === $noemail->get_error_code() );
$inv = Pos::sell( array( 'occurrence_id' => $occ, 'quantities' => array( $type_b => 1 ), 'method' => 'invoice', 'email' => 'firma@example.com', 'form_key' => wpb_key() ) );
$oi = Orders::get( $inv['sale']->order_id );
check( 'invoice payment issues an invoice and sends the order link', 'pos_invoice' === $oi->payment_provider && 'firma@example.com' === $oi->customer_email && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_invoices WHERE order_id=" . (int) $oi->order_id ) && (bool) array_filter( $GLOBALS['wpcap_test_mails'], static fn( $m ) => 'firma@example.com' === $m['to'] && false !== strpos( $m['body'], 'wpcap_access=' ) ) );
$bad_method = Pos::sell( array( 'occurrence_id' => $occ, 'quantities' => array( $type_b => 1 ), 'method' => 'bitcoin', 'form_key' => wpb_key() ) );
$foreign = Pos::sell( array( 'occurrence_id' => $occ, 'quantities' => array( make_type( 5 ) => 1 ), 'method' => 'cash', 'form_key' => wpb_key() ) );
check( 'unknown payment method and foreign ticket types are rejected', is_wp_error( $bad_method ) && is_wp_error( $foreign ) && 'wpcap_pos_item' === $foreign->get_error_code() );
$input = PosPage::sale_input( array( 'occurrence_id' => (string) $occ, 'qty' => array( $type_a => '2', $type_b => '0' ), 'names' => array( $type_a => "Ada\nBob" ), 'method' => 'cash', 'tendered' => '50,00', 'form_key' => 'abcdefabcdefabcdef' ) );
check( 'POS form input is parsed (quantities, names per line, tendered amount)', array( $type_a => 2 ) === $input['quantities'] && array( 'Ada', 'Bob' ) === $input['names'][ $type_a ] && 5000 === $input['tendered_minor'] );

echo "Box office: shared quota with online sales\n";
$type_c = make_type( 3, 10, 1500 );
$occ_c = wpb_occurrence_of( $type_c );
$GLOBALS['wpcap_test_transients'] = array();
$online = Orders::create( array( 'email' => 'online@example.com', 'items' => array( array( 'ticket_type_id' => $type_c, 'quantity' => 2 ) ), 'idempotency_key' => 'wpb-online-' . uniqid() ) );
check( 'POS sees the online hold in live availability', 1 === Pos::ticket_types( $occ_c )[0]['available'] );
$too_many = Pos::sell( array( 'occurrence_id' => $occ_c, 'quantities' => array( $type_c => 2 ), 'method' => 'cash', 'form_key' => wpb_key() ) );
$last_one = Pos::sell( array( 'occurrence_id' => $occ_c, 'quantities' => array( $type_c => 1 ), 'method' => 'cash', 'form_key' => wpb_key() ) );
check( 'box office cannot oversell the shared quota', is_wp_error( $too_many ) && ! is_wp_error( $last_one ) && 0 === Orders::availability( $type_c ) );

$type_d = make_type( 5, 10, 1000 );
$occ_d = wpb_occurrence_of( $type_d );
$procs = array();
$worker = __DIR__ . '/boxoffice/worker.php';
$run = dirname( __DIR__ ) . '/run.php';
for ( $i = 0; $i < 12; $i++ ) {
	$cmd = 0 === $i % 2
		? escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $worker ) . ' ' . $type_d . ' ' . $occ_d
		: escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $run ) . ' --child ' . $type_d;
	$procs[] = array( 0 === $i % 2 ? 'pos' : 'online', popen( $cmd . ' 2>&1', 'r' ) );
}
$ok = array( 'pos' => 0, 'online' => 0 );
foreach ( $procs as list( $kind, $proc ) ) { $out = trim( (string) stream_get_contents( $proc ) ); $ok[ $kind ] += 'OK' === substr( $out, -2 ) ? 1 : 0; pclose( $proc ); }
$sold = (int) $wpdb->get_var( "SELECT COALESCE(SUM(oi.quantity),0) FROM {$p}wpcap_order_items oi JOIN {$p}wpcap_orders o ON o.order_id=oi.order_id WHERE oi.ticket_type_id=$type_d AND o.payment_status='paid'" );
$held = (int) $wpdb->get_var( "SELECT COALESCE(SUM(quantity),0) FROM {$p}wpcap_reservations WHERE ticket_type_id=$type_d AND status='active'" );
check( "parallel online and box office buyers share 5 tickets (pos {$ok['pos']}, online {$ok['online']}, sold $sold, held $held)", 5 === $ok['pos'] + $ok['online'] && 5 === $sold + $held && $sold === $ok['pos'] && 0 === Orders::availability( $type_d ) );

echo "Box office: storno and permissions\n";
$type_e = make_type( 4, 10, 2000 );
$occ_e = wpb_occurrence_of( $type_e );
$se = Pos::sell( array( 'occurrence_id' => $occ_e, 'quantities' => array( $type_e => 2 ), 'method' => 'card', 'form_key' => wpb_key() ) )['sale'];
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_refund', 'wpcap_approve_refunds' );
$denied = Pos::storno( $se->sale_id, 'Vertippt' );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'storno needs the refund capability (enforced by Refunds)', is_wp_error( $denied ) && 'completed' === Pos::sale( $se->sale_id )->status );
$GLOBALS['wpcap_test_user_id'] = 2;
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options', 'wpcap_approve_refunds' );
$other = Pos::storno( $se->sale_id );
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 1;
check( 'another cashier cannot void the sale', is_wp_error( $other ) );
$voided = Pos::storno( $se->sale_id, 'Vertippt' );
$refund = PassCaptain\Refunds::for_order( $se->order_id )[0] ?? array();
check( 'storno refunds through the pos_card gateway without external call', ! is_wp_error( $voided ) && 'voided' === $voided->status && 'completed' === ( $refund['status'] ?? '' ) && 4000 === (int) $refund['amount_minor'] && str_starts_with( (string) $refund['provider_reference'], 'pos_card:' ) );
check( 'storno revokes the tickets and frees the quota', 2 === count( tickets( $se->order_id, 'revoked' ) ) && 4 === Orders::availability( $type_e ) && 'refunded' === Orders::get( $se->order_id )->status );
check( 'storno receipt gets the next number of the receipt series', wpb_seq( $voided->storno_receipt_number ) > wpb_seq( $voided->receipt_number ) && false !== strpos( Pos::receipt_html( $se->sale_id, true ), 'STORNOBELEG' ) );
$twice = Pos::storno( $se->sale_id );
check( 'voiding twice is harmless', ! is_wp_error( $twice ) && $twice->storno_receipt_number === $voided->storno_receipt_number && 1 === count( PassCaptain\Refunds::for_order( $se->order_id ) ) );
$o1_valid = tickets( $o1->order_id, 'valid' );
$refund_other = PassCaptain\Refunds::request( $o1->order_id, array( (int) end( $o1_valid )->ticket_id ), 'Kulanz', 'wpb-partial' );
check( 'a normal partial refund does not void the box office sale', 'completed' === ( $refund_other['status'] ?? '' ) && 'completed' === Pos::sale( $s1->sale_id )->status );

Settings::update( array( 'refund_approval_threshold' => 100 ) );
$sp = Pos::sell( array( 'occurrence_id' => $occ_e, 'quantities' => array( $type_e => 1 ), 'method' => 'cash', 'form_key' => wpb_key() ) )['sale'];
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_approve_refunds' );
$pending = Pos::storno( $sp->sale_id, 'Groß' );
$GLOBALS['wpcap_test_denied_caps'] = array();
$blocked_close = Pos::close_session( $session->session_id, 0 );
check( 'storno above the approval threshold waits and blocks the cash closing', ! is_wp_error( $pending ) && 'storno_pending' === $pending->status && is_wp_error( $blocked_close ) && 'wpcap_pos_storno_pending' === $blocked_close->get_error_code() );
$GLOBALS['wpcap_test_user_id'] = 2;
PassCaptain\Refunds::approve( (int) Pos::sale( $sp->sale_id )->storno_refund_id );
$GLOBALS['wpcap_test_user_id'] = 1;
check( 'approved storno voids the sale', 'voided' === Pos::sale( $sp->sale_id )->status && '' !== (string) Pos::sale( $sp->sale_id )->storno_receipt_number );
Settings::update( array( 'refund_approval_threshold' => 0 ) );

echo "Box office: rejected stornos (regression)\n";
Settings::update( array( 'refund_approval_threshold' => 100 ) );
$sr = Pos::sell( array( 'occurrence_id' => $occ_e, 'quantities' => array( $type_e => 1 ), 'method' => 'cash', 'form_key' => wpb_key() ) )['sale'];
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_approve_refunds' );
Pos::storno( $sr->sale_id, 'Groß' );
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 2;
PassCaptain\Refunds::reject( (int) Pos::sale( $sr->sale_id )->storno_refund_id, 'Nein' );
$GLOBALS['wpcap_test_user_id'] = 1;
$after_reject = Pos::sale( $sr->sale_id );
check( 'rejected storno (wpcap_refund_rejected) puts the sale back to completed with a new attempt', 'completed' === $after_reject->status && 1 === (int) $after_reject->storno_attempt && 0 === (int) $after_reject->storno_refund_id );
Settings::update( array( 'refund_approval_threshold' => 0 ) );
$retry = Pos::storno( $sr->sale_id, 'Zweiter Versuch' );
check( 'a new storno after a rejection is possible (new refund key)', ! is_wp_error( $retry ) && 'voided' === $retry->status && 2 === count( PassCaptain\Refunds::for_order( $sr->order_id ) ) );
Settings::update( array( 'refund_approval_threshold' => 100 ) );
$sl = Pos::sell( array( 'occurrence_id' => $occ_e, 'quantities' => array( $type_e => 1 ), 'method' => 'cash', 'form_key' => wpb_key() ) )['sale'];
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_approve_refunds' );
Pos::storno( $sl->sale_id );
$GLOBALS['wpcap_test_denied_caps'] = array();
$wpdb->query( "UPDATE {$p}wpcap_refunds SET status='rejected' WHERE refund_id=" . (int) Pos::sale( $sl->sale_id )->storno_refund_id ); // decided without the hook
check( 'refund rejected without hook is detected lazily (storno/close)', 1 === Pos::reset_rejected_stornos( $session->session_id ) && 'completed' === Pos::sale( $sl->sale_id )->status );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_approve_refunds' );
Pos::storno( $sl->sale_id );
$GLOBALS['wpcap_test_denied_caps'] = array();
$rej = Pos::reject_storno( $sl->sale_id, 'Kassenabschluss' );
check( 'approvers can reject a pending storno from the box office screen', ! is_wp_error( $rej ) && 'completed' === $rej->status && 'rejected' === PassCaptain\Refunds::get( (int) $wpdb->get_var( "SELECT MAX(refund_id) FROM {$p}wpcap_refunds WHERE order_id=" . (int) $sl->order_id ) )['status'] );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_approve_refunds' );
check( 'rejecting needs the approval capability', is_wp_error( Pos::reject_storno( $sl->sale_id ) ) || 'completed' === Pos::sale( $sl->sale_id )->status );
$GLOBALS['wpcap_test_denied_caps'] = array();
Settings::update( array( 'refund_approval_threshold' => 0 ) );

$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_orders' );
$nocap = Pos::sell( array( 'occurrence_id' => $occ, 'quantities' => array( $type_b => 1 ), 'method' => 'cash', 'form_key' => wpb_key() ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$noscope = Pos::sell( array( 'occurrence_id' => $occ, 'quantities' => array( $type_b => 1 ), 'method' => 'cash', 'form_key' => wpb_key() ) );
$wpdb->insert( "{$p}wpcap_event_staff", array( 'event_id' => wpb_event_of_type( $type_b ), 'user_id' => 1, 'capability' => 'wpcap_manage_orders' ) );
$scoped = Pos::sell( array( 'occurrence_id' => $occ, 'quantities' => array( $type_b => 1 ), 'method' => 'cash', 'form_key' => wpb_key() ) );
$wpdb->query( "DELETE FROM {$p}wpcap_event_staff WHERE user_id=1 AND capability='wpcap_manage_orders'" );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options', 'wpcap_view_finance' );
$GLOBALS['wpcap_test_user_id'] = 3;
$receipt_other = PosPage::can_view_sale( $s1 );
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'selling needs wpcap_manage_orders and the event scope', is_wp_error( $nocap ) && 403 === $nocap->get_error_data()['status'] && is_wp_error( $noscope ) && 403 === $noscope->get_error_data()['status'] && ! is_wp_error( $scoped ) );
check( 'receipts are only visible to the cashier, scoped order managers or finance', true === PosPage::can_view_sale( $s1 ) && false === $receipt_other );

echo "Box office: tickets, numbering and closing\n";
$old_hash = $wpdb->get_var( "SELECT token_hash FROM {$p}wpcap_tickets WHERE order_id=" . (int) $s1->order_id . " AND status='valid' ORDER BY ticket_id LIMIT 1" );
$tpdf = PosPage::tickets_pdf( $s1->sale_id );
$new_hash = $wpdb->get_var( "SELECT token_hash FROM {$p}wpcap_tickets WHERE order_id=" . (int) $s1->order_id . " AND status='valid' ORDER BY ticket_id LIMIT 1" );
preg_match( '/startxref\n(\d+)\n%%EOF$/', $tpdf['pdf'] ?? '', $xm );
check( 'printed tickets are a valid PDF with one page per valid ticket and fresh codes', ! is_wp_error( $tpdf ) && str_starts_with( $tpdf['pdf'], '%PDF-1.4' ) && count( tickets( $s1->order_id, 'valid' ) ) === preg_match_all( '#/Type /Page /Parent#', $tpdf['pdf'] ) && isset( $xm[1] ) && 0 === strpos( substr( $tpdf['pdf'], (int) $xm[1] ), 'xref' ) && $old_hash !== $new_hash && false !== strpos( $tpdf['pdf'], '(Ada Lovelace) Tj' ) );
check( 'no ticket print for voided sales', is_wp_error( PosPage::tickets_pdf( $se->sale_id ) ) );

$numbers = array();
foreach ( $wpdb->get_results( "SELECT receipt_number,storno_receipt_number FROM {$p}wpcap_boxoffice_sales" ) as $r ) {
	if ( $r->receipt_number ) { $numbers[] = wpb_seq( $r->receipt_number ); }
	if ( $r->storno_receipt_number ) { $numbers[] = wpb_seq( $r->storno_receipt_number ); }
}
sort( $numbers );
check( 'receipt numbers (sales and stornos, incl. parallel sales) are gapless and unique (' . count( $numbers ) . ')', $numbers && range( $numbers[0], $numbers[0] + count( $numbers ) - 1 ) === $numbers );

$totals = Pos::session_totals( $session->session_id );
$expected_cash = (int) $wpdb->get_var( "SELECT COALESCE(SUM(total_minor),0) FROM {$p}wpcap_boxoffice_sales WHERE session_id=" . (int) $session->session_id . " AND payment_method='cash' AND status<>'voided'" );
check( 'session totals per payment method net out stornos', 1 === $totals['methods']['card']['voided_count'] && 2140 === $totals['methods']['card']['net'] && 0 === $totals['methods']['free']['net'] && 2140 === $totals['methods']['invoice']['net'] && 10000 + $expected_cash === $totals['expected_cash'] && $expected_cash === $totals['methods']['cash']['net'] );
$GLOBALS['wpcap_test_user_id'] = 2;
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$foreign_close = Pos::close_session( $session->session_id, 0 );
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 1;
$closed = Pos::close_session( $session->session_id, $totals['expected_cash'] - 150, 'Kassensturz' );
check( 'only the cashier closes the session; difference to the count is stored', is_wp_error( $foreign_close ) && ! is_wp_error( $closed ) && 'closed' === $closed->status && -150 === (int) $closed->difference_minor && (int) $closed->expected_cash_minor === $totals['expected_cash'] && null === $closed->open_key );
check( 'after closing: no storno, no sale, closing twice fails', is_wp_error( Pos::storno( $s1->sale_id ) ) && is_wp_error( Pos::sell( array( 'occurrence_id' => $occ, 'quantities' => array( $type_b => 1 ), 'method' => 'cash', 'form_key' => wpb_key() ) ) ) && is_wp_error( Pos::close_session( $session->session_id, 0 ) ) );
$next = Pos::open_session( 0 );
check( 'a new session can be opened after closing', ! is_wp_error( $next ) && (int) $next->session_id !== (int) $session->session_id );

echo "Box office: consistency of sale and order (regression)\n";
// Paid order without a booked sale (e.g. crash after payment): resubmitting the form key books it once.
$rkey = wpb_key();
$rorder = Orders::create( array( 'email' => 'boxoffice+recover@invalid.example', 'items' => array( array( 'ticket_type_id' => $type_b, 'quantity' => 1 ) ), 'idempotency_key' => $rkey ), array( 'external' => true, 'payment_provider' => 'pos_cash', 'key_namespace' => 'pos', 'source' => 'pos', 'skip_rate_limit' => true ) );
Orders::confirm_external_payment( $rorder['order_id'], 'pos:cash' );
$wpdb->insert( "{$p}wpcap_boxoffice_sales", array( 'session_id' => (int) $next->session_id, 'order_id' => (int) $rorder['order_id'], 'form_key_hash' => hash( 'sha256', 'pos-sale|' . $rkey ), 'payment_method' => 'cash', 'currency' => 'EUR', 'occurrence_id' => $occ, 'cashier_id' => 1, 'status' => 'pending', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$pending_id = (int) $wpdb->insert_id;
$blocked = Pos::close_session( $next->session_id, 0 );
check( 'closing is refused while a paid sale is not booked', is_wp_error( $blocked ) && 'wpcap_pos_unbooked' === $blocked->get_error_code() && 'open' === Pos::session( $next->session_id )->status );
$wpdb->query( "DELETE FROM {$p}wpcap_tickets WHERE order_id=" . (int) $rorder['order_id'] ); // tickets lost: must be re-issued
$resumed = Pos::sell( array( 'occurrence_id' => $occ, 'quantities' => array( $type_b => 1 ), 'method' => 'cash', 'form_key' => $rkey ) );
$resumed2 = Pos::sell( array( 'occurrence_id' => $occ, 'quantities' => array( $type_b => 1 ), 'method' => 'cash', 'form_key' => $rkey ) );
check( 'resubmitting the form key books the pending sale once, with all tickets issued', ! is_wp_error( $resumed ) && $pending_id === (int) $resumed['sale']->sale_id && 'completed' === $resumed['sale']->status && '' !== (string) $resumed['sale']->receipt_number && $resumed2['reused'] && 1 === count( tickets( $rorder['order_id'], 'valid' ) ) && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_boxoffice_sales WHERE order_id=" . (int) $rorder['order_id'] ) );

// Order not really paid (paid_unallocated / needs review): never booked, marked failed at closing.
$ukey = wpb_key();
$uorder = Orders::create( array( 'email' => 'boxoffice+unalloc@invalid.example', 'items' => array( array( 'ticket_type_id' => $type_b, 'quantity' => 1 ) ), 'idempotency_key' => $ukey ), array( 'external' => true, 'payment_provider' => 'pos_cash', 'key_namespace' => 'pos', 'source' => 'pos', 'skip_rate_limit' => true ) );
$wpdb->query( "UPDATE {$p}wpcap_orders SET status='needs_review',payment_status='paid_unallocated' WHERE order_id=" . (int) $uorder['order_id'] );
$wpdb->insert( "{$p}wpcap_boxoffice_sales", array( 'session_id' => (int) $next->session_id, 'order_id' => (int) $uorder['order_id'], 'form_key_hash' => hash( 'sha256', 'pos-sale|' . $ukey ), 'payment_method' => 'cash', 'currency' => 'EUR', 'occurrence_id' => $occ, 'cashier_id' => 1, 'status' => 'pending', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$unalloc_id = (int) $wpdb->insert_id;
$not_ready = Pos::finalize( $unalloc_id );
check( 'a sale whose order is not paid with tickets is not booked', is_wp_error( $not_ready ) && 'wpcap_pos_not_ready' === $not_ready->get_error_code() && null === Pos::sale( $unalloc_id )->receipt_number );
$totals_before = Pos::session_totals( $next->session_id );
check( 'unbooked sales do not count in the session totals', 1 === $totals_before['sales'] && 2140 === $totals_before['methods']['cash']['net'] );

// Session closed in another tab while the sale was being processed.
$GLOBALS['wpb_close_during_sale'] = (int) $next->session_id;
add_action( 'wpcap_order_paid', static function () {
	global $wpdb;
	if ( empty( $GLOBALS['wpb_close_during_sale'] ) ) { return; }
	$wpdb->query( "UPDATE {$wpdb->prefix}wpcap_boxoffice_sessions SET status='closed',open_key=NULL WHERE session_id=" . (int) $GLOBALS['wpb_close_during_sale'] );
	$GLOBALS['wpb_close_during_sale'] = 0;
} );
$numbers_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_boxoffice_sales WHERE receipt_number IS NOT NULL" );
$late = Pos::sell( array( 'occurrence_id' => $occ, 'quantities' => array( $type_b => 1 ), 'method' => 'cash', 'form_key' => wpb_key() ) );
$late_row = $wpdb->get_row( "SELECT * FROM {$p}wpcap_boxoffice_sales ORDER BY sale_id DESC LIMIT 1" );
check( 'no sale is booked into a session closed meanwhile; its order is cancelled', is_wp_error( $late ) && 'wpcap_pos_session_closed' === $late->get_error_code() && 'failed' === $late_row->status && null === $late_row->receipt_number && 'cancelled' === Orders::get( $late_row->order_id )->status && ! tickets( $late_row->order_id, 'valid' ) && $numbers_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_boxoffice_sales WHERE receipt_number IS NOT NULL" ) );
check( 'selling into a closed session is refused before any order is created', is_wp_error( Pos::sell( array( 'occurrence_id' => $occ, 'quantities' => array( $type_b => 1 ), 'method' => 'cash', 'form_key' => wpb_key() ) ) ) );
$wpdb->query( "UPDATE {$p}wpcap_boxoffice_sessions SET status='open',open_key=1 WHERE session_id=" . (int) $next->session_id );
$closed_next = Pos::close_session( $next->session_id, 2140 );
check( 'closing marks unpaid unfinished sales failed', ! is_wp_error( $closed_next ) && 'failed' === Pos::sale( $unalloc_id )->status && 0 === (int) $closed_next->difference_minor );

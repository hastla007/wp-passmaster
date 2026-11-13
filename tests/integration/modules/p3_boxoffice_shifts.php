<?php
/**
 * Integration tests for box office registers, shifts, handover, Z reports, refunds after close,
 * partial storno, checked-in protection and the migration of legacy cash sessions.
 * Included from run.php after boxoffice*.php; uses check(), make_type(), tickets() and wpb_* helpers.
 *
 * Also a concurrency worker when run directly:
 *   php p3_boxoffice_shifts.php cycles <register_id> <user_id> <cycles>   (prints closes=<n>)
 *   php p3_boxoffice_shifts.php close <shift_id> <user_id>                (prints OK or ERR)
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\BoxOffice\Registers;
use PassCaptain\BoxOffice\Service as Pos;
use PassCaptain\BoxOffice\Shifts;
use PassCaptain\BoxOffice\ZReport;
use PassCaptain\Modules\BoxOffice as BoxOfficeModule;
use PassCaptain\Orders;
use PassCaptain\Refunds;

if ( ! function_exists( 'check' ) ) {
	// Concurrency worker.
	require dirname( __DIR__ ) . '/bootstrap.php';
	BoxOfficeModule::register();
	$mode = (string) ( $argv[1] ?? '' );
	$GLOBALS['wpcap_test_user_id'] = (int) ( $argv[3] ?? 0 );
	if ( 'cycles' === $mode ) {
		$register = (int) $argv[2];
		$closes = 0;
		for ( $c = 0; $c < (int) $argv[4]; $c++ ) {
			$shift = null;
			for ( $try = 0; $try < 400 && ! $shift; $try++ ) {
				$r = Shifts::open( $register, array( 'float_minor' => 0 ) );
				if ( is_wp_error( $r ) ) { usleep( random_int( 5000, 25000 ) ); continue; }
				$shift = $r;
			}
			if ( ! $shift ) { continue; }
			$count = Shifts::submit_count( $shift->session_id, array( 'cash_minor' => 0, 'card_minor' => 0 ) );
			$closed = is_wp_error( $count ) ? $count : Shifts::close( $shift->session_id );
			if ( ! is_wp_error( $closed ) ) { $closes++; }
		}
		echo 'closes=' . $closes;
	} elseif ( 'close' === $mode ) {
		usleep( 20000 );
		$r = Shifts::close( (int) $argv[2] );
		echo is_wp_error( $r ) ? 'ERR' : 'OK';
	}
	exit;
}

global $wpdb;
$p = $wpdb->prefix;
if ( ! function_exists( 'esc_js' ) ) { function esc_js( $s ) { return addslashes( (string) $s ); } }
if ( ! function_exists( 'plugins_url' ) ) { function plugins_url( $path = '', $file = '' ) { return 'https://example.test/wp-content/plugins/wp-pass-captain/' . ltrim( $path, '/' ); } }
if ( ! function_exists( 'nocache_headers' ) ) { function nocache_headers() {} }
// Same stubs as partners.php / zz_phase1_ux.php (admin screens, fiscal notice reads user meta).
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); } }
if ( ! function_exists( 'checked' ) ) { function checked( $a, $b = true, $echo = true ) { $r = $a == $b ? ' checked="checked"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'selected' ) ) { function selected( $a, $b = true, $echo = true ) { $r = $a == $b ? ' selected="selected"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce" value="n">'; } }
if ( ! function_exists( 'wp_nonce_url' ) ) { function wp_nonce_url( $u, $a = -1 ) { return $u . '&_wpnonce=n'; } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = null ) { return esc_attr( $t ); } }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) {} }
if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $a = -1 ) { return 'n'; } }
if ( ! function_exists( 'wp_verify_nonce' ) ) { function wp_verify_nonce( $n, $a = -1 ) { return 'n' === $n ? 1 : false; } }
if ( ! function_exists( 'get_user_meta' ) ) { function get_user_meta( $u, $k, $single = false ) { return $GLOBALS['p1u_user_meta'][ $u ][ $k ] ?? ''; } }
if ( ! function_exists( 'update_user_meta' ) ) { function update_user_meta( $u, $k, $v ) { $GLOBALS['p1u_user_meta'][ $u ][ $k ] = $v; return true; } }

$as = static function ( $user_id, array $denied = array() ) {
	$GLOBALS['wpcap_test_user_id'] = $user_id;
	$GLOBALS['wpcap_test_denied_caps'] = $denied;
};
$reset = static function () use ( $as ) { $as( 1 ); };
$cashier = array( 'manage_options', 'wpcap_view_finance', 'wpcap_boxoffice_override' );
$closed_events = array();
add_action( 'wpcap_boxoffice_shift_closed', static function ( $z_id, $z, $shift ) use ( &$closed_events ) { $closed_events[] = array( $z_id, $z, $shift ); }, 10, 3 );

echo "Box office shifts: registers\n";
$as( 501, $cashier );
$denied_register = Registers::save( array( 'name' => 'Kasse X' ) );
$reset();
$r1 = Registers::save( array( 'name' => 'Kasse Foyer', 'location' => 'Foyer links', 'currency' => 'EUR', 'blind_count' => 1 ) );
$r2 = Registers::save( array( 'name' => 'Abendkasse', 'blind_count' => 0 ) );
$noname = Registers::save( array( 'name' => '  ' ) );
check( 'registers are managed by administrators only and need a name', is_wp_error( $denied_register ) && 403 === $denied_register->get_error_data()['status'] && is_object( $r1 ) && 'Foyer links' === $r1->location && 1 === (int) $r1->blind_count && 0 === (int) $r2->blind_count && is_wp_error( $noname ) );

echo "Box office shifts: open with denomination count\n";
$as( 501, $cashier );
$mismatch = Shifts::open( $r1->register_id, array( 'float_minor' => 1000, 'denominations' => array( 2000 => 1 ) ) );
$bad_denom = Shifts::open( $r1->register_id, array( 'denominations' => array( 300 => 1 ) ) );
$s1 = Shifts::open( $r1->register_id, array( 'denominations' => array( 2000 => 2, 500 => 4, 100 => 5 ), 'note' => 'Frühschicht' ) );
$opening = Shifts::latest_count( $s1->session_id, 'opening' );
check( 'opening float is counted by denominations (amount and count must match)', is_wp_error( $mismatch ) && 'wpcap_pos_denomination_sum' === $mismatch->get_error_code() && is_wp_error( $bad_denom ) && ! is_wp_error( $s1 ) && 6500 === (int) $s1->opening_float_minor && 6500 === (int) $opening->cash_minor && array( '2000' => 2, '500' => 4, '100' => 5 ) === json_decode( $opening->denominations, true ) );
$second_shift = Shifts::open( $r2->register_id );
$as( 502, $cashier );
$busy = Shifts::open( $r1->register_id );
check( 'a register has at most one open shift; a cashier works in one shift only', is_wp_error( $busy ) && 'wpcap_pos_register_busy' === $busy->get_error_code() && is_wp_error( $second_shift ) && 'wpcap_pos_shift_active' === $second_shift->get_error_code() );
$as( 501, $cashier );
Shifts::add_cashier( $s1->session_id, 502 );
check( 'a second cashier can be added to the shift and sells into it', (int) Pos::open_session_for( 502 )->session_id === (int) $s1->session_id && array( 501, 502 ) === Shifts::cashiers( $s1->session_id ) );

echo "Box office shifts: sales, cash in/out and blind count\n";
$t19 = make_type( 50, 10, 1190 );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET tax_rate_bp=1900, name='Normal' WHERE ticket_type_id=$t19" );
$occ = wpb_occurrence_of( $t19 );
$t7 = wpb_add_type( $occ, 'Kind', 1070, 700, 50 );
// Cashiers without manage_options work with event-scoped staff rights.
foreach ( array( 501, 502, 503, 505, 509, 801 ) as $uid ) {
	$wpdb->insert( "{$p}wpcap_event_staff", array( 'event_id' => wpb_event_of_type( $t19 ), 'user_id' => $uid, 'capability' => 'wpcap_manage_orders' ) );
}
$sale_cash = Pos::sell( array( 'occurrence_id' => $occ, 'quantities' => array( $t19 => 2, $t7 => 1 ), 'method' => 'cash', 'tendered_minor' => 5000, 'form_key' => wpb_key() ) )['sale'];
$as( 502, $cashier );
$sale_card = Pos::sell( array( 'occurrence_id' => $occ, 'quantities' => array( $t7 => 2 ), 'method' => 'card', 'reference' => 'T-1', 'form_key' => wpb_key() ) )['sale'];
$as( 501, $cashier );
$a47_mails = count( $GLOBALS['wpcap_test_mails'] );
$a47_suppressed = 0;
$a47_hook = static function () use ( &$a47_suppressed ) { ++$a47_suppressed; };
add_action( 'wpcap_mail_suppressed', $a47_hook );
$sale_free = Pos::sell( array( 'occurrence_id' => $occ, 'quantities' => array( $t19 => 1 ), 'method' => 'free', 'form_key' => wpb_key() ) )['sale'];
remove_action( 'wpcap_mail_suppressed', $a47_hook );
check( 'A047: a sale without buyer e-mail sends no mail to the placeholder (even without pre_wp_mail)', $a47_suppressed >= 1 && ! array_filter( array_slice( $GLOBALS['wpcap_test_mails'], $a47_mails ), static fn( $m ) => false !== stripos( implode( ',', (array) $m['to'] ), 'invalid.example' ) ) );
check( 'A047: placeholder detection', PassCaptain\Mail\Mailer::is_placeholder( 'boxoffice+ab12@invalid.example' ) && PassCaptain\Mail\Mailer::is_placeholder( 'x@test.invalid' ) && ! PassCaptain\Mail\Mailer::is_placeholder( 'a@example.com' ) && ! PassCaptain\Mail\Mailer::is_placeholder( 'boxoffice+x@invalid.example, a@example.com' ) && ! PassCaptain\Mail\Mailer::is_placeholder( '' ) );
$in = Shifts::cash_movement( $s1->session_id, 'cash_in', 1000, 'Wechselgeld nachgelegt' );
$out = Shifts::cash_movement( $s1->session_id, 'cash_out', 2000, 'Abschöpfung Tresor' );
$no_reason = Shifts::cash_movement( $s1->session_id, 'cash_out', 100, ' ' );
$zero = Shifts::cash_movement( $s1->session_id, 'cash_in', 0, 'x' );
check( 'sales of both cashiers are booked into the shift; cash in/out need amount and reason', (int) $sale_cash->session_id === (int) $s1->session_id && (int) $sale_card->session_id === (int) $s1->session_id && 3450 === (int) $sale_cash->total_minor && 1000 === (int) $in->amount_minor && -2000 === (int) $out->amount_minor && is_wp_error( $no_reason ) && is_wp_error( $zero ) );

$view = Shifts::view( $s1->session_id );
check( 'blind count: cashiers do not see expected amounts before counting', true === $view['masked'] && ! isset( $view['totals']['cash']['expected'] ) && ! isset( $view['totals']['methods'] ) && false === Shifts::can_see_expected( $s1 ) && 3 === $view['totals']['counts']['sales'] );
$reset();
check( 'administrators see the expected amounts of a blind shift', false === Shifts::view( $s1->session_id )['masked'] && 8950 === Shifts::view( $s1->session_id )['totals']['cash']['expected'] );
$as( 501, $cashier );
$count1 = Shifts::submit_count( $s1->session_id, array( 'denominations' => array( 5000 => 1, 2000 => 1, 1000 => 1, 500 => 1, 100 => 4 ), 'card_minor' => 2140 ) );
check( 'blind count: the cashier does not learn expected cash or the difference from the count (audit 3)', ! is_wp_error( $count1 ) && 8900 === $count1['cash_minor'] && null === $count1['expected_cash_minor'] && null === $count1['cash_difference_minor'] && null === $count1['card_difference_minor'] && true === $count1['blind'] && true === Shifts::view( $s1->session_id )['masked'] );
$recount_cashier = Shifts::submit_count( $s1->session_id, array( 'cash_minor' => 8950 ) );
check( 'blind count: the cashier cannot recount (audit 3)', is_wp_error( $recount_cashier ) && 'wpcap_pos_recount_forbidden' === $recount_cashier->get_error_code() && 1 === count( array_filter( Shifts::counts( $s1->session_id ), static fn( $c ) => 'closing' === $c->kind ) ) );
$during = Pos::sell( array( 'occurrence_id' => $occ, 'quantities' => array( $t19 => 1 ), 'method' => 'cash', 'form_key' => wpb_key() ) );
$mv_during = Shifts::cash_movement( $s1->session_id, 'cash_in', 100, 'zu spät' );
check( 'after the count no sales and no movements are possible', is_wp_error( $during ) && 'wpcap_pos_no_session' === $during->get_error_code() && is_wp_error( $mv_during ) );
$reset(); // Register manager: sees the difference, so a reason is required.
$no_diff_reason = Shifts::close( $s1->session_id );
check( 'closing with a difference needs a reason', is_wp_error( $no_diff_reason ) && 'wpcap_pos_difference_reason' === $no_diff_reason->get_error_code() && 'counting' === Shifts::get( $s1->session_id )->status );
$count2 = Shifts::submit_count( $s1->session_id, array( 'cash_minor' => 8950, 'card_minor' => 2100 ) );
check( 'recount by the register manager is a new count entry (both kept); card terminal difference is computed', -40 === $count2['card_difference_minor'] && 0 === $count2['cash_difference_minor'] && 2 === count( array_filter( Shifts::counts( $s1->session_id ), static fn( $c ) => 'closing' === $c->kind ) ) );
$as( 501, $cashier );
$too_much = Shifts::close( $s1->session_id, array( 'reason' => 'Terminal', 'handover_to' => 503, 'handover_minor' => 9000 ) );
check( 'handover amount cannot exceed the counted cash', is_wp_error( $too_much ) && 'wpcap_pos_handover_amount' === $too_much->get_error_code() );
$closed1 = Shifts::close( $s1->session_id, array( 'reason' => 'Terminal-Trinkgeld storniert', 'handover_to' => 503, 'handover_denominations' => array( 2000 => 2, 1000 => 1 ) ) );
$z1 = ZReport::for_shift( $s1->session_id );
$t = $z1 ? $z1->totals_data : array();
check( 'Z report lists every count with its difference (first -0,50, recount 0) (audit 3)', 2 === count( $t['count_history'] ?? array() ) && -50 === $t['count_history'][0]['cash_difference'] && 0 === $t['count_history'][1]['cash_difference'] );

echo "Box office shifts: Z report\n";
check( 'closing writes Z report no. 1 of the register and fires wpcap_boxoffice_shift_closed', ! is_wp_error( $closed1 ) && 'closed' === $closed1->status && null === $closed1->open_register && 1 === (int) $z1->z_number && (int) $r1->register_id === (int) $z1->register_id && 1 === count( $closed_events ) && (int) $closed_events[0][0] === (int) $z1->z_id && 8950 === (int) $closed_events[0][1]->totals_data['cash']['expected'] );
check( 'Z totals per payment method', 1 === $t['methods']['cash']['sales_count'] && 3450 === $t['methods']['cash']['sales_gross'] && 2140 === $t['methods']['card']['net'] && 1 === $t['methods']['free']['sales_count'] && 0 === $t['methods']['free']['net'] );
check( 'Z totals per VAT rate (net/VAT/gross)', array( 'gross' => 2380, 'tax' => 380, 'net' => 2000 ) === $t['vat'][1900] && array( 'gross' => 3210, 'tax' => 210, 'net' => 3000 ) === $t['vat'][700] );
check( 'Z counts sales, comps and tickets', 3 === $t['counts']['sales'] && 1 === $t['counts']['comps'] && 1 === $t['counts']['comp_tickets'] && 6 === $t['counts']['tickets'] && 0 === $t['counts']['stornos'] );
check( 'Z cash: float, cash in/out, counted vs expected, handover and removal', 6500 === $t['cash']['opening'] && 1000 === $t['cash']['in'] && 2000 === $t['cash']['out'] && 8950 === $t['cash']['counted'] && 0 === $t['cash']['difference'] && 5000 === $t['cash']['handover'] && 3950 === $t['cash']['to_safe'] && 8950 === (int) $z1->expected_cash_minor );
check( 'Z card terminal: entered vs expected with difference and reason', 2140 === (int) $z1->expected_card_minor && 2100 === (int) $z1->counted_card_minor && -40 === (int) $z1->card_difference_minor && 'Terminal-Trinkgeld storniert' === $z1->difference_reason );
check( 'Z grand total, times, register id and hash chain', 5590 === (int) $z1->net_total_minor && 5590 === (int) $z1->grand_total_minor && '' !== $z1->opened_at && '' !== $z1->closed_at && '' === $z1->prev_hash && 64 === strlen( $z1->hash ) && array() === ZReport::verify( $r1->register_id ) );
$html = ZReport::html( $z1 );
$csv = ZReport::csv( $z1 );
check( 'printable HTML and CSV of the Z report contain number, VAT rates and grand total', false !== strpos( $html, 'Z-Bericht Nr. 1' ) && false !== strpos( $html, '19,00 % Netto' ) && false !== strpos( $html, 'Grand Total' ) && false !== strpos( $csv, 'Kassendifferenz' ) && false !== strpos( $csv, '7,00 % USt.' ) && false !== strpos( $csv, $z1->hash ) );

echo "Box office shifts: handover\n";
$handover = Registers::pending_handover( $r1->register_id );
$as( 504, $cashier );
$foreign_open = Shifts::open( $r1->register_id );
$foreign_accept = Shifts::accept_handover( $handover->handover_id, array( 'cash_minor' => 5000 ) );
$as( 503, $cashier );
$no_reason_accept = Shifts::accept_handover( $handover->handover_id, array( 'cash_minor' => 4950 ) );
$s2 = Shifts::accept_handover( $handover->handover_id, array( 'denominations' => array( 2000 => 2, 500 => 1, 200 => 2, 50 => 1 ), 'reason' => 'Eine 50-Cent-Münze fehlt' ) );
$handover = Shifts::handover( $handover->handover_id );
check( 'a pending handover blocks other openers; only the addressee accepts', is_wp_error( $foreign_open ) && 'wpcap_pos_handover_pending' === $foreign_open->get_error_code() && is_wp_error( $foreign_accept ) && 403 === $foreign_accept->get_error_data()['status'] );
check( 'handover difference needs a reason; the next shift opens with the confirmed float', is_wp_error( $no_reason_accept ) && ! is_wp_error( $s2 ) && 4950 === (int) $s2->opening_float_minor && (int) $r1->register_id === (int) $s2->register_id && (int) $handover->handover_id === (int) $s2->handover_id );
check( 'both users and the difference are recorded (handover row and audit log)', 'accepted' === $handover->status && 501 === (int) $handover->from_user && 503 === (int) $handover->to_user && 503 === (int) $handover->decided_by && -50 === (int) $handover->difference_minor && 'Eine 50-Cent-Münze fehlt' === $handover->reason && (int) $s2->session_id === (int) $handover->to_shift_id && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='pos_handover_accepted' AND object_id=" . (int) $r1->register_id ) );

echo "Box office shifts: refunds after close are booked into the open shift\n";
$z1_hash = $z1->hash;
$b_ticket = (int) $wpdb->get_var( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=" . (int) $sale_cash->order_id . " AND ticket_type_id=$t7" );
$late = Refunds::request( (int) $sale_cash->order_id, array( $b_ticket ), 'Kulanz', 'p3-late-' . uniqid() );
$late_mv = $wpdb->get_row( "SELECT * FROM {$p}wpcap_boxoffice_movements WHERE refund_id=" . (int) ( $late['refund_id'] ?? 0 ) );
check( 'a refund of a closed shift\'s cash sale is a negative cash movement in the open shift', 'completed' === ( $late['status'] ?? '' ) && $late_mv && (int) $s2->session_id === (int) $late_mv->shift_id && 'refund' === $late_mv->kind && 'cash' === $late_mv->method && -1070 === (int) $late_mv->amount_minor && -70 === (int) $late_mv->tax_minor && '' !== (string) $late_mv->receipt_number );
check( 'the closed Z report stays unchanged', $z1_hash === ZReport::get( $z1->z_id )->hash && array() === ZReport::verify( $r1->register_id ) );
$s2_totals = Pos::session_totals( $s2->session_id );
check( 'the payout reduces the expected cash of the open shift and counts as refund', 4950 - 1070 === $s2_totals['cash']['expected'] && 1 === $s2_totals['counts']['refunds'] && 1070 === $s2_totals['methods']['cash']['refund_amount'] && array( 'gross' => -1070, 'tax' => -70, 'net' => -1000 ) === $s2_totals['vat'][700] );
$receipt_html = ZReport::payout_receipt_html( $late_mv );
check( 'payout receipt shows its own number and the sale receipt', false !== strpos( $receipt_html, (string) $late_mv->receipt_number ) && false !== strpos( $receipt_html, (string) $sale_cash->receipt_number ) && false !== strpos( $receipt_html, 'AUSZAHLUNGSBELEG' ) );

// Register R2: sale, close; then a cash refund without any open shift on R2 or for the user fails.
$as( 505, $cashier );
$s_r2 = Shifts::open( $r2->register_id, array( 'float_minor' => 0 ) );
$sale_r2 = Pos::sell( array( 'occurrence_id' => $occ, 'quantities' => array( $t19 => 1 ), 'method' => 'cash', 'form_key' => wpb_key() ) )['sale'];
Pos::close_session( $s_r2->session_id, 1190 );
$as( 509, $cashier );
$no_shift_storno = Pos::storno( $sale_r2->sale_id, 'nach Abschluss' );
$refunds_before = count( Refunds::for_order( $sale_r2->order_id ) );
$no_shift_refund = Refunds::request( (int) $sale_r2->order_id, array(), 'Rückgabe', 'p3-noshift-' . uniqid() );
$failed_refund = Refunds::for_order( $sale_r2->order_id );
check( 'storno/refund of a cash sale needs an open shift (storno refused upfront, refund fails retryably)', is_wp_error( $no_shift_storno ) && 'wpcap_pos_no_shift' === $no_shift_storno->get_error_code() && 0 === $refunds_before && is_wp_error( $no_shift_refund ) && 'wpcap_pos_no_shift' === $no_shift_refund->get_error_code() && 'failed' === end( $failed_refund )['status'] && 1 === count( tickets( $sale_r2->order_id, 'valid' ) ) );
$as( 505, $cashier );
$s_r2b = Shifts::open( $r2->register_id, array( 'float_minor' => 2000 ) );
$as( 509, $cashier );
$retried = Refunds::execute( (int) end( $failed_refund )['refund_id'] );
$retry_mv = $wpdb->get_row( "SELECT * FROM {$p}wpcap_boxoffice_movements WHERE refund_id=" . (int) end( $failed_refund )['refund_id'] );
check( 'once a shift is open on the register the refund is executed and booked there', 'completed' === ( $retried['status'] ?? '' ) && $retry_mv && (int) $s_r2b->session_id === (int) $retry_mv->shift_id && -1190 === (int) $retry_mv->amount_minor && 'voided' === Pos::sale( $sale_r2->sale_id )->status && (string) $retry_mv->receipt_number === (string) Pos::sale( $sale_r2->sale_id )->storno_receipt_number );

echo "Box office shifts: partial storno\n";
$as( 503, $cashier );
$sale_p = Pos::sell( array( 'occurrence_id' => $occ, 'quantities' => array( $t19 => 3 ), 'method' => 'cash', 'form_key' => wpb_key() ) )['sale'];
$p_tickets = tickets( $sale_p->order_id, 'valid' );
$foreign_ticket = Pos::storno( $sale_p->sale_id, 'falsch', array( $b_ticket ) );
$part = Pos::storno( $sale_p->sale_id, 'Ein Gast fehlt', array( (int) $p_tickets[0]->ticket_id ) );
$part_mv = $wpdb->get_row( "SELECT * FROM {$p}wpcap_boxoffice_movements WHERE sale_id=" . (int) $sale_p->sale_id . ' ORDER BY movement_id DESC LIMIT 1' );
check( 'storno of single tickets refunds only them; the sale stays booked', is_wp_error( $foreign_ticket ) && ! is_wp_error( $part ) && 'completed' === $part->status && 1 === count( tickets( $sale_p->order_id, 'revoked' ) ) && 2 === count( tickets( $sale_p->order_id, 'valid' ) ) && 'storno' === $part_mv->kind && -1190 === (int) $part_mv->amount_minor && (int) $s2->session_id === (int) $part_mv->shift_id );
$rest = Pos::storno( $sale_p->sale_id, 'Rest' );
$rest_mv = $wpdb->get_row( "SELECT * FROM {$p}wpcap_boxoffice_movements WHERE sale_id=" . (int) $sale_p->sale_id . ' ORDER BY movement_id DESC LIMIT 1' );
check( 'storno of the remaining tickets voids the sale with the payout receipt number', ! is_wp_error( $rest ) && 'voided' === $rest->status && -2380 === (int) $rest_mv->amount_minor && $rest->storno_receipt_number === $rest_mv->receipt_number && 2 === Pos::session_totals( $s2->session_id )['counts']['stornos'] );

if ( ! function_exists( 'a46_checkin' ) ) {
	/** Check-in history row for a ticket that stays "valid" (re-entry / multi-entry / pass). */
	function a46_checkin( $ticket_id, $result ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$p}wpcap_checkins (ticket_id,event_id,occurrence_day_id,user_id,result,created_at) SELECT t.ticket_id,o.event_id,0,0,%s,%s FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE t.ticket_id=%d", $result, gmdate( 'Y-m-d H:i:s' ), (int) $ticket_id ) );
	}
}
echo "Box office shifts: re-entry tickets with an admission history are protected (A046)\n";
$sale_re = Pos::sell( array( 'occurrence_id' => $occ, 'quantities' => array( $t19 => 1 ), 'method' => 'cash', 'form_key' => wpb_key() ) )['sale'];
$re_ticket = (int) tickets( $sale_re->order_id, 'valid' )[0]->ticket_id;
a46_checkin( $re_ticket, 'checked_in' );
$re_blocked = Pos::storno( $sale_re->sale_id, 'Rückgabe' );
check( 'A046: a still valid ticket that was admitted before blocks the storno', is_wp_error( $re_blocked ) && 'wpcap_pos_checked_in' === $re_blocked->get_error_code() && 'valid' === tickets( $sale_re->order_id )[0]->status );
a46_checkin( $re_ticket, 'undone' );
$re_ok = Pos::storno( $sale_re->sale_id, 'Rückgabe' );
check( 'A046: an undone check-in does not count as use', ! is_wp_error( $re_ok ) && 'voided' === $re_ok->status );

echo "Box office shifts: checked-in tickets are protected\n";
$sale_ci = Pos::sell( array( 'occurrence_id' => $occ, 'quantities' => array( $t19 => 2 ), 'method' => 'cash', 'form_key' => wpb_key() ) )['sale'];
$ci_tickets = tickets( $sale_ci->order_id, 'valid' );
$wpdb->query( "UPDATE {$p}wpcap_tickets SET status='checked_in' WHERE ticket_id=" . (int) $ci_tickets[0]->ticket_id );
$blocked = Pos::storno( $sale_ci->sale_id, 'Rückgabe' );
$blocked_reason = Pos::storno( $sale_ci->sale_id, 'Rückgabe', array(), 'Kunde krank' );
$as( 503, array( 'manage_options', 'wpcap_view_finance' ) );
$no_reason_override = Pos::storno( $sale_ci->sale_id, 'Rückgabe', array(), '' );
check( 'storno of a checked-in ticket is blocked without override capability and reason', is_wp_error( $blocked ) && 'wpcap_pos_checked_in' === $blocked->get_error_code() && is_wp_error( $blocked_reason ) && is_wp_error( $no_reason_override ) && 2 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE refunded=0 AND order_id=" . (int) $sale_ci->order_id ) );
$as( 503, $cashier );
$valid_only = Pos::storno( $sale_ci->sale_id, 'nur der nicht eingecheckte', array( (int) $ci_tickets[1]->ticket_id ) );
check( 'tickets not checked in can still be stornoed without override', ! is_wp_error( $valid_only ) && 'completed' === $valid_only->status && 1 === count( tickets( $sale_ci->order_id, 'revoked' ) ) );
$as( 503, array( 'manage_options', 'wpcap_view_finance' ) );
$override = Pos::storno( $sale_ci->sale_id, 'Rückgabe', array(), 'Notfall, Veranstalter genehmigt' );
$ov_mv = $wpdb->get_row( "SELECT * FROM {$p}wpcap_boxoffice_movements WHERE sale_id=" . (int) $sale_ci->sale_id . ' ORDER BY movement_id DESC LIMIT 1' );
check( 'with the override capability and a reason the checked-in ticket is refunded (recorded and audited)', ! is_wp_error( $override ) && 'voided' === $override->status && 'Notfall, Veranstalter genehmigt' === $ov_mv->override_reason && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='pos_storno_override' AND object_id=" . (int) $sale_ci->order_id ) && 1 === count( tickets( $sale_ci->order_id, 'checked_in' ) ) );

echo "Box office shifts: immutability and corrections\n";
$as( 501, $cashier );
$again = Shifts::close( $s1->session_id, array( 'reason' => 'x' ) );
$late_count = Shifts::submit_count( $s1->session_id, array( 'cash_minor' => 1 ) );
$late_move = Shifts::cash_movement( $s1->session_id, 'cash_in', 100, 'nachträglich' );
check( 'a closed shift accepts no close, count or movement; there is one Z per shift', is_wp_error( $again ) && is_wp_error( $late_count ) && is_wp_error( $late_move ) && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_boxoffice_zreports WHERE shift_id=" . (int) $s1->session_id ) );
$wpdb->query( "UPDATE {$p}wpcap_boxoffice_zreports SET net_total_minor=net_total_minor+1 WHERE z_id=" . (int) $z1->z_id );
$tampered = ZReport::verify( $r1->register_id );
$wpdb->query( "UPDATE {$p}wpcap_boxoffice_zreports SET net_total_minor=net_total_minor-1 WHERE z_id=" . (int) $z1->z_id );
check( 'editing a Z report row is detected by the hash chain', isset( $tampered[ (int) $z1->z_id ] ) && array() === ZReport::verify( $r1->register_id ) );
$denied_correction = Shifts::correction( $z1->z_id, -50, 'Zählfehler' );
$reset();
$correction = Shifts::correction( $z1->z_id, -50, 'Zählfehler beim Abschluss' );
check( 'corrections are new movements in the open shift (administrators only); the Z report is unchanged', is_wp_error( $denied_correction ) && ! is_wp_error( $correction ) && 'correction' === $correction->kind && (int) $s2->session_id === (int) $correction->shift_id && (int) $z1->z_id === (int) $correction->z_id && -50 === Pos::session_totals( $s2->session_id )['cash']['corrections'] && $z1_hash === ZReport::get( $z1->z_id )->hash );

// Close the second shift: Z 2 continues numbering and grand total.
$as( 503, $cashier );
$exp2 = ZReport::compute( $s2->session_id );
Shifts::submit_count( $s2->session_id, array( 'cash_minor' => $exp2['cash']['expected'], 'card_minor' => 0 ) );
Shifts::close( $s2->session_id );
$z2 = ZReport::for_shift( $s2->session_id );
check( 'Z 2 of the register: number, previous hash and cumulative grand total', 2 === (int) $z2->z_number && $z2->prev_hash === $z1->hash && (int) $z2->grand_total_minor === 5590 + (int) $z2->net_total_minor && (int) $z2->net_total_minor === $exp2['net_total'] && array() === ZReport::verify( $r1->register_id ) );
$as( 505, $cashier );
Pos::close_session( $s_r2b->session_id, ZReport::compute( $s_r2b->session_id )['cash']['expected'] );
$reset();

echo "Box office shifts: gapless Z numbers per register under concurrency\n";
$r3 = Registers::save( array( 'name' => 'Race A', 'blind_count' => 0 ) );
$r4 = Registers::save( array( 'name' => 'Race B', 'blind_count' => 0 ) );
$procs = array();
foreach ( array( 601, 602, 603, 604, 605, 606 ) as $i => $uid ) {
	$reg = $i < 3 ? $r3->register_id : $r4->register_id;
	$procs[] = array( $reg, popen( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' cycles ' . (int) $reg . ' ' . $uid . ' 4 2>&1', 'r' ) );
}
$closes = array( (int) $r3->register_id => 0, (int) $r4->register_id => 0 );
foreach ( $procs as list( $reg, $proc ) ) { $out = (string) stream_get_contents( $proc ); pclose( $proc ); if ( preg_match( '/closes=(\d+)/', $out, $m ) ) { $closes[ $reg ] += (int) $m[1]; } }
$numbers = static function ( $reg ) use ( $wpdb, $p ) { return array_map( 'intval', $wpdb->get_col( "SELECT z_number FROM {$p}wpcap_boxoffice_zreports WHERE register_id=" . (int) $reg . ' ORDER BY z_number' ) ); };
$n3 = $numbers( $r3->register_id );
$n4 = $numbers( $r4->register_id );
check( "parallel open/close cycles on two registers give gapless Z numbers per register ({$closes[$r3->register_id]} + {$closes[$r4->register_id]} closes)", 12 === $closes[ (int) $r3->register_id ] && 12 === $closes[ (int) $r4->register_id ] && range( 1, 12 ) === $n3 && range( 1, 12 ) === $n4 && array() === ZReport::verify( $r3->register_id ) && array() === ZReport::verify( $r4->register_id ) );
$as( 607, $cashier );
$race = Shifts::open( $r3->register_id, array( 'float_minor' => 0 ) );
Shifts::submit_count( $race->session_id, array( 'cash_minor' => 0 ) );
$procs = array();
for ( $i = 0; $i < 5; $i++ ) { $procs[] = popen( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' close ' . (int) $race->session_id . ' 607 2>&1', 'r' ); }
$ok = 0;
foreach ( $procs as $proc ) { $ok += 'OK' === substr( trim( (string) stream_get_contents( $proc ) ), -2 ) ? 1 : 0; pclose( $proc ); }
check( "closing the same shift in parallel writes exactly one Z report ($ok of 5 succeeded)", 1 === $ok && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_boxoffice_zreports WHERE shift_id=" . (int) $race->session_id ) && range( 1, 13 ) === $numbers( $r3->register_id ) );
$reset();

echo "Box office shifts: admin screens render\n";
$render = static function ( array $get ) {
	$_GET = $get;
	ob_start();
	try { PassCaptain\BoxOffice\Page::render(); } catch ( Throwable $e ) { ob_end_clean(); return 'EXCEPTION ' . $e->getMessage(); }
	return (string) ob_get_clean();
};
$as( 801, $cashier );
$open_screen = $render( array( 'page' => 'wpcap-boxoffice' ) );
$s_ui = Shifts::open( $r3->register_id, array( 'float_minor' => 1000 ) );
$sale_screen = $render( array( 'page' => 'wpcap-boxoffice', 'occurrence_id' => $occ ) );
$shift_screen = $render( array( 'page' => 'wpcap-boxoffice', 'tab' => 'shift' ) );
$reset();
$list_screen = $render( array( 'page' => 'wpcap-boxoffice', 'tab' => 'shifts' ) );
$reg_screen = $render( array( 'page' => 'wpcap-boxoffice', 'tab' => 'registers' ) );
$_GET = array();
foreach ( array( 'open' => $open_screen, 'sale' => $sale_screen, 'shift' => $shift_screen, 'list' => $list_screen, 'reg' => $reg_screen ) as $k => $v ) { if ( strlen( $v ) < 400 || false !== strpos( $v, 'EXCEPTION' ) ) { echo "DEBUG $k: " . substr( $v, 0, 600 ) . "\n"; } }
check( 'open, sale, shift, shift list and register screens render', false !== strpos( $open_screen, 'Schicht öffnen' ) && false !== strpos( $open_screen, 'denoms_open[5000]' ) && false !== strpos( $sale_screen, 'Verkauf buchen' ) && false !== strpos( $sale_screen, 'Race A' ) && false !== strpos( $shift_screen, 'Zählung abgeben' ) && false !== strpos( $shift_screen, 'Einlage' ) && false !== strpos( $list_screen, 'Z-Berichte' ) && false !== strpos( $list_screen, 'lückenlos' ) && false !== strpos( $reg_screen, 'Kasse Foyer' ) );
$as( 801, $cashier );
Pos::close_session( $s_ui->session_id, 1000 );
$reset();

echo "Box office shifts: migration of legacy cash sessions\n";
$mig_default = Registers::save( array( 'name' => 'Migrationskasse', 'blind_count' => 0 ) );
$old_default = get_option( Registers::DEFAULT_OPTION );
update_option( Registers::DEFAULT_OPTION, (int) $mig_default->register_id );
$legacy = static function ( array $row ) use ( $wpdb, $p ) {
	$wpdb->insert( "{$p}wpcap_boxoffice_sessions", array_merge( array( 'register_id' => 0, 'currency' => 'EUR', 'opening_float_minor' => 0, 'note' => '', 'closing_note' => '', 'closed_by' => 0 ), $row ) );
	return (int) $wpdb->insert_id;
};
$legacy_totals = static fn( $cash, $card ) => wp_json_encode( array( 'methods' => array( 'cash' => array( 'count' => 2, 'gross' => $cash, 'voided_count' => 0, 'voided' => 0, 'net' => $cash ), 'card' => array( 'count' => 1, 'gross' => $card, 'voided_count' => 0, 'voided' => 0, 'net' => $card ) ), 'expected_cash' => 5000 + $cash, 'net_total' => $cash + $card, 'sales' => 3, 'voided' => 0, 'currency' => 'EUR' ) );
$l1 = $legacy( array( 'user_id' => 701, 'opening_float_minor' => 5000, 'closing_count_minor' => 6000, 'expected_cash_minor' => 6000, 'difference_minor' => 0, 'totals' => $legacy_totals( 1000, 500 ), 'status' => 'closed', 'opened_at' => gmdate( 'Y-m-d H:i:s', time() - 4 * 86400 ), 'closed_at' => gmdate( 'Y-m-d H:i:s', time() - 3 * 86400 ), 'closed_by' => 701 ) );
$l2 = $legacy( array( 'user_id' => 702, 'opening_float_minor' => 5000, 'closing_count_minor' => 5190, 'expected_cash_minor' => 5200, 'difference_minor' => -10, 'totals' => $legacy_totals( 200, 0 ), 'closing_note' => 'Rundung', 'status' => 'closed', 'opened_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * 86400 ), 'closed_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * 86400 + 3600 ), 'closed_by' => 702 ) );
$l3 = $legacy( array( 'user_id' => 703, 'open_key' => 703, 'opening_float_minor' => 3000, 'status' => 'open', 'opened_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ) );
$l4 = $legacy( array( 'user_id' => 704, 'open_key' => 704, 'opening_float_minor' => 1000, 'status' => 'open', 'opened_at' => gmdate( 'Y-m-d H:i:s', time() - 1800 ) ) );
$wpdb->insert( "{$p}wpcap_boxoffice_sales", array( 'session_id' => $l1, 'order_id' => null, 'form_key_hash' => hash( 'sha256', 'p3-legacy-' . uniqid() ), 'receipt_number' => 'LEG-' . uniqid(), 'payment_method' => 'cash', 'currency' => 'EUR', 'total_minor' => 1000, 'occurrence_id' => $occ, 'cashier_id' => 701, 'status' => 'completed', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 4 * 86400 ) ) );
$legacy_sale = (int) $wpdb->insert_id;
$GLOBALS['wpcap_test_options']['wpcap_boxoffice_migration'] = 1;
$first = BoxOfficeModule::maybe_migrate();
$second = BoxOfficeModule::maybe_migrate();
$sess = static fn( $id ) => Shifts::get( $id );
$zl1 = ZReport::for_shift( $l1 );
$zl2 = ZReport::for_shift( $l2 );
check( 'migration runs once (option version 2)', true === $first && false === $second && 2 === (int) get_option( 'wpcap_boxoffice_migration' ) );
check( 'closed legacy sessions are mapped to the default register with Z reports in closing order', (int) $mig_default->register_id === (int) $sess( $l1 )->register_id && (int) $mig_default->register_id === (int) $sess( $l2 )->register_id && 1 === (int) $zl1->z_number && 2 === (int) $zl2->z_number && 'migration' === $zl1->source && 1500 === (int) $zl1->grand_total_minor && 1700 === (int) $zl2->grand_total_minor && -10 === (int) $zl2->cash_difference_minor && 'Rundung' === $zl2->difference_reason && 702 === (int) $zl2->closed_by && array() === ZReport::verify( $mig_default->register_id ) );
$l4_register = Registers::get( $sess( $l4 )->register_id );
check( 'open legacy sessions stay open: the first on the default register, further ones on own registers', 'open' === $sess( $l3 )->status && (int) $mig_default->register_id === (int) $sess( $l3 )->open_register && (int) $l3 === (int) Shifts::open_for_register( $mig_default->register_id )->session_id && $l4_register && 'Kasse 2 (übernommen)' === $l4_register->name && (int) $l4_register->register_id === (int) $sess( $l4 )->open_register && (int) $l3 === (int) Pos::open_session_for( 703 )->session_id );
check( 'legacy data is kept (floats, counts, totals, sales)', 5000 === (int) $sess( $l1 )->opening_float_minor && 6000 === (int) $sess( $l1 )->closing_count_minor && $legacy_totals( 1000, 500 ) === $sess( $l1 )->totals && (int) $l1 === (int) Pos::sale( $legacy_sale )->session_id && 3000 === (int) $sess( $l3 )->opening_float_minor );
BoxOfficeModule::migrate();
check( 'migration is idempotent', 2 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_boxoffice_zreports WHERE register_id=" . (int) $mig_default->register_id ) && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_boxoffice_sessions WHERE register_id=0" ) );
// Close the migrated open sessions so later tests start from a clean state.
foreach ( array( 703 => $l3, 704 => $l4 ) as $uid => $sid ) {
	$as( $uid, $cashier );
	Pos::close_session( $sid, ZReport::compute( $sid )['cash']['expected'] );
}
$reset();
update_option( Registers::DEFAULT_OPTION, $old_default );

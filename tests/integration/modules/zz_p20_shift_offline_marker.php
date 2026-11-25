<?php
/**
 * A527 follow-up (Pass wave 5, item 6): the cash bookings of a shift carry the same offline marker as
 * the sale tab (BoxOffice\Page::ONLINE_ONLY, decision E-13). pos.js disables every form with
 * data-wpcap-pos-online while the browser is offline and shows the same banner text
 * (window.wpcapPosOffline); nothing is queued. Marked: opening a shift, accepting a handover, cash
 * in/out, count, closing (with its confirm prompt) and the Z correction. Not marked: adding a cashier
 * and the register filter (no money is booked).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\BoxOffice\Page;
use PassCaptain\BoxOffice\Registers;
use PassCaptain\BoxOffice\Shifts;
use PassCaptain\Modules\BoxOffice as BoxOfficeModule;
use PassCaptain\Settings;

BoxOfficeModule::register();
$p20_user = $GLOBALS['wpcap_test_user_id'] ?? null;
$p20_denied = $GLOBALS['wpcap_test_denied_caps'] ?? array();
$p20_get = $_GET;
$p20_saved = array( 'boxoffice_cash_confirmed' => Settings::get( 'boxoffice_cash_confirmed' ) );
Settings::update( array( 'boxoffice_cash_confirmed' => 1 ) );
$GLOBALS['wpcap_test_denied_caps'] = array();

$p20_page = static function ( array $get ) {
	$_GET = $get;
	ob_start();
	try { Page::render(); } catch ( Throwable $e ) { ob_end_clean(); return 'EXCEPTION ' . $e->getMessage(); }
	return (string) ob_get_clean();
};
/** Opening tags of all POST forms by their admin-post action: action => list of opening tags. */
$p20_forms = static function ( $html ) {
	preg_match_all( '/(<form[^>]*>)\s*<input type="hidden" name="action" value="([a-z0-9_]+)"/', $html, $m, PREG_SET_ORDER );
	$out = array();
	foreach ( $m as $row ) { $out[ $row[2] ][] = $row[1]; }
	return $out;
};
$p20_marked = static function ( array $forms, $action ) {
	if ( empty( $forms[ $action ] ) ) { return false; }
	foreach ( $forms[ $action ] as $tag ) { if ( false === strpos( $tag, Page::ONLINE_ONLY ) ) { return false; } }
	return true;
};

echo "A527 follow-up: shift bookings carry the offline marker\n";
check( 'marker constant is unchanged (pos.js selects form[data-wpcap-pos-online])', 'data-wpcap-pos-online="1"' === Page::ONLINE_ONLY );
Registers::default_id( 'EUR' );
$p20_reg = Registers::save( array( 'name' => 'Kasse P20 Offline', 'blind_count' => 0 ) );
check( 'setup: own register', is_object( $p20_reg ) );

$GLOBALS['wpcap_test_user_id'] = 2071;
$p20_html = $p20_page( array( 'tab' => 'sale' ) );
$p20_f = $p20_forms( $p20_html );
check( 'sale tab without a shift: "Schicht öffnen" (opening float) is marked', $p20_marked( $p20_f, 'wpcap_pos_open' ) && false === strpos( $p20_html, 'EXCEPTION' ) );
check( 'sale tab: the offline texts are passed to pos.js (same banner text as the sale form)', false !== strpos( $p20_html, 'window.wpcapPosOffline=' ) && false !== strpos( $p20_html, 'Keine Netzverbindung' ) );

$p20_shift = Shifts::open( $p20_reg->register_id, array( 'float_minor' => 0 ) );
check( 'setup: shift opened', is_object( $p20_shift ) && 'open' === $p20_shift->status );
$p20_html = $p20_page( array( 'tab' => 'shift' ) );
$p20_f = $p20_forms( $p20_html );
check( 'shift tab: cash in/out form is marked', $p20_marked( $p20_f, 'wpcap_pos_movement' ) );
check( 'shift tab: count form is marked', $p20_marked( $p20_f, 'wpcap_pos_count' ) );
check( 'shift tab: adding a cashier books no money and stays unmarked', ! empty( $p20_f['wpcap_pos_cashier'] ) && false === strpos( $p20_f['wpcap_pos_cashier'][0], 'data-wpcap-pos-online' ) );
check( 'shift tab: the offline guard is loaded with the same texts', false !== strpos( $p20_html, 'window.wpcapPosOffline=' ) && false !== strpos( $p20_html, 'Ohne Netzverbindung gesperrt' ) );

$p20_count = Shifts::submit_count( $p20_shift->session_id, array( 'cash_minor' => 0, 'card_minor' => null ) );
check( 'setup: count submitted', ! is_wp_error( $p20_count ) );
$p20_html = $p20_page( array( 'tab' => 'shift' ) );
$p20_f = $p20_forms( $p20_html );
check( 'counting: recount form is marked', $p20_marked( $p20_f, 'wpcap_pos_count' ) );
check( 'counting: closing form is marked and keeps its confirm prompt', $p20_marked( $p20_f, 'wpcap_pos_close' ) && false !== strpos( $p20_f['wpcap_pos_close'][0], 'data-wpcap-confirm=' ) );

$p20_closed = Shifts::close( $p20_shift->session_id, array( 'handover_to' => 2072, 'handover_minor' => 0 ) );
check( 'setup: shift closed with handover to the next cashier', ! is_wp_error( $p20_closed ) );
$GLOBALS['wpcap_test_user_id'] = 2072;
$p20_html = $p20_page( array( 'tab' => 'sale' ) );
$p20_f = $p20_forms( $p20_html );
check( 'next cashier: accepting the handover is marked', $p20_marked( $p20_f, 'wpcap_pos_handover' ) );

$GLOBALS['wpcap_test_user_id'] = 2071;
$p20_html = $p20_page( array( 'tab' => 'shifts' ) );
$p20_f = $p20_forms( $p20_html );
check( 'shifts tab: Z correction (new cash booking) is marked', $p20_marked( $p20_f, 'wpcap_pos_correction' ) );
check( 'shifts tab: the register filter (GET) carries no marker', 1 === preg_match( '/<form method="get"><input type="hidden" name="page"/', $p20_html ) );

// Clean up: cancel the open handover so later modules find the register free.
foreach ( Shifts::handovers_for( 2072 ) as $p20_h ) { if ( (int) $p20_h->register_id === (int) $p20_reg->register_id ) { Shifts::cancel_handover( (int) $p20_h->handover_id, 'p20 test' ); } }
Settings::update( $p20_saved );
$_GET = $p20_get;
$GLOBALS['wpcap_test_user_id'] = $p20_user;
$GLOBALS['wpcap_test_denied_caps'] = $p20_denied;

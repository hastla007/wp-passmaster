<?php
/**
 * Box office ↔ fiscalization:
 * - A257: the "keine Registrierkasse" notice disappears once a fiscal integration signs the receipts.
 * - A527 / E-13: money-booking forms carry the offline marker for pos.js, the offline texts are passed on.
 * - Phase 3: shift close → provider closing (setting boxoffice_fiscal_closing, default off) and the
 *   "Fiskalisierung" section of the Z report (Fiscalization\ShiftClosing). Scripted adapter, no provider call.
 * Cleans up its sellers/profiles at the end, so later modules see no POS fiscal profile.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

require_once dirname( __DIR__ ) . '/lib/fixtures_shared.php';
require_once dirname( __DIR__ ) . '/lib/fixtures_fiscal.php';

use PassCaptain\BoxOffice\Page as W13Page;
use PassCaptain\BoxOffice\Service as W13Pos;
use PassCaptain\BoxOffice\ZReport as W13Z;
use PassCaptain\Fiscalization\AdapterInterface;
use PassCaptain\Fiscalization\ClosingAdapterInterface;
use PassCaptain\Fiscalization\ShiftClosing;
use PassCaptain\Modules\Fiscalization as W13Fiscal;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;
require_once WPCAP_PATH . 'src/functions.php';
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) {} }
if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $a = -1 ) { return 'n'; } }
if ( ! function_exists( 'get_user_meta' ) ) { function get_user_meta( $u, $k, $single = false ) { return ''; } }

/** Receipts like x5_test, plus a scriptable shift closing. */
final class W13_Closing_Adapter implements AdapterInterface, ClosingAdapterInterface {
	public function id() { return 'w13_closing'; }
	public function interface_version() { return AdapterInterface::VERSION; }
	public function countries() { return array( 'AT', 'DE' ); }
	public function capabilities() { return array( 'receipt_submit', 'operation_status', ClosingAdapterInterface::CAPABILITY ); }
	public function health_check( array $seller, array $configuration ) { return array( 'ok' => true ); }
	public function query_status( array $document, array $seller, array $configuration ) {
		return isset( $GLOBALS['w13']['refs'][ $document['idempotency_key'] ] ) ? array( 'status' => 'submitted', 'reference' => $GLOBALS['w13']['refs'][ $document['idempotency_key'] ] ) : array( 'status' => 'not_found' );
	}
	public function submit( array $document, array $seller, array $configuration ) {
		$ref = 'W13-' . substr( $document['idempotency_key'], 0, 8 );
		$GLOBALS['w13']['refs'][ $document['idempotency_key'] ] = $ref;
		return array( 'reference' => $ref, 'receipt' => array( 'qr_payload' => 'QR', 'fields' => array() ) );
	}
	public function close_shift( array $closing, array $seller, array $configuration ) {
		$GLOBALS['w13']['closings'][] = array( $closing, $seller, $configuration );
		if ( 'error' === $GLOBALS['w13']['mode'] ) { return new WP_Error( 'provider_down', 'down' ); }
		if ( 'throw' === $GLOBALS['w13']['mode'] ) { throw new RuntimeException( 'socket' ); }
		return array( 'reference' => 'Z-' . substr( $closing['idempotency_key'], 0, 8 ), 'signature' => 'SIG-' . $closing['z_number'], 'fields' => array( 'Abschluss-Nr.' => '7', 'bad' => array( 'x' ) ) );
	}
}
$GLOBALS['w13'] = array( 'refs' => array(), 'closings' => array(), 'mode' => 'ok' );
add_filter( 'wpcap_fiscal_adapters', static function ( $adapters ) { $adapters = (array) $adapters; $adapters['w13_closing'] = new W13_Closing_Adapter(); return $adapters; }, 20, 1 );
function w13_events( $z_id ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_fiscal_events WHERE event_type='shift_closing' AND metadata LIKE %s ORDER BY event_id", '%"z_id":' . (int) $z_id . ',%' ) );
}
function w13_close( $session_id ) {
	$r = W13Pos::close_session( $session_id, W13Z::compute( $session_id )['cash']['expected'] );
	return is_wp_error( $r ) ? $r : W13Z::for_shift( $session_id );
}
function w13_notice() { ob_start(); W13Page::render_fiscal_notice(); return (string) ob_get_clean(); }

$GLOBALS['wpcap_test_user_id'] = 7131;
$w13_denied = $GLOBALS['wpcap_test_denied_caps'] ?? array();
$GLOBALS['wpcap_test_denied_caps'] = array();
// The fiscal module hooks (registered once by fiscalization.php in a full run; Fiscal::register() also
// registers ShiftClosing).
$w13_registered = false;
foreach ( $GLOBALS['wpcap_test_hooks']['wpcap_order_creating'] ?? array() as $w13_cbs ) {
	foreach ( $w13_cbs as $w13_cb ) { if ( array( W13Fiscal::class, 'snapshot_order' ) === $w13_cb[0] ) { $w13_registered = true; } }
}
if ( ! $w13_registered ) { W13Fiscal::register(); }
check( 'Fiscalization::register() hooks the shift close and the Z report extra HTML', has_action( 'wpcap_boxoffice_shift_closed' ) && has_filter( 'wpcap_boxoffice_zreport_html_extra' ) );
$w13_module = in_array( 'PassCaptain\\Modules\\Fiscalization', PassCaptain\Modules::classes(), true );

echo "Box office: fiscal notice (A257)\n";
check( 'A257: without a fiscal integration the "keine Registrierkasse" notice is shown', str_contains( w13_notice(), 'keine Registrierkasse' ) && false === W13Pos::fiscal_ready() );
add_filter( 'wpcap_boxoffice_fiscal_ready', $w13_ready = static function () { return true; } );
check( 'A257: filter wpcap_boxoffice_fiscal_ready = true hides the notice', '' === w13_notice() && true === W13Pos::fiscal_ready() );
remove_filter( 'wpcap_boxoffice_fiscal_ready', $w13_ready );

// Seller A: closing-capable adapter; seller B: x5_test (no closing). Both with an active POS profile for the register.
$w13_a = x5_seller( 'W13 Kasse A GmbH' );
$w13_b = x5_seller( 'W13 Kasse B GmbH' );
W13Fiscal::rest_save_provider( x5_rest( 'PUT', array( 'seller_id' => $w13_a ), array( 'provider_id' => 'w13_closing', 'enabled' => true, 'configuration' => array( 'cashbox' => 'CB-1' ) ) ) );
W13Fiscal::rest_save_provider( x5_rest( 'PUT', array( 'seller_id' => $w13_b ), array( 'provider_id' => 'x5_test', 'enabled' => true, 'configuration' => array( 'region' => 'eu' ) ) ) );
$w13_pa = x5_profile( $w13_a, 'pos', 'active', 31, 'BOX-W13' );
$w13_pb = x5_profile( $w13_b, 'pos', 'active', 31, 'BOX-W13' );
check( 'setup: two sellers with active production POS profiles for location 31 / BOX-W13', $w13_a && $w13_b && ! is_wp_error( $w13_pa ) && ! is_wp_error( $w13_pb ) );
if ( $w13_module ) {
	check( 'A257: an active production POS fiscal profile (pos_fiscalization_ready) hides the notice', '' === w13_notice() && W13Pos::pos_fiscalization_ready() );
}
$w13_type = make_type( 0, 10, 1500 );
$w13_occ = wpb_occurrence_of( $w13_type );
check( 'setup: ticket type mapped to seller A', true === W13Fiscal::assign_ticket_type( $w13_type, $w13_a, 'platform_seller' ) );
$w13_saved = array( 'boxoffice_terminals' => Settings::get( 'boxoffice_terminals', '' ), 'boxoffice_cash_confirmed' => Settings::get( 'boxoffice_cash_confirmed', 0 ), 'boxoffice_fiscal_closing' => Settings::get( 'boxoffice_fiscal_closing', 0 ) );
Settings::update( array( 'boxoffice_terminals' => '', 'boxoffice_cash_confirmed' => 1 ) );

// Own register for location 31 / BOX-W13 without event binding (open_session() prefers the matching register).
PassCaptain\BoxOffice\Registers::default_id( 'EUR' ); // The main register must exist first, or this module's register would become it.
$w13_register = PassCaptain\BoxOffice\Registers::save( array( 'name' => 'W13 Kasse', 'location_id' => 31, 'fiscal_register_id' => 'BOX-W13', 'currency' => 'EUR', 'blind_count' => 0 ) );
check( 'setup: register for location 31 / BOX-W13', is_object( $w13_register ) );

echo "Box office: offline guard markers (A527 / E-13)\n";
$w13_s0 = W13Pos::open_session( 0, 'EUR', '', 31, 'BOX-W13' );
$w13_sale0 = W13Pos::sell( array( 'occurrence_id' => $w13_occ, 'quantities' => array( $w13_type => 1 ), 'method' => 'cash', 'form_key' => wpb_key() ) );
$_GET = array( 'occurrence_id' => $w13_occ );
ob_start();
try { W13Page::render(); } catch ( \Throwable $e ) { echo 'ERR ' . $e->getMessage(); }
$w13_html = (string) ob_get_clean();
$_GET = array();
check( 'A527: sale and storno forms carry data-wpcap-pos-online (pos.js disables them offline)', ! is_wp_error( $w13_s0 ) && ! is_wp_error( $w13_sale0 ) && 1 === preg_match( '/<form[^>]*id="wpcap-pos-form"[^>]*data-wpcap-pos-online="1"/', $w13_html ) && 1 === preg_match( '/<form[^>]*data-wpcap-pos-online="1"[^>]*data-wpcap-confirm=/', $w13_html ) && ! str_contains( $w13_html, 'ERR ' ) );
check( 'A527: forms that do not book money (shift search, occurrence choice) carry no marker', 1 === preg_match( '/<form method="get"><input type="hidden" name="page"/', $w13_html ) );
if ( ! function_exists( 'wp_add_inline_script' ) ) {
	ob_start(); W13Page::enqueue_offline_guard(); $w13_js = (string) ob_get_clean();
	check( 'A527: offline texts are passed to pos.js as window.wpcapPosOffline (German, no "Sie")', str_contains( $w13_js, 'window.wpcapPosOffline=' ) && str_contains( $w13_js, 'Keine Netzverbindung' ) && str_contains( $w13_js, 'Scanner-App' ) && str_contains( $w13_js, 'Wieder online' ) && ! preg_match( '/\bSie\b/', $w13_js ) );
}

echo "Box office: shift closing and fiscalization (setting off = default)\n";
check( 'setting boxoffice_fiscal_closing defaults to off and is registered', 0 === (int) PassCaptain\Modules\BoxOffice::settings_defaults()['boxoffice_fiscal_closing'] && isset( PassCaptain\Modules\BoxOffice::settings_schema()['boxoffice_fiscal_closing'] ) && false === ShiftClosing::enabled() );
$w13_z0 = w13_close( $w13_s0->session_id );
check( 'off: shift closes, no provider closing call, no closing event', is_object( $w13_z0 ) && array() === $GLOBALS['w13']['closings'] && array() === w13_events( $w13_z0->z_id ) );
$w13_zh0 = W13Z::html( $w13_z0 );
check( 'off: Z report shows the fiscal section with seller, provider, receipts and "Nicht angefordert"', str_contains( $w13_zh0, '<h2>Fiskalisierung</h2>' ) && str_contains( $w13_zh0, 'W13 Kasse A GmbH #' . $w13_a ) && str_contains( $w13_zh0, 'w13_closing' ) && str_contains( $w13_zh0, '1 bestätigt, 0 offen' ) && str_contains( $w13_zh0, 'Nicht angefordert' ) );
check( 'shift without fiscal receipts or closing events: Z report unchanged (no fiscal section)', '' === ShiftClosing::zreport_html( '', (object) array( 'z_id' => 999999, 'shift_id' => 999999 ) ) );

echo "Box office: shift closing and fiscalization (setting on)\n";
Settings::update( array( 'boxoffice_fiscal_closing' => 1 ) );
$w13_s1 = W13Pos::open_session( 0, 'EUR', '', 31, 'BOX-W13' );
$w13_sale1 = W13Pos::sell( array( 'occurrence_id' => $w13_occ, 'quantities' => array( $w13_type => 1 ), 'method' => 'cash', 'form_key' => wpb_key() ) );
$w13_doc1 = ! is_wp_error( $w13_sale1 ) ? x5_docs( (int) $w13_sale1['sale']->order_id ) : array();
$w13_z1 = w13_close( $w13_s1->session_id );
$w13_c = $GLOBALS['w13']['closings'];
check( 'on: exactly one closing call (seller A) with the stable idempotency key per Z report and seller', is_object( $w13_z1 ) && 1 === count( $w13_c ) && ShiftClosing::idempotency_key( $w13_z1, $w13_a ) === $w13_c[0][0]['idempotency_key'] && $w13_a === (int) $w13_c[0][1]['seller_id'] && 'CB-1' === ( $w13_c[0][2]['cashbox'] ?? '' ) );
check( 'on: closing payload carries Z number/hash, register scope, period and the seller\'s confirmed receipts', is_object( $w13_z1 ) && $w13_c && (int) $w13_z1->z_number === $w13_c[0][0]['z_number'] && $w13_z1->hash === $w13_c[0][0]['z_hash'] && 31 === $w13_c[0][0]['location_id'] && 'BOX-W13' === $w13_c[0][0]['fiscal_register_id'] && 1 === $w13_c[0][0]['documents']['submitted'] && 1500 === $w13_c[0][0]['seller_gross_minor'] && $w13_doc1 && array( $w13_doc1[0]->document_key ) === $w13_c[0][0]['document_keys'] && 'EUR' === $w13_c[0][0]['currency'] );
$w13_ev = is_object( $w13_z1 ) ? ShiftClosing::events_for( $w13_z1->z_id ) : array();
check( 'on: fiscal event log has "submitted" with reference/signature for A and "unsupported" for B (no call)', 'submitted' === ( $w13_ev[ $w13_a ]['status'] ?? '' ) && str_starts_with( (string) ( $w13_ev[ $w13_a ]['metadata']['reference'] ?? '' ), 'Z-' ) && 'unsupported' === ( $w13_ev[ $w13_b ]['status'] ?? '' ) && 'x5_test' === ( $w13_ev[ $w13_b ]['provider_id'] ?? '' ) && array( 'Abschluss-Nr.' => '7' ) === ( $w13_ev[ $w13_a ]['metadata']['fields'] ?? null ) );
$w13_zh1 = is_object( $w13_z1 ) ? W13Z::html( $w13_z1 ) : '';
check( 'on: Z report shows status, closing reference, signature and provider fields; B "Vom Anbieter nicht unterstützt"', str_contains( $w13_zh1, 'Übermittelt' ) && str_contains( $w13_zh1, (string) ( $w13_ev[ $w13_a ]['metadata']['reference'] ?? 'x' ) ) && str_contains( $w13_zh1, 'SIG-' . ( is_object( $w13_z1 ) ? (int) $w13_z1->z_number : 0 ) ) && str_contains( $w13_zh1, 'Abschluss-Nr.' ) && str_contains( $w13_zh1, 'Vom Anbieter nicht unterstützt' ) && str_contains( $w13_zh1, 'W13 Kasse B GmbH' ) );
if ( is_object( $w13_z1 ) ) { do_action( 'wpcap_boxoffice_shift_closed', $w13_z1->z_id, $w13_z1, PassCaptain\BoxOffice\Shifts::get( $w13_s1->session_id ) ); }
check( 'on: a repeated hook does not close seller A a second time', 1 === count( $GLOBALS['w13']['closings'] ) );
check( 'audit: successful closing recorded', 1 <= (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='fiscal_shift_closing_submitted' AND object_id=%d", $w13_s1->session_id ) ) );

echo "Box office: shift closing and fiscalization (provider errors)\n";
$GLOBALS['w13']['mode'] = 'error';
$w13_s2 = W13Pos::open_session( 0, 'EUR', '', 31, 'BOX-W13' );
$w13_z2 = w13_close( $w13_s2->session_id );
$w13_ev2 = is_object( $w13_z2 ) ? ShiftClosing::events_for( $w13_z2->z_id ) : array();
check( 'error: the shift stays closed, event "failed" with the error code, audited; Z report shows it', is_object( $w13_z2 ) && 'closed' === PassCaptain\BoxOffice\Shifts::get( $w13_s2->session_id )->status && 'failed' === ( $w13_ev2[ $w13_a ]['status'] ?? '' ) && 'provider_down' === ( $w13_ev2[ $w13_a ]['metadata']['error_code'] ?? '' ) && 1 <= (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='fiscal_shift_closing_failed' AND object_id=%d", $w13_s2->session_id ) ) && str_contains( W13Z::html( $w13_z2 ), 'Fehlgeschlagen (provider_down)' ) );
check( 'error: a shift without receipts is still closed for the profile sellers (zero closing)', is_object( $w13_z2 ) && 0 === end( $GLOBALS['w13']['closings'] )[0]['documents']['submitted'] && array() === end( $GLOBALS['w13']['closings'] )[0]['document_keys'] );
$GLOBALS['w13']['mode'] = 'throw';
$w13_s3 = W13Pos::open_session( 0, 'EUR', '', 31, 'BOX-W13' );
$w13_z3 = w13_close( $w13_s3->session_id );
$w13_ev3 = is_object( $w13_z3 ) ? ShiftClosing::events_for( $w13_z3->z_id ) : array();
check( 'exception in the adapter: caught, recorded as failed (provider_exception), shift closed', is_object( $w13_z3 ) && 'failed' === ( $w13_ev3[ $w13_a ]['status'] ?? '' ) && 'provider_exception' === ( $w13_ev3[ $w13_a ]['metadata']['error_code'] ?? '' ) );
$GLOBALS['w13']['mode'] = 'ok';

// Cleanup: no POS fiscal profile, closing off, notice visible again for later modules.
Settings::update( $w13_saved );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_fiscal_profiles WHERE seller_id IN (%d,%d)", $w13_a, $w13_b ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_fiscal_configs SET enabled=0 WHERE seller_id IN (%d,%d)", $w13_a, $w13_b ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_ticket_sellers WHERE ticket_type_id=%d", $w13_type ) );
if ( is_object( $w13_register ) ) { PassCaptain\BoxOffice\Registers::save( array( 'name' => 'W13 Kasse', 'status' => 'inactive' ), (int) $w13_register->register_id ); }
check( 'cleanup: notice is back once no fiscal profile is active', str_contains( w13_notice(), 'keine Registrierkasse' ) );
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = $w13_denied;

<?php
/**
 * Box office: asynchronous card-terminal payments (contract DeviceGatewayInterface v1, filter
 * wp_pass_captain_pos_payment_devices_v1, Service::confirm_device_payment()).
 *
 * A scriptable fake device covers start/poll/cancel, success, failure, timeout, cancel (also refused),
 * duplicate and conflicting confirmations, webhook confirmation, the fiscal gate (stop_sale) and the
 * ajax endpoints' permissions. Default (no device registered): POS unchanged.
 * Included from run.php after zzzzz_x5_fiscal_reconcile.php (uses its fiscal test adapter helpers).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

require_once dirname( __DIR__ ) . '/lib/fixtures_shared.php';
require_once dirname( __DIR__ ) . '/lib/fixtures_fiscal.php';

use PassCaptain\BoxOffice\DeviceGatewayInterface;
use PassCaptain\BoxOffice\Devices;
use PassCaptain\BoxOffice\Page as X5dPage;
use PassCaptain\BoxOffice\Service as X5dPos;
use PassCaptain\Modules\BoxOffice as X5dModule;
use PassCaptain\Orders;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;
require_once WPCAP_PATH . 'src/functions.php';
if ( ! function_exists( 'get_user_meta' ) ) { function get_user_meta( $u, $k, $single = false ) { return ''; } }
if ( ! function_exists( 'wp_add_inline_script' ) ) { function wp_add_inline_script( $h, $js, $pos = 'after' ) { return true; } }

/** Scriptable terminal: records calls; answers from its public properties. */
final class X5d_Device implements DeviceGatewayInterface {
	public $calls = array();
	public $state = array();
	public $start_status = 'pending';
	public $start_error = false;
	public $cancel_error = false;
	public $poll_throws = false;
	public $last_sale = array();
	public function label(): string { return 'Terminal Eingang'; }
	public function start( array $sale ) {
		$this->calls[] = array( 'start', $sale['sale_key'] );
		$this->last_sale = $sale;
		if ( $this->start_error ) { return new WP_Error( 'x5d_offline', 'Terminal offline' ); }
		$ref = 'tx_' . substr( $sale['sale_key'], 0, 10 );
		$this->state[ $ref ] = $this->start_status;
		return array( 'reference' => $ref, 'status' => $this->start_status );
	}
	public function poll( string $reference ) {
		$this->calls[] = array( 'poll', $reference );
		if ( $this->poll_throws ) { throw new RuntimeException( 'socket timeout' ); }
		$status = $this->state[ $reference ] ?? 'pending';
		return array( 'status' => $status, 'provider_reference' => 'succeeded' === $status ? 'ch_' . $reference : '', 'message' => 'failed' === $status ? 'Karte abgelehnt' : '' );
	}
	public function cancel( string $reference ) {
		$this->calls[] = array( 'cancel', $reference );
		if ( $this->cancel_error ) { return new WP_Error( 'x5d_busy', 'Karte bereits vorgelegt' ); }
		$this->state[ $reference ] = 'cancelled';
		return true;
	}
	public function count( $kind ) { return count( array_filter( $this->calls, static fn( $c ) => $c[0] === $kind ) ); }
}

$x5d_hooks = array();
foreach ( array( 'started', 'completed', 'failed' ) as $x5d_h ) {
	add_action( 'wp_pass_captain_pos_device_payment_' . $x5d_h . '_v1', static function ( ...$args ) use ( $x5d_h ) { $GLOBALS['x5d_hooks'][ $x5d_h ][] = $args; }, 10, 4 );
}
$GLOBALS['x5d_hooks'] = array();
$GLOBALS['x5d_device'] = new X5d_Device();
$GLOBALS['x5d_enabled'] = false;
add_filter( Devices::FILTER, static function ( $devices, $context ) {
	if ( ! $GLOBALS['x5d_enabled'] ) { return $devices; }
	$devices['fake'] = $GLOBALS['x5d_device'];
	// Array form, restricted to another register: never offered here.
	$devices['other'] = array( 'label' => 'Andere Kasse', 'start' => static fn() => false, 'poll' => static fn() => false, 'cancel' => static fn() => false, 'registers' => array( '99|ELSEWHERE' ) );
	$devices['Bad Id!'] = $GLOBALS['x5d_device'];
	return $devices;
}, 10, 2 );
function x5d_sale_row( $key ) { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_boxoffice_sales WHERE form_key_hash=%s", hash( 'sha256', 'pos-sale|' . $key ) ) ); }
function x5d_sell( $occ, $type, $key, $method = 'card_device', $extra = array() ) {
	return X5dPos::sell( $extra + array( 'occurrence_id' => $occ, 'quantities' => array( $type => 1 ), 'method' => $method, 'device' => 'fake', 'form_key' => $key ) );
}
function x5d_render( $get ) {
	$_GET = $get;
	ob_start();
	try { X5dPage::render(); } catch ( \Throwable $e ) { echo 'ERR ' . $e->getMessage(); }
	$_GET = array();
	return (string) ob_get_clean();
}
function x5d_ajax( $method, $sale_id ) {
	$_REQUEST = $_POST = array( 'sale_id' => $sale_id, '_ajax_nonce' => 'n' );
	try { X5dPage::$method(); $r = null; } catch ( Wpmt_Json_Response $e ) { $r = $e; } catch ( \Throwable $e ) { $r = $e; }
	$_REQUEST = $_POST = array();
	return $r;
}

echo "Box office: card terminals – default without a registered device\n";
$GLOBALS['wpcap_test_user_id'] = 5151;
Settings::update( array( 'boxoffice_terminals' => '' ) );
$x5d_type = make_type( 30, 10, 1200 );
$x5d_occ = wpb_occurrence_of( $x5d_type );
$x5d_session = X5dPos::open_session( 0, 'EUR', '', 23, 'BOX-D' );
check( 'no device registered: no devices, methods unchanged, card_device is refused', ! is_wp_error( $x5d_session ) && array() === Devices::for_session( $x5d_session ) && ! isset( X5dPos::methods()['card_device'] ) && 'wpcap_pos_method' === ( ( $e = x5d_sell( $x5d_occ, $x5d_type, wpb_key() ) ) instanceof WP_Error ? $e->get_error_code() : '' ) );
$x5d_html = x5d_render( array( 'occurrence_id' => $x5d_occ ) );
check( 'no device registered: the POS form offers the four known methods only (no terminal select)', str_contains( $x5d_html, 'value="invoice"' ) && ! str_contains( $x5d_html, 'card_device' ) && ! str_contains( $x5d_html, 'wpcap-pos-device' ) );
$x5d_cash = x5d_sell( $x5d_occ, $x5d_type, wpb_key(), 'cash' );
check( 'no device registered: cash sales are booked as before', ! is_wp_error( $x5d_cash ) && 'completed' === $x5d_cash['sale']->status && '' === (string) $x5d_cash['sale']->device_status && empty( $x5d_cash['device_pending'] ) );

echo "Box office: card terminals – registry and start\n";
$GLOBALS['x5d_enabled'] = true;
$x5d_dev = $GLOBALS['x5d_device'];
check( 'registry: valid instances only (bad ids and devices for other registers are dropped)', array( 'fake' ) === array_keys( Devices::for_session( $x5d_session ) ) );
Settings::update( array( 'boxoffice_terminals' => "24|BOX-OTHER|Andere" ) );
check( 'registry: no devices for a register the terminal registry does not allow', array() === Devices::for_session( $x5d_session ) );
Settings::update( array( 'boxoffice_terminals' => '' ) );
$x5d_html = x5d_render( array( 'occurrence_id' => $x5d_occ ) );
check( 'POS form offers "card_device" with a labelled terminal select', str_contains( $x5d_html, 'value="card_device"' ) && str_contains( $x5d_html, '<label for="wpcap-pos-device-select">' ) && str_contains( $x5d_html, 'Terminal Eingang' ) );
check( 'POS form input carries the device', 'fake' === X5dPage::sale_input( array( 'method' => 'card_device', 'device' => 'fake' ) )['device'] && 'card_device' === X5dPage::sale_input( array( 'method' => 'card_device' ) )['method'] );
check( 'unknown device id is refused', 'wpcap_pos_device' === ( ( $e = x5d_sell( $x5d_occ, $x5d_type, wpb_key(), 'card_device', array( 'device' => 'nope' ) ) ) instanceof WP_Error ? $e->get_error_code() : '' ) );

$x5d_avail = Orders::availability( $x5d_type );
$x5d_key = wpb_key();
$x5d_r = x5d_sell( $x5d_occ, $x5d_type, $x5d_key );
$x5d_row = x5d_sale_row( $x5d_key );
$x5d_order = $x5d_row ? Orders::get( $x5d_row->order_id ) : null;
check( 'start: the sale stays pending with the order hold, no receipt and no tickets', is_array( $x5d_r ) && ! empty( $x5d_r['device_pending'] ) && 'pending' === $x5d_row->status && 'pending' === $x5d_row->device_status && 'fake' === $x5d_row->device_id && null === $x5d_row->receipt_number && 'pending' === $x5d_order->payment_status && 0 === count( tickets( $x5d_row->order_id ) ) && $x5d_avail - 1 === Orders::availability( $x5d_type ) );
check( 'start: the device gets sale_key, amount, currency, order and register (v1 payload)', 1 === $x5d_dev->count( 'start' ) && $x5d_row->form_key_hash === $x5d_dev->last_sale['sale_key'] && 1200 === $x5d_dev->last_sale['amount_minor'] && 'EUR' === $x5d_dev->last_sale['currency'] && (int) $x5d_row->order_id === $x5d_dev->last_sale['order_id'] && 'BOX-D' === $x5d_dev->last_sale['register_id'] && 1 === $x5d_dev->last_sale['version'] && 'tx_' . substr( $x5d_row->form_key_hash, 0, 10 ) === $x5d_row->device_reference );
check( 'action started_v1 fires once with sale, device and reference', 1 === count( $GLOBALS['x5d_hooks']['started'] ?? array() ) && (int) $x5d_row->sale_id === $GLOBALS['x5d_hooks']['started'][0][0] && 'fake' === $GLOBALS['x5d_hooks']['started'][0][1] && $x5d_row->device_reference === $GLOBALS['x5d_hooks']['started'][0][2] );
$x5d_replay = x5d_sell( $x5d_occ, $x5d_type, $x5d_key );
check( 'resubmitting the form key does not start a second terminal payment (idempotent per sale key)', is_array( $x5d_replay ) && ! empty( $x5d_replay['device_pending'] ) && (int) $x5d_replay['sale']->sale_id === (int) $x5d_row->sale_id && 1 === $x5d_dev->count( 'start' ) );
$x5d_nach = X5dPos::finalize( $x5d_row->sale_id );
$x5d_close = X5dPos::close_session( $x5d_session->session_id, 0 );
check( '"Nachbuchen" and the cash closing are refused while the terminal payment is pending', is_wp_error( $x5d_nach ) && 'wpcap_pos_device_pending' === $x5d_nach->get_error_code() && 'paid' !== Orders::get( $x5d_row->order_id )->payment_status && is_wp_error( $x5d_close ) && 'wpcap_pos_device_pending' === $x5d_close->get_error_code() );
$x5d_state = X5dPos::poll_device_payment( $x5d_row->sale_id );
check( 'poll: pending while the terminal waits', is_array( $x5d_state ) && 'pending' === $x5d_state['status'] && 180 === $x5d_state['timeout'] && 1 === $x5d_dev->count( 'poll' ) );
$x5d_dev->poll_throws = true;
$x5d_state = X5dPos::poll_device_payment( $x5d_row->sale_id );
$x5d_dev->poll_throws = false;
check( 'poll: an exception of the device counts as pending (message, sale untouched)', is_array( $x5d_state ) && 'pending' === $x5d_state['status'] && '' !== $x5d_state['message'] && 'pending' === X5dPos::sale( $x5d_row->sale_id )->device_status );

echo "Box office: card terminals – success, duplicate and conflicting confirmations\n";
$x5d_dev->state[ $x5d_row->device_reference ] = 'succeeded';
$x5d_state = X5dPos::poll_device_payment( $x5d_row->sale_id );
$x5d_done = X5dPos::sale( $x5d_row->sale_id );
$x5d_order = Orders::get( $x5d_row->order_id );
check( 'poll succeeded: booked through the cash/card path (receipt number, paid order, tickets issued)', is_array( $x5d_state ) && 'succeeded' === $x5d_state['status'] && 'completed' === $x5d_done->status && 'succeeded' === $x5d_done->device_status && '' !== (string) $x5d_done->receipt_number && $x5d_done->receipt_number === $x5d_state['receipt'] && 'paid' === $x5d_order->payment_status && 'pos_card_device' === $x5d_order->payment_provider && 'ch_' . $x5d_row->device_reference === $x5d_order->provider_reference && 1 === count( tickets( $x5d_row->order_id, 'valid' ) ) && 1200 === (int) $x5d_done->total_minor );
check( 'action completed_v1 fires once with sale, order and provider reference', 1 === count( $GLOBALS['x5d_hooks']['completed'] ?? array() ) && (int) $x5d_row->order_id === $GLOBALS['x5d_hooks']['completed'][0][1] && 'ch_' . $x5d_row->device_reference === $GLOBALS['x5d_hooks']['completed'][0][2] );
$x5d_dup1 = X5dPos::confirm_device_payment( (string) $x5d_row->order_id, 'ch_' . $x5d_row->device_reference );
$x5d_dup2 = wpcap_confirm_pos_payment( (int) $x5d_row->order_id, $x5d_row->device_reference );
$x5d_dup3 = X5dPos::poll_device_payment( $x5d_row->sale_id );
check( 'duplicate confirmations (webhook with provider or start reference, another poll) return true and never book twice', true === $x5d_dup1 && true === $x5d_dup2 && 'succeeded' === $x5d_dup3['status'] && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_boxoffice_sales WHERE order_id=%d", $x5d_row->order_id ) ) && $x5d_done->receipt_number === X5dPos::sale( $x5d_row->sale_id )->receipt_number && 1 === count( tickets( $x5d_row->order_id ) ) && 1 === count( $GLOBALS['x5d_hooks']['completed'] ) );
$x5d_conflict = X5dPos::confirm_device_payment( $x5d_row->form_key_hash, 'ch_second_payment' );
check( 'a confirmation with a different reference after a confirmed payment is a 409 conflict (audited)', is_wp_error( $x5d_conflict ) && 'wpcap_pos_device_conflict' === $x5d_conflict->get_error_code() && 409 === $x5d_conflict->get_error_data()['status'] && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='pos_device_payment_conflict' AND object_id=" . (int) $x5d_row->order_id ) );
$x5d_unknown = X5dPos::confirm_device_payment( 'no-such-sale', 'x' );
$x5d_cash_confirm = X5dPos::confirm_device_payment( (string) $x5d_cash['sale']->order_id, 'x' );
$x5d_empty_ref = X5dPos::confirm_device_payment( (string) $x5d_row->order_id, '' );
check( 'confirm_device_payment(): unknown keys and non-terminal sales are 404, an empty reference 400', is_wp_error( $x5d_unknown ) && 404 === $x5d_unknown->get_error_data()['status'] && is_wp_error( $x5d_cash_confirm ) && 'wpcap_pos_device_sale' === $x5d_cash_confirm->get_error_code() && is_wp_error( $x5d_empty_ref ) && 400 === $x5d_empty_ref->get_error_data()['status'] );
check( 'audit trail: started and confirmed (with source) for the terminal sale', 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='pos_device_payment_started' AND object_id=" . (int) $x5d_row->order_id ) && false !== strpos( (string) $wpdb->get_var( "SELECT details FROM {$p}wpcap_audit_logs WHERE action='pos_device_payment_confirmed' AND object_id=" . (int) $x5d_row->order_id ), '"source":"poll"' ) );
check( 'session totals list the terminal sale under its own method', 1200 === X5dPos::session_totals( $x5d_session->session_id )['methods']['card_device']['net'] && 'Karte am Terminal (automatisch)' === X5dPos::method_label( 'card_device' ) );

echo "Box office: card terminals – failure, cancel, timeout\n";
$x5d_avail = Orders::availability( $x5d_type );
$x5d_fkey = wpb_key();
x5d_sell( $x5d_occ, $x5d_type, $x5d_fkey );
$x5d_frow = x5d_sale_row( $x5d_fkey );
$x5d_dev->state[ $x5d_frow->device_reference ] = 'failed';
$x5d_state = X5dPos::poll_device_payment( $x5d_frow->sale_id );
$x5d_frow = X5dPos::sale( $x5d_frow->sale_id );
check( 'failed payment: sale failed, order cancelled, hold released, reason shown', 'failed' === $x5d_state['status'] && str_contains( $x5d_state['message'], 'Karte abgelehnt' ) && 'failed' === $x5d_frow->status && 'failed' === $x5d_frow->device_status && 'cancelled' === Orders::get( $x5d_frow->order_id )->status && $x5d_avail === Orders::availability( $x5d_type ) && null === $x5d_frow->receipt_number );
check( 'action failed_v1 fires with the status and message', 'failed' === ( end( $GLOBALS['x5d_hooks']['failed'] )[1] ?? '' ) && (int) $x5d_frow->sale_id === end( $GLOBALS['x5d_hooks']['failed'] )[0] && 'Karte abgelehnt' === end( $GLOBALS['x5d_hooks']['failed'] )[2] );
$x5d_late = X5dPos::confirm_device_payment( (string) $x5d_frow->order_id, 'ch_late' );
check( 'a confirmation after failure never books (409 not pending, audited as late payment)', is_wp_error( $x5d_late ) && 'wpcap_pos_device_not_pending' === $x5d_late->get_error_code() && 'failed' === X5dPos::sale( $x5d_frow->sale_id )->status && 0 === count( tickets( $x5d_frow->order_id ) ) && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='pos_device_payment_late' AND object_id=" . (int) $x5d_frow->order_id ) );
$x5d_retry = x5d_sell( $x5d_occ, $x5d_type, wpb_key(), 'cash' );
check( 'after a failed terminal payment the cashier sells again with another method (new form key)', ! is_wp_error( $x5d_retry ) && 'completed' === $x5d_retry['sale']->status );
$x5d_resubmit = x5d_sell( $x5d_occ, $x5d_type, $x5d_fkey );
check( 'resubmitting the failed sale\'s form key is refused (no new terminal payment)', is_wp_error( $x5d_resubmit ) && 'wpcap_pos_sale_failed' === $x5d_resubmit->get_error_code() );

$x5d_ckey = wpb_key();
x5d_sell( $x5d_occ, $x5d_type, $x5d_ckey );
$x5d_crow = x5d_sale_row( $x5d_ckey );
$x5d_cancel = X5dPos::cancel_device_payment( $x5d_crow->sale_id );
$x5d_cancel2 = X5dPos::cancel_device_payment( $x5d_crow->sale_id );
check( 'cancel: terminal cancelled, sale failed, order cancelled; cancelling twice is harmless', is_array( $x5d_cancel ) && 'cancelled' === $x5d_cancel['status'] && 'cancelled' === $x5d_cancel2['status'] && 1 === $x5d_dev->count( 'cancel' ) && 'cancelled' === Orders::get( $x5d_crow->order_id )->status && 'failed' === X5dPos::sale( $x5d_crow->sale_id )->status && 'cancelled' === end( $GLOBALS['x5d_hooks']['failed'] )[1] );

$x5d_rkey = wpb_key();
x5d_sell( $x5d_occ, $x5d_type, $x5d_rkey );
$x5d_rrow = x5d_sale_row( $x5d_rkey );
$x5d_dev->cancel_error = true;
$x5d_refused = X5dPos::cancel_device_payment( $x5d_rrow->sale_id );
check( 'cancel refused by the terminal (card presented): error, the sale stays pending', is_wp_error( $x5d_refused ) && 'wpcap_pos_device_cancel' === $x5d_refused->get_error_code() && 'pending' === X5dPos::sale( $x5d_rrow->sale_id )->device_status && 'pending' === Orders::get( $x5d_rrow->order_id )->payment_status );
$x5d_dev->state[ $x5d_rrow->device_reference ] = 'succeeded';
$x5d_after = X5dPos::cancel_device_payment( $x5d_rrow->sale_id );
$x5d_dev->cancel_error = false;
check( 'cancel refused and the payment went through: the sale is booked instead', is_array( $x5d_after ) && 'succeeded' === $x5d_after['status'] && 'completed' === X5dPos::sale( $x5d_rrow->sale_id )->status );

$x5d_tkey = wpb_key();
x5d_sell( $x5d_occ, $x5d_type, $x5d_tkey );
$x5d_trow = x5d_sale_row( $x5d_tkey );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_boxoffice_sales SET device_started_at=%s WHERE sale_id=%d", gmdate( 'Y-m-d H:i:s', time() - 181 ), $x5d_trow->sale_id ) );
$x5d_cancels = $x5d_dev->count( 'cancel' );
$x5d_state = X5dPos::poll_device_payment( $x5d_trow->sale_id );
check( 'timeout: still pending after the timeout → cancelled on the terminal, sale failed (timeout), hold released', 'timeout' === $x5d_state['status'] && $x5d_cancels + 1 === $x5d_dev->count( 'cancel' ) && 'timeout' === X5dPos::sale( $x5d_trow->sale_id )->device_status && 'cancelled' === Orders::get( $x5d_trow->order_id )->status && 'timeout' === end( $GLOBALS['x5d_hooks']['failed'] )[1] );
$x5d_short = static fn() => 5;
add_filter( Devices::TIMEOUT_FILTER, $x5d_short );
check( 'timeout filter is clamped to 30–840 seconds', 30 === Devices::timeout( 'fake' ) );
remove_filter( Devices::TIMEOUT_FILTER, $x5d_short );

echo "Box office: card terminals – webhook confirmation, start variants\n";
$x5d_wkey = wpb_key();
x5d_sell( $x5d_occ, $x5d_type, $x5d_wkey );
$x5d_wrow = x5d_sale_row( $x5d_wkey );
$x5d_wh = X5dPos::confirm_device_payment( $x5d_wrow->form_key_hash, 'pi_webhook_1' );
$x5d_wdone = X5dPos::sale( $x5d_wrow->sale_id );
$x5d_wh2 = X5dPos::confirm_device_payment( $x5d_wrow->form_key_hash, 'pi_webhook_1' );
check( 'webhook confirmation by sale_key books the sale (reference stored, audited as webhook), repeated webhook is idempotent', true === $x5d_wh && true === $x5d_wh2 && 'completed' === $x5d_wdone->status && 'pi_webhook_1' === Orders::get( $x5d_wrow->order_id )->provider_reference && 1 === count( tickets( $x5d_wrow->order_id ) ) && false !== strpos( (string) $wpdb->get_var( "SELECT details FROM {$p}wpcap_audit_logs WHERE action='pos_device_payment_confirmed' AND object_id=" . (int) $x5d_wrow->order_id ), '"source":"webhook"' ) );
check( 'after the webhook the poll reports succeeded without asking the device again', 'succeeded' === X5dPos::poll_device_payment( $x5d_wrow->sale_id )['status'] );

$x5d_dev->start_error = true;
$x5d_skey = wpb_key();
$x5d_start_fail = x5d_sell( $x5d_occ, $x5d_type, $x5d_skey );
$x5d_dev->start_error = false;
$x5d_srow = x5d_sale_row( $x5d_skey );
check( 'start error: sale failed, order cancelled, error for the cashier', is_wp_error( $x5d_start_fail ) && 'wpcap_pos_device_failed' === $x5d_start_fail->get_error_code() && 'failed' === $x5d_srow->status && 'cancelled' === Orders::get( $x5d_srow->order_id )->status );
$x5d_dev->start_status = 'succeeded';
$x5d_now = x5d_sell( $x5d_occ, $x5d_type, wpb_key() );
$x5d_dev->start_status = 'pending';
check( 'start answering "succeeded" at once books the sale directly', is_array( $x5d_now ) && 'completed' === $x5d_now['sale']->status && empty( $x5d_now['device_pending'] ) );

echo "Box office: card terminals – POS status view and ajax endpoints\n";
$x5d_akey = wpb_key();
x5d_sell( $x5d_occ, $x5d_type, $x5d_akey );
$x5d_arow = x5d_sale_row( $x5d_akey );
$x5d_html = x5d_render( array( 'occurrence_id' => $x5d_occ, 'device_sale' => $x5d_arow->sale_id ) );
check( 'status view: "Am Terminal bezahlen …" in a polite live region with a cancel button', str_contains( $x5d_html, 'id="wpcap-pos-device-status" role="status" aria-live="polite"' ) && str_contains( $x5d_html, 'Am Terminal bezahlen' ) && str_contains( $x5d_html, 'id="wpcap-pos-device-cancel"' ) && str_contains( $x5d_html, 'wpcap_pos_device_cancel' ) );
$x5d_ok = x5d_ajax( 'ajax_device_status', $x5d_arow->sale_id );
check( 'ajax status (own sale): JSON success with the pending state', $x5d_ok instanceof Wpmt_Json_Response && true === $x5d_ok->success && 'pending' === $x5d_ok->payload['status'] );
$GLOBALS['wpcap_test_user_id'] = 5252;
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$x5d_polls = $x5d_dev->count( 'poll' );
$x5d_foreign = x5d_ajax( 'ajax_device_status', $x5d_arow->sale_id );
$x5d_foreign_cancel = x5d_ajax( 'ajax_device_cancel', $x5d_arow->sale_id );
$GLOBALS['wpcap_test_user_id'] = 5151;
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_orders' );
$x5d_nocap = x5d_ajax( 'ajax_device_cancel', $x5d_arow->sale_id );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'ajax: another cashier and users without wpcap_manage_orders get 403 and change nothing', $x5d_foreign instanceof Wpmt_Json_Response && false === $x5d_foreign->success && 403 === $x5d_foreign->payload['status'] && $x5d_foreign_cancel instanceof Wpmt_Json_Response && false === $x5d_foreign_cancel->success && $x5d_nocap instanceof Wpmt_Json_Response && 403 === $x5d_nocap->payload['status'] && $x5d_polls === $x5d_dev->count( 'poll' ) && 'pending' === X5dPos::sale( $x5d_arow->sale_id )->device_status );
$x5d_dev->state[ $x5d_arow->device_reference ] = 'succeeded';
$x5d_ok = x5d_ajax( 'ajax_device_status', $x5d_arow->sale_id );
check( 'ajax status after success: redirect to the booked sale (receipt/tickets view)', $x5d_ok instanceof Wpmt_Json_Response && 'succeeded' === $x5d_ok->payload['status'] && str_contains( (string) $x5d_ok->payload['redirect'], 'sale=' . (int) $x5d_arow->sale_id ) );

echo "Box office: card terminals – fiscal gate (stop_sale)\n";
if ( function_exists( 'x5_seller' ) ) {
	$x5d_seller = x5_seller( 'X5D Kassen GmbH' );
	PassCaptain\Modules\Fiscalization::rest_save_provider( x5_rest( 'PUT', array( 'seller_id' => $x5d_seller ), array( 'provider_id' => 'x5_test', 'enabled' => true, 'configuration' => array( 'region' => 'eu' ) ) ) );
	$x5d_ftype = make_type( 0, 10, 1500 );
	$x5d_focc = wpb_occurrence_of( $x5d_ftype );
	PassCaptain\Modules\Fiscalization::assign_ticket_type( $x5d_ftype, $x5d_seller, 'platform_seller' );
	x5_profile( $x5d_seller, 'pos', 'active', 23, 'BOX-D' );
	$GLOBALS['x5_fiscal']['fail'][ $x5d_seller ] = true;
	$x5d_gkey = wpb_key();
	x5d_sell( $x5d_focc, $x5d_ftype, $x5d_gkey );
	$x5d_grow = x5d_sale_row( $x5d_gkey );
	$x5d_gdocs = $x5d_grow ? x5_docs( (int) $x5d_grow->order_id ) : array();
	$x5d_gsubmits = array_filter( x5_calls( 'submit' ), static fn( $c ) => $x5d_gdocs && $c[1] === $x5d_gdocs[0]->document_key );
	check( 'fiscal: nothing is submitted to the fiscal provider or issued while the terminal payment is pending', $x5d_grow && 'pending' === $x5d_grow->device_status && ! array_filter( $x5d_gdocs, static fn( $d ) => 'submitted' === $d->status ) && ! $x5d_gsubmits && 0 === count( tickets( (int) $x5d_grow->order_id ) ) );
	$x5d_gconf = X5dPos::confirm_device_payment( (string) $x5d_grow->order_id, 'pi_fiscal' );
	$x5d_grow = X5dPos::sale( $x5d_grow->sale_id );
	$x5d_gstate = X5dPos::poll_device_payment( $x5d_grow->sale_id );
	check( 'fiscal provider down: payment confirmed, but no tickets and no receipt number; POS shows "unbooked" with the fiscal message', true === $x5d_gconf && 'succeeded' === $x5d_grow->device_status && 'pending' === $x5d_grow->status && '' === (string) $x5d_grow->receipt_number && 0 === count( tickets( (int) $x5d_grow->order_id ) ) && 'unbooked' === $x5d_gstate['status'] && str_contains( $x5d_gstate['message'], 'Fiskalbeleg' ) );
	$x5d_gclose = X5dPos::close_session( $x5d_session->session_id, 0 );
	check( 'fiscal: the session cannot be closed while the paid terminal sale is unbooked', is_wp_error( $x5d_gclose ) && 'wpcap_pos_unbooked' === $x5d_gclose->get_error_code() );
	$GLOBALS['x5_fiscal']['fail'][ $x5d_seller ] = false;
	$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_fiscal_documents SET next_attempt_at=%s WHERE order_id=%d", gmdate( 'Y-m-d H:i:s', time() - 60 ), (int) $x5d_grow->order_id ) );
	$x5d_gstate = X5dPos::poll_device_payment( $x5d_grow->sale_id );
	$x5d_grow = X5dPos::sale( $x5d_grow->sale_id );
	check( 'fiscal provider back: the next poll books the sale with tickets after the confirmed fiscal receipt', 'succeeded' === $x5d_gstate['status'] && 'completed' === $x5d_grow->status && 1 === count( tickets( (int) $x5d_grow->order_id ) ) && 'submitted' === x5_docs( (int) $x5d_grow->order_id )[0]->status && 'card' === ( json_decode( x5_docs( (int) $x5d_grow->order_id )[0]->snapshot, true )['payment_method'] ?? '' ) );
	$wpdb->query( "DELETE FROM {$p}wpcap_ticket_sellers WHERE ticket_type_id=" . (int) $x5d_ftype );
} else {
	check( 'fiscal test adapter helpers from zzzzz_x5_fiscal_reconcile.php are loaded', false );
}

echo "Box office: card terminals – closing and migration\n";
// Merged: a cash difference needs a reason (shift close), so the till is counted with the expected amount (two cash sales).
$x5d_closed = X5dPos::close_session( $x5d_session->session_id, PassCaptain\BoxOffice\ZReport::compute( $x5d_session->session_id )['cash']['expected'] );
check( 'with all terminal sales decided the session closes', ! is_wp_error( $x5d_closed ) && 'closed' === $x5d_closed->status );
$wpdb->query( "ALTER TABLE {$p}wpcap_boxoffice_sales DROP COLUMN device_started_at, DROP COLUMN device_message, DROP COLUMN device_status, DROP COLUMN device_reference, DROP COLUMN device_id" );
$GLOBALS['wpcap_test_options']['wpcap_boxoffice_migration'] = 2;
$x5d_migrated = X5dModule::maybe_migrate();
$x5d_cols = $wpdb->get_col( "SHOW COLUMNS FROM {$p}wpcap_boxoffice_sales LIKE 'device%'" );
check( 'migration 3 adds the terminal columns to existing installs (idempotent)', true === $x5d_migrated && 5 === count( $x5d_cols ) && true === X5dModule::migrate() && X5dModule::MIGRATION_VERSION === (int) get_option( 'wpcap_boxoffice_migration' ) ); // merged: version 4 (fiscal_register_id)

$GLOBALS['x5d_enabled'] = false;
$GLOBALS['wpcap_test_user_id'] = 1;
Settings::update( array( 'boxoffice_terminals' => '' ) );

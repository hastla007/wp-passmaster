<?php
/**
 * E18-1 (PASS-FE-17 remainder, owner decision 25.11.2026 "Zahlungslink"): the no-show fee of a service
 * booking is requested with a payment link by e-mail with a due date – status open/paid/cancelled, no
 * automatic debit and no dunning, staff actions in the no-show list (send, send again, cancel) with
 * rights, payment through the real gateways (Stripe, PayPal, Mollie: HTTP stubbed; bank transfer),
 * receipt/invoice like every order, late payment after the due date, overdue link, unmatched payment.
 * Runs alone (svc_* helpers from lib/fixtures_*.php).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

require_once dirname( __DIR__ ) . '/lib/fixtures_shared.php';

use PassCaptain\Admin\ServicesPage;
use PassCaptain\Mollie\MollieGateway;
use PassCaptain\Orders;
use PassCaptain\Payments\PayPalGateway;
use PassCaptain\Payments\Registry;
use PassCaptain\ServiceBookings;
use PassCaptain\Services\NoShowFees;
use PassCaptain\Services\Portal as SvcPortal;
use PassCaptain\Settings;

if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) {} }

svc_setup();
PassCaptain\Modules\Mollie::register();
// Invoice on payment (auto_invoice) as in a request (Invoices::register()); removed again at the end.
$nf_invoice_hook = array( PassCaptain\Invoices::class, 'maybe_auto_issue' );
$nf_added_hook = false === has_action( 'wpcap_order_paid', $nf_invoice_hook );
if ( $nf_added_hook ) { add_action( 'wpcap_order_paid', $nf_invoice_hook ); }
global $wpdb;
$p = $wpdb->prefix;
$nf_user = $GLOBALS['wpcap_test_user_id'] ?? null;
$nf_denied = $GLOBALS['wpcap_test_denied_caps'] ?? array();
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 1;
$nf_keys = array( 'stripe_secret_key', 'stripe_webhook_secret', 'paypal_mode', 'paypal_client_id', 'paypal_client_secret', 'paypal_webhook_id', 'mollie_api_key', 'enabled_providers', 'bank_iban', 'bank_recipient', 'auto_invoice' );
$nf_settings = array();
foreach ( $nf_keys as $k ) { $nf_settings[ $k ] = Settings::get( $k ); }
// Test isolation: the invoice counter is restored at the end (the module issues invoices).
$nf_sequences = (array) $wpdb->get_results( "SELECT * FROM {$p}wpcap_invoice_sequences", ARRAY_A );
Settings::update( array(
	'stripe_secret_key' => 'sk_test_nf', 'stripe_webhook_secret' => 'whsec_test',
	'paypal_mode' => 'sandbox', 'paypal_client_id' => 'client', 'paypal_client_secret' => 'secret', 'paypal_webhook_id' => 'WH-ID-1',
	'mollie_api_key' => 'test_' . str_repeat( 'Ab1', 10 ),
	'enabled_providers' => array( 'manual', 'stripe', 'paypal', 'mollie' ),
	'bank_iban' => 'AT611904300234573201', 'bank_recipient' => 'Segelschule Muster',
	'auto_invoice' => 1,
) );
Registry::reset();
wpcap_test_reset_transients();
http_reset();

list( $nf_event, $nf_type ) = svc_event( 4000 );
$nf_res = svc_resource( $nf_event, array( 'ticket_type_id' => $nf_type, 'capacity' => 20, 'rules' => array( 'noshow_policy' => 'forfeit', 'noshow_fee_minor' => 2500 ) ) );

/** Paid booking whose slot is over, marked as no-show (fee 25 €). Returns the booking id. */
function nf_noshow( $resource, $type, $email ) {
	global $wpdb;
	$slot = svc_slot( $resource, 48 + mt_rand( 1, 500 ) );
	list( $b ) = svc_paid( $slot, $type, $email );
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_service_slots SET starts_at=%s,ends_at=%s WHERE slot_id=%d", gmdate( 'Y-m-d H:i:s', time() - 7200 ), gmdate( 'Y-m-d H:i:s', time() - 3600 ), $slot ) );
	ServiceBookings::mark_no_show( $b );
	return (int) $b;
}
/** [fee_id, token] of the last payment link mailed to $email. */
function nf_link( $email ) {
	foreach ( array_reverse( $GLOBALS['wpcap_test_mails'] ) as $m ) {
		if ( $m['to'] === $email && preg_match( '/wpcap_noshow_fee=(\d+)(?:&amp;|&)token=([a-f0-9]{64})/', $m['body'], $mm ) ) { return array( (int) $mm[1], $mm[2] ); }
	}
	return array( 0, '' );
}
function nf_fee( $booking ) { return NoShowFees::for_booking( $booking ); }
function nf_pay( $fee_id, $token, $provider ) { return SvcPortal::perform( array( 'do' => 'noshow_fee_pay', 'id' => $fee_id, 'token' => $token, 'provider' => $provider ) ); }
function nf_send( $booking, $days = 14 ) { return ServicesPage::admin_action( array( 'do' => 'noshow_fee_send', 'id' => $booking, 'due_days' => $days, 'return_view' => 'noshow_fees' ) ); }
function nf_stripe_session( $id ) { http_push( array( 'id' => $id, 'url' => 'https://checkout.stripe.com/c/pay/' . $id, 'status' => 'open', 'amount_total' => 2500, 'currency' => 'eur' ), 200, 'checkout/sessions' ); }

echo "E18-1: no-show fee – list, rights, sending the payment link\n";
$nf_table = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', NoShowFees::table() ) );
check( 'module schema creates wpcap_service_noshow_fees (Services module, MANIFEST lists schema)', NoShowFees::table() === $nf_table && in_array( 'schema', PassCaptain\Modules::MANIFEST['Services'], true ) );
$b1 = nf_noshow( $nf_res, $nf_type, 'nf-stripe@svc.test' );
$nf_list = NoShowFees::listing( array( $nf_res ) );
$nf_row = array_values( array_filter( $nf_list, static fn( $r ) => (int) $r->booking_id === $b1 ) )[0] ?? null;
check( 'no-show with fee is listed as open and not sent yet (no fee row before the first action)', 'no_show' === svc_status( $b1 ) && 2500 === (int) ServiceBookings::get( $b1 )->fee_minor && $nf_row && null === $nf_row->fee_status && null === nf_fee( $b1 ) && 'open' === NoShowFees::status_of( ServiceBookings::get( $b1 ) ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_events' );
$nf_forbidden = nf_send( $b1 );
$nf_forbidden_cancel = ServicesPage::admin_action( array( 'do' => 'noshow_fee_cancel', 'id' => $b1 ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'without rights for the resource sending and cancelling are refused (403), nothing stored', is_wp_error( $nf_forbidden ) && 403 === (int) ( $nf_forbidden->get_error_data()['status'] ?? 0 ) && is_wp_error( $nf_forbidden_cancel ) && null === nf_fee( $b1 ) );
$nf_src = (string) file_get_contents( WPCAP_PATH . 'src/Admin/ServicesPage.php' );
check( 'staff actions go through the nonce-checked admin-post handler of the screen', 1 === preg_match( '/function handle_action\(\) \{\s*Forms::verify\( self::ACTION \);/', $nf_src ) && str_contains( $nf_src, "case 'noshow_fee_send':" ) && str_contains( $nf_src, 'self::managed_booking( $id )' ) );
$nf_bad_days = nf_send( $b1, 0 );
$nf_bad_days2 = nf_send( $b1, 91 );
check( 'due date must be 1–90 days', is_wp_error( $nf_bad_days ) && 'wpcap_noshow_fee_due_days' === $nf_bad_days->get_error_code() && is_wp_error( $nf_bad_days2 ) );
$nf_confirmed_slot = svc_slot( $nf_res, 30 );
list( $nf_confirmed ) = svc_paid( $nf_confirmed_slot, $nf_type, 'nf-confirmed@svc.test' );
$nf_not_noshow = nf_send( $nf_confirmed );
check( 'a booking without no-show fee gets no link', is_wp_error( $nf_not_noshow ) && 'wpcap_noshow_fee_missing' === $nf_not_noshow->get_error_code() );
$mails = count( $GLOBALS['wpcap_test_mails'] );
$nf_sent = nf_send( $b1, 10 );
$f1 = nf_fee( $b1 );
list( $f1_id, $t1 ) = nf_link( 'nf-stripe@svc.test' );
$nf_mail = svc_mails_to( 'nf-stripe@svc.test', $mails )[0] ?? array( 'body' => '', 'subject' => '' );
check( 'sending: fee row open with amount/currency frozen, due date in 10 days, link token stored as hash only', ! is_wp_error( $nf_sent ) && $f1 && 'open' === $f1->status && 2500 === (int) $f1->amount_minor && 'EUR' === $f1->currency && 1 === (int) $f1->sent_count && abs( strtotime( $f1->due_at . ' UTC' ) - ( time() + 10 * 86400 ) ) < 120 && $f1_id === (int) $f1->fee_id && hash( 'sha256', $t1 ) === $f1->token_hash );
check( 'mail service_noshow_fee_link with amount, due date and link; the admin result carries no link', str_contains( $nf_mail['subject'], 'No-Show-Gebühr' ) && str_contains( $nf_mail['body'], '25,00' ) && str_contains( $nf_mail['body'], 'bis ' ) && '' !== $t1 && ! isset( $nf_sent['pay_url'] ) && 'noshow_fees' === ( $nf_sent['return_view'] ?? '' ) && svc_audit( 'service_noshow_fee_sent', $b1 ) );
$nf_page = NoShowFees::page_html( $f1_id, $t1 );
check( 'link page: amount, due date and a POST form with the enabled payment methods', str_contains( $nf_page, '25,00' ) && str_contains( $nf_page, 'Zahlbar bis' ) && str_contains( $nf_page, 'name="do" value="noshow_fee_pay"' ) && str_contains( $nf_page, 'method="post"' ) && str_contains( $nf_page, 'value="stripe"' ) && str_contains( $nf_page, 'value="paypal"' ) && str_contains( $nf_page, 'value="mollie"' ) && str_contains( $nf_page, 'value="manual"' ) );
check( 'a wrong token shows no fee data', ! str_contains( NoShowFees::page_html( $f1_id, str_repeat( 'a', 64 ) ), '25,00' ) && is_wp_error( nf_pay( $f1_id, str_repeat( 'a', 64 ), 'stripe' ) ) );
nf_send( $b1, 10 );
list( , $t1b ) = nf_link( 'nf-stripe@svc.test' );
check( 'send again: new link, the earlier one stops working; counted and audited as resend', $t1b !== $t1 && ! str_contains( NoShowFees::page_html( $f1_id, $t1 ), '25,00' ) && str_contains( NoShowFees::page_html( $f1_id, $t1b ), '25,00' ) && 2 === (int) nf_fee( $b1 )->sent_count && true === (bool) ( json_decode( (string) svc_audit( 'service_noshow_fee_sent', $b1 )->details, true )['resent'] ?? false ) );

echo "E18-1: payment through Stripe (HTTP stubbed) – order, webhook, receipt and invoice\n";
http_reset();
nf_stripe_session( 'cs_nf_1' );
$nf_pay = nf_pay( $f1_id, $t1b, 'stripe' );
$f1 = nf_fee( $b1 );
$o1 = Orders::get( (int) $f1->order_id );
$o1_items = Orders::items_for_order( (int) $f1->order_id );
$o1_hold = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_reservations WHERE order_id=%d", (int) $f1->order_id ) );
$nf_req = form_body( http_requests( 'checkout/sessions' )[0] ?? array( 'body' => '' ) );
check( 'pay: payment order of 25 € with the fee line (quantity 0, no ticket) and a hold of 0 until the due date', ! is_wp_error( $nf_pay ) && $o1 && 2500 === (int) $o1->total_minor && 'stripe' === $o1->payment_provider && 'pending' === $o1->payment_status && 1 === count( $o1_items ) && 0 === (int) $o1_items[0]->quantity && $nf_type === (int) $o1_items[0]->ticket_type_id && $o1_hold && 0 === (int) $o1_hold->quantity && $o1_hold->expires_at === $f1->due_at && $f1_id === NoShowFees::fee_of_order( $o1 ) );
check( 'Stripe Checkout session for exactly the fee, the hosted page is the redirect', 'https://checkout.stripe.com/c/pay/cs_nf_1' === ( $nf_pay['noshow_fee']['payment']['redirect_url'] ?? '' ) && '2500' === (string) ( $nf_req['line_items'][0]['price_data']['unit_amount'] ?? '' ) && ( $o1->public_id ?? 'x' ) === ( $nf_req['metadata']['wpcap_order'] ?? '' ) && str_contains( (string) ( $nf_req['line_items'][0]['price_data']['product_data']['name'] ?? '' ), 'No-Show-Gebühr' ) && 'cs_nf_1' === Orders::get( $o1->order_id )->provider_reference );
http_reset();
nf_stripe_session( 'cs_nf_1b' );
$nf_pay_again = nf_pay( $f1_id, $t1b, 'stripe' );
check( 'paying again with the same method reuses the unpaid order (no second order)', ! is_wp_error( $nf_pay_again ) && (int) $nf_pay_again['noshow_fee']['order_id'] === (int) $o1->order_id && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_orders WHERE price_snapshot LIKE %s", '%"fee_id":' . $f1_id . ',%' ) ) );
$mails = count( $GLOBALS['wpcap_test_mails'] );
$nf_paid = stripe_deliver( Registry::get( 'stripe' ), stripe_session_event( 'evt_nf_1', 'checkout.session.completed', (array) Orders::get( $o1->order_id ) ) );
$f1 = nf_fee( $b1 );
$nf_paid_mail = svc_mails_to( 'nf-stripe@svc.test', $mails );
$nf_invoice = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_invoices WHERE order_id=%d", (int) $o1->order_id ) );
$nf_line = $nf_invoice ? (string) $wpdb->get_var( $wpdb->prepare( "SELECT description FROM {$p}wpcap_invoice_lines WHERE invoice_id=%d", (int) $nf_invoice->invoice_id ) ) : '';
check( 'Stripe webhook: order paid, fee paid (paid_order_id, paid_at), no ticket issued', 'paid' === $nf_paid && 'paid' === Orders::get( $o1->order_id )->payment_status && 'paid' === $f1->status && (int) $o1->order_id === (int) $f1->paid_order_id && null !== $f1->paid_at && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE order_id=%d", (int) $o1->order_id ) ) && svc_audit( 'service_noshow_fee_paid', $b1 ) );
check( 'receipt: invoice like every order (auto_invoice) with the line named as no-show fee; payment mail links the order and names the invoice', $nf_invoice && 2500 === (int) $nf_invoice->total_minor && str_contains( $nf_line, 'No-Show-Gebühr' ) && 1 === count( $nf_paid_mail ) && str_contains( $nf_paid_mail[0]['body'], 'wpcap_order=' . $o1->public_id ) && str_contains( $nf_paid_mail[0]['body'], (string) $nf_invoice->invoice_number ) );
$nf_dup = stripe_deliver( Registry::get( 'stripe' ), stripe_session_event( 'evt_nf_1', 'checkout.session.completed', (array) Orders::get( $o1->order_id ) ) );
Orders::confirm_external_payment( (int) $o1->order_id, 'again' );
check( 'redelivery and repeated confirmation change nothing (one paid audit, one mail)', 'duplicate' === $nf_dup && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='service_noshow_fee_paid' AND object_id=%d", $b1 ) ) && 1 === count( svc_mails_to( 'nf-stripe@svc.test', $mails ) ) );
$nf_resend_paid = nf_send( $b1 );
$nf_cancel_paid = ServicesPage::admin_action( array( 'do' => 'noshow_fee_cancel', 'id' => $b1 ) );
check( 'a paid fee can neither be sent nor cancelled; its link page says paid and offers no payment', is_wp_error( $nf_resend_paid ) && 'wpcap_noshow_fee_closed' === $nf_resend_paid->get_error_code() && is_wp_error( $nf_cancel_paid ) && ! str_contains( NoShowFees::page_html( $f1_id, $t1b ), '25,00' ) );

echo "E18-1: PayPal (capture on return) and Mollie (webhook), switching the method\n";
$b2 = nf_noshow( $nf_res, $nf_type, 'nf-paypal@svc.test' );
nf_send( $b2 );
list( $f2_id, $t2 ) = nf_link( 'nf-paypal@svc.test' );
http_reset();
http_push( array( 'access_token' => 'A21-nf', 'expires_in' => 32400, 'token_type' => 'Bearer' ), 200, '/v1/oauth2/token' );
http_push( array( 'id' => 'NFPAYPAL1', 'status' => 'PAYER_ACTION_REQUIRED', 'links' => array( array( 'rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=NFPAYPAL1' ) ) ), 201, '/v2/checkout/orders' );
$nf_pp = nf_pay( $f2_id, $t2, 'paypal' );
$o2 = Orders::get( (int) nf_fee( $b2 )->order_id );
$nf_pp_req = json_decode( (string) ( http_requests( '/v2/checkout/orders' )[0]['body'] ?? '' ), true );
check( 'PayPal order for the fee (custom_id = order), buyer is sent to the approval page', ! is_wp_error( $nf_pp ) && 'https://www.sandbox.paypal.com/checkoutnow?token=NFPAYPAL1' === ( $nf_pp['noshow_fee']['payment']['redirect_url'] ?? '' ) && '25.00' === ( $nf_pp_req['purchase_units'][0]['amount']['value'] ?? '' ) && $o2->public_id === ( $nf_pp_req['purchase_units'][0]['custom_id'] ?? '' ) );
http_push( array( 'id' => 'NFPAYPAL1', 'status' => 'COMPLETED', 'purchase_units' => array( array( 'reference_id' => $o2->public_id, 'payments' => array( 'captures' => array( array( 'id' => 'NFCAP1', 'status' => 'COMPLETED', 'custom_id' => $o2->public_id, 'amount' => array( 'currency_code' => 'EUR', 'value' => '25.00' ) ) ) ) ) ) ), 201, '/v2/checkout/orders/NFPAYPAL1/capture' );
$nf_pp_ret = PayPalGateway::handle_return( $o2->public_id, 'NFPAYPAL1' );
check( 'PayPal return captures (hold of the fee order still runs) → fee paid', 'paid' === $nf_pp_ret && 'paid' === nf_fee( $b2 )->status && (int) $o2->order_id === (int) nf_fee( $b2 )->paid_order_id );

$b3 = nf_noshow( $nf_res, $nf_type, 'nf-mollie@svc.test' );
nf_send( $b3 );
list( $f3_id, $t3 ) = nf_link( 'nf-mollie@svc.test' );
http_reset();
nf_stripe_session( 'cs_nf_3' );
nf_pay( $f3_id, $t3, 'stripe' );
$o3a = (int) nf_fee( $b3 )->order_id;
http_push( array( 'resource' => 'payment', 'id' => 'tr_nfmolaaa', 'status' => 'open', '_links' => array( 'checkout' => array( 'href' => 'https://www.mollie.com/checkout/select-method/tr_nfmolaaa' ) ) ), 201, '/v2/payments' );
$nf_mol = nf_pay( $f3_id, $t3, 'mollie' );
$o3 = Orders::get( (int) nf_fee( $b3 )->order_id );
check( 'switching the method cancels the unpaid order of the other method and starts a new one (Mollie hosted checkout)', ! is_wp_error( $nf_mol ) && 'cancelled' === Orders::get( $o3a )->status && (int) $o3->order_id !== $o3a && 'mollie' === $o3->payment_provider && 'https://www.mollie.com/checkout/select-method/tr_nfmolaaa' === ( $nf_mol['noshow_fee']['payment']['redirect_url'] ?? '' ) && 'tr_nfmolaaa' === $o3->provider_reference );
http_push( mollie_payment( 'tr_nfmolaaa', 'paid', (array) $o3 ), 200, 'payments/tr_nfmolaaa' );
$nf_mol_paid = mollie_deliver( Registry::get( 'mollie' ), 'tr_nfmolaaa' );
check( 'Mollie webhook (fetched by id) → fee paid', 'paid' === $nf_mol_paid && 'paid' === nf_fee( $b3 )->status && (int) $o3->order_id === (int) nf_fee( $b3 )->paid_order_id );

echo "E18-1: bank transfer, late payment after the due date, no dunning\n";
$b4 = nf_noshow( $nf_res, $nf_type, 'nf-bank@svc.test' );
nf_send( $b4, 7 );
list( $f4_id, $t4 ) = nf_link( 'nf-bank@svc.test' );
http_reset();
$nf_bank = nf_pay( $f4_id, $t4, 'manual' );
$nf_bank_details = $nf_bank['noshow_fee']['payment']['bank_transfer'] ?? array();
$nf_bank_html = NoShowFees::result_html( $nf_bank );
check( 'bank transfer: payment details with IBAN, reference and the fee due date (no HTTP call)', ! is_wp_error( $nf_bank ) && ! empty( $nf_bank_details['transfer'] ) && str_contains( (string) $nf_bank_details['iban'], 'AT61' ) && nf_fee( $b4 )->due_at === $nf_bank_details['due_at'] && str_contains( $nf_bank_html, 'AT61 1904' ) && ! $GLOBALS['wpcap_test_http_requests'] );
$o4 = (int) nf_fee( $b4 )->order_id;
Orders::confirm_manual_payment( $o4 );
check( 'staff confirm the transfer → fee paid', 'paid' === nf_fee( $b4 )->status && $o4 === (int) nf_fee( $b4 )->paid_order_id );

$b5 = nf_noshow( $nf_res, $nf_type, 'nf-late@svc.test' );
nf_send( $b5, 3 );
list( $f5_id, $t5 ) = nf_link( 'nf-late@svc.test' );
nf_pay( $f5_id, $t5, 'manual' );
$o5 = (int) nf_fee( $b5 )->order_id;
$nf_past = gmdate( 'Y-m-d H:i:s', time() - 60 );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_reservations SET expires_at=%s WHERE order_id=%d", $nf_past, $o5 ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_service_noshow_fees SET due_at=%s WHERE fee_id=%d", $nf_past, $f5_id ) );
Orders::expire_holds( 50, array( $o5 ) );
$nf_late_page = NoShowFees::page_html( $f5_id, $t5 );
$nf_late_pay = nf_pay( $f5_id, $t5, 'stripe' );
$mails = count( $GLOBALS['wpcap_test_mails'] );
svc_run_jobs();
ServiceBookings::sweep();
check( 'after the due date: the order expired, the link page says so and offers no payment; the fee stays open; no reminder mail (no dunning)', 'expired' === Orders::get( $o5 )->status && str_contains( $nf_late_page, 'Zahlungsfrist ist abgelaufen' ) && ! str_contains( $nf_late_page, 'noshow_fee_pay' ) && is_wp_error( $nf_late_pay ) && 'wpcap_noshow_fee_overdue' === $nf_late_pay->get_error_code() && 'open' === nf_fee( $b5 )->status && ! svc_mails_to( 'nf-late@svc.test', $mails ) );
// The price carrier (occurrence of the resource's ticket type) is over by now, too.
$nf_occ = $wpdb->get_row( $wpdb->prepare( "SELECT o.occurrence_id,o.starts_at,o.ends_at FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_ticket_types tt ON tt.occurrence_id=o.occurrence_id WHERE tt.ticket_type_id=%d", $nf_type ) );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() - 3 * 86400 ), 'ends_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * 86400 ) ), array( 'occurrence_id' => (int) $nf_occ->occurrence_id ) );
$nf_late = Orders::confirm_external_payment( $o5, 'late-transfer' );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => $nf_occ->starts_at, 'ends_at' => $nf_occ->ends_at ), array( 'occurrence_id' => (int) $nf_occ->occurrence_id ) );
check( 'a transfer that arrives late (order expired, price occurrence over) still settles the fee: a line without units needs no sale/capacity re-check, no review case', true === $nf_late && 'paid' === Orders::get( $o5 )->payment_status && 'paid' === nf_fee( $b5 )->status );

$b6 = nf_noshow( $nf_res, $nf_type, 'nf-overdue@svc.test' );
nf_send( $b6, 1 );
list( $f6_id, $t6 ) = nf_link( 'nf-overdue@svc.test' );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_service_noshow_fees SET due_at=%s WHERE fee_id=%d", $nf_past, $f6_id ) );
$nf_over_list = array_values( array_filter( NoShowFees::listing( array( $nf_res ), 'open' ), static fn( $r ) => (int) $r->booking_id === $b6 ) );
nf_send( $b6, 5 );
list( , $t6b ) = nf_link( 'nf-overdue@svc.test' );
http_reset();
nf_stripe_session( 'cs_nf_6' );
check( 'overdue fee stays in the open list; sending again sets a new due date and the new link can be paid', 1 === count( $nf_over_list ) && strtotime( nf_fee( $b6 )->due_at . ' UTC' ) > time() + 4 * 86400 && ! is_wp_error( nf_pay( $f6_id, $t6b, 'stripe' ) ) );

echo "E18-1: cancelling, unmatched payment, free resources\n";
$o6 = (int) nf_fee( $b6 )->order_id;
$nf_cancel = ServicesPage::admin_action( array( 'do' => 'noshow_fee_cancel', 'id' => $b6, 'reason' => 'Kulanz' ) );
check( 'cancel: fee cancelled, its unpaid order cancelled, link page says nothing to pay, paying refused, audited', ! is_wp_error( $nf_cancel ) && 'cancelled' === nf_fee( $b6 )->status && 'cancelled' === Orders::get( $o6 )->status && ! str_contains( NoShowFees::page_html( $f6_id, $t6b ), 'noshow_fee_pay' ) && is_wp_error( nf_pay( $f6_id, $t6b, 'stripe' ) ) && svc_audit( 'service_noshow_fee_cancelled', $b6 ) );
check( 'cancelling twice is idempotent; sending a cancelled fee is refused', ! is_wp_error( NoShowFees::cancel( $b6 ) ) && is_wp_error( nf_send( $b6 ) ) );
$b7 = nf_noshow( $nf_res, $nf_type, 'nf-unsent@svc.test' );
$nf_cancel_unsent = NoShowFees::cancel( $b7 );
check( 'a fee that was never sent can be cancelled (waived) as well', ! is_wp_error( $nf_cancel_unsent ) && 'cancelled' === nf_fee( $b7 )->status && 0 === (int) nf_fee( $b7 )->sent_count );

$b8 = nf_noshow( $nf_res, $nf_type, 'nf-unmatched@svc.test' );
nf_send( $b8 );
list( $f8_id, $t8 ) = nf_link( 'nf-unmatched@svc.test' );
http_reset();
nf_stripe_session( 'cs_nf_8' );
nf_pay( $f8_id, $t8, 'stripe' );
$o8 = Orders::get( (int) nf_fee( $b8 )->order_id );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_service_noshow_fees SET status='cancelled' WHERE fee_id=%d", $f8_id ) );
stripe_deliver( Registry::get( 'stripe' ), stripe_session_event( 'evt_nf_8', 'checkout.session.completed', (array) $o8 ) );
check( 'a payment for a fee that is no longer open is logged for a refund through the order (fee unchanged)', 'paid' === Orders::get( $o8->order_id )->payment_status && 'cancelled' === nf_fee( $b8 )->status && svc_audit( 'service_noshow_fee_payment_unmatched', (int) $o8->order_id ) );

list( $nf_free_event ) = svc_event( 0 );
$nf_free_res = svc_resource( $nf_free_event, array( 'ticket_type_id' => 0, 'rules' => array( 'noshow_policy' => 'forfeit', 'noshow_fee_minor' => 1000 ) ) );
$nf_free_slot = svc_slot( $nf_free_res, 30 );
$nf_free = svc_book( $nf_free_slot, 'nf-free@svc.test' );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_service_bookings SET status='confirmed',expires_at=NULL WHERE booking_id=%d", (int) $nf_free['booking_id'] ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_service_slots SET starts_at=%s,ends_at=%s WHERE slot_id=%d", gmdate( 'Y-m-d H:i:s', time() - 7200 ), gmdate( 'Y-m-d H:i:s', time() - 3600 ), $nf_free_slot ) );
ServiceBookings::mark_no_show( (int) $nf_free['booking_id'] );
$nf_free_send = nf_send( (int) $nf_free['booking_id'] );
check( 'free resource (no ticket type): no payment link, clear message', is_wp_error( $nf_free_send ) && 'wpcap_noshow_fee_free_resource' === $nf_free_send->get_error_code() );

echo "E18-1: admin list\n";
$b9 = nf_noshow( $nf_res, $nf_type, 'nf-open@svc.test' );
nf_send( $b9 );
$_GET = array( 'page' => 'wpcap-services', 'view' => 'noshow_fees' );
ob_start(); ServicesPage::render(); $nf_html = (string) ob_get_clean();
$_GET = array( 'page' => 'wpcap-services', 'view' => 'noshow_fees', 'fee_status' => 'paid' );
ob_start(); ServicesPage::render(); $nf_html_paid = (string) ob_get_clean();
$_GET = array();
check( 'no-show list: statuses, send/send again with due days and cancel as nonce forms; filter by status', str_contains( $nf_html, 'No-Show-Gebühren' ) && str_contains( $nf_html, 'value="noshow_fee_send"' ) && str_contains( $nf_html, 'value="noshow_fee_cancel"' ) && str_contains( $nf_html, 'name="due_days"' ) && str_contains( $nf_html, '_wpnonce' ) && str_contains( $nf_html, 'Erneut senden' ) && str_contains( $nf_html, 'Bezahlt' ) && str_contains( $nf_html, 'Storniert' ) && str_contains( $nf_html_paid, '#' . $b1 ) && ! str_contains( $nf_html_paid, '#' . $b6 . '<' ) );
$nf_core = (string) file_get_contents( WPCAP_PATH . 'src/Services/NoShowFees.php' );
check( 'no automatic debit and no dunning: no refunds, saved payment methods, cron hooks or reminder mails in the fee code', 0 === preg_match( '/->refund\(|Refunder::|wpcap_process_jobs|Jobs::enqueue|off_session|setup_future_usage|mandate/', $nf_core ) );

// Restore: invoices of the fee orders and the invoice counter (test isolation), settings.
$nf_invoices = array_map( 'intval', (array) $wpdb->get_col( "SELECT i.invoice_id FROM {$p}wpcap_invoices i INNER JOIN {$p}wpcap_orders o ON o.order_id=i.order_id WHERE o.price_snapshot LIKE '%\"noshow_fee\"%'" ) );
if ( $nf_invoices ) {
	$wpdb->query( "DELETE FROM {$p}wpcap_invoice_lines WHERE invoice_id IN (" . implode( ',', $nf_invoices ) . ')' );
	$wpdb->query( "DELETE FROM {$p}wpcap_invoices WHERE invoice_id IN (" . implode( ',', $nf_invoices ) . ')' );
}
$wpdb->query( "DELETE FROM {$p}wpcap_invoice_sequences" );
foreach ( $nf_sequences as $row ) { $wpdb->insert( "{$p}wpcap_invoice_sequences", $row ); }
Settings::update( $nf_settings );
if ( $nf_added_hook ) { remove_action( 'wpcap_order_paid', $nf_invoice_hook ); }
Registry::reset();
http_reset();
$GLOBALS['wpcap_test_denied_caps'] = $nf_denied;
$GLOBALS['wpcap_test_user_id'] = $nf_user;

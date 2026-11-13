<?php
/**
 * Phase 1 customer UX and tax quick fixes: checkout confirmation data (short order number, bank
 * transfer details), error-to-field mapping, {payment_instructions} in the access-link mail,
 * portal resend route with rate limit, portal page (short number, resend, confirm texts), event
 * card wording/formatting, VAT breakdown and "Rechnungskorrektur" on invoices, local invoice year,
 * box office fiscal notice.
 * Included from run.php after all module tests (uses check(), make_type(), admin_paid_order()).
 */
// phpcs:ignoreFile
use PassCaptain\Frontend\Format;
use PassCaptain\Invoices;
use PassCaptain\Mail\Mailer;
use PassCaptain\Orders;
use PassCaptain\OrderPortal;
use PassCaptain\Payments\BankTransfer;
use PassCaptain\Rest\Controller;
use PassCaptain\Settings;

if ( ! function_exists( 'bloginfo' ) ) { function bloginfo( $k = '' ) { echo 'UTF-8'; } }
if ( ! function_exists( 'esc_textarea' ) ) { function esc_textarea( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'checked' ) ) { function checked( $a, $b = true, $echo = true ) { $r = $a == $b ? ' checked="checked"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . ltrim( $p, '/' ); } }
if ( ! function_exists( 'wp_verify_nonce' ) ) { function wp_verify_nonce( $n, $a = -1 ) { return 'n' === $n ? 1 : false; } }
if ( ! function_exists( 'wp_nonce_url' ) ) { function wp_nonce_url( $u, $a = -1 ) { return $u . '&_wpnonce=n'; } }
if ( ! function_exists( 'get_user_meta' ) ) { function get_user_meta( $u, $k, $single = false ) { return $GLOBALS['p1u_user_meta'][ $u ][ $k ] ?? ''; } }
if ( ! function_exists( 'update_user_meta' ) ) { function update_user_meta( $u, $k, $v ) { $GLOBALS['p1u_user_meta'][ $u ][ $k ] = $v; return true; } }
if ( ! function_exists( 'locate_template' ) ) { function locate_template( $t ) { return ''; } }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) {} }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) {} }
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $t ) { return '<p>' . $t . '</p>'; } }

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();

function p1u_request( array $body, array $params = array() ) {
	$r = new WP_REST_Request( 'POST' );
	$r->set_body( wp_json_encode( $body ) );
	foreach ( $params as $k => $v ) { $r->set_param( $k, $v ); }
	return $r;
}
function p1u_data( $res ) { return $res instanceof WP_REST_Response ? $res->get_data() : $res; }
function p1u_access_token( $public_id ) {
	foreach ( array_reverse( $GLOBALS['wpcap_test_mails'] ) as $mail ) {
		if ( preg_match( '/wpcap_order=' . preg_quote( $public_id, '/' ) . '&wpcap_access=([a-f0-9]{64})/', $mail['body'], $m ) ) { return $m[1]; }
	}
	return '';
}

echo "Phase 1 UX: formatting helpers\n";
check( 'short order number from the public id', '3F2A-9C1E' === Format::order_reference( '3f2a9c1e5b7d4a6f8e0c2b4d6f8a0c2e' ) );
check( 'masked e-mail keeps the first letter and the domain', 'k***@example.com' === Format::mask_email( 'kundin@example.com' ) && '***' === Format::mask_email( 'kaputt' ) );
check( 'money uses number_format_i18n and the currency symbol', '1.234,50 €' === Format::money( 123450, 'EUR' ) && '9,00 CHF' === Format::money( 900, 'chf' ) && '5,00 XYZ' === Format::money( 500, 'XYZ' ) );
$GLOBALS['wpcap_test_options']['timezone_string'] = 'Europe/Vienna';
$GLOBALS['wpcap_test_options']['date_format'] = 'j. F Y';
$GLOBALS['wpcap_test_options']['time_format'] = 'G:i';
check( 'event start uses the site date/time format and hides the timezone when it matches the site', '15. January 2027 19:30' === Format::event_start( '2027-01-15 18:30:00', 'Europe/Vienna' ) && '15. January 2027 19:30' === Format::event_start( '2027-01-15 18:30:00', 'Europe/Berlin' ) );
check( 'a differing event timezone is named', '15. January 2027 13:30 (America/New_York)' === Format::event_start( '2027-01-15 18:30:00', 'America/New_York' ) );
unset( $GLOBALS['wpcap_test_options']['date_format'], $GLOBALS['wpcap_test_options']['time_format'] );

echo "Phase 1 UX: checkout response with bank transfer details\n";
Settings::update( array( 'bank_recipient' => 'Kulturverein Musterstadt', 'bank_iban' => 'at61 1904 3002 3457 3201', 'bank_bic' => 'bkauatww', 'manual_instructions' => '', 'enabled_providers' => array( 'manual' ), 'manual_hold_hours' => 48 ) );
$GLOBALS['wpcap_test_transients'] = array();
$mail_offset = count( $GLOBALS['wpcap_test_mails'] );
$type = make_type( 20, 10, 2500 );
$res = Controller::checkout( p1u_request( array( 'email' => 'bank@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 2 ) ), 'provider' => 'manual', 'idempotency_key' => 'p1u-bank-' . uniqid() ) ) );
$data = p1u_data( $res );
$bank = $data['payment']['bank_transfer'] ?? array();
$ref = Format::order_reference( $data['public_id'] ?? '' );
check( 'checkout returns the short order number and the formatted total', is_array( $data ) && $ref === ( $data['reference'] ?? '' ) && 9 === strlen( $ref ) && '50,00 €' === ( $data['total_formatted'] ?? '' ) );
check( 'manual payment: IBAN/BIC/recipient/reference/amount/due date in the response', 'AT61 1904 3002 3457 3201' === ( $bank['iban'] ?? '' ) && 'BKAUATWW' === ( $bank['bic'] ?? '' ) && 'Kulturverein Musterstadt' === ( $bank['recipient'] ?? '' ) && 'Bestellung ' . $ref === ( $bank['remittance'] ?? '' ) && '50,00 €' === ( $bank['amount'] ?? '' ) && '' !== ( $bank['due_date'] ?? '' ) && '' !== ( $bank['instructions'] ?? '' ) );
$found = PassCaptain\Admin\Queries::search_orders( array( 'search' => 'Bestellung ' . $ref ), null );
$found2 = PassCaptain\Admin\Queries::search_orders( array( 'search' => strtolower( str_replace( '-', '', $ref ) ) ), null );
check( 'admin order search finds the order by its short number / payment reference', in_array( $data['public_id'], array_column( $found['rows'], 'public_id' ), true ) && in_array( $data['public_id'], array_column( $found2['rows'], 'public_id' ), true ) );
$due = BankTransfer::due_at( $data['order_id'] );
check( 'due date is the end of the reservation (48 h)', '' !== $due && abs( strtotime( $due . ' UTC' ) - ( time() + 48 * 3600 ) ) < 120 );
check( 'the access token never travels in the checkout response', false === strpos( wp_json_encode( $data ), p1u_access_token( $data['public_id'] ) ) && '' !== p1u_access_token( $data['public_id'] ) );
$access_mail = array_values( array_filter( array_slice( $GLOBALS['wpcap_test_mails'], $mail_offset ), static fn( $m ) => 'bank@example.com' === $m['to'] && str_starts_with( $m['subject'], 'Deine Bestellung' ) ) )[0] ?? array( 'body' => '' );
check( 'access-link mail contains the payment instructions by default', false !== strpos( $access_mail['body'], "Zahlungsinformationen\nBetrag: 50,00 €\nEmpfänger: Kulturverein Musterstadt\nIBAN: AT61 1904 3002 3457 3201\nBIC: BKAUATWW\nVerwendungszweck: Bestellung " . $ref . "\nZahlbar bis: " ) && false !== strpos( $access_mail['body'], "verfällt die Reservierung.\n\nBewahre diesen Link sicher auf" ) );
check( 'payment instructions are empty once the order is paid', ( function () use ( $data ) { Orders::confirm_manual_payment( $data['order_id'] ); $vars = PassCaptain\Mail\Types::order_access_vars( $data['public_id'] ); return '' === $vars['payment_instructions']['text'] && Format::order_reference( $data['public_id'] ) === $vars['order_reference']; } )() );
check( 'order_access registry lists the new placeholders; preview sample shows the bank block', isset( Mailer::type( 'order_access' )['placeholders']['payment_instructions'], Mailer::type( 'order_access' )['placeholders']['order_reference'] ) && false !== strpos( (string) Mailer::text_body( Mailer::compose( 'order_access', call_user_func( Mailer::type( 'order_access' )['sample'] ) ) ), 'IBAN: AT61 1904 3002 3457 3201' ) );
Settings::update( array( 'bank_iban' => '' ) );
$no_iban = BankTransfer::details( Orders::get( $data['order_id'] ) );
check( 'without IBAN no bank rows (recipient/BIC) are shown, reference and amount stay', '' === $no_iban['iban'] && '' === $no_iban['bic'] && '' === $no_iban['recipient'] && ! isset( BankTransfer::rows( $no_iban )['IBAN'] ) && isset( BankTransfer::rows( $no_iban )['Verwendungszweck'] ) );

echo "Phase 1 UX: manual payment cannot block an event anonymously\n";
$GLOBALS['wpcap_test_transients'] = array();
$mh = array();
for ( $i = 0; $i < 3; $i++ ) {
	$mh[] = Controller::checkout( p1u_request( array( 'email' => 'hold-abuse@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'provider' => 'manual', 'idempotency_key' => 'p1u-hold-' . $i . uniqid() ) ) );
}
check( 'at most two unpaid manual orders per e-mail address', ! is_wp_error( $mh[0] ) && ! is_wp_error( $mh[1] ) && is_wp_error( $mh[2] ) && 'wpcap_manual_open_orders' === $mh[2]->get_error_code() );
$mh_client = array();
for ( $i = 0; $i < 4; $i++ ) {
	$mh_client[] = Controller::checkout( p1u_request( array( 'email' => 'hold-abuse-' . $i . '@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'provider' => 'manual', 'idempotency_key' => 'p1u-holdc-' . $i . uniqid() ) ) );
}
check( 'at most five manual orders per client within the hold time', is_wp_error( end( $mh_client ) ) && 'wpcap_rate_limited' === end( $mh_client )->get_error_code() );
foreach ( array_merge( $mh, $mh_client ) as $r ) { $r = p1u_data( $r ); if ( is_array( $r ) && ! empty( $r['order_id'] ) ) { Orders::cancel_order( $r['order_id'], 'test_cleanup' ); } }
$GLOBALS['wpcap_test_transients'] = array();

echo "Phase 1 UX: checkout errors carry the form field\n";
$GLOBALS['wpcap_test_transients'] = array();
$err = Controller::checkout( p1u_request( array( 'email' => 'kaputt', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'provider' => 'manual' ) ) );
check( 'invalid e-mail -> field "email"', is_wp_error( $err ) && 'email' === ( $err->get_error_data()['field'] ?? '' ) && 400 === $err->get_error_data()['status'] );
$err = Controller::checkout( p1u_request( array( 'email' => 'code@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'campaign_codes' => array( 'GIBTSNICHT' ), 'provider' => 'manual', 'idempotency_key' => 'p1u-code-' . uniqid() ) ) );
check( 'unknown discount code -> field "campaign_code", wording "Rabattcode" like the label', is_wp_error( $err ) && 'campaign_code' === ( $err->get_error_data()['field'] ?? '' ) && false !== strpos( $err->get_error_message(), 'Rabattcode' ) );
check( 'the checkout message filter is removed again', ! ( function () { $m = apply_filters( 'wpcap_checkout_error_messages', array() ); return isset( $m['campaign_invalid'] ); } )() );
Settings::update( array( 'require_terms' => 1 ) );
$err = Controller::checkout( p1u_request( array( 'email' => 'terms@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'provider' => 'manual' ) ) );
check( 'missing consent -> field "accept_terms"', is_wp_error( $err ) && 'accept_terms' === ( $err->get_error_data()['field'] ?? '' ) );
Settings::update( array( 'require_terms' => 0 ) );
$err = Controller::with_error_field( new WP_Error( 'wpcap_voucher_state', 'x', array( 'status' => 409 ) ) );
check( 'voucher errors -> field "voucher_code"; unknown codes stay without field', 'voucher_code' === $err->get_error_data()['field'] && ! isset( Controller::with_error_field( new WP_Error( 'wpcap_db_busy', 'x', array( 'status' => 503 ) ) )->get_error_data()['field'] ) );

echo "Phase 1 UX: portal resend route\n";
$GLOBALS['wpcap_test_transients'] = array();
$paid_id = admin_paid_order( make_type( 20 ), 2, 'resend@example.com' );
$paid = Orders::get( $paid_id );
$token = p1u_access_token( $paid->public_id );
$denied = Controller::resend_tickets( p1u_request( array( 'access_token' => str_repeat( 'a', 64 ) ), array( 'public_id' => $paid->public_id ) ) );
check( 'wrong access token is refused (403)', is_wp_error( $denied ) && 403 === $denied->get_error_data()['status'] );
$before = count( $GLOBALS['wpcap_test_mails'] );
$ok = p1u_data( Controller::resend_tickets( p1u_request( array( 'access_token' => $token ), array( 'public_id' => $paid->public_id ) ) ) );
$resent = array_slice( $GLOBALS['wpcap_test_mails'], $before );
check( 'resend sends the tickets again and returns the masked address', is_array( $ok ) && 'sent' === $ok['status'] && 'r***@example.com' === $ok['email'] && 1 === count( $resent ) && 'resend@example.com' === $resent[0]['to'] );
p1u_data( Controller::resend_tickets( p1u_request( array( 'access_token' => $token ), array( 'public_id' => $paid->public_id ) ) ) );
p1u_data( Controller::resend_tickets( p1u_request( array( 'access_token' => $token ), array( 'public_id' => $paid->public_id ) ) ) );
$limited = Controller::resend_tickets( p1u_request( array( 'access_token' => $token ), array( 'public_id' => $paid->public_id ) ) );
check( 'at most 3 resends per order and hour (429 afterwards)', is_wp_error( $limited ) && 429 === $limited->get_error_data()['status'] && 'wpcap_resend_rate_limited' === $limited->get_error_code() );
$GLOBALS['wpcap_test_transients'] = array();
$pending = Orders::create( array( 'email' => 'unpaid@example.com', 'items' => array( array( 'ticket_type_id' => make_type( 5 ), 'quantity' => 1 ) ), 'idempotency_key' => 'p1u-unpaid-' . uniqid() ) );
$unpaid = Controller::resend_tickets( p1u_request( array( 'access_token' => p1u_access_token( $pending['public_id'] ) ), array( 'public_id' => $pending['public_id'] ) ) );
check( 'unpaid order: resend refused with 409', is_wp_error( $unpaid ) && 409 === $unpaid->get_error_data()['status'] );

echo "Phase 1 UX: order portal page\n";
$html = OrderPortal::render_page( $paid, $token );
check( 'heading shows the short order number instead of the 32-hex id', false !== strpos( $html, '<h1>Bestellung ' . Format::order_reference( $paid->public_id ) . '</h1>' ) && ! preg_match( '#<h1>[^<]*[a-f0-9]{32}#', $html ) );
check( 'resend button names the masked buyer address and the code rotation', false !== strpos( $html, 'Tickets erneut an r***@example.com senden' ) && false !== strpos( $html, '/resend' ) && false !== strpos( $html, 'bisher gesendete Codes werden ungültig' ) );
check( 'transfer asks for confirmation and names the consequence', false !== strpos( $html, 'id="wpcap-confirm"' ) && false !== strpos( $html, substr( wp_json_encode( 'endgültig und nur einmal möglich' ), 1, -1 ) ) && false !== strpos( $html, substr( wp_json_encode( 'bisherige Ticketcode wird sofort ungültig' ), 1, -1 ) ) && false !== strpos( $html, 'Ticket einmalig übertragen' ) );
check( 'portal amount uses the currency symbol', false !== strpos( $html, 'Gesamtbetrag: 25,00 €' ) || false !== strpos( $html, 'Gesamtbetrag: 50,00 €' ) );
Settings::update( array( 'bank_iban' => 'AT611904300234573201' ) );
$pending_order = Orders::get( $pending['order_id'] );
$pending_html = OrderPortal::render_page( $pending_order, p1u_access_token( $pending['public_id'] ) );
check( 'unpaid manual order: portal shows the payment details, no resend button', false !== strpos( $pending_html, 'Zahlungsinformationen' ) && false !== strpos( $pending_html, 'AT61 1904 3002 3457 3201' ) && false === strpos( $pending_html, '<form class="wpcap-resend-form' ) );

echo "Phase 1 UX: event card\n";
Settings::update( array( 'require_terms' => 1, 'terms_text' => 'AGB', 'consumer_info_text' => '' ) );
$card_type = make_type( 30, 5, 1990 );
// Only this occurrence is listed (the shortcode shows the next 50 dates).
$card_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$card_type" );
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET status='p1u_hidden' WHERE status='scheduled' AND occurrence_id<>$card_occ" );
$card = PassCaptain\PublicEvents::render_shortcode();
$card_pos = strpos( $card, 'data-type="' . $card_type . '"' );
$article = false !== $card_pos ? substr( $card, strrpos( substr( $card, 0, $card_pos ), '<article' ), 12000 ) : '';
check( 'buyer e-mail comes before the attendee box with the clear label', false !== strpos( $article, 'Deine E-Mail-Adresse (für Tickets und Bestätigung)' ) && strpos( $article, 'name="email"' ) < strpos( $article, 'wpcap-attendees' ) );
check( 'CTA uses the theme button class', false !== strpos( $article, 'class="wp-element-button wpcap-submit"' ) );
check( 'consent mentions consumer information only when configured', false !== strpos( $article, 'Ich akzeptiere die Bedingungen.</label>' ) && false === strpos( $article, 'Verbraucherinformationen gelesen' ) );
check( 'prices with currency symbol', false !== strpos( $article, 'Standard — 19,90 €' ) );
check( 'fields are labelled explicitly (for/id) for inline errors', 1 === preg_match( '#<label for="(wpcap-\d+-email)">.*?<input id="\1" name="email"#s', $article ) );
// Audit 3 (A017): the discount code field only appears when a code is active for the event.
$card_event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$card_occ" );
check( 'discount code field only with an active code for the event', PassCaptain\Campaigns::has_active( $card_event, 'EUR' ) === ( false !== strpos( $article, 'name="campaign_code"' ) ) );
Settings::update( array( 'consumer_info_text' => 'Widerrufsbelehrung …' ) );
$card2 = PassCaptain\PublicEvents::render_shortcode();
check( 'with consumer information the consent names it', false !== strpos( $card2, 'Ich akzeptiere die Bedingungen und habe die Verbraucherinformationen gelesen.' ) );
Settings::update( array( 'require_terms' => 0, 'terms_text' => '', 'consumer_info_text' => '' ) );
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET status='scheduled' WHERE status='p1u_hidden'" );
check( 'event start without timezone suffix in the site timezone', false === strpos( $article, 'Europe/Vienna' ) );

echo "Phase 1 tax: VAT breakdown, Rechnungskorrektur, local year\n";
$now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( "{$p}wpcap_invoices", array( 'order_id' => $paid_id, 'invoice_number' => 'P1U-' . uniqid(), 'currency' => 'EUR', 'subtotal_minor' => 3290, 'tax_minor' => 422, 'total_minor' => 3290, 'buyer_snapshot' => wp_json_encode( array( 'email' => 'tax@example.com', 'seller' => array( 'name' => 'Veranstalter GmbH', 'address' => 'Wien', 'vat_id' => 'ATU1' ) ) ), 'issued_at' => $now, 'status' => 'issued' ) );
$inv_id = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_invoice_lines", array( 'invoice_id' => $inv_id, 'description' => 'Konzert', 'quantity' => 1, 'unit_minor' => 2200, 'discount_minor' => 0, 'tax_rate' => 13, 'tax_minor' => 253, 'total_minor' => 2200 ) );
$wpdb->insert( "{$p}wpcap_invoice_lines", array( 'invoice_id' => $inv_id, 'description' => 'Garderobe', 'quantity' => 1, 'unit_minor' => 1090, 'discount_minor' => 0, 'tax_rate' => 20, 'tax_minor' => 182, 'total_minor' => 1090 ) );
$wpdb->insert( "{$p}wpcap_credit_notes", array( 'invoice_id' => $inv_id, 'refund_id' => 900000 + $inv_id, 'credit_note_number' => 'GS-P1U-' . $inv_id, 'currency' => 'EUR', 'net_minor' => 1000, 'tax_minor' => 200, 'total_minor' => 1200, 'issued_at' => $now ) );
$lines = $wpdb->get_results( "SELECT * FROM {$p}wpcap_invoice_lines WHERE invoice_id=$inv_id" );
$sum = Invoices::rate_summary( $lines );
check( 'rate summary: net, tax and gross per rate', array( 'net' => 1947, 'tax' => 253, 'gross' => 2200 ) === $sum['13'] && array( 'net' => 908, 'tax' => 182, 'gross' => 1090 ) === $sum['20'] );
$inv_html = Invoices::render_html( $inv_id );
check( 'invoice HTML lists net/tax/gross per VAT rate', false !== strpos( $inv_html, 'Aufstellung nach Steuersätzen' ) && 1 === preg_match( '#<td>13,00 %</td><td class="n">19,47 EUR</td><td class="n">2,53 EUR</td><td class="n">22,00 EUR</td>#', $inv_html ) && 1 === preg_match( '#<td>20,00 %</td><td class="n">9,08 EUR</td><td class="n">1,82 EUR</td><td class="n">10,90 EUR</td>#', $inv_html ) );
check( 'credit notes are labelled "Rechnungskorrektur" in the HTML document', false !== strpos( $inv_html, 'Rechnungskorrektur GS-P1U-' . $inv_id ) && false === strpos( $inv_html, 'Gutschrift' ) );
$pdf = PassCaptain\Printing\InvoicePdf::render( $inv_id );
check( 'invoice PDF lists net/tax/gross per rate and the Rechnungskorrektur page', false !== strpos( $pdf, '(19,47 EUR) Tj' ) && false !== strpos( $pdf, '(22,00 EUR) Tj' ) && false !== strpos( $pdf, '(9,08 EUR) Tj' ) && false !== strpos( $pdf, '(Rechnungskorrektur GS-P1U-' . $inv_id . ') Tj' ) && false === strpos( $pdf, 'Gutschrift' ) );
$GLOBALS['wpcap_test_options']['timezone_string'] = 'Europe/Vienna';
check( 'invoice year follows the site timezone (31 Dec 23:30 UTC = next year in Vienna)', 2027 === Invoices::local_year( gmmktime( 23, 30, 0, 12, 31, 2026 ) ) && 2026 === Invoices::local_year( gmmktime( 22, 30, 0, 12, 31, 2026 ) - 3600 ) );
check( 'next invoice number uses the local year', 1 === preg_match( '/-' . Invoices::local_year() . '-\d{6}$/', (string) Invoices::next_number() ) );
unset( $GLOBALS['wpcap_test_options']['timezone_string'] );

echo "Phase 1 UX: box office fiscal notice\n";
ob_start();
PassCaptain\BoxOffice\Page::render_fiscal_notice();
$notice = ob_get_clean();
check( 'box office shows the RKSV/KassenSichV notice with docs link and dismiss link', false !== strpos( $notice, 'keine Registrierkasse im Sinne von RKSV (AT) bzw. KassenSichV/TSE (DE)' ) && false !== strpos( $notice, 'zertifizierte Signatureinheit' ) && false !== strpos( $notice, 'OPERATIONS.md' ) && false !== strpos( $notice, 'wpcap_dismiss_fiscal_notice=1' ) );
$_GET = array( 'wpcap_dismiss_fiscal_notice' => '1', '_wpnonce' => 'falsch' );
ob_start(); PassCaptain\BoxOffice\Page::render_fiscal_notice(); $still = ob_get_clean();
$_GET = array( 'wpcap_dismiss_fiscal_notice' => '1', '_wpnonce' => 'n' );
ob_start(); PassCaptain\BoxOffice\Page::render_fiscal_notice(); $gone = ob_get_clean();
$_GET = array();
ob_start(); PassCaptain\BoxOffice\Page::render_fiscal_notice(); $later = ob_get_clean();
$GLOBALS['wpcap_test_user_id'] = 2;
ob_start(); PassCaptain\BoxOffice\Page::render_fiscal_notice(); $other_user = ob_get_clean();
$GLOBALS['wpcap_test_user_id'] = 1;
check( 'dismissal needs the nonce and is stored per user', '' !== $still && '' === $gone && '' === $later && '' !== $other_user );

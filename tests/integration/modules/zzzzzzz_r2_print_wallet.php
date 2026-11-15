<?php
/**
 * Audit round 2, package R2 (print, PDF, QR, wallet): regression tests for
 * - batch print with QR codes: permission (event management + 2FA step-up), only undelivered tickets by
 *   default, confirmation for customer tickets, holder notification, limit per job, transaction that
 *   only commits after the PDF is written (simulated abort keeps the old codes),
 * - compressed ticket PDFs with merged QR runs (sizes measured), attachment limits in the ticket mail,
 *   attendee name, /Title and /Lang, wrapping, Unicode transliteration,
 * - QR without ECI header (signed 251 characters → version 11), batched signed QR values,
 * - wallet: collection link instead of one .pkpass per ticket, deferred pass creation (job), skinny
 *   Google JWT, key revocation → wallet_sync jobs, key id in the fingerprint, APNs 403 / certificate
 *   expiry in the system status, one APNs client per run and the time budget of a run.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Printing\Page as PrintPage;
use PassCaptain\Printing\PdfWriter;
use PassCaptain\Printing\Templates;
use PassCaptain\QrCode;
use PassCaptain\Security\SignedQr;
use PassCaptain\Settings;
use PassCaptain\TicketPdf;
use PassCaptain\WalletPasses\AppleProvider;
use PassCaptain\WalletPasses\GoogleProvider;
use PassCaptain\WalletPasses\Service as WalletService;
use PassCaptain\WalletPasses\Store as WalletStore;
use PassCaptain\WalletPasses\WebService as WalletWeb;

if ( is_readable( dirname( __DIR__, 3 ) . '/vendor/autoload.php' ) ) { require_once dirname( __DIR__, 3 ) . '/vendor/autoload.php'; }
PassCaptain\Modules\PrintTemplates::register();
PassCaptain\Security\Policy::register();

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response { public function __construct( public $data = null, public $status = 200 ) {} public function get_data() { return $this->data; } public function get_status() { return $this->status; } }
}
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . ltrim( $p, '/' ); } }
if ( ! function_exists( 'wp_salt' ) ) { function wp_salt( $scheme = 'auth' ) { return 'r2-test-salt-' . $scheme; } }
global $wpdb;
$p = $wpdb->prefix;
$r2_has_qr = class_exists( '\\BaconQrCode\\Encoder\\Encoder' );

function r2p_hashes( array $ids ) {
	global $wpdb;
	if ( ! $ids ) { return array(); }
	return $wpdb->get_col( "SELECT token_hash FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ') ORDER BY ticket_id' );
}
function r2p_order( $type, $qty, $email, array $extra = array() ) {
	wpcap_test_reset_transients();
	return Orders::create( array_merge( array( 'email' => $email, 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => 'r2p-' . uniqid( '', true ) ), $extra ) );
}
function r2p_ids( $order_id ) { return array_map( static fn( $t ) => (int) $t->ticket_id, tickets( $order_id ) ); }
function r2p_ticket_mail( $since ) {
	foreach ( array_slice( $GLOBALS['wpcap_test_mails'], $since ) as $m ) { if ( str_starts_with( $m['subject'], 'Deine Tickets' ) ) { return $m; } }
	return array( 'body' => '', 'files' => array(), 'to' => '' );
}

echo "R2: batch print with QR codes\n";
$r2_type = make_type( 50, 50, 0 );
$r2_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$r2_type" );
// Box office sale without e-mail (placeholder buyer): codes never reached anybody.
$r2_pos = r2p_order( $r2_type, 3, 'boxoffice+' . substr( md5( uniqid() ), 0, 16 ) . '@invalid.example' );
// Customer orders: one with a holder e-mail on one ticket.
$r2_cust = r2p_order( $r2_type, 2, 'kunde-r2@example.com', array( 'attendees' => array( array( 'ticket_type_id' => $r2_type, 'name' => 'Ada Holder', 'email' => 'holder-r2@example.com' ) ) ) );
$r2_pos_ids = r2p_ids( $r2_pos['order_id'] );
$r2_cust_ids = r2p_ids( $r2_cust['order_id'] );
$r2_pos_before = r2p_hashes( $r2_pos_ids );
$r2_cust_before = r2p_hashes( $r2_cust_ids );
check( 'R2 setup: 3 box office tickets without e-mail, 2 customer tickets', 3 === count( $r2_pos_ids ) && 2 === count( $r2_cust_ids ) && 3 === count( Templates::code_candidates( $r2_occ )['undelivered'] ) && 2 === count( Templates::code_candidates( $r2_occ )['delivered'] ) );

// Permission: box office role (order management, no event management) must not create codes.
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_events' );
$r2_cashier = PrintPage::batch_request( $r2_occ, 'ticket', true );
$r2_cashier_plain = PrintPage::batch_request( $r2_occ, 'badge', false );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'R2 #3: box office role (manage_orders only) cannot print QR codes, codes unchanged', is_wp_error( $r2_cashier ) && 403 === ( $r2_cashier->get_error_data()['status'] ?? 0 ) && $r2_pos_before === r2p_hashes( $r2_pos_ids ) && $r2_cust_before === r2p_hashes( $r2_cust_ids ) );
check( 'R2 #3: box office role may still print without QR codes', ! is_wp_error( $r2_cashier_plain ) && str_starts_with( $r2_cashier_plain['pdf'], '%PDF-1.4' ) );

// 2FA step-up for code generation.
Settings::update( array( 'mfa_bulk' => 1 ) );
$r2_mfa = PrintPage::batch_request( $r2_occ, 'ticket', true );
Settings::update( array( 'mfa_bulk' => 0 ) );
check( 'R2 #3: QR batch print requires 2FA when the bulk/exports step-up is on', is_wp_error( $r2_mfa ) && 'wpcap_mfa_required' === $r2_mfa->get_error_code() && $r2_pos_before === r2p_hashes( $r2_pos_ids ) );

// Simulated abort while the PDF is rendered: nothing may be rotated.
$r2_rotations = array();
$r2_rot_listener = static function ( $ticket_id, $token, $reason ) use ( &$r2_rotations ) { $r2_rotations[ (int) $ticket_id ] = array( $token, $reason ); };
add_action( 'wpcap_ticket_code_rotated', $r2_rot_listener, 10, 3 );
$r2_calls = 0;
$r2_bomb = static function ( $label ) use ( &$r2_calls ) { if ( 2 === ++$r2_calls ) { throw new RuntimeException( 'simulated abort' ); } return $label; };
add_filter( 'wpcap_ticket_seat_label', $r2_bomb, 99 );
$r2_abort = PrintPage::batch_request( $r2_occ, 'ticket', true );
remove_filter( 'wpcap_ticket_seat_label', $r2_bomb, 99 );
check( 'R2 #5/#11: abort during the PDF (exception) rolls back: old codes stay valid, no rotation hook', is_wp_error( $r2_abort ) && 'wpcap_print_codes_failed' === $r2_abort->get_error_code() && $r2_pos_before === r2p_hashes( $r2_pos_ids ) && ! $r2_rotations );
$r2_dir = PassCaptain\TempFiles::private_dir();
$r2_bad_file = $r2_dir . '/missing-subdir/x.pdf';
$r2_write = Templates::batch_pdf_with_codes( $r2_occ, 'ticket', $r2_bad_file );
PassCaptain\TempFiles::remove_dir( $r2_dir );
check( 'R2 #5: PDF file cannot be written → ROLLBACK, old codes stay valid', is_wp_error( $r2_write ) && 'write' === ( $r2_write->get_error_data()['step'] ?? '' ) && $r2_pos_before === r2p_hashes( $r2_pos_ids ) && ! $r2_rotations );
check( 'R2 #5: failed attempts are audited', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='print_batch_codes_failed'" ) >= 2 );

// Default: only undelivered tickets; limit per job.
add_filter( 'wp_pass_captain_print_batch_limit_v1', static fn() => 2 );
$r2_part1 = PrintPage::batch_request( $r2_occ, 'ticket', true );
$r2_after1 = r2p_hashes( $r2_pos_ids );
$r2_part2 = PrintPage::batch_request( $r2_occ, 'ticket', true, array( 'offset' => 2 ) );
unset( $GLOBALS['wpcap_test_hooks']['wp_pass_captain_print_batch_limit_v1'] );
$r2_after2 = r2p_hashes( $r2_pos_ids );
check( 'R2 #5: limit per job (filter): first part rotates 2 of 3 box office tickets, filename names the part', ! is_wp_error( $r2_part1 ) && 2 === $r2_part1['count'] && 3 === $r2_part1['total'] && str_ends_with( $r2_part1['filename'], '-1-2.pdf' ) && $r2_after1[0] !== $r2_pos_before[0] && $r2_after1[1] !== $r2_pos_before[1] && $r2_after1[2] === $r2_pos_before[2] );
check( 'R2 #5: second part (offset) rotates the rest; customer tickets untouched by default', ! is_wp_error( $r2_part2 ) && 1 === $r2_part2['count'] && $r2_after2[2] !== $r2_pos_before[2] && $r2_cust_before === r2p_hashes( $r2_cust_ids ) && 0 === $r2_part1['delivered'] + $r2_part2['delivered'] );
check( 'R2 #5: printed PDF is complete (one page per ticket, compressed, QR drawn)', wpp_pdf_ok( $r2_part1['pdf'], 2 ) && false !== strpos( $r2_part1['pdf'], '/Filter /FlateDecode' ) && ( ! $r2_has_qr || substr_count( wpcap_test_pdf_plain( $r2_part1['pdf'] ), " re\n" ) > 100 ) );
$r2_rot_ok = 3 === count( $r2_rotations );
foreach ( $r2_rotations as $rid => $rot ) { $r2_rot_ok = $r2_rot_ok && 'print' === $rot[1] && hash( 'sha256', $rot[0] ) === $wpdb->get_var( "SELECT token_hash FROM {$p}wpcap_tickets WHERE ticket_id=" . (int) $rid ); }
check( 'R2 #5: wpcap_ticket_code_rotated fires after the commit with the stored code (reason print)', $r2_rot_ok );

// Customer tickets: explicit confirmation of their exact number, holders get the new links.
$r2_noconfirm = PrintPage::batch_request( $r2_occ, 'badge', true, array( 'include_delivered' => true ) );
$r2_wrongconfirm = PrintPage::batch_request( $r2_occ, 'badge', true, array( 'include_delivered' => true, 'confirm' => 5 ) );
check( 'R2 #3: customer tickets need the confirmation "N Kundentickets werden ungültig"', is_wp_error( $r2_noconfirm ) && 'wpcap_print_confirm_required' === $r2_noconfirm->get_error_code() && false !== strpos( $r2_noconfirm->get_error_message(), '2 Kundentickets werden ungültig' ) && is_wp_error( $r2_wrongconfirm ) && $r2_cust_before === r2p_hashes( $r2_cust_ids ) );
$r2_mails = count( $GLOBALS['wpcap_test_mails'] );
$r2_rotations = array();
$r2_all = PrintPage::batch_request( $r2_occ, 'badge', true, array( 'include_delivered' => true, 'confirm' => 2 ) );
$r2_new_mails = array_slice( $GLOBALS['wpcap_test_mails'], $r2_mails );
$r2_to = array_map( static fn( $m ) => $m['to'], $r2_new_mails );
$r2_cust_after = r2p_hashes( $r2_cust_ids );
$r2_links_ok = true;
foreach ( $r2_cust_ids as $cid ) {
	$tok = $r2_rotations[ $cid ][0] ?? '';
	$r2_links_ok = $r2_links_ok && '' !== $tok && (bool) array_filter( $r2_new_mails, static fn( $m ) => false !== strpos( $m['body'], $tok ) );
}
check( 'R2 #3: confirmed reprint rotates customer tickets and mails new links to the holder and the buyer', ! is_wp_error( $r2_all ) && 5 === $r2_all['count'] && 2 === $r2_all['delivered'] && 2 === $r2_all['notified'] && $r2_cust_after[0] !== $r2_cust_before[0] && $r2_cust_after[1] !== $r2_cust_before[1] && in_array( 'holder-r2@example.com', $r2_to, true ) && in_array( 'kunde-r2@example.com', $r2_to, true ) && $r2_links_ok );
check( 'R2 #3: box office placeholder addresses get no mail', ! array_filter( $r2_to, static fn( $t ) => str_ends_with( (string) $t, '@invalid.example' ) ) );
remove_filter( 'wpcap_ticket_code_rotated', $r2_rot_listener, 10 );

echo "R2: ticket PDF size, attachment limits, metadata\n";
$r2_tickets = static function ( $n, $signed_type = 0 ) {
	$out = array();
	for ( $i = 0; $i < $n; $i++ ) { $out[] = array( 'event' => 'Konzert', 'ticket' => 'Standard', 'when' => '01.01.2027 20:00', 'location' => 'Wien', 'token' => bin2hex( random_bytes( 32 ) ) ); }
	return $out;
};
$r2_t = microtime( true );
$r2_500 = TicketPdf::render( $r2_tickets( 500 ) );
$r2_ms = ( microtime( true ) - $r2_t ) * 1000;
$r2_plain1 = wpcap_test_pdf_plain( TicketPdf::render( $r2_tickets( 1 ) ) );
$r2_runs = substr_count( $r2_plain1, " re\n" );
$r2_modules = 0;
if ( $r2_has_qr ) { foreach ( QrCode::matrix( str_repeat( 'a', 64 ) ) as $row ) { $r2_modules += count( array_filter( $row ) ); } }
printf( "    measured: 500 tickets (classic code) %.1f KB in %d ms (before: 10.2 MB)\n", strlen( $r2_500 ) / 1024, $r2_ms );
check( 'R2 #5: ticket PDF is compressed (FlateDecode) and structurally valid', wpp_pdf_ok( $r2_500, 500 ) && 500 === substr_count( $r2_500, '/Filter /FlateDecode' ) );
check( 'R2 #5: 500 classic tickets stay far below the mail limits (< 1.5 MB, was 10.2 MB)', strlen( $r2_500 ) < 1.5 * 1048576 );
check( 'R2 #5: QR modules are merged into horizontal runs (fewer rectangles than dark modules), one fill', ! $r2_has_qr || ( $r2_runs > 50 && $r2_runs < $r2_modules * 0.75 && 0 === substr_count( $r2_plain1, 're f' ) ) );
// Signed QR (longer payload, more modules).
Settings::update( array( 'signed_qr' => 1 ) );
$r2_sig_order = r2p_order( make_type( 600, 600, 0 ), 1, 'sig-r2@example.com' );
$r2_sig_tokens = array();
$r2_sig_tickets = array();
for ( $i = 0; $i < 100; $i++ ) {
	$tok = bin2hex( random_bytes( 32 ) );
	$r2_sig_tickets[] = array( 'event' => 'Konzert', 'ticket' => 'Standard', 'when' => '01.01.2027 20:00', 'location' => 'Wien', 'token' => $tok );
}
// The tokens must belong to tickets for signing: reuse one real ticket's code for all pages.
$r2_real = bin2hex( random_bytes( 32 ) );
$wpdb->update( "{$p}wpcap_tickets", array( 'token_hash' => hash( 'sha256', $r2_real ) ), array( 'ticket_id' => r2p_ids( $r2_sig_order['order_id'] )[0] ) );
foreach ( $r2_sig_tickets as &$st ) { $st['token'] = $r2_real; }
unset( $st );
$r2_t = microtime( true );
$r2_q0 = $wpdb->num_queries;
$r2_sig_pdf = TicketPdf::render( $r2_sig_tickets );
$r2_sig_ms = ( microtime( true ) - $r2_t ) * 1000;
$r2_sig_q = $wpdb->num_queries - $r2_q0;
printf( "    measured: 100 tickets (signed QR) %.1f KB in %d ms, %d queries (before: 500 tickets 30.6 MB, 13.9 s, +1000 queries)\n", strlen( $r2_sig_pdf ) / 1024, $r2_sig_ms, $r2_sig_q );
check( 'R2 #5: 100 signed-QR tickets < 400 KB (500 were 30.6 MB)', ! $r2_has_qr || ( strlen( $r2_sig_pdf ) < 400 * 1024 && wpp_pdf_ok( $r2_sig_pdf, 100 ) ) );
check( 'R2 #7: signed QR values of a PDF are read in batches (not one query per ticket)', $r2_sig_q < 20 );

// SignedQr: batched values equal per-token values; one key opening per request.
$r2_tok_a = $r2_real;
$r2_vals = SignedQr::qr_values( array( 7 => $r2_tok_a, 9 => 'not-a-code', 11 => $r2_tok_a ) );
check( 'R2 #7: qr_values() = qr_value() per token, keys kept, non-codes unchanged', SignedQr::qr_value( $r2_tok_a ) === $r2_vals[7] && $r2_vals[7] === $r2_vals[11] && 'not-a-code' === $r2_vals[9] && SignedQr::is_signed( $r2_vals[7] ) );
$r2_t = microtime( true );
for ( $i = 0; $i < 200; $i++ ) { SignedQr::qr_value( $r2_tok_a ); }
$r2_cached_ms = ( microtime( true ) - $r2_t ) * 1000;
printf( "    measured: 200 x SignedQr::qr_value with cached key %.1f ms\n", $r2_cached_ms );
$r2_rot = SignedQr::rotate( false, 'r2_test' );
$r2_after_rot = SignedQr::qr_value( $r2_tok_a );
$r2_claims = SignedQr::verify( $r2_after_rot );
check( 'R2 #7: the cached signing key follows a rotation (new key id in new QR codes)', ! is_wp_error( $r2_claims ) && $r2_rot['key_id'] === $r2_claims['k'] );
SignedQr::forget_key();
check( 'R2 #7: key cache can be wiped (shutdown) and reopened', SignedQr::is_signed( SignedQr::qr_value( $r2_tok_a ) ) );

echo "R2: QR without ECI\n";
$r2_signed = SignedQr::for_ticket( r2p_ids( $r2_sig_order['order_id'] )[0] );
$r2_251 = 'PC1.' . substr( str_repeat( 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-_', 5 ), 0, 160 ) . '.' . str_repeat( 'Q', 86 );
check( 'R2 #4: a 251-character signed payload (ASCII) needs QR version 11 (61 modules) without ECI header', ! $r2_has_qr || ( 251 === strlen( $r2_251 ) && 61 === count( QrCode::matrix( $r2_251 ) ) ) );
$r2_w_iso = $r2_has_qr ? \BaconQrCode\Encoder\Encoder::encode( $r2_signed, \BaconQrCode\Common\ErrorCorrectionLevel::M(), 'ISO-8859-1' )->getMatrix()->getWidth() : 0;
$r2_w_utf = $r2_has_qr ? \BaconQrCode\Encoder\Encoder::encode( $r2_signed, \BaconQrCode\Common\ErrorCorrectionLevel::M(), 'UTF-8' )->getMatrix()->getWidth() : 0;
check( 'R2 #4: real signed ticket QR is encoded without ECI (same size as ISO-8859-1, never larger than UTF-8+ECI)', ! $r2_has_qr || ( $r2_w_iso === count( QrCode::matrix( $r2_signed ) ) && $r2_w_iso <= $r2_w_utf ) );
check( 'R2 #4: non-ASCII payloads still encode (UTF-8 with ECI)', ! $r2_has_qr || count( QrCode::matrix( 'Grüße' ) ) >= 21 );
check( 'R2 #4: own vendor Bacon via a symlinked vendor/package directory is not reported as foreign', ! $r2_has_qr || '' === QrCode::foreign_encoder() );
Settings::update( array( 'signed_qr' => 0 ) );

// Attachment limits in the ticket mail.
$r2_mt = make_type( 20, 20, 0 );
add_filter( 'wp_pass_captain_ticket_pdf_limits_v1', static fn( $l ) => array( 'max_tickets' => 2, 'max_bytes' => $l['max_bytes'] ) );
$r2_m0 = count( $GLOBALS['wpcap_test_mails'] );
$r2_big = r2p_order( $r2_mt, 3, 'gross-r2@example.com' );
$r2_big_mail = r2p_ticket_mail( $r2_m0 );
check( 'R2 #5: more tickets than allowed → no PDF attachment, note in the mail, ticket links present', ! array_filter( array_keys( $r2_big_mail['files'] ), static fn( $n ) => str_ends_with( $n, '.pdf' ) ) && false !== strpos( $r2_big_mail['body'], 'nicht als PDF angehängt' ) && 3 <= substr_count( $r2_big_mail['body'], 'wpcap_ticket=' ) );
unset( $GLOBALS['wpcap_test_hooks']['wp_pass_captain_ticket_pdf_limits_v1'] );
add_filter( 'wp_pass_captain_ticket_pdf_limits_v1', static fn( $l ) => array( 'max_tickets' => 50, 'max_bytes' => 500 ) );
$r2_m0 = count( $GLOBALS['wpcap_test_mails'] );
r2p_order( $r2_mt, 1, 'bytes-r2@example.com' );
$r2_bytes_mail = r2p_ticket_mail( $r2_m0 );
unset( $GLOBALS['wpcap_test_hooks']['wp_pass_captain_ticket_pdf_limits_v1'] );
check( 'R2 #5: PDF larger than the byte limit → no attachment (size check in the mail path)', ! array_filter( array_keys( $r2_bytes_mail['files'] ), static fn( $n ) => str_ends_with( $n, '.pdf' ) ) && false !== strpos( $r2_bytes_mail['body'], 'nicht als PDF angehängt' ) );

// Attendee name, /Title, /Lang, wrapping in the mail PDF.
$r2_attachments = array();
add_filter( 'wpcap_ticket_mail', static function ( $mail ) use ( &$r2_attachments ) { $r2_attachments = array_merge( $r2_attachments, array_filter( (array) ( $mail['attachments'] ?? array() ), static fn( $f ) => str_ends_with( (string) $f, '.pdf' ) ) ); return $mail; }, 99 );
$r2_m0 = count( $GLOBALS['wpcap_test_mails'] );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_events e INNER JOIN {$p}wpcap_occurrences o ON o.event_id=e.event_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.occurrence_id=o.occurrence_id SET e.name=%s WHERE tt.ticket_type_id=%d", 'Ein außergewöhnlich langer Veranstaltungsname für das große Sommerfestival der Stadtwerke mit Gästen aus aller Welt', $r2_mt ) );
r2p_order( $r2_mt, 1, 'name-r2@example.com', array( 'attendees' => array( array( 'ticket_type_id' => $r2_mt, 'name' => 'Zoë Ćirić' ) ) ) );
$r2_mail = r2p_ticket_mail( $r2_m0 );
$r2_att_ok = (bool) $r2_attachments;
foreach ( $r2_attachments as $r2_att ) { $r2_att_ok = $r2_att_ok && ! file_exists( $r2_att ) && ! is_dir( dirname( $r2_att ) ); }
check( 'R2 #5: temporary ticket PDF and its private directory are removed after sending', $r2_att_ok );
$r2_pdf_files = array_values( array_filter( $r2_mail['files'], static fn( $n ) => str_ends_with( $n, '.pdf' ), ARRAY_FILTER_USE_KEY ) );
$r2_mpdf = $r2_pdf_files[0] ?? '';
$r2_mplain = wpcap_test_pdf_plain( $r2_mpdf );
check( 'R2 #8: ticket PDF of the mail shows the attendee name (transliterated Ć)', false !== strpos( $r2_mplain, '(Zo' . "\xEB" . ' Ciric) Tj' ) );
check( 'R2 #8: PDF has /Title and /Lang', false !== strpos( $r2_mpdf, '/Title (Testsite)' ) && 1 === preg_match( '#/Lang \([a-zA-Z-]+\)#', $r2_mpdf ) );
check( 'R2 #8: long event names wrap onto two lines', 2 <= preg_match_all( '#/F2 22\.00 Tf [0-9. ]+Td \([^)]*\) Tj#', $r2_mplain ) );

echo "R2: Unicode in PDFs\n";
$r2_uni = PdfWriter::escape( 'Привет 東京 Łódź Ä€' );
check( 'R2 #5: non-WinAnsi characters are transliterated instead of "?"', false === strpos( $r2_uni, '?' ) && false !== strpos( $r2_uni, 'Privet' ) && false !== strpos( $r2_uni, "L\xF3dz" ) && false !== strpos( $r2_uni, "\xC4\x80" ) );
check( 'R2 #5: WinAnsi characters (umlauts, €) are kept', "M\xFCller \x80" === PdfWriter::escape( 'Müller €' ) && PdfWriter::needs_transliteration( 'Łódź' ) && ! PdfWriter::needs_transliteration( 'Müller' ) );
$r2_tag = wpcap_test_pdf_plain( Templates::render( 'nametag', array( array( 'attendee_name' => 'Ćirić Привет', 'event' => 'X', 'qr' => '' ) ) ) );
check( 'R2 #5: name tags transliterate as well', false !== strpos( $r2_tag, '(Ciric Privet) Tj' ) );

echo "R2: wallet\n";
// Test PKI (the wallet module test removed its files).
$r2_dir = sys_get_temp_dir() . '/r2-wallet-' . getmypid();
@mkdir( $r2_dir );
$r2_cnf = $r2_dir . '/openssl.cnf';
file_put_contents( $r2_cnf, "[req]\ndistinguished_name=dn\n[dn]\n[v3_ca]\nbasicConstraints=critical,CA:true\nkeyUsage=critical,keyCertSign,cRLSign\n[usr]\nbasicConstraints=CA:false\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=clientAuth,emailProtection\n" );
$r2_cfg = array( 'config' => $r2_cnf, 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA );
$r2_ca_key = openssl_pkey_new( $r2_cfg );
$r2_ca = openssl_csr_sign( openssl_csr_new( array( 'commonName' => 'R2 WWDR' ), $r2_ca_key, $r2_cfg ), null, $r2_ca_key, 365, $r2_cfg + array( 'x509_extensions' => 'v3_ca' ), 1 );
$r2_pkey = openssl_pkey_new( $r2_cfg );
// Pass certificate expiring in 10 days: the system status must warn.
$r2_cert = openssl_csr_sign( openssl_csr_new( array( 'commonName' => 'Pass Type ID: pass.test.wpcap', 'UID' => 'pass.test.wpcap', 'organizationalUnitName' => 'ABCDE12345' ), $r2_pkey, $r2_cfg ), $r2_ca, $r2_ca_key, 10, $r2_cfg + array( 'x509_extensions' => 'usr' ), 2 );
openssl_x509_export( $r2_ca, $r2_ca_pem );
openssl_x509_export( $r2_cert, $r2_cert_pem );
openssl_pkey_export( $r2_pkey, $r2_key_pem, null, $r2_cfg );
$r2_gkey = openssl_pkey_new( $r2_cfg );
openssl_pkey_export( $r2_gkey, $r2_gkey_pem, null, $r2_cfg );
// The test hook registry does not deduplicate callbacks like WordPress: register the wallet module once.
$r2_wallet_registered = false;
foreach ( $GLOBALS['wpcap_test_hooks']['wpcap_ticket_mail'] ?? array() as $r2_cbs ) {
	foreach ( $r2_cbs as $r2_cb ) { $r2_wallet_registered = $r2_wallet_registered || array( WalletService::class, 'on_ticket_mail' ) === $r2_cb[0]; }
}
if ( ! $r2_wallet_registered ) { PassCaptain\Modules\Wallet::register(); }
PassCaptain\WalletPasses\AdminPage::save( array(
	'wallet_apple_enabled' => '1', 'wallet_apple_pass_type_id' => 'pass.test.wpcap', 'wallet_apple_team_id' => 'ABCDE12345', 'wallet_apple_delivery' => 'attachment',
	'apple_pass_cert' => $r2_cert_pem, 'apple_pass_key' => $r2_key_pem, 'apple_wwdr_cert' => $r2_ca_pem,
	'wallet_google_enabled' => '1', 'wallet_google_issuer_id' => '3388000000012345678',
	'google_wallet_service_account' => json_encode( array( 'client_email' => 'wallet@test.iam.gserviceaccount.com', 'private_key' => $r2_gkey_pem, 'token_uri' => 'https://oauth2.googleapis.com/token' ) ),
) );
set_transient( 'wpcap_wallet_google_token', 'r2-token', 3600 );
if ( ! class_exists( 'R2_Apns' ) ) {
	class R2_Apns extends PassCaptain\WalletPasses\ApnsClient {
		public array $calls = array();
		public array $statuses = array();
		public float $sleep = 0.0;
		protected function request( $url, array $headers, $body, array $credentials ) {
			$this->calls[] = $url;
			if ( $this->sleep > 0 ) { usleep( (int) ( $this->sleep * 1000000 ) ); $this->sleep = 0.0; }
			return array( 'status' => $this->statuses ? array_shift( $this->statuses ) : 200, 'body' => '' );
		}
	}
}
$r2_apns_made = 0;
$r2_apns = new R2_Apns();
add_filter( 'wpcap_wallet_apns_client', static function () use ( $r2_apns, &$r2_apns_made ) { ++$r2_apns_made; return $r2_apns; } );
check( 'R2 wallet setup: both providers configured', null !== WalletService::provider( 'apple' ) && null !== WalletService::provider( 'google' ) );

// Status: certificate expiring within 30 days.
$r2_issues = PassCaptain\Ops\Status::issues();
check( 'R2 #9: Apple certificate expiring in < 30 days appears in the system status', (bool) array_filter( $r2_issues, static fn( $i ) => 'warning' === $i['severity'] && false !== strpos( $i['message'], 'Apple-Wallet-Zertifikat' ) ) );

// Multi-ticket mail: one collection link, no attachment per ticket, no inline signing.
$r2_wt = make_type( 20, 20, 0 );
$r2_json_calls = 0;
$r2_json_counter = static function ( $json ) use ( &$r2_json_calls ) { ++$r2_json_calls; return $json; };
add_filter( 'wpcap_wallet_apple_pass_json', $r2_json_counter );
$r2_m0 = count( $GLOBALS['wpcap_test_mails'] );
$r2_wo = r2p_order( $r2_wt, 3, 'wallet-r2@example.com' );
$wpdb->update( "{$p}wpcap_orders", array( 'locale' => 'en_US' ), array( 'order_id' => $r2_wo['order_id'] ) );
$r2_wmail = r2p_ticket_mail( $r2_m0 );
$r2_inline_json = $r2_json_calls;
preg_match( '#(https://\S+/wallet/order/([A-Za-z0-9-]+)\?exp=(\d+)&sig=([a-f0-9]{64}))#', $r2_wmail['body'], $r2_cm );
check( 'R2 #3(wallet): 3 tickets → no .pkpass attachments, one collection link, no inline pass signing', ! array_filter( array_keys( $r2_wmail['files'] ), static fn( $n ) => str_ends_with( $n, '.pkpass' ) ) && 1 === substr_count( $r2_wmail['body'], '/wallet/order/' ) && isset( $r2_cm[4] ) && 0 === $r2_inline_json && false === strpos( $r2_wmail['body'], 'pay.google.com' ) );
$r2_issue_jobs = $wpdb->get_results( "SELECT job_id,payload FROM {$p}wpcap_jobs WHERE job_type='wallet_issue' AND status='queued'" );
$r2_issue_ids = array();
foreach ( $r2_issue_jobs as $j ) { $r2_issue_ids = array_merge( $r2_issue_ids, json_decode( $j->payload, true )['ticket_ids'] ); }
check( 'R2 #3(wallet): pass creation is queued as job (wallet_issue) with the order\'s tickets; codes stored encrypted', 3 === count( array_intersect( $r2_issue_ids, r2p_ids( $r2_wo['order_id'] ) ) ) && 3 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_wallet_state WHERE ticket_id IN (" . implode( ',', r2p_ids( $r2_wo['order_id'] ) ) . ')' ) );

// Collection page before the job: works (fat Google JWT), signature checked.
$r2_req = new WP_REST_Request( 'GET' );
$r2_req->set_param( 'order', $r2_cm[2] ?? '' );
$r2_req->set_param( 'exp', $r2_cm[3] ?? '' );
$r2_req->set_param( 'sig', $r2_cm[4] ?? '' );
$r2_page = WalletWeb::collection( $r2_req );
$r2_html = is_wp_error( $r2_page ) ? '' : (string) $r2_page->get_data()['__wpcap_raw'];
$r2_bad = new WP_REST_Request( 'GET' );
$r2_bad->set_param( 'order', $r2_cm[2] ?? '' );
$r2_bad->set_param( 'exp', $r2_cm[3] ?? '' );
$r2_bad->set_param( 'sig', str_repeat( 'a', 64 ) );
$r2_expired = new WP_REST_Request( 'GET' );
$r2_expired->set_param( 'order', $r2_cm[2] ?? '' );
$r2_expired->set_param( 'exp', time() - 10 );
$r2_expired->set_param( 'sig', WalletService::collection_sig( $r2_cm[2] ?? '', time() - 10 ) );
check( 'R2 #3(wallet): collection page lists Apple and Google buttons for all 3 tickets', 3 === substr_count( $r2_html, '/wallet/apple/download/' ) && 3 === substr_count( $r2_html, 'pay.google.com/gp/v/save/' ) && 'no-store, no-cache, must-revalidate, max-age=0, private' === ( $r2_page->get_data()['headers']['Cache-Control'] ?? '' ) );
check( 'R2 #3(wallet): wrong or expired signature → 404', is_wp_error( WalletWeb::collection( $r2_bad ) ) && is_wp_error( WalletWeb::collection( $r2_expired ) ) );

// Run the job: Google objects over REST, then skinny JWTs.
http_reset();
set_transient( 'wpcap_wallet_google_token', 'r2-token', 3600 );
for ( $i = 0; $i < 3; $i++ ) { http_push( array(), 200, 'walletobjects' ); }
foreach ( $r2_issue_jobs as $j ) {
	$r2_job_ok = WalletService::handle_job( false, 'wallet_issue', json_decode( $j->payload, true ) );
	$wpdb->query( "UPDATE {$p}wpcap_jobs SET status='complete' WHERE job_id=" . (int) $j->job_id );
}
$r2_patch = array_values( array_filter( http_requests( 'eventTicketObject/' ), static fn( $q ) => 'PATCH' === $q['method'] ) );
$r2_patch_body = json_decode( $r2_patch[0]['body'] ?? '{}', true );
check( 'R2 #3(wallet): issue job creates the Google objects via REST and marks them synced', ! empty( $r2_job_ok ) && 3 === count( $r2_patch ) && 3 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_wallet_passes WHERE provider='google' AND synced_at IS NOT NULL AND ticket_id IN (" . implode( ',', r2p_ids( $r2_wo['order_id'] ) ) . ')' ) );
check( 'R2 #8: Google language follows the order locale (en_US → en-US)', 'en-US' === ( $r2_patch_body['ticketType']['defaultValue']['language'] ?? '' ) );
check( 'R2 #3(wallet): Apple pass rows exist after the job (download link works)', 3 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_wallet_passes WHERE provider='apple' AND ticket_id IN (" . implode( ',', r2p_ids( $r2_wo['order_id'] ) ) . ')' ) );
$r2_html2 = (string) WalletWeb::collection( $r2_req )->get_data()['__wpcap_raw'];
preg_match_all( '#https://pay\.google\.com/gp/v/save/([A-Za-z0-9._-]+)#', $r2_html2, $r2_jwts );
$r2_payload = json_decode( (string) PassCaptain\WalletPasses\Jwt::b64url_decode( explode( '.', $r2_jwts[1][0] ?? '..' )[1] ?? '' ), true );
printf( "    measured: Google save URL skinny %d chars (fat JWT before ~2300)\n", strlen( $r2_jwts[0][0] ?? '' ) );
check( 'R2 #3(wallet): after the REST creation the save link is a skinny JWT (object id only, < 1000 chars)', 3 === count( $r2_jwts[0] ) && strlen( $r2_jwts[0][0] ) < 1000 && array( 'id' ) === array_keys( $r2_payload['payload']['eventTicketObjects'][0] ?? array() ) && ! isset( $r2_payload['payload']['eventTicketClasses'] ) );
// Transferred ticket is not listed (no access to the new holder's pass).
$r2_wids = r2p_ids( $r2_wo['order_id'] );
$wpdb->update( "{$p}wpcap_tickets", array( 'transfer_count' => 1, 'attendee_email' => 'other@example.com' ), array( 'ticket_id' => $r2_wids[0] ) );
check( 'R2 #3(wallet): transferred tickets are not on the buyer\'s collection page', 2 === count( WalletService::collection( $r2_cm[2] ?? '' ) ) );

// Single-ticket mail keeps the attachment.
$r2_m0 = count( $GLOBALS['wpcap_test_mails'] );
r2p_order( $r2_wt, 1, 'single-r2@example.com' );
$r2_single = r2p_ticket_mail( $r2_m0 );
check( 'R2 #3(wallet): one ticket keeps the .pkpass attachment', 1 === count( array_filter( array_keys( $r2_single['files'] ), static fn( $n ) => str_ends_with( $n, '.pkpass' ) ) ) );
remove_filter( 'wpcap_wallet_apple_pass_json', $r2_json_counter, 10 );

// Batch print rotation (reason print): deferred, no inline signing.
$r2_json_calls = 0;
add_filter( 'wpcap_wallet_apple_pass_json', $r2_json_counter );
$r2_rid = $r2_wids[1];
$r2_gen = (int) WalletStore::state( $r2_rid )->generation;
$r2_code = bin2hex( random_bytes( 32 ) );
$wpdb->update( "{$p}wpcap_tickets", array( 'token_hash' => hash( 'sha256', $r2_code ) ), array( 'ticket_id' => $r2_rid ) );
$r2_jobs0 = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_jobs WHERE job_type='wallet_issue'" );
do_action( 'wpcap_ticket_code_rotated', $r2_rid, $r2_code, 'print' );
check( 'R2 #1: code rotated by batch print → new generation stored, pass reissue queued (no inline signing)', $r2_gen + 1 === (int) WalletStore::state( $r2_rid )->generation && hash( 'sha256', $r2_code ) === WalletStore::state( $r2_rid )->code_hash && 0 === $r2_json_calls && $r2_jobs0 + 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_jobs WHERE job_type='wallet_issue'" ) );
remove_filter( 'wpcap_wallet_apple_pass_json', $r2_json_counter, 10 );
http_reset();
set_transient( 'wpcap_wallet_google_token', 'r2-token', 3600 );
for ( $i = 0; $i < 10; $i++ ) { http_push( array(), 200, 'walletobjects' ); }
WalletService::flush();

// APNs: one client per run, 403 = configuration error without retry.
$r2_wids_all = r2p_ids( $r2_wo['order_id'] );
foreach ( $r2_wids_all as $i => $tid ) {
	$st = WalletStore::state( $tid );
	WalletStore::register_device( 'r2dev' . $i, 'pass.test.wpcap', (string) $wpdb->get_var( "SELECT public_id FROM {$p}wpcap_tickets WHERE ticket_id=$tid" ), 'abcdef0123456' . $i, (string) $st->auth_token_hash );
}
$r2_apns_made = 0;
$r2_apns->calls = array();
http_reset();
set_transient( 'wpcap_wallet_google_token', 'r2-token', 3600 );
for ( $i = 0; $i < 10; $i++ ) { http_push( array(), 200, 'walletobjects' ); }
$r2_ok = WalletService::process( $r2_wids_all );
check( 'R2 #9: one APNs client (connection) per dispatch run', true === $r2_ok && 3 === count( $r2_apns->calls ) && 1 === $r2_apns_made );
delete_option( WalletService::APNS_ERROR_OPTION );
$r2_apns->calls = array();
$r2_apns->statuses = array( 403, 403, 403 );
http_reset();
set_transient( 'wpcap_wallet_google_token', 'r2-token', 3600 );
for ( $i = 0; $i < 10; $i++ ) { http_push( array(), 200, 'walletobjects' ); }
$r2_403 = WalletService::process( $r2_wids_all );
check( 'R2 #9: APNs 403 is a configuration error: no retry (run succeeds), flagged in the system status', true === $r2_403 && (int) get_option( WalletService::APNS_ERROR_OPTION ) > 0 && (bool) array_filter( PassCaptain\Ops\Status::issues(), static fn( $i ) => 'error' === $i['severity'] && false !== strpos( $i['message'], '403' ) ) );
delete_option( WalletService::APNS_ERROR_OPTION );

// Time budget: a slow run stops and queues the rest as a new job.
add_filter( 'wpcap_cron_time_budget', static fn() => 1 );
PassCaptain\Jobs\TimeBudget::arm();
$r2_apns->statuses = array();
$r2_apns->sleep = 1.1;
$r2_sync0 = (int) $wpdb->get_var( "SELECT MAX(job_id) FROM {$p}wpcap_jobs" );
http_reset();
set_transient( 'wpcap_wallet_google_token', 'r2-token', 3600 );
for ( $i = 0; $i < 10; $i++ ) { http_push( array(), 200, 'walletobjects' ); }
$r2_budget = WalletService::process( $r2_wids_all );
PassCaptain\Jobs\TimeBudget::disarm();
unset( $GLOBALS['wpcap_test_hooks']['wpcap_cron_time_budget'] );
$r2_rest = $wpdb->get_row( $wpdb->prepare( "SELECT payload FROM {$p}wpcap_jobs WHERE job_type='wallet_sync' AND job_id>%d ORDER BY job_id DESC LIMIT 1", $r2_sync0 ) );
check( 'R2 #9: TimeBudget in Service::process(): the rest continues as its own wallet_sync job', true === $r2_budget && $r2_rest && array_slice( $r2_wids_all, 1 ) === json_decode( $r2_rest->payload, true )['ticket_ids'] );
$wpdb->query( "UPDATE {$p}wpcap_jobs SET status='complete' WHERE job_type IN ('wallet_sync','wallet_issue') AND status='queued'" );

// Key revocation: active passes of upcoming occurrences get resync jobs; key id in the fingerprint.
Settings::update( array( 'signed_qr' => 1 ) );
SignedQr::active_id();
$r2_ticket = WalletService::ticket( $r2_wids_all[2] );
$r2_fp = WalletService::fingerprint( $r2_ticket );
$r2_max = (int) $wpdb->get_var( "SELECT MAX(job_id) FROM {$p}wpcap_jobs" );
SignedQr::rotate( false, 'r2_grace' );
$r2_after_grace = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_jobs WHERE job_id>%d AND job_type='wallet_sync'", $r2_max ) );
$r2_fp2 = WalletService::fingerprint( $r2_ticket );
SignedQr::rotate( true, 'r2_compromise' );
$r2_resync = $wpdb->get_results( $wpdb->prepare( "SELECT payload FROM {$p}wpcap_jobs WHERE job_id>%d AND job_type='wallet_sync'", $r2_max ) );
$r2_resync_ids = array();
foreach ( $r2_resync as $j ) { $pl = json_decode( $j->payload, true ); if ( ! empty( $pl['resync'] ) ) { $r2_resync_ids = array_merge( $r2_resync_ids, $pl['ticket_ids'] ); } }
check( 'R2 #6: the QR key id is part of the wallet fingerprint', $r2_fp !== $r2_fp2 );
check( 'R2 #6: a normal rotation (grace period) queues no wallet resync', 0 === $r2_after_grace );
check( 'R2 #6: revoking the QR key queues wallet_sync jobs for all active passes of upcoming occurrences', in_array( $r2_wids_all[2], array_map( 'intval', $r2_resync_ids ), true ) );
http_reset();
set_transient( 'wpcap_wallet_google_token', 'r2-token', 3600 );
for ( $i = 0; $i < 20; $i++ ) { http_push( array(), 200, 'walletobjects' ); }
$r2_apns->calls = array();
$r2_state_tag = (int) WalletStore::state( $r2_wids_all[2] )->update_tag;
WalletService::handle_job( false, 'wallet_sync', array( 'ticket_ids' => array( $r2_wids_all[2] ), 'occurrence_ids' => array(), 'resync' => 1 ) );
check( 'R2 #6: the resync job updates the pass (new tag, push sent)', (int) WalletStore::state( $r2_wids_all[2] )->update_tag > $r2_state_tag && count( $r2_apns->calls ) >= 1 );
Settings::update( array( 'signed_qr' => 0 ) );
$wpdb->query( "UPDATE {$p}wpcap_jobs SET status='complete' WHERE job_type IN ('wallet_sync','wallet_issue') AND status='queued'" );

/* ---- cleanup ---- */
Settings::update( array( 'wallet_apple_enabled' => 0, 'wallet_google_enabled' => 0 ) );
unset( $GLOBALS['wpcap_test_hooks']['wpcap_wallet_apns_client'] );
array_map( 'unlink', glob( $r2_dir . '/*' ) );
@rmdir( $r2_dir );

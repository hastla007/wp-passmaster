<?php
// phpcs:ignoreFile
/**
 * A621: a resent ticket mail (admin/portal resend and the retry job, which uses the resend) carries
 * the same attachments as the ticket mail: ticket PDF with the codes of this mail (one builder,
 * Orders::ticket_pdf_attachments()), .ics (Calendar) and .pkpass (Wallet) through
 * wpcap_ticket_resend_attachments. The temp files are removed afterwards.
 */

use PassCaptain\Modules\Calendar;
use PassCaptain\Orders;
use PassCaptain\Settings;

echo "A621: resend mail with PDF and .ics\n";
$a621_saved_cal = Settings::get( 'calendar' );
Settings::update( array( 'calendar' => array( 'checkout_url' => 'https://example.test/tickets/', 'attach_ics' => 1 ) ) );
if ( ! has_filter( 'wpcap_ticket_resend_attachments', array( Calendar::class, 'resend_ics' ) ) ) {
	add_filter( 'wpcap_ticket_resend_attachments', array( Calendar::class, 'resend_ics' ), 20, 3 );
	$a621_added = true;
}
$a621_type = make_type( 5, 10, 0 );
$a621 = Orders::create( array( 'email' => 'x4-a621@example.com', 'items' => array( array( 'ticket_type_id' => $a621_type, 'quantity' => 2 ) ), 'idempotency_key' => 'x4-a621-' . uniqid() ) );
$a621_before = count( $GLOBALS['wpcap_test_mails'] );
$a621_res = Orders::resend_tickets( (int) $a621['order_id'] );
$a621_mail = array_values( array_filter( array_slice( $GLOBALS['wpcap_test_mails'], $a621_before ), static fn( $m ) => 'x4-a621@example.com' === $m['to'] ) )[0] ?? array();
$a621_names = array_keys( (array) ( $a621_mail['files'] ?? array() ) );
$a621_pdf = (string) ( array_values( array_filter( (array) ( $a621_mail['files'] ?? array() ), static fn( $c, $n ) => str_ends_with( $n, '.pdf' ), ARRAY_FILTER_USE_BOTH ) )[0] ?? '' );
check( 'A621: the resend succeeds', true === $a621_res );
check( 'A621: the resend mail has the ticket PDF' . ' – ' . implode( ', ', $a621_names ), (bool) preg_grep( '/^tickets-.*\.pdf$/', $a621_names ) && str_starts_with( $a621_pdf, '%PDF-1.4' ) && 2 === preg_match_all( '#/Type /Page /Parent#', $a621_pdf ) );
check( 'A621: the resend mail has the .ics file of the date', (bool) preg_grep( '/\.ics$/', $a621_names ) );
// The PDF carries the codes of this mail: the links in the mail point at the same codes.
preg_match_all( '/wpcap_ticket=([a-f0-9]{64})/', (string) ( $a621_mail['body'] ?? '' ), $a621_links );
$a621_plain = wpcap_pdf_plain( $a621_pdf );
$a621_codes = array_unique( $a621_links[1] );
check( 'A621: the PDF prints the codes sent in this mail (code text in two halves)', 2 === count( $a621_codes ) && ! array_filter( $a621_codes, static fn( $c ) => false === strpos( $a621_plain, '(' . substr( $c, 0, 32 ) . ') Tj' ) || false === strpos( $a621_plain, '(' . substr( $c, 32 ) . ') Tj' ) ) );
check( 'A621: no PDF temp directory is left behind', ! glob( rtrim( get_temp_dir(), '/' ) . '/wpcap-*/tickets-*.pdf' ) );
if ( ! empty( $a621_added ) ) { remove_filter( 'wpcap_ticket_resend_attachments', array( Calendar::class, 'resend_ics' ), 20 ); }
Settings::update( array( 'calendar' => is_array( $a621_saved_cal ) ? $a621_saved_cal : array() ) );

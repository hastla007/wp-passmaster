<?php
/**
 * A497: names saved through sanitize_text_field() keep a lone "<" as "&lt;" (and may carry "&amp;"
 * etc.). HTML output shows them right; every plain-text output (CSV, iCalendar, ticket PDF, badge/list
 * PDF, wallet pass, text mail, subject) decodes them with Text::plain() instead of showing "&lt;".
 */

// phpcs:ignoreFile

use PassCaptain\Text;

global $wpdb;
$p = $wpdb->prefix;

echo "A497: stored entities are plain text in non-HTML outputs\n";
$a497_stored = 'Kids &lt;3 &amp; Co &quot;Live&quot;'; // What sanitize_text_field( 'Kids <3 & Co "Live"' ) and older saves leave.
$a497_plain = 'Kids <3 & Co "Live"';
check( 'A497: Text::plain decodes complete entities only (a&b, &lang=de stay), arrays become ""', $a497_plain === Text::plain( $a497_stored ) && 'a&b' === Text::plain( 'a&b' ) && 'x?a=1&lang=de' === Text::plain( 'x?a=1&lang=de' ) && '' === Text::plain( array( 'x' ) ) && 'Grüße' === Text::plain( 'Grüße' ) );

// CSV: decoded before the formula guard.
check( 'A497: CSV cell shows the plain name; an encoded "=" is still guarded', $a497_plain === PassCaptain\Reports\Scope::csv_cell( $a497_stored ) && "'=1+1" === PassCaptain\Reports\Scope::csv_cell( '&#61;1+1' ) );

// iCalendar.
check( 'A497: iCalendar TEXT value is the plain name (RFC 5545 escaping after decoding)', 'Kids <3 & Co "Live"\\, Wien' === PassCaptain\Calendar\Ics::escape_text( $a497_stored . ', Wien' ) );

// Text mail body and subject (HTML mode keeps escaping the value).
$a497_msg = PassCaptain\Mail\Mailer::render_template( 'ticket_assigned_attendee', array( 'subject' => 'Ticket: {event_name}', 'body' => "Hallo {attendee_name}\nEvent: {event_name}", 'format' => 'text' ), array( 'event_name' => $a497_stored, 'attendee_name' => 'Anna &lt;Admin&gt;' ) );
check( 'A497: text mail and subject show the plain name', 'Ticket: ' . $a497_plain === $a497_msg['subject'] && false !== strpos( $a497_msg['body'], 'Event: ' . $a497_plain ) && false !== strpos( $a497_msg['body'], 'Hallo Anna <Admin>' ) && false === strpos( $a497_msg['body'] . $a497_msg['subject'], '&lt;' ) );
$a497_block = PassCaptain\Mail\Mailer::replace( '{list}', array( 'list' => PassCaptain\Mail\Mailer::block( 'Event: ' . $a497_stored, '<b>x</b>' ) ), false );
check( 'A497: text variant of pre-rendered blocks (ticket lists) is plain too', 'Event: ' . $a497_plain === $a497_block );

// Ticket PDF and the PDF writer of badges/lists: WinAnsi string without entities.
$a497_pdf = PassCaptain\TicketPdf::render( array( array( 'event' => $a497_stored, 'ticket' => 'Standard', 'when' => '01.01.2027 19:00', 'location' => 'Wien', 'token' => str_repeat( 'a', 64 ) ) ) );
check( 'A497: ticket PDF prints the plain name', false !== strpos( $a497_pdf, '(Kids <3 & Co "Live") Tj' ) && false === strpos( $a497_pdf, '&lt;' ) );
$a497_writer = new PassCaptain\Printing\PdfWriter();
$a497_writer->add_page( 200, 100 );
$a497_writer->text( 10, 10, 10, $a497_stored );
$a497_bytes = $a497_writer->output( 'A497' );
check( 'A497: badge/list PDF writer prints the plain name, fit()/wrap() measure the plain text', false !== strpos( $a497_bytes, '(Kids <3 & Co "Live")' ) && false === strpos( $a497_bytes, '&lt;' ) && array( 'Kids', '<3' ) === array_slice( PassCaptain\Printing\PdfWriter::wrap( 'Kids &lt;3', 10, 1 ), 0, 2 ) && false === strpos( PassCaptain\Printing\PdfWriter::fit( $a497_stored, 10, 1000 ), '&' . 'lt;' ) );

// Wallet pass data.
$a497_type = make_type( 10, 10, 0 );
$a497_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=" . (int) $a497_type );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_events e JOIN {$p}wpcap_occurrences o ON o.event_id=e.event_id SET e.name=%s, o.location=%s WHERE o.occurrence_id=%d", $a497_stored, 'Halle &amp; Hof', $a497_occ ) );
$wpdb->update( "{$p}wpcap_ticket_types", array( 'name' => 'Eltern &amp; Kind' ), array( 'ticket_type_id' => $a497_type ) );
$a497_o = PassCaptain\Orders::create( array( 'email' => 'a497@example.com', 'items' => array( array( 'ticket_type_id' => $a497_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a497-' . uniqid(), 'attendees' => array( array( 'ticket_type_id' => $a497_type, 'name' => 'Max <3 Muster', 'email' => '' ) ) ) );
$a497_tid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=%d", is_wp_error( $a497_o ) ? 0 : $a497_o['order_id'] ) );
$a497_pass = $a497_tid ? PassCaptain\WalletPasses\Service::ticket( $a497_tid ) : null;
check( 'A497: wallet pass data: event, ticket type, location and attendee as plain text', $a497_pass && $a497_plain === $a497_pass['event_name'] && 'Eltern & Kind' === $a497_pass['ticket_name'] && 'Halle & Hof' === $a497_pass['location'] && false === strpos( $a497_pass['attendee_name'], '&' ) );

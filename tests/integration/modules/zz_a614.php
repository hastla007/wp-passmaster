<?php
/**
 * A614: the age/ID notice and the ticket holder name appear on the ticket page, in the ticket mail and on the
 * ticket PDF, not only on the event card.
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

use PassCaptain\AdmissionChecks;
use PassCaptain\Mail\Blocks;
use PassCaptain\Printing\Templates;
use PassCaptain\TicketPdf;

if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }

echo "A614: admission notice and holder on ticket, mail and PDF\n";
global $wpdb;
$p = $wpdb->prefix;
$a614_type = make_type( 30, 10, 2000 );
$a614_plain = make_type( 30, 10, 2000 );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_ticket_types SET id_check=1, min_age=18 WHERE ticket_type_id=%d", $a614_type ) );
check( 'A614: notice of the ticket type matches the event card', 'ab 18 Jahren, Ausweis erforderlich' === AdmissionChecks::notice_for_type( $a614_type ) && '' === AdmissionChecks::notice_for_type( $a614_plain ) && '' === AdmissionChecks::notice_for_type( 0 ) );
check( 'A614: holder line with name and notice, empty without both', 'Ticketinhaber: Mia Muster · ab 18 Jahren, Ausweis erforderlich' === AdmissionChecks::holder_line( ' Mia Muster ', $a614_type ) && '' === AdmissionChecks::holder_line( '', $a614_plain ) && 'Ticketinhaber: Max' === AdmissionChecks::holder_line( 'Max', $a614_plain ) );
$a614_ticket = array( 'ticket_id' => 0, 'ticket_type_id' => $a614_type, 'event' => 'Konzert', 'ticket' => 'Stehplatz', 'when' => '21.06.2027 20:00', 'location' => 'Halle', 'url' => 'https://example.com/t', 'token' => 'TOKEN', 'attendee' => 'Mia Muster' );
$a614_mail = Blocks::issued_tickets( array( $a614_ticket ) );
check( 'A614: the ticket mail shows holder and notice (text and HTML)', false !== strpos( $a614_mail['text'], 'Ticketinhaber: Mia Muster · ab 18 Jahren, Ausweis erforderlich' ) && false !== strpos( $a614_mail['html'], 'Ticketinhaber: Mia Muster' ) );
$a614_plain_mail = Blocks::issued_tickets( array( array( 'ticket_type_id' => $a614_plain, 'attendee' => '' ) + $a614_ticket ) );
check( 'A614: without name and notice the mail reads as before', false === strpos( $a614_plain_mail['text'], 'Ticketinhaber' ) && false !== strpos( $a614_plain_mail['text'], "Halle\n\n" ) );
$a614_values = TicketPdf::values( $a614_ticket );
check( 'A614: the PDF layout gets {admission_notice}', 'ab 18 Jahren, Ausweis erforderlich' === $a614_values['admission_notice'] && in_array( '{admission_notice}', Templates::PLACEHOLDERS, true ) && false !== strpos( Templates::default_body( 'ticket' ), '{admission_notice}' ) );
check( 'A614: the placeholder is filled in the ticket layout', 'ab 18 Jahren, Ausweis erforderlich' === Templates::fill( '{admission_notice}', $a614_values ) );
check( 'A614: the ticket page shows the holder line', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/TicketPage.php' ), 'AdmissionChecks::holder_line(' ) );

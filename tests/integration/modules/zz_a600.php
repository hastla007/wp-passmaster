<?php
// phpcs:ignoreFile
/**
 * A600: the order confirmation links the .ics of the ordered date right away (WPCAP_CHECKOUT.icsBase
 * + &id={occurrence}, shown by checkout.js – tests/js/checkout-x3.test.js); the link answers with
 * the calendar of the date. No ticket link in the browser (open decision, see report).
 */
use PassCaptain\Modules\Calendar;

echo "A600: calendar entry in the order confirmation\n";
global $wpdb;
$a600_type = make_type( 10 );
$a600_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$wpdb->prefix}wpcap_ticket_types WHERE ticket_type_id=$a600_type" );
$a600_src = (string) file_get_contents( WPCAP_PATH . 'src/PublicEvents.php' );
check( 'A600 the checkout config carries the .ics base URL (only with the calendar module)', false !== strpos( $a600_src, "'icsBase' => Modules::enabled( Modules\\Calendar::class ) ? esc_url_raw( add_query_arg( 'wpcap_ics', 'occurrence', home_url( '/' ) ) ) : ''," ) && false !== strpos( $a600_src, "'addToCalendar' => __( 'Termin in den Kalender eintragen (.ics)', 'pass-captain' )," ) );
check( 'A600 the base plus &id= is the public .ics URL of the date', add_query_arg( 'wpcap_ics', 'occurrence', home_url( '/' ) ) . '&id=' . $a600_occ === PassCaptain\Calendar\Ics::occurrence_url( $a600_occ ) );
check( 'A600 that URL answers with the calendar of the date', false !== strpos( (string) Calendar::ics_for( 'occurrence', $a600_occ ), 'UID:wpcap-occurrence-' . $a600_occ . '@' ) );

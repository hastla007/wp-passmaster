<?php
/**
 * A377/A415: consistent terms – "Ticketart" (not "Ticketkategorie"), "Veranstalter" for partners,
 * "Storniert" for orders/tickets vs "Abgesagt" for events/dates, user picker instead of an ID field.
 */

use PassCaptain\Admin\Forms;

echo "A377/A415: terms and labels\n";
$a377_src = '';
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__, 3 ) . '/src' ) ) as $a377_f ) {
	if ( str_ends_with( (string) $a377_f, '.php' ) ) { $a377_src .= (string) file_get_contents( (string) $a377_f ); }
}
check( 'A377: no "Ticketkategorie" left in the plugin', false === stripos( $a377_src, 'Ticketkategorie' ) );
check( 'A377: no partner label "– kein Partner –"', false === strpos( $a377_src, 'kein Partner' ) );
check( 'A415: cancelled reads Storniert for orders/tickets, Abgesagt for events/dates', 'Storniert' === Forms::status_label( 'cancelled', 'order' ) && 'Storniert' === Forms::status_label( 'cancelled', 'ticket' ) && 'Abgesagt' === Forms::status_label( 'cancelled', 'occurrence' ) && 'Abgesagt' === Forms::status_label( 'cancelled', 'event' ) );
check( 'A415: other statuses are unchanged by the object hint', Forms::status_label( 'paid' ) === Forms::status_label( 'paid', 'order' ) );

ob_start(); Forms::user_select( 'user_id', 0, 'aria-describedby="x"' ); $a377_html = (string) ob_get_clean();
if ( function_exists( 'get_users' ) ) {
	check( 'A377: user picker is a select with a "no user" option', str_contains( $a377_html, '<select name="user_id"' ) && str_contains( $a377_html, 'value="0"' ) && str_contains( $a377_html, 'aria-describedby="x"' ) );
} else {
	check( 'A377: without get_users() the picker falls back to the user-ID field', str_contains( $a377_html, 'type="number" name="user_id"' ) && str_contains( $a377_html, 'aria-describedby="x"' ) );
}

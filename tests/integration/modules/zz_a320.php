<?php
// phpcs:ignoreFile
/**
 * A320: print and receipt pages declare the language they are rendered in (determine_locale(),
 * dir="rtl" for right-to-left locales) instead of a fixed lang="de".
 */
echo "A320: lang attribute of print/receipt pages\n";
global $wpdb;
$p = $wpdb->prefix;
$a320_type = make_type( 5 );
$a320_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a320_type" );

$GLOBALS['wpcap_test_locale'] = 'en_US';
$a320_pages = array(
	'attendee list' => PassCaptain\Printing\AttendeeList::html( $a320_occ, 'basic' ),
	'no-show list' => PassCaptain\Printing\NoShowList::html( $a320_occ, 'basic' ),
	'payout receipt' => PassCaptain\BoxOffice\ZReport::payout_receipt_html( (object) array( 'sale_id' => 0, 'currency' => 'EUR', 'ticket_ids' => '[]', 'receipt_number' => 'A320-1', 'amount_minor' => 0, 'method' => 'cash', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'register_id' => 0, 'shift_id' => 0, 'kind' => 'refund', 'tax_breakdown' => '[]', 'created_by' => 0, 'vat' => '[]', 'reason' => '' ) ),
);
foreach ( $a320_pages as $a320_name => $a320_html ) {
	check( "A320: $a320_name uses the current locale (en-US), not lang=\"de\"", is_string( $a320_html ) && false !== strpos( $a320_html, '<html lang="en-US">' ) );
}
$GLOBALS['wpcap_test_locale'] = 'ar';
$GLOBALS['wpcap_test_rtl'] = true;
check( 'A320: right-to-left locale gets dir="rtl"', 'lang="ar" dir="rtl"' === PassCaptain\Frontend\Format::html_lang_attributes() && false !== strpos( (string) PassCaptain\Printing\AttendeeList::html( $a320_occ, 'basic' ), '<html lang="ar" dir="rtl">' ) );
unset( $GLOBALS['wpcap_test_locale'], $GLOBALS['wpcap_test_rtl'] );
check( 'A320: default test locale renders de-DE', 'lang="de-DE"' === PassCaptain\Frontend\Format::html_lang_attributes() );

// No standalone page in src/ hard-codes its language any more.
$a320_fixed = array();
$a320_it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__, 3 ) . '/src', FilesystemIterator::SKIP_DOTS ) );
foreach ( $a320_it as $a320_file ) {
	if ( 'php' !== $a320_file->getExtension() ) { continue; }
	$a320_src = (string) file_get_contents( $a320_file->getPathname() );
	if ( preg_match( '/<html lang="[a-z]{2}(-[A-Z]{2})?"/', $a320_src ) || false !== strpos( $a320_src, "lang=\"' . esc_attr( determine_locale() )" ) ) { $a320_fixed[] = basename( $a320_file->getPathname() ); }
}
check( 'A320: no page with a fixed or non-BCP-47 lang attribute' . ( $a320_fixed ? ' – ' . implode( ', ', $a320_fixed ) : '' ), ! $a320_fixed );

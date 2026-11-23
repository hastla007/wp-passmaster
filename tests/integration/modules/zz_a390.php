<?php
/**
 * A390: RTL – public pages set dir="rtl" for right-to-left locales, the stylesheets use logical properties.
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

echo "A390: RTL support\n";
$a390_phys = array();
foreach ( array( 'assets/public.css', 'assets/seating/picker.css', 'assets/seating/editor.css', 'assets/offline-scanner/app.css' ) as $a390_f ) {
	if ( preg_match_all( '/(?:margin|padding|border)-(?:left|right)\s*:|text-align\s*:\s*(?:left|right)|float\s*:\s*(?:left|right)/', (string) file_get_contents( WPCAP_PATH . $a390_f ), $a390_m ) ) { $a390_phys[ $a390_f ] = count( $a390_m[0] ); }
}
check( 'A390: front-end stylesheets use logical properties only (' . wp_json_encode( $a390_phys ) . ')', array() === $a390_phys );
$a390_pages = array( 'src/TicketPage.php', 'src/OrderPortal.php', 'src/TicketRecovery.php', 'src/Security/AccessCookie.php', 'src/Sales/Consents.php', 'src/Services/Portal.php', 'src/Mail/Suppression.php', 'src/Modules/Reminders.php' );
$a390_missing = array();
foreach ( $a390_pages as $a390_p ) {
	if ( false === strpos( (string) file_get_contents( WPCAP_PATH . $a390_p ), 'html_lang_attributes()' ) ) { $a390_missing[] = $a390_p; }
}
check( 'A390: public pages take lang and dir from Format::html_lang_attributes() (' . implode( ', ', $a390_missing ) . ')', array() === $a390_missing );
if ( function_exists( 'is_rtl' ) ) {
	check( 'A390: the helper adds dir only for RTL', false === strpos( PassCaptain\Frontend\Format::html_lang_attributes(), 'dir=' ) || is_rtl() );
} else {
	function is_rtl() { return ! empty( $GLOBALS['a390_rtl'] ); }
	$GLOBALS['a390_rtl'] = true;
	$a390_rtl = PassCaptain\Frontend\Format::html_lang_attributes();
	$GLOBALS['a390_rtl'] = false;
	check( 'A390: an RTL locale gets dir="rtl", others none', false !== strpos( $a390_rtl, 'dir="rtl"' ) && false === strpos( PassCaptain\Frontend\Format::html_lang_attributes(), 'dir=' ) );
}

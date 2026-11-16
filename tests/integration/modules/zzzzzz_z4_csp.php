<?php
/**
 * Z4 / FE-8: strict Content-Security-Policy on the standalone token pages; no inline event handlers
 * (print buttons and admin confirm dialogs via data attributes + external scripts).
 */

// phpcs:ignoreFile

use PassCaptain\Security\PrivatePage;

global $wpdb;

echo "Z4 FE-8: strict CSP on standalone private pages\n";
PrivatePage::reset();
PrivatePage::send();
$z4c_default = PrivatePage::last_sent();
check( 'default private page keeps only frame-ancestors (theme pages, portal)', in_array( "Content-Security-Policy: frame-ancestors 'none'", $z4c_default, true ) );
PrivatePage::reset();
PrivatePage::send( true );
$z4c_strict = (string) ( preg_grep( '/^Content-Security-Policy: /', PrivatePage::last_sent() ) ? array_values( preg_grep( '/^Content-Security-Policy: /', PrivatePage::last_sent() ) )[0] : '' );
$z4c_directives = array();
foreach ( explode( ';', substr( $z4c_strict, strlen( 'Content-Security-Policy: ' ) ) ) as $z4c_part ) {
	$z4c_part = trim( $z4c_part );
	if ( '' === $z4c_part ) { continue; }
	$z4c_bits = preg_split( '/\s+/', $z4c_part );
	$z4c_directives[ array_shift( $z4c_bits ) ] = $z4c_bits;
}
check( 'strict CSP: default-src self, script-src self without unsafe-inline/eval', array( "'self'" ) === ( $z4c_directives['default-src'] ?? null ) && array( "'self'" ) === ( $z4c_directives['script-src'] ?? null ) );
check( 'strict CSP: img-src self + data:, style-src self + unsafe-inline (inline <style> block)', array( "'self'", 'data:' ) === ( $z4c_directives['img-src'] ?? null ) && array( "'self'", "'unsafe-inline'" ) === ( $z4c_directives['style-src'] ?? null ) );
check( 'strict CSP: frame-ancestors none, base-uri none, form-action self, object-src none', array( "'none'" ) === ( $z4c_directives['frame-ancestors'] ?? null ) && array( "'none'" ) === ( $z4c_directives['base-uri'] ?? null ) && array( "'self'" ) === ( $z4c_directives['form-action'] ?? null ) && array( "'none'" ) === ( $z4c_directives['object-src'] ?? null ) );
check( 'strict CSP keeps the other private-page headers', in_array( 'X-Frame-Options: DENY', PrivatePage::last_sent(), true ) && (bool) preg_grep( '/^Cache-Control: no-store/', PrivatePage::last_sent() ) );
$z4c_filter = static function ( $policy ) { return $policy . "; img-src 'self' data: https://logo.example\r\nX-Injected: 1"; };
add_filter( 'wpcap_strict_csp', $z4c_filter );
$z4c_filtered = PrivatePage::strict_csp();
remove_filter( 'wpcap_strict_csp', $z4c_filter );
check( 'wpcap_strict_csp can extend the policy; line breaks are stripped (no header injection)', false !== strpos( $z4c_filtered, 'https://logo.example' ) && false === strpos( $z4c_filtered, "\n" ) && false === strpos( $z4c_filtered, "\r" ) );
PrivatePage::reset();

$z4c_src = static function ( $class, $method ) {
	$rm = new ReflectionMethod( $class, $method );
	return implode( '', array_slice( file( $rm->getFileName() ), $rm->getStartLine() - 1, $rm->getEndLine() - $rm->getStartLine() + 1 ) );
};
$z4c_strict_pages = array(
	array( \PassCaptain\TicketPage::class, 'maybe_render' ),
	array( \PassCaptain\Invoices::class, 'maybe_render_customer_invoice' ),
	array( \PassCaptain\Invoices::class, 'handle_admin_print' ),
	array( \PassCaptain\Sales\Consents::class, 'maybe_render_attendee_page' ),
);
$z4c_missing = array();
foreach ( $z4c_strict_pages as list( $class, $method ) ) {
	if ( false === strpos( $z4c_src( $class, $method ), 'PrivatePage::send( true )' ) ) { $z4c_missing[] = $class . '::' . $method; }
}
check( 'ticket page, invoices and consent page send the strict CSP' . ( $z4c_missing ? ' – missing: ' . implode( ', ', $z4c_missing ) : '' ), ! $z4c_missing );
check( 'theme page with scanner shortcode does not get the strict CSP', false === strpos( $z4c_src( \PassCaptain\PublicEvents::class, 'protect_scanner_page' ), 'send( true )' ) );

echo "Z4 FE-8: no inline JavaScript on the standalone pages\n";
$z4c_ticket_src = (string) file_get_contents( WPCAP_PATH . 'src/TicketPage.php' );
check( 'ticket page: print button via data-wpcap-print and external script, no onclick/inline <script>', false === strpos( $z4c_ticket_src, 'onclick' ) && false !== strpos( $z4c_ticket_src, 'PrintButton::html' ) && ! preg_match( '/<script>(?!<\/script>)/', $z4c_ticket_src ) );
$z4c_button = \PassCaptain\Frontend\PrintButton::html( 'Drucken <x>', 'print' );
check( 'PrintButton: data-wpcap-print button, escaped label, versioned external print.js', false !== strpos( $z4c_button, '<button type="button" data-wpcap-print class="print">Drucken &lt;x&gt;</button>' ) && 1 === preg_match( '#<script src="[^"]*assets/print\.js\?ver=[^"]+" defer></script>#', $z4c_button ) && false === strpos( $z4c_button, 'onclick' ) );
$z4c_type = make_type( 10, 10, 0 );
$z4c_occ = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT occurrence_id FROM %i WHERE ticket_type_id=%d', $wpdb->prefix . 'wpcap_ticket_types', $z4c_type ) );
$z4c_list = \PassCaptain\Printing\AttendeeList::html( $z4c_occ, 'basic' );
check( 'attendee list: print button without inline handler', false !== strpos( $z4c_list, 'data-wpcap-print' ) && false === stripos( $z4c_list, 'onclick' ) );
check( 'POS receipt auto-print via body data attribute (no onload handler)', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/BoxOffice/Service.php' ), '<body data-wpcap-autoprint>' ) && false === strpos( (string) file_get_contents( WPCAP_PATH . 'src/BoxOffice/Service.php' ), 'onload=' ) );
check( 'print.js exists and contains no eval', is_readable( WPCAP_PATH . 'assets/print.js' ) && false === strpos( (string) file_get_contents( WPCAP_PATH . 'assets/print.js' ), 'eval(' ) );

echo "Z4 FE-8: admin confirm dialogs via data-wpcap-confirm\n";
// Merged Menu: admin_styles() adds the plugin page assets through plugin_page_assets().
check( 'admin-confirm.js is enqueued on the plugin pages (admin_styles)', false !== strpos( $z4c_src( \PassCaptain\Admin\Menu::class, 'admin_styles' ) . $z4c_src( \PassCaptain\Admin\Menu::class, 'plugin_page_assets' ), "assets/admin-confirm.js" ) && ( false !== strpos( $z4c_src( \PassCaptain\Admin\Menu::class, 'admin_styles' ), "assets/admin-confirm.js" ) || false !== strpos( $z4c_src( \PassCaptain\Admin\Menu::class, 'admin_styles' ), 'plugin_page_assets()' ) ) );
$z4c_offenders = array();
$z4c_it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WPCAP_PATH . 'src', FilesystemIterator::SKIP_DOTS ) );
foreach ( $z4c_it as $z4c_file ) {
	if ( 'php' !== $z4c_file->getExtension() ) { continue; }
	if ( preg_match( '/\son(click|submit|change|load|focus|input)\s*=/i', (string) file_get_contents( $z4c_file->getPathname() ) ) ) { $z4c_offenders[] = substr( $z4c_file->getPathname(), strlen( WPCAP_PATH ) ); }
}
check( 'no inline event handlers left in src/' . ( $z4c_offenders ? ' – ' . implode( ', ', $z4c_offenders ) : '' ), ! $z4c_offenders );
// Merged EventsPage: cancelling goes through a confirmation page (A266/A356), no confirm dialog left there.
check( 'confirm texts are HTML-escaped data attributes (Orders, Tickets, Status, Webhooks, guest lists, exchange)', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Admin/OrdersPage.php' ), 'data-wpcap-confirm="' ) && false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Admin/TicketsPage.php' ), 'data-wpcap-confirm="' ) && false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/GuestLists/Page.php' ), 'data-wpcap-confirm="' ) && false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Exchange/AdminPage.php' ), 'data-wpcap-confirm="' ) && false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Admin/StatusPage.php' ), 'data-wpcap-confirm="' ) && false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Modules/Webhooks.php' ), 'data-wpcap-confirm="' ) );

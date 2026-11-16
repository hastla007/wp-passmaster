<?php
/**
 * A407: theme overrides for the ticket page, order portal, ticket recovery page and mail layout through
 * PublicEvents::page() (theme folder pass-captain/, template gets its variables and the default HTML).
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

use PassCaptain\PublicEvents;
use PassCaptain\TicketRecovery;

if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $a = -1 ) { return 'n'; } }

echo "A407: theme overrides for pages and the mail layout\n";
$a407_dir = sys_get_temp_dir() . '/wpcap-a407-' . uniqid();
mkdir( $a407_dir . '/pass-captain', 0777, true );
$a407_before = $GLOBALS['wpcap_test_theme_dirs'] ?? null;
check( 'A407: without a theme template the default is returned unchanged', '<p>default</p>' === PublicEvents::page( 'ticket-page.php', '<p>default</p>', array( 'ticket' => null ) ) );
file_put_contents( $a407_dir . '/pass-captain/ticket-recovery.php', '<?php echo "<div class=\"theme\" data-step=\"" . esc_attr( $step ) . "\">" . $default_html . "</div>";' );
file_put_contents( $a407_dir . '/pass-captain/email-layout.php', '<?php echo "<html><body class=\"theme-mail\">" . $content . "<footer>" . esc_html( $site ) . "</footer></body></html>";' );
$GLOBALS['wpcap_test_theme_dirs'] = array( $a407_dir );
$a407_page = TicketRecovery::page( '1' );
check( 'A407: the theme template wraps the page and gets its variables', 0 === strpos( $a407_page, '<div class="theme" data-step="request">' ) && false !== strpos( $a407_page, 'Tickets anfordern' ) );
$a407_mail = PassCaptain\Mail\Layout::wrap( '<p>Hallo</p>', 'Betreff' );
check( 'A407: the mail layout can be replaced by the theme', false !== strpos( $a407_mail, 'class="theme-mail"' ) && false !== strpos( $a407_mail, '<p>Hallo</p>' ) );
$a407_filter = static function ( $html, $name ) { return 'x.php' === $name ? 'filtered' : $html; };
add_filter( 'wpcap_template_page', $a407_filter, 10, 2 );
check( 'A407: wpcap_template_page filters the result', 'filtered' === PublicEvents::page( 'x.php', 'default' ) );
remove_filter( 'wpcap_template_page', $a407_filter, 10 );
$GLOBALS['wpcap_test_theme_dirs'] = $a407_before;
if ( null === $a407_before ) { unset( $GLOBALS['wpcap_test_theme_dirs'] ); }
array_map( 'unlink', glob( $a407_dir . '/pass-captain/*' ) );
rmdir( $a407_dir . '/pass-captain' );
rmdir( $a407_dir );
$a407_src = (string) file_get_contents( WPCAP_PATH . 'src/TicketPage.php' ) . (string) file_get_contents( WPCAP_PATH . 'src/OrderPortal.php' );
check( 'A407: ticket page and order portal go through the override', false !== strpos( $a407_src, "PublicEvents::page( 'ticket-page.php'" ) && false !== strpos( $a407_src, "PublicEvents::page( 'order-portal.php'" ) );

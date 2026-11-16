<?php
// phpcs:ignoreFile
/**
 * A659: theme overrides of the event card in the folder used before the rename (<theme>/<old slug>/)
 * are still used (not silently replaced by the bundled card), with a deprecation note and an admin
 * notice until the theme moves them to <theme>/pass-captain/. The old name is assembled from parts.
 */

use PassCaptain\LegacySlug;
use PassCaptain\PublicEvents;

$a659_old = 'wp-' . 'pass-captain';
if ( ! function_exists( 'get_theme_root' ) ) { function get_theme_root() { return $GLOBALS['a659_theme_root'] ?? ''; } }
if ( ! function_exists( '_deprecated_file' ) ) { function _deprecated_file( $file, $version, $replacement = '', $message = '' ) { $GLOBALS['a659_deprecated'][] = array( $file, $version, $replacement ); } }
if ( ! function_exists( 'wp_nonce_url' ) ) { function wp_nonce_url( $u, $a = -1 ) { return $u . '&_wpnonce=n'; } }
if ( ! function_exists( 'wp_verify_nonce' ) ) { function wp_verify_nonce( $n, $a = -1 ) { return 'n' === $n ? 1 : false; } }
if ( ! function_exists( 'get_user_meta' ) ) { function get_user_meta( $u, $k, $single = false ) { return $GLOBALS['p1u_user_meta'][ $u ][ $k ] ?? ''; } }
if ( ! function_exists( 'update_user_meta' ) ) { function update_user_meta( $u, $k, $v ) { $GLOBALS['p1u_user_meta'][ $u ][ $k ] = $v; return true; } }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) {} }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) {} }
if ( ! function_exists( 'esc_attr_e' ) ) { function esc_attr_e( $t, $d = null ) { echo esc_attr( $t ); } }
if ( ! function_exists( 'checked' ) ) { function checked( $a, $b = true, $echo = true ) { $r = $a == $b ? ' checked="checked"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $t ) { return '<p>' . $t . '</p>'; } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }

$a659_root  = sys_get_temp_dir() . '/wpcap-a659-' . getmypid();
$a659_child = $a659_root . '/child';
$a659_parent = $a659_root . '/parent';
@mkdir( $a659_child . '/' . $a659_old, 0777, true );
@mkdir( $a659_parent . '/pass-captain', 0777, true );
$GLOBALS['a659_theme_root'] = $a659_root;
$GLOBALS['a659_deprecated'] = array();
$GLOBALS['wpcap_test_theme_dirs'] = array( $a659_child, $a659_parent );
delete_option( LegacySlug::THEME_TEMPLATE_OPTION );
$a659_notice = static function () {
	ob_start();
	LegacySlug::theme_template_notice();
	return (string) ob_get_clean();
};

// No override at all: bundled template, no record, no notice.
check( 'A659: without a theme override no theme template is used and nothing is recorded', '' === PublicEvents::theme_template( 'event-card.php' ) && false === get_option( LegacySlug::THEME_TEMPLATE_OPTION ) && '' === $a659_notice() );

// Only the old folder has an override: it is used (before the fix the bundled card was used silently).
file_put_contents( $a659_child . '/' . $a659_old . '/event-card.php', "<?php echo 'old card';\n" );
$a659_found = PublicEvents::theme_template( 'event-card.php' );
check( 'A659: an override only in the old theme folder is still used', $a659_child . '/' . $a659_old . '/event-card.php' === $a659_found );
check( 'A659: its use is recorded with a path relative to the themes directory (no server path)', array( 'event-card.php' => 'child/' . $a659_old . '/event-card.php' ) === get_option( LegacySlug::THEME_TEMPLATE_OPTION ) );
check( 'A659: a deprecation note names the old file and the new folder (once per request)', 1 === count( $GLOBALS['a659_deprecated'] ) && 'child/' . $a659_old . '/event-card.php' === $GLOBALS['a659_deprecated'][0][0] && 'pass-captain/event-card.php' === $GLOBALS['a659_deprecated'][0][2] );
PublicEvents::theme_template( 'event-card.php' );
check( 'A659: repeated renders add no further deprecation note', 1 === count( $GLOBALS['a659_deprecated'] ) );
check( 'A659: path traversal in the template name is ignored', '' === PublicEvents::theme_template( '../' . $a659_old . '/x.php' ) );

// Admin notice for users who can change the theme; dismissible per user.
$GLOBALS['p1u_user_meta'] = array();
$a659_html = $a659_notice();
check( 'A659: admin notice names the old template and the new folder', false !== strpos( $a659_html, 'child/' . $a659_old . '/event-card.php' ) && false !== strpos( $a659_html, '„pass-captain/“' ) && false !== strpos( $a659_html, 'wpcap_dismiss_theme_notice' ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'switch_themes' );
check( 'A659: no notice for users who cannot change the theme', '' === $a659_notice() );
$GLOBALS['wpcap_test_denied_caps'] = array();
$_GET['wpcap_dismiss_theme_notice'] = '1';
$_GET['_wpnonce'] = 'n';
$a659_dismissed = $a659_notice();
unset( $_GET['wpcap_dismiss_theme_notice'], $_GET['_wpnonce'] );
check( 'A659: the notice can be dismissed (nonce) and stays hidden for that user', '' === $a659_dismissed && '' === $a659_notice() );
$_GET['wpcap_dismiss_theme_notice'] = '1';
$_GET['_wpnonce'] = 'bad';
$GLOBALS['wpcap_test_user_id'] = 77;
check( 'A659: dismissing needs a valid nonce', '' !== $a659_notice() );
unset( $_GET['wpcap_dismiss_theme_notice'], $_GET['_wpnonce'], $GLOBALS['wpcap_test_user_id'] );

// The new folder wins (also when it is only in the parent theme); the notice disappears by itself.
file_put_contents( $a659_parent . '/pass-captain/event-card.php', "<?php echo 'new card';\n" );
check( 'A659: an override in the new folder takes precedence over the old folder', $a659_parent . '/pass-captain/event-card.php' === PublicEvents::theme_template( 'event-card.php' ) );
$GLOBALS['wpcap_test_user_id'] = 77;
check( 'A659: once moved, the notice is gone and the record is cleared', '' === $a659_notice() && array() === LegacySlug::legacy_theme_templates() && false === get_option( LegacySlug::THEME_TEMPLATE_OPTION ) );
unset( $GLOBALS['wpcap_test_user_id'] );

// The event list renders the old-folder card (end to end through PublicEvents::render()).
unlink( $a659_parent . '/pass-captain/event-card.php' );
$a659_type = make_type( 10 );
$a659_occ = (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT occurrence_id FROM {$GLOBALS['wpdb']->prefix}wpcap_ticket_types WHERE ticket_type_id=%d", $a659_type ) );
PassCaptain\Orders::flush_availability_cache();
$a659_out = PublicEvents::render_shortcode( array( 'occurrence' => $a659_occ ) );
check( 'A659: the event list renders the card from the old theme folder', false !== strpos( (string) $a659_out, 'old card' ) );

// Cleanup.
$GLOBALS['wpcap_test_theme_dirs'] = array();
delete_option( LegacySlug::THEME_TEMPLATE_OPTION );
foreach ( array( $a659_child . '/' . $a659_old . '/event-card.php' ) as $a659_f ) { @unlink( $a659_f ); }
@rmdir( $a659_child . '/' . $a659_old );
@rmdir( $a659_parent . '/pass-captain' );
@rmdir( $a659_child );
@rmdir( $a659_parent );
@rmdir( $a659_root );

<?php
/**
 * A379: settings link in the plugin list, help tabs on the plugin screens, no references to docs/ files that
 * the plugin ZIP does not contain.
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

use PassCaptain\Admin\Menu;

if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }

echo "A379: plugin links, help tabs, no unshipped docs\n";
$a379_links = Menu::plugin_action_links( array( 'deactivate' => '<a>Deaktivieren</a>' ) );
check( 'A379: "Einstellungen" comes first in the plugin list, existing links stay', 'wpcap_settings' === array_key_first( $a379_links ) && false !== strpos( $a379_links['wpcap_settings'], 'page=wpcap-settings' ) && isset( $a379_links['deactivate'] ) );
$a379_screen = new class() {
	public $id = 'toplevel_page_pass-captain';
	public $tabs = array();
	public $sidebar = '';
	public function add_help_tab( $tab ) { $this->tabs[] = $tab; }
	public function set_help_sidebar( $html ) { $this->sidebar = $html; }
};
Menu::help_tabs( $a379_screen );
check( 'A379: plugin screens get help tabs and a sidebar', count( $a379_screen->tabs ) >= 2 && false !== strpos( $a379_screen->sidebar, 'page=wpcap-status' ) );
$a379_other = new class() { public $id = 'dashboard'; public $tabs = array(); public function add_help_tab( $tab ) { $this->tabs[] = $tab; } };
Menu::help_tabs( $a379_other );
check( 'A379: other screens are left alone', array() === $a379_other->tabs );
$a379_hits = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WPCAP_PATH . 'src', FilesystemIterator::SKIP_DOTS ) ) as $a379_file ) {
	if ( 'php' !== $a379_file->getExtension() ) { continue; }
	foreach ( file( $a379_file->getPathname() ) as $a379_n => $a379_line ) {
		if ( preg_match( "/(?:__|esc_html__|esc_html_e|esc_attr__|_e|_n)\\(\\s*'[^']*docs\\/[A-Za-z_-]+\\.md/", $a379_line ) ) { $a379_hits[] = basename( $a379_file->getPathname() ) . ':' . ( $a379_n + 1 ); }
	}
}
check( 'A379: no translatable text points to docs/*.md (not in the plugin ZIP) (' . implode( ', ', $a379_hits ) . ')', array() === $a379_hits );

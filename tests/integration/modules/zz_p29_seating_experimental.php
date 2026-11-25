<?php
/**
 * E09-1 (owner decision E-09, 25.11.2026): the seat map ships marked as "experimentell". The admin screen
 * "Saalpläne" (list and editor) shows a text badge in the heading and a notice the heading refers to
 * (aria-describedby), the module list in the settings carries the label, and readme.txt and
 * docs/RELEASE-MATRIX.md say so.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Seating\AdminPage;
use PassCaptain\Seating\Layouts;

echo "E09-1 seat map marked as experimental\n";

$e091_id = AdminPage::EXPERIMENTAL_ID;
$e091_marked = static function ( $html ) use ( $e091_id ) {
	return (bool) preg_match( '#<h1 aria-describedby="' . preg_quote( $e091_id, '#' ) . '">[^<]*<span class="screen-reader-text"> – </span><span class="wpcap-experimental-badge"[^>]*>experimentell</span></h1>#u', $html )
		&& (bool) preg_match( '#<div id="' . preg_quote( $e091_id, '#' ) . '" class="notice notice-warning inline wpcap-experimental-notice" role="note"><p><strong>Experimentelle Funktion:</strong> Der Saalplan ist im ersten Release als experimentell gekennzeichnet\.#u', $html );
};

// The badge is text with a border, not colour alone.
$e091_badge = AdminPage::experimental_badge();
check( 'E09-1: the badge is visible text with a border (not colour alone)', false !== strpos( $e091_badge, '>experimentell</span>' ) && false !== strpos( $e091_badge, 'border:1px solid currentColor' ) );

// List view.
ob_start();
AdminPage::render();
$e091_list = (string) ob_get_clean();
check( 'E09-1: the layout list shows the badge in the heading and the notice it describes', $e091_marked( $e091_list ) );
check( 'E09-1: the notice id occurs exactly once on the list view', 1 === substr_count( $e091_list, 'id="' . $e091_id . '"' ) );

// Editor view of a real layout.
global $wpdb;
$e091_now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( "{$wpdb->prefix}wpcap_events", array( 'name' => 'E09-1 Saalplan', 'slug' => 'e091-' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $e091_now, 'updated_at' => $e091_now ) );
$e091_event = (int) $wpdb->insert_id;
$e091_layout = Layouts::create( $e091_event, 'E09-1 Plan' );
$e091_layout_id = is_array( $e091_layout ) ? (int) $e091_layout['layout_id'] : 0;
check( 'E09-1: test layout created', $e091_layout_id > 0 );
$_GET['layout_id'] = $e091_layout_id;
ob_start();
AdminPage::render();
$e091_editor = (string) ob_get_clean();
unset( $_GET['layout_id'] );
check( 'E09-1: the seat map editor shows the badge in the heading and the notice it describes', false !== strpos( $e091_editor, 'wpcap-seat-editor' ) && $e091_marked( $e091_editor ) );

// Related setting: module list "Funktionen" in the settings.
$e091_modules = \PassCaptain\Admin\Dashboard::hideable_modules();
check( 'E09-1: the module list in the settings labels the seat map as experimental', 'Saalpläne (experimentell)' === ( $e091_modules['Seating'] ?? '' ) );

// Shipped texts.
$e091_root = dirname( __DIR__, 3 ) . '/';
$e091_readme = (string) file_get_contents( $e091_root . 'readme.txt' );
$e091_matrix = (string) file_get_contents( $e091_root . 'docs/RELEASE-MATRIX.md' );
check( 'E09-1: readme.txt features list marks seating plans as experimental', str_contains( $e091_readme, '* Seating plans (experimental in this release, see FAQ)' ) );
check( 'E09-1: readme.txt FAQ explains the experimental seat map', str_contains( $e091_readme, '= Is the seat map stable? =' ) && str_contains( $e091_readme, 'is marked as experimental in this release' ) );
check( 'E09-1: docs/RELEASE-MATRIX.md marks the seat map row as experimental', (bool) preg_match( '/^\| Sitzplätze \/ Saalplan \| teilweise, \*\*experimentell\*\* \|.*E-09/mu', $e091_matrix ) && str_contains( $e091_matrix, '| **experimentell** |' ) );

// Clean up the layout and the event.
if ( $e091_layout_id ) {
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE layout_id=%d', Layouts::table( 'layouts' ), $e091_layout_id ) );
}
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE event_id=%d', $wpdb->prefix . 'wpcap_events', $e091_event ) );

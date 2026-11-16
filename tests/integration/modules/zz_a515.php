<?php
// phpcs:ignoreFile
/**
 * A515: ambiguous short strings carry a gettext context: "Einlass" (doors time, admission area,
 * scanner result, scan direction, pass rule), "Offen" (remaining quantity, conflict status, shift
 * status) and the status vocabulary of Forms::status_label() ("status").
 */

use PassCaptain\Admin\Forms;

echo "A515: gettext contexts for ambiguous short strings\n";
$GLOBALS['wpcap_test_x'] = array( "status\x04Aktiv" => 'Active (status)', "status\x04Geplant" => 'Scheduled (status)' );
check( 'A515: status labels are translated in the "status" context', 'Active (status)' === Forms::status_label( 'active' ) && 'Scheduled (status)' === Forms::status_label( 'scheduled' ) );
$GLOBALS['wpcap_test_x'] = array();
$a515_forms = (string) file_get_contents( WPCAP_PATH . 'src/Admin/Forms.php' );
$a515_body = substr( $a515_forms, strpos( $a515_forms, 'public static function status_label(' ), 6000 );
$a515_body = substr( $a515_body, 0, strpos( $a515_body, 'return $labels' ) );
check( 'A515: no status label without context is left', false === strpos( $a515_body, "=> __( '" ) && substr_count( $a515_body, "'status', 'pass-captain' )" ) > 30 );
$a515_bare = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WPCAP_PATH . 'src' ) ) as $a515_file ) {
	if ( '.php' !== substr( (string) $a515_file, -4 ) ) { continue; }
	if ( preg_match_all( "/(?<![_a-z])(?:__|esc_html__|esc_attr__|esc_html_e|_e)\\( '(Einlass|Offen|offen)', 'pass-captain' \\)/", (string) file_get_contents( (string) $a515_file ), $a515_m ) ) {
		$a515_bare[] = basename( (string) $a515_file ) . ': ' . implode( ',', $a515_m[1] );
	}
}
check( 'A515: "Einlass"/"Offen" only appear with a context' . ( $a515_bare ? ' – ' . implode( ' | ', $a515_bare ) : '' ), ! $a515_bare );
$a515_contexts = array( 'src/Notifications.php' => 'doors open time', 'src/WalletPasses/AppleProvider.php' => 'doors open time', 'src/Admin/Menu.php' => 'admission (check-in area of work)', 'src/PublicEvents.php' => 'scanner result: entry granted', 'src/Admission/Rest.php' => 'scanner direction: entry (opposite of exit)' );
check( 'A515: each meaning of "Einlass" has its own context', ! array_filter( $a515_contexts, static fn( $ctx, $f ) => false === strpos( (string) file_get_contents( WPCAP_PATH . $f ), "_x( 'Einlass', '" . $ctx . "', 'pass-captain' )" ), ARRAY_FILTER_USE_BOTH ) );

<?php
/**
 * A381: destructive admin forms ask for confirmation (data-wpcap-confirm, assets/admin-confirm.js) or carry
 * an explicit confirmation checkbox. Static scan of every Forms::open_form() whose action deletes, removes,
 * revokes or cancels something.
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

echo "A381: destructive admin actions ask for confirmation\n";
$a381_missing = array();
// Forms with their own confirmation step: required checkbox on a separate confirmation page (event/occurrence
// cancellation) or a JS-driven terminal cancel on the POS screen.
$a381_exempt = array( 'wpcap_cancel_occurrence', 'wpcap_cancel_event', 'wpcap_pos_device_cancel' );
$a381_it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WPCAP_PATH . 'src', FilesystemIterator::SKIP_DOTS ) );
foreach ( $a381_it as $a381_file ) {
	if ( 'php' !== $a381_file->getExtension() ) { continue; }
	$a381_lines = file( $a381_file->getPathname() );
	foreach ( $a381_lines as $a381_n => $a381_line ) {
		if ( ! preg_match( "/open_form\\( '([a-z_]*(?:delete|remove|revoke|purge|discard|erase|unpair|cancel)[a-z_]*)'/", $a381_line, $a381_m ) ) { continue; }
		if ( in_array( $a381_m[1], $a381_exempt, true ) ) { continue; }
		$a381_window = implode( '', array_slice( $a381_lines, $a381_n, 13 ) );
		if ( false === strpos( $a381_window, 'wpcap-confirm' ) && false === strpos( $a381_window, 'confirm(' ) ) {
			$a381_missing[] = str_replace( WPCAP_PATH, '', $a381_file->getPathname() ) . ':' . ( $a381_n + 1 ) . ' ' . $a381_m[1];
		}
	}
}
check( 'A381: every delete/remove/revoke/cancel form asks for confirmation' . ( $a381_missing ? ' – missing: ' . implode( ', ', $a381_missing ) : '' ), ! $a381_missing );
check( 'A381: the confirm script is loaded on plugin pages', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Admin/Menu.php' ), "'wpcap-admin-confirm'" ) );

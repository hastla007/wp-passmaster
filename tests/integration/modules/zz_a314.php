<?php
/**
 * A314: box office pickers (date on the sale screen, register filter on the shift list) no longer
 * submit the form on every change – with the keyboard each arrow key reloaded the page. The
 * rendered sale screen is checked in p3_boxoffice_shifts.php; here the shift list on its own and
 * the sources. Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

echo "A314: box office pickers without submit on change\n";

ob_start();
try { PassCaptain\BoxOffice\ShiftPage::render_shifts(); $a314_list = (string) ob_get_clean(); } catch ( Throwable $e ) { ob_end_clean(); $a314_list = 'EXCEPTION ' . $e->getMessage(); }
check( 'A314 shift list: register filter without onchange, with an "Anzeigen" button', false === strpos( $a314_list, 'onchange' ) && 1 === preg_match( '#<select name="register_id">.*?</select></label> <button type="submit" class="button">Anzeigen</button>#s', $a314_list ) );
foreach ( array( 'src/BoxOffice/Page.php', 'src/BoxOffice/ShiftPage.php' ) as $a314_file ) {
	check( "A314 $a314_file has no submit-on-change select", false === strpos( (string) file_get_contents( WPCAP_PATH . $a314_file ), 'this.form.submit()' ) );
}

<?php
/**
 * A312: seat picker keeps keyboard focus (30 s refresh, "Entfernen"). Behaviour in
 * tests/js/seating-picker.test.js; here the hooks the script relies on. Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

echo "A312: seat picker focus\n";

$a312_js = (string) file_get_contents( WPCAP_PATH . 'assets/seating/picker.js' );
check( 'A312 seat list is rebuilt only when its content changes', false !== strpos( $a312_js, 'if (signature === this.selectionSignature) return;' ) );
check( 'A312 focus inside the seat list is restored after a rebuild (same seat, next/previous, heading)', false !== strpos( $a312_js, 'this.selectionList.contains(active)' ) && false !== strpos( $a312_js, "this.selectionHeading = h('h3', { tabindex: '-1' }" ) && false !== strpos( $a312_js, 'if (target) target.focus();' ) );
check( 'A312 refresh does not overwrite a standing-area number while it has focus', false !== strpos( $a312_js, 'if (document.activeElement !== input) input.value' ) );
check( 'A312 node test exists', file_exists( WPCAP_PATH . 'tests/js/seating-picker.test.js' ) );

<?php
// phpcs:ignoreFile
/**
 * A363: the translator comment of the "paid without tickets" admin mail names the payment reference
 * as %2$s (it used to say "buyer e-mail"), and the catalogue template carries the same comment.
 */

echo "A363: translator comment of the late payment mail\n";
$a363_src = (string) file_get_contents( WPCAP_PATH . 'src/Orders.php' );
$a363_ok = (bool) preg_match( '#/\* translators: 1: order number, 2: payment reference, 3: reason \*/\s*__\( \'Die Zahlung für Bestellung %1\$s \(Referenz %2\$s\)#', $a363_src );
check( 'A363: Orders.php names %2$s the payment reference directly before the gettext call', $a363_ok );
check( 'A363: no translator comment calls the reference a buyer e-mail any more', false === strpos( $a363_src, '2: buyer e-mail */ __( \'Die Zahlung für Bestellung' ) );
$a363_pot = (string) file_get_contents( WPCAP_PATH . 'languages/pass-captain.pot' );
check( 'A363: the POT carries the corrected comment', (bool) preg_match( '/#\. translators: 1: order number, 2: payment reference, 3: reason\n(?:#:[^\n]*\n|#,[^\n]*\n)*msgid "Die Zahlung für Bestellung %1\$s \(Referenz %2\$s\)/', $a363_pot ) );

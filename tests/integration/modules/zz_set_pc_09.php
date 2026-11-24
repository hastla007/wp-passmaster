<?php
/**
 * SET-PC-09: de_DE uses "du" only, de_DE_formal ("Sie") is shipped and selectable.
 */
echo "SET-PC-09: address forms\n";
$sp09_loc = PassCaptain\Localization::supported_locales();
check( 'SET-PC-09: de_DE_formal is offered as "Deutsch (Sie)"', 'Deutsch (Sie)' === ( $sp09_loc['de_DE_formal'] ?? '' ) && isset( $sp09_loc['de_DE'] ) );
check( 'SET-PC-09: "de" still resolves to de_DE (informal stays the default)', 'de_DE' === PassCaptain\Localization::normalize_buyer_locale( 'de' ) && 'de_DE_formal' === PassCaptain\Localization::normalize_buyer_locale( 'de_DE_formal' ) );
exec( 'php ' . escapeshellarg( WPCAP_PATH . 'tests/i18n-check.php' ) . ' 2>&1', $sp09_out, $sp09_rc );
check( 'SET-PC-09: the language check passes (no formal address in sources, no informal address in de_DE_formal)', 0 === $sp09_rc && false !== strpos( implode( "\n", $sp09_out ), 'informal address only' ) && false !== strpos( implode( "\n", $sp09_out ), 'ok   de_DE_formal' ) );

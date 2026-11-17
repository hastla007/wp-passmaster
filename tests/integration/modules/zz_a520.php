<?php
// phpcs:ignoreFile
/**
 * A520: report groups (events, ticket types, channels …) are ordered like a reader of the site
 * language expects (umlauts with their base letter, case-insensitive, numbers naturally), not by
 * strcmp byte order.
 */

use PassCaptain\Localization;

echo "A520: report labels in language order\n";
$a520 = array( 'zoe', 'Äpfel', 'Zebra', 'Apfel', 'Block 10', 'Block 2', 'Österreich-Tour', 'Oper' );
usort( $a520, array( Localization::class, 'compare' ) );
check( 'A520: umlauts sort with their base letter, case does not split the list, numbers are natural' . ' – ' . implode( ', ', $a520 ), array( 'Apfel', 'Äpfel', 'Block 2', 'Block 10', 'Oper', 'Österreich-Tour', 'Zebra', 'zoe' ) === $a520 );
check( 'A520: the report no longer sorts labels with strcmp', false === strpos( (string) file_get_contents( WPCAP_PATH . 'src/Reports/Data.php' ), "strcmp( \$a['label']" ) && false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Reports/Data.php' ), "Localization::compare( \$a['label'], \$b['label'] )" ) );
$a520_r = new ReflectionMethod( Localization::class, 'fold' );
$a520_r->setAccessible( true );
check( 'A520: the fallback without intl folds accents', 0 === strnatcasecmp( (string) $a520_r->invoke( null, 'Äpfel' ), 'Apfel' ) );

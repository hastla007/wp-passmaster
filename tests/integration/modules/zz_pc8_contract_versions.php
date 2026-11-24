<?php
/**
 * PC-8: one meaning per WPCAP_CONTRACT_VERSION – docs/EXTENSIONS.md says that 1.2.0 is only the invoice service
 * date and 1.3.0 the read facade, and that the 1.2.0 "read facade" of the unreleased branch
 * claude/plugin-todo-liste-2a83v4 does not count. Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

echo "PC-8: one meaning per contract version\n";
$pc8_doc = (string) file_get_contents( WPCAP_PATH . 'docs/EXTENSIONS.md' );
preg_match( '/^\| 1\.2\.0 \| [^|]+ \| ([^\n]*)$/m', $pc8_doc, $pc8_120 );
preg_match( '/^\| 1\.3\.0 \| [^|]+ \| ([^\n]*)$/m', $pc8_doc, $pc8_130 );
check( 'PC-8: the 1.2.0 row names only the invoice service date, not the read facade', isset( $pc8_120[1] ) && false !== strpos( $pc8_120[1], 'service_date' ) && false === stripos( $pc8_120[1], 'Lesefassade' ) && false === strpos( $pc8_120[1], 'ReadFacade' ) );
check( 'PC-8: the 1.3.0 row names the read facade', isset( $pc8_130[1] ) && false !== strpos( $pc8_130[1], 'ReadFacade' ) );
check( 'PC-8: the document says the 1.2.0 "read facade" of claude/plugin-todo-liste-2a83v4 was never released', (bool) preg_match( '/claude\/plugin-todo-liste-2a83v4.{0,140}?nie veröffentlicht/su', $pc8_doc ) );
check( 'PC-8: the code ties the read facade to 1.3.0 and the service date to 1.2.0', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Api/Invoices.php' ), 'Since contract 1.2.0' ) && false === strpos( (string) file_get_contents( WPCAP_PATH . 'src/Api/ReadFacade.php' ), '1.2.0' ) );

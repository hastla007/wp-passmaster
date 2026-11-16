<?php
/**
 * A367: every shipped PHP file has an ABSPATH guard. The check itself lives in tests/consistency.php
 * (CI, all files of the release ZIP); this runs it and also loads one formerly unguarded file in a
 * PHP process without WordPress, which must stop without output or declarations.
 * Included from run.php (uses check()).
 */
// phpcs:ignoreFile

echo "A367: ABSPATH guard in every shipped PHP file\n";

$x2_a367_out = array();
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( WPCAP_PATH . 'tests/consistency.php' ) . ' 2>&1', $x2_a367_out );
$x2_a367_line = (string) current( array_filter( $x2_a367_out, static fn( $l ) => false !== strpos( $l, 'ABSPATH guard before its first statement' ) ) );
check( 'A367: consistency check finds an ABSPATH guard in every shipped PHP file' . ( 0 === strpos( trim( $x2_a367_line ), 'ok' ) ? '' : ' – ' . trim( $x2_a367_line ) ), 0 === strpos( trim( $x2_a367_line ), 'ok' ) );

$x2_a367_code = 'var_export( include ' . var_export( WPCAP_PATH . 'src/blocks/events/editor.asset.php', true ) . ' ); echo "|"; require ' . var_export( WPCAP_PATH . 'src/Integrations/ConnectorInterface.php', true ) . '; echo interface_exists( "PassCaptain\\\\Integrations\\\\ConnectorInterface", false ) ? "declared" : "none";';
$x2_a367_direct = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $x2_a367_code ) . ' 2>&1' );
check( 'A367: a block asset file loaded outside WordPress exits before returning data', '' === trim( $x2_a367_direct ) );

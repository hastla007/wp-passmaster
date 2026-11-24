<?php
/**
 * A402: every class in src/ outside the public add-on API carries @internal in its class docblock, so a
 * new class has to decide (public list: tests/integration/lib/class_api.php).
 */
require_once dirname( __DIR__ ) . '/lib/class_api.php';

echo "A402: @internal on every non-public class\n";
$a402_root    = dirname( __DIR__, 3 );
$a402_classes = wpcap_test_class_docblocks( $a402_root . '/src' );
$a402_public  = WPCAP_PUBLIC_CLASSES;
$a402_marked  = static fn( $doc ) => (bool) preg_match( '/^\s*\*\s*@internal\b/m', (string) $doc );

$a402_missing = array();
foreach ( $a402_classes as $a402_fqcn => $a402_info ) {
	if ( ! in_array( $a402_fqcn, $a402_public, true ) && ! $a402_marked( $a402_info[2] ) ) {
		$a402_missing[] = $a402_fqcn . ' (' . str_replace( $a402_root . '/', '', $a402_info[0] ) . ':' . $a402_info[1] . ')';
	}
}
check( 'A402: the scan finds the classes of src/ (more than 300)', count( $a402_classes ) > 300 );
check( 'A402: every non-public class carries @internal in its class docblock' . ( $a402_missing ? ': ' . implode( ', ', array_slice( $a402_missing, 0, 8 ) ) . ( count( $a402_missing ) > 8 ? ' …' : '' ) : '' ), ! $a402_missing );

$a402_public_bad = array_values( array_filter( $a402_public, static fn( $c ) => ! isset( $a402_classes[ $c ] ) || $a402_marked( $a402_classes[ $c ][2] ) ) );
check( 'A402: every public class exists and is not marked @internal' . ( $a402_public_bad ? ': ' . implode( ', ', $a402_public_bad ) : '' ), ! $a402_public_bad );

$a402_api_dir = array_keys( array_filter( $a402_classes, static fn( $i ) => false !== strpos( $i[0], '/src/Api/' ) ) );
check( 'A402: every class in src/Api/ is in the public list', $a402_api_dir && ! array_diff( $a402_api_dir, $a402_public ) );

// Classes the extension docs name must be decided: public, or listed as named-but-internal.
$a402_named = array();
foreach ( array( 'docs/CONTRACTS.md', 'docs/EXTENSIONS.md' ) as $a402_doc ) {
	preg_match_all( '/PassCaptain\\\\[A-Za-z\\\\]*[A-Za-z]/', (string) file_get_contents( $a402_root . '/' . $a402_doc ), $a402_m );
	$a402_named = array_merge( $a402_named, $a402_m[0] );
}
$a402_named     = array_unique( $a402_named );
$a402_undecided = array_values( array_diff( $a402_named, $a402_public, WPCAP_NAMED_INTERNAL_CLASSES ) );
check( 'A402: every class named in CONTRACTS.md/EXTENSIONS.md is public or listed as named-but-internal' . ( $a402_undecided ? ': ' . implode( ', ', $a402_undecided ) : '' ), ! $a402_undecided );
$a402_explicit_internal = array_filter( WPCAP_NAMED_INTERNAL_CLASSES, static fn( $c ) => isset( $a402_classes[ $c ] ) );
check( 'A402: classes the docs call internal (Audit, Reports\\Data, Reports\\Scope) carry @internal', isset( $a402_classes['PassCaptain\\Audit'] ) && array() === array_filter( $a402_explicit_internal, static fn( $c ) => ! $a402_marked( $a402_classes[ $c ][2] ) ) );

// The Orders split keeps the public surface: only the facade is public, every part under src/Orders/ is
// @internal and none is listed or named as public API; the transaction helpers stay private on the facade.
$a402_parts = array_keys( array_filter( $a402_classes, static fn( $i ) => false !== strpos( str_replace( '\\', '/', $i[0] ), '/src/Orders/' ) ) );
$a402_parts_bad = array_values( array_filter( $a402_parts, static fn( $c ) => in_array( $c, $a402_public, true ) || in_array( $c, $a402_named, true ) || ! $a402_marked( $a402_classes[ $c ][2] ) ) );
check( 'A402: the Orders parts (incl. Transaction, Payment, Cancellation) are @internal and not public API' . ( $a402_parts_bad ? ': ' . implode( ', ', $a402_parts_bad ) : '' ), ! $a402_parts_bad && ! array_diff( array( 'PassCaptain\\Orders\\Transaction', 'PassCaptain\\Orders\\Payment', 'PassCaptain\\Orders\\Cancellation' ), $a402_parts ) );
$a402_private = array_filter( array( 'begin', 'in_transaction', 'capacity_released' ), static fn( $m ) => ! ( new ReflectionMethod( 'PassCaptain\\Orders', $m ) )->isPrivate() );
check( 'A402: Orders::begin(), in_transaction() and capacity_released() stay private (the parts use Orders\\Transaction)', ! $a402_private );

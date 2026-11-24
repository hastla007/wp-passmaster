<?php
/**
 * PASS-FE-16 / E-13: the shipped texts say explicitly that without network only admissions are possible
 * (offline scanner) and that box office sales, stornos and refunds need the network. Guards against a
 * later text that promises offline sales.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

echo "PASS-FE-16 offline box office statement (E-13)\n";
$fe16_root = dirname( __DIR__, 3 ) . '/';
$fe16_readme = (string) file_get_contents( $fe16_root . 'readme.txt' );
$fe16_scanner = (string) file_get_contents( $fe16_root . 'docs/OFFLINE-SCANNER.md' );
$fe16_ops = (string) file_get_contents( $fe16_root . 'docs/OPERATIONS.md' );

check( 'PASS-FE-16: readme.txt says only admissions work without network', str_contains( $fe16_readme, 'Without network only admissions are possible' ) && str_contains( $fe16_readme, 'Box office sales, stornos and refund payouts need the network' ) );
check( 'PASS-FE-16: docs/OFFLINE-SCANNER.md states E-13 (only admissions offline)', str_contains( $fe16_scanner, 'Entscheidung E-13' ) && str_contains( $fe16_scanner, '**nur Einlässe**' ) );
check( 'PASS-FE-16: docs/OPERATIONS.md states that sales, stornos and refunds need the network', str_contains( $fe16_ops, 'Entscheidung E-13' ) && str_contains( $fe16_ops, '**Verkäufe, Stornos und Erstattungen**' ) && str_contains( $fe16_ops, 'Ein Barverkauf ohne Netz ist nicht vorgesehen.' ) );
check( 'PASS-FE-16: no shipped text promises offline sales', ! preg_match( '/offline (sales|selling) (is|are) (possible|supported)|offline verkaufen|Offline-Verkauf (ist )?möglich/i', $fe16_readme . $fe16_scanner . $fe16_ops ) );

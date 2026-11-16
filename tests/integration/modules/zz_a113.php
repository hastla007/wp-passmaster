<?php
/**
 * A113: one-off migrations compare against the highest schema the site ever ran, so a plugin
 * rollback followed by a roll forward does not rerun them (real drill: tests/restore/drill.sh).
 */

use PassCaptain\Installer;
use PassCaptain\Settings;

echo "A113: rollback / roll forward keeps one-off migrations from rerunning\n";

check( 'baseline: no max known → stored version', '3.5.0+abc' === Installer::migration_baseline( '3.5.0+abc', '' ) );
check( 'baseline: rollback wrote a lower version → the remembered higher one wins', '3.8.5' === Installer::migration_baseline( '3.5.0+abc', '3.8.5' ) );
check( 'baseline: normal upgrade path → stored version when it is the higher one', '3.8.5+x' === Installer::migration_baseline( '3.8.5+x', '3.8.4' ) );
check( 'baseline: lost version option but max known → max', '3.8.5' === Installer::migration_baseline( '', '3.8.5' ) );

global $wpdb;
// The real install() path is exercised against WordPress + MariaDB by tests/restore/drill.sh (the test
// harness' dbDelta stub recreates tables). Here: the migration decision exactly as install() makes it.
check( 'install() remembers the highest schema version', (string) strtok( Installer::version(), '+' ) === (string) get_option( 'wpcap_db_version_max' ) );
$a113_orig = get_option( Settings::OPTION, array() );
$wpdb->query( 'START TRANSACTION' );
$a113_settings = $a113_orig;
unset( $a113_settings['credit_note_prefix'] );
update_option( Settings::OPTION, $a113_settings );
$wpdb->query( "DELETE FROM {$wpdb->prefix}wpcap_credit_notes WHERE credit_note_number LIKE 'GS-%'" );
// Fresh site on the new schema, old code (3.5.0) ran in between, now rolled forward again.
Installer::pin_legacy_credit_prefix( Installer::migration_baseline( '3.5.0+rollback', (string) get_option( 'wpcap_db_version_max' ) ) );
$a113_after = get_option( Settings::OPTION, array() );
check( 'roll forward after a rollback does not pin the legacy credit-note prefix', '' === (string) ( $a113_after['credit_note_prefix'] ?? '' ) );
// Genuine old install (never ran the new schema): the migration still runs.
Installer::pin_legacy_credit_prefix( Installer::migration_baseline( '3.5.0+old', '' ) );
$a113_old = get_option( Settings::OPTION, array() );
check( 'a real upgrade from an old version still pins the legacy prefix', 'GS' === (string) ( $a113_old['credit_note_prefix'] ?? '' ) );
$wpdb->query( 'ROLLBACK' );
update_option( Settings::OPTION, $a113_orig );
check( 'install() reads the baseline through migration_baseline()', false !== strpos( file_get_contents( dirname( __DIR__, 3 ) . '/src/Installer.php' ), "self::migration_baseline( (string) get_option( 'wpcap_db_version', '' ), (string) get_option( 'wpcap_db_version_max', '' ) )" ) );

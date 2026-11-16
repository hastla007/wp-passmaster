<?php
/**
 * A513: after dbDelta and upgrade_indexes() the installer verifies the declared indexes as well; a
 * missing or different index (e.g. a UNIQUE KEY over duplicates, an ALTER that timed out) counts as
 * a failed schema upgrade (wpcap_schema_error, retry with backoff) instead of storing the version.
 * Included from run.php (uses check()).
 */
// phpcs:ignoreFile
use PassCaptain\Installer;

global $wpdb;
$p = $wpdb->prefix;
echo "A513: schema check covers indexes\n";

$x2_a513_ok = Installer::missing_indexes( array( "CREATE TABLE {$p}wpcap_orders (\n order_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n public_id char(32) NOT NULL,\n PRIMARY KEY  (order_id),\n UNIQUE KEY public_id (public_id),\n KEY provider_ref (payment_provider,provider_reference(64))\n) ENGINE=InnoDB;" ) );
check( 'A513: indexes as declared (primary, unique, prefix length) are not reported', array() === $x2_a513_ok );
$x2_a513_bad = Installer::missing_indexes( array(
	"CREATE TABLE {$p}wpcap_orders (\n order_id bigint(20) unsigned NOT NULL,\n KEY gibt_es_nicht (order_id),\n KEY customer_email (customer_email,order_id),\n UNIQUE KEY status (status)\n) ENGINE=InnoDB;",
	"CREATE TABLE {$p}wpcap_fehlt_x2 (\n id int NOT NULL,\n KEY id (id)\n) ENGINE=InnoDB;",
) );
check( 'A513: a missing index, other columns and lost uniqueness are reported (missing tables only by missing_schema)', array( "{$p}wpcap_orders: index gibt_es_nicht", "{$p}wpcap_orders: index customer_email", "{$p}wpcap_orders: index status" ) === $x2_a513_bad );

// The schema installed by the test bootstrap (real CREATE TABLE statements) passed the new check.
check( 'A513: the full plugin schema has no index differences after install (no false positives)', Installer::version() === (string) get_option( 'wpcap_db_version' ) );

$x2_a513_src = (string) file_get_contents( WPCAP_PATH . 'src/Installer.php' );
check( 'A513: install() treats index differences like missing columns (record_failure, no version stored)', 1 === preg_match( '/\$missing = self::missing_schema\( \$all_queries \);\s*(?:\/\/[^\n]*\n\s*)?\$missing = array_merge\( \$missing, self::missing_indexes\( \$all_queries \) \);\s*if \( \$missing \) \{\s*self::record_failure/', $x2_a513_src ) );

<?php
/**
 * A181: Installer::upgrade_indexes() fixes what dbDelta does not (changed and obsolete indexes).
 * The full upgrade tests per previous version run separately: tests/upgrade/run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Installer;

global $wpdb;
$p = $wpdb->prefix;

echo "A181: index upgrade (changed and obsolete indexes)\n";
$a181_indexes = static function ( $table ) use ( $wpdb ) {
	$out = array();
	foreach ( $wpdb->get_results( "SHOW INDEX FROM `$table`" ) as $row ) {
		$out[ $row->Key_name ] = ( $out[ $row->Key_name ] ?? ( $row->Non_unique ? 'KEY ' : 'UNIQUE ' ) ) . $row->Column_name . ( $row->Sub_part ? "({$row->Sub_part})" : '' ) . ',';
	}
	ksort( $out );
	return $out;
};
check( 'current module schema matches the installed indexes (nothing to rebuild)', array() === Installer::upgrade_indexes( PassCaptain\Modules::schema( $p, '' ) ) );

$a181_t = "{$p}wpcap_a181_idx";
$wpdb->query( "DROP TABLE IF EXISTS $a181_t" );
$wpdb->query( "CREATE TABLE $a181_t (id bigint unsigned NOT NULL AUTO_INCREMENT, token varchar(200) NOT NULL, a int NOT NULL, b int NOT NULL, PRIMARY KEY (id), KEY token (token), KEY pair (a), UNIQUE KEY keep_me (a,b)) ENGINE=InnoDB" );
$wpdb->insert( $a181_t, array( 'token' => 'x', 'a' => 1, 'b' => 1 ) );
$a181_schema = "CREATE TABLE $a181_t (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	token varchar(200) NOT NULL,
	a int(11) NOT NULL,
	b int(11) NOT NULL,
	PRIMARY KEY  (id),
	KEY token (token(191)),
	UNIQUE KEY pair (a,b),
	UNIQUE KEY keep_me (a,b),
	KEY added_later (b)
) ENGINE=InnoDB";
$a181_changed = Installer::upgrade_indexes( array( $a181_schema ) );
$a181_now = $a181_indexes( $a181_t );
check( 'changed prefix length and uniqueness are rebuilt', array( "$a181_t.token", "$a181_t.pair" ) === $a181_changed && 'KEY token(191),' === $a181_now['token'] && 'UNIQUE a,b,' === $a181_now['pair'] );
check( 'unchanged indexes stay, missing ones are left to dbDelta', 'UNIQUE a,b,' === $a181_now['keep_me'] && ! isset( $a181_now['added_later'] ) && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $a181_t" ) );
check( 'second run changes nothing', array() === Installer::upgrade_indexes( array( $a181_schema ) ) );
$wpdb->insert( $a181_t, array( 'token' => 'y', 'a' => 2, 'b' => 2 ) );
$wpdb->insert( $a181_t, array( 'token' => 'z', 'a' => 3, 'b' => 3 ) );
$wpdb->query( "ALTER TABLE $a181_t DROP INDEX pair, DROP INDEX keep_me, ADD KEY keep_me (b)" );
$wpdb->query( "UPDATE $a181_t SET a=1, b=1" );
check( 'a unique index that the data violates is kept as it was (one ALTER, no index lost)', array() === Installer::upgrade_indexes( array( $a181_schema ) ) && 'KEY b,' === $a181_indexes( $a181_t )['keep_me'] );
$wpdb->query( "DROP TABLE IF EXISTS $a181_t" );

$wpdb->query( "ALTER TABLE {$p}wpcap_order_items ADD KEY type_order (ticket_type_id,order_id)" );
$a181_changed = Installer::upgrade_indexes( array( "CREATE TABLE {$p}wpcap_order_items (\n\titem_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n\tPRIMARY KEY  (item_id)\n) ENGINE=InnoDB" ) );
check( 'obsolete index type_order (replaced by capacity_sum) is dropped', array( "{$p}wpcap_order_items.type_order" ) === $a181_changed && ! isset( $a181_indexes( "{$p}wpcap_order_items" )['type_order'] ) && isset( $a181_indexes( "{$p}wpcap_order_items" )['capacity_sum'] ) );

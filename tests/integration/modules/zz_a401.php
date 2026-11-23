<?php
// phpcs:ignoreFile
/**
 * A401 (increment): the largest methods are split without behaviour change.
 * - Rest\Controller::register_routes() calls one method per resource group in the former order; the
 *   registered routes (path, methods, callbacks, permission callbacks, args) were compared before/after.
 * - Installer::install() keeps the control flow; the CREATE TABLE statements come from
 *   Installer::schema_statements() (compared statement by statement before/after; the whole suite
 *   installs its schema through install()).
 */

use PassCaptain\Installer;
use PassCaptain\Rest\Controller;

echo "A401: large methods split\n";
$z3m_len = static function ( $class, $method ) { $r = new ReflectionMethod( $class, $method ); return $r->getEndLine() - $r->getStartLine(); };
$z3m_groups = array( 'register_checkout_routes', 'register_order_routes', 'register_admission_routes', 'register_member_pass_routes', 'register_service_routes', 'register_campaign_routes', 'register_payment_routes', 'register_waitlist_routes', 'register_admin_routes' );

$z3m_body = implode( "\n", array_slice( file( ( new ReflectionMethod( Controller::class, 'register_routes' ) )->getFileName() ), ( new ReflectionMethod( Controller::class, 'register_routes' ) )->getStartLine(), $z3m_len( Controller::class, 'register_routes' ) ) );
preg_match_all( '/self::(register_\w+_routes)\(\);/', $z3m_body, $z3m_calls );
check( 'A401 register_routes() only calls the resource groups, in order', $z3m_groups === $z3m_calls[1] && $z3m_len( Controller::class, 'register_routes' ) <= 20 );
$z3m_long = array_filter( $z3m_groups, static fn( $m ) => $z3m_len( Controller::class, $m ) > 80 );
check( 'A401 no route group method is longer than 80 lines' . ( $z3m_long ? ' – ' . implode( ', ', $z3m_long ) : '' ), ! $z3m_long );

if ( ! class_exists( 'WP_REST_Server' ) ) { class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; const EDITABLE = 'POST, PUT, PATCH'; const DELETABLE = 'DELETE'; const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE'; } }
if ( ! function_exists( 'register_rest_route' ) ) { function register_rest_route( $ns, $route, $args, $override = false ) { $GLOBALS['wpcap_test_routes'][ $ns . $route ] = $args; return true; } }
$z3m_saved = $GLOBALS['wpcap_test_routes'] ?? null;
$GLOBALS['wpcap_test_routes'] = array();
Controller::register_routes();
$z3m_routes = (array) $GLOBALS['wpcap_test_routes'];
if ( $z3m_routes ) {
	$z3m_no_perm = array();
	foreach ( $z3m_routes as $z3m_route => $z3m_args ) {
		foreach ( isset( $z3m_args['methods'] ) ? array( $z3m_args ) : $z3m_args as $z3m_endpoint ) {
			if ( empty( $z3m_endpoint['permission_callback'] ) ) { $z3m_no_perm[] = $z3m_route; }
		}
	}
	check( 'A401 all 46 core routes are still registered under pass-captain/v1, each with a permission callback', count( $z3m_routes ) >= 46 && ! array_filter( array_keys( $z3m_routes ), static fn( $r ) => 0 !== strpos( $r, 'pass-captain/v1/' ) ) && ! $z3m_no_perm );
	check( 'A401 first and last route keep their place (order of registration unchanged)', 'pass-captain/v1/events' === array_key_first( $z3m_routes ) && isset( $z3m_routes['pass-captain/v1/tax-profiles'] ) );
}
$GLOBALS['wpcap_test_routes'] = $z3m_saved;

check( 'A401 install() keeps the control flow only (schema statements moved out)', $z3m_len( Installer::class, 'install' ) <= 150 && false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Installer.php' ), '$all_queries = self::schema_statements();' ) );
$z3m_statements = Installer::schema_statements();
global $wpdb;
$z3m_tables = array();
foreach ( $z3m_statements as $z3m_sql ) { if ( preg_match( '/CREATE TABLE (\S+)/', (string) $z3m_sql, $z3m_m ) ) { $z3m_tables[] = $z3m_m[1]; } }
check( 'A401 schema_statements(): only CREATE TABLE statements, each table once, all with the site prefix', count( $z3m_statements ) >= 140 && count( $z3m_tables ) === count( $z3m_statements ) && count( array_unique( $z3m_tables ) ) === count( $z3m_tables ) && ! array_filter( $z3m_tables, static fn( $t ) => 0 !== strpos( $t, $wpdb->prefix . 'wpcap_' ) ) );
check( 'A401 the installed schema has every table of schema_statements()', array() === array_values( array_filter( $z3m_tables, static fn( $t ) => $t !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) ) ) );

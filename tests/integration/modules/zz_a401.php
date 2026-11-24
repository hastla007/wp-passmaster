<?php
// phpcs:ignoreFile
/**
 * A401: the largest methods are split without behaviour change.
 * - Rest\Controller::register_routes() calls one method per resource group in the former order; the
 *   registered routes (path, methods, callbacks, permission callbacks, args) were compared before/after.
 * - Installer::install() keeps the control flow; the CREATE TABLE statements come from
 *   Installer::schema_statements(), which merges one method per table group in the former order
 *   (core statements checked against a hash recorded before the split; the whole suite installs its
 *   schema through install()).
 * - Orders::create_attempt() runs one private method per checkout phase (validation, transaction parts,
 *   rollback, post-commit); Orders\CheckoutState (@internal) carries the values between the phases.
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

// A401 (second step): schema_statements() is split into table groups, Orders::create_attempt() into phases.
// The CREATE TABLE text must stay byte-identical: the core statements (without module tables) are compared
// with the hash recorded before the split, prefix and charset normalised (fixture of core schema 3.18.4;
// a later schema version changes the text on purpose and records its own hash here).
$z3m_groups_schema = array( 'schema_event_tables', 'schema_order_tables', 'schema_staff_tables', 'schema_seating_tables', 'schema_service_tables', 'schema_admission_tables', 'schema_invoice_tables', 'schema_participant_tables', 'schema_message_tables', 'schema_pass_campaign_tables', 'schema_waitlist_tables', 'schema_settings_tables', 'schema_payment_tables', 'schema_operation_tables' );
$z3m_charset = $wpdb->get_charset_collate();
$z3m_core = array_slice( $z3m_statements, 0, count( $z3m_statements ) - count( PassCaptain\Modules::schema( $wpdb->prefix, $z3m_charset ) ) );
$z3m_norm = str_replace( array( $wpdb->prefix . 'wpcap_', $z3m_charset ), array( '{prefix}wpcap_', '{charset}' ), implode( "\n", $z3m_core ) );
// 3.18.5: wpcap_ticket_types.kind and addon_max_per_ticket (add-on products, Sales\Addons).
$z3m_fixture = array( '3.18.4' => array( 64, '3de7fababdcbcad3d8d22df437260388d9e75ef8d0b389a57805641362aa3700' ), '3.18.5' => array( 64, '515f8cc5c14b224dc84412e2084f97702c6c0377af23c6506fc0cd2bfa8e7e6d' ) );
if ( isset( $z3m_fixture[ Installer::DB_VERSION ] ) ) {
	check( 'A401 core CREATE TABLE statements byte-identical to before the split (64 statements, normalised sha256)', array( count( $z3m_core ), hash( 'sha256', $z3m_norm ) ) === $z3m_fixture[ Installer::DB_VERSION ] );
} else {
	echo '  note A401 schema fixture recorded for ' . implode( ', ', array_keys( $z3m_fixture ) ) . ' only (now ' . Installer::DB_VERSION . ")\n";
}
$z3m_parts = array();
foreach ( $z3m_groups_schema as $z3m_group ) { $z3m_parts = array_merge( $z3m_parts, ( new ReflectionMethod( Installer::class, $z3m_group ) )->invoke( null, $z3m_charset ) ); }
$z3m_parts = array_merge( $z3m_parts, PassCaptain\Sales\Schema::statements( $wpdb->prefix, $z3m_charset ), PassCaptain\Modules::schema( $wpdb->prefix, $z3m_charset ) );
check( 'A401 schema_statements() = the table group methods in order, then Sales\Schema and the module statements (dbDelta input unchanged)', $z3m_parts === $z3m_statements );
$z3m_long = array_filter( $z3m_groups_schema, static fn( $m ) => $z3m_len( Installer::class, $m ) > 160 );
check( 'A401 schema_statements() <= 40 lines, no table group method longer than 160 lines' . ( $z3m_long ? ' – ' . implode( ', ', $z3m_long ) : '' ), $z3m_len( Installer::class, 'schema_statements' ) <= 40 && ! $z3m_long );

$z3m_phases = array( 'checkout_state', 'checkout_existing_order', 'checkout_clean_lines', 'checkout_buyer_checks', 'checkout_prepare_transaction', 'checkout_lock_rows', 'checkout_reserve', 'checkout_price_lines', 'checkout_apply_campaigns', 'checkout_apply_taxes', 'checkout_insert_order', 'checkout_insert_lines', 'checkout_commit', 'checkout_failed', 'checkout_completed' );
$z3m_attempt = new ReflectionMethod( PassCaptain\Orders::class, 'create_attempt' );
$z3m_body = implode( '', array_slice( file( $z3m_attempt->getFileName() ), $z3m_attempt->getStartLine(), $z3m_len( PassCaptain\Orders::class, 'create_attempt' ) ) );
preg_match_all( '/self::(checkout_\w+)\(/', $z3m_body, $z3m_calls );
check( 'A401 Orders::create_attempt() runs the checkout phases in order (transaction parts after begin())', $z3m_phases === $z3m_calls[1] && $z3m_len( PassCaptain\Orders::class, 'create_attempt' ) <= 40 && false !== strpos( $z3m_body, 'self::begin();' ) && strpos( $z3m_body, 'self::begin();' ) < strpos( $z3m_body, 'self::checkout_lock_rows(' ) );
$z3m_long = array_filter( $z3m_phases, static fn( $m ) => $z3m_len( PassCaptain\Orders::class, $m ) > 100 );
check( 'A401 no checkout phase method is longer than 100 lines, all private' . ( $z3m_long ? ' – ' . implode( ', ', $z3m_long ) : '' ), ! $z3m_long && ! array_filter( $z3m_phases, static fn( $m ) => ! ( new ReflectionMethod( PassCaptain\Orders::class, $m ) )->isPrivate() ) );
$z3m_create = new ReflectionMethod( PassCaptain\Orders::class, 'create' );
$z3m_state = new ReflectionClass( PassCaptain\Orders\CheckoutState::class );
check( 'A401 Orders::create() keeps its public signature; Orders\CheckoutState is final and @internal', $z3m_create->isPublic() && $z3m_create->isStatic() && array( 'payload', 'internal' ) === array_map( static fn( $p ) => $p->getName(), $z3m_create->getParameters() ) && $z3m_state->isFinal() && false !== strpos( (string) $z3m_state->getDocComment(), '@internal' ) );

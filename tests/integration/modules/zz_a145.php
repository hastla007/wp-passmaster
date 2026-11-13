<?php
/**
 * A145: server-side object permission in every channel. Enumerates every REST route, admin-post
 * action and AJAX action the plugin registers and asserts for each one
 *  - it refuses a logged-in user without any plugin capability (dynamic probe),
 *  - it refuses a user who holds every operational capability but is not assigned to the object's
 *    event, when called with ids of existing objects of that event (dynamic probe), and
 *  - its code path contains a permission check, object-scoped where it reads an object id (static).
 * Extends the REST route coverage of zz_a119.php (shared enumeration in channels.php).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Installer;
use PassCaptain\StaffAssignments;
use PassCaptain\Admin\Menu;

require_once dirname( __DIR__ ) . '/channels.php';

global $wpdb;
$p = $wpdb->prefix;
$wpa145_root = dirname( __DIR__, 3 );
$wpa145_saved_user = $GLOBALS['wpcap_test_user_id'] ?? null;
$wpa145_saved_denied = $GLOBALS['wpcap_test_denied_caps'] ?? array();
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();

echo "A145: enumerate REST routes, admin-post and AJAX actions\n";
$wpa145_rest = wpcap_test_rest_endpoints();
$wpa145_post = Menu::handlers();
// admin-post/AJAX actions registered outside Admin\Menu (action => callable), found by the source scan below.
$wpa145_extra_post = array(
	'wpcap_print_invoice' => array( PassCaptain\Invoices::class, 'handle_admin_print' ),
	'wpcap_export_accounting' => array( PassCaptain\Invoices::class, 'handle_export' ),
	'wpcap_accept_waitlist' => array( PassCaptain\Waitlists::class, 'handle_accept_request' ),
	PassCaptain\Waitlists::UNSUBSCRIBE_ACTION => array( PassCaptain\Waitlists::class, 'handle_unsubscribe_request' ),
);
$wpa145_ajax = array(
	PassCaptain\Mail\Admin::ACTION_PREVIEW => array( PassCaptain\Mail\Admin::class, 'handle_preview_ajax' ),
);
// Source scan: every add_action( 'admin_post_…' / 'wp_ajax_…' ) outside Admin\Menu must be known here.
$wpa145_resolve = static function ( $expr, $file ) {
	$expr = trim( $expr );
	if ( preg_match( "/^'([^']*)'$/", $expr, $m ) ) { return $m[1]; }
	if ( preg_match( '/^([A-Za-z_\\\\]+)::([A-Z_]+)$/', $expr, $m ) ) {
		$class = $m[1];
		if ( false === strpos( $class, '\\' ) ) {
			$src = (string) file_get_contents( $file );
			if ( preg_match( '/^use\s+([A-Za-z_\\\\]+\\\\' . preg_quote( $class, '/' ) . ');/m', $src, $u ) ) { $class = $u[1]; }
			elseif ( preg_match( '/^namespace\s+([A-Za-z_\\\\]+);/m', $src, $n ) ) { $class = $n[1] . '\\' . $class; }
		}
		return defined( $class . '::' . $m[2] ) ? constant( $class . '::' . $m[2] ) : '?' . $expr;
	}
	return '?' . $expr;
};
$wpa145_scanned = array( 'post' => array(), 'nopriv' => array(), 'ajax' => array(), 'ajax_nopriv' => array() );
foreach ( array_merge( wpcap_test_source_files_with( "'admin_post_" ), wpcap_test_source_files_with( "'wp_ajax_" ) ) as $wpa145_file ) {
	if ( 'src/Admin/Menu.php' === $wpa145_file ) { continue; }
	$wpa145_src = (string) file_get_contents( $wpa145_root . '/' . $wpa145_file );
	preg_match_all( "/add_action\(\s*'(admin_post_nopriv_|admin_post_|wp_ajax_nopriv_|wp_ajax_)'\s*\.\s*([^,]+),|add_action\(\s*'(admin_post_nopriv_|admin_post_|wp_ajax_nopriv_|wp_ajax_)([a-z0-9_]+)'/", $wpa145_src, $wpa145_m, PREG_SET_ORDER );
	foreach ( $wpa145_m as $wpa145_hit ) {
		$wpa145_prefix = '' !== ( $wpa145_hit[1] ?? '' ) ? $wpa145_hit[1] : $wpa145_hit[3];
		$wpa145_name = '' !== ( $wpa145_hit[1] ?? '' ) ? $wpa145_resolve( $wpa145_hit[2], $wpa145_root . '/' . $wpa145_file ) : $wpa145_hit[4];
		$wpa145_kind = array( 'admin_post_' => 'post', 'admin_post_nopriv_' => 'nopriv', 'wp_ajax_' => 'ajax', 'wp_ajax_nopriv_' => 'ajax_nopriv' )[ $wpa145_prefix ];
		$wpa145_scanned[ $wpa145_kind ][] = $wpa145_name;
	}
}
$wpa145_menu_src = (string) file_get_contents( $wpa145_root . '/src/Admin/Menu.php' );
$wpa145_unknown_post = array_diff( $wpa145_scanned['post'], array_keys( $wpa145_extra_post ) );
$wpa145_unknown_ajax = array_diff( $wpa145_scanned['ajax'], array_keys( $wpa145_ajax ) );
check( 'admin-post actions registered outside Admin\\Menu are all covered' . ( $wpa145_unknown_post ? ' – ' . implode( ', ', $wpa145_unknown_post ) : '' ), ! $wpa145_unknown_post && ! array_diff( array_keys( $wpa145_extra_post ), $wpa145_scanned['post'] ) );
check( 'AJAX actions are all covered' . ( $wpa145_unknown_ajax ? ' – ' . implode( ', ', $wpa145_unknown_ajax ) : '' ), ! $wpa145_unknown_ajax && ! array_diff( array_keys( $wpa145_ajax ), $wpa145_scanned['ajax'] ) );
check( 'Admin\\Menu registers every handler of handlers() (incl. modules) as admin_post_', false !== strpos( $wpa145_menu_src, "add_action( 'admin_post_' . \$action, \$handler );" ) );
$wpa145_all_post = $wpa145_post + $wpa145_extra_post;
echo '  info ' . count( $wpa145_rest ) . ' REST endpoints, ' . count( $wpa145_all_post ) . ' admin-post actions, ' . count( $wpa145_ajax ) . " AJAX actions\n";

// --- fixtures: a foreign event with objects, and the probe user's own event -----------------------
if ( ! function_exists( 'status_header' ) ) { function status_header( $code ) { $GLOBALS['wpcap_test_status'] = (int) $code; } }
$wpa145_foreign = adm_setup();
$wpa145_own = adm_setup();
$wpa145_probe_user = 9160;
$GLOBALS['wpcap_test_users'][] = new Wpcap_Test_User( $wpa145_probe_user, 'wpa145probe', 'probe145@example.com' );
$GLOBALS['wpcap_test_users'][] = new Wpcap_Test_User( 9161, 'wpa145other', 'other145@example.com' );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_event_staff WHERE user_id=%d", $wpa145_probe_user ) );
foreach ( PassCaptain\Admin\EventsPage::STAFF_CAPS as $wpa145_cap ) {
	$wpdb->insert( "{$p}wpcap_event_staff", array( 'event_id' => $wpa145_own['event'], 'user_id' => $wpa145_probe_user, 'capability' => $wpa145_cap ) );
	$wpdb->insert( "{$p}wpcap_event_staff", array( 'event_id' => $wpa145_foreign['event'], 'user_id' => 9161, 'capability' => $wpa145_cap ) );
}
$wpa145_now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( "{$p}wpcap_resources", array( 'event_id' => $wpa145_foreign['event'], 'name' => 'WPA145', 'capacity' => 2, 'buffer_before' => 0, 'buffer_after' => 0, 'status' => 'active' ) );
$wpa145_res = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_service_slots", array( 'resource_id' => $wpa145_res, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() - 600 ), 'ends_at' => gmdate( 'Y-m-d H:i:s', time() + 3000 ), 'capacity' => 2, 'status' => 'open' ) );
$wpa145_slot = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_service_bookings", array( 'slot_id' => $wpa145_slot, 'order_id' => 0, 'customer_email' => 'wpa145@example.com', 'idempotency_key' => hash( 'sha256', uniqid( 'wpa145', true ) ), 'quantity' => 1, 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 600 ), 'status' => 'held', 'created_at' => $wpa145_now ) );
$wpa145_booking = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_staff_assignments", array( 'user_id' => 9161, 'event_id' => $wpa145_foreign['event'], 'scope' => 'occurrence', 'object_id' => $wpa145_foreign['occ'], 'capability' => 'wpcap_checkin', 'created_by' => 1, 'created_at' => $wpa145_now ) );
$wpa145_assignment = (int) $wpdb->insert_id;
if ( PassCaptain\Privacy::has_table( 'wpcap_boxoffice_registers' ) ) {
	$wpdb->insert( "{$p}wpcap_boxoffice_registers", array( 'name' => 'WPA145 Fremdkasse', 'location' => '', 'event_id' => $wpa145_foreign['event'], 'currency' => 'EUR', 'blind_count' => 1, 'status' => 'active', 'created_by' => 1, 'created_at' => $wpa145_now, 'updated_at' => $wpa145_now ) );
}
if ( PassCaptain\Privacy::has_table( 'wpcap_admission_areas' ) ) { PassCaptain\Admission\Areas::save( $wpa145_foreign['event'], 'WPA145 Bereich' ); }

/*
 * Id of an existing object for a parameter name: the foreign fixtures first, otherwise the newest row
 * of the plugin table whose primary key is that column – of an event other than the probe user's own
 * (rows without event are taken as they are).
 */
$wpa145_fixed = array(
	'event_id' => $wpa145_foreign['event'],
	'occurrence_id' => $wpa145_foreign['occ'],
	'ticket_type_id' => $wpa145_foreign['type'],
	'order_id' => (int) $wpdb->get_var( "SELECT order_id FROM {$p}wpcap_order_items WHERE ticket_type_id=" . (int) $wpa145_foreign['type'] . ' LIMIT 1' ),
	'ticket_id' => (int) $wpdb->get_var( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE ticket_type_id=" . (int) $wpa145_foreign['type'] . ' LIMIT 1' ),
	'resource_id' => $wpa145_res,
	'slot_id' => $wpa145_slot,
	'booking_id' => $wpa145_booking,
	'assignment_id' => $wpa145_assignment,
	'user_id' => 9161,
);
$wpa145_id_value = static function ( $name ) use ( $wpdb, $p, $wpa145_fixed, $wpa145_own ) {
	static $cache = array();
	$column = preg_replace( '/s$/', '', (string) $name );
	if ( isset( $wpa145_fixed[ $column ] ) ) { return $wpa145_fixed[ $column ]; }
	if ( isset( $cache[ $column ] ) ) { return $cache[ $column ]; }
	$table = $wpdb->get_var( $wpdb->prepare( "SELECT c.TABLE_NAME FROM information_schema.COLUMNS c WHERE c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME LIKE %s AND c.COLUMN_NAME=%s AND c.COLUMN_KEY='PRI' AND c.EXTRA LIKE %s ORDER BY c.TABLE_NAME LIMIT 1", addcslashes( $p, '_' ) . 'wpcap\\_%', $column, '%auto_increment%' ) );
	$id = 0;
	if ( $table ) {
		$has_event = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME=%s', $table, 'event_id' ) );
		$id = (int) $wpdb->get_var( "SELECT MAX(`$column`) FROM `$table`" . ( $has_event ? ' WHERE event_id NOT IN (0,' . (int) $wpa145_own['event'] . ')' : '' ) );
		if ( ! $id ) { $id = (int) $wpdb->get_var( "SELECT MAX(`$column`) FROM `$table`" . ( $has_event ? ' WHERE event_id<>' . (int) $wpa145_own['event'] : '' ) ); }
	}
	$cache[ $column ] = $id;
	return $id;
};

/** Capabilities the plugin checks (literal capability strings in the source) plus the plugin's own. */
$wpa145_caps = Installer::CAPABILITIES;
foreach ( array( "/current_user_can\(\s*'([a-z_]+)'/", "/user_can\([^,]+,\s*'([a-z_]+)'/", "/const [A-Z_]*CAP[A-Z_]* = '([a-z_]+)'/" ) as $wpa145_re ) {
	foreach ( wpcap_test_source_files_with( 'can' ) as $wpa145_file ) {
		if ( preg_match_all( $wpa145_re, (string) file_get_contents( $wpa145_root . '/' . $wpa145_file ), $wpa145_m ) ) { $wpa145_caps = array_merge( $wpa145_caps, $wpa145_m[1] ); }
	}
}
$wpa145_caps = array_values( array_unique( array_merge( $wpa145_caps, array( 'manage_options', 'manage_woocommerce', 'edit_posts', 'list_users', 'edit_users' ) ) ) );
$wpa145_caps = array_values( array_filter( $wpa145_caps, static fn( $c ) => ! in_array( $c, array( 'user_cap_added', 'user_cap_removed' ), true ) ) );

/** Who acts: 'nocaps' (logged in, no capability at all) or 'foreign' (every operational capability, own event only). */
$wpa145_as = static function ( $who ) use ( $wpa145_probe_user, $wpa145_caps ) {
	$GLOBALS['wpcap_test_user_id'] = $wpa145_probe_user;
	$GLOBALS['wpcap_test_denied_caps'] = 'nocaps' === $who ? $wpa145_caps : array( 'manage_options', 'manage_woocommerce', 'wpcap_view_finance' );
	$GLOBALS['wpcap_test_transients'] = array();
	StaffAssignments::flush();
};

/** Thrown by the wpcap_response_end action during a probe: the handler sent a download/page. */
final class Wpa145_Response_End extends Exception {}
$GLOBALS['wpa145_end'] = static function () { throw new Wpa145_Response_End( 'response' ); };
$GLOBALS['wpa145_probe'] = '';
register_shutdown_function( static function () {
	if ( '' !== ( $GLOBALS['wpa145_probe'] ?? '' ) ) { echo "  FAIL A145 probe terminated the process: {$GLOBALS['wpa145_probe']}\n\n" . ( $GLOBALS['passes'] ?? 0 ) . ' passed, ' . ( ( $GLOBALS['failures'] ?? 0 ) + 1 ) . " failed\n"; }
} );

/** Outcome of an admin-post/AJAX handler call: denied, notfound, error:<msg>, ok, exception:<msg>. */
$wpa145_call = static function ( $label, callable $cb, array $payload ) {
	$GLOBALS['wpa145_probe'] = $label;
	$saved = array( $_POST, $_GET, $_REQUEST );
	$_POST = $payload;
	$_GET = $payload;
	$_REQUEST = $payload;
	$key = 'wpcap_admin_notices_' . get_current_user_id();
	unset( $GLOBALS['wpcap_test_transients'][ $key ] );
	add_action( 'wpcap_response_end', $GLOBALS['wpa145_end'] );
	ob_start();
	try {
		call_user_func( $cb );
		$out = 'ok';
	} catch ( Wpa145_Response_End $e ) {
		$out = 'ok';
	} catch ( Wpcap_Test_Die $e ) {
		$out = 'denied';
	} catch ( Wpcap_Test_Redirect $e ) {
		$errors = array_filter( (array) ( $GLOBALS['wpcap_test_transients'][ $key ] ?? array() ), static fn( $n ) => 'error' === ( $n['type'] ?? '' ) );
		$out = $errors ? 'error:' . reset( $errors )['message'] : 'ok';
	} catch ( Wpmt_Json_Response $e ) {
		$out = $e->success ? 'ok' : ( in_array( (int) ( $e->payload['status'] ?? 0 ), array( 401, 403 ), true ) ? 'denied' : 'error:json' );
	} catch ( Throwable $e ) {
		$out = 'exception:' . get_class( $e ) . ':' . $e->getMessage();
	}
	ob_end_clean();
	remove_action( 'wpcap_response_end', $GLOBALS['wpa145_end'] );
	list( $_POST, $_GET, $_REQUEST ) = $saved;
	$GLOBALS['wpa145_probe'] = '';
	return $out;
};

/** Outcome of a REST endpoint: denied (permission callback false/WP_Error or 401/403), notfound (404), error:<code>, ok, exception. */
$wpa145_rest_call = static function ( $key, array $endpoint, array $params ) {
	$GLOBALS['wpa145_probe'] = $key;
	$req = new WP_REST_Request( $endpoint['method'] );
	foreach ( $params as $k => $v ) { $req->set_param( $k, $v ); }
	try {
		$perm = call_user_func( $endpoint['args']['permission_callback'], $req );
		if ( is_wp_error( $perm ) || ! $perm ) {
			$GLOBALS['wpa145_probe'] = '';
			return 'denied';
		}
		$res = call_user_func( $endpoint['args']['callback'], $req );
		$status = is_wp_error( $res ) ? (int) ( ( (array) $res->get_error_data() )['status'] ?? 0 ) : 0;
		$out = is_wp_error( $res ) ? ( in_array( $status, array( 401, 403 ), true ) ? 'denied' : ( 404 === $status ? 'notfound' : 'error:' . $res->get_error_code() ) ) : 'ok';
	} catch ( Throwable $e ) {
		$out = 'exception:' . get_class( $e ) . ':' . $e->getMessage();
	}
	$GLOBALS['wpa145_probe'] = '';
	return $out;
};

// --- static analysis: the code reachable from an entry point ------------------------------------
/** Source of a callable plus the plugin methods it calls (self::/static::/Class::), up to $depth levels. */
$wpa145_reachable = static function ( $callable, $depth = 4 ) {
	$seen = array();
	$source = '';
	$queue = array( array( $callable, 0 ) );
	while ( $queue ) {
		list( $cb, $level ) = array_shift( $queue );
		if ( $cb instanceof Closure ) {
			// Closures (e.g. permission callbacks built by a factory): their own code, self:: = scope class.
			$ref = new ReflectionFunction( $cb );
			$class_name = $ref->getClosureScopeClass() ? $ref->getClosureScopeClass()->getName() : '';
			$key = strtolower( $class_name . '::{closure}' . $ref->getStartLine() );
			// Closures it captured (use ( $auth )) are part of its code path, too.
			foreach ( $ref->getStaticVariables() as $captured ) {
				if ( $captured instanceof Closure || ( is_array( $captured ) && 2 === count( $captured ) && is_string( $captured[0] ?? null ) && is_string( $captured[1] ?? null ) ) ) { $queue[] = array( $captured, $level + 1 ); }
			}
		} else {
			if ( ! is_array( $cb ) || ! is_string( $cb[0] ) || ! method_exists( $cb[0], $cb[1] ) ) { continue; }
			$ref = new ReflectionMethod( $cb[0], $cb[1] );
			$class_name = $ref->getDeclaringClass()->getName();
			$key = strtolower( $class_name . '::' . $ref->getName() );
		}
		if ( isset( $seen[ $key ] ) ) { continue; }
		$seen[ $key ] = true;
		$file = (string) $ref->getFileName();
		if ( ! $file || 0 !== strpos( $class_name, 'PassCaptain\\' ) ) { continue; }
		$lines = file( $file );
		$body = implode( '', array_slice( $lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1 ) );
		$source .= "\n/* " . $key . " */\n" . $body;
		if ( $level >= $depth ) { continue; }
		$file_src = implode( '', $lines );
		preg_match( '/^namespace\s+([A-Za-z_\\\\]+);/m', $file_src, $ns );
		$uses = array();
		if ( preg_match_all( '/^use\s+([A-Za-z_\\\\]+?)(?:\s+as\s+([A-Za-z_]+))?;/m', $file_src, $um, PREG_SET_ORDER ) ) {
			foreach ( $um as $u ) { $uses[ ! empty( $u[2] ) ? $u[2] : substr( (string) strrchr( '\\' . $u[1], '\\' ), 1 ) ] = $u[1]; }
		}
		preg_match_all( '/(\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)::([a-z_][A-Za-z0-9_]*)\s*\(/', $body, $calls, PREG_SET_ORDER );
		foreach ( $calls as $call ) {
			$class = $call[1];
			if ( in_array( strtolower( $class ), array( 'self', 'static' ), true ) ) { $class = $class_name; }
			elseif ( '\\' === $class[0] ) { $class = substr( $class, 1 ); }
			else {
				$first = strtok( $class, '\\' );
				$class = isset( $uses[ $first ] ) ? $uses[ $first ] . substr( $class, strlen( $first ) ) : ( ( $ns[1] ?? '' ) . '\\' . $class );
			}
			if ( 0 === strpos( $class, 'PassCaptain\\' ) && class_exists( $class ) ) { $queue[] = array( array( $class, $call[2] ), $level + 1 ); }
		}
	}
	return $source;
};
/** Capability or access check anywhere in the code. */
$wpa145_has_permission = static function ( $source ) {
	return (bool) preg_match( '/current_user_can\(|Access::|::can(?:_[a-z_]+)?\(|_permission\(|::permission\(|is_global_admin\(|DeveloperKeys::authorize\(|::authenticate\(|function authenticate\(/', $source );
};
/** Object-scoped check (event/occurrence/order/service of the object against the user's assignments). */
$wpa145_has_object_scope = static function ( $source ) {
	return (bool) preg_match( '/Access::(?:can_access_(?:event|occurrence|resource)|order_in_scope|occurrence_in_scope|event_in_scope|event_fully_in_scope|filter_event_ids|occurrence_scope_sql)\(|can_access_order\(|can_access_event_any\(|Scope::(?:can_event|can_occurrence|can_order|event_ids)\(|event_ids_for\(|can_handle_booking\(|StaffAssignments::can_manage\(|Devices::event_ids\('
		// Service-booking self-service: the personal booking token; box office: shifts, Z reports and
		// receipts belong to their cashiers (ownership).
		. '|verify_token\(|Shifts::can_(?:operate|view)\(|can_view_z\(|can_view_sale\(|is_cashier\(|->(?:from_user|to_user|user_id|created_by)\s*[!=]==\s*get_current_user_id\(\)'
		// Partner API (own key model): the key's partner and its events.
		. "|\\\$context\\['event_ids'\\]|context\\['partner'\\]->partner_id|partner_id=%d/", $source );
};
/** Object-id parameter names the code reads (Forms::int/ids, $_POST/$_GET, REST params). */
$wpa145_id_keys_in = static function ( $source ) {
	preg_match_all( "/(?:Forms::(?:int|ids|ids_or_keys|text)\(\s*\\\$in,\s*|\\\$_(?:POST|GET|REQUEST)\[\s*|get_param\(\s*|\\\$request\[\s*|\\\$in\[\s*)'([a-z_]*_ids?)'/", $source, $m );
	return array_values( array_diff( array_unique( $m[1] ), array( 'public_id' ) ) );
};

// --- entry points --------------------------------------------------------------------------------
// Payload of every admin-post/AJAX probe: every id parameter any handler reads, pointing at existing
// objects of the foreign event, plus the usual fields, so that validation does not stop the request
// before the permission check.
$wpa145_payload = array( '_wpnonce' => 'n', 'user' => 'probe145@example.com', 'capability' => 'wpcap_checkin', 'status' => 'confirmed', 'scope' => 'occurrence', 'object_id' => (string) $wpa145_fixed['occurrence_id'], 'confirm' => '1', 'name' => 'WPA145', 'reason' => 'WPA145', 'amount' => '1', 'price' => '10' );
$wpa145_all_keys = array();
foreach ( $wpa145_all_post + $wpa145_ajax as $wpa145_cb ) { $wpa145_all_keys = array_merge( $wpa145_all_keys, $wpa145_id_keys_in( $wpa145_reachable( $wpa145_cb ) ) ); }
foreach ( array_unique( $wpa145_all_keys ) as $wpa145_k ) {
	$wpa145_payload[ $wpa145_k ] = str_ends_with( $wpa145_k, '_ids' ) ? array( (string) $wpa145_id_value( $wpa145_k ) ) : (string) $wpa145_id_value( $wpa145_k );
}

// Public admin-post actions (also registered for visitors): the token of the waitlist offer or link is
// verified by the function they call (tests in zz_a158.php / waitlist tests).
$wpa145_public_post = array( 'wpcap_accept_waitlist', PassCaptain\Waitlists::UNSUBSCRIBE_ACTION );
$wpa145_nopriv = array_values( array_unique( $wpa145_scanned['nopriv'] ) );
sort( $wpa145_nopriv );
$wpa145_public_sorted = $wpa145_public_post;
sort( $wpa145_public_sorted );
check( 'public admin-post actions are exactly the visitor (nopriv) ones and pass the token to the verifying function; no visitor AJAX', $wpa145_nopriv === $wpa145_public_sorted && ! $wpa145_scanned['ajax_nopriv'] && (bool) preg_match( '/accept_offer\(\s*\$waitlist_id,\s*\$token/', $wpa145_reachable( $wpa145_all_post['wpcap_accept_waitlist'], 0 ) ) && (bool) preg_match( '/unsubscribe\(\s*\$waitlist_id,\s*\$token/', $wpa145_reachable( $wpa145_all_post[ PassCaptain\Waitlists::UNSUBSCRIBE_ACTION ], 0 ) ) );

$wpa145_entries = array();
foreach ( $wpa145_rest as $wpa145_key => $wpa145_ep ) {
	if ( wpcap_test_rest_is_public( $wpa145_key, $wpa145_ep ) ) { continue; }
	$wpa145_src = $wpa145_reachable( $wpa145_ep['args']['callback'] ) . $wpa145_reachable( $wpa145_ep['args']['permission_callback'] );
	preg_match_all( '/\(\?P<([a-z_]+)>/', $wpa145_ep['route'], $wpa145_pm );
	$wpa145_keys = array_values( array_unique( array_merge( $wpa145_pm[1], $wpa145_id_keys_in( $wpa145_src ) ) ) );
	$wpa145_params = array();
	foreach ( $wpa145_keys as $wpa145_k ) { $wpa145_params[ $wpa145_k ] = str_ends_with( $wpa145_k, '_ids' ) ? array( $wpa145_id_value( $wpa145_k ) ) : $wpa145_id_value( $wpa145_k ); }
	$wpa145_entries[ 'REST ' . $wpa145_key ] = array( 'kind' => 'rest', 'key' => $wpa145_key, 'ep' => $wpa145_ep, 'params' => $wpa145_params, 'src' => $wpa145_src, 'ids' => array_values( array_filter( $wpa145_keys, static fn( $k ) => (bool) preg_match( '/_ids?$/', $k ) ) ) );
}
foreach ( $wpa145_all_post as $wpa145_action => $wpa145_cb ) {
	if ( in_array( $wpa145_action, $wpa145_public_post, true ) ) { continue; }
	$wpa145_src = $wpa145_reachable( $wpa145_cb );
	$wpa145_entries[ 'POST ' . $wpa145_action ] = array( 'kind' => 'admin-post', 'cb' => $wpa145_cb, 'payload' => array( 'action' => $wpa145_action ) + $wpa145_payload, 'src' => $wpa145_src, 'ids' => $wpa145_id_keys_in( $wpa145_src ) );
}
foreach ( $wpa145_ajax as $wpa145_action => $wpa145_cb ) {
	$wpa145_src = $wpa145_reachable( $wpa145_cb );
	$wpa145_entries[ 'AJAX ' . $wpa145_action ] = array( 'kind' => 'ajax', 'cb' => $wpa145_cb, 'payload' => array( 'action' => $wpa145_action ) + $wpa145_payload, 'src' => $wpa145_src, 'ids' => $wpa145_id_keys_in( $wpa145_src ) );
}
$wpa145_run = static function ( array $entry ) use ( $wpa145_call, $wpa145_rest_call ) {
	return 'rest' === $entry['kind'] ? $wpa145_rest_call( $entry['key'], $entry['ep'], $entry['params'] ) : $wpa145_call( $entry['kind'] . ' ' . ( $entry['payload']['action'] ?? '' ), $entry['cb'], $entry['payload'] );
};

echo "A145: every authenticated entry point refuses a user without capabilities\n";
$wpa145_bad = array();
foreach ( $wpa145_entries as $wpa145_name => $wpa145_entry ) {
	$wpa145_as( 'nocaps' );
	$wpa145_out = $wpa145_run( $wpa145_entry );
	if ( 'denied' !== $wpa145_out ) { $wpa145_bad[] = "$wpa145_name ($wpa145_out)"; }
}
check( count( $wpa145_entries ) . ' entry points probed without capabilities: all refused (403)' . ( $wpa145_bad ? ' – ' . implode( '; ', $wpa145_bad ) : '' ), count( $wpa145_entries ) >= 150 && ! $wpa145_bad );

echo "A145: entry points taking an object id refuse objects of an event the user is not assigned to\n";
$wpa145_bad = array();
$wpa145_probed = 0;
$wpa145_outcomes = array();
foreach ( $wpa145_entries as $wpa145_name => $wpa145_entry ) {
	if ( ! $wpa145_entry['ids'] ) { continue; }
	$wpa145_probed++;
	$wpa145_as( 'foreign' );
	$wpa145_out = $wpa145_run( $wpa145_entry );
	$wpa145_kind = (string) strtok( $wpa145_out, ':' );
	$wpa145_outcomes[ $wpa145_kind ] = ( $wpa145_outcomes[ $wpa145_kind ] ?? 0 ) + 1;
	// Refused (403/404), or stopped by validation in a code path with an object-scoped check.
	$wpa145_ok = in_array( $wpa145_out, array( 'denied', 'notfound' ), true ) || ( 'error' === $wpa145_kind && $wpa145_has_object_scope( $wpa145_entry['src'] ) );
	if ( ! $wpa145_ok ) { $wpa145_bad[] = "$wpa145_name ($wpa145_out)"; }
}
ksort( $wpa145_outcomes );
check( $wpa145_probed . ' entry points with object ids probed with objects of a foreign event: all refused ' . wp_json_encode( $wpa145_outcomes ) . ( $wpa145_bad ? ' – ' . implode( '; ', $wpa145_bad ) : '' ), $wpa145_probed >= 120 && ! $wpa145_bad );

echo "A145: static check of every entry point\n";
// Administrator-only entry points (manage_options, also through a class constant such as self::CAP)
// need no object scope: staff never get through.
$wpa145_admin_only = array();
foreach ( $wpa145_entries as $wpa145_name => $wpa145_entry ) {
	$wpa145_admin = (bool) preg_match( "/current_user_can\\(\\s*'manage_options'/", $wpa145_entry['src'] );
	preg_match_all( '#/\* ([a-z0-9_\\\\]+)::#', $wpa145_entry['src'], $wpa145_cm );
	foreach ( array_unique( $wpa145_cm[1] ) as $wpa145_class ) {
		if ( $wpa145_admin || ! class_exists( $wpa145_class ) ) { continue; }
		$wpa145_short = substr( (string) strrchr( '\\' . $wpa145_class, '\\' ), 1 );
		foreach ( ( new ReflectionClass( $wpa145_class ) )->getConstants() as $wpa145_const => $wpa145_val ) {
			if ( 'manage_options' === $wpa145_val && preg_match( '/current_user_can\\(\\s*(?:self|static|' . preg_quote( $wpa145_short, '/' ) . ')::' . $wpa145_const . '\\b/i', $wpa145_entry['src'] ) ) { $wpa145_admin = true; }
		}
	}
	if ( $wpa145_admin ) { $wpa145_admin_only[] = $wpa145_name; }
}
$wpa145_no_perm = array();
$wpa145_no_scope = array();
foreach ( $wpa145_entries as $wpa145_name => $wpa145_entry ) {
	if ( ! $wpa145_has_permission( $wpa145_entry['src'] ) ) { $wpa145_no_perm[] = $wpa145_name; }
	if ( $wpa145_entry['ids'] && ! $wpa145_has_object_scope( $wpa145_entry['src'] ) && ! in_array( $wpa145_name, $wpa145_admin_only, true ) ) { $wpa145_no_scope[] = $wpa145_name; }
}
check( 'every authenticated entry point has a capability/access check in its code path' . ( $wpa145_no_perm ? ' – ' . implode( ', ', $wpa145_no_perm ) : '' ), ! $wpa145_no_perm );
check( 'every entry point reading an object id has an object-scoped check (or is for administrators only)' . ( $wpa145_no_scope ? ' – ' . implode( ', ', $wpa145_no_scope ) : '' ), ! $wpa145_no_scope );

echo "A145: fixes found by the probes\n";
$wpa145_as( 'foreign' );
$wpa145_scope = PassCaptain\Reports\Data::scope();
$wpa145_filter = static fn( $event, $occ = 0 ) => array( 'from' => gmdate( 'Y-m-d' ), 'to' => gmdate( 'Y-m-d' ), 'event_id' => $event, 'occurrence_id' => $occ );
check( 'report filter on a foreign event/occurrence is refused, the own event allowed', ! PassCaptain\Reports\Page::filters_allowed( $wpa145_filter( $wpa145_foreign['event'] ), $wpa145_scope ) && ! PassCaptain\Reports\Page::filters_allowed( $wpa145_filter( 0, $wpa145_foreign['occ'] ), $wpa145_scope ) && PassCaptain\Reports\Page::filters_allowed( $wpa145_filter( $wpa145_own['event'], $wpa145_own['occ'] ), $wpa145_scope ) && PassCaptain\Reports\Page::filters_allowed( $wpa145_filter( 0 ), $wpa145_scope ) );
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
$wpa145_prov = PassCaptain\ServiceProviders::save( array( 'name' => 'WPA145 Leistung', 'user_id' => 9161 ) );
PassCaptain\ServiceProviders::assign( $wpa145_res, array( $wpa145_prov ) );
$wpa145_as( 'foreign' );
$wpa145_prov_edit = PassCaptain\ServiceProviders::save( array( 'name' => 'WPA145 Leistung', 'user_id' => $wpa145_probe_user ), $wpa145_prov );
$wpa145_prov_new = PassCaptain\ServiceProviders::save( array( 'name' => 'WPA145 Neu' ) );
check( 'provider record of a foreign event cannot be relinked (403, user unchanged); new records can be created', is_wp_error( $wpa145_prov_edit ) && 403 === $wpa145_prov_edit->get_error_data()['status'] && 9161 === (int) PassCaptain\ServiceProviders::get( $wpa145_prov )->user_id && is_int( $wpa145_prov_new ) );
$wpa145_as( 'nocaps' );
$wpa145_die = false;
try { PassCaptain\Admin\Forms::require_any_cap( array( 'wpcap_manage_orders' ) ); } catch ( Wpcap_Test_Die $e ) { $wpa145_die = true; }
$wpa145_as( 'foreign' );
$wpa145_pass = true;
try { PassCaptain\Admin\Forms::require_any_cap( array( 'manage_options', 'wpcap_manage_orders' ) ); } catch ( Wpcap_Test_Die $e ) { $wpa145_pass = false; }
check( 'Forms::require_any_cap(): 403 without any of the capabilities, passes with one of them', $wpa145_die && $wpa145_pass );

$GLOBALS['wpcap_test_user_id'] = $wpa145_saved_user ?? 1;
$GLOBALS['wpcap_test_denied_caps'] = $wpa145_saved_denied;
$GLOBALS['wpcap_test_transients'] = array();
StaffAssignments::flush();

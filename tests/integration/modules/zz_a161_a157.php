<?php
/**
 * A161: developer API keys in the admin (list, create with one-time secret, change expiry and rate
 * limit, revoke), per-key rate limit, route → scope map, event and location scope.
 * A157: admin page for service resources, time slots and bookings.
 * Included from run.php (helpers from p3_exchange.php, stubs from admin.php / zz_a119.php).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\DeveloperKeys;
use PassCaptain\ServiceBookings;
use PassCaptain\Admin\DeveloperKeysPage;
use PassCaptain\Admin\ServiceSlotsPage;
use PassCaptain\Admin\Menu;

if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = null ) { return esc_attr( $t ); } }
if ( ! function_exists( 'selected' ) ) { function selected( $a, $b = true, $echo = true ) { $r = $a == $b ? ' selected="selected"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce" value="n">'; } }
if ( ! function_exists( 'submit_button' ) ) { function submit_button( $t = '', $type = 'primary', $name = 'submit', $wrap = true ) { echo '<button>' . esc_html( $t ) . '</button>'; } }
if ( ! function_exists( 'wp_timezone_string' ) ) { function wp_timezone_string() { return 'Europe/Vienna'; } }
if ( ! function_exists( 'delete_transient' ) ) { function delete_transient( $k ) { unset( $GLOBALS['wpcap_test_transients'][ $k ] ); return true; } }
if ( ! function_exists( 'register_rest_route' ) ) { function register_rest_route( $ns, $route, $args ) { $GLOBALS['wpcap_test_routes'][ $ns . $route ] = $args; return true; } }
if ( ! function_exists( 'rest_ensure_response' ) ) { function rest_ensure_response( $r ) { return $r instanceof WP_REST_Response ? $r : new WP_REST_Response( $r ); } }

global $wpdb;
$p = $wpdb->prefix;
$z_saved_user = $GLOBALS['wpcap_test_user_id'] ?? null;
$z_saved_denied = $GLOBALS['wpcap_test_denied_caps'] ?? array();
$z_saved_filters = $GLOBALS['wpcap_test_filters'] ?? array();
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
$z_audit = static function ( $action, $type, $id ) use ( $wpdb, $p ) {
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_audit_logs WHERE action=%s AND object_type=%s AND object_id=%d ORDER BY audit_id DESC LIMIT 1", $action, $type, $id ) );
};
$z_render = static function ( callable $render, array $get = array() ) {
	$saved = $_GET;
	$_GET = $get;
	ob_start();
	try { $render(); $html = ob_get_clean(); } catch ( Throwable $e ) { ob_end_clean(); $html = 'EXCEPTION ' . get_class( $e ) . ' ' . $e->getMessage(); }
	$_GET = $saved;
	return $html;
};
$z_request = static function ( $key, $event_id ) {
	$r = new WP_REST_Request( 'GET' );
	$r->set_header( 'X-WPCAP-Key', $key );
	$r->set_param( 'event_id', $event_id );
	return $r;
};

echo "A161: developer API keys administrable\n";
check( 'schema 3.10.0: api_keys.rate_limit exists', version_compare( PassCaptain\Installer::DB_VERSION, '3.10.0', '>=' ) && DeveloperKeys::ready() && null !== $wpdb->get_row( "SHOW COLUMNS FROM {$p}wpcap_api_keys LIKE 'rate_limit'" ) );
check( 'menu: "Entwickler-API" in the integrations area (manage_options), "Service-Zeitfenster" in events', 'settings' === Menu::group_of( DeveloperKeysPage::SLUG ) && 'manage_options' === DeveloperKeysPage::CAP && 'events' === Menu::group_of( ServiceSlotsPage::SLUG )
	&& isset( Menu::handlers()['wpcap_developer_key_create'], Menu::handlers()['wpcap_developer_key_update'], Menu::handlers()['wpcap_developer_key_revoke'], Menu::handlers()['wpcap_service_resource_save'], Menu::handlers()['wpcap_service_resource_delete'], Menu::handlers()['wpcap_service_slot_save'], Menu::handlers()['wpcap_service_slot_delete'] )
	&& in_array( DeveloperKeysPage::SLUG, array_column( Menu::entries(), 0 ), true ) && in_array( ServiceSlotsPage::SLUG, array_column( Menu::entries(), 0 ), true ) );

$z_event = ex_event( 'A161 Workshoptage' );
$z_occ = ex_occ( $z_event );
$z_other_event = ex_event( 'A161 Anderes Event' );
ex_occ( $z_other_event );

// Create via the admin page: local expiry (Europe/Vienna), own rate limit.
$GLOBALS['wpcap_test_transients'] = array();
$z_local_expiry = ( new DateTimeImmutable( '+10 days', new DateTimeZone( 'Europe/Vienna' ) ) )->format( 'Y-m-d\TH:i' );
$z_created = DeveloperKeysPage::create( array( 'label' => 'Statistik', 'event_id' => $z_event, 'scopes' => array( 'events.read', 'slots.read', 'admin.write' ), 'expires_at' => $z_local_expiry, 'rate_limit' => '3' ) );
$z_key_id = is_array( $z_created ) ? (int) $z_created['api_key_id'] : 0;
$z_row = $wpdb->get_row( "SELECT * FROM {$p}wpcap_api_keys WHERE api_key_id=$z_key_id" );
$z_expected_utc = ( new DateTimeImmutable( $z_local_expiry, new DateTimeZone( 'Europe/Vienna' ) ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
check( 'admin create: key with label, event, known scopes only, local expiry stored as UTC, own rate limit', $z_row && 'Statistik' === $z_row->label && $z_event === (int) $z_row->event_id && array( 'events.read', 'slots.read' ) === DeveloperKeys::key_scopes( $z_row ) && $z_expected_utc === $z_row->expires_at && 3 === (int) $z_row->rate_limit && 1 === (int) $z_row->user_id );
check( 'only the hash is stored', $z_row && hash( 'sha256', explode( '.', $z_created['key'] )[1] ) === $z_row->secret_hash && false === strpos( wp_json_encode( $z_row ), explode( '.', $z_created['key'] )[1] ) );
$z_issued = $z_audit( 'developer_key_issued', 'api_key', $z_key_id );
check( 'creation audited with prefix, never the secret', $z_issued && false !== strpos( $z_issued->details, $z_row->public_prefix ) && false === strpos( $z_issued->details, explode( '.', $z_created['key'] )[1] ) );
$z_html = $z_render( array( DeveloperKeysPage::class, 'render' ) );
$z_html2 = $z_render( array( DeveloperKeysPage::class, 'render' ) );
check( 'secret is shown exactly once after creation', false !== strpos( $z_html, $z_created['key'] ) && false === strpos( $z_html2, $z_created['key'] ) && false !== strpos( $z_html2, 'Entwickler-API' ) );
check( 'list shows prefix, label, owner, scopes, event, expiry, rate limit, status and actions', false !== strpos( $z_html2, $z_row->public_prefix ) && false !== strpos( $z_html2, 'Statistik' ) && false !== strpos( $z_html2, 'events.read, slots.read' ) && false !== strpos( $z_html2, 'A161 Workshoptage' ) && false !== strpos( $z_html2, '3/min' ) && false !== strpos( $z_html2, 'Aktiv' ) && false !== strpos( $z_html2, 'wpcap_developer_key_revoke' ) && false !== strpos( $z_html2, 'wpcap_developer_key_update' ) && false !== strpos( $z_html2, 'Zuletzt genutzt' ) );
check( 'route/scope table on the page', false !== strpos( $z_html2, '/developer/events/{event_id}/service-slots' ) && false !== strpos( $z_html2, 'reports.read' ) );

// Validation.
check( 'unknown scopes only → rejected', is_wp_error( DeveloperKeysPage::create( array( 'event_id' => $z_event, 'scopes' => array( 'admin.write' ) ) ) ) );
check( 'expiry in the past → rejected', is_wp_error( $e = DeveloperKeysPage::create( array( 'event_id' => $z_event, 'scopes' => array( 'events.read' ), 'expires_at' => '2020-01-01T10:00' ) ) ) && 'wpcap_api_key_expiry' === $e->get_error_code() );
check( 'rate limit above 10000 → rejected', is_wp_error( $e = DeveloperKeysPage::create( array( 'event_id' => $z_event, 'scopes' => array( 'events.read' ), 'rate_limit' => 20000 ) ) ) && 'wpcap_api_key_rate' === $e->get_error_code() );
check( 'unknown event → rejected', is_wp_error( DeveloperKeysPage::create( array( 'event_id' => 99999999, 'scopes' => array( 'events.read' ) ) ) ) );

// Non-administrators: no page, no actions.
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$z_denied_create = DeveloperKeysPage::create( array( 'event_id' => $z_event, 'scopes' => array( 'events.read' ) ) );
$z_denied_page = $z_render( array( DeveloperKeysPage::class, 'render' ) );
check( 'without manage_options: create/update/revoke forbidden (403), page dies', is_wp_error( $z_denied_create ) && 403 === $z_denied_create->get_error_data()['status'] && is_wp_error( DeveloperKeysPage::revoke( array( 'api_key_id' => $z_key_id ) ) ) && is_wp_error( DeveloperKeysPage::update( array( 'api_key_id' => $z_key_id ) ) ) && 0 === strpos( $z_denied_page, 'EXCEPTION' ) );
$GLOBALS['wpcap_test_denied_caps'] = array();

// Handlers check the per-action nonce.
$GLOBALS['wpcap_test_nonce_checks'] = array();
$_POST = array( 'api_key_id' => (string) $z_key_id, 'expires_at' => $z_local_expiry, 'rate_limit' => '3' );
try { DeveloperKeysPage::handle_update(); } catch ( Wpcap_Test_Redirect $e ) { }
$_POST = array();
check( 'update handler verifies its nonce', in_array( 'wpcap_developer_key_update_' . $z_key_id, $GLOBALS['wpcap_test_nonce_checks'], true ) );

// Route → scope map is complete and enforced.
$z_saved_routes = $GLOBALS['wpcap_test_routes'] ?? array();
$GLOBALS['wpcap_test_routes'] = array();
PassCaptain\Rest\Controller::register_routes();
$z_routes = $GLOBALS['wpcap_test_routes'];
$GLOBALS['wpcap_test_routes'] = $z_saved_routes;
$z_map_ok = true;
foreach ( DeveloperKeys::ROUTES as $z_route => $z_scope ) {
	list( $z_method, $z_path ) = explode( ' ', $z_route, 2 );
	$z_reg = 'pass-captain/v1' . str_replace( '{event_id}', '(?P<event_id>\d+)', $z_path );
	$z_map_ok = $z_map_ok && isset( $z_routes[ $z_reg ] ) && 'GET' === $z_routes[ $z_reg ]['methods'] && is_callable( $z_routes[ $z_reg ]['permission_callback'] ) && isset( DeveloperKeys::scopes()[ $z_scope ] );
}
check( 'every mapped developer route is registered with a permission callback and a known scope', $z_map_ok && 3 === count( DeveloperKeys::ROUTES ) );
$z_src = (string) file_get_contents( WPCAP_PATH . 'src/Rest/Controller.php' );
preg_match_all( "/DeveloperKeys::(?:authorize|permission)\( \\\$request, '([a-z.]+)'/", $z_src, $z_used );
check( 'every scope checked in the controller is in the route map', $z_used[1] && ! array_diff( $z_used[1], array_values( DeveloperKeys::ROUTES ) ) && ! array_diff( array_values( DeveloperKeys::ROUTES ), $z_used[1] ) );

$GLOBALS['wpcap_test_transients'] = array();
$z_key = $z_created['key'];
check( 'events.read key reads its event', PassCaptain\Rest\Controller::developer_key_permission( $z_request( $z_key, $z_event ) ) );
check( 'slots.read key passes the slots route', PassCaptain\Rest\Controller::developer_slots_permission( $z_request( $z_key, $z_event ) ) );
$GLOBALS['wpcap_test_transients'] = array();
check( 'missing scope reports.read → summary refused for an anonymous caller', ( function () use ( $z_request, $z_key, $z_event ) { $GLOBALS['wpcap_test_user_id'] = 0; $r = PassCaptain\Rest\Controller::report_permission( $z_request( $z_key, $z_event ) ); $GLOBALS['wpcap_test_user_id'] = 1; return ! $r; } )() );
check( 'event restriction: another event is refused', ! PassCaptain\Rest\Controller::developer_key_permission( $z_request( $z_key, $z_other_event ) ) );
check( 'wrong secret is refused', ! PassCaptain\Rest\Controller::developer_key_permission( $z_request( $z_row->public_prefix . '.' . str_repeat( 'a', 64 ), $z_event ) ) );

// Per-key rate limit (3/min) and the filter.
$GLOBALS['wpcap_test_transients'] = array();
$z_hits = array();
for ( $i = 0; $i < 4; $i++ ) { $z_hits[] = DeveloperKeys::authorize( $z_request( $z_key, $z_event ), 'events.read', $z_event ) !== false; }
check( 'own rate limit: 3 requests per minute, the 4th is refused', array( true, true, true, false ) === $z_hits );
check( 'default rate limit is 600/min', 600 === DeveloperKeys::rate_limit( (object) array( 'api_key_id' => 1, 'rate_limit' => 0 ) ) && 600 === DeveloperKeys::DEFAULT_RATE_LIMIT );
$GLOBALS['wpcap_test_transients'] = array();
$GLOBALS['wpcap_test_filters']['wpcap_developer_key_rate_limit'] = 1;
$z_hits = array( DeveloperKeys::authorize( $z_request( $z_key, $z_event ), 'events.read', $z_event ) !== false, DeveloperKeys::authorize( $z_request( $z_key, $z_event ), 'events.read', $z_event ) !== false );
unset( $GLOBALS['wpcap_test_filters']['wpcap_developer_key_rate_limit'] );
check( 'filter wpcap_developer_key_rate_limit overrides the limit', array( true, false ) === $z_hits );
// Over the limit the route answers 429 with Retry-After, not 401/403 (translator review).
$GLOBALS['wpcap_test_transients'] = array();
$GLOBALS['wpcap_test_filters']['wpcap_developer_key_rate_limit'] = 1;
$z_first = PassCaptain\Rest\Controller::developer_key_permission( $z_request( $z_key, $z_event ) );
$z_second = PassCaptain\Rest\Controller::developer_key_permission( $z_request( $z_key, $z_event ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_developer_key_rate_limit'] );
$z_resp = new class() { public $headers = array(); public function header( $k, $v ) { $this->headers[ $k ] = $v; } public function get_status() { return 429; } };
DeveloperKeys::retry_after_header( $z_resp );
check( 'rate limit reached: first request true, then a 429 wpcap_rate_limited error with Retry-After', true === $z_first && is_wp_error( $z_second ) && 'wpcap_rate_limited' === $z_second->get_error_code() && 429 === ( $z_second->get_error_data()['status'] ?? 0 ) && (int) ( $z_resp->headers['Retry-After'] ?? 0 ) >= 1 && (int) $z_resp->headers['Retry-After'] <= 60 );
check( 'report route returns the developer-key result itself (a 429 WP_Error is never collapsed into true by ||)', 1 === preg_match( '/function report_permission.*?return DeveloperKeys::permission\( \$request, \'reports\.read\'/s', $z_src ) );
$GLOBALS['wpcap_test_transients'] = array();
check( 'a wrong key is still a plain refusal (false → 401/403), not 429', false === PassCaptain\Rest\Controller::developer_key_permission( $z_request( $z_row->public_prefix . '.' . str_repeat( 'b', 64 ), $z_event ) ) );
check( 'last use is recorded', null !== $wpdb->get_var( "SELECT last_used_at FROM {$p}wpcap_api_keys WHERE api_key_id=$z_key_id" ) );

// Location scope of the key owner (A119).
$GLOBALS['wpcap_test_transients'] = array();
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$GLOBALS['wpcap_test_filters']['wpcap_access_scope'] = array( 'locations' => array( 'Nirgendwo' ), 'partners' => null );
$z_out = DeveloperKeys::authorize( $z_request( $z_key, $z_event ), 'events.read', $z_event );
$GLOBALS['wpcap_test_filters']['wpcap_access_scope'] = array( 'locations' => array( 'Wien' ), 'partners' => null );
$z_in = DeveloperKeys::authorize( $z_request( $z_key, $z_event ), 'events.read', $z_event );
unset( $GLOBALS['wpcap_test_filters']['wpcap_access_scope'] );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'owner outside the event location scope → refused; inside → allowed', false === $z_out && 1 === $z_in );

// Expiry: expired key refused, extension makes it usable again (audited), shortening to the past rejected.
$GLOBALS['wpcap_test_transients'] = array();
$wpdb->update( "{$p}wpcap_api_keys", array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'api_key_id' => $z_key_id ) );
$z_expired = DeveloperKeys::authorize( $z_request( $z_key, $z_event ), 'events.read', $z_event );
$z_status_expired = DeveloperKeys::status( $wpdb->get_row( "SELECT * FROM {$p}wpcap_api_keys WHERE api_key_id=$z_key_id" ) );
$z_new_local = ( new DateTimeImmutable( '+60 days', new DateTimeZone( 'Europe/Vienna' ) ) )->format( 'Y-m-d\TH:i' );
$z_ext = DeveloperKeysPage::update( array( 'api_key_id' => $z_key_id, 'expires_at' => $z_new_local, 'rate_limit' => '' ) );
$z_row = $wpdb->get_row( "SELECT * FROM {$p}wpcap_api_keys WHERE api_key_id=$z_key_id" );
check( 'expired key refused and listed as expired', false === $z_expired && 'expired' === $z_status_expired );
check( 'extend expiry: new date stored, rate limit back to default, key usable again', true === $z_ext && $z_row->expires_at > gmdate( 'Y-m-d H:i:s', time() + 50 * DAY_IN_SECONDS ) && 0 === (int) $z_row->rate_limit && 'active' === DeveloperKeys::status( $z_row ) && false !== DeveloperKeys::authorize( $z_request( $z_key, $z_event ), 'events.read', $z_event ) );
$z_upd = $z_audit( 'developer_key_updated', 'api_key', $z_key_id );
check( 'expiry change audited with old and new values', $z_upd && false !== strpos( $z_upd->details, $z_row->expires_at ) );
check( 'expiry in the past cannot be set', is_wp_error( DeveloperKeysPage::update( array( 'api_key_id' => $z_key_id, 'expires_at' => '2020-01-01T00:00' ) ) ) );
check( 'no expiry (empty) is allowed', true === DeveloperKeysPage::update( array( 'api_key_id' => $z_key_id, 'expires_at' => '', 'rate_limit' => '50' ) ) && null === $wpdb->get_var( "SELECT expires_at FROM {$p}wpcap_api_keys WHERE api_key_id=$z_key_id" ) && 50 === (int) $wpdb->get_var( "SELECT rate_limit FROM {$p}wpcap_api_keys WHERE api_key_id=$z_key_id" ) );

// REST create with rate limit and validation moved into issue().
$z_req = new WP_REST_Request( 'POST' );
$z_req->set_body( wp_json_encode( array( 'event_id' => $z_event, 'scopes' => array( 'reports.read' ), 'label' => 'REST', 'rate_limit' => 120 ) ) );
$z_rest = PassCaptain\Rest\Controller::create_developer_key( $z_req );
$z_req2 = new WP_REST_Request( 'POST' );
$z_req2->set_body( wp_json_encode( array( 'event_id' => $z_event, 'scopes' => array( 'reports.read' ), 'expires_at' => '2020-01-01 00:00:00' ) ) );
check( 'REST create: 201 with rate limit; past expiry rejected', $z_rest instanceof WP_REST_Response && 201 === $z_rest->get_status() && 120 === $z_rest->get_data()['rate_limit'] && is_wp_error( $e = PassCaptain\Rest\Controller::create_developer_key( $z_req2 ) ) && 'wpcap_api_key_expiry' === $e->get_error_code() );

// Revoke.
$z_rev = DeveloperKeysPage::revoke( array( 'api_key_id' => $z_key_id ) );
$GLOBALS['wpcap_test_transients'] = array();
check( 'revoke: key refused afterwards, status revoked, audited, no further changes', true === $z_rev && false === DeveloperKeys::authorize( $z_request( $z_key, $z_event ), 'events.read', $z_event ) && 'revoked' === DeveloperKeys::status( $wpdb->get_row( "SELECT * FROM {$p}wpcap_api_keys WHERE api_key_id=$z_key_id" ) ) && $z_audit( 'developer_key_revoked', 'api_key', $z_key_id ) && is_wp_error( DeveloperKeysPage::update( array( 'api_key_id' => $z_key_id, 'expires_at' => '' ) ) ) );
$z_list = DeveloperKeys::all( 'revoked' );
check( 'status filter lists the revoked key', in_array( $z_key_id, array_map( 'intval', array_column( $z_list, 'api_key_id' ) ), true ) && ! in_array( $z_key_id, array_map( 'intval', array_column( DeveloperKeys::all( 'active' ), 'api_key_id' ) ), true ) );

$z_docs = (string) file_get_contents( WPCAP_PATH . 'docs/SECURITY.md' );
$z_doc_ok = false !== strpos( (string) file_get_contents( WPCAP_PATH . 'docs/HOOKS.md' ), '`wpcap_developer_key_rate_limit`' );
foreach ( DeveloperKeys::ROUTES as $z_route => $z_scope ) { $z_doc_ok = $z_doc_ok && false !== strpos( $z_docs, '`' . $z_route . '` | `' . $z_scope . '`' ); }
check( 'documented: route/scope table in SECURITY.md, filter in HOOKS.md', $z_doc_ok );

echo "A157: admin page for service resources and slots\n";
$GLOBALS['wpcap_test_transients'] = array();
$_SERVER['REMOTE_ADDR'] = '203.0.113.157';
$z_res = ServiceSlotsPage::save_resource( array( 'event_id' => $z_event, 'name' => 'Werkbank', 'capacity' => '4', 'buffer_before' => '5', 'buffer_after' => '10' ) );
$z_res_row = ServiceBookings::resource( $z_res );
check( 'create resource (name, capacity, buffers) and audit', is_int( $z_res ) && 'Werkbank' === $z_res_row->name && 4 === (int) $z_res_row->capacity && 5 === (int) $z_res_row->buffer_before && 10 === (int) $z_res_row->buffer_after && 'active' === $z_res_row->status && $z_audit( 'service_resource_created', 'resource', $z_res ) );
check( 'resource without name rejected', is_wp_error( ServiceSlotsPage::save_resource( array( 'event_id' => $z_event, 'name' => '' ) ) ) );

$z_day = ( new DateTimeImmutable( '+5 days', new DateTimeZone( 'Europe/Vienna' ) ) )->format( 'Y-m-d' );
$z_slot = ServiceSlotsPage::save_slot( array( 'resource_id' => $z_res, 'starts_at' => $z_day . 'T10:00', 'ends_at' => $z_day . 'T11:00', 'capacity' => '' ) );
$z_slot_row = ServiceBookings::slot( $z_slot );
$z_utc10 = ( new DateTimeImmutable( $z_day . ' 10:00', new DateTimeZone( 'Europe/Vienna' ) ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
check( 'create slot: local event time stored as UTC, capacity from resource, audited', is_int( $z_slot ) && $z_utc10 === $z_slot_row->starts_at && 4 === (int) $z_slot_row->capacity && 'open' === $z_slot_row->status && $z_audit( 'service_slot_created', 'service_slot', $z_slot ) );
check( 'slot with end before start rejected', is_wp_error( ServiceSlotsPage::save_slot( array( 'resource_id' => $z_res, 'starts_at' => $z_day . 'T12:00', 'ends_at' => $z_day . 'T11:00' ) ) ) );
$z_empty_slot = ServiceSlotsPage::save_slot( array( 'resource_id' => $z_res, 'starts_at' => $z_day . 'T14:00', 'ends_at' => $z_day . 'T15:00', 'capacity' => '2' ) );

// A booking of 3 places.
$z_book = ServiceBookings::reserve( $z_slot, 'kundin157@example.com', 3, 0, 10, 'a157-' . uniqid() );
check( 'setup: booking of 3 places', is_array( $z_book ) && 3 === ServiceBookings::booked( $z_slot ) );
$z_low = ServiceSlotsPage::save_slot( array( 'slot_id' => $z_slot, 'starts_at' => $z_day . 'T10:00', 'ends_at' => $z_day . 'T11:00', 'capacity' => '2' ) );
check( 'capacity cannot drop below booked places', is_wp_error( $z_low ) && 'wpcap_service_slot_capacity' === $z_low->get_error_code() && 4 === (int) ServiceBookings::slot( $z_slot )->capacity );
$z_move = ServiceSlotsPage::save_slot( array( 'slot_id' => $z_slot, 'starts_at' => $z_day . 'T12:00', 'ends_at' => $z_day . 'T13:00', 'capacity' => '4' ) );
check( 'time of a booked slot cannot change', is_wp_error( $z_move ) && 'wpcap_service_slot_booked' === $z_move->get_error_code() );
$z_up = ServiceSlotsPage::save_slot( array( 'slot_id' => $z_slot, 'starts_at' => $z_day . 'T10:00', 'ends_at' => $z_day . 'T11:00', 'capacity' => '8', 'status' => 'closed' ) );
check( 'capacity raised and slot closed (audited); closed slot no longer bookable', true === $z_up && 8 === (int) ServiceBookings::slot( $z_slot )->capacity && 'closed' === ServiceBookings::slot( $z_slot )->status && $z_audit( 'service_slot_updated', 'service_slot', $z_slot ) && is_wp_error( ServiceBookings::reserve( $z_slot, 'zwei157@example.com', 1, 0, 10, 'a157b-' . uniqid() ) ) );
$z_mv = ServiceSlotsPage::save_slot( array( 'slot_id' => $z_empty_slot, 'starts_at' => $z_day . 'T16:00', 'ends_at' => $z_day . 'T17:30', 'capacity' => '3' ) );
check( 'time and capacity of an unbooked slot can change', true === $z_mv && ( new DateTimeImmutable( $z_day . ' 17:30', new DateTimeZone( 'Europe/Vienna' ) ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) === ServiceBookings::slot( $z_empty_slot )->ends_at && 3 === (int) ServiceBookings::slot( $z_empty_slot )->capacity );

check( 'slot with bookings cannot be deleted', is_wp_error( $e = ServiceBookings::delete_slot( $z_slot ) ) && 'wpcap_service_slot_has_bookings' === $e->get_error_code() && ServiceBookings::slot( $z_slot ) );
check( 'resource with bookings cannot be deleted', is_wp_error( $e = ServiceBookings::delete_resource( $z_res ) ) && 'wpcap_resource_has_bookings' === $e->get_error_code() );
check( 'unbooked slot deleted and audited', true === ServiceBookings::delete_slot( $z_empty_slot ) && null === ServiceBookings::slot( $z_empty_slot ) && $z_audit( 'service_slot_deleted', 'service_slot', $z_empty_slot ) );
$z_res2 = ServiceBookings::create_resource( $z_event, array( 'name' => 'Leerer Raum', 'capacity' => 2 ) );
$z_res2_slot = ServiceBookings::create_slot( $z_res2, array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 9 * DAY_IN_SECONDS ), 'ends_at' => gmdate( 'Y-m-d H:i:s', time() + 9 * DAY_IN_SECONDS + 3600 ) ) );
check( 'resource without bookings deleted together with its slots', true === ServiceBookings::delete_resource( $z_res2 ) && null === ServiceBookings::resource( $z_res2 ) && null === ServiceBookings::slot( $z_res2_slot ) && $z_audit( 'service_resource_deleted', 'resource', $z_res2 ) );
$z_deact = ServiceSlotsPage::save_resource( array( 'resource_id' => $z_res, 'event_id' => $z_event, 'name' => 'Werkbank A', 'capacity' => '4', 'status' => 'inactive' ) );
check( 'resource edited and deactivated (audited); no new slots for inactive resources', true === $z_deact && 'inactive' === ServiceBookings::resource( $z_res )->status && 'Werkbank A' === ServiceBookings::resource( $z_res )->name && $z_audit( 'service_resource_updated', 'resource', $z_res ) && is_wp_error( ServiceBookings::create_slot( $z_res, array( 'starts_at' => $z_day . ' 18:00:00', 'ends_at' => $z_day . ' 19:00:00' ) ) ) );
ServiceSlotsPage::save_resource( array( 'resource_id' => $z_res, 'event_id' => $z_event, 'name' => 'Werkbank A', 'capacity' => '4', 'status' => 'active' ) );

// Page: resources, slots with booked/capacity, bookings list (PII view audited).
$z_page = $z_render( array( ServiceSlotsPage::class, 'render' ), array( 'event_id' => (string) $z_event ) );
check( 'page lists resource, slot (booked/capacity), booking and forms', false !== strpos( $z_page, 'Werkbank A' ) && false !== strpos( $z_page, '3 / 8' ) && false !== strpos( $z_page, 'kundin157@example.com' ) && false !== strpos( $z_page, 'wpcap_service_slot_save' ) && false !== strpos( $z_page, 'wpcap_service_resource_save' ) && false !== strpos( $z_page, 'Geschlossen' ) && false === strpos( $z_page, 'EXCEPTION' ) );
check( 'booking list view audited as pii_list_viewed', (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='pii_list_viewed' AND object_type='event' AND object_id=%d AND details LIKE %s", $z_event, '%service_bookings%' ) ) > 0 );
$z_filtered = ServiceBookings::bookings( $z_event, $z_slot );
check( 'bookings filtered by slot', 1 === count( $z_filtered ) && 3 === (int) $z_filtered[0]->quantity && 'held' === ServiceBookings::booking_status( $z_filtered[0] ) );

// Developer route slots.read: no customer data.
$GLOBALS['wpcap_test_transients'] = array();
$z_dev = DeveloperKeys::issue( $z_event, array( 'slots.read' ) );
$z_dev_req = $z_request( $z_dev['key'], $z_event );
$z_dev_resp = PassCaptain\Rest\Controller::developer_service_slots( $z_dev_req );
$z_dev_json = wp_json_encode( $z_dev_resp->get_data() );
check( 'GET /developer/events/{id}/service-slots: slots.read key allowed, events.read route refused, no e-mail in the payload', PassCaptain\Rest\Controller::developer_slots_permission( $z_dev_req ) && ! PassCaptain\Rest\Controller::developer_key_permission( $z_request( $z_dev['key'], $z_event ) ) && false !== strpos( $z_dev_json, '"booked":3' ) && false === strpos( $z_dev_json, 'kundin157' ) );

// REST routes use the same logic (validation, audit).
$z_rr = new WP_REST_Request( 'POST' );
$z_rr->set_param( 'event_id', $z_event );
$z_rr->set_body( wp_json_encode( array( 'name' => 'REST-Raum', 'capacity' => 3 ) ) );
$z_rest_res = PassCaptain\Rest\Controller::create_resource( $z_rr );
$z_rs = new WP_REST_Request( 'POST' );
$z_rs->set_param( 'resource_id', $z_rest_res instanceof WP_REST_Response ? $z_rest_res->get_data()['resource_id'] : 0 );
$z_rs->set_body( wp_json_encode( array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS ), 'ends_at' => gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS + 1800 ) ) ) );
$z_rest_slot = PassCaptain\Rest\Controller::create_service_slot( $z_rs );
check( 'REST create resource/slot still 201 and audited', $z_rest_res instanceof WP_REST_Response && 201 === $z_rest_res->get_status() && $z_rest_slot instanceof WP_REST_Response && 201 === $z_rest_slot->get_status() && 3 === (int) ServiceBookings::slot( $z_rest_slot->get_data()['slot_id'] )->capacity && $z_audit( 'service_slot_created', 'service_slot', $z_rest_slot->get_data()['slot_id'] ) );

// Staff without event management rights: nothing (user_can() is always true in this harness, so the
// missing right is simulated through current_user_can()).
$GLOBALS['wpcap_test_user_id'] = 4157;
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options', 'wpcap_manage_events' );
$wpdb->query( "DELETE FROM {$p}wpcap_event_staff WHERE user_id=4157" );
$z_f1 = ServiceSlotsPage::save_resource( array( 'event_id' => $z_event, 'name' => 'Fremd' ) );
$z_f2 = ServiceSlotsPage::save_slot( array( 'slot_id' => $z_slot, 'starts_at' => $z_day . 'T10:00', 'ends_at' => $z_day . 'T11:00', 'capacity' => '9' ) );
$z_f3 = ServiceBookings::delete_slot( $z_slot );
$z_f4 = ServiceBookings::update_resource( $z_res, array( 'name' => 'X' ) );
$z_fpage = $z_render( array( ServiceSlotsPage::class, 'render' ), array( 'event_id' => (string) $z_event ) );
check( 'staff without wpcap_manage_events: create/edit/delete forbidden, page shows no data', is_wp_error( $z_f1 ) && is_wp_error( $z_f2 ) && 403 === $z_f2->get_error_data()['status'] && is_wp_error( $z_f3 ) && is_wp_error( $z_f4 ) && 8 === (int) ServiceBookings::slot( $z_slot )->capacity && false === strpos( $z_fpage, 'kundin157' ) && false === strpos( $z_fpage, 'Werkbank' ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$wpdb->insert( "{$p}wpcap_event_staff", array( 'event_id' => $z_event, 'user_id' => 4157, 'capability' => 'wpcap_manage_events' ) );
$z_ok = ServiceSlotsPage::save_slot( array( 'slot_id' => $z_slot, 'starts_at' => $z_day . 'T10:00', 'ends_at' => $z_day . 'T11:00', 'capacity' => '9' ) );
$GLOBALS['wpcap_test_filters']['wpcap_access_scope'] = array( 'locations' => array( 'Graz' ), 'partners' => null );
$z_scoped = ServiceSlotsPage::save_slot( array( 'slot_id' => $z_slot, 'starts_at' => $z_day . 'T10:00', 'ends_at' => $z_day . 'T11:00', 'capacity' => '10' ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_access_scope'] );
check( 'assigned staff may edit; outside their location scope they may not', true === $z_ok && is_wp_error( $z_scoped ) && 9 === (int) ServiceBookings::slot( $z_slot )->capacity );
$wpdb->query( "DELETE FROM {$p}wpcap_event_staff WHERE user_id=4157" );

$GLOBALS['wpcap_test_user_id'] = $z_saved_user;
if ( null === $z_saved_user ) { unset( $GLOBALS['wpcap_test_user_id'] ); }
$GLOBALS['wpcap_test_denied_caps'] = $z_saved_denied;
$GLOBALS['wpcap_test_filters'] = $z_saved_filters;
$GLOBALS['wpcap_test_transients'] = array();

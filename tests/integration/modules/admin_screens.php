<?php
/**
 * Integration tests for the admin screens of formerly REST-only features (src/Admin/FeatureScreens.php):
 * developer API keys (one-time secret, per-key rate limit, revoke), service resources and slots,
 * waitlists (offer, remove, CSV export), campaigns and member passes. Covers capability checks,
 * nonce checks of the admin-post handlers, audit records and escaped/labelled render output.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\ApiKeysPage;
use PassCaptain\Admin\CampaignsPage;
use PassCaptain\Admin\FeatureScreens;
use PassCaptain\Admin\MembershipsPage;
use PassCaptain\Admin\Menu;
use PassCaptain\Admin\ServicesPage;
use PassCaptain\Admin\WaitlistsPage;
use PassCaptain\DeveloperKeys;
use PassCaptain\ServiceResources;
use PassCaptain\Waitlists;

if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'checked' ) ) { function checked( $a, $b = true, $echo = true ) { $r = $a == $b ? ' checked="checked"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'selected' ) ) { function selected( $a, $b = true, $echo = true ) { $r = $a == $b ? ' selected="selected"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'disabled' ) ) { function disabled( $a, $b = true, $echo = true ) { $r = $a == $b ? ' disabled="disabled"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce" value="n">'; } }
if ( ! function_exists( 'wp_nonce_url' ) ) { function wp_nonce_url( $u, $a = -1 ) { return $u . '&_wpnonce=n'; } }
if ( ! function_exists( 'submit_button' ) ) { function submit_button( $t = '', $type = 'primary', $name = 'submit', $wrap = true ) { echo '<button>' . esc_html( $t ) . '</button>'; } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = null ) { return esc_attr( $t ); } }

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_transients'] = array();
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 1;
$_SERVER['REMOTE_ADDR'] = '198.51.100.23';
$as_tz_before = $GLOBALS['wpcap_test_options']['timezone_string'] ?? null;
$GLOBALS['wpcap_test_options']['timezone_string'] = 'Europe/Berlin';

function as_render( $cb, array $get = array() ) {
	$_GET = $get;
	ob_start();
	try { call_user_func( $cb ); } catch ( Throwable $e ) { ob_end_clean(); $_GET = array(); return 'DIED:' . $e->getMessage(); }
	$_GET = array();
	return (string) ob_get_clean();
}
/** Runs an admin-post handler; returns ['redirect' => url] or ['died' => message]. */
function as_post( $handler, array $post ) {
	$_POST = $post;
	$out = array();
	try { call_user_func( $handler ); } catch ( Wpcap_Test_Redirect $e ) { $out['redirect'] = $e->getMessage(); } catch ( Throwable $e ) { $out['died'] = $e->getMessage(); }
	$_POST = array();
	return $out;
}
function as_audit( $action ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_audit_logs WHERE action=%s ORDER BY audit_id DESC LIMIT 1", $action ) );
}
function as_forbidden( $r ) { return is_wp_error( $r ) && 403 === (int) ( $r->get_error_data()['status'] ?? 0 ); }
function as_event_of( $type ) {
	global $wpdb;
	return (int) $wpdb->get_var( "SELECT o.event_id FROM {$wpdb->prefix}wpcap_ticket_types tt INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=" . (int) $type );
}
function as_key_request( $key ) {
	$req = new WP_REST_Request( 'GET' );
	$req->set_header( 'X-WPCAP-Key', $key );
	return $req;
}

echo "Admin screens: registration and schema\n";
$handlers = Menu::handlers();
check( 'all feature admin-post handlers are registered', isset( $handlers['wpcap_api_key_action'], $handlers['wpcap_service_action'], $handlers['wpcap_waitlist_action'], $handlers['wpcap_waitlist_export'], $handlers['wpcap_campaign_action'], $handlers['wpcap_membership_action'] ) );
$pages = array();
foreach ( FeatureScreens::pages() as $page ) { $pages[ $page[0] ] = $page[2]; }
check( 'feature pages are registered with their capabilities', array( 'wpcap-campaigns' => 'wpcap_manage_events', 'wpcap-waitlists' => 'wpcap_manage_orders', 'wpcap-services' => 'wpcap_manage_events', 'wpcap-memberships' => 'wpcap_manage_orders', 'wpcap-api-keys' => 'wpcap_manage_events' ) === $pages );
check( 'schema: rate_limit on api keys (default 600) and timezone on resources, DB version 3.6.0 or newer', version_compare( PassCaptain\Installer::DB_VERSION, '3.6.0', '>=' ) && '600' === (string) ( $wpdb->get_row( "SHOW COLUMNS FROM {$p}wpcap_api_keys LIKE 'rate_limit'" )->Default ?? '' ) && null !== $wpdb->get_row( "SHOW COLUMNS FROM {$p}wpcap_resources LIKE 'timezone'" ) );

echo "Admin screens: capability checks\n";
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_events', 'wpcap_manage_orders' );
$denied = array(
	ApiKeysPage::admin_action( array( 'do' => 'create', 'event_id' => 1, 'scopes' => array( 'events.read' ) ) ),
	ServicesPage::admin_action( array( 'do' => 'save_resource', 'event_id' => 1, 'name' => 'X' ) ),
	WaitlistsPage::admin_action( array( 'do' => 'remove', 'id' => 1 ) ),
	WaitlistsPage::export( array( 'event_id' => 1 ) ),
	CampaignsPage::admin_action( array( 'do' => 'save', 'code' => 'NOPE10', 'value' => '10' ) ),
	MembershipsPage::admin_action( array( 'do' => 'revoke', 'id' => 1 ) ),
);
$died = array_filter( array( ApiKeysPage::class, ServicesPage::class, WaitlistsPage::class, CampaignsPage::class, MembershipsPage::class ), static fn( $c ) => str_starts_with( as_render( array( $c, 'render' ) ), 'DIED:' ) );
$denied_post = as_post( array( ApiKeysPage::class, 'handle_action' ), array( 'do' => 'create', 'event_id' => 1 ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'every action is refused (403) without the capability', 6 === count( array_filter( $denied, 'as_forbidden' ) ) );
check( 'every screen refuses to render without the capability', 5 === count( $died ) );
check( 'admin-post handler ends with wp_die for forbidden actions', isset( $denied_post['died'] ) && ! isset( $denied_post['redirect'] ) );

echo "Admin screens: developer API keys\n";
$type = make_type( 10 );
$event = as_event_of( $type );
$GLOBALS['wpcap_test_nonce_checks'] = array();
$r = as_post( array( ApiKeysPage::class, 'handle_action' ), array( 'do' => 'create', 'event_id' => $event, 'label' => 'Dashboard <b>', 'scopes' => array( 'events.read', 'reports.read', 'admin.write' ), 'rate_limit' => '3', 'expires_at' => gmdate( 'Y-m-d', time() + 30 * 86400 ) . 'T12:00' ) );
$key_row = $wpdb->get_row( "SELECT * FROM {$p}wpcap_api_keys ORDER BY api_key_id DESC LIMIT 1" );
$secret = $GLOBALS['wpcap_test_transients'][ ApiKeysPage::new_key_transient() ] ?? '';
check( 'create handler checks the nonce and redirects', in_array( 'wpcap_api_key_action', $GLOBALS['wpcap_test_nonce_checks'], true ) && isset( $r['redirect'] ) && str_contains( $r['redirect'], 'page=wpcap-api-keys' ) );
check( 'key stored hashed with scopes, label, per-key rate limit and UTC expiry', $key_row && 3 === (int) $key_row->rate_limit && array( 'events.read', 'reports.read' ) === json_decode( $key_row->scopes, true ) && 'Dashboard' === $key_row->label && hash( 'sha256', explode( '.', $secret )[1] ?? '' ) === $key_row->secret_hash && $key_row->public_prefix === explode( '.', $secret )[0] && gmdate( 'Y-m-d', time() + 30 * 86400 ) . ' 11:00:00' === $key_row->expires_at );
$first = as_render( array( ApiKeysPage::class, 'render' ) );
$second = as_render( array( ApiKeysPage::class, 'render' ) );
check( 'secret is shown exactly once, afterwards only the prefix', '' !== $secret && str_contains( $first, $secret ) && ! str_contains( $second, $secret ) && str_contains( $second, $key_row->public_prefix ) && ! str_contains( $second, $key_row->secret_hash ) );
check( 'key screen: labelled fields and escaped output', str_contains( $first, '<label for="wpcap-ak-label">' ) && str_contains( $first, '<label for="wpcap-ak-rate">' ) && str_contains( $first, '<label for="wpcap-new-api-key">' ) && str_contains( $first, '<legend class="screen-reader-text">' ) && ! str_contains( $first, '<b>' ) );
$audit = as_audit( 'developer_key_issued' );
check( 'key creation is audited with rate limit, without the secret', $audit && (int) $audit->object_id === (int) $key_row->api_key_id && 3 === json_decode( $audit->details, true )['rate_limit'] && ! str_contains( $audit->details, explode( '.', $secret )[1] ?? 'x' ) );
$GLOBALS['wpcap_test_transients'] = array();
$ok = 0;
for ( $i = 0; $i < 4; $i++ ) { $ok += false !== DeveloperKeys::authorize( as_key_request( $secret ), 'events.read', $event ) ? 1 : 0; }
check( "per-key rate limit is enforced (3 of 4 requests allowed, got $ok)", 3 === $ok );
$set = ApiKeysPage::admin_action( array( 'do' => 'rate_limit', 'id' => $key_row->api_key_id, 'rate_limit' => '5' ) );
$after = 0;
for ( $i = 0; $i < 3; $i++ ) { $after += false !== DeveloperKeys::authorize( as_key_request( $secret ), 'events.read', $event ) ? 1 : 0; }
check( 'changed rate limit applies immediately and is audited', 5 === $set && 2 === $after && 5 === (int) $wpdb->get_var( "SELECT rate_limit FROM {$p}wpcap_api_keys WHERE api_key_id=" . (int) $key_row->api_key_id ) && as_audit( 'developer_key_rate_limit' ) );
$bad_rate = ApiKeysPage::admin_action( array( 'do' => 'rate_limit', 'id' => $key_row->api_key_id, 'rate_limit' => '0' ) );
$past = ApiKeysPage::admin_action( array( 'do' => 'create', 'event_id' => $event, 'scopes' => array( 'events.read' ), 'expires_at' => '2020-01-01T10:00' ) );
$no_scope = ApiKeysPage::admin_action( array( 'do' => 'create', 'event_id' => $event, 'scopes' => array( 'admin.write' ) ) );
check( 'invalid rate limit, past expiry and missing scopes are rejected', is_wp_error( $bad_rate ) && is_wp_error( $past ) && 'wpcap_api_key_expiry' === $past->get_error_code() && is_wp_error( $no_scope ) );
$default = DeveloperKeys::issue( $event, array( 'reports.read' ) );
check( 'keys created without a limit (REST) get the default of 600', 600 === (int) $wpdb->get_var( "SELECT rate_limit FROM {$p}wpcap_api_keys WHERE api_key_id=" . (int) $default['api_key_id'] ) && 600 === $default['rate_limit'] );
$GLOBALS['wpcap_test_user_id'] = 9;
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$foreign = ApiKeysPage::admin_action( array( 'do' => 'revoke', 'id' => $key_row->api_key_id ) );
$foreign_list = as_render( array( ApiKeysPage::class, 'render' ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 1;
check( 'other users cannot revoke a key or see it in their list', is_wp_error( $foreign ) && null === $wpdb->get_var( "SELECT revoked_at FROM {$p}wpcap_api_keys WHERE api_key_id=" . (int) $key_row->api_key_id ) && ! str_contains( $foreign_list, $key_row->public_prefix ) );
$GLOBALS['wpcap_test_nonce_checks'] = array();
$rv = as_post( array( ApiKeysPage::class, 'handle_action' ), array( 'do' => 'revoke', 'id' => $key_row->api_key_id ) );
$GLOBALS['wpcap_test_transients'] = array();
check( 'revoke handler (nonce checked) revokes the key; it no longer authorizes; audited', in_array( 'wpcap_api_key_action', $GLOBALS['wpcap_test_nonce_checks'], true ) && isset( $rv['redirect'] ) && null !== $wpdb->get_var( "SELECT revoked_at FROM {$p}wpcap_api_keys WHERE api_key_id=" . (int) $key_row->api_key_id ) && false === DeveloperKeys::authorize( as_key_request( $secret ), 'events.read', $event ) && (int) as_audit( 'developer_key_revoked' )->object_id === (int) $key_row->api_key_id );
$list = as_render( array( ApiKeysPage::class, 'render' ) );
check( 'list shows status of revoked keys without revoke button for them', str_contains( $list, 'Widerrufen' ) && str_contains( $list, $key_row->public_prefix ) );

echo "Admin screens: service resources and slots\n";
$sv_type = make_type( 5 );
$sv_event = as_event_of( $sv_type );
$bad_tz = ServicesPage::admin_action( array( 'do' => 'save_resource', 'event_id' => $sv_event, 'name' => 'Raum', 'timezone' => 'Mars/Base' ) );
$res = ServicesPage::admin_action( array( 'do' => 'save_resource', 'event_id' => $sv_event, 'name' => 'Raum <script>x</script>', 'capacity' => '4', 'timezone' => 'Europe/Vienna' ) );
$resource_id = (int) ( $res['resource_id'] ?? 0 );
$resource = ServiceResources::resource( $resource_id );
check( 'resource is created with capacity and timezone; invalid timezone rejected; audited', is_wp_error( $bad_tz ) && $resource && 4 === (int) $resource->capacity && 'Europe/Vienna' === $resource->timezone && 'Raum x' === $resource->name && as_audit( 'service_resource_created' ) );
$day = gmdate( 'Y-m-d', strtotime( 'next monday', time() + 14 * 86400 ) );
$slot = ServicesPage::admin_action( array( 'do' => 'create_slot', 'resource_id' => $resource_id, 'starts_at' => $day . 'T10:00', 'ends_at' => $day . 'T11:00', 'capacity' => '2' ) );
$slot_row = ServiceResources::slot( $slot['slot_id'] ?? 0 );
$expected_start = PassCaptain\Admin\Forms::local_to_utc( $day . 'T10:00', 'Europe/Vienna' );
check( 'single slot is stored in UTC from the resource timezone; audited', $slot_row && $expected_start === $slot_row->starts_at && 2 === (int) $slot_row->capacity && 'open' === $slot_row->status && as_audit( 'service_slot_created' ) );
$bad_slot = ServicesPage::admin_action( array( 'do' => 'create_slot', 'resource_id' => $resource_id, 'starts_at' => $day . 'T11:00', 'ends_at' => $day . 'T10:00' ) );
check( 'slot with end before start is rejected', is_wp_error( $bad_slot ) );
$gen_args = array( 'do' => 'generate_slots', 'resource_id' => $resource_id, 'start_date' => $day, 'until_date' => gmdate( 'Y-m-d', strtotime( $day . ' +13 days' ) ), 'weekdays' => array( '1', '3' ), 'time' => '14:30', 'duration' => '45', 'capacity' => '3' );
$gen = ServicesPage::admin_action( $gen_args );
$gen_again = ServicesPage::admin_action( $gen_args );
$gen_rows = $wpdb->get_results( "SELECT * FROM {$p}wpcap_service_slots WHERE resource_id=$resource_id AND capacity=3 ORDER BY starts_at" );
check( 'weekly generator creates Mon+Wed slots for two weeks with duration; re-run skips them', 4 === count( $gen['created'] ?? array() ) && 0 === count( $gen_again['created'] ?? array( 1 ) ) && 4 === (int) $gen_again['skipped'] && 4 === count( $gen_rows ) && 45 * 60 === strtotime( $gen_rows[0]->ends_at ) - strtotime( $gen_rows[0]->starts_at ) && PassCaptain\Admin\Forms::local_to_utc( $day . ' 14:30', 'Europe/Vienna' ) === $gen_rows[0]->starts_at && as_audit( 'service_slots_generated' ) );
$bad_gen = ServicesPage::admin_action( array( 'do' => 'generate_slots', 'resource_id' => $resource_id, 'start_date' => $day, 'until_date' => $day, 'weekdays' => array(), 'time' => '10:00', 'duration' => '30' ) );
check( 'generator without weekday is rejected', is_wp_error( $bad_gen ) );
$wpdb->insert( "{$p}wpcap_service_bookings", array( 'slot_id' => $slot['slot_id'], 'order_id' => 0, 'customer_email' => 'guest<x>@example.com', 'idempotency_key' => hash( 'sha256', 'as-' . uniqid() ), 'quantity' => 2, 'expires_at' => null, 'status' => 'confirmed', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$shrink = ServicesPage::admin_action( array( 'do' => 'update_slot', 'id' => $slot['slot_id'], 'starts_at' => $day . 'T10:00', 'ends_at' => $day . 'T11:00', 'capacity' => '1', 'status' => 'open' ) );
$close = ServicesPage::admin_action( array( 'do' => 'update_slot', 'id' => $slot['slot_id'], 'starts_at' => $day . 'T10:15', 'ends_at' => $day . 'T11:15', 'capacity' => '3', 'status' => 'closed' ) );
$closed_row = ServiceResources::slot( $slot['slot_id'] );
check( 'slot capacity cannot drop below booked places; edit updates times/status; audited', is_wp_error( $shrink ) && 'wpcap_service_slot_capacity' === $shrink->get_error_code() && ! is_wp_error( $close ) && 'closed' === $closed_row->status && 3 === (int) $closed_row->capacity && PassCaptain\Admin\Forms::local_to_utc( $day . 'T10:15', 'Europe/Vienna' ) === $closed_row->starts_at && as_audit( 'service_slot_updated' ) );
$del_booked = ServicesPage::admin_action( array( 'do' => 'delete_slot', 'id' => $slot['slot_id'] ) );
$GLOBALS['wpcap_test_nonce_checks'] = array();
$del = as_post( array( ServicesPage::class, 'handle_action' ), array( 'do' => 'delete_slot', 'id' => $gen_rows[0]->slot_id ) );
check( 'booked slot cannot be deleted; free slot deleted via handler (nonce checked, audited)', is_wp_error( $del_booked ) && in_array( 'wpcap_service_action', $GLOBALS['wpcap_test_nonce_checks'], true ) && isset( $del['redirect'] ) && str_contains( $del['redirect'], 'resource_id=' . $resource_id ) && null === ServiceResources::slot( $gen_rows[0]->slot_id ) && (int) as_audit( 'service_slot_deleted' )->object_id === (int) $gen_rows[0]->slot_id );
$upd = ServicesPage::admin_action( array( 'do' => 'save_resource', 'id' => $resource_id, 'name' => 'Raum 1', 'capacity' => '6', 'timezone' => '', 'status' => 'inactive' ) );
$resource = ServiceResources::resource( $resource_id );
check( 'resource edit changes name/capacity/status and falls back to the event timezone', ! is_wp_error( $upd ) && 'Raum 1' === $resource->name && 'inactive' === $resource->status && 'Europe/Vienna' === ServiceResources::timezone_of( $resource ) && as_audit( 'service_resource_updated' ) );
$wpdb->update( "{$p}wpcap_resources", array( 'name' => 'Raum <script>x</script>' ), array( 'resource_id' => $resource_id ) );
$overview = as_render( array( ServicesPage::class, 'render' ) );
$detail = as_render( array( ServicesPage::class, 'render' ), array( 'resource_id' => (string) $resource_id ) );
$slot_view = as_render( array( ServicesPage::class, 'render' ), array( 'slot_id' => (string) $slot['slot_id'] ) );
check( 'service screens: escaped names, labelled forms, generator weekdays as fieldset', str_contains( $overview, 'Raum &lt;script&gt;' ) && ! str_contains( $overview . $detail . $slot_view, '<script>' ) && str_contains( $detail, '<label for="wpcap-slot-start">' ) && str_contains( $detail, '<label for="wpcap-gen-wd-1"' ) && str_contains( $detail, '<legend class="screen-reader-text">Wochentage</legend>' ) && str_contains( $detail, '2 / 3' ) );
check( 'slot view lists bookings (escaped) and the edit form', str_contains( $slot_view, 'guest&lt;x&gt;@example.com' ) && str_contains( $slot_view, 'Bestätigt' ) && str_contains( $slot_view, '<label for="wpcap-slot-capacity">' ) );

echo "Admin screens: waitlists\n";
$wl_type = make_type( 1 );
$wl_event = as_event_of( $wl_type );
$wl_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$wl_type" );
$GLOBALS['wpcap_test_transients'] = array();
foreach ( array( 'first@example.com', 'second@example.com', 'third@example.com' ) as $mail ) { Waitlists::join( $wl_event, $wl_occ, $wl_type, $mail ); }
$entries = Waitlists::entries( $wl_event );
check( 'entries are listed in queue order', 3 === count( $entries ) && array( 1, 2, 3 ) === array_map( static fn( $e ) => (int) $e->position, $entries ) && 'first@example.com' === $entries[0]->email );
$other_type = make_type( 1 );
$mismatch = WaitlistsPage::admin_action( array( 'do' => 'offer', 'event_id' => $wl_event, 'ticket_type_id' => $other_type ) );
check( 'offer for a ticket type of another event is refused', as_forbidden( $mismatch ) );
$mails_before = count( $GLOBALS['wpcap_test_mails'] );
$GLOBALS['wpcap_test_nonce_checks'] = array();
$of = as_post( array( WaitlistsPage::class, 'handle_action' ), array( 'do' => 'offer', 'event_id' => $wl_event, 'ticket_type_id' => $wl_type ) );
$offered = $wpdb->get_row( "SELECT * FROM {$p}wpcap_waitlist WHERE event_id=$wl_event AND position=1" );
$offer_mail = array_slice( $GLOBALS['wpcap_test_mails'], $mails_before );
check( 'offer handler (nonce checked) sends the offer to position 1 and audits it', in_array( 'wpcap_waitlist_action', $GLOBALS['wpcap_test_nonce_checks'], true ) && isset( $of['redirect'] ) && 'offered' === $offered->status && 1 === count( $offer_mail ) && 'first@example.com' === $offer_mail[0]['to'] && (int) as_audit( 'waitlist_offer_sent' )->object_id === (int) $offered->waitlist_id );
$second = $wpdb->get_row( "SELECT * FROM {$p}wpcap_waitlist WHERE event_id=$wl_event AND position=2" );
$rm = WaitlistsPage::admin_action( array( 'do' => 'remove', 'id' => $second->waitlist_id ) );
$audit_rm = as_audit( 'waitlist_entry_removed' );
check( 'remove deletes the entry; audit without e-mail address', ! is_wp_error( $rm ) && null === $wpdb->get_row( "SELECT * FROM {$p}wpcap_waitlist WHERE waitlist_id=" . (int) $second->waitlist_id ) && (int) $audit_rm->object_id === (int) $second->waitlist_id && ! str_contains( $audit_rm->details, '@' ) );
check( 'removing an unknown entry fails', is_wp_error( WaitlistsPage::admin_action( array( 'do' => 'remove', 'id' => 99999999 ) ) ) );
$csv = WaitlistsPage::export( array( 'event_id' => $wl_event ) );
$audit_csv = as_audit( 'waitlist_exported' );
check( 'CSV export lists entries with header and is audited', is_string( $csv ) && str_contains( $csv, 'Position;E-Mail' ) && str_contains( $csv, 'first@example.com' ) && str_contains( $csv, 'third@example.com' ) && ! str_contains( $csv, 'second@example.com' ) && (int) $audit_csv->object_id === $wl_event && 2 === json_decode( $audit_csv->details, true )['rows'] );
$wl_html = as_render( array( WaitlistsPage::class, 'render' ), array( 'event_id' => (string) $wl_event ) );
check( 'waitlist screen: labelled filters, offer button, export link with nonce', str_contains( $wl_html, '<label for="wpcap-wl-event">' ) && str_contains( $wl_html, 'Angebot an nächste Person senden' ) && str_contains( $wl_html, 'action=wpcap_waitlist_export' ) && str_contains( $wl_html, '_wpnonce=n' ) && str_contains( $wl_html, 'Angebot gesendet' ) );

echo "Admin screens: campaigns\n";
$cp_type = make_type( 10 );
$cp_event = as_event_of( $cp_type );
$GLOBALS['wpcap_test_nonce_checks'] = array();
$cp = as_post( array( CampaignsPage::class, 'handle_action' ), array( 'do' => 'save', 'code' => 'sommer-10', 'name' => 'Sommer <i>', 'kind' => 'percent', 'value' => '10', 'currency' => 'EUR', 'event_id' => $cp_event, 'ticket_type_id' => $cp_type, 'usage_limit' => '5', 'priority' => '50', 'starts_at' => '2026-06-01T00:00', 'ends_at' => '2030-08-31T23:59' ) );
$campaign = $wpdb->get_row( "SELECT * FROM {$p}wpcap_campaigns WHERE code='SOMMER-10'" );
check( 'create handler (nonce checked) stores code, percent in basis points, scope, limit, UTC window', in_array( 'wpcap_campaign_action', $GLOBALS['wpcap_test_nonce_checks'], true ) && isset( $cp['redirect'] ) && $campaign && 1000 === (int) $campaign->discount_value && 'percent' === $campaign->kind && $cp_type === (int) $campaign->ticket_type_id && 5 === (int) $campaign->usage_limit && '2026-05-31 22:00:00' === $campaign->starts_at && 'Sommer' === $campaign->name && as_audit( 'campaign_created' ) );
$fixed = CampaignsPage::admin_action( array( 'do' => 'save', 'code' => 'FIX5', 'kind' => 'fixed', 'value' => '5,50', 'currency' => 'EUR', 'event_id' => $cp_event ) );
check( 'fixed amounts are parsed to minor units', ! is_wp_error( $fixed ) && 550 === (int) $wpdb->get_var( "SELECT discount_value FROM {$p}wpcap_campaigns WHERE code='FIX5'" ) );
$wrong_scope = CampaignsPage::admin_action( array( 'do' => 'save', 'code' => 'WRONG1', 'kind' => 'percent', 'value' => '5', 'event_id' => $cp_event, 'ticket_type_id' => $wl_type ) );
$bad_window = CampaignsPage::admin_action( array( 'do' => 'save', 'code' => 'WIN1', 'kind' => 'percent', 'value' => '5', 'event_id' => $cp_event, 'starts_at' => '2026-06-02T00:00', 'ends_at' => '2026-06-01T00:00' ) );
$too_much = CampaignsPage::admin_action( array( 'do' => 'save', 'code' => 'BIG1', 'kind' => 'percent', 'value' => '150', 'event_id' => $cp_event ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$global = CampaignsPage::admin_action( array( 'do' => 'save', 'code' => 'GLOBAL1', 'kind' => 'percent', 'value' => '5', 'event_id' => 0 ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'invalid scope, window and percent are rejected; global campaigns only for administrators', is_wp_error( $wrong_scope ) && is_wp_error( $bad_window ) && is_wp_error( $too_much ) && as_forbidden( $global ) );
$edit = CampaignsPage::admin_action( array( 'do' => 'save', 'id' => $campaign->campaign_id, 'code' => 'SOMMER-10', 'name' => 'Sommer neu', 'kind' => 'percent', 'value' => '15', 'currency' => 'EUR', 'event_id' => $cp_event, 'ticket_type_id' => 0, 'usage_limit' => '8', 'priority' => '20', 'status' => 'active' ) );
$campaign = PassCaptain\Campaigns::get( $campaign->campaign_id );
check( 'edit updates value, scope, limit, priority and clears the window; audited', ! is_wp_error( $edit ) && 1500 === (int) $campaign->discount_value && 0 === (int) $campaign->ticket_type_id && 8 === (int) $campaign->usage_limit && 20 === (int) $campaign->priority && null === $campaign->starts_at && as_audit( 'campaign_updated' ) );
$wpdb->update( "{$p}wpcap_campaigns", array( 'used_count' => 3 ), array( 'campaign_id' => $campaign->campaign_id ) );
$rename = CampaignsPage::admin_action( array( 'do' => 'save', 'id' => $campaign->campaign_id, 'code' => 'HERBST', 'kind' => 'percent', 'value' => '15', 'event_id' => $cp_event ) );
$low = CampaignsPage::admin_action( array( 'do' => 'save', 'id' => $campaign->campaign_id, 'code' => 'SOMMER-10', 'kind' => 'percent', 'value' => '15', 'event_id' => $cp_event, 'usage_limit' => '2' ) );
check( 'used codes keep code/kind; limit cannot drop below uses', is_wp_error( $rename ) && 'wpcap_campaign_used' === $rename->get_error_code() && is_wp_error( $low ) && 'wpcap_campaign_limit_low' === $low->get_error_code() );
$off = CampaignsPage::admin_action( array( 'do' => 'status', 'id' => $campaign->campaign_id, 'status' => 'inactive' ) );
check( 'deactivate sets the status and is audited', 'inactive' === PassCaptain\Campaigns::get( $campaign->campaign_id )->status && 'inactive' === $off['status'] && (int) as_audit( 'campaign_deactivated' )->object_id === (int) $campaign->campaign_id );
$cp_list = as_render( array( CampaignsPage::class, 'render' ) );
$cp_form = as_render( array( CampaignsPage::class, 'render' ), array( 'campaign_id' => (string) $campaign->campaign_id ) );
check( 'campaign screens: list with code/state/uses, form labelled and used code locked', str_contains( $cp_list, 'SOMMER-10' ) && str_contains( $cp_list, 'Deaktiviert' ) && str_contains( $cp_list, '3 / 8' ) && str_contains( $cp_list, 'Aktivieren' ) && str_contains( $cp_form, '<label for="wpcap-cp-code">' ) && str_contains( $cp_form, '<label for="wpcap-cp-value">' ) && str_contains( $cp_form, 'readonly' ) && str_contains( $cp_form, 'value="15"' ) && ! str_contains( $cp_list . $cp_form, '<i>' ) );

echo "Admin screens: member passes\n";
$mp_type = make_type( 10 );
$mp_event = as_event_of( $mp_type );
$mails_before = count( $GLOBALS['wpcap_test_mails'] );
$GLOBALS['wpcap_test_nonce_checks'] = array();
$is = as_post( array( MembershipsPage::class, 'handle_action' ), array( 'do' => 'issue', 'email' => 'member@example.com', 'valid_from' => '2026-01-01T00:00', 'valid_until' => '2030-12-31T23:59', 'event_id' => 0, 'ticket_type_id' => $mp_type, 'uses_total' => '10', 'uses_per_day' => '1' ) );
$pass = $wpdb->get_row( "SELECT * FROM {$p}wpcap_member_passes WHERE member_email='member@example.com' ORDER BY pass_id DESC LIMIT 1" );
$rules = $pass ? PassCaptain\Memberships::rules( $pass->pass_id ) : array();
check( 'issue handler (nonce checked) creates the pass with its rule (event from ticket type) and mails the code', in_array( 'wpcap_membership_action', $GLOBALS['wpcap_test_nonce_checks'], true ) && isset( $is['redirect'] ) && $pass && '2025-12-31 23:00:00' === $pass->valid_from && 1 === count( $rules ) && $mp_event === (int) $rules[0]->event_id && 10 === (int) $rules[0]->uses_total && count( $GLOBALS['wpcap_test_mails'] ) === $mails_before + 1 );
$bad_range = MembershipsPage::admin_action( array( 'do' => 'issue', 'email' => 'm2@example.com', 'valid_from' => '2026-02-01T00:00', 'valid_until' => '2026-01-01T00:00', 'ticket_type_id' => $mp_type ) );
check( 'issue with end before start is rejected', is_wp_error( $bad_range ) );
$upd = MembershipsPage::admin_action( array( 'do' => 'update', 'id' => $pass->pass_id, 'valid_from' => '2026-01-01T00:00', 'valid_until' => '2031-06-30T12:00' ) );
$rule_upd = MembershipsPage::admin_action( array( 'do' => 'save_rule', 'id' => $pass->pass_id, 'rule_id' => $rules[0]->rule_id, 'uses_total' => '20', 'uses_per_day' => '2' ) );
$rule_add = MembershipsPage::admin_action( array( 'do' => 'save_rule', 'id' => $pass->pass_id, 'event_id' => $mp_event, 'ticket_type_id' => 0, 'uses_total' => '0', 'uses_per_day' => '1' ) );
$rules = PassCaptain\Memberships::rules( $pass->pass_id );
check( 'validity, rule quotas and an added rule are saved and audited', ! is_wp_error( $upd ) && '2031-06-30 10:00:00' === PassCaptain\Memberships::get( $pass->pass_id )->valid_until && 20 === (int) $rules[0]->uses_total && 2 === (int) $rules[0]->uses_per_day && 2 === count( $rules ) && as_audit( 'member_pass_updated' ) && as_audit( 'member_pass_rule_updated' ) && as_audit( 'member_pass_rule_added' ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$global_rule = MembershipsPage::admin_action( array( 'do' => 'save_rule', 'id' => $pass->pass_id, 'event_id' => 0, 'ticket_type_id' => 0 ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'rules for all events need administrator rights', as_forbidden( $global_rule ) );
$mp_detail = as_render( array( MembershipsPage::class, 'render' ), array( 'pass_id' => (string) $pass->pass_id ) );
$rv = MembershipsPage::admin_action( array( 'do' => 'revoke', 'id' => $pass->pass_id ) );
$again = MembershipsPage::admin_action( array( 'do' => 'revoke', 'id' => $pass->pass_id ) );
check( 'revoke marks the pass revoked (audited); a second revoke is refused', ! is_wp_error( $rv ) && 'revoked' === PassCaptain\Memberships::get( $pass->pass_id )->status && (int) as_audit( 'member_pass_revoked' )->object_id === (int) $pass->pass_id && is_wp_error( $again ) );
$mp_list = as_render( array( MembershipsPage::class, 'render' ), array( 'q' => 'member@' ) );
check( 'pass screens: labelled forms, rule quota inputs with labels, list with status', str_contains( $mp_detail, '<label for="wpcap-mp-edit-from">' ) && str_contains( $mp_detail, 'for="wpcap-mp-r' . (int) $rules[0]->rule_id . '-total"' ) && str_contains( $mp_detail, 'Pass widerrufen' ) && str_contains( $mp_list, 'member@example.com' ) && str_contains( $mp_list, 'Widerrufen' ) && str_contains( $mp_list, '<label for="wpcap-mp-email">' ) );

if ( null === $as_tz_before ) { unset( $GLOBALS['wpcap_test_options']['timezone_string'] ); } else { $GLOBALS['wpcap_test_options']['timezone_string'] = $as_tz_before; }
$GLOBALS['wpcap_test_transients'] = array();
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 1;

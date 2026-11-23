<?php
/**
 * N02/N04: merged admin screens. One menu entry per topic – "Warteliste" (Waitlists\AdminPage with the
 * queue/export tab of Admin\WaitlistsPage), "Entwickler-API" (DeveloperKeysPage with the event-scoped
 * view of ApiKeysPage) and "Systemstatus" (StatusPage with the tab "Betrieb" of OperationsPage); the
 * former slugs wpcap-waitlists, wpcap-api-keys and wpcap-operations stay registered without menu entry
 * and redirect to the merged screens (query args kept). Waitlist double opt-in entries use `pending`
 * (a legacy `unconfirmed` value is shown and purged like `pending`).
 * Included from run.php (uses check(), make_type()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\ApiKeysPage;
use PassCaptain\Admin\DeveloperKeysPage;
use PassCaptain\Admin\Menu;
use PassCaptain\Admin\OperationsPage;
use PassCaptain\Admin\StatusPage;
use PassCaptain\Admin\WaitlistsPage;
use PassCaptain\Privacy\Retention;
use PassCaptain\Waitlists;
use PassCaptain\Waitlists\AdminPage as WaitlistAdmin;

global $wpdb;
$p = $wpdb->prefix;

require_once dirname( __DIR__ ) . '/lib/menu_stubs.php';
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); } }
if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $a = -1 ) { return 'n'; } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'wp_timezone_string' ) ) { function wp_timezone_string() { return 'Europe/Vienna'; } }

$n04_render = static function ( callable $cb, array $get = array() ) {
	$_GET = $get;
	ob_start();
	try { call_user_func( $cb ); } catch ( Throwable $e ) { ob_end_clean(); $_GET = array(); return 'DIED:' . $e->getMessage(); }
	$_GET = array();
	return html_entity_decode( (string) ob_get_clean() ); // esc_url() differs between stubs (&amp; / &#038;).
};

echo "N02/N04: one admin screen per topic\n";
$n04_slugs = array_count_values( array_map( 'strval', array_column( Menu::entries(), 0 ) ) );
$n04_titles = array_map( 'strval', array_column( Menu::entries(), 1 ) );
check( 'menu: "Warteliste", "Entwickler-API" and "Systemstatus" are listed exactly once', 1 === ( $n04_slugs[ WaitlistAdmin::PAGE ] ?? 0 ) && 1 === ( $n04_slugs[ DeveloperKeysPage::SLUG ] ?? 0 ) && 1 === ( $n04_slugs[ StatusPage::SLUG ] ?? 0 ) );
check( 'menu: no entry for the former screens (Wartelisten-Export, Entwickler-API-Keys, Betrieb)', ! isset( $n04_slugs[ WaitlistsPage::SLUG ] ) && ! isset( $n04_slugs[ ApiKeysPage::SLUG ] ) && ! isset( $n04_slugs[ OperationsPage::SLUG ] ) && ! array_intersect( array( 'Wartelisten', 'Wartelisten (Export)', 'Entwickler-API-Keys', 'Betrieb' ), $n04_titles ) );
$n04_cap = array_column( Menu::entries(), 3, 0 );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$n04_cap_organizer = array_column( Menu::entries(), 3, 0 );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options', 'wpcap_manage_events' );
$n04_cap_none = array_column( Menu::entries(), 3, 0 );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'menu: "Entwickler-API" is open to administrators and to organizers (event-scoped view), nobody else', 'manage_options' === $n04_cap[ DeveloperKeysPage::SLUG ] && 'wpcap_manage_events' === $n04_cap_organizer[ DeveloperKeysPage::SLUG ] && 'manage_options' === $n04_cap_none[ DeveloperKeysPage::SLUG ] && 'manage_options' === $n04_cap[ StatusPage::SLUG ] );

// add_menus(): the former slugs are registered without parent (no menu entry) with a load-hook that redirects.
$GLOBALS['submenu'] = array();
Menu::add_menus();
$n04_hidden = array_column( $GLOBALS['submenu'][''] ?? array(), 1, 2 );
$n04_listed = array_count_values( array_column( $GLOBALS['submenu'][ Menu::SLUG ] ?? array(), 2 ) );
check( 'add_menus: former slugs registered without menu entry (capabilities kept), merged screens listed once', 'wpcap_manage_orders' === ( $n04_hidden[ WaitlistsPage::SLUG ] ?? '' ) && 'manage_options' === ( $n04_hidden[ OperationsPage::SLUG ] ?? '' ) && isset( $n04_hidden[ ApiKeysPage::SLUG ] ) && ! isset( $n04_listed[ WaitlistsPage::SLUG ] ) && ! isset( $n04_listed[ ApiKeysPage::SLUG ] ) && ! isset( $n04_listed[ OperationsPage::SLUG ] ) && 1 === ( $n04_listed[ StatusPage::SLUG ] ?? 0 ) && 1 === ( $n04_listed[ DeveloperKeysPage::SLUG ] ?? 0 ) );
check( 'add_menus: each former slug redirects on load', ! array_filter( array( WaitlistsPage::SLUG, ApiKeysPage::SLUG, OperationsPage::SLUG ), static fn( $slug ) => empty( $GLOBALS['wpcap_test_hooks'][ 'load-_page_' . $slug ] ) ) );

echo "N02/N04: former slugs lead to the merged screens\n";
check( 'wpcap-waitlists → queue tab of "Warteliste" (event and occurrence kept)', 'https://example.test/wp-admin/admin.php?page=wpcap-waitlist&event_id=5&occurrence_id=7&tab=queue' === Menu::legacy_url( 'wpcap-waitlists', array( 'page' => 'wpcap-waitlists', 'event_id' => '5', 'occurrence_id' => '7' ) ) );
check( 'wpcap-api-keys → "Entwickler-API"', 'https://example.test/wp-admin/admin.php?page=wpcap-developer-keys' === Menu::legacy_url( 'wpcap-api-keys', array( 'page' => 'wpcap-api-keys' ) ) );
$n04_ops_url = Menu::legacy_url( 'wpcap-operations', array( 'page' => 'wpcap-operations', 'wpcap_doctor' => '1', 'wpcap_log_ref' => 'a b&c', 'x' => array( 1 ), 'tab' => 'other' ) );
check( 'wpcap-operations → tab "Betrieb" of "Systemstatus" (filters kept and encoded, arrays dropped)', str_starts_with( $n04_ops_url, 'https://example.test/wp-admin/admin.php?page=wpcap-status&wpcap_doctor=1&wpcap_log_ref=a%' ) && str_ends_with( $n04_ops_url, '&tab=operations' ) && ! str_contains( $n04_ops_url, '&c' ) && ! str_contains( $n04_ops_url, 'x=' ) && ! str_contains( $n04_ops_url, 'tab=other' ) );
check( 'other slugs are no legacy pages', '' === Menu::legacy_url( 'wpcap-orders', array() ) && '' === Menu::legacy_url( WaitlistAdmin::PAGE, array() ) );
$n04_parent = $GLOBALS['_parent_pages'] ?? null;
$GLOBALS['_parent_pages'] = array( DeveloperKeysPage::SLUG => Menu::SLUG );
$_GET = array( 'page' => ApiKeysPage::SLUG, 'status' => 'revoked' );
$n04_to = '';
try { Menu::redirect_legacy_page(); } catch ( Throwable $e ) { $n04_to = $e->getMessage(); }
$GLOBALS['_parent_pages'] = array();
$_GET = array( 'page' => WaitlistsPage::SLUG, 'event_id' => '3' );
$n04_stay = 'none';
try { Menu::redirect_legacy_page(); } catch ( Throwable $e ) { $n04_stay = $e->getMessage(); }
$_GET = array();
if ( null === $n04_parent ) { unset( $GLOBALS['_parent_pages'] ); } else { $GLOBALS['_parent_pages'] = $n04_parent; }
check( 'load hook redirects to the registered merged screen; without it (module off) the alias renders in place', 'https://example.test/wp-admin/admin.php?page=wpcap-developer-keys&status=revoked' === $n04_to && 'none' === $n04_stay );
check( 'admin-post handlers of all merged screens stay registered', isset( Menu::handlers()[ ApiKeysPage::ACTION ], Menu::handlers()['wpcap_developer_key_create'], Menu::handlers()['wpcap_developer_key_update'], Menu::handlers()['wpcap_developer_key_revoke'], Menu::handlers()[ WaitlistsPage::ACTION ], Menu::handlers()[ WaitlistsPage::EXPORT_ACTION ], Menu::handlers()[ WaitlistAdmin::ACTION_ENTRY ], Menu::handlers()[ WaitlistAdmin::ACTION_SETTINGS ], Menu::handlers()[ OperationsPage::ACTION ], Menu::handlers()[ OperationsPage::DOCTOR_ACTION ], Menu::handlers()['wpcap_ops_queue_action'], Menu::handlers()['wpcap_ops_diagnose'] ) );

echo "N04: Systemstatus with the tab Betrieb\n";
$n04_overview = $n04_render( array( StatusPage::class, 'render' ) );
$n04_ops = $n04_render( array( StatusPage::class, 'render' ), array( 'tab' => 'operations' ) );
$n04_ops_old = $n04_render( array( OperationsPage::class, 'render' ) );
check( 'overview tab: status sections, tab link to "Betrieb"', str_contains( $n04_overview, 'WP-Cron und Worker' ) && ! str_contains( $n04_overview, 'id="wpcap-doctor"' ) && str_contains( $n04_overview, 'nav-tab-active" href="https://example.test/wp-admin/admin.php?page=wpcap-status" aria-current="page"' ) && str_contains( $n04_overview, 'page=wpcap-status&tab=operations' ) );
check( 'tab "Betrieb": monitoring, support log, data consistency and queues inside the one screen; the former callback opens it', 1 === substr_count( $n04_ops, '<h1>' ) && str_contains( $n04_ops, '<h1>Systemstatus</h1>' ) && str_contains( $n04_ops, 'id="wpcap-doctor"' ) && str_contains( $n04_ops, 'id="wpcap-log"' ) && ! str_contains( $n04_ops, 'WP-Cron und Worker' ) && str_contains( $n04_ops, 'name="tab" value="operations"' ) && str_contains( $n04_ops_old, 'id="wpcap-doctor"' ) && str_contains( $n04_ops_old, '<h1>Systemstatus</h1>' ) );
check( 'tab "Betrieb" links (repairs, doctor) stay on the merged screen', str_contains( $n04_ops, 'page=wpcap-status&tab=operations&wpcap_doctor=1' ) && ! str_contains( $n04_ops, 'page=wpcap-operations' ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$n04_ops_denied = $n04_render( array( StatusPage::class, 'render' ), array( 'tab' => 'operations' ) );
$n04_section_denied = $n04_render( array( OperationsPage::class, 'render_section' ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'both tabs require manage_options', str_starts_with( $n04_ops_denied, 'DIED:' ) && str_starts_with( $n04_section_denied, 'DIED:' ) );

echo "N04: Entwickler-API for administrators and organizers\n";
$n04_type = make_type( 5 );
$n04_event = (int) $wpdb->get_var( "SELECT o.event_id FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=" . (int) $n04_type );
$n04_admin = $n04_render( array( DeveloperKeysPage::class, 'render' ) );
check( 'administrators get the full view (all keys, status filter, endpoint map)', str_contains( $n04_admin, '<h1>Entwickler-API</h1>' ) && str_contains( $n04_admin, 'Endpunkte und Scopes' ) && str_contains( $n04_admin, 'wpcap_developer_key_create' ) );
$n04_issued = ApiKeysPage::admin_action( array( 'do' => 'create', 'event_id' => $n04_event, 'label' => 'n04', 'scopes' => array( 'events.read' ), 'rate_limit' => 10 ) );
$n04_secret = is_array( $n04_issued ) ? (string) $n04_issued['key'] : '';
$n04_once = $n04_render( array( DeveloperKeysPage::class, 'render' ) );
$n04_twice = $n04_render( array( DeveloperKeysPage::class, 'render' ) );
check( 'a key created through the event-scoped form is shown once on the merged screen', '' !== $n04_secret && str_contains( $n04_once, $n04_secret ) && ! str_contains( $n04_twice, $n04_secret ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$n04_org = $n04_render( array( DeveloperKeysPage::class, 'render' ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options', 'wpcap_manage_events' );
$n04_none = $n04_render( array( DeveloperKeysPage::class, 'render' ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'organizers get the event-scoped view on the same screen, others are refused', str_contains( $n04_org, '<h1>Entwickler-API</h1>' ) && str_contains( $n04_org, 'Vorhandene API-Keys' ) && ! str_contains( $n04_org, 'Endpunkte und Scopes' ) && str_starts_with( $n04_none, 'DIED:' ) );

echo "N02: waitlist status pending (double opt-in), legacy unconfirmed\n";
check( 'status label: legacy `unconfirmed` reads like `pending`', Waitlists::status_label( 'pending' ) === Waitlists::status_label( 'unconfirmed' ) && 'unconfirmed' !== Waitlists::status_label( 'unconfirmed' ) && WaitlistsPage::status_labels()['unconfirmed'] === WaitlistsPage::status_labels()['pending'] );
$n02_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=" . (int) $n04_type );
$n02_now = gmdate( 'Y-m-d H:i:s' );
$n02_row = static function ( $email, $status, $confirm_expires ) use ( $wpdb, $p, $n04_event, $n02_occ, $n04_type, $n02_now ) {
	$wpdb->insert( "{$p}wpcap_waitlist", array( 'event_id' => $n04_event, 'occurrence_id' => $n02_occ, 'ticket_type_id' => $n04_type, 'email_hash' => hash( 'sha256', $email ), 'email' => $email, 'position' => 0, 'quantity' => 1, 'status' => $status, 'confirm_expires_at' => $confirm_expires, 'created_at' => $n02_now, 'updated_at' => $n02_now ) );
	return (int) $wpdb->insert_id;
};
$n02_lapsed = $n02_row( 'n02-lapsed@example.com', 'pending', gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );
$n02_legacy = $n02_row( 'n02-legacy@example.com', 'unconfirmed', gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );
$n02_open = $n02_row( 'n02-open@example.com', 'pending', gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) );
Retention::run();
$n02_left = array_map( 'intval', $wpdb->get_col( "SELECT waitlist_id FROM {$p}wpcap_waitlist WHERE waitlist_id IN ($n02_lapsed,$n02_legacy,$n02_open)" ) );
check( 'retention deletes `pending` (and legacy `unconfirmed`) sign-ups whose confirmation link expired, keeps open ones', array( $n02_open ) === $n02_left );
$wpdb->query( "DELETE FROM {$p}wpcap_waitlist WHERE waitlist_id=$n02_open" );

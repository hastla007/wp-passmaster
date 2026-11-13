<?php
/**
 * A088: grouped admin menu (areas with headings, slugs/capabilities unchanged), "Erste Schritte"
 * checklist derived from data (dismissible per user), event list with key figures per event
 * (sold / capacity / revenue) computed with a constant number of queries.
 * Included from run.php (uses check(), make_type(), admin_paid_order()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\EventsPage;
use PassCaptain\Admin\GettingStarted;
use PassCaptain\Admin\Menu;
use PassCaptain\Admin\Queries;
use PassCaptain\Settings;

if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = null ) { return esc_attr( $t ); } }
if ( ! function_exists( 'esc_js' ) ) { function esc_js( $s ) { return addslashes( (string) $s ); } }
if ( ! function_exists( 'selected' ) ) { function selected( $a, $b = true, $echo = true ) { $r = $a == $b ? ' selected="selected"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce" value="n">'; } }
if ( ! function_exists( 'submit_button' ) ) { function submit_button( $t = '', $type = 'primary', $name = 'submit', $wrap = true ) { echo '<button>' . esc_html( $t ) . '</button>'; } }
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $t ) { return trim( strip_tags( (string) $t ) ); } }
if ( ! function_exists( 'wp_timezone_string' ) ) { function wp_timezone_string() { return 'Europe/Vienna'; } }
if ( ! function_exists( 'get_user_meta' ) ) { function get_user_meta( $u, $k, $single = false ) { return $GLOBALS['p1u_user_meta'][ $u ][ $k ] ?? ''; } }
if ( ! function_exists( 'update_user_meta' ) ) { function update_user_meta( $u, $k, $v ) { $GLOBALS['p1u_user_meta'][ $u ][ $k ] = $v; return true; } }
if ( ! function_exists( 'add_menu_page' ) ) {
	function add_menu_page( $page_title, $menu_title, $cap, $slug, $cb = '', $icon = '', $pos = null ) { $GLOBALS['a088_menu'][] = array( $menu_title, $cap, $slug ); return 'toplevel_page_' . $slug; }
}
if ( ! function_exists( 'add_submenu_page' ) ) {
	function add_submenu_page( $parent, $page_title, $menu_title, $cap, $slug, $cb = '', $pos = null ) { $GLOBALS['submenu'][ $parent ][] = array( $menu_title, $cap, $slug, $page_title ); return $parent . '_page_' . $slug; }
}

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();

/* ============================================================== Menu */

echo "A088: admin menu grouped by area\n";
$a088_entries = Menu::entries();
$a088_layout = Menu::layout( $a088_entries );
$a088_pages = array_values( array_filter( $a088_layout, static fn( $r ) => 'page' === $r[0] ) );
$a088_heads = array_values( array_filter( $a088_layout, static fn( $r ) => 'heading' === $r[0] ) );
$a088_slugs = array_map( static fn( $r ) => $r[1][0], $a088_pages );
$a088_sig_in = array_map( static fn( $e ) => $e[0] . '|' . $e[3], $a088_entries );
$a088_sig_out = array_map( static fn( $r ) => $r[1][0] . '|' . $r[1][3], $a088_pages );
sort( $a088_sig_in );
sort( $a088_sig_out );
check( 'every page keeps its slug and capability (nothing added, nothing lost)', $a088_sig_in === $a088_sig_out && count( $a088_sig_in ) >= 25 );
check( 'events overview stays the first entry (top-level link target)', 'pass-captain' === $a088_layout[0][1][0] && 'page' === $a088_layout[0][0] );
check( 'all module pages are in the menu exactly once', ! array_diff( array_map( static fn( $pg ) => $pg[0], PassCaptain\Modules::admin_pages() ), $a088_slugs ) && count( $a088_slugs ) === count( array_unique( $a088_slugs ) ) );
check( 'headings for the further areas in order, none in front of the first area', array( 'sales', 'admission', 'finance', 'communication', 'settings' ) === array_map( static fn( $h ) => $h[1], $a088_heads ) && 'Verkauf' === $a088_heads[0][2] );
$a088_pos = array_flip( $a088_slugs );
check( 'pages sit in their area (orders after events, check-ins after orders, reports after refunds, settings last area)', $a088_pos['wpcap-orders'] < $a088_pos['wpcap-checkins'] && $a088_pos['wpcap-checkins'] < $a088_pos['wpcap-refunds'] && $a088_pos['wpcap-series'] < $a088_pos['wpcap-orders'] && $a088_pos['wpcap-devices'] < $a088_pos['wpcap-reports'] && $a088_pos['wpcap-mail-templates'] < $a088_pos['wpcap-settings'] );
$a088_index = array();
foreach ( $a088_layout as $i => $row ) { $a088_index[ 'heading' === $row[0] ? '#' . $row[1] : $row[1][0] ] = $i; }
check( 'area headings sit right before their first page', $a088_index['#sales'] + 1 === $a088_index['wpcap-orders'] && $a088_index['#admission'] + 1 === $a088_index['wpcap-checkins'] && $a088_index['#finance'] + 1 === $a088_index['wpcap-refunds'] );
check( 'unknown pages land in the settings area; module may declare its area', 'settings' === Menu::group_of( 'wpcap-unknown-x' ) && 'sales' === Menu::group_of( 'wpcap-unknown-x', 'sales' ) && 'settings' === Menu::group_of( 'wpcap-unknown-x', 'bogus' ) );
add_filter( 'wpcap_admin_menu_group', $a088_f = static function ( $group, $slug ) { return 'wpcap-reports' === $slug ? 'sales' : $group; }, 10, 2 );
check( 'wpcap_admin_menu_group moves a page into another area', 'sales' === Menu::group_of( 'wpcap-reports' ) );
remove_filter( 'wpcap_admin_menu_group', $a088_f, 10 );

// A scanner-only user: only admission pages visible → no heading before that area, headings use a cap the user holds.
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options', 'wpcap_manage_events', 'wpcap_manage_orders', 'wpcap_view_reports', 'wpcap_approve_refunds', 'wpcap_manage_campaigns', 'wpcap_manage_series', 'wpcap_manage_passes', 'wpcap_manage_seating', 'wpcap_manage_allocations', 'wpcap_boxoffice', 'wpcap_guestlists', 'wpcap_manage_guestlists', 'wpcap_manage_partners', 'wpcap_manage_waitlist', 'wpcap_exchange_tickets', 'wpcap_print', 'wpcap_manage_print', 'wpcap_manage_offline_scanner', 'wpcap_manage_admission', 'manage_woocommerce' );
$a088_staff = Menu::layout( Menu::entries() );
$a088_staff_heads = array_values( array_filter( $a088_staff, static fn( $r ) => 'heading' === $r[0] ) );
$a088_visible = array_values( array_filter( $a088_staff, static fn( $r ) => 'page' === $r[0] && current_user_can( $r[1][3] ) ) );
$a088_first_visible_area = $a088_visible ? Menu::group_of( $a088_visible[0][1][0] ) : '';
check( 'restricted user: no heading in front of the first visible area, every heading is visible to the user', ! in_array( $a088_first_visible_area, array_map( static fn( $h ) => $h[1], $a088_staff_heads ), true ) && ! array_filter( $a088_staff_heads, static fn( $h ) => ! current_user_can( $h[3] ) ) );
$GLOBALS['wpcap_test_denied_caps'] = array();

$GLOBALS['submenu'] = array();
$GLOBALS['a088_menu'] = array();
Menu::add_menus();
$a088_sub = $GLOBALS['submenu']['pass-captain'] ?? array();
$a088_sub_heads = array_values( array_filter( $a088_sub, static fn( $s ) => 'wpcap-menu-heading' === ( $s[4] ?? '' ) ) );
check( 'add_menus registers the top level page and the grouped submenu with heading rows', 'pass-captain' === ( $GLOBALS['a088_menu'][0][2] ?? '' ) && 'pass-captain' === $a088_sub[0][2] && 5 === count( $a088_sub_heads ) && '#wpcap-menu-sales' === $a088_sub_heads[0][2] && count( $a088_sub ) === count( $a088_layout ) );
check( 'old URLs/slugs remain registered with the same capabilities', in_array( array( 'Bestellungen', 'wpcap_manage_orders', 'wpcap-orders', 'Bestellungen' ), $a088_sub, true ) && in_array( 'wpcap-settings', array_column( $a088_sub, 2 ), true ) && in_array( 'wpcap-devices', array_column( $a088_sub, 2 ), true ) );
ob_start(); Menu::menu_heading_script(); $a088_js = ob_get_clean();
check( 'headings become plain text in the menu (no dead link)', false !== strpos( $a088_js, 'li.wpcap-menu-heading > a' ) && false !== strpos( $a088_js, 'textContent' ) );
check( 'checklist toggle handler is registered', isset( Menu::handlers()['wpcap_getting_started'] ) );

/* ============================================================== Checklist */

echo "A088: getting-started checklist derived from data\n";
$a088_empty = GettingStarted::items( array() );
check( 'nine steps, all open on an empty installation', 9 === count( $a088_empty ) && ! array_filter( $a088_empty, static fn( $i ) => $i['done'] ) && array( 'payment', 'legal', 'seller', 'event', 'occurrence', 'ticket_type', 'publish', 'scanner', 'test_order' ) === array_column( $a088_empty, 'key' ) );
$a088_full = GettingStarted::items( array( 'payment_manual' => true, 'legal_texts' => true, 'seller' => true, 'events' => 1, 'upcoming_occurrences' => 2, 'ticket_types' => 3, 'published_events' => 1, 'devices' => 1, 'paid_orders' => 1 ) );
check( 'all steps done when the data is complete', 9 === count( array_filter( $a088_full, static fn( $i ) => $i['done'] ) ) );
$a088_by = static function ( array $items ) { $o = array(); foreach ( $items as $i ) { $o[ $i['key'] ] = $i; } return $o; };
check( 'payment counts via online gateway, bank transfer or WooCommerce', $a088_by( GettingStarted::items( array( 'payment_online' => true ) ) )['payment']['done'] && $a088_by( GettingStarted::items( array( 'woocommerce' => true ) ) )['payment']['done'] && ! $a088_by( GettingStarted::items( array( 'payment_manual' => false ) ) )['payment']['done'] );
check( 'open steps link to the place where they are done', false !== strpos( $a088_by( $a088_empty )['legal']['url'], 'page=wpcap-settings' ) && str_ends_with( $a088_by( $a088_empty )['legal']['url'], '#wpcap-settings-legal' ) && false !== strpos( $a088_by( $a088_empty )['scanner']['url'], 'page=wpcap-devices' ) && str_ends_with( $a088_by( $a088_empty )['event']['url'], '#wpcap-create-event' ) );

$a088_settings_before = get_option( Settings::OPTION, array() );
Settings::update( array( 'enabled_providers' => array( 'manual' ), 'bank_iban' => '', 'manual_instructions' => '', 'terms_text' => '', 'consumer_info_text' => '', 'seller_name' => '', 'seller_address' => '', 'stripe_secret_key' => '', 'stripe_webhook_secret' => '' ) );
$a088_state = GettingStarted::state();
check( 'state: counts come from the tables', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_events" ) === $a088_state['events'] && (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_orders WHERE payment_status='paid'" ) === $a088_state['paid_orders'] && (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_scanner_devices" ) === $a088_state['devices'] && (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_ticket_types WHERE status='active'" ) === $a088_state['ticket_types'] );
check( 'state: no payment, no legal texts, no seller while the settings are empty', ! $a088_state['payment_online'] && ! $a088_state['payment_manual'] && ! $a088_state['legal_texts'] && ! $a088_state['seller'] );
Settings::update( array( 'bank_iban' => 'AT611904300234573201', 'terms_text' => '<p>AGB</p>', 'consumer_info_text' => '<p>Widerruf</p>', 'seller_name' => 'Verein', 'seller_address' => 'Gasse 1, Wien' ) );
$a088_state2 = GettingStarted::state();
check( 'state: bank transfer with IBAN, legal texts and seller are detected', $a088_state2['payment_manual'] && $a088_state2['legal_texts'] && $a088_state2['seller'] );
Settings::update( array( 'enabled_providers' => array( 'manual', 'stripe' ), 'stripe_secret_key' => 'sk_test_x', 'stripe_webhook_secret' => '' ) );
$a088_half = GettingStarted::state()['payment_online'];
Settings::update( array( 'stripe_webhook_secret' => 'whsec_x' ) );
check( 'state: Stripe counts only with secret key and webhook secret', ! $a088_half && GettingStarted::state()['payment_online'] );
Settings::update( array( 'terms_text' => '<p> </p>' ) );
check( 'state: legal texts made of empty markup do not count', ! GettingStarted::state()['legal_texts'] );
$a088_q = $wpdb->num_queries;
GettingStarted::state();
check( 'state needs a single database query', 1 === $wpdb->num_queries - $a088_q );

add_filter( 'wpcap_getting_started_items', $a088_gf = static function ( $items, $state ) { $items[] = array( 'key' => 'module_step', 'label' => 'Modul', 'done' => ! empty( $state['events'] ) ); $items[] = array( 'label' => 'ohne Schlüssel' ); return $items; }, 10, 2 );
$a088_ext = $a088_by( GettingStarted::items( array( 'events' => 1 ) ) );
remove_filter( 'wpcap_getting_started_items', $a088_gf, 10 );
check( 'wpcap_getting_started_items adds steps (invalid ones are dropped)', isset( $a088_ext['module_step'] ) && $a088_ext['module_step']['done'] && 10 === count( $a088_ext ) );

$GLOBALS['p1u_user_meta'][1][ GettingStarted::META ] = 0;
ob_start(); GettingStarted::render(); $a088_html = ob_get_clean();
check( 'checklist: section with heading, progress and ordered list', false !== strpos( $a088_html, '<section class="wpcap-getting-started' ) && false !== strpos( $a088_html, 'aria-labelledby="wpcap-getting-started-title"' ) && false !== strpos( $a088_html, '<h2 id="wpcap-getting-started-title">Erste Schritte</h2>' ) && false !== strpos( $a088_html, '<progress max="9"' ) && false !== strpos( $a088_html, '<ol class="wpcap-getting-started-list"' ) );
check( 'checklist: done/open is announced as text, icons are hidden', false !== strpos( $a088_html, '<span class="screen-reader-text">Erledigt: </span>' ) && false !== strpos( $a088_html, '<span class="screen-reader-text">Offen: </span>' ) && false !== strpos( $a088_html, 'aria-hidden="true"' ) );
check( 'checklist: open step is a link, the hide button posts the toggle', false !== strpos( $a088_html, '#wpcap-settings-legal">Rechtstexte hinterlegen</a>' ) && false !== strpos( $a088_html, 'value="wpcap_getting_started"' ) && false !== strpos( $a088_html, 'name="mode" value="hide"' ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
ob_start(); GettingStarted::render(); $a088_nohtml = ob_get_clean();
$a088_forbidden = GettingStarted::toggle( array( 'mode' => 'hide' ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'checklist is only for administrators', '' === $a088_nohtml && is_wp_error( $a088_forbidden ) );

$_POST = array( 'action' => 'wpcap_getting_started', 'mode' => 'hide', '_wpnonce' => 'n' );
$GLOBALS['wpcap_test_nonce_checks'] = array();
$a088_redirect = '';
try { GettingStarted::handle_toggle(); } catch ( Wpcap_Test_Redirect $e ) { $a088_redirect = $e->getMessage(); }
check( 'hiding is stored per user with nonce check and redirects to the events page', GettingStarted::hidden() && in_array( 'wpcap_getting_started', $GLOBALS['wpcap_test_nonce_checks'], true ) && false !== strpos( $a088_redirect, 'page=pass-captain' ) );
$GLOBALS['wpcap_test_user_id'] = 2;
check( 'another user still sees the checklist', ! GettingStarted::hidden() );
$GLOBALS['wpcap_test_user_id'] = 1;
ob_start(); GettingStarted::render(); $a088_hidden_html = ob_get_clean();
check( 'hidden checklist leaves only a "show" button while steps are open', false === strpos( $a088_hidden_html, '<section' ) && false !== strpos( $a088_hidden_html, 'name="mode" value="show"' ) && false !== strpos( $a088_hidden_html, 'Erste-Schritte-Checkliste anzeigen' ) );
GettingStarted::toggle( array( 'mode' => 'show' ) );
check( 'showing it again works', ! GettingStarted::hidden() );
$_POST = array();
update_option( Settings::OPTION, $a088_settings_before );
Settings::flush();

/* ============================================================== Event figures */

echo "A088: event list with key figures\n";
$a088_type = make_type( 5, 10, 2000, 0 );
$a088_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a088_type" );
$a088_event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$a088_occ" );
$a088_o1 = admin_paid_order( $a088_type, 2, 'a088-1@example.com' );
$a088_o2 = admin_paid_order( $a088_type, 1, 'a088-2@example.com' );
$GLOBALS['wpcap_test_transients'] = array();
$a088_pending = PassCaptain\Orders::create( array( 'email' => 'a088-p@example.com', 'items' => array( array( 'ticket_type_id' => $a088_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a088-pending' ) );
$a088_fig = Queries::event_figures( array( $a088_event ) )[ $a088_event ] ?? array();
check( 'sold counts paid units only; capacity from the ticket type limit', $a088_o1 && $a088_o2 && ! is_wp_error( $a088_pending ) && 3 === $a088_fig['sold'] && 5 === $a088_fig['capacity'] );
check( 'revenue per currency from the paid lines', array( 'EUR' => 6000 ) === $a088_fig['revenue'] );
check( 'sold matches the occurrence table figure', Queries::sold_for_occurrence( $a088_occ ) === $a088_fig['sold'] );

// Partial refund of one unit: sold and revenue pro rata.
$wpdb->query( "UPDATE {$p}wpcap_order_items SET refunded_quantity=1 WHERE order_id=$a088_o1 AND ticket_type_id=$a088_type" );
PassCaptain\CapacityCounters::reconcile(); // The direct update bypasses the counters.
$a088_fig = Queries::event_figures( array( $a088_event ) )[ $a088_event ];
check( 'refunded units leave sold and revenue (pro rata)', 2 === $a088_fig['sold'] && array( 'EUR' => 4000 ) === $a088_fig['revenue'] );

// Occurrence limit smaller than the ticket types; a cancelled occurrence does not count.
$wpdb->update( "{$p}wpcap_occurrences", array( 'capacity' => 4 ), array( 'occurrence_id' => $a088_occ ) );
$now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $a088_event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 14 * 86400 ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => 50, 'status' => 'cancelled' ) );
$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $a088_event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 21 * 86400 ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => 0, 'status' => 'scheduled' ) );
$a088_occ3 = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $a088_occ3, 'name' => 'B', 'price_minor' => 1000, 'currency' => 'EUR', 'capacity' => 7, 'max_per_order' => 10, 'status' => 'active' ) );
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $a088_occ3, 'name' => 'C', 'price_minor' => 1000, 'currency' => 'EUR', 'capacity' => 3, 'max_per_order' => 10, 'status' => 'paused' ) );
$a088_fig = Queries::event_figures( array( $a088_event ) )[ $a088_event ];
check( 'capacity: min(occurrence limit, type limits) summed over not cancelled occurrences', 4 + 10 === $a088_fig['capacity'] );
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $a088_occ3, 'name' => 'Frei', 'price_minor' => 0, 'currency' => 'EUR', 'capacity' => 0, 'max_per_order' => 10, 'status' => 'active' ) );
$a088_fig = Queries::event_figures( array( $a088_event ) )[ $a088_event ];
check( 'capacity: unlimited when an occurrence has no limit at all', null === $a088_fig['capacity'] && '2 / ∞' === EventsPage::figures_sold_label( $a088_fig ) );
check( 'labels: percentage and money', '3 / 10 (30 %)' === EventsPage::figures_sold_label( array( 'sold' => 3, 'capacity' => 10 ) ) && '0 / 0' === EventsPage::figures_sold_label( array( 'sold' => 0, 'capacity' => 0 ) ) && '—' === EventsPage::figures_revenue_label( array( 'revenue' => array() ) ) && PassCaptain\Admin\Forms::money( 4000, 'EUR' ) === EventsPage::figures_revenue_label( $a088_fig ) );

// Constant number of queries for any number of events.
$a088_all = array_map( 'intval', $wpdb->get_col( "SELECT event_id FROM {$p}wpcap_events ORDER BY event_id DESC LIMIT 50" ) );
$a088_q = $wpdb->num_queries;
$a088_many = Queries::event_figures( $a088_all );
$a088_q_many = $wpdb->num_queries - $a088_q;
$a088_q = $wpdb->num_queries;
Queries::event_figures( array( $a088_event ) );
$a088_q_one = $wpdb->num_queries - $a088_q;
check( 'figures need two queries for one or fifty events (no N+1)', 2 === $a088_q_many && 2 === $a088_q_one && count( $a088_many ) === count( array_unique( $a088_all ) ) && 0 === count( Queries::event_figures( array() ) ) );
$a088_mismatch = 0;
foreach ( array_slice( $a088_all, 0, 15 ) as $eid ) {
	$sum = 0;
	foreach ( Queries::occurrences_for_event( $eid ) as $o ) { $sum += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(oi.quantity - oi.refunded_quantity),0) FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_orders ord ON ord.order_id=oi.order_id WHERE tt.occurrence_id=%d AND ord.payment_status='paid' AND ord.status <> 'refunded'", $o->occurrence_id ) ); }
	if ( $sum !== $a088_many[ $eid ]['sold'] ) { $a088_mismatch++; }
}
check( 'bulk sold equals the per-occurrence sums for existing events', 0 === $a088_mismatch );
add_filter( 'wpcap_event_figures', $a088_ff = static function ( $figs ) { foreach ( $figs as &$f ) { $f['sold'] += 100; } return $figs; } );
check( 'wpcap_event_figures adjusts the figures in bulk', 102 === Queries::event_figures( array( $a088_event ) )[ $a088_event ]['sold'] );
remove_filter( 'wpcap_event_figures', $a088_ff );

$_GET = array( 'page' => 'pass-captain' );
$GLOBALS['p1u_user_meta'][1][ GettingStarted::META ] = 0;
ob_start();
try { EventsPage::render(); } catch ( Throwable $e ) { echo 'EXCEPTION ' . $e->getMessage(); }
$a088_list = ob_get_clean();
$_GET = array();
check( 'event list renders without errors', false === strpos( $a088_list, 'EXCEPTION' ) );
check( 'event list: caption, column headers with scope, event name as row header', false !== strpos( $a088_list, '<caption class="screen-reader-text">' ) && false !== strpos( $a088_list, '<th scope="col">Verkauft / Kapazität</th>' ) && false !== strpos( $a088_list, '<th scope="col">Umsatz</th>' ) && 1 <= substr_count( $a088_list, '<th scope="row">' ) );
check( 'event list shows sold/capacity and revenue of the event', 1 === preg_match( '/event_id=' . $a088_event . '[^<]*">[^<]*<\/a><\/strong><\/th>.*?<td>2 \/ ∞<\/td>\s*<td>' . preg_quote( esc_html( PassCaptain\Admin\Forms::money( 4000, 'EUR' ) ), '/' ) . '<\/td>/s', $a088_list ) );
check( 'event list: checklist above the table, create form has an anchor', strpos( $a088_list, 'wpcap-getting-started' ) < strpos( $a088_list, 'wpcap-events-list' ) && false !== strpos( $a088_list, '<h2 id="wpcap-create-event">' ) );

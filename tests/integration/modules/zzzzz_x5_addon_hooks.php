<?php
/**
 * X5: core extension points for the premium add-on (no premium features in the core):
 * admin menu action, white label (brand name/logo, admin menu title/icon), invoice API and hooks
 * (created action, read API, mail attachments, links), checkout gate (waiting room) for native
 * checkout, REST, service bookings and WooCommerce, and API scopes with Api\Auth::request_can().
 * Every hook is proven to fire/apply, and without callbacks the core behaves exactly as before.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

require_once dirname( __DIR__ ) . '/lib/fixtures_shared.php';
wpcap_test_route_exception(); // Store API exception (declared by woocommerce_multiseller.php in a full run).
if ( ! function_exists( 'WC' ) ) { function WC() { return $GLOBALS['wpcap_test_wc']; } }
// WordPress template stubs for the order portal in a single run (a full run defines them earlier, zz_phase1_ux.php).
if ( ! function_exists( 'bloginfo' ) ) { function bloginfo( $k = '' ) { echo 'UTF-8'; } }
if ( ! function_exists( 'esc_textarea' ) ) { function esc_textarea( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'checked' ) ) { function checked( $a, $b = true, $echo = true ) { $r = $a == $b ? ' checked="checked"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . ltrim( $p, '/' ); } }
if ( ! function_exists( 'wp_verify_nonce' ) ) { function wp_verify_nonce( $n, $a = -1 ) { return 'n' === $n ? 1 : false; } }
if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $a = -1 ) { return 'n'; } }
if ( ! function_exists( 'wp_nonce_url' ) ) { function wp_nonce_url( $u, $a = -1 ) { return $u . '&_wpnonce=n'; } }
if ( ! function_exists( 'get_user_meta' ) ) { function get_user_meta( $u, $k, $single = false ) { return $GLOBALS['p1u_user_meta'][ $u ][ $k ] ?? ''; } }
if ( ! function_exists( 'update_user_meta' ) ) { function update_user_meta( $u, $k, $v ) { $GLOBALS['p1u_user_meta'][ $u ][ $k ] = $v; return true; } }
if ( ! function_exists( 'locate_template' ) ) { function locate_template( $t ) { return ''; } }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) {} }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) {} }
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $t ) { return '<p>' . $t . '</p>'; } }

use PassCaptain\Admin\ApiKeysPage;
use PassCaptain\Admin\Menu;
use PassCaptain\Api\Auth;
use PassCaptain\Api\Invoices as InvoiceApi;
use PassCaptain\Brand;
use PassCaptain\DeveloperKeys;
use PassCaptain\Integrations\WooCommerce\CheckoutGate as WooGate;
use PassCaptain\Integrations\WooCommerce\OrderAdapter as WooAdapter;
use PassCaptain\Invoices;
use PassCaptain\Orders;
use PassCaptain\Partners\Repository as X5Partners;
use PassCaptain\Partners\Settlements as X5Settlements;
use PassCaptain\Rest\Controller;
use PassCaptain\Sales\CheckoutGate;
use PassCaptain\ServiceBookings;
use PassCaptain\Settings;

require_once WPCAP_PATH . 'src/functions.php';

require_once dirname( __DIR__ ) . '/lib/menu_stubs.php'; // Shared recorder: zz_a088 may have defined the stubs first.
if ( ! function_exists( 'is_user_logged_in' ) ) { function is_user_logged_in() { return true; } }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) {} }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) {} }
if ( ! defined( 'WPCAP_URL' ) ) { define( 'WPCAP_URL', 'https://example.test/wp-content/plugins/pass-captain/' ); }
if ( ! function_exists( 'sanitize_file_name' ) ) { function sanitize_file_name( $f ) { return preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $f ); } }
// The merged offline scanner link carries the user locale (THEIRS ?lang=).
if ( ! function_exists( 'get_user_locale' ) ) { function get_user_locale( $u = 0 ) { return 'de_DE'; } }
if ( ! function_exists( 'wp_script_is' ) ) { function wp_script_is( $h, $l = 'enqueued' ) { return false; } }

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
wpcap_test_reset_transients();
PassCaptain\Settings::update( array( 'require_terms' => 0, 'refund_approval_threshold' => 0 ) );

/** Collects hook calls of a test callback. */
$x5_calls = array();

/* ================================================================== (a) admin menu */

echo "X5: admin menu – built action with the parent slug, title and icon filters\n";
$GLOBALS['x5_menu'] = array();
$GLOBALS['x5_submenu'] = array();
$x5_built = static function ( $parent ) use ( &$x5_calls ) {
	$x5_calls['menu_built'][] = $parent;
	add_submenu_page( $parent, 'Pro', 'Pro', 'manage_options', 'x5-pro-page', '__return_true' );
};
add_action( 'wp_pass_captain_admin_menu_built_v1', $x5_built );
Menu::add_menus();
remove_action( 'wp_pass_captain_admin_menu_built_v1', $x5_built );
check( 'wp_pass_captain_admin_menu_built_v1 fires once with the core parent slug', array( Menu::SLUG ) === ( $x5_calls['menu_built'] ?? array() ) );
check( 'add-on page registered from the action lands under the core menu, after the core pages', array( Menu::SLUG, 'x5-pro-page' ) === end( $GLOBALS['x5_submenu'] ) );
check( 'default menu: title "Pass Captain" and the tickets dashicon (unchanged)', 'Pass Captain' === $GLOBALS['x5_menu'][0]['menu_title'] && 'dashicons-tickets-alt' === $GLOBALS['x5_menu'][0]['icon'] );
$x5_title = static fn( $t ) => 'Tixx <b>Admin</b>';
$x5_icon = static fn( $i ) => 'data:image/svg+xml;base64,PHN2Zy8+';
add_filter( 'wp_pass_captain_admin_menu_title_v1', $x5_title );
add_filter( 'wp_pass_captain_admin_menu_icon_v1', $x5_icon );
$GLOBALS['x5_menu'] = array();
Menu::add_menus();
check( 'admin menu title and icon filters apply (title as plain text, SVG data URI accepted)', 'Tixx Admin' === $GLOBALS['x5_menu'][0]['menu_title'] && 'data:image/svg+xml;base64,PHN2Zy8+' === $GLOBALS['x5_menu'][0]['icon'] );
remove_filter( 'wp_pass_captain_admin_menu_title_v1', $x5_title );
remove_filter( 'wp_pass_captain_admin_menu_icon_v1', $x5_icon );
$x5_bad_icon = static fn( $i ) => 'javascript:alert(1)';
add_filter( 'wp_pass_captain_admin_menu_icon_v1', $x5_bad_icon );
check( 'invalid menu icons fall back to the default dashicon', 'dashicons-tickets-alt' === Brand::menu_icon() );
remove_filter( 'wp_pass_captain_admin_menu_icon_v1', $x5_bad_icon );

/* ================================================================== (d) white label */

echo "X5: white label – brand name and logo\n";
check( 'defaults: name "Pass Captain", no logo, texts unchanged', 'Pass Captain' === Brand::name( 'portal' ) && '' === Brand::logo( 'portal' ) && 'Pass Captain Check-in' === Brand::filter_gettext( 'Pass Captain Check-in', 'Pass Captain Check-in' ) && '' === Brand::logo_html( 'portal' ) );
$x5_brand_contexts = array();
$x5_name = static function ( $name, $context ) use ( &$x5_brand_contexts ) { $x5_brand_contexts[] = $context; return 'Tixx'; };
$x5_logo = static fn( $logo, $context ) => 'ticket_page' === $context ? 'javascript:alert(1)' : 'https://cdn.example.test/logo.png';
add_filter( 'wp_pass_captain_brand_name_v1', $x5_name, 10, 2 );
add_filter( 'wp_pass_captain_brand_logo_v1', $x5_logo, 10, 2 );
check( 'brand name filter gets the context and applies', 'Tixx' === Brand::name( 'scanner' ) && in_array( 'scanner', $x5_brand_contexts, true ) );
check( 'translated texts of the domain are rewritten (gettext filter)', 'Tixx Check-in' === Brand::filter_gettext( 'Pass Captain Check-in', 'Pass Captain Check-in' ) && 'Im WordPress-Admin unter „Tixx → Scanner-Geräte“' === Brand::filter_gettext( 'Im WordPress-Admin unter „Pass Captain → Scanner-Geräte“' ) );
check( 'admin menu title follows the brand name by default', 'Tixx' === Brand::menu_title() );
check( 'logo filter applies; non-http(s) URLs are dropped', 'https://cdn.example.test/logo.png' === Brand::logo( 'portal' ) && '' === Brand::logo( 'ticket_page' ) && false !== strpos( Brand::logo_html( 'portal' ), 'src="https://cdn.example.test/logo.png"' ) && false !== strpos( Brand::logo_html( 'portal' ), 'alt="Tixx"' ) );
$x5_i18n = PassCaptain\OfflineScanner\I18n::table( 'de-DE' );
check( 'offline scanner i18n table carries the brand (name, logo, custom flag)', array( 'name' => 'Tixx', 'logo' => 'https://cdn.example.test/logo.png', 'custom' => true ) === $x5_i18n['brand'] );
$x5_manifest = PassCaptain\OfflineScanner\Rest::manifest( new WP_REST_Request( 'GET' ) );
$x5_manifest = $x5_manifest instanceof WP_REST_Response ? $x5_manifest->get_data() : $x5_manifest;
check( 'offline scanner manifest uses the brand name and absolute icon/start URLs', 'Tixx Scanner' === $x5_manifest['name'] && 'Tixx' === $x5_manifest['short_name'] && 0 === strpos( $x5_manifest['icons'][0]['src'], 'http' ) && false !== strpos( $x5_manifest['start_url'], 'index.html?api=' ) );
$x5_scanner_html = PassCaptain\PublicEvents::render_scanner_shortcode();
check( 'web scanner shows the brand logo', false !== strpos( $x5_scanner_html, 'wpcap-brand-logo' ) && false !== strpos( $x5_scanner_html, 'logo.png' ) );
check( 'PDF metadata names the brand as producer', false !== strpos( PassCaptain\Printing\PdfWriter::escape( Brand::name( 'pdf' ) ), 'Tixx' ) );
$GLOBALS['x5_menu'] = array();
Menu::add_menus();
check( 'admin menu shows the brand name', 'Tixx' === $GLOBALS['x5_menu'][0]['menu_title'] );
$x5_pro = static fn( $name, $context ) => 'Pass Captain Pro';
remove_filter( 'wp_pass_captain_brand_name_v1', $x5_name, 10 );
add_filter( 'wp_pass_captain_brand_name_v1', $x5_pro, 10, 2 );
check( 'names containing the default name are applied once (idempotent)', 'Pass Captain Pro Check-in' === Brand::text( Brand::text( 'Pass Captain Check-in' ) ) );
remove_filter( 'wp_pass_captain_brand_name_v1', $x5_pro, 10 );
remove_filter( 'wp_pass_captain_brand_logo_v1', $x5_logo, 10 );
$x5_i18n = PassCaptain\OfflineScanner\I18n::table( 'de-DE' );
$x5_manifest = PassCaptain\OfflineScanner\Rest::manifest( new WP_REST_Request( 'GET' ) );
$x5_manifest = $x5_manifest instanceof WP_REST_Response ? $x5_manifest->get_data() : $x5_manifest;
check( 'without filters: brand not custom, manifest identical to the static manifest.json names', array( 'name' => 'Pass Captain', 'logo' => '', 'custom' => false ) === $x5_i18n['brand'] && 'Pass Captain Scanner' === $x5_manifest['name'] && 'PC Scanner' === $x5_manifest['short_name'] );
check( 'without filters: web scanner has no logo and the default heading', false === strpos( PassCaptain\PublicEvents::render_scanner_shortcode(), 'wpcap-brand-logo' ) && false !== strpos( PassCaptain\PublicEvents::render_scanner_shortcode(), 'Pass Captain Check-in' ) );
$x5_static = json_decode( (string) file_get_contents( WPCAP_PATH . 'assets/offline-scanner/manifest.json' ), true );
check( 'dynamic default manifest matches the static manifest.json (name, short name, colors)', $x5_static['name'] === $x5_manifest['name'] && $x5_static['short_name'] === $x5_manifest['short_name'] && $x5_static['theme_color'] === $x5_manifest['theme_color'] );
$x5_app = (string) file_get_contents( WPCAP_PATH . 'assets/offline-scanner/app.js' );
check( 'offline scanner app uses the brand for title, manifest and logo', false !== strpos( $x5_app, "document.title = t('Offline-Scanner') + ' · ' + brandName();" ) && false !== strpos( $x5_app, "endpoint('offline/manifest', '')" ) && false !== strpos( $x5_app, "'brand-logo'" ) );

/* ================================================================== (b) invoices */

echo "X5: invoices – created action, read API, mail attachments, links\n";
// Core invoice hooks as Plugin::run() registers them (credit notes on refunds, auto invoices, ticket mail attachments).
Invoices::register();
check( 'Invoices::register() hooks the add-on attachments into the ticket mail (priority 30)', (bool) array_filter( $GLOBALS['wpcap_test_hooks']['wpcap_ticket_mail'][30] ?? array(), static fn( $e ) => array( InvoiceApi::class, 'filter_ticket_mail' ) === $e[0] ) );
$x5_created = array();
$x5_on_created = static function ( $id, $type, $context ) use ( &$x5_created ) { $x5_created[] = array( $id, $type, $context ); };
add_action( 'wp_pass_captain_invoice_created_v1', $x5_on_created, 10, 3 );
$x5_type = make_type( 20, 10, 2380 );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET tax_rate_bp=1900 WHERE ticket_type_id=$x5_type" );
$x5_type7 = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$x5_type" );
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $x5_type7, 'name' => 'Ermäßigt', 'price_minor' => 1070, 'currency' => 'EUR', 'capacity' => 20, 'max_per_order' => 10, 'tax_rate_bp' => 700, 'status' => 'active' ) );
$x5_type7 = (int) $wpdb->insert_id;
wpcap_test_reset_transients();
$x5_order = Orders::create( array( 'email' => 'x5-inv@example.com', 'items' => array( array( 'ticket_type_id' => $x5_type, 'quantity' => 2 ), array( 'ticket_type_id' => $x5_type7, 'quantity' => 1 ) ), 'idempotency_key' => 'x5-inv-' . uniqid(), 'billing' => bb_billing( array( 'company' => 'X5 GmbH', 'postcode' => '10115', 'city' => 'Berlin', 'street' => 'Unter den Linden 1', 'country' => 'DE', 'vat_id' => 'DE123456789', 'po_number' => '991-12345-67' ) ) ) );
Orders::confirm_manual_payment( $x5_order['order_id'] );
$x5_inv = Invoices::issue_for_order( $x5_order['order_id'] );
$x5_inv_created = array_values( array_filter( $x5_created, static fn( $c ) => 'invoice' === $c[1] ) );
check( 'invoice_created_v1 fires once with (invoice_id, "invoice", context order_id/number)', 1 === count( $x5_inv_created ) && $x5_inv['invoice_id'] === $x5_inv_created[0][0] && (int) $x5_order['order_id'] === $x5_inv_created[0][2]['order_id'] && $x5_inv['invoice_number'] === $x5_inv_created[0][2]['number'] );
Invoices::issue_for_order( $x5_order['order_id'] );
check( 'reusing an existing invoice does not fire the action again', 1 === count( array_filter( $x5_created, static fn( $c ) => 'invoice' === $c[1] ) ) );
$x5_doc = InvoiceApi::get( $x5_inv['invoice_id'] );
$x5_sum = static fn( array $rows, $key ) => array_sum( array_column( $rows, $key ) );
check( 'read API: version 1, type, number, date, currency with decimals', is_array( $x5_doc ) && 1 === $x5_doc['version'] && 'invoice' === $x5_doc['type'] && $x5_inv['invoice_number'] === $x5_doc['number'] && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $x5_doc['date'] ) && 'EUR' === $x5_doc['currency'] && 2 === $x5_doc['currency_decimals'] );
check( 'read API: buyer address, VAT ID and buyer reference from the billing snapshot', 'X5 GmbH' === $x5_doc['buyer']['company'] && 'Erika Muster' === $x5_doc['buyer']['name'] && 'Berlin' === $x5_doc['buyer']['city'] && '10115' === $x5_doc['buyer']['postcode'] && 'DE' === $x5_doc['buyer']['country'] && 'DE123456789' === $x5_doc['buyer']['vat_id'] && '991-12345-67' === $x5_doc['references']['buyer_reference'] && 'x5-inv@example.com' === $x5_doc['buyer']['email'] );
check( 'read API: seller party with VAT ID fields', array_key_exists( 'vat_id', $x5_doc['seller'] ) && array_key_exists( 'tax_number', $x5_doc['seller'] ) && is_array( $x5_doc['seller']['address_lines'] ) );
check( 'read API: lines with net/VAT rate/VAT/gross (19 % 2×23,80; 7 % 10,70)', 2 === count( $x5_doc['lines'] ) && 4760 === $x5_doc['lines'][0]['gross_minor'] && 19.0 === $x5_doc['lines'][0]['vat_rate'] && 760 === $x5_doc['lines'][0]['vat_minor'] && 4000 === $x5_doc['lines'][0]['net_minor'] && 2 === $x5_doc['lines'][0]['quantity'] && 7.0 === $x5_doc['lines'][1]['vat_rate'] && 70 === $x5_doc['lines'][1]['vat_minor'] );
check( 'read API: totals and VAT breakdown add up', 5830 === $x5_doc['totals']['gross_minor'] && 830 === $x5_doc['totals']['vat_minor'] && 5000 === $x5_doc['totals']['net_minor'] && $x5_doc['totals']['gross_minor'] === $x5_sum( $x5_doc['vat_breakdown'], 'gross_minor' ) && 2 === count( $x5_doc['vat_breakdown'] ) && 7.0 === $x5_doc['vat_breakdown'][0]['vat_rate'] );
check( 'read API: references (order) and global wpcap_get_invoice()', (int) $x5_order['order_id'] === $x5_doc['references']['order_id'] && $x5_doc === wpcap_get_invoice( $x5_inv['invoice_id'] ) && $x5_doc === InvoiceApi::for_order( $x5_order['order_id'] ) );
check( 'read API: unknown ids/types give null', null === InvoiceApi::get( 999999 ) && null === InvoiceApi::get( $x5_inv['invoice_id'], 'quote' ) && null === wpcap_get_invoice( 0 ) );
// PASS-INV-15 (contract 1.2.0): service date per document and per line (local date of the occurrence).
$x5_occ = $wpdb->get_row( $wpdb->prepare( "SELECT o.starts_at,o.ends_at,o.timezone FROM {$wpdb->prefix}wpcap_order_items oi INNER JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE oi.order_id=%d LIMIT 1", $x5_order['order_id'] ) );
$x5_day = wp_date( 'Y-m-d', strtotime( $x5_occ->starts_at . ' UTC' ), new DateTimeZone( $x5_occ->timezone ?: 'UTC' ) );
$x5_end = $x5_occ->ends_at ? wp_date( 'Y-m-d', strtotime( $x5_occ->ends_at . ' UTC' ), new DateTimeZone( $x5_occ->timezone ?: 'UTC' ) ) : $x5_day;
check( 'PASS-INV-15: every line carries the local service date of its occurrence', (bool) $x5_doc['lines'] && ! array_filter( $x5_doc['lines'], static fn( $l ) => $x5_day !== $l['service_date'] || $x5_end !== $l['service_date_end'] ) );
check( 'PASS-INV-15: service_period is always set; service_date only for a single date', array( 'start' => $x5_day, 'end' => $x5_end ) === $x5_doc['service_period'] && ( $x5_day === $x5_end ? $x5_day : '' ) === $x5_doc['service_date'] && (bool) preg_match( "/define\( 'WPCAP_CONTRACT_VERSION', '1\.([2-9]|[1-9][0-9])\./", (string) file_get_contents( dirname( __DIR__, 3 ) . '/pass-captain.php' ) ) );
$x5_snap = (array) json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT buyer_snapshot FROM {$wpdb->prefix}wpcap_invoices WHERE invoice_id=%d", $x5_inv['invoice_id'] ) ), true );
$x5_old_snap = $x5_snap;
unset( $x5_old_snap['service_dates'] );
$wpdb->update( $wpdb->prefix . 'wpcap_invoices', array( 'buyer_snapshot' => wp_json_encode( $x5_old_snap ) ), array( 'invoice_id' => $x5_inv['invoice_id'] ) );
$x5_old = InvoiceApi::get( $x5_inv['invoice_id'] );
check( 'PASS-INV-15: an invoice issued before 1.2.0 takes the period from the order\'s occurrences, no date per line', array( 'start' => $x5_day, 'end' => $x5_end ) === $x5_old['service_period'] && '' === $x5_old['lines'][0]['service_date'] );
$wpdb->update( $wpdb->prefix . 'wpcap_invoices', array( 'buyer_snapshot' => wp_json_encode( $x5_snap ) ), array( 'invoice_id' => $x5_inv['invoice_id'] ) );

// Correction (credit note) for a refund of one 19 % ticket.
$x5_ticket19 = 0;
foreach ( tickets( $x5_order['order_id'], 'valid' ) as $t ) { if ( (int) $t->ticket_type_id === $x5_type ) { $x5_ticket19 = (int) $t->ticket_id; break; } }
$x5_mail_files = array();
$x5_attach = static function ( $files, $id, $type, $context ) use ( &$x5_mail_files ) {
	$file = InvoiceApi::temp_file( $type . '-' . $id . '.xml', '<Invoice/>' );
	$x5_mail_files[] = array( $file, $id, $type, $context );
	$files[] = $file;
	$files[] = '/nonexistent/x5.xml'; // Unreadable entries are dropped.
	return $files;
};
add_filter( 'wp_pass_captain_invoice_mail_attachments_v1', $x5_attach, 10, 4 );
$x5_mails_before = count( $GLOBALS['wpcap_test_mails'] );
$x5_refund = PassCaptain\Refunds::request( $x5_order['order_id'], array( $x5_ticket19 ), 'X5', 'x5-ref-' . uniqid() );
$x5_corr_created = array_values( array_filter( $x5_created, static fn( $c ) => 'correction' === $c[1] ) );
check( 'refund completed with a credit note; invoice_created_v1 fires with "correction"', 'completed' === $x5_refund['status'] && 1 === count( $x5_corr_created ) && $x5_inv['invoice_id'] === $x5_corr_created[0][2]['invoice_id'] && (int) $x5_refund['refund_id'] === $x5_corr_created[0][2]['refund_id'] );
$x5_corr = InvoiceApi::get( $x5_corr_created[0][0] ?? 0, 'correction' );
check( 'correction read API: negative amounts, VAT rate of the refunded ticket, reference to the original invoice', is_array( $x5_corr ) && 'correction' === $x5_corr['type'] && -2380 === $x5_corr['totals']['gross_minor'] && -380 === $x5_corr['totals']['vat_minor'] && 1 === count( $x5_corr['lines'] ) && 19.0 === $x5_corr['lines'][0]['vat_rate'] && $x5_inv['invoice_id'] === $x5_corr['references']['invoice_id'] && $x5_inv['invoice_number'] === $x5_corr['references']['invoice_number'] && (int) $x5_refund['refund_id'] === $x5_corr['references']['refund_id'] );
check( 'invoice lists its corrections', array( (int) $x5_corr['id'] ) === InvoiceApi::get( $x5_inv['invoice_id'] )['corrections'] );
check( 'PASS-INV-15: the correction carries the service period of its invoice', $x5_corr['service_period'] === InvoiceApi::get( $x5_inv['invoice_id'] )['service_period'] && $x5_corr['service_date'] === InvoiceApi::get( $x5_inv['invoice_id'] )['service_date'] );
$x5_refund_mails = array_slice( $GLOBALS['wpcap_test_mails'], $x5_mails_before );
$x5_refund_mail = array_values( array_filter( $x5_refund_mails, static fn( $m ) => 'x5-inv@example.com' === $m['to'] ) );
$x5_corr_hook = array_values( array_filter( $x5_mail_files, static fn( $c ) => 'correction' === $c[2] ) );
check( 'refund confirmation carries the add-on attachment of the correction (filter gets id, type, context)', 1 === count( $x5_corr_hook ) && (int) $x5_corr['id'] === $x5_corr_hook[0][1] && 'refund_confirmation' === $x5_corr_hook[0][3]['mail'] && $x5_refund_mail && isset( $x5_refund_mail[0]['files'][ 'correction-' . $x5_corr['id'] . '.xml' ] ) && 1 === count( $x5_refund_mail[0]['files'] ) );
check( 'temporary attachment files are deleted after sending', ! is_file( $x5_corr_hook[0][0] ) );
// Ticket mail of an order that has an invoice (resend with new codes, auto invoice on payment).
Settings::update( array( 'auto_invoice' => 1 ) );
wpcap_test_reset_transients();
$x5_auto = Orders::create( array( 'email' => 'x5-auto@example.com', 'items' => array( array( 'ticket_type_id' => $x5_type, 'quantity' => 1 ) ), 'idempotency_key' => 'x5-auto-' . uniqid() ) );
$x5_mails_before = count( $GLOBALS['wpcap_test_mails'] );
Orders::confirm_manual_payment( $x5_auto['order_id'] );
Settings::update( array( 'auto_invoice' => 0 ) );
$x5_auto_inv = (int) $wpdb->get_var( "SELECT invoice_id FROM {$p}wpcap_invoices WHERE order_id=" . (int) $x5_auto['order_id'] );
$x5_ticket_mail = array_values( array_filter( array_slice( $GLOBALS['wpcap_test_mails'], $x5_mails_before ), static fn( $m ) => 'x5-auto@example.com' === $m['to'] && $m['files'] ) );
$x5_inv_hook = array_values( array_filter( $x5_mail_files, static fn( $c ) => 'invoice' === $c[2] && $x5_auto_inv === $c[1] ) );
check( 'ticket mail of an invoiced order carries the add-on attachment ("invoice", context mail=tickets)', $x5_auto_inv && 1 === count( $x5_inv_hook ) && 'tickets' === $x5_inv_hook[0][3]['mail'] && $x5_ticket_mail && isset( $x5_ticket_mail[0]['files'][ 'invoice-' . $x5_auto_inv . '.xml' ] ) );
remove_filter( 'wp_pass_captain_invoice_mail_attachments_v1', $x5_attach, 10 );
$x5_plain = Invoices::issue_for_order( $x5_auto['order_id'] );
$x5_mail_in = array( 'subject' => 's', 'body' => 'b', 'attachments' => array() );
check( 'without a filter the ticket mail stays unchanged', $x5_mail_in === InvoiceApi::filter_ticket_mail( $x5_mail_in, Orders::get( $x5_auto['order_id'] ), array() ) && array() === InvoiceApi::correction_mail_attachments( $x5_refund['refund_id'], $x5_order['order_id'] ) );

// Links on the invoice views.
$x5_link_calls = array();
$x5_links = static function ( $links, $id, $type, $context ) use ( &$x5_link_calls ) {
	$x5_link_calls[] = array( $id, $type, $context );
	$links[] = array( 'label' => 'XRechnung (XML)', 'url' => 'https://example.test/?x5_xml=' . $id . ( isset( $context['access_token'] ) ? '&t=' . $context['access_token'] : '' ), 'download' => true );
	$links[] = array( 'label' => 'Böse', 'url' => 'javascript:alert(1)' );
	$links[] = array( 'label' => '', 'url' => 'https://example.test/' );
	return $links;
};
$x5_html_default = Invoices::render_html( $x5_inv['invoice_id'] );
add_filter( 'wp_pass_captain_invoice_links_v1', $x5_links, 10, 4 );
$x5_html = Invoices::render_html( $x5_inv['invoice_id'] );
check( 'invoice view shows valid add-on links only (escaped, download attribute)', false !== strpos( $x5_html, 'XRechnung (XML)' ) && false !== strpos( $x5_html, ' download' ) && false === strpos( $x5_html, 'javascript:' ) && 'invoice_view' === end( $x5_link_calls )[2]['location'] && 'admin' === end( $x5_link_calls )[2]['audience'] );
$x5_token = p1u_access_token( Orders::get( $x5_order['order_id'] )->public_id );
$x5_portal = PassCaptain\OrderPortal::render_page( Orders::get( $x5_order['order_id'] ), $x5_token );
$x5_portal_call = end( $x5_link_calls );
check( 'order portal shows the links with the customer context (access token for the add-on download)', false !== strpos( $x5_portal, 'XRechnung (XML)' ) && 'portal' === $x5_portal_call[2]['location'] && 'customer' === $x5_portal_call[2]['audience'] && $x5_token === $x5_portal_call[2]['access_token'] && $x5_inv['invoice_id'] === $x5_portal_call[0] );
check( 'add-on can verify the buyer link (for_order_access) – wrong token gives 0', $x5_inv['invoice_id'] === InvoiceApi::for_order_access( Orders::get( $x5_order['order_id'] )->public_id, $x5_token ) && 0 === InvoiceApi::for_order_access( Orders::get( $x5_order['order_id'] )->public_id, str_repeat( 'a', 64 ) ) );
check( 'admin may view the invoice and the correction (current_user_can_view)', InvoiceApi::current_user_can_view( $x5_inv['invoice_id'] ) && InvoiceApi::current_user_can_view( $x5_corr['id'], 'correction' ) );
remove_filter( 'wp_pass_captain_invoice_links_v1', $x5_links, 10 );
check( 'without the links filter the invoice view is unchanged (no extra block)', $x5_html_default === Invoices::render_html( $x5_inv['invoice_id'] ) && false === strpos( $x5_html_default, 'wpcap-invoice-extra' ) && false === strpos( PassCaptain\OrderPortal::render_page( Orders::get( $x5_order['order_id'] ), $x5_token ), 'wpcap-invoice-links' ) );

// Settlements: created action and read API.
$x5_m = gmdate( 'Y-m', strtotime( 'first day of -2 month' ) );
$x5_pid = X5Partners::save( array( 'name' => 'X5 Veranstalter GmbH', 'billing_address' => "Weg 1\n10115 Berlin", 'vat_id' => 'DE999999999', 'currency' => 'EUR', 'commission_bp' => 1000, 'fee_per_ticket_minor' => 50, 'fee_tax_rate_bp' => 1900, 'settlement_start' => $x5_m . '-01' ) );
$x5_pid = is_array( $x5_pid ) ? (int) $x5_pid['partner_id'] : (int) $x5_pid;
$x5_stype = make_type( 20, 10, 1190 );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET tax_rate_bp=1900 WHERE ticket_type_id=$x5_stype" );
X5Partners::set_events( $x5_pid, array( pa_event_of_type( $x5_stype ) ) );
wpcap_test_reset_transients();
pa_order( array( array( 'ticket_type_id' => $x5_stype, 'quantity' => 2 ) ), 'x5-set', gmdate( 'Y-m-d H:i:s', strtotime( $x5_m . '-15 12:00:00 UTC' ) ) );
$x5_draft = X5Settlements::create_draft( $x5_pid, $x5_m );
$x5_sid = is_array( $x5_draft ) ? (int) ( $x5_draft['settlement_id'] ?? 0 ) : ( is_object( $x5_draft ) ? (int) $x5_draft->settlement_id : (int) $x5_draft );
check( 'settlement drafts are no documents (read API null, no action)', $x5_sid && null === InvoiceApi::get( $x5_sid, 'settlement' ) && ! array_filter( $x5_created, static fn( $c ) => 'settlement' === $c[1] ) );
$x5_fin = X5Settlements::finalize( $x5_sid );
$x5_set_created = array_values( array_filter( $x5_created, static fn( $c ) => 'settlement' === $c[1] ) );
check( 'finalizing fires invoice_created_v1 with "settlement" (partner, period, number)', ! is_wp_error( $x5_fin ) && 1 === count( $x5_set_created ) && $x5_sid === $x5_set_created[0][0] && $x5_pid === $x5_set_created[0][2]['partner_id'] && $x5_m === $x5_set_created[0][2]['period'] && (string) $x5_fin->document_number === $x5_set_created[0][2]['number'] );
$x5_set = InvoiceApi::get( $x5_sid, 'settlement' );
check( 'settlement read API: platform fee invoice (commission 2,00 + fees 1,00 + 19 % VAT)', is_array( $x5_set ) && 'settlement' === $x5_set['type'] && (string) $x5_fin->document_number === $x5_set['number'] && 300 === $x5_set['totals']['net_minor'] && 57 === $x5_set['totals']['vat_minor'] && 357 === $x5_set['totals']['gross_minor'] && 57 === $x5_sum( $x5_set['lines'], 'vat_minor' ) && 19.0 === $x5_set['lines'][0]['vat_rate'] && 'X5 Veranstalter GmbH' === $x5_set['buyer']['name'] && 'DE999999999' === $x5_set['buyer']['vat_id'] && $x5_m === $x5_set['references']['period'] );
remove_action( 'wp_pass_captain_invoice_created_v1', $x5_on_created, 10 );
$x5_throw = static function () { throw new RuntimeException( 'add-on down' ); };
add_action( 'wp_pass_captain_invoice_created_v1', $x5_throw );
wpcap_test_reset_transients();
$x5_o2 = Orders::create( array( 'email' => 'x5-throw@example.com', 'items' => array( array( 'ticket_type_id' => $x5_type, 'quantity' => 1 ) ), 'idempotency_key' => 'x5-throw-' . uniqid() ) );
Orders::confirm_manual_payment( $x5_o2['order_id'] );
$x5_i2 = Invoices::issue_for_order( $x5_o2['order_id'] );
remove_action( 'wp_pass_captain_invoice_created_v1', $x5_throw );
check( 'a failing invoice_created_v1 callback never breaks invoicing (logged)', is_array( $x5_i2 ) && $x5_i2['invoice_id'] > 0 );
foreach ( array( array( 'wpcap_order_paid', array( Invoices::class, 'maybe_auto_issue' ), 10 ), array( 'wpcap_refund_completed', array( Invoices::class, 'credit_note_for_refund' ), 10 ), array( 'wpcap_ticket_mail', array( InvoiceApi::class, 'filter_ticket_mail' ), 30 ) ) as $x5_hook ) { remove_filter( $x5_hook[0], $x5_hook[1], $x5_hook[2] ); }
foreach ( array( 'admin_post_wpcap_print_invoice', 'admin_post_wpcap_export_accounting', 'template_redirect' ) as $x5_hook ) {
	foreach ( $GLOBALS['wpcap_test_hooks'][ $x5_hook ][10] ?? array() as $x5_i => $x5_e ) { if ( is_array( $x5_e[0] ) && Invoices::class === $x5_e[0][0] ) { unset( $GLOBALS['wpcap_test_hooks'][ $x5_hook ][10][ $x5_i ] ); } }
}

/* ================================================================== (e) checkout gate */

echo "X5: checkout gate (waiting room)\n";
$x5_gate_calls = array();
$x5_gate_type = make_type( 10, 10, 1000 );
$x5_gate_event = pa_event_of_type( $x5_gate_type );
$x5_gate_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$x5_gate_type" );
$x5_gate = static function ( $allowed, $context ) use ( &$x5_gate_calls ) {
	$x5_gate_calls[] = $context;
	return new WP_Error( 'x5_waiting_room', 'Du bist in der Warteschlange.', array( 'status' => 429, 'retry_after' => 30 ) );
};
wpcap_test_reset_transients();
$x5_free_key = 'x5-gate0-' . uniqid();
$x5_free = Orders::create( array( 'email' => 'x5-gate0@example.com', 'items' => array( array( 'ticket_type_id' => $x5_gate_type, 'quantity' => 1 ) ), 'idempotency_key' => $x5_free_key ) );
check( 'without a callback the gate allows (true) and checkout works', true === CheckoutGate::check( array( 'items' => array( $x5_gate_type => 1 ) ) ) && ! is_wp_error( $x5_free ) );
add_filter( 'wp_pass_captain_checkout_allowed_v1', $x5_gate, 10, 2 );
$x5_orders_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_orders" );
wpcap_test_reset_transients();
$x5_refused = Orders::create( array( 'email' => 'x5-gate1@example.com', 'items' => array( array( 'ticket_type_id' => $x5_gate_type, 'quantity' => 2 ) ), 'idempotency_key' => 'x5-gate1' ) );
$x5_ctx = end( $x5_gate_calls );
check( 'native checkout is refused with the gate error (status 429, retry_after) and creates nothing', is_wp_error( $x5_refused ) && 'x5_waiting_room' === $x5_refused->get_error_code() && 429 === $x5_refused->get_error_data()['status'] && 30 === $x5_refused->get_error_data()['retry_after'] && $x5_orders_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_orders" ) );
check( 'gate context: version, source native, phase checkout, items, ticket type/occurrence/event ids, e-mail, key', 1 === $x5_ctx['version'] && 'native' === $x5_ctx['source'] && 'checkout' === $x5_ctx['phase'] && array( $x5_gate_type => 2 ) === $x5_ctx['items'] && array( $x5_gate_type ) === $x5_ctx['ticket_type_ids'] && array( $x5_gate_occ ) === $x5_ctx['occurrence_ids'] && array( $x5_gate_event ) === $x5_ctx['event_ids'] && 'x5-gate1@example.com' === $x5_ctx['email'] && 'x5-gate1' === $x5_ctx['idempotency_key'] );
$x5_calls_before = count( $x5_gate_calls );
wpcap_test_reset_transients();
$x5_retry = Orders::create( array( 'email' => 'x5-gate0@example.com', 'items' => array( array( 'ticket_type_id' => $x5_gate_type, 'quantity' => 1 ) ), 'idempotency_key' => $x5_free_key ) );
check( 'a retry of an order created before (same idempotency key) is not gated', ! is_wp_error( $x5_retry ) && ! empty( $x5_retry['reused'] ) && $x5_free['order_id'] === $x5_retry['order_id'] && count( $x5_gate_calls ) === $x5_calls_before );
$x5_calls_before = count( $x5_gate_calls );
$x5_pos = Orders::create( array( 'email' => 'x5-pos@example.com', 'items' => array( array( 'ticket_type_id' => $x5_gate_type, 'quantity' => 1 ) ), 'idempotency_key' => 'x5-pos-' . uniqid() ), array( 'key_namespace' => 'pos', 'skip_rate_limit' => true, 'source' => 'pos' ) );
$x5_ext = Orders::create( array( 'email' => 'x5-ext@example.com', 'items' => array( array( 'ticket_type_id' => $x5_gate_type, 'quantity' => 1 ) ), 'idempotency_key' => 'x5-ext-' . uniqid() ), array( 'external' => true, 'key_namespace' => 'woocommerce', 'currency' => 'EUR' ) );
$x5_wl = Orders::create( array( 'email' => 'x5-wl@example.com', 'items' => array( array( 'ticket_type_id' => $x5_gate_type, 'quantity' => 1 ) ), 'idempotency_key' => 'x5-wl-' . uniqid(), 'accept_terms' => 1 ), array( 'key_namespace' => 'waitlist', 'skip_rate_limit' => true, 'source' => 'waitlist' ) );
check( 'box office, trusted adapters (WooCommerce order processing) and waitlist offers bypass the gate', ! is_wp_error( $x5_pos ) && ! is_wp_error( $x5_ext ) && ! is_wp_error( $x5_wl ) && count( $x5_gate_calls ) === $x5_calls_before );
// REST checkout: error with status, request in the context, Retry-After header.
wpcap_test_reset_transients();
$x5_req = p1u_request( array( 'email' => 'x5-rest@example.com', 'items' => array( array( 'ticket_type_id' => $x5_gate_type, 'quantity' => 1 ) ), 'provider' => 'manual', 'idempotency_key' => 'x5-rest-' . uniqid() ) );
$x5_providers_before = Settings::get( 'enabled_providers' );
Settings::update( array( 'enabled_providers' => array( 'manual' ) ) );
PassCaptain\Payments\Registry::reset();
$x5_rest = Controller::checkout( $x5_req );
Settings::update( array( 'enabled_providers' => $x5_providers_before ) );
check( 'REST checkout passes the gate error to the buyer (429) with the request in the context' . ( is_wp_error( $x5_rest ) && 'x5_waiting_room' === $x5_rest->get_error_code() ? '' : ' – got ' . ( is_wp_error( $x5_rest ) ? $x5_rest->get_error_code() . ' ' . $x5_rest->get_error_message() : wp_json_encode( p1u_data( $x5_rest ) ) ) ), is_wp_error( $x5_rest ) && 429 === $x5_rest->get_error_data()['status'] && 'Du bist in der Warteschlange.' === $x5_rest->get_error_message() && end( $x5_gate_calls )['request'] === $x5_req );
$x5_resp = new class( array( 'code' => 'x5_waiting_room', 'message' => 'x', 'data' => array( 'status' => 429, 'retry_after' => 30, 'checkout_gate' => true ) ), 429 ) extends WP_REST_Response { public $headers = array(); public function header( $k, $v ) { $this->headers[ $k ] = $v; } };
CheckoutGate::retry_after_header( $x5_resp );
$x5_other = new class( array( 'code' => 'other', 'message' => 'x', 'data' => array( 'status' => 429, 'retry_after' => 30 ) ), 429 ) extends WP_REST_Response { public $headers = array(); public function header( $k, $v ) { $this->headers[ $k ] = $v; } };
CheckoutGate::retry_after_header( $x5_other );
check( 'REST responses of gate refusals get Retry-After (others do not)', array( 'Retry-After' => '30' ) === $x5_resp->headers && array() === $x5_other->headers );
// Service bookings.
list( $x5_svc_event ) = svc_event( 0 );
$x5_res = svc_resource( $x5_svc_event );
$x5_slot = svc_slot_at( $x5_res, 3, '10:00' );
$x5_book = svc_book( $x5_slot, 'x5-svc@example.com' );
$x5_svc_ctx = end( $x5_gate_calls );
check( 'customer service booking is refused by the gate (source service_booking, phase booking, slot, event)', is_wp_error( $x5_book ) && 429 === $x5_book->get_error_data()['status'] && 'service_booking' === $x5_svc_ctx['source'] && 'booking' === $x5_svc_ctx['phase'] && (int) $x5_slot === $x5_svc_ctx['slot_id'] && array( (int) $x5_svc_event ) === $x5_svc_ctx['event_ids'] );
$x5_calls_before = count( $x5_gate_calls );
$x5_admin_book = svc_book( $x5_slot, 'x5-svc-admin@example.com', 1, array( 'actor' => 'admin' ) );
check( 'admin/system service bookings bypass the gate', ! is_wp_error( $x5_admin_book ) && count( $x5_gate_calls ) === $x5_calls_before );
// WooCommerce: add to cart (classic + Store API) and checkout.
$GLOBALS['wpcap_test_wc'] = new Wpcap_Ms_WC();
$GLOBALS['wpcap_test_wc']->cart = new Wpcap_Ms_Cart();
$x5_product = new WC_Product( 13901 );
$x5_product->update_meta_data( WooAdapter::PRODUCT_META, $x5_gate_type );
$GLOBALS['wpcap_test_products'][13901] = $x5_product;
$x5_plain_product = new WC_Product( 13902 );
$GLOBALS['wpcap_test_products'][13902] = $x5_plain_product;
$GLOBALS['wpcap_test_notices'] = array();
check( 'Woo classic add to cart of a ticket is refused with the gate message', false === WooGate::validate_add_to_cart( true, 13901, 2 ) && in_array( 'Du bist in der Warteschlange.', $GLOBALS['wpcap_test_notices'], true ) && 'woocommerce' === end( $x5_gate_calls )['source'] && 'cart' === end( $x5_gate_calls )['phase'] && array( $x5_gate_type => 2 ) === end( $x5_gate_calls )['items'] );
$x5_calls_before = count( $x5_gate_calls );
check( 'products without a ticket type are never gated', true === WooGate::validate_add_to_cart( true, 13902, 1 ) && count( $x5_gate_calls ) === $x5_calls_before );
$x5_route = null;
try { WooGate::store_api_validate_add_to_cart( $x5_product ); } catch ( Exception $e ) { $x5_route = $e; }
check( 'Store API add to cart throws a RouteException with the gate status (429)', $x5_route instanceof Automattic\WooCommerce\StoreApi\Exceptions\RouteException && 429 === (int) $x5_route->status && 'woocommerce_store_api' === end( $x5_gate_calls )['source'] );
$GLOBALS['wpcap_test_wc']->cart->cart_contents = array( 'k1' => array( 'data' => $x5_product, 'quantity' => 3 ) );
$x5_errors = new Wpcap_Ms_Errors();
WooGate::validate_checkout( array( 'billing_email' => 'x5-woo@example.com' ), $x5_errors );
check( 'Woo classic checkout gets the refusal as checkout error (phase checkout, cart items, e-mail)', 'Du bist in der Warteschlange.' === ( $x5_errors->errors['wpcap_checkout_not_allowed'] ?? '' ) && 'checkout' === end( $x5_gate_calls )['phase'] && array( $x5_gate_type => 3 ) === end( $x5_gate_calls )['items'] && 'x5-woo@example.com' === end( $x5_gate_calls )['email'] );
$x5_wc_order = make_wc_order( 13950, $x5_gate_type, 1 );
$x5_route = null;
try { WooGate::store_api_checkout( $x5_wc_order ); } catch ( Exception $e ) { $x5_route = $e; }
check( 'Store API checkout is refused before the hold (RouteException 429)', $x5_route instanceof Automattic\WooCommerce\StoreApi\Exceptions\RouteException && 429 === (int) $x5_route->status && 'checkout' === end( $x5_gate_calls )['phase'] && ! $x5_wc_order->get_meta( WooAdapter::ORDER_META ) );
remove_filter( 'wp_pass_captain_checkout_allowed_v1', $x5_gate, 10 );
// Normalisation and fail-open.
$x5_false = static fn( $a, $c ) => false;
add_filter( 'wp_pass_captain_checkout_allowed_v1', $x5_false, 10, 2 );
$x5_n = CheckoutGate::check( array( 'items' => array( $x5_gate_type => 1 ) ) );
remove_filter( 'wp_pass_captain_checkout_allowed_v1', $x5_false, 10 );
check( 'non-true results become a generic refusal with status 503', is_wp_error( $x5_n ) && 503 === $x5_n->get_error_data()['status'] && 'wpcap_checkout_not_allowed' === $x5_n->get_error_code() );
$x5_nostatus = static fn( $a, $c ) => new WP_Error( 'x5_closed', 'Geschlossen.' );
add_filter( 'wp_pass_captain_checkout_allowed_v1', $x5_nostatus, 10, 2 );
$x5_n = CheckoutGate::check( array( 'items' => array( $x5_gate_type => 1 ) ) );
remove_filter( 'wp_pass_captain_checkout_allowed_v1', $x5_nostatus, 10 );
check( 'errors without status default to 503', is_wp_error( $x5_n ) && 503 === $x5_n->get_error_data()['status'] && 'x5_closed' === $x5_n->get_error_code() );
$x5_boom = static function ( $a, $c ) { throw new RuntimeException( 'waiting room service down' ); };
add_filter( 'wp_pass_captain_checkout_allowed_v1', $x5_boom, 10, 2 );
wpcap_test_reset_transients();
$x5_open = Orders::create( array( 'email' => 'x5-open@example.com', 'items' => array( array( 'ticket_type_id' => $x5_gate_type, 'quantity' => 1 ) ), 'idempotency_key' => 'x5-open-' . uniqid() ) );
remove_filter( 'wp_pass_captain_checkout_allowed_v1', $x5_boom, 10 );
check( 'fail open: an exception in the gate never blocks the sale', ! is_wp_error( $x5_open ) && $x5_open['order_id'] > 0 );
$x5_allow = static fn( $a, $c ) => true;
add_filter( 'wp_pass_captain_checkout_allowed_v1', $x5_allow, 10, 2 );
wpcap_test_reset_transients();
$x5_ok = Orders::create( array( 'email' => 'x5-ok@example.com', 'items' => array( array( 'ticket_type_id' => $x5_gate_type, 'quantity' => 1 ) ), 'idempotency_key' => 'x5-ok-' . uniqid() ) );
remove_filter( 'wp_pass_captain_checkout_allowed_v1', $x5_allow, 10 );
check( 'gate returning true lets the checkout through', ! is_wp_error( $x5_ok ) );

/* ================================================================== (f) API scopes and Api\Auth */

echo "X5: developer API scopes and Api\\Auth::request_can()\n";
check( 'core scopes unchanged without filter', array( 'events.read', 'reports.read', 'slots.read' ) === array_keys( DeveloperKeys::scopes() ) /* merged: slots.read of the services line */ && array_keys( DeveloperKeys::scopes() ) === array_keys( ApiKeysPage::scope_labels() ) );
$x5_scopes = static function ( $scopes ) {
	$scopes['orders.write'] = 'Bestellungen anlegen (orders.write)';
	$scopes['events.read'] = 'Hijack';
	$scopes['Bad Scope'] = 'x';
	$scopes['tickets.write'] = '';
	return $scopes;
};
add_filter( 'wp_pass_captain_api_scopes_v1', $x5_scopes );
$x5_all = DeveloperKeys::scopes();
check( 'add-on scopes are registered (valid ids only, core labels kept, empty label = id)', array( 'events.read', 'reports.read', 'slots.read', 'orders.write', 'tickets.write' ) === array_keys( $x5_all ) && 'Eventdaten lesen (Name, Beschreibung, Status)' === $x5_all['events.read'] && 'tickets.write' === $x5_all['tickets.write'] );
check( 'add-on scopes appear on the API key admin page', isset( ApiKeysPage::scope_labels()['orders.write'] ) );
$x5_api_event = pa_event_of_type( make_type( 5 ) );
$x5_other_event = pa_event_of_type( make_type( 5 ) );
$x5_key = ApiKeysPage::admin_action( array( 'do' => 'create', 'event_id' => $x5_api_event, 'scopes' => array( 'orders.write', 'events.read' ), 'label' => 'x5', 'rate_limit' => 2 ) );
check( 'keys can be issued with add-on scopes from the admin page', is_array( $x5_key ) && array( 'orders.write', 'events.read' ) === $x5_key['scopes'] );
$x5_r = static function ( $key = null, array $params = array() ) {
	$r = new WP_REST_Request( 'POST' );
	if ( null !== $key ) { $r->set_header( 'X-WPCAP-Key', $key ); }
	foreach ( $params as $k => $v ) { $r->set_param( $k, $v ); }
	return $r;
};
$x5_code = static fn( $r ) => is_wp_error( $r ) ? $r->get_error_code() . ':' . (int) ( $r->get_error_data()['status'] ?? 0 ) : $r;
wpcap_test_reset_transients();
$x5_ok_req = $x5_r( $x5_key['key'], array( 'event_id' => $x5_api_event ) );
check( 'request_can: valid key, scope and event (route parameter event_id) → true; key_for() names the key owner', true === Auth::request_can( $x5_ok_req, 'orders.write' ) && $x5_key['api_key_id'] === Auth::key_for( $x5_ok_req )['api_key_id'] && 1 === Auth::key_for( $x5_ok_req )['user_id'] && null === Auth::key_for( $x5_r( $x5_key['key'] ) ) );
check( 'global wpcap_api_request_can() with explicit event id', true === wpcap_api_request_can( $x5_r( $x5_key['key'] ), 'events.read', $x5_api_event ) );
check( 'rate limit per key and minute shared with core routes → 429 with retry_after', 'wpcap_api_rate_limited:429' === $x5_code( Auth::request_can( $x5_r( $x5_key['key'], array( 'event_id' => $x5_api_event ) ), 'orders.write' ) ) && false === DeveloperKeys::authorize( $x5_r( $x5_key['key'] ), 'events.read', $x5_api_event ) && Auth::request_can( $x5_r( $x5_key['key'] ), 'orders.write', $x5_api_event )->get_error_data()['retry_after'] >= 1 );
wpcap_test_reset_transients();
check( 'missing key → 401', 'wpcap_api_key_missing:401' === $x5_code( Auth::request_can( $x5_r( null ), 'orders.write', $x5_api_event ) ) );
check( 'malformed or wrong secret → 401', 'wpcap_api_key_invalid:401' === $x5_code( Auth::request_can( $x5_r( 'abc' ), 'orders.write', $x5_api_event ) ) && 'wpcap_api_key_invalid:401' === $x5_code( Auth::request_can( $x5_r( $x5_key['prefix'] . '.' . str_repeat( '0', 64 ) ), 'orders.write', $x5_api_event ) ) );
check( 'other event → 403, missing scope → 403, unknown scope → 403, no event → 400', 'wpcap_api_key_event:403' === $x5_code( Auth::request_can( $x5_r( $x5_key['key'] ), 'orders.write', $x5_other_event ) ) && 'wpcap_api_key_scope:403' === $x5_code( Auth::request_can( $x5_r( $x5_key['key'] ), 'reports.read', $x5_api_event ) ) && 'wpcap_api_scope_unknown:403' === $x5_code( Auth::request_can( $x5_r( $x5_key['key'] ), 'nothing.here', $x5_api_event ) ) && 'wpcap_api_event_required:400' === $x5_code( Auth::request_can( $x5_r( $x5_key['key'] ), 'orders.write' ) ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_api_keys SET expires_at=%s WHERE api_key_id=%d", gmdate( 'Y-m-d H:i:s', time() - 60 ), $x5_key['api_key_id'] ) );
check( 'expired key → 401', 'wpcap_api_key_invalid:401' === $x5_code( Auth::request_can( $x5_r( $x5_key['key'] ), 'orders.write', $x5_api_event ) ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_api_keys SET expires_at=NULL WHERE api_key_id=%d", $x5_key['api_key_id'] ) );
DeveloperKeys::revoke( $x5_key['api_key_id'] );
check( 'revoked key → 401', 'wpcap_api_key_invalid:401' === $x5_code( Auth::request_can( $x5_r( $x5_key['key'] ), 'orders.write', $x5_api_event ) ) );
$GLOBALS['wpcap_test_user_can'] = static fn( $u, $c ) => 'manage_options' !== $c;
$x5_key2 = DeveloperKeys::issue( $x5_api_event, array( 'orders.write' ), 'x5-noaccess' );
check( 'key owner without access to the event → 403 (same rule as core routes)', 'wpcap_api_key_event:403' === $x5_code( Auth::request_can( $x5_r( $x5_key2['key'] ), 'orders.write', $x5_api_event ) ) );
unset( $GLOBALS['wpcap_test_user_can'] );
remove_filter( 'wp_pass_captain_api_scopes_v1', $x5_scopes );
wpcap_test_reset_transients();
check( 'after removing the add-on scope its keys no longer pass (scope unknown)', 'wpcap_api_scope_unknown:403' === $x5_code( Auth::request_can( $x5_r( $x5_key2['key'] ), 'orders.write', $x5_api_event ) ) && is_wp_error( DeveloperKeys::issue( $x5_api_event, array( 'orders.write' ), 'x5-gone' ) ) );
$x5_core_key = DeveloperKeys::issue( $x5_api_event, array( 'events.read' ), 'x5-core' );
check( 'core routes keep working with authorize() (user id) for core scopes', 1 === DeveloperKeys::authorize( $x5_r( $x5_core_key['key'] ), 'events.read', $x5_api_event ) && false === DeveloperKeys::authorize( $x5_r( $x5_core_key['key'] ), 'reports.read', $x5_api_event ) );

/* ================================================================== guard */

echo "X5: no licence code in the new extension points\n";
$x5_src = '';
foreach ( array( 'src/Brand.php', 'src/Api/Invoices.php', 'src/Api/Auth.php', 'src/Sales/CheckoutGate.php', 'src/Integrations/WooCommerce/CheckoutGate.php', 'src/functions.php' ) as $x5_file ) { $x5_src .= (string) file_get_contents( WPCAP_PATH . $x5_file ); }
check( 'extension point classes contain no licence or entitlement checks', ! preg_match( '/licen[cs]e|entitlement|premium_active|is_pro\b/i', $x5_src ) );

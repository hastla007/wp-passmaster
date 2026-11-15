<?php
/**
 * R7 (audit round 2, expert #9): start screen key figures and setup checklist (Admin\Dashboard),
 * grouped admin menu and "Funktionen" (menu entries of unused modules hidden, modules keep running).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\Dashboard as R7iDashboard;
use PassCaptain\Admin\Menu as R7iMenu;
use PassCaptain\Settings as R7iSettings;

global $wpdb;
$p = $wpdb->prefix;
wpcap_test_reset_transients();
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; } }

echo "R7 dashboard: key figures, checklist, menu groups, hidden modules\n";
$r7i_before = R7iDashboard::kpis( null );
$r7i_type = make_type( 20, 10, 1500 );
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET starts_at=%s WHERE occurrence_id=(SELECT occurrence_id FROM %i WHERE ticket_type_id=%d)', $p . 'wpcap_occurrences', gmdate( 'Y-m-d H:i:s', time() + 3 * DAY_IN_SECONDS ), $p . 'wpcap_ticket_types', $r7i_type ) );
$r7i_o = PassCaptain\Orders::create( array( 'email' => 'r7i@example.com', 'items' => array( array( 'ticket_type_id' => $r7i_type, 'quantity' => 2 ) ), 'idempotency_key' => 'r7i-' . microtime() ), array( 'skip_rate_limit' => true ) );
$r7i_mid = R7iDashboard::kpis( null );
check( 'unpaid order with active hold counts as open payment', $r7i_mid['unpaid'] === $r7i_before['unpaid'] + 1 );
PassCaptain\Orders::confirm_manual_payment( $r7i_o['order_id'] );
$r7i_after = R7iDashboard::kpis( null );
$r7i_paid = PassCaptain\Orders::get( $r7i_o['order_id'] );
check( 'paid order counts today: orders +1 (' . $r7i_before['orders_today'] . ' → ' . $r7i_after['orders_today'] . ', status ' . $r7i_paid->payment_status . ')', $r7i_after['orders_today'] === $r7i_before['orders_today'] + 1 );
check( 'paid order counts today: revenue +30,00 EUR', ( $r7i_after['revenue_today']['EUR'] ?? 0 ) === ( $r7i_before['revenue_today']['EUR'] ?? 0 ) + 3000 );
check( 'tickets of the next 7 days +2 (' . $r7i_before['tickets_next_7d'] . ' → ' . $r7i_after['tickets_next_7d'] . ')', $r7i_after['tickets_next_7d'] === $r7i_before['tickets_next_7d'] + 2 );
$r7i_event = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT o.event_id FROM %i tt INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=%d', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $r7i_type ) );
$r7i_scoped = R7iDashboard::kpis( array( $r7i_event ) );
$r7i_none = R7iDashboard::kpis( array( 999999999 ) );
check( 'scope limits the figures to the user\'s events', 1 === $r7i_scoped['orders_today'] && 0 === $r7i_none['orders_today'] && 0 === $r7i_none['tickets_next_7d'] );

$r7i_settings = array( 'seller_name' => R7iSettings::get( 'seller_name' ), 'seller_address' => R7iSettings::get( 'seller_address' ), 'hidden_modules' => R7iSettings::get( 'hidden_modules' ) );
R7iSettings::update( array( 'seller_name' => '', 'seller_address' => '' ) );
$r7i_list = R7iDashboard::checklist();
check( 'checklist: missing seller is open, sellable event is done', false === $r7i_list['seller'][0] && true === $r7i_list['event'][0] && 6 === count( $r7i_list ) );
R7iSettings::update( array( 'seller_name' => 'Kulturverein', 'seller_address' => 'Weg 1, Wien' ) );
check( 'checklist: seller done after entering name and address', true === R7iDashboard::checklist()['seller'][0] );

check( 'hidden modules: only known modules with admin pages are accepted', array( 'Sms', 'Wallet' ) === R7iDashboard::sanitize_hidden( array( 'Sms', 'Wallet', 'Services', 'Evil' ) ) );
R7iSettings::update( array( 'hidden_modules' => array( 'Sms' ) ) );
list( $r7i_visible, $r7i_hidden ) = PassCaptain\Modules::menu_pages();
$r7i_slugs = static fn( $pages ) => array_map( static fn( $pg ) => (string) $pg[0], $pages );
check( 'hidden module pages leave the menu but stay registered (links work)', R7iDashboard::module_hidden( 'PassCaptain\\Modules\\Sms' ) && $r7i_hidden && ! array_intersect( $r7i_slugs( $r7i_hidden ), $r7i_slugs( $r7i_visible ) ) && count( $r7i_visible ) + count( $r7i_hidden ) === count( PassCaptain\Modules::admin_pages() ) );
R7iSettings::update( $r7i_settings );

$r7i_sorted = R7iMenu::group_submenu( array( array( 'Einstellungen', 'x', 'wpcap-settings' ), array( 'Events', 'x', 'wp-pass-captain' ), array( 'Check-ins', 'x', 'wpcap-checkins' ), array( 'Bestellungen', 'x', 'wpcap-orders' ), array( 'SMS', 'x', 'wpcap-sms' ), array( 'Berichte', 'x', 'wpcap-reports' ) ) );
check( 'menu grouped: Verkauf, Einlass, Auswertung, Konfiguration, System (stable inside a group)', array( 'wp-pass-captain', 'wpcap-orders', 'wpcap-checkins', 'wpcap-reports', 'wpcap-sms', 'wpcap-settings' ) === array_map( static fn( $i ) => $i[2], $r7i_sorted ) );
check( 'group separators start at the first entry of each further group', array( 'wpcap-checkins' => 'entry', 'wpcap-reports' => 'analysis', 'wpcap-sms' => 'setup', 'wpcap-settings' => 'system' ) === R7iMenu::group_starts() );

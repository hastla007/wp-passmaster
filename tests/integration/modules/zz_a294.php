<?php
/**
 * A294: without an available payment method the event card says "Online-Verkauf derzeit nicht
 * möglich" (no order form that fails on submit) and administrators see "Keine verfügbare Zahlungsart".
 * Included from run.php (uses check()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\Menu;
use PassCaptain\Orders;
use PassCaptain\PublicEvents;
use PassCaptain\Settings;

require_once __DIR__ . '/zz_r6_stubs.inc';
if ( ! function_exists( 'locate_template' ) ) { function locate_template( $t ) { return ''; } }
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $t ) { return '<p>' . $t . '</p>'; } }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) {} }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) {} }

global $wpdb;
$p = $wpdb->prefix;
echo "A294: missing payment method shown on the card and to administrators\n";
$a294_saved = get_option( Settings::OPTION, array() );
$a294_now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'A294 Konzert', 'slug' => 'a294-' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $a294_now, 'updated_at' => $a294_now ) );
$a294_event = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $a294_event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 9 * DAY_IN_SECONDS ), 'timezone' => 'Europe/Vienna', 'location' => 'Halle', 'capacity' => 0, 'status' => 'scheduled' ) );
$a294_occ = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $a294_occ, 'name' => 'Stehplatz', 'price_minor' => 2000, 'currency' => 'CHF', 'capacity' => 0, 'max_per_order' => 10, 'status' => 'active' ) );
$a294_render = static function () use ( $a294_event ) { Orders::flush_availability_cache(); return PublicEvents::render_shortcode( array( 'event' => $a294_event ) ); };

Settings::update( array( 'enabled_providers' => array(), 'require_terms' => 0 ) );
$a294_html = $a294_render();
// A456: the form is always part of the card (a cached page may become bookable); without a payment
// method it is hidden and the note is visible.
check( 'card without payment method: "Online-Verkauf derzeit nicht möglich" visible, order form (e-mail, submit) hidden, quantity disabled', 1 === preg_match( '/class="wpcap-no-payment" role="status">Online-Verkauf derzeit nicht möglich/', $a294_html ) && 1 === preg_match( '/<div class="wpcap-buy" hidden>/', $a294_html ) && 1 === preg_match( '/class="wpcap-qty"[^>]* disabled>/', $a294_html ) );
check( 'ticket types and prices stay visible', false !== strpos( $a294_html, 'Stehplatz' ) );

check( 'currencies_without_payment lists the currency of the bookable date', in_array( 'CHF', Menu::currencies_without_payment(), true ) );
$_GET['page'] = 'pass-captain';
ob_start(); Menu::missing_payment_notice(); $a294_notice = ob_get_clean();
$_GET['page'] = 'other-plugin';
ob_start(); Menu::missing_payment_notice(); $a294_elsewhere = ob_get_clean();
$_GET['page'] = 'pass-captain';
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
ob_start(); Menu::missing_payment_notice(); $a294_denied = ob_get_clean();
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'admin notice "Keine verfügbare Zahlungsart" on plugin pages for administrators, with link to the payments tab', false !== strpos( $a294_notice, 'Keine verfügbare Zahlungsart für' ) && false !== strpos( $a294_notice, 'CHF' ) && false !== strpos( $a294_notice, 'tab=payments' ) && '' === $a294_elsewhere && '' === $a294_denied );

// Bank transfer configured: the card offers the order form again, no notice for CHF.
Settings::update( array( 'enabled_providers' => array( 'manual' ), 'manual_instructions' => 'Bitte überweisen.' ) );
$a294_ok = $a294_render();
check( 'with an available payment method the order form is back', 1 === preg_match( '/class="wpcap-no-payment" role="status" hidden>/', $a294_ok ) && 1 === preg_match( '/<div class="wpcap-buy">/', $a294_ok ) && false !== strpos( $a294_ok, 'name="email"' ) && false !== strpos( $a294_ok, 'wpcap-submit' ) );
ob_start(); Menu::missing_payment_notice(); $a294_none = ob_get_clean();
check( 'no notice once a payment method is available', '' === $a294_none && ! in_array( 'CHF', Menu::currencies_without_payment(), true ) );
unset( $_GET['page'] );

$wpdb->update( "{$p}wpcap_occurrences", array( 'status' => 'cancelled' ), array( 'occurrence_id' => $a294_occ ) );
$wpdb->update( "{$p}wpcap_events", array( 'status' => 'cancelled' ), array( 'event_id' => $a294_event ) );
update_option( Settings::OPTION, $a294_saved );
Settings::flush();

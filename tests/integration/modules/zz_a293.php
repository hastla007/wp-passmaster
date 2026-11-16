<?php
/**
 * A293: a published event no longer disappears silently – the listing shows an empty state when
 * nothing is left after filtering, a date whose sale has not started gets a "Vorverkauf ab …" card,
 * and the admin shows a publishing checklist (date, ticket type on sale, payment method).
 * Included from run.php (uses check()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\EventsPage;
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
echo "A293: empty state, presale card, publishing checklist\n";
$a293_saved = get_option( Settings::OPTION, array() );
Settings::update( array( 'enabled_providers' => array( 'manual' ), 'manual_instructions' => 'Bitte überweisen.', 'require_terms' => 0 ) );
$a293_now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'A293 Lesung', 'slug' => 'a293-' . uniqid(), 'description' => 'Text', 'status' => 'draft', 'created_at' => $a293_now, 'updated_at' => $a293_now ) );
$a293_event = (int) $wpdb->insert_id;
$a293_render = static function () use ( $a293_event ) { Orders::flush_availability_cache(); return PublicEvents::render_shortcode( array( 'event' => $a293_event ) ); };

// --- Publishing checklist -------------------------------------------------------------------------
check( 'checklist: no upcoming date', array( 'occurrence' ) === array_keys( EventsPage::publish_issues( $a293_event ) ) );
$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $a293_event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS ), 'timezone' => 'Europe/Vienna', 'location' => 'Bibliothek', 'capacity' => 0, 'status' => 'scheduled' ) );
$a293_occ = (int) $wpdb->insert_id;
check( 'checklist: date without active ticket type', array( 'ticket_type' ) === array_keys( EventsPage::publish_issues( $a293_event ) ) );
$a293_presale = gmdate( 'Y-m-d H:i:s', time() + 3 * DAY_IN_SECONDS );
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $a293_occ, 'name' => 'Normal', 'price_minor' => 1500, 'currency' => 'EUR', 'capacity' => 0, 'max_per_order' => 10, 'status' => 'active', 'sales_start' => $a293_presale ) );
$a293_type = (int) $wpdb->insert_id;
$a293_issues = EventsPage::publish_issues( $a293_event );
check( 'checklist: presale not started is named with its start', array( 'on_sale' ) === array_keys( $a293_issues ) && false !== strpos( $a293_issues['on_sale'], 'Vorverkauf beginnt' ) );

// Publishing with open points returns them as warning.
$a293_update = EventsPage::update_event( array( 'event_id' => $a293_event, 'name' => 'A293 Lesung', 'description' => 'Text', 'status' => 'published' ) );
check( 'publishing returns the open points as warning', is_array( $a293_update ) && false !== strpos( $a293_update['warning'] ?? '', 'noch nicht buchbar' ) && array( 'on_sale' ) === $a293_update['publish_issues'] );

// --- Listing: presale card instead of nothing ---------------------------------------------------
$a293_html = $a293_render();
check( 'listing shows a "Vorverkauf ab …" card for a date not on sale yet', false !== strpos( $a293_html, 'wpcap-event-presale' ) && false !== strpos( $a293_html, 'Vorverkauf ab' ) && false !== strpos( $a293_html, 'A293 Lesung' ) && false !== strpos( $a293_html, 'Bibliothek' ) && false === strpos( $a293_html, 'wpcap-checkout-form' ) && false === strpos( $a293_html, 'keine Veranstaltungen' ) );
add_filter( 'wpcap_presale_card_html', $a293_f = static fn( $html, $event, $start ) => '<div class="custom-presale">' . $start . '</div>', 10, 3 );
$a293_custom = $a293_render();
remove_filter( 'wpcap_presale_card_html', $a293_f );
check( 'wpcap_presale_card_html replaces the presale card', false !== strpos( $a293_custom, '<div class="custom-presale">' . $a293_presale . '</div>' ) );

// Sale over: nothing to show → empty state instead of an empty page.
$wpdb->update( "{$p}wpcap_ticket_types", array( 'sales_start' => gmdate( 'Y-m-d H:i:s', time() - 5 * DAY_IN_SECONDS ), 'sales_end' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ), array( 'ticket_type_id' => $a293_type ) );
$a293_empty = $a293_render();
check( 'listing with nothing left after filtering shows the empty state', false !== strpos( $a293_empty, 'wpcap-events-empty' ) && false === strpos( $a293_empty, '<article' ) );
$a293_issues = EventsPage::publish_issues( $a293_event );
check( 'checklist: sale ended is named', array( 'on_sale' ) === array_keys( $a293_issues ) && false !== strpos( $a293_issues['on_sale'], 'beendet' ) );

// On sale, but no payment method available.
$wpdb->update( "{$p}wpcap_ticket_types", array( 'sales_start' => null, 'sales_end' => null ), array( 'ticket_type_id' => $a293_type ) );
check( 'checklist: bookable event has no open points', array() === EventsPage::publish_issues( $a293_event ) );
check( 'listing shows the normal card again', false !== strpos( $a293_render(), 'wpcap-checkout-form' ) );
Settings::update( array( 'enabled_providers' => array() ) );
$a293_issues = EventsPage::publish_issues( $a293_event );
check( 'checklist: missing payment method for the currency', array( 'payment' ) === array_keys( $a293_issues ) && false !== strpos( $a293_issues['payment'], 'EUR' ) );

// Event screen shows the checklist above the event data.
$_GET = array( 'page' => 'pass-captain', 'view' => 'event', 'event_id' => $a293_event );
ob_start();
try { EventsPage::render(); } catch ( Throwable $e ) { echo 'EXCEPTION ' . $e->getMessage(); }
$a293_screen = ob_get_clean();
$_GET = array();
check( 'event screen shows "Veröffentlicht, aber noch nicht buchbar" with the open point', false === strpos( $a293_screen, 'EXCEPTION' ) && false !== strpos( $a293_screen, 'wpcap-publish-checklist' ) && false !== strpos( $a293_screen, 'wpcap-publish-payment' ) );

$wpdb->update( "{$p}wpcap_events", array( 'status' => 'cancelled' ), array( 'event_id' => $a293_event ) );
$wpdb->update( "{$p}wpcap_occurrences", array( 'status' => 'cancelled' ), array( 'occurrence_id' => $a293_occ ) );
update_option( Settings::OPTION, $a293_saved );
Settings::flush();

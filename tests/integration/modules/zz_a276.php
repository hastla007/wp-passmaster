<?php
/**
 * A276: WooCommerce shows occurrence times in the occurrence's time zone (Orders::format_start), not
 * in the site's: thank-you page / My Account / order meta box ticket table. Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Integrations\WooCommerce\OrderAdapter as A276Woo;

global $wpdb;
$p = $wpdb->prefix;

echo "A276: WooCommerce ticket table in the occurrence time zone\n";
$a276_now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'A276 Broadway', 'slug' => 'a276-' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $a276_now, 'updated_at' => $a276_now ) );
$a276_event = (int) $wpdb->insert_id;
// 00:30 UTC on 12 Dec is 19:30 on 11 Dec in New York (site time zone in the stubs: UTC).
$a276_year = (int) gmdate( 'Y' ) + 1;
$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $a276_event, 'starts_at' => "$a276_year-12-12 00:30:00", 'timezone' => 'America/New_York', 'location' => 'NYC', 'capacity' => 0, 'status' => 'scheduled' ) );
$a276_occ = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $a276_occ, 'name' => 'Orchestra', 'price_minor' => 0, 'currency' => 'EUR', 'capacity' => 5, 'max_per_order' => 5, 'status' => 'active' ) );
$a276_type = (int) $wpdb->insert_id;
$a276_order = Orders::create( array( 'email' => 'a276@example.com', 'items' => array( array( 'ticket_type_id' => $a276_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a276-1' ) );
$a276_wc = new WC_Order( 92760 );
$a276_wc->update_meta_data( A276Woo::ORDER_META, is_wp_error( $a276_order ) ? 0 : $a276_order['order_id'] );
ob_start();
A276Woo::render_customer_tickets( $a276_wc );
$a276_html = (string) ob_get_clean();
check( 'A276 the ticket table shows the local start time with its time zone', false !== strpos( $a276_html, "11.12.$a276_year 19:30 America/New_York" ) );
check( 'A276 the site-time (UTC) start is not shown', false === strpos( $a276_html, "12.12.$a276_year 00:30" ) );
if ( ! is_wp_error( $a276_order ) ) { Orders::cancel_order( $a276_order['order_id'], 'a276_cleanup' ); }
$wpdb->update( "{$p}wpcap_occurrences", array( 'status' => 'a276_done' ), array( 'occurrence_id' => $a276_occ ) );

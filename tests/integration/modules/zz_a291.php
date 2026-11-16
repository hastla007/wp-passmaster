<?php
/**
 * A291: "Zahlungsart einrichten" counts WooCommerce only when a published product is linked to an
 * active ticket type – an active WooCommerce alone sells no tickets.
 * Included from run.php (uses check(), make_type()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\GettingStarted;
use PassCaptain\Integrations\WooCommerce\OrderAdapter;
use PassCaptain\Settings;

require_once __DIR__ . '/zz_r6_stubs.inc';

global $wpdb;
$p = $wpdb->prefix;
echo "A291: WooCommerce counts as payment only with a linked ticket product\n";
$a291_saved = get_option( Settings::OPTION, array() );
$a291_by = static function ( array $items ) { $o = array(); foreach ( $items as $i ) { $o[ $i['key'] ] = $i; } return $o; };
Settings::update( array( 'enabled_providers' => array(), 'stripe_secret_key' => '', 'stripe_webhook_secret' => '' ) );

r6_posts_tables();
$a291_state = GettingStarted::state();
$a291_payment = $a291_by( GettingStarted::items( $a291_state ) )['payment'];
check( 'WooCommerce active without linked product: payment step stays open with a hint', true === $a291_state['woocommerce_active'] && false === $a291_state['woocommerce'] && ! $a291_payment['done'] && false !== strpos( $a291_payment['description'], 'kein veröffentlichtes Produkt' ) );

$a291_type = make_type( 10 );
$wpdb->insert( "{$p}posts", array( 'post_title' => 'Ticket', 'post_content' => '', 'post_type' => 'product', 'post_status' => 'draft' ) );
$a291_product = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}postmeta", array( 'post_id' => $a291_product, 'meta_key' => OrderAdapter::PRODUCT_META, 'meta_value' => (string) $a291_type ) );
check( 'a linked draft product does not count', false === OrderAdapter::has_linked_ticket_products() );
$wpdb->update( "{$p}posts", array( 'post_status' => 'publish' ), array( 'ID' => $a291_product ) );
$wpdb->update( "{$p}wpcap_ticket_types", array( 'status' => 'inactive' ), array( 'ticket_type_id' => $a291_type ) );
check( 'a product linked to an inactive ticket type does not count', false === OrderAdapter::has_linked_ticket_products() );
$wpdb->update( "{$p}wpcap_ticket_types", array( 'status' => 'active' ), array( 'ticket_type_id' => $a291_type ) );
$a291_state = GettingStarted::state();
$a291_payment = $a291_by( GettingStarted::items( $a291_state ) )['payment'];
check( 'published product linked to an active ticket type: payment step done via WooCommerce', true === $a291_state['woocommerce'] && $a291_payment['done'] && false !== strpos( $a291_payment['description'], 'WooCommerce-Checkout' ) );
check( 'items(): WooCommerce active alone is not a payment method', ! $a291_by( GettingStarted::items( array( 'woocommerce_active' => true ) ) )['payment']['done'] );

r6_posts_tables( false );
check( 'without posts tables (no WordPress) nothing is counted and nothing queried', false === OrderAdapter::has_linked_ticket_products() );
update_option( Settings::OPTION, $a291_saved );
Settings::flush();

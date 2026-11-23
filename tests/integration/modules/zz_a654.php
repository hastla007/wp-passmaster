<?php
// phpcs:ignoreFile
/**
 * A654: "pay later / purchase on account" gateways that set "processing" before the money arrives are
 * detected (admin warning) unless they are listed in wpcap_woocommerce_issue_on_completed_gateways;
 * docs/WOOCOMMERCE.md warns with an example.
 */
use PassCaptain\Integrations\WooCommerce\OrderAdapter;

echo "A654: WooCommerce pay-later gateways\n";
$a654_enabled = array( 'german_market_purchase_on_account' => 'Kauf auf Rechnung', 'bacs' => 'Überweisung', 'cod' => 'Nachnahme', 'stripe' => 'Kreditkarte', 'invoice' => 'Rechnung', 'my-pay-later' => '' );
$a654_found = OrderAdapter::pay_later_gateways( $a654_enabled );
check( 'A654: invoice / on-account gateways are reported, others not', array( 'german_market_purchase_on_account', 'invoice', 'my-pay-later' ) === array_keys( $a654_found ) && 'Kauf auf Rechnung' === $a654_found['german_market_purchase_on_account'] && 'my-pay-later' === $a654_found['my-pay-later'] );
$a654_filter = static function ( $gateways ) { $gateways[] = 'invoice'; $gateways[] = 'german_market_purchase_on_account'; return $gateways; };
add_filter( 'wpcap_woocommerce_issue_on_completed_gateways', $a654_filter );
check( 'A654: gateways listed in the filter (tickets at "completed") are not reported', array( 'my-pay-later' ) === array_keys( OrderAdapter::pay_later_gateways( $a654_enabled ) ) );
remove_filter( 'wpcap_woocommerce_issue_on_completed_gateways', $a654_filter );
check( 'A654: without WooCommerce gateways nothing is reported', array() === OrderAdapter::pay_later_gateways( array() ) );
check( 'A654: the admin warning is registered', method_exists( PassCaptain\Admin\Menu::class, 'pay_later_gateway_notice' ) );
$a654_doc = (string) file_get_contents( WPCAP_PATH . 'docs/WOOCOMMERCE.md' );
check( 'A654: WOOCOMMERCE.md warns about pay-later gateways with a filter example', false !== strpos( $a654_doc, 'Kauf auf Rechnung' ) && false !== strpos( $a654_doc, "add_filter( 'wpcap_woocommerce_issue_on_completed_gateways'" ) && false !== strpos( $a654_doc, 'vor dem Geldeingang' ) );

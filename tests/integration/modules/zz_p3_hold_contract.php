<?php
/**
 * TODO "Fähigkeit wpcap_woocommerce_hold_fulfilment versioniert anbieten" (core part): the filter is a public,
 * versioned hook of the add-on API (since 1.1, PublicApi::VERSION 1.1.0), documented in the public table of
 * docs/HOOKS.md and in docs/CONTRACTS.md; the contract constant OrderAdapter::HOLD_FULFILMENT stays 1 and the
 * core really applies the filter. Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Contracts;
use PassCaptain\Integrations\WooCommerce\OrderAdapter;
use PassCaptain\PublicApi;

echo "WooCommerce hold contract: wpcap_woocommerce_hold_fulfilment in the public add-on API\n";
$p3h_def = PublicApi::hooks()['wpcap_woocommerce_hold_fulfilment'] ?? null;
check( 'hold contract: the filter is a public add-on hook since API 1.1 (area orders)', array( 'filter', '1.1', 'orders' ) === $p3h_def );
check( 'hold contract: the add-on API is 1.1.0 (minor bump for the added hook), contract addon follows it, an add-on built against 1.0 stays compatible', '1.1.0' === PublicApi::VERSION && PublicApi::VERSION === Contracts::version( 'addon' ) && PublicApi::compatible( '1.0' ) && version_compare( PublicApi::VERSION, $p3h_def[1], '>=' ) );
$p3h_since_ok = true;
foreach ( PublicApi::hooks() as $p3h_hook => $p3h_entry ) {
	$p3h_since_ok = $p3h_since_ok && version_compare( $p3h_entry[1] . '.0', PublicApi::VERSION, '<=' );
}
check( 'hold contract: no public hook claims a newer "since" than the API version', $p3h_since_ok );
check( 'hold contract: the contract constant of the hold stays at version 1', 1 === OrderAdapter::HOLD_FULFILMENT );
$p3h_src = (string) file_get_contents( WPCAP_PATH . 'src/Integrations/WooCommerce/OrderAdapter.php' );
check( 'hold contract: the core applies the filter with the WooCommerce order', 1 === preg_match( "/apply_filters\(\s*'wpcap_woocommerce_hold_fulfilment',\s*false,\s*\\\$wc_order\s*\)/", $p3h_src ) );
$p3h_hooks_doc = (string) file_get_contents( WPCAP_PATH . 'docs/HOOKS.md' );
$p3h_contracts_doc = (string) file_get_contents( WPCAP_PATH . 'docs/CONTRACTS.md' );
check( 'hold contract: docs/HOOKS.md lists the hook in the public table (since 1.1) and names the API version', 1 === preg_match( '/^\| `wpcap_woocommerce_hold_fulfilment` \| Filter \| 1\.1 \|/m', $p3h_hooks_doc ) && false !== strpos( $p3h_hooks_doc, 'Version **' . PublicApi::VERSION . '**' ) );
check( 'hold contract: docs/CONTRACTS.md names add-on API ' . PublicApi::VERSION, false !== strpos( $p3h_contracts_doc, '| `addon` | ' . PublicApi::VERSION ) );

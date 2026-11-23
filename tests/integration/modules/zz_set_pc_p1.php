<?php
/**
 * SET-PC-P1/P2/P4: regression tests for the review findings of 23.11.2026.
 */
use PassCaptain\Settings;
use PassCaptain\SettingsManager;
use PassCaptain\SettingsSchema;

echo "SET-PC-P1: review findings\n";
$pp_saved = get_option( Settings::OPTION, array() );
// 1: money values in minor units survive export/import, presets and the CLI.
Settings::update( array( 'billing_required_threshold_minor' => 25000, 'refund_approval_threshold' => 15000 ) );
$pp_doc = SettingsManager::export();
$pp_prev = SettingsManager::preview( wp_json_encode( $pp_doc ) );
if ( 0 !== $pp_prev['changes'] || 0 !== $pp_prev['errors'] ) { echo '     debug: ' . wp_json_encode( array_filter( (array) ( $pp_prev['rows'] ?? $pp_prev ), static fn( $r ) => ! is_array( $r ) || ! empty( $r['changed'] ) || ! empty( $r['error'] ) ) ) . "\n"; }
check( 'P1-1: an unchanged export has no changes in the preview (money stays in minor units)', 0 === $pp_prev['changes'] && 0 === $pp_prev['errors'] );
Settings::update( array( 'billing_required_threshold_minor' => 100 ) );
SettingsManager::import( wp_json_encode( $pp_doc ) );
check( 'P1-1: import restores money values exactly', 25000 === (int) Settings::get( 'billing_required_threshold_minor' ) && 15000 === (int) Settings::get( 'refund_approval_threshold' ) );
check( 'P1-1: the DE preset does not propose its own threshold again', ! in_array( 'billing_required_threshold_minor', array_column( PassCaptain\CountryPresets::diff( 'DE' ), 'key' ), true ) );
check( 'P1-1: CLI money values are minor units like "get" prints them', 2500 === (int) PassCaptain\Ops\SettingsCli::set_value( 'billing_required_threshold_minor', '2500' ) );
check( 'P1-1: form input stays in major units', 1250 === SettingsSchema::validate( 'refund_approval_threshold', '12,50' ) );
// 3: field sanitize callbacks and prefix normalisation also apply outside the settings screen.
check( 'P1-3: an unreadable payment rule is refused by the CLI', is_wp_error( PassCaptain\Ops\SettingsCli::set_value( 'payment_rules', 'garbage | foo=1' ) ) );
check( 'P1-3: invoice prefix is normalised like on the settings screen', 'RECHNUNG20' === PassCaptain\Ops\SettingsCli::set_value( 'invoice_prefix', 'rechnung 2026/ä' ) );
// 6: resetting the proxy header also resets the derived header.
Settings::update( array( 'trusted_proxy_header' => 'cf_connecting_ip' ) );
SettingsManager::reset( array( 'trusted_proxy_header' ) );
$pp_raw = get_option( Settings::OPTION, array() );
check( 'P1-6: reset of the proxy header drops the derived client_ip_header', ! array_key_exists( 'client_ip_header', $pp_raw ) && ! array_key_exists( 'trusted_proxy_header', $pp_raw ) );
// 9: a reset key keeps following its default after later saves.
SettingsManager::reset( array( 'hold_minutes' ) );
Settings::update( array( 'campaign_max_codes' => 2 ) );
check( 'P1-9: a later save does not store defaults of reset keys', ! array_key_exists( 'hold_minutes', get_option( Settings::OPTION, array() ) ) );
// 2: net entry keeps the stored gross price when the net value is unchanged.
Settings::update( array( 'price_entry' => 'net' ) );
$pp_drift = 0;
foreach ( range( 100, 400 ) as $pp_gross ) {
	$pp_net = PassCaptain\Admin\EventsPage::gross_to_entry( $pp_gross, 1900 );
	if ( PassCaptain\Admin\EventsPage::entry_to_gross( $pp_net, 1900 ) !== $pp_gross ) { ++$pp_drift; }
}
check( 'P1-2: rounding can drift (why save keeps the old price for an unchanged net value)', $pp_drift > 0 );
$pp_src = (string) file_get_contents( WPCAP_PATH . 'src/Admin/EventsPage.php' );
check( 'P1-2: save_ticket_type keeps old->price_minor for an unchanged net value', false !== strpos( $pp_src, '(int) $price === self::gross_to_entry( (int) $old->price_minor, (int) $old->tax_rate_bp ) ) ? (int) $old->price_minor' ) );
// 7: payment rule amounts follow the decimals of the order currency.
Settings::update( array( 'payment_rules' => 'manual | max=1000' ) );
check( 'P2-7: rule amounts use the currency decimals (JPY 0, EUR 2)', PassCaptain\Payments\Rules::allows( 'manual', array( 'total_minor' => 1000, 'currency' => 'JPY' ) ) && ! PassCaptain\Payments\Rules::allows( 'manual', array( 'total_minor' => 1001, 'currency' => 'JPY' ) ) && PassCaptain\Payments\Rules::allows( 'manual', array( 'total_minor' => 100000, 'currency' => 'EUR' ) ) );
// 8: an own WooCommerce hold never shortens the WooCommerce hold.
Settings::update( array( 'woo_hold_minutes' => 5 ) );
$pp_hold = new ReflectionMethod( PassCaptain\Integrations\WooCommerce\OrderAdapter::class, 'hold_minutes' );
check( 'P2-8: a short WooCommerce hold setting does not undercut the automatic hold', $pp_hold->invoke( null ) >= PassCaptain\Orders::HOLD_MINUTES );
// 5: waitlist offers respect payment rules.
check( 'P2-5: waitlist offers filter the payment methods through the rules', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Waitlists.php' ), 'Payments\\Rules::filter( Payments\\Registry::enabled( $order->currency )' ) );
update_option( Settings::OPTION, $pp_saved );
Settings::flush();

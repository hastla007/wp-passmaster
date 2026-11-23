<?php
/**
 * SET-PC-24: countries of the billing address as a setting, optional region field, seller country as a checked choice.
 */
use PassCaptain\Billing;
use PassCaptain\Settings;
use PassCaptain\SettingsSchema;

echo "SET-PC-24: countries and address fields\n";
$sp24_saved = get_option( Settings::OPTION, array() );
check( 'SET-PC-24: without the setting the built-in list applies (unchanged)', Billing::builtin_countries() === Billing::countries() );
Settings::update( array( 'billing_countries' => 'CH,LI,US' ) );
check( 'SET-PC-24: the setting limits the billing countries (unknown codes shown by code)', array( 'CH', 'LI', 'US' ) === array_keys( Billing::countries() ) && 'US' === Billing::countries()['US'] );
check( 'SET-PC-24: a country outside the list is refused at checkout', is_wp_error( Billing::validate( array( 'first_name' => 'A', 'last_name' => 'B', 'street' => 'S 1', 'postcode' => '1010', 'city' => 'Wien', 'country' => 'AT' ) ) ) );
check( 'SET-PC-24: the region field is hidden by default and shown when switched on', false === strpos( Billing::fields_html( 'sp24' ), 'billing[region]' ) && ( static function () { Settings::update( array( 'billing_region_field' => 1 ) ); return false !== strpos( Billing::fields_html( 'sp24' ), 'billing[region]' ); } )() );
check( 'SET-PC-24: the region is kept and printed on the address', 'Zürich' === Billing::sanitize( array( 'region' => 'Zürich' ) )['region'] && in_array( 'Zürich', Billing::address_lines( array( 'first_name' => 'A', 'last_name' => 'B', 'city' => 'Winterthur', 'region' => 'Zürich', 'country' => 'CH' ) ), true ) );
check( 'SET-PC-24: country codes are checked', is_wp_error( SettingsSchema::validate( 'seller_country', 'Deutschland' ) ) && 'AT' === SettingsSchema::validate( 'seller_country', ' at ' ) && is_wp_error( SettingsSchema::validate( 'billing_countries', 'AT, DEU' ) ) && 'AT,DE' === SettingsSchema::validate( 'billing_countries', 'at;de at' ) );
ob_start();
PassCaptain\Admin\SettingsPage::render( 'invoices' );
$sp24_html = ob_get_clean();
check( 'SET-PC-24: the seller country is a selection', (bool) preg_match( '/<select[^>]+name="seller_country"/', $sp24_html ) );
update_option( Settings::OPTION, $sp24_saved );
Settings::flush();

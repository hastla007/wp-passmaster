<?php
/**
 * SET-PC-08: currencies and default currency as settings (filter keeps precedence); new prices use the default.
 */
use PassCaptain\Localization;
use PassCaptain\Settings;
use PassCaptain\SettingsSchema;

echo "SET-PC-08: currency settings\n";
$sp08_saved = get_option( Settings::OPTION, array() );
check( 'SET-PC-08: without settings the built-in list and EUR apply (unchanged behaviour)', Localization::DEFAULT_CURRENCIES === Localization::currencies() && 'EUR' === Localization::default_currency() );
Settings::update( array( 'currencies' => 'CHF', 'default_currency' => 'CHF' ) );
check( 'SET-PC-08: CHF without EUR: only CHF is offered and is the default', array( 'CHF' ) === Localization::currencies() && 'CHF' === Localization::default_currency() );
$sp08_c = PassCaptain\Campaigns::create( array( 'name' => 'SP08', 'code' => 'SP08CHF', 'kind' => 'fixed', 'value' => 100 ) );
check( 'SET-PC-08: a new discount code without currency takes the default currency', ! is_wp_error( $sp08_c ) && 'CHF' === (string) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT currency FROM {$GLOBALS['wpdb']->prefix}wpcap_campaigns WHERE campaign_id=%d", (int) ( $sp08_c["campaign_id"] ?? 0 ) ) ) );
check( 'SET-PC-08: a register without currency takes the default currency', ( static function () { $id = PassCaptain\BoxOffice\Registers::save( array( 'name' => 'SP08 Kasse' ) ); return ! is_wp_error( $id ) && 'CHF' === (string) PassCaptain\BoxOffice\Registers::get( (int) $id )->currency; } )() );
Settings::update( array( 'currencies' => 'CHF', 'default_currency' => 'EUR' ) );
check( 'SET-PC-08: the default currency is always offered', array( 'EUR', 'CHF' ) === Localization::currencies() && 'EUR' === Localization::default_currency() );
$sp08_f = static function () { return array( 'GBP' ); };
add_filter( 'wp_pass_captain_currencies_v1', $sp08_f );
check( 'SET-PC-08: the filter keeps precedence; the default falls back to the first offered currency', array( 'GBP' ) === Localization::currencies() && 'GBP' === Localization::default_currency() );
remove_filter( 'wp_pass_captain_currencies_v1', $sp08_f );
check( 'SET-PC-08: invalid codes are reported, not stored', is_wp_error( SettingsSchema::validate( 'default_currency', 'EURO' ) ) && is_wp_error( SettingsSchema::validate( 'currencies', 'CHF, EU' ) ) && 'CHF,EUR' === SettingsSchema::validate( 'currencies', 'chf; eur chf' ) && 'USD' === SettingsSchema::validate( 'default_currency', ' usd ' ) );
update_option( Settings::OPTION, $sp08_saved );
Settings::flush();

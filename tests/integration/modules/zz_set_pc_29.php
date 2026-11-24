<?php
/**
 * SET-PC-29: WooCommerce settings bundled in the Pass Captain settings (hold, issuing status, attendee data).
 */
use PassCaptain\Settings;
use PassCaptain\SettingsSchema;

echo "SET-PC-29: WooCommerce settings\n";
$sp29_saved = get_option( Settings::OPTION, array() );
$sp29_hold = new ReflectionMethod( PassCaptain\Integrations\WooCommerce\OrderAdapter::class, 'hold_minutes' );
$sp29_auto = $sp29_hold->invoke( null );
check( 'SET-PC-29: hold 0 = automatic (unchanged behaviour)', $sp29_auto >= PassCaptain\Orders::HOLD_MINUTES );
Settings::update( array( 'woo_hold_minutes' => 1440 ) );
check( 'SET-PC-29: an explicit longer hold time applies (it never shortens the automatic hold)', 1440 === $sp29_hold->invoke( null ) );
$sp29_f = static function () { return 30; };
add_filter( 'wpcap_woocommerce_hold_minutes', $sp29_f );
check( 'SET-PC-29: the filter keeps precedence', 30 === $sp29_hold->invoke( null ) );
remove_filter( 'wpcap_woocommerce_hold_minutes', $sp29_f );
check( 'SET-PC-29: hold minutes are limited (reported, not clamped)', is_wp_error( SettingsSchema::validate( 'woo_hold_minutes', 5000 ) ) );
check( 'SET-PC-29: attendee mode is a checked choice', is_wp_error( SettingsSchema::validate( 'woo_collect_attendees', 'maybe' ) ) && 'always' === SettingsSchema::validate( 'woo_collect_attendees', 'always' ) );
check( 'SET-PC-29: the section sits on the general settings tab', 'general' === SettingsSchema::get( 'woo_hold_minutes' )['tab'] && 'cod' === Settings::defaults()['woo_issue_on_completed_gateways'] );
update_option( Settings::OPTION, $sp29_saved );
Settings::flush();

<?php
/**
 * SET-PC-40: payment method rules by event, amount and billing country.
 */
use PassCaptain\Payments\Rules;
use PassCaptain\Settings;

echo "SET-PC-40: payment method rules\n";
$sp40_saved = get_option( Settings::OPTION, array() );
check( 'SET-PC-40: without rules every method is allowed (unchanged)', Rules::allows( 'manual', array( 'event_ids' => array( 3 ), 'total_minor' => 99999, 'country' => 'US' ) ) );
check( 'SET-PC-40: an unreadable rule line is reported with its number', is_wp_error( Rules::parse( "manual | max=500\nstripe | foo=1" ) ) && false !== strpos( Rules::parse( "manual | max=500\nstripe | foo=1" )->get_error_message(), '2' ) );
Settings::update( array( 'payment_rules' => "manual | max=500,00 | countries=DE,AT\nstripe | events=7\nstripe | min=1000" ) );
check( 'SET-PC-40: amount and country limit a method', Rules::allows( 'manual', array( 'total_minor' => 50000, 'country' => 'AT' ) ) && ! Rules::allows( 'manual', array( 'total_minor' => 50001, 'country' => 'AT' ) ) && ! Rules::allows( 'manual', array( 'total_minor' => 100, 'country' => 'CH' ) ) );
check( 'SET-PC-40: several lines are alternatives; events limit a method', Rules::allows( 'stripe', array( 'event_ids' => array( 7 ), 'total_minor' => 100 ) ) && Rules::allows( 'stripe', array( 'event_ids' => array( 8 ), 'total_minor' => 200000 ) ) && ! Rules::allows( 'stripe', array( 'event_ids' => array( 8 ), 'total_minor' => 500 ) ) );
check( 'SET-PC-40: a method without a rule stays allowed', Rules::allows( 'paypal', array( 'event_ids' => array( 8 ), 'total_minor' => 1, 'country' => 'US' ) ) );
check( 'SET-PC-40: unknown values are not checked (event card without amount)', Rules::allows( 'manual', array( 'event_ids' => array( 8 ) ) ) );
$sp40_list = Rules::filter( array( 'manual' => 1, 'stripe' => 2, 'paypal' => 3 ), array( 'event_ids' => array( 8 ), 'total_minor' => 500 ) );
check( 'SET-PC-40: filter removes methods whose rules do not match', array( 'manual', 'paypal' ) === array_keys( $sp40_list ) );
$sp40_saved_page = PassCaptain\Admin\SettingsPage::save( array( 'wpcap_tab' => 'payments', 'payment_rules' => 'manual | maximum=1' ) );
check( 'SET-PC-40: the settings screen reports an unreadable rule and keeps the stored rules', ! is_wp_error( $sp40_saved_page ) && false !== strpos( (string) Settings::get( 'payment_rules' ), 'manual | max=500,00' ) );
update_option( Settings::OPTION, $sp40_saved );
Settings::flush();

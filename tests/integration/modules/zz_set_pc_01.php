<?php
/**
 * SET-PC-01: settings register. Every key of Settings::defaults() is described (label and help), types
 * and limits come from one place, validate() reports instead of correcting silently, add-ons extend the
 * register through wp_pass_captain_settings_schema_v1. Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Settings;
use PassCaptain\SettingsSchema;

echo "SET-PC-01: settings register\n";
SettingsSchema::flush();
$sp_all = SettingsSchema::all();
$sp_defaults = Settings::defaults();
check( 'SET-PC-01: the register has an entry for every key of Settings::defaults() and nothing else', array() === array_diff_key( $sp_defaults, $sp_all ) && array() === array_diff_key( $sp_all, $sp_defaults ) );
// Backlog: keys still without label and help text. The list may only shrink.
$sp_backlog = array();
$sp_undescribed = SettingsSchema::undescribed();
check( 'SET-PC-01: every setting has a label and a help text (undescribed: ' . implode( ', ', array_diff( $sp_undescribed, $sp_backlog ) ) . ')', array() === array_diff( $sp_undescribed, $sp_backlog ) );
$sp_ok = true;
foreach ( $sp_all as $sp_key => $sp_entry ) {
	if ( ! in_array( $sp_entry['type'], SettingsSchema::TYPES, true ) || $sp_entry['key'] !== $sp_key || '' === $sp_entry['capability'] ) { $sp_ok = false; }
	if ( 'choice' === $sp_entry['type'] && ! array_key_exists( (string) $sp_entry['default'], $sp_entry['options'] ) ) { $sp_ok = false; echo "    choice default not offered: $sp_key\n"; }
	if ( 'int' === $sp_entry['type'] && ( (int) $sp_entry['default'] < $sp_entry['min'] || (int) $sp_entry['default'] > $sp_entry['max'] ) ) { $sp_ok = false; echo "    int default out of range: $sp_key\n"; }
}
check( 'SET-PC-01: entries are complete; choice defaults are offered and int defaults within their limits', $sp_ok );
check( 'SET-PC-01: secrets are flagged (Stripe key, SMS token, Apple key)', $sp_all['stripe_secret_key']['secret'] && $sp_all['twilio_auth_token']['secret'] && $sp_all['apple_pass_key']['secret'] && ! $sp_all['hold_minutes']['secret'] );
check( 'SET-PC-01: legal weight and country dependency are flagged (withdrawal text, invoice prefix, seller country)', $sp_all['withdrawal_notice_text']['legal'] && $sp_all['invoice_prefix']['legal'] && $sp_all['seller_country']['country'] && ! $sp_all['hold_minutes']['legal'] );
check( 'SET-PC-01: module settings name their module and page', 'module' === $sp_all['waitlist']['source'] && 'wpcap-waitlist' === $sp_all['waitlist']['page'] && false !== strpos( $sp_all['waitlist']['module'], 'Waitlist' ) );
check( 'SET-PC-01: internal keys are marked (no form field)', $sp_all['legal_texts']['internal'] && $sp_all['client_ip_header']['internal'] );

// validate(): report, never clamp silently.
$sp_v = SettingsSchema::validate( 'hold_minutes', '500' );
check( 'SET-PC-01: a number above the limit is an error naming the field, not clamped', is_wp_error( $sp_v ) && 'wpcap_setting_range' === $sp_v->get_error_code() && false !== strpos( $sp_v->get_error_message(), '120' ) );
check( 'SET-PC-01: a valid number is returned as int', 15 === SettingsSchema::validate( 'hold_minutes', ' 15 ' ) );
check( 'SET-PC-01: not a whole number is an error', is_wp_error( SettingsSchema::validate( 'hold_minutes', '1.5' ) ) && is_wp_error( SettingsSchema::validate( 'hold_minutes', array() ) ) );
check( 'SET-PC-01: an unknown choice is an error, an offered one passes', is_wp_error( SettingsSchema::validate( 'campaign_stacking', 'all' ) ) && 'best' === SettingsSchema::validate( 'campaign_stacking', 'best' ) );
check( 'SET-PC-01: an invalid e-mail address is an error', is_wp_error( SettingsSchema::validate( 'alert_email', 'no-mail' ) ) && 'ops@example.com' === SettingsSchema::validate( 'alert_email', ' ops@example.com ' ) );
check( 'SET-PC-01: an unknown key is an error', is_wp_error( SettingsSchema::validate( 'no_such_setting', 1 ) ) );

// Add-ons: new keys through the defaults filter, descriptions through the schema filter; core types stay.
$sp_def = static function ( $d ) { $d['addon_sp_limit'] = 5; return $d; };
$sp_sch = static function ( $e ) {
	$e['addon_sp_limit'] = array( 'type' => 'int', 'min' => 1, 'max' => 10, 'label' => 'Add-on Grenze', 'help' => 'Hilfe' );
	$e['hold_minutes']['type'] = 'text';
	$e['hold_minutes']['max'] = 9999;
	return $e;
};
add_filter( 'wp_pass_captain_settings_defaults_v1', $sp_def );
add_filter( 'wp_pass_captain_settings_schema_v1', $sp_sch );
Settings::flush();
$sp_addon = SettingsSchema::get( 'addon_sp_limit' );
$sp_hold = SettingsSchema::get( 'hold_minutes' );
check( 'SET-PC-01: an add-on describes its own key through wp_pass_captain_settings_schema_v1', is_array( $sp_addon ) && 'addon' === $sp_addon['source'] && 10 === $sp_addon['max'] && is_wp_error( SettingsSchema::validate( 'addon_sp_limit', 11 ) ) );
check( 'SET-PC-01: the filter cannot retype or widen a core entry', 'int' === $sp_hold['type'] && 120 === $sp_hold['max'] );
remove_filter( 'wp_pass_captain_settings_defaults_v1', $sp_def );
remove_filter( 'wp_pass_captain_settings_schema_v1', $sp_sch );
Settings::flush();
check( 'SET-PC-01: the register follows Settings::flush()', null === SettingsSchema::get( 'addon_sp_limit' ) );

// Step 3: module pages validate through the register (no form clamps on its own).
$sp_grp = SettingsSchema::check_group( 'waitlist', array( 'offer_hours' => '400', 'max_offers_per_run' => '0', 'auto_offers' => 1 ) );
check( 'SET-PC-01: check_group reports every invalid sub-field in one error', is_wp_error( $sp_grp ) && 2 === count( $sp_grp->get_error_data()['messages'] ?? array() ) );
$sp_grp = SettingsSchema::check_group( 'waitlist', array( 'offer_hours' => ' 48 ', 'confirm_hours' => '', 'auto_offers' => 1 ) );
check( 'SET-PC-01: check_group returns checked numbers; an emptied number field falls back to the default', is_array( $sp_grp ) && 48 === $sp_grp['offer_hours'] && 48 === $sp_grp['confirm_hours'] && 1 === $sp_grp['auto_offers'] );
check( 'SET-PC-01: list fields check every item and the number of items', is_wp_error( SettingsSchema::check_group( 'reminders', array( 'offsets' => array( 2, 721 ) ) ) ) && is_wp_error( SettingsSchema::check_group( 'reminders', array( 'offsets' => array( 1, 2, 3, 4, 5, 6 ) ) ) ) && array( 'offsets' => array( 2, 24 ) ) === SettingsSchema::check_group( 'reminders', array( 'offsets' => array( '2', '24' ) ) ) );
$sp_many = SettingsSchema::check_many( array( 'partners' => array( 'rate_limit' => '0' ), 'partner_negative_balance' => 'later' ) );
check( 'SET-PC-01: check_many reports group and top-level errors together', is_wp_error( $sp_many ) && 2 === count( $sp_many->get_error_data()['messages'] ?? array() ) );
foreach ( array( 'waitlist', 'exchange', 'allocations', 'admission', 'webhooks', 'partners', 'sms', 'reminders' ) as $sp_group ) {
	check( "SET-PC-01: group setting $sp_group describes its limited fields", ! empty( SettingsSchema::get( $sp_group )['fields'] ) );
}
// Source guard: the module settings forms no longer clamp numbers themselves.
$sp_forms = array( 'src/Waitlists/AdminPage.php', 'src/Modules/Webhooks.php', 'src/Modules/Sms.php', 'src/Partners/AdminPage.php', 'src/Allocations/AdminPage.php', 'src/Admission/RulesPage.php', 'src/Exchange/AdminPage.php', 'src/Modules/Reminders.php' );
$sp_clamps = array();
foreach ( $sp_forms as $sp_file ) {
	if ( preg_match( '/min\(\s*\d+,\s*(?:Forms::int|absint\(\s*\$in)/', (string) file_get_contents( WPCAP_PATH . $sp_file ) ) ) { $sp_clamps[] = $sp_file; }
}
check( 'SET-PC-01: no module settings form clamps numbers itself', array() === $sp_clamps );

<?php
/**
 * SET-PC-02: country presets DE, AT, CH, EU, OTHER as a proposal with before/after.
 */
use PassCaptain\Admin\SettingsTools;
use PassCaptain\CountryPresets;
use PassCaptain\Settings;
use PassCaptain\SettingsManager;

echo "SET-PC-02: country presets\n";
$sp02_saved = get_option( Settings::OPTION, array() );
$sp02_all = CountryPresets::all();
check( 'SET-PC-02: presets DE, AT, CH, EU and OTHER exist with the same structure', array( 'DE', 'AT', 'CH', 'EU', 'OTHER' ) === array_keys( $sp02_all ) && array() === array_filter( $sp02_all, static fn( $p ) => '' === $p['label'] || '' === $p['note'] || ! $p['values'] ) );
check( 'SET-PC-02: no preset is marked as legally reviewed (DE/AT/CH need review, EU/OTHER unreviewed)', 'needs_review' === $sp02_all['DE']['review'] && 'needs_review' === $sp02_all['CH']['review'] && 'unreviewed' === $sp02_all['EU']['review'] && 'unreviewed' === $sp02_all['OTHER']['review'] );
Settings::update( array( 'seller_country' => 'DE', 'retention_order_years' => 10, 'attendee_retention_days' => 0 ) );
$sp02_diff = CountryPresets::diff( 'AT' );
$sp02_keys = array_column( $sp02_diff, null, 'key' );
check( 'SET-PC-02: the diff shows only values that change, with old and new', isset( $sp02_keys['seller_country'] ) && 'DE' === $sp02_keys['seller_country']['old'] && 'AT' === $sp02_keys['seller_country']['new'] && 7 === (int) $sp02_keys['retention_order_years']['new'] );
check( 'SET-PC-02: legally relevant values are marked', true === $sp02_keys['seller_country']['legal'] && true === $sp02_keys['withdrawal_notice_text']['legal'] );
check( 'SET-PC-02: showing a preset changes nothing', 'DE' === Settings::get( 'seller_country' ) );
check( 'SET-PC-02: group values are merged (SMS prefix only, other SMS settings kept)', '+43' === ( $sp02_keys['sms']['new']['default_country_code'] ?? '' ) && array_key_exists( 'budget_warn_percent', $sp02_keys['sms']['new'] ) );
$sp02_applied = CountryPresets::apply( 'AT', array( 'seller_country', 'retention_order_years' ) );
check( 'SET-PC-02: only the selected values are applied', array( 'seller_country', 'retention_order_years' ) === $sp02_applied && 'AT' === Settings::get( 'seller_country' ) && 7 === (int) Settings::get( 'retention_order_years' ) && 0 === (int) Settings::get( 'attendee_retention_days' ) );
$sp02_h = SettingsManager::history( array( 'limit' => 1 ) )[0] ?? array();
check( 'SET-PC-02: applying is logged with source "preset" and old/new', 'preset' === ( $sp02_h['source'] ?? '' ) && 'DE' === ( array_column( $sp02_h['changes'] ?? array(), null, 'key' )['seller_country']['old'] ?? '' ) );
check( 'SET-PC-02: an unknown preset is an error', is_wp_error( CountryPresets::diff( 'XX' ) ) );
check( 'SET-PC-02: the admin step needs a selection and manage_options', is_wp_error( SettingsTools::preset_step( array( 'preset' => 'CH' ) ) ) && array( 'seller_country' ) === SettingsTools::preset_step( array( 'preset' => 'ch', 'keys' => array( 'seller_country' ) ) ) && 'CH' === Settings::get( 'seller_country' ) );
$sp02_f = static function ( $p ) { $p['DE']['values']['hold_minutes'] = 999; $p['XY'] = array( 'label' => 'Testland', 'values' => array( 'seller_country' => 'XY', 'no_such' => 1 ) ); return $p; };
add_filter( 'wp_pass_captain_country_presets_v1', $sp02_f );
$sp02_de = array_column( CountryPresets::diff( 'DE' ), null, 'key' );
check( 'SET-PC-02: the filter adds presets; unknown keys are dropped and invalid values reported, never applied', isset( CountryPresets::all()['XY'] ) && ! isset( CountryPresets::all()['XY']['values']['no_such'] ) && 'unreviewed' === CountryPresets::all()['XY']['review'] && '' !== ( $sp02_de['hold_minutes']['error'] ?? '' ) && ! in_array( 'hold_minutes', (array) CountryPresets::apply( 'DE' ), true ) && 999 !== (int) Settings::get( 'hold_minutes' ) );
remove_filter( 'wp_pass_captain_country_presets_v1', $sp02_f );
ob_start();
PassCaptain\Admin\SettingsPage::render( 'tools' );
ob_end_clean();
$_GET['view'] = 'preset';
$_GET['preset'] = 'at';
ob_start();
SettingsTools::render();
$sp02_html = ob_get_clean();
unset( $_GET['view'], $_GET['preset'] );
check( 'SET-PC-02: the preset view shows the review note and a checkbox per value', false !== strpos( $sp02_html, 'Rechts- und Steuerprüfung' ) && false !== strpos( $sp02_html, 'name="keys[]"' ) );
update_option( Settings::OPTION, $sp02_saved );
Settings::flush();

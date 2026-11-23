<?php
/**
 * SET-PC-10 (export/import), SET-PC-11 (reset), SET-PC-12 (WP-CLI core), SET-PC-13 (change log with
 * old/new) and SET-PC-32 (overview with search).
 */
use PassCaptain\Admin\SettingsTools;
use PassCaptain\Ops\SettingsCli;
use PassCaptain\Settings;
use PassCaptain\SettingsManager;
use PassCaptain\SettingsSchema;

echo "SET-PC-13: change log with old and new values\n";
$sp10_saved = get_option( Settings::OPTION, array() );
$sp10_templates = get_option( PassCaptain\Mail\Templates::OPTION, array() );
Settings::update( array( 'hold_minutes' => 15 ) );
SettingsManager::with_source( 'page', static function () { Settings::update( array( 'hold_minutes' => 25, 'terms_text' => str_repeat( 'AGB ', 100 ), 'stripe_secret_key' => 'sk_test_abcdefghijklmnop' ) ); } );
$sp10_h = SettingsManager::history( array( 'limit' => 1 ) )[0] ?? array();
$sp10_by = array_column( $sp10_h['changes'] ?? array(), null, 'key' );
check( 'SET-PC-13: one entry per save with source and old/new', 'page' === ( $sp10_h['source'] ?? '' ) && 15 === (int) ( $sp10_by['hold_minutes']['old'] ?? 0 ) && 25 === (int) ( $sp10_by['hold_minutes']['new'] ?? 0 ) );
check( 'SET-PC-13: long texts are logged as checksum with length, not as text', isset( $sp10_by['terms_text']['new']['sha256'] ) && 400 === $sp10_by['terms_text']['new']['length'] );
if ( empty( $sp10_by['stripe_secret_key']['secret'] ) || 'changed' !== ( $sp10_by['stripe_secret_key']['new'] ?? '' ) ) { echo '     debug: ' . wp_json_encode( $sp10_h ) . "\n"; }
check( 'SET-PC-13: secrets are logged without any value', ! empty( $sp10_by['stripe_secret_key']['secret'] ) && 'changed' === $sp10_by['stripe_secret_key']['new'] && false === strpos( wp_json_encode( $sp10_h ), 'sk_test_abc' ) );
check( 'SET-PC-13: unchanged values write no entry', ( SettingsManager::history( array( 'limit' => 1 ) )[0]['log_id'] ?? 0 ) === ( function () { Settings::update( array( 'hold_minutes' => 25 ) ); return SettingsManager::history( array( 'limit' => 1 ) )[0]['log_id'] ?? 0; } )() );
check( 'SET-PC-13: history can be filtered by key', array() !== SettingsManager::history( array( 'key' => 'hold_minutes' ) ) && array() === array_filter( SettingsManager::history( array( 'key' => 'hold_minutes' ) ), static fn( $e ) => array() === $e['changes'] ) );
SettingsManager::with_source( 'page', static function () { Settings::update( array( 'stripe_secret_key' => '' ) ); } );

echo "SET-PC-11: reset to defaults\n";
Settings::update( array( 'hold_minutes' => 30, 'campaign_max_codes' => 2, 'delete_data_on_uninstall' => 0 ) );
$sp10_r = SettingsManager::reset( array( 'hold_minutes', 'stripe_secret_key', 'delete_data_on_uninstall' ) );
$sp10_stored = get_option( Settings::OPTION, array() );
check( 'SET-PC-11: reset removes the stored value so the default applies; secrets and the uninstall choice are ignored', array( 'hold_minutes' ) === $sp10_r && ! array_key_exists( 'hold_minutes', $sp10_stored ) && Settings::defaults()['hold_minutes'] === Settings::get( 'hold_minutes' ) && 2 === (int) Settings::get( 'campaign_max_codes' ) );
$sp10_h = SettingsManager::history( array( 'limit' => 1 ) )[0] ?? array();
check( 'SET-PC-11: reset is logged with the old value and source "reset"', 'reset' === ( $sp10_h['source'] ?? '' ) && 30 === (int) ( $sp10_h['changes'][0]['old'] ?? 0 ) );
check( 'SET-PC-11: scopes resolve to keys (section, page) without secrets or protected keys', in_array( 'hold_minutes', SettingsManager::scope_keys( 'section:' . SettingsSchema::get( 'hold_minutes' )['section'] ), true ) && in_array( 'waitlist', SettingsManager::scope_keys( 'page:' . SettingsSchema::get( 'waitlist' )['page'] ), true ) && ! in_array( 'stripe_secret_key', SettingsManager::scope_keys( 'all' ), true ) && ! in_array( 'delete_data_on_uninstall', SettingsManager::scope_keys( 'all' ), true ) );
check( 'SET-PC-11: a section reset needs the confirmation', is_wp_error( SettingsTools::reset_step( array( 'scope' => 'all' ) ) ) && is_array( SettingsTools::reset_step( array( 'scope' => 'key:campaign_max_codes' ) ) ) && Settings::defaults()['campaign_max_codes'] === Settings::get( 'campaign_max_codes' ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
check( 'SET-PC-11: reset, import and export need manage_options', is_wp_error( SettingsTools::reset_step( array( 'scope' => 'key:hold_minutes' ) ) ) && is_wp_error( SettingsTools::import_step( array( 'do' => 'preview' ), array( 'content' => '{}' ) ) ) );
$GLOBALS['wpcap_test_denied_caps'] = array();

echo "SET-PC-10: export and import\n";
Settings::update( array( 'hold_minutes' => 40, 'seller_name' => 'Export GmbH', 'stripe_secret_key' => 'sk_test_exportsecret123', 'waitlist' => array_merge( (array) Settings::get( 'waitlist' ), array( 'offer_hours' => 72 ) ) ) );
PassCaptain\Mail\Templates::save( 'tickets', 'de_DE', array( 'subject' => 'Danke {order}', 'body' => '<p>Hallo</p>', 'format' => 'html' ) );
$sp10_doc = SettingsManager::export();
$sp10_json = wp_json_encode( $sp10_doc );
check( 'SET-PC-10: export has format, plugin and schema version and the settings', 'pass-captain-settings' === $sp10_doc['format'] && 1 === $sp10_doc['format_version'] && WPCAP_VERSION === $sp10_doc['plugin_version'] && 40 === (int) $sp10_doc['settings']['hold_minutes'] && 72 === (int) $sp10_doc['settings']['waitlist']['offer_hours'] );
check( 'SET-PC-10: export never contains secrets or the uninstall choice', false === strpos( $sp10_json, 'sk_test_exportsecret' ) && ! array_key_exists( 'stripe_secret_key', $sp10_doc['settings'] ) && ! array_key_exists( 'delete_data_on_uninstall', $sp10_doc['settings'] ) );
check( 'SET-PC-10: export contains the mail templates', 'Danke {order}' === ( $sp10_doc['mail_templates']['tickets']['de_DE']['subject'] ?? '' ) );
// Round trip: change values, import the export, values are back.
Settings::update( array( 'hold_minutes' => 10, 'seller_name' => 'Anders AG' ) );
PassCaptain\Mail\Templates::reset( 'tickets', 'de_DE' );
$sp10_prev = SettingsManager::preview( $sp10_json );
check( 'SET-PC-10: preview lists changed values with old and new and stores nothing', is_array( $sp10_prev ) && 0 === $sp10_prev['errors'] && 2 <= $sp10_prev['changes'] && 10 === (int) Settings::get( 'hold_minutes' ) && in_array( 'seller_name', array_column( array_filter( $sp10_prev['rows'], static fn( $r ) => $r['changed'] ), 'key' ), true ) );
check( 'SET-PC-10: legally relevant values are marked in the preview', true === ( array_column( $sp10_prev['rows'], 'legal', 'key' )['seller_name'] ?? null ) );
$sp10_imp = SettingsManager::import( $sp10_json );
check( 'SET-PC-10: import round trip restores values and mail templates', is_array( $sp10_imp ) && 40 === (int) Settings::get( 'hold_minutes' ) && 'Export GmbH' === Settings::get( 'seller_name' ) && 1 === $sp10_imp['mail_templates'] && null !== PassCaptain\Mail\Templates::get( 'tickets', 'de_DE' ) );
check( 'SET-PC-10: import keeps the stored secret', 'sk_test_exportsecret123' === PassCaptain\Secrets::stored( 'stripe_secret_key' ) );
check( 'SET-PC-10: import is logged with source "import"', 'import' === ( SettingsManager::history( array( 'limit' => 1 ) )[0]['source'] ?? '' ) );
// Invalid values: nothing is stored.
$sp10_bad = $sp10_doc;
$sp10_bad['settings']['hold_minutes'] = 999;
$sp10_bad['settings']['seller_name'] = 'Nicht übernommen';
$sp10_bad['settings']['stripe_secret_key'] = 'sk_live_evil';
$sp10_bad['settings']['no_such_key'] = 1;
Settings::update( array( 'seller_name' => 'Vorher' ) );
$sp10_r = SettingsManager::import( $sp10_bad );
check( 'SET-PC-10: an invalid value blocks the whole import (all or nothing)', is_wp_error( $sp10_r ) && 'Vorher' === Settings::get( 'seller_name' ) && false !== strpos( $sp10_r->get_error_message(), '120' ) );
$sp10_prev = SettingsManager::preview( $sp10_bad );
check( 'SET-PC-10: secrets and unknown keys in a file are skipped, never imported', 'protected' === ( $sp10_prev['skipped']['stripe_secret_key'] ?? '' ) && 'unknown' === ( $sp10_prev['skipped']['no_such_key'] ?? '' ) && 'sk_test_exportsecret123' === PassCaptain\Secrets::stored( 'stripe_secret_key' ) );
check( 'SET-PC-10: a foreign file or a newer format is refused', is_wp_error( SettingsManager::preview( '{"foo":1}' ) ) && is_wp_error( SettingsManager::preview( array( 'format' => 'pass-captain-settings', 'format_version' => 99, 'settings' => array() ) ) ) );
// Admin flow: preview step keeps the file per user, confirm imports.
Settings::update( array( 'hold_minutes' => 11 ) );
$sp10_step = SettingsTools::import_step( array( 'do' => 'preview' ), array( 'content' => $sp10_json ) );
check( 'SET-PC-10: the admin preview step stores nothing', is_array( $sp10_step ) && 11 === (int) Settings::get( 'hold_minutes' ) );
$sp10_step = SettingsTools::import_step( array( 'do' => 'confirm', 'mail_templates' => '1' ) );
check( 'SET-PC-10: the admin confirm step imports the previewed file', is_array( $sp10_step ) && 40 === (int) Settings::get( 'hold_minutes' ) && is_wp_error( SettingsTools::import_step( array( 'do' => 'confirm' ) ) ) );

echo "SET-PC-12: WP-CLI core\n";
check( 'SET-PC-12: get returns values, secrets only as status', 40 === (int) SettingsCli::get_value( 'hold_minutes' ) && 'set' === SettingsCli::get_value( 'stripe_secret_key' ) && is_wp_error( SettingsCli::get_value( 'nope' ) ) );
check( 'SET-PC-12: set checks through the register (reported, not clamped)', is_wp_error( SettingsCli::set_value( 'hold_minutes', '500' ) ) && 40 === (int) Settings::get( 'hold_minutes' ) && 33 === SettingsCli::set_value( 'hold_minutes', '33' ) );
check( 'SET-PC-12: set accepts yes/no for switches and JSON for groups', 1 === (int) SettingsCli::set_value( 'require_terms', 'yes' ) && 96 === (int) ( SettingsCli::set_value( 'waitlist', array( 'offer_hours' => 96 ) )['offer_hours'] ?? 0 ) && is_wp_error( SettingsCli::set_value( 'waitlist', array( 'offer_hours' => 999 ) ) ) );
check( 'SET-PC-12: data deletion at uninstall needs --confirm=LÖSCHEN to switch on, not to switch off', is_wp_error( SettingsCli::set_value( 'delete_data_on_uninstall', '1' ) ) && ! Settings::deletes_data_on_uninstall() && 1 === (int) SettingsCli::set_value( 'delete_data_on_uninstall', '1', 'LOESCHEN' ) && 0 === (int) SettingsCli::set_value( 'delete_data_on_uninstall', '0' ) );
check( 'SET-PC-12: a secret can be set but is never printed', 'set' === SettingsCli::set_value( 'stripe_secret_key', 'sk_test_cli1234567890' ) && 'sk_test_cli1234567890' === PassCaptain\Secrets::stored( 'stripe_secret_key' ) );
check( 'SET-PC-12: changes from the CLI are logged with source "cli"', 'cli' === ( SettingsManager::history( array( 'limit' => 1 ) )[0]['source'] ?? '' ) );
check( 'SET-PC-12: list can show only changed settings', in_array( 'hold_minutes', array_column( SettingsCli::list_rows( '', true ), 'key' ), true ) && ! in_array( 'campaign_max_codes', array_column( SettingsCli::list_rows( '', true ), 'key' ), true ) );

echo "SET-PC-32: overview with search\n";
$sp10_rows = SettingsTools::overview_rows();
check( 'SET-PC-32: the overview lists every setting with value, default and source', count( $sp10_rows ) >= count( Settings::defaults() ) - 2 && isset( array_column( $sp10_rows, null, 'key' )['hold_minutes']['default'] ) );
check( 'SET-PC-32: search finds by label, key and help text', in_array( 'hold_minutes', array_column( SettingsTools::overview_rows( 'Reservierungsdauer' ), 'key' ), true ) && in_array( 'bank_bic', array_column( SettingsTools::overview_rows( 'bank_bic' ), 'key' ), true ) && array() === SettingsTools::overview_rows( 'xyz-nichts-gefunden' ) );
check( 'SET-PC-32: secrets show only their status', 'gesetzt (geheim)' === array_column( $sp10_rows, null, 'key' )['stripe_secret_key']['value'] );
ob_start();
PassCaptain\Admin\SettingsPage::render( 'tools' );
$sp10_html = ob_get_clean();
check( 'SET-PC-32: the tools tab renders the overview with a search field', false !== strpos( $sp10_html, 'wpcap-settings-search' ) && false !== strpos( $sp10_html, 'hold_minutes' ) && false === strpos( $sp10_html, 'sk_test_cli' ) );

// Restore.
PassCaptain\Secrets::set( 'stripe_secret_key', '' );
update_option( Settings::OPTION, $sp10_saved );
update_option( PassCaptain\Mail\Templates::OPTION, $sp10_templates );
update_option( Settings::UNINSTALL_POLICY, 'keep' );
Settings::flush();

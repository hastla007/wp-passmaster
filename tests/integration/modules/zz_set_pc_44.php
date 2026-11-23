<?php
/**
 * SET-PC-44: settings screens – help texts linked by aria-describedby, error list with jump links, aria-invalid.
 */
use PassCaptain\Admin\SettingsPage;
use PassCaptain\Settings;

echo "SET-PC-44: accessible settings screens\n";
$sp44_saved = get_option( Settings::OPTION, array() );
ob_start();
SettingsPage::render( 'general' );
$sp44_html = ob_get_clean();
check( 'SET-PC-44: help texts are linked to their inputs (aria-describedby)', (bool) preg_match( '/<input[^>]+id="wpcap-set-hold_minutes"[^>]+aria-describedby="wpcap-set-hold_minutes-desc"/', $sp44_html ) && false !== strpos( $sp44_html, 'id="wpcap-set-hold_minutes-desc"' ) );
$sp44_result = SettingsPage::save( array( 'wpcap_tab' => 'general', 'hold_minutes' => '999' ) );
check( 'SET-PC-44: save returns the invalid fields by key', isset( $sp44_result['field_errors']['hold_minutes'] ) );
set_transient( SettingsPage::FIELD_ERRORS . get_current_user_id(), $sp44_result['field_errors'], 300 );
ob_start();
SettingsPage::render( 'general' );
$sp44_html = ob_get_clean();
check( 'SET-PC-44: an error list with jump links is shown', false !== strpos( $sp44_html, 'wpcap-error-summary' ) && false !== strpos( $sp44_html, 'href="#wpcap-set-hold_minutes"' ) );
check( 'SET-PC-44: the invalid field carries aria-invalid and its message', (bool) preg_match( '/id="wpcap-set-hold_minutes"[^>]+aria-describedby="wpcap-set-hold_minutes-desc wpcap-set-hold_minutes-error" aria-invalid="true"/', $sp44_html ) && false !== strpos( $sp44_html, 'id="wpcap-set-hold_minutes-error"' ) );
check( 'SET-PC-44: field errors are shown once', array() === SettingsPage::take_field_errors() );
update_option( Settings::OPTION, $sp44_saved );
Settings::flush();

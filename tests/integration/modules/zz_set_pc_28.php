<?php
/**
 * SET-PC-28: every field of the settings screen has a help text and a check; IBAN/BIC are validated.
 */
use PassCaptain\Admin\SettingsPage;
use PassCaptain\SettingsSchema;

echo "SET-PC-28: help text and validation at every field\n";
$sp28_missing = array();
foreach ( SettingsPage::all_sections() as $sp28_id => $sp28_section ) {
	foreach ( (array) ( $sp28_section['fields'] ?? array() ) as $sp28_key => $sp28_def ) {
		if ( ! is_array( $sp28_def ) ) { continue; }
		$sp28_help = (string) ( $sp28_def['description'] ?? ( $sp28_def['help'] ?? '' ) );
		if ( '' === trim( $sp28_help ) ) { $sp28_missing[] = $sp28_key; }
	}
}
if ( $sp28_missing ) { echo '     missing: ' . implode( ', ', $sp28_missing ) . "\n"; }
check( 'SET-PC-28: every field of the settings screen has a help text', array() === $sp28_missing );
check( 'SET-PC-28: IBAN with a wrong check digit is reported, a valid one is stored without spaces', is_wp_error( SettingsSchema::validate( 'bank_iban', 'AT61 1904 3002 3457 3202' ) ) && 'AT611904300234573201' === SettingsSchema::validate( 'bank_iban', 'at61 1904 3002 3457 3201' ) && '' === SettingsSchema::validate( 'bank_iban', '' ) );
check( 'SET-PC-28: BIC is checked (8 or 11 characters)', is_wp_error( SettingsSchema::validate( 'bank_bic', 'GIBA-AT' ) ) && 'GIBAATWWXXX' === SettingsSchema::validate( 'bank_bic', 'giba atww xxx' ) && 'GIBAATWW' === SettingsSchema::validate( 'bank_bic', 'GIBAATWW' ) );
$sp28_option = get_option( PassCaptain\Settings::OPTION, array() );
$sp28_before = PassCaptain\Settings::get( 'bank_iban' );
$sp28_saved = SettingsPage::save( array( 'wpcap_tab' => 'payments', 'wpcap_section' => 'manual', 'bank_iban' => 'DE00 1234' ) );
update_option( PassCaptain\Settings::OPTION, $sp28_option );
PassCaptain\Settings::flush();
check( 'SET-PC-28: the settings screen reports an invalid IBAN and keeps the stored one', ! is_wp_error( $sp28_saved ) && 1 === count( array_filter( $sp28_saved['errors'], static fn( $e ) => false !== strpos( $e, 'IBAN' ) ) ) && $sp28_before === PassCaptain\Settings::get( 'bank_iban' ) );
check( 'SET-PC-28: payment_poll_after_minutes is described in the register', SettingsSchema::get( 'payment_poll_after_minutes' )['described'] && 1440 === SettingsSchema::get( 'payment_poll_after_minutes' )['max'] );

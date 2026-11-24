<?php
/**
 * SET-PC-02 (rest): country presets DE/AT/CH also propose the regional formats (document date/time
 * format, first weekday of the calendar); EU and OTHER leave them unset. No legal values.
 */
use PassCaptain\CountryPresets;
use PassCaptain\Settings;

echo "SET-PC-02: regional formats in the country presets\n";
$spf_saved = get_option( Settings::OPTION, array() );
$spf_has_calendar = array_key_exists( 'calendar', Settings::defaults() );
check( 'SET-PC-02 formats: the calendar module provides the first-weekday setting', $spf_has_calendar );
$spf_all = CountryPresets::all();
$spf_ok = true;
foreach ( array( 'DE', 'AT', 'CH' ) as $spf_code ) {
	$spf_v = $spf_all[ $spf_code ]['values'];
	$spf_ok = $spf_ok && 'd.m.Y' === ( $spf_v['document_date_format'] ?? '' ) && 'H:i' === ( $spf_v['document_time_format'] ?? '' ) && 1 === ( $spf_v['calendar']['first_weekday'] ?? null );
}
check( 'SET-PC-02 formats: DE, AT and CH propose d.m.Y, H:i and Monday', $spf_ok );
$spf_unset = true;
foreach ( array( 'EU', 'OTHER' ) as $spf_code ) {
	$spf_unset = $spf_unset && ! array_intersect_key( $spf_all[ $spf_code ]['values'], array_flip( array( 'document_date_format', 'document_time_format', 'calendar' ) ) );
}
check( 'SET-PC-02 formats: EU and OTHER leave date format and first weekday unset', $spf_unset );

// A site with WordPress formats (empty) and a Sunday week start sees the proposal as a change.
$spf_cal = (array) Settings::get( 'calendar' );
Settings::update( array( 'document_date_format' => '', 'document_time_format' => '', 'calendar' => array_merge( $spf_cal, array( 'first_weekday' => 0 ) ) ) );
$spf_diff = array_column( CountryPresets::diff( 'DE' ), null, 'key' );
check( 'SET-PC-02 formats: the diff shows the formats with old and new value, without error', 'd.m.Y' === ( $spf_diff['document_date_format']['new'] ?? '' ) && '' === ( $spf_diff['document_date_format']['old'] ?? null ) && '' === $spf_diff['document_date_format']['error'] && 'H:i' === ( $spf_diff['document_time_format']['new'] ?? '' ) );
check( 'SET-PC-02 formats: the first weekday is merged into the calendar group (other calendar settings kept)', 1 === (int) ( $spf_diff['calendar']['new']['first_weekday'] ?? -1 ) && array_key_exists( 'attach_ics', $spf_diff['calendar']['new'] ?? array() ) && '' === $spf_diff['calendar']['error'] );
check( 'SET-PC-02 formats: formats and weekday are not marked as legal values', false === $spf_diff['document_date_format']['legal'] && false === $spf_diff['document_time_format']['legal'] && false === $spf_diff['calendar']['legal'] );
$spf_applied = CountryPresets::apply( 'AT', array( 'document_date_format', 'calendar' ) );
check( 'SET-PC-02 formats: only the selected formats are applied', array( 'document_date_format', 'calendar' ) === $spf_applied && 'd.m.Y' === Settings::get( 'document_date_format' ) && '' === Settings::get( 'document_time_format' ) && 1 === PassCaptain\Modules\Calendar::first_weekday() );
check( 'SET-PC-02 formats: once applied, the formats no longer appear in the diff', ! array_intersect( array( 'document_date_format', 'calendar' ), array_column( CountryPresets::diff( 'CH' ), 'key' ) ) );
update_option( Settings::OPTION, $spf_saved );
Settings::flush();

<?php
// phpcs:ignoreFile
/**
 * A332: series occurrences take over the template's participant fields, ID/age check and staff
 * note, admission rule (multi-day dates moved with the occurrence) and admission areas; the
 * preview lists what is copied per ticket type.
 */
use PassCaptain\Series\Generator;

if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }

echo "A332: series copy participant fields and admission settings\n";
global $wpdb;
$p = $wpdb->prefix;
$a332_type = make_type( 40, 6, 3000 );
$a332_tpl = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a332_type" );
$a332_event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$a332_tpl" );
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET starts_at='2027-05-01 17:00:00',timezone='Europe/Vienna' WHERE occurrence_id=$a332_tpl" );
$a332_checks = PassCaptain\AdmissionChecks::ready();
if ( $a332_checks ) { $wpdb->query( "UPDATE {$p}wpcap_ticket_types SET id_check=1,min_age=18,staff_note='Bändchen ausgeben' WHERE ticket_type_id=$a332_type" ); }
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $a332_type, 'field_key' => 'name', 'label' => 'Name', 'field_type' => 'text', 'required' => 1, 'purpose' => 'Namensschild', 'retention_days' => 90, 'position' => 1, 'status' => 'active' ) );
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $a332_type, 'field_key' => 'diet', 'label' => 'Ernährung', 'field_type' => 'text', 'required' => 0, 'purpose' => '', 'retention_days' => 30, 'position' => 2, 'status' => 'active' ) );
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $a332_type, 'field_key' => 'old', 'label' => 'Alt', 'field_type' => 'text', 'required' => 0, 'purpose' => '', 'retention_days' => 30, 'position' => 3, 'status' => 'inactive' ) );
$a332_has_policies = "{$p}wpcap_admission_policies" === $wpdb->get_var( "SHOW TABLES LIKE '{$p}wpcap_admission_policies'" );
$a332_has_areas = "{$p}wpcap_admission_type_areas" === $wpdb->get_var( "SHOW TABLES LIKE '{$p}wpcap_admission_type_areas'" );
if ( $a332_has_policies ) {
	$wpdb->insert( "{$p}wpcap_admission_policies", array( 'ticket_type_id' => $a332_type, 'policy' => 'multi_day', 'max_entries' => 0, 'valid_dates' => wp_json_encode( array( '2027-05-01', '2027-05-02' ) ), 'updated_by' => 1, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
}
if ( $a332_has_areas ) { $wpdb->insert( "{$p}wpcap_admission_type_areas", array( 'ticket_type_id' => $a332_type, 'area_id' => 777 ) ); }

$a332_in = array( 'event_id' => $a332_event, 'template_occurrence_id' => $a332_tpl, 'freq' => 'dates', 'dates' => "2027-06-05\n2027-06-12", 'time' => '19:00', 'duration_minutes' => 120, 'timezone' => 'Europe/Vienna', 'name' => 'A332' );
$a332_run = Generator::create( $a332_in );
check( 'A332 setup: two occurrences created', ! is_wp_error( $a332_run ) && 2 === count( $a332_run['created'] ) );
$a332_new = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_type_id FROM {$p}wpcap_ticket_types WHERE occurrence_id=%d", $a332_run['created'][0] ?? 0 ) );
$a332_fields = $wpdb->get_results( $wpdb->prepare( "SELECT field_key,label,required,purpose,retention_days,position,status FROM {$p}wpcap_participant_fields WHERE ticket_type_id=%d ORDER BY position", $a332_new ) );
check( 'A332: active participant fields are copied with label, required, purpose, retention and order (inactive ones not)', 2 === count( $a332_fields ) && 'name' === $a332_fields[0]->field_key && 1 === (int) $a332_fields[0]->required && 'Namensschild' === $a332_fields[0]->purpose && 90 === (int) $a332_fields[0]->retention_days && 'diet' === $a332_fields[1]->field_key && 'active' === $a332_fields[1]->status );
if ( $a332_checks ) {
	$a332_copy = $wpdb->get_row( $wpdb->prepare( "SELECT id_check,min_age,staff_note FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d", $a332_new ) );
	check( 'A332: ID check, minimum age and staff note are copied', 1 === (int) $a332_copy->id_check && 18 === (int) $a332_copy->min_age && 'Bändchen ausgeben' === $a332_copy->staff_note );
}
if ( $a332_has_policies ) {
	$a332_pol = $wpdb->get_row( $wpdb->prepare( "SELECT policy,valid_dates FROM {$p}wpcap_admission_policies WHERE ticket_type_id=%d", $a332_new ) );
	check( 'A332: admission rule copied, multi-day dates moved with the occurrence (+35 days)', $a332_pol && 'multi_day' === $a332_pol->policy && array( '2027-06-05', '2027-06-06' ) === json_decode( (string) $a332_pol->valid_dates, true ) );
}
if ( $a332_has_areas ) {
	check( 'A332: admission areas copied', 777 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT area_id FROM {$p}wpcap_admission_type_areas WHERE ticket_type_id=%d", $a332_new ) ) );
}
$a332_summary = Generator::template_summary( $a332_tpl );
$a332_text = implode( ' | ', array_map( static function ( $t ) { return $t['name'] . ': ' . implode( '; ', $t['details'] ); }, $a332_summary ) );
check( 'A332: preview summary names what is copied per ticket type', 1 === count( $a332_summary ) && false !== strpos( $a332_text, 'Standard: Preis, USt.' ) && false !== strpos( $a332_text, '2 Teilnehmerfelder' ) && ( ! $a332_checks || false !== strpos( $a332_text, 'Einlassprüfung: Ausweis prüfen · Mindestalter 18 · Bändchen ausgeben' ) ) && ( ! $a332_has_policies || false !== strpos( $a332_text, 'Einlassregel: ' ) ) && ( ! $a332_has_areas || false !== strpos( $a332_text, '1 Einlassbereich' ) ) );
ob_start();
PassCaptain\Series\AdminPage::render_template_summary( $a332_tpl );
$a332_html = ob_get_clean();
check( 'A332: the preview renders the summary', false !== strpos( $a332_html, 'Aus der Vorlage übernommen' ) && false !== strpos( $a332_html, '<strong>Standard</strong>' ) );
$a332_hooked = array();
add_action( 'wpcap_ticket_type_copied', static function ( $from, $to, $ctx ) use ( &$a332_hooked ) { $a332_hooked[] = array( $from, $to, $ctx['source'] ?? '' ); }, 10, 3 );
$a332_run2 = Generator::create( array( 'dates' => '2027-06-19' ) + $a332_in );
check( 'A332: wpcap_ticket_type_copied fires per copied type inside the run', ! is_wp_error( $a332_run2 ) && 1 === count( $a332_hooked ) && $a332_type === $a332_hooked[0][0] && 'series' === $a332_hooked[0][2] );
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET status='cancelled' WHERE event_id=$a332_event" );

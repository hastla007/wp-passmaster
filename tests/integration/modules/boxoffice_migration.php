<?php
/**
 * Box office regressions: legacy order_id NOT NULL schema (migration) and participant fields at the POS.
 * Included from run.php after boxoffice.php; uses check(), make_type(), tickets() and the wpb_* helpers.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\BoxOffice\Page as PosPage;
use PassCaptain\BoxOffice\Service as Pos;
use PassCaptain\Modules\BoxOffice as BoxOfficeModule;

global $wpdb;
$p = $wpdb->prefix;

echo "Box office: legacy order_id NOT NULL schema (migration)\n";
$bm_type = make_type( 20, 10, 1000 );
$bm_occ = wpb_occurrence_of( $bm_type );
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $bm_type, 'field_key' => 'company', 'label' => 'Firma', 'field_type' => 'text', 'required' => 1, 'position' => 1 ) );
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $bm_type, 'field_key' => 'newsletter', 'label' => 'Newsletter', 'field_type' => 'checkbox', 'required' => 0, 'position' => 2 ) );
$bm_session = Pos::open_session( 0 );

// Recreate the state of an existing install: column NOT NULL, WordPress' non-strict SQL mode.
$wpdb->query( "DELETE FROM {$p}wpcap_boxoffice_sales WHERE order_id IS NULL" );
$wpdb->query( "ALTER TABLE {$p}wpcap_boxoffice_sales MODIFY order_id bigint(20) unsigned NOT NULL" );
$bm_mode = $wpdb->get_var( 'SELECT @@SESSION.sql_mode' );
$wpdb->query( "SET SESSION sql_mode=''" );
$wpdb->query( "INSERT INTO {$p}wpcap_boxoffice_sales (session_id,form_key_hash,payment_method,currency,occurrence_id,cashier_id,status,created_at) VALUES (" . (int) $bm_session->session_id . ",'" . hash( 'sha256', 'legacy-orphan' ) . "','cash','EUR',$bm_occ,1,'pending','" . gmdate( 'Y-m-d H:i:s', time() - 3600 ) . "')" );
$wpdb->query( "INSERT INTO {$p}wpcap_boxoffice_sales (session_id,form_key_hash,payment_method,currency,occurrence_id,cashier_id,status,created_at,order_id) VALUES (" . (int) $bm_session->session_id . ",'" . hash( 'sha256', 'legacy-failed' ) . "','cash','EUR',$bm_occ,1,'failed','" . gmdate( 'Y-m-d H:i:s' ) . "',999999999)" );
$bm_col = static function () use ( $wpdb, $p ) { return (array) $wpdb->get_row( "SHOW COLUMNS FROM {$p}wpcap_boxoffice_sales LIKE 'order_id'" ); };
check( 'legacy schema reproduced (order_id NOT NULL with an orphan order_id=0)', 'NO' === $bm_col()['Null'] && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_boxoffice_sales WHERE order_id=0" ) );

$wpdb->query( "ALTER TABLE {$p}wpcap_boxoffice_sessions DROP COLUMN register_id, DROP COLUMN location_id" );
$GLOBALS['wpcap_test_options']['wpcap_boxoffice_migration'] = 0;
check( 'migration runs once on init (option flag)', true === BoxOfficeModule::maybe_migrate() && false === BoxOfficeModule::maybe_migrate() );
check( 'migration makes order_id NULL-able and removes stale orphan claims', 'YES' === $bm_col()['Null'] && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_boxoffice_sales WHERE order_id=0 OR form_key_hash='" . hash( 'sha256', 'legacy-orphan' ) . "'" ) && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_boxoffice_sales WHERE form_key_hash='" . hash( 'sha256', 'legacy-failed' ) . "'" ) );
check( 'migration is idempotent', true === BoxOfficeModule::migrate() && 'YES' === $bm_col()['Null'] );
$bm_location_col = (array) $wpdb->get_row( "SHOW COLUMNS FROM {$p}wpcap_boxoffice_sessions LIKE 'location_id'" );
$bm_register_col = (array) $wpdb->get_row( "SHOW COLUMNS FROM {$p}wpcap_boxoffice_sessions LIKE 'register_id'" );
check( 'migration adds location and register columns to existing POS sessions', 'location_id' === ( $bm_location_col['Field'] ?? '' ) && 'register_id' === ( $bm_register_col['Field'] ?? '' ) );

// Self-healing without the init hook: a sale on the legacy schema repairs the table and succeeds.
$wpdb->query( "ALTER TABLE {$p}wpcap_boxoffice_sales MODIFY order_id bigint(20) unsigned NOT NULL" );
$wpdb->query( "INSERT INTO {$p}wpcap_boxoffice_sales (session_id,form_key_hash,payment_method,currency,occurrence_id,cashier_id,status,created_at) VALUES (" . (int) $bm_session->session_id . ",'" . hash( 'sha256', 'legacy-orphan-2' ) . "','cash','EUR',$bm_occ,1,'pending','" . gmdate( 'Y-m-d H:i:s' ) . "')" );
$bm_failed = Pos::sell( array( 'occurrence_id' => $bm_occ, 'quantities' => array( $bm_type => 1 ), 'method' => 'cash', 'form_key' => wpb_key() ) );
$bm_after_fail = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_boxoffice_sales WHERE session_id=" . (int) $bm_session->session_id . " AND status='pending' AND (order_id IS NULL OR order_id=0)" );
$bm_ok = Pos::sell( array( 'occurrence_id' => $bm_occ, 'quantities' => array( $bm_type => 1 ), 'attendees' => array( $bm_type => array( array( 'name' => 'Grace Hopper', 'answers' => array( 'company' => 'Navy' ) ) ) ), 'method' => 'cash', 'form_key' => wpb_key() ) );
$bm_ok2 = Pos::sell( array( 'occurrence_id' => $bm_occ, 'quantities' => array( $bm_type => 1 ), 'attendees' => array( $bm_type => array( array( 'answers' => array( 'company' => 'ACME' ) ) ) ), 'method' => 'card', 'form_key' => wpb_key() ) );
$wpdb->query( $wpdb->prepare( 'SET SESSION sql_mode=%s', (string) $bm_mode ) );
check( 'failed sale on the legacy schema leaves no blocking claim (order_id 0 cleanup)', is_wp_error( $bm_failed ) && 'wpcap_attendee_field_required' === $bm_failed->get_error_code() && 1 === $bm_after_fail );
check( 'sales after a failed sale work (no duplicate order_id 0)', ! is_wp_error( $bm_ok ) && ! is_wp_error( $bm_ok2 ) && 'completed' === $bm_ok['sale']->status && 'completed' === $bm_ok2['sale']->status && 'YES' === $bm_col()['Null'] );

echo "Box office: participant fields and receipts\n";
$bm_types = Pos::ticket_types( $bm_occ );
check( 'POS ticket types carry the checkout participant fields', 2 === count( $bm_types[0]['fields'] ) && 'company' === $bm_types[0]['fields'][0]['key'] && true === $bm_types[0]['fields'][0]['required'] && 'checkbox' === $bm_types[0]['fields'][1]['type'] && false === $bm_types[0]['seated'] );
$bm_answer = static function ( $order_id ) use ( $wpdb, $p ) {
	return $wpdb->get_col( "SELECT a.answer FROM {$p}wpcap_participant_answers a JOIN {$p}wpcap_tickets t ON t.ticket_id=a.ticket_id JOIN {$p}wpcap_participant_fields f ON f.field_id=a.field_id WHERE f.field_key='company' AND t.order_id=" . (int) $order_id . ' ORDER BY t.ticket_id' );
};
check( 'required field answers given at the POS are stored per ticket', array( 'Navy' ) === $bm_answer( $bm_ok['sale']->order_id ) && 'Grace Hopper' === $wpdb->get_var( "SELECT a.attendee_name FROM {$p}wpcap_ticket_assignments a JOIN {$p}wpcap_tickets t ON t.ticket_id=a.ticket_id WHERE t.order_id=" . (int) $bm_ok['sale']->order_id ) );
$bm_form = PosPage::sale_input( array(
	'occurrence_id' => (string) $bm_occ,
	'qty' => array( $bm_type => '2' ),
	'attendees' => array( $bm_type => array(
		'0' => array( 'name' => 'Ada', 'email' => '', 'answers' => array( 'company' => 'Analytical Engines', 'newsletter' => '1' ) ),
		'1' => array( 'name' => '', 'email' => 'bob@example.com', 'answers' => array( 'company' => 'Bob & Co' ) ),
		'2' => array( 'name' => 'Too many', 'answers' => array( 'company' => 'X' ) ),
	) ),
	'method' => 'cash',
	'form_key' => wpb_key(),
) );
check( 'POS form attendee inputs are parsed and capped at the quantity', 2 === count( $bm_form['attendees'][ $bm_type ] ) && 'Bob & Co' === $bm_form['attendees'][ $bm_type ][1]['answers']['company'] );
$bm_two = Pos::sell( $bm_form );
check( 'POS sale with a required field for two tickets succeeds', ! is_wp_error( $bm_two ) && array( 'Analytical Engines', 'Bob & Co' ) === $bm_answer( $bm_two['sale']->order_id ) && 2 === count( tickets( $bm_two['sale']->order_id, 'valid' ) ) );
$bm_partial = Pos::sell( array( 'occurrence_id' => $bm_occ, 'quantities' => array( $bm_type => 2 ), 'attendees' => array( $bm_type => array( array( 'answers' => array( 'company' => 'Nur einer' ) ) ) ), 'method' => 'cash', 'form_key' => wpb_key() ) );
check( 'a required field missing for one of several tickets is rejected', is_wp_error( $bm_partial ) && 'wpcap_attendee_field_required' === $bm_partial->get_error_code() );

$bm_unbooked = $wpdb->get_row( "SELECT * FROM {$p}wpcap_boxoffice_sales WHERE session_id=" . (int) $bm_session->session_id . " AND status='pending' AND receipt_number IS NULL LIMIT 1" );
$wpdb->query( "UPDATE {$p}wpcap_boxoffice_sales SET tendered_minor=3000 WHERE sale_id=" . (int) $bm_unbooked->sale_id );
check( 'no receipt for an unbooked sale; booked sales keep theirs', false === Pos::is_booked( $bm_unbooked ) && null === Pos::receipt( $bm_unbooked->sale_id ) && '' === Pos::receipt_html( $bm_unbooked->sale_id ) && Pos::is_booked( $bm_ok['sale'] ) && false !== strpos( Pos::receipt_html( $bm_ok['sale']->sale_id ), (string) $bm_ok['sale']->receipt_number ) );
$wpdb->query( "DELETE FROM {$p}wpcap_boxoffice_sales WHERE sale_id=" . (int) $bm_unbooked->sale_id );
Pos::close_session( $bm_session->session_id, 0 );

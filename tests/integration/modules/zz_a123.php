<?php
/**
 * A123: retention periods per data type are settings; financial records (invoices, corrections,
 * box office shifts) are deleted after the statutory period (opt-in), buyer data pseudonymised.
 */
use PassCaptain\Privacy\Retention;
use PassCaptain\Settings;

echo "A123: retention per data type and financial records\n";
global $wpdb;
$p = $wpdb->prefix;

// Settings drive the periods; the filter still has the last word.
check( 'defaults unchanged (audit 730, messages 30, waitlist 30, partner codes 30, financial off)', 730 === Retention::days( 'audit_logs' ) && 30 === Retention::days( 'message_content' ) && 30 === Retention::days( 'waitlist' ) && 30 === Retention::days( 'partner_ticket_codes' ) && 0 === Retention::financial_years() );
Settings::update( array( 'retention_audit_logs_days' => 400, 'retention_waitlist_days' => 7, 'retention_message_content_days' => 0 ) );
check( 'settings change the periods (audit 400, waitlist 7, message content off)', 400 === Retention::days( 'audit_logs' ) && 7 === Retention::days( 'waitlist' ) && 0 === Retention::days( 'message_content' ) );
$a123_f = static fn( $d, $step ) => 'audit_logs' === $step ? 99 : $d;
add_filter( 'wpcap_privacy_retention_days', $a123_f, 10, 2 );
check( 'filter wpcap_privacy_retention_days still overrides a setting', 99 === Retention::days( 'audit_logs' ) );
remove_filter( 'wpcap_privacy_retention_days', $a123_f, 10 );
Settings::update( array( 'retention_audit_logs_days' => 730, 'retention_waitlist_days' => 30, 'retention_message_content_days' => 30 ) );
check( 'all retention settings are on the settings page', ! array_diff( array_values( Retention::SETTINGS ), array_keys( PassCaptain\Admin\SettingsPage::sections()['privacy']['fields'] ) ) && isset( PassCaptain\Admin\SettingsPage::sections()['privacy']['fields']['retention_financial_years'] ) );

// Financial records.
$a123_now = gmmktime( 12, 0, 0, 6, 1, 2026 );
$a123_type = ex_type( ex_occ( ex_event( 'Belegfrist' ) ), 'Beleg', 2000 );
$a123_mk = static function ( $email, $issued ) use ( $wpdb, $p, $a123_type ) {
	list( $order_id ) = ex_buy( $a123_type, 1, $email );
	$wpdb->update( "{$p}wpcap_orders", array( 'billing' => wp_json_encode( array( 'name' => 'Firma GmbH', 'address' => 'Gasse 1' ) ) ), array( 'order_id' => $order_id ) );
	PassCaptain\Invoices::issue_for_order( $order_id );
	$wpdb->update( "{$p}wpcap_invoices", array( 'issued_at' => $issued ), array( 'order_id' => $order_id ) );
	return array( $order_id, (int) $wpdb->get_var( $wpdb->prepare( "SELECT invoice_id FROM {$p}wpcap_invoices WHERE order_id=%d", $order_id ) ) );
};
list( $a123_old_order, $a123_old_inv ) = $a123_mk( 'beleg-alt@example.com', '2015-12-31 22:00:00' );
list( $a123_new_order, $a123_new_inv ) = $a123_mk( 'beleg-neu@example.com', '2016-01-01 00:30:00' );
list( $a123_cn_order, $a123_cn_inv ) = $a123_mk( 'beleg-korr@example.com', '2015-03-01 10:00:00' );
$wpdb->insert( "{$p}wpcap_credit_notes", array( 'invoice_id' => $a123_cn_inv, 'refund_id' => 900000 + $a123_cn_inv, 'credit_note_number' => 'RK-A123-' . $a123_cn_inv, 'currency' => 'EUR', 'net_minor' => 0, 'tax_minor' => 0, 'total_minor' => 100, 'issued_at' => '2020-02-01 10:00:00' ) );
$a123_inv = static fn( $id ) => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_invoices WHERE invoice_id=%d", $id ) );
$a123_lines = static fn( $id ) => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_invoice_lines WHERE invoice_id=%d", $id ) );

Retention::run( $a123_now );
check( 'financial retention off by default: nothing deleted', 1 === $a123_inv( $a123_old_inv ) && 1 === $a123_inv( $a123_new_inv ) );

Settings::update( array( 'retention_financial_years' => 10 ) );
check( 'cutoff with 10 years in 2026 is 1 January 2016', '2016-01-01 00:00:00' === Retention::financial_cutoff( $a123_now ) );
$a123_counts = Retention::run( $a123_now );
$a123_order = PassCaptain\Orders::get( $a123_old_order );
check( 'invoice issued 2015 (UTC) deleted with its lines', 0 === $a123_inv( $a123_old_inv ) && 0 === $a123_lines( $a123_old_inv ) && (int) $a123_counts['financial_records'] >= 1 );
check( 'invoice issued 2016 kept (period runs until end of 2026)', 1 === $a123_inv( $a123_new_inv ) && $a123_lines( $a123_new_inv ) > 0 );
check( 'buyer data of the deleted invoice pseudonymised, amounts kept', false === strpos( (string) $a123_order->customer_email, 'beleg-alt' ) && null === $a123_order->billing && 2000 === (int) $a123_order->total_minor );
check( 'invoice of 2015 with a correction from 2020 is kept (correction still in its period)', 1 === $a123_inv( $a123_cn_inv ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_credit_notes WHERE invoice_id=%d", $a123_cn_inv ) ) );
Retention::run( gmmktime( 12, 0, 0, 6, 1, 2031 ) );
check( 'once the correction is past its period both go (2031: corrections of 2020 deleted, then the invoice)', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_credit_notes WHERE invoice_id=%d", $a123_cn_inv ) ) && 0 === $a123_inv( $a123_cn_inv ) );

// Box office shifts.
if ( PassCaptain\Privacy::has_table( 'wpcap_boxoffice_sessions' ) ) {
	$a123_row = static function ( $table, array $values ) use ( $wpdb, $p ) {
		foreach ( $wpdb->get_results( "SHOW COLUMNS FROM {$p}{$table}" ) as $col ) {
			if ( 'NO' === $col->Null && null === $col->Default && false === strpos( (string) $col->Extra, 'auto_increment' ) && ! array_key_exists( $col->Field, $values ) ) {
				$values[ $col->Field ] = preg_match( '/int|decimal/', $col->Type ) ? 0 : ( preg_match( '/date/', $col->Type ) ? '2015-01-01 00:00:00' : '' );
			}
		}
		$wpdb->insert( "{$p}{$table}", $values );
		return (int) $wpdb->insert_id;
	};
	$a123_old_shift = $a123_row( 'wpcap_boxoffice_sessions', array( 'user_id' => 1, 'status' => 'closed', 'opened_at' => '2015-05-01 08:00:00', 'closed_at' => '2015-05-01 20:00:00' ) );
	$a123_row( 'wpcap_boxoffice_zreports', array( 'shift_id' => $a123_old_shift, 'z_number' => 990001, 'register_id' => 990001, 'closed_at' => '2015-05-01 20:00:00', 'created_at' => '2015-05-01 20:00:00' ) );
	$a123_open_shift = $a123_row( 'wpcap_boxoffice_sessions', array( 'user_id' => 1, 'status' => 'open', 'opened_at' => '2015-05-02 08:00:00' ) );
	$a123_recent = $a123_row( 'wpcap_boxoffice_sessions', array( 'user_id' => 1, 'status' => 'closed', 'opened_at' => '2016-02-01 08:00:00', 'closed_at' => '2016-02-01 20:00:00' ) );
	$a123_c = Retention::run( $a123_now );
	$a123_has = static fn( $id ) => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_boxoffice_sessions WHERE session_id=%d", $id ) );
	check( 'box office: shift closed 2015 deleted with its Z report', 0 === $a123_has( $a123_old_shift ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_boxoffice_zreports WHERE shift_id=%d", $a123_old_shift ) ) && (int) ( $a123_c['boxoffice_shifts'] ?? 0 ) >= 1 );
	check( 'box office: open shift and shift closed 2016 kept', 1 === $a123_has( $a123_open_shift ) && 1 === $a123_has( $a123_recent ) );
	$wpdb->query( "DELETE FROM {$p}wpcap_boxoffice_sessions WHERE session_id IN ($a123_open_shift,$a123_recent)" );
}
Settings::update( array( 'retention_financial_years' => 0 ) );

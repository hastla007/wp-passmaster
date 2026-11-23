<?php
/**
 * A425, owner decision 23.11.2026 (2): tax of the service fee. "wie das Ticket (Nebenleistung)" (default,
 * same rate and profile as the ticket line) or "eigenes Steuerprofil": the active tax profile of the
 * configured tax class for the place of supply, else the rate setting, else the ticket rate. Order
 * lines, invoice, correction document (per rate) and report follow the fee line's rate.
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

use PassCaptain\Invoices;
use PassCaptain\Orders;
use PassCaptain\Refunds;
use PassCaptain\Sales\TaxProfiles;
use PassCaptain\ServiceFee;
use PassCaptain\Settings;
use PassCaptain\Tax\Profiles;

echo "A425: tax of the service fee\n";
global $wpdb;
$p = $wpdb->prefix;
$ft_saved = Settings::all();
Settings::update( array( 'refund_approval_threshold' => 0, 'auto_invoice' => 0, 'require_2fa_for_refunds' => 0, 'service_fee_ticket_minor' => 150, 'service_fee_order_minor' => 200 ) );
ServiceFee::register();

check( 'A425 tax: default is "wie das Ticket"', 'ticket' === Settings::defaults()['service_fee_tax'] && 'ticket' === ServiceFee::tax_mode() );

function ft_type( $price = 2500, $tax_bp = 700 ) {
	global $wpdb;
	$id = make_type( 100, 10, $price );
	$wpdb->update( $wpdb->prefix . 'wpcap_ticket_types', array( 'tax_rate_bp' => $tax_bp ), array( 'ticket_type_id' => $id ) );
	// Own place of supply (LU) so profiles of other modules (DE/AT standard) do not apply.
	$event = (int) $wpdb->get_var( $wpdb->prepare( "SELECT o.event_id FROM {$wpdb->prefix}wpcap_occurrences o INNER JOIN {$wpdb->prefix}wpcap_ticket_types t ON t.occurrence_id=o.occurrence_id WHERE t.ticket_type_id=%d", $id ) );
	TaxProfiles::set_event_country( $event, 'LU' );
	return $id;
}
function ft_order( array $items ) {
	$GLOBALS['wpcap_test_transients'] = array();
	$lines = array();
	foreach ( $items as $type => $qty ) { $lines[] = array( 'ticket_type_id' => $type, 'quantity' => $qty ); }
	return Orders::create( array( 'email' => 'ft-' . uniqid() . '@example.com', 'items' => $lines, 'idempotency_key' => 'ft-' . wp_generate_uuid4() ) );
}
function ft_lines( $order_id ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_order_items WHERE order_id=%d ORDER BY item_id", (int) $order_id ) );
}

// Like the ticket (default): the fee shares the ticket rate.
$ft_type = ft_type();
$ft_like = ft_order( array( $ft_type => 1 ) );
$ft_like_lines = ft_lines( $ft_like['order_id'] );
check( 'A425 tax: "wie das Ticket" – fee lines take the ticket rate', 3 === count( $ft_like_lines ) && 700 === (int) $ft_like_lines[1]->tax_rate_bp && 700 === (int) $ft_like_lines[2]->tax_rate_bp );

// Own tax profile: class "ft_service" with an active LU profile of 17 %.
$ft_profile = TaxProfiles::create( array( 'country_code' => 'LU', 'tax_class' => 'ft_service', 'rate' => '17', 'version' => 'lu-fee-' . uniqid(), 'effective_from' => gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS ), 'label' => 'LU Gebühr' ) );
TaxProfiles::activate( $ft_profile );
Settings::update( array( 'service_fee_tax' => 'own', 'service_fee_tax_class' => 'ft_service', 'service_fee_tax_rate' => '' ) );
$ft_own = ft_order( array( $ft_type => 2 ) );
$ft_own_id = (int) $ft_own['order_id'];
$ft_own_lines = ft_lines( $ft_own_id );
$ft_own_snap = json_decode( (string) $ft_own_lines[1]->line_snapshot, true );
check( 'A425 tax: "eigenes Steuerprofil" – the ticket keeps its rate, fee lines take the profile rate', ! is_wp_error( $ft_own ) && 700 === (int) $ft_own_lines[0]->tax_rate_bp && 1700 === (int) $ft_own_lines[1]->tax_rate_bp && 1700 === (int) $ft_own_lines[2]->tax_rate_bp );
check( 'A425 tax: fee lines store profile id, source and snapshot of their own profile', (int) $ft_profile === (int) $ft_own_lines[1]->tax_profile_id && Profiles::SOURCE_PROFILE === $ft_own_lines[1]->tax_source && 'LU' === $ft_own_lines[1]->tax_country && 'ft_service' === ( $ft_own_snap['tax']['tax_class'] ?? '' ) );
check( 'A425 tax: fee VAT at 17 % and the order VAT adds up', Orders::gross_tax( 300, 1700 ) === (int) $ft_own_lines[1]->tax_minor && Orders::gross_tax( 200, 1700 ) === (int) $ft_own_lines[2]->tax_minor && Orders::gross_tax( 5000, 700 ) + Orders::gross_tax( 300, 1700 ) + Orders::gross_tax( 200, 1700 ) === (int) Orders::get( $ft_own_id )->tax_minor );
check( 'A425 tax: the price snapshot names the tax mode', 'own' === ( json_decode( (string) Orders::get( $ft_own_id )->price_snapshot, true )['service_fee']['tax'] ?? '' ) );

// Invoice: VAT summary per rate.
Orders::confirm_manual_payment( $ft_own_id );
$ft_inv = Invoices::issue_for_order( $ft_own_id );
$ft_inv_lines = is_array( $ft_inv ) ? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}wpcap_invoice_lines WHERE invoice_id=%d ORDER BY invoice_line_id", (int) $ft_inv['invoice_id'] ) ) : array();
$ft_inv_rates = array();
foreach ( $ft_inv_lines as $ft_l ) { $ft_inv_rates[ (string) (float) $ft_l->tax_rate ] = ( $ft_inv_rates[ (string) (float) $ft_l->tax_rate ] ?? 0 ) + (int) $ft_l->tax_minor; }
check( 'A425 tax: the invoice shows the fee positions at 17 %, the tickets at 7 %', 3 === count( $ft_inv_lines ) && Orders::gross_tax( 5000, 700 ) === ( $ft_inv_rates['7'] ?? null ) && Orders::gross_tax( 300, 1700 ) + Orders::gross_tax( 200, 1700 ) === ( $ft_inv_rates['17'] ?? null ) );

// Correction document of a ticket refund (fee refunded with the ticket): the fee part at its own rate.
$ft_tickets = $wpdb->get_results( $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=%d ORDER BY ticket_id", $ft_own_id ) );
$ft_refund = Refunds::request( $ft_own_id, array( (int) $ft_tickets[0]->ticket_id ), 'Storno', 'ft-r1', array( 'service_fee' => 'refund' ) );
$ft_cn = is_array( $ft_refund ) ? Invoices::credit_note_for_refund( (int) $ft_refund['refund_id'] ) : null;
$ft_cn_rates = array();
foreach ( is_array( $ft_cn ) ? (array) json_decode( (string) $ft_cn['rates'], true ) : array() as $ft_r ) { $ft_cn_rates[ (int) $ft_r['rate_bp'] ] = (int) $ft_r['gross']; }
$ft_cn_lines = is_array( $ft_cn ) ? $wpdb->get_results( $wpdb->prepare( "SELECT tax_rate_bp,total_minor FROM {$p}wpcap_credit_note_lines WHERE credit_note_id=%d ORDER BY tax_rate_bp", (int) $ft_cn['credit_note_id'] ) ) : array();
check( 'A425 tax: the correction splits ticket (7 %) and its per-ticket fee (17 %)', is_array( $ft_refund ) && 2650 === (int) $ft_refund['amount_minor'] && 2500 === ( $ft_cn_rates[700] ?? null ) && 150 === ( $ft_cn_rates[1700] ?? null ) );
check( 'A425 tax: the correction lines per rate follow the same split', 2 === count( $ft_cn_lines ) && 700 === (int) $ft_cn_lines[0]->tax_rate_bp && 2500 === (int) $ft_cn_lines[0]->total_minor && 1700 === (int) $ft_cn_lines[1]->tax_rate_bp && 150 === (int) $ft_cn_lines[1]->total_minor );

// Report: the fee is in the 17 % rate bucket.
$ft_today = \PassCaptain\Clock::today( \PassCaptain\Reports\Scope::site_timezone() );
$ft_report = PassCaptain\Reports\Data::build( array( 'from' => $ft_today, 'to' => $ft_today ), null );
check( 'A425 tax: the report books the fee at its own rate and still reconciles', isset( $ft_report['rates'][1700] ) && (int) $ft_report['rates'][1700]['gross'] >= 500 && array() === PassCaptain\Reports\Data::reconcile( $ft_report ) );

// No active profile for the class: the rate setting, else the ticket rate.
Settings::update( array( 'service_fee_tax_class' => 'ft_none', 'service_fee_tax_rate' => '19' ) );
$ft_fallback_lines = ft_lines( ft_order( array( $ft_type => 1 ) )['order_id'] );
check( 'A425 tax: without a matching profile the rate setting applies (source "setting")', 1900 === (int) $ft_fallback_lines[1]->tax_rate_bp && Profiles::SOURCE_SETTING === $ft_fallback_lines[1]->tax_source && 'Steuersatz der Servicegebühr (Einstellung)' === Profiles::source_label( $ft_fallback_lines[1] ) );
Settings::update( array( 'service_fee_tax_rate' => '' ) );
$ft_ticket_rate_lines = ft_lines( ft_order( array( $ft_type => 1 ) )['order_id'] );
check( 'A425 tax: without profile and rate setting the ticket rate applies', 700 === (int) $ft_ticket_rate_lines[1]->tax_rate_bp );
check( 'A425 tax: the rate setting is validated', '7,7' === PassCaptain\Admin\SettingsPage::sanitize_fee_tax_rate( '7.7 %' ) && '' === PassCaptain\Admin\SettingsPage::sanitize_fee_tax_rate( '' ) && is_wp_error( PassCaptain\Admin\SettingsPage::sanitize_fee_tax_rate( 'zwanzig' ) ) );
check( 'A425 tax: the settings offer the tax classes of the profiles', isset( PassCaptain\Admin\SettingsPage::fee_tax_classes()['ft_service'] ) && isset( PassCaptain\Admin\SettingsPage::fee_tax_classes()['service'] ) );

// Filter.
$ft_filter = static function ( $tax ) { $tax['rate_bp'] = 500; $tax['tax_info']['rate_bp'] = 500; return $tax; };
add_filter( 'wpcap_service_fee_tax', $ft_filter );
$ft_filtered_lines = ft_lines( ft_order( array( $ft_type => 1 ) )['order_id'] );
remove_filter( 'wpcap_service_fee_tax', $ft_filter );
check( 'A425 tax: wpcap_service_fee_tax changes the own tax', 500 === (int) $ft_filtered_lines[1]->tax_rate_bp && 700 === (int) $ft_filtered_lines[0]->tax_rate_bp );

TaxProfiles::archive( $ft_profile );
update_option( Settings::OPTION, $ft_saved );
Settings::flush();

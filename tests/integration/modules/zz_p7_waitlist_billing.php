<?php
/**
 * L707: waitlist offers ask for invoice data like the checkout – optional billing fields on the offer
 * page of paid offers, validated on acceptance, stored on the order, required above the checkout's
 * billing threshold (the offer stays open when they are missing or invalid).
 * Included from run.php (uses check(), make_type()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Billing;
use PassCaptain\Orders;
use PassCaptain\Settings;
use PassCaptain\Waitlists;

global $wpdb;
$p = $wpdb->prefix;

echo "L707: invoice data on waitlist offers\n";
Waitlists::register();
$p7_saved = array_intersect_key( Settings::all(), array_flip( array( 'enabled_providers', 'require_terms', 'waitlist', 'billing_required_threshold_minor', 'bank_iban', 'bank_recipient' ) ) );
Settings::update( array( 'enabled_providers' => array( 'manual' ), 'bank_iban' => 'AT611904300234573201', 'bank_recipient' => 'Testverein', 'require_terms' => 0, 'billing_required_threshold_minor' => 25000, 'waitlist' => array( 'auto_offers' => 1, 'offer_hours' => 24, 'max_offers_per_run' => 10, 'strict_fifo' => 0, 'double_opt_in' => 0 ) ) );
$p7_delay = $GLOBALS['wpcap_test_filters']['wpcap_waitlist_process_delay'] ?? null;
$GLOBALS['wpcap_test_filters']['wpcap_waitlist_process_delay'] = 0;
$p7_user = $GLOBALS['wpcap_test_user_id'] ?? 0;
$GLOBALS['wpcap_test_user_id'] = 0;
update_option( Waitlists::QUEUE_OPTION, array() );

$p7_offer = static function ( $price, $qty, $email ) use ( $wpdb, $p ) {
	$type = make_type( $qty, 10, $price );
	$occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=" . (int) $type );
	$event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$occ" );
	$GLOBALS['wpcap_test_transients'] = array();
	$sold = Orders::create( array( 'email' => 'p7-first-' . uniqid() . '@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => 'p7-' . uniqid( '', true ), 'billing' => array( 'enabled' => 1, 'first_name' => 'Erst', 'last_name' => 'Käufer', 'street' => 'Weg 1', 'postcode' => '1010', 'city' => 'Wien', 'country' => 'AT' ) ) );
	$_SERVER['REMOTE_ADDR'] = '10.77.' . random_int( 0, 255 ) . '.' . random_int( 0, 255 );
	$GLOBALS['wpcap_test_transients'] = array();
	Waitlists::join( $event, $occ, $type, $email, $qty );
	if ( ! is_wp_error( $sold ) ) { Orders::cancel_order( $sold['order_id'], 'test' ); }
	Waitlists::run_due( true );
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_waitlist WHERE ticket_type_id=%d AND email=%s", $type, $email ) );
	$token = '';
	foreach ( array_reverse( $GLOBALS['wpcap_test_mails'] ) as $mail ) {
		if ( $mail['to'] === $email && preg_match( '/wpcap_waitlist_accept=\d+(?:&amp;|&)token=([a-f0-9]{64})/', $mail['body'], $m ) ) { $token = $m[1]; break; }
	}
	return array( $row, $token, is_wp_error( $sold ) ? $sold : null );
};
$p7_status = static function ( $row ) use ( $wpdb, $p ) {
	return (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$p}wpcap_waitlist WHERE waitlist_id=%d", (int) $row->waitlist_id ) );
};
$p7_billing = array( 'enabled' => 1, 'company' => 'Muster GmbH', 'first_name' => 'Maria', 'last_name' => 'Muster', 'street' => 'Ring 5', 'postcode' => '1010', 'city' => 'Wien', 'country' => 'AT', 'vat_id' => 'ATU12345678', 'po_number' => 'PO-7' );

// Above the threshold (3 x 100.00 > 250.00).
list( $p7_big, $p7_big_tok, $p7_err ) = $p7_offer( 10000, 3, 'p7-big@example.com' );
check( 'L707 setup: a paid offer above the billing threshold exists', null === $p7_err && $p7_big && 'offered' === $p7_big->status && 64 === strlen( $p7_big_tok ) );
$p7_big_order = Orders::get( (int) $p7_big->order_id );
$p7_form = Waitlists::billing_form_html( $p7_big_order );
check( 'L707: the offer page of a paid offer shows the billing fields, opened above the threshold', false !== strpos( $p7_form, 'name="billing[first_name]"' ) && false !== strpos( $p7_form, 'name="billing[vat_id]"' ) && 0 === strpos( $p7_form, '<details open ' ) );
$p7_missing = Waitlists::accept_offer( (int) $p7_big->waitlist_id, $p7_big_tok, array( 'provider' => 'manual' ) );
check( 'L707: accepting above the threshold without billing data is refused, the offer stays open', is_wp_error( $p7_missing ) && 'wpcap_billing_required' === $p7_missing->get_error_code() && 'offered' === $p7_status( $p7_big ) );
$p7_bad = Waitlists::accept_offer( (int) $p7_big->waitlist_id, $p7_big_tok, array( 'provider' => 'manual', 'billing' => array( 'enabled' => 1, 'first_name' => 'Maria', 'last_name' => 'Muster', 'street' => 'Ring 5', 'postcode' => '1010', 'city' => 'Wien', 'country' => 'AT', 'vat_id' => 'ATX1' ) ) );
check( 'L707: invalid billing data (VAT ID format) is refused with the field, the offer stays open', is_wp_error( $p7_bad ) && 'wpcap_billing_invalid' === $p7_bad->get_error_code() && 'billing_vat_id' === ( $p7_bad->get_error_data()['field'] ?? '' ) && 'offered' === $p7_status( $p7_big ) );
$p7_ok = Waitlists::accept_offer( (int) $p7_big->waitlist_id, $p7_big_tok, array( 'provider' => 'manual', 'billing' => $p7_billing ) );
$p7_stored = Billing::for_order( (int) $p7_big->order_id );
check( 'L707: accepting with billing data works and stores it on the order (VAT ID normalised)', ! is_wp_error( $p7_ok ) && 'accepted' === $p7_ok['status'] && 'Muster GmbH' === ( $p7_stored['company'] ?? '' ) && 'ATU12345678' === ( $p7_stored['vat_id'] ?? '' ) && 'PO-7' === ( $p7_stored['po_number'] ?? '' ) );
check( 'L707: the invoice buyer snapshot takes the billing data', 'Maria Muster' === ( Billing::snapshot( (int) $p7_big->order_id )['name'] ?? '' ) );

// Below the threshold: optional.
list( $p7_small, $p7_small_tok ) = $p7_offer( 1000, 1, 'p7-small@example.com' );
$p7_small_form = Waitlists::billing_form_html( Orders::get( (int) $p7_small->order_id ) );
check( 'L707: below the threshold the billing section is offered but closed', false !== strpos( $p7_small_form, 'name="billing[city]"' ) && 0 !== strpos( $p7_small_form, '<details open ' ) );
$p7_small_ok = Waitlists::accept_offer( (int) $p7_small->waitlist_id, $p7_small_tok, array( 'provider' => 'manual' ) );
check( 'L707: below the threshold an offer is accepted without billing data (none stored)', ! is_wp_error( $p7_small_ok ) && array() === Billing::for_order( (int) $p7_small->order_id ) );

// Free offers: no billing section.
list( $p7_free ) = $p7_offer( 0, 1, 'p7-free@example.com' );
check( 'L707: free offers show no billing section', $p7_free && '' === Waitlists::billing_form_html( Orders::get( (int) $p7_free->order_id ) ) );

// Threshold switched off: no requirement even for large offers.
Settings::update( array( 'billing_required_threshold_minor' => 0 ) );
list( $p7_off, $p7_off_tok ) = $p7_offer( 20000, 2, 'p7-off@example.com' );
$p7_off_ok = Waitlists::accept_offer( (int) $p7_off->waitlist_id, $p7_off_tok, array( 'provider' => 'manual' ) );
check( 'L707: with the threshold at 0 no billing data is required', ! is_wp_error( $p7_off_ok ) && 'accepted' === $p7_off_ok['status'] );

Settings::update( $p7_saved );
if ( null === $p7_delay ) { unset( $GLOBALS['wpcap_test_filters']['wpcap_waitlist_process_delay'] ); } else { $GLOBALS['wpcap_test_filters']['wpcap_waitlist_process_delay'] = $p7_delay; }
$GLOBALS['wpcap_test_user_id'] = $p7_user;
update_option( Waitlists::QUEUE_OPTION, array() );

<?php
/**
 * A491 (technical core): organizer terms were not versioned – changing commission, fee per ticket or
 * refund policy recomputed every not yet settled sale and the corrections of settled ones with the
 * new terms. Now the terms that applied when the order was created are used (terms history, like the
 * assignment history of A448).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Partners\Repository as Partners;
use PassCaptain\Partners\Settlements;

global $wpdb;
$p = $wpdb->prefix;
$a491_user = $GLOBALS['wpcap_test_user_id'] ?? null;
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();

$a491_m1 = gmdate( 'Y-m', strtotime( 'first day of -2 month' ) );
$a491_now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'Konditionen-Konzert', 'slug' => 'a491' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $a491_now, 'updated_at' => $a491_now ) );
$a491_ev = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $a491_ev, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 30 * 86400 ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => 0, 'status' => 'scheduled' ) );
$a491_occ = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $a491_occ, 'name' => 'Standard', 'price_minor' => 2000, 'currency' => 'EUR', 'capacity' => 0, 'max_per_order' => 10, 'status' => 'active' ) );
$a491_type = (int) $wpdb->insert_id;
$a491_data = array( 'name' => 'Konditionen GmbH', 'currency' => 'EUR', 'commission_bp' => 1000, 'fee_per_ticket_minor' => 50, 'refund_policy' => 'reverse_all', 'billing_address' => "Str 1\n1010 Wien", 'vat_id' => 'ATU12345678', 'sales_model' => 'agent', 'settlement_start' => $a491_m1 . '-01' );
$a491_partner = Partners::save( $a491_data );
Partners::set_events( $a491_partner, array( $a491_ev ) );

function a491_sale( $type, $created_at ) {
	global $wpdb;
	$GLOBALS['wpcap_test_transients'] = array();
	$r = Orders::create( array( 'email' => 'k' . uniqid() . '@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 2 ) ), 'idempotency_key' => 'a491-' . uniqid( '', true ) ), array( 'skip_rate_limit' => true ) );
	if ( is_wp_error( $r ) ) { return 0; }
	Orders::confirm_manual_payment( $r['order_id'] );
	if ( $created_at ) { $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_orders SET created_at=%s WHERE order_id=%d", $created_at, $r['order_id'] ) ); }
	return (int) $r['order_id'];
}
function a491_line( array $computed, $order_id, $kind = 'sale' ) {
	foreach ( $computed['lines'] as $line ) { if ( (int) $line['order_id'] === (int) $order_id && $kind === $line['kind'] ) { return $line; } }
	return null;
}

echo "A491: organizer terms apply from the time they were set\n";
check( 'A491: schema 3.10.18 table wpcap_partner_terms_history exists', Partners::terms_ready() );
$a491_old = a491_sale( $a491_type, $a491_m1 . '-15 12:00:00' );
// Terms change now: 20 % commission, 1,00 fee per ticket.
Partners::save( array_merge( $a491_data, array( 'commission_bp' => 2000, 'fee_per_ticket_minor' => 100 ) ), $a491_partner );
$a491_new = a491_sale( $a491_type, '' );
$a491_partner_row = Partners::get( $a491_partner );
$a491_m1_calc = Settlements::compute( $a491_partner_row, $a491_m1 );
$a491_line_old = a491_line( $a491_m1_calc, $a491_old );
check( 'A491: an unsettled sale from before the change keeps the old terms (10 %, 0,50/ticket)', $a491_line_old && 400 === (int) $a491_line_old['commission_minor'] && 100 === (int) $a491_line_old['fee_minor'] );
$a491_cur_calc = Settlements::compute( $a491_partner_row, gmdate( 'Y-m' ) );
$a491_line_new = a491_line( $a491_cur_calc, $a491_new );
check( 'A491: a sale after the change uses the new terms (20 %, 1,00/ticket)', $a491_line_new && 800 === (int) $a491_line_new['commission_minor'] && 200 === (int) $a491_line_new['fee_minor'] );
$a491_history = Partners::terms_history( $a491_partner );
check( 'A491: the history holds the previous terms (since always) and the new version', 2 === count( $a491_history ) && 2000 === (int) $a491_history[0]->commission_bp && null === $a491_history[1]->valid_from && 1000 === (int) $a491_history[1]->commission_bp );
$a491_unchanged = Partners::save( array_merge( $a491_data, array( 'commission_bp' => 2000, 'fee_per_ticket_minor' => 100, 'notes' => 'nur Notiz' ) ), $a491_partner );
check( 'A491: saving without a terms change records no new version', 2 === count( Partners::terms_history( $a491_partner ) ) );
// A refund of the old sale today is a correction: it reverses what was charged on the sale.
$a491_settings = array( 'refund_approval_threshold' => PassCaptain\Settings::get( 'refund_approval_threshold' ), 'require_2fa_for_refunds' => PassCaptain\Settings::get( 'require_2fa_for_refunds' ) );
PassCaptain\Settings::update( array( 'refund_approval_threshold' => 0, 'require_2fa_for_refunds' => 0 ) );
$a491_refund = PassCaptain\Refunds::request( $a491_old, array(), 'Kulanz', 'a491-r-' . uniqid() );
$a491_corr = a491_line( Settlements::compute( Partners::get( $a491_partner ), gmdate( 'Y-m' ) ), $a491_old, 'correction' );
check( 'A491: the correction of an old sale reverses the old commission and fee (-400 / -100)', ! is_wp_error( $a491_refund ) && $a491_corr && -400 === (int) $a491_corr['commission_minor'] && -100 === (int) $a491_corr['fee_minor'] );

Partners::set_events( $a491_partner, array() );
PassCaptain\Settings::update( $a491_settings );
$GLOBALS['wpcap_test_user_id'] = $a491_user;

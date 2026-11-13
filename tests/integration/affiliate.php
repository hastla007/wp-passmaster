<?php
/** Affiliate Captain adapter (contract v1). Included from run.php; contract functions are stubbed. */
// phpcs:ignoreFile
use PassCaptain\Orders;
use PassCaptain\Integrations\AffiliateCaptain\Adapter as AffiliateAdapter;

echo "Affiliate Captain adapter\n";
$GLOBALS['wpac_test_version'] = '1.1.0';
$GLOBALS['wpac_test_sales']   = array();
$GLOBALS['wpac_test_refunds'] = array();
$GLOBALS['wpac_test_touch']   = null;
if ( ! function_exists( 'wpac_contract_version' ) ) {
	function wpac_contract_version() { return $GLOBALS['wpac_test_version']; }
	function wpac_record_sale( array $payload ) { $GLOBALS['wpac_test_sales'][] = $payload; return array( 'status' => 'created' ); }
	function wpac_record_refund( array $payload ) { $GLOBALS['wpac_test_refunds'][] = $payload; return array( 'status' => 'adjusted' ); }
	function wpac_resolve_partner_code( $code, array $context = array() ) { return 'NORD-1' === strtoupper( $code ) ? array( 'affiliate_id' => 7, 'code_id' => 3, 'code' => 'NORD-1' ) : null; }
	function wpac_current_touch() { return $GLOBALS['wpac_test_touch']; }
}
if ( ! function_exists( 'wp_salt' ) ) { function wp_salt( $scheme = 'auth' ) { return 'salt-' . $scheme; } }
AffiliateAdapter::register();
$aff_last_sale = static fn() => end( $GLOBALS['wpac_test_sales'] );
$aff_snapshot  = static function ( $order_id ) { global $wpdb; return json_decode( $wpdb->get_var( "SELECT price_snapshot FROM {$wpdb->prefix}wpcap_orders WHERE order_id=" . (int) $order_id ), true ); };
$aff_order     = static function ( $type, $qty, $payload = array(), $internal = array() ) {
	return Orders::create( array_merge( array( 'email' => 'aff' . uniqid() . '@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => 'aff-' . uniqid() ), $payload ), $internal );
};

check( 'affiliate adapter is available for contract 1.x', AffiliateAdapter::available() );
$GLOBALS['wpac_test_version'] = '2.0.0';
check( 'affiliate adapter refuses an incompatible major contract', ! AffiliateAdapter::available() );
$GLOBALS['wpac_test_version'] = '1.1.0';
check( 'integration is listed on the Affiliate Captain status page', isset( apply_filters( 'wpac_registered_integrations', array() )['pass-captain'] ) );
check( 'public checkout accepts partner_code', in_array( 'partner_code', apply_filters( 'wpcap_checkout_payload_keys', array( 'email' ) ), true ) );
ob_start(); do_action( 'wpcap_checkout_form_fields', null, array() ); $aff_field = ob_get_clean();
check( 'checkout form shows the partner code field', false !== strpos( $aff_field, 'name="partner_code"' ) );

$aff_type = make_type( 50, 10, 11900 );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET tax_rate_bp=1900 WHERE ticket_type_id=$aff_type" );
$bad = $aff_order( $aff_type, 1, array( 'partner_code' => 'FALSCH' ) );
check( 'unknown partner code aborts the order with a clear message', is_wp_error( $bad ) && false !== strpos( $bad->get_error_message(), 'Partnercode' ) );
$GLOBALS['wpac_test_touch'] = array( 'code' => 'NORD-1', 'method' => 'cookie', 'campaign' => 'sommer', 'content_id' => 42 );
$o1 = $aff_order( $aff_type, 2, array( 'partner_code' => 'nord-1' ) );
$snap = $aff_snapshot( $o1['order_id'] );
check( 'referral (code, touch, channel) is stored with the order', 'NORD-1' === ( $snap['affiliate_referral']['code'] ?? '' ) && 'cookie' === ( $snap['affiliate_referral']['touch']['method'] ?? '' ) && 'native' === $snap['affiliate_referral']['channel'] );
check( 'nothing is reported before payment', 0 === count( $GLOBALS['wpac_test_sales'] ) );
$GLOBALS['wpac_test_touch'] = null;
Orders::confirm_manual_payment( $o1['order_id'] );
$sale = $aff_last_sale();
check( 'paid order is reported once as pass-captain sale', 1 === count( $GLOBALS['wpac_test_sales'] ) && 'pass-captain' === $sale['source'] && (string) $o1['order_id'] === $sale['order_id'] );
check( 'sale carries code and touch from the checkout request', 'NORD-1' === $sale['partner_code'] && 'sommer' === $sale['touch']['campaign'] && 42 === $sale['touch']['content_id'] );
check( 'net base excludes the stored VAT (2 × 119.00 gross → 200.00 net)', 20000 === $sale['items'][0]['net_minor'] && 2 === $sale['items'][0]['quantity'] && 0 === strpos( $sale['items'][0]['sku'], 'PASS-E' ) && ! isset( $sale['items'][0]['_type_id'] ) && ! isset( $sale['items'][0]['_event_end'] ) );
check( 'commission is held until the date has taken place', ! empty( $sale['hold_until'] ) && strtotime( $sale['hold_until'] . ' UTC' ) > time() );
do_action( 'wpcap_order_paid', $o1['order_id'] );
check( 'a repeated paid event does not report again', 1 === count( $GLOBALS['wpac_test_sales'] ) );

$GLOBALS['wpac_test_touch'] = array( 'code' => 'STAFF', 'method' => 'cookie', 'campaign' => '', 'content_id' => 0 );
$pos = $aff_order( $aff_type, 1, array(), array( 'key_namespace' => 'pos', 'skip_rate_limit' => true, 'source' => 'pos' ) );
Orders::confirm_manual_payment( $pos['order_id'] );
$sale = $aff_last_sale();
check( 'POS sale is reported without the staff member\'s referral', (string) $pos['order_id'] === $sale['order_id'] && ! isset( $sale['touch'] ) );
$GLOBALS['wpac_test_touch'] = null;

$before = count( $GLOBALS['wpac_test_sales'] );
$woo = Orders::create( array( 'email' => 'woo-aff@example.com', 'items' => array( array( 'ticket_type_id' => $aff_type, 'quantity' => 1 ) ), 'idempotency_key' => 'woo-aff-1' ), array( 'external' => true, 'source' => 'woocommerce', 'key_namespace' => 'woocommerce', 'currency' => 'EUR' ) );
Orders::confirm_external_payment( $woo['order_id'], 'woocommerce:1' );
check( 'WooCommerce-backed orders are not reported (Affiliate Captain records the Woo order)', $before === count( $GLOBALS['wpac_test_sales'] ) );

$pending = $aff_order( $aff_type, 1 );
Orders::cancel_order( $pending['order_id'], 'test' );
check( 'cancelling an unpaid order reverses nothing', 0 === count( $GLOBALS['wpac_test_refunds'] ) );

$aff_tickets = tickets( $o1['order_id'] );
$refund = PassCaptain\Refunds::request( $o1['order_id'], array( (int) $aff_tickets[0]->ticket_id ), 'Gast verhindert', 'aff-r1' );
$ref = end( $GLOBALS['wpac_test_refunds'] );
check( 'partial refund of one ticket reverses its net share (119.00 → 100.00)', is_array( $ref ) && 'refund-' . $refund['refund_id'] === $ref['refund_id'] && 10000 === $ref['items'][0]['net_minor'] && empty( $ref['full'] ) );
$refund2 = PassCaptain\Refunds::request( $o1['order_id'], array( (int) $aff_tickets[1]->ticket_id ), 'Gast verhindert', 'aff-r2' );
$types = array_column( $GLOBALS['wpac_test_refunds'], 'refund_id' );
check( 'refunding the last ticket reverses the rest (partial + full)', in_array( 'refund-' . $refund2['refund_id'], $types, true ) && in_array( 'refund-full-' . $o1['order_id'], $types, true ) );

Orders::cancel_order( $pos['order_id'], 'storniert' );
$ref = end( $GLOBALS['wpac_test_refunds'] );
check( 'cancelling a paid order reverses the commission', 'cancellation' === $ref['type'] && true === $ref['full'] && 'cancel-' . $pos['order_id'] === $ref['refund_id'] );

// A Voucher Captain gift card pays part of the order: commission only on what was paid (no double commission).
$GLOBALS['wpcap_test_filters'][ PassCaptain\Integrations\Vouchers\Bridge::FILTER ] = new Test_Voucher_Provider();
$vo = $aff_order( $aff_type, 1 );
$applied = PassCaptain\Integrations\Vouchers\Checkout::apply( $vo['order_id'], 'GIFT-15' );
Orders::confirm_manual_payment( $vo['order_id'] );
$fresh = Orders::get( $vo['order_id'] );
$sale = $aff_last_sale();
$vsnap    = json_decode( (string) $fresh->voucher_snapshot, true );
$by_means = ! empty( $vsnap['amount_paid_by_voucher'] );
// Multi-purpose voucher: VAT stays on the full price → only the paid share of it is removed;
// single-purpose voucher: price and VAT were reduced → paid gross minus the remaining VAT.
$expected = $by_means ? (int) $fresh->total_minor - (int) round( (int) $fresh->tax_minor * (int) $fresh->total_minor / ( (int) $fresh->total_minor + (int) $vsnap['amount_paid_by_voucher'] ) ) : (int) $fresh->total_minor - (int) $fresh->tax_minor;
check( 'a redeemed voucher reduces the commission base to the net of what was paid', ! is_wp_error( $applied ) && (string) $vo['order_id'] === $sale['order_id'] && $expected === $sale['items'][0]['net_minor'] && $sale['items'][0]['net_minor'] < 10000 );
echo '  (voucher ' . ( $by_means ? 'multi-purpose' : 'single-purpose' ) . ': paid ' . (int) $fresh->total_minor . ', base ' . (int) $sale['items'][0]['net_minor'] . ")\n";
unset( $GLOBALS['wpcap_test_filters'][ PassCaptain\Integrations\Vouchers\Bridge::FILTER ] );

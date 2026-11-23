<?php
/**
 * A643: the actor check of refunds runs before an idempotency hit can resume a refund, and the optional
 * four-eyes rule for approvers (`refund_four_eyes_approvers`, default off = previous behaviour).
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Refunds;
use PassCaptain\Settings;

global $wpdb;
$a643_settings = array( 'refund_approval_threshold' => Settings::get( 'refund_approval_threshold' ), 'refund_four_eyes_approvers' => Settings::get( 'refund_four_eyes_approvers' ), 'require_2fa_for_refunds' => Settings::get( 'require_2fa_for_refunds' ) );
Settings::update( array( 'refund_approval_threshold' => 0, 'require_2fa_for_refunds' => 0 ) );

function a643_paid_order( $qty = 2 ) {
	$GLOBALS['wpcap_test_transients'] = array();
	$o = Orders::create( array( 'email' => 'a643-' . uniqid() . '@example.com', 'items' => array( array( 'ticket_type_id' => make_type( 10, 10, 2500 ), 'quantity' => $qty ) ), 'idempotency_key' => 'a643-' . uniqid() ) );
	Orders::confirm_manual_payment( $o['order_id'] );
	return $o;
}

echo "A643: refund actor check before the idempotency hit\n";
$o = a643_paid_order();
$t = tickets( $o['order_id'] );
$first = Refunds::request( $o['order_id'], array( (int) $t[0]->ticket_id ), 'Storno', 'a643-key-1' );
$amount_first = Refunds::request_amount( $o['order_id'], 100, 'Kulanz', 'a643-amount-1' );
$GLOBALS['wpcap_test_denied_caps'] = array( Refunds::REFUND_CAP, Refunds::APPROVE_CAP );
$replay = Refunds::request( $o['order_id'], array( (int) $t[0]->ticket_id ), 'Storno', 'a643-key-1' );
$replay_amount = Refunds::request_amount( $o['order_id'], 100, 'Kulanz', 'a643-amount-1' );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'A643: a replayed refund key is refused for a user without refund rights', is_array( $first ) && is_wp_error( $replay ) && 'wpcap_refund_forbidden' === $replay->get_error_code() );
check( 'A643: the same for amount-only refunds', is_array( $amount_first ) && is_wp_error( $replay_amount ) && 'wpcap_refund_forbidden' === $replay_amount->get_error_code() );
check( 'A643: with the rights the replay still returns the same refund', (int) $first['refund_id'] === (int) ( Refunds::request( $o['order_id'], array( (int) $t[0]->ticket_id ), 'Storno', 'a643-key-1' )['refund_id'] ?? 0 ) );
Settings::update( array( 'require_2fa_for_refunds' => 1 ) );
$replay_2fa = Refunds::request( $o['order_id'], array( (int) $t[0]->ticket_id ), 'Storno', 'a643-key-1' );
Settings::update( array( 'require_2fa_for_refunds' => 0 ) );
check( 'A643: the 2FA requirement also applies to a replayed key', is_wp_error( $replay_2fa ) && 'wpcap_refund_2fa' === $replay_2fa->get_error_code() );

echo "A643: four-eyes rule for approvers (setting)\n";
Settings::update( array( 'refund_approval_threshold' => 1000 ) );
$o2 = a643_paid_order();
$own = Refunds::request( $o2['order_id'], array(), 'Absage', 'a643-own' );
check( 'A643: by default an approver executes an own refund above the threshold (unchanged)', 0 === (int) Settings::get( 'refund_four_eyes_approvers' ) && is_array( $own ) && 'pending_approval' !== $own['status'] && true === Refunds::may_self_approve() );
Settings::update( array( 'refund_four_eyes_approvers' => 1 ) );
$o3 = a643_paid_order();
$wait = Refunds::request( $o3['order_id'], array(), 'Absage', 'a643-four-eyes' );
$wait_amount = Refunds::request_amount( a643_paid_order()['order_id'], 2000, 'Kulanz', 'a643-four-eyes-amount' );
$self = Refunds::approve( (int) $wait['refund_id'] );
check( 'A643: with the setting an approver\'s own refund above the threshold waits for a second person', 'pending_approval' === $wait['status'] && 'pending_approval' === $wait_amount['status'] && false === Refunds::may_self_approve() );
check( 'A643: …and cannot be approved by the requester', is_wp_error( $self ) && 'wpcap_refund_approver' === $self->get_error_code() );
$GLOBALS['wpcap_test_user_id'] = 2;
$other = Refunds::approve( (int) $wait['refund_id'] );
$GLOBALS['wpcap_test_user_id'] = 1;
check( 'A643: another approver can approve it', ! is_wp_error( $other ) || 'wpcap_refund_approver' !== $other->get_error_code() );
check( 'A643: …and it is no longer pending', 'pending_approval' !== Refunds::get( (int) $wait['refund_id'] )['status'] && 2 === (int) Refunds::get( (int) $wait['refund_id'] )['approved_by'] );
$small = Refunds::request_amount( a643_paid_order()['order_id'], 500, 'Kulanz', 'a643-small' );
check( 'A643: below the threshold nothing changes', is_array( $small ) && 'pending_approval' !== $small['status'] );

Settings::update( $a643_settings );

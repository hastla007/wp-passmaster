<?php
/**
 * A705/A708 (TODO C5/C8): the connector contract of the core delivers the business events a Pro connector
 * (BoatingSchool, LearnBoating) needs – paid order, voucher redeemed → tickets, refund/revocation, admission –
 * through the job queue, only to enabled connectors, with stable idempotency keys, contract version and
 * without personal data; the suite status lists registered connectors with the contract version and no
 * licence state. Stub connector, no licence anywhere.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\SuiteStatus;
use PassCaptain\Integrations\ConnectorEvents;
use PassCaptain\Integrations\ConnectorInterface;
use PassCaptain\Integrations\Vouchers\TicketService;
use PassCaptain\Jobs;
use PassCaptain\Orders;
use PassCaptain\Refunds;

final class Z2_Stub_Connector implements ConnectorInterface {
	public array $received = array();
	public array $fail_next = array();
	public function __construct( private string $k, private string $v = '2.4.1' ) {}
	public function key() { return $this->k; }
	public function version() { return $this->v; }
	public function capabilities() { return array( 'course_access', 'tickets' ); }
	public function deliver( $event_name, array $payload, $idempotency_key ) {
		if ( 'error' === array_shift( $this->fail_next ) ) { return new WP_Error( 'z2_down', 'stub down' ); }
		$this->received[] = array( 'event' => $event_name, 'payload' => $payload, 'key' => $idempotency_key );
		return true;
	}
	public function of( $event ) { return array_values( array_filter( $this->received, static fn( $r ) => $event === $r['event'] ) ); }
}

echo "A705/A708: connector contract delivers paid order, voucher → ticket, refund/revocation and admission\n";
global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
wpcap_test_reset_transients();
ConnectorEvents::register();
$z2_stub = new Z2_Stub_Connector( 'z2_boatingschool' );
$z2_idle = new Z2_Stub_Connector( 'z2_learnboating', '1.0.0' ); // Registered, not enabled (no opt-in).
$z2_filter = static function ( $list ) use ( $z2_stub, $z2_idle ) { $list[] = $z2_stub; $list[] = $z2_idle; return $list; };
add_filter( 'wp_pass_captain_connectors_v1', $z2_filter );
$z2_enabled_before = get_option( 'wpcap_enabled_connectors', array() );
update_option( 'wpcap_enabled_connectors', array( 'z2_boatingschool' ) );
$z2_jobs = static function () use ( $wpdb, $p ) { return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_jobs WHERE job_type='connector_delivery'" ); };
$z2_run = static function () use ( $wpdb, $p ) {
	$wpdb->query( "UPDATE {$p}wpcap_jobs SET run_after='2000-01-01 00:00:00' WHERE job_type='connector_delivery' AND status='queued'" );
	Jobs::process_due( 200 );
};
$z2_markers = array( 'z2-buyer@example.com', 'z2-voucher@example.com', 'Zweite Mustermann', 'z2-secret-redemption-KEY', 'QRST' );

// Contract version.
check( 'A705: connector contract version 1.2.0 (also in Contracts::versions()) and the fourteen events are declared', '1.2.0' === ConnectorEvents::CONTRACT_VERSION && '1.2.0' === PassCaptain\Contracts::version( 'connector' ) && 1 === ConnectorEvents::VERSION && in_array( 'voucher_redeemed', ConnectorEvents::EVENTS, true ) && in_array( 'ticket_revoked', ConnectorEvents::EVENTS, true ) && 14 === count( ConnectorEvents::EVENTS ) );
check( 'A705: reason codes pass, free text becomes "other"', 'refund_12' === ConnectorEvents::reason_code( 'refund_12' ) && 'other' === ConnectorEvents::reason_code( 'Kunde Max Mustermann krank' ) && 'other' === ConnectorEvents::reason_code( 'partner_api: Hallo' ) && '' === ConnectorEvents::reason_code( '' ) && 'other' === ConnectorEvents::reason_code( str_repeat( 'a', 70 ) ) );

// 1. Paid order.
$z2_type = make_type( 50, 10, 3000 );
$z2_oid = admin_paid_order( $z2_type, 2, 'z2-buyer@example.com' );
$z2_run();
check( 'A705: paid order delivers order_paid and tickets_issued (no voucher_redeemed) to the enabled stub only', 1 === count( $z2_stub->of( 'order_paid' ) ) && 1 === count( $z2_stub->of( 'tickets_issued' ) ) && array() === $z2_stub->of( 'voucher_redeemed' ) && array() === $z2_idle->received );
$z2_paid = $z2_stub->of( 'order_paid' )[0];
check( 'A705: payload carries version 1, contract_version 1.2.0, event, time and the order identifiers', 1 === $z2_paid['payload']['version'] && '1.2.0' === $z2_paid['payload']['contract_version'] && 'order_paid' === $z2_paid['payload']['event'] && 1 === preg_match( '/^\d{4}-\d\d-\d\dT/', $z2_paid['payload']['occurred_at'] ) && $z2_oid === $z2_paid['payload']['data']['order_id'] && 32 === strlen( $z2_paid['payload']['data']['public_id'] ) );
check( 'A705: idempotency key = sha256(event key | connector key)', hash( 'sha256', 'order_paid|' . $z2_oid . '|z2_boatingschool' ) === $z2_paid['key'] );

// 2. Voucher redeemed → tickets (ticket service v1).
$z2_service = TicketService::service( null, 1 );
$z2_vtype = make_type( 20, 10, 4500 );
$z2_src = array( 'provider' => 'voucher-captain', 'voucher_id' => 7705, 'redemption_key' => 'z2-secret-redemption-KEY', 'code_hint' => 'QRST', 'amount_minor' => 9000 );
$z2_mails = count( $GLOBALS['wpcap_test_mails'] );
$z2_v = $z2_service->issue_tickets( $z2_src, 'z2-voucher-exchange', array( 'ticket_type_id' => $z2_vtype, 'quantity' => 2 ), array( 'email' => 'z2-voucher@example.com', 'attendees' => array( array( 'name' => 'Zweite Mustermann' ) ) ) );
$z2_code = null;
foreach ( array_slice( $GLOBALS['wpcap_test_mails'], $z2_mails ) as $m ) { if ( preg_match( '/wpcap_ticket=([a-f0-9]{64})/', (string) $m['body'], $mm ) ) { $z2_code = $mm[1]; } }
check( 'A705: fixture – the voucher exchange issued two tickets and mailed a ticket code', is_array( $z2_v ) && 2 === count( $z2_v['ticket_ids'] ?? array() ) && null !== $z2_code );
$z2_jobs_after_voucher = $z2_jobs();
$z2_again = $z2_service->issue_tickets( $z2_src, 'z2-voucher-exchange', array( 'ticket_type_id' => $z2_vtype, 'quantity' => 2 ), array( 'email' => 'z2-voucher@example.com' ) );
check( 'A705: repeating the exchange (same key) queues no second connector event', is_array( $z2_again ) && ! empty( $z2_again['reused'] ) && $z2_jobs_after_voucher === $z2_jobs() );
$z2_run();
$z2_vr = $z2_stub->of( 'voucher_redeemed' );
$z2_voucher_order = (int) ( $z2_vr[0]['payload']['data']['order_id'] ?? 0 );
check( 'A705: voucher redemption delivers voucher_redeemed once with order, voucher id, provider, purpose and tickets', 1 === count( $z2_vr ) && $z2_voucher_order > 0 && 7705 === $z2_vr[0]['payload']['data']['voucher_id'] && 'voucher-captain' === $z2_vr[0]['payload']['data']['voucher_provider'] && '' !== $z2_vr[0]['payload']['data']['voucher_purpose'] && 'voucher' === $z2_vr[0]['payload']['data']['payment_provider'] && 2 === count( $z2_vr[0]['payload']['data']['tickets'] ) );
check( 'A705: the voucher order also delivers order_paid and tickets_issued', 2 === count( $z2_stub->of( 'order_paid' ) ) && 2 === count( $z2_stub->of( 'tickets_issued' ) ) );

// 3. Admission.
$z2_checkin = PassCaptain\Checkin::perform( (string) $z2_code, array( 'idempotency_key' => 'z2-scan-1', 'source' => 'web' ) );
$z2_run();
$z2_ci = $z2_stub->of( 'ticket_checked_in' );
check( 'A705: admission delivers ticket_checked_in with ticket, order and occurrence ids', ! is_wp_error( $z2_checkin ) && 1 === count( $z2_ci ) && $z2_voucher_order === $z2_ci[0]['payload']['data']['order_id'] && $z2_ci[0]['payload']['data']['occurrence_id'] > 0 && 'web' === $z2_ci[0]['payload']['data']['source'] );

// 4. Refund and revocation.
PassCaptain\Settings::update( array( 'refund_approval_threshold' => 0 ) );
$z2_refund = Refunds::request( $z2_oid, array(), 'Test', 'z2-refund-' . uniqid() );
$z2_cancel = $z2_service->revoke_tickets( 'z2-voucher-exchange', 'Kunde Zweite Mustermann storniert' );
$z2_run();
$z2_revoked = $z2_stub->of( 'ticket_revoked' );
$z2_reasons = array_column( array_column( array_column( $z2_revoked, 'payload' ), 'data' ), 'reason' );
check( 'A705: refund delivers refund_completed and order_refunded', ! is_wp_error( $z2_refund ) && 1 === count( $z2_stub->of( 'refund_completed' ) ) && 1 === count( $z2_stub->of( 'order_refunded' ) ) && $z2_oid === $z2_stub->of( 'refund_completed' )[0]['payload']['data']['order_id'] );
check( 'A705: revocation delivers ticket_revoked per ticket (2 refunded + 1 voucher ticket not yet checked in)', true === $z2_cancel && 3 === count( $z2_revoked ) && 'revoked' === $z2_revoked[0]['payload']['data']['status'] );
check( 'A705: ticket_revoked carries the reason as code (refund_{id}, order_cancelled)', 2 === count( array_filter( $z2_reasons, static fn( $r ) => 1 === preg_match( '/^refund_\d+$/', $r ) ) ) && in_array( 'order_cancelled', $z2_reasons, true ) );
$z2_oc = $z2_stub->of( 'order_cancelled' );
check( 'A705: the voucher system\'s free-text reason reaches connectors only as "other"', 1 === count( $z2_oc ) && 'other' === $z2_oc[0]['payload']['data']['reason'] && $z2_voucher_order === $z2_oc[0]['payload']['data']['order_id'] );

// No personal data, codes or voucher secrets in any payload.
$z2_all = wp_json_encode( $z2_stub->received );
$z2_leaks = array_values( array_filter( array_merge( $z2_markers, array( (string) $z2_code ) ), static fn( $m ) => '' !== $m && false !== stripos( $z2_all, $m ) ) );
check( 'A705: no e-mail, name, ticket code, code hint or redemption key in any connector payload' . ( $z2_leaks ? ' – found ' . implode( ', ', $z2_leaks ) : '' ), array() === $z2_leaks && count( $z2_stub->received ) >= 12 );
check( 'A705: every delivered payload names contract 1.2.0', array( '1.2.0' ) === array_values( array_unique( array_map( static fn( $r ) => $r['payload']['contract_version'], $z2_stub->received ) ) ) );
check( 'A705: the connector that is registered but not enabled received nothing', array() === $z2_idle->received );

// Retry keeps the idempotency key.
$z2_stub->received = array();
$z2_stub->fail_next = array( 'error' );
$z2_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=" . (int) $z2_type );
do_action( 'wpcap_occurrence_rescheduled', $z2_occ, '2031-01-01 18:00:00', '2031-01-02 18:00:00' );
$z2_run();
$z2_first = count( $z2_stub->received );
$z2_run();
check( 'A705: a failed delivery is retried by the job queue with the same idempotency key', 0 === $z2_first && 1 === count( $z2_stub->received ) && hash( 'sha256', 'occurrence_rescheduled|' . $z2_occ . '|2031-01-02 18:00:00|z2_boatingschool' ) === $z2_stub->received[0]['key'] );

// Suite status: registered connectors with contract version, no licence state.
$z2_reg = ConnectorEvents::registered();
$z2_by_key = array_column( $z2_reg, null, 'key' );
check( 'A705: registered() lists both stub connectors with own version, opt-in state and contract version', isset( $z2_by_key['z2_boatingschool'], $z2_by_key['z2_learnboating'] ) && '2.4.1' === $z2_by_key['z2_boatingschool']['version'] && true === $z2_by_key['z2_boatingschool']['enabled'] && false === $z2_by_key['z2_learnboating']['enabled'] && '1.2.0' === $z2_by_key['z2_boatingschool']['contract_version'] && array( 'course_access', 'tickets' ) === $z2_by_key['z2_boatingschool']['capabilities'] );
ob_start();
SuiteStatus::render_connectors();
$z2_html = (string) ob_get_clean();
check( 'A705: suite status shows the connectors, their versions, opt-in and contract 1.2.0', false !== strpos( $z2_html, 'data-connector="z2_boatingschool" data-enabled="1"' ) && false !== strpos( $z2_html, 'data-connector="z2_learnboating" data-enabled="0"' ) && false !== strpos( $z2_html, '2.4.1' ) && false !== strpos( $z2_html, 'Connector-Vertrag 1.2.0' ) );
check( 'A705: suite status shows no licence state', 0 === preg_match( '/lizenz|licen[cs]e/i', $z2_html ) );
$z2_src_files = (string) file_get_contents( WPCAP_PATH . 'src/Integrations/ConnectorEvents.php' ) . (string) file_get_contents( WPCAP_PATH . 'src/Integrations/ConnectorRegistry.php' ) . (string) file_get_contents( WPCAP_PATH . 'src/Admin/SuiteStatus.php' );
check( 'A708: connector delivery and suite status contain no licence or entitlement check', 0 === preg_match( '/LicenseClient|allows_entitlement|wplc\/v1|license_status|licence_status/i', $z2_src_files ) );

remove_filter( 'wp_pass_captain_connectors_v1', $z2_filter );
update_option( 'wpcap_enabled_connectors', $z2_enabled_before );
$wpdb->query( "UPDATE {$p}wpcap_jobs SET status='complete' WHERE job_type='connector_delivery' AND status='queued'" );

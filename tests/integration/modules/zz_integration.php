<?php
/** Cross-module integration checks run after all module tests. */
// phpcs:ignoreFile
use PassCaptain\Orders;
use PassCaptain\Webhooks\Dispatcher;

echo "Integration: full refunds emit order.refunded once\n";
\PassCaptain\Modules\Webhooks::register();
$wpdb->query( "DELETE FROM {$p}wpcap_webhook_subscriptions" );
$zz_sub = Dispatcher::create_subscription( 'https://zz.hooks.example/in', array( 'order.refunded' ) );
$zz_count = static fn() => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_webhook_deliveries WHERE subscription_id=%d", $zz_sub['subscription_id'] ) );
$zt = make_type( 10, 10, 1000 );
$zo = Orders::create( array( 'email' => 'zz@example.com', 'items' => array( array( 'ticket_type_id' => $zt, 'quantity' => 1 ) ), 'idempotency_key' => 'zz-1' ) );
Orders::confirm_manual_payment( $zo['order_id'] );
Orders::mark_refunded( $zo['order_id'], 'woocommerce_refunded' );
Orders::mark_refunded( $zo['order_id'], 'woocommerce_refunded' );
check( 'external full refund emits exactly one order.refunded', 1 === $zz_count() );
$zo2 = Orders::create( array( 'email' => 'zz2@example.com', 'items' => array( array( 'ticket_type_id' => $zt, 'quantity' => 1 ) ), 'idempotency_key' => 'zz-2' ) );
Orders::confirm_manual_payment( $zo2['order_id'] );
PassCaptain\Settings::update( array( 'refund_approval_threshold' => 0 ) );
PassCaptain\Refunds::request( $zo2['order_id'], array(), 'Test', 'zz-r2' );
check( 'native full refund is reported once (per refund), not twice', 2 === $zz_count() );
$wpdb->query( "DELETE FROM {$p}wpcap_webhook_subscriptions" );

echo "Integration: payment webhook robustness\n";
$wt = make_type( 10, 10, 2000 );
$mk = static function ( $key ) use ( $wt ) { return Orders::create( array( 'email' => 'wh@example.com', 'items' => array( array( 'ticket_type_id' => $wt, 'quantity' => 1 ) ), 'idempotency_key' => 'zzwh-' . $key ), array( 'payment_provider' => 'stripe' ) ); };
$w1 = $mk( 'wh-1' );
PassCaptain\Orders::set_provider_reference( $w1['order_id'], 'cs_new' );
$stale = PassCaptain\Payments\Webhooks::process( 'stripe', array( 'event_id' => 'evt_old_fail', 'type' => 'failed', 'order_public_id' => $w1['public_id'], 'provider_reference' => 'cs_old', 'related_references' => array( 'cs_old' ) ) );
check( 'failure of an older payment attempt does not cancel the order', 'stale_attempt' === $stale && 'pending' === Orders::get( $w1['order_id'] )->payment_status );
$paid = PassCaptain\Payments\Webhooks::process( 'stripe', array( 'event_id' => 'evt_new_paid', 'type' => 'paid', 'order_public_id' => $w1['public_id'], 'provider_reference' => 'pi_new', 'related_references' => array( 'cs_new', 'pi_new' ), 'amount_minor' => 2000, 'currency' => 'EUR' ) );
$before = count( $GLOBALS['wpcap_test_mails'] );
$double = PassCaptain\Payments\Webhooks::process( 'stripe', array( 'event_id' => 'evt_other_paid', 'type' => 'paid', 'order_public_id' => $w1['public_id'], 'provider_reference' => 'pi_other', 'related_references' => array( 'cs_old', 'pi_other' ), 'amount_minor' => 2000, 'currency' => 'EUR' ) );
check( 'second successful payment is flagged as double payment and the admin alerted', 'paid' === $paid && 'double_payment' === $double && 'pi_new' === Orders::get( $w1['order_id'] )->provider_reference && 1 === count( array_slice( $GLOBALS['wpcap_test_mails'], $before ) ) );
$w2 = $mk( 'wh-2' );
Orders::cancel_order( $w2['order_id'], 'test' );
$closed = PassCaptain\Payments\Webhooks::process( 'stripe', array( 'event_id' => 'evt_closed', 'type' => 'paid', 'order_public_id' => $w2['public_id'], 'provider_reference' => 'pi_c', 'amount_minor' => 2000, 'currency' => 'EUR' ) );
check( 'payment for a cancelled order is recorded and alerted, not silently kept', 'paid_for_closed_order' === $closed );
$wpdb->insert( "{$p}wpcap_payment_events", array( 'provider' => 'stripe', 'provider_event_id' => 'evt_stuck', 'event_type' => 'paid', 'status' => 'processing', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ), 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ) );
$w3 = $mk( 'wh-3' );
$stuck = PassCaptain\Payments\Webhooks::process( 'stripe', array( 'event_id' => 'evt_stuck', 'type' => 'paid', 'order_public_id' => $w3['public_id'], 'provider_reference' => 'pi_3', 'amount_minor' => 2000, 'currency' => 'EUR' ) );
check( 'a claim stuck in processing is taken over after 10 minutes', 'paid' === $stuck && 'paid' === Orders::get( $w3['order_id'] )->payment_status );

echo "Integration: transfer clears previous attendee data\n";
$tt = make_type( 10, 10, 0 );
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $tt, 'field_key' => 'firma', 'label' => 'Firma', 'field_type' => 'text', 'required' => 0, 'status' => 'active' ) );
$to = Orders::create( array( 'email' => 'zz-tr@example.com', 'items' => array( array( 'ticket_type_id' => $tt, 'quantity' => 1 ) ), 'idempotency_key' => 'zz-tr-1', 'attendees' => array( array( 'ticket_type_id' => $tt, 'name' => 'Alice Original', 'email' => 'alice@example.com', 'answers' => array( 'firma' => 'Alice GmbH' ) ) ) ) );
$tm = array_values( array_filter( $GLOBALS['wpcap_test_mails'], static fn( $m ) => 'zz-tr@example.com' === $m['to'] && false !== strpos( $m['body'], 'wpcap_access=' ) ) );
preg_match( '/wpcap_access=([a-f0-9]{64})/', end( $tm )['body'], $tam );
$tticket = tickets( $to['order_id'] )[0];
Orders::transfer_ticket( $to['public_id'], $tam[1], $tticket->public_id, 'bob@example.com' );
$assign = $wpdb->get_row( "SELECT attendee_name,attendee_email FROM {$p}wpcap_ticket_assignments WHERE ticket_id=" . (int) $tticket->ticket_id );
$answers = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_participant_answers WHERE ticket_id=" . (int) $tticket->ticket_id );
check( 'transfer removes the previous attendee name and answers', $assign && '' === $assign->attendee_name && 'bob@example.com' === $assign->attendee_email && 0 === $answers );

echo "E2E fixes: local times, code rotation, labels, refund mail\n";
check( 'event start is shown in the event timezone, not UTC', '12.12.2026 19:30 Europe/Vienna' === Orders::format_start( '2026-12-12 18:30:00', 'Europe/Vienna' ) && '12.12.2026 18:30 UTC' === Orders::format_start( '2026-12-12 18:30:00', 'Bad/Zone' ) );
$et = make_type( 10, 10, 0 );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_occurrences o JOIN {$p}wpcap_ticket_types tt ON tt.occurrence_id=o.occurrence_id SET o.starts_at=%s WHERE tt.ticket_type_id=%d", '2027-01-15 18:30:00', $et ) );
$mails_before = count( $GLOBALS['wpcap_test_mails'] );
$eo = Orders::create( array( 'email' => 'e2e@example.com', 'items' => array( array( 'ticket_type_id' => $et, 'quantity' => 1 ) ), 'idempotency_key' => 'zz-e2e-1' ) );
$e2e_mails = array_slice( $GLOBALS['wpcap_test_mails'], $mails_before );
$e2e_ticket_mail = array_values( array_filter( $e2e_mails, static fn( $m ) => str_starts_with( $m['subject'], 'Deine Tickets' ) ) )[0] ?? array( 'body' => '', 'subject' => '' );
check( 'ticket mail shows local start time and site name instead of plugin name', false !== strpos( $e2e_ticket_mail['body'], '15.01.2027 19:30 Europe/Vienna' ) && false === strpos( $e2e_ticket_mail['subject'] . $e2e_ticket_mail['body'], 'WP Pass Captain' ) );
$access = array_values( array_filter( $e2e_mails, static fn( $m ) => false !== strpos( $m['body'], 'wpcap_access=' ) ) );
preg_match( '/wpcap_access=([a-f0-9]{64})/', $access ? end( $access )['body'] : '', $eam );
$eticket = tickets( $eo['order_id'] )[0];
$hash_before = $wpdb->get_var( $wpdb->prepare( "SELECT token_hash FROM {$p}wpcap_tickets WHERE ticket_id=%d", $eticket->ticket_id ) );
$same = PassCaptain\TicketAssignments::assign( $eo['public_id'], $eam[1] ?? '', $eticket->public_id, 'Eva Buyer', 'e2e@example.com' );
$hash_same = $wpdb->get_var( $wpdb->prepare( "SELECT token_hash FROM {$p}wpcap_tickets WHERE ticket_id=%d", $eticket->ticket_id ) );
check( 'name correction for the same holder keeps the mailed code', ! is_wp_error( $same ) && false === $same['code_rotated'] && $hash_before === $hash_same );
$mails_before = count( $GLOBALS['wpcap_test_mails'] );
$other = PassCaptain\TicketAssignments::assign( $eo['public_id'], $eam[1] ?? '', $eticket->public_id, 'Fritz', 'fritz@example.com' );
$hash_new = $wpdb->get_var( $wpdb->prepare( "SELECT token_hash FROM {$p}wpcap_tickets WHERE ticket_id=%d", $eticket->ticket_id ) );
$to = array_column( array_slice( $GLOBALS['wpcap_test_mails'], $mails_before ), 'to' );
check( 'new holder gets a new code; buyer is told the old code is invalid', ! is_wp_error( $other ) && true === $other['code_rotated'] && $hash_new !== $hash_same && in_array( 'fritz@example.com', $to, true ) && in_array( 'e2e@example.com', $to, true ) );
check( 'status values have customer-facing labels', 'Abgeschlossen' === PassCaptain\Admin\Forms::status_label( 'complete' ) && 'Gültig' === PassCaptain\Admin\Forms::status_label( 'valid' ) );
$defs = array();
foreach ( PassCaptain\Admin\SettingsPage::field_definitions() as $section ) { $defs += $section['fields']; }
check( 'settings page has no raw-key leftovers and no duplicated module settings', ! isset( $defs['wallet_apple_enabled'] ) && 'bool' === ( $defs['scanner_show_attendee']['type'] ?? '' ) && 'bool' === ( $defs['auto_refund_cancelled']['type'] ?? '' ) );
$rt = make_type( 10, 10, 1500 );
$ro = Orders::create( array( 'email' => 'refundmail@example.com', 'items' => array( array( 'ticket_type_id' => $rt, 'quantity' => 1 ) ), 'idempotency_key' => 'zz-e2e-r' ) );
Orders::confirm_manual_payment( $ro['order_id'] );
PassCaptain\Settings::update( array( 'refund_approval_threshold' => 0 ) );
$mails_before = count( $GLOBALS['wpcap_test_mails'] );
PassCaptain\Refunds::request( $ro['order_id'], array(), 'Test', 'zz-e2e-refund' );
$refund_mails = array_values( array_filter( array_slice( $GLOBALS['wpcap_test_mails'], $mails_before ), static fn( $m ) => 'refundmail@example.com' === $m['to'] && str_starts_with( $m['subject'], 'Erstattung bestätigt' ) ) );
check( 'buyer receives a refund confirmation', 1 === count( $refund_mails ) && false !== strpos( $refund_mails[0]['body'], '15,00' ) || false !== strpos( $refund_mails[0]['body'] ?? '', '15.00' ) );

echo "Integration: shared scanner (check-in service)\n";
// Shared scanner: another plugin's scanner hands Pass Captain tickets to the check-in service.
$checkin_service = PassCaptain\Integrations\Vouchers\CheckinService::service( null, 1 );
$scan_order = Orders::create( array( 'email' => 'scan@example.com', 'items' => array( array( 'ticket_type_id' => make_type( 5, 10, 0 ), 'quantity' => 1 ) ), 'idempotency_key' => 'scan-1' ) );
$scan_token = is_wp_error( $scan_order ) ? '' : (string) ( ticket_code_for( $scan_order['order_id'] )[0] ?? '' );
check( 'the check-in service recognises ticket codes and URLs, not voucher codes', $checkin_service->recognizes( str_repeat( 'a', 64 ) ) && $checkin_service->recognizes( 'https://example.com/?wpcap_ticket=' . str_repeat( 'b', 64 ) ) && ! $checkin_service->recognizes( 'GIFT-ABCD-1234' ) && ! $checkin_service->recognizes( str_repeat( 'c', 48 ) ) );
check( 'a ticket code for the shared scanner test exists', '' !== $scan_token );
if ( '' !== $scan_token ) {
	$pv = $checkin_service->preview( $scan_token );
	$ci = $checkin_service->check_in( $scan_token, 'shared-scan-1' );
	$ci_again = $checkin_service->check_in( $scan_token, 'shared-scan-1' );
	$ci_other = $checkin_service->check_in( $scan_token, 'shared-scan-2' );
	check( 'the shared scanner previews and checks a ticket in exactly once', is_array( $pv ) && 'valid' === $pv['status'] && is_array( $ci ) && 'checked_in' === $ci['status'] && is_array( $ci_again ) && ! empty( $ci_again['replayed'] ) && ( is_wp_error( $ci_other ) || 'already_checked_in' === ( $ci_other['status'] ?? '' ) ) );
}
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_checkin' );
check( 'the check-in service refuses users without the check-in capability', is_wp_error( $checkin_service->check_in( str_repeat( 'a', 64 ) ) ) && 'wpcap_checkin_forbidden' === $checkin_service->preview( str_repeat( 'a', 64 ) )->get_error_code() );
$GLOBALS['wpcap_test_denied_caps'] = array();
$status_rows = PassCaptain\Integrations\Vouchers\IntegrationStatus::rows();
check( 'without Voucher Captain the status says so and nothing else is offered', 1 === count( $status_rows ) && false === array_values( $status_rows )[0][0] );
echo "Integration: transaction state per database server\n";
$tracks = new ReflectionMethod( PassCaptain\Orders::class, 'server_tracks_transactions' );
$tracks->setAccessible( true );
check( 'MariaDB is asked for @@in_transaction, MySQL is not (no error-log line per order)', true === $tracks->invoke( null, '11.4.2-MariaDB-ubu2404' ) && false === $tracks->invoke( null, '8.4.13' ) && false === $tracks->invoke( null, '' ) );
echo "Integration: server-side rollback inside the order transaction\n";
// Simulates a deadlock victim: the server rolls the transaction back (locks released) while PHP
// continues. Without the guards the remaining statements would autocommit an unchecked order.
$rb_type = make_type( 1, 10, 1000 );
$rb_hook = static function ( $result ) { global $wpdb; $wpdb->query( 'ROLLBACK' ); return $result; };
add_filter( 'wpcap_reserve_capacity', $rb_hook, 10, 3 );
$rb = Orders::create( array( 'email' => 'rb@example.com', 'items' => array( array( 'ticket_type_id' => $rb_type, 'quantity' => 1 ) ), 'idempotency_key' => 'zz-rb-1' ) );
remove_filter( 'wpcap_reserve_capacity', $rb_hook, 10 );
$rb_orders = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_order_items WHERE ticket_type_id=%d", $rb_type ) );
check( 'lost transaction aborts the order with a retryable error and writes nothing', is_wp_error( $rb ) && 503 === ( $rb->get_error_data()['status'] ?? 0 ) && 0 === $rb_orders );
$rb_ok = Orders::create( array( 'email' => 'rb@example.com', 'items' => array( array( 'ticket_type_id' => $rb_type, 'quantity' => 1 ) ), 'idempotency_key' => 'zz-rb-2' ) );
check( 'the retry succeeds and the capacity is still available', ! is_wp_error( $rb_ok ) && 0 === Orders::availability( $rb_type ) );

echo "Integration: voucher ledger edge cases (expert review)\n";
final class ZZ_Voucher_Provider implements PassCaptain\Integrations\Vouchers\ProviderInterface {
	public array $calls = array();
	public array $commit_errors = array();
	public array $refund_errors = array();
	public $treatment = null;
	public $max = 1500;
	public function quote( array $context ) { $this->calls[] = array( 'quote', $context['order_reference'] ); return array_filter( array( 'amount_minor' => min( $this->max, $context['amount_minor'] ), 'currency' => $context['currency'], 'treatment' => $this->treatment ) ); }
	public function reserve( array $quote, $key ) { $this->calls[] = array( 'reserve', $key ); return array( 'reservation' => $key, 'applied_minor' => $quote['amount_minor'] ); }
	public function commit( array $reservation, $key ) { $this->calls[] = array( 'commit', $key ); return $this->commit_errors ? array_shift( $this->commit_errors ) : array( 'redemption' => $key ); }
	public function release( array $reservation, $key ) { $this->calls[] = array( 'release', $key ); return true; }
	public function refund( array $redemption, $amount, $key ) { if ( $this->refund_errors ) { return array_shift( $this->refund_errors ); } $this->calls[] = array( 'refund', $amount, $key ); return true; }
	public function for_order( $public_id, $kind ) { return array_values( array_filter( $this->calls, static fn( $c ) => $kind === $c[0] && str_contains( (string) end( $c ), $public_id ) ) ); }
}
$zv = new ZZ_Voucher_Provider();
$GLOBALS['wpcap_test_filters'][ PassCaptain\Integrations\Vouchers\Bridge::FILTER ] = $zv;
$VC = PassCaptain\Integrations\Vouchers\Checkout::class;
$temporary = static fn() => new WP_Error( 'vcap_temporary_failure', 'Später', array( 'status' => 503 ) );
PassCaptain\Settings::update( array( 'refund_approval_threshold' => 0 ) );

// Refund share by value: ticket A 80.00, ticket B 20.00, voucher 15.00 → refunding B returns 3.00 of the voucher (not half).
$za = make_type( 10, 10, 8000 );
$zb = make_type( 10, 10, 2000 );
$zvo = Orders::create( array( 'email' => 'zzv@example.com', 'items' => array( array( 'ticket_type_id' => $za, 'quantity' => 1 ), array( 'ticket_type_id' => $zb, 'quantity' => 1 ) ), 'idempotency_key' => 'zzv-1' ) );
$VC::apply( $zvo['order_id'], 'ZZ-VALUE' );
Orders::confirm_manual_payment( $zvo['order_id'] );
$VC::commit( $zvo['order_id'] );
$zb_ticket = array_values( array_filter( tickets( $zvo['order_id'] ), static fn( $t ) => (int) $t->ticket_type_id === $zb ) )[0];
$zr = PassCaptain\Refunds::request( $zvo['order_id'], array( (int) $zb_ticket->ticket_id ), 'Storno', 'zzv-r1' );
$VC::refund( $zr['refund_id'], $zvo['order_id'] );
$zref = $zv->for_order( 'wpcap-refund-' . $zr['refund_id'], 'refund' );
check( 'the voucher share of a ticket refund follows the ticket value', 1 === count( $zref ) && 300 === (int) $zref[0][1] );

// The voucher system is briefly unavailable during a refund: the share is retried by a job with the same key.
$zfail = make_type( 10, 10, 1000 );
$zfo = Orders::create( array( 'email' => 'zzf@example.com', 'items' => array( array( 'ticket_type_id' => $zfail, 'quantity' => 2 ) ), 'idempotency_key' => 'zzv-fail' ) );
$VC::apply( $zfo['order_id'], 'ZZ-REFUND-RETRY' );
Orders::confirm_manual_payment( $zfo['order_id'] );
$VC::commit( $zfo['order_id'] );
$zv->refund_errors = array( $temporary() );
$zfr = PassCaptain\Refunds::request( $zfo['order_id'], array( (int) tickets( $zfo['order_id'] )[0]->ticket_id ), 'Storno', 'zzv-rf' );
$zf_first = $VC::refund( $zfr['refund_id'], $zfo['order_id'] );
$zf_job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_jobs WHERE idempotency_key=%s", hash( 'sha256', 'voucher-refund-' . $zfr['refund_id'] ) ) );
check( 'a temporarily failed voucher refund is kept and queued for a retry', false === $zf_first && $zf_job && 'voucher_refund' === $zf_job->job_type && in_array( (int) $zfr['refund_id'], $VC::voucher_state( $zfo['order_id'] )['failed_refund_ids'], true ) );
$zf_retry = $VC::handle_job( false, 'voucher_refund', json_decode( $zf_job->payload, true ), (int) $zf_job->job_id );
$zf_calls = $zv->for_order( 'wpcap-refund-' . $zfr['refund_id'], 'refund' );
check( 'the retry refunds the voucher share exactly once and clears the marker', true === $zf_retry && 1 === count( $zf_calls ) && 750 === (int) $zf_calls[0][1] && ! $VC::voucher_state( $zfo['order_id'] )['failed_refund_ids'] && true === $VC::refund( $zfr['refund_id'], $zfo['order_id'] ) && 1 === count( $zv->for_order( 'wpcap-refund-' . $zfr['refund_id'], 'refund' ) ) );

// Refund while the commit is still pending (retry job): the share follows right after the commit.
$zt2 = make_type( 10, 10, 1000 );
$zp = Orders::create( array( 'email' => 'zzp@example.com', 'items' => array( array( 'ticket_type_id' => $zt2, 'quantity' => 2 ) ), 'idempotency_key' => 'zzv-2' ) );
$VC::apply( $zp['order_id'], 'ZZ-PENDING' );
$zv->commit_errors = array( $temporary(), $temporary() );
Orders::confirm_manual_payment( $zp['order_id'] );
$VC::commit( $zp['order_id'] );
$zv->commit_errors = array();
$zpr = PassCaptain\Refunds::request( $zp['order_id'], array( (int) tickets( $zp['order_id'] )[0]->ticket_id ), 'Storno', 'zzv-r2' );
$VC::refund( $zpr['refund_id'], $zp['order_id'] );
$queued = $VC::voucher_state( $zp['order_id'] );
check( 'a refund during a pending commit is queued, not dropped', 'reserved' === $queued['status'] && array( (int) $zpr['refund_id'] ) === $queued['pending_refund_ids'] && ! $zv->for_order( 'wpcap-refund-' . $zpr['refund_id'], 'refund' ) );
$VC::commit( $zp['order_id'] );
$zpref = $zv->for_order( 'wpcap-refund-' . $zpr['refund_id'], 'refund' );
check( 'the queued voucher share is refunded once the commit succeeds', 'committed' === $VC::voucher_state( $zp['order_id'] )['status'] && 1 === count( $zpref ) && 750 === (int) $zpref[0][1] );

// Fully refunded before the retried commit: the voucher is released, never charged.
$zf = Orders::create( array( 'email' => 'zzf@example.com', 'items' => array( array( 'ticket_type_id' => $zt2, 'quantity' => 1 ) ), 'idempotency_key' => 'zzv-3' ) );
$VC::apply( $zf['order_id'], 'ZZ-REFUNDED' );
$zv->commit_errors = array( $temporary(), $temporary() );
Orders::confirm_manual_payment( $zf['order_id'] );
$VC::commit( $zf['order_id'] );
$zv->commit_errors = array();
Orders::mark_refunded( $zf['order_id'], 'test' );
$zf_commits = count( $zv->for_order( $zf['public_id'], 'commit' ) );
$VC::commit( $zf['order_id'] );
check( 'a commit retry after a full refund releases the voucher instead of charging it', 'released' === $VC::voucher_state( $zf['order_id'] )['status'] && $zf_commits === count( $zv->for_order( $zf['public_id'], 'commit' ) ) && 1 === count( $zv->for_order( $zf['public_id'], 'release' ) ) );

// Reservation released on the voucher side (TTL) while the order waited for a bank transfer.
$zm = Orders::create( array( 'email' => 'zzm@example.com', 'items' => array( array( 'ticket_type_id' => $zt2, 'quantity' => 1 ) ), 'idempotency_key' => 'zzv-4' ) );
$VC::apply( $zm['order_id'], 'ZZ-MISSING' );
$zv->commit_errors = array( new WP_Error( 'vcap_reservation_missing', 'abgelaufen', array( 'status' => 409 ) ) );
Orders::confirm_manual_payment( $zm['order_id'] );
$VC::commit( $zm['order_id'] );
$zms = $VC::voucher_state( $zm['order_id'] );
check( 'a reservation released by the voucher plugin is reserved again and committed (no false alarm)', 'committed' === $zms['status'] && ! empty( $zms['late_reservation'] ) && 2 === count( $zv->for_order( $zm['public_id'], 'reserve' ) ) );

// Event cancellation signals cancelled open orders (their voucher reservations are released).
$zc_type = make_type( 10, 10, 2000 );
$zc = Orders::create( array( 'email' => 'zzc@example.com', 'items' => array( array( 'ticket_type_id' => $zc_type, 'quantity' => 1 ) ), 'idempotency_key' => 'zzv-5' ) );
$VC::apply( $zc['order_id'], 'ZZ-EVENT' );
$zc_event = (int) $wpdb->get_var( $wpdb->prepare( "SELECT o.event_id FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=%d", $zc_type ) );
$zc_signals = array();
add_action( 'wpcap_order_cancelled', static function ( $id ) use ( &$zc_signals ) { $zc_signals[] = (int) $id; } );
Orders::cancel_event( $zc_event );
$VC::release( $zc['order_id'] );
check( 'cancelling an event signals its open orders as cancelled and releases their vouchers', in_array( (int) $zc['order_id'], $zc_signals, true ) && 'released' === $VC::voucher_state( $zc['order_id'] )['status'] );

// Expired holds: each order is expired (and signalled) exactly once.
$ze = Orders::create( array( 'email' => 'zze@example.com', 'items' => array( array( 'ticket_type_id' => $zt2, 'quantity' => 1 ) ), 'idempotency_key' => 'zzv-6' ) );
expire_holds_of( $ze['order_id'] );
$zc_signals = array();
Orders::expire_holds();
Orders::expire_holds();
check( 'expired holds signal each order exactly once', 1 === count( array_keys( $zc_signals, (int) $ze['order_id'], true ) ) && 'expired' === Orders::get( $ze['order_id'] )->payment_status );

// Checked writes in apply(): an order that changed meanwhile is not discounted and the reservation is released.
$zw = Orders::create( array( 'email' => 'zzw@example.com', 'items' => array( array( 'ticket_type_id' => $zt2, 'quantity' => 1 ) ), 'idempotency_key' => 'zzv-7' ) );
$zw_hook = static function ( $result ) use ( $zw ) { global $wpdb; $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_orders SET payment_status='expired' WHERE order_id=%d", $zw['order_id'] ) ); return $result; };
$zv2 = new class( $zv, $zw_hook ) implements PassCaptain\Integrations\Vouchers\ProviderInterface {
	public function __construct( private $inner, private $hook ) {}
	public function quote( array $c ) { return $this->inner->quote( $c ); }
	public function reserve( array $q, $k ) { return ( $this->hook )( $this->inner->reserve( $q, $k ) ); }
	public function commit( array $r, $k ) { return $this->inner->commit( $r, $k ); }
	public function release( array $r, $k ) { return $this->inner->release( $r, $k ); }
	public function refund( array $r, $a, $k ) { return $this->inner->refund( $r, $a, $k ); }
};
$GLOBALS['wpcap_test_filters'][ PassCaptain\Integrations\Vouchers\Bridge::FILTER ] = $zv2;
$zw_res = $VC::apply( $zw['order_id'], 'ZZ-RACE' );
$GLOBALS['wpcap_test_filters'][ PassCaptain\Integrations\Vouchers\Bridge::FILTER ] = $zv;
check( 'apply() does not discount an order that is no longer pending and releases the reservation', is_wp_error( $zw_res ) && ! Orders::get( $zw['order_id'] )->voucher_snapshot && 1000 === (int) Orders::get( $zw['order_id'] )->total_minor && 1 === count( $zv->for_order( $zw['public_id'], 'release' ) ) );
unset( $GLOBALS['wpcap_test_filters'][ PassCaptain\Integrations\Vouchers\Bridge::FILTER ] );

echo "Integration: privacy covers participant answers\n";
$zq_type = make_type( 10, 10, 1000 );
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $zq_type, 'field_key' => 'diet', 'label' => 'Ernährung' ) );
$zq_field = (int) $wpdb->insert_id;
$zq = Orders::create( array( 'email' => 'zzq@example.com', 'items' => array( array( 'ticket_type_id' => $zq_type, 'quantity' => 1 ) ), 'idempotency_key' => 'zzq-1' ) );
Orders::confirm_manual_payment( $zq['order_id'] );
$zq_ticket = (int) tickets( $zq['order_id'] )[0]->ticket_id;
$wpdb->insert( "{$p}wpcap_participant_answers", array( 'ticket_id' => $zq_ticket, 'field_id' => $zq_field, 'answer' => 'glutenfrei', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$zq_export = json_encode( PassCaptain\Privacy::export( 'zzq@example.com' ), JSON_UNESCAPED_UNICODE );
check( 'the personal data export contains participant answers with their labels', str_contains( $zq_export, 'Ernährung' ) && str_contains( $zq_export, 'glutenfrei' ) );
$zq_mails = count( $GLOBALS['wpcap_test_mails'] );
$zq_erase = PassCaptain\Privacy::erase( 'zzq@example.com' );
$zq_kept = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_participant_answers WHERE ticket_id=%d", $zq_ticket ) );
check( 'erasure before the event is deferred: the ticket stays valid, the admin is told', 'valid' === tickets( $zq['order_id'] )[0]->status && 1 === $zq_kept && true === $zq_erase['items_retained'] && 2 === count( $zq_erase['messages'] ) && isset( PassCaptain\Privacy::deferred()[ (int) $zq['order_id'] ] ) && count( $GLOBALS['wpcap_test_mails'] ) === $zq_mails + 1 );
PassCaptain\Privacy::process_deferred();
check( 'nothing is anonymised while the date is still upcoming', 'zzq@example.com' === Orders::get( $zq['order_id'] )->customer_email );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_occurrences SET starts_at=%s,ends_at=NULL WHERE occurrence_id=(SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d)", gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ), $zq_type ) );
PassCaptain\Privacy::process_deferred();
check( 'after the event the deferred order is anonymised and its participant answers are deleted', str_starts_with( Orders::get( $zq['order_id'] )->customer_email, 'deleted+' ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_participant_answers WHERE ticket_id=%d", $zq_ticket ) ) && ! isset( PassCaptain\Privacy::deferred()[ (int) $zq['order_id'] ] ) );

echo "Integration: revoked exchange tickets are reported to the voucher system\n";
PassCaptain\Integrations\Vouchers\TicketService::register();
$zr_seen = array();
add_action( PassCaptain\Integrations\Vouchers\TicketService::REVOKED_ACTION, static function ( $info ) use ( &$zr_seen ) { $zr_seen[] = $info; } );
$zr_service = PassCaptain\Integrations\Vouchers\TicketService::service( null, 1 );
$zr_type = make_type( 10, 10, 2500 );
$zr = $zr_service->issue_tickets( array( 'provider' => 'voucher-captain', 'voucher_id' => 901, 'redemption_key' => 'passcaptain:ticket:901', 'code_hint' => 'ZR01' ), 'vcap-voucher-901', array( 'ticket_type_id' => $zr_type, 'quantity' => 2 ), array( 'email' => 'zr@example.com' ) );
check( 'a voucher exchange issues tickets without reporting anything', is_array( $zr ) && 'issued' === $zr['status'] && ! $zr_seen );
Orders::cancel_order( (int) $zr['order_id'], 'admin' );
$zr_last = end( $zr_seen );
check( 'cancelling the exchange order in Pass Captain reports it with the redemption key', $zr_seen && 'passcaptain:ticket:901' === $zr_last['redemption_key'] && 0 === $zr_last['remaining'] && 2 === $zr_last['revoked'] );
$zr_seen = array();
$zr3 = $zr_service->issue_tickets( array( 'provider' => 'voucher-captain', 'voucher_id' => 903, 'redemption_key' => 'passcaptain:ticket:903' ), 'vcap-voucher-903', array( 'ticket_type_id' => $zr_type, 'quantity' => 1 ), array( 'email' => 'zr3@example.com' ) );
$zr3_state = $zr_service->get( 'vcap-voucher-903' );
check( 'the exchange state names the event, the start and the recipient', '' !== $zr3_state['event'] && '' !== $zr3_state['starts_at'] && 'zr3@example.com' === $zr3_state['email'] );
$zr3_mails = count( $GLOBALS['wpcap_test_mails'] );
$zr3_resend = $zr_service->resend_tickets( 'vcap-voucher-903' );
check( 'the voucher system can send the tickets of an exchange again', is_array( $zr3_resend ) && 'zr3@example.com' === $zr3_resend['email'] && count( $GLOBALS['wpcap_test_mails'] ) === $zr3_mails + 1 );
$zr_service->resend_tickets( 'vcap-voucher-903' );
$zr_service->resend_tickets( 'vcap-voucher-903' );
$zr3_limited = $zr_service->resend_tickets( 'vcap-voucher-903' );
check( 'resending is limited per hour and unknown exchanges are refused', is_wp_error( $zr3_limited ) && 'wpcap_resend_rate_limited' === $zr3_limited->get_error_code() && is_wp_error( $zr_service->resend_tickets( 'vcap-voucher-unknown' ) ) );
$zr2 = $zr_service->issue_tickets( array( 'provider' => 'voucher-captain', 'voucher_id' => 902, 'redemption_key' => 'passcaptain:ticket:902' ), 'vcap-voucher-902', array( 'ticket_type_id' => $zr_type, 'quantity' => 1 ), array( 'email' => 'zr2@example.com' ) );
$zr_service->revoke_tickets( 'vcap-voucher-902', 'voucher_refunded' );
check( 'a revocation requested by the voucher system is not reported back', is_array( $zr2 ) && ! $zr_seen && 'revoked' === $zr_service->get( 'vcap-voucher-902' )['status'] );

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

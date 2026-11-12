<?php
/** Invoices with VAT, credit notes and accounting export. Included from run.php. */
// phpcs:ignoreFile
use PassCaptain\Orders;

echo "Invoices, credit notes, accounting export\n";
PassCaptain\Settings::update( array( 'seller_name' => 'Kulturverein', 'seller_vat_id' => 'ATU12345678', 'refund_approval_threshold' => 0 ) );
$fin_type = make_type( 10, 10, 1100 );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET tax_rate_bp=1000 WHERE ticket_type_id=$fin_type" );
$fin = Orders::create( array( 'email' => 'fin@example.com', 'items' => array( array( 'ticket_type_id' => $fin_type, 'quantity' => 3 ) ), 'idempotency_key' => 'fin-1' ) );
Orders::confirm_manual_payment( $fin['order_id'] );
$inv = PassCaptain\Invoices::issue_for_order( $fin['order_id'] );
$row = $wpdb->get_row( "SELECT * FROM {$p}wpcap_invoices WHERE invoice_id=" . (int) $inv['invoice_id'] );
$line = $wpdb->get_row( "SELECT * FROM {$p}wpcap_invoice_lines WHERE invoice_id=" . (int) $inv['invoice_id'] );
check( 'invoice carries VAT from order lines', 300 === (int) $row->tax_minor && 3300 === (int) $row->total_minor && 10.0 === (float) $line->tax_rate && 'issued' === $row->status );
check( 'invoice snapshot contains seller data', false !== strpos( $row->buyer_snapshot, 'ATU12345678' ) );
$fin_tickets = tickets( $fin['order_id'] );
$refund = PassCaptain\Refunds::request( $fin['order_id'], array( (int) $fin_tickets[0]->ticket_id ), 'Absage Gast', 'fin-r1' );
$credit = PassCaptain\Invoices::credit_note_for_refund( $refund['refund_id'] );
$credit_again = PassCaptain\Invoices::credit_note_for_refund( $refund['refund_id'] );
check( 'refund creates exactly one credit note with VAT share', is_array( $credit ) && 1100 === (int) $credit['total_minor'] && 100 === (int) $credit['tax_minor'] && $credit['credit_note_id'] === $credit_again['credit_note_id'] );
$html = PassCaptain\Invoices::render_html( $inv['invoice_id'] );
check( 'print view lists invoice number, VAT and credit note', false !== strpos( $html, $inv['invoice_number'] ) && false !== strpos( $html, $credit['credit_note_number'] ) && false !== strpos( $html, 'Umsatzsteuer' ) );
$rows = PassCaptain\Invoices::export_rows( gmdate( 'Y-m-d', time() - 86400 ), gmdate( 'Y-m-d' ) );
$kinds = array_count_values( array_column( array_slice( $rows, 1 ), 0 ) );
check( 'accounting export contains invoice lines and credit notes', ( $kinds['Rechnung'] ?? 0 ) >= 1 && ( $kinds['Gutschrift'] ?? 0 ) >= 1 && 'Belegart' === $rows[0][0] );

echo "Voucher Captain bridge\n";
final class Test_Voucher_Provider implements PassCaptain\Integrations\Vouchers\ProviderInterface {
	public array $calls = array();
	public $commit_error = null;
	public $reserve_error = null;
	public function quote( array $context ) { $this->calls[] = array( 'quote', $context['amount_minor'] ); return 'BAD' === $context['code'] ? new WP_Error( 'invalid', 'Gutschein ungültig' ) : array( 'amount_minor' => min( 1500, $context['amount_minor'] ), 'currency' => $context['currency'], 'token' => 'q1' ); }
	public function reserve( array $quote, $key ) { $this->calls[] = array( 'reserve', $key ); if ( $this->reserve_error ) { return $this->reserve_error; } return array( 'reservation' => 'r1', 'amount_minor' => $quote['amount_minor'] ); }
	public function commit( array $reservation, $key ) { $this->calls[] = array( 'commit', $key ); if ( $this->commit_error ) { return $this->commit_error; } return array( 'redemption' => 'd1' ); }
	public function release( array $reservation, $key ) { $this->calls[] = array( 'release', $key ); return true; }
	public function refund( array $redemption, $amount, $key ) { $this->calls[] = array( 'refund', $amount, $key ); return true; }
}
$voucher_provider = new Test_Voucher_Provider();
$GLOBALS['wpcap_test_filters'][ PassCaptain\Integrations\Vouchers\Bridge::FILTER ] = $voucher_provider;
$vt = make_type( 10, 10, 1000 );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET tax_rate_bp=2000 WHERE ticket_type_id=$vt" );
$vo = Orders::create( array( 'email' => 'v@example.com', 'items' => array( array( 'ticket_type_id' => $vt, 'quantity' => 3 ) ), 'idempotency_key' => 'v-1' ) );
$bad = PassCaptain\Integrations\Vouchers\Checkout::apply( $vo['order_id'], 'BAD' );
check( 'rejected voucher returns a customer error', is_wp_error( $bad ) && 'Gutschein ungültig' === $bad->get_error_message() );
$applied = PassCaptain\Integrations\Vouchers\Checkout::apply( $vo['order_id'], 'GOOD-1234' );
$vrow = Orders::get( $vo['order_id'] );
$item_tax = (int) $wpdb->get_var( "SELECT SUM(tax_minor) FROM {$p}wpcap_order_items WHERE order_id=" . (int) $vo['order_id'] );
check( 'voucher reduces total and VAT consistently', 1500 === $applied['applied_minor'] && 1500 === (int) $vrow->total_minor && 250 === (int) $vrow->tax_minor && 250 === $item_tax );
check( 'second voucher on same order is rejected', is_wp_error( PassCaptain\Integrations\Vouchers\Checkout::apply( $vo['order_id'], 'GOOD-1234' ) ) );
Orders::confirm_manual_payment( $vo['order_id'] );
PassCaptain\Integrations\Vouchers\Checkout::commit( $vo['order_id'] );
PassCaptain\Integrations\Vouchers\Checkout::commit( $vo['order_id'] );
$commits = array_filter( $voucher_provider->calls, static fn( $c ) => 'commit' === $c[0] );
check( 'voucher committed exactly once after payment', 1 === count( $commits ) );
$vtickets = tickets( $vo['order_id'] );
$vr = PassCaptain\Refunds::request( $vo['order_id'], array( (int) $vtickets[0]->ticket_id ), 'Storno', 'v-r1' );
PassCaptain\Integrations\Vouchers\Checkout::refund( $vr['refund_id'], $vo['order_id'] );
PassCaptain\Integrations\Vouchers\Checkout::refund( $vr['refund_id'], $vo['order_id'] ) ;
$refunds = array_values( array_filter( $voucher_provider->calls, static fn( $c ) => 'refund' === $c[0] ) );
check( 'ticket refund refunds proportional voucher share once, with stable key', 1 === count( $refunds ) && 500 === (int) $vr['amount_minor'] && 500 === $refunds[0][1] && 'wpcap-refund-' . $vr['refund_id'] === $refunds[0][2] );
$vo2 = Orders::create( array( 'email' => 'v2@example.com', 'items' => array( array( 'ticket_type_id' => $vt, 'quantity' => 1 ) ), 'idempotency_key' => 'v-2' ) );
$applied2 = PassCaptain\Integrations\Vouchers\Checkout::apply( $vo2['order_id'], 'GOOD-9999' );
check( 'voucher covering the full total confirms the order and issues tickets', 0 === (int) Orders::get( $vo2['order_id'] )->total_minor && 'paid' === Orders::get( $vo2['order_id'] )->payment_status && 1 === count( tickets( $vo2['order_id'] ) ) );
$vo3 = Orders::create( array( 'email' => 'v3@example.com', 'items' => array( array( 'ticket_type_id' => $vt, 'quantity' => 2 ) ), 'idempotency_key' => 'v-3' ) );
PassCaptain\Integrations\Vouchers\Checkout::apply( $vo3['order_id'], 'GOOD-5555' );
Orders::cancel_order( $vo3['order_id'], 'test' );
PassCaptain\Integrations\Vouchers\Checkout::release( $vo3['order_id'] );
$releases = array_filter( $voucher_provider->calls, static fn( $c ) => 'release' === $c[0] );
check( 'cancelled order releases its voucher reservation', 1 === count( $releases ) && false !== strpos( Orders::get( $vo3['order_id'] )->voucher_snapshot, '"released"' ) );

// Late payment after the order expired and its voucher reservation was released.
$vo4 = Orders::create( array( 'email' => 'v4@example.com', 'items' => array( array( 'ticket_type_id' => $vt, 'quantity' => 2 ) ), 'idempotency_key' => 'v-4' ) );
PassCaptain\Integrations\Vouchers\Checkout::apply( $vo4['order_id'], 'GOOD-4444' );
$past = gmdate( 'Y-m-d H:i:s', time() - 60 );
$wpdb->query( "UPDATE {$p}wpcap_reservations SET status='expired', expires_at='$past' WHERE order_id=" . (int) $vo4['order_id'] );
$wpdb->query( "UPDATE {$p}wpcap_orders SET status='expired', payment_status='expired' WHERE order_id=" . (int) $vo4['order_id'] );
PassCaptain\Integrations\Vouchers\Checkout::release( $vo4['order_id'] );
$late = Orders::confirm_external_payment( $vo4['order_id'], 'late-transfer' );
PassCaptain\Integrations\Vouchers\Checkout::commit( $vo4['order_id'] );
$vs4 = PassCaptain\Integrations\Vouchers\Checkout::voucher_state( $vo4['order_id'] );
$late_calls = array_values( array_filter( $voucher_provider->calls, static fn( $c ) => in_array( $c[0], array( 'reserve', 'commit' ), true ) && str_contains( (string) $c[1], $vo4['public_id'] ) ) );
check( 'late payment after a released voucher reserves it again and commits it', true === $late && 'committed' === $vs4['status'] && ! empty( $vs4['late_reservation'] ) && array( 'reserve', 'reserve', 'commit' ) === array_column( $late_calls, 0 ) && 'wpcap-order-late-' . $vo4['public_id'] === $late_calls[1][1] );

// Voucher can no longer be charged: tickets stay valid, order is flagged and the admin alerted.
$vo5 = Orders::create( array( 'email' => 'v5@example.com', 'items' => array( array( 'ticket_type_id' => $vt, 'quantity' => 1 ) ), 'idempotency_key' => 'v-5' ) );
PassCaptain\Integrations\Vouchers\Checkout::apply( $vo5['order_id'], 'GOOD-5151' );
Orders::confirm_manual_payment( $vo5['order_id'] );
$voucher_provider->commit_error = new WP_Error( 'vcap_cancelled', 'Gutschein storniert', array( 'status' => 409 ) );
$mails_before = count( $GLOBALS['wpcap_test_mails'] );
$done5 = PassCaptain\Integrations\Vouchers\Checkout::commit( $vo5['order_id'] );
$vs5 = PassCaptain\Integrations\Vouchers\Checkout::voucher_state( $vo5['order_id'] );
$alert = array_values( array_filter( array_slice( $GLOBALS['wpcap_test_mails'], $mails_before ), static fn( $m ) => str_contains( $m['subject'], 'Gutschein konnte nicht belastet werden' ) ) );
check( 'a voucher that cannot be charged flags the paid order and alerts the admin', true === $done5 && 'commit_failed' === $vs5['status'] && 'vcap_cancelled' === $vs5['error'] && 1 === count( $alert ) && str_contains( $alert[0]['body'], $vo5['public_id'] ) && 1 === count( tickets( $vo5['order_id'] ) ) );
PassCaptain\Integrations\Vouchers\Checkout::commit( $vo5['order_id'] );
check( 'a flagged voucher commit is not retried', 'commit_failed' === PassCaptain\Integrations\Vouchers\Checkout::voucher_state( $vo5['order_id'] )['status'] );

// Temporary outage: retried through the Jobs queue with the same keys.
$vo6 = Orders::create( array( 'email' => 'v6@example.com', 'items' => array( array( 'ticket_type_id' => $vt, 'quantity' => 1 ) ), 'idempotency_key' => 'v-6' ) );
PassCaptain\Integrations\Vouchers\Checkout::apply( $vo6['order_id'], 'GOOD-6161' );
$voucher_provider->commit_error = null;
Orders::confirm_manual_payment( $vo6['order_id'] );
$voucher_provider->commit_error = new WP_Error( 'vcap_temporary_failure', 'Später erneut', array( 'status' => 503 ) );
$retry6 = PassCaptain\Integrations\Vouchers\Checkout::commit( $vo6['order_id'] );
$job6 = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_jobs WHERE idempotency_key=%s", hash( 'sha256', 'voucher-commit-' . (int) $vo6['order_id'] ) ) );
check( 'a temporary provider error schedules a retry job', false === $retry6 && $job6 && 'voucher_commit' === $job6->job_type && 'reserved' === PassCaptain\Integrations\Vouchers\Checkout::voucher_state( $vo6['order_id'] )['status'] );
$voucher_provider->commit_error = null;
$wpdb->query( "UPDATE {$p}wpcap_jobs SET attempts=1 WHERE job_id=" . (int) $job6->job_id );
$handled6 = PassCaptain\Integrations\Vouchers\Checkout::handle_job( false, 'voucher_commit', array( 'order_id' => (int) $vo6['order_id'] ), (int) $job6->job_id );
check( 'the retry job commits the voucher once the provider is back', true === $handled6 && 'committed' === PassCaptain\Integrations\Vouchers\Checkout::voucher_state( $vo6['order_id'] )['status'] );
check( 'other job types are left to their handlers', 'x' === PassCaptain\Integrations\Vouchers\Checkout::handle_job( 'x', 'other_job', array() ) );

$vo7 = Orders::create( array( 'email' => 'v7@example.com', 'items' => array( array( 'ticket_type_id' => $vt, 'quantity' => 1 ) ), 'idempotency_key' => 'v-7' ) );
PassCaptain\Integrations\Vouchers\Checkout::apply( $vo7['order_id'], 'GOOD-7171' );
Orders::confirm_manual_payment( $vo7['order_id'] );
$voucher_provider->commit_error = new WP_Error( 'vcap_temporary_failure', 'Später erneut', array( 'status' => 503 ) );
PassCaptain\Integrations\Vouchers\Checkout::commit( $vo7['order_id'], true );
check( 'the last retry flags the order instead of giving up silently', 'commit_failed' === PassCaptain\Integrations\Vouchers\Checkout::voucher_state( $vo7['order_id'] )['status'] );
$voucher_provider->commit_error = null;
unset( $GLOBALS['wpcap_test_filters'][ PassCaptain\Integrations\Vouchers\Bridge::FILTER ] );

echo "Voucher to ticket exchange (reverse bridge)\n";
$ticket_service = PassCaptain\Integrations\Vouchers\TicketService::service( null, 1 );
check( 'ticket service is offered for contract version 1 only', $ticket_service instanceof PassCaptain\Integrations\Vouchers\TicketService && null === PassCaptain\Integrations\Vouchers\TicketService::service( null, 2 ) );
$xt = make_type( 3, 10, 4000 );
$targets = array_column( $ticket_service->targets(), 'ticket_type_id' );
check( 'upcoming ticket types are listed as exchange targets', in_array( $xt, $targets, true ) );
$src = array( 'provider' => 'voucher-captain', 'voucher_id' => 77, 'redemption_key' => 'vcap-x-77', 'code_hint' => 'ABCD' );
$mails_before = count( $GLOBALS['wpcap_test_mails'] );
$x1 = $ticket_service->issue_tickets( $src, 'vcap-voucher-77', array( 'ticket_type_id' => $xt, 'quantity' => 2 ), array( 'email' => 'gift@example.com', 'name' => 'Gift' ) );
$x1_order = is_array( $x1 ) ? Orders::get( $x1['order_id'] ) : null;
$x1_mails = array_filter( array_slice( $GLOBALS['wpcap_test_mails'], $mails_before ), static fn( $m ) => 'gift@example.com' === $m['to'] && str_starts_with( $m['subject'], 'Deine Tickets' ) );
check( 'a voucher is exchanged for two free tickets sent to the recipient', is_array( $x1 ) && 'issued' === $x1['status'] && 2 === count( $x1['ticket_ids'] ) && 0 === (int) $x1_order->total_minor && 'paid' === $x1_order->payment_status && 'voucher' === $x1_order->payment_provider && 1 === count( $x1_mails ) );
$x1_again = $ticket_service->issue_tickets( $src, 'vcap-voucher-77', array( 'ticket_type_id' => $xt, 'quantity' => 2 ), array( 'email' => 'gift@example.com' ) );
check( 'repeating the exchange answers with the same order and tickets', is_array( $x1_again ) && $x1_again['order_id'] === $x1['order_id'] && $x1_again['ticket_ids'] === $x1['ticket_ids'] && true === $x1_again['reused'] && 2 === count( tickets( $x1['order_id'] ) ) );
check( 'the exchange is listed in the audit log with its voucher', 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='voucher_tickets_issued' AND object_id=%d", $x1['order_id'] ) ) );
$x2 = $ticket_service->issue_tickets( $src, 'vcap-voucher-78', array( 'ticket_type_id' => $xt, 'quantity' => 2 ), array( 'email' => 'gift2@example.com' ) );
check( 'a sold-out ticket type is refused without creating tickets', is_wp_error( $x2 ) && empty( $x2->get_error_data()['retryable'] ) && null === $ticket_service->get( 'vcap-voucher-78' ) );
check( 'invalid requests are refused', is_wp_error( $ticket_service->issue_tickets( $src, '', array( 'ticket_type_id' => $xt ), array( 'email' => 'x@example.com' ) ) ) && is_wp_error( $ticket_service->issue_tickets( $src, 'vcap-voucher-79', array( 'ticket_type_id' => $xt, 'quantity' => 1 ), array( 'email' => 'nope' ) ) ) );
$ticket_service->revoke_tickets( 'vcap-voucher-77', 'voucher voided' );
$ticket_service->revoke_tickets( 'vcap-voucher-77', 'voucher voided' );
$x1_state = $ticket_service->get( 'vcap-voucher-77' );
check( 'revoking the exchange cancels its tickets (repeat is harmless)', 'revoked' === $x1_state['status'] && array() === $x1_state['ticket_ids'] && 0 === count( tickets( $x1['order_id'], 'valid' ) ) );
check( 'a revoked exchange is not issued again', is_wp_error( $ticket_service->issue_tickets( $src, 'vcap-voucher-77', array( 'ticket_type_id' => $xt, 'quantity' => 2 ), array( 'email' => 'gift@example.com' ) ) ) );
$x3 = $ticket_service->issue_tickets( $src, 'vcap-voucher-80', array( 'ticket_type_id' => $xt, 'quantity' => 2 ), array( 'email' => 'gift3@example.com' ) );
check( 'revoked tickets give their capacity back', is_array( $x3 ) && 2 === count( $x3['ticket_ids'] ) );
check( 'a public checkout cannot claim a voucher exchange key', ! is_wp_error( $free_try = Orders::create( array( 'email' => 'pub@example.com', 'items' => array( array( 'ticket_type_id' => make_type( 5, 10, 1000 ), 'quantity' => 1 ) ), 'idempotency_key' => 'vcap-voucher-80' ) ) ) && $free_try['order_id'] !== $x3['order_id'] );

// Voucher valid for any date of an event: the customer chooses date and ticket type.
$ev_type = make_type( 10, 10, 3000 );
$ev = $wpdb->get_row( "SELECT o.event_id,o.occurrence_id FROM {$p}wpcap_ticket_types tt JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=$ev_type" );
$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $ev->event_id, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 14 * 86400 ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => 0, 'status' => 'scheduled' ) );
$ev_occ2 = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $ev_occ2, 'name' => 'Standard', 'price_minor' => 3000, 'currency' => 'EUR', 'capacity' => 10, 'max_per_order' => 10, 'status' => 'active' ) );
$ev_type2 = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $ev_occ2, 'name' => 'VIP', 'price_minor' => 9000, 'currency' => 'EUR', 'capacity' => 10, 'max_per_order' => 10, 'status' => 'active' ) );
$ev_vip = (int) $wpdb->insert_id;
$ev_target = array( 'event_id' => (int) $ev->event_id, 'ticket_name' => 'Standard' );
$choices = array_column( $ticket_service->options( $ev_target ), 'ticket_type_id' );
$listed = array_values( array_filter( $ticket_service->events(), static fn( $e ) => (int) $e['event_id'] === (int) $ev->event_id ) );
check( 'an event voucher offers every upcoming date of the event with its ticket name', array( $ev_type, $ev_type2 ) === $choices && 1 === count( $listed ) && 2 === $listed[0]['dates'] && in_array( 'VIP', $listed[0]['ticket_names'], true ) );
$ev_x = $ticket_service->issue_tickets( $src, 'vcap-voucher-90', $ev_target + array( 'ticket_type_id' => $ev_type2, 'quantity' => 1 ), array( 'email' => 'ev@example.com' ) );
check( 'the customer gets a ticket for the chosen date', is_array( $ev_x ) && 1 === count( $ev_x['ticket_ids'] ) && $ev_type2 === (int) tickets( $ev_x['order_id'] )[0]->ticket_type_id );
check( 'a ticket type of another ticket name or event cannot be chosen', is_wp_error( $ticket_service->issue_tickets( $src, 'vcap-voucher-91', $ev_target + array( 'ticket_type_id' => $ev_vip, 'quantity' => 1 ), array( 'email' => 'ev@example.com' ) ) ) && is_wp_error( $ticket_service->issue_tickets( $src, 'vcap-voucher-92', $ev_target + array( 'ticket_type_id' => $xt, 'quantity' => 1 ), array( 'email' => 'ev@example.com' ) ) ) );

$wpdb->update( "{$p}wpcap_ticket_types", array( 'capacity' => 1 ), array( 'ticket_type_id' => $ev_type2 ) );
check( 'sold-out dates are not offered for the voucher quantity', array( $ev_type ) === array_column( $ticket_service->options( $ev_target + array( 'quantity' => 1 ) ), 'ticket_type_id' ) && array_key_exists( 'available', $ticket_service->targets()[0] ) );
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $ev_type, 'field_key' => 'name', 'label' => 'Name', 'field_type' => 'text', 'required' => 1, 'status' => 'active' ) );
$ev_fields = $ticket_service->fields( $ev_type );
check( 'attendee fields of a ticket type are listed for the exchange form', 1 === count( $ev_fields ) && 'name' === $ev_fields[0]['key'] && true === $ev_fields[0]['required'] );
check( 'missing required attendee answers refuse the exchange', is_wp_error( $ticket_service->issue_tickets( $src, 'vcap-voucher-93', $ev_target + array( 'ticket_type_id' => $ev_type, 'quantity' => 1 ), array( 'email' => 'ev@example.com' ) ) ) );
$ev_y = $ticket_service->issue_tickets( $src, 'vcap-voucher-94', $ev_target + array( 'ticket_type_id' => $ev_type, 'quantity' => 1 ), array( 'email' => 'ev@example.com', 'attendees' => array( array( 'ticket_type_id' => $ev_type, 'answers' => array( 'name' => 'Erika' ) ) ) ) );
check( 'attendee answers are stored with the exchanged ticket', is_array( $ev_y ) && 'Erika' === $wpdb->get_var( $wpdb->prepare( "SELECT a.answer FROM {$p}wpcap_participant_answers a JOIN {$p}wpcap_tickets t ON t.ticket_id=a.ticket_id WHERE t.order_id=%d", $ev_y['order_id'] ) ) );

echo "Self-service and schedule changes\n";
PassCaptain\Settings::update( array( 'attendee_edit_hours' => 24 ) );
$st = make_type( 10, 10, 0 );
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $st, 'field_key' => 'diet', 'label' => 'Ernährung', 'field_type' => 'text', 'required' => 0, 'status' => 'active' ) );
$so = Orders::create( array( 'email' => 'self@example.com', 'items' => array( array( 'ticket_type_id' => $st, 'quantity' => 1 ) ), 'idempotency_key' => 'self-1' ) );
$access_mail = array_values( array_filter( $GLOBALS['wpcap_test_mails'], static fn( $m ) => 'self@example.com' === $m['to'] && false !== strpos( $m['body'], 'wpcap_access=' ) ) );
preg_match( '/wpcap_access=([a-f0-9]{64})/', end( $access_mail )['body'], $am );
$sticket = tickets( $so['order_id'] )[0];
$assigned = PassCaptain\TicketAssignments::assign( $so['public_id'], $am[1], $sticket->public_id, 'Clara', 'clara@example.com', array( 'diet' => 'vegan' ) );
$diet = $wpdb->get_var( "SELECT answer FROM {$p}wpcap_participant_answers WHERE ticket_id=" . (int) $sticket->ticket_id );
check( 'buyer can update attendee and custom fields before the deadline', ! is_wp_error( $assigned ) && 'vegan' === $diet );
$wpdb->query( "UPDATE {$p}wpcap_occurrences o JOIN {$p}wpcap_ticket_types tt ON tt.occurrence_id=o.occurrence_id SET o.starts_at='" . gmdate( 'Y-m-d H:i:s', time() + 3600 ) . "' WHERE tt.ticket_type_id=$st" );
$late = PassCaptain\TicketAssignments::assign( $so['public_id'], $am[1], $sticket->public_id, 'Dora', 'dora@example.com', array( 'diet' => 'x' ) );
check( 'changes are blocked inside the edit deadline', is_wp_error( $late ) );
$occ_id = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$st" );
$before = count( $GLOBALS['wpcap_test_mails'] );
$notified = PassCaptain\Notifications::occurrence_rescheduled( $occ_id, '2026-12-01 18:00:00', '2026-12-08 18:00:00' );
$last = end( $GLOBALS['wpcap_test_mails'] );
check( 'reschedule notifies ticket holders (attendee address preferred)', 1 === $notified && 'clara@example.com' === $last['to'] && false !== strpos( $last['body'], '08.12.2026' ) );

echo "Ticket PDF\n";
if ( is_readable( dirname( __DIR__, 2 ) . '/vendor/autoload.php' ) ) { require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php'; }
$pt = make_type( 5, 10, 0 );
$before = count( $GLOBALS['wpcap_test_mails'] );
$po = Orders::create( array( 'email' => 'pdf@example.com', 'items' => array( array( 'ticket_type_id' => $pt, 'quantity' => 2 ) ), 'idempotency_key' => 'pdf-1' ) );
$ticket_mail = array_values( array_filter( array_slice( $GLOBALS['wpcap_test_mails'], $before ), static fn( $m ) => str_starts_with( $m['subject'], 'Deine Tickets' ) ) )[0] ?? array();
$pdf = array_values( $ticket_mail['files'] ?? array() )[0] ?? '';
// Structural checks in PHP (no poppler needed in CI): header, page count, text, xref offsets.
preg_match( '/startxref\n(\d+)\n%%EOF$/', $pdf, $xm );
$xref_ok = isset( $xm[1] ) && 0 === strpos( substr( $pdf, (int) $xm[1] ), 'xref' );
check( 'ticket mail has a valid PDF with one page per ticket', str_starts_with( $pdf, '%PDF-1.4' ) && 2 === preg_match_all( '#/Type /Page /Parent#', $pdf ) && false !== strpos( $pdf, '(Konzert) Tj' ) && $xref_ok );
check( 'PDF contains the QR code when the encoder is installed', ! class_exists( '\\BaconQrCode\\Encoder\\Encoder' ) || substr_count( $pdf, 're f' ) > 200 );
check( 'temporary PDF file is removed after sending', ! glob( sys_get_temp_dir() . '/tickets-*.pdf' ) );

echo "Door sales\n";
$dt = make_type( 10, 10, 1500 );
$wpdb->query( "UPDATE {$p}wpcap_occurrences o JOIN {$p}wpcap_ticket_types tt ON tt.occurrence_id=o.occurrence_id SET o.starts_at='" . gmdate( 'Y-m-d H:i:s', time() - 3600 ) . "', o.ends_at=NULL WHERE tt.ticket_type_id=$dt" );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET sales_end='" . gmdate( 'Y-m-d H:i:s', time() - 7200 ) . "' WHERE ticket_type_id=$dt" );
$online_late = Orders::create( array( 'email' => 'late@example.com', 'items' => array( array( 'ticket_type_id' => $dt, 'quantity' => 1 ) ), 'idempotency_key' => 'door-1' ) );
$door = Orders::create( array( 'email' => 'door@example.com', 'items' => array( array( 'ticket_type_id' => $dt, 'quantity' => 1 ) ), 'idempotency_key' => 'door-2' ), array( 'key_namespace' => 'pos', 'skip_rate_limit' => true ) );
check( 'box office can sell after start and online sales end; online cannot', is_wp_error( $online_late ) && ! is_wp_error( $door ) );
$wpdb->query( "UPDATE {$p}wpcap_occurrences o JOIN {$p}wpcap_ticket_types tt ON tt.occurrence_id=o.occurrence_id SET o.ends_at='" . gmdate( 'Y-m-d H:i:s', time() - 60 ) . "' WHERE tt.ticket_type_id=$dt" );
$door_ended = Orders::create( array( 'email' => 'door@example.com', 'items' => array( array( 'ticket_type_id' => $dt, 'quantity' => 1 ) ), 'idempotency_key' => 'door-3' ), array( 'key_namespace' => 'pos', 'skip_rate_limit' => true ) );
check( 'no box office sales after the occurrence has ended', is_wp_error( $door_ended ) );

<?php
/**
 * Workstream X2 – remaining payment/refund issues:
 * 1. WooCommerce refund mirror: any failure (error or exception) queues one retry job per WooCommerce
 *    refund; the job runs until it succeeds or is dead-lettered (system status page).
 * 2. Affiliate Captain sale report: failures/exceptions queue one retry job per order.
 * 3. Provider "refunded" event for a never-paid order (STATE-MACHINES.md): covered in
 *    zzzz_w6_state_machines.php.
 * 4. Zero- and three-decimal currencies in CSV exports, the fiscal HTTP client and Stripe.
 * 5. Buyer limits are re-checked for late payments of expired holds (needs_review instead of tickets).
 * 6. Partner payout details are encrypted at rest, migrated, masked and only exported to finance.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Jobs;
use PassCaptain\Localization;
use PassCaptain\Orders;
use PassCaptain\Refunds;
use PassCaptain\Integrations\WooCommerce\OrderAdapter as Woo;
use PassCaptain\Integrations\AffiliateCaptain\Adapter as AffiliateAdapter;
use PassCaptain\Partners\AdminPage as PartnerAdmin;
use PassCaptain\Partners\Repository as Partners;
use PassCaptain\Partners\Settlements;
use PassCaptain\Payments\StripeGateway;
use PassCaptain\Security\Vault;

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_transients'] = array();
PassCaptain\Settings::update( array( 'refund_approval_threshold' => 0, 'require_2fa_for_refunds' => 0 ) );
$x2_job = static function ( $key ) use ( $wpdb, $p ) {
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_jobs WHERE idempotency_key=%s", hash( 'sha256', $key ) ) );
};
$x2_due = static function ( $job_id ) use ( $wpdb, $p ) {
	$wpdb->query( "UPDATE {$p}wpcap_jobs SET run_after='2000-01-01 00:00:00' WHERE job_id=" . (int) $job_id );
};
$x2_audits = static function ( $action, $object_id ) use ( $wpdb, $p ) {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action=%s AND object_id=%d", $action, $object_id ) );
};

/* ------------------------------------------------------------------------------------------ */
echo "X2: WooCommerce refund mirror is retried through the job queue\n";
add_filter( 'wp_pass_captain_process_job_v1', array( Woo::class, 'handle_job' ), 10, 3 );
$type = make_type( 10, 10, 2500 );
$wc = woo_t_order( 9901, array( array( $type, 2, '50.00' ) ) );
$core = Woo::reserve( $wc );
$wc->status = 'processing';
Woo::sync_paid_order( 9901 );
$refund = woo_t_refund( 9902, $wc, '25.00', array( array( 0, 1, 25 ) ) );
$x2_boom = static function () { throw new RuntimeException( 'Mirror exploded' ); };
add_filter( 'wpcap_woocommerce_refund_checked_in', $x2_boom );
$first = Woo::sync_refund( 9901, 9902 );
$again = Woo::sync_refund( 9901, 9902 );
$job = $x2_job( Woo::refund_job_key( 9902 ) );
$jobs_for_refund = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_jobs WHERE job_type=%s AND payload LIKE %s", Woo::REFUND_SYNC_JOB, '%"refund_id":9902%' ) );
check( 'Woo refund mirror throwing: nothing recorded, refund not flagged, exception does not escape', false === $first && false === $again && array() === Refunds::for_order( $core ) && ! $refund->get_meta( Woo::REFUND_META, true ) );
check( 'Woo refund mirror failure: exactly one retry job per WooCommerce refund (idempotent), audited, order note', $job && 1 === $jobs_for_refund && 'queued' === $job->status && $x2_audits( 'woocommerce_refund_sync_failed', $core ) >= 1 && (bool) array_filter( $wc->notes, static fn( $n ) => false !== strpos( $n, 'automatisch erneut versucht' ) ) );
$x2_due( $job->job_id );
Jobs::process_due( 200 );
$job = $x2_job( Woo::refund_job_key( 9902 ) );
check( 'Woo refund retry job still failing: back to the queue with the real error as last_error (status page)', 'queued' === $job->status && 1 === (int) $job->attempts && false !== strpos( (string) $job->last_error, 'Mirror exploded' ) && array() === Refunds::for_order( $core ) );
remove_filter( 'wpcap_woocommerce_refund_checked_in', $x2_boom );
$x2_due( $job->job_id );
Jobs::process_due( 200 );
$job = $x2_job( Woo::refund_job_key( 9902 ) );
check( 'Woo refund retry job succeeds once the cause is gone: refund recorded exactly once', 'complete' === $job->status && 1 === count( Refunds::for_order( $core ) ) && 2500 === (int) Orders::get( $core )->refunded_minor && $refund->get_meta( Woo::REFUND_META, true ) );
check( 'Woo refund retry: a further sync or job run changes nothing', true === Woo::sync_refund( 9901, 9902 ) && true === Woo::handle_job( false, Woo::REFUND_SYNC_JOB, array( 'wc_order_id' => 9901, 'refund_id' => 9902 ) ) && 1 === count( Refunds::for_order( $core ) ) );
// A failure that does not go away is retried until the attempts are used up, then dead-lettered.
$big = woo_t_refund( 9903, $wc, '25.00', array( array( 0, 1, 25 ) ) );
add_filter( 'wpcap_woocommerce_refund_checked_in', $x2_boom );
Woo::sync_refund( 9901, 9903 );
remove_filter( 'wpcap_woocommerce_refund_checked_in', $x2_boom );
$job = $x2_job( Woo::refund_job_key( 9903 ) );
$wpdb->query( "UPDATE {$p}wpcap_jobs SET attempts=" . ( Jobs::MAX_ATTEMPTS - 1 ) . " WHERE job_id=" . (int) $job->job_id );
add_filter( 'wpcap_woocommerce_refund_checked_in', $x2_boom );
$x2_due( $job->job_id );
Jobs::process_due( 200 );
remove_filter( 'wpcap_woocommerce_refund_checked_in', $x2_boom );
$failed = PassCaptain\Ops\Status::failed_items( 'jobs' );
$listed = array_values( array_filter( (array) $failed, static fn( $r ) => (int) $r->id === (int) $job->job_id ) );
check( 'Woo refund retry that keeps failing is dead-lettered and listed with its error on the system status page', 'dead_letter' === $x2_job( Woo::refund_job_key( 9903 ) )->status && 1 === count( $listed ) && false !== strpos( (string) $listed[0]->last_error, 'Mirror exploded' ) );
$retried = PassCaptain\Ops\Status::retry( 'jobs', (int) $job->job_id );
check( 'Status page retry re-queues the dead-lettered refund job', ! is_wp_error( $retried ) && 'queued' === $x2_job( Woo::refund_job_key( 9903 ) )->status );
$x2_due( $job->job_id );
Jobs::process_due( 200 );
check( 'retried refund job completes and records the second refund', 'complete' === $x2_job( Woo::refund_job_key( 9903 ) )->status && 2 === count( Refunds::for_order( $core ) ) );
remove_filter( 'wp_pass_captain_process_job_v1', array( Woo::class, 'handle_job' ), 10 );

/* ------------------------------------------------------------------------------------------ */
echo "X2: Affiliate Captain sale report is retried through the job queue\n";
$aff_type = make_type( 50, 10, 11900 );
$sales_before = count( $GLOBALS['wpac_test_sales'] );
foreach ( array( 'throw', 'error' ) as $mode ) {
	$GLOBALS['wpac_test_fail'] = $mode;
	$o = Orders::create( array( 'email' => 'x2aff-' . $mode . '@example.com', 'items' => array( array( 'ticket_type_id' => $aff_type, 'quantity' => 1 ) ), 'idempotency_key' => 'x2aff-' . $mode . uniqid() ) );
	$paid = Orders::confirm_manual_payment( $o['order_id'] );
	$job = $x2_job( 'affiliate-sale-' . $o['order_id'] );
	do_action( 'wpcap_order_paid', $o['order_id'] ); // Repeated paid event while still failing.
	$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_jobs WHERE job_type=%s AND payload=%s", AffiliateAdapter::SALE_JOB, wp_json_encode( array( 'order_id' => (int) $o['order_id'] ) ) ) );
	check( "affiliate $mode: payment and tickets unaffected, one retry job per order, failure audited", true === $paid && 1 === count( tickets( $o['order_id'], 'valid' ) ) && $job && 'queued' === $job->status && 1 === $count && $x2_audits( 'affiliate_sale_failed', $o['order_id'] ) >= 1 );
	$x2_due( $job->job_id );
	Jobs::process_due( 200 );
	$job = $x2_job( 'affiliate-sale-' . $o['order_id'] );
	check( "affiliate $mode: retry while Affiliate Captain still fails keeps the job queued with its error", 'queued' === $job->status && '' !== (string) $job->last_error && 'No handler acknowledged the job.' !== $job->last_error );
	$GLOBALS['wpac_test_fail'] = '';
	$x2_due( $job->job_id );
	Jobs::process_due( 200 );
	$job = $x2_job( 'affiliate-sale-' . $o['order_id'] );
	$reported = array_values( array_filter( $GLOBALS['wpac_test_sales'], static fn( $s ) => (string) $o['order_id'] === $s['order_id'] ) );
	check( "affiliate $mode: retry reports the sale exactly once and completes", 'complete' === $job->status && 1 === count( $reported ) && $x2_audits( 'affiliate_sale_retried', $o['order_id'] ) >= 1 );
	check( "affiliate $mode: a further job run is a no-op", true === AffiliateAdapter::handle_job( false, AffiliateAdapter::SALE_JOB, array( 'order_id' => (int) $o['order_id'] ) ) && 1 === count( array_filter( $GLOBALS['wpac_test_sales'], static fn( $s ) => (string) $o['order_id'] === $s['order_id'] ) ) );
}
$GLOBALS['wpac_test_version'] = '2.0.0';
check( 'affiliate retry job answers an error while Affiliate Captain is inactive (retried, then dead-lettered)', is_wp_error( AffiliateAdapter::handle_job( false, AffiliateAdapter::SALE_JOB, array( 'order_id' => 1 ) ) ) );
$GLOBALS['wpac_test_version'] = '1.1.0';
check( 'other job types pass through the affiliate handler unchanged', 'x' === AffiliateAdapter::handle_job( 'x', 'other_job', array() ) );

/* ------------------------------------------------------------------------------------------ */
echo "X2: zero- and three-decimal currencies\n";
check( 'money_decimal: EUR, JPY, KWD, negative and small amounts', '1234.50' === Localization::money_decimal( 123450, 'EUR' ) && '1500' === Localization::money_decimal( 1500, 'JPY' ) && '12.345' === Localization::money_decimal( 12345, 'KWD' ) && '0.005' === Localization::money_decimal( 5, 'BHD' ) && '-0.07' === Localization::money_decimal( -7, 'EUR' ) && '0.00' === Localization::money_decimal( 0, '' ) );
check( 'fiscal HTTP client: amounts follow the document currency', '12.345' === PassCaptain\Fiscalization\Providers\HttpClient::minor_to_major( 12345, 'KWD' ) && '1500' === PassCaptain\Fiscalization\Providers\HttpClient::minor_to_major( 1500, 'JPY' ) && '12.50' === PassCaptain\Fiscalization\Providers\HttpClient::minor_to_major( 1250 ) );
// Report and accounting CSV of a KWD event.
$kwd = make_type( 20, 10, 12345 );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET currency='KWD' WHERE ticket_type_id=$kwd" );
$kwd_event = (int) $wpdb->get_var( "SELECT o.event_id FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=$kwd" );
$ko = Orders::create( array( 'email' => 'x2kwd@example.com', 'items' => array( array( 'ticket_type_id' => $kwd, 'quantity' => 2 ) ), 'idempotency_key' => 'x2kwd-' . uniqid() ) );
Orders::confirm_manual_payment( $ko['order_id'] );
$today = gmdate( 'Y-m-d' );
$report = PassCaptain\Reports\Data::build( array( 'from' => $today, 'to' => $today, 'event_id' => $kwd_event ), null );
$csv = PassCaptain\Reports\Scope::csv( PassCaptain\Reports\Data::csv_rows( $report ) );
check( 'report CSV: KWD amounts with three decimals (2 × 12.345 = 24.690)', 'KWD' === $report['currency'] && false !== strpos( $csv, '24.690' ) && false === strpos( $csv, '246.90' ) );
$inv = PassCaptain\Invoices::issue_for_order( $ko['order_id'] );
$acc = PassCaptain\Reports\Scope::csv( PassCaptain\Invoices::export_rows( $today, $today ) );
check( 'accounting CSV: KWD invoice line with three decimals', ! is_wp_error( $inv ) && false !== strpos( $acc, '24.690' ) && false === strpos( $acc, '246.90' ) );
$jpy = make_type( 20, 10, 1500 );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET currency='JPY' WHERE ticket_type_id=$jpy" );
$jpy_event = (int) $wpdb->get_var( "SELECT o.event_id FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=$jpy" );
$jo = Orders::create( array( 'email' => 'x2jpy@example.com', 'items' => array( array( 'ticket_type_id' => $jpy, 'quantity' => 1 ) ), 'idempotency_key' => 'x2jpy-' . uniqid() ) );
Orders::confirm_manual_payment( $jo['order_id'] );
$jcsv = PassCaptain\Reports\Scope::csv( PassCaptain\Reports\Data::csv_rows( PassCaptain\Reports\Data::build( array( 'from' => $today, 'to' => $today, 'event_id' => $jpy_event ), null ) ) );
check( 'report CSV: JPY amounts without decimals (1500, not 15.00)', false !== strpos( $jcsv, ';1500;' ) && false === strpos( $jcsv, '15.00' ) );
// Stripe: three-decimal amounts must be multiples of 10.
PassCaptain\Settings::update( array( 'stripe_secret_key' => 'sk_test_123', 'stripe_webhook_secret' => 'whsec_test' ) );
$stripe = new StripeGateway();
http_reset();
$odd = $stripe->create_payment( array( 'public_id' => 'x2kwdodd', 'currency' => 'KWD', 'total_minor' => 12345 ), array() );
check( 'Stripe: KWD 12.345 is refused before any API call (Stripe needs multiples of 0.010)', is_wp_error( $odd ) && 'wpcap_stripe_amount_precision' === $odd->get_error_code() && array() === http_requests( 'checkout/sessions' ) );
http_push( array( 'id' => 'cs_test_kwd', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_kwd', 'status' => 'open' ), 200, '/v1/checkout/sessions' );
$even = $stripe->create_payment( array( 'public_id' => 'x2kwdeven', 'currency' => 'KWD', 'total_minor' => 12340 ), array() );
$f = form_body( http_requests( 'checkout/sessions' )[0] ?? array( 'body' => '' ) );
check( 'Stripe: KWD 12.340 is charged as 12340 (thousandths)', ! is_wp_error( $even ) && '12340' === ( $f['line_items'][0]['price_data']['unit_amount'] ?? '' ) );
http_reset();
$rf = $stripe->refund( array( 'provider_reference' => 'pi_x2', 'currency' => 'KWD', 'public_id' => 'x2' ), 1235, 'x2-ref' );
check( 'Stripe: KWD refund of 1.235 is refused instead of being rounded by Stripe', is_wp_error( $rf ) && 'wpcap_stripe_amount_precision' === $rf->get_error_code() && array() === http_requests( 'refunds' ) );
check( 'Stripe: zero-decimal and two-decimal amounts unchanged', null === StripeGateway::amount_error( 1235, 'EUR' ) && null === StripeGateway::amount_error( 1501, 'JPY' ) && 150100 === StripeGateway::to_stripe_amount( 1501, 'ISK' ) );

/* ------------------------------------------------------------------------------------------ */
echo "X2: buyer limits are re-checked for late payments of expired holds\n";
$bl_type = make_type( 20, 10, 1000 );
$bl_event = (int) $wpdb->get_var( "SELECT o.event_id FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=$bl_type" );
$limit_id = PassCaptain\Sales\BuyerLimits::create( $bl_event, array( 'max_quantity' => 2, 'match_by' => 'email' ) );
$bl_buy = static function ( $email, $qty ) use ( $bl_type ) {
	$GLOBALS['wpcap_test_transients'] = array();
	return Orders::create( array( 'email' => $email, 'items' => array( array( 'ticket_type_id' => $bl_type, 'quantity' => $qty ) ), 'idempotency_key' => 'x2bl-' . uniqid( '', true ) ), array( 'skip_rate_limit' => true ) );
};
$late = $bl_buy( 'X2-Limit@example.com', 2 );
expire_holds_of( $late['order_id'] );
Orders::expire_holds();
$meanwhile = $bl_buy( 'x2-limit@example.com', 2 );
Orders::confirm_manual_payment( $meanwhile['order_id'] );
$mails = count( $GLOBALS['wpcap_test_mails'] );
$res = Orders::confirm_external_payment( $late['order_id'], 'manual:x2-late' );
$state = Orders::get( $late['order_id'] );
$alert = array_values( array_filter( array_slice( $GLOBALS['wpcap_test_mails'], $mails ), static fn( $m ) => false !== strpos( (string) $m['subject'], 'Käuferlimit' ) ) );
check( 'setup: limit created, buyer got 2 tickets meanwhile', is_int( $limit_id ) && 2 === count( tickets( $meanwhile['order_id'], 'valid' ) ) );
check( 'late payment over the buyer limit: needs_review, no tickets issued, admin alerted', is_wp_error( $res ) && 'wpcap_needs_review' === $res->get_error_code() && 'needs_review|paid_unallocated' === $state->status . '|' . $state->payment_status && ! Orders::tickets_for_order( $late['order_id'] ) && 1 === count( $alert ) );
check( 'late payment over the buyer limit: audited with reason buyer_limit', (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='payment_unallocated' AND object_id=%d AND details LIKE %s", $late['order_id'], '%buyer_limit%' ) ) );
check( 'webhook redelivery stays needs_review (no tickets)', is_wp_error( Orders::confirm_external_payment( $late['order_id'], 'manual:x2-late' ) ) && ! Orders::tickets_for_order( $late['order_id'] ) );
$other = $bl_buy( 'x2-other@example.com', 2 );
expire_holds_of( $other['order_id'] );
Orders::expire_holds();
check( 'late payment of another buyer within the limit is confirmed and issued', true === Orders::confirm_external_payment( $other['order_id'], 'manual:x2-other' ) && 2 === count( tickets( $other['order_id'], 'valid' ) ) );
PassCaptain\Sales\BuyerLimits::delete( $limit_id );
check( 'after the operator lifts the limit, confirming the payment again issues the tickets (N → C)', true === Orders::confirm_manual_payment( $late['order_id'] ) && 2 === count( tickets( $late['order_id'], 'valid' ) ) && 'complete' === Orders::get( $late['order_id'] )->status );

/* ------------------------------------------------------------------------------------------ */
echo "X2: partner payout details are encrypted at rest\n";
$iban = 'DE89 3704 0044 0532 0130 00';
$iban_compact = 'DE89370400440532013000';
$x2_raw = static function ( $partner_id ) use ( $wpdb, $p ) { return (string) $wpdb->get_var( "SELECT payout_details FROM {$p}wpcap_partners WHERE partner_id=" . (int) $partner_id ); };
$x2_leaks = static function ( $text ) use ( $iban, $iban_compact ) { return false !== strpos( (string) $text, $iban ) || false !== strpos( (string) $text, $iban_compact ) || false !== strpos( (string) $text, '0532 0130' ); };
$pid = Partners::save( array( 'name' => 'X2 Veranstalter', 'currency' => 'EUR', 'sales_model' => PassCaptain\Partners\SalesModel::AGENT, 'payout_details' => "Kontoinhaber X2\nIBAN $iban\nBIC COBADEFFXXX" ) );
$raw = $x2_raw( $pid );
check( 'payout details are stored encrypted (no IBAN in the table)', ! is_wp_error( $pid ) && Vault::is_encrypted( $raw ) && ! $x2_leaks( $raw ) && ! $x2_leaks( $wpdb->get_var( "SELECT GROUP_CONCAT(payout_details) FROM {$p}wpcap_partners" ) ) );
check( 'mask: grouped and compact IBAN, text without IBAN, short values', '•••• 3000' === Partners::mask_payout( "Inhaber\nDE89370400440532013000\nBIC X" ) && '•••• 7890' === Partners::mask_payout( 'Konto 1234567890' ) && '••••' === Partners::mask_payout( 'abc' ) && '' === Partners::mask_payout( '' ) );
check( 'payout details decrypt for the operator and are masked to the last 4 IBAN characters', false !== strpos( (string) Partners::payout_details( Partners::get( $pid ) ), $iban ) && '•••• 3000' === Partners::mask_payout( Partners::payout_details( Partners::get( $pid ) ) ) );
$other_pid = Partners::save( array( 'name' => 'X2 Fremd', 'currency' => 'EUR' ) );
$wpdb->update( "{$p}wpcap_partners", array( 'payout_details' => $raw ), array( 'partner_id' => $other_pid ) );
check( 'ciphertext is bound to its partner row (copied into another row it does not decrypt)', null === Partners::payout_details( Partners::get( $other_pid ) ) );
$wpdb->update( "{$p}wpcap_partners", array( 'payout_details' => '' ), array( 'partner_id' => $other_pid ) );
Partners::save( array( 'name' => 'X2 Veranstalter', 'currency' => 'EUR', 'sales_model' => PassCaptain\Partners\SalesModel::AGENT ), $pid );
check( 'saving without payout details keeps the stored (encrypted) value', $raw === $x2_raw( $pid ) );
$GLOBALS['wpcap_test_user_id'] = 1;
$form = array( 'do' => 'save_partner', 'id' => $pid, 'name' => 'X2 Veranstalter', 'currency' => 'EUR', 'sales_model' => PassCaptain\Partners\SalesModel::AGENT, 'commission' => '0', 'fee_tax_rate' => '19', 'fee_per_ticket' => '0', 'payout_details' => '' );
PartnerAdmin::admin_action( $form );
check( 'admin form: empty field keeps the stored payout details', false !== strpos( (string) Partners::payout_details( Partners::get( $pid ) ), $iban ) );
ob_start(); ( new ReflectionMethod( PartnerAdmin::class, 'render_partner_form' ) )->invoke( null, Partners::get( $pid ) ); $form_html = ob_get_clean();
check( 'admin form shows only the masked hint, never the IBAN', false !== strpos( $form_html, '•••• 3000' ) && ! $x2_leaks( $form_html ) );
PartnerAdmin::admin_action( array_merge( $form, array( 'payout_details_clear' => '1' ) ) );
check( 'admin form: clear checkbox removes the payout details', '' === $x2_raw( $pid ) );
PartnerAdmin::admin_action( array_merge( $form, array( 'payout_details' => "IBAN $iban" ) ) );
check( 'admin form: new payout details are stored encrypted', Vault::is_encrypted( $x2_raw( $pid ) ) && ! $x2_leaks( $x2_raw( $pid ) ) );
// Migration of legacy plaintext rows (idempotent).
$wpdb->update( "{$p}wpcap_partners", array( 'payout_details' => "IBAN $iban" ), array( 'partner_id' => $other_pid ) );
$mig1 = Vault::migrate();
$mig2 = Vault::migrate();
check( "migration encrypts legacy plaintext payout details once ({$mig1['partners']} values)", $mig1['partners'] >= 1 && 0 === $mig2['partners'] && Vault::is_encrypted( $x2_raw( $other_pid ) ) && ! $x2_leaks( $x2_raw( $other_pid ) ) && false !== strpos( (string) Partners::payout_details( Partners::get( $other_pid ) ), $iban ) );
// Settlement snapshot and export.
$x2_m = gmdate( 'Y-m', strtotime( 'first day of -1 month' ) );
$sid = Settlements::create_draft( $pid, $x2_m );
$fin = Settlements::finalize( $sid );
$snapshot_raw = (string) $wpdb->get_var( "SELECT partner_snapshot FROM {$p}wpcap_partner_settlements WHERE settlement_id=" . (int) $sid );
check( 'finalized settlement snapshot keeps the payout account only encrypted', ! is_wp_error( $fin ) && '' !== $snapshot_raw && ! $x2_leaks( $snapshot_raw ) && Vault::is_encrypted( json_decode( $snapshot_raw, true )['partner']['payout_details'] ?? '' ) );
$csv_admin = PassCaptain\Reports\Scope::csv( Settlements::csv_rows( Settlements::get( $sid ) ) );
check( 'settlement CSV for finance contains the full payout account (needed for the transfer)', false !== strpos( $csv_admin, 'Auszahlungskonto' ) && false !== strpos( $csv_admin, $iban ) );
check( 'printable settlement document never contains the payout account', ! $x2_leaks( Settlements::render_html( Settlements::get( $sid ) ) ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options', Settlements::MANAGE_CAP );
$csv_partner = PassCaptain\Reports\Scope::csv( Settlements::csv_rows( Settlements::get( $sid ) ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'settlement CSV for a linked partner user shows only the masked account', false !== strpos( $csv_partner, '•••• 3000' ) && ! $x2_leaks( $csv_partner ) );
// Legacy finalized snapshot with plaintext is migrated as well.
$legacy = json_decode( $snapshot_raw, true );
$legacy['partner']['payout_details'] = "IBAN $iban";
$wpdb->update( "{$p}wpcap_partner_settlements", array( 'partner_snapshot' => wp_json_encode( $legacy ) ), array( 'settlement_id' => $sid ) );
$mig3 = Vault::migrate();
$snapshot_raw = (string) $wpdb->get_var( "SELECT partner_snapshot FROM {$p}wpcap_partner_settlements WHERE settlement_id=" . (int) $sid );
check( 'migration encrypts plaintext payout details in finalized settlement snapshots', $mig3['partners'] >= 1 && ! $x2_leaks( $snapshot_raw ) && false !== strpos( PassCaptain\Reports\Scope::csv( Settlements::csv_rows( Settlements::get( $sid ) ) ), $iban ) );
check( 'partner REST settlement payload has no payout account', ! $x2_leaks( wp_json_encode( ( new ReflectionMethod( PassCaptain\Partners\Api::class, 'settlement_payload' ) )->invoke( null, Settlements::get( $sid ), true ) ) ) );

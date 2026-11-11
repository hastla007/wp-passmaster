<?php
/**
 * Integration tests for the partners module (src/Partners): partner API keys (hash only, scopes,
 * event restriction, IP allowlist, expiry, revocation, rate limit, lockout, audit, PII scope) and
 * partner settlements (math incl. refunds and VAT, refund policies, immutability, number series,
 * correction carry-over, partner visibility, CSV/HTML output).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Partners\AdminPage as PartnerAdmin;
use PassCaptain\Partners\Api as PartnerApi;
use PassCaptain\Partners\Keys as PartnerKeys;
use PassCaptain\Partners\Repository as Partners;
use PassCaptain\Partners\Settlements;
use PassCaptain\Reports\Scope;

if ( ! function_exists( 'register_rest_route' ) ) { function register_rest_route( $ns, $route, $args ) { $GLOBALS['wpcap_test_routes'][ $ns . $route ] = $args; return true; } }

global $wpdb;
$p = $wpdb->prefix;
PassCaptain\Payments\Registry::reset();
PassCaptain\Settings::update( array( 'refund_approval_threshold' => 0 ) );
$GLOBALS['wpcap_test_transients'] = array();
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 1;
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

$m1 = gmdate( 'Y-m', strtotime( 'first day of -2 month' ) );
$m2 = gmdate( 'Y-m', strtotime( 'first day of -1 month' ) );
$m0 = gmdate( 'Y-m', strtotime( 'first day of -3 month' ) );

function pa_event_of_type( $type ) {
	global $wpdb;
	return (int) $wpdb->get_var( "SELECT o.event_id FROM {$wpdb->prefix}wpcap_ticket_types tt INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=" . (int) $type );
}
function pa_order( $items, $key, $created_at, $pay = true ) {
	global $wpdb;
	$r = Orders::create( array( 'email' => "$key@example.com", 'items' => $items, 'idempotency_key' => $key . '-' . uniqid() ) );
	if ( $pay ) { Orders::confirm_manual_payment( $r['order_id'] ); }
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_orders SET created_at=%s WHERE order_id=%d", $created_at, $r['order_id'] ) );
	return (int) $r['order_id'];
}
function pa_refund( $order_id, array $ticket_ids, $completed_at ) {
	global $wpdb;
	$r = PassCaptain\Refunds::request( $order_id, $ticket_ids, 'Test', 'pa-ref-' . uniqid() );
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_refunds SET updated_at=%s WHERE refund_id=%d", $completed_at, $r['refund_id'] ) );
	return $r;
}
function pa_ticket_of_type( $order_id, $type ) {
	foreach ( tickets( $order_id, 'valid' ) as $t ) { if ( (int) $t->ticket_type_id === (int) $type ) { return (int) $t->ticket_id; } }
	return 0;
}
function pa_call( $callback, $scope, $token, array $params = array() ) {
	$req = new WP_REST_Request( 'GET' );
	if ( null !== $token ) { $req->set_header( 'Authorization', 'Bearer ' . $token ); }
	foreach ( $params as $k => $v ) { $req->set_param( $k, $v ); }
	$auth = call_user_func( PartnerApi::permission( $scope ), $req );
	if ( true !== $auth ) { return $auth; }
	$res = call_user_func( array( PartnerApi::class, $callback ), $req );
	return $res instanceof WP_REST_Response ? $res->get_data() : $res;
}
function pa_status( $r ) { return is_wp_error( $r ) ? (int) ( $r->get_error_data()['status'] ?? 0 ) : 200; }
function pa_audits( $reason ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_audit_logs WHERE action='partner_key_auth_failed' AND details LIKE %s", '%"reason":"' . $reason . '"%' ) );
}

echo "Partners: master data and events\n";
$ta = make_type( 50, 10, 1190 );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET tax_rate_bp=1900, name='Normal' WHERE ticket_type_id=$ta" );
$ea = pa_event_of_type( $ta );
$wpdb->query( "UPDATE {$p}wpcap_events SET name='=Gala <b>Abend</b>' WHERE event_id=$ea" );
$occ_a = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$ta" );
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $occ_a, 'name' => 'Kind', 'price_minor' => 1070, 'currency' => 'EUR', 'capacity' => 50, 'max_per_order' => 10, 'tax_rate_bp' => 700, 'status' => 'active' ) );
$tk = (int) $wpdb->insert_id;
$tb = make_type( 50, 10, 2000 );
$eb = pa_event_of_type( $tb );
$tc = make_type( 50, 10, 1500 );
$ec = pa_event_of_type( $tc );

check( 'partner requires a name and valid percentages', is_wp_error( Partners::save( array( 'name' => '' ) ) ) && is_wp_error( Partners::save( array( 'name' => 'X', 'commission_bp' => 10001 ) ) ) && is_wp_error( Partners::save( array( 'name' => 'X', 'contact_email' => 'nope' ) ) ) );
$saved = PartnerAdmin::admin_action( array( 'do' => 'save_partner', 'name' => 'Veranstalter GmbH', 'contact_email' => 'kontakt@veranstalter.example', 'billing_address' => "Hauptstr. 1\n10115 Berlin", 'vat_id' => 'DE123456789', 'currency' => 'EUR', 'commission' => '10', 'fee_per_ticket' => '0,50', 'fee_tax_rate' => '19', 'refund_policy' => 'reverse_all', 'settlement_start' => $m1 . '-01', 'event_ids' => array( $ea, $eb ), 'users' => '77' ) );
$p1 = (int) ( $saved['partner_id'] ?? 0 );
$partner1 = Partners::get( $p1 );
check( 'admin form saves partner terms (10 %, 0,50 per ticket, 19 % VAT on fees)', $p1 && 1000 === (int) $partner1->commission_bp && 50 === (int) $partner1->fee_per_ticket_minor && 1900 === (int) $partner1->fee_tax_rate_bp && array( $ea, $eb ) === Partners::event_ids( $p1 ) && array( 77 ) === Partners::user_ids( $p1 ) );
$p2 = Partners::save( array( 'name' => 'Andere AG', 'currency' => 'EUR', 'settlement_start' => $m1 . '-01' ) );
$steal = Partners::set_events( $p2, array( $ea, $ec ) );
check( 'an event belongs to one partner only', array( $ec ) === $steal['event_ids'] && array( $ea ) === $steal['skipped'] && $p1 === Partners::partner_of_event( $ea ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
check( 'partner CRUD requires manage_options', is_wp_error( PartnerAdmin::admin_action( array( 'do' => 'save_partner', 'name' => 'Hacker' ) ) ) );
$GLOBALS['wpcap_test_denied_caps'] = array();

echo "Partners: settlement fixtures\n";
$t1 = gmdate( 'Y-m-d H:i:s', strtotime( $m1 . '-15 12:00:00 UTC' ) );
$oa = pa_order( array( array( 'ticket_type_id' => $ta, 'quantity' => 2 ), array( 'ticket_type_id' => $tk, 'quantity' => 1 ) ), 'pa-a', $t1 );
$ref1 = pa_refund( $oa, array( pa_ticket_of_type( $oa, $ta ) ), $m1 . '-20 10:00:00' );
check( 'fixture: refund of one 11,90 ticket with pro-rata VAT 1,55', 'completed' === $ref1['status'] && 1190 === (int) $ref1['amount_minor'] && 155 === (int) $ref1['tax_minor'] );
$oc = pa_order( array( array( 'ticket_type_id' => $tc, 'quantity' => 2 ) ), 'pa-c', $t1 );
$od = pa_order( array( array( 'ticket_type_id' => $ta, 'quantity' => 1 ) ), 'pa-d', $t1, false );
$old = pa_order( array( array( 'ticket_type_id' => $ta, 'quantity' => 1 ) ), 'pa-old', gmdate( 'Y-m-d H:i:s', strtotime( $m0 . '-10 12:00:00 UTC' ) ) );

echo "Partners: settlement math (sales, refunds, VAT, fees)\n";
$calc = Settlements::compute( $partner1, $m1 );
$t = $calc['totals'];
check( 'only paid orders of partner events after the settlement start are included', 3 === count( $calc['lines'] ) && ! array_filter( $calc['lines'], static fn( $l ) => in_array( $l['order_id'], array( $oc, $od, $old ), true ) ) );
check( 'gross 34,50 − refund 11,90 = 22,60; VAT 4,50 − 1,55 = 2,95; net 19,65', 3450 === $t['sales_gross'] && 1190 === $t['refunded'] && 2260 === $t['gross'] && 295 === $t['tax'] && 1965 === $t['net'] && 3 === $t['tickets_sold'] && 1 === $t['tickets_refunded'] );
check( 'VAT per rate with refund netted (19 %: 11,90/2,25, 7 %: 10,70/0,70)', 1190 === $t['rates'][1900]['gross'] && 225 === $t['rates'][1900]['tax'] && 965 === $t['rates'][1900]['net'] && 1070 === $t['rates'][700]['gross'] && 70 === $t['rates'][700]['tax'] );
check( 'commission 10 % of net per line (20,00 + 10,00 − 10,35 rounded) and fees 3×0,50 − 0,50', 196 === $t['commission'] && 100 === $t['fee'] );
check( 'platform fee 2,96 + 19 % VAT 0,56 = 3,52; payout 22,60 − 3,52 = 19,08', 296 === $t['platform_fee_net'] && 56 === $t['platform_fee_tax'] && 352 === $t['platform_fee_gross'] && 1908 === $t['payout'] );
$kinds = array_count_values( array_column( $calc['lines'], 'kind' ) );
check( 'lines are classified as sale/refund of the period', 2 === ( $kinds['sale'] ?? 0 ) && 1 === ( $kinds['refund'] ?? 0 ) );

echo "Partners: refund policies\n";
$tdd = make_type( 50, 10, 1190 );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET tax_rate_bp=1900 WHERE ticket_type_id=$tdd" );
$ed = pa_event_of_type( $tdd );
$p3 = Partners::save( array( 'name' => 'Policy KG', 'commission_bp' => 1000, 'fee_per_ticket_minor' => 50, 'fee_tax_rate_bp' => 0, 'settlement_start' => $m1 . '-01' ) );
Partners::set_events( $p3, array( $ed ) );
$oe = pa_order( array( array( 'ticket_type_id' => $tdd, 'quantity' => 2 ) ), 'pa-e', $t1 );
pa_refund( $oe, array( (int) tickets( $oe, 'valid' )[0]->ticket_id ), $m1 . '-16 09:00:00' );
$policy_totals = array();
foreach ( array( 'reverse_all', 'keep_fixed_fee', 'keep_all' ) as $policy ) {
	$partner3 = Partners::get( $p3 );
	$partner3->refund_policy = $policy;
	$policy_totals[ $policy ] = Settlements::compute( $partner3, $m1 )['totals'];
}
check( 'reverse_all reverses commission and per-ticket fee', 100 === $policy_totals['reverse_all']['commission'] && 50 === $policy_totals['reverse_all']['fee'] && 1190 === $policy_totals['reverse_all']['gross'] );
check( 'keep_fixed_fee reverses commission only', 100 === $policy_totals['keep_fixed_fee']['commission'] && 100 === $policy_totals['keep_fixed_fee']['fee'] );
check( 'keep_all keeps commission and fee', 200 === $policy_totals['keep_all']['commission'] && 100 === $policy_totals['keep_all']['fee'] && 0 === $policy_totals['keep_all']['platform_fee_tax'] && 1190 - 300 === $policy_totals['keep_all']['payout'] );

echo "Partners: finalize, number series, immutability\n";
check( 'future periods are rejected', is_wp_error( Settlements::create_draft( $p1, gmdate( 'Y-m', strtotime( 'first day of +1 month' ) ) ) ) && is_wp_error( Settlements::create_draft( $p1, '2026-13' ) ) );
$s1 = PartnerAdmin::admin_action( array( 'do' => 'create_settlement', 'partner_id' => $p1, 'period' => $m1 ) )['settlement_id'] ?? 0;
$draft1 = Settlements::get( $s1 );
check( 'draft stores the computed totals and lines', 'draft' === $draft1->status && null === $draft1->document_number && 1908 === (int) $draft1->payout_minor && 3 === count( Settlements::lines( $s1 ) ) );
check( 'second settlement for the same month is rejected', is_wp_error( Settlements::create_draft( $p1, $m1 ) ) );
$current = Settlements::create_draft( $p3, gmdate( 'Y-m' ) );
check( 'running month can be drafted but not finalized', ! is_wp_error( $current ) && is_wp_error( Settlements::finalize( $current ) ) && 'draft' === Settlements::get( $current )->status );
Settlements::delete_draft( $current );
$fin1 = PartnerAdmin::admin_action( array( 'do' => 'finalize', 'id' => $s1 ) );
$s1row = Settlements::get( $s1 );
check( 'finalize assigns a gapless PA number and locks all lines', ! is_wp_error( $fin1 ) && 'finalized' === $s1row->status && 1 === preg_match( '/^PA-\d{4}-\d{6}$/', (string) $s1row->document_number ) && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_partner_settlement_lines WHERE settlement_id=$s1 AND source_key IS NULL" ) );
$snap = json_decode( $s1row->partner_snapshot, true );
check( 'partner billing data is snapshotted', 'Veranstalter GmbH' === ( $snap['partner']['name'] ?? '' ) && 'DE123456789' === ( $snap['partner']['vat_id'] ?? '' ) );
check( 'finalized settlement cannot be recomputed, finalized again or deleted', is_wp_error( Settlements::recompute( $s1 ) ) && is_wp_error( Settlements::finalize( $s1 ) ) && is_wp_error( Settlements::delete_draft( $s1 ) ) );
check( 'no settlement for periods before a finalized one', is_wp_error( Settlements::create_draft( $p1, $m0 ) ) );
$wpdb->query( "UPDATE {$p}wpcap_partners SET commission_bp=5000 WHERE partner_id=$p1" );
$s1after_terms = Settlements::get( $s1 );
$wpdb->query( "UPDATE {$p}wpcap_partners SET commission_bp=1000 WHERE partner_id=$p1" );
check( 'changing partner terms later does not touch a finalized settlement', 196 === (int) $s1after_terms->commission_minor && 1908 === (int) $s1after_terms->payout_minor );

echo "Partners: late refunds and late payments carry over as corrections\n";
$before = array( (int) $s1row->gross_minor, (int) $s1row->payout_minor, count( Settlements::lines( $s1 ) ), $s1row->totals );
pa_refund( $oa, array( pa_ticket_of_type( $oa, $tk ) ), $m2 . '-05 10:00:00' );
Orders::confirm_manual_payment( $od );
$ob = pa_order( array( array( 'ticket_type_id' => $ta, 'quantity' => 1 ) ), 'pa-b', gmdate( 'Y-m-d H:i:s', strtotime( $m2 . '-10 12:00:00 UTC' ) ) );
$s1now = Settlements::get( $s1 );
check( 'finalized settlement is unchanged by later refunds/payments', $before === array( (int) $s1now->gross_minor, (int) $s1now->payout_minor, count( Settlements::lines( $s1 ) ), $s1now->totals ) );
$s2 = Settlements::create_draft( $p1, $m2 );
$s2row = Settlements::get( $s2 );
$lines2 = Settlements::lines( $s2 );
$by_kind = array();
foreach ( $lines2 as $l ) { $by_kind[ $l->kind ][] = $l; }
check( 'next period: sale, late sale of the previous month and refund correction', 1 === count( $by_kind['sale'] ?? array() ) && 1 === count( $by_kind['late_sale'] ?? array() ) && 1 === count( $by_kind['correction'] ?? array() ) && 3 === count( $lines2 ) );
$corr = $by_kind['correction'][0];
check( 'correction references the origin period and reverses 10,70 (VAT 1,40, commission, fee)', $m1 === $corr->origin_period && -1070 === (int) $corr->gross_minor && -140 === (int) $corr->tax_minor && -93 === (int) $corr->commission_minor && -50 === (int) $corr->fee_minor && -1 === (int) $corr->tickets );
check( 'period 2 totals: gross 13,10, VAT 2,40, platform fee 1,87, payout 11,23, corrections 1,20', 1310 === (int) $s2row->gross_minor && 240 === (int) $s2row->tax_minor && 107 === (int) $s2row->commission_minor && 50 === (int) $s2row->fee_minor && 187 === (int) $s2row->platform_fee_gross_minor && 1123 === (int) $s2row->payout_minor && 120 === (int) $s2row->corrections_minor );
check( 'items of the finalized period are never settled twice', ! array_filter( $lines2, static fn( $l ) => 'sale:' === substr( $l->item_key, 0, 5 ) && $oa === (int) $l->order_id ) );
$dup = $wpdb->insert( "{$p}wpcap_partner_settlement_lines", array( 'settlement_id' => $s2, 'partner_id' => $p1, 'kind' => 'sale', 'item_key' => 'x', 'source_key' => Settlements::lines( $s1 )[0]->source_key, 'occurred_at' => $t1 ) );
check( 'database rejects a second finalized line for the same source', false === $dup );
$fin2 = Settlements::finalize( $s2 );
check( 'second settlement gets the next number', ! is_wp_error( $fin2 ) && (int) substr( $fin2->document_number, -6 ) === (int) substr( $s1row->document_number, -6 ) + 1 );
check( 'only finalized settlements can be marked paid', is_wp_error( Settlements::mark_paid( Settlements::create_draft( $p3, $m1 ) ) ) );
$paid = PartnerAdmin::admin_action( array( 'do' => 'mark_paid', 'id' => $s1, 'reference' => 'SEPA-4711' ) );
check( 'finalized → paid with reference', ! is_wp_error( $paid ) && 'paid' === Settlements::get( $s1 )->status && 'SEPA-4711' === Settlements::get( $s1 )->payment_reference && is_wp_error( Settlements::mark_paid( $s1 ) ) );

echo "Partners: visibility, CSV and print view\n";
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options', 'wpcap_view_finance' );
$GLOBALS['wpcap_test_user_id'] = 77;
$visible = array_map( static fn( $s ) => (int) $s->settlement_id, Settlements::visible() );
$p3_draft = (int) $wpdb->get_var( "SELECT settlement_id FROM {$p}wpcap_partner_settlements WHERE partner_id=$p3 AND status='draft' LIMIT 1" );
$partner_cannot = is_wp_error( PartnerAdmin::admin_action( array( 'do' => 'finalize', 'id' => $p3_draft ) ) ) && is_wp_error( PartnerAdmin::admin_action( array( 'do' => 'create_settlement', 'partner_id' => $p1, 'period' => $m0 ) ) );
$menu_cap = PartnerAdmin::settlements_menu_cap();
$GLOBALS['wpcap_test_user_id'] = 78;
$foreign_visible = Settlements::visible();
$foreign_view = Settlements::can_view( Settlements::get( $s1 ) );
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
sort( $visible );
check( 'partner user sees only own finalized/paid settlements (read-only)', array( $s1, $s2 ) === $visible && $partner_cannot && 'read' === $menu_cap );
check( 'unlinked users see no settlements', array() === $foreign_visible && false === $foreign_view );
$csv = Scope::csv( Settlements::csv_rows( Settlements::get( $s2 ) ) );
check( 'CSV contains number, correction line and is formula safe', false !== strpos( $csv, $fin2->document_number ) && false !== strpos( $csv, 'Korrektur Erstattung' ) && false !== strpos( $csv, "'=Gala" ) && false === strpos( $csv, ';=Gala' ) && false !== strpos( $csv, '11.23' ) );
$html = Settlements::render_html( Settlements::get( $s2 ) );
check( 'print view is escaped and shows payout and corrections', false === strpos( $html, '<b>Abend</b>' ) && false !== strpos( $html, '&lt;b&gt;Abend' ) && false !== strpos( $html, 'Auszahlungsbetrag an Partner' ) && false !== strpos( $html, '11,23 EUR' ) && false !== strpos( $html, 'Korrekturen' ) );
check( 'draft print view is marked as draft', false !== strpos( Settlements::render_html( Settlements::get( $p3_draft ) ), 'Entwurf' ) );

echo "Partner API: keys\n";
$GLOBALS['wpcap_test_transients'] = array();
check( 'key needs a supported scope and only partner events', is_wp_error( PartnerKeys::issue( $p1, array( 'orders:write' ) ) ) && is_wp_error( PartnerKeys::issue( $p1, array( 'events:read' ), array( 'event_ids' => array( $ec ) ) ) ) );
check( 'invalid IP entries and past expiry are rejected', is_wp_error( PartnerKeys::issue( $p1, array( 'events:read' ), array( 'ip_allowlist' => '300.1.1.1' ) ) ) && is_wp_error( PartnerKeys::issue( $p1, array( 'events:read' ), array( 'expires_at' => '2001-01-01 00:00:00' ) ) ) );
$k1 = PartnerAdmin::admin_action( array( 'do' => 'issue_key', 'id' => $p1, 'label' => 'ERP', 'scopes' => array( 'events:read', 'orders:read', 'tickets:read', 'checkins:read', 'reports:read', 'settlements:read' ) ) );
$tok1 = $k1['key'];
$row1 = PartnerKeys::get( $k1['key_id'] );
$secret1 = substr( $tok1, strlen( 'wpcap_pk_' ) + 13 );
check( 'key format wpcap_pk_{prefix}_{secret}; only the SHA-256 hash is stored', 1 === preg_match( '/^wpcap_pk_[a-f0-9]{12}_[a-f0-9]{64}$/', $tok1 ) && hash( 'sha256', $secret1 ) === $row1->secret_hash && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_partner_keys WHERE secret_hash=%s OR scopes LIKE %s OR label LIKE %s", $secret1, '%' . $secret1 . '%', '%' . $secret1 . '%' ) ) );
check( 'new key is shown once to the admin', $tok1 === get_transient( 'wpcap_partner_new_key_1' ) );
check( 'audit log records key creation', 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='partner_key_created' AND object_id=%d", $k1['key_id'] ) ) );

$me = pa_call( 'me', null, $tok1 );
check( 'valid key authenticates and reports partner, scopes and events', ! is_wp_error( $me ) && $p1 === $me['partner']['id'] && array( $ea, $eb ) === $me['event_ids'] && null !== PartnerKeys::get( $k1['key_id'] )->last_used_at );
$events = pa_call( 'events', 'events:read', $tok1 );
check( 'events of the partner only (with occurrences and ticket types)', array( $ea, $eb ) === array_column( $events['events'], 'id' ) && 2 === count( $events['events'][0]['occurrences'][0]['ticket_types'] ) );
$orders = pa_call( 'orders', 'orders:read', $tok1 );
$order_ids = array_column( $orders['orders'], 'id' );
$public = static fn( $id ) => Orders::get( $id )->public_id;
check( 'orders of partner events only, without e-mail addresses', in_array( $public( $oa ), $order_ids, true ) && in_array( $public( $ob ), $order_ids, true ) && ! in_array( $public( $oc ), $order_ids, true ) && ! array_key_exists( 'customer_email', $orders['orders'][0] ) );
$paid_only = pa_call( 'orders', 'orders:read', $tok1, array( 'payment_status' => 'pending', 'per_page' => 1 ) );
check( 'order filters and paging', 0 === $paid_only['total'] || 1 === count( $paid_only['orders'] ) );
$tix = pa_call( 'tickets', 'tickets:read', $tok1, array( 'event_id' => $ea ) );
check( 'tickets without attendee e-mail by default', $tix['total'] > 0 && ! array_key_exists( 'attendee_email', $tix['tickets'][0] ) && ! array_filter( $tix['tickets'], static fn( $x ) => $x['event_id'] !== $ea ) );
$kp = PartnerKeys::issue( $p1, array( 'tickets:read', 'orders:read', 'attendees:read' ) );
$tixp = pa_call( 'tickets', 'tickets:read', $kp['key'], array( 'event_id' => $ea ) );
$ordp = pa_call( 'orders', 'orders:read', $kp['key'] );
check( 'e-mail addresses only with the attendees:read scope', array_key_exists( 'attendee_email', $tixp['tickets'][0] ) && array_key_exists( 'customer_email', $ordp['orders'][0] ) );

$ticket_a = (int) tickets( $ob )[0]->ticket_id;
$ticket_c = (int) tickets( $oc )[0]->ticket_id;
$wpdb->insert( "{$p}wpcap_checkins", array( 'ticket_id' => $ticket_a, 'event_id' => $ea, 'user_id' => 5, 'result' => 'checked_in', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$wpdb->insert( "{$p}wpcap_checkins", array( 'ticket_id' => $ticket_c, 'event_id' => $ec, 'user_id' => 5, 'result' => 'checked_in', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$checkins = pa_call( 'checkins', 'checkins:read', $tok1 );
check( 'check-ins of partner events only, without staff ids', 1 === $checkins['total'] && $ea === $checkins['checkins'][0]['event_id'] && ! array_key_exists( 'user_id', $checkins['checkins'][0] ) );
$report = pa_call( 'reports', 'reports:read', $tok1, array( 'from' => $m1 . '-01', 'to' => gmdate( 'Y-m-d' ) ) );
check( 'report is scoped to partner events', ! is_wp_error( $report ) && $report['total']['tickets'] >= 5 && ! array_diff( array_column( $report['events'], 'event_id' ), array( $ea, $eb ) ) );
$sts = pa_call( 'settlements', 'settlements:read', $tok1 );
$one = pa_call( 'settlement', 'settlements:read', $tok1, array( 'settlement_id' => $s2 ) );
$other = pa_call( 'settlement', 'settlements:read', $tok1, array( 'settlement_id' => $p3_draft ) );
check( 'settlements endpoint lists own finalized settlements with lines', array( $s2, $s1 ) === array_column( $sts['settlements'], 'id' ) && 1123 === $one['payout_minor'] && 3 === count( $one['lines'] ) && 404 === pa_status( $other ) );

echo "Partner API: scopes, event restriction, failures\n";
$k2 = PartnerKeys::issue( $p1, array( 'events:read', 'orders:read', 'settlements:read' ), array( 'event_ids' => array( $eb ) ) );
$restricted = pa_call( 'events', 'events:read', $k2['key'] );
$forbidden_event = pa_call( 'orders', 'orders:read', $k2['key'], array( 'event_id' => $ea ) );
$single_forbidden = pa_call( 'event', 'events:read', $k2['key'], array( 'event_id' => $ea ) );
check( 'event-restricted key sees only its events', array( $eb ) === array_column( $restricted['events'], 'id' ) && 403 === pa_status( $forbidden_event ) && 403 === pa_status( $single_forbidden ) && pa_audits( 'event_not_allowed' ) >= 2 );
check( 'event-restricted key cannot read partner-wide settlements', 403 === pa_status( pa_call( 'settlements', 'settlements:read', $k2['key'] ) ) );
$noscope = pa_call( 'tickets', 'tickets:read', $k2['key'] );
check( 'missing scope → 403 and audit', 403 === pa_status( $noscope ) && 'scope_missing' === $noscope->get_error_data()['reason'] && 1 === pa_audits( 'scope_missing' ) );
check( 'missing, malformed or wrong key → 401 and audit', 401 === pa_status( pa_call( 'me', null, null ) ) && 401 === pa_status( pa_call( 'me', null, 'abc' ) ) && 401 === pa_status( pa_call( 'me', null, substr( $tok1, 0, -1 ) . ( '0' === substr( $tok1, -1 ) ? '1' : '0' ) ) ) && 1 === pa_audits( 'invalid' ) && 1 === pa_audits( 'missing' ) && 1 === pa_audits( 'malformed' ) );
check( 'audit log never contains the secret', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE details LIKE %s", '%' . substr( $secret1, 0, 40 ) . '%' ) ) );

$k3 = PartnerKeys::issue( $p1, array( 'events:read' ), array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ) );
$ok_before = pa_status( pa_call( 'events', 'events:read', $k3['key'] ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_partner_keys SET expires_at=%s WHERE key_id=%d", gmdate( 'Y-m-d H:i:s', time() - 1 ), $k3['key_id'] ) );
check( 'expired key → 401', 200 === $ok_before && 401 === pa_status( pa_call( 'events', 'events:read', $k3['key'] ) ) && 1 === pa_audits( 'expired' ) );
$k4 = PartnerKeys::issue( $p1, array( 'events:read' ) );
$ok_before = pa_status( pa_call( 'events', 'events:read', $k4['key'] ) );
PartnerAdmin::admin_action( array( 'do' => 'revoke_key', 'id' => $k4['key_id'] ) );
check( 'revoked key → 401, revocation audited', 200 === $ok_before && 401 === pa_status( pa_call( 'events', 'events:read', $k4['key'] ) ) && 1 === pa_audits( 'revoked' ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='partner_key_revoked' AND object_id=%d", $k4['key_id'] ) ) );

$k5 = PartnerKeys::issue( $p1, array( 'events:read' ), array( 'ip_allowlist' => "203.0.113.0/24\n2001:db8::/32" ) );
$in_range = pa_status( pa_call( 'events', 'events:read', $k5['key'] ) );
$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
$out_range = pa_call( 'events', 'events:read', $k5['key'] );
$_SERVER['REMOTE_ADDR'] = '2001:db8:0:1::5';
$v6 = pa_status( pa_call( 'events', 'events:read', $k5['key'] ) );
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
check( 'IP allowlist with IPv4/IPv6 CIDR ranges', 200 === $in_range && 403 === pa_status( $out_range ) && 200 === $v6 && 1 === pa_audits( 'ip_not_allowed' ) );
check( 'audited addresses are truncated', 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='partner_key_auth_failed' AND details LIKE '%198.51.100.0%'" ) && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE details LIKE '%198.51.100.7%'" ) );
check( 'CIDR matcher edge cases', PartnerKeys::ip_allowed( '10.1.2.3', array( '10.0.0.0/8' ) ) && ! PartnerKeys::ip_allowed( '11.0.0.1', array( '10.0.0.0/8' ) ) && PartnerKeys::ip_allowed( '192.168.1.130', array( '192.168.1.128/25' ) ) && ! PartnerKeys::ip_allowed( '192.168.1.127', array( '192.168.1.128/25' ) ) && ! PartnerKeys::ip_allowed( '::ffff:10.0.0.1', array( '10.0.0.0/8' ) ) );

$k6 = PartnerKeys::issue( $p1, array( 'events:read' ), array( 'rate_limit' => 3 ) );
$codes = array();
for ( $i = 0; $i < 5; $i++ ) { $codes[] = pa_status( pa_call( 'events', 'events:read', $k6['key'] ) ); }
check( 'per-key rate limit → 429 (audited once per minute)', array( 200, 200, 200, 429, 429 ) === $codes && 1 === pa_audits( 'rate_limited' ) );

$wpdb->query( "UPDATE {$p}wpcap_partners SET status='inactive' WHERE partner_id=$p1" );
$inactive = pa_status( pa_call( 'events', 'events:read', $tok1 ) );
$wpdb->query( "UPDATE {$p}wpcap_partners SET status='active' WHERE partner_id=$p1" );
check( 'inactive partner → 403', 403 === $inactive );
Partners::set_events( $p1, array( $ea ) );
$after_unlink = pa_call( 'events', 'events:read', $k2['key'] );
Partners::set_events( $p1, array( $ea, $eb ) );
check( 'unlinking an event from the partner removes key access immediately', array() === $after_unlink['events'] );

$GLOBALS['wpcap_test_transients'] = array();
$_SERVER['REMOTE_ADDR'] = '192.0.2.44';
for ( $i = 0; $i < PartnerKeys::FAIL_LIMIT + 1; $i++ ) { pa_call( 'me', null, 'wpcap_pk_000000000000_' . str_repeat( '0', 64 ) ); }
$locked = pa_call( 'events', 'events:read', $tok1 );
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$other_ip = pa_status( pa_call( 'events', 'events:read', $tok1 ) );
check( 'repeated failures lock the client address out (valid keys too), other addresses unaffected', 429 === pa_status( $locked ) && 200 === $other_ip );
$GLOBALS['wpcap_test_transients'] = array();

PartnerApi::register_routes();
$routes = array_filter( array_keys( $GLOBALS['wpcap_test_routes'] ?? array() ), static fn( $r ) => str_starts_with( $r, 'wp-pass-captain/v1/partner/' ) );
check( 'read-only routes registered under /partner/', 9 === count( $routes ) && ! array_filter( $routes, static fn( $r ) => 'GET' !== $GLOBALS['wpcap_test_routes'][ $r ]['methods'] ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 1;

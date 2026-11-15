<?php
/**
 * Integration tests for the sales rules (src/Sales): versioned tax profiles on order lines,
 * automatic price rules for all occurrences, buyer limits across orders (incl. concurrency),
 * campaign stacking rules, logged participant consents and the report comparison/campaign
 * breakdown with role-scoped CSV. Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\CampaignsPage;
use PassCaptain\Admin\EventsPage;
use PassCaptain\Campaigns;
use PassCaptain\Invoices;
use PassCaptain\Orders;
use PassCaptain\Refunds;
use PassCaptain\Reports\Data;
use PassCaptain\Reports\Page as ReportsPage;
use PassCaptain\Sales\AdminSections;
use PassCaptain\Sales\BuyerLimits;
use PassCaptain\Sales\Consents;
use PassCaptain\Sales\PriceRules;
use PassCaptain\Sales\TaxProfiles;
use PassCaptain\Sales\TaxProfilesPage;
use PassCaptain\Settings;

PassCaptain\Modules\SalesRules::register();
PassCaptain\Modules\Series::register();
global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_transients'] = array();

if ( ! function_exists( 'wp_safe_redirect' ) ) { function wp_safe_redirect( $url, $status = 302 ) { throw new RuntimeException( 'redirect:' . $url ); } }

function sr_event_of( $type_id ) {
	global $wpdb;
	return (int) $wpdb->get_var( "SELECT o.event_id FROM {$wpdb->prefix}wpcap_ticket_types tt INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=" . (int) $type_id );
}
function sr_occ_of( $type_id ) {
	global $wpdb;
	return (int) $wpdb->get_var( "SELECT occurrence_id FROM {$wpdb->prefix}wpcap_ticket_types WHERE ticket_type_id=" . (int) $type_id );
}
function sr_buy( $type_id, $qty, $email, $key, array $payload = array(), array $internal = array( 'skip_rate_limit' => true ) ) {
	return Orders::create( array( 'email' => $email, 'items' => array( array( 'ticket_type_id' => $type_id, 'quantity' => $qty ) ), 'idempotency_key' => 'sr-' . $key . '-' . uniqid() ) + $payload, $internal );
}
function sr_item( $order_id ) {
	global $wpdb;
	return $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}wpcap_order_items WHERE order_id=" . (int) $order_id . ' ORDER BY item_id LIMIT 1' );
}
$today = gmdate( 'Y-m-d' );

/* =============================================================================================
 * Tax profiles.
 * =========================================================================================== */
echo "Sales rules: versioned tax profiles\n";
$wpdb->query( "DELETE FROM {$p}wpcap_tax_profiles" );
check( 'tax profile validation: country, empty rate and date order are checked',
	is_wp_error( TaxProfiles::create( array( 'country_code' => 'Austria', 'rate' => '20', 'version' => 'v1', 'effective_from' => '2026-01-01' ) ) )
	&& is_wp_error( TaxProfiles::create( array( 'country_code' => 'AT', 'rate' => '', 'version' => 'v1', 'effective_from' => '2026-01-01' ) ) )
	&& is_wp_error( TaxProfiles::create( array( 'country_code' => 'AT', 'rate' => '20', 'version' => 'v1', 'effective_from' => '2026-05-01', 'effective_until' => '2026-04-01' ) ) ) );
$at1 = TaxProfiles::create( array( 'country_code' => 'AT', 'tax_class' => 'standard', 'rate' => '20', 'version' => 'at-2026', 'effective_from' => gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS ), 'label' => 'AT Normalsatz' ) );
check( 'draft is created and not applied before activation', is_int( $at1 ) && null === TaxProfiles::resolve( 'AT', 'standard', $today ) && 'draft' === TaxProfiles::get( $at1 )->status );
check( 'duplicate version for country/class is refused', is_wp_error( TaxProfiles::create( array( 'country_code' => 'AT', 'rate' => '20', 'version' => 'at-2026', 'effective_from' => '2026-01-01' ) ) ) );
check( 'activation makes the version resolvable for its validity range', true === TaxProfiles::activate( $at1 ) && (int) TaxProfiles::resolve( 'AT', 'standard', $today )->tax_profile_id === $at1 && null === TaxProfiles::resolve( 'AT', 'standard', gmdate( 'Y-m-d', time() - 60 * DAY_IN_SECONDS ) ) );
$overlap = TaxProfiles::create( array( 'country_code' => 'AT', 'rate' => '13', 'version' => 'at-overlap', 'effective_from' => $today ) );
check( 'overlapping active versions are refused; active versions are frozen', is_wp_error( TaxProfiles::activate( $overlap ) ) && 'wpcap_tax_profile_overlap' === TaxProfiles::activate( $overlap )->get_error_code() && is_wp_error( TaxProfiles::update( $at1, array( 'country_code' => 'AT', 'rate' => '10', 'version' => 'x', 'effective_from' => $today ) ) ) );

$tt = make_type( 20, 10, 1200 );
$tev = sr_event_of( $tt );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET tax_rate_bp=1000, tax_class='standard' WHERE ticket_type_id=$tt" );
check( 'event tax country is validated and stored', is_wp_error( TaxProfiles::set_event_country( $tev, 'Österreich' ) ) && true === TaxProfiles::set_event_country( $tev, 'AT' ) && 'AT' === TaxProfiles::event_country( $tev ) );
$to = sr_buy( $tt, 1, 'tax@example.com', 'tax1' );
$ti = sr_item( $to['order_id'] );
$snap = json_decode( (string) $ti->line_snapshot, true );
check( 'order line uses the active AT profile instead of the ticket type rate and stores the snapshot', 2000 === (int) $ti->tax_rate_bp && 200 === (int) $ti->tax_minor && $at1 === (int) $ti->tax_profile_id && 'at-2026' === ( $snap['tax']['version'] ?? '' ) && 'event_country' === ( $snap['tax']['place_of_supply'] ?? '' ) && 2000 === ( $snap['tax']['rate_bp'] ?? 0 ) );
$plain = make_type( 20, 10, 1100 );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET tax_rate_bp=1000 WHERE ticket_type_id=$plain" );
$po = sr_buy( $plain, 1, 'tax2@example.com', 'tax2' );
$pi = sr_item( $po['order_id'] );
check( 'without event country the seller country applies; no matching profile keeps the ticket type rate', 1000 === (int) $pi->tax_rate_bp && 0 === (int) $pi->tax_profile_id && null === $pi->line_snapshot );
$red = make_type( 20, 10, 1100 );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET tax_rate_bp=1900, tax_class='culture' WHERE ticket_type_id=$red" );
$de = TaxProfiles::create( array( 'country_code' => 'DE', 'tax_class' => 'culture', 'rate' => '7', 'version' => 'de-culture-1', 'effective_from' => '2020-01-01' ) );
TaxProfiles::activate( $de );
$ro = sr_buy( $red, 1, 'tax3@example.com', 'tax3' );
check( 'ticket type tax class selects the profile of the seller country (DE culture 7 %)', 700 === (int) sr_item( $ro['order_id'] )->tax_rate_bp && $de === (int) sr_item( $ro['order_id'] )->tax_profile_id && 'seller_country' === json_decode( sr_item( $ro['order_id'] )->line_snapshot, true )['tax']['place_of_supply'] );

// Rate change between order and refund: the refund and the credit note keep the original rate.
Orders::confirm_manual_payment( $to['order_id'] );
$inv = Invoices::issue_for_order( $to['order_id'] );
$inv_snapshot = json_decode( (string) $wpdb->get_var( "SELECT buyer_snapshot FROM {$p}wpcap_invoices WHERE invoice_id=" . (int) $inv['invoice_id'] ), true );
$inv_line = $wpdb->get_row( "SELECT * FROM {$p}wpcap_invoice_lines WHERE invoice_id=" . (int) $inv['invoice_id'] );
check( 'invoice line uses the snapshot rate and the invoice carries the place-of-supply note', 20.0 === (float) $inv_line->tax_rate && 200 === (int) $inv_line->tax_minor && in_array( 'Leistungsort: Veranstaltungsort (AT).', (array) ( $inv_snapshot['tax_notes'] ?? array() ), true ) && in_array( 'Leistungsort: Veranstaltungsort (AT).', Invoices::seller_notes( $inv_snapshot ), true ) );
TaxProfiles::archive( $at1 );
$at2 = TaxProfiles::create( array( 'country_code' => 'AT', 'tax_class' => 'standard', 'rate' => '13', 'version' => 'at-2026b', 'effective_from' => $today, 'place_of_supply_note' => 'Leistungsort Österreich (geprüfter Hinweis).' ) );
check( 'new version activates after the old one was archived', true === TaxProfiles::activate( $at2 ) );
$to2 = sr_buy( $tt, 1, 'tax4@example.com', 'tax4' );
check( 'new orders use the new rate (13 %)', 1300 === (int) sr_item( $to2['order_id'] )->tax_rate_bp && 138 === (int) sr_item( $to2['order_id'] )->tax_minor );
$tref = Refunds::request( $to['order_id'], array( (int) tickets( $to['order_id'] )[0]->ticket_id ), 'Steuertest', 'sr-tax-refund' );
$tcn = Invoices::credit_note_for_refund( $tref['refund_id'] );
check( 'refund after the rate change uses the original 20 % of the order line', ! is_wp_error( $tref ) && 'completed' === $tref['status'] && 200 === (int) $tref['tax_minor'] && 200 === (int) $tcn['tax_minor'] && 1000 === (int) $tcn['net_minor'] );
check( 'order line snapshot is unchanged after the rate change', 2000 === (int) sr_item( $to['order_id'] )->tax_rate_bp && $at1 === (int) sr_item( $to['order_id'] )->tax_profile_id );
check( 'operator note of the profile replaces the generic place-of-supply note', array( 'Leistungsort Österreich (geprüfter Hinweis).' ) === TaxProfiles::invoice_notes( array( sr_item( $to2['order_id'] ) ) ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
check( 'tax profile admin actions require manage_options', is_wp_error( TaxProfilesPage::admin_action( array( 'do' => 'activate', 'id' => $overlap ) ) ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'tax profile admin action saves drafts', is_array( TaxProfilesPage::admin_action( array( 'do' => 'save', 'country_code' => 'CH', 'tax_class' => 'standard', 'rate' => '8,1', 'version' => 'ch-1', 'effective_from' => '2024-01-01' ) ) ) && 810 === (int) $wpdb->get_var( "SELECT rate_basis_points FROM {$p}wpcap_tax_profiles WHERE country_code='CH'" ) );
check( 'tax classes for the ticket type select', in_array( 'culture', TaxProfiles::classes(), true ) && 'standard' === TaxProfiles::classes()[0] );
$wpdb->query( "UPDATE {$p}wpcap_tax_profiles SET status='archived'" );

/* =============================================================================================
 * Automatic price rules for all occurrences.
 * =========================================================================================== */
echo "Sales rules: automatic price rules\n";
$pa = make_type( 4, 10, 2500 );
$pocc = sr_occ_of( $pa );
$pev = sr_event_of( $pa );
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $pocc, 'name' => 'Ermäßigt', 'price_minor' => 1500, 'currency' => 'EUR', 'capacity' => 10, 'max_per_order' => 10, 'status' => 'active' ) );
$pb = (int) $wpdb->insert_id;
$occ = $wpdb->get_row( "SELECT * FROM {$p}wpcap_occurrences WHERE occurrence_id=$pocc" );
$type_a = $wpdb->get_row( "SELECT * FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$pa" );
$type_b = $wpdb->get_row( "SELECT * FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$pb" );
$until_local = ( new DateTimeImmutable( '@' . ( time() + 3600 ) ) )->setTimezone( new DateTimeZone( 'Europe/Vienna' ) )->format( 'Y-m-d\TH:i' );
$eb = PriceRules::create( $pev, array( 'kind' => 'early_bird', 'label' => 'Frühbucher', 'ticket_type_id' => $pa, 'price_mode' => 'fixed', 'amount' => '19,90', 'until' => $until_local ) );
check( 'event price rule is stored (scope: one ticket type)', is_int( $eb ) && 1990 === (int) $wpdb->get_var( "SELECT amount FROM {$p}wpcap_price_rules WHERE rule_id=$eb" ) );
check( 'rule scope: occurrence/type of another event is refused', is_wp_error( PriceRules::create( $pev, array( 'kind' => 'demand', 'sold_pct' => 10, 'price_mode' => 'percent', 'amount' => '10', 'ticket_type_id' => $tt ) ) ) );
$until_utc = $wpdb->get_var( "SELECT until_at FROM {$p}wpcap_price_rules WHERE rule_id=$eb" );
$before = gmdate( 'Y-m-d H:i:s', strtotime( $until_utc . ' UTC' ) - 1 );
check( 'early bird boundary: applies one second before until, not at until (UTC)', null !== PriceRules::rule_for( $type_a, array( 'occurrence' => $occ, 'now' => $before ) ) && null === PriceRules::rule_for( $type_a, array( 'occurrence' => $occ, 'now' => $until_utc ) ) );
check( 'rule limited to ticket type A does not touch type B', 1990 === Orders::unit_price( $type_a, $occ ) && 1500 === Orders::unit_price( $type_b, $occ ) );
check( 'buyer-visible label and explanation', 'Frühbucher' === Orders::price_label( $type_a, $occ ) && false !== strpos( PriceRules::explanation_for( $type_a, $occ ), 'gilt bei Buchung bis' ) && '' === PriceRules::explanation_for( $type_b, $occ ) );
$prd = sr_buy( $pa, 1, 'rule@example.com', 'rule1' );
$prd_snap = json_decode( (string) sr_item( $prd['order_id'] )->line_snapshot, true );
check( 'order line stores price snapshot with rule, explanation and prices', 1990 === (int) sr_item( $prd['order_id'] )->unit_price_minor && 'early_bird' === ( $prd_snap['price']['kind'] ?? '' ) && 2500 === ( $prd_snap['price']['base_minor'] ?? 0 ) && 1990 === ( $prd_snap['price']['unit_minor'] ?? 0 ) && $eb === ( $prd_snap['price']['rule_id'] ?? 0 ) && 'event' === ( $prd_snap['price']['source'] ?? '' ) && false !== strpos( $prd_snap['price']['explanation'] ?? '', 'Frühbucher' ) );
check( 'order without a price rule has no price snapshot', ! isset( json_decode( (string) sr_item( $po['order_id'] )->line_snapshot, true )['price'] ) );

$lm = PriceRules::create( $pev, array( 'kind' => 'last_minute', 'occurrence_id' => $pocc, 'type_name' => 'Ermäßigt', 'hours_before' => 24, 'price_mode' => 'percent', 'amount' => '-20' ) );
$start = strtotime( $occ->starts_at . ' UTC' );
check( 'last minute boundary: applies exactly 24 h before start (inclusive), not one second earlier', 1200 === Orders::unit_price( $type_b, $occ, 1, array( 'now' => gmdate( 'Y-m-d H:i:s', $start - 86400 ) ) ) && 1500 === Orders::unit_price( $type_b, $occ, 1, array( 'now' => gmdate( 'Y-m-d H:i:s', $start - 86401 ) ) ) );
// Weekday/time window on the occurrence's local start (Vienna): a Friday 19:00 local.
$fri = new DateTimeImmutable( 'next friday 19:00', new DateTimeZone( 'Europe/Vienna' ) );
$occ_fri = clone $occ;
$occ_fri->starts_at = $fri->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
$wk = PriceRules::create( $pev, array( 'kind' => 'weekday', 'ticket_type_id' => $pb, 'weekdays' => array( 5 ), 'time_from' => '19:00', 'time_to' => '23:00', 'price_mode' => 'fixed', 'amount' => '9,00', 'priority' => '5' ) );
$occ_late = clone $occ_fri;
$occ_late->starts_at = $fri->modify( '+4 hours' )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
$far = gmdate( 'Y-m-d H:i:s', $start - 10 * 86400 );
check( 'weekday/time window: from inclusive (Fri 19:00), to exclusive (23:00 no longer)', 900 === Orders::unit_price( $type_b, $occ_fri, 1, array( 'now' => $far ) ) && 1500 === Orders::unit_price( $type_b, $occ_late, 1, array( 'now' => $far ) ) );
$wrap = PriceRules::create( $pev, array( 'kind' => 'weekday', 'ticket_type_id' => $pa, 'time_from' => '22:00', 'time_to' => '02:00', 'price_mode' => 'fixed', 'amount' => '5,00', 'priority' => '99' ) );
$occ_night = clone $occ;
$occ_night->starts_at = ( new DateTimeImmutable( 'next saturday 01:30', new DateTimeZone( 'Europe/Vienna' ) ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
$occ_day = clone $occ;
$occ_day->starts_at = ( new DateTimeImmutable( 'next saturday 02:00', new DateTimeZone( 'Europe/Vienna' ) ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
check( 'time window over midnight (22:00–02:00) applies at 01:30, not at 02:00; higher priority wins over early bird', 500 === Orders::unit_price( $type_a, $occ_night, 1, array( 'now' => $before ) ) && 1990 === Orders::unit_price( $type_a, $occ_day, 1, array( 'now' => $before ) ) );
PriceRules::delete( $wrap );
PriceRules::delete( $wk );
// Demand step: 50 % of capacity 4 sold or held.
$dm = PriceRules::create( $pev, array( 'kind' => 'demand', 'ticket_type_id' => $pa, 'sold_pct' => 50, 'price_mode' => 'fixed', 'amount' => '30,00', 'priority' => '50' ) );
check( 'demand rule: 1 of 4 sold (25 %) keeps the early bird price', 1990 === Orders::unit_price( $type_a, $occ ) );
$prd2 = sr_buy( $pa, 1, 'rule2@example.com', 'rule2' );
check( 'demand rule: from 2 of 4 (50 %) the demand price applies (inclusive threshold)', 3000 === Orders::unit_price( $type_a, $occ ) && 1990 === (int) sr_item( $prd2['order_id'] )->unit_price_minor );
PriceRules::delete( $dm );
check( 'deleted rules no longer apply', 1990 === Orders::unit_price( $type_a, $occ ) && 'deleted' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_price_rules WHERE rule_id=$dm" ) );
// Event rules compete with series rules in one selection.
$ser = sp_series( array( 20 ), 50, 2500 );
$socc_id = $ser['occurrences'][0];
$stype = $wpdb->get_row( "SELECT * FROM {$p}wpcap_ticket_types WHERE ticket_type_id=" . (int) $ser['types'][ $socc_id ]['Standard'] );
$socc = $wpdb->get_row( "SELECT * FROM {$p}wpcap_occurrences WHERE occurrence_id=" . (int) $socc_id );
$far_local = ( new DateTimeImmutable( '@' . ( time() + 5 * 86400 ) ) )->setTimezone( new DateTimeZone( 'Europe/Vienna' ) )->format( 'Y-m-d\TH:i' );
PassCaptain\Series\Pricing::create( $ser['series_id'], array( 'kind' => 'early_bird', 'price_mode' => 'fixed', 'amount' => '20,00', 'until' => $far_local ) );
PriceRules::flush_cache();
check( 'series rule still applies through the general engine', 2000 === Orders::unit_price( $stype, $socc ) );
$evr = PriceRules::create( $ser['event_id'], array( 'kind' => 'early_bird', 'price_mode' => 'fixed', 'amount' => '18,00', 'until' => $far_local, 'priority' => '30' ) );
check( 'event rule with higher priority beats the series rule; snapshot source is recorded', 1800 === Orders::unit_price( $stype, $socc ) && 'event' === PriceRules::applied( $stype->ticket_type_id )['source'] );
PriceRules::delete( $evr );
check( 'after removing the event rule the series rule wins again (source series)', 2000 === Orders::unit_price( $stype, $socc ) && 'series' === PriceRules::applied( $stype->ticket_type_id )['source'] );
// Admin section actions.
$sa = AdminSections::action( array( 'do' => 'rule_save', 'event_id' => $pev, 'kind' => 'last_minute', 'hours_before' => 2, 'price_mode' => 'fixed', 'amount' => '10' ) );
check( 'admin section: rule_save creates a rule; rule_delete only for rules of the same event', is_array( $sa ) && ! empty( $sa['rule_id'] ) && is_wp_error( AdminSections::action( array( 'do' => 'rule_delete', 'event_id' => $tev, 'rule_id' => $sa['rule_id'] ) ) ) && is_array( AdminSections::action( array( 'do' => 'rule_delete', 'event_id' => $pev, 'rule_id' => $sa['rule_id'] ) ) ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_events' );
check( 'admin section actions require wpcap_manage_events', is_wp_error( AdminSections::action( array( 'do' => 'tax_country', 'event_id' => $pev, 'tax_country' => 'AT' ) ) ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
ob_start();
AdminSections::render( (object) array( 'event_id' => $pev, 'status' => 'published' ), true );
$html = ob_get_clean();
check( 'event screen section renders rules, buyer limits and tax country', false !== strpos( $html, 'Automatische Preisregeln' ) && false !== strpos( $html, 'Käuferlimits' ) && false !== strpos( $html, 'Steuerland' ) && false !== strpos( $html, 'gilt bei Buchung bis' ) );

/* =============================================================================================
 * Buyer limits.
 * =========================================================================================== */
echo "Sales rules: buyer limits across orders\n";
$bt = make_type( 50, 10, 1000 );
$bev = sr_event_of( $bt );
$limit = BuyerLimits::create( $bev, array( 'max_quantity' => 3, 'match_by' => 'email' ) );
check( 'limit validation (max >= 1, scope of the event)', is_int( $limit ) && is_wp_error( BuyerLimits::create( $bev, array( 'max_quantity' => 0 ) ) ) && is_wp_error( BuyerLimits::create( $bev, array( 'max_quantity' => 2, 'ticket_type_id' => $tt ) ) ) );
$b1 = sr_buy( $bt, 2, 'limit@example.com', 'bl1' );
$b2 = sr_buy( $bt, 2, 'LIMIT@example.com', 'bl2' );
check( 'pending order counts; second order over the limit is refused (case-insensitive e-mail) with remaining count', ! is_wp_error( $b1 ) && is_wp_error( $b2 ) && false !== strpos( $b2->get_error_message(), 'noch 1 Ticket' ) && 409 === $b2->get_error_data()['status'] );
$b3 = sr_buy( $bt, 1, 'limit@example.com', 'bl3' );
$b4 = sr_buy( $bt, 1, 'limit@example.com', 'bl4' );
check( 'up to the limit is allowed, beyond is refused', ! is_wp_error( $b3 ) && is_wp_error( $b4 ) && false !== strpos( $b4->get_error_message(), 'bereits erreicht' ) );
check( 'other buyers are not affected', ! is_wp_error( sr_buy( $bt, 3, 'other-limit@example.com', 'bl5' ) ) );
expire_holds_of( $b3['order_id'] );
check( 'expired holds no longer count', ! is_wp_error( sr_buy( $bt, 1, 'limit@example.com', 'bl6' ) ) );
Orders::confirm_manual_payment( $b1['order_id'] );
check( 'paid orders count (2 paid + 1 held = 3)', 3 === BuyerLimits::used( (object) array( 'event_id' => $bev, 'occurrence_id' => 0, 'ticket_type_id' => 0, 'match_by' => 'email' ), 'limit@example.com', 0, gmdate( 'Y-m-d H:i:s' ) ) );
check( 'door sales (box office) are not limited', ! is_wp_error( sr_buy( $bt, 2, 'limit@example.com', 'bl7', array(), array( 'skip_rate_limit' => true, 'allow_door_sales' => true, 'source' => 'pos' ) ) ) );
// Per ticket type and per logged-in customer.
$bt2 = make_type( 50, 10, 1000 );
$bocc2 = sr_occ_of( $bt2 );
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $bocc2, 'name' => 'VIP', 'price_minor' => 5000, 'currency' => 'EUR', 'capacity' => 50, 'max_per_order' => 10, 'status' => 'active' ) );
$vip = (int) $wpdb->insert_id;
BuyerLimits::create( sr_event_of( $bt2 ), array( 'ticket_type_id' => $vip, 'max_quantity' => 1, 'match_by' => 'customer' ) );
$GLOBALS['wpcap_test_user_id'] = 7711;
$c1 = sr_buy( $vip, 1, 'acc-a@example.com', 'bc1' );
$c2 = sr_buy( $vip, 1, 'acc-b@example.com', 'bc2' );
$c3 = sr_buy( $bt2, 4, 'acc-b@example.com', 'bc3' );
check( 'customer account limit applies across different e-mails, only for the limited ticket type', ! is_wp_error( $c1 ) && is_wp_error( $c2 ) && ! is_wp_error( $c3 ) && 7711 === (int) Orders::get( $c1['order_id'] )->customer_user_id );
$GLOBALS['wpcap_test_user_id'] = 7712;
check( 'another account may buy the limited ticket type', ! is_wp_error( sr_buy( $vip, 1, 'acc-b@example.com', 'bc4' ) ) );
$GLOBALS['wpcap_test_user_id'] = 1;
check( 'admin section: limit_save and limit_delete', is_array( $lr = AdminSections::action( array( 'do' => 'limit_save', 'event_id' => $bev, 'max_quantity' => 5, 'match_by' => 'both' ) ) ) && is_array( AdminSections::action( array( 'do' => 'limit_delete', 'event_id' => $bev, 'limit_id' => $lr['limit_id'] ) ) ) );
// Concurrency: 8 parallel checkouts of the same buyer against a limit of 3.
$cc = make_type( 100, 10, 1000 );
BuyerLimits::create( sr_event_of( $cc ), array( 'max_quantity' => 3, 'match_by' => 'email' ) );
$child = __DIR__ . '/sales/buyer_child.php';
$procs = array();
for ( $i = 0; $i < 8; $i++ ) {
	$procs[] = popen( 'WPCAP_TEST_PREFIX=' . escapeshellarg( $p ) . ' ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $child ) . ' ' . (int) $cc . ' ' . escapeshellarg( 0 === $i % 2 ? 'race@example.com' : 'Race@Example.com' ) . ' race' . $i . ' 2>&1', 'r' );
}
$ok = 0;
$limited = 0;
foreach ( $procs as $proc ) {
	$out = trim( stream_get_contents( $proc ) );
	pclose( $proc );
	$ok += 'OK' === $out ? 1 : 0;
	$limited += false !== strpos( $out, 'Pro Person' ) ? 1 : 0;
}
$held = (int) $wpdb->get_var( "SELECT COALESCE(SUM(quantity),0) FROM {$p}wpcap_reservations WHERE ticket_type_id=$cc AND status='active'" );
check( "exactly 3 of 8 concurrent orders of one buyer pass the limit (ok $ok, limited $limited, held $held)", 3 === $ok && 5 === $limited && 3 === $held );

/* =============================================================================================
 * Campaign stacking rules.
 * =========================================================================================== */
echo "Sales rules: campaign stacking\n";
$st = make_type( 50, 10, 2000 );
$sev = sr_event_of( $st );
$sc = uniqid();
Campaigns::create( array( 'code' => 'STA' . $sc, 'kind' => 'percent', 'value' => 1000, 'currency' => 'EUR' ) );
Campaigns::create( array( 'code' => 'STB' . $sc, 'kind' => 'fixed', 'value' => 100, 'currency' => 'EUR', 'combinable' => 0 ) );
Campaigns::create( array( 'code' => 'STC' . $sc, 'kind' => 'percent', 'value' => 5000, 'currency' => 'EUR', 'max_discount_minor' => 500 ) );
$s1 = sr_buy( $st, 1, 'stack@example.com', 'st1', array( 'campaign_codes' => array( 'STA' . $sc, 'STB' . $sc ) ) );
check( 'non-combinable code together with another code is refused with a clear message', is_wp_error( $s1 ) && false !== strpos( $s1->get_error_message(), 'nicht mit anderen Codes kombiniert' ) );
$s2 = sr_buy( $st, 1, 'stack@example.com', 'st2', array( 'campaign_codes' => array( 'STB' . $sc ) ) );
check( 'non-combinable code alone works', ! is_wp_error( $s2 ) && 100 === (int) $s2['discount_minor'] );
$s3 = sr_buy( $st, 2, 'stack@example.com', 'st3', array( 'campaign_codes' => array( 'STC' . $sc ) ) );
check( 'per-campaign maximum discount caps a percentage code (50 % of 40,00 → 5,00)', ! is_wp_error( $s3 ) && 500 === (int) $s3['discount_minor'] );
$s4 = sr_buy( $st, 1, 'stack@example.com', 'st4', array( 'campaign_codes' => array( 'STA' . $sc, 'STC' . $sc ) ) );
check( 'combinable codes stack in priority order on the remaining amount', ! is_wp_error( $s4 ) && 200 + 500 === (int) $s4['discount_minor'] );
// Codes vs automatic price rules.
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => sr_occ_of( $st ), 'name' => 'Regel', 'price_minor' => 2000, 'currency' => 'EUR', 'capacity' => 50, 'max_per_order' => 10, 'status' => 'active' ) );
$rt = (int) $wpdb->insert_id;
PriceRules::create( $sev, array( 'kind' => 'last_minute', 'ticket_type_id' => $rt, 'hours_before' => 1440, 'price_mode' => 'fixed', 'amount' => '16,00' ) );
Campaigns::create( array( 'code' => 'STN' . $sc, 'kind' => 'percent', 'value' => 1000, 'currency' => 'EUR', 'with_price_rules' => 0 ) );
$s5 = sr_buy( $rt, 1, 'stack@example.com', 'st5', array( 'campaign_codes' => array( 'STN' . $sc ) ) );
$s6 = Orders::create( array( 'email' => 'stack@example.com', 'items' => array( array( 'ticket_type_id' => $rt, 'quantity' => 1 ), array( 'ticket_type_id' => $st, 'quantity' => 1 ) ), 'campaign_codes' => array( 'STN' . $sc ), 'idempotency_key' => 'sr-st6-' . uniqid() ), array( 'skip_rate_limit' => true ) );
check( 'code excluded from price-rule tickets: refused alone, applies only to the regular line in a mixed order', is_wp_error( $s5 ) && ! is_wp_error( $s6 ) && 200 === (int) $s6['discount_minor'] && 3600 === (int) $s6['subtotal_minor'] );
Settings::update( array( 'max_total_discount_bp' => 2000 ) );
$s7 = sr_buy( $rt, 1, 'stack@example.com', 'st7', array( 'campaign_codes' => array( 'STA' . $sc ) ) );
check( 'global maximum: price rule already at 20 % leaves no room for a code (clear message)', is_wp_error( $s7 ) && false !== strpos( $s7->get_error_message(), 'höchstmögliche Rabatt' ) );
Settings::update( array( 'max_total_discount_bp' => 2500 ) );
$s8 = sr_buy( $rt, 1, 'stack@example.com', 'st8', array( 'campaign_codes' => array( 'STC' . $sc ) ) );
check( 'global maximum 25 %: the code is cut to the remaining 5 % (1,00 instead of 5,00)', ! is_wp_error( $s8 ) && 100 === (int) $s8['discount_minor'] && 1500 === (int) $s8['total_minor'] );
Settings::update( array( 'max_total_discount_bp' => 0 ) );
$parsed = CampaignsPage::parse( array( 'code' => 'XYZ123', 'kind' => 'percent', 'value' => '10', 'currency' => 'EUR', 'combinable' => '', 'with_price_rules' => '1', 'max_discount' => '12,50' ) );
check( 'campaign admin form parses stacking options', 0 === $parsed['combinable'] && 1 === $parsed['with_price_rules'] && 1250 === $parsed['max_discount_minor'] );
$upd = Campaigns::update( (int) $wpdb->get_var( $wpdb->prepare( "SELECT campaign_id FROM {$p}wpcap_campaigns WHERE code=%s", 'STA' . $sc ) ), array( 'combinable' => 0 ) );
check( 'campaign update stores stacking options', ! is_wp_error( $upd ) && '0' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT combinable FROM {$p}wpcap_campaigns WHERE code=%s", 'STA' . $sc ) ) );
check( 'global stacking setting via the campaign screen (administrators)', is_array( CampaignsPage::admin_action( array( 'do' => 'stacking', 'max_total_discount' => '30' ) ) ) && 3000 === (int) Settings::get( 'max_total_discount_bp' ) );
Settings::update( array( 'max_total_discount_bp' => 0 ) );

/* =============================================================================================
 * Logged consents for participant fields.
 * =========================================================================================== */
echo "Sales rules: logged participant consents\n";
$ct = make_type( 20, 10, 0 );
$cf = EventsPage::save_participant_field( array( 'ticket_type_id' => $ct, 'field_key' => 'photo', 'label' => 'Fotoeinwilligung', 'field_type' => 'consent', 'required' => '1', 'consent_text' => 'Ich bin mit Fotos einverstanden.', 'consent_version' => '1' ) );
check( 'consent field needs text and version', is_wp_error( EventsPage::save_participant_field( array( 'ticket_type_id' => $ct, 'field_key' => 'news', 'label' => 'News', 'field_type' => 'consent' ) ) ) && is_array( $cf ) );
$no = Orders::create( array( 'email' => 'consent@example.com', 'items' => array( array( 'ticket_type_id' => $ct, 'quantity' => 1 ) ), 'attendees' => array( array( 'ticket_type_id' => $ct, 'answers' => array( 'photo' => '' ) ) ), 'idempotency_key' => 'sr-c0-' . uniqid() ), array( 'skip_rate_limit' => true ) );
check( 'required consent must be given at checkout', is_wp_error( $no ) && 'wpcap_attendee_field_required' === $no->get_error_code() );
$co = Orders::create( array( 'email' => 'consent@example.com', 'items' => array( array( 'ticket_type_id' => $ct, 'quantity' => 1 ) ), 'attendees' => array( array( 'ticket_type_id' => $ct, 'name' => 'Ana', 'email' => 'ana@example.com', 'answers' => array( 'photo' => 'on' ) ) ), 'idempotency_key' => 'sr-c1-' . uniqid() ), array( 'skip_rate_limit' => true ) );
$cons = Consents::for_order( $co['order_id'] );
check( 'consent record with text, version, hash, source and time of the order', ! is_wp_error( $co ) && 1 === count( $cons ) && 'Ich bin mit Fotos einverstanden.' === $cons[0]->consent_text && '1' === $cons[0]->text_version && Consents::text_hash( 'Ich bin mit Fotos einverstanden.', '1' ) === $cons[0]->text_hash && 'checkout' === $cons[0]->source && 'ana@example.com' === $cons[0]->subject_email && Orders::get( $co['order_id'] )->created_at === $cons[0]->granted_at && null === $cons[0]->withdrawn_at );
check( 'consent answer is stored as "1"', '1' === $wpdb->get_var( "SELECT answer FROM {$p}wpcap_participant_answers WHERE field_id=" . (int) $cf['field_id'] ) );
check( 'changed consent text needs a new version once consents exist', is_wp_error( EventsPage::save_participant_field( array( 'field_id' => $cf['field_id'], 'label' => 'Fotoeinwilligung', 'field_type' => 'consent', 'consent_text' => 'Neuer Text', 'consent_version' => '1' ) ) ) && is_array( EventsPage::save_participant_field( array( 'field_id' => $cf['field_id'], 'label' => 'Fotoeinwilligung', 'field_type' => 'consent', 'required' => '1', 'consent_text' => 'Neuer Text', 'consent_version' => '2' ) ) ) && 'Ich bin mit Fotos einverstanden.' === Consents::for_order( $co['order_id'] )[0]->consent_text );
check( 'portal attendee form cannot change consents (filled for validation, removed before saving)', array( 'photo' => '1', 'x' => 'y' ) == Consents::portal_answers( $ct, array( 'x' => 'y' ) ) && array() === Consents::without_consent_answers( $ct, array( (int) $cf['field_id'] => '1' ) ) );
$other_order = Orders::get( $po['order_id'] );
check( 'withdrawal only for consents of the own order', is_wp_error( Consents::withdraw_for_order( $other_order, $cons[0]->consent_id ) ) );
$w = Consents::withdraw_for_order( Orders::get( $co['order_id'] ), $cons[0]->consent_id );
$after = Consents::get( $cons[0]->consent_id );
check( 'withdrawal keeps the record with time and source and deletes the answer', true === $w && null !== $after->withdrawn_at && 'portal' === $after->withdrawn_source && null === $wpdb->get_var( "SELECT answer FROM {$p}wpcap_participant_answers WHERE field_id=" . (int) $cf['field_id'] ) );
check( 'second withdrawal is refused', is_wp_error( Consents::withdraw( $cons[0]->consent_id ) ) );
$portal = Consents::portal_section( Orders::get( $co['order_id'] ) );
check( 'portal section lists the consent with its withdrawal state', false !== strpos( $portal, 'Fotoeinwilligung' ) && false !== strpos( $portal, 'Widerrufen am' ) && false === strpos( $portal, 'wpcap_consent_withdraw' ) );
$co2 = Orders::create( array( 'email' => 'consent2@example.com', 'items' => array( array( 'ticket_type_id' => $ct, 'quantity' => 1 ) ), 'attendees' => array( array( 'ticket_type_id' => $ct, 'answers' => array( 'photo' => '1' ) ) ), 'idempotency_key' => 'sr-c2-' . uniqid() ), array( 'skip_rate_limit' => true ) );
check( 'new consents use the new text version; portal shows a withdraw button', '2' === Consents::for_order( $co2['order_id'] )[0]->text_version && 'consent2@example.com' === Consents::for_order( $co2['order_id'] )[0]->subject_email && false !== strpos( Consents::portal_section( Orders::get( $co2['order_id'] ) ), 'wpcap_consent_withdraw' ) );
// The consent belongs to its subject (Ana), not to the buyer who ordered the ticket (PRIV-3).
$export = Consents::export( 'ana@example.com' );
$flat = wp_json_encode( $export );
check( 'privacy export contains consent text, version, grant and withdrawal', 1 === count( $export['data'] ) && false !== strpos( $flat, 'Ich bin mit Fotos einverstanden.' ) && false !== strpos( $flat, 'portal' ) && array() === Consents::export( 'consent@example.com' )['data'] );
$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
check( 'consent exporter and eraser are registered', isset( $exporters['wp-pass-captain-consents'] ) && isset( apply_filters( 'wp_privacy_personal_data_erasers', array() )['wp-pass-captain-consents'] ) );
$erase_buyer = Consents::erase( 'consent@example.com' );
$erase = Consents::erase( 'ana@example.com' );
check( 'privacy eraser deletes the consent records of the subject only', 0 === $erase_buyer['items_removed'] && 1 === $erase['items_removed'] && array() === Consents::for_order( $co['order_id'] ) );
// Retention: delete with the answers after end + retention days.
$wpdb->query( "UPDATE {$p}wpcap_participant_fields SET retention_days=10 WHERE field_id=" . (int) $cf['field_id'] );
check( 'retention keeps consents before end + retention days', 0 === Consents::retention( time() ) && 1 === count( Consents::for_order( $co2['order_id'] ) ) );
check( 'retention deletes consents after end + retention days', 1 <= Consents::retention( time() + 40 * DAY_IN_SECONDS ) && array() === Consents::for_order( $co2['order_id'] ) );

/* =============================================================================================
 * Reports: comparison periods, campaign breakdown, role-scoped CSV.
 * =========================================================================================== */
echo "Sales rules: report comparison and campaigns\n";
$ra = make_type( 50, 10, 1000 );
$rev = sr_event_of( $ra );
$wpdb->query( "UPDATE {$p}wpcap_events SET name='Vergleichsgala' WHERE event_id=$rev" );
$rb = make_type( 50, 10, 1000 );
$rev_b = sr_event_of( $rb );
$wpdb->query( "UPDATE {$p}wpcap_events SET name='Fremdevent' WHERE event_id=$rev_b" );
$rc = strtoupper( uniqid() );
Campaigns::create( array( 'code' => 'REP' . $rc, 'name' => 'Herbst', 'kind' => 'percent', 'value' => 1000, 'currency' => 'EUR' ) );
Campaigns::create( array( 'code' => 'HID' . $rc, 'kind' => 'fixed', 'value' => 100, 'currency' => 'EUR' ) );
$r1 = sr_buy( $ra, 2, 'rep@example.com', 'r1', array( 'campaign_codes' => array( 'REP' . $rc ) ) );
$r2 = sr_buy( $ra, 1, 'rep2@example.com', 'r2' );
$r3 = sr_buy( $rb, 1, 'rep3@example.com', 'r3', array( 'campaign_codes' => array( 'HID' . $rc ) ) );
$prev = sr_buy( $ra, 3, 'rep4@example.com', 'r4' );
$ly = sr_buy( $ra, 4, 'rep5@example.com', 'r5' );
foreach ( array( $r1, $r2, $r3, $prev, $ly ) as $o ) { Orders::confirm_manual_payment( $o['order_id'] ); }
$wpdb->query( "UPDATE {$p}wpcap_orders SET created_at='" . gmdate( 'Y-m-d 12:00:00', time() - 7 * DAY_IN_SECONDS ) . "' WHERE order_id=" . (int) $prev['order_id'] );
$wpdb->query( "UPDATE {$p}wpcap_orders SET created_at='" . Data::comparison_range( $today, $today, 'last_year' )[0] . " 12:00:00' WHERE order_id=" . (int) $ly['order_id'] );
check( 'comparison ranges: previous period of equal length, same dates last year (29 Feb → 28 Feb)', array( '2026-10-25', '2026-10-31' ) === Data::comparison_range( '2026-11-01', '2026-11-07', 'previous' ) && array( '2023-02-28', '2023-03-01' ) === Data::comparison_range( '2024-02-29', '2024-03-01', 'last_year' ) && null === Data::comparison_range( '2026-11-01', '2026-11-07', '' ) );
$week_from = gmdate( 'Y-m-d', time() - 6 * DAY_IN_SECONDS );
$rp = Data::build( array( 'from' => $week_from, 'to' => $today, 'event_id' => $rev, 'compare' => 'previous' ), null );
check( 'previous period: current 3 tickets (28,00), previous week 3 tickets (30,00), change in % ', 3 === $rp['total']['tickets'] && 2800 === $rp['total']['gross'] && 3 === $rp['comparison']['total']['tickets'] && 3000 === $rp['comparison']['total']['gross'] && -200 === $rp['comparison']['delta']['gross']['diff'] && -6.7 === $rp['comparison']['delta']['gross']['pct'] );
$rl = Data::build( array( 'from' => $today, 'to' => $today, 'event_id' => $rev, 'compare' => 'last_year' ), null );
check( 'same period last year', 4 === $rl['comparison']['total']['tickets'] && 4000 === $rl['comparison']['total']['revenue'] && Data::comparison_range( $today, $today, 'last_year' )[0] === $rl['comparison']['from'] );
$camp_rows = array_values( array_filter( $rl['campaigns'], static fn( $c ) => 0 === strpos( $c['label'], 'REP' ) ) );
check( 'campaign breakdown: orders, tickets, discount and revenue per code plus orders without code', 1 === count( $camp_rows ) && 1 === $camp_rows[0]['orders'] && 2 === $camp_rows[0]['tickets'] && 200 === $camp_rows[0]['discount'] && 1800 === $camp_rows[0]['revenue'] && false !== strpos( $camp_rows[0]['label'], 'Herbst' ) && 1 === $rl['campaigns'][0]['orders'] && 1000 === $rl['campaigns'][0]['revenue'] );
$csv_all = ReportsPage::csv_for_current_user( array( 'from' => $today, 'to' => $today, 'compare' => 'last_year' ) );
check( 'CSV for finance/admins contains all events, campaigns and the comparison section', false !== strpos( $csv_all['csv'], 'Fremdevent' ) && false !== strpos( $csv_all['csv'], 'HID' . $rc ) && false !== strpos( $csv_all['csv'], 'Vorjahreszeitraum' ) && false !== strpos( $csv_all['csv'], 'Kampagne' ) );
// Organizer with report access to one event only.
$GLOBALS['wpcap_test_user_id'] = 8801;
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options', 'wpcap_view_finance' );
$wpdb->insert( "{$p}wpcap_event_staff", array( 'event_id' => $rev, 'user_id' => 8801, 'capability' => 'wpcap_view_reports' ) );
$csv_scoped = ReportsPage::csv_for_current_user( array( 'from' => $today, 'to' => $today, 'compare' => 'last_year' ) );
check( 'CSV for a scoped organizer only contains assigned events and their campaigns (also in the comparison)', ! is_wp_error( $csv_scoped ) && false !== strpos( $csv_scoped['csv'], 'Vergleichsgala' ) && false === strpos( $csv_scoped['csv'], 'Fremdevent' ) && false === strpos( $csv_scoped['csv'], 'HID' . $rc ) && false !== strpos( $csv_scoped['csv'], 'REP' . $rc ) && 3 === $csv_scoped['report']['total']['tickets'] && 4 === $csv_scoped['report']['comparison']['total']['tickets'] );
$csv_other = ReportsPage::csv_for_current_user( array( 'from' => $today, 'to' => $today, 'event_id' => $rev_b ) );
check( 'event filter outside the scope yields an empty CSV report', 0 === $csv_other['report']['total']['tickets'] && array() === $csv_other['report']['campaigns'] );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options', 'wpcap_view_finance', 'wpcap_view_reports' );
check( 'CSV export without report capability is refused', is_wp_error( ReportsPage::csv_for_current_user( array() ) ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 1;

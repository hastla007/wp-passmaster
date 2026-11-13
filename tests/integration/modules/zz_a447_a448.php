<?php
/**
 * A447: saving an organizer never drops event links the form did not show (explicit add/remove
 * diff, searchable/paginated event picker, no N+1), event selects keep stored values and report a
 * cut list. A448: moving an event between organizers never pays a sale out twice (global source
 * keys, assignment history with validity period, corrections to the organizer of the sale,
 * migration of existing data).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\Queries;
use PassCaptain\Partners\AdminPage as PartnerAdmin;
use PassCaptain\Partners\Repository as Partners;
use PassCaptain\Partners\Settlements;

global $wpdb;
$p = $wpdb->prefix;
PassCaptain\Payments\Registry::reset();
PassCaptain\Settings::update( array( 'refund_approval_threshold' => 0 ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 1;
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); } }
if ( ! function_exists( 'checked' ) ) { function checked( $a, $b = true, $echo = true ) { $r = $a == $b ? ' checked="checked"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'selected' ) ) { function selected( $a, $b = true, $echo = true ) { $r = $a == $b ? ' selected="selected"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'disabled' ) ) { function disabled( $a, $b = true, $echo = true ) { $r = $a == $b ? ' disabled="disabled"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce" value="n">'; } }
if ( ! function_exists( 'wp_nonce_url' ) ) { function wp_nonce_url( $u, $a = -1 ) { return $u . '&_wpnonce=n'; } }
if ( ! function_exists( 'submit_button' ) ) { function submit_button( $t = '', $type = 'primary', $name = 'submit', $wrap = true ) { echo '<button>' . esc_html( $t ) . '</button>'; } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = null ) { return esc_attr( $t ); } }
if ( ! function_exists( 'wp_die' ) ) { function wp_die( $m = '' ) { throw new RuntimeException( (string) $m ); } }

function a448_event_of_type( $type ) {
	global $wpdb;
	return (int) $wpdb->get_var( "SELECT o.event_id FROM {$wpdb->prefix}wpcap_ticket_types tt INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=" . (int) $type );
}
function a448_order( $type, $qty, $key, $created_at, $pay = true ) {
	global $wpdb;
	$r = PassCaptain\Orders::create( array( 'email' => "$key@example.com", 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => $key . '-' . uniqid() ) );
	if ( $pay ) { PassCaptain\Orders::confirm_manual_payment( $r['order_id'] ); }
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_orders SET created_at=%s WHERE order_id=%d", $created_at, $r['order_id'] ) );
	return (int) $r['order_id'];
}
function a448_refund( $order_id, $completed_at ) {
	global $wpdb;
	$ticket = (int) tickets( $order_id, 'valid' )[0]->ticket_id;
	$r = PassCaptain\Refunds::request( $order_id, array( $ticket ), 'Test', 'a448-ref-' . uniqid() );
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_refunds SET updated_at=%s WHERE refund_id=%d", $completed_at, $r['refund_id'] ) );
	return (int) $r['refund_id'];
}
function a448_keys( array $lines, $order_id ) {
	return array_values( array_map( static fn( $l ) => $l['kind'], array_filter( $lines, static fn( $l ) => (int) $l['order_id'] === (int) $order_id ) ) );
}
$a447_render = static function ( $cb, array $get = array() ) { $_GET = $get; ob_start(); try { call_user_func( $cb ); } catch ( Throwable $e ) { ob_end_clean(); $_GET = array(); return 'DIED:' . $e->getMessage(); } $_GET = array(); return (string) ob_get_clean(); };

echo "A447: saving an organizer keeps event links it did not show\n";
$a447_events = array();
foreach ( array( 'A447 Alpha', 'A447 Beta', 'A447 Gamma', 'A447 Delta' ) as $name ) {
	$a447_events[] = $e = a448_event_of_type( make_type( 10, 10, 1000 ) );
	$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_events SET name=%s WHERE event_id=%d", $name, $e ) );
}
list( $e1, $e2, $e3, $e4 ) = $a447_events;
$pp = PartnerAdmin::admin_action( array( 'do' => 'save_partner', 'name' => 'A447 Veranstalter', 'currency' => 'EUR', 'commission' => '10', 'fee_per_ticket' => '0', 'fee_tax_rate' => '19', 'event_ids' => array( $e1, $e2, $e3 ) ) )['partner_id'] ?? 0;
check( 'A447: new partner gets the events of the form', array( $e1, $e2, $e3 ) === Partners::event_ids( $pp ) );
$resave = PartnerAdmin::admin_action( array( 'do' => 'save_partner', 'id' => $pp, 'name' => 'A447 Veranstalter', 'currency' => 'EUR', 'commission' => '10', 'fee_per_ticket' => '0', 'fee_tax_rate' => '19' ) );
check( 'A447: saving without event fields keeps every link (no delete-all/re-insert)', ! is_wp_error( $resave ) && array( $e1, $e2, $e3 ) === Partners::event_ids( $pp ) );
$diff = PartnerAdmin::admin_action( array( 'do' => 'save_partner', 'id' => $pp, 'name' => 'A447 Veranstalter', 'currency' => 'EUR', 'commission' => '10', 'fee_per_ticket' => '0', 'fee_tax_rate' => '19', 'remove_event_ids' => array( $e1 ), 'add_event_ids_text' => (string) $e4 ) );
check( 'A447: form submits an explicit diff (remove one, add one by ID)', ! is_wp_error( $diff ) && array( $e2, $e3, $e4 ) === Partners::event_ids( $pp ) );
$other = Partners::save( array( 'name' => 'A447 Anderer', 'currency' => 'EUR' ) );
$foreign = Partners::update_events( $other, array( $e2 ), array( $e3 ) );
check( 'A447: events of another organizer are skipped, foreign links cannot be removed', array( $e2 ) === $foreign['skipped'] && array() === $foreign['removed'] && array( $e2, $e3, $e4 ) === Partners::event_ids( $pp ) );

// More events than any select shows: the link to an event beyond the first 500 must survive.
$now = gmdate( 'Y-m-d H:i:s' );
$values = array();
for ( $i = 0; $i < 510; $i++ ) { $values[] = $wpdb->prepare( '(%s,%s,%s,%s,%s,%s)', sprintf( '000 A447 Masse %03d', $i ), 'a447-bulk-' . $i . '-' . uniqid(), '', 'published', $now, $now ); }
$wpdb->query( "INSERT INTO {$p}wpcap_events (name,slug,description,status,created_at,updated_at) VALUES " . implode( ',', $values ) );
$bulk_ids = array_map( 'intval', $wpdb->get_col( "SELECT event_id FROM {$p}wpcap_events WHERE slug LIKE 'a447-bulk-%'" ) );
$ezz = a448_event_of_type( make_type( 10, 10, 1000 ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_events SET name=%s WHERE event_id=%d", 'zzz A447 Letztes Event', $ezz ) );
Partners::update_events( $pp, array( $ezz ) );
$options = Queries::event_options( null );
check( 'A447: event selects are capped and report the cut list', ! isset( $options[ $ezz ] ) && Queries::event_options_truncated() && false !== strpos( Queries::event_options_note(), '500' ) );
$options = Queries::event_options( null, array( $ezz ) );
check( 'A447: a selected/stored event beyond the cap is always in the select', isset( $options[ $ezz ] ) && 'zzz A447 Letztes Event' === $options[ $ezz ] );
check( 'A447: a complete list has no note', array() !== Queries::event_options( array( $e1, $e2 ) ) && ! Queries::event_options_truncated() && '' === Queries::event_options_note() );
$before_q = $wpdb->num_queries;
$form = $a447_render( array( PartnerAdmin::class, 'render_partners' ), array( 'partner_id' => (string) $pp ) );
$form_queries = $wpdb->num_queries - $before_q;
check( 'A447: partner form lists the linked event beyond the cap with a remove box, no add box for it', false !== strpos( $form, 'name="remove_event_ids[]" value="' . $ezz . '"' ) && false === strpos( $form, 'name="add_event_ids[]" value="' . $ezz . '"' ) && false === strpos( strstr( $form, 'id="wpcap-pa-event-search"', true ), 'name="event_ids[]"' ) );
check( 'A447: the event picker is a search with a hint when results are cut', false !== strpos( $form, 'form="wpcap-pa-event-search"' ) && false !== strpos( $form, 'id="wpcap-pa-event-search"' ) && false !== strpos( $form, 'wpcap-truncated' ) );
check( 'A447: no query per event on the partner form (N+1 removed)', $form_queries < 60 );
$search = $a447_render( array( PartnerAdmin::class, 'render_partners' ), array( 'partner_id' => (string) $pp, 'event_search' => 'A447 Masse 50' ) );
check( 'A447: search finds events to add by name', false !== strpos( $search, '000 A447 Masse 500' ) && false !== strpos( $search, 'name="add_event_ids[]"' ) && false === strpos( $search, 'A447 Masse 499' ) );
$found = Partners::search_events( (string) $e2 );
check( 'A447: search by event ID shows the current owner', in_array( $e2, array_map( 'intval', array_column( $found['rows'], 'event_id' ) ), true ) && $pp === (int) array_values( array_filter( $found['rows'], static fn( $r ) => (int) $r->event_id === $e2 ) )[0]->owner_id );
PartnerAdmin::admin_action( array( 'do' => 'save_partner', 'id' => $pp, 'name' => 'A447 Veranstalter', 'currency' => 'EUR', 'commission' => '10', 'fee_per_ticket' => '0', 'fee_tax_rate' => '19', 'add_event_ids' => array( $bulk_ids[0] ) ) );
check( 'A447: saving the form keeps the event beyond the cap linked', in_array( $ezz, Partners::event_ids( $pp ), true ) && in_array( $bulk_ids[0], Partners::event_ids( $pp ), true ) );
$list = $a447_render( array( PartnerAdmin::class, 'render_partners' ) );
check( 'A447: partner list shows event counts from one grouped query', 5 === ( Partners::event_counts()[ $pp ] ?? 0 ) && false !== strpos( $list, 'A447 Veranstalter' ) );
$waitlist = $a447_render( array( PassCaptain\Waitlists\AdminPage::class, 'render' ), array( 'event_id' => (string) $ezz ) );
check( 'A447: other event selects keep the requested event beyond the cap and show the note', false !== strpos( $waitlist, 'value="' . $ezz . '" selected' ) && false !== strpos( $waitlist, 'wpcap-truncated' ) );
Partners::update_events( $pp, array(), array( $bulk_ids[0] ) );
$wpdb->query( "DELETE FROM {$p}wpcap_events WHERE event_id IN (" . implode( ',', $bulk_ids ) . ')' );
$wpdb->query( "DELETE FROM {$p}wpcap_partner_event_history WHERE event_id IN (" . implode( ',', $bulk_ids ) . ')' );

echo "A448: moving an event to another organizer never pays a sale twice\n";
$m1 = gmdate( 'Y-m', strtotime( 'first day of -2 month' ) );
$m2 = gmdate( 'Y-m', strtotime( 'first day of -1 month' ) );
$type = make_type( 50, 10, 2000 );
$ev = a448_event_of_type( $type );
$pa = Partners::save( array( 'name' => 'A448 A GmbH', 'currency' => 'EUR', 'commission_bp' => 1000, 'billing_address' => "Str 1\n1010 Wien", 'vat_id' => 'ATU12345678', 'sales_model' => 'agent', 'settlement_start' => $m1 . '-01' ) );
$pb = Partners::save( array( 'name' => 'A448 B GmbH', 'currency' => 'EUR', 'commission_bp' => 1000, 'billing_address' => "Str 2\n1010 Wien", 'vat_id' => 'ATU87654321', 'sales_model' => 'agent', 'settlement_start' => $m1 . '-01' ) );
Partners::set_events( $pa, array( $ev ) );
$o1 = a448_order( $type, 2, 'a448-o1', $m1 . '-15 12:00:00' );
$o3 = a448_order( $type, 1, 'a448-o3', $m1 . '-20 12:00:00', false ); // Paid only after A's statement.
$sa = Settlements::create_draft( $pa, $m1 );
$fa = Settlements::finalize( $sa );
check( 'A448: A settles the first month', ! is_wp_error( $fa ) && 4000 === (int) Settlements::get( $sa )->gross_minor );
// The event moves to B; the move takes effect at the start of the next month.
Partners::update_events( $pa, array(), array( $ev ) );
Partners::update_events( $pb, array( $ev ) );
$moved_at = gmdate( 'Y-m-d H:i:s', strtotime( $m2 . '-01 00:00:00 UTC' ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_partner_event_history SET valid_to=%s WHERE event_id=%d AND partner_id=%d", $moved_at, $ev, $pa ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_partner_event_history SET valid_from=%s WHERE event_id=%d AND partner_id=%d", $moved_at, $ev, $pb ) );
$hist = Partners::assignments( array( $ev ) )[ $ev ] ?? array();
check( 'A448: assignment history keeps both organizers with validity periods', 2 === count( $hist ) && $pa === $hist[0]['partner_id'] && '' === $hist[0]['from'] && $moved_at === $hist[0]['to'] && $pb === $hist[1]['partner_id'] && $moved_at === $hist[1]['from'] && '' === $hist[1]['to'] );
check( 'A448: partner_at resolves the organizer at sale time', $pa === Partners::partner_at( $hist, $m1 . '-15 12:00:00' ) && $pb === Partners::partner_at( $hist, $m2 . '-02 00:00:00' ) && $pb === Partners::partner_at( $hist, $moved_at ) );
$o2 = a448_order( $type, 1, 'a448-o2', $m2 . '-10 12:00:00' );
PassCaptain\Orders::confirm_manual_payment( $o3 );
$r1 = a448_refund( $o1, $m2 . '-12 10:00:00' );
$calc_b = Settlements::compute( Partners::get( $pb ), $m2 );
check( 'A448: B does not settle A\'s already settled sales again (proof t2)', array() === a448_keys( $calc_b['lines'], $o1 ) );
check( 'A448: B gets only the sale made while it was assigned', array( 'sale' ) === a448_keys( $calc_b['lines'], $o2 ) && array() === a448_keys( $calc_b['lines'], $o3 ) && 2000 === $calc_b['totals']['gross'] );
$calc_a = Settlements::compute( Partners::get( $pa ), $m2 );
check( 'A448: the correction of a settled sale and a late payment from A\'s time stay with A', array( 'correction' ) === a448_keys( $calc_a['lines'], $o1 ) && array( 'late_sale' ) === a448_keys( $calc_a['lines'], $o3 ) && array() === a448_keys( $calc_a['lines'], $o2 ) && 0 === $calc_a['totals']['gross'] );
$sb = Settlements::create_draft( $pb, $m2 );
$fb = Settlements::finalize( $sb );
$sa2 = Settlements::create_draft( $pa, $m2 );
$fa2 = Settlements::finalize( $sa2 );
check( 'A448: both organizers finalize without overlap', ! is_wp_error( $fb ) && ! is_wp_error( $fa2 ) && 2000 === (int) Settlements::get( $sb )->gross_minor && 0 === (int) Settlements::get( $sa2 )->gross_minor );
$sale_key = (string) $wpdb->get_var( $wpdb->prepare( "SELECT source_key FROM {$p}wpcap_partner_settlement_lines WHERE settlement_id=%d AND kind='sale' LIMIT 1", $sa ) );
$dup = $wpdb->insert( "{$p}wpcap_partner_settlement_lines", array( 'settlement_id' => $sb, 'partner_id' => $pb, 'kind' => 'sale', 'item_key' => $sale_key, 'source_key' => $sale_key, 'occurred_at' => $now ) );
check( 'A448: the database rejects an item settled by another partner (global source key)', '' !== $sale_key && false === $dup );
check( 'A448: voucher keys name the partner, other keys are global', 'voucher:7:3' === Settlements::source_key( 'voucher', 'voucher:7', 3 ) && 'voucher_refund:9:3' === Settlements::source_key( 'voucher_refund', 'voucher_refund:9', 3 ) && 'sale:5' === Settlements::source_key( 'sale', 'sale:5', 3 ) );
$form_a = $a447_render( array( PartnerAdmin::class, 'render_partners' ), array( 'partner_id' => (string) $pa ) );
check( 'A448: the partner screen shows the assignment history', false !== strpos( $form_a, 'Zuordnungsverlauf' ) && false !== strpos( $form_a, '#' . $ev ) );

echo "A448: links without history (before the update) and the migration\n";
// An event moved before this update: no history, A settled its sale, the link was changed directly.
$type2 = make_type( 50, 10, 1500 );
$ev2 = a448_event_of_type( $type2 );
$wpdb->insert( "{$p}wpcap_partner_events", array( 'event_id' => $ev2, 'partner_id' => $pa ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_partner_event_history WHERE event_id=%d", $ev2 ) );
$o4 = a448_order( $type2, 1, 'a448-o4', $m2 . '-03 12:00:00' );
$calc_a_legacy = Settlements::compute( Partners::get( $pa ), $m2 );
check( 'A448: a link without history counts since always (fallback)', array( 'sale' ) === a448_keys( $calc_a_legacy['lines'], $o4 ) );
// Pretend A finalized this sale before the move (lock its line directly).
$item4 = (int) $wpdb->get_var( $wpdb->prepare( "SELECT item_id FROM {$p}wpcap_order_items WHERE order_id=%d", $o4 ) );
$wpdb->insert( "{$p}wpcap_partner_settlement_lines", array( 'settlement_id' => $sa2, 'partner_id' => $pa, 'kind' => 'sale', 'item_key' => 'sale:' . $item4, 'source_key' => 'sale:' . $item4, 'order_id' => $o4, 'item_id' => $item4, 'event_id' => $ev2, 'occurred_at' => $m2 . '-03 12:00:00' ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_partner_events SET partner_id=%d WHERE event_id=%d", $pb, $ev2 ) );
a448_refund( $o4, $m2 . '-20 10:00:00' );
$calc_b_legacy = Settlements::compute( Partners::get( $pb ), $m2 );
$calc_a_legacy = Settlements::compute( Partners::get( $pa ), $m2 );
check( 'A448: without history the new organizer neither re-settles the sale nor gets its refund', array() === a448_keys( $calc_b_legacy['lines'], $o4 ) );
check( 'A448: the refund of a sale A settled goes to A (settled-by rule)', array( 'refund' ) === a448_keys( $calc_a_legacy['lines'], $o4 ) && in_array( $ev2, Partners::settlement_event_ids( $pa ), true ) );

// Migration of an installation from before 3.10.8: old per-partner index, legacy voucher keys,
// an item settled twice, links without history.
$lines_t = "{$p}wpcap_partner_settlement_lines";
$wpdb->query( "ALTER TABLE {$lines_t} DROP INDEX source_key, ADD UNIQUE KEY partner_source (partner_id,source_key)" );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_partner_event_history WHERE event_id=%d", $ev ) );
$mig = array( 'settlement_id' => 0, 'order_id' => 0, 'occurred_at' => $now );
$wpdb->insert( $lines_t, $mig + array( 'partner_id' => 990001, 'kind' => 'voucher', 'item_key' => 'voucher:990001', 'source_key' => 'voucher:990001' ) );
$wpdb->insert( $lines_t, $mig + array( 'partner_id' => 990002, 'kind' => 'voucher', 'item_key' => 'voucher:990001', 'source_key' => 'voucher:990001' ) );
$wpdb->insert( $lines_t, $mig + array( 'partner_id' => 990001, 'kind' => 'sale', 'item_key' => 'sale:990009', 'source_key' => 'sale:990009' ) );
$first_dup = (int) $wpdb->insert_id;
$wpdb->insert( $lines_t, $mig + array( 'partner_id' => 990002, 'kind' => 'sale', 'item_key' => 'sale:990009', 'source_key' => 'sale:990009' ) );
$second_dup = (int) $wpdb->insert_id;
$migrated = PassCaptain\Modules\Partners::migrate();
$idx = array();
foreach ( (array) $wpdb->get_results( "SHOW INDEX FROM {$lines_t}" ) as $row ) { $row = (array) $row; $idx[ $row['Key_name'] ][] = $row['Column_name']; }
check( 'A448 migration: unique index on source_key replaces the per-partner index', $migrated && array( 'source_key' ) === ( $idx['source_key'] ?? null ) && ! isset( $idx['partner_source'] ) );
check( 'A448 migration: legacy voucher keys get the partner', 2 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$lines_t} WHERE source_key IN ('voucher:990001:990001','voucher:990001:990002')" ) );
check( 'A448 migration: an item settled twice keeps the first line, the second is marked and audited', 'sale:990009' === $wpdb->get_var( "SELECT source_key FROM {$lines_t} WHERE line_id=$first_dup" ) && 'dup:' . $second_dup === $wpdb->get_var( "SELECT source_key FROM {$lines_t} WHERE line_id=$second_dup" ) && 0 < (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='partner_settlement_duplicates'" ) );
check( 'A448 migration: current links without history get a row since always', 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_partner_event_history WHERE event_id=%d AND partner_id=%d AND valid_from IS NULL AND valid_to IS NULL", $ev, $pb ) ) );
check( 'A448 migration: idempotent', PassCaptain\Modules\Partners::migrate() && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_partner_event_history WHERE event_id=%d", $ev ) ) && 1 === (int) PassCaptain\Modules\Partners::MIGRATION_VERSION && 1 === (int) get_option( 'wpcap_partners_migration' ) );
$wpdb->query( "DELETE FROM {$lines_t} WHERE partner_id IN (990001,990002)" );

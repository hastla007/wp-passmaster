<?php
/**
 * Audit panel 2026-11-15, package Y4 (privacy): regression tests for PRIV-1…4, 6…8, 10…13, 15, 17
 * and the verified suspicions V1…V3. Included from run.php after all module tests (uses check(),
 * make_type()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Privacy;
use PassCaptain\Privacy\PolicyText;
use PassCaptain\Privacy\Retention;
use PassCaptain\RateLimit;
use PassCaptain\Sales\Consents;
use PassCaptain\Security\Redact;
use PassCaptain\Settings;
use PassCaptain\WalletPasses\GoogleProvider;
use PassCaptain\WalletPasses\Service as Y4WalletService;
use PassCaptain\WalletPasses\Store as Y4WalletStore;
use PassCaptain\Integrations\WooCommerce\Attendees as Y4WooAttendees;
use PassCaptain\Integrations\WooCommerce\OrderAdapter as Y4Woo;

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 1;
unset( $GLOBALS['wpcap_test_user_can'] );
wpcap_test_reset_transients();
unset( $GLOBALS['wpcap_test_filters']['wpcap_privacy_defer_upcoming'] );
Settings::update( array( 'require_terms' => 0, 'terms_text' => '', 'attendee_retention_days' => 0, 'retention_order_years' => 10, 'retention_waitlist_days' => 30, 'retention_pass_days' => 365 ) );
Consents::register();

/** Occurrence of a ticket type. */
function y4_occ( $type ) {
	global $wpdb;
	return (int) $wpdb->get_var( "SELECT occurrence_id FROM {$wpdb->prefix}wpcap_ticket_types WHERE ticket_type_id=" . (int) $type );
}
function y4_event( $type ) {
	global $wpdb;
	return (int) $wpdb->get_var( "SELECT event_id FROM {$wpdb->prefix}wpcap_occurrences WHERE occurrence_id=" . y4_occ( $type ) );
}
/** Moves the occurrence of $type so it ended $days_ago days ago (negative = future). */
function y4_move( $type, $days_ago ) {
	global $wpdb;
	$end = time() - (int) ( $days_ago * 86400 );
	$wpdb->update( "{$wpdb->prefix}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', $end - 7200 ), 'ends_at' => gmdate( 'Y-m-d H:i:s', $end ) ), array( 'occurrence_id' => y4_occ( $type ) ) );
}
/** Free order; attendees: list of array( name, email, answers ). */
function y4_order( $type, $email, array $attendees ) {
	$list = array();
	foreach ( $attendees as $a ) { $list[] = array( 'ticket_type_id' => $type, 'name' => $a[0], 'email' => $a[1], 'answers' => $a[2] ?? array() ); }
	wpcap_test_reset_transients();
	return Orders::create( array( 'email' => $email, 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => count( $attendees ) ) ), 'idempotency_key' => 'y4-' . uniqid( '', true ), 'attendees' => $list ), array( 'skip_rate_limit' => true ) );
}
function y4_tickets( $order_id ) { return array_map( static fn( $t ) => (int) $t->ticket_id, Orders::tickets_for_order( $order_id ) ); }
function y4_flat( array $export ) {
	$out = array();
	foreach ( $export['data'] as $item ) { foreach ( $item['data'] as $row ) { $out[] = $row['name'] . '=' . $row['value']; } }
	return implode( "\n", $out );
}
function y4_ago( $days ) { return gmdate( 'Y-m-d H:i:s', time() - (int) ( $days * 86400 ) ); }

/* ================================================================== PRIV-2 */
echo "Y4 privacy: PRIV-2 billing of unpaid orders\n";
$y4t = make_type( 20, 10, 2500 );
$y4u = Orders::create( array( 'email' => 'y4-unpaid@example.com', 'items' => array( array( 'ticket_type_id' => $y4t, 'quantity' => 1 ) ), 'idempotency_key' => 'y4-unpaid-' . uniqid() ), array( 'skip_rate_limit' => true ) );
$wpdb->update( "{$p}wpcap_orders", array( 'payment_status' => 'expired', 'status' => 'cancelled', 'billing' => '{"name":"Erika Muster","address":"Hauptstr. 1"}', 'created_at' => y4_ago( 100 ), 'updated_at' => y4_ago( 100 ) ), array( 'order_id' => $y4u['order_id'] ) );
// Box office sale without e-mail: the placeholder address was there from the start, the billing data was not.
$y4b = Orders::create( array( 'email' => 'boxoffice+y4' . substr( md5( uniqid() ), 0, 8 ) . Privacy::PLACEHOLDER_DOMAIN, 'items' => array( array( 'ticket_type_id' => $y4t, 'quantity' => 1 ) ), 'idempotency_key' => 'y4-box-' . uniqid() ), array( 'skip_rate_limit' => true ) );
$wpdb->update( "{$p}wpcap_orders", array( 'payment_status' => 'cancelled', 'status' => 'cancelled', 'billing' => '{"name":"Max Kasse"}', 'created_at' => y4_ago( 100 ), 'updated_at' => y4_ago( 100 ) ), array( 'order_id' => $y4b['order_id'] ) );
// Recent unpaid order: a late payment may still need the billing data.
$y4r = Orders::create( array( 'email' => 'y4-recent@example.com', 'items' => array( array( 'ticket_type_id' => $y4t, 'quantity' => 1 ) ), 'idempotency_key' => 'y4-recent-' . uniqid() ), array( 'skip_rate_limit' => true ) );
$wpdb->update( "{$p}wpcap_orders", array( 'payment_status' => 'expired', 'status' => 'cancelled', 'billing' => '{"name":"Neu"}', 'updated_at' => y4_ago( 5 ) ), array( 'order_id' => $y4r['order_id'] ) );
Retention::run();
$y4billing = static fn( $id ) => $wpdb->get_var( "SELECT billing FROM {$p}wpcap_orders WHERE order_id=" . (int) $id );
check( 'PRIV-2: never-paid order older than 90 days loses its billing data with the e-mail', null === $y4billing( $y4u['order_id'] ) && Privacy::is_placeholder_email( Orders::get( $y4u['order_id'] )->customer_email ) );
check( 'PRIV-2: billing of a never-paid order with a placeholder address is removed as well', null === $y4billing( $y4b['order_id'] ) );
check( 'PRIV-2: recent unpaid orders keep billing data (late payment)', '{"name":"Neu"}' === $y4billing( $y4r['order_id'] ) );

/* ================================================================== PRIV-3 */
echo "Y4 privacy: PRIV-3 consents and answers belong to their subject\n";
$y4ct = make_type( 20, 10, 0 );
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $y4ct, 'field_key' => 'photo', 'label' => 'Foto', 'field_type' => 'consent', 'required' => 0, 'consent_text' => 'Fotos ok.', 'consent_version' => '1', 'status' => 'active' ) );
$y4photo = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $y4ct, 'field_key' => 'diet', 'label' => 'Essen', 'field_type' => 'text', 'required' => 0, 'status' => 'active' ) );
$y4co = y4_order( $y4ct, 'y4-buyer@example.com', array( array( 'Ana Teil', 'y4-ana@example.com', array( 'photo' => '1', 'diet' => 'vegan' ) ) ) );
$y4ct_ticket = y4_tickets( $y4co['order_id'] )[0];
check( 'PRIV-3: fixture – consent recorded for the attendee', 1 === count( Consents::for_order( $y4co['order_id'] ) ) && 'y4-ana@example.com' === Consents::for_order( $y4co['order_id'] )[0]->subject_email );
check( 'PRIV-3: the buyer\'s consent export does not contain the attendee\'s consent, the attendee\'s does', array() === Consents::export( 'y4-buyer@example.com' )['data'] && 1 === count( Consents::export( 'y4-ana@example.com' )['data'] ) );
$y4bexp = y4_flat( Privacy::export( 'y4-buyer@example.com' ) );
check( 'PRIV-3: buyer export keeps answers the buyer typed in, not the attendee\'s consent answer', false !== strpos( $y4bexp, 'Essen=vegan' ) && false === strpos( $y4bexp, 'Foto=1' ) );
check( 'PRIV-3: attendee export contains the own consent answer', false !== strpos( y4_flat( Privacy::export( 'y4-ana@example.com' ) ), 'Foto=1' ) );
$y4ce = Consents::erase( 'y4-buyer@example.com' );
check( 'PRIV-3: the buyer\'s erasure does not destroy the attendee\'s consent proof', 0 === $y4ce['items_removed'] && 1 === count( Consents::for_order( $y4co['order_id'] ) ) );

/* ================================================================== PRIV-1 */
echo "Y4 privacy: PRIV-1 retention after the statutory period, waitlists, bookings, passes, SMS, admission\n";
// a) Orders past the statutory period (10 years from the end of the order year).
$y4ot = make_type( 20, 10, 0 );
$y4old = y4_order( $y4ot, 'y4-2014@example.com', array( array( 'Olaf Alt', 'y4-olaf@example.com' ) ) );
$y4new = y4_order( $y4ot, 'y4-thisyear@example.com', array( array( 'Nina Neu', '' ) ) );
y4_move( $y4ot, 30 );
$y4old_year = (int) gmdate( 'Y' ) - 11;
$wpdb->update( "{$p}wpcap_orders", array( 'created_at' => $y4old_year . '-06-01 10:00:00', 'billing' => '{"name":"Olaf Alt"}', 'customer_user_id' => 77 ), array( 'order_id' => $y4old['order_id'] ) );
$wpdb->update( "{$p}wpcap_orders", array( 'billing' => '{"name":"Nina"}' ), array( 'order_id' => $y4new['order_id'] ) );
$wpdb->insert( "{$p}wpcap_invoices", array( 'order_id' => $y4old['order_id'], 'invoice_number' => 'Y4-OLD-1', 'currency' => 'EUR', 'subtotal_minor' => 0, 'tax_minor' => 0, 'total_minor' => 0, 'buyer_snapshot' => '{"email":"y4-2014@example.com"}', 'issued_at' => $y4old_year . '-06-01 10:00:00', 'status' => 'issued' ) );
$y4old_ticket = y4_tickets( $y4old['order_id'] )[0];
// b) Event waitlist: ended occurrence (40 days) vs. upcoming one.
$y4wt = make_type( 20, 10, 0 );
y4_move( $y4wt, 40 );
$y4wf = make_type( 20, 10, 0 );
y4_move( $y4wf, -10 );
$y4wl = static function ( $type, $email ) use ( $wpdb, $p ) {
	$wpdb->insert( "{$p}wpcap_waitlist", array( 'event_id' => y4_event( $type ), 'occurrence_id' => y4_occ( $type ), 'ticket_type_id' => $type, 'email_hash' => hash( 'sha256', $email ), 'email' => $email, 'position' => 1, 'status' => 'waiting', 'manage_token_hash' => hash( 'sha256', 'm' . $email ), 'created_at' => y4_ago( 60 ), 'updated_at' => y4_ago( 60 ) ) );
	return (int) $wpdb->insert_id;
};
$y4wl_past = $y4wl( $y4wt, 'y4-wait-past@example.com' );
$y4wl_future = $y4wl( $y4wf, 'y4-wait-future@example.com' );
// c) Appointment waitlist and bookings.
$wpdb->insert( "{$p}wpcap_resources", array( 'event_id' => y4_event( $y4wt ), 'name' => 'Y4 Raum', 'capacity' => 1, 'status' => 'active' ) );
$y4res = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_service_slots", array( 'resource_id' => $y4res, 'starts_at' => y4_ago( 41 ), 'ends_at' => y4_ago( 40 ), 'capacity' => 1, 'status' => 'open' ) );
$y4slot_past = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_service_slots", array( 'resource_id' => $y4res, 'starts_at' => y4_ago( -10 ), 'ends_at' => y4_ago( -11 ), 'capacity' => 1, 'status' => 'open' ) );
$y4slot_future = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_service_waitlist", array( 'slot_id' => $y4slot_past, 'email_hash' => hash( 'sha256', 'y4-sw' ), 'email' => 'y4-slotwait@example.com', 'quantity' => 1, 'position' => 1, 'status' => 'waiting', 'manage_token_hash' => hash( 'sha256', 'y4-sw-m' ), 'created_at' => y4_ago( 50 ), 'updated_at' => y4_ago( 50 ) ) );
$y4sw = (int) $wpdb->insert_id;
$y4book = static function ( $slot, $email, $status, $days, $order = 0 ) use ( $wpdb, $p ) {
	$wpdb->insert( "{$p}wpcap_service_bookings", array( 'slot_id' => $slot, 'order_id' => $order, 'customer_email' => $email, 'idempotency_key' => hash( 'sha256', $email . uniqid() ), 'quantity' => 1, 'status' => $status, 'expires_at' => y4_ago( $days ), 'manage_token_hash' => hash( 'sha256', 'b' . $email ), 'created_at' => y4_ago( $days ), 'updated_at' => y4_ago( $days ) ) );
	return (int) $wpdb->insert_id;
};
$y4b_expired = $y4book( $y4slot_future, 'y4-held-old@example.com', 'expired', 100, 999999 );
$y4b_recent = $y4book( $y4slot_future, 'y4-held-new@example.com', 'expired', 5, 999999 );
$y4b_pass = $y4book( $y4slot_past, 'y4-passbook@example.com', 'confirmed', 400 );
$wpdb->update( "{$p}wpcap_service_slots", array( 'starts_at' => y4_ago( 401 ), 'ends_at' => y4_ago( 400 ) ), array( 'slot_id' => $y4slot_past ) );
// d) Member passes.
$y4pass = static function ( $email, $until_days_ago ) use ( $wpdb, $p ) {
	$wpdb->insert( "{$p}wpcap_member_passes", array( 'public_id' => bin2hex( random_bytes( 16 ) ), 'member_email' => $email, 'token_hash' => hash( 'sha256', $email . uniqid() ), 'valid_from' => y4_ago( $until_days_ago + 365 ), 'valid_until' => y4_ago( $until_days_ago ), 'status' => 'active', 'created_at' => y4_ago( $until_days_ago + 365 ) ) );
	return (int) $wpdb->insert_id;
};
$y4p_old = $y4pass( 'y4-pass-old@example.com', 400 );
$y4p_valid = $y4pass( 'y4-pass-valid@example.com', -30 );
$y4p_old_token = $wpdb->get_var( "SELECT token_hash FROM {$p}wpcap_member_passes WHERE pass_id=$y4p_old" );
// e) SMS texts.
$y4sms = static function ( $status, $days ) use ( $wpdb, $p ) {
	$wpdb->insert( "{$p}wpcap_sms_messages", array( 'dedupe_key' => hash( 'sha256', 'y4sms' . uniqid( '', true ) ), 'consent_id' => 0, 'order_id' => 0, 'purpose' => 'cancellation', 'body' => 'Abgesagt: Y4', 'status' => $status, 'last_error' => 'failed' === $status ? 'Nummer +43 660 1234567 ungültig' : null, 'send_at' => y4_ago( $days ), 'created_at' => y4_ago( $days ), 'updated_at' => y4_ago( $days ) ) );
	return (int) $wpdb->insert_id;
};
$y4sms_old = $y4sms( 'failed', 40 );
$y4sms_queued = $y4sms( 'queued', 40 );
// f) Admission entries/conflicts and device labels.
$wpdb->insert( "{$p}wpcap_admission_entries", array( 'ticket_id' => $y4old_ticket, 'occurrence_id' => y4_occ( $y4ot ), 'service_date' => gmdate( 'Y-m-d', time() - 400 * 86400 ), 'direction' => 'in', 'user_id' => 5, 'device_id' => 3, 'entered_at' => y4_ago( 400 ), 'created_at' => y4_ago( 400 ) ) );
$y4ae = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_admission_conflicts", array( 'dedupe_key' => hash( 'sha256', 'y4c' . uniqid() ), 'ticket_id' => $y4old_ticket, 'result' => 'duplicate', 'user_id' => 5, 'device_id' => 3, 'first_user_id' => 6, 'first_device_id' => 4, 'status' => 'resolved', 'resolution_note' => 'Anna Muster kam zweimal', 'resolved_by' => 1, 'created_at' => y4_ago( 400 ) ) );
$y4ac = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_scanner_devices", array( 'public_id' => bin2hex( random_bytes( 16 ) ), 'user_id' => 5, 'secret_hash' => hash( 'sha256', 'y4dev' . uniqid() ), 'label' => 'iPhone Anna', 'revoked_at' => y4_ago( 400 ), 'created_at' => y4_ago( 500 ) ) );
$y4dev = (int) $wpdb->insert_id;
// g) PRIV-13: pending attendee data of a paid order whose event ended more than a year ago.
$y4at = make_type( 20, 10, 0 );
$y4ao = y4_order( $y4at, 'y4-pending@example.com', array( array( 'Pia', '' ) ) );
y4_move( $y4at, 400 );
$wpdb->update( "{$p}wpcap_orders", array( 'attendee_data' => '[{"name":"Paul Später"}]' ), array( 'order_id' => $y4ao['order_id'] ) );
$y4af = make_type( 20, 10, 0 );
$y4afo = y4_order( $y4af, 'y4-pending-future@example.com', array( array( 'Fritz', '' ) ) );
$wpdb->update( "{$p}wpcap_orders", array( 'attendee_data' => '[{"name":"Fritz Später"}]' ), array( 'order_id' => $y4afo['order_id'] ) );

$y4counts = Retention::run();
$y4oo = Orders::get( $y4old['order_id'] );
check( 'PRIV-1: order past the statutory period loses buyer e-mail, user link, billing (placeholder kept deterministic)', Privacy::replacement_email( 'y4-2014@example.com', $y4old['order_id'] ) === $y4oo->customer_email && 0 === (int) $y4oo->customer_user_id && null === $y4oo->billing && $y4counts['paid_orders'] >= 2 );
check( 'PRIV-1: its ticket assignment loses buyer e-mail and attendee data', $y4oo->customer_email === $wpdb->get_var( "SELECT buyer_email FROM {$p}wpcap_ticket_assignments WHERE ticket_id=$y4old_ticket" ) && '' === $wpdb->get_var( "SELECT attendee_name FROM {$p}wpcap_ticket_assignments WHERE ticket_id=$y4old_ticket" ) );
check( 'PRIV-1: invoices are not changed by the retention run', '{"email":"y4-2014@example.com"}' === $wpdb->get_var( "SELECT buyer_snapshot FROM {$p}wpcap_invoices WHERE invoice_number='Y4-OLD-1'" ) );
check( 'PRIV-1: orders of this year keep e-mail and billing', 'y4-thisyear@example.com' === Orders::get( $y4new['order_id'] )->customer_email && '{"name":"Nina"}' === Orders::get( $y4new['order_id'] )->billing );
$y4w = static fn( $id ) => $wpdb->get_row( "SELECT email,email_hash,manage_token_hash,status FROM {$p}wpcap_waitlist WHERE waitlist_id=$id" );
check( 'PRIV-1: event waitlist entry pseudonymised 30 days after its occurrence, tokens cleared', Privacy::is_placeholder_email( $y4w( $y4wl_past )->email ) && '' === $y4w( $y4wl_past )->manage_token_hash && 'expired' === $y4w( $y4wl_past )->status );
check( 'PRIV-1: waitlist entry of an upcoming occurrence is kept', 'y4-wait-future@example.com' === $y4w( $y4wl_future )->email );
check( 'PRIV-1: appointment waitlist entry pseudonymised after the slot', Privacy::is_placeholder_email( $wpdb->get_var( "SELECT email FROM {$p}wpcap_service_waitlist WHERE entry_id=$y4sw" ) ) );
$y4be = static fn( $id ) => $wpdb->get_var( "SELECT customer_email FROM {$p}wpcap_service_bookings WHERE booking_id=$id" );
check( 'PRIV-1: lapsed service hold pseudonymised after 90 days, a recent one is kept', Privacy::is_placeholder_email( $y4be( $y4b_expired ) ) && 'y4-held-new@example.com' === $y4be( $y4b_recent ) );
check( 'PRIV-1: pass booking without order pseudonymised after slot end plus the attendee period', Privacy::is_placeholder_email( $y4be( $y4b_pass ) ) );
$y4pr = static fn( $id ) => $wpdb->get_row( "SELECT member_email,token_hash FROM {$p}wpcap_member_passes WHERE pass_id=$id" );
check( 'PRIV-1: member pass pseudonymised 365 days after valid_until (code invalidated), valid pass kept', Privacy::is_placeholder_email( $y4pr( $y4p_old )->member_email ) && $y4p_old_token !== $y4pr( $y4p_old )->token_hash && 'y4-pass-valid@example.com' === $y4pr( $y4p_valid )->member_email );
$y4sr = static fn( $id ) => $wpdb->get_row( "SELECT body,last_error FROM {$p}wpcap_sms_messages WHERE message_id=$id" );
check( 'PRIV-1: finished SMS lose text and error after 30 days, queued ones keep their text', '' === $y4sr( $y4sms_old )->body && null === $y4sr( $y4sms_old )->last_error && 'Abgesagt: Y4' === $y4sr( $y4sms_queued )->body );
$y4aer = $wpdb->get_row( "SELECT user_id,device_id FROM {$p}wpcap_admission_entries WHERE entry_id=$y4ae" );
$y4acr = $wpdb->get_row( "SELECT user_id,device_id,first_user_id,first_device_id,resolved_by,resolution_note,result FROM {$p}wpcap_admission_conflicts WHERE conflict_id=$y4ac" );
check( 'PRIV-1: admission entries and conflicts lose staff, devices and the note after a year', '0' === (string) $y4aer->user_id && '0' === (string) $y4aer->device_id && '0' === (string) $y4acr->user_id && '0' === (string) $y4acr->first_user_id && '0' === (string) $y4acr->first_device_id && '0' === (string) $y4acr->resolved_by && null === $y4acr->resolution_note && 'duplicate' === $y4acr->result );
check( 'V3: label of a revoked scanner device is cleared after a year', '' === $wpdb->get_var( "SELECT label FROM {$p}wpcap_scanner_devices WHERE device_id=$y4dev" ) );
check( 'PRIV-13: pending attendee data of a paid order cleared after its last date plus the attendee period, upcoming kept', null === Orders::get( $y4ao['order_id'] )->attendee_data && null !== Orders::get( $y4afo['order_id'] )->attendee_data );
// Settings switch steps off; the policy text follows the settings.
$y4wl_off = $y4wl( $y4wt, 'y4-wait-off@example.com' );
Settings::update( array( 'retention_waitlist_days' => 0 ) );
Retention::run();
check( 'PRIV-1: setting retention_waitlist_days = 0 disables the waitlist step', 'y4-wait-off@example.com' === $y4w( $y4wl_off )->email );
$y4policy_off = PolicyText::content();
Settings::update( array( 'retention_waitlist_days' => 30 ) );
$y4policy = PolicyText::content();
check( 'PRIV-1: policy text names waitlist, pass and order periods from the settings', false !== strpos( $y4policy, 'Wartelisteneinträge anonymisieren wir 30 Tage' ) && false !== strpos( $y4policy, '365 Tage nach dem Ende ihrer Gültigkeit' ) && false !== strpos( $y4policy, '10 Jahre nach dem Ende des Bestelljahres' ) && false === strpos( $y4policy_off, 'Wartelisteneinträge anonymisieren' ) );
check( 'PRIV-1: settings page offers the retention periods', isset( PassCaptain\Admin\SettingsPage::field_definitions()['privacy']['fields']['retention_order_years'], PassCaptain\Admin\SettingsPage::field_definitions()['privacy']['fields']['retention_waitlist_days'], PassCaptain\Admin\SettingsPage::field_definitions()['privacy']['fields']['retention_pass_days'] ) );

/* ================================================================== PRIV-4 */
echo "Y4 privacy: PRIV-4 wallet passes lose the name\n";
if ( ! class_exists( 'Y4_Google' ) ) {
	class Y4_Google extends GoogleProvider {
		public array $calls = array();
		public function api( $method, $path, array $body ) { $this->calls[] = array( $method, $path, $body ); return array( 'code' => 200, 'body' => array() ); }
	}
}
$y4g = new Y4_Google();
$y4g->update_pass( array( 'public_id' => 'abc', 'generation' => 1, 'occurrence_id' => 1, 'ticket_name' => 'Std', 'voided' => true, 'code' => null, 'seat' => '', 'attendee_name' => '' ), array( 'serial_number' => 'y4.serial' ) );
check( 'PRIV-4: Google PATCH sends an empty ticketHolderName (PATCH keeps omitted fields)', 'PATCH' === ( $y4g->calls[0][0] ?? '' ) && array_key_exists( 'ticketHolderName', $y4g->calls[0][2] ?? array() ) && '' === $y4g->calls[0][2]['ticketHolderName'] );
$y4wt2 = make_type( 20, 10, 0 );
$y4wo = y4_order( $y4wt2, 'y4-walletbuyer@example.com', array( array( 'Wanda Wallet', 'y4-wanda@example.com' ) ) );
$y4wtid = y4_tickets( $y4wo['order_id'] )[0];
$y4code = bin2hex( random_bytes( 32 ) );
$wpdb->update( "{$p}wpcap_tickets", array( 'token_hash' => hash( 'sha256', $y4code ) ), array( 'ticket_id' => $y4wtid ) );
Y4WalletStore::put_code( $y4wtid, $y4code );
Y4WalletStore::touch( $y4wtid, Y4WalletService::fingerprint( Y4WalletService::ticket( $y4wtid ) ), Y4WalletStore::state( $y4wtid )->update_tag );
$y4tag = (int) Y4WalletStore::state( $y4wtid )->update_tag;
Privacy::erase( 'y4-wanda@example.com' );
$y4state = Y4WalletStore::state( $y4wtid );
check( 'PRIV-4: attendee erasure updates the wallet pass (new fingerprint without name, update tag bumped for the push)', (int) $y4state->update_tag > $y4tag && hash_equals( (string) $y4state->fingerprint, Y4WalletService::fingerprint( Y4WalletService::ticket( $y4wtid ) ) ) && '' === Y4WalletService::ticket( $y4wtid )['attendee_name'] );
// Retention: same for names cleared after the event.
$y4rt = make_type( 20, 10, 0 );
$y4ro = y4_order( $y4rt, 'y4-retwallet@example.com', array( array( 'Rolf Wallet', 'y4-rolf@example.com' ) ) );
$y4rtid = y4_tickets( $y4ro['order_id'] )[0];
$y4code2 = bin2hex( random_bytes( 32 ) );
$wpdb->update( "{$p}wpcap_tickets", array( 'token_hash' => hash( 'sha256', $y4code2 ) ), array( 'ticket_id' => $y4rtid ) );
Y4WalletStore::put_code( $y4rtid, $y4code2 );
Y4WalletStore::touch( $y4rtid, Y4WalletService::fingerprint( Y4WalletService::ticket( $y4rtid ) ), Y4WalletStore::state( $y4rtid )->update_tag );
$wpdb->insert( "{$p}wpcap_wallet_passes", array( 'ticket_id' => $y4rtid, 'provider' => 'apple', 'serial_number' => 'y4-rolf-serial', 'status' => 'active', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$wpdb->insert( "{$p}wpcap_wallet_registrations", array( 'device_library_id' => 'y4-device', 'push_token' => 'y4-push', 'pass_type_id' => 'pass.y4', 'serial_number' => 'y4-rolf-serial', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
y4_move( $y4rt, 365.5 );
$y4tag2 = (int) Y4WalletStore::state( $y4rtid )->update_tag;
Retention::run();
check( 'PRIV-4: retention clears the name and updates the wallet pass; the device registration stays for the push', (int) Y4WalletStore::state( $y4rtid )->update_tag > $y4tag2 && '' === Y4WalletService::ticket( $y4rtid )['attendee_name'] && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_wallet_registrations WHERE serial_number='y4-rolf-serial'" ) );
y4_move( $y4rt, 367 );
Retention::run();
check( 'PRIV-4: the device registration is deleted one day later', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_wallet_registrations WHERE serial_number='y4-rolf-serial'" ) );

/* ================================================================== PRIV-6 */
echo "Y4 privacy: PRIV-6 waitlist e-mails masked for support\n";
$y4mt = make_type( 20, 10, 0 );
y4_move( $y4mt, -20 );
$y4wl( $y4mt, 'y4-support-view@example.com' );
$wpdb->insert( "{$p}wpcap_event_staff", array( 'event_id' => y4_event( $y4mt ), 'user_id' => 9471, 'capability' => 'wpcap_manage_orders' ) );
$y4all = array( 'manage_options', 'manage_network_options', 'wpcap_manage_events', 'wpcap_manage_orders', 'wpcap_checkin', 'wpcap_view_reports', 'wpcap_refund', 'wpcap_approve_refunds', 'wpcap_view_finance', 'wpcap_view_audit' );
$GLOBALS['wpcap_test_user_id'] = 9471;
$GLOBALS['wpcap_test_denied_caps'] = array_values( array_diff( $y4all, array( 'wpcap_manage_orders' ) ) );
$GLOBALS['wpcap_test_user_can'] = static fn( $u, $c ) => 9471 === (int) $u && 'wpcap_manage_orders' === $c;
if ( class_exists( '\PassCaptain\Security\SupportAccess' ) ) { \PassCaptain\Security\SupportAccess::flush_cache(); }
$y4csv = PassCaptain\Admin\WaitlistsPage::export( array( 'event_id' => y4_event( $y4mt ) ) );
ob_start();
$_GET['event_id'] = y4_event( $y4mt );
PassCaptain\Admin\WaitlistsPage::render();
$y4html = (string) ob_get_clean();
unset( $_GET['event_id'] );
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
unset( $GLOBALS['wpcap_test_user_can'] );
if ( class_exists( '\PassCaptain\Security\SupportAccess' ) ) { \PassCaptain\Security\SupportAccess::flush_cache(); }
check( 'PRIV-6: support profile gets the waitlist CSV with masked e-mail', is_string( $y4csv ) && false === strpos( $y4csv, 'y4-support-view@example.com' ) && false !== strpos( $y4csv, 'y•••@e•••.com' ) );
check( 'PRIV-6: support profile sees the masked e-mail on the waitlist screen', false === strpos( $y4html, 'y4-support-view@example.com' ) );
$y4csv_admin = PassCaptain\Admin\WaitlistsPage::export( array( 'event_id' => y4_event( $y4mt ) ) );
check( 'PRIV-6: full profile still sees the address', is_string( $y4csv_admin ) && false !== strpos( $y4csv_admin, 'y4-support-view@example.com' ) );

/* ================================================================== PRIV-7 */
echo "Y4 privacy: PRIV-7 WooCommerce attendee copy\n";
$y4hooks_backup = $GLOBALS['wpcap_test_hooks'];
Y4WooAttendees::register();
$y4registered = static function ( $hook, $method ) {
	foreach ( (array) ( $GLOBALS['wpcap_test_hooks'][ $hook ] ?? array() ) as $callbacks ) { foreach ( $callbacks as $cb ) { if ( array( Y4WooAttendees::class, $method ) === $cb[0] ) { return true; } } }
	return false;
};
check( 'PRIV-7: WooCommerce privacy exporter/eraser and status hooks are registered', $y4registered( 'woocommerce_privacy_export_order_personal_data', 'privacy_export' ) && $y4registered( 'woocommerce_privacy_remove_order_personal_data', 'privacy_erase' ) && $y4registered( 'woocommerce_order_status_cancelled', 'discard' ) && $y4registered( 'woocommerce_order_status_failed', 'remember_failed' ) );
$GLOBALS['wpcap_test_hooks'] = $y4hooks_backup;
$y4wc = new WC_Order( 947001 );
$y4wc->update_meta_data( Y4Woo::ATTENDEE_META, array( array( 'ticket_type_id' => 1, 'name' => 'Woo Wilma', 'email' => 'wilma@example.com' ) ) );
$y4exp = Y4WooAttendees::privacy_export( array(), $y4wc );
check( 'PRIV-7: WooCommerce order export contains the attendee copy', 1 === count( $y4exp ) && false !== strpos( $y4exp[0]['value'], 'Woo Wilma' ) );
Y4WooAttendees::discard( 947001 );
check( 'PRIV-7: a cancelled order loses the attendee copy', '' === $y4wc->get_meta( Y4Woo::ATTENDEE_META ) );
$y4wc2 = new WC_Order( 947002 );
$y4wc2->update_meta_data( Y4Woo::ATTENDEE_META, array( array( 'name' => 'Fail Fritz' ) ) );
Y4WooAttendees::remember_failed( 947002 );
Y4WooAttendees::purge_failed( time() + 2 * 86400 );
$y4kept = '' !== $y4wc2->get_meta( Y4Woo::ATTENDEE_META );
Y4WooAttendees::purge_failed( time() + 8 * 86400 );
check( 'PRIV-7: a failed order keeps the copy for a payment retry (7 days), then loses it', $y4kept && '' === $y4wc2->get_meta( Y4Woo::ATTENDEE_META ) );
$y4wc3 = new WC_Order( 947003 );
$y4wc3->update_meta_data( Y4Woo::ATTENDEE_META, array( array( 'name' => 'Erase Emma' ) ) );
Y4WooAttendees::privacy_erase( $y4wc3 );
check( 'PRIV-7: WooCommerce order eraser removes the attendee copy', '' === $y4wc3->get_meta( Y4Woo::ATTENDEE_META ) );

/* ================================================================== PRIV-8 */
echo "Y4 privacy: PRIV-8 keyed rate-limit hashes\n";
$y4src = '';
foreach ( array( 'src/Seats.php', 'src/Waitlists.php', 'src/ServiceBookings.php', 'src/ServiceWaitlist.php', 'src/Services/Api.php', 'src/Partners/Keys.php', 'src/Sales/BuyerLimits.php' ) as $y4file ) { $y4src .= file_get_contents( WPCAP_PATH . $y4file ); }
// Transient/lock names built from an unkeyed hash ('wpcap_x_' . hash( 'sha256', … ) or md5( … )) and the buyer lock name.
check( 'PRIV-8: no unkeyed sha256/md5 of e-mail or IP addresses for counters and locks', ! preg_match( '/\'(wpcap|partner)_[a-z_]+\' \. (hash\( \'sha256\'|md5\()/', $y4src ) && false === strpos( $y4src, "substr( hash( 'sha256', self::normalize_email( \$email ) )" ) );
check( 'PRIV-8: RateLimit::hash is keyed (differs from plain sha256)', RateLimit::hash( 'a@example.com' ) !== hash( 'sha256', 'a@example.com' ) && 64 === strlen( RateLimit::hash( 'a@example.com' ) ) );
$y4seat_before = $wpdb->get_col( $wpdb->prepare( 'SELECT bucket_key FROM %i', RateLimit::table() ) );
$y4ip = '203.0.113.' . random_int( 10, 200 );
$_SERVER['REMOTE_ADDR'] = $y4ip;
PassCaptain\Waitlists::join( y4_event( $y4wf ), y4_occ( $y4wf ), $y4wf, 'y4-ratelimit@example.com' );
$y4new_keys = array_values( array_diff( $wpdb->get_col( $wpdb->prepare( 'SELECT bucket_key FROM %i', RateLimit::table() ) ), $y4seat_before ) );
check( 'PRIV-8: waitlist rate-limit counters use keyed hashes (bucket = RateLimit::hash of a keyed name)', 3 === count( $y4new_keys ) && in_array( RateLimit::hash( 'wpcap_waitlist_ip_' . RateLimit::hash( $y4ip . '|' . y4_event( $y4wf ) ) ), $y4new_keys, true ) && ! in_array( hash( 'sha256', $y4ip . '|' . y4_event( $y4wf ) ), $y4new_keys, true ) && ! in_array( RateLimit::hash( 'wpcap_waitlist_' . hash( 'sha256', 'y4-ratelimit@example.com|' . y4_event( $y4wf ) ) ), $y4new_keys, true ) );
check( 'PRIV-8: policy text names HTTP/JSON connectors with names', ! PolicyText::recipients()['webhooks'] || false !== strpos( PolicyText::content(), 'HTTP/JSON' ) );
$y4filter = static fn( $r ) => array_merge( $r, array( 'webhooks' => true, 'fiscal' => true ) );
$GLOBALS['wpcap_test_hooks']['wpcap_privacy_policy_recipients'][10][] = array( $y4filter, 1 );
$y4pc = PolicyText::content();
array_pop( $GLOBALS['wpcap_test_hooks']['wpcap_privacy_policy_recipients'][10] );
check( 'PRIV-8: policy text lists connector names and the fiscal signature services', false !== strpos( $y4pc, 'HTTP/JSON-Connectoren' ) && false !== strpos( $y4pc, 'Namen der Teilnehmenden' ) && false !== strpos( $y4pc, 'fiskaly' ) );

/* ================================================================== PRIV-10 */
echo "Y4 privacy: PRIV-10 redaction of phone, IBAN and IP\n";
$y4red = Redact::text( 'Ruf +49 176 12345678 an, IBAN DE89 3704 0044 0532 0130 00, IP 192.0.2.44 / 2001:db8::7, am 2026-11-15 12:30:45 Bestellung 12345678, Netz 198.51.100.0' );
check( 'PRIV-10: phone numbers, IBANs and IPv4/IPv6 addresses are masked in free text', false === strpos( $y4red, '12345678 an' ) && false === strpos( $y4red, 'DE89' ) && false === strpos( $y4red, '192.0.2.44' ) && false === strpos( $y4red, '2001:db8::7' ) );
check( 'PRIV-10: dates, times, plain numbers and truncated addresses stay readable', false !== strpos( $y4red, '2026-11-15 12:30:45' ) && false !== strpos( $y4red, 'Bestellung 12345678' ) && false !== strpos( $y4red, '198.51.100.0' ) );
$y4rd = Redact::data( array( 'mobile' => '+436601234567', 'ip_address' => '192.0.2.1', 'attendee_name' => 'Ana Teil', 'event_name' => 'Konzert', 'billing' => array( 'street' => 'Hauptstr. 1' ) ) );
check( 'PRIV-10: contact keys (mobile, ip_address, names, street) are masked, other keys kept', Redact::MASK === $y4rd['mobile'] && Redact::MASK === $y4rd['ip_address'] && Redact::MASK === $y4rd['attendee_name'] && 'Konzert' === $y4rd['event_name'] );

/* ================================================================== PRIV-11 / PRIV-12 */
echo "Y4 privacy: PRIV-11/12 exporter and eraser gaps\n";
$y4et = make_type( 20, 10, 0 );
$y4eo = y4_order( $y4et, 'y4-gaps@example.com', array( array( 'Gerd', '' ) ) );
y4_move( $y4et, 10 );
$wpdb->update( "{$p}wpcap_orders", array( 'customer_user_id' => 4242 ), array( 'order_id' => $y4eo['order_id'] ) );
$wpdb->insert( "{$p}wpcap_refunds", array( 'order_id' => $y4eo['order_id'], 'idempotency_key' => hash( 'sha256', 'y4-ref' . uniqid() ), 'amount_minor' => 100, 'currency' => 'EUR', 'ticket_ids' => '[]', 'reason' => 'Krank geworden', 'status' => 'completed', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$wpdb->insert( "{$p}wpcap_message_queue", array( 'dedupe_key' => hash( 'sha256', 'y4-mq' . uniqid() ), 'recipient' => 'y4-gaps@example.com', 'template_key' => 'order_confirmation', 'payload' => '{}', 'send_at' => gmdate( 'Y-m-d H:i:s' ), 'status' => 'sent', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$y4gb = $y4book( $y4slot_future, 'y4-gaps@example.com', 'cancelled', 1 );
$wpdb->update( "{$p}wpcap_service_bookings", array( 'cancel_reason' => 'customer_request' ), array( 'booking_id' => $y4gb ) );
$wpdb->insert( "{$p}wpcap_waitlist", array( 'event_id' => y4_event( $y4wf ), 'occurrence_id' => y4_occ( $y4wf ), 'ticket_type_id' => $y4wf, 'email_hash' => hash( 'sha256', 'y4-gaps-wl' ), 'email' => 'y4-gaps@example.com', 'position' => 9, 'status' => 'waiting', 'manage_token_hash' => hash( 'sha256', 'y4-gaps-m' ), 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$y4gwl = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_partners", array( 'name' => 'Y4 Partner GmbH', 'contact_name' => 'Gabi Gaps', 'contact_email' => 'y4-gaps@example.com', 'contact_phone' => '+43 1 234567', 'billing_address' => '', 'payout_details' => '', 'notes' => '', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$y4partner = (int) $wpdb->insert_id;
$y4gexp = y4_flat( Privacy::export( 'y4-gaps@example.com' ) );
check( 'PRIV-11: export contains linked user account, refund reason, cancel reason, queued/sent e-mails and partner contact', false !== strpos( $y4gexp, '4242' ) && false !== strpos( $y4gexp, 'Krank geworden' ) && false !== strpos( $y4gexp, 'customer_request' ) && false !== strpos( $y4gexp, 'Vorlage=order_confirmation' ) && false !== strpos( $y4gexp, 'Gabi Gaps' ) );
Privacy::erase( 'y4-gaps@example.com' );
check( 'PRIV-12: eraser removes the user link of the order', 0 === (int) Orders::get( $y4eo['order_id'] )->customer_user_id );
check( 'PRIV-12: eraser clears manage tokens of bookings and waitlist entries', '' === $wpdb->get_var( "SELECT manage_token_hash FROM {$p}wpcap_service_bookings WHERE booking_id=$y4gb" ) && '' === $wpdb->get_var( "SELECT manage_token_hash FROM {$p}wpcap_waitlist WHERE waitlist_id=$y4gwl" ) );
$y4pc_row = $wpdb->get_row( "SELECT name,contact_name,contact_email,contact_phone FROM {$p}wpcap_partners WHERE partner_id=$y4partner" );
check( 'PRIV-12: eraser removes the partner contact person, the partner stays', 'Y4 Partner GmbH' === $y4pc_row->name && '' === $y4pc_row->contact_name && '' === $y4pc_row->contact_email && '' === $y4pc_row->contact_phone );
// SMS: message text erased with the consent.
$wpdb->insert( "{$p}wpcap_orders", array( 'public_id' => bin2hex( random_bytes( 16 ) ), 'idempotency_key' => hash( 'sha256', 'y4-sms-order' . uniqid() ), 'customer_email' => 'y4-sms@example.com', 'status' => 'complete', 'payment_status' => 'paid', 'currency' => 'EUR', 'price_snapshot' => '{}', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$y4so = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_sms_consents", array( 'order_id' => $y4so, 'phone' => '+436601234567', 'purpose' => 'event_changes', 'text_version' => 'v1', 'consented_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$y4scid = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_sms_messages", array( 'dedupe_key' => hash( 'sha256', 'y4-sms-msg' . uniqid() ), 'consent_id' => $y4scid, 'order_id' => $y4so, 'purpose' => 'reschedule', 'body' => 'Neuer Termin Y4', 'status' => 'sent', 'send_at' => gmdate( 'Y-m-d H:i:s' ), 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$y4smid = (int) $wpdb->insert_id;
check( 'PRIV-11: SMS export contains the message text', false !== strpos( wp_json_encode( PassCaptain\Modules\Sms::export( 'y4-sms@example.com' ) ), 'Neuer Termin Y4' ) );
PassCaptain\Modules\Sms::erase( 'y4-sms@example.com' );
check( 'PRIV-12: SMS eraser removes message texts of the person\'s order', '' === $wpdb->get_var( "SELECT body FROM {$p}wpcap_sms_messages WHERE message_id=$y4smid" ) );
// Member passes: deferral consistent with orders.
$y4dp = $y4pass( 'y4-defer-pass@example.com', -30 );
$y4dpe = Privacy::erase( 'y4-defer-pass@example.com' );
$y4dprow = $wpdb->get_row( "SELECT member_email,status FROM {$p}wpcap_member_passes WHERE pass_id=$y4dp" );
check( 'PRIV-12: a valid member pass is deferred (stays usable) like orders with upcoming dates', 'y4-defer-pass@example.com' === $y4dprow->member_email && 'active' === $y4dprow->status && isset( Privacy::deferred_passes()[ $y4dp ] ) && $y4dpe['items_retained'] && (bool) preg_grep( '/Mitgliedspass/', $y4dpe['messages'] ) );
$wpdb->update( "{$p}wpcap_member_passes", array( 'valid_until' => y4_ago( 1 ) ), array( 'pass_id' => $y4dp ) );
Privacy::process_deferred();
$y4dprow = $wpdb->get_row( "SELECT member_email,status FROM {$p}wpcap_member_passes WHERE pass_id=$y4dp" );
check( 'PRIV-12: the deferred pass is anonymised once it expired', Privacy::is_placeholder_email( $y4dprow->member_email ) && 'revoked' === $y4dprow->status && ! isset( Privacy::deferred_passes()[ $y4dp ] ) );
$y4xp = $y4pass( 'y4-expired-pass@example.com', 10 );
Privacy::erase( 'y4-expired-pass@example.com' );
check( 'PRIV-12: an expired pass is anonymised right away', Privacy::is_placeholder_email( $wpdb->get_var( "SELECT member_email FROM {$p}wpcap_member_passes WHERE pass_id=$y4xp" ) ) );

/* ================================================================== PRIV-15 */
echo "Y4 privacy: PRIV-15 affiliate cookie without consent API\n";
if ( ! function_exists( 'wp_has_consent' ) ) {
	check( 'PRIV-15: without a consent API the referral cookie counts as not consented', false === PassCaptain\Integrations\AffiliateCaptain\Adapter::tracking_allowed( 'cookie' ) && true === PassCaptain\Integrations\AffiliateCaptain\Adapter::tracking_allowed( 'link' ) );
} else {
	$GLOBALS['p1p_consent'] = array();
	check( 'PRIV-15: without marketing consent the referral cookie is not used (consent API present in this run)', false === PassCaptain\Integrations\AffiliateCaptain\Adapter::tracking_allowed( 'cookie' ) );
}
$y4adapter = (string) file_get_contents( WPCAP_PATH . 'src/Integrations/AffiliateCaptain/Adapter.php' );
check( 'PRIV-15: default without consent API is false in the code', false !== strpos( $y4adapter, "function_exists( 'wp_has_consent' ) && (bool) wp_has_consent( 'marketing' )" ) );

/* ================================================================== PRIV-17 */
echo "Y4 privacy: PRIV-17 uninstall needs a typed confirmation\n";
Settings::update( array( 'delete_data_on_uninstall' => 0 ) );
$y4base = Settings::all();
// save() writes every field of the form; the settings are restored afterwards.
$y4save = PassCaptain\Admin\SettingsPage::save( array( 'delete_data_on_uninstall' => '1' ) );
$y4unconfirmed = (int) Settings::get( 'delete_data_on_uninstall' );
PassCaptain\Admin\SettingsPage::save( array( 'delete_data_on_uninstall' => '1', 'delete_data_on_uninstall_confirm' => 'löschen' ) );
$y4confirmed = (int) Settings::get( 'delete_data_on_uninstall' );
update_option( Settings::OPTION, $y4base, false );
Settings::update( array( 'delete_data_on_uninstall' => 0 ) );
check( 'PRIV-17: enabling data deletion on uninstall without typing LÖSCHEN is refused', 0 === $y4unconfirmed && ( is_wp_error( $y4save ) || ! empty( $y4save['errors'] ) ) );
check( 'PRIV-17: with the typed confirmation the option is saved', 1 === $y4confirmed );
$y4desc = implode( ' ', array_map( static fn( $s ) => $s['fields']['delete_data_on_uninstall']['description'] ?? '', PassCaptain\Admin\SettingsPage::field_definitions() ) );
check( 'PRIV-17: the warning names invoices and fiscal records and asks for an export first', false !== strpos( $y4desc, 'Fiskal' ) && false !== strpos( $y4desc, 'exportieren' ) );

/* ================================================================== Suspicions */
echo "Y4 privacy: suspicions V1/V2\n";
$wpdb->insert( "{$p}wpcap_webhook_subscriptions", array( 'url' => 'https://hooks.example.com/y4', 'events' => '*', 'secret' => 'x', 'include_pii' => 1, 'status' => 'disabled', 'created_at' => y4_ago( 90 ), 'updated_at' => y4_ago( 90 ) ) );
$y4sub = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_webhook_deliveries", array( 'subscription_id' => $y4sub, 'event_id' => 'evt_y4_' . uniqid(), 'event_type' => 'order.paid', 'payload' => wp_json_encode( array( 'id' => 'e', 'data' => array( 'object' => array( 'id' => 'o', 'customer_email' => 'y4-webhook@example.com' ) ) ) ), 'status' => 'pending', 'next_attempt_at' => y4_ago( 60 ), 'created_at' => y4_ago( 60 ), 'updated_at' => y4_ago( 60 ) ) );
$y4del = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_webhook_deliveries", array( 'subscription_id' => $y4sub, 'event_id' => 'evt_y4n_' . uniqid(), 'event_type' => 'order.paid', 'payload' => wp_json_encode( array( 'id' => 'e', 'data' => array( 'object' => array( 'id' => 'o', 'customer_email' => 'y4-webhook-new@example.com' ) ) ) ), 'status' => 'pending', 'next_attempt_at' => y4_ago( 1 ), 'created_at' => y4_ago( 1 ), 'updated_at' => y4_ago( 1 ) ) );
$y4del_new = (int) $wpdb->insert_id;
PassCaptain\Webhooks\Dispatcher::purge_old();
$y4dr = $wpdb->get_row( "SELECT status,payload,pii_purged FROM {$p}wpcap_webhook_deliveries WHERE delivery_id=$y4del" );
check( 'V1: pending deliveries of a disabled subscription are cancelled without personal data after the retention period', $y4dr && 'cancelled' === $y4dr->status && false === strpos( $y4dr->payload, 'y4-webhook@' ) && '1' === (string) $y4dr->pii_purged );
check( 'V1: recent pending deliveries stay for a reactivation', 'pending' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_webhook_deliveries WHERE delivery_id=$y4del_new" ) );
$y4api = (string) file_get_contents( WPCAP_PATH . 'src/Partners/Api.php' );
check( 'V2: partner API reads of e-mail addresses are audited (orders and tickets)', 2 === substr_count( $y4api, 'self::audit_pii_read(' ) && false !== strpos( $y4api, "'partner_api_pii_read'" ) );

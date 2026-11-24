<?php
/**
 * N03: one consent model for both field kinds. PassCaptain\Consents (checkbox fields with consent=1,
 * append-only `wpcap_consent_log`) is the entry point; Sales\Consents (field type `consent`,
 * `wpcap_consents`) stays the store of its records. No consent is logged twice, the order portal shows
 * one consent card, the admin order detail lists and withdraws both kinds, the event CSV contains both,
 * the ticket page attendee form shows no consent field, and stored rows and hook payloads keep their shape.
 */

// phpcs:ignoreFile

use PassCaptain\Consents;
use PassCaptain\Orders;
use PassCaptain\TicketPage;
use PassCaptain\Sales\Consents as SalesConsents;

if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $a = -1 ) { return 'n'; } }
if ( ! function_exists( 'wp_verify_nonce' ) ) { function wp_verify_nonce( $n, $a = -1 ) { return 'n' === $n ? 1 : false; } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = null ) { return esc_attr( $t ); } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce" value="n">'; } }
if ( ! function_exists( 'wp_nonce_url' ) ) { function wp_nonce_url( $u, $a = -1 ) { return $u . '&_wpnonce=n'; } }
if ( ! function_exists( 'wp_timezone_string' ) ) { function wp_timezone_string() { return 'UTC'; } }

echo "N03: one consent model (Consents + Sales\\Consents)\n";
global $wpdb;
$p = $wpdb->prefix;
SalesConsents::register();

$n03_type = make_type( 20, 10, 0 );
$n03_event = (int) $wpdb->get_var( "SELECT o.event_id FROM {$p}wpcap_ticket_types tt JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=$n03_type" );
// Logged kind (checkbox + consent=1).
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $n03_type, 'field_key' => 'news', 'label' => 'N03 Newsletter', 'field_type' => 'checkbox', 'required' => 0, 'consent' => 1, 'purpose' => 'Monatlicher Newsletter', 'position' => 1, 'status' => 'active' ) );
$n03_news = (int) $wpdb->insert_id;
// Record kind (field type consent).
$n03_photo = PassCaptain\Admin\EventsPage::save_participant_field( array( 'ticket_type_id' => $n03_type, 'field_key' => 'photo', 'label' => 'N03 Fotos', 'field_type' => 'consent', 'required' => '0', 'consent_text' => 'N03 Fotos sind ok.', 'consent_version' => '1' ) );
$n03_photo = is_array( $n03_photo ) ? (int) $n03_photo['field_id'] : 0;
// A legacy row flagged both ways (the editor refuses it; data from imports could carry it).
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $n03_type, 'field_key' => 'both', 'label' => 'N03 Beides', 'field_type' => 'consent', 'required' => 0, 'consent' => 1, 'purpose' => 'Zweck', 'consent_text' => 'N03 Beides ok.', 'consent_version' => '1', 'position' => 3, 'status' => 'active' ) );
$n03_both = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $n03_type, 'field_key' => 'company', 'label' => 'N03 Firma', 'field_type' => 'text', 'required' => 0, 'position' => 4, 'status' => 'active' ) );

check( 'N03 field kinds: the log owns only consent=1 fields that are not of type consent', $n03_photo > 0 && array( $n03_news ) === array_map( static fn( $f ) => (int) $f->field_id, Consents::fields_for_type( $n03_type ) ) && isset( SalesConsents::fields( $n03_type )[ $n03_both ] ) && isset( SalesConsents::fields( $n03_type )[ $n03_photo ] ) );

$n03_hooks = array();
$n03_g = static function ( ...$a ) use ( &$n03_hooks ) { $n03_hooks[] = array( 'granted', $a ); };
$n03_w = static function ( ...$a ) use ( &$n03_hooks ) { $n03_hooks[] = array( 'withdrawn', $a ); };
add_action( 'wpcap_consent_granted', $n03_g, 10, 2 );
add_action( 'wpcap_consent_withdrawn', $n03_w, 10, 2 );

$n03_r = Orders::create( array( 'email' => 'n03-buyer@example.com', 'items' => array( array( 'ticket_type_id' => $n03_type, 'quantity' => 1 ) ), 'attendees' => array(
	array( 'ticket_type_id' => $n03_type, 'name' => 'Nina', 'answers' => array( 'news' => '1', 'photo' => '1', 'both' => '1', 'company' => 'ACME' ) ),
), 'idempotency_key' => 'n03-' . uniqid() ), array( 'skip_rate_limit' => true ) );
check( 'N03 checkout with both kinds is issued', is_array( $n03_r ) && 'complete' === $n03_r['status'] );
$n03_order_id = (int) $n03_r['order_id'];
$n03_order = Orders::get( $n03_order_id );
$n03_ticket = tickets( $n03_order_id )[0];
$n03_log = $wpdb->get_results( "SELECT * FROM {$p}wpcap_consent_log WHERE order_id=$n03_order_id ORDER BY log_id" );
$n03_records = SalesConsents::for_order( $n03_order_id );
check( 'N03 each consent is stored once: logged field in the log, type consent (also the double-flagged one) as record only', 1 === count( $n03_log ) && $n03_news === (int) $n03_log[0]->field_id && 2 === count( $n03_records ) && array( $n03_photo, $n03_both ) === array_map( static fn( $r ) => (int) $r->field_id, $n03_records ) );
check( 'N03 stored shapes unchanged: log row with text version hash, record with text, version and clear subject', 64 === strlen( (string) $n03_log[0]->text_version ) && 'granted' === $n03_log[0]->action && 'N03 Fotos sind ok.' === $n03_records[0]->consent_text && '1' === $n03_records[0]->text_version && 'n03-buyer@example.com' === $n03_records[0]->subject_email && null === $n03_records[0]->withdrawn_at );
$n03_granted = array_values( array_filter( $n03_hooks, static fn( $h ) => 'granted' === $h[0] ) );
check( 'N03 wpcap_consent_granted keeps its record array (log only)', 1 === count( $n03_granted ) && is_array( $n03_granted[0][1][0] ) && 'news' === $n03_granted[0][1][0]['field_key'] && isset( $n03_granted[0][1][0]['log_id'], $n03_granted[0][1][0]['text_version'], $n03_granted[0][1][0]['email'] ) );

// ---- Portal: one card for both kinds.
$n03_tickets = $wpdb->get_results( $wpdb->prepare( "SELECT t.ticket_id,t.public_id,t.ticket_type_id,t.status,'N03' AS title FROM {$p}wpcap_tickets t WHERE t.order_id=%d", $n03_order_id ) );
$n03_html = Consents::portal_html( $n03_order, $n03_tickets, Consents::result_notice( 'granted' ) );
check( 'N03 portal: one consent section with one heading (no duplicate id)', 1 === substr_count( $n03_html, '<section' ) && 1 === substr_count( $n03_html, 'id="wpcap-consents-title"' ) && 1 === substr_count( $n03_html, 'id="wpcap-consents"' ) && 1 === substr_count( $n03_html, '<h2' ) );
check( 'N03 portal: the card shows both kinds with their own forms and the result notice', str_contains( $n03_html, 'N03 Newsletter' ) && str_contains( $n03_html, 'name="wpcap_consent_op" value="withdraw"' ) && str_contains( $n03_html, 'N03 Fotos sind ok.' ) && str_contains( $n03_html, 'name="wpcap_consent_withdraw" value="' . (int) $n03_records[0]->consent_id . '"' ) && str_contains( $n03_html, 'Deine Einwilligung wurde gespeichert' ) );
check( 'N03 portal: no second withdrawal hint inside the shared card', ! str_contains( $n03_html, 'Du kannst erteilte Einwilligungen jederzeit' ) && str_contains( SalesConsents::portal_section( $n03_order ), 'Du kannst erteilte Einwilligungen jederzeit' ) );
check( 'N03 portal: only logged consents → same card as before', Consents::portal_html( $n03_order, $n03_tickets ) !== '' && '' === Consents::portal_html( (object) array( 'order_id' => 0, 'public_id' => 'x', 'customer_email' => 'x@example.com' ), array() ) );
$n03_portal_src = (string) file_get_contents( WPCAP_PATH . 'src/OrderPortal.php' );
check( 'N03 portal page renders only the shared card (Sales\\Consents::portal_section no longer echoed)', str_contains( $n03_portal_src, 'Consents::portal_html(' ) && ! str_contains( $n03_portal_src, 'Sales\\Consents::portal_section' ) && ! str_contains( $n03_portal_src, 'Consents::self_service_html(' ) );

// ---- Ticket page attendee form: no consent field of either kind.
$n03_tp_ticket = $wpdb->get_row( $wpdb->prepare( "SELECT t.*,o.starts_at FROM {$p}wpcap_tickets t JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE t.ticket_id=%d", (int) $n03_ticket->ticket_id ) );
$n03_form = TicketPage::attendee_html( $n03_tp_ticket );
check( 'N03 ticket page attendee form: no input for consent fields (logged or type consent), other fields stay', str_contains( $n03_form, 'wpcap_answer[company]' ) && ! str_contains( $n03_form, 'wpcap_answer[photo]' ) && ! str_contains( $n03_form, 'wpcap_answer[both]' ) && ! str_contains( $n03_form, 'wpcap_answer[news]' ) );

// ---- Admin: one section lists both kinds; withdrawal of a record goes to its own store.
ob_start();
Consents::render_admin_section( $n03_order, array( (object) array( 'ticket_id' => (int) $n03_ticket->ticket_id, 'public_id' => 'pubN03' ) ) );
$n03_admin = (string) ob_get_clean();
check( 'N03 admin order detail: one section with log and record consents, a withdraw form each', 1 === substr_count( $n03_admin, 'id="wpcap-consents"' ) && str_contains( $n03_admin, 'N03 Newsletter' ) && str_contains( $n03_admin, 'N03 Fotos' ) && str_contains( $n03_admin, 'name="consent_id" value="' . (int) $n03_records[0]->consent_id . '"' ) && str_contains( $n03_admin, 'name="field_id" value="' . $n03_news . '"' ) );
$n03_other = Orders::create( array( 'email' => 'n03-other@example.com', 'items' => array( array( 'ticket_type_id' => $n03_type, 'quantity' => 1 ) ), 'idempotency_key' => 'n03-o-' . uniqid() ), array( 'skip_rate_limit' => true ) );
$n03_wrong = Consents::admin_withdraw( array( 'order_id' => (int) $n03_other['order_id'], 'consent_id' => (int) $n03_records[0]->consent_id ) );
check( 'N03 admin withdrawal of a record checks that it belongs to the order', is_wp_error( $n03_wrong ) && null === SalesConsents::get( (int) $n03_records[0]->consent_id )->withdrawn_at );
$n03_hooks = array();
$n03_admin_w = Consents::admin_withdraw( array( 'order_id' => $n03_order_id, 'consent_id' => (int) $n03_records[0]->consent_id ) );
$n03_after = SalesConsents::get( (int) $n03_records[0]->consent_id );
check( 'N03 admin withdrawal of a record: kept with time and source admin, answer deleted, log untouched', is_array( $n03_admin_w ) && null !== $n03_after->withdrawn_at && 'admin' === $n03_after->withdrawn_source && null === $wpdb->get_var( "SELECT answer FROM {$p}wpcap_participant_answers WHERE ticket_id=" . (int) $n03_ticket->ticket_id . " AND field_id=$n03_photo" ) && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_consent_log WHERE order_id=$n03_order_id" ) );
check( 'N03 wpcap_consent_withdrawn of a record keeps its ( consent_id, row ) arguments', 1 === count( $n03_hooks ) && 'withdrawn' === $n03_hooks[0][0] && (int) $n03_records[0]->consent_id === $n03_hooks[0][1][0] && is_object( $n03_hooks[0][1][1] ) && isset( $n03_hooks[0][1][1]->consent_text ) );
$n03_hooks = array();
$n03_admin_l = Consents::admin_withdraw( array( 'order_id' => $n03_order_id, 'ticket_id' => (int) $n03_ticket->ticket_id, 'field_id' => $n03_news, 'note' => 'N03 Telefon' ) );
check( 'N03 admin withdrawal of a logged consent unchanged (log row, record array hook)', is_array( $n03_admin_l ) && ! Consents::is_granted( (int) $n03_ticket->ticket_id, $n03_news ) && 1 === count( $n03_hooks ) && is_array( $n03_hooks[0][1][0] ) && 'admin' === $n03_hooks[0][1][0]['source'] );
check( 'N03 admin source labels cover record sources', 'Inhaberwechsel' === Consents::source_label( 'holder_changed' ) && 'Einwilligungslink' === Consents::source_label( 'attendee_link' ) );

// ---- Event CSV: both kinds, header unchanged.
$n03_csv = Consents::export_rows( $n03_event );
$n03_data = is_array( $n03_csv ) ? array_slice( $n03_csv, 1 ) : array();
$n03_photo_rows = array_values( array_filter( $n03_data, static fn( $r ) => 'photo' === $r[6] ) );
check( 'N03 CSV: header with 15 columns unchanged', is_array( $n03_csv ) && 15 === count( $n03_csv[0] ) && 'Textversion' === $n03_csv[0][9] && 'Aktueller Stand' === $n03_csv[0][14] );
check( 'N03 CSV: a record yields a grant and a withdrawal row; current state on the last one', 2 === count( $n03_photo_rows ) && 'granted' === $n03_photo_rows[0][10] && 'checkout' === $n03_photo_rows[0][11] && '' === $n03_photo_rows[0][14] && 'withdrawn' === $n03_photo_rows[1][10] && 'admin' === $n03_photo_rows[1][11] && 'withdrawn' === $n03_photo_rows[1][14] && 'N03 Fotos sind ok.' === $n03_photo_rows[0][8] && '1' === $n03_photo_rows[0][9] );
check( 'N03 CSV: log rows still included, no e-mail in clear (keyed hash as person reference)', 2 === count( array_filter( $n03_data, static fn( $r ) => 'news' === $r[6] ) ) && 1 === count( array_filter( $n03_data, static fn( $r ) => 'both' === $r[6] ) ) && false === strpos( wp_json_encode( $n03_csv ), '@example.com' ) && substr( Consents::subject_hash( 'n03-buyer@example.com' ), 0, 16 ) === $n03_photo_rows[0][13] );

// ---- Webhook payloads of both kinds keep their shape.
$n03_wh_log = ( new ReflectionMethod( PassCaptain\Modules\Webhooks::class, 'consent_event' ) );
$n03_wh_log->setAccessible( true );
$n03_e1 = $n03_wh_log->invoke( null, 'consent.withdrawn', $n03_hooks[0][1][0], null );
$n03_e2 = $n03_wh_log->invoke( null, 'consent.withdrawn', (int) $n03_after->consent_id, $n03_after );
check( 'N03 webhooks: consent ids keep their prefixes (log:/consent:) and the same keys', is_array( $n03_e1 ) && is_array( $n03_e2 ) && 0 === strpos( $n03_e1[1]['consent_id'], 'log:' ) && 0 === strpos( $n03_e2[1]['consent_id'], 'consent:' ) && array_keys( $n03_e1[1] ) === array_keys( $n03_e2[1] ) && 'admin' === $n03_e2[1]['source'] );

check( 'N03 both classes stay @internal (A402)', str_contains( (string) file_get_contents( WPCAP_PATH . 'src/Consents.php' ), '@internal Not part of the public add-on API (A402)' ) && str_contains( (string) file_get_contents( WPCAP_PATH . 'src/Sales/Consents.php' ), '@internal Not part of the public add-on API (A402)' ) );

remove_action( 'wpcap_consent_granted', $n03_g, 10 );
remove_action( 'wpcap_consent_withdrawn', $n03_w, 10 );

<?php
/**
 * A116: participant fields as consents – proof records (text version, locale, time, source, no IP in
 * clear), withdrawal (self-service with nonce, admin), hooks, admin export with audit, privacy export
 * and erasure (person reference removed, minimal proof kept), server-side validation of required
 * consents (checkout, REST, WooCommerce, box office).
 */

// phpcs:ignoreFile

use PassCaptain\Consents;
use PassCaptain\Installer;
use PassCaptain\Orders;
use PassCaptain\OrderPortal;
use PassCaptain\TicketPage;
use PassCaptain\Privacy;

if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $a = -1 ) { return 'n'; } }
if ( ! function_exists( 'wp_verify_nonce' ) ) { function wp_verify_nonce( $n, $a = -1 ) { return 'n' === $n ? 1 : false; } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = null ) { return esc_attr( $t ); } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce" value="n">'; } }
if ( ! function_exists( 'wp_nonce_url' ) ) { function wp_nonce_url( $u, $a = -1 ) { return $u . '&_wpnonce=n'; } }
if ( ! function_exists( 'wp_timezone_string' ) ) { function wp_timezone_string() { return 'UTC'; } }

echo "A116: consent records, withdrawal, export and erasure\n";
global $wpdb;
$p = $wpdb->prefix;

check( 'A116 schema: DB version 3.8.8, consent column and both tables, ready()', version_compare( Installer::DB_VERSION, '3.8.8', '>=' ) && null !== $wpdb->get_row( "SHOW COLUMNS FROM {$p}wpcap_participant_fields LIKE 'consent'" ) && null !== $wpdb->get_row( "SHOW COLUMNS FROM {$p}wpcap_consent_log LIKE 'subject_hash'" ) && null !== $wpdb->get_row( "SHOW COLUMNS FROM {$p}wpcap_consent_texts LIKE 'text_version'" ) && Consents::ready() );
check( 'A116 schema: the log keeps no IP address column in clear', null === $wpdb->get_row( "SHOW COLUMNS FROM {$p}wpcap_consent_log LIKE 'ip'" ) && null !== $wpdb->get_row( "SHOW COLUMNS FROM {$p}wpcap_consent_log LIKE 'ip_hash'" ) );

$a116_type = make_type( 20, 10, 0 );
$a116_event = (int) $wpdb->get_var( "SELECT o.event_id FROM {$p}wpcap_ticket_types tt JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=$a116_type" );
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $a116_type, 'field_key' => 'company', 'label' => 'Firma', 'field_type' => 'text', 'required' => 0, 'position' => 1, 'status' => 'active' ) );
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $a116_type, 'field_key' => 'photos', 'label' => 'Fotos', 'field_type' => 'checkbox', 'required' => 1, 'consent' => 1, 'purpose' => 'Veröffentlichung von Fotos auf der Website', 'position' => 2, 'status' => 'active' ) );
$a116_photos = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $a116_type, 'field_key' => 'newsletter', 'label' => 'Newsletter', 'field_type' => 'checkbox', 'required' => 0, 'consent' => 1, 'purpose' => 'Monatlicher Newsletter des Veranstalters', 'position' => 3, 'status' => 'active' ) );
$a116_news = (int) $wpdb->insert_id;

$GLOBALS['a116_hooks'] = array();
add_action( 'wpcap_consent_granted', static function ( $r ) { $GLOBALS['a116_hooks'][] = array( 'granted', $r ); } );
add_action( 'wpcap_consent_withdrawn', static function ( $r ) { $GLOBALS['a116_hooks'][] = array( 'withdrawn', $r ); } );
$a116_log = static function ( $where = '1=1' ) use ( $wpdb, $p ) { return $wpdb->get_results( "SELECT * FROM {$p}wpcap_consent_log WHERE $where ORDER BY log_id" ); };
$a116_end = static function ( $where ) use ( $a116_log ) { $rows = $a116_log( $where ); return $rows ? $rows[ count( $rows ) - 1 ] : null; };
$a116_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_consent_log" );

// ---- 5. Server-side validation of required consents.
$a116_items = array( array( 'ticket_type_id' => $a116_type, 'quantity' => 1 ) );
$a116_r = Orders::create( array( 'email' => 'a116-none@example.com', 'items' => $a116_items, 'idempotency_key' => 'a116-v1-' . uniqid() ) );
check( 'A116 checkout/REST: no attendee data → required consent rejected', is_wp_error( $a116_r ) && 'wpcap_attendee_field_required' === $a116_r->get_error_code() );
foreach ( array( '' => 'empty', '0' => '"0"' ) as $a116_v => $a116_label ) {
	$a116_r = Orders::create( array( 'email' => 'a116-v@example.com', 'items' => $a116_items, 'attendees' => array( array( 'ticket_type_id' => $a116_type, 'name' => 'X', 'answers' => array( 'photos' => (string) $a116_v ) ) ), 'idempotency_key' => 'a116-v2-' . uniqid() ) );
	check( "A116 checkout/REST: required consent $a116_label is not ticked", is_wp_error( $a116_r ) && 'wpcap_attendee_field_required' === $a116_r->get_error_code() );
}
$a116_r = Orders::create( array( 'email' => 'a116-pos@example.com', 'items' => $a116_items, 'attendees' => array( array( 'ticket_type_id' => $a116_type, 'name' => 'Kasse', 'answers' => array( 'company' => 'ACME' ) ) ), 'idempotency_key' => 'a116-v3-' . uniqid() ), array( 'source' => 'pos', 'key_namespace' => 'pos', 'skip_rate_limit' => true ) );
check( 'A116 box office (source pos): missing required consent rejected', is_wp_error( $a116_r ) && 'wpcap_attendee_field_required' === $a116_r->get_error_code() );
$a116_r = Orders::create( array( 'email' => 'a116-wc@example.com', 'items' => $a116_items, 'attendees' => array( array( 'ticket_type_id' => $a116_type, 'answers' => array( 'photos' => '' ) ) ), 'idempotency_key' => 'a116-v4-' . uniqid() ), array( 'external' => true, 'source' => 'woocommerce', 'key_namespace' => 'woocommerce' ) );
check( 'A116 WooCommerce order: missing required consent rejected by the core', is_wp_error( $a116_r ) && 'wpcap_attendee_field_required' === $a116_r->get_error_code() );
$a116_wc = PassCaptain\Integrations\WooCommerce\Attendees::normalize( array( $a116_type => array( array( 'name' => 'W', 'answers' => array( 'photos' => '' ) ) ) ), array( $a116_type => 1 ) );
check( 'A116 WooCommerce checkout fields: missing required consent rejected', is_wp_error( $a116_wc ) && 'wpcap_attendee_field_required' === $a116_wc->get_error_code() );
$a116_wc = PassCaptain\Integrations\WooCommerce\Attendees::normalize( array( $a116_type => array( array( 'name' => 'W', 'answers' => array( 'photos' => '1' ) ) ) ), array( $a116_type => 1 ) );
check( 'A116 WooCommerce checkout fields: ticked required consent accepted', is_array( $a116_wc ) && '1' === ( $a116_wc[0]['answers']['photos'] ?? '' ) );
$a116_skip = Orders::validate_attendees( array( $a116_type => 1 ), array( array( 'ticket_type_id' => $a116_type, 'answers' => array( 'company' => 'Neu' ) ) ), array( 'skip_consent' => true ) );
check( 'A116 portal corrections skip consent fields (handled by grant/withdraw)', is_array( $a116_skip ) && array( 'company' ) === array_values( array_map( static fn( $id ) => (string) $wpdb->get_var( "SELECT field_key FROM {$p}wpcap_participant_fields WHERE field_id=" . (int) $id ), array_keys( $a116_skip[0]['answers'] ) ) ) );
check( 'A116 nothing was logged for rejected orders', $a116_count === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_consent_log" ) );

// ---- 1. Proof records from the checkout.
$_SERVER['REMOTE_ADDR'] = '203.0.113.77';
$a116_r = Orders::create( array( 'email' => 'a116-buyer@example.com', 'items' => array( array( 'ticket_type_id' => $a116_type, 'quantity' => 2 ) ), 'attendees' => array(
	array( 'ticket_type_id' => $a116_type, 'name' => 'Ada', 'email' => 'ada.a116@example.com', 'answers' => array( 'photos' => '1', 'newsletter' => '1', 'company' => 'ACME' ) ),
	array( 'ticket_type_id' => $a116_type, 'name' => 'Bob', 'answers' => array( 'photos' => 'yes' ) ),
), 'idempotency_key' => 'a116-ok-' . uniqid() ) );
check( 'A116 checkout with ticked consents is accepted and issued', is_array( $a116_r ) && 'complete' === $a116_r['status'] );
$a116_order = (int) $a116_r['order_id'];
$a116_tickets = array_map( static fn( $t ) => (int) $t->ticket_id, tickets( $a116_order ) );
$a116_rows = $a116_log( "order_id=$a116_order" );
$a116_v_photo = Consents::text_version( 'Fotos', 'Veröffentlichung von Fotos auf der Website' );
check( 'A116 three grants logged (2× photos, 1× newsletter) with source checkout', 3 === count( $a116_rows ) && ! array_filter( $a116_rows, static fn( $r ) => 'granted' !== $r->action || 'checkout' !== $r->source ) );
$a116_ada = array_values( array_filter( $a116_rows, static fn( $r ) => (int) $r->ticket_id === $a116_tickets[0] && (int) $r->field_id === $a116_photos ) )[0] ?? null;
check( 'A116 record names the text version (SHA-256 of label + purpose) and the text row', $a116_ada && $a116_v_photo === $a116_ada->text_version && 'Veröffentlichung von Fotos auf der Website' === (string) $wpdb->get_var( "SELECT purpose FROM {$p}wpcap_consent_texts WHERE text_id=" . (int) $a116_ada->text_id ) );
check( 'A116 record has locale, timestamp and the attendee as keyed hash', $a116_ada && 'de_DE' === $a116_ada->locale && '' !== $a116_ada->created_at && Consents::subject_hash( 'ada.a116@example.com' ) === $a116_ada->subject_hash && 64 === strlen( $a116_ada->subject_hash ) );
$a116_bob = array_values( array_filter( $a116_rows, static fn( $r ) => (int) $r->ticket_id === $a116_tickets[1] ) )[0] ?? null;
check( 'A116 attendee without e-mail: the buyer is the person reference', $a116_bob && Consents::subject_hash( 'a116-buyer@example.com' ) === $a116_bob->subject_hash );
check( 'A116 IP only as keyed hash, never in clear', $a116_ada && Consents::ip_hash( '203.0.113.77' ) === $a116_ada->ip_hash && false === strpos( wp_json_encode( $a116_rows ), '203.0.113.77' ) );
$a116_granted_hooks = array_values( array_filter( $GLOBALS['a116_hooks'], static fn( $h ) => 'granted' === $h[0] && $a116_order === $h[1]['order_id'] ) );
check( 'A116 wpcap_consent_granted fired once per grant after commit, with e-mail and text version', 3 === count( $a116_granted_hooks ) && in_array( 'ada.a116@example.com', array_map( static fn( $h ) => $h[1]['email'], $a116_granted_hooks ), true ) && $a116_v_photo === $a116_granted_hooks[0][1]['text_version'] );
check( 'A116 state derived from the log: granted', Consents::is_granted( $a116_tickets[0], $a116_news ) && ! Consents::is_granted( $a116_tickets[1], $a116_news ) );
check( 'A116 the order keeps no attendee copy (with consent proof) after issuance', null === $wpdb->get_var( "SELECT attendee_data FROM {$p}wpcap_orders WHERE order_id=$a116_order" ) );

// ---- Box office and API sources.
$a116_pos = Orders::create( array( 'email' => 'a116-pos2@example.com', 'items' => $a116_items, 'attendees' => array( array( 'ticket_type_id' => $a116_type, 'name' => 'Kasse', 'answers' => array( 'photos' => '1' ) ) ), 'idempotency_key' => 'a116-pos-' . uniqid() ), array( 'source' => 'pos', 'key_namespace' => 'pos', 'skip_rate_limit' => true ) );
$a116_pos_row = is_array( $a116_pos ) ? ( $a116_log( 'order_id=' . (int) $a116_pos['order_id'] )[0] ?? null ) : null;
check( 'A116 box office sale: grant logged with source box_office, no IP hash', $a116_pos_row && 'box_office' === $a116_pos_row->source && '' === $a116_pos_row->ip_hash );
check( 'A116 channel sources map to consent sources', 'api' === Consents::source_for_channel( 'partner' ) && 'checkout' === Consents::source_for_channel( 'native' ) && 'woocommerce' === Consents::source_for_channel( 'woocommerce' ) );

// ---- Editor: consent fields need checkbox + purpose; a text change creates a new version.
$a116_bad = PassCaptain\Admin\EventsPage::save_participant_field( array( 'ticket_type_id' => $a116_type, 'field_key' => 'x_consent', 'label' => 'X', 'field_type' => 'text', 'consent' => '1', 'purpose' => 'Zweck' ) );
check( 'A116 editor: consent must be a checkbox', is_wp_error( $a116_bad ) && 'wpcap_field_consent_type' === $a116_bad->get_error_code() );
$a116_bad = PassCaptain\Admin\EventsPage::save_participant_field( array( 'ticket_type_id' => $a116_type, 'field_key' => 'x_consent', 'label' => 'X', 'field_type' => 'checkbox', 'consent' => '1', 'purpose' => '' ) );
check( 'A116 editor: consent needs a purpose', is_wp_error( $a116_bad ) && 'wpcap_field_consent_purpose' === $a116_bad->get_error_code() );
$a116_texts_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_consent_texts WHERE field_id=$a116_news" );
$a116_saved = PassCaptain\Admin\EventsPage::save_participant_field( array( 'field_id' => $a116_news, 'label' => 'Newsletter', 'field_type' => 'checkbox', 'consent' => '1', 'purpose' => 'Newsletter mit Veranstaltungstipps (monatlich)', 'retention_days' => 365, 'position' => 3 ) );
$a116_news_v2 = Consents::text_version( 'Newsletter', 'Newsletter mit Veranstaltungstipps (monatlich)' );
check( 'A116 editor: changed consent text is stored as a new version, the old one is kept', is_array( $a116_saved ) && $a116_texts_before + 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_consent_texts WHERE field_id=$a116_news" ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_consent_texts WHERE field_id=%d AND text_version=%s", $a116_news, $a116_news_v2 ) ) && '1' === (string) $wpdb->get_var( "SELECT consent FROM {$p}wpcap_participant_fields WHERE field_id=$a116_news" ) );
check( 'A116 editor: text change is audited with the version', false !== strpos( (string) $wpdb->get_var( "SELECT details FROM {$p}wpcap_audit_logs WHERE action='participant_field_updated' ORDER BY audit_id DESC LIMIT 1" ), $a116_news_v2 ) );

// ---- 2. Self-service withdrawal (order portal) and re-grant (ticket page).
$a116_order_row = $wpdb->get_row( "SELECT * FROM {$p}wpcap_orders WHERE order_id=$a116_order" );
$a116_t0 = $wpdb->get_row( "SELECT t.*,'Konzert — Standard' AS event_name,'Standard' AS ticket_name FROM {$p}wpcap_tickets t WHERE ticket_id=" . $a116_tickets[0] );
$a116_post = array( '_wpcap_consent_nonce' => 'n', 'wpcap_consent_op' => 'withdraw', 'wpcap_consent_ticket' => $a116_t0->public_id, 'wpcap_consent_field' => 'newsletter' );
$a116_bad = OrderPortal::handle_consent( $a116_order_row, array( '_wpcap_consent_nonce' => 'forged' ) + $a116_post );
check( 'A116 portal: withdrawal without valid nonce is refused, nothing logged', is_wp_error( $a116_bad ) && 'wpcap_consent_nonce' === $a116_bad->get_error_code() && Consents::is_granted( $a116_tickets[0], $a116_news ) );
$GLOBALS['a116_hooks'] = array();
$a116_res = OrderPortal::handle_consent( $a116_order_row, $a116_post );
$a116_last = $a116_log( 'ticket_id=' . $a116_tickets[0] . " AND field_id=$a116_news" );
check( 'A116 portal: withdrawal logged (append-only: grant row unchanged), state withdrawn', 'withdrawn' === $a116_res && 2 === count( $a116_last ) && 'granted' === $a116_last[0]->action && 'withdrawn' === $a116_last[1]->action && 'portal' === $a116_last[1]->source && ! Consents::is_granted( $a116_tickets[0], $a116_news ) );
check( 'A116 portal: withdrawal refers to the granted text version and the same person', $a116_last[0]->text_version === $a116_last[1]->text_version && $a116_last[0]->subject_hash === $a116_last[1]->subject_hash );
check( 'A116 portal: ticked answer removed with the withdrawal', null === $wpdb->get_var( "SELECT answer FROM {$p}wpcap_participant_answers WHERE ticket_id=" . $a116_tickets[0] . " AND field_id=$a116_news" ) );
check( 'A116 wpcap_consent_withdrawn fired with source and e-mail', 1 === count( $GLOBALS['a116_hooks'] ) && 'withdrawn' === $GLOBALS['a116_hooks'][0][0] && 'portal' === $GLOBALS['a116_hooks'][0][1]['source'] && 'ada.a116@example.com' === $GLOBALS['a116_hooks'][0][1]['email'] && 'newsletter' === $GLOBALS['a116_hooks'][0][1]['field_key'] );
check( 'A116 withdrawal is audited', (bool) $wpdb->get_var( "SELECT audit_id FROM {$p}wpcap_audit_logs WHERE action='consent_withdrawn' AND object_id=" . $a116_tickets[0] ) );
$a116_again = OrderPortal::handle_consent( $a116_order_row, $a116_post );
check( 'A116 second withdrawal is refused and not logged twice', is_wp_error( $a116_again ) && 'wpcap_consent_unchanged' === $a116_again->get_error_code() && 2 === count( $a116_log( 'ticket_id=' . $a116_tickets[0] . " AND field_id=$a116_news" ) ) );
$a116_other = OrderPortal::handle_consent( $a116_order_row, array( 'wpcap_consent_ticket' => 'ffffffffffffffffffffffffffffffff' ) + $a116_post );
check( 'A116 portal: tickets of other orders cannot be managed', is_wp_error( $a116_other ) && 'wpcap_consent_invalid' === $a116_other->get_error_code() );
$a116_regrant = TicketPage::handle_consent( $a116_t0, array( 'wpcap_consent_op' => 'grant' ) + $a116_post );
$a116_last = $a116_log( 'ticket_id=' . $a116_tickets[0] . " AND field_id=$a116_news" );
check( 'A116 ticket page: re-grant logged with source ticket_page and the current text version', 'granted' === $a116_regrant && 3 === count( $a116_last ) && 'ticket_page' === $a116_last[2]->source && $a116_news_v2 === $a116_last[2]->text_version && Consents::is_granted( $a116_tickets[0], $a116_news ) && '1' === (string) $wpdb->get_var( "SELECT answer FROM {$p}wpcap_participant_answers WHERE ticket_id=" . $a116_tickets[0] . " AND field_id=$a116_news" ) );
$a116_html = TicketPage::consent_html( $a116_t0, 'granted' );
check( 'A116 ticket page card: state, withdraw button with nonce and result notice', false !== strpos( $a116_html, 'Einwilligung widerrufen' ) && false !== strpos( $a116_html, 'name="_wpcap_consent_nonce" value="n"' ) && false !== strpos( $a116_html, 'Deine Einwilligung wurde gespeichert' ) && false !== strpos( $a116_html, 'Erteilt am' ) );
$a116_html = Consents::self_service_html( array( (object) array( 'ticket_id' => $a116_tickets[1], 'public_id' => 'x', 'ticket_type_id' => $a116_type, 'status' => 'valid', 'title' => 'T' ) ), 'x' );
check( 'A116 portal card: a consent not given can be granted', false !== strpos( $a116_html, 'Nicht erteilt' ) && false !== strpos( $a116_html, 'Einwilligung erteilen' ) );

// Portal corrections leave consents alone, even a withdrawn required one.
$a116_token = str_repeat( 'ab', 32 );
$wpdb->update( "{$p}wpcap_orders", array( 'access_token_hash' => hash( 'sha256', $a116_token ), 'access_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ), array( 'order_id' => $a116_order ) );
$a116_t1 = $wpdb->get_row( "SELECT * FROM {$p}wpcap_tickets WHERE ticket_id=" . $a116_tickets[1] );
Consents::withdraw( $a116_tickets[1], $a116_photos, 'portal' );
$a116_assign = PassCaptain\TicketAssignments::assign( $a116_order_row->public_id, $a116_token, $a116_t1->public_id, 'Bob Korrigiert', '', array( 'company' => 'Neu GmbH' ) );
check( 'A116 portal assignment: name/answer correction works without re-ticking a withdrawn required consent', is_array( $a116_assign ) && ! Consents::is_granted( $a116_tickets[1], $a116_photos ) );

// ---- 3. Admin: withdrawal, history section and CSV export with audit entry.
$GLOBALS['a116_hooks'] = array();
$a116_admin = Consents::admin_withdraw( array( 'order_id' => $a116_order, 'ticket_id' => $a116_tickets[0], 'field_id' => $a116_photos, 'note' => 'per E-Mail' ) );
$a116_last = $a116_log( 'ticket_id=' . $a116_tickets[0] . " AND field_id=$a116_photos" );
check( 'A116 admin withdrawal logged with source admin, note and acting user', is_array( $a116_admin ) && 'withdrawn' === end( $a116_last )->action && 'admin' === end( $a116_last )->source && 'per E-Mail' === end( $a116_last )->note && 1 === (int) end( $a116_last )->actor_user_id && 1 === count( $GLOBALS['a116_hooks'] ) );
$a116_wrong = Consents::admin_withdraw( array( 'order_id' => (int) $a116_pos['order_id'], 'ticket_id' => $a116_tickets[0], 'field_id' => $a116_photos ) );
check( 'A116 admin withdrawal checks that the ticket belongs to the order', is_wp_error( $a116_wrong ) );
ob_start();
Consents::render_admin_section( $a116_order_row, array_map( static fn( $id ) => (object) array( 'ticket_id' => $id, 'public_id' => 'pub' . $id ), $a116_tickets ) );
$a116_admin_html = (string) ob_get_clean();
check( 'A116 admin order detail: state, history with source and a withdraw form for granted consents', false !== strpos( $a116_admin_html, 'Einwilligungen' ) && false !== strpos( $a116_admin_html, 'Widerrufen' ) && false !== strpos( $a116_admin_html, 'Bestellübersicht' ) && false !== strpos( $a116_admin_html, 'wpcap_consent_withdraw' ) && false !== strpos( $a116_admin_html, 'per E-Mail' ) );
$a116_csv = Consents::export_rows( $a116_event );
check( 'A116 CSV export: header plus one row per log entry of the event', is_array( $a116_csv ) && count( $a116_csv ) - 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_consent_log l JOIN {$p}wpcap_tickets t ON t.ticket_id=l.ticket_id WHERE t.ticket_type_id=$a116_type" ) && 'Textversion' === $a116_csv[0][9] );
check( 'A116 CSV export: current state marked, no e-mail addresses', in_array( 'withdrawn', array_column( array_slice( $a116_csv, 1 ), 14 ), true ) && false === strpos( wp_json_encode( $a116_csv ), '@example.com' ) );
check( 'A116 CSV export is audited', (bool) $wpdb->get_var( "SELECT audit_id FROM {$p}wpcap_audit_logs WHERE action='consents_exported' AND object_id=$a116_event" ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_orders' );
$a116_denied = Consents::export_rows( $a116_event );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'A116 CSV export requires the order capability for the event', is_wp_error( $a116_denied ) );

// ---- Exchange carry-over, transfer ends consents.
$a116_type2 = make_type( 5, 5, 0 );
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $a116_type2, 'field_key' => 'newsletter', 'label' => 'Newsletter', 'field_type' => 'checkbox', 'consent' => 1, 'purpose' => 'Newsletter', 'status' => 'active' ) );
$a116_x = Orders::create( array( 'email' => 'a116-x@example.com', 'items' => array( array( 'ticket_type_id' => $a116_type2, 'quantity' => 1 ) ), 'idempotency_key' => 'a116-x-' . uniqid() ) );
$a116_xt = tickets( (int) $a116_x['order_id'] )[0];
check( 'A116 exchange: granted consent carried over to the new ticket (source exchange)', 1 === Consents::carry_over( $a116_tickets[0], (int) $a116_xt->ticket_id, $a116_type2 ) && Consents::is_granted( (int) $a116_xt->ticket_id, (int) $wpdb->get_var( "SELECT field_id FROM {$p}wpcap_participant_fields WHERE ticket_type_id=$a116_type2" ) ) && 'exchange' === $a116_end( 'ticket_id=' . (int) $a116_xt->ticket_id )->source );
$GLOBALS['a116_hooks'] = array();
$a116_t0 = $wpdb->get_row( "SELECT * FROM {$p}wpcap_tickets WHERE ticket_id=" . $a116_tickets[0] );
$a116_tr = Orders::transfer_ticket( $a116_order_row->public_id, $a116_token, $a116_t0->public_id, 'friend.a116@example.com' );
$a116_last = $a116_end( 'ticket_id=' . $a116_tickets[0] . " AND field_id=$a116_news" );
check( 'A116 transfer: previous holder\'s consents end (source transfer), hook names the previous holder', ! is_wp_error( $a116_tr ) && 'withdrawn' === $a116_last->action && 'transfer' === $a116_last->source && Consents::subject_hash( 'ada.a116@example.com' ) === $a116_last->subject_hash && in_array( 'ada.a116@example.com', array_map( static fn( $h ) => $h[1]['email'], $GLOBALS['a116_hooks'] ), true ) );

// ---- 4. Privacy export and erasure.
$a116_export = Privacy::export( 'a116-buyer@example.com' );
$a116_flat = wp_json_encode( $a116_export['data'] );
check( 'A116 privacy export contains the consent history (text, version, source)', false !== strpos( $a116_flat, 'Einwilligungen' ) && false !== strpos( $a116_flat, $a116_v_photo ) && false !== strpos( $a116_flat, 'ticket_page' ) );
// Attendee-only erasure: Bob's ticket on Ada's (buyer) order gets an attendee e-mail first.
$wpdb->update( "{$p}wpcap_tickets", array( 'attendee_email' => 'carl.a116@example.com' ), array( 'ticket_id' => $a116_tickets[1] ) );
Consents::grant( $a116_tickets[1], $a116_photos, 'ticket_page', array( 'ip_hash' => Consents::ip_hash( '198.51.100.9' ) ) );
$GLOBALS['a116_hooks'] = array();
Privacy::erase( 'carl.a116@example.com' );
$a116_after = $a116_log( 'ticket_id=' . $a116_tickets[1] );
check( 'A116 erasure: running consents ended (privacy_erasure), hook without e-mail', 'withdrawn' === $a116_after[ count( $a116_after ) - 1 ]->action && 'privacy_erasure' === $a116_after[ count( $a116_after ) - 1 ]->source && 1 === count( $GLOBALS['a116_hooks'] ) && null === $GLOBALS['a116_hooks'][0][1]['email'] );
check( 'A116 erasure: person reference (e-mail hash, IP hash, note) removed from all records of the ticket', ! array_filter( $a116_after, static fn( $r ) => '' !== $r->subject_hash || '' !== $r->ip_hash || '' !== $r->note ) );
check( 'A116 erasure: minimal proof kept (field, text version, action, source, time)', count( $a116_after ) >= 3 && ! array_filter( $a116_after, static fn( $r ) => '' === $r->text_version || '' === $r->action || '' === $r->source || '' === $r->created_at ) );
check( 'A116 erasure: other tickets\' records untouched', '' !== (string) $wpdb->get_var( "SELECT subject_hash FROM {$p}wpcap_consent_log WHERE ticket_id=" . $a116_tickets[0] . ' ORDER BY log_id LIMIT 1' ) );

// ---- Docs.
$a116_docs = (string) file_get_contents( WPCAP_PATH . 'docs/HOOKS.md' ) . (string) file_get_contents( WPCAP_PATH . 'docs/BETREIBERPFLICHTEN.md' );
check( 'A116 hooks and the erasure decision are documented', false !== strpos( $a116_docs, '| `wpcap_consent_granted` |' ) && false !== strpos( $a116_docs, '| `wpcap_consent_withdrawn` |' ) && false !== strpos( $a116_docs, '### 5.3 Entscheidung: Datenschutz-Löschung und Nachweis' ) );

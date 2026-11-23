<?php
/**
 * A688: device list with offline package expiry; bulk check-in/out in the ticket list with confirmation,
 * result report and idempotent retry.
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

use PassCaptain\Admin\DevicesPage;
use PassCaptain\Admin\TicketsPage;
use PassCaptain\Clock;
use PassCaptain\OfflineScanner\Assignments;
use PassCaptain\Orders;

echo "A688: package expiry and bulk check-in/out\n";
global $wpdb;
$p = $wpdb->prefix;
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce" value="n">'; } }
if ( ! function_exists( 'wp_timezone_string' ) ) { function wp_timezone_string() { return 'Europe/Vienna'; } }
$z688_near = static function ( $a, $b ) { return '' !== (string) $a && abs( strtotime( $a . ' UTC' ) - strtotime( $b . ' UTC' ) ) <= 2; };
if ( ! function_exists( 'wp_generate_uuid4' ) ) { function wp_generate_uuid4() { return sprintf( '%04x%04x-%04x-4%03x-%04x-%04x%04x%04x', mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xfff ), mt_rand( 0x8000, 0xbfff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ) ); } }

// ---- Package expiry.
$z688_type = make_type( 20, 10, 0 );
$z688_occ = (int) $wpdb->get_var( $wpdb->prepare( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d", $z688_type ) );
$z688_event = (int) $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=%d", $z688_occ ) );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => Clock::mysql( 600 ) ), array( 'occurrence_id' => $z688_occ ) );
$z688_has_offline = PassCaptain\Privacy::has_table( 'wpcap_offline_assignments' );
if ( $z688_has_offline ) {
	$wpdb->replace( "{$p}wpcap_offline_events", array( 'event_id' => $z688_event, 'enabled' => 1, 'max_offline_hours' => 24, 'duplicate_policy' => 'review', 'include_attendee' => 0, 'updated_by' => 1, 'updated_at' => Clock::mysql() ) );
	$z688_dev = array();
	foreach ( array( 'fresh', 'stale', 'never' ) as $z688_label ) {
		$wpdb->insert( "{$p}wpcap_scanner_devices", array( 'public_id' => bin2hex( random_bytes( 16 ) ), 'user_id' => 1, 'secret_hash' => hash( 'sha256', $z688_label ), 'label' => 'A688 ' . $z688_label, 'platform' => 'pwa', 'app_version' => '', 'event_ids' => wp_json_encode( array( $z688_event ) ), 'expires_at' => Clock::mysql( DAY_IN_SECONDS ), 'created_at' => Clock::mysql() ) );
		$z688_dev[ $z688_label ] = (int) $wpdb->insert_id;
	}
	// Rows of earlier runs (same ids after the tables were reset) must not count.
	$wpdb->query( "DELETE FROM {$p}wpcap_offline_assignments WHERE device_id IN (" . implode( ',', $z688_dev ) . ')' );
	$wpdb->insert( "{$p}wpcap_offline_assignments", array( 'device_id' => $z688_dev['fresh'], 'event_id' => $z688_event, 'occurrence_id' => 0, 'assigned_by' => 1, 'last_package_at' => Clock::mysql( -2 * HOUR_IN_SECONDS ), 'created_at' => Clock::mysql() ) );
	$wpdb->insert( "{$p}wpcap_offline_assignments", array( 'device_id' => $z688_dev['stale'], 'event_id' => $z688_event, 'occurrence_id' => 0, 'assigned_by' => 1, 'last_package_at' => Clock::mysql( -30 * HOUR_IN_SECONDS ), 'created_at' => Clock::mysql() ) );
	$wpdb->insert( "{$p}wpcap_offline_assignments", array( 'device_id' => $z688_dev['never'], 'event_id' => $z688_event, 'occurrence_id' => 0, 'assigned_by' => 1, 'created_at' => Clock::mysql() ) );
	$z688_status = Assignments::package_status( array_values( $z688_dev ) );
	$z688_h = (int) PassCaptain\OfflineScanner\Rules::get( $z688_event )['max_offline_hours'];
	$z688_fresh = $z688_status[ $z688_dev['fresh'] ];
	check( 'A688: package valid until last fetch + max offline hours of the event', $z688_near( $z688_fresh['valid_until'], Clock::mysql( ( $z688_h - 2 ) * HOUR_IN_SECONDS ) ) && 1 === $z688_fresh['packages'] && ! $z688_fresh['all_expired'] );
	check( 'A688: an expired package is reported with its expiry', '' === $z688_status[ $z688_dev['stale'] ]['valid_until'] && $z688_near( $z688_status[ $z688_dev['stale'] ]['expired_at'], Clock::mysql( ( $z688_h - 30 ) * HOUR_IN_SECONDS ) ) && $z688_status[ $z688_dev['stale'] ]['all_expired'] );
	check( 'A688: assigned but never fetched', 1 === $z688_status[ $z688_dev['never'] ]['assignments'] && 0 === $z688_status[ $z688_dev['never'] ]['packages'] );
	check( 'A688: package_expiry() of one assignment row', $z688_near( Assignments::package_expiry( (object) array( 'event_id' => $z688_event, 'last_package_at' => Clock::mysql( -2 * HOUR_IN_SECONDS ) ) ), Clock::mysql( ( $z688_h - 2 ) * HOUR_IN_SECONDS ) ) && '' === Assignments::package_expiry( (object) array( 'event_id' => $z688_event, 'last_package_at' => null ) ) );
	check( 'A688: device list labels (valid until, expired, never loaded, not assigned)', 0 === strpos( DevicesPage::package_label( $z688_fresh ), 'gültig bis' ) && 0 === strpos( DevicesPage::package_label( $z688_status[ $z688_dev['stale'] ] ), 'abgelaufen am' ) && 'kein Paket geladen' === DevicesPage::package_label( $z688_status[ $z688_dev['never'] ] ) && '—' === DevicesPage::package_label( null ) );
}
$z688_src = (string) file_get_contents( WPCAP_PATH . 'src/Admin/DevicesPage.php' );
check( 'A688: the device list has the offline package column', false !== strpos( $z688_src, "esc_html_e( 'Offline-Paket', 'pass-captain' )" ) && false !== strpos( $z688_src, 'self::package_label(' ) );

// ---- Bulk check-in/out.
$GLOBALS['wpcap_test_user_id'] = 1;
$z688_order = Orders::create( array( 'email' => 'z688@example.com', 'items' => array( array( 'ticket_type_id' => $z688_type, 'quantity' => 3 ) ), 'idempotency_key' => 'z688-' . uniqid() ), array( 'skip_rate_limit' => true ) );
$z688_ids = array_map( static function ( $t ) { return (int) $t->ticket_id; }, tickets( $z688_order['order_id'] ) );
check( 'A688: fixture tickets', 3 === count( $z688_ids ) );
$z688_rows = static function () use ( $wpdb, $p, $z688_ids ) { return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_checkins WHERE result='checked_in' AND ticket_id IN (" . implode( ',', $z688_ids ) . ')' ); };

check( 'A688: an empty selection is refused', is_wp_error( TicketsPage::bulk_prepare( array( 'ticket_ids' => array() ) ) ) );
$z688_cap = static function () { return 2; };
add_filter( 'wpcap_bulk_checkin_max', $z688_cap );
check( 'A688: more tickets than allowed are refused', is_wp_error( TicketsPage::bulk_prepare( array( 'ticket_ids' => $z688_ids ) ) ) );
remove_filter( 'wpcap_bulk_checkin_max', $z688_cap );

$z688_state = TicketsPage::bulk_prepare( array( 'ticket_ids' => array_map( 'strval', $z688_ids ), 'direction' => 'in' ) );
check( 'A688: step 1 keeps the selection for confirmation and books nothing', is_array( $z688_state ) && 3 === count( $z688_state['tickets'] ) && null === $z688_state['report'] && 'in' === $z688_state['direction'] && 0 === $z688_rows() && TicketsPage::bulk_state()['batch_key'] === $z688_state['batch_key'] );
check( 'A688: a wrong or old confirmation key runs nothing', is_wp_error( TicketsPage::bulk_run( 'admin-falsch' ) ) && 0 === $z688_rows() );
$z688_report = TicketsPage::bulk_run( $z688_state['batch_key'] );
check( 'A688: confirmed run checks every ticket in and reports per ticket', is_array( $z688_report ) && 3 === $z688_report['counts']['done'] && 3 === count( $z688_report['results'] ) && $z688_report['complete'] && 3 === $z688_rows() );
$z688_again = TicketsPage::bulk_run( $z688_state['batch_key'] );
check( 'A688: a double-submitted confirmation books nothing twice (replayed)', 3 === $z688_again['counts']['done'] && 3 === count( array_filter( $z688_again['results'], static function ( $r ) { return $r['replayed']; } ) ) && 3 === $z688_rows() );
check( 'A688: retry without retryable lines has nothing to do', is_wp_error( TicketsPage::bulk_run( $z688_state['batch_key'], true ) ) );
// A temporary error on one ticket: the retry runs only that ticket with the same key.
$z688_stored = get_transient( 'wpcap_bulk_checkin_1' );
$z688_stored['report']['results'][1] = array( 'ticket_id' => $z688_ids[1], 'public_id' => '', 'ok' => false, 'result' => 'retry', 'message' => 'busy', 'replayed' => false, 'retryable' => true );
$z688_stored['report']['counts'] = array( 'done' => 2, 'already' => 0, 'refused' => 0, 'retry' => 1 );
set_transient( 'wpcap_bulk_checkin_1', $z688_stored, HOUR_IN_SECONDS );
$z688_retry = TicketsPage::bulk_run( $z688_state['batch_key'], true );
check( 'A688: idempotent retry of the retryable ticket, merged into the report', 3 === $z688_retry['counts']['done'] && 0 === $z688_retry['counts']['retry'] && 3 === count( $z688_retry['results'] ) && true === $z688_retry['results'][1]['ok'] && 3 === $z688_rows() );

// Report view.
$z688_render = new ReflectionMethod( TicketsPage::class, 'render_bulk' );
$z688_render->setAccessible( true );
ob_start();
$z688_render->invoke( null, TicketsPage::filters( array() ), 1 );
$z688_html = (string) ob_get_clean();
check( 'A688: the result report lists the tickets and the totals', false !== strpos( $z688_html, 'Ergebnis der Sammelaktion' ) && 3 === substr_count( $z688_html, '<code>' ) && false !== strpos( $z688_html, 'Bericht schließen' ) );

// Check-out of the same tickets (new selection, new key); a second selection shows the confirmation.
$z688_out = TicketsPage::bulk_prepare( array( 'ticket_ids' => array( $z688_ids[0], $z688_ids[1] ), 'direction' => 'out' ) );
ob_start();
$z688_render->invoke( null, TicketsPage::filters( array() ), 1 );
$z688_confirm = (string) ob_get_clean();
check( 'A688: the confirmation names the number of tickets', false !== strpos( $z688_confirm, '2 Tickets auschecken?' ) && false !== strpos( $z688_confirm, 'Ja, auschecken' ) && $z688_out['batch_key'] !== $z688_state['batch_key'] );
$z688_out_report = TicketsPage::bulk_run( $z688_out['batch_key'] );
check( 'A688: bulk check-out', 2 === $z688_out_report['counts']['done'] && 'checked_out' === $z688_out_report['results'][0]['result'] );

// Permissions.
$z688_other = TicketsPage::bulk_prepare( array( 'ticket_ids' => array( $z688_ids[2] ) ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_checkin' );
check( 'A688: without the check-in capability nothing is prepared or run', is_wp_error( TicketsPage::bulk_prepare( array( 'ticket_ids' => $z688_ids ) ) ) && is_wp_error( TicketsPage::bulk_run( $z688_other['batch_key'] ) ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'A688: handler registered', isset( PassCaptain\Admin\Menu::handlers()['wpcap_bulk_checkin'] ) || false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Admin/Menu.php' ), "'wpcap_bulk_checkin' => array( TicketsPage::class, 'handle_bulk_checkin' )" ) );
delete_transient( 'wpcap_bulk_checkin_1' );

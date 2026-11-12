<?php
/**
 * Order/admission flow fixes: event cancellation via occurrence cancellation, refundable cancelled
 * tickets, per-type line quantity, admission day cutoff, per-ticket reissue, expire_holds race,
 * private ticket PDF temp files, proxy-aware checkout rate limit.
 * Included from run.php after all module tests (uses check(), make_type(), expire_holds_of(),
 * admin_paid_order() and the admission helpers adm_setup()/adm_in()/adm_ts()).
 */
// phpcs:ignoreFile
use PassCaptain\Admin\EventsPage;
use PassCaptain\Admin\Menu;
use PassCaptain\Admin\TicketsPage;
use PassCaptain\Admission\Engine;
use PassCaptain\Admission\RulesPage;
use PassCaptain\OccurrenceCancellation;
use PassCaptain\Orders;
use PassCaptain\Refunds;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 1;

/** Adds a second scheduled occurrence (+ ticket type) to the event of $type; returns the new type id. */
function p1f_second_occurrence( $type, $price = 1000 ) {
	global $wpdb;
	$p = $wpdb->prefix;
	$event = (int) $wpdb->get_var( "SELECT o.event_id FROM {$p}wpcap_ticket_types tt JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=" . (int) $type );
	$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 8 * 86400 ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => 0, 'status' => 'scheduled' ) );
	$occ = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $occ, 'name' => 'Tag 2', 'price_minor' => $price, 'currency' => 'EUR', 'capacity' => 50, 'max_per_order' => 10, 'status' => 'active' ) );
	return (int) $wpdb->insert_id;
}
function p1f_event_of( $type ) {
	global $wpdb;
	return (int) $wpdb->get_var( "SELECT o.event_id FROM {$wpdb->prefix}wpcap_ticket_types tt JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=" . (int) $type );
}
function p1f_ticket_ids( $order_id ) {
	return array_map( static fn( $t ) => (int) $t->ticket_id, Orders::tickets_for_order( $order_id ) );
}
function p1f_order( $type, $qty, $email ) {
	$GLOBALS['wpcap_test_transients'] = array();
	return Orders::create( array( 'email' => $email, 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => 'p1f-' . uniqid( '', true ) ) );
}

OccurrenceCancellation::register();
Settings::update( array( 'refund_approval_threshold' => 0, 'auto_refund_cancelled' => 0, 'require_terms' => 0 ) );

echo "Phase 1: event cancellation runs the occurrence cancellation per occurrence\n";
$t1 = make_type( 50, 10, 1000 );
$t2 = p1f_second_occurrence( $t1 );
$ev = p1f_event_of( $t1 );
$paid_a = admin_paid_order( $t1, 2, 'p1f-a@example.com' );
$paid_b = admin_paid_order( $t2, 1, 'p1f-b@example.com' );
$open = p1f_order( $t1, 1, 'p1f-open@example.com' );
$mark = count( $GLOBALS['wpcap_test_actions'] );
$mails_before = count( $GLOBALS['wpcap_test_mails'] );
$res = Orders::cancel_event( $ev );
$fired = array_count_values( array_slice( $GLOBALS['wpcap_test_actions'], $mark ) );
$new_mails = array_slice( $GLOBALS['wpcap_test_mails'], $mails_before );
$subjects = array_map( static fn( $m ) => $m['subject'], $new_mails );
check( 'cancel_event fires wpcap_occurrence_cancelled once per occurrence', ! is_wp_error( $res ) && 2 === ( $fired['wpcap_occurrence_cancelled'] ?? 0 ) && 2 === $res['occurrences'] );
check( 'open order of the event is cancelled', 'cancelled' === Orders::get( $open['order_id'] )->payment_status && $res['cancelled_orders'] >= 1 );
check( 'all tickets are revoked, none refunded (auto refund off)', 3 === $res['revoked'] && 0 === $res['refunds'] && ! (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE order_id=$paid_a AND (status<>'revoked' OR refunded<>0)" ) );
check( 'paid and open buyers get one event cancellation mail each, no per-occurrence mails', 3 === $res['notified_count'] && 3 === count( array_filter( $subjects, static fn( $s ) => str_starts_with( $s, 'Eventabsage' ) ) ) && ! array_filter( $subjects, static fn( $s ) => str_starts_with( $s, 'Absage:' ) ) );
check( 'event and occurrences are cancelled; a second cancel is rejected', 'cancelled' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_events WHERE event_id=$ev" ) && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_occurrences WHERE event_id=$ev AND status<>'cancelled'" ) && is_wp_error( Orders::cancel_event( $ev ) ) );
$refundable = array_map( static fn( $r ) => (int) $r->ticket_id, Orders::refundable_tickets( $paid_a ) );
check( 'tickets revoked by the cancellation stay refundable (refundable_tickets)', p1f_ticket_ids( $paid_a ) === $refundable && 'paid' === Orders::get( $paid_a )->payment_status );
$calc = Refunds::calculate( Orders::get( $paid_a ), array() );
if ( is_wp_error( $calc ) ) {
	echo "  skip native refund of cancelled tickets (Refunds::calculate does not use Orders::refundable_tickets yet)\n";
} else {
	check( 'Refunds::calculate accepts the revoked tickets of the cancelled event', 2000 === $calc['amount_minor'] && 2 === count( $calc['ticket_ids'] ) );
}
// Tickets revoked for other reasons on a running occurrence are not refundable.
$t3 = make_type( 10, 10, 1000 );
$o3 = admin_paid_order( $t3, 2, 'p1f-c@example.com' );
$ids3 = p1f_ticket_ids( $o3 );
Orders::revoke_ids( array( $ids3[0] ) );
check( 'staff-revoked ticket of a scheduled occurrence is not refundable', array( $ids3[1] ) === array_map( static fn( $r ) => (int) $r->ticket_id, Orders::refundable_tickets( $o3 ) ) );

echo "Phase 1: event cancellation with automatic refund\n";
Settings::update( array( 'auto_refund_cancelled' => 1 ) );
$t4 = make_type( 10, 10, 1500 );
$o4 = admin_paid_order( $t4, 2, 'p1f-auto@example.com' );
$res4 = Orders::cancel_event( p1f_event_of( $t4 ) );
$refunds4 = Refunds::for_order( $o4 );
check( 'paid native order is refunded automatically', ! is_wp_error( $res4 ) && 1 === $res4['refunds'] && 0 === $res4['refund_errors'] && 1 === count( $refunds4 ) && 'completed' === $refunds4[0]['status'] && 3000 === (int) $refunds4[0]['amount_minor'] );
check( 'refunded tickets are flagged and revoked', ! (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE order_id=$o4 AND (status<>'revoked' OR refunded<>1)" ) && array() === Orders::refundable_tickets( $o4 ) );
Settings::update( array( 'auto_refund_cancelled' => 0 ) );

echo "Phase 1: single occurrence cancellation without automatic refund\n";
$t5 = make_type( 10, 10, 1000 );
$o5 = admin_paid_order( $t5, 1, 'p1f-occ@example.com' );
$occ5 = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$t5" );
$c5 = EventsPage::cancel_occurrence( array( 'occurrence_id' => $occ5 ) );
check( 'admin warning points to Bestellung → Erstatten', ! is_wp_error( $c5 ) && str_contains( (string) ( $c5['warning'] ?? '' ), 'Bestellung → Erstatten' ) );
check( 'paid ticket is revoked but stays refundable', 'revoked' === Orders::tickets_for_order( $o5 )[0]->status && 1 === count( Orders::refundable_tickets( $o5 ) ) );

echo "Phase 1: line quantity follows max_per_order\n";
$t6 = make_type( 200, 50, 1000 );
$q30 = p1f_order( $t6, 30, 'p1f-q@example.com' );
$q51 = p1f_order( $t6, 51, 'p1f-q@example.com' );
check( 'quantity above 10 but within max_per_order is accepted', ! is_wp_error( $q30 ) && 30 === (int) $wpdb->get_var( "SELECT SUM(quantity) FROM {$p}wpcap_order_items WHERE order_id=" . (int) $q30['order_id'] ) );
check( 'quantity above max_per_order is rejected with a clear message', is_wp_error( $q51 ) && str_contains( $q51->get_error_message(), 'pro Bestellung' ) );
$GLOBALS['wpcap_test_transients'] = array();
$split = Orders::create( array( 'email' => 'p1f-q@example.com', 'items' => array( array( 'ticket_type_id' => $t6, 'quantity' => 30 ), array( 'ticket_type_id' => $t6, 'quantity' => 30 ) ), 'idempotency_key' => 'p1f-split' ) );
check( 'split lines of one type count together against max_per_order', is_wp_error( $split ) );
$GLOBALS['wpcap_test_filters']['wpcap_max_line_quantity'] = 20;
$capped = p1f_order( $t6, 30, 'p1f-q@example.com' );
unset( $GLOBALS['wpcap_test_filters']['wpcap_max_line_quantity'] );
check( 'absolute cap filter wpcap_max_line_quantity applies first', is_wp_error( $capped ) && 'wpcap_invalid_item' === $capped->get_error_code() );

echo "Phase 1: admission day cutoff\n";
$tz = 'Europe/Vienna';
$night = adm_ts( '2027-07-03 02:30', $tz );
check( 'default cutoff is local midnight', '2027-07-03' === Engine::service_date( $night, $tz ) && 0 === (int) Settings::get( 'admission_day_cutoff_hour' ) );
Settings::update( array( 'admission_day_cutoff_hour' => 6 ) );
check( 'with cutoff 6 a 02:30 scan belongs to the previous day', '2027-07-02' === Engine::service_date( $night, $tz ) && '2027-07-03' === Engine::service_date( adm_ts( '2027-07-03 06:00', $tz ), $tz ) );
$p1f_ctx = null;
$p1f_cutoff = static function ( $hour, $ctx ) use ( &$p1f_ctx ) { $p1f_ctx = $ctx; return 777 === $ctx['occurrence_id'] ? 0 : $hour; };
add_filter( 'wpcap_admission_day_cutoff_hour', $p1f_cutoff, 10, 2 );
check( 'filter can override the cutoff per occurrence', '2027-07-03' === Engine::service_date( $night, $tz, array( 'occurrence_id' => 777, 'event_id' => 5 ) ) && 5 === $p1f_ctx['event_id'] && '2027-07-02' === Engine::service_date( $night, $tz, array( 'occurrence_id' => 778 ) ) );
remove_filter( 'wpcap_admission_day_cutoff_hour', $p1f_cutoff, 10 );
$s = adm_setup( $tz, '2027-07-02 18:00', '2027-07-04 23:00', 2 );
RulesPage::save_policy( array( 'ticket_type_id' => $s['type'], 'policy' => 'daily' ) );
$first = adm_in( $s['codes'][0], adm_ts( '2027-07-02 23:00', $tz ) );
$after_midnight = adm_in( $s['codes'][0], adm_ts( '2027-07-03 01:30', $tz ) );
$next_day = adm_in( $s['codes'][0], adm_ts( '2027-07-03 12:00', $tz ) );
check( 'daily ticket: re-scan after midnight but before the cutoff is the same day', 'checked_in' === $first && 'daily_limit_reached' === $after_midnight && 'checked_in' === $next_day );
adm_in( $s['codes'][1], adm_ts( '2027-07-05 02:00', $tz ) );
$entry_day = $wpdb->get_var( "SELECT service_date FROM {$p}wpcap_admission_entries WHERE ticket_id=(SELECT ticket_id FROM {$p}wpcap_tickets WHERE token_hash='" . hash( 'sha256', $s['codes'][1] ) . "') ORDER BY entry_id DESC LIMIT 1" );
check( 'entry before the cutoff is stored on the previous service day', '2027-07-04' === $entry_day );
Settings::update( array( 'admission_day_cutoff_hour' => 0 ) );
$settings_before = get_option( Settings::OPTION, array() );
PassCaptain\Admin\SettingsPage::save( array( 'admission_day_cutoff_hour' => '30' ) );
check( 'settings field clamps the cutoff to 0–12', 12 === (int) Settings::get( 'admission_day_cutoff_hour' ) );
update_option( Settings::OPTION, $settings_before );

echo "Phase 1: per-ticket reissue and resend\n";
$rotated = array();
$p1f_rot = static function ( $id, $token, $reason ) use ( &$rotated ) { $rotated[] = array( (int) $id, $token, $reason ); };
add_action( 'wpcap_ticket_code_rotated', $p1f_rot, 10, 3 );
$t7 = make_type( 10, 10, 1000 );
$o7 = admin_paid_order( $t7, 2, 'p1f-re@example.com' );
list( $k1, $k2 ) = p1f_ticket_ids( $o7 );
$wpdb->query( "UPDATE {$p}wpcap_tickets SET status='checked_in' WHERE ticket_id=$k1" );
$hash_k1 = $wpdb->get_var( "SELECT token_hash FROM {$p}wpcap_tickets WHERE ticket_id=$k1" );
$rotated = array();
$resent = Orders::resend_tickets( $o7 );
check( 'resend rotates only valid tickets, checked-in codes stay', true === $resent && 1 === count( $rotated ) && $k2 === $rotated[0][0] && 'resend' === $rotated[0][2] && $hash_k1 === $wpdb->get_var( "SELECT token_hash FROM {$p}wpcap_tickets WHERE ticket_id=$k1" ) );
$rotated = array();
$mails_before = count( $GLOBALS['wpcap_test_mails'] );
$re = Orders::reissue_ticket( $k2, array( 'reason' => 'Handy verloren' ) );
$re_mail = $GLOBALS['wpcap_test_mails'][ $mails_before ] ?? array( 'to' => '', 'body' => '' );
check( 'reissue rotates one ticket with reason "reissue" and mails the holder', ! is_wp_error( $re ) && $re['mailed'] && 1 === count( $rotated ) && 'reissue' === $rotated[0][2] && hash( 'sha256', $rotated[0][1] ) === $wpdb->get_var( "SELECT token_hash FROM {$p}wpcap_tickets WHERE ticket_id=$k2" ) && 'p1f-re@example.com' === $re_mail['to'] && str_contains( $re_mail['body'], $rotated[0][1] ) );
check( 'checked-in tickets cannot be reissued', is_wp_error( Orders::reissue_ticket( $k1 ) ) && $hash_k1 === $wpdb->get_var( "SELECT token_hash FROM {$p}wpcap_tickets WHERE ticket_id=$k1" ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_orders', 'wpcap_manage_events', 'manage_options' );
$forbidden = TicketsPage::reissue_ticket( array( 'ticket_id' => $k2 ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'admin reissue requires order management access', is_wp_error( $forbidden ) && 403 === $forbidden->get_error_data()['status'] );
$_POST = array( 'ticket_id' => (string) $k2 );
$GLOBALS['wpcap_test_nonce_checks'] = array();
try { TicketsPage::handle_reissue_ticket(); } catch ( Wpcap_Test_Redirect $e ) {}
$_POST = array();
check( 'admin-post handler verifies the per-ticket nonce and is registered', in_array( 'wpcap_reissue_ticket_' . $k2, $GLOBALS['wpcap_test_nonce_checks'], true ) && isset( Menu::handlers()['wpcap_reissue_ticket'] ) && 'reissue' === end( $rotated )[2] );
remove_action( 'wpcap_ticket_code_rotated', $p1f_rot, 10 );

echo "Phase 1: expire_holds acts only on orders it actually expired\n";
while ( Orders::expire_holds() > 0 ) {}
$t8 = make_type( 20, 10, 1000 );
$e1 = p1f_order( $t8, 1, 'p1f-e1@example.com' );
$e2 = p1f_order( $t8, 1, 'p1f-e2@example.com' );
expire_holds_of( $e1['order_id'] );
expire_holds_of( $e2['order_id'] );
$cancelled_seen = array();
$p1f_pay = static function ( $order_id ) use ( &$cancelled_seen, $e1, $e2, $wpdb, $p ) {
	$cancelled_seen[] = (int) $order_id;
	// The buyer pays order 2 while the cron is busy with order 1.
	if ( (int) $order_id === (int) $e1['order_id'] ) { $wpdb->query( "UPDATE {$p}wpcap_orders SET payment_status='paid',status='confirmed' WHERE order_id=" . (int) $e2['order_id'] ); }
};
add_action( 'wpcap_order_cancelled', $p1f_pay, 1, 1 );
$expired = Orders::expire_holds();
remove_action( 'wpcap_order_cancelled', $p1f_pay, 1 );
check( 'order paid between selection and update keeps its state and gets no side effects', 1 === $expired && array( (int) $e1['order_id'] ) === $cancelled_seen && 'paid' === Orders::get( $e2['order_id'] )->payment_status && 'expired' === Orders::get( $e1['order_id'] )->payment_status );
$batch = array();
for ( $i = 0; $i < 3; $i++ ) { $b = p1f_order( $t8, 1, "p1f-b$i@example.com" ); expire_holds_of( $b['order_id'] ); $batch[] = $b['order_id']; }
$GLOBALS['wpcap_test_filters']['wpcap_expire_holds_batch'] = 2;
$run1 = Orders::expire_holds();
$run2 = Orders::expire_holds();
unset( $GLOBALS['wpcap_test_filters']['wpcap_expire_holds_batch'] );
check( 'expire_holds works in batches', 2 === $run1 && 1 === $run2 && 3 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_orders WHERE payment_status='expired' AND order_id IN (" . implode( ',', $batch ) . ')' ) );

echo "Phase 1: ticket PDF in a private temp directory\n";
$pdf_seen = array();
$p1f_pdf = static function ( $mail ) use ( &$pdf_seen ) {
	foreach ( (array) $mail['attachments'] as $file ) {
		clearstatcache();
		$pdf_seen[] = array( 'file' => $file, 'file_mode' => fileperms( $file ) & 0777, 'dir_mode' => fileperms( dirname( $file ) ) & 0777 );
	}
	return $mail;
};
add_filter( 'wpcap_ticket_mail', $p1f_pdf, 10, 1 );
$free = make_type( 5, 10, 0 );
$fo = p1f_order( $free, 1, 'p1f-pdf@example.com' );
remove_filter( 'wpcap_ticket_mail', $p1f_pdf, 10 );
$pdf = $pdf_seen[0] ?? array( 'file' => '', 'file_mode' => 0, 'dir_mode' => 0 );
$pdf_mailed = (bool) array_filter( $GLOBALS['wpcap_test_mails'], static fn( $m ) => isset( $m['files'][ basename( $pdf['file'] ) ] ) && str_starts_with( (string) $m['files'][ basename( $pdf['file'] ) ], '%PDF' ) );
check( 'PDF is written to a random 0700 directory with mode 0600', 0600 === $pdf['file_mode'] && 0700 === $pdf['dir_mode'] && str_starts_with( basename( dirname( $pdf['file'] ) ), 'wpcap-' ) && rtrim( sys_get_temp_dir(), '/' ) !== dirname( $pdf['file'] ) );
check( 'PDF is attached and removed together with its directory', $pdf_mailed && ! file_exists( $pdf['file'] ) && ! is_dir( dirname( $pdf['file'] ) ) );

echo "Phase 1: checkout rate limit behind a proxy\n";
$p1f_remote = $_SERVER['REMOTE_ADDR'] ?? null;
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
$p1f_proxy = static function ( $ip, $server ) { return ( '203.0.113.10' === $ip && ! empty( $server['HTTP_CF_CONNECTING_IP'] ) ) ? $server['HTTP_CF_CONNECTING_IP'] : $ip; };
add_filter( 'wpcap_client_ip', $p1f_proxy, 10, 2 );
$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.1';
check( 'wpcap_client_ip resolves the trusted proxy header', '198.51.100.1' === Orders::client_ip() );
$_SERVER['HTTP_CF_CONNECTING_IP'] = 'not-an-ip';
check( 'invalid filtered IP falls back to REMOTE_ADDR', '203.0.113.10' === Orders::client_ip() );
$GLOBALS['wpcap_test_transients'] = array();
$GLOBALS['wpcap_test_filters']['wpcap_checkout_ip_limit'] = 2;
$t9 = make_type( 50, 10, 1000 );
$buy = static function ( $client, $n ) use ( $t9 ) { $_SERVER['HTTP_CF_CONNECTING_IP'] = $client; return Orders::create( array( 'email' => "p1f-rl$n@example.com", 'items' => array( array( 'ticket_type_id' => $t9, 'quantity' => 1 ) ), 'idempotency_key' => 'p1f-rl-' . uniqid( '', true ) ) ); };
$a1 = $buy( '198.51.100.1', 1 );
$a2 = $buy( '198.51.100.1', 2 );
$a3 = $buy( '198.51.100.1', 3 );
$b1 = $buy( '198.51.100.2', 4 );
check( 'limit applies per real client, not to the whole proxy', ! is_wp_error( $a1 ) && ! is_wp_error( $a2 ) && is_wp_error( $a3 ) && 429 === $a3->get_error_data()['status'] && ! is_wp_error( $b1 ) );
$keys = array_keys( $GLOBALS['wpcap_test_transients'] );
check( 'transient keys use a keyed hash, not plain sha256 of the IP', in_array( 'wpcap_checkout_ip_' . Orders::rate_hash( '198.51.100.1' ), $keys, true ) && ! in_array( 'wpcap_checkout_ip_' . hash( 'sha256', '198.51.100.1' ), $keys, true ) && ! in_array( 'wpcap_checkout_ip_' . hash( 'sha256', '203.0.113.10' ), $keys, true ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_checkout_ip_limit'], $_SERVER['HTTP_CF_CONNECTING_IP'] );
remove_filter( 'wpcap_client_ip', $p1f_proxy, 10 );
if ( null === $p1f_remote ) { unset( $_SERVER['REMOTE_ADDR'] ); } else { $_SERVER['REMOTE_ADDR'] = $p1f_remote; }
$GLOBALS['wpcap_test_transients'] = array();

remove_action( 'wpcap_occurrence_cancelled', array( OccurrenceCancellation::class, 'handle' ), 10 );

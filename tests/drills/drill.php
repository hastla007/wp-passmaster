<?php
/**
 * Helper for tests/drills/restore-drill.sh (wp eval-file, DISPOSABLE site only).
 *
 *   wp eval-file tests/drills/drill.php inflight <seed.json>   work that is "in flight" when the backup is taken:
 *        A  paid order whose ticket issuance crashed after the payment commit (no tickets, queued ticket_issue job),
 *        B  the same, but a worker died while running its job (stale "running" claim),
 *        D  pending order whose hold expired two hours ago (hourly cleanup did not run).
 *        A and B were paid 20 minutes ago (older than Diagnostics::ISSUE_GRACE). Prints JSON with the order ids.
 *   wp eval-file tests/drills/drill.php after-backup <seed.json>  writes that must disappear with the restore
 *        (a new paid order, a check-in, a refund).
 *   wp eval-file tests/drills/drill.php diagnose                  Diagnostics::run() as JSON {check: count}.
 *   wp eval-file tests/drills/drill.php order-tickets <order_id>  JSON {tickets, valid} of one order.
 *   wp eval-file tests/drills/drill.php code-valid <code>         exit 0 when the ticket code still resolves.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;

global $wpdb;
$p    = $wpdb->prefix;
$mode = $args[0] ?? '';
$seed = isset( $args[1] ) && is_file( $args[1] ) ? json_decode( (string) file_get_contents( $args[1] ), true ) : array();
$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( (int) ( $admin[0] ?? 1 ) );
add_filter( 'wpcap_checkout_ip_limit', static fn() => PHP_INT_MAX );
$die = static function ( $what, $error ) {
	fwrite( STDERR, "drill: $what: " . ( is_wp_error( $error ) ? $error->get_error_code() . ' ' . $error->get_error_message() : wp_json_encode( $error ) ) . "\n" );
	exit( 1 );
};
$order = static function ( $key, $type, $qty, array $internal = array() ) use ( $die ) {
	$r = Orders::create( array( 'email' => $key . '@example.com', 'items' => array( array( 'ticket_type_id' => (int) $type, 'quantity' => $qty ) ), 'idempotency_key' => $key, 'accept_terms' => true ), $internal + array( 'payment_provider' => 'manual', 'skip_rate_limit' => true ) );
	if ( is_wp_error( $r ) ) { $die( "order $key", $r ); }
	return (int) $r['order_id'];
};

switch ( $mode ) {
	case 'inflight':
		$tag = (string) ( $seed['tag'] ?? 'drill' );
		$type = (int) ( $seed['ticket_types']['later'] ?? 0 );
		$paid_ago = gmdate( 'Y-m-d H:i:s', time() - 20 * MINUTE_IN_SECONDS );
		$crash_paid = static function ( $order_id ) use ( $wpdb, $p, $paid_ago ) {
			// State Orders::confirm_external_payment() leaves when the request dies between COMMIT and issuance.
			$wpdb->update( "{$p}wpcap_orders", array( 'status' => 'complete', 'payment_status' => 'paid', 'updated_at' => $paid_ago ), array( 'order_id' => $order_id ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_reservations SET status='converted' WHERE order_id=%d", $order_id ) );
		};
		$a = $order( "drill-a-$tag", $type, 2 );
		$crash_paid( $a );
		PassCaptain\Jobs::enqueue( "drill-issue-a-$tag", Orders::ISSUE_JOB, array( 'order_id' => $a ) );
		$b = $order( "drill-b-$tag", $type, 1 );
		$crash_paid( $b );
		PassCaptain\Jobs::enqueue( "drill-issue-b-$tag", Orders::ISSUE_JOB, array( 'order_id' => $b ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_jobs SET status='running', attempts=1, locked_at=%s WHERE idempotency_key=%s", $paid_ago, hash( 'sha256', "drill-issue-b-$tag" ) ) );
		$d = $order( "drill-d-$tag", (int) ( $seed['ticket_types']['paid'] ?? 0 ), 1 );
		$two_hours = gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_reservations SET expires_at=%s WHERE order_id=%d", $two_hours, $d ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_orders SET created_at=%s, updated_at=%s WHERE order_id=%d", $two_hours, $two_hours, $d ) );
		echo wp_json_encode( array( 'paid_crashed' => $a, 'paid_stale_claim' => $b, 'expired_hold' => $d ) ) . "\n";
		break;

	case 'after-backup':
		$tag = (string) ( $seed['tag'] ?? 'drill' ) . '-late';
		$late = $order( "drill-late-$tag", (int) ( $seed['ticket_types']['free'] ?? 0 ), 1 );
		$paid = (int) ( $seed['orders']['paid'] ?? 0 );
		$ticket = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=%d AND status='valid' ORDER BY ticket_id DESC LIMIT 1", $paid ) );
		$refund = PassCaptain\Refunds::record_external( $paid, array( $ticket ), 2500, 'after backup', "drill-late-refund-$tag", array( 'provider_reference' => "re_late_$tag" ) );
		if ( is_wp_error( $refund ) ) { $die( 'late refund', $refund ); }
		$code = (string) ( $seed['codes']['free'][1] ?? '' );
		$checkin = PassCaptain\Checkin::perform( $code, array( 'idempotency_key' => "drill-late-checkin-$tag", 'source' => 'web' ) );
		if ( is_wp_error( $checkin ) ) { $die( 'late check-in', $checkin ); }
		echo wp_json_encode( array( 'order' => $late, 'refund_id' => (int) $refund['refund_id'] ) ) . "\n";
		break;

	case 'diagnose':
		$report = PassCaptain\Diagnostics::run();
		$out = array();
		foreach ( $report['checks'] as $key => $check ) { $out[ $key ] = '' !== $check['error'] ? 'error: ' . $check['error'] : (int) $check['count']; }
		echo wp_json_encode( $out ) . "\n";
		break;

	case 'order-tickets':
		$id = absint( $args[1] ?? 0 );
		echo wp_json_encode( array(
			'tickets' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE order_id=%d", $id ) ),
			'valid'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE order_id=%d AND status='valid'", $id ) ),
		) ) . "\n";
		break;

	case 'code-valid':
		$found = PassCaptain\Checkin::lookup( (string) ( $args[1] ?? '' ), null, false );
		exit( is_wp_error( $found ) ? 1 : 0 );

	default:
		fwrite( STDERR, "usage: wp eval-file tests/drills/drill.php inflight|after-backup <seed.json> | diagnose | order-tickets <id> | code-valid <code>\n" );
		exit( 2 );
}

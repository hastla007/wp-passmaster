<?php
/**
 * Realistic plugin data for the install/update/uninstall test (build/ci-install-test.sh) and the restore drill
 * (tests/drills/restore-drill.sh). Run with WP-CLI against a DISPOSABLE site only:
 *
 *   wp eval-file build/ci-data.php seed [tag]        creates an event with two occurrences, a free order, a paid
 *                                                     (manually confirmed) order, a check-in, an external refund,
 *                                                     a pending order with an active hold and a queued job; prints
 *                                                     JSON with ids and the ticket codes
 *   wp eval-file build/ci-data.php snapshot           prints a JSON fingerprint of the business data
 *   wp eval-file build/ci-data.php verify <file>      compares the current data with a snapshot file; exit 1 on a
 *                                                     difference (tables missing from the file are ignored, so a
 *                                                     schema upgrade that adds tables still matches)
 *   wp eval-file build/ci-data.php invariants         consistency checks independent of a snapshot (duplicate
 *                                                     codes/check-ins/refunds/idempotency keys, orphans, paid
 *                                                     orders without tickets, tickets of unpaid orders); exit 1
 *   wp eval-file build/ci-data.php leftovers          JSON: what of the plugin is still in the database (tables,
 *                                                     options, roles, administrator capabilities, user meta, cron
 *                                                     hooks); works without the plugin (after an uninstall)
 *
 * Only APIs that exist since 1.4.0 are used (Orders::create, Orders::confirm_manual_payment, Checkin::perform,
 * Refunds::record_external, Jobs::enqueue), so the same script seeds the previous release before an update.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

global $wpdb;
$wpcap_mode = $args[0] ?? '';
$p          = $wpdb->prefix;

/** Business tables and the columns that must survive an update or a restore unchanged. */
function wpcap_ci_data_tables() {
	return array(
		'events'       => array( 'event_id', 'event_id,slug,status' ),
		'occurrences'  => array( 'occurrence_id', 'occurrence_id,event_id,starts_at,status' ),
		'ticket_types' => array( 'ticket_type_id', 'ticket_type_id,occurrence_id,price_minor,capacity' ),
		'orders'       => array( 'order_id', 'order_id,public_id,status,payment_status,total_minor,payment_provider' ),
		'order_items'  => array( 'item_id', 'item_id,order_id,ticket_type_id,quantity' ),
		'reservations' => array( 'reservation_id', 'reservation_id,order_id,ticket_type_id,quantity,status' ),
		'tickets'      => array( 'ticket_id', 'ticket_id,order_id,ticket_type_id,status,token_hash' ),
		'checkins'     => array( 'checkin_id', 'checkin_id,ticket_id,result' ),
		'refunds'      => array( 'refund_id', 'refund_id,order_id,status,amount_minor,idempotency_key' ),
		'jobs'         => array( 'job_id', 'job_id,idempotency_key,job_type' ),
	);
}

function wpcap_ci_data_snapshot() {
	global $wpdb;
	$out = array( 'tables' => array() );
	foreach ( wpcap_ci_data_tables() as $table => list( $pk, $columns ) ) {
		$name = $wpdb->prefix . 'wpcap_' . $table;
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) ) { continue; }
		$present = $wpdb->get_col( "SHOW COLUMNS FROM `$name`" );
		$cols = array_values( array_intersect( explode( ',', $columns ), $present ) );
		$rows = $wpdb->get_results( 'SELECT ' . implode( ',', $cols ) . " FROM `$name` ORDER BY $pk", ARRAY_N );
		$out['tables'][ $table ] = array( 'rows' => count( $rows ), 'columns' => implode( ',', $cols ), 'md5' => md5( wp_json_encode( $rows ) ) );
	}
	$settings = get_option( 'wpcap_settings', array() );
	$out['settings_md5'] = md5( wp_json_encode( is_array( $settings ) ? $settings : array() ) );
	return $out;
}

/** @return string[] Problems found (empty = consistent). */
function wpcap_ci_data_invariants() {
	global $wpdb;
	$p = $wpdb->prefix;
	$checks = array(
		'duplicate ticket codes'            => "SELECT COUNT(*) FROM (SELECT token_hash FROM {$p}wpcap_tickets GROUP BY token_hash HAVING COUNT(*)>1) d",
		'duplicate order idempotency keys'  => "SELECT COUNT(*) FROM (SELECT idempotency_key FROM {$p}wpcap_orders WHERE idempotency_key<>'' GROUP BY idempotency_key HAVING COUNT(*)>1) d",
		'duplicate public order ids'        => "SELECT COUNT(*) FROM (SELECT public_id FROM {$p}wpcap_orders GROUP BY public_id HAVING COUNT(*)>1) d",
		'more than one admission per ticket' => "SELECT COUNT(*) FROM (SELECT ticket_id FROM {$p}wpcap_checkins WHERE result='checked_in' GROUP BY ticket_id HAVING COUNT(*)>1) d",
		'duplicate refund idempotency keys' => "SELECT COUNT(*) FROM (SELECT idempotency_key FROM {$p}wpcap_refunds GROUP BY idempotency_key HAVING COUNT(*)>1) d",
		'duplicate job keys'                => "SELECT COUNT(*) FROM (SELECT idempotency_key FROM {$p}wpcap_jobs GROUP BY idempotency_key HAVING COUNT(*)>1) d",
		'tickets without order'             => "SELECT COUNT(*) FROM {$p}wpcap_tickets t LEFT JOIN {$p}wpcap_orders o ON o.order_id=t.order_id WHERE o.order_id IS NULL",
		'order items without order'         => "SELECT COUNT(*) FROM {$p}wpcap_order_items i LEFT JOIN {$p}wpcap_orders o ON o.order_id=i.order_id WHERE o.order_id IS NULL",
		'reservations without order'        => "SELECT COUNT(*) FROM {$p}wpcap_reservations r LEFT JOIN {$p}wpcap_orders o ON o.order_id=r.order_id WHERE r.order_id>0 AND o.order_id IS NULL",
		'active holds of paid/closed orders' => "SELECT COUNT(*) FROM {$p}wpcap_reservations r JOIN {$p}wpcap_orders o ON o.order_id=r.order_id WHERE r.status='active' AND o.payment_status<>'pending'",
		'check-ins without ticket'          => "SELECT COUNT(*) FROM {$p}wpcap_checkins c LEFT JOIN {$p}wpcap_tickets t ON t.ticket_id=c.ticket_id WHERE c.ticket_id>0 AND t.ticket_id IS NULL",
		'refunds without order'             => "SELECT COUNT(*) FROM {$p}wpcap_refunds r LEFT JOIN {$p}wpcap_orders o ON o.order_id=r.order_id WHERE o.order_id IS NULL",
		'more tickets than ordered'         => "SELECT COUNT(*) FROM (SELECT i.order_id, i.ticket_type_id, SUM(i.quantity) q, (SELECT COUNT(*) FROM {$p}wpcap_tickets t WHERE t.order_id=i.order_id AND t.ticket_type_id=i.ticket_type_id) n FROM {$p}wpcap_order_items i GROUP BY i.order_id, i.ticket_type_id HAVING n>q) d",
		'paid orders missing tickets'       => "SELECT COUNT(*) FROM (SELECT i.order_id, i.ticket_type_id, SUM(i.quantity) q, (SELECT COUNT(*) FROM {$p}wpcap_tickets t WHERE t.order_id=i.order_id AND t.ticket_type_id=i.ticket_type_id) n FROM {$p}wpcap_order_items i JOIN {$p}wpcap_orders o ON o.order_id=i.order_id WHERE o.payment_status='paid' GROUP BY i.order_id, i.ticket_type_id HAVING n<q) d",
		'valid tickets of unpaid orders'    => "SELECT COUNT(*) FROM {$p}wpcap_tickets t JOIN {$p}wpcap_orders o ON o.order_id=t.order_id WHERE t.status='valid' AND o.payment_status NOT IN ('paid','paid_unallocated')",
		'oversold ticket types'             => "SELECT COUNT(*) FROM {$p}wpcap_ticket_types tt WHERE tt.capacity>0 AND (SELECT COUNT(*) FROM {$p}wpcap_tickets t WHERE t.ticket_type_id=tt.ticket_type_id AND t.status IN ('valid','checked_in')) > tt.capacity",
	);
	$problems = array();
	foreach ( $checks as $label => $sql ) {
		$wpdb->last_error = '';
		$n = (int) $wpdb->get_var( $sql );
		if ( '' !== $wpdb->last_error ) { $problems[] = "$label: SQL error " . $wpdb->last_error; continue; }
		if ( $n > 0 ) { $problems[] = "$label: $n"; }
	}
	return $problems;
}

switch ( $wpcap_mode ) {
	case 'seed':
		$tag   = sanitize_key( $args[1] ?? 'ci' ) . '-' . bin2hex( random_bytes( 3 ) );
		$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
		wp_set_current_user( (int) ( $admin[0] ?? 1 ) );
		$mails = array();
		add_filter( 'pre_wp_mail', static function ( $short, $atts ) use ( &$mails ) { $mails[] = $atts; return true; }, 1, 2 );
		add_filter( 'wpcap_checkout_ip_limit', static fn() => PHP_INT_MAX );
		$codes_from_mails = static function () use ( &$mails ) {
			$codes = array();
			foreach ( $mails as $mail ) {
				$body = is_array( $mail['message'] ?? null ) ? implode( "\n", $mail['message'] ) : (string) ( $mail['message'] ?? '' );
				if ( preg_match_all( '#wpcap_ticket=([a-f0-9]{64})#', $body, $m ) ) { $codes = array_merge( $codes, $m[1] ); }
			}
			$mails = array();
			return array_values( array_unique( $codes ) );
		};
		$fail = static function ( $what, $error ) {
			fwrite( STDERR, "seed: $what: " . ( is_wp_error( $error ) ? $error->get_error_code() . ' ' . $error->get_error_message() : wp_json_encode( $error ) ) . "\n" );
			exit( 1 );
		};
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'CI Konzert ' . $tag, 'slug' => 'ci-' . $tag, 'description' => 'Testdaten', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now ) );
		$event = (int) $wpdb->insert_id;
		// Occurrence inside the admission window (check-in) and one in a month.
		$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 30 * MINUTE_IN_SECONDS ), 'timezone' => 'Europe/Vienna', 'location' => 'Halle', 'capacity' => 0, 'status' => 'scheduled' ) );
		$today = (int) $wpdb->insert_id;
		$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ), 'timezone' => 'Europe/Vienna', 'location' => 'Großer Saal', 'capacity' => 0, 'status' => 'scheduled' ) );
		$later = (int) $wpdb->insert_id;
		$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $today, 'name' => 'Abendkasse frei', 'price_minor' => 0, 'currency' => 'EUR', 'capacity' => 50, 'max_per_order' => 10, 'status' => 'active' ) );
		$free_type = (int) $wpdb->insert_id;
		$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $today, 'name' => 'Standard', 'price_minor' => 2500, 'currency' => 'EUR', 'capacity' => 50, 'max_per_order' => 10, 'status' => 'active' ) );
		$paid_type = (int) $wpdb->insert_id;
		$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $later, 'name' => 'Vorverkauf', 'price_minor' => 1900, 'currency' => 'EUR', 'capacity' => 20, 'max_per_order' => 10, 'status' => 'active' ) );
		$later_type = (int) $wpdb->insert_id;
		$base = array( 'accept_terms' => true );

		// Free order: tickets are issued and mailed immediately.
		$free = PassCaptain\Orders::create( $base + array( 'email' => "free-$tag@example.com", 'items' => array( array( 'ticket_type_id' => $free_type, 'quantity' => 2 ) ), 'idempotency_key' => "free-$tag" ), array( 'skip_rate_limit' => true ) );
		if ( is_wp_error( $free ) ) { $fail( 'free order', $free ); }
		$free_codes = $codes_from_mails();

		// Paid order (manual payment, confirmed by staff): three tickets.
		$paid = PassCaptain\Orders::create( $base + array( 'email' => "paid-$tag@example.com", 'items' => array( array( 'ticket_type_id' => $paid_type, 'quantity' => 3 ) ), 'idempotency_key' => "paid-$tag" ), array( 'payment_provider' => 'manual', 'skip_rate_limit' => true ) );
		if ( is_wp_error( $paid ) ) { $fail( 'paid order', $paid ); }
		$confirmed = PassCaptain\Orders::confirm_manual_payment( (int) $paid['order_id'] );
		if ( is_wp_error( $confirmed ) ) { $fail( 'confirm payment', $confirmed ); }
		$paid_codes = $codes_from_mails();

		// Check-in of the first free ticket.
		$checkin = PassCaptain\Checkin::perform( $free_codes[0] ?? '', array( 'idempotency_key' => "ci-checkin-$tag", 'source' => 'web' ) );
		if ( is_wp_error( $checkin ) ) { $fail( 'check-in', $checkin ); }

		// External refund of one paid ticket (as a payment provider/WooCommerce refund would book it).
		$paid_tickets = $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=%d ORDER BY ticket_id", (int) $paid['order_id'] ) );
		$refund = PassCaptain\Refunds::record_external( (int) $paid['order_id'], array( (int) $paid_tickets[0] ), 2500, 'CI-Erstattung', "ci-refund-$tag", array( 'provider_reference' => "re_ci_$tag", 'source' => 'ci' ) );
		if ( is_wp_error( $refund ) ) { $fail( 'refund', $refund ); }

		// Pending order with an active hold (one day) and a queued job far in the future.
		$pending = PassCaptain\Orders::create( $base + array( 'email' => "pending-$tag@example.com", 'items' => array( array( 'ticket_type_id' => $later_type, 'quantity' => 2 ) ), 'idempotency_key' => "pending-$tag" ), array( 'payment_provider' => 'manual', 'hold_minutes' => 24 * 60, 'skip_rate_limit' => true ) );
		if ( is_wp_error( $pending ) ) { $fail( 'pending order', $pending ); }
		$job = PassCaptain\Jobs::enqueue( "ci-job-$tag", 'ci_noop', array( 'tag' => $tag ), gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ) );

		echo wp_json_encode( array(
			'tag'          => $tag,
			'event_id'     => $event,
			'occurrences'  => array( $today, $later ),
			'ticket_types' => array( 'free' => $free_type, 'paid' => $paid_type, 'later' => $later_type ),
			'orders'       => array( 'free' => (int) $free['order_id'], 'paid' => (int) $paid['order_id'], 'pending' => (int) $pending['order_id'] ),
			'codes'        => array( 'free' => $free_codes, 'paid' => $paid_codes ),
			'refund_id'    => (int) ( $refund['refund_id'] ?? 0 ),
			'job'          => $job,
		) ) . "\n";
		break;

	case 'snapshot':
		echo wp_json_encode( wpcap_ci_data_snapshot(), JSON_PRETTY_PRINT ) . "\n";
		break;

	case 'verify':
		$expected = json_decode( (string) file_get_contents( (string) ( $args[1] ?? '' ) ), true );
		if ( ! is_array( $expected ) || empty( $expected['tables'] ) ) { fwrite( STDERR, "verify: unreadable snapshot file\n" ); exit( 2 ); }
		$current = wpcap_ci_data_snapshot();
		$diff = array();
		foreach ( $expected['tables'] as $table => $want ) {
			$have = $current['tables'][ $table ] ?? null;
			if ( ! $have ) { $diff[] = "$table: table missing"; continue; }
			if ( $have['rows'] !== $want['rows'] ) { $diff[] = "$table: {$have['rows']} rows, expected {$want['rows']}"; continue; }
			if ( $have['columns'] === $want['columns'] && $have['md5'] !== $want['md5'] ) { $diff[] = "$table: row contents differ"; }
		}
		if ( ( $expected['settings_md5'] ?? '' ) !== $current['settings_md5'] && empty( $args[2] ) ) { $diff[] = 'wpcap_settings differ'; }
		if ( $diff ) { echo 'FAIL  data differs from the snapshot: ' . implode( '; ', $diff ) . "\n"; exit( 1 ); }
		echo 'ok    data matches the snapshot (' . count( $expected['tables'] ) . " tables)\n";
		break;

	case 'invariants':
		$problems = wpcap_ci_data_invariants();
		if ( $problems ) { echo 'FAIL  data invariants: ' . implode( '; ', $problems ) . "\n"; exit( 1 ); }
		echo "ok    data invariants (no duplicates, orphans, paid orders without tickets, tickets of unpaid orders, overselling)\n";
		break;

	case 'leftovers':
		$cron = array();
		foreach ( (array) _get_cron_array() as $events ) {
			foreach ( array_keys( (array) $events ) as $hook ) {
				if ( 0 === strpos( (string) $hook, 'wpcap_' ) ) { $cron[] = $hook; }
			}
		}
		$roles = array_values( array_filter( array_keys( wp_roles()->roles ), static fn( $r ) => 0 === strpos( $r, 'wpcap_' ) ) );
		$admin = get_role( 'administrator' );
		echo wp_json_encode( array(
			'tables'     => count( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $p . 'wpcap_' ) . '%' ) ) ),
			'options'    => (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s ORDER BY option_name", $wpdb->esc_like( 'wpcap_' ) . '%', $wpdb->esc_like( '_transient_wpcap_' ) . '%' ) ),
			'roles'      => $roles,
			'admin_caps' => $admin ? array_values( array_filter( array_keys( array_filter( $admin->capabilities ) ), static fn( $c ) => 0 === strpos( $c, 'wpcap_' ) ) ) : array(),
			'usermeta'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key LIKE %s OR meta_key LIKE %s", $wpdb->esc_like( 'wpcap_' ) . '%', $wpdb->esc_like( $wpdb->get_blog_prefix() . 'wpcap_' ) . '%' ) ),
			'cron'       => array_values( array_unique( $cron ) ),
		) ) . "\n";
		break;

	default:
		fwrite( STDERR, "usage: wp eval-file build/ci-data.php seed [tag] | snapshot | verify <file> [ignore-settings] | invariants | leftovers\n" );
		exit( 2 );
}


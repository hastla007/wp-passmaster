<?php
// Drill helper (A469): state outside the schema – provider keys, capacity counters, options and defaults.
// Runs under the old (1.4.0) and the new plugin code: `LC=<action> [LC_ARG=…] wp eval-file lifecycle.php`.
// Reads the seeded ticket type from SEED_OUT (seed.php). Prints one line.
wp_set_current_user( 1 );
add_filter( 'wpcap_checkout_ip_limit', static function () { return 1000000; } );
global $wpdb;
$p = $wpdb->prefix;
$seed = json_decode( (string) file_get_contents( (string) getenv( 'SEED_OUT' ) ), true );
$type = (int) ( $seed['type'] ?? 0 );
$arg = (string) getenv( 'LC_ARG' );
$buy = static function ( $qty, $email ) use ( $type ) {
	$o = PassCaptain\Orders::create( array( 'email' => $email, 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => (int) $qty ) ), 'accept_terms' => true, 'idempotency_key' => uniqid( 'lc', true ) ) );
	return is_wp_error( $o ) ? 'ERR ' . $o->get_error_code() : 'OK';
};
switch ( (string) getenv( 'LC' ) ) {
	case 'set-secrets': // new code: provider keys in the encrypted store
		PassCaptain\Secrets::set( 'stripe_secret_key', 'sk_test_drill_' . md5( 'drill' ) );
		PassCaptain\Secrets::set( 'stripe_webhook_secret', 'whsec_drill_' . md5( 'hook' ) );
		echo 'OK';
		break;
	case 'set-plain-secrets': // old code (1.4.0): provider keys in plaintext in wpcap_settings
		$s = (array) get_option( 'wpcap_settings', array() );
		$s['stripe_secret_key'] = 'sk_test_drill_' . md5( 'drill' );
		$s['stripe_webhook_secret'] = 'whsec_drill_' . md5( 'hook' );
		update_option( 'wpcap_settings', $s );
		echo 'OK';
		break;
	case 'stripe': // both versions: Stripe counts as configured (secret key + webhook secret)
		echo ( new PassCaptain\Payments\StripeGateway() )->is_available( 'EUR' ) ? 'yes' : 'no';
		break;
	case 'sell': // LC_ARG "<orders>x<quantity>": orders of the running code, buyer drill-lc-*
		list( $n, $qty ) = array_map( 'intval', explode( 'x', $arg ) );
		$out = array();
		for ( $i = 0; $i < $n; $i++ ) { $out[] = $buy( $qty, 'drill-lc-' . $i . '-' . uniqid() . '@example.com' ); }
		echo implode( ',', array_unique( $out ) );
		break;
	case 'backdate': // LC_ARG days: the drill-lc-* orders were placed and last changed that long ago
		$at = gmdate( 'Y-m-d H:i:s', time() - (int) $arg * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_orders SET created_at=%s, updated_at=%s WHERE customer_email LIKE %s", $at, $at, 'drill-lc-%' ) );
		echo (int) $wpdb->rows_affected;
		break;
	case 'buy': // LC_ARG quantity
		echo $buy( (int) $arg, 'drill-buy-' . uniqid() . '@example.com' );
		break;
	case 'capacity': // new code: counter state and what capacity decisions see
		PassCaptain\Orders::flush_availability_cache();
		$sold = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(oi.quantity-oi.refunded_quantity),0) FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_orders o ON o.order_id=oi.order_id WHERE oi.ticket_type_id=%d AND o.payment_status='paid' AND o.status<>'refunded'", $type ) );
		echo wp_json_encode( array( 'state' => PassCaptain\CapacityCounters::state(), 'enabled' => PassCaptain\CapacityCounters::enabled(), 'drift' => count( PassCaptain\CapacityCounters::drift() ), 'sold' => $sold, 'capacity' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT capacity FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d", $type ) ), 'available' => PassCaptain\Orders::availability( $type ) ) );
		break;
	case 'verify': // new code: cron reconcile runs (rebuild, then verification without the 10-minute wait)
		add_filter( 'wpcap_capacity_verify_after', '__return_zero' );
		for ( $i = 0; $i < 20 && 'ready' !== PassCaptain\CapacityCounters::state(); $i++ ) { PassCaptain\CapacityCounters::reconcile(); }
		echo PassCaptain\CapacityCounters::state();
		break;
	case 'settings': // new code: effective defaults that changed between the versions
		echo wp_json_encode( array( 'default_entry_window' => (int) PassCaptain\Settings::get( 'default_entry_window' ), 'entry_window_notice' => (int) get_option( 'wpcap_entry_window_pinned' ), 'plaintext_secrets' => array_values( array_intersect( array_keys( (array) get_option( 'wpcap_settings', array() ) ), PassCaptain\Secrets::KEYS ) ) ) );
		break;
	case 'health': // new code: no schema error, recurring events scheduled
		echo wp_json_encode( array( 'schema_error' => false !== get_option( 'wpcap_schema_error' ), 'events' => (bool) wp_next_scheduled( 'wpcap_process_jobs' ) && (bool) wp_next_scheduled( 'wpcap_reconcile_capacity' ) ) );
		break;
	default:
		echo 'unknown action';
}

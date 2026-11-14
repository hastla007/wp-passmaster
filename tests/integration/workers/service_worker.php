<?php
/**
 * Child process for the service booking / waitlist concurrency tests
 * (tests/integration/modules/zzzz_services_e_concurrency.php and _c_waitlist.php).
 *
 * Usage: php service_worker.php <start_at_unix_float> <mode> <args...>
 *   book <slot_id> <email>                  ServiceBookings::reserve()
 *   rebook <booking_id> <slot_id>           ServiceBookings::rebook() as admin
 *   buy <ticket_type_id> <email>            Orders::create()
 *   accept_event <waitlist_id> <token>      Waitlists::accept_offer()
 *   accept_slot <entry_id> <token>          ServiceWaitlist::accept()
 * Prints "OK:<id>" or "ERR:<code>". All workers wait for the same start time to collide.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

require dirname( __DIR__ ) . '/bootstrap.php';
if ( ! function_exists( 'register_rest_route' ) ) { function register_rest_route() { return true; } }
$GLOBALS['wpcap_test_options']['admin_email'] = 'admin@example.com';
$_SERVER['REMOTE_ADDR'] = '203.0.113.' . ( getmypid() % 250 );
PassCaptain\Modules\Services::register();

$start = (float) $argv[1];
$mode = (string) $argv[2];
while ( microtime( true ) < $start ) { usleep( 1000 ); }

switch ( $mode ) {
	case 'book':
		$r = PassCaptain\ServiceBookings::reserve( (int) $argv[3], (string) $argv[4], 1, 0, 10, 'worker-' . getmypid() . '-' . uniqid(), array( 'skip_rate_limit' => true ) );
		$id = is_array( $r ) ? $r['booking_id'] : 0;
		break;
	case 'rebook':
		$r = PassCaptain\ServiceBookings::rebook( (int) $argv[3], (int) $argv[4], array( 'actor' => 'admin', 'idempotency_key' => 'worker-' . getmypid() ) );
		$id = is_array( $r ) ? $r['booking_id'] : 0;
		break;
	case 'buy':
		$r = PassCaptain\Orders::create( array( 'email' => (string) $argv[4], 'items' => array( array( 'ticket_type_id' => (int) $argv[3], 'quantity' => 1 ) ), 'idempotency_key' => 'worker-buy-' . getmypid() ), array( 'skip_rate_limit' => true ) );
		$id = is_array( $r ) ? $r['order_id'] : 0;
		break;
	case 'accept_event':
		$r = PassCaptain\Waitlists::accept_offer( (int) $argv[3], (string) $argv[4] );
		$id = is_array( $r ) ? $r['order']['order_id'] : 0;
		break;
	case 'accept_slot':
		$r = PassCaptain\ServiceWaitlist::accept( (int) $argv[3], (string) $argv[4] );
		$id = is_array( $r ) ? $r['booking']['booking_id'] : 0;
		break;
	default:
		$r = new WP_Error( 'unknown_mode', 'unknown mode' );
		$id = 0;
}
echo is_wp_error( $r ) ? 'ERR:' . $r->get_error_code() : 'OK:' . (int) $id;

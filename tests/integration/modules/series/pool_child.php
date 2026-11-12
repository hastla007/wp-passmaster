<?php
/**
 * Concurrency worker for the series pool tests (not loaded by run.php's module glob).
 *
 * Usage: php pool_child.php <ticket_type_id> <quantity> <tag>
 * Prints "OK <seconds>" or "ERR:<message> <seconds>".
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

require dirname( __DIR__, 2 ) . '/bootstrap.php';

PassCaptain\Modules\Series::register();
$start = microtime( true );
$type = (int) ( $argv[1] ?? 0 );
$qty = max( 1, (int) ( $argv[2] ?? 1 ) );
$tag = preg_replace( '/[^a-z0-9-]/i', '', (string) ( $argv[3] ?? getmypid() ) );
$r = PassCaptain\Orders::create(
	array( 'email' => 'pool-' . $tag . '@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => 'pool-child-' . $tag . '-' . getmypid() ),
	array( 'skip_rate_limit' => true )
);
$elapsed = sprintf( '%.2f', microtime( true ) - $start );
echo is_wp_error( $r ) ? 'ERR:' . $r->get_error_message() . ' ' . $elapsed : 'OK ' . $elapsed;

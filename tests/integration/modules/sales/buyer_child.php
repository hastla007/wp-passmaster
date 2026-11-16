<?php
/**
 * Concurrency worker for the buyer limit tests (not loaded by run.php's module glob).
 *
 * Usage: php buyer_child.php <ticket_type_id> <email> <tag>
 * Prints "OK" or "ERR:<message>".
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

require dirname( __DIR__, 2 ) . '/bootstrap.php';

$type = (int) ( $argv[1] ?? 0 );
$email = (string) ( $argv[2] ?? 'child@example.com' );
$tag = preg_replace( '/[^a-z0-9-]/i', '', (string) ( $argv[3] ?? getmypid() ) );
$r = PassCaptain\Orders::create(
	array( 'email' => $email, 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => 'buyer-child-' . $tag . '-' . getmypid() ),
	array( 'skip_rate_limit' => true )
);
echo is_wp_error( $r ) ? 'ERR:' . $r->get_error_message() : 'OK';

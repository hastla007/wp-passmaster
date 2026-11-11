<?php
/**
 * Concurrency worker for tests/integration/modules/boxoffice.php: one box office sale of one ticket.
 * Usage: php worker.php <ticket_type_id> <occurrence_id>   (prints OK or ERR)
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

require dirname( __DIR__, 2 ) . '/bootstrap.php';

PassCaptain\Modules\BoxOffice::register();
$type = (int) ( $argv[1] ?? 0 );
$occurrence = (int) ( $argv[2] ?? 0 );
$r = PassCaptain\BoxOffice\Service::sell( array(
	'occurrence_id' => $occurrence,
	'quantities' => array( $type => 1 ),
	'method' => 'cash',
	'form_key' => 'race-' . getmypid() . '-' . bin2hex( random_bytes( 6 ) ),
) );
echo is_wp_error( $r ) ? 'ERR' : 'OK';

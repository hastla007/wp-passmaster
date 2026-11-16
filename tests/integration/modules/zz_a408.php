<?php
/**
 * A408: runtime values from PassCaptain\Config (filter wpcap_config, clamped) instead of hard-coded numbers.
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

use PassCaptain\Config;
use PassCaptain\Jobs;

echo "A408: configurable runtime values\n";
check( 'A408: defaults keep the previous behaviour', 8 === Config::get( 'jobs_max_attempts' ) && Jobs::MAX_ATTEMPTS === Jobs::max_attempts() && 15 === Config::get( 'jobs_stale_minutes' ) && 15 === Config::get( 'http_timeout' ) && 10 === Config::get( 'refund_wait_minutes' ) && 10 === Config::get( 'webhook_lock_minutes' ) );
$a408_f = static function ( $v, $key ) { return 'jobs_max_attempts' === $key ? 3 : $v; };
add_filter( 'wpcap_config', $a408_f, 10, 2 );
check( 'A408: the filter changes one value', 3 === Jobs::max_attempts() && 15 === Config::get( 'http_timeout' ) );
remove_filter( 'wpcap_config', $a408_f, 10 );
$a408_all = static function ( $v, $key ) { return 'http_timeout' === $key ? 999 : $v; };
add_filter( 'wpcap_config', $a408_all, 10, 2 );
check( 'A408: the global filter works and values are clamped to their bounds', 60 === Config::get( 'http_timeout' ) && 8 === Config::get( 'jobs_max_attempts' ) );
remove_filter( 'wpcap_config', $a408_all, 10 );
check( 'A408: unknown keys give 0', 0 === Config::get( 'nope' ) );
check( 'A408: all() lists every key', array_keys( Config::VALUES ) === array_keys( Config::all() ) );
$a408_src = '';
foreach ( array( 'src/Jobs.php', 'src/Refunds.php', 'src/Payments/StripeGateway.php', 'src/Payments/PayPalGateway.php', 'src/Payments/Webhooks.php' ) as $f ) { $a408_src .= (string) file_get_contents( WPCAP_PATH . $f ); }
check( 'A408: the former hard-coded values are read from Config', false === strpos( $a408_src, "'timeout' => 15," ) && false === strpos( $a408_src, '10 * MINUTE_IN_SECONDS' ) && false === strpos( $a408_src, 'self::STALE_MINUTES * MINUTE_IN_SECONDS' ) );

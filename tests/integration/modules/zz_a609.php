<?php
// phpcs:ignoreFile
/**
 * A609: uploaded offline scans must state their time with a UTC offset (ISO 8601 with Z/±hh:mm) or as
 * Unix timestamp; a local time without offset was read as UTC and shifted the scan. Such a batch is
 * refused with 400 before anything is booked.
 */

use PassCaptain\Checkin;

echo "A609: offline scanned_at needs an offset\n";
$a609_ts = strtotime( '2026-11-13 19:42:00 UTC' );
check( 'A609: ISO 8601 with Z, with ±hh:mm and Unix timestamps are accepted', $a609_ts === Checkin::parse_scanned_at( '2026-11-13T19:42:00Z' ) && $a609_ts === Checkin::parse_scanned_at( '2026-11-13T20:42:00+01:00' ) && $a609_ts === Checkin::parse_scanned_at( '2026-11-13T20:42:00.123+0100' ) && $a609_ts === Checkin::parse_scanned_at( (string) $a609_ts ) && $a609_ts === Checkin::parse_scanned_at( $a609_ts ) );
check( 'A609: times without offset and garbage are refused, empty means "now"', false === Checkin::parse_scanned_at( '2026-11-13 20:42:00' ) && false === Checkin::parse_scanned_at( '2026-11-13T20:42' ) && false === Checkin::parse_scanned_at( 'yesterday' ) && false === Checkin::parse_scanned_at( array() ) && null === Checkin::parse_scanned_at( '' ) && null === Checkin::parse_scanned_at( null ) );
$a609_err = Checkin::scanned_at_error( array( array( 'scanned_at' => '2026-11-13T19:42:00Z' ), array( 'scanned_at' => '2026-11-13 20:42:00' ) ) );
check( 'A609: a batch with one local time is a 400 naming the scan', is_wp_error( $a609_err ) && 'wpcap_scanned_at_invalid' === $a609_err->get_error_code() && 400 === ( $a609_err->get_error_data()['status'] ?? 0 ) && 1 === ( $a609_err->get_error_data()['index'] ?? -1 ) && null === Checkin::scanned_at_error( array( array( 'scanned_at' => '2026-11-13T19:42:00Z' ), array( 'token' => 'x' ) ) ) );
$a609_req = new WP_REST_Request( 'POST', '/pass-captain/v1/admission/offline-sync' );
$a609_req->set_param( 'scans', array( array( 'token' => str_repeat( 'a', 64 ), 'idempotency_key' => 'x4-a609-1', 'scanned_at' => '2026-11-13 20:42:00' ) ) );
$a609_res = PassCaptain\Admission\Rest::offline_sync( $a609_req );
check( 'A609: the web scanner upload refuses the batch with 400', is_wp_error( $a609_res ) && 'wpcap_scanned_at_invalid' === $a609_res->get_error_code() );
$a609_api = (string) file_get_contents( WPCAP_PATH . 'src/Mobile/Api.php' );
check( 'A609: the app upload checks the batch the same way', false !== strpos( $a609_api, 'Checkin::scanned_at_error( $scans )' ) && false !== strpos( $a609_api, 'Checkin::parse_scanned_at(' ) );

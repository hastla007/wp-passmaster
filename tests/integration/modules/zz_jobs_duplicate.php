<?php
/**
 * Jobs::enqueue(): a repeated idempotency key is a silent no-op (no database error in the log).
 */

use PassCaptain\Jobs;

echo "Jobs: repeated idempotency key without database error\n";

global $wpdb;
$jd_key = 'jd-' . uniqid();
$jd_first = Jobs::enqueue( $jd_key, 'jd_test', array( 'n' => 1 ) );
$wpdb->last_error = '';
$jd_second = Jobs::enqueue( $jd_key, 'jd_test', array( 'n' => 2 ) );
$jd_error = (string) $wpdb->last_error;
$jd_rows = $wpdb->get_results( $wpdb->prepare( "SELECT payload FROM {$wpdb->prefix}wpcap_jobs WHERE idempotency_key=%s", hash( 'sha256', $jd_key ) ) );
check( 'JD: both calls succeed', true === $jd_first && true === $jd_second );
check( 'JD: the repeated key leaves no database error', '' === $jd_error );
check( 'JD: one job row, the first payload is kept', 1 === count( $jd_rows ) && false !== strpos( (string) $jd_rows[0]->payload, '"n":1' ) );
check( 'JD: an invalid key is still refused', is_wp_error( Jobs::enqueue( '', 'jd_test', array() ) ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wpcap_jobs WHERE job_type=%s", 'jd_test' ) );

<?php
/** Minimal append-only audit helper. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Audit {
	public static function record( $action, $object_type, $object_id, array $details = array() ) {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'wpcap_audit_logs',
			array(
				'user_id' => get_current_user_id(),
				'action' => sanitize_key( $action ),
				'object_type' => sanitize_key( $object_type ),
				'object_id' => absint( $object_id ),
				'details' => wp_json_encode( Redact::value( $details, false ) ), // A194: never secrets, card data or full IBANs.
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s' )
		);
	}
}

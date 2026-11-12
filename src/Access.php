<?php
/** Event-scoped capability checks. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Access {
	public static function can_access_event( $event_id, $capability ) {
		$user_id = get_current_user_id();
		if ( ! $user_id || ! current_user_can( $capability ) ) {
			return false;
		}
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->prefix}wpcap_event_staff WHERE event_id = %d AND user_id = %d AND capability = %s LIMIT 1",
				absint( $event_id ),
				$user_id,
				sanitize_key( $capability )
			)
		);
	}
}

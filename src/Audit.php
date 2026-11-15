<?php
/** Minimal append-only audit helper. Details are redacted (Security\Redact): no secrets, codes or plain e-mail addresses. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Audit {
	/** Per-request cache: table prefix => whether the audit table exists (only checked for switched sites). */
	private static $site_tables = array();

	public static function record( $action, $object_type, $object_id, array $details = array() ) {
		global $wpdb;
		if ( ! self::site_has_tables() ) {
			return; // Multisite: switched to a site where the plugin is not installed (e.g. role change there).
		}
		$wpdb->insert(
			$wpdb->prefix . 'wpcap_audit_logs',
			array(
				'user_id' => get_current_user_id(),
				'action' => sanitize_key( $action ),
				'object_type' => sanitize_key( $object_type ),
				'object_id' => absint( $object_id ),
				'details' => wp_json_encode( Security\Redact::data( $details ) ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Whether the current site has the audit table. Only checked after switch_to_blog() (cached per table
	 * prefix): a network-wide hook such as a role change may run for a site without the plugin's tables.
	 */
	private static function site_has_tables() {
		global $wpdb;
		if ( ! function_exists( 'ms_is_switched' ) || ! ms_is_switched() ) {
			return true;
		}
		$table = $wpdb->prefix . 'wpcap_audit_logs';
		if ( ! isset( self::$site_tables[ $table ] ) ) {
			self::$site_tables[ $table ] = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', addcslashes( $table, '_%\\' ) ) );
		}
		return self::$site_tables[ $table ];
	}
}

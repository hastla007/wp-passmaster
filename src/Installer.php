<?php
/**
 * Schema setup and role registration.
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Installer {
	const DB_VERSION = '1.0.0';

	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$events = $wpdb->prefix . 'wpcap_events';
		$occurrences = $wpdb->prefix . 'wpcap_occurrences';
		$types = $wpdb->prefix . 'wpcap_ticket_types';
		$orders = $wpdb->prefix . 'wpcap_orders';
		$items = $wpdb->prefix . 'wpcap_order_items';
		$reservations = $wpdb->prefix . 'wpcap_reservations';
		$tickets = $wpdb->prefix . 'wpcap_tickets';
		$checkins = $wpdb->prefix . 'wpcap_checkins';
		$staff = $wpdb->prefix . 'wpcap_event_staff';
		$audit = $wpdb->prefix . 'wpcap_audit_logs';

		$queries = array(
			"CREATE TABLE $events (
				event_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(190) NOT NULL,
				slug varchar(190) NOT NULL,
				description longtext NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'draft',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (event_id),
				UNIQUE KEY slug (slug),
				KEY status (status)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $occurrences (
				occurrence_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL,
				starts_at datetime NOT NULL,
				ends_at datetime NULL,
				timezone varchar(64) NOT NULL,
				location varchar(190) NOT NULL DEFAULT '',
				capacity int(10) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'scheduled',
				PRIMARY KEY  (occurrence_id),
				KEY event_id (event_id),
				KEY starts_at (starts_at),
				KEY status (status)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $types (
				ticket_type_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				occurrence_id bigint(20) unsigned NOT NULL,
				name varchar(190) NOT NULL,
				price_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				currency char(3) NOT NULL DEFAULT 'EUR',
				tax_class varchar(64) NOT NULL DEFAULT '',
				capacity int(10) unsigned NOT NULL DEFAULT 0,
				max_per_order smallint(5) unsigned NOT NULL DEFAULT 10,
				sales_start datetime NULL,
				sales_end datetime NULL,
				status varchar(20) NOT NULL DEFAULT 'active',
				PRIMARY KEY  (ticket_type_id),
				KEY occurrence_id (occurrence_id),
				KEY status (status)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $orders (
				order_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				public_id char(32) NOT NULL,
				idempotency_key char(64) NOT NULL,
				customer_email varchar(190) NOT NULL,
				status varchar(24) NOT NULL DEFAULT 'pending_payment',
				payment_status varchar(24) NOT NULL DEFAULT 'pending',
				currency char(3) NOT NULL,
				total_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (order_id),
				UNIQUE KEY public_id (public_id),
				UNIQUE KEY idempotency_key (idempotency_key),
				KEY customer_email (customer_email),
				KEY status (status)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $items (
				item_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				order_id bigint(20) unsigned NOT NULL,
				ticket_type_id bigint(20) unsigned NOT NULL,
				quantity smallint(5) unsigned NOT NULL,
				unit_price_minor bigint(20) unsigned NOT NULL,
				line_total_minor bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (item_id),
				KEY order_id (order_id),
				KEY ticket_type_id (ticket_type_id)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $reservations (
				reservation_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				order_id bigint(20) unsigned NOT NULL,
				ticket_type_id bigint(20) unsigned NOT NULL,
				quantity smallint(5) unsigned NOT NULL,
				expires_at datetime NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'active',
				PRIMARY KEY  (reservation_id),
				KEY order_id (order_id),
				KEY inventory (ticket_type_id,status,expires_at)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $tickets (
				ticket_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				public_id char(32) NOT NULL,
				order_id bigint(20) unsigned NOT NULL,
				ticket_type_id bigint(20) unsigned NOT NULL,
				token_hash char(64) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'valid',
				issued_at datetime NOT NULL,
				PRIMARY KEY  (ticket_id),
				UNIQUE KEY public_id (public_id),
				UNIQUE KEY token_hash (token_hash),
				KEY order_id (order_id),
				KEY ticket_type_id (ticket_type_id),
				KEY status (status)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $checkins (
				checkin_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				ticket_id bigint(20) unsigned NOT NULL,
				event_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				result varchar(24) NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (checkin_id),
				KEY ticket_id (ticket_id),
				KEY event_id (event_id),
				KEY user_id (user_id)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $staff (
				event_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				capability varchar(64) NOT NULL,
				PRIMARY KEY  (event_id,user_id,capability),
				KEY user_id (user_id)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $audit (
				audit_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				action varchar(64) NOT NULL,
				object_type varchar(32) NOT NULL,
				object_id bigint(20) unsigned NOT NULL DEFAULT 0,
				details longtext NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (audit_id),
				KEY object (object_type,object_id),
				KEY created_at (created_at)
			) ENGINE=InnoDB $charset;",
		);

		foreach ( $queries as $query ) {
			dbDelta( $query );
		}

		self::register_roles();
		update_option( 'wpcap_db_version', self::DB_VERSION );
		update_option( 'wpcap_version', WPCAP_VERSION );
	}

	public static function maybe_upgrade() {
		if ( self::DB_VERSION !== get_option( 'wpcap_db_version' ) ) {
			self::install();
		}
	}

	private static function register_roles() {
		$admin_caps = array(
			'wpcap_manage_events'  => true,
			'wpcap_manage_orders'  => true,
			'wpcap_checkin'        => true,
			'wpcap_view_reports'   => true,
		);
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( $admin_caps as $cap => $grant ) {
				$admin->add_cap( $cap, $grant );
			}
		}

		add_role(
			'wpcap_event_manager',
			__( 'Pass Captain Veranstaltungsleitung', 'wp-pass-captain' ),
			array( 'read' => true, 'wpcap_manage_events' => true, 'wpcap_manage_orders' => true )
		);
		add_role(
			'wpcap_checkin_staff',
			__( 'Pass Captain Einlasspersonal', 'wp-pass-captain' ),
			array( 'read' => true, 'wpcap_checkin' => true )
		);
	}
}

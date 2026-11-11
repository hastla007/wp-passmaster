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
	const DB_VERSION = '3.1.0';

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
		$seat_maps = $wpdb->prefix . 'wpcap_seat_maps';
		$seats = $wpdb->prefix . 'wpcap_seats';
		$seat_holds = $wpdb->prefix . 'wpcap_seat_holds';
		$resources = $wpdb->prefix . 'wpcap_resources';
		$service_slots = $wpdb->prefix . 'wpcap_service_slots';
		$service_bookings = $wpdb->prefix . 'wpcap_service_bookings';
		$occurrence_days = $wpdb->prefix . 'wpcap_occurrence_days';
		$scanner_devices = $wpdb->prefix . 'wpcap_scanner_devices';
		$wallet_passes = $wpdb->prefix . 'wpcap_wallet_passes';
		$invoices = $wpdb->prefix . 'wpcap_invoices';
		$invoice_lines = $wpdb->prefix . 'wpcap_invoice_lines';
		$invoice_sequences = $wpdb->prefix . 'wpcap_invoice_sequences';
		$participant_fields = $wpdb->prefix . 'wpcap_participant_fields';
		$participant_answers = $wpdb->prefix . 'wpcap_participant_answers';
		$message_queue = $wpdb->prefix . 'wpcap_message_queue';
		$ticket_assignments = $wpdb->prefix . 'wpcap_ticket_assignments';
		$member_passes = $wpdb->prefix . 'wpcap_member_passes';
		$pass_rules = $wpdb->prefix . 'wpcap_pass_rules';
		$pass_uses = $wpdb->prefix . 'wpcap_pass_uses';
		$campaigns = $wpdb->prefix . 'wpcap_campaigns';
		$campaign_uses = $wpdb->prefix . 'wpcap_campaign_uses';
		$waitlist = $wpdb->prefix . 'wpcap_waitlist';
		$api_keys = $wpdb->prefix . 'wpcap_api_keys';
		$event_translations = $wpdb->prefix . 'wpcap_event_translations';
		$tax_profiles = $wpdb->prefix . 'wpcap_tax_profiles';
		$connector_settings = $wpdb->prefix . 'wpcap_connector_settings';
		$jobs = $wpdb->prefix . 'wpcap_jobs';

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
				access_token_hash char(64) NOT NULL DEFAULT '',
				access_expires_at datetime NULL,
				status varchar(24) NOT NULL DEFAULT 'pending_payment',
				payment_status varchar(24) NOT NULL DEFAULT 'pending',
				currency char(3) NOT NULL,
				total_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				subtotal_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				discount_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				price_snapshot longtext NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (order_id),
				UNIQUE KEY public_id (public_id),
				UNIQUE KEY idempotency_key (idempotency_key),
				KEY customer_email (customer_email),
				KEY access_token_hash (access_token_hash),
				KEY access_expires_at (access_expires_at),
				KEY status (status),
				KEY paid_created (payment_status,created_at,status)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $items (
				item_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				order_id bigint(20) unsigned NOT NULL,
				ticket_type_id bigint(20) unsigned NOT NULL,
				quantity smallint(5) unsigned NOT NULL,
				unit_price_minor bigint(20) unsigned NOT NULL,
				line_total_minor bigint(20) unsigned NOT NULL,
				refunded_quantity smallint(5) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (item_id),
				KEY order_id (order_id),
				KEY ticket_type_id (ticket_type_id),
				KEY type_order (ticket_type_id,order_id)
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
				attendee_email varchar(190) NOT NULL DEFAULT '',
				transfer_count tinyint(3) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'valid',
				issued_at datetime NOT NULL,
				PRIMARY KEY  (ticket_id),
				UNIQUE KEY public_id (public_id),
				UNIQUE KEY token_hash (token_hash),
				KEY order_id (order_id),
				KEY ticket_type_id (ticket_type_id),
				KEY status (status),
				KEY order_type_status (order_id,ticket_type_id,status)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $checkins (
				checkin_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				ticket_id bigint(20) unsigned NOT NULL,
				event_id bigint(20) unsigned NOT NULL,
				occurrence_day_id bigint(20) unsigned NOT NULL DEFAULT 0,
				user_id bigint(20) unsigned NOT NULL,
				result varchar(24) NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (checkin_id),
				KEY ticket_id (ticket_id),
				KEY event_id (event_id),
				KEY occurrence_day_id (occurrence_day_id),
				KEY user_id (user_id),
				KEY event_result_created (event_id,result,created_at)
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
			"CREATE TABLE $seat_maps (
				map_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL,
				version int(10) unsigned NOT NULL,
				name varchar(190) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'draft',
				created_at datetime NOT NULL,
				PRIMARY KEY  (map_id),
				UNIQUE KEY event_version (event_id,version),
				KEY event_status (event_id,status)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $seats (
				seat_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				map_id bigint(20) unsigned NOT NULL,
				seat_key varchar(64) NOT NULL,
				zone varchar(100) NOT NULL DEFAULT '',
				row_label varchar(40) NOT NULL DEFAULT '',
				seat_label varchar(40) NOT NULL,
				position_x decimal(9,3) NOT NULL DEFAULT 0,
				position_y decimal(9,3) NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'available',
				PRIMARY KEY  (seat_id),
				UNIQUE KEY map_seat (map_id,seat_key),
				KEY map_status (map_id,status)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $seat_holds (
				hold_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				occurrence_id bigint(20) unsigned NOT NULL,
				seat_id bigint(20) unsigned NOT NULL,
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				request_key char(64) NOT NULL,
				expires_at datetime NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'active',
				PRIMARY KEY  (hold_id),
				KEY seat_state (occurrence_id,seat_id,status),
				UNIQUE KEY request_seat (request_key,seat_id),
				KEY expiry (status,expires_at)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $resources (
				resource_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL,
				name varchar(190) NOT NULL,
				capacity smallint(5) unsigned NOT NULL DEFAULT 1,
				buffer_before smallint(5) unsigned NOT NULL DEFAULT 0,
				buffer_after smallint(5) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'active',
				PRIMARY KEY  (resource_id),
				KEY event_status (event_id,status)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $service_slots (
				slot_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				resource_id bigint(20) unsigned NOT NULL,
				starts_at datetime NOT NULL,
				ends_at datetime NOT NULL,
				capacity smallint(5) unsigned NOT NULL DEFAULT 1,
				status varchar(20) NOT NULL DEFAULT 'open',
				PRIMARY KEY  (slot_id),
				KEY resource_time (resource_id,starts_at,ends_at),
				KEY status_time (status,starts_at)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $service_bookings (
				booking_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				slot_id bigint(20) unsigned NOT NULL,
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				customer_email varchar(190) NOT NULL,
				idempotency_key char(64) NOT NULL,
				quantity smallint(5) unsigned NOT NULL DEFAULT 1,
				expires_at datetime NULL,
				status varchar(20) NOT NULL DEFAULT 'held',
				created_at datetime NOT NULL,
				PRIMARY KEY  (booking_id),
				KEY slot_state (slot_id,status),
				KEY email (customer_email),
				UNIQUE KEY idempotency_key (idempotency_key)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $occurrence_days (
				occurrence_day_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				occurrence_id bigint(20) unsigned NOT NULL,
				service_date date NOT NULL,
				admission_policy varchar(24) NOT NULL DEFAULT 'single_entry',
				max_entries smallint(5) unsigned NOT NULL DEFAULT 1,
				PRIMARY KEY  (occurrence_day_id),
				UNIQUE KEY occurrence_date (occurrence_id,service_date),
				KEY admission_policy (admission_policy)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $scanner_devices (
				device_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				public_id char(32) NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				secret_hash char(64) NOT NULL,
				label varchar(190) NOT NULL DEFAULT '',
				last_seen_at datetime NULL,
				revoked_at datetime NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (device_id),
				UNIQUE KEY public_id (public_id),
				UNIQUE KEY secret_hash (secret_hash),
				KEY user_id (user_id)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $wallet_passes (
				wallet_pass_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				ticket_id bigint(20) unsigned NOT NULL,
				provider varchar(20) NOT NULL,
				provider_ref varchar(190) NOT NULL DEFAULT '',
				serial_number varchar(190) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'active',
				updated_at datetime NOT NULL,
				PRIMARY KEY  (wallet_pass_id),
				UNIQUE KEY serial_number (serial_number),
				KEY ticket_provider (ticket_id,provider)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $invoices (
				invoice_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				order_id bigint(20) unsigned NOT NULL,
				invoice_number varchar(40) NOT NULL,
				currency char(3) NOT NULL,
				subtotal_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				tax_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				total_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				buyer_snapshot longtext NOT NULL,
				issued_at datetime NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'issued',
				PRIMARY KEY  (invoice_id),
				UNIQUE KEY invoice_number (invoice_number),
				UNIQUE KEY order_id (order_id)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $invoice_lines (
				invoice_line_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				invoice_id bigint(20) unsigned NOT NULL,
				description varchar(190) NOT NULL,
				quantity smallint(5) unsigned NOT NULL DEFAULT 1,
				unit_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				discount_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				tax_rate decimal(7,4) NOT NULL DEFAULT 0,
				tax_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				total_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (invoice_line_id),
				KEY invoice_id (invoice_id)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $invoice_sequences (
				year smallint(5) unsigned NOT NULL,
				next_number bigint(20) unsigned NOT NULL DEFAULT 1,
				PRIMARY KEY  (year)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $participant_fields (
				field_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				ticket_type_id bigint(20) unsigned NOT NULL,
				field_key varchar(64) NOT NULL,
				label varchar(190) NOT NULL,
				field_type varchar(24) NOT NULL DEFAULT 'text',
				required tinyint(1) NOT NULL DEFAULT 0,
				purpose varchar(190) NOT NULL DEFAULT '',
				retention_days smallint(5) unsigned NOT NULL DEFAULT 365,
				position smallint(5) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'active',
				PRIMARY KEY  (field_id),
				UNIQUE KEY type_key (ticket_type_id,field_key),
				KEY type_status (ticket_type_id,status)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $participant_answers (
				answer_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				ticket_id bigint(20) unsigned NOT NULL,
				field_id bigint(20) unsigned NOT NULL,
				answer longtext NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (answer_id),
				UNIQUE KEY ticket_field (ticket_id,field_id),
				KEY field_id (field_id)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $message_queue (
				message_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				dedupe_key char(64) NOT NULL,
				recipient varchar(190) NOT NULL,
				template_key varchar(64) NOT NULL,
				payload longtext NOT NULL,
				send_at datetime NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'queued',
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				locked_at datetime NULL,
				last_error text NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (message_id),
				UNIQUE KEY dedupe_key (dedupe_key),
				KEY due_messages (status,send_at)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $ticket_assignments (
				assignment_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				ticket_id bigint(20) unsigned NOT NULL,
				buyer_email varchar(190) NOT NULL,
				attendee_email varchar(190) NOT NULL DEFAULT '',
				attendee_name varchar(190) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'assigned',
				created_at datetime NOT NULL,
				PRIMARY KEY  (assignment_id),
				UNIQUE KEY ticket_id (ticket_id),
				KEY buyer_email (buyer_email)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $member_passes (
				pass_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				public_id char(32) NOT NULL,
				member_email varchar(190) NOT NULL,
				token_hash char(64) NOT NULL,
				valid_from datetime NOT NULL,
				valid_until datetime NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL,
				PRIMARY KEY  (pass_id),
				UNIQUE KEY public_id (public_id),
				UNIQUE KEY token_hash (token_hash),
				KEY member_status (member_email,status)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $pass_rules (
				rule_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				pass_id bigint(20) unsigned NOT NULL,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				ticket_type_id bigint(20) unsigned NOT NULL DEFAULT 0,
				uses_total smallint(5) unsigned NOT NULL DEFAULT 0,
				uses_per_day smallint(5) unsigned NOT NULL DEFAULT 1,
				PRIMARY KEY  (rule_id),
				KEY pass_scope (pass_id,event_id,ticket_type_id)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $pass_uses (
				pass_use_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				pass_id bigint(20) unsigned NOT NULL,
				rule_id bigint(20) unsigned NOT NULL,
				service_date date NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				idempotency_key char(64) NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (pass_use_id),
				UNIQUE KEY idempotency_key (idempotency_key),
				KEY pass_day (pass_id,service_date)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $campaigns (
				campaign_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				ticket_type_id bigint(20) unsigned NOT NULL DEFAULT 0,
				code varchar(32) NOT NULL,
				name varchar(190) NOT NULL,
				kind varchar(16) NOT NULL,
				discount_value bigint(20) unsigned NOT NULL,
				currency char(3) NOT NULL DEFAULT 'EUR',
				usage_limit int(10) unsigned NOT NULL DEFAULT 0,
				used_count int(10) unsigned NOT NULL DEFAULT 0,
				priority smallint(5) unsigned NOT NULL DEFAULT 100,
				starts_at datetime NULL,
				ends_at datetime NULL,
				status varchar(20) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL,
				PRIMARY KEY  (campaign_id),
				UNIQUE KEY code (code),
				KEY scope_status (event_id,ticket_type_id,status),
				KEY active_window (status,starts_at,ends_at)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $campaign_uses (
				campaign_use_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				campaign_id bigint(20) unsigned NOT NULL,
				order_id bigint(20) unsigned NOT NULL,
				code varchar(32) NOT NULL,
				discount_minor bigint(20) unsigned NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'reserved',
				created_at datetime NOT NULL,
				PRIMARY KEY  (campaign_use_id),
				UNIQUE KEY campaign_order (campaign_id,order_id),
				KEY order_status (order_id,status)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $waitlist (
				waitlist_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL,
				occurrence_id bigint(20) unsigned NOT NULL DEFAULT 0,
				ticket_type_id bigint(20) unsigned NOT NULL DEFAULT 0,
				email_hash char(64) NOT NULL,
				email varchar(190) NOT NULL,
				position bigint(20) unsigned NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'waiting',
				offer_token_hash char(64) NOT NULL DEFAULT '',
				offer_expires_at datetime NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (waitlist_id),
				UNIQUE KEY event_type_email (event_id,ticket_type_id,email_hash),
				KEY queue_order (event_id,ticket_type_id,status,position),
				KEY offer_expiry (status,offer_expires_at)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $api_keys (
				api_key_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				public_prefix char(12) NOT NULL,
				secret_hash char(64) NOT NULL,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				scopes varchar(255) NOT NULL,
				label varchar(190) NOT NULL DEFAULT '',
				last_used_at datetime NULL,
				expires_at datetime NULL,
				revoked_at datetime NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (api_key_id),
				UNIQUE KEY secret_hash (secret_hash),
				UNIQUE KEY public_prefix (public_prefix),
				KEY user_scope (user_id,event_id),
				KEY revoked_at (revoked_at)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $event_translations (
				translation_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL,
				locale varchar(20) NOT NULL,
				name varchar(190) NOT NULL,
				description longtext NOT NULL,
				location varchar(190) NOT NULL DEFAULT '',
				updated_at datetime NOT NULL,
				PRIMARY KEY  (translation_id),
				UNIQUE KEY event_locale (event_id,locale),
				KEY locale (locale)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $tax_profiles (
				tax_profile_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				country_code char(2) NOT NULL,
				tax_class varchar(64) NOT NULL DEFAULT 'standard',
				rate_basis_points int(10) unsigned NOT NULL,
				version varchar(32) NOT NULL,
				effective_from date NOT NULL,
				effective_until date NULL,
				status varchar(20) NOT NULL DEFAULT 'draft',
				created_at datetime NOT NULL,
				PRIMARY KEY  (tax_profile_id),
				KEY country_effective (country_code,tax_class,effective_from,effective_until),
				KEY status (status)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $connector_settings (
				connector_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				connector_key varchar(64) NOT NULL,
				site_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'disabled',
				mapping longtext NOT NULL,
				secret_ref varchar(190) NOT NULL DEFAULT '',
				updated_at datetime NOT NULL,
				PRIMARY KEY  (connector_id),
				UNIQUE KEY connector_site (connector_key,site_id),
				KEY status (status)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $jobs (
				job_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				idempotency_key char(64) NOT NULL,
				job_type varchar(64) NOT NULL,
				payload longtext NOT NULL,
				run_after datetime NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'queued',
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				locked_at datetime NULL,
				last_error text NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (job_id),
				UNIQUE KEY idempotency_key (idempotency_key),
				KEY due_jobs (status,run_after),
				KEY job_type (job_type,status)
			) ENGINE=InnoDB $charset;",
		);

		foreach ( $queries as $query ) {
			dbDelta( $query );
		}

		self::register_roles();
		update_option( 'wpcap_db_version', self::DB_VERSION );
		update_option( 'wpcap_version', WPCAP_VERSION );
	}

	/** Runs the schema upgrade when needed; returns true if an upgrade ran. */
	public static function maybe_upgrade() {
		if ( self::DB_VERSION !== get_option( 'wpcap_db_version' ) ) {
			self::install();
			return true;
		}
		return false;
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
			array( 'read' => true, 'wpcap_manage_events' => true, 'wpcap_manage_orders' => true, 'wpcap_view_reports' => true )
		);
		$manager = get_role( 'wpcap_event_manager' );
		if ( $manager ) { $manager->add_cap( 'wpcap_view_reports' ); }
		add_role(
			'wpcap_checkin_staff',
			__( 'Pass Captain Einlasspersonal', 'wp-pass-captain' ),
			array( 'read' => true, 'wpcap_checkin' => true )
		);
	}
}

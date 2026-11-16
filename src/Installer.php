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
	const DB_VERSION = '3.18.0';

	public static function install() {
		global $wpdb;
		// Schema version before this run ('' on a fresh install), for one-off data migrations below.
		$previous_version = self::migration_baseline( (string) get_option( 'wpcap_db_version', '' ), (string) get_option( 'wpcap_db_version_max', '' ) );
		// Older plugin code ran on this site since the highest schema (A464): state it did not maintain
		// (capacity counters) is rebuilt below.
		$rolled_back = self::rolled_back( (string) get_option( 'wpcap_db_version', '' ), (string) get_option( 'wpcap_db_version_max', '' ) );
		// Marker of a running attempt (A466): only survives when the request dies mid-upgrade.
		self::begin_attempt();
		// Schema line of the site before the merged code wrote anything (see migration_previous()).
		self::schema_origin( (string) get_option( 'wpcap_db_version', '' ) );

		// Downgrade: the database was written by a newer plugin version. Installing would write the older
		// version back while newer data (e.g. pcv1 secrets, new columns) stays – nothing is changed.
		if ( self::schema_is_newer( self::stored_db_version() ) ) { return false; }

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
		$staff_assignments = $wpdb->prefix . 'wpcap_staff_assignments';
		$audit = $wpdb->prefix . 'wpcap_audit_logs';
		$seat_maps = $wpdb->prefix . 'wpcap_seat_maps';
		$seats = $wpdb->prefix . 'wpcap_seats';
		$seat_holds = $wpdb->prefix . 'wpcap_seat_holds';
		$resources = $wpdb->prefix . 'wpcap_resources';
		$service_slots = $wpdb->prefix . 'wpcap_service_slots';
		$service_bookings = $wpdb->prefix . 'wpcap_service_bookings';
		$service_providers = $wpdb->prefix . 'wpcap_service_providers';
		$resource_providers = $wpdb->prefix . 'wpcap_resource_providers';
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
				tax_country char(2) NOT NULL DEFAULT '',
				image_url varchar(500) NOT NULL DEFAULT '',
				category varchar(190) NOT NULL DEFAULT '',
				refund_policy text NULL,
				notify_changes tinyint(1) unsigned NOT NULL DEFAULT 1,
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
				doors_open_at datetime NULL,
				timezone varchar(64) NOT NULL,
				location varchar(190) NOT NULL DEFAULT '',
				venue_country char(2) NOT NULL DEFAULT '',
				capacity int(10) unsigned NOT NULL DEFAULT 0,
				sold_qty int(11) NOT NULL DEFAULT 0,
				held_qty int(11) NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'scheduled',
				rescheduled_at datetime NULL,
				revision int(10) unsigned NOT NULL DEFAULT 0,
				revised_at datetime NULL,
				notified_revision int(10) unsigned NOT NULL DEFAULT 0,
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
				tax_rate_bp int(10) unsigned NOT NULL DEFAULT 0,
				sales_start datetime NULL,
				sales_end datetime NULL,
				status varchar(20) NOT NULL DEFAULT 'active',
				visibility varchar(10) NOT NULL DEFAULT 'public',
				access_codes text NULL,
				description text NULL,
				position smallint(5) unsigned NOT NULL DEFAULT 0,
				attendee_name varchar(10) NOT NULL DEFAULT 'optional',
				sold_qty int(11) NOT NULL DEFAULT 0,
				held_qty int(11) NOT NULL DEFAULT 0,
				id_check tinyint(1) unsigned NOT NULL DEFAULT 0,
				min_age tinyint(3) unsigned NOT NULL DEFAULT 0,
				staff_note varchar(190) NOT NULL DEFAULT '',
				attendee_name_required tinyint(1) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (ticket_type_id),
				KEY occurrence_id (occurrence_id),
				KEY status (status)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $orders (
				order_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				public_id char(32) NOT NULL,
				idempotency_key char(64) NOT NULL,
				request_hash char(64) NOT NULL DEFAULT '',
				customer_email varchar(190) NOT NULL,
				customer_name varchar(190) NOT NULL DEFAULT '',
				customer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				access_token_hash char(64) NOT NULL DEFAULT '',
				access_expires_at datetime NULL,
				access_pending_hash char(64) NOT NULL DEFAULT '',
				access_pending_expires_at datetime NULL,
				status varchar(24) NOT NULL DEFAULT 'pending_payment',
				payment_status varchar(24) NOT NULL DEFAULT 'pending',
				currency char(3) NOT NULL,
				total_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				subtotal_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				discount_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				price_snapshot longtext NOT NULL,
				tax_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				refunded_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				payment_provider varchar(32) NOT NULL DEFAULT 'manual',
				provider_reference varchar(191) NOT NULL DEFAULT '',
				terms_version varchar(64) NOT NULL DEFAULT '',
				locale varchar(20) NOT NULL DEFAULT '',
				voucher_snapshot longtext NULL,
				attendee_data longtext NULL,
				billing longtext NULL,
				request_fingerprint char(64) NULL,
				attribution varchar(500) NOT NULL DEFAULT '',
				payout_account longtext NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (order_id),
				UNIQUE KEY public_id (public_id),
				UNIQUE KEY idempotency_key (idempotency_key),
				KEY customer_email (customer_email),
				KEY customer_user_id (customer_user_id),
				KEY access_token_hash (access_token_hash),
				KEY access_expires_at (access_expires_at),
				KEY status (status),
				KEY paid_created (payment_status,created_at,status),
				KEY created (created_at,order_id),
				KEY updated (updated_at),
				KEY provider_ref (payment_provider,provider_reference(64))
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $items (
				item_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				order_id bigint(20) unsigned NOT NULL,
				ticket_type_id bigint(20) unsigned NOT NULL,
				quantity smallint(5) unsigned NOT NULL,
				unit_price_minor bigint(20) unsigned NOT NULL,
				line_total_minor bigint(20) unsigned NOT NULL,
				tax_rate_bp int(10) unsigned NOT NULL DEFAULT 0,
				tax_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				refunded_quantity smallint(5) unsigned NOT NULL DEFAULT 0,
				line_snapshot longtext NULL,
				counted_qty smallint(5) unsigned NOT NULL DEFAULT 0,
				orig_quantity smallint(5) unsigned DEFAULT NULL,
				orig_line_total_minor bigint(20) unsigned DEFAULT NULL,
				orig_tax_minor bigint(20) unsigned DEFAULT NULL,
				discount_minor bigint(20) unsigned DEFAULT NULL,
				tax_source varchar(16) NOT NULL DEFAULT '',
				tax_profile_id bigint(20) unsigned NOT NULL DEFAULT 0,
				tax_profile_version varchar(32) NOT NULL DEFAULT '',
				tax_country char(2) NOT NULL DEFAULT '',
				PRIMARY KEY  (item_id),
				KEY order_id (order_id),
				KEY ticket_type_id (ticket_type_id),
				KEY capacity_sum (ticket_type_id,order_id,quantity,refunded_quantity,counted_qty)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $reservations (
				reservation_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				order_id bigint(20) unsigned NOT NULL,
				ticket_type_id bigint(20) unsigned NOT NULL,
				quantity smallint(5) unsigned NOT NULL,
				expires_at datetime NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'active',
				counted_qty smallint(5) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (reservation_id),
				KEY order_id (order_id),
				KEY inventory (ticket_type_id,status,expires_at),
				KEY status_expires (status,expires_at),
				KEY expiry (status,expires_at),
				KEY counted_type (ticket_type_id,counted_qty)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $tickets (
				ticket_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				public_id char(32) NOT NULL,
				order_id bigint(20) unsigned NOT NULL,
				ticket_type_id bigint(20) unsigned NOT NULL,
				token_hash char(64) NOT NULL DEFAULT '',
				code_secret varchar(255) NULL DEFAULT NULL,
				help_hash char(64) NULL DEFAULT NULL,
				help_n tinyint(3) unsigned NOT NULL DEFAULT 0,
				attendee_email varchar(190) NOT NULL DEFAULT '',
				transfer_count tinyint(3) unsigned NOT NULL DEFAULT 0,
				holder_changes tinyint(3) unsigned NOT NULL DEFAULT 0,
				name_changes tinyint(3) unsigned NOT NULL DEFAULT 0,
				code_with_attendee tinyint(1) unsigned NOT NULL DEFAULT 0,
				refunded tinyint(1) unsigned NOT NULL DEFAULT 0,
				capacity_released tinyint(1) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'valid',
				issued_at datetime NOT NULL,
				updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY  (ticket_id),
				UNIQUE KEY public_id (public_id),
				UNIQUE KEY token_hash (token_hash),
				UNIQUE KEY help_hash (help_hash),
				KEY order_id (order_id),
				KEY ticket_type_id (ticket_type_id),
				KEY status (status),
				KEY order_type_status (order_id,ticket_type_id,status),
				KEY attendee_email (attendee_email),
				KEY updated_at (updated_at)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $checkins (
				checkin_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				ticket_id bigint(20) unsigned NOT NULL,
				event_id bigint(20) unsigned NOT NULL,
				occurrence_day_id bigint(20) unsigned NOT NULL DEFAULT 0,
				user_id bigint(20) unsigned NOT NULL,
				result varchar(24) NOT NULL,
				device_id bigint(20) unsigned NOT NULL DEFAULT 0,
				idempotency_key char(64) NULL,
				source varchar(16) NOT NULL DEFAULT 'web',
				scanned_at datetime NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (checkin_id),
				KEY idempotency (user_id,idempotency_key),
				KEY ticket_id (ticket_id,result,created_at),
				KEY event_id (event_id),
				KEY occurrence_day_id (occurrence_day_id),
				KEY user_id (user_id),
				KEY event_result_created (event_id,result,created_at),
				KEY created_checkin (created_at,checkin_id),
				KEY created (created_at,checkin_id),
				KEY device_event_result (device_id,event_id,result)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $staff (
				event_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				capability varchar(64) NOT NULL,
				PRIMARY KEY  (event_id,user_id,capability),
				KEY user_id (user_id)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $staff_assignments (
				assignment_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				event_id bigint(20) unsigned NOT NULL,
				scope varchar(20) NOT NULL,
				object_id bigint(20) unsigned NOT NULL,
				capability varchar(64) NOT NULL,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (assignment_id),
				UNIQUE KEY user_object (user_id,scope,object_id,capability),
				KEY event_user (event_id,user_id),
				KEY scope_object (scope,object_id)
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
				KEY created_at (created_at),
				KEY action_created (action,created_at),
				KEY user_created (user_id,created_at)
			) ENGINE=InnoDB $charset;",
			// W9: date-level staff assignments (Access::can_access_occurrence) and time-limited support access.
			"CREATE TABLE {$wpdb->prefix}wpcap_occurrence_staff (
				occurrence_id bigint(20) unsigned NOT NULL,
				event_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				capability varchar(64) NOT NULL,
				PRIMARY KEY  (occurrence_id,user_id,capability),
				KEY event_user (event_id,user_id),
				KEY user_id (user_id)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$wpdb->prefix}wpcap_support_grants (
				grant_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				granted_by bigint(20) unsigned NOT NULL DEFAULT 0,
				reason varchar(255) NOT NULL DEFAULT '',
				capabilities varchar(255) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'active',
				expires_at datetime NOT NULL,
				revoked_by bigint(20) unsigned NOT NULL DEFAULT 0,
				ended_at datetime NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (grant_id),
				KEY user_status (user_id,status),
				KEY status_expires (status,expires_at)
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
				timezone varchar(64) NOT NULL DEFAULT '',
				capacity smallint(5) unsigned NOT NULL DEFAULT 1,
				buffer_before smallint(5) unsigned NOT NULL DEFAULT 0,
				buffer_after smallint(5) unsigned NOT NULL DEFAULT 0,
				default_duration smallint(5) unsigned NOT NULL DEFAULT 60,
				partner_id bigint(20) unsigned NOT NULL DEFAULT 0,
				ticket_type_id bigint(20) unsigned NOT NULL DEFAULT 0,
				rules longtext NULL,
				duration_minutes smallint(5) unsigned NOT NULL DEFAULT 60,
				lead_minutes int(10) unsigned NOT NULL DEFAULT 0,
				advance_days smallint(5) unsigned NOT NULL DEFAULT 0,
				max_per_customer smallint(5) unsigned NOT NULL DEFAULT 0,
				rebook_hours smallint(5) unsigned NOT NULL DEFAULT 0,
				cancel_hours smallint(5) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'active',
				PRIMARY KEY  (resource_id),
				KEY event_status (event_id,status),
				KEY partner_id (partner_id)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $service_providers (
				provider_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(190) NOT NULL,
				email varchar(190) NOT NULL DEFAULT '',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				partner_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL,
				PRIMARY KEY  (provider_id),
				KEY status (status)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $resource_providers (
				resource_id bigint(20) unsigned NOT NULL,
				provider_id bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (resource_id,provider_id),
				KEY provider (provider_id)
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
				status varchar(24) NOT NULL DEFAULT 'held',
				ticket_ids varchar(500) NOT NULL DEFAULT '',
				amount_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				currency char(3) NOT NULL DEFAULT '',
				manage_token_hash char(64) NOT NULL DEFAULT '',
				confirm_by datetime NULL,
				cancel_reason varchar(64) NOT NULL DEFAULT '',
				refund_id bigint(20) unsigned NOT NULL DEFAULT 0,
				rebook_count smallint(5) unsigned NOT NULL DEFAULT 0,
				last_action_key char(64) NOT NULL DEFAULT '',
				pass_use_id bigint(20) unsigned NOT NULL DEFAULT 0,
				checked_in_at datetime NULL,
				noshow_at datetime NULL,
				fee_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				supplement_for bigint(20) unsigned NOT NULL DEFAULT 0,
				origin_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				origin_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				token_hash char(64) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				updated_at datetime NULL,
				PRIMARY KEY  (booking_id),
				KEY slot_state (slot_id,status),
				KEY supplement_for (supplement_for),
				KEY email (customer_email),
				KEY order_id (order_id),
				KEY status_confirm (status,confirm_by),
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
				expires_at datetime NULL,
				event_ids longtext NULL,
				platform varchar(32) NOT NULL DEFAULT '',
				app_version varchar(32) NOT NULL DEFAULT '',
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
				synced_at datetime NULL DEFAULT NULL,
				remote_at datetime NULL,
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
				consent tinyint(1) NOT NULL DEFAULT 0,
				retention_days smallint(5) unsigned NOT NULL DEFAULT 365,
				position smallint(5) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'active',
				consent_text text NULL,
				consent_version varchar(32) NOT NULL DEFAULT '',
				options text NULL,
				help_text varchar(190) NOT NULL DEFAULT '',
				scope varchar(10) NOT NULL DEFAULT 'ticket',
				version int(10) unsigned NOT NULL DEFAULT 1,
				self_service varchar(10) NOT NULL DEFAULT 'edit',
				edit_hours smallint(5) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (field_id),
				UNIQUE KEY type_key (ticket_type_id,field_key),
				KEY type_status (ticket_type_id,status)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $participant_answers (
				answer_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				ticket_id bigint(20) unsigned NOT NULL,
				field_id bigint(20) unsigned NOT NULL,
				answer longtext NOT NULL,
				field_version int(10) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (answer_id),
				UNIQUE KEY ticket_field (ticket_id,field_id),
				KEY field_id (field_id)
			) ENGINE=InnoDB $charset;",
			// Consent records (A116): consent texts by version, append-only grant/withdrawal log.
			"CREATE TABLE {$wpdb->prefix}wpcap_consent_texts (
				text_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				field_id bigint(20) unsigned NOT NULL,
				text_version char(64) NOT NULL,
				label varchar(190) NOT NULL,
				purpose varchar(190) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				PRIMARY KEY  (text_id),
				UNIQUE KEY field_version (field_id,text_version)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$wpdb->prefix}wpcap_consent_log (
				log_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				ticket_id bigint(20) unsigned NOT NULL,
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				field_id bigint(20) unsigned NOT NULL,
				field_key varchar(64) NOT NULL,
				text_id bigint(20) unsigned NOT NULL DEFAULT 0,
				text_version char(64) NOT NULL DEFAULT '',
				action varchar(12) NOT NULL,
				source varchar(24) NOT NULL,
				locale varchar(20) NOT NULL DEFAULT '',
				subject_hash char(64) NOT NULL DEFAULT '',
				ip_hash char(64) NOT NULL DEFAULT '',
				actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				note varchar(190) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				PRIMARY KEY  (log_id),
				KEY ticket_field (ticket_id,field_id,log_id),
				KEY order_id (order_id),
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
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				sent_at datetime NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (message_id),
				UNIQUE KEY dedupe_key (dedupe_key),
				KEY due_messages (status,send_at),
				KEY order_id (order_id)
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
				KEY buyer_email (buyer_email),
				KEY attendee_email (attendee_email,ticket_id),
				KEY attendee_name (attendee_name,ticket_id)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $member_passes (
				pass_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				public_id char(32) NOT NULL,
				member_email varchar(190) NOT NULL,
				token_hash char(64) NOT NULL,
				valid_from datetime NOT NULL,
				valid_until datetime NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'active',
				requested_by bigint(20) unsigned NOT NULL DEFAULT 0,
				approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
				approved_at datetime NULL,
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
				scope varchar(16) NOT NULL DEFAULT 'event',
				resource_id bigint(20) unsigned NOT NULL DEFAULT 0,
				slot_mode varchar(16) NOT NULL DEFAULT 'slot_free',
				expires_at datetime NULL,
				PRIMARY KEY  (rule_id),
				KEY pass_scope (pass_id,event_id,ticket_type_id)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $pass_uses (
				pass_use_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				pass_id bigint(20) unsigned NOT NULL,
				rule_id bigint(20) unsigned NOT NULL,
				occurrence_id bigint(20) unsigned NOT NULL DEFAULT 0,
				ticket_type_id bigint(20) unsigned NOT NULL DEFAULT 0,
				service_date date NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				idempotency_key char(64) NOT NULL,
				resource_id bigint(20) unsigned NOT NULL DEFAULT 0,
				slot_id bigint(20) unsigned NOT NULL DEFAULT 0,
				booking_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (pass_use_id),
				UNIQUE KEY idempotency_key (idempotency_key),
				KEY pass_day (pass_id,service_date),
				KEY occurrence_id (occurrence_id)
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
				stacking varchar(16) NOT NULL DEFAULT 'combinable',
				starts_at datetime NULL,
				ends_at datetime NULL,
				status varchar(20) NOT NULL DEFAULT 'active',
				combinable tinyint(1) unsigned NOT NULL DEFAULT 1,
				with_price_rules tinyint(1) unsigned NOT NULL DEFAULT 1,
				max_discount_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				min_quantity int(10) unsigned NOT NULL DEFAULT 0,
				auto_apply tinyint(1) unsigned NOT NULL DEFAULT 0,
				per_buyer_limit int(10) unsigned NOT NULL DEFAULT 0,
				batch_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (campaign_id),
				UNIQUE KEY code (code),
				KEY auto_status (auto_apply,status),
				KEY batch_id (batch_id),
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
				quantity smallint(5) unsigned NOT NULL DEFAULT 1,
				priority int(11) NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'waiting',
				block_reason varchar(40) NOT NULL DEFAULT '',
				confirm_token_hash char(64) NOT NULL DEFAULT '',
				confirmed_at datetime NULL,
				offer_token_hash char(64) NOT NULL DEFAULT '',
				unsubscribe_token_hash char(64) NOT NULL DEFAULT '',
				offer_expires_at datetime NULL,
				hold_quantity smallint(5) unsigned NOT NULL DEFAULT 0,
				manage_token_hash char(64) NOT NULL DEFAULT '',
				confirm_expires_at datetime NULL,
				offered_at datetime NULL,
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (waitlist_id),
				UNIQUE KEY event_type_email (event_id,ticket_type_id,email_hash),
				KEY queue_order (event_id,ticket_type_id,status,position),
				KEY offer_expiry (status,offer_expires_at),
				KEY confirm_expiry (status,confirm_expires_at),
				KEY type_hold (ticket_type_id,status,offer_expires_at),
				KEY type_queue (ticket_type_id,status,priority,position),
				KEY order_id (order_id)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$wpdb->prefix}wpcap_service_waitlist (
				entry_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				slot_id bigint(20) unsigned NOT NULL,
				email_hash char(64) NOT NULL,
				email varchar(190) NOT NULL,
				quantity smallint(5) unsigned NOT NULL DEFAULT 1,
				position bigint(20) unsigned NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'waiting',
				offer_token_hash char(64) NOT NULL DEFAULT '',
				offer_expires_at datetime NULL,
				manage_token_hash char(64) NOT NULL DEFAULT '',
				confirm_token_hash char(64) NOT NULL DEFAULT '',
				confirm_expires_at datetime NULL,
				booking_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (entry_id),
				UNIQUE KEY slot_email (slot_id,email_hash),
				KEY queue_order (slot_id,status,position),
				KEY offer_expiry (status,offer_expires_at),
				KEY confirm_expiry (status,confirm_expires_at)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $api_keys (
				api_key_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				public_prefix char(12) NOT NULL,
				secret_hash char(64) NOT NULL,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				scopes varchar(255) NOT NULL,
				label varchar(190) NOT NULL DEFAULT '',
				rate_limit int(10) unsigned NOT NULL DEFAULT 0,
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
				label varchar(190) NOT NULL DEFAULT '',
				place_of_supply_note text NULL,
				activated_at datetime NULL,
				created_at datetime NOT NULL,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
				updated_at datetime NULL,
				reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
				reviewed_at datetime NULL,
				review_note varchar(190) NOT NULL DEFAULT '',
				retired_by bigint(20) unsigned NOT NULL DEFAULT 0,
				retired_at datetime NULL,
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
			"CREATE TABLE {$wpdb->prefix}wpcap_payment_events (
				payment_event_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				provider varchar(32) NOT NULL,
				provider_event_id varchar(191) NOT NULL,
				event_type varchar(64) NOT NULL DEFAULT '',
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'processing',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (payment_event_id),
				UNIQUE KEY provider_event (provider,provider_event_id),
				KEY order_id (order_id),
				KEY cleanup (status,updated_at)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$wpdb->prefix}wpcap_refunds (
				refund_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				order_id bigint(20) unsigned NOT NULL,
				idempotency_key char(64) NOT NULL,
				amount_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				tax_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				currency char(3) NOT NULL,
				ticket_ids longtext NOT NULL,
				reason varchar(255) NOT NULL DEFAULT '',
				status varchar(24) NOT NULL DEFAULT 'pending_approval',
				provider_reference varchar(191) NOT NULL DEFAULT '',
				requested_by bigint(20) unsigned NOT NULL DEFAULT 0,
				approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
				error_message varchar(255) NOT NULL DEFAULT '',
				credit_note_number varchar(40) NOT NULL DEFAULT '',
				provider_attempt smallint(5) unsigned NOT NULL DEFAULT 0,
				deducted_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				source varchar(20) NOT NULL DEFAULT '',
				provider_status varchar(20) NOT NULL DEFAULT '',
				reversed_at datetime NULL DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (refund_id),
				UNIQUE KEY idempotency_key (idempotency_key),
				KEY order_status (order_id,status),
				KEY status_updated (status,updated_at)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$wpdb->prefix}wpcap_credit_notes (
				credit_note_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				invoice_id bigint(20) unsigned NOT NULL,
				refund_id bigint(20) unsigned NOT NULL,
				credit_note_number varchar(40) NOT NULL,
				currency char(3) NOT NULL,
				net_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				tax_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				total_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				rates longtext NULL,
				issued_at datetime NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'issued',
				voided_at datetime NULL,
				reversal_number varchar(40) NOT NULL DEFAULT '',
				reversed_at datetime NULL DEFAULT NULL,
				PRIMARY KEY  (credit_note_id),
				UNIQUE KEY refund_id (refund_id),
				UNIQUE KEY credit_note_number (credit_note_number),
				KEY invoice_id (invoice_id),
				KEY issued_at (issued_at)
			) ENGINE=InnoDB $charset;",
			// Credit note split per VAT rate (3.17.4). Credit notes issued before have no lines and keep
			// their single net/tax/total presentation (no recalculation of issued documents).
			"CREATE TABLE {$wpdb->prefix}wpcap_credit_note_lines (
				credit_note_line_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				credit_note_id bigint(20) unsigned NOT NULL,
				tax_rate_bp int(10) unsigned NOT NULL DEFAULT 0,
				net_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				tax_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				total_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (credit_note_line_id),
				KEY credit_note_id (credit_note_id)
			) ENGINE=InnoDB $charset;",
			// Payments that need a decision (Payments\Cases, 3.17.4).
			"CREATE TABLE {$wpdb->prefix}wpcap_payment_cases (
				case_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				provider varchar(32) NOT NULL,
				kind varchar(32) NOT NULL,
				reference varchar(191) NOT NULL DEFAULT '',
				amount_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				currency char(3) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'open',
				note varchar(255) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (case_id),
				UNIQUE KEY case_reference (provider,kind,reference),
				KEY status (status),
				KEY order_id (order_id)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$wpdb->prefix}wpcap_document_sequences (
				series varchar(40) NOT NULL,
				year smallint(5) unsigned NOT NULL,
				next_number bigint(20) unsigned NOT NULL DEFAULT 1,
				PRIMARY KEY  (series,year)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$wpdb->prefix}wpcap_device_pairings (
				pairing_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				code_hash char(64) NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				event_ids longtext NULL,
				label varchar(190) NOT NULL DEFAULT '',
				device_hours smallint(5) unsigned NOT NULL DEFAULT 24,
				expires_at datetime NOT NULL,
				used_at datetime NULL,
				device_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (pairing_id),
				UNIQUE KEY code_hash (code_hash),
				KEY user_id (user_id)
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
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (job_id),
				UNIQUE KEY idempotency_key (idempotency_key),
				KEY due_jobs (status,run_after),
				KEY job_type (job_type,status),
				KEY order_id (order_id)
			) ENGINE=InnoDB $charset;",
			// Support log (schema 3.10.16, A327; see Log).
			"CREATE TABLE {$wpdb->prefix}wpcap_logs (
				log_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				level varchar(10) NOT NULL DEFAULT 'info',
				channel varchar(32) NOT NULL DEFAULT '',
				reference char(12) NOT NULL DEFAULT '',
				request_id char(12) NOT NULL DEFAULT '',
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				ticket_id bigint(20) unsigned NOT NULL DEFAULT 0,
				occurrence_id bigint(20) unsigned NOT NULL DEFAULT 0,
				message varchar(500) NOT NULL DEFAULT '',
				context longtext NULL,
				PRIMARY KEY  (log_id),
				KEY created_at (created_at),
				KEY reference (reference),
				KEY order_id (order_id,log_id),
				KEY level (level,log_id)
			) ENGINE=InnoDB $charset;",
			// Resumable cancellation/notification runs (schema 3.10.13, A428; see BulkRuns).
			"CREATE TABLE {$wpdb->prefix}wpcap_bulk_runs (
				run_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				run_key varchar(120) NOT NULL,
				kind varchar(32) NOT NULL,
				object_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'running',
				phase varchar(32) NOT NULL DEFAULT '',
				cursor_id bigint(20) unsigned NOT NULL DEFAULT 0,
				payload longtext NOT NULL,
				cancelled_orders int(10) unsigned NOT NULL DEFAULT 0,
				revoked int(10) unsigned NOT NULL DEFAULT 0,
				refunds int(10) unsigned NOT NULL DEFAULT 0,
				refunds_open int(10) unsigned NOT NULL DEFAULT 0,
				mails_queued int(10) unsigned NOT NULL DEFAULT 0,
				locked_until datetime NULL,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				finished_at datetime NULL,
				PRIMARY KEY  (run_id),
				UNIQUE KEY run_key (run_key),
				KEY object_runs (kind,object_id),
				KEY open_runs (status,updated_at)
			) ENGINE=InnoDB $charset;",
			// Replaced codes and revocation reasons for the scanner (schema 3.10.20, A543; see CodeHistory).
			"CREATE TABLE {$wpdb->prefix}wpcap_code_history (
				history_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				ticket_id bigint(20) unsigned NOT NULL,
				token_hash char(64) NOT NULL DEFAULT '',
				kind varchar(20) NOT NULL,
				reason varchar(32) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				PRIMARY KEY  (history_id),
				KEY token_hash (token_hash),
				KEY ticket_kind (ticket_id,kind)
			) ENGINE=InnoDB $charset;",
			// Archived legal text versions per language (schema 3.10.21, A568; see LegalVersions). Append-only.
			"CREATE TABLE {$wpdb->prefix}wpcap_legal_versions (
				legal_version_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				version varchar(64) NOT NULL,
				locale varchar(20) NOT NULL DEFAULT '',
				terms_text longtext NOT NULL,
				consumer_info_text longtext NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (legal_version_id),
				UNIQUE KEY version (version),
				KEY created_at (created_at)
			) ENGINE=InnoDB $charset;",
			// Event outbox of the workflow contract (schema 3.10.24, A675; see Workflow\Outbox).
			Workflow\Outbox::schema( $wpdb->prefix, $charset ),
			// Idempotency and results of workflow commands v1 (schema 3.10.27, A676; see Workflow\Commands).
			Workflow\Commands::schema( $wpdb->prefix, $charset ),
			// Atomic rate-limit counters without a persistent object cache (QA-V2/PERF-16, src/RateLimit.php).
			RateLimit::schema( $wpdb->prefix . 'wpcap_rate_limits', $charset ),
			// Atomic idempotency claims of check-ins (audit R2 #6, src/Checkin.php).
			Checkin::claims_schema( $wpdb->prefix . 'wpcap_checkin_claims', $charset ),
			// Reminder unsubscribes and bounce/complaint reports, address hashes only (DB 3.17.2, src/Mail/Suppression.php).
			Mail\Suppression::schema( $wpdb->prefix . 'wpcap_mail_suppressions', $charset ),
		);

		// Price rules, buyer limits and logged consents (src/Sales, docs/SALES-MODELS.md, docs/TAX-PROFILES.md).
		$queries = array_merge( $queries, Sales\Schema::statements( $wpdb->prefix, $charset ) );
		$all_queries = array_merge( $queries, Modules::schema( $wpdb->prefix, $charset ) );
		// Structural changes dbDelta cannot make (both development lines of 1.5 shipped other layouts).
		self::prepare_merged_schema();
		$errors = array();
		foreach ( $all_queries as $query ) {
			$wpdb->last_error = '';
			dbDelta( $query );
			if ( '' !== (string) $wpdb->last_error ) { $errors[] = (string) $wpdb->last_error; }
		}
		// dbDelta only adds indexes: changed and removed ones are fixed here (schema 3.10.4, A181).
		self::upgrade_indexes( $all_queries );
		// dbDelta reports no errors: verify every table/column exists before the version is stored,
		// otherwise the next request would rely on a schema that is not there.
		$missing = self::missing_schema( $all_queries );
		// A513: indexes are part of the schema, too – a failed index change is not silently stored as done.
		$missing = array_merge( $missing, self::missing_indexes( $all_queries ) );
		if ( $missing ) {
			self::schema_failed( $errors, $missing );
			if ( function_exists( 'delete_option' ) ) { delete_option( self::ATTEMPT_OPTION ); }
			return false;
		}
		if ( $errors ) {
			// The schema is complete (checked above); remaining dbDelta messages are only logged.
			Log::warning( 'schema_upgrade_notice', 'dbDelta reported: ' . implode( ' | ', array_slice( array_unique( $errors ), 0, 3 ) ), array( 'audit' => false ) );
		}
		if ( function_exists( 'delete_option' ) ) { delete_option( self::ERROR_OPTION ); }

		// One-off data migrations of both development lines of 1.5 (see migration_previous()): a site
		// that comes from the other line never ran them, whatever its version number says.
		$theirs = self::migration_previous( $previous_version, 'theirs' );
		// Capacity counters (schema 3.7.0): fresh installs use them right away, upgrades and rollbacks
		// rebuild them from the source tables (resumable, A468) and verify them before capacity
		// decisions rely on them again (A464).
		CapacityCounters::after_install( $rolled_back || $theirs !== $previous_version );
		self::pin_legacy_credit_prefix( $theirs );
		self::pin_legacy_manual_provider( $theirs );
		// The entry window default was already in force on the other line: only the own baseline decides.
		self::pin_legacy_entry_window( $previous_version );
		self::backfill_capacity_released( $theirs );
		self::backfill_ticket_updated_at( $theirs );
		self::backfill_holder_changes( $theirs );
		self::backfill_code_holder( $theirs );
		// Provider secrets leave wpcap_settings (encrypted store, idempotent; see Secrets). "pcv1:" values
		// encrypted in place by Security\Vault move first, so they are never sealed as ciphertext.
		Settings::migrate_secrets_to_store();
		if ( Secrets::migration_pending() ) { Secrets::migrate(); }
		self::align_legacy_stripe_mode( $theirs );
		self::migrate_site_scopes( $theirs );
		self::mark_legacy_google_passes( $theirs );
		self::migrate_merged_data();
		// A568: the legal texts in force at the upgrade become the first archived versions.
		LegalVersions::archive_all();

		// Roles are registered on init (A362: with their source names; translated on display).
		if ( did_action( 'init' ) || ! function_exists( 'add_action' ) ) {
			self::register_roles();
		} else {
			add_action( 'init', array( __CLASS__, 'register_roles' ), 1 );
		}

		// Encrypts provider secrets still stored in plaintext (idempotent, see Security\Vault).
		Security\Vault::migrate();
		// Uninstall opt-in out of reach of the old copy's uninstall.php (INT-1, idempotent).
		Settings::migrate_uninstall_policy();
		// wpcap_settings is read on every request: autoload it (PERF-11, idempotent).
		Settings::ensure_autoload();
		update_option( 'wpcap_db_version', self::version() );
		self::remember_max_version( (string) get_option( 'wpcap_db_version_max', '' ) );
		OrderLines::flush();
		Tax\Profiles::flush();
		update_option( 'wpcap_version', WPCAP_VERSION );
		update_option( 'wpcap_schema_fingerprint', self::fingerprint() );
		if ( get_option( self::ERROR_OPTION ) ) { delete_option( self::ERROR_OPTION ); }
		if ( function_exists( 'delete_option' ) ) { delete_option( self::ATTEMPT_OPTION ); }
		return true;
	}

	/** Option: schema line a site came from when the merged code first ran (fresh, base, ours, theirs, merged). */
	const ORIGIN_OPTION = 'wpcap_schema_origin';

	/** Last core schema version of the "theirs" line of 1.5 (A001–A709); the "ours" line went 3.6.0 → 3.17.7. */
	const THEIRS_LINE_MAX = '3.10.27';

	/** Core schema of the common ancestor of both lines (release 1.4). */
	const FORK_BASE = '3.5.0';

	/**
	 * Schema line the site came from before the merged code (3.18.0) ran the first time, remembered in
	 * ORIGIN_OPTION. Versions above THEIRS_LINE_MAX are the audit line ("ours"); versions between the
	 * fork and THEIRS_LINE_MAX exist on both lines and are told apart by markers only the "theirs" line
	 * wrote during a schema upgrade (wpcap_db_version_max, table wpcap_logs).
	 *
	 * @param string $stored wpcap_db_version before this run.
	 */
	public static function schema_origin( $stored ) {
		$known = get_option( self::ORIGIN_OPTION, '' );
		if ( is_string( $known ) && '' !== $known ) { return $known; }
		global $wpdb;
		$core = self::core_version( $stored );
		if ( '' === $core || ! preg_match( '/^\d+\.\d+/', $core ) ) {
			$origin = 'fresh';
		} elseif ( version_compare( $core, self::DB_VERSION, '>=' ) ) {
			$origin = 'merged';
		} elseif ( version_compare( $core, self::FORK_BASE, '<=' ) ) {
			$origin = 'base';
		} elseif ( version_compare( $core, self::THEIRS_LINE_MAX, '>' ) ) {
			$origin = 'ours';
		} else {
			$logs = $wpdb->prefix . 'wpcap_logs';
			$theirs = false !== get_option( 'wpcap_db_version_max', false )
				|| $logs === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', addcslashes( $logs, '_%\\' ) ) );
			$origin = $theirs ? 'theirs' : 'ours';
		}
		update_option( self::ORIGIN_OPTION, $origin, false );
		return $origin;
	}

	/**
	 * Previous schema version a one-off data migration of $line ('ours' or 'theirs') compares against.
	 * Both lines numbered their schemas from 3.5.0 independently, so a site from the other line can carry
	 * a version above a migration's threshold without ever having run it: for such a site the fork base
	 * is returned (the migration runs once, as for a release-1.4 site). Once the merged schema is stored
	 * the real version is returned and nothing runs again.
	 *
	 * @param string $previous wpcap_db_version before this run (migration_baseline()).
	 * @param string $line     Line the migration belongs to.
	 */
	public static function migration_previous( $previous, $line ) {
		$core = self::core_version( $previous );
		if ( '' === $core || ! preg_match( '/^\d+\.\d+/', $core ) || version_compare( $core, self::DB_VERSION, '>=' ) ) { return (string) $previous; }
		$origin = self::schema_origin( (string) get_option( 'wpcap_db_version', '' ) );
		return in_array( $origin, array( 'ours', 'theirs' ), true ) && $origin !== $line ? self::FORK_BASE : (string) $previous;
	}

	/**
	 * Before dbDelta: layouts both lines created differently that dbDelta cannot convert. Idempotent.
	 * - wpcap_rate_limits: the "theirs" line keyed counters by rl_key, the merged table by bucket_key
	 *   (PRIMARY KEY change). The table only holds counters of the running minute/hour: it is dropped
	 *   and recreated by dbDelta.
	 * - Box office: the audit line's varchar register_id becomes fiscal_register_id (Modules\BoxOffice).
	 */
	private static function prepare_merged_schema() {
		global $wpdb;
		$rate = $wpdb->prefix . 'wpcap_rate_limits';
		$suppress = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
		if ( $wpdb->get_row( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $rate, 'rl_key' ) ) ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $rate ) );
		}
		if ( null !== $suppress ) { $wpdb->suppress_errors( $suppress ); }
		if ( method_exists( Modules\BoxOffice::class, 'rename_legacy_register_column' ) ) {
			Modules\BoxOffice::rename_legacy_register_column();
		}
	}

	/**
	 * After dbDelta: data conversions between the two lines, checked on the data itself (idempotent,
	 * cheap when nothing is left to convert).
	 * - Waitlist entries waiting for their double opt-in: status "unconfirmed" (audit line) is "pending".
	 * - Proxy header: a header chosen in client_ip_header ("theirs" line) is carried over to
	 *   trusted_proxy_header, which the settings screen edits now.
	 *
	 * @return array Counts of converted rows/settings.
	 */
	public static function migrate_merged_data() {
		global $wpdb;
		$done = array();
		$waitlist = $wpdb->prefix . 'wpcap_waitlist';
		$done['waitlist_pending'] = (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='pending' WHERE status='unconfirmed'", $waitlist ) );
		$stored = get_option( Settings::OPTION, array() );
		if ( is_array( $stored ) && ! empty( $stored['client_ip_header'] ) && ! array_key_exists( 'trusted_proxy_header', $stored ) ) {
			$map = array( 'x-forwarded-for' => 'x_forwarded_for', 'forwarded' => 'forwarded', 'cf-connecting-ip' => 'cf_connecting_ip' );
			$stored['trusted_proxy_header'] = $map[ (string) $stored['client_ip_header'] ] ?? 'x_forwarded_for';
			update_option( Settings::OPTION, $stored, true );
			Settings::flush();
			$done['proxy_header'] = 1;
		}
		return $done;
	}

	/** Option holding details of the last failed schema upgrade (shown as admin notice until an upgrade succeeds). */
	const ERROR_OPTION = 'wpcap_schema_error';

	/** Option holding the newer core schema version found in the database (downgrade; removed once the code is current again). */
	const NEWER_OPTION = 'wpcap_schema_newer';

	/** Core schema version of a stored wpcap_db_version ("3.16.1+hash" -> "3.16.1"; '' when none). */
	public static function core_version( $stored ) {
		$core = strtok( (string) $stored, '+' );
		return false === $core ? '' : trim( $core );
	}

	/**
	 * Whether the stored schema comes from a newer plugin version than this code (version_compare, not
	 * equality: an older copy must neither run install() nor write its version back). Keeps the option
	 * wpcap_schema_newer in sync: set to the newer version, removed once the code is current again.
	 *
	 * @param string|false|null $stored wpcap_db_version (null: read the option).
	 */
	public static function schema_is_newer( $stored = null ) {
		$stored = null === $stored ? get_option( 'wpcap_db_version' ) : $stored;
		$core = self::core_version( $stored );
		$newer = (bool) preg_match( '/^\d+(\.\d+)*$/', $core ) && version_compare( $core, self::DB_VERSION, '>' );
		$flag = get_option( self::NEWER_OPTION );
		if ( $newer && $flag !== $core ) {
			update_option( self::NEWER_OPTION, $core, false );
		} elseif ( ! $newer && false !== $flag ) {
			delete_option( self::NEWER_OPTION );
		}
		return $newer;
	}

	/** Admin notice while the database is newer than the plugin code (downgrade). */
	public static function render_newer_notice() {
		$newer = get_option( self::NEWER_OPTION );
		if ( ! $newer || ! current_user_can( 'activate_plugins' ) ) { return; }
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			/* translators: 1: schema version in the database, 2: schema version of the installed plugin */
			esc_html( sprintf( __( 'Pass Captain: Die Datenbank stammt von einer neueren Plugin-Version (Schema %1$s, dieses Plugin kennt %2$s). Es wurde nichts verändert. Bitte die neuere Plugin-Version wieder installieren oder eine Datensicherung von vor dem Update wiederherstellen (Restore erforderlich) – mit älterem Code können neuere Daten (z. B. verschlüsselte Zugangsdaten) unlesbar sein.', 'pass-captain' ), (string) $newer, self::DB_VERSION ) )
		);
	}

	/** Option: an upgrade attempt that started and did not finish yet ({target, count, at}; A466). */
	const ATTEMPT_OPTION = 'wpcap_schema_attempt';

	/** Remembers that an upgrade attempt starts (counts consecutive unfinished attempts per target). */
	private static function begin_attempt() {
		$previous = get_option( self::ATTEMPT_OPTION );
		$target = self::version();
		$count = is_array( $previous ) && ( $previous['target'] ?? '' ) === $target ? (int) ( $previous['count'] ?? 0 ) + 1 : 1;
		update_option( self::ATTEMPT_OPTION, array( 'target' => $target, 'count' => $count, 'at' => time() ), false );
	}

	/** First retry after a failed schema upgrade (seconds); doubles per failure up to RETRY_MAX (A466). */
	const RETRY_FIRST = 300;
	/** Longest wait between two automatic attempts of a failing schema upgrade (seconds). */
	const RETRY_MAX = 6 * 3600;

	/**
	 * A failed schema upgrade (A466): remembered with the target schema, the number of failed attempts
	 * and the time of the next automatic attempt. Until then admin, cron and frontend requests do not
	 * run dbDelta again (WP-CLI and the "retry" button do); a new plugin version (another target)
	 * retries at once. Audited once per failed attempt.
	 *
	 * @param string[] $missing  Missing tables/columns.
	 * @param string   $db_error Last database error (redacted).
	 */
	private static function record_failure( array $missing, $db_error ) {
		$previous = get_option( 'wpcap_schema_error' );
		$target = self::version();
		$attempts = is_array( $previous ) && ( $previous['target'] ?? '' ) === $target ? (int) ( $previous['attempts'] ?? 0 ) + 1 : 1;
		$delay = (int) min( self::RETRY_MAX, self::RETRY_FIRST * ( 2 ** min( 16, $attempts - 1 ) ) );
		/**
		 * Seconds until the next automatic attempt of a failing schema upgrade (A466).
		 *
		 * @param int $delay    Default: 5 minutes, doubled per failed attempt, at most 6 hours.
		 * @param int $attempts Failed attempts for this schema version so far.
		 */
		$delay = max( 0, (int) apply_filters( 'wpcap_schema_retry_delay', $delay, $attempts ) );
		update_option( 'wpcap_schema_error', array( 'missing' => $missing, 'at' => time(), 'db_error' => $db_error, 'target' => $target, 'attempts' => $attempts, 'retry_at' => time() + $delay ), false );
		Audit::record( 'schema_upgrade_failed', 'system', 0, array( 'missing' => $missing, 'attempts' => $attempts, 'retry_at' => gmdate( 'Y-m-d H:i:s', time() + $delay ) ) );
	}

	/**
	 * Time of the next automatic attempt while a failed schema upgrade for the current target waits
	 * (A466); 0 when an upgrade may run now.
	 */
	public static function retry_at() {
		$target = self::version();
		$at = 0;
		$error = get_option( 'wpcap_schema_error' );
		if ( is_array( $error ) && ( $error['target'] ?? '' ) === $target ) { $at = (int) ( $error['retry_at'] ?? 0 ); }
		// Attempts that died mid-upgrade (time limit, fatal error): the first one is repeated at once,
		// from the second one on with the same backoff.
		$attempt = get_option( self::ATTEMPT_OPTION );
		if ( is_array( $attempt ) && ( $attempt['target'] ?? '' ) === $target && (int) ( $attempt['count'] ?? 0 ) >= 2 ) {
			$count = (int) $attempt['count'];
			$at = max( $at, (int) $attempt['at'] + (int) min( self::RETRY_MAX, self::RETRY_FIRST * ( 2 ** min( 16, $count - 2 ) ) ) );
		}
		return $at > time() ? $at : 0;
	}

	/** Lets the next request retry a failed schema upgrade at once (admin button, WP-CLI). */
	public static function allow_retry() {
		if ( function_exists( 'delete_option' ) ) { delete_option( self::ATTEMPT_OPTION ); }
		$error = get_option( 'wpcap_schema_error' );
		if ( ! is_array( $error ) ) { return false; }
		$error['retry_at'] = 0;
		update_option( 'wpcap_schema_error', $error, false );
		return true;
	}

	/**
	 * Schema version that one-off data migrations compare against (A113).
	 *
	 * After a plugin rollback the older code rewrites `wpcap_db_version` to its own, lower version.
	 * Rolling forward again must not treat the site as if it came from that old version (that would
	 * rerun one-off migrations, e.g. pin the legacy credit-note prefix on a site that already issued
	 * documents with the new one). `wpcap_db_version_max` remembers the highest schema this site ever
	 * ran; older code does not know the option and leaves it alone.
	 *
	 * @param string $stored  wpcap_db_version as stored ('' = fresh install).
	 * @param string $highest wpcap_db_version_max as stored ('' = unknown).
	 * @return string The higher of both.
	 */
	public static function migration_baseline( $stored, $highest ) {
		$a = (string) strtok( (string) $stored, '+' );
		$b = (string) strtok( (string) $highest, '+' );
		if ( '' === $b || ! preg_match( '/^\d+\.\d+/', $b ) ) { return (string) $stored; }
		if ( '' === $a || ! preg_match( '/^\d+\.\d+/', $a ) ) { return (string) $highest; }
		return version_compare( $b, $a, '>' ) ? (string) $highest : (string) $stored;
	}

	/**
	 * Whether older plugin code ran on this site after a newer schema (A464): the stored schema
	 * version is lower than the highest one ever stored. Older code rewrites `wpcap_db_version` to its
	 * own version but does not know `wpcap_db_version_max`.
	 *
	 * @param string $stored  wpcap_db_version as stored.
	 * @param string $highest wpcap_db_version_max as stored.
	 */
	public static function rolled_back( $stored, $highest ) {
		$a = (string) strtok( (string) $stored, '+' );
		$b = (string) strtok( (string) $highest, '+' );
		if ( ! preg_match( '/^\d+\.\d+/', $a ) || ! preg_match( '/^\d+\.\d+/', $b ) ) { return false; }
		return version_compare( $b, $a, '>' );
	}

	/** Stores the highest schema version this site ran (never lowers it). */
	private static function remember_max_version( $highest ) {
		$current = (string) strtok( self::version(), '+' );
		$known   = (string) strtok( (string) $highest, '+' );
		if ( '' === $known || ! preg_match( '/^\d+\.\d+/', $known ) || version_compare( $current, $known, '>' ) ) {
			update_option( 'wpcap_db_version_max', $current );
		}
	}

	/**
	 * Schema 3.8.3: the default prefix of refund corrections changed from "GS" (read as "Gutschrift",
	 * which in DE tax law means self-billing) to "RK" ("Rechnungskorrektur"). Installs that existed
	 * before keep the prefix their running correction range uses: when no prefix is stored, "GS" is
	 * stored explicitly. A configured prefix and issued documents are never changed.
	 *
	 * @param string $previous_version wpcap_db_version before this install run ('' = fresh install).
	 */
	/**
	 * Schema 3.10.10 (A579): tickets record the release of their capacity unit. Refunded tickets of
	 * earlier versions already released it (refunded_quantity); they are marked once, so a later flow
	 * never releases them a second time. Idempotent.
	 *
	 * @param string $previous_version wpcap_db_version before this install run ('' = fresh install).
	 */
	public static function backfill_capacity_released( $previous_version ) {
		global $wpdb;
		$previous = (string) strtok( (string) $previous_version, '+' );
		if ( 1 !== preg_match( '/^\d+\.\d+/', $previous ) || version_compare( $previous, '3.10.10', '>=' ) || ! CapacityRelease::ready() ) { return false; }
		return false !== $wpdb->query( "UPDATE {$wpdb->prefix}wpcap_tickets SET capacity_released=1 WHERE refunded=1 AND capacity_released=0" );
	}

	/**
	 * Schema 3.10.22 (A574): wpcap_tickets.updated_at (TIMESTAMP, kept by the database on every change
	 * of the row) is what the partner API's "since" filter for tickets compares. Existing tickets start
	 * at their issue time instead of the upgrade time, so the first poll after the update does not
	 * return every ticket as changed. Idempotent (only rows still carrying the column default of this
	 * run are touched), run once per upgrade from an older schema.
	 *
	 * @param string $previous_version wpcap_db_version before this install run ('' = fresh install).
	 */
	public static function backfill_ticket_updated_at( $previous_version ) {
		global $wpdb;
		$previous = (string) strtok( (string) $previous_version, '+' );
		if ( 1 !== preg_match( '/^\d+\.\d+/', $previous ) || version_compare( $previous, '3.10.22', '>=' ) || ! ServiceBookings::has_column( 'wpcap_tickets', 'updated_at' ) ) { return false; }
		// issued_at is UTC; the TIMESTAMP column converts from the session time zone.
		$zone = (string) $wpdb->get_var( 'SELECT @@session.time_zone' );
		$wpdb->query( "SET time_zone='+00:00'" );
		$done = $wpdb->query( "UPDATE {$wpdb->prefix}wpcap_tickets SET updated_at=issued_at WHERE issued_at>'1970-01-02' AND updated_at>=issued_at" );
		if ( '' !== $zone ) { $wpdb->query( $wpdb->prepare( 'SET time_zone=%s', $zone ) ); }
		return false !== $done;
	}

	/**
	 * Schema 3.10.22 (A569): holder and name changes are counted on the ticket. Holder changes made
	 * before were only audit entries (`ticket_holder_changed`); they are carried over once, so the
	 * limit keeps applying when the audit log is purged later. Idempotent (GREATEST).
	 *
	 * @param string $previous_version wpcap_db_version before this install run ('' = fresh install).
	 */
	public static function backfill_holder_changes( $previous_version ) {
		global $wpdb;
		$previous = (string) strtok( (string) $previous_version, '+' );
		if ( 1 !== preg_match( '/^\d+\.\d+/', $previous ) || version_compare( $previous, '3.10.22', '>=' ) || ! TicketAssignments::counters_ready() ) { return false; }
		$p = $wpdb->prefix;
		return false !== $wpdb->query( "UPDATE {$p}wpcap_tickets t INNER JOIN (SELECT object_id,COUNT(*) AS n FROM {$p}wpcap_audit_logs WHERE object_type='ticket' AND action='ticket_holder_changed' GROUP BY object_id) a ON a.object_id=t.ticket_id SET t.holder_changes=GREATEST(t.holder_changes,LEAST(a.n,255))" );
	}

	/**
	 * Schema 3.10.22 (A581): wpcap_tickets.code_with_attendee says whether the current code was mailed
	 * to the attendee address instead of the buyer. Before, an attendee address alone was treated as
	 * "held elsewhere". Existing tickets with an attendee address other than the buyer's are marked
	 * unless the audit log shows that only the buyer received the code: no transfer, no assignment to
	 * another address, no reissue, no resend or failed ticket mail of the order, and the audit log
	 * reaches back to the issue of the ticket. Run once per upgrade from an older schema.
	 *
	 * @param string $previous_version wpcap_db_version before this install run ('' = fresh install).
	 */
	public static function backfill_code_holder( $previous_version ) {
		global $wpdb;
		$previous = (string) strtok( (string) $previous_version, '+' );
		if ( 1 !== preg_match( '/^\d+\.\d+/', $previous ) || version_compare( $previous, '3.10.22', '>=' ) || ! TicketCodes::holder_ready() ) { return false; }
		$p = $wpdb->prefix;
		$oldest = (string) $wpdb->get_var( "SELECT MIN(created_at) FROM {$p}wpcap_audit_logs" );
		$oldest = '' !== $oldest ? $oldest : gmdate( 'Y-m-d H:i:s' );
		return false !== $wpdb->query( $wpdb->prepare(
			"UPDATE {$p}wpcap_tickets t INNER JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id SET t.code_with_attendee=1
			WHERE t.code_with_attendee=0 AND t.attendee_email<>'' AND LOWER(t.attendee_email)<>LOWER(ord.customer_email) AND (
				t.transfer_count>0 OR t.issued_at<%s
				OR EXISTS (SELECT 1 FROM {$p}wpcap_audit_logs a WHERE a.object_type='ticket' AND a.object_id=t.ticket_id AND a.action IN ('ticket_holder_changed','ticket_reissued','ticket_transferred'))
				OR EXISTS (SELECT 1 FROM {$p}wpcap_audit_logs a WHERE a.object_type='order' AND a.object_id=t.order_id AND a.action IN ('tickets_resent','ticket_mail_failed'))
			)",
			$oldest
		) );
	}

	public static function pin_legacy_credit_prefix( $previous_version ) {
		global $wpdb;
		$previous = (string) strtok( (string) $previous_version, '+' );
		$existing = 1 === preg_match( '/^\d+\.\d+/', $previous ) && version_compare( $previous, '3.8.3', '<' );
		if ( ! $existing ) {
			// Fallback for a lost version option: documents with the former default prefix exist.
			$existing = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT credit_note_id FROM {$wpdb->prefix}wpcap_credit_notes WHERE credit_note_number LIKE %s LIMIT 1", addcslashes( Invoices::CREDIT_PREFIX_LEGACY . '-', '_%\\' ) . '%' ) );
		}
		if ( ! $existing ) { return false; }
		$stored = get_option( Settings::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		if ( '' !== (string) ( $stored['credit_note_prefix'] ?? '' ) ) { return false; }
		$stored['credit_note_prefix'] = Invoices::CREDIT_PREFIX_LEGACY;
		update_option( Settings::OPTION, $stored, false );
		Settings::flush();
		return true;
	}

	/**
	 * Schema 3.10.9 (A265): new installs start without an enabled payment method (before: "manual").
	 * Installs that existed before and never saved the payment methods relied on the old default;
	 * they keep "manual" enabled (stored explicitly). Whether it is complete is shown by
	 * Admin\Menu::manual_payment_notice(); the checkout only offers it once it is configured.
	 *
	 * @param string $previous_version wpcap_db_version before this install run ('' = fresh install).
	 */
	public static function pin_legacy_manual_provider( $previous_version ) {
		$previous = (string) strtok( (string) $previous_version, '+' );
		if ( 1 !== preg_match( '/^\d+\.\d+/', $previous ) || ! version_compare( $previous, '3.10.9', '<' ) ) { return false; }
		$stored = get_option( Settings::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		if ( array_key_exists( 'enabled_providers', $stored ) ) { return false; }
		$stored['enabled_providers'] = array( 'manual' );
		update_option( Settings::OPTION, $stored, false );
		Settings::flush();
		return true;
	}

	/** Option: the upgrade kept the default entry window off for this existing install (A465, admin notice). */
	const ENTRY_WINDOW_NOTICE = 'wpcap_entry_window_pinned';

	/**
	 * A465: releases up to 1.4.0 (schema 3.5.0 and older) admitted tickets without their own admission
	 * rule at any time; since then new installs default to an entry window (2 h before the start until
	 * the end, `default_entry_window`). Installs that existed before and never saved the scanner
	 * settings keep the old behaviour: the window is stored as off and an admin notice explains the
	 * new option. A stored choice is never changed; a rollback/roll-forward does not pin again
	 * (migration_baseline()).
	 *
	 * @param string $previous_version wpcap_db_version before this install run ('' = fresh install).
	 */
	public static function pin_legacy_entry_window( $previous_version ) {
		$previous = (string) strtok( (string) $previous_version, '+' );
		if ( 1 !== preg_match( '/^\d+\.\d+/', $previous ) || ! version_compare( $previous, '3.6.0', '<' ) ) { return false; }
		$stored = get_option( Settings::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		if ( array_key_exists( 'default_entry_window', $stored ) ) { return false; }
		$stored['default_entry_window'] = 0;
		update_option( Settings::OPTION, $stored, false );
		update_option( self::ENTRY_WINDOW_NOTICE, 1, false );
		Settings::flush();
		return true;
	}

	/**
	 * Schema 3.10.15 (A274): the Stripe mode is enforced from now on (a key of the other mode makes
	 * Stripe unavailable). Before, the setting had no effect and was left at its default "test" by
	 * shops selling with a live key; they are switched to "live" so Stripe stays in their checkout.
	 * A test key with "live" is left alone: that shop did not collect real payments and now sees
	 * the mismatch in the settings.
	 *
	 * @param string $previous_version wpcap_db_version before this install run ('' = fresh install).
	 */
	public static function align_legacy_stripe_mode( $previous_version ) {
		$previous = (string) strtok( (string) $previous_version, '+' );
		if ( 1 !== preg_match( '/^\d+\.\d+/', $previous ) || ! version_compare( $previous, '3.10.15', '<' ) ) { return false; }
		if ( 'live' !== Payments\StripeGateway::key_mode( Secrets::get( 'stripe_secret_key' ) ) || 'live' === Settings::get( 'stripe_mode' ) ) { return false; }
		Settings::update( array( 'stripe_mode' => 'live' ) );
		Audit::record( 'stripe_mode_aligned', 'settings', 0, array( 'mode' => 'live' ) );
		return true;
	}

	/**
	 * Schema 3.10.19 (A470): access scopes become per-site user options; the former network-wide
	 * user meta is copied to this site's key (Access::migrate_legacy_scopes()). Idempotent.
	 *
	 * @param string $previous_version wpcap_db_version before this install run ('' = fresh install).
	 */
	public static function migrate_site_scopes( $previous_version ) {
		$previous = (string) strtok( (string) $previous_version, '+' );
		if ( 1 !== preg_match( '/^\d+\.\d+/', $previous ) || ! version_compare( $previous, '3.10.19', '<' ) ) { return 0; }
		return Access::migrate_legacy_scopes();
	}

	/**
	 * Schema 3.10.21 (A566): Google Wallet objects are only updated when they exist at Google
	 * (`remote_at`, set when the buyer uses the save link). Whether passes from before this schema
	 * were saved is unknown, so they keep being updated (never created) as before.
	 *
	 * @param string $previous_version wpcap_db_version before this install run ('' = fresh install).
	 */
	public static function mark_legacy_google_passes( $previous_version ) {
		global $wpdb;
		$previous = (string) strtok( (string) $previous_version, '+' );
		if ( 1 !== preg_match( '/^\d+\.\d+/', $previous ) || ! version_compare( $previous, '3.10.21', '<' ) || ! WalletPasses\Store::remote_ready() ) { return 0; }
		return (int) $wpdb->query( "UPDATE {$wpdb->prefix}wpcap_wallet_passes SET remote_at=updated_at WHERE provider='google' AND remote_at IS NULL" );
	}

	/**
	 * Tables and columns of the CREATE TABLE statements that do not exist in the database
	 * ("table" or "table.column").
	 *
	 * @param string[] $queries CREATE TABLE statements (dbDelta format).
	 * @return string[]
	 */
	public static function missing_schema( array $queries ) {
		global $wpdb;
		$missing = array();
		foreach ( $queries as $query ) {
			if ( ! preg_match( '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_$]+)`?\s*\((.*)\)/is', (string) $query, $m ) ) { continue; }
			$table = $m[1];
			$expected = array();
			foreach ( preg_split( '/\r?\n/', $m[2] ) as $line ) {
				$line = trim( $line );
				if ( '' === $line || ! preg_match( '/^`?([A-Za-z0-9_]+)`?\s/', $line, $c ) ) { continue; }
				if ( in_array( strtoupper( $c[1] ), array( 'PRIMARY', 'KEY', 'UNIQUE', 'INDEX', 'FULLTEXT', 'SPATIAL', 'CONSTRAINT', 'FOREIGN', 'CHECK' ), true ) ) { continue; }
				$expected[] = strtolower( $c[1] );
			}
			$columns = $wpdb->get_col( "SHOW COLUMNS FROM `$table`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
			if ( ! $columns ) {
				$missing[] = $table;
				continue;
			}
			foreach ( array_diff( $expected, array_map( 'strtolower', (array) $columns ) ) as $column ) { $missing[] = $table . '.' . $column; }
		}
		return $missing;
	}

	/**
	 * Records a failed schema upgrade: PHP error log plus an option that drives the admin notice.
	 * Consecutive failures of the same schema version back off (RETRY_DELAYS), so a broken database
	 * does not run dbDelta on every admin/cron request; the system status page can force a retry.
	 */
	private static function schema_failed( array $errors, array $missing ) {
		global $wpdb;
		// One record for both readers: record_failure() (A466 backoff: target, retry_at, db_error) plus the
		// keys of the system status page (version, errors, next_retry, time).
		self::record_failure( array_slice( $missing, 0, 50 ), Redact::text( (string) ( $errors ? end( $errors ) : $wpdb->last_error ) ) );
		$details = (array) get_option( self::ERROR_OPTION, array() );
		$attempts = max( 1, (int) ( $details['attempts'] ?? 1 ) );
		$details += array(
			'time'       => time(),
			'version'    => self::version(),
			'errors'     => array_slice( array_values( array_unique( array_map( array( Redact::class, 'text' ), $errors ) ) ), 0, 5 ),
			'next_retry' => (int) ( $details['retry_at'] ?? ( time() + self::retry_delay( $attempts ) ) ),
		);
		update_option( self::ERROR_OPTION, $details, false );
		// The admin notice (ERROR_OPTION) is the primary channel; the log reaches add-ons and, if enabled, the PHP error log.
		Log::error( 'schema_upgrade_failed', 'database upgrade to ' . $details['version'] . ' failed (attempt ' . $attempts . '); next retry in ' . self::retry_delay( $attempts ) . 's. Errors: ' . implode( ' | ', $details['errors'] ) . '; missing: ' . implode( ', ', $details['missing'] ), array( 'details' => $details ) );
	}

	/** Seconds to wait after the n-th consecutive failed upgrade (1 min, 5 min, 15 min, then hourly). */
	const RETRY_DELAYS = array( 60, 300, 900, 3600 );

	/** Backoff delay after $attempts consecutive failures. */
	public static function retry_delay( $attempts ) {
		$delays = self::RETRY_DELAYS;
		return (int) $delays[ max( 0, min( count( $delays ), (int) $attempts ) - 1 ) ];
	}

	/**
	 * Unix time before which no automatic upgrade retry runs; 0 when a retry is allowed now.
	 * A newer plugin/schema version than the failed one is always tried at once.
	 */
	public static function retry_blocked_until( $now = null ) {
		unset( $now );
		return self::retry_at(); // One backoff (A466): see record_failure().
	}

	/**
	 * Runs the schema upgrade now, ignoring the backoff (system status page, WP-CLI). Behind the
	 * upgrade lock like every upgrade.
	 *
	 * @return bool|null True on success, false when it failed again, null when another request holds the lock.
	 */
	public static function retry_now() {
		$result = null;
		$locked = self::run_locked(
			static function () use ( &$result ) {
				self::allow_retry();
				$result = self::install();
			}
		);
		return $locked ? (bool) $result : null;
	}

	/** Admin notice while the last schema upgrade failed (registered by maybe_upgrade() on admin requests). */
	public static function render_schema_notice() {
		$error = get_option( self::ERROR_OPTION );
		if ( ! is_array( $error ) || ! $error || ! current_user_can( 'activate_plugins' ) ) { return; }
		$detail = implode( ', ', array_merge( (array) ( $error['missing'] ?? array() ), (array) ( $error['errors'] ?? array() ) ) );
		printf(
			'<div class="notice notice-error"><p>%1$s</p><p><code>%2$s</code></p></div>',
			esc_html__( 'Pass Captain: Die Datenbank-Aktualisierung ist fehlgeschlagen. Sie wird automatisch in zunehmenden Abständen wiederholt und kann unter „Pass Captain → Systemstatus“ sofort erneut gestartet werden. Bitte das PHP-Fehlerprotokoll prüfen, falls die Meldung bleibt.', 'pass-captain' ),
			esc_html( substr( $detail, 0, 500 ) )
		);
	}

	/**
	 * Indexes removed from the schema (table without prefix => names). dbDelta never drops an index,
	 * so upgrades drop them here. Never list an index a site could have added itself.
	 */
	const OBSOLETE_INDEXES = array(
		// Replaced by capacity_sum (ticket_type_id,order_id,quantity,refunded_quantity), which covers it.
		'wpcap_order_items' => array( 'type_order' ),
	);

	/**
	 * Brings existing indexes in line with the CREATE TABLE statements, which dbDelta does not do:
	 * an index whose columns, prefix lengths or uniqueness changed is rebuilt (drop and add in one
	 * ALTER, so a failure keeps the old index), and OBSOLETE_INDEXES are dropped. Idempotent.
	 *
	 * @param string[] $queries CREATE TABLE statements (dbDelta format).
	 * @return string[] "table.index" of the indexes that were changed.
	 */
	public static function upgrade_indexes( array $queries ) {
		global $wpdb;
		$changed = array();
		foreach ( $queries as $query ) {
			if ( ! preg_match( '/CREATE TABLE\s+`?([A-Za-z0-9_]+)`?\s*\((.*)\)[^)]*$/s', (string) $query, $m ) ) { continue; }
			$table = $m[1];
			$existing = self::existing_indexes( $table );
			if ( ! $existing ) { continue; }
			$short = substr( $table, strlen( (string) $wpdb->prefix ) );
			foreach ( self::OBSOLETE_INDEXES[ $short ] ?? array() as $name ) {
				if ( isset( $existing[ $name ] ) && false !== $wpdb->query( "ALTER TABLE `$table` DROP INDEX `$name`" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- names from our own schema.
					$changed[] = "$table.$name";
				}
			}
			foreach ( self::declared_indexes( $m[2] ) as $name => $index ) {
				if ( 'PRIMARY' === $name || ! isset( $existing[ $name ] ) ) { continue; } // dbDelta adds missing indexes.
				if ( $index['unique'] === $existing[ $name ]['unique'] && $index['columns'] === $existing[ $name ]['columns'] ) { continue; }
				$type = $index['unique'] ? 'UNIQUE KEY' : 'KEY';
				if ( false !== $wpdb->query( "ALTER TABLE `$table` DROP INDEX `$name`, ADD $type `$name` ({$index['definition']})" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- definition from our own schema.
					$changed[] = "$table.$name";
				}
			}
		}
		return $changed;
	}

	/**
	 * Indexes of the CREATE TABLE statements that are missing in the database or differ from their
	 * declaration (columns, prefix lengths, uniqueness) after dbDelta and upgrade_indexes() – e.g. a
	 * UNIQUE KEY that could not be built over duplicate rows, or an ALTER that hit a lock timeout
	 * (A513). Reported as "table: index name" next to missing tables/columns (wpcap_schema_error).
	 *
	 * @param string[] $queries CREATE TABLE statements (dbDelta format).
	 * @return string[]
	 */
	public static function missing_indexes( array $queries ) {
		$missing = array();
		foreach ( $queries as $query ) {
			if ( ! preg_match( '/CREATE TABLE\s+`?([A-Za-z0-9_]+)`?\s*\((.*)\)[^)]*$/s', (string) $query, $m ) ) { continue; }
			$existing = self::existing_indexes( $m[1] );
			if ( ! $existing ) { continue; } // A missing table is reported by missing_schema().
			foreach ( self::declared_indexes( $m[2] ) as $name => $index ) {
				if ( isset( $existing[ $name ] ) && $index['unique'] === $existing[ $name ]['unique'] && $index['columns'] === $existing[ $name ]['columns'] ) { continue; }
				$missing[] = $m[1] . ': index ' . $name;
			}
		}
		return $missing;
	}

	/**
	 * Indexes of a table as the database has them: name => [unique, columns] (lower case, prefix
	 * lengths as "col(64)"). Empty when the table does not exist.
	 */
	private static function existing_indexes( $table ) {
		global $wpdb;
		$existing = array();
		foreach ( (array) $wpdb->get_results( "SHOW INDEX FROM `$table`" ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from our own schema.
			$row = (array) $row;
			$name = (string) $row['Key_name'];
			$existing[ $name ]['unique'] = ! (int) $row['Non_unique'];
			$existing[ $name ]['columns'][ (int) $row['Seq_in_index'] ] = strtolower( (string) $row['Column_name'] ) . ( null !== $row['Sub_part'] && '' !== (string) $row['Sub_part'] ? '(' . (int) $row['Sub_part'] . ')' : '' );
		}
		foreach ( $existing as $name => $index ) {
			ksort( $index['columns'] );
			$existing[ $name ]['columns'] = array_values( $index['columns'] );
		}
		return $existing;
	}

	/** Indexes declared in the body of a CREATE TABLE statement: name => [unique, columns, definition]. */
	private static function declared_indexes( $body ) {
		$declared = array();
		foreach ( preg_split( '/\R/', (string) $body ) as $line ) {
			if ( ! preg_match( '/^\s*(PRIMARY\s+KEY|UNIQUE\s+KEY|UNIQUE\s+INDEX|KEY|INDEX)\s+(?:`?([A-Za-z0-9_]+)`?\s*)?\((.+)\)\s*,?\s*$/i', $line, $k ) ) { continue; }
			$primary = 0 === stripos( $k[1], 'PRIMARY' );
			$name = $primary ? 'PRIMARY' : (string) $k[2];
			if ( '' === $name ) { continue; }
			$declared[ $name ] = array(
				'unique' => $primary || 0 === stripos( $k[1], 'UNIQUE' ),
				'columns' => array_map( static fn( $c ) => strtolower( (string) preg_replace( '/[\s`]/', '', $c ) ), explode( ',', $k[3] ) ),
				'definition' => $k[3],
			);
		}
		return $declared;
	}

	/** Cron hook that performs a deferred upgrade (scheduled by frontend requests). */
	const UPGRADE_HOOK = 'wpcap_run_upgrade';

	/** A deferred upgrade still not done after this many seconds is run by a frontend request (behind the lock). */
	const OVERDUE_AFTER = 300;

	/** Per-request cache of version(), keyed by table prefix (multisite switches sites). */
	private static $versions = array();

	/** Stored schema version: core version plus a hash of all module tables (cached per request). */
	public static function version() {
		global $wpdb;
		$key = (string) $wpdb->prefix;
		if ( ! isset( self::$versions[ $key ] ) ) {
			self::$versions[ $key ] = self::DB_VERSION . '+' . Modules::schema_version();
		}
		return self::$versions[ $key ];
	}

	/** Forgets the cached schema version (tests, or after module classes changed at runtime). */
	public static function flush_version_cache() { self::$versions = array(); }

	/**
	 * Cheap upgrade indicator that needs no schema building: core schema version, plugin version and
	 * the list of module classes. Frontend requests only compare this; admin, cron and WP-CLI
	 * requests additionally compare the full module schema hash (version()).
	 */
	public static function fingerprint() {
		return substr( md5( self::DB_VERSION . '|' . WPCAP_VERSION . '|' . implode( ',', Modules::classes() ) ), 0, 12 );
	}

	/** Request context for upgrades: cli, cron, admin or frontend (REST and admin-ajax count as frontend). */
	public static function context() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) { return 'cli'; }
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) { return 'cron'; }
		$ajax = function_exists( 'wp_doing_ajax' ) && wp_doing_ajax();
		if ( function_exists( 'is_admin' ) && is_admin() && ! $ajax ) { return 'admin'; }
		return 'frontend';
	}

	/**
	 * Runs the schema upgrade when needed; returns true if an upgrade ran in this request.
	 *
	 * - frontend: only the cheap fingerprint is compared. On a mismatch a single cron event is
	 *   scheduled and the request continues with the current schema (dbDelta only adds tables,
	 *   columns and indexes, so old code paths keep working). Only when that event is overdue
	 *   (WP-Cron not running) does one frontend request upgrade, behind the lock.
	 * - admin, cron, cli: the full schema version is compared and the upgrade runs behind a
	 *   database lock, so concurrent requests after a plugin update never run dbDelta in parallel;
	 *   requests that do not get the lock simply skip.
	 *
	 * @param string|null $context Overrides context() (tests, WP-CLI commands).
	 */
	public static function maybe_upgrade( $context = null ) {
		$context = $context ?? self::context();
		// A failed upgrade is shown by Admin\Menu::schema_error_notice() (with retry button); render_schema_notice() stays for add-ons.
		// Downgrade: never install over a newer schema (also no cron event from frontend requests).
		if ( self::schema_is_newer() ) {
			if ( 'admin' === $context && function_exists( 'add_action' ) ) {
				add_action( 'admin_notices', array( __CLASS__, 'render_newer_notice' ) );
				add_action( 'network_admin_notices', array( __CLASS__, 'render_newer_notice' ) );
			}
			return false;
		}
		// Role names are translated: rename only once translations may load (init), never earlier.
		if ( did_action( 'init' ) ) {
			self::rename_legacy_roles();
		} elseif ( function_exists( 'add_action' ) ) {
			add_action( 'init', array( __CLASS__, 'rename_legacy_roles' ), 1 );
		}
		$fingerprint_ok = get_option( 'wpcap_schema_fingerprint' ) === self::fingerprint();
		// A failing upgrade waits for its next attempt (A466); WP-CLI always tries.
		$retry_at = 'cli' === $context ? 0 : self::retry_at();
		if ( 'frontend' === $context ) {
			if ( $fingerprint_ok ) { return false; }
			$next = function_exists( 'wp_next_scheduled' ) ? wp_next_scheduled( self::UPGRADE_HOOK ) : false;
			if ( ! $next && function_exists( 'wp_schedule_single_event' ) ) {
				wp_schedule_single_event( max( time(), $retry_at ), self::UPGRADE_HOOK );
				return false;
			}
			if ( $retry_at || ( $next && (int) $next > time() - self::OVERDUE_AFTER ) ) { return false; }
		}
		if ( self::version() === get_option( 'wpcap_db_version' ) ) {
			// Schema is current; only the fingerprint was missing or outdated (e.g. installs before 1.2).
			if ( ! $fingerprint_ok ) { update_option( 'wpcap_schema_fingerprint', self::fingerprint() ); }
			return false;
		}
		if ( $retry_at ) { return false; }
		$ran = false;
		self::run_locked(
			static function () use ( &$ran ) {
				// Another request may have finished the upgrade while we waited for the lock.
				if ( self::version() === self::stored_db_version() ) { return; }
				// Only a completed install counts: a failed one schedules no events (A466).
				$ran = false !== self::install();
			}
		);
		return $ran;
	}

	/** wpcap_db_version straight from the database when possible (the options cache may be stale). */
	private static function stored_db_version() {
		global $wpdb;
		if ( ! empty( $wpdb->options ) ) {
			$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name=%s', $wpdb->options, 'wpcap_db_version' ) );
			return null === $value ? false : (string) $value;
		}
		return get_option( 'wpcap_db_version' );
	}

	/** Name of the per-site upgrade lock (MySQL lock names are limited to 64 characters). */
	public static function lock_name() {
		return 'wpcap_upgrade_' . self::lock_scope();
	}

	/**
	 * Install-specific part of MySQL GET_LOCK names (16 hex characters). GET_LOCK names are
	 * server-wide: the scope covers table prefix and database, so installs sharing one server (same
	 * prefix in different databases) never block each other. Also used by Sales\BuyerLimits.
	 */
	public static function lock_scope() {
		global $wpdb;
		$database = defined( 'DB_NAME' ) ? (string) DB_NAME : (string) ( $wpdb->dbname ?? '' );
		if ( '' === $database ) {
			static $selected = null;
			$selected = $selected ?? (string) $wpdb->get_var( 'SELECT DATABASE()' );
			$database = $selected;
		}
		return substr( md5( (string) $wpdb->prefix . '|' . $database ), 0, 16 );
	}

	/**
	 * Runs $callback while holding the upgrade lock (MySQL GET_LOCK, released automatically if the
	 * process dies). By default it does not wait: returns false without running $callback when another
	 * request holds the lock; $wait seconds lets it wait that long first (activation, A512).
	 */
	public static function run_locked( callable $callback, $wait = 0 ) {
		global $wpdb;
		$name = self::lock_name();
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,%d)', $name, max( 0, (int) $wait ) ) ) ) { return false; }
		try {
			call_user_func( $callback );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
		return true;
	}

	/**
	 * Former default role names that were renamed (slug => old names). The organizer role was called
	 * "Pass Captain Partner" until it was renamed to avoid confusion with affiliate partners.
	 */
	const LEGACY_ROLE_NAMES = array( 'wpcap_partner' => array( 'Pass Captain Partner' ) );

	/**
	 * A362: role names as stored in the database – the untranslated source names, like WordPress core
	 * stores "Editor". They are translated on display (role_label(), the "User role" gettext context of
	 * translate_user_role()), so the role list follows the language of the viewing user instead of the
	 * language active at activation.
	 */
	const ROLE_NAMES = array(
		'wpcap_ticket_admin'     => 'Pass Captain Ticket-Administration',
		'wpcap_event_manager'    => 'Pass Captain Veranstaltungsleitung',
		'wpcap_box_office'       => 'Pass Captain Vor-Ort-Kasse',
		'wpcap_checkin_staff'    => 'Pass Captain Einlasspersonal',
		'wpcap_partner'          => 'Pass Captain Veranstalter',
		'wpcap_service_provider' => 'Pass Captain Leistungserbringer',
		'wpcap_finance'          => 'Pass Captain Finanzbuchhaltung',
		'wpcap_support'          => 'Pass Captain Support',
	);

	/**
	 * A362: translated role names (slug => name in the current language). Every name is a literal
	 * _x() call with the context "User role", so the extractor puts them into the catalogue.
	 *
	 * @return array<string, string>
	 */
	public static function role_labels() {
		return array(
			'wpcap_ticket_admin'     => _x( 'Pass Captain Ticket-Administration', 'User role', 'pass-captain' ),
			'wpcap_event_manager'    => _x( 'Pass Captain Veranstaltungsleitung', 'User role', 'pass-captain' ),
			'wpcap_box_office'       => _x( 'Pass Captain Vor-Ort-Kasse', 'User role', 'pass-captain' ),
			'wpcap_checkin_staff'    => _x( 'Pass Captain Einlasspersonal', 'User role', 'pass-captain' ),
			'wpcap_partner'          => _x( 'Pass Captain Veranstalter', 'User role', 'pass-captain' ),
			'wpcap_service_provider' => _x( 'Pass Captain Leistungserbringer', 'User role', 'pass-captain' ),
			'wpcap_finance'          => _x( 'Pass Captain Finanzbuchhaltung', 'User role', 'pass-captain' ),
			'wpcap_support'          => _x( 'Pass Captain Support', 'User role', 'pass-captain' ),
		);
	}

	/** A362: translated display name of a plugin role ('' for an unknown slug). */
	public static function role_label( $slug ) {
		return self::role_labels()[ (string) $slug ] ?? '';
	}

	/**
	 * A362: gettext_with_context filter. WordPress shows role names through translate_user_role()
	 * (text domain "default", context "User role"); the plugin's stored source names are answered
	 * from the plugin catalogue. Names an operator changed are not ours and stay as they are.
	 *
	 * @param string $translation Translation so far.
	 * @param string $text        Source text.
	 * @param string $context     Gettext context.
	 * @param string $domain      Text domain.
	 */
	public static function translate_role_name( $translation, $text, $context, $domain ) {
		if ( 'User role' !== $context || 'default' !== $domain ) { return $translation; }
		$slug = array_search( (string) $text, self::ROLE_NAMES, true );
		return false === $slug ? $translation : self::role_label( $slug );
	}

	/**
	 * Stores the neutral source name for roles that still carry a former default name or a name that
	 * an earlier version translated at activation (A362: the name in the current language, or the
	 * current translation of a former name). A name the operator changed is kept. Cheap: reads the
	 * already loaded roles and writes only when a rename is due.
	 *
	 * @param \WP_Roles|object|null $wp_roles Roles registry (default wp_roles()).
	 */
	public static function rename_legacy_roles( $wp_roles = null ) {
		if ( null === $wp_roles ) {
			if ( ! function_exists( 'wp_roles' ) ) { return; }
			$wp_roles = wp_roles();
		}
		$labels  = self::role_labels();
		$changed = false;
		foreach ( self::ROLE_NAMES as $slug => $neutral ) {
			$current = $wp_roles->roles[ $slug ]['name'] ?? null;
			if ( null === $current || $neutral === $current ) { continue; }
			$known = array_merge( self::LEGACY_ROLE_NAMES[ $slug ] ?? array(), array( $labels[ $slug ] ) );
			if ( in_array( $current, $known, true ) ) {
				$wp_roles->roles[ $slug ]['name'] = $neutral;
				$wp_roles->role_names[ $slug ]    = $neutral;
				$changed = true;
			}
		}
		if ( $changed && ! empty( $wp_roles->use_db ) ) {
			update_option( $wp_roles->role_key, $wp_roles->roles );
		}
	}

	/**
	 * Plugin capabilities of the role packages (administrators hold every one of them). The full list,
	 * including module capabilities, is Capabilities::all() (A372).
	 */
	const CAPABILITIES = Capabilities::CORE;

	/**
	 * Role packages from the concept: slug => [stored source name (A362: translated on display, see
	 * role_label()), capabilities]. Matrix in docs/SECURITY.md ("Rollen und Capabilities").
	 *
	 * @return array<string, array{0: string, 1: string[]}>
	 */
	public static function role_definitions() {
		return array(
			'wpcap_ticket_admin'      => array( self::ROLE_NAMES['wpcap_ticket_admin'], self::CAPABILITIES ),
			'wpcap_event_manager'     => array( self::ROLE_NAMES['wpcap_event_manager'], array( 'wpcap_manage_events', 'wpcap_manage_orders', 'wpcap_view_reports', 'wpcap_refund', 'wpcap_checkin' ) ),
			'wpcap_box_office'        => array( self::ROLE_NAMES['wpcap_box_office'], array( 'wpcap_manage_orders', 'wpcap_checkin' ) ),
			'wpcap_checkin_staff'     => array( self::ROLE_NAMES['wpcap_checkin_staff'], array( 'wpcap_checkin' ) ),
			'wpcap_partner'           => array( self::ROLE_NAMES['wpcap_partner'], array( 'wpcap_checkin', 'wpcap_view_reports' ) ),
			'wpcap_service_provider'  => array( self::ROLE_NAMES['wpcap_service_provider'], array( 'wpcap_service_bookings' ) ),
			'wpcap_finance'           => array( self::ROLE_NAMES['wpcap_finance'], array( 'wpcap_view_finance', 'wpcap_view_reports', 'wpcap_approve_refunds' ) ),
			'wpcap_support'           => array( self::ROLE_NAMES['wpcap_support'], array( 'wpcap_manage_orders' ) ),
		);
	}

	/**
	 * Registers the roles. Operational roles are additionally limited per event through
	 * wpcap_event_staff assignments (Access::can_access_event); only finance gets global read access.
	 * Service providers (wpcap_service_bookings) reach nothing but the services their provider record
	 * (ServiceProviders, linked through user_id) is assigned to (Access::can_access_resource()). Optional
	 * per-user location/partner scopes (Access::scope()) and the PII matrix per capability profile
	 * (Pii, docs/SECURITY.md) apply on top (A119).
	 */
	public static function register_roles() {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( array_unique( array_merge( self::CAPABILITIES, array( 'wpcap_approve_refunds', 'wpcap_view_finance' ) ) ) as $cap ) { $admin->add_cap( $cap, true ); }
			$admin->add_cap( 'wpcap_view_audit', true ); // Audit-Log viewer (W9); not part of any operational role.
		}
		$roles = self::role_definitions();
		foreach ( $roles as $slug => $definition ) {
			$caps = array( 'read' => true );
			foreach ( $definition[1] as $cap ) { $caps[ $cap ] = true; }
			$role = get_role( $slug );
			if ( ! $role ) {
				add_role( $slug, $definition[0], $caps );
				continue;
			}
			// Upgrade existing roles additively; never remove capabilities an operator granted.
			foreach ( $caps as $cap => $grant ) { $role->add_cap( $cap, $grant ); }
		}
	}
}

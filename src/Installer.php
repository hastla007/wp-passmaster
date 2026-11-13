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
	const DB_VERSION = '3.8.0';

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
				sold_qty int(11) NOT NULL DEFAULT 0,
				held_qty int(11) NOT NULL DEFAULT 0,
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
				tax_rate_bp int(10) unsigned NOT NULL DEFAULT 0,
				sales_start datetime NULL,
				sales_end datetime NULL,
				status varchar(20) NOT NULL DEFAULT 'active',
				sold_qty int(11) NOT NULL DEFAULT 0,
				held_qty int(11) NOT NULL DEFAULT 0,
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
				tax_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				refunded_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				payment_provider varchar(32) NOT NULL DEFAULT 'manual',
				provider_reference varchar(191) NOT NULL DEFAULT '',
				terms_version varchar(64) NOT NULL DEFAULT '',
				locale varchar(20) NOT NULL DEFAULT '',
				voucher_snapshot longtext NULL,
				attendee_data longtext NULL,
				billing longtext NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (order_id),
				UNIQUE KEY public_id (public_id),
				UNIQUE KEY idempotency_key (idempotency_key),
				KEY customer_email (customer_email),
				KEY access_token_hash (access_token_hash),
				KEY access_expires_at (access_expires_at),
				KEY status (status),
				KEY paid_created (payment_status,created_at,status),
				KEY created (created_at,order_id)
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
				counted_qty smallint(5) unsigned NOT NULL DEFAULT 0,
				orig_quantity smallint(5) unsigned DEFAULT NULL,
				orig_line_total_minor bigint(20) unsigned DEFAULT NULL,
				orig_tax_minor bigint(20) unsigned DEFAULT NULL,
				PRIMARY KEY  (item_id),
				KEY order_id (order_id),
				KEY ticket_type_id (ticket_type_id),
				KEY capacity_sum (ticket_type_id,order_id,quantity,refunded_quantity)
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
				KEY expiry (status,expires_at)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE $tickets (
				ticket_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				public_id char(32) NOT NULL,
				order_id bigint(20) unsigned NOT NULL,
				ticket_type_id bigint(20) unsigned NOT NULL,
				token_hash char(64) NOT NULL DEFAULT '',
				attendee_email varchar(190) NOT NULL DEFAULT '',
				transfer_count tinyint(3) unsigned NOT NULL DEFAULT 0,
				refunded tinyint(1) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'valid',
				issued_at datetime NOT NULL,
				PRIMARY KEY  (ticket_id),
				UNIQUE KEY public_id (public_id),
				UNIQUE KEY token_hash (token_hash),
				KEY order_id (order_id),
				KEY ticket_type_id (ticket_type_id),
				KEY status (status),
				KEY order_type_status (order_id,ticket_type_id,status),
				KEY attendee_email (attendee_email)
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
				quantity smallint(5) unsigned NOT NULL DEFAULT 1,
				priority int(11) NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'waiting',
				offer_token_hash char(64) NOT NULL DEFAULT '',
				offer_expires_at datetime NULL,
				offered_at datetime NULL,
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (waitlist_id),
				UNIQUE KEY event_type_email (event_id,ticket_type_id,email_hash),
				KEY queue_order (event_id,ticket_type_id,status,position),
				KEY type_queue (ticket_type_id,status,priority,position),
				KEY offer_expiry (status,offer_expires_at),
				KEY order_id (order_id)
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
				KEY order_id (order_id)
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
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (refund_id),
				UNIQUE KEY idempotency_key (idempotency_key),
				KEY order_status (order_id,status)
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
				issued_at datetime NOT NULL,
				PRIMARY KEY  (credit_note_id),
				UNIQUE KEY refund_id (refund_id),
				UNIQUE KEY credit_note_number (credit_note_number),
				KEY invoice_id (invoice_id)
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
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (job_id),
				UNIQUE KEY idempotency_key (idempotency_key),
				KEY due_jobs (status,run_after),
				KEY job_type (job_type,status)
			) ENGINE=InnoDB $charset;",
		);

		foreach ( array_merge( $queries, Modules::schema( $wpdb->prefix, $charset ) ) as $query ) {
			dbDelta( $query );
		}

		// Capacity counters (schema 3.7.0): fresh installs use them right away, upgrades rebuild them
		// from the source tables and verify them before capacity decisions rely on them.
		CapacityCounters::after_install();

		// Role names are translated; before init that would load the text domain too early.
		if ( did_action( 'init' ) || ! function_exists( 'add_action' ) ) {
			self::register_roles();
		} else {
			add_action( 'init', array( __CLASS__, 'register_roles' ), 1 );
		}
		update_option( 'wpcap_db_version', self::version() );
		update_option( 'wpcap_version', WPCAP_VERSION );
		update_option( 'wpcap_schema_fingerprint', self::fingerprint() );
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
		// Role names are translated: rename only once translations may load (init), never earlier.
		if ( did_action( 'init' ) ) {
			self::rename_legacy_roles();
		} elseif ( function_exists( 'add_action' ) ) {
			add_action( 'init', array( __CLASS__, 'rename_legacy_roles' ), 1 );
		}
		$fingerprint_ok = get_option( 'wpcap_schema_fingerprint' ) === self::fingerprint();
		if ( 'frontend' === $context ) {
			if ( $fingerprint_ok ) { return false; }
			$next = function_exists( 'wp_next_scheduled' ) ? wp_next_scheduled( self::UPGRADE_HOOK ) : false;
			if ( ! $next && function_exists( 'wp_schedule_single_event' ) ) {
				wp_schedule_single_event( time(), self::UPGRADE_HOOK );
				return false;
			}
			if ( $next && (int) $next > time() - self::OVERDUE_AFTER ) { return false; }
		}
		if ( self::version() === get_option( 'wpcap_db_version' ) ) {
			// Schema is current; only the fingerprint was missing or outdated (e.g. installs before 1.2).
			if ( ! $fingerprint_ok ) { update_option( 'wpcap_schema_fingerprint', self::fingerprint() ); }
			return false;
		}
		$ran = false;
		self::run_locked(
			static function () use ( &$ran ) {
				// Another request may have finished the upgrade while we waited for the lock.
				if ( self::version() === self::stored_db_version() ) { return; }
				self::install();
				$ran = true;
			}
		);
		return $ran;
	}

	/** wpcap_db_version straight from the database when possible (the options cache may be stale). */
	private static function stored_db_version() {
		global $wpdb;
		if ( ! empty( $wpdb->options ) ) {
			$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", 'wpcap_db_version' ) );
			return null === $value ? false : (string) $value;
		}
		return get_option( 'wpcap_db_version' );
	}

	/** Name of the per-site upgrade lock (MySQL lock names are limited to 64 characters). */
	public static function lock_name() {
		global $wpdb;
		return 'wpcap_upgrade_' . substr( md5( (string) $wpdb->prefix . '|' . ( defined( 'DB_NAME' ) ? DB_NAME : '' ) ), 0, 16 );
	}

	/**
	 * Runs $callback while holding the upgrade lock (MySQL GET_LOCK, released automatically if the
	 * process dies). Does not wait: returns false without running $callback when another request
	 * holds the lock.
	 */
	public static function run_locked( callable $callback ) {
		global $wpdb;
		$name = self::lock_name();
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $name ) ) ) { return false; }
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
	 * Renames roles that still carry a former default name. A name the operator changed is kept.
	 * Cheap: reads the already loaded roles and writes only when a rename is due.
	 */
	public static function rename_legacy_roles() {
		if ( ! function_exists( 'wp_roles' ) ) { return; }
		$wp_roles = wp_roles();
		$targets  = array( 'wpcap_partner' => __( 'Pass Captain Veranstalter', 'wp-pass-captain' ) );
		$changed  = false;
		foreach ( self::LEGACY_ROLE_NAMES as $slug => $old_names ) {
			$current = $wp_roles->roles[ $slug ]['name'] ?? null;
			if ( null !== $current && in_array( $current, $old_names, true ) && isset( $targets[ $slug ] ) ) {
				$wp_roles->roles[ $slug ]['name'] = $targets[ $slug ];
				$wp_roles->role_names[ $slug ]    = $targets[ $slug ];
				$changed = true;
			}
		}
		if ( $changed && $wp_roles->use_db ) {
			update_option( $wp_roles->role_key, $wp_roles->roles );
		}
	}

	/**
	 * Role packages from the concept. Operational roles are additionally limited per event through
	 * wpcap_event_staff assignments (Access::can_access_event); only finance gets global read access.
	 */
	public static function register_roles() {
		$all_caps = array( 'wpcap_manage_events', 'wpcap_manage_orders', 'wpcap_checkin', 'wpcap_view_reports', 'wpcap_refund', 'wpcap_approve_refunds', 'wpcap_view_finance' );
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( $all_caps as $cap ) { $admin->add_cap( $cap, true ); }
		}
		$roles = array(
			'wpcap_ticket_admin'   => array( __( 'Pass Captain Ticket-Administration', 'wp-pass-captain' ), $all_caps ),
			'wpcap_event_manager'  => array( __( 'Pass Captain Veranstaltungsleitung', 'wp-pass-captain' ), array( 'wpcap_manage_events', 'wpcap_manage_orders', 'wpcap_view_reports', 'wpcap_refund', 'wpcap_checkin' ) ),
			'wpcap_box_office'     => array( __( 'Pass Captain Vor-Ort-Kasse', 'wp-pass-captain' ), array( 'wpcap_manage_orders', 'wpcap_checkin' ) ),
			'wpcap_checkin_staff'  => array( __( 'Pass Captain Einlasspersonal', 'wp-pass-captain' ), array( 'wpcap_checkin' ) ),
			'wpcap_partner'        => array( __( 'Pass Captain Veranstalter', 'wp-pass-captain' ), array( 'wpcap_checkin', 'wpcap_view_reports' ) ),
			'wpcap_finance'        => array( __( 'Pass Captain Finanzbuchhaltung', 'wp-pass-captain' ), array( 'wpcap_view_finance', 'wpcap_view_reports', 'wpcap_approve_refunds' ) ),
			'wpcap_support'        => array( __( 'Pass Captain Support', 'wp-pass-captain' ), array( 'wpcap_manage_orders' ) ),
		);
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

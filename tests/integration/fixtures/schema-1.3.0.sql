-- Pass Captain 1.3.0 schema (DB_VERSION 3.4.0), extracted from the installer and module schemas of the 1.3.0 commit (0ee8418). Placeholders {prefix} and {charset}; statements are separated by '-- ;;'.
-- ;;
CREATE TABLE {prefix}wpcap_events (
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
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_occurrences (
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
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_ticket_types (
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
				PRIMARY KEY  (ticket_type_id),
				KEY occurrence_id (occurrence_id),
				KEY status (status)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_orders (
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
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_order_items (
				item_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				order_id bigint(20) unsigned NOT NULL,
				ticket_type_id bigint(20) unsigned NOT NULL,
				quantity smallint(5) unsigned NOT NULL,
				unit_price_minor bigint(20) unsigned NOT NULL,
				line_total_minor bigint(20) unsigned NOT NULL,
				tax_rate_bp int(10) unsigned NOT NULL DEFAULT 0,
				tax_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				refunded_quantity smallint(5) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (item_id),
				KEY order_id (order_id),
				KEY ticket_type_id (ticket_type_id),
				KEY capacity_sum (ticket_type_id,order_id,quantity,refunded_quantity)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_reservations (
				reservation_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				order_id bigint(20) unsigned NOT NULL,
				ticket_type_id bigint(20) unsigned NOT NULL,
				quantity smallint(5) unsigned NOT NULL,
				expires_at datetime NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'active',
				PRIMARY KEY  (reservation_id),
				KEY order_id (order_id),
				KEY inventory (ticket_type_id,status,expires_at)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_tickets (
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
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_checkins (
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
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_event_staff (
				event_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				capability varchar(64) NOT NULL,
				PRIMARY KEY  (event_id,user_id,capability),
				KEY user_id (user_id)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_audit_logs (
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
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_seat_maps (
				map_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL,
				version int(10) unsigned NOT NULL,
				name varchar(190) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'draft',
				created_at datetime NOT NULL,
				PRIMARY KEY  (map_id),
				UNIQUE KEY event_version (event_id,version),
				KEY event_status (event_id,status)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_seats (
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
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_seat_holds (
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
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_resources (
				resource_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL,
				name varchar(190) NOT NULL,
				capacity smallint(5) unsigned NOT NULL DEFAULT 1,
				buffer_before smallint(5) unsigned NOT NULL DEFAULT 0,
				buffer_after smallint(5) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'active',
				PRIMARY KEY  (resource_id),
				KEY event_status (event_id,status)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_service_slots (
				slot_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				resource_id bigint(20) unsigned NOT NULL,
				starts_at datetime NOT NULL,
				ends_at datetime NOT NULL,
				capacity smallint(5) unsigned NOT NULL DEFAULT 1,
				status varchar(20) NOT NULL DEFAULT 'open',
				PRIMARY KEY  (slot_id),
				KEY resource_time (resource_id,starts_at,ends_at),
				KEY status_time (status,starts_at)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_service_bookings (
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
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_occurrence_days (
				occurrence_day_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				occurrence_id bigint(20) unsigned NOT NULL,
				service_date date NOT NULL,
				admission_policy varchar(24) NOT NULL DEFAULT 'single_entry',
				max_entries smallint(5) unsigned NOT NULL DEFAULT 1,
				PRIMARY KEY  (occurrence_day_id),
				UNIQUE KEY occurrence_date (occurrence_id,service_date),
				KEY admission_policy (admission_policy)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_scanner_devices (
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
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_wallet_passes (
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
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_invoices (
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
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_invoice_lines (
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
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_invoice_sequences (
				year smallint(5) unsigned NOT NULL,
				next_number bigint(20) unsigned NOT NULL DEFAULT 1,
				PRIMARY KEY  (year)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_participant_fields (
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
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_participant_answers (
				answer_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				ticket_id bigint(20) unsigned NOT NULL,
				field_id bigint(20) unsigned NOT NULL,
				answer longtext NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (answer_id),
				UNIQUE KEY ticket_field (ticket_id,field_id),
				KEY field_id (field_id)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_message_queue (
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
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_ticket_assignments (
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
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_member_passes (
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
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_pass_rules (
				rule_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				pass_id bigint(20) unsigned NOT NULL,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				ticket_type_id bigint(20) unsigned NOT NULL DEFAULT 0,
				uses_total smallint(5) unsigned NOT NULL DEFAULT 0,
				uses_per_day smallint(5) unsigned NOT NULL DEFAULT 1,
				PRIMARY KEY  (rule_id),
				KEY pass_scope (pass_id,event_id,ticket_type_id)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_pass_uses (
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
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_campaigns (
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
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_campaign_uses (
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
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_waitlist (
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
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_api_keys (
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
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_event_translations (
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
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_tax_profiles (
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
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_connector_settings (
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
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_payment_events (
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
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_refunds (
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
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_credit_notes (
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
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_document_sequences (
				series varchar(16) NOT NULL,
				year smallint(5) unsigned NOT NULL,
				next_number bigint(20) unsigned NOT NULL DEFAULT 1,
				PRIMARY KEY  (series,year)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_device_pairings (
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
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_jobs (
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
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_admission_policies (
				ticket_type_id bigint(20) unsigned NOT NULL,
				policy varchar(24) NOT NULL DEFAULT 'single',
				max_entries smallint(5) unsigned NOT NULL DEFAULT 0,
				valid_dates longtext NULL,
				opens_before_minutes int(11) NULL,
				closes_after_minutes int(11) NULL,
				day_from char(5) NULL,
				day_until char(5) NULL,
				updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (ticket_type_id)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_admission_state (
				ticket_id bigint(20) unsigned NOT NULL,
				occurrence_id bigint(20) unsigned NOT NULL,
				event_id bigint(20) unsigned NOT NULL,
				inside tinyint(1) unsigned NOT NULL DEFAULT 0,
				area_id bigint(20) unsigned NOT NULL DEFAULT 0,
				entries int(10) unsigned NOT NULL DEFAULT 0,
				last_in_at datetime NULL,
				last_out_at datetime NULL,
				last_checkin_id bigint(20) unsigned NOT NULL DEFAULT 0,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (ticket_id),
				KEY occurrence_inside (occurrence_id,inside,area_id)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_admission_entries (
				entry_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				ticket_id bigint(20) unsigned NOT NULL,
				occurrence_id bigint(20) unsigned NOT NULL,
				area_id bigint(20) unsigned NOT NULL DEFAULT 0,
				service_date date NOT NULL,
				direction varchar(3) NOT NULL DEFAULT 'in',
				checkin_id bigint(20) unsigned NOT NULL DEFAULT 0,
				device_id bigint(20) unsigned NOT NULL DEFAULT 0,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				undone tinyint(1) unsigned NOT NULL DEFAULT 0,
				entered_at datetime NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (entry_id),
				KEY ticket_day (ticket_id,service_date,direction),
				KEY occurrence_direction (occurrence_id,direction,area_id)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_admission_areas (
				area_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL,
				name varchar(190) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL,
				PRIMARY KEY  (area_id),
				KEY event_status (event_id,status)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_admission_type_areas (
				ticket_type_id bigint(20) unsigned NOT NULL,
				area_id bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (ticket_type_id,area_id),
				KEY area_id (area_id)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_admission_area_staff (
				area_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (area_id,user_id),
				KEY user_id (user_id)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_admission_devices (
				device_id bigint(20) unsigned NOT NULL,
				area_id bigint(20) unsigned NOT NULL DEFAULT 0,
				last_sync_at datetime NULL,
				last_sync_scans int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (device_id)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_admission_pairings (
				pairing_id bigint(20) unsigned NOT NULL,
				area_id bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (pairing_id)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_admission_conflicts (
				conflict_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				dedupe_key char(64) NOT NULL,
				ticket_id bigint(20) unsigned NOT NULL,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				occurrence_id bigint(20) unsigned NOT NULL DEFAULT 0,
				area_id bigint(20) unsigned NOT NULL DEFAULT 0,
				result varchar(24) NOT NULL,
				source varchar(16) NOT NULL DEFAULT 'offline',
				device_id bigint(20) unsigned NOT NULL DEFAULT 0,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				scanned_at datetime NULL,
				checkin_id bigint(20) unsigned NOT NULL DEFAULT 0,
				first_checkin_id bigint(20) unsigned NOT NULL DEFAULT 0,
				first_device_id bigint(20) unsigned NOT NULL DEFAULT 0,
				first_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				first_at datetime NULL,
				status varchar(20) NOT NULL DEFAULT 'open',
				resolution varchar(32) NOT NULL DEFAULT '',
				resolution_note text NULL,
				resolved_by bigint(20) unsigned NOT NULL DEFAULT 0,
				resolved_at datetime NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (conflict_id),
				UNIQUE KEY dedupe_key (dedupe_key),
				KEY status_event (status,event_id),
				KEY device_status (device_id,status),
				KEY ticket_id (ticket_id)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_boxoffice_sessions (
				session_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				open_key bigint(20) unsigned NULL,
				currency char(3) NOT NULL DEFAULT 'EUR',
				opening_float_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				closing_count_minor bigint(20) unsigned NULL,
				expected_cash_minor bigint(20) NULL,
				difference_minor bigint(20) NULL,
				totals longtext NULL,
				note varchar(255) NOT NULL DEFAULT '',
				closing_note varchar(255) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'open',
				opened_at datetime NOT NULL,
				closed_at datetime NULL,
				closed_by bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (session_id),
				UNIQUE KEY open_key (open_key),
				KEY user_status (user_id,status)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_boxoffice_sales (
				sale_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				session_id bigint(20) unsigned NOT NULL,
				order_id bigint(20) unsigned NULL,
				form_key_hash char(64) NOT NULL,
				receipt_number varchar(40) NULL,
				payment_method varchar(20) NOT NULL,
				currency char(3) NOT NULL,
				total_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				tax_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				tendered_minor bigint(20) unsigned NULL,
				occurrence_id bigint(20) unsigned NOT NULL DEFAULT 0,
				cashier_id bigint(20) unsigned NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'completed',
				storno_refund_id bigint(20) unsigned NOT NULL DEFAULT 0,
				storno_receipt_number varchar(40) NULL,
				storno_attempt smallint(5) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				voided_at datetime NULL,
				voided_by bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (sale_id),
				UNIQUE KEY order_id (order_id),
				UNIQUE KEY form_key_hash (form_key_hash),
				UNIQUE KEY receipt_number (receipt_number),
				UNIQUE KEY storno_receipt_number (storno_receipt_number),
				KEY session_status (session_id,status),
				KEY created_at (created_at)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_mollie_choices (
				order_id bigint(20) unsigned NOT NULL,
				method varchar(32) NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (order_id)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_mollie_payments (
				payment_id varchar(64) NOT NULL,
				order_id bigint(20) unsigned NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (payment_id),
				KEY order_id (order_id)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_offline_events (
				event_id bigint(20) unsigned NOT NULL,
				enabled tinyint(1) unsigned NOT NULL DEFAULT 0,
				max_offline_hours smallint(5) unsigned NOT NULL DEFAULT 12,
				duplicate_policy varchar(20) NOT NULL DEFAULT 'review',
				include_attendee tinyint(1) unsigned NOT NULL DEFAULT 0,
				updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (event_id)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_offline_assignments (
				device_id bigint(20) unsigned NOT NULL,
				event_id bigint(20) unsigned NOT NULL,
				occurrence_id bigint(20) unsigned NOT NULL DEFAULT 0,
				assigned_by bigint(20) unsigned NOT NULL DEFAULT 0,
				last_package_at datetime NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (device_id,event_id,occurrence_id),
				KEY event_id (event_id)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_offline_state (
				occurrence_id bigint(20) unsigned NOT NULL,
				epoch char(16) NOT NULL,
				version int(10) unsigned NOT NULL DEFAULT 0,
				refreshed_at datetime NULL,
				PRIMARY KEY  (occurrence_id)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_offline_entries (
				occurrence_id bigint(20) unsigned NOT NULL,
				token_hash char(64) NOT NULL,
				ticket_id bigint(20) unsigned NOT NULL,
				ticket_type_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL,
				attendee_name varchar(190) NOT NULL DEFAULT '',
				fingerprint char(32) NOT NULL,
				version int(10) unsigned NOT NULL,
				PRIMARY KEY  (occurrence_id,token_hash),
				KEY occurrence_version (occurrence_id,version)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_partners (
				partner_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(190) NOT NULL,
				contact_name varchar(190) NOT NULL DEFAULT '',
				contact_email varchar(190) NOT NULL DEFAULT '',
				contact_phone varchar(64) NOT NULL DEFAULT '',
				billing_address longtext NOT NULL,
				vat_id varchar(64) NOT NULL DEFAULT '',
				payout_details longtext NOT NULL,
				currency char(3) NOT NULL DEFAULT 'EUR',
				commission_bp int(10) unsigned NOT NULL DEFAULT 0,
				fee_per_ticket_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				fee_tax_rate_bp int(10) unsigned NOT NULL DEFAULT 0,
				refund_policy varchar(20) NOT NULL DEFAULT 'reverse_all',
				settlement_start date NULL,
				status varchar(20) NOT NULL DEFAULT 'active',
				notes longtext NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (partner_id),
				KEY status (status)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_partner_events (
				event_id bigint(20) unsigned NOT NULL,
				partner_id bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (event_id),
				KEY partner_id (partner_id)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_partner_users (
				partner_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (partner_id,user_id),
				KEY user_id (user_id)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_partner_keys (
				key_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				partner_id bigint(20) unsigned NOT NULL,
				label varchar(190) NOT NULL DEFAULT '',
				public_prefix char(12) NOT NULL,
				secret_hash char(64) NOT NULL,
				scopes varchar(255) NOT NULL,
				event_ids longtext NULL,
				ip_allowlist longtext NULL,
				rate_limit int(10) unsigned NOT NULL DEFAULT 0,
				expires_at datetime NULL,
				last_used_at datetime NULL,
				revoked_at datetime NULL,
				revoked_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (key_id),
				UNIQUE KEY public_prefix (public_prefix),
				KEY partner_id (partner_id)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_partner_settlements (
				settlement_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				partner_id bigint(20) unsigned NOT NULL,
				period char(7) NOT NULL,
				period_start datetime NOT NULL,
				period_end datetime NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'draft',
				document_number varchar(40) NULL,
				currency char(3) NOT NULL DEFAULT 'EUR',
				tickets_sold int(11) NOT NULL DEFAULT 0,
				tickets_refunded int(11) NOT NULL DEFAULT 0,
				gross_minor bigint(20) NOT NULL DEFAULT 0,
				tax_minor bigint(20) NOT NULL DEFAULT 0,
				net_minor bigint(20) NOT NULL DEFAULT 0,
				refunded_minor bigint(20) NOT NULL DEFAULT 0,
				corrections_minor bigint(20) NOT NULL DEFAULT 0,
				commission_minor bigint(20) NOT NULL DEFAULT 0,
				fee_minor bigint(20) NOT NULL DEFAULT 0,
				platform_fee_net_minor bigint(20) NOT NULL DEFAULT 0,
				platform_fee_tax_minor bigint(20) NOT NULL DEFAULT 0,
				platform_fee_gross_minor bigint(20) NOT NULL DEFAULT 0,
				payout_minor bigint(20) NOT NULL DEFAULT 0,
				totals longtext NOT NULL,
				partner_snapshot longtext NULL,
				payment_reference varchar(190) NOT NULL DEFAULT '',
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				finalized_by bigint(20) unsigned NOT NULL DEFAULT 0,
				finalized_at datetime NULL,
				paid_at datetime NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (settlement_id),
				UNIQUE KEY partner_period (partner_id,period),
				UNIQUE KEY document_number (document_number),
				KEY status (status)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_partner_settlement_lines (
				line_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				settlement_id bigint(20) unsigned NOT NULL,
				partner_id bigint(20) unsigned NOT NULL,
				kind varchar(16) NOT NULL,
				item_key varchar(64) NOT NULL,
				source_key varchar(64) NULL,
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				refund_id bigint(20) unsigned NOT NULL DEFAULT 0,
				item_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event_name varchar(190) NOT NULL DEFAULT '',
				origin_period char(7) NOT NULL DEFAULT '',
				occurred_at datetime NOT NULL,
				tax_rate_bp int(10) unsigned NOT NULL DEFAULT 0,
				tickets int(11) NOT NULL DEFAULT 0,
				gross_minor bigint(20) NOT NULL DEFAULT 0,
				tax_minor bigint(20) NOT NULL DEFAULT 0,
				net_minor bigint(20) NOT NULL DEFAULT 0,
				commission_minor bigint(20) NOT NULL DEFAULT 0,
				fee_minor bigint(20) NOT NULL DEFAULT 0,
				PRIMARY KEY  (line_id),
				UNIQUE KEY partner_source (partner_id,source_key),
				KEY settlement_id (settlement_id)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_print_templates (
				template_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(190) NOT NULL,
				kind varchar(20) NOT NULL,
				owner_event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				body text NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'active',
				updated_at datetime NOT NULL,
				PRIMARY KEY  (template_id),
				KEY kind_status (kind,status),
				KEY owner_event_id (owner_event_id)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_print_event_templates (
				event_id bigint(20) unsigned NOT NULL,
				kind varchar(20) NOT NULL,
				template_id bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (event_id,kind)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_seating_layouts (
				layout_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL,
				name varchar(190) NOT NULL,
				draft longtext NOT NULL,
				draft_revision int(10) unsigned NOT NULL DEFAULT 0,
				published_version int(10) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (layout_id),
				KEY event_id (event_id)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_seating_versions (
				version_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				layout_id bigint(20) unsigned NOT NULL,
				event_id bigint(20) unsigned NOT NULL,
				version int(10) unsigned NOT NULL,
				name varchar(190) NOT NULL,
				definition longtext NOT NULL,
				seat_count int(10) unsigned NOT NULL DEFAULT 0,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (version_id),
				UNIQUE KEY layout_version (layout_id,version),
				KEY event_id (event_id)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_seating_seats (
				seat_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				version_id bigint(20) unsigned NOT NULL,
				seat_key varchar(64) NOT NULL,
				kind varchar(16) NOT NULL DEFAULT 'seat',
				group_key varchar(64) NOT NULL DEFAULT '',
				row_label varchar(60) NOT NULL DEFAULT '',
				seat_label varchar(40) NOT NULL DEFAULT '',
				label varchar(120) NOT NULL DEFAULT '',
				zone varchar(64) NOT NULL DEFAULT '',
				category varchar(64) NOT NULL DEFAULT '',
				is_accessible tinyint(1) unsigned NOT NULL DEFAULT 0,
				pos_x decimal(9,2) NOT NULL DEFAULT 0,
				pos_y decimal(9,2) NOT NULL DEFAULT 0,
				sort_order int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (seat_id),
				UNIQUE KEY version_seat (version_id,seat_key),
				KEY version_zone (version_id,zone)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_seating_links (
				occurrence_id bigint(20) unsigned NOT NULL,
				layout_id bigint(20) unsigned NOT NULL,
				version_id bigint(20) unsigned NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (occurrence_id),
				KEY layout_id (layout_id),
				KEY version_id (version_id)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_seating_type_zones (
				occurrence_id bigint(20) unsigned NOT NULL,
				ticket_type_id bigint(20) unsigned NOT NULL,
				zone varchar(64) NOT NULL,
				PRIMARY KEY  (occurrence_id,ticket_type_id,zone),
				KEY ticket_type_id (ticket_type_id)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_seating_claims (
				claim_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				occurrence_id bigint(20) unsigned NOT NULL,
				seat_key varchar(64) NOT NULL,
				active_key varchar(64) NULL,
				seat_id bigint(20) unsigned NOT NULL,
				order_id bigint(20) unsigned NOT NULL,
				ticket_type_id bigint(20) unsigned NOT NULL,
				ticket_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'held',
				release_reason varchar(32) NOT NULL DEFAULT '',
				expires_at datetime NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (claim_id),
				UNIQUE KEY active_seat (occurrence_id,active_key),
				KEY order_id (order_id,status),
				KEY ticket_id (ticket_id),
				KEY occurrence_status (occurrence_id,status)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_seating_ticket_seats (
				assignment_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				ticket_id bigint(20) unsigned NOT NULL,
				order_id bigint(20) unsigned NOT NULL,
				occurrence_id bigint(20) unsigned NOT NULL,
				claim_id bigint(20) unsigned NOT NULL,
				seat_id bigint(20) unsigned NOT NULL,
				seat_key varchar(64) NOT NULL,
				label varchar(120) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'assigned',
				assigned_at datetime NOT NULL,
				released_at datetime NULL,
				PRIMARY KEY  (assignment_id),
				KEY ticket_id (ticket_id,status),
				KEY order_id (order_id),
				KEY occurrence_id (occurrence_id)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_series (
				series_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL,
				name varchar(190) NOT NULL DEFAULT '',
				template_occurrence_id bigint(20) unsigned NOT NULL DEFAULT 0,
				definition longtext NOT NULL,
				definition_hash char(64) NOT NULL,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (series_id),
				UNIQUE KEY event_definition (event_id,definition_hash),
				KEY event_id (event_id)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_series_occurrences (
				series_id bigint(20) unsigned NOT NULL,
				occurrence_id bigint(20) unsigned NOT NULL,
				starts_at datetime NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (series_id,occurrence_id),
				UNIQUE KEY occurrence_id (occurrence_id),
				KEY series_start (series_id,starts_at)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_series_price_rules (
				rule_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				series_id bigint(20) unsigned NOT NULL,
				label varchar(190) NOT NULL DEFAULT '',
				type_name varchar(190) NOT NULL DEFAULT '',
				kind varchar(20) NOT NULL,
				priority int(11) NOT NULL DEFAULT 0,
				until_at datetime NULL DEFAULT NULL,
				hours_before int(10) unsigned NOT NULL DEFAULT 0,
				sold_pct smallint(5) unsigned NOT NULL DEFAULT 0,
				weekdays varchar(20) NOT NULL DEFAULT '',
				time_from char(5) NOT NULL DEFAULT '',
				time_to char(5) NOT NULL DEFAULT '',
				price_mode varchar(10) NOT NULL DEFAULT 'fixed',
				amount bigint(20) NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (rule_id),
				KEY series_status (series_id,status)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_series_pools (
				pool_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				series_id bigint(20) unsigned NOT NULL,
				name varchar(190) NOT NULL DEFAULT '',
				type_name varchar(190) NOT NULL DEFAULT '',
				capacity int(10) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (pool_id),
				KEY series_status (series_id,status)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_sms_consents (
				consent_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				order_id bigint(20) unsigned NOT NULL,
				phone varchar(20) NOT NULL DEFAULT '',
				purpose varchar(32) NOT NULL,
				text_version char(16) NOT NULL,
				consented_at datetime NOT NULL,
				withdrawn_at datetime NULL,
				retired_at datetime NULL,
				PRIMARY KEY  (consent_id),
				UNIQUE KEY order_purpose (order_id,purpose),
				KEY phone (phone,consented_at)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_sms_messages (
				message_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				dedupe_key char(64) NOT NULL,
				consent_id bigint(20) unsigned NOT NULL,
				order_id bigint(20) unsigned NOT NULL,
				occurrence_id bigint(20) unsigned NOT NULL DEFAULT 0,
				purpose varchar(32) NOT NULL,
				body text NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'queued',
				provider varchar(32) NOT NULL DEFAULT '',
				provider_ref varchar(64) NOT NULL DEFAULT '',
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				send_at datetime NOT NULL,
				locked_at datetime NULL,
				last_error varchar(190) NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (message_id),
				UNIQUE KEY dedupe_key (dedupe_key),
				KEY due (status,send_at),
				KEY provider_ref (provider,provider_ref),
				KEY order_id (order_id)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_wallet_state (
				ticket_id bigint(20) unsigned NOT NULL,
				secret text NOT NULL,
				code_hash char(64) NOT NULL DEFAULT '',
				auth_token_hash char(64) NOT NULL DEFAULT '',
				generation int(10) unsigned NOT NULL DEFAULT 1,
				fingerprint char(64) NOT NULL DEFAULT '',
				update_tag bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (ticket_id),
				KEY update_tag (update_tag)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_wallet_registrations (
				registration_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				device_library_id varchar(128) NOT NULL,
				pass_type_id varchar(190) NOT NULL,
				serial_number varchar(190) NOT NULL,
				push_token varchar(200) NOT NULL,
				auth_token_hash char(64) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (registration_id),
				UNIQUE KEY device_serial (device_library_id,serial_number),
				KEY serial_number (serial_number),
				KEY push_token (push_token)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_webhook_subscriptions (
				subscription_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				url varchar(500) NOT NULL,
				description varchar(190) NOT NULL DEFAULT '',
				events varchar(500) NOT NULL,
				secret varchar(80) NOT NULL,
				include_pii tinyint(1) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'active',
				disabled_reason varchar(32) NOT NULL DEFAULT '',
				consecutive_failures int(10) unsigned NOT NULL DEFAULT 0,
				last_success_at datetime NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (subscription_id),
				KEY status (status)
			) ENGINE=InnoDB {charset}
-- ;;
CREATE TABLE {prefix}wpcap_webhook_deliveries (
				delivery_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				subscription_id bigint(20) unsigned NOT NULL,
				event_id varchar(40) NOT NULL,
				event_type varchar(40) NOT NULL,
				payload longtext NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'pending',
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				response_code smallint(5) unsigned NOT NULL DEFAULT 0,
				last_error varchar(190) NOT NULL DEFAULT '',
				next_attempt_at datetime NOT NULL,
				locked_at datetime NULL,
				delivered_at datetime NULL,
				pii_purged tinyint(1) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (delivery_id),
				UNIQUE KEY subscription_event (subscription_id,event_id),
				KEY due (status,next_attempt_at)
			) ENGINE=InnoDB {charset}
-- ;;

<?php
/**
 * Admission module: entry policies per ticket type (single, daily, multi-day, multiple entries,
 * re-entry), entry windows, check-out and presence, entrance areas, device sync status and the
 * review of offline double admissions ("Einlass-Konflikte").
 *
 * The check-in/-out logic itself lives in PassCaptain\Checkin (atomic, row lock on the ticket);
 * the policy evaluation and state are in src/Admission/.
 */
namespace PassCaptain\Modules;

use PassCaptain\Admission\Conflicts;
use PassCaptain\Admission\ConflictsPage;
use PassCaptain\Admission\Rest;
use PassCaptain\Admission\RulesPage;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Admission {
	public static function register() {
		add_action( 'rest_api_init', array( Rest::class, 'routes' ) );
		add_action( 'wp_enqueue_scripts', array( Rest::class, 'localize_scanner' ), 20 );
	}

	public static function schema( $prefix, $charset ) {
		return array(
			"CREATE TABLE {$prefix}wpcap_admission_policies (
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
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}wpcap_admission_state (
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
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}wpcap_admission_entries (
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
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}wpcap_admission_areas (
				area_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL,
				name varchar(190) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'active',
				capacity int(10) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (area_id),
				KEY event_status (event_id,status)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}wpcap_admission_type_areas (
				ticket_type_id bigint(20) unsigned NOT NULL,
				area_id bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (ticket_type_id,area_id),
				KEY area_id (area_id)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}wpcap_admission_area_staff (
				area_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (area_id,user_id),
				KEY user_id (user_id)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}wpcap_admission_devices (
				device_id bigint(20) unsigned NOT NULL,
				area_id bigint(20) unsigned NOT NULL DEFAULT 0,
				last_sync_at datetime NULL,
				last_sync_scans int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (device_id)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}wpcap_admission_pairings (
				pairing_id bigint(20) unsigned NOT NULL,
				area_id bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (pairing_id)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}wpcap_admission_conflicts (
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
			) ENGINE=InnoDB $charset;",
		);
	}

	public static function settings_defaults() {
		// Array setting: kept out of the generic settings form; edited on "Einlassregeln".
		return array( 'admission' => array( 'offline_max_age_hours' => 12 ) );
	}

	public static function admin_pages() {
		$open = Conflicts::open_count_for_current_user();
		$title = __( 'Einlass-Konflikte', 'wp-pass-captain' ) . ( $open ? ' <span class="awaiting-mod">' . (int) $open . '</span>' : '' );
		return array(
			array( RulesPage::SLUG, __( 'Einlassregeln', 'wp-pass-captain' ), RulesPage::CAP, array( RulesPage::class, 'render' ) ),
			array( ConflictsPage::SLUG, $title, ConflictsPage::menu_cap(), array( ConflictsPage::class, 'render' ) ),
		);
	}

	public static function admin_handlers() {
		return array(
			'wpcap_admission_save_policy' => array( RulesPage::class, 'handle_save_policy' ),
			'wpcap_admission_save_area' => array( RulesPage::class, 'handle_save_area' ),
			'wpcap_admission_area_status' => array( RulesPage::class, 'handle_area_status' ),
			'wpcap_admission_add_staff' => array( RulesPage::class, 'handle_add_staff' ),
			'wpcap_admission_remove_staff' => array( RulesPage::class, 'handle_remove_staff' ),
			'wpcap_admission_save_settings' => array( RulesPage::class, 'handle_save_settings' ),
			'wpcap_admission_resolve_conflict' => array( ConflictsPage::class, 'handle_resolve' ),
		);
	}
}

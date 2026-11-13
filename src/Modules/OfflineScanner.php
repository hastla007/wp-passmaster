<?php
/**
 * OfflineScanner module: installable PWA scanner that can admit guests without network.
 *
 * - Explicit opt-in per event (Rules): max. offline duration, policy for duplicate scans across
 *   devices, whether attendee names may be included (only with scanner_show_attendee).
 * - Device assignment per event/occurrence (Assignments): only assigned, paired devices receive
 *   packages.
 * - Signed verification packages (Package, Keys): token hashes + status + ticket type + area
 *   permissions of one occurrence, Ed25519-signed, with delta updates (cursor) incl. revocations.
 * - REST for paired devices under /app/offline/… (Rest), sync post-processing of the existing
 *   /app/scan/sync endpoint for the duplicate policy and the offline duration (Sync).
 * - Admin page "Offline-Scanner" (AdminPage) incl. key rotation with grace period.
 *
 * The PWA lives in assets/offline-scanner/. See docs/OFFLINE-SCANNER.md.
 */
namespace PassCaptain\Modules;

use PassCaptain\OfflineScanner\AdminPage;
use PassCaptain\OfflineScanner\Rest;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class OfflineScanner {
	public static function register() {
		add_action( 'rest_api_init', array( Rest::class, 'routes' ) );
		add_filter( 'rest_request_after_callbacks', array( Rest::class, 'after_callbacks' ), 10, 3 );
	}

	public static function schema( $prefix, $charset ) {
		return array(
			"CREATE TABLE {$prefix}wpcap_offline_events (
				event_id bigint(20) unsigned NOT NULL,
				enabled tinyint(1) unsigned NOT NULL DEFAULT 0,
				max_offline_hours smallint(5) unsigned NOT NULL DEFAULT 12,
				duplicate_policy varchar(20) NOT NULL DEFAULT 'review',
				include_attendee tinyint(1) unsigned NOT NULL DEFAULT 0,
				updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (event_id)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}wpcap_offline_assignments (
				device_id bigint(20) unsigned NOT NULL,
				event_id bigint(20) unsigned NOT NULL,
				occurrence_id bigint(20) unsigned NOT NULL DEFAULT 0,
				assigned_by bigint(20) unsigned NOT NULL DEFAULT 0,
				last_package_at datetime NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (device_id,event_id,occurrence_id),
				KEY event_id (event_id)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}wpcap_offline_state (
				occurrence_id bigint(20) unsigned NOT NULL,
				epoch char(16) NOT NULL,
				version int(10) unsigned NOT NULL DEFAULT 0,
				refreshed_at datetime NULL,
				PRIMARY KEY  (occurrence_id)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}wpcap_offline_entries (
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
			) ENGINE=InnoDB $charset;",
		);
	}

	public static function admin_pages() {
		return array( array( AdminPage::SLUG, __( 'Offline-Scanner', 'pass-captain' ), AdminPage::CAP, array( AdminPage::class, 'render' ) ) );
	}

	public static function admin_handlers() {
		return array(
			'wpcap_offline_save_rules' => array( AdminPage::class, 'handle_save_rules' ),
			'wpcap_offline_assign' => array( AdminPage::class, 'handle_assign' ),
			'wpcap_offline_unassign' => array( AdminPage::class, 'handle_unassign' ),
			'wpcap_offline_rotate_key' => array( AdminPage::class, 'handle_rotate_key' ),
		);
	}
}

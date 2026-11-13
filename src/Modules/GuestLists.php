<?php
/**
 * Module: guest lists and complimentary tickets ("Freikarten", "Gästelisten").
 * Code lives in src/GuestLists/ (Service = workflow, Page = admin UI, Rest = scanner lookup).
 * See docs/GUEST-LISTS.md.
 */
namespace PassCaptain\Modules;

use PassCaptain\GuestLists\Page;
use PassCaptain\GuestLists\Rest;
use PassCaptain\GuestLists\Service;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class GuestLists {
	/** Version of the capability grant (option wpcap_guestlists_caps). */
	const CAPS_VERSION = 1;

	public static function settings_defaults() {
		// Four-eyes principle: whoever requested a comp entry cannot approve it (site admins excepted).
		return array( 'guestlist_four_eyes' => 1 );
	}

	public static function register() {
		add_action( 'wpcap_order_creating', array( Service::class, 'annotate_order' ), 10, 4 );
		add_action( 'wpcap_order_cancelled', array( Service::class, 'on_order_ended' ), 10, 1 );
		add_action( 'wpcap_order_refunded', array( Service::class, 'on_order_ended' ), 10, 1 );
		add_filter( 'pre_wp_mail', array( Service::class, 'suppress_placeholder_mail' ), 10, 2 );
		add_action( 'rest_api_init', array( Rest::class, 'routes' ) );
		add_filter( 'wpcap_privacy_retention_steps', array( __CLASS__, 'retention_step' ), 10, 3 );
		add_action( 'init', array( __CLASS__, 'maybe_grant_caps' ), 6, 0 );
		add_action( 'add_option_wpcap_db_version', array( __CLASS__, 'grant_caps' ), 10, 0 );
		add_action( 'update_option_wpcap_db_version', array( __CLASS__, 'grant_caps' ), 10, 0 );
	}

	/**
	 * Retention (wpcap_privacy_retention_steps): personal fields of guest entries (name, e-mail,
	 * company, note – also of rejected/cancelled entries) are cleared once the occurrence ended more
	 * than `attendee_retention_days` (or the default attendee retention) ago. Counts, status and the
	 * link to the comp order stay for reports.
	 */
	public static function retention_step( $counts, $now, $deadline ) {
		global $wpdb;
		if ( microtime( true ) >= $deadline ) { return $counts; }
		$p = $wpdb->prefix;
		$days = \PassCaptain\Privacy\Retention::attendee_days() ?: \PassCaptain\Privacy\Retention::default_attendee_days();
		$cutoff = gmdate( 'Y-m-d H:i:s', (int) $now - $days * DAY_IN_SECONDS );
		$counts['guest_entries'] = (int) $wpdb->query( $wpdb->prepare(
			"UPDATE {$p}wpcap_guest_entries g INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=g.occurrence_id SET g.name=%s,g.email='',g.company='',g.note='',g.updated_at=%s WHERE COALESCE(o.ends_at, DATE_ADD(o.starts_at, INTERVAL 1 DAY))<%s AND (g.name<>%s OR g.email<>'' OR g.company<>'' OR g.note<>'')",
			__( 'Gast (gelöscht)', 'wp-pass-captain' ),
			gmdate( 'Y-m-d H:i:s', (int) $now ),
			$cutoff,
			__( 'Gast (gelöscht)', 'wp-pass-captain' )
		) );
		return $counts;
	}

	/** Grants wpcap_approve_comps once per CAPS_VERSION (Installer roles stay untouched). */
	public static function maybe_grant_caps() {
		if ( (int) get_option( 'wpcap_guestlists_caps', 0 ) >= self::CAPS_VERSION ) { return false; }
		return self::grant_caps();
	}

	/**
	 * Adds wpcap_approve_comps to the administrator role and, if present, to the role
	 * "Pass Captain Ticket-Administration" (wpcap_ticket_admin). Additive and idempotent.
	 */
	public static function grant_caps() {
		$granted = false;
		foreach ( array( 'administrator', 'wpcap_ticket_admin' ) as $slug ) {
			$role = function_exists( 'get_role' ) ? get_role( $slug ) : null;
			if ( $role && method_exists( $role, 'add_cap' ) ) {
				$role->add_cap( Service::APPROVE_CAP, true );
				$granted = true;
			}
		}
		if ( $granted ) { update_option( 'wpcap_guestlists_caps', self::CAPS_VERSION ); }
		return $granted;
	}

	public static function schema( $prefix, $charset ) {
		return array(
			"CREATE TABLE {$prefix}wpcap_guest_lists (
				list_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL,
				occurrence_id bigint(20) unsigned NOT NULL DEFAULT 0,
				name varchar(190) NOT NULL,
				reason varchar(20) NOT NULL DEFAULT 'other',
				quota int(10) unsigned NOT NULL DEFAULT 0,
				owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
				approver_id bigint(20) unsigned NOT NULL DEFAULT 0,
				note text NULL,
				status varchar(20) NOT NULL DEFAULT 'active',
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (list_id),
				KEY event_status (event_id,status),
				KEY occurrence_id (occurrence_id)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}wpcap_guest_entries (
				entry_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				list_id bigint(20) unsigned NOT NULL,
				event_id bigint(20) unsigned NOT NULL,
				occurrence_id bigint(20) unsigned NOT NULL,
				ticket_type_id bigint(20) unsigned NOT NULL,
				name varchar(190) NOT NULL,
				email varchar(190) NOT NULL DEFAULT '',
				company varchar(190) NOT NULL DEFAULT '',
				quantity smallint(5) unsigned NOT NULL DEFAULT 1,
				note varchar(255) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'requested',
				order_id bigint(20) unsigned NULL,
				requested_by bigint(20) unsigned NOT NULL DEFAULT 0,
				decided_by bigint(20) unsigned NOT NULL DEFAULT 0,
				decided_at datetime NULL,
				issued_at datetime NULL,
				cancelled_at datetime NULL,
				arrived_at datetime NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (entry_id),
				UNIQUE KEY order_id (order_id),
				KEY list_status (list_id,status),
				KEY occurrence_name (occurrence_id,name),
				KEY event_id (event_id)
			) ENGINE=InnoDB $charset;",
		);
	}

	public static function admin_pages() {
		return array( array( Page::SLUG, __( 'Gästelisten', 'wp-pass-captain' ), Page::menu_cap(), array( Page::class, 'render' ) ) );
	}

	public static function admin_handlers() {
		return array(
			'wpcap_gl_save_list' => array( Page::class, 'handle_save_list' ),
			'wpcap_gl_archive_list' => array( Page::class, 'handle_archive_list' ),
			'wpcap_gl_add_entry' => array( Page::class, 'handle_add_entry' ),
			'wpcap_gl_import' => array( Page::class, 'handle_import' ),
			'wpcap_gl_entry_action' => array( Page::class, 'handle_entry_action' ),
			'wpcap_gl_arrive' => array( Page::class, 'handle_arrive' ),
			'wpcap_gl_export' => array( Page::class, 'handle_export' ),
			'wpcap_gl_print' => array( Page::class, 'handle_print' ),
		);
	}
}

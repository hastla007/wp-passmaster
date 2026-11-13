<?php
/**
 * Module "Pässe": festival/multi-date passes. A pass is an ordinary ticket type (sold on its
 * "anchor" occurrence) that is linked to a set of occurrences – of the same event or across events
 * of a festival/series – and admits at each of them.
 *
 * Integration only via hooks (see docs/PASSES.md):
 * - capacity: `wpcap_reserve_capacity` (lock phase at priority 5, check phase at 1000),
 *   `wpcap_ticket_availability`, `wpcap_capacity_released`;
 * - admission: `wpcap_ticket_valid_for_occurrence` (Checkin::lookup), `wpcap_admission_policy`,
 *   `wpcap_admission_evaluate`, `wpcap_ticket_allowed_areas`;
 * - offline packages: `wpcap_offline_live_tickets`, `wpcap_offline_package_types`;
 * - display: `wpcap_ticket_seat_label`, `wpcap_ticket_mail`, `wpcap_ticket_code_mail_section`,
 *   `wpcap_event_card_html`, shortcode [wpcap_passes];
 * - cancellation: `wpcap_occurrence_cancelled` (priority 5 re-anchors, priority 20 refunds per day).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Modules;

use PassCaptain\Passes\Admission as PassAdmission;
use PassCaptain\Passes\AdminPage;
use PassCaptain\Passes\Cancellation;
use PassCaptain\Passes\Capacity;
use PassCaptain\Passes\Definitions;
use PassCaptain\Passes\Display;
use PassCaptain\Passes\Offline;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Passes {
	private static $registered = false;

	public static function register() {
		if ( self::$registered ) { return; }
		self::$registered = true;
		// Capacity: lock gates before any other module reads usage, check after all modules locked.
		add_filter( 'wpcap_reserve_capacity', array( Capacity::class, 'reserve_lock' ), 5, 3 );
		add_filter( 'wpcap_reserve_capacity', array( Capacity::class, 'reserve_check' ), 1000, 3 );
		add_filter( 'wpcap_ticket_availability', array( Capacity::class, 'availability' ), 15, 3 );
		add_action( 'wpcap_capacity_released', array( Capacity::class, 'on_capacity_released' ), 10, 3 );
		// Admission.
		add_filter( 'wpcap_ticket_valid_for_occurrence', array( PassAdmission::class, 'bind' ), 10, 3 );
		// A pass has begun as soon as its first linked date has (cancellation windows, partner API).
		add_filter( 'wpcap_order_started', array( \PassCaptain\Passes\Definitions::class, 'order_started' ), 10, 3 );
		add_filter( 'wpcap_admission_policy', array( PassAdmission::class, 'policy' ), 10, 2 );
		add_filter( 'wpcap_admission_evaluate', array( PassAdmission::class, 'evaluate' ), 10, 5 );
		add_filter( 'wpcap_ticket_allowed_areas', array( PassAdmission::class, 'allowed_areas' ), 10, 2 );
		// Offline packages.
		add_filter( 'wpcap_offline_live_tickets', array( Offline::class, 'live_tickets' ), 10, 2 );
		add_filter( 'wpcap_offline_package_types', array( Offline::class, 'package_types' ), 10, 2 );
		// Display.
		add_filter( 'wpcap_ticket_seat_label', array( Display::class, 'seat_label' ), 20, 3 );
		add_filter( 'wpcap_ticket_mail', array( Display::class, 'ticket_mail' ), 20, 3 );
		add_filter( 'wpcap_ticket_code_mail_section', array( Display::class, 'code_mail_section' ), 20, 2 );
		add_filter( 'wpcap_event_card_html', array( Display::class, 'event_card' ), 10, 3 );
		if ( function_exists( 'add_shortcode' ) ) { add_shortcode( 'wpcap_passes', array( Display::class, 'shortcode' ) ); }
		// Cancellation of a linked occurrence.
		add_action( 'wpcap_occurrence_cancelled', array( Cancellation::class, 'before_core' ), 5, 1 );
		add_action( 'wpcap_occurrence_cancelled', array( Cancellation::class, 'after_core' ), 20, 1 );
		add_filter( 'wpcap_refundable_tickets', array( Cancellation::class, 'refundable_tickets' ), 10, 2 );
		add_filter( 'wpcap_occurrence_cancellation_preview', array( Cancellation::class, 'preview' ), 10, 2 );
		add_filter( 'wpcap_occurrence_sold', array( Capacity::class, 'sold_display' ), 10, 2 );
		add_action( 'wpcap_availability_flushed', array( Definitions::class, 'flush' ), 10, 0 );
	}

	public static function schema( $prefix, $charset ) {
		$t = $prefix . 'wpcap_pass_';
		return array(
			"CREATE TABLE {$t}types (
				type_id bigint(20) unsigned NOT NULL,
				entry_mode varchar(10) NOT NULL DEFAULT 'once',
				max_occurrences int(10) unsigned NOT NULL DEFAULT 0,
				area_ids text NULL,
				refund_per_day_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				on_cancel varchar(10) NOT NULL DEFAULT 'keep',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (type_id)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$t}occurrences (
				type_id bigint(20) unsigned NOT NULL,
				occurrence_id bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (type_id,occurrence_id),
				KEY occurrence (occurrence_id)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$t}gates (
				occurrence_id bigint(20) unsigned NOT NULL,
				pass_quota int(10) unsigned NOT NULL DEFAULT 0,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (occurrence_id)
			) ENGINE=InnoDB $charset;",
		);
	}

	public static function admin_pages() {
		return array( array( AdminPage::SLUG, __( 'Pässe', 'pass-captain' ), Definitions::CAP, array( AdminPage::class, 'render' ) ) );
	}

	public static function admin_handlers() {
		return array(
			'wpcap_pass_create' => array( AdminPage::class, 'handle_create' ),
			'wpcap_pass_save' => array( AdminPage::class, 'handle_save' ),
			'wpcap_pass_remove' => array( AdminPage::class, 'handle_remove' ),
			'wpcap_pass_quota' => array( AdminPage::class, 'handle_quota' ),
		);
	}
}

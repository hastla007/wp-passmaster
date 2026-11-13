<?php
/**
 * Module "Kontingente": reserved contingents (holds/kills) per occurrence or ticket type, seat holds
 * on seated occurrences and per-buyer ticket limits per event/ticket type.
 *
 * Integration only via core hooks (see docs/ALLOCATIONS.md):
 * - `wpcap_reserve_capacity`: locks the occurrence's holds and enforces them for public sources;
 * - `wpcap_order_creating`: hold consumption by trusted sources (box office, guest list, admin),
 *   seat transfer from a seat hold, per-buyer limit (buyer row lock);
 * - `wpcap_ticket_availability`: public availability minus open holds;
 * - `wpcap_capacity_released`: returns consumed hold units of expired/cancelled/refunded orders;
 * - `wpcap_checkout_error_messages`: German messages for the abort codes.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Modules;

use PassCaptain\Allocations\AdminPage;
use PassCaptain\Allocations\BuyerLimits;
use PassCaptain\Allocations\Holds;
use PassCaptain\Allocations\SeatHolds;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Allocations {
	private static $registered = false;

	public static function register() {
		if ( self::$registered ) { return; }
		self::$registered = true;
		add_filter( 'wpcap_reserve_capacity', array( Holds::class, 'reserve_capacity' ), 20, 3 );
		add_filter( 'wpcap_ticket_availability', array( Holds::class, 'availability' ), 20, 3 );
		add_action( 'wpcap_order_creating', array( Holds::class, 'on_order_creating' ), 4, 4 );
		add_action( 'wpcap_order_creating', array( BuyerLimits::class, 'on_order_creating' ), 6, 4 );
		add_filter( 'wpcap_reserve_capacity', array( BuyerLimits::class, 'reserve_exchange' ), 25, 3 );
		add_action( 'wpcap_availability_flushed', array( Holds::class, 'flush' ), 10, 0 );
		add_action( 'wpcap_availability_flushed', array( BuyerLimits::class, 'flush' ), 10, 0 );
		// Keyed buyer hashes: migrated once with the schema install/upgrade (not on every request).
		add_action( 'add_option_wpcap_db_version', array( BuyerLimits::class, 'maybe_migrate_keys' ), 10, 0 );
		add_action( 'update_option_wpcap_db_version', array( BuyerLimits::class, 'maybe_migrate_keys' ), 10, 0 );
		add_filter( 'wpcap_privacy_retention_steps', array( BuyerLimits::class, 'retention_step' ), 10, 3 );
		add_filter( 'wp_privacy_personal_data_erasers', static function ( $erasers ) {
			$erasers['pass-captain-buyer-limits'] = array( 'eraser_friendly_name' => __( 'Pass Captain Käuferlimit', 'pass-captain' ), 'callback' => array( BuyerLimits::class, 'privacy_erase' ) );
			return $erasers;
		} );
		add_filter( 'wp_privacy_personal_data_exporters', static function ( $exporters ) {
			$exporters['pass-captain-buyer-limits'] = array( 'exporter_friendly_name' => __( 'Pass Captain Käuferlimit', 'pass-captain' ), 'callback' => array( BuyerLimits::class, 'privacy_export' ) );
			return $exporters;
		} );
		add_action( 'wpcap_order_creating', array( SeatHolds::class, 'after_seat_claims' ), 20, 4 );
		add_action( 'wpcap_capacity_released', array( Holds::class, 'on_capacity_released' ), 10, 3 );
		add_filter( 'wpcap_checkout_error_messages', array( __CLASS__, 'error_messages' ) );
		add_action( 'wpcap_cleanup_reservations', array( Holds::class, 'auto_release_due' ), 30, 0 );
		add_action( 'wpcap_checkout_form_fields', array( BuyerLimits::class, 'render_notice' ), 5, 2 );
		add_filter( 'wpcap_event_card_quantity_max', array( BuyerLimits::class, 'card_quantity_max' ), 10, 3 );
		add_filter( 'wpcap_event_card_max_total', array( BuyerLimits::class, 'card_max_total' ), 10, 2 );
	}

	public static function schema( $prefix, $charset ) {
		$t = $prefix . 'wpcap_alloc_';
		return array(
			"CREATE TABLE {$t}holds (
				hold_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				occurrence_id bigint(20) unsigned NOT NULL,
				ticket_type_id bigint(20) unsigned NOT NULL DEFAULT 0,
				name varchar(100) NOT NULL DEFAULT '',
				kind varchar(10) NOT NULL DEFAULT 'hold',
				quantity int(10) unsigned NOT NULL DEFAULT 0,
				used int(10) unsigned NOT NULL DEFAULT 0,
				release_hours int(10) unsigned NOT NULL DEFAULT 0,
				sources varchar(190) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'active',
				note varchar(255) NOT NULL DEFAULT '',
				released_at datetime NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (hold_id),
				KEY occurrence (occurrence_id,status)
			) $charset;",
			"CREATE TABLE {$t}usage (
				usage_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				hold_id bigint(20) unsigned NOT NULL,
				order_id bigint(20) unsigned NOT NULL,
				ticket_type_id bigint(20) unsigned NOT NULL,
				quantity int(10) unsigned NOT NULL DEFAULT 0,
				released int(10) unsigned NOT NULL DEFAULT 0,
				source varchar(32) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (usage_id),
				KEY hold (hold_id,status),
				KEY order_id (order_id)
			) $charset;",
			"CREATE TABLE {$t}hold_seats (
				hold_seat_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				hold_id bigint(20) unsigned NOT NULL,
				occurrence_id bigint(20) unsigned NOT NULL,
				claim_id bigint(20) unsigned NOT NULL DEFAULT 0,
				seat_id bigint(20) unsigned NOT NULL,
				seat_key varchar(64) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'held',
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (hold_seat_id),
				KEY hold (hold_id,status),
				KEY claim (claim_id)
			) $charset;",
			"CREATE TABLE {$t}limits (
				limit_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL,
				ticket_type_id bigint(20) unsigned NOT NULL DEFAULT 0,
				max_per_buyer int(10) unsigned NOT NULL DEFAULT 0,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (limit_id),
				UNIQUE KEY event_type (event_id,ticket_type_id)
			) $charset;",
			"CREATE TABLE {$t}buyers (
				buyer_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL,
				buyer_key char(64) NOT NULL,
				holdings longtext NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (buyer_id),
				UNIQUE KEY event_buyer (event_id,buyer_key)
			) $charset;",
		);
	}

	public static function settings_defaults() {
		return array(
			'allocations' => array(
				// Default hours before start at which new holds are released to public sale (0 = never).
				'auto_release_hours' => 48,
				// Also count tickets per logged-in WordPress user (in addition to the e-mail address).
				'buyer_limit_by_user' => 1,
			),
		);
	}

	public static function admin_pages() {
		return array( array( AdminPage::SLUG, __( 'Kontingente', 'pass-captain' ), AdminPage::CAP, array( AdminPage::class, 'render' ) ) );
	}

	public static function admin_handlers() {
		return array(
			'wpcap_alloc_create' => array( AdminPage::class, 'handle_create' ),
			'wpcap_alloc_release' => array( AdminPage::class, 'handle_release' ),
			'wpcap_alloc_update' => array( AdminPage::class, 'handle_update' ),
			'wpcap_alloc_seats' => array( AdminPage::class, 'handle_seats' ),
			'wpcap_alloc_limits' => array( AdminPage::class, 'handle_limits' ),
			'wpcap_alloc_settings' => array( AdminPage::class, 'handle_settings' ),
		);
	}

	/** Module configuration merged with defaults. */
	public static function config() {
		$stored = \PassCaptain\Settings::get( 'allocations' );
		return array_merge( self::settings_defaults()['allocations'], is_array( $stored ) ? $stored : array() );
	}

	public static function error_messages( $messages ) {
		return array_merge( (array) $messages, Holds::error_messages(), BuyerLimits::error_messages() );
	}
}

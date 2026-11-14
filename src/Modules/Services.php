<?php
/**
 * Module: service bookings (Bootsschule/Termine) – booking lifecycle hooks (payment confirmation,
 * expiry, ticket check-in), job handlers (refund retries, waitlist offers), the periodic sweep
 * (partner confirmation deadlines, no-shows, expired waitlist offers), event waitlist automation,
 * buyer REST endpoints and the public booking/waitlist pages. Docs: docs/SERVICE-BOOKINGS.md.
 *
 * Tables live in the core schema (Installer): wpcap_resources, wpcap_service_slots,
 * wpcap_service_bookings, wpcap_service_waitlist.
 */
namespace PassCaptain\Modules;

use PassCaptain\ServiceBookings;
use PassCaptain\Services\Api;
use PassCaptain\Services\Portal;
use PassCaptain\Waitlists;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Services {
	/** Whether register() already ran in this request (tests call it explicitly). */
	private static $registered = false;

	public static function register() {
		if ( self::$registered ) { return; }
		self::$registered = true;
		add_action( 'wpcap_order_paid', array( ServiceBookings::class, 'on_order_paid' ), 30, 1 );
		add_action( 'wpcap_tickets_issued', array( ServiceBookings::class, 'on_order_paid' ), 30, 1 );
		add_action( 'wpcap_order_cancelled', array( ServiceBookings::class, 'on_order_cancelled' ), 20, 1 );
		add_action( 'wpcap_ticket_checked_in', array( ServiceBookings::class, 'on_ticket_checked_in' ), 20, 1 );
		add_filter( 'wp_pass_captain_process_job_v1', array( ServiceBookings::class, 'handle_job' ), 10, 3 );
		// Before the job worker runs: deadlines, no-shows, expired offers (each queues follow-up work).
		add_action( 'wpcap_process_jobs', array( __CLASS__, 'sweep' ), 5, 0 );
		Waitlists::register();
		add_action( 'rest_api_init', array( Api::class, 'register_routes' ) );
		add_action( 'template_redirect', array( Portal::class, 'maybe_render' ) );
		add_action( 'admin_post_wpcap_service_portal', array( Portal::class, 'handle_post' ) );
		add_action( 'admin_post_nopriv_wpcap_service_portal', array( Portal::class, 'handle_post' ) );
		add_filter( 'wpcap_order_portal_sections', array( Portal::class, 'order_section' ), 10, 3 );
	}

	/** Cron entry point (wpcap_process_jobs, priority 5). */
	public static function sweep() {
		return ServiceBookings::sweep();
	}
}

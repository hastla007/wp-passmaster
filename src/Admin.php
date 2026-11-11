<?php
/**
 * Backwards-compatible entry points for the admin screens.
 *
 * The screens and handlers live in src/Admin/ (registered via Admin\Menu::register()). These public
 * static methods are kept because other code and existing hook registrations may call them.
 */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Admin {
	/** Events screen (former single admin page). */
	public static function render_page() {
		Admin\EventsPage::render();
	}

	/** admin-post: wpcap_create_event. */
	public static function create_event() {
		Admin\EventsPage::handle_create_event();
	}

	/** admin-post: wpcap_assign_staff. */
	public static function assign_staff() {
		Admin\EventsPage::handle_assign_staff();
	}

	/** admin-post: wpcap_confirm_payment (nonce wpcap_confirm_payment_{order_id}). */
	public static function confirm_payment() {
		Admin\OrdersPage::handle_confirm_payment();
	}

	/** admin-post: wpcap_resend_tickets (nonce wpcap_resend_tickets_{order_id}). */
	public static function resend_tickets() {
		Admin\OrdersPage::handle_resend_tickets();
	}

	/** admin-post: wpcap_cancel_event (nonce wpcap_cancel_event_{event_id}). */
	public static function cancel_event() {
		Admin\EventsPage::handle_cancel_event();
	}
}

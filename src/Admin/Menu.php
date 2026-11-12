<?php
/**
 * Registers the "Pass Captain" admin menu and all admin-post handlers of the admin screens.
 * Call Menu::register() once from Plugin::run().
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Refunds;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Menu {
	const SLUG = 'wp-pass-captain';

	/** admin-post action => handler. */
	public static function handlers() {
		return array(
			'wpcap_create_event' => array( EventsPage::class, 'handle_create_event' ),
			'wpcap_update_event' => array( EventsPage::class, 'handle_update_event' ),
			'wpcap_cancel_event' => array( EventsPage::class, 'handle_cancel_event' ),
			'wpcap_save_occurrence' => array( EventsPage::class, 'handle_save_occurrence' ),
			'wpcap_cancel_occurrence' => array( EventsPage::class, 'handle_cancel_occurrence' ),
			'wpcap_save_ticket_type' => array( EventsPage::class, 'handle_save_ticket_type' ),
			'wpcap_save_participant_field' => array( EventsPage::class, 'handle_save_participant_field' ),
			'wpcap_participant_field_status' => array( EventsPage::class, 'handle_participant_field_status' ),
			'wpcap_assign_staff' => array( EventsPage::class, 'handle_assign_staff' ),
			'wpcap_remove_staff' => array( EventsPage::class, 'handle_remove_staff' ),
			'wpcap_confirm_payment' => array( OrdersPage::class, 'handle_confirm_payment' ),
			'wpcap_cancel_order' => array( OrdersPage::class, 'handle_cancel_order' ),
			'wpcap_resend_tickets' => array( OrdersPage::class, 'handle_resend_tickets' ),
			'wpcap_refund_order' => array( OrdersPage::class, 'handle_refund' ),
			'wpcap_approve_refund' => array( OrdersPage::class, 'handle_approve_refund' ),
			'wpcap_reject_refund' => array( OrdersPage::class, 'handle_reject_refund' ),
			'wpcap_revoke_ticket' => array( TicketsPage::class, 'handle_revoke_ticket' ),
			'wpcap_undo_checkin' => array( TicketsPage::class, 'handle_undo_checkin' ),
			'wpcap_save_settings' => array( SettingsPage::class, 'handle_save' ),
			'wpcap_create_pairing' => array( DevicesPage::class, 'handle_create_pairing' ),
			'wpcap_revoke_device' => array( DevicesPage::class, 'handle_revoke_device' ),
		) + \PassCaptain\Modules::admin_handlers();
	}

	public static function register() {
		add_action( 'admin_menu', array( self::class, 'add_menus' ) );
		add_action( 'admin_head', array( self::class, 'admin_styles' ) );
		foreach ( self::handlers() as $action => $handler ) {
			add_action( 'admin_post_' . $action, $handler );
		}
	}

	/** Small accessibility fixes on plugin pages: readable code in links (contrast), delete links. */
	public static function admin_styles() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only selects CSS.
		if ( ! str_starts_with( $page, 'wpcap' ) && 'wp-pass-captain' !== $page ) { return; }
		echo '<style>#wpbody-content a code{color:inherit;background:transparent}#wpbody-content .button-link-delete{color:#b32d2e}</style>';
	}

	/** First capability of the list the current user holds (fallback: the first one). */
	private static function first_cap( array $caps ) {
		foreach ( $caps as $cap ) {
			if ( current_user_can( $cap ) ) { return $cap; }
		}
		return $caps[0];
	}

	public static function add_menus() {
		$top_cap = self::first_cap( array( EventsPage::CAP, OrdersPage::CAP, 'wpcap_checkin', 'wpcap_view_reports', Refunds::APPROVE_CAP, SettingsPage::CAP ) );
		add_menu_page(
			__( 'WP Pass Captain', 'wp-pass-captain' ),
			__( 'Pass Captain', 'wp-pass-captain' ),
			$top_cap,
			self::SLUG,
			array( EventsPage::class, 'render' ),
			'dashicons-tickets-alt',
			58
		);
		add_submenu_page( self::SLUG, __( 'Events', 'wp-pass-captain' ), __( 'Events', 'wp-pass-captain' ), EventsPage::CAP, self::SLUG, array( EventsPage::class, 'render' ) );
		add_submenu_page( self::SLUG, __( 'Bestellungen', 'wp-pass-captain' ), __( 'Bestellungen', 'wp-pass-captain' ), OrdersPage::CAP, OrdersPage::SLUG, array( OrdersPage::class, 'render' ) );
		add_submenu_page( self::SLUG, __( 'Tickets', 'wp-pass-captain' ), __( 'Tickets', 'wp-pass-captain' ), TicketsPage::menu_cap(), TicketsPage::SLUG, array( TicketsPage::class, 'render' ) );
		add_submenu_page( self::SLUG, __( 'Check-ins', 'wp-pass-captain' ), __( 'Check-ins', 'wp-pass-captain' ), CheckinsPage::menu_cap(), CheckinsPage::SLUG, array( CheckinsPage::class, 'render' ) );
		$pending = current_user_can( Refunds::APPROVE_CAP ) ? count( Queries::pending_refunds( Queries::event_ids_for( OrdersPage::CAP ) ) ) : 0;
		$refund_title = __( 'Erstattungen', 'wp-pass-captain' ) . ( $pending ? ' <span class="awaiting-mod">' . (int) $pending . '</span>' : '' );
		add_submenu_page( self::SLUG, __( 'Erstattungen', 'wp-pass-captain' ), $refund_title, RefundsPage::menu_cap(), RefundsPage::SLUG, array( RefundsPage::class, 'render' ) );
		add_submenu_page( self::SLUG, __( 'Scanner-Geräte', 'wp-pass-captain' ), __( 'Scanner-Geräte', 'wp-pass-captain' ), DevicesPage::CAP, DevicesPage::SLUG, array( DevicesPage::class, 'render' ) );
		foreach ( \PassCaptain\Modules::admin_pages() as $page ) {
			list( $slug, $title, $cap, $callback ) = $page;
			add_submenu_page( self::SLUG, $title, $title, $cap, $slug, $callback );
		}
		add_submenu_page( self::SLUG, __( 'Einstellungen', 'wp-pass-captain' ), __( 'Einstellungen', 'wp-pass-captain' ), SettingsPage::CAP, SettingsPage::SLUG, array( SettingsPage::class, 'render' ) );
		if ( \PassCaptain\Integrations\Vouchers\IntegrationStatus::voucher_captain_active() ) {
			// Links into Voucher Captain's own menu (both plugins keep their menus, CONCEPT 15.7).
			add_submenu_page( self::SLUG, __( 'Gemeinsamer Scanner', 'wp-pass-captain' ), __( 'Gemeinsamer Scanner ↗', 'wp-pass-captain' ), 'wpcap_checkin', 'admin.php?page=vcap-scanner' );
			add_submenu_page( self::SLUG, __( 'WP Voucher Captain', 'wp-pass-captain' ), __( 'WP Voucher Captain ↗', 'wp-pass-captain' ), 'manage_woocommerce', 'admin.php?page=vcap-vouchers' );
		}
	}
}

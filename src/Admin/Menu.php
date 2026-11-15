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
			'wpcap_reissue_ticket' => array( TicketsPage::class, 'handle_reissue_ticket' ),
			'wpcap_save_settings' => array( SettingsPage::class, 'handle_save' ),
			'wpcap_create_pairing' => array( DevicesPage::class, 'handle_create_pairing' ),
			'wpcap_revoke_device' => array( DevicesPage::class, 'handle_revoke_device' ),
		) + FeatureScreens::handlers() + StatusPage::handlers() + SecurityScreens::handlers() + \PassCaptain\Modules::admin_handlers();
	}

	public static function register() {
		add_action( 'admin_menu', array( self::class, 'add_menus' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'admin_styles' ) );
		StatusPage::register();
		foreach ( self::handlers() as $action => $handler ) {
			add_action( 'admin_post_' . $action, $handler );
		}
	}

	/** Whether the current admin request shows one of the plugin's own pages (admin.php?page=wpcap…). */
	public static function is_plugin_page() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only selects output.
		return str_starts_with( $page, 'wpcap' ) || 'wp-pass-captain' === $page;
	}

	/** Inline CSS of admin_styles() (handle wpcap-admin). */
	const ADMIN_CSS = '#wpbody-content a code{color:inherit;background:transparent}#wpbody-content .button-link-delete{color:#b32d2e}'
		. '#wpbody-content input[type=checkbox],#wpbody-content input[type=radio]{width:1.5rem;height:1.5rem;margin-top:0}'
		. '#wpbody-content input[type=checkbox]:checked::before{width:1.8rem;height:1.8rem;margin:-.15rem -.3rem}'
		. '#wpbody-content input[type=radio]:checked::before{width:.5625rem;height:.5625rem;margin:.40625rem}';

	/**
	 * Small accessibility fixes on plugin pages: readable code in links (contrast), delete links, and checkboxes/radios
	 * with a 24×24 px target (WCAG 2.2 SC 2.5.8; core draws them 16 px wide on desktop). Added with
	 * wp_add_inline_style() to a source-less handle (no raw <style> in admin_head).
	 */
	public static function admin_styles() {
		if ( ! self::is_plugin_page() ) { return; }
		wp_register_style( 'wpcap-admin', false, array(), WPCAP_VERSION );
		wp_enqueue_style( 'wpcap-admin' );
		wp_add_inline_style( 'wpcap-admin', self::ADMIN_CSS );
	}

	/** First capability of the list the current user holds (fallback: the first one). */
	private static function first_cap( array $caps ) {
		foreach ( $caps as $cap ) {
			if ( current_user_can( $cap ) ) { return $cap; }
		}
		return $caps[0];
	}

	/**
	 * Submenu pages of add-ons that are not modules (filter wp_pass_captain_admin_menu_v1), same format
	 * as a module's admin_pages(): [ slug, title, capability, callable ]. Invalid entries and slugs of
	 * core pages are ignored. Listed before "Einstellungen".
	 *
	 * @return array<int,array{0:string,1:string,2:string,3:callable}>
	 */
	public static function extra_pages() {
		$pages = apply_filters( 'wp_pass_captain_admin_menu_v1', array() );
		if ( ! is_array( $pages ) ) { return array(); }
		$reserved = array( self::SLUG, OrdersPage::SLUG, TicketsPage::SLUG, CheckinsPage::SLUG, RefundsPage::SLUG, DevicesPage::SLUG, SettingsPage::SLUG );
		$reserved[] = StatusPage::SLUG;
		foreach ( array_merge( FeatureScreens::pages(), SecurityScreens::pages(), \PassCaptain\Modules::admin_pages() ) as $page ) { $reserved[] = (string) $page[0]; }
		$out = array();
		foreach ( $pages as $page ) {
			if ( ! is_array( $page ) || 4 !== count( $page ) ) { continue; }
			list( $slug, $title, $cap, $callback ) = array_values( $page );
			$slug = sanitize_key( (string) $slug );
			if ( '' === $slug || in_array( $slug, $reserved, true ) || '' === (string) $cap || ! is_callable( $callback ) ) { continue; }
			$reserved[] = $slug;
			$out[] = array( $slug, (string) $title, (string) $cap, $callback );
		}
		return $out;
	}

	public static function add_menus() {
		$top_cap = self::first_cap( array( EventsPage::CAP, OrdersPage::CAP, 'wpcap_checkin', 'wpcap_view_reports', Refunds::APPROVE_CAP, SettingsPage::CAP ) );
		// White label: title and icon (wp_pass_captain_admin_menu_title_v1 / _icon_v1, PassCaptain\Brand).
		$menu_title = \PassCaptain\Brand::menu_title();
		add_menu_page(
			$menu_title,
			$menu_title,
			$top_cap,
			self::SLUG,
			array( EventsPage::class, 'render' ),
			\PassCaptain\Brand::menu_icon(),
			58
		);
		add_submenu_page( self::SLUG, __( 'Events', 'pass-captain' ), __( 'Events', 'pass-captain' ), EventsPage::CAP, self::SLUG, array( EventsPage::class, 'render' ) );
		add_submenu_page( self::SLUG, __( 'Bestellungen', 'pass-captain' ), __( 'Bestellungen', 'pass-captain' ), OrdersPage::CAP, OrdersPage::SLUG, array( OrdersPage::class, 'render' ) );
		add_submenu_page( self::SLUG, __( 'Tickets', 'pass-captain' ), __( 'Tickets', 'pass-captain' ), TicketsPage::menu_cap(), TicketsPage::SLUG, array( TicketsPage::class, 'render' ) );
		add_submenu_page( self::SLUG, __( 'Check-ins', 'pass-captain' ), __( 'Check-ins', 'pass-captain' ), CheckinsPage::menu_cap(), CheckinsPage::SLUG, array( CheckinsPage::class, 'render' ) );
		$pending = current_user_can( Refunds::APPROVE_CAP ) ? count( Queries::pending_refunds( Queries::event_ids_for( OrdersPage::CAP ) ) ) : 0;
		$refund_title = __( 'Erstattungen', 'pass-captain' ) . ( $pending ? ' <span class="awaiting-mod">' . (int) $pending . '</span>' : '' );
		add_submenu_page( self::SLUG, __( 'Erstattungen', 'pass-captain' ), $refund_title, RefundsPage::menu_cap(), RefundsPage::SLUG, array( RefundsPage::class, 'render' ) );
		add_submenu_page( self::SLUG, __( 'Scanner-Geräte', 'pass-captain' ), __( 'Scanner-Geräte', 'pass-captain' ), DevicesPage::CAP, DevicesPage::SLUG, array( DevicesPage::class, 'render' ) );
		foreach ( array_merge( FeatureScreens::pages(), \PassCaptain\Modules::admin_pages(), self::extra_pages() ) as $page ) {
			list( $slug, $title, $cap, $callback ) = $page;
			add_submenu_page( self::SLUG, $title, $title, $cap, $slug, $callback );
		}
		foreach ( SecurityScreens::pages() as $page ) { add_submenu_page( self::SLUG, $page[1], $page[1], $page[2], $page[0], $page[3] ); }
		add_submenu_page( self::SLUG, __( 'Systemstatus', 'pass-captain' ), StatusPage::menu_title(), StatusPage::CAP, StatusPage::SLUG, array( StatusPage::class, 'render' ) );
		add_submenu_page( self::SLUG, __( 'Einstellungen', 'pass-captain' ), __( 'Einstellungen', 'pass-captain' ), SettingsPage::CAP, SettingsPage::SLUG, array( SettingsPage::class, 'render' ) );
		if ( \PassCaptain\Integrations\Vouchers\IntegrationStatus::voucher_captain_active() ) {
			// Links into Voucher Captain's own menu (both plugins keep their menus, CONCEPT 15.7).
			add_submenu_page( self::SLUG, __( 'Gemeinsamer Scanner', 'pass-captain' ), __( 'Gemeinsamer Scanner ↗', 'pass-captain' ), 'wpcap_checkin', 'admin.php?page=vcap-scanner' );
			add_submenu_page( self::SLUG, __( 'WP Voucher Captain', 'pass-captain' ), __( 'WP Voucher Captain ↗', 'pass-captain' ), 'manage_woocommerce', 'admin.php?page=vcap-vouchers' );
		}
		/**
		 * The core menu is complete: add-ons add their own submenu pages under $parent_slug here (they are
		 * listed after the core pages). Independent of modules and of the order of admin_menu callbacks.
		 */
		do_action( 'wp_pass_captain_admin_menu_built_v1', self::SLUG );
	}
}

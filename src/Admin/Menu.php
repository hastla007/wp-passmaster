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
			'wpcap_retry_allocation' => array( OrdersPage::class, 'handle_retry_allocation' ),
			'wpcap_cancel_order' => array( OrdersPage::class, 'handle_cancel_order' ),
			'wpcap_resend_tickets' => array( OrdersPage::class, 'handle_resend_tickets' ),
			'wpcap_refund_order' => array( OrdersPage::class, 'handle_refund' ),
			'wpcap_approve_refund' => array( OrdersPage::class, 'handle_approve_refund' ),
			'wpcap_reject_refund' => array( OrdersPage::class, 'handle_reject_refund' ),
			'wpcap_consent_withdraw' => array( \PassCaptain\Consents::class, 'handle_admin_withdraw' ),
			'wpcap_export_consents' => array( \PassCaptain\Consents::class, 'handle_export' ),
			'wpcap_revoke_ticket' => array( TicketsPage::class, 'handle_revoke_ticket' ),
			'wpcap_undo_checkin' => array( TicketsPage::class, 'handle_undo_checkin' ),
			'wpcap_correct_attendee' => array( TicketsPage::class, 'handle_correct_attendee' ),
			'wpcap_reissue_ticket' => array( TicketsPage::class, 'handle_reissue_ticket' ),
			'wpcap_save_settings' => array( SettingsPage::class, 'handle_save' ),
			'wpcap_create_pairing' => array( DevicesPage::class, 'handle_create_pairing' ),
			'wpcap_revoke_device' => array( DevicesPage::class, 'handle_revoke_device' ),
			'wpcap_campaign_create' => array( CampaignsPage::class, 'handle_create' ),
			'wpcap_campaign_status' => array( CampaignsPage::class, 'handle_status' ),
			'wpcap_getting_started' => array( GettingStarted::class, 'handle_toggle' ),
		) +\PassCaptain\Modules::admin_handlers();
	}

	public static function register() {
		add_action( 'admin_menu', array( self::class, 'add_menus' ) );
		add_action( 'admin_head', array( self::class, 'admin_styles' ) );
		add_action( 'admin_footer', array( self::class, 'menu_heading_script' ) );
		add_action( 'admin_notices', array( self::class, 'schema_error_notice' ) );
		foreach ( self::handlers() as $action => $handler ) {
			add_action( 'admin_post_' . $action, $handler );
		}
	}

	/** Failed schema upgrade (Installer::missing_schema()): administrators see what is missing. */
	public static function schema_error_notice() {
		$error = get_option( 'wpcap_schema_error' );
		if ( ! is_array( $error ) || ! current_user_can( 'manage_options' ) ) { return; }
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'WP Pass Captain: Das Datenbank-Update ist unvollständig.', 'wp-pass-captain' ) . '</strong> ' . esc_html__( 'Es fehlen Tabellen oder Spalten; das Plugin arbeitet weiter mit dem alten Schema. Bitte Datenbankrechte prüfen und das Plugin deaktivieren und wieder aktivieren.', 'wp-pass-captain' ) . '</p><p><code>' . esc_html( implode( ', ', array_slice( (array) ( $error['missing'] ?? array() ), 0, 10 ) ) ) . '</code>' . ( ! empty( $error['db_error'] ) ? ' – ' . esc_html( (string) $error['db_error'] ) : '' ) . '</p></div>';
	}

	/** Area headings of the menu (every admin page); small accessibility fixes on plugin pages: readable code in links (contrast), delete links. */
	public static function admin_styles() {
		echo '<style>#adminmenu li.wpcap-menu-heading{margin-top:6px;padding-top:4px;border-top:1px solid rgba(240,246,252,.15)}#adminmenu li.wpcap-menu-heading a,#adminmenu li.wpcap-menu-heading .wpcap-menu-heading-label{display:block;padding:5px 12px 1px;font-size:11px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:#c3c4c7;pointer-events:none;cursor:default}</style>';
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only selects CSS.
		if ( ! str_starts_with( $page, 'wpcap' ) && 'wp-pass-captain' !== $page ) { return; }
		echo '<style>#wpbody-content a code{color:inherit;background:transparent}#wpbody-content .button-link-delete{color:#b32d2e}#wpbody-content .wpcap-admin details>summary{min-height:24px;padding:2px 0;line-height:24px;cursor:pointer}</style>';
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
		foreach ( self::layout( self::entries() ) as $row ) {
			if ( 'heading' === $row[0] ) {
				// Area heading (index 4 = CSS class of the list item); menu_heading_script() turns the link into text.
				$GLOBALS['submenu'][ self::SLUG ][] = array( $row[2], $row[3], '#wpcap-menu-' . $row[1], $row[2], 'wpcap-menu-heading' );
				continue;
			}
			list( $slug, $title, $menu_title, $cap, $callback ) = $row[1];
			if ( null === $callback ) {
				add_submenu_page( self::SLUG, $title, $menu_title, $cap, $slug );
			} else {
				add_submenu_page( self::SLUG, $title, $menu_title, $cap, $slug, $callback );
			}
		}
	}

	/** Menu areas in display order: key => heading. */
	public static function groups() {
		return array(
			'events'        => __( 'Events & Programm', 'wp-pass-captain' ),
			'sales'         => __( 'Verkauf', 'wp-pass-captain' ),
			'admission'     => __( 'Einlass', 'wp-pass-captain' ),
			'finance'       => __( 'Finanzen & Auswertung', 'wp-pass-captain' ),
			'communication' => __( 'Kommunikation', 'wp-pass-captain' ),
			'settings'      => __( 'Einstellungen & Integrationen', 'wp-pass-captain' ),
		);
	}

	/** Area of the built-in and bundled module pages (page slug => group key); unknown slugs land in "settings". */
	public static function group_map() {
		return array(
			self::SLUG                     => 'events',
			'wpcap-series'                 => 'events',
			'wpcap-calendar'               => 'events',
			'wpcap-seating'                => 'events',
			'wpcap-passes'                 => 'events',
			'wpcap-print'                  => 'events',
			OrdersPage::SLUG               => 'sales',
			TicketsPage::SLUG              => 'sales',
			'wpcap-boxoffice'              => 'sales',
			CampaignsPage::SLUG            => 'sales',
			'wpcap-allocations'            => 'sales',
			'wpcap-guestlists'             => 'sales',
			'wpcap-waitlist'               => 'sales',
			'wpcap-exchange'               => 'sales',
			'wpcap-partners'               => 'sales',
			CheckinsPage::SLUG             => 'admission',
			DevicesPage::SLUG              => 'admission',
			'wpcap-offline-scanner'        => 'admission',
			'wpcap-admission'              => 'admission',
			'wpcap-admission-conflicts'    => 'admission',
			'admin.php?page=vcap-scanner'  => 'admission',
			RefundsPage::SLUG              => 'finance',
			'wpcap-reports'                => 'finance',
			'wpcap-partner-settlements'    => 'finance',
			'wpcap-mail-templates'         => 'communication',
			'wpcap-reminders'              => 'communication',
			'wpcap-sms'                    => 'communication',
			SettingsPage::SLUG             => 'settings',
			AuditLogPage::SLUG             => 'settings',
			'wpcap-mollie'                 => 'settings',
			'wpcap-wallet'                 => 'settings',
			'wpcap-webhooks'               => 'settings',
			'admin.php?page=vcap-vouchers' => 'settings',
		);
	}

	/** Area key of a submenu page: declared by the module (5th element), else the map, then the filter. */
	public static function group_of( $slug, $declared = '' ) {
		$groups = self::groups();
		$map = self::group_map();
		$group = '' !== (string) $declared ? (string) $declared : ( $map[ (string) $slug ] ?? 'settings' );
		/** Area of a Pass Captain submenu page (events, sales, admission, finance, communication, settings). */
		$group = (string) apply_filters( 'wpcap_admin_menu_group', $group, (string) $slug );
		return isset( $groups[ $group ] ) ? $group : 'settings';
	}

	/**
	 * All submenu entries in registration order: [slug, page title, menu title, capability,
	 * callback (null = plain link), declared area]. Module pages may declare their area as 5th element.
	 */
	public static function entries() {
		$pending = current_user_can( Refunds::APPROVE_CAP ) ? count( Queries::pending_refunds( Queries::event_ids_for( OrdersPage::CAP ) ) ) : 0;
		$refund_title = __( 'Erstattungen', 'wp-pass-captain' ) . ( $pending ? ' <span class="awaiting-mod">' . (int) $pending . '</span>' : '' );
		$entries = array(
			array( self::SLUG, __( 'Events', 'wp-pass-captain' ), __( 'Events', 'wp-pass-captain' ), EventsPage::CAP, array( EventsPage::class, 'render' ), '' ),
			array( OrdersPage::SLUG, __( 'Bestellungen', 'wp-pass-captain' ), __( 'Bestellungen', 'wp-pass-captain' ), OrdersPage::CAP, array( OrdersPage::class, 'render' ), '' ),
			array( TicketsPage::SLUG, __( 'Tickets', 'wp-pass-captain' ), __( 'Tickets', 'wp-pass-captain' ), TicketsPage::menu_cap(), array( TicketsPage::class, 'render' ), '' ),
			array( CheckinsPage::SLUG, __( 'Check-ins', 'wp-pass-captain' ), __( 'Check-ins', 'wp-pass-captain' ), CheckinsPage::menu_cap(), array( CheckinsPage::class, 'render' ), '' ),
			array( RefundsPage::SLUG, __( 'Erstattungen', 'wp-pass-captain' ), $refund_title, RefundsPage::menu_cap(), array( RefundsPage::class, 'render' ), '' ),
			array( CampaignsPage::SLUG, __( 'Rabattcodes', 'wp-pass-captain' ), __( 'Rabattcodes', 'wp-pass-captain' ), CampaignsPage::CAP, array( CampaignsPage::class, 'render' ), '' ),
			array( DevicesPage::SLUG, __( 'Scanner-Geräte', 'wp-pass-captain' ), __( 'Scanner-Geräte', 'wp-pass-captain' ), DevicesPage::CAP, array( DevicesPage::class, 'render' ), '' ),
		);
		foreach ( \PassCaptain\Modules::admin_pages() as $page ) {
			$page = array_values( (array) $page );
			list( $slug, $title, $cap, $callback ) = $page;
			$entries[] = array( $slug, $title, $title, $cap, $callback, (string) ( $page[4] ?? '' ) );
		}
		$entries[] = array( SettingsPage::SLUG, __( 'Einstellungen', 'wp-pass-captain' ), __( 'Einstellungen', 'wp-pass-captain' ), SettingsPage::CAP, array( SettingsPage::class, 'render' ), '' );
		$entries[] = array( AuditLogPage::SLUG, __( 'Audit-Log', 'wp-pass-captain' ), __( 'Audit-Log', 'wp-pass-captain' ), AuditLogPage::CAP, array( AuditLogPage::class, 'render' ), '' );
		if ( \PassCaptain\Integrations\Vouchers\IntegrationStatus::voucher_captain_active() ) {
			// Links into Voucher Captain's own menu (both plugins keep their menus, CONCEPT 15.7).
			$entries[] = array( 'admin.php?page=vcap-scanner', __( 'Gemeinsamer Scanner', 'wp-pass-captain' ), __( 'Gemeinsamer Scanner ↗', 'wp-pass-captain' ), 'wpcap_checkin', null, '' );
			$entries[] = array( 'admin.php?page=vcap-vouchers', __( 'WP Voucher Captain', 'wp-pass-captain' ), __( 'WP Voucher Captain ↗', 'wp-pass-captain' ), 'manage_woocommerce', null, '' );
		}
		return $entries;
	}

	/**
	 * Entries ordered by area (stable within an area). A heading row precedes every area the current
	 * user sees at least one page of – except the first visible one: WordPress links the top-level
	 * item to the first submenu entry, which must stay a real page. The events overview always opens
	 * the "events" area.
	 *
	 * @param array $entries Output of entries().
	 * @return array Rows ['page', entry] and ['heading', group key, label, capability].
	 */
	public static function layout( array $entries ) {
		$groups = self::groups();
		$by_group = array_fill_keys( array_keys( $groups ), array() );
		foreach ( $entries as $entry ) {
			$group = self::SLUG === $entry[0] ? 'events' : self::group_of( $entry[0], $entry[5] ?? '' );
			$by_group[ $group ][] = $entry;
		}
		$rows = array();
		$first = true;
		foreach ( $by_group as $key => $list ) {
			if ( ! $list ) { continue; }
			$visible_cap = '';
			foreach ( $list as $entry ) {
				if ( current_user_can( $entry[3] ) ) { $visible_cap = (string) $entry[3]; break; }
			}
			if ( '' !== $visible_cap ) {
				if ( ! $first ) { $rows[] = array( 'heading', $key, $groups[ $key ], $visible_cap ); }
				$first = false;
			}
			foreach ( $list as $entry ) { $rows[] = array( 'page', $entry ); }
		}
		return $rows;
	}

	/** Turns the area headings of the menu into plain text (no dead link, no extra tab stop). */
	public static function menu_heading_script() {
		echo '<script>document.querySelectorAll("#adminmenu li.wpcap-menu-heading > a").forEach(function(a){var s=document.createElement("span");s.className="wpcap-menu-heading-label";s.textContent=a.textContent;a.parentNode.replaceChild(s,a);});</script>';
	}
}

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
	const SLUG = 'pass-captain';

	/** admin-post action => handler. */
	public static function handlers() {
		return array(
			'wpcap_create_event' => array( EventsPage::class, 'handle_create_event' ),
			self::SCHEMA_RETRY_ACTION => array( self::class, 'handle_schema_retry' ),
			self::ENTRY_WINDOW_DISMISS => array( self::class, 'handle_entry_window_dismiss' ),
			'wpcap_update_event' => array( EventsPage::class, 'handle_update_event' ),
			'wpcap_cancel_event' => array( EventsPage::class, 'handle_cancel_event' ),
			'wpcap_save_occurrence' => array( EventsPage::class, 'handle_save_occurrence' ),
			'wpcap_cancel_occurrence' => array( EventsPage::class, 'handle_cancel_occurrence' ),
			'wpcap_resume_bulk_run' => array( EventsPage::class, 'handle_resume_bulk_run' ),
			'wpcap_save_ticket_type' => array( EventsPage::class, 'handle_save_ticket_type' ),
			'wpcap_save_participant_field' => array( EventsPage::class, 'handle_save_participant_field' ),
			'wpcap_participant_field_status' => array( EventsPage::class, 'handle_participant_field_status' ),
			'wpcap_assign_staff' => array( EventsPage::class, 'handle_assign_staff' ),
			'wpcap_remove_staff' => array( EventsPage::class, 'handle_remove_staff' ),
			'wpcap_confirm_payment' => array( OrdersPage::class, 'handle_confirm_payment' ),
			'wpcap_retry_allocation' => array( OrdersPage::class, 'handle_retry_allocation' ),
			'wpcap_settle_unallocated' => array( OrdersPage::class, 'handle_settle_unallocated' ),
			'wpcap_cancel_order' => array( OrdersPage::class, 'handle_cancel_order' ),
			'wpcap_resend_tickets' => array( OrdersPage::class, 'handle_resend_tickets' ),
			'wpcap_ticket_pdf' => array( OrdersPage::class, 'handle_ticket_pdf' ),
			'wpcap_refund_order' => array( OrdersPage::class, 'handle_refund' ),
			'wpcap_approve_refund' => array( OrdersPage::class, 'handle_approve_refund' ),
			'wpcap_reject_refund' => array( OrdersPage::class, 'handle_reject_refund' ),
			'wpcap_resolve_refund' => array( OrdersPage::class, 'handle_resolve_refund' ),
			'wpcap_consent_withdraw' => array( \PassCaptain\Consents::class, 'handle_admin_withdraw' ),
			'wpcap_export_consents' => array( \PassCaptain\Consents::class, 'handle_export' ),
			'wpcap_revoke_ticket' => array( TicketsPage::class, 'handle_revoke_ticket' ),
			'wpcap_undo_checkin' => array( TicketsPage::class, 'handle_undo_checkin' ),
			'wpcap_correct_attendee' => array( TicketsPage::class, 'handle_correct_attendee' ),
			'wpcap_reissue_ticket' => array( TicketsPage::class, 'handle_reissue_ticket' ),
			'wpcap_checkin_ticket' => array( TicketsPage::class, 'handle_checkin_ticket' ),
			ScannerPage::ACTION_CREATE => array( ScannerPage::class, 'handle_create' ),
			'wpcap_save_settings' => array( SettingsPage::class, 'handle_save' ),
			SettingsPage::TEST_ACTION => array( SettingsPage::class, 'handle_test_connection' ),
			'wpcap_create_pairing' => array( DevicesPage::class, 'handle_create_pairing' ),
			'wpcap_revoke_device' => array( DevicesPage::class, 'handle_revoke_device' ),
			'wpcap_campaign_create' => array( CampaignsPage::class, 'handle_create' ),
			'wpcap_campaign_status' => array( CampaignsPage::class, 'handle_status' ),
			'wpcap_tax_profile_save' => array( TaxPage::class, 'handle_save' ),
			'wpcap_tax_profile_activate' => array( TaxPage::class, 'handle_activate' ),
			'wpcap_tax_profile_retire' => array( TaxPage::class, 'handle_retire' ),
			'wpcap_getting_started' => array( GettingStarted::class, 'handle_toggle' ),
			GettingStarted::PAGE_ACTION => array( GettingStarted::class, 'handle_create_sales_page' ),
			OperationsPage::ACTION => array( OperationsPage::class, 'handle_action' ),
			OperationsPage::DOCTOR_ACTION => array( OperationsPage::class, 'handle_doctor' ),
			'wpcap_developer_key_create' => array( DeveloperKeysPage::class, 'handle_create' ),
			'wpcap_developer_key_update' => array( DeveloperKeysPage::class, 'handle_update' ),
			'wpcap_developer_key_revoke' => array( DeveloperKeysPage::class, 'handle_revoke' ),
			'wpcap_service_resource_save' => array( ServiceSlotsPage::class, 'handle_save_resource' ),
			'wpcap_service_resource_delete' => array( ServiceSlotsPage::class, 'handle_delete_resource' ),
			'wpcap_service_slot_save' => array( ServiceSlotsPage::class, 'handle_save_slot' ),
			'wpcap_service_slot_delete' => array( ServiceSlotsPage::class, 'handle_delete_slot' ),
			'wpcap_service_slots_generate' => array( ServiceSlotsPage::class, 'handle_generate_slots' ),
			'wpcap_service_provider_save' => array( ServiceSlotsPage::class, 'handle_save_provider' ),
			'wpcap_service_resource_providers' => array( ServiceSlotsPage::class, 'handle_assign_providers' ),
			'wpcap_service_booking_action' => array( ServiceSlotsPage::class, 'handle_booking_action' ),
			MyServicesPage::STATUS_ACTION => array( MyServicesPage::class, 'handle_status' ),
			'wpcap_assignment_add' => array( AssignmentsPage::class, 'handle_add' ),
			'wpcap_assignment_remove' => array( AssignmentsPage::class, 'handle_remove' ),
			'wpcap_assignment_area_add' => array( AssignmentsPage::class, 'handle_add_area' ),
			'wpcap_assignment_area_remove' => array( AssignmentsPage::class, 'handle_remove_area' ),
		) +\PassCaptain\Modules::admin_handlers();
	}

	public static function register() {
		add_action( 'admin_menu', array( self::class, 'add_menus' ) );
		add_action( 'admin_head', array( self::class, 'admin_styles' ) );
		add_action( 'admin_footer', array( self::class, 'menu_heading_script' ) );
		add_action( 'admin_notices', array( self::class, 'schema_error_notice' ) );
		add_action( 'admin_notices', array( self::class, 'manual_payment_notice' ) );
		add_action( 'admin_notices', array( self::class, 'needs_review_notice' ) );
		add_action( 'admin_notices', array( self::class, 'entry_window_notice' ) );
		add_action( 'admin_notices', array( self::class, 'missing_payment_notice' ) );
		UserScope::register();
		foreach ( self::handlers() as $action => $handler ) {
			add_action( 'admin_post_' . $action, $handler );
		}
	}

	/** admin-post action of the "retry the database update" button (A466). */
	const SCHEMA_RETRY_ACTION = 'wpcap_schema_retry';

	/**
	 * Failed schema upgrade (Installer::missing_schema()): administrators see what is missing, how
	 * often it failed, when the next automatic attempt runs (A466) and a button to retry now.
	 */
	public static function schema_error_notice() {
		$error = get_option( 'wpcap_schema_error' );
		if ( ! is_array( $error ) || ! current_user_can( 'manage_options' ) ) { return; }
		$retry_at = \PassCaptain\Installer::retry_at();
		$attempts = max( 1, (int) ( $error['attempts'] ?? 1 ) );
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Pass Captain: Das Datenbank-Update ist unvollständig.', 'pass-captain' ) . '</strong> ' . esc_html__( 'Es fehlen Tabellen oder Spalten; das Plugin arbeitet weiter mit dem alten Schema. Bitte Datenbankrechte prüfen und das Update danach erneut starten.', 'pass-captain' ) . '</p><p><code>' . esc_html( implode( ', ', array_slice( (array) ( $error['missing'] ?? array() ), 0, 10 ) ) ) . '</code>' . ( ! empty( $error['db_error'] ) ? ' – ' . esc_html( (string) $error['db_error'] ) : '' ) . '</p><p>';
		if ( $retry_at ) {
			/* translators: 1: number of failed attempts, 2: date and time of the next automatic attempt */
			echo esc_html( sprintf( _n( '%1$d Versuch fehlgeschlagen. Nächster automatischer Versuch: %2$s.', '%1$d Versuche fehlgeschlagen. Nächster automatischer Versuch: %2$s.', $attempts, 'pass-captain' ), $attempts, wp_date( 'd.m.Y H:i', $retry_at ) ) ) . ' ';
		}
		echo '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::SCHEMA_RETRY_ACTION ) . '">';
		wp_nonce_field( self::SCHEMA_RETRY_ACTION );
		echo '<p><button type="submit" class="button">' . esc_html__( 'Datenbank-Update jetzt erneut versuchen', 'pass-captain' ) . '</button></p></form></div>';
	}

	/** admin-post: retries a failed schema upgrade right away (manage_options, nonce; A466). */
	public static function handle_schema_retry() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), '', array( 'response' => 403, 'back_link' => true ) );
		}
		Forms::verify( self::SCHEMA_RETRY_ACTION );
		\PassCaptain\Installer::allow_retry();
		\PassCaptain\Plugin::maybe_upgrade( 'admin' );
		$ok = ! is_array( get_option( 'wpcap_schema_error' ) );
		\PassCaptain\Audit::record( 'schema_upgrade_retried', 'system', 0, array( 'ok' => $ok ) );
		Forms::flash( $ok ? 'success' : 'error', $ok ? __( 'Das Datenbank-Update wurde abgeschlossen.', 'pass-captain' ) : __( 'Das Datenbank-Update ist erneut fehlgeschlagen. Details stehen im Hinweis.', 'pass-captain' ) );
		$back = wp_get_referer();
		Forms::redirect( $back ? $back : admin_url() );
	}

	/**
	 * Manual payment enabled without bank account or payment instructions (A265, e.g. an install from
	 * before 3.10.9 that kept the old default): the checkout does not offer it until it is completed.
	 * Shown on the plugin's pages and the dashboard to users who may change the settings.
	 */
	public static function manual_payment_notice() {
		if ( ! current_user_can( SettingsPage::CAP ) || ! \PassCaptain\Payments\ManualGateway::enabled_but_incomplete() ) { return; }
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only selects the screen.
		$dashboard = $screen && 'dashboard' === ( $screen->id ?? '' );
		if ( ! $dashboard && ! str_starts_with( $page, 'wpcap' ) && 'pass-captain' !== $page ) { return; }
		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Pass Captain: Die Zahlungsart „Überweisung“ ist aktiviert, aber nicht vollständig konfiguriert.', 'pass-captain' ) . '</strong> ' . esc_html__( 'Es fehlen Empfänger und IBAN bzw. ein Zahlungshinweis. Bis dahin wird Überweisung im Checkout nicht angeboten.', 'pass-captain' ) . ' <a href="' . esc_url( SettingsPage::provider_url( 'manual' ) ) . '">' . esc_html__( 'Überweisung einrichten', 'pass-captain' ) . '</a></p></div>';
	}

	/**
	 * Paid orders without tickets (`needs_review`, A337): staff who handle orders see how many are
	 * waiting for a decision, with a link to the filtered order list – on the plugin's pages and the
	 * dashboard, scoped to the events they may access. Not repeated on the filtered list itself.
	 */
	public static function needs_review_notice() {
		if ( ! current_user_can( OrdersPage::CAP ) ) { return; }
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- only selects the screen.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$dashboard = $screen && 'dashboard' === ( $screen->id ?? '' );
		if ( ! $dashboard && ! str_starts_with( $page, 'wpcap' ) && self::SLUG !== $page ) { return; }
		if ( OrdersPage::SLUG === $page && 'needs_review' === $status ) { return; }
		$count = Queries::needs_review_count( Queries::event_ids_for( OrdersPage::CAP ) );
		if ( ! $count ) { return; }
		/* translators: %d: number of orders */
		$text = sprintf( _n( 'Pass Captain: %d bezahlte Bestellung ohne Tickets muss geklärt werden.', 'Pass Captain: %d bezahlte Bestellungen ohne Tickets müssen geklärt werden.', $count, 'pass-captain' ), $count );
		echo '<div class="notice notice-warning wpcap-needs-review-notice"><p><strong>' . esc_html( $text ) . '</strong> ' . esc_html__( 'Die Zahlung kam nach Ablauf der Reservierung und es war kein Kontingent mehr frei. Bitte Platz freigeben und die Zuteilung erneut versuchen oder die Zahlung erstatten.', 'pass-captain' ) . ' <a href="' . esc_url( self::needs_review_url() ) . '">' . esc_html__( 'Bestellungen anzeigen', 'pass-captain' ) . '</a></p></div>';
	}

	/** Order list filtered to orders that need a decision (A337). */
	public static function needs_review_url() {
		return Forms::page_url( OrdersPage::SLUG, array( 'status' => 'needs_review' ) );
	}

	/** admin-post action that hides the entry-window notice (A465). */
	const ENTRY_WINDOW_DISMISS = 'wpcap_entry_window_dismiss';

	/**
	 * A465: the upgrade kept the default entry window off for an existing install (Installer::
	 * pin_legacy_entry_window()). Tells administrators that the option exists and how scans behave,
	 * until they dismiss it or change the setting. Plugin pages and the dashboard only.
	 */
	public static function entry_window_notice() {
		if ( ! get_option( \PassCaptain\Installer::ENTRY_WINDOW_NOTICE ) || ! current_user_can( SettingsPage::CAP ) ) { return; }
		if ( \PassCaptain\Settings::get( 'default_entry_window' ) ) {
			delete_option( \PassCaptain\Installer::ENTRY_WINDOW_NOTICE ); // The operator switched it on.
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only selects the screen.
		$dashboard = $screen && 'dashboard' === ( $screen->id ?? '' );
		if ( ! $dashboard && ! str_starts_with( $page, 'wpcap' ) && 'pass-captain' !== $page ) { return; }
		echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'Pass Captain: Neues Standard-Einlassfenster', 'pass-captain' ) . '</strong> ' . esc_html__( 'Neue Installationen lassen Tickets ohne eigene Einlassregel nur von 2 Stunden vor Beginn bis zum Ende des Termins ein. Für diese bestehende Installation wurde das Einlassfenster beim Update ausgeschaltet, Scans verhalten sich also wie bisher. Es lässt sich unter Einstellungen → Einlass / Scanner-Geräte einschalten.', 'pass-captain' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::ENTRY_WINDOW_DISMISS ) . '">';
		wp_nonce_field( self::ENTRY_WINDOW_DISMISS );
		echo '<p><a class="button" href="' . esc_url( Forms::page_url( SettingsPage::SLUG, SettingsPage::url_args( 'admission' ) ) ) . '">' . esc_html__( 'Einlass-Einstellungen öffnen', 'pass-captain' ) . '</a> <button type="submit" class="button-link">' . esc_html__( 'Hinweis ausblenden', 'pass-captain' ) . '</button></p></form></div>';
	}

	/** admin-post: hides the entry-window notice (A465). */
	public static function handle_entry_window_dismiss() {
		if ( ! current_user_can( SettingsPage::CAP ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), '', array( 'response' => 403, 'back_link' => true ) );
		}
		Forms::verify( self::ENTRY_WINDOW_DISMISS );
		delete_option( \PassCaptain\Installer::ENTRY_WINDOW_NOTICE );
		$back = wp_get_referer();
		Forms::redirect( $back ? $back : admin_url() );
	}

	/**
	 * Currencies of bookable dates (published event, upcoming scheduled date, active ticket type)
	 * for which no payment method is available (A294): their cards cannot be ordered.
	 *
	 * @return string[]
	 */
	public static function currencies_without_payment() {
		global $wpdb;
		$p = $wpdb->prefix;
		$currencies = (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT tt.currency FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE e.status='published' AND o.status='scheduled' AND o.starts_at >= %s AND tt.status='active'", current_time( 'mysql', true ) ) );
		$missing = array();
		foreach ( $currencies as $currency ) {
			$currency = strtoupper( (string) $currency );
			if ( '' !== $currency && ! \PassCaptain\Payments\Registry::enabled( $currency ) ) { $missing[] = $currency; }
		}
		sort( $missing );
		return array_values( array_unique( $missing ) );
	}

	/**
	 * Admin notice (dashboard and plugin pages, administrators): published events exist but no
	 * payment method is available for their currency (A294). Shops that sell through linked
	 * WooCommerce products are not warned (their checkout is WooCommerce's).
	 */
	public static function missing_payment_notice() {
		if ( ! current_user_can( SettingsPage::CAP ) ) { return; }
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only selects the screen.
		$dashboard = $screen && 'dashboard' === ( $screen->id ?? '' );
		if ( ! $dashboard && ! str_starts_with( $page, 'wpcap' ) && 'pass-captain' !== $page ) { return; }
		$missing = self::currencies_without_payment();
		if ( ! $missing || \PassCaptain\Integrations\WooCommerce\OrderAdapter::has_linked_ticket_products() ) { return; }
		$url = \PassCaptain\Payments\ManualGateway::enabled_but_incomplete() ? SettingsPage::provider_url( 'manual' ) : Forms::page_url( SettingsPage::SLUG, array( 'tab' => 'payments' ) );
		/* translators: %s: currency codes */
		$title = sprintf( __( 'Pass Captain: Keine verfügbare Zahlungsart für %s.', 'pass-captain' ), implode( ', ', $missing ) );
		echo '<div class="notice notice-error"><p><strong>' . esc_html( $title ) . '</strong> ' . esc_html__( 'Veröffentlichte Events können auf der Website nicht gebucht werden; die Eventkarten zeigen „Online-Verkauf derzeit nicht möglich“.', 'pass-captain' ) . ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Zahlungsart einrichten', 'pass-captain' ) . '</a></p></div>';
	}

	/** Area headings of the menu (every admin page); small accessibility fixes on plugin pages: readable code in links (contrast), delete links. */
	public static function admin_styles() {
		echo '<style>#adminmenu li.wpcap-menu-heading{margin-top:6px;padding-top:4px;border-top:1px solid rgba(240,246,252,.15)}#adminmenu li.wpcap-menu-heading a,#adminmenu li.wpcap-menu-heading .wpcap-menu-heading-label{display:block;padding:5px 12px 1px;font-size:11px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:#c3c4c7;pointer-events:none;cursor:default}</style>';
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only selects CSS.
		if ( ! str_starts_with( $page, 'wpcap' ) && 'pass-captain' !== $page ) { return; }
		echo '<style>#wpbody-content a code{color:inherit;background:transparent}#wpbody-content .button-link-delete{color:#b32d2e}#wpbody-content .wpcap-admin details>summary{min-height:24px;padding:2px 0;line-height:24px;cursor:pointer}#wpbody-content .wpcap-admin .button-link{min-height:24px;min-width:24px;padding:2px 0;line-height:20px}</style>';
	}

	/** First capability of the list the current user holds (fallback: the first one). */
	private static function first_cap( array $caps ) {
		foreach ( $caps as $cap ) {
			if ( current_user_can( $cap ) ) { return $cap; }
		}
		return $caps[0];
	}

	public static function add_menus() {
		$top_cap = self::first_cap( array( EventsPage::CAP, OrdersPage::CAP, 'wpcap_checkin', 'wpcap_view_reports', Refunds::APPROVE_CAP, SettingsPage::CAP, MyServicesPage::CAP ) );
		add_menu_page(
			__( 'Pass Captain', 'pass-captain' ),
			__( 'Pass Captain', 'pass-captain' ),
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
			'events'        => __( 'Events & Programm', 'pass-captain' ),
			'sales'         => __( 'Verkauf', 'pass-captain' ),
			'admission'     => __( 'Einlass', 'pass-captain' ),
			'finance'       => __( 'Finanzen & Auswertung', 'pass-captain' ),
			'communication' => __( 'Kommunikation', 'pass-captain' ),
			'settings'      => __( 'Einstellungen & Integrationen', 'pass-captain' ),
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
			ServiceSlotsPage::SLUG         => 'events',
			MyServicesPage::SLUG           => 'events',
			AssignmentsPage::SLUG          => 'events',
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
			ScannerPage::SLUG              => 'admission',
			DevicesPage::SLUG              => 'admission',
			'wpcap-offline-scanner'        => 'admission',
			'wpcap-admission'              => 'admission',
			'wpcap-admission-conflicts'    => 'admission',
			'admin.php?page=vcap-scanner'  => 'admission',
			RefundsPage::SLUG              => 'finance',
			'wpcap-reports'                => 'finance',
			'wpcap-partner-settlements'    => 'finance',
			TaxPage::SLUG                  => 'finance',
			'wpcap-mail-templates'         => 'communication',
			'wpcap-reminders'              => 'communication',
			'wpcap-sms'                    => 'communication',
			SettingsPage::SLUG             => 'settings',
			AuditLogPage::SLUG             => 'settings',
			OperationsPage::SLUG           => 'settings',
			'wpcap-mollie'                 => 'settings',
			'wpcap-wallet'                 => 'settings',
			'wpcap-webhooks'               => 'settings',
			DeveloperKeysPage::SLUG        => 'settings',
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
		$refund_title = __( 'Erstattungen', 'pass-captain' ) . ( $pending ? ' <span class="awaiting-mod">' . (int) $pending . '</span>' : '' );
		// A337: paid orders without tickets waiting for a decision.
		$review = current_user_can( OrdersPage::CAP ) ? Queries::needs_review_count( Queries::event_ids_for( OrdersPage::CAP ) ) : 0;
		/* translators: %d: number of orders */
		$review_label = $review ? sprintf( _n( '%d Bestellung muss geklärt werden', '%d Bestellungen müssen geklärt werden', $review, 'pass-captain' ), $review ) : '';
		$orders_title = __( 'Bestellungen', 'pass-captain' ) . ( $review ? ' <span class="awaiting-mod" title="' . esc_attr( $review_label ) . '"><span aria-hidden="true">' . (int) $review . '</span><span class="screen-reader-text">' . esc_html( $review_label ) . '</span></span>' : '' );
		$entries = array(
			array( self::SLUG, __( 'Events', 'pass-captain' ), __( 'Events', 'pass-captain' ), EventsPage::CAP, array( EventsPage::class, 'render' ), '' ),
			array( OrdersPage::SLUG, __( 'Bestellungen', 'pass-captain' ), $orders_title, OrdersPage::CAP, array( OrdersPage::class, 'render' ), '' ),
			array( TicketsPage::SLUG, __( 'Tickets', 'pass-captain' ), __( 'Tickets', 'pass-captain' ), TicketsPage::menu_cap(), array( TicketsPage::class, 'render' ), '' ),
			array( CheckinsPage::SLUG, __( 'Check-ins', 'pass-captain' ), __( 'Check-ins', 'pass-captain' ), CheckinsPage::menu_cap(), array( CheckinsPage::class, 'render' ), '' ),
			array( RefundsPage::SLUG, __( 'Erstattungen', 'pass-captain' ), $refund_title, RefundsPage::menu_cap(), array( RefundsPage::class, 'render' ), '' ),
			array( CampaignsPage::SLUG, __( 'Rabattcodes', 'pass-captain' ), __( 'Rabattcodes', 'pass-captain' ), CampaignsPage::CAP, array( CampaignsPage::class, 'render' ), '' ),
			array( ScannerPage::SLUG, __( 'Web-Scanner', 'pass-captain' ), __( 'Web-Scanner', 'pass-captain' ), ScannerPage::CAP, array( ScannerPage::class, 'render' ), '' ),
			array( DevicesPage::SLUG, __( 'Scanner-Geräte', 'pass-captain' ), __( 'Scanner-Geräte', 'pass-captain' ), DevicesPage::CAP, array( DevicesPage::class, 'render' ), '' ),
			array( TaxPage::SLUG, __( 'Steuerprüfung', 'pass-captain' ), __( 'Steuerprüfung', 'pass-captain' ), TaxPage::CAP, array( TaxPage::class, 'render' ), '' ),
			array( ServiceSlotsPage::SLUG, __( 'Service-Zeitfenster', 'pass-captain' ), __( 'Service-Zeitfenster', 'pass-captain' ), ServiceSlotsPage::CAP, array( ServiceSlotsPage::class, 'render' ), '' ),
			array( MyServicesPage::SLUG, __( 'Meine Leistungen', 'pass-captain' ), __( 'Meine Leistungen', 'pass-captain' ), MyServicesPage::CAP, array( MyServicesPage::class, 'render' ), '' ),
			array( AssignmentsPage::SLUG, __( 'Zuweisungen', 'pass-captain' ), __( 'Zuweisungen', 'pass-captain' ), AssignmentsPage::CAP, array( AssignmentsPage::class, 'render' ), '' ),
		);
		foreach ( \PassCaptain\Modules::admin_pages() as $page ) {
			$page = array_values( (array) $page );
			list( $slug, $title, $cap, $callback ) = $page;
			$entries[] = array( $slug, $title, $title, $cap, $callback, (string) ( $page[4] ?? '' ) );
		}
		$entries[] = array( SettingsPage::SLUG, __( 'Einstellungen', 'pass-captain' ), __( 'Einstellungen', 'pass-captain' ), SettingsPage::CAP, array( SettingsPage::class, 'render' ), '' );
		$entries[] = array( DeveloperKeysPage::SLUG, __( 'Entwickler-API', 'pass-captain' ), __( 'Entwickler-API', 'pass-captain' ), DeveloperKeysPage::CAP, array( DeveloperKeysPage::class, 'render' ), '' );
		$entries[] = array( AuditLogPage::SLUG, __( 'Audit-Log', 'pass-captain' ), __( 'Audit-Log', 'pass-captain' ), AuditLogPage::CAP, array( AuditLogPage::class, 'render' ), '' );
		$entries[] = array( OperationsPage::SLUG, __( 'Betrieb', 'pass-captain' ), __( 'Betrieb', 'pass-captain' ), OperationsPage::CAP, array( OperationsPage::class, 'render' ), '' );
		if ( \PassCaptain\Integrations\Vouchers\IntegrationStatus::voucher_captain_active() ) {
			// Links into Voucher Captain's own menu (both plugins keep their menus, CONCEPT 15.7).
			$entries[] = array( 'admin.php?page=vcap-scanner', __( 'Gemeinsamer Scanner', 'pass-captain' ), __( 'Gemeinsamer Scanner ↗', 'pass-captain' ), 'wpcap_checkin', null, '' );
			$entries[] = array( 'admin.php?page=vcap-vouchers', __( 'Voucher Captain', 'pass-captain' ), __( 'Voucher Captain ↗', 'pass-captain' ), 'manage_woocommerce', null, '' );
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

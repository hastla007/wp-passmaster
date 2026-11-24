<?php
/**
 * "Erste Schritte" checklist on the events overview (the plugin's start page). Every step is
 * derived from the stored data (settings, events, ticket types, devices, orders) – nothing is
 * ticked by hand. Administrators can hide the box per user and show it again.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Localization;
use PassCaptain\Payments\Registry;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class GettingStarted {
	/** User meta: 1 = checklist hidden by this user. */
	const META = 'wpcap_getting_started_hidden';
	/** Only site administrators see the checklist (payments, legal texts and seller data are theirs). */
	const CAP = 'manage_options';
	const ACTION = 'wpcap_getting_started';
	/** admin-post action: creates a draft page with the events shortcode (A292). */
	const PAGE_ACTION = 'wpcap_create_sales_page';
	/** Shortcode of the sales page (events with ticket selection and checkout). */
	const SALES_SHORTCODE = '[wpcap_events]';
	/** Transient caching the published pages with the sales/scanner shortcode or block. */
	const PAGES_CACHE = 'wpcap_getting_started_pages';

	/**
	 * Current state of the installation: payment setup, legal/seller texts and counts from one query.
	 *
	 * @return array
	 */
	public static function state() {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = \PassCaptain\Clock::mysql();
		$counts = $wpdb->get_row( $wpdb->prepare(
			"SELECT
				(SELECT COUNT(*) FROM {$p}wpcap_events) AS events,
				(SELECT COUNT(*) FROM {$p}wpcap_events WHERE status='published') AS published_events,
				(SELECT COUNT(*) FROM {$p}wpcap_occurrences WHERE status='scheduled' AND starts_at >= %s) AS upcoming_occurrences,
				(SELECT COUNT(*) FROM {$p}wpcap_ticket_types WHERE status='active') AS ticket_types,
				(SELECT COUNT(*) FROM {$p}wpcap_scanner_devices) AS devices,
				(SELECT COUNT(*) FROM {$p}wpcap_orders WHERE payment_status='paid') AS paid_orders",
			$now
		) );
		$counts = $counts ? (array) $counts : array();
		$state = array(
			'payment_online'      => self::online_payment_ready(),
			'payment_manual'      => self::manual_payment_ready(),
			'manual_incomplete'   => \PassCaptain\Payments\ManualGateway::enabled_but_incomplete(),
			// A291: only WooCommerce products linked to a ticket type sell tickets.
			'woocommerce_active'  => class_exists( 'WooCommerce' ),
			'woocommerce'         => \PassCaptain\Integrations\WooCommerce\OrderAdapter::has_linked_ticket_products(),
			'terms_text'          => '' !== trim( wp_strip_all_tags( (string) Settings::get( 'terms_text' ) ) ),
			'consumer_info_text'  => '' !== trim( wp_strip_all_tags( (string) Settings::get( 'consumer_info_text' ) ) ),
			// A289: the terms are shown (and accepted) in the checkout only with require_terms.
			'require_terms'       => (bool) Settings::get( 'require_terms' ),
			'seller'              => '' !== trim( (string) Settings::get( 'seller_name' ) ) && '' !== trim( (string) Settings::get( 'seller_address' ) ),
		);
		// SET-PC-31: the country counts as chosen once seller_country was stored (screen, preset or import).
		$stored = get_option( Settings::OPTION, array() );
		$state['country'] = is_array( $stored ) && array_key_exists( 'seller_country', $stored );
		$state['legal_texts'] = $state['terms_text'] && $state['consumer_info_text'];
		$state += self::frontend_pages();
		foreach ( array( 'events', 'published_events', 'upcoming_occurrences', 'ticket_types', 'devices', 'paid_orders' ) as $key ) {
			$state[ $key ] = (int) ( $counts[ $key ] ?? 0 );
		}
		return $state;
	}

	/**
	 * Published pages/posts that sell tickets (events shortcode or block, also the calendar) and
	 * that hold the web scanner (A292). Cached for ten minutes; creating a page drops the cache.
	 *
	 * @return array sales_page_id, scanner_page_id (0 = none).
	 */
	public static function frontend_pages() {
		global $wpdb;
		$none = array( 'sales_page_id' => 0, 'scanner_page_id' => 0 );
		if ( empty( $wpdb->posts ) ) { return $none; }
		$cached = get_transient( self::PAGES_CACHE );
		if ( is_array( $cached ) && isset( $cached['sales_page_id'], $cached['scanner_page_id'] ) ) { return array_map( 'intval', $cached ); }
		$like = static function ( $needle ) use ( $wpdb ) { return '%' . $wpdb->esc_like( $needle ) . '%'; };
		$sales = array( '[wpcap_events', '[wp_pass_captain_events', '<!-- wp:pass-captain/events', '<!-- wp:wp-pass-captain/events', '[wpcap_calendar', '[wp_pass_captain_calendar', '<!-- wp:pass-captain/calendar', '<!-- wp:wp-pass-captain/calendar' );
		$scanner = array( '[wpcap_scanner', '[wp_pass_captain_scanner' );
		$sales_sql = implode( ' OR ', array_fill( 0, count( $sales ), 'post_content LIKE %s' ) );
		$scanner_sql = implode( ' OR ', array_fill( 0, count( $scanner ), 'post_content LIKE %s' ) );
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT MIN(CASE WHEN {$sales_sql} THEN ID END) AS sales_page_id, MIN(CASE WHEN {$scanner_sql} THEN ID END) AS scanner_page_id FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ('page','post')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholder lists built above.
			...array_merge( array_map( $like, $sales ), array_map( $like, $scanner ) )
		) );
		$pages = array(
			'sales_page_id' => $row ? (int) $row->sales_page_id : 0,
			'scanner_page_id' => $row ? (int) $row->scanner_page_id : 0,
		);
		set_transient( self::PAGES_CACHE, $pages, 10 * MINUTE_IN_SECONDS );
		return $pages;
	}

	/** An enabled online gateway (not "manual") with complete credentials for one of the shop currencies. */
	private static function online_payment_ready() {
		$enabled = (array) Settings::get( 'enabled_providers' );
		foreach ( Registry::all() as $id => $gateway ) {
			if ( 'manual' === $id || ! in_array( $id, $enabled, true ) ) { continue; }
			foreach ( Localization::currencies() as $currency ) {
				if ( $gateway->is_available( $currency ) ) { return true; }
			}
		}
		return false;
	}

	/** Manual payment (bank transfer) enabled with a bank account (IBAN + recipient) or payment instructions. */
	private static function manual_payment_ready() {
		if ( ! in_array( 'manual', (array) Settings::get( 'enabled_providers' ), true ) ) { return false; }
		return \PassCaptain\Payments\ManualGateway::configured();
	}

	/**
	 * Checklist steps derived from a state (see state()).
	 *
	 * @param array $state State values.
	 * @return array[] Steps: key, label, description, done, url, action (link text of an open step).
	 */
	public static function items( array $state ) {
		$events = Forms::page_url( EventsPage::SLUG );
		$payment_done = ! empty( $state['payment_online'] ) || ! empty( $state['payment_manual'] ) || ! empty( $state['woocommerce'] );
		if ( ! empty( $state['payment_online'] ) ) {
			$payment_note = __( 'Eine Online-Zahlungsart ist aktiv und vollständig eingerichtet.', 'pass-captain' );
		} elseif ( ! empty( $state['payment_manual'] ) ) {
			$payment_note = __( 'Überweisung ist mit Bankverbindung bzw. Zahlungshinweis eingerichtet.', 'pass-captain' );
		} elseif ( ! empty( $state['woocommerce'] ) ) {
			$payment_note = __( 'Die Zahlung läuft über den WooCommerce-Checkout (Produkte mit Ticketart verknüpft).', 'pass-captain' );
		} elseif ( ! empty( $state['woocommerce_active'] ) ) {
			$payment_note = __( 'WooCommerce ist aktiv, aber noch kein veröffentlichtes Produkt ist mit einer Ticketart verknüpft (Produkt bearbeiten → „Pass Captain Ticketart“). Alternativ eine eigene Zahlungsart einrichten.', 'pass-captain' );
		} else {
			$payment_note = __( 'Stripe, PayPal oder Mollie mit Zugangsdaten aktivieren oder für Überweisung die IBAN hinterlegen.', 'pass-captain' );
		}
		$items = array(
			// SET-PC-31: guided order country → seller → payment → legal texts → first event.
			array(
				'key' => 'country',
				'label' => __( 'Land wählen', 'pass-captain' ),
				'description' => __( 'Ein Länderprofil schlägt passende Werte für Fristen, Hinweise und Vorwahlen vor; du übernimmst nur, was du auswählst.', 'pass-captain' ),
				'done' => ! empty( $state['country'] ),
				'url' => SettingsTools::url( array( 'view' => 'preset' ) ),
			),
			array(
				'key' => 'seller',
				'label' => __( 'Rechnungssteller angeben', 'pass-captain' ),
				'description' => __( 'Name und Anschrift für Rechnungen und Belege.', 'pass-captain' ),
				'done' => ! empty( $state['seller'] ),
				'url' => self::settings_url( 'invoices', '', 'seller' ),
			),
			array(
				'key' => 'payment',
				'label' => __( 'Zahlungsart einrichten', 'pass-captain' ),
				'description' => $payment_note,
				'done' => $payment_done,
				// An enabled but incomplete bank transfer is completed in its own section (A290).
				'url' => self::settings_url( 'payments', ! empty( $state['manual_incomplete'] ) ? 'manual' : '' ),
			),
			array(
				'key' => 'legal',
				'label' => __( 'Rechtstexte hinterlegen', 'pass-captain' ),
				'description' => self::legal_note( $state ),
				// A289: done only when the buyer sees and accepts the terms in the checkout.
				'done' => ! empty( $state['legal_texts'] ) && ! empty( $state['require_terms'] ),
				'url' => self::settings_url( 'legal' ),
			),
			array(
				'key' => 'event',
				'label' => __( 'Erstes Event anlegen', 'pass-captain' ),
				'description' => __( 'Ein Event mit erstem Termin und Ticketart lässt sich in einem Schritt anlegen.', 'pass-captain' ),
				'done' => ! empty( $state['events'] ),
				'url' => $events . '#wpcap-create-event',
			),
			array(
				'key' => 'occurrence',
				'label' => __( 'Kommenden Termin planen', 'pass-captain' ),
				'description' => __( 'Mindestens ein geplanter Termin in der Zukunft.', 'pass-captain' ),
				'done' => ! empty( $state['upcoming_occurrences'] ),
				'url' => $events,
			),
			array(
				'key' => 'ticket_type',
				'label' => __( 'Ticketart anlegen', 'pass-captain' ),
				'description' => __( 'Mindestens eine aktive Ticketart mit Preis und Kontingent.', 'pass-captain' ),
				'done' => ! empty( $state['ticket_types'] ),
				'url' => $events,
			),
			array(
				'key' => 'publish',
				'label' => __( 'Event veröffentlichen', 'pass-captain' ),
				'description' => __( 'Erst veröffentlichte Events sind buchbar.', 'pass-captain' ),
				'done' => ! empty( $state['published_events'] ),
				'url' => $events,
			),
			array(
				'key' => 'sales_page',
				'label' => __( 'Verkaufsseite anlegen', 'pass-captain' ),
				/* translators: %s: shortcode */
				'description' => sprintf( __( 'Eine veröffentlichte Seite mit dem Shortcode %s oder dem Block „Pass Captain Events“ – dort wählen Besucher Tickets und bezahlen.', 'pass-captain' ), self::SALES_SHORTCODE ),
				'done' => ! empty( $state['sales_page_id'] ),
				'url' => '',
				'view_url' => ! empty( $state['sales_page_id'] ) && function_exists( 'get_permalink' ) ? (string) get_permalink( (int) $state['sales_page_id'] ) : '',
			),
			array(
				'key' => 'scanner',
				'label' => __( 'Einlass vorbereiten', 'pass-captain' ),
				'description' => __( 'Ein Smartphone mit der Scanner-App koppeln oder den Web-Scanner nutzen: Shortcode [wpcap_scanner] auf einer Seite für das Einlasspersonal bzw. den Offline-Scanner als installierbare Web-App (Links unter „Scanner-Geräte“).', 'pass-captain' ),
				'done' => ! empty( $state['devices'] ) || ! empty( $state['scanner_page_id'] ),
				'url' => Forms::page_url( DevicesPage::SLUG ),
			),
			array(
				'key' => 'test_order',
				'label' => __( 'Testbestellung durchführen', 'pass-captain' ),
				'description' => __( 'Eine Bestellung bis zur Zahlung durchspielen und Ticket-E-Mail prüfen.', 'pass-captain' ),
				'done' => ! empty( $state['paid_orders'] ),
				'url' => Forms::page_url( OrdersPage::SLUG ),
			),
		);
		/**
		 * Steps of the "Erste Schritte" checklist; modules add or adjust steps (derive "done" from data).
		 *
		 * @param array[] $items Steps (key, label, description, done, url; optional view_url: "Auf Website ansehen" link of a done step).
		 * @param array   $state Result of GettingStarted::state().
		 */
		$items = (array) apply_filters( 'wpcap_getting_started_items', $items, $state );
		$out = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['key'] ) || empty( $item['label'] ) ) { continue; }
			$out[] = array(
				'key' => sanitize_key( (string) $item['key'] ),
				'label' => (string) $item['label'],
				'description' => (string) ( $item['description'] ?? '' ),
				'done' => ! empty( $item['done'] ),
				'url' => (string) ( $item['url'] ?? '' ),
				'view_url' => (string) ( $item['view_url'] ?? '' ),
			);
		}
		return $out;
	}

	/** Description of the legal step: what is still missing for the checkout (A289). */
	private static function legal_note( array $state ) {
		$terms = ! empty( $state['terms_text'] ) || ! empty( $state['legal_texts'] );
		if ( ! empty( $state['require_terms'] ) && ! $terms ) {
			return __( 'Die Zustimmung im Checkout ist aktiv, aber es ist kein AGB-Text hinterlegt: Käufer bestätigen Bedingungen, die sie nicht sehen. AGB/Teilnahmebedingungen eintragen.', 'pass-captain' );
		}
		if ( $terms && empty( $state['require_terms'] ) ) {
			return __( 'Die AGB sind hinterlegt, werden im Checkout aber erst angezeigt, wenn „Zustimmung im Checkout verlangen“ aktiv ist.', 'pass-captain' );
		}
		return __( 'AGB/Teilnahmebedingungen und Verbraucherinformationen (Widerrufshinweis) hinterlegen und „Zustimmung im Checkout verlangen“ aktivieren.', 'pass-captain' );
	}

	/**
	 * Settings URL that opens the tab (and payments section) of a step and jumps to its heading
	 * (A290: without ?tab= the screen opens "Allgemein", where the anchor does not exist).
	 *
	 * @param string $tab     Settings tab (payments, legal, invoices, …).
	 * @param string $section Payments section (provider) or ''.
	 * @param string $anchor  Section id of the heading; default: the section, else the tab.
	 */
	public static function settings_url( $tab, $section = '', $anchor = '' ) {
		$args = SettingsPage::url_args( $tab, $section );
		$anchor = '' !== $anchor ? $anchor : ( $args['section'] ?? $tab );
		return Forms::page_url( SettingsPage::SLUG, $args ) . '#wpcap-settings-' . $anchor;
	}

	/** Whether the current user hid the checklist. */
	public static function hidden() {
		return (bool) get_user_meta( get_current_user_id(), self::META, true );
	}

	/** Prints the checklist (or the "show again" button) for administrators. */
	public static function render() {
		if ( ! current_user_can( self::CAP ) ) { return; }
		$items = self::items( self::state() );
		if ( ! $items ) { return; }
		$done = count( array_filter( $items, static function ( $item ) { return $item['done']; } ) );
		$total = count( $items );
		if ( self::hidden() ) {
			if ( $done < $total ) {
				echo '<div class="wpcap-getting-started-restore">';
				self::toggle_form( 'show', __( 'Erste-Schritte-Checkliste anzeigen', 'pass-captain' ) );
				echo '</div>';
			}
			return;
		}
		echo '<section class="wpcap-getting-started card" aria-labelledby="wpcap-getting-started-title" style="max-width:none;margin:16px 0;padding:12px 20px">';
		echo '<h2 id="wpcap-getting-started-title">' . esc_html__( 'Erste Schritte', 'pass-captain' ) . '</h2>';
		/* translators: 1: completed steps, 2: all steps */
		$progress = sprintf( __( '%1$d von %2$d Schritten erledigt', 'pass-captain' ), $done, $total );
		$view = '';
		foreach ( $items as $item ) {
			if ( 'sales_page' === $item['key'] ) { $view = $item['view_url']; }
		}
		echo '<p id="wpcap-getting-started-progress">' . esc_html( $progress ) . ( $done === $total ? ' – ' . esc_html__( 'alles bereit für den Verkauf.', 'pass-captain' ) . ( '' !== $view ? ' <a href="' . esc_url( $view ) . '">' . esc_html__( 'Verkaufsseite ansehen', 'pass-captain' ) . '</a>' : '' ) : '' ) . '</p>';
		echo '<progress max="' . (int) $total . '" value="' . (int) $done . '" aria-labelledby="wpcap-getting-started-progress" style="width:100%;max-width:420px"></progress>';
		echo '<ol class="wpcap-getting-started-list" style="list-style:none;margin:12px 0 0;padding:0">';
		foreach ( $items as $item ) {
			$icon = $item['done'] ? 'dashicons-yes-alt' : 'dashicons-marker';
			$color = $item['done'] ? '#00a32a' : '#646970';
			echo '<li class="wpcap-gs-' . esc_attr( $item['key'] ) . ( $item['done'] ? ' is-done' : ' is-open' ) . '" style="display:flex;gap:8px;align-items:flex-start;margin:0 0 8px">';
			echo '<span class="dashicons ' . esc_attr( $icon ) . '" style="color:' . esc_attr( $color ) . '" aria-hidden="true"></span><span>';
			echo '<span class="screen-reader-text">' . esc_html( $item['done'] ? __( 'Erledigt:', 'pass-captain' ) : __( 'Offen:', 'pass-captain' ) ) . ' </span>';
			if ( ! $item['done'] && '' !== $item['url'] ) {
				echo '<strong><a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['label'] ) . '</a></strong>';
			} else {
				echo '<strong>' . esc_html( $item['label'] ) . '</strong>';
			}
			if ( $item['done'] && '' !== $item['view_url'] ) {
				echo ' <a href="' . esc_url( $item['view_url'] ) . '">' . esc_html__( 'Auf Website ansehen', 'pass-captain' ) . '</a>';
			}
			if ( '' !== $item['description'] ) { echo '<br><span class="description">' . esc_html( $item['description'] ) . '</span>'; }
			if ( 'sales_page' === $item['key'] && ! $item['done'] ) {
				Forms::open_form( self::PAGE_ACTION, null, 'style="margin:4px 0 0"' );
				echo '<button type="submit" class="button button-secondary">' . esc_html__( 'Verkaufsseite als Entwurf anlegen', 'pass-captain' ) . '</button> ';
				/* translators: %s: shortcode */
				echo '<span class="description">' . esc_html( sprintf( __( 'oder %s in eine bestehende Seite einfügen', 'pass-captain' ), self::SALES_SHORTCODE ) ) . '</span></form>';
			}
			echo '</span></li>';
		}
		echo '</ol>';
		self::toggle_form( 'hide', __( 'Checkliste ausblenden', 'pass-captain' ) );
		echo '</section>';
	}

	private static function toggle_form( $mode, $label ) {
		Forms::open_form( self::ACTION );
		Forms::hidden( 'mode', $mode );
		echo '<p><button type="submit" class="button button-link">' . esc_html( $label ) . '</button></p></form>';
	}

	/**
	 * Hides or shows the checklist for the current user.
	 *
	 * @param array $in mode: hide|show.
	 * @return true|\WP_Error
	 */
	public static function toggle( array $in ) {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		$mode = Forms::choice( $in, 'mode', array( 'hide', 'show' ), 'hide' );
		update_user_meta( get_current_user_id(), self::META, 'hide' === $mode ? 1 : 0 );
		return true;
	}

	/**
	 * Creates a draft page "Tickets" with the events shortcode (A292). A draft, so the administrator
	 * checks title and position in the menu before it goes live.
	 *
	 * @return array|\WP_Error page_id, edit_url.
	 */
	public static function create_sales_page() {
		if ( ! current_user_can( self::CAP ) || ! current_user_can( 'publish_pages' ) ) { return Forms::forbidden(); }
		$page_id = wp_insert_post(
			array(
				'post_type' => 'page',
				'post_status' => 'draft',
				'post_title' => __( 'Tickets', 'pass-captain' ),
				'post_content' => "<!-- wp:shortcode -->\n" . self::SALES_SHORTCODE . "\n<!-- /wp:shortcode -->",
			),
			true
		);
		if ( is_wp_error( $page_id ) || ! $page_id ) {
			return Forms::error( 'wpcap_sales_page', __( 'Die Seite konnte nicht angelegt werden.', 'pass-captain' ), 500 );
		}
		delete_transient( self::PAGES_CACHE );
		$edit = function_exists( 'get_edit_post_link' ) ? (string) get_edit_post_link( (int) $page_id, 'raw' ) : '';
		return array( 'page_id' => (int) $page_id, 'edit_url' => '' !== $edit ? $edit : admin_url( 'post.php?post=' . (int) $page_id . '&action=edit' ) );
	}

	/** admin-post handler: creates the draft and opens it in the editor. */
	public static function handle_create_sales_page() {
		Forms::verify( self::PAGE_ACTION );
		$result = self::create_sales_page();
		Forms::finish( $result, __( 'Seite „Tickets“ als Entwurf angelegt. Prüfen und veröffentlichen – danach ist der Schritt „Verkaufsseite anlegen“ erledigt.', 'pass-captain' ), is_array( $result ) ? $result['edit_url'] : '', Forms::page_url( EventsPage::SLUG ) );
	}

	/** admin-post handler. */
	public static function handle_toggle() {
		Forms::verify( self::ACTION );
		Forms::finish( self::toggle( Forms::post() ), '', Forms::page_url( EventsPage::SLUG ) );
	}
}

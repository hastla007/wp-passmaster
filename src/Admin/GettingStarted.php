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

final class GettingStarted {
	/** User meta: 1 = checklist hidden by this user. */
	const META = 'wpcap_getting_started_hidden';
	/** Only site administrators see the checklist (payments, legal texts and seller data are theirs). */
	const CAP = 'manage_options';
	const ACTION = 'wpcap_getting_started';

	/**
	 * Current state of the installation: payment setup, legal/seller texts and counts from one query.
	 *
	 * @return array
	 */
	public static function state() {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = current_time( 'mysql', true );
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
			'woocommerce'         => class_exists( 'WooCommerce' ),
			'legal_texts'         => '' !== trim( wp_strip_all_tags( (string) Settings::get( 'terms_text' ) ) ) && '' !== trim( wp_strip_all_tags( (string) Settings::get( 'consumer_info_text' ) ) ),
			'seller'              => '' !== trim( (string) Settings::get( 'seller_name' ) ) && '' !== trim( (string) Settings::get( 'seller_address' ) ),
		);
		foreach ( array( 'events', 'published_events', 'upcoming_occurrences', 'ticket_types', 'devices', 'paid_orders' ) as $key ) {
			$state[ $key ] = (int) ( $counts[ $key ] ?? 0 );
		}
		return $state;
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

	/** Manual payment (bank transfer) enabled with an IBAN or payment instructions. */
	private static function manual_payment_ready() {
		if ( ! in_array( 'manual', (array) Settings::get( 'enabled_providers' ), true ) ) { return false; }
		return '' !== trim( (string) Settings::get( 'bank_iban' ) ) || '' !== trim( (string) Settings::get( 'manual_instructions' ) );
	}

	/**
	 * Checklist steps derived from a state (see state()).
	 *
	 * @param array $state State values.
	 * @return array[] Steps: key, label, description, done, url, action (link text of an open step).
	 */
	public static function items( array $state ) {
		$settings = static function ( $section ) { return Forms::page_url( SettingsPage::SLUG ) . '#wpcap-settings-' . $section; };
		$events = Forms::page_url( EventsPage::SLUG );
		$payment_done = ! empty( $state['payment_online'] ) || ! empty( $state['payment_manual'] ) || ! empty( $state['woocommerce'] );
		if ( ! empty( $state['payment_online'] ) ) {
			$payment_note = __( 'Eine Online-Zahlungsart ist aktiv und vollständig eingerichtet.', 'wp-pass-captain' );
		} elseif ( ! empty( $state['payment_manual'] ) ) {
			$payment_note = __( 'Überweisung ist mit Bankverbindung bzw. Zahlungshinweis eingerichtet.', 'wp-pass-captain' );
		} elseif ( ! empty( $state['woocommerce'] ) ) {
			$payment_note = __( 'Die Zahlung läuft über den WooCommerce-Checkout.', 'wp-pass-captain' );
		} else {
			$payment_note = __( 'Stripe, PayPal oder Mollie mit Zugangsdaten aktivieren oder für Überweisung die IBAN hinterlegen.', 'wp-pass-captain' );
		}
		$items = array(
			array(
				'key' => 'payment',
				'label' => __( 'Zahlungsart einrichten', 'wp-pass-captain' ),
				'description' => $payment_note,
				'done' => $payment_done,
				'url' => $settings( 'payments' ),
			),
			array(
				'key' => 'legal',
				'label' => __( 'Rechtstexte hinterlegen', 'wp-pass-captain' ),
				'description' => __( 'AGB/Teilnahmebedingungen und Verbraucherinformationen (Widerrufshinweis) für den Checkout.', 'wp-pass-captain' ),
				'done' => ! empty( $state['legal_texts'] ),
				'url' => $settings( 'legal' ),
			),
			array(
				'key' => 'seller',
				'label' => __( 'Rechnungssteller angeben', 'wp-pass-captain' ),
				'description' => __( 'Name und Anschrift für Rechnungen und Belege.', 'wp-pass-captain' ),
				'done' => ! empty( $state['seller'] ),
				'url' => $settings( 'seller' ),
			),
			array(
				'key' => 'event',
				'label' => __( 'Erstes Event anlegen', 'wp-pass-captain' ),
				'description' => __( 'Ein Event mit erstem Termin und Ticketart lässt sich in einem Schritt anlegen.', 'wp-pass-captain' ),
				'done' => ! empty( $state['events'] ),
				'url' => $events . '#wpcap-create-event',
			),
			array(
				'key' => 'occurrence',
				'label' => __( 'Kommenden Termin planen', 'wp-pass-captain' ),
				'description' => __( 'Mindestens ein geplanter Termin in der Zukunft.', 'wp-pass-captain' ),
				'done' => ! empty( $state['upcoming_occurrences'] ),
				'url' => $events,
			),
			array(
				'key' => 'ticket_type',
				'label' => __( 'Ticketart anlegen', 'wp-pass-captain' ),
				'description' => __( 'Mindestens eine aktive Ticketart mit Preis und Kontingent.', 'wp-pass-captain' ),
				'done' => ! empty( $state['ticket_types'] ),
				'url' => $events,
			),
			array(
				'key' => 'publish',
				'label' => __( 'Event veröffentlichen', 'wp-pass-captain' ),
				'description' => __( 'Erst veröffentlichte Events sind buchbar.', 'wp-pass-captain' ),
				'done' => ! empty( $state['published_events'] ),
				'url' => $events,
			),
			array(
				'key' => 'scanner',
				'label' => __( 'Scanner-Gerät koppeln', 'wp-pass-captain' ),
				'description' => __( 'Ein Smartphone für den Einlass mit der Scanner-App koppeln.', 'wp-pass-captain' ),
				'done' => ! empty( $state['devices'] ),
				'url' => Forms::page_url( DevicesPage::SLUG ),
			),
			array(
				'key' => 'test_order',
				'label' => __( 'Testbestellung durchführen', 'wp-pass-captain' ),
				'description' => __( 'Eine Bestellung bis zur Zahlung durchspielen und Ticket-E-Mail prüfen.', 'wp-pass-captain' ),
				'done' => ! empty( $state['paid_orders'] ),
				'url' => Forms::page_url( OrdersPage::SLUG ),
			),
		);
		/**
		 * Steps of the "Erste Schritte" checklist; modules add or adjust steps (derive "done" from data).
		 *
		 * @param array[] $items Steps (key, label, description, done, url).
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
			);
		}
		return $out;
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
				self::toggle_form( 'show', __( 'Erste-Schritte-Checkliste anzeigen', 'wp-pass-captain' ) );
				echo '</div>';
			}
			return;
		}
		echo '<section class="wpcap-getting-started card" aria-labelledby="wpcap-getting-started-title" style="max-width:none;margin:16px 0;padding:12px 20px">';
		echo '<h2 id="wpcap-getting-started-title">' . esc_html__( 'Erste Schritte', 'wp-pass-captain' ) . '</h2>';
		/* translators: 1: completed steps, 2: all steps */
		$progress = sprintf( __( '%1$d von %2$d Schritten erledigt', 'wp-pass-captain' ), $done, $total );
		echo '<p id="wpcap-getting-started-progress">' . esc_html( $progress ) . ( $done === $total ? ' – ' . esc_html__( 'alles bereit für den Verkauf.', 'wp-pass-captain' ) : '' ) . '</p>';
		echo '<progress max="' . (int) $total . '" value="' . (int) $done . '" aria-labelledby="wpcap-getting-started-progress" style="width:100%;max-width:420px"></progress>';
		echo '<ol class="wpcap-getting-started-list" style="list-style:none;margin:12px 0 0;padding:0">';
		foreach ( $items as $item ) {
			$icon = $item['done'] ? 'dashicons-yes-alt' : 'dashicons-marker';
			$color = $item['done'] ? '#00a32a' : '#646970';
			echo '<li class="wpcap-gs-' . esc_attr( $item['key'] ) . ( $item['done'] ? ' is-done' : ' is-open' ) . '" style="display:flex;gap:8px;align-items:flex-start;margin:0 0 8px">';
			echo '<span class="dashicons ' . esc_attr( $icon ) . '" style="color:' . esc_attr( $color ) . '" aria-hidden="true"></span><span>';
			echo '<span class="screen-reader-text">' . esc_html( $item['done'] ? __( 'Erledigt:', 'wp-pass-captain' ) : __( 'Offen:', 'wp-pass-captain' ) ) . ' </span>';
			if ( ! $item['done'] && '' !== $item['url'] ) {
				echo '<strong><a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['label'] ) . '</a></strong>';
			} else {
				echo '<strong>' . esc_html( $item['label'] ) . '</strong>';
			}
			if ( '' !== $item['description'] ) { echo '<br><span class="description">' . esc_html( $item['description'] ) . '</span>'; }
			echo '</span></li>';
		}
		echo '</ol>';
		self::toggle_form( 'hide', __( 'Checkliste ausblenden', 'wp-pass-captain' ) );
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

	/** admin-post handler. */
	public static function handle_toggle() {
		Forms::verify( self::ACTION );
		Forms::finish( self::toggle( Forms::post() ), '', Forms::page_url( EventsPage::SLUG ) );
	}
}

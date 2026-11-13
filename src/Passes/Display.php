<?php
/**
 * Display of passes: "Gültig für: <Termine>" on tickets (mail, PDF, ticket page, order portal,
 * wallet, print – all via `wpcap_ticket_seat_label`), in the ticket mail and resend mails, on the
 * event card (pass types and a hint on included dates) and the [wpcap_passes] listing.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Passes;

use PassCaptain\Orders;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Display {
	/** Local date (d.m.Y) of a UTC datetime. */
	public static function date( $utc, $timezone ) {
		$tz = \PassCaptain\Admission\Engine::timezone( $timezone );
		return wp_date( 'd.m.Y', strtotime( $utc . ' UTC' ), $tz );
	}

	/** List of included dates, e.g. "01.08.2027, 02.08.2027"; event names when several events. */
	public static function dates( $type_id ) {
		$linked = Definitions::linked_occurrences( $type_id );
		if ( ! $linked ) { return ''; }
		$events = array();
		foreach ( $linked as $occ ) { $events[ (int) $occ->event_id ] = true; }
		$items = array();
		foreach ( $linked as $occ ) {
			$item = self::date( $occ->starts_at, $occ->timezone );
			if ( count( $events ) > 1 ) { $item = $occ->event_name . ' ' . $item; }
			if ( 'cancelled' === $occ->status ) { $item .= ' ' . __( '(abgesagt)', 'wp-pass-captain' ); }
			$items[] = $item;
		}
		return implode( ', ', $items );
	}

	/** "Gültig für: …" label of a pass type ('' for other types). */
	public static function valid_for( $type_id ) {
		$config = Definitions::get( $type_id );
		if ( ! $config ) { return ''; }
		$dates = self::dates( $type_id );
		if ( '' === $dates ) { return ''; }
		$label = sprintf( /* translators: %s: list of dates */ __( 'Gültig für: %s', 'wp-pass-captain' ), $dates );
		if ( $config->max_occurrences > 0 ) {
			/* translators: 1: usable dates, 2: included dates */
			$label .= ' ' . sprintf( __( '(%1$d aus %2$d Terminen frei wählbar)', 'wp-pass-captain' ), $config->max_occurrences, count( $config->occurrence_ids ) );
		}
		return $label;
	}

	private static function type_of_ticket( $ticket_id, $row ) {
		$row = (array) $row;
		if ( ! empty( $row['ticket_type_id'] ) ) { return (int) $row['ticket_type_id']; }
		if ( ! $ticket_id ) { return 0; }
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_type_id FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_id=%d", absint( $ticket_id ) ) );
	}

	/** Filter callback for `wpcap_ticket_seat_label`: appends the included dates. */
	public static function seat_label( $label, $ticket_id = 0, $row = array() ) {
		$type_id = self::type_of_ticket( $ticket_id, $row );
		if ( ! $type_id || ! Definitions::is_pass( $type_id ) ) { return $label; }
		$valid = self::valid_for( $type_id );
		if ( '' === $valid ) { return $label; }
		return '' !== trim( (string) $label ) ? $label . ' · ' . $valid : $valid;
	}

	/** Filter callback for `wpcap_ticket_mail`: one "Gültig für" line per pass in the order. */
	public static function ticket_mail( $mail, $order = null, $issued = array() ) {
		if ( ! is_array( $mail ) ) { return $mail; }
		$lines = array();
		foreach ( (array) $issued as $ticket ) {
			$type_id = (int) ( $ticket['ticket_type_id'] ?? 0 );
			if ( isset( $lines[ $type_id ] ) || ! Definitions::is_pass( $type_id ) ) { continue; }
			$valid = self::valid_for( $type_id );
			if ( '' !== $valid ) { $lines[ $type_id ] = sprintf( '%1$s: %2$s', (string) ( $ticket['ticket'] ?? '' ), $valid ); }
		}
		if ( $lines ) { $mail['body'] = rtrim( (string) ( $mail['body'] ?? '' ) ) . "\n\n" . implode( "\n", $lines ) . "\n"; }
		return $mail;
	}

	/** Filter callback for `wpcap_ticket_code_mail_section` (resend/reissue/transfer mails). */
	public static function code_mail_section( $section, $ticket_id = 0 ) {
		$type_id = self::type_of_ticket( $ticket_id, array() );
		if ( ! $type_id || ! Definitions::is_pass( $type_id ) ) { return $section; }
		$valid = self::valid_for( $type_id );
		return '' === $valid ? $section : (string) $section . $valid . "\n";
	}

	/** Filter callback for `wpcap_event_card_html`. */
	public static function event_card( $html, $event = null, $types = array() ) {
		if ( ! is_string( $html ) || ! $event ) { return $html; }
		$occurrence_id = (int) ( $event->occurrence_id ?? 0 );
		foreach ( (array) $types as $type ) {
			$valid = self::valid_for( (int) $type->ticket_type_id );
			if ( '' === $valid ) { continue; }
			$for = 'for="' . esc_attr( 'wpcap-' . $occurrence_id . '-' . (int) $type->ticket_type_id ) . '"';
			$pos = strpos( $html, $for );
			$end = false === $pos ? false : strpos( $html, '</label>', $pos );
			if ( false === $end ) { continue; }
			$end += strlen( '</label>' );
			$html = substr( $html, 0, $end ) . ' <small class="wpcap-pass-dates">' . esc_html( $valid ) . '</small>' . substr( $html, $end );
		}
		// Hint on the cards of included dates: the pass is sold on its anchor occurrence.
		$hints = array();
		foreach ( Definitions::pass_ids_for_occurrence( $occurrence_id ) as $type_id ) {
			$type = Definitions::type_row( $type_id );
			if ( ! $type || 'active' !== $type->status || (int) $type->occurrence_id === $occurrence_id ) { continue; }
			/* translators: 1: pass name, 2: event name, 3: date */
			$hints[] = sprintf( __( 'Auch enthalten im Pass „%1$s“ (erhältlich bei %2$s am %3$s).', 'wp-pass-captain' ), $type->name, $type->event_name, self::date( $type->starts_at, $type->timezone ) );
		}
		if ( $hints ) {
			$block = '<p class="wpcap-pass-hint">' . implode( '<br>', array_map( 'esc_html', $hints ) ) . '</p>';
			$pos = strpos( $html, '</fieldset>' );
			$html = false === $pos ? $html . $block : substr( $html, 0, $pos + strlen( '</fieldset>' ) ) . $block . substr( $html, $pos + strlen( '</fieldset>' ) );
		}
		return $html;
	}

	/**
	 * Passes currently on sale (active type, scheduled anchor in the future, sales window open).
	 *
	 * @return array[] type (row), dates (label), available (int|null), price_minor.
	 */
	public static function on_sale() {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$out = array();
		foreach ( Definitions::all_ids() as $type_id ) {
			$type = Definitions::type_row( $type_id );
			if ( ! $type || 'active' !== $type->status || 'scheduled' !== $type->occurrence_status || $type->starts_at < $now ) { continue; }
			if ( ( $type->sales_start && $type->sales_start > $now ) || ( $type->sales_end && $type->sales_end < $now ) ) { continue; }
			$out[] = array( 'type' => $type, 'dates' => self::valid_for( $type_id ), 'available' => Orders::availability( $type_id ), 'price_minor' => Orders::unit_price( $type_id ) );
		}
		return $out;
	}

	/** Shortcode [wpcap_passes]: list of passes with their dates and where to buy them. */
	public static function shortcode() {
		$passes = self::on_sale();
		$html = '<div class="wpcap-passes">';
		if ( ! $passes ) { $html .= '<p>' . esc_html__( 'Aktuell sind keine Pässe verfügbar.', 'wp-pass-captain' ) . '</p>'; }
		foreach ( $passes as $entry ) {
			$type = $entry['type'];
			$html .= '<article class="wpcap-pass"><h3>' . esc_html( $type->name ) . '</h3>';
			$html .= '<p>' . esc_html( \PassCaptain\Frontend\Format::money( $entry['price_minor'], $type->currency ) ) . '</p>';
			$html .= '<p class="wpcap-pass-dates">' . esc_html( $entry['dates'] ) . '</p>';
			/* translators: 1: event name, 2: date */
			$html .= '<p>' . esc_html( sprintf( __( 'Erhältlich bei %1$s am %2$s.', 'wp-pass-captain' ), $type->event_name, self::date( $type->starts_at, $type->timezone ) ) ) . '</p>';
			if ( null !== $entry['available'] && (int) $entry['available'] < 1 ) { $html .= '<p class="wpcap-soldout">' . esc_html__( 'Ausverkauft', 'wp-pass-captain' ) . '</p>'; }
			$html .= '</article>';
		}
		return $html . '</div>';
	}
}

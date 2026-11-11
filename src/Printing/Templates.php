<?php
/**
 * Print layouts (ticket, badge A6, name tag 90x55 mm on A4, wristband) rendered as PDF.
 *
 * A template body is one instruction per line: "[*]size|text" ("*" = bold) or "{qr}" for the QR code.
 * Placeholders: {event} {date} {time} {location} {ticket_category} {attendee_name} {seat_label} {order}
 * and {qr}. Lines that are empty after replacement are skipped.
 */
namespace PassCaptain\Printing;

use PassCaptain\Audit;
use PassCaptain\Reports\Scope;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Templates {
	const KINDS = array( 'ticket', 'badge', 'nametag', 'wristband' );
	const PLACEHOLDERS = array( '{event}', '{date}', '{time}', '{location}', '{ticket_category}', '{attendee_name}', '{seat_label}', '{order}', '{qr}' );

	public static function kind_labels() {
		return array(
			'ticket'    => __( 'Ticket (A4)', 'wp-pass-captain' ),
			'badge'     => __( 'Badge (A6)', 'wp-pass-captain' ),
			'nametag'   => __( 'Namensschild (90×55 mm, 10 pro A4)', 'wp-pass-captain' ),
			'wristband' => __( 'Einlassband (250×25 mm)', 'wp-pass-captain' ),
		);
	}

	public static function default_body( $kind ) {
		$bodies = array(
			'ticket'    => "*22|{event}\n14|{ticket_category}\n12|{date}\n12|{location}\n12|{attendee_name}\n12|{seat_label}\n{qr}",
			'badge'     => "*20|{attendee_name}\n12|{ticket_category}\n11|{event}\n10|{date}\n{qr}",
			'nametag'   => "*16|{attendee_name}\n9|{ticket_category}\n8|{event}\n{qr}",
			'wristband' => "*11|{event}\n8|{date} · {ticket_category}\n8|{attendee_name} {seat_label}\n{qr}",
		);
		return $bodies[ $kind ] ?? $bodies['ticket'];
	}

	/** Normalises a user supplied template body (plain text, max 30 lines). */
	public static function sanitize_body( $body ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $body );
		$out = array();
		foreach ( array_slice( $lines, 0, 30 ) as $line ) {
			$line = trim( sanitize_text_field( $line ) );
			if ( '' !== $line ) { $out[] = mb_substr( $line, 0, 200 ); }
		}
		return implode( "\n", $out );
	}

	/** Parses the body into [{qr:bool,size,bold,text}]. */
	public static function parse( $body ) {
		$out = array();
		foreach ( preg_split( '/\n/', (string) $body ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) { continue; }
			if ( '{qr}' === $line ) { $out[] = array( 'qr' => true ); continue; }
			$bold = false;
			$size = 12.0;
			if ( preg_match( '/^(\*?)(\d{1,2}(?:\.\d)?)\|(.*)$/', $line, $m ) ) {
				$bold = '*' === $m[1];
				$size = max( 5.0, min( 48.0, (float) $m[2] ) );
				$line = $m[3];
			} elseif ( '*' === substr( $line, 0, 1 ) ) {
				$bold = true;
				$line = substr( $line, 1 );
			}
			$out[] = array( 'qr' => false, 'size' => $size, 'bold' => $bold, 'text' => $line );
		}
		return $out;
	}

	/** Replaces placeholders; {qr} inside text is removed (it is an image). */
	public static function fill( $text, array $values ) {
		$map = array();
		foreach ( self::PLACEHOLDERS as $placeholder ) {
			$key = trim( $placeholder, '{}' );
			$map[ $placeholder ] = 'qr' === $key ? '' : sanitize_text_field( (string) ( $values[ $key ] ?? '' ) );
		}
		$text = strtr( (string) $text, $map );
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
		return (string) preg_replace( '/^[\s·\-|,\/]+|[\s·\-|,\/]+$/u', '', $text );
	}

	/**
	 * Renders items with a layout.
	 *
	 * @param string $kind  One of KINDS.
	 * @param array  $items List of placeholder value arrays (keys without braces; 'qr' = ticket code or '').
	 * @param string $body  Template body.
	 * @return string PDF bytes.
	 */
	public static function render( $kind, array $items, $body = '' ) {
		$kind = in_array( $kind, self::KINDS, true ) ? $kind : 'ticket';
		$lines = self::parse( '' !== $body ? $body : self::default_body( $kind ) );
		$pdf = new PdfWriter();
		$mm = PdfWriter::MM;
		if ( 'nametag' === $kind ) {
			$w = 90 * $mm;
			$h = 55 * $mm;
			$left = 15 * $mm;
			$top = 297 * $mm - 11 * $mm;
			foreach ( array_values( $items ) as $i => $item ) {
				$slot = $i % 10;
				if ( 0 === $slot ) { $pdf->add_page( 210 * $mm, 297 * $mm ); }
				$x = $left + ( $slot % 2 ) * $w;
				$y = $top - ( intdiv( $slot, 2 ) + 1 ) * $h;
				$pdf->rect( $x, $y, $w, $h, false, 0.8 );
				self::cell( $pdf, $lines, $item, $x, $y, $w, $h, 'center', 'right' );
			}
			return $pdf->output( __( 'Namensschilder', 'wp-pass-captain' ) );
		}
		$sizes = array(
			'ticket'    => array( 595.28, 841.89, 50, 'left', 'flow' ),
			'badge'     => array( 105 * $mm, 148 * $mm, 18, 'center', 'flow' ),
			'wristband' => array( 250 * $mm, 25 * $mm, 6, 'left', 'right' ),
		);
		list( $pw, $ph, $pad, $align, $qr_mode ) = $sizes[ $kind ];
		foreach ( $items as $item ) {
			$pdf->add_page( $pw, $ph );
			if ( 'wristband' === $kind ) {
				// Adhesive tab on the left end stays empty.
				$pdf->line( 40 * $mm, 0, 40 * $mm, $ph, 0.8 );
				self::cell( $pdf, $lines, $item, 40 * $mm, 0, $pw - 40 * $mm, $ph, $align, $qr_mode );
				continue;
			}
			self::cell( $pdf, $lines, $item, $pad, $pad, $pw - 2 * $pad, $ph - 2 * $pad, $align, $qr_mode, 0 );
		}
		return $pdf->output( self::kind_labels()[ $kind ] ?? '' );
	}

	/** Lays out one item inside a box. $qr_mode: flow (below the text) or right (square at the right edge). */
	private static function cell( PdfWriter $pdf, array $lines, array $item, $x, $y, $w, $h, $align, $qr_mode, $inner = 8 ) {
		$x += $inner;
		$y += $inner;
		$w -= 2 * $inner;
		$h -= 2 * $inner;
		$wants_qr = (bool) array_filter( $lines, static function ( $l ) { return $l['qr']; } ) && '' !== (string) ( $item['qr'] ?? '' );
		$text_w = $w;
		if ( $wants_qr && 'right' === $qr_mode ) {
			$q = min( $h, $w * 0.4 );
			if ( $pdf->qr( $x + $w - $q, $y + ( $h - $q ) / 2, $q, $item['qr'] ) ) { $text_w = $w - $q - 6; }
		}
		$cursor = $y + $h;
		$anchor = 'center' === $align ? $x + $text_w / 2 : $x;
		foreach ( $lines as $line ) {
			if ( $line['qr'] ) {
				if ( ! $wants_qr || 'flow' !== $qr_mode ) { continue; }
				$q = min( $w * 0.6, $cursor - $y - 30, 260 );
				if ( $q < 40 ) { continue; }
				$cursor -= 14;
				$qx = 'center' === $align ? $x + ( $w - $q ) / 2 : $x + ( $w - $q ) / 2;
				if ( $pdf->qr( $qx, $cursor - $q, $q, $item['qr'] ) ) {
					$cursor -= $q + 6;
					$pdf->text( $x + $w / 2, $cursor - 8, 7, substr( (string) $item['qr'], 0, 16 ) . '…', false, 'center' );
					$cursor -= 14;
				}
				continue;
			}
			$text = self::fill( $line['text'], $item );
			if ( '' === $text ) { continue; }
			$size = $line['size'];
			if ( $cursor - $size * 1.25 < $y - 0.5 ) { break; }
			$cursor -= $size * 1.2;
			$pdf->text( $anchor, $cursor, $size, PdfWriter::fit( $text, $size, $text_w, $line['bold'] ), $line['bold'], 'center' === $align ? 'center' : 'left' );
			$cursor -= $size * 0.25;
		}
	}

	/* ------------------------------------------------------------------ storage */

	public static function all_templates( $kind = '' ) {
		global $wpdb;
		$p = $wpdb->prefix;
		if ( '' !== $kind ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}wpcap_print_templates WHERE kind=%s AND status='active' ORDER BY name", $kind ) );
		}
		return $wpdb->get_results( "SELECT * FROM {$p}wpcap_print_templates WHERE status='active' ORDER BY kind,name" );
	}

	public static function get_template( $template_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_print_templates WHERE template_id=%d", absint( $template_id ) ) );
	}

	/** Creates or updates a template. @return int|\WP_Error template id. */
	public static function save_template( $template_id, $name, $kind, $body ) {
		global $wpdb;
		$name = mb_substr( sanitize_text_field( (string) $name ), 0, 190 );
		$body = self::sanitize_body( $body );
		if ( '' === $name || ! in_array( $kind, self::KINDS, true ) || '' === $body ) {
			return new \WP_Error( 'wpcap_print_template_invalid', __( 'Bitte Name, Layout und Inhalt angeben.', 'wp-pass-captain' ), array( 'status' => 400 ) );
		}
		$data = array( 'name' => $name, 'kind' => $kind, 'body' => $body, 'status' => 'active', 'updated_at' => current_time( 'mysql', true ) );
		if ( $template_id ) {
			$wpdb->update( $wpdb->prefix . 'wpcap_print_templates', $data, array( 'template_id' => absint( $template_id ) ) );
			return absint( $template_id );
		}
		$wpdb->insert( $wpdb->prefix . 'wpcap_print_templates', $data );
		return (int) $wpdb->insert_id;
	}

	/** Selects the template per event and layout (0 = built-in default). */
	public static function assign( $event_id, $kind, $template_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		if ( ! in_array( $kind, self::KINDS, true ) ) { return new \WP_Error( 'wpcap_print_kind', __( 'Unbekanntes Layout.', 'wp-pass-captain' ) ); }
		$template_id = absint( $template_id );
		if ( $template_id ) {
			$template = self::get_template( $template_id );
			if ( ! $template || $template->kind !== $kind ) { return new \WP_Error( 'wpcap_print_template_kind', __( 'Die Vorlage passt nicht zum Layout.', 'wp-pass-captain' ) ); }
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_print_event_templates WHERE event_id=%d AND kind=%s", absint( $event_id ), $kind ) );
		if ( $template_id ) {
			$wpdb->insert( "{$p}wpcap_print_event_templates", array( 'event_id' => absint( $event_id ), 'kind' => $kind, 'template_id' => $template_id ) );
		}
		return true;
	}

	/** Body used for an event and layout. */
	public static function body_for_event( $event_id, $kind ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$body = $wpdb->get_var( $wpdb->prepare( "SELECT t.body FROM {$p}wpcap_print_event_templates et INNER JOIN {$p}wpcap_print_templates t ON t.template_id=et.template_id WHERE et.event_id=%d AND et.kind=%s AND t.status='active'", absint( $event_id ), $kind ) );
		return $body ? (string) $body : self::default_body( $kind );
	}

	/* ------------------------------------------------------------------ data */

	/** Tickets of an occurrence with print context (no codes). */
	public static function tickets_for_occurrence( $occurrence_id, array $statuses = array( 'valid', 'checked_in' ) ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$statuses = array_values( array_intersect( $statuses, array( 'valid', 'checked_in', 'revoked' ) ) );
		$in = $statuses ? "'" . implode( "','", $statuses ) . "'" : "'valid'";
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT t.ticket_id,t.public_id,t.status,t.order_id,t.ticket_type_id,t.attendee_email,tt.name AS ticket_name,o.occurrence_id,o.event_id,o.starts_at,o.timezone,o.location,e.name AS event_name,ord.public_id AS order_public_id,ord.customer_email,COALESCE(a.attendee_name,'') AS attendee_name
			FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id INNER JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id LEFT JOIN {$p}wpcap_ticket_assignments a ON a.ticket_id=t.ticket_id
			WHERE o.occurrence_id=%d AND t.status IN ($in) ORDER BY COALESCE(NULLIF(a.attendee_name,''),'~'),t.ticket_id",
			absint( $occurrence_id )
		) );
	}

	/** Placeholder values for a ticket row (see tickets_for_occurrence / Orders::tickets_for_order). */
	public static function values( $row, $token = '' ) {
		$row = (object) $row;
		$seat = (string) apply_filters( 'wpcap_ticket_seat_label', '', (int) ( $row->ticket_id ?? 0 ), (array) $row );
		return array(
			'event'           => (string) ( $row->event_name ?? '' ),
			'date'            => Scope::local( $row->starts_at ?? '', $row->timezone ?? 'UTC', 'd.m.Y H:i' ),
			'time'            => Scope::local( $row->starts_at ?? '', $row->timezone ?? 'UTC', 'H:i' ),
			'location'        => (string) ( $row->location ?? '' ),
			'ticket_category' => (string) ( $row->ticket_name ?? '' ),
			'attendee_name'   => (string) ( $row->attendee_name ?? '' ),
			'seat_label'      => $seat,
			'order'           => substr( (string) ( $row->order_public_id ?? '' ), 0, 8 ),
			'qr'              => (string) $token,
		);
	}

	/**
	 * Issues new codes for valid tickets so they can be printed with a QR code (only hashes are stored,
	 * so existing codes cannot be printed). Previously sent/printed codes become invalid.
	 *
	 * @return array<int,string> ticket_id => new plaintext code (never stored).
	 */
	public static function rotate_codes( array $ticket_ids, $reason = 'print' ) {
		global $wpdb;
		$out = array();
		foreach ( array_unique( array_map( 'absint', $ticket_ids ) ) as $ticket_id ) {
			$token = bin2hex( random_bytes( 32 ) );
			$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_tickets SET token_hash=%s WHERE ticket_id=%d AND status='valid'", hash( 'sha256', $token ), $ticket_id ) );
			if ( $updated ) { $out[ $ticket_id ] = $token; }
		}
		if ( $out ) {
			Audit::record( 'ticket_codes_rotated', 'ticket', (int) array_key_first( $out ), array( 'count' => count( $out ), 'reason' => sanitize_key( $reason ) ) );
		}
		return $out;
	}

	/**
	 * Batch PDF for an occurrence.
	 *
	 * @param bool $with_codes Rotate codes of valid tickets and print QR codes.
	 */
	public static function batch_pdf( $occurrence_id, $kind, $with_codes = false ) {
		$rows = self::tickets_for_occurrence( $occurrence_id, 'ticket' === $kind || 'wristband' === $kind ? array( 'valid' ) : array( 'valid', 'checked_in' ) );
		$tokens = $with_codes ? self::rotate_codes( array_map( static function ( $r ) { return (int) $r->ticket_id; }, $rows ), 'batch_' . $kind ) : array();
		$items = array();
		foreach ( $rows as $row ) { $items[] = self::values( $row, $tokens[ (int) $row->ticket_id ] ?? '' ); }
		$event_id = Scope::occurrence_event( $occurrence_id );
		return self::render( $kind, $items, self::body_for_event( $event_id, $kind ) );
	}
}

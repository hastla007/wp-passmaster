<?php
/**
 * Ticket PDFs of the ticket mail, the order portal and the admin (one A4 page per ticket).
 *
 * A330: rendered by the print engine (Printing\Templates with the "ticket" layout, Printing\PdfWriter),
 * so the ticket template selected for the event under "Druck & Listen" applies to these PDFs too
 * and names in any script print correctly (embedded Unicode font). Each page carries the site name
 * as header and a footer (default "only valid once"; filter `wpcap_ticket_pdf_footer` per ticket,
 * e.g. per ticket type or pass).
 *
 * The QR matrix comes from the bundled bacon/bacon-qr-code encoder (installed with Composer in the
 * release ZIP). Without it the PDF still contains the ticket code as text.
 */
namespace PassCaptain;

use PassCaptain\Printing\PdfWriter;
use PassCaptain\Printing\Templates;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TicketPdf {
	/**
	 * @param array $tickets List of ['event','ticket','when','location','seat','attendee','token','help_code'] arrays,
	 *                       optionally 'occurrence_id' (selects the event's ticket template), 'ticket_type_id',
	 *                       'starts_at'/'timezone' (for {time}), 'order' and 'footer' (overrides $meta['footer']).
	 * @param array $meta    ['title' => string, 'footer' => string].
	 * @return string PDF bytes.
	 */
	public static function render( array $tickets, array $meta = array() ) {
		$pdf = new PdfWriter();
		$bodies = array();
		foreach ( $tickets as $ticket ) {
			$ticket = (array) $ticket;
			$occurrence_id = (int) ( $ticket['occurrence_id'] ?? 0 );
			if ( ! isset( $bodies[ $occurrence_id ] ) ) {
				$event_id = $occurrence_id ? (int) Reports\Scope::occurrence_event( $occurrence_id ) : 0;
				$bodies[ $occurrence_id ] = $event_id ? Templates::body_for_event( $event_id, 'ticket' ) : Templates::default_body( 'ticket' );
			}
			Templates::pages( 'ticket', array( self::values( $ticket, $meta ) ), $bodies[ $occurrence_id ], $pdf );
		}
		return $pdf->output( (string) ( $meta['title'] ?? __( 'Tickets', 'pass-captain' ) ) );
	}

	/** Placeholder values of the ticket layout for one ticket entry. */
	public static function values( array $ticket, array $meta = array() ) {
		$time = '';
		if ( ! empty( $ticket['starts_at'] ) ) { $time = Reports\Scope::local( (string) $ticket['starts_at'], (string) ( $ticket['timezone'] ?? 'UTC' ), 'H:i' ); }
		$footer = (string) ( $ticket['footer'] ?? ( $meta['footer'] ?? '' ) );
		/**
		 * Footer line of one page of a ticket PDF (mail, portal, admin), e.g. conditions per ticket
		 * type or pass. '' = none.
		 *
		 * @param string $footer Default footer ("Dieses Ticket ist nur einmal gültig …").
		 * @param array  $ticket Ticket entry (event, ticket, when, occurrence_id, ticket_type_id …; no code).
		 */
		$footer = (string) apply_filters( 'wpcap_ticket_pdf_footer', $footer, array_diff_key( $ticket, array( 'token' => 1, 'help_code' => 1, 'url' => 1 ) ) );
		return array(
			'event' => (string) ( $ticket['event'] ?? '' ),
			'date' => (string) ( $ticket['when'] ?? '' ),
			'time' => $time,
			'location' => (string) ( $ticket['location'] ?? '' ),
			'ticket_category' => (string) ( $ticket['ticket'] ?? '' ),
			'attendee_name' => (string) ( $ticket['attendee'] ?? '' ),
			'seat_label' => (string) ( $ticket['seat'] ?? '' ),
			'order' => (string) ( $ticket['order'] ?? '' ),
			'help_code' => (string) ( $ticket['help_code'] ?? '' ),
			'qr' => (string) ( $ticket['token'] ?? '' ),
			'_title' => (string) ( $meta['title'] ?? '' ),
			'_footer' => $footer,
		);
	}

	/** QR modules as rows of booleans, or empty array when the encoder is unavailable. */
	public static function qr_matrix( $payload ) {
		if ( '' === $payload || ! class_exists( '\\BaconQrCode\\Encoder\\Encoder' ) ) { return array(); }
		try {
			$qr = \BaconQrCode\Encoder\Encoder::encode( $payload, \BaconQrCode\Common\ErrorCorrectionLevel::M(), 'UTF-8' );
			$matrix = $qr->getMatrix();
			$rows = array();
			for ( $y = 0; $y < $matrix->getHeight(); $y++ ) {
				for ( $x = 0; $x < $matrix->getWidth(); $x++ ) { $rows[ $y ][ $x ] = 1 === $matrix->get( $x, $y ); }
			}
			return $rows;
		} catch ( \Throwable $e ) {
			return array();
		}
	}
}

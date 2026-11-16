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
 * Size (A618, audit R2 #5): compressed content streams, QR modules merged into runs; render_to_file()
 * streams the document page by page into a private file (ticket mail attachments, limits see
 * mail_limits()). The QR code carries the signed ticket code when signed QR codes are enabled
 * (Security\SignedQr). The QR matrix comes from the bundled bacon/bacon-qr-code encoder (installed
 * with Composer in the release ZIP). Without it the PDF still contains the ticket code as text.
 */
namespace PassCaptain;

use PassCaptain\Printing\PdfWriter;
use PassCaptain\Printing\Templates;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TicketPdf {
	/** Tickets per mail attachment before the PDF is replaced by a note (filter wp_pass_captain_ticket_pdf_limits_v1). */
	const MAIL_MAX_TICKETS = 50;
	/** Attachment size limit in bytes (same filter). */
	const MAIL_MAX_BYTES = 5242880;

	/**
	 * @param array $tickets List of ['event','ticket','when','location','seat','attendee','token','help_code'] arrays,
	 *                       optionally 'occurrence_id' (selects the event's ticket template), 'ticket_type_id',
	 *                       'starts_at'/'timezone' (for {time}), 'order' and 'footer' (overrides $meta['footer']).
	 * @param array $meta    ['title' => string, 'footer' => string, 'lang' => locale].
	 * @return string PDF bytes.
	 */
	public static function render( array $tickets, array $meta = array() ) {
		$pdf = self::build( new PdfWriter(), $tickets, $meta );
		return $pdf->output( self::title( $meta ) );
	}

	/** Streams the PDF into $file (mode 0600) page by page. False when writing failed. */
	public static function render_to_file( $file, array $tickets, array $meta = array() ) {
		$pdf = self::build( new PdfWriter( (string) $file ), $tickets, $meta );
		return $pdf->finish( self::title( $meta ) );
	}

	private static function title( array $meta ) {
		return (string) ( $meta['title'] ?? __( 'Tickets', 'pass-captain' ) );
	}

	/** Draws one page per ticket with the event's ticket template (A330). */
	private static function build( PdfWriter $pdf, array $tickets, array $meta ) {
		$pdf->set_language( ! empty( $meta['lang'] ) ? (string) $meta['lang'] : ( function_exists( 'determine_locale' ) ? determine_locale() : '' ) );
		$tickets = array_values( array_map( static function ( $ticket ) { return (array) $ticket; }, $tickets ) );
		// Signed QR values (PC1) for all tickets at once; the plain code when signing is off.
		$qr = Security\SignedQr::qr_values( array_map( static function ( $t ) { return (string) ( $t['token'] ?? '' ); }, $tickets ) );
		$bodies = array();
		foreach ( $tickets as $i => $ticket ) {
			if ( ! $pdf->ok() ) { break; }
			$occurrence_id = (int) ( $ticket['occurrence_id'] ?? 0 );
			if ( ! isset( $bodies[ $occurrence_id ] ) ) {
				$event_id = $occurrence_id ? (int) Reports\Scope::occurrence_event( $occurrence_id ) : 0;
				$bodies[ $occurrence_id ] = $event_id ? Templates::body_for_event( $event_id, 'ticket' ) : Templates::default_body( 'ticket' );
			}
			$values = self::values( self::data( $ticket, $meta ), $meta );
			$values['qr'] = (string) ( $qr[ $i ] ?? $values['qr'] );
			Templates::pages( 'ticket', array( $values ), $bodies[ $occurrence_id ], $pdf );
		}
		return $pdf;
	}

	/** Fields a filter can never change: the code and the help code (scanning must keep working). */
	const FIXED_FIELDS = array( 'token', 'help_code' );

	/**
	 * Filter wpcap_ticket_pdf_data (A331): display data of one ticket page (event, ticket, when,
	 * location, seat, attendee, ticket_id when known, further keys for the layout filter). The ticket
	 * code and help code always stay as issued.
	 */
	public static function data( array $ticket, array $meta = array() ) {
		$filtered = apply_filters( 'wpcap_ticket_pdf_data', $ticket, $meta );
		$filtered = is_array( $filtered ) ? $filtered : $ticket;
		foreach ( self::FIXED_FIELDS as $field ) {
			unset( $filtered[ $field ] );
			if ( array_key_exists( $field, $ticket ) ) { $filtered[ $field ] = $ticket[ $field ]; }
		}
		return $filtered;
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
			'admission_notice' => (string) ( $ticket['admission_notice'] ?? AdmissionChecks::notice_for_type( (int) ( $ticket['ticket_type_id'] ?? 0 ) ) ),
			'qr' => (string) ( $ticket['token'] ?? '' ),
			'_title' => (string) ( $meta['title'] ?? '' ),
			'_footer' => $footer,
		);
	}

	/** QR modules as rows of booleans, or empty array when the encoder is unavailable (see QrCode::matrix()). */
	public static function qr_matrix( $payload ) {
		return QrCode::matrix( (string) $payload );
	}

	/**
	 * Attachment limits for the ticket mail: [max tickets, max bytes]. Above them the PDF is not attached;
	 * the mail carries the ticket links and a note instead (audit R2 #5: 500 tickets made 10–30 MB mails).
	 *
	 * @param object|null $order Order row.
	 * @return array{0:int,1:int}
	 */
	public static function mail_limits( $order = null ) {
		/**
		 * Limits of the ticket PDF attached to the ticket mail.
		 *
		 * @param array       $limits ['max_tickets' => 50, 'max_bytes' => 5 MB].
		 * @param object|null $order  Order row.
		 */
		$limits = (array) apply_filters( 'wp_pass_captain_ticket_pdf_limits_v1', array( 'max_tickets' => self::MAIL_MAX_TICKETS, 'max_bytes' => self::MAIL_MAX_BYTES ), $order );
		return array( max( 0, (int) ( $limits['max_tickets'] ?? self::MAIL_MAX_TICKETS ) ), max( 0, (int) ( $limits['max_bytes'] ?? self::MAIL_MAX_BYTES ) ) );
	}

	/**
	 * Builds the ticket PDF for the ticket mail of an order (in the order's language, with attendee
	 * names) in a fresh private directory. When the order has more tickets than allowed or the PDF is
	 * larger than allowed, no file is attached and `notice` explains where the tickets are instead.
	 *
	 * @param object $order  Order row.
	 * @param array  $issued Issued tickets (ticket_id, token, event, ticket, starts_at, timezone, location).
	 * @return array{files:string[],dir:string,notice:string}
	 */
	public static function mail_attachment( $order, array $issued ) {
		$out = array( 'files' => array(), 'dir' => '', 'notice' => '' );
		if ( ! $issued ) { return $out; }
		list( $max_tickets, $max_bytes ) = self::mail_limits( $order );
		$locale = (string) ( $order->locale ?? '' );
		$too_large = static function () use ( $issued, $locale ) {
			return Mail\Mailer::with_locale( $locale, static function () use ( $issued ) {
				/* translators: %d: number of tickets */
				return sprintf( _n( '%d Ticket ist wegen seiner Größe nicht als PDF angehängt. Öffne es über den Ticketlink in dieser E-Mail; dort kannst du es vorzeigen oder ausdrucken. Deine Bestellung findest du jederzeit über den Link aus der Bestellbestätigung.', 'Deine %d Tickets sind wegen ihrer Anzahl nicht als PDF angehängt. Öffne sie über die Ticketlinks in dieser E-Mail; dort kannst du sie vorzeigen oder ausdrucken. Deine Bestellung findest du jederzeit über den Link aus der Bestellbestätigung.', count( $issued ), 'pass-captain' ), count( $issued ) );
			} );
		};
		if ( $max_tickets > 0 && count( $issued ) > $max_tickets ) {
			$out['notice'] = $too_large();
			return $out;
		}
		$dir = TempFiles::private_dir();
		if ( ! $dir ) { return $out; }
		$out['dir'] = $dir;
		$file = $dir . '/tickets-' . preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $order->public_id ?? 'order' ) ) . '.pdf';
		$names = self::attendee_names( array_column( $issued, 'ticket_id' ) );
		$ok = Mail\Mailer::with_locale( $locale, static function () use ( $file, $issued, $names, $locale ) {
			$tickets = array_map( static function ( $ticket ) use ( $names ) {
				return array(
					'event' => $ticket['event'],
					'ticket' => $ticket['ticket'],
					'when' => Orders::format_start( $ticket['starts_at'], $ticket['timezone'] ),
					'location' => $ticket['location'],
					'seat' => (string) apply_filters( 'wpcap_ticket_seat_label', '', (int) $ticket['ticket_id'], $ticket ),
					'attendee' => $names[ (int) $ticket['ticket_id'] ] ?? '',
					'token' => $ticket['token'],
					// Template selection, {time} and the help code under the QR code (A089, A330).
					'occurrence_id' => (int) ( $ticket['occurrence_id'] ?? 0 ),
					'ticket_type_id' => (int) ( $ticket['ticket_type_id'] ?? 0 ),
					'starts_at' => $ticket['starts_at'],
					'timezone' => $ticket['timezone'],
					'help_code' => class_exists( HelpCode::class ) ? HelpCode::for_ticket( (int) $ticket['ticket_id'], (string) $ticket['token'] ) : '',
				);
			}, $issued );
			return self::render_to_file( $file, $tickets, array( 'title' => get_bloginfo( 'name' ), 'footer' => __( 'Dieses Ticket ist nur einmal gültig. Code nicht weitergeben.', 'pass-captain' ), 'lang' => '' !== $locale ? $locale : Mail\Mailer::current_locale() ) );
		} );
		$size = $ok && is_file( $file ) ? (int) filesize( $file ) : 0;
		if ( ! $ok || ! $size ) {
			TempFiles::remove( $file );
			return $out;
		}
		if ( $max_bytes > 0 && $size > $max_bytes ) {
			TempFiles::remove( $file );
			$out['notice'] = $too_large();
			return $out;
		}
		$out['files'][] = $file;
		return $out;
	}

	/**
	 * Attendee names of tickets (ticket_assignments), keyed by ticket id.
	 *
	 * @param int[] $ticket_ids Ticket ids.
	 * @return array<int,string>
	 */
	public static function attendee_names( array $ticket_ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', $ticket_ids ) ) );
		if ( ! $ids ) { return array(); }
		$out = array();
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT ticket_id,attendee_name FROM %i WHERE ticket_id IN (' . implode( ',', array_fill( 0, count( $chunk ), '%d' ) ) . ')', array_merge( array( $wpdb->prefix . 'wpcap_ticket_assignments' ), $chunk ) ) );
			foreach ( (array) $rows as $row ) { $out[ (int) $row->ticket_id ] = (string) $row->attendee_name; }
		}
		return $out;
	}
}

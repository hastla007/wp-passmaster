<?php
/**
 * Print layouts (ticket, badge A6, name tag 90x55 mm on A4, wristband) rendered as PDF.
 *
 * A template body is one instruction per line: "[*]size|text" ("*" = bold) or "{qr}" for the QR code.
 * Placeholders: {event} {date} {time} {location} {ticket_category} {attendee_name} {seat_label} {order}
 * {help_code} (short code for staff when the QR code cannot be read; only with printed codes), {admission_notice}
 * (age/ID notice of the ticket type, A614) and {qr}. Lines that are empty after replacement are skipped.
 *
 * A330: the ticket layout is also the layout of the ticket PDFs in mails, the order portal and the
 * admin (TicketPdf), so the template selected for an event applies there too. On the ticket layout
 * the full code is printed under the QR code, a line with {help_code} gets the help-code caption
 * above it, and items may carry a page header (`_title`) and footer (`_footer`).
 *
 * A685 design lines (start with "@", never printed): "@logo|<media id>" (JPEG/PNG of the media library,
 * printed at the top; on wristbands next to the adhesive tab), "@accent|#RRGGBB" (colour of bold lines) and
 * "@text|#RRGGBB" (colour of the other lines). Both colours need a contrast of at least 4.5:1 on white
 * (WCAG 2.2, 1.4.3); save_template() refuses lower values. starters() offers accessible starting points.
 *
 * A686: "{field:<key>}" prints the answer to the participant field <key> of the ticket (attendee field
 * mapping; consents are never printed). Label sheets besides the name tags: 24 labels 70×37 mm and 14 labels
 * 99.1×38.1 mm per A4 and four A6 badges per A4 sheet with cut marks (grids()). Batch prints can be sorted
 * by attendee name, ticket type, order or ticket number (SORTS) and every template has a PDF preview with
 * sample data (preview_pdf()).
 */
namespace PassCaptain\Printing;

use PassCaptain\Audit;
use PassCaptain\Reports\Scope;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Templates {
	const KINDS = array( 'ticket', 'badge', 'nametag', 'wristband', 'label_3x8', 'label_2x7', 'badge_4up' );

	/** A686: sort orders of batch prints (attendee name is the default, as before). */
	const SORTS = array( 'name', 'type', 'order', 'ticket' );
	const PLACEHOLDERS = array( '{event}', '{date}', '{time}', '{location}', '{ticket_category}', '{attendee_name}', '{seat_label}', '{order}', '{help_code}', '{qr}', '{admission_notice}' );

	/** A685: design lines of a template body. */
	const DESIGN_KEYS = array( 'logo', 'accent', 'text' );

	/** A685: minimum contrast of template colours on white (WCAG 2.2 AA, normal text). */
	const MIN_CONTRAST = 4.5;

	/** @var array<int,array|null> Logos loaded in this request: media id => image (PdfWriter::load_image()). */
	private static $logos = array();

	public static function kind_labels() {
		return array(
			'ticket'    => __( 'Ticket (A4)', 'pass-captain' ),
			'badge'     => __( 'Badge (A6)', 'pass-captain' ),
			'nametag'   => __( 'Namensschild (90×55 mm, 10 pro A4)', 'pass-captain' ),
			'wristband' => __( 'Einlassband (250×25 mm)', 'pass-captain' ),
			'label_3x8' => __( 'Etiketten 70×37 mm (24 pro A4, z. B. Avery 3474)', 'pass-captain' ),
			'label_2x7' => __( 'Etiketten 99,1×38,1 mm (14 pro A4, z. B. Avery L7163)', 'pass-captain' ),
			'badge_4up' => __( 'Badges A6 (4 pro A4 mit Schnittrahmen)', 'pass-captain' ),
		);
	}

	/** A686: labels of the batch print sort orders. */
	public static function sort_labels() {
		return array(
			'name'   => __( 'Name der Teilnehmenden', 'pass-captain' ),
			'type'   => __( 'Ticketart, dann Name', 'pass-captain' ),
			'order'  => __( 'Bestellung', 'pass-captain' ),
			'ticket' => __( 'Ticketnummer', 'pass-captain' ),
		);
	}

	/** A686: ORDER BY expression of a sort order (aliases t, tt, a of the print queries). */
	public static function sort_sql( $sort ) {
		$name = "COALESCE(NULLIF(a.attendee_name,''),'~')";
		$map = array(
			'name'   => "$name,t.ticket_id",
			'type'   => "tt.name,$name,t.ticket_id",
			'order'  => 't.order_id,t.ticket_id',
			'ticket' => 't.ticket_id',
		);
		return $map[ $sort ] ?? $map['name'];
	}

	/**
	 * A686: sheet layouts with several labels per A4 page (millimetres): columns, rows, label width and height,
	 * left and top margin, horizontal and vertical pitch, text alignment, QR position (right of the text or
	 * below it), whether a thin cut frame is drawn (plain paper) or not (die-cut label sheets) and the inner
	 * padding (mm; labels keep 4 mm off the edge, which most printers cannot reach).
	 *
	 * @return array<string,array{cols:int,rows:int,w:float,h:float,left:float,top:float,pitch_x:float,pitch_y:float,align:string,qr:string,frame:bool,pad:float}>
	 */
	public static function grids() {
		return array(
			'nametag'   => array( 'cols' => 2, 'rows' => 5, 'w' => 90, 'h' => 55, 'left' => 15, 'top' => 11, 'pitch_x' => 90, 'pitch_y' => 55, 'align' => 'center', 'qr' => 'right', 'frame' => true, 'pad' => 2.8222 ),
			'label_3x8' => array( 'cols' => 3, 'rows' => 8, 'w' => 70, 'h' => 37, 'left' => 0, 'top' => 0.5, 'pitch_x' => 70, 'pitch_y' => 37, 'align' => 'left', 'qr' => 'right', 'frame' => false, 'pad' => 3.5 ),
			'label_2x7' => array( 'cols' => 2, 'rows' => 7, 'w' => 99.1, 'h' => 38.1, 'left' => 4.65, 'top' => 15.15, 'pitch_x' => 101.6, 'pitch_y' => 38.1, 'align' => 'left', 'qr' => 'right', 'frame' => false, 'pad' => 4 ),
			'badge_4up' => array( 'cols' => 2, 'rows' => 2, 'w' => 105, 'h' => 148.5, 'left' => 0, 'top' => 0, 'pitch_x' => 105, 'pitch_y' => 148.5, 'align' => 'center', 'qr' => 'flow', 'frame' => true, 'pad' => 6 ),
		);
	}

	/** A686: items per printed page of a layout (1 for one page per ticket). */
	public static function per_page( $kind ) {
		$grid = self::grids()[ $kind ] ?? null;
		return $grid ? $grid['cols'] * $grid['rows'] : 1;
	}

	public static function default_body( $kind ) {
		$bodies = array(
			'ticket'    => "*22|{event}\n14|{ticket_category}\n12|{date}\n12|{location}\n12|{attendee_name}\n12|{seat_label}\n*11|{admission_notice}\n{qr}\n*14|{help_code}",
			'badge'     => "*20|{attendee_name}\n12|{ticket_category}\n11|{event}\n10|{date}\n{qr}",
			'nametag'   => "*16|{attendee_name}\n9|{ticket_category}\n8|{event}\n{qr}",
			'wristband' => "*11|{event}\n8|{date} · {ticket_category}\n8|{attendee_name} {seat_label}\n{qr}",
			'label_3x8' => "*10|{attendee_name}\n8|{ticket_category}\n7|{event}\n{qr}",
			'label_2x7' => "*13|{attendee_name}\n9|{ticket_category}\n8|{event} · {date}\n{qr}",
			'badge_4up' => "*20|{attendee_name}\n12|{ticket_category}\n11|{event}\n10|{date}\n{qr}",
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
			if ( '' === $line || '@' === substr( $line, 0, 1 ) ) { continue; } // A685: design lines are no content.
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
		// A686: answers to participant fields ({field:<key>}).
		$fields = (array) ( $values['fields'] ?? array() );
		$text = (string) preg_replace_callback( '/\{field:([a-z0-9_-]{1,64})\}/i', static function ( $m ) use ( $fields ) { return sanitize_text_field( (string) ( $fields[ strtolower( $m[1] ) ] ?? '' ) ); }, $text );
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
		return (string) preg_replace( '/^[\s·\-|,\/]+|[\s·\-|,\/]+$/u', '', $text );
	}

	/**
	 * A685: design of a template body (its "@" lines): logo (media id, 0 = none), accent and text colour
	 * (#rrggbb or '' for black). Unknown or malformed design lines are ignored.
	 *
	 * @return array{logo:int,accent:string,text:string}
	 */
	public static function design( $body ) {
		$design = array( 'logo' => 0, 'accent' => '', 'text' => '' );
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $body ) as $line ) {
			if ( ! preg_match( '/^@(logo|accent|text)\|\s*(.+?)\s*$/i', trim( $line ), $m ) ) { continue; }
			$key = strtolower( $m[1] );
			if ( 'logo' === $key ) {
				$design['logo'] = absint( $m[2] );
			} elseif ( preg_match( '/^#?([0-9a-f]{6})$/i', $m[2], $c ) ) {
				$design[ $key ] = '#' . strtolower( $c[1] );
			}
		}
		return $design;
	}

	/**
	 * A685: splits a body into its content lines and its design (for the edit form).
	 *
	 * @return array{0:string,1:array{logo:int,accent:string,text:string}}
	 */
	public static function split( $body ) {
		$content = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $body ) as $line ) {
			if ( '@' !== substr( trim( $line ), 0, 1 ) ) { $content[] = $line; }
		}
		return array( trim( implode( "\n", $content ) ), self::design( $body ) );
	}

	/** A685: content lines plus design lines (empty values are left out). */
	public static function compose( $content, array $design ) {
		$lines = array();
		$design = array_merge( array( 'logo' => 0, 'accent' => '', 'text' => '' ), $design );
		if ( absint( $design['logo'] ) ) { $lines[] = '@logo|' . absint( $design['logo'] ); }
		foreach ( array( 'accent', 'text' ) as $key ) {
			if ( preg_match( '/^#?([0-9a-f]{6})$/i', trim( (string) $design[ $key ] ), $m ) ) { $lines[] = '@' . $key . '|#' . strtolower( $m[1] ); }
		}
		list( $content ) = self::split( (string) $content );
		return implode( "\n", array_merge( $lines, '' !== $content ? array( $content ) : array() ) );
	}

	/** A685: WCAG contrast ratio of two #rrggbb colours (1 … 21). */
	public static function contrast( $a, $b = '#ffffff' ) {
		$lum = static function ( $hex ) {
			$rgb = PdfWriter::rgb( (string) $hex );
			if ( ! $rgb ) { return 0.0; }
			$lin = array_map( static function ( $c ) { return $c <= 0.04045 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 ); }, $rgb );
			return 0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2];
		};
		$l1 = $lum( $a );
		$l2 = $lum( $b );
		return round( ( max( $l1, $l2 ) + 0.05 ) / ( min( $l1, $l2 ) + 0.05 ), 2 );
	}

	/**
	 * A685: problems of a body's design: colours below MIN_CONTRAST on white, a logo that is no readable
	 * JPEG/PNG of the media library, and text smaller than 6 pt. Empty when everything is fine.
	 *
	 * @return string[] Messages.
	 */
	public static function design_problems( $body ) {
		$design = self::design( $body );
		$problems = array();
		$names = array( 'accent' => __( 'Akzentfarbe', 'pass-captain' ), 'text' => __( 'Textfarbe', 'pass-captain' ) );
		foreach ( $names as $key => $label ) {
			if ( '' === $design[ $key ] ) { continue; }
			$ratio = self::contrast( $design[ $key ] );
			if ( $ratio < self::MIN_CONTRAST ) {
				/* translators: 1: colour name, 2: colour value, 3: contrast ratio, 4: minimum ratio */
				$problems[] = sprintf( __( '%1$s %2$s hat auf Weiß nur einen Kontrast von %3$s:1 (mindestens %4$s:1). Bitte eine dunklere Farbe wählen.', 'pass-captain' ), $label, $design[ $key ], number_format( $ratio, 1, ',', '' ), number_format( self::MIN_CONTRAST, 1, ',', '' ) );
			}
		}
		if ( $design['logo'] && ! self::logo( $design['logo'] ) ) {
			$problems[] = __( 'Das Logo muss ein JPEG- oder PNG-Bild aus der Mediathek sein (höchstens 1 MB).', 'pass-captain' );
		}
		foreach ( self::parse( $body ) as $line ) {
			if ( ! $line['qr'] && $line['size'] < 6 ) {
				$problems[] = __( 'Schrift unter 6 pt ist kaum lesbar. Bitte mindestens 6 pt verwenden (für Tickets 10 pt und mehr).', 'pass-captain' );
				break;
			}
		}
		return $problems;
	}

	/** A685: logo image of a media id (PdfWriter::load_image(), cached per request) or null. */
	public static function logo( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id ) { return null; }
		if ( ! array_key_exists( $attachment_id, self::$logos ) ) {
			$file = function_exists( 'get_attached_file' ) ? (string) get_attached_file( $attachment_id ) : '';
			/**
			 * A685: file of a print template logo (media id); '' = no logo.
			 *
			 * @param string $file          Local file.
			 * @param int    $attachment_id Media id.
			 */
			$file = (string) apply_filters( 'wpcap_print_logo_file', $file, $attachment_id );
			self::$logos[ $attachment_id ] = PdfWriter::load_image( $file );
		}
		return self::$logos[ $attachment_id ];
	}

	/** Forgets loaded logos (tests, after a media file changed). */
	public static function reset_logos() {
		self::$logos = array();
	}

	/**
	 * A685: accessible starter templates – large type (ticket text 13 pt and more, names on badges 20 pt and
	 * more), strong contrast, the essential details first and the help code under the QR code.
	 *
	 * @return array<string,array{label:string,kind:string,body:string,description:string}>
	 */
	public static function starters() {
		return array(
			'ticket_clear'  => array(
				'label'       => __( 'Ticket „Klar“', 'pass-captain' ),
				'kind'        => 'ticket',
				'description' => __( 'Schwarz auf Weiß, große Schrift, Datum und Ort direkt unter dem Eventnamen.', 'pass-captain' ),
				'body'        => "*24|{event}\n*16|{date}\n14|{location}\n14|{ticket_category}\n14|{attendee_name}\n14|{seat_label}\n*13|{admission_notice}\n{qr}\n*16|{help_code}",
			),
			'ticket_large'  => array(
				'label'       => __( 'Ticket „Großdruck“', 'pass-captain' ),
				'kind'        => 'ticket',
				'description' => __( 'Für Menschen mit Sehbeeinträchtigung: alle Angaben ab 16 pt, Kernangaben fett.', 'pass-captain' ),
				'body'        => "*30|{event}\n*20|{date}\n*18|{location}\n18|{ticket_category}\n*18|{attendee_name}\n*18|{seat_label}\n*16|{admission_notice}\n{qr}\n*20|{help_code}",
			),
			'ticket_accent' => array(
				'label'       => __( 'Ticket „Akzent“', 'pass-captain' ),
				'kind'        => 'ticket',
				'description' => __( 'Überschriften in Dunkelblau (Kontrast über 10:1 auf Weiß); das Logo in der Vorlage ergänzen.', 'pass-captain' ),
				'body'        => "@accent|#1f3a68\n@text|#1a1a1a\n*24|{event}\n*15|{date}\n13|{location}\n13|{ticket_category}\n13|{attendee_name}\n13|{seat_label}\n*13|{admission_notice}\n{qr}\n*15|{help_code}",
			),
			'badge_large'   => array(
				'label'       => __( 'Badge „Name groß“', 'pass-captain' ),
				'kind'        => 'badge',
				'description' => __( 'Name in 26 pt fett, auf Abstand lesbar; Ticketart darunter.', 'pass-captain' ),
				'body'        => "*26|{attendee_name}\n*14|{ticket_category}\n12|{event}\n{qr}",
			),
			'nametag_large' => array(
				'label'       => __( 'Namensschild „Name groß“', 'pass-captain' ),
				'kind'        => 'nametag',
				'description' => __( 'Name in 20 pt fett, Ticketart in 11 pt.', 'pass-captain' ),
				'body'        => "*20|{attendee_name}\n11|{ticket_category}\n{qr}",
			),
		);
	}

	/** A685: creates a template from a starter (permission checked like save_template()). */
	public static function create_from_starter( $starter, $name, $owner_event_id = 0 ) {
		$starters = self::starters();
		if ( ! isset( $starters[ $starter ] ) ) {
			return new \WP_Error( 'wpcap_print_starter', __( 'Unbekannte Startvorlage.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$name = '' !== trim( (string) $name ) ? $name : $starters[ $starter ]['label'];
		return self::save_template( 0, $name, $starters[ $starter ]['kind'], $starters[ $starter ]['body'], $owner_event_id );
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
		$pdf = new PdfWriter();
		return self::draw( $pdf, $kind, $items, $body )->output( self::title( $kind ) );
	}

	/** Like render(), streamed page by page into $file (0600). False when writing failed. */
	public static function render_to_file( $file, $kind, array $items, $body = '' ) {
		$pdf = new PdfWriter( (string) $file );
		return self::draw( $pdf, $kind, $items, $body )->finish( self::title( $kind ) );
	}

	/** Draws all items into $pdf (document language: the current locale). */
	private static function draw( PdfWriter $pdf, $kind, array $items, $body ) {
		if ( function_exists( 'determine_locale' ) ) { $pdf->set_language( determine_locale() ); }
		return self::pages( in_array( $kind, self::KINDS, true ) ? $kind : 'ticket', $items, $body, $pdf );
	}

	/** Document title of a layout. */
	public static function title( $kind ) {
		return 'nametag' === $kind ? __( 'Namensschilder', 'pass-captain' ) : (string) ( self::kind_labels()[ $kind ] ?? '' );
	}

	/**
	 * Lays out $items on pages (render() without the PDF file around them; the batch job appends
	 * the pages block by block, A427). Name tags: 10 per page.
	 */
	public static function pages( $kind, array $items, $body = '', ?PdfWriter $pdf = null ) {
		$kind = in_array( $kind, self::KINDS, true ) ? $kind : 'ticket';
		$body = '' !== $body ? $body : self::default_body( $kind );
		$lines = self::parse( $body );
		$design = self::design( $body );
		$logo = $design['logo'] ? self::logo( $design['logo'] ) : null;
		if ( ! $pdf ) {
			$pdf = new PdfWriter();
			if ( function_exists( 'determine_locale' ) ) { $pdf->set_language( determine_locale() ); }
		}
		$mm = PdfWriter::MM;
		$grid = self::grids()[ $kind ] ?? null;
		if ( $grid ) {
			// Name tags and label sheets (A686): several labels per A4 page, row by row.
			$w = $grid['w'] * $mm;
			$h = $grid['h'] * $mm;
			$per = $grid['cols'] * $grid['rows'];
			$top = 297 * $mm - $grid['top'] * $mm;
			foreach ( array_values( $items ) as $i => $item ) {
				$slot = $i % $per;
				if ( 0 === $slot ) { $pdf->add_page( 210 * $mm, 297 * $mm ); }
				$x = $grid['left'] * $mm + ( $slot % $grid['cols'] ) * $grid['pitch_x'] * $mm;
				$y = $top - intdiv( $slot, $grid['cols'] ) * $grid['pitch_y'] * $mm - $h;
				if ( $grid['frame'] ) { $pdf->rect( $x, $y, $w, $h, false, 0.8 ); }
				// A685: logo in the top left corner of the label.
				$logo_box = min( 9 * $mm, $h * 0.2 );
				$pad = $grid['pad'] * $mm;
				$logo_h = $logo && $pdf->image( $x + $pad, $y + $h - $pad - $logo_box, 30 * $mm, $logo_box, $logo, 'left' ) ? $logo_box + $mm : 0;
				self::cell( $pdf, $lines, $item, $x, $y, $w, $h - $logo_h, $grid['align'], $grid['qr'], $pad, false, $design );
			}
			return $pdf;
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
				// A685: logo right of the adhesive tab.
				$logo_w = $logo && $pdf->image( 42 * $mm, 2 * $mm, 24 * $mm, $ph - 4 * $mm, $logo, 'left' ) ? 26 * $mm : 0;
				self::cell( $pdf, $lines, $item, 40 * $mm + $logo_w, 0, $pw - 40 * $mm - $logo_w, $ph, $align, $qr_mode, 8, false, $design );
				continue;
			}
			if ( 'ticket' === $kind ) {
				// A330: header (e.g. site name) and footer (e.g. "only valid once") of ticket PDFs.
				if ( '' !== (string) ( $item['_title'] ?? '' ) ) { $pdf->text( $pad, $ph - 30, 11, PdfWriter::fit( (string) $item['_title'], 11, $pw - 2 * $pad ) ); }
				$footer = '' !== (string) ( $item['_footer'] ?? '' ) ? array_slice( PdfWriter::wrap( (string) $item['_footer'], 8, $pw - 2 * $pad ), 0, 3 ) : array();
				foreach ( $footer as $i => $footer_line ) { $pdf->text( $pad, 22 + 10 * ( count( $footer ) - 1 - $i ), 8, $footer_line ); }
			}
			// A685: logo at the top (ticket: left, badge: centred).
			$logo_h = 0;
			if ( $logo ) {
				$box = 'ticket' === $kind ? array( 60 * $mm, 16 * $mm, 'left' ) : array( $pw - 2 * $pad, 14 * $mm, 'center' );
				if ( $pdf->image( $pad, $ph - $pad - $box[1], $box[0], $box[1], $logo, $box[2] ) ) { $logo_h = $box[1] + 3 * $mm; }
			}
			self::cell( $pdf, $lines, $item, $pad, $pad, $pw - 2 * $pad, $ph - 2 * $pad - $logo_h, $align, $qr_mode, 0, 'ticket' === $kind, $design );
		}
		return $pdf;
	}

	/** Lays out one item inside a box. $qr_mode: flow (below the text) or right (square at the right edge). */
	private static function cell( PdfWriter $pdf, array $lines, array $item, $x, $y, $w, $h, $align, $qr_mode, $inner = 8, $full = false, array $design = array() ) {
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
				$drawn = $pdf->qr( $qx, $cursor - $q, $q, $item['qr'] );
				if ( $drawn ) { $cursor -= $q + 6; }
				if ( $full ) {
					// Ticket layout: the whole code (also without QR encoder), for typing it in by hand.
					// The ticket code itself ('code'), not a signed QR value (Security\SignedQr).
					foreach ( str_split( (string) ( $item['code'] ?? $item['qr'] ), 32 ) as $part ) {
						$pdf->text( $x + $w / 2, $cursor - 8, 8, $part, false, 'center' );
						$cursor -= 10;
					}
					$cursor -= 4;
				} elseif ( $drawn ) {
					$pdf->text( $x + $w / 2, $cursor - 8, 7, substr( (string) ( $item['code'] ?? $item['qr'] ), 0, 16 ) . '…', false, 'center' );
					$cursor -= 14;
				}
				continue;
			}
			$text = self::fill( $line['text'], $item );
			if ( '' === $text ) { continue; }
			$size = $line['size'];
			if ( $full && false !== strpos( $line['text'], '{help_code}' ) && '' !== (string) ( $item['help_code'] ?? '' ) ) {
				$caption = max( 7.0, $size * 0.6 );
				if ( $cursor - $caption * 1.25 - $size * 1.25 >= $y - 0.5 ) {
					foreach ( PdfWriter::wrap( \PassCaptain\HelpCode::caption(), $caption, $text_w ) as $caption_line ) {
						$cursor -= $caption * 1.2;
						$pdf->text( $anchor, $cursor, $caption, $caption_line, false, 'center' === $align ? 'center' : 'left' );
					}
					$cursor -= $caption * 0.3;
				}
			}
			// Ticket layout (audit R2 #8): long texts such as event names wrap onto a second line instead of
			// being cut; labels (badges, name tags) keep one line.
			$parts = $full ? PdfWriter::wrap( $text, $size, $text_w, $line['bold'], 2 ) : array( PdfWriter::fit( $text, $size, $text_w, $line['bold'] ) );
			if ( $cursor - $size * 1.25 < $y - 0.5 ) { break; }
			// A685: bold lines in the accent colour, the others in the text colour (both default black).
			$pdf->set_color( $line['bold'] && '' !== (string) ( $design['accent'] ?? '' ) ? $design['accent'] : ( (string) ( $design['text'] ?? '' ) ?: null ) );
			foreach ( $parts as $index => $part ) {
				if ( $index > 0 && $cursor - $size * 1.25 < $y - 0.5 ) { break; }
				$cursor -= $size * 1.2;
				$pdf->text( $anchor, $cursor, $size, $part, $line['bold'], 'center' === $align ? 'center' : 'left' );
			}
			$pdf->set_color( null );
			$cursor -= $size * 0.25;
		}
	}

	/* ------------------------------------------------------------------ storage */

	/**
	 * Templates visible to the current user: global ones (owner_event_id 0) and those of events the user
	 * manages. Global templates can be used by every event manager but only edited by administrators.
	 */
	public static function all_templates( $kind = '' ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$scope = Scope::event_ids( 'wpcap_manage_events' );
		// Owner events: all (null scope), else global templates (0) and the managed events.
		$all_owners = null === $scope ? 1 : 0;
		$owners = array_merge( array( 0 ), array_values( array_map( 'absint', (array) $scope ) ) );
		if ( '' !== $kind ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE kind=%s AND status='active' AND (%d=1 OR owner_event_id IN (" . implode( ',', array_fill( 0, count( $owners ), '%d' ) ) . ')) ORDER BY name', array_merge( array( $p . 'wpcap_print_templates', $kind, $all_owners ), $owners ) ) );
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE status='active' AND (%d=1 OR owner_event_id IN (" . implode( ',', array_fill( 0, count( $owners ), '%d' ) ) . ')) ORDER BY kind,name', array_merge( array( $p . 'wpcap_print_templates', $all_owners ), $owners ) ) );
	}

	public static function get_template( $template_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE template_id=%d', $wpdb->prefix . 'wpcap_print_templates', absint( $template_id ) ) );
	}

	/** Events a template is selected for. */
	public static function assigned_events( $template_id ) {
		global $wpdb;
		return array_map( 'absint', $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT event_id FROM %i WHERE template_id=%d', $wpdb->prefix . 'wpcap_print_event_templates', absint( $template_id ) ) ) );
	}

	/**
	 * Whether the current user may create/edit a template of this owner. Global templates (owner 0):
	 * administrators only. Event templates: event managers of that event, and of every event the
	 * template is assigned to.
	 */
	public static function can_edit( $owner_event_id, $template_id = 0 ) {
		if ( ! $owner_event_id ) { return current_user_can( 'manage_options' ); }
		if ( ! Scope::can_event( $owner_event_id, 'wpcap_manage_events' ) ) { return false; }
		foreach ( $template_id ? self::assigned_events( $template_id ) : array() as $event_id ) {
			if ( ! Scope::can_event( $event_id, 'wpcap_manage_events' ) ) { return false; }
		}
		return true;
	}

	/**
	 * Creates or updates a template (permission checked for the current user).
	 *
	 * @param int $owner_event_id 0 = global template (administrators), otherwise the event it belongs to.
	 *                            Ignored on update: the owner of a template never changes.
	 * @return int|\WP_Error template id.
	 */
	public static function save_template( $template_id, $name, $kind, $body, $owner_event_id = 0 ) {
		global $wpdb;
		$name = mb_substr( sanitize_text_field( (string) $name ), 0, 190 );
		$body = self::sanitize_body( $body );
		if ( '' === $name || ! in_array( $kind, self::KINDS, true ) || '' === $body ) {
			return new \WP_Error( 'wpcap_print_template_invalid', __( 'Bitte Name, Layout und Inhalt angeben.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$problems = self::design_problems( $body );
		if ( $problems ) {
			return new \WP_Error( 'wpcap_print_template_design', implode( ' ', $problems ), array( 'status' => 400 ) );
		}
		$data = array( 'name' => $name, 'kind' => $kind, 'body' => $body, 'status' => 'active', 'updated_at' => \PassCaptain\Clock::mysql() );
		$forbidden = new \WP_Error( 'wpcap_forbidden', __( 'Keine Berechtigung für diese Vorlage.', 'pass-captain' ), array( 'status' => 403 ) );
		if ( $template_id ) {
			$template = self::get_template( $template_id );
			if ( ! $template ) { return new \WP_Error( 'wpcap_print_template_missing', __( 'Vorlage nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
			if ( ! self::can_edit( (int) $template->owner_event_id, (int) $template->template_id ) ) { return $forbidden; }
			if ( $template->kind !== $kind && self::assigned_events( $template_id ) ) {
				return new \WP_Error( 'wpcap_print_template_kind', __( 'Das Layout einer zugeordneten Vorlage kann nicht geändert werden.', 'pass-captain' ), array( 'status' => 409 ) );
			}
			$wpdb->update( $wpdb->prefix . 'wpcap_print_templates', $data, array( 'template_id' => absint( $template_id ) ) );
			Audit::record( 'print_template_saved', 'print_template', absint( $template_id ) );
			return absint( $template_id );
		}
		$owner_event_id = absint( $owner_event_id );
		if ( ! self::can_edit( $owner_event_id ) ) { return $forbidden; }
		$data['owner_event_id'] = $owner_event_id;
		if ( false === $wpdb->insert( $wpdb->prefix . 'wpcap_print_templates', $data ) ) {
			return new \WP_Error( 'wpcap_print_template_save', __( 'Die Vorlage konnte nicht gespeichert werden.', 'pass-captain' ), array( 'status' => 500 ) );
		}
		$new_id = (int) $wpdb->insert_id;
		Audit::record( 'print_template_saved', 'print_template', $new_id );
		return $new_id;
	}

	/** Selects the template per event and layout (0 = built-in default). Global or the event's own templates only. */
	public static function assign( $event_id, $kind, $template_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		if ( ! in_array( $kind, self::KINDS, true ) ) { return new \WP_Error( 'wpcap_print_kind', __( 'Unbekanntes Layout.', 'pass-captain' ) ); }
		if ( ! Scope::can_event( $event_id, 'wpcap_manage_events' ) ) { return new \WP_Error( 'wpcap_forbidden', __( 'Keine Berechtigung.', 'pass-captain' ), array( 'status' => 403 ) ); }
		$template_id = absint( $template_id );
		if ( $template_id ) {
			$template = self::get_template( $template_id );
			if ( ! $template || $template->kind !== $kind ) { return new \WP_Error( 'wpcap_print_template_kind', __( 'Die Vorlage passt nicht zum Layout.', 'pass-captain' ) ); }
			if ( (int) $template->owner_event_id && (int) $template->owner_event_id !== absint( $event_id ) ) {
				return new \WP_Error( 'wpcap_print_template_owner', __( 'Diese Vorlage gehört zu einem anderen Event.', 'pass-captain' ), array( 'status' => 403 ) );
			}
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE event_id=%d AND kind=%s', $p . 'wpcap_print_event_templates', absint( $event_id ), $kind ) );
		if ( $template_id ) {
			$wpdb->insert( "{$p}wpcap_print_event_templates", array( 'event_id' => absint( $event_id ), 'kind' => $kind, 'template_id' => $template_id ) );
		}
		return true;
	}

	/** Body used for an event and layout. */
	public static function body_for_event( $event_id, $kind ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$body = $wpdb->get_var( $wpdb->prepare( "SELECT t.body FROM %i et INNER JOIN %i t ON t.template_id=et.template_id WHERE et.event_id=%d AND et.kind=%s AND t.status='active'", $p . 'wpcap_print_event_templates', $p . 'wpcap_print_templates', absint( $event_id ), $kind ) );
		return $body ? (string) $body : self::default_body( $kind );
	}

	/* ------------------------------------------------------------------ data */

	/**
	 * Tickets of an occurrence with print context (no codes). With $with_passes (default) the holders
	 * of passes linked to the occurrence are included and passes are not listed on their anchor
	 * occurrence unless it is linked (A573, Reports\Attendance::ticket_condition()); the occurrence
	 * columns are those of $occurrence_id. Without it: the tickets whose ticket type belongs to the
	 * occurrence (batch printing of admission media, see ticket_refs_for_occurrence()).
	 */
	public static function tickets_for_occurrence( $occurrence_id, array $statuses = array( 'valid', 'checked_in' ), $with_passes = true, $sort = 'name' ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$statuses = array_values( array_intersect( $statuses, array( 'valid', 'checked_in', 'revoked' ) ) );
		$in = $statuses ? "'" . implode( "','", $statuses ) . "'" : "'valid'";
		$occurrence_id = absint( $occurrence_id );
		$which = $with_passes ? \PassCaptain\Reports\Attendance::ticket_condition( $occurrence_id ) : 'tt.occurrence_id=' . $occurrence_id;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT t.ticket_id,t.public_id,t.status,t.order_id,t.ticket_type_id,t.attendee_email,t.transfer_count,tt.name AS ticket_name,o.occurrence_id,o.event_id,o.starts_at,o.timezone,o.location,e.name AS event_name,ord.public_id AS order_public_id,ord.customer_email,COALESCE(a.attendee_name,'') AS attendee_name
			FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=%d INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id INNER JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id LEFT JOIN {$p}wpcap_ticket_assignments a ON a.ticket_id=t.ticket_id
			WHERE $which AND t.status IN ($in) ORDER BY " . self::sort_sql( $sort ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- fixed status list, integer condition, sort expression from a fixed map (A686).
			$occurrence_id
		) );
	}

	/**
	 * One block of tickets_for_occurrence() in the same order, read after a position (keyset, A477):
	 * large events are listed block by block instead of loading every ticket at once.
	 *
	 * @param array{0:string,1:int}|null $after Sort key and ticket id of the last row of the previous
	 *                                          block (null = from the start).
	 * @return object[] Rows as tickets_for_occurrence() plus sort_key.
	 */
	public static function tickets_for_occurrence_after( $occurrence_id, array $statuses, $after, $limit ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$statuses = array_values( array_intersect( $statuses, array( 'valid', 'checked_in', 'revoked' ) ) );
		$in = $statuses ? "'" . implode( "','", $statuses ) . "'" : "'valid'";
		$key = "COALESCE(NULLIF(a.attendee_name,''),'~')";
		// A573: holders of linked passes belong to every linked occurrence (same rule as tickets_for_occurrence()).
		$which = \PassCaptain\Reports\Attendance::ticket_condition( absint( $occurrence_id ) );
		$where = '';
		$args = array( absint( $occurrence_id ) );
		if ( is_array( $after ) ) {
			$where = " AND ($key > %s OR ($key = %s AND t.ticket_id > %d))";
			array_push( $args, (string) $after[0], (string) $after[0], (int) $after[1] );
		}
		$args[] = max( 1, (int) $limit );
		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT t.ticket_id,t.public_id,t.status,t.order_id,t.ticket_type_id,t.attendee_email,t.transfer_count,tt.name AS ticket_name,o.occurrence_id,o.event_id,o.starts_at,o.timezone,o.location,e.name AS event_name,ord.public_id AS order_public_id,ord.customer_email,COALESCE(a.attendee_name,'') AS attendee_name,$key AS sort_key
			FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=%d INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id INNER JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id LEFT JOIN {$p}wpcap_ticket_assignments a ON a.ticket_id=t.ticket_id
			WHERE $which AND t.status IN ($in)$where ORDER BY $key,t.ticket_id LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed status list, integer condition and sort expression, values as placeholders.
			$args
		) );
	}

	/**
	 * All tickets of an occurrence in list order, one block at a time (A477): yields arrays of at most
	 * $chunk rows (see tickets_for_occurrence_after()).
	 *
	 * @return \Generator<int, object[]>
	 */
	public static function ticket_blocks( $occurrence_id, array $statuses, $chunk ) {
		$after = null;
		do {
			$rows = self::tickets_for_occurrence_after( $occurrence_id, $statuses, $after, $chunk );
			if ( $rows ) {
				$last = end( $rows );
				$after = array( (string) $last->sort_key, (int) $last->ticket_id );
				yield $rows;
			}
		} while ( count( $rows ) >= $chunk );
	}

	/** Number of tickets of an occurrence with the given statuses (without loading them). */
	public static function count_for_occurrence( $occurrence_id, array $statuses ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$statuses = array_values( array_intersect( $statuses, array( 'valid', 'checked_in', 'revoked' ) ) );
		$in = $statuses ? "'" . implode( "','", $statuses ) . "'" : "'valid'";
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id WHERE tt.occurrence_id=%d AND t.status IN ($in)", absint( $occurrence_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed status list.
	}

	/**
	 * Print order of an occurrence's tickets without names or codes (batch job snapshot, A427):
	 * ticket id, current code hash and whether someone other than the buyer holds the ticket
	 * (transferred, or assigned/sent to an attendee e-mail other than the buyer's).
	 *
	 * @return object[] {ticket_id, token_hash, status, held_by_other}
	 */
	public static function ticket_refs_for_occurrence( $occurrence_id, array $statuses, $sort = 'name' ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$statuses = array_values( array_intersect( $statuses, array( 'valid', 'checked_in', 'revoked' ) ) );
		$in = $statuses ? "'" . implode( "','", $statuses ) . "'" : "'valid'";
		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT t.ticket_id,t.token_hash,t.status,IF(t.transfer_count>0 OR (t.attendee_email<>'' AND t.attendee_email<>ord.customer_email),1,0) AS held_by_other
			FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id LEFT JOIN {$p}wpcap_ticket_assignments a ON a.ticket_id=t.ticket_id
			WHERE tt.occurrence_id=%d AND t.status IN ($in) ORDER BY " . self::sort_sql( $sort ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- fixed status list, sort expression from a fixed map (A686).
			absint( $occurrence_id )
		) );
	}

	/**
	 * Print rows (as tickets_for_occurrence()) of the given tickets, keyed by ticket id, with the
	 * current token_hash.
	 *
	 * @param int[] $ticket_ids Tickets.
	 * @return array<int,object>
	 */
	public static function tickets_by_ids( array $ticket_ids ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$ticket_ids = array_values( array_filter( array_map( 'absint', $ticket_ids ) ) );
		if ( ! $ticket_ids ) { return array(); }
		$out = array();
		foreach ( (array) $wpdb->get_results(
			"SELECT t.ticket_id,t.public_id,t.status,t.token_hash,t.order_id,t.ticket_type_id,t.attendee_email,t.transfer_count,tt.name AS ticket_name,o.occurrence_id,o.event_id,o.starts_at,o.timezone,o.location,e.name AS event_name,ord.public_id AS order_public_id,ord.customer_email,COALESCE(a.attendee_name,'') AS attendee_name
			FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id INNER JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id LEFT JOIN {$p}wpcap_ticket_assignments a ON a.ticket_id=t.ticket_id
			WHERE t.ticket_id IN (" . implode( ',', $ticket_ids ) . ')' // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		) as $row ) {
			$out[ (int) $row->ticket_id ] = $row;
		}
		return $out;
	}

	/**
	 * Placeholder values for a ticket row (see tickets_for_occurrence / Orders::tickets_for_order).
	 *
	 * @param string|null $help Help code to print; null derives it from $token (only for the ticket's current code).
	 * @param string|null $qr   QR value to print; null derives it from $token (signed when Security\SignedQr is on).
	 */
	public static function values( $row, $token = '', $help = null, $qr = null ) {
		$row = (object) $row;
		$seat = (string) apply_filters( 'wpcap_ticket_seat_label', '', (int) ( $row->ticket_id ?? 0 ), (array) $row );
		return array(
			'event'           => (string) ( $row->event_name ?? '' ),
			'date'            => \PassCaptain\Frontend\Format::local( $row->starts_at ?? '', $row->timezone ?? 'UTC' ),
			'time'            => Scope::local( $row->starts_at ?? '', $row->timezone ?? 'UTC', 'H:i' ),
			'location'        => (string) ( $row->location ?? '' ),
			'ticket_category' => (string) ( $row->ticket_name ?? '' ),
			'attendee_name'   => (string) ( $row->attendee_name ?? '' ),
			'seat_label'      => $seat,
			'order'           => substr( (string) ( $row->order_public_id ?? '' ), 0, 8 ),
			'help_code'       => null !== $help ? (string) $help : ( '' !== (string) $token ? \PassCaptain\HelpCode::for_ticket( (int) ( $row->ticket_id ?? 0 ), (string) $token ) : '' ),
			'qr'              => null !== $qr ? (string) $qr : ( '' !== (string) $token ? \PassCaptain\Security\SignedQr::qr_value( (string) $token ) : '' ),
			'code'            => (string) $token,
			'fields'          => (array) ( $row->_fields ?? array() ),
		);
	}

	/**
	 * A686: adds the participant field answers of the rows' tickets as `_fields` (field key => answer, one
	 * query for all rows); consents are left out. Keys of $rows are kept.
	 *
	 * @param object[] $rows Rows with ticket_id.
	 * @return object[]
	 */
	public static function attach_fields( array $rows ) {
		$answers = self::field_answers( array_map( static function ( $r ) { return (int) ( $r->ticket_id ?? 0 ); }, $rows ) );
		foreach ( $rows as $row ) { $row->_fields = $answers[ (int) ( $row->ticket_id ?? 0 ) ] ?? array(); }
		return $rows;
	}

	/**
	 * A686: participant field answers per ticket (ticket_id => field key => answer), without consents.
	 *
	 * @param int[] $ticket_ids Tickets.
	 * @return array<int,array<string,string>>
	 */
	public static function field_answers( array $ticket_ids ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$ticket_ids = array_values( array_unique( array_filter( array_map( 'absint', $ticket_ids ) ) ) );
		$out = array();
		foreach ( array_chunk( $ticket_ids, 500 ) as $chunk ) {
			$rows = $wpdb->get_results( "SELECT pa.ticket_id,pf.field_key,pa.answer FROM {$p}wpcap_participant_answers pa INNER JOIN {$p}wpcap_participant_fields pf ON pf.field_id=pa.field_id WHERE pf.field_type<>'consent' AND pa.ticket_id IN (" . implode( ',', $chunk ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- integer list.
			foreach ( (array) $rows as $row ) { $out[ (int) $row->ticket_id ][ strtolower( (string) $row->field_key ) ] = (string) $row->answer; }
		}
		return $out;
	}

	/**
	 * A686: keys of the active participant fields of an event (or of all events the user manages for 0) that
	 * a template can print with {field:<key>}, key => label.
	 *
	 * @return array<string,string>
	 */
	public static function field_keys( $event_id = 0 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$scope = $event_id ? array( absint( $event_id ) ) : Scope::event_ids( 'wpcap_manage_events' );
		$where = null === $scope ? '' : ' AND o.event_id IN (' . ( $scope ? implode( ',', array_map( 'absint', $scope ) ) : '0' ) . ')';
		$rows = $wpdb->get_results( "SELECT DISTINCT pf.field_key,pf.label FROM {$p}wpcap_participant_fields pf INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=pf.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE pf.status='active' AND pf.field_type<>'consent'$where ORDER BY pf.field_key LIMIT 100" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		$out = array();
		foreach ( (array) $rows as $row ) {
			$key = strtolower( (string) $row->field_key );
			if ( preg_match( '/^[a-z0-9_-]{1,64}$/', $key ) && ! isset( $out[ $key ] ) ) { $out[ $key ] = (string) $row->label; }
		}
		return $out;
	}

	/**
	 * A686: sample values for the preview (no real ticket, no valid code): every {field:<key>} of the body
	 * shows its key.
	 */
	public static function sample_item( $body = '' ) {
		$fields = array();
		if ( preg_match_all( '/\{field:([a-z0-9_-]{1,64})\}/i', (string) $body, $m ) ) {
			foreach ( $m[1] as $key ) { $fields[ strtolower( $key ) ] = '‹' . strtolower( $key ) . '›'; }
		}
		return array(
			'event'            => __( 'Beispiel-Event', 'pass-captain' ),
			'date'             => '24.12.2026 19:30',
			'time'             => '19:30',
			'location'         => __( 'Großer Saal', 'pass-captain' ),
			'ticket_category'  => __( 'Stehplatz', 'pass-captain' ),
			'attendee_name'    => __( 'Erika Musterfrau', 'pass-captain' ),
			'seat_label'       => __( 'Reihe 3, Platz 12', 'pass-captain' ),
			'order'            => 'AB12CD34',
			'help_code'        => 'K7M2-Q9X4',
			'admission_notice' => __( 'Ab 18 Jahren, Ausweis mitbringen', 'pass-captain' ),
			'qr'               => 'VORSCHAU-' . str_repeat( '0', 55 ),
			'code'             => 'VORSCHAU-' . str_repeat( '0', 55 ),
			'fields'           => $fields,
		);
	}

	/**
	 * A686: PDF preview of a layout with sample data – one page (label sheets: a full sheet). The preview code
	 * is no ticket code; nothing is read from or written to tickets.
	 */
	public static function preview_pdf( $kind, $body ) {
		$kind = in_array( $kind, self::KINDS, true ) ? $kind : 'ticket';
		$body = '' !== trim( (string) $body ) ? (string) $body : self::default_body( $kind );
		return self::render( $kind, array_fill( 0, self::per_page( $kind ), self::sample_item( $body ) ), $body );
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
			$old_hash = (string) $wpdb->get_var( $wpdb->prepare( "SELECT token_hash FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_id=%d", $ticket_id ) );
			$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_tickets SET token_hash=%s WHERE ticket_id=%d AND status='valid'", hash( 'sha256', $token ), $ticket_id ) );
			if ( $updated ) {
				\PassCaptain\CodeHistory::replaced( (int) $ticket_id, $old_hash, 'print' ); // A543
				$out[ $ticket_id ] = $token;
				/** The ticket got a new code (old one invalid), e.g. so wallet passes can be refreshed. */
				do_action( 'wpcap_ticket_code_rotated', (int) $ticket_id, $token, 'pos_print' === $reason ? 'pos_print' : 'print' );
			}
		}
		if ( $out ) {
			Audit::record( 'ticket_codes_rotated', 'ticket', (int) array_key_first( $out ), array( 'count' => count( $out ), 'reason' => sanitize_key( $reason ) ) );
		}
		return $out;
	}

	/**
	 * Batch PDF for an occurrence without new codes (no QR code), rendered at once; Page::batch_request()
	 * uses it only up to BatchJob::sync_limit() tickets. Batches with new codes run as a background job
	 * (BatchJob, A427: codes are rotated only when the finished file is downloaded) or, without the job
	 * tables, through batch_pdf_with_codes() (codes committed only with the complete file).
	 */
	public static function batch_pdf( $occurrence_id, $kind, $sort = 'name' ) {
		$rows = self::attach_fields( self::tickets_for_occurrence( $occurrence_id, self::batch_statuses( $kind ), false, $sort ) );
		$items = array();
		foreach ( $rows as $row ) { $items[] = self::values( $row, '' ); }
		$event_id = Scope::occurrence_event( $occurrence_id );
		return self::render( $kind, $items, self::body_for_event( $event_id, $kind ) );
	}

	/** Maximum number of new codes per print job (filter wp_pass_captain_print_batch_limit_v1, default 500). */
	public static function batch_limit() {
		/**
		 * Maximum number of tickets that get new codes in one batch print with QR codes. Larger
		 * occurrences are printed in parts (field "ab Ticket Nr.").
		 *
		 * @param int $limit Default 500.
		 */
		return max( 1, (int) apply_filters( 'wp_pass_captain_print_batch_limit_v1', 500 ) );
	}

	/**
	 * True when a ticket's code never reached a customer: box office sale without buyer e-mail
	 * (placeholder address), never transferred, no holder e-mail. Only such tickets are printed with
	 * new codes by default; every other valid ticket is a customer ticket that would become invalid.
	 */
	public static function undelivered( $row ) {
		$row = (object) $row;
		$buyer = strtolower( (string) ( $row->customer_email ?? '' ) );
		$holder = (string) ( $row->attendee_email ?? '' );
		return 0 === strpos( $buyer, 'boxoffice+' ) && \PassCaptain\Privacy::is_placeholder_email( $buyer )
			&& 0 === (int) ( $row->transfer_count ?? 0 )
			&& ( ! is_email( $holder ) || \PassCaptain\Privacy::is_placeholder_email( $holder ) );
	}

	/**
	 * Valid tickets of an occurrence split into undelivered (box office without e-mail) and customer tickets.
	 *
	 * @return array{undelivered:object[],delivered:object[]}
	 */
	public static function code_candidates( $occurrence_id ) {
		$out = array( 'undelivered' => array(), 'delivered' => array() );
		foreach ( self::tickets_for_occurrence( $occurrence_id, array( 'valid' ), false ) as $row ) {
			$out[ self::undelivered( $row ) ? 'undelivered' : 'delivered' ][] = $row;
		}
		return $out;
	}

	/**
	 * Batch PDF with new ticket codes (QR) for an occurrence, written to $file.
	 *
	 * The new codes are stored in one transaction that only commits after the complete PDF has been
	 * written; any error, exception or abort (time/memory limit: the connection closes without COMMIT)
	 * rolls the codes back, so the previous codes stay valid (audit R2 #5/#11). At most batch_limit()
	 * tickets per job ($offset selects the part). Wallet passes are reissued after the commit through
	 * the job queue (wpcap_ticket_code_rotated, reason "print"). With $include_delivered the holders of
	 * customer tickets receive their new links by e-mail after the commit.
	 *
	 * @param int    $occurrence_id     Occurrence.
	 * @param string $kind              Layout.
	 * @param string $file              Target file (private temp directory).
	 * @param bool   $include_delivered Also customer tickets (their previous codes become invalid).
	 * @param int    $offset            First ticket (0-based) of this part.
	 * @return array{count:int,delivered:int,total:int,notified:int,notify_failed:int}|\WP_Error
	 */
	public static function batch_pdf_with_codes( $occurrence_id, $kind, $file, $include_delivered = false, $offset = 0 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$candidates = self::code_candidates( $occurrence_id );
		$rows = $include_delivered ? array_merge( $candidates['undelivered'], $candidates['delivered'] ) : $candidates['undelivered'];
		usort( $rows, static function ( $a, $b ) { return (int) $a->ticket_id <=> (int) $b->ticket_id; } );
		$total = count( $rows );
		$rows = array_slice( $rows, max( 0, (int) $offset ), self::batch_limit() );
		if ( ! $rows ) {
			return new \WP_Error( 'wpcap_print_nothing', $include_delivered || ! $candidates['delivered'] ? __( 'Für diesen Termin gibt es keine (weiteren) gültigen Tickets zum Drucken.', 'pass-captain' ) : __( 'Für diesen Termin gibt es keine Kassentickets ohne Zustellung. Kundentickets werden nur nach ausdrücklicher Bestätigung neu gedruckt.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$failed = static function ( $step, $message = '' ) use ( $wpdb, $file, $occurrence_id ) {
			$wpdb->query( 'ROLLBACK' );
			\PassCaptain\TempFiles::remove( $file );
			Audit::record( 'print_batch_codes_failed', 'occurrence', absint( $occurrence_id ), array( 'step' => sanitize_key( $step ) ) );
			return new \WP_Error( 'wpcap_print_codes_failed', '' !== $message ? $message : __( 'Der Druck mit neuen Codes ist fehlgeschlagen. Es wurden keine Ticketcodes geändert; die bisherigen Codes bleiben gültig.', 'pass-captain' ), array( 'status' => 503, 'step' => $step ) );
		};
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return $failed( 'start' ); }
		$ids = array_map( static function ( $r ) { return (int) $r->ticket_id; }, $rows );
		$tokens = array();
		$printable = array();
		try {
			$locked = $wpdb->get_results( $wpdb->prepare( "SELECT ticket_id,token_hash FROM %i WHERE status='valid' AND ticket_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY ticket_id FOR UPDATE', array_merge( array( $p . 'wpcap_tickets' ), $ids ) ) );
			if ( '' !== $wpdb->last_error ) { return $failed( 'lock' ); }
			$current = array();
			foreach ( (array) $locked as $row ) { $current[ (int) $row->ticket_id ] = (string) $row->token_hash; }
			foreach ( $rows as $row ) {
				$ticket_id = (int) $row->ticket_id;
				if ( ! isset( $current[ $ticket_id ] ) ) { continue; } // Checked in or revoked meanwhile.
				$token = bin2hex( random_bytes( 32 ) );
				$updated = $wpdb->query( $wpdb->prepare( "UPDATE %i SET token_hash=%s WHERE ticket_id=%d AND status='valid' AND token_hash=%s", $p . 'wpcap_tickets', hash( 'sha256', $token ), $ticket_id, $current[ $ticket_id ] ) );
				if ( 1 !== (int) $updated ) { return $failed( 'rotate' ); }
				$tokens[ $ticket_id ] = $token;
				$printable[] = $row;
			}
			if ( ! $tokens ) { return $failed( 'nothing', __( 'Die Tickets wurden inzwischen eingecheckt oder storniert.', 'pass-captain' ) ); }
			$qr = \PassCaptain\Security\SignedQr::qr_values( $tokens );
			$items = array();
			foreach ( self::attach_fields( $printable ) as $row ) { $items[] = self::values( $row, $tokens[ (int) $row->ticket_id ], null, $qr[ (int) $row->ticket_id ] ?? '' ); }
			if ( ! self::render_to_file( $file, $kind, $items, self::body_for_event( Scope::occurrence_event( $occurrence_id ), $kind ) ) || ! is_file( $file ) ) {
				return $failed( 'write' );
			}
		} catch ( \Throwable $e ) {
			return $failed( 'exception' );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) { return $failed( 'commit' ); }
		$delivered = array_values( array_filter( $printable, static function ( $row ) { return ! self::undelivered( $row ); } ) );
		Audit::record( 'ticket_codes_rotated', 'occurrence', absint( $occurrence_id ), array( 'count' => count( $tokens ), 'customer_tickets' => count( $delivered ), 'reason' => sanitize_key( 'batch_' . $kind ) ) );
		foreach ( $tokens as $ticket_id => $token ) {
			if ( class_exists( \PassCaptain\CodeHistory::class ) ) { \PassCaptain\CodeHistory::replaced( (int) $ticket_id, $current[ (int) $ticket_id ] ?? '', 'print' ); } // A543
			/** The ticket got a new code (old one invalid); wallet passes are reissued through the job queue. */
			do_action( 'wpcap_ticket_code_rotated', (int) $ticket_id, $token, 'print' );
		}
		list( $notified, $notify_failed ) = self::notify_holders( $delivered, $tokens );
		return array( 'count' => count( $tokens ), 'delivered' => count( $delivered ), 'total' => $total, 'notified' => $notified, 'notify_failed' => $notify_failed );
	}

	/**
	 * Mails the new links of reprinted customer tickets to their holders (attendee e-mail, else the
	 * buyer), one mail per recipient and order (template tickets_resend).
	 *
	 * @param object[]          $rows   Ticket rows (tickets_for_occurrence).
	 * @param array<int,string> $tokens ticket_id => new code.
	 * @return array{0:int,1:int} Tickets mailed, tickets whose mail failed.
	 */
	private static function notify_holders( array $rows, array $tokens ) {
		$groups = array();
		foreach ( $rows as $row ) {
			$holder = (string) $row->attendee_email;
			$to = is_email( $holder ) && ! \PassCaptain\Privacy::is_placeholder_email( $holder ) ? $holder : (string) $row->customer_email;
			if ( ! is_email( $to ) || \PassCaptain\Privacy::is_placeholder_email( $to ) ) { continue; }
			$groups[ (int) $row->order_id . '|' . strtolower( $to ) ][] = $row;
		}
		$sent = 0;
		$failed = 0;
		foreach ( $groups as $key => $group ) {
			$order = \PassCaptain\Orders::get( (int) $group[0]->order_id );
			if ( ! $order ) { continue; }
			$entries = array();
			foreach ( $group as $row ) {
				$entries[] = array( 'event' => $row->event_name, 'ticket' => $row->ticket_name, 'when' => \PassCaptain\Orders::format_start( $row->starts_at, $row->timezone ), 'location' => $row->location, 'url' => add_query_arg( 'wpcap_ticket', $tokens[ (int) $row->ticket_id ], home_url( '/' ) ), 'extra' => '' );
			}
			$vars = \PassCaptain\Orders::ticket_mail_vars( $order, $entries, static function () use ( $entries ) { return \PassCaptain\Mail\Blocks::code_tickets( $entries ); } );
			if ( \PassCaptain\Mail\Mailer::send( 'tickets_resend', substr( $key, strpos( $key, '|' ) + 1 ), $vars, array(), (string) $order->locale ) ) {
				$sent += count( $group );
			} else {
				$failed += count( $group );
			}
		}
		if ( $failed ) { Audit::record( 'print_batch_notify_failed', 'ticket', (int) $rows[0]->ticket_id, array( 'tickets' => $failed ) ); }
		return array( $sent, $failed );
	}

	/** Ticket statuses printed by a layout: admission media only for valid tickets. */
	public static function batch_statuses( $kind ) {
		return 'ticket' === $kind || 'wristband' === $kind ? array( 'valid' ) : array( 'valid', 'checked_in' );
	}
}

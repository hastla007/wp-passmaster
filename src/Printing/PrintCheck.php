<?php
/**
 * PASS-FE-07: automated print check of a layout and template body – QR contrast and quiet zone, QR module
 * size and print margins – on the real layout (Templates::pages() drawn into a tracing PdfWriter with
 * sample data, the same values as the PDF preview).
 *
 * Rules (millimetres unless noted):
 * - QR contrast: the dark modules need at least QR_MIN_CONTRAST against the white paper (they are drawn
 *   in black; the check guards against a later colour change);
 * - quiet zone: QR_QUIET_MODULES modules around the symbol stay free of text, logos and fills (ISO/IEC
 *   18004) and inside the page – on label sheets inside the label, which is cut or peeled off;
 * - module size: at least QR_MIN_MODULE_MM, so phone cameras and handheld scanners read printed codes;
 * - print margins: nothing printed closer than margin() to the page edge (setting print_margin_mm, default
 *   4 mm: most office printers cannot print the outer 4 mm; wristband printers print almost to the edge) and, on label sheets, at least
 *   LABEL_GAP_MM inside the own label (cutting and die-cut tolerance).
 *
 * The check never blocks saving (templates printed on edge-to-edge printers can be fine); the print
 * screen shows the findings as a warning after saving and the starter templates must pass it
 * (test zz_p8_print_check).
 */
namespace PassCaptain\Printing;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class PrintCheck {
	/** Minimum contrast of the dark QR modules on white paper. */
	const QR_MIN_CONTRAST = 7.0;
	/** Quiet zone in modules (ISO/IEC 18004: four modules). */
	const QR_QUIET_MODULES = 4;
	/** Smallest printed QR module. */
	const QR_MIN_MODULE_MM = 0.4;
	/** Distance to the edge of the own label on label sheets. */
	const LABEL_GAP_MM = 1.0;
	/** Rounding tolerance in points. */
	const EPSILON = 0.05;

	/**
	 * Margin to the page edge a layout needs (mm): the setting print_margin_mm (default 4 mm,
	 * Templates::margin_mm()); wristbands at most 1 mm (thermal printers print almost to the edge).
	 * Filter `wpcap_print_check_margin` (e.g. 0 for an edge-to-edge printer) has the last word.
	 */
	public static function margin( $kind ) {
		$setting = (float) Templates::margin_mm();
		$margin = 'wristband' === $kind ? min( 1.0, $setting ) : $setting;
		/**
		 * PASS-FE-07: minimum distance of printed content to the page edge (mm) used by the print check.
		 *
		 * @param float  $margin Default: the setting print_margin_mm (4 mm), wristbands at most 1 mm.
		 * @param string $kind   Layout (Templates::KINDS).
		 */
		return max( 0.0, (float) apply_filters( 'wpcap_print_check_margin', $margin, $kind ) );
	}

	/**
	 * Checks a layout with a template body ('' = the layout's default body).
	 *
	 * @param string     $kind One of Templates::KINDS.
	 * @param string     $body Template body.
	 * @param array|null $item Placeholder values; null = Templates::sample_item() (ticket layout: with a
	 *                         page header and a two-line footer like the ticket PDFs).
	 * @return array{problems:string[],qr:array,margin_mm:float} Findings (empty = fine), the measured QR
	 *         codes (module_mm, quiet_modules, contrast) and the smallest distance to the page edge.
	 */
	public static function check( $kind, $body = '', $item = null ) {
		$kind = in_array( $kind, Templates::KINDS, true ) ? $kind : 'ticket';
		$body = '' !== trim( (string) $body ) ? (string) $body : Templates::default_body( $kind );
		if ( null === $item ) {
			$item = Templates::sample_item( $body );
			if ( 'ticket' === $kind ) {
				$item['_title'] = __( 'Beispiel-Veranstalter', 'pass-captain' );
				$item['_footer'] = \PassCaptain\TicketPdf::default_footer() . "\n" . __( 'Impressum', 'pass-captain' ) . ' · ' . __( 'Datenschutz', 'pass-captain' );
			}
		}
		$pdf = ( new PdfWriter() )->trace();
		Templates::pages( $kind, array_fill( 0, Templates::per_page( $kind ), (array) $item ), $body, $pdf );
		return self::evaluate( $kind, $pdf->traced() );
	}

	/**
	 * Evaluates traced pages (PdfWriter::traced()) of a layout.
	 *
	 * @return array{problems:string[],qr:array,margin_mm:float}
	 */
	public static function evaluate( $kind, array $pages ) {
		$mm = PdfWriter::MM;
		$grid = Templates::grids()[ $kind ] ?? null;
		$margin = self::margin( $kind ) * $mm;
		$problems = array();
		$codes = array();
		$min_edge = null;
		$add = static function ( $key, $message ) use ( &$problems ) {
			if ( ! isset( $problems[ $key ] ) ) { $problems[ $key ] = $message; }
		};
		foreach ( $pages as $page ) {
			$pw = (float) $page['w'];
			$ph = (float) $page['h'];
			$ink = array_values( array_filter( (array) $page['boxes'], static function ( $b ) { return in_array( $b['type'], array( 'text', 'qr', 'image', 'fill' ), true ) && $b['w'] > 0 && $b['h'] > 0; } ) );
			foreach ( $ink as $index => $box ) {
				$edge = min( $box['x'], $box['y'], $pw - $box['x'] - $box['w'], $ph - $box['y'] - $box['h'] );
				$min_edge = null === $min_edge ? $edge : min( $min_edge, $edge );
				if ( $edge < $margin - self::EPSILON ) {
					/* translators: 1: measured distance in mm, 2: minimum distance in mm */
					$add( 'margin', sprintf( __( 'Druckränder: Inhalt liegt nur %1$s mm vom Blattrand entfernt; viele Drucker drucken erst ab %2$s mm. Bitte Text kürzen oder kleiner setzen.', 'pass-captain' ), self::number( max( 0.0, $edge ) / $mm ), self::number( $margin / $mm ) ) );
				}
				$cell = $grid ? self::cell_of( $grid, $box ) : array( 0.0, 0.0, $pw, $ph );
				if ( $grid ) {
					$gap = self::LABEL_GAP_MM * $mm;
					if ( $box['x'] < $cell[0] + $gap - self::EPSILON || $box['y'] < $cell[1] + $gap - self::EPSILON || $box['x'] + $box['w'] > $cell[0] + $cell[2] - $gap + self::EPSILON || $box['y'] + $box['h'] > $cell[1] + $cell[3] - $gap + self::EPSILON ) {
						/* translators: %s: distance in mm */
						$add( 'label', sprintf( __( 'Etikett: Inhalt reicht bis auf weniger als %s mm an den Rand des Etiketts. Bitte Text kürzen oder kleiner setzen.', 'pass-captain' ), self::number( self::LABEL_GAP_MM ) ) );
					}
				}
				if ( 'qr' !== $box['type'] ) { continue; }
				$module = (float) $box['module'];
				$contrast = Templates::contrast( self::hex( $box['color'] ?? array( 0, 0, 0 ) ) );
				// Free space around the symbol in modules: up to the cell edge and to every other printed box.
				$free = min( $box['x'] - $cell[0], $box['y'] - $cell[1], $cell[0] + $cell[2] - $box['x'] - $box['w'], $cell[1] + $cell[3] - $box['y'] - $box['h'] );
				foreach ( $ink as $other_index => $other ) {
					if ( $other_index === $index ) { continue; }
					$free = min( $free, self::distance( $box, $other ) );
				}
				$quiet = $module > 0 ? $free / $module : 0.0;
				$codes[] = array( 'module_mm' => round( $module / $mm, 3 ), 'quiet_modules' => round( $quiet, 2 ), 'contrast' => $contrast );
				if ( $contrast < self::QR_MIN_CONTRAST ) {
					/* translators: 1: contrast ratio, 2: minimum ratio */
					$add( 'contrast', sprintf( __( 'QR-Code: Kontrast zum Papier nur %1$s:1 (mindestens %2$s:1).', 'pass-captain' ), self::number( $contrast ), self::number( self::QR_MIN_CONTRAST ) ) );
				}
				if ( $quiet < self::QR_QUIET_MODULES - 0.01 ) {
					/* translators: 1: measured quiet zone in modules, 2: required modules */
					$add( 'quiet', sprintf( __( 'QR-Code: Die Ruhezone um den Code ist nur %1$s Module breit (nötig: %2$d). Text, Logo oder Rand liegen zu nah am Code; Scanner lesen ihn dann schlecht.', 'pass-captain' ), self::number( max( 0.0, $quiet ) ), self::QR_QUIET_MODULES ) );
				}
				if ( $module / $mm < self::QR_MIN_MODULE_MM - 0.0005 ) {
					/* translators: 1: module size in mm, 2: minimum size in mm */
					$add( 'module', sprintf( __( 'QR-Code: Ein Modul ist nur %1$s mm groß (mindestens %2$s mm). Bitte dem Code mehr Platz geben, z. B. durch weniger Zeilen.', 'pass-captain' ), self::number( $module / $mm, 2 ), self::number( self::QR_MIN_MODULE_MM, 2 ) ) );
				}
			}
		}
		return array( 'problems' => array_values( $problems ), 'qr' => $codes, 'margin_mm' => null === $min_edge ? 0.0 : round( $min_edge / $mm, 2 ) );
	}

	/** Label rectangle [x, y, w, h] (points) of a box on a label sheet: the label containing its centre. */
	private static function cell_of( array $grid, array $box ) {
		$mm = PdfWriter::MM;
		$cx = $box['x'] + $box['w'] / 2;
		$cy = $box['y'] + $box['h'] / 2;
		$col = max( 0, min( $grid['cols'] - 1, (int) floor( ( $cx - $grid['left'] * $mm ) / ( $grid['pitch_x'] * $mm ) ) ) );
		$from_top = 297 * $mm - $grid['top'] * $mm - $cy;
		$row = max( 0, min( $grid['rows'] - 1, (int) floor( $from_top / ( $grid['pitch_y'] * $mm ) ) ) );
		$x = $grid['left'] * $mm + $col * $grid['pitch_x'] * $mm;
		$y = 297 * $mm - $grid['top'] * $mm - $row * $grid['pitch_y'] * $mm - $grid['h'] * $mm;
		return array( $x, $y, $grid['w'] * $mm, $grid['h'] * $mm );
	}

	/** Gap between two boxes (0 when they touch or overlap). */
	private static function distance( array $a, array $b ) {
		$dx = max( 0.0, max( $b['x'] - ( $a['x'] + $a['w'] ), $a['x'] - ( $b['x'] + $b['w'] ) ) );
		$dy = max( 0.0, max( $b['y'] - ( $a['y'] + $a['h'] ), $a['y'] - ( $b['y'] + $b['h'] ) ) );
		return $dx > 0 && $dy > 0 ? sqrt( $dx * $dx + $dy * $dy ) : max( $dx, $dy );
	}

	/** #rrggbb of a colour as three floats 0..1 (null = black). */
	private static function hex( $rgb ) {
		$rgb = is_array( $rgb ) && 3 === count( $rgb ) ? array_values( $rgb ) : array( 0, 0, 0 );
		return vsprintf( '#%02x%02x%02x', array_map( static function ( $c ) { return (int) round( max( 0.0, min( 1.0, (float) $c ) ) * 255 ); }, $rgb ) );
	}

	private static function number( $value, $decimals = 1 ) {
		return number_format( (float) $value, $decimals, ',', '' );
	}
}

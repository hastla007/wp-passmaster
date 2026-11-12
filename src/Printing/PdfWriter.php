<?php
/**
 * Small dependency-free PDF writer for print layouts (tickets, badges, name tags, wristbands, invoices).
 *
 * Pages may have any size (points; 1 mm = 2.8346 pt). Supports Helvetica / Helvetica-Bold text
 * (WinAnsi), filled and stroked rectangles, lines and vector QR codes (matrix from TicketPdf::qr_matrix).
 */
namespace PassCaptain\Printing;

use PassCaptain\TicketPdf;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class PdfWriter {
	const MM = 2.834645669;

	/** @var array<int,array{w:float,h:float,ops:string[]}> */
	private $pages = array();
	private $current = -1;

	/** Starts a new page of the given size in points. */
	public function add_page( $width, $height ) {
		$this->pages[] = array( 'w' => (float) $width, 'h' => (float) $height, 'ops' => array() );
		$this->current = count( $this->pages ) - 1;
		return $this;
	}

	public function page_count() { return count( $this->pages ); }

	/** Approximate Helvetica text width (average glyph widths; good enough for fitting and centring). */
	public static function text_width( $text, $size, $bold = false ) {
		$text = (string) $text;
		$width = 0.0;
		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = function_exists( 'mb_substr' ) ? mb_substr( $text, $i, 1 ) : $text[ $i ];
			if ( ' ' === $ch || in_array( $ch, array( 'i', 'l', 'j', '.', ',', ':', ';', '!', '|', "'", 'I', 't', 'f', 'r' ), true ) ) { $w = 0.30; }
			elseif ( in_array( $ch, array( 'm', 'w', 'M', 'W' ), true ) ) { $w = 0.85; }
			elseif ( preg_match( '/[A-Z0-9ÄÖÜ]/u', $ch ) ) { $w = 0.66; }
			else { $w = 0.54; }
			$width += $w;
		}
		return $width * $size * ( $bold ? 1.06 : 1.0 );
	}

	/** Shortens $text with "…" so it fits into $max_width. */
	public static function fit( $text, $size, $max_width, $bold = false ) {
		$text = (string) $text;
		if ( self::text_width( $text, $size, $bold ) <= $max_width ) { return $text; }
		while ( '' !== $text && self::text_width( $text . '...', $size, $bold ) > $max_width ) {
			$text = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, -1 ) : substr( $text, 0, -1 );
		}
		return rtrim( $text ) . '...';
	}

	/** Splits text into lines of at most $max_width. */
	public static function wrap( $text, $size, $max_width, $bold = false ) {
		$lines = array();
		$line = '';
		foreach ( preg_split( '/\s+/u', trim( (string) $text ) ) as $word ) {
			$candidate = '' === $line ? $word : $line . ' ' . $word;
			if ( '' !== $line && self::text_width( $candidate, $size, $bold ) > $max_width ) {
				$lines[] = $line;
				$line = $word;
			} else {
				$line = $candidate;
			}
		}
		if ( '' !== $line ) { $lines[] = $line; }
		return $lines;
	}

	/**
	 * Text at baseline ($x,$y) from bottom-left. $align: left|center|right ($x is the anchor).
	 */
	public function text( $x, $y, $size, $text, $bold = false, $align = 'left' ) {
		$text = (string) $text;
		if ( '' === $text ) { return $this; }
		if ( 'center' === $align ) { $x -= self::text_width( $text, $size, $bold ) / 2; }
		elseif ( 'right' === $align ) { $x -= self::text_width( $text, $size, $bold ); }
		$this->op( sprintf( 'BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET', $bold ? 'F2' : 'F1', $size, $x, $y, self::escape( $text ) ) );
		return $this;
	}

	public function rect( $x, $y, $w, $h, $fill = true, $gray = 0.0 ) {
		$this->op( sprintf( $fill ? '%.3F g %.2F %.2F %.2F %.2F re f 0 g' : '%.3F G 0.5 w %.2F %.2F %.2F %.2F re S 0 G', $gray, $x, $y, $w, $h ) );
		return $this;
	}

	public function line( $x1, $y1, $x2, $y2, $gray = 0.6 ) {
		$this->op( sprintf( '%.3F G 0.5 w %.2F %.2F m %.2F %.2F l S 0 G', $gray, $x1, $y1, $x2, $y2 ) );
		return $this;
	}

	/** Vector QR code with its lower-left corner at ($x,$y) and edge length $size. Returns false without encoder/payload. */
	public function qr( $x, $y, $size, $payload ) {
		$matrix = TicketPdf::qr_matrix( (string) $payload );
		if ( ! $matrix ) { return false; }
		$count = count( $matrix );
		$module = $size / ( $count + 2 );
		$ops = array( '0 g' );
		foreach ( $matrix as $row_index => $row ) {
			foreach ( $row as $col_index => $dark ) {
				if ( $dark ) {
					$ops[] = sprintf( '%.2F %.2F %.2F %.2F re f', $x + ( $col_index + 1 ) * $module, $y + $size - ( $row_index + 2 ) * $module, $module, $module );
				}
			}
		}
		$this->op( implode( "\n", $ops ) );
		return true;
	}

	private function op( $op ) {
		if ( $this->current < 0 ) { $this->add_page( 595.28, 841.89 ); }
		$this->pages[ $this->current ]['ops'][] = $op;
	}

	/** PDF bytes. A document without pages gets one empty A4 page (PDFs need at least one page). */
	public function output( $title = '' ) {
		if ( ! $this->pages ) { $this->add_page( 595.28, 841.89 ); }
		$objects = array();
		$objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
		$objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		$objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
		$objects[5] = '<< /Producer (WP Pass Captain)' . ( '' !== (string) $title ? ' /Title (' . self::escape( $title ) . ')' : '' ) . ' >>';
		$kids = array();
		$next = 6;
		foreach ( $this->pages as $page ) {
			$page_id = $next++;
			$content_id = $next++;
			$content = implode( "\n", $page['ops'] );
			$kids[] = $page_id . ' 0 R';
			$objects[ $page_id ] = sprintf( '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', $page['w'], $page['h'], $content_id );
			$objects[ $content_id ] = '<< /Length ' . strlen( $content ) . " >>\nstream\n" . $content . "\nendstream";
		}
		$objects[2] = '<< /Type /Pages /Kids [' . implode( ' ', $kids ) . '] /Count ' . count( $kids ) . ' >>';
		ksort( $objects );
		$pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array();
		foreach ( $objects as $id => $body ) {
			$offsets[ $id ] = strlen( $pdf );
			$pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
		}
		$xref = strlen( $pdf );
		$pdf .= "xref\n0 " . ( count( $objects ) + 1 ) . "\n0000000000 65535 f \n";
		foreach ( $offsets as $offset ) { $pdf .= sprintf( "%010d 00000 n \n", $offset ); }
		$pdf .= 'trailer << /Size ' . ( count( $objects ) + 1 ) . " /Root 1 0 R /Info 5 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
		return $pdf;
	}

	/** UTF-8 to WinAnsi with PDF string escaping. */
	public static function escape( $str ) {
		$str = (string) $str;
		$str = function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( $str, 'Windows-1252', 'UTF-8' ) : $str;
		return str_replace( array( '\\', '(', ')', "\r", "\n" ), array( '\\\\', '\\(', '\\)', ' ', ' ' ), $str );
	}
}

<?php
/**
 * Small dependency-free PDF writer for print layouts (tickets, badges, name tags, wristbands, invoices).
 *
 * Pages may have any size (points; 1 mm = 2.8346 pt). Supports Helvetica / Helvetica-Bold text
 * (WinAnsi; characters outside WinAnsi are transliterated, see winansi()), filled and stroked
 * rectangles, lines and vector QR codes (matrix from TicketPdf::qr_matrix).
 *
 * Size and memory (audit R2 #5): content streams are compressed (FlateDecode, when zlib is available),
 * dark QR modules are merged into horizontal runs drawn as one path, and every finished page is
 * serialised right away, so only the current page is kept as drawing operations. With a file name
 * the document is streamed to that file page by page (to_file mode, see finish()) and never held in
 * memory as a whole. A QR code is not stored as a Form XObject: every ticket has its own code, so an
 * XObject would never be reused and only add an object per page.
 */
namespace PassCaptain\Printing;

use PassCaptain\TempFiles;
use PassCaptain\TicketPdf;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class PdfWriter {
	const MM = 2.834645669;

	/** Fixed object ids: catalog, page tree, the two fonts, document info. Pages start at FIRST_PAGE_ID. */
	const FIRST_PAGE_ID = 6;

	/** @var array{w:float,h:float,ops:string[]}|null Page being drawn. */
	private $page = null;
	private $count = 0;
	/** @var string Serialised objects (memory mode). */
	private $buffer = '';
	/** @var string Target file (to_file mode) or ''. */
	private $file = '';
	private $written = 0;
	private $failed = false;
	/** @var array<int,int> Object id => byte offset. */
	private $offsets = array();
	private $kids = array();
	private $next = self::FIRST_PAGE_ID;
	private $lang = '';

	/**
	 * @param string $file Optional target file: the PDF is streamed into it page by page (created with
	 *                     mode 0600; use a TempFiles::private_dir()). finish() completes it.
	 */
	public function __construct( $file = '' ) {
		$this->file = (string) $file;
		$this->emit( "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n" );
	}

	/** Document language (BCP 47 or WordPress locale such as de_DE), written as /Lang of the catalog. */
	public function set_language( $locale ) {
		$this->lang = preg_replace( '/[^A-Za-z0-9-]/', '', str_replace( '_', '-', (string) $locale ) );
		return $this;
	}

	/** Starts a new page of the given size in points (the previous one is serialised). */
	public function add_page( $width, $height ) {
		$this->flush_page();
		$this->page = array( 'w' => (float) $width, 'h' => (float) $height, 'ops' => array() );
		++$this->count;
		return $this;
	}

	public function page_count() { return $this->count; }

	/** False once writing to the target file failed (to_file mode). */
	public function ok() { return ! $this->failed; }

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

	/**
	 * Splits text into lines of at most $max_width. Words longer than a line are shortened with fit().
	 * With $max_lines the last line is shortened ("...") when the text does not fit.
	 */
	public static function wrap( $text, $size, $max_width, $bold = false, $max_lines = 0 ) {
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
		$lines = array_map( static function ( $l ) use ( $size, $max_width, $bold ) { return self::fit( $l, $size, $max_width, $bold ); }, $lines );
		if ( $max_lines > 0 && count( $lines ) > $max_lines ) {
			$rest = implode( ' ', array_slice( $lines, $max_lines - 1 ) );
			$lines = array_slice( $lines, 0, $max_lines - 1 );
			$lines[] = self::fit( $rest . ' ...', $size, $max_width, $bold );
		}
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

	/**
	 * Vector QR code with its lower-left corner at ($x,$y) and edge length $size (including a quiet zone
	 * of $quiet modules). Returns false without encoder/payload.
	 */
	public function qr( $x, $y, $size, $payload, $quiet = 1 ) {
		return $this->qr_matrix( $x, $y, $size, TicketPdf::qr_matrix( (string) $payload ), $quiet );
	}

	/** Like qr() with a matrix computed by the caller (QR encoding is the expensive part). */
	public function qr_matrix( $x, $y, $size, array $matrix, $quiet = 1 ) {
		if ( ! $matrix ) { return false; }
		$this->op( self::qr_ops( $matrix, $x, $y, $size / ( count( $matrix ) + 2 * $quiet ), $quiet ) );
		return true;
	}

	/**
	 * Drawing operations of a QR matrix: one rectangle per horizontal run of dark modules, in module
	 * units (small integers, scaled by one "cm" transform) and filled as a single path – far fewer and
	 * shorter operations than one rectangle per module, and adjacent runs leave no hairline seams.
	 *
	 * @param array $matrix Rows of booleans.
	 */
	public static function qr_ops( array $matrix, $x, $y, $module, $quiet ) {
		$count = count( $matrix );
		$dim = $count + 2 * $quiet;
		$ops = array( sprintf( 'q %.4F 0 0 %.4F %.2F %.2F cm 0 g', $module, $module, $x, $y ) );
		foreach ( $matrix as $row_index => $row ) {
			$n = count( $row );
			$col = 0;
			$row_y = $dim - $row_index - $quiet - 1;
			while ( $col < $n ) {
				if ( ! $row[ $col ] ) { ++$col; continue; }
				$start = $col;
				while ( $col < $n && $row[ $col ] ) { ++$col; }
				$ops[] = ( $start + $quiet ) . ' ' . $row_y . ' ' . ( $col - $start ) . ' 1 re';
			}
		}
		$ops[] = 'f Q';
		return implode( "\n", $ops );
	}

	private function op( $op ) {
		if ( null === $this->page ) { $this->add_page( 595.28, 841.89 ); }
		$this->page['ops'][] = $op;
	}

	/** Content stream object body (compressed when zlib is available). */
	public static function stream( $content ) {
		$content = (string) $content;
		$compressed = function_exists( 'gzcompress' ) ? gzcompress( $content, 6 ) : false;
		if ( is_string( $compressed ) ) {
			return '<< /Length ' . strlen( $compressed ) . " /Filter /FlateDecode >>\nstream\n" . $compressed . "\nendstream";
		}
		return '<< /Length ' . strlen( $content ) . " >>\nstream\n" . $content . "\nendstream";
	}

	private function emit( $bytes ) {
		if ( $this->failed ) { return; }
		// File mode: written in chunks of about 256 KB (few appends, bounded memory).
		$this->buffer .= $bytes;
		$this->written += strlen( $bytes );
		if ( '' !== $this->file && strlen( $this->buffer ) >= 262144 ) { $this->drain(); }
	}

	/** Appends the buffered bytes to the target file (file mode). */
	private function drain() {
		if ( '' === $this->file || '' === $this->buffer || $this->failed ) { return; }
		if ( ! TempFiles::append( $this->file, $this->buffer ) ) { $this->failed = true; }
		$this->buffer = '';
	}

	private function object( $id, $body ) {
		$this->offsets[ $id ] = $this->written;
		$this->emit( $id . " 0 obj\n" . $body . "\nendobj\n" );
	}

	/** Serialises the current page (page object + content stream). */
	private function flush_page() {
		if ( null === $this->page ) { return; }
		$page_id = $this->next++;
		$content_id = $this->next++;
		$this->kids[] = $page_id . ' 0 R';
		$this->object( $page_id, sprintf( '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', $this->page['w'], $this->page['h'], $content_id ) );
		$this->object( $content_id, self::stream( implode( "\n", $this->page['ops'] ) ) );
		$this->page = null;
	}

	/** Writes the remaining objects, the xref table and the trailer. */
	private function close( $title ) {
		if ( ! $this->count ) { $this->add_page( 595.28, 841.89 ); }
		$this->flush_page();
		$this->object( 1, '<< /Type /Catalog /Pages 2 0 R' . ( '' !== $this->lang ? ' /Lang (' . $this->lang . ')' : '' ) . ' >>' );
		$this->object( 2, '<< /Type /Pages /Kids [' . implode( ' ', $this->kids ) . '] /Count ' . count( $this->kids ) . ' >>' );
		$this->object( 3, '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>' );
		$this->object( 4, '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>' );
		$this->object( 5, '<< /Producer (' . self::escape( \PassCaptain\Brand::name( 'pdf' ) ) . ')' . ( '' !== (string) $title ? ' /Title (' . self::escape( $title ) . ')' : '' ) . ' >>' );
		ksort( $this->offsets );
		$size = count( $this->offsets ) + 1;
		$xref = $this->written;
		$tail = "xref\n0 " . $size . "\n0000000000 65535 f \n";
		foreach ( $this->offsets as $offset ) { $tail .= sprintf( "%010d 00000 n \n", $offset ); }
		$tail .= 'trailer << /Size ' . $size . " /Root 1 0 R /Info 5 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
		$this->emit( $tail );
	}

	/** PDF bytes (memory mode). A document without pages gets one empty A4 page (PDFs need at least one page). */
	public function output( $title = '' ) {
		$this->close( $title );
		$pdf = $this->buffer;
		$this->buffer = '';
		return $pdf;
	}

	/** Completes a streamed document (to_file mode). False when a write failed: the file is incomplete. */
	public function finish( $title = '' ) {
		$this->close( $title );
		$this->drain();
		return ! $this->failed;
	}

	/** UTF-8 to WinAnsi with PDF string escaping. */
	public static function escape( $str ) {
		return str_replace( array( '\\', '(', ')', "\r", "\n" ), array( '\\\\', '\\(', '\\)', ' ', ' ' ), self::winansi( $str ) );
	}

	/**
	 * UTF-8 to Windows-1252 (the encoding of the standard Helvetica font). Characters WinAnsi cannot
	 * show are transliterated instead of becoming "?" (Ł → L, ć → c, Привет → Privet, 東京 → dong jing),
	 * with the Intl transliterator or iconv; only what neither can map becomes "?". Embedding a Unicode
	 * font is out of scope for this writer (see docs/OPERATIONS.md).
	 */
	public static function winansi( $str ) {
		$str = (string) $str;
		if ( '' === $str || ! function_exists( 'mb_convert_encoding' ) ) { return $str; }
		if ( ! preg_match( '/[^\x00-\x7F]/', $str ) ) { return $str; }
		$str = (string) preg_replace_callback( '/[^\x00-\x7F]+/u', static function ( $m ) {
			// Runs of unmappable characters are transliterated together (keeps word context, e.g. CJK).
			$out = '';
			$run = '';
			foreach ( preg_split( '//u', $m[0], -1, PREG_SPLIT_NO_EMPTY ) as $ch ) {
				if ( self::representable( $ch ) ) {
					$out .= ( '' !== $run ? self::transliterate( $run ) : '' ) . $ch;
					$run = '';
				} else {
					$run .= $ch;
				}
			}
			return $out . ( '' !== $run ? self::transliterate( $run ) : '' );
		}, $str );
		return (string) mb_convert_encoding( $str, 'Windows-1252', 'UTF-8' );
	}

	/** True when one UTF-8 character exists in Windows-1252. */
	private static function representable( $ch ) {
		static $cache = array();
		if ( ! isset( $cache[ $ch ] ) ) {
			$cache[ $ch ] = mb_convert_encoding( (string) mb_convert_encoding( $ch, 'Windows-1252', 'UTF-8' ), 'UTF-8', 'Windows-1252' ) === $ch;
		}
		return $cache[ $ch ];
	}

	/** ASCII transliteration of a text ('?' when impossible). */
	public static function transliterate( $ch ) {
		static $intl = null;
		$out = '';
		if ( null === $intl ) {
			$intl = class_exists( '\\Transliterator' ) ? \Transliterator::create( 'Any-Latin; Latin-ASCII' ) : false;
			$intl = $intl ? $intl : false;
		}
		if ( $intl ) {
			$out = (string) $intl->transliterate( $ch );
		}
		if ( ( '' === $out || preg_match( '/[^\x20-\x7E]/', $out ) ) && function_exists( 'iconv' ) ) {
			$converted = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $ch ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- iconv warns on unmappable input; the result is checked.
			$out = false === $converted ? '' : (string) $converted;
		}
		$out = (string) preg_replace( '/[^\x20-\x7E]/', '', $out );
		return '' === $out ? '?' : $out;
	}

	/** True when a text contains characters the PDF fonts cannot show and that are transliterated. */
	public static function needs_transliteration( $str ) {
		$str = (string) $str;
		if ( ! preg_match( '/[^\x00-\x7F]/', $str ) || ! function_exists( 'mb_convert_encoding' ) ) { return false; }
		foreach ( preg_split( '//u', $str, -1, PREG_SPLIT_NO_EMPTY ) as $ch ) {
			if ( strlen( $ch ) > 1 && ! self::representable( $ch ) ) { return true; }
		}
		return false;
	}
}

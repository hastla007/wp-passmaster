<?php
/**
 * Small dependency-free PDF writer – the one PDF engine of the plugin (A330): ticket PDFs of
 * mails, portal and admin, print layouts (tickets, badges, name tags, wristbands) and invoices.
 *
 * Pages may have any size (points; 1 mm = 2.8346 pt). Supports text in two fonts (regular /F1,
 * bold /F2), filled and stroked rectangles, lines and vector QR codes (matrix from
 * TicketPdf::qr_matrix).
 *
 * Text is UTF-8: the bundled DejaVu Sans / DejaVu Sans Bold (assets/fonts, filter `wpcap_pdf_fonts`
 * for other TrueType fonts) are embedded as subsets with only the glyphs the document uses
 * (Type0/CIDFontType2, Identity-H, ToUnicode for copying and search), so names in Polish, Czech,
 * Turkish, Greek, Cyrillic, Hebrew or Arabic letters print correctly. Characters the font lacks
 * (e.g. CJK with the bundled font) print as the font's empty box. Without a usable font file the
 * writer falls back to the standard fonts Helvetica / Helvetica-Bold (WinAnsi): characters outside
 * Windows-1252 are transliterated where possible ("ł" → "l"), otherwise printed as "?".
 */
namespace PassCaptain\Printing;

use PassCaptain\TicketPdf;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class PdfWriter {
	const MM = 2.834645669;

	/** Font resource names: regular and bold (objects 3 and 4). */
	const FONTS = array( 'F1' => 3, 'F2' => 4 );

	/** @var array<int,array{w:float,h:float,ops:string[]}> */
	private $pages = array();
	private $current = -1;
	/** @var bool Embedded Unicode fonts (true) or standard fonts (false) for this document. */
	private $unicode;
	/** @var array<string,array<int,int>> font resource => glyph id => code point (ToUnicode). */
	private $used = array( 'F1' => array(), 'F2' => array() );

	/** @var array|null Loaded fonts: F1/F2 => TrueTypeFont|null; null = not loaded yet. */
	private static $fonts = null;

	/**
	 * @param bool|null $unicode null = embedded fonts when available; false = standard fonts (used
	 *                           to finish batch files begun before 3.10.18 in the same encoding).
	 */
	public function __construct( $unicode = null ) {
		$this->unicode = null === $unicode ? self::unicode_available() : ( $unicode && self::unicode_available() );
	}

	/** Whether this document embeds Unicode fonts. */
	public function is_unicode() {
		return $this->unicode;
	}

	/**
	 * Fonts used for text: F1 regular, F2 bold (TrueTypeFont, or null when the file is missing or
	 * unusable). Filter `wpcap_pdf_fonts`: array( 'regular' => path, 'bold' => path ) – TrueType
	 * (.ttf with glyf outlines) only; without a bold font the regular one is used for bold text.
	 *
	 * @return array{F1:?TrueTypeFont, F2:?TrueTypeFont}
	 */
	public static function fonts() {
		if ( null === self::$fonts ) {
			$dir = ( defined( 'WPCAP_PATH' ) ? WPCAP_PATH : dirname( __DIR__, 2 ) . '/' ) . 'assets/fonts/';
			$paths = (array) apply_filters( 'wpcap_pdf_fonts', array( 'regular' => $dir . 'DejaVuSans.ttf', 'bold' => $dir . 'DejaVuSans-Bold.ttf' ) );
			$regular = TrueTypeFont::load( (string) ( $paths['regular'] ?? '' ) );
			$bold = $regular ? TrueTypeFont::load( (string) ( $paths['bold'] ?? '' ) ) : null;
			self::$fonts = array( 'F1' => $regular, 'F2' => $bold ? $bold : $regular );
		}
		return self::$fonts;
	}

	/** Forgets the loaded fonts (after the `wpcap_pdf_fonts` filter changed, e.g. in tests). */
	public static function reset_fonts() {
		self::$fonts = null;
	}

	/** Whether an embeddable Unicode font is available. */
	public static function unicode_available() {
		return null !== self::fonts()['F1'];
	}

	/** Starts a new page of the given size in points. */
	public function add_page( $width, $height ) {
		$this->pages[] = array( 'w' => (float) $width, 'h' => (float) $height, 'ops' => array() );
		$this->current = count( $this->pages ) - 1;
		return $this;
	}

	public function page_count() { return count( $this->pages ); }

	/**
	 * Text width in points: real advance widths of the embedded font, else an approximation of
	 * Helvetica (average glyph widths; good enough for fitting and centring).
	 */
	public static function text_width( $text, $size, $bold = false ) {
		$text = (string) $text;
		$fonts = self::fonts();
		$font = $fonts[ $bold ? 'F2' : 'F1' ];
		if ( $font ) { return $font->width( $text ) * (float) $size / 1000; }
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

	/** Shortens $text with "..." so it fits into $max_width. */
	public static function fit( $text, $size, $max_width, $bold = false ) {
		$text = \PassCaptain\Text::plain( $text );
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
		foreach ( preg_split( '/\s+/u', trim( \PassCaptain\Text::plain( $text ) ) ) as $word ) {
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
		// PDF text is plain text: stored entities ("Kids &lt;3") are decoded (A497).
		$text = \PassCaptain\Text::plain( $text );
		if ( '' === $text ) { return $this; }
		if ( 'center' === $align ) { $x -= self::text_width( $text, $size, $bold ) / 2; }
		elseif ( 'right' === $align ) { $x -= self::text_width( $text, $size, $bold ); }
		$font = $bold ? 'F2' : 'F1';
		$string = $this->unicode ? $this->encode( $font, $text ) : '(' . self::escape( $text ) . ')';
		$fonts = $this->unicode ? self::fonts() : array();
		if ( $bold && $fonts && $fonts['F1'] === $fonts['F2'] ) {
			// No bold font file: fill and stroke the outline (text state reset by Q).
			$this->op( sprintf( 'q BT /F2 %.2F Tf 2 Tr %.2F w %.2F %.2F Td %s Tj ET Q', $size, $size * 0.03, $x, $y, $string ) );
			return $this;
		}
		$this->op( sprintf( 'BT /%s %.2F Tf %.2F %.2F Td %s Tj ET', $font, $size, $x, $y, $string ) );
		return $this;
	}

	/** Hex string of glyph ids for the embedded font; records the glyphs for the subset. */
	private function encode( $font, $text ) {
		$ttf = self::fonts()[ $font ];
		$hex = '';
		foreach ( TrueTypeFont::codepoints( preg_replace( '/[\r\n\t]+/', ' ', $text ) ) as $cp ) {
			$gid = $ttf->glyph( $cp );
			if ( $gid && ! isset( $this->used[ $font ][ $gid ] ) ) { $this->used[ $font ][ $gid ] = $cp; }
			$hex .= sprintf( '%04X', $gid );
		}
		return '<' . $hex . '>';
	}

	/** Glyphs used so far: F1/F2 => glyph id => code point (the batch job keeps them per block). */
	public function used_glyphs() {
		return $this->used;
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
		$pdf = self::header();
		$body = $this->page_objects( 6, strlen( $pdf ) );
		$pdf .= $body['bytes'];
		return $pdf . self::trailer( $title, $body['offsets'], strlen( $pdf ), $this->unicode ? $this->used : false );
	}

	/** File header (PDF version and binary marker). */
	public static function header() {
		return "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
	}

	/**
	 * The pages as objects (page, content stream) numbered from $first_id, for a file that already
	 * has $base bytes. Used by output() and by the batch job that appends block after block (A427).
	 *
	 * @return array{bytes:string, offsets:array<int,int>} offsets: object id => byte offset.
	 */
	public function page_objects( $first_id, $base ) {
		$bytes = '';
		$offsets = array();
		$next = (int) $first_id;
		foreach ( $this->pages as $page ) {
			$page_id = $next++;
			$content_id = $next++;
			$content = implode( "\n", $page['ops'] );
			$offsets[ $page_id ] = (int) $base + strlen( $bytes );
			$bytes .= $page_id . " 0 obj\n" . sprintf( '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', $page['w'], $page['h'], $content_id ) . "\nendobj\n";
			$offsets[ $content_id ] = (int) $base + strlen( $bytes );
			$bytes .= $content_id . " 0 obj\n" . '<< /Length ' . strlen( $content ) . " >>\nstream\n" . $content . "\nendstream" . "\nendobj\n";
		}
		return array( 'bytes' => $bytes, 'offsets' => $offsets );
	}

	/**
	 * Closing part of a file whose page objects (ids 6, 7, … as written by page_objects(): page,
	 * content, page, content …) end at byte $base: catalog, page tree, fonts, info, xref, trailer.
	 * Without pages an empty A4 page is added (PDFs need at least one page).
	 *
	 * @param array<int,int>   $offsets Object id => byte offset of the page objects.
	 * @param array|false|null $glyphs  Unicode fonts: F1/F2 => glyph id => code point used by the pages
	 *                                  (null = unknown: the whole font is embedded); false = the pages
	 *                                  use the standard fonts (Helvetica, WinAnsi).
	 */
	public static function trailer( $title, array $offsets, $base, $glyphs = false ) {
		$bytes = '';
		$base = (int) $base;
		$unicode = false !== $glyphs && self::unicode_available();
		if ( ! $offsets ) {
			$empty = ( new self( $unicode ) )->add_page( 595.28, 841.89 )->page_objects( 6, $base );
			$bytes .= $empty['bytes'];
			$offsets = $empty['offsets'];
		}
		ksort( $offsets );
		$kids = array();
		foreach ( array_keys( $offsets ) as $i => $id ) {
			if ( 0 === $i % 2 ) { $kids[] = $id . ' 0 R'; }
		}
		$fixed = array(
			1 => '<< /Type /Catalog /Pages 2 0 R >>',
			2 => '<< /Type /Pages /Kids [' . implode( ' ', $kids ) . '] /Count ' . count( $kids ) . ' >>',
			3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
			4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
			5 => '<< /Producer (Pass Captain)' . ( '' !== (string) $title ? ' /Title ' . self::info_string( $title ) : '' ) . ' >>',
		);
		$extra = array();
		if ( $unicode ) {
			$next = max( array_keys( $offsets ) ) + 1;
			$fonts = self::fonts();
			$shared = $fonts['F1'] === $fonts['F2'];
			foreach ( self::FONTS as $resource => $id ) {
				$used = is_array( $glyphs ) ? (array) ( $glyphs[ $resource ] ?? array() ) : null;
				if ( $shared && 'F2' === $resource ) {
					// One font program for both styles: bold is drawn with a stroked outline.
					$fixed[4] = $fixed[3];
					continue;
				}
				if ( $shared && is_array( $glyphs ) ) { $used = (array) ( $glyphs['F1'] ?? array() ) + (array) ( $glyphs['F2'] ?? array() ); }
				list( $dict, $objects ) = self::font_objects( $fonts[ $resource ], $used, $next );
				$fixed[ $id ] = $dict;
				$extra += $objects;
				$next += count( $objects );
			}
		}
		foreach ( $fixed + $extra as $id => $body ) {
			$offsets[ $id ] = $base + strlen( $bytes );
			$bytes .= $id . " 0 obj\n" . $body . "\nendobj\n";
		}
		ksort( $offsets );
		$xref = $base + strlen( $bytes );
		$bytes .= "xref\n0 " . ( count( $offsets ) + 1 ) . "\n0000000000 65535 f \n";
		foreach ( $offsets as $offset ) { $bytes .= sprintf( "%010d 00000 n \n", $offset ); }
		$bytes .= 'trailer << /Size ' . ( count( $offsets ) + 1 ) . " /Root 1 0 R /Info 5 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
		return $bytes;
	}

	/**
	 * Type0 font dictionary and its objects (CIDFontType2, FontDescriptor, FontFile2, ToUnicode)
	 * numbered from $first.
	 *
	 * @param array<int,int>|null $used glyph id => code point; null = all glyphs.
	 * @return array{0:string, 1:array<int,string>}
	 */
	private static function font_objects( TrueTypeFont $font, $used, $first ) {
		if ( null === $used ) {
			$used = array();
			foreach ( $font->cmap() as $cp => $gid ) {
				if ( ! isset( $used[ $gid ] ) ) { $used[ $gid ] = $cp; }
			}
		}
		ksort( $used );
		$tag = '';
		$seed = hash( 'crc32b', $font->name . '|' . implode( ',', array_keys( $used ) ) );
		for ( $i = 0; $i < 6; $i++ ) { $tag .= chr( 65 + hexdec( $seed[ $i ] ) % 26 ); }
		$name = $tag . '+' . $font->name;
		$cid = $first;
		$descriptor = $first + 1;
		$file = $first + 2;
		$cmap = $first + 3;
		$widths = '';
		foreach ( $used as $gid => $cp ) { $widths .= $gid . ' [' . $font->advance( $gid ) . '] '; }
		$program = $font->subset( array_keys( $used ) );
		$packed = function_exists( 'gzcompress' ) ? gzcompress( $program, 6 ) : false;
		$stream = false !== $packed ? '<< /Length ' . strlen( $packed ) . ' /Length1 ' . strlen( $program ) . " /Filter /FlateDecode >>\nstream\n" . $packed . "\nendstream" : '<< /Length ' . strlen( $program ) . ' /Length1 ' . strlen( $program ) . " >>\nstream\n" . $program . "\nendstream";
		$flags = 32 | ( $font->fixed_pitch ? 1 : 0 ) | ( $font->italic_angle ? 64 : 0 );
		$to_unicode = self::to_unicode( $used );
		$objects = array(
			$cid => '<< /Type /Font /Subtype /CIDFontType2 /BaseFont /' . $name . ' /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor ' . $descriptor . ' 0 R /DW ' . $font->advance( 0 ) . ' /W [' . trim( $widths ) . '] /CIDToGIDMap /Identity >>',
			$descriptor => sprintf( '<< /Type /FontDescriptor /FontName /%s /Flags %d /FontBBox [%d %d %d %d] /ItalicAngle %s /Ascent %d /Descent %d /CapHeight %d /StemV %d /FontFile2 %d 0 R >>', $name, $flags, $font->bbox[0], $font->bbox[1], $font->bbox[2], $font->bbox[3], rtrim( rtrim( sprintf( '%.2F', $font->italic_angle ), '0' ), '.' ), $font->ascent, $font->descent, $font->cap_height, $font->bold ? 120 : 80, $file ),
			$file => $stream,
			$cmap => '<< /Length ' . strlen( $to_unicode ) . " >>\nstream\n" . $to_unicode . "\nendstream",
		);
		return array( '<< /Type /Font /Subtype /Type0 /BaseFont /' . $name . ' /Encoding /Identity-H /DescendantFonts [' . $cid . ' 0 R] /ToUnicode ' . $cmap . ' 0 R >>', $objects );
	}

	/** ToUnicode CMap (glyph id → UTF-16BE) so text can be copied and searched. */
	private static function to_unicode( array $used ) {
		$out = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n";
		foreach ( array_chunk( $used, 100, true ) as $chunk ) {
			$out .= count( $chunk ) . " beginbfchar\n";
			foreach ( $chunk as $gid => $cp ) {
				$out .= sprintf( '<%04X> <%s>', $gid, strtoupper( bin2hex( (string) mb_convert_encoding( pack( 'N', $cp ), 'UTF-16BE', 'UTF-32BE' ) ) ) ) . "\n";
			}
			$out .= "endbfchar\n";
		}
		return $out . "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend";
	}

	/** Document info string: plain for ASCII, UTF-16BE with byte order mark otherwise. */
	private static function info_string( $text ) {
		$text = (string) $text;
		if ( ! preg_match( '/[^\x20-\x7E]/', $text ) || ! function_exists( 'mb_convert_encoding' ) ) { return '(' . self::escape( $text ) . ')'; }
		return '<FEFF' . strtoupper( bin2hex( (string) mb_convert_encoding( $text, 'UTF-16BE', 'UTF-8' ) ) ) . '>';
	}

	/**
	 * UTF-8 to WinAnsi (standard fonts) with PDF string escaping. Characters outside
	 * Windows-1252 are transliterated where iconv can ("ł" → "l"), else "?".
	 */
	public static function escape( $str ) {
		$str = (string) $str;
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$out = '';
			foreach ( preg_split( '//u', $str, -1, PREG_SPLIT_NO_EMPTY ) ?: array() as $ch ) {
				$win = mb_convert_encoding( $ch, 'Windows-1252', 'UTF-8' );
				if ( '?' === $win && '?' !== $ch && function_exists( 'iconv' ) ) {
					$t = @iconv( 'UTF-8', 'Windows-1252//TRANSLIT', $ch ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unconvertible input.
					$win = is_string( $t ) && '' !== $t ? $t : '?';
				}
				$out .= $win;
			}
			$str = $out;
		}
		return str_replace( array( '\\', '(', ')', "\r", "\n" ), array( '\\\\', '\\(', '\\)', ' ', ' ' ), $str );
	}
}

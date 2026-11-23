<?php
/**
 * Small dependency-free PDF writer – the one PDF engine of the plugin (A330): ticket PDFs of
 * mails, portal and admin, print layouts (tickets, badges, name tags, wristbands) and invoices.
 *
 * Pages may have any size (points; 1 mm = 2.8346 pt). Supports text in two fonts (regular /F1,
 * bold /F2), filled and stroked rectangles, lines, vector QR codes (matrix from
 * TicketPdf::qr_matrix), text and fill colours (A685, set_color()/fill_rgb()) and logos as inline
 * images (A685, image(); JPEG and PNG, see load_image()).
 *
 * Text is UTF-8: the bundled DejaVu Sans / DejaVu Sans Bold (assets/fonts, filter `wpcap_pdf_fonts`
 * for other TrueType fonts) are embedded as subsets with only the glyphs the document uses
 * (Type0/CIDFontType2, Identity-H, ToUnicode for copying and search), so names in Polish, Czech,
 * Turkish, Greek, Cyrillic, Hebrew or Arabic letters print correctly. Characters the font lacks
 * (e.g. CJK with the bundled font) print as the font's empty box. Without a usable font file the
 * writer falls back to the standard fonts Helvetica / Helvetica-Bold (WinAnsi): characters outside
 * Windows-1252 are transliterated (see winansi(): "ł" → "l", "Привет" → "Privet"), otherwise "?".
 *
 * Size and memory (A618, audit R2 #5): content streams are compressed (FlateDecode, when zlib is
 * available; filter `wpcap_pdf_compress`) and dark QR modules are merged into horizontal runs drawn as
 * one path. With a file name the document is streamed to that file page by page (file mode, see
 * finish()): every finished page is serialised right away and never held in memory as a whole. A QR
 * code is not stored as a Form XObject: every ticket has its own code, so an XObject would never be
 * reused and only add an object per page.
 */
namespace PassCaptain\Printing;

use PassCaptain\TempFiles;
use PassCaptain\TicketPdf;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class PdfWriter {
	const MM = 2.834645669;

	/** Font resource names: regular and bold (objects 3 and 4). */
	const FONTS = array( 'F1' => 3, 'F2' => 4 );

	/** Fixed object ids: catalog, page tree, the two fonts, document info. Pages start at FIRST_PAGE_ID. */
	const FIRST_PAGE_ID = 6;

	/** @var array<int,array{w:float,h:float,ops:string[]}> Pages not serialised yet (file mode: only the current one). */
	private $pages = array();
	private $current = -1;
	/** @var bool Embedded Unicode fonts (true) or standard fonts (false) for this document. */
	private $unicode;
	/** @var array<string,array<int,int>> font resource => glyph id => code point (ToUnicode). */
	private $used = array( 'F1' => array(), 'F2' => array() );

	/** @var string Target file (file mode) or ''. */
	private $file = '';
	/** @var string Bytes not yet written to the target file (file mode). */
	private $buffer = '';
	private $written = 0;
	private $failed = false;
	/** @var array<int,int> Object id => byte offset of the pages already serialised (file mode). */
	private $offsets = array();
	private $next = self::FIRST_PAGE_ID;
	private $flushed = 0;
	private $lang = '';
	/** @var float[]|null Text colour (r, g, b in 0..1) or null for black (A685). */
	private $rgb = null;

	/** Largest logo file read by load_image() (bytes). */
	const IMAGE_MAX_BYTES = 1048576;

	/** @var array|null Loaded fonts: F1/F2 => TrueTypeFont|null; null = not loaded yet. */
	private static $fonts = null;

	/**
	 * @param string|bool|null $file_or_unicode A file name: the PDF is streamed into it page by page
	 *                         (created with mode 0600; use a TempFiles::private_dir()), finish()
	 *                         completes it. Otherwise the font choice: null = embedded fonts when
	 *                         available; false = standard fonts (used to finish batch files begun before
	 *                         3.10.18 in the same encoding).
	 * @param bool|null        $unicode Font choice in file mode (see above).
	 */
	public function __construct( $file_or_unicode = null, $unicode = null ) {
		if ( is_string( $file_or_unicode ) && '' !== $file_or_unicode ) {
			$this->file = $file_or_unicode;
		} elseif ( null === $unicode && ! is_string( $file_or_unicode ) ) {
			$unicode = $file_or_unicode;
		}
		$this->unicode = null === $unicode ? self::unicode_available() : ( $unicode && self::unicode_available() );
		if ( '' !== $this->file ) { $this->emit( self::header() ); }
	}

	/** Whether this document embeds Unicode fonts. */
	public function is_unicode() {
		return $this->unicode;
	}

	/** Document language (BCP 47 or WordPress locale such as de_DE), written as /Lang of the catalog. */
	public function set_language( $locale ) {
		$this->lang = self::lang_tag( $locale );
		return $this;
	}

	/** BCP 47 form of a locale for /Lang ('' when empty). */
	private static function lang_tag( $locale ) {
		return (string) preg_replace( '/[^A-Za-z0-9-]/', '', str_replace( '_', '-', (string) $locale ) );
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

	/** Starts a new page of the given size in points (file mode: the previous one is serialised). */
	public function add_page( $width, $height ) {
		if ( '' !== $this->file ) { $this->flush_pages(); }
		$this->pages[] = array( 'w' => (float) $width, 'h' => (float) $height, 'ops' => array() );
		$this->current = count( $this->pages ) - 1;
		return $this;
	}

	public function page_count() { return $this->flushed + count( $this->pages ); }

	/** False once writing to the target file failed (file mode). */
	public function ok() { return ! $this->failed; }

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

	/**
	 * Splits text into lines of at most $max_width. Words longer than a line are shortened with fit().
	 * With $max_lines the last line is shortened ("...") when the text does not fit.
	 */
	public static function wrap( $text, $size, $max_width, $bold = false, $max_lines = 0 ) {
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
		// PDF text is plain text: stored entities ("Kids &lt;3") are decoded (A497).
		$text = \PassCaptain\Text::plain( $text );
		if ( '' === $text ) { return $this; }
		if ( 'center' === $align ) { $x -= self::text_width( $text, $size, $bold ) / 2; }
		elseif ( 'right' === $align ) { $x -= self::text_width( $text, $size, $bold ); }
		$font = $bold ? 'F2' : 'F1';
		$string = $this->unicode ? $this->encode( $font, $text ) : '(' . self::escape( $text ) . ')';
		$fonts = $this->unicode ? self::fonts() : array();
		$colour = $this->rgb ? vsprintf( '%1$.3F %2$.3F %3$.3F rg %1$.3F %2$.3F %3$.3F RG ', $this->rgb ) : '';
		if ( $bold && $fonts && $fonts['F1'] === $fonts['F2'] ) {
			// No bold font file: fill and stroke the outline (text state reset by Q).
			$this->op( sprintf( 'q %sBT /F2 %.2F Tf 2 Tr %.2F w %.2F %.2F Td %s Tj ET Q', $colour, $size, $size * 0.03, $x, $y, $string ) );
			return $this;
		}
		if ( '' !== $colour ) {
			$this->op( sprintf( 'q %sBT /%s %.2F Tf %.2F %.2F Td %s Tj ET Q', $colour, $font, $size, $x, $y, $string ) );
			return $this;
		}
		$this->op( sprintf( 'BT /%s %.2F Tf %.2F %.2F Td %s Tj ET', $font, $size, $x, $y, $string ) );
		return $this;
	}

	/**
	 * A685: colour of the following text (r, g, b in 0..1, or a #RRGGBB string); null = black.
	 *
	 * @param float[]|string|null $rgb Colour.
	 */
	public function set_color( $rgb ) {
		$this->rgb = self::rgb( $rgb );
		return $this;
	}

	/** Colour as three floats 0..1 from #RRGGBB or an array; null for anything else. */
	public static function rgb( $value ) {
		if ( is_string( $value ) && preg_match( '/^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', trim( $value ), $m ) ) {
			return array( hexdec( $m[1] ) / 255.0, hexdec( $m[2] ) / 255.0, hexdec( $m[3] ) / 255.0 );
		}
		if ( is_array( $value ) && 3 === count( $value ) ) {
			return array_map( static function ( $c ) { return max( 0.0, min( 1.0, (float) $c ) ); }, array_values( $value ) );
		}
		return null;
	}

	/** A685: filled rectangle in a colour (#RRGGBB or r, g, b in 0..1). */
	public function fill_rgb( $x, $y, $w, $h, $rgb ) {
		$rgb = self::rgb( $rgb );
		if ( ! $rgb ) { return $this; }
		$this->op( vsprintf( 'q %.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f Q', array_merge( $rgb, array( $x, $y, $w, $h ) ) ) );
		return $this;
	}

	/**
	 * A685: reads a logo for image(): JPEG (baseline or progressive, grey/RGB/CMYK) as it is, PNG with 8 bit
	 * grey or RGB without interlacing as its compressed data; other PNGs (transparency, palette) are
	 * flattened onto white with GD when available. Null for anything else, for files larger than
	 * IMAGE_MAX_BYTES and when the file cannot be read.
	 *
	 * @return array{w:int,h:int,cs:string,bpc:int,filter:string,parms:string,data:string}|null
	 */
	public static function load_image( $path ) {
		$path = (string) $path;
		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) { return null; }
		$size = (int) filesize( $path );
		if ( $size < 8 || $size > self::IMAGE_MAX_BYTES ) { return null; }
		$bytes = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local media file.
		return self::parse_image( $bytes );
	}

	/** Like load_image() for image bytes. */
	public static function parse_image( $bytes ) {
		$bytes = (string) $bytes;
		if ( "\xFF\xD8" === substr( $bytes, 0, 2 ) ) { return self::parse_jpeg( $bytes ); }
		if ( "\x89PNG\r\n\x1A\n" === substr( $bytes, 0, 8 ) ) { return self::parse_png( $bytes ); }
		return null;
	}

	private static function parse_jpeg( $bytes ) {
		$len = strlen( $bytes );
		$pos = 2;
		while ( $pos + 9 < $len ) {
			if ( "\xFF" !== $bytes[ $pos ] ) { return null; }
			$marker = ord( $bytes[ $pos + 1 ] );
			if ( 0xD8 === $marker || 0x01 === $marker || ( $marker >= 0xD0 && $marker <= 0xD7 ) ) { $pos += 2; continue; }
			$segment = unpack( 'n', substr( $bytes, $pos + 2, 2 ) )[1];
			// SOF0..SOF15 without DHT (C4), JPG (C8) and DAC (CC).
			if ( $marker >= 0xC0 && $marker <= 0xCF && ! in_array( $marker, array( 0xC4, 0xC8, 0xCC ), true ) ) {
				$info = unpack( 'Cbpc/nh/nw/Cn', substr( $bytes, $pos + 4, 6 ) );
				$spaces = array( 1 => 'G', 3 => 'RGB', 4 => 'CMYK' );
				if ( ! isset( $spaces[ $info['n'] ] ) || $info['w'] < 1 || $info['h'] < 1 ) { return null; }
				return array( 'w' => (int) $info['w'], 'h' => (int) $info['h'], 'cs' => $spaces[ $info['n'] ], 'bpc' => 8, 'filter' => 'DCT', 'parms' => '', 'data' => $bytes );
			}
			$pos += 2 + $segment;
		}
		return null;
	}

	private static function parse_png( $bytes ) {
		$ihdr = unpack( 'Nw/Nh/Cdepth/Ctype/Ccomp/Cfilter/Cinterlace', substr( $bytes, 16, 13 ) );
		if ( ! $ihdr || $ihdr['w'] < 1 || $ihdr['h'] < 1 ) { return null; }
		if ( 8 === $ihdr['depth'] && in_array( $ihdr['type'], array( 0, 2 ), true ) && 0 === $ihdr['interlace'] ) {
			$data = '';
			$pos = 8;
			$len = strlen( $bytes );
			while ( $pos + 8 <= $len ) {
				$chunk = unpack( 'N', substr( $bytes, $pos, 4 ) )[1];
				$type = substr( $bytes, $pos + 4, 4 );
				if ( 'IDAT' === $type ) { $data .= substr( $bytes, $pos + 8, $chunk ); }
				if ( 'IEND' === $type ) { break; }
				$pos += 12 + $chunk;
			}
			if ( '' === $data ) { return null; }
			$colors = 2 === $ihdr['type'] ? 3 : 1;
			return array( 'w' => (int) $ihdr['w'], 'h' => (int) $ihdr['h'], 'cs' => 3 === $colors ? 'RGB' : 'G', 'bpc' => 8, 'filter' => 'Fl', 'parms' => sprintf( ' /DP [null << /Predictor 15 /Colors %d /BitsPerComponent 8 /Columns %d >>]', $colors, (int) $ihdr['w'] ), 'data' => $data );
		}
		// Transparency or palette: flattened onto white and re-encoded as JPEG (GD).
		if ( ! function_exists( 'imagecreatefromstring' ) || ! function_exists( 'imagejpeg' ) || $ihdr['w'] * $ihdr['h'] > 4000000 ) { return null; }
		$src = @imagecreatefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- broken files give false.
		if ( ! $src ) { return null; }
		$w = imagesx( $src );
		$h = imagesy( $src );
		$canvas = imagecreatetruecolor( $w, $h );
		imagefill( $canvas, 0, 0, (int) imagecolorallocate( $canvas, 255, 255, 255 ) );
		imagecopy( $canvas, $src, 0, 0, 0, 0, $w, $h );
		ob_start();
		imagejpeg( $canvas, null, 92 );
		$jpeg = (string) ob_get_clean();
		return self::parse_jpeg( $jpeg );
	}

	/**
	 * A685: draws an image from load_image() into the box ($x, $y, $w, $h) keeping its aspect ratio
	 * (centred, or left-aligned with $align 'left'); returns the drawn [width, height] or null. Inline
	 * image (hex encoded) – a logo is small and every page carries its own copy, so no shared object
	 * is needed and the streamed and batch files keep their two objects per page.
	 */
	public function image( $x, $y, $w, $h, $img, $align = 'center' ) {
		if ( ! is_array( $img ) || empty( $img['w'] ) || empty( $img['h'] ) || $w <= 0 || $h <= 0 ) { return null; }
		$scale = min( $w / $img['w'], $h / $img['h'] );
		$dw = $img['w'] * $scale;
		$dh = $img['h'] * $scale;
		$dx = 'left' === $align ? $x : ( 'right' === $align ? $x + $w - $dw : $x + ( $w - $dw ) / 2 );
		$dy = $y + ( $h - $dh ) / 2;
		$this->op( sprintf( "q %.2F 0 0 %.2F %.2F %.2F cm\nBI /W %d /H %d /CS /%s /BPC %d /F [/AHx /%s]%s\nID\n%s>\nEI Q", $dw, $dh, $dx, $dy, $img['w'], $img['h'], $img['cs'], $img['bpc'], $img['filter'], $img['parms'], chunk_split( bin2hex( $img['data'] ), 128, "\n" ) ) );
		return array( $dw, $dh );
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

	/**
	 * A618: whether page content streams are compressed (FlateDecode). Default on when zlib is
	 * available; filter `wpcap_pdf_compress` (false e.g. to inspect the PDF operators).
	 */
	public static function compress() {
		return function_exists( 'gzcompress' ) && (bool) apply_filters( 'wpcap_pdf_compress', true );
	}

	private function op( $op ) {
		if ( $this->current < 0 ) { $this->add_page( 595.28, 841.89 ); }
		$this->pages[ $this->current ]['ops'][] = $op;
	}

	/** Content stream object body (compressed when compress() allows it). */
	public static function stream( $content ) {
		$content = (string) $content;
		$packed = self::compress() ? gzcompress( $content, 6 ) : false;
		if ( is_string( $packed ) ) {
			return '<< /Length ' . strlen( $packed ) . " /Filter /FlateDecode >>\nstream\n" . $packed . "\nendstream";
		}
		return '<< /Length ' . strlen( $content ) . " >>\nstream\n" . $content . "\nendstream";
	}

	/** PDF bytes. A document without pages gets one empty A4 page (PDFs need at least one page). */
	public function output( $title = '' ) {
		if ( '' !== $this->file ) {
			// File mode: the document is completed in the file; nothing is returned.
			$this->finish( $title );
			return '';
		}
		$pdf = self::header();
		$body = $this->page_objects( self::FIRST_PAGE_ID, strlen( $pdf ) );
		$pdf .= $body['bytes'];
		return $pdf . self::trailer( $title, $body['offsets'], strlen( $pdf ), $this->unicode ? $this->used : false, $this->lang );
	}

	/** Completes a streamed document (file mode). False when a write failed: the file is incomplete. */
	public function finish( $title = '' ) {
		if ( '' === $this->file ) { return false; }
		$this->flush_pages();
		$this->emit( self::trailer( $title, $this->offsets, $this->written, $this->unicode ? $this->used : false, $this->lang ) );
		$this->drain();
		return ! $this->failed;
	}

	/** Serialises the pending pages into the target file (file mode). */
	private function flush_pages() {
		if ( ! $this->pages || $this->failed ) { return; }
		$body = $this->page_objects( $this->next, $this->written );
		$this->offsets += $body['offsets'];
		$this->next += 2 * count( $this->pages );
		$this->flushed += count( $this->pages );
		$this->pages = array();
		$this->current = -1;
		$this->emit( $body['bytes'] );
	}

	private function emit( $bytes ) {
		if ( $this->failed ) { return; }
		// File mode: written in chunks of about 256 KB (few appends, bounded memory).
		$this->buffer .= $bytes;
		$this->written += strlen( $bytes );
		if ( strlen( $this->buffer ) >= 262144 ) { $this->drain(); }
	}

	/** Appends the buffered bytes to the target file (file mode). */
	private function drain() {
		if ( '' === $this->file || '' === $this->buffer || $this->failed ) { return; }
		if ( ! TempFiles::append( $this->file, $this->buffer ) ) { $this->failed = true; }
		$this->buffer = '';
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
			$bytes .= $content_id . " 0 obj\n" . self::stream( $content ) . "\nendobj\n";
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
	 * @param string           $lang    Document language (/Lang of the catalog), '' = none.
	 */
	public static function trailer( $title, array $offsets, $base, $glyphs = false, $lang = '' ) {
		$bytes = '';
		$base = (int) $base;
		$unicode = false !== $glyphs && self::unicode_available();
		if ( ! $offsets ) {
			$empty = ( new self( $unicode ) )->add_page( 595.28, 841.89 )->page_objects( self::FIRST_PAGE_ID, $base );
			$bytes .= $empty['bytes'];
			$offsets = $empty['offsets'];
		}
		ksort( $offsets );
		$kids = array();
		foreach ( array_keys( $offsets ) as $i => $id ) {
			if ( 0 === $i % 2 ) { $kids[] = $id . ' 0 R'; }
		}
		$fixed = array(
			1 => '<< /Type /Catalog /Pages 2 0 R' . ( '' !== self::lang_tag( $lang ) ? ' /Lang (' . self::lang_tag( $lang ) . ')' : '' ) . ' >>',
			2 => '<< /Type /Pages /Kids [' . implode( ' ', $kids ) . '] /Count ' . count( $kids ) . ' >>',
			3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
			4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
			5 => '<< /Producer ' . self::info_string( class_exists( '\\PassCaptain\\Brand' ) ? \PassCaptain\Brand::name( 'pdf' ) : 'Pass Captain' ) . ( '' !== (string) $title ? ' /Title ' . self::info_string( $title ) : '' ) . ' >>',
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

	/** UTF-8 to WinAnsi (standard fonts) with PDF string escaping, see winansi(). */
	public static function escape( $str ) {
		return str_replace( array( '\\', '(', ')', "\r", "\n" ), array( '\\\\', '\\(', '\\)', ' ', ' ' ), self::winansi( $str ) );
	}

	/**
	 * UTF-8 to Windows-1252 (the encoding of the standard Helvetica font). Characters WinAnsi cannot
	 * show are transliterated instead of becoming "?" (Ł → L, ć → c, Привет → Privet, 東京 → dong jing),
	 * with the Intl transliterator or iconv; only what neither can map becomes "?". Used only without an
	 * embeddable Unicode font (see fonts()).
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

	/** True when a text contains characters the PDF fonts cannot show and that are transliterated (standard fonts only). */
	public static function needs_transliteration( $str ) {
		if ( self::unicode_available() ) { return false; }
		$str = (string) $str;
		if ( ! preg_match( '/[^\x00-\x7F]/', $str ) || ! function_exists( 'mb_convert_encoding' ) ) { return false; }
		foreach ( preg_split( '//u', $str, -1, PREG_SPLIT_NO_EMPTY ) as $ch ) {
			if ( strlen( $ch ) > 1 && ! self::representable( $ch ) ) { return true; }
		}
		return false;
	}
}

<?php
/**
 * Minimal TrueType reader and subsetter for the PDF writer (A330): metrics, Unicode → glyph
 * mapping (cmap formats 4 and 12), advance widths and a subset font program that keeps the glyph
 * numbering (unused glyphs are emptied, composite glyphs keep their components), so a PDF can
 * embed it as CIDFontType2 with /CIDToGIDMap /Identity.
 *
 * Only fonts with TrueType outlines (glyf table) are supported – OpenType/CFF (.otf) and
 * collections (.ttc) are rejected, as are fonts whose licence forbids embedding (OS/2 fsType 2).
 */
namespace PassCaptain\Printing;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class TrueTypeFont {
	/** @var string PostScript name (letters, digits, hyphen). */
	public $name = 'Font';
	public $units_per_em = 1000;
	/** @var int[] xMin, yMin, xMax, yMax in 1000 units. */
	public $bbox = array( 0, 0, 1000, 1000 );
	public $ascent = 800;
	public $descent = -200;
	public $cap_height = 700;
	public $italic_angle = 0;
	public $fixed_pitch = false;
	public $bold = false;
	public $num_glyphs = 0;

	private $data;
	/** @var array<string,array{0:int,1:int}> tag => [offset, length] */
	private $tables = array();
	private $advances = array();
	private $cmap = null;
	private $loca = null;

	/** Parses a TrueType file; null when it is missing, unreadable or not supported. */
	public static function load( $path ) {
		$path = (string) $path;
		if ( '' === $path || ! is_readable( $path ) ) { return null; }
		$data = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local font file.
		if ( ! is_string( $data ) || strlen( $data ) < 12 ) { return null; }
		try {
			return new self( $data );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	private function __construct( $data ) {
		$this->data = $data;
		$version = substr( $data, 0, 4 );
		if ( "\x00\x01\x00\x00" !== $version && 'true' !== $version ) { throw new \RuntimeException( 'not a TrueType font' ); }
		$count = $this->u16( 4 );
		for ( $i = 0; $i < $count; $i++ ) {
			$at = 12 + 16 * $i;
			$this->tables[ substr( $data, $at, 4 ) ] = array( $this->u32( $at + 8 ), $this->u32( $at + 12 ) );
		}
		foreach ( array( 'head', 'hhea', 'maxp', 'hmtx', 'loca', 'glyf', 'cmap' ) as $required ) {
			if ( ! isset( $this->tables[ $required ] ) ) { throw new \RuntimeException( 'missing table' ); }
		}
		$head = $this->tables['head'][0];
		$this->units_per_em = max( 16, $this->u16( $head + 18 ) );
		$scale = 1000 / $this->units_per_em;
		$this->bbox = array( (int) round( $this->s16( $head + 36 ) * $scale ), (int) round( $this->s16( $head + 38 ) * $scale ), (int) round( $this->s16( $head + 40 ) * $scale ), (int) round( $this->s16( $head + 42 ) * $scale ) );
		$this->bold = (bool) ( $this->u16( $head + 44 ) & 1 );
		$hhea = $this->tables['hhea'][0];
		$this->ascent = (int) round( $this->s16( $hhea + 4 ) * $scale );
		$this->descent = (int) round( $this->s16( $hhea + 6 ) * $scale );
		$metrics = $this->u16( $hhea + 34 );
		$this->num_glyphs = $this->u16( $this->tables['maxp'][0] + 4 );
		$this->cap_height = $this->ascent;
		if ( isset( $this->tables['OS/2'] ) ) {
			$os2 = $this->tables['OS/2'][0];
			if ( 2 === ( $this->u16( $os2 + 8 ) & 0x000F ) ) { throw new \RuntimeException( 'embedding not permitted' ); }
			if ( $this->u16( $os2 ) >= 2 && $this->tables['OS/2'][1] >= 90 ) { $this->cap_height = (int) round( $this->s16( $os2 + 88 ) * $scale ); }
		}
		if ( isset( $this->tables['post'] ) ) {
			$post = $this->tables['post'][0];
			$this->italic_angle = $this->s32( $post + 4 ) / 65536;
			$this->fixed_pitch = 0 !== $this->u32( $post + 12 );
		}
		$hmtx = $this->tables['hmtx'][0];
		for ( $i = 0; $i < $metrics; $i++ ) { $this->advances[] = $this->u16( $hmtx + 4 * $i ); }
		$this->name = $this->postscript_name();
	}

	/** Glyph id of a Unicode code point (0 = .notdef, not in the font). */
	public function glyph( $codepoint ) {
		if ( null === $this->cmap ) { $this->cmap = $this->read_cmap(); }
		return (int) ( $this->cmap[ (int) $codepoint ] ?? 0 );
	}

	/** Code point => glyph id of every mapped character. */
	public function cmap() {
		if ( null === $this->cmap ) { $this->cmap = $this->read_cmap(); }
		return $this->cmap;
	}

	/** Advance width of a glyph in 1/1000 em. */
	public function advance( $gid ) {
		$gid = (int) $gid;
		$raw = $this->advances[ $gid ] ?? ( $this->advances ? end( $this->advances ) : 0 );
		return (int) round( $raw * 1000 / $this->units_per_em );
	}

	/** Width of a UTF-8 string in 1/1000 em. */
	public function width( $text ) {
		$sum = 0;
		foreach ( self::codepoints( $text ) as $cp ) { $sum += $this->advance( $this->glyph( $cp ) ); }
		return $sum;
	}

	/** Unicode code points of a UTF-8 string (invalid bytes become U+FFFD). */
	public static function codepoints( $text ) {
		$text = (string) $text;
		if ( '' === $text ) { return array(); }
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$utf32 = mb_convert_encoding( $text, 'UTF-32BE', 'UTF-8' );
			$out = unpack( 'N*', $utf32 );
			return is_array( $out ) ? array_values( $out ) : array();
		}
		return preg_match_all( '/./us', $text, $m ) ? array_map( static function ( $ch ) {
			$c = unpack( 'C*', $ch );
			$n = count( $c );
			if ( 1 === $n ) { return $c[1]; }
			if ( 2 === $n ) { return ( ( $c[1] & 0x1F ) << 6 ) | ( $c[2] & 0x3F ); }
			if ( 3 === $n ) { return ( ( $c[1] & 0x0F ) << 12 ) | ( ( $c[2] & 0x3F ) << 6 ) | ( $c[3] & 0x3F ); }
			return ( ( $c[1] & 0x07 ) << 18 ) | ( ( $c[2] & 0x3F ) << 12 ) | ( ( $c[3] & 0x3F ) << 6 ) | ( $c[4] & 0x3F );
		}, $m[0] ) : array();
	}

	/**
	 * Font program with only the given glyphs (plus .notdef and the components of composite
	 * glyphs); glyph ids stay the same. Tables: head, hhea, maxp, hmtx, loca, glyf, cvt, fpgm, prep,
	 * OS/2, name and post (version 3, without glyph names).
	 *
	 * @param int[] $gids Glyph ids used by the document.
	 */
	public function subset( array $gids ) {
		$loca = $this->loca();
		$glyf = $this->tables['glyf'][0];
		$keep = array( 0 => true );
		$queue = array_values( array_unique( array_map( 'intval', $gids ) ) );
		while ( $queue ) {
			$gid = array_pop( $queue );
			if ( $gid < 0 || $gid >= $this->num_glyphs ) { continue; }
			$keep[ $gid ] = true;
			foreach ( $this->components( $glyf + $loca[ $gid ], $loca[ $gid + 1 ] - $loca[ $gid ] ) as $component ) {
				if ( ! isset( $keep[ $component ] ) ) { $queue[] = $component; }
			}
		}
		$new_glyf = '';
		$offsets = array();
		for ( $gid = 0; $gid < $this->num_glyphs; $gid++ ) {
			$offsets[] = strlen( $new_glyf );
			if ( isset( $keep[ $gid ] ) && $loca[ $gid + 1 ] > $loca[ $gid ] ) {
				$new_glyf .= substr( $this->data, $glyf + $loca[ $gid ], $loca[ $gid + 1 ] - $loca[ $gid ] );
				$new_glyf .= str_repeat( "\0", ( 4 - strlen( $new_glyf ) % 4 ) % 4 );
			}
		}
		$offsets[] = strlen( $new_glyf );
		$short = strlen( $new_glyf ) <= 0x1FFFE; // Short loca stores offset / 2.
		$new_loca = $short ? pack( 'n*', ...array_map( static function ( $o ) { return $o >> 1; }, $offsets ) ) : pack( 'N*', ...$offsets );
		$head = $this->table( 'head' );
		$head = substr( $head, 0, 8 ) . "\0\0\0\0" . substr( $head, 12, 38 ) . pack( 'n', $short ? 0 : 1 ) . substr( $head, 52 ); // checkSumAdjustment 0.
		// Metrics of unused glyphs are zeroed (the PDF carries the widths; zeros compress well).
		$hmtx = $this->table( 'hmtx' );
		$metrics = count( $this->advances );
		$new_hmtx = '';
		for ( $gid = 0; $gid < $metrics; $gid++ ) { $new_hmtx .= isset( $keep[ $gid ] ) ? substr( $hmtx, 4 * $gid, 4 ) : "\0\0\0\0"; }
		for ( $gid = $metrics; $gid < $this->num_glyphs; $gid++ ) { $new_hmtx .= isset( $keep[ $gid ] ) ? substr( $hmtx, 4 * $metrics + 2 * ( $gid - $metrics ), 2 ) : "\0\0"; }
		$tables = array( 'head' => $head, 'hhea' => $this->table( 'hhea' ), 'maxp' => $this->table( 'maxp' ), 'hmtx' => $new_hmtx, 'loca' => $new_loca, 'glyf' => $new_glyf );
		foreach ( array( 'cvt ', 'fpgm', 'prep', 'OS/2', 'name' ) as $tag ) {
			if ( isset( $this->tables[ $tag ] ) ) { $tables[ $tag ] = $this->table( $tag ); }
		}
		if ( isset( $this->tables['post'] ) && $this->tables['post'][1] >= 32 ) { $tables['post'] = pack( 'N', 0x00030000 ) . substr( $this->table( 'post' ), 4, 28 ); }
		return self::assemble( $tables );
	}

	/** Builds an sfnt file from tables (tag => bytes). */
	private static function assemble( array $tables ) {
		ksort( $tables, SORT_STRING );
		$count = count( $tables );
		$power = 1;
		$selector = 0;
		while ( $power * 2 <= $count ) { $power *= 2; ++$selector; }
		$out = pack( 'Nnnnn', 0x00010000, $count, $power * 16, $selector, $count * 16 - $power * 16 );
		$offset = 12 + 16 * $count;
		$body = '';
		foreach ( $tables as $tag => $bytes ) {
			$padded = $bytes . str_repeat( "\0", ( 4 - strlen( $bytes ) % 4 ) % 4 );
			$out .= $tag . pack( 'NNN', self::checksum( $padded ), $offset + strlen( $body ), strlen( $bytes ) );
			$body .= $padded;
		}
		return $out . $body;
	}

	private static function checksum( $bytes ) {
		$sum = 0;
		foreach ( unpack( 'N*', $bytes ) as $word ) { $sum = ( $sum + $word ) & 0xFFFFFFFF; }
		return $sum;
	}

	/** Glyph ids referenced by a composite glyph (empty for simple glyphs). */
	private function components( $offset, $length ) {
		if ( $length < 10 || $this->s16( $offset ) >= 0 ) { return array(); }
		$out = array();
		$at = $offset + 10;
		$end = $offset + $length;
		do {
			if ( $at + 4 > $end ) { break; }
			$flags = $this->u16( $at );
			$out[] = $this->u16( $at + 2 );
			$at += 4 + ( $flags & 0x0001 ? 4 : 2 );
			if ( $flags & 0x0008 ) {
				$at += 2;
			} elseif ( $flags & 0x0040 ) {
				$at += 4;
			} elseif ( $flags & 0x0080 ) {
				$at += 8;
			}
		} while ( $flags & 0x0020 );
		return $out;
	}

	/** Glyph offsets inside glyf (num_glyphs + 1 entries). */
	private function loca() {
		if ( null !== $this->loca ) { return $this->loca; }
		$long = 1 === $this->s16( $this->tables['head'][0] + 50 );
		$at = $this->tables['loca'][0];
		$out = array();
		for ( $i = 0; $i <= $this->num_glyphs; $i++ ) { $out[] = $long ? $this->u32( $at + 4 * $i ) : 2 * $this->u16( $at + 2 * $i ); }
		return $this->loca = $out;
	}

	/** Unicode cmap (platform 3/10 format 12, else 3/1 or 0/x format 4). */
	private function read_cmap() {
		$base = $this->tables['cmap'][0];
		$count = $this->u16( $base + 2 );
		$best = null;
		$rank = 0;
		for ( $i = 0; $i < $count; $i++ ) {
			$platform = $this->u16( $base + 4 + 8 * $i );
			$encoding = $this->u16( $base + 6 + 8 * $i );
			$sub = $base + $this->u32( $base + 8 + 8 * $i );
			$format = $this->u16( $sub );
			$r = 12 === $format && ( 3 === $platform || 0 === $platform ) ? 3 : ( 4 === $format && ( ( 3 === $platform && 1 === $encoding ) || 0 === $platform ) ? 2 : 0 );
			if ( $r > $rank ) { $rank = $r; $best = $sub; }
		}
		if ( null === $best ) { return array(); }
		$map = array();
		if ( 12 === $this->u16( $best ) ) {
			$groups = $this->u32( $best + 12 );
			for ( $g = 0; $g < $groups; $g++ ) {
				$at = $best + 16 + 12 * $g;
				$start = $this->u32( $at );
				$end = min( $this->u32( $at + 4 ), $start + 0x10000 );
				$glyph = $this->u32( $at + 8 );
				for ( $c = $start; $c <= $end; $c++ ) { $map[ $c ] = $glyph + $c - $start; }
			}
			return $map;
		}
		$segments = $this->u16( $best + 6 ) / 2;
		$ends = $best + 14;
		$starts = $ends + 2 * $segments + 2;
		$deltas = $starts + 2 * $segments;
		$ranges = $deltas + 2 * $segments;
		for ( $s = 0; $s < $segments; $s++ ) {
			$start = $this->u16( $starts + 2 * $s );
			$end = $this->u16( $ends + 2 * $s );
			$delta = $this->s16( $deltas + 2 * $s );
			$range = $this->u16( $ranges + 2 * $s );
			for ( $c = $start; $c <= $end && 0xFFFF !== $c; $c++ ) {
				if ( 0 === $range ) {
					$glyph = ( $c + $delta ) & 0xFFFF;
				} else {
					$glyph = $this->u16( $ranges + 2 * $s + $range + 2 * ( $c - $start ) );
					if ( $glyph ) { $glyph = ( $glyph + $delta ) & 0xFFFF; }
				}
				if ( $glyph ) { $map[ $c ] = $glyph; }
			}
		}
		return $map;
	}

	/** PostScript name (name id 6) reduced to letters, digits and hyphens. */
	private function postscript_name() {
		if ( ! isset( $this->tables['name'] ) ) { return 'Font'; }
		$base = $this->tables['name'][0];
		$count = $this->u16( $base + 2 );
		$strings = $base + $this->u16( $base + 4 );
		for ( $i = 0; $i < $count; $i++ ) {
			$at = $base + 6 + 12 * $i;
			if ( 6 !== $this->u16( $at + 6 ) ) { continue; }
			$raw = substr( $this->data, $strings + $this->u16( $at + 10 ), $this->u16( $at + 8 ) );
			$text = 1 === $this->u16( $at ) ? $raw : ( function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( $raw, 'UTF-8', 'UTF-16BE' ) : str_replace( "\0", '', $raw ) );
			$name = preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $text );
			if ( '' !== $name ) { return \PassCaptain\Text::cut( $name, 60 ); }
		}
		return 'Font';
	}

	private function table( $tag ) {
		return substr( $this->data, $this->tables[ $tag ][0], $this->tables[ $tag ][1] );
	}

	private function u16( $at ) {
		$v = unpack( 'n', substr( $this->data, $at, 2 ) );
		return is_array( $v ) ? (int) $v[1] : 0;
	}

	private function s16( $at ) {
		$v = $this->u16( $at );
		return $v >= 0x8000 ? $v - 0x10000 : $v;
	}

	private function u32( $at ) {
		$v = unpack( 'N', substr( $this->data, $at, 4 ) );
		return is_array( $v ) ? (int) $v[1] : 0;
	}

	private function s32( $at ) {
		$v = $this->u32( $at );
		return $v >= 0x80000000 ? $v - 0x100000000 : $v;
	}
}

<?php
/** Zip writer (ZipArchive, or a minimal stored-only writer when the extension is missing) and PNG helpers. */
namespace PassCaptain\WalletPasses;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Archive {
	/**
	 * @param array<string,string> $files name => bytes
	 * @param bool                 $force_fallback Use the built-in writer even if ZipArchive exists.
	 */
	public static function zip( array $files, $force_fallback = false ) {
		if ( ! $force_fallback && class_exists( '\ZipArchive' ) ) {
			$path = tempnam( get_temp_dir(), 'wpcap-pass' );
			$zip = new \ZipArchive();
			if ( true === $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
				foreach ( $files as $name => $bytes ) { $zip->addFromString( $name, $bytes ); }
				$zip->close();
				$out = (string) file_get_contents( $path );
				wp_delete_file( $path );
				if ( '' !== $out ) { return $out; }
			}
			wp_delete_file( $path );
		}
		return self::stored_zip( $files );
	}

	/** Minimal PKZIP writer: no compression, no data descriptors. */
	public static function stored_zip( array $files ) {
		$data = '';
		$central = '';
		$count = 0;
		foreach ( $files as $name => $bytes ) {
			$name = (string) $name;
			$crc = crc32( $bytes );
			$size = strlen( $bytes );
			$offset = strlen( $data );
			// DOS time/date 1980-01-01 00:00 keeps the output deterministic.
			$common = pack( 'vvvvvVVVv', 20, 0, 0, 0, 0x0021, $crc, $size, $size, strlen( $name ) );
			$data .= "PK\x03\x04" . $common . pack( 'v', 0 ) . $name . $bytes;
			$central .= "PK\x01\x02" . pack( 'v', 20 ) . $common . pack( 'vvvvVV', 0, 0, 0, 0, 0, $offset ) . $name;
			$count++;
		}
		return $data . $central . "PK\x05\x06" . pack( 'vvvvVVv', 0, 0, $count, $count, strlen( $central ), strlen( $data ), 0 );
	}

	/** Hex colour (#rrggbb) to [r,g,b]. */
	public static function rgb( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( ! preg_match( '/^[0-9a-f]{6}$/i', $hex ) ) { $hex = '1d2327'; }
		return array( hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) );
	}

	/** Dependency-free solid colour RGB PNG. */
	public static function solid_png( $width, $height, array $rgb ) {
		$row = "\0" . str_repeat( chr( $rgb[0] ) . chr( $rgb[1] ) . chr( $rgb[2] ), $width );
		$chunk = static function ( $type, $body ) { return pack( 'N', strlen( $body ) ) . $type . $body . pack( 'N', crc32( $type . $body ) ); };
		return "\x89PNG\r\n\x1a\n" . $chunk( 'IHDR', pack( 'NNCCCCC', $width, $height, 8, 2, 0, 0, 0 ) ) . $chunk( 'IDAT', gzcompress( str_repeat( $row, $height ) ) ) . $chunk( 'IEND', '' );
	}

	/** Ticket-shaped icon (GD) or a solid square without GD. */
	public static function icon_png( $size, $background, $foreground ) {
		$bg = self::rgb( $background );
		if ( ! function_exists( 'imagecreatetruecolor' ) ) { return self::solid_png( $size, $size, $bg ); }
		$fg = self::rgb( $foreground );
		$img = imagecreatetruecolor( $size, $size );
		imagefill( $img, 0, 0, imagecolorallocate( $img, $bg[0], $bg[1], $bg[2] ) );
		$fill = imagecolorallocate( $img, $fg[0], $fg[1], $fg[2] );
		$m = (int) round( $size * 0.18 );
		imagefilledrectangle( $img, $m, (int) round( $size * 0.3 ), $size - $m, (int) round( $size * 0.7 ), $fill );
		$notch = imagecolorallocate( $img, $bg[0], $bg[1], $bg[2] );
		$d = max( 2, (int) round( $size * 0.16 ) );
		imagefilledellipse( $img, $m, (int) round( $size / 2 ), $d, $d, $notch );
		imagefilledellipse( $img, $size - $m, (int) round( $size / 2 ), $d, $d, $notch );
		return self::gd_png( $img );
	}

	/** Logo strip with the organisation name (transparent background). */
	public static function logo_png( $width, $height, $text, $foreground ) {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) { return self::solid_png( $width, $height, self::rgb( '#ffffff' ) ); }
		$fg = self::rgb( $foreground );
		$base_w = (int) ( $width / ( $height / 50 ) );
		$img = imagecreatetruecolor( $base_w, 50 );
		imagesavealpha( $img, true );
		imagefill( $img, 0, 0, imagecolorallocatealpha( $img, 0, 0, 0, 127 ) );
		$text = substr( preg_replace( '/[^\x20-\x7e]/', '', (string) $text ), 0, 17 );
		imagestring( $img, 5, 2, 17, $text, imagecolorallocate( $img, $fg[0], $fg[1], $fg[2] ) );
		if ( 50 !== $height ) {
			$scaled = imagecreatetruecolor( $width, $height );
			imagesavealpha( $scaled, true );
			imagefill( $scaled, 0, 0, imagecolorallocatealpha( $scaled, 0, 0, 0, 127 ) );
			imagecopyresampled( $scaled, $img, 0, 0, 0, 0, $width, $height, $base_w, 50 );
			$img = $scaled;
		}
		return self::gd_png( $img );
	}

	private static function gd_png( $img ) {
		ob_start();
		imagepng( $img );
		return (string) ob_get_clean();
	}
}

<?php
/**
 * QR codes for ticket pages, PDFs, receipts and device pairing.
 *
 * The module matrix comes straight from the bundled bacon/bacon-qr-code encoder (Encoder::encode(), an API
 * that is the same in Bacon 2.x and 3.x); the SVG is drawn here. No QR library builder or writer is used, so
 * another plugin that loads a different endroid/qr-code or Bacon writer first cannot break the ticket QR
 * code. The vendor namespaces are not prefixed: if another plugin loads an incompatible Bacon version first,
 * matrix() returns an empty array and the system status page warns (foreign_encoder()).
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class QrCode {
	/** Quiet zone around the code in modules (ISO/IEC 18004 asks for 4). */
	const QUIET_ZONE = 4;

	/**
	 * QR modules as rows of booleans (true = dark), or an empty array when no encoder is available.
	 *
	 * @param string $payload Text to encode (UTF-8).
	 * @return array<int, array<int, bool>>
	 */
	public static function matrix( $payload ) {
		$payload = (string) $payload;
		if ( '' === $payload || ! class_exists( '\\BaconQrCode\\Encoder\\Encoder' ) || ! class_exists( '\\BaconQrCode\\Common\\ErrorCorrectionLevel' ) ) { return array(); }
		try {
			$qr     = \BaconQrCode\Encoder\Encoder::encode( $payload, \BaconQrCode\Common\ErrorCorrectionLevel::M(), 'UTF-8' );
			$matrix = $qr->getMatrix();
			$rows   = array();
			$height = $matrix->getHeight();
			$width  = $matrix->getWidth();
			for ( $y = 0; $y < $height; $y++ ) {
				for ( $x = 0; $x < $width; $x++ ) { $rows[ $y ][ $x ] = 1 === $matrix->get( $x, $y ); }
			}
			return $rows;
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/**
	 * SVG markup of the QR code: one path of unit squares on a white background (crisp at any size).
	 *
	 * @param string $payload Text to encode.
	 * @param int    $size    Width and height in pixels.
	 * @return string '' when no encoder is available.
	 */
	public static function svg( $payload, $size = 320 ) {
		$rows = self::matrix( $payload );
		if ( ! $rows ) { return ''; }
		$q    = self::QUIET_ZONE;
		$dim  = count( $rows ) + 2 * $q;
		$path = '';
		foreach ( $rows as $y => $row ) {
			$x = 0;
			$n = count( $row );
			while ( $x < $n ) {
				if ( ! $row[ $x ] ) { ++$x; continue; }
				$start = $x;
				while ( $x < $n && $row[ $x ] ) { ++$x; }
				// One horizontal run of dark modules per subpath.
				$path .= 'M' . ( $start + $q ) . ' ' . ( $y + $q ) . 'h' . ( $x - $start ) . 'v1h-' . ( $x - $start ) . 'z';
			}
		}
		$size = max( 1, (int) $size );
		return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 ' . $dim . ' ' . $dim . '" shape-rendering="crispEdges">'
			. '<rect width="' . $dim . '" height="' . $dim . '" fill="#fff"/><path fill="#000" d="' . $path . '"/></svg>';
	}

	/** data: URI of the SVG QR code for <img src>, or '' when no encoder is available. */
	public static function data_uri( $payload ) {
		$svg = self::svg( $payload );
		return '' === $svg ? '' : 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- data URI.
	}

	/**
	 * File the Bacon encoder was loaded from when it is not this plugin's vendor copy (another plugin loaded
	 * its own Bacon first), '' otherwise or when it is not loaded at all. Shown on the system status page.
	 */
	public static function foreign_encoder() {
		if ( ! class_exists( '\\BaconQrCode\\Encoder\\Encoder' ) ) { return ''; }
		try {
			$file = (string) ( new \ReflectionClass( '\\BaconQrCode\\Encoder\\Encoder' ) )->getFileName();
		} catch ( \ReflectionException $e ) {
			return '';
		}
		$own = str_replace( '\\', '/', WPCAP_PATH . 'vendor/' );
		return '' === $file || 0 === strpos( str_replace( '\\', '/', $file ), $own ) ? '' : $file;
	}
}

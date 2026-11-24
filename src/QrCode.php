<?php
/**
 * QR codes for ticket pages, PDFs, receipts and device pairing.
 *
 * The module matrix comes straight from the bundled bacon/bacon-qr-code encoder (Encoder::encode()); the SVG
 * is drawn here. A103: the encoder and its dependency dasprid/enum are shipped namespace-prefixed
 * (vendor-prefixed/, PassCaptain\Vendor\BaconQrCode\ via Strauss, build/prefix-vendor.sh), so another plugin
 * that loads a different endroid/qr-code or BaconQrCode version can neither replace nor break the classes
 * used here. If the encoder is missing anyway, matrix() returns an empty array and the system status page
 * reports it (foreign_encoder() covers a copy loaded from outside this plugin, e.g. a second plugin copy).
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class QrCode {
	/** Quiet zone around the code in modules (ISO/IEC 18004 asks for 4). */
	const QUIET_ZONE = 4;

	/** The namespace-prefixed Bacon encoder class (vendor-prefixed/, A103). */
	const ENCODER = '\\PassCaptain\\Vendor\\BaconQrCode\\Encoder\\Encoder';

	/**
	 * QR modules as rows of booleans (true = dark), or an empty array when no encoder is available.
	 *
	 * @param string $payload Text to encode (UTF-8).
	 * @return array<int, array<int, bool>>
	 */
	public static function matrix( $payload ) {
		$payload = (string) $payload;
		if ( '' === $payload || ! class_exists( self::ENCODER ) || ! class_exists( '\\PassCaptain\\Vendor\\BaconQrCode\\Common\\ErrorCorrectionLevel' ) ) { return array(); }
		try {
			// Ticket payloads are plain ASCII: encoded as ISO-8859-1 (Bacon's default) they need no ECI
			// header, which saves capacity (signed QR: version 11 instead of 12) and avoids handheld
			// scanners that print or reject the ECI designator. Only non-ASCII text uses UTF-8 with ECI.
			$encoding = preg_match( '/[^\x00-\x7F]/', $payload ) ? 'UTF-8' : 'ISO-8859-1';
			$qr     = \PassCaptain\Vendor\BaconQrCode\Encoder\Encoder::encode( $payload, \PassCaptain\Vendor\BaconQrCode\Common\ErrorCorrectionLevel::M(), $encoding );
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
	 * File the prefixed Bacon encoder was loaded from when it is not this plugin's own copy (e.g. a second copy
	 * of the plugin in another folder loaded first), '' otherwise or when it is not loaded at all. Shown on the
	 * system status page. Other plugins' unprefixed BaconQrCode classes are never used (A103).
	 */
	public static function foreign_encoder() {
		if ( ! class_exists( self::ENCODER ) ) { return ''; }
		try {
			$file = (string) ( new \ReflectionClass( self::ENCODER ) )->getFileName();
		} catch ( \ReflectionException $e ) {
			return '';
		}
		$own = str_replace( '\\', '/', WPCAP_PATH . 'vendor-prefixed/' );
		// The vendor directory or the package may be a symlink (deployments, development checkouts) and PHP
		// reports resolved paths: compare the real paths too.
		$norm = str_replace( '\\', '/', $file );
		if ( '' === $file || 0 === strpos( $norm, $own ) ) { return ''; }
		foreach ( array( 'vendor-prefixed', 'vendor-prefixed/bacon/bacon-qr-code' ) as $dir ) {
			$real = realpath( WPCAP_PATH . $dir );
			if ( false !== $real && 0 === strpos( $norm, rtrim( str_replace( '\\', '/', $real ), '/' ) . '/' ) ) { return ''; }
		}
		return $file;
	}
}

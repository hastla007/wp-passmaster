<?php
/** QR rendering through the optional bundled Endroid dependency. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class QrCode {
	public static function data_uri( $payload ) {
		if ( ! class_exists( '\\Endroid\\QrCode\\Builder\\Builder' ) || ! class_exists( '\\Endroid\\QrCode\\Writer\\SvgWriter' ) ) {
			return '';
		}
		try {
			$builder = new \Endroid\QrCode\Builder\Builder(
				writer: new \Endroid\QrCode\Writer\SvgWriter(),
				data: (string) $payload,
				size: 320,
				margin: 12
			);
			$result = $builder->build();
			return 'data:image/svg+xml;base64,' . base64_encode( $result->getString() );
		} catch ( \Throwable $error ) {
			return '';
		}
	}
}

<?php
/**
 * Worker for tests/integration/modules/zz_a103.php, in its own fresh PHP process (no WordPress needed):
 * another plugin's QR libraries are declared BEFORE this plugin's Composer autoloader is loaded, the way a
 * plugin that loads first would. Prints one JSON object.
 *
 *   php child.php <plugin root> foreign    unprefixed Endroid\QrCode\QrCode, BaconQrCode\… and DASPRiD\Enum\…
 *                                          of a different (incompatible) version are already declared
 *   php child.php <plugin root> isolated   the unprefixed packages cannot be loaded at all (as in the
 *                                          release ZIP); every autoload request for them is recorded
 */
// phpcs:ignoreFile
$root = rtrim( (string) ( $argv[1] ?? '' ), '/' ) . '/';
$mode = (string) ( $argv[2] ?? 'foreign' );
define( 'ABSPATH', sys_get_temp_dir() . '/' );
define( 'WPCAP_PATH', $root );

$requested = array();
if ( 'foreign' === $mode ) {
	// "endroid/qr-code 4.x" and an incompatible Bacon/Enum of another plugin: every call throws.
	eval( 'namespace Endroid\\QrCode; class QrCode { const VERSION = "4.x-foreign"; public function __construct( ...$a ) { throw new \\Error( "foreign endroid 4.x" ); } }' );
	eval( 'namespace DASPRiD\\Enum; abstract class AbstractEnum { const FOREIGN = true; public static function __callStatic( $n, $a ) { throw new \\Error( "foreign enum" ); } }' );
	eval( 'namespace BaconQrCode\\Common; final class ErrorCorrectionLevel { const FOREIGN = true; public static function __callStatic( $n, $a ) { throw new \\Error( "foreign bacon" ); } }' );
	eval( 'namespace BaconQrCode\\Encoder; final class Encoder { const FOREIGN = true; public static function encode( ...$a ) { throw new \\Error( "foreign bacon 1.x API" ); } }' );
}

$loader = require $root . 'vendor/autoload.php';
if ( 'isolated' === $mode ) {
	// Development installs also have the unprefixed packages (require-dev); hide them like the ZIP does.
	$loader->setPsr4( 'BaconQrCode\\', array() );
	$loader->setPsr4( 'DASPRiD\\Enum\\', array() );
	spl_autoload_register(
		static function ( $class ) use ( &$requested ) {
			if ( preg_match( '/^(BaconQrCode|DASPRiD|Endroid)\\\\/', $class ) ) { $requested[] = $class; }
		},
		true,
		true
	);
}
require_once $root . 'src/QrCode.php';

$out = array( 'mode' => $mode );
try {
	$matrix = PassCaptain\QrCode::matrix( 'PC1.a103-ticket-payload' );
	$svg    = PassCaptain\QrCode::svg( 'PC1.a103-ticket-payload' );
	$out['error'] = '';
} catch ( Throwable $e ) {
	$matrix = array();
	$svg    = '';
	$out['error'] = get_class( $e ) . ': ' . $e->getMessage();
}
$out['matrix_md5']    = md5( (string) json_encode( $matrix ) );
$out['matrix_size']   = count( $matrix );
$out['svg_ok']        = false !== strpos( $svg, '<svg' ) && false !== strpos( $svg, '<path fill="#000" d="M' );
$out['foreign']       = PassCaptain\QrCode::foreign_encoder();
$encoder_file         = class_exists( PassCaptain\QrCode::ENCODER, false ) ? (string) ( new ReflectionClass( PassCaptain\QrCode::ENCODER ) )->getFileName() : '';
$out['encoder_file']  = str_replace( '\\', '/', $encoder_file );
$out['enum_file']     = class_exists( 'PassCaptain\\Vendor\\DASPRiD\\Enum\\AbstractEnum', false ) ? str_replace( '\\', '/', (string) ( new ReflectionClass( 'PassCaptain\\Vendor\\DASPRiD\\Enum\\AbstractEnum' ) )->getFileName() ) : '';
$out['foreign_intact'] = 'foreign' !== $mode || ( defined( 'Endroid\\QrCode\\QrCode::VERSION' ) && defined( 'BaconQrCode\\Encoder\\Encoder::FOREIGN' ) && defined( 'DASPRiD\\Enum\\AbstractEnum::FOREIGN' ) );
$out['requested']     = array_values( array_unique( $requested ) );
echo json_encode( $out ), "\n";

<?php
/**
 * A685: ticket design – logo, colours (contrast checked), accessible starter templates.
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

use PassCaptain\Printing\Page as PrintPage;
use PassCaptain\Printing\PdfWriter;
use PassCaptain\Printing\Templates;

echo "A685: ticket design (logo, colours, starter templates)\n";
PassCaptain\Modules\PrintTemplates::register();

$z685_dir = sys_get_temp_dir() . '/wpcap-a685-' . getmypid();
if ( ! is_dir( $z685_dir ) ) { mkdir( $z685_dir, 0700, true ); }
$z685_files = array();
if ( function_exists( 'imagecreatetruecolor' ) ) {
	$im = imagecreatetruecolor( 40, 20 );
	imagefill( $im, 0, 0, imagecolorallocate( $im, 31, 58, 104 ) );
	imagejpeg( $im, $z685_dir . '/logo.jpg', 90 );
	imagepng( $im, $z685_dir . '/logo.png' );
	$alpha = imagecreatetruecolor( 30, 30 );
	imagesavealpha( $alpha, true );
	imagefill( $alpha, 0, 0, imagecolorallocatealpha( $alpha, 200, 0, 0, 60 ) );
	imagepng( $alpha, $z685_dir . '/alpha.png' );
	$z685_files = array( 901 => $z685_dir . '/logo.jpg', 902 => $z685_dir . '/logo.png', 903 => $z685_dir . '/alpha.png', 904 => $z685_dir . '/missing.jpg' );
}
file_put_contents( $z685_dir . '/fake.jpg', 'not an image at all' );
$z685_files[905] = $z685_dir . '/fake.jpg';
add_filter( 'wpcap_print_logo_file', static function ( $file, $id ) use ( $z685_files ) { return $z685_files[ $id ] ?? $file; }, 10, 2 );
Templates::reset_logos();

// Colours and contrast.
check( 'A685: WCAG contrast of black and white is 21:1, the dark blue starter accent passes, yellow fails', 21.0 === Templates::contrast( '#000000' ) && Templates::contrast( '#1f3a68' ) >= 4.5 && Templates::contrast( '#ffcc00' ) < 4.5 );
$z685_design = Templates::design( "@logo|901\n@accent|1F3A68\n@text|#zzzzzz\n*12|{event}" );
check( 'A685: design lines are read (malformed colours ignored) and never printed as content', 901 === $z685_design['logo'] && '#1f3a68' === $z685_design['accent'] && '' === $z685_design['text'] && 1 === count( Templates::parse( "@logo|901\n@accent|#1f3a68\n*12|{event}" ) ) );
$z685_composed = Templates::compose( "@accent|#000000\n*12|{event}\n{qr}", array( 'logo' => 901, 'accent' => '#1F3A68', 'text' => '' ) );
list( $z685_content, $z685_split ) = Templates::split( $z685_composed );
check( 'A685: compose/split round trip keeps content and design (old design lines replaced)', "*12|{event}\n{qr}" === $z685_content && 901 === $z685_split['logo'] && '#1f3a68' === $z685_split['accent'] && 1 === substr_count( $z685_composed, '@accent' ) );
$z685_low = Templates::save_template( 0, 'Gelb', 'ticket', "@accent|#ffcc00\n*20|{event}" );
check( 'A685: a template with a low-contrast colour is refused with the contrast in the message', is_wp_error( $z685_low ) && 'wpcap_print_template_design' === $z685_low->get_error_code() && false !== strpos( $z685_low->get_error_message(), '4,5:1' ) );
check( 'A685: tiny text (under 6 pt) is refused', is_wp_error( Templates::save_template( 0, 'Klein', 'badge', "5|{event}" ) ) );
check( 'A685: a logo that is no image is refused', is_wp_error( Templates::save_template( 0, 'Fake', 'ticket', "@logo|905\n*20|{event}" ) ) );

// PDF writer: colours and images.
check( 'A685: hex colours are parsed, invalid ones give null', array( 1.0, 0.0, 0.0 ) === PdfWriter::rgb( '#ff0000' ) && null === PdfWriter::rgb( 'red' ) );
check( 'A685: unreadable and non-image files give no logo', null === PdfWriter::load_image( $z685_dir . '/fake.jpg' ) && null === PdfWriter::load_image( $z685_dir . '/missing.jpg' ) && null === PdfWriter::load_image( '' ) );
$z685_item = array( 'event' => 'Gala', 'date' => '24.12.2026', 'location' => 'Saal', 'ticket_category' => 'VIP', 'attendee_name' => 'Ada', 'seat_label' => '', 'order' => 'abc', 'qr' => str_repeat( 'a', 64 ), 'code' => str_repeat( 'a', 64 ) );
if ( function_exists( 'imagecreatetruecolor' ) ) {
	$jpeg = PdfWriter::load_image( $z685_dir . '/logo.jpg' );
	$png = PdfWriter::load_image( $z685_dir . '/logo.png' );
	$alpha = PdfWriter::load_image( $z685_dir . '/alpha.png' );
	check( 'A685: JPEG logos are embedded as they are, plain PNGs with their compressed data, PNGs with transparency flattened (GD)', $jpeg && 'DCT' === $jpeg['filter'] && 40 === $jpeg['w'] && 20 === $jpeg['h'] && 'RGB' === $jpeg['cs'] && $png && 'Fl' === $png['filter'] && false !== strpos( $png['parms'], '/Columns 40' ) && $alpha && 'DCT' === $alpha['filter'] && 30 === $alpha['w'] );
	foreach ( Templates::KINDS as $z685_kind ) {
		$pdf = Templates::render( $z685_kind, array( $z685_item ), "@logo|901\n@accent|#1f3a68\n@text|#1a1a1a\n*14|{event}\n10|{ticket_category}\n{qr}" );
		$plain = wpcap_test_pdf_plain( $pdf );
		check( "A685: $z685_kind layout prints the logo as an inline image and the colours", 1 === preg_match_all( '#/Type /Page /Parent#', $pdf ) && false !== strpos( $plain, "BI /W 40 /H 20 /CS /RGB /BPC 8 /F [/AHx /DCT]" ) && false !== strpos( $plain, '0.122 0.227 0.408 rg' ) && false !== strpos( $plain, '0.102 0.102 0.102 rg' ) );
	}
	$z685_png_pdf = wpcap_test_pdf_plain( Templates::render( 'ticket', array( $z685_item ), "@logo|902\n*14|{event}" ) );
	check( 'A685: PNG logos carry the PNG predictor parameters', false !== strpos( $z685_png_pdf, '/F [/AHx /Fl] /DP [null << /Predictor 15 /Colors 3' ) );
	$z685_ok = Templates::save_template( 0, 'Mit Logo', 'ticket', "@logo|901\n@accent|#1f3a68\n*20|{event}\n{qr}" );
	check( 'A685: a template with logo and accessible colours is saved', is_int( $z685_ok ) && $z685_ok > 0 );
}
$z685_plain_default = wpcap_test_pdf_plain( Templates::render( 'ticket', array( $z685_item ) ) );
check( 'A685: without design lines nothing changes (no image, no colour operators)', false === strpos( $z685_plain_default, 'BI /W' ) && false === strpos( $z685_plain_default, ' rg ' ) );

// Starters.
$z685_starters = Templates::starters();
$z685_ok_starters = true;
foreach ( $z685_starters as $z685_key => $z685_starter ) {
	$min = 99;
	foreach ( Templates::parse( $z685_starter['body'] ) as $line ) { if ( ! $line['qr'] ) { $min = min( $min, $line['size'] ); } }
	$need = 'ticket' === $z685_starter['kind'] ? 13 : 11;
	if ( ! in_array( $z685_starter['kind'], Templates::KINDS, true ) || Templates::design_problems( $z685_starter['body'] ) || $min < $need || false === strpos( $z685_starter['body'], '{qr}' ) ) { $z685_ok_starters = false; }
	if ( 'ticket' === $z685_starter['kind'] && false === strpos( $z685_starter['body'], '{help_code}' ) ) { $z685_ok_starters = false; }
}
check( 'A685: several accessible starter templates (large type, contrast, QR and help code on tickets)', count( $z685_starters ) >= 4 && 3 <= count( array_filter( $z685_starters, static function ( $s ) { return 'ticket' === $s['kind']; } ) ) && $z685_ok_starters );
$z685_new = Templates::create_from_starter( 'ticket_large', '' );
$z685_row = is_int( $z685_new ) ? Templates::get_template( $z685_new ) : null;
check( 'A685: a template is created from a starter (name defaults to the starter label)', $z685_row && 'ticket' === $z685_row->kind && $z685_starters['ticket_large']['body'] === $z685_row->body && $z685_starters['ticket_large']['label'] === $z685_row->name );
check( 'A685: unknown starters are refused', is_wp_error( Templates::create_from_starter( 'nope', 'X' ) ) );
check( 'A685: the form composes text and design fields into the body', "@logo|7\n@accent|#1f3a68\n*12|{event}" === PrintPage::posted_body( array( 'body' => "*12|{event}", 'logo' => '7', 'accent' => '#1F3A68', 'text_color' => '' ) ) );
check( 'A685: starter handler is registered', isset( PassCaptain\Modules\PrintTemplates::admin_handlers()['wpcap_print_starter'] ) );

foreach ( glob( $z685_dir . '/*' ) as $f ) { unlink( $f ); }
rmdir( $z685_dir );
Templates::reset_logos();

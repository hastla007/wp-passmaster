<?php
/**
 * A690: configurable terms (Teilnehmende → Gäste …) and further theme-overridable templates.
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

use PassCaptain\Settings;
use PassCaptain\Terms;

echo "A690: configurable terms and overridable templates\n";
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = null ) { return esc_attr( $t ); } }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) {} }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) {} }
if ( ! function_exists( 'locate_template' ) ) { function locate_template( $t ) { return ''; } }

$z690_reset = static function ( $preset, $lines = '' ) {
	Settings::update( array( 'attendee_terms' => $preset, 'term_replacements' => $lines ) );
	Settings::flush();
	Terms::flush();
};

// Default: nothing changes.
$z690_reset( '' );
check( 'A690: without a preset texts stay unchanged', 'Name der Teilnehmer:in' === Terms::filter_gettext( 'Name der Teilnehmer:in' ) && array() === Terms::map() );

// Preset "Gäste".
$z690_reset( 'guests' );
$z690_cases = array(
	'Teilnehmerdaten speichern'                                     => 'Gästedaten speichern',
	'Name der Teilnehmer:in'                                         => 'Name des Gastes',
	'Für deine Bestellung fehlen noch Angaben zu den Teilnehmenden:' => 'Für deine Bestellung fehlen noch Angaben zu den Gästen:',
	'Teilnehmer:in 2'                                                => 'Gast 2',
	'E-Mail der Teilnehmer:in (optional, für ein eigenes Ticket)'   => 'E-Mail des Gastes (optional, für ein eigenes Ticket)',
	'Der Teilnehmername dieses Tickets'                             => 'Der Gastname dieses Tickets',
	'Teilnehmer-E-Mail'                                             => 'Gast-E-Mail',
);
$z690_ok = true;
foreach ( $z690_cases as $z690_in => $z690_out ) {
	if ( Terms::filter_gettext( $z690_in ) !== $z690_out ) { $z690_ok = false; echo '    got: ' . Terms::filter_gettext( $z690_in ) . "\n"; }
}
check( 'A690: preset "Gäste" rewrites word forms, phrases with articles and compounds', $z690_ok );
check( 'A690: only whole words, single pass (no double replacement, other words untouched)', 'Teilnehmerschaft' === Terms::apply( 'Teilnehmerschaft' ) && 'Gast' === Terms::apply( 'Teilnehmer' ) );

// Preset "Besucher:innen".
$z690_reset( 'visitors' );
check( 'A690: preset "Besucher:innen"', 'Angaben zu den Besucher:innen' === Terms::apply( 'Angaben zu den Teilnehmenden' ) && 'Besucherdaten' === Terms::apply( 'Teilnehmerdaten' ) );

// Own lines: sanitised, win over the preset, also for other languages.
$z690_clean = Terms::sanitize_lines( "Teilnehmende = Kursteilnehmende\n# Kommentar\nohne Gleichheitszeichen\nattendee = guest\n  <b>x</b> = y  \nsame = same" );
check( 'A690: own lines are normalised (comments, invalid and unchanged lines dropped, tags stripped)', "Teilnehmende = Kursteilnehmende\nattendee = guest\nx = y" === $z690_clean );
$z690_reset( 'guests', $z690_clean );
check( 'A690: own lines win over the preset and work for other languages', 'Kursteilnehmende' === Terms::apply( 'Teilnehmende' ) && 'Gästedaten' === Terms::apply( 'Teilnehmerdaten' ) && 'Add guest' === Terms::apply( 'Add attendee' ) );
$z690_reset( 'custom', 'attendee = guest' );
check( 'A690: "custom" uses only the own lines', 'Teilnehmerdaten' === Terms::apply( 'Teilnehmerdaten' ) && 'guest' === Terms::apply( 'attendee' ) );
check( 'A690: at most 60 own lines', Terms::MAX_LINES === count( Terms::parse( implode( "\n", array_map( static function ( $i ) { return "w$i = v$i"; }, range( 1, 80 ) ) ) ) ) );
$z690_filter = static function ( $map ) { $map['Ticket'] = 'Karte'; return $map; };
add_filter( 'wpcap_terms_map', $z690_filter );
Terms::flush();
check( 'A690: filter wpcap_terms_map adds words', 'Karte' === Terms::apply( 'Ticket' ) );
remove_filter( 'wpcap_terms_map', $z690_filter );

// Scope: admin screens keep the plugin terms, mails get them.
$z690_reset( 'guests' );
check( 'A690: active outside admin screens', Terms::active() );
check( 'A690: mails rendered anywhere use the terms', true === Terms::in_mail( static function () { return Terms::active(); } ) );
check( 'A690: the filter is hooked and registered from Plugin::run(), mails wrap their rendering', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Plugin.php' ), 'Terms::register()' ) && false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Mail/Mailer.php' ), 'Terms::in_mail(' ) );
$z690_def = PassCaptain\Admin\SettingsPage::sections()['terms']['fields'] ?? array();
check( 'A690: settings page offers the presets and own lines', isset( $z690_def['attendee_terms']['options']['guests'], $z690_def['term_replacements'] ) );
$z690_reset( '' );

// Further theme templates.
$z690_theme = sys_get_temp_dir() . '/wpcap-a690-' . getmypid();
@mkdir( $z690_theme . '/pass-captain', 0700, true );
foreach ( array( 'calendar-list.php', 'calendar-month.php', 'passes.php', 'service-booking.php' ) as $z690_name ) {
	file_put_contents( $z690_theme . '/pass-captain/' . $z690_name, '<?php echo "<div class=\"theme-' . $z690_name . '\">" . $default_html . "</div>";' );
}
$GLOBALS['wpcap_test_theme_dirs'] = array( $z690_theme );
$z690_page = PassCaptain\PublicEvents::page( 'passes.php', '<p>Standard</p>', array( 'passes' => array() ) );
check( 'A690: a theme template wraps the default output', '<div class="theme-passes.php"><p>Standard</p></div>' === $z690_page );
$z690_list = PassCaptain\Modules\Calendar::render_shortcode( array( 'view' => 'list' ) );
check( 'A690: calendar list is overridable', false !== strpos( $z690_list, 'theme-calendar-list.php' ) );
$z690_month = PassCaptain\Modules\Calendar::render_shortcode( array( 'view' => 'month' ) );
check( 'A690: calendar month is overridable', false !== strpos( $z690_month, 'theme-calendar-month.php' ) );
check( 'A690: passes and service booking go through the theme override', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Passes/Display.php' ), "page( 'passes.php'" ) && false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Frontend/ServiceBooking.php' ), "page( 'service-booking.php'" ) );
$z690_sb = PassCaptain\Frontend\ServiceBooking::render_block( array( 'eventId' => 0 ) );
check( 'A690: service booking output is overridable', false !== strpos( $z690_sb, 'theme-service-booking.php' ) );
$GLOBALS['wpcap_test_theme_dirs'] = array();
foreach ( glob( $z690_theme . '/pass-captain/*' ) as $f ) { unlink( $f ); }
rmdir( $z690_theme . '/pass-captain' );
rmdir( $z690_theme );

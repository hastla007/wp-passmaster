<?php
// phpcs:ignoreFile
/**
 * A503: shortcode attributes with an unusable value no longer fall back silently to "all events":
 * event="abc" shows no dates, editors see a hint (also for an unknown or unpublished id, an
 * invalid limit/dates/view), visitors see none. The attribute filter runs under the tag used
 * ([wpcap_events] → shortcode_atts_wpcap_events) and, for compatibility, under the long name.
 */
use PassCaptain\Frontend\EventCard;
use PassCaptain\Modules\Calendar;
use PassCaptain\Orders;
use PassCaptain\PublicEvents;

if ( ! defined( 'WPCAP_URL' ) ) { define( 'WPCAP_URL', 'https://example.test/wp-content/plugins/pass-captain/' ); }
if ( ! function_exists( 'locate_template' ) ) { function locate_template( $t ) { return ''; } }
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $t ) { return '<p>' . $t . '</p>'; } }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) {} }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) {} }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = null ) { return esc_attr( $t ); } }
if ( ! function_exists( 'wp_timezone_string' ) ) { function wp_timezone_string() { return 'Europe/Vienna'; } }

echo "A503: invalid shortcode attributes\n";
global $wpdb;
$p = $wpdb->prefix;
$a503_type = make_type( 10 );
$a503_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a503_type" );
$a503_event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$a503_occ" );
Orders::flush_availability_cache();
$a503_events = static function ( $atts, $tag = 'wpcap_events' ) { EventCard::reset_form_ids(); return PublicEvents::render_shortcode( $atts, '', $tag ); };

$a503_valid = $a503_events( array( 'event' => (string) $a503_event ) );
check( 'A503 a valid event attribute lists the event, without hint', false !== strpos( $a503_valid, 'data-occurrence="' . $a503_occ . '"' ) && false === strpos( $a503_valid, 'wpcap-shortcode-notice' ) );
$a503_bad = $a503_events( array( 'event' => 'sommerfest' ) );
check( 'A503 event="sommerfest" lists nothing instead of every event', false === strpos( $a503_bad, '<form class="wpcap-checkout-form"' ) && false !== strpos( $a503_bad, 'wpcap-events-empty' ) );
check( 'A503 editors see a hint naming shortcode, attribute and value', false !== strpos( $a503_bad, '<p class="wpcap-shortcode-notice" role="note">Hinweis für Redakteur:innen zum Shortcode [wpcap_events]: event=&quot;sommerfest&quot; ist ungültig' ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'edit_posts' );
$a503_visitor = $a503_events( array( 'event' => 'sommerfest' ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'A503 visitors see no hint', false === strpos( $a503_visitor, 'wpcap-shortcode-notice' ) && false !== strpos( $a503_visitor, 'wpcap-events-empty' ) );
$a503_unknown = $a503_events( array( 'event' => '99999999' ), 'wp_pass_captain_events' );
check( 'A503 an unknown event id: hint for editors with the long shortcode name', false !== strpos( $a503_unknown, 'zum Shortcode [wp_pass_captain_events]: event=&quot;99999999&quot;' ) );
$a503_limit = $a503_events( array( 'event' => (string) $a503_event, 'limit' => 'viele', 'dates' => 'alle' ) );
check( 'A503 invalid limit and dates: defaults with a hint, the event is still listed', false !== strpos( $a503_limit, 'data-occurrence="' . $a503_occ . '"' ) && false !== strpos( $a503_limit, 'limit=&quot;viele&quot;' ) && false !== strpos( $a503_limit, 'dates=&quot;alle&quot;' ) );
check( 'A503 render_shortcode() without a tag (direct calls) keeps working', false !== strpos( PublicEvents::render_shortcode( array( 'occurrence' => $a503_occ ) ), 'data-occurrence="' . $a503_occ . '"' ) );

$a503_cal_bad = Calendar::render_shortcode( array( 'view' => 'list', 'event' => 'x1' ), '', 'wpcap_calendar' );
check( 'A503 calendar with event="x1": no dates of other events, hint for editors', false === strpos( $a503_cal_bad, 'wpcap-cal-link' ) && false !== strpos( $a503_cal_bad, 'zum Shortcode [wpcap_calendar]: event=&quot;x1&quot;' ) );
$a503_cal_view = Calendar::render_shortcode( array( 'view' => 'grid', 'event' => (string) $a503_event ), '', 'wpcap_calendar' );
check( 'A503 calendar with view="grid": month view with a hint', false !== strpos( $a503_cal_view, 'wpcap-cal-grid' ) && false !== strpos( $a503_cal_view, 'view=&quot;grid&quot;' ) );
$wpdb->update( "{$p}wpcap_events", array( 'status' => 'draft' ), array( 'event_id' => $a503_event ) );
check( 'A503 calendar of an unpublished event: hint for editors', false !== strpos( Calendar::render_shortcode( array( 'view' => 'list', 'event' => (string) $a503_event ), '', 'wpcap_calendar' ), 'event=&quot;' . $a503_event . '&quot;' ) );
$wpdb->update( "{$p}wpcap_events", array( 'status' => 'published' ), array( 'event_id' => $a503_event ) );

$a503_raw = (string) shell_exec( 'WPCAP_TEST_PREFIX=' . escapeshellarg( $wpdb->prefix ) . ' ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/zz_a503/child.php' ) . ' 2>&1' );
$a503_tags = json_decode( trim( $a503_raw ), true );
check( 'A503 child process ran' . ( is_array( $a503_tags ) ? '' : ' – ' . substr( $a503_raw, 0, 300 ) ), is_array( $a503_tags ) );
check( 'A503 [wpcap_events] runs shortcode_atts_wpcap_events and, for compatibility, the long name', array( 'wpcap_events', 'wp_pass_captain_events' ) === ( $a503_tags['short'] ?? null ) );
check( 'A503 the long name runs its filter once; no tag means the long name', array( 'wp_pass_captain_events' ) === ( $a503_tags['long'] ?? null ) && array( 'wp_pass_captain_calendar' ) === ( $a503_tags['none'] ?? null ) && array( 'wpcap_calendar', 'wp_pass_captain_calendar' ) === ( $a503_tags['calendar'] ?? null ) );
$wpdb->update( "{$p}wpcap_occurrences", array( 'status' => 'a503_done' ), array( 'occurrence_id' => $a503_occ ) );

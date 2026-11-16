<?php
/**
 * A452: month navigation of the calendar block/shortcode is built from the current request URL
 * (widgets, template parts and archives have no matching global post), and every month calendar
 * of a page pages with its own parameter (wpcap_month, wpcap_month_2, …) while keeping the
 * others. Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Modules\Calendar;

if ( ! defined( 'WPCAP_URL' ) ) { define( 'WPCAP_URL', 'https://example.test/wp-content/plugins/pass-captain/' ); }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = null ) { return esc_attr( $t ); } }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) {} }

/** href values of the prev/next links of a rendered month calendar. */
function a452_links( $html ) {
	preg_match( '/<a href="([^"]*)" rel="prev">/', $html, $prev );
	preg_match( '/<a href="([^"]*)" rel="next">/', $html, $next );
	return array( html_entity_decode( $prev[1] ?? '', ENT_QUOTES ), html_entity_decode( $next[1] ?? '', ENT_QUOTES ) );
}
function a452_reset() {
	$r = new ReflectionProperty( Calendar::class, 'month_instances' );
	$r->setValue( null, 0 );
}

echo "A452: calendar month navigation\n";
$a452_saved_uri = $_SERVER['REQUEST_URI'] ?? null;
$a452_saved_get = $_GET;
$a452_tz = new DateTimeZone( function_exists( 'wp_timezone_string' ) ? ( wp_timezone_string() ?: 'UTC' ) : 'UTC' );
$a452_now = new DateTime( 'now', $a452_tz );
$a452_this = $a452_now->format( 'Y-m' );
$a452_next = ( new DateTime( $a452_this . '-01', $a452_tz ) )->modify( '+1 month' )->format( 'Y-m' );
$a452_later = ( new DateTime( $a452_this . '-01', $a452_tz ) )->modify( '+5 month' );

// A page (or widget area) with two month calendars; the second one was paged to $a452_later.
$_SERVER['REQUEST_URI'] = '/veranstaltungen/sommer/?ref=nl&wpcap_month_2=' . $a452_later->format( 'Y-m' );
$_GET = array( 'ref' => 'nl', 'wpcap_month_2' => $a452_later->format( 'Y-m' ) );
a452_reset();
$a452_first = Calendar::render_shortcode( array( 'view' => 'month' ) );
$a452_list = Calendar::render_shortcode( array( 'view' => 'list' ) );
$a452_second = Calendar::render_shortcode( array( 'view' => 'month' ) );
list( , $a452_first_next ) = a452_links( $a452_first );
list( $a452_second_prev ) = a452_links( $a452_second );
check( 'base is the request URL, not the permalink of the global post', 0 === strpos( $a452_first_next, '/veranstaltungen/sommer/?' ) );
check( 'first calendar: own parameter wpcap_month, keeps the other query parameters and the second calendar\'s month', '/veranstaltungen/sommer/?ref=nl&wpcap_month_2=' . $a452_later->format( 'Y-m' ) . '&wpcap_month=' . $a452_next . '#wpcap-cal-nav-1' === $a452_first_next );
check( 'second calendar: parameter wpcap_month_2 replaced, not duplicated', '/veranstaltungen/sommer/?ref=nl&wpcap_month_2=' . ( clone $a452_later )->modify( '-1 month' )->format( 'Y-m' ) . '#wpcap-cal-nav-2' === $a452_second_prev );
check( 'list views do not take a month parameter', false === strpos( $a452_list, 'wpcap_month' ) );
check( 'each calendar shows its own month', 1 === preg_match( '/<caption>([^<]+)<\/caption>/', $a452_first, $a452_c1 ) && 1 === preg_match( '/<caption>([^<]+)<\/caption>/', $a452_second, $a452_c2 ) && $a452_c1[1] !== $a452_c2[1] && str_ends_with( $a452_c2[1], ' ' . $a452_later->format( 'Y' ) ) );
check( 'navigation anchors are unique per calendar', false !== strpos( $a452_first, 'id="wpcap-cal-nav-1"' ) && false !== strpos( $a452_second, 'id="wpcap-cal-nav-2"' ) );

// The first calendar paged: the second keeps reading its own parameter.
$_GET = array( 'wpcap_month' => $a452_next );
a452_reset();
$a452_a = Calendar::render_shortcode( array( 'view' => 'month' ) );
$a452_b = Calendar::render_shortcode( array( 'view' => 'month' ) );
check( 'paging the first calendar leaves the second on the current month', 1 === preg_match( '/<caption>([^<]+)<\/caption>/', $a452_a, $a452_ca ) && 1 === preg_match( '/<caption>([^<]+)<\/caption>/', $a452_b, $a452_cb ) && $a452_ca[1] !== $a452_cb[1] );

// Hostile request paths: never a protocol-relative link to another host.
$_GET = array();
$_SERVER['REQUEST_URI'] = '//evil.example/x?y=1';
a452_reset();
list( , $a452_p ) = a452_links( Calendar::render_shortcode( array( 'view' => 'month' ) ) );
check( 'protocol-relative request path is collapsed to a local path', 0 === strpos( $a452_p, '/evil.example/x?y=1&wpcap_month=' ) );
$_SERVER['REQUEST_URI'] = '/\\evil.example/';
check( 'backslash variant collapsed too', 0 === strpos( Calendar::nav_url( 'wpcap_month', '2030-01' ), '/evil.example/?wpcap_month=2030-01' ) );
check( 'fragment of the request is dropped, value encoded', '/a/?b=1&wpcap_month=2030-01#wpcap-cal-nav-1' === ( function () { $_SERVER['REQUEST_URI'] = '/a/?b=1#x'; return Calendar::nav_url( 'wpcap_month', '2030-01', 'wpcap-cal-nav-1' ); } )() );
unset( $_SERVER['REQUEST_URI'] );
check( 'without a request URL (CLI): permalink or home URL as base', '' !== Calendar::nav_url( 'wpcap_month', '2030-01' ) && false !== strpos( Calendar::nav_url( 'wpcap_month', '2030-01' ), 'wpcap_month=2030-01' ) );
check( 'parameter names: wpcap_month, wpcap_month_2, wpcap_month_3', 'wpcap_month' === Calendar::month_param( 1 ) && 'wpcap_month_2' === Calendar::month_param( 2 ) && 'wpcap_month_3' === Calendar::month_param( 3 ) );

if ( null === $a452_saved_uri ) { unset( $_SERVER['REQUEST_URI'] ); } else { $_SERVER['REQUEST_URI'] = $a452_saved_uri; }
$_GET = $a452_saved_get;
a452_reset();

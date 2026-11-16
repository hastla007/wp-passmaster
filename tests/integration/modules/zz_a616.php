<?php
/**
 * A616: validity of discount codes created through the REST API (and the admin form) is validated
 * and converted to UTC: local time in the site time zone, ISO 8601 with offset, a date alone as start
 * resp. end of that day; garbage and an end before the start are refused instead of "unlimited".
 */
// phpcs:ignoreFile
use PassCaptain\Campaigns;
use PassCaptain\Rest\Controller;

if ( ! function_exists( 'wp_timezone_string' ) ) { function wp_timezone_string() { return 'Europe/Vienna'; } }
global $wpdb;
$p = $wpdb->prefix;
echo "A616: campaign validity window\n";

check( 'A616: a date alone ends at the end of that local day (Vienna, winter: 22:59:59 UTC)', '2026-12-24 22:59:59' === Campaigns::window_bound( '2026-12-24', true, 'Europe/Vienna' ) );
check( 'A616: a date alone starts at the beginning of that local day', '2026-12-23 23:00:00' === Campaigns::window_bound( '2026-12-24', false, 'Europe/Vienna' ) );
check( 'A616: local date and time are converted with DST (summer: UTC+2)', '2026-07-01 16:00:00' === Campaigns::window_bound( '2026-07-01T18:00', false, 'Europe/Vienna' ) );
check( 'A616: ISO 8601 with offset or Z is converted to UTC', '2026-12-24 17:00:00' === Campaigns::window_bound( '2026-12-24T18:00:00+01:00', true, 'Europe/Vienna' ) && '2026-12-24 18:00:00' === Campaigns::window_bound( '2026-12-24T18:00:00Z', true, 'Europe/Vienna' ) && '2026-12-24 17:00:00' === Campaigns::window_bound( '2026-12-24T18:00+0100', true, 'UTC' ) );
check( 'A616: a fixed-offset site time zone works too', '2026-12-24 17:00:00' === Campaigns::window_bound( '2026-12-24 18:00', false, '+01:00' ) );
check( 'A616: empty is open', null === Campaigns::window_bound( '', true, 'Europe/Vienna' ) );
$x1_bad = array( 'tomorrow', '2026-02-30', '24.12.2026', '2026-12-24 25:00', array( '2026-12-24' ) );
check( 'A616: garbage, impossible dates and other formats are refused', ! array_filter( $x1_bad, static fn( $v ) => ! is_wp_error( Campaigns::window_bound( $v, true, 'Europe/Vienna' ) ) ) );

$x1_type = make_type( 10, 10, 2000 );
$x1_event = (int) $wpdb->get_var( $wpdb->prepare( "SELECT o.event_id FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=%d", $x1_type ) );
$x1_rest = static function ( array $body ) use ( $x1_event ) {
	$req = new WP_REST_Request( 'POST', '/pass-captain/v1/events/' . $x1_event . '/campaigns' );
	$req['event_id'] = $x1_event;
	$req->set_body( json_encode( $body ) );
	$res = Controller::create_campaign( $req );
	return $res instanceof WP_REST_Response ? $res->get_data() : $res;
};
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
$x1_today = wp_date( 'Y-m-d', time(), new DateTimeZone( wp_timezone_string() ) );
$x1_c = $x1_rest( array( 'code' => 'X1A616DAY', 'kind' => 'percent', 'value' => 1000, 'currency' => 'EUR', 'ends_at' => $x1_today ) );
$x1_row = is_array( $x1_c ) ? Campaigns::get( $x1_c['campaign_id'] ) : null;
check( 'A616: REST code with ends_at = today is stored as the end of today (site time zone)', $x1_row && Campaigns::window_bound( $x1_today, true, wp_timezone_string() ) === $x1_row->ends_at );
$x1_order = PassCaptain\Orders::create( array( 'email' => 'x1-a616@example.com', 'items' => array( array( 'ticket_type_id' => $x1_type, 'quantity' => 1 ) ), 'campaign_codes' => array( 'X1A616DAY' ), 'idempotency_key' => 'x1-a616-' . uniqid() ) );
check( 'A616: the code is still valid today (was: expired at midnight UTC)', ! is_wp_error( $x1_order ) && (int) $x1_order['discount_minor'] > 0 );
$x1_garbage = $x1_rest( array( 'code' => 'X1A616BAD', 'kind' => 'percent', 'value' => 1000, 'currency' => 'EUR', 'ends_at' => 'next week' ) );
$x1_reverse = $x1_rest( array( 'code' => 'X1A616REV', 'kind' => 'percent', 'value' => 1000, 'currency' => 'EUR', 'starts_at' => '2026-12-24', 'ends_at' => '2026-12-01' ) );
check( 'A616: an unreadable date or an end before the start is refused (400), nothing stored', is_wp_error( $x1_garbage ) && 'wpcap_campaign_dates' === $x1_garbage->get_error_code() && 400 === $x1_garbage->get_error_data()['status'] && is_wp_error( $x1_reverse ) && 'wpcap_campaign_dates' === $x1_reverse->get_error_code() && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_campaigns WHERE code IN ('X1A616BAD','X1A616REV')" ) );
$x1_admin = PassCaptain\Admin\CampaignsPage::create( array( 'code' => 'X1A616ADM', 'kind' => 'percent', 'value' => '10', 'currency' => 'EUR', 'event_id' => $x1_event, 'ends_at' => '2026-13-45T10:00' ) );
check( 'A616: the admin form refuses an unreadable date instead of creating an unlimited code', is_wp_error( $x1_admin ) && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_campaigns WHERE code='X1A616ADM'" ) );
check( 'A616: Campaigns::create() accepts only UTC "Y-m-d H:i:s"', is_wp_error( Campaigns::create( array( 'code' => 'X1A616RAW', 'kind' => 'percent', 'value' => 1000, 'currency' => 'EUR', 'ends_at' => '2026-12-24' ) ) ) );

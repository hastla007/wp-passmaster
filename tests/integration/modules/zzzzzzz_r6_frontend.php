<?php
/**
 * Audit round 2, package R6 (experts #9, #7, #12): event list/detail view with shortcode/block
 * attributes, calendar links to the single-occurrence view, waitlist in the frontend, live
 * availability endpoint and purge hook, ticket types before their sales start, REST nonce only for
 * signed-in buyers, attendee name option and new participant field types (server-side), ticket type
 * description/position, "kostenlos", quantity stepper, return to the ordering page with "pay again"
 * and "release reservation", Retry-After / remaining tickets in checkout errors, buyer name.
 * Included from run.php after all other module tests (uses check(), make_type()).
 */
// phpcs:ignoreFile
use PassCaptain\Admin\EventsPage;
use PassCaptain\Calendar\Ics;
use PassCaptain\Frontend\Availability;
use PassCaptain\Modules\Calendar;
use PassCaptain\Orders;
use PassCaptain\Payments\ReturnNotice;
use PassCaptain\PublicEvents;
use PassCaptain\Rest\Controller;
use PassCaptain\Settings;

if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . ltrim( $p, '/' ); } }
if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $action = -1 ) { return 'n'; } }
if ( ! function_exists( 'locate_template' ) ) { function locate_template( $t ) { return ''; } }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) {} }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) {} }
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $t ) { return '<p>' . $t . '</p>'; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); } }
if ( ! function_exists( 'delete_transient' ) ) { function delete_transient( $k ) { unset( $GLOBALS['wpcap_test_transients'][ $k ] ); return true; } }
if ( ! function_exists( 'get_permalink' ) ) { function get_permalink( $id = 0 ) { return $GLOBALS['r6_permalinks'][ (int) $id ] ?? ''; } }
if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args = array() ) {
		$needle = (string) ( $args['s'] ?? '' );
		return array_values( array_filter( $GLOBALS['r6_posts'] ?? array(), static function ( $post ) use ( $needle, $args ) { return in_array( $post->post_type, (array) $args['post_type'], true ) && ( '' === $needle || false !== strpos( $post->post_content, $needle ) ); } ) );
	}
}
if ( ! function_exists( 'wp_validate_redirect' ) ) {
	function wp_validate_redirect( $location, $default = '' ) { return 'example.test' === parse_url( (string) $location, PHP_URL_HOST ) ? (string) $location : $default; }
}
if ( ! function_exists( 'remove_query_arg' ) ) {
	function remove_query_arg( $keys, $url = false ) {
		$parts = explode( '?', (string) $url, 2 );
		if ( ! isset( $parts[1] ) ) { return $parts[0]; }
		parse_str( $parts[1], $q );
		foreach ( (array) $keys as $k ) { unset( $q[ $k ] ); }
		return $parts[0] . ( $q ? '?' . http_build_query( $q ) : '' );
	}
}

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 1;
wpcap_test_reset_transients();
Availability::register(); // Plugin::boot() is not run by the suite.

/** Event with $n upcoming occurrences, one ticket type each (with a participant field); returns [event_id, occurrence ids, type ids]. */
function r6_series( $n, array $type = array() ) {
	global $wpdb;
	$p = $wpdb->prefix;
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'Serie R6 ' . uniqid(), 'slug' => 'r6-' . uniqid(), 'description' => 'Eine Führung durch das Haus.', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now ) );
	$event = (int) $wpdb->insert_id;
	$occs = array();
	$types = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + ( 3 + $i ) * DAY_IN_SECONDS ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => 0, 'status' => 'scheduled' ) );
		$occ = (int) $wpdb->insert_id;
		$wpdb->insert( "{$p}wpcap_ticket_types", array_merge( array( 'occurrence_id' => $occ, 'name' => 'Standard', 'price_minor' => 1500, 'currency' => 'EUR', 'capacity' => 20, 'max_per_order' => 10, 'status' => 'active' ), $type ) );
		$tid = (int) $wpdb->insert_id;
		$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $tid, 'field_key' => 'diet', 'label' => 'Ernährung', 'field_type' => 'text', 'required' => 0, 'status' => 'active' ) );
		$occs[] = $occ;
		$types[] = $tid;
	}
	return array( $event, $occs, $types );
}
function r6_req( array $body ) {
	$r = new WP_REST_Request( 'POST' );
	$r->set_body( wp_json_encode( $body ) );
	return $r;
}
function r6_data( $res ) { return $res instanceof WP_REST_Response ? $res->get_data() : $res; }
function r6_occ_of( $type_id ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT occurrence_id FROM %i WHERE ticket_type_id=%d', $wpdb->prefix . 'wpcap_ticket_types', $type_id ) );
}
function r6_event_of( $type_id ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT o.event_id FROM %i t INNER JOIN %i o ON o.occurrence_id=t.occurrence_id WHERE t.ticket_type_id=%d', $wpdb->prefix . 'wpcap_ticket_types', $wpdb->prefix . 'wpcap_occurrences', $type_id ) );
}

echo "R6: schema\n";
check( 'R6: DB_VERSION is at least 3.17.5', version_compare( PassCaptain\Installer::DB_VERSION, '3.17.5', '>=' ) );
$r6_cols = static function ( $table ) use ( $wpdb, $p ) { return $wpdb->get_col( 'SHOW COLUMNS FROM `' . $p . $table . '`' ); };
check( 'R6: ticket types have description, position and attendee_name', ! array_diff( array( 'description', 'position', 'attendee_name' ), $r6_cols( 'wpcap_ticket_types' ) ) );
check( 'R6: participant fields have options; orders have customer_name', in_array( 'options', $r6_cols( 'wpcap_participant_fields' ), true ) && in_array( 'customer_name', $r6_cols( 'wpcap_orders' ), true ) );

echo "R6: list and single-occurrence view (#9)\n";
list( $s_event, $s_occs, $s_types ) = r6_series( 60 );
$list = PublicEvents::render_shortcode( array( 'event' => $s_event ) );
check( 'R6-1: 60 dates of one event render without any checkout form (layout auto → list)', 0 === substr_count( $list, 'wpcap-checkout-form' ) && false !== strpos( $list, 'wpcap-events-list' ) );
check( 'R6-1: series with many dates get a date picker with every date (GET wpcap_occ, works without JS)', 60 === substr_count( $list, '<option value=' ) && false !== strpos( $list, 'name="wpcap_occ"' ) && false !== strpos( $list, '60 Termine ab' ) );
check( 'R6-1: the list stays small (< 20 KB, < 5 inputs) instead of 60 forms', strlen( $list ) < 20000 && substr_count( $list, '<input' ) + substr_count( $list, '<select' ) < 5 );
$cards = PublicEvents::render_shortcode( array( 'event' => $s_event, 'layout' => 'cards', 'limit' => 5 ) );
check( 'R6-1: layout=cards with limit renders exactly that many forms', 5 === substr_count( $cards, '<form class="wpcap-checkout-form"' ) );
$last = end( $s_occs );
$_GET['wpcap_occ'] = $last;
$detail = PublicEvents::render_shortcode( array() );
unset( $_GET['wpcap_occ'] );
check( 'R6-1: ?wpcap_occ shows only that occurrence (also beyond 50) with its form, a back link and the date picker', 1 === substr_count( $detail, '<form class="wpcap-checkout-form"' ) && false !== strpos( $detail, 'id="wpcap-' . $last . '"' ) && false !== strpos( $detail, 'wpcap-back' ) && false !== strpos( $detail, 'Anderen Termin wählen' ) );
$attr = PublicEvents::render_shortcode( array( 'occurrence' => $s_occs[1] ) );
check( 'R6-1: attribute occurrence renders that date directly (no back link)', 1 === substr_count( $attr, '<form class="wpcap-checkout-form"' ) && false !== strpos( $attr, 'id="wpcap-' . $s_occs[1] . '"' ) && false === strpos( $attr, 'wpcap-back' ) );
list( $two_event, $two_occs ) = r6_series( 2 );
$two = PublicEvents::render_list( PublicEvents::listing_rows( $two_event ) );
check( 'R6-1: few dates are compact rows with a "Tickets" link to the single view', 2 === substr_count( $two, 'class="wpcap-occ-row"' ) && false !== strpos( $two, 'wpcap_occ=' . $two_occs[0] ) && 0 === substr_count( $two, '<form' ) );
$_GET['wpcap_occ'] = 99999999;
$gone = PublicEvents::render_shortcode( array() );
unset( $_GET['wpcap_occ'] );
check( 'R6-1: unknown/past occurrence shows a notice instead of an empty page', false !== strpos( $gone, 'nicht (mehr) buchbar' ) );
check( 'R6-1: attributes are normalised (limit 1–200, layout list|cards|auto)', array( 'event' => 3, 'occurrence' => 0, 'limit' => 200, 'layout' => 'auto' ) === PublicEvents::atts( array( 'event' => '3', 'limit' => '5000', 'layout' => 'grid' ) ) );
$block = json_decode( (string) file_get_contents( WPCAP_PATH . 'src/blocks/events/block.json' ), true );
check( 'R6-1: block.json declares eventId, occurrenceId, limit and layout', ! array_diff( array( 'eventId', 'occurrenceId', 'limit', 'layout' ), array_keys( (array) ( $block['attributes'] ?? array() ) ) ) && false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/blocks/events/render.php' ), "'layout'" ) );

echo "R6: calendar links (#9)\n";
Settings::update( array( 'calendar' => array( 'checkout_url' => '', 'attach_ics' => 1 ) ) );
delete_transient( 'wpcap_events_page' );
$GLOBALS['r6_posts'] = array(
	(object) array( 'ID' => 11, 'post_type' => 'page', 'post_content' => 'Kalender [wp_pass_captain_calendar]' ),
	(object) array( 'ID' => 12, 'post_type' => 'page', 'post_content' => 'Nur ein Termin [wp_pass_captain_events occurrence="5"]' ),
	(object) array( 'ID' => 13, 'post_type' => 'post', 'post_content' => '[wp_pass_captain_events]' ),
	(object) array( 'ID' => 14, 'post_type' => 'page', 'post_content' => '<!-- wp:wp-pass-captain/events /-->' ),
);
$GLOBALS['r6_permalinks'][13] = 'https://example.test/beitrag/';
$GLOBALS['r6_permalinks'][14] = 'https://example.test/tickets-auto/';
$auto = Ics::checkout_url( $last );
check( 'R6-2: without checkout_url the list page with the events shortcode/block is found (pages first, not a fixed-date page) and linked to the single view', 'https://example.test/tickets-auto/?wpcap_occ=' . $last . '#wpcap-occ-' . $last === $auto );
Settings::update( array( 'calendar' => array( 'checkout_url' => 'https://example.test/kasse/', 'attach_ics' => 1 ) ) );
check( 'R6-2: a configured checkout page wins', 0 === strpos( Ics::checkout_url( $last ), 'https://example.test/kasse/?wpcap_occ=' . $last ) );
Settings::update( array( 'calendar' => array( 'checkout_url' => '', 'attach_ics' => 1 ) ) );
$GLOBALS['r6_posts'] = array();
delete_transient( 'wpcap_events_page' );
$cal_list = Calendar::render_list( $s_event, 60 );
check( 'R6-2: no events page at all: calendar links open the date on the calendar page itself', false !== strpos( $cal_list, '?wpcap_occ=' . $last . '#wpcap-occ-' . $last ) && false === strpos( $cal_list, 'href="#wpcap-occ-' ) );
$_GET['wpcap_occ'] = $last;
$cal_page = Calendar::render_shortcode( array( 'view' => 'list', 'event' => $s_event ) );
unset( $_GET['wpcap_occ'] );
check( 'R6-2: …and the calendar shortcode shows that date with its checkout form above the calendar', 1 === substr_count( $cal_page, '<form class="wpcap-checkout-form"' ) && strpos( $cal_page, 'wpcap-checkout-form' ) < strpos( $cal_page, 'wpcap-calendar' ) );
delete_transient( 'wpcap_events_page' );

echo "R6: waitlist in the frontend (#9)\n";
$w_type = make_type( 1, 10, 1200 );
$w_occ = r6_occ_of( $w_type );
$w_event = r6_event_of( $w_type );
Orders::create( array( 'email' => 'r6-sold@example.com', 'items' => array( array( 'ticket_type_id' => $w_type, 'quantity' => 1 ) ), 'idempotency_key' => 'r6-sold-' . uniqid() ), array( 'skip_rate_limit' => true, 'hold_minutes' => 600 ) );
$w_card = PublicEvents::render_shortcode( array( 'occurrence' => $w_occ ) );
check( 'R6-3: sold-out type renders the waitlist container and the form knows event/occurrence', false !== strpos( $w_card, '<div class="wpcap-waitlist" data-type="' . $w_type . '"' ) && false !== strpos( $w_card, 'data-event="' . $w_event . '"' ) && false !== strpos( $w_card, 'data-occurrence="' . $w_occ . '"' ) && false !== strpos( $w_card, 'data-waitlist="1"' ) );
check( 'R6-3: sold-out type keeps a disabled quantity field (live updates can enable it)', 1 === preg_match( '/<input id="wpcap-' . $w_occ . '-' . $w_type . '"[^>]* disabled/', $w_card ) && false !== strpos( $w_card, 'class="wpcap-soldout"' ) );
$GLOBALS['wpcap_test_filters']['wpcap_waitlist_frontend'] = false;
$w_off = PublicEvents::render_shortcode( array( 'occurrence' => $w_occ ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_waitlist_frontend'] );
check( 'R6-3: filter wpcap_waitlist_frontend switches it off', false === strpos( $w_off, 'class="wpcap-waitlist"' ) && false !== strpos( $w_off, 'data-waitlist="0"' ) );
$jr = new WP_REST_Request( 'POST' );
$jr->set_param( 'event_id', $w_event );
$jr->set_param( 'occurrence_id', $w_occ );
$jr->set_param( 'ticket_type_id', $w_type );
$jr->set_param( 'email', 'r6-wait@example.com' );
$join = r6_data( Controller::join_waitlist( $jr ) );
check( 'R6-3: the existing waitlist route answers with the double opt-in flag for the "confirm the link" hint', is_array( $join ) && 'received' === ( $join['status'] ?? '' ) && true === ( $join['double_opt_in'] ?? null ) );
$config = PublicEvents::checkout_config();
check( 'R6-3: checkout config carries the waitlist route and the confirmation hint', false !== strpos( (string) $config['waitlistUrl'], 'events/0/waitlist' ) && false !== strpos( (string) $config['i18n']['waitlistConfirm'], 'bestätige den Link' ) );

echo "R6: live availability (#7)\n";
$a_type = make_type( 5, 4, 1000 );
$a_upcoming = make_type( 5, 4, 1000 );
$wpdb->update( "{$p}wpcap_ticket_types", array( 'sales_start' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ), array( 'ticket_type_id' => $a_upcoming ) );
$a_paused = make_type( 5, 4, 1000 );
$wpdb->update( "{$p}wpcap_ticket_types", array( 'status' => 'paused' ), array( 'ticket_type_id' => $a_paused ) );
$snap = Availability::snapshot( array( $a_type, $a_upcoming, $a_paused, $w_type ) );
check( 'R6-4: snapshot: available with max and remaining', 'available' === $snap[ $a_type ]['status'] && 4 === $snap[ $a_type ]['max'] && 5 === $snap[ $a_type ]['remaining'] );
check( 'R6-4: snapshot: sold out, upcoming (with ISO sales start) and closed', 'soldout' === $snap[ $w_type ]['status'] && 'upcoming' === $snap[ $a_upcoming ]['status'] && 1 === preg_match( '/^\d{4}-\d\d-\d\dT/', (string) $snap[ $a_upcoming ]['sales_start'] ) && 'closed' === $snap[ $a_paused ]['status'] );
wpcap_test_reset_transients();
$_SERVER['REMOTE_ADDR'] = '198.51.100.' . random_int( 2, 250 );
$ar = new WP_REST_Request( 'GET' );
$ar->set_param( 'types', $a_type . ',' . $w_type . ',abc,' . $a_type );
$a_res = Availability::rest( $ar );
$a_data = r6_data( $a_res );
check( 'R6-4: GET /availability answers per requested type (deduplicated, invalid ids dropped)', $a_res instanceof WP_REST_Response && 200 === $a_res->get_status() && 2 === count( (array) $a_data['types'] ) && 'soldout' === ( (array) $a_data['types'] )[ (string) $w_type ]['status'] );
check( 'R6-4: cacheable for 5 s with stale-while-revalidate (filterable)', false !== strpos( Availability::cache_control(), 'max-age=5' ) && false !== strpos( Availability::cache_control(), 'stale-while-revalidate' ) );
$GLOBALS['wpcap_test_filters']['wpcap_availability_rate_limit'] = 2;
Availability::rest( $ar );
$limited = Availability::rest( $ar );
unset( $GLOBALS['wpcap_test_filters']['wpcap_availability_rate_limit'] );
check( 'R6-4: rate limited per client network (429)', $limited instanceof WP_REST_Response && 429 === $limited->get_status() );
check( 'R6-4: availability route is registered as public GET', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Rest/Controller.php' ), "'/availability'" ) );
check( 'R6-4: the poll interval is limited to 15–60 s', 20 === PublicEvents::checkout_config()['poll'] && false !== strpos( (string) PublicEvents::checkout_config()['availabilityUrl'], 'wp-pass-captain/v1/availability' ) );
$a_card = PublicEvents::render_shortcode( array( 'occurrence' => r6_occ_of( $a_upcoming ) ) );
check( 'R6-4: a ticket type before its sales start is rendered (countdown data, disabled) instead of being left out', 1 === substr_count( $a_card, '<form class="wpcap-checkout-form"' ) && false !== strpos( $a_card, 'class="wpcap-upcoming" data-sales-start="' ) && 1 === preg_match( '/data-type="' . $a_upcoming . '"[^>]*data-sales-start="/', $a_card ) );
$r6_changed = array();
add_action( 'wp_pass_captain_availability_changed_v1', static function ( $ids, $reason ) use ( &$r6_changed ) { $r6_changed[] = array( $ids, $reason ); }, 10, 2 );
$c_res = Controller::checkout( r6_req( array( 'email' => 'r6-live@example.com', 'items' => array( array( 'ticket_type_id' => $a_type, 'quantity' => 1 ) ), 'idempotency_key' => 'r6-live-' . uniqid() ) ) );
check( 'R6-4: a new reservation fires wp_pass_captain_availability_changed_v1 (reserved)', ! is_wp_error( $c_res ) && array( array( array( $a_type ), 'reserved' ) ) === $r6_changed );
$c_order = (int) $wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM {$p}wpcap_orders WHERE customer_email=%s ORDER BY order_id DESC LIMIT 1", 'r6-live@example.com' ) );
$r6_changed = array();
Orders::cancel_order( $c_order, 'test' );
check( 'R6-4: released capacity fires the hook with the reason (cancelled)', array( array( array( $a_type ), 'cancelled' ) ) === $r6_changed );
$hooks_doc = (string) file_get_contents( WPCAP_PATH . 'docs/HOOKS.md' );
check( 'R6-4: purge hook documented in HOOKS.md', false !== strpos( $hooks_doc, '`wp_pass_captain_availability_changed_v1`' ) );

echo "R6: REST nonce only for signed-in buyers (#7)\n";
$GLOBALS['wpcap_test_user_id'] = 0;
$guest = PublicEvents::checkout_config();
$GLOBALS['wpcap_test_user_id'] = 7;
$member = PublicEvents::checkout_config();
check( 'R6-5: guests get no nonce (cached HTML stays nonce-free); signed-in buyers get the wp_rest nonce', ! isset( $guest['nonce'] ) && 'n' === ( $member['nonce'] ?? '' ) );
$m_res = r6_data( Controller::checkout( r6_req( array( 'email' => 'r6-member@example.com', 'items' => array( array( 'ticket_type_id' => $a_type, 'quantity' => 1 ) ), 'idempotency_key' => 'r6-member-' . uniqid() ) ) ) );
$m_user = (int) $wpdb->get_var( $wpdb->prepare( "SELECT customer_user_id FROM {$p}wpcap_orders WHERE customer_email=%s", 'r6-member@example.com' ) );
check( 'R6-5: a REST checkout as signed-in user stores customer_user_id (buyer limits per account apply)', is_array( $m_res ) && 7 === $m_user );
$GLOBALS['wpcap_test_user_id'] = 1;
check( 'R6-5: checkout.js sends X-WP-Nonce only when configured', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'assets/checkout.js' ), "if (config.nonce) h['X-WP-Nonce'] = config.nonce;" ) );

echo "R6: personalisation and field types (#9)\n";
$n_type = make_type( 10, 10, 1000 );
$wpdb->update( "{$p}wpcap_ticket_types", array( 'attendee_name' => 'required' ), array( 'ticket_type_id' => $n_type ) );
$no_name = Controller::checkout( r6_req( array( 'email' => 'r6-name@example.com', 'items' => array( array( 'ticket_type_id' => $n_type, 'quantity' => 2 ) ), 'attendees' => array( array( 'ticket_type_id' => $n_type, 'name' => 'Alex' ) ), 'idempotency_key' => 'r6-name-' . uniqid() ) ) );
check( 'R6-8: attendee name "required" is enforced on the server for every ticket (field attendees)', is_wp_error( $no_name ) && 'wpcap_attendee_name_required' === $no_name->get_error_code() && 'attendees' === ( $no_name->get_error_data()['field'] ?? '' ) );
$with_name = Orders::create( array( 'email' => 'r6-name2@example.com', 'items' => array( array( 'ticket_type_id' => $n_type, 'quantity' => 2 ) ), 'attendees' => array( array( 'ticket_type_id' => $n_type, 'name' => 'Alex' ), array( 'ticket_type_id' => $n_type, 'name' => 'Kim' ) ), 'idempotency_key' => 'r6-name2-' . uniqid() ), array( 'skip_rate_limit' => true ) );
check( 'R6-8: with names for every ticket the order is accepted', is_array( $with_name ) );
$wpdb->update( "{$p}wpcap_ticket_types", array( 'attendee_name' => 'off' ), array( 'ticket_type_id' => $n_type ) );
$off = Orders::validate_attendees( array( $n_type => 1 ), array( array( 'ticket_type_id' => $n_type, 'name' => 'Nicht speichern' ) ) );
check( 'R6-8: attendee name "off" is not stored', is_array( $off ) && '' === $off[0]['name'] );
$wpdb->update( "{$p}wpcap_ticket_types", array( 'attendee_name' => 'optional' ), array( 'ticket_type_id' => $n_type ) );
$fid = array();
foreach ( array( 'size' => array( 'select', "S\nM\nL" ), 'menu' => array( 'radio', "Fleisch\nVegan" ), 'born' => array( 'date', null ), 'phone' => array( 'tel', null ) ) as $key => list( $ftype, $opts ) ) {
	$res = EventsPage::save_participant_field( array( 'ticket_type_id' => $n_type, 'field_key' => $key, 'label' => ucfirst( $key ), 'field_type' => $ftype, 'options' => (string) $opts, 'retention_days' => 30 ) );
	$fid[ $key ] = is_array( $res ) ? (int) $res['field_id'] : 0;
}
check( 'R6-8: admin accepts the field types select, radio, date and tel', ! in_array( 0, $fid, true ) && in_array( 'tel', EventsPage::FIELD_TYPES, true ) );
check( 'R6-8: choices are stored one per line', "S\nM\nL" === (string) $wpdb->get_var( $wpdb->prepare( "SELECT options FROM {$p}wpcap_participant_fields WHERE field_id=%d", $fid['size'] ) ) );
$bad = EventsPage::save_participant_field( array( 'ticket_type_id' => $n_type, 'field_key' => 'one', 'label' => 'Eins', 'field_type' => 'select', 'options' => 'Nur eine' ) );
check( 'R6-8: a select field needs at least two choices', is_wp_error( $bad ) && 'wpcap_field_options' === $bad->get_error_code() );
$answers = static function ( array $a ) use ( $n_type ) { return Orders::validate_attendees( array( $n_type => 1 ), array( array( 'ticket_type_id' => $n_type, 'answers' => $a ) ) ); };
$ok = $answers( array( 'size' => 'M', 'menu' => 'Vegan', 'born' => '1990-02-28', 'phone' => '+43 660 123456' ) );
check( 'R6-8: valid select/radio/date/tel answers are accepted', is_array( $ok ) && 'M' === $ok[0]['answers'][ $fid['size'] ] && '1990-02-28' === $ok[0]['answers'][ $fid['born'] ] );
$e1 = $answers( array( 'size' => 'XXL' ) );
$e2 = $answers( array( 'born' => '2026-02-30' ) );
$e3 = $answers( array( 'phone' => 'ruf mich an' ) );
check( 'R6-8: a choice outside the options, an impossible date and a non-phone value are refused', is_wp_error( $e1 ) && is_wp_error( $e2 ) && is_wp_error( $e3 ) && 'wpcap_attendee_field_invalid' === $e1->get_error_code() );
$f_card = PublicEvents::render_shortcode( array( 'occurrence' => r6_occ_of( $n_type ) ) );
check( 'R6-8: the card hands the choices and the name option to the checkout script', false !== strpos( $f_card, '&quot;options&quot;:[&quot;S&quot;,&quot;M&quot;,&quot;L&quot;]' ) && false !== strpos( $f_card, 'data-attendee-name="optional"' ) );
check( 'R6-8: optional buyer name field in the card; the value is stored on the order', false !== strpos( $f_card, 'name="buyer_name"' ) );
$bn = Controller::checkout( r6_req( array( 'email' => 'r6-buyer@example.com', 'buyer_name' => 'Alex Muster', 'items' => array( array( 'ticket_type_id' => $a_type, 'quantity' => 1 ) ), 'idempotency_key' => 'r6-buyer-' . uniqid() ) ) );
check( 'R6-8: buyer_name is stored as customer_name', ! is_wp_error( $bn ) && 'Alex Muster' === $wpdb->get_var( $wpdb->prepare( "SELECT customer_name FROM {$p}wpcap_orders WHERE customer_email=%s", 'r6-buyer@example.com' ) ) );

echo "R6: ticket type description, order, free tickets, stepper (#9, #12)\n";
$d_occ = r6_occ_of( $a_type );
$second = (int) EventsPage::save_ticket_type( array( 'occurrence_id' => $d_occ, 'name' => 'Ermäßigt', 'price' => '0', 'currency' => 'EUR', 'tax_rate' => '0', 'max_per_order' => 5, 'capacity' => 0, 'description' => 'Nur mit Ausweis', 'position' => 0, 'attendee_name' => 'required' ) )['ticket_type_id'];
EventsPage::save_ticket_type( array( 'ticket_type_id' => $a_type, 'name' => 'Standard', 'price' => '10', 'currency' => 'EUR', 'tax_rate' => '0', 'max_per_order' => 4, 'capacity' => 5, 'position' => 5 ) );
$stored = $wpdb->get_row( $wpdb->prepare( "SELECT description,position,attendee_name FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d", $second ) );
check( 'R6-9: admin stores description, position and attendee name option', 'Nur mit Ausweis' === $stored->description && 0 === (int) $stored->position && 'required' === $stored->attendee_name );
$d_card = PublicEvents::render_shortcode( array( 'occurrence' => $d_occ ) );
check( 'R6-9: ticket types are sorted by position, then id', strpos( $d_card, 'data-type="' . $second . '"' ) < strpos( $d_card, 'data-type="' . $a_type . '"' ) );
check( 'R6-9: description is shown and linked to the quantity field', false !== strpos( $d_card, 'class="wpcap-type-description" id="wpcap-' . $d_occ . '-' . $second . '-desc">Nur mit Ausweis' ) && false !== strpos( $d_card, 'aria-describedby="wpcap-' . $d_occ . '-' . $second . '-desc"' ) );
check( 'R6-9: a free ticket reads "kostenlos" instead of 0,00 €', false !== strpos( $d_card, '<span class="wpcap-type-price">kostenlos</span>' ) && false === strpos( $d_card, '>0,00 €<' ) );
check( 'R6-9: mixed card keeps "Zahlungspflichtig bestellen"', false !== strpos( $d_card, 'Zahlungspflichtig bestellen' ) );
$free_type = make_type( 10, 10, 0 );
$free_card = PublicEvents::render_shortcode( array( 'occurrence' => r6_occ_of( $free_type ) ) );
check( 'R6-9: only free tickets: button "Kostenlos bestellen"', false !== strpos( $free_card, 'Kostenlos bestellen' ) && false === strpos( $free_card, 'Zahlungspflichtig bestellen' ) );
check( 'R6-12: −/+ stepper buttons with an accessible name per ticket type', 1 === preg_match( '/<button type="button" class="wpcap-step" data-step="-1" tabindex="-1" aria-controls="wpcap-' . $d_occ . '-' . $a_type . '" aria-label="Anzahl „Standard“ verringern">/', $d_card ) && false !== strpos( $d_card, 'aria-label="Anzahl „Standard“ erhöhen"' ) );
check( 'R6-6: no live region around the whole attendee block, a short status instead', false !== strpos( $d_card, '<div class="wpcap-attendees"></div>' ) && false !== strpos( $d_card, 'class="wpcap-attendee-status screen-reader-text" role="status"' ) );

echo "R6: checkout errors – Retry-After and remaining tickets (#7)\n";
$busy = Controller::checkout_error( new WP_Error( 'wpcap_checkout_failed', 'busy', array( 'status' => 503, 'retryable' => true ) ), array() );
check( 'R6-10: busy database (503, retryable) carries retry_after for the Retry-After header', 2 === ( $busy->get_error_data()['retry_after'] ?? 0 ) );
check( 'R6-10: the Retry-After header also covers retryable checkout errors', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Sales/CheckoutGate.php' ), "! empty( \$error['retryable'] )" ) );
$s_type = make_type( 2, 10, 1000 );
Orders::create( array( 'email' => 'r6-first@example.com', 'items' => array( array( 'ticket_type_id' => $s_type, 'quantity' => 1 ) ), 'idempotency_key' => 'r6-first-' . uniqid() ), array( 'skip_rate_limit' => true ) );
$short = Controller::checkout( r6_req( array( 'email' => 'r6-late@example.com', 'items' => array( array( 'ticket_type_id' => $s_type, 'quantity' => 2 ) ), 'idempotency_key' => 'r6-late-' . uniqid() ) ) );
check( 'R6-10: 409 "not enough tickets" includes the remaining quantity per requested type', is_wp_error( $short ) && 409 === ( $short->get_error_data()['status'] ?? 0 ) && array( (string) $s_type => 1 ) === ( $short->get_error_data()['remaining'] ?? null ) );

echo "R6: return from the payment page (#9, #7)\n";
final class R6_Test_Gateway implements PassCaptain\Payments\GatewayInterface {
	public static $calls = 0;
	public static $cancelled = 0;
	public function id() { return 'r6test'; }
	public function title() { return 'R6 Test'; }
	public function is_available( $currency ) { return true; }
	public function create_payment( array $order, array $items = array() ) { self::$calls++; return array( 'provider' => 'r6test', 'status' => 'pending', 'redirect_url' => 'https://psp.example/pay/' . $order['public_id'] . '?n=' . self::$calls, 'provider_reference' => 'r6-' . self::$calls ); }
	public function cancel_payment( array $order ) { self::$cancelled++; return true; }
	public function verify_webhook( \WP_REST_Request $request ) { return new WP_Error( 'x', 'x' ); }
	public function supports_refunds() { return false; }
	public function refund( array $order, $amount_minor, $idempotency_key ) { return new WP_Error( 'x', 'x' ); }
}
add_filter( 'wpcap_payment_gateways', static function ( $list ) { $list[] = new R6_Test_Gateway(); return $list; } );
PassCaptain\Payments\Registry::reset();
$r6_enabled_before = (array) Settings::get( 'enabled_providers' );
Settings::update( array( 'enabled_providers' => array_values( array_unique( array_merge( $r6_enabled_before, array( 'r6test' ) ) ) ) ) );
ReturnNotice::reset();
$pay = r6_data( Controller::checkout( r6_req( array( 'email' => 'r6-pay@example.com', 'provider' => 'r6test', 'return_to' => 'https://example.test/konzerte/?wpcap_occ=5&wpcap_payment=cancel#tickets', 'items' => array( array( 'ticket_type_id' => $a_type, 'quantity' => 1 ) ), 'idempotency_key' => 'r6-pay-' . uniqid() ) ) ) );
$pay_order = $wpdb->get_row( $wpdb->prepare( "SELECT order_id,public_id FROM {$p}wpcap_orders WHERE customer_email=%s", 'r6-pay@example.com' ) );
$token = ReturnNotice::token( $pay_order->public_id );
check( 'R6-7: hosted payment pages return to the ordering page (return_to, without old return parameters)', 0 === strpos( ReturnNotice::url( 'cancel', $pay_order->public_id ), 'https://example.test/konzerte/?wpcap_occ=5?' ) || 0 === strpos( ReturnNotice::url( 'cancel', $pay_order->public_id ), 'https://example.test/konzerte/?wpcap_occ=5&' ) );
check( 'R6-7: return URLs carry the order return token', false !== strpos( PassCaptain\Payments\StripeGateway::return_url( 'cancel', $pay_order->public_id ), 'wpcap_rt=' . $token ) );
ReturnNotice::set_base( 'https://evil.example/phish' );
check( 'R6-7: a foreign return page is refused (home page instead)', 0 === strpos( ReturnNotice::url( 'success', $pay_order->public_id ), 'https://example.test/?' ) );
ReturnNotice::reset();
check( 'R6-7: PayPal/Mollie return handlers keep the return page', false !== strpos( ( static function () { ReturnNotice::set_base( 'https://example.test/konzerte/' ); $u = ReturnNotice::handler_url( array( 'wpcap_paypal_return' => '1' ) ); ReturnNotice::reset(); return $u; } )(), 'wpcap_return_to=' ) );
$denied = ReturnNotice::retry( $pay_order->public_id, str_repeat( '0', 32 ) );
check( 'R6-7: "pay again" needs the return token', is_wp_error( $denied ) && 403 === $denied->get_error_data()['status'] );
$again = ReturnNotice::retry( $pay_order->public_id, $token, 'https://example.test/konzerte/' );
check( 'R6-7: "pay again" opens a new payment page for the same order while the hold lasts', is_array( $again ) && 0 === strpos( $again['redirect_url'], 'https://psp.example/pay/' . $pay_order->public_id ) && 'r6-2' === $wpdb->get_var( $wpdb->prepare( "SELECT provider_reference FROM {$p}wpcap_orders WHERE order_id=%d", $pay_order->order_id ) ) );
$_GET = array( 'wpcap_payment' => 'cancel', 'order' => $pay_order->public_id, 'wpcap_rt' => $token );
$banner = ReturnNotice::html();
check( 'R6-7: after a cancelled payment the banner offers "Erneut bezahlen" and "Reservierung freigeben"', false !== strpos( $banner, 'Erneut bezahlen' ) && false !== strpos( $banner, 'Reservierung freigeben' ) && false !== strpos( $banner, 'value="wpcap_payment_release"' ) && false !== strpos( $banner, 'reserviert' ) );
check( 'R6-7: banner falls back to the_content once when wp_body_open is missing', 0 === strpos( ReturnNotice::prepend_to_content( '<p>Inhalt</p>' ), '<div class="wpcap-payment-notice"' ) && '<p>x</p>' === ReturnNotice::prepend_to_content( '<p>x</p>' ) );
check( 'R6-7: pages with ?wpcap_payment are never cached', true === ReturnNotice::no_cache() && defined( 'DONOTCACHEPAGE' ) );
$_GET = array();
ReturnNotice::reset();
$before = Orders::availability( $a_type );
$r6_changed = array();
$r6_released = ReturnNotice::release( $pay_order->public_id, $token );
$pay_state = $wpdb->get_row( $wpdb->prepare( "SELECT status,payment_status FROM {$p}wpcap_orders WHERE order_id=%d", $pay_order->order_id ) );
check( 'R6-7: "release reservation" cancels the unpaid order at once (gateway session ended first)', true === $r6_released && 'cancelled' === $pay_state->status && 1 === R6_Test_Gateway::$cancelled && Orders::availability( $a_type ) === $before + 1 );
check( 'R6-7: …and purge adapters are told (cancelled)', array( array( array( $a_type ), 'cancelled' ) ) === $r6_changed );
$closed = ReturnNotice::retry( $pay_order->public_id, $token );
check( 'R6-7: no "pay again" for a released order', is_wp_error( $closed ) && 409 === $closed->get_error_data()['status'] );
$paid = Orders::create( array( 'email' => 'r6-paid@example.com', 'items' => array( array( 'ticket_type_id' => $free_type, 'quantity' => 1 ) ), 'idempotency_key' => 'r6-paid-' . uniqid() ), array( 'skip_rate_limit' => true ) );
$paid_pid = (string) $wpdb->get_var( $wpdb->prepare( "SELECT public_id FROM {$p}wpcap_orders WHERE order_id=%d", $paid['order_id'] ) );
$no_release = ReturnNotice::release( $paid_pid, ReturnNotice::token( $paid_pid ) );
check( 'R6-7: a paid order is never released', is_wp_error( $no_release ) && 'complete' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$p}wpcap_orders WHERE order_id=%d", $paid['order_id'] ) ) );
Settings::update( array( 'enabled_providers' => $r6_enabled_before ) );
PassCaptain\Payments\Registry::reset();
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

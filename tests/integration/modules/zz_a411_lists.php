<?php
// phpcs:ignoreFile
/**
 * A411 (second part): the ticket list and the event list are WP_List_Tables like the order list – views,
 * sortable columns on cheap/indexed keys only, screen option "Einträge pro Seite" per user, captions; the
 * event list has the bulk action "Als Entwurf duplizieren" through the single action's Duplicator. The
 * ticket list keeps its filters, row actions, A414 return fields, A688 bulk check-in and the role masking.
 * Uses the WP_List_Table stand-in of tests/integration/lib/list_table_standin.php (no wp-admin here).
 */

use PassCaptain\Admin\EventsPage;
use PassCaptain\Admin\ListTables;
use PassCaptain\Admin\Queries;
use PassCaptain\Admin\TicketsPage;
use PassCaptain\Orders;

echo "A411: ticket and event lists as WP_List_Table\n";
global $wpdb;
$p = $wpdb->prefix;
$a4l_root = dirname( __DIR__, 3 );
require_once dirname( __DIR__ ) . '/lib/admin_render_stubs.php';
require_once dirname( __DIR__ ) . '/lib/list_table_standin.php';
foreach ( array( 'esc_url' => 'return htmlspecialchars( (string) $a[0], ENT_QUOTES );', 'wp_nonce_url' => 'return $a[0] . "&_wpnonce=n";' ) as $a4l_fn => $a4l_body ) {
	if ( ! function_exists( $a4l_fn ) ) { eval( "function $a4l_fn( ...\$a ) { $a4l_body }" ); }
}
if ( ! function_exists( 'get_user_meta' ) ) { function get_user_meta( $u, $k, $single = false ) { return $GLOBALS['p1u_user_meta'][ $u ][ $k ] ?? ''; } }
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
$a4l_render = static function ( $class, array $get ) {
	$_GET = $get;
	$_REQUEST = $get;
	ob_start();
	try { $class::render(); } catch ( Throwable $e ) { echo 'EXCEPTION ' . get_class( $e ) . ' ' . $e->getMessage(); }
	$html = (string) ob_get_clean();
	$_GET = array();
	$_REQUEST = array();
	return $html;
};
check( 'A411L the core list table class is available in this run (stand-in)', ListTables::available() );

/* ------------------------------------------------------------------------------------------ tickets */

// Sort keys: header column "ticket" maps to newest/oldest, only the date order has a second direction.
$a4l_f = static fn( array $q ) => TicketsPage::filters( $q );
check( 'A411L ticket sort: orderby=ticket&order=asc is "oldest", without direction "newest"', 'oldest' === $a4l_f( array( 'orderby' => 'ticket', 'order' => 'asc' ) )['orderby'] && 'newest' === $a4l_f( array( 'orderby' => 'ticket' ) )['orderby'] && '' === $a4l_f( array( 'orderby' => 'ticket', 'order' => 'asc' ) )['order'] );
check( 'A411L ticket sort: date keeps "desc", other orders ignore the direction, unknown values fall back', 'desc' === $a4l_f( array( 'orderby' => 'date', 'order' => 'DESC' ) )['order'] && '' === $a4l_f( array( 'orderby' => 'type', 'order' => 'desc' ) )['order'] && '' === $a4l_f( array( 'orderby' => 'date', 'order' => 'sideways' ) )['order'] && 'newest' === $a4l_f( array( 'orderby' => 't.ticket_id; DROP' ) )['orderby'] );
check( 'A411L ticket ORDER BY: date in both directions, the other keys unchanged (fixed SQL)', 'o.starts_at DESC, t.ticket_id DESC' === Queries::ticket_order( 'date', 'desc' ) && 'o.starts_at ASC, t.ticket_id ASC' === Queries::ticket_order( 'date' ) && 't.ticket_id ASC' === Queries::ticket_order( 'oldest', 'desc' ) && 't.ticket_id DESC' === Queries::ticket_order( "x'", 'desc' ) );
check( 'A411L header sort of the ticket list: newest/oldest on the ticket column, date on the event column, others none', array( 'ticket', 'desc' ) === TicketsPage::header_sort( array( 'orderby' => 'newest' ) ) && array( 'ticket', 'asc' ) === TicketsPage::header_sort( array( 'orderby' => 'oldest' ) ) && array( 'date', 'desc' ) === TicketsPage::header_sort( array( 'orderby' => 'date', 'order' => 'desc' ) ) && array( '', '' ) === TicketsPage::header_sort( array( 'orderby' => 'name' ) ) );
$a4l_back = new ReflectionMethod( TicketsPage::class, 'back_url' );
$a4l_back->setAccessible( true );
parse_str( (string) wp_parse_url( $a4l_back->invoke( null, array( 'return_search' => 'Muster', 'return_event' => '5', 'return_status' => 'valid', 'return_orderby' => 'date', 'return_order' => 'desc', 'return_paged' => '2' ) ), PHP_URL_QUERY ), $a4l_q );
check( 'A411L A414: after an action the list returns with search, event, status, page and the sort direction', 'Muster' === ( $a4l_q['s'] ?? '' ) && '5' === ( $a4l_q['event_id'] ?? '' ) && 'valid' === ( $a4l_q['status'] ?? '' ) && '2' === ( $a4l_q['paged'] ?? '' ) && 'date' === ( $a4l_q['orderby'] ?? '' ) && 'desc' === ( $a4l_q['order'] ?? '' ) );
parse_str( (string) wp_parse_url( $a4l_back->invoke( null, array( 'return_orderby' => 'type', 'return_order' => 'desc' ) ), PHP_URL_QUERY ), $a4l_q );
check( 'A411L A414: a direction without the date order is dropped', ! isset( $a4l_q['order'] ) && 'type' === ( $a4l_q['orderby'] ?? '' ) );
$a4l_views = TicketsPage::list_views( TicketsPage::filters( array( 's' => 'abc', 'event_id' => 7, 'status' => 'revoked', 'orderby' => 'oldest' ) ) );
check( 'A411L ticket views: all, valid, checked in, revoked; the active one marked; search, event and sort kept, status replaced', array( 'all', 'valid', 'checked_in', 'revoked' ) === array_keys( $a4l_views ) && $a4l_views['revoked']['current'] && ! $a4l_views['all']['current'] && false !== strpos( $a4l_views['valid']['url'], 'status=valid' ) && false !== strpos( $a4l_views['valid']['url'], 'event_id=7' ) && false !== strpos( $a4l_views['valid']['url'], 's=abc' ) && false !== strpos( $a4l_views['valid']['url'], 'orderby=oldest' ) && false === strpos( $a4l_views['all']['url'], 'status=' ) );
check( 'A411L ticket screen option: 1–200, other options untouched', 200 === TicketsPage::save_per_page( false, TicketsPage::PER_PAGE_OPTION, '999' ) && 1 === TicketsPage::save_per_page( false, TicketsPage::PER_PAGE_OPTION, '0' ) && 'keep' === TicketsPage::save_per_page( 'keep', 'other', '5' ) && 30 === TicketsPage::PER_PAGE_DEFAULT );

// Rendering through the list table: one event with three tickets, page size 2 from the screen option.
$a4l_type = make_type( 20, 10, 1500 );
$a4l_event = (int) $wpdb->get_var( $wpdb->prepare( "SELECT o.event_id FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=%d", $a4l_type ) );
$a4l_order = admin_paid_order( $a4l_type, 3, 'a411l.buyer@example.com' );
$a4l_tickets = array_map( static fn( $t ) => (int) $t->ticket_id, Orders::tickets_for_order( $a4l_order ) );
sort( $a4l_tickets );
check( 'A411L three paid tickets for the test event', 3 === count( $a4l_tickets ) );
$GLOBALS['z3l_user_options'] = array( TicketsPage::PER_PAGE_OPTION => 2 );
$a4l_html = $a4l_render( TicketsPage::class, array( 'page' => TicketsPage::SLUG, 'event_id' => (string) $a4l_event ) );
check( 'A411L ticket list renders through the list table', false === strpos( $a4l_html, 'EXCEPTION' ) && false !== strpos( $a4l_html, 'class="wp-list-table ' ) );
check( 'A411L ticket list: page size from the screen option, total of the filter', false !== strpos( $a4l_html, '<span class="pages">3/2</span>' ) && 2 === substr_count( $a4l_html, 'name="ticket_ids[]"' ) );
check( 'A411L ticket list: newest first by default, the ticket column marked as sorted descending', false !== strpos( $a4l_html, 'value="' . $a4l_tickets[2] . '"' ) && false === strpos( $a4l_html, 'name="ticket_ids[]" form="wpcap-bulk-checkin" value="' . $a4l_tickets[0] . '"' ) && (bool) preg_match( '/class="column-ticket column-primary sorted desc" aria-sort="descending"/', $a4l_html ) && false !== strpos( $a4l_html, 'column-event sortable' ) );
check( 'A411L ticket list: no core bulk selector and no bulk nonce (A688 keeps its own form), row checkboxes join that form', false === strpos( $a4l_html, 'name="action"><option value="-1">' ) && false === strpos( $a4l_html, 'value="bulk-tickets"' ) && false !== strpos( $a4l_html, 'id="wpcap-bulk-checkin"' ) && false !== strpos( $a4l_html, 'form="wpcap-bulk-checkin"' ) );
check( 'A411L ticket list: caption, ticket number as row header, views', false !== strpos( $a4l_html, '<caption class="screen-reader-text">' . TicketsPage::list_caption() . '</caption>' ) && false !== strpos( $a4l_html, '<th scope="row" class="column-ticket column-primary"><code>' ) && false !== strpos( $a4l_html, 'class="subsubsub"' ) && false !== strpos( $a4l_html, 'aria-current="page"' ) );
check( 'A411L ticket list: the page-number field submits the GET form with the list state (rows hold their own forms)', false !== strpos( $a4l_html, "name='paged' form='" . TicketsPage::PAGING_FORM . "'" ) && (bool) preg_match( '/<form method="get" id="' . TicketsPage::PAGING_FORM . '"[^>]*><input type="hidden" name="page" value="wpcap-tickets"><input type="hidden" name="event_id" value="' . $a4l_event . '">/', $a4l_html ) );
check( 'A411L ticket list: row actions unchanged and every action form carries the list state (A414)', false !== strpos( $a4l_html, 'value="wpcap_revoke_ticket"' ) && false !== strpos( $a4l_html, 'value="wpcap_reissue_ticket"' ) && false !== strpos( $a4l_html, 'value="wpcap_correct_attendee"' ) && false !== strpos( $a4l_html, 'value="wpcap_checkin_ticket"' ) && substr_count( $a4l_html, 'name="return_event" value="' . $a4l_event . '"' ) >= 8 && false !== strpos( $a4l_html, 'name="return_order"' ) && false !== strpos( $a4l_html, 'a411l.buyer@example.com' ) );
$a4l_get_before = array( 'orderby' => 'oldest', 'page' => TicketsPage::SLUG, 'event_id' => (string) $a4l_event );
$a4l_html = $a4l_render( TicketsPage::class, $a4l_get_before );
check( 'A411L ticket list: the sort select\'s "oldest" sorts ascending and marks the ticket column ascending', false !== strpos( $a4l_html, 'value="' . $a4l_tickets[0] . '"' ) && false === strpos( $a4l_html, 'name="ticket_ids[]" form="wpcap-bulk-checkin" value="' . $a4l_tickets[2] . '"' ) && (bool) preg_match( '/column-ticket column-primary sorted asc" aria-sort="ascending"/', $a4l_html ) );
$a4l_html = $a4l_render( TicketsPage::class, array( 'page' => TicketsPage::SLUG, 'event_id' => (string) $a4l_event, 'orderby' => 'ticket', 'order' => 'asc' ) );
check( 'A411L ticket list: the header link (orderby=ticket&order=asc) sorts the same way', false !== strpos( $a4l_html, 'value="' . $a4l_tickets[0] . '"' ) && false === strpos( $a4l_html, 'name="ticket_ids[]" form="wpcap-bulk-checkin" value="' . $a4l_tickets[2] . '"' ) );
$_GET = array( 'orderby' => 'oldest', 'keep' => '1' );
$a4l_table = new PassCaptain\Admin\TicketsListTable();
$a4l_table->load( TicketsPage::filters( $_GET ), 1, array( 'rows' => array(), 'total' => 0 ), 2, false );
ob_start();
$a4l_table->display();
ob_end_clean();
check( 'A411L ticket list: the query string is restored after the headers were drawn', array( 'orderby' => 'oldest', 'keep' => '1' ) === $_GET );
$_GET = array();
$a4l_html = $a4l_render( TicketsPage::class, array( 'page' => TicketsPage::SLUG, 's' => 'a411l-nobody-' . uniqid() ) );
check( 'A411L ticket list: empty result says so', false !== strpos( $a4l_html, 'Keine Tickets gefunden.' ) && false === strpos( $a4l_html, 'EXCEPTION' ) );
$a4l_html = $a4l_render( TicketsPage::class, array( 'page' => TicketsPage::SLUG ) );
check( 'A411L ticket list without a filter: search hint, no table, status views offered', false !== strpos( $a4l_html, 'Bitte nach Ticket, Käufer oder Teilnehmer suchen' ) && false === strpos( $a4l_html, 'wp-list-table' ) );

// Role masking stays: door staff (wpcap_checkin only, assigned to the event) sees no contact data.
$a4l_door = 9411;
$wpdb->insert( "{$p}wpcap_event_staff", array( 'event_id' => $a4l_event, 'user_id' => $a4l_door, 'capability' => 'wpcap_checkin' ) );
$GLOBALS['wpcap_test_user_id'] = $a4l_door;
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options', 'wpcap_manage_orders', 'wpcap_manage_events', 'wpcap_view_reports', 'wpcap_view_finance', 'wpcap_refund', 'wpcap_approve_refunds' );
$a4l_door_html = $a4l_render( TicketsPage::class, array( 'page' => TicketsPage::SLUG, 'event_id' => (string) $a4l_event ) );
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'A411L door staff: list renders the tickets, without buyer e-mail and without order actions', false === strpos( $a4l_door_html, 'EXCEPTION' ) && false !== strpos( $a4l_door_html, 'wp-list-table' ) && false === strpos( $a4l_door_html, 'a411l.buyer' ) && false === strpos( $a4l_door_html, 'value="wpcap_revoke_ticket"' ) && false !== strpos( $a4l_door_html, 'value="wpcap_checkin_ticket"' ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_checkin' );
$a4l_nocheckin = $a4l_render( TicketsPage::class, array( 'page' => TicketsPage::SLUG, 'event_id' => (string) $a4l_event ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'A411L without the check-in capability: no selection column and no check-in action', false === strpos( $a4l_nocheckin, 'name="ticket_ids[]"' ) && false === strpos( $a4l_nocheckin, 'cb-select-all' ) && false === strpos( $a4l_nocheckin, 'value="wpcap_checkin_ticket"' ) && false !== strpos( $a4l_nocheckin, 'value="wpcap_revoke_ticket"' ) );

// Plain fallback table (when WP_List_Table cannot be loaded) prints the same cells.
$a4l_plain = new ReflectionMethod( TicketsPage::class, 'render_plain_list' );
$a4l_plain->setAccessible( true );
$a4l_f0 = TicketsPage::filters( array( 'event_id' => $a4l_event ) );
ob_start();
$a4l_plain->invoke( null, TicketsPage::list_data( $a4l_f0, 1, 30 ), $a4l_f0, 1, false );
$a4l_plain_html = (string) ob_get_clean();
check( 'A411L ticket fallback table: caption, column headers, three rows with checkbox and actions', false !== strpos( $a4l_plain_html, '<caption class="screen-reader-text">' ) && false !== strpos( $a4l_plain_html, '<th scope="col">Event / Termin</th>' ) && 3 === substr_count( $a4l_plain_html, 'name="ticket_ids[]"' ) && 3 === substr_count( $a4l_plain_html, 'value="wpcap_revoke_ticket"' ) );

/* ------------------------------------------------------------------------------------------- events */

$_GET = array( 'status' => 'draft', 'orderby' => 'name', 'order' => 'DESC' );
check( 'A411L event list state: status view and sort read from the query string', array( 'status' => 'draft', 'orderby' => 'name', 'order' => 'desc' ) === EventsPage::list_state() );
$_GET = array( 'status' => 'gone', 'orderby' => 'e.name', 'order' => 'desc' );
check( 'A411L event list state: unknown status and sort columns are ignored', array( 'status' => '', 'orderby' => '', 'order' => '' ) === EventsPage::list_state() );
$_GET = array();
check( 'A411L event ORDER BY whitelist: name/status in both directions, default newest first', 'e.name DESC, e.event_id DESC' === Queries::events_order_sql( array( 'orderby' => 'name', 'order' => 'desc' ) ) && 'e.status ASC, e.created_at DESC, e.event_id DESC' === Queries::events_order_sql( array( 'orderby' => 'status' ) ) && 'e.created_at DESC, e.event_id DESC' === Queries::events_order_sql( array( 'orderby' => 'next_occurrence', 'order' => 'desc' ) ) );
check( 'A411L event screen option: 1–200, other options untouched, default 50 as before', 200 === EventsPage::save_per_page( false, EventsPage::PER_PAGE_OPTION, '500' ) && 1 === EventsPage::save_per_page( false, EventsPage::PER_PAGE_OPTION, '0' ) && 'keep' === EventsPage::save_per_page( 'keep', 'other', '5' ) && 50 === EventsPage::PER_PAGE_DEFAULT );

$a4l_uniq = substr( md5( uniqid( '', true ) ), 0, 6 );
$a4l_mk = static function ( $name, $status ) use ( $wpdb, $p ) {
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( "{$p}wpcap_events", array( 'name' => $name, 'slug' => 'a411l-' . uniqid(), 'description' => '', 'status' => $status, 'created_at' => $now, 'updated_at' => $now ) );
	$id = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $id, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 9 * 86400 ), 'timezone' => 'Europe/Vienna', 'location' => 'Graz', 'capacity' => 0, 'status' => 'scheduled' ) );
	return $id;
};
$a4l_ev = array(
	'b' => $a4l_mk( '000A411L ' . $a4l_uniq . ' Bravo', 'draft' ),
	'a' => $a4l_mk( '000A411L ' . $a4l_uniq . ' Alpha', 'draft' ),
	'c' => $a4l_mk( '000A411L ' . $a4l_uniq . ' Charlie', 'published' ),
);
$a4l_names = static function ( array $rows ) use ( $a4l_uniq ) {
	return array_values( array_map( static fn( $r ) => substr( $r->name, strlen( '000A411L ' . $a4l_uniq . ' ' ) ), array_filter( $rows, static fn( $r ) => str_starts_with( $r->name, '000A411L ' . $a4l_uniq ) ) ) );
};
check( 'A411L events sorted by name ascending', array( 'Alpha', 'Bravo', 'Charlie' ) === $a4l_names( Queries::events_overview( null, 1, 200, array( 'orderby' => 'name', 'order' => 'asc' ) )['rows'] ) );
check( 'A411L events: the draft view lists drafts only', array( 'Alpha', 'Bravo' ) === $a4l_names( Queries::events_overview( null, 1, 200, array( 'status' => 'draft', 'orderby' => 'name' ) )['rows'] ) && ! array_filter( Queries::events_overview( null, 1, 200, array( 'status' => 'draft' ) )['rows'], static fn( $r ) => 'draft' !== $r->status ) );
$a4l_counts = Queries::event_status_counts( null );
check( 'A411L event status counts: per state and in total (scope respected)', $a4l_counts['all'] === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_events" ) && $a4l_counts['draft'] === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_events WHERE status='draft'" ) && array( 'all' => 2, 'draft' => 1, 'published' => 1, 'cancelled' => 0 ) === Queries::event_status_counts( array( $a4l_ev['a'], $a4l_ev['c'] ) ) );
$a4l_eviews = EventsPage::list_views( array( 'status' => 'draft', 'orderby' => 'name', 'order' => 'desc' ), array( 'all' => 3, 'draft' => 2, 'published' => 1, 'cancelled' => 0 ) );
check( 'A411L event views: all, draft, published with counts; empty states left out; the active one marked; sort kept', array( 'all', 'draft', 'published' ) === array_keys( $a4l_eviews ) && 'Alle (3)' === $a4l_eviews['all']['label'] && $a4l_eviews['draft']['current'] && false !== strpos( $a4l_eviews['published']['url'], 'status=published' ) && false !== strpos( $a4l_eviews['published']['url'], 'orderby=name' ) && false === strpos( $a4l_eviews['all']['url'], 'status=' ) );

$GLOBALS['z3l_user_options'] = array( EventsPage::PER_PAGE_OPTION => 2 );
$GLOBALS['p1u_user_meta'][1][ PassCaptain\Admin\GettingStarted::META ] = 0;
$a4l_ehtml = $a4l_render( EventsPage::class, array( 'page' => EventsPage::SLUG, 'status' => 'draft', 'orderby' => 'name', 'order' => 'asc' ) );
check( 'A411L event list renders through the list table', false === strpos( $a4l_ehtml, 'EXCEPTION' ) && false !== strpos( $a4l_ehtml, 'class="wp-list-table ' ) );
check( 'A411L event list: page size from the screen option, total of the view', false !== strpos( $a4l_ehtml, '<span class="pages">' . (int) $a4l_counts['draft'] . '/2</span>' ) && 2 === substr_count( $a4l_ehtml, 'name="event[]"' ) && false === strpos( $a4l_ehtml, 'Veröffentlicht</td>' ) );
check( 'A411L event list: bulk action "Als Entwurf duplizieren", labelled checkboxes, sortable name/status', false !== strpos( $a4l_ehtml, 'value="' . EventsPage::BULK_DUPLICATE . '">Als Entwurf duplizieren' ) && false !== strpos( $a4l_ehtml, '<label class="screen-reader-text" for="wpcap-event-' ) && (bool) preg_match( '/column-event column-primary sorted asc" aria-sort="ascending"/', $a4l_ehtml ) && false !== strpos( $a4l_ehtml, 'column-status sortable' ) );
check( 'A411L event list: caption, views, unchanged actions, bulk form posts back to the view', false !== strpos( $a4l_ehtml, '<caption class="screen-reader-text">' . EventsPage::list_caption() . '</caption>' ) && false !== strpos( $a4l_ehtml, 'class="subsubsub"' ) && false !== strpos( $a4l_ehtml, '>Bearbeiten</a>' ) && (bool) preg_match( '/<form method="post" action="[^"]*page=pass-captain[^"]*status=draft[^"]*orderby=name/', $a4l_ehtml ) && false !== strpos( $a4l_ehtml, 'value="bulk-events"' ) );
check( 'A411L event list: create form and import stay below the list, former table class kept', false !== strpos( $a4l_ehtml, 'class="wp-list-table widefat striped events wpcap-events-list"' ) && false !== strpos( $a4l_ehtml, '<h2 id="wpcap-create-event">' ) && strpos( $a4l_ehtml, 'wp-list-table' ) < strpos( $a4l_ehtml, 'wpcap-create-event' ) );
$a4l_ehtml = $a4l_render( EventsPage::class, array( 'page' => EventsPage::SLUG, 'status' => 'cancelled', 'orderby' => 'name' ) );
check( 'A411L event list: a view without events offers "Filter zurücksetzen" (or lists cancelled events only)', false === strpos( $a4l_ehtml, 'EXCEPTION' ) && ( false !== strpos( $a4l_ehtml, 'Filter zurücksetzen' ) || false === strpos( $a4l_ehtml, '>Entwurf</td>' ) ) );
unset( $GLOBALS['z3l_user_options'] );

// Plain fallback keeps the former markup (zz_a088 reads it).
$a4l_eplain = new ReflectionMethod( EventsPage::class, 'render_plain_list' );
$a4l_eplain->setAccessible( true );
ob_start();
$a4l_eplain->invoke( null, array( 'status' => 'published', 'orderby' => 'name', 'order' => 'asc' ), 1 );
$a4l_eplain_html = (string) ob_get_clean();
check( 'A411L event fallback table: caption, scoped headers, event as row header, views', false !== strpos( $a4l_eplain_html, '<caption class="screen-reader-text">' ) && false !== strpos( $a4l_eplain_html, '<th scope="col">Verkauft / Kapazität</th>' ) && false !== strpos( $a4l_eplain_html, '<th scope="row"><strong><a href=' ) && false !== strpos( $a4l_eplain_html, 'class="subsubsub"' ) );

// Bulk duplication through the single action's Duplicator.
$a4l_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_events" );
$a4l_dup = EventsPage::bulk_duplicate( array( $a4l_ev['a'], (string) $a4l_ev['c'], 0, $a4l_ev['a'], 99999999 ) );
$a4l_copies = $wpdb->get_results( "SELECT event_id,name,status FROM {$p}wpcap_events WHERE event_id IN (" . implode( ',', array_map( 'intval', $a4l_dup['duplicated'] ?: array( 0 ) ) ) . ')' );
check( 'A411L bulk duplicate: each selected event copied once as a draft "(Kopie)" with its upcoming date', 2 === count( $a4l_dup['duplicated'] ) && $a4l_before + 2 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_events" ) && 2 === count( array_filter( $a4l_copies, static fn( $r ) => 'draft' === $r->status && str_contains( $r->name, '(Kopie)' ) ) ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_occurrences WHERE event_id=%d", $a4l_dup['duplicated'][ $a4l_ev['c'] ] ?? 0 ) ) );
check( 'A411L bulk duplicate: an unknown id fails like the single action', isset( $a4l_dup['failed'][99999999] ) && 0 === $a4l_dup['over_limit'] );
$GLOBALS['wpcap_test_denied_caps'] = array( EventsPage::CAP );
$a4l_denied = EventsPage::bulk_duplicate( array( $a4l_ev['b'] ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'A411L bulk duplicate: without the capability the single-action check refuses it', isset( $a4l_denied['failed'][ $a4l_ev['b'] ] ) && ! $a4l_denied['duplicated'] );
$a4l_over = EventsPage::bulk_duplicate( array_merge( array( 99999990 ), range( 99999901, 99999901 + EventsPage::BULK_LIMIT ) ) );
check( 'A411L bulk duplicate: at most BULK_LIMIT events per request, the rest reported', EventsPage::BULK_LIMIT === count( $a4l_over['failed'] ) && 2 === $a4l_over['over_limit'] );
$a4l_msgs = EventsPage::bulk_messages( $a4l_dup );
check( 'A411L bulk duplicate: notices name the copies and the failures', 'success' === $a4l_msgs[0][0] && false !== strpos( $a4l_msgs[0][1], '2 Events als Entwurf dupliziert.' ) && 'error' === $a4l_msgs[1][0] && 'warning' === EventsPage::bulk_messages( array() )[0][0] && false !== strpos( EventsPage::bulk_messages( $a4l_over )[ count( EventsPage::bulk_messages( $a4l_over ) ) - 1 ][1], 'Höchstens 20 Events' ) );

// load-{hook}: capability before the nonce, then the same path, back to the list with its view.
$_POST = array( 'action' => EventsPage::BULK_DUPLICATE, 'event' => array( (string) $a4l_ev['b'] ) );
$_GET = array( 'page' => EventsPage::SLUG, 'status' => 'draft' );
$GLOBALS['wpcap_test_nonce_checks'] = array();
$GLOBALS['wpcap_test_denied_caps'] = array( EventsPage::CAP );
$a4l_die = false;
try { EventsPage::load_screen(); } catch ( Wpcap_Test_Die $e ) { $a4l_die = true; }
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'A411L bulk action without capability: refused before the nonce check', $a4l_die && array() === $GLOBALS['wpcap_test_nonce_checks'] );
$a4l_redirect = '';
$a4l_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_events" );
try { EventsPage::load_screen(); } catch ( Wpcap_Test_Redirect $e ) { $a4l_redirect = $e->getMessage(); }
check( 'A411L bulk action: nonce "bulk-events" checked, event duplicated, redirect to the list with its view', array( 'bulk-events' ) === $GLOBALS['wpcap_test_nonce_checks'] && $a4l_count + 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_events" ) && false !== strpos( $a4l_redirect, 'page=pass-captain' ) && false !== strpos( $a4l_redirect, 'status=draft' ) );
$_POST = array( 'action' => '-1', 'action2' => '-1' );
$a4l_none = 'none';
try { EventsPage::load_screen(); $a4l_none = 'none'; } catch ( Throwable $e ) { $a4l_none = get_class( $e ); }
$_POST = array();
$_GET = array( 'page' => EventsPage::SLUG, 'view' => 'event', 'event_id' => '1' );
try { EventsPage::load_screen(); } catch ( Throwable $e ) { $a4l_none = get_class( $e ); }
$_GET = array();
check( 'A411L load-{hook} without a bulk action (or on another view) changes nothing', 'none' === $a4l_none );
ob_start();
PassCaptain\Admin\Forms::render_notices(); // Clear the notices of the bulk runs above.
ob_end_clean();

// Menu wiring.
$a4l_menu = (string) file_get_contents( $a4l_root . '/src/Admin/Menu.php' );
check( 'A411L ticket and event pages hook screen option (and bulk action) on load-{hook}, page sizes saved through set_screen_option', false !== strpos( $a4l_menu, "array( TicketsPage::class, 'load_screen' )" ) && false !== strpos( $a4l_menu, "array( EventsPage::class, 'load_screen' )" ) && false !== strpos( $a4l_menu, "'set_screen_option_' . TicketsPage::PER_PAGE_OPTION" ) && false !== strpos( $a4l_menu, "'set_screen_option_' . EventsPage::PER_PAGE_OPTION" ) );

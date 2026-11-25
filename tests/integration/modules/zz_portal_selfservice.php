<?php
/**
 * Buyer portal and self-service: admin "Käufer-E-Mail ändern" (audited, old links invalid), public
 * "Tickets/Bestelllink anfordern" (same answer for every address, rate limited), the page for an expired or
 * invalid order link, the calendar download in the portal, site date formats in buyer-facing output and the
 * informal address in customer texts.
 */
// phpcs:ignoreFile
use PassCaptain\Admin\OrdersPage;
use PassCaptain\OrderLinkRequest;
use PassCaptain\Orders;
use PassCaptain\PortalSelfService;

if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $a = -1 ) { return 'n'; } }
if ( ! function_exists( 'wp_verify_nonce' ) ) { function wp_verify_nonce( $n, $a = -1 ) { return 'n' === $n ? 1 : false; } }
global $wpdb;
$p = $wpdb->prefix;
echo "Portal self-service: buyer e-mail change, order link request, invalid link page, calendar\n";
$ps_reset_limits = static function () use ( $wpdb, $p ) { $GLOBALS['wpcap_test_transients'] = array(); $wpdb->query( "DELETE FROM {$p}wpcap_rate_limits" ); };
$ps_reset_limits();
$ps_type = make_type( 10, 10, 0 );
$ps_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$ps_type" );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_occurrences SET starts_at=%s, ends_at=%s WHERE occurrence_id=%d", gmdate( 'Y-m-d H:i:s', time() + 20 * DAY_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', time() + 20 * DAY_IN_SECONDS + 7200 ), $ps_occ ) );
$ps_mail = 'ps-buyer-' . uniqid() . '@example.com';
$ps_o = Orders::create( array( 'email' => $ps_mail, 'items' => array( array( 'ticket_type_id' => $ps_type, 'quantity' => 2 ) ), 'idempotency_key' => 'ps-' . uniqid() ) );
check( 'PS: fixture order (free, paid)', ! is_wp_error( $ps_o ) );
$ps_oid = (int) $ps_o['order_id'];
$ps_row = static function () use ( $wpdb, $p, $ps_oid ) { return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_orders WHERE order_id=%d", $ps_oid ) ); };
$ps_tokens = static function ( $before ) {
	$out = array();
	foreach ( array_slice( $GLOBALS['wpcap_test_mails'], $before ) as $m ) {
		if ( preg_match_all( '/wpcap_access=([a-f0-9]{64})/', (string) $m['body'], $mm ) ) { $out = array_merge( $out, $mm[1] ); }
	}
	return array_values( array_unique( $out ) );
};
$ps_current = bin2hex( random_bytes( 32 ) );
$wpdb->update( "{$p}wpcap_orders", array( 'access_token_hash' => hash( 'sha256', $ps_current ), 'access_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ) ), array( 'order_id' => $ps_oid ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_tickets SET attendee_email=%s WHERE order_id=%d", $ps_mail, $ps_oid ) );

/* ---------------------------------------------------------------- Order link request (public form) */

$before = count( $GLOBALS['wpcap_test_mails'] );
check( 'PS: invalid address → input error', is_wp_error( OrderLinkRequest::request( 'no-address' ) ) && 'wpcap_order_link_email' === OrderLinkRequest::request( 'no-address' )->get_error_code() );
$ps_r1 = OrderLinkRequest::request( strtoupper( $ps_mail ) );
$ps_new = $ps_tokens( $before );
$ps_mails = array_slice( $GLOBALS['wpcap_test_mails'], $before );
check( 'PS: an address with orders gets one mail with a fresh link per order', true === $ps_r1 && 1 === count( $ps_mails ) && 1 === count( $ps_new ) && false !== strpos( (string) $ps_mails[0]['to'], $ps_mail ) );
check( 'PS: the mail lists the short order number', false !== strpos( (string) $ps_mails[0]['body'], PassCaptain\Frontend\Format::order_reference( $ps_row()->public_id ) ) );
check( 'PS: the new link is pending; the current link keeps working (nobody is locked out)', Orders::access_matches( $ps_row(), $ps_current ) && hash( 'sha256', $ps_new[0] ) === (string) $ps_row()->access_pending_hash );
$before = count( $GLOBALS['wpcap_test_mails'] );
$ps_r2 = OrderLinkRequest::request( 'nobody-' . uniqid() . '@example.com' );
check( 'PS: an unknown address gets the same answer and no mail (no enumeration)', true === $ps_r2 && count( $GLOBALS['wpcap_test_mails'] ) === $before );
$ps_page_known = OrderLinkRequest::page( array( '_wpnonce' => 'n', 'wpcap_order_link_email' => $ps_mail ) );
$ps_page_unknown = OrderLinkRequest::page( array( '_wpnonce' => 'n', 'wpcap_order_link_email' => 'nobody2-' . uniqid() . '@example.com' ) );
check( 'PS: the page shows the identical neutral notice for known and unknown addresses', false !== strpos( $ps_page_known, esc_html( OrderLinkRequest::generic_message() ) ) && $ps_page_known === $ps_page_unknown );
check( 'PS: honeypot or missing nonce → not processed', false === strpos( OrderLinkRequest::page( array( '_wpnonce' => 'x', 'wpcap_order_link_email' => $ps_mail ) ), esc_html( OrderLinkRequest::generic_message() ) ) && false === strpos( OrderLinkRequest::page( array( '_wpnonce' => 'n', 'website' => 'spam', 'wpcap_order_link_email' => $ps_mail ) ), esc_html( OrderLinkRequest::generic_message() ) ) );
// Per address at most 3 mails a day, silently.
$ps_reset_limits();
$before = count( $GLOBALS['wpcap_test_mails'] );
$ps_answers = array();
for ( $i = 0; $i < 4; $i++ ) { $ps_answers[] = OrderLinkRequest::request( $ps_mail ); }
check( 'PS: per address at most 3 mails a day; the 4th request gets the same answer without mail', 3 === count( $GLOBALS['wpcap_test_mails'] ) - $before && array( true, true, true, true ) === $ps_answers );
$ps_reset_limits();
$ps_limited = null;
for ( $i = 0; $i < 7; $i++ ) { $ps_limited = OrderLinkRequest::request( 'flood-' . $i . '@example.com' ); }
check( 'PS: per client the form is rate limited (429)', is_wp_error( $ps_limited ) && 'wpcap_rate_limited' === $ps_limited->get_error_code() );
$ps_reset_limits();
check( 'PS: REST route /orders/link-request is registered publicly', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/OrderLinkRequest.php' ), "'/orders/link-request'" ) );
check( 'PS: mail type order_links is registered', null !== PassCaptain\Mail\Mailer::type( 'order_links' ) );

/* ---------------------------------------------------------------- Invalid link page */

$ps_invalid = OrderLinkRequest::invalid_link_page();
check( 'PS: the invalid-link page explains and carries the request form (no order data)', false !== strpos( $ps_invalid, 'name="wpcap_order_link_email"' ) && false !== strpos( $ps_invalid, 'abgelaufen' ) && false === strpos( $ps_invalid, $ps_mail ) );
$ps_portal_src = (string) file_get_contents( WPCAP_PATH . 'src/OrderPortal.php' );
check( 'PS: the portal answers an unknown/expired link with that page instead of an empty 404', 2 === substr_count( $ps_portal_src, 'self::invalid_link();' ) && false !== strpos( $ps_portal_src, 'OrderLinkRequest::invalid_link_page()' ) && false === strpos( $ps_portal_src, "status_header( 404 ); \\PassCaptain\\Http::end(); }" ) );

/* ---------------------------------------------------------------- Admin: change buyer e-mail */

$ps_pending = $ps_new[0];
$ps_new_mail = 'ps-new-' . uniqid() . '@example.com';
check( 'PS: change needs a valid, different address and a reason', is_wp_error( OrdersPage::change_buyer_email( array( 'order_id' => $ps_oid, 'new_email' => 'x', 'reason' => 'Tippfehler' ) ) )
	&& 'wpcap_order_email_unchanged' === OrdersPage::change_buyer_email( array( 'order_id' => $ps_oid, 'new_email' => strtoupper( $ps_mail ), 'reason' => 'Tippfehler' ) )->get_error_code()
	&& 'wpcap_order_email_reason' === OrdersPage::change_buyer_email( array( 'order_id' => $ps_oid, 'new_email' => $ps_new_mail, 'reason' => '' ) )->get_error_code() );
$before = count( $GLOBALS['wpcap_test_mails'] );
$ps_change = OrdersPage::change_buyer_email( array( 'order_id' => $ps_oid, 'new_email' => $ps_new_mail, 'reason' => 'Tippfehler beim Kauf', 'send_link' => '1' ) );
$ps_after = $ps_tokens( $before );
$ps_last = end( $GLOBALS['wpcap_test_mails'] );
check( 'PS: the buyer address is changed', ! is_wp_error( $ps_change ) && $ps_new_mail === (string) $ps_row()->customer_email && true === $ps_change['sent'] );
check( 'PS: every old link (current and pending) is invalid at once', ! Orders::access_matches( $ps_row(), $ps_current ) && ! Orders::access_matches( $ps_row(), $ps_pending ) && '' === (string) $ps_row()->access_pending_hash );
check( 'PS: the new link goes to the new address and opens the portal', 1 === count( $ps_after ) && false !== strpos( (string) $ps_last['to'], $ps_new_mail ) && Orders::access_matches( $ps_row(), $ps_after[0] ) );
check( 'PS: tickets held under the old address follow the new one', 2 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE order_id=%d AND attendee_email=%s", $ps_oid, $ps_new_mail ) ) );
$ps_audit = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_audit_logs WHERE action='order_email_changed' AND object_id=%d ORDER BY 1 DESC LIMIT 1", $ps_oid ) );
check( 'PS: the change is audited with masked addresses and the reason', $ps_audit && false !== strpos( (string) $ps_audit->details, 'Tippfehler beim Kauf' ) && false === strpos( (string) $ps_audit->details, $ps_mail ) && false === strpos( (string) $ps_audit->details, $ps_new_mail ) );
$wpdb->update( "{$p}wpcap_orders", array( 'payment_provider' => 'woocommerce' ), array( 'order_id' => $ps_oid ) );
check( 'PS: WooCommerce orders are refused', 'wpcap_order_email_woocommerce' === OrdersPage::change_buyer_email( array( 'order_id' => $ps_oid, 'new_email' => 'ps-woo@example.com', 'reason' => 'Test' ) )->get_error_code() );
$wpdb->update( "{$p}wpcap_orders", array( 'payment_provider' => 'free' ), array( 'order_id' => $ps_oid ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_orders' );
check( 'PS: without wpcap_manage_orders the change is refused', is_wp_error( OrdersPage::change_buyer_email( array( 'order_id' => $ps_oid, 'new_email' => 'ps-x@example.com', 'reason' => 'Test' ) ) ) && $ps_new_mail === (string) $ps_row()->customer_email );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'PS: admin action is registered (capability before nonce)', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Admin/Menu.php' ), "'wpcap_change_buyer_email' => array( OrdersPage::class, 'handle_change_buyer_email' )" ) && 1 === preg_match( '/function handle_change_buyer_email\(\) \{\s*Forms::require_any_cap/', (string) file_get_contents( WPCAP_PATH . 'src/Admin/OrdersPage.php' ) ) );

/* ---------------------------------------------------------------- Calendar download in the portal */

$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_events e INNER JOIN {$p}wpcap_occurrences o ON o.event_id=e.event_id SET e.status='published' WHERE o.occurrence_id=%d", $ps_occ ) );
$ps_ics = PortalSelfService::ics( $ps_row()->public_id, $ps_after[0] );
check( 'PS: the portal offers the order\'s dates as .ics (one entry per date, stable UID)', is_array( $ps_ics ) && 1 === substr_count( $ps_ics['content'], 'BEGIN:VEVENT' ) && false !== strpos( $ps_ics['content'], 'UID:wpcap-occurrence-' . $ps_occ . '@' ) && str_ends_with( $ps_ics['filename'], '.ics' ) );
check( 'PS: the .ics contains no buyer data', false === strpos( $ps_ics['content'], $ps_new_mail ) );
check( 'PS: wrong token → denied', is_wp_error( PortalSelfService::ics( $ps_row()->public_id, str_repeat( 'a', 64 ) ) ) );
$ps_card = PortalSelfService::calendar_html( $ps_row(), $ps_after[0] );
check( 'PS: the portal card posts the calendar form', false !== strpos( $ps_card, 'name="wpcap_portal_ics"' ) && false !== strpos( $ps_card, '(.ics)' ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_tickets SET status='cancelled' WHERE order_id=%d", $ps_oid ) );
check( 'PS: without usable tickets there is no calendar card', '' === PortalSelfService::calendar_html( $ps_row(), $ps_after[0] ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_tickets SET status='valid' WHERE order_id=%d", $ps_oid ) );

/* ---------------------------------------------------------------- Date formats and informal address */

$GLOBALS['wpcap_test_options']['date_format'] = 'Y/m/d';
$GLOBALS['wpcap_test_options']['time_format'] = 'g:i A';
$ps_deadline = PassCaptain\RescheduleRefunds::deadline_label( gmdate( 'Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS ), 'UTC' );
unset( $GLOBALS['wpcap_test_options']['date_format'], $GLOBALS['wpcap_test_options']['time_format'] );
check( 'PS: buyer-facing deadlines follow the site date/time format', 1 === preg_match( '#^\d{4}/\d{2}/\d{2} \d{1,2}:\d{2} (AM|PM) \(UTC\)$#', $ps_deadline ) );
$ps_fixed = array();
foreach ( array( 'src/ServiceBookings.php', 'src/Services/Portal.php', 'src/ServiceWaitlist.php', 'src/RescheduleRefunds.php', 'src/Modules/Calendar.php' ) as $ps_file ) {
	if ( preg_match( "/'d\\.m\\.Y( H:i)?'/", (string) file_get_contents( WPCAP_PATH . $ps_file ) ) ) { $ps_fixed[] = $ps_file; }
}
check( 'PS: no fixed d.m.Y formats left in buyer-facing service/portal/calendar output' . ( $ps_fixed ? ' – ' . implode( ', ', $ps_fixed ) : '' ), ! $ps_fixed );
// Formal address: "Ihnen/Ihre/Ihr…" or "Sie" after a lower-case word (verb/conjunction) in translatable strings.
$ps_formal = array();
$ps_it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WPCAP_PATH . 'src', FilesystemIterator::SKIP_DOTS ) );
foreach ( $ps_it as $ps_f ) {
	if ( 'php' !== $ps_f->getExtension() ) { continue; }
	foreach ( file( $ps_f->getPathname() ) as $ps_n => $ps_line ) {
		if ( ! preg_match_all( "/(?:__|_e|_x|_n|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $ps_line, $ps_m ) ) { continue; }
		foreach ( $ps_m[1] as $ps_s ) {
			if ( preg_match( '/\b(Ihnen|Ihre[mnrs]?|Ihr)\b|[a-zäöüß,] Sie\b(?! (wird|werden|wurde|wurden|ist|sind|bleibt|bleiben|enthalten|erhalten|können|zeigt|gilt|gelten|wartet)\b)/u', $ps_s ) ) { $ps_formal[] = str_replace( WPCAP_PATH, '', $ps_f->getPathname() ) . ':' . ( $ps_n + 1 ); }
		}
	}
}
check( 'PS: no formal "Sie/Ihnen/Ihre" address left in UI and customer texts' . ( $ps_formal ? ' – ' . implode( ', ', array_slice( $ps_formal, 0, 10 ) ) : '' ), ! $ps_formal );

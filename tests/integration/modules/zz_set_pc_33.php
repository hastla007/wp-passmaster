<?php
/**
 * SET-PC-33/-34/-36/-38/-39: ticket PDF logo and footer (per language, per event, filters keep
 * precedence), reminders per event, first weekday and document formats, operator rate limits with
 * safe lower bounds, box office shift rules. Defaults keep the previous behaviour.
 * Included from run.php (check(), make_type()).
 */

// phpcs:ignoreFile

use PassCaptain\BoxOffice\Cash;
use PassCaptain\BoxOffice\Registers;
use PassCaptain\BoxOffice\Shifts;
use PassCaptain\EventOverrides;
use PassCaptain\Modules\Reminders;
use PassCaptain\RateLimit;
use PassCaptain\Settings;
use PassCaptain\SettingsSchema;
use PassCaptain\TicketPdf;

global $wpdb;
$sp33_p = $wpdb->prefix;
$sp33_saved = get_option( Settings::OPTION, array() );
$sp33_overrides = get_option( EventOverrides::OPTION, array() );
$sp33_no_privacy = static function () { return ''; };
add_filter( 'privacy_policy_url', $sp33_no_privacy );
Settings::update( array( 'imprint_url' => '', 'privacy_url' => '' ) );
Settings::flush();

echo "SET-PC-33: ticket PDF logo and footer\n";
$sp33_type = make_type( 20, 10, 0 );
$sp33_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$sp33_p}wpcap_ticket_types WHERE ticket_type_id=$sp33_type" );
$sp33_event = (int) $wpdb->get_var( "SELECT event_id FROM {$sp33_p}wpcap_occurrences WHERE occurrence_id=$sp33_occ" );
$sp33_ticket = array( 'event' => 'Konzert', 'ticket' => 'Standard', 'when' => 'x', 'occurrence_id' => $sp33_occ, 'token' => 'T', 'starts_at' => '2027-05-01 18:00:00', 'timezone' => 'UTC' );
$sp33_meta = array( 'footer' => TicketPdf::default_footer(), 'lang' => 'de_DE' );
$sp33_def = Settings::defaults();
check( 'SET-PC-33: defaults – no logo, built-in footer, legal links on', array( 'logo' => 0, 'footer' => '', 'translations' => array(), 'legal_links' => 1 ) === $sp33_def['ticket_pdf'] );
check( 'SET-PC-33: without settings the footer is the built-in text, as before', TicketPdf::default_footer() === TicketPdf::values( $sp33_ticket, $sp33_meta )['_footer'] );
check( 'SET-PC-33: without settings the ticket layout gets no logo', TicketPdf::with_logo( "12|{event}", $sp33_event ) === "12|{event}" );

Settings::update( array( 'ticket_pdf' => array( 'logo' => 0, 'footer' => 'Einlass nur mit Ausweis.', 'translations' => array( 'en_US' => 'ID required.' ), 'legal_links' => 1 ) ) );
check( 'SET-PC-33: the global footer replaces the built-in text', 'Einlass nur mit Ausweis.' === TicketPdf::values( $sp33_ticket, $sp33_meta )['_footer'] );
check( 'SET-PC-33: footer per language', 'ID required.' === TicketPdf::values( $sp33_ticket, array( 'lang' => 'en_US' ) + $sp33_meta )['_footer'] );
check( 'SET-PC-33: an explicit footer of the ticket entry still wins', 'Eigene' === TicketPdf::values( array( 'footer' => 'Eigene' ) + $sp33_ticket, $sp33_meta )['_footer'] );

$sp33_ev = EventOverrides::save( $sp33_event, 'ticket_pdf', array( 'footer' => 'Festival-Bedingungen', 'translations' => array( 'en_US' => 'Festival terms' ) ) );
check( 'SET-PC-33: event override of the footer (also per language)', true === $sp33_ev && 'Festival-Bedingungen' === TicketPdf::values( $sp33_ticket, $sp33_meta )['_footer'] && 'Festival terms' === TicketPdf::values( $sp33_ticket, array( 'lang' => 'en_US' ) + $sp33_meta )['_footer'] );
check( 'SET-PC-33: other events keep the global footer', 'Einlass nur mit Ausweis.' === TicketPdf::values( array( 'occurrence_id' => 0 ) + $sp33_ticket, $sp33_meta )['_footer'] );
$sp33_filter = static function ( $footer, $ticket ) { return 'Filter: ' . $footer . ' #' . (int) ( $ticket['event_id'] ?? 0 ); };
add_filter( 'wpcap_ticket_pdf_footer', $sp33_filter, 10, 2 );
check( 'SET-PC-33: the filter wpcap_ticket_pdf_footer keeps precedence and receives the configured footer', 'Filter: Festival-Bedingungen #' . $sp33_event === TicketPdf::values( $sp33_ticket, $sp33_meta )['_footer'] );
remove_filter( 'wpcap_ticket_pdf_footer', $sp33_filter, 10 );
check( 'SET-PC-33: an unknown event is refused', is_wp_error( EventOverrides::save( 999999999, 'ticket_pdf', array( 'footer' => 'x' ) ) ) && is_wp_error( EventOverrides::save( $sp33_event, 'nope', array() ) ) );

// Logo: media id through wpcap_print_logo_file (filter stays), template logo wins.
$sp33_png = WPCAP_PATH . 'assets/offline-scanner/icon-192.png';
$sp33_logo_file = static function ( $file, $id ) use ( $sp33_png ) { return 4711 === (int) $id ? $sp33_png : $file; };
add_filter( 'wpcap_print_logo_file', $sp33_logo_file, 10, 2 );
PassCaptain\Printing\Templates::reset_logos();
$sp33_bad = PassCaptain\Printing\Page::save_ticket_pdf( array( 'event_id' => 0, 'logo' => '4712', 'footer' => '' ) );
check( 'SET-PC-33: a logo that is no JPEG/PNG is reported, not stored', is_wp_error( $sp33_bad ) && 'Einlass nur mit Ausweis.' === ( Settings::get( 'ticket_pdf' )['footer'] ?? '' ) );
$sp33_neg = PassCaptain\Printing\Page::save_ticket_pdf( array( 'event_id' => 0, 'logo' => '-3', 'footer' => '' ) );
check( 'SET-PC-33: a negative logo id is reported by the register', is_wp_error( $sp33_neg ) );
$sp33_ok = PassCaptain\Printing\Page::save_ticket_pdf( array( 'event_id' => 0, 'logo' => '4711', 'footer' => TicketPdf::default_footer(), 'translations' => array( 'en_US' => '' ), 'legal_links' => '1' ) );
check( 'SET-PC-33: global logo saved; the unchanged built-in footer is stored as empty', ! is_wp_error( $sp33_ok ) && 4711 === Settings::get( 'ticket_pdf' )['logo'] && '' === Settings::get( 'ticket_pdf' )['footer'] );
check( 'SET-PC-33: the logo is added to a layout without its own logo, a layout logo wins', 0 === strpos( TicketPdf::with_logo( "12|{event}", 0 ), '@logo|4711' ) && "@logo|99\n12|{event}" === TicketPdf::with_logo( "@logo|99\n12|{event}", 0 ) );
$sp33_ev2 = PassCaptain\Printing\Page::save_ticket_pdf( array( 'event_id' => $sp33_event, 'logo' => '', 'footer' => 'Nur Event' ) );
check( 'SET-PC-33: event form – empty logo means the global logo, own footer stored', ! is_wp_error( $sp33_ev2 ) && array( 'footer' => 'Nur Event' ) === EventOverrides::get( $sp33_event, 'ticket_pdf' ) && 4711 === TicketPdf::settings_for_event( $sp33_event )['logo'] );
$sp33_pdf = TicketPdf::render( array( $sp33_ticket ), $sp33_meta );
remove_filter( 'wpcap_print_logo_file', $sp33_logo_file, 10 );
PassCaptain\Printing\Templates::reset_logos();
$sp33_pdf_plain = TicketPdf::render( array( $sp33_ticket ), $sp33_meta ); // Logo file no longer resolvable: no logo.
check( 'SET-PC-33: the ticket PDF renders with the configured logo', 0 === strpos( $sp33_pdf, '%PDF' ) && strlen( $sp33_pdf ) > strlen( $sp33_pdf_plain ) + 500 );

// Imprint and privacy links (SET-PC-19) in the PDF footer and on the ticket page.
Settings::update( array( 'imprint_url' => 'https://example.com/impressum' ) );
$sp33_f = TicketPdf::values( $sp33_ticket, $sp33_meta )['_footer'];
check( 'SET-PC-33: the imprint link is the last footer line of the PDF', "Nur Event\nImpressum: https://example.com/impressum" === $sp33_f && false !== strpos( TicketPdf::values( array( 'occurrence_id' => 0 ) + $sp33_ticket, $sp33_meta )['_footer'], "\nImpressum:" ) );
check( 'SET-PC-33: the ticket page shows the legal links below the ticket', false !== strpos( PassCaptain\TicketPage::legal_footer( $sp33_occ ), 'https://example.com/impressum' ) );
$sp33_cfg = Settings::get( 'ticket_pdf' );
Settings::update( array( 'ticket_pdf' => array( 'legal_links' => 0 ) + $sp33_cfg ) );
check( 'SET-PC-33: legal links can be switched off', '' === PassCaptain\TicketPage::legal_footer( $sp33_occ ) && "Nur Event" === TicketPdf::values( $sp33_ticket, $sp33_meta )['_footer'] );
Settings::update( array( 'imprint_url' => '' ) );
check( 'SET-PC-33: the ticket_pdf group is described in the register with limits', 'group' === SettingsSchema::get( 'ticket_pdf' )['type'] && isset( SettingsSchema::get( 'ticket_pdf' )['fields']['logo']['min'] ) );

echo "SET-PC-34: reminders per event\n";
Settings::update( array( 'reminders' => array( 'enabled' => 0, 'offsets' => array( 24 ) ) + Reminders::settings_defaults()['reminders'] ) );
check( 'SET-PC-34: without an override the event uses the global configuration', Reminders::config() === Reminders::config_for_event( Reminders::config(), $sp33_event ) );
$sp33_r_bad = Reminders::save_event( array( 'event_id' => $sp33_event, 'enabled' => '1', 'offsets' => '2, 721' ) );
check( 'SET-PC-34: invalid offsets are reported by the register, nothing stored', is_wp_error( $sp33_r_bad ) && array() === EventOverrides::get( $sp33_event, 'reminders' ) );
check( 'SET-PC-34: an invalid on/off value is reported', is_wp_error( Reminders::save_event( array( 'event_id' => $sp33_event, 'enabled' => 'yes' ) ) ) );
// The occurrence starts in 10 hours; one paid (free) order.
$wpdb->query( $wpdb->prepare( "UPDATE {$sp33_p}wpcap_occurrences SET starts_at=%s WHERE occurrence_id=%d", gmdate( 'Y-m-d H:i:s', time() + 10 * 3600 ), $sp33_occ ) );
wpcap_test_reset_transients();
PassCaptain\Orders::create( array( 'email' => 'sp33-rem@example.com', 'items' => array( array( 'ticket_type_id' => $sp33_type, 'quantity' => 1 ) ), 'idempotency_key' => 'sp33-rem-' . uniqid() ) );
$sp33_rem_rows = static function () use ( $wpdb, $sp33_occ ) { return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_message_queue WHERE template_key='wpcap_reminder' AND payload LIKE %s", '%"occurrence_id":' . (int) $sp33_occ . ',%' ) ); };
Reminders::schedule_due();
check( 'SET-PC-34: globally off and no override – nothing is queued', 0 === $sp33_rem_rows() );
$sp33_r_ok = Reminders::save_event( array( 'event_id' => $sp33_event, 'enabled' => '1', 'offsets' => '12, 2' ) );
check( 'SET-PC-34: override stored (switched on, own offsets ascending)', ! is_wp_error( $sp33_r_ok ) && array( 'enabled' => 1, 'offsets' => array( 2, 12 ) ) === EventOverrides::get( $sp33_event, 'reminders' ) );
$sp33_eff = Reminders::config_for_event( Reminders::config(), $sp33_event );
check( 'SET-PC-34: the event switches reminders on with its own offsets', 1 === $sp33_eff['enabled'] && array( 2, 12 ) === $sp33_eff['offsets'] );
Reminders::schedule_due();
$sp33_payload = json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT payload FROM {$sp33_p}wpcap_message_queue WHERE template_key='wpcap_reminder' AND payload LIKE %s ORDER BY message_id DESC LIMIT 1", '%"occurrence_id":' . $sp33_occ . ',%' ) ), true );
check( 'SET-PC-34: the event override queues the reminder at its own stage (12 h)', 1 === $sp33_rem_rows() && 12 === (int) ( $sp33_payload['hours'] ?? 0 ) );
Reminders::save_event( array( 'event_id' => $sp33_event, 'enabled' => '0' ) );
check( 'SET-PC-34: switched off for the event – queued reminders are no longer valid', false === Reminders::still_valid( 'sp33-rem@example.com', $sp33_payload ) );
Reminders::save_event( array( 'event_id' => $sp33_event, 'enabled' => '', 'offsets' => '' ) );
check( 'SET-PC-34: "as globally" and empty offsets remove the override', array() === EventOverrides::get( $sp33_event, 'reminders' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$sp33_p}wpcap_message_queue WHERE template_key='wpcap_reminder' AND payload LIKE %s", '%"occurrence_id":' . $sp33_occ . ',%' ) );

echo "SET-PC-36: first weekday and document formats\n";
$sp33_month = gmdate( 'Y-m', strtotime( '+2 months' ) );
check( 'SET-PC-36: default first weekday is Monday (as before)', 1 === PassCaptain\Modules\Calendar::first_weekday() && 1 === $sp33_def['calendar']['first_weekday'] );
$sp33_html = PassCaptain\Modules\Calendar::render_month( $sp33_month );
check( 'SET-PC-36: the month view starts with Monday by default', (bool) preg_match( '#<thead><tr><th scope="col"><abbr title="Montag">#', $sp33_html ) );
check( 'SET-PC-36: a weekday outside 0–6 is reported by the register', is_wp_error( PassCaptain\Modules\Calendar::save( array( 'first_weekday' => '7' ) ) ) );
$sp33_cal = PassCaptain\Modules\Calendar::save( array( 'first_weekday' => '0', 'attach_ics' => '1' ) );
$sp33_html = PassCaptain\Modules\Calendar::render_month( $sp33_month );
$sp33_first = new DateTime( $sp33_month . '-01' );
$sp33_blank = (int) $sp33_first->format( 'w' ); // Sunday-first: empty cells before day 1.
check( 'SET-PC-36: first weekday Sunday – columns start with Sunday and day 1 sits in its column', ! is_wp_error( $sp33_cal ) && (bool) preg_match( '#<thead><tr><th scope="col"><abbr title="Sonntag">#', $sp33_html ) && substr_count( strstr( $sp33_html, '<tbody>' ), 'wpcap-cal-empty' ) >= $sp33_blank && preg_match( '#<tbody><tr>((?:<td class="wpcap-cal-empty" aria-hidden="true"></td>)*)#', $sp33_html, $sp33_m ) && $sp33_blank === substr_count( $sp33_m[1], '<td' ) );
check( 'SET-PC-36: default document formats are empty (WordPress setting, as before)', '' === $sp33_def['document_date_format'] && '' === $sp33_def['document_time_format'] && 'H:i' === PassCaptain\Frontend\Format::ticket_time_format() );
Settings::update( array( 'document_date_format' => 'Y-m-d', 'document_time_format' => 'g:i a' ) );
check( 'SET-PC-36: the document formats are used in the site language', 'Y-m-d' === PassCaptain\Frontend\Format::date_format() && 'g:i a' === PassCaptain\Frontend\Format::time_format() && 'g:i a' === PassCaptain\Frontend\Format::ticket_time_format() );
check( 'SET-PC-36: {time} on the ticket PDF follows the document time format', '6:00 pm' === TicketPdf::values( $sp33_ticket, $sp33_meta )['time'] );
$sp33_df = static function () { return 'd/m/Y'; };
add_filter( 'wpcap_date_format', $sp33_df );
check( 'SET-PC-36: the filter wpcap_date_format keeps precedence', 'd/m/Y' === PassCaptain\Frontend\Format::date_format() );
remove_filter( 'wpcap_date_format', $sp33_df );
check( 'SET-PC-36: an invalid format is reported', is_wp_error( PassCaptain\Admin\SettingsPage::sanitize_date_format( 'abc<b>' ) ) && is_wp_error( PassCaptain\Admin\SettingsPage::sanitize_date_format( str_repeat( 'd', 41 ) ) ) && is_wp_error( PassCaptain\Admin\SettingsPage::sanitize_date_format( '---' ) ) && 'j. F Y' === PassCaptain\Admin\SettingsPage::sanitize_date_format( ' j. F Y ' ) );
$sp33_page = PassCaptain\Admin\SettingsPage::save( array( 'wpcap_tab' => 'general', 'document_date_format' => '---' ) );
check( 'SET-PC-36: the settings screen reports an invalid date format and keeps the stored one', ! is_wp_error( $sp33_page ) && 1 === count( array_filter( $sp33_page['errors'], static fn( $e ) => false !== strpos( $e, 'Datums' ) ) ) && 'Y-m-d' === Settings::get( 'document_date_format' ) );
Settings::update( array( 'document_date_format' => '', 'document_time_format' => '' ) );

echo "SET-PC-38: rate limits with safe lower bounds\n";
$sp33_rates = array( 'rate_checkout_ip_per_hour' => 30, 'rate_checkout_email_per_hour' => 8, 'rate_checkout_unpaid_per_network' => 10, 'rate_waitlist_ip_per_hour' => 60, 'rate_waitlist_email_per_hour' => 5, 'rate_portal_resend_per_hour' => 3, 'rate_portal_assign_per_hour' => 20, 'rate_portal_pdf_per_hour' => 20 );
check( 'SET-PC-38: defaults are the former fixed values', $sp33_rates === array_intersect_key( $sp33_def, $sp33_rates ) && PassCaptain\Sales\CheckoutThrottle::EMAIL_LIMIT === RateLimit::configured( 'rate_checkout_email_per_hour' ) && PassCaptain\Waitlists::EMAIL_HOURLY_LIMIT === PassCaptain\Waitlists::email_limit_per_hour() && 60 === PassCaptain\Waitlists::ip_limit_per_hour() );
$sp33_min_ok = true;
foreach ( array_keys( $sp33_rates ) as $sp33_key ) {
	$sp33_entry = SettingsSchema::get( $sp33_key );
	if ( ! $sp33_entry['described'] || $sp33_entry['min'] < 1 || ! is_wp_error( SettingsSchema::validate( $sp33_key, $sp33_entry['min'] - 1 ) ) || $sp33_entry['min'] !== SettingsSchema::validate( $sp33_key, $sp33_entry['min'] ) ) { $sp33_min_ok = false; echo "    $sp33_key\n"; }
}
check( 'SET-PC-38: every limit has a lower bound; a value below it is an error', $sp33_min_ok && 10 === SettingsSchema::get( 'rate_checkout_ip_per_hour' )['min'] );
$sp33_page = PassCaptain\Admin\SettingsPage::save( array( 'wpcap_tab' => 'advanced', 'rate_checkout_ip_per_hour' => '2', 'rate_portal_resend_per_hour' => '5' ) );
check( 'SET-PC-38: the settings screen reports a value below the lower bound and keeps the stored one', ! is_wp_error( $sp33_page ) && 1 === count( array_filter( $sp33_page['errors'], static fn( $e ) => false !== strpos( $e, 'Checkout' ) ) ) && 30 === RateLimit::configured( 'rate_checkout_ip_per_hour' ) && 5 === RateLimit::configured( 'rate_portal_resend_per_hour' ) );
Settings::update( array( 'rate_waitlist_ip_per_hour' => 120, 'rate_waitlist_email_per_hour' => 3 ) );
check( 'SET-PC-38: the setting changes the waitlist limits', 120 === PassCaptain\Waitlists::ip_limit_per_hour() && 3 === PassCaptain\Waitlists::email_limit_per_hour() );
$sp33_wl = static function () { return 7; };
add_filter( 'wp_pass_captain_waitlist_ip_limit_v1', $sp33_wl );
check( 'SET-PC-38: the filter keeps precedence over the setting', 7 === PassCaptain\Waitlists::ip_limit_per_hour() );
remove_filter( 'wp_pass_captain_waitlist_ip_limit_v1', $sp33_wl );
Settings::update( array( 'rate_checkout_ip_per_hour' => 1 ) ); // Below the bound without the screen (direct update).
check( 'SET-PC-38: a stored value below the lower bound is raised to it at runtime', 10 === RateLimit::configured( 'rate_checkout_ip_per_hour' ) );
check( 'SET-PC-38: the code reads the settings (checkout, portal)', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Sales/CheckoutThrottle.php' ), "RateLimit::configured( 'rate_checkout_email_per_hour' )" ) && false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Rest/Controller.php' ), "apply_filters( 'wpcap_portal_resend_limit', \\PassCaptain\\RateLimit::configured( 'rate_portal_resend_per_hour' ) )" ) );

echo "SET-PC-39: box office shift rules\n";
check( 'SET-PC-39: defaults – no tolerance, amount or denominations, screen language, blind count preselected', 0 === $sp33_def['boxoffice_difference_tolerance_minor'] && 'amount' === $sp33_def['boxoffice_count_mode'] && '' === $sp33_def['boxoffice_receipt_locale'] && 1 === $sp33_def['boxoffice_blind_default'] );
check( 'SET-PC-39: by default every difference needs a reason and a plain amount suffices', Cash::needs_reason( 1 ) && ! Cash::needs_reason( 0 ) && ! Cash::needs_reason( null ) && true === Cash::check_mode( 5000, array() ) );
$sp33_rules = PassCaptain\BoxOffice\Page::check_shift_rules( array( 'boxoffice_difference_tolerance_minor' => 'abc', 'boxoffice_count_mode' => 'all' ) );
check( 'SET-PC-39: invalid tolerance and count mode are reported together', is_wp_error( $sp33_rules ) && 2 === count( $sp33_rules->get_error_data()['messages'] ?? array() ) );
check( 'SET-PC-39: an invalid receipt language is reported', is_wp_error( PassCaptain\BoxOffice\Page::check_shift_rules( array( 'boxoffice_receipt_locale' => 'Deutsch!' ) ) ) );
$sp33_rules = PassCaptain\BoxOffice\Page::check_shift_rules( array( 'boxoffice_difference_tolerance_minor' => '0,50', 'boxoffice_count_mode' => 'denominations', 'boxoffice_receipt_locale' => 'en_US', 'boxoffice_blind_default' => '1' ) );
check( 'SET-PC-39: valid rules are returned for storing', is_array( $sp33_rules ) && 50 === $sp33_rules['boxoffice_difference_tolerance_minor'] && 'denominations' === $sp33_rules['boxoffice_count_mode'] && 'en_US' === $sp33_rules['boxoffice_receipt_locale'] && 1 === $sp33_rules['boxoffice_blind_default'] );
Settings::update( $sp33_rules );
check( 'SET-PC-39: tolerance and count duty apply', ! Cash::needs_reason( 50 ) && ! Cash::needs_reason( -50 ) && Cash::needs_reason( 51 ) && is_wp_error( Cash::check_mode( 5000, array() ) ) && true === Cash::check_mode( 0, array() ) && true === Cash::check_mode( 5000, array( 5000 => 1 ) ) );
check( 'SET-PC-39: the receipt language is used for receipts', 'en_US' === PassCaptain\BoxOffice\Service::receipt_locale() );
// A shift: the count duty refuses a plain amount, a difference within the tolerance closes without a reason.
$GLOBALS['wpcap_test_user_id'] = 93301;
$sp33_reg = Registers::save( array( 'name' => 'SET-PC-39 Kasse', 'blind_count' => 0 ) );
$sp33_plain = is_object( $sp33_reg ) ? Shifts::open( $sp33_reg->register_id, array( 'float_minor' => 1000 ) ) : null;
$sp33_shift = is_object( $sp33_reg ) ? Shifts::open( $sp33_reg->register_id, array( 'denominations' => array( 1000 => 1 ) ) ) : null;
check( 'SET-PC-39: with the count duty a float must be counted by denominations', is_wp_error( $sp33_plain ) && 'wpcap_pos_denominations_required' === $sp33_plain->get_error_code() && is_object( $sp33_shift ) );
if ( is_object( $sp33_shift ) ) {
	$sp33_count = Shifts::submit_count( $sp33_shift->session_id, array( 'denominations' => array( 1000 => 1, 20 => 2 ) ) );
	$sp33_closed = Shifts::close( $sp33_shift->session_id );
	check( 'SET-PC-39: a difference within the tolerance (0,40) closes without a reason', ! is_wp_error( $sp33_count ) && 40 === $sp33_count['cash_difference_minor'] && is_object( $sp33_closed ) && 'closed' === $sp33_closed->status );
	Settings::update( array( 'boxoffice_difference_tolerance_minor' => 0, 'boxoffice_count_mode' => 'amount' ) );
	$sp33_shift2 = Shifts::open( $sp33_reg->register_id, array( 'float_minor' => 0 ) );
	Shifts::submit_count( $sp33_shift2->session_id, array( 'cash_minor' => 40 ) );
	$sp33_refused = Shifts::close( $sp33_shift2->session_id );
	$sp33_closed2 = Shifts::close( $sp33_shift2->session_id, array( 'reason' => 'Trinkgeld' ) );
	check( 'SET-PC-39: without tolerance (default) the same difference needs a reason, as before', is_wp_error( $sp33_refused ) && 'wpcap_pos_difference_reason' === $sp33_refused->get_error_code() && is_object( $sp33_closed2 ) );
}
$GLOBALS['wpcap_test_user_id'] = 1;

// Register register: every new key is described.
$sp33_keys = array_merge( array( 'ticket_pdf', 'document_date_format', 'document_time_format', 'boxoffice_difference_tolerance_minor', 'boxoffice_count_mode', 'boxoffice_receipt_locale', 'boxoffice_blind_default' ), array_keys( $sp33_rates ) );
check( 'SET-PC-33..39: every new setting is described in the register', array() === array_intersect( $sp33_keys, SettingsSchema::undescribed() ) );

remove_filter( 'privacy_policy_url', $sp33_no_privacy );
update_option( Settings::OPTION, $sp33_saved );
update_option( EventOverrides::OPTION, $sp33_overrides );
Settings::flush();

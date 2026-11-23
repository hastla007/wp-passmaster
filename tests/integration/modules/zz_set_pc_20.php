<?php
/**
 * SET-PC-20/-21/-47: buyer rules, logs and periods as settings (defaults = former fixed values, the
 * filters keep precedence), overview "Was wird wann gelöscht", Site Health reminder for the attendee
 * retention.
 */
use PassCaptain\Admin\SettingsPage;
use PassCaptain\Clock;
use PassCaptain\Integrations\HttpJson\Endpoints;
use PassCaptain\Log;
use PassCaptain\OfflineScanner\Rules;
use PassCaptain\Operations\SiteHealth;
use PassCaptain\Orders;
use PassCaptain\Payments\ReturnNotice;
use PassCaptain\Printing\BatchJob;
use PassCaptain\Privacy\Retention;
use PassCaptain\Review\Events;
use PassCaptain\Settings;
use PassCaptain\SettingsSchema;
use PassCaptain\TicketAssignments;
use PassCaptain\Waitlists;

echo "SET-PC-20/-21/-47: business rules as settings, retention overview, Site Health\n";

$sp20_option = get_option( Settings::OPTION, array() );
$sp20_restore = static function () use ( $sp20_option ) {
	update_option( Settings::OPTION, $sp20_option );
	Settings::flush();
};
// Start from the defaults of the new keys (an earlier module may have stored other values).
$sp20_keys = array( 'order_access_days', 'payment_resume_link', 'max_attendee_name_changes', 'max_holder_changes', 'max_line_quantity', 'manual_open_orders_per_email', 'log_retention_days', 'log_min_level', 'connector_log_days', 'print_batch_keep_days', 'review_no_show_grace_hours', 'offline_default_hours', 'attendee_retention_days' );
$sp20_clean = is_array( $sp20_option ) ? array_diff_key( $sp20_option, array_flip( $sp20_keys ) ) : array();
if ( is_array( $sp20_clean['waitlist'] ?? null ) ) { unset( $sp20_clean['waitlist']['offer_cutoff_minutes'] ); }
update_option( Settings::OPTION, $sp20_clean );
Settings::flush();

$sp20_defaults = Settings::defaults();
$sp20_expected = array(
	'order_access_days' => 30, 'payment_resume_link' => 'show', 'max_attendee_name_changes' => 3, 'max_holder_changes' => 1, 'max_line_quantity' => 100, 'manual_open_orders_per_email' => 2,
	'log_retention_days' => 30, 'log_min_level' => 'info', 'connector_log_days' => 30, 'print_batch_keep_days' => 7, 'review_no_show_grace_hours' => 6, 'offline_default_hours' => 12,
);
$sp20_wrong = array();
foreach ( $sp20_expected as $sp20_key => $sp20_value ) {
	if ( ! array_key_exists( $sp20_key, $sp20_defaults ) || $sp20_value !== $sp20_defaults[ $sp20_key ] ) { $sp20_wrong[] = $sp20_key; }
	$sp20_entry = SettingsSchema::get( $sp20_key );
	if ( ! $sp20_entry || empty( $sp20_entry['described'] ) || '' === trim( (string) $sp20_entry['help'] ) ) { $sp20_wrong[] = $sp20_key . ' (register)'; }
}
check( 'SET-PC-20/-21: new settings have the former fixed values as default and are described with help text' . ( $sp20_wrong ? ' – ' . implode( ', ', $sp20_wrong ) : '' ), array() === $sp20_wrong );
check( 'SET-PC-21: waitlist lock period before the end of sales is a described group value (default 15)', 15 === ( Settings::defaults()['waitlist']['offer_cutoff_minutes'] ?? null ) && isset( SettingsSchema::get( 'waitlist' )['fields']['offer_cutoff_minutes'] ) && 15 === Waitlists::config()['offer_cutoff_minutes'] );

// Defaults = former behaviour.
$sp20_access = strtotime( Orders::access_until( 0 ) . ' UTC' ) - Clock::now();
check( 'SET-PC-20: defaults keep the former behaviour (portal 30 days, 3 name changes, 1 holder change, 100 per line, 2 open bank transfers, resume link on)',
	abs( $sp20_access - 30 * DAY_IN_SECONDS ) < 120 && 3 === TicketAssignments::max_name_changes( 1 ) && 1 === TicketAssignments::max_holder_changes( 1 )
	&& 100 === Orders::max_line_quantity() && 2 === PassCaptain\Rest\Controller::manual_open_orders_limit() && ReturnNotice::resume_enabled()
	&& ( ! function_exists( 'wp_create_nonce' ) || '' !== ( PassCaptain\PublicEvents::checkout_config()['i18n']['resumePayment'] ?? '' ) ) );
check( 'SET-PC-21: defaults keep the former behaviour (support log 30 days from info, connector log 30, print batches 7, no-show 6 h, offline package 12 h)',
	30 === Log::retention_days() && ( Log::debug_enabled() || 'info' === Log::min_level() ) && 30 === Endpoints::log_days() && 7 === BatchJob::keep_days()
	&& 6 === Events::no_show_grace_hours() && 12 === Rules::default_hours() && 12 === Rules::defaults()['max_offline_hours'] );

// Changed settings change the behaviour.
Settings::update( array(
	'order_access_days' => 90, 'payment_resume_link' => 'hide', 'max_attendee_name_changes' => 0, 'max_holder_changes' => 2, 'max_line_quantity' => 20, 'manual_open_orders_per_email' => 5,
	'log_retention_days' => 14, 'log_min_level' => 'warning', 'connector_log_days' => 60, 'print_batch_keep_days' => 3, 'review_no_show_grace_hours' => 24, 'offline_default_hours' => 24,
	'waitlist' => array_merge( Waitlists::config(), array( 'offer_cutoff_minutes' => 60 ) ),
) );
Settings::flush();
$sp20_access = strtotime( Orders::access_until( 0 ) . ' UTC' ) - Clock::now();
check( 'SET-PC-20: changed settings change the buyer rules',
	abs( $sp20_access - 90 * DAY_IN_SECONDS ) < 120 && 0 === TicketAssignments::max_name_changes( 1 ) && 2 === TicketAssignments::max_holder_changes( 1 )
	&& 20 === Orders::max_line_quantity() && 5 === PassCaptain\Rest\Controller::manual_open_orders_limit() && ! ReturnNotice::resume_enabled() );
$sp20_name = TicketAssignments::name_change( 999999, 'Anna Muster', 'Berta Beispiel' );
check( 'SET-PC-20: with 0 name changes a rename by the buyer is refused', is_wp_error( $sp20_name ) && 'wpcap_assignment_name_limit' === $sp20_name->get_error_code() );
// checkout_config() needs wp_create_nonce() (missing when this module runs in isolation).
$sp20_config = function_exists( 'wp_create_nonce' ) ? PassCaptain\PublicEvents::checkout_config() : array( 'i18n' => array( 'resumePayment' => ReturnNotice::resume_enabled() ? 'x' : '' ) );
check( 'SET-PC-20: "Zahlung fortsetzen" switched off: the checkout form gets no resume text, the return page action is gated',
	'' === ( $sp20_config['i18n']['resumePayment'] ?? null ) && false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Payments/ReturnNotice.php' ), "'resume' === \$decision && self::resume_enabled()" ) );
check( 'SET-PC-21: changed settings change logs and periods',
	14 === Log::retention_days() && ( Log::debug_enabled() || 'warning' === Log::min_level() ) && ( Log::debug_enabled() || ! Log::enabled( 'notice' ) ) && 60 === Endpoints::log_days()
	&& 3 === BatchJob::keep_days() && 24 === Events::no_show_grace_hours() && 24 === Rules::defaults()['max_offline_hours'] && 60 === Waitlists::config()['offer_cutoff_minutes'] );

// The register reports values outside the limits instead of clamping them.
check( 'SET-PC-20/-21: the register reports out-of-range values',
	is_wp_error( SettingsSchema::validate( 'max_holder_changes', 11 ) ) && is_wp_error( SettingsSchema::validate( 'order_access_days', 0 ) ) && is_wp_error( SettingsSchema::validate( 'log_min_level', 'debug' ) )
	&& 'error' === SettingsSchema::validate( 'log_min_level', 'error' ) && is_wp_error( SettingsSchema::check_group( 'waitlist', array( 'offer_cutoff_minutes' => 2 ) ) ) );
$sp20_saved = SettingsPage::save( array( 'wpcap_tab' => 'general', 'max_line_quantity' => '5000' ) );
Settings::flush();
check( 'SET-PC-20: the settings screen reports a too large quantity and keeps the stored one', ! is_wp_error( $sp20_saved ) && ! empty( $sp20_saved['errors'] ) && 20 === (int) Settings::get( 'max_line_quantity' ) );

// Filters keep precedence and receive the setting as default.
$sp20_seen = array();
$sp20_filters = array(
	'wpcap_order_access_days' => 7, 'wpcap_max_attendee_name_changes' => 9, 'wpcap_max_holder_changes' => 4, 'wpcap_max_line_quantity' => 8, 'wpcap_manual_open_orders_per_email' => 1,
	'wpcap_log_retention_days' => 2, 'wpcap_log_min_level' => 'error', 'wpcap_connector_log_days' => 5, 'wpcap_print_batch_keep_days' => 1, 'wpcap_review_no_show_grace_hours' => 0,
);
$sp20_callbacks = array();
foreach ( $sp20_filters as $sp20_hook => $sp20_value ) {
	$sp20_callbacks[ $sp20_hook ] = static function ( $default ) use ( $sp20_hook, $sp20_value, &$sp20_seen ) {
		$sp20_seen[ $sp20_hook ] = $default;
		return $sp20_value;
	};
	add_filter( $sp20_hook, $sp20_callbacks[ $sp20_hook ] );
}
$sp20_access = strtotime( Orders::access_until( 0 ) . ' UTC' ) - Clock::now();
$sp20_ok = abs( $sp20_access - 7 * DAY_IN_SECONDS ) < 120 && 9 === TicketAssignments::max_name_changes( 1 ) && 4 === TicketAssignments::max_holder_changes( 1 )
	&& 8 === Orders::max_line_quantity() && 1 === PassCaptain\Rest\Controller::manual_open_orders_limit() && 2 === Log::retention_days() && 'error' === Log::min_level()
	&& 5 === Endpoints::log_days() && 1 === BatchJob::keep_days() && 0 === Events::no_show_grace_hours();
foreach ( $sp20_callbacks as $sp20_hook => $sp20_callback ) { remove_filter( $sp20_hook, $sp20_callback ); }
check( 'SET-PC-20/-21: an existing filter keeps precedence over the setting', $sp20_ok );
check( 'SET-PC-20/-21: the filters receive the setting value as default',
	90 === ( $sp20_seen['wpcap_order_access_days'] ?? null ) && 0 === ( $sp20_seen['wpcap_max_attendee_name_changes'] ?? null ) && 2 === ( $sp20_seen['wpcap_max_holder_changes'] ?? null )
	&& 20 === ( $sp20_seen['wpcap_max_line_quantity'] ?? null ) && 5 === ( $sp20_seen['wpcap_manual_open_orders_per_email'] ?? null ) && 14 === ( $sp20_seen['wpcap_log_retention_days'] ?? null )
	&& 60 === ( $sp20_seen['wpcap_connector_log_days'] ?? null ) && 3 === ( $sp20_seen['wpcap_print_batch_keep_days'] ?? null ) && 24 === ( $sp20_seen['wpcap_review_no_show_grace_hours'] ?? null )
	&& ( Log::debug_enabled() ? 'debug' : 'warning' ) === ( $sp20_seen['wpcap_log_min_level'] ?? null ) );

// SET-PC-21: overview "Was wird wann gelöscht".
$sp20_overview = Retention::overview();
$sp20_by_setting = array();
foreach ( $sp20_overview as $sp20_row ) { if ( '' !== $sp20_row['setting'] ) { $sp20_by_setting[ $sp20_row['setting'] ] = $sp20_row['when']; } }
check( 'SET-PC-21: the overview lists the periods from the settings', count( $sp20_overview ) >= 15 && false !== strpos( $sp20_by_setting['log_retention_days'] ?? '', '14' ) && false !== strpos( $sp20_by_setting['print_batch_keep_days'] ?? '', '3' ) && false !== strpos( $sp20_by_setting['connector_log_days'] ?? '', '60' ) && isset( $sp20_by_setting['retention_order_years'], $sp20_by_setting['retention_audit_logs_days'] ) );
wp_set_current_user( 1 );
ob_start();
SettingsPage::render( 'privacy' );
$sp20_html = (string) ob_get_clean();
check( 'SET-PC-21: the privacy tab shows the overview table', false !== strpos( $sp20_html, 'wpcap-retention-overview' ) && false !== strpos( $sp20_html, 'Was wird wann gelöscht' ) && false !== strpos( $sp20_html, 'Druckstapel und ihre PDF-Dateien' ) );

// SET-PC-47: Site Health reminds while no attendee retention is set; the default stays 0 (never).
Settings::update( array( 'attendee_retention_days' => 0 ) );
Settings::flush();
$sp20_health = SiteHealth::test_retention();
check( 'SET-PC-47: default attendee retention stays 0 and Site Health recommends setting one', 0 === Settings::defaults()['attendee_retention_days'] && isset( SiteHealth::test_callbacks()['retention'] ) && 'recommended' === $sp20_health['status'] && 'wpcap_retention' === $sp20_health['test'] && false !== strpos( (string) $sp20_health['actions'], 'tab=privacy' ) );
Settings::update( array( 'attendee_retention_days' => 365 ) );
Settings::flush();
$sp20_health = SiteHealth::test_retention();
check( 'SET-PC-47: with a retention period the Site Health test is good', 'good' === $sp20_health['status'] && false !== strpos( (string) $sp20_health['description'], '365' ) );
check( 'SET-PC-47: the reminder is never critical (no admin notice)', ! in_array( 'critical', array( SiteHealth::test_retention()['status'] ), true ) );

$sp20_restore();

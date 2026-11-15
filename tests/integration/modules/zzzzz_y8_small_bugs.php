<?php
/**
 * Y8 (audit panel 2026-11-15): small bugs.
 *
 * - QA-3 / QA-4 / LEAD-3: ids are read right after their INSERT, never after Audit::record() (guard
 *   scan over src/).
 * - QA-5 / QA-V1: strict date input (DateInput) for campaigns, fiscal profiles and REST service slots;
 *   the admin campaign form (local time) keeps working.
 * - QA-9: e-mail addresses longer than the varchar(190) columns are refused with a field error.
 * - QA-V2: checkout rate limit counts atomically with a persistent object cache.
 * - QA-V3: buyer lock names carry the database (Installer::lock_scope()).
 * - QA-V4: uninstall removes the Action Scheduler group.
 *
 * Included from run.php after the X5 tests (uses make_type(), x5_rest() and x5_seller()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\CampaignsPage as Y8CampaignsPage;
use PassCaptain\Admin\Forms as Y8Forms;
use PassCaptain\Campaigns as Y8Campaigns;
use PassCaptain\DateInput;
use PassCaptain\Installer as Y8Installer;
use PassCaptain\Modules\Fiscalization as Y8Fiscal;
use PassCaptain\Orders as Y8Orders;
use PassCaptain\RateLimit as Y8RateLimit;
use PassCaptain\Sales\BuyerLimits as Y8BuyerLimits;

global $wpdb;
$p = $wpdb->prefix;

/** Fake persistent object cache (off unless a test switches it on). */
$GLOBALS['y8_ext_cache'] = false;
$GLOBALS['y8_cache'] = array();
if ( ! function_exists( 'wp_using_ext_object_cache' ) ) { function wp_using_ext_object_cache( $using = null ) { return ! empty( $GLOBALS['y8_ext_cache'] ); } }
if ( ! function_exists( 'wp_cache_add' ) ) { function wp_cache_add( $key, $value, $group = '', $expire = 0 ) { if ( isset( $GLOBALS['y8_cache'][ $group ][ $key ] ) ) { return false; } $GLOBALS['y8_cache'][ $group ][ $key ] = $value; return true; } }
if ( ! function_exists( 'wp_cache_incr' ) ) { function wp_cache_incr( $key, $offset = 1, $group = '' ) { if ( ! isset( $GLOBALS['y8_cache'][ $group ][ $key ] ) ) { return false; } $GLOBALS['y8_cache'][ $group ][ $key ] += $offset; return $GLOBALS['y8_cache'][ $group ][ $key ]; } }

function y8_email( $length, $local = 'buyer' ) {
	$rest = $length - strlen( $local ) - 1 - 4; // Labels and their dots before ".com".
	$labels = array();
	while ( $rest > 0 ) {
		$take = $rest <= 61 ? $rest : 60;
		$labels[] = str_repeat( chr( 97 + count( $labels ) ), $take );
		$rest -= $rest <= 61 ? $rest : 61;
	}
	return $local . '@' . implode( '.', $labels ) . '.com';
}
function y8_event_of_type( $type_id ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT o.event_id FROM %i tt INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=%d', $wpdb->prefix . 'wpcap_ticket_types', $wpdb->prefix . 'wpcap_occurrences', $type_id ) );
}

/* ------------------------------------------------------------------------------------------ */
echo "Y8 QA-3/QA-4: ids are read right after their INSERT\n";
$wpdb->insert( "{$p}wpcap_audit_logs", array( 'user_id' => 1, 'action' => 'y8_padding', 'object_type' => 'test', 'object_id' => 0, 'details' => '{}', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) ); // Audit ids ahead of campaign ids.
$y8_code = 'YEIGHT' . strtoupper( substr( uniqid(), -6 ) );
$y8_c = Y8Campaigns::create( array( 'code' => $y8_code, 'kind' => 'percent', 'value' => 1000, 'currency' => 'EUR' ) );
$y8_c_db = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT campaign_id FROM %i WHERE code=%s', $p . 'wpcap_campaigns', $y8_code ) );
$y8_c_audit = $wpdb->get_row( $wpdb->prepare( "SELECT object_id FROM %i WHERE action='campaign_created' ORDER BY audit_id DESC LIMIT 1", $p . 'wpcap_audit_logs' ) );
check( 'QA-3: Campaigns::create returns the id of the new campaign (not of the audit row)', is_array( $y8_c ) && $y8_c_db > 0 && $y8_c_db === $y8_c['campaign_id'] && Y8Campaigns::get( $y8_c['campaign_id'] ) && $y8_code === Y8Campaigns::get( $y8_c['campaign_id'] )->code );
check( 'QA-3: the audit entry references the same campaign', $y8_c_audit && $y8_c_db === (int) $y8_c_audit->object_id );

$y8_seller = x5_seller( 'Y8 Fiskal GmbH' );
$y8_prof = Y8Fiscal::save_profile( $y8_seller, array( 'channel' => 'pos', 'environment' => 'test', 'status' => 'draft', 'register_id' => 'y8-' . uniqid() ) );
$y8_prof_db = is_array( $y8_prof ) ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT profile_id FROM %i WHERE seller_id=%d ORDER BY profile_id DESC LIMIT 1', $p . 'wpcap_fiscal_profiles', $y8_seller ) ) : 0;
check( 'QA-4: save_profile returns the id of a new fiscal profile (not of the audit row)', $y8_seller > 0 && is_array( $y8_prof ) && $y8_prof_db > 0 && $y8_prof_db === $y8_prof['profile_id'] );

echo "Y8 LEAD-3: no Audit::record()/Log/do_action between an INSERT and reading \$wpdb->insert_id\n";
$y8_offenders = array();
$y8_src = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WPCAP_PATH . 'src', FilesystemIterator::SKIP_DOTS ) );
foreach ( $y8_src as $y8_file ) {
	if ( 'php' !== $y8_file->getExtension() ) { continue; }
	$y8_lines = file( $y8_file->getPathname() );
	foreach ( $y8_lines as $y8_i => $y8_line ) {
		$y8_pos = strpos( $y8_line, 'insert_id' );
		if ( false === $y8_pos ) { continue; }
		// Statements on the same line before the one that reads insert_id.
		$y8_before = substr( $y8_line, 0, $y8_pos );
		$y8_between = false !== strrpos( $y8_before, ';' ) ? substr( $y8_before, 0, strrpos( $y8_before, ';' ) ) : '';
		for ( $y8_j = $y8_i - 1; $y8_j >= 0 && $y8_j >= $y8_i - 40; $y8_j-- ) {
			if ( preg_match( '#^\s*(//|\*|/\*)#', $y8_lines[ $y8_j ] ) ) { continue; } // Comments are neither origin nor call.
			if ( preg_match( '/->(insert|replace)\s*\(|\bINSERT\s/i', $y8_lines[ $y8_j ] ) ) { break; }
			$y8_between .= $y8_lines[ $y8_j ];
		}
		if ( preg_match( '/Audit::record\s*\(|AuditTrail::\w+\s*\(|Log::\w+\s*\(|do_action\s*\(/', $y8_between ) ) {
			$y8_offenders[] = str_replace( WPCAP_PATH, '', $y8_file->getPathname() ) . ':' . ( $y8_i + 1 );
		}
	}
}
check( 'LEAD-3: every insert_id is read before the next audit/log/hook write' . ( $y8_offenders ? ' (' . implode( ', ', $y8_offenders ) . ')' : '' ), array() === $y8_offenders );

/* ------------------------------------------------------------------------------------------ */
echo "Y8 QA-5: strict date input for campaigns and fiscal profiles\n";
check( 'DateInput: UTC MySQL format and ISO 8601 with offset are accepted and converted to UTC',
	'2026-12-01 18:00:00' === DateInput::utc( '2026-12-01 18:00:00', 'x' )
	&& '2026-12-01 17:00:00' === DateInput::utc( '2026-12-01T18:00:00+01:00', 'x' )
	&& '2026-12-01 17:00:00' === DateInput::utc( '2026-12-01T18:00+0100', 'x' )
	&& '2026-12-01 18:00:00' === DateInput::utc( '2026-12-01T18:00:00.250Z', 'x' )
	&& '2026-12-01 23:30:00' === DateInput::utc( '2026-12-01T18:00:00-05:30', 'x' )
	&& null === DateInput::utc( '', 'x' ) && null === DateInput::utc( null, 'x' ) );
$y8_bad = array( '01.12.2026 18:00', 'tomorrow', '2026-02-30 10:00:00', '2026-12-01T18:00:00', '2026-12-01 18:00', '2026-12-01', '2026-12-01T25:00:00Z', '2026-12-01T18:00:00+15:00', array( '2026-12-01 18:00:00' ), 1764612000 );
$y8_bad_ok = true;
foreach ( $y8_bad as $y8_value ) {
	$y8_r = DateInput::utc( $y8_value, 'starts_at' );
	$y8_bad_ok = $y8_bad_ok && is_wp_error( $y8_r ) && 'wpcap_invalid_datetime' === $y8_r->get_error_code() && 400 === $y8_r->get_error_data()['status'] && 'starts_at' === $y8_r->get_error_data()['field'] && false !== strpos( $y8_r->get_error_message(), 'starts_at' );
}
check( 'DateInput: German format, relative words, impossible dates, ISO without offset and non-strings are 400 with the field name', $y8_bad_ok );

$y8_type = make_type( 10 );
$y8_event = y8_event_of_type( $y8_type );
$y8_rest = static function ( array $body ) use ( $y8_event ) { $r = x5_rest( 'POST', array( 'event_id' => $y8_event ), $body + array( 'kind' => 'percent', 'value' => 500, 'currency' => 'EUR' ) ); return PassCaptain\Rest\Controller::create_campaign( $r ); };
$y8_r1 = $y8_rest( array( 'code' => 'Y8ISO' . substr( uniqid(), -5 ), 'starts_at' => '2026-12-01T18:00:00+01:00', 'ends_at' => '2026-12-24T00:00:00Z' ) );
$y8_r1_row = $y8_r1 instanceof WP_REST_Response ? Y8Campaigns::get( $y8_r1->get_data()['campaign_id'] ) : null;
check( 'QA-5: REST campaign with ISO 8601 offsets is stored in UTC', $y8_r1_row && '2026-12-01 17:00:00' === $y8_r1_row->starts_at && '2026-12-24 00:00:00' === $y8_r1_row->ends_at );
$y8_r2 = $y8_rest( array( 'code' => 'Y8DE' . substr( uniqid(), -5 ), 'starts_at' => '01.12.2026 18:00' ) );
$y8_r3 = $y8_rest( array( 'code' => 'Y8REL' . substr( uniqid(), -5 ), 'ends_at' => 'next monday' ) );
check( 'QA-5: REST campaign with German or relative dates is 400 naming the field (no 0000-00-00 row)', is_wp_error( $y8_r2 ) && 400 === $y8_r2->get_error_data()['status'] && 'starts_at' === $y8_r2->get_error_data()['field'] && is_wp_error( $y8_r3 ) && 'ends_at' === $y8_r3->get_error_data()['field'] && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE code LIKE 'Y8DE%%' OR code LIKE 'Y8REL%%'", $p . 'wpcap_campaigns' ) ) );
$y8_r4 = Y8Campaigns::create( array( 'code' => 'Y8WIN' . substr( uniqid(), -5 ), 'kind' => 'percent', 'value' => 500, 'currency' => 'EUR', 'starts_at' => '2026-12-02 00:00:00', 'ends_at' => '2026-12-01 00:00:00' ) );
check( 'QA-5: campaign window with end before start is refused on create', is_wp_error( $y8_r4 ) && 'wpcap_campaign_window' === $y8_r4->get_error_code() );
$y8_u = Y8Campaigns::update( $y8_r1_row->campaign_id, array( 'ends_at' => '24.12.2026' ) );
$y8_u2 = Y8Campaigns::update( $y8_r1_row->campaign_id, array( 'ends_at' => '2026-12-31T23:59:59+01:00' ) );
check( 'QA-5: Campaigns::update validates the same way (invalid refused, offset converted, start kept)', is_wp_error( $y8_u ) && 'ends_at' === $y8_u->get_error_data()['field'] && ! is_wp_error( $y8_u2 ) && '2026-12-31 22:59:59' === Y8Campaigns::get( $y8_r1_row->campaign_id )->ends_at && '2026-12-01 17:00:00' === Y8Campaigns::get( $y8_r1_row->campaign_id )->starts_at );
$y8_tz = PassCaptain\Admin\FeatureScreens::site_timezone();
$y8_admin_code = 'Y8ADM' . strtoupper( substr( uniqid(), -5 ) );
$y8_admin = Y8CampaignsPage::admin_action( array( 'do' => 'save', 'code' => $y8_admin_code, 'kind' => 'percent', 'value' => '10', 'currency' => 'EUR', 'starts_at' => '2026-07-01T09:30', 'ends_at' => '2026-07-31T18:00' ) );
$y8_admin_row = is_array( $y8_admin ) ? Y8Campaigns::get( $y8_admin['campaign_id'] ) : null;
check( 'QA-5: admin campaign form still takes local times (converted to UTC before the strict check)', $y8_admin_row && $y8_admin_code === $y8_admin_row->code && Y8Forms::local_to_utc( '2026-07-01T09:30', $y8_tz ) === $y8_admin_row->starts_at && Y8Forms::local_to_utc( '2026-07-31T18:00', $y8_tz ) === $y8_admin_row->ends_at );

$y8_fp_base = array( 'channel' => 'pos', 'environment' => 'test', 'status' => 'draft' );
$y8_fp = Y8Fiscal::save_profile( $y8_seller, $y8_fp_base + array( 'register_id' => 'y8-eff-' . uniqid(), 'effective_from' => '2027-01-01T00:00:00+01:00', 'effective_until' => '2027-12-31 23:00:00' ) );
$y8_fp_row = is_array( $y8_fp ) ? $wpdb->get_row( $wpdb->prepare( 'SELECT effective_from,effective_until FROM %i WHERE profile_id=%d', $p . 'wpcap_fiscal_profiles', $y8_fp['profile_id'] ) ) : null;
check( 'QA-5: fiscal profile validity with offset is stored in UTC', $y8_fp_row && '2026-12-31 23:00:00' === $y8_fp_row->effective_from && '2027-12-31 23:00:00' === $y8_fp_row->effective_until );
$y8_fp_bad = Y8Fiscal::save_profile( $y8_seller, $y8_fp_base + array( 'register_id' => 'y8-bad-' . uniqid(), 'effective_from' => '01.01.2027' ) );
$y8_fp_order = Y8Fiscal::save_profile( $y8_seller, $y8_fp_base + array( 'register_id' => 'y8-ord-' . uniqid(), 'effective_from' => '2027-02-01 00:00:00', 'effective_until' => '2027-01-01 00:00:00' ) );
check( 'QA-5: fiscal profile with an invalid or reversed validity is 400 naming the field', is_wp_error( $y8_fp_bad ) && 400 === $y8_fp_bad->get_error_data()['status'] && 'effective_from' === $y8_fp_bad->get_error_data()['field'] && is_wp_error( $y8_fp_order ) && 'effective_until' === $y8_fp_order->get_error_data()['field'] );

echo "Y8 QA-V1: REST service slots take strict times, independent of the PHP time zone\n";
$wpdb->insert( "{$p}wpcap_resources", array( 'event_id' => $y8_event, 'name' => 'Y8 Raum', 'capacity' => 2, 'buffer_before' => 0, 'buffer_after' => 0, 'status' => 'active' ) );
$y8_resource = (int) $wpdb->insert_id;
$y8_slot = static function ( array $body ) use ( $y8_resource ) { return PassCaptain\Rest\Controller::create_service_slot( x5_rest( 'POST', array( 'resource_id' => $y8_resource ), $body ) ); };
$y8_s1 = $y8_slot( array( 'starts_at' => 'tomorrow', 'ends_at' => 'tomorrow +1 hour' ) );
check( 'QA-V1: relative words ("tomorrow") are refused with 400 and the field name', is_wp_error( $y8_s1 ) && 'wpcap_invalid_datetime' === $y8_s1->get_error_code() && 'starts_at' === $y8_s1->get_error_data()['field'] );
$y8_php_tz = date_default_timezone_get();
date_default_timezone_set( 'Europe/Vienna' );
$y8_s2 = $y8_slot( array( 'starts_at' => '2031-05-04 10:00:00', 'ends_at' => '2031-05-04 11:00:00' ) );
date_default_timezone_set( $y8_php_tz );
$y8_s2_row = $y8_s2 instanceof WP_REST_Response ? $wpdb->get_row( "SELECT starts_at,ends_at FROM {$p}wpcap_service_slots WHERE slot_id=" . (int) $y8_s2->get_data()['slot_id'] ) : null;
check( 'QA-V1: "Y-m-d H:i:s" is UTC even when PHP runs in another time zone', $y8_s2_row && '2031-05-04 10:00:00' === $y8_s2_row->starts_at && '2031-05-04 11:00:00' === $y8_s2_row->ends_at );

/* ------------------------------------------------------------------------------------------ */
echo "Y8 QA-9: e-mail addresses longer than the database columns\n";
wpcap_test_reset_transients();
$y8_long = y8_email( 191 );
$y8_max = y8_email( 190, 'max' );
$y8_o1 = Y8Orders::create( array( 'email' => $y8_long, 'items' => array( array( 'ticket_type_id' => $y8_type, 'quantity' => 1 ) ), 'idempotency_key' => 'y8-long-' . uniqid() ) );
$y8_o2 = Y8Orders::create( array( 'email' => $y8_max, 'items' => array( array( 'ticket_type_id' => $y8_type, 'quantity' => 1 ) ), 'idempotency_key' => 'y8-max-' . uniqid() ) );
check( 'QA-9: buyer e-mail of 191 characters is 400 with field "email"; 190 characters still work', 191 === strlen( $y8_long ) && false !== is_email( $y8_long ) && is_wp_error( $y8_o1 ) && 'wpcap_email_too_long' === $y8_o1->get_error_code() && 400 === $y8_o1->get_error_data()['status'] && 'email' === $y8_o1->get_error_data()['field'] && 190 === strlen( $y8_max ) && is_array( $y8_o2 ) && $y8_max === $wpdb->get_var( $wpdb->prepare( 'SELECT customer_email FROM %i WHERE order_id=%d', $p . 'wpcap_orders', $y8_o2['order_id'] ) ) );
$y8_o3 = Y8Orders::create( array( 'email' => 'y8-att@example.com', 'items' => array( array( 'ticket_type_id' => $y8_type, 'quantity' => 1 ) ), 'attendees' => array( array( 'ticket_type_id' => $y8_type, 'name' => 'Lang', 'email' => y8_email( 200, 'guest' ) ) ), 'idempotency_key' => 'y8-att-' . uniqid() ) );
check( 'QA-9: attendee e-mail over 190 characters is refused at checkout with field "attendees.email"', is_wp_error( $y8_o3 ) && 'wpcap_email_too_long' === $y8_o3->get_error_code() && 'attendees.email' === $y8_o3->get_error_data()['field'] );
$y8_t = Y8Orders::transfer_ticket( str_repeat( 'a', 32 ), str_repeat( 'b', 64 ), str_repeat( 'c', 32 ), y8_email( 195, 'friend' ) );
check( 'QA-9: ticket transfer to an over-long address is refused with field "recipient_email"', is_wp_error( $y8_t ) && 'wpcap_email_too_long' === $y8_t->get_error_code() && 'recipient_email' === $y8_t->get_error_data()['field'] );

/* ------------------------------------------------------------------------------------------ */
echo "Y8 QA-V2: checkout rate limit with a persistent object cache\n";
$GLOBALS['y8_ext_cache'] = true;
$GLOBALS['y8_cache'] = array();
wpcap_test_reset_transients();
check( 'RateLimit::hit counts with wp_cache_incr in the transient group (atomic), limit enforced', Y8RateLimit::hit( 'y8_rl', 2, 60 ) && Y8RateLimit::hit( 'y8_rl', 2, 60 ) && ! Y8RateLimit::hit( 'y8_rl', 2, 60 ) && 3 === ( $GLOBALS['y8_cache']['transient']['y8_rl'] ?? 0 ) && array() === $GLOBALS['wpcap_test_transients'] );
$y8_ip = Y8Orders::client_ip();
$y8_rl_email = 'y8-rl@example.com';
$y8_rl = array();
for ( $y8_n = 0; $y8_n < 9; $y8_n++ ) { $y8_rl[] = Y8Orders::create( array( 'email' => $y8_rl_email, 'items' => array( array( 'ticket_type_id' => $y8_type, 'quantity' => 1 ) ), 'idempotency_key' => 'y8-rl-' . $y8_n . uniqid() ) ); }
$y8_rl_ok = count( array_filter( $y8_rl, static fn( $r ) => ! is_wp_error( $r ) ) );
$y8_rl_key = 'wpcap_checkout_' . Y8Orders::rate_hash( $y8_ip . '|' . $y8_rl_email );
check( 'QA-V2: checkout counts in the object cache (not a read-then-write transient) and refuses the 9th order', 8 === $y8_rl_ok && is_wp_error( $y8_rl[8] ) && 'wpcap_rate_limited' === $y8_rl[8]->get_error_code() && 9 === ( $GLOBALS['y8_cache']['transient'][ $y8_rl_key ] ?? 0 ) && ! isset( $GLOBALS['wpcap_test_transients'][ $y8_rl_key ] ) );
$GLOBALS['y8_ext_cache'] = false;
wpcap_test_reset_transients();
check( 'without an object cache the counter table limits (no transient written)', Y8RateLimit::hit( 'y8_fb', 1, 60 ) && ! Y8RateLimit::hit( 'y8_fb', 1, 60 ) && ! isset( $GLOBALS['wpcap_test_transients']['y8_fb'] ) && 2 === Y8RateLimit::count( 'y8_fb' ) );

/* ------------------------------------------------------------------------------------------ */
echo "Y8 QA-V3/QA-V4: lock names and uninstall\n";
$y8_scope = substr( md5( $wpdb->prefix . '|' . (string) $wpdb->get_var( 'SELECT DATABASE()' ) ), 0, 16 );
$y8_names = Y8BuyerLimits::lock_names( 'Käufer@Example.com', 7 );
check( 'QA-V3: buyer lock names include table prefix and database (like the upgrade lock), max. 64 characters', $y8_scope === Y8Installer::lock_scope() && 'wpcap_upgrade_' . $y8_scope === Y8Installer::lock_name() && 2 === count( $y8_names ) && array() === array_filter( $y8_names, static fn( $n ) => false === strpos( $n, $y8_scope ) || strlen( $n ) > 64 ) );
$y8_out = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/workers/y8_uninstall_child.php' ) . ' 2>&1' );
$y8_calls = json_decode( trim( (string) $y8_out ), true );
check( 'QA-V4: uninstall unschedules the Action Scheduler group wp-pass-captain (also when data is kept)', is_array( $y8_calls ) && in_array( 'as:|wp-pass-captain', $y8_calls, true ) && in_array( 'cron:wpcap_process_jobs', $y8_calls, true ) );

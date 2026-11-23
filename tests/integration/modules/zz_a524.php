<?php
/**
 * A524: member pass validity given as dates covers whole days in the site time zone; uses_per_day 0 = unlimited.
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

use PassCaptain\Memberships;

echo "A524: member pass validity and daily limit\n";
$a524_tz_before = get_option( 'timezone_string' );
update_option( 'timezone_string', 'Europe/Vienna' );
check( 'A524: a pure end date ends at 23:59:59 local time (Vienna winter: 22:59:59 UTC)', '2026-12-31 22:59:59' === Memberships::normalize_validity( '2026-12-31', true ) );
check( 'A524: a pure start date starts at 00:00 local time', '2026-12-30 23:00:00' === Memberships::normalize_validity( '2026-12-31', false ) );
check( 'A524: date-times stay as given (UTC)', '2026-12-31 10:00:00' === Memberships::normalize_validity( '2026-12-31 10:00:00', true ) );
$a524_type = make_type( 50, 10, 1000 );
global $wpdb;
$a524_event = (int) $wpdb->get_var( $wpdb->prepare( "SELECT o.event_id FROM {$wpdb->prefix}wpcap_ticket_types tt INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=%d", $a524_type ) );
$a524_pass = Memberships::issue( 'a524-' . uniqid() . '@example.com', gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ), gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS ), array( array( 'event_id' => $a524_event, 'uses_total' => 0, 'uses_per_day' => 0 ) ) );
$a524_id = is_wp_error( $a524_pass ) ? 0 : (int) ( $a524_pass['pass_id'] ?? 0 );
$a524_row = $a524_id ? $wpdb->get_row( $wpdb->prepare( "SELECT valid_until FROM {$wpdb->prefix}wpcap_member_passes WHERE pass_id=%d", $a524_id ) ) : null;
$a524_rule = $a524_id ? $wpdb->get_row( $wpdb->prepare( "SELECT uses_per_day FROM {$wpdb->prefix}wpcap_pass_rules WHERE pass_id=%d", $a524_id ) ) : null;
check( 'A524: an issued pass with date validity ends at the end of the local day', $a524_row && 1 === preg_match( '/ 2[23]:59:59$/', (string) $a524_row->valid_until ) );
check( 'A524: uses_per_day 0 is stored as unlimited', $a524_rule && 0 === (int) $a524_rule->uses_per_day );
check( 'A524: the admin label shows ∞ for unlimited daily uses', false !== strpos( PassCaptain\Admin\MembershipsPage::rule_label( (object) array( 'event_id' => 0, 'ticket_type_id' => 0, 'uses_per_day' => 0, 'uses_total' => 0 ) ), '∞/Tag' ) );
update_option( 'timezone_string', $a524_tz_before );

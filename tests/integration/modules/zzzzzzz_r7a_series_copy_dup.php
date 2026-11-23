<?php
/**
 * R7 (audit round 2, expert #9): "Duplizieren" for occurrences and events (Admin\Duplicator).
 * Runs after zzzzzzz_r7a_series_copy.php and reuses its event ($r7a_event, $r7a_tpl, $r7a_type).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\Duplicator as R7aDuplicator;

global $wpdb;
$p = $wpdb->prefix;

echo "R7 duplicate: occurrence and event copies with types, fields and rules\n";
if ( ! function_exists( 'sanitize_title' ) ) { function sanitize_title( $t ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $t ) ), '-' ); } }
if ( ! function_exists( 'wp_generate_password' ) ) { function wp_generate_password( $len = 12, $special = true, $extra = false ) { return substr( bin2hex( random_bytes( $len ) ), 0, $len ); } }
$r7d_tpl_start = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT starts_at FROM %i WHERE occurrence_id=%d', $p . 'wpcap_occurrences', $r7a_tpl ) );
$wpdb->update( "{$p}wpcap_ticket_types", array( 'sales_end' => gmdate( 'Y-m-d H:i:s', strtotime( $r7d_tpl_start . ' UTC' ) - 3600 ) ), array( 'ticket_type_id' => $r7a_type ) );
$r7d_start = gmdate( 'Y-m-d H:i:s', strtotime( $r7d_tpl_start . ' UTC' ) + 2 * DAY_IN_SECONDS );
$r7d_dup = R7aDuplicator::duplicate_occurrence( $r7a_tpl, $r7d_start );
$r7d_type = is_wp_error( $r7d_dup ) ? null : $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE occurrence_id=%d', $p . 'wpcap_ticket_types', $r7d_dup['occurrence_id'] ) );
check( 'duplicated occurrence has the type with price and shifted sales end', $r7d_type && 2000 === (int) $r7d_type->price_minor && gmdate( 'Y-m-d H:i:s', strtotime( $r7d_start . ' UTC' ) - 3600 ) === $r7d_type->sales_end );
check( 'duplicated type has the participant fields and the price rule', $r7d_type && 2 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ticket_type_id=%d', $p . 'wpcap_participant_fields', $r7d_type->ticket_type_id ) ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ticket_type_id=%d', $p . 'wpcap_price_rules', $r7d_type->ticket_type_id ) ) );
check( 'same start twice is refused', is_wp_error( R7aDuplicator::duplicate_occurrence( $r7a_tpl, $r7d_start ) ) );
check( 'start in the past is refused', is_wp_error( R7aDuplicator::duplicate_occurrence( $r7a_tpl, '2001-01-01 10:00:00' ) ) );
$r7d_ev = R7aDuplicator::duplicate_event( $r7a_event, true );
$r7d_row = is_wp_error( $r7d_ev ) ? null : $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE event_id=%d', $p . 'wpcap_events', $r7d_ev['event_id'] ) );
check( 'event copy is a draft with a new name', $r7d_row && 'draft' === $r7d_row->status && false !== strpos( $r7d_row->name, '(Kopie)' ) );
check( 'event copy has all upcoming occurrences (template, 3 series, 1 duplicate)', ! is_wp_error( $r7d_ev ) && 5 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event_id=%d', $p . 'wpcap_occurrences', $r7d_ev['event_id'] ) ) );
check( 'event copy: fields copied, event-wide rule copied, admission areas not linked across events', ! is_wp_error( $r7d_ev )
	&& 10 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i f INNER JOIN %i t ON t.ticket_type_id=f.ticket_type_id INNER JOIN %i o ON o.occurrence_id=t.occurrence_id WHERE o.event_id=%d', $p . 'wpcap_participant_fields', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $r7d_ev['event_id'] ) )
	&& 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE event_id=%d AND label='Eventweit'", $p . 'wpcap_price_rules', $r7d_ev['event_id'] ) )
	&& 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i a INNER JOIN %i t ON t.ticket_type_id=a.ticket_type_id INNER JOIN %i o ON o.occurrence_id=t.occurrence_id WHERE o.event_id=%d', $p . 'wpcap_admission_type_areas', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $r7d_ev['event_id'] ) ) );

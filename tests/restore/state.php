<?php
// Prints a canonical projection of the business data (columns that exist in every version) plus schema state.
global $wpdb; $p = $wpdb->prefix;
$out = array(
	'events'  => $wpdb->get_results( "SELECT event_id,name,status FROM {$p}wpcap_events ORDER BY event_id", ARRAY_A ),
	'occ'     => $wpdb->get_results( "SELECT occurrence_id,event_id,starts_at,capacity,status FROM {$p}wpcap_occurrences ORDER BY occurrence_id", ARRAY_A ),
	'types'   => $wpdb->get_results( "SELECT ticket_type_id,occurrence_id,name,price_minor,capacity FROM {$p}wpcap_ticket_types ORDER BY ticket_type_id", ARRAY_A ),
	'orders'  => $wpdb->get_results( "SELECT order_id,public_id,customer_email,status,payment_status,total_minor FROM {$p}wpcap_orders ORDER BY order_id", ARRAY_A ),
	'tickets' => $wpdb->get_results( "SELECT ticket_id,order_id,status,token_hash FROM {$p}wpcap_tickets ORDER BY ticket_id", ARRAY_A ),
	'checkins'=> $wpdb->get_results( "SELECT ticket_id,result FROM {$p}wpcap_checkins ORDER BY checkin_id", ARRAY_A ),
);
$hash = md5( wp_json_encode( $out ) );
$cols = array();
foreach ( $wpdb->get_col( "SHOW TABLES LIKE '{$p}wpcap\\_%'" ) as $t ) { $cols[ $t ] = count( $wpdb->get_col( "SHOW COLUMNS FROM `$t`" ) ); }
echo wp_json_encode( array( 'data_hash' => $hash, 'orders' => count( $out['orders'] ), 'tickets' => count( $out['tickets'] ), 'checkins' => count( $out['checkins'] ), 'db_version' => get_option( 'wpcap_db_version' ), 'db_version_max' => get_option( 'wpcap_db_version_max' ), 'credit_prefix' => get_option( 'wpcap_settings' )['credit_note_prefix'] ?? '(default)', 'tables' => count( $cols ), 'columns' => array_sum( $cols ) ) ), "\n";

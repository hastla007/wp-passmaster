<?php
/**
 * A637: a replayed box office form key (also via the deprecated POST /pos/sales) re-checks the cashier's
 * event scope before it returns, resumes or books the existing sale.
 */

// phpcs:ignoreFile

use PassCaptain\BoxOffice\Service as A637Pos;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;
PassCaptain\Modules\BoxOffice::register();
$a637_country = Settings::get( 'seller_country' );
Settings::update( array( 'seller_country' => 'CH' ) );

echo "A637: replayed box office sale re-checks the event scope\n";
$a637_type = make_type( 20, 10, 1000 );
$a637_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=" . (int) $a637_type );
$a637_event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=" . $a637_occ );
if ( ! A637Pos::open_session_for( 1 ) ) { A637Pos::open_session( 0 ); }
$a637_key = 'a637-' . bin2hex( random_bytes( 8 ) );
$a637_input = array( 'occurrence_id' => $a637_occ, 'quantities' => array( $a637_type => 1 ), 'method' => 'cash', 'form_key' => $a637_key );

// Scoped cashier (no manage_options): event staff entry for this event.
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$wpdb->insert( "{$p}wpcap_event_staff", array( 'event_id' => $a637_event, 'user_id' => 1, 'capability' => 'wpcap_manage_orders' ) );
$sale = A637Pos::sell( $a637_input );
$again = A637Pos::sell( $a637_input );
check( 'A637: a replay with the scope returns the same sale', ! is_wp_error( $sale ) && ! is_wp_error( $again ) && ! empty( $again['reused'] ) && (int) $again['sale']->sale_id === (int) $sale['sale']->sale_id );

// The scope is removed: the replay must not reveal (or resume) the sale.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_event_staff WHERE user_id=1 AND event_id=%d", $a637_event ) );
$replay = A637Pos::sell( $a637_input );
$req = new WP_REST_Request( 'POST', '/pass-captain/v1/pos/sales' );
$req->set_body( wp_json_encode( array( 'items' => array( array( 'ticket_type_id' => $a637_type, 'quantity' => 1 ) ), 'idempotency_key' => substr( $a637_key, 5 ), 'method' => 'cash' ) ) );
$req->set_header( 'Content-Type', 'application/json' );
$rest_key = 'rest-' . substr( $a637_key, 5 );
$wpdb->update( "{$p}wpcap_boxoffice_sales", array( 'form_key_hash' => hash( 'sha256', 'pos-sale|' . $rest_key ) ), array( 'sale_id' => (int) $sale['sale']->sale_id ) );
$rest = PassCaptain\Rest\Controller::pos_sale( $req );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'A637: without the event scope a replayed key is refused (403)', is_wp_error( $replay ) && 'wpcap_forbidden' === $replay->get_error_code() && 403 === ( $replay->get_error_data()['status'] ?? 0 ) );
check( 'A637: the same through the deprecated POST /pos/sales route', is_wp_error( $rest ) && 'wpcap_forbidden' === $rest->get_error_code() );
$admin = PassCaptain\Rest\Controller::pos_sale( $req );
check( 'A637: with full access the replay still works', ! is_wp_error( $admin ) && 200 === $admin->get_status() );

Settings::update( array( 'seller_country' => $a637_country ) );

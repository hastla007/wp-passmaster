<?php
// Seeds a small, realistic data set with the plugin API of the running version (works on 1.4.0 and later).
wp_set_current_user( 1 );
add_filter( 'wpcap_checkout_ip_limit', static function () { return 1000000; } );
global $wpdb; $p = $wpdb->prefix; $now = gmdate( 'Y-m-d H:i:s' );
$tag = getenv( 'SEED_TAG' ) ?: 'seed';
$wpdb->insert( "{$p}wpcap_events", array( 'name' => "Drill $tag", 'slug' => "drill-$tag-" . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now ) );
$event = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => 50, 'status' => 'scheduled' ) );
$occ = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $occ, 'name' => 'Frei', 'price_minor' => 0, 'currency' => 'EUR', 'capacity' => 40, 'max_per_order' => 10, 'status' => 'active' ) );
$type = (int) $wpdb->insert_id;
$codes = array();
for ( $i = 0; $i < 3; $i++ ) {
	$o = PassCaptain\Orders::create( array( 'email' => "drill-$tag-$i@example.com", 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 2 ) ), 'accept_terms' => true, 'idempotency_key' => "drill-$tag-$i-" . uniqid() ) );
	if ( is_wp_error( $o ) ) { fwrite( STDERR, 'order: ' . $o->get_error_message() . "\n" ); exit( 1 ); }
	foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=%d ORDER BY ticket_id", $o['order_id'] ) ) as $tid ) {
		$code = bin2hex( random_bytes( 32 ) );
		$wpdb->update( "{$p}wpcap_tickets", array( 'token_hash' => hash( 'sha256', $code ) ), array( 'ticket_id' => $tid ) );
		$codes[] = $code;
	}
}
$r = PassCaptain\Checkin::perform( $codes[0], array( 'source' => 'drill' ) );
if ( is_wp_error( $r ) ) { fwrite( STDERR, 'checkin: ' . $r->get_error_message() . "\n" ); exit( 1 ); }
file_put_contents( getenv( 'SEED_OUT' ), wp_json_encode( array( 'type' => $type, 'codes' => $codes ) ) );
echo "seeded event $event, 3 orders, 6 tickets, 1 check-in\n";

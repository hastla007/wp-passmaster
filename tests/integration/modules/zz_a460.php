<?php
/**
 * A460: at a venue all scanners share one public address (NAT). App pairing and help-code lookups
 * must not be limited per address for valid use: the address only counts failures (unknown, used or
 * expired pairing codes; unknown help codes); every help-code lookup counts per paired device or
 * WordPress user. Included from run.php.
 */
// phpcs:ignoreFile
use PassCaptain\HelpCode;
use PassCaptain\Mobile\Api;
use PassCaptain\Mobile\Devices;
use PassCaptain\Orders;

echo "A460: venue NAT – pairing and help codes limited per code/device, the address only for failures\n";
global $wpdb;
$p = $wpdb->prefix;
$a460_saved_user = $GLOBALS['wpcap_test_user_id'] ?? 0;
$a460_saved_ip = $_SERVER['REMOTE_ADDR'] ?? null;
$a460_nat = '198.18.' . random_int( 0, 250 ) . '.' . random_int( 1, 250 );
$a460_set_device = static function ( $device ) {
	$prop = new ReflectionProperty( Devices::class, 'current' );
	$prop->setAccessible( true );
	$prop->setValue( null, $device );
};
$a460_saved_device = Devices::current();

/* ---- Pairing ---- */
$GLOBALS['wpcap_test_user_id'] = 7;
$a460_type = make_type( 10, 10, 0 );
$a460_event = (int) $wpdb->get_var( "SELECT o.event_id FROM {$p}wpcap_ticket_types tt JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=$a460_type" );
$a460_codes = array();
for ( $i = 0; $i < 12; $i++ ) { $a460_codes[] = Devices::create_pairing( array( $a460_event ), 'Einlass ' . $i, 2 )['code'] ?? ''; }
$GLOBALS['wpcap_test_user_id'] = 0;
$a460_pair = static function ( $code, $ip ) {
	$_SERVER['REMOTE_ADDR'] = $ip;
	$r = new WP_REST_Request( 'POST' );
	$r->set_param( 'code', $code );
	$r->set_param( 'device_label', 'Gerät' );
	$r->set_param( 'platform', 'android' );
	$res = Api::pair( $r );
	return is_wp_error( $res ) ? (int) ( $res->get_error_data()['status'] ?? 0 ) : (int) $res->get_status();
};
$a460_status = array();
foreach ( $a460_codes as $code ) { $a460_status[] = $a460_pair( $code, $a460_nat ); }
check( 'A460: twelve scanners pair one after another from one venue address (old limit: 10 per address and hour)', 12 === count( array_filter( $a460_codes ) ) && array( 201 ) === array_values( array_unique( $a460_status ) ) );

$a460_limits = static function () { return array( 'failures' => 2, 'requests' => 200, 'window' => 3600 ); };
add_filter( 'wpcap_app_pair_rate_limits', $a460_limits );
$a460_bad_ip = '198.18.' . random_int( 0, 250 ) . '.' . random_int( 1, 250 );
$GLOBALS['wpcap_test_user_id'] = 7;
$a460_fresh = Devices::create_pairing( array( $a460_event ), 'Nachzügler', 2 )['code'] ?? '';
$a460_fresh2 = Devices::create_pairing( array( $a460_event ), 'Nachzügler 2', 2 )['code'] ?? '';
$GLOBALS['wpcap_test_user_id'] = 0;
$a460_f = array( $a460_pair( 'WRONGCODEWRONGCODE12', $a460_bad_ip ), $a460_pair( $a460_codes[0], $a460_bad_ip ), $a460_pair( $a460_fresh, $a460_bad_ip ) );
check( 'A460: wrong or used codes count per address: 400, 400, then 429 even for a valid code', array( 400, 400, 429 ) === $a460_f );
check( 'A460: another address still pairs', 201 === $a460_pair( $a460_fresh2, '198.19.3.' . random_int( 1, 250 ) ) );
check( 'A460: pairing limits filterable (wpcap_app_pair_rate_limits)', 2 === Api::pair_limits()['failures'] );
remove_filter( 'wpcap_app_pair_rate_limits', $a460_limits );
check( 'A460: pairing defaults (10 failures, 200 requests per hour and address)', 10 === Api::pair_limits()['failures'] && 200 === Api::pair_limits()['requests'] && 3600 === Api::pair_limits()['window'] );

/* ---- Help codes ---- */
$a460_order = Orders::create( array( 'email' => 'a460@example.com', 'items' => array( array( 'ticket_type_id' => $a460_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a460-' . uniqid() ) );
$a460_ticket = (int) $wpdb->get_var( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=" . (int) $a460_order['order_id'] . ' LIMIT 1' );
$a460_code = '';
for ( $i = 0; $i < HelpCode::LENGTH; $i++ ) { $a460_code .= HelpCode::ALPHABET[ random_int( 0, 31 ) ]; }
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_tickets SET help_hash=%s,help_n=0 WHERE ticket_id=%d", HelpCode::lookup_hash( $a460_code ), $a460_ticket ) );
$a460_resolve = static function ( $code ) { HelpCode::flush(); return HelpCode::resolve( $code ); };
$a460_unknown = static function ( $i ) { return 'ZZZY' . strtr( sprintf( '%04d', $i ), '01', 'AB' ); };
$GLOBALS['wpcap_test_filters']['wpcap_help_code_rate_limits'] = array( 'user' => 5, 'client' => 3, 'site' => 1000, 'window' => 600 );
$_SERVER['REMOTE_ADDR'] = '198.18.' . random_int( 0, 250 ) . '.' . random_int( 1, 250 );
$a460_found = array();
$a460_set_device( null ); // Web scanner requests: no paired device.
$a460_base = random_int( 1000000, 9000000 );
foreach ( array( $a460_base + 1, $a460_base + 2 ) as $a460_uid ) {
	$GLOBALS['wpcap_test_user_id'] = $a460_uid;
	for ( $i = 0; $i < 4; $i++ ) { $a460_found[] = $a460_resolve( $a460_code ); }
}
check( 'A460: two scanners behind one address resolve 8 valid help codes (client limit 3 counts only unknown codes)', array( $a460_ticket ) === array_values( array_unique( array_map( static fn( $r ) => is_wp_error( $r ) ? -1 : $r, $a460_found ) ) ) );

// Two app devices paired by the same staff account: each has its own budget.
$GLOBALS['wpcap_test_user_id'] = $a460_base + 3;
$a460_set_device( (object) array( 'device_id' => $a460_base + 1, 'user_id' => $a460_base + 3 ) );
$a460_dev_a = array();
for ( $i = 0; $i < 6; $i++ ) { $a460_dev_a[] = $a460_resolve( $a460_code ); }
$a460_set_device( (object) array( 'device_id' => $a460_base + 2, 'user_id' => $a460_base + 3 ) );
$a460_dev_b = $a460_resolve( $a460_code );
check( 'A460: per device: the sixth lookup of device A is limited, device B of the same account is not', $a460_ticket === $a460_dev_a[4] && is_wp_error( $a460_dev_a[5] ) && $a460_ticket === $a460_dev_b );
$a460_set_device( null );

// Guesses from one address: after 3 unknown codes the address is limited, also for other accounts.
$GLOBALS['wpcap_test_user_id'] = $a460_base + 4;
$a460_g = array();
for ( $i = 0; $i < 3; $i++ ) { $a460_g[] = $a460_resolve( $a460_unknown( $i ) ); }
$GLOBALS['wpcap_test_user_id'] = $a460_base + 5;
$a460_after = $a460_resolve( $a460_code );
check( 'A460: unknown codes use up the address budget (0, 0, 0, then 429 for everyone at that address)', array( 0, 0, 0 ) === $a460_g && is_wp_error( $a460_after ) && 429 === ( $a460_after->get_error_data()['status'] ?? 0 ) );
$_SERVER['REMOTE_ADDR'] = '198.19.4.' . random_int( 1, 250 );
check( 'A460: another address is not affected', $a460_ticket === $a460_resolve( $a460_code ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_help_code_rate_limits'] );

HelpCode::flush();
$a460_set_device( $a460_saved_device );
$GLOBALS['wpcap_test_user_id'] = $a460_saved_user;
if ( null === $a460_saved_ip ) { unset( $_SERVER['REMOTE_ADDR'] ); } else { $_SERVER['REMOTE_ADDR'] = $a460_saved_ip; }

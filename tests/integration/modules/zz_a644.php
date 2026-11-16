<?php
/**
 * A644: limiters keyed by values anyone can send (e-mail address, phone number) count only after
 * validation / success: junk requests with someone's address no longer lock them out of the
 * waitlist or service booking, and unpaid orders with someone's number no longer block their SMS
 * opt-in. Runs after modules/sms.php (SMS tables and module).
 */
// phpcs:ignoreFile
use PassCaptain\Modules\Sms;
use PassCaptain\Orders;
use PassCaptain\ServiceBookings;
use PassCaptain\Settings;
use PassCaptain\Waitlists;

global $wpdb;
$p = $wpdb->prefix;
echo "A644: limiters count after validation\n";
$GLOBALS['wpcap_test_transients'] = array();
$x1_ip = $_SERVER['REMOTE_ADDR'] ?? null;

// Waitlist.
$x1_type = make_type( 1, 5, 1000 );
$x1_occ = (int) $wpdb->get_var( $wpdb->prepare( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d", $x1_type ) );
$x1_event = (int) $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=%d", $x1_occ ) );
$x1_victim = 'x1-a644-victim@example.com';
for ( $i = 0; $i < 8; $i++ ) {
	$_SERVER['REMOTE_ADDR'] = '203.0.113.' . ( 10 + $i );
	Waitlists::join( $x1_event, $x1_occ, $x1_type + 100000, $x1_victim, 1 ); // Unknown ticket type: 404.
}
$_SERVER['REMOTE_ADDR'] = '198.51.100.44';
$x1_join = Waitlists::join( $x1_event, $x1_occ, $x1_type, $x1_victim, 1 );
check( 'A644: invalid waitlist requests with an address do not use up its limit', ! is_wp_error( $x1_join ) );
for ( $i = 0; $i < 6; $i++ ) { Waitlists::join( $x1_event, $x1_occ, $x1_type, $x1_victim, 1 ); }
$x1_rejoin = Waitlists::join( $x1_event, $x1_occ, $x1_type, $x1_victim, 1 );
check( 'A644: repeating a sign-up that is already open sends no mail and does not count either', ! is_wp_error( $x1_rejoin ) );
$x1_wsrc = (string) file_get_contents( WPCAP_PATH . 'src/Waitlists.php' );
check( 'A644: the address limit is checked with hits() and counted only when a mail goes out', false !== strpos( $x1_wsrc, 'RateLimit::hits( $email_bucket, HOUR_IN_SECONDS ) >= self::EMAIL_HOURLY_LIMIT' ) && false !== strpos( $x1_wsrc, "RateLimit::allow( \$email_bucket, self::EMAIL_HOURLY_LIMIT, HOUR_IN_SECONDS ); // A644" ) );

// Service booking.
$x1_svc_victim = 'x1-a644-svc@example.com';
for ( $i = 0; $i < ServiceBookings::EMAIL_HOURLY_LIMIT + 3; $i++ ) {
	$_SERVER['REMOTE_ADDR'] = '203.0.113.' . ( 100 + $i );
	ServiceBookings::reserve( 999999 + $i, $x1_svc_victim, 1, 0, 10, 'x1-a644-junk-' . $i ); // Unknown slot.
}
$_SERVER['REMOTE_ADDR'] = '198.51.100.45';
$x1_svc = ServiceBookings::reserve( 999990, $x1_svc_victim, 1, 0, 10, 'x1-a644-own' );
check( 'A644: failed booking attempts with an address do not lock it out (no 429 for the owner)', ! ( is_wp_error( $x1_svc ) && 'wpcap_booking_rate' === $x1_svc->get_error_code() ) );

// SMS opt-in: consents of unpaid orders do not count against the number.
$x1_sms_before = (array) Settings::get( 'sms' );
Settings::update( array( 'twilio_account_sid' => 'AC' . str_repeat( 'd4', 16 ), 'twilio_auth_token' => 'x1-a644', 'sms' => array_merge( Sms::settings_defaults()['sms'], array( 'provider' => 'twilio', 'twilio_from' => '+4915100000000', 'default_country_code' => '+49' ) ) ) );
$GLOBALS['wpcap_test_filters']['wpcap_sms_ip_hourly_limit'] = 100;
$x1_paid_type = make_type( 30, 10, 1500 );
$x1_free_type = make_type( 30, 10, 0 );
$x1_number = '+4915164464400';
for ( $i = 0; $i < 4; $i++ ) {
	$_SERVER['REMOTE_ADDR'] = '203.0.113.' . ( 200 + $i );
	Orders::create( array( 'email' => "x1-a644-u$i@example.com", 'items' => array( array( 'ticket_type_id' => $x1_paid_type, 'quantity' => 1 ) ), 'idempotency_key' => 'x1-a644-u' . $i . uniqid(), 'sms_consent' => 1, 'sms_phone' => $x1_number ) );
}
$_SERVER['REMOTE_ADDR'] = '198.51.100.46';
$x1_own = Orders::create( array( 'email' => 'x1-a644-own@example.com', 'items' => array( array( 'ticket_type_id' => $x1_free_type, 'quantity' => 1 ) ), 'idempotency_key' => 'x1-a644-own' . uniqid(), 'sms_consent' => 1, 'sms_phone' => $x1_number ) );
check( 'A644: four unpaid orders with a number do not block its owner\'s SMS opt-in', ! is_wp_error( $x1_own ) && null !== $wpdb->get_var( $wpdb->prepare( "SELECT consent_id FROM {$p}wpcap_sms_consents WHERE order_id=%d", $x1_own['order_id'] ) ) );
check( 'A644: only consents of paid orders count', 1 === PassCaptain\Sms\Service::recent_consents_for_number( $x1_number ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_sms_ip_hourly_limit'] );
Settings::update( array( 'sms' => $x1_sms_before ) );
if ( null === $x1_ip ) { unset( $_SERVER['REMOTE_ADDR'] ); } else { $_SERVER['REMOTE_ADDR'] = $x1_ip; }
$GLOBALS['wpcap_test_transients'] = array();

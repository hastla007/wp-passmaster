<?php
// phpcs:ignoreFile
/**
 * A623: the message queue sends at most mail_rate_per_minute mails per minute (setting/filter), and a
 * provider limit error defers the mail (no attempt used up) instead of failing it for good.
 * A689: reminders carry a personal opt-out link and List-Unsubscribe / List-Unsubscribe-Post
 * (RFC 8058 one-click); an opt-out stops queued and future reminders.
 */

use PassCaptain\Communications;
use PassCaptain\Modules\Reminders;

echo "A623/A689: mail rate, limit deferral, reminder opt-out\n";
global $wpdb;
$a623_q = $wpdb->prefix . 'wpcap_message_queue';
$a623_tpl = static function ( $template, $key = '', $payload = array() ) { return 'x4a623' === $key ? array( 'subject' => (string) ( $payload['subject'] ?? 'S' ), 'body' => 'B' ) : $template; };
add_filter( 'wpcap_email_template', $a623_tpl, 5, 3 );
$a623_m = new ReflectionMethod( Communications::class, 'sent_last_minute' );
$a623_m->setAccessible( true );
$GLOBALS['wpcap_test_filters']['wpcap_message_rate_per_minute'] = (int) $a623_m->invoke( null, PassCaptain\Clock::now() ) + 2;
$a623_run = uniqid();
for ( $i = 0; $i < 4; $i++ ) { Communications::enqueue( "x4a623|$a623_run|$i", "x4-a623-$i@example.com", 'x4a623', array( 'subject' => 'S' ) ); }
Communications::process_template( 'x4a623' );
$a623_sent = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $a623_q WHERE template_key=%s AND status='sent' AND recipient LIKE %s", 'x4a623', 'x4-a623-%' ) );
check( 'A623: a run sends no more than the rate per minute allows', 2 === $a623_sent );
check( 'A623: the rest stays queued for the next run', 2 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $a623_q WHERE template_key=%s AND status='queued' AND recipient LIKE %s", 'x4a623', 'x4-a623-%' ) ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_message_rate_per_minute'] );
$wpdb->query( "UPDATE $a623_q SET status='cancelled' WHERE template_key='x4a623'" );

check( 'A623: provider limits are recognised', Communications::is_limit_error( 'SMTP Error: 421 4.7.0 Try again later' ) && Communications::is_limit_error( 'Daily sending quota exceeded' ) && Communications::is_limit_error( '452 Too many recipients' ) && ! Communications::is_limit_error( '550 5.1.1 User unknown' ) );
// A failing mail (no subject) treated as limit error: deferred, attempts unchanged, still queued.
$GLOBALS['wpcap_test_filters']['wpcap_mail_limit_error'] = true;
Communications::enqueue( "x4a623|$a623_run|limit", 'x4-a623-limit@example.com', 'x4a623', array( 'subject' => '' ) );
Communications::process_template( 'x4a623' );
$a623_row = $wpdb->get_row( $wpdb->prepare( "SELECT status,attempts,send_at,payload FROM $a623_q WHERE recipient=%s ORDER BY message_id DESC LIMIT 1", 'x4-a623-limit@example.com' ) );
check( 'A623: a limit error defers the mail by 15 minutes without using an attempt', $a623_row && 'queued' === $a623_row->status && 0 === (int) $a623_row->attempts && strtotime( $a623_row->send_at . ' UTC' ) >= time() + 14 * 60 && 1 === ( json_decode( $a623_row->payload, true )['_limit_deferrals'] ?? 0 ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_mail_limit_error'] );
$wpdb->query( "UPDATE $a623_q SET status='cancelled' WHERE template_key='x4a623'" );
remove_filter( 'wpcap_email_template', $a623_tpl, 5 );

// A689: opt-out.
$a689_mail = 'x4-a689-' . uniqid() . '@example.com';
$a689_url = Reminders::optout_url( $a689_mail );
parse_str( (string) parse_url( $a689_url, PHP_URL_QUERY ), $a689_q );
check( 'A689: the opt-out link carries a keyed reference, never the address', '' !== $a689_url && false === strpos( $a689_url, 'x4-a689' ) && preg_match( '/^[a-f0-9]{64}$/', $a689_q[ Reminders::OPTOUT_QUERY ] ?? '' ) );
$a689_headers = Reminders::optout_headers( $a689_mail );
check( 'A689: reminders get List-Unsubscribe and the one-click header', in_array( 'List-Unsubscribe: <' . $a689_url . '>', $a689_headers, true ) && in_array( 'List-Unsubscribe-Post: List-Unsubscribe=One-Click', $a689_headers, true ) );
$a689_get = Reminders::optout_page( $a689_q[ Reminders::OPTOUT_QUERY ], $a689_q['sig'], false );
check( 'A689: opening the link (GET) only shows a confirmation button', 200 === $a689_get[0] && false !== strpos( $a689_get[1], '<form method="post"' ) && ! Reminders::is_opted_out( $a689_mail ) );
check( 'A689: a wrong signature is refused', is_wp_error( Reminders::opt_out( $a689_q[ Reminders::OPTOUT_QUERY ], str_repeat( '0', 64 ) ) ) && 400 === Reminders::optout_page( $a689_q[ Reminders::OPTOUT_QUERY ], 'x', true )[0] );
$a689_post = Reminders::optout_page( $a689_q[ Reminders::OPTOUT_QUERY ], $a689_q['sig'], true );
check( 'A689: the one-click POST records the opt-out', 200 === $a689_post[0] && Reminders::is_opted_out( $a689_mail ) && ! Reminders::is_opted_out( 'x4-other@example.com' ) );
check( 'A689: a queued reminder of an opted-out recipient is skipped (cancelled when due)', array( 'skip' => 'opted_out' ) === Reminders::render_template( null, Reminders::TEMPLATE, array( 'occurrence_id' => 1 ), $a689_mail ) );
$a689_list = get_option( Reminders::OPTOUT_OPTION, array() );
unset( $a689_list[ $a689_q[ Reminders::OPTOUT_QUERY ] ] );
update_option( Reminders::OPTOUT_OPTION, $a689_list, false );

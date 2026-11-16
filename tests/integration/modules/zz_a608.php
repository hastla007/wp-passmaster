<?php
// phpcs:ignoreFile
/**
 * A608: deadlines in mails and pages (bank transfer due date, waitlist offer and confirmation expiry,
 * reservation end) are shown in the event's time zone with the zone named – like the event date in the
 * same mail – instead of the site zone with an unlabeled time.
 */

use PassCaptain\Frontend\Format;
use PassCaptain\Orders;
use PassCaptain\Payments\BankTransfer;

echo "A608: deadlines in the event time zone, labelled\n";
check( 'A608: Format::deadline() names the event zone', '24.12.2026 20:00 (Europe/Vienna)' === Format::deadline( '2026-12-24 19:00:00', 'Europe/Vienna' ) && '24.12.2026 14:00 (America/New_York)' === Format::deadline( strtotime( '2026-12-24 19:00:00 UTC' ), 'America/New_York' ) );
check( 'A608: without event zone the site zone is named', '24.12.2026 19:00 (UTC)' === Format::deadline( '2026-12-24 19:00:00', '' ) && '' === Format::deadline( '' ) );
$a608_type = make_type( 5, 10, 2500 );
$a608 = Orders::create( array( 'email' => 'x4-a608@example.com', 'items' => array( array( 'ticket_type_id' => $a608_type, 'quantity' => 1 ) ), 'idempotency_key' => 'x4-a608-' . uniqid(), 'payment_provider' => 'manual' ) );
$a608_details = BankTransfer::details( Orders::get( (int) $a608['order_id'] ) );
check( 'A608: the bank transfer due date carries the event time zone (Europe/Vienna)', '' !== $a608_details['due_at'] && Format::deadline( $a608_details['due_at'], 'Europe/Vienna' ) === $a608_details['due_date'] && false !== strpos( $a608_details['due_date'], '(Europe/Vienna)' ) );
$a608_src = (string) file_get_contents( WPCAP_PATH . 'src/Waitlists.php' );
check( 'A608: waitlist deadlines no longer use the site zone with a bare abbreviation', false === strpos( $a608_src, "datetime_format() . ' T'" ) && 4 <= substr_count( $a608_src, 'Frontend\\Format::deadline(' ) );

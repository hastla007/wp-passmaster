<?php
// phpcs:ignoreFile
/**
 * A412: the exchange history of an order shows the time in the site time zone, the channel translated
 * and the orders by their order number ("AB12-CD34") instead of the internal id.
 */

use PassCaptain\Exchange\AdminPage as ExchangeAdmin;
use PassCaptain\Frontend\Format;
use PassCaptain\Orders;

if ( ! function_exists( 'admin_url' ) ) { function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return (string) $u; } }

echo "A412: exchange history in site time, translated channel, order numbers\n";
$a412_saved_tz = $GLOBALS['wpcap_test_options']['timezone_string'] ?? null;
$GLOBALS['wpcap_test_options']['timezone_string'] = 'Europe/Vienna';
$a412_type = make_type( 5, 10, 0 );
$a412_order = Orders::create( array( 'email' => 'x4-a412@example.com', 'items' => array( array( 'ticket_type_id' => $a412_type, 'quantity' => 1 ) ), 'idempotency_key' => 'x4-a412-' . uniqid() ) );
$a412_row = Orders::get( (int) $a412_order['order_id'] );
$a412_m = new ReflectionMethod( ExchangeAdmin::class, 'render_table' );
$a412_m->setAccessible( true );
ob_start();
$a412_m->invoke( null, array( array( 'exchange_id' => 1, 'order_id' => (int) $a412_row->order_id, 'supplementary_order_id' => 0, 'from_type_id' => $a412_type, 'to_type_id' => $a412_type, 'channel' => 'portal', 'currency' => 'EUR', 'difference_minor' => 0, 'fee_minor' => 0, 'due_minor' => 0, 'refund_minor' => 0, 'waived' => 0, 'refund_status' => '', 'status' => 'completed', 'created_at' => '2026-07-01 10:00:00' ) ) );
$a412_html = (string) ob_get_clean();
check( 'A412: the time is shown in the site time zone (10:00 UTC = 12:00 Vienna in summer)', false !== strpos( $a412_html, '<td>01.07.2026 12:00</td>' ) );
check( 'A412: the channel is translated', false !== strpos( $a412_html, '<td>Bestellportal</td>' ) && false === strpos( $a412_html, '<td>portal</td>' ) );
check( 'A412: the order is linked with its order number', false !== strpos( $a412_html, '>' . Format::order_reference( $a412_row->public_id ) . '</a>' ) && false === strpos( $a412_html, '>#' . (int) $a412_row->order_id . '</a>' ) );
check( 'A412: every exchange channel has a label', ! array_filter( PassCaptain\Exchange\Service::CHANNELS, static fn( $c ) => ExchangeAdmin::channel_label( $c ) === $c ) );
if ( null === $a412_saved_tz ) { unset( $GLOBALS['wpcap_test_options']['timezone_string'] ); } else { $GLOBALS['wpcap_test_options']['timezone_string'] = $a412_saved_tz; }

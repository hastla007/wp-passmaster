<?php
/**
 * A414: after an action on the ticket list (revoke, reissue, correction, undo) the list comes back with the
 * same search, event filter, status filter and page.
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

echo "A414: ticket list keeps its filters after an action\n";
$a414_m = new ReflectionMethod( \PassCaptain\Admin\TicketsPage::class, 'back_url' );
$a414_m->setAccessible( true );
$a414_url = (string) $a414_m->invoke( null, array( 'return_search' => 'Muster', 'return_event' => '42', 'return_status' => 'checked_in', 'return_paged' => '3' ) );
parse_str( (string) parse_url( $a414_url, PHP_URL_QUERY ), $a414_q );
check( 'A414: search, event, status and page are restored', 'Muster' === ( $a414_q['s'] ?? '' ) && '42' === ( $a414_q['event_id'] ?? '' ) && 'checked_in' === ( $a414_q['status'] ?? '' ) && '3' === ( $a414_q['paged'] ?? '' ) );
$a414_url = (string) $a414_m->invoke( null, array( 'return_status' => 'bogus', 'return_paged' => '1' ) );
parse_str( (string) parse_url( $a414_url, PHP_URL_QUERY ), $a414_q );
check( 'A414: unknown status and page 1 are left out', ! isset( $a414_q['status'] ) && ! isset( $a414_q['paged'] ) );
check( 'A414: every action form of the list carries the list state', 5 <= substr_count( (string) file_get_contents( WPCAP_PATH . 'src/Admin/TicketsPage.php' ), 'self::return_fields( $filters, $page );' ) );
$a414_m = new ReflectionMethod( \PassCaptain\Admin\TicketsPage::class, 'return_fields' );
$a414_m->setAccessible( true );
ob_start();
$a414_m->invoke( null, array( 'search' => 'Muster', 'event_id' => 42, 'status' => 'valid' ), 3 );
$a414_hidden = (string) ob_get_clean();
check( 'A414: the hidden fields render search, event, status and page (no recursion)', false !== strpos( $a414_hidden, 'return_search' ) && false !== strpos( $a414_hidden, 'Muster' ) && false !== strpos( $a414_hidden, 'return_paged' ) );

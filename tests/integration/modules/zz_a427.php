<?php
/**
 * A427/A630: batch print with new codes runs as a background job in blocks; codes are activated
 * only by the download of the finished file; aborting never leaves a ticket invalid; tickets held
 * by someone other than the buyer keep their code.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\HelpCode;
use PassCaptain\Orders;
use PassCaptain\Printing\BatchJob;
use PassCaptain\Printing\Page as PrintPage;

global $wpdb;
$p = $wpdb->prefix;

echo "A427: batch print with new codes as background job\n";
$a427_type = make_type( 200, 50, 0 );
$a427_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a427_type" );
$a427_order = Orders::create( array( 'email' => 'a427@example.com', 'items' => array( array( 'ticket_type_id' => $a427_type, 'quantity' => 45 ) ), 'idempotency_key' => 'a427-o1' ) );
$a427_hashes = static fn() => $wpdb->get_col( "SELECT CONCAT(ticket_id,':',token_hash) FROM {$p}wpcap_tickets WHERE order_id=" . (int) $a427_order['order_id'] . ' ORDER BY ticket_id' );
$a427_ids = array_map( 'intval', $wpdb->get_col( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=" . (int) $a427_order['order_id'] . ' ORDER BY ticket_id' ) );
// A630: one ticket transferred, one assigned to another attendee.
$wpdb->query( "UPDATE {$p}wpcap_tickets SET transfer_count=1 WHERE ticket_id=" . $a427_ids[0] );
$wpdb->query( "UPDATE {$p}wpcap_tickets SET attendee_email='other-a427@example.com' WHERE ticket_id=" . $a427_ids[1] );
$a427_start = $a427_hashes();
check( 'setup: 45 valid tickets', 45 === count( $a427_start ) );

$a427_block = static fn() => 10;
$a427_inline = static fn() => 0.0;
add_filter( 'wpcap_print_batch_block', $a427_block );
add_filter( 'wpcap_print_batch_inline_budget', $a427_inline );

// Cap per batch.
$a427_max = static fn() => 20;
add_filter( 'wpcap_print_batch_max', $a427_max );
$a427_big = PrintPage::batch_request( $a427_occ, 'ticket', true );
remove_filter( 'wpcap_print_batch_max', $a427_max );
check( 'more tickets than wpcap_print_batch_max: refused, nothing changed', is_wp_error( $a427_big ) && 'wpcap_print_batch_too_large' === $a427_big->get_error_code() && $a427_start === $a427_hashes() );

// Abort while rendering: no ticket changes, files gone.
$a427_abort = PrintPage::batch_request( $a427_occ, 'ticket', true );
$a427_ab = BatchJob::get( $a427_abort['batch'] ?? 0 );
check( 'request only creates the batch (inline budget 0: one block), codes unchanged', ! is_wp_error( $a427_abort ) && 'rendering' === $a427_abort['status'] && 10 === (int) $a427_ab->done && 45 === (int) $a427_ab->total && $a427_start === $a427_hashes() );
BatchJob::step( $a427_abort['batch'] );
check( 'cancel during rendering: batch cancelled, files deleted, tickets unchanged', true === BatchJob::cancel( $a427_abort['batch'] ) && ! file_exists( BatchJob::path( $a427_ab, 'part' ) ) && ! file_exists( BatchJob::path( $a427_ab, 'codes' ) ) && is_wp_error( BatchJob::deliver( $a427_abort['batch'] ) ) && $a427_start === $a427_hashes() );

// Full run through the job worker, with a worker that "died" after writing a block.
add_filter( 'wpcap_process_job', array( BatchJob::class, 'handle_job' ), 10, 3 );
$a427 = PrintPage::batch_request( $a427_occ, 'ticket', true );
$a427_b = BatchJob::get( $a427['batch'] );
file_put_contents( BatchJob::path( $a427_b, 'part' ), str_repeat( 'garbage', 100 ), FILE_APPEND );
file_put_contents( BatchJob::path( $a427_b, 'codes' ), "999999\tdeadbeef\tdeadbeef\n", FILE_APPEND );
for ( $i = 0; $i < 20 && 'rendering' === BatchJob::get( $a427['batch'] )->status; $i++ ) { PassCaptain\Jobs::process_due( 50 ); }
$a427_b = BatchJob::get( $a427['batch'] );
$a427_pdf = (string) @file_get_contents( BatchJob::path( $a427_b, 'pdf' ) );
check( 'job renders in blocks of 10 until ready; one page per ticket; a half-written block is redone', 'ready' === $a427_b->status && 45 === (int) $a427_b->done && 45 === (int) $a427_b->pages && wpp_pdf_ok( $a427_pdf, 45 ) && false === strpos( $a427_pdf, 'garbage' ) );
check( 'ready but not downloaded: all codes unchanged', $a427_start === $a427_hashes() );
preg_match_all( '/\(([A-Z2-9]{4}-[A-Z2-9]{4})\) Tj/', wpcap_pdf_plain( $a427_pdf ), $a427_help );
check( 'help codes printed for the 43 tickets with a new code (none for transferred/assigned)', 43 === count( array_unique( $a427_help[1] ) ) );

// Ticket changed after rendering: its code is not replaced.
$a427_resent = bin2hex( random_bytes( 32 ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_tickets SET token_hash=%s WHERE ticket_id=%d", hash( 'sha256', $a427_resent ), $a427_ids[2] ) );
if ( ! in_array( array( HelpCode::class, 'on_code_rotated' ), array_column( $GLOBALS['wpcap_test_hooks']['wpcap_ticket_code_rotated'][5] ?? array(), 0 ), true ) ) { HelpCode::register(); }
$a427_rotations = array();
$a427_spy = static function ( $ticket_id, $token, $reason ) use ( &$a427_rotations ) { $a427_rotations[ $ticket_id ] = $reason; };
add_action( 'wpcap_ticket_code_rotated', $a427_spy, 10, 3 );
$a427_file = BatchJob::deliver( $a427['batch'] );
$a427_after = $a427_hashes();
check( 'first download activates the new codes: 42 rotated, 1 skipped (changed meanwhile)', ! is_wp_error( $a427_file ) && 42 === $a427_file['rotated'] && 1 === $a427_file['skipped'] && 'delivered' === BatchJob::get( $a427['batch'] )->status );
check( 'A630: transferred and assigned tickets keep their code; changed ticket keeps the newer code', $a427_after[0] === $a427_start[0] && $a427_after[1] === $a427_start[1] && $a427_ids[2] . ':' . hash( 'sha256', $a427_resent ) === $a427_after[2] && 42 === count( array_diff( array_slice( $a427_after, 3 ), array_slice( $a427_start, 3 ) ) ) );
PassCaptain\Jobs::process_due( 50 );
remove_action( 'wpcap_ticket_code_rotated', $a427_spy, 10 );
check( 'notify job: wpcap_ticket_code_rotated ("print") once per activated code, codes file deleted', 42 === count( $a427_rotations ) && array( 'print' ) === array_values( array_unique( $a427_rotations ) ) && ! file_exists( BatchJob::path( $a427_b, 'codes' ) ) );
$a427_help_ok = 0;
foreach ( array_unique( $a427_help[1] ) as $code ) {
	if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE help_hash=%s AND order_id=%d", HelpCode::lookup_hash( HelpCode::normalize( $code ) ), $a427_order['order_id'] ) ) ) { ++$a427_help_ok; }
}
check( 'printed help codes are the ones assigned after activation', ! HelpCode::ready() || 42 === $a427_help_ok );
$a427_again = BatchJob::deliver( $a427['batch'] );
check( 'second download: same file, no further rotation', ! is_wp_error( $a427_again ) && $a427_after === $a427_hashes() && $a427_file['path'] === $a427_again['path'] );
check( 'delivered batch cannot be cancelled', is_wp_error( BatchJob::cancel( $a427['batch'] ) ) );

// Without new codes: above the sync limit also in the background, never touches codes.
$a427_sync = static fn() => 5;
add_filter( 'wpcap_print_batch_sync_limit', $a427_sync );
$a427_plain = PrintPage::batch_request( $a427_occ, 'nametag', false );
remove_filter( 'wpcap_print_batch_sync_limit', $a427_sync );
remove_filter( 'wpcap_print_batch_inline_budget', $a427_inline );
BatchJob::work( $a427_plain['batch'] ?? 0, 30.0 );
$a427_pb = BatchJob::get( $a427_plain['batch'] ?? 0 );
$a427_plain_file = BatchJob::deliver( $a427_plain['batch'] ?? 0 );
check( 'large batch without codes: background file, name tags 10 per page, no rotation', $a427_pb && 'ready' === $a427_pb->status && 5 === (int) $a427_pb->pages && ! is_wp_error( $a427_plain_file ) && wpp_pdf_ok( (string) file_get_contents( $a427_plain_file['path'] ), 5 ) && 0 === $a427_plain_file['rotated'] && $a427_after === $a427_hashes() );

// Permission: new codes only for order managers (also for the download).
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_orders' );
check( 'download of a batch with codes needs order management', ! BatchJob::can_access( BatchJob::get( $a427['batch'] ) ) && BatchJob::can_access( $a427_pb ) );
$GLOBALS['wpcap_test_denied_caps'] = array();

// Cleanup after keep_days(): rows and files go.
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_print_batches SET updated_at=%s", gmdate( 'Y-m-d H:i:s', time() - 8 * DAY_IN_SECONDS ) ) );
BatchJob::cleanup();
check( 'cleanup deletes old batches and their files', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_print_batches" ) && ! file_exists( $a427_file['path'] ) && ! file_exists( $a427_plain_file['path'] ) );
remove_filter( 'wpcap_process_job', array( BatchJob::class, 'handle_job' ), 10 );
remove_filter( 'wpcap_print_batch_block', $a427_block );

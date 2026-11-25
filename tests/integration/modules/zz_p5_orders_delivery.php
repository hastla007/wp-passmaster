<?php
// phpcs:ignoreFile
/**
 * Splitting the Orders class (TODO "Gott-Klasse Orders"): the building blocks of the ticket mail – placeholders,
 * ticket PDF attachments, attachment budget, delivery status, missing tickets – live in the @internal class
 * Orders\Delivery; Orders keeps thin delegators with the former signatures. The mail flows stay covered by
 * orders/finance, zz_a621 (resend attachments), zz_a583_a580 (attachment budget) and the issuance modules; this
 * module pins the facade shape, the delegation and a few results on the database.
 */

use PassCaptain\Orders;

echo "Orders split: Orders\\Delivery (ticket mail building blocks)\n";

$p5d_sig = static function ( $class, $method ) {
	$r = new ReflectionMethod( $class, $method );
	return array(
		$r->isPublic() ? 'public' : ( $r->isPrivate() ? 'private' : 'protected' ),
		$r->isStatic(),
		array_map( static fn( $p ) => $p->getName() . ( $p->isDefaultValueAvailable() ? '=' . var_export( $p->getDefaultValue(), true ) : '' ), $r->getParameters() ),
	);
};
$p5d_expected = array(
	'discard_attachments'    => array( 'public', true, array( 'files', "dir=''" ) ),
	'addon_free_join'        => array( 'public', true, array() ),
	'missing_tickets'        => array( 'public', true, array( 'order_id' ) ),
	'delivery_status'        => array( 'public', true, array( 'order_id' ) ),
	'ticket_mail_vars'       => array( 'public', true, array( 'order', 'entries', 'list' ) ),
	'code_mail_section'      => array( 'public', true, array( 'ticket_id', "locale=''" ) ),
	'ticket_pdf_chunk_size'  => array( 'public', true, array() ),
	'attachment_budget'      => array( 'public', true, array( 'order=NULL' ) ),
	'pdf_ticket_entry'       => array( 'public', true, array( 'ticket' ) ),
	'ticket_pdf_attachments' => array( 'public', true, array( 'order', 'recipient', 'pdf_tickets' ) ),
	'format_start'           => array( 'public', true, array( 'starts_at', 'timezone' ) ),
	'site_name'              => array( 'public', true, array() ),
);
$p5d_bad = array();
foreach ( $p5d_expected as $p5d_m => $p5d_want ) {
	if ( ! method_exists( Orders::class, $p5d_m ) || $p5d_sig( Orders::class, $p5d_m ) !== $p5d_want ) { $p5d_bad[] = $p5d_m; continue; }
	if ( $p5d_sig( Orders\Delivery::class, $p5d_m ) !== $p5d_want ) { $p5d_bad[] = $p5d_m . ' (Delivery signature differs)'; }
	$p5d_r = new ReflectionMethod( Orders::class, $p5d_m );
	$p5d_body = implode( '', array_slice( file( $p5d_r->getFileName() ), $p5d_r->getStartLine() - 1, $p5d_r->getEndLine() - $p5d_r->getStartLine() + 1 ) );
	if ( $p5d_r->getEndLine() - $p5d_r->getStartLine() > 3 || false === strpos( $p5d_body, 'Orders\\Delivery::' . $p5d_m . '(' ) ) { $p5d_bad[] = $p5d_m . ' (not a thin delegator)'; }
}
check( 'Orders\\Delivery: the facade keeps every moved method with the former signature, as a thin delegator' . ( $p5d_bad ? ': ' . implode( ', ', $p5d_bad ) : '' ), ! $p5d_bad );
$p5d_class = new ReflectionClass( Orders\Delivery::class );
check( 'Orders\\Delivery: final and @internal; the private temp directory helper is private there and gone from Orders', $p5d_class->isFinal() && false !== strpos( (string) $p5d_class->getDocComment(), '@internal' ) && ( new ReflectionMethod( Orders\Delivery::class, 'private_temp_dir' ) )->isPrivate() && ! method_exists( Orders::class, 'private_temp_dir' ) );
$p5d_src = (string) file_get_contents( WPCAP_PATH . 'src/Orders.php' );
check( 'Orders\\Delivery: no file system calls left in Orders.php (mkdir/chmod/file_put_contents moved)', ! preg_match( '/\b(mkdir|chmod|file_put_contents)\(/', $p5d_src ) );

// Pure helpers answer as before.
check( 'Orders\\Delivery: chunk size and attachment budget keep their defaults and filters', 50 === Orders::ticket_pdf_chunk_size() && 7 * 1024 * 1024 === Orders::attachment_budget() );
add_filter( 'wpcap_ticket_pdf_chunk_size', $p5d_chunk = static fn() => 0 );
check( 'Orders\\Delivery: the chunk size is at least 1', 1 === Orders::ticket_pdf_chunk_size() );
remove_filter( 'wpcap_ticket_pdf_chunk_size', $p5d_chunk );
check( 'Orders\\Delivery: format_start() shows the start in the event timezone', false !== strpos( Orders::format_start( '2026-07-01 18:00:00', 'Europe/Vienna' ), 'Europe/Vienna' ) && false !== strpos( Orders::format_start( '2026-07-01 18:00:00', 'Europe/Vienna' ), '20' ) && 'kein Datum' === Orders::format_start( 'kein Datum', 'UTC' ) );
check( 'Orders\\Delivery: site_name() is never empty', '' !== Orders::site_name() );
$p5d_entry = Orders::pdf_ticket_entry( array( 'event' => 'E', 'ticket' => 'T', 'starts_at' => '2026-07-01 18:00:00', 'timezone' => 'UTC', 'location' => 'L', 'token' => 'tok', 'ticket_id' => 7, 'occurrence_id' => 3, 'ticket_type_id' => 2 ) );
check( 'Orders\\Delivery: pdf_ticket_entry() builds the PDF row', 'E' === $p5d_entry['event'] && 7 === $p5d_entry['ticket_id'] && '' === $p5d_entry['attendee'] && '' === $p5d_entry['help_code'] && '' !== $p5d_entry['when'] );

// On the database: an issued order misses no tickets; its PDF attachments are written privately and released.
wpcap_test_reset_transients();
$p5d_type = make_type( 10, 10, 0 );
$p5d_order = Orders::create( array( 'email' => 'p5d@example.com', 'items' => array( array( 'ticket_type_id' => $p5d_type, 'quantity' => 2 ) ), 'idempotency_key' => 'p5d-' . uniqid() ) );
$p5d_id = is_array( $p5d_order ) ? (int) $p5d_order['order_id'] : 0;
check( 'Orders\\Delivery: missing_tickets() is 0 for an issued free order', $p5d_id > 0 && 0 === Orders::missing_tickets( $p5d_id ) );
check( 'Orders\\Delivery: delivery_status() is null or a known status', in_array( Orders::delivery_status( $p5d_id )['status'] ?? null, array( null, 'sent', 'pending', 'retrying', 'failed' ), true ) );
$p5d_row = Orders::get( $p5d_id );
$p5d_vars = Orders::ticket_mail_vars( $p5d_row, array( array( 'event' => 'E', 'when' => 'W', 'location' => 'L', 'url' => 'https://example.test/t' ) ), static fn() => 'list' );
check( 'Orders\\Delivery: ticket_mail_vars() fills the shared placeholders', (string) $p5d_row->public_id === $p5d_vars['order_id'] && 'E' === $p5d_vars['event_name'] && 1 === $p5d_vars['ticket_count'] && is_callable( $p5d_vars['contract_texts'] ) );
$p5d_pdf = Orders::ticket_pdf_attachments( $p5d_row, 'p5d@example.com', array( $p5d_entry ) );
$p5d_perms = $p5d_pdf['dir'] ? substr( sprintf( '%o', fileperms( $p5d_pdf['dir'] ) ), -3 ) : '';
check( 'Orders\\Delivery: ticket_pdf_attachments() writes one PDF into a private directory (0700)', 1 === count( $p5d_pdf['files'] ) && 1 === $p5d_pdf['covered'] && '700' === $p5d_perms && is_file( $p5d_pdf['files'][0] ) && '%PDF' === substr( (string) file_get_contents( $p5d_pdf['files'][0] ), 0, 4 ) );
Orders::discard_attachments( $p5d_pdf['files'], $p5d_pdf['dir'] );
check( 'Orders\\Delivery: no attachments for a placeholder address', array() === Orders::ticket_pdf_attachments( $p5d_row, 'buyer@invalid.example', array( $p5d_entry ) )['files'] );

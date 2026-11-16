<?php
// phpcs:ignoreFile
/**
 * A626: mail attachments (ticket/invoice PDF, .pkpass, .ics) are not deleted right after wp_mail(),
 * so mail queue plugins that send later still find them; the hourly cleanup removes the plugin's
 * temp files once their TTL (wpcap_mail_attachment_ttl, default 6 h) is over. TTL 0 = delete at once.
 */

use PassCaptain\Mail\TempFiles;
use PassCaptain\Orders;

echo "A626: attachments kept until the cleanup\n";
$a626_saved = $GLOBALS['wpcap_test_filters']['wpcap_mail_attachment_ttl'] ?? null;
unset( $GLOBALS['wpcap_test_filters']['wpcap_mail_attachment_ttl'] );
check( 'A626: default TTL is 6 hours', 6 * HOUR_IN_SECONDS === TempFiles::ttl() );
$a626_base = sys_get_temp_dir() . '/x4-a626-' . bin2hex( random_bytes( 4 ) );
mkdir( $a626_base, 0700 );
$a626_dir = $a626_base . '/wpcap-' . bin2hex( random_bytes( 12 ) );
mkdir( $a626_dir, 0700 );
file_put_contents( $a626_dir . '/tickets-x.pdf', '%PDF' );
$a626_ics = $a626_base . '/termin-7-' . bin2hex( random_bytes( 8 ) ) . '.ics';
file_put_contents( $a626_ics, 'BEGIN:VCALENDAR' );
$a626_foreign = $a626_base . '/wpcap-notours';
mkdir( $a626_foreign );
file_put_contents( $a626_foreign . '/keep.txt', 'x' );
Orders::discard_attachments( array( $a626_dir . '/tickets-x.pdf', $a626_ics ), $a626_dir );
check( 'A626: after sending the attachments stay for queue plugins', is_file( $a626_dir . '/tickets-x.pdf' ) && is_file( $a626_ics ) );
check( 'A626: the cleanup keeps files younger than the TTL', 0 === TempFiles::purge( time(), $a626_base ) && is_file( $a626_ics ) );
check( 'A626: after the TTL the cleanup removes private dirs and .ics files', 2 === TempFiles::purge( time() + 7 * HOUR_IN_SECONDS, $a626_base ) && ! is_dir( $a626_dir ) && ! is_file( $a626_ics ) );
check( 'A626: other files in the temp dir are never touched', is_file( $a626_foreign . '/keep.txt' ) );
$GLOBALS['wpcap_test_filters']['wpcap_mail_attachment_ttl'] = 0;
$a626_dir2 = $a626_base . '/wpcap-' . bin2hex( random_bytes( 12 ) );
mkdir( $a626_dir2, 0700 );
file_put_contents( $a626_dir2 . '/a.pdf', '%PDF' );
Orders::discard_attachments( array( $a626_dir2 . '/a.pdf' ), $a626_dir2 );
check( 'A626: with TTL 0 they are deleted right after sending', ! is_dir( $a626_dir2 ) );
check( 'A626: the hourly cleanup runs the sweep', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Plugin.php' ), "array( Mail\\TempFiles::class, 'purge' )" ) );
unlink( $a626_foreign . '/keep.txt' );
rmdir( $a626_foreign );
rmdir( $a626_base );
if ( null === $a626_saved ) { unset( $GLOBALS['wpcap_test_filters']['wpcap_mail_attachment_ttl'] ); } else { $GLOBALS['wpcap_test_filters']['wpcap_mail_attachment_ttl'] = $a626_saved; }

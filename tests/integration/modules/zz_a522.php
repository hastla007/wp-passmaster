<?php
// phpcs:ignoreFile
/**
 * A522: HTML mails carry the reading direction of the recipient's language (dir="rtl" for Arabic,
 * Hebrew, Persian …, also on the content table and text alignment) and the order table puts the
 * amount on the end side.
 */

use PassCaptain\Mail\Blocks;
use PassCaptain\Mail\Layout;
use PassCaptain\Mail\Mailer;

echo "A522: reading direction of HTML mails\n";
check( 'A522: right-to-left languages are recognised, others are ltr', 'rtl' === Layout::direction( 'ar' ) && 'rtl' === Layout::direction( 'he_IL' ) && 'rtl' === Layout::direction( 'fa_IR' ) && 'ltr' === Layout::direction( 'de_DE' ) && 'ltr' === Layout::direction( 'en_US' ) );
$a522_ar = Layout::wrap( '<p>x</p>', 'S', 'ar' );
check( 'A522: an Arabic mail has dir="rtl" on html, body and the content table, text aligned right', false !== strpos( $a522_ar, '<html lang="ar" dir="rtl">' ) && false !== strpos( $a522_ar, '<body dir="rtl"' ) && (bool) preg_match( '/<table role="presentation"[^>]* dir="rtl"/', $a522_ar ) && false !== strpos( $a522_ar, 'text-align:right;font-family' ) );
$a522_de = Layout::wrap( '<p>x</p>', 'S', 'de_DE' );
check( 'A522: a German mail stays ltr', false !== strpos( $a522_de, 'dir="ltr"' ) && false === strpos( $a522_de, 'dir="rtl"' ) );
$a522_line = array( array( 'quantity' => 1, 'event' => 'E', 'ticket' => 'T', 'when' => 'W', 'gross_minor' => 1000, 'currency' => 'EUR' ) );
$a522_html_ar = Mailer::with_locale( 'ar', static fn() => Blocks::order_items( $a522_line )['html'] );
$a522_html_de = Blocks::order_items( $a522_line )['html'];
check( 'A522: the amount column sits on the end side (left in RTL, right in LTR)', false !== strpos( $a522_html_ar, 'text-align:left' ) && false !== strpos( $a522_html_ar, 'padding-left:16px' ) && false !== strpos( $a522_html_de, 'text-align:right' ) && false !== strpos( $a522_html_de, 'padding-right:16px' ) );

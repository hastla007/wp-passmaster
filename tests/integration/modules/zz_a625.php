<?php
// phpcs:ignoreFile
/**
 * A625: the HTML mail layout has an MSO ghost table (fixed 600 px column in desktop Outlook), declares
 * light/dark colour schemes with dark-mode overrides, and "Label: URL" lines (wallet links, ticket
 * links) become labelled buttons instead of long bare URLs.
 */

use PassCaptain\Mail\Layout;
use PassCaptain\Mail\Mailer;

echo "A625: Outlook, dark mode, labelled links\n";
$a625 = Layout::wrap( '<p>x</p>', 'S', 'de_DE' );
check( 'A625: MSO ghost table around the 600 px card', false !== strpos( $a625, '<!--[if mso]><table role="presentation" width="600" align="center"' ) && false !== strpos( $a625, '<!--[if mso]></td></tr></table><![endif]-->' ) );
check( 'A625: colour schemes declared with dark-mode overrides', false !== strpos( $a625, '<meta name="color-scheme" content="light dark">' ) && false !== strpos( $a625, '@media (prefers-color-scheme:dark)' ) && false !== strpos( $a625, 'class="wpcap-text"' ) );
$a625_url = 'https://pay.google.com/gp/v/save/' . str_repeat( 'eyJ0', 60 );
$a625_html = Mailer::text_to_html( "Konzert — Stehplatz\nIn Google Wallet speichern: $a625_url\nSiehe https://example.test/info für mehr." );
check( 'A625: a "Label: URL" line is a button with the label, the URL is not shown as text', false !== strpos( $a625_html, '<a href="' . $a625_url . '" style="display:inline-block' ) && false !== strpos( $a625_html, '>In Google Wallet speichern</a>' ) && false === strpos( $a625_html, '>' . $a625_url . '<' ) );
check( 'A625: URLs inside a sentence stay plain links; lines keep their breaks', false !== strpos( $a625_html, 'Siehe <a href="https://example.test/info">https://example.test/info</a> für mehr.' ) && 2 === substr_count( $a625_html, '<br />' ) );
check( 'A625: text is still escaped', false !== strpos( Mailer::text_to_html( '<b>x</b>: https://e.test/a' ), '&lt;b&gt;x&lt;/b&gt;</a>' ) );

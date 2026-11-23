<?php
/**
 * Z1 (merge follow-up): legal texts in mails.
 * - {contract_texts} (A568) followed the ticket list / ordered lines with an extra blank line and
 *   ended with a single line break, so "Bewahre diesen Link …" in the order access mail stuck to the
 *   last line of the consumer information (HTML: in the same line). Like {order_items},
 *   {payment_instructions}, {consumer_info} and {terms}, the block now brings its own trailing blank
 *   line (HTML: its own block element) and no leading one.
 * - The default ticket and order access mails carry the legal texts exactly once ({contract_texts};
 *   {consumer_info}/{terms} are only for custom templates).
 * Self-contained (WPCAP_TEST_ONLY=z1_mail).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Mail\Mailer;
use PassCaptain\Mail\Types;
use PassCaptain\Orders;
use PassCaptain\Settings;

echo "Z1: legal texts in the ticket and order access mails\n";
$z1m_saved = array_intersect_key( Settings::all(), array_flip( array( 'terms_text', 'consumer_info_text', 'require_terms', 'legal_texts' ) ) );
Settings::update( array( 'require_terms' => 1, 'legal_texts' => array(), 'terms_text' => 'Z1 AGB Satz', 'consumer_info_text' => 'Z1 Widerruf Satz' ) );
$z1m_type = make_type( 20, 10, 0 );
$z1m_o = Orders::create( array( 'email' => 'z1m@example.com', 'accept_terms' => 1, 'locale' => 'de_DE', 'items' => array( array( 'ticket_type_id' => $z1m_type, 'quantity' => 1 ) ), 'idempotency_key' => 'z1m-' . uniqid() ) );
$z1m_order = Orders::get( $z1m_o['order_id'] );
$z1m_vars = Types::order_access_vars( $z1m_order->public_id );
$z1m_body = Types::all()['order_access']['body']();
$z1m_text = Mailer::render_template( 'order_access', array( 'subject' => 'x', 'body' => $z1m_body, 'format' => 'text' ), $z1m_vars )['body'];
$z1m_html = Mailer::render_template( 'order_access', array( 'subject' => 'x', 'body' => $z1m_body, 'format' => 'html' ), $z1m_vars )['body'];
check( 'z1: order access mail – blank line between the consumer information and "Bewahre diesen Link"', false !== strpos( $z1m_text, "Z1 Widerruf Satz\n\nBewahre diesen Link" ) );
// From the ordered lines on ({access_link} is empty here: the test calls the mail without a portal token).
check( 'z1: order access mail – no run of more than one blank line after the ordered lines', false !== strpos( $z1m_text, 'Deine Bestellung' ) && false === strpos( (string) strstr( $z1m_text, 'Deine Bestellung' ), "\n\n\n" ) );
check( 'z1: order access mail (HTML) – the texts are their own block, "Bewahre" starts after it', 1 === preg_match( '#Z1 Widerruf Satz(?:</p>)?\s*</div>\s*Bewahre diesen Link#', $z1m_html ) );
check( 'z1: order access mail carries the legal texts exactly once', 1 === substr_count( $z1m_text, 'Z1 Widerruf Satz' ) && 1 === substr_count( $z1m_text, 'Z1 AGB Satz' ) );

$z1m_tickets_body = Types::all()['tickets']['body']();
check( 'z1: default ticket mail uses {contract_texts} only (no second copy via {consumer_info}/{terms})', false !== strpos( $z1m_tickets_body, '{contract_texts}' ) && false === strpos( $z1m_tickets_body, '{consumer_info}' ) && false === strpos( $z1m_tickets_body, '{terms}' ) );
$z1m_ticket_text = Mailer::render_template( 'tickets', array( 'subject' => 'x', 'body' => $z1m_tickets_body, 'format' => 'text' ), array( 'ticket_list' => PassCaptain\Mail\Mailer::block( "Konzert — Standard\n\n", '<p>Konzert — Standard</p>' ), 'contract_texts' => PassCaptain\LegalVersions::mail_block( $z1m_order ) ) )['body'];
check( 'z1: ticket mail – one blank line between the ticket list and the contract texts', false !== strpos( $z1m_ticket_text, "Konzert — Standard\n\nDiese Texte galten" ) && false === strpos( $z1m_ticket_text, "\n\n\n" ) );

$z1m_custom = Mailer::render_template( 'order_access', array( 'subject' => 'x', 'body' => "Hallo\n\n{consumer_info}{terms}Danke", 'format' => 'text' ), $z1m_vars )['body'];
check( 'z1: {consumer_info}/{terms} in a custom template: heading, text and one blank line each', "Hallo\n\nVerbraucherinformationen\nZ1 Widerruf Satz\n\nBedingungen\nZ1 AGB Satz\n\nDanke" === $z1m_custom );
Settings::update( $z1m_saved );

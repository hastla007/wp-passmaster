<?php
/**
 * N03 follow-up: the ticket page shows the state of consent-type fields (Sales\Consents) instead of
 * nothing, and while one is open a link to the holder's consent page (?wpcap_consent=<ticket code>).
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\TicketPage;
use PassCaptain\Sales\Consents as SalesConsents;

if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $a = -1 ) { return 'n'; } }

echo "P10: ticket page consent-type fields (state + consent link)\n";
global $wpdb;
$p = $wpdb->prefix;
SalesConsents::register();

$p10_type = make_type( 20, 10, 0 );
$p10_photo = PassCaptain\Admin\EventsPage::save_participant_field( array( 'ticket_type_id' => $p10_type, 'field_key' => 'photo', 'label' => 'P10 Fotos', 'field_type' => 'consent', 'required' => '0', 'consent_text' => 'P10 Fotos sind ok.', 'consent_version' => '2' ) );
$p10_photo = is_array( $p10_photo ) ? (int) $p10_photo['field_id'] : 0;
$p10_video = PassCaptain\Admin\EventsPage::save_participant_field( array( 'ticket_type_id' => $p10_type, 'field_key' => 'video', 'label' => 'P10 Video', 'field_type' => 'consent', 'required' => '0', 'consent_text' => 'P10 Video ist ok.', 'consent_version' => '1' ) );
$p10_video = is_array( $p10_video ) ? (int) $p10_video['field_id'] : 0;
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $p10_type, 'field_key' => 'company', 'label' => 'P10 Firma', 'field_type' => 'text', 'required' => 0, 'position' => 9, 'status' => 'active' ) );

$p10_r = Orders::create( array( 'email' => 'p10-buyer@example.com', 'items' => array( array( 'ticket_type_id' => $p10_type, 'quantity' => 1 ) ), 'attendees' => array(
	array( 'ticket_type_id' => $p10_type, 'name' => 'Paula', 'answers' => array( 'photo' => '1', 'company' => 'ACME' ) ),
), 'idempotency_key' => 'p10-' . uniqid() ), array( 'skip_rate_limit' => true ) );
check( 'P10 checkout with consent-type fields is issued', is_array( $p10_r ) && 'complete' === $p10_r['status'] && $p10_photo > 0 && $p10_video > 0 );
$p10_order_id = (int) $p10_r['order_id'];
$p10_tid = (int) tickets( $p10_order_id )[0]->ticket_id;
// Known plain code for the ticket (the capability of the ticket and consent page).
$p10_code = str_repeat( 'c', 63 ) . 'a';
$wpdb->update( "{$p}wpcap_tickets", array( 'token_hash' => hash( 'sha256', $p10_code ) ), array( 'ticket_id' => $p10_tid ) );
$p10_row = static function () use ( $wpdb, $p, $p10_tid ) {
	return $wpdb->get_row( $wpdb->prepare( "SELECT ticket_id,public_id,ticket_type_id,status FROM {$p}wpcap_tickets WHERE ticket_id=%d", $p10_tid ) );
};

$p10_html = TicketPage::consent_fields_html( $p10_row(), $p10_code );
check( 'P10 card lists both consent fields with version, the granted one with its date, the other as not granted', str_contains( $p10_html, 'id="wpcap-consent-fields"' ) && str_contains( $p10_html, 'P10 Fotos' ) && str_contains( $p10_html, 'Version 2' ) && str_contains( $p10_html, 'Erteilt am ' ) && str_contains( $p10_html, 'P10 Video' ) && str_contains( $p10_html, 'Nicht erteilt' ) );
check( 'P10 card links to the consent page with the ticket code while a consent is open', str_contains( $p10_html, '?wpcap_consent=' . $p10_code ) && str_contains( $p10_html, 'Einwilligung ansehen und erteilen' ) );
check( 'P10 card shows no consent text and no form (granting happens on the consent page)', ! str_contains( $p10_html, 'P10 Fotos sind ok.' ) && ! str_contains( $p10_html, '<form' ) && ! str_contains( $p10_html, 'P10 Firma' ) );
$p10_link = preg_match( '/wpcap_consent=([a-f0-9]{64})/', $p10_html, $p10_m ) ? $p10_m[1] : '';
$p10_page = SalesConsents::attendee_page( $p10_link );
check( 'P10 the linked consent page opens and offers only the open field', is_string( $p10_page ) && str_contains( $p10_page, 'P10 Video ist ok.' ) && ! str_contains( $p10_page, 'P10 Fotos sind ok.' ) );

SalesConsents::attendee_page( $p10_link, array( $p10_video ) );
$p10_html = TicketPage::consent_fields_html( $p10_row(), $p10_code );
check( 'P10 after granting on the consent page: both shown as granted, no link any more', 2 === substr_count( $p10_html, 'Erteilt am ' ) && ! str_contains( $p10_html, 'Nicht erteilt' ) && ! str_contains( $p10_html, 'wpcap_consent=' ) );

$p10_rec = $wpdb->get_var( $wpdb->prepare( "SELECT consent_id FROM {$p}wpcap_consents WHERE ticket_id=%d AND field_id=%d AND withdrawn_at IS NULL", $p10_tid, $p10_photo ) );
check( 'P10 withdrawal recorded', true === SalesConsents::withdraw( (int) $p10_rec, 'portal' ) );
$p10_html = TicketPage::consent_fields_html( $p10_row(), $p10_code );
check( 'P10 withdrawn consent shows its withdrawal date and the link comes back', str_contains( $p10_html, 'Widerrufen am ' ) && 1 === substr_count( $p10_html, 'Erteilt am ' ) && str_contains( $p10_html, '?wpcap_consent=' . $p10_code ) );

$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_consents SET withdrawn_source='holder_changed' WHERE consent_id=%d", (int) $p10_rec ) );
$p10_html = TicketPage::consent_fields_html( $p10_row(), $p10_code );
check( 'P10 a consent ended by a holder change reads as not granted for the current holder', str_contains( $p10_html, 'vorherigen Person gilt nicht weiter' ) && ! str_contains( $p10_html, 'Widerrufen am ' ) );

$wpdb->update( "{$p}wpcap_participant_fields", array( 'status' => 'inactive' ), array( 'field_id' => $p10_photo ) );
$p10_html = TicketPage::consent_fields_html( $p10_row(), $p10_code );
check( 'P10 inactive consent fields are not listed', ! str_contains( $p10_html, 'P10 Fotos' ) && str_contains( $p10_html, 'P10 Video' ) );
$wpdb->update( "{$p}wpcap_participant_fields", array( 'status' => 'active' ), array( 'field_id' => $p10_photo ) );

$p10_cancelled = $p10_row();
$p10_cancelled->status = 'cancelled';
check( 'P10 no card for a ticket that is no longer valid (the consent page would not open)', '' === TicketPage::consent_fields_html( $p10_cancelled, $p10_code ) );

$p10_plain = make_type( 20, 10, 0 );
$p10_r2 = Orders::create( array( 'email' => 'p10-plain@example.com', 'items' => array( array( 'ticket_type_id' => $p10_plain, 'quantity' => 1 ) ), 'idempotency_key' => 'p10b-' . uniqid() ), array( 'skip_rate_limit' => true ) );
$p10_t2 = tickets( (int) $p10_r2['order_id'] )[0];
check( 'P10 no card without consent-type fields', '' === TicketPage::consent_fields_html( (object) array( 'ticket_id' => (int) $p10_t2->ticket_id, 'ticket_type_id' => $p10_plain, 'status' => 'valid' ), str_repeat( 'd', 64 ) ) );

$p10_src = (string) file_get_contents( WPCAP_PATH . 'src/TicketPage.php' );
check( 'P10 ticket page renders the card', str_contains( $p10_src, 'echo self::consent_fields_html( $ticket, $token );' ) );

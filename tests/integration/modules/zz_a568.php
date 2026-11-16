<?php
/**
 * A568: every version of the legal texts is archived append-only (version hash = content), so the
 * wording an order was concluded under can be shown later; the default ticket confirmation mail
 * carries the texts of the order's version ({contract_texts}). Included from run.php.
 */
// phpcs:ignoreFile
use PassCaptain\LegalVersions;
use PassCaptain\Orders;
use PassCaptain\Settings;

if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $a = -1 ) { return 'n'; } }
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $t ) { return '<p>' . $t . '</p>'; } }
echo "A568: archived legal text versions and contract texts in the confirmation mail\n";
global $wpdb;
$p = $wpdb->prefix;
$a568_saved = array_intersect_key( Settings::all(), array_flip( array( 'terms_text', 'consumer_info_text', 'require_terms', 'legal_texts' ) ) );
$a568_rows = static function () use ( $wpdb, $p ) { return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_legal_versions" ); };
$a568_mail = static function ( $to ) {
	// The ticket mail (the order access mail follows it).
	foreach ( array_reverse( $GLOBALS['wpcap_test_mails'] ) as $mail ) { if ( $mail['to'] === $to && false !== strpos( $mail['subject'], 'Tickets' ) ) { return $mail; } }
	return array( 'body' => '', 'subject' => '' );
};
$a568_type = make_type( 20, 10, 0 );

check( 'A568: archive table exists', LegalVersions::ready() );
$a568_before = $a568_rows();
// Only the default language (texts of further languages would be versions of their own).
Settings::update( array( 'require_terms' => 1, 'legal_texts' => array(), 'terms_text' => 'A568 AGB Fassung eins', 'consumer_info_text' => 'A568 Verbraucherinfo eins' ) );
$a568_v1 = Settings::terms_version( 'de_DE' );
check( 'A568: saving the texts archives the version', $a568_before + 1 === $a568_rows() && 'A568 AGB Fassung eins' === ( LegalVersions::texts( $a568_v1 )['terms_text'] ?? '' ) && true === LegalVersions::texts( $a568_v1 )['archived'] );
$a568_o1 = Orders::create( array( 'email' => 'a568-1@example.com', 'accept_terms' => 1, 'locale' => 'de_DE', 'items' => array( array( 'ticket_type_id' => $a568_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a568-1' ) );
$a568_m1 = $a568_mail( 'a568-1@example.com' );
check( 'A568: order stores the version', ! is_wp_error( $a568_o1 ) && $a568_v1 === Orders::get( $a568_o1['order_id'] )->terms_version );
check( 'A568: default ticket mail carries the contract texts with the version', false !== strpos( $a568_m1['body'], 'A568 AGB Fassung eins' ) && false !== strpos( $a568_m1['body'], 'A568 Verbraucherinfo eins' ) && false !== strpos( $a568_m1['body'], 'Fassung ' . $a568_v1 ) );

Settings::update( array( 'terms_text' => 'A568 AGB Fassung zwei' ) );
$a568_v2 = Settings::terms_version( 'de_DE' );
check( 'A568: a new wording is archived next to the old one', $a568_v1 !== $a568_v2 && $a568_before + 2 === $a568_rows() );
check( 'A568: the old version stays provable after the change', 'A568 AGB Fassung eins' === ( LegalVersions::texts( $a568_v1 )['terms_text'] ?? '' ) );
$a568_o1_order = Orders::get( $a568_o1['order_id'] );
$a568_block = LegalVersions::mail_block( $a568_o1_order );
check( 'A568: {contract_texts} of an older order quotes its own version, not the current one', false !== strpos( (string) ( $a568_block['text'] ?? '' ), 'A568 AGB Fassung eins' ) && false === strpos( (string) ( $a568_block['text'] ?? '' ), 'Fassung zwei' ) && false !== strpos( (string) ( $a568_block['html'] ?? '' ), '<h3>' ) );
Settings::update( array( 'terms_text' => 'A568 AGB Fassung eins' ) );
check( 'A568: append-only – going back to an earlier wording adds no row and changes nothing', $a568_before + 2 === $a568_rows() && $a568_v1 === Settings::terms_version( 'de_DE' ) );

// Texts changed outside the settings page: the order still archives its version.
$a568_opt = get_option( Settings::OPTION );
$a568_opt['terms_text'] = 'A568 AGB direkt geändert';
update_option( Settings::OPTION, $a568_opt );
$a568_o3 = Orders::create( array( 'email' => 'a568-3@example.com', 'accept_terms' => 1, 'locale' => 'de_DE', 'items' => array( array( 'ticket_type_id' => $a568_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a568-3' ) );
$a568_v3 = Orders::get( $a568_o3['order_id'] )->terms_version;
check( 'A568: a checkout archives a version that was not archived yet', 'A568 AGB direkt geändert' === ( LegalVersions::texts( $a568_v3 )['terms_text'] ?? '' ) && true === LegalVersions::texts( $a568_v3 )['archived'] );

// Placeholder in the order access mail; empty without texts.
$a568_access = PassCaptain\Mail\Types::order_access_vars( Orders::get( $a568_o3['order_id'] )->public_id );
$a568_access_block = is_callable( $a568_access['contract_texts'] ) ? call_user_func( $a568_access['contract_texts'] ) : $a568_access['contract_texts'];
check( 'A568: {contract_texts} is available in the order access mail too', false !== strpos( (string) ( $a568_access_block['text'] ?? '' ), 'A568 AGB direkt geändert' ) );
check( 'A568: placeholder is documented for the ticket mail', isset( PassCaptain\Mail\Types::all()['tickets']['placeholders']['contract_texts'] ) );
Settings::update( array( 'require_terms' => 0, 'terms_text' => '', 'consumer_info_text' => '' ) );
$a568_o4 = Orders::create( array( 'email' => 'a568-4@example.com', 'items' => array( array( 'ticket_type_id' => $a568_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a568-4' ) );
$a568_m4 = $a568_mail( 'a568-4@example.com' );
check( 'A568: without legal texts the mail has no contract block', '' === (string) Orders::get( $a568_o4['order_id'] )->terms_version && false === strpos( $a568_m4['body'], 'Fassung' ) && '' === LegalVersions::mail_block( Orders::get( $a568_o4['order_id'] ) ) );
check( 'A568: unknown version → no texts', null === LegalVersions::texts( 'ffffffffffffffff' ) );
Settings::update( $a568_saved );

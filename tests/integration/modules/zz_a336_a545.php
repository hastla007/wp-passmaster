<?php
/**
 * A336/A545: the admin finds orders by buyer (billing) and attendee names and by the short order
 * number shown to buyers ("AB12-CD34"); the order list shows that number; the ticket search accepts
 * the short order number, the help code and the full ticket code. Scope and PII rules still apply.
 * Included from run.php (uses check(), make_type(), admin_paid_order()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\OrdersPage;
use PassCaptain\Admin\Queries;
use PassCaptain\Billing;
use PassCaptain\Frontend\Format;
use PassCaptain\HelpCode;
use PassCaptain\Orders;

if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = null ) { return esc_attr( $t ); } }
if ( ! function_exists( 'esc_attr_e' ) ) { function esc_attr_e( $t, $d = null ) { echo esc_attr( $t ); } }
if ( ! function_exists( 'esc_js' ) ) { function esc_js( $s ) { return addslashes( (string) $s ); } }
if ( ! function_exists( 'selected' ) ) { function selected( $a, $b = true, $echo = true ) { $r = $a == $b ? ' selected="selected"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce" value="n">'; } }
if ( ! function_exists( 'wp_timezone_string' ) ) { function wp_timezone_string() { return 'Europe/Vienna'; } }

echo "A336/A545: admin order and ticket search by name, short order number, help code\n";

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_transients'] = array();

$a336_type = make_type( 20, 10, 1200 );
$a336_order = admin_paid_order( $a336_type, 2, 'a336-kauf@example.com' );
$a336_row = Orders::get( $a336_order );
Billing::save( $a336_order, array( 'first_name' => 'Jürgen', 'last_name' => 'Müllerschön', 'company' => 'Zwölf Bühnen GmbH' ) );
$a336_tickets = Orders::tickets_for_order( $a336_order );
$wpdb->insert( "{$p}wpcap_ticket_assignments", array( 'ticket_id' => (int) $a336_tickets[1]->ticket_id, 'buyer_email' => 'a336-kauf@example.com', 'attendee_email' => 'a336-gast@example.com', 'attendee_name' => 'Erika Gästeliebe', 'status' => 'assigned', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$a336_other = admin_paid_order( $a336_type, 1, 'a336-other@example.com' );

$a336_find = static function ( $search, $scope = null ) {
	$ids = array_map( static fn( $o ) => (int) $o->order_id, Queries::search_orders( array( 'search' => $search ), $scope, 1, 50 )['rows'] );
	return $ids;
};
$a336_ref = Format::order_reference( $a336_row->public_id );
check( 'short order number helper: "AB12-CD34", lower case, spaces, prefixes and # are normalized', substr( $a336_row->public_id, 0, 8 ) === Queries::public_id_prefix( $a336_ref ) && substr( $a336_row->public_id, 0, 8 ) === Queries::public_id_prefix( 'Bestellung ' . strtolower( $a336_ref ) ) && substr( $a336_row->public_id, 0, 8 ) === Queries::public_id_prefix( '#' . str_replace( '-', ' ', $a336_ref ) ) && '' === Queries::public_id_prefix( 'Müller' ) );
check( 'order search: billing last name (non-ASCII, stored JSON-escaped)', array( $a336_order ) === $a336_find( 'Müllerschön' ) );
check( 'order search: first and last name together, any case', array( $a336_order ) === $a336_find( 'jürgen müllerschön' ) );
check( 'order search: company', array( $a336_order ) === $a336_find( 'Zwölf Bühnen' ) );
check( 'order search: attendee name and attendee e-mail', array( $a336_order ) === $a336_find( 'Gästeliebe' ) && array( $a336_order ) === $a336_find( 'a336-gast@' ) );
check( 'order search: short order number as in the mail, also with prefix/lower case', array( $a336_order ) === $a336_find( $a336_ref ) && array( $a336_order ) === $a336_find( 'Bestellung ' . strtolower( $a336_ref ) ) );
check( 'order search: a ticket number of the order', array( $a336_order ) === $a336_find( substr( (string) $a336_tickets[0]->public_id, 0, 12 ) ) );
check( 'order search: e-mail part still works, other orders not matched by the name', array( $a336_other ) === $a336_find( 'a336-other@' ) && ! in_array( $a336_other, $a336_find( 'Müllerschön' ), true ) );
check( 'order search: no events in scope finds nothing', array() === $a336_find( 'Müllerschön', array() ) );
$a336_deny = static function ( $allowed, $field ) { return in_array( $field, array( 'attendee_name', 'attendee_email', 'buyer_name', 'billing' ), true ) ? false : $allowed; };
add_filter( 'wpcap_pii_can_view', $a336_deny, 10, 2 );
check( 'order search: names are not searchable for users who may not see them (no probing)', array() === $a336_find( 'Gästeliebe' ) && array() === $a336_find( 'Müllerschön' ) );
remove_filter( 'wpcap_pii_can_view', $a336_deny, 10 );

// Order list shows the short order number of the mails and the buyer name.
$_GET = array( 'page' => OrdersPage::SLUG, 's' => 'Müllerschön' );
ob_start();
try { OrdersPage::render(); } catch ( Throwable $e ) { echo 'EXCEPTION ' . $e->getMessage(); }
$a336_html = ob_get_clean();
$_GET = array();
check( 'order list: short order number as in the mail, buyer name, search hint mentions names', false !== strpos( $a336_html, '<code>' . $a336_ref . '</code>' ) && false !== strpos( $a336_html, 'Jürgen Müllerschön' ) && false !== strpos( $a336_html, 'Bestellnummer, E-Mail oder Name' ) && false === strpos( $a336_html, 'EXCEPTION' ) );

// A545: ticket search.
$a336_tfind = static function ( $search, $scope = null ) {
	return array_map( static fn( $t ) => (int) $t->ticket_id, Queries::search_tickets( array( 'search' => $search ), $scope, 1, 50 )['rows'] );
};
$a336_tids = array_map( static fn( $t ) => (int) $t->ticket_id, $a336_tickets );
rsort( $a336_tids );
check( 'ticket search: displayed order number "AB12-CD34" finds the order\'s tickets', $a336_tids === $a336_tfind( $a336_ref ) && $a336_tids === $a336_tfind( 'Bestellung ' . strtolower( $a336_ref ) ) );
$a336_token = bin2hex( random_bytes( 32 ) );
$wpdb->update( "{$p}wpcap_tickets", array( 'token_hash' => hash( 'sha256', $a336_token ), 'help_hash' => null, 'help_n' => 0 ), array( 'ticket_id' => (int) $a336_tickets[0]->ticket_id ) );
HelpCode::flush();
$a336_help = HelpCode::for_ticket( (int) $a336_tickets[0]->ticket_id, $a336_token );
HelpCode::flush();
check( 'ticket search: help code (with or without hyphen, any case) finds exactly its ticket', '' !== $a336_help && array( (int) $a336_tickets[0]->ticket_id ) === $a336_tfind( $a336_help ) && array( (int) $a336_tickets[0]->ticket_id ) === $a336_tfind( strtolower( str_replace( '-', '', $a336_help ) ) ) );
check( 'ticket search: full ticket code or ticket link finds its ticket', array( (int) $a336_tickets[0]->ticket_id ) === $a336_tfind( $a336_token ) && array( (int) $a336_tickets[0]->ticket_id ) === $a336_tfind( 'https://example.test/?wpcap_ticket=' . $a336_token ) );
check( 'ticket search: help code outside the scope finds nothing', array() === $a336_tfind( $a336_help, array() ) );
// Earlier lookups in this file already counted: with a limit of 1 the next lookup is refused.
$GLOBALS['wpcap_test_filters']['wpcap_help_code_rate_limits'] = array( 'user' => 1, 'client' => 1, 'site' => 1, 'window' => 600 );
HelpCode::flush();
$a336_limited = Queries::search_tickets( array( 'search' => $a336_help ), null, 1, 50 );
unset( $GLOBALS['wpcap_test_filters']['wpcap_help_code_rate_limits'] );
HelpCode::flush();
check( 'ticket search: help-code lookups share the scanner rate limit and report it', ! $a336_limited['rows'] && ! empty( $a336_limited['notice'] ) );

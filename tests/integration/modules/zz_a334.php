<?php
// phpcs:ignoreFile
/**
 * A334: changing price, tax rate or tax class of a ticket type that already has orders needs an
 * explicit confirmation; the form shows how many tickets are sold and that the change applies to
 * new orders only; without orders nothing changes.
 */
use PassCaptain\Admin\EventsPage;
use PassCaptain\Orders;

if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce" value="n">'; } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = null ) { return esc_attr( $t ); } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); } }
if ( ! function_exists( 'selected' ) ) { function selected( $a, $b = true, $echo = true ) { $r = $a == $b ? ' selected="selected"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'submit_button' ) ) { function submit_button( $t = '', $type = 'primary', $name = 'submit', $wrap = true ) { echo '<button>' . esc_html( $t ) . '</button>'; } }

echo "A334: ticket type changes after the first sales\n";
global $wpdb;
$p = $wpdb->prefix;
$a334_type = make_type( 20, 10, 0 );
$a334_in = static function ( array $over = array() ) use ( $a334_type ) {
	return $over + array( 'ticket_type_id' => $a334_type, 'name' => 'Standard', 'currency' => 'EUR', 'price' => '0', 'tax_rate' => '0', 'capacity' => '20', 'max_per_order' => '10', 'status' => 'active' );
};
// Without orders: price changes are saved at once.
$a334_free = EventsPage::save_ticket_type( $a334_in( array( 'price' => '0,00', 'name' => 'Standard neu' ) ) );
check( 'A334: without orders a change needs no confirmation', is_array( $a334_free ) && empty( $a334_free['warning'] ) );
$a334_order = Orders::create( array( 'email' => 'a334@example.com', 'items' => array( array( 'ticket_type_id' => $a334_type, 'quantity' => 2 ) ), 'idempotency_key' => 'a334-' . uniqid() ) );
check( 'A334 setup: two tickets sold', ! is_wp_error( $a334_order ) && 2 === PassCaptain\Admin\Queries::sold_for_type( $a334_type ) );

$a334_r = EventsPage::save_ticket_type( $a334_in( array( 'price' => '12,50' ) ) );
$a334_row = $wpdb->get_row( "SELECT price_minor,tax_rate_bp FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a334_type" );
check( 'A334: price change after sales without confirmation is refused, nothing saved', is_wp_error( $a334_r ) && 'wpcap_type_sold_change' === $a334_r->get_error_code() && 409 === ( $a334_r->get_error_data()['status'] ?? 0 ) && 'confirm_sold_change' === ( $a334_r->get_error_data()['field'] ?? '' ) && 0 === (int) $a334_row->price_minor );
check( 'A334: the message names the sold tickets and says it applies to new orders only', is_wp_error( $a334_r ) && false !== strpos( $a334_r->get_error_message(), '2 Tickets verkauft' ) && false !== strpos( $a334_r->get_error_message(), 'nur für neue Bestellungen' ) && false !== strpos( $a334_r->get_error_message(), '(Preis)' ) );
$a334_tax = EventsPage::save_ticket_type( $a334_in( array( 'tax_rate' => '19' ) ) );
check( 'A334: a tax rate change needs the confirmation too', is_wp_error( $a334_tax ) && false !== strpos( $a334_tax->get_error_message(), '(Steuersatz)' ) );
$a334_other = EventsPage::save_ticket_type( $a334_in( array( 'name' => 'Stehplatz', 'capacity' => '30' ) ) );
check( 'A334: other changes (name, capacity) need no confirmation', is_array( $a334_other ) && empty( $a334_other['warning'] ) );

$a334_ok = EventsPage::save_ticket_type( $a334_in( array( 'name' => 'Stehplatz', 'capacity' => '30', 'price' => '12,50', 'tax_rate' => '19', 'confirm_sold_change' => '1' ) ) );
$a334_row = $wpdb->get_row( "SELECT price_minor,tax_rate_bp FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a334_type" );
check( 'A334: confirmed change is saved and the notice repeats what it does not change', is_array( $a334_ok ) && 1250 === (int) $a334_row->price_minor && 1900 === (int) $a334_row->tax_rate_bp && false !== strpos( (string) ( $a334_ok['warning'] ?? '' ), 'nur für neue Bestellungen' ) && false !== strpos( (string) $a334_ok['warning'], 'Preis, Steuersatz' ) );
check( 'A334: the existing order keeps its price', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT unit_price_minor FROM {$p}wpcap_order_items WHERE order_id=%d", $a334_order['order_id'] ) ) );

// The edit form shows the sales count and the confirmation box.
$a334_m = new ReflectionMethod( EventsPage::class, 'ticket_type_form' );
$a334_tt = PassCaptain\Admin\Queries::ticket_type( $a334_type );
ob_start();
$a334_m->invoke( null, $a334_tt, PassCaptain\Admin\Queries::occurrence( $a334_tt->occurrence_id ) );
$a334_form = ob_get_clean();
check( 'A334: edit form shows "2 Tickets verkauft" and the confirmation checkbox', false !== strpos( $a334_form, '2 Tickets verkauft.' ) && false !== strpos( $a334_form, 'name="confirm_sold_change"' ) );
$a334_new = make_type( 5 );
$a334_tt2 = PassCaptain\Admin\Queries::ticket_type( $a334_new );
ob_start();
$a334_m->invoke( null, $a334_tt2, PassCaptain\Admin\Queries::occurrence( $a334_tt2->occurrence_id ) );
check( 'A334: a type without orders has no confirmation box', false === strpos( (string) ob_get_clean(), 'confirm_sold_change' ) );

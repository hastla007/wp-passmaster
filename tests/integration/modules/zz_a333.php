<?php
// phpcs:ignoreFile
/**
 * A333: a ticket type or participant field can be applied to the same-named ticket type of every
 * upcoming date of the event ("auf alle Termine anwenden"); each date runs through the normal
 * checks (A334 confirmation, currency lock); the event forms point to the series module.
 */
use PassCaptain\Admin\EventsPage;
use PassCaptain\Orders;

if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce" value="n">'; } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = null ) { return esc_attr( $t ); } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); } }
if ( ! function_exists( 'selected' ) ) { function selected( $a, $b = true, $echo = true ) { $r = $a == $b ? ' selected="selected"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'submit_button' ) ) { function submit_button( $t = '', $type = 'primary', $name = 'submit', $wrap = true ) { echo '<button>' . esc_html( $t ) . '</button>'; } }

echo "A333: apply ticket types and participant fields to all dates\n";
global $wpdb;
$p = $wpdb->prefix;
$a333_now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'A333 Kurs', 'slug' => 'a333-' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $a333_now, 'updated_at' => $a333_now ) );
$a333_event = (int) $wpdb->insert_id;
$a333_occ = array();
$a333_types = array();
foreach ( array( 10, 17, 24 ) as $i => $a333_days ) {
	$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $a333_event, 'starts_at' => gmdate( 'Y-m-d H:00:00', time() + $a333_days * DAY_IN_SECONDS ), 'timezone' => 'Europe/Vienna', 'location' => 'Raum', 'capacity' => 0, 'status' => 'scheduled' ) );
	$a333_occ[] = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => (int) $wpdb->insert_id, 'name' => 'Standard', 'price_minor' => 0, 'currency' => 'EUR', 'capacity' => 10 + $i, 'max_per_order' => 10, 'status' => 'active' ) );
	$a333_types[] = (int) $wpdb->insert_id;
}
// A past date of the same event is never touched.
$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $a333_event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() - 5 * DAY_IN_SECONDS ), 'timezone' => 'Europe/Vienna', 'location' => 'Raum', 'capacity' => 0, 'status' => 'scheduled' ) );
$a333_past_occ = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $a333_past_occ, 'name' => 'Standard', 'price_minor' => 0, 'currency' => 'EUR', 'capacity' => 5, 'max_per_order' => 10, 'status' => 'active' ) );
$a333_past_type = (int) $wpdb->insert_id;
$a333_row = static function ( $id ) use ( $wpdb, $p ) { return $wpdb->get_row( "SELECT * FROM {$p}wpcap_ticket_types WHERE ticket_type_id=" . (int) $id ); };
$a333_in = array( 'ticket_type_id' => $a333_types[0], 'name' => 'Standard', 'currency' => 'EUR', 'price' => '15,00', 'tax_rate' => '10', 'capacity' => '50', 'max_per_order' => '4', 'status' => 'active', 'apply_all' => '1' );

$a333_r = EventsPage::save_ticket_type( $a333_in );
check( 'A333: change applied to the same-named type of the two other upcoming dates', is_array( $a333_r ) && 2 === ( $a333_r['applied'] ?? null ) && 1500 === (int) $a333_row( $a333_types[1] )->price_minor && 1000 === (int) $a333_row( $a333_types[2] )->tax_rate_bp && 4 === (int) $a333_row( $a333_types[2] )->max_per_order );
check( 'A333: capacity stays per date; the edited one takes the new value', 50 === (int) $a333_row( $a333_types[0] )->capacity && 11 === (int) $a333_row( $a333_types[1] )->capacity && 12 === (int) $a333_row( $a333_types[2] )->capacity );
check( 'A333: past dates are not changed', 0 === (int) $a333_row( $a333_past_type )->price_minor );
check( 'A333: without the checkbox only the edited type changes', is_array( EventsPage::save_ticket_type( array( 'price' => '16,00' ) + array_diff_key( $a333_in, array( 'apply_all' => 1 ) ) ) ) && 1600 === (int) $a333_row( $a333_types[0] )->price_minor && 1500 === (int) $a333_row( $a333_types[1] )->price_minor );

// A date whose type already sold: the price change needs the A334 confirmation there.
$a333_order = Orders::create( array( 'email' => 'a333@example.com', 'items' => array( array( 'ticket_type_id' => $a333_types[2], 'quantity' => 1 ) ), 'idempotency_key' => 'a333-' . uniqid() ) );
$a333_r2 = EventsPage::save_ticket_type( array( 'price' => '18,00' ) + $a333_in );
check( 'A333: a date with orders is skipped and named without confirmation, the others are changed', is_array( $a333_r2 ) && 1 === $a333_r2['applied'] && 1 === count( $a333_r2['skipped'] ) && false !== strpos( (string) $a333_r2['warning'], 'Nicht übernommen für' ) && 1800 === (int) $a333_row( $a333_types[1] )->price_minor && 1500 === (int) $a333_row( $a333_types[2] )->price_minor );
$a333_r3 = EventsPage::save_ticket_type( array( 'price' => '18,00', 'confirm_sold_change' => '1' ) + $a333_in );
check( 'A333: with the confirmation that date is changed too', is_array( $a333_r3 ) && 2 === $a333_r3['applied'] && 1800 === (int) $a333_row( $a333_types[2] )->price_minor );

// New type on all dates, sales window moved with each date.
$a333_start0 = (string) $wpdb->get_var( "SELECT starts_at FROM {$p}wpcap_occurrences WHERE occurrence_id={$a333_occ[0]}" );
$a333_sales_end = PassCaptain\Admin\Forms::utc_to_local( gmdate( 'Y-m-d H:i:s', strtotime( $a333_start0 . ' UTC' ) - 3600 ), 'Europe/Vienna' );
$a333_vip = EventsPage::save_ticket_type( array( 'ticket_type_id' => 0, 'occurrence_id' => $a333_occ[0], 'name' => 'VIP', 'currency' => 'EUR', 'price' => '40,00', 'tax_rate' => '10', 'capacity' => '5', 'max_per_order' => '2', 'status' => 'active', 'sales_end' => $a333_sales_end, 'apply_all' => '1' ) );
$a333_vips = $wpdb->get_results( "SELECT tt.*,o.starts_at AS occ_start FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE o.event_id=$a333_event AND tt.name='VIP' ORDER BY o.starts_at" );
$a333_moved = array_filter( $a333_vips, static function ( $t ) { return strtotime( $t->sales_end . ' UTC' ) === strtotime( $t->occ_start . ' UTC' ) - 3600; } );
check( 'A333: a new type is created on every upcoming date (not the past one), sales end moved with each date', is_array( $a333_vip ) && 2 === $a333_vip['applied'] && 3 === count( $a333_vips ) && 3 === count( $a333_moved ) && 4000 === (int) $a333_vips[2]->price_minor );
$a333_again = EventsPage::save_ticket_type( array( 'ticket_type_id' => 0, 'occurrence_id' => $a333_occ[0], 'name' => 'VIP', 'currency' => 'EUR', 'price' => '40,00', 'tax_rate' => '10', 'capacity' => '5', 'max_per_order' => '2', 'status' => 'active', 'apply_all' => '1' ) );
check( 'A333: dates that already have a type of that name are skipped', is_array( $a333_again ) && 0 === $a333_again['applied'] );

// Participant fields.
$a333_f = EventsPage::save_participant_field( array( 'ticket_type_id' => $a333_types[0], 'field_key' => 'firma', 'label' => 'Firma', 'field_type' => 'text', 'required' => '1', 'retention_days' => '90', 'position' => '1', 'apply_all' => '1' ) );
$a333_fields = $wpdb->get_results( "SELECT ticket_type_id,label,required FROM {$p}wpcap_participant_fields WHERE field_key='firma' AND ticket_type_id IN (" . implode( ',', array_merge( $a333_types, array( $a333_past_type ) ) ) . ') ORDER BY ticket_type_id' );
check( 'A333: participant field created on the same-named type of every upcoming date', is_array( $a333_f ) && 2 === $a333_f['applied'] && 3 === count( $a333_fields ) && ! in_array( $a333_past_type, array_map( 'intval', array_column( $a333_fields, 'ticket_type_id' ) ), true ) );
$a333_f2 = EventsPage::save_participant_field( array( 'field_id' => $a333_f['field_id'], 'label' => 'Firma / Verein', 'field_type' => 'text', 'retention_days' => '90', 'position' => '1', 'apply_all' => '1' ) );
check( 'A333: editing the field updates it on all dates (matched by key)', is_array( $a333_f2 ) && 2 === $a333_f2['applied'] && 3 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_participant_fields WHERE field_key='firma' AND label='Firma / Verein' AND required=0" ) );

// Form: the checkbox appears with the number of other dates.
$a333_m = new ReflectionMethod( EventsPage::class, 'ticket_type_form' );
$a333_tt = PassCaptain\Admin\Queries::ticket_type( $a333_types[0] );
ob_start();
$a333_m->invoke( null, $a333_tt, PassCaptain\Admin\Queries::occurrence( $a333_occ[0] ) );
$a333_form = ob_get_clean();
check( 'A333: ticket type form offers "an 2 weiteren kommenden Terminen übernehmen"', false !== strpos( $a333_form, 'name="apply_all"' ) && false !== strpos( $a333_form, 'an 2 weiteren kommenden Terminen übernehmen' ) );
check( 'A333: the series module is offered while it is on', EventsPage::series_available() );
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET status='cancelled' WHERE event_id=$a333_event" );

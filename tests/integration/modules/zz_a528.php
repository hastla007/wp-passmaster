<?php
// phpcs:ignoreFile
/**
 * A528: standing spots ("{area}-{n}") that collide with a seat key or with spots of another standing
 * area are refused when the layout is saved or published, instead of disappearing silently.
 */
use PassCaptain\Seating\Layouts;

echo "A528: seating – standing spot key collisions\n";
$a528_base = Layouts::empty_draft();
$a528_draft = static function ( array $groups, array $seats ) use ( $a528_base ) {
	return array_merge( $a528_base, array( 'groups' => $groups, 'seats' => $seats ) );
};
$a528_seat = static fn( $key ) => array( 'key' => $key, 'label' => '1', 'row' => 'A', 'x' => 10, 'y' => 10 );
$a528_area = static fn( $key, $capacity ) => array( 'key' => $key, 'type' => 'standing', 'label' => 'Stehen', 'capacity' => $capacity, 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100 );

$a528_ok = Layouts::normalize( $a528_draft( array( $a528_area( 'steh', 3 ) ), array( $a528_seat( 'a-1' ), $a528_seat( 'a-2' ) ) ) );
check( 'A528: a layout without collisions expands to every seat and spot', ! is_wp_error( $a528_ok ) && 5 === count( Layouts::expand( $a528_ok ) ) );

$a528_seat_clash = Layouts::normalize( $a528_draft( array( $a528_area( 'steh', 3 ) ), array( $a528_seat( 'steh-2' ) ) ) );
check( 'A528: a seat with a standing spot key is refused with the key named', is_wp_error( $a528_seat_clash ) && 'wpcap_seating_standing_key' === $a528_seat_clash->get_error_code() && false !== strpos( $a528_seat_clash->get_error_message(), 'steh-2' ) && 400 === ( $a528_seat_clash->get_error_data()['status'] ?? 0 ) );

$a528_long = str_repeat( 'x', 54 );
$a528_area_clash = Layouts::normalize( $a528_draft( array( $a528_area( $a528_long . 'AAAA', 2 ), $a528_area( $a528_long . 'BBBB', 2 ) ), array() ) );
check( 'A528: two standing areas whose keys share the first 54 characters are refused', is_wp_error( $a528_area_clash ) && 'wpcap_seating_standing_key' === $a528_area_clash->get_error_code() );

$a528_nested = Layouts::normalize( $a528_draft( array( $a528_area( 'z', 12 ), $a528_area( 'z-1', 2 ) ), array() ) );
check( 'A528: spots "z-1…z-12" and area "z-1" (spots "z-1-1") do not collide', ! is_wp_error( $a528_nested ) && 14 === count( Layouts::expand( $a528_nested ) ) );
check( 'A528: the spot key format is unchanged for published layouts', 'steh-7' === Layouts::standing_key( 'steh', 7 ) );

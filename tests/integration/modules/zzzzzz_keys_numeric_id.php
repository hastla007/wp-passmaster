<?php
/**
 * Regression: signing-key ids are 16 hex characters; an id of digits only (about 1 in 1,800 keys)
 * becomes an integer array key in PHP. Strict id comparisons then dropped the freshly created active
 * key (flaky CI: "one active Ed25519 public key is published", TypeError in SignedQr::sign()).
 */
use PassCaptain\Security\SignedQr;
use PassCaptain\OfflineScanner\Keys;

echo "Signing keys with an all-digit key id\n";
$kn_rename = static function ( $option ) {
	$data = get_option( $option, array() );
	$old = (string) $data['active'];
	$new = '5139970823570999';
	$data['keys'][ $new ] = $data['keys'][ $old ]; // PHP stores this as an integer key.
	unset( $data['keys'][ $old ] );
	$data['active'] = $new;
	update_option( $option, $data, false );
	return $new;
};

SignedQr::rotate( true, 'test' );
$kn_qr = $kn_rename( SignedQr::OPTION );
$kn_pub = SignedQr::public_keys();
check( 'QR keys: the active key with a numeric id is still published as active', 1 === count( array_filter( $kn_pub, static function ( $k ) use ( $kn_qr ) { return $kn_qr === $k['key_id'] && 'active' === $k['status']; } ) ) );
$kn_rot = SignedQr::rotate( false, 'test' );
$kn_data = get_option( SignedQr::OPTION );
check( 'QR keys: a rotation keeps the new active key and the retired numeric key until it expires', isset( $kn_data['keys'][ $kn_rot['key_id'] ] ) && $kn_rot['key_id'] === $kn_data['active'] && ! ctype_digit( $kn_rot['key_id'] ) );

Keys::rotate( 'test' );
$kn_off = $kn_rename( Keys::OPTION );
$kn_offpub = Keys::public_keys();
check( 'offline keys: the active key with a numeric id is still published as active', 1 === count( array_filter( $kn_offpub, static function ( $k ) use ( $kn_off ) { return $kn_off === $k['key_id'] && 'active' === $k['status']; } ) ) );
$kn_offrot = Keys::rotate( 'test' );
$kn_offdata = get_option( Keys::OPTION );
check( 'offline keys: a rotation keeps the new active key', isset( $kn_offdata['keys'][ $kn_offrot['key_id'] ] ) && $kn_offrot['key_id'] === $kn_offdata['active'] );

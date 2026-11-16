<?php
/**
 * A629: offline signing keys without lost updates. A rotation that happens while a package is being
 * signed is not undone by the signer's stale copy, the package's key keeps its grace until the
 * package expires, and the latest signed expiry is kept in its own option.
 */
// phpcs:ignoreFile
use PassCaptain\OfflineScanner\Keys;

echo "A629: offline signing keys under concurrency\n";

/** Option store whose next read of the key option runs $hook (another process) after taking its value. */
final class X1_A629_Store {
	public $data = array();
	public $hook = null;
	public function get( $k, $d = false ) {
		$v = array_key_exists( $k, $this->data ) ? $this->data[ $k ] : $d;
		if ( Keys::OPTION === $k && $this->hook ) { $h = $this->hook; $this->hook = null; $h(); }
		return $v;
	}
	public function update( $k, $v ) { $this->data[ $k ] = $v; return true; }
	public function delete( $k ) { unset( $this->data[ $k ] ); return true; }
}
$x1_saved_store = $GLOBALS['wpcap_test_option_store'] ?? null;
$x1_store = new X1_A629_Store();
$GLOBALS['wpcap_test_option_store'] = $x1_store;

$x1_first = Keys::active_id();
$x1_rotated = null;
$x1_store->hook = static function () use ( &$x1_rotated ) { $x1_rotated = Keys::rotate( 'x1-a629' ); };
$x1_expires = time() + 20 * DAY_IN_SECONDS;
$x1_signed = null;
try { $x1_signed = Keys::sign( 'x1-a629-package', $x1_expires ); } catch ( \Throwable $e ) { $x1_signed = $e; }
$x1_after = $x1_store->data[ Keys::OPTION ] ?? array();
check( 'A629: signing works while another process rotates the key', is_array( $x1_signed ) && $x1_first === $x1_signed['key_id'] );
check( 'A629: the rotation is not undone by the signer (new key stays active)', is_array( $x1_rotated ) && $x1_rotated['key_id'] === ( $x1_after['active'] ?? '' ) && isset( $x1_after['keys'][ $x1_rotated['key_id'] ] ) );
check( 'A629: the old key keeps no secret and stays published until the package expires', '' === (string) ( $x1_after['keys'][ $x1_first ]['secret'] ?? 'x' ) && (int) ( $x1_after['keys'][ $x1_first ]['valid_until'] ?? 0 ) >= $x1_expires );
$x1_pub = null;
foreach ( Keys::public_keys() as $k ) { if ( $k['key_id'] === $x1_first ) { $x1_pub = $k; } }
check( 'A629: devices can still verify the package (retired key in the public list)', $x1_pub && 'retired' === $x1_pub['status'] && is_array( $x1_signed ) && sodium_crypto_sign_verify_detached( base64_decode( $x1_signed['signature'] ), 'x1-a629-package', base64_decode( $x1_pub['public_key'] ) ) );

// Expiry kept separately; signing does not write the key option.
$x1_active = Keys::active_id();
$x1_keys_before = $x1_store->data[ Keys::OPTION ];
Keys::sign( 'x1-a629-2', time() + 30 * DAY_IN_SECONDS );
check( 'A629: signing records the expiry in its own option and leaves the key option untouched', $x1_keys_before === $x1_store->data[ Keys::OPTION ] && (int) ( $x1_store->data[ Keys::EXPIRY_OPTION ][ $x1_active ] ?? 0 ) >= time() + 30 * DAY_IN_SECONDS - 5 );
$x1_r2 = Keys::rotate( 'x1-a629-2' );
check( 'A629: a rotation uses the separately stored expiry for the grace', $x1_r2['previous_valid_until'] >= time() + 30 * DAY_IN_SECONDS - 5 );

// Two first-time callers: the second one finds the key of the first instead of rotating again.
$x1_store->data = array();
$x1_inner = null;
$x1_store->hook = static function () use ( &$x1_inner ) { $x1_inner = Keys::active_id(); };
$x1_outer = Keys::active_id();
check( 'A629: parallel first use creates one key, not two that overwrite each other', $x1_inner === $x1_outer && 1 === count( $x1_store->data[ Keys::OPTION ]['keys'] ?? array() ) );

if ( null === $x1_saved_store ) { unset( $GLOBALS['wpcap_test_option_store'] ); } else { $GLOBALS['wpcap_test_option_store'] = $x1_saved_store; }

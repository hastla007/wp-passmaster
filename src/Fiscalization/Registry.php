<?php
/** Fiscal provider registry. */
namespace PassCaptain\Fiscalization;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Registry {
	/** @return AdapterInterface[] */
	public static function all() {
		$adapters = apply_filters( 'wpcap_fiscal_adapters', array() );
		$out = array();
		foreach ( (array) $adapters as $adapter ) {
			if ( $adapter instanceof AdapterInterface && preg_match( '/^[a-z0-9_]{2,64}$/', (string) $adapter->id() ) ) {
				$out[ $adapter->id() ] = $adapter;
			}
		}
		return $out;
	}

	/** @return AdapterInterface|null */
	public static function get( $id ) {
		$adapters = self::all();
		return $adapters[ sanitize_key( $id ) ] ?? null;
	}
}

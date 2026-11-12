<?php
/** Versioned fiscal provider registry. */
namespace PassCaptain\Fiscalization;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Registry {
	/** @return AdapterInterface[] */
	public static function all() {
		$adapters = apply_filters( 'wpcap_fiscal_adapters', array() );
		$out = array();
		foreach ( (array) $adapters as $adapter ) {
			if ( ! $adapter instanceof AdapterInterface ) { continue; }
			$id = (string) $adapter->id();
			if ( ! preg_match( '/^[a-z0-9_]{2,64}$/', $id ) || $id !== sanitize_key( $id ) ) { continue; }
			if ( AdapterInterface::VERSION !== (int) $adapter->interface_version() ) { continue; }
			$methods = array( 'countries', 'capabilities', 'health_check', 'query_status', 'submit' );
			$valid = true;
			foreach ( $methods as $method ) { if ( ! is_callable( array( $adapter, $method ) ) ) { $valid = false; break; } }
			if ( ! $valid ) { continue; }
			$capabilities = array_filter( array_map( 'sanitize_key', (array) $adapter->capabilities() ) );
			if ( ! in_array( 'receipt_submit', $capabilities, true ) || ! in_array( 'operation_status', $capabilities, true ) ) { continue; }
			$out[ $id ] = $adapter;
		}
		ksort( $out );
		return $out;
	}

	/** @return AdapterInterface|null */
	public static function get( $id ) {
		$adapters = self::all();
		return $adapters[ sanitize_key( $id ) ] ?? null;
	}
}

<?php
/** Built-in provider adapter catalog. */
namespace PassCaptain\Fiscalization\Providers;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ProviderAdapters {
	public static function register( $adapters ) {
		$adapters = is_array( $adapters ) ? $adapters : array();
		foreach ( array( new EfstaEfrAdapter(), new FiskaltrustAdapter(), new FiskalySignAtAdapter(), new FiskalySignDeAdapter(), new FiskalyUnifiedAdapter() ) as $adapter ) {
			if ( ! isset( $adapters[ $adapter->id() ] ) ) { $adapters[ $adapter->id() ] = $adapter; }
		}
		return $adapters;
	}
}

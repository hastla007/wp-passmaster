<?php
/** Registry of payment adapters. Third parties add adapters via the `wpcap_payment_gateways` filter. */
namespace PassCaptain\Payments;

use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Registry {
	private static $gateways = null;

	/** All registered gateways keyed by id. */
	public static function all() {
		if ( null === self::$gateways ) {
			$list = array( new ManualGateway() );
			foreach ( array( 'StripeGateway', 'PayPalGateway' ) as $class ) {
				$fqcn = __NAMESPACE__ . '\\' . $class;
				if ( class_exists( $fqcn ) ) { $list[] = new $fqcn(); }
			}
			$list = apply_filters( 'wpcap_payment_gateways', $list );
			self::$gateways = array();
			foreach ( $list as $gateway ) {
				if ( $gateway instanceof GatewayInterface ) { self::$gateways[ $gateway->id() ] = $gateway; }
			}
		}
		return self::$gateways;
	}

	/** One gateway or null. */
	public static function get( $id ) {
		$all = self::all();
		return $all[ (string) $id ] ?? null;
	}

	/** Gateways enabled in the settings and available for the currency. */
	public static function enabled( $currency ) {
		$enabled = (array) Settings::get( 'enabled_providers' );
		$out = array();
		foreach ( self::all() as $id => $gateway ) {
			if ( in_array( $id, $enabled, true ) && $gateway->is_available( $currency ) ) { $out[ $id ] = $gateway; }
		}
		return $out;
	}

	/** Test helper. */
	public static function reset() { self::$gateways = null; }
}

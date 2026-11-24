<?php
/** Registry of payment adapters. Third parties add adapters via the `wpcap_payment_gateways` filter. */
namespace PassCaptain\Payments;

use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
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

	/**
	 * Error for a buyer whose payment could not be started (A642). Kept for callers of the earlier API;
	 * delegates to StartFailures::handle() (support log with Fehler-ID, audit entry, generic code).
	 *
	 * @param \WP_Error $error    Gateway error.
	 * @param int       $order_id Order.
	 * @param string    $provider Gateway id.
	 * @return \WP_Error wpcap_payment_start_failed (502).
	 */
	public static function public_start_error( \WP_Error $error, $order_id, $provider ) {
		return StartFailures::handle( (string) $provider, (int) $order_id, $error );
	}

	/** Test helper. */
	public static function reset() { self::$gateways = null; }
}

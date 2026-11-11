<?php
/** Plugin settings. Secrets are preferably defined as constants in wp-config.php. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Settings {
	const OPTION = 'wpcap_settings';

	/** Default values for every setting. */
	public static function defaults() {
		return array(
			'hold_minutes'              => 10,
			'manual_hold_hours'         => 72,
			'enabled_providers'         => array( 'manual' ),
			'manual_instructions'       => '',
			'stripe_mode'               => 'test',
			'stripe_secret_key'         => '',
			'stripe_webhook_secret'     => '',
			'paypal_mode'               => 'sandbox',
			'paypal_client_id'          => '',
			'paypal_client_secret'      => '',
			'paypal_webhook_id'         => '',
			'terms_text'                => '',
			'consumer_info_text'        => '',
			'require_terms'             => 0,
			'refund_approval_threshold' => 0,
			'require_2fa_for_refunds'   => 0,
			'attendee_retention_days'   => 0,
			'attendee_edit_hours'       => 24,
			'seller_name'               => '',
			'seller_address'            => '',
			'seller_vat_id'             => '',
			'invoice_prefix'            => 'WPC',
			'credit_note_prefix'        => 'GS',
			'auto_invoice'              => 0,
			'delete_data_on_uninstall'  => 0,
			'scanner_show_attendee'     => 0,
			'device_max_hours'          => 24,
			'auto_refund_cancelled'     => 0,
		);
	}

	/** All settings merged with defaults. */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/** One setting. */
	public static function get( $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/** Persist already sanitised values (unknown keys are ignored). */
	public static function update( array $values ) {
		$values = array_intersect_key( $values, self::defaults() );
		update_option( self::OPTION, array_merge( self::all(), $values ), false );
	}

	/**
	 * Secret lookup: constant (e.g. WPCAP_STRIPE_SECRET_KEY) > environment variable > stored option.
	 *
	 * @param string $key Settings key, e.g. "stripe_secret_key".
	 */
	public static function secret( $key ) {
		$constant = 'WPCAP_' . strtoupper( $key );
		if ( defined( $constant ) && '' !== (string) constant( $constant ) ) { return (string) constant( $constant ); }
		$env = getenv( $constant );
		if ( is_string( $env ) && '' !== $env ) { return $env; }
		return (string) self::get( $key );
	}

	/** Version hash of the legal texts currently shown in the checkout (stored with each order). */
	public static function terms_version() {
		$text = trim( (string) self::get( 'terms_text' ) ) . "\n--\n" . trim( (string) self::get( 'consumer_info_text' ) );
		return "\n--\n" === $text ? '' : substr( hash( 'sha256', $text ), 0, 16 );
	}
}

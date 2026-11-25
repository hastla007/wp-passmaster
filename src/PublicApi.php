<?php
/**
 * Public, versioned add-on API (A663): the hooks add-ons (e.g. the premium add-on) and other plugins may
 * rely on. Everything else in docs/HOOKS.md is an internal extension point that can change in a minor
 * release.
 *
 * Version rule (like docs/CONTRACTS.md): MAJOR.MINOR.PATCH. A minor version only adds hooks or
 * arguments at the end; a public hook is never removed, renamed or given other argument types within
 * a major version. A hook that is to go away stays deprecated (apply_filters_deprecated /
 * do_action_deprecated) for at least one minor release and is removed only with the next major.
 *
 * The add-on checks the version on `wpcap_loaded` (argument) or with PublicApi::compatible(). The list
 * below is compared with the code and with the public table in docs/HOOKS.md by tests/consistency.php.
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class PublicApi {
	const VERSION = '1.1.0'; // 1.1.0: wpcap_woocommerce_hold_fulfilment.

	/**
	 * Public hooks: name => [type (action|filter), since (API version), area].
	 * Areas: bootstrap, admin, settings, checkout, orders, tickets, mail, rest, reports, exports, payments, scanner.
	 * An integration that relies on a hook added in a minor version checks isset( PublicApi::hooks()[ $hook ] )
	 * (or version_compare( PublicApi::VERSION, <since>, '>=' )), not only the major version.
	 */
	const HOOKS = array(
		'wpcap_loaded'                        => array( 'action', '1.0', 'bootstrap' ),
		'wpcap_admin_pages'                   => array( 'filter', '1.0', 'admin' ),
		'wpcap_settings_tabs'                 => array( 'filter', '1.0', 'settings' ),
		'wpcap_checkout_validate'             => array( 'filter', '1.0', 'checkout' ),
		'wpcap_order_created'                 => array( 'action', '1.0', 'orders' ),
		'wpcap_order_paid'                    => array( 'action', '1.0', 'orders' ),
		'wpcap_order_cancelled'               => array( 'action', '1.0', 'orders' ),
		'wpcap_order_refunded'                => array( 'action', '1.0', 'orders' ),
		'wpcap_refund_completed'              => array( 'action', '1.0', 'orders' ),
		'wpcap_refund_reversed'               => array( 'action', '1.0', 'orders' ),
		'wpcap_tickets_issued'                => array( 'action', '1.0', 'tickets' ),
		'wpcap_ticket_revoked'                => array( 'action', '1.0', 'tickets' ),
		'wpcap_ticket_transferred'            => array( 'action', '1.0', 'tickets' ),
		'wpcap_event_cancelled'               => array( 'action', '1.0', 'tickets' ),
		'wpcap_ticket_checked_in'             => array( 'action', '1.0', 'scanner' ),
		'wpcap_ticket_checked_out'            => array( 'action', '1.0', 'scanner' ),
		'wpcap_occurrence_changed'            => array( 'action', '1.0', 'tickets' ),
		'wpcap_occurrence_cancelled'          => array( 'action', '1.0', 'tickets' ),
		'wpcap_ticket_pdf_data'               => array( 'filter', '1.0', 'tickets' ),
		'wpcap_mail_recipients'               => array( 'filter', '1.0', 'mail' ),
		'wpcap_rest_prepare_event'            => array( 'filter', '1.0', 'rest' ),
		'wpcap_rest_prepare_order'            => array( 'filter', '1.0', 'rest' ),
		'wpcap_rest_prepare_developer_event'  => array( 'filter', '1.0', 'rest' ),
		'wpcap_report_data'                   => array( 'filter', '1.0', 'reports' ),
		'wpcap_reports_render'                => array( 'action', '1.0', 'reports' ),
		'wpcap_export_formats'                => array( 'filter', '1.0', 'exports' ),
		'wpcap_payment_gateways'              => array( 'filter', '1.0', 'payments' ),
		'wp_pass_captain_checkin_service_v1'  => array( 'filter', '1.0', 'scanner' ),
		// Keeps the tickets of a paid WooCommerce order back (Voucher Captain: gift card not chargeable); the hold
		// itself exists since 1.4 (OrderAdapter::HOLD_FULFILMENT = 1), public and versioned since API 1.1.
		'wpcap_woocommerce_hold_fulfilment'   => array( 'filter', '1.1', 'orders' ),
	);

	/**
	 * Public global functions of src/functions.php: name => extension contract version (WPCAP_CONTRACT_VERSION)
	 * that introduced it. Compared with src/functions.php and docs/EXTENSIONS.md by
	 * tests/integration/modules/zzzzzzzz_r16_contract.php.
	 */
	const FUNCTIONS = array(
		'wpcap_get_invoice'              => '1.1.0',
		'wpcap_confirm_pos_payment'      => '1.1.0',
		'wpcap_api_request_can'          => '1.1.0',
		'wpcap_get_ticket'               => '1.1.0',
		'wpcap_get_order_state'          => '1.3.0',
		'wpcap_get_ticket_state'         => '1.3.0',
		'wpcap_get_refund_state'         => '1.3.0',
		'wpcap_get_occurrence_state'     => '1.3.0',
		'wpcap_get_event_state'          => '1.3.0',
		'wpcap_get_ticket_details'       => '1.4.0', // PASS-E17-01: read service for connectors.
		'wpcap_get_order_ticket_details' => '1.4.0',
		'wpcap_get_ticket_ids'           => '1.5.0', // C9: ticket IDs per occurrence, event or ticket type.
		'wpcap_get_event_catalog'        => '1.5.0', // C9: events and ticket types, also without future dates.
		'wpcap_connector_audit'          => '1.4.0', // PASS-E17-01: audit entry of a connector.
		'wpcap_set_connector_enabled'    => '1.4.0', // PASS-E17-01: opt-in instead of writing the option.
		'wpcap_is_connector_enabled'     => '1.4.0',
		'wpcap_resend_ticket_mail'       => '1.4.0', // PC-7: public actions (contract actions 1.0.0).
		'wpcap_revoke_ticket'            => '1.4.0',
		'wpcap_cancel_event'             => '1.4.0',
		'wpcap_reschedule_occurrence'    => '1.4.0',
		'wpcap_confirm_attendance'       => '1.4.0',
	);

	/** Public global functions (see FUNCTIONS). */
	public static function functions() {
		return self::FUNCTIONS;
	}

	/** Public hooks (see HOOKS). */
	public static function hooks() {
		return self::HOOKS;
	}

	/** Whether an add-on built against $version (e.g. "1.0", "1.3.2") can run on this API. */
	public static function compatible( $version ) {
		return Contracts::compatible( $version, self::VERSION );
	}

	/** Fires wpcap_loaded once, after the core registered all its hooks (end of Plugin::run()). */
	public static function loaded() {
		if ( did_action( 'wpcap_loaded' ) ) { return; }
		/** Core is ready; add-ons register here. Argument: public API version. */
		do_action( 'wpcap_loaded', self::VERSION );
	}
}

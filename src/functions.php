<?php
/**
 * Global convenience functions of the public add-on API (thin wrappers around PassCaptain\Api\*).
 * Loaded by pass-captain.php; guarded so an add-on may ship identical fallbacks.
 *
 * @package PassCaptain
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'wpcap_get_invoice' ) ) {
	/**
	 * Invoice document as a stable array (contract version 1), see PassCaptain\Api\Invoices::get().
	 *
	 * @param int    $id   Document id (invoice_id, credit_note_id or settlement_id).
	 * @param string $type invoice|correction|settlement.
	 * @return array<string,mixed>|null
	 */
	function wpcap_get_invoice( $id, $type = 'invoice' ) {
		return PassCaptain\Api\Invoices::get( $id, $type );
	}
}

if ( ! function_exists( 'wpcap_confirm_pos_payment' ) ) {
	/**
	 * Confirms the card-terminal payment of a box office sale (e.g. from a verified provider webhook),
	 * see PassCaptain\BoxOffice\Service::confirm_device_payment(). Idempotent; books the sale once.
	 *
	 * @param int|string $order_id  Order ID or the sale_key passed to DeviceGatewayInterface::start().
	 * @param string     $reference Payment reference of the provider.
	 * @param array      $payment   Optional (contract 1.1): amount_minor and currency actually charged;
	 *                              a difference to the order is never booked (amount_mismatch).
	 * @return true|WP_Error
	 */
	function wpcap_confirm_pos_payment( $order_id, $reference, $payment = array() ) {
		return PassCaptain\BoxOffice\Service::confirm_device_payment( (string) $order_id, (string) $reference, (array) $payment );
	}
}

if ( ! function_exists( 'wpcap_api_request_can' ) ) {
	/**
	 * Checks the developer API key of a REST request for a scope (and event), see PassCaptain\Api\Auth::request_can().
	 *
	 * @param WP_REST_Request $request  REST request with header X-WPCAP-Key.
	 * @param string          $scope    Scope, e.g. "orders.write".
	 * @param int|null        $event_id Event; null = parameter event_id of the request.
	 * @return true|WP_Error
	 */
	function wpcap_api_request_can( WP_REST_Request $request, $scope, $event_id = null ) {
		return PassCaptain\Api\Auth::request_can( $request, $scope, $event_id );
	}
}

if ( ! function_exists( 'wpcap_get_ticket' ) ) {
	/**
	 * Ticket → order, event, occurrence without personal data (contract ticket_read 1.x, A709),
	 * see PassCaptain\Api\Tickets::get().
	 *
	 * @param int|string $ticket Ticket id (int) or public ticket id (string).
	 * @return array<string,mixed>|null
	 */
	function wpcap_get_ticket( $ticket ) {
		return PassCaptain\Api\Tickets::get( $ticket );
	}
}

/*
 * Read facade (contract 1.3.0): state of orders, tickets, refunds, occurrences and events as flat arrays
 * without personal data or codes, see PassCaptain\Api\ReadFacade. Pure reads without capability checks:
 * the calling plugin decides who may see the result. Null when the record does not exist.
 */

if ( ! function_exists( 'wpcap_get_order_state' ) ) {
	/**
	 * State of an order, see PassCaptain\Api\ReadFacade::order().
	 *
	 * @param int|string $id Order ID or public_id (32 hex characters).
	 * @return array<string,mixed>|null
	 */
	function wpcap_get_order_state( $id ) {
		return PassCaptain\Api\ReadFacade::order( $id );
	}
}

if ( ! function_exists( 'wpcap_get_ticket_state' ) ) {
	/**
	 * State of a ticket, see PassCaptain\Api\ReadFacade::ticket().
	 *
	 * @param int|string $id Ticket ID or public_id (32 hex characters).
	 * @return array<string,mixed>|null
	 */
	function wpcap_get_ticket_state( $id ) {
		return PassCaptain\Api\ReadFacade::ticket( $id );
	}
}

if ( ! function_exists( 'wpcap_get_refund_state' ) ) {
	/**
	 * State of a refund, see PassCaptain\Api\ReadFacade::refund().
	 *
	 * @param int|string $id Refund ID.
	 * @return array<string,mixed>|null
	 */
	function wpcap_get_refund_state( $id ) {
		return PassCaptain\Api\ReadFacade::refund( $id );
	}
}

if ( ! function_exists( 'wpcap_get_occurrence_state' ) ) {
	/**
	 * State of an occurrence, see PassCaptain\Api\ReadFacade::occurrence().
	 *
	 * @param int|string $id Occurrence ID.
	 * @return array<string,mixed>|null
	 */
	function wpcap_get_occurrence_state( $id ) {
		return PassCaptain\Api\ReadFacade::occurrence( $id );
	}
}

if ( ! function_exists( 'wpcap_get_event_state' ) ) {
	/**
	 * State of an event, see PassCaptain\Api\ReadFacade::event().
	 *
	 * @param int|string $id Event ID.
	 * @return array<string,mixed>|null
	 */
	function wpcap_get_event_state( $id ) {
		return PassCaptain\Api\ReadFacade::event( $id );
	}
}

/*
 * Read service for connectors (contract 1.4.0, PASS-E17-01): a ticket or an order with occurrence, event,
 * holder and check-ins, see PassCaptain\Api\TicketDetails. The holder's e-mail address only with the
 * capability wpcap_read_holder_email (audited). Null when the record does not exist.
 */

if ( ! function_exists( 'wpcap_get_ticket_details' ) ) {
	/**
	 * Details of a ticket, see PassCaptain\Api\TicketDetails::ticket().
	 *
	 * @param int|string $id   Ticket ID or public_id (32 hex characters).
	 * @param array      $args include_email, user_id, source.
	 * @return array<string,mixed>|null
	 */
	function wpcap_get_ticket_details( $id, $args = array() ) {
		return PassCaptain\Api\TicketDetails::ticket( $id, (array) $args );
	}
}

if ( ! function_exists( 'wpcap_get_order_ticket_details' ) ) {
	/**
	 * Details of an order and its tickets, see PassCaptain\Api\TicketDetails::order().
	 *
	 * @param int|string $id   Order ID or public_id (32 hex characters).
	 * @param array      $args include_email, user_id, source.
	 * @return array<string,mixed>|null
	 */
	function wpcap_get_order_ticket_details( $id, $args = array() ) {
		return PassCaptain\Api\TicketDetails::order( $id, (array) $args );
	}
}

/*
 * Contract 1.5.0 (C9): ticket IDs per occurrence, event or ticket type and the event catalogue for connectors,
 * see PassCaptain\Api\TicketDetails. No personal data; null for invalid arguments.
 */

if ( ! function_exists( 'wpcap_get_ticket_ids' ) ) {
	/**
	 * Public IDs of the tickets of an occurrence, event or ticket type, see PassCaptain\Api\TicketDetails::ticket_ids().
	 *
	 * @param array $args occurrence_id|event_id|ticket_type_id, statuses, limit, after.
	 * @return array<string,mixed>|null
	 */
	function wpcap_get_ticket_ids( $args = array() ) {
		return PassCaptain\Api\TicketDetails::ticket_ids( (array) $args );
	}
}

if ( ! function_exists( 'wpcap_get_event_catalog' ) ) {
	/**
	 * Events with their ticket types, also without future occurrences, see PassCaptain\Api\TicketDetails::catalog().
	 *
	 * @param array $args statuses, limit, after.
	 * @return array<string,mixed>|null
	 */
	function wpcap_get_event_catalog( $args = array() ) {
		return PassCaptain\Api\TicketDetails::catalog( (array) $args );
	}
}

if ( ! function_exists( 'wpcap_connector_audit' ) ) {
	/**
	 * Audit entry of a connector without personal data (contract 1.4.0, PASS-E17-01), see
	 * PassCaptain\Integrations\ConnectorAudit::record(). Rate-limited per connector.
	 *
	 * @param string $source      Key of a connector registered through wp_pass_captain_connectors_v1.
	 * @param string $action      Action code, stored as connector_log_<action>.
	 * @param string $object_type connector|order|ticket|occurrence|event|refund ('' = connector).
	 * @param int    $object_id   Object ID.
	 * @param array  $details     Flat details: code keys, values int/float/bool/null or codes.
	 * @return true|WP_Error
	 */
	function wpcap_connector_audit( $source, $action, $object_type = '', $object_id = 0, $details = array() ) {
		return PassCaptain\Integrations\ConnectorAudit::record( $source, $action, $object_type, $object_id, $details );
	}
}

if ( ! function_exists( 'wpcap_set_connector_enabled' ) ) {
	/**
	 * Opts a connector in or out (contract 1.4.0, PASS-E17-01) instead of writing the option
	 * wpcap_enabled_connectors, see PassCaptain\Integrations\ConnectorRegistry::set_enabled().
	 * Needs manage_options (or WP-CLI); enabling needs a registered connector; audited.
	 *
	 * @param string $key     Connector key.
	 * @param bool   $enabled True = opt in, false = opt out.
	 * @return true|WP_Error
	 */
	function wpcap_set_connector_enabled( $key, $enabled ) {
		return PassCaptain\Integrations\ConnectorRegistry::set_enabled( $key, $enabled );
	}
}

if ( ! function_exists( 'wpcap_is_connector_enabled' ) ) {
	/**
	 * Whether a connector is opted in (contract 1.4.0), see PassCaptain\Integrations\ConnectorRegistry::is_enabled().
	 *
	 * @param string $key Connector key.
	 * @return bool
	 */
	function wpcap_is_connector_enabled( $key ) {
		return PassCaptain\Integrations\ConnectorRegistry::is_enabled( $key );
	}
}

/*
 * Public write actions (contract actions 1.0.0, WPCAP_CONTRACT_VERSION 1.4.0; PC-7), see PassCaptain\Api\Actions.
 * Each checks the capability of the current user or of $args['user_id'], runs the regular core service,
 * writes an audit entry and returns a result array (with `action`, `replayed`) or a WP_Error. Optional
 * $args['idempotency_key'] (8–64 characters): the same key with the same arguments returns the stored result.
 */

if ( ! function_exists( 'wpcap_resend_ticket_mail' ) ) {
	/**
	 * Sends the ticket mail of an order again (capability wpcap_manage_orders).
	 *
	 * @param int|string $order Order ID or public order ID.
	 * @param array      $args  new_codes (bool, default false), user_id, idempotency_key.
	 * @return array<string,mixed>|WP_Error
	 */
	function wpcap_resend_ticket_mail( $order, $args = array() ) {
		return PassCaptain\Api\Actions::resend_ticket_mail( $order, (array) $args );
	}
}

if ( ! function_exists( 'wpcap_revoke_ticket' ) ) {
	/**
	 * Revokes a ticket without refund (capability wpcap_manage_orders).
	 *
	 * @param int|string $ticket Ticket ID or public ticket ID.
	 * @param string     $reason Reason (required).
	 * @param array      $args   user_id, idempotency_key.
	 * @return array<string,mixed>|WP_Error
	 */
	function wpcap_revoke_ticket( $ticket, $reason, $args = array() ) {
		return PassCaptain\Api\Actions::revoke_ticket( $ticket, $reason, (array) $args );
	}
}

if ( ! function_exists( 'wpcap_cancel_event' ) ) {
	/**
	 * Cancels an event through the regular cancellation (capability wpcap_manage_events).
	 *
	 * @param int    $event_id Event ID.
	 * @param string $reason   Reason (required).
	 * @param array  $args     user_id, idempotency_key.
	 * @return array<string,mixed>|WP_Error
	 */
	function wpcap_cancel_event( $event_id, $reason, $args = array() ) {
		return PassCaptain\Api\Actions::cancel_event( $event_id, $reason, (array) $args );
	}
}

if ( ! function_exists( 'wpcap_reschedule_occurrence' ) ) {
	/**
	 * Moves a date (capability wpcap_manage_events).
	 *
	 * @param int         $occurrence_id Occurrence ID.
	 * @param string      $starts_at     New start, ISO 8601 with time zone.
	 * @param string|null $ends_at       New end (ISO 8601) or null to keep the duration.
	 * @param array       $args          user_id, idempotency_key.
	 * @return array<string,mixed>|WP_Error
	 */
	function wpcap_reschedule_occurrence( $occurrence_id, $starts_at, $ends_at = null, $args = array() ) {
		return PassCaptain\Api\Actions::reschedule_occurrence( $occurrence_id, $starts_at, $ends_at, (array) $args );
	}
}

if ( ! function_exists( 'wpcap_confirm_attendance' ) ) {
	/**
	 * Confirms an allowed attendance without a scan (capability wpcap_checkin).
	 *
	 * @param int|string $ticket Ticket ID or public ticket ID.
	 * @param string     $method manual, list, session or online.
	 * @param array      $args   user_id, idempotency_key.
	 * @return array<string,mixed>|WP_Error
	 */
	function wpcap_confirm_attendance( $ticket, $method = 'manual', $args = array() ) {
		return PassCaptain\Api\Actions::confirm_attendance( $ticket, $method, (array) $args );
	}
}

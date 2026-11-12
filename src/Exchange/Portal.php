<?php
/**
 * Self-service exchange in the buyer's order portal: a form per ticket (when the policy allows it)
 * and the REST endpoint POST /wp-pass-captain/v1/orders/{public_id}/exchange (access token).
 */
namespace PassCaptain\Exchange;

use PassCaptain\Frontend\Format;
use PassCaptain\Payments\Registry;
use PassCaptain\RateLimit;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Portal {
	/** Whether the portal script was printed on this page. */
	private static $script_printed = false;

	public static function register() {
		add_filter( 'wpcap_order_portal_ticket_html', array( __CLASS__, 'ticket_html' ), 10, 4 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route( 'wp-pass-captain/v1', '/orders/(?P<public_id>[a-f0-9]{32})/exchange', array(
			'methods' => 'POST',
			'callback' => array( __CLASS__, 'rest_exchange' ),
			'permission_callback' => '__return_true', // Capability link: the access token is checked in the callback.
		) );
	}

	/** Order row when the public id and the access token match (null otherwise). */
	public static function order_with_access( $public_id, $token ) {
		global $wpdb;
		$public_id = sanitize_text_field( (string) $public_id );
		$token = strtolower( preg_replace( '/[^a-f0-9]/', '', (string) $token ) );
		if ( 32 !== strlen( $public_id ) || 64 !== strlen( $token ) ) { return null; }
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_orders WHERE public_id=%s", $public_id ) );
		if ( ! $order || ! $order->access_expires_at || $order->access_expires_at <= current_time( 'mysql', true ) || ! hash_equals( (string) $order->access_token_hash, hash( 'sha256', $token ) ) ) { return null; }
		return $order;
	}

	/**
	 * Self-service exchange for a buyer with a valid order access link.
	 *
	 * @param string $public_id Order public id.
	 * @param string $token     Access token.
	 * @param array  $in        ticket_public_id, ticket_type_id, idempotency_key, provider.
	 * @return array|\WP_Error
	 */
	public static function exchange( $public_id, $token, array $in ) {
		global $wpdb;
		$order = self::order_with_access( $public_id, $token );
		if ( ! $order ) { return new \WP_Error( 'wpcap_order_access_denied', __( 'Der Bestellzugriff ist ungültig.', 'wp-pass-captain' ), array( 'status' => 403 ) ); }
		if ( ! RateLimit::allow( 'exchange|' . $order->order_id, (int) apply_filters( 'wpcap_exchange_portal_rate_limit', 10 ), HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'wpcap_rate_limited', __( 'Bitte warte kurz und versuche es erneut.', 'wp-pass-captain' ), array( 'status' => 429 ) );
		}
		$ticket_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_id FROM {$wpdb->prefix}wpcap_tickets WHERE public_id=%s AND order_id=%d", sanitize_text_field( (string) ( $in['ticket_public_id'] ?? '' ) ), (int) $order->order_id ) );
		if ( ! $ticket_id ) { return new \WP_Error( 'wpcap_exchange_ticket_missing', __( 'Ticket nicht gefunden.', 'wp-pass-captain' ), array( 'status' => 404 ) ); }
		$result = Service::request( $ticket_id, absint( $in['ticket_type_id'] ?? 0 ), array(
			'channel' => 'portal',
			'idempotency_key' => sanitize_text_field( (string) ( $in['idempotency_key'] ?? '' ) ),
			'provider' => sanitize_key( (string) ( $in['provider'] ?? '' ) ),
		) );
		if ( is_wp_error( $result ) ) { return $result; }
		return self::public_result( $result );
	}

	/** Customer-safe view of an exchange result. */
	public static function public_result( array $row ) {
		$out = array(
			'status' => (string) $row['status'],
			'difference_minor' => (int) $row['difference_minor'],
			'fee_minor' => (int) $row['fee_minor'],
			'refund_minor' => (int) $row['refund_minor'],
			'due_minor' => (int) $row['due_minor'],
			'currency' => (string) $row['currency'],
		);
		if ( Service::PENDING === $row['status'] ) {
			$out['message'] = __( 'Der neue Platz ist für dich reserviert. Bitte bezahle den Aufpreis; das neue Ticket kommt nach Zahlungseingang per E-Mail. Bis dahin bleibt dein bisheriges Ticket gültig.', 'wp-pass-captain' );
			$out['payment'] = array_intersect_key( (array) ( $row['payment'] ?? array() ), array_flip( array( 'provider', 'status', 'redirect_url', 'instructions', 'amount_minor', 'currency', 'hold_minutes' ) ) );
		} elseif ( Service::COMPLETED === $row['status'] ) {
			$out['message'] = __( 'Umtausch erledigt. Das neue Ticket wurde per E-Mail gesendet; der bisherige Ticketcode ist ungültig.', 'wp-pass-captain' );
		}
		if ( ! empty( $row['reused'] ) ) { $out['reused'] = true; }
		if ( ! empty( $row['warning'] ) ) { $out['warning'] = (string) $row['warning']; }
		return $out;
	}

	public static function rest_exchange( \WP_REST_Request $request ) {
		$params = $request->get_json_params() ?: $request->get_params();
		$params = is_array( $params ) ? $params : array();
		$result = self::exchange( (string) $request['public_id'], (string) ( $params['access_token'] ?? '' ), $params );
		if ( is_wp_error( $result ) ) { return $result; }
		return rest_ensure_response( $result );
	}

	/**
	 * Filter wpcap_order_portal_ticket_html: exchange form below a ticket card.
	 *
	 * @param string $html   HTML of other modules.
	 * @param object $ticket Ticket row of the portal (ticket_id, public_id, status, starts_at, …).
	 * @param object $order  Order row.
	 * @param string $token  Verified access token.
	 */
	public static function ticket_html( $html, $ticket = null, $order = null, $token = '' ) {
		if ( ! is_object( $ticket ) || ! is_object( $order ) || 'valid' !== ( $ticket->status ?? '' ) || 'paid' !== $order->payment_status ) { return $html; }
		$options = Service::options( (int) $ticket->ticket_id, 'portal' );
		if ( ! $options ) { return $html; }
		$policy = Policy::for_event( (int) Service::ticket( (int) $ticket->ticket_id )->event_id );
		$providers = array();
		foreach ( (array) Settings::get( 'enabled_providers' ) as $id ) {
			$gateway = Registry::get( $id );
			if ( $gateway && $gateway->is_available( $order->currency ) ) { $providers[ $id ] = $gateway->title(); }
		}
		$out = '<details class="wpcap-exchange"><summary>' . esc_html__( 'Ticket umtauschen', 'wp-pass-captain' ) . '</summary><form class="wpcap-exchange-form" data-ticket="' . esc_attr( $ticket->public_id ) . '">';
		$out .= '<label>' . esc_html__( 'Neuer Termin / neue Ticketart', 'wp-pass-captain' ) . '<select name="ticket_type_id" required>';
		foreach ( $options as $option ) {
			if ( null !== $option['available'] && $option['available'] < 1 ) { continue; }
			$diff = (int) $option['difference_minor'];
			$label = $option['ticket_name'] . ' – ' . Format::event_start( $option['starts_at'], $option['timezone'] ) . ' – ';
			if ( $diff > 0 ) {
				/* translators: %s: amount */
				$label .= sprintf( __( 'Aufpreis %s', 'wp-pass-captain' ), Format::money( $diff, $option['currency'] ) );
			} elseif ( $diff < 0 ) {
				$label .= 'none' === $policy['refund_policy']
					? __( 'günstiger (keine Erstattung)', 'wp-pass-captain' )
					/* translators: %s: amount */
					: sprintf( __( 'Erstattung %s', 'wp-pass-captain' ), Format::money( max( 0, -$diff - (int) $policy['fee_minor'] ), $option['currency'] ) );
			} else {
				$label .= __( 'gleicher Preis', 'wp-pass-captain' );
			}
			$out .= '<option value="' . esc_attr( (string) $option['ticket_type_id'] ) . '">' . esc_html( $label ) . '</option>';
		}
		$out .= '</select></label>';
		if ( (int) $policy['fee_minor'] > 0 ) {
			/* translators: %s: amount */
			$out .= '<p class="muted"><small>' . esc_html( sprintf( __( 'Umtauschgebühr: %s', 'wp-pass-captain' ), Format::money( (int) $policy['fee_minor'], $order->currency ) ) ) . '</small></p>';
		}
		if ( count( $providers ) > 1 ) {
			$out .= '<label>' . esc_html__( 'Zahlungsart für einen Aufpreis', 'wp-pass-captain' ) . '<select name="provider">';
			foreach ( $providers as $id => $title ) { $out .= '<option value="' . esc_attr( $id ) . '"' . ( $id === $order->payment_provider ? ' selected' : '' ) . '>' . esc_html( $title ) . '</option>'; }
			$out .= '</select></label>';
		}
		$out .= '<p class="muted"><small>' . esc_html__( 'Beim Umtausch wird der bisherige Ticketcode ungültig und du erhältst ein neues Ticket per E-Mail. Bei einem Aufpreis gilt das neue Ticket erst nach Zahlungseingang.', 'wp-pass-captain' ) . '</small></p>';
		if ( $policy['portal_hours'] ) {
			/* translators: %d: hours */
			$out .= '<p class="muted"><small>' . esc_html( sprintf( __( 'Umtausch bis %d Stunden vor Beginn möglich.', 'wp-pass-captain' ), (int) $policy['portal_hours'] ) ) . '</small></p>';
		}
		$out .= '<button type="submit">' . esc_html__( 'Jetzt umtauschen', 'wp-pass-captain' ) . '</button><p class="message" role="status" aria-live="polite"></p></form></details>';
		if ( ! self::$script_printed ) {
			self::$script_printed = true;
			$out .= self::script( (string) $order->public_id, (string) $token );
		}
		return $html . $out;
	}

	private static function script( $public_id, $token ) {
		$strings = array(
			'confirm' => __( 'Ticket wirklich umtauschen? Der bisherige Ticketcode wird ungültig.', 'wp-pass-captain' ),
			'failed' => __( 'Der Umtausch ist fehlgeschlagen.', 'wp-pass-captain' ),
			'sending' => __( 'Wird bearbeitet …', 'wp-pass-captain' ),
		);
		$url = function_exists( 'rest_url' ) ? rest_url( 'wp-pass-captain/v1/orders/' . rawurlencode( $public_id ) . '/exchange' ) : '';
		return '<script>(function(){var S=' . wp_json_encode( $strings ) . ',URL=' . wp_json_encode( $url ) . ',TOKEN=' . wp_json_encode( $token ) . ';'
			. 'function key(){return (window.crypto&&crypto.randomUUID)?crypto.randomUUID():String(Date.now())+Math.random();}'
			. 'document.querySelectorAll(".wpcap-exchange-form").forEach(function(form){var k=key();form.addEventListener("submit",async function(e){e.preventDefault();var m=form.querySelector(".message"),b=form.querySelector("button");if(!window.confirm(S.confirm)){return;}b.disabled=true;m.textContent=S.sending;'
			. 'try{var body={access_token:TOKEN,ticket_public_id:form.dataset.ticket,ticket_type_id:form.ticket_type_id.value,idempotency_key:k};if(form.provider){body.provider=form.provider.value;}'
			. 'var r=await fetch(URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(body)});var d={};try{d=await r.json();}catch(x){}'
			. 'if(!r.ok){k=key();throw new Error(d.message||S.failed);}m.textContent=(d.message||"")+(d.payment&&d.payment.instructions?" "+d.payment.instructions:"");'
			. 'if(d.payment&&d.payment.redirect_url){window.location.href=d.payment.redirect_url;return;}form.querySelectorAll("select,button").forEach(function(n){n.disabled=true;});}catch(err){m.textContent=err.message;b.disabled=false;}});});})();</script>';
	}
}

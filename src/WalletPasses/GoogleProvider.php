<?php
/**
 * Google Wallet: one EventTicketClass per occurrence, one EventTicketObject per ticket (and code
 * generation). Customers add passes through a "Save to Google Wallet" link (RS256 JWT signed with
 * the service account key); later changes go through the Wallet REST API (OAuth2 JWT bearer flow).
 */
namespace PassCaptain\WalletPasses;

use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class GoogleProvider implements WalletProvider {
	const API = 'https://walletobjects.googleapis.com/walletobjects/v1/';
	const SCOPE = 'https://www.googleapis.com/auth/wallet_object.issuer';
	const SAVE_URL = 'https://pay.google.com/gp/v/save/';

	public function id() { return 'google'; }

	public function issuer_id() { return preg_replace( '/[^0-9]/', '', (string) Settings::get( 'wallet_google_issuer_id' ) ); }

	public function is_configured() {
		return (bool) Settings::get( 'wallet_google_enabled' ) && '' !== $this->issuer_id() && is_array( Credentials::google() );
	}

	private static function site_key() { return substr( hash( 'sha256', home_url( '/' ) ), 0, 8 ); }

	public function class_id( $occurrence_id ) { return $this->issuer_id() . '.wpcap_' . self::site_key() . '_o' . absint( $occurrence_id ); }

	public function object_id( array $ticket ) {
		return $this->issuer_id() . '.wpcap_' . self::site_key() . '_t' . preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $ticket['public_id'] ) ) . '_g' . max( 1, (int) ( $ticket['generation'] ?? 1 ) );
	}

	private static function text( $value ) { return array( 'defaultValue' => array( 'language' => 'de', 'value' => (string) $value ) ); }

	private static function iso( $utc, $timezone ) {
		try { $tz = new \DateTimeZone( $timezone ?: 'UTC' ); } catch ( \Exception $e ) { $tz = new \DateTimeZone( 'UTC' ); }
		return ( new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) ) )->setTimezone( $tz )->format( 'Y-m-d\TH:i:sP' );
	}

	public function class_payload( array $ticket ) {
		$class = array(
			'id' => $this->class_id( $ticket['occurrence_id'] ),
			'issuerName' => AppleProvider::organization(),
			'reviewStatus' => 'UNDER_REVIEW',
			'eventName' => self::text( $ticket['event_name'] ),
			'eventId' => 'wpcap-' . self::site_key() . '-' . absint( $ticket['occurrence_id'] ),
			'dateTime' => array( 'start' => self::iso( $ticket['starts_at'], $ticket['timezone'] ) ),
			'hexBackgroundColor' => (string) ( Settings::get( 'wallet_background_color' ) ?: '#1d2327' ),
		);
		if ( $ticket['ends_at'] ) { $class['dateTime']['end'] = self::iso( $ticket['ends_at'], $ticket['timezone'] ); }
		if ( 'cancelled' === ( $ticket['occurrence_status'] ?? '' ) || 'cancelled' === ( $ticket['event_status'] ?? '' ) ) {
			$class['messages'] = array( array( 'id' => 'wpcap_cancelled', 'header' => __( 'Abgesagt', 'wp-pass-captain' ), 'body' => __( 'Die Veranstaltung wurde abgesagt.', 'wp-pass-captain' ) ) );
		}
		if ( '' !== (string) $ticket['location'] ) { $class['venue'] = array( 'name' => self::text( $ticket['location'] ), 'address' => self::text( $ticket['location'] ) ); }
		return apply_filters( 'wpcap_wallet_google_class', $class, $ticket );
	}

	public function object_payload( array $ticket ) {
		$active = empty( $ticket['voided'] ) && '' !== (string) ( $ticket['code'] ?? '' );
		$object = array(
			'id' => $this->object_id( $ticket ),
			'classId' => $this->class_id( $ticket['occurrence_id'] ),
			'state' => $active ? 'ACTIVE' : 'INACTIVE',
			'ticketNumber' => (string) $ticket['public_id'],
			'ticketType' => self::text( $ticket['ticket_name'] ),
		);
		if ( $active ) { $object['barcode'] = array( 'type' => 'QR_CODE', 'value' => (string) $ticket['code'], 'alternateText' => substr( (string) $ticket['public_id'], 0, 8 ) ); }
		if ( '' !== (string) ( $ticket['seat'] ?? '' ) ) { $object['seatInfo'] = array( 'seat' => self::text( $ticket['seat'] ) ); }
		if ( '' !== (string) ( $ticket['attendee_name'] ?? '' ) ) { $object['ticketHolderName'] = (string) $ticket['attendee_name']; }
		return apply_filters( 'wpcap_wallet_google_object', $object, $ticket );
	}

	/** "Save to Google Wallet" URL with the class and object embedded in the JWT. */
	public function save_url( array $ticket ) {
		$account = Credentials::google();
		if ( is_wp_error( $account ) ) { throw new \RuntimeException( $account->get_error_code() ); }
		$claims = array(
			'iss' => $account['client_email'],
			'aud' => 'google',
			'typ' => 'savetowallet',
			'iat' => time(),
			'origins' => array( home_url( '/' ) ),
			'payload' => array( 'eventTicketClasses' => array( $this->class_payload( $ticket ) ), 'eventTicketObjects' => array( $this->object_payload( $ticket ) ) ),
		);
		return self::SAVE_URL . Jwt::sign( $claims, $account['private_key'] );
	}

	/* ---- ProviderInterface ---- */

	public function create_pass( array $ticket ) {
		return array( 'provider' => 'google', 'serial_number' => $this->object_id( $ticket ), 'provider_ref' => $this->class_id( $ticket['occurrence_id'] ), 'save_url' => $this->save_url( $ticket ) );
	}

	/** Brings the object to the current state; creates it when the customer has not saved it yet. */
	public function update_pass( array $ticket, array $provider_pass ) {
		$object = $this->object_payload( $ticket );
		if ( ! empty( $provider_pass['serial_number'] ) ) { $object['id'] = (string) $provider_pass['serial_number']; }
		$res = $this->api( 'PATCH', 'eventTicketObject/' . rawurlencode( $object['id'] ), $object );
		if ( 404 === $res['code'] ) {
			$class = $this->api( 'POST', 'eventTicketClass', $this->class_payload( $ticket ) );
			if ( ! in_array( $class['code'], array( 200, 409 ), true ) ) { return false; }
			$res = $this->api( 'POST', 'eventTicketObject', $object );
			return in_array( $res['code'], array( 200, 409 ), true );
		}
		return 200 === $res['code'];
	}

	public function revoke_pass( array $provider_pass ) {
		$res = $this->api( 'PATCH', 'eventTicketObject/' . rawurlencode( (string) $provider_pass['serial_number'] ), array( 'state' => 'INACTIVE' ) );
		return in_array( $res['code'], array( 200, 404 ), true );
	}

	/** Updates date/venue of an occurrence class (404 = nobody saved a pass yet). */
	public function update_class( array $ticket ) {
		$class = $this->class_payload( $ticket );
		$res = $this->api( 'PATCH', 'eventTicketClass/' . rawurlencode( $class['id'] ), $class );
		return in_array( $res['code'], array( 200, 404 ), true );
	}

	/* ---- REST ---- */

	/** OAuth2 access token via the JWT bearer grant (cached). */
	public function access_token() {
		$cached = get_transient( 'wpcap_wallet_google_token' );
		if ( is_string( $cached ) && '' !== $cached ) { return $cached; }
		$account = Credentials::google();
		if ( is_wp_error( $account ) ) { return ''; }
		$now = time();
		$assertion = Jwt::sign( array( 'iss' => $account['client_email'], 'scope' => self::SCOPE, 'aud' => $account['token_uri'], 'iat' => $now, 'exp' => $now + 3600 ), $account['private_key'] );
		$res = wp_remote_post( $account['token_uri'], array( 'timeout' => 15, 'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ), 'body' => http_build_query( array( 'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion ) ) ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) { return ''; }
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		$token = is_array( $data ) ? (string) ( $data['access_token'] ?? '' ) : '';
		if ( '' !== $token ) { set_transient( 'wpcap_wallet_google_token', $token, max( 60, (int) ( $data['expires_in'] ?? 3600 ) - 120 ) ); }
		return $token;
	}

	/** @return array{code:int,body:mixed} code 0 on transport/auth errors. */
	public function api( $method, $path, array $body ) {
		$token = $this->access_token();
		if ( '' === $token ) { return array( 'code' => 0, 'body' => null ); }
		$res = wp_remote_request( self::API . $path, array( 'method' => $method, 'timeout' => 15, 'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( $body ) ) );
		if ( is_wp_error( $res ) ) { return array( 'code' => 0, 'body' => null ); }
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 401 === $code ) { delete_transient( 'wpcap_wallet_google_token' ); }
		return array( 'code' => $code, 'body' => json_decode( wp_remote_retrieve_body( $res ), true ) );
	}
}

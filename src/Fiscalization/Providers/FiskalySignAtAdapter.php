<?php
/** fiskaly SIGN AT (RKSV) adapter. */
namespace PassCaptain\Fiscalization\Providers;

use PassCaptain\Fiscalization\AdapterInterface;
use PassCaptain\Fiscalization\ProviderHttp;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class FiskalySignAtAdapter implements AdapterInterface {
	const BASE_URL = 'https://rksv.fiskaly.com/api/v1';
	public function id() { return 'fiskaly_sign_at'; }
	public function interface_version() { return AdapterInterface::VERSION; }
	public function countries() { return array( 'AT' ); }
	public function capabilities() { return array( 'receipt_submit', 'operation_status', 'receipt_qr_payload', 'signature_chain', 'journal_export' ); }

	public function health_check( array $seller, array $configuration ) {
		$cash = $this->cash_register_id( $configuration );
		if ( is_wp_error( $cash ) ) { return $cash; }
		$result = $this->request( 'GET', '/cash-register/' . rawurlencode( $cash ), null, $seller, $configuration );
		if ( is_wp_error( $result ) ) { return $result; }
		return array( 'ok' => true, 'state' => sanitize_key( $result['state'] ?? '' ) );
	}

	public function query_status( array $document, array $seller, array $configuration ) {
		$cash = $this->cash_register_id( $configuration );
		if ( is_wp_error( $cash ) ) { return $cash; }
		$id = ProviderHttp::uuid_from_key( $document['idempotency_key'] ?? '' );
		$result = $this->request( 'GET', '/cash-register/' . rawurlencode( $cash ) . '/receipt/' . rawurlencode( $id ), null, $seller, $configuration );
		if ( is_wp_error( $result ) && 404 === (int) $result->get_error_data()['status'] ) { return array( 'status' => 'not_found' ); }
		if ( is_wp_error( $result ) ) { return $result; }
		return $this->normalize( $result, $id );
	}

	public function submit( array $document, array $seller, array $configuration ) {
		$cash = $this->cash_register_id( $configuration );
		if ( is_wp_error( $cash ) ) { return $cash; }
		$schema = $configuration['receipt_schema'] ?? null;
		if ( ! is_array( $schema ) || ! $schema ) { return new \WP_Error( 'fiskaly_at_schema_missing', __( 'Die RKSV-Belegschema-Zuordnung für fiskaly SIGN AT muss fachlich konfiguriert werden.', 'wp-pass-captain' ) ); }
		$id = ProviderHttp::uuid_from_key( $document['idempotency_key'] ?? '' );
		$body = array( 'receipt_type' => 'NORMAL', 'schema' => $this->replace_tokens( $schema, $document, $seller ) );
		$result = $this->request( 'PUT', '/cash-register/' . rawurlencode( $cash ) . '/receipt/' . rawurlencode( $id ), $body, $seller, $configuration );
		return is_wp_error( $result ) ? $result : $this->normalize( $result, $id );
	}

	private function normalize( array $data, $fallback ) {
		$reference = (string) ( $data['receipt_number'] ?? $data['_id'] ?? $data['id'] ?? $fallback );
		$receipt = array();
		if ( is_string( $data['qr_code_data'] ?? null ) && '' !== $data['qr_code_data'] ) { $receipt['qr_payload'] = $data['qr_code_data']; }
		$fields = array();
		foreach ( array( 'receipt_number' => 'Belegnummer', 'cash_register_serial_number' => 'Kassen-ID', 'time_signature' => 'Signaturzeitpunkt' ) as $key => $label ) {
			if ( isset( $data[$key] ) && is_scalar( $data[$key] ) ) { $fields[$label] = (string) $data[$key]; }
		}
		if ( $fields ) { $receipt['fields'] = $fields; }
		return array( 'reference' => substr( sanitize_text_field( $reference ), 0, 191 ), 'receipt' => $receipt );
	}

	private function cash_register_id( array $configuration ) {
		$id = sanitize_text_field( (string) ( $configuration['cash_register_id'] ?? '' ) );
		return preg_match( '/^[a-f0-9-]{36}$/i', $id ) ? $id : new \WP_Error( 'fiskaly_at_register_missing', __( 'Eine konfigurierte fiskaly SIGN AT Kassen-ID ist erforderlich.', 'wp-pass-captain' ) );
	}

	private function replace_tokens( $value, array $document, array $seller ) {
		if ( is_array( $value ) ) { foreach ( $value as $key => $part ) { $value[$key] = $this->replace_tokens( $part, $document, $seller ); } return $value; }
		if ( ! is_string( $value ) ) { return $value; }
		$replacements = array(
			'{ORDER_ID}' => (string) ( $document['order_id'] ?? '' ),
			'{DOCUMENT_KEY}' => (string) ( $document['idempotency_key'] ?? '' ),
			'{GROSS_MINOR}' => (string) ( $document['gross_minor'] ?? 0 ),
			'{TAX_MINOR}' => (string) ( $document['tax_minor'] ?? 0 ),
			'{CURRENCY}' => (string) ( $document['currency'] ?? 'EUR' ),
			'{SELLER_NAME}' => (string) ( $seller['legal_name'] ?? '' ),
		);
		return strtr( $value, $replacements );
	}

	private function request( $method, $path, $body, array $seller, array $configuration ) {
		$credentials = (array) ( $configuration['credentials'] ?? array() );
		if ( empty( $credentials['api_key'] ) || empty( $credentials['api_secret'] ) ) { return new \WP_Error( 'fiskaly_credentials_missing', __( 'fiskaly API-Key und API-Secret fehlen.', 'wp-pass-captain' ) ); }
		$key = 'wpcap_fiskaly_at_token_' . absint( $seller['seller_id'] ?? 0 );
		$token = get_transient( $key );
		if ( ! is_string( $token ) || '' === $token ) {
			$auth = ProviderHttp::request( self::BASE_URL . '/auth', 'POST', array(), array( 'api_key' => $credentials['api_key'], 'api_secret' => $credentials['api_secret'] ) );
			if ( is_wp_error( $auth ) ) { return $auth; }
			$token = (string) ( $auth['data']['access_token'] ?? '' );
			if ( '' === $token ) { return new \WP_Error( 'fiskaly_auth_response_invalid', __( 'fiskaly hat kein Zugriffstoken zurückgegeben.', 'wp-pass-captain' ) ); }
			set_transient( $key, $token, 20 * HOUR_IN_SECONDS );
		}
		$result = ProviderHttp::request( self::BASE_URL . $path, $method, array( 'Authorization' => 'Bearer ' . $token ), $body, 8 );
		if ( is_wp_error( $result ) && 401 === (int) $result->get_error_data()['status'] ) {
			delete_transient( $key );
			return new \WP_Error( 'fiskaly_auth_expired', __( 'fiskaly-Zugriffstoken abgelaufen; Vorgang wird sicher wiederholt.', 'wp-pass-captain' ) );
		}
		return is_wp_error( $result ) ? $result : $result['data'];
	}
}

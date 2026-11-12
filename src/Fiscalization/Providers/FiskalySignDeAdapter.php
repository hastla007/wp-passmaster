<?php
/** fiskaly SIGN DE v2 transaction adapter. */
namespace PassCaptain\Fiscalization\Providers;

use PassCaptain\Fiscalization\AdapterInterface;
use PassCaptain\Fiscalization\ProviderHttp;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class FiskalySignDeAdapter implements AdapterInterface {
	const BASE_URL = 'https://kassensichv-middleware.fiskaly.com/api/v2';
	public function id() { return 'fiskaly_sign_de'; }
	public function interface_version() { return AdapterInterface::VERSION; }
	public function countries() { return array( 'DE' ); }
	public function capabilities() { return array( 'receipt_submit', 'operation_status', 'transaction_start', 'transaction_finish', 'transaction_cancel', 'receipt_qr_payload', 'tse_export' ); }

	public function health_check( array $seller, array $configuration ) {
		$tss = $this->resource_id( $configuration, 'tss_id' );
		if ( is_wp_error( $tss ) ) { return $tss; }
		$result = $this->request( 'GET', '/tss/' . rawurlencode( $tss ), null, $seller, $configuration );
		return is_wp_error( $result ) ? $result : array( 'ok' => true, 'state' => sanitize_key( $result['state'] ?? '' ) );
	}

	public function query_status( array $document, array $seller, array $configuration ) {
		$tx = ProviderHttp::uuid_from_key( $document['idempotency_key'] ?? '' );
		$result = $this->request( 'GET', $this->transaction_path( $document, $configuration, $tx ), null, $seller, $configuration );
		if ( is_wp_error( $result ) && 404 === (int) $result->get_error_data()['status'] ) { return array( 'status' => 'not_found' ); }
		if ( is_wp_error( $result ) ) { return $result; }
		if ( 'FINISHED' === strtoupper( (string) ( $result['state'] ?? '' ) ) ) { return $this->normalize( $result, $tx ); }
		if ( 'ACTIVE' === strtoupper( (string) ( $result['state'] ?? '' ) ) ) { return $this->finish( $document, $seller, $configuration, $tx, max( 2, (int) ( $result['latest_revision'] ?? 1 ) + 1 ) ); }
		return new \WP_Error( 'fiskaly_de_status_unknown', __( 'Der fiskaly-TSE-Transaktionsstatus ist nicht eindeutig.', 'wp-pass-captain' ) );
	}

	public function submit( array $document, array $seller, array $configuration ) {
		$tx = ProviderHttp::uuid_from_key( $document['idempotency_key'] ?? '' );
		$existing = $this->request( 'GET', $this->transaction_path( $document, $configuration, $tx ), null, $seller, $configuration );
		if ( ! is_wp_error( $existing ) ) {
			if ( 'FINISHED' === strtoupper( (string) ( $existing['state'] ?? '' ) ) ) { return $this->normalize( $existing, $tx ); }
			if ( 'ACTIVE' === strtoupper( (string) ( $existing['state'] ?? '' ) ) ) { return $this->finish( $document, $seller, $configuration, $tx, max( 2, (int) ( $existing['latest_revision'] ?? 1 ) + 1 ) ); }
		}
		elseif ( 404 !== (int) $existing->get_error_data()['status'] ) { return $existing; }
		$started = $this->request( 'PUT', $this->transaction_path( $document, $configuration, $tx, 1 ), array( 'state' => 'ACTIVE', 'client_id' => $configuration['client_id'], 'schema' => array() ), $seller, $configuration );
		if ( is_wp_error( $started ) ) { return $started; }
		return $this->finish( $document, $seller, $configuration, $tx, 2 );
	}

	private function finish( array $document, array $seller, array $configuration, $tx, $revision ) {
		$schema = $this->receipt_schema( $document, $configuration );
		if ( is_wp_error( $schema ) ) { return $schema; }
		$body = array( 'state' => 'FINISHED', 'client_id' => $configuration['client_id'], 'schema' => array( 'standard_v1' => array( 'receipt' => $schema ) ) );
		$result = $this->request( 'PUT', $this->transaction_path( $document, $configuration, $tx, $revision ), $body, $seller, $configuration );
		return is_wp_error( $result ) ? $result : $this->normalize( $result, $tx );
	}

	private function receipt_schema( array $document, array $configuration ) {
		$vat_map = (array) ( $configuration['vat_rate_map'] ?? array() );
		$payment_map = (array) ( $configuration['payment_type_map'] ?? array() );
		$taxes = array(); $payments = array(); $total = 0; $tax_total = 0;
		foreach ( (array) ( $document['items'] ?? array() ) as $item ) {
			$rate = (string) (int) ( $item['tax_rate_bp'] ?? 0 );
			$vat_type = sanitize_key( $vat_map[$rate] ?? '' );
			if ( '' === $vat_type ) { return new \WP_Error( 'fiskaly_de_vat_map_missing', __( 'Für einen Steuersatz fehlt die fiskaly SIGN DE VAT-Zuordnung.', 'wp-pass-captain' ) ); }
			$gross = max( 0, (int) ( $item['gross_minor'] ?? 0 ) );
			$tax = min( $gross, max( 0, (int) ( $item['tax_minor'] ?? 0 ) ) );
			$taxes[$vat_type] = ( $taxes[$vat_type] ?? 0 ) + $gross;
			$tax_total += $tax; $total += $gross;
		}
		$method = sanitize_key( $document['payment_method'] ?? 'other' );
		$payment = sanitize_key( $payment_map[$method] ?? '' );
		if ( '' === $payment || ! $taxes || $total !== (int) ( $document['gross_minor'] ?? -1 ) ) { return new \WP_Error( 'fiskaly_de_payment_map_missing', __( 'Die fiskaly SIGN DE Zahlungsart fehlt oder die Belegsummen stimmen nicht.', 'wp-pass-captain' ) ); }
		$vat = array();
		foreach ( $taxes as $type => $gross ) { $vat[] = array( 'vat_rate' => strtoupper( $type ), 'amount' => ProviderHttp::decimal( $gross ) ); }
		$payments[$payment] = $total;
		$currency = strtoupper( sanitize_text_field( (string) ( $document['currency'] ?? 'EUR' ) ) );
		return array(
			'receipt_type' => 'RECEIPT',
			'amounts_per_vat_rate' => $vat,
			'amounts_per_payment_type' => array( array( 'payment_type' => strtoupper( $payment ), 'amount' => ProviderHttp::decimal( $total ), 'currency_code' => $currency ) ),
		);
	}

	private function normalize( array $data, $fallback ) {
		$reference = (string) ( $data['number'] ?? $data['tx_number'] ?? $fallback );
		$receipt = array();
		foreach ( array( 'tss_serial_number' => 'TSE-Seriennummer', 'client_serial_number' => 'Kassen-ID', 'time_end' => 'Signaturzeitpunkt' ) as $key => $label ) {
			if ( isset( $data[$key] ) && is_scalar( $data[$key] ) ) { $receipt['fields'][$label] = (string) $data[$key]; }
		}
		if ( is_string( $data['qr_code_data'] ?? null ) ) { $receipt['qr_payload'] = $data['qr_code_data']; }
		if ( is_string( $data['signature'] ?? null ) ) { $receipt['fields']['Signatur'] = $data['signature']; }
		$out = array( 'reference' => substr( sanitize_text_field( $reference ), 0, 191 ) );
		if ( $receipt ) { $out['receipt'] = $receipt; }
		return $out;
	}

	private function transaction_path( array $document, array $configuration, $tx, $revision = null ) {
		$tss = rawurlencode( (string) $configuration['tss_id'] );
		$path = '/tss/' . $tss . '/tx/' . rawurlencode( $tx );
		if ( null !== $revision ) { $path .= '?tx_revision=' . absint( $revision ); }
		return $path;
	}
	private function resource_id( array $configuration, $key ) {
		$id = sanitize_text_field( (string) ( $configuration[$key] ?? '' ) );
		return preg_match( '/^[a-f0-9-]{36}$/i', $id ) ? $id : new \WP_Error( 'fiskaly_de_resource_missing', __( 'Die fiskaly SIGN DE Ressourcen-ID ist ungültig oder fehlt.', 'wp-pass-captain' ) );
	}
	private function request( $method, $path, $body, array $seller, array $configuration ) {
		$credentials = (array) ( $configuration['credentials'] ?? array() );
		if ( empty( $credentials['api_key'] ) || empty( $credentials['api_secret'] ) ) { return new \WP_Error( 'fiskaly_credentials_missing', __( 'fiskaly API-Key und API-Secret fehlen.', 'wp-pass-captain' ) ); }
		$key = 'wpcap_fiskaly_de_token_' . absint( $seller['seller_id'] ?? 0 );
		$token = get_transient( $key );
		if ( ! is_string( $token ) || '' === $token ) {
			$auth = ProviderHttp::request( self::BASE_URL . '/auth', 'POST', array(), array( 'api_key' => $credentials['api_key'], 'api_secret' => $credentials['api_secret'] ) );
			if ( is_wp_error( $auth ) ) { return $auth; }
			$token = (string) ( $auth['data']['access_token'] ?? '' );
			if ( '' === $token ) { return new \WP_Error( 'fiskaly_auth_response_invalid', __( 'fiskaly hat kein Zugriffstoken zurückgegeben.', 'wp-pass-captain' ) ); }
			set_transient( $key, $token, 20 * HOUR_IN_SECONDS );
		}
		$result = ProviderHttp::request( self::BASE_URL . $path, $method, array( 'Authorization' => 'Bearer ' . $token ), $body, 8 );
		if ( is_wp_error( $result ) && 401 === (int) $result->get_error_data()['status'] ) { delete_transient( $key ); return new \WP_Error( 'fiskaly_auth_expired', __( 'fiskaly-Zugriffstoken abgelaufen.', 'wp-pass-captain' ) ); }
		return is_wp_error( $result ) ? $result : $result['data'];
	}
}

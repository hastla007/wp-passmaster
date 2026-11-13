<?php
/** fiskaltrust POS System API v2 adapter. */
namespace PassCaptain\Fiscalization\Providers;

use PassCaptain\Fiscalization\AdapterInterface;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class FiskaltrustAdapter implements AdapterInterface {
	const ID = 'fiskaltrust_possystem';
	const SANDBOX = 'https://possystem-api-sandbox.fiskaltrust.eu/v2';

	public function id() { return self::ID; }
	public function interface_version() { return AdapterInterface::VERSION; }
	public function countries() { return array( 'AT', 'BE', 'DE', 'ES', 'FR', 'GR', 'IT', 'PL', 'PT' ); }
	public function capabilities() { return array( 'receipt_submit', 'operation_status' ); }

	public function health_check( array $seller, array $configuration ) {
		$headers = $this->headers( $configuration, $this->operation_id( 'health:' . ( $seller['seller_id'] ?? 0 ) ) );
		if ( is_wp_error( $headers ) ) { return $headers; }
		$response = HttpClient::request( HttpClient::endpoint( $configuration, self::SANDBOX, 'echo' ), 'POST', $headers, array( 'Message' => 'Pass Captain connection check' ) );
		if ( is_wp_error( $response ) ) { return $response; }
		if ( ! in_array( $response['status'], array( 200, 201 ), true ) ) {
			return new \WP_Error( 'fiskaltrust_health_failed', __( 'fiskaltrust-Verbindungstest wurde abgelehnt.', 'pass-captain' ) );
		}
		return array( 'ok' => true );
	}

	public function query_status( array $document, array $seller, array $configuration ) {
		$operation_id = $this->operation_id( (string) ( $document['idempotency_key'] ?? '' ) );
		$headers = $this->headers( $configuration, $operation_id );
		if ( is_wp_error( $headers ) ) { return $headers; }
		$url = HttpClient::endpoint( $configuration, self::SANDBOX, 'journal/OperationItem/' . rawurlencode( $operation_id ) );
		$response = HttpClient::request( $url, 'GET', $headers );
		if ( is_wp_error( $response ) ) { return $response; }
		if ( 404 === $response['status'] ) { return array( 'status' => 'not_found' ); }
		if ( 200 !== $response['status'] ) { return new \WP_Error( 'fiskaltrust_status_unknown', __( 'fiskaltrust-Status konnte nicht sicher abgefragt werden.', 'pass-captain' ) ); }
		$item = $response['data'];
		if ( 'done' !== strtolower( (string) ( $item['LastState'] ?? '' ) ) || ! isset( $item['Response'] ) || ! is_string( $item['Response'] ) ) {
			return new \WP_Error( 'fiskaltrust_status_pending', __( 'fiskaltrust verarbeitet den Vorgang noch.', 'pass-captain' ) );
		}
		$receipt = json_decode( $item['Response'], true );
		if ( ! is_array( $receipt ) || (int) ( $item['ResponseCode'] ?? 0 ) >= 400 ) {
			return new \WP_Error( 'fiskaltrust_status_failed', __( 'fiskaltrust meldet keinen erfolgreich abgeschlossenen Beleg.', 'pass-captain' ) );
		}
		return $this->result( $receipt, $document );
	}

	public function submit( array $document, array $seller, array $configuration ) {
		$cases = $this->validate_cases( $configuration, $document );
		if ( is_wp_error( $cases ) ) { return $cases; }
		$operation_id = $this->operation_id( (string) ( $document['idempotency_key'] ?? '' ) );
		$headers = $this->headers( $configuration, $operation_id );
		if ( is_wp_error( $headers ) ) { return $headers; }
		$lines = array();
		foreach ( (array) ( $document['items'] ?? array() ) as $index => $item ) {
			$rate = (int) ( $item['tax_rate_bp'] ?? -1 );
			if ( $rate < 0 || ! isset( $cases['charge'] ) ) { return new \WP_Error( 'fiskaltrust_tax_mapping_missing', __( 'Steuer- oder Belegfall-Zuordnung für fiskaltrust fehlt.', 'pass-captain' ) ); }
			$lines[] = array(
				'Quantity' => max( 1, (int) ( $item['quantity'] ?? 1 ) ),
				'Description' => sanitize_text_field( (string) ( $item['description'] ?? __( 'Ticket', 'pass-captain' ) ) ),
				'Amount' => (float) HttpClient::minor_to_major( (int) ( $item['gross_minor'] ?? 0 ) ),
				'VATRate' => $rate / 100,
				'ftChargeItemCase' => $cases['charge'],
			);
		}
		$payment_method = sanitize_key( $document['payment_method'] ?? 'other' );
		$pay_case = absint( $configuration['payment_cases'][ $payment_method ] ?? $configuration['payment_cases']['default'] ?? 0 );
		if ( ! $pay_case ) { return new \WP_Error( 'fiskaltrust_payment_mapping_missing', __( 'Zahlungsart ist in fiskaltrust nicht zugeordnet.', 'pass-captain' ) ); }
		$reference = (string) ( $document['idempotency_key'] ?? '' );
		$payload = array(
			'cbReceiptReference' => substr( 'WPC-' . $reference, 0, 128 ),
			'cbReceiptMoment' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'cbChargeItems' => $lines,
			'cbPayItems' => array( array( 'Description' => $payment_method, 'Amount' => (float) HttpClient::minor_to_major( (int) ( $document['gross_minor'] ?? 0 ) ), 'ftPayItemCase' => $pay_case ) ),
			'ftReceiptCase' => $cases['receipt'],
		);
		$response = HttpClient::request( HttpClient::endpoint( $configuration, self::SANDBOX, 'sign' ), 'POST', $headers, $payload );
		if ( is_wp_error( $response ) ) { return $response; }
		if ( ! in_array( $response['status'], array( 200, 201 ), true ) ) {
			return new \WP_Error( 'fiskaltrust_submit_failed', __( 'fiskaltrust hat den Beleg nicht bestätigt.', 'pass-captain' ) );
		}
		return $this->result( $response['data'], $document );
	}

	private function validate_cases( array $configuration, array $document ) {
		if ( ! absint( $configuration['ft_receipt_case'] ?? 0 ) || ! absint( $configuration['ft_charge_item_case'] ?? 0 ) ) {
			return new \WP_Error( 'fiskaltrust_cases_missing', __( 'Die von fiskaltrust für das Land freigegebenen Geschäftsfall-Codes fehlen.', 'pass-captain' ) );
		}
		foreach ( (array) ( $document['items'] ?? array() ) as $item ) {
			$rate = (string) (int) ( $item['tax_rate_bp'] ?? -1 );
			if ( ! isset( $configuration['tax_cases'][ $rate ] ) ) {
				return new \WP_Error( 'fiskaltrust_tax_mapping_missing', __( 'Ein Steuersatz ist nicht auf einen fiskaltrust-Belegfall abgebildet.', 'pass-captain' ) );
			}
		}
		return array( 'receipt' => absint( $configuration['ft_receipt_case'] ), 'charge' => absint( $configuration['ft_charge_item_case'] ) );
	}

	private function result( array $data, array $document ) {
		$reference = is_scalar( $data['cbReceiptReference'] ?? null ) ? sanitize_text_field( (string) $data['cbReceiptReference'] ) : 'WPC-' . substr( (string) ( $document['idempotency_key'] ?? '' ), 0, 48 );
		$fields = array();
		foreach ( array( 'ftQueueID', 'ftQueueItemID', 'cbReceiptMoment', 'cbReceiptIdentification', 'cbFiscalNumber' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ) { $fields[ $key ] = (string) $data[ $key ]; }
		}
		return array( 'reference' => $reference, 'receipt' => array( 'fields' => $fields ) );
	}

	private function headers( array $configuration, $operation_id ) {
		$credentials = (array) ( $configuration['credentials'] ?? array() );
		$cashbox = sanitize_text_field( $credentials['cashbox_id'] ?? '' );
		$token = (string) ( $credentials['cashbox_access_token'] ?? '' );
		$possystem = sanitize_text_field( $configuration['possystem_id'] ?? '' );
		if ( '' === $cashbox || '' === $token || '' === $possystem ) {
			return new \WP_Error( 'fiskaltrust_credentials_missing', __( 'fiskaltrust CashBox-Zugang und POS-System-ID fehlen.', 'pass-captain' ) );
		}
		return array(
			'x-cashbox-id' => $cashbox,
			'x-cashbox-accesstoken' => $token,
			'x-possystem-id' => $possystem,
			'x-operation-id' => $operation_id,
		);
	}

	private function operation_id( $key ) {
		$bytes = substr( hash( 'sha256', 'wpcap-fiskaltrust|' . $key, true ), 0, 16 );
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x50 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
		$hex = bin2hex( $bytes );
		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20 );
	}
}

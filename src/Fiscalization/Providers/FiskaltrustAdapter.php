<?php
/** fiskaltrust POS System API adapter. */
namespace PassCaptain\Fiscalization\Providers;

use PassCaptain\Fiscalization\AdapterInterface;
use PassCaptain\Fiscalization\ProviderHttp;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class FiskaltrustAdapter implements AdapterInterface {
	public function id() { return 'fiskaltrust_pos'; }
	public function interface_version() { return AdapterInterface::VERSION; }
	public function countries() { return array( 'AT', 'BE', 'DE', 'ES', 'FR', 'GR', 'IT', 'PT' ); }
	public function capabilities() { return array( 'receipt_submit', 'operation_status', 'receipt_qr_payload', 'daily_report', 'journal_export' ); }

	public function health_check( array $seller, array $configuration ) {
		$auth = $this->headers( $configuration, ProviderHttp::uuid_from_key( 'health|' . (int) ( $seller['seller_id'] ?? 0 ) ) );
		if ( is_wp_error( $auth ) ) { return $auth; }
		$url = $this->endpoint( $configuration, '/echo' );
		if ( is_wp_error( $url ) ) { return $url; }
		$result = ProviderHttp::request( $url, 'POST', $auth, array( 'Message' => 'WP Pass Captain fiscal adapter health check' ) );
		return is_wp_error( $result ) ? $result : array( 'ok' => true );
	}

	/** fiskaltrust's x-operation-id makes the /sign call safely replayable. */
	public function query_status( array $document, array $seller, array $configuration ) {
		return $this->sign( $document, $seller, $configuration );
	}

	public function submit( array $document, array $seller, array $configuration ) {
		return $this->sign( $document, $seller, $configuration );
	}

	private function sign( array $document, array $seller, array $configuration ) {
		$payload = $this->payload( $document, $configuration );
		if ( is_wp_error( $payload ) ) { return $payload; }
		$headers = $this->headers( $configuration, ProviderHttp::uuid_from_key( $document['idempotency_key'] ?? '' ) );
		if ( is_wp_error( $headers ) ) { return $headers; }
		$url = $this->endpoint( $configuration, '/sign' );
		if ( is_wp_error( $url ) ) { return $url; }
		$result = ProviderHttp::request( $url, 'POST', $headers, $payload, 12 );
		if ( is_wp_error( $result ) ) { return $result; }
		$data = $result['data'];
		$reference = (string) ( $data['ftReceiptIdentification'] ?? $data['ftQueueItemID'] ?? $data['ftQueueItemId'] ?? '' );
		if ( '' === $reference ) { return new \WP_Error( 'fiskaltrust_result_incomplete', __( 'fiskaltrust hat keine Belegreferenz zurückgegeben.', 'wp-pass-captain' ) ); }
		$fields = array( 'Fiskaltrust-Beleg' => $reference );
		if ( ! empty( $data['ftReceiptMoment'] ) ) { $fields['Zeitpunkt'] = sanitize_text_field( $data['ftReceiptMoment'] ); }
		$receipt = array( 'fields' => $fields );
		$signatures = is_array( $data['ftSignatures'] ?? null ) ? $data['ftSignatures'] : array();
		if ( isset( $signatures[0]['ftSignatureData'] ) && is_string( $signatures[0]['ftSignatureData'] ) && strlen( $signatures[0]['ftSignatureData'] ) <= 4096 ) {
			$receipt['qr_payload'] = $signatures[0]['ftSignatureData'];
		}
		return array( 'reference' => substr( sanitize_text_field( $reference ), 0, 191 ), 'receipt' => $receipt );
	}

	private function payload( array $document, array $configuration ) {
		$receipt_case = $this->case_value( $configuration['receipt_case'] ?? null );
		$charge_case = $this->case_value( $configuration['charge_item_case'] ?? null );
		$payment_cases = (array) ( $configuration['payment_cases'] ?? array() );
		if ( null === $receipt_case || null === $charge_case ) { return new \WP_Error( 'fiskaltrust_case_mapping_missing', __( 'Die fiskaltrust-Geschäftsfallcodes müssen je Land konfiguriert werden.', 'wp-pass-captain' ) ); }
		$charges = array();
		$sum = 0;
		foreach ( (array) ( $document['items'] ?? array() ) as $item ) {
			$gross = max( 0, (int) ( $item['gross_minor'] ?? 0 ) );
			$qty = max( 1, (int) ( $item['quantity'] ?? 1 ) );
			$charges[] = array(
				'Quantity' => $qty,
				'Description' => sanitize_text_field( (string) ( $item['description'] ?? ( 'Ticket ' . (int) ( $item['ticket_type_id'] ?? 0 ) ) ) ),
				'Amount' => (float) ProviderHttp::decimal( $gross ) / $qty,
				'VATRate' => (float) number_format( (int) ( $item['tax_rate_bp'] ?? 0 ) / 100, 2, '.', '' ),
				'ftChargeItemCase' => $charge_case,
			);
			$sum += $gross;
		}
		$method = sanitize_key( $document['payment_method'] ?? 'other' );
		$pay_case = $this->case_value( $payment_cases[$method] ?? null );
		if ( null === $pay_case || ! $charges || $sum !== (int) ( $document['gross_minor'] ?? -1 ) ) {
			return new \WP_Error( 'fiskaltrust_payment_mapping_missing', __( 'Die Zahlungscode-Zuordnung fehlt oder der Belegbetrag stimmt nicht.', 'wp-pass-captain' ) );
		}
		$moment = sanitize_text_field( (string) ( $document['created_at'] ?? gmdate( 'c' ) ) );
		$register = sanitize_text_field( (string) ( $document['profile']['register_id'] ?? $configuration['terminal_id'] ?? '1' ) );
		$payload = array(
			'cbReceiptReference' => (string) ( $document['idempotency_key'] ?? '' ),
			'cbReceiptMoment' => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $moment ) ?: time() ),
			'cbTerminalID' => $register,
			'cbReceiptAmount' => (float) ProviderHttp::decimal( $sum ),
			'cbChargeItems' => $charges,
			'cbPayItems' => array( array( 'ftPayItemID' => ProviderHttp::uuid_from_key( 'pay|' . ( $document['idempotency_key'] ?? '' ) ), 'Description' => $method, 'Amount' => (float) ProviderHttp::decimal( $sum ), 'ftPayItemCase' => $pay_case ) ),
			'ftReceiptCase' => $receipt_case,
			'ftPosSystemId' => sanitize_text_field( (string) ( $configuration['pos_system_id'] ?? '' ) ),
		);
		if ( '' === $payload['ftPosSystemId'] ) { return new \WP_Error( 'fiskaltrust_possystem_missing', __( 'Die fiskaltrust-POS-System-ID fehlt.', 'wp-pass-captain' ) ); }
		return $payload;
	}

	private function case_value( $value ) {
		if ( ! is_scalar( $value ) || '' === (string) $value || ! preg_match( '/^\d+$/', (string) $value ) ) { return null; }
		return (int) $value;
	}
	private function headers( array $configuration, $operation_id ) {
		$credentials = (array) ( $configuration['credentials'] ?? array() );
		$cashbox_id = sanitize_text_field( (string) ( $credentials['cashbox_id'] ?? '' ) );
		$access_token = (string) ( $credentials['access_token'] ?? '' );
		$pos_id = sanitize_text_field( (string) ( $configuration['pos_system_id'] ?? '' ) );
		if ( '' === $cashbox_id || '' === $access_token || '' === $pos_id ) { return new \WP_Error( 'fiskaltrust_credentials_missing', __( 'fiskaltrust-CashBox-Zugangsdaten oder POS-System-ID fehlen.', 'wp-pass-captain' ) ); }
		return array( 'x-cashbox-id' => $cashbox_id, 'x-cashbox-accesstoken' => $access_token, 'x-possystem-id' => $pos_id, 'x-operation-id' => $operation_id );
	}
	private function endpoint( array $configuration, $path ) {
		$env = sanitize_key( $configuration['environment'] ?? 'test' );
		$base = untrailingslashit( (string) ( $configuration['endpoint_url'] ?? ( 'production' === $env ? 'https://possystem-api.fiskaltrust.eu/v2' : 'https://possystem-api-sandbox.fiskaltrust.eu/v2' ) ) );
		return '' === $base ? new \WP_Error( 'fiskaltrust_endpoint_missing', __( 'fiskaltrust-Endpunkt fehlt.', 'wp-pass-captain' ) ) : $base . $path;
	}
}

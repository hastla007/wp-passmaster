<?php
/** fiskaly Unified API adapter for SIGN FR/IT/PT/SE. */
namespace PassCaptain\Fiscalization\Providers;

use PassCaptain\Fiscalization\AdapterInterface;
use PassCaptain\Fiscalization\ProviderHttp;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class FiskalyUnifiedAdapter implements AdapterInterface {
	public function id() { return 'fiskaly_sign_unified'; }
	public function interface_version() { return AdapterInterface::VERSION; }
	public function countries() { return array( 'FR', 'IT', 'PT', 'SE' ); }
	public function capabilities() { return array( 'receipt_submit', 'operation_status', 'receipt_qr_payload', 'record_intention', 'daily_report' ); }

	public function health_check( array $seller, array $configuration ) {
		$system = $this->required( $configuration, 'system_id' );
		$scope = $this->required( $configuration, 'scope_identifier' );
		if ( is_wp_error( $system ) ) { return $system; }
		if ( is_wp_error( $scope ) ) { return $scope; }
		$result = $this->request( 'GET', '/systems/' . rawurlencode( $system ), null, $seller, $configuration );
		return is_wp_error( $result ) ? $result : array( 'ok' => true, 'state' => sanitize_key( $result['state'] ?? '' ) );
	}

	public function query_status( array $document, array $seller, array $configuration ) {
		// The Unified API's idempotency retention is time-limited. Never replay creation
		// requests once the original snapshot is too old to guarantee deduplication.
		$created = strtotime( (string) ( $document['created_at'] ?? '' ) );
		if ( ! $created || $created < time() - ( 20 * HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'fiskaly_unified_reconciliation_required', __( 'Der fiskaly Unified-Vorgang ist älter als das sichere Wiederholungsfenster. Bitte im fiskaly Dashboard abgleichen, bevor erneut signiert wird.', 'wp-pass-captain' ) );
		}
		return $this->submit( $document, $seller, $configuration );
	}

	public function submit( array $document, array $seller, array $configuration ) {
		$system = $this->required( $configuration, 'system_id' );
		$scope = $this->required( $configuration, 'scope_identifier' );
		if ( is_wp_error( $system ) ) { return $system; }
		if ( is_wp_error( $scope ) ) { return $scope; }
		$country = strtoupper( (string) ( $seller['country_code'] ?? '' ) );
		if ( ! in_array( $country, $this->countries(), true ) ) { return new \WP_Error( 'fiskaly_unified_country_unsupported', __( 'Die fiskaly Unified API unterstützt dieses Land nicht in diesem Adapter.', 'wp-pass-captain' ) ); }
		$base_key = (string) ( $document['idempotency_key'] ?? '' );
		$version = sanitize_text_field( (string) ( $configuration['api_version'] ?? '2026-06-01' ) );
		$intention = $this->request( 'POST', '/records', array( 'content' => array( 'type' => 'INTENTION', 'system' => array( 'id' => $system ), 'operation' => array( 'type' => 'TRANSACTION' ) ) ), $seller, $configuration, ProviderHttp::uuid_from_key( $base_key . '|intention' ), $version );
		if ( is_wp_error( $intention ) ) { return $intention; }
		$intention_id = (string) ( $intention['content']['id'] ?? '' );
		if ( '' === $intention_id ) { return new \WP_Error( 'fiskaly_unified_intention_invalid', __( 'fiskaly hat keine Intention-Referenz zurückgegeben.', 'wp-pass-captain' ) ); }
		$transaction = $this->transaction_content( $document, $configuration, $intention_id );
		if ( is_wp_error( $transaction ) ) { return $transaction; }
		$result = $this->request( 'POST', '/records', $transaction, $seller, $configuration, ProviderHttp::uuid_from_key( $base_key . '|transaction' ), $version );
		if ( is_wp_error( $result ) ) { return $result; }
		$content = is_array( $result['content'] ?? null ) ? $result['content'] : array();
		$reference = (string) ( $content['id'] ?? $content['record']['id'] ?? '' );
		if ( '' === $reference ) { return new \WP_Error( 'fiskaly_unified_result_invalid', __( 'fiskaly hat keine Belegreferenz zurückgegeben.', 'wp-pass-captain' ) ); }
		$receipt = array( 'fields' => array( 'Fiskaly-Record' => $reference ) );
		$compliance = (array) ( $content['compliance'] ?? array() );
		$code = (array) ( $compliance['code'] ?? array() );
		foreach ( array( 'image', 'value', 'data' ) as $key ) {
			if ( is_string( $code[$key] ?? null ) && '' !== $code[$key] && strlen( $code[$key] ) <= 4096 ) { $receipt['qr_payload'] = $code[$key]; break; }
		}
		if ( isset( $content['signature'] ) && is_scalar( $content['signature'] ) ) { $receipt['fields']['Signatur'] = (string) $content['signature']; }
		return array( 'reference' => substr( sanitize_text_field( $reference ), 0, 191 ), 'receipt' => $receipt );
	}

	private function transaction_content( array $document, array $configuration, $intention_id ) {
		$vat_map = (array) ( $configuration['vat_rate_map'] ?? array() );
		$payment_map = (array) ( $configuration['payment_type_map'] ?? array() );
		$breakdown = array(); $items = array(); $sums = array(); $taxes = array(); $total = 0;
		foreach ( (array) ( $document['items'] ?? array() ) as $item ) {
			$rate = (string) (int) ( $item['tax_rate_bp'] ?? 0 );
			$code = sanitize_key( $vat_map[$rate] ?? '' );
			if ( '' === $code ) { return new \WP_Error( 'fiskaly_unified_vat_map_missing', __( 'Für einen Steuersatz fehlt die fiskaly Länder-Zuordnung.', 'wp-pass-captain' ) ); }
			$gross = max( 0, (int) ( $item['gross_minor'] ?? 0 ) );
			$tax = min( $gross, max( 0, (int) ( $item['tax_minor'] ?? 0 ) ) );
			$qty = max( 1, (int) ( $item['quantity'] ?? 1 ) );
			$key = $code . ':' . $rate;
			$sums[$key] = ( $sums[$key] ?? 0 ) + $gross;
			$taxes[$key] = ( $taxes[$key] ?? 0 ) + $tax;
			$items[] = array( 'text' => sanitize_text_field( (string) ( $item['description'] ?? ( 'Ticket ' . (int) ( $item['ticket_type_id'] ?? 0 ) ) ) ), 'quantity' => (string) $qty, 'unit_amount' => ProviderHttp::decimal( (int) round( $gross / $qty ) ), 'full_amount' => ProviderHttp::decimal( $gross ) );
			$total += $gross;
		}
		foreach ( $sums as $key => $gross ) {
			list( $code, $rate ) = explode( ':', $key, 2 );
			$tax = (int) $taxes[$key];
			$breakdown[] = array( 'type' => 'VAT_RATE', 'code' => strtoupper( $code ), 'percentage' => number_format( (int) $rate / 100, 2, '.', '' ), 'exclusive' => ProviderHttp::decimal( $gross - $tax ), 'inclusive' => ProviderHttp::decimal( $gross ), 'amount' => ProviderHttp::decimal( $tax ) );
		}
		$method = sanitize_key( $document['payment_method'] ?? 'other' );
		$payment = strtoupper( sanitize_key( $payment_map[$method] ?? '' ) );
		if ( '' === $payment || $total !== (int) ( $document['gross_minor'] ?? -1 ) ) { return new \WP_Error( 'fiskaly_unified_payment_map_missing', __( 'Zahlungsart fehlt in der fiskaly Länder-Zuordnung oder der Betrag stimmt nicht.', 'wp-pass-captain' ) ); }
		$operation = array(
			'type' => 'RECEIPT',
			'document' => array( 'number' => (string) ( $document['order_id'] ?? '' ) ),
			'items' => $items,
			'breakdown' => $breakdown,
			'payments' => array( array( 'type' => $payment, 'details' => array( 'amount' => ProviderHttp::decimal( $total ) ) ) ),
		);
		return array( 'content' => array( 'type' => 'TRANSACTION', 'record' => array( 'id' => $intention_id ), 'operation' => $operation ) );
	}

	private function request( $method, $path, $body, array $seller, array $configuration, $idempotency_key = '', $version = '' ) {
		$env = sanitize_key( $configuration['environment'] ?? 'test' );
		$base = 'production' === $env ? 'https://live.api.fiskaly.com' : 'https://test.api.fiskaly.com';
		$headers = array( 'X-Api-Version' => $version ?: sanitize_text_field( (string) ( $configuration['api_version'] ?? '2026-06-01' ) ) );
		$scope = sanitize_text_field( (string) ( $configuration['scope_identifier'] ?? '' ) );
		if ( '' !== $scope ) { $headers['X-Scope-Identifier'] = $scope; }
		if ( '' !== $idempotency_key ) { $headers['X-Idempotency-Key'] = $idempotency_key; }
		$credentials = (array) ( $configuration['credentials'] ?? array() );
		if ( empty( $credentials['api_key'] ) || empty( $credentials['api_secret'] ) ) { return new \WP_Error( 'fiskaly_credentials_missing', __( 'fiskaly API-Key und API-Secret fehlen.', 'wp-pass-captain' ) ); }
		$cache_key = 'wpcap_fiskaly_uapi_token_' . absint( $seller['seller_id'] ?? 0 );
		$token = get_transient( $cache_key );
		if ( ! is_string( $token ) || '' === $token ) {
			$auth = ProviderHttp::request( $base . '/tokens', 'POST', array( 'X-Api-Version' => $headers['X-Api-Version'] ), array( 'api_key' => $credentials['api_key'], 'api_secret' => $credentials['api_secret'] ) );
			if ( is_wp_error( $auth ) ) { return $auth; }
			$token = (string) ( $auth['data']['access_token'] ?? '' );
			if ( '' === $token ) { return new \WP_Error( 'fiskaly_auth_response_invalid', __( 'fiskaly hat kein Zugriffstoken zurückgegeben.', 'wp-pass-captain' ) ); }
			set_transient( $cache_key, $token, 20 * HOUR_IN_SECONDS );
		}
		$result = ProviderHttp::request( $base . $path, strtoupper( $method ), array_merge( $headers, array( 'Authorization' => 'Bearer ' . $token ) ), $body, 8 );
		if ( is_wp_error( $result ) && 401 === (int) ( (array) $result->get_error_data() )['status'] ) { delete_transient( $cache_key ); return new \WP_Error( 'fiskaly_auth_expired', __( 'fiskaly-Zugriffstoken abgelaufen.', 'wp-pass-captain' ) ); }
		return is_wp_error( $result ) ? $result : $result['data'];
	}

	private function required( array $configuration, $key ) {
		$value = sanitize_text_field( (string) ( $configuration[$key] ?? '' ) );
		return '' !== $value ? $value : new \WP_Error( 'fiskaly_configuration_missing', __( 'Eine fiskaly SIGN Unified API Ressource fehlt in der Konfiguration.', 'wp-pass-captain' ) );
	}
}

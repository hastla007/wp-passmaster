<?php
/** efsta EFR unified JSON API adapter (local EFR or Cloud EFR). */
namespace PassCaptain\Fiscalization\Providers;

use PassCaptain\Fiscalization\AdapterInterface;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class EfstaEfrAdapter implements AdapterInterface {
	const ID = 'efsta_efr';
	const COUNTRIES = array( 'AT', 'BE', 'CZ', 'DE', 'DK', 'ES', 'FR', 'HR', 'HU', 'IT', 'LT', 'PL', 'PT', 'SE', 'SI', 'SK' );
	public function id() { return self::ID; }
	public function interface_version() { return AdapterInterface::VERSION; }
	public function countries() { return self::COUNTRIES; }
	public function capabilities() { return array( 'receipt_submit', 'operation_status' ); }

	public function health_check( array $seller, array $configuration ) {
		$auth = $this->authorization( $configuration, $seller );
		if ( is_wp_error( $auth ) ) { return $auth; }
		$headers = $auth ? array( 'Authorization' => 'Bearer ' . $auth ) : array();
		$response = HttpClient::request( HttpClient::endpoint( $configuration, 'http://127.0.0.1:5618', 'state' ), 'GET', $headers );
		if ( is_wp_error( $response ) ) { return $response; }
		if ( 200 !== $response['status'] ) { return new \WP_Error( 'efsta_health_failed', __( 'efsta EFR-Verbindungstest wurde abgelehnt.', 'pass-captain' ) ); }
		return array( 'ok' => true );
	}

	public function query_status( array $document, array $seller, array $configuration ) {
		$auth = $this->authorization( $configuration, $seller );
		if ( is_wp_error( $auth ) ) { return $auth; }
		$headers = array( 'Accept' => 'application/json' );
		if ( $auth ) { $headers['Authorization'] = 'Bearer ' . $auth; }
		$rn = sanitize_text_field( (string) ( $configuration['register_number'] ?? 'def' ) );
		$date = gmdate( 'Y-m-d', strtotime( (string) ( $document['created_at'] ?? 'now' ) ) );
		$tn = absint( $document['document_id'] ?? 0 );
		$query = http_build_query( array( 'rn' => $rn, 'd' => $date, 'tn' => $tn, '_' => 'Tra', 'limit' => '5rows5000ms' ) );
		$url = HttpClient::endpoint( $configuration, 'http://127.0.0.1:5618', 'find?' . $query );
		$response = HttpClient::request( $url, 'GET', $headers );
		if ( is_wp_error( $response ) ) { return $response; }
		if ( 200 !== $response['status'] ) { return new \WP_Error( 'efsta_status_unknown', __( 'efsta-Journalstatus konnte nicht sicher abgefragt werden.', 'pass-captain' ) ); }
		$matches = $this->transactions( $response['data'] );
		foreach ( $matches as $transaction ) {
			$esr = (array) ( $transaction['Tra']['ESR'] ?? $transaction['ESR'] ?? array() );
			if ( (string) ( $esr['TN'] ?? '' ) === (string) $tn && (string) ( $esr['D'] ?? '' ) === $date ) {
				return $this->result( $transaction, $document );
			}
		}
		return array( 'status' => 'not_found' );
	}

	public function submit( array $document, array $seller, array $configuration ) {
		$auth = $this->authorization( $configuration, $seller );
		if ( is_wp_error( $auth ) ) { return $auth; }
		$tax_groups = (array) ( $configuration['tax_groups'] ?? array() );
		$positions = array();
		$tax_totals = array();
		foreach ( (array) ( $document['items'] ?? array() ) as $index => $item ) {
			$rate = (string) (int) ( $item['tax_rate_bp'] ?? -1 );
			$group = sanitize_text_field( (string) ( $tax_groups[ $rate ] ?? '' ) );
			if ( '' === $group || ! preg_match( '/^[A-Z0-9]{1,4}$/', $group ) ) {
				return new \WP_Error( 'efsta_tax_mapping_missing', __( 'Ein Steuersatz ist keiner efsta-Steuergruppe zugeordnet.', 'pass-captain' ) );
			}
			$gross = (int) ( $item['gross_minor'] ?? 0 );
			$tax = (int) ( $item['tax_minor'] ?? 0 );
			$net = max( 0, $gross - $tax );
			$positions[] = array(
				'_' => 'Pos',
				'PN' => (string) ( $index + 1 ),
				'IN' => (string) absint( $item['ticket_type_id'] ?? 0 ),
				'Dsc' => 'Ticket ' . absint( $item['ticket_type_id'] ?? 0 ),
				'TaxG' => $group,
				'Amt' => HttpClient::minor_to_major( $gross ),
				'Qty' => (string) max( 1, (int) ( $item['quantity'] ?? 1 ) ),
			);
			if ( ! isset( $tax_totals[ $group ] ) ) { $tax_totals[ $group ] = array( 'rate' => (int) $rate, 'net' => 0, 'tax' => 0, 'gross' => 0 ); }
			$tax_totals[ $group ]['net'] += $net;
			$tax_totals[ $group ]['tax'] += $tax;
			$tax_totals[ $group ]['gross'] += $gross;
		}
		if ( ! $positions ) { return new \WP_Error( 'efsta_lines_missing', __( 'Der Fiskalbeleg enthält keine Positionen.', 'pass-captain' ) ); }
		$payment_method = sanitize_key( $document['payment_method'] ?? 'other' );
		$payment_groups = (array) ( $configuration['payment_groups'] ?? array() );
		$pay_group = sanitize_key( $payment_groups[ $payment_method ] ?? $payment_groups['default'] ?? '' );
		if ( ! in_array( $pay_group, array( 'cash', 'cashless', 'voucher', 'change', 'other' ), true ) ) {
			return new \WP_Error( 'efsta_payment_mapping_missing', __( 'Zahlungsart ist keiner efsta-Zahlungsgruppe zugeordnet.', 'pass-captain' ) );
		}
		$taxes = array();
		foreach ( $tax_totals as $group => $amounts ) {
			$taxes[] = array( '_' => 'Tax', 'TaxG' => $group, 'Prc' => (string) ( $amounts['rate'] / 100 ), 'Net' => HttpClient::minor_to_major( $amounts['net'] ), 'TAmt' => HttpClient::minor_to_major( $amounts['tax'] ), 'Amt' => HttpClient::minor_to_major( $amounts['gross'] ) );
		}
		$tax_id = preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $seller['tax_number'] ?? $seller['vat_id'] ?? '' ) );
		$esr = array(
			'D' => gmdate( 'Y-m-d\TH:i:s' ),
			'TL' => sanitize_text_field( (string) ( $configuration['transaction_location'] ?? '001' ) ),
			'TT' => sanitize_text_field( (string) ( $configuration['transaction_terminal'] ?? '1' ) ),
			'TN' => (string) absint( $document['document_id'] ?? 0 ),
			'T' => HttpClient::minor_to_major( (int) ( $document['gross_minor'] ?? 0 ) ),
			'PosA' => $positions,
			'PayA' => array( array( '_' => 'Pay', 'Dsc' => $payment_method, 'PayG' => $pay_group, 'Amt' => HttpClient::minor_to_major( (int) ( $document['gross_minor'] ?? 0 ) ) ) ),
			'TaxA' => $taxes,
		);
		if ( '' !== $tax_id ) { $esr['TaxId'] = $tax_id; }
		$headers = array( 'Accept' => 'application/json' );
		if ( $auth ) { $headers['Authorization'] = 'Bearer ' . $auth; }
		$response = HttpClient::request( HttpClient::endpoint( $configuration, 'http://127.0.0.1:5618', 'register' ), 'POST', $headers, array( 'Tra' => array( 'ESR' => $esr ) ) );
		if ( is_wp_error( $response ) ) { return $response; }
		if ( ! in_array( $response['status'], array( 200, 201 ), true ) ) { return new \WP_Error( 'efsta_submit_failed', __( 'efsta hat den Beleg nicht bestätigt.', 'pass-captain' ) ); }
		$data = $response['data'];
		$tra_c = $data['TraC'] ?? $data['Tra']['TraC'] ?? null;
		if ( is_array( $tra_c ) && in_array( strtoupper( (string) ( $tra_c['RC'] ?? '' ) ), array( 'BAD', 'NO' ), true ) ) {
			return new \WP_Error( 'efsta_register_rejected', __( 'efsta hat die Transaktion abgelehnt oder zur Wiederholung markiert.', 'pass-captain' ) );
		}
		return $this->result( $data, $document );
	}

	private function authorization( array $configuration, array $seller ) {
		if ( 'cloud' !== sanitize_key( $configuration['deployment'] ?? 'local' ) ) { return ''; }
		$credentials = (array) ( $configuration['credentials'] ?? array() );
		$key = (string) ( $credentials['api_key'] ?? '' );
		$secret = (string) ( $credentials['api_secret'] ?? '' );
		if ( '' === $key || '' === $secret ) { return new \WP_Error( 'efsta_credentials_missing', __( 'efsta Cloud API-Key und Secret fehlen.', 'pass-captain' ) ); }
		$tax_id = preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $configuration['cloud_tax_id'] ?? $seller['tax_number'] ?? $seller['vat_id'] ?? '' ) );
		if ( '' === $tax_id ) { return new \WP_Error( 'efsta_tax_id_missing', __( 'Für efsta Cloud ist die Verkäufer-Steuernummer erforderlich.', 'pass-captain' ) ); }
		$base = untrailingslashit( (string) ( $configuration['base_url'] ?? '' ) );
		$body = http_build_query( array( 'grant_type' => 'client_credentials', 'TaxId' => $tax_id, 'TL' => (string) ( $configuration['transaction_location'] ?? '001' ), 'TT' => (string) ( $configuration['transaction_terminal'] ?? '1' ) ) );
		$headers = array( 'Content-Type' => 'application/x-www-form-urlencoded', 'Authorization' => 'Basic ' . base64_encode( $key . ':' . $secret ) );
		$token = HttpClient::request( $base . '/auth/token', 'POST', $headers, $body );
		if ( is_wp_error( $token ) || ! in_array( (int) ( $token['status'] ?? 0 ), array( 200, 201 ), true ) || empty( $token['data']['access_token'] ) ) {
			return new \WP_Error( 'efsta_auth_failed', __( 'efsta Cloud-Zugang konnte nicht authentifiziert werden.', 'pass-captain' ) );
		}
		return sanitize_text_field( (string) $token['data']['access_token'] );
	}

	private function transactions( array $data ) {
		if ( isset( $data['Tra'] ) ) { return array( $data ); }
		if ( array_is_list( $data ) ) { return $data; }
		foreach ( array( 'Transactions', 'Journal', 'Data', 'TraA' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) { return $data[ $key ]; }
		}
		return array();
	}

	private function result( array $data, array $document ) {
		$tra_c = $data['TraC'] ?? $data['Tra']['TraC'] ?? array();
		$esr = $data['Tra']['ESR'] ?? $data['ESR'] ?? array();
		$reference = is_scalar( $esr['FN'] ?? null ) ? (string) $esr['FN'] : 'EFR-' . absint( $document['document_id'] ?? 0 );
		$fields = array();
		foreach ( array( 'FN', 'JIR', 'EOR', 'QR', 'QRC', 'ZI' ) as $key ) {
			$value = $tra_c[ $key ] ?? $esr[ $key ] ?? null;
			if ( is_scalar( $value ) && '' !== (string) $value ) { $fields[ $key ] = (string) $value; }
		}
		return array( 'reference' => sanitize_text_field( $reference ), 'receipt' => array( 'fields' => $fields ) );
	}
}

<?php
/** efsta EFR adapter using the vendor's unified /register and /restart APIs. */
namespace PassCaptain\Fiscalization\Providers;

use PassCaptain\Fiscalization\AdapterInterface;
use PassCaptain\Fiscalization\ProviderHttp;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class EfstaEfrAdapter implements AdapterInterface {
	public function id() { return 'efsta_efr'; }
	public function interface_version() { return AdapterInterface::VERSION; }
	public function countries() { return array( 'AT', 'BE', 'CZ', 'DK', 'DE', 'ES', 'FR', 'HR', 'HU', 'IT', 'LT', 'NO', 'PL', 'PT', 'SE', 'SI', 'SK' ); }
	public function capabilities() { return array( 'receipt_submit', 'operation_status', 'journal_export', 'daily_report', 'fiscal_printer_bridge' ); }

	public function health_check( array $seller, array $configuration ) {
		$url = $this->endpoint( $configuration, '/state' );
		if ( is_wp_error( $url ) ) { return $url; }
		$result = ProviderHttp::request( $url, 'GET', $this->headers( $configuration ) );
		if ( is_wp_error( $result ) ) { return $result; }
		$state = $result['data'];
		return ! empty( $state['name'] ) && 'EFR' === strtoupper( (string) $state['name'] )
			? array( 'ok' => true, 'version' => sanitize_text_field( $state['version'] ?? '' ) )
			: new \WP_Error( 'efsta_health_invalid', __( 'Die Antwort bestätigt keine laufende efsta EFR-Instanz.', 'wp-pass-captain' ) );
	}

	/**
	 * EFR's /restart is intentionally used for reconciliation. EFR documents it as the recovery
	 * endpoint that returns the original result for an already registered transaction and never
	 * registers a duplicate. The operation is therefore safe to replay with the frozen ESR identity.
	 */
	public function query_status( array $document, array $seller, array $configuration ) {
		return $this->register( $document, $seller, $configuration, '/restart' );
	}

	public function submit( array $document, array $seller, array $configuration ) {
		return $this->register( $document, $seller, $configuration, '/register' );
	}

	/** Only AT and DE have enough stored original transaction data for an automatic full void here. */
	public function supports_correction( $country_code, $kind ) {
		return 'void' === sanitize_key( $kind ) && in_array( strtoupper( sanitize_text_field( $country_code ) ), array( 'AT', 'DE' ), true );
	}

	/** Validate and build the EFR payload before an irreversible void submission. */
	public function prepare_void( array $document, array $seller, array $configuration, $event_id ) {
		$country = strtoupper( sanitize_text_field( $document['country_code'] ?? ( $seller['country_code'] ?? '' ) ) );
		if ( ! $this->supports_correction( $country, 'void' ) ) {
			return new \WP_Error( 'efsta_void_country_unsupported', __( 'Automatisches efsta-Storno ist hier nur für AT und DE freigeschaltet; HU benötigt zusätzliche Kundendaten.', 'wp-pass-captain' ) );
		}
		$payload = $this->payload( $document, $seller, $configuration );
		if ( is_wp_error( $payload ) ) { return $payload; }
		if ( empty( $payload['Tra']['ESR']['TN'] ) || empty( $payload['Tra']['ESR']['TL'] ) || empty( $payload['Tra']['ESR']['TT'] ) ) {
			return new \WP_Error( 'efsta_void_original_identity_missing', __( 'Originale efsta-Transaktionskennung fehlt; Storno wird nicht gesendet.', 'wp-pass-captain' ) );
		}
		$payload['Tra']['SESR'] = array( 'TN' => 'PC' . absint( $event_id ) );
		$url = $this->endpoint( $configuration, '/register/void' );
		if ( is_wp_error( $url ) ) { return $url; }
		$register = rawurlencode( sanitize_text_field( $configuration['register_number'] ?? 'def' ) );
		return array( 'url' => add_query_arg( 'rn', $register, $url ), 'payload' => $payload );
	}

	/** Submit one full reversal through EFR's documented /register/void endpoint. */
	public function submit_void( array $document, array $seller, array $configuration, $event_id, array $prepared = array() ) {
		if ( ! isset( $prepared['url'], $prepared['payload'] ) ) {
			$prepared = $this->prepare_void( $document, $seller, $configuration, $event_id );
		}
		if ( is_wp_error( $prepared ) ) { return $prepared; }
		$result = ProviderHttp::request( $prepared['url'], 'POST', $this->headers( $configuration ), $prepared['payload'], 12 );
		if ( is_wp_error( $result ) ) { return $result; }
		$data = $result['data'];
		$transaction = is_array( $data['TraC']['ESR'] ?? null ) ? $data['TraC']['ESR'] : (array) ( $data['ESR'] ?? array() );
		$trac = is_array( $data['TraC'] ?? null ) ? $data['TraC'] : $data;
		$rc = strtoupper( (string) ( $trac['Result']['RC'] ?? '' ) );
		$error = sanitize_text_field( (string) ( $trac['Result']['ErrorCode'] ?? '' ) );
		if ( 'OK' !== $rc ) {
			return new \WP_Error( 'efsta_void_result_' . ( '' !== $rc ? sanitize_key( $rc ) : 'unknown' ), __( 'efsta EFR hat das Storno nicht bestätigt.', 'wp-pass-captain' ), array( 'provider_error' => sanitize_key( $error ) ) );
		}
		$fis = is_array( $trac['Fis'] ?? null ) ? $trac['Fis'] : array();
		$reference = (string) ( $transaction['FN'] ?? $fis['FN'] ?? $transaction['TN'] ?? '' );
		if ( '' === $reference ) { return new \WP_Error( 'efsta_void_reference_missing', __( 'efsta hat das Storno angenommen, aber keine Referenz geliefert; Vorgang muss abgeglichen werden.', 'wp-pass-captain' ) ); }
		return array( 'reference' => substr( sanitize_text_field( $reference ), 0, 191 ) );
	}

	private function register( array $document, array $seller, array $configuration, $path ) {
		$payload = $this->payload( $document, $seller, $configuration );
		if ( is_wp_error( $payload ) ) { return $payload; }
		$url = $this->endpoint( $configuration, $path );
		if ( is_wp_error( $url ) ) { return $url; }
		$register = rawurlencode( sanitize_text_field( $configuration['register_number'] ?? 'def' ) );
		$url = add_query_arg( 'rn', $register, $url );
		$result = ProviderHttp::request( $url, 'POST', $this->headers( $configuration ), $payload, 12 );
		if ( is_wp_error( $result ) ) { return $result; }
		$data = $result['data'];
		$transaction = is_array( $data['TraC']['ESR'] ?? null ) ? $data['TraC']['ESR'] : (array) ( $data['ESR'] ?? array() );
		$trac = is_array( $data['TraC'] ?? null ) ? $data['TraC'] : $data;
		$rc = strtoupper( (string) ( $trac['Result']['RC'] ?? '' ) );
		$error = sanitize_text_field( (string) ( $trac['Result']['ErrorCode'] ?? '' ) );
		if ( 'OK' !== $rc ) {
			return new \WP_Error( 'efsta_result_' . ( '' !== $rc ? sanitize_key( $rc ) : 'unknown' ), __( 'efsta EFR hat die Transaktion nicht bestätigt.', 'wp-pass-captain' ), array( 'provider_error' => sanitize_key( $error ) ) );
		}
		$fis = is_array( $trac['Fis'] ?? null ) ? $trac['Fis'] : array();
		$reference = (string) ( $transaction['FN'] ?? $fis['FN'] ?? $document['idempotency_key'] ?? '' );
		$fields = array();
		if ( ! empty( $transaction['TN'] ) ) { $fields['Transaktionsnummer'] = (string) $transaction['TN']; }
		if ( ! empty( $transaction['D'] ) ) { $fields['Zeitpunkt'] = (string) $transaction['D']; }
		if ( ! empty( $fis['SN'] ) ) { $fields['Signatur'] = (string) $fis['SN']; }
		$receipt = array();
		if ( $fields ) { $receipt['fields'] = $fields; }
		if ( is_string( $fis['QR'] ?? null ) && '' !== $fis['QR'] ) { $receipt['qr_payload'] = $fis['QR']; }
		$out = array( 'reference' => $reference ?: (string) ( $document['idempotency_key'] ?? '' ) );
		if ( $receipt ) { $out['receipt'] = $receipt; }
		return $out;
	}

	private function payload( array $document, array $seller, array $configuration ) {
		$groups = (array) ( $configuration['tax_groups'] ?? array() );
		$payments = (array) ( $configuration['payment_groups'] ?? array() );
		$items = array();
		$taxes = array();
		$tax_rates = array();
		$gross_total = 0;
		$tax_total = 0;
		foreach ( (array) ( $document['items'] ?? array() ) as $index => $item ) {
			$rate = (int) ( $item['tax_rate_bp'] ?? 0 );
			$group = sanitize_text_field( (string) ( $groups[(string) $rate] ?? $groups[$rate] ?? '' ) );
			if ( '' === $group ) { return new \WP_Error( 'efsta_tax_mapping_missing', __( 'Für einen Steuersatz fehlt die efsta-Steuergruppen-Zuordnung.', 'wp-pass-captain' ) ); }
			$gross = max( 0, (int) ( $item['gross_minor'] ?? 0 ) );
			$tax = min( $gross, max( 0, (int) ( $item['tax_minor'] ?? 0 ) ) );
			$qty = max( 1, (int) ( $item['quantity'] ?? 1 ) );
			$items[] = array( '_' => 'Pos', 'PN' => (string) ( $index + 1 ), 'IN' => (string) (int) ( $item['ticket_type_id'] ?? $item['product_id'] ?? 0 ), 'Dsc' => sanitize_text_field( (string) ( $item['description'] ?? ( 'Ticket ' . (int) ( $item['ticket_type_id'] ?? 0 ) ) ) ), 'Qty' => (string) $qty, 'TaxG' => $group, 'Amt' => ProviderHttp::decimal( $gross ) );
			$taxes[$group] = ( $taxes[$group] ?? 0 ) + $gross;
			$tax_rates[$group] = $rate;
			$tax_total += $tax;
			$gross_total += $gross;
		}
		if ( ! $items || $gross_total !== (int) ( $document['gross_minor'] ?? -1 ) ) { return new \WP_Error( 'efsta_document_invalid', __( 'Die efsta-Belegdaten sind leer oder stimmen betragsmäßig nicht überein.', 'wp-pass-captain' ) ); }
		$tax_rows = array();
		foreach ( $taxes as $group => $gross ) {
			$tax = 0;
			foreach ( (array) $document['items'] as $item ) { if ( (string) ( $groups[(string) (int) $item['tax_rate_bp']] ?? $groups[(int) $item['tax_rate_bp']] ?? '' ) === (string) $group ) { $tax += max( 0, (int) $item['tax_minor'] ); } }
			$tax_rows[] = array( '_' => 'Tax', 'TaxG' => $group, 'Prc' => number_format( $tax_rates[$group] / 100, 2, '.', '' ), 'Net' => ProviderHttp::decimal( $gross - $tax ), 'TAmt' => ProviderHttp::decimal( $tax ), 'Amt' => ProviderHttp::decimal( $gross ) );
		}
		$payment_method = sanitize_key( $document['payment_method'] ?? 'other' );
		$payment_group = sanitize_text_field( (string) ( $payments[$payment_method] ?? '' ) );
		if ( '' === $payment_group ) { return new \WP_Error( 'efsta_payment_mapping_missing', __( 'Für die Zahlungsart fehlt die efsta-Zahlungsgruppen-Zuordnung.', 'wp-pass-captain' ) ); }
		$now = sanitize_text_field( (string) ( $document['created_at'] ?? gmdate( 'c' ) ) );
		$register = sanitize_text_field( (string) ( $configuration['terminal_id'] ?? '1' ) );
		$tax_id = sanitize_text_field( (string) ( $seller['tax_number'] ?? $seller['vat_id'] ?? '' ) );
		$esr = array(
			'D' => $now, 'TL' => $register, 'TT' => '1', 'TN' => substr( (string) ( $document['order_id'] ?? 0 ), 0, 32 ),
			'T' => ProviderHttp::decimal( $gross_total ), 'PosA' => $items,
			'PayA' => array( array( '_' => 'Pay', 'Dsc' => $payment_method, 'PayG' => $payment_group, 'Amt' => ProviderHttp::decimal( $gross_total ) ) ),
			'TaxA' => $tax_rows,
		);
		if ( '' !== $tax_id ) { $esr['TaxId'] = $tax_id; }
		return array( 'Tra' => array( 'ESR' => $esr ) );
	}

	private function endpoint( array $configuration, $path ) {
		$base = rtrim( (string) ( $configuration['endpoint_url'] ?? '' ), '/' );
		if ( '' === $base ) { return new \WP_Error( 'efsta_endpoint_missing', __( 'Die efsta EFR-Endpunktadresse muss konfiguriert werden.', 'wp-pass-captain' ) ); }
		return $base . '/' . ltrim( $path, '/' );
	}
	private function headers( array $configuration ) {
		$headers = array();
		$credentials = (array) ( $configuration['credentials'] ?? array() );
		if ( ! empty( $credentials['username'] ) && isset( $credentials['password'] ) ) { $headers['Authorization'] = 'Basic ' . base64_encode( $credentials['username'] . ':' . $credentials['password'] ); }
		return $headers;
	}
}

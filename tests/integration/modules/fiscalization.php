<?php
/**
 * Fiscalization adapter, credential, and response-boundary integration checks.
 *
 * Loaded by tests/integration/run.php after the shared database-backed test bootstrap.
 */

// phpcs:ignoreFile

use PassCaptain\Fiscalization\AdapterInterface;
use PassCaptain\Fiscalization\CountryPolicy;
use PassCaptain\Fiscalization\Credentials;
use PassCaptain\Modules\Fiscalization as FiscalizationModule;

$fiscal_adapter = new class implements AdapterInterface {
	public function id() { return 'test_fiscal_adapter'; }
	public function interface_version() { return AdapterInterface::VERSION; }
	public function countries() { return array( 'AT', 'DE' ); }
	public function capabilities() { return array( 'receipt_submit', 'operation_status', 'receipt_qr_payload' ); }
	public function health_check( array $seller, array $configuration ) { return array( 'ok' => true ); }
	public function query_status( array $document, array $seller, array $configuration ) { return array( 'status' => 'not_found' ); }
	public function submit( array $document, array $seller, array $configuration ) { return array( 'reference' => 'test-ref' ); }
};

$required_profile = array( 'required_capabilities' => array( 'receipt_qr_payload' ) );
check( 'adapter capability policy accepts a complete profile', array() === CountryPolicy::missing_capabilities( $fiscal_adapter, $required_profile ) );
$missing_profile = array( 'required_capabilities' => array( 'daily_report' ) );
check( 'adapter capability policy reports missing country workflows', array( 'daily_report' ) === CountryPolicy::missing_capabilities( $fiscal_adapter, $missing_profile ) );

$GLOBALS['wpcap_test_filters']['wpcap_fiscal_credentials'] = array( 'api_secret' => 'secret-value', 'bad key!' => array( 'nested' ) );
$runtime_config = Credentials::with_credentials( array( 'region' => 'eu' ), 17, 'test_fiscal_adapter' );
unset( $GLOBALS['wpcap_test_filters']['wpcap_fiscal_credentials'] );
check( 'runtime credentials are added without changing public adapter settings', 'eu' === $runtime_config['region'] && 'secret-value' === $runtime_config['credentials']['api_secret'] );
check( 'runtime credentials reject nested values and sanitize credential names', ! isset( $runtime_config['credentials']['badkey'] ) );

$normalizer = new ReflectionMethod( FiscalizationModule::class, 'normalize_provider_result' );
$normalizer->setAccessible( true );
$normalized = $normalizer->invoke( null, array(
	'reference' => ' ref-123 ',
	'access_token' => 'must-not-persist',
	'raw_response' => 'must-not-persist',
	'receipt' => array(
		'qr_payload' => 'fiscal-qr-payload',
		'fields' => array( 'Belegnummer' => 'AB-123', 'nested' => array( 'secret' => 'must-not-persist' ) ),
	),
) );
$normalized_json = wp_json_encode( $normalized );
check( 'provider result persistence keeps only normalized receipt data', 'ref-123' === $normalized['reference'] && 'fiscal-qr-payload' === $normalized['receipt']['qr_payload'] && 'AB-123' === $normalized['receipt']['fields']['Belegnummer'] );
check( 'provider tokens and nested response objects are excluded from stored results', false === strpos( $normalized_json, 'must-not-persist' ) && ! isset( $normalized['access_token'] ) );

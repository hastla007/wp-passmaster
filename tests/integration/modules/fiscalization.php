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

if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) { return 'fiscalization-test-' . $scheme . '-' . ( $GLOBALS['wpcap_test_salt_epoch'] ?? 'one' ); }
}


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

$GLOBALS['wpcap_test_salt_epoch'] = 'one';
$vault_save = Credentials::save( 73, 'test_fiscal_adapter', array( 'api_key' => 'vault-key-value', 'api_secret' => 'vault-secret-value' ) );
$vault_config = Credentials::with_credentials( array( 'region' => 'eu' ), 73, 'test_fiscal_adapter' );
$vault_raw = wp_json_encode( get_option( Credentials::OPTION, array() ) );
check( 'credential vault encrypts values and exposes them only at adapter-call time', true === $vault_save && 'vault-key-value' === $vault_config['credentials']['api_key'] && false === strpos( $vault_raw, 'vault-secret-value' ) );
check( 'credential admin metadata exposes names but never values', array( 'api_key', 'api_secret' ) === Credentials::stored_names( 73, 'test_fiscal_adapter' ) );
$vault_update = Credentials::save( 73, 'test_fiscal_adapter', array( 'api_key' => 'rotated-key' ), array( 'api_secret' ) );
$updated_config = Credentials::with_credentials( array(), 73, 'test_fiscal_adapter' );
check( 'credential vault supports safe rotation and per-field deletion', true === $vault_update && 'rotated-key' === $updated_config['credentials']['api_key'] && ! isset( $updated_config['credentials']['api_secret'] ) );
$GLOBALS['wpcap_test_salt_epoch'] = 'rotated';
$rotated_salt_config = Credentials::with_credentials( array(), 73, 'test_fiscal_adapter' );
$GLOBALS['wpcap_test_salt_epoch'] = 'one';
check( 'credential vault fails closed after WordPress salts change', empty( $rotated_salt_config['credentials'] ) );
Credentials::delete( 73, 'test_fiscal_adapter' );

$permanent_failure = new ReflectionMethod( FiscalizationModule::class, 'failure_is_permanent' );
$permanent_failure->setAccessible( true );
check( 'invalid provider and country configuration stops retrying immediately', true === $permanent_failure->invoke( null, 'country_unsupported' ) && true === $permanent_failure->invoke( null, 'capability_missing:daily_report' ) );
check( 'transient provider errors retain automatic retry behavior', false === $permanent_failure->invoke( null, 'provider_error:timeout' ) );
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

$GLOBALS['wpcap_test_filters']['wpcap_fiscal_profile_scope'] = array( 'location_id' => 23, 'register_id' => 'BOX-OFFICE-2' );
$scope = FiscalizationModule::profile_scope( 'pos', array( 'source' => 'pos' ), 99 );
unset( $GLOBALS['wpcap_test_filters']['wpcap_fiscal_profile_scope'] );
check( 'fiscal profile scope resolves a trusted POS register', ! is_wp_error( $scope ) && 23 === $scope['location_id'] && 'BOX-OFFICE-2' === $scope['register_id'] );
$GLOBALS['wpcap_test_filters']['wpcap_fiscal_profile_scope'] = array( 'location_id' => 23, 'register_id' => '<script>' );
$invalid_scope = FiscalizationModule::profile_scope( 'pos', array( 'source' => 'pos' ), 99 );
unset( $GLOBALS['wpcap_test_filters']['wpcap_fiscal_profile_scope'] );
check( 'invalid POS register identifiers fail closed', is_wp_error( $invalid_scope ) );
$session_scope = FiscalizationModule::profile_scope( 'pos', array( 'fiscal_location_id' => 23, 'fiscal_register_id' => 'BOX-OFFICE-2' ), 99 );
check( 'POS fiscal profile scope inherits the locked session assignment', ! is_wp_error( $session_scope ) && 23 === $session_scope['location_id'] && 'BOX-OFFICE-2' === $session_scope['register_id'] );


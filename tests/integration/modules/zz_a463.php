<?php
/**
 * A463: releases up to 1.4.0 do not know the encrypted secrets store; after a code rollback every
 * payment method would count as not configured. `wp pass-captain secrets rollback-config` prints the
 * stored secrets as WPCAP_<NAME> constants, which both versions read (constant > environment > stored
 * value). The real rollback with release 1.4.0 is exercised by tests/restore/drill.sh (scenario 3).
 */

use PassCaptain\Secrets;

echo "A463: secrets survive a rollback to a release without the vault\n";

$a463_vault = get_option( Secrets::OPTION );
$a463_values = array(
	'stripe_secret_key' => "sk_test_a463_'quote'\\backslash",
	'apple_pass_cert' => "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n",
	'mollie_api_key' => 'test_a463mollie',
);
update_option( Secrets::OPTION, array() );
foreach ( $a463_values as $a463_key => $a463_value ) { Secrets::set( $a463_key, $a463_value ); }
$a463_result = Secrets::rollback_constants();
check( 'every stored secret gets a define() line', array( 'stripe_secret_key', 'mollie_api_key', 'apple_pass_cert' ) === array_keys( $a463_result['lines'] ) && array() === $a463_result['undecryptable'] );

// The lines are valid PHP and yield exactly the stored values under the names release 1.4.0 reads
// (Settings::secret(): constant 'WPCAP_' . strtoupper( $key )).
$a463_file = tempnam( sys_get_temp_dir(), 'a463' );
file_put_contents( $a463_file, "<?php\n" . implode( "\n", $a463_result['lines'] ) . "\necho json_encode( array( WPCAP_STRIPE_SECRET_KEY, WPCAP_APPLE_PASS_CERT, WPCAP_MOLLIE_API_KEY ) );\n" );
$a463_out = json_decode( (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $a463_file ) ), true );
unlink( $a463_file );
check( 'the constants carry the exact values (quotes, backslashes, newlines)', array( $a463_values['stripe_secret_key'], $a463_values['apple_pass_cert'], $a463_values['mollie_api_key'] ) === $a463_out );

// Values that already come from a constant are not exported again; undecryptable ones are named.
$a463_vault_now = get_option( Secrets::OPTION );
$a463_vault_now['paypal_client_secret'] = 'v1:deadbeef00:' . base64_encode( str_repeat( 'x', 60 ) );
update_option( Secrets::OPTION, $a463_vault_now );
putenv( 'WPCAP_MOLLIE_API_KEY=from-env' );
$a463_result = Secrets::rollback_constants();
putenv( 'WPCAP_MOLLIE_API_KEY' );
check( 'secrets set by constant/environment are skipped, undecryptable ones reported', ! isset( $a463_result['lines']['mollie_api_key'] ) && isset( $a463_result['lines']['stripe_secret_key'] ) && array( 'paypal_client_secret' ) === $a463_result['undecryptable'] );
check( 'nothing is written back to wpcap_settings in plaintext', ! array_intersect_key( (array) get_option( PassCaptain\Settings::OPTION, array() ), array_flip( Secrets::KEYS ) ) );
check( 'WP-CLI command is registered by Secrets::register()', false !== strpos( (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Secrets.php' ), "'pass-captain secrets rollback-config'" ) );

if ( false === $a463_vault ) { delete_option( Secrets::OPTION ); } else { update_option( Secrets::OPTION, $a463_vault ); }

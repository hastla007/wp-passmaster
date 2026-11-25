<?php
/**
 * Wallet test fixture for the R7 regression tests (zz_a5xx files): a throw-away PKI (WWDR CA + pass
 * certificate), a Google service account key, an APNs stub and helpers. Never contacts Apple or
 * Google: APNs goes through the stub client, Google through the scripted HTTP queue (payments.php).
 */
// phpcs:ignoreFile
use PassCaptain\Settings;
use PassCaptain\WalletPasses\AdminPage;
use PassCaptain\WalletPasses\ApnsClient;

if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'wp_salt' ) ) { function wp_salt( $scheme = 'auth' ) { return 'wpw-test-salt-' . $scheme; } }

if ( ! class_exists( 'Wpr7_Test_Apns' ) ) {
	class Wpr7_Test_Apns extends ApnsClient {
		public array $calls = array();
		protected function request( $url, array $headers, $body, array $credentials ) {
			$this->calls[] = array( 'url' => $url );
			return array( 'status' => 200, 'body' => '' );
		}
	}
}

if ( ! function_exists( 'wpr7_wallet_enable' ) ) {
	/**
	 * Enables Apple + Google with fresh test credentials. $cert_days: validity of the pass certificate
	 * (negative = already expired). Returns ['dir' => …, 'apns' => Wpr7_Test_Apns, 'google_pub' => PEM].
	 */
	function wpr7_wallet_enable( $cert_days = 365 ) {
		static $apns = null;
		if ( null === $apns ) {
			$apns = new Wpr7_Test_Apns();
			add_filter( 'wpcap_wallet_apns_client', static function () use ( &$apns ) { return $apns; } );
			// modules/wallet.php registers the module already; a second registration would run every hook twice.
			if ( false === has_action( 'wpcap_ticket_mail', array( \PassCaptain\WalletPasses\Service::class, 'on_ticket_mail' ) ) ) { \PassCaptain\Modules\Wallet::register(); }
		}
		$dir = sys_get_temp_dir() . '/wpr7-wallet-' . getmypid() . '-' . bin2hex( random_bytes( 3 ) );
		@mkdir( $dir );
		$cnf = $dir . '/openssl.cnf';
		file_put_contents( $cnf, "[req]\ndistinguished_name=dn\n[dn]\n[v3_ca]\nbasicConstraints=critical,CA:true\nkeyUsage=critical,keyCertSign,cRLSign\n[usr]\nbasicConstraints=CA:false\nkeyUsage=critical,digitalSignature\n" );
		$cfg = array( 'config' => $cnf, 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA );
		$ca_key = openssl_pkey_new( $cfg );
		$ca_cert = openssl_csr_sign( openssl_csr_new( array( 'commonName' => 'Test WWDR CA' ), $ca_key, $cfg ), null, $ca_key, 3650, $cfg + array( 'x509_extensions' => 'v3_ca' ), 1 );
		$pass_key = openssl_pkey_new( $cfg );
		$csr = openssl_csr_new( array( 'commonName' => 'Pass Type ID: pass.test.wpcap', 'UID' => 'pass.test.wpcap', 'organizationalUnitName' => 'ABCDE12345' ), $pass_key, $cfg );
		if ( $cert_days > 0 ) {
			$pass_cert = openssl_csr_sign( $csr, $ca_cert, $ca_key, $cert_days, $cfg + array( 'x509_extensions' => 'usr' ), 2 );
			openssl_x509_export( $pass_cert, $pass_pem );
		} else {
			// openssl_csr_sign cannot back-date: build an expired certificate with the CLI.
			openssl_csr_export( $csr, $csr_pem );
			openssl_x509_export( $ca_cert, $ca_tmp );
			openssl_pkey_export( $ca_key, $ca_key_tmp, null, $cfg );
			file_put_contents( $dir . '/csr.pem', $csr_pem );
			file_put_contents( $dir . '/ca-tmp.pem', $ca_tmp );
			file_put_contents( $dir . '/ca-key.pem', $ca_key_tmp );
			$start = gmdate( 'YmdHis', time() - 400 * 86400 ) . 'Z';
			$end = gmdate( 'YmdHis', time() + $cert_days * 86400 ) . 'Z';
			file_put_contents( $dir . '/ca.cnf', "[ca]\ndefault_ca=c\n[c]\ndatabase=$dir/index.txt\nnew_certs_dir=$dir\nserial=$dir/serial\ndefault_md=sha256\npolicy=p\nunique_subject=no\n[p]\ncommonName=supplied\n[usr]\nbasicConstraints=CA:false\n" );
			file_put_contents( $dir . '/index.txt', '' );
			file_put_contents( $dir . '/serial', "03\n" );
			exec( 'openssl ca -batch -config ' . escapeshellarg( $dir . '/ca.cnf' ) . ' -extensions usr -cert ' . escapeshellarg( $dir . '/ca-tmp.pem' ) . ' -keyfile ' . escapeshellarg( $dir . '/ca-key.pem' ) . ' -in ' . escapeshellarg( $dir . '/csr.pem' ) . ' -out ' . escapeshellarg( $dir . '/expired.pem' ) . ' -startdate ' . $start . ' -enddate ' . $end . ' -notext 2>/dev/null' );
			$pass_pem = (string) @file_get_contents( $dir . '/expired.pem' );
		}
		openssl_x509_export( $ca_cert, $ca_pem );
		openssl_pkey_export( $pass_key, $pass_key_pem, 'k3y-pass', $cfg );
		$g_key = openssl_pkey_new( $cfg );
		openssl_pkey_export( $g_key, $g_key_pem, null, $cfg );
		AdminPage::save( array(
			'wallet_apple_enabled' => '1', 'wallet_apple_pass_type_id' => 'pass.test.wpcap', 'wallet_apple_team_id' => 'ABCDE12345', 'wallet_apple_delivery' => 'attachment',
			'apple_pass_cert' => $pass_pem, 'apple_pass_key' => $pass_key_pem, 'apple_pass_key_password' => 'k3y-pass', 'apple_wwdr_cert' => $ca_pem,
			'wallet_google_enabled' => '1', 'wallet_google_issuer_id' => '3388000000012345678',
			'google_wallet_service_account' => json_encode( array( 'client_email' => 'wallet@test.iam.gserviceaccount.com', 'private_key' => $g_key_pem, 'token_uri' => 'https://oauth2.googleapis.com/token' ) ),
			'wallet_organization_name' => 'Pass Captain Test',
		) );
		$GLOBALS['wpcap_test_transients'][ \PassCaptain\WalletPasses\GoogleProvider::token_cache_key() ] = 'ya29.wpr7';
		$apns->calls = array();
		array_map( 'unlink', glob( $dir . '/*' ) );
		@rmdir( $dir );
		return array( 'apns' => $apns, 'google_pub' => openssl_pkey_get_details( $g_key )['key'], 'cert_pem' => $pass_pem );
	}

	function wpr7_wallet_disable() {
		Settings::update( array( 'wallet_apple_enabled' => 0, 'wallet_google_enabled' => 0 ) );
		\PassCaptain\WalletPasses\Service::flush();
	}

	function wpr7_unzip( $bytes ) {
		$file = tempnam( sys_get_temp_dir(), 'wpr7' );
		file_put_contents( $file, $bytes );
		$zip = new ZipArchive();
		$out = array();
		if ( true === $zip->open( $file ) ) {
			for ( $i = 0; $i < $zip->numFiles; $i++ ) { $name = $zip->getNameIndex( $i ); $out[ $name ] = $zip->getFromName( $name ); }
			$zip->close();
		}
		unlink( $file );
		return $out;
	}

	/** pass.json of the pass the Apple web service currently serves for a ticket (download link). */
	function wpr7_served_pass( $ticket_id ) {
		$pass = \PassCaptain\WalletPasses\Store::passes( $ticket_id, 'apple' )[0] ?? null;
		if ( ! $pass ) { return null; }
		$bundle = \PassCaptain\WalletPasses\Service::apple_bundle( $pass->serial_number );
		return $bundle ? json_decode( wpr7_unzip( $bundle['bundle'] )['pass.json'] ?? 'null', true ) : null;
	}

	/** The latest ticket mail (subject "Deine Tickets…") sent to $to. */
	function wpr7_ticket_mail( $to ) {
		foreach ( array_reverse( $GLOBALS['wpcap_test_mails'] ) as $m ) {
			if ( $to === $m['to'] && str_starts_with( $m['subject'], 'Deine Tickets' ) ) { return $m; }
		}
		return array( 'body' => '', 'files' => array(), 'subject' => '' );
	}
}

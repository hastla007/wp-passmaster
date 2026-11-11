<?php
/** Wallet module (Apple .pkpass + PassKit web service + APNs, Google Wallet). Included from run.php. */
// phpcs:ignoreFile
use PassCaptain\Orders;
use PassCaptain\Settings;
use PassCaptain\Modules\Wallet;
use PassCaptain\WalletPasses\AdminPage;
use PassCaptain\WalletPasses\ApnsClient;
use PassCaptain\WalletPasses\AppleProvider;
use PassCaptain\WalletPasses\Archive;
use PassCaptain\WalletPasses\Jwt;
use PassCaptain\WalletPasses\Service;
use PassCaptain\WalletPasses\Store;
use PassCaptain\WalletPasses\WebService;

if ( ! function_exists( 'wp_salt' ) ) { function wp_salt( $scheme = 'auth' ) { return 'wpw-test-salt-' . $scheme; } }

if ( ! class_exists( 'Wpw_Test_Apns' ) ) {
	class Wpw_Test_Apns extends ApnsClient {
		public array $calls = array();
		public array $statuses = array();
		protected function request( $url, array $headers, $body, array $credentials ) {
			$this->calls[] = array( 'url' => $url, 'headers' => $headers, 'body' => $body, 'has_cert' => ! empty( $credentials['cert'] ) );
			return array( 'status' => $this->statuses ? array_shift( $this->statuses ) : 200, 'body' => '' );
		}
	}
}

echo "Wallet\n";
Wallet::register();
global $wpdb;
$p = $wpdb->prefix;
$wpw_apns = new Wpw_Test_Apns();
add_filter( 'wpcap_wallet_apns_client', static function () use ( $wpw_apns ) { return $wpw_apns; } );

function wpw_ticket_mail() { foreach ( array_reverse( $GLOBALS['wpcap_test_mails'] ) as $m ) { if ( 'Deine Tickets' === $m['subject'] ) { return $m; } } return array( 'body' => '', 'files' => array() ); }

/* ---- Test PKI: a self-signed "WWDR" CA and a pass certificate signed by it ---- */
$wpw_dir = sys_get_temp_dir() . '/wpw-wallet-' . getmypid();
@mkdir( $wpw_dir );
$wpw_cnf = $wpw_dir . '/openssl.cnf';
file_put_contents( $wpw_cnf, "[req]\ndistinguished_name=dn\n[dn]\n[v3_ca]\nbasicConstraints=critical,CA:true\nkeyUsage=critical,keyCertSign,cRLSign\n[usr]\nbasicConstraints=CA:false\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=clientAuth,emailProtection\n" );
$wpw_cfg = array( 'config' => $wpw_cnf, 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA );
$ca_key = openssl_pkey_new( $wpw_cfg );
$ca_cert = openssl_csr_sign( openssl_csr_new( array( 'commonName' => 'Test WWDR CA' ), $ca_key, $wpw_cfg ), null, $ca_key, 365, $wpw_cfg + array( 'x509_extensions' => 'v3_ca' ), 1 );
$pass_key = openssl_pkey_new( $wpw_cfg );
$pass_cert = openssl_csr_sign( openssl_csr_new( array( 'commonName' => 'Pass Type ID: pass.test.wpcap', 'UID' => 'pass.test.wpcap', 'organizationalUnitName' => 'ABCDE12345' ), $pass_key, $wpw_cfg ), $ca_cert, $ca_key, 365, $wpw_cfg + array( 'x509_extensions' => 'usr' ), 2 );
openssl_x509_export( $ca_cert, $ca_pem );
openssl_x509_export( $pass_cert, $pass_pem );
openssl_pkey_export( $pass_key, $pass_key_pem, 'k3y-pass', $wpw_cfg );
file_put_contents( $wpw_dir . '/ca.pem', $ca_pem );
file_put_contents( $wpw_dir . '/pass.pem', $pass_pem );
$g_key = openssl_pkey_new( $wpw_cfg );
openssl_pkey_export( $g_key, $g_key_pem, null, $wpw_cfg );
$g_pub = openssl_pkey_get_details( $g_key )['key'];
check( 'wallet: test PKI generated', $ca_pem && $pass_pem && $pass_key_pem && $g_key_pem );

/* ---- Not configured: mails stay unchanged ---- */
$type = make_type( 10, 10, 0 );
$r0 = Orders::create( array( 'email' => 'wpw0@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => 'wpw-0' ) );
$m0 = wpw_ticket_mail();
check( 'wallet: without configuration no wallet links or passes', ! is_wp_error( $r0 ) && false === strpos( $m0['body'], 'Wallet' ) && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_wallet_state" ) );

/* ---- Admin settings ---- */
$bad = AdminPage::save( array( 'wallet_apple_pass_type_id' => 'com.example' ) );
check( 'wallet admin: invalid pass type id rejected', is_wp_error( $bad ) && 'wpcap_wallet_pass_type' === $bad->get_error_code() );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
check( 'wallet admin: settings require manage_options', is_wp_error( AdminPage::save( array() ) ) && is_wp_error( AdminPage::test_pass() ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
$saved = AdminPage::save( array(
	'wallet_apple_enabled' => '1', 'wallet_apple_pass_type_id' => 'pass.test.wpcap', 'wallet_apple_team_id' => 'abcde12345', 'wallet_apple_delivery' => 'attachment',
	'apple_pass_cert' => $wpw_dir . '/pass.pem', 'apple_pass_key' => $pass_key_pem, 'apple_pass_key_password' => 'k3y-pass', 'apple_wwdr_cert' => $ca_pem,
	'wallet_google_enabled' => '1', 'wallet_google_issuer_id' => '3388000000012345678',
	'google_wallet_service_account' => json_encode( array( 'client_email' => 'wallet@test.iam.gserviceaccount.com', 'private_key' => $g_key_pem, 'token_uri' => 'https://oauth2.googleapis.com/token' ) ),
	'wallet_background_color' => '#102030', 'wallet_organization_name' => 'Pass Captain Test',
) );
$status = AdminPage::status();
check( 'wallet admin: settings saved, team id normalised', ! is_wp_error( $saved ) && 'ABCDE12345' === Settings::get( 'wallet_apple_team_id' ) && '#102030' === Settings::get( 'wallet_background_color' ) );
check( 'wallet admin: status shows both providers ready with certificate validity', $status['apple']['configured'] && $status['google']['configured'] && '' === $status['apple']['error'] && $status['apple']['cert']['valid_to'] > time() + 300 * 86400 && 'Test WWDR CA' === $status['apple']['wwdr']['subject'] );
AdminPage::save( array( 'wallet_apple_enabled' => '1', 'wallet_apple_pass_type_id' => 'pass.test.wpcap', 'wallet_apple_team_id' => 'ABCDE12345', 'wallet_google_enabled' => '1', 'wallet_google_issuer_id' => '3388000000012345678', 'wallet_background_color' => '#102030', 'wallet_organization_name' => 'Pass Captain Test' ) );
check( 'wallet admin: empty secret fields keep stored values', AdminPage::status()['apple']['configured'] && 'settings' === AdminPage::secret_source( 'apple_pass_key' ) );
$test_pass = AdminPage::test_pass();
check( 'wallet admin: test pass download is a zip', is_string( $test_pass ) && "PK\x03\x04" === substr( $test_pass, 0, 4 ) );

/* ---- helpers ---- */
function wpw_unzip( $bytes ) {
	$file = tempnam( sys_get_temp_dir(), 'wpw' );
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
function wpw_verify_signature( $manifest, $der, $ca_file, &$certs = null ) {
	$dir = sys_get_temp_dir();
	$b = 'wpwboundary' . bin2hex( random_bytes( 4 ) );
	$smime = "MIME-Version: 1.0\nContent-Type: multipart/signed; protocol=\"application/x-pkcs7-signature\"; micalg=\"sha-256\"; boundary=\"$b\"\n\nThis is an S/MIME signed message\n\n--$b\n" . $manifest . "\n--$b\nContent-Type: application/x-pkcs7-signature; name=\"smime.p7s\"\nContent-Transfer-Encoding: base64\nContent-Disposition: attachment; filename=\"smime.p7s\"\n\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "\n--$b--\n";
	$in = tempnam( $dir, 'wpw' ); $signers = tempnam( $dir, 'wpw' ); $content = tempnam( $dir, 'wpw' ); $p7 = tempnam( $dir, 'wpw' );
	file_put_contents( $in, $smime );
	$ok = openssl_pkcs7_verify( $in, PKCS7_BINARY, $signers, array( $ca_file ), $ca_file, $content, $p7 );
	$certs = array();
	if ( true === $ok ) { openssl_pkcs7_read( (string) file_get_contents( $p7 ), $certs ); }
	foreach ( array( $in, $signers, $content, $p7 ) as $f ) { @unlink( $f ); }
	return true === $ok;
}
function wpw_rest( $method, array $params, $token = null, $body = null ) {
	$r = new WP_REST_Request( $method );
	foreach ( $params as $k => $v ) { $r->set_param( $k, $v ); }
	if ( null !== $token ) { $r->set_header( 'Authorization', 'ApplePass ' . $token ); }
	if ( null !== $body ) { $r->set_body( json_encode( $body ) ); }
	return $r;
}
function wpw_status( $res ) { return is_wp_error( $res ) ? (int) ( $res->get_error_data()['status'] ?? 0 ) : $res->get_status(); }
function wpw_pass_json( $res ) { $files = wpw_unzip( $res->get_data()['__wpcap_raw'] ?? '' ); return json_decode( $files['pass.json'] ?? 'null', true ); }
function wpw_jwt_parts( $jwt ) { return array_map( static fn( $s ) => Jwt::b64url_decode( $s ), explode( '.', $jwt ) ); }

/* ---- Issuance: pkpass attached, Google link in mail ---- */
http_reset();
delete_transient( 'wpcap_wallet_google_token' );
$type = make_type( 10, 10, 0 );
$occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$type" );
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET starts_at='2027-03-01 18:30:00', location='Konzerthaus' WHERE occurrence_id=$occ" );
add_filter( 'wpcap_ticket_seat_label', static function ( $label, $ticket_id ) { return 'Reihe 3, Platz 7'; }, 10, 2 );
$r = Orders::create( array( 'email' => 'wpw@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => 'wpw-1' ) );
$mail = wpw_ticket_mail();
$codes = ticket_code_for( $r['order_id'] );
$code = $codes[0] ?? '';
$ticket = tickets( $r['order_id'] )[0];
$pkpass_name = 'ticket-' . substr( $ticket->public_id, 0, 12 ) . '.pkpass';
check( 'wallet mail: .pkpass attached and Google link added', isset( $mail['files'][ $pkpass_name ] ) && false !== strpos( $mail['body'], 'Zu Apple Wallet hinzufügen' ) && false !== strpos( $mail['body'], 'In Google Wallet speichern: https://pay.google.com/gp/v/save/' ) );
check( 'wallet mail: temporary .pkpass removed after sending', ! file_exists( trailingslashit( get_temp_dir() ) . $pkpass_name ) );
$files = wpw_unzip( $mail['files'][ $pkpass_name ] ?? '' );
$expected = array( 'icon.png', 'icon@2x.png', 'logo.png', 'logo@2x.png', 'manifest.json', 'pass.json', 'signature' );
$names = array_keys( $files ); sort( $names );
check( 'pkpass: zip contains pass.json, manifest, signature and @1x/@2x images', $expected === $names );
$manifest = json_decode( $files['manifest.json'] ?? '{}', true );
$hashes_ok = count( $manifest ) === 5;
foreach ( (array) $manifest as $name => $sha ) { $hashes_ok = $hashes_ok && isset( $files[ $name ] ) && sha1( $files[ $name ] ) === $sha; }
check( 'pkpass: manifest has SHA-1 of every file except manifest/signature', $hashes_ok && ! isset( $manifest['signature'] ) );
check( 'pkpass: images are PNGs of the right size', 58 === getimagesizefromstring( $files['icon@2x.png'] )[0] && 29 === getimagesizefromstring( $files['icon.png'] )[0] && 320 === getimagesizefromstring( $files['logo@2x.png'] )[0] );
$certs = array();
check( 'pkpass: detached PKCS#7 signature verifies against the WWDR CA', wpw_verify_signature( $files['manifest.json'], $files['signature'], $wpw_dir . '/ca.pem', $certs ) && 2 === count( $certs ) );
check( 'pkpass: tampered manifest fails verification', ! wpw_verify_signature( str_replace( 'pass.json', 'pass.jsoN', $files['manifest.json'] ), $files['signature'], $wpw_dir . '/ca.pem' ) );
$pj = json_decode( $files['pass.json'], true );
check( 'pass.json: identifiers, serial = ticket public id, organisation', 1 === $pj['formatVersion'] && 'pass.test.wpcap' === $pj['passTypeIdentifier'] && 'ABCDE12345' === $pj['teamIdentifier'] && $ticket->public_id === $pj['serialNumber'] && 'Pass Captain Test' === $pj['organizationName'] );
check( 'pass.json: QR barcode carries the plaintext ticket code', 'PKBarcodeFormatQR' === $pj['barcodes'][0]['format'] && $code === $pj['barcodes'][0]['message'] && '' !== $code );
check( 'pass.json: date in occurrence timezone (Vienna, +01:00)', '2027-03-01T19:30:00+01:00' === $pj['relevantDate'] && '2027-03-01T19:30:00+01:00' === $pj['eventTicket']['secondaryFields'][0]['value'] && true === $pj['eventTicket']['secondaryFields'][0]['ignoresTimeZone'] );
$aux = array_column( $pj['eventTicket']['auxiliaryFields'], 'value', 'key' );
check( 'pass.json: event, category and seat from wpcap_ticket_seat_label', 'Konzert' === $pj['eventTicket']['primaryFields'][0]['value'] && 'Standard' === $aux['category'] && 'Reihe 3, Platz 7' === $aux['seat'] );
check( 'pass.json: web service URL and auth token', 'https://example.test/wp-json/wp-pass-captain/v1/wallet/apple' === $pj['webServiceURL'] && strlen( $pj['authenticationToken'] ) >= 16 && 'rgb(16,32,48)' === $pj['backgroundColor'] );
$state = Store::state( $ticket->ticket_id );
check( 'wallet storage: code encrypted (secretbox), auth token only hashed', $state && false === strpos( $state->secret, $code ) && hash( 'sha256', $code ) === $state->code_hash && hash( 'sha256', $pj['authenticationToken'] ) === $state->auth_token_hash && $code === Store::secrets( $state )['code'] );
$tampered = clone $state; $tampered->secret = substr( $state->secret, 0, -4 ) . 'AAAA';
check( 'wallet storage: tampered ciphertext is rejected', null === Store::secrets( $tampered ) );
$rows = Store::passes( $ticket->ticket_id );
check( 'wallet_passes rows for apple and google', 2 === count( $rows ) && 'apple' === $rows[0]->provider && $ticket->public_id === $rows[0]->serial_number && 'google' === $rows[1]->provider );

/* ---- Google save JWT ---- */
preg_match( '#https://pay\.google\.com/gp/v/save/(\S+)#', $mail['body'], $gm );
$jwt = $gm[1] ?? '';
list( $jh, $jc, $js ) = wpw_jwt_parts( $jwt ) + array( '', '', '' );
$header = json_decode( $jh, true );
$claims = json_decode( $jc, true );
check( 'google JWT: RS256 header and valid signature with the service account key', 'RS256' === $header['alg'] && 1 === openssl_verify( implode( '.', array_slice( explode( '.', $jwt ), 0, 2 ) ), $js, $g_pub, OPENSSL_ALGO_SHA256 ) );
$object = $claims['payload']['eventTicketObjects'][0] ?? array();
$class = $claims['payload']['eventTicketClasses'][0] ?? array();
check( 'google JWT: savetowallet claims with class per occurrence and object per ticket', 'google' === $claims['aud'] && 'savetowallet' === $claims['typ'] && 'wallet@test.iam.gserviceaccount.com' === $claims['iss'] && str_starts_with( $class['id'], '3388000000012345678.' ) && str_ends_with( $class['id'], '_o' . $occ ) && $object['classId'] === $class['id'] );
check( 'google JWT: QR barcode with ticket code, seat, date in occurrence timezone', 'QR_CODE' === $object['barcode']['type'] && $code === $object['barcode']['value'] && 'ACTIVE' === $object['state'] && 'Reihe 3, Platz 7' === $object['seatInfo']['seat']['defaultValue']['value'] && '2027-03-01T19:30:00+01:00' === $class['dateTime']['start'] );

/* ---- PassKit web service ---- */
$auth = $pj['authenticationToken'];
$serial = $ticket->public_id;
$base = array( 'device' => 'devA1b2c3', 'pass_type' => 'pass.test.wpcap', 'serial' => $serial );
check( 'web service: registration without token → 401', 401 === wpw_status( WebService::register_device( wpw_rest( 'POST', $base, null, array( 'pushToken' => 'aabbccddeeff0011' ) ) ) ) );
check( 'web service: registration with wrong token → 401', 401 === wpw_status( WebService::register_device( wpw_rest( 'POST', $base, str_repeat( 'x', 40 ), array( 'pushToken' => 'aabbccddeeff0011' ) ) ) ) );
check( 'web service: wrong pass type → 401', 401 === wpw_status( WebService::register_device( wpw_rest( 'POST', array( 'pass_type' => 'pass.other' ) + $base, $auth, array( 'pushToken' => 'aabbccddeeff0011' ) ) ) ) );
check( 'web service: registration → 201, repeated → 200', 201 === wpw_status( WebService::register_device( wpw_rest( 'POST', $base, $auth, array( 'pushToken' => 'aabbccddeeff0011' ) ) ) ) && 200 === wpw_status( WebService::register_device( wpw_rest( 'POST', $base, $auth, array( 'pushToken' => 'aabbccddeeff0011' ) ) ) ) );
WebService::register_device( wpw_rest( 'POST', array( 'device' => 'devB' ) + $base, $auth, array( 'pushToken' => 'ffeeddccbbaa9988' ) ) );
$list = WebService::serials( wpw_rest( 'GET', array( 'device' => 'devA1b2c3', 'pass_type' => 'pass.test.wpcap', 'passesUpdatedSince' => '' ) ) );
$last = $list->get_data()['lastUpdated'] ?? '';
check( 'web service: serial numbers for device', 200 === $list->get_status() && array( $serial ) === $list->get_data()['serialNumbers'] && '' !== $last );
check( 'web service: nothing updated since lastUpdated → 204, unknown device → 404', 204 === wpw_status( WebService::serials( wpw_rest( 'GET', array( 'device' => 'devA1b2c3', 'pass_type' => 'pass.test.wpcap', 'passesUpdatedSince' => $last ) ) ) ) && 404 === wpw_status( WebService::serials( wpw_rest( 'GET', array( 'device' => 'nobody', 'pass_type' => 'pass.test.wpcap' ) ) ) ) );
$latest = WebService::latest_pass( wpw_rest( 'GET', array( 'pass_type' => 'pass.test.wpcap', 'serial' => $serial ), $auth ) );
$lj = wpw_pass_json( $latest );
check( 'web service: latest pass is a signed pkpass with Last-Modified', 200 === $latest->get_status() && 'application/vnd.apple.pkpass' === $latest->get_data()['headers']['Content-Type'] && ! empty( $latest->get_data()['headers']['Last-Modified'] ) && $code === $lj['barcodes'][0]['message'] );
$ims = wpw_rest( 'GET', array( 'pass_type' => 'pass.test.wpcap', 'serial' => $serial ), $auth );
$ims->set_header( 'If-Modified-Since', gmdate( 'D, d M Y H:i:s', time() + 60 ) . ' GMT' );
check( 'web service: If-Modified-Since → 304', 304 === wpw_status( WebService::latest_pass( $ims ) ) );
$h1 = $latest->get_data()['headers'];
Store::touch( $ticket->ticket_id, 'forced-change', Store::state( $ticket->ticket_id )->update_tag );
$ims_same = wpw_rest( 'GET', array( 'pass_type' => 'pass.test.wpcap', 'serial' => $serial ), $auth );
$ims_same->set_header( 'If-Modified-Since', $h1['Last-Modified'] );
$etag_old = wpw_rest( 'GET', array( 'pass_type' => 'pass.test.wpcap', 'serial' => $serial ), $auth );
$etag_old->set_header( 'If-None-Match', $h1['ETag'] );
$fresh = WebService::latest_pass( $etag_old );
$etag_new = wpw_rest( 'GET', array( 'pass_type' => 'pass.test.wpcap', 'serial' => $serial ), $auth );
$etag_new->set_header( 'If-None-Match', $fresh->get_data()['headers']['ETag'] ?? '' );
check( 'web service (regression): update in the same second is not hidden by If-Modified-Since/ETag', 200 === wpw_status( WebService::latest_pass( $ims_same ) ) && 200 === $fresh->get_status() && 304 === wpw_status( WebService::latest_pass( $etag_new ) ) );
check( 'update tags always advance to a later second', intdiv( Store::next_tag( (int) floor( microtime( true ) * 1000 ) ), 1000 ) > intdiv( (int) floor( microtime( true ) * 1000 ), 1000 ) - 1 && intdiv( Store::next_tag( 5000999 ), 1000 ) === max( 5001, intdiv( (int) floor( microtime( true ) * 1000 ), 1000 ) ) );
Service::sync_ticket( $ticket->ticket_id );
check( 'web service: latest pass requires the token', 401 === wpw_status( WebService::latest_pass( wpw_rest( 'GET', array( 'pass_type' => 'pass.test.wpcap', 'serial' => $serial ), str_repeat( 'a', 40 ) ) ) ) );
$server_log_before = get_option( 'wpcap_wallet_log', array() );
WebService::log( wpw_rest( 'POST', array(), null, array( 'logs' => array( 'Web service error for pass.test.wpcap token ' . $auth ) ) ) );
$wlog_all = get_option( Service::DEVICE_LOG_OPTION );
$wlog = end( $wlog_all );
check( 'web service: device log stored without tokens', 'device' === $wlog['code'] && false === strpos( $wlog['message'], $auth ) );
for ( $i = 0; $i < 20; $i++ ) { WebService::log( wpw_rest( 'POST', array(), null, array( 'logs' => array( "spam $i a", "spam $i b", "spam $i c", "spam $i d" ) ) ) ); }
$device_log = get_option( Service::DEVICE_LOG_OPTION );
check( 'web service log (regression): unauthenticated posts cannot flood the server diagnostics', $server_log_before === get_option( 'wpcap_wallet_log', array() ) && count( $device_log ) <= 10 && ! in_array( 'spam 5 a', array_column( $device_log, 'message' ), true ) );
$dl_url = Service::download_url( $serial );
parse_str( (string) parse_url( $dl_url, PHP_URL_QUERY ), $dlq );
check( 'download link: valid signature → pkpass, wrong → 404', 200 === wpw_status( WebService::download( wpw_rest( 'GET', array( 'serial' => $serial, 'sig' => $dlq['sig'] ?? '' ) ) ) ) && 404 === wpw_status( WebService::download( wpw_rest( 'GET', array( 'serial' => $serial, 'sig' => str_repeat( '0', 64 ) ) ) ) ) );

/* ---- Revocation: APNs push + Google object INACTIVE ---- */
http_reset();
http_push( array( 'access_token' => 'ya29.test', 'expires_in' => 3600 ), 200, 'oauth2.googleapis.com' );
http_push( array( 'id' => 'x' ), 200, 'eventTicketObject/' );
$wpw_apns->calls = array();
$wpw_apns->statuses = array( 200, 410 );
$rev = PassCaptain\Admin\TicketsPage::revoke_ticket( array( 'ticket_id' => $ticket->ticket_id, 'reason' => 'Test' ) );
check( 'revoke (regression): nothing is sent while the request is still running', 0 === count( $wpw_apns->calls ) );
Service::flush();
$revoke_job = $wpdb->get_row( "SELECT * FROM {$p}wpcap_jobs WHERE job_type='wallet_sync' ORDER BY job_id DESC LIMIT 1" );
check( 'revoke: dispatched once on shutdown; safety job completed after inline success', $revoke_job && 'complete' === $revoke_job->status );
$push_urls = array_column( $wpw_apns->calls, 'url' );
check( 'revoke: APNs push to each registered device with pass type topic', ! is_wp_error( $rev ) && 2 === count( $wpw_apns->calls ) && in_array( 'https://api.push.apple.com/3/device/aabbccddeeff0011', $push_urls, true ) && in_array( 'apns-topic: pass.test.wpcap', $wpw_apns->calls[0]['headers'], true ) && '{}' === $wpw_apns->calls[0]['body'] && $wpw_apns->calls[0]['has_cert'] );
check( 'revoke: APNs 410 removes the stale registration', 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_wallet_registrations WHERE serial_number='" . esc_sql_wpw( $serial ) . "'" ) );
function esc_sql_wpw( $s ) { return preg_replace( '/[^a-z0-9]/', '', $s ); }
$token_req = http_requests( 'oauth2.googleapis.com' )[0] ?? array( 'body' => '' );
$tq = form_body( $token_req );
list( , $ac, $as ) = wpw_jwt_parts( $tq['assertion'] ?? 'a.b.c' );
$assertion = json_decode( $ac, true );
check( 'google OAuth: JWT bearer grant signed with the service account key', 'urn:ietf:params:oauth:grant-type:jwt-bearer' === ( $tq['grant_type'] ?? '' ) && 'https://www.googleapis.com/auth/wallet_object.issuer' === $assertion['scope'] && 'https://oauth2.googleapis.com/token' === $assertion['aud'] && 1 === openssl_verify( implode( '.', array_slice( explode( '.', $tq['assertion'] ), 0, 2 ) ), $as, $g_pub, OPENSSL_ALGO_SHA256 ) );
$patch = http_requests( 'eventTicketObject/' )[0] ?? array();
$patch_body = json_decode( $patch['body'] ?? '{}', true );
check( 'google: object PATCHed to INACTIVE without barcode', 'PATCH' === ( $patch['method'] ?? '' ) && 'Bearer ya29.test' === $patch['headers']['Authorization'] && 'INACTIVE' === $patch_body['state'] && ! isset( $patch_body['barcode'] ) && false !== strpos( $patch['url'], rawurlencode( $object['id'] ) ) );
$after = WebService::serials( wpw_rest( 'GET', array( 'device' => 'devA1b2c3', 'pass_type' => 'pass.test.wpcap', 'passesUpdatedSince' => $last ) ) );
$vj = wpw_pass_json( WebService::latest_pass( wpw_rest( 'GET', array( 'pass_type' => 'pass.test.wpcap', 'serial' => $serial ), $auth ) ) );
check( 'revoke: serial reported as updated, pass served voided without barcode', 200 === $after->get_status() && array( $serial ) === $after->get_data()['serialNumbers'] && true === $vj['voided'] && ! isset( $vj['barcodes'] ) );
check( 'revoke: wallet_passes marked voided', 'voided' === Store::passes( $ticket->ticket_id, 'apple' )[0]->status );

/* ---- Reschedule: class + object update, new date in pass ---- */
$r2 = Orders::create( array( 'email' => 'wpw2@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => 'wpw-2' ) );
$mail2 = wpw_ticket_mail();
$ticket2 = tickets( $r2['order_id'] )[0];
$code2 = ticket_code_for( $r2['order_id'] )[0] ?? '';
$pj2 = json_decode( wpw_unzip( $mail2['files'][ 'ticket-' . substr( $ticket2->public_id, 0, 12 ) . '.pkpass' ] ?? '' )['pass.json'] ?? '{}', true );
$auth2 = $pj2['authenticationToken'] ?? '';
WebService::register_device( wpw_rest( 'POST', array( 'device' => 'devC', 'pass_type' => 'pass.test.wpcap', 'serial' => $ticket2->public_id ), $auth2, array( 'pushToken' => 'c0ffee00c0ffee00' ) ) );
$obj2_id = Store::passes( $ticket2->ticket_id, 'google' )[0]->serial_number ?? 'none';
http_reset();
http_push( array(), 200, 'eventTicketClass/' );
http_push( array(), 200, 'eventTicketObject/' . $object['id'] );
http_push( array(), 404, 'eventTicketObject/' . $obj2_id );
http_push( array(), 200, 'walletobjects/v1/eventTicketClass' );
http_push( array(), 200, 'walletobjects/v1/eventTicketObject' );
$wpw_apns->calls = array();
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET starts_at='2027-03-08 17:00:00' WHERE occurrence_id=$occ" );
do_action( 'wpcap_occurrence_rescheduled', $occ, '2027-03-01 18:30:00', '2027-03-08 17:00:00' );
Service::flush();
$rj = wpw_pass_json( WebService::latest_pass( wpw_rest( 'GET', array( 'pass_type' => 'pass.test.wpcap', 'serial' => $ticket2->public_id ), $auth2 ) ) );
check( 'reschedule: devices of all passes pushed and pass shows the new local time', 2 === count( $wpw_apns->calls ) && '2027-03-08T18:00:00+01:00' === $rj['relevantDate'] && $code2 === $rj['barcodes'][0]['message'] );
$class_patch = json_decode( http_requests( 'eventTicketClass/' )[0]['body'] ?? '{}', true );
$posts = array_filter( http_requests( 'walletobjects' ), static fn( $q ) => 'POST' === $q['method'] );
check( 'reschedule: Google class PATCHed with new date; missing object inserted', '2027-03-08T18:00:00+01:00' === ( $class_patch['dateTime']['start'] ?? '' ) && 2 === count( $posts ) );

/* ---- Code rotation (resend): new generation, old devices get a voided pass ---- */
http_reset();
http_push( array(), 200, 'eventTicketObject/' );
http_push( array(), 404, 'eventTicketObject/' );
http_push( array(), 409, 'walletobjects/v1/eventTicketClass' );
http_push( array(), 200, 'walletobjects/v1/eventTicketObject' );
$wpw_apns->calls = array();
// Core fires wpcap_ticket_code_rotated and adds wpcap_ticket_code_mail_section to the resend mail.
Orders::resend_tickets( $r2['order_id'] );
Service::flush();
$resent = wpw_ticket_mail();
preg_match( '/wpcap_ticket=([a-f0-9]{64})/', $resent['body'], $nm );
$new_code = $nm[1] ?? '';
$filtered = array( 'message' => $resent['body'] );
$state2 = Store::state( $ticket2->ticket_id );
check( 'rotation: core hook starts a new generation and the resend mail carries wallet links', 2 === (int) $state2->generation && hash( 'sha256', $new_code ) === $state2->code_hash && false !== strpos( $filtered['message'], '/wallet/apple/download/' . $ticket2->public_id ) && false !== strpos( $filtered['message'], 'pay.google.com/gp/v/save/' ) );
check( 'rotation: devices of the old generation are pushed', 1 === count( $wpw_apns->calls ) );
$old = wpw_pass_json( WebService::latest_pass( wpw_rest( 'GET', array( 'pass_type' => 'pass.test.wpcap', 'serial' => $ticket2->public_id ), $auth2 ) ) );
check( 'rotation: old auth token gets a voided pass without the new code', true === ( $old['voided'] ?? false ) && ! isset( $old['barcodes'] ) && $auth2 === $old['authenticationToken'] );
check( 'rotation: old token can no longer register', 401 === wpw_status( WebService::register_device( wpw_rest( 'POST', array( 'device' => 'devD', 'pass_type' => 'pass.test.wpcap', 'serial' => $ticket2->public_id ), $auth2, array( 'pushToken' => 'abcdefabcdef1234' ) ) ) ) );
check( 'rotation: unregister with old token still works', 200 === wpw_status( WebService::unregister_device( wpw_rest( 'DELETE', array( 'device' => 'devC', 'pass_type' => 'pass.test.wpcap', 'serial' => $ticket2->public_id ), $auth2 ) ) ) );
preg_match( '#(https://example\.test/wp-json/wp-pass-captain/v1/wallet/apple/download/\S+)#', $filtered['message'], $dm );
parse_str( (string) parse_url( $dm[1] ?? '', PHP_URL_QUERY ), $dq );
$new_pass = wpw_pass_json( WebService::download( wpw_rest( 'GET', array( 'serial' => $ticket2->public_id, 'sig' => $dq['sig'] ?? '' ) ) ) );
check( 'rotation: new download link serves the new code', $new_code === ( $new_pass['barcodes'][0]['message'] ?? '' ) && $auth2 !== $new_pass['authenticationToken'] );
$google_rows = Store::passes( $ticket2->ticket_id, 'google' );
$obj_reqs = http_requests( 'eventTicketObject/' );
check( 'rotation: old Google object retired (INACTIVE), new generation object created', 2 === count( $google_rows ) && 'retired' === $google_rows[0]->status && str_ends_with( $google_rows[1]->serial_number, '_g2' ) && 'INACTIVE' === ( json_decode( $obj_reqs[0]['body'] ?? '{}', true )['state'] ?? '' ) );
check( 'rotation: repeated hook call for the same code is idempotent', array() === Service::on_code_rotated( $ticket2->ticket_id, $new_code ) );
$wpdb->insert( "{$p}wpcap_ticket_assignments", array( 'ticket_id' => $ticket2->ticket_id, 'buyer_email' => 'wpw2@example.com', 'attendee_email' => 'neu@example.com', 'attendee_name' => 'Neue Inhaberin', 'status' => 'assigned', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
Store::register_device( 'devOld', 'pass.test.wpcap', $ticket2->public_id, 'dd00dd00dd00dd00', hash( 'sha256', $auth2 ) ); // a device still on the old generation
$old_view = wpw_pass_json( WebService::latest_pass( wpw_rest( 'GET', array( 'pass_type' => 'pass.test.wpcap', 'serial' => $ticket2->public_id ), $auth2 ) ) );
$new_view = wpw_pass_json( WebService::latest_pass( wpw_rest( 'GET', array( 'pass_type' => 'pass.test.wpcap', 'serial' => $ticket2->public_id ), $new_pass['authenticationToken'] ) ) );
$old_aux = array_column( $old_view['eventTicket']['auxiliaryFields'] ?? array(), 'value', 'key' );
$new_aux = array_column( $new_view['eventTicket']['auxiliaryFields'] ?? array(), 'value', 'key' );
check( 'rotation (regression): previous holder sees neither the new attendee name nor the seat', ! isset( $old_aux['attendee'] ) && ! isset( $old_aux['seat'] ) && false === strpos( wp_json_encode( $old_view ), 'Neue Inhaberin' ) && 'Neue Inhaberin' === ( $new_aux['attendee'] ?? '' ) && 'Reihe 3, Platz 7' === ( $new_aux['seat'] ?? '' ) );

/* ---- Failed push → retried via the job queue ---- */
http_reset();
http_push( array(), 500, 'eventTicketObject/' );
$wpw_apns->statuses = array( 503 );
WebService::register_device( wpw_rest( 'POST', array( 'device' => 'devE', 'pass_type' => 'pass.test.wpcap', 'serial' => $ticket2->public_id ), $new_pass['authenticationToken'], array( 'pushToken' => 'e0e0e0e0e0e0e0e0' ) ) );
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET location='Stadthalle' WHERE occurrence_id=$occ" );
do_action( 'wpcap_occurrence_cancelled', $occ, 1 );
Service::flush();
$job = $wpdb->get_row( "SELECT * FROM {$p}wpcap_jobs WHERE job_type='wallet_sync' ORDER BY job_id DESC LIMIT 1" );
check( 'retry: failed APNs/Google update is queued as wallet_sync job', $job && 'queued' === $job->status );
http_reset();
http_push( array(), 200, 'eventTicketClass/' );
http_push( array(), 200, 'eventTicketObject/' );
http_push( array(), 200, 'eventTicketObject/' );
http_push( array(), 200, 'eventTicketObject/' );
$wpw_apns->statuses = array();
$handled = Service::handle_job( false, 'wallet_sync', json_decode( $job->payload, true ) );
check( 'retry: job handler succeeds once services answer', true === $handled && false === Service::handle_job( false, 'other_job', array() ) );

/* ---- Regression: per-ticket revoke hooks from a cancellation are batched; class always updated ---- */
http_reset();
http_push( array(), 200, 'walletobjects' );
$wpw_apns->calls = array();
$wpw_apns->statuses = array();
$type3 = make_type( 10, 10, 0 );
$occ3 = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$type3" );
$r3 = Orders::create( array( 'email' => 'wpw3@example.com', 'items' => array( array( 'ticket_type_id' => $type3, 'quantity' => 3 ) ), 'idempotency_key' => 'wpw-3' ) );
$mail3 = wpw_ticket_mail();
foreach ( tickets( $r3['order_id'] ) as $i => $t3 ) {
	$pj3 = json_decode( wpw_unzip( $mail3['files'][ 'ticket-' . substr( $t3->public_id, 0, 12 ) . '.pkpass' ] ?? '' )['pass.json'] ?? '{}', true );
	WebService::register_device( wpw_rest( 'POST', array( 'device' => 'devF' . $i, 'pass_type' => 'pass.test.wpcap', 'serial' => $t3->public_id ), $pj3['authenticationToken'] ?? '', array( 'pushToken' => 'f0f0f0f0f0f0f00' . $i ) ) );
}
http_reset();
for ( $i = 0; $i < 4; $i++ ) { http_push( array(), 200, 'walletobjects' ); }
$jobs_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_jobs WHERE job_type='wallet_sync'" );
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET status='cancelled' WHERE occurrence_id=$occ3" );
PassCaptain\OccurrenceCancellation::handle( $occ3 );
do_action( 'wpcap_occurrence_cancelled', $occ3, 3 );
$calls_before_flush = count( $wpw_apns->calls );
Service::flush();
$jobs_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_jobs WHERE job_type='wallet_sync'" );
$class3 = array_values( array_filter( http_requests( 'eventTicketClass/' ), static fn( $q ) => 'PATCH' === $q['method'] ) );
$class3_body = json_decode( $class3[0]['body'] ?? '{}', true );
check( 'cancellation (regression): 3 revoke hooks → no inline calls during the request, one dispatch at shutdown', 0 === $calls_before_flush && 3 === count( $wpw_apns->calls ) && 1 === $jobs_after - $jobs_before );
check( 'cancellation (regression): Google class updated although tickets were already synced by the revoke hooks', 1 === count( $class3 ) && str_ends_with( $class3_body['id'] ?? '', '_o' . $occ3 ) && 'wpcap_cancelled' === ( $class3_body['messages'][0]['id'] ?? '' ) );
$wpw_apns->calls = array();
Service::queue( range( 900001, 900000 + Service::INLINE_LIMIT + 5 ) );
Service::flush();
$big = $wpdb->get_row( "SELECT * FROM {$p}wpcap_jobs WHERE job_type='wallet_sync' ORDER BY job_id DESC LIMIT 1" );
check( 'dispatch: more than INLINE_LIMIT tickets go to the job queue only', 'queued' === $big->status && Service::INLINE_LIMIT + 5 === count( json_decode( $big->payload, true )['ticket_ids'] ) );
$wpdb->query( "UPDATE {$p}wpcap_jobs SET status='complete' WHERE job_id=" . (int) $big->job_id );

/* ---- Rotation for reason 'print' (Printing::rotate_codes) ---- */
$print_code = bin2hex( random_bytes( 32 ) );
$gen_before = (int) Store::state( $ticket2->ticket_id )->generation;
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_tickets SET token_hash=%s WHERE ticket_id=%d", hash( 'sha256', $print_code ), $ticket2->ticket_id ) );
http_reset();
for ( $i = 0; $i < 6; $i++ ) { http_push( array(), 200, 'walletobjects' ); }
do_action( 'wpcap_ticket_code_rotated', $ticket2->ticket_id, $print_code, 'print' );
Service::flush();
check( 'rotation: reason "print" starts a new generation too', $gen_before + 1 === (int) Store::state( $ticket2->ticket_id )->generation && hash( 'sha256', $print_code ) === Store::state( $ticket2->ticket_id )->code_hash );

/* ---- Zip fallback + PNG fallback ---- */
$fallback = wpw_unzip( Archive::stored_zip( array( 'a.txt' => 'hello', 'pass.json' => '{"x":1}' ) ) );
check( 'zip fallback: built-in writer produces a valid archive', array( 'a.txt' => 'hello', 'pass.json' => '{"x":1}' ) === $fallback );
$png = Archive::solid_png( 3, 2, array( 255, 0, 0 ) );
$im = imagecreatefromstring( $png );
check( 'png fallback: dependency-free PNG decodes', $im && 3 === imagesx( $im ) && 0xff0000 === ( imagecolorat( $im, 1, 1 ) & 0xffffff ) );
check( 'bridge: providers resolve through the versioned filter', PassCaptain\Wallet\Bridge::provider( 'apple' ) instanceof AppleProvider && PassCaptain\Wallet\Bridge::available( 'google' ) && null === PassCaptain\Wallet\Bridge::provider( 'samsung' ) );

/* ---- cleanup: disable for later tests ---- */
Settings::update( array( 'wallet_apple_enabled' => 0, 'wallet_google_enabled' => 0 ) );
array_map( 'unlink', glob( $wpw_dir . '/*' ) );
@rmdir( $wpw_dir );

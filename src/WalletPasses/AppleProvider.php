<?php
/**
 * Apple Wallet (.pkpass, style eventTicket): pass.json, images, manifest, PKCS#7 signature, zip,
 * and APNs update pushes. Registered through PassCaptain\Wallet\Bridge as provider "apple".
 */
namespace PassCaptain\WalletPasses;

use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class AppleProvider implements WalletProvider {
	const REST_BASE = 'wallet/apple';

	public function id() { return 'apple'; }

	public function pass_type_id() { return trim( (string) Settings::get( 'wallet_apple_pass_type_id' ) ); }

	public function team_id() { return trim( (string) Settings::get( 'wallet_apple_team_id' ) ); }

	public function is_configured() {
		return (bool) Settings::get( 'wallet_apple_enabled' ) && '' !== $this->pass_type_id() && '' !== $this->team_id() && is_array( Credentials::apple() );
	}

	public static function organization() {
		$name = trim( (string) Settings::get( 'wallet_organization_name' ) );
		return '' !== $name ? $name : (string) get_bloginfo( 'name' );
	}

	/** webServiceURL; Apple appends /v1/devices/… and /v1/passes/…. */
	public static function web_service_url() { return rest_url( 'wp-pass-captain/v1/' . self::REST_BASE ); }

	/* ---- ProviderInterface ---- */

	public function create_pass( array $ticket ) {
		return array( 'provider' => 'apple', 'serial_number' => (string) $ticket['public_id'], 'provider_ref' => $this->pass_type_id(), 'bundle' => $this->bundle( $this->pass_json( $ticket ) ) );
	}

	public function update_pass( array $ticket, array $provider_pass ) {
		return $this->create_pass( $ticket );
	}

	/** The pass itself is served voided by the web service; tell the devices to fetch it. */
	public function revoke_pass( array $provider_pass ) {
		return $this->notify( (string) ( $provider_pass['serial_number'] ?? '' ) );
	}

	/* ---- pass.json ---- */

	private static function local_iso( $utc, $timezone ) {
		if ( ! $utc ) { return null; }
		try { $tz = new \DateTimeZone( $timezone ?: 'UTC' ); } catch ( \Exception $e ) { $tz = new \DateTimeZone( 'UTC' ); }
		return ( new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) ) )->setTimezone( $tz )->format( 'Y-m-d\TH:i:sP' );
	}

	private static function color( $key, $fallback ) {
		list( $r, $g, $b ) = Archive::rgb( Settings::get( $key ) ?: $fallback );
		return "rgb($r,$g,$b)";
	}

	public function pass_json( array $ticket ) {
		$start = self::local_iso( $ticket['starts_at'], $ticket['timezone'] );
		$end_utc = $ticket['ends_at'] ?: gmdate( 'Y-m-d H:i:s', strtotime( $ticket['starts_at'] . ' UTC' ) + 6 * HOUR_IN_SECONDS );
		$voided = ! empty( $ticket['voided'] ) || '' === (string) ( $ticket['code'] ?? '' );
		$secondary = array( array( 'key' => 'date', 'label' => __( 'Datum', 'pass-captain' ), 'value' => $start, 'dateStyle' => 'PKDateStyleMedium', 'timeStyle' => 'PKDateStyleShort', 'ignoresTimeZone' => true ) );
		if ( '' !== (string) $ticket['location'] ) { $secondary[] = array( 'key' => 'location', 'label' => __( 'Ort', 'pass-captain' ), 'value' => (string) $ticket['location'] ); }
		$auxiliary = array( array( 'key' => 'category', 'label' => __( 'Ticket', 'pass-captain' ), 'value' => (string) $ticket['ticket_name'] ) );
		if ( '' !== (string) ( $ticket['seat'] ?? '' ) ) { $auxiliary[] = array( 'key' => 'seat', 'label' => __( 'Platz', 'pass-captain' ), 'value' => (string) $ticket['seat'] ); }
		if ( '' !== (string) ( $ticket['attendee_name'] ?? '' ) ) { $auxiliary[] = array( 'key' => 'attendee', 'label' => __( 'Name', 'pass-captain' ), 'value' => (string) $ticket['attendee_name'] ); }
		$back = array(
			array( 'key' => 'ticket_id', 'label' => __( 'Ticketnummer', 'pass-captain' ), 'value' => (string) $ticket['public_id'] ),
			array( 'key' => 'timezone', 'label' => __( 'Zeitzone', 'pass-captain' ), 'value' => (string) $ticket['timezone'] ),
			array( 'key' => 'note', 'label' => __( 'Hinweis', 'pass-captain' ), 'value' => $voided ? __( 'Dieser Pass ist nicht mehr gültig. Bitte verwende das zuletzt per E-Mail erhaltene Ticket.', 'pass-captain' ) : __( 'Dieses Ticket ist nur einmal gültig. Code nicht weitergeben.', 'pass-captain' ) ),
		);
		if ( 'cancelled' === ( $ticket['occurrence_status'] ?? '' ) || 'cancelled' === ( $ticket['event_status'] ?? '' ) ) {
			array_unshift( $back, array( 'key' => 'status', 'label' => __( 'Status', 'pass-captain' ), 'value' => __( 'Die Veranstaltung wurde abgesagt.', 'pass-captain' ), 'changeMessage' => '%@' ) );
		}
		$secondary[0]['changeMessage'] = __( 'Neuer Termin: %@', 'pass-captain' );
		$json = array(
			'formatVersion' => 1,
			'passTypeIdentifier' => $this->pass_type_id(),
			'teamIdentifier' => $this->team_id(),
			'serialNumber' => (string) $ticket['public_id'],
			'organizationName' => self::organization(),
			/* translators: %s: event name */
			'description' => sprintf( __( 'Ticket: %s', 'pass-captain' ), $ticket['event_name'] ),
			'logoText' => self::organization(),
			'foregroundColor' => self::color( 'wallet_foreground_color', '#ffffff' ),
			'backgroundColor' => self::color( 'wallet_background_color', '#1d2327' ),
			'labelColor' => self::color( 'wallet_label_color', '#c3c4c7' ),
			'relevantDate' => $start,
			'expirationDate' => self::local_iso( gmdate( 'Y-m-d H:i:s', strtotime( $end_utc . ' UTC' ) + 12 * HOUR_IN_SECONDS ), $ticket['timezone'] ),
			'sharingProhibited' => true,
			'eventTicket' => array(
				'primaryFields' => array( array( 'key' => 'event', 'label' => __( 'Veranstaltung', 'pass-captain' ), 'value' => (string) $ticket['event_name'] ) ),
				'secondaryFields' => $secondary,
				'auxiliaryFields' => $auxiliary,
				'backFields' => $back,
			),
		);
		if ( ! empty( $ticket['auth_token'] ) ) {
			$json['webServiceURL'] = self::web_service_url();
			$json['authenticationToken'] = (string) $ticket['auth_token'];
		}
		if ( $voided ) {
			$json['voided'] = true;
		} else {
			$json['barcodes'] = array( array( 'format' => 'PKBarcodeFormatQR', 'message' => (string) $ticket['code'], 'messageEncoding' => 'iso-8859-1', 'altText' => substr( (string) $ticket['public_id'], 0, 8 ) ) );
		}
		/** Optional geo relevance: list of ['latitude'=>…, 'longitude'=>…, 'relevantText'=>…]. */
		$locations = apply_filters( 'wpcap_wallet_apple_locations', array(), $ticket );
		if ( is_array( $locations ) && $locations ) { $json['locations'] = array_values( array_slice( $locations, 0, 10 ) ); }
		return apply_filters( 'wpcap_wallet_apple_pass_json', $json, $ticket );
	}

	/* ---- bundle ---- */

	/** icon.png, icon@2x.png, logo.png, logo@2x.png (operator files from settings or generated). */
	public function images() {
		$bg = Settings::get( 'wallet_background_color' ) ?: '#1d2327';
		$fg = Settings::get( 'wallet_foreground_color' ) ?: '#ffffff';
		$images = array(
			'icon.png' => Archive::icon_png( 29, $bg, $fg ),
			'icon@2x.png' => Archive::icon_png( 58, $bg, $fg ),
			'logo.png' => Archive::logo_png( 160, 50, self::organization(), $fg ),
			'logo@2x.png' => Archive::logo_png( 320, 100, self::organization(), $fg ),
		);
		foreach ( array( 'icon' => 'wallet_apple_icon_path', 'logo' => 'wallet_apple_logo_path' ) as $name => $key ) {
			$path = trim( (string) Settings::get( $key ) );
			if ( '' !== $path && is_readable( $path ) && "\x89PNG" === substr( (string) file_get_contents( $path, false, null, 0, 4 ), 0, 4 ) ) {
				$images[ $name . '.png' ] = (string) file_get_contents( $path );
				$retina = preg_replace( '/\.png$/i', '@2x.png', $path );
				$images[ $name . '@2x.png' ] = is_readable( $retina ) ? (string) file_get_contents( $retina ) : $images[ $name . '.png' ];
			}
		}
		return apply_filters( 'wpcap_wallet_apple_images', $images );
	}

	/** Builds the signed .pkpass bytes. */
	public function bundle( array $pass_json, $credentials = null ) {
		$credentials = $credentials ?: Credentials::apple();
		if ( is_wp_error( $credentials ) ) { throw new \RuntimeException( $credentials->get_error_code() ); }
		$files = array( 'pass.json' => (string) json_encode( $pass_json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) + $this->images();
		$manifest = array();
		foreach ( $files as $name => $bytes ) { $manifest[ $name ] = sha1( $bytes ); }
		$files['manifest.json'] = (string) json_encode( $manifest, JSON_UNESCAPED_SLASHES );
		$files['signature'] = self::sign( $files['manifest.json'], $credentials );
		return Archive::zip( $files );
	}

	/** Detached PKCS#7 signature (DER) of the manifest incl. the WWDR intermediate. */
	public static function sign( $manifest, array $credentials ) {
		$dir = get_temp_dir();
		$in = tempnam( $dir, 'wpcap-mf' );
		$out = tempnam( $dir, 'wpcap-sig' );
		$wwdr = tempnam( $dir, 'wpcap-wwdr' );
		file_put_contents( $in, $manifest );
		file_put_contents( $wwdr, $credentials['wwdr'] );
		$ok = openssl_pkcs7_sign( $in, $out, $credentials['cert'], array( $credentials['key'], (string) $credentials['password'] ), array(), PKCS7_BINARY | PKCS7_DETACHED, $wwdr );
		$smime = $ok ? (string) file_get_contents( $out ) : '';
		foreach ( array( $in, $out, $wwdr ) as $file ) { wp_delete_file( $file ); }
		if ( ! preg_match( '/filename="?smime\.p7s"?\s*\r?\n\r?\n(.+?)\r?\n\r?\n?-{2,}/s', $smime, $m ) ) {
			throw new \RuntimeException( 'wallet_sign_failed' );
		}
		$der = base64_decode( preg_replace( '/\s+/', '', $m[1] ), true );
		if ( false === $der || '' === $der ) { throw new \RuntimeException( 'wallet_sign_failed' ); }
		return $der;
	}

	/* ---- push ---- */

	public static function apns_client() {
		$client = apply_filters( 'wpcap_wallet_apns_client', null );
		return $client instanceof ApnsClient ? $client : new ApnsClient();
	}

	/**
	 * Sends an update push to every device registered for the serial. Tokens answered with 410
	 * (device unregistered) are removed. Returns false on transport/server errors (retry later).
	 */
	public function notify( $serial ) {
		$registrations = Store::registrations_for_serial( $serial );
		if ( ! $registrations ) { return true; }
		$credentials = Credentials::apple();
		if ( is_wp_error( $credentials ) ) { return false; }
		$client = self::apns_client();
		$host = (string) ( Settings::get( 'wallet_apns_host' ) ?: 'https://api.push.apple.com' );
		$ok = true;
		$sent = array();
		foreach ( $registrations as $registration ) {
			if ( isset( $sent[ $registration->push_token ] ) ) { continue; }
			$status = $client->send( $registration->push_token, $registration->pass_type_id, $credentials, $host );
			$sent[ $registration->push_token ] = $status;
			if ( 410 === $status ) { Store::delete_push_token( $registration->push_token ); continue; }
			if ( 200 !== $status && 400 !== $status ) { $ok = false; }
		}
		return $ok;
	}
}

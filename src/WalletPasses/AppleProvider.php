<?php
/**
 * Apple Wallet (.pkpass, style eventTicket): pass.json, images, manifest, PKCS#7 signature, zip,
 * and APNs update pushes. Registered through PassCaptain\Wallet\Bridge as provider "apple".
 */
namespace PassCaptain\WalletPasses;

use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
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
	public static function web_service_url() { return rest_url( 'pass-captain/v1/' . self::REST_BASE ); }

	/* ---- ProviderInterface ---- */

	/** Identifiers of the pass without building/signing the bundle (A580: large orders). */
	public function pass_ref( array $ticket ) {
		return array( 'provider' => 'apple', 'serial_number' => (string) $ticket['public_id'], 'provider_ref' => $this->pass_type_id() );
	}

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
		$end_utc = \PassCaptain\Occurrence::effective_end( $ticket ); // A606: same default duration as sales and listings.
		$voided = ! empty( $ticket['voided'] ) || '' === (string) ( $ticket['code'] ?? '' );
		$secondary = array( array( 'key' => 'date', 'label' => __( 'Datum', 'pass-captain' ), 'value' => $start, 'dateStyle' => 'PKDateStyleMedium', 'timeStyle' => 'PKDateStyleShort', 'ignoresTimeZone' => true ) );
		if ( ! empty( $ticket['doors_open_at'] ) ) {
			$secondary[] = array( 'key' => 'doors', 'label' => _x( 'Einlass', 'doors open time', 'pass-captain' ), 'value' => self::local_iso( $ticket['doors_open_at'], $ticket['timezone'] ), 'dateStyle' => 'PKDateStyleNone', 'timeStyle' => 'PKDateStyleShort', 'ignoresTimeZone' => true, 'changeMessage' => __( 'Neuer Einlass: %@', 'pass-captain' ) );
		}
		if ( '' !== (string) $ticket['location'] ) { $secondary[] = array( 'key' => 'location', 'label' => __( 'Ort', 'pass-captain' ), 'value' => (string) $ticket['location'], 'changeMessage' => __( 'Neuer Ort: %@', 'pass-captain' ) ); }
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
			$json['barcodes'] = array( array( 'format' => 'PKBarcodeFormatQR', 'message' => \PassCaptain\Security\SignedQr::qr_value( (string) $ticket['code'] ), 'messageEncoding' => 'iso-8859-1', 'altText' => substr( (string) $ticket['public_id'], 0, 8 ) ) );
		}
		/** Optional geo relevance: list of ['latitude'=>…, 'longitude'=>…, 'relevantText'=>…]. */
		$locations = apply_filters( 'wpcap_wallet_apple_locations', array(), $ticket );
		if ( is_array( $locations ) && $locations ) { $json['locations'] = array_values( array_slice( $locations, 0, 10 ) ); }
		return apply_filters( 'wpcap_wallet_apple_pass_json', $json, $ticket );
	}

	/* ---- bundle ---- */

	/**
	 * icon.png, icon@2x.png (operator files from settings or generated) and logo.png, logo@2x.png
	 * (only an operator file). A628: no generated logo – Wallet already shows the organisation name
	 * as logoText (UTF-8, umlauts intact) next to the logo; a generated text logo showed the name
	 * twice and could only draw ASCII.
	 */
	public function images() {
		$bg = Settings::get( 'wallet_background_color' ) ?: '#1d2327';
		$fg = Settings::get( 'wallet_foreground_color' ) ?: '#ffffff';
		// A620: built once per settings hash (colours, image files and their mtimes), kept in a transient.
		$sources = array( $bg, $fg, function_exists( 'imagecreatetruecolor' ) );
		foreach ( array( 'wallet_apple_icon_path', 'wallet_apple_logo_path' ) as $key ) {
			$path = trim( (string) Settings::get( $key ) );
			$retina = preg_replace( '/\.png$/i', '@2x.png', $path );
			$sources[] = array( $path, '' !== $path && is_file( $path ) ? filemtime( $path ) : 0, '' !== $retina && is_file( $retina ) ? filemtime( $retina ) : 0 );
		}
		$hash = hash( 'sha256', (string) wp_json_encode( $sources ) );
		if ( ! isset( self::$images[ $hash ] ) ) {
			$cached = get_transient( self::IMAGES_TRANSIENT );
			if ( is_array( $cached ) && ( $cached['hash'] ?? '' ) === $hash && is_array( $cached['images'] ?? null ) ) {
				self::$images[ $hash ] = array_map( 'base64_decode', $cached['images'] );
			} else {
				self::$images[ $hash ] = $this->build_images( $bg, $fg );
				set_transient( self::IMAGES_TRANSIENT, array( 'hash' => $hash, 'images' => array_map( 'base64_encode', self::$images[ $hash ] ) ), DAY_IN_SECONDS ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary PNGs in an option.
			}
		}
		return apply_filters( 'wpcap_wallet_apple_images', self::$images[ $hash ] );
	}

	/** Transient with the built images of the current settings hash (A620). */
	const IMAGES_TRANSIENT = 'wpcap_wallet_apple_images';

	/** @var array<string,array<string,string>> Images per settings hash (this request). */
	private static $images = array();

	/** @var array<string,string> Signed bundles per content hash (this request, A620). */
	private static $bundles = array();

	private function build_images( $bg, $fg ) {
		$images = array(
			'icon.png' => Archive::icon_png( 29, $bg, $fg ),
			'icon@2x.png' => Archive::icon_png( 58, $bg, $fg ),
		);
		foreach ( array( 'icon' => 'wallet_apple_icon_path', 'logo' => 'wallet_apple_logo_path' ) as $name => $key ) {
			$path = trim( (string) Settings::get( $key ) );
			if ( '' !== $path && is_readable( $path ) && "\x89PNG" === substr( (string) file_get_contents( $path, false, null, 0, 4 ), 0, 4 ) ) {
				$images[ $name . '.png' ] = (string) file_get_contents( $path );
				$retina = preg_replace( '/\.png$/i', '@2x.png', $path );
				$images[ $name . '@2x.png' ] = is_readable( $retina ) ? (string) file_get_contents( $retina ) : $images[ $name . '.png' ];
			}
		}
		return $images;
	}

	/**
	 * Builds the signed .pkpass bytes. A620: the signed bundle is reused for the same content (pass.json,
	 * images, signing certificate) – within the request and, with a persistent object cache, across
	 * requests (encrypted like the stored codes, 1 hour). A new update tag, code or design changes the
	 * content and therefore the key.
	 */
	public function bundle( array $pass_json, $credentials = null ) {
		$credentials = $credentials ?: Credentials::apple();
		if ( is_wp_error( $credentials ) ) { throw new \RuntimeException( $credentials->get_error_code() ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal error code, never printed.
		$files = array( 'pass.json' => (string) json_encode( $pass_json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) + $this->images();
		$manifest = array();
		foreach ( $files as $name => $bytes ) { $manifest[ $name ] = sha1( $bytes ); }
		$files['manifest.json'] = (string) json_encode( $manifest, JSON_UNESCAPED_SLASHES );
		$key = hash( 'sha256', $files['manifest.json'] . '|' . hash( 'sha256', $credentials['cert'] . $credentials['wwdr'] ) );
		if ( isset( self::$bundles[ $key ] ) ) { return self::$bundles[ $key ]; }
		$shared = self::shared_cache();
		if ( $shared ) {
			$box = wp_cache_get( $key, self::CACHE_GROUP );
			$open = is_string( $box ) ? Store::decrypt( $box ) : null;
			$bytes = is_array( $open ) ? base64_decode( (string) ( $open['b'] ?? '' ), true ) : false;
			if ( is_string( $bytes ) && '' !== $bytes ) { return self::remember( $key, $bytes ); }
		}
		$files['signature'] = self::sign( $files['manifest.json'], $credentials );
		$bundle = Archive::zip( $files );
		if ( $shared ) { wp_cache_set( $key, Store::encrypt( array( 'b' => base64_encode( $bundle ) ) ), self::CACHE_GROUP, HOUR_IN_SECONDS ); } // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary bundle in the cache.
		return self::remember( $key, $bundle );
	}

	/** Object cache group of the signed bundles (A620). */
	const CACHE_GROUP = 'wpcap_wallet';

	/** Whether a persistent object cache keeps bundles across requests (never the options table). */
	private static function shared_cache() {
		return function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() && function_exists( 'wp_cache_get' );
	}

	/** Keeps at most 50 bundles of this request. */
	private static function remember( $key, $bundle ) {
		if ( count( self::$bundles ) >= 50 ) { self::$bundles = array_slice( self::$bundles, -25, null, true ); }
		self::$bundles[ $key ] = $bundle;
		return $bundle;
	}

	/** Detached PKCS#7 signature (DER) of the manifest incl. the WWDR intermediate. */
	public static function sign( $manifest, array $credentials ) {
		// openssl_pkcs7_sign() only works with file paths: private directory (0700) and files (0600).
		$dir = \PassCaptain\TempFiles::private_dir();
		if ( ! $dir ) { throw new \RuntimeException( 'wallet_sign_failed' ); }
		$in = $dir . '/manifest';
		$out = $dir . '/signature';
		$wwdr = $dir . '/wwdr.pem';
		$smime = '';
		try {
			if ( \PassCaptain\TempFiles::put( $in, $manifest ) && \PassCaptain\TempFiles::put( $wwdr, $credentials['wwdr'] ) && \PassCaptain\TempFiles::put( $out, '' ) ) {
				$ok = openssl_pkcs7_sign( $in, $out, $credentials['cert'], array( $credentials['key'], (string) $credentials['password'] ), array(), PKCS7_BINARY | PKCS7_DETACHED, $wwdr );
				$smime = $ok ? \PassCaptain\TempFiles::get( $out ) : '';
			}
		} finally {
			foreach ( array( $in, $out, $wwdr ) as $file ) { \PassCaptain\TempFiles::remove( $file ); }
			\PassCaptain\TempFiles::remove_dir( $dir );
		}
		if ( ! preg_match( '/filename="?smime\.p7s"?\s*\r?\n\r?\n(.+?)\r?\n\r?\n?-{2,}/s', $smime, $m ) ) {
			throw new \RuntimeException( 'wallet_sign_failed' );
		}
		$der = base64_decode( preg_replace( '/\s+/', '', $m[1] ), true );
		if ( false === $der || '' === $der ) { throw new \RuntimeException( 'wallet_sign_failed' ); }
		return $der;
	}

	/* ---- push ---- */

	/** @var ApnsClient|null Client of the current run (one HTTP/2 connection for all pushes). */
	private static $apns = null;
	private static $in_run = false;

	public static function apns_client() {
		if ( self::$in_run && null !== self::$apns ) { return self::$apns; }
		$client = apply_filters( 'wpcap_wallet_apns_client', null );
		$client = $client instanceof ApnsClient ? $client : new ApnsClient();
		if ( self::$in_run ) { self::$apns = $client; }
		return $client;
	}

	/** Starts a dispatch run (Service::process()): all pushes of the run share one client/connection. */
	public static function begin_apns_run() {
		self::$in_run = true;
	}

	/** Ends the run: closes the APNs connection. */
	public static function release_apns_client() {
		if ( null !== self::$apns ) { self::$apns->close(); }
		self::$apns = null;
		self::$in_run = false;
	}

	/**
	 * Sends an update push to every device registered for the serial. Tokens answered with 410
	 * (device unregistered) are removed. Returns false on transport/server errors (retry later).
	 * 403 means APNs rejects the certificate or topic: a configuration error that no retry fixes, so it
	 * is recorded for the system status (Service::status_issues()) and not retried.
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
			if ( 403 === $status ) {
				if ( (int) get_option( Service::APNS_ERROR_OPTION, 0 ) < \PassCaptain\Clock::real() - HOUR_IN_SECONDS ) {
					update_option( Service::APNS_ERROR_OPTION, \PassCaptain\Clock::real(), false );
					Service::log( 'apns_forbidden', 'APNs 403: certificate or pass type id rejected' );
				}
				continue;
			}
			if ( 429 === $status ) {
				// A422: APNs rate limit – no further pushes in this run, the sync retries later.
				Service::rate_limited( 'apple', 60 );
				return false;
			}
			if ( 200 !== $status && 400 !== $status ) { $ok = false; }
		}
		return $ok;
	}
}

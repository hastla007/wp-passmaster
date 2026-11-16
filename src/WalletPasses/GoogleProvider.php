<?php
/**
 * Google Wallet: one EventTicketClass per occurrence, one EventTicketObject per ticket (and code
 * generation). Customers add passes through a "Save to Google Wallet" link (RS256 JWT signed with
 * the service account key); later changes go through the Wallet REST API (OAuth2 JWT bearer flow).
 */
namespace PassCaptain\WalletPasses;

use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class GoogleProvider implements WalletProvider {
	const API = 'https://walletobjects.googleapis.com/walletobjects/v1/';
	/** Hosts a service account token_uri may point to (OutboundHttp policy). */
	const TOKEN_HOSTS = array( 'oauth2.googleapis.com', 'accounts.google.com', 'www.googleapis.com' );
	const SCOPE = 'https://www.googleapis.com/auth/wallet_object.issuer';
	const SAVE_URL = 'https://pay.google.com/gp/v/save/';

	public function id() { return 'google'; }

	public function issuer_id() { return preg_replace( '/[^0-9]/', '', (string) Settings::get( 'wallet_google_issuer_id' ) ); }

	public function is_configured() {
		return (bool) Settings::get( 'wallet_google_enabled' ) && '' !== $this->issuer_id() && is_array( Credentials::google() );
	}

	/** Option holding the site part of the Google class/object ids (A475). */
	const SITE_KEY_OPTION = 'wpcap_wallet_google_site_key';

	/**
	 * Site part of every Google id (8 hex characters). Fixed at the first use and stored, so a domain
	 * change (home_url()) no longer gives new class/object ids and cuts saved passes off from updates
	 * (A475). Installs from before take the key of their newest issued pass (wpcap_wallet_passes),
	 * otherwise the hash of the current home URL as before.
	 */
	public static function site_key() {
		$stored = (string) get_option( self::SITE_KEY_OPTION, '' );
		if ( 1 === preg_match( '/^[a-f0-9]{8}$/', $stored ) ) { return $stored; }
		$current = substr( hash( 'sha256', home_url( '/' ) ), 0, 8 );
		$issued = self::issued_site_key();
		$key = '' !== $issued ? $issued : $current;
		update_option( self::SITE_KEY_OPTION, $key, false );
		if ( $key !== $current ) { Service::log( 'google_site_key_kept', 'site key of issued passes kept after an address change: ' . $key ); }
		return $key;
	}

	/** Site key of the newest Google pass issued before the key was stored ('' without one). */
	private static function issued_site_key() {
		global $wpdb;
		if ( ! \PassCaptain\Privacy::has_table( 'wpcap_wallet_passes' ) ) { return ''; }
		$ref = (string) $wpdb->get_var( "SELECT provider_ref FROM {$wpdb->prefix}wpcap_wallet_passes WHERE provider='google' AND provider_ref<>'' ORDER BY wallet_pass_id DESC LIMIT 1" );
		return 1 === preg_match( '/\.wpcap_([a-f0-9]{8})_o\d+$/', $ref, $m ) ? $m[1] : '';
	}

	public function class_id( $occurrence_id ) { return $this->issuer_id() . '.wpcap_' . self::site_key() . '_o' . absint( $occurrence_id ); }

	/**
	 * Class id of a ticket: the one stored with its pass (provider_ref, Service::ticket() and
	 * class_ticket() set google_class_id) wins over the computed one, so passes issued under an
	 * older id keep receiving updates (A475).
	 */
	private function ticket_class_id( array $ticket ) {
		$stored = (string) ( $ticket['google_class_id'] ?? '' );
		return '' !== $stored ? $stored : $this->class_id( $ticket['occurrence_id'] );
	}

	public function object_id( array $ticket ) {
		return $this->issuer_id() . '.wpcap_' . self::site_key() . '_t' . preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $ticket['public_id'] ) ) . '_g' . max( 1, (int) ( $ticket['generation'] ?? 1 ) );
	}

	/**
	 * Localized string in the buyer's language (A474): the order locale of the ticket, else the
	 * current locale.
	 */
	private static function text( $value, array $ticket = array() ) {
		$locale = (string) ( $ticket['locale'] ?? '' );
		if ( '' === $locale && function_exists( 'determine_locale' ) ) { $locale = (string) determine_locale(); }
		$language = strtolower( (string) strtok( '' !== $locale ? $locale : 'de', '_' ) );
		return array( 'defaultValue' => array( 'language' => '' !== $language ? $language : 'de', 'value' => (string) $value ) );
	}

	private static function iso( $utc, $timezone ) {
		try { $tz = new \DateTimeZone( $timezone ?: 'UTC' ); } catch ( \Exception $e ) { $tz = new \DateTimeZone( 'UTC' ); }
		return ( new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) ) )->setTimezone( $tz )->format( 'Y-m-d\TH:i:sP' );
	}

	public function class_payload( array $ticket ) {
		$class = array(
			'id' => $this->ticket_class_id( $ticket ),
			'issuerName' => AppleProvider::organization(),
			'reviewStatus' => 'UNDER_REVIEW',
			'eventName' => self::text( $ticket['event_name'], $ticket ),
			'eventId' => 'wpcap-' . self::site_key() . '-' . absint( $ticket['occurrence_id'] ),
			'dateTime' => array( 'start' => self::iso( $ticket['starts_at'], $ticket['timezone'] ) ),
			'hexBackgroundColor' => (string) ( Settings::get( 'wallet_background_color' ) ?: '#1d2327' ),
		);
		if ( $ticket['ends_at'] ) { $class['dateTime']['end'] = self::iso( $ticket['ends_at'], $ticket['timezone'] ); }
		if ( ! empty( $ticket['doors_open_at'] ) ) { $class['dateTime']['doorsOpen'] = self::iso( $ticket['doors_open_at'], $ticket['timezone'] ); }
		if ( 'cancelled' === ( $ticket['occurrence_status'] ?? '' ) || 'cancelled' === ( $ticket['event_status'] ?? '' ) ) {
			$class['messages'] = array( array( 'id' => 'wpcap_cancelled', 'header' => __( 'Abgesagt', 'pass-captain' ), 'body' => __( 'Die Veranstaltung wurde abgesagt.', 'pass-captain' ) ) );
		}
		if ( '' !== (string) $ticket['location'] ) { $class['venue'] = array( 'name' => self::text( $ticket['location'], $ticket ), 'address' => self::text( $ticket['location'], $ticket ) ); }
		return apply_filters( 'wpcap_wallet_google_class', $class, $ticket );
	}

	public function object_payload( array $ticket ) {
		$active = empty( $ticket['voided'] ) && '' !== (string) ( $ticket['code'] ?? '' );
		$object = array(
			'id' => $this->object_id( $ticket ),
			'classId' => $this->ticket_class_id( $ticket ),
			'state' => $active ? 'ACTIVE' : 'INACTIVE',
			'ticketNumber' => (string) $ticket['public_id'],
			'ticketType' => self::text( $ticket['ticket_name'], $ticket ),
		);
		// A584: code not decryptable but ticket valid – a PATCH without state/barcode keeps both as saved.
		if ( Service::code_unavailable( $ticket ) ) { unset( $object['state'] ); }
		if ( $active ) { $object['barcode'] = array( 'type' => 'QR_CODE', 'value' => (string) $ticket['code'], 'alternateText' => substr( (string) $ticket['public_id'], 0, 8 ) ); }
		// A566: an invalid ticket carries no personal data (like Apple's voided older generations).
		$personal = $active || Service::code_unavailable( $ticket );
		if ( $personal && '' !== (string) ( $ticket['seat'] ?? '' ) ) { $object['seatInfo'] = array( 'seat' => self::text( $ticket['seat'], $ticket ) ); }
		if ( $personal && '' !== (string) ( $ticket['attendee_name'] ?? '' ) ) { $object['ticketHolderName'] = (string) $ticket['attendee_name']; }
		return apply_filters( 'wpcap_wallet_google_object', $object, $ticket );
	}

	/** Signed "Save to Google Wallet" URL for a payload (eventTicketClasses / eventTicketObjects). */
	private function signed_save_url( array $payload ) {
		$account = Credentials::google();
		if ( is_wp_error( $account ) ) { throw new \RuntimeException( $account->get_error_code() ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal error code, never printed.
		$claims = array(
			'iss' => $account['client_email'],
			'aud' => 'google',
			'typ' => 'savetowallet',
			'iat' => time(),
			'origins' => array( home_url( '/' ) ),
			'payload' => $payload,
		);
		return self::SAVE_URL . Jwt::sign( $claims, $account['private_key'] );
	}

	/**
	 * "Save to Google Wallet" URL with the class and object embedded in the JWT ("fat" JWT). Only a
	 * fallback for one ticket when the Wallet API is unreachable: such URLs exceed 1,800 characters.
	 */
	public function save_url( array $ticket ) {
		return $this->signed_save_url( array( 'eventTicketClasses' => array( $this->class_payload( $ticket ) ), 'eventTicketObjects' => array( $this->object_payload( $ticket ) ) ) );
	}

	/**
	 * A583: short save URL for several tickets. Inserts the classes and objects through the Wallet
	 * API first (on the customer's click), so the JWT only carries the object IDs. Returns '' when the
	 * API is not reachable (caller may fall back to save_url() for a single ticket).
	 *
	 * @param array[] $tickets Service::ticket() rows with code.
	 */
	public function save_url_for( array $tickets ) {
		$classes = array();
		$objects = array();
		foreach ( $tickets as $ticket ) {
			$class_id = $this->class_id( $ticket['occurrence_id'] );
			if ( ! isset( $classes[ $class_id ] ) ) {
				$res = $this->api( 'POST', 'eventTicketClass', $this->class_payload( $ticket ) );
				if ( ! in_array( $res['code'], array( 200, 409 ), true ) ) { return ''; }
				if ( 409 === $res['code'] && ! $this->update_class( $ticket ) ) { return ''; }
				$classes[ $class_id ] = true;
			}
			$object = $this->object_payload( $ticket );
			$res = $this->api( 'POST', 'eventTicketObject', $object );
			if ( 409 === $res['code'] ) {
				// Already inserted (an earlier click or a sync): bring it to the current state.
				$res = $this->api( 'PATCH', 'eventTicketObject/' . rawurlencode( $object['id'] ), $object );
			}
			if ( 200 !== $res['code'] ) { return ''; }
			// A566: from now on the object exists at Google and is kept up to date.
			Store::mark_remote( 'google', (string) $object['id'] );
			$objects[] = array( 'id' => $object['id'] ); // Inserted already: the ID is enough (no class, no data).
		}
		return $objects ? $this->signed_save_url( array( 'eventTicketObjects' => $objects ) ) : '';
	}

	/* ---- ProviderInterface ---- */

	/** Identifiers of the pass without building anything (A580: large orders). */
	public function pass_ref( array $ticket ) {
		return array( 'provider' => 'google', 'serial_number' => $this->object_id( $ticket ), 'provider_ref' => $this->class_id( $ticket['occurrence_id'] ) );
	}

	/** The save link is a short link of the plugin (Service::google_link()), signed at the customer's click. */
	public function create_pass( array $ticket ) {
		return $this->pass_ref( $ticket );
	}

	/**
	 * Brings an object the buyer saved to the current state (A566). Nothing is sent for an object
	 * that was never saved (the save link inserts the current state on the buyer's click), and an
	 * object missing at Google is never created by a sync. A valid ticket is PATCHed (a code that
	 * cannot be decrypted keeps state and barcode, A584); an invalid one is replaced (PUT) without
	 * barcode, holder name and seat, so Google keeps no personal data of it.
	 */
	public function update_pass( array $ticket, array $provider_pass ) {
		if ( ! Store::is_remote( $provider_pass ) ) { return true; } // A566: never saved by the buyer – nothing at Google.
		// The ids stored at issuance (A475): the object id and its class, whatever home_url() is now.
		if ( ! empty( $provider_pass['provider_ref'] ) ) { $ticket['google_class_id'] = (string) $provider_pass['provider_ref']; }
		$object = $this->object_payload( $ticket );
		if ( ! empty( $provider_pass['serial_number'] ) ) { $object['id'] = (string) $provider_pass['serial_number']; }
		$invalid = isset( $object['state'] ) && 'INACTIVE' === $object['state'];
		// A PATCH keeps fields it does not name: a removed holder name (retention, correction) is cleared explicitly.
		if ( ! $invalid && ! isset( $object['ticketHolderName'] ) ) { $object['ticketHolderName'] = ''; }
		$res = $this->api( $invalid ? 'PUT' : 'PATCH', 'eventTicketObject/' . rawurlencode( $object['id'] ), $object );
		if ( 404 === $res['code'] ) {
			Service::log( 'google_not_found', 'object ' . self::short_id( $object['id'] ) ); // A valid object is never re-created (A566).
			// A619: an invalid ticket is inserted as an empty INACTIVE object, so an older save link with
			// the full object (fallback JWT) cannot create an ACTIVE pass with the old code later.
			if ( $invalid ) { return $this->insert_inactive( (string) $object['id'], (string) $object['classId'] ); }
		}
		return in_array( $res['code'], array( 200, 404 ), true );
	}

	/**
	 * Older generation after a code rotation: replaced by an inactive object without data (A566). A619:
	 * missing at Google (the buyer opened but never finished an older save link) → inserted as INACTIVE,
	 * so that link can no longer create an ACTIVE pass with the old, invalid code.
	 */
	public function revoke_pass( array $provider_pass ) {
		if ( ! Store::is_remote( $provider_pass ) ) { return true; }
		$object = array( 'id' => (string) $provider_pass['serial_number'], 'state' => 'INACTIVE' );
		if ( '' !== (string) ( $provider_pass['provider_ref'] ?? '' ) ) { $object['classId'] = (string) $provider_pass['provider_ref']; }
		$res = $this->api( 'PUT', 'eventTicketObject/' . rawurlencode( $object['id'] ), $object );
		if ( 404 === $res['code'] ) {
			Service::log( 'google_not_found', 'revoke ' . self::short_id( (string) $provider_pass['serial_number'] ) );
			return $this->insert_inactive( $object['id'], (string) ( $object['classId'] ?? '' ) );
		}
		return 200 === $res['code'];
	}

	/**
	 * Inserts an INACTIVE object without barcode or personal data (A619). Google keeps an existing
	 * object when a save link carries the same id, so a later save shows the invalid pass. 409 = it
	 * exists already. Without a class (nobody saved a ticket of this date) nothing can be inserted –
	 * the class is created only by a save link, which then finds no such object: logged, not retried.
	 */
	private function insert_inactive( $object_id, $class_id ) {
		if ( '' === (string) $class_id ) { return true; }
		$res = $this->api( 'POST', 'eventTicketObject', array( 'id' => (string) $object_id, 'classId' => (string) $class_id, 'state' => 'INACTIVE' ) );
		if ( in_array( $res['code'], array( 200, 409 ), true ) ) { return true; }
		if ( in_array( $res['code'], array( 400, 404 ), true ) ) {
			Service::log( 'google_inactive_failed', 'object ' . self::short_id( $object_id ) . ' HTTP ' . $res['code'] );
			return true;
		}
		return false; // Transport error, 429, 5xx: the sync retries.
	}

	/**
	 * Updates date/venue of an occurrence class. 404 = nobody saved a pass yet, or the class lives
	 * under another id; it is logged (Wallet page) instead of passing silently (A475).
	 */
	public function update_class( array $ticket ) {
		$class = $this->class_payload( $ticket );
		$res = $this->api( 'PATCH', 'eventTicketClass/' . rawurlencode( $class['id'] ), $class );
		if ( 404 === $res['code'] ) { Service::log( 'google_not_found', 'class ' . self::short_id( $class['id'] ) ); }
		return in_array( $res['code'], array( 200, 404 ), true );
	}

	/** Id without the issuer number for the diagnostics log. */
	private static function short_id( $id ) {
		$id = (string) $id;
		$dot = strpos( $id, '.' );
		return false === $dot ? $id : substr( $id, $dot + 1 );
	}

	/* ---- REST ---- */

	/** Prefix of the cached access token (transient). */
	const TOKEN_TRANSIENT = 'wpcap_wallet_google_token';

	/**
	 * Cache key of the access token, bound to the service account (A423): client_email, token_uri and
	 * the private key. A new or changed service account never uses a token of the previous one.
	 *
	 * @param array|\WP_Error|null $account Credentials::google() (read when null).
	 */
	public static function token_cache_key( $account = null ) {
		$account = null === $account ? Credentials::google() : $account;
		if ( is_wp_error( $account ) || ! is_array( $account ) ) { return self::TOKEN_TRANSIENT; }
		return self::TOKEN_TRANSIENT . '_' . substr( hash( 'sha256', (string) $account['client_email'] . '|' . (string) $account['token_uri'] . '|' . hash( 'sha256', (string) $account['private_key'] ) ), 0, 20 );
	}

	/** Drops the cached access token of the current service account. */
	public static function forget_token() {
		delete_transient( self::token_cache_key() );
		delete_transient( self::TOKEN_TRANSIENT ); // Unbound key of versions before A423.
	}

	/** OAuth2 access token via the JWT bearer grant (cached per service account, A423). */
	public function access_token() {
		$account = Credentials::google();
		if ( is_wp_error( $account ) ) { return ''; }
		$cache_key = self::token_cache_key( $account );
		$cached = get_transient( $cache_key );
		if ( is_string( $cached ) && '' !== $cached ) { return $cached; }
		$now = time();
		$assertion = Jwt::sign( array( 'iss' => $account['client_email'], 'scope' => self::SCOPE, 'aud' => $account['token_uri'], 'iat' => $now, 'exp' => $now + 3600 ), $account['private_key'] );
		// token_uri comes from the uploaded service account JSON: only Google's token endpoints (A202).
		$res = \PassCaptain\OutboundHttp::request( $account['token_uri'], array( 'method' => 'POST', 'timeout' => 15, 'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ), 'body' => http_build_query( array( 'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion ) ) ), array( 'context' => 'wallet_google', 'hosts' => self::TOKEN_HOSTS, 'max_bytes' => 65536 ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) { return ''; }
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		$token = is_array( $data ) ? (string) ( $data['access_token'] ?? '' ) : '';
		if ( '' !== $token ) { set_transient( $cache_key, $token, max( 60, (int) ( $data['expires_in'] ?? 3600 ) - 120 ) ); }
		return $token;
	}

	/** @return array{code:int,body:mixed} code 0 on transport/auth errors. */
	public function api( $method, $path, array $body ) {
		$token = $this->access_token();
		if ( '' === $token ) { return array( 'code' => 0, 'body' => null ); }
		$res = \PassCaptain\OutboundHttp::request( self::API . $path, array( 'method' => $method, 'timeout' => 15, 'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( $body ) ), array( 'context' => 'wallet_google', 'hosts' => array( 'walletobjects.googleapis.com' ) ) );
		if ( is_wp_error( $res ) ) { return array( 'code' => 0, 'body' => null ); }
		$code = (int) wp_remote_retrieve_response_code( $res );
		// Rejected token (expired, revoked, or the account lost its issuer permission): fetched anew next time (A423).
		if ( 401 === $code || 403 === $code ) { self::forget_token(); }
		// A422: rate limit – the sync stops calling Google in this run and retries after Retry-After.
		if ( 429 === $code ) { Service::rate_limited( 'google', (int) wp_remote_retrieve_header( $res, 'retry-after' ) ); }
		return array( 'code' => $code, 'body' => json_decode( wp_remote_retrieve_body( $res ), true ) );
	}
}

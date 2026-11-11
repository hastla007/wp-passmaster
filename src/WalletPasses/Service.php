<?php
/**
 * Wallet orchestration: creates passes when tickets are mailed, keeps them in sync with ticket,
 * occurrence and code changes, and dispatches Apple pushes / Google object updates.
 */
namespace PassCaptain\WalletPasses;

use PassCaptain\Jobs;
use PassCaptain\Settings;
use PassCaptain\Wallet\Bridge;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Service {
	const JOB = 'wallet_sync';
	/** Tickets handled inline per request; larger sets go to the job queue. */
	const INLINE_LIMIT = 20;

	public static function register() {
		add_filter( 'wp_pass_captain_wallet_provider_v1', array( __CLASS__, 'default_provider' ), 5, 2 );
		add_filter( 'wpcap_ticket_mail', array( __CLASS__, 'on_ticket_mail' ), 20, 3 );
		add_action( 'wpcap_ticket_revoked', array( __CLASS__, 'on_ticket_changed' ), 20, 1 );
		add_action( 'wpcap_occurrence_rescheduled', array( __CLASS__, 'on_occurrence_changed' ), 20, 1 );
		add_action( 'wpcap_occurrence_cancelled', array( __CLASS__, 'on_occurrence_changed' ), 20, 1 );
		add_action( 'wpcap_order_cancelled', array( __CLASS__, 'on_order_changed' ), 20, 1 );
		add_action( 'wpcap_refund_completed', array( __CLASS__, 'on_refund_completed' ), 20, 2 );
		// Fired by core on resend, transfer, participant assignment and reprint (any reason).
		add_action( 'wpcap_ticket_code_rotated', array( __CLASS__, 'on_code_rotated' ), 10, 2 );
		// Wallet links for the new code in the resend/transfer/assignment mails.
		add_filter( 'wpcap_ticket_code_mail_section', array( __CLASS__, 'code_mail_section' ), 10, 2 );
		add_filter( 'wp_pass_captain_process_job_v1', array( __CLASS__, 'handle_job' ), 10, 3 );
		add_action( 'shutdown', array( __CLASS__, 'flush' ), 5 );
	}

	public static function default_provider( $provider, $key ) {
		if ( null !== $provider ) { return $provider; }
		if ( 'apple' === $key ) { return new AppleProvider(); }
		if ( 'google' === $key ) { return new GoogleProvider(); }
		return $provider;
	}

	/** Configured provider or null. */
	public static function provider( $key ) {
		$provider = Bridge::provider( $key );
		if ( ! $provider ) { return null; }
		return ( ! $provider instanceof WalletProvider || $provider->is_configured() ) ? $provider : null;
	}

	/* ---- ticket data ---- */

	/**
	 * Current pass-relevant data of a ticket incl. decrypted code (null if unavailable).
	 *
	 * @return array|null
	 */
	public static function ticket( $ticket_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT t.ticket_id,t.public_id,t.order_id,t.status,t.token_hash,t.ticket_type_id,tt.name AS ticket_name,o.occurrence_id,o.event_id,o.starts_at,o.ends_at,o.timezone,o.location,o.status AS occurrence_status,e.name AS event_name,e.status AS event_status,ta.attendee_name FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id LEFT JOIN {$p}wpcap_ticket_assignments ta ON ta.ticket_id=t.ticket_id WHERE t.ticket_id=%d",
			absint( $ticket_id )
		), ARRAY_A );
		if ( ! $row ) { return null; }
		$state = Store::state( $ticket_id );
		$secrets = Store::secrets( $state );
		$row['attendee_name'] = (string) ( $row['attendee_name'] ?? '' );
		/**
		 * Seat/area label printed on wallet passes (and available to other renderers).
		 *
		 * @param string $label     Default ''.
		 * @param int    $ticket_id Ticket ID.
		 * @param array  $ticket    Ticket context (event, occurrence, type).
		 */
		$row['seat'] = (string) apply_filters( 'wpcap_ticket_seat_label', '', (int) $row['ticket_id'], $row );
		$row['code_hash'] = $state ? (string) $state->code_hash : '';
		$row['generation'] = $state ? (int) $state->generation : 1;
		$row['code'] = $secrets['code'] ?? null;
		$row['auth_token'] = $secrets['auth'] ?? null;
		$row['voided'] = ! in_array( $row['status'], array( 'valid', 'checked_in' ), true ) || 'cancelled' === $row['occurrence_status'] || 'cancelled' === $row['event_status'] || ! hash_equals( (string) $row['token_hash'], $row['code_hash'] );
		unset( $row['token_hash'] );
		return $row;
	}

	public static function fingerprint( array $ticket ) {
		$keys = array( 'status', 'voided', 'starts_at', 'ends_at', 'timezone', 'location', 'event_name', 'ticket_name', 'seat', 'attendee_name', 'code_hash', 'generation', 'occurrence_status', 'event_status' );
		return hash( 'sha256', (string) wp_json_encode( array_intersect_key( $ticket, array_flip( $keys ) ) ) );
	}

	/* ---- issuance ---- */

	/**
	 * Stores the code and creates the provider passes of one ticket.
	 *
	 * @return array{apple?:array,google?:array}
	 */
	public static function issue( $ticket_id, $code ) {
		$apple = self::provider( 'apple' );
		$google = self::provider( 'google' );
		if ( ! $apple && ! $google ) { return array(); }
		Store::put_code( $ticket_id, $code );
		$ticket = self::ticket( $ticket_id );
		if ( ! $ticket ) { return array(); }
		$state = Store::state( $ticket_id );
		Store::touch( $ticket_id, self::fingerprint( $ticket ), $state->update_tag );
		$out = array();
		foreach ( array( 'apple' => $apple, 'google' => $google ) as $key => $provider ) {
			if ( ! $provider ) { continue; }
			try {
				$pass = $provider->create_pass( $ticket );
				Store::save_pass( $ticket_id, $key, (string) $pass['serial_number'], (string) ( $pass['provider_ref'] ?? '' ), $ticket['voided'] ? 'voided' : 'active' );
				if ( 'apple' === $key ) { $pass['download_url'] = self::download_url( $pass['serial_number'] ); }
				$out[ $key ] = $pass;
			} catch ( \Throwable $e ) {
				self::log( 'issue_failed', $key . ':' . $e->getMessage() );
			}
		}
		return $out;
	}

	/** Signed, non-expiring download link for the current generation of an Apple pass. */
	public static function download_url( $serial ) {
		$pass = Store::pass_by_serial( 'apple', $serial );
		$state = $pass ? Store::state( $pass->ticket_id ) : null;
		if ( ! $state ) { return ''; }
		return add_query_arg( 'sig', self::download_sig( $serial, $state ), rest_url( 'wp-pass-captain/v1/' . AppleProvider::REST_BASE . '/download/' . rawurlencode( $serial ) ) );
	}

	public static function download_sig( $serial, $state ) {
		return hash_hmac( 'sha256', 'dl|' . $serial . '|' . $state->auth_token_hash, Store::key() );
	}

	/** Mail text and attachments for created passes. */
	private static function mail_parts( array $passes, array $ticket_label, $allow_attachment ) {
		$text = '';
		$files = array();
		if ( isset( $passes['apple'] ) ) {
			$use_file = $allow_attachment && 'link' !== Settings::get( 'wallet_apple_delivery' ) && ! empty( $passes['apple']['bundle'] );
			if ( $use_file ) {
				$file = trailingslashit( get_temp_dir() ) . 'ticket-' . substr( $passes['apple']['serial_number'], 0, 12 ) . '.pkpass';
				if ( false !== file_put_contents( $file, $passes['apple']['bundle'] ) ) {
					$files[] = $file;
					/* translators: %s: file name */
					$text .= sprintf( __( 'Zu Apple Wallet hinzufügen: Anhang %s öffnen', 'wp-pass-captain' ), basename( $file ) ) . "\n";
				} else {
					$use_file = false;
				}
			}
			if ( ! $use_file && ! empty( $passes['apple']['download_url'] ) ) {
				$text .= __( 'Zu Apple Wallet hinzufügen:', 'wp-pass-captain' ) . ' ' . esc_url_raw( $passes['apple']['download_url'] ) . "\n";
			}
		}
		if ( ! empty( $passes['google']['save_url'] ) ) {
			$text .= __( 'In Google Wallet speichern:', 'wp-pass-captain' ) . ' ' . esc_url_raw( $passes['google']['save_url'] ) . "\n";
		}
		if ( '' !== $text ) { $text = implode( ' — ', array_filter( $ticket_label ) ) . "\n" . $text . "\n"; }
		return array( $text, $files );
	}

	/** Filter wpcap_ticket_mail: create passes while the plaintext codes are available. */
	public static function on_ticket_mail( $mail, $order = null, $issued = array() ) {
		if ( ! is_array( $mail ) || ! is_array( $issued ) || ! $issued ) { return $mail; }
		$text = '';
		foreach ( $issued as $entry ) {
			if ( empty( $entry['ticket_id'] ) || empty( $entry['token'] ) ) { continue; }
			try {
				$passes = self::issue( (int) $entry['ticket_id'], (string) $entry['token'] );
			} catch ( \Throwable $e ) {
				self::log( 'issue_failed', $e->getMessage() );
				continue;
			}
			list( $part, $files ) = self::mail_parts( $passes, array( $entry['event'] ?? '', $entry['ticket'] ?? '' ), true );
			$text .= $part;
			$mail['attachments'] = array_merge( (array) ( $mail['attachments'] ?? array() ), $files );
		}
		if ( '' !== $text ) { $mail['body'] = (string) $mail['body'] . "\n" . __( 'Wallet', 'wp-pass-captain' ) . "\n" . $text; }
		return $mail;
	}

	/* ---- code rotation ---- */

	/**
	 * A ticket got a new code (resend, transfer, participant assignment). Starts a new pass
	 * generation: devices/accounts holding the old pass only receive a voided version.
	 *
	 * @return array Passes of the new generation (empty when nothing changed).
	 */
	public static function on_code_rotated( $ticket_id, $code ) {
		$state = Store::state( $ticket_id );
		if ( ! $state || ! is_string( $code ) || hash_equals( (string) $state->code_hash, hash( 'sha256', $code ) ) ) { return array(); }
		global $wpdb;
		$current = (string) $wpdb->get_var( $wpdb->prepare( "SELECT token_hash FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_id=%d", absint( $ticket_id ) ) );
		if ( ! hash_equals( $current, hash( 'sha256', $code ) ) ) { return array(); }
		foreach ( Store::passes( $ticket_id, 'google' ) as $old ) { Store::set_pass_status( $old->wallet_pass_id, 'retired' ); }
		$passes = self::issue( $ticket_id, $code );
		self::queue( array( (int) $ticket_id ) );
		self::$rotated[ (int) $ticket_id ] = $passes;
		return $passes;
	}

	/** Passes created by a code rotation in this request, keyed by ticket id. */
	private static $rotated = array();

	/** Filter wpcap_ticket_code_mail_section: wallet links for the ticket's new code. */
	public static function code_mail_section( $text, $ticket_id ) {
		$passes = self::$rotated[ (int) $ticket_id ] ?? array();
		if ( ! $passes ) { return $text; }
		$ticket = self::ticket( (int) $ticket_id );
		list( $part ) = self::mail_parts( $passes, array( $ticket['event_name'] ?? '', $ticket['ticket_name'] ?? '' ), false );
		return '' === $part ? $text : $text . "\n" . __( 'Wallet', 'wp-pass-captain' ) . "\n" . $part;
	}

	/* ---- change propagation ---- */

	/** Recomputes the fingerprint; bumps the update tag when the pass content changed. */
	public static function sync_ticket( $ticket_id ) {
		$state = Store::state( $ticket_id );
		$ticket = $state ? self::ticket( $ticket_id ) : null;
		if ( ! $ticket ) { return false; }
		$fingerprint = self::fingerprint( $ticket );
		if ( hash_equals( (string) $state->fingerprint, $fingerprint ) ) { return false; }
		Store::touch( $ticket_id, $fingerprint, $state->update_tag );
		foreach ( Store::passes( $ticket_id ) as $pass ) {
			if ( 'retired' === $pass->status ) { continue; }
			Store::set_pass_status( $pass->wallet_pass_id, $ticket['voided'] ? 'voided' : 'active' );
		}
		return true;
	}

	/** wpcap_ticket_revoked fires once per ticket (e.g. for a whole cancelled occurrence): only collect here. */
	public static function on_ticket_changed( $ticket_id ) {
		if ( self::sync_ticket( (int) $ticket_id ) ) { self::queue( array( (int) $ticket_id ) ); }
	}

	private static function sync_many( array $ticket_ids ) {
		$changed = array();
		foreach ( $ticket_ids as $id ) {
			if ( self::sync_ticket( (int) $id ) ) { $changed[] = (int) $id; }
		}
		self::queue( $changed );
	}

	public static function on_occurrence_changed( $occurrence_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT s.ticket_id FROM {$p}wpcap_wallet_state s INNER JOIN {$p}wpcap_tickets t ON t.ticket_id=s.ticket_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=%d", absint( $occurrence_id ) ) );
		self::sync_many( $ids );
		// Tickets may already have been synced through wpcap_ticket_revoked; the Google class still needs the new date/status.
		if ( $ids ) { self::$pending_occurrences[ (int) $occurrence_id ] = true; }
	}

	public static function on_order_changed( $order_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		self::sync_many( $wpdb->get_col( $wpdb->prepare( "SELECT s.ticket_id FROM {$p}wpcap_wallet_state s INNER JOIN {$p}wpcap_tickets t ON t.ticket_id=s.ticket_id WHERE t.order_id=%d", absint( $order_id ) ) ) );
	}

	public static function on_refund_completed( $refund_id, $order_id = 0 ) { self::on_order_changed( $order_id ); }

	/** Tickets / occurrences changed in this request; dispatched once on shutdown. */
	private static $pending_tickets = array();
	private static $pending_occurrences = array();

	/** Collects changed tickets (and occurrences whose Google class must be updated) for flush(). */
	public static function queue( array $ticket_ids, $occurrence_id = 0 ) {
		foreach ( $ticket_ids as $id ) { self::$pending_tickets[ (int) $id ] = true; }
		if ( $occurrence_id ) { self::$pending_occurrences[ (int) $occurrence_id ] = true; }
	}

	/** Kept for callers of the first version: queue + immediate flush. */
	public static function dispatch( array $ticket_ids, $occurrence_id = 0 ) {
		self::queue( $ticket_ids, $occurrence_id );
		self::flush();
	}

	/**
	 * Dispatches everything collected in this request (runs on shutdown). Work is always written to
	 * the job queue first, so a timeout or fatal during the inline attempt cannot lose an update;
	 * the first INLINE_LIMIT tickets are processed right away and their job is completed on success.
	 */
	public static function flush() {
		$ticket_ids = array_keys( self::$pending_tickets );
		$occurrences = array_keys( self::$pending_occurrences );
		self::$pending_tickets = array();
		self::$pending_occurrences = array();
		if ( ! $ticket_ids && ! $occurrences ) { return; }
		$chunks = array_chunk( $ticket_ids, 200 ) ?: array( array() );
		foreach ( $chunks as $i => $chunk ) {
			$payload = array( 'ticket_ids' => $chunk, 'occurrence_ids' => 0 === $i ? $occurrences : array() );
			$key = 'wallet-sync-' . md5( wp_json_encode( $payload ) . microtime( true ) . $i . random_int( 0, PHP_INT_MAX ) );
			$queued = true === Jobs::enqueue( $key, self::JOB, $payload );
			if ( ! $queued ) { self::log( 'queue_failed', 'wallet_sync' ); }
			if ( 0 === $i && count( $chunk ) <= self::INLINE_LIMIT && self::process( $chunk, $payload['occurrence_ids'] ) && $queued ) {
				self::complete_job( $key );
			}
		}
	}

	private static function complete_job( $key ) {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'wpcap_jobs', array( 'status' => 'complete', 'updated_at' => current_time( 'mysql', true ) ), array( 'idempotency_key' => hash( 'sha256', $key ), 'status' => 'queued' ) );
	}

	/** Filter wp_pass_captain_process_job_v1. */
	public static function handle_job( $handled, $type, $payload = array() ) {
		if ( self::JOB !== $type ) { return $handled; }
		$occurrences = (array) ( $payload['occurrence_ids'] ?? array() );
		if ( ! empty( $payload['occurrence_id'] ) ) { $occurrences[] = (int) $payload['occurrence_id']; }
		return self::process( (array) ( $payload['ticket_ids'] ?? array() ), $occurrences );
	}

	/** Any ticket of an occurrence that has a Google pass (class data source), or null. */
	private static function class_ticket( $occurrence_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT w.ticket_id FROM {$p}wpcap_wallet_passes w INNER JOIN {$p}wpcap_tickets t ON t.ticket_id=w.ticket_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id WHERE w.provider='google' AND tt.occurrence_id=%d LIMIT 1", absint( $occurrence_id ) ) );
		return $id ? self::ticket( (int) $id ) : null;
	}

	/** Sends Apple pushes and Google updates. Idempotent; false = retry later. */
	public static function process( array $ticket_ids, $occurrence_ids = array() ) {
		$apple = self::provider( 'apple' );
		$google = self::provider( 'google' );
		$ok = true;
		if ( $google && method_exists( $google, 'update_class' ) ) {
			foreach ( array_unique( array_map( 'intval', (array) $occurrence_ids ) ) as $occurrence_id ) {
				$ticket = $occurrence_id ? self::class_ticket( $occurrence_id ) : null;
				try {
					if ( $ticket ) { $ok = $google->update_class( $ticket ) && $ok; }
				} catch ( \Throwable $e ) {
					self::log( 'push_failed', 'google_class:' . $e->getMessage() );
					$ok = false;
				}
			}
		}
		foreach ( $ticket_ids as $ticket_id ) {
			$ticket = null;
			foreach ( Store::passes( $ticket_id ) as $pass ) {
				$row = (array) $pass;
				try {
					if ( 'apple' === $pass->provider && $apple ) {
						$ok = ( method_exists( $apple, 'notify' ) ? $apple->notify( $pass->serial_number ) : $apple->revoke_pass( $row ) ) && $ok;
					} elseif ( 'google' === $pass->provider && $google ) {
						$ticket = $ticket ?: self::ticket( $ticket_id );
						$ok = ( 'retired' === $pass->status ? $google->revoke_pass( $row ) : ( $ticket && $google->update_pass( $ticket, $row ) ) ) && $ok;
					}
				} catch ( \Throwable $e ) {
					self::log( 'push_failed', $pass->provider . ':' . $e->getMessage() );
					$ok = false;
				}
			}
		}
		return $ok;
	}

	/* ---- Apple bundle for the web service ---- */

	/**
	 * Builds the .pkpass for a serial. $presented_token: authentication token sent by a device
	 * (null for the signed download link = current generation). Returns null when unauthorised.
	 *
	 * @return array{bundle:string,tag:int}|null
	 */
	public static function apple_bundle( $serial, $presented_token = null ) {
		$apple = self::provider( 'apple' );
		$pass = Store::pass_by_serial( 'apple', $serial );
		if ( ! $apple || ! $pass ) { return null; }
		$state = Store::state( $pass->ticket_id );
		$ticket = $state ? self::ticket( $pass->ticket_id ) : null;
		if ( ! $ticket ) { return null; }
		if ( null !== $presented_token ) {
			$hash = hash( 'sha256', (string) $presented_token );
			if ( ! hash_equals( (string) $state->auth_token_hash, $hash ) ) {
				if ( ! Store::registration_by_auth( $serial, $hash ) ) { return null; }
				// Device holds an older generation (code was rotated): serve it voided.
				// It must not learn anything about the new holder either.
				$ticket['voided'] = true;
				$ticket['code'] = null;
				$ticket['auth_token'] = (string) $presented_token;
				$ticket['attendee_name'] = '';
				$ticket['seat'] = '';
			}
		}
		return array( 'bundle' => $apple->update_pass( $ticket, (array) $pass )['bundle'], 'tag' => (int) $state->update_tag );
	}

	const LOG_OPTION = 'wpcap_wallet_log';
	/** Separate, smaller buffer for the unauthenticated device log endpoint so it cannot flush server diagnostics. */
	const DEVICE_LOG_OPTION = 'wpcap_wallet_device_log';

	/** Small ring buffer of non-personal diagnostics shown on the Wallet admin page. */
	public static function log( $code, $message, $option = self::LOG_OPTION, $keep = 30 ) {
		$log = get_option( $option, array() );
		$log = is_array( $log ) ? $log : array();
		$log[] = array( 'time' => time(), 'code' => sanitize_key( $code ), 'message' => substr( sanitize_text_field( (string) $message ), 0, 300 ) );
		update_option( $option, array_slice( $log, -$keep ), false );
	}
}

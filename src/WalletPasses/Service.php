<?php
/**
 * Wallet orchestration: creates passes when tickets are mailed, keeps them in sync with ticket,
 * occurrence and code changes, and dispatches Apple pushes / Google object updates.
 */
namespace PassCaptain\WalletPasses;

use PassCaptain\Jobs;
use PassCaptain\Jobs\TimeBudget;
use PassCaptain\Mail\Mailer;
use PassCaptain\Security\SignedQr;
use PassCaptain\Settings;
use PassCaptain\Wallet\Bridge;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Service {
	const JOB = 'wallet_sync';
	/** Deferred pass creation (multi-ticket mails, batch print): passes are created after the commit. */
	const ISSUE_JOB = 'wallet_issue';
	/** Option holding the time of the last APNs 403 (certificate/topic rejected; configuration error). */
	const APNS_ERROR_OPTION = 'wpcap_wallet_apns_forbidden';
	/** Code rotations whose passes are reissued through the job queue instead of inline. */
	const DEFERRED_REASONS = array( 'print', 'pos_print' );
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
		add_action( 'wpcap_ticket_code_rotated', array( __CLASS__, 'on_code_rotated' ), 10, 3 );
		// A revoked QR signing key: every active pass of an upcoming occurrence needs a new barcode.
		add_action( 'wp_pass_captain_qr_key_rotated_v1', array( __CLASS__, 'on_qr_key_rotated' ), 10, 1 );
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
			'SELECT t.ticket_id,t.public_id,t.order_id,t.status,t.token_hash,t.ticket_type_id,ord.locale,tt.name AS ticket_name,o.occurrence_id,o.event_id,o.starts_at,o.ends_at,o.timezone,o.location,o.status AS occurrence_status,e.name AS event_name,e.status AS event_status,ta.attendee_name FROM %i t INNER JOIN %i ord ON ord.order_id=t.order_id INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id INNER JOIN %i e ON e.event_id=o.event_id LEFT JOIN %i ta ON ta.ticket_id=t.ticket_id WHERE t.ticket_id=%d', $p . 'wpcap_tickets', $p . 'wpcap_orders', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $p . 'wpcap_events', $p . 'wpcap_ticket_assignments',
			absint( $ticket_id )
		), ARRAY_A );
		if ( ! $row ) { return null; }
		$state = Store::state( $ticket_id );
		$secrets = Store::secrets( $state );
		$row['attendee_name'] = (string) ( $row['attendee_name'] ?? '' );
		$row['locale'] = (string) ( $row['locale'] ?? '' );
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

	/**
	 * Hash of everything a pass shows. Includes the id of the active QR signing key while signed QR
	 * codes are on, so a key rotation/revocation marks every pass as changed (new barcode).
	 */
	public static function fingerprint( array $ticket ) {
		$keys = array( 'status', 'voided', 'starts_at', 'ends_at', 'timezone', 'location', 'event_name', 'ticket_name', 'seat', 'attendee_name', 'code_hash', 'generation', 'occurrence_status', 'event_status' );
		$data = array_intersect_key( $ticket, array_flip( $keys ) );
		$data['qr_key'] = SignedQr::enabled() ? SignedQr::active_key_id() : '';
		return hash( 'sha256', (string) wp_json_encode( $data ) );
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
				$pass = Mailer::with_locale( $ticket['locale'], static function () use ( $provider, $ticket ) { return $provider->create_pass( $ticket ); } );
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
				// Unguessable private directory per attachment (the pass is a valid ticket); removed at
				// shutdown at the latest, like InvoicePdf::attach_to_ticket_mail().
				$dir = \PassCaptain\TempFiles::private_dir();
				$file = $dir . '/ticket-' . preg_replace( '/[^A-Za-z0-9_-]/', '', substr( (string) $passes['apple']['serial_number'], 0, 12 ) ) . '.pkpass';
				if ( $dir ) {
					self::$temp_files[] = array( $file, $dir );
					if ( 1 === count( self::$temp_files ) ) { register_shutdown_function( array( __CLASS__, 'cleanup_temp_files' ) ); }
				}
				if ( $dir && \PassCaptain\TempFiles::put( $file, $passes['apple']['bundle'] ) ) {
					$files[] = $file;
					/* translators: %s: file name */
					$text .= sprintf( __( 'Zu Apple Wallet hinzufügen: Anhang %s öffnen', 'pass-captain' ), basename( $file ) ) . "\n";
				} else {
					$use_file = false;
				}
			}
			if ( ! $use_file && ! empty( $passes['apple']['download_url'] ) ) {
				$text .= __( 'Zu Apple Wallet hinzufügen:', 'pass-captain' ) . ' ' . esc_url_raw( $passes['apple']['download_url'] ) . "\n";
			}
		}
		if ( ! empty( $passes['google']['save_url'] ) ) {
			$text .= __( 'In Google Wallet speichern:', 'pass-captain' ) . ' ' . esc_url_raw( $passes['google']['save_url'] ) . "\n";
		}
		if ( '' !== $text ) { $text = implode( ' — ', array_filter( $ticket_label ) ) . "\n" . $text . "\n"; }
		return array( $text, $files );
	}

	/** @var array<int,array{0:string,1:string}> Temporary .pkpass attachments and their directories. */
	private static $temp_files = array();

	/** Removes temporary .pkpass attachments and their directories (idempotent). */
	public static function cleanup_temp_files() {
		foreach ( self::$temp_files as $entry ) {
			list( $file, $dir ) = $entry;
			\PassCaptain\TempFiles::remove_with_dir( $file, $dir );
		}
		self::$temp_files = array();
	}

	/**
	 * Filter wpcap_ticket_mail: stores the codes while they are available in plaintext.
	 *
	 * One ticket: the pass is created right away (.pkpass attachment or link, Google save link).
	 * Several tickets (audit R2 #5): no attachment per ticket and no long link per ticket; the codes are
	 * stored, the passes are created by the job queue after the commit (ISSUE_JOB) and the mail gets one
	 * link to a page listing the wallet buttons of all tickets (collection_url()).
	 */
	public static function on_ticket_mail( $mail, $order = null, $issued = array() ) {
		if ( ! is_array( $mail ) || ! is_array( $issued ) || ! $issued ) { return $mail; }
		$entries = array_values( array_filter( $issued, static function ( $entry ) { return ! empty( $entry['ticket_id'] ) && ! empty( $entry['token'] ); } ) );
		if ( ! $entries || ( ! self::provider( 'apple' ) && ! self::provider( 'google' ) ) ) { return $mail; }
		$locale = is_object( $order ) ? (string) ( $order->locale ?? '' ) : '';
		if ( 1 === count( $entries ) ) {
			$entry = $entries[0];
			try {
				$passes = self::issue( (int) $entry['ticket_id'], (string) $entry['token'] );
			} catch ( \Throwable $e ) {
				self::log( 'issue_failed', $e->getMessage() );
				return $mail;
			}
			list( $part, $files ) = Mailer::with_locale( $locale, static function () use ( $passes, $entry ) { return self::mail_parts( $passes, array( $entry['event'] ?? '', $entry['ticket'] ?? '' ), true ); } );
			$mail['attachments'] = array_merge( (array) ( $mail['attachments'] ?? array() ), $files );
			if ( '' !== $part ) { $mail['body'] = (string) $mail['body'] . "\n" . Mailer::with_locale( $locale, static function () { return __( 'Wallet', 'pass-captain' ); } ) . "\n" . $part; }
			return $mail;
		}
		$codes = array();
		foreach ( $entries as $entry ) { $codes[ (int) $entry['ticket_id'] ] = (string) $entry['token']; }
		$deferred = self::defer( $codes );
		$url = $deferred && is_object( $order ) ? self::collection_url( $order ) : '';
		if ( '' === $url ) { return $mail; }
		$text = Mailer::with_locale( $locale, static function () use ( $deferred, $url ) {
			/* translators: %d: number of tickets */
			return __( 'Wallet', 'pass-captain' ) . "\n" . sprintf( __( 'Apple Wallet / Google Wallet für alle %d Tickets:', 'pass-captain' ), count( $deferred ) ) . ' ' . esc_url_raw( $url ) . "\n";
		} );
		$mail['body'] = (string) $mail['body'] . "\n" . $text;
		return $mail;
	}

	/**
	 * Stores new codes and queues the creation of their passes (ISSUE_JOB) instead of signing them
	 * inline: used for multi-ticket mails and batch prints. Returns the ticket ids handled.
	 *
	 * @param array<int,string> $codes ticket_id => plaintext code.
	 * @return int[]
	 */
	public static function defer( array $codes ) {
		if ( ! self::provider( 'apple' ) && ! self::provider( 'google' ) ) { return array(); }
		$ids = array();
		foreach ( $codes as $ticket_id => $code ) {
			Store::put_code( (int) $ticket_id, (string) $code );
			$ticket = self::ticket( (int) $ticket_id );
			$state = $ticket ? Store::state( (int) $ticket_id ) : null;
			if ( ! $state ) { continue; }
			Store::touch( (int) $ticket_id, self::fingerprint( $ticket ), $state->update_tag );
			$ids[] = (int) $ticket_id;
		}
		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			if ( true !== Jobs::enqueue( 'wallet-issue-' . md5( wp_json_encode( $chunk ) . microtime( true ) . random_int( 0, PHP_INT_MAX ) ), self::ISSUE_JOB, array( 'ticket_ids' => $chunk ) ) ) {
				self::log( 'queue_failed', self::ISSUE_JOB );
			}
		}
		return $ids;
	}

	/**
	 * Job ISSUE_JOB: creates the passes of tickets whose codes were stored by defer() – Apple pass rows
	 * (the bundle itself is built on download) and Google objects through the REST API, so the save
	 * link only needs to carry the object id (skinny JWT). Stops at the time budget and queues the rest.
	 */
	public static function issue_deferred( array $ticket_ids ) {
		$apple = self::provider( 'apple' );
		$google = self::provider( 'google' );
		$deadline = TimeBudget::deadline();
		$ids = array_values( array_unique( array_map( 'intval', $ticket_ids ) ) );
		$ok = true;
		foreach ( $ids as $index => $ticket_id ) {
			if ( $index > 0 && TimeBudget::exhausted( $deadline ) ) {
				return self::requeue( self::ISSUE_JOB, array_slice( $ids, $index ) ) && $ok;
			}
			$ticket = self::ticket( $ticket_id );
			if ( ! $ticket || null === $ticket['code'] || $ticket['voided'] ) { continue; }
			if ( $apple && method_exists( $apple, 'pass_type_id' ) ) {
				Store::save_pass( $ticket_id, 'apple', (string) $ticket['public_id'], (string) $apple->pass_type_id(), 'active' );
			}
			if ( is_object( $google ) && method_exists( $google, 'object_id' ) ) {
				$object_id = $google->object_id( $ticket );
				try {
					$created = Mailer::with_locale( $ticket['locale'], static function () use ( $google, $ticket, $object_id ) { return $google->update_pass( $ticket, array( 'serial_number' => $object_id ) ); } );
				} catch ( \Throwable $e ) {
					self::log( 'issue_failed', 'google:' . $e->getMessage() );
					$created = false;
				}
				$pass_id = Store::save_pass( $ticket_id, 'google', $object_id, $google->class_id( $ticket['occurrence_id'] ), 'active' );
				if ( $created ) {
					Store::mark_synced( $pass_id );
				} else {
					$ok = false;
				}
			}
		}
		return $ok;
	}

	/** Queues the remaining tickets of a job as a new job (time budget used up). */
	private static function requeue( $type, array $ticket_ids ) {
		$queued = true === Jobs::enqueue( 'wallet-' . $type . '-' . md5( wp_json_encode( $ticket_ids ) . microtime( true ) . random_int( 0, PHP_INT_MAX ) ), $type, array( 'ticket_ids' => array_values( $ticket_ids ) ) );
		if ( ! $queued ) { self::log( 'queue_failed', $type ); }
		return $queued;
	}

	/* ---- collection page (all passes of an order) ---- */

	/** Validity of a collection link in seconds (180 days). */
	const COLLECTION_TTL = 15552000;

	public static function collection_sig( $order_public_id, $expires ) {
		return hash_hmac( 'sha256', 'orderpasses|' . $order_public_id . '|' . (int) $expires, Store::key() );
	}

	/** Signed link to the page with the wallet buttons of all tickets of an order that the buyer still holds. */
	public static function collection_url( $order ) {
		$public_id = (string) ( $order->public_id ?? '' );
		if ( '' === $public_id ) { return ''; }
		$expires = time() + self::COLLECTION_TTL;
		return add_query_arg( array( 'exp' => $expires, 'sig' => self::collection_sig( $public_id, $expires ) ), rest_url( 'wp-pass-captain/v1/wallet/order/' . rawurlencode( $public_id ) ) );
	}

	/**
	 * Wallet links of the tickets of an order the buyer still holds (valid, never transferred, no
	 * other holder): Apple download link and Google save link (skinny JWT once the object exists).
	 *
	 * @return array[] event, ticket, when, apple, google
	 */
	public static function collection( $order_public_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order = $wpdb->get_row( $wpdb->prepare( 'SELECT order_id,customer_email,locale FROM %i WHERE public_id=%s', $p . 'wpcap_orders', (string) $order_public_id ) );
		if ( ! $order ) { return array(); }
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT t.ticket_id FROM %i t INNER JOIN %i s ON s.ticket_id=t.ticket_id WHERE t.order_id=%d AND t.status IN ('valid','checked_in') AND t.transfer_count=0 AND (t.attendee_email='' OR t.attendee_email=%s) ORDER BY t.ticket_id", $p . 'wpcap_tickets', $p . 'wpcap_wallet_state', (int) $order->order_id, (string) $order->customer_email ) );
		$apple = self::provider( 'apple' );
		$google = self::provider( 'google' );
		$out = array();
		foreach ( $ids as $ticket_id ) {
			$ticket = self::ticket( (int) $ticket_id );
			if ( ! $ticket || $ticket['voided'] || null === $ticket['code'] ) { continue; }
			$row = array( 'event' => $ticket['event_name'], 'ticket' => $ticket['ticket_name'], 'when' => \PassCaptain\Orders::format_start( $ticket['starts_at'], $ticket['timezone'] ), 'apple' => '', 'google' => '' );
			if ( $apple ) {
				if ( ! Store::pass_by_serial( 'apple', (string) $ticket['public_id'] ) && method_exists( $apple, 'pass_type_id' ) ) {
					Store::save_pass( (int) $ticket_id, 'apple', (string) $ticket['public_id'], (string) $apple->pass_type_id(), 'active' );
				}
				$row['apple'] = self::download_url( (string) $ticket['public_id'] );
			}
			if ( is_object( $google ) && method_exists( $google, 'save_url' ) ) {
				$pass = Store::pass_by_serial( 'google', $google->object_id( $ticket ) );
				try {
					$row['google'] = $google->save_url( $ticket, $pass && ! empty( $pass->synced_at ) );
				} catch ( \Throwable $e ) {
					self::log( 'issue_failed', 'google:' . $e->getMessage() );
				}
			}
			$out[] = $row;
		}
		return $out;
	}

	/** HTML of the collection page (in the order's language). */
	public static function collection_html( $order_public_id ) {
		global $wpdb;
		$locale = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT locale FROM %i WHERE public_id=%s', $wpdb->prefix . 'wpcap_orders', (string) $order_public_id ) );
		return Mailer::with_locale( $locale, static function () use ( $order_public_id ) {
			$rows = self::collection( $order_public_id );
			$html = '<!doctype html><html lang="' . esc_attr( str_replace( '_', '-', Mailer::current_locale() ) ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . esc_html__( 'Wallet-Pässe', 'pass-captain' ) . '</title>'
				. '<style>body{font:16px/1.5 system-ui,sans-serif;max-width:40rem;margin:2rem auto;padding:0 1rem;color:#1d2327}li{margin:.75rem 0;padding:.75rem;border:1px solid #c3c4c7;border-radius:8px;list-style:none}a{margin-right:1rem}</style></head><body><main>'
				. '<h1>' . esc_html__( 'Wallet-Pässe deiner Tickets', 'pass-captain' ) . '</h1>';
			if ( ! $rows ) {
				$html .= '<p>' . esc_html__( 'Für diese Bestellung sind keine Wallet-Pässe (mehr) verfügbar. Übertragene oder neu ausgestellte Tickets erhalten ihre Pässe mit der jeweiligen E-Mail.', 'pass-captain' ) . '</p>';
			}
			$html .= '<ul>';
			foreach ( $rows as $row ) {
				$html .= '<li><strong>' . esc_html( $row['event'] . ' — ' . $row['ticket'] ) . '</strong><br>' . esc_html( $row['when'] ) . '<br>';
				if ( '' !== $row['apple'] ) { $html .= '<a href="' . esc_url( $row['apple'] ) . '">' . esc_html__( 'Zu Apple Wallet hinzufügen', 'pass-captain' ) . '</a>'; }
				if ( '' !== $row['google'] ) { $html .= '<a href="' . esc_url( $row['google'] ) . '">' . esc_html__( 'In Google Wallet speichern', 'pass-captain' ) . '</a>'; }
				$html .= '</li>';
			}
			return $html . '</ul><p><small>' . esc_html__( 'Diesen persönlichen Link nicht teilen: Er enthält gültige Tickets.', 'pass-captain' ) . '</small></p></main></body></html>';
		} );
	}

	/* ---- code rotation ---- */

	/**
	 * A ticket got a new code (resend, transfer, participant assignment). Starts a new pass
	 * generation: devices/accounts holding the old pass only receive a voided version.
	 *
	 * @return array Passes of the new generation (empty when nothing changed).
	 */
	public static function on_code_rotated( $ticket_id, $code, $reason = '' ) {
		$state = Store::state( $ticket_id );
		if ( ! $state || ! is_string( $code ) || hash_equals( (string) $state->code_hash, hash( 'sha256', $code ) ) ) { return array(); }
		global $wpdb;
		$current = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT token_hash FROM %i WHERE ticket_id=%d', $wpdb->prefix . 'wpcap_tickets', absint( $ticket_id ) ) );
		if ( ! hash_equals( $current, hash( 'sha256', $code ) ) ) { return array(); }
		foreach ( Store::passes( $ticket_id, 'google' ) as $old ) { Store::set_pass_status( $old->wallet_pass_id, 'retired' ); }
		if ( in_array( (string) $reason, self::DEFERRED_REASONS, true ) ) {
			// Batch print (possibly hundreds of tickets): no inline signing; passes follow through the job queue.
			self::defer( array( (int) $ticket_id => $code ) );
			self::queue( array( (int) $ticket_id ) );
			return array();
		}
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
		return '' === $part ? $text : $text . "\n" . __( 'Wallet', 'pass-captain' ) . "\n" . $part;
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
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT s.ticket_id FROM %i s INNER JOIN %i t ON t.ticket_id=s.ticket_id INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=%d', $p . 'wpcap_wallet_state', $p . 'wpcap_tickets', $p . 'wpcap_ticket_types', absint( $occurrence_id ) ) );
		self::sync_many( $ids );
		// Tickets may already have been synced through wpcap_ticket_revoked; the Google class still needs the new date/status.
		if ( $ids ) { self::$pending_occurrences[ (int) $occurrence_id ] = true; }
	}

	public static function on_order_changed( $order_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		self::sync_many( $wpdb->get_col( $wpdb->prepare( 'SELECT s.ticket_id FROM %i s INNER JOIN %i t ON t.ticket_id=s.ticket_id WHERE t.order_id=%d', $p . 'wpcap_wallet_state', $p . 'wpcap_tickets', absint( $order_id ) ) ) );
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
		if ( self::ISSUE_JOB === $type ) { return self::issue_deferred( (array) ( $payload['ticket_ids'] ?? array() ) ); }
		if ( self::JOB !== $type ) { return $handled; }
		if ( ! empty( $payload['resync'] ) ) {
			// Signing key revoked: recompute fingerprints (new barcode), then push/update every pass.
			foreach ( (array) ( $payload['ticket_ids'] ?? array() ) as $id ) { self::sync_ticket( (int) $id ); }
		}
		$occurrences = (array) ( $payload['occurrence_ids'] ?? array() );
		if ( ! empty( $payload['occurrence_id'] ) ) { $occurrences[] = (int) $payload['occurrence_id']; }
		return self::process( (array) ( $payload['ticket_ids'] ?? array() ), $occurrences );
	}

	/** Any ticket of an occurrence that has a Google pass (class data source), or null. */
	private static function class_ticket( $occurrence_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT w.ticket_id FROM %i w INNER JOIN %i t ON t.ticket_id=w.ticket_id INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id WHERE w.provider='google' AND tt.occurrence_id=%d LIMIT 1", $p . 'wpcap_wallet_passes', $p . 'wpcap_tickets', $p . 'wpcap_ticket_types', absint( $occurrence_id ) ) );
		return $id ? self::ticket( (int) $id ) : null;
	}

	/** Sends Apple pushes and Google updates. Idempotent; false = retry later. */
	public static function process( array $ticket_ids, $occurrence_ids = array() ) {
		$apple = self::provider( 'apple' );
		$google = self::provider( 'google' );
		$deadline = TimeBudget::deadline();
		AppleProvider::begin_apns_run();
		try {
			return self::process_run( $apple, $google, array_values( $ticket_ids ), $occurrence_ids, $deadline );
		} finally {
			// One APNs connection per run (HTTP/2 multiplexing); closed when the run ends.
			AppleProvider::release_apns_client();
		}
	}

	private static function process_run( $apple, $google, array $ticket_ids, $occurrence_ids, $deadline ) {
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
		foreach ( $ticket_ids as $index => $ticket_id ) {
			if ( $index > 0 && TimeBudget::exhausted( $deadline ) ) {
				// Budget used up: the rest continues as its own job instead of retrying this one from the start.
				return self::requeue( self::JOB, array_slice( $ticket_ids, $index ) ) && $ok;
			}
			$ticket = null;
			foreach ( Store::passes( $ticket_id ) as $pass ) {
				$row = (array) $pass;
				try {
					if ( 'apple' === $pass->provider && $apple ) {
						$ok = ( method_exists( $apple, 'notify' ) ? $apple->notify( $pass->serial_number ) : $apple->revoke_pass( $row ) ) && $ok;
					} elseif ( 'google' === $pass->provider && $google ) {
						$ticket = $ticket ?: self::ticket( $ticket_id );
						if ( 'retired' === $pass->status ) {
							$ok = $google->revoke_pass( $row ) && $ok;
						} else {
							$updated = $ticket && Mailer::with_locale( $ticket['locale'], static function () use ( $google, $ticket, $row ) { return $google->update_pass( $ticket, $row ); } );
							if ( $updated ) { Store::mark_synced( (int) $pass->wallet_pass_id ); }
							$ok = $updated && $ok;
						}
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
		$bundle = Mailer::with_locale( $ticket['locale'] ?? '', static function () use ( $apple, $ticket, $pass ) { return $apple->update_pass( $ticket, (array) $pass )['bundle']; } );
		return array( 'bundle' => $bundle, 'tag' => (int) $state->update_tag );
	}

	/* ---- QR signing key rotation ---- */

	/**
	 * Action wp_pass_captain_qr_key_rotated_v1: after a revocation (compromised key) every QR signed with
	 * the old key is rejected at the entrance, so all passes of upcoming occurrences get a new barcode
	 * (wallet_sync jobs with resync, 200 tickets each). A normal rotation keeps the old key valid until
	 * its last QR expires; nothing to do then.
	 *
	 * @param array $result SignedQr::rotate() result (revoked, key_id, previous).
	 */
	public static function on_qr_key_rotated( $result ) {
		if ( empty( $result['revoked'] ) || ! SignedQr::enabled() ) { return; }
		global $wpdb;
		$p = $wpdb->prefix;
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT s.ticket_id FROM %i s INNER JOIN %i w ON w.ticket_id=s.ticket_id AND w.status='active' INNER JOIN %i t ON t.ticket_id=s.ticket_id INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id WHERE t.status IN ('valid','checked_in') AND o.starts_at>=%s ORDER BY s.ticket_id", $p . 'wpcap_wallet_state', $p . 'wpcap_wallet_passes', $p . 'wpcap_tickets', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ) ) );
		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			if ( true !== Jobs::enqueue( 'wallet-resync-' . md5( wp_json_encode( $chunk ) . microtime( true ) . random_int( 0, PHP_INT_MAX ) ), self::JOB, array( 'ticket_ids' => $chunk, 'occurrence_ids' => array(), 'resync' => 1 ) ) ) {
				self::log( 'queue_failed', 'wallet_resync' );
			}
		}
	}

	/* ---- system status ---- */

	/**
	 * Wallet problems for the system status (Ops\Status::issues()): Apple certificates that expire
	 * within 30 days or have expired, and APNs rejecting the certificate (HTTP 403, no retry).
	 *
	 * @return array[] {severity, message}
	 */
	public static function status_issues( $now = null ) {
		$now = null === $now ? time() : (int) $now;
		$issues = array();
		if ( ! Settings::get( 'wallet_apple_enabled' ) ) { return $issues; }
		$credentials = Credentials::apple();
		if ( is_array( $credentials ) ) {
			foreach ( array( 'cert' => __( 'Apple-Wallet-Zertifikat (Pass Type ID)', 'pass-captain' ), 'wwdr' => __( 'Apple-WWDR-Zertifikat', 'pass-captain' ) ) as $key => $label ) {
				$cert = Credentials::describe_cert( $credentials[ $key ] );
				if ( ! $cert ) { continue; }
				if ( $cert['valid_to'] < $now ) {
					/* translators: 1: certificate, 2: date */
					$issues[] = array( 'severity' => 'error', 'message' => sprintf( __( '%1$s ist am %2$s abgelaufen: Wallet-Pässe können nicht mehr signiert oder aktualisiert werden.', 'pass-captain' ), $label, gmdate( 'd.m.Y', $cert['valid_to'] ) ) );
				} elseif ( $cert['valid_to'] < $now + 30 * DAY_IN_SECONDS ) {
					/* translators: 1: certificate, 2: date */
					$issues[] = array( 'severity' => 'warning', 'message' => sprintf( __( '%1$s läuft am %2$s ab. Bitte rechtzeitig erneuern.', 'pass-captain' ), $label, gmdate( 'd.m.Y', $cert['valid_to'] ) ) );
				}
			}
		}
		$forbidden = (int) get_option( self::APNS_ERROR_OPTION, 0 );
		if ( $forbidden && $forbidden > $now - 7 * DAY_IN_SECONDS ) {
			$issues[] = array( 'severity' => 'error', 'message' => __( 'Apple Push (APNs) lehnt das Wallet-Zertifikat ab (HTTP 403). Pass-Aktualisierungen erreichen die Geräte nicht; bitte Zertifikat und Pass-Type-ID prüfen.', 'pass-captain' ) );
		}
		return $issues;
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

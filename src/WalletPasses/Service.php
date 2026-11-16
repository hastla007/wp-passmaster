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
		add_filter( 'wpcap_ticket_resend_attachments', array( __CLASS__, 'resend_attachments' ), 20, 3 );
		add_action( 'wpcap_ticket_revoked', array( __CLASS__, 'on_ticket_changed' ), 20, 1 );
		add_action( 'wpcap_occurrence_rescheduled', array( __CLASS__, 'on_occurrence_changed' ), 20, 1 );
		add_action( 'wpcap_occurrence_cancelled', array( __CLASS__, 'on_occurrence_changed' ), 20, 1 );
		// A559/A582: location, end, doors open, status (any calendar-relevant field), event and ticket
		// type names and attendee corrections reach the passes too.
		add_action( 'wpcap_occurrence_changed', array( __CLASS__, 'on_occurrence_changed' ), 20, 1 );
		add_action( 'wpcap_event_updated', array( __CLASS__, 'on_event_changed' ), 20, 1 );
		add_action( 'wpcap_ticket_type_updated', array( __CLASS__, 'on_ticket_type_changed' ), 20, 1 );
		add_action( 'wpcap_record_corrected', array( __CLASS__, 'on_record_corrected' ), 20, 2 );
		add_action( 'wpcap_ticket_attendee_updated', array( __CLASS__, 'on_ticket_changed' ), 20, 1 );
		add_action( 'wpcap_order_cancelled', array( __CLASS__, 'on_order_changed' ), 20, 1 );
		add_action( 'wpcap_refund_completed', array( __CLASS__, 'on_refund_completed' ), 20, 2 );
		// A566: erased or expired personal data leaves the passes too (Google objects lose the name).
		add_action( 'wpcap_privacy_order_anonymized', array( __CLASS__, 'on_order_changed' ), 20, 1 );
		add_action( 'wpcap_privacy_attendees_cleared', array( __CLASS__, 'on_attendees_cleared' ), 20, 1 );
		// Fired by core on resend, transfer, participant assignment and reprint (any reason).
		add_action( 'wpcap_ticket_code_rotated', array( __CLASS__, 'on_code_rotated' ), 10, 3 );
		// A490: tickets issued outside the ticket mail (exchange) get their passes too.
		add_action( 'wpcap_ticket_code_issued', array( __CLASS__, 'on_code_issued' ), 10, 2 );
		// A revoked QR signing key: every active pass of an upcoming occurrence needs a new barcode.
		add_action( 'wp_pass_captain_qr_key_rotated_v1', array( __CLASS__, 'on_qr_key_rotated' ), 10, 1 );
		// Wallet links for the new code in the resend/transfer/assignment mails.
		add_filter( 'wpcap_ticket_code_mail_section', array( __CLASS__, 'code_mail_section' ), 10, 2 );
		// The stored (encrypted) code lets the order portal build a ticket PDF without a new code.
		add_filter( 'wpcap_ticket_known_code', array( __CLASS__, 'known_code' ), 10, 2 );
		add_filter( 'wpcap_process_job', array( __CLASS__, 'handle_job' ), 10, 3 );
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
		$doors = \PassCaptain\DoorsOpen::ready() ? 'o.doors_open_at' : 'NULL';
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT t.ticket_id,t.public_id,t.order_id,t.status,t.token_hash,t.ticket_type_id,tt.name AS ticket_name,o.occurrence_id,o.event_id,o.starts_at,o.ends_at,$doors AS doors_open_at,o.timezone,o.location,o.status AS occurrence_status,e.name AS event_name,e.status AS event_status,ta.attendee_name,ord.locale FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id LEFT JOIN {$p}wpcap_ticket_assignments ta ON ta.ticket_id=t.ticket_id LEFT JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id WHERE t.ticket_id=%d",
			absint( $ticket_id )
		), ARRAY_A );
		if ( ! $row ) { return null; }
		$row['doors_open_at'] = empty( $row['doors_open_at'] ) || '0000-00-00 00:00:00' === $row['doors_open_at'] ? null : (string) $row['doors_open_at'];
		$state = Store::state( $ticket_id );
		$secrets = Store::secrets( $state );
		$row['attendee_name'] = (string) ( $row['attendee_name'] ?? '' );
		// Pass fields are plain text: stored entities ("Kids &lt;3") are decoded (A497).
		foreach ( array( 'event_name', 'ticket_name', 'location', 'attendee_name' ) as $plain_key ) { $row[ $plain_key ] = \PassCaptain\Text::plain( $row[ $plain_key ] ?? '' ); }
		// A474: passes are (re)built in the buyer's language, whatever the request's locale is.
		$row['locale'] = \PassCaptain\Mail\Mailer::normalize_locale( (string) ( $row['locale'] ?? '' ) );
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
		// A584: a secret that cannot be opened (salt/key changed) is not a revoked ticket. Passes keep
		// their last state instead of being rebuilt without barcode (see code_unavailable()).
		$row['secret_unreadable'] = $state && null === $secrets;
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
		$keys = array( 'status', 'voided', 'starts_at', 'ends_at', 'doors_open_at', 'timezone', 'location', 'event_name', 'ticket_name', 'seat', 'attendee_name', 'code_hash', 'generation', 'occurrence_status', 'event_status' );
		$data = array_intersect_key( $ticket, array_flip( $keys ) );
		$data['qr_key'] = SignedQr::enabled() ? SignedQr::active_key_id() : '';
		return hash( 'sha256', (string) wp_json_encode( $data ) );
	}

	/* ---- issuance ---- */

	/**
	 * Stores the code and creates the provider passes of one ticket.
	 *
	 * @param bool $build false: only store the code and the pass rows (no .pkpass is signed), for
	 *                    large orders whose mail carries "add all" links instead (A580).
	 * @return array{apple?:array,google?:array}
	 */
	public static function issue( $ticket_id, $code, $build = true ) {
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
				$pass = ! $build && method_exists( $provider, 'pass_ref' ) ? $provider->pass_ref( $ticket ) : self::in_locale( $ticket, static function () use ( $provider, $ticket ) { return $provider->create_pass( $ticket ); } );
				Store::save_pass( $ticket_id, $key, (string) $pass['serial_number'], (string) ( $pass['provider_ref'] ?? '' ), $ticket['voided'] ? 'voided' : 'active' );
				if ( 'apple' === $key ) { $pass['download_url'] = self::download_url( $pass['serial_number'] ); }
				if ( 'google' === $key && $provider instanceof GoogleProvider ) { $pass['save_url'] = self::google_link( $ticket_id ); }
				$out[ $key ] = $pass;
			} catch ( \Throwable $e ) {
				self::log( 'issue_failed', $key . ':' . $e->getMessage() );
			}
		}
		return $out;
	}

	/**
	 * Whether the current generation of a pass cannot be rebuilt because its stored code cannot be
	 * decrypted (key changed) while the ticket itself is still valid (A584). Devices then keep their
	 * last pass (304) and Google objects are not switched to INACTIVE.
	 */
	public static function code_unavailable( array $ticket ) {
		return ! empty( $ticket['secret_unreadable'] ) && empty( $ticket['voided'] );
	}

	/** Runs $callback in the ticket's (order's) locale (A474). */
	private static function in_locale( array $ticket, callable $callback ) {
		return \PassCaptain\Mail\Mailer::with_locale( (string) ( $ticket['locale'] ?? '' ), $callback );
	}

	/** Signed, non-expiring download link for the current generation of an Apple pass. */
	public static function download_url( $serial ) {
		$pass = Store::pass_by_serial( 'apple', $serial );
		$state = $pass ? Store::state( $pass->ticket_id ) : null;
		if ( ! $state ) { return ''; }
		return add_query_arg( 'sig', self::download_sig( $serial, $state ), rest_url( 'pass-captain/v1/' . AppleProvider::REST_BASE . '/download/' . rawurlencode( $serial ) ) );
	}

	public static function download_sig( $serial, $state ) {
		return self::link_sig( 'dl|' . $serial . '|' . $state->auth_token_hash );
	}

	/** HMAC of a wallet link with the link key (A648: not the encryption key). */
	public static function link_sig( $payload, $legacy = false ) {
		return hash_hmac( 'sha256', (string) $payload, $legacy ? Store::key() : Store::link_key() );
	}

	/**
	 * Checks a link signature. Links sent before A648 were signed with the encryption key; they stay
	 * valid (verification only – new links are always signed with the link key).
	 */
	public static function link_sig_valid( $payload, $sig ) {
		$sig = (string) $sig;
		return '' !== $sig && ( hash_equals( self::link_sig( $payload ), $sig ) || hash_equals( self::link_sig( $payload, true ), $sig ) );
	}

	/** Whether $sig is a valid download signature of the current generation. */
	public static function download_sig_valid( $serial, $state, $sig ) {
		return self::link_sig_valid( 'dl|' . $serial . '|' . $state->auth_token_hash, $sig );
	}

	/* ---- short Google links and "add all" links (A583, A580) ---- */

	/** Tickets per Google save JWT (object IDs only) and per Apple .pkpasses bundle. */
	public static function batch_size( $provider ) {
		$default = 'google' === $provider ? 8 : 10;
		/**
		 * Tickets per "add all" wallet link: Google object IDs per save JWT (default and maximum 8;
		 * measured ~1,500 characters, below the 1,800 Google recommends) or Apple passes per
		 * .pkpasses bundle (default and maximum 10).
		 *
		 * @param int    $size
		 * @param string $provider 'apple' or 'google'.
		 */
		return max( 1, min( $default, (int) apply_filters( 'wpcap_wallet_batch_size', $default, $provider ) ) );
	}

	/** Up to this many tickets a ticket mail lists wallet links (and .pkpass files) per ticket. */
	public static function mail_inline_limit() {
		/**
		 * Tickets per ticket mail up to which wallet passes are attached/linked per ticket (A580);
		 * larger orders get one "add all" link per batch instead.
		 *
		 * @param int $limit Default 10.
		 */
		return max( 0, (int) apply_filters( 'wpcap_wallet_mail_inline_limit', 10 ) );
	}

	/** Short "Save to Google Wallet" link of the ticket's current generation (signed, no ticket data). */
	public static function google_link( $ticket_id ) {
		global $wpdb;
		$state = Store::state( $ticket_id );
		$public = (string) $wpdb->get_var( $wpdb->prepare( "SELECT public_id FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_id=%d", absint( $ticket_id ) ) );
		if ( ! $state || '' === $public ) { return ''; }
		return add_query_arg( array( 't' => $public, 'sig' => self::google_ticket_sig( $public, $state ) ), rest_url( WebService::NS . '/wallet/google/save' ) );
	}

	public static function google_ticket_sig( $public_id, $state ) {
		return self::link_sig( 'gsave|' . $public_id . '|' . $state->auth_token_hash );
	}

	/**
	 * Signature of the order-wide "add all" links. Bound to the order's access token: a new order
	 * link (portal) invalidates them, like the portal link itself.
	 */
	public static function order_sig( $kind, $order ) {
		return self::link_sig( 'wallet-all|' . $kind . '|' . $order->public_id . '|' . (string) $order->access_token_hash );
	}

	/**
	 * Tickets an "add all" link of the order covers: valid tickets that the buyer still holds (not
	 * passed on or assigned to another e-mail address – like the portal PDF), with a pass of the provider.
	 *
	 * @return int[] Ticket ids in issuance order.
	 */
	public static function order_ticket_ids( $order, $provider ) {
		global $wpdb;
		$p = $wpdb->prefix;
		// A581: tickets whose current code went to another address are left out; an attendee address from
		// the checkout alone does not count (the buyer received that code).
		$with_buyer = \PassCaptain\TicketCodes::holder_ready() ? ' OR t.code_with_attendee=0' : '';
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT t.ticket_id FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_wallet_state s ON s.ticket_id=t.ticket_id INNER JOIN {$p}wpcap_wallet_passes w ON w.ticket_id=t.ticket_id AND w.provider=%s AND w.status='active' WHERE t.order_id=%d AND t.status='valid' AND t.transfer_count=0 AND (t.attendee_email IS NULL OR t.attendee_email='' OR LOWER(t.attendee_email)=LOWER(%s)$with_buyer) ORDER BY t.ticket_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed SQL fragment.
			$provider, (int) $order->order_id, (string) $order->customer_email
		) ) );
	}

	/** "Add all" links of an order: provider => list of [url, first, last]. */
	public static function order_links( $order, $pending = 0 ) {
		$out = array();
		foreach ( array( 'apple' => 'wallet/apple/bundle', 'google' => 'wallet/google/save' ) as $key => $route ) {
			if ( ! self::provider( $key ) ) { continue; }
			// $pending: tickets of a ticket mail whose new codes commit only after the mail (outbox delivery).
			$count = max( count( self::order_ticket_ids( $order, $key ) ), absint( $pending ) );
			$size = self::batch_size( $key );
			for ( $part = 0; $part * $size < $count; $part++ ) {
				$url = add_query_arg( array( 'o' => (string) $order->public_id, 'p' => $part + 1, 'sig' => self::order_sig( $key, $order ) ), rest_url( WebService::NS . '/' . $route ) );
				$out[ $key ][] = array( $url, $part * $size + 1, min( $count, ( $part + 1 ) * $size ) );
			}
		}
		return $out;
	}

	/** Mail text with the "add all" links of an order ('' without passes). */
	private static function order_links_text( $order, $pending = 0 ) {
		$text = '';
		foreach ( self::order_links( $order, $pending ) as $key => $links ) {
			foreach ( $links as $link ) {
				list( $url, $first, $last ) = $link;
				if ( $first === $last ) {
					$label = 'apple' === $key
						/* translators: %d: ticket number */
						? sprintf( __( 'Ticket %d zu Apple Wallet hinzufügen:', 'pass-captain' ), $first )
						/* translators: %d: ticket number */
						: sprintf( __( 'Ticket %d in Google Wallet speichern:', 'pass-captain' ), $first );
				} elseif ( 'apple' === $key ) {
					/* translators: 1: first ticket number, 2: last ticket number */
					$label = sprintf( __( 'Tickets %1$d–%2$d zu Apple Wallet hinzufügen:', 'pass-captain' ), $first, $last );
				} else {
					/* translators: 1: first ticket number, 2: last ticket number */
					$label = sprintf( __( 'Tickets %1$d–%2$d in Google Wallet speichern:', 'pass-captain' ), $first, $last );
				}
				$text .= $label . ' ' . esc_url_raw( $url ) . "\n";
			}
		}
		return '' === $text ? '' : $text . "\n";
	}

	/**
	 * Tickets a signed wallet link stands for (A580/A583): one ticket (t + sig over its current
	 * generation, Google) or one batch of an order (o, p, sig). Voided tickets and tickets whose code
	 * is not available are left out.
	 *
	 * @return array[]|null Service::ticket() rows, null for an invalid link.
	 */
	public static function link_tickets( $provider, array $params ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$sig = (string) ( $params['sig'] ?? '' );
		if ( 'google' === $provider && ! empty( $params['t'] ) ) {
			$public = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $params['t'] ) );
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE public_id=%s", $public ) );
			$state = $id ? Store::state( $id ) : null;
			if ( ! $state || ! self::link_sig_valid( 'gsave|' . $public . '|' . $state->auth_token_hash, $sig ) ) { return null; }
			$ids = array( $id );
		} else {
			$public = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) ( $params['o'] ?? '' ) ) );
			$order = '' === $public ? null : $wpdb->get_row( $wpdb->prepare( "SELECT order_id,public_id,customer_email,access_token_hash FROM {$p}wpcap_orders WHERE public_id=%s", $public ) );
			if ( ! $order || ! self::link_sig_valid( 'wallet-all|' . $provider . '|' . $order->public_id . '|' . (string) $order->access_token_hash, $sig ) ) { return null; }
			$size = self::batch_size( $provider );
			$ids = array_slice( self::order_ticket_ids( $order, $provider ), max( 0, (int) ( $params['p'] ?? 1 ) - 1 ) * $size, $size );
		}
		$out = array();
		foreach ( $ids as $id ) {
			$ticket = self::ticket( $id );
			if ( $ticket && empty( $ticket['voided'] ) && '' !== (string) ( $ticket['code'] ?? '' ) ) { $out[] = $ticket; }
		}
		return $out;
	}

	/** Google save URL for the tickets of a link: object IDs only, single-ticket fat JWT as fallback. */
	public static function google_save_url( array $tickets ) {
		$google = self::provider( 'google' );
		if ( ! $google instanceof GoogleProvider || ! $tickets ) { return ''; }
		try {
			$url = self::in_locale( $tickets[0], static function () use ( $google, $tickets ) {
				$url = $google->save_url_for( $tickets );
				if ( '' === $url && 1 === count( $tickets ) ) {
					// Fallback JWT with the full object: Google creates it when the buyer saves (A566).
					$url = $google->save_url( $tickets[0] );
					Store::mark_remote( 'google', $google->object_id( $tickets[0] ) );
				}
				return $url;
			} );
		} catch ( \Throwable $e ) {
			self::log( 'save_failed', 'google:' . $e->getMessage() );
			return '';
		}
		if ( '' === $url ) { self::log( 'save_failed', 'google:api' ); }
		return $url;
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
		// A626: kept for mail queue plugins until the cleanup sweep (Mail\TempFiles), unless the TTL is 0.
		\PassCaptain\Mail\TempFiles::release( array_column( self::$temp_files, 0 ), array_column( self::$temp_files, 1 ) );
		self::$temp_files = array();
	}

	/**
	 * Filter wpcap_ticket_mail: stores the codes while they are available in plaintext.
	 *
	 * One ticket: the pass is created right away (.pkpass attachment within the attachment budget or
	 * link, short Google save link).
	 * Several tickets up to mail_inline_limit() (audit R2 #5 + A583): no .pkpass is signed or attached
	 * during the request; the codes and pass rows are stored (passes are built on download), the mail
	 * lists the short per-ticket links plus one link to the page with the wallet buttons of all tickets
	 * (collection_url()).
	 * Larger orders (A580): no per-ticket links, one "add all" link per batch (order_links()).
	 */
	public static function on_ticket_mail( $mail, $order = null, $issued = array() ) {
		if ( ! is_array( $mail ) || ! is_array( $issued ) || ! $issued ) { return $mail; }
		$entries = array_values( array_filter( $issued, static function ( $entry ) { return ! empty( $entry['ticket_id'] ) && ! empty( $entry['token'] ); } ) );
		if ( ! $entries || ( ! self::provider( 'apple' ) && ! self::provider( 'google' ) ) ) { return $mail; }
		$locale = is_object( $order ) ? (string) ( $order->locale ?? '' ) : '';
		$text = '';
		$inline = count( $entries ) <= self::mail_inline_limit() || ! is_object( $order );
		$single = 1 === count( $entries );
		$any = false;
		$used = 0;
		foreach ( (array) ( $mail['attachments'] ?? array() ) as $file ) { $used += is_string( $file ) && is_file( $file ) ? (int) filesize( $file ) : 0; }
		$budget = \PassCaptain\Orders::attachment_budget( $order );
		foreach ( $entries as $entry ) {
			try {
				$passes = self::issue( (int) $entry['ticket_id'], (string) $entry['token'], $inline && $single );
			} catch ( \Throwable $e ) {
				self::log( 'issue_failed', $e->getMessage() );
				continue;
			}
			$any = $any || (bool) $passes;
			if ( ! $inline ) { continue; }
			// Within the attachment budget the .pkpass of a single ticket is attached, otherwise linked (A580, R2).
			$allow = $single && $used + strlen( (string) ( $passes['apple']['bundle'] ?? '' ) ) <= $budget;
			list( $part, $files ) = Mailer::with_locale( $locale, static function () use ( $passes, $entry, $allow ) { return self::mail_parts( $passes, array( $entry['event'] ?? '', $entry['ticket'] ?? '' ), $allow ); } );
			foreach ( $files as $file ) { $used += (int) filesize( $file ); }
			$text .= $part;
			$mail['attachments'] = array_merge( (array) ( $mail['attachments'] ?? array() ), $files );
		}
		if ( ! $inline && $any ) {
			$pending = count( $entries );
			$text = Mailer::with_locale( $locale, static function () use ( $order, $pending ) { return self::order_links_text( $order, $pending ); } );
		} elseif ( ! $single && $any && is_object( $order ) ) {
			$url = self::collection_url( $order );
			if ( '' !== $url ) {
				$count = count( $entries );
				$text .= Mailer::with_locale( $locale, static function () use ( $count, $url ) {
					/* translators: %d: number of tickets */
					return sprintf( __( 'Apple Wallet / Google Wallet für alle %d Tickets:', 'pass-captain' ), $count ) . ' ' . esc_url_raw( $url ) . "\n";
				} );
			}
		}
		if ( '' !== $text ) { $mail['body'] = (string) $mail['body'] . "\n" . Mailer::with_locale( $locale, static function () { return __( 'Wallet', 'pass-captain' ); } ) . "\n" . $text; }
		return $mail;
	}

	/**
	 * A621: wpcap_ticket_resend_attachments – the Apple Wallet .pkpass of each resent ticket (current
	 * code), within the attachment budget; the wallet links are part of the resend text already
	 * (wpcap_ticket_code_mail_section). Google passes are links only.
	 */
	public static function resend_attachments( $files, $order = null, $tickets = array() ) {
		$files = (array) $files;
		$apple = self::provider( 'apple' );
		if ( ! $apple || ! is_array( $tickets ) || count( $tickets ) > self::mail_inline_limit() ) { return $files; }
		$used = 0;
		foreach ( $files as $file ) { $used += is_string( $file ) && is_file( $file ) ? (int) filesize( $file ) : 0; }
		$budget = \PassCaptain\Orders::attachment_budget( $order );
		foreach ( $tickets as $entry ) {
			if ( empty( $entry['ticket_id'] ) || empty( $entry['token'] ) ) { continue; }
			try {
				// Only tickets whose current wallet generation holds this code (issuance or the rotation
				// hook stored it); storing it again would start another generation.
				$state = Store::state( (int) $entry['ticket_id'] );
				if ( ! $state || ! hash_equals( (string) $state->code_hash, hash( 'sha256', (string) $entry['token'] ) ) ) { continue; }
				$ticket = self::ticket( (int) $entry['ticket_id'] );
				if ( ! $ticket ) { continue; }
				$pass = self::in_locale( $ticket, static function () use ( $apple, $ticket ) { return $apple->create_pass( $ticket ); } );
			} catch ( \Throwable $e ) {
				self::log( 'issue_failed', 'apple:' . $e->getMessage() );
				continue;
			}
			$allow = $used + strlen( (string) ( $pass['bundle'] ?? '' ) ) <= $budget;
			list( , $new ) = self::mail_parts( array( 'apple' => $pass ), array( $entry['event'] ?? '', $entry['ticket'] ?? '' ), $allow );
			foreach ( $new as $file ) { $used += (int) filesize( $file ); }
			$files = array_merge( $files, $new );
		}
		return $files;
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
	 * (the bundle itself is built on download). Google objects are created when the buyer saves the pass
	 * (A566). Stops at the time budget and queues the rest.
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
			// Google objects are not created here: A566 – the provider gets personal data only once the buyer
			// uses the save link (the collection page and the mail carry the full save link instead).
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
		return add_query_arg( array( 'exp' => $expires, 'sig' => self::collection_sig( $public_id, $expires ) ), rest_url( 'pass-captain/v1/wallet/order/' . rawurlencode( $public_id ) ) );
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
					// Skinny JWT (object id only) once the object exists at Google (saved by the buyer, A566).
					$row['google'] = $google->save_url( $ticket, $pass && Store::remote_ready() && ! empty( $pass->remote_at ) );
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
		if ( ! $state || ! is_string( $code ) ) { return array(); }
		global $wpdb;
		$current = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT token_hash FROM %i WHERE ticket_id=%d', $wpdb->prefix . 'wpcap_tickets', absint( $ticket_id ) ) );
		if ( hash_equals( (string) $state->code_hash, hash( 'sha256', $code ) ) ) {
			// The ticket mail stored this code before the rotation committed (outbox delivery: mail first,
			// then commit): its pass rows were saved as voided then – activate them now.
			if ( hash_equals( $current, hash( 'sha256', $code ) ) && self::sync_ticket( (int) $ticket_id ) ) { self::queue( array( (int) $ticket_id ) ); }
			return array();
		}
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

	/**
	 * A new ticket got its first code outside the ticket mail (exchange, A490): creates its passes;
	 * the exchange mail adds the wallet links through wpcap_ticket_code_mail_section.
	 *
	 * @return array Created passes.
	 */
	public static function on_code_issued( $ticket_id, $code ) {
		if ( ! is_string( $code ) || '' === $code ) { return array(); }
		$state = Store::state( $ticket_id );
		if ( $state && hash_equals( (string) $state->code_hash, hash( 'sha256', $code ) ) ) { return array(); }
		global $wpdb;
		$current = (string) $wpdb->get_var( $wpdb->prepare( "SELECT token_hash FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_id=%d", absint( $ticket_id ) ) );
		if ( ! hash_equals( $current, hash( 'sha256', $code ) ) ) { return array(); }
		$passes = self::issue( (int) $ticket_id, $code );
		self::$rotated[ (int) $ticket_id ] = $passes;
		return $passes;
	}

	/** Filter wpcap_ticket_known_code: the stored code when it is still the ticket's current one. */
	public static function known_code( $code, $ticket_id ) {
		if ( '' !== (string) $code ) { return $code; }
		$state = Store::state( $ticket_id );
		$secrets = Store::secrets( $state );
		$stored = is_array( $secrets ) ? (string) ( $secrets['code'] ?? '' ) : '';
		if ( '' === $stored ) { return $code; }
		global $wpdb;
		$current = (string) $wpdb->get_var( $wpdb->prepare( "SELECT token_hash FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_id=%d", absint( $ticket_id ) ) );
		return hash_equals( $current, hash( 'sha256', $stored ) ) ? $stored : $code;
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

	/** wpcap_event_updated: the event name is on every pass and in the Google class of each occurrence. */
	public static function on_event_changed( $event_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT s.ticket_id FROM {$p}wpcap_wallet_state s INNER JOIN {$p}wpcap_tickets t ON t.ticket_id=s.ticket_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE o.event_id=%d", absint( $event_id ) ) );
		self::sync_many( $ids );
		if ( ! $ids ) { return; }
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT occurrence_id FROM {$p}wpcap_occurrences WHERE event_id=%d", absint( $event_id ) ) ) as $occurrence_id ) {
			self::$pending_occurrences[ (int) $occurrence_id ] = true;
		}
	}

	/** wpcap_ticket_type_updated: the ticket type name is printed on the passes of that type. */
	public static function on_ticket_type_changed( $ticket_type_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		self::sync_many( $wpdb->get_col( $wpdb->prepare( "SELECT s.ticket_id FROM {$p}wpcap_wallet_state s INNER JOIN {$p}wpcap_tickets t ON t.ticket_id=s.ticket_id WHERE t.ticket_type_id=%d", absint( $ticket_type_id ) ) ) );
	}

	/** wpcap_record_corrected: staff corrected the attendee name of a ticket (same holder, same code). */
	public static function on_record_corrected( $object_type, $object_id = 0 ) {
		if ( 'ticket' === $object_type ) { self::on_ticket_changed( (int) $object_id ); }
	}

	public static function on_order_changed( $order_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		self::sync_many( $wpdb->get_col( $wpdb->prepare( 'SELECT s.ticket_id FROM %i s INNER JOIN %i t ON t.ticket_id=s.ticket_id WHERE t.order_id=%d', $p . 'wpcap_wallet_state', $p . 'wpcap_tickets', absint( $order_id ) ) ) );
	}

	public static function on_refund_completed( $refund_id, $order_id = 0 ) { self::on_order_changed( $order_id ); }

	/** wpcap_privacy_attendees_cleared (retention): attendee names of these tickets were removed. */
	public static function on_attendees_cleared( $ticket_ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', (array) $ticket_ids ) ) );
		if ( ! $ids ) { return; }
		self::sync_many( $wpdb->get_col( "SELECT ticket_id FROM {$wpdb->prefix}wpcap_wallet_state WHERE ticket_id IN (" . implode( ',', $ids ) . ')' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
	}

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

	/** Filter wpcap_process_job. */
	public static function handle_job( $handled, $type, $payload = array() ) {
		if ( self::ISSUE_JOB === $type ) { return self::issue_deferred( (array) ( $payload['ticket_ids'] ?? array() ) ); }
		if ( self::JOB !== $type ) { return $handled; }
		if ( ! empty( $payload['resync'] ) ) {
			// Signing key revoked: recompute fingerprints (new barcode), then push/update every pass.
			foreach ( (array) ( $payload['ticket_ids'] ?? array() ) as $id ) { self::sync_ticket( (int) $id ); }
		}
		$occurrences = (array) ( $payload['occurrence_ids'] ?? array() );
		if ( ! empty( $payload['occurrence_id'] ) ) { $occurrences[] = (int) $payload['occurrence_id']; }
		return self::process( (array) ( $payload['ticket_ids'] ?? array() ), $occurrences, (int) ( $payload['retry'] ?? 0 ) );
	}

	/** Follow-up jobs for the failed part of a sync before it is given up (A422). */
	const MAX_RETRIES = 10;

	/** @var array<string,int> Provider => seconds to wait, set when a provider answered 429 in this run (A422). */
	private static $rate_limited = array();

	/**
	 * A provider answered 429 (rate limit): no further calls to it in this run, the rest of the work
	 * is retried after $seconds (Retry-After, at least 60 s, at most one hour).
	 */
	public static function rate_limited( $provider, $seconds = 0 ) {
		$seconds = max( 60, min( HOUR_IN_SECONDS, (int) $seconds ) );
		self::$rate_limited[ (string) $provider ] = max( $seconds, (int) ( self::$rate_limited[ (string) $provider ] ?? 0 ) );
	}

	/** Whether a provider answered 429 in this run. */
	public static function is_rate_limited( $provider ) {
		return isset( self::$rate_limited[ (string) $provider ] );
	}

	/**
	 * Queues the failed part of a sync as a new job (A422): only the tickets and classes that failed,
	 * due after the rate limit's Retry-After or an exponential backoff (1 min … 1 h). After
	 * MAX_RETRIES follow-ups the rest is given up and logged (Wallet page).
	 *
	 * @return bool False when the follow-up job could not be written (the caller retries everything).
	 */
	private static function requeue_failed( array $ticket_ids, array $occurrence_ids, $retry ) {
		$retry = (int) $retry + 1;
		if ( $retry > self::MAX_RETRIES ) {
			self::log( 'sync_gave_up', sprintf( 'tickets:%d classes:%d', count( $ticket_ids ), count( $occurrence_ids ) ) );
			return true;
		}
		$delay = max( (int) ( self::$rate_limited ? max( self::$rate_limited ) : 0 ), min( HOUR_IN_SECONDS, 60 * ( 2 ** ( $retry - 1 ) ) ) );
		$payload = array( 'ticket_ids' => array_values( array_unique( array_map( 'intval', $ticket_ids ) ) ), 'occurrence_ids' => array_values( array_unique( array_map( 'intval', $occurrence_ids ) ) ), 'retry' => $retry );
		$key = 'wallet-sync-retry-' . md5( wp_json_encode( $payload ) . microtime( true ) . random_int( 0, PHP_INT_MAX ) );
		$queued = true === Jobs::enqueue( $key, self::JOB, $payload, gmdate( 'Y-m-d H:i:s', time() + $delay ) );
		if ( ! $queued ) { self::log( 'queue_failed', 'wallet_sync_retry' ); }
		if ( self::$rate_limited ) { self::log( 'rate_limited', implode( ',', array_keys( self::$rate_limited ) ) . ' retry_in:' . $delay ); }
		return $queued;
	}

	/**
	 * Any ticket of an occurrence whose Google object a buyer saved (class data source), or null:
	 * the class only exists at Google once a save link was used (A566).
	 */
	private static function class_ticket( $occurrence_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$remote = Store::remote_ready() ? ' AND w.remote_at IS NOT NULL' : ''; // A566: only classes with a saved object.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT w.ticket_id,w.provider_ref FROM {$p}wpcap_wallet_passes w INNER JOIN {$p}wpcap_tickets t ON t.ticket_id=w.ticket_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id WHERE w.provider='google' AND tt.occurrence_id=%d$remote ORDER BY w.wallet_pass_id LIMIT 1", absint( $occurrence_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- literal fragment.
		$ticket = $row ? self::ticket( (int) $row->ticket_id ) : null;
		// A475: the class id stored with the issued passes, not one computed from today's home_url().
		if ( $ticket && '' !== (string) $row->provider_ref ) { $ticket['google_class_id'] = (string) $row->provider_ref; }
		return $ticket;
	}

	/**
	 * Sends Apple pushes and Google updates. Idempotent. A422: the result is kept per pass and class –
	 * only what failed is queued again as a new job (requeue_failed()), passes already updated are not
	 * sent a second time; after a 429 no further calls go to that provider in this run.
	 *
	 * @param int $retry Number of the follow-up job (0 for the first run).
	 * @return bool False only when the failed part could not be queued (the caller retries everything).
	 */
	public static function process( array $ticket_ids, $occurrence_ids = array(), $retry = 0 ) {
		$apple = self::provider( 'apple' );
		$google = self::provider( 'google' );
		$deadline = TimeBudget::deadline();
		AppleProvider::begin_apns_run();
		try {
			return self::process_run( $apple, $google, array_values( $ticket_ids ), $occurrence_ids, $deadline, $retry );
		} finally {
			// One APNs connection per run (HTTP/2 multiplexing); closed when the run ends.
			AppleProvider::release_apns_client();
		}
	}

	private static function process_run( $apple, $google, array $ticket_ids, $occurrence_ids, $deadline, $retry = 0 ) {
		self::$rate_limited = array();
		$failed_classes = array();
		$failed_tickets = array();
		if ( $google && method_exists( $google, 'update_class' ) ) {
			foreach ( array_unique( array_map( 'intval', (array) $occurrence_ids ) ) as $occurrence_id ) {
				if ( self::is_rate_limited( 'google' ) ) { $failed_classes[] = $occurrence_id; continue; }
				$ticket = $occurrence_id ? self::class_ticket( $occurrence_id ) : null;
				try {
					if ( $ticket && ! self::in_locale( $ticket, static function () use ( $google, $ticket ) { return $google->update_class( $ticket ); } ) ) { $failed_classes[] = $occurrence_id; }
				} catch ( \Throwable $e ) {
					self::log( 'push_failed', 'google_class:' . $e->getMessage() );
					$failed_classes[] = $occurrence_id;
				}
			}
		}
		foreach ( $ticket_ids as $index => $ticket_id ) {
			if ( $index > 0 && TimeBudget::exhausted( $deadline ) ) {
				// Budget used up: the rest continues as its own job instead of retrying this one from the start.
				$rest = self::requeue( self::JOB, array_slice( $ticket_ids, $index ) );
				if ( ! $failed_tickets && ! $failed_classes ) { return $rest; }
				return self::requeue_failed( $failed_tickets, $failed_classes, $retry ) && $rest;
			}
			$ticket = null;
			foreach ( Store::passes( $ticket_id ) as $pass ) {
				$row = (array) $pass;
				if ( self::is_rate_limited( (string) $pass->provider ) ) { $failed_tickets[] = (int) $ticket_id; continue; }
				try {
					$done = true;
					if ( 'apple' === $pass->provider && $apple ) {
						$done = method_exists( $apple, 'notify' ) ? $apple->notify( $pass->serial_number ) : $apple->revoke_pass( $row );
					} elseif ( 'google' === $pass->provider && $google ) {
						$ticket = $ticket ?: self::ticket( $ticket_id );
						$done = 'retired' === $pass->status ? $google->revoke_pass( $row ) : ( $ticket && self::in_locale( $ticket, static function () use ( $google, $ticket, $row ) { return $google->update_pass( $ticket, $row ); } ) );
					}
					if ( ! $done ) { $failed_tickets[] = (int) $ticket_id; }
				} catch ( \Throwable $e ) {
					self::log( 'push_failed', $pass->provider . ':' . $e->getMessage() );
					$failed_tickets[] = (int) $ticket_id;
				}
			}
		}
		if ( ! $failed_tickets && ! $failed_classes ) { return true; }
		return self::requeue_failed( $failed_tickets, $failed_classes, $retry );
	}

	/* ---- Apple bundle for the web service ---- */

	/**
	 * Builds the .pkpass for a serial. $presented_token: authentication token sent by a device
	 * (null for the signed download link = current generation). Returns null when unauthorised.
	 *
	 * @return array{bundle?:string,tag:int,unavailable?:bool}|null unavailable: code not decryptable (A584).
	 */
	public static function apple_bundle( $serial, $presented_token = null ) {
		$apple = self::provider( 'apple' );
		$pass = Store::pass_by_serial( 'apple', $serial );
		if ( ! $apple || ! $pass ) { return null; }
		$state = Store::state( $pass->ticket_id );
		$ticket = $state ? self::ticket( $pass->ticket_id ) : null;
		if ( ! $ticket ) { return null; }
		$current = null === $presented_token || hash_equals( (string) $state->auth_token_hash, hash( 'sha256', (string) $presented_token ) );
		if ( $current && self::code_unavailable( $ticket ) ) {
			self::log( 'secret_unreadable', 'apple:' . substr( (string) $serial, 0, 8 ) );
			return array( 'unavailable' => true, 'tag' => (int) $state->update_tag );
		}
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
		$bundle = self::in_locale( $ticket, static function () use ( $apple, $ticket, $pass ) { return $apple->update_pass( $ticket, (array) $pass )['bundle']; } );
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
		$credentials = Credentials::apple( false ); // Also an expired certificate (reported below).
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
		$log[] = array( 'time' => time(), 'code' => sanitize_key( $code ), 'message' => \PassCaptain\Text::cut( sanitize_text_field( \PassCaptain\Redact::text( (string) $message ) ), 300 ) );
		update_option( $option, array_slice( $log, -$keep ), false );
	}
}

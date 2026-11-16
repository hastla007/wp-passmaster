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
		add_action( 'wpcap_ticket_code_rotated', array( __CLASS__, 'on_code_rotated' ), 10, 2 );
		// A490: tickets issued outside the ticket mail (exchange) get their passes too.
		add_action( 'wpcap_ticket_code_issued', array( __CLASS__, 'on_code_issued' ), 10, 2 );
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

	public static function fingerprint( array $ticket ) {
		$keys = array( 'status', 'voided', 'starts_at', 'ends_at', 'doors_open_at', 'timezone', 'location', 'event_name', 'ticket_name', 'seat', 'attendee_name', 'code_hash', 'generation', 'occurrence_status', 'event_status' );
		return hash( 'sha256', (string) wp_json_encode( array_intersect_key( $ticket, array_flip( $keys ) ) ) );
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
	public static function order_links( $order ) {
		$out = array();
		foreach ( array( 'apple' => 'wallet/apple/bundle', 'google' => 'wallet/google/save' ) as $key => $route ) {
			if ( ! self::provider( $key ) ) { continue; }
			$count = count( self::order_ticket_ids( $order, $key ) );
			$size = self::batch_size( $key );
			for ( $part = 0; $part * $size < $count; $part++ ) {
				$url = add_query_arg( array( 'o' => (string) $order->public_id, 'p' => $part + 1, 'sig' => self::order_sig( $key, $order ) ), rest_url( WebService::NS . '/' . $route ) );
				$out[ $key ][] = array( $url, $part * $size + 1, min( $count, ( $part + 1 ) * $size ) );
			}
		}
		return $out;
	}

	/** Mail text with the "add all" links of an order ('' without passes). */
	private static function order_links_text( $order ) {
		$text = '';
		foreach ( self::order_links( $order ) as $key => $links ) {
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
				$dir = trailingslashit( get_temp_dir() ) . 'wpcap-' . bin2hex( random_bytes( 16 ) );
				$file = $dir . '/ticket-' . preg_replace( '/[^A-Za-z0-9_-]/', '', substr( (string) $passes['apple']['serial_number'], 0, 12 ) ) . '.pkpass';
				if ( @mkdir( $dir, 0700 ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
					self::$temp_files[] = array( $file, $dir );
					if ( 1 === count( self::$temp_files ) ) { register_shutdown_function( array( __CLASS__, 'cleanup_temp_files' ) ); }
				}
				if ( is_dir( $dir ) && false !== file_put_contents( $file, $passes['apple']['bundle'] ) ) {
					@chmod( $file, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
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
			if ( is_file( $file ) ) { @unlink( $file ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
			if ( is_dir( $dir ) ) { @rmdir( $dir ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		}
		self::$temp_files = array();
	}

	/** Filter wpcap_ticket_mail: create passes while the plaintext codes are available. */
	public static function on_ticket_mail( $mail, $order = null, $issued = array() ) {
		if ( ! is_array( $mail ) || ! is_array( $issued ) || ! $issued ) { return $mail; }
		$text = '';
		// A580: large orders get one "add all" link per batch instead of a .pkpass + links per ticket.
		$inline = count( $issued ) <= self::mail_inline_limit() || ! is_object( $order );
		$any = false;
		$used = 0;
		foreach ( (array) ( $mail['attachments'] ?? array() ) as $file ) { $used += is_string( $file ) && is_file( $file ) ? (int) filesize( $file ) : 0; }
		$budget = \PassCaptain\Orders::attachment_budget( $order );
		foreach ( $issued as $entry ) {
			if ( empty( $entry['ticket_id'] ) || empty( $entry['token'] ) ) { continue; }
			try {
				$passes = self::issue( (int) $entry['ticket_id'], (string) $entry['token'], $inline );
			} catch ( \Throwable $e ) {
				self::log( 'issue_failed', $e->getMessage() );
				continue;
			}
			$any = $any || (bool) $passes;
			if ( ! $inline ) { continue; }
			// Within the attachment budget the .pkpass is attached, otherwise linked (A580).
			$allow = $used + strlen( (string) ( $passes['apple']['bundle'] ?? '' ) ) <= $budget;
			list( $part, $files ) = self::mail_parts( $passes, array( $entry['event'] ?? '', $entry['ticket'] ?? '' ), $allow );
			foreach ( $files as $file ) { $used += (int) filesize( $file ); }
			$text .= $part;
			$mail['attachments'] = array_merge( (array) ( $mail['attachments'] ?? array() ), $files );
		}
		if ( ! $inline && $any ) { $text = self::order_links_text( $order ); }
		if ( '' !== $text ) { $mail['body'] = (string) $mail['body'] . "\n" . __( 'Wallet', 'pass-captain' ) . "\n" . $text; }
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
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT s.ticket_id FROM {$p}wpcap_wallet_state s INNER JOIN {$p}wpcap_tickets t ON t.ticket_id=s.ticket_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=%d", absint( $occurrence_id ) ) );
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
		self::sync_many( $wpdb->get_col( $wpdb->prepare( "SELECT s.ticket_id FROM {$p}wpcap_wallet_state s INNER JOIN {$p}wpcap_tickets t ON t.ticket_id=s.ticket_id WHERE t.order_id=%d", absint( $order_id ) ) ) );
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
		if ( self::JOB !== $type ) { return $handled; }
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
		foreach ( $ticket_ids as $ticket_id ) {
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

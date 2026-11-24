<?php
/**
 * Buyer self-service in the order portal (A118).
 *
 * - Ticket PDF download (one ticket or all tickets of the order) through a POST form in the portal.
 * - Cancellation/refund requests: the buyer selects tickets and gives an optional reason; this creates a
 *   refund in status pending_approval (Refunds::request with require_approval, source "portal") that an
 *   approver decides on under "Erstattungen zur Freigabe". Allowed until the configured number of hours
 *   before the start (setting portal_refund_request_hours, 0 = off), only for valid, unused tickets of
 *   paid orders, never for comps (free tickets) or WooCommerce orders.
 * - "Send me my order link" on the ticket page (new access link to the buyer's address).
 *
 * Authorisation is exactly the portal's: order public id + access token (capability link from the order
 * mail). The PDF uses the stored current code (filter wpcap_ticket_known_code: core TicketCodes,
 * A586, or the wallet module), so repeated downloads keep the code. Only a ticket whose code is not
 * stored (issued before schema 3.10.17, storage switched off) gets a new code once; the previous
 * code/link of that ticket becomes invalid (the portal says so before the download).
 */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class PortalSelfService {
	const SOURCE = 'portal';
	/** Upper bound of tickets per request (form input). */
	const MAX_TICKETS = 50;

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		// Before OrderPortal::maybe_render (priority 10), which would render the page for the same POST.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_download' ), 5 );
		add_action( 'wpcap_refund_rejected', array( __CLASS__, 'on_refund_rejected' ), 10, 2 );
	}

	/** wpcap_refund_rejected: tells the buyer that a portal request was not accepted (tickets stay valid). */
	public static function on_refund_rejected( $refund_id, $order_id = 0 ) {
		$refund = Refunds::get( $refund_id );
		if ( ! $refund || self::SOURCE !== (string) ( $refund['source'] ?? '' ) ) { return false; }
		$order = Orders::get( (int) $refund['order_id'] );
		if ( ! $order || ! is_email( (string) $order->customer_email ) ) { return false; }
		Audit::record( 'refund_request_rejected_notified', 'order', (int) $order->order_id, array( 'refund_id' => (int) $refund_id ) );
		return Mail\Mailer::send( 'refund_request_rejected', (string) $order->customer_email, array(
			'order_id' => Frontend\Format::order_reference( (string) $order->public_id ),
			'ticket_count' => count( (array) json_decode( (string) $refund['ticket_ids'], true ) ),
		), array(), (string) ( $order->locale ?? '' ) );
	}

	public static function register_routes() {
		register_rest_route( 'pass-captain/v1', '/orders/(?P<public_id>[a-f0-9]{32})/refund-request', array(
			'methods' => 'POST',
			'callback' => array( __CLASS__, 'rest_refund_request' ),
			'permission_callback' => '__return_true', // Capability link: access token and nonce are checked in the callback.
		) );
	}

	/* ------------------------------------------------------------------ Access */

	/** Order row when the public id and the access token match (null otherwise) – the portal's check. */
	public static function order_with_access( $public_id, $token ) {
		global $wpdb;
		$public_id = strtolower( preg_replace( '/[^a-f0-9]/i', '', (string) $public_id ) );
		$token = strtolower( preg_replace( '/[^a-f0-9]/i', '', (string) $token ) );
		if ( 32 !== strlen( $public_id ) || 64 !== strlen( $token ) ) { return null; }
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_orders WHERE public_id=%s", $public_id ) );
		if ( ! \PassCaptain\Orders::access_matches( $order, $token ) ) { return null; }
		return $order;
	}

	private static function denied() {
		return new \WP_Error( 'wpcap_order_access_denied', __( 'Der Bestellzugriff ist ungültig.', 'pass-captain' ), array( 'status' => 403 ) );
	}

	private static function limited() {
		return new \WP_Error( 'wpcap_rate_limited', __( 'Zu viele Anfragen. Bitte warte kurz und versuche es erneut.', 'pass-captain' ), array( 'status' => 429 ) );
	}

	/** Nonce action of the portal forms of an order. */
	public static function nonce_action( $kind, $public_id ) {
		return 'wpcap_portal_' . sanitize_key( $kind ) . '_' . strtolower( preg_replace( '/[^a-f0-9]/i', '', (string) $public_id ) );
	}

	/**
	 * Form proof of the cancellation request (A459): keyed hash of order and access token, independent
	 * of the WordPress user and without expiry. A WordPress nonce cannot be used here: the portal script
	 * posts to the REST API without X-WP-Nonce, so REST treats the request as logged out and a nonce
	 * rendered for a logged-in buyer never verifies; it would also expire on an open or cached page.
	 * The capability is the access token itself (checked again in request_refund()).
	 */
	public static function refund_form_sig( $public_id, $token ) {
		$public_id = strtolower( preg_replace( '/[^a-f0-9]/i', '', (string) $public_id ) );
		$token = strtolower( preg_replace( '/[^a-f0-9]/i', '', (string) $token ) );
		return hash_hmac( 'sha256', 'wpcap-portal-refund|' . $public_id . '|' . $token, function_exists( 'wp_salt' ) ? wp_salt( 'nonce' ) : ABSPATH );
	}

	/** Whether $proof (form field `nonce`) belongs to this order link; WordPress nonces of older pages still count. */
	public static function verify_refund_proof( $proof, $public_id, $token ) {
		$proof = (string) $proof;
		if ( '' === $proof ) { return false; }
		if ( 64 === strlen( $proof ) && hash_equals( self::refund_form_sig( $public_id, $token ), strtolower( $proof ) ) ) { return true; }
		return (bool) wp_verify_nonce( $proof, self::nonce_action( 'refund', $public_id ) );
	}

	/** Portal URL of an order (capability link). */
	public static function portal_url( $public_id, $token ) {
		return add_query_arg( array( 'wpcap_order' => (string) $public_id, 'wpcap_access' => (string) $token ), home_url( '/' ) );
	}

	/* ------------------------------------------------------------------ Tickets */

	/** Tickets of an order with the columns the self-service needs (no codes). */
	public static function tickets( $order ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT t.ticket_id,t.public_id,t.status,t.refunded,t.transfer_count,t.attendee_email,t.ticket_type_id,tt.name AS ticket_name,e.name AS event_name,o.occurrence_id,o.starts_at,o.timezone,o.location,COALESCE(ta.attendee_name,'') AS attendee_name FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id LEFT JOIN {$p}wpcap_ticket_assignments ta ON ta.ticket_id=t.ticket_id WHERE t.order_id=%d ORDER BY t.ticket_id",
			(int) $order->order_id
		) );
	}

	/**
	 * PDF state of a ticket: available, held_elsewhere (passed on, or its current code was mailed to another
	 * address – its holder has the code; TicketCodes::held_elsewhere(), A581), used (checked in), void
	 * (revoked, refunded, cancelled) or unpaid.
	 */
	public static function pdf_state( $ticket, $order ) {
		if ( 'paid' !== (string) $order->payment_status ) { return 'unpaid'; }
		if ( (int) $ticket->refunded || ! in_array( (string) $ticket->status, array( 'valid', 'checked_in' ), true ) ) { return 'void'; }
		if ( 'checked_in' === $ticket->status ) { return 'used'; }
		// A581: only when someone else actually received the current code (transfer, assignment or
		// resend to the attendee address) – an attendee address from the checkout alone does not count.
		if ( TicketCodes::held_elsewhere( $ticket, $order ) ) { return 'held_elsewhere'; }
		return 'available';
	}

	/** Buyer-facing note for a ticket that cannot be downloaded ('' for available tickets). */
	public static function pdf_state_note( $state ) {
		switch ( $state ) {
			case 'void':
				return __( 'Dieses Ticket ist storniert bzw. erstattet und nicht mehr gültig.', 'pass-captain' );
			case 'used':
				return __( 'Dieses Ticket wurde bereits zum Einlass verwendet.', 'pass-captain' );
			case 'held_elsewhere':
				return __( 'Dieses Ticket wurde an eine andere Person übertragen bzw. zugewiesen; nur sie hat den Ticketcode.', 'pass-captain' );
			case 'unpaid':
				return __( 'Tickets gibt es erst nach bestätigter Zahlung.', 'pass-captain' );
		}
		return '';
	}

	/**
	 * Current plaintext code of a ticket if it is stored (core TicketCodes, A586, or the wallet
	 * module) – '' when unknown. Never issues a new code.
	 */
	public static function current_code( $ticket_id ) {
		global $wpdb;
		$hash = (string) $wpdb->get_var( $wpdb->prepare( "SELECT token_hash FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_id=%d", absint( $ticket_id ) ) );
		return '' === $hash ? '' : self::known_code( (int) $ticket_id, $hash );
	}

	/** Plaintext code a module still knows for the ticket's current code ('' = unknown). */
	private static function known_code( $ticket_id, $token_hash ) {
		$code = strtolower( (string) apply_filters( 'wpcap_ticket_known_code', '', (int) $ticket_id ) );
		return 64 === strlen( $code ) && ctype_xdigit( $code ) && hash_equals( (string) $token_hash, hash( 'sha256', $code ) ) ? $code : '';
	}

	/** Whether downloading these tickets issues new codes (the portal warns before the download). */
	public static function pdf_needs_new_codes( array $tickets ) {
		global $wpdb;
		foreach ( $tickets as $ticket ) {
			$hash = (string) $wpdb->get_var( $wpdb->prepare( "SELECT token_hash FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_id=%d", (int) $ticket->ticket_id ) );
			if ( '' === self::known_code( (int) $ticket->ticket_id, $hash ) ) { return true; }
		}
		return false;
	}

	/**
	 * Ticket PDF for the portal.
	 *
	 * @param string $public_id Order public id.
	 * @param string $token     Order access token.
	 * @param string $which     'all' (first part), 'all-N' (part N, A627) or a ticket public id of this order.
	 * @return array|\WP_Error filename, content (PDF bytes), tickets, new_codes.
	 */
	public static function pdf( $public_id, $token, $which = 'all' ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order = self::order_with_access( $public_id, $token );
		if ( ! $order ) { return self::denied(); }
		if ( ! RateLimit::allow( 'portal-pdf-client|' . RateLimit::client(), 60, HOUR_IN_SECONDS ) || ! RateLimit::allow( 'portal-pdf|' . $order->order_id, (int) apply_filters( 'wpcap_portal_pdf_rate_limit', RateLimit::configured( 'rate_portal_pdf_per_hour' ), $order ), HOUR_IN_SECONDS ) ) {
			return self::limited();
		}
		if ( 'paid' !== $order->payment_status ) {
			return new \WP_Error( 'wpcap_order_not_paid', self::pdf_state_note( 'unpaid' ), array( 'status' => 409 ) );
		}
		$which = sanitize_key( (string) $which );
		// A627: "all" is split into parts of pdf_max_tickets() tickets ("all-2" = second part).
		$part = 0;
		if ( preg_match( '/^all(?:-(\d{1,4}))?$/', $which, $m ) ) {
			$part = max( 1, (int) ( $m[1] ?? 1 ) );
			$which = 'all';
		}
		$selected = array();
		foreach ( self::tickets( $order ) as $ticket ) {
			if ( 'all' === $which ) {
				if ( 'available' === self::pdf_state( $ticket, $order ) ) { $selected[] = $ticket; }
			} elseif ( hash_equals( (string) $ticket->public_id, $which ) ) {
				$state = self::pdf_state( $ticket, $order );
				if ( 'available' !== $state ) { return new \WP_Error( 'wpcap_ticket_pdf_' . $state, self::pdf_state_note( $state ), array( 'status' => 409 ) ); }
				$selected[] = $ticket;
			}
		}
		if ( 'all' === $which ) {
			$selected = array_slice( $selected, ( $part - 1 ) * self::pdf_max_tickets(), self::pdf_max_tickets() );
		}
		if ( ! $selected ) {
			return 'all' === $which
				? new \WP_Error( 'wpcap_ticket_pdf_none', __( 'Für diese Bestellung gibt es keine Tickets, die du herunterladen kannst.', 'pass-captain' ), array( 'status' => 409 ) )
				: new \WP_Error( 'wpcap_ticket_missing', __( 'Ticket nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) );
		}

		// Codes: serialised per order like resend_tickets(); either every needed code is replaced or none.
		$wpdb->query( 'START TRANSACTION' );
		$locked = $wpdb->get_row( $wpdb->prepare( "SELECT order_id,payment_status FROM {$p}wpcap_orders WHERE order_id=%d FOR UPDATE", (int) $order->order_id ) );
		if ( ! $locked || 'paid' !== $locked->payment_status ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_order_not_paid', self::pdf_state_note( 'unpaid' ), array( 'status' => 409 ) );
		}
		$codes = array();
		$rotated = array();
		$locked_reason = '';
		foreach ( $selected as $i => $ticket ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT ticket_id,status,refunded,token_hash FROM {$p}wpcap_tickets WHERE ticket_id=%d AND order_id=%d FOR UPDATE", (int) $ticket->ticket_id, (int) $order->order_id ) );
			if ( ! $row || 'valid' !== $row->status || (int) $row->refunded ) {
				$wpdb->query( 'ROLLBACK' );
				return new \WP_Error( 'wpcap_ticket_pdf_void', self::pdf_state_note( 'void' ), array( 'status' => 409 ) );
			}
			$code = self::known_code( (int) $row->ticket_id, (string) $row->token_hash );
			if ( '' === $code ) {
				// A593: no new code once admission has begun or the code is on offline scanners ("all"
				// leaves such tickets out of the PDF).
				$locked = \PassCaptain\Admission\CodeRotationLock::reason( (int) $row->ticket_id );
				if ( '' !== $locked ) {
					$locked_reason = $locked;
					unset( $selected[ $i ] );
					continue;
				}
				$code = bin2hex( random_bytes( 32 ) );
				$updated = $wpdb->update( "{$p}wpcap_tickets", array( 'token_hash' => hash( 'sha256', $code ) ), array( 'ticket_id' => (int) $row->ticket_id, 'status' => 'valid', 'token_hash' => (string) $row->token_hash ), array( '%s' ), array( '%d', '%s', '%s' ) );
				if ( 1 !== (int) $updated ) {
					$wpdb->query( 'ROLLBACK' );
					return new \WP_Error( 'wpcap_ticket_rotation_failed', __( 'Ticketcode konnte nicht erneuert werden.', 'pass-captain' ), array( 'status' => 503, 'retryable' => true ) );
				}
				\PassCaptain\CodeHistory::replaced( (int) $row->ticket_id, (string) $row->token_hash, 'portal_pdf' ); // A543
				$rotated[ (int) $row->ticket_id ] = $code;
			}
			$codes[ (int) $row->ticket_id ] = $code;
		}
		if ( ! $selected ) {
			$wpdb->query( 'ROLLBACK' );
			return \PassCaptain\Admission\CodeRotationLock::error( $locked_reason );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_ticket_rotation_failed', __( 'Ticketcode konnte nicht erneuert werden.', 'pass-captain' ), array( 'status' => 503, 'retryable' => true ) );
		}
		foreach ( $rotated as $ticket_id => $code ) {
			/** A ticket received a new code; the old one is invalid. $code is plaintext – never store it. */
			do_action( 'wpcap_ticket_code_rotated', $ticket_id, $code, 'portal_pdf' );
		}
		$entries = array();
		foreach ( $selected as $ticket ) {
			$code = $codes[ (int) $ticket->ticket_id ];
			$entries[] = array(
				'event' => (string) $ticket->event_name,
				'ticket' => (string) $ticket->ticket_name,
				'when' => Orders::format_start( $ticket->starts_at, $ticket->timezone ),
				'location' => (string) $ticket->location,
				'seat' => (string) apply_filters( 'wpcap_ticket_seat_label', '', (int) $ticket->ticket_id, (array) $ticket ),
				'attendee' => (string) $ticket->attendee_name,
				'token' => $code,
				'occurrence_id' => (int) $ticket->occurrence_id,
				'ticket_type_id' => (int) $ticket->ticket_type_id,
				'starts_at' => (string) $ticket->starts_at,
				'timezone' => (string) $ticket->timezone,
				'help_code' => HelpCode::for_ticket( (int) $ticket->ticket_id, $code ),
				'ticket_id' => (int) $ticket->ticket_id,
			);
		}
		$content = TicketPdf::render( $entries, array( 'title' => get_bloginfo( 'name' ), 'footer' => \PassCaptain\TicketPdf::default_footer() ) );
		Audit::record( 'portal_ticket_pdf', 'order', (int) $order->order_id, array( 'tickets' => count( $entries ), 'new_codes' => count( $rotated ), 'scope' => 'all' === $which ? 'all' : 'single' ) );
		$reference = preg_replace( '/[^A-Za-z0-9\-]/', '', Frontend\Format::order_reference( $order->public_id ) );
		return array(
			'filename' => ( 'all' === $which ? 'tickets-' : 'ticket-' ) . $reference . ( 'all' === $which ? ( $part > 1 ? '-' . $part : '' ) : '-' . substr( $which, 0, 8 ) ) . '.pdf',
			'content' => $content,
			'tickets' => count( $entries ),
			'new_codes' => count( $rotated ),
		);
	}

	/** template_redirect: streams the PDF of a portal download form (POST). */
	public static function maybe_download() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified below.
		if ( \PassCaptain\Http::is_post() && isset( $_POST['wpcap_portal_ics'] ) ) { self::maybe_download_ics(); return; }
		if ( ! \PassCaptain\Http::is_post() || ! isset( $_POST['wpcap_portal_pdf'] ) ) { return; }
		$public_id = sanitize_text_field( wp_unslash( (string) ( $_POST['wpcap_portal_order'] ?? '' ) ) );
		$token = sanitize_text_field( wp_unslash( (string) ( $_POST['wpcap_portal_access'] ?? '' ) ) );
		$which = sanitize_key( wp_unslash( (string) $_POST['wpcap_portal_pdf'] ) );
		$nonce = sanitize_text_field( wp_unslash( (string) ( $_POST['_wpnonce'] ?? '' ) ) );
		// phpcs:enable
		if ( ! wp_verify_nonce( $nonce, self::nonce_action( 'pdf', $public_id ) ) ) {
			wp_die( esc_html__( 'Das Formular ist abgelaufen. Bitte lade die Bestellseite neu.', 'pass-captain' ), '', array( 'response' => 403, 'back_link' => true ) );
		}
		$result = self::pdf( $public_id, $token, $which );
		if ( is_wp_error( $result ) ) {
			$data = (array) $result->get_error_data();
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => (int) ( $data['status'] ?? 400 ), 'back_link' => true ) );
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $result['filename'] . '"' );
		header( 'Content-Length: ' . strlen( $result['content'] ) );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: no-referrer' );
		echo $result['content']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF.
		\PassCaptain\Http::end();
	}

	/* ------------------------------------------------------------------ Calendar (.ics) */

	/**
	 * Occurrences of the order's usable tickets (valid or checked in, not refunded) as calendar rows of
	 * public events (Calendar\Ics::published_occurrence), in start order; only dates that are not over
	 * unless $all.
	 *
	 * @return object[]
	 */
	public static function calendar_rows( $order, $all = false ) {
		global $wpdb;
		$p = $wpdb->prefix;
		// Without $all: dates whose end (or start) is not over; with $all the cut-off lies far in the past.
		$since = $all ? '1970-01-01 00:00:00' : \PassCaptain\Clock::mysql();
		// GROUP BY instead of DISTINCT: MySQL refuses ORDER BY on a column outside a DISTINCT select list.
		$ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT tt.occurrence_id FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE t.order_id=%d AND t.status IN ('valid','checked_in') AND t.refunded=0 AND COALESCE(o.ends_at,o.starts_at)>=%s GROUP BY tt.occurrence_id ORDER BY MIN(o.starts_at) LIMIT 100", (int) $order->order_id, $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from $wpdb->prefix.
		$rows = array();
		foreach ( $ids as $occurrence_id ) {
			$row = Calendar\Ics::published_occurrence( (int) $occurrence_id );
			if ( $row ) { $rows[] = $row; }
		}
		return $rows;
	}

	/**
	 * Calendar file with the dates of the order (portal download).
	 *
	 * @return array|\WP_Error filename, content.
	 */
	public static function ics( $public_id, $token ) {
		$order = self::order_with_access( $public_id, $token );
		if ( ! $order ) { return self::denied(); }
		if ( ! RateLimit::allow( 'portal-ics-client|' . RateLimit::client(), 60, HOUR_IN_SECONDS ) ) { return self::limited(); }
		if ( 'paid' !== (string) $order->payment_status ) { return new \WP_Error( 'wpcap_order_not_paid', self::pdf_state_note( 'unpaid' ), array( 'status' => 409 ) ); }
		$rows = self::calendar_rows( $order );
		if ( ! $rows ) { return new \WP_Error( 'wpcap_portal_ics_none', __( 'Für diese Bestellung gibt es keine anstehenden Termine.', 'pass-captain' ), array( 'status' => 409 ) ); }
		return array(
			'filename' => 'termine-' . Frontend\Format::order_reference( (string) $order->public_id ) . '.ics',
			'content' => Calendar\Ics::build( array_map( array( Calendar\Ics::class, 'entry_from_row' ), $rows ), array( 'organizer' => Calendar\Ics::organizer() ) ),
		);
	}

	/** Streams the calendar file of a portal form (POST, nonce of the portal's download forms). */
	private static function maybe_download_ics() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified below.
		$public_id = sanitize_text_field( wp_unslash( (string) ( $_POST['wpcap_portal_order'] ?? '' ) ) );
		$token = sanitize_text_field( wp_unslash( (string) ( $_POST['wpcap_portal_access'] ?? '' ) ) );
		$nonce = sanitize_text_field( wp_unslash( (string) ( $_POST['_wpnonce'] ?? '' ) ) );
		// phpcs:enable
		if ( ! wp_verify_nonce( $nonce, self::nonce_action( 'pdf', $public_id ) ) ) {
			wp_die( esc_html__( 'Das Formular ist abgelaufen. Bitte lade die Bestellseite neu.', 'pass-captain' ), '', array( 'response' => 403, 'back_link' => true ) );
		}
		$result = self::ics( $public_id, $token );
		if ( is_wp_error( $result ) ) {
			$data = (array) $result->get_error_data();
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => (int) ( $data['status'] ?? 400 ), 'back_link' => true ) );
		}
		nocache_headers();
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $result['filename'] . '"' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: no-referrer' );
		echo $result['content']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text/calendar body, values escaped per RFC 5545.
		\PassCaptain\Http::end();
	}

	/** Portal card with the calendar download ('' without upcoming dates or before payment). */
	public static function calendar_html( $order, $token ) {
		if ( 'paid' !== (string) $order->payment_status ) { return ''; }
		$rows = self::calendar_rows( $order );
		if ( ! $rows ) { return ''; }
		$html = '<section class="card" aria-labelledby="wpcap-ics-title"><h2 id="wpcap-ics-title">' . esc_html__( 'Kalender', 'pass-captain' ) . '</h2>';
		$html .= '<form method="post" action="' . esc_url( home_url( '/' ) ) . '" class="wpcap-ics-form">';
		$html .= '<input type="hidden" name="wpcap_portal_ics" value="all">';
		$html .= '<input type="hidden" name="wpcap_portal_order" value="' . esc_attr( (string) $order->public_id ) . '">';
		$html .= '<input type="hidden" name="wpcap_portal_access" value="' . esc_attr( (string) $token ) . '">';
		$html .= '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( self::nonce_action( 'pdf', (string) $order->public_id ) ) ) . '">';
		$label = sprintf( /* translators: %d: number of dates */ _n( '%d Termin in den Kalender übernehmen (.ics)', '%d Termine in den Kalender übernehmen (.ics)', count( $rows ), 'pass-captain' ), count( $rows ) );
		$html .= '<button type="submit">' . esc_html( $label ) . '</button></form>';
		$html .= '<p class="muted"><small>' . esc_html__( 'Die Datei enthält nur Datum, Ort und Event, keine Ticketcodes. Ändert sich ein Termin, informieren wir dich per E-Mail.', 'pass-captain' ) . '</small></p></section>';
		return $html;
	}

	/* ------------------------------------------------------------------ Refund requests */

	/** Hours before the start until which a request is possible; 0 = feature off. */
	public static function refund_hours() {
		return max( 0, (int) Settings::get( 'portal_refund_request_hours' ) );
	}

	/** Why the order takes no self-service refund requests ('' = it does). */
	public static function refund_order_block( $order ) {
		if ( ! self::refund_hours() ) { return 'disabled'; }
		if ( 'paid' !== (string) $order->payment_status ) { return 'unpaid'; }
		if ( 'woocommerce' === (string) $order->payment_provider ) { return 'woocommerce'; }
		if ( (int) $order->total_minor <= 0 || in_array( (string) $order->payment_provider, Reports\Data::comp_providers(), true ) ) { return 'comp'; }
		if ( (int) $order->refunded_minor >= (int) $order->total_minor ) { return 'refunded'; }
		/**
		 * Whether the buyer of this order may request a cancellation in the portal (after the core rules).
		 *
		 * @param bool   $allowed Default true.
		 * @param object $order   Order row.
		 */
		return apply_filters( 'wpcap_portal_refund_request_allowed', true, $order ) ? '' : 'not_allowed';
	}

	/** Ticket ids bound by refunds of the order that are not finished (pending, approved, processing). */
	public static function open_refund_ticket_ids( $order_id ) {
		$ids = array();
		foreach ( Refunds::for_order( $order_id ) as $refund ) {
			if ( in_array( $refund['status'], array( 'pending_approval', 'approved', 'processing' ), true ) ) {
				$ids = array_merge( $ids, array_map( 'absint', (array) json_decode( (string) $refund['ticket_ids'], true ) ) );
			}
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/** Why a ticket cannot be requested for cancellation ('' = it can). */
	public static function refund_ticket_block( $ticket, $order, array $open_ids ) {
		if ( (int) $ticket->refunded ) { return 'refunded'; }
		if ( in_array( (int) $ticket->ticket_id, $open_ids, true ) ) { return 'requested'; }
		if ( 'valid' !== (string) $ticket->status ) { return 'not_valid'; }
		if ( (int) $ticket->transfer_count > 0 ) { return 'transferred'; }
		if ( strtotime( $ticket->starts_at . ' UTC' ) - self::refund_hours() * HOUR_IN_SECONDS <= \PassCaptain\Clock::now() ) { return 'too_late'; }
		if ( Refunds::gross_for_tickets( $order, array( (int) $ticket->ticket_id ) ) <= 0 ) { return 'comp'; }
		return '';
	}

	/** Buyer-facing message for a block reason. */
	public static function refund_block_message( $reason ) {
		switch ( $reason ) {
			case 'disabled':
			case 'not_allowed':
				return __( 'Für diese Bestellung ist keine Stornierungsanfrage im Bestellportal möglich. Bitte wende dich an den Veranstalter.', 'pass-captain' );
			case 'unpaid':
				return __( 'Eine Stornierung kann erst nach bestätigter Zahlung angefragt werden.', 'pass-captain' );
			case 'woocommerce':
				return __( 'Diese Bestellung wurde im Shop abgewickelt. Bitte wende dich für eine Stornierung an den Shop.', 'pass-captain' );
			case 'comp':
				return __( 'Für Freikarten ist keine Erstattung möglich.', 'pass-captain' );
			case 'refunded':
				return __( 'Dieses Ticket wurde bereits erstattet.', 'pass-captain' );
			case 'requested':
				return __( 'Für dieses Ticket liegt bereits eine Stornierungsanfrage vor.', 'pass-captain' );
			case 'not_valid':
				return __( 'Nur gültige, noch nicht verwendete Tickets können storniert werden.', 'pass-captain' );
			case 'transferred':
				return __( 'Übertragene Tickets können nicht über das Bestellportal storniert werden.', 'pass-captain' );
			case 'too_late':
				/* translators: %d: hours */
				return sprintf( _n( 'Stornierungen können nur bis %d Stunde vor Beginn angefragt werden.', 'Stornierungen können nur bis %d Stunden vor Beginn angefragt werden.', self::refund_hours(), 'pass-captain' ), self::refund_hours() );
		}
		return __( 'Die Stornierung ist für dieses Ticket nicht möglich.', 'pass-captain' );
	}

	/** Buyer-facing status of a refund (request). */
	public static function refund_status_label( $status ) {
		switch ( $status ) {
			case 'pending_approval':
				return __( 'Angefragt – wird geprüft', 'pass-captain' );
			case 'approved':
			case 'processing':
			case 'failed':
				return __( 'Angenommen – Erstattung in Bearbeitung', 'pass-captain' );
			case 'completed':
				return __( 'Erstattet', 'pass-captain' );
			case 'rejected':
				return __( 'Abgelehnt', 'pass-captain' );
		}
		return Admin\Forms::status_label( $status );
	}

	/** Customer-safe view of a refund row (no internal notes, provider data or user ids). */
	public static function public_refund( array $row ) {
		return array(
			'status' => (string) $row['status'],
			'status_label' => self::refund_status_label( (string) $row['status'] ),
			'amount_minor' => (int) $row['amount_minor'],
			'currency' => (string) $row['currency'],
			'amount' => Frontend\Format::money( (int) $row['amount_minor'], (string) $row['currency'] ),
			'ticket_count' => count( (array) json_decode( (string) $row['ticket_ids'], true ) ),
			'created_at' => (string) $row['created_at'],
		);
	}

	/**
	 * Cancellation/refund request of the buyer.
	 *
	 * @param string $public_id Order public id.
	 * @param string $token     Order access token.
	 * @param array  $in        ticket_public_ids (string[]), reason (string, optional), idempotency_key (string).
	 * @return array|\WP_Error Public refund view (+ reused, message).
	 */
	public static function request_refund( $public_id, $token, array $in ) {
		global $wpdb;
		$order = self::order_with_access( $public_id, $token );
		if ( ! $order ) { return self::denied(); }
		$key = substr( preg_replace( '/[^A-Za-z0-9\-]/', '', Http::str( $in['idempotency_key'] ?? '' ) ), 0, 64 );
		if ( strlen( $key ) < 16 ) {
			return new \WP_Error( 'wpcap_refund_key', __( 'Das Formular ist abgelaufen. Bitte lade die Bestellseite neu.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$refund_key = self::SOURCE . '|' . $key;
		// Double submit: the same form returns the request it already created (no second mail).
		$existing = Refunds::find_by_key( (int) $order->order_id, $refund_key );
		if ( $existing ) {
			return self::public_refund( $existing ) + array( 'reused' => true, 'message' => self::request_message() );
		}
		if ( ! RateLimit::allow( 'portal-refund-client|' . RateLimit::client(), 20, HOUR_IN_SECONDS ) || ! RateLimit::allow( 'portal-refund|' . $order->order_id, (int) apply_filters( 'wpcap_portal_refund_rate_limit', 5, $order ), HOUR_IN_SECONDS ) ) {
			return self::limited();
		}
		$block = self::refund_order_block( $order );
		if ( '' !== $block ) {
			return new \WP_Error( 'wpcap_refund_request_' . $block, self::refund_block_message( $block ), array( 'status' => 409 ) );
		}
		$wanted = array_slice( array_values( array_unique( array_filter( array_map( static function ( $id ) { return strtolower( preg_replace( '/[^a-f0-9]/i', '', Http::str( $id ) ) ); }, (array) ( $in['ticket_public_ids'] ?? array() ) ) ) ) ), 0, self::MAX_TICKETS + 1 );
		if ( ! $wanted ) {
			return new \WP_Error( 'wpcap_refund_tickets', __( 'Bitte wähle mindestens ein Ticket aus.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		if ( count( $wanted ) > self::MAX_TICKETS ) {
			return new \WP_Error( 'wpcap_refund_tickets', __( 'Zu viele Tickets ausgewählt.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$by_public = array();
		foreach ( self::tickets( $order ) as $ticket ) { $by_public[ (string) $ticket->public_id ] = $ticket; }
		$open = self::open_refund_ticket_ids( (int) $order->order_id );
		$ticket_ids = array();
		$lines = array();
		foreach ( $wanted as $public ) {
			// Tickets of other orders look exactly like unknown ones.
			if ( ! isset( $by_public[ $public ] ) ) {
				return new \WP_Error( 'wpcap_ticket_missing', __( 'Ticket nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) );
			}
			$ticket = $by_public[ $public ];
			$why = self::refund_ticket_block( $ticket, $order, $open );
			if ( '' !== $why ) {
				return new \WP_Error( 'wpcap_refund_ticket_' . $why, self::refund_block_message( $why ), array( 'status' => 409 ) );
			}
			$ticket_ids[] = (int) $ticket->ticket_id;
			$lines[] = $ticket->event_name . ' — ' . $ticket->ticket_name . ', ' . Orders::format_start( $ticket->starts_at, $ticket->timezone );
		}
		$reason = trim( preg_replace( '/\s+/', ' ', sanitize_textarea_field( \PassCaptain\Http::str( $in['reason'] ?? '' ) ) ) );
		$reason = \PassCaptain\Text::limit( $reason, 200 );
		$result = Refunds::request( (int) $order->order_id, $ticket_ids, $reason, $refund_key, array( 'system' => true, 'require_approval' => true, 'source' => self::SOURCE ) );
		if ( is_wp_error( $result ) ) { return $result; }
		$refund_id = (int) $result['refund_id'];
		Audit::record( 'refund_requested_by_customer', 'order', (int) $order->order_id, array( 'refund_id' => $refund_id, 'tickets' => count( $ticket_ids ), 'amount_minor' => (int) $result['amount_minor'], 'with_reason' => '' !== $reason ) );
		/**
		 * A buyer requested a cancellation/refund in the order portal (refund in status pending_approval).
		 *
		 * @param int $refund_id Refund id.
		 * @param int $order_id  Order id.
		 */
		do_action( 'wpcap_portal_refund_requested', $refund_id, (int) $order->order_id );
		if ( is_email( (string) $order->customer_email ) ) {
			$amount_minor = (int) $result['amount_minor'];
			$currency = (string) $result['currency'];
			Mail\Mailer::send( 'refund_request_received', (string) $order->customer_email, array(
				'order_id' => Frontend\Format::order_reference( (string) $order->public_id ),
				'ticket_count' => count( $ticket_ids ),
				'ticket_list' => implode( "\n", $lines ),
				'refund_amount' => static function () use ( $amount_minor, $currency ) { return Money::format( $amount_minor, $currency ); },
				'reason' => $reason,
				'portal_link' => Mail\Mailer::link( self::portal_url( (string) $order->public_id, strtolower( (string) $token ) ) ),
			), array(), (string) ( $order->locale ?? '' ) );
		}
		return self::public_refund( $result ) + array( 'message' => self::request_message() );
	}

	private static function request_message() {
		return __( 'Deine Stornierungsanfrage ist eingegangen. Wir prüfen sie und melden uns per E-Mail. Bis dahin bleiben deine Tickets gültig.', 'pass-captain' );
	}

	public static function rest_refund_request( \WP_REST_Request $request ) {
		$params = $request->get_json_params() ?: $request->get_params();
		$params = is_array( $params ) ? $params : array();
		$public_id = (string) $request['public_id'];
		// SEC-7: the portal sends the token in the X-WPCAP-Access-Token header; the body field still works.
		$token = (string) \PassCaptain\Rest\Credentials::token( $request );
		if ( '' === $token ) { $token = (string) ( $params['access_token'] ?? '' ); }
		if ( ! self::verify_refund_proof( (string) ( $params['nonce'] ?? '' ), $public_id, $token ) ) {
			return new \WP_Error( 'wpcap_nonce', __( 'Das Formular ist abgelaufen. Bitte lade die Bestellseite neu.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		$result = self::request_refund( $public_id, $token, $params );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/* ------------------------------------------------------------------ Portal HTML */

	/** Default maximum of tickets in one portal PDF (A627). */
	const PDF_MAX_TICKETS = 50;

	/**
	 * A627: tickets per portal PDF (filter wpcap_portal_pdf_max_tickets, 1–200). Larger orders get one
	 * download per part, so a click never renders (or re-codes) hundreds of tickets at once.
	 */
	public static function pdf_max_tickets() {
		return max( 1, min( 200, (int) apply_filters( 'wpcap_portal_pdf_max_tickets', self::PDF_MAX_TICKETS ) ) );
	}

	/**
	 * Portal card: PDF of all tickets, status of refunds/requests and the cancellation form.
	 *
	 * @param object   $order   Order row (access verified by the caller).
	 * @param string   $token   Verified access token.
	 * @param object[] $tickets Ticket rows (tickets()).
	 */
	public static function portal_html( $order, $token, array $tickets ) {
		$html = '';
		$available = array_values( array_filter( $tickets, static function ( $t ) use ( $order ) { return 'available' === self::pdf_state( $t, $order ); } ) );
		if ( $available ) {
			$html .= '<section class="card" aria-labelledby="wpcap-pdf-title"><h2 id="wpcap-pdf-title">' . esc_html__( 'Tickets als PDF', 'pass-captain' ) . '</h2>';
			$max = self::pdf_max_tickets();
			if ( count( $available ) <= $max ) {
				$html .= self::pdf_form( $order, $token, 'all', sprintf( /* translators: %d: number of tickets */ _n( '%d Ticket als PDF herunterladen', 'Alle %d Tickets als PDF herunterladen', count( $available ), 'pass-captain' ), count( $available ) ), self::pdf_needs_new_codes( $available ) );
			} else {
				// A627: one PDF per part of $max tickets.
				$html .= '<p>' . esc_html( sprintf( /* translators: %d: tickets per PDF */ _n( 'Je PDF höchstens %d Ticket:', 'Je PDF höchstens %d Tickets:', $max, 'pass-captain' ), $max ) ) . '</p>';
				foreach ( array_chunk( $available, $max ) as $index => $chunk ) {
					$first = $index * $max + 1;
					$label = sprintf(
						/* translators: 1: first ticket number, 2: last ticket number, 3: number of tickets */
						__( 'Tickets %1$d–%2$d von %3$d als PDF herunterladen', 'pass-captain' ),
						$first,
						$first + count( $chunk ) - 1,
						count( $available )
					);
					$html .= self::pdf_form( $order, $token, 0 === $index ? 'all' : 'all-' . ( $index + 1 ), $label, self::pdf_needs_new_codes( $chunk ) );
				}
			}
			if ( count( $available ) < count( $tickets ) ) {
				$html .= '<p class="muted"><small>' . esc_html__( 'Stornierte, erstattete, bereits verwendete und an andere Personen übertragene Tickets sind nicht enthalten.', 'pass-captain' ) . '</small></p>';
			}
			$html .= '</section>';
		}
		$html .= self::calendar_html( $order, $token );
		$html .= self::refunds_html( $order );
		$html .= self::refund_form_html( $order, $token, $tickets );
		return $html;
	}

	/** Per-ticket HTML below a ticket card: single PDF download or the reason why there is none. */
	public static function ticket_html( $ticket, $order, $token ) {
		$state = self::pdf_state( $ticket, $order );
		if ( 'available' === $state ) {
			return self::pdf_form( $order, $token, (string) $ticket->public_id, __( 'Dieses Ticket als PDF', 'pass-captain' ), self::pdf_needs_new_codes( array( $ticket ) ) );
		}
		$note = self::pdf_state_note( $state );
		return '' === $note ? '' : '<p class="muted"><small>' . esc_html( $note ) . '</small></p>';
	}

	private static function pdf_form( $order, $token, $which, $label, $new_codes ) {
		$html = '<form method="post" action="' . esc_url( home_url( '/' ) ) . '" class="wpcap-pdf-form"' . ( $new_codes ? ' data-new-codes="1"' : '' ) . '>';
		$html .= '<input type="hidden" name="wpcap_portal_pdf" value="' . esc_attr( $which ) . '">';
		$html .= '<input type="hidden" name="wpcap_portal_order" value="' . esc_attr( (string) $order->public_id ) . '">';
		$html .= '<input type="hidden" name="wpcap_portal_access" value="' . esc_attr( (string) $token ) . '">';
		$html .= '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( self::nonce_action( 'pdf', (string) $order->public_id ) ) ) . '">';
		$html .= '<button type="submit">' . esc_html( $label ) . '</button>';
		if ( $new_codes ) {
			$html .= '<p class="muted"><small>' . esc_html__( 'Beim Herunterladen erhalten die Tickets neue Codes: Bisher gesendete Ticketlinks, QR-Codes und Ausdrucke dieser Tickets werden ungültig. Verwende danach nur noch das PDF.', 'pass-captain' ) . '</small></p>';
		}
		return $html . '</form>';
	}

	/** Status list of the order's refunds and cancellation requests ('' when there are none). */
	private static function refunds_html( $order ) {
		$rows = Refunds::for_order( (int) $order->order_id );
		if ( ! $rows ) { return ''; }
		$html = '<section class="card" aria-labelledby="wpcap-refunds-title"><h2 id="wpcap-refunds-title">' . esc_html__( 'Stornierungen und Erstattungen', 'pass-captain' ) . '</h2><ul class="wpcap-refund-list">';
		foreach ( $rows as $row ) {
			$view = self::public_refund( $row );
			$html .= '<li>' . esc_html( sprintf(
				/* translators: 1: date, 2: number of tickets, 3: amount, 4: status */
				_n( '%1$s: %2$d Ticket, %3$s – %4$s', '%1$s: %2$d Tickets, %3$s – %4$s', max( 1, $view['ticket_count'] ), 'pass-captain' ),
				Frontend\Format::site_datetime( $view['created_at'] ),
				$view['ticket_count'],
				$view['amount'],
				$view['status_label']
			) ) . '</li>';
		}
		return $html . '</ul></section>';
	}

	/** Cancellation request form ('' when the feature is off or nothing can be requested). */
	private static function refund_form_html( $order, $token, array $tickets ) {
		$block = self::refund_order_block( $order );
		if ( 'disabled' === $block || 'unpaid' === $block ) { return ''; }
		$hours = self::refund_hours();
		$html = '<section class="card" aria-labelledby="wpcap-cancel-title"><h2 id="wpcap-cancel-title">' . esc_html__( 'Stornierung anfragen', 'pass-captain' ) . '</h2>';
		if ( '' !== $block ) {
			return $html . '<p class="muted">' . esc_html( self::refund_block_message( $block ) ) . '</p></section>';
		}
		$open = self::open_refund_ticket_ids( (int) $order->order_id );
		$options = '';
		$count = 0;
		foreach ( $tickets as $ticket ) {
			$why = self::refund_ticket_block( $ticket, $order, $open );
			$label = $ticket->event_name . ' — ' . $ticket->ticket_name . ', ' . Frontend\Format::event_start( $ticket->starts_at, $ticket->timezone );
			if ( '' === $why ) {
				++$count;
				$options .= '<label style="display:flex;gap:.5rem;align-items:flex-start"><input type="checkbox" name="ticket_public_ids[]" value="' . esc_attr( (string) $ticket->public_id ) . '"> <span>' . esc_html( $label ) . '</span></label>';
			} elseif ( in_array( $why, array( 'requested', 'too_late', 'transferred', 'comp' ), true ) ) {
				$options .= '<p class="muted"><small>' . esc_html( $label ) . ': ' . esc_html( self::refund_block_message( $why ) ) . '</small></p>';
			}
		}
		if ( ! $count ) {
			return $html . $options . '<p class="muted">' . esc_html__( 'Derzeit kann für keines deiner Tickets eine Stornierung angefragt werden.', 'pass-captain' ) . '</p></section>';
		}
		$html .= '<form class="wpcap-refund-form"><input type="hidden" name="idempotency_key" value="' . esc_attr( str_replace( '-', '', wp_generate_uuid4() ) ) . '"><input type="hidden" name="nonce" value="' . esc_attr( self::refund_form_sig( (string) $order->public_id, (string) $token ) ) . '">';
		$html .= '<fieldset style="border:0;padding:0;margin:0"><legend>' . esc_html__( 'Welche Tickets möchtest du stornieren?', 'pass-captain' ) . '</legend>' . $options . '</fieldset>';
		$html .= '<label>' . esc_html__( 'Grund (optional)', 'pass-captain' ) . '<textarea name="reason" maxlength="200" rows="2"></textarea></label>';
		/* translators: %d: hours */
		$html .= '<p class="muted"><small>' . esc_html( sprintf( _n( 'Anfragen sind bis %d Stunde vor Beginn möglich.', 'Anfragen sind bis %d Stunden vor Beginn möglich.', $hours, 'pass-captain' ), $hours ) ) . ' ';
		$html .= esc_html__( 'Die Anfrage wird vom Veranstalter geprüft; erst nach der Freigabe werden die Tickets ungültig und der Betrag erstattet.', 'pass-captain' );
		// A425 (owner decision 23.11.2026): a kept service fee is named before the request.
		if ( ServiceFee::of_order( $order ) && 'retain' === ServiceFee::buyer_refund_rule() ) {
			$html .= ' ' . esc_html__( 'Die Servicegebühr wird bei einer Stornierung auf deinen Wunsch nicht erstattet.', 'pass-captain' );
		}
		$html .= '</small></p>';
		$html .= '<button type="submit">' . esc_html__( 'Stornierung anfragen', 'pass-captain' ) . '</button><p class="message" role="status" aria-live="polite"></p></form></section>';
		return $html;
	}

	/** Strings of the portal script (confirmations and messages). */
	public static function script_strings() {
		return array(
			'pdfConfirm' => __( 'Beim Herunterladen erhalten die Tickets neue Codes. Bisher gesendete Ticketlinks, QR-Codes und Ausdrucke werden ungültig. Fortfahren?', 'pass-captain' ),
			'pdfConfirmButton' => __( 'Ja, PDF erstellen', 'pass-captain' ),
			'refundConfirm' => __( 'Stornierung der ausgewählten Tickets jetzt anfragen?', 'pass-captain' ),
			'refundConfirmButton' => __( 'Ja, Stornierung anfragen', 'pass-captain' ),
			'refundNone' => __( 'Bitte wähle mindestens ein Ticket aus.', 'pass-captain' ),
			'refundFailed' => __( 'Die Anfrage konnte nicht gesendet werden.', 'pass-captain' ),
		);
	}

	/* ------------------------------------------------------------------ Ticket page */

	/**
	 * Sends a new order access link to the buyer of the ticket with this code (ticket page, POST). The
	 * previous link becomes invalid. Only for the buyer's own tickets (not passed on / assigned to
	 * another address), rate limited per order and client.
	 *
	 * @return string|\WP_Error Masked buyer address.
	 */
	public static function send_order_link( $ticket_token ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$ticket_token = strtolower( preg_replace( '/[^a-f0-9]/i', '', (string) $ticket_token ) );
		if ( 64 !== strlen( $ticket_token ) ) { return new \WP_Error( 'wpcap_ticket_missing', __( 'Ticket nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
		$ticket = $wpdb->get_row( $wpdb->prepare( "SELECT t.ticket_id,t.order_id,t.status,t.refunded,t.transfer_count,t.attendee_email FROM {$p}wpcap_tickets t WHERE t.token_hash=%s LIMIT 1", hash( 'sha256', $ticket_token ) ) );
		$order = $ticket ? Orders::get( (int) $ticket->order_id ) : null;
		if ( ! $ticket || ! $order || ! self::can_request_order_link( $ticket, $order ) ) {
			return new \WP_Error( 'wpcap_order_link_unavailable', __( 'Für dieses Ticket kann kein Bestelllink gesendet werden.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		if ( ! RateLimit::allow( 'ticket-order-link-client|' . RateLimit::client(), 10, HOUR_IN_SECONDS ) || ! RateLimit::allow( 'ticket-order-link|' . (int) $order->order_id, 3, DAY_IN_SECONDS ) ) {
			return self::limited();
		}
		// A631: anyone with the ticket code can ask; the buyer's current link stays until the new one is opened.
		if ( ! Orders::rotate_access_link( (int) $order->order_id, true ) ) {
			return new \WP_Error( 'wpcap_mail_failed', __( 'Die E-Mail konnte nicht gesendet werden. Bitte versuche es später erneut.', 'pass-captain' ), array( 'status' => 503 ) );
		}
		Audit::record( 'order_link_requested', 'order', (int) $order->order_id, array( 'ticket_id' => (int) $ticket->ticket_id, 'source' => 'ticket_page' ) );
		return Frontend\Format::mask_email( (string) $order->customer_email );
	}

	/** Whether the ticket page offers "send me my order link" for this ticket. */
	public static function can_request_order_link( $ticket, $order ) {
		return 'paid' === (string) $order->payment_status
			&& is_email( (string) $order->customer_email ) && ! Mail\Mailer::is_placeholder( (string) $order->customer_email )
			&& ! TicketCodes::held_elsewhere( $ticket, $order ); // A581: the buyer holds this code.
	}
}

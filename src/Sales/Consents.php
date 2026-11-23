<?php
/**
 * Logged consents for participant fields of type "consent" (checkbox with consent text + version).
 *
 * - Checkout: the answer "1" of a consent field is stored like a checkbox answer; when the ticket is
 *   issued (`wpcap_ticket_created`) a consent record is written with the consent text, its version
 *   and SHA-256 hash, the source ("checkout"), the subject e-mail (attendee, else buyer) and the
 *   time of the order (when the box was ticked).
 * - Withdrawal: buyers withdraw in the order portal (POST to the portal link, capability = access
 *   token). The record keeps withdrawn_at/withdrawn_source, the participant answer is deleted.
 *   Consent fields are not editable in the attendee form of the portal (no silent re-consent).
 * - Holder change: when a ticket moves to another person (buyer assignment with a new holder, ticket
 *   transfer), the previous person's active consents end (withdrawn_source "holder_changed", answer
 *   deleted) – they never carry over. The new holder gives consent through the link in the ticket
 *   mail (?wpcap_consent=<ticket code>, source "attendee_link") or, when the buyer holds the ticket,
 *   in the order portal (source "portal").
 * - Privacy: own exporter and eraser (wp_privacy_personal_data_*) and deletion together with the
 *   participant answers after the retention period (Privacy\Retention::HOOK).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Sales;

use PassCaptain\Audit;
use PassCaptain\Privacy\Retention;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Consents {
	const BATCH = 500;

	private static $registered = false;

	public static function register() {
		if ( self::$registered ) { return; }
		self::$registered = true;
		add_action( 'wpcap_ticket_created', array( __CLASS__, 'record_for_ticket' ), 10, 2 );
		add_action( 'wpcap_ticket_code_rotated', array( __CLASS__, 'on_code_rotated' ), 5, 3 );
		add_filter( 'wpcap_ticket_code_mail_section', array( __CLASS__, 'mail_section' ), 20, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render_attendee_page' ) );
		// Before the core eraser (priority 10), which pseudonymises the order e-mail.
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ), 5 );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ), 5 );
		add_action( Retention::HOOK, array( __CLASS__, 'retention' ), 20 );
	}

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wpcap_consents';
	}

	/** Consent fields (active and inactive) of a ticket type, keyed by field id. */
	public static function fields( $ticket_type_id ) {
		global $wpdb;
		$out = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT field_id,field_key,label,required,consent_text,consent_version FROM %i WHERE ticket_type_id=%d AND field_type='consent'", $wpdb->prefix . 'wpcap_participant_fields', absint( $ticket_type_id ) ) ) as $field ) {
			$out[ (int) $field->field_id ] = $field;
		}
		return $out;
	}

	/** Consent text hash (version + text) stored with each record. */
	public static function text_hash( $text, $version ) {
		return hash( 'sha256', (string) $version . "\n" . (string) $text );
	}

	/**
	 * Writes consent records for the consent answers of a newly issued ticket (inside the issuance
	 * transaction, action `wpcap_ticket_created`). Idempotent per ticket and field.
	 */
	public static function record_for_ticket( $ticket_id, $order ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$ticket = $wpdb->get_row( $wpdb->prepare( 'SELECT ticket_id,order_id,ticket_type_id,attendee_email FROM %i WHERE ticket_id=%d', $p . 'wpcap_tickets', absint( $ticket_id ) ) );
		if ( ! $ticket ) { return; }
		$fields = self::fields( (int) $ticket->ticket_type_id );
		if ( ! $fields ) { return; }
		$field_ids = array_map( 'absint', array_keys( $fields ) );
		$answers = $wpdb->get_results( $wpdb->prepare( 'SELECT field_id,answer FROM %i WHERE ticket_id=%d AND field_id IN (' . implode( ',', array_fill( 0, count( $field_ids ), '%d' ) ) . ')', array_merge( array( $p . 'wpcap_participant_answers', (int) $ticket->ticket_id ), $field_ids ) ) );
		foreach ( $answers as $answer ) {
			if ( '1' !== (string) $answer->answer ) { continue; }
			$field = $fields[ (int) $answer->field_id ];
			$subject = '' !== (string) $ticket->attendee_email ? (string) $ticket->attendee_email : (string) ( $order->customer_email ?? '' );
			// Box office sales (payment provider pos_*) log the source "box_office"; the record is otherwise identical.
			$source = str_starts_with( (string) ( $order->payment_provider ?? '' ), 'pos_' ) ? 'box_office' : 'checkout';
			self::grant( (int) $ticket->ticket_id, (int) $ticket->order_id, $field, $subject, $source, (string) ( $order->created_at ?? current_time( 'mysql', true ) ) );
		}
	}

	/* ---------------------------------------------------------------------------------------------
	 * Holder change (assignment to another person, transfer).
	 * ------------------------------------------------------------------------------------------- */

	/** Plain ticket codes of this request whose new holder still has to consent (for the mail link). */
	private static $pending_codes = array();

	/** Current holder of a ticket: attendee e-mail, else the buyer. */
	private static function holder( $ticket_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT t.attendee_email,ord.customer_email FROM %i t INNER JOIN %i ord ON ord.order_id=t.order_id WHERE t.ticket_id=%d', $wpdb->prefix . 'wpcap_tickets', $wpdb->prefix . 'wpcap_orders', absint( $ticket_id ) ) );
		if ( ! $row ) { return ''; }
		return '' !== (string) $row->attendee_email ? (string) $row->attendee_email : (string) $row->customer_email;
	}

	/**
	 * Action wpcap_ticket_code_rotated: on a holder change (assign/transfer) the consents of other
	 * people end; the new code is kept for the consent link in the ticket mail.
	 */
	public static function on_code_rotated( $ticket_id, $code, $reason = '' ) {
		if ( ! in_array( (string) $reason, array( 'assign', 'transfer' ), true ) ) { return; }
		$holder = self::holder( $ticket_id );
		if ( '' === $holder ) { return; }
		self::end_for_holder_change( (int) $ticket_id, $holder );
		if ( self::missing_for_ticket( (int) $ticket_id ) ) { self::$pending_codes[ (int) $ticket_id ] = (string) $code; }
	}

	/**
	 * Ends active consents of a ticket that were given by someone else than $holder (kept as proof,
	 * withdrawn_source "holder_changed"); their participant answers are deleted.
	 *
	 * @return int Number of ended consents.
	 */
	public static function end_for_holder_change( $ticket_id, $holder ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE ticket_id=%d AND withdrawn_at IS NULL AND LOWER(subject_email)<>LOWER(%s)', self::table(), absint( $ticket_id ), (string) $holder ) );
		$ended = 0;
		foreach ( (array) $rows as $consent ) {
			$updated = $wpdb->query( $wpdb->prepare( "UPDATE %i SET withdrawn_at=%s,withdrawn_source='holder_changed' WHERE consent_id=%d AND withdrawn_at IS NULL", self::table(), current_time( 'mysql', true ), (int) $consent->consent_id ) );
			if ( 1 !== (int) $updated ) { continue; }
			++$ended;
			$wpdb->delete( "{$wpdb->prefix}wpcap_participant_answers", array( 'ticket_id' => (int) $consent->ticket_id, 'field_id' => (int) $consent->field_id ) );
			Audit::record( 'consent_ended_holder_change', 'order', (int) $consent->order_id, array( 'consent_id' => (int) $consent->consent_id, 'ticket_id' => (int) $consent->ticket_id, 'field_id' => (int) $consent->field_id, 'version' => $consent->text_version ) );
			do_action( 'wpcap_consent_withdrawn', (int) $consent->consent_id, $consent );
		}
		return $ended;
	}

	/** Active consent fields of the ticket's type without an active consent for the ticket (field_id => field). */
	public static function missing_for_ticket( $ticket_id ) {
		global $wpdb;
		$type_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_type_id FROM %i WHERE ticket_id=%d AND status IN ('valid','checked_in')", $wpdb->prefix . 'wpcap_tickets', absint( $ticket_id ) ) );
		if ( ! $type_id ) { return array(); }
		$fields = $wpdb->get_results( $wpdb->prepare( "SELECT field_id,field_key,label,required,consent_text,consent_version FROM %i WHERE ticket_type_id=%d AND field_type='consent' AND status='active' ORDER BY position,field_id", $wpdb->prefix . 'wpcap_participant_fields', $type_id ) );
		$given = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( 'SELECT field_id FROM %i WHERE ticket_id=%d AND withdrawn_at IS NULL', self::table(), absint( $ticket_id ) ) ) );
		$out = array();
		foreach ( (array) $fields as $field ) {
			if ( ! in_array( (int) $field->field_id, $given, true ) ) { $out[ (int) $field->field_id ] = $field; }
		}
		return $out;
	}

	/**
	 * Records the current holder's consent for fields of a ticket (only fields that are missing).
	 *
	 * @param int[]  $field_ids Fields the holder ticked.
	 * @param string $source    attendee_link|portal.
	 * @return int[] New consent ids.
	 */
	public static function grant_for_holder( $ticket_id, array $field_ids, $source ) {
		global $wpdb;
		$ticket_id = absint( $ticket_id );
		$missing = self::missing_for_ticket( $ticket_id );
		$holder = self::holder( $ticket_id );
		$order_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT order_id FROM %i WHERE ticket_id=%d', $wpdb->prefix . 'wpcap_tickets', $ticket_id ) );
		$now = current_time( 'mysql', true );
		$ids = array();
		foreach ( array_unique( array_map( 'absint', $field_ids ) ) as $field_id ) {
			if ( ! isset( $missing[ $field_id ] ) || '' === $holder ) { continue; }
			$id = self::grant( $ticket_id, $order_id, $missing[ $field_id ], $holder, $source, $now );
			if ( ! $id ) { continue; }
			$wpdb->query( $wpdb->prepare( "INSERT INTO %i (ticket_id,field_id,answer,created_at,updated_at) VALUES (%d,%d,'1',%s,%s) ON DUPLICATE KEY UPDATE answer='1',updated_at=VALUES(updated_at)", $wpdb->prefix . 'wpcap_participant_answers', $ticket_id, $field_id, $now, $now ) );
			Audit::record( 'consent_granted', 'order', $order_id, array( 'consent_id' => $id, 'ticket_id' => $ticket_id, 'field_id' => $field_id, 'version' => (string) $missing[ $field_id ]->consent_version, 'source' => sanitize_key( $source ) ) );
			$ids[] = $id;
		}
		return $ids;
	}

	/** Filter wpcap_ticket_code_mail_section: consent link for a new holder who still has to consent. */
	public static function mail_section( $html, $ticket_id ) {
		$code = self::$pending_codes[ (int) $ticket_id ] ?? '';
		if ( '' === $code || ! self::missing_for_ticket( (int) $ticket_id ) ) { return $html; }
		unset( self::$pending_codes[ (int) $ticket_id ] );
		$url = add_query_arg( 'wpcap_consent', $code, home_url( '/' ) );
		return (string) $html . "\n\n" . __( 'Für dieses Ticket wird deine eigene Einwilligung benötigt (Einwilligungen der vorherigen Person gelten nicht weiter):', 'pass-captain' ) . "\n" . $url;
	}

	/** Ticket of a plain ticket code (the holder's credential), or null. */
	private static function ticket_for_code( $code ) {
		global $wpdb;
		$code = \PassCaptain\Security\Input::hex( $code );
		if ( 64 !== strlen( $code ) ) { return null; }
		return $wpdb->get_row( $wpdb->prepare( 'SELECT t.ticket_id,t.order_id,t.status,tt.name AS ticket_name,e.name AS event_name FROM %i t INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id INNER JOIN %i e ON e.event_id=o.event_id WHERE t.token_hash=%s', $wpdb->prefix . 'wpcap_tickets', $wpdb->prefix . 'wpcap_ticket_types', $wpdb->prefix . 'wpcap_occurrences', $wpdb->prefix . 'wpcap_events', hash( 'sha256', $code ) ) );
	}

	/**
	 * Consent page of the ticket holder (GET shows the open consents, POST records the ticked ones).
	 * The ticket code is the capability (like the ticket page); a rotated code no longer works.
	 *
	 * @param int[]|null $grant Field ids posted (null = only show).
	 * @return string|null HTML, null for an unknown code.
	 */
	public static function attendee_page( $code, $grant = null ) {
		$ticket = self::ticket_for_code( $code );
		if ( ! $ticket || ! in_array( $ticket->status, array( 'valid', 'checked_in' ), true ) ) { return null; }
		$granted = is_array( $grant ) ? self::grant_for_holder( (int) $ticket->ticket_id, $grant, 'attendee_link' ) : array();
		$missing = self::missing_for_ticket( (int) $ticket->ticket_id );
		$html = '<!doctype html><html ' . \PassCaptain\Frontend\Format::html_lang_attributes() . '><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . esc_html__( 'Einwilligungen', 'pass-captain' ) . '</title><style>body{font:16px/1.5 system-ui,sans-serif;max-width:40rem;margin:2rem auto;padding:0 1rem;color:#1d2327}.card{border:1px solid #c3c4c7;border-radius:10px;padding:1rem;margin:1rem 0}button{padding:.55rem 1rem;min-height:2.75rem}:focus-visible{outline:3px solid #135e96;outline-offset:3px}</style></head><body><main>';
		$html .= '<h1>' . esc_html__( 'Einwilligungen', 'pass-captain' ) . '</h1><p>' . esc_html( $ticket->event_name . ' — ' . $ticket->ticket_name ) . '</p>';
		if ( $granted ) { $html .= '<p role="status">' . esc_html__( 'Danke, deine Einwilligung wurde gespeichert.', 'pass-captain' ) . '</p>'; }
		if ( ! $missing ) {
			$html .= '<p>' . esc_html__( 'Für dieses Ticket sind keine Einwilligungen offen.', 'pass-captain' ) . '</p>';
		} else {
			$html .= '<form method="post" action="' . esc_url( add_query_arg( 'wpcap_consent', \PassCaptain\Security\Input::hex( $code ), home_url( '/' ) ) ) . '">';
			foreach ( $missing as $field ) {
				$id = 'wpcap-consent-' . (int) $field->field_id;
				/* translators: %s: consent text version */
				$version = sprintf( __( 'Version %s', 'pass-captain' ), $field->consent_version );
				$html .= '<div class="card"><p id="' . esc_attr( $id ) . '-text">' . nl2br( esc_html( (string) $field->consent_text ) ) . ' <small>' . esc_html( $version ) . '</small></p><p><input type="checkbox" id="' . esc_attr( $id ) . '" name="wpcap_consent_fields[]" value="' . (int) $field->field_id . '" aria-describedby="' . esc_attr( $id ) . '-text"> <label for="' . esc_attr( $id ) . '">' . esc_html( $field->label . ( (int) $field->required ? ' *' : '' ) ) . '</label></p></div>';
			}
			$html .= '<p><button type="submit">' . esc_html__( 'Einwilligung erteilen', 'pass-captain' ) . '</button></p></form>';
		}
		return $html . '</main></body></html>';
	}

	/** template_redirect: ?wpcap_consent=<ticket code>. */
	public static function maybe_render_attendee_page() {
		$code = isset( $_GET['wpcap_consent'] ) ? sanitize_text_field( wp_unslash( $_GET['wpcap_consent'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the ticket code is the capability.
		if ( '' === $code || isset( $_GET['wpcap_order'] ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the order portal uses wpcap_consent as status flag.
		$post = 'POST' === strtoupper( sanitize_key( wp_unslash( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) );
		$fields = $post ? array_map( 'absint', (array) wp_unslash( $_POST['wpcap_consent_fields'] ?? array() ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the ticket code is the capability.
		$html = self::attendee_page( $code, $fields );
		\PassCaptain\Security\PrivatePage::send( true ); // No page cache, no framing, no indexing (secret link); strict CSP (standalone page).
		status_header( null === $html ? 404 : 200 );
		echo null === $html ? esc_html__( 'Der Link ist ungültig.', 'pass-captain' ) : $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		\PassCaptain\Http::end();
	}

	/** Inserts an active consent record unless one exists for ticket and field. @return int consent id */
	public static function grant( $ticket_id, $order_id, $field, $subject_email, $source, $granted_at ) {
		global $wpdb;
		$existing = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT consent_id FROM %i WHERE ticket_id=%d AND field_id=%d AND withdrawn_at IS NULL', self::table(), absint( $ticket_id ), (int) $field->field_id ) );
		if ( $existing ) { return $existing; }
		$text = (string) ( $field->consent_text ?? '' );
		$version = (string) ( $field->consent_version ?? '' );
		$wpdb->insert( self::table(), array(
			'ticket_id' => absint( $ticket_id ),
			'order_id' => absint( $order_id ),
			'field_id' => (int) $field->field_id,
			'subject_email' => mb_substr( (string) $subject_email, 0, 190 ),
			'consent_label' => mb_substr( (string) $field->label, 0, 190 ),
			'consent_text' => $text,
			'text_version' => mb_substr( $version, 0, 32 ),
			'text_hash' => self::text_hash( $text, $version ),
			'source' => sanitize_key( $source ),
			'granted_at' => $granted_at,
		) );
		return (int) $wpdb->insert_id;
	}

	/** Consent records of an order (portal, admin). */
	public static function for_order( $order_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d ORDER BY consent_id', self::table(), absint( $order_id ) ) );
	}

	public static function get( $consent_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE consent_id=%d', self::table(), absint( $consent_id ) ) );
	}

	/**
	 * Withdraws a consent: keeps the record (proof) with withdrawal time and source and deletes the
	 * participant answer of the field.
	 *
	 * @return true|\WP_Error
	 */
	public static function withdraw( $consent_id, $source = 'portal' ) {
		global $wpdb;
		$consent = self::get( $consent_id );
		if ( ! $consent ) { return new \WP_Error( 'wpcap_consent_missing', __( 'Einwilligung nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
		if ( $consent->withdrawn_at ) { return new \WP_Error( 'wpcap_consent_withdrawn', __( 'Diese Einwilligung wurde bereits widerrufen.', 'pass-captain' ), array( 'status' => 409 ) ); }
		$now = current_time( 'mysql', true );
		$updated = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET withdrawn_at=%s,withdrawn_source=%s WHERE consent_id=%d AND withdrawn_at IS NULL', self::table(), $now, sanitize_key( $source ), (int) $consent->consent_id ) );
		if ( 1 !== (int) $updated ) { return new \WP_Error( 'wpcap_consent_withdrawn', __( 'Diese Einwilligung wurde bereits widerrufen.', 'pass-captain' ), array( 'status' => 409 ) ); }
		$wpdb->delete( "{$wpdb->prefix}wpcap_participant_answers", array( 'ticket_id' => (int) $consent->ticket_id, 'field_id' => (int) $consent->field_id ) );
		Audit::record( 'consent_withdrawn', 'order', (int) $consent->order_id, array( 'consent_id' => (int) $consent->consent_id, 'field_id' => (int) $consent->field_id, 'version' => $consent->text_version, 'source' => sanitize_key( $source ) ) );
		do_action( 'wpcap_consent_withdrawn', (int) $consent->consent_id, $consent );
		return true;
	}

	/**
	 * Portal withdrawal: the order access (public id + token) must be valid and the consent must
	 * belong to the order.
	 *
	 * @return true|\WP_Error
	 */
	public static function withdraw_for_order( $order, $consent_id ) {
		$consent = self::get( $consent_id );
		if ( ! $order || ! $consent || (int) $consent->order_id !== (int) $order->order_id ) {
			return new \WP_Error( 'wpcap_consent_missing', __( 'Einwilligung nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) );
		}
		return self::withdraw( (int) $consent->consent_id, 'portal' );
	}

	/**
	 * Handles a withdrawal POST on the order portal page (called after the access token was
	 * verified). Redirects back to the portal (PRG) when a withdrawal was posted.
	 */
	public static function maybe_handle_portal_post( $order, $token ) {
		if ( 'POST' !== strtoupper( sanitize_key( wp_unslash( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ) || ( empty( $_POST['wpcap_consent_withdraw'] ) && empty( $_POST['wpcap_consent_grant'] ) ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the emailed access token is the capability.
		if ( ! empty( $_POST['wpcap_consent_grant'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$granted = self::grant_for_order( $order, absint( wp_unslash( $_POST['wpcap_consent_grant'] ) ), absint( wp_unslash( $_POST['wpcap_consent_field'] ?? 0 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			wp_safe_redirect( add_query_arg( array( 'wpcap_order' => $order->public_id, 'wpcap_access' => $token, 'wpcap_consent' => is_wp_error( $granted ) ? 'error' : 'granted' ), home_url( '/' ) ), 303 );
			\PassCaptain\Http::end();
		}
		$result = self::withdraw_for_order( $order, absint( wp_unslash( $_POST['wpcap_consent_withdraw'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$url = add_query_arg( array( 'wpcap_order' => $order->public_id, 'wpcap_access' => $token, 'wpcap_consent' => is_wp_error( $result ) ? 'error' : 'withdrawn' ), home_url( '/' ) );
		wp_safe_redirect( $url, 303 );
		\PassCaptain\Http::end();
	}

	/**
	 * Portal consent of the buyer for a ticket the buyer holds (no other attendee e-mail): only the
	 * holder may consent, so tickets assigned or transferred to someone else are refused.
	 *
	 * @return int|\WP_Error Consent id.
	 */
	public static function grant_for_order( $order, $ticket_id, $field_id ) {
		global $wpdb;
		$ticket = $order ? $wpdb->get_row( $wpdb->prepare( 'SELECT ticket_id,attendee_email FROM %i WHERE ticket_id=%d AND order_id=%d', $wpdb->prefix . 'wpcap_tickets', absint( $ticket_id ), (int) $order->order_id ) ) : null;
		if ( ! $ticket || 0 !== strcasecmp( self::holder( (int) $ticket->ticket_id ), (string) $order->customer_email ) ) {
			return new \WP_Error( 'wpcap_consent_not_holder', __( 'Nur die Person, der das Ticket gehört, kann hier einwilligen.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		$ids = self::grant_for_holder( (int) $ticket->ticket_id, array( absint( $field_id ) ), 'portal' );
		return $ids ? (int) $ids[0] : new \WP_Error( 'wpcap_consent_missing', __( 'Einwilligung nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) );
	}

	/** Open consents of tickets the buyer holds: [ticket_id => [field_id => field]]. */
	private static function open_for_buyer( $order ) {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM %i WHERE order_id=%d AND status IN ('valid','checked_in') AND (attendee_email='' OR LOWER(attendee_email)=LOWER(%s)) ORDER BY ticket_id LIMIT 100", $wpdb->prefix . 'wpcap_tickets', (int) $order->order_id, (string) $order->customer_email ) ) as $ticket_id ) {
			$missing = self::missing_for_ticket( (int) $ticket_id );
			if ( $missing ) { $out[ (int) $ticket_id ] = $missing; }
		}
		return $out;
	}

	/** Portal section listing the consents of the order with a withdraw button each and open consents of buyer-held tickets ('' without any). */
	public static function portal_section( $order ) {
		$consents = self::for_order( (int) $order->order_id );
		$open = self::open_for_buyer( $order );
		if ( ! $consents && ! $open ) { return ''; }

		$state = sanitize_key( wp_unslash( $_GET['wpcap_consent'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$html = '<section class="card" aria-labelledby="wpcap-consents-title"><h2 id="wpcap-consents-title">' . esc_html__( 'Einwilligungen', 'pass-captain' ) . '</h2>';
		if ( 'withdrawn' === $state ) { $html .= '<p role="status">' . esc_html__( 'Die Einwilligung wurde widerrufen.', 'pass-captain' ) . '</p>'; }
		if ( 'error' === $state ) { $html .= '<p role="alert">' . esc_html__( 'Die Einwilligung konnte nicht widerrufen werden.', 'pass-captain' ) . '</p>'; }
		if ( 'granted' === $state ) { $html .= '<p role="status">' . esc_html__( 'Danke, deine Einwilligung wurde gespeichert.', 'pass-captain' ) . '</p>'; }
		foreach ( $open as $ticket_id => $fields ) {
			foreach ( $fields as $field ) {
				/* translators: %s: consent text version */
				$version = sprintf( __( 'Version %s', 'pass-captain' ), $field->consent_version );
				$html .= '<div class="card"><p><strong>' . esc_html( $field->label ) . '</strong> <small class="muted">' . esc_html( $version ) . '</small></p><p>' . nl2br( esc_html( (string) $field->consent_text ) ) . '</p>';
				$html .= '<form method="post"><input type="hidden" name="wpcap_consent_grant" value="' . (int) $ticket_id . '"><input type="hidden" name="wpcap_consent_field" value="' . (int) $field->field_id . '"><button type="submit">' . esc_html__( 'Einwilligung erteilen', 'pass-captain' ) . '</button></form></div>';
			}
		}
		$html .= '<p class="muted"><small>' . esc_html__( 'Du kannst erteilte Einwilligungen jederzeit mit Wirkung für die Zukunft widerrufen.', 'pass-captain' ) . '</small></p>';

		foreach ( $consents as $consent ) {
			$html .= '<div class="card"><p><strong>' . esc_html( $consent->consent_label ) . '</strong>';
			/* translators: %s: consent text version */
			$html .= ' <small class="muted">' . esc_html( sprintf( __( 'Version %s', 'pass-captain' ), $consent->text_version ) ) . '</small></p>';
			if ( '' !== (string) $consent->consent_text ) { $html .= '<p>' . nl2br( esc_html( $consent->consent_text ) ) . '</p>'; }
			/* translators: %s: date/time */
			$html .= '<p class="muted"><small>' . esc_html( sprintf( __( 'Erteilt am %s', 'pass-captain' ), \PassCaptain\Frontend\Format::site_datetime( $consent->granted_at ) ) ) . '</small></p>';
			if ( $consent->withdrawn_at ) {
				/* translators: %s: date/time */
				$html .= '<p class="status">' . esc_html( sprintf( __( 'Widerrufen am %s', 'pass-captain' ), \PassCaptain\Frontend\Format::site_datetime( $consent->withdrawn_at ) ) ) . '</p>';
			} else {
				$html .= '<form method="post"><input type="hidden" name="wpcap_consent_withdraw" value="' . (int) $consent->consent_id . '"><button type="submit">' . esc_html__( 'Einwilligung widerrufen', 'pass-captain' ) . '</button></form>';
			}
			$html .= '</div>';
		}
		return $html . '</section>';
	}

	/**
	 * Portal attendee form: consent fields are not part of it. Fills the stored state so required
	 * consent fields pass validation; the values are removed again by without_consent_answers().
	 */
	public static function portal_answers( $ticket_type_id, $answers ) {
		if ( ! is_array( $answers ) ) { return $answers; }
		foreach ( self::fields( $ticket_type_id ) as $field ) { $answers[ $field->field_key ] = '1'; }
		return $answers;
	}

	/** Removes consent field answers (by field id) from validated answers. */
	public static function without_consent_answers( $ticket_type_id, array $answers ) {
		return array_diff_key( $answers, self::fields( $ticket_type_id ) );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Privacy.
	 * ------------------------------------------------------------------------------------------- */

	public static function register_exporter( $exporters ) {
		$exporters['pass-captain-consents'] = array( 'exporter_friendly_name' => __( 'Pass Captain Einwilligungen', 'pass-captain' ), 'callback' => array( __CLASS__, 'export' ) );
		return $exporters;
	}

	public static function register_eraser( $erasers ) {
		$erasers['pass-captain-consents'] = array( 'eraser_friendly_name' => __( 'Pass Captain Einwilligungen', 'pass-captain' ), 'callback' => array( __CLASS__, 'erase' ) );
		return $erasers;
	}

	/**
	 * Records of a person: only consents whose subject is this person. A buyer's export/erasure must
	 * neither hand out nor destroy the consent proof of the attendees (Art. 7(1), Art. 15(4) GDPR);
	 * consents ticked by the buyer for an attendee at checkout name the attendee as subject and stay
	 * with the attendee's request.
	 */
	private static function ids_for_email( $email, $limit, $offset = 0 ) {
		global $wpdb;
		return $wpdb->get_col( $wpdb->prepare( 'SELECT consent_id FROM %i WHERE subject_email=%s ORDER BY consent_id LIMIT %d OFFSET %d', self::table(), $email, $limit, $offset ) );
	}

	/** Personal data exporter: every consent with text, version, times and source. */
	public static function export( $email_address, $page = 1 ) {
		$email = sanitize_email( $email_address );
		if ( '' === $email ) { return array( 'data' => array(), 'done' => true ); }
		$ids = self::ids_for_email( $email, 100, max( 0, ( absint( $page ) - 1 ) * 100 ) );
		$data = array();
		foreach ( $ids as $id ) {
			$c = self::get( $id );
			if ( ! $c ) { continue; }
			$data[] = array(
				'group_id' => 'pass-captain-consents',
				'group_label' => __( 'Pass Captain Einwilligungen', 'pass-captain' ),
				'item_id' => 'consent-' . (int) $c->consent_id,
				'data' => array(
					array( 'name' => __( 'Einwilligung', 'pass-captain' ), 'value' => $c->consent_label ),
					array( 'name' => __( 'Einwilligungstext', 'pass-captain' ), 'value' => $c->consent_text ),
					array( 'name' => __( 'Textversion', 'pass-captain' ), 'value' => $c->text_version ),
					array( 'name' => __( 'Erteilt (UTC) und Quelle', 'pass-captain' ), 'value' => $c->granted_at . ' / ' . $c->source ),
					array( 'name' => __( 'Widerrufen (UTC) und Quelle', 'pass-captain' ), 'value' => $c->withdrawn_at ? $c->withdrawn_at . ' / ' . $c->withdrawn_source : '—' ),
					array( 'name' => __( 'Betroffene E-Mail', 'pass-captain' ), 'value' => $c->subject_email ),
				),
			);
		}
		return array( 'data' => $data, 'done' => count( $ids ) < 100 );
	}

	/** Personal data eraser: deletes the consent records of the person. */
	public static function erase( $email_address, $page = 1 ) {
		global $wpdb;
		$email = sanitize_email( $email_address );
		$removed = 0;
		if ( '' !== $email ) {
			$ids = array_map( 'absint', self::ids_for_email( $email, self::BATCH ) );
			if ( $ids ) { $removed = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE consent_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( self::table() ), $ids ) ) ); }
			$done = count( $ids ) < self::BATCH;
		} else {
			$done = true;
		}
		return array( 'items_removed' => $removed, 'items_retained' => false, 'messages' => array(), 'done' => $done );
	}

	/**
	 * Retention: consent records are deleted together with the participant answers, i.e. after the
	 * end of the occurrence plus the field's retention days (or the global attendee retention
	 * setting when it is set).
	 *
	 * @param int|null $now UNIX time (tests).
	 * @return int Deleted records.
	 */
	public static function retention( $now = null ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$now_sql = gmdate( 'Y-m-d H:i:s', is_numeric( $now ) ? (int) $now : time() ); // "continue" on a continuation run.
		$global = Retention::attendee_days(); // 0 = per-field periods.
		$default_days = (int) Retention::default_attendee_days();
		$deleted = 0;
		for ( $round = 0; $round < 20; $round++ ) {
			// A global retention period overrides the per-field one (which falls back to the default). End of the occurrence: Occurrence::end_sql() (A606).
			$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT c.consent_id FROM %i c INNER JOIN %i t ON t.ticket_id=c.ticket_id INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id LEFT JOIN %i f ON f.field_id=c.field_id WHERE DATE_ADD(' . \PassCaptain\Occurrence::end_sql( 'o' ) . ', INTERVAL IF(%d>0, %d, COALESCE(f.retention_days, %d)) DAY)<%s LIMIT %d', self::table(), $p . 'wpcap_tickets', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $p . 'wpcap_participant_fields', $global, $global, $default_days, $now_sql, self::BATCH ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Occurrence::end_sql() returns a static SQL expression (sanitized alias, integer duration).
			if ( ! $ids ) { break; }
			$deleted += (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE consent_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( self::table() ), $ids ) ) );
			if ( count( $ids ) < self::BATCH ) { break; }
		}
		if ( $deleted ) { Audit::record( 'privacy_retention_consents', 'system', 0, array( 'deleted' => $deleted ) ); }
		return $deleted;
	}
}

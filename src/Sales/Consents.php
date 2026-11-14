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
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT field_id,field_key,label,required,consent_text,consent_version FROM {$wpdb->prefix}wpcap_participant_fields WHERE ticket_type_id=%d AND field_type='consent'", absint( $ticket_type_id ) ) ) as $field ) {
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
		$ticket = $wpdb->get_row( $wpdb->prepare( "SELECT ticket_id,order_id,ticket_type_id,attendee_email FROM {$p}wpcap_tickets WHERE ticket_id=%d", absint( $ticket_id ) ) );
		if ( ! $ticket ) { return; }
		$fields = self::fields( (int) $ticket->ticket_type_id );
		if ( ! $fields ) { return; }
		$answers = $wpdb->get_results( $wpdb->prepare( "SELECT field_id,answer FROM {$p}wpcap_participant_answers WHERE ticket_id=%d AND field_id IN (" . implode( ',', array_map( 'absint', array_keys( $fields ) ) ) . ')', (int) $ticket->ticket_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- absint ids.
		foreach ( $answers as $answer ) {
			if ( '1' !== (string) $answer->answer ) { continue; }
			$field = $fields[ (int) $answer->field_id ];
			$subject = '' !== (string) $ticket->attendee_email ? (string) $ticket->attendee_email : (string) ( $order->customer_email ?? '' );
			self::grant( (int) $ticket->ticket_id, (int) $ticket->order_id, $field, $subject, 'checkout', (string) ( $order->created_at ?? current_time( 'mysql', true ) ) );
		}
	}

	/** Inserts an active consent record unless one exists for ticket and field. @return int consent id */
	public static function grant( $ticket_id, $order_id, $field, $subject_email, $source, $granted_at ) {
		global $wpdb;
		$existing = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT consent_id FROM ' . self::table() . ' WHERE ticket_id=%d AND field_id=%d AND withdrawn_at IS NULL', absint( $ticket_id ), (int) $field->field_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- own table.
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
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE order_id=%d ORDER BY consent_id', absint( $order_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- own table.
	}

	public static function get( $consent_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE consent_id=%d', absint( $consent_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- own table.
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
		$updated = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET withdrawn_at=%s,withdrawn_source=%s WHERE consent_id=%d AND withdrawn_at IS NULL', $now, sanitize_key( $source ), (int) $consent->consent_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- own table.
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
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) || empty( $_POST['wpcap_consent_withdraw'] ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the emailed access token is the capability.
		$result = self::withdraw_for_order( $order, absint( wp_unslash( $_POST['wpcap_consent_withdraw'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$url = add_query_arg( array( 'wpcap_order' => $order->public_id, 'wpcap_access' => $token, 'wpcap_consent' => is_wp_error( $result ) ? 'error' : 'withdrawn' ), home_url( '/' ) );
		wp_safe_redirect( $url, 303 );
		exit;
	}

	/** Portal section listing the consents of the order with a withdraw button each ('' without consents). */
	public static function portal_section( $order ) {
		$consents = self::for_order( (int) $order->order_id );
		if ( ! $consents ) { return ''; }
		$state = sanitize_key( (string) ( $_GET['wpcap_consent'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$html = '<section class="card" aria-labelledby="wpcap-consents-title"><h2 id="wpcap-consents-title">' . esc_html__( 'Einwilligungen', 'pass-captain' ) . '</h2>';
		if ( 'withdrawn' === $state ) { $html .= '<p role="status">' . esc_html__( 'Die Einwilligung wurde widerrufen.', 'pass-captain' ) . '</p>'; }
		if ( 'error' === $state ) { $html .= '<p role="alert">' . esc_html__( 'Die Einwilligung konnte nicht widerrufen werden.', 'pass-captain' ) . '</p>'; }
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
		$exporters['wp-pass-captain-consents'] = array( 'exporter_friendly_name' => __( 'Pass Captain Einwilligungen', 'pass-captain' ), 'callback' => array( __CLASS__, 'export' ) );
		return $exporters;
	}

	public static function register_eraser( $erasers ) {
		$erasers['wp-pass-captain-consents'] = array( 'eraser_friendly_name' => __( 'Pass Captain Einwilligungen', 'pass-captain' ), 'callback' => array( __CLASS__, 'erase' ) );
		return $erasers;
	}

	/** Records of a person: subject e-mail or buyer e-mail of the order. */
	private static function ids_for_email( $email, $limit, $offset = 0 ) {
		global $wpdb;
		return $wpdb->get_col( $wpdb->prepare( 'SELECT c.consent_id FROM ' . self::table() . " c LEFT JOIN {$wpdb->prefix}wpcap_orders o ON o.order_id=c.order_id WHERE c.subject_email=%s OR o.customer_email=%s ORDER BY c.consent_id LIMIT %d OFFSET %d", $email, $email, $limit, $offset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- own table.
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
				'group_id' => 'wp-pass-captain-consents',
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
			if ( $ids ) { $removed = (int) $wpdb->query( 'DELETE FROM ' . self::table() . ' WHERE consent_id IN (' . implode( ',', $ids ) . ')' ); } // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
		$now_sql = gmdate( 'Y-m-d H:i:s', null === $now || '' === $now ? time() : (int) $now );
		$global = Retention::attendee_days();
		$days = $global > 0 ? (string) (int) $global : 'COALESCE(f.retention_days, ' . (int) Retention::default_attendee_days() . ')';
		$deleted = 0;
		for ( $round = 0; $round < 20; $round++ ) {
			$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT c.consent_id FROM ' . self::table() . " c INNER JOIN {$p}wpcap_tickets t ON t.ticket_id=c.ticket_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id LEFT JOIN {$p}wpcap_participant_fields f ON f.field_id=c.field_id WHERE DATE_ADD(COALESCE(o.ends_at, DATE_ADD(o.starts_at, INTERVAL 1 DAY)), INTERVAL $days DAY)<%s LIMIT %d", $now_sql, self::BATCH ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- integer SQL.
			if ( ! $ids ) { break; }
			$deleted += (int) $wpdb->query( 'DELETE FROM ' . self::table() . ' WHERE consent_id IN (' . implode( ',', array_map( 'absint', $ids ) ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( count( $ids ) < self::BATCH ) { break; }
		}
		if ( $deleted ) { Audit::record( 'privacy_retention_consents', 'system', 0, array( 'deleted' => $deleted ) ); }
		return $deleted;
	}
}

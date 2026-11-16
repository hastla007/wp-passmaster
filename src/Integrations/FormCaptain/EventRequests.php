<?php
/**
 * Event requests handed over from Form Captain (PASS-FC-05): Pass Captain's own table
 * `wpcap_event_requests`, the form action callback, clean-up on entry deletion and consent
 * withdrawal, and the review list on the event screen.
 *
 * A request is only a note "to review" for an event. It never creates a reservation, payment or
 * ticket on its own: staff book it manually through Orders::create() (same capacity locks and
 * payment flow as every other channel) or reject it. The event comes only from the verified
 * signed context `pass.event_registration` of the entry, never from form fields. Only the mapped,
 * non-sensitive fields (name, e-mail, phone, message) plus form and entry ID are stored.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Integrations\FormCaptain;

use PassCaptain\Access;
use PassCaptain\Admin\EventsPage;
use PassCaptain\Admin\Forms;
use PassCaptain\Audit;
use PassCaptain\Orders;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Pass Captain's own tables.

/**
 * Handed-over event requests.
 */
final class EventRequests {
	const TABLE         = 'wpcap_event_requests';
	const ACTION_BOOK   = 'wpcap_form_captain_request_book';
	const ACTION_REJECT = 'wpcap_form_captain_request_reject';
	const KEY_NAMESPACE = 'form_captain';
	const ORDERS_CAP    = 'wpcap_manage_orders';

	/** Status: waiting for review by staff. */
	const STATUS_REVIEW = 'review';
	/** Status: staff created an order from the request. */
	const STATUS_BOOKED = 'booked';
	/** Status: staff rejected the request. */
	const STATUS_REJECTED = 'rejected';

	/**
	 * CREATE TABLE statement (dbDelta format).
	 *
	 * @param string $prefix  Table prefix.
	 * @param string $charset Charset/collation clause.
	 * @return string[]
	 */
	public static function schema( $prefix, $charset ) {
		return array(
			"CREATE TABLE {$prefix}" . self::TABLE . " (
				request_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL,
				form_id bigint(20) unsigned NOT NULL,
				entry_id bigint(20) unsigned NOT NULL,
				idempotency_key varchar(191) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'review',
				name varchar(190) NOT NULL DEFAULT '',
				email varchar(190) NOT NULL DEFAULT '',
				phone varchar(40) NOT NULL DEFAULT '',
				message text NOT NULL,
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				decided_by bigint(20) unsigned NOT NULL DEFAULT 0,
				decided_at datetime NULL,
				anonymized_at datetime NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (request_id),
				UNIQUE KEY idempotency_key (idempotency_key),
				KEY event_status (event_id,status),
				KEY entry (form_id,entry_id)
			) ENGINE=InnoDB $charset;",
		);
	}

	/**
	 * Request row or null.
	 *
	 * @param int $request_id Request ID.
	 * @return object|null
	 */
	public static function get( $request_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_event_requests WHERE request_id=%d", absint( $request_id ) ) );
	}

	/**
	 * Requests of an event, open ones first (newest first within a status).
	 *
	 * @param int $event_id Event ID.
	 * @param int $limit    Maximum rows.
	 * @return object[]
	 */
	public static function for_event( $event_id, $limit = 100 ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_event_requests WHERE event_id=%d ORDER BY status='review' DESC, request_id DESC LIMIT %d", absint( $event_id ), max( 1, absint( $limit ) ) ) );
	}

	/**
	 * Number of requests of an event (all states).
	 *
	 * @param int $event_id Event ID.
	 */
	public static function count_for_event( $event_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_event_requests WHERE event_id=%d", absint( $event_id ) ) );
	}

	/**
	 * Contract result array.
	 *
	 * @param string $status    success, retry or fail.
	 * @param string $code      Machine code.
	 * @param string $message   Message without personal data.
	 * @param string $remote_id Request reference.
	 * @return array{status:string,code:string,message:string,remote_id?:string}
	 */
	private static function result( $status, $code, $message = '', $remote_id = '' ) {
		$out = array(
			'status'  => $status,
			'code'    => $code,
			'message' => $message,
		);
		if ( '' !== $remote_id ) {
			$out['remote_id'] = $remote_id;
		}
		return $out;
	}

	/**
	 * Reference returned to Form Captain as remote_id.
	 *
	 * @param int $request_id Request ID.
	 */
	public static function remote_id( $request_id ) {
		return 'pass-request-' . absint( $request_id );
	}

	/**
	 * Form action callback (contract v1 job array). Idempotent: the same idempotency key always
	 * returns the same request.
	 *
	 * @param array<string,mixed> $job Job: form_id, entry_id, idempotency_key, values, context, test_mode, ….
	 * @return array{status:string,code:string,message:string,remote_id?:string}
	 */
	public static function handle_job( array $job ) {
		if ( ! empty( $job['test_mode'] ) ) {
			return self::result( 'success', 'test_ok', 'Test mode: no request created.' );
		}
		$key = is_string( $job['idempotency_key'] ?? null ) ? $job['idempotency_key'] : '';
		if ( '' === $key || strlen( $key ) > 191 ) {
			return self::result( 'fail', 'missing_idempotency_key', 'No valid idempotency key.' );
		}
		global $wpdb;
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT request_id FROM {$wpdb->prefix}wpcap_event_requests WHERE idempotency_key=%s", $key ) );
		if ( $existing ) {
			return self::result( 'success', 'already_created', '', self::remote_id( (int) $existing ) );
		}
		// The event comes only from the verified signed context, never from form values.
		$ctx = is_array( $job['context'] ?? null ) ? $job['context'] : array();
		if ( Adapter::PURPOSE !== ( $ctx['purpose'] ?? '' ) || Adapter::REF_TYPE !== ( $ctx['ref_type'] ?? '' ) || true !== ( $ctx['verified'] ?? null ) || ! is_string( $ctx['ref_id'] ?? null ) || ! ctype_digit( $ctx['ref_id'] ) ) {
			return self::result( 'fail', 'context_missing', 'The entry has no verified event context.' );
		}
		$event_id = absint( $ctx['ref_id'] );
		$status   = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}wpcap_events WHERE event_id=%d", $event_id ) );
		if ( null === $status ) {
			return self::result( 'fail', 'event_not_found', 'The event does not exist.' );
		}
		if ( 'cancelled' === $status ) {
			return self::result( 'fail', 'event_cancelled', 'The event was cancelled.' );
		}
		if ( 'published' !== $status ) {
			return self::result( 'fail', 'event_not_open', 'The event is not published.' );
		}
		$form_id  = absint( $job['form_id'] ?? 0 );
		$entry_id = absint( $job['entry_id'] ?? 0 );
		if ( ! $form_id || ! $entry_id ) {
			return self::result( 'fail', 'invalid_entry', 'Form or entry ID missing.' );
		}
		$values     = is_array( $job['values'] ?? null ) ? $job['values'] : array();
		$text       = static function ( $key, $max ) use ( $values ) {
			$value = $values[ $key ] ?? '';
			$value = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
			return mb_substr( 'message' === $key ? sanitize_textarea_field( $value ) : sanitize_text_field( $value ), 0, $max );
		};
		$email      = sanitize_email( (string) ( is_scalar( $values['email'] ?? null ) ? $values['email'] : '' ) );
		$now        = current_time( 'mysql', true );
		$saved      = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}wpcap_event_requests (event_id,form_id,entry_id,idempotency_key,status,name,email,phone,message,created_at,updated_at) VALUES (%d,%d,%d,%s,%s,%s,%s,%s,%s,%s,%s)",
				$event_id,
				$form_id,
				$entry_id,
				$key,
				self::STATUS_REVIEW,
				$text( 'name', 190 ),
				is_email( $email ) ? mb_substr( $email, 0, 190 ) : '',
				$text( 'phone', 40 ),
				$text( 'message', 5000 ),
				$now,
				$now
			)
		);
		$request_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT request_id FROM {$wpdb->prefix}wpcap_event_requests WHERE idempotency_key=%s", $key ) );
		if ( ! $request_id ) {
			return self::result( 'retry', 'storage_failed', 'The request could not be stored.' );
		}
		if ( 1 !== (int) $saved ) {
			// A parallel delivery stored it first.
			return self::result( 'success', 'already_created', '', self::remote_id( $request_id ) );
		}
		Audit::record(
			'event_request_received',
			'event',
			$event_id,
			array(
				'request_id' => $request_id,
				'form_id'    => $form_id,
				'entry_id'   => $entry_id,
			)
		);
		return self::result( 'success', 'created', '', self::remote_id( $request_id ) );
	}

	/**
	 * Entry deleted or consent withdrawn in Form Captain: open requests of the entry are removed,
	 * decided ones (booked/rejected) keep their status but lose the personal data. An order created
	 * from a request is Pass Captain's own record and follows its own retention.
	 *
	 * @param int    $entry_id Entry ID.
	 * @param int    $form_id  Form ID.
	 * @param string $reason   Reason or source (logged).
	 */
	public static function forget_entry( $entry_id, $form_id = 0, $reason = '' ) {
		global $wpdb;
		$entry_id = absint( $entry_id );
		$form_id  = absint( $form_id );
		if ( ! $entry_id || ! $form_id ) {
			return;
		}
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT request_id,event_id,status FROM {$wpdb->prefix}wpcap_event_requests WHERE form_id=%d AND entry_id=%d", $form_id, $entry_id ) );
		foreach ( $rows as $row ) {
			if ( self::STATUS_REVIEW === $row->status ) {
				$wpdb->delete( $wpdb->prefix . self::TABLE, array( 'request_id' => (int) $row->request_id ), array( '%d' ) );
				$action = 'event_request_removed';
			} else {
				$now = current_time( 'mysql', true );
				$wpdb->update(
					$wpdb->prefix . self::TABLE,
					array(
						'name'          => '',
						'email'         => '',
						'phone'         => '',
						'message'       => '',
						'anonymized_at' => $now,
						'updated_at'    => $now,
					),
					array( 'request_id' => (int) $row->request_id ),
					array( '%s', '%s', '%s', '%s', '%s', '%s' ),
					array( '%d' )
				);
				$action = 'event_request_anonymized';
			}
			Audit::record(
				$action,
				'event',
				(int) $row->event_id,
				array(
					'request_id' => (int) $row->request_id,
					'reason'     => sanitize_key( (string) $reason ),
				)
			);
		}
	}

	/**
	 * Action `form_captain_entry_deleted_v1`.
	 *
	 * @param int    $entry_id Entry ID.
	 * @param int    $form_id  Form ID.
	 * @param string $reason   Reason.
	 */
	public static function on_entry_deleted( $entry_id, $form_id = 0, $reason = '' ) {
		self::forget_entry( $entry_id, $form_id, '' !== (string) $reason ? (string) $reason : 'deleted' );
	}

	/**
	 * Action `form_captain_consent_withdrawn_v1`.
	 *
	 * @param int    $entry_id Entry ID.
	 * @param int    $form_id  Form ID.
	 * @param string $source   Source of the withdrawal (unused).
	 */
	public static function on_consent_withdrawn( $entry_id, $form_id = 0, $source = '' ) {
		unset( $source );
		self::forget_entry( $entry_id, $form_id, 'consent_withdrawn' );
	}

	/**
	 * Loads an open request and checks access to its event.
	 *
	 * @param array<string,mixed> $in         Input with request_id.
	 * @param string              $capability Capability for the event.
	 * @return object|\WP_Error
	 */
	private static function open_request( array $in, $capability ) {
		$request = self::get( Forms::int( $in, 'request_id' ) );
		if ( ! $request ) {
			return Forms::error( 'wpcap_request_missing', __( 'Anfrage nicht gefunden.', 'pass-captain' ), 404 );
		}
		if ( ! Access::can_access_event( (int) $request->event_id, $capability ) ) {
			return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ) );
		}
		if ( self::STATUS_REVIEW !== $request->status ) {
			return Forms::error( 'wpcap_request_decided', __( 'Diese Anfrage wurde bereits bearbeitet.', 'pass-captain' ), 409 );
		}
		return $request;
	}

	/**
	 * Marks an open request as decided (only if still open).
	 *
	 * @param int    $request_id Request ID.
	 * @param string $status     New status.
	 * @param int    $order_id   Order created from it.
	 */
	private static function decide( $request_id, $status, $order_id = 0 ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		return 1 === (int) $wpdb->update(
			$wpdb->prefix . self::TABLE,
			array(
				'status'     => $status,
				'order_id'   => absint( $order_id ),
				'decided_by' => get_current_user_id(),
				'decided_at' => $now,
				'updated_at' => $now,
			),
			array(
				'request_id' => absint( $request_id ),
				'status'     => self::STATUS_REVIEW,
			),
			array( '%s', '%d', '%d', '%s', '%s' ),
			array( '%d', '%s' )
		);
	}

	/**
	 * Rejects an open request (capability wpcap_manage_events for the event).
	 *
	 * @param array<string,mixed> $in Unslashed input: request_id.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function reject( array $in ) {
		$request = self::open_request( $in, EventsPage::CAP );
		if ( is_wp_error( $request ) ) {
			return $request;
		}
		if ( ! self::decide( (int) $request->request_id, self::STATUS_REJECTED ) ) {
			return Forms::error( 'wpcap_request_decided', __( 'Diese Anfrage wurde bereits bearbeitet.', 'pass-captain' ), 409 );
		}
		Audit::record( 'event_request_rejected', 'event', (int) $request->event_id, array( 'request_id' => (int) $request->request_id ) );
		return array(
			'request_id' => (int) $request->request_id,
			'event_id'   => (int) $request->event_id,
			'status'     => self::STATUS_REJECTED,
		);
	}

	/**
	 * Books an open request manually through Orders::create(): ticket type of this event, quantity,
	 * the request's e-mail. Paid tickets are held for the manual payment period and issued after the
	 * payment is confirmed (Orders); free tickets are issued at once. Capability wpcap_manage_orders
	 * for the event. Repeating the call reuses the same order (idempotency key per request).
	 *
	 * @param array<string,mixed> $in Unslashed input: request_id, ticket_type_id, quantity, accept_terms.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function book( array $in ) {
		$request = self::open_request( $in, self::ORDERS_CAP );
		if ( is_wp_error( $request ) ) {
			return $request;
		}
		if ( ! is_email( $request->email ) ) {
			return Forms::error( 'wpcap_request_email', __( 'Die Anfrage enthält keine gültige E-Mail-Adresse; eine Buchung ist so nicht möglich.', 'pass-captain' ) );
		}
		global $wpdb;
		$type_id  = Forms::int( $in, 'ticket_type_id' );
		$quantity = min( 10, max( 1, Forms::int( $in, 'quantity', 1 ) ) );
		$belongs  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT t.ticket_type_id FROM {$wpdb->prefix}wpcap_ticket_types t INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=t.occurrence_id WHERE t.ticket_type_id=%d AND o.event_id=%d AND o.status='scheduled' AND t.status='active'", $type_id, (int) $request->event_id ) );
		if ( ! $belongs ) {
			return Forms::error( 'wpcap_request_ticket_type', __( 'Bitte eine aktive Ticketart dieses Events wählen.', 'pass-captain' ) );
		}
		$order = Orders::create(
			array(
				'email'           => $request->email,
				'items'           => array(
					array(
						'ticket_type_id' => $type_id,
						'quantity'       => $quantity,
					),
				),
				'idempotency_key' => 'request-' . (int) $request->request_id,
				'accept_terms'    => Forms::flag( $in, 'accept_terms' ),
			),
			array(
				'key_namespace'    => self::KEY_NAMESPACE,
				'source'           => self::KEY_NAMESPACE,
				'payment_provider' => 'manual',
				'hold_minutes'     => max( 1, (int) Settings::get( 'manual_hold_hours' ) ) * 60,
				'skip_rate_limit'  => true,
			)
		);
		if ( is_wp_error( $order ) ) {
			return Forms::error( $order->get_error_code(), $order->get_error_message(), 409 );
		}
		self::decide( (int) $request->request_id, self::STATUS_BOOKED, (int) $order['order_id'] );
		Audit::record(
			'event_request_booked',
			'event',
			(int) $request->event_id,
			array(
				'request_id' => (int) $request->request_id,
				'order_id'   => (int) $order['order_id'],
			)
		);
		return array(
			'request_id'     => (int) $request->request_id,
			'event_id'       => (int) $request->event_id,
			'status'         => self::STATUS_BOOKED,
			'order_id'       => (int) $order['order_id'],
			'payment_status' => (string) ( $order['payment_status'] ?? '' ),
		);
	}

	/**
	 * Event ID of a request for the redirect after a handler.
	 *
	 * @param array<string,mixed> $in Input.
	 */
	private static function back_url( array $in ) {
		$request = self::get( Forms::int( $in, 'request_id' ) );
		return Forms::page_url(
			EventsPage::SLUG,
			array(
				'view'     => 'event',
				'event_id' => $request ? (int) $request->event_id : 0,
			)
		);
	}

	/** Admin-post: reject. */
	public static function handle_reject() {
		$in = Forms::post();
		Forms::verify( self::ACTION_REJECT . '_' . Forms::int( $in, 'request_id' ) );
		Forms::finish( self::reject( $in ), __( 'Anfrage abgelehnt.', 'pass-captain' ), self::back_url( $in ) );
	}

	/** Admin-post: book. */
	public static function handle_book() {
		$in = Forms::post();
		Forms::verify( self::ACTION_BOOK . '_' . Forms::int( $in, 'request_id' ) );
		$result  = self::book( $in );
		$message = ! is_wp_error( $result ) && 'pending' === $result['payment_status']
			? __( 'Buchung angelegt. Die Tickets sind reserviert und werden nach bestätigter Zahlung ausgestellt (Bestellungen).', 'pass-captain' )
			: __( 'Buchung angelegt.', 'pass-captain' );
		Forms::finish( $result, $message, self::back_url( $in ) );
	}

	/**
	 * Labels of the request states.
	 *
	 * @return array<string,string>
	 */
	private static function status_labels() {
		return array(
			self::STATUS_REVIEW   => __( 'Zu prüfen', 'pass-captain' ),
			self::STATUS_BOOKED   => __( 'Gebucht', 'pass-captain' ),
			self::STATUS_REJECTED => __( 'Abgelehnt', 'pass-captain' ),
		);
	}

	/**
	 * Review list on the event screen (inside the Form Captain section).
	 *
	 * @param object $event    Event row.
	 * @param bool   $editable Event can still be edited.
	 */
	public static function render_list( $event, $editable = true ) {
		$rows = self::for_event( (int) $event->event_id );
		if ( ! $rows ) {
			return;
		}
		global $wpdb;
		$types   = $editable ? (array) $wpdb->get_results( $wpdb->prepare( "SELECT t.ticket_type_id,t.name,o.starts_at,o.timezone FROM {$wpdb->prefix}wpcap_ticket_types t INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=t.occurrence_id WHERE o.event_id=%d AND o.status='scheduled' AND t.status='active' ORDER BY o.starts_at,t.ticket_type_id", (int) $event->event_id ) ) : array();
		$options = array();
		foreach ( $types as $type ) {
			$options[ (int) $type->ticket_type_id ] = $type->name . ' – ' . Forms::datetime( $type->starts_at, $type->timezone, false );
		}
		$can_book   = $options && Access::can_access_event( (int) $event->event_id, self::ORDERS_CAP );
		$need_terms = (bool) Settings::get( 'require_terms' );
		$labels     = self::status_labels();
		echo '<h3>' . esc_html__( 'Anfragen aus dem Formular', 'pass-captain' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Anfragen sind keine Buchungen. Eine Buchung entsteht nur, wenn du sie hier anlegst; abgelehnte Anfragen bleiben ohne Folgen.', 'pass-captain' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Eingang', 'pass-captain' ), __( 'Name', 'pass-captain' ), __( 'E-Mail', 'pass-captain' ), __( 'Telefon', 'pass-captain' ), __( 'Nachricht', 'pass-captain' ), __( 'Status', 'pass-captain' ), __( 'Aktion', 'pass-captain' ) ) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$id = (int) $row->request_id;
			echo '<tr><td>' . esc_html( Forms::datetime( $row->created_at, wp_timezone_string(), false ) ) . '</td>';
			echo '<td>' . esc_html( $row->name ) . '</td><td>' . esc_html( $row->email ) . '</td><td>' . esc_html( $row->phone ) . '</td>';
			echo '<td>' . esc_html( mb_substr( (string) $row->message, 0, 300 ) ) . '</td>';
			echo '<td>' . esc_html( $labels[ $row->status ] ?? $row->status ) . ( $row->anonymized_at ? ' ' . esc_html__( '(anonymisiert)', 'pass-captain' ) : '' ) . '</td><td>';
			if ( self::STATUS_BOOKED === $row->status && $row->order_id ) {
				/* translators: %d: order ID */
				echo '<a href="' . esc_url( \PassCaptain\Admin\OrdersPage::order_url( (int) $row->order_id ) ) . '">' . esc_html( sprintf( __( 'Bestellung #%d', 'pass-captain' ), (int) $row->order_id ) ) . '</a>';
			} elseif ( self::STATUS_REVIEW === $row->status && $editable ) {
				if ( $can_book && is_email( $row->email ) ) {
					Forms::open_form( self::ACTION_BOOK, self::ACTION_BOOK . '_' . $id );
					Forms::hidden( 'request_id', $id );
					echo '<label class="screen-reader-text" for="wpcap-req-type-' . esc_attr( (string) $id ) . '">' . esc_html__( 'Ticketart', 'pass-captain' ) . '</label>';
					Forms::select( 'ticket_type_id', $options, '', 'wpcap-req-type-' . $id );
					echo ' <label for="wpcap-req-qty-' . esc_attr( (string) $id ) . '">' . esc_html__( 'Anzahl', 'pass-captain' ) . '</label> <input id="wpcap-req-qty-' . esc_attr( (string) $id ) . '" name="quantity" type="number" min="1" max="10" value="1" class="small-text">';
					if ( $need_terms ) {
						echo ' <label><input type="checkbox" name="accept_terms" value="1" required> ' . esc_html__( 'Bedingungen wurden akzeptiert', 'pass-captain' ) . '</label>';
					}
					echo ' <button class="button button-primary" type="submit">' . esc_html__( 'Buchung anlegen', 'pass-captain' ) . '</button></form> ';
				}
				Forms::open_form( self::ACTION_REJECT, self::ACTION_REJECT . '_' . $id, 'style="display:inline"' );
				Forms::hidden( 'request_id', $id );
				echo '<button class="button" type="submit">' . esc_html__( 'Ablehnen', 'pass-captain' ) . '</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}
}

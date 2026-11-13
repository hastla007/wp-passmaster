<?php
/**
 * Service booking flow end-to-end (A135): payment through the regular checkout, order hooks,
 * customer/staff notifications, the personal booking token (REST self-service) and the order
 * portal card. The booking operations themselves (reserve, confirm, rebook, cancel – locking,
 * rules, capacity) live in ServiceBookings.
 */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ServiceBookingFlow {
	/** Failed token checks per booking and hour before the booking is locked for REST calls. */
	const TOKEN_ATTEMPTS = 10;

	public static function register() {
		add_action( 'wpcap_order_creating', array( __CLASS__, 'link_order' ), 10, 4 );
		add_action( 'wpcap_order_paid', array( __CLASS__, 'on_order_paid' ), 10, 1 );
		add_action( 'wpcap_order_cancelled', array( __CLASS__, 'on_order_ended' ), 10, 1 );
		add_action( 'wpcap_order_refunded', array( __CLASS__, 'on_order_ended' ), 10, 1 );
		add_action( 'wpcap_tickets_issued', array( ServiceBookings::class, 'refund_unallocated' ), 10, 1 );
		add_filter( 'wpcap_checkout_error_messages', array( __CLASS__, 'checkout_messages' ) );
		add_filter( 'wpcap_process_job', array( __CLASS__, 'handle_job' ), 10, 3 );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Payment through the checkout.
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Starts the payment of a held booking (called by ServiceBookings::confirm() after its checks):
	 * the regular checkout with one line of the resource's ticket type × places. The order is linked
	 * inside the order transaction (link_order()); a second attempt for the same booking fails there,
	 * so a booking never gets two orders. Free orders (price 0, full voucher) complete at once and
	 * confirm the booking through `wpcap_order_paid`.
	 *
	 * @return array|\WP_Error booking_id, status, order (public_id, reference, payment_status, total, payment).
	 */
	public static function checkout( $booking, $slot, $ticket_type_id, array $args ) {
		$params = array(
			'email' => (string) $booking->customer_email,
			'items' => array( array( 'ticket_type_id' => (int) $ticket_type_id, 'quantity' => (int) $booking->quantity ) ),
			'idempotency_key' => 'service-booking-' . (int) $booking->booking_id . '-' . wp_generate_uuid4(),
			'provider' => sanitize_key( (string) ( $args['provider'] ?? 'manual' ) ),
		);
		foreach ( array( 'accept_terms', 'terms_version', 'locale', 'billing', 'code', 'attendees' ) as $key ) {
			if ( isset( $args[ $key ] ) ) { $params[ $key ] = $args[ $key ]; }
		}
		$request = new \WP_REST_Request( 'POST' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $params ) );
		$response = Rest\Controller::checkout(
			$request,
			array(
				'manual_start_ts' => strtotime( $slot->starts_at . ' UTC' ),
				'internal' => array(
					'service_booking_id' => (int) $booking->booking_id,
					'service_booking_until' => (string) $slot->starts_at,
					// The ticket type is only the price position: its own sales window and occurrence
					// start do not apply (the booking rules do); an ended occurrence still blocks.
					'allow_door_sales' => true,
					'source' => 'service_booking',
				),
			)
		);
		if ( is_wp_error( $response ) ) { return $response; }
		$data = $response instanceof \WP_REST_Response ? (array) $response->get_data() : (array) $response;
		$fresh = ServiceBookings::booking( (int) $booking->booking_id );
		Audit::record( 'service_booking_checkout', 'service_booking', (int) $booking->booking_id, array( 'event_id' => (int) $booking->event_id, 'order_id' => (int) ( $data['order_id'] ?? 0 ), 'payment_status' => (string) ( $data['payment_status'] ?? '' ) ) );
		return array(
			'booking_id' => (int) $booking->booking_id,
			'status' => $fresh ? ServiceBookings::booking_status( $fresh ) : 'held',
			'order' => array_intersect_key( $data, array_flip( array( 'order_id', 'public_id', 'reference', 'status', 'payment_status', 'total_minor', 'currency', 'total_formatted', 'payment', 'hold_minutes' ) ) ),
		);
	}

	/**
	 * `wpcap_order_creating` (inside the order transaction): links the booking named by the trusted
	 * option `service_booking_id` to the new order – only while it is still a valid hold. Otherwise
	 * the order is aborted (code service_booking_unavailable), so the payment never starts.
	 */
	public static function link_order( $order_id, $selected = array(), $payload = array(), $internal = array() ) {
		global $wpdb;
		$booking_id = absint( $internal['service_booking_id'] ?? 0 );
		if ( ! $booking_id ) { return; }
		$now = current_time( 'mysql', true );
		$until = gmdate( 'Y-m-d H:i:s', time() + max( 1, absint( $internal['hold_minutes'] ?? 10 ) ) * MINUTE_IN_SECONDS );
		$cap = (string) ( $internal['service_booking_until'] ?? '' );
		if ( '' !== $cap && $cap < $until ) { $until = $cap; }
		$linked = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_service_bookings SET status='pending_payment',order_id=%d,expires_at=%s,updated_at=%s WHERE booking_id=%d AND status='held' AND order_id=0 AND expires_at>%s", absint( $order_id ), $until, $now, $booking_id, $now ) );
		if ( 1 !== (int) $linked ) { throw new \RuntimeException( 'service_booking_unavailable' ); }
	}

	public static function checkout_messages( $messages ) {
		$messages['service_booking_unavailable'] = __( 'Die Reservierung des Zeitfensters ist abgelaufen oder wird bereits bezahlt. Bitte lade die Seite neu.', 'pass-captain' );
		return $messages;
	}

	public static function on_order_paid( $order_id ) {
		ServiceBookings::confirm_paid_order( absint( $order_id ) );
	}

	public static function on_order_ended( $order_id ) {
		ServiceBookings::release_order( absint( $order_id ) );
	}

	/** Job `service_booking_paid_retry`: confirming a paid order's bookings hit a lock. */
	public static function handle_job( $handled, $type, $payload = array() ) {
		if ( 'service_booking_paid_retry' !== $type ) { return $handled; }
		ServiceBookings::confirm_paid_order( absint( $payload['order_id'] ?? 0 ) );
		return true;
	}

	/* ---------------------------------------------------------------------------------------------
	 * Notifications.
	 * ------------------------------------------------------------------------------------------- */

	/** Timezone of the booking's event (first occurrence), else UTC. */
	private static function timezone( $event_id ) {
		global $wpdb;
		$zone = (string) $wpdb->get_var( $wpdb->prepare( "SELECT timezone FROM {$wpdb->prefix}wpcap_occurrences WHERE event_id=%d ORDER BY starts_at LIMIT 1", absint( $event_id ) ) );
		return in_array( $zone, timezone_identifiers_list(), true ) ? $zone : 'UTC';
	}

	/** "Boot 1 · Mo., 3. März 2027, 10:00" for a booking-like row (resource_name, starts_at, event_id). */
	public static function describe( $row ) {
		return (string) $row->resource_name . ' · ' . Frontend\Format::event_start( (string) $row->starts_at, self::timezone( (int) $row->event_id ) );
	}

	/**
	 * Mails the customer and the team (site admin plus the active providers of the resource) about
	 * a change: confirmed, rebooked, cancelled, unallocated (paid after the hold lapsed, refunded).
	 * Fires `wpcap_service_booking_changed` first. Each mail passes `wpcap_service_booking_mail`
	 * (return false to suppress).
	 *
	 * @param int    $booking_id Booking.
	 * @param string $change     confirmed | rebooked | cancelled | unallocated.
	 * @param array  $context    previous (booking row before), refund (row/WP_Error/null), actor.
	 */
	public static function notify( $booking_id, $change, array $context = array() ) {
		$booking = ServiceBookings::booking( $booking_id );
		if ( ! $booking ) { return; }
		/**
		 * After a service booking changed (committed): confirmed, rebooked, cancelled, unallocated.
		 */
		do_action( 'wpcap_service_booking_changed', (int) $booking_id, (string) $change, $context );
		$when = self::describe( $booking );
		$previous = isset( $context['previous'] ) && is_object( $context['previous'] ) ? self::describe( $context['previous'] ) : '';
		$refund = $context['refund'] ?? null;
		$refund_line = '';
		if ( is_wp_error( $refund ) ) {
			$refund_line = __( 'Die Erstattung konnte nicht automatisch ausgelöst werden; wir melden uns bei dir.', 'pass-captain' );
		} elseif ( is_array( $refund ) && 'pending_approval' === ( $refund['status'] ?? '' ) ) {
			$refund_line = __( 'Die Erstattung wird geprüft und anschließend ausgezahlt.', 'pass-captain' );
		} elseif ( is_array( $refund ) ) {
			/* translators: %s: amount */
			$refund_line = sprintf( __( 'Erstattet werden %s.', 'pass-captain' ), Frontend\Format::money( (int) ( $refund['amount_minor'] ?? 0 ), (string) ( $refund['currency'] ?? 'EUR' ) ) );
		}
		$subjects = array(
			/* translators: %s: resource and start */
			'confirmed' => sprintf( __( 'Buchung bestätigt: %s', 'pass-captain' ), $when ),
			/* translators: %s: resource and new start */
			'rebooked' => sprintf( __( 'Buchung umgebucht: %s', 'pass-captain' ), $when ),
			/* translators: %s: resource and start */
			'cancelled' => sprintf( __( 'Buchung storniert: %s', 'pass-captain' ), $when ),
			/* translators: %s: resource and start */
			'unallocated' => sprintf( __( 'Zahlung erhalten, Termin nicht mehr frei: %s', 'pass-captain' ), $when ),
		);
		if ( ! isset( $subjects[ $change ] ) ) { return; }
		$lines = array( $subjects[ $change ] );
		/* translators: %d: number of places */
		$lines[] = sprintf( _n( '%d Platz', '%d Plätze', (int) $booking->quantity, 'pass-captain' ), (int) $booking->quantity );
		if ( '' !== $previous && 'rebooked' === $change ) {
			/* translators: %s: previous resource and start */
			$lines[] = sprintf( __( 'Bisheriger Termin: %s', 'pass-captain' ), $previous );
		}
		if ( 'unallocated' === $change ) { $lines[] = __( 'Deine Zahlung ist erst nach Ablauf der Reservierung eingegangen und das Zeitfenster ist inzwischen vergeben. Der Betrag wird erstattet.', 'pass-captain' ); }
		if ( '' !== $refund_line ) { $lines[] = $refund_line; }
		$rules = ServiceBookings::rules( $booking );
		if ( in_array( $change, array( 'confirmed', 'rebooked' ), true ) && $rules['cancel_hours'] ) {
			/* translators: %s: deadline, e.g. "2 Tage" */
			$lines[] = sprintf( __( 'Stornieren kannst du bis %s vor Beginn.', 'pass-captain' ), ServiceBookings::duration_label( $rules['cancel_hours'] * 60 ) );
		}
		if ( in_array( $change, array( 'confirmed', 'rebooked' ), true ) && (int) $booking->order_id ) {
			$lines[] = __( 'Umbuchen und stornieren kannst du über den Link zu deiner Bestellung (Bestellportal).', 'pass-captain' );
		}
		$customer = array( 'to' => (string) $booking->customer_email, 'subject' => $subjects[ $change ], 'body' => implode( "\n\n", $lines ) );
		$customer = apply_filters( 'wpcap_service_booking_mail', $customer, $change, $booking, 'customer' );
		if ( is_array( $customer ) && is_email( $customer['to'] ) && ! Mail\Mailer::is_placeholder( $customer['to'] ) ) {
			wp_mail( $customer['to'], $customer['subject'], $customer['body'] );
		}
		$staff = array( (string) get_option( 'admin_email' ) );
		foreach ( ServiceProviders::for_resource( (int) $booking->resource_id ) as $provider ) {
			if ( 'active' === $provider->status && is_email( (string) $provider->email ) ) { $staff[] = (string) $provider->email; }
		}
		$staff = array_values( array_unique( array_filter( (array) apply_filters( 'wpcap_service_booking_staff_recipients', $staff, $booking, $change ), 'is_email' ) ) );
		if ( ! $staff ) { return; }
		/* translators: 1: booking id, 2: change summary */
		$staff_subject = sprintf( __( 'Pass Captain: Servicebuchung #%1$d – %2$s', 'pass-captain' ), (int) $booking_id, $subjects[ $change ] );
		$staff_lines = $lines;
		/* translators: %s: customer e-mail address */
		$staff_lines[] = sprintf( __( 'Kunde/Kundin: %s', 'pass-captain' ), (string) $booking->customer_email );
		if ( 'unallocated' === $change || is_wp_error( $refund ) ) { $staff_lines[] = __( 'Bitte prüfen: Erstattung unter „Erstattungen“ kontrollieren bzw. manuell klären.', 'pass-captain' ); }
		$mail = apply_filters( 'wpcap_service_booking_mail', array( 'to' => implode( ',', $staff ), 'subject' => $staff_subject, 'body' => implode( "\n\n", $staff_lines ) ), $change, $booking, 'staff' );
		if ( is_array( $mail ) ) { wp_mail( $mail['to'], $mail['subject'], $mail['body'] ); }
	}

	/* ---------------------------------------------------------------------------------------------
	 * Customer self-service: booking token (REST) and order portal.
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Booking for a valid personal token (constant-time hash compare), else an error. Wrong tokens
	 * count per booking; after TOKEN_ATTEMPTS per hour the booking answers 429.
	 *
	 * @return object|\WP_Error
	 */
	public static function verify_token( $booking_id, $token ) {
		$booking_id = absint( $booking_id );
		$token = strtolower( preg_replace( '/[^a-f0-9]/i', '', (string) $token ) );
		$key = 'wpcap_svc_token_' . $booking_id;
		$fails = (int) get_transient( $key );
		if ( $fails >= self::TOKEN_ATTEMPTS ) { return new \WP_Error( 'wpcap_booking_rate', __( 'Zu viele Versuche; bitte warte kurz.', 'pass-captain' ), array( 'status' => 429 ) ); }
		$booking = ServiceBookings::flow_ready() ? ServiceBookings::booking( $booking_id ) : null;
		if ( ! $booking || 64 !== strlen( $token ) || '' === (string) $booking->token_hash || ! hash_equals( (string) $booking->token_hash, hash( 'sha256', $token ) ) ) {
			set_transient( $key, $fails + 1, HOUR_IN_SECONDS );
			return new \WP_Error( 'wpcap_booking_access_denied', __( 'Der Buchungszugang ist ungültig.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		return $booking;
	}

	/** REST permission: valid booking token (param `token`). */
	public static function token_permission( \WP_REST_Request $request ) {
		$booking = self::verify_token( absint( $request['booking_id'] ), (string) self::param( $request, 'token' ) );
		return is_wp_error( $booking ) ? $booking : true;
	}

	private static function param( \WP_REST_Request $request, $key ) {
		$json = $request->get_json_params();
		if ( is_array( $json ) && array_key_exists( $key, $json ) ) { return $json[ $key ]; }
		return $request->get_param( $key );
	}

	/** Public view of a booking (no other customers' data). */
	public static function public_view( $booking ) {
		return array(
			'booking_id' => (int) $booking->booking_id,
			'status' => ServiceBookings::booking_status( $booking ),
			'slot_id' => (int) $booking->slot_id,
			'resource_name' => (string) $booking->resource_name,
			'starts_at' => (string) $booking->starts_at,
			'ends_at' => (string) $booking->ends_at,
			'quantity' => (int) $booking->quantity,
			'expires_at' => $booking->expires_at,
			'rules' => ServiceBookings::rules( $booking ),
		);
	}

	public static function rest_get( \WP_REST_Request $request ) {
		$booking = ServiceBookings::booking( absint( $request['booking_id'] ) );
		if ( ! $booking ) { return new \WP_Error( 'wpcap_booking_not_found', __( 'Buchung nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
		$view = self::public_view( $booking );
		$view['rebook_options'] = array_map( static function ( $slot ) { return array_intersect_key( $slot, array_flip( array( 'slot_id', 'resource_name', 'starts_at', 'ends_at', 'available' ) ) ); }, self::rebook_options( $booking ) );
		return rest_ensure_response( $view );
	}

	public static function rest_confirm( \WP_REST_Request $request ) {
		$args = array( 'actor' => 'customer' );
		foreach ( array( 'provider', 'accept_terms', 'terms_version', 'locale', 'billing', 'code' ) as $key ) {
			$value = self::param( $request, $key );
			if ( null !== $value ) { $args[ $key ] = $value; }
		}
		$result = ServiceBookings::confirm( absint( $request['booking_id'] ), $args );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function rest_rebook( \WP_REST_Request $request ) {
		$result = ServiceBookings::rebook( absint( $request['booking_id'] ), absint( self::param( $request, 'slot_id' ) ), array( 'actor' => 'customer' ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function rest_cancel( \WP_REST_Request $request ) {
		$result = ServiceBookings::cancel( absint( $request['booking_id'] ), array( 'actor' => 'customer', 'reason' => __( 'Storniert durch Kund:in', 'pass-captain' ) ) );
		if ( is_wp_error( $result ) ) { return $result; }
		$refund = $result['refund'] ?? null;
		$result['refund'] = null === $refund ? null : ( is_wp_error( $refund ) ? array( 'status' => 'failed' ) : array( 'status' => (string) ( $refund['status'] ?? '' ), 'amount_minor' => (int) ( $refund['amount_minor'] ?? 0 ) ) );
		return rest_ensure_response( $result );
	}

	/**
	 * Slots a booking can move to: publicly bookable slots of the same event (A133/A134 checks) with
	 * room for its places, inside the customer's rules, and – for bookings with an order – of a
	 * resource with the same ticket type.
	 */
	public static function rebook_options( $booking ) {
		global $wpdb;
		$same_type = null;
		if ( (int) $booking->order_id && ServiceBookings::flow_ready() ) {
			$same_type = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_type_id FROM {$wpdb->prefix}wpcap_resources WHERE resource_id=%d", (int) $booking->resource_id ) );
		}
		$out = array();
		foreach ( ServiceBookings::public_slots( (int) $booking->event_id ) as $slot ) {
			if ( (int) $slot['slot_id'] === (int) $booking->slot_id || (int) $slot['available'] < (int) $booking->quantity ) { continue; }
			if ( null !== $same_type && $same_type !== (int) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_type_id FROM {$wpdb->prefix}wpcap_resources WHERE resource_id=%d", (int) $slot['resource_id'] ) ) ) { continue; }
			$row = ServiceBookings::bookable_slot( (int) $slot['slot_id'] );
			if ( ! $row || ServiceBookings::rule_error( $row, (string) $booking->customer_email, (int) $booking->booking_id ) || ServiceBookings::blocking_slot( $row, (int) $booking->booking_id ) ) { continue; }
			$out[] = $slot;
			if ( count( $out ) >= 50 ) { break; }
		}
		return $out;
	}

	private static function portal_nonce_action( $order ) {
		return 'wpcap_portal_service_' . (string) $order->public_id;
	}

	/** Active, not yet ended bookings with the order's e-mail address (order portal). */
	public static function portal_bookings( $order ) {
		global $wpdb;
		$email = strtolower( trim( (string) ( $order->customer_email ?? '' ) ) );
		if ( '' === $email || Mail\Mailer::is_placeholder( $email ) ) { return array(); }
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT b.booking_id FROM {$wpdb->prefix}wpcap_service_bookings b INNER JOIN {$wpdb->prefix}wpcap_service_slots s ON s.slot_id=b.slot_id WHERE b.customer_email=%s AND b.status IN ('held','pending_payment','confirmed') AND s.ends_at>%s ORDER BY s.starts_at LIMIT 50", $email, current_time( 'mysql', true ) ) );
		$out = array();
		foreach ( $ids as $id ) {
			$booking = ServiceBookings::booking( (int) $id );
			if ( $booking && ServiceBookings::is_active( $booking ) ) { $out[] = $booking; }
		}
		return $out;
	}

	/**
	 * POST from the order portal (nonce per order): cancel or rebook a booking with the order's
	 * e-mail address, as the customer (deadlines and rules apply).
	 *
	 * @return string|\WP_Error 'cancelled' | 'rebooked'.
	 */
	public static function handle_portal_request( $order, array $post ) {
		$nonce = sanitize_text_field( (string) ( $post['_wpcap_service_nonce'] ?? '' ) );
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::portal_nonce_action( $order ) ) ) {
			return new \WP_Error( 'wpcap_service_nonce', __( 'Die Sitzung ist abgelaufen. Bitte lade die Seite neu und versuche es erneut.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		$booking = ServiceBookings::booking( absint( $post['booking_id'] ?? 0 ) );
		$email = strtolower( trim( (string) ( $order->customer_email ?? '' ) ) );
		if ( ! $booking || '' === $email || strtolower( trim( (string) $booking->customer_email ) ) !== $email || Mail\Mailer::is_placeholder( $email ) ) {
			return new \WP_Error( 'wpcap_booking_not_found', __( 'Buchung nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) );
		}
		$op = sanitize_key( (string) ( $post['wpcap_service_op'] ?? '' ) );
		if ( 'cancel' === $op ) {
			$result = ServiceBookings::cancel( (int) $booking->booking_id, array( 'actor' => 'customer', 'reason' => __( 'Storniert im Bestellportal', 'pass-captain' ) ) );
			return is_wp_error( $result ) ? $result : 'cancelled';
		}
		if ( 'rebook' === $op ) {
			$result = ServiceBookings::rebook( (int) $booking->booking_id, absint( $post['slot_id'] ?? 0 ), array( 'actor' => 'customer' ) );
			return is_wp_error( $result ) ? $result : 'rebooked';
		}
		return new \WP_Error( 'wpcap_service_op', __( 'Unbekannte Aktion.', 'pass-captain' ), array( 'status' => 400 ) );
	}

	/** Result flag for the redirect after a portal POST. */
	public static function result_flag( $result ) {
		if ( ! is_wp_error( $result ) ) { return (string) $result; }
		if ( in_array( $result->get_error_code(), array( 'wpcap_booking_cancel_deadline', 'wpcap_booking_rebook_deadline' ), true ) ) { return 'deadline'; }
		if ( in_array( $result->get_error_code(), array( 'wpcap_slot_full', 'wpcap_slot_unavailable', 'wpcap_slot_resource_busy', 'wpcap_slot_provider_busy', 'wpcap_booking_cutoff', 'wpcap_booking_too_early', 'wpcap_booking_customer_limit', 'wpcap_booking_rebook_price' ), true ) ) { return 'unavailable'; }
		return 'error';
	}

	/** Order portal card "Deine Termine" ('' without bookings and result; escaped HTML). */
	public static function portal_html( $order, $result_flag = '' ) {
		$bookings = self::portal_bookings( $order );
		$flag = sanitize_key( (string) $result_flag );
		if ( ! $bookings && '' === $flag ) { return ''; }
		$html = '<section class="card" id="wpcap-service-bookings" aria-labelledby="wpcap-service-title"><h2 id="wpcap-service-title">' . esc_html__( 'Deine Termine', 'pass-captain' ) . '</h2>';
		$notices = array(
			'cancelled' => array( 'status', __( 'Der Termin ist storniert. Bezahlte Termine werden automatisch erstattet.', 'pass-captain' ) ),
			'rebooked' => array( 'status', __( 'Der Termin ist umgebucht. Du erhältst eine Bestätigung per E-Mail.', 'pass-captain' ) ),
			'deadline' => array( 'alert', __( 'Die Frist für diese Änderung ist abgelaufen.', 'pass-captain' ) ),
			'unavailable' => array( 'alert', __( 'Das gewählte Zeitfenster ist nicht mehr verfügbar. Bitte wähle ein anderes.', 'pass-captain' ) ),
			'error' => array( 'alert', __( 'Das hat nicht geklappt. Bitte lade die Seite neu und versuche es erneut.', 'pass-captain' ) ),
		);
		if ( isset( $notices[ $flag ] ) ) { $html .= '<p role="' . esc_attr( $notices[ $flag ][0] ) . '">' . esc_html( $notices[ $flag ][1] ) . '</p>'; }
		if ( ! $bookings ) { return $html . '</section>'; }
		$nonce = wp_create_nonce( self::portal_nonce_action( $order ) );
		$labels = array( 'held' => __( 'Reserviert', 'pass-captain' ), 'pending_payment' => __( 'Zahlung offen', 'pass-captain' ), 'confirmed' => __( 'Bestätigt', 'pass-captain' ) );
		$html .= '<ul>';
		foreach ( $bookings as $booking ) {
			$id = (string) (int) $booking->booking_id;
			$rules = ServiceBookings::rules( $booking );
			/* translators: %d: number of places */
			$places = sprintf( _n( '%d Platz', '%d Plätze', (int) $booking->quantity, 'pass-captain' ), (int) $booking->quantity );
			$html .= '<li><strong>' . esc_html( self::describe( $booking ) ) . '</strong><br>' . esc_html( $places . ' · ' . ( $labels[ $booking->status ] ?? $booking->status ) );
			$hidden = '<input type="hidden" name="booking_id" value="' . esc_attr( $id ) . '"><input type="hidden" name="_wpcap_service_nonce" value="' . esc_attr( $nonce ) . '">';
			if ( 'pending_payment' !== $booking->status && ! ServiceBookings::rebook_deadline_error( $booking ) ) {
				$options = self::rebook_options( $booking );
				if ( $options ) {
					$html .= '<form method="post"><input type="hidden" name="wpcap_service_op" value="rebook">' . $hidden . '<label>' . esc_html__( 'Umbuchen auf', 'pass-captain' ) . ' <select name="slot_id">';
					foreach ( $options as $slot ) {
						$html .= '<option value="' . esc_attr( (string) (int) $slot['slot_id'] ) . '">' . esc_html( self::describe( (object) array( 'resource_name' => $slot['resource_name'], 'starts_at' => $slot['starts_at'], 'event_id' => (int) $booking->event_id ) ) ) . '</option>';
					}
					$html .= '</select></label> <button type="submit">' . esc_html__( 'Umbuchen', 'pass-captain' ) . '</button></form>';
				}
				/* translators: %s: deadline, e.g. "1 Tag" */
				$html .= $rules['rebook_hours'] ? '<p><small>' . esc_html( sprintf( __( 'Umbuchen ist bis %s vor Beginn möglich.', 'pass-captain' ), ServiceBookings::duration_label( $rules['rebook_hours'] * 60 ) ) ) . '</small></p>' : '';
			}
			if ( 'confirmed' !== $booking->status || ! ServiceBookings::cancel_deadline_error( $booking ) ) {
				$html .= '<form method="post" class="wpcap-service-cancel"><input type="hidden" name="wpcap_service_op" value="cancel">' . $hidden . '<button type="submit">' . esc_html__( 'Termin stornieren', 'pass-captain' ) . '</button></form>';
				/* translators: %s: deadline, e.g. "2 Tage" */
				$html .= 'confirmed' === $booking->status && $rules['cancel_hours'] ? '<p><small>' . esc_html( sprintf( __( 'Kostenlos stornieren mit voller Erstattung bis %s vor Beginn.', 'pass-captain' ), ServiceBookings::duration_label( $rules['cancel_hours'] * 60 ) ) ) . '</small></p>' : '';
			} else {
				$html .= '<p><small>' . esc_html__( 'Die Stornofrist ist abgelaufen.', 'pass-captain' ) . '</small></p>';
			}
			$html .= '</li>';
		}
		return $html . '</ul></section>';
	}
}

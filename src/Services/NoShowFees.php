<?php
/**
 * No-show fee by payment link (owner decision E-18, 25.11.2026): the fee noted on a no-show booking
 * (resource rule noshow_policy "forfeit", noshow_fee_minor) is requested with a personal payment link
 * by e-mail with a due date. No automatic debit, no dunning: an unpaid fee stays "open" after the due
 * date until staff send a new link (new due date) or cancel it.
 *
 * - Fee state per booking in wpcap_service_noshow_fees (module schema): open | paid | cancelled. A
 *   no-show booking with a fee and no row yet counts as open (nothing sent).
 * - send(): staff action (Ressourcen & Zeitfenster → No-Show-Gebühren); creates the row, a new link
 *   token (an earlier link stops working) and the due date, mails `service_noshow_fee_link`.
 * - The link (?wpcap_noshow_fee=ID&token=…) shows amount, due date and the enabled payment methods;
 *   paying is a POST (Services\Portal, action noshow_fee_pay) that creates a payment order and starts
 *   the provider payment (Stripe, PayPal, Mollie: hosted page; bank transfer: payment details).
 * - The payment order is an ordinary order (so webhooks, reconciliation, return pages, refunds and
 *   invoices work as for every payment): one line of the resource's ticket type with quantity 0 (no
 *   ticket, no capacity) for the fee, and a hold of quantity 0 until the due date. Its price snapshot
 *   names the fee (`noshow_fee`).
 * - `wpcap_order_paid` → the fee is paid (idempotent), mail `service_noshow_fee_paid` with the link to
 *   the order (receipt; the invoice is issued like for every order with the setting auto_invoice).
 * - cancel(): staff action; the fee is cancelled and an unpaid payment order cancelled with it.
 *
 * Docs: docs/SERVICE-BOOKINGS.md (No-Show-Gebühr per Zahlungslink).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Services;

use PassCaptain\Audit;
use PassCaptain\Clock;
use PassCaptain\Mail\Mailer;
use PassCaptain\Money;
use PassCaptain\Orders;
use PassCaptain\Payments;
use PassCaptain\ServiceBookings;
use PassCaptain\ServiceResources;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class NoShowFees {
	const STATUSES = array( 'open', 'paid', 'cancelled' );

	/** Default and limits of the due date (days after sending). */
	const DUE_DAYS = 14;
	const DUE_DAYS_MAX = 90;

	/** Module schema (Modules\Services::schema()). */
	public static function schema( $prefix, $charset ) {
		return array(
			"CREATE TABLE {$prefix}wpcap_service_noshow_fees (
				fee_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				booking_id bigint(20) unsigned NOT NULL,
				amount_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				currency char(3) NOT NULL DEFAULT '',
				status varchar(16) NOT NULL DEFAULT 'open',
				token_hash char(64) NOT NULL DEFAULT '',
				due_at datetime NULL,
				sent_at datetime NULL,
				sent_count smallint(5) unsigned NOT NULL DEFAULT 0,
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				paid_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				paid_at datetime NULL,
				cancelled_at datetime NULL,
				cancelled_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (fee_id),
				UNIQUE KEY booking_id (booking_id),
				KEY order_id (order_id),
				KEY status (status)
			) ENGINE=InnoDB $charset;",
		);
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wpcap_service_noshow_fees';
	}

	/** Hooks (Modules\Services::register()). */
	public static function register() {
		add_action( 'wpcap_order_paid', array( __CLASS__, 'on_order_paid' ), 40, 1 );
		add_filter( 'wpcap_invoice_items', array( __CLASS__, 'invoice_items' ), 10, 2 );
	}

	public static function get( $fee_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE fee_id=%d', self::table(), absint( $fee_id ) ) );
	}

	public static function for_booking( $booking_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE booking_id=%d', self::table(), absint( $booking_id ) ) );
	}

	/** Fee status of a booking: the stored one, else "open" (fee noted, nothing sent yet). */
	public static function status_of( $booking, $fee = null ) {
		$fee = null === $fee ? self::for_booking( (int) $booking->booking_id ) : $fee;
		return $fee ? (string) $fee->status : 'open';
	}

	public static function status_label( $status ) {
		$labels = array( 'open' => __( 'Offen', 'pass-captain' ), 'paid' => __( 'Bezahlt', 'pass-captain' ), 'cancelled' => __( 'Storniert', 'pass-captain' ) );
		return $labels[ $status ] ?? (string) $status;
	}

	/**
	 * No-show bookings with a fee in the given resources (staff list), newest first, with the fee row.
	 *
	 * @param int[]  $resource_ids Resources the user may manage.
	 * @param string $status       '' | open | paid | cancelled.
	 * @return object[] booking columns + resource_name, starts_at, fee_* columns.
	 */
	public static function listing( array $resource_ids, $status = '', $limit = 200 ) {
		global $wpdb;
		$resource_ids = array_values( array_filter( array_map( 'absint', $resource_ids ) ) );
		if ( ! $resource_ids ) { return array(); }
		$p = $wpdb->prefix;
		$in = implode( ',', $resource_ids );
		$where = '';
		if ( 'open' === $status ) {
			$where = " AND (f.status IS NULL OR f.status='open')";
		} elseif ( in_array( $status, array( 'paid', 'cancelled' ), true ) ) {
			$where = $wpdb->prepare( ' AND f.status=%s', $status );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- absint ids and prepared status condition.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT b.booking_id,b.customer_email,b.quantity,b.fee_minor,b.currency,b.noshow_at,s.starts_at,s.resource_id,r.name AS resource_name,r.timezone AS resource_timezone,f.fee_id,f.status AS fee_status,f.amount_minor AS fee_amount,f.currency AS fee_currency,f.due_at,f.sent_at,f.sent_count,f.paid_at,f.cancelled_at,f.order_id AS fee_order_id,f.paid_order_id FROM %i b INNER JOIN %i s ON s.slot_id=b.slot_id INNER JOIN %i r ON r.resource_id=s.resource_id LEFT JOIN %i f ON f.booking_id=b.booking_id WHERE b.status='no_show' AND b.fee_minor>0 AND s.resource_id IN ($in)$where ORDER BY b.noshow_at DESC, b.booking_id DESC LIMIT %d", $p . 'wpcap_service_bookings', $p . 'wpcap_service_slots', $p . 'wpcap_resources', self::table(), absint( $limit ) ) );
	}

	/* ================================================================== Staff actions */

	/**
	 * Sends (or sends again) the payment link of a no-show fee. A new token replaces the earlier link;
	 * the due date is set anew (days after now). An unpaid payment order keeps going, its hold moves
	 * to the new due date. Caller checks the rights (Admin\ServicesPage).
	 *
	 * @param array $args due_days (1–90, default 14).
	 * @return array|\WP_Error fee state + pay_url (the link that was mailed).
	 */
	public static function send( $booking_id, array $args = array() ) {
		global $wpdb;
		$context = self::fee_context( $booking_id );
		if ( is_wp_error( $context ) ) { return $context; }
		list( $booking, , $resource, $type ) = $context;
		if ( ! Payments\Registry::enabled( (string) $type->currency ) ) {
			return new \WP_Error( 'wpcap_noshow_fee_no_provider', __( 'Für die Währung dieser Gebühr ist keine Zahlungsart aktiviert.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$fee = self::ensure_row( $booking, $type );
		if ( is_wp_error( $fee ) ) { return $fee; }
		if ( 'open' !== $fee->status ) { return self::closed_error( $fee ); }
		$days = isset( $args['due_days'] ) ? (int) $args['due_days'] : self::DUE_DAYS;
		if ( $days < 1 || $days > self::DUE_DAYS_MAX ) {
			/* translators: %d: maximum number of days */
			return new \WP_Error( 'wpcap_noshow_fee_due_days', sprintf( __( 'Die Zahlungsfrist muss zwischen 1 und %d Tagen liegen.', 'pass-captain' ), self::DUE_DAYS_MAX ), array( 'status' => 400 ) );
		}
		$now = Clock::mysql();
		$due = gmdate( 'Y-m-d H:i:s', Clock::now() + $days * DAY_IN_SECONDS );
		$token = bin2hex( random_bytes( 32 ) );
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE %i SET token_hash=%s,due_at=%s,sent_at=%s,sent_count=sent_count+1,updated_at=%s WHERE fee_id=%d AND status='open'", self::table(), hash( 'sha256', $token ), $due, $now, $now, (int) $fee->fee_id ) );
		if ( 1 !== (int) $updated ) { return self::db_failed() ? \PassCaptain\Errors::busy() : self::closed_error( self::get( (int) $fee->fee_id ) ); }
		if ( (int) $fee->order_id ) {
			// The open payment order follows the new due date (its hold is the bank transfer due date too).
			$wpdb->query( $wpdb->prepare( "UPDATE %i SET expires_at=%s WHERE order_id=%d AND status='active'", $wpdb->prefix . 'wpcap_reservations', $due, (int) $fee->order_id ) );
		}
		$fee = self::get( (int) $fee->fee_id );
		$url = self::pay_url( (int) $fee->fee_id, $token );
		$vars = self::mail_vars( $booking, $fee, $resource );
		$vars['pay_link'] = Mailer::link( $url );
		$sent = Mailer::send( 'service_noshow_fee_link', (string) $booking->customer_email, $vars, array(), self::locale_of( $booking ) );
		Audit::record( 'service_noshow_fee_sent', 'service_booking', (int) $booking->booking_id, array( 'fee_id' => (int) $fee->fee_id, 'amount_minor' => (int) $fee->amount_minor, 'currency' => (string) $fee->currency, 'due_at' => $due, 'resent' => (int) $fee->sent_count > 1, 'mail' => (bool) $sent, 'user_id' => get_current_user_id() ) );
		return self::state( $fee ) + array( 'pay_url' => $url, 'mail_sent' => (bool) $sent );
	}

	/**
	 * Cancels a no-show fee (also one that was never sent): status cancelled, an unpaid payment order
	 * is cancelled with it. A paid fee is refused (refund it through the order).
	 *
	 * @return array|\WP_Error fee state.
	 */
	public static function cancel( $booking_id, $note = '' ) {
		global $wpdb;
		$booking = ServiceBookings::get( $booking_id );
		if ( ! $booking || (int) $booking->fee_minor <= 0 ) { return self::no_fee(); }
		$fee = self::for_booking( (int) $booking->booking_id );
		if ( ! $fee ) {
			$context = self::fee_context( $booking_id, false );
			$fee = is_wp_error( $context ) ? $context : self::ensure_row( $booking, $context[3] );
			if ( is_wp_error( $fee ) ) { return $fee; }
		}
		if ( 'cancelled' === $fee->status ) { return self::state( $fee ); }
		if ( 'open' !== $fee->status ) { return self::closed_error( $fee ); }
		$order = (int) $fee->order_id ? Orders::get( (int) $fee->order_id ) : null;
		if ( $order && 'paid' === (string) $order->payment_status ) { return self::closed_error( $fee ); }
		$now = Clock::mysql();
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='cancelled',token_hash='',cancelled_at=%s,cancelled_by=%d,updated_at=%s WHERE fee_id=%d AND status='open'", self::table(), $now, get_current_user_id(), $now, (int) $fee->fee_id ) );
		if ( 1 !== (int) $changed ) { return self::db_failed() ? \PassCaptain\Errors::busy() : self::closed_error( self::get( (int) $fee->fee_id ) ); }
		if ( $order && 'pending' === (string) $order->payment_status ) { Orders::cancel_order( (int) $order->order_id, 'noshow_fee_cancelled', array( 'pending' ) ); }
		Audit::record( 'service_noshow_fee_cancelled', 'service_booking', (int) $booking->booking_id, array( 'fee_id' => (int) $fee->fee_id, 'order_id' => (int) $fee->order_id, 'note' => \PassCaptain\Text::cut( sanitize_text_field( (string) $note ), 190 ), 'user_id' => get_current_user_id() ) );
		return self::state( self::get( (int) $fee->fee_id ) );
	}

	/* ================================================================== Payment link */

	/** Public URL of the payment link. */
	public static function pay_url( $fee_id, $token ) {
		return add_query_arg( array( 'wpcap_noshow_fee' => absint( $fee_id ), 'token' => (string) $token ), home_url( '/' ) );
	}

	/** Fee row of a link (token compared in constant time), or null. */
	public static function with_token( $fee_id, $token ) {
		$fee = self::get( $fee_id );
		$token = (string) $token;
		if ( ! $fee || 64 !== strlen( $token ) || '' === (string) $fee->token_hash || ! hash_equals( (string) $fee->token_hash, hash( 'sha256', $token ) ) ) { return null; }
		return $fee;
	}

	/** Whether the due date of an open fee has passed. */
	public static function overdue( $fee ) {
		return $fee && $fee->due_at && (string) $fee->due_at <= Clock::mysql();
	}

	/**
	 * Content of the link page (Services\Portal): amount, booking, due date and – while the fee is open
	 * and due – a POST form with the enabled payment methods.
	 *
	 * @return string HTML ('' for an invalid link).
	 */
	public static function page_html( $fee_id, $token ) {
		$fee = self::with_token( $fee_id, $token );
		if ( ! $fee ) { return '<p>' . esc_html__( 'Der Zahlungslink ist ungültig oder wurde durch einen neueren Link ersetzt.', 'pass-captain' ) . '</p>'; }
		$booking = ServiceBookings::get( (int) $fee->booking_id );
		$resource = $booking ? ServiceBookings::resource_of( $booking ) : null;
		$vars = $booking ? self::mail_vars( $booking, $fee, $resource ) : array();
		$html = '<div class="card"><p><strong>' . esc_html( Money::format( (int) $fee->amount_minor, (string) $fee->currency ) ) . '</strong></p>';
		if ( $vars ) {
			/* translators: 1: resource, 2: date and time of the slot */
			$html .= '<p>' . esc_html( sprintf( __( 'No-Show-Gebühr für den nicht wahrgenommenen Termin %1$s am %2$s.', 'pass-captain' ), $vars['resource_name'], $vars['slot_date'] ) ) . '</p>';
			/* translators: %s: due date */
			$html .= '<p>' . esc_html( sprintf( __( 'Zahlbar bis: %s', 'pass-captain' ), $vars['due_date'] ) ) . '</p>';
		}
		$html .= '</div>';
		if ( 'paid' === $fee->status ) { return $html . '<p>' . esc_html__( 'Diese Gebühr ist bezahlt. Vielen Dank!', 'pass-captain' ) . '</p>'; }
		if ( 'cancelled' === $fee->status ) { return $html . '<p>' . esc_html__( 'Diese Gebühr wurde storniert. Es ist nichts zu bezahlen.', 'pass-captain' ) . '</p>'; }
		if ( self::overdue( $fee ) ) { return $html . '<p>' . esc_html__( 'Die Zahlungsfrist ist abgelaufen. Bitte wende dich an den Veranstalter, wenn du die Gebühr noch bezahlen möchtest.', 'pass-captain' ) . '</p>'; }
		$gateways = Payments\Registry::enabled( (string) $fee->currency );
		if ( ! $gateways ) { return $html . '<p>' . esc_html__( 'Zurzeit ist keine Zahlungsart verfügbar. Bitte versuche es später erneut.', 'pass-captain' ) . '</p>'; }
		$current = (int) $fee->order_id ? (string) ( Orders::get( (int) $fee->order_id )->payment_provider ?? '' ) : '';
		$select = '<p><label for="wpcap-noshow-provider">' . esc_html__( 'Zahlungsart', 'pass-captain' ) . '</label><br><select id="wpcap-noshow-provider" name="provider">';
		foreach ( $gateways as $id => $gateway ) {
			$select .= '<option value="' . esc_attr( (string) $id ) . '"' . selected( $current, (string) $id, false ) . '>' . esc_html( (string) $gateway->title() ) . '</option>';
		}
		$select .= '</select></p>';
		return $html . Portal::form( array( 'do' => 'noshow_fee_pay', 'id' => (int) $fee->fee_id, 'token' => (string) $token ), __( 'Jetzt bezahlen', 'pass-captain' ), $select );
	}

	/**
	 * Starts the payment of an open fee with the chosen provider (Services\Portal action noshow_fee_pay):
	 * reuses the unpaid payment order of that provider while its hold runs, else creates a new one (an
	 * unpaid order of another provider is cancelled first).
	 *
	 * @return array|\WP_Error status noshow_fee_pending + noshow_fee {amount_minor, currency, due_at, payment}.
	 */
	public static function pay( $fee_id, $token, $provider = '' ) {
		$fee = self::with_token( $fee_id, $token );
		if ( ! $fee ) { return new \WP_Error( 'wpcap_order_access_denied', __( 'Der Zugriff ist ungültig oder abgelaufen.', 'pass-captain' ), array( 'status' => 403 ) ); }
		if ( 'open' !== $fee->status ) { return self::closed_error( $fee ); }
		if ( self::overdue( $fee ) ) { return new \WP_Error( 'wpcap_noshow_fee_overdue', __( 'Die Zahlungsfrist ist abgelaufen. Bitte wende dich an den Veranstalter, wenn du die Gebühr noch bezahlen möchtest.', 'pass-captain' ), array( 'status' => 409 ) ); }
		$enabled = Payments\Registry::enabled( (string) $fee->currency );
		$provider = sanitize_key( (string) $provider );
		$gateway = '' !== $provider ? ( $enabled[ $provider ] ?? null ) : ( $enabled ? reset( $enabled ) : null );
		if ( ! $gateway ) { return new \WP_Error( 'wpcap_provider_unavailable', __( 'Diese Zahlungsart ist nicht verfügbar.', 'pass-captain' ), array( 'status' => 400 ) ); }
		$order = (int) $fee->order_id ? Orders::get( (int) $fee->order_id ) : null;
		if ( $order && 'paid' === (string) $order->payment_status ) { return self::closed_error( $fee ); }
		$reusable = $order && 'pending' === (string) $order->payment_status && 'pending_payment' === (string) $order->status && $gateway->id() === (string) $order->payment_provider && Orders::hold_end( (int) $order->order_id ) > Clock::now();
		if ( ! $reusable ) {
			if ( $order && 'pending' === (string) $order->payment_status ) { Orders::cancel_order( (int) $order->order_id, 'noshow_fee_provider_changed', array( 'pending' ) ); }
			$created = self::create_order( $fee, $gateway->id() );
			if ( is_wp_error( $created ) ) { return $created; }
			$order = Orders::get( $created );
		}
		$row = (array) $order;
		$payment = $gateway->create_payment( $row, self::payment_items( $fee, $order ) );
		if ( is_wp_error( $payment ) ) {
			Orders::cancel_order( (int) $order->order_id, 'payment_start_failed', array( 'pending' ) );
			return Payments\StartFailures::handle( $gateway->id(), (int) $order->order_id, $payment, 'noshow_fee', 'wpcap_noshow_fee_payment' );
		}
		if ( ! empty( $payment['provider_reference'] ) ) { Orders::set_provider_reference( (int) $order->order_id, $payment['provider_reference'] ); }
		$out = array_intersect_key( (array) $payment, array_flip( array( 'provider', 'status', 'redirect_url', 'instructions' ) ) );
		if ( Payments\BankTransfer::applies( $row ) ) { $out['bank_transfer'] = Payments\BankTransfer::details( $row ); }
		Audit::record( 'service_noshow_fee_payment_started', 'service_booking', (int) $fee->booking_id, array( 'fee_id' => (int) $fee->fee_id, 'order_id' => (int) $order->order_id, 'provider' => $gateway->id(), 'reused' => $reusable ) );
		return array( 'status' => 'noshow_fee_pending', 'noshow_fee' => array( 'fee_id' => (int) $fee->fee_id, 'order_id' => (int) $order->order_id, 'amount_minor' => (int) $fee->amount_minor, 'currency' => (string) $fee->currency, 'due_at' => (string) $fee->due_at, 'payment' => $out ) );
	}

	/** Result page content after noshow_fee_pay without a redirect (bank transfer, instructions). */
	public static function result_html( array $result ) {
		$fee = (array) ( $result['noshow_fee'] ?? array() );
		$payment = (array) ( $fee['payment'] ?? array() );
		/* translators: %s: amount */
		$html = '<p>' . esc_html( sprintf( __( 'Bitte bezahle die No-Show-Gebühr von %s.', 'pass-captain' ), Money::format( (int) ( $fee['amount_minor'] ?? 0 ), (string) ( $fee['currency'] ?? '' ) ) ) ) . '</p>';
		if ( ! empty( $payment['redirect_url'] ) ) { $html .= '<p><a href="' . esc_url( (string) $payment['redirect_url'] ) . '">' . esc_html__( 'Weiter zur Zahlung', 'pass-captain' ) . '</a></p>'; }
		$bank = (array) ( $payment['bank_transfer'] ?? array() );
		if ( ! empty( $bank['transfer'] ) ) {
			$html .= '<dl>';
			foreach ( Payments\BankTransfer::rows( $bank ) as $label => $value ) { $html .= '<dt>' . esc_html( (string) $label ) . '</dt><dd>' . esc_html( (string) $value ) . '</dd>'; }
			$html .= '</dl>';
		}
		$instructions = (string) ( $bank['instructions'] ?? $payment['instructions'] ?? '' );
		if ( '' !== $instructions ) { $html .= '<p>' . nl2br( esc_html( $instructions ) ) . '</p>'; }
		return $html;
	}

	/* ================================================================== Order hooks */

	/** Fee id named by an order's price snapshot (0 for other orders). */
	public static function fee_of_order( $order ) {
		if ( ! $order ) { return 0; }
		$snapshot = json_decode( (string) ( $order->price_snapshot ?? '' ), true );
		return is_array( $snapshot ) && is_array( $snapshot['noshow_fee'] ?? null ) ? absint( $snapshot['noshow_fee']['fee_id'] ?? 0 ) : 0;
	}

	/**
	 * `wpcap_order_paid`: the payment order of a fee was paid → fee paid (once), mail with the link to the
	 * order (receipt/invoice). A payment for a fee that is no longer open (paid by another order,
	 * cancelled) is only logged for the admin, who refunds it through the order.
	 */
	public static function on_order_paid( $order_id ) {
		global $wpdb;
		$order = Orders::get( $order_id );
		$fee = self::get( self::fee_of_order( $order ) );
		if ( ! $order || ! $fee ) { return; }
		if ( (int) $fee->paid_order_id === (int) $order->order_id ) { return; }
		$now = Clock::mysql();
		$changed = 'open' === $fee->status ? $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='paid',paid_order_id=%d,order_id=%d,paid_at=%s,token_hash='',updated_at=%s WHERE fee_id=%d AND status='open'", self::table(), (int) $order->order_id, (int) $order->order_id, $now, $now, (int) $fee->fee_id ) ) : 0;
		if ( 1 !== (int) $changed ) {
			Audit::record( 'service_noshow_fee_payment_unmatched', 'order', (int) $order->order_id, array( 'fee_id' => (int) $fee->fee_id, 'fee_status' => (string) $fee->status ) );
			\PassCaptain\Log::warning( 'service_noshow_fee', 'Payment for a no-show fee that is no longer open; refund it through the order.', array( 'object_type' => 'order', 'object_id' => (int) $order->order_id, 'details' => array( 'fee_id' => (int) $fee->fee_id, 'fee_status' => (string) $fee->status ) ) );
			return;
		}
		$fee = self::get( (int) $fee->fee_id );
		Audit::record( 'service_noshow_fee_paid', 'service_booking', (int) $fee->booking_id, array( 'fee_id' => (int) $fee->fee_id, 'order_id' => (int) $order->order_id, 'amount_minor' => (int) $order->total_minor, 'provider' => (string) $order->payment_provider ) );
		$booking = ServiceBookings::get( (int) $fee->booking_id );
		if ( ! $booking ) { return; }
		$token = Orders::issue_access_token( (int) $order->order_id );
		$vars = self::mail_vars( $booking, $fee, ServiceBookings::resource_of( $booking ) );
		$vars['receipt_link'] = '' !== $token ? Mailer::link( add_query_arg( array( 'wpcap_order' => (string) $order->public_id, 'wpcap_access' => $token ), home_url( '/' ) ) ) : '';
		$invoice = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT invoice_number FROM %i WHERE order_id=%d', $wpdb->prefix . 'wpcap_invoices', (int) $order->order_id ) );
		/* translators: %s: invoice number */
		$vars['invoice_info'] = '' !== $invoice ? sprintf( __( 'Rechnungsnummer: %s', 'pass-captain' ), $invoice ) : '';
		Mailer::send( 'service_noshow_fee_paid', (string) $booking->customer_email, $vars, array(), (string) $order->locale, (int) $order->order_id );
	}

	/** `wpcap_invoice_items`: the fee line of a payment order is named as such, with the date of the missed slot. */
	public static function invoice_items( $items, $order = null ) {
		$fee = self::get( self::fee_of_order( is_object( $order ) ? $order : null ) );
		$booking = $fee ? ServiceBookings::get( (int) $fee->booking_id ) : null;
		$slot = $booking ? ServiceResources::slot( (int) $booking->slot_id ) : null;
		if ( ! $slot ) { return $items; }
		$resource = ServiceResources::resource( (int) $slot->resource_id );
		foreach ( (array) $items as $item ) {
			if ( ! is_object( $item ) ) { continue; }
			/* translators: %s: resource */
			$item->name = sprintf( __( 'No-Show-Gebühr %s', 'pass-captain' ), (string) $slot->resource_name );
			$item->starts_at = (string) $slot->starts_at;
			$item->ends_at = (string) $slot->ends_at;
			$item->timezone = ServiceResources::timezone_of( $resource );
		}
		return $items;
	}

	/* ================================================================== Internals */

	/**
	 * Booking, slot, resource and price ticket type of a fee; refused without a fee, for a booking that
	 * is no longer a no-show ($require_no_show) and for a free resource (no ticket type to book on).
	 *
	 * @return array|\WP_Error [booking, slot, resource, type]
	 */
	private static function fee_context( $booking_id, $require_no_show = true ) {
		global $wpdb;
		$booking = ServiceBookings::get( $booking_id );
		if ( ! $booking || (int) $booking->fee_minor <= 0 ) { return self::no_fee(); }
		if ( $require_no_show && 'no_show' !== (string) $booking->status ) {
			return new \WP_Error( 'wpcap_noshow_fee_state', __( 'Die Buchung ist nicht (mehr) als „nicht erschienen“ vermerkt.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$slot = ServiceResources::slot( (int) $booking->slot_id );
		$resource = $slot ? ServiceResources::resource( (int) $slot->resource_id ) : null;
		$type = $resource && (int) $resource->ticket_type_id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE ticket_type_id=%d', $wpdb->prefix . 'wpcap_ticket_types', (int) $resource->ticket_type_id ) ) : null;
		if ( ! $slot || ! $resource || ! $type ) {
			return new \WP_Error( 'wpcap_noshow_fee_free_resource', __( 'Ein Zahlungslink braucht eine Ressource mit Preis (Ticketart). Bei kostenlosen Ressourcen wird die Gebühr außerhalb von Pass Captain eingezogen.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		return array( $booking, $slot, $resource, $type );
	}

	/** Fee row of a booking, created on first use (amount and currency frozen from the booking). */
	private static function ensure_row( $booking, $type ) {
		global $wpdb;
		$fee = self::for_booking( (int) $booking->booking_id );
		if ( $fee ) { return $fee; }
		$now = Clock::mysql();
		$currency = strtoupper( (string) ( '' !== (string) $booking->currency ? $booking->currency : $type->currency ) );
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i (booking_id,amount_minor,currency,status,created_at,updated_at) VALUES (%d,%d,%s,'open',%s,%s)", self::table(), (int) $booking->booking_id, (int) $booking->fee_minor, $currency, $now, $now ) );
		$fee = self::for_booking( (int) $booking->booking_id );
		return $fee ? $fee : \PassCaptain\Errors::busy();
	}

	/**
	 * Payment order of a fee (one transaction): order row, fee line (resource's ticket type, quantity 0,
	 * tax as the ticket type), hold of quantity 0 until the due date; the fee points to it.
	 *
	 * @return int|\WP_Error order id.
	 */
	private static function create_order( $fee, $provider ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$context = self::fee_context( (int) $fee->booking_id, false );
		if ( is_wp_error( $context ) ) { return $context; }
		list( $booking, $slot, , $type ) = $context;
		$origin = (int) $booking->order_id ? Orders::get( (int) $booking->order_id ) : null;
		$amount = (int) $fee->amount_minor;
		$tax_info = \PassCaptain\Tax\Profiles::resolve( $type, null, '', 'noshow_fee' );
		$rate = (int) $tax_info['rate_bp'];
		$tax = Orders::gross_tax( $amount, $rate );
		$now = Clock::mysql();
		$due = (string) $fee->due_at;
		$public_id = bin2hex( random_bytes( 16 ) );
		$order_row = array(
			'public_id' => $public_id,
			'idempotency_key' => hash( 'sha256', 'noshow-fee|' . (int) $fee->fee_id . '|' . wp_generate_uuid4() ),
			'customer_email' => (string) $booking->customer_email,
			'customer_name' => $origin ? (string) $origin->customer_name : '',
			'access_token_hash' => hash( 'sha256', bin2hex( random_bytes( 32 ) ) ),
			'access_expires_at' => gmdate( 'Y-m-d H:i:s', max( Clock::now(), (int) strtotime( $due . ' UTC' ) ) + 30 * DAY_IN_SECONDS ),
			'status' => 'pending_payment',
			'payment_status' => 'pending',
			'currency' => (string) $fee->currency,
			'total_minor' => $amount,
			'subtotal_minor' => $amount,
			'discount_minor' => 0,
			'price_snapshot' => (string) wp_json_encode( array( 'currency' => (string) $fee->currency, 'subtotal_minor' => $amount, 'discount_minor' => 0, 'total_minor' => $amount, 'campaigns' => array(), 'noshow_fee' => array( 'fee_id' => (int) $fee->fee_id, 'booking_id' => (int) $booking->booking_id, 'slot_id' => (int) $slot->slot_id ) ) ),
			'tax_minor' => $tax,
			'payment_provider' => sanitize_key( (string) $provider ),
			'terms_version' => '',
			'locale' => $origin ? (string) $origin->locale : '',
			'created_at' => $now,
			'updated_at' => $now,
		);
		if ( $origin && ! empty( $origin->billing ) ) { $order_row['billing'] = (string) $origin->billing; }
		$wpdb->query( 'START TRANSACTION' );
		$locked = $wpdb->get_row( $wpdb->prepare( 'SELECT status,order_id FROM %i WHERE fee_id=%d FOR UPDATE', self::table(), (int) $fee->fee_id ) );
		if ( ! $locked || 'open' !== $locked->status || (int) $locked->order_id !== (int) $fee->order_id ) {
			$wpdb->query( 'ROLLBACK' );
			return $locked && 'open' !== $locked->status ? self::closed_error( $locked ) : \PassCaptain\Errors::busy();
		}
		$ok = $wpdb->insert( "{$p}wpcap_orders", $order_row );
		$order_id = (int) $wpdb->insert_id;
		$ok = $ok && $order_id && $wpdb->insert( "{$p}wpcap_order_items", array( 'order_id' => $order_id, 'ticket_type_id' => (int) $type->ticket_type_id, 'quantity' => 0, 'unit_price_minor' => $amount, 'line_total_minor' => $amount, 'tax_rate_bp' => $rate, 'tax_minor' => $tax, 'refunded_quantity' => 0 ) + \PassCaptain\Tax\Profiles::line_columns( $tax_info ) );
		$ok = $ok && $wpdb->insert( "{$p}wpcap_reservations", array( 'order_id' => $order_id, 'ticket_type_id' => (int) $type->ticket_type_id, 'quantity' => 0, 'expires_at' => $due, 'status' => 'active' ) );
		$ok = $ok && false !== $wpdb->update( self::table(), array( 'order_id' => $order_id, 'updated_at' => $now ), array( 'fee_id' => (int) $fee->fee_id ) );
		if ( ! $ok || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return \PassCaptain\Errors::busy();
		}
		Audit::record( 'order_created', 'order', $order_id, array( 'total_minor' => $amount, 'currency' => (string) $fee->currency, 'source' => 'noshow_fee' ) );
		return $order_id;
	}

	/** Line shown at the payment provider. */
	private static function payment_items( $fee, $order ) {
		$booking = ServiceBookings::get( (int) $fee->booking_id );
		$slot = $booking ? ServiceResources::slot( (int) $booking->slot_id ) : null;
		/* translators: %s: resource */
		$name = sprintf( __( 'No-Show-Gebühr %s', 'pass-captain' ), $slot ? (string) $slot->resource_name : '' );
		return array( array( 'name' => $name, 'event_name' => '', 'quantity' => 1, 'unit_price_minor' => (int) $order->total_minor, 'line_total_minor' => (int) $order->total_minor ) );
	}

	/** Mail placeholders: those of the booking plus fee_amount and due_date. */
	private static function mail_vars( $booking, $fee, $res ) {
		$slot = ServiceResources::slot( (int) $booking->slot_id );
		$vars = ServiceBookings::mail_vars( $booking, $slot, $res );
		$vars['fee_amount'] = Money::format( (int) $fee->amount_minor, (string) $fee->currency );
		$vars['due_date'] = $fee->due_at ? \PassCaptain\Frontend\Format::deadline( (string) $fee->due_at, ServiceResources::timezone_of( $res ) ) : '';
		return $vars;
	}

	private static function locale_of( $booking ) {
		return (int) $booking->order_id ? (string) ( Orders::get( (int) $booking->order_id )->locale ?? '' ) : '';
	}

	/** Public state of a fee. */
	public static function state( $fee ) {
		return array( 'fee_id' => (int) $fee->fee_id, 'booking_id' => (int) $fee->booking_id, 'status' => (string) $fee->status, 'amount_minor' => (int) $fee->amount_minor, 'currency' => (string) $fee->currency, 'due_at' => (string) $fee->due_at, 'sent_at' => (string) $fee->sent_at, 'sent_count' => (int) $fee->sent_count, 'order_id' => (int) $fee->order_id, 'paid_order_id' => (int) $fee->paid_order_id );
	}

	private static function no_fee() {
		return new \WP_Error( 'wpcap_noshow_fee_missing', __( 'Für diese Buchung ist keine No-Show-Gebühr vermerkt.', 'pass-captain' ), array( 'status' => 404 ) );
	}

	private static function closed_error( $fee ) {
		$paid = $fee && 'paid' === (string) $fee->status;
		return new \WP_Error( 'wpcap_noshow_fee_closed', $paid ? __( 'Diese Gebühr ist bereits bezahlt.', 'pass-captain' ) : __( 'Diese Gebühr wurde storniert.', 'pass-captain' ), array( 'status' => 409, 'fee_status' => $fee ? (string) $fee->status : '' ) );
	}

	/** Whether the last query failed (impure, so static analysis does not treat last_error as constant). */
	private static function db_failed() {
		global $wpdb;
		return '' !== (string) $wpdb->last_error;
	}
}

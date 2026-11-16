<?php
/**
 * Orders admin: filtered list, order detail and order actions (manual payment, cancellation,
 * ticket resend, refunds incl. second approval).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Audit;
use PassCaptain\Orders;
use PassCaptain\Refunds;
use PassCaptain\Security\AuditTrail;
use PassCaptain\Security\DataPolicy;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class OrdersPage {
	const SLUG = 'wpcap-orders';
	const CAP = 'wpcap_manage_orders';
	const ORDER_STATUSES = array( 'pending_payment', 'complete', 'cancelled', 'refunded', 'expired', 'needs_review' );
	const PAYMENT_STATUSES = array( 'pending', 'paid', 'expired', 'cancelled', 'refunded', 'paid_unallocated' );

	/** Capability that allows requesting refunds (Refunds::REFUND_CAP when defined). */
	public static function refund_cap() {
		return defined( Refunds::class . '::REFUND_CAP' ) ? constant( Refunds::class . '::REFUND_CAP' ) : self::CAP;
	}

	/** Whether the current user may request refunds at all. */
	public static function can_request_refunds() {
		return current_user_can( self::refund_cap() ) || current_user_can( Refunds::APPROVE_CAP );
	}

	/** Loads an order the current user may act on. */
	private static function order_for_action( array $in ) {
		$order = Orders::get( Forms::int( $in, 'order_id' ) );
		if ( ! $order ) { return Forms::error( 'wpcap_order_missing', __( 'Bestellung nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! Queries::can_access_order( $order->order_id, self::CAP ) ) { return Forms::forbidden( __( 'Keine Berechtigung für diese Bestellung.', 'pass-captain' ) ); }
		return $order;
	}

	/* =============================================================================================
	 * Processing
	 * =========================================================================================== */

	/** Confirms a manual payment (manual provider only). */
	public static function confirm_payment( array $in ) {
		$order = self::order_for_action( $in );
		if ( is_wp_error( $order ) ) { return $order; }
		if ( 'manual' !== $order->payment_provider ) {
			return Forms::error( 'wpcap_not_manual', __( 'Nur Bestellungen mit manueller Zahlart können hier als bezahlt markiert werden.', 'pass-captain' ), 409 );
		}
		$result = Orders::confirm_manual_payment( $order->order_id );
		if ( is_wp_error( $result ) ) { return Forms::error( $result->get_error_code(), $result->get_error_message(), 409 ); }
		return array( 'order_id' => (int) $order->order_id );
	}

	/**
	 * Notice on an unpaid order whose voucher reservation was released (expiry/cancellation, A632): the
	 * amount due still carries the discount; a late payment only issues tickets once the voucher could be
	 * reserved again. Empty for every other order.
	 *
	 * @param object $order Order row.
	 * @return string HTML.
	 */
	public static function voucher_released_notice( $order ) {
		$voucher = \PassCaptain\Integrations\Vouchers\Checkout::voucher_state( (int) $order->order_id );
		if ( ! $voucher || 'released' !== ( $voucher['status'] ?? '' ) || in_array( (string) $order->payment_status, array( 'paid', 'refunded' ), true ) ) { return ''; }
		$text = sprintf(
			/* translators: 1: voucher amount, 2: masked voucher code, 3: amount due without the voucher */
			__( 'Der Gutschein (%2$s, %1$s) wurde mit Ablauf bzw. Storno der Bestellung freigegeben. Der angezeigte offene Betrag enthält noch den Gutscheinabzug; ohne Gutschein beträgt er %3$s. Eine verspätete Zahlung stellt erst Tickets aus, wenn der Gutschein erneut reserviert werden konnte, sonst wird die Bestellung zur Prüfung markiert.', 'pass-captain' ),
			Forms::money( (int) $voucher['amount_minor'], $order->currency ),
			\PassCaptain\Integrations\Vouchers\Checkout::masked_code( (string) ( $voucher['code_hint'] ?? '' ) ),
			Forms::money( (int) $order->total_minor + (int) $voucher['amount_minor'], $order->currency )
		);
		return '<div class="notice notice-warning inline"><p>' . esc_html( $text ) . '</p></div>';
	}

	/**
	 * Retries the ticket allocation of a paid order flagged `needs_review` (late payment without
	 * free capacity at that moment), e.g. after capacity was freed or a transient error. Any
	 * provider except WooCommerce (handled by its own order action).
	 */
	public static function retry_allocation( array $in ) {
		$order = self::order_for_action( $in );
		if ( is_wp_error( $order ) ) { return $order; }
		if ( 'needs_review' !== $order->status || 'paid_unallocated' !== $order->payment_status || 'woocommerce' === $order->payment_provider ) {
			return Forms::error( 'wpcap_order_state', __( 'Der Bestellstatus erlaubt diese Aktion nicht.', 'pass-captain' ), 409 );
		}
		$result = Orders::confirm_external_payment( $order->order_id, 'retry:user-' . get_current_user_id() );
		if ( is_wp_error( $result ) ) { return Forms::error( $result->get_error_code(), $result->get_error_message(), 409 ); }
		\PassCaptain\Audit::record( 'allocation_retried', 'order', (int) $order->order_id );
		return array( 'order_id' => (int) $order->order_id );
	}

	/**
	 * Closes a paid order without tickets (`needs_review`/`paid_unallocated`, A358): refund the full
	 * payment through the provider (`mode=provider`) or record that it was refunded outside the plugin
	 * (`mode=external`). Bypasses the two-person approval, so it needs the approver capability (and
	 * 2FA when required for refunds) and a reason.
	 */
	public static function settle_unallocated( array $in ) {
		$order = self::order_for_action( $in );
		if ( is_wp_error( $order ) ) { return $order; }
		if ( ! current_user_can( Refunds::APPROVE_CAP ) ) {
			return Forms::forbidden( __( 'Nur Personen, die Erstattungen freigeben dürfen, können diese Bestellung abschließen.', 'pass-captain' ) );
		}
		if ( \PassCaptain\Settings::get( 'require_2fa_for_refunds' ) && ! Refunds::user_has_2fa( get_current_user_id() ) ) {
			return Forms::forbidden( __( 'Für Erstattungen ist eine aktivierte Zwei-Faktor-Anmeldung erforderlich.', 'pass-captain' ) );
		}
		$reason = Forms::text( $in, 'reason', 255 );
		if ( '' === $reason ) { return Forms::error( 'wpcap_refund_reason', __( 'Bitte einen Grund für die Erstattung angeben.', 'pass-captain' ) ); }
		$mode = Forms::choice( $in, 'mode', array( 'provider', 'external' ), '' );
		$result = Orders::settle_unallocated( $order->order_id, $mode, $reason );
		if ( is_wp_error( $result ) ) { return Forms::error( $result->get_error_code(), $result->get_error_message(), 409 ); }
		return array( 'order_id' => (int) $order->order_id );
	}

	/** Forms of the A358 actions: refund through the provider / refunded outside – close. */
	private static function render_settle_unallocated( $order ) {
		$order_id = (int) $order->order_id;
		$gateway = \PassCaptain\Payments\Registry::get( (string) $order->payment_provider );
		$modes = array();
		if ( $gateway && $gateway->supports_refunds() ) {
			/* translators: 1: amount, 2: payment method */
			$modes['provider'] = sprintf( __( '%1$s über %2$s erstatten', 'pass-captain' ), Forms::money( (int) $order->total_minor, $order->currency ), $gateway->title() );
		}
		$modes['external'] = __( 'Extern erstattet – abschließen', 'pass-captain' );
		foreach ( $modes as $mode => $label ) {
			echo '<p>';
			Forms::open_form( 'wpcap_settle_unallocated', 'wpcap_settle_unallocated_' . $order_id, 'style="display:inline"' );
			Forms::hidden( 'order_id', $order_id );
			Forms::hidden( 'mode', $mode );
			echo '<label for="wpcap-settle-' . esc_attr( $mode ) . '">' . esc_html__( 'Grund', 'pass-captain' ) . '</label> <input type="text" required maxlength="255" name="reason" id="wpcap-settle-' . esc_attr( $mode ) . '"> ';
			echo '<button class="button" type="submit">' . esc_html( $label ) . '</button></form></p>';
		}
		echo '<p class="description">' . esc_html__( '„Extern erstattet“ sendet nichts an den Zahlungsanbieter: nur verwenden, wenn das Geld bereits auf anderem Weg zurückgezahlt wurde. Beide Aktionen schließen die Bestellung als erstattet ab.', 'pass-captain' ) . '</p>';
	}

	/** Cancels an unpaid order. */
	public static function cancel_order( array $in ) {
		$order = self::order_for_action( $in );
		if ( is_wp_error( $order ) ) { return $order; }
		if ( 'woocommerce' === $order->payment_provider ) {
			return Forms::error( 'wpcap_order_woocommerce', __( 'WooCommerce-Bestellungen bitte in WooCommerce stornieren.', 'pass-captain' ), 409 );
		}
		if ( 'pending' !== $order->payment_status || 'pending_payment' !== $order->status ) {
			return Forms::error( 'wpcap_order_not_unpaid', __( 'Nur unbezahlte, offene Bestellungen können storniert werden. Bezahlte Bestellungen bitte erstatten.', 'pass-captain' ), 409 );
		}
		$cancelled = Orders::cancel_order( $order->order_id, Forms::text( $in, 'reason', 255 ) ?: 'admin_cancel' );
		if ( is_wp_error( $cancelled ) ) { return $cancelled; }
		return array( 'order_id' => (int) $order->order_id );
	}

	/**
	 * Resends tickets with fresh codes (ticket codes are only stored hashed, so a resend always means
	 * new codes). A335: by default tickets passed on to another person (transfer) keep their code –
	 * the resend must not invalidate the recipient's QR code or print. Including them is a deliberate
	 * choice (scope "all"); the recipients then receive the new code at their own address.
	 *
	 * A588: codes 'keep' sends the stored current codes again (Orders::resend_tickets() keep_codes;
	 * tickets without a stored code still get a new one), 'new' (default) always issues new codes.
	 *
	 * @param array $in order_id, scope ('own' default | 'all'), codes ('new' default | 'keep').
	 * @return array|\WP_Error {order_id, sent, kept, codes}
	 */
	public static function resend_tickets( array $in ) {
		$order = self::order_for_action( $in );
		if ( is_wp_error( $order ) ) { return $order; }
		$all = 'all' === Forms::choice( $in, 'scope', array( 'own', 'all' ), 'own' );
		$keep_codes = 'keep' === Forms::choice( $in, 'codes', array( 'new', 'keep' ), 'new' );
		$counts = Orders::resend_counts( (int) $order->order_id );
		if ( ! $all && $counts['valid'] > 0 && $counts['valid'] === $counts['transferred'] ) {
			return Forms::error( 'wpcap_resend_only_transferred', __( 'Alle gültigen Tickets dieser Bestellung wurden an andere Personen übertragen und behalten ihren Code. Um sie trotzdem neu auszustellen, „Auch übertragene Tickets“ wählen oder einzelne Tickets unter „Tickets“ neu ausstellen.', 'pass-captain' ), 409 );
		}
		// A533: the form key makes a double submit harmless (one resend per key and order).
		$request_key = preg_replace( '/[^A-Za-z0-9\-]/', '', (string) ( $in['request_key'] ?? '' ) );
		$request = strlen( $request_key ) >= 16 && strlen( $request_key ) <= 64 ? array( 'request_key' => $request_key ) : array();
		$result = Orders::resend_tickets( $order->order_id, ( $all ? array() : array( 'skip_transferred' => true ) ) + ( $keep_codes ? array( 'keep_codes' => true ) : array() ) + $request );
		if ( is_wp_error( $result ) ) { return Forms::error( $result->get_error_code(), $result->get_error_message(), 409 ); }
		$kept = $all ? 0 : $counts['transferred'];
		return array( 'order_id' => (int) $order->order_id, 'sent' => $counts['valid'] - $kept, 'kept' => $kept, 'codes' => $keep_codes ? 'keep' : 'new' );
	}

	/** Success message of resend_tickets(). */
	private static function resend_message( array $result ) {
		if ( 'keep' === ( $result['codes'] ?? 'new' ) ) {
			/* translators: %d: number of tickets */
			$message = sprintf( _n( '%d Ticket wurde erneut gesendet (gespeicherter Code bleibt gültig, sonst neuer Code).', '%d Tickets wurden erneut gesendet (gespeicherte Codes bleiben gültig, sonst neue Codes).', (int) $result['sent'], 'pass-captain' ), (int) $result['sent'] );
		} else {
			/* translators: %d: number of tickets */
			$message = sprintf( _n( '%d Ticket wurde mit neuem Code erneut gesendet.', '%d Tickets wurden mit neuen Codes erneut gesendet.', (int) $result['sent'], 'pass-captain' ), (int) $result['sent'] );
		}
		if ( (int) $result['kept'] ) {
			/* translators: %d: number of transferred tickets */
			$message .= ' ' . sprintf( _n( '%d übertragenes Ticket behält seinen Code.', '%d übertragene Tickets behalten ihren Code.', (int) $result['kept'], 'pass-captain' ), (int) $result['kept'] );
		}
		return $message;
	}

	/**
	 * A586: ticket PDF of the order's valid tickets (or one ticket, field "ticket" = public id) with
	 * their stored current codes – nothing is rotated, mailed or printed codes stay valid. Tickets
	 * without a stored code are left out and counted (they need "Tickets erneut senden").
	 *
	 * @return array|\WP_Error filename, content, tickets, missing.
	 */
	public static function ticket_pdf( array $in ) {
		$order = self::order_for_action( $in );
		if ( is_wp_error( $order ) ) { return $order; }
		if ( 'paid' !== $order->payment_status ) {
			return Forms::error( 'wpcap_order_unpaid', __( 'Tickets gibt es erst nach bestätigter Zahlung.', 'pass-captain' ), 409 );
		}
		$only = strtolower( preg_replace( '/[^a-f0-9]/i', '', Forms::text( $in, 'ticket', 32 ) ) );
		$entries = array();
		$missing = 0;
		foreach ( \PassCaptain\PortalSelfService::tickets( $order ) as $ticket ) {
			if ( 'valid' !== (string) $ticket->status || (int) $ticket->refunded || ( '' !== $only && ! hash_equals( (string) $ticket->public_id, $only ) ) ) { continue; }
			$code = \PassCaptain\PortalSelfService::current_code( (int) $ticket->ticket_id );
			if ( '' === $code ) { ++$missing; continue; }
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
				'help_code' => \PassCaptain\HelpCode::for_ticket( (int) $ticket->ticket_id, $code ),
				'ticket_id' => (int) $ticket->ticket_id,
			);
		}
		if ( ! $entries ) {
			return Forms::error(
				'wpcap_ticket_pdf_no_code',
				$missing
					? __( 'Für diese Tickets ist kein Code gespeichert (vor dem Update ausgestellt). „Tickets erneut senden“ stellt neue Codes aus; danach ist das PDF hier verfügbar.', 'pass-captain' )
					: __( 'Diese Bestellung hat keine gültigen Tickets für ein PDF.', 'pass-captain' ),
				409
			);
		}
		$content = \PassCaptain\Mail\Mailer::with_locale( (string) $order->locale, static function () use ( $entries ) {
			return \PassCaptain\TicketPdf::render( $entries, array( 'title' => get_bloginfo( 'name' ), 'footer' => __( 'Dieses Ticket ist nur einmal gültig. Code nicht weitergeben.', 'pass-captain' ) ) );
		} );
		\PassCaptain\Audit::record( 'admin_ticket_pdf', 'order', (int) $order->order_id, array( 'tickets' => count( $entries ), 'missing' => $missing, 'scope' => '' === $only ? 'all' : 'single' ) );
		$reference = preg_replace( '/[^A-Za-z0-9\-]/', '', \PassCaptain\Frontend\Format::order_reference( $order->public_id ) );
		return array( 'filename' => ( '' === $only ? 'tickets-' : 'ticket-' ) . $reference . ( '' === $only ? '' : '-' . substr( $only, 0, 8 ) ) . '.pdf', 'content' => $content, 'tickets' => count( $entries ), 'missing' => $missing );
	}

	/**
	 * Requests a refund for selected tickets or the whole order. The idempotency key is generated
	 * when the form is rendered, so a double submit returns the same refund.
	 */
	public static function refund( array $in ) {
		$order = self::order_for_action( $in );
		if ( is_wp_error( $order ) ) { return $order; }
		if ( ! self::can_request_refunds() ) { return Forms::forbidden( __( 'Keine Berechtigung für Erstattungen.', 'pass-captain' ) ); }
		if ( 'woocommerce' === $order->payment_provider ) {
			return Forms::error( 'wpcap_refund_woocommerce', __( 'WooCommerce-Bestellungen bitte in WooCommerce erstatten; die Tickets werden automatisch widerrufen.', 'pass-captain' ), 409 );
		}
		$reason = Forms::text( $in, 'reason', 255 );
		if ( '' === $reason ) { return Forms::error( 'wpcap_refund_reason', __( 'Bitte einen Grund für die Erstattung angeben.', 'pass-captain' ) ); }
		$key = preg_replace( '/[^A-Za-z0-9\-]/', '', (string) ( $in['idempotency_key'] ?? '' ) );
		if ( strlen( $key ) < 16 || strlen( $key ) > 64 ) {
			return Forms::error( 'wpcap_refund_key', __( 'Das Formular ist abgelaufen. Bitte die Seite neu laden.', 'pass-captain' ) );
		}
		$all = Forms::flag( $in, 'refund_all' );
		// A340: "Alle" no longer overrides a selection silently – both at once is ambiguous.
		if ( $all && Forms::ids( $in, 'ticket_ids' ) ) {
			return Forms::error( 'wpcap_refund_ambiguous', __( 'Bitte entweder einzelne Tickets auswählen oder „Alle offenen Tickets“ – nicht beides.', 'pass-captain' ) );
		}
		$ticket_ids = $all ? array() : Forms::ids( $in, 'ticket_ids' );
		if ( ! $all && ! $ticket_ids ) {
			return Forms::error( 'wpcap_refund_tickets', __( 'Bitte Tickets auswählen oder „Alle offenen Tickets“ wählen.', 'pass-captain' ) );
		}
		$options = self::refund_options( $in, (string) $order->currency );
		if ( is_wp_error( $options ) ) { return $options; }
		$options['used_override'] = Forms::text( $in, 'used_override', 200 );
		$result = Refunds::request( $order->order_id, $ticket_ids, $reason, $key, $options );
		if ( is_wp_error( $result ) ) {
			$status = (int) ( ( $result->get_error_data()['status'] ?? 409 ) );
			return Forms::error( $result->get_error_code(), $result->get_error_message(), 403 === $status ? 403 : 409 );
		}
		return array( 'order_id' => (int) $order->order_id, 'refund' => $result );
	}

	/**
	 * Optional fee kept and goodwill cap from the refund form (money input in the order currency).
	 *
	 * @return array|\WP_Error deduct_minor, max_minor.
	 */
	public static function refund_options( array $in, $currency ) {
		$out = array();
		foreach ( array( 'deduct' => 'deduct_minor', 'max_amount' => 'max_minor' ) as $field => $key ) {
			$raw = is_scalar( $in[ $field ] ?? null ) ? trim( (string) $in[ $field ] ) : '';
			if ( '' === $raw ) { continue; }
			$minor = Forms::parse_money( $raw, Forms::currency_decimals( $currency ) );
			if ( null === $minor ) { return Forms::error( 'wpcap_refund_amount', __( 'Abzug oder Höchstbetrag ist ungültig. Beispiel: 5,00', 'pass-captain' ) ); }
			if ( $minor > 0 ) { $out[ $key ] = $minor; }
		}
		return $out;
	}

	/**
	 * Refund preview, two forms:
	 * - refund_preview( array $in ): the "Betrag berechnen" preview of the admin form (nothing is written):
	 *   selected tickets or all open ones, value, kept fee, refund and VAT (see Refunds::preview()).
	 * - refund_preview( object $order, array $refundable ): amounts and route for the live sum of the
	 *   refund form (A340), see refund_amounts().
	 *
	 * @param array|object $in         Form data, or the order row.
	 * @param array|null   $refundable Refundable tickets when $in is the order row.
	 * @return array|\WP_Error
	 */
	public static function refund_preview( $in, $refundable = null ) {
		if ( is_object( $in ) ) { return self::refund_amounts( $in, (array) $refundable ); }
		$in = (array) $in;
		$order = self::order_for_action( $in );
		if ( is_wp_error( $order ) ) { return $order; }
		if ( ! self::can_request_refunds() ) { return Forms::forbidden( __( 'Keine Berechtigung für Erstattungen.', 'pass-captain' ) ); }
		$options = self::refund_options( $in, (string) $order->currency );
		if ( is_wp_error( $options ) ) { return $options; }
		$all = Forms::flag( $in, 'refund_all' );
		$ticket_ids = $all ? array() : Forms::ids( $in, 'ticket_ids' );
		if ( ! $all && ! $ticket_ids ) {
			return Forms::error( 'wpcap_refund_tickets', __( 'Bitte Tickets auswählen oder „Alle offenen Tickets“ wählen.', 'pass-captain' ) );
		}
		return Refunds::preview( (int) $order->order_id, $ticket_ids, $options );
	}

	/** Success message of a refund request, naming the amount (A340). */
	public static function refund_message( array $refund ) {
		$status = (string) ( $refund['status'] ?? '' );
		$amount = Forms::money( (int) ( $refund['amount_minor'] ?? 0 ), (string) ( $refund['currency'] ?? '' ) );
		if ( 'pending_approval' === $status ) {
			/* translators: %s: refund amount */
			return sprintf( __( 'Erstattung über %s angefordert. Sie wartet auf die Freigabe durch eine zweite Person.', 'pass-captain' ), $amount );
		}
		/* translators: 1: refund amount, 2: status */
		return sprintf( __( 'Erstattung über %1$s verarbeitet (Status: %2$s).', 'pass-captain' ), $amount, Forms::status_label( $status ) );
	}

	/**
	 * Amounts and route for the refund form (A340): amount per refundable ticket and for "all open
	 * tickets" (Refunds::preview(), same calculation as the refund itself), how the money goes back and
	 * whether the threshold requires a second approval.
	 *
	 * @param object   $order      Order row.
	 * @param object[] $refundable Refundable tickets (ticket_id).
	 * @return array {per_ticket: int[] ticket_id => minor, all: int, all_ids: int[], route: string, approval_all: bool}
	 */
	public static function refund_amounts( $order, array $refundable ) {
		$per = array();
		foreach ( $refundable as $ticket ) {
			$one = Refunds::preview( $order, array( (int) $ticket->ticket_id ) );
			if ( ! is_wp_error( $one ) ) { $per[ (int) $ticket->ticket_id ] = (int) $one['amount_minor']; }
		}
		$all = Refunds::preview( $order, array() );
		return array(
			'per_ticket' => $per,
			'all' => is_wp_error( $all ) ? array_sum( $per ) : (int) $all['amount_minor'],
			'all_ids' => is_wp_error( $all ) ? array_keys( $per ) : array_map( 'intval', $all['ticket_ids'] ),
			'route' => self::refund_route( $order ),
			'approval_all' => ! is_wp_error( $all ) && ! empty( $all['needs_approval'] ),
		);
	}

	/** How a refund of this order is paid out (A340). */
	public static function refund_route( $order ) {
		$gateway = \PassCaptain\Payments\Registry::get( '' !== (string) $order->payment_provider ? (string) $order->payment_provider : 'manual' );
		if ( $gateway && $gateway->supports_refunds() ) {
			/* translators: %s: payment provider */
			return sprintf( __( 'Automatisch über %s zurück auf das Zahlungsmittel der Käufer:in.', 'pass-captain' ), wp_strip_all_tags( (string) $gateway->title() ) );
		}
		$account = \PassCaptain\PayoutAccount::applies( $order ) ? \PassCaptain\PayoutAccount::get( $order ) : null;
		if ( $account ) {
			/* translators: %s: masked IBAN */
			return sprintf( __( 'Nicht automatisch: Betrag bitte außerhalb des Plugins auf das hinterlegte Konto %s überweisen. Die Tickets werden widerrufen.', 'pass-captain' ), \PassCaptain\PayoutAccount::mask( $account['iban'] ) );
		}
		return __( 'Nicht automatisch: Betrag bitte außerhalb des Plugins zurückzahlen. Die Tickets werden widerrufen.', 'pass-captain' );
	}

	/**
	 * Live sum, "Alle" locking the single selection and a confirmation naming amount and route (A340).
	 * Without JavaScript the server refuses a form with both "Alle" and single tickets.
	 */
	private static function refund_form_script( array $preview, $currency ) {
		$data = array(
			'all' => (int) $preview['all'],
			'allCount' => count( $preview['all_ids'] ),
			'currency' => (string) $currency,
			'decimals' => Forms::currency_decimals( (string) $currency ),
			'locale' => str_replace( '_', '-', function_exists( 'get_user_locale' ) ? (string) get_user_locale() : 'de_DE' ),
			'route' => (string) $preview['route'],
			'none' => __( 'Noch keine Tickets gewählt.', 'pass-captain' ),
			/* translators: 1: amount, 2: number of tickets (not 1; sumOne is used for one ticket) */
			'sum' => __( '%1$s für %2$d Tickets', 'pass-captain' ),
			/* translators: 1: amount, 2: number of tickets (always 1) */
			'sumOne' => __( '%1$s für %2$d Ticket', 'pass-captain' ),
			/* translators: 1: amount, 2: number of tickets */
			'sumAll' => __( '%1$s für alle %2$d offenen Tickets (Restbetrag)', 'pass-captain' ),
			/* translators: 1: amount and number of tickets, 2: how the money goes back */
			'confirm' => __( 'Jetzt %1$s erstatten? %2$s', 'pass-captain' ),
			'pick' => __( 'Bitte Tickets auswählen oder „Alle offenen Tickets“ wählen.', 'pass-captain' ),
		);
		\PassCaptain\Assets::inline_script( 'wpcap-refund-form', '(function(){var D=' . wp_json_encode( $data ) . ';var f=document.getElementById("wpcap-refund-form");if(!f){return;}'
			. 'var all=document.getElementById("wpcap-refund-all"),out=document.getElementById("wpcap-refund-amount"),boxes=f.querySelectorAll("input.wpcap-refund-ticket"),saved=[];'
			. 'function money(m){try{return new Intl.NumberFormat(D.locale,{style:"currency",currency:D.currency}).format(m/Math.pow(10,D.decimals));}catch(e){return (m/Math.pow(10,D.decimals)).toFixed(D.decimals)+" "+D.currency;}}'
			. 'function fmt(t,a,b){return t.replace("%1$s",a).replace("%2$d",b).replace("%2$s",b);}'
			. 'function state(){if(all&&all.checked){return fmt(D.sumAll,money(D.all),D.allCount);}var s=0,n=0;boxes.forEach(function(b){if(b.checked){s+=parseInt(b.getAttribute("data-amount")||"0",10);n++;}});return n?fmt(1===n&&D.sumOne?D.sumOne:D.sum,money(s),n):"";}'
			. 'function update(){out.textContent=state()||D.none;}'
			. 'if(all){all.addEventListener("change",function(){boxes.forEach(function(b,i){if(all.checked){saved[i]=b.checked;b.checked=true;b.disabled=true;}else{b.disabled=false;b.checked=!!saved[i];}});update();});}'
			. 'boxes.forEach(function(b){b.addEventListener("change",update);});'
			. 'f.addEventListener("submit",function(e){if(e.submitter&&"preview"===e.submitter.name){return;}var t=state();if(!t){e.preventDefault();window.alert(D.pick);return;}if(!window.confirm(fmt(D.confirm,t,D.route))){e.preventDefault();}});update();})();' );
	}

	/** Loads a pending refund the current user may decide on. */
	private static function refund_for_decision( array $in ) {
		if ( ! current_user_can( Refunds::APPROVE_CAP ) ) { return Forms::forbidden( __( 'Keine Berechtigung für Erstattungsfreigaben.', 'pass-captain' ) ); }
		$refund = Refunds::get( Forms::int( $in, 'refund_id' ) );
		if ( ! $refund ) { return Forms::error( 'wpcap_refund_missing', __( 'Erstattung nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! Queries::can_access_order( $refund['order_id'], self::CAP ) ) { return Forms::forbidden( __( 'Keine Berechtigung für diese Bestellung.', 'pass-captain' ) ); }
		if ( 'pending_approval' !== $refund['status'] ) {
			return Forms::error( 'wpcap_refund_not_pending', __( 'Diese Erstattung wartet nicht auf Freigabe.', 'pass-captain' ), 409 );
		}
		return $refund;
	}

	/** Second approval of a pending refund (must be another person than the requester). */
	public static function approve_refund( array $in ) {
		$refund = self::refund_for_decision( $in );
		if ( is_wp_error( $refund ) ) { return $refund; }
		if ( (int) $refund['requested_by'] === get_current_user_id() ) {
			return Forms::error( 'wpcap_refund_same_person', __( 'Die Freigabe muss durch eine andere berechtigte Person erfolgen.', 'pass-captain' ), 409 );
		}
		$result = Refunds::approve( $refund['refund_id'] );
		if ( is_wp_error( $result ) ) { return Forms::error( $result->get_error_code(), $result->get_error_message(), 409 ); }
		return array( 'order_id' => (int) $refund['order_id'], 'refund' => $result );
	}

	/** Rejects a pending refund (reason required). */
	public static function reject_refund( array $in ) {
		$refund = self::refund_for_decision( $in );
		if ( is_wp_error( $refund ) ) { return $refund; }
		$reason = Forms::text( $in, 'reason', 255 );
		if ( '' === $reason ) { return Forms::error( 'wpcap_refund_reason', __( 'Bitte einen Grund für die Ablehnung angeben.', 'pass-captain' ) ); }
		$result = Refunds::reject( $refund['refund_id'], $reason );
		if ( is_wp_error( $result ) ) { return Forms::error( $result->get_error_code(), $result->get_error_message(), 409 ); }
		\PassCaptain\Audit::record( 'refund_rejected', 'order', (int) $refund['order_id'], array( 'refund_id' => (int) $refund['refund_id'], 'reason' => $reason ) );
		return array( 'order_id' => (int) $refund['order_id'], 'refund' => $result );
	}

	/**
	 * Clears up a refund whose outcome at the provider is unknown (A350): "lookup" asks the provider
	 * (Refunds::reconcile()), "paid"/"not_paid" record a manual decision after a check in the provider's
	 * account (approval capability, note required). Every action is audited.
	 *
	 * @return array|\WP_Error ['order_id', 'do', 'result' (string or refund row)].
	 */
	public static function resolve_refund( array $in ) {
		$refund = Refunds::get( Forms::int( $in, 'refund_id' ) );
		if ( ! $refund ) { return Forms::error( 'wpcap_refund_missing', __( 'Erstattung nicht gefunden.', 'pass-captain' ), 404 ); }
		$admin = current_user_can( OperationsPage::CAP );
		if ( ! $admin && ! Queries::can_access_order( $refund['order_id'], self::CAP ) ) { return Forms::forbidden( __( 'Keine Berechtigung für diese Bestellung.', 'pass-captain' ) ); }
		if ( ! $admin && ! self::can_request_refunds() ) { return Forms::forbidden( __( 'Keine Berechtigung für Erstattungen.', 'pass-captain' ) ); }
		$do = Forms::choice( $in, 'do', array( 'lookup', 'paid', 'not_paid', 'retry' ), '' );
		if ( '' === $do ) { return Forms::error( 'wpcap_refund_decision', __( 'Bitte eine Aktion wählen.', 'pass-captain' ) ); }
		if ( 'retry' === $do ) {
			// A348: pays out the amount of a refund that failed at the provider again (new refund, audited).
			$result = Refunds::retry_reversed( (int) $refund['refund_id'] );
		} elseif ( 'lookup' === $do ) {
			$result = Refunds::reconcile( (int) $refund['refund_id'], 'admin' );
			\PassCaptain\Audit::record( 'refund_lookup_requested', 'order', (int) $refund['order_id'], array( 'refund_id' => (int) $refund['refund_id'], 'result' => is_wp_error( $result ) ? $result->get_error_code() : (string) $result ) );
		} else {
			$result = Refunds::resolve_manually( (int) $refund['refund_id'], $do, Forms::text( $in, 'note', 200 ), Forms::text( $in, 'reference', 150 ) );
		}
		if ( is_wp_error( $result ) ) { return $result; }
		return array( 'order_id' => (int) $refund['order_id'], 'do' => $do, 'result' => $result );
	}

	/** Success message of resolve_refund(). */
	private static function resolve_message( array $result ) {
		if ( 'retry' === $result['do'] ) {
			$status = is_array( $result['result'] ) ? (string) ( $result['result']['status'] ?? '' ) : '';
			return 'pending_approval' === $status ? __( 'Neue Auszahlung angefordert. Sie wartet auf die Freigabe durch eine zweite Person.', 'pass-captain' ) : sprintf( /* translators: %s: status */ __( 'Neue Auszahlung verarbeitet (Status: %s).', 'pass-captain' ), Forms::status_label( $status ) );
		}
		if ( 'lookup' !== $result['do'] ) {
			return 'paid' === $result['do'] ? __( 'Als ausgezahlt bestätigt; die Erstattung wurde verbucht.', 'pass-captain' ) : __( 'Als nicht ausgezahlt bestätigt; die Erstattung ist fehlgeschlagen und kann neu angefordert werden.', 'pass-captain' );
		}
		$messages = array(
			'completed' => __( 'Der Zahlungsanbieter hat die Erstattung ausgezahlt; sie wurde verbucht.', 'pass-captain' ),
			'failed'    => __( 'Beim Zahlungsanbieter wurde nichts ausgezahlt; die Erstattung ist fehlgeschlagen und kann neu angefordert werden.', 'pass-captain' ),
			'waiting'   => __( 'Der Zahlungsanbieter kennt die Erstattung noch nicht. Bitte in einigen Minuten erneut prüfen.', 'pass-captain' ),
		);
		return $messages[ (string) $result['result'] ] ?? __( 'Der Ausgang dieser Erstattung ist bereits geklärt.', 'pass-captain' );
	}

	/* =============================================================================================
	 * admin-post wrappers
	 * =========================================================================================== */

	public static function handle_resolve_refund() {
		$in = Forms::post();
		Forms::verify( 'wpcap_resolve_refund_' . Forms::int( $in, 'refund_id' ) );
		$result = self::resolve_refund( $in );
		$order_id = is_wp_error( $result ) ? Forms::int( $in, 'order_id' ) : $result['order_id'];
		$url = 'operations' === ( $in['return'] ?? '' ) ? Forms::page_url( OperationsPage::SLUG ) . '#wpcap-refunds-unresolved' : self::order_url( $order_id );
		Forms::finish( $result, is_wp_error( $result ) ? '' : self::resolve_message( $result ), $url );
	}

	public static function order_url( $order_id ) {
		return Forms::page_url( self::SLUG, array( 'order_id' => absint( $order_id ) ) );
	}

	/** Redirect target after a refund decision (refund queue or order detail). */
	private static function decision_url( array $in, $order_id ) {
		return 'queue' === ( $in['return'] ?? '' ) ? Forms::page_url( RefundsPage::SLUG ) : self::order_url( $order_id );
	}

	public static function handle_confirm_payment() {
		$in = Forms::post();
		$order_id = Forms::int( $in, 'order_id' );
		Forms::verify( 'wpcap_confirm_payment_' . $order_id );
		Forms::finish( self::confirm_payment( $in ), __( 'Zahlung bestätigt; Tickets wurden ausgestellt.', 'pass-captain' ), self::order_url( $order_id ) );
	}

	public static function handle_retry_allocation() {
		$in = Forms::post();
		$order_id = Forms::int( $in, 'order_id' );
		Forms::verify( 'wpcap_retry_allocation_' . $order_id );
		Forms::finish( self::retry_allocation( $in ), __( 'Kontingent zugeteilt; Tickets wurden ausgestellt.', 'pass-captain' ), self::order_url( $order_id ) );
	}

	public static function handle_settle_unallocated() {
		$in = Forms::post();
		$order_id = Forms::int( $in, 'order_id' );
		Forms::verify( 'wpcap_settle_unallocated_' . $order_id );
		Forms::finish( self::settle_unallocated( $in ), __( 'Zahlung erstattet; die Bestellung ist abgeschlossen.', 'pass-captain' ), self::order_url( $order_id ) );
	}

	public static function handle_cancel_order() {
		$in = Forms::post();
		$order_id = Forms::int( $in, 'order_id' );
		Forms::verify( 'wpcap_cancel_order_' . $order_id );
		Forms::finish( self::cancel_order( $in ), __( 'Bestellung storniert.', 'pass-captain' ), self::order_url( $order_id ) );
	}

	public static function handle_resend_tickets() {
		$in = Forms::post();
		$order_id = Forms::int( $in, 'order_id' );
		Forms::verify( 'wpcap_resend_tickets_' . $order_id );
		$result = self::resend_tickets( $in );
		Forms::finish( $result, is_wp_error( $result ) ? '' : self::resend_message( $result ), self::order_url( $order_id ) );
	}

	/** A424: sends the buyer a new order link; the current link stays valid until the new one is opened (A631). */
	public static function handle_send_order_link() {
		$in = Forms::post();
		$order_id = Forms::int( $in, 'order_id' );
		Forms::verify( 'wpcap_send_order_link_' . $order_id );
		$order = self::order_for_action( $in );
		if ( is_wp_error( $order ) ) { Forms::finish( $order, '', self::order_url( $order_id ) ); }
		$sent = Orders::rotate_access_link( (int) $order->order_id, true );
		if ( $sent ) { \PassCaptain\Audit::record( 'order_link_resent', 'order', (int) $order->order_id, array( 'by' => 'admin' ) ); }
		Forms::finish( $sent ? true : new \WP_Error( 'wpcap_order_link_failed', __( 'Der Bestelllink konnte nicht gesendet werden.', 'pass-captain' ) ), __( 'Neuer Bestelllink gesendet. Der bisherige Link gilt, bis der neue zum ersten Mal geöffnet wird.', 'pass-captain' ), self::order_url( (int) $order->order_id ) );
	}

	public static function handle_ticket_pdf() {
		$in = Forms::post();
		$order_id = Forms::int( $in, 'order_id' );
		Forms::verify( 'wpcap_ticket_pdf_' . $order_id );
		$result = self::ticket_pdf( $in );
		if ( is_wp_error( $result ) ) { Forms::finish( $result, '', self::order_url( $order_id ) ); }
		\PassCaptain\Reports\Scope::send_download( $result['content'], $result['filename'], 'application/pdf' );
	}

	public static function handle_refund() {
		$in = Forms::post();
		$order_id = Forms::int( $in, 'order_id' );
		Forms::verify( 'wpcap_refund_order_' . $order_id );
		if ( Forms::flag( $in, 'preview' ) ) {
			// "Betrag berechnen": back to the order with the selection; the page shows the preview.
			$args = array( 'refund_preview' => 1, 'refund_all' => Forms::flag( $in, 'refund_all' ) ? 1 : 0, 'tickets' => implode( ',', Forms::ids( $in, 'ticket_ids' ) ), 'deduct' => Forms::text( $in, 'deduct', 20 ), 'max_amount' => Forms::text( $in, 'max_amount', 20 ) );
			Forms::redirect( add_query_arg( array_filter( $args, static function ( $value ) { return '' !== (string) $value; } ), self::order_url( $order_id ) ) );
		}
		$result = self::refund( $in );
		$message = '';
		if ( ! is_wp_error( $result ) ) {
			$message = self::refund_message( (array) $result['refund'] );
		}
		Forms::finish( $result, $message, self::order_url( $order_id ) );
	}

	public static function handle_approve_refund() {
		$in = Forms::post();
		Forms::verify( 'wpcap_approve_refund_' . Forms::int( $in, 'refund_id' ) );
		$result = self::approve_refund( $in );
		$order_id = is_wp_error( $result ) ? Forms::int( $in, 'order_id' ) : $result['order_id'];
		Forms::finish( $result, __( 'Erstattung freigegeben.', 'pass-captain' ), self::decision_url( $in, $order_id ) );
	}

	public static function handle_reject_refund() {
		$in = Forms::post();
		Forms::verify( 'wpcap_reject_refund_' . Forms::int( $in, 'refund_id' ) );
		$result = self::reject_refund( $in );
		$order_id = is_wp_error( $result ) ? Forms::int( $in, 'order_id' ) : $result['order_id'];
		Forms::finish( $result, __( 'Erstattung abgelehnt.', 'pass-captain' ), self::decision_url( $in, $order_id ) );
	}

	/* =============================================================================================
	 * Rendering
	 * =========================================================================================== */

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		echo '<div class="wrap wpcap-admin">';
		$order_id = absint( $_GET['order_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $order_id ) {
			self::render_detail( $order_id );
		} else {
			self::render_list();
		}
		echo '</div>';
	}

	/** List filters from the query string (list and CSV export). */
	private static function list_filters() {
		return array(
			'status' => in_array( Forms::query_arg( 'status' ), self::ORDER_STATUSES, true ) ? Forms::query_arg( 'status' ) : '',
			'payment_status' => in_array( Forms::query_arg( 'payment_status' ), self::PAYMENT_STATUSES, true ) ? Forms::query_arg( 'payment_status' ) : '',
			'search' => Forms::query_arg( 's' ),
			'event_id' => absint( Forms::query_arg( 'event_id', 0 ) ),
		);
	}

	/** Orders per CSV export at most. */
	const CSV_LIMIT = 5000;

	/**
	 * CSV rows of the filtered orders in the user's event scope (header first): order number as shown to
	 * buyers (Format::order_reference()), full id, dates, states, provider, buyer e-mail (masked for the
	 * support profile), amounts and events. Cells are protected against formulas by Reports\Scope.
	 *
	 * @return array[]
	 */
	public static function csv_rows( array $filters ) {
		$data = Queries::search_orders( $filters, Queries::event_ids_for( self::CAP ), 1, self::CSV_LIMIT );
		$masked = DataPolicy::masks_pii_many( array_map( static fn( $row ) => (int) $row->order_id, $data['rows'] ) );
		$rows = array( array( __( 'Bestellnummer', 'pass-captain' ), __( 'Bestell-ID', 'pass-captain' ), __( 'Datum (UTC)', 'pass-captain' ), __( 'Status', 'pass-captain' ), __( 'Zahlung', 'pass-captain' ), __( 'Zahlungsanbieter', 'pass-captain' ), __( 'E-Mail', 'pass-captain' ), __( 'Gesamt', 'pass-captain' ), __( 'Erstattet', 'pass-captain' ), __( 'Währung', 'pass-captain' ), __( 'Events', 'pass-captain' ) ) );
		foreach ( $data['rows'] as $order ) {
			$decimals = Forms::currency_decimals( (string) $order->currency );
			$rows[] = array(
				\PassCaptain\Frontend\Format::order_reference( $order->public_id ),
				(string) $order->public_id,
				(string) $order->created_at,
				(string) $order->status,
				(string) $order->payment_status,
				(string) $order->payment_provider,
				! empty( $masked[ (int) $order->order_id ] ) ? DataPolicy::mask_email( $order->customer_email ) : (string) $order->customer_email,
				number_format( (int) $order->total_minor / ( 10 ** $decimals ), $decimals, '.', '' ),
				number_format( (int) $order->refunded_minor / ( 10 ** $decimals ), $decimals, '.', '' ),
				(string) $order->currency,
				(string) ( $order->event_names ?? '' ),
			);
		}
		return $rows;
	}

	/** admin-post wpcap_orders_csv (GET, nonce): CSV of the filtered order list. */
	public static function handle_csv() {
		check_admin_referer( 'wpcap_orders_csv' );
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), '', array( 'response' => 403 ) ); }
		$filters = self::list_filters();
		$rows = self::csv_rows( $filters );
		Audit::record( 'orders_exported', 'order', 0, array( 'rows' => count( $rows ) - 1, 'filters' => array_filter( $filters ) ) );
		\PassCaptain\Reports\Scope::send_csv( $rows, 'bestellungen-' . gmdate( 'Y-m-d' ) . '.csv' );
	}

	private static function render_list() {
		$scope = Queries::event_ids_for( self::CAP );
		$filters = self::list_filters();
		$page = Forms::current_page();
		$per_page = 20;
		$data = Queries::search_orders( $filters, $scope, $page, $per_page );
		// Masking decided once for the page (profile, policy, one unmask lookup), not per row (PERF-15).
		$masked = DataPolicy::masks_pii_many( array_map( static fn( $row ) => (int) $row->order_id, $data['rows'] ) );
		\PassCaptain\Privacy\AccessLog::list_view( 'order_list', $filters + array( 'paged' => $page ), count( $data['rows'] ) );
		$events = Queries::event_options( $scope, array( $filters['event_id'] ) );
		$base = Forms::page_url( self::SLUG, array_filter( array( 'status' => $filters['status'], 'payment_status' => $filters['payment_status'], 's' => $filters['search'], 'event_id' => $filters['event_id'] ) ) );
		$status_options = array( '' => __( 'Alle Bestellstatus', 'pass-captain' ) );
		foreach ( self::ORDER_STATUSES as $s ) { $status_options[ $s ] = Forms::status_label( $s ); }
		$payment_options = array( '' => __( 'Alle Zahlungsstatus', 'pass-captain' ) );
		foreach ( self::PAYMENT_STATUSES as $s ) { $payment_options[ $s ] = Forms::status_label( $s ); }
		?>
		<h1><?php esc_html_e( 'Bestellungen', 'pass-captain' ); ?></h1>
		<?php Forms::render_notices(); ?>
		<form method="get" class="wpcap-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
			<?php Forms::select( 'status', $status_options, $filters['status'], '', 'aria-label="' . esc_attr__( 'Bestellstatus', 'pass-captain' ) . '"' ); ?>
			<?php Forms::select( 'payment_status', $payment_options, $filters['payment_status'], '', 'aria-label="' . esc_attr__( 'Zahlungsstatus', 'pass-captain' ) . '"' ); ?>
			<?php Forms::select( 'event_id', array( 0 => __( 'Alle Events', 'pass-captain' ) ) + $events, $filters['event_id'], '', 'aria-label="' . esc_attr__( 'Event', 'pass-captain' ) . '"' ); ?>
			<input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Bestellnummer, E-Mail, Name oder Ticket-ID', 'pass-captain' ); ?>" aria-label="<?php esc_attr_e( 'Bestellnummer, E-Mail, Name oder Ticket-ID', 'pass-captain' ); ?>">
			<button class="button" type="submit"><?php esc_html_e( 'Filtern', 'pass-captain' ); ?></button>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array_filter( array( 'action' => 'wpcap_orders_csv', 'status' => $filters['status'], 'payment_status' => $filters['payment_status'], 's' => $filters['search'], 'event_id' => $filters['event_id'] ) ), admin_url( 'admin-post.php' ) ), 'wpcap_orders_csv' ) ); ?>"><?php esc_html_e( 'CSV-Export', 'pass-captain' ); ?></a>
		</form>
		<?php echo Queries::event_options_note(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped note. ?>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Bestellung', 'pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Datum', 'pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Käufer', 'pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Event', 'pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Summe', 'pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Status', 'pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Zahlart', 'pass-captain' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $data['rows'] ) : ?><tr><td colspan="7"><?php esc_html_e( 'Keine Bestellungen gefunden.', 'pass-captain' ); ?></td></tr><?php endif; ?>
			<?php foreach ( $data['rows'] as $order ) : ?>
				<tr>
					<td><a href="<?php echo esc_url( self::order_url( $order->order_id ) ); ?>" title="<?php echo esc_attr( $order->public_id ); ?>"><code><?php echo esc_html( \PassCaptain\Frontend\Format::order_reference( $order->public_id ) ); ?></code></a></td>
					<td><?php echo esc_html( Forms::datetime( $order->created_at, wp_timezone_string(), false ) ); ?></td>
					<?php $row_masked = ! empty( $masked[ (int) $order->order_id ] ); ?>
					<td><?php echo esc_html( $row_masked ? DataPolicy::mask_email( $order->customer_email ) : $order->customer_email ); ?><?php $buyer_name = $row_masked ? '' : self::buyer_name( $order ); ?><?php if ( '' !== $buyer_name ) : ?><br><small><?php echo esc_html( $buyer_name ); ?></small><?php endif; ?></td>
					<td><?php echo esc_html( (string) $order->event_names ); ?></td>
					<td><?php echo esc_html( Forms::money( $order->total_minor, $order->currency ) ); ?><?php if ( (int) $order->refunded_minor ) : ?><br><small><?php echo esc_html( sprintf( /* translators: %s: amount */ __( 'erstattet: %s', 'pass-captain' ), Forms::money( $order->refunded_minor, $order->currency ) ) ); ?></small><?php endif; ?></td>
					<td><?php echo esc_html( Forms::status_label( $order->status ) . ' / ' . Forms::status_label( $order->payment_status ) ); ?></td>
					<td><?php echo esc_html( $order->payment_provider ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		Forms::pagination( $data['total'], $page, $per_page, $base );
	}

	/** Billing name or company of an order for the list (A336), masked like elsewhere when not visible. */
	private static function buyer_name( $order ) {
		$billing = \PassCaptain\Billing::for_order( $order );
		if ( ! $billing ) { return ''; }
		$name = trim( (string) ( $billing['first_name'] ?? '' ) . ' ' . (string) ( $billing['last_name'] ?? '' ) );
		$name = '' !== $name ? $name : (string) ( $billing['company'] ?? '' );
		return \PassCaptain\Pii::show( 'buyer_name', $name );
	}

	/** Finds the WooCommerce order id that references a core order (read-only hint). */
	private static function woocommerce_order_id( $order_id ) {
		$wc_order = \PassCaptain\Integrations\WooCommerce\OrderAdapter::wc_order_for_core( $order_id ); // Verified link (A284).
		return $wc_order ? absint( $wc_order->get_id() ) : 0;
	}

	private static function render_detail( $order_id ) {
		$order = Orders::get( $order_id );
		$list_url = Forms::page_url( self::SLUG );
		if ( ! $order || ! Queries::can_access_order( $order_id, self::CAP ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Bestellung nicht gefunden oder keine Berechtigung.', 'pass-captain' ) . '</p></div>';
			echo '<p><a href="' . esc_url( $list_url ) . '">' . esc_html__( '← Zur Bestellliste', 'pass-captain' ) . '</a></p>';
			return;
		}
		\PassCaptain\Privacy\AccessLog::view( 'order_detail', 'order', (int) $order_id );
		$items = Queries::order_items( $order_id );
		// Data minimisation (support sees masked personal data until a per-case unmask) and read audit.
		$masked = DataPolicy::masks_pii( $order_id );
		$tickets = array_map( static function ( $ticket ) use ( $order_id ) { return DataPolicy::ticket_view( $ticket, $order_id ); }, Queries::order_tickets( $order_id ) );
		$is_supplement = \PassCaptain\Exchange\Service::is_supplement( (int) $order_id );
		$has_moves = $is_supplement;
		foreach ( $items as $item ) {
			if ( null !== ( $item->orig_quantity ?? null ) && (int) $item->orig_quantity !== (int) $item->quantity ) { $has_moves = true; }
		}
		AuditTrail::read( 'order_detail', 'order', $order_id, array( 'masked' => $masked ) );
		$refunds = Refunds::for_order( $order_id );
		$is_woo = 'woocommerce' === $order->payment_provider;
		$currency = $order->currency;
		$tz = wp_timezone_string();
		?>
		<p><a href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( '← Zur Bestellliste', 'pass-captain' ); ?></a></p>
		<h1><?php echo esc_html( sprintf( /* translators: %s: order id */ __( 'Bestellung %s', 'pass-captain' ), \PassCaptain\Frontend\Format::order_reference( $order->public_id ) ) ); ?> <small style="font-size:.6em;font-weight:normal;color:#646970"><?php echo esc_html( $order->public_id ); ?></small></h1>
		<?php Forms::render_notices(); ?>
		<?php if ( $is_woo ) :
			$wc_id = self::woocommerce_order_id( $order_id );
			?>
			<div class="notice notice-info inline"><p>
				<?php esc_html_e( 'Diese Bestellung wurde über WooCommerce abgewickelt und ist hier nur lesbar. Zahlungen, Stornos und Erstattungen bitte in WooCommerce durchführen; Tickets werden automatisch widerrufen.', 'pass-captain' ); ?>
				<?php if ( $wc_id ) : ?> <a href="<?php echo esc_url( admin_url( 'post.php?post=' . $wc_id . '&action=edit' ) ); ?>"><?php echo esc_html( sprintf( /* translators: %d: WooCommerce order id */ __( 'WooCommerce-Bestellung #%d öffnen', 'pass-captain' ), $wc_id ) ); ?></a><?php endif; ?>
			</p></div>
		<?php endif; ?>
		<?php $voucher = \PassCaptain\Integrations\Vouchers\Checkout::voucher_state( $order_id ); ?>
		<?php echo self::voucher_released_notice( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the method. ?>
		<?php if ( $voucher && 'commit_failed' === ( $voucher['status'] ?? '' ) ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( sprintf( /* translators: 1: amount, 2: error code */ __( 'Bezahlt, aber der Gutschein über %1$s konnte nicht belastet werden (Fehler: %2$s). Die Tickets sind gültig. Bitte den Betrag nachfordern oder den Gutschein in Voucher Captain manuell belasten.', 'pass-captain' ), Forms::money( (int) $voucher['amount_minor'], $order->currency ), (string) ( $voucher['error'] ?? '' ) ) ); ?></p></div>
		<?php endif; ?>
		<?php if ( $is_supplement ) :
			$exchange_row = \PassCaptain\Exchange\Service::for_supplement( (int) $order_id );
			?>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'Zusatzbestellung zu einem Ticketumtausch (Aufpreis/Gebühr). Das Ticket wird in der ursprünglichen Bestellung geführt.', 'pass-captain' ); ?>
			<?php if ( $exchange_row ) : ?> <a href="<?php echo esc_url( self::order_url( (int) $exchange_row['order_id'] ) ); ?>"><?php esc_html_e( 'Ursprüngliche Bestellung öffnen', 'pass-captain' ); ?></a><?php endif; ?></p></div>
		<?php endif; ?>
		<?php
		global $wpdb;
		$mail_retry = $wpdb->get_row( $wpdb->prepare( "SELECT status,attempts FROM {$wpdb->prefix}wpcap_jobs WHERE job_type='ticket_mail_retry' AND payload IN (%s,%s) AND status IN ('queued','running','dead_letter') ORDER BY job_id DESC LIMIT 1", wp_json_encode( Orders::mail_retry_payload( $order_id ) ), wp_json_encode( Orders::mail_retry_payload( $order_id, true ) ) ) );
		if ( $mail_retry ) :
			?>
			<div class="notice notice-<?php echo 'dead_letter' === $mail_retry->status ? 'error' : 'warning'; ?> inline"><p><?php echo esc_html( 'dead_letter' === $mail_retry->status ? __( 'Die Ticket-E-Mail konnte auch nach mehreren Versuchen nicht zugestellt werden. Bitte E-Mail-Adresse und Mailversand prüfen und „Tickets erneut senden“ verwenden.', 'pass-captain' ) : __( 'Die Ticket-E-Mail konnte nicht zugestellt werden; ein neuer Versuch (mit neuen Ticketcodes) ist geplant.', 'pass-captain' ) ); ?></p></div>
		<?php endif; ?>
		<?php $delivery = 'paid' === $order->payment_status ? Orders::delivery_status( $order_id ) : null; ?>
		<?php if ( $delivery && 'failed' === $delivery['status'] ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( sprintf( /* translators: %d: attempts */ __( 'Die Ticket-E-Mail konnte nach %d Versuchen nicht zugestellt werden. Bitte die E-Mail-Adresse prüfen und die Tickets erneut senden.', 'pass-captain' ), (int) $delivery['attempts'] ) ); ?></p></div>
		<?php elseif ( $delivery && 'retrying' === $delivery['status'] ) : ?>
			<div class="notice notice-warning inline"><p><?php echo esc_html( sprintf( /* translators: 1: attempts, 2: UTC date/time */ __( 'Die Ticket-E-Mail konnte bisher nicht zugestellt werden (%1$d Versuche). Der Versand wird automatisch wiederholt (nächster Versuch: %2$s UTC).', 'pass-captain' ), (int) $delivery['attempts'], (string) $delivery['next_attempt'] ) ); ?></p></div>
		<?php endif; ?>
		<?php $bounce = \PassCaptain\Mail\Suppression::bounce_for_order( $order_id ); ?>
		<?php if ( $bounce ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( 'complaint' === $bounce['type']
				/* translators: %s: UTC date/time */
				? sprintf( __( 'Der Versanddienst meldet eine Spam-Beschwerde der Käufer-Adresse (%s UTC). Erinnerungen gehen nicht mehr an diese Adresse.', 'pass-captain' ), $bounce['at'] )
				/* translators: %s: UTC date/time */
				: sprintf( __( 'Der Versanddienst meldet die Käufer-Adresse als unzustellbar (Bounce, %s UTC). Bitte die E-Mail-Adresse prüfen und die Tickets erneut senden.', 'pass-captain' ), $bounce['at'] ) ); ?></p></div>
		<?php endif; ?>
		<?php if ( 'needs_review' === $order->status ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'Die Zahlung ist nach Ablauf der Reservierung eingegangen und es war kein Kontingent mehr frei. Bitte Platz freigeben und die Zuteilung erneut versuchen oder die Zahlung erstatten und die Bestellung abschließen.', 'pass-captain' ); ?></p>
			<?php if ( 'paid_unallocated' === $order->payment_status && ! $is_woo ) : ?><p><?php Forms::open_form( 'wpcap_retry_allocation', 'wpcap_retry_allocation_' . $order_id, 'style="display:inline"' ); Forms::hidden( 'order_id', $order_id ); ?><button class="button" type="submit"><?php esc_html_e( 'Zuteilung erneut versuchen', 'pass-captain' ); ?></button></form></p>
				<?php if ( current_user_can( Refunds::APPROVE_CAP ) ) : self::render_settle_unallocated( $order ); endif; ?>
			<?php endif; ?></div>
		<?php endif; ?>

		<table class="form-table"><tbody>
			<tr><th><?php esc_html_e( 'Status', 'pass-captain' ); ?></th><td><?php echo esc_html( Forms::status_label( $order->status ) . ' / ' . Forms::status_label( $order->payment_status ) ); ?></td></tr>
			<?php if ( ! empty( $order->attribution ) ) : ?>
				<tr><th><?php esc_html_e( 'Herkunft', 'pass-captain' ); ?></th><td><code><?php echo esc_html( (string) $order->attribution ); ?></code></td></tr>
			<?php endif; ?>
			<tr><th><?php esc_html_e( 'Käufer', 'pass-captain' ); ?></th><td><?php echo esc_html( $masked ? DataPolicy::mask_email( $order->customer_email ) : $order->customer_email ); ?>
				<?php if ( $masked ) : ?>
					<?php Forms::open_form( SecurityScreens::UNMASK_ACTION, SecurityScreens::UNMASK_ACTION . '_' . (int) $order_id, 'style="margin-top:.5em"' ); Forms::hidden( 'order_id', (int) $order_id ); ?>
					<label><?php esc_html_e( 'Grund / Supportfall', 'pass-captain' ); ?> <input type="text" name="reason" required minlength="5" maxlength="190"></label>
					<button class="button button-small" type="submit"><?php esc_html_e( 'Daten für diesen Fall anzeigen (wird protokolliert)', 'pass-captain' ); ?></button>
					</form>
				<?php endif; ?>
			</td></tr>
			<?php $billing = $masked ? array() : \PassCaptain\Billing::for_order( $order ); ?>
			<tr><th><?php esc_html_e( 'Rechnungsdaten', 'pass-captain' ); ?></th><td class="wpcap-order-billing"><?php echo $masked ? esc_html__( 'Maskiert', 'pass-captain' ) : ( $billing ? implode( '<br>', array_map( 'esc_html', array_merge( \PassCaptain\Billing::address_lines( $billing ), \PassCaptain\Billing::detail_lines( $billing ) ) ) ) : esc_html__( 'Keine (Rechnung nur mit E-Mail-Adresse)', 'pass-captain' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each line escaped. ?></td></tr>
			<tr><th><?php esc_html_e( 'Angelegt', 'pass-captain' ); ?></th><td><?php echo esc_html( Forms::datetime( $order->created_at, $tz ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Zahlart / Referenz', 'pass-captain' ); ?></th><td><?php echo esc_html( $order->payment_provider . ( '' !== (string) $order->provider_reference ? ' / ' . $order->provider_reference : '' ) ); ?></td></tr>
			<?php if ( \PassCaptain\PayoutAccount::applies( $order ) ) : $payout = \PassCaptain\PayoutAccount::get( $order ); ?>
			<tr><th><?php esc_html_e( 'Bankverbindung für Erstattungen', 'pass-captain' ); ?></th><td><?php echo $payout ? esc_html( \PassCaptain\Payments\BankTransfer::format_iban( $payout['iban'] ) . ' · ' . $payout['holder'] ) : esc_html__( 'Noch nicht angegeben (Käufer:in kann sie in der Bestellübersicht eintragen).', 'pass-captain' ); ?></td></tr>
			<?php endif; ?>
			<?php
			global $wpdb;
			$invoice = $wpdb->get_row( $wpdb->prepare( 'SELECT invoice_id,invoice_number FROM %i WHERE order_id=%d LIMIT 1', $wpdb->prefix . 'wpcap_invoices', $order_id ) );
			$credit_notes = $invoice ? $wpdb->get_col( $wpdb->prepare( 'SELECT credit_note_number FROM %i WHERE invoice_id=%d ORDER BY credit_note_id', $wpdb->prefix . 'wpcap_credit_notes', $invoice->invoice_id ) ) : array();
			$invoice_url = add_query_arg( array( 'action' => 'wpcap_print_invoice', 'order_id' => (int) $order_id ), admin_url( 'admin-post.php' ) );
			?>
			<tr><th><?php esc_html_e( 'Rechnung', 'pass-captain' ); ?></th><td>
				<?php if ( $invoice ) : ?>
					<a href="<?php echo esc_url( $invoice_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( sprintf( /* translators: %s: invoice number */ __( 'Rechnung %s anzeigen', 'pass-captain' ), $invoice->invoice_number ) ); ?></a>
					<?php if ( $credit_notes ) : ?><br><?php echo esc_html( sprintf( /* translators: %s: credit note numbers */ __( 'Rechnungskorrekturen (in der Rechnungsansicht): %s', 'pass-captain' ), implode( ', ', $credit_notes ) ) ); ?><?php endif; ?>
					<?php $invoice_links = \PassCaptain\Api\Invoices::links_html( (int) $invoice->invoice_id, 'invoice', array( 'audience' => 'admin', 'location' => 'admin_order', 'order_id' => (int) $order_id, 'order_public_id' => (string) $order->public_id ) ); ?>
					<?php if ( '' !== $invoice_links ) : ?><br><?php echo $invoice_links; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in links_html(). ?><?php endif; ?>
				<?php elseif ( 'paid' === $order->payment_status && current_user_can( 'wpcap_view_finance' ) ) : ?>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( $invoice_url, 'wpcap_issue_invoice_' . (int) $order_id ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Rechnung erstellen', 'pass-captain' ); ?></a>
				<?php else : ?>—<?php endif; ?>
			</td></tr>
			<?php
			// Sales model / seller of the customer invoice (docs/SALES-MODELS.md).
			if ( $invoice ) {
				$sales_label = \PassCaptain\Invoices::seller_label( (array) json_decode( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT buyer_snapshot FROM %i WHERE invoice_id=%d', $wpdb->prefix . 'wpcap_invoices', $invoice->invoice_id ) ), true ) );
			} else {
				$sales_context = \PassCaptain\Partners\SalesModel::order_context( $order_id );
				$sales_label = is_wp_error( $sales_context ) ? '' : \PassCaptain\Invoices::seller_label( array( 'sales_model' => $sales_context['model'], 'seller' => $sales_context['seller'] ) );
			}
			?>
			<tr><th><?php esc_html_e( 'Verkaufsmodell', 'pass-captain' ); ?></th><td>
				<?php if ( ! $invoice && isset( $sales_context ) && is_wp_error( $sales_context ) ) : ?>
					<div class="notice notice-error inline"><p><?php echo esc_html( $sales_context->get_error_message() ); ?></p></div>
				<?php else : ?><?php echo esc_html( $sales_label ); ?><?php endif; ?>
			</td></tr>
			<tr><th><?php esc_html_e( 'Version AGB/Verbraucherinfo', 'pass-captain' ); ?></th><td><?php echo esc_html( '' !== (string) $order->terms_version ? $order->terms_version : '—' ); ?>
				<?php
				// A568: wording of the stored version from the append-only archive.
				$legal = '' !== (string) $order->terms_version ? \PassCaptain\LegalVersions::texts( (string) $order->terms_version, (string) $order->locale ) : null;
				if ( $legal ) :
					?>
					<details><summary><?php echo esc_html( $legal['archived'] ? __( 'Text dieser Fassung anzeigen', 'pass-captain' ) : __( 'Text dieser Fassung anzeigen (aktuell gültige Fassung, noch nicht archiviert)', 'pass-captain' ) ); ?></summary>
						<?php foreach ( array( 'terms_text' => __( 'Bedingungen', 'pass-captain' ), 'consumer_info_text' => __( 'Verbraucherinformationen', 'pass-captain' ) ) as $legal_key => $legal_label ) : ?>
							<?php if ( '' !== trim( (string) $legal[ $legal_key ] ) ) : ?><h4><?php echo esc_html( $legal_label ); ?></h4><?php echo wp_kses_post( wpautop( (string) $legal[ $legal_key ] ) ); ?><?php endif; ?>
						<?php endforeach; ?>
					</details>
				<?php elseif ( '' !== (string) $order->terms_version ) : ?>
					<p class="description"><?php esc_html_e( 'Der Text dieser Fassung ist nicht archiviert (Bestellung vor dem Archiv, Schema 3.10.21).', 'pass-captain' ); ?></p>
				<?php endif; ?>
			</td></tr>
		</tbody></table>

		<h2><?php esc_html_e( 'Positionen', 'pass-captain' ); ?></h2>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Ticketart', 'pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Termin', 'pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Menge', 'pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Einzelpreis', 'pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Steuersatz', 'pass-captain' ); ?></th>
				<th><?php esc_html_e( 'enth. Steuer', 'pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Summe', 'pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Erstattet (Stk.)', 'pass-captain' ); ?></th>
				<?php if ( $has_moves ) : ?><th><?php esc_html_e( 'Umgebucht (Stk.)', 'pass-captain' ); ?></th><?php endif; ?>
			</tr></thead>
			<tbody>
			<?php foreach ( $items as $item ) :
				// Quantity, VAT and total as paid (OrderLines); units moved by a ticket exchange are shown
				// separately, so the lines match the invoice.
				$paid_qty = null !== ( $item->orig_quantity ?? null ) ? (int) $item->orig_quantity : (int) $item->quantity;
				$paid_tax = null !== ( $item->orig_tax_minor ?? null ) ? (int) $item->orig_tax_minor : (int) $item->tax_minor;
				$paid_total = null !== ( $item->orig_line_total_minor ?? null ) ? (int) $item->orig_line_total_minor : (int) $item->line_total_minor;
				$moved = $is_supplement ? -(int) $item->quantity : (int) $item->quantity - $paid_qty;
				$refunded_units = $is_supplement ? 0 : (int) $item->refunded_quantity;
				?>
				<tr>
					<td><?php echo esc_html( $item->event_name . ' — ' . $item->name ); ?></td>
					<td><?php echo esc_html( Forms::datetime( $item->starts_at, $item->timezone ) ); ?></td>
					<td><?php echo esc_html( (string) $paid_qty ); ?></td>
					<td><?php echo esc_html( Forms::money( $item->unit_price_minor, $currency ) ); ?></td>
					<td><?php echo esc_html( Forms::percent_input( $item->tax_rate_bp ) . ' %' ); ?><br><small class="wpcap-tax-source"><?php echo esc_html( \PassCaptain\Tax\Profiles::source_label( $item ) ); ?></small></td>
					<td><?php echo esc_html( Forms::money( $paid_tax, $currency ) ); ?></td>
					<td><?php echo esc_html( Forms::money( $paid_total, $currency ) ); ?></td>
					<td><?php echo esc_html( (string) $refunded_units ); ?></td>
					<?php if ( $has_moves ) : ?><td><?php echo esc_html( 0 === $moved ? '—' : sprintf( '%+d', $moved ) ); ?></td><?php endif; ?>
				</tr>
			<?php endforeach; ?>
			</tbody>
			<tfoot>
				<tr><th colspan="6"><?php esc_html_e( 'Zwischensumme', 'pass-captain' ); ?></th><td colspan="<?php echo $has_moves ? 3 : 2; ?>"><?php echo esc_html( Forms::money( $order->subtotal_minor, $currency ) ); ?></td></tr>
				<?php if ( (int) $order->discount_minor ) : ?><tr><th colspan="6"><?php esc_html_e( 'Rabatt', 'pass-captain' ); ?></th><td colspan="<?php echo $has_moves ? 3 : 2; ?>"><?php echo esc_html( '− ' . Forms::money( $order->discount_minor, $currency ) ); ?></td></tr><?php endif; ?>
				<?php $voucher_paid = class_exists( '\\PassCaptain\\Integrations\\Vouchers\\Checkout' ) ? \PassCaptain\Integrations\Vouchers\Checkout::voucher_payment_minor( $order ) : 0; ?>
				<tr><th colspan="6"><?php esc_html_e( 'Gesamt (brutto)', 'pass-captain' ); ?></th><td colspan="<?php echo $has_moves ? 3 : 2; ?>"><strong><?php echo esc_html( Forms::money( (int) $order->total_minor + $voucher_paid, $currency ) ); ?></strong></td></tr>
				<?php if ( $voucher_paid ) : ?>
					<tr><th colspan="6"><?php esc_html_e( 'davon bezahlt mit Mehrzweckgutschein', 'pass-captain' ); ?></th><td colspan="<?php echo $has_moves ? 3 : 2; ?>"><?php echo esc_html( Forms::money( $voucher_paid, $currency ) ); ?></td></tr>
					<tr><th colspan="6"><?php esc_html_e( 'Zahlbetrag', 'pass-captain' ); ?></th><td colspan="<?php echo $has_moves ? 3 : 2; ?>"><?php echo esc_html( Forms::money( $order->total_minor, $currency ) ); ?></td></tr>
				<?php endif; ?>
				<tr><th colspan="6"><?php esc_html_e( 'darin enthaltene Steuer', 'pass-captain' ); ?></th><td colspan="<?php echo $has_moves ? 3 : 2; ?>"><?php echo esc_html( Forms::money( $order->tax_minor, $currency ) ); ?></td></tr>
				<tr><th colspan="6"><?php esc_html_e( 'Erstattet', 'pass-captain' ); ?></th><td colspan="<?php echo $has_moves ? 3 : 2; ?>"><?php echo esc_html( Forms::money( $order->refunded_minor, $currency ) ); ?></td></tr>
			</tfoot>
		</table>

		<?php if ( ! $is_woo ) { self::render_actions( $order ); } ?>

		<h2><?php esc_html_e( 'Tickets', 'pass-captain' ); ?></h2>
		<?php
		$in_progress = array();
		foreach ( $refunds as $refund ) {
			if ( in_array( $refund['status'], array( 'pending_approval', 'approved', 'processing' ), true ) ) {
				$in_progress = array_merge( $in_progress, array_map( 'absint', (array) json_decode( (string) $refund['ticket_ids'], true ) ) );
			}
		}
		$refundable = array_filter( $tickets, static function ( $t ) use ( $in_progress ) { return ! (int) $t->refunded && in_array( $t->status, array( 'valid', 'checked_in' ), true ) && ! in_array( (int) $t->ticket_id, $in_progress, true ); } );
		$can_refund = ! $is_woo && 'paid' === $order->payment_status && $refundable && self::can_request_refunds();
		// Refund preview (query args from "Betrag berechnen"; read-only).
		$preview_in = array();
		$calc_preview = null;
		if ( $can_refund && Forms::query_arg( 'refund_preview' ) ) {
			$preview_in = array(
				'order_id' => (int) $order->order_id,
				'refund_all' => Forms::query_arg( 'refund_all' ),
				'ticket_ids' => array_filter( array_map( 'absint', explode( ',', (string) Forms::query_arg( 'tickets' ) ) ) ),
				'deduct' => (string) Forms::query_arg( 'deduct' ),
				'max_amount' => (string) Forms::query_arg( 'max_amount' ),
			);
			$calc_preview = self::refund_preview( $preview_in );
		}
		$preselected = array_map( 'intval', (array) ( $preview_in['ticket_ids'] ?? array() ) );
		$preview = $can_refund ? self::refund_preview( $order, $refundable ) : array();
		if ( $can_refund ) {
			Forms::open_form( 'wpcap_refund_order', 'wpcap_refund_order_' . $order->order_id, 'id="wpcap-refund-form"' );
			Forms::hidden( 'order_id', $order->order_id );
			Forms::hidden( 'idempotency_key', wp_generate_uuid4() );
		}
		?>
		<?php $wpcap_missing = \PassCaptain\ParticipantFields::missing( array_map( static function ( $t ) { return (int) $t->ticket_id; }, $tickets ) ); // A682. ?>
		<table class="widefat striped">
			<thead><tr>
				<?php if ( $can_refund ) : ?><td class="check-column"></td><?php endif; ?>
				<th><?php esc_html_e( 'Ticket', 'pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Art', 'pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Status', 'pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Teilnehmer', 'pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Angaben', 'pass-captain' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $tickets ) : ?><tr><td colspan="6"><?php esc_html_e( 'Noch keine Tickets ausgestellt.', 'pass-captain' ); ?></td></tr><?php endif; ?>
			<?php foreach ( $tickets as $index => $ticket ) :
				$email = '' !== (string) $ticket->assigned_email ? $ticket->assigned_email : $ticket->attendee_email;
				?>
				<tr>
					<?php if ( $can_refund ) : ?>
						<th class="check-column"><?php if ( isset( $refundable[ $index ] ) ) : ?><input type="checkbox" class="wpcap-refund-ticket" name="ticket_ids[]" value="<?php echo esc_attr( $ticket->ticket_id ); ?>" data-amount="<?php echo esc_attr( (string) ( $preview['per_ticket'][ (int) $ticket->ticket_id ] ?? 0 ) ); ?>"<?php checked( in_array( (int) $ticket->ticket_id, $preselected, true ) ); ?> aria-label="<?php echo esc_attr( sprintf( /* translators: %s: ticket id */ __( 'Ticket %s erstatten', 'pass-captain' ), $ticket->public_id ) ); ?>"><?php endif; ?></th>
					<?php endif; ?>
					<td><a href="<?php echo esc_url( Forms::page_url( TicketsPage::SLUG, array( 's' => $ticket->public_id ) ) ); ?>"><code><?php echo esc_html( $ticket->public_id ); ?></code></a></td>
					<td><?php echo esc_html( $ticket->event_name . ' — ' . $ticket->ticket_name ); ?></td>
					<td><?php echo esc_html( Forms::status_label( $ticket->status ) . ( (int) $ticket->refunded ? ' (' . __( 'erstattet', 'pass-captain' ) . ')' : ( in_array( (int) $ticket->ticket_id, $in_progress, true ) ? ' (' . __( 'Erstattung läuft', 'pass-captain' ) . ')' : '' ) ) ); ?></td>
					<td><?php echo esc_html( trim( (string) $ticket->attendee_name . ' ' . ( $email ? '<' . $email . '>' : '' ) ) ?: '—' ); ?></td>
					<td>
						<?php foreach ( $ticket->answers as $label => $answer ) : ?>
							<div><strong><?php echo esc_html( $label ); ?>:</strong> <?php echo esc_html( $answer ); ?></div>
						<?php endforeach; ?>
						<?php if ( ! empty( $wpcap_missing[ (int) $ticket->ticket_id ] ) ) : ?>
							<div class="wpcap-missing"><strong><?php esc_html_e( 'Fehlt:', 'pass-captain' ); ?></strong> <?php echo esc_html( implode( ', ', $wpcap_missing[ (int) $ticket->ticket_id ] ) ); ?></div>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $can_refund ) : ?>
			<h3><?php esc_html_e( 'Erstatten', 'pass-captain' ); ?></h3>
			<p>
				<label><input type="checkbox" name="refund_all" value="1" id="wpcap-refund-all" aria-describedby="wpcap-refund-all-help"> <?php echo esc_html( sprintf( /* translators: %s: amount */ __( 'Alle offenen Tickets erstatten (Restbetrag der Bestellung: %s)', 'pass-captain' ), Forms::money( (int) $preview['all'], $currency ) ) ); ?></label>
				<span class="description" id="wpcap-refund-all-help"><?php esc_html_e( 'Ersetzt die Einzelauswahl in der Tabelle; diese ist dann gesperrt.', 'pass-captain' ); ?></span><br>
				<label><?php esc_html_e( 'Abzug (optional, z. B. Bearbeitungsgebühr)', 'pass-captain' ); ?> <input type="text" name="deduct" inputmode="decimal" style="width:7em" value="<?php echo esc_attr( (string) ( $preview_in['deduct'] ?? '' ) ); ?>"></label>
				<label><?php esc_html_e( 'Höchstbetrag (optional, Kulanz)', 'pass-captain' ); ?> <input type="text" name="max_amount" inputmode="decimal" style="width:7em" value="<?php echo esc_attr( (string) ( $preview_in['max_amount'] ?? '' ) ); ?>"></label><br>
				<label><?php esc_html_e( 'Grund', 'pass-captain' ); ?> <input type="text" name="reason" class="regular-text" required maxlength="255"></label>
			</p>
			<?php if ( is_array( $calc_preview ) ) : ?>
				<div class="notice notice-info inline" role="status"><p><?php echo esc_html( sprintf( /* translators: 1: tickets, 2: value, 3: fee, 4: refund, 5: VAT */ __( 'Vorschau: %1$d Tickets, Wert %2$s, Abzug %3$s → Erstattung %4$s (darin USt %5$s). Die Rechnungskorrektur weist den erstatteten Betrag aus.', 'pass-captain' ), count( $calc_preview['ticket_ids'] ), Forms::money( (int) $calc_preview['value_minor'], $currency ), Forms::money( (int) $calc_preview['deducted_minor'], $currency ), Forms::money( (int) $calc_preview['amount_minor'], $currency ), Forms::money( (int) $calc_preview['tax_minor'], $currency ) ) ); ?></p></div>
			<?php elseif ( is_wp_error( $calc_preview ) ) : ?>
				<div class="notice notice-error inline" role="alert"><p><?php echo esc_html( $calc_preview->get_error_message() ); ?></p></div>
			<?php endif; ?>
			<p><button class="button" type="submit" name="preview" value="1" formnovalidate><?php esc_html_e( 'Betrag berechnen', 'pass-captain' ); ?></button></p>
			<?php if ( Refunds::used_ticket_ids( array_map( static function ( $t ) { return (int) $t->ticket_id; }, (array) $tickets ) ) && current_user_can( Refunds::APPROVE_CAP ) ) : ?>
				<p><label for="wpcap-used-override"><?php esc_html_e( 'Ausnahme für bereits eingelassene Tickets (Begründung)', 'pass-captain' ); ?></label><br>
				<input type="text" id="wpcap-used-override" name="used_override" class="regular-text" maxlength="200" aria-describedby="wpcap-used-override-help">
				<span id="wpcap-used-override-help" class="description"><?php esc_html_e( 'Nur ausfüllen, wenn eingelassene Tickets trotzdem erstattet werden sollen (z. B. Abbruch der Veranstaltung). Die Erstattung braucht dann immer eine zweite Freigabe.', 'pass-captain' ); ?></span></p>
			<?php endif; ?>
			<div class="wpcap-refund-summary" style="border-left:4px solid #72aee6;padding:.4em .8em;margin:.8em 0;background:#f6f7f7">
				<p><strong><?php esc_html_e( 'Erstattungsbetrag:', 'pass-captain' ); ?></strong> <span id="wpcap-refund-amount" aria-live="polite"><?php esc_html_e( 'Noch keine Tickets gewählt.', 'pass-captain' ); ?></span></p>
				<p><strong><?php esc_html_e( 'Weg:', 'pass-captain' ); ?></strong> <span id="wpcap-refund-route"><?php echo esc_html( $preview['route'] ); ?></span></p>
				<?php if ( $preview['approval_all'] ) : ?><p class="description"><?php esc_html_e( 'Ab einem Betrag über der Freigabegrenze wartet die Erstattung auf eine zweite Person.', 'pass-captain' ); ?></p><?php endif; ?>
			</div>
			<?php if ( (int) \PassCaptain\Settings::get( 'refund_approval_threshold' ) > 0 && ! Refunds::may_self_approve() ) : ?>
				<p class="description"><?php echo esc_html( sprintf( /* translators: %s: amount */ __( 'Übersteigt die Summe aller Erstattungen dieser Bestellung (inklusive laufender und wartender) %s, ist eine zweite Freigabe nötig.', 'pass-captain' ), Forms::money( (int) \PassCaptain\Settings::get( 'refund_approval_threshold' ), $currency ) ) ); ?></p>
			<?php endif; ?>
			<button class="button button-primary" type="submit" id="wpcap-refund-submit"><?php esc_html_e( 'Erstattung auslösen', 'pass-captain' ); ?></button>
			</form>
			<?php self::refund_form_script( $preview, $currency ); ?>
		<?php endif; ?>

		<?php /** Extra sections on the order detail page (e.g. ticket exchange). */ do_action( 'wpcap_admin_order_detail', $order, $tickets ); ?>
		<h2><?php esc_html_e( 'Erstattungen', 'pass-captain' ); ?></h2>
		<?php self::render_refund_table( $refunds, $currency, false ); ?>
		<?php OrderHistory::render( (int) $order_id ); // A328: timeline of audit, payments, mails, jobs and support log. ?>
		<?php
	}

	private static function render_actions( $order ) {
		$can_mark_paid = 'manual' === $order->payment_provider && in_array( $order->payment_status, array( 'pending', 'expired' ), true ) && ! in_array( $order->status, array( 'cancelled', 'refunded' ), true );
		$can_cancel = 'pending' === $order->payment_status && 'pending_payment' === $order->status;
		$can_resend = 'paid' === $order->payment_status;
		if ( ! $can_mark_paid && ! $can_cancel && ! $can_resend ) { return; }
		echo '<h2>' . esc_html__( 'Aktionen', 'pass-captain' ) . '</h2><p>';
		if ( $can_mark_paid ) {
			Forms::open_form( 'wpcap_confirm_payment', 'wpcap_confirm_payment_' . $order->order_id, 'style="display:inline"' );
			Forms::hidden( 'order_id', $order->order_id );
			echo '<button class="button button-primary" type="submit" data-wpcap-confirm="' . esc_attr( __( 'Zahlungseingang bestätigen und Tickets ausstellen?', 'pass-captain' ) ) . '">' . esc_html__( 'Als bezahlt markieren', 'pass-captain' ) . '</button></form> ';
		}
		if ( $can_cancel ) {
			Forms::open_form( 'wpcap_cancel_order', 'wpcap_cancel_order_' . $order->order_id, 'style="display:inline"' );
			Forms::hidden( 'order_id', $order->order_id );
			echo '<input type="text" name="reason" placeholder="' . esc_attr__( 'Grund (optional)', 'pass-captain' ) . '" maxlength="255"> ';
			echo '<button class="button" type="submit" data-wpcap-confirm="' . esc_attr( __( 'Unbezahlte Bestellung stornieren und Reservierung freigeben?', 'pass-captain' ) ) . '">' . esc_html__( 'Stornieren', 'pass-captain' ) . '</button></form> ';
		}
		if ( $can_resend ) {
			// A586: PDF with the current codes (nothing becomes invalid), whole order or one ticket.
			$options = array( '' => __( 'Alle gültigen Tickets', 'pass-captain' ) );
			foreach ( \PassCaptain\PortalSelfService::tickets( $order ) as $ticket ) {
				/* translators: 1: ticket number (short), 2: ticket type */
				if ( 'valid' === (string) $ticket->status && ! (int) $ticket->refunded ) { $options[ (string) $ticket->public_id ] = sprintf( __( 'Ticket %1$s (%2$s)', 'pass-captain' ), substr( (string) $ticket->public_id, 0, 8 ), (string) $ticket->ticket_name ); }
			}
			Forms::open_form( 'wpcap_ticket_pdf', 'wpcap_ticket_pdf_' . $order->order_id, 'style="display:inline"' );
			Forms::hidden( 'order_id', $order->order_id );
			Forms::select( 'ticket', $options, '', 'wpcap-ticket-pdf-' . (int) $order->order_id, 'aria-label="' . esc_attr__( 'Tickets für das PDF', 'pass-captain' ) . '"' );
			echo ' <button class="button" type="submit">' . esc_html__( 'Ticket-PDF herunterladen', 'pass-captain' ) . '</button></form>';
		}
		echo '</p>';
		if ( $can_resend ) { self::render_resend_form( $order ); }
		if ( $can_resend ) {
			Forms::open_form( 'wpcap_send_order_link', 'wpcap_send_order_link_' . $order->order_id, 'class="wpcap-order-link-form"' );
			Forms::hidden( 'order_id', $order->order_id );
			echo '<p><button class="button" type="submit">' . esc_html__( 'Bestelllink erneut senden', 'pass-captain' ) . '</button> <span class="description">' . esc_html__( 'Für Rechnung und Tickets: „Tickets erneut senden“ (die Rechnung hängt an der Ticket-Mail).', 'pass-captain' ) . '</span></p></form>';
		}
		if ( $can_resend && \PassCaptain\ParticipantFields::missing( array_map( static function ( $t ) { return (int) $t->ticket_id; }, Orders::tickets_for_order( (int) $order->order_id ) ) ) ) {
			// A682: ask the buyer for missing attendee data (new order link, at most once per hour).
			Forms::open_form( 'wpcap_request_attendee_data', 'wpcap_request_attendee_data_' . $order->order_id );
			Forms::hidden( 'order_id', $order->order_id );
			echo '<p><button class="button" type="submit">' . esc_html__( 'Fehlende Teilnehmerangaben anfordern', 'pass-captain' ) . '</button> <span class="description">' . esc_html__( 'Die Käufer:in erhält eine E-Mail mit den fehlenden Angaben je Ticket und einem neuen Bestelllink; der bisherige Bestelllink wird ungültig.', 'pass-captain' ) . '</span></p></form>';
		}
	}

	/** A682: "Fehlende Teilnehmerangaben anfordern" (capability of the orders page). */
	public static function request_attendee_data( array $in ) {
		$order = self::order_for_action( $in );
		if ( is_wp_error( $order ) ) { return $order; }
		$result = \PassCaptain\ParticipantFields::request_missing( (int) $order->order_id );
		if ( is_wp_error( $result ) ) {
			$data = (array) $result->get_error_data();
			return Forms::error( $result->get_error_code(), $result->get_error_message(), (int) ( $data['status'] ?? 409 ) );
		}
		return $result;
	}

	public static function handle_request_attendee_data() {
		$in = Forms::post();
		$order_id = Forms::int( $in, 'order_id' );
		Forms::verify( 'wpcap_request_attendee_data_' . $order_id );
		$result = self::request_attendee_data( $in );
		Forms::finish( $result, is_wp_error( $result ) ? '' : __( 'Fehlende Teilnehmerangaben angefordert.', 'pass-captain' ), self::order_url( $order_id ) );
	}

	/**
	 * "Tickets erneut senden" (A335): which tickets get new codes. Default: only tickets that were not
	 * passed on to someone else; transferred tickets are included only on explicit choice.
	 */
	private static function render_resend_form( $order ) {
		$counts = Orders::resend_counts( (int) $order->order_id );
		$id = 'wpcap-resend-' . (int) $order->order_id;
		Forms::open_form( 'wpcap_resend_tickets', 'wpcap_resend_tickets_' . $order->order_id, 'class="wpcap-resend-form"' );
		Forms::hidden( 'order_id', $order->order_id );
		Forms::hidden( 'request_key', wp_generate_uuid4() ); // A533: a double click resends (and rotates) once.
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Tickets erneut senden', 'pass-captain' ) . '</legend>';
		echo '<p class="description" id="' . esc_attr( $id . '-help' ) . '">' . esc_html__( 'Zugestellt nicht angekommen? „Gespeicherte Codes erneut senden“ verschickt dieselben Codes noch einmal – nichts wird ungültig. „Neue Codes“ macht bisher gesendete Codes und Ausdrucke dieser Tickets ungültig (z. B. wenn ein Code weitergegeben wurde).', 'pass-captain' ) . '</p>';
		echo '<p><label><input type="radio" name="codes" value="keep" checked> ' . esc_html__( 'Gespeicherte Codes erneut senden (Tickets ohne gespeicherten Code erhalten einen neuen)', 'pass-captain' ) . '</label><br>';
		echo '<label><input type="radio" name="codes" value="new"> ' . esc_html__( 'Neue Codes ausstellen: bisherige Codes werden ungültig', 'pass-captain' ) . '</label></p>';
		if ( $counts['transferred'] ) {
			/* translators: %d: number of transferred tickets */
			echo '<p><label><input type="radio" name="scope" value="own" checked> ' . esc_html( sprintf( _n( 'Nur nicht übertragene Tickets (empfohlen) – %d übertragenes Ticket behält seinen Code', 'Nur nicht übertragene Tickets (empfohlen) – %d übertragene Tickets behalten ihren Code', $counts['transferred'], 'pass-captain' ), $counts['transferred'] ) ) . '</label><br>';
			echo '<label><input type="radio" name="scope" value="all"> ' . esc_html__( 'Auch übertragene Tickets: deren Empfänger:innen erhalten einen neuen Code, ihr bisheriger wird ungültig', 'pass-captain' ) . '</label></p>';
		} else {
			Forms::hidden( 'scope', 'own' );
		}
		echo '</fieldset>';
		echo '<button class="button" type="submit" aria-describedby="' . esc_attr( $id . '-help' ) . '" data-wpcap-confirm="' . esc_attr( __( 'Tickets erneut senden? Mit „Neue Codes“ werden die bisherigen Codes dieser Tickets ungültig; übertragene Tickets nur, wenn sie ausdrücklich gewählt sind.', 'pass-captain' ) ) . '">' . esc_html__( 'Tickets erneut senden', 'pass-captain' ) . '</button></form>';
	}

	/**
	 * Controls for a refund with an unknown outcome (A350): provider lookup and – with the approval
	 * capability – manual confirmation "paid out" / "not paid out" with a required note.
	 *
	 * @param array  $refund Refund row.
	 * @param string $return Redirect target after the action: '' (order page) or 'operations'.
	 */
	public static function render_refund_resolution( array $refund, $return = '' ) {
		$id = (int) $refund['refund_id'];
		Forms::open_form( 'wpcap_resolve_refund', 'wpcap_resolve_refund_' . $id, 'style="display:inline"' );
		Forms::hidden( 'refund_id', $id );
		Forms::hidden( 'order_id', (int) $refund['order_id'] );
		Forms::hidden( 'do', 'lookup' );
		if ( 'operations' === $return ) { Forms::hidden( 'return', 'operations' ); }
		echo '<button class="button button-small" type="submit">' . esc_html__( 'Beim Anbieter prüfen', 'pass-captain' ) . '</button></form>';
		if ( ! current_user_can( Refunds::APPROVE_CAP ) ) { return; }
		$note_id = 'wpcap-resolve-note-' . $id . ( 'operations' === $return ? '-ops' : '' );
		echo '<details style="margin-top:.4em"><summary>' . esc_html__( 'Manuell bestätigen', 'pass-captain' ) . '</summary>';
		Forms::open_form( 'wpcap_resolve_refund', 'wpcap_resolve_refund_' . $id );
		Forms::hidden( 'refund_id', $id );
		Forms::hidden( 'order_id', (int) $refund['order_id'] );
		if ( 'operations' === $return ) { Forms::hidden( 'return', 'operations' ); }
		echo '<p><label for="' . esc_attr( $note_id ) . '">' . esc_html__( 'Wie wurde der Ausgang geprüft?', 'pass-captain' ) . '</label><br><input type="text" id="' . esc_attr( $note_id ) . '" name="note" required maxlength="200" class="regular-text"></p>';
		echo '<p><label for="' . esc_attr( $note_id . '-ref' ) . '">' . esc_html__( 'Erstattungs-ID beim Anbieter (falls ausgezahlt)', 'pass-captain' ) . '</label><br><input type="text" id="' . esc_attr( $note_id . '-ref' ) . '" name="reference" maxlength="150"></p>';
		echo '<p><button class="button" type="submit" name="do" value="paid" data-wpcap-confirm="' . esc_attr( __( 'Bestätigen, dass der Zahlungsanbieter diese Erstattung ausgezahlt hat? Sie wird dann verbucht (Tickets, Gutschrift).', 'pass-captain' ) ) . '">' . esc_html__( 'Ausgezahlt', 'pass-captain' ) . '</button> ';
		echo '<button class="button" type="submit" name="do" value="not_paid" data-wpcap-confirm="' . esc_attr( __( 'Bestätigen, dass beim Zahlungsanbieter nichts ausgezahlt wurde? Die Erstattung gilt dann als fehlgeschlagen und kann neu angefordert werden.', 'pass-captain' ) ) . '">' . esc_html__( 'Nicht ausgezahlt', 'pass-captain' ) . '</button></p></form></details>';
	}

	/**
	 * Refund table with approve/reject controls for pending refunds.
	 *
	 * @param array[] $refunds  Refund rows (arrays; may contain order_public_id/event_names).
	 * @param string  $currency Fallback currency.
	 * @param bool    $queue    Whether rendered in the refund queue (adds order columns).
	 */
	public static function render_refund_table( array $refunds, $currency, $queue ) {
		$can_approve = current_user_can( Refunds::APPROVE_CAP );
		$cols = $queue ? 9 : 7;
		?>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Datum', 'pass-captain' ); ?></th>
				<?php if ( $queue ) : ?><th><?php esc_html_e( 'Bestellung', 'pass-captain' ); ?></th><th><?php esc_html_e( 'Event', 'pass-captain' ); ?></th><?php endif; ?>
				<th><?php esc_html_e( 'Betrag', 'pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Tickets', 'pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Grund', 'pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Status', 'pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Angefordert / freigegeben von', 'pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Aktion', 'pass-captain' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $refunds ) : ?><tr><td colspan="<?php echo esc_attr( $cols ); ?>"><?php esc_html_e( 'Keine Erstattungen.', 'pass-captain' ); ?></td></tr><?php endif; ?>
			<?php foreach ( $refunds as $refund ) :
				$refund = (array) $refund;
				$ticket_count = count( (array) json_decode( (string) $refund['ticket_ids'], true ) );
				?>
				<tr>
					<td><?php echo esc_html( Forms::datetime( $refund['created_at'], wp_timezone_string(), false ) ); ?></td>
					<?php if ( $queue ) : ?>
						<td><a href="<?php echo esc_url( self::order_url( $refund['order_id'] ) ); ?>"><code><?php echo esc_html( \PassCaptain\Frontend\Format::order_reference( (string) ( $refund['order_public_id'] ?? '' ) ) ); ?></code></a><br><small><?php echo esc_html( (string) ( $refund['customer_email'] ?? '' ) ); ?></small></td>
						<td><?php echo esc_html( (string) ( $refund['event_names'] ?? '' ) ); ?></td>
					<?php endif; ?>
					<td><?php echo esc_html( Forms::money( $refund['amount_minor'], $refund['currency'] ?: $currency ) ); ?><br><small><?php echo esc_html( sprintf( /* translators: %s: tax amount */ __( 'davon Steuer %s', 'pass-captain' ), Forms::money( $refund['tax_minor'], $refund['currency'] ?: $currency ) ) ); ?></small></td>
					<td><?php echo esc_html( (string) $ticket_count ); ?></td>
					<td><?php echo esc_html( $refund['reason'] ); ?></td>
					<td><?php echo esc_html( Refunds::is_unresolved( $refund ) ? __( 'Ausgang unklar', 'pass-captain' ) : Forms::status_label( $refund['status'] ) ); ?><?php if ( Refunds::is_unresolved( $refund ) ) : ?><br><small><?php esc_html_e( 'Der Zahlungsanbieter hat nicht eindeutig geantwortet. Die Erstattung wird automatisch abgeglichen und nicht erneut ausgezahlt.', 'pass-captain' ); ?></small><?php elseif ( '' !== (string) $refund['error_message'] ) : ?><br><small><?php echo esc_html( $refund['error_message'] ); ?></small><?php endif; ?><?php if ( '' !== (string) $refund['provider_reference'] ) : ?><br><small><?php echo esc_html( $refund['provider_reference'] ); ?></small><?php endif; ?><?php if ( 'completed' === $refund['status'] && 'pending' === (string) ( $refund['provider_status'] ?? '' ) ) : ?><br><small><?php esc_html_e( 'beim Zahlungsanbieter noch ausstehend', 'pass-captain' ); ?></small><?php endif; ?></td>
					<td><?php echo esc_html( ( 'portal' === (string) ( $refund['source'] ?? '' ) ? __( 'Käufer:in (Stornierungsanfrage im Bestellportal)', 'pass-captain' ) : Forms::user_label( $refund['requested_by'] ) ) . ' / ' . Forms::user_label( $refund['approved_by'] ) ); ?></td>
					<td>
						<?php if ( Refunds::is_unresolved( $refund ) ) : ?>
							<?php self::render_refund_resolution( $refund ); ?>
						<?php endif; ?>
						<?php if ( 'reversed' === $refund['status'] && self::can_request_refunds() && ! Refunds::find_retry( (int) $refund['refund_id'] ) ) : ?>
							<?php Forms::open_form( 'wpcap_resolve_refund', 'wpcap_resolve_refund_' . $refund['refund_id'], 'style="display:inline"' ); Forms::hidden( 'refund_id', $refund['refund_id'] ); Forms::hidden( 'order_id', $refund['order_id'] ); Forms::hidden( 'do', 'retry' ); ?>
							<button class="button" type="submit" data-wpcap-confirm="<?php echo esc_attr( __( 'Den Betrag dieser gescheiterten Erstattung erneut über den Zahlungsanbieter auszahlen?', 'pass-captain' ) ); ?>"><?php esc_html_e( 'Erneut auszahlen', 'pass-captain' ); ?></button>
							</form>
						<?php endif; ?>
						<?php if ( 'pending_approval' === $refund['status'] ) : ?>
							<?php if ( ! $can_approve ) : ?>
								<?php esc_html_e( 'Wartet auf Freigabe', 'pass-captain' ); ?>
							<?php elseif ( (int) $refund['requested_by'] === get_current_user_id() ) : ?>
								<em><?php esc_html_e( 'Freigabe durch eine andere Person erforderlich', 'pass-captain' ); ?></em>
							<?php else : ?>
								<?php Forms::open_form( 'wpcap_approve_refund', 'wpcap_approve_refund_' . $refund['refund_id'], 'style="display:inline"' ); Forms::hidden( 'refund_id', $refund['refund_id'] ); Forms::hidden( 'order_id', $refund['order_id'] ); if ( $queue ) { Forms::hidden( 'return', 'queue' ); } ?>
								<button class="button button-primary" type="submit" data-wpcap-confirm="<?php echo esc_attr( __( 'Erstattung freigeben und auszahlen?', 'pass-captain' ) ); ?>"><?php esc_html_e( 'Freigeben', 'pass-captain' ); ?></button>
								</form>
								<?php Forms::open_form( 'wpcap_reject_refund', 'wpcap_reject_refund_' . $refund['refund_id'], 'style="display:inline"' ); Forms::hidden( 'refund_id', $refund['refund_id'] ); Forms::hidden( 'order_id', $refund['order_id'] ); if ( $queue ) { Forms::hidden( 'return', 'queue' ); } ?>
								<input type="text" name="reason" required maxlength="255" placeholder="<?php esc_attr_e( 'Grund der Ablehnung', 'pass-captain' ); ?>">
								<button class="button" type="submit"><?php esc_html_e( 'Ablehnen', 'pass-captain' ); ?></button>
								</form>
							<?php endif; ?>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}

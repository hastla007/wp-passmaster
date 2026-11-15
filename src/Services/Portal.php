<?php
/**
 * Public pages for service bookings and waitlists, reached through links in mails:
 *
 * - ?wpcap_service_booking=ID&token=…            booking without order (status, cancel, rebook)
 * - ?wpcap_service_waitlist=ID&do=…&token=…       slot waitlist (status/position, accept, decline, leave)
 * - ?wpcap_waitlist_decline=ID&token=…            event waitlist: decline an offer
 * - ?wpcap_waitlist_leave=ID&token=…              event waitlist: unsubscribe
 * - section in the order portal                   bookings of the order (cancel, rebook)
 *
 * GET only shows a confirmation form (mail scanners and link previews never change anything); the
 * action is a nonce-checked POST to admin-post.php (action wpcap_service_portal), see perform().
 *
 * @package PassCaptain
 */

namespace PassCaptain\Services;

use PassCaptain\ServiceBookings;
use PassCaptain\ServiceResources;
use PassCaptain\ServiceWaitlist;
use PassCaptain\Waitlists;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Portal {
	const ACTION = 'wpcap_service_portal';

	private static function token_from( array $in ) {
		$token = \PassCaptain\Security\Input::hex( $in['token'] ?? '' );
		return 64 === strlen( $token ) ? $token : '';
	}

	/** template_redirect: renders a confirmation page for a mail link. */
	public static function maybe_render() {
		$get = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page; actions are nonce-checked POSTs.
		$token = self::token_from( (array) $get );
		$html = '';
		if ( ! empty( $get['wpcap_service_booking'] ) && $token ) {
			$html = self::booking_page( absint( $get['wpcap_service_booking'] ), $token );
		} elseif ( ! empty( $get['wpcap_service_waitlist'] ) && $token ) {
			$html = self::waitlist_page( absint( $get['wpcap_service_waitlist'] ), sanitize_key( \PassCaptain\Security\Input::text( $get['do'] ?? 'status' ) ), $token );
		} elseif ( ! empty( $get['wpcap_waitlist_decline'] ) && $token ) {
			$html = self::confirm_page( __( 'Wartelistenangebot ablehnen', 'pass-captain' ), __( 'Möchtest du das Angebot ablehnen? Das Ticket geht dann an die nächste Person auf der Warteliste.', 'pass-captain' ), array( 'do' => 'event_decline', 'id' => absint( $get['wpcap_waitlist_decline'] ), 'token' => $token ), __( 'Angebot ablehnen', 'pass-captain' ) );
		} elseif ( ! empty( $get['wpcap_waitlist_leave'] ) && $token ) {
			$html = self::confirm_page( __( 'Von der Warteliste austragen', 'pass-captain' ), __( 'Möchtest du dich von der Warteliste austragen?', 'pass-captain' ), array( 'do' => 'event_leave', 'id' => absint( $get['wpcap_waitlist_leave'] ), 'token' => $token ), __( 'Austragen', 'pass-captain' ) );
		}
		if ( '' === $html ) { return; }
		\PassCaptain\Security\PrivatePage::send(); // No page cache, no framing, no indexing (secret link).
		status_header( 200 );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		exit;
	}

	/** admin-post handler (logged in or not). */
	public static function handle_post() {
		$in = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified below.
		check_admin_referer( self::ACTION . '_' . sanitize_key( \PassCaptain\Security\Input::text( $in['do'] ?? '' ) ) . '_' . absint( $in['id'] ?? 0 ) );
		$result = self::perform( (array) $in );
		$redirect = is_array( $result ) ? (string) ( $result['supplement']['payment']['redirect_url'] ?? '' ) : '';
		if ( '' !== $redirect && wp_http_validate_url( $redirect ) ) {
			// Hosted payment page of the gateway for the supplementary payment (URL from the gateway, not from the request).
			wp_redirect( $redirect, 303 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- payment provider host.
			exit;
		}
		status_header( is_wp_error( $result ) ? 409 : 200 );
		echo self::result_page( $result ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in result_page.
		exit;
	}

	/**
	 * Executes a portal action (testable without a request; nonce checked by handle_post()).
	 *
	 * @param array $in do, id, token | public_id + access_token, slot_id.
	 * @return array|\WP_Error
	 */
	public static function perform( array $in ) {
		$do = sanitize_key( \PassCaptain\Security\Input::text( $in['do'] ?? '' ) );
		$id = absint( $in['id'] ?? 0 );
		$token = self::token_from( $in );
		$key = 'portal-' . substr( hash( 'sha256', \PassCaptain\Security\Input::text( $in['_wpnonce'] ?? '' ) . '|' . $id . '|' . absint( $in['slot_id'] ?? 0 ) ), 0, 24 );
		switch ( $do ) {
			case 'booking_confirm':
				return ServiceBookings::confirm_free( $id, $token );
			case 'booking_cancel':
			case 'booking_rebook':
				$booking = ServiceBookings::with_token( $id, $token );
				if ( ! $booking || (int) $booking->order_id ) { return self::denied(); }
				return 'booking_cancel' === $do ? ServiceBookings::cancel( $id, array( 'actor' => 'customer' ) ) : ServiceBookings::rebook( $id, absint( $in['slot_id'] ?? 0 ), array( 'actor' => 'customer', 'idempotency_key' => $key ) );
			case 'order_supplement_pay':
				// Open supplementary payment of a dearer rebooking (card of the booking or of the supplement itself).
				$booking = ServiceBookings::for_order_access( \PassCaptain\Security\Input::text( $in['public_id'] ?? '' ), \PassCaptain\Security\Input::text( $in['access_token'] ?? '' ), $id );
				if ( ! $booking ) { return self::denied(); }
				$supplement = (int) $booking->supplement_for ? $booking : ServiceBookings::open_supplement( (int) $booking->booking_id );
				$payment = ServiceBookings::supplement_payment( $supplement );
				return is_wp_error( $payment ) ? $payment : array( 'status' => 'supplement_pending', 'supplement' => array( 'amount_minor' => (int) $supplement->amount_minor, 'currency' => (string) $supplement->currency, 'expires_at' => (string) $supplement->expires_at, 'payment' => $payment ) );
			case 'order_cancel':
			case 'order_rebook':
				$booking = ServiceBookings::for_order_access( \PassCaptain\Security\Input::text( $in['public_id'] ?? '' ), \PassCaptain\Security\Input::text( $in['access_token'] ?? '' ), $id );
				if ( ! $booking ) { return self::denied(); }
				return 'order_cancel' === $do ? ServiceBookings::cancel( $id, array( 'actor' => 'customer' ) ) : ServiceBookings::rebook( $id, absint( $in['slot_id'] ?? 0 ), array( 'actor' => 'customer', 'idempotency_key' => $key, 'provider' => sanitize_key( \PassCaptain\Security\Input::text( $in['provider'] ?? '' ) ) ) );
			case 'waitlist_accept':
				return ServiceWaitlist::accept( $id, $token );
			case 'waitlist_decline':
				return ServiceWaitlist::decline( $id, $token );
			case 'waitlist_leave':
				return ServiceWaitlist::leave( $id, $token );
			case 'event_decline':
				return Waitlists::decline_offer( $id, $token );
			case 'event_leave':
				return Waitlists::unsubscribe( $id, $token );
		}
		return new \WP_Error( 'wpcap_portal_action', __( 'Unbekannte Aktion.', 'pass-captain' ), array( 'status' => 404 ) );
	}

	private static function denied() {
		return new \WP_Error( 'wpcap_order_access_denied', __( 'Der Zugriff ist ungültig oder abgelaufen.', 'pass-captain' ), array( 'status' => 403 ) );
	}

	/* ------------------------------------------------------------------ Rendering */

	private static function page( $title, $content ) {
		return '<!doctype html><html lang="' . esc_attr( str_replace( '_', '-', determine_locale() ) ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $title ) . '</title><style>body{font:16px/1.5 system-ui,sans-serif;max-width:40rem;margin:2rem auto;padding:0 1rem;color:#1d2327}.card{border:1px solid #c3c4c7;border-radius:10px;padding:1rem;margin:1rem 0}button{padding:.55rem 1rem;min-height:2.75rem}select{padding:.45rem;max-width:100%}:focus-visible{outline:3px solid #135e96;outline-offset:3px}</style></head><body><main><h1>' . esc_html( $title ) . '</h1>' . $content . '</main></body></html>';
	}

	/** POST form for one action (nonce bound to action and id). */
	public static function form( array $fields, $label, $extra = '', $class = '' ) {
		ob_start();
		wp_nonce_field( self::ACTION . '_' . sanitize_key( (string) $fields['do'] ) . '_' . absint( $fields['id'] ?? 0 ) );
		$nonce = (string) ob_get_clean();
		$html = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . '>' . $nonce . '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		foreach ( $fields as $name => $value ) { $html .= '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">'; }
		return $html . $extra . '<button type="submit">' . esc_html( $label ) . '</button></form>';
	}

	private static function confirm_page( $title, $text, array $fields, $label ) {
		return self::page( $title, '<p>' . esc_html( $text ) . '</p>' . self::form( $fields, $label ) );
	}

	private static function when( $utc, $timezone ) {
		return wp_date( 'd.m.Y H:i', strtotime( $utc . ' UTC' ), new \DateTimeZone( $timezone ) ) . ' (' . $timezone . ')';
	}

	/** Booking card with buyer actions; $base holds the credential fields for the forms. */
	private static function booking_card( $booking, array $base, $prefix ) {
		$view = ServiceBookings::public_view( $booking );
		$html = '<section class="card"><h2>' . esc_html( $view['resource_name'] ) . '</h2><p>' . esc_html( self::when( $view['starts_at'], $view['timezone'] ) ) . ' · ' . esc_html( sprintf( /* translators: %d: places */ _n( '%d Platz', '%d Plätze', $view['quantity'], 'pass-captain' ), $view['quantity'] ) ) . '</p><p>' . esc_html( __( 'Status:', 'pass-captain' ) . ' ' . self::status_label( $view['status'] ) ) . '</p>';
		$fields = $base + array( 'id' => (int) $booking->booking_id );
		if ( $view['can_rebook'] ) {
			$options = ServiceBookings::rebook_options( $booking );
			if ( $options ) {
				$select = '<label>' . esc_html__( 'Neues Zeitfenster', 'pass-captain' ) . ' <select name="slot_id" required>';
				foreach ( $options as $slot ) {
					/* translators: %s: formatted amount */
					$extra_cost = ! empty( $slot->supplement_minor ) ? ' (' . sprintf( __( 'Zuzahlung %s', 'pass-captain' ), \PassCaptain\Frontend\Format::money( (int) $slot->supplement_minor, (string) $booking->currency ) ) . ')' : '';
					$select .= '<option value="' . (int) $slot->slot_id . '">' . esc_html( $slot->resource_name . ' – ' . self::when( $slot->starts_at, $view['timezone'] ) . $extra_cost ) . '</option>';
				}
				$select .= '</select></label> ';
				$html .= self::form( $fields + array( 'do' => $prefix . '_rebook' ), __( 'Umbuchen', 'pass-captain' ), $select );
			}
			/* translators: %s: deadline */
			$html .= '<p><small>' . esc_html( sprintf( __( 'Umbuchen bis %s möglich.', 'pass-captain' ), self::when( $view['rebook_until'], $view['timezone'] ) ) ) . '</small></p>';
		}
		if ( $view['supplement'] && 'order' === $prefix ) {
			$supplement = $view['supplement'];
			/* translators: 1: amount, 2: new slot (resource and date), 3: deadline */
			$html .= '<p><strong>' . esc_html( sprintf( __( 'Umbuchung vorgemerkt: Zuzahlung von %1$s für %2$s offen bis %3$s. Bis dahin bleibt deine bisherige Buchung bestehen.', 'pass-captain' ), \PassCaptain\Frontend\Format::money( $supplement['amount_minor'], $supplement['currency'] ), $supplement['resource_name'] . ' – ' . self::when( $supplement['starts_at'], $view['timezone'] ), self::when( $supplement['expires_at'], $view['timezone'] ) ) ) . '</strong></p>';
			$html .= self::form( $fields + array( 'do' => 'order_supplement_pay' ), __( 'Zuzahlung jetzt bezahlen', 'pass-captain' ) );
		}
		if ( $view['can_cancel'] ) {
			$html .= self::form( $fields + array( 'do' => $prefix . '_cancel' ), __( 'Buchung stornieren', 'pass-captain' ) );
			/* translators: %s: deadline */
			$html .= '<p><small>' . esc_html( sprintf( __( 'Kostenlose Stornierung mit Erstattung bis %s.', 'pass-captain' ), self::when( $view['cancel_until'], $view['timezone'] ) ) ) . '</small></p>';
		}
		return $html . '</section>';
	}

	private static function booking_page( $booking_id, $token ) {
		$booking = ServiceBookings::with_token( $booking_id, $token );
		if ( ! $booking || (int) $booking->order_id ) { return self::page( __( 'Buchung', 'pass-captain' ), '<p>' . esc_html__( 'Der Link ist ungültig.', 'pass-captain' ) . '</p>' ); }
		$content = self::booking_card( $booking, array( 'token' => $token ), 'booking' );
		if ( 'held' === $booking->status && ! (int) ( ServiceBookings::resource_of( $booking )->ticket_type_id ?? 0 ) ) {
			$content .= self::form( array( 'do' => 'booking_confirm', 'id' => (int) $booking->booking_id, 'token' => $token ), __( 'Buchung verbindlich bestätigen', 'pass-captain' ) );
		}
		return self::page( __( 'Deine Buchung', 'pass-captain' ), $content );
	}

	private static function waitlist_page( $entry_id, $do, $token ) {
		$state = ServiceWaitlist::position( $entry_id, $token );
		if ( is_wp_error( $state ) ) { return self::page( __( 'Warteliste', 'pass-captain' ), '<p>' . esc_html( $state->get_error_message() ) . '</p>' ); }
		$entry = ServiceWaitlist::get( $entry_id );
		$slot = ServiceResources::slot( (int) $entry->slot_id );
		$tz = ServiceResources::timezone_of( $slot ? ServiceResources::resource( (int) $slot->resource_id ) : null );
		$content = '<p>' . esc_html( $state['resource_name'] . ' – ' . self::when( $state['starts_at'], $tz ) ) . '</p>';
		$labels = array( 'waiting' => __( 'Wartend', 'pass-captain' ), 'offered' => __( 'Angebot offen', 'pass-captain' ), 'accepted' => __( 'Angenommen', 'pass-captain' ), 'declined' => __( 'Abgelehnt', 'pass-captain' ), 'expired' => __( 'Abgelaufen', 'pass-captain' ), 'left' => __( 'Ausgetragen', 'pass-captain' ) );
		$content .= '<p><strong>' . esc_html( $labels[ $state['status'] ] ?? $state['status'] ) . '</strong>';
		/* translators: %d: position */
		$content .= $state['position'] ? ' · ' . esc_html( sprintf( __( 'Position %d', 'pass-captain' ), $state['position'] ) ) : '';
		$content .= '</p>';
		$fields = array( 'id' => (int) $entry_id, 'token' => $token );
		if ( 'offered' === $state['status'] ) {
			/* translators: %s: expiry */
			$content .= '<p>' . esc_html( sprintf( __( 'Die Plätze sind bis %s für dich reserviert.', 'pass-captain' ), self::when( (string) $state['offer_expires_at'], $tz ) ) ) . '</p>';
			if ( in_array( $do, array( 'accept', 'status' ), true ) ) { $content .= self::form( $fields + array( 'do' => 'waitlist_accept' ), __( 'Angebot annehmen', 'pass-captain' ) ); }
			$content .= self::form( $fields + array( 'do' => 'waitlist_decline' ), __( 'Angebot ablehnen', 'pass-captain' ) );
		}
		if ( in_array( $state['status'], array( 'waiting', 'offered' ), true ) && 'accept' !== $do ) {
			$content .= self::form( $fields + array( 'do' => 'waitlist_leave' ), __( 'Von der Warteliste austragen', 'pass-captain' ) );
		}
		return self::page( __( 'Warteliste', 'pass-captain' ), $content );
	}

	private static function result_page( $result ) {
		if ( is_wp_error( $result ) ) { return self::page( __( 'Nicht möglich', 'pass-captain' ), '<p>' . esc_html( $result->get_error_message() ) . '</p>' ); }
		$status = (string) ( $result['status'] ?? '' );
		$messages = array(
			'cancelled' => __( 'Die Buchung wurde storniert. Eine Bestätigung ist unterwegs; eine Erstattung wird automatisch veranlasst.', 'pass-captain' ),
			'confirmed' => __( 'Deine Buchung ist bestätigt. Eine Bestätigung ist per E-Mail unterwegs.', 'pass-captain' ),
			'pending_confirmation' => __( 'Deine Buchung wartet auf die Bestätigung des Anbieters.', 'pass-captain' ),
			'accepted' => __( 'Angebot angenommen. Die Details (und gegebenenfalls die Zahlungsinformationen) bekommst du per E-Mail.', 'pass-captain' ),
			'declined' => __( 'Angebot abgelehnt. Danke für die Rückmeldung!', 'pass-captain' ),
			'left' => __( 'Du wurdest von der Warteliste ausgetragen.', 'pass-captain' ),
			'unsubscribed' => __( 'Du wurdest von der Warteliste ausgetragen.', 'pass-captain' ),
		);
		if ( isset( $result['supplement'] ) ) {
			$supplement = $result['supplement'];
			$payment = (array) ( $supplement['payment'] ?? array() );
			/* translators: 1: amount, 2: deadline (UTC date/time) */
			$content = '<p>' . esc_html( sprintf( __( 'Das neue Zeitfenster ist teurer. Bitte bezahle die Zuzahlung von %1$s bis %2$s (UTC); danach wird deine Buchung automatisch umgebucht. Bis dahin bleibt deine bisherige Buchung bestehen.', 'pass-captain' ), \PassCaptain\Frontend\Format::money( (int) $supplement['amount_minor'], (string) $supplement['currency'] ), wp_date( 'd.m.Y H:i', strtotime( (string) $supplement['expires_at'] . ' UTC' ), new \DateTimeZone( 'UTC' ) ) ) ) . '</p>';
			if ( ! empty( $payment['redirect_url'] ) ) { $content .= '<p><a href="' . esc_url( (string) $payment['redirect_url'] ) . '">' . esc_html__( 'Zuzahlung jetzt bezahlen', 'pass-captain' ) . '</a></p>'; }
			if ( ! empty( $payment['instructions'] ) ) { $content .= '<p>' . nl2br( esc_html( (string) $payment['instructions'] ) ) . '</p>'; }
			return self::page( __( 'Zuzahlung erforderlich', 'pass-captain' ), $content );
		}
		$text = isset( $result['difference_minor'] ) ? __( 'Die Buchung wurde umgebucht. Eine Bestätigung ist per E-Mail unterwegs.', 'pass-captain' ) : ( $messages[ $status ] ?? __( 'Erledigt.', 'pass-captain' ) );
		return self::page( __( 'Erledigt', 'pass-captain' ), '<p>' . esc_html( $text ) . '</p>' );
	}

	/** Filter wpcap_order_portal_sections: bookings of the order with cancel/rebook forms. */
	public static function order_section( $html, $order, $token ) {
		$bookings = array_filter( ServiceBookings::for_order( (int) $order->order_id ), static function ( $b ) { return in_array( $b['status'], array( 'held', 'pending_confirmation', 'confirmed', 'checked_in', 'cancelled', 'declined' ), true ); } );
		if ( ! $bookings ) { return $html; }
		$out = '<h2>' . esc_html__( 'Terminbuchungen', 'pass-captain' ) . '</h2>';
		foreach ( $bookings as $view ) {
			$out .= self::booking_card( ServiceBookings::get( $view['booking_id'] ), array( 'public_id' => (string) $order->public_id, 'access_token' => (string) $token ), 'order' );
		}
		return $html . $out;
	}

	public static function status_label( $status ) {
		$labels = array( 'held' => __( 'Reserviert (Zahlung offen)', 'pass-captain' ), 'pending_confirmation' => __( 'Wartet auf Bestätigung', 'pass-captain' ), 'confirmed' => __( 'Bestätigt', 'pass-captain' ), 'checked_in' => __( 'Eingecheckt', 'pass-captain' ), 'no_show' => __( 'Nicht erschienen', 'pass-captain' ), 'cancelled' => __( 'Storniert', 'pass-captain' ), 'declined' => __( 'Abgelehnt', 'pass-captain' ), 'expired' => __( 'Abgelaufen', 'pass-captain' ), 'merged' => __( 'Umbuchung übernommen', 'pass-captain' ) );
		return $labels[ $status ] ?? (string) $status;
	}
}

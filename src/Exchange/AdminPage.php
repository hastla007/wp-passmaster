<?php
/**
 * Admin UI for ticket exchanges: "Umtausch" page (ticket search, exchange form per ticket, global
 * policy, event overrides, recent exchanges), a section on the order detail page and the "Umbuchen"
 * action of the ticket list (A339).
 */
namespace PassCaptain\Exchange;

use PassCaptain\Admin\Forms;
use PassCaptain\Admin\OrdersPage;
use PassCaptain\Admin\Queries;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class AdminPage {
	const SLUG = 'wpcap-exchange';
	const CAP = 'wpcap_manage_orders';

	public static function register() {
		add_action( 'wpcap_admin_order_detail', array( __CLASS__, 'order_section' ), 10, 2 );
	}

	public static function url( array $args = array() ) {
		return Forms::page_url( self::SLUG, $args );
	}

	private static function status_label( $status ) {
		$labels = array(
			Service::PENDING => __( 'Wartet auf Aufpreis', 'pass-captain' ),
			Service::COMPLETED => __( 'Umgetauscht', 'pass-captain' ),
			Service::EXPIRED => __( 'Reservierung abgelaufen', 'pass-captain' ),
			Service::CANCELLED => __( 'Abgebrochen', 'pass-captain' ),
			Service::REVIEW => __( 'Prüfen (bezahlt, nicht durchführbar)', 'pass-captain' ),
		);
		return $labels[ $status ] ?? $status;
	}

	/** A412: translated channel of an exchange (admin, portal, pos, api). */
	public static function channel_label( $channel ) {
		$labels = array(
			'admin'  => __( 'Verwaltung', 'pass-captain' ),
			'portal' => __( 'Bestellportal', 'pass-captain' ),
			'pos'    => __( 'Kasse', 'pass-captain' ),
			'api'    => __( 'API', 'pass-captain' ),
		);
		return $labels[ (string) $channel ] ?? (string) $channel;
	}

	/** A412: link to an order, labelled with its order number ("AB12-CD34") instead of the internal id. */
	private static function order_link( $order_id ) {
		$order = \PassCaptain\Orders::get( (int) $order_id );
		$label = $order && '' !== (string) ( $order->public_id ?? '' ) ? \PassCaptain\Frontend\Format::order_reference( $order->public_id ) : '#' . (int) $order_id;
		return '<a href="' . esc_url( OrdersPage::order_url( (int) $order_id ) ) . '">' . esc_html( $label ) . '</a>';
	}

	/**
	 * Exchange by staff (admin page / order detail).
	 *
	 * @param array $in ticket_id, ticket_type_id, seat_id, waive, refund_policy ('', 'refund', 'none'), idempotency_key, note.
	 * @return array|\WP_Error
	 */
	public static function exchange( array $in ) {
		$ticket = Service::ticket( absint( $in['ticket_id'] ?? 0 ) );
		if ( ! $ticket || ! Queries::can_access_order( (int) $ticket->order_id, self::CAP ) ) {
			return Forms::error( 'wpcap_admin_forbidden', __( 'Ticket nicht gefunden oder keine Berechtigung.', 'pass-captain' ), 403 );
		}
		$refund_policy = in_array( $in['refund_policy'] ?? '', array( 'refund', 'none' ), true ) ? $in['refund_policy'] : '';
		$args = array(
			'channel' => 'admin',
			'idempotency_key' => sanitize_text_field( (string) ( $in['idempotency_key'] ?? '' ) ),
			'seat_id' => absint( $in['seat_id'] ?? 0 ),
			'waive' => ! empty( $in['waive'] ),
			'note' => sanitize_text_field( (string) ( $in['note'] ?? '' ) ),
		);
		if ( is_array( $in['billing'] ?? null ) ) { $args['billing'] = $in['billing']; } // Needed above the billing threshold.
		if ( '' !== $refund_policy ) { $args['refund_policy'] = $refund_policy; }
		return Service::request( (int) $ticket->ticket_id, absint( $in['ticket_type_id'] ?? 0 ), $args );
	}

	public static function handle_exchange() {
		$in = Forms::post();
		$ticket_id = absint( $in['ticket_id'] ?? 0 );
		Forms::verify( 'wpcap_exchange_ticket_' . $ticket_id );
		$result = self::exchange( $in );
		$ticket = Service::ticket( $ticket_id );
		$back = $ticket ? OrdersPage::order_url( (int) $ticket->order_id ) : self::url();
		$message = __( 'Ticket umgetauscht. Das neue Ticket wurde per E-Mail gesendet.', 'pass-captain' );
		if ( ! is_wp_error( $result ) && Service::PENDING === $result['status'] ) {
			$message = __( 'Umtausch angelegt. Der Zielplatz ist reserviert; das neue Ticket wird nach Zahlung des Aufpreises ausgestellt (Zusatzbestellung).', 'pass-captain' );
		}
		Forms::finish( $result, $message, $back, self::url( array( 'ticket_id' => $ticket_id ) ) );
	}

	public static function handle_settings() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		Forms::verify( 'wpcap_exchange_settings' );
		$in = Forms::post();
		$fee = Forms::parse_money( $in['fee'] ?? '0' );
		if ( null === $fee ) { Forms::finish( Forms::error( 'wpcap_exchange_fee', __( 'Bitte eine gültige Gebühr angeben.', 'pass-captain' ) ), '', self::url() ); }
		Policy::save_global( array(
			'enabled' => Forms::flag( $in, 'enabled' ),
			'portal_enabled' => Forms::flag( $in, 'portal_enabled' ),
			'portal_hours' => Forms::int( $in, 'portal_hours', 24 ),
			'portal_max_per_ticket' => Forms::int( $in, 'portal_max_per_ticket', 1 ),
			'refund_policy' => Forms::choice( $in, 'refund_policy', array( 'refund', 'none' ), 'refund' ),
			'fee_minor' => (int) $fee,
			'allow_series' => Forms::flag( $in, 'allow_series' ),
		) );
		Forms::finish( true, __( 'Umtausch-Einstellungen gespeichert.', 'pass-captain' ), self::url() );
	}

	public static function handle_event_policy() {
		Forms::verify( 'wpcap_exchange_event_policy' );
		$in = Forms::post();
		$event_id = absint( $in['event_id'] ?? 0 );
		if ( ! \PassCaptain\Access::can_access_event( $event_id, 'wpcap_manage_events' ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		$values = array();
		foreach ( array( 'enabled', 'portal_enabled', 'refund_policy' ) as $key ) {
			$values[ $key ] = '' === (string) ( $in[ $key ] ?? '' ) ? null : sanitize_key( (string) $in[ $key ] );
		}
		foreach ( array( 'portal_hours', 'portal_max_per_ticket' ) as $key ) {
			$values[ $key ] = '' === trim( (string) ( $in[ $key ] ?? '' ) ) ? null : absint( $in[ $key ] );
		}
		$values['fee_minor'] = '' === trim( (string) ( $in['fee'] ?? '' ) ) ? null : Forms::parse_money( $in['fee'] );
		Forms::finish( Policy::save_event( $event_id, $values ), __( 'Umtausch-Regeln des Events gespeichert.', 'pass-captain' ), self::url() );
	}

	/** Section on the order detail page: exchanges of the order and links per valid ticket. */
	public static function order_section( $order, $tickets = array() ) {
		if ( ! is_object( $order ) ) { return; }
		$exchanges = Service::for_order( (int) $order->order_id );
		$valid = 'paid' === $order->payment_status ? array_filter( (array) $tickets, static function ( $t ) { return 'valid' === $t->status && ! (int) $t->refunded; } ) : array();
		if ( ! $exchanges && ! $valid ) { return; }
		echo '<h2>' . esc_html__( 'Umtausch', 'pass-captain' ) . '</h2>';
		if ( $valid && current_user_can( self::CAP ) ) {
			echo '<p>';
			foreach ( $valid as $ticket ) {
				echo '<a class="button" href="' . esc_url( self::url( array( 'ticket_id' => (int) $ticket->ticket_id ) ) ) . '">' . esc_html( sprintf( /* translators: %s: ticket */ __( 'Umtauschen: %s', 'pass-captain' ), $ticket->event_name . ' — ' . $ticket->ticket_name . ' (' . substr( (string) $ticket->public_id, 0, 8 ) . ')' ) ) . '</a> ';
			}
			echo '</p>';
		}
		if ( $exchanges ) { self::render_table( $exchanges ); }
	}

	private static function render_table( array $rows ) {
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Datum', 'pass-captain' ) . '</th><th>' . esc_html__( 'Von → Nach', 'pass-captain' ) . '</th><th>' . esc_html__( 'Kanal', 'pass-captain' ) . '</th><th>' . esc_html__( 'Differenz', 'pass-captain' ) . '</th><th>' . esc_html__( 'Gebühr', 'pass-captain' ) . '</th><th>' . esc_html__( 'Aufpreis / Erstattung', 'pass-captain' ) . '</th><th>' . esc_html__( 'Status', 'pass-captain' ) . '</th><th>' . esc_html__( 'Bestellungen', 'pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$money = static function ( $minor ) use ( $row ) { return Forms::money( (int) $minor, $row['currency'] ); };
			$settle = (int) $row['due_minor'] > 0 ? sprintf( /* translators: %s: amount */ __( 'Aufpreis %s', 'pass-captain' ), $money( $row['due_minor'] ) ) : ( (int) $row['refund_minor'] > 0 ? sprintf( /* translators: %s: amount */ __( 'Erstattung %s', 'pass-captain' ), $money( $row['refund_minor'] ) ) : ( (int) $row['waived'] ? __( 'Kulanz', 'pass-captain' ) : '—' ) );
			if ( 'woocommerce' === (string) ( $row['refund_status'] ?? '' ) ) {
				$settle .= ' – ' . __( 'in WooCommerce erstatten', 'pass-captain' );
			} elseif ( 'error' === (string) ( $row['refund_status'] ?? '' ) ) {
				$settle .= ' – ' . __( 'Erstattung fehlgeschlagen, bitte manuell erstatten', 'pass-captain' );
			}
			$orders = self::order_link( (int) $row['order_id'] );
			if ( (int) $row['supplementary_order_id'] ) { $orders .= ' + ' . self::order_link( (int) $row['supplementary_order_id'] ); }
			// A412: time in the site time zone, channel translated, orders by their order number.
			echo '<tr><td>' . esc_html( \PassCaptain\Frontend\Format::site_datetime( (string) $row['created_at'] ) ) . '</td><td>' . esc_html( self::type_label( (int) $row['from_type_id'] ) . ' → ' . self::type_label( (int) $row['to_type_id'] ) ) . '</td><td>' . esc_html( self::channel_label( $row['channel'] ) ) . '</td><td>' . esc_html( $money( $row['difference_minor'] ) ) . '</td><td>' . esc_html( $money( $row['fee_minor'] ) ) . '</td><td>' . esc_html( $settle ) . '</td><td>' . esc_html( self::status_label( $row['status'] ) ) . '</td><td>' . $orders . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $orders is built from escaped parts.
		}
		echo '</tbody></table>';
	}

	private static function type_label( $type_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT tt.name,o.starts_at,o.timezone FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=%d", absint( $type_id ) ) );
		return $row ? $row->name . ' (' . Forms::datetime( $row->starts_at, $row->timezone, false ) . ')' : '#' . absint( $type_id );
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		echo '<div class="wrap"><h1>' . esc_html__( 'Ticketumtausch', 'pass-captain' ) . '</h1>';
		Forms::render_notices();
		$ticket_id = absint( Forms::query_arg( 'ticket_id', 0 ) );
		if ( $ticket_id ) { self::render_exchange_form( $ticket_id ); } else { self::render_ticket_search(); }
		if ( current_user_can( 'manage_options' ) ) { self::render_settings(); }
		self::render_event_policy();
		global $wpdb;
		$scope = Queries::event_ids_for( array( self::CAP ) );
		$rows = $wpdb->get_results( 'SELECT * FROM ' . Service::table() . ' ORDER BY exchange_id DESC LIMIT 50', ARRAY_A );
		if ( null !== $scope ) {
			$rows = array_values( array_filter( $rows, static function ( $row ) { return Queries::can_access_order( (int) $row['order_id'], self::CAP ); } ) );
		}
		echo '<h2>' . esc_html__( 'Letzte Umtausche', 'pass-captain' ) . '</h2>';
		if ( $rows ) { \PassCaptain\Privacy\AccessLog::list_view( 'exchange_list', array(), count( $rows ) ); self::render_table( $rows ); } else { echo '<p>' . esc_html__( 'Noch keine Umtausche.', 'pass-captain' ) . '</p>'; }
		echo '</div>';
	}

	/**
	 * Valid tickets matching a search (same search as the ticket list: ticket/order number, help code,
	 * e-mail, name) that the current user may exchange (A339). At most 20.
	 *
	 * @return object[] Rows of Queries::search_tickets().
	 */
	public static function find_tickets( $search ) {
		$search = trim( (string) $search );
		if ( '' === $search || ! current_user_can( self::CAP ) ) { return array(); }
		$rows = Queries::search_tickets( array( 'search' => $search, 'status' => 'valid' ), Queries::event_ids_for( array( self::CAP ) ), 1, 20 )['rows'];
		return array_values( array_filter( (array) $rows, static function ( $t ) { return ! (int) $t->refunded && Queries::can_access_order( (int) $t->order_id, self::CAP ); } ) );
	}

	/** Ticket search on the exchange page (A339): rebooking no longer needs the way via the order. */
	private static function render_ticket_search() {
		$search = Forms::query_arg( 'ticket_search' );
		$placeholder = __( 'Ticket-ID, Bestellnummer, Hilfscode, E-Mail oder Name', 'pass-captain' );
		echo '<h2>' . esc_html__( 'Ticket umbuchen oder umtauschen', 'pass-captain' ) . '</h2>';
		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><label for="wpcap-exchange-search">' . esc_html__( 'Ticket suchen', 'pass-captain' ) . '</label> <input type="search" id="wpcap-exchange-search" name="ticket_search" class="regular-text" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr( $placeholder ) . '"> <button class="button" type="submit">' . esc_html__( 'Suchen', 'pass-captain' ) . '</button></form>';
		if ( '' === $search ) {
			echo '<p class="description">' . esc_html__( 'Auf einen anderen Termin oder eine andere Ticketart umbuchen: Ticket suchen und „Umbuchen“ wählen. Das geht auch direkt aus der Ticketliste und dem Bestelldetail.', 'pass-captain' ) . '</p>';
			return;
		}
		$rows = self::find_tickets( $search );
		\PassCaptain\Privacy\AccessLog::list_view( 'exchange_ticket_search', array( 'search' => $search ), count( $rows ) );
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'Keine gültigen Tickets gefunden, die du umbuchen darfst.', 'pass-captain' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Ticket', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Event / Termin', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Käufer', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Aktion', 'pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $ticket ) {
			echo '<tr><td><code>' . esc_html( (string) $ticket->public_id ) . '</code><br><small>' . esc_html( (string) $ticket->ticket_name ) . '</small></td>';
			echo '<td>' . esc_html( (string) $ticket->event_name ) . '<br><small>' . esc_html( Forms::datetime( $ticket->starts_at, $ticket->timezone ) ) . '</small></td>';
			echo '<td>' . esc_html( \PassCaptain\Pii::show( 'buyer_email', (string) $ticket->customer_email ) ) . '<br><small>' . esc_html( \PassCaptain\Frontend\Format::order_reference( (string) $ticket->order_public_id ) ) . '</small></td>';
			echo '<td><a class="button" href="' . esc_url( self::url( array( 'ticket_id' => (int) $ticket->ticket_id ) ) ) . '">' . esc_html__( 'Umbuchen', 'pass-captain' ) . '</a></td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_exchange_form( $ticket_id ) {
		$ticket = Service::ticket( $ticket_id );
		if ( ! $ticket || ! Queries::can_access_order( (int) $ticket->order_id, self::CAP ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Ticket nicht gefunden oder keine Berechtigung.', 'pass-captain' ) . '</p></div>';
			return;
		}
		echo '<h2>' . esc_html( sprintf( /* translators: 1: event, 2: ticket type, 3: date */ __( 'Ticket umtauschen: %1$s — %2$s (%3$s)', 'pass-captain' ), $ticket->event_name, $ticket->ticket_name, Forms::datetime( $ticket->starts_at, $ticket->timezone ) ) ) . '</h2>';
		\PassCaptain\Privacy\AccessLog::view( 'exchange_ticket', 'ticket', (int) $ticket_id );
		$options = Service::options( $ticket_id, 'admin' );
		if ( 'valid' !== $ticket->status || ! $options ) {
			echo '<p>' . esc_html__( 'Für dieses Ticket ist derzeit kein Umtausch möglich (Status, Zahlung oder keine verfügbaren Ziele).', 'pass-captain' ) . '</p>';
			return;
		}
		$value = Service::ticket_value( $ticket );
		echo '<p>' . esc_html( sprintf( /* translators: %s: amount */ __( 'Wert des bisherigen Tickets: %s', 'pass-captain' ), Forms::money( $value, $ticket->currency ) ) ) . '</p>';
		Forms::open_form( 'wpcap_exchange_ticket', 'wpcap_exchange_ticket_' . $ticket_id );
		Forms::hidden( 'ticket_id', $ticket_id );
		Forms::hidden( 'idempotency_key', wp_generate_uuid4() );
		echo '<table class="form-table"><tr><th><label for="wpcap-exchange-target">' . esc_html__( 'Ziel', 'pass-captain' ) . '</label></th><td><select name="ticket_type_id" id="wpcap-exchange-target" required>';
		$seated = array();
		foreach ( $options as $option ) {
			$left = null === $option['available'] ? __( 'unbegrenzt', 'pass-captain' ) : (string) $option['available'];
			$label = $option['event_name'] . ' — ' . $option['ticket_name'] . ' — ' . Forms::datetime( $option['starts_at'], $option['timezone'] ) . ' — ' . Forms::money( $option['price_minor'], $option['currency'] ) . ' (' . sprintf( /* translators: %s: difference */ __( 'Differenz %s', 'pass-captain' ), Forms::money( $option['difference_minor'], $option['currency'] ) ) . ', ' . sprintf( /* translators: %s: remaining */ __( 'frei: %s', 'pass-captain' ), $left ) . ')';
			echo '<option value="' . esc_attr( (string) $option['ticket_type_id'] ) . '"' . disabled( null !== $option['available'] && $option['available'] < 1, true, false ) . '>' . esc_html( $label ) . '</option>';
			if ( $option['seated'] ) { $seated[] = $option; }
		}
		echo '</select></td></tr>';
		if ( $seated ) {
			echo '<tr><th><label for="wpcap-exchange-seat">' . esc_html__( 'Sitzplatz', 'pass-captain' ) . '</label></th><td><select name="seat_id" id="wpcap-exchange-seat"><option value="0">' . esc_html__( 'Besten freien Platz automatisch wählen', 'pass-captain' ) . '</option>';
			foreach ( $seated as $option ) {
				echo '<optgroup label="' . esc_attr( $option['ticket_name'] . ' — ' . Forms::datetime( $option['starts_at'], $option['timezone'] ) ) . '">';
				foreach ( Service::free_seats( (int) $option['occurrence_id'], (int) $option['ticket_type_id'] ) as $seat_id => $label ) {
					echo '<option value="' . esc_attr( (string) $seat_id ) . '">' . esc_html( $label ) . '</option>';
				}
				echo '</optgroup>';
			}
			echo '</select><p class="description">' . esc_html__( 'Der Platz muss zur Ticketart des Ziels passen.', 'pass-captain' ) . '</p></td></tr>';
		}
		if ( current_user_can( Service::COMP_CAP ) ) {
			echo '<tr><th>' . esc_html__( 'Kulanz', 'pass-captain' ) . '</th><td><label><input type="checkbox" name="waive" value="1"> ' . esc_html__( 'Ohne Aufpreis und Gebühr umtauschen', 'pass-captain' ) . '</label></td></tr>';
		}
		echo '<tr><th><label for="wpcap-exchange-refund">' . esc_html__( 'Günstigeres Ziel', 'pass-captain' ) . '</label></th><td>';
		Forms::select( 'refund_policy', array( '' => __( 'Regel des Events', 'pass-captain' ), 'refund' => __( 'Differenz erstatten', 'pass-captain' ), 'none' => __( 'Keine Erstattung', 'pass-captain' ) ), '', 'wpcap-exchange-refund' );
		echo '</td></tr><tr><th><label for="wpcap-exchange-note">' . esc_html__( 'Notiz', 'pass-captain' ) . '</label></th><td><input type="text" name="note" id="wpcap-exchange-note" class="regular-text" maxlength="255"></td></tr></table>';
		echo \PassCaptain\Billing::fields_html( 'wpcap-exchange-billing' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Billing::fields_html().
		echo '<p><button class="button button-primary" type="submit" data-wpcap-confirm="' . esc_attr( __( 'Ticket jetzt umtauschen? Der bisherige Ticketcode wird ungültig.', 'pass-captain' ) ) . '">' . esc_html__( 'Umtauschen', 'pass-captain' ) . '</button></p></form>';
	}

	private static function render_settings() {
		$policy = Policy::global_policy();
		echo '<h2>' . esc_html__( 'Einstellungen (global)', 'pass-captain' ) . '</h2>';
		Forms::open_form( 'wpcap_exchange_settings' );
		echo '<table class="form-table">';
		echo '<tr><th>' . esc_html__( 'Umtausch durch Mitarbeitende', 'pass-captain' ) . '</th><td><label><input type="checkbox" name="enabled" value="1"' . checked( 1, $policy['enabled'], false ) . '> ' . esc_html__( 'Erlaubt (Admin, Abendkasse)', 'pass-captain' ) . '</label></td></tr>';
		echo '<tr><th>' . esc_html__( 'Selbstbedienung', 'pass-captain' ) . '</th><td><label><input type="checkbox" name="portal_enabled" value="1"' . checked( 1, $policy['portal_enabled'], false ) . '> ' . esc_html__( 'Käufer:innen dürfen im Bestellportal umtauschen (nur innerhalb desselben Events)', 'pass-captain' ) . '</label></td></tr>';
		echo '<tr><th><label for="wpcap-ex-hours">' . esc_html__( 'Frist (Stunden vor Beginn)', 'pass-captain' ) . '</label></th><td><input type="number" min="0" id="wpcap-ex-hours" name="portal_hours" value="' . esc_attr( (string) $policy['portal_hours'] ) . '"></td></tr>';
		echo '<tr><th><label for="wpcap-ex-max">' . esc_html__( 'Höchstens Umtausche pro Ticket (Selbstbedienung)', 'pass-captain' ) . '</label></th><td><input type="number" min="0" id="wpcap-ex-max" name="portal_max_per_ticket" value="' . esc_attr( (string) $policy['portal_max_per_ticket'] ) . '"></td></tr>';
		echo '<tr><th><label for="wpcap-ex-refund">' . esc_html__( 'Günstigeres Ziel', 'pass-captain' ) . '</label></th><td>';
		Forms::select( 'refund_policy', array( 'refund' => __( 'Differenz erstatten (Freigaberegeln gelten)', 'pass-captain' ), 'none' => __( 'Keine Erstattung', 'pass-captain' ) ), $policy['refund_policy'], 'wpcap-ex-refund' );
		echo '</td></tr>';
		echo '<tr><th><label for="wpcap-ex-fee">' . esc_html__( 'Umtauschgebühr (brutto)', 'pass-captain' ) . '</label></th><td><input type="text" id="wpcap-ex-fee" name="fee" value="' . esc_attr( Forms::money_input( $policy['fee_minor'] ) ) . '"><p class="description">' . esc_html__( 'Wird als eigene Position der Zusatzbestellung berechnet; bei günstigerem Ziel mit der Erstattung verrechnet.', 'pass-captain' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Serien', 'pass-captain' ) . '</th><td><label><input type="checkbox" name="allow_series" value="1"' . checked( 1, $policy['allow_series'], false ) . '> ' . esc_html__( 'Mitarbeitende dürfen in andere Events derselben Serie umtauschen', 'pass-captain' ) . '</label></td></tr>';
		echo '</table><p><button class="button button-primary" type="submit">' . esc_html__( 'Speichern', 'pass-captain' ) . '</button></p></form>';
	}

	private static function render_event_policy() {
		$events = Queries::event_options( Queries::event_ids_for( array( 'wpcap_manage_events' ) ) );
		if ( ! $events ) { return; }
		$inherit = array( '' => __( 'wie global', 'pass-captain' ) );
		echo '<h2>' . esc_html__( 'Regeln pro Event', 'pass-captain' ) . '</h2><p class="description">' . esc_html__( 'Leere Felder übernehmen die globale Einstellung.', 'pass-captain' ) . '</p>';
		Forms::open_form( 'wpcap_exchange_event_policy' );
		echo '<table class="form-table"><tr><th>' . esc_html__( 'Event', 'pass-captain' ) . '</th><td>';
		Forms::select( 'event_id', $events, '' );
		echo '</td></tr><tr><th>' . esc_html__( 'Umtausch', 'pass-captain' ) . '</th><td>';
		Forms::select( 'enabled', $inherit + array( '1' => __( 'erlaubt', 'pass-captain' ), '0' => __( 'nicht erlaubt', 'pass-captain' ) ), '' );
		echo '</td></tr><tr><th>' . esc_html__( 'Selbstbedienung', 'pass-captain' ) . '</th><td>';
		Forms::select( 'portal_enabled', $inherit + array( '1' => __( 'erlaubt', 'pass-captain' ), '0' => __( 'nicht erlaubt', 'pass-captain' ) ), '' );
		echo '</td></tr><tr><th>' . esc_html__( 'Frist (Stunden)', 'pass-captain' ) . '</th><td><input type="number" min="0" name="portal_hours"></td></tr>';
		echo '<tr><th>' . esc_html__( 'Höchstens pro Ticket', 'pass-captain' ) . '</th><td><input type="number" min="0" name="portal_max_per_ticket"></td></tr><tr><th>' . esc_html__( 'Günstigeres Ziel', 'pass-captain' ) . '</th><td>';
		Forms::select( 'refund_policy', $inherit + array( 'refund' => __( 'Differenz erstatten', 'pass-captain' ), 'none' => __( 'Keine Erstattung', 'pass-captain' ) ), '' );
		echo '</td></tr><tr><th>' . esc_html__( 'Gebühr (brutto)', 'pass-captain' ) . '</th><td><input type="text" name="fee"></td></tr></table>';
		echo '<p><button class="button" type="submit">' . esc_html__( 'Event-Regeln speichern', 'pass-captain' ) . '</button></p></form>';
	}
}

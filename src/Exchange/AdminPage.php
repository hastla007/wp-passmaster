<?php
/**
 * Admin UI for ticket exchanges: "Umtausch" page (exchange form per ticket, global policy, event
 * overrides, recent exchanges) and a section on the order detail page.
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
			Service::PENDING => __( 'Wartet auf Aufpreis', 'wp-pass-captain' ),
			Service::COMPLETED => __( 'Umgetauscht', 'wp-pass-captain' ),
			Service::EXPIRED => __( 'Reservierung abgelaufen', 'wp-pass-captain' ),
			Service::CANCELLED => __( 'Abgebrochen', 'wp-pass-captain' ),
			Service::REVIEW => __( 'Prüfen (bezahlt, nicht durchführbar)', 'wp-pass-captain' ),
		);
		return $labels[ $status ] ?? $status;
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
			return Forms::error( 'wpcap_admin_forbidden', __( 'Ticket nicht gefunden oder keine Berechtigung.', 'wp-pass-captain' ), 403 );
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
		$message = __( 'Ticket umgetauscht. Das neue Ticket wurde per E-Mail gesendet.', 'wp-pass-captain' );
		if ( ! is_wp_error( $result ) && Service::PENDING === $result['status'] ) {
			$message = __( 'Umtausch angelegt. Der Zielplatz ist reserviert; das neue Ticket wird nach Zahlung des Aufpreises ausgestellt (Zusatzbestellung).', 'wp-pass-captain' );
		}
		Forms::finish( $result, $message, $back, self::url( array( 'ticket_id' => $ticket_id ) ) );
	}

	public static function handle_settings() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 ); }
		Forms::verify( 'wpcap_exchange_settings' );
		$in = Forms::post();
		$fee = Forms::parse_money( $in['fee'] ?? '0' );
		if ( null === $fee ) { Forms::finish( Forms::error( 'wpcap_exchange_fee', __( 'Bitte eine gültige Gebühr angeben.', 'wp-pass-captain' ) ), '', self::url() ); }
		Policy::save_global( array(
			'enabled' => Forms::flag( $in, 'enabled' ),
			'portal_enabled' => Forms::flag( $in, 'portal_enabled' ),
			'portal_hours' => Forms::int( $in, 'portal_hours', 24 ),
			'portal_max_per_ticket' => Forms::int( $in, 'portal_max_per_ticket', 1 ),
			'refund_policy' => Forms::choice( $in, 'refund_policy', array( 'refund', 'none' ), 'refund' ),
			'fee_minor' => (int) $fee,
			'allow_series' => Forms::flag( $in, 'allow_series' ),
		) );
		Forms::finish( true, __( 'Umtausch-Einstellungen gespeichert.', 'wp-pass-captain' ), self::url() );
	}

	public static function handle_event_policy() {
		Forms::verify( 'wpcap_exchange_event_policy' );
		$in = Forms::post();
		$event_id = absint( $in['event_id'] ?? 0 );
		if ( ! \PassCaptain\Access::can_access_event( $event_id, 'wpcap_manage_events' ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 ); }
		$values = array();
		foreach ( array( 'enabled', 'portal_enabled', 'refund_policy' ) as $key ) {
			$values[ $key ] = '' === (string) ( $in[ $key ] ?? '' ) ? null : sanitize_key( (string) $in[ $key ] );
		}
		foreach ( array( 'portal_hours', 'portal_max_per_ticket' ) as $key ) {
			$values[ $key ] = '' === trim( (string) ( $in[ $key ] ?? '' ) ) ? null : absint( $in[ $key ] );
		}
		$values['fee_minor'] = '' === trim( (string) ( $in['fee'] ?? '' ) ) ? null : Forms::parse_money( $in['fee'] );
		Forms::finish( Policy::save_event( $event_id, $values ), __( 'Umtausch-Regeln des Events gespeichert.', 'wp-pass-captain' ), self::url() );
	}

	/** Section on the order detail page: exchanges of the order and links per valid ticket. */
	public static function order_section( $order, $tickets = array() ) {
		if ( ! is_object( $order ) ) { return; }
		$exchanges = Service::for_order( (int) $order->order_id );
		$valid = 'paid' === $order->payment_status ? array_filter( (array) $tickets, static function ( $t ) { return 'valid' === $t->status && ! (int) $t->refunded; } ) : array();
		if ( ! $exchanges && ! $valid ) { return; }
		echo '<h2>' . esc_html__( 'Umtausch', 'wp-pass-captain' ) . '</h2>';
		if ( $valid && current_user_can( self::CAP ) ) {
			echo '<p>';
			foreach ( $valid as $ticket ) {
				echo '<a class="button" href="' . esc_url( self::url( array( 'ticket_id' => (int) $ticket->ticket_id ) ) ) . '">' . esc_html( sprintf( /* translators: %s: ticket */ __( 'Umtauschen: %s', 'wp-pass-captain' ), $ticket->event_name . ' — ' . $ticket->ticket_name . ' (' . substr( (string) $ticket->public_id, 0, 8 ) . ')' ) ) . '</a> ';
			}
			echo '</p>';
		}
		if ( $exchanges ) { self::render_table( $exchanges ); }
	}

	private static function render_table( array $rows ) {
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Datum', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Von → Nach', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Kanal', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Differenz', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Gebühr', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Aufpreis / Erstattung', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Status', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Bestellungen', 'wp-pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$money = static function ( $minor ) use ( $row ) { return Forms::money( (int) $minor, $row['currency'] ); };
			$settle = (int) $row['due_minor'] > 0 ? sprintf( /* translators: %s: amount */ __( 'Aufpreis %s', 'wp-pass-captain' ), $money( $row['due_minor'] ) ) : ( (int) $row['refund_minor'] > 0 ? sprintf( /* translators: %s: amount */ __( 'Erstattung %s', 'wp-pass-captain' ), $money( $row['refund_minor'] ) ) : ( (int) $row['waived'] ? __( 'Kulanz', 'wp-pass-captain' ) : '—' ) );
			if ( 'woocommerce' === (string) ( $row['refund_status'] ?? '' ) ) {
				$settle .= ' – ' . __( 'in WooCommerce erstatten', 'wp-pass-captain' );
			} elseif ( 'error' === (string) ( $row['refund_status'] ?? '' ) ) {
				$settle .= ' – ' . __( 'Erstattung fehlgeschlagen, bitte manuell erstatten', 'wp-pass-captain' );
			}
			$orders = '<a href="' . esc_url( OrdersPage::order_url( (int) $row['order_id'] ) ) . '">#' . (int) $row['order_id'] . '</a>';
			if ( (int) $row['supplementary_order_id'] ) { $orders .= ' + <a href="' . esc_url( OrdersPage::order_url( (int) $row['supplementary_order_id'] ) ) . '">#' . (int) $row['supplementary_order_id'] . '</a>'; }
			echo '<tr><td>' . esc_html( Forms::datetime( $row['created_at'], 'UTC', false ) ) . '</td><td>' . esc_html( self::type_label( (int) $row['from_type_id'] ) . ' → ' . self::type_label( (int) $row['to_type_id'] ) ) . '</td><td>' . esc_html( $row['channel'] ) . '</td><td>' . esc_html( $money( $row['difference_minor'] ) ) . '</td><td>' . esc_html( $money( $row['fee_minor'] ) ) . '</td><td>' . esc_html( $settle ) . '</td><td>' . esc_html( self::status_label( $row['status'] ) ) . '</td><td>' . $orders . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $orders is built from escaped parts.
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
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 ); }
		echo '<div class="wrap"><h1>' . esc_html__( 'Ticketumtausch', 'wp-pass-captain' ) . '</h1>';
		Forms::render_notices();
		$ticket_id = absint( Forms::query_arg( 'ticket_id', 0 ) );
		if ( $ticket_id ) { self::render_exchange_form( $ticket_id ); }
		if ( current_user_can( 'manage_options' ) ) { self::render_settings(); }
		self::render_event_policy();
		global $wpdb;
		$scope = Queries::event_ids_for( array( self::CAP ) );
		$rows = $wpdb->get_results( 'SELECT * FROM ' . Service::table() . ' ORDER BY exchange_id DESC LIMIT 50', ARRAY_A );
		if ( null !== $scope ) {
			$rows = array_values( array_filter( $rows, static function ( $row ) { return Queries::can_access_order( (int) $row['order_id'], self::CAP ); } ) );
		}
		echo '<h2>' . esc_html__( 'Letzte Umtausche', 'wp-pass-captain' ) . '</h2>';
		if ( $rows ) { self::render_table( $rows ); } else { echo '<p>' . esc_html__( 'Noch keine Umtausche.', 'wp-pass-captain' ) . '</p>'; }
		echo '</div>';
	}

	private static function render_exchange_form( $ticket_id ) {
		$ticket = Service::ticket( $ticket_id );
		if ( ! $ticket || ! Queries::can_access_order( (int) $ticket->order_id, self::CAP ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Ticket nicht gefunden oder keine Berechtigung.', 'wp-pass-captain' ) . '</p></div>';
			return;
		}
		echo '<h2>' . esc_html( sprintf( /* translators: 1: event, 2: ticket type, 3: date */ __( 'Ticket umtauschen: %1$s — %2$s (%3$s)', 'wp-pass-captain' ), $ticket->event_name, $ticket->ticket_name, Forms::datetime( $ticket->starts_at, $ticket->timezone ) ) ) . '</h2>';
		$options = Service::options( $ticket_id, 'admin' );
		if ( 'valid' !== $ticket->status || ! $options ) {
			echo '<p>' . esc_html__( 'Für dieses Ticket ist derzeit kein Umtausch möglich (Status, Zahlung oder keine verfügbaren Ziele).', 'wp-pass-captain' ) . '</p>';
			return;
		}
		$value = Service::ticket_value( $ticket );
		echo '<p>' . esc_html( sprintf( /* translators: %s: amount */ __( 'Wert des bisherigen Tickets: %s', 'wp-pass-captain' ), Forms::money( $value, $ticket->currency ) ) ) . '</p>';
		Forms::open_form( 'wpcap_exchange_ticket', 'wpcap_exchange_ticket_' . $ticket_id );
		Forms::hidden( 'ticket_id', $ticket_id );
		Forms::hidden( 'idempotency_key', wp_generate_uuid4() );
		echo '<table class="form-table"><tr><th><label for="wpcap-exchange-target">' . esc_html__( 'Ziel', 'wp-pass-captain' ) . '</label></th><td><select name="ticket_type_id" id="wpcap-exchange-target" required>';
		$seated = array();
		foreach ( $options as $option ) {
			$left = null === $option['available'] ? __( 'unbegrenzt', 'wp-pass-captain' ) : (string) $option['available'];
			$label = $option['event_name'] . ' — ' . $option['ticket_name'] . ' — ' . Forms::datetime( $option['starts_at'], $option['timezone'] ) . ' — ' . Forms::money( $option['price_minor'], $option['currency'] ) . ' (' . sprintf( /* translators: %s: difference */ __( 'Differenz %s', 'wp-pass-captain' ), Forms::money( $option['difference_minor'], $option['currency'] ) ) . ', ' . sprintf( /* translators: %s: remaining */ __( 'frei: %s', 'wp-pass-captain' ), $left ) . ')';
			echo '<option value="' . esc_attr( (string) $option['ticket_type_id'] ) . '"' . disabled( null !== $option['available'] && $option['available'] < 1, true, false ) . '>' . esc_html( $label ) . '</option>';
			if ( $option['seated'] ) { $seated[] = $option; }
		}
		echo '</select></td></tr>';
		if ( $seated ) {
			echo '<tr><th><label for="wpcap-exchange-seat">' . esc_html__( 'Sitzplatz', 'wp-pass-captain' ) . '</label></th><td><select name="seat_id" id="wpcap-exchange-seat"><option value="0">' . esc_html__( 'Besten freien Platz automatisch wählen', 'wp-pass-captain' ) . '</option>';
			foreach ( $seated as $option ) {
				echo '<optgroup label="' . esc_attr( $option['ticket_name'] . ' — ' . Forms::datetime( $option['starts_at'], $option['timezone'] ) ) . '">';
				foreach ( Service::free_seats( (int) $option['occurrence_id'], (int) $option['ticket_type_id'] ) as $seat_id => $label ) {
					echo '<option value="' . esc_attr( (string) $seat_id ) . '">' . esc_html( $label ) . '</option>';
				}
				echo '</optgroup>';
			}
			echo '</select><p class="description">' . esc_html__( 'Der Platz muss zur Ticketart des Ziels passen.', 'wp-pass-captain' ) . '</p></td></tr>';
		}
		if ( current_user_can( Service::COMP_CAP ) ) {
			echo '<tr><th>' . esc_html__( 'Kulanz', 'wp-pass-captain' ) . '</th><td><label><input type="checkbox" name="waive" value="1"> ' . esc_html__( 'Ohne Aufpreis und Gebühr umtauschen', 'wp-pass-captain' ) . '</label></td></tr>';
		}
		echo '<tr><th><label for="wpcap-exchange-refund">' . esc_html__( 'Günstigeres Ziel', 'wp-pass-captain' ) . '</label></th><td>';
		Forms::select( 'refund_policy', array( '' => __( 'Regel des Events', 'wp-pass-captain' ), 'refund' => __( 'Differenz erstatten', 'wp-pass-captain' ), 'none' => __( 'Keine Erstattung', 'wp-pass-captain' ) ), '', 'wpcap-exchange-refund' );
		echo '</td></tr><tr><th><label for="wpcap-exchange-note">' . esc_html__( 'Notiz', 'wp-pass-captain' ) . '</label></th><td><input type="text" name="note" id="wpcap-exchange-note" class="regular-text" maxlength="255"></td></tr></table>';
		echo \PassCaptain\Billing::fields_html( 'wpcap-exchange-billing' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Billing::fields_html().
		echo '<p><button class="button button-primary" type="submit" onclick="return window.confirm(\'' . esc_js( __( 'Ticket jetzt umtauschen? Der bisherige Ticketcode wird ungültig.', 'wp-pass-captain' ) ) . '\')">' . esc_html__( 'Umtauschen', 'wp-pass-captain' ) . '</button></p></form>';
	}

	private static function render_settings() {
		$policy = Policy::global_policy();
		echo '<h2>' . esc_html__( 'Einstellungen (global)', 'wp-pass-captain' ) . '</h2>';
		Forms::open_form( 'wpcap_exchange_settings' );
		echo '<table class="form-table">';
		echo '<tr><th>' . esc_html__( 'Umtausch durch Mitarbeitende', 'wp-pass-captain' ) . '</th><td><label><input type="checkbox" name="enabled" value="1"' . checked( 1, $policy['enabled'], false ) . '> ' . esc_html__( 'Erlaubt (Admin, Abendkasse)', 'wp-pass-captain' ) . '</label></td></tr>';
		echo '<tr><th>' . esc_html__( 'Selbstbedienung', 'wp-pass-captain' ) . '</th><td><label><input type="checkbox" name="portal_enabled" value="1"' . checked( 1, $policy['portal_enabled'], false ) . '> ' . esc_html__( 'Käufer:innen dürfen im Bestellportal umtauschen (nur innerhalb desselben Events)', 'wp-pass-captain' ) . '</label></td></tr>';
		echo '<tr><th><label for="wpcap-ex-hours">' . esc_html__( 'Frist (Stunden vor Beginn)', 'wp-pass-captain' ) . '</label></th><td><input type="number" min="0" id="wpcap-ex-hours" name="portal_hours" value="' . esc_attr( (string) $policy['portal_hours'] ) . '"></td></tr>';
		echo '<tr><th><label for="wpcap-ex-max">' . esc_html__( 'Höchstens Umtausche pro Ticket (Selbstbedienung)', 'wp-pass-captain' ) . '</label></th><td><input type="number" min="0" id="wpcap-ex-max" name="portal_max_per_ticket" value="' . esc_attr( (string) $policy['portal_max_per_ticket'] ) . '"></td></tr>';
		echo '<tr><th><label for="wpcap-ex-refund">' . esc_html__( 'Günstigeres Ziel', 'wp-pass-captain' ) . '</label></th><td>';
		Forms::select( 'refund_policy', array( 'refund' => __( 'Differenz erstatten (Freigaberegeln gelten)', 'wp-pass-captain' ), 'none' => __( 'Keine Erstattung', 'wp-pass-captain' ) ), $policy['refund_policy'], 'wpcap-ex-refund' );
		echo '</td></tr>';
		echo '<tr><th><label for="wpcap-ex-fee">' . esc_html__( 'Umtauschgebühr (brutto)', 'wp-pass-captain' ) . '</label></th><td><input type="text" id="wpcap-ex-fee" name="fee" value="' . esc_attr( Forms::money_input( $policy['fee_minor'] ) ) . '"><p class="description">' . esc_html__( 'Wird als eigene Position der Zusatzbestellung berechnet; bei günstigerem Ziel mit der Erstattung verrechnet.', 'wp-pass-captain' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Serien', 'wp-pass-captain' ) . '</th><td><label><input type="checkbox" name="allow_series" value="1"' . checked( 1, $policy['allow_series'], false ) . '> ' . esc_html__( 'Mitarbeitende dürfen in andere Events derselben Serie umtauschen', 'wp-pass-captain' ) . '</label></td></tr>';
		echo '</table><p><button class="button button-primary" type="submit">' . esc_html__( 'Speichern', 'wp-pass-captain' ) . '</button></p></form>';
	}

	private static function render_event_policy() {
		$events = Queries::event_options( Queries::event_ids_for( array( 'wpcap_manage_events' ) ) );
		if ( ! $events ) { return; }
		$inherit = array( '' => __( 'wie global', 'wp-pass-captain' ) );
		echo '<h2>' . esc_html__( 'Regeln pro Event', 'wp-pass-captain' ) . '</h2><p class="description">' . esc_html__( 'Leere Felder übernehmen die globale Einstellung.', 'wp-pass-captain' ) . '</p>';
		Forms::open_form( 'wpcap_exchange_event_policy' );
		echo '<table class="form-table"><tr><th>' . esc_html__( 'Event', 'wp-pass-captain' ) . '</th><td>';
		Forms::select( 'event_id', $events, '' );
		echo '</td></tr><tr><th>' . esc_html__( 'Umtausch', 'wp-pass-captain' ) . '</th><td>';
		Forms::select( 'enabled', $inherit + array( '1' => __( 'erlaubt', 'wp-pass-captain' ), '0' => __( 'nicht erlaubt', 'wp-pass-captain' ) ), '' );
		echo '</td></tr><tr><th>' . esc_html__( 'Selbstbedienung', 'wp-pass-captain' ) . '</th><td>';
		Forms::select( 'portal_enabled', $inherit + array( '1' => __( 'erlaubt', 'wp-pass-captain' ), '0' => __( 'nicht erlaubt', 'wp-pass-captain' ) ), '' );
		echo '</td></tr><tr><th>' . esc_html__( 'Frist (Stunden)', 'wp-pass-captain' ) . '</th><td><input type="number" min="0" name="portal_hours"></td></tr>';
		echo '<tr><th>' . esc_html__( 'Höchstens pro Ticket', 'wp-pass-captain' ) . '</th><td><input type="number" min="0" name="portal_max_per_ticket"></td></tr><tr><th>' . esc_html__( 'Günstigeres Ziel', 'wp-pass-captain' ) . '</th><td>';
		Forms::select( 'refund_policy', $inherit + array( 'refund' => __( 'Differenz erstatten', 'wp-pass-captain' ), 'none' => __( 'Keine Erstattung', 'wp-pass-captain' ) ), '' );
		echo '</td></tr><tr><th>' . esc_html__( 'Gebühr (brutto)', 'wp-pass-captain' ) . '</th><td><input type="text" name="fee"></td></tr></table>';
		echo '<p><button class="button" type="submit">' . esc_html__( 'Event-Regeln speichern', 'wp-pass-captain' ) . '</button></p></form>';
	}
}

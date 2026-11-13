<?php
/**
 * Admin screens of the box office around shifts: open (register, counted float), handover
 * acceptance, shift screen (cashiers, cash in/out, blind count, close with handover), shift list
 * with Z reports (HTML/CSV, corrections, chain check) and register management; plus their
 * admin-post handlers. The sale screen itself is Page.
 */
namespace PassCaptain\BoxOffice;

use PassCaptain\Admin\Forms;
use PassCaptain\Reports\Scope;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ShiftPage {
	public static function url( $tab, array $args = array() ) {
		return Forms::page_url( Page::SLUG, array_merge( array( 'tab' => $tab ), $args ) );
	}

	/* ------------------------------------------------------------------ helpers */

	/** Users who may work at the till (id => label) for selects; empty when WordPress cannot list them. */
	public static function user_options() {
		if ( ! function_exists( 'get_users' ) ) { return array(); }
		$out = array();
		foreach ( (array) get_users( array( 'capability' => Service::CAP, 'fields' => array( 'ID', 'display_name' ), 'number' => 300, 'orderby' => 'display_name' ) ) as $u ) {
			$out[ (int) $u->ID ] = $u->display_name . ' (#' . (int) $u->ID . ')';
		}
		return $out;
	}

	private static function user_field( $name, $label, $required = false ) {
		$users = self::user_options();
		echo '<label>' . esc_html( $label ) . ' ';
		if ( $users ) {
			Forms::select( $name, array( 0 => __( '– niemand –', 'pass-captain' ) ) + $users, 0 );
		} else {
			echo '<input type="number" min="1" name="' . esc_attr( $name ) . '" class="small-text"' . ( $required ? ' required' : '' ) . '>';
		}
		echo '</label>';
	}

	/** Denomination table (inputs denoms_<prefix>[value]) with a live sum into the amount field. */
	public static function denomination_fields( $prefix, $currency, $amount_name, $amount_label, $required = true ) {
		$id = 'wpcap-denoms-' . sanitize_key( $prefix );
		echo '<p><label>' . esc_html( $amount_label ) . ' <input type="text" inputmode="decimal" name="' . esc_attr( $amount_name ) . '" id="' . esc_attr( $id ) . '-total" size="10"' . ( $required ? ' required' : '' ) . '></label> ' . esc_html( $currency ) . '</p>';
		echo '<details class="wpcap-denoms"><summary>' . esc_html__( 'Stückelung zählen (Scheine und Münzen)', 'pass-captain' ) . '</summary><table class="widefat striped wpcap-denoms-table" style="max-width:24rem" data-total="' . esc_attr( $id ) . '-total"><thead><tr><th>' . esc_html__( 'Wert', 'pass-captain' ) . '</th><th>' . esc_html__( 'Anzahl', 'pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( Cash::denominations( $currency ) as $value ) {
			echo '<tr><td>' . esc_html( Cash::label( $value ) ) . '</td><td><input type="number" min="0" step="1" class="small-text" data-value="' . (int) $value . '" name="' . esc_attr( 'denoms_' . $prefix . '[' . (int) $value . ']' ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: denomination */ __( 'Anzahl %s', 'pass-captain' ), Cash::label( $value ) ) ) . '"></td></tr>';
		}
		echo '</tbody></table><p class="description">' . esc_html__( 'Wenn die Stückelung ausgefüllt ist, wird der Betrag daraus berechnet.', 'pass-captain' ) . '</p></details>';
	}

	private static function enqueue() {
		if ( function_exists( 'wp_enqueue_script' ) && function_exists( 'plugins_url' ) ) {
			wp_enqueue_script( 'wpcap-pos-shift', plugins_url( 'assets/boxoffice/shift.js', WPCAP_FILE ), array(), WPCAP_VERSION, true );
		}
	}

	/** Amount and denominations of a posted count ([amount|null, denominations]). */
	public static function count_input( array $in, $prefix, $amount_key, $currency = 'EUR' ) {
		$raw = trim( (string) ( $in[ $amount_key ] ?? '' ) );
		$amount = '' === $raw ? null : Forms::parse_money( $raw, Forms::currency_decimals( $currency ) );
		$denoms = is_array( $in[ 'denoms_' . $prefix ] ?? null ) ? $in[ 'denoms_' . $prefix ] : array();
		$denoms = array_filter( $denoms, static function ( $q ) { return is_scalar( $q ) && '' !== trim( (string) $q ); } );
		return array( '' !== $raw && null === $amount ? false : $amount, $denoms );
	}

	public static function status_label( $status ) {
		$labels = array( 'open' => __( 'offen', 'pass-captain' ), 'counting' => __( 'wird gezählt', 'pass-captain' ), 'closed' => __( 'abgeschlossen', 'pass-captain' ) );
		return $labels[ $status ] ?? Forms::status_label( $status );
	}

	private static function money( $minor, $currency ) {
		return null === $minor ? '–' : Scope::money( (int) $minor, $currency );
	}

	/* ------------------------------------------------------------------ open / handover */

	/** Shown on the sale tab while the user has no shift: handovers to accept and the open form. */
	public static function render_open_forms() {
		self::enqueue();
		foreach ( Shifts::handovers_for( get_current_user_id() ) as $h ) {
			$register = Registers::get( $h->register_id );
			$cur = $register ? $register->currency : 'EUR';
			/* translators: 1: user, 2: register */
			echo '<h2>' . esc_html( sprintf( __( 'Übergabe von %1$s an Kasse „%2$s“ annehmen', 'pass-captain' ), Service::user_name( $h->from_user ), $register ? $register->name : '#' . $h->register_id ) ) . '</h2>';
			echo '<p class="description">' . esc_html( $register && (int) $register->blind_count ? __( 'Bitte das übergebene Bargeld zählen. Der übergebene Betrag wird erst nach der Zählung angezeigt.', 'pass-captain' ) : sprintf( /* translators: %s: amount */ __( 'Übergebener Betrag laut Übergabe: %s. Bitte nachzählen.', 'pass-captain' ), Scope::money( $h->amount_minor, $cur ) ) ) . '</p>';
			Forms::open_form( 'wpcap_pos_handover', 'wpcap_pos_handover_' . (int) $h->handover_id );
			Forms::hidden( 'handover_id', (int) $h->handover_id );
			self::denomination_fields( 'handover' . (int) $h->handover_id, $cur, 'cash', __( 'Gezählter Betrag', 'pass-captain' ) );
			echo '<p><label>' . esc_html__( 'Grund bei Abweichung', 'pass-captain' ) . ' <input type="text" name="reason" size="40" maxlength="255"></label></p>';
			echo '<p><button class="button button-primary">' . esc_html__( 'Übergabe bestätigen und Schicht öffnen', 'pass-captain' ) . '</button></p></form>';
		}
		if ( ! Registers::all() && Registers::can_manage() ) { Registers::default_id(); }
		$available = Registers::available();
		echo '<h2>' . esc_html__( 'Schicht öffnen', 'pass-captain' ) . '</h2>';
		if ( ! $available ) {
			echo '<p>' . esc_html__( 'Keine freie Kasse: Alle aktiven Kassen haben eine offene Schicht oder warten auf eine Übergabe.', 'pass-captain' ) . ( Registers::can_manage() ? ' <a href="' . esc_url( self::url( 'registers' ) ) . '">' . esc_html__( 'Kassen verwalten', 'pass-captain' ) . '</a>' : '' ) . '</p>';
			return;
		}
		$options = array();
		foreach ( $available as $r ) { $options[ (int) $r->register_id ] = $r->name . ( '' !== (string) $r->location ? ' – ' . $r->location : '' ) . ' (' . $r->currency . ( (int) $r->blind_count ? ', ' . __( 'Blindzählung', 'pass-captain' ) : '' ) . ')'; }
		Forms::open_form( 'wpcap_pos_open' );
		echo '<p><label>' . esc_html__( 'Kasse', 'pass-captain' ) . ' ';
		Forms::select( 'register_id', $options, (int) array_key_first( $options ) );
		echo '</label></p>';
		self::denomination_fields( 'open', $available[0]->currency, 'float', __( 'Wechselgeld (Anfangsbestand, gezählt)', 'pass-captain' ) );
		echo '<p><label>' . esc_html__( 'Notiz', 'pass-captain' ) . ' <input type="text" name="note" size="40" maxlength="255"></label></p>';
		echo '<p><button class="button button-primary">' . esc_html__( 'Schicht öffnen', 'pass-captain' ) . '</button></p></form>';
	}

	public static function handle_open() {
		Forms::verify( 'wpcap_pos_open' );
		$in = Forms::post();
		$register = Registers::get( Forms::int( $in, 'register_id' ) );
		list( $amount, $denoms ) = self::count_input( $in, 'open', 'float', $register ? $register->currency : 'EUR' );
		if ( false === $amount ) { Forms::finish( Forms::error( 'wpcap_pos_amount', __( 'Ungültiger Betrag.', 'pass-captain' ) ), '', Page::back() ); }
		if ( null === $amount && ! $denoms ) { $amount = 0; }
		$result = $register ? Shifts::open( $register->register_id, array( 'float_minor' => $amount, 'denominations' => $denoms, 'note' => Forms::text( $in, 'note', 255 ) ) ) : Forms::error( 'wpcap_pos_register', __( 'Kasse nicht gefunden.', 'pass-captain' ), 404 );
		Forms::finish( $result, __( 'Schicht geöffnet.', 'pass-captain' ), Page::back() );
	}

	public static function handle_handover() {
		$in = Forms::post();
		$id = Forms::int( $in, 'handover_id' );
		Forms::verify( 'wpcap_pos_handover_' . $id );
		$handover = Shifts::handover( $id );
		$register = $handover ? Registers::get( $handover->register_id ) : null;
		list( $amount, $denoms ) = self::count_input( $in, 'handover' . $id, 'cash', $register ? $register->currency : 'EUR' );
		if ( false === $amount ) { Forms::finish( Forms::error( 'wpcap_pos_amount', __( 'Ungültiger Betrag.', 'pass-captain' ) ), '', Page::back() ); }
		$result = Shifts::accept_handover( $id, array( 'cash_minor' => $amount, 'denominations' => $denoms, 'reason' => Forms::text( $in, 'reason', 255 ) ) );
		Forms::finish( $result, __( 'Übergabe bestätigt, Schicht geöffnet.', 'pass-captain' ), Page::back() );
	}

	public static function handle_handover_cancel() {
		$in = Forms::post();
		$id = Forms::int( $in, 'handover_id' );
		Forms::verify( 'wpcap_pos_handover_cancel_' . $id );
		Forms::finish( Shifts::cancel_handover( $id, Forms::text( $in, 'reason', 255 ) ), __( 'Übergabe storniert; die Kasse kann neu geöffnet werden.', 'pass-captain' ), self::url( 'registers' ) );
	}

	/* ------------------------------------------------------------------ shift screen */

	public static function render_shift() {
		$shift_id = absint( Forms::query_arg( 'shift_id', '0' ) );
		$shift = $shift_id ? Shifts::get( $shift_id ) : Shifts::current_for_user( get_current_user_id() );
		if ( ! $shift ) {
			echo '<p>' . esc_html__( 'Du hast keine offene Schicht.', 'pass-captain' ) . ' <a href="' . esc_url( Page::back() ) . '">' . esc_html__( 'Schicht öffnen', 'pass-captain' ) . '</a></p>';
			return;
		}
		if ( ! Shifts::can_view( $shift ) ) { echo '<p>' . esc_html__( 'Keine Berechtigung.', 'pass-captain' ) . '</p>'; return; }
		if ( 'closed' === $shift->status ) {
			$z = ZReport::for_shift( $shift->session_id );
			echo '<p>' . esc_html__( 'Diese Schicht ist abgeschlossen.', 'pass-captain' ) . ( $z ? ' <a target="_blank" href="' . esc_url( self::z_url( $z->z_id ) ) . '">' . esc_html( sprintf( /* translators: %d: Z number */ __( 'Z-Bericht Nr. %d', 'pass-captain' ), (int) $z->z_number ) ) . '</a>' : '' ) . '</p>';
			return;
		}
		self::enqueue();
		$view = Shifts::view( $shift->session_id );
		$cur = $shift->currency;
		$register = $view['register'];
		$tz = Scope::site_timezone();
		/* translators: 1: register, 2: shift id, 3: time */
		echo '<h2>' . esc_html( sprintf( __( 'Kasse „%1$s“ · Schicht #%2$d (seit %3$s)', 'pass-captain' ), $register ? $register->name : '–', $shift->session_id, Scope::local( $shift->opened_at, $tz ) ) ) . '</h2>';
		echo '<p>' . esc_html__( 'Kassierer:', 'pass-captain' ) . ' ' . esc_html( implode( ', ', array_map( array( Service::class, 'user_name' ), $view['cashiers'] ) ) ) . ' · ' . esc_html( sprintf( /* translators: %s: amount */ __( 'Anfangsbestand %s', 'pass-captain' ), Scope::money( $shift->opening_float_minor, $cur ) ) ) . '</p>';
		$can = Shifts::can_operate( $shift );
		if ( $can && 'open' === $shift->status ) {
			Forms::open_form( 'wpcap_pos_cashier', 'wpcap_pos_cashier_' . (int) $shift->session_id, 'style="margin-bottom:1em"' );
			Forms::hidden( 'shift_id', (int) $shift->session_id );
			self::user_field( 'user_id', __( 'Kassierer hinzufügen', 'pass-captain' ), true );
			echo ' <button class="button">' . esc_html__( 'Hinzufügen', 'pass-captain' ) . '</button></form>';
		}

		// Movements (cash in/out, payouts, corrections).
		echo '<h3>' . esc_html__( 'Einlagen, Entnahmen und Auszahlungen', 'pass-captain' ) . '</h3>';
		$kinds = ZReport::kind_labels();
		if ( $view['movements'] ) {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Zeit', 'pass-captain' ) . '</th><th>' . esc_html__( 'Art', 'pass-captain' ) . '</th><th>' . esc_html__( 'Zahlart', 'pass-captain' ) . '</th><th>' . esc_html__( 'Betrag', 'pass-captain' ) . '</th><th>' . esc_html__( 'Grund', 'pass-captain' ) . '</th><th>' . esc_html__( 'Beleg', 'pass-captain' ) . '</th></tr></thead><tbody>';
			foreach ( $view['movements'] as $mv ) {
				$receipt = $mv->receipt_number ? '<a target="_blank" href="' . esc_url( add_query_arg( array( 'action' => 'wpcap_pos_payout_receipt', 'movement_id' => (int) $mv->movement_id ), admin_url( 'admin-post.php' ) ) ) . '">' . esc_html( $mv->receipt_number ) . '</a>' : '';
				echo '<tr><td>' . esc_html( Scope::local( $mv->created_at, $tz, 'H:i' ) ) . '</td><td>' . esc_html( $kinds[ $mv->kind ] ?? $mv->kind ) . '</td><td>' . esc_html( Service::method_label( $mv->method ) ) . '</td><td>' . esc_html( Scope::money( $mv->amount_minor, $mv->currency ) ) . '</td><td>' . esc_html( $mv->reason . ( '' !== (string) $mv->override_reason ? ' [' . $mv->override_reason . ']' : '' ) ) . '</td><td>' . $receipt . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $receipt escaped above.
			}
			echo '</tbody></table>';
		}
		if ( $can && 'open' === $shift->status ) {
			Forms::open_form( 'wpcap_pos_movement', 'wpcap_pos_movement_' . (int) $shift->session_id );
			Forms::hidden( 'shift_id', (int) $shift->session_id );
			echo '<p><label>' . esc_html__( 'Art', 'pass-captain' ) . ' ';
			Forms::select( 'kind', array( 'cash_in' => __( 'Einlage (Geld in die Kasse)', 'pass-captain' ), 'cash_out' => __( 'Entnahme (Geld aus der Kasse)', 'pass-captain' ) ), 'cash_out' );
			echo '</label> <label>' . esc_html__( 'Betrag', 'pass-captain' ) . ' <input type="text" inputmode="decimal" name="amount" size="8" required></label> <label>' . esc_html__( 'Grund', 'pass-captain' ) . ' <input type="text" name="reason" size="30" maxlength="255" required></label> <button class="button">' . esc_html__( 'Buchen', 'pass-captain' ) . '</button></p></form>';
		}

		// Count and close.
		echo '<h3>' . esc_html__( 'Kassenabschluss', 'pass-captain' ) . '</h3>';
		$count = $view['count'];
		if ( ! $view['masked'] && (int) $shift->blind_count && 'closed' !== $shift->status ) {
			// Supervisors see the expected amounts although the register counts blind: say so.
			echo '<p class="description">' . esc_html__( 'Diese Kasse zählt blind. Du siehst den Soll-Bestand nur, weil du Kassenverwaltung oder Finanzrecht hast – Kassierkräfte ohne diese Rechte sehen ihn erst im Z-Bericht.', 'pass-captain' ) . '</p>';
		}
		if ( $view['masked'] ) {
			echo '<p class="description">' . esc_html__( 'Blindzählung: Soll-Bestand und Differenz stehen erst nach dem Schließen im Z-Bericht. Eine Nachzählung kann nur die Kassenverwaltung durchführen.', 'pass-captain' ) . '</p>';
			if ( $count && 'counting' === $shift->status ) {
				/* translators: %s: counted cash */
				echo '<p>' . esc_html( sprintf( __( 'Abgegebene Zählung: Bargeld %s.', 'pass-captain' ), Scope::money( $count->cash_minor, $cur ) ) ) . '</p>';
			}
		} else {
			$t = $view['totals'];
			echo '<p>' . esc_html( sprintf( /* translators: 1: cash, 2: card */ __( 'Soll-Bestand bar %1$s · Kartenumsatz laut Kasse %2$s', 'pass-captain' ), Scope::money( $t['cash']['expected'], $cur ), Scope::money( $t['card']['expected'], $cur ) ) ) . '</p>';
		}
		if ( $count && 'counting' === $shift->status && ! $view['masked'] ) {
			$cash_diff = (int) $count->cash_minor - (int) $count->expected_cash_minor;
			$card_diff = null === $count->card_minor ? null : (int) $count->card_minor - (int) $count->expected_card_minor;
			echo '<table class="widefat" style="max-width:40rem"><thead><tr><th></th><th>' . esc_html__( 'Gezählt', 'pass-captain' ) . '</th><th>' . esc_html__( 'Soll', 'pass-captain' ) . '</th><th>' . esc_html__( 'Differenz', 'pass-captain' ) . '</th></tr></thead><tbody>';
			echo '<tr><td>' . esc_html__( 'Bargeld', 'pass-captain' ) . '</td><td>' . esc_html( Scope::money( $count->cash_minor, $cur ) ) . '</td><td>' . esc_html( Scope::money( $count->expected_cash_minor, $cur ) ) . '</td><td>' . esc_html( Scope::money( $cash_diff, $cur ) ) . '</td></tr>';
			echo '<tr><td>' . esc_html__( 'Kartenterminal', 'pass-captain' ) . '</td><td>' . esc_html( self::money( $count->card_minor, $cur ) ) . '</td><td>' . esc_html( Scope::money( $count->expected_card_minor, $cur ) ) . '</td><td>' . esc_html( self::money( $card_diff, $cur ) ) . '</td></tr></tbody></table>';
		}
		if ( ! $can ) { return; }
		if ( ! ( $view['masked'] && $count ) ) { // Blind count: no recount by the cashiers.
			Forms::open_form( 'wpcap_pos_count', 'wpcap_pos_count_' . (int) $shift->session_id );
			Forms::hidden( 'shift_id', (int) $shift->session_id );
			echo '<h4>' . esc_html( $count ? __( 'Neu zählen', 'pass-captain' ) : __( 'Zählung abgeben', 'pass-captain' ) ) . '</h4>';
			self::denomination_fields( 'count', $cur, 'cash', __( 'Gezählter Bargeldbestand', 'pass-captain' ) );
			echo '<p><label>' . esc_html__( 'Summe laut Kartenterminal (Tagesabschluss)', 'pass-captain' ) . ' <input type="text" inputmode="decimal" name="card" size="10"></label> <span class="description">' . esc_html__( 'Leer lassen, wenn kein Terminal genutzt wurde.', 'pass-captain' ) . '</span></p>';
			echo '<p><button class="button">' . esc_html( $count ? __( 'Neu zählen', 'pass-captain' ) : __( 'Zählung abgeben', 'pass-captain' ) ) . '</button> <span class="description">' . esc_html__( 'Nach der Zählung sind keine Verkäufe mehr möglich.', 'pass-captain' ) . '</span></p></form>';
		}
		if ( ! $count || 'counting' !== $shift->status ) { return; }
		Forms::open_form( 'wpcap_pos_close', 'wpcap_pos_close_' . (int) $shift->session_id, 'onsubmit="return confirm(\'' . esc_js( __( 'Schicht jetzt abschließen? Der Z-Bericht kann danach nicht mehr geändert werden.', 'pass-captain' ) ) . '\')"' );
		Forms::hidden( 'shift_id', (int) $shift->session_id );
		echo '<h4>' . esc_html__( 'Schicht abschließen (Z-Bericht)', 'pass-captain' ) . '</h4>';
		echo '<p><label>' . esc_html__( 'Grund der Differenz (Pflicht bei Abweichung)', 'pass-captain' ) . ' <input type="text" name="reason" size="40" maxlength="255"></label></p>';
		echo '<fieldset><legend>' . esc_html__( 'Übergabe an die nächste Schicht (optional)', 'pass-captain' ) . '</legend><p>';
		self::user_field( 'handover_to', __( 'Übergabe an', 'pass-captain' ) );
		echo '</p>';
		self::denomination_fields( 'handover', $cur, 'handover_amount', __( 'Betrag, der in der Kasse bleibt (Rest wird abgeschöpft)', 'pass-captain' ), false );
		echo '</fieldset><p><button class="button button-primary">' . esc_html__( 'Schicht abschließen', 'pass-captain' ) . '</button></p></form>';
	}

	public static function handle_cashier() {
		$in = Forms::post();
		$shift_id = Forms::int( $in, 'shift_id' );
		Forms::verify( 'wpcap_pos_cashier_' . $shift_id );
		Forms::finish( Shifts::add_cashier( $shift_id, Forms::int( $in, 'user_id' ) ), __( 'Kassierer hinzugefügt.', 'pass-captain' ), self::url( 'shift' ) );
	}

	public static function handle_movement() {
		$in = Forms::post();
		$shift_id = Forms::int( $in, 'shift_id' );
		Forms::verify( 'wpcap_pos_movement_' . $shift_id );
		$amount = Forms::parse_money( $in['amount'] ?? '' );
		$result = null === $amount ? Forms::error( 'wpcap_pos_amount', __( 'Ungültiger Betrag.', 'pass-captain' ) ) : Shifts::cash_movement( $shift_id, Forms::choice( $in, 'kind', array( 'cash_in', 'cash_out' ), '' ), $amount, Forms::text( $in, 'reason', 255 ) );
		Forms::finish( $result, __( 'Buchung gespeichert.', 'pass-captain' ), self::url( 'shift' ) );
	}

	public static function handle_count() {
		$in = Forms::post();
		$shift_id = Forms::int( $in, 'shift_id' );
		Forms::verify( 'wpcap_pos_count_' . $shift_id );
		$shift = Shifts::get( $shift_id );
		$cur = $shift ? $shift->currency : 'EUR';
		list( $cash, $denoms ) = self::count_input( $in, 'count', 'cash', $cur );
		$card_raw = trim( (string) ( $in['card'] ?? '' ) );
		$card = '' === $card_raw ? null : Forms::parse_money( $card_raw, Forms::currency_decimals( $cur ) );
		if ( false === $cash || ( '' !== $card_raw && null === $card ) ) { Forms::finish( Forms::error( 'wpcap_pos_amount', __( 'Ungültiger Betrag.', 'pass-captain' ) ), '', self::url( 'shift' ) ); }
		$result = Shifts::submit_count( $shift_id, array( 'cash_minor' => $cash, 'denominations' => $denoms, 'card_minor' => $card ) );
		if ( is_wp_error( $result ) ) {
			$message = '';
		} elseif ( null === $result['expected_cash_minor'] ) {
			/* translators: %s: counted amount */
			$message = sprintf( __( 'Zählung gespeichert (Blindzählung). Gezählt %s. Soll und Differenz stehen nach dem Schließen im Z-Bericht.', 'pass-captain' ), Scope::money( $result['cash_minor'], $cur ) );
		} else {
			$message = sprintf( /* translators: 1: counted, 2: expected, 3: difference */ __( 'Zählung gespeichert. Gezählt %1$s, Soll %2$s, Differenz %3$s.', 'pass-captain' ), Scope::money( $result['cash_minor'], $cur ), Scope::money( $result['expected_cash_minor'], $cur ), Scope::money( $result['cash_difference_minor'], $cur ) );
		}
		Forms::finish( $result, $message, self::url( 'shift' ) );
	}

	public static function handle_close() {
		$in = Forms::post();
		$shift_id = Forms::int( $in, 'shift_id' );
		Forms::verify( 'wpcap_pos_close_' . $shift_id );
		$shift = Shifts::get( $shift_id );
		list( $amount, $denoms ) = self::count_input( $in, 'handover', 'handover_amount', $shift ? $shift->currency : 'EUR' );
		if ( false === $amount ) { Forms::finish( Forms::error( 'wpcap_pos_amount', __( 'Ungültiger Betrag.', 'pass-captain' ) ), '', self::url( 'shift' ) ); }
		$to = Forms::int( $in, 'handover_to' );
		$result = Shifts::close( $shift_id, array( 'reason' => Forms::text( $in, 'reason', 255 ), 'handover_to' => $to, 'handover_minor' => $to && null === $amount && ! $denoms ? 0 : $amount, 'handover_denominations' => $denoms ) );
		$z = is_wp_error( $result ) ? null : ZReport::for_shift( $shift_id );
		$message = $z ? sprintf( /* translators: %d: Z number */ __( 'Schicht abgeschlossen: Z-Bericht Nr. %d.', 'pass-captain' ), (int) $z->z_number ) : '';
		Forms::finish( $result, $message, self::url( 'shifts' ), self::url( 'shift' ) );
	}

	/* ------------------------------------------------------------------ shifts and Z reports */

	public static function z_url( $z_id, $csv = false ) {
		return add_query_arg( array( 'action' => $csv ? 'wpcap_pos_zreport_csv' : 'wpcap_pos_zreport', 'z_id' => absint( $z_id ) ), admin_url( 'admin-post.php' ) );
	}

	/** Whether the current user may see a Z report (cashiers of the shift, administrators, finance). */
	public static function can_view_z( $z ) {
		if ( ! $z ) { return false; }
		if ( Registers::can_manage() || current_user_can( Shifts::FINANCE_CAP ) ) { return true; }
		$shift = Shifts::get( $z->shift_id );
		return $shift && Shifts::is_cashier( $shift, get_current_user_id() );
	}

	public static function render_shifts() {
		$registers = Registers::all( true );
		$names = array();
		foreach ( $registers as $r ) { $names[ (int) $r->register_id ] = $r->name; }
		$filter = absint( Forms::query_arg( 'register_id', '0' ) );
		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( Page::SLUG ) . '"><input type="hidden" name="tab" value="shifts"><p><label>' . esc_html__( 'Kasse', 'pass-captain' ) . ' ';
		Forms::select( 'register_id', array( 0 => __( 'Alle', 'pass-captain' ) ) + $names, $filter, '', 'onchange="this.form.submit()"' );
		echo '</label></p></form>';
		$open = array();
		foreach ( $registers as $r ) {
			$s = Shifts::open_for_register( $r->register_id );
			if ( $s && ( ! $filter || $filter === (int) $r->register_id ) && Shifts::can_view( $s ) ) { $open[] = $s; }
		}
		if ( $open ) {
			echo '<h2>' . esc_html__( 'Offene Schichten', 'pass-captain' ) . '</h2><ul>';
			foreach ( $open as $s ) {
				echo '<li><a href="' . esc_url( self::url( 'shift', array( 'shift_id' => (int) $s->session_id ) ) ) . '">' . esc_html( sprintf( /* translators: 1: register, 2: shift, 3: cashier */ __( '%1$s · Schicht #%2$d · %3$s', 'pass-captain' ), $names[ (int) $s->register_id ] ?? '–', $s->session_id, Service::user_name( $s->user_id ) ) ) . '</a> (' . esc_html( self::status_label( $s->status ) ) . ')</li>';
			}
			echo '</ul>';
		}
		echo '<h2>' . esc_html__( 'Abgeschlossene Schichten (Z-Berichte)', 'pass-captain' ) . '</h2>';
		$rows = array_filter( ZReport::list_reports( $filter, 100 ), static function ( $z ) { return self::can_view_z( $z ); } );
		if ( ! $rows ) { echo '<p>' . esc_html__( 'Noch keine Z-Berichte.', 'pass-captain' ) . '</p>'; }
		else {
			$tz = Scope::site_timezone();
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Kasse', 'pass-captain' ) . '</th><th>' . esc_html__( 'Z-Nr.', 'pass-captain' ) . '</th><th>' . esc_html__( 'Schicht', 'pass-captain' ) . '</th><th>' . esc_html__( 'Geöffnet', 'pass-captain' ) . '</th><th>' . esc_html__( 'Geschlossen', 'pass-captain' ) . '</th><th>' . esc_html__( 'Netto', 'pass-captain' ) . '</th><th>' . esc_html__( 'Grand Total', 'pass-captain' ) . '</th><th>' . esc_html__( 'Differenz bar / Karte', 'pass-captain' ) . '</th><th>' . esc_html__( 'Bericht', 'pass-captain' ) . '</th></tr></thead><tbody>';
			foreach ( $rows as $z ) {
				echo '<tr><td>' . esc_html( $names[ (int) $z->register_id ] ?? '#' . $z->register_id ) . '</td><td>' . (int) $z->z_number . ( 'migration' === $z->source ? ' <small>(' . esc_html__( 'übernommen', 'pass-captain' ) . ')</small>' : '' ) . '</td><td>' . (int) $z->shift_id . '</td><td>' . esc_html( Scope::local( $z->opened_at, $tz ) ) . '</td><td>' . esc_html( Scope::local( $z->closed_at, $tz ) ) . ' · ' . esc_html( Service::user_name( $z->closed_by ) ) . '</td><td>' . esc_html( Scope::money( $z->net_total_minor, $z->currency ) ) . '</td><td>' . esc_html( Scope::money( $z->grand_total_minor, $z->currency ) ) . '</td><td>' . esc_html( self::money( $z->cash_difference_minor, $z->currency ) . ' / ' . self::money( $z->card_difference_minor, $z->currency ) ) . '</td>';
				echo '<td><a target="_blank" href="' . esc_url( self::z_url( $z->z_id ) ) . '">HTML</a> · <a href="' . esc_url( self::z_url( $z->z_id, true ) ) . '">CSV</a></td></tr>';
			}
			echo '</tbody></table>';
		}
		if ( ! Registers::can_manage() ) { return; }
		echo '<h2>' . esc_html__( 'Prüfung der Z-Kette', 'pass-captain' ) . '</h2><ul>';
		foreach ( $registers as $r ) {
			$problems = ZReport::verify( $r->register_id );
			echo '<li>' . esc_html( $r->name ) . ': ' . esc_html( $problems ? implode( ' ', $problems ) : __( 'lückenlos und unverändert', 'pass-captain' ) ) . '</li>';
		}
		echo '</ul><h2>' . esc_html__( 'Korrektur zu einem Z-Bericht', 'pass-captain' ) . '</h2><p class="description">' . esc_html__( 'Z-Berichte werden nie geändert. Eine Korrektur wird als neue Buchung in die offene Schicht derselben Kasse gebucht und erscheint im nächsten Z-Bericht.', 'pass-captain' ) . '</p>';
		$z_options = array();
		foreach ( ZReport::list_reports( 0, 100 ) as $z ) { $z_options[ (int) $z->z_id ] = ( $names[ (int) $z->register_id ] ?? '#' . $z->register_id ) . ' – Z ' . (int) $z->z_number; }
		if ( ! $z_options ) { return; }
		Forms::open_form( 'wpcap_pos_correction' );
		echo '<p><label>' . esc_html__( 'Z-Bericht', 'pass-captain' ) . ' ';
		Forms::select( 'z_id', $z_options, 0 );
		echo '</label> <label>' . esc_html__( 'Richtung', 'pass-captain' ) . ' ';
		Forms::select( 'sign', array( '+' => __( 'mehr Bargeld (+)', 'pass-captain' ), '-' => __( 'weniger Bargeld (−)', 'pass-captain' ) ), '+' );
		echo '</label> <label>' . esc_html__( 'Betrag', 'pass-captain' ) . ' <input type="text" inputmode="decimal" name="amount" size="8" required></label> <label>' . esc_html__( 'Grund', 'pass-captain' ) . ' <input type="text" name="reason" size="30" maxlength="200" required></label> <button class="button">' . esc_html__( 'Korrektur buchen', 'pass-captain' ) . '</button></p></form>';
	}

	public static function handle_correction() {
		Forms::verify( 'wpcap_pos_correction' );
		$in = Forms::post();
		$amount = Forms::parse_money( $in['amount'] ?? '' );
		$result = null === $amount ? Forms::error( 'wpcap_pos_amount', __( 'Ungültiger Betrag.', 'pass-captain' ) ) : Shifts::correction( Forms::int( $in, 'z_id' ), '-' === ( $in['sign'] ?? '+' ) ? -$amount : $amount, Forms::text( $in, 'reason', 200 ) );
		Forms::finish( $result, __( 'Korrektur gebucht.', 'pass-captain' ), self::url( 'shifts' ) );
	}

	public static function handle_zreport() {
		$z = ZReport::get( absint( $_GET['z_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view with permission check.
		if ( ! $z ) { wp_die( esc_html__( 'Z-Bericht nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! self::can_view_z( $z ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		\PassCaptain\Privacy\AccessLog::export( 'zreport_html', 'pos_register', (int) $z->register_id, array( 'z_number' => (int) $z->z_number ) );
		nocache_headers();
		echo ZReport::html( $z ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in html().
		exit;
	}

	public static function handle_zreport_csv() {
		$z = ZReport::get( absint( $_GET['z_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only download with permission check.
		if ( ! $z ) { wp_die( esc_html__( 'Z-Bericht nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! self::can_view_z( $z ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		\PassCaptain\Privacy\AccessLog::export( 'zreport_csv', 'pos_register', (int) $z->register_id, array( 'z_number' => (int) $z->z_number ) );
		Scope::send_download( ZReport::csv( $z ), 'z-report-' . (int) $z->register_id . '-' . (int) $z->z_number . '.csv', 'text/csv; charset=utf-8' );
	}

	public static function handle_payout_receipt() {
		$mv = Shifts::movement( absint( $_GET['movement_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view with permission check.
		$shift = $mv ? Shifts::get( $mv->shift_id ) : null;
		if ( ! $mv || ! $mv->receipt_number || ! Shifts::can_view( $shift ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		nocache_headers();
		echo ZReport::payout_receipt_html( $mv ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in payout_receipt_html().
		exit;
	}

	/* ------------------------------------------------------------------ registers */

	public static function render_registers() {
		if ( ! Registers::can_manage() ) { echo '<p>' . esc_html__( 'Keine Berechtigung.', 'pass-captain' ) . '</p>'; return; }
		$edit = Registers::get( absint( Forms::query_arg( 'register_id', '0' ) ) );
		$registers = Registers::all( true );
		if ( $registers ) {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Name', 'pass-captain' ) . '</th><th>' . esc_html__( 'Standort', 'pass-captain' ) . '</th><th>' . esc_html__( 'Event', 'pass-captain' ) . '</th><th>' . esc_html__( 'Währung', 'pass-captain' ) . '</th><th>' . esc_html__( 'Blindzählung', 'pass-captain' ) . '</th><th>' . esc_html__( 'Status', 'pass-captain' ) . '</th><th>' . esc_html__( 'Schicht', 'pass-captain' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $registers as $r ) {
				$shift = Shifts::open_for_register( $r->register_id );
				$handover = Registers::pending_handover( $r->register_id );
				echo '<tr><td>' . esc_html( $r->name ) . '</td><td>' . esc_html( $r->location ) . '</td><td>' . ( (int) $r->event_id ? (int) $r->event_id : '–' ) . '</td><td>' . esc_html( $r->currency ) . '</td><td>' . esc_html( (int) $r->blind_count ? __( 'ja', 'pass-captain' ) : __( 'nein', 'pass-captain' ) ) . '</td><td>' . esc_html( Forms::status_label( $r->status ) ) . '</td><td>';
				if ( $shift ) {
					echo '<a href="' . esc_url( self::url( 'shift', array( 'shift_id' => (int) $shift->session_id ) ) ) . '">#' . (int) $shift->session_id . '</a> · ' . esc_html( Service::user_name( $shift->user_id ) );
				} elseif ( $handover ) {
					echo esc_html( sprintf( /* translators: %s: user */ __( 'Übergabe an %s ausstehend', 'pass-captain' ), Service::user_name( $handover->to_user ) ) ) . ' ';
					Forms::open_form( 'wpcap_pos_handover_cancel', 'wpcap_pos_handover_cancel_' . (int) $handover->handover_id, 'style="display:inline"' );
					Forms::hidden( 'handover_id', (int) $handover->handover_id );
					echo '<input type="text" name="reason" size="12" required placeholder="' . esc_attr__( 'Grund', 'pass-captain' ) . '"> <button class="button-link-delete">' . esc_html__( 'Übergabe stornieren', 'pass-captain' ) . '</button></form>';
				} else {
					echo '–';
				}
				echo '</td><td><a href="' . esc_url( self::url( 'registers', array( 'register_id' => (int) $r->register_id ) ) ) . '">' . esc_html__( 'Bearbeiten', 'pass-captain' ) . '</a></td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '<h2>' . esc_html( $edit ? __( 'Kasse bearbeiten', 'pass-captain' ) : __( 'Neue Kasse', 'pass-captain' ) ) . '</h2>';
		Forms::open_form( 'wpcap_pos_register' );
		Forms::hidden( 'register_id', $edit ? (int) $edit->register_id : 0 );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="wpcap-reg-name">' . esc_html__( 'Name', 'pass-captain' ) . '</label></th><td><input type="text" id="wpcap-reg-name" name="name" required maxlength="100" value="' . esc_attr( $edit ? $edit->name : '' ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="wpcap-reg-location">' . esc_html__( 'Standort', 'pass-captain' ) . '</label></th><td><input type="text" id="wpcap-reg-location" name="location" maxlength="190" value="' . esc_attr( $edit ? $edit->location : '' ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="wpcap-reg-event">' . esc_html__( 'Nur für Event (optional)', 'pass-captain' ) . '</label></th><td>';
		\PassCaptain\Admin\Forms::select( 'event_id', array( 0 => __( 'Alle Events', 'pass-captain' ) ) + \PassCaptain\Admin\Queries::event_options( \PassCaptain\Admin\Queries::event_ids_for( array( Service::CAP ) ) ), $edit ? (int) $edit->event_id : 0, 'wpcap-reg-event' );
		echo '</td></tr>';
		echo '<tr><th scope="row"><label for="wpcap-reg-currency">' . esc_html__( 'Währung', 'pass-captain' ) . '</label></th><td><input type="text" id="wpcap-reg-currency" name="currency" size="4" maxlength="3" value="' . esc_attr( $edit ? $edit->currency : 'EUR' ) . '"></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Blindzählung', 'pass-captain' ) . '</th><td><label><input type="checkbox" name="blind_count" value="1"' . ( ! $edit || (int) $edit->blind_count ? ' checked' : '' ) . '> ' . esc_html__( 'Soll-Bestand erst nach der Zählung anzeigen', 'pass-captain' ) . '</label><p class="description">' . esc_html__( 'Gilt für Kassierkräfte. Personen mit Kassenverwaltung oder Finanzrecht sehen den Soll-Bestand immer (sie können auch nachzählen); für eine echte Blindzählung sollte daher eine Person ohne diese Rechte zählen.', 'pass-captain' ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Status', 'pass-captain' ) . '</th><td>';
		Forms::select( 'status', array( 'active' => __( 'Aktiv', 'pass-captain' ), 'inactive' => __( 'Deaktiviert', 'pass-captain' ) ), $edit ? $edit->status : 'active' );
		echo '</td></tr></tbody></table><p><button class="button button-primary">' . esc_html__( 'Speichern', 'pass-captain' ) . '</button></p></form>';
	}

	public static function handle_register() {
		Forms::verify( 'wpcap_pos_register' );
		$in = Forms::post();
		$result = Registers::save( array(
			'name' => Forms::text( $in, 'name', 100 ),
			'location' => Forms::text( $in, 'location', 190 ),
			'event_id' => Forms::int( $in, 'event_id' ),
			'currency' => Forms::text( $in, 'currency', 3 ),
			'blind_count' => Forms::flag( $in, 'blind_count' ),
			'status' => Forms::choice( $in, 'status', array( 'active', 'inactive' ), 'active' ),
		), Forms::int( $in, 'register_id' ) );
		Forms::finish( $result, __( 'Kasse gespeichert.', 'pass-captain' ), self::url( 'registers' ) );
	}
}

<?php
/** Admin screen "Kasse" and its admin-post handlers. */
namespace PassCaptain\BoxOffice;

use PassCaptain\Admin\Forms;
use PassCaptain\Audit;
use PassCaptain\Admin\Queries;
use PassCaptain\Orders;
use PassCaptain\Printing\Templates;
use PassCaptain\Reports\Scope;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Page {
	const SLUG = 'wpcap-boxoffice';

	/** Whether the current user may see a sale (receipt): cashier of the sale, order managers in scope or finance. */
	public static function can_view_sale( $sale ) {
		if ( ! $sale ) { return false; }
		if ( current_user_can( 'manage_options' ) || current_user_can( 'wpcap_view_finance' ) ) { return true; }
		if ( (int) $sale->cashier_id === get_current_user_id() && current_user_can( Service::CAP ) ) { return true; }
		return Scope::can_order( $sale->order_id, Service::CAP );
	}

	/**
	 * Ticket PDF for a sale. New codes are issued for the valid tickets (only hashes are stored), so
	 * codes sent by e-mail before become invalid.
	 *
	 * @return array{pdf:string,filename:string}|\WP_Error
	 */
	public static function tickets_pdf( $sale_id ) {
		$sale = Service::sale( $sale_id );
		if ( ! $sale || ! Scope::can_order( $sale->order_id, Service::CAP ) ) { return Forms::forbidden(); }
		if ( 'completed' !== $sale->status ) { return Forms::error( 'wpcap_pos_voided', __( 'Für stornierte Verkäufe werden keine Tickets gedruckt.', 'wp-pass-captain' ), 409 ); }
		$tickets = array_values( array_filter( Orders::tickets_for_order( $sale->order_id ), static function ( $t ) { return 'valid' === $t->status; } ) );
		$tokens = Templates::rotate_codes( array_map( static function ( $t ) { return (int) $t->ticket_id; }, $tickets ), 'pos_print' );
		global $wpdb;
		$items = array();
		foreach ( $tickets as $t ) {
			$t->attendee_name = (string) $wpdb->get_var( $wpdb->prepare( "SELECT attendee_name FROM {$wpdb->prefix}wpcap_ticket_assignments WHERE ticket_id=%d", $t->ticket_id ) );
			$items[] = Templates::values( $t, $tokens[ (int) $t->ticket_id ] ?? '' );
		}
		$event_id = Scope::occurrence_event( (int) $sale->occurrence_id );
		return array( 'pdf' => Templates::render( 'ticket', $items, Templates::body_for_event( $event_id, 'ticket' ) ), 'filename' => 'tickets-' . $sale->receipt_number . '.pdf' );
	}

	/** Tabs of the box office screen (registers only for administrators). */
	public static function tabs() {
		$tabs = array(
			'sale' => __( 'Verkauf', 'wp-pass-captain' ),
			'shift' => __( 'Schicht', 'wp-pass-captain' ),
			'shifts' => __( 'Schichten & Z-Berichte', 'wp-pass-captain' ),
		);
		if ( Registers::can_manage() ) { $tabs['registers'] = __( 'Kassen', 'wp-pass-captain' ); }
		return $tabs;
	}

	public static function render() {
		if ( ! current_user_can( Service::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 ); }
		$tabs = self::tabs();
		$tab = Forms::query_arg( 'tab', 'sale' );
		$tab = isset( $tabs[ $tab ] ) ? $tab : 'sale';
		echo '<div class="wrap wpcap-admin wpcap-pos"><h1>' . esc_html__( 'Kasse', 'wp-pass-captain' ) . '</h1>';
		self::render_fiscal_notice();
		Forms::render_notices();
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Kassenbereiche', 'wp-pass-captain' ) . '">';
		foreach ( $tabs as $key => $label ) {
			echo '<a class="nav-tab' . ( $key === $tab ? ' nav-tab-active" aria-current="page' : '' ) . '" href="' . esc_url( ShiftPage::url( $key ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		if ( 'shift' === $tab ) { ShiftPage::render_shift(); echo '</div>'; return; }
		if ( 'shifts' === $tab ) { ShiftPage::render_shifts(); echo '</div>'; return; }
		if ( 'registers' === $tab ) { ShiftPage::render_registers(); echo '</div>'; return; }
		$session = Service::open_session_for( get_current_user_id() );
		$last = absint( $_GET['sale'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$last_sale = $last ? Service::sale( $last ) : null;
		if ( $last_sale && Service::is_booked( $last_sale ) && self::can_view_sale( $last_sale ) ) {
			$receipt = add_query_arg( array( 'action' => 'wpcap_pos_receipt', 'sale_id' => $last ), admin_url( 'admin-post.php' ) );
			echo '<div class="notice notice-success inline"><p>' . esc_html( sprintf( __( 'Verkauf gebucht: Beleg %1$s über %2$s.', 'wp-pass-captain' ), $last_sale->receipt_number, Scope::money( $last_sale->total_minor, $last_sale->currency ) ) ) . ' <a class="button" target="_blank" href="' . esc_url( $receipt ) . '">' . esc_html__( 'Beleg drucken', 'wp-pass-captain' ) . '</a> ';
			Forms::open_form( 'wpcap_pos_tickets', 'wpcap_pos_tickets_' . $last, 'target="_blank" style="display:inline"' );
			Forms::hidden( 'sale_id', $last );
			echo '<button class="button">' . esc_html__( 'Tickets drucken (PDF)', 'wp-pass-captain' ) . '</button></form></p></div>';
		}
		if ( ! $session ) {
			$active = Shifts::current_for_user( get_current_user_id() );
			if ( $active ) {
				echo '<p>' . esc_html__( 'Ihre Schicht wird gerade gezählt; Verkäufe sind erst in der nächsten Schicht möglich.', 'wp-pass-captain' ) . ' <a class="button" href="' . esc_url( ShiftPage::url( 'shift' ) ) . '">' . esc_html__( 'Zum Kassenabschluss', 'wp-pass-captain' ) . '</a></p>';
			} else {
				ShiftPage::render_open_forms();
			}
			self::render_lookup();
			echo '</div>';
			return;
		}
		$register = Registers::get( $session->register_id );
		$occurrences = \PassCaptain\Printing\Page::occurrence_options( Service::CAP, false );
		if ( $register && (int) $register->event_id ) {
			$occurrences = array_filter( $occurrences, static function ( $label, $id ) use ( $register ) { return Scope::occurrence_event( $id ) === (int) $register->event_id; }, ARRAY_FILTER_USE_BOTH );
		}
		$occurrence_id = absint( $_GET['occurrence_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $occurrences[ $occurrence_id ] ) ) { $occurrence_id = (int) array_key_first( $occurrences ); }
		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><p><label>' . esc_html__( 'Termin', 'wp-pass-captain' ) . ' ';
		Forms::select( 'occurrence_id', $occurrences, $occurrence_id, '', 'onchange="this.form.submit()"' );
		echo '</label></p></form>';
		if ( $occurrence_id ) {
			$types = Service::ticket_types( $occurrence_id );
			Forms::open_form( 'wpcap_pos_sell', null, 'id="wpcap-pos-form"' );
			Forms::hidden( 'occurrence_id', $occurrence_id );
			Forms::hidden( 'form_key', wp_generate_uuid4() );
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Ticketart', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Preis', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Verfügbar', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Anzahl', 'wp-pass-captain' ) . '</th></tr></thead><tbody>';
			$any_seated = false;
			foreach ( $types as $t ) {
				$max = null === $t['available'] ? $t['max_per_order'] : min( $t['max_per_order'], $t['available'] );
				$any_seated = $any_seated || $t['seated'];
				$required = array_values( array_filter( $t['fields'], static function ( $f ) { return $f['required']; } ) );
				$hints = array();
				if ( $t['seated'] ) { $hints[] = __( 'Saalplan: Sitzplätze werden automatisch zugewiesen (beste freie Plätze).', 'wp-pass-captain' ); }
				/* translators: %s: list of field labels */
				if ( $required ) { $hints[] = sprintf( __( 'Pflichtangaben je Ticket: %s', 'wp-pass-captain' ), implode( ', ', array_map( static function ( $f ) { return $f['label']; }, $required ) ) ); }
				echo '<tr data-type="' . (int) $t['ticket_type_id'] . '" data-price="' . (int) $t['price_minor'] . '" data-name="' . esc_attr( $t['name'] ) . '" data-fields="' . esc_attr( wp_json_encode( $t['fields'] ) ) . '"><td>' . esc_html( $t['name'] ) . ( $hints ? '<br><small class="description">' . esc_html( implode( ' · ', $hints ) ) . '</small>' : '' ) . '</td><td>' . esc_html( Scope::money( $t['price_minor'], $t['currency'] ) ) . '</td><td class="wpcap-pos-avail">' . esc_html( null === $t['available'] ? '∞' : (string) $t['available'] ) . '</td>';
				echo '<td><input type="number" class="small-text wpcap-pos-qty" min="0" max="' . (int) max( 0, $max ) . '" name="qty[' . (int) $t['ticket_type_id'] . ']" value="0" aria-label="' . esc_attr( sprintf( /* translators: %s: ticket type */ __( 'Anzahl %s', 'wp-pass-captain' ), $t['name'] ) ) . '"></td></tr>';
			}
			echo '</tbody></table>';
			if ( $any_seated ) {
				echo '<p class="description">' . esc_html__( 'An der Kasse kann kein Platz im Saalplan gewählt werden: Für Ticketarten mit Saalplan werden die besten freien Plätze automatisch vergeben und auf den Tickets angegeben. Für Wunschplätze bitte die Online-Buchung nutzen.', 'wp-pass-captain' ) . '</p>';
			}
			echo '<div id="wpcap-pos-attendees" class="wpcap-pos-attendees"></div>';
			echo '<noscript><p class="description">' . esc_html__( 'Für Teilnehmerangaben wird JavaScript benötigt.', 'wp-pass-captain' ) . '</p></noscript>';
			echo '<p><strong>' . esc_html__( 'Summe:', 'wp-pass-captain' ) . ' <span id="wpcap-pos-total">0,00</span> ' . esc_html( $session->currency ) . '</strong></p><p>';
			$sale_methods = Service::sale_methods();
			$first = (string) array_key_first( $sale_methods );
			foreach ( $sale_methods as $key => $def ) {
				if ( 'free' === $key && ! Service::can_give_free() ) { continue; }
				echo '<label style="margin-right:1em"><input type="radio" name="method" value="' . esc_attr( $key ) . '"' . ( $first === $key ? ' checked' : '' ) . '> ' . esc_html( $def['label'] ) . '</label>';
			}
			if ( Service::fiscal_block() ) {
				echo '<br><span class="description">' . esc_html__( 'Bar und Karte sind gesperrt: In Deutschland und Österreich brauchen diese Verkäufe eine zertifizierte Kassenlösung (TSE/RKSV), die Pass Captain nicht hat.', 'wp-pass-captain' ) . '</span>';
			}
			echo '</p><p><label>' . esc_html__( 'Gegeben (bar)', 'wp-pass-captain' ) . ' <input type="text" inputmode="decimal" name="tendered" size="8"></label> <span id="wpcap-pos-change"></span> ';
			echo '<label>' . esc_html__( 'Terminal-Referenz', 'wp-pass-captain' ) . ' <input type="text" name="reference" size="14" maxlength="100"></label> ';
			echo '<label>' . esc_html__( 'E-Mail Käufer (optional, Pflicht bei Rechnung)', 'wp-pass-captain' ) . ' <input type="email" name="email" size="28"></label></p>';
			self::render_billing_fields();
			echo '<p><button class="button button-primary button-hero">' . esc_html__( 'Verkauf buchen', 'wp-pass-captain' ) . '</button></p></form>';
			$availability_url = add_query_arg( array( 'action' => 'wpcap_pos_availability', 'occurrence_id' => $occurrence_id, '_wpnonce' => wp_create_nonce( 'wpcap_pos' ) ), admin_url( 'admin-post.php' ) );
			echo '<script>window.wpcapPos=' . wp_json_encode( array(
				'availabilityUrl' => $availability_url,
				'changeLabel' => __( 'Rückgeld:', 'wp-pass-captain' ),
				/* translators: 1: running ticket number, 2: ticket type */
				'attendee' => __( 'Ticket %1$d · %2$s', 'wp-pass-captain' ),
				'name' => __( 'Name', 'wp-pass-captain' ),
				'email' => __( 'E-Mail (optional)', 'wp-pass-captain' ),
				'heading' => __( 'Teilnehmerangaben', 'wp-pass-captain' ),
			) ) . ';</script>';
			wp_enqueue_script( 'wpcap-pos', plugins_url( 'assets/boxoffice/pos.js', WPCAP_FILE ), array(), WPCAP_VERSION, true );
		}
		self::render_session( $session );
		self::render_lookup();
		echo '</div>';
	}

	/**
	 * Receipt lookup: finds a booked sale by receipt number (also of closed shifts) so that it can
	 * be stornoed (in full or per ticket) into the current open shift.
	 */
	private static function render_lookup() {
		$number = Forms::query_arg( 'receipt', '' );
		echo '<h3>' . esc_html__( 'Beleg suchen (Storno früherer Schichten)', 'wp-pass-captain' ) . '</h3><form method="get"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><p><label>' . esc_html__( 'Belegnummer', 'wp-pass-captain' ) . ' <input type="text" name="receipt" size="22" value="' . esc_attr( $number ) . '"></label> <button class="button">' . esc_html__( 'Suchen', 'wp-pass-captain' ) . '</button></p></form>';
		if ( '' === $number ) { return; }
		global $wpdb;
		$sale = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_boxoffice_sales WHERE receipt_number=%s", $number ) );
		if ( ! $sale || ! Service::is_booked( $sale ) || ! self::can_view_sale( $sale ) ) {
			echo '<p>' . esc_html__( 'Kein Beleg mit dieser Nummer gefunden.', 'wp-pass-captain' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><tbody>';
		self::render_sale_row( $sale );
		echo '</tbody></table>';
	}

	private static function render_session( $session ) {
		global $wpdb;
		$view = Shifts::view( $session->session_id );
		$totals = $view['totals'];
		$cur = $session->currency;
		$register = $view['register'];
		/* translators: 1: register, 2: shift id, 3: time */
		echo '<h2>' . esc_html( sprintf( __( 'Kasse „%1$s“ · Schicht #%2$d (seit %3$s)', 'wp-pass-captain' ), $register ? $register->name : '–', $session->session_id, Scope::local( $session->opened_at, Scope::site_timezone() ) ) ) . '</h2>';
		if ( $view['masked'] ) {
			echo '<p>' . esc_html( sprintf( /* translators: 1: sales, 2: stornos */ __( 'Verkäufe %1$d · Stornos %2$d. Blindzählung: Beträge und Soll-Bestand werden erst nach der Zählung angezeigt.', 'wp-pass-captain' ), $totals['counts']['sales'], $totals['counts']['stornos'] + $totals['counts']['refunds'] ) ) . '</p>';
		} else {
			echo '<table class="widefat" style="max-width:40rem"><thead><tr><th>' . esc_html__( 'Zahlart', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Verkäufe', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Stornos/Erstattungen', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Netto-Umsatz', 'wp-pass-captain' ) . '</th></tr></thead><tbody>';
			foreach ( $totals['methods'] as $m => $t ) {
				echo '<tr><td>' . esc_html( Service::method_label( $m ) ) . '</td><td>' . (int) $t['sales_count'] . '</td><td>' . (int) $t['refund_count'] . '</td><td>' . esc_html( Scope::money( $t['net'], $cur ) ) . '</td></tr>';
			}
			echo '</tbody></table><p>' . esc_html( sprintf( __( 'Anfangsbestand %1$s · erwarteter Kassenbestand (bar) %2$s', 'wp-pass-captain' ), Scope::money( $session->opening_float_minor, $cur ), Scope::money( $totals['expected_cash'], $cur ) ) ) . '</p>';
		}

		$sales = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_boxoffice_sales WHERE session_id=%d AND status <> 'failed' ORDER BY sale_id DESC LIMIT 50", $session->session_id ) );
		if ( $sales ) {
			echo '<h3>' . esc_html__( 'Verkäufe dieser Schicht', 'wp-pass-captain' ) . '</h3><table class="widefat striped"><tbody>';
			foreach ( $sales as $s ) { self::render_sale_row( $s ); }
			echo '</tbody></table>';
		}
		echo '<p><a class="button" href="' . esc_url( ShiftPage::url( 'shift' ) ) . '">' . esc_html__( 'Einlage/Entnahme, Zählung und Kassenabschluss', 'wp-pass-captain' ) . '</a></p>';
		if ( current_user_can( 'manage_options' ) ) {
			echo '<h3>' . esc_html__( 'Belegeinstellungen', 'wp-pass-captain' ) . '</h3>';
			Forms::open_form( 'wpcap_pos_settings' );
			echo '<p><label>' . esc_html__( 'Präfix Belegnummer', 'wp-pass-captain' ) . ' <input type="text" name="boxoffice_receipt_prefix" size="6" value="' . esc_attr( (string) Settings::get( 'boxoffice_receipt_prefix' ) ) . '"></label> <label>' . esc_html__( 'Fußzeile', 'wp-pass-captain' ) . ' <input type="text" name="boxoffice_receipt_footer" size="50" value="' . esc_attr( (string) Settings::get( 'boxoffice_receipt_footer' ) ) . '"></label> <label>' . esc_html__( 'Bediener auf Kundenbelegen', 'wp-pass-captain' ) . ' <select name="boxoffice_receipt_cashier">' . implode( '', array_map( static function ( $value, $label ) { return '<option value="' . esc_attr( $value ) . '"' . selected( (string) Settings::get( 'boxoffice_receipt_cashier' ), $value, false ) . '>' . esc_html( $label ) . '</option>'; }, array( 'id', 'initials', 'name' ), array( __( 'Bedienernummer', 'wp-pass-captain' ), __( 'Initialen', 'wp-pass-captain' ), __( 'Vollständiger Name', 'wp-pass-captain' ) ) ) ) . '</select></label> </p><p><label><input type="checkbox" name="boxoffice_cash_confirmed" value="1"' . checked( (bool) Settings::get( 'boxoffice_cash_confirmed' ), true, false ) . '> ' . esc_html__( 'Bar- und Kartenverkäufe werden zusätzlich in einer eigenen zertifizierten Kasse (TSE/RKSV) erfasst – Bar und Karte hier freigeben', 'wp-pass-captain' ) . '</label></p><p><button class="button">' . esc_html__( 'Speichern', 'wp-pass-captain' ) . '</button></p></form>';
		}
	}

	/** One sale with receipt links and actions (book, reject storno, storno in full or per ticket). */
	private static function render_sale_row( $s ) {
		global $wpdb;
		$url = add_query_arg( array( 'action' => 'wpcap_pos_receipt', 'sale_id' => (int) $s->sale_id ), admin_url( 'admin-post.php' ) );
		$booked = Service::is_booked( $s );
		echo '<tr><td>' . esc_html( $s->receipt_number ?: __( '(nicht gebucht)', 'wp-pass-captain' ) ) . '</td><td>' . esc_html( Scope::local( $s->created_at, Scope::site_timezone(), 'd.m. H:i' ) ) . '</td><td>' . esc_html( Service::method_label( $s->payment_method ) ) . '</td><td>' . esc_html( $booked ? Scope::money( $s->total_minor, $s->currency ) : '–' ) . '</td><td>' . esc_html( Forms::status_label( $s->status ) ) . '</td><td>' . ( $booked ? '<a target="_blank" href="' . esc_url( $url ) . '">' . esc_html__( 'Beleg', 'wp-pass-captain' ) . '</a>' : '' );
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT movement_id,receipt_number FROM {$wpdb->prefix}wpcap_boxoffice_movements WHERE sale_id=%d AND receipt_number IS NOT NULL ORDER BY movement_id", (int) $s->sale_id ) ) as $mv ) {
			echo ' · <a target="_blank" href="' . esc_url( add_query_arg( array( 'action' => 'wpcap_pos_payout_receipt', 'movement_id' => (int) $mv->movement_id ), admin_url( 'admin-post.php' ) ) ) . '">' . esc_html( $mv->receipt_number ) . '</a>';
		}
		if ( 'pending' === $s->status ) {
			echo ' ';
			Forms::open_form( 'wpcap_pos_finalize', 'wpcap_pos_finalize_' . (int) $s->sale_id, 'style="display:inline"' );
			Forms::hidden( 'sale_id', (int) $s->sale_id );
			echo '<button class="button button-small">' . esc_html__( 'Nachbuchen', 'wp-pass-captain' ) . '</button></form>';
		} elseif ( 'storno_pending' === $s->status && current_user_can( \PassCaptain\Refunds::APPROVE_CAP ) ) {
			echo ' ';
			Forms::open_form( 'wpcap_pos_storno_reject', 'wpcap_pos_storno_reject_' . (int) $s->sale_id, 'style="display:inline"' );
			Forms::hidden( 'sale_id', (int) $s->sale_id );
			echo '<button class="button button-small">' . esc_html__( 'Storno ablehnen', 'wp-pass-captain' ) . '</button></form>';
		} elseif ( 'voided' === $s->status && '' !== (string) $s->storno_receipt_number ) {
			echo ' · <a target="_blank" href="' . esc_url( add_query_arg( 'storno', 1, $url ) ) . '">' . esc_html__( 'Stornobeleg', 'wp-pass-captain' ) . '</a>';
		} elseif ( 'completed' === $s->status ) {
			$tickets = $wpdb->get_results( $wpdb->prepare( "SELECT t.ticket_id,t.status,tt.name FROM {$wpdb->prefix}wpcap_tickets t LEFT JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id WHERE t.order_id=%d AND t.refunded=0 ORDER BY t.ticket_id", (int) $s->order_id ) );
			$any_checked_in = (bool) array_filter( $tickets, static function ( $t ) { return 'checked_in' === $t->status; } );
			echo ' ';
			Forms::open_form( 'wpcap_pos_storno', 'wpcap_pos_storno_' . (int) $s->sale_id, 'style="display:inline" onsubmit="return confirm(\'' . esc_js( __( 'Wirklich stornieren?', 'wp-pass-captain' ) ) . '\')"' );
			Forms::hidden( 'sale_id', (int) $s->sale_id );
			echo '<input type="text" name="reason" size="12" placeholder="' . esc_attr__( 'Grund', 'wp-pass-captain' ) . '" aria-label="' . esc_attr__( 'Grund', 'wp-pass-captain' ) . '"> <button class="button-link-delete">' . esc_html__( 'Stornieren', 'wp-pass-captain' ) . '</button>';
			if ( count( $tickets ) > 1 || $any_checked_in ) {
				echo '<details><summary>' . esc_html__( 'Nur einzelne Tickets stornieren', 'wp-pass-captain' ) . '</summary>';
				foreach ( $tickets as $t ) {
					echo '<label style="display:block"><input type="checkbox" name="ticket_ids[]" value="' . (int) $t->ticket_id . '"> #' . (int) $t->ticket_id . ' ' . esc_html( (string) $t->name ) . ( 'checked_in' === $t->status ? ' (' . esc_html__( 'eingecheckt', 'wp-pass-captain' ) . ')' : '' ) . '</label>';
				}
				echo '</details>';
			}
			if ( $any_checked_in && Service::can_override_checkin() ) {
				echo '<label style="display:block">' . esc_html__( 'Ausnahme für eingecheckte Tickets, Grund:', 'wp-pass-captain' ) . ' <input type="text" name="override_reason" size="20" maxlength="200"></label>';
			}
			echo '</form>';
		}
		echo '</td></tr>';
	}

	const FISCAL_NOTICE_META = 'wpcap_pos_fiscal_notice_dismissed';

	/** Where the fiscal-law notice links to (filter `wpcap_boxoffice_fiscal_docs_url`). */
	public static function fiscal_docs_url() {
		return (string) apply_filters( 'wpcap_boxoffice_fiscal_docs_url', 'https://github.com/hastla007/wp-passmaster/blob/main/docs/OPERATIONS.md#kasse-keine-registrierkasse' );
	}

	/**
	 * Prominent notice: the box office is not a certified cash register (RKSV / KassenSichV). It can
	 * be dismissed per user (?wpcap_dismiss_fiscal_notice=1 with nonce; stored as user meta).
	 */
	public static function render_fiscal_notice() {
		$user_id = get_current_user_id();
		if ( isset( $_GET['wpcap_dismiss_fiscal_notice'] ) && $user_id && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), self::FISCAL_NOTICE_META ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified here.
			update_user_meta( $user_id, self::FISCAL_NOTICE_META, 1 );
		}
		if ( $user_id && get_user_meta( $user_id, self::FISCAL_NOTICE_META, true ) ) { return; }
		$dismiss = wp_nonce_url( add_query_arg( 'wpcap_dismiss_fiscal_notice', '1', self::back() ), self::FISCAL_NOTICE_META );
		echo '<div class="notice notice-warning wpcap-fiscal-notice" role="note" style="border-left-width:6px;padding:12px 16px"><p style="font-size:1.05em"><strong>' . esc_html__( 'Wichtiger Hinweis:', 'wp-pass-captain' ) . '</strong> '
			. esc_html__( 'Die Kasse ist keine Registrierkasse im Sinne von RKSV (AT) bzw. KassenSichV/TSE (DE). Für Bar- und Kartenzahlungen vor Ort ist eine zertifizierte Signatureinheit nötig.', 'wp-pass-captain' ) . '</p><p>'
			. '<a href="' . esc_url( self::fiscal_docs_url() ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Mehr dazu in der Dokumentation', 'wp-pass-captain' ) . '<span class="screen-reader-text"> ' . esc_html__( '(öffnet in neuem Tab)', 'wp-pass-captain' ) . '</span></a>'
			. ' · <a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Hinweis für mich ausblenden', 'wp-pass-captain' ) . '</a></p></div>';
	}

	/* ------------------------------------------------------------------ handlers */

	public static function back( array $args = array() ) {
		return Forms::page_url( self::SLUG, $args );
	}

	/** Optional "Rechnung auf Firma" fields (collapsed); empty fields mean no billing data. */
	private static function render_billing_fields() {
		echo \PassCaptain\Billing::fields_html( 'wpcap-pos-billing' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Billing::fields_html().
	}

	/** Maps the POS form to Service::sell() input. */
	public static function sale_input( array $in ) {
		$tendered = '' !== trim( (string) ( $in['tendered'] ?? '' ) ) ? Forms::parse_money( $in['tendered'] ) : null;
		$quantities = array();
		foreach ( (array) ( $in['qty'] ?? array() ) as $type_id => $qty ) {
			if ( is_scalar( $qty ) && absint( $qty ) ) { $quantities[ absint( $type_id ) ] = absint( $qty ); }
		}
		$names = array();
		foreach ( (array) ( $in['names'] ?? array() ) as $type_id => $text ) {
			if ( is_scalar( $text ) ) { $names[ absint( $type_id ) ] = preg_split( '/\r\n|\r|\n/', (string) $text ); }
		}
		// attendees[type_id][index][name|email|answers][field_key], rendered per ticket by pos.js.
		$attendees = array();
		foreach ( is_array( $in['attendees'] ?? null ) ? $in['attendees'] : array() as $type_id => $entries ) {
			if ( ! isset( $quantities[ absint( $type_id ) ] ) || ! is_array( $entries ) ) { continue; }
			ksort( $entries, SORT_NUMERIC );
			foreach ( array_slice( array_values( $entries ), 0, $quantities[ absint( $type_id ) ] ) as $entry ) {
				$entry = is_array( $entry ) ? $entry : array();
				$answers = array();
				foreach ( is_array( $entry['answers'] ?? null ) ? $entry['answers'] : array() as $key => $value ) {
					if ( is_scalar( $value ) ) { $answers[ sanitize_key( (string) $key ) ] = (string) $value; }
				}
				$attendees[ absint( $type_id ) ][] = array(
					'name' => is_scalar( $entry['name'] ?? null ) ? (string) $entry['name'] : '',
					'email' => is_scalar( $entry['email'] ?? null ) ? (string) $entry['email'] : '',
					'answers' => $answers,
				);
			}
		}
		return array(
			'occurrence_id' => Forms::int( $in, 'occurrence_id' ),
			'quantities' => $quantities,
			'names' => $names,
			'attendees' => $attendees,
			'email' => Forms::text( $in, 'email' ),
			'method' => Forms::choice( $in, 'method', array_keys( Service::methods() ), '' ),
			'tendered_minor' => $tendered,
			'reference' => Forms::text( $in, 'reference', 100 ),
			'form_key' => Forms::text( $in, 'form_key', 64 ),
			'billing' => self::billing_input( $in ),
		);
	}

	/** Billing fields of the POS form; null when only the preselected country is set. */
	private static function billing_input( array $in ) {
		$raw = is_array( $in['billing'] ?? null ) ? $in['billing'] : array();
		$billing = \PassCaptain\Billing::sanitize( $raw );
		$filled = array_filter( array_diff_key( $billing, array( 'country' => true ) ), 'strlen' );
		return $filled ? array( 'enabled' => true ) + $billing : null;
	}

	public static function handle_sell() {
		Forms::verify( 'wpcap_pos_sell' );
		$input = self::sale_input( Forms::post() );
		$result = Service::sell( $input );
		if ( is_wp_error( $result ) ) {
			Forms::finish( $result, '', self::back( array( 'occurrence_id' => $input['occurrence_id'] ) ) );
		}
		Forms::redirect( self::back( array( 'occurrence_id' => $input['occurrence_id'], 'sale' => (int) $result['sale']->sale_id ) ) );
	}

	public static function handle_storno() {
		$in = Forms::post();
		$sale_id = Forms::int( $in, 'sale_id' );
		Forms::verify( 'wpcap_pos_storno_' . $sale_id );
		$result = Service::storno( $sale_id, Forms::text( $in, 'reason' ), Forms::ids( $in, 'ticket_ids' ), Forms::text( $in, 'override_reason', 200 ) );
		$message = ! is_wp_error( $result ) && 'storno_pending' === $result->status ? __( 'Storno angelegt; es wartet auf Freigabe.', 'wp-pass-captain' ) : __( 'Storno gebucht. Bitte Betrag auszahlen bzw. am Kartenterminal gutschreiben und den Stornobeleg drucken.', 'wp-pass-captain' );
		Forms::finish( $result, $message, self::back() );
	}

	/** Whether the current user may act on a sale of the given session (cashier or administrator). */
	private static function own_or_admin( $sale ) {
		if ( ! $sale ) { return false; }
		$session = Service::session( $sale->session_id );
		return $session && ( Shifts::is_cashier( $session, get_current_user_id() ) || current_user_can( 'manage_options' ) ) && Scope::can_order( $sale->order_id, Service::CAP );
	}

	public static function handle_finalize() {
		$sale_id = Forms::int( Forms::post(), 'sale_id' );
		Forms::verify( 'wpcap_pos_finalize_' . $sale_id );
		$sale = Service::sale( $sale_id );
		$result = self::own_or_admin( $sale ) ? Service::finalize( $sale_id ) : Forms::forbidden();
		Forms::finish( $result, __( 'Verkauf gebucht.', 'wp-pass-captain' ), self::back( is_wp_error( $result ) ? array() : array( 'sale' => $sale_id ) ) );
	}

	public static function handle_storno_reject() {
		$sale_id = Forms::int( Forms::post(), 'sale_id' );
		Forms::verify( 'wpcap_pos_storno_reject_' . $sale_id );
		Forms::finish( Service::reject_storno( $sale_id ), __( 'Storno abgelehnt; der Verkauf bleibt bestehen.', 'wp-pass-captain' ), self::back() );
	}

	public static function handle_receipt() {
		$sale = Service::sale( absint( $_GET['sale_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view with permission check.
		if ( ! self::can_view_sale( $sale ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 ); }
		if ( ! Service::is_booked( $sale ) ) { wp_die( esc_html__( 'Für diesen Verkauf gibt es keinen Beleg, weil er nicht gebucht wurde.', 'wp-pass-captain' ), 404 ); }
		nocache_headers();
		echo Service::receipt_html( $sale->sale_id, ! empty( $_GET['storno'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.NonceVerification.Recommended -- escaped in receipt_html.
		exit;
	}

	public static function handle_tickets() {
		$sale_id = Forms::int( Forms::post(), 'sale_id' );
		Forms::verify( 'wpcap_pos_tickets_' . $sale_id );
		$result = self::tickets_pdf( $sale_id );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), 403 ); }
		Scope::send_download( $result['pdf'], $result['filename'], 'application/pdf' );
	}

	public static function handle_availability() {
		check_admin_referer( 'wpcap_pos' );
		$occurrence_id = absint( $_GET['occurrence_id'] ?? 0 );
		if ( ! Scope::can_event( Scope::occurrence_event( $occurrence_id ), Service::CAP ) ) { wp_send_json_error( null, 403 ); }
		$out = array();
		foreach ( Service::ticket_types( $occurrence_id ) as $t ) { $out[ $t['ticket_type_id'] ] = $t['available']; }
		wp_send_json_success( $out );
	}

	public static function handle_settings() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 ); }
		Forms::verify( 'wpcap_pos_settings' );
		$in = Forms::post();
		Settings::update( array(
			'boxoffice_receipt_prefix' => preg_replace( '/[^A-Za-z0-9_-]/', '', Forms::text( $in, 'boxoffice_receipt_prefix', 10 ) ) ?: 'BON',
			'boxoffice_receipt_footer' => Forms::text( $in, 'boxoffice_receipt_footer', 300 ),
			'boxoffice_receipt_cashier' => Forms::choice( $in, 'boxoffice_receipt_cashier', array( 'id', 'initials', 'name' ), 'id' ),
			'boxoffice_cash_confirmed' => Forms::flag( $in, 'boxoffice_cash_confirmed' ) ? 1 : 0,
		) );
		Audit::record( 'boxoffice_settings_saved', 'settings', 0, array( 'cash_confirmed' => (bool) Settings::get( 'boxoffice_cash_confirmed' ) ) );
		Forms::finish( true, __( 'Einstellungen gespeichert.', 'wp-pass-captain' ), self::back() );
	}
}

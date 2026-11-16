<?php
/**
 * Sections of the event edit screen (hook `wpcap_admin_event_sections`): tax country / place of
 * supply, automatic price rules and buyer limits. Form posts go to admin-post handlers registered
 * by the module Modules\SalesRules.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Sales;

use PassCaptain\Access;
use PassCaptain\Admin\EventsPage;
use PassCaptain\Admin\Forms;
use PassCaptain\Admin\Queries;
use PassCaptain\Billing;
use PassCaptain\Series\Pricing as Engine;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class AdminSections {
	const CAP = 'wpcap_manage_events';
	const NONCE = 'wpcap_sales_rules';

	public static function register() {
		add_action( 'wpcap_admin_event_sections', array( __CLASS__, 'render' ), 10, 2 );
	}

	public static function handlers() {
		return array(
			'wpcap_sales_event_action' => array( __CLASS__, 'handle' ),
		);
	}

	private static function event_url( $event_id ) {
		return Forms::page_url( EventsPage::SLUG, array( 'view' => 'event', 'event_id' => absint( $event_id ) ) );
	}

	/**
	 * Executes a section action (testable without a request).
	 *
	 * do: tax_country (country), rule_save (Series\Pricing::normalize input + occurrence_id,
	 * ticket_type_id), rule_delete (rule_id), limit_save (BuyerLimits::create input), limit_delete (limit_id).
	 *
	 * @return array|\WP_Error
	 */
	public static function action( array $in ) {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		$event_id = Forms::int( $in, 'event_id' );
		if ( ! $event_id || ! Access::can_access_event( $event_id, self::CAP ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ) ); }
		switch ( sanitize_key( $in['do'] ?? '' ) ) {
			case 'tax_country':
				$result = TaxProfiles::set_event_country( $event_id, Forms::text( $in, 'tax_country', 2 ) );
				return is_wp_error( $result ) ? $result : array( 'event_id' => $event_id, 'message' => __( 'Steuerland gespeichert.', 'pass-captain' ) );
			case 'rule_save':
				$result = PriceRules::create( $event_id, $in );
				return is_wp_error( $result ) ? $result : array( 'event_id' => $event_id, 'rule_id' => $result, 'message' => __( 'Preisregel gespeichert.', 'pass-captain' ) );
			case 'rule_delete':
				$rule = self::owned( 'wpcap_price_rules', 'rule_id', Forms::int( $in, 'rule_id' ), $event_id );
				if ( ! $rule ) { return Forms::error( 'wpcap_price_rule_missing', __( 'Preisregel nicht gefunden.', 'pass-captain' ), 404 ); }
				$result = PriceRules::delete( (int) $rule );
				return is_wp_error( $result ) ? $result : array( 'event_id' => $event_id, 'message' => __( 'Preisregel entfernt.', 'pass-captain' ) );
			case 'limit_save':
				$result = BuyerLimits::create( $event_id, $in );
				return is_wp_error( $result ) ? $result : array( 'event_id' => $event_id, 'limit_id' => $result, 'message' => __( 'Käuferlimit gespeichert.', 'pass-captain' ) );
			case 'limit_delete':
				$limit = self::owned( 'wpcap_buyer_limits', 'limit_id', Forms::int( $in, 'limit_id' ), $event_id );
				if ( ! $limit ) { return Forms::error( 'wpcap_buyer_limit_missing', __( 'Käuferlimit nicht gefunden.', 'pass-captain' ), 404 ); }
				$result = BuyerLimits::delete( (int) $limit );
				return is_wp_error( $result ) ? $result : array( 'event_id' => $event_id, 'message' => __( 'Käuferlimit entfernt.', 'pass-captain' ) );
		}
		return Forms::error( 'wpcap_sales_action', __( 'Unbekannte Aktion.', 'pass-captain' ), 404 );
	}

	/** Id of a row that belongs to the event (0 otherwise). */
	private static function owned( $table, $column, $id, $event_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT $column FROM {$wpdb->prefix}$table WHERE $column=%d AND event_id=%d", absint( $id ), absint( $event_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed identifiers.
	}

	public static function handle() {
		Forms::verify( self::NONCE );
		$in = Forms::post();
		$result = self::action( $in );
		Forms::finish( $result, is_array( $result ) ? (string) ( $result['message'] ?? '' ) : '', self::event_url( Forms::int( $in, 'event_id' ) ) . '#wpcap-sales-rules' );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Rendering.
	 * ------------------------------------------------------------------------------------------- */

	private static function open( $event_id, $do ) {
		Forms::open_form( 'wpcap_sales_event_action', self::NONCE );
		Forms::hidden( 'event_id', (int) $event_id );
		Forms::hidden( 'do', $do );
	}

	private static function weekday_names() {
		return array( 1 => __( 'Montag', 'pass-captain' ), 2 => __( 'Dienstag', 'pass-captain' ), 3 => __( 'Mittwoch', 'pass-captain' ), 4 => __( 'Donnerstag', 'pass-captain' ), 5 => __( 'Freitag', 'pass-captain' ), 6 => __( 'Samstag', 'pass-captain' ), 7 => __( 'Sonntag', 'pass-captain' ) );
	}

	/** Scope label of a rule/limit row. */
	private static function scope_label( $row, array $occurrences, array $types ) {
		if ( (int) $row->ticket_type_id ) { return $types[ (int) $row->ticket_type_id ] ?? '#' . (int) $row->ticket_type_id; }
		$label = (int) $row->occurrence_id ? ( $occurrences[ (int) $row->occurrence_id ] ?? '#' . (int) $row->occurrence_id ) : __( 'Alle Termine', 'pass-captain' );
		if ( isset( $row->type_name ) && '' !== (string) $row->type_name ) { $label .= ' – ' . $row->type_name; }
		return $label;
	}

	public static function render( $event, $editable ) {
		$event_id = (int) $event->event_id;
		if ( ! Access::can_access_event( $event_id, self::CAP ) ) { return; }
		$occ_rows = Queries::occurrences_for_event( $event_id );
		$occurrences = array();
		$types = array();
		$timezone = 'UTC';
		$currency = 'EUR';
		foreach ( $occ_rows as $occ ) {
			$occurrences[ (int) $occ->occurrence_id ] = Forms::datetime( $occ->starts_at, $occ->timezone, false );
			$timezone = (string) $occ->timezone;
			foreach ( Queries::ticket_types_for_occurrence( $occ->occurrence_id ) as $type ) {
				$types[ (int) $type->ticket_type_id ] = $occurrences[ (int) $occ->occurrence_id ] . ' – ' . $type->name;
				$currency = (string) $type->currency;
			}
		}
		echo '<h2 id="wpcap-sales-rules">' . esc_html__( 'Steuern, Preisregeln und Käuferlimits', 'pass-captain' ) . '</h2>';

		// Tax country / place of supply.
		$country = TaxProfiles::event_country( $event_id );
		echo '<h3>' . esc_html__( 'Steuerland (Leistungsort)', 'pass-captain' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Für Eintrittsberechtigungen zu Veranstaltungen wird in der Regel am Veranstaltungsort besteuert. Das hier gewählte Land bestimmt zusammen mit der Steuerklasse der Ticketart das aktive Steuerprofil. Die Steuerprofile sind technische Vorlagen und müssen fachlich (Steuerberatung) geprüft werden.', 'pass-captain' ) . '</p>';
		if ( $editable ) {
			self::open( $event_id, 'tax_country' );
			$choices = array( '' => sprintf( /* translators: %s: seller country code */ __( 'Wie Verkäuferland (%s)', 'pass-captain' ), TaxProfiles::seller_country() ) ) + Billing::countries();
			echo '<p><label for="wpcap-ev-taxcountry">' . esc_html__( 'Land des Veranstaltungsorts', 'pass-captain' ) . '</label> ';
			Forms::select( 'tax_country', $choices, $country, 'wpcap-ev-taxcountry' );
			echo ' <button type="submit" class="button">' . esc_html__( 'Speichern', 'pass-captain' ) . '</button></p></form>';
		} else {
			echo '<p>' . esc_html( '' !== $country ? $country : TaxProfiles::seller_country() ) . '</p>';
		}

		// Price rules.
		$kinds = Engine::kind_labels();
		echo '<h3>' . esc_html__( 'Automatische Preisregeln', 'pass-captain' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Frühbucher-, Last-Minute-, Nachfrage- und Wochentags-/Zeitfensterpreise für Termine und Ticketarten dieses Events. Von mehreren passenden Regeln (auch Regeln einer Terminserie) gewinnt die mit der höchsten Priorität. Käufer sehen den Grund des Preises; die angewendete Regel wird in der Bestellposition gespeichert.', 'pass-captain' ) . '</p>';
		$rules = PriceRules::list_for_event( $event_id );
		if ( $rules ) {
			echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Bezeichnung', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Gilt für', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Bedingung (Käuferhinweis)', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Preis', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Priorität', 'pass-captain' ) . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__( 'Aktionen', 'pass-captain' ) . '</span></th></tr></thead><tbody>';
			foreach ( $rules as $rule ) {
				$price = 'percent' === $rule->price_mode ? ( (int) $rule->amount > 0 ? '+' : '−' ) . Forms::percent_input( abs( (int) $rule->amount ) ) . ' %' : Forms::money( (int) $rule->amount, $currency );
				echo '<tr><td>' . esc_html( PriceRules::label( $rule ) ) . ' <small>(' . esc_html( $kinds[ $rule->kind ] ?? $rule->kind ) . ')</small></td><td>' . esc_html( self::scope_label( $rule, $occurrences, $types ) ) . '</td><td>' . esc_html( PriceRules::explain( $rule, (object) array( 'timezone' => $timezone ) ) ) . '</td><td>' . esc_html( $price ) . '</td><td>' . (int) $rule->priority . '</td><td>';
				if ( $editable ) {
					self::open( $event_id, 'rule_delete' );
					Forms::hidden( 'rule_id', (int) $rule->rule_id );
					/* translators: %s: rule name */
					echo '<button type="submit" class="button-link-delete" aria-label="' . esc_attr( sprintf( __( 'Preisregel „%s“ entfernen', 'pass-captain' ), PriceRules::label( $rule ) ) ) . '">' . esc_html__( 'Entfernen', 'pass-captain' ) . '</button></form>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>' . esc_html__( 'Keine Preisregeln – es gilt der Preis der Ticketart (bzw. die Regeln einer Terminserie).', 'pass-captain' ) . '</p>';
		}
		if ( $editable && $occ_rows ) {
			echo '<h4>' . esc_html__( 'Preisregel hinzufügen', 'pass-captain' ) . '</h4>';
			self::open( $event_id, 'rule_save' );
			echo '<table class="form-table" role="presentation"><tbody>';
			$row = static function ( $id, $label, $callback, $help = '' ) {
				echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
				$callback();
				if ( $help ) { echo '<p class="description" id="' . esc_attr( $id ) . '-help">' . esc_html( $help ) . '</p>'; }
				echo '</td></tr>';
			};
			$row( 'wpcap-pr-label', __( 'Bezeichnung (öffentlich, optional)', 'pass-captain' ), static function () { echo '<input id="wpcap-pr-label" name="label" type="text" class="regular-text" maxlength="190">'; }, __( 'Wird neben dem Preis angezeigt, z. B. „Frühbucher“.', 'pass-captain' ) );
			$row( 'wpcap-pr-kind', __( 'Art', 'pass-captain' ), static function () use ( $kinds ) { Forms::select( 'kind', $kinds, 'early_bird', 'wpcap-pr-kind' ); } );
			$row( 'wpcap-pr-occ', __( 'Termin', 'pass-captain' ), static function () use ( $occurrences ) { Forms::select( 'occurrence_id', array( '0' => __( 'Alle Termine', 'pass-captain' ) ) + $occurrences, '0', 'wpcap-pr-occ' ); } );
			$row( 'wpcap-pr-type', __( 'Ticketart', 'pass-captain' ), static function () use ( $types ) { Forms::select( 'ticket_type_id', array( '0' => __( 'Alle Ticketarten', 'pass-captain' ) ) + $types, '0', 'wpcap-pr-type' ); } );
			$row( 'wpcap-pr-tname', __( 'Nur Ticketarten mit Namen (optional)', 'pass-captain' ), static function () { echo '<input id="wpcap-pr-tname" name="type_name" type="text" class="regular-text" maxlength="190" aria-describedby="wpcap-pr-tname-help">'; }, __( 'Z. B. „Ermäßigt“ für alle Termine; leer = alle.', 'pass-captain' ) );
			$row( 'wpcap-pr-mode', __( 'Preis', 'pass-captain' ), static function () use ( $currency ) {
				Forms::select( 'price_mode', array( 'fixed' => sprintf( /* translators: %s: currency */ __( 'Fester Preis (brutto, %s)', 'pass-captain' ), $currency ), 'percent' => __( 'Prozentuale Änderung', 'pass-captain' ) ), 'fixed', 'wpcap-pr-mode' );
				echo ' <label class="screen-reader-text" for="wpcap-pr-amount">' . esc_html__( 'Betrag oder Prozent', 'pass-captain' ) . '</label><input id="wpcap-pr-amount" name="amount" type="text" inputmode="decimal" size="10" required aria-describedby="wpcap-pr-mode-help">';
			}, __( 'Fester Preis z. B. 19,90; prozentual z. B. -20 (20 % günstiger) oder 15 (15 % teurer).', 'pass-captain' ) );
			/* translators: %s: timezone */
			$row( 'wpcap-pr-until', __( 'Frühbucher bis', 'pass-captain' ), static function () { echo '<input id="wpcap-pr-until" name="until" type="datetime-local">'; }, sprintf( __( 'Nur für Frühbucher. Ortszeit %s; gilt für Buchungen vor diesem Zeitpunkt.', 'pass-captain' ), $timezone ) );
			$row( 'wpcap-pr-hours', __( 'Last-Minute ab Stunden vor Beginn', 'pass-captain' ), static function () { echo '<input id="wpcap-pr-hours" name="hours_before" type="number" min="1" max="1440">'; }, __( 'Gilt ab genau diesem Zeitpunkt (inklusive).', 'pass-captain' ) );
			$row( 'wpcap-pr-pct', __( 'Nachfragestufe ab % verkauft', 'pass-captain' ), static function () { echo '<input id="wpcap-pr-pct" name="sold_pct" type="number" min="1" max="100">'; }, __( 'Verkauft = bezahlte und aktuell reservierte Tickets der Ticketart (bzw. des Termins, wenn die Ticketart kein eigenes Kontingent hat).', 'pass-captain' ) );
			echo '<tr><th scope="row">' . esc_html__( 'Wochentage', 'pass-captain' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Wochentage', 'pass-captain' ) . '</legend>';
			foreach ( self::weekday_names() as $num => $name ) {
				echo '<label style="margin-right:1em"><input type="checkbox" name="weekdays[]" value="' . (int) $num . '"> ' . esc_html( $name ) . '</label>';
			}
			echo '<p class="description">' . esc_html__( 'Nur für Wochentags-/Zeitfensterpreise; maßgeblich ist der Beginn des Termins in Ortszeit.', 'pass-captain' ) . '</p></fieldset></td></tr>';
			$row( 'wpcap-pr-from', __( 'Zeitfenster Beginn von–bis', 'pass-captain' ), static function () {
				echo '<input id="wpcap-pr-from" name="time_from" type="time"> – <label class="screen-reader-text" for="wpcap-pr-to">' . esc_html__( 'bis', 'pass-captain' ) . '</label><input id="wpcap-pr-to" name="time_to" type="time">';
			}, __( '„von“ inklusive, „bis“ exklusive. Leer = ganzer Tag.', 'pass-captain' ) );
			$row( 'wpcap-pr-priority', __( 'Priorität (optional)', 'pass-captain' ), static function () { echo '<input id="wpcap-pr-priority" name="priority" type="number" min="-9999" max="9999">'; }, __( 'Leer = Standard (Last-Minute 40, Nachfrage 30, Frühbucher 20, Wochentag 10).', 'pass-captain' ) );
			echo '</tbody></table>';
			submit_button( __( 'Preisregel hinzufügen', 'pass-captain' ), 'secondary' );
			echo '</form>';
		}

		// Buyer limits.
		$match = array( 'both' => __( 'E-Mail oder Kundenkonto', 'pass-captain' ), 'email' => __( 'E-Mail-Adresse', 'pass-captain' ), 'customer' => __( 'Kundenkonto (angemeldet)', 'pass-captain' ) );
		echo '<h3>' . esc_html__( 'Käuferlimits', 'pass-captain' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Höchstzahl an Tickets pro Person über alle Bestellungen (bezahlt und aktuell reserviert). Gilt im Online-Checkout und für WooCommerce, nicht für die Abendkasse.', 'pass-captain' ) . '</p>';
		$limits = BuyerLimits::for_events( array( $event_id ) );
		if ( $limits ) {
			echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Gilt für', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Höchstens', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Person erkannt an', 'pass-captain' ) . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__( 'Aktionen', 'pass-captain' ) . '</span></th></tr></thead><tbody>';
			foreach ( $limits as $limit ) {
				echo '<tr><td>' . esc_html( self::scope_label( $limit, $occurrences, $types ) ) . '</td><td>' . (int) $limit->max_quantity . '</td><td>' . esc_html( $match[ $limit->match_by ] ?? $limit->match_by ) . '</td><td>';
				if ( $editable ) {
					self::open( $event_id, 'limit_delete' );
					Forms::hidden( 'limit_id', (int) $limit->limit_id );
					echo '<button type="submit" class="button-link-delete">' . esc_html__( 'Entfernen', 'pass-captain' ) . '</button></form>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>' . esc_html__( 'Keine Käuferlimits.', 'pass-captain' ) . '</p>';
		}
		if ( $editable && $occ_rows ) {
			self::open( $event_id, 'limit_save' );
			echo '<p><label for="wpcap-bl-occ">' . esc_html__( 'Termin', 'pass-captain' ) . '</label> ';
			Forms::select( 'occurrence_id', array( '0' => __( 'Alle Termine', 'pass-captain' ) ) + $occurrences, '0', 'wpcap-bl-occ' );
			echo ' <label for="wpcap-bl-type">' . esc_html__( 'Ticketart', 'pass-captain' ) . '</label> ';
			Forms::select( 'ticket_type_id', array( '0' => __( 'Alle Ticketarten', 'pass-captain' ) ) + $types, '0', 'wpcap-bl-type' );
			echo ' <label for="wpcap-bl-max">' . esc_html__( 'Höchstens', 'pass-captain' ) . '</label> <input id="wpcap-bl-max" name="max_quantity" type="number" min="1" max="10000" required style="width:6em"> ';
			echo '<label for="wpcap-bl-match">' . esc_html__( 'Person erkannt an', 'pass-captain' ) . '</label> ';
			Forms::select( 'match_by', $match, 'both', 'wpcap-bl-match' );
			echo ' <button type="submit" class="button">' . esc_html__( 'Käuferlimit hinzufügen', 'pass-captain' ) . '</button></p></form>';
		}
	}
}

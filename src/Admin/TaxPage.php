<?php
/**
 * Admin page "Steuerprüfung" (A115): country tax profiles – list, create/edit drafts, review and
 * activate (four-eyes), retire. Only users with finance rights (wpcap_view_finance or
 * manage_options) see the page. Logic and rules: Tax\Profiles.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Tax\Profiles;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TaxPage {
	const SLUG = 'wpcap-tax-profiles';
	const CAP = Profiles::REVIEW_CAP;

	public static function url( array $args = array() ) {
		return Forms::page_url( self::SLUG, $args );
	}

	/** Admin form input → Profiles::save_draft() input (rate in percent, e.g. "13" or "7,7"). */
	public static function save( array $in ) {
		$rate = Forms::parse_percent_bp( $in['rate'] ?? '' );
		if ( null === $rate ) { return Forms::error( 'wpcap_tax_profile_invalid', __( 'Der Steuersatz ist ungültig. Beispiel: 19 oder 7,7', 'wp-pass-captain' ) ); }
		return Profiles::save_draft( array(
			'tax_profile_id' => Forms::int( $in, 'tax_profile_id' ),
			'country_code' => Forms::text( $in, 'country_code', 2 ),
			'tax_class' => Forms::text( $in, 'tax_class', 64 ),
			'rate_basis_points' => (int) $rate,
			'version' => Forms::text( $in, 'version', 32 ),
			'effective_from' => Forms::text( $in, 'effective_from', 10 ),
			'effective_until' => Forms::text( $in, 'effective_until', 10 ),
		) );
	}

	public static function handle_save() {
		Forms::verify( 'wpcap_tax_profile_save' );
		$in = Forms::post();
		$result = self::save( $in );
		Forms::finish( $result, __( 'Entwurf gespeichert. Er gilt erst, wenn ihn eine zweite Person aus der Finanzbuchhaltung geprüft und aktiviert hat.', 'wp-pass-captain' ), self::url(), self::url( Forms::int( $in, 'tax_profile_id' ) ? array( 'edit' => Forms::int( $in, 'tax_profile_id' ) ) : array() ) );
	}

	public static function handle_activate() {
		$in = Forms::post();
		Forms::verify( 'wpcap_tax_profile_activate_' . Forms::int( $in, 'tax_profile_id' ) );
		if ( ! Forms::flag( $in, 'confirm' ) ) {
			Forms::finish( Forms::error( 'wpcap_tax_profile_confirm', __( 'Bitte bestätige, dass du Satz, Land, Steuerklasse und Gültigkeit geprüft hast.', 'wp-pass-captain' ) ), '', self::url() );
		}
		Forms::finish( Profiles::activate( Forms::int( $in, 'tax_profile_id' ), Forms::text( $in, 'note' ) ), __( 'Steuerprofil geprüft und aktiviert. Neue Bestellungen verwenden ab sofort diesen Satz.', 'wp-pass-captain' ), self::url() );
	}

	public static function handle_retire() {
		$in = Forms::post();
		Forms::verify( 'wpcap_tax_profile_retire_' . Forms::int( $in, 'tax_profile_id' ) );
		Forms::finish( Profiles::retire( Forms::int( $in, 'tax_profile_id' ), Forms::text( $in, 'note' ) ), __( 'Steuerprofil außer Kraft gesetzt. Bestehende Bestellungen behalten ihren Satz.', 'wp-pass-captain' ), self::url() );
	}

	private static function status_label( $status ) {
		$labels = array(
			'draft' => __( 'Entwurf – wartet auf Prüfung', 'wp-pass-captain' ),
			'active' => __( 'Aktiv', 'wp-pass-captain' ),
			'retired' => __( 'Außer Kraft', 'wp-pass-captain' ),
		);
		return $labels[ $status ] ?? (string) $status;
	}

	private static function date_label( $date ) {
		return $date ? wp_date( 'd.m.Y', (int) strtotime( $date . ' 12:00:00 UTC' ), new \DateTimeZone( 'UTC' ) ) : '…';
	}

	public static function render() {
		if ( ! Profiles::can_manage() ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 ); }
		echo '<div class="wrap"><h1>' . esc_html__( 'Steuerprüfung', 'wp-pass-captain' ) . '</h1>';
		Forms::render_notices();
		echo '<p class="description">' . esc_html__( 'Steuerprofile legen den Umsatzsteuersatz je Land und Steuerklasse fest. Für Eintrittskarten ist der Leistungsort das Land der Veranstaltung (Feld „Veranstaltungsland“ am Termin, sonst das Land des Rechnungsstellers). Hat eine Ticketart eine Steuerklasse und gibt es dafür ein aktives Profil, das am Verkaufstag gilt, verwendet die Bestellung dessen Satz – sonst den Steuersatz der Ticketart. Jede Bestellposition speichert den verwendeten Satz samt Profil und Version; Rechnungen, Korrekturen und Berichte bleiben dadurch nachvollziehbar.', 'wp-pass-captain' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'WooCommerce-Bestellungen verwenden die Steuereinstellungen von WooCommerce; die Profile gelten für den eigenen Checkout, die Abendkasse, die Partner-API, Wartelisten-Angebote und Gutscheineinlösungen.', 'wp-pass-captain' ) . '</p>';

		$coverage = Profiles::coverage();
		echo '<h2>' . esc_html__( 'Steuerklassen in Verwendung', 'wp-pass-captain' ) . '</h2>';
		if ( ! $coverage ) {
			echo '<p>' . esc_html__( 'Keine aktive Ticketart mit Steuerklasse für kommende Termine. Alle Verkäufe verwenden den Steuersatz der Ticketart.', 'wp-pass-captain' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Land', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Steuerklasse', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Ticketarten', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Heute gültiges Profil', 'wp-pass-captain' ) . '</th></tr></thead><tbody>';
			foreach ( $coverage as $row ) {
				$profile = $row['profile'];
				$label = $profile ? Forms::percent_input( (int) $profile->rate_basis_points ) . ' % – ' . sprintf( /* translators: %s: profile version */ __( 'Version %s', 'wp-pass-captain' ), $profile->version ) : __( 'keins – es gilt der Satz der Ticketart', 'wp-pass-captain' );
				echo '<tr><td>' . esc_html( $row['country'] ) . '</td><td><code>' . esc_html( $row['tax_class'] ) . '</code></td><td>' . (int) $row['ticket_types'] . '</td><td>' . ( $profile ? '' : '<span class="dashicons dashicons-warning" aria-hidden="true"></span> ' ) . esc_html( $label ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		$rows = Profiles::all();
		echo '<h2>' . esc_html__( 'Steuerprofile', 'wp-pass-captain' ) . '</h2>';
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'Noch keine Steuerprofile.', 'wp-pass-captain' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Land', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Steuerklasse', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Satz', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Version', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Gültig', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Status', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Angelegt / bearbeitet von', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Geprüft von', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Aktionen', 'wp-pass-captain' ) . '</th></tr></thead><tbody>';
			foreach ( $rows as $row ) {
				$editors = Forms::user_label( (int) ( $row->created_by ?? 0 ) );
				if ( (int) ( $row->updated_by ?? 0 ) && (int) $row->updated_by !== (int) ( $row->created_by ?? 0 ) ) { $editors .= ' / ' . Forms::user_label( (int) $row->updated_by ); }
				$reviewed = (int) ( $row->reviewed_by ?? 0 ) ? Forms::user_label( (int) $row->reviewed_by ) . ( ! empty( $row->reviewed_at ) ? ', ' . Forms::datetime( $row->reviewed_at, wp_timezone_string(), false ) : '' ) . ( ! empty( $row->review_note ) ? ' – ' . $row->review_note : '' ) : '—';
				echo '<tr><td>' . esc_html( $row->country_code ) . '</td><td><code>' . esc_html( $row->tax_class ) . '</code></td><td>' . esc_html( Forms::percent_input( (int) $row->rate_basis_points ) . ' %' ) . '</td><td>' . esc_html( $row->version ) . '</td><td>' . esc_html( self::date_label( $row->effective_from ) . ' – ' . self::date_label( $row->effective_until ) ) . '</td><td>' . esc_html( self::status_label( $row->status ) ) . '</td><td>' . esc_html( $editors ) . '</td><td>' . esc_html( $reviewed ) . '</td><td>';
				$id = (int) $row->tax_profile_id;
				if ( 'draft' === $row->status ) {
					echo '<a class="button button-small" href="' . esc_url( self::url( array( 'edit' => $id ) ) ) . '">' . esc_html__( 'Bearbeiten', 'wp-pass-captain' ) . '</a> ';
					if ( Profiles::self_review_blocked( $row ) ) {
						echo '<p class="description">' . esc_html__( 'Vier-Augen-Prinzip: Die Prüfung muss eine andere Person aus der Finanzbuchhaltung übernehmen.', 'wp-pass-captain' ) . '</p>';
					} else {
						Forms::open_form( 'wpcap_tax_profile_activate', 'wpcap_tax_profile_activate_' . $id );
						Forms::hidden( 'tax_profile_id', $id );
						echo '<label><input type="checkbox" name="confirm" value="1" required> ' . esc_html__( 'Satz, Land, Steuerklasse und Gültigkeit geprüft', 'wp-pass-captain' ) . '</label><br>';
						echo '<label for="wpcap-tp-note-' . (int) $id . '" class="screen-reader-text">' . esc_html__( 'Prüfvermerk', 'wp-pass-captain' ) . '</label><input id="wpcap-tp-note-' . (int) $id . '" name="note" maxlength="190" placeholder="' . esc_attr__( 'Prüfvermerk (optional)', 'wp-pass-captain' ) . '"> ';
						echo '<button class="button button-primary button-small">' . esc_html__( 'Prüfen und aktivieren', 'wp-pass-captain' ) . '</button></form>';
					}
				}
				if ( 'retired' !== $row->status ) {
					Forms::open_form( 'wpcap_tax_profile_retire', 'wpcap_tax_profile_retire_' . $id );
					Forms::hidden( 'tax_profile_id', $id );
					echo '<button class="button button-small button-link-delete" onclick="return confirm(this.dataset.confirm)" data-confirm="' . esc_attr__( 'Profil wirklich außer Kraft setzen? Neue Bestellungen verwenden danach wieder den Satz der Ticketart (oder ein anderes aktives Profil).', 'wp-pass-captain' ) . '">' . esc_html( 'draft' === $row->status ? __( 'Entwurf verwerfen', 'wp-pass-captain' ) : __( 'Außer Kraft setzen', 'wp-pass-captain' ) ) . '</button></form>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}

		$edit_id = absint( Forms::query_arg( 'edit', 0 ) );
		$edit = $edit_id ? Profiles::get( $edit_id ) : null;
		if ( $edit && 'draft' !== $edit->status ) { $edit = null; }
		echo '<h2>' . esc_html( $edit ? sprintf( /* translators: %d: profile ID */ __( 'Entwurf #%d bearbeiten', 'wp-pass-captain' ), (int) $edit->tax_profile_id ) : __( 'Neues Steuerprofil (Entwurf)', 'wp-pass-captain' ) ) . '</h2>';
		Forms::open_form( 'wpcap_tax_profile_save' );
		Forms::hidden( 'tax_profile_id', $edit ? (int) $edit->tax_profile_id : 0 );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="wpcap-tp-country">' . esc_html__( 'Land', 'wp-pass-captain' ) . '</label></th><td><input id="wpcap-tp-country" name="country_code" required size="3" maxlength="2" pattern="[A-Za-z]{2}" style="text-transform:uppercase" value="' . esc_attr( $edit ? $edit->country_code : Profiles::seller_country() ) . '"> <span class="description">' . esc_html__( 'ISO-Code des Veranstaltungslandes, z. B. DE oder AT', 'wp-pass-captain' ) . '</span></td></tr>';
		echo '<tr><th><label for="wpcap-tp-class">' . esc_html__( 'Steuerklasse', 'wp-pass-captain' ) . '</label></th><td><input id="wpcap-tp-class" name="tax_class" required maxlength="64" pattern="[a-z0-9_-]+" value="' . esc_attr( $edit ? $edit->tax_class : '' ) . '"> <span class="description">' . esc_html__( 'Gleicher Wert wie das Feld „Steuerklasse“ der Ticketarten, z. B. culture', 'wp-pass-captain' ) . '</span></td></tr>';
		echo '<tr><th><label for="wpcap-tp-rate">' . esc_html__( 'Steuersatz in %', 'wp-pass-captain' ) . '</label></th><td><input id="wpcap-tp-rate" name="rate" required inputmode="decimal" size="6" value="' . esc_attr( $edit ? Forms::percent_input( (int) $edit->rate_basis_points ) : '' ) . '"></td></tr>';
		echo '<tr><th><label for="wpcap-tp-version">' . esc_html__( 'Version', 'wp-pass-captain' ) . '</label></th><td><input id="wpcap-tp-version" name="version" required maxlength="32" pattern="[A-Za-z0-9._-]{1,32}" value="' . esc_attr( $edit ? $edit->version : '' ) . '"> <span class="description">' . esc_html__( 'z. B. 2027-01; erscheint in der Bestellansicht', 'wp-pass-captain' ) . '</span></td></tr>';
		echo '<tr><th><label for="wpcap-tp-from">' . esc_html__( 'Gültig ab (Verkaufstag)', 'wp-pass-captain' ) . '</label></th><td><input id="wpcap-tp-from" name="effective_from" type="date" required value="' . esc_attr( $edit ? $edit->effective_from : '' ) . '"></td></tr>';
		echo '<tr><th><label for="wpcap-tp-until">' . esc_html__( 'Gültig bis (einschließlich, optional)', 'wp-pass-captain' ) . '</label></th><td><input id="wpcap-tp-until" name="effective_until" type="date" value="' . esc_attr( $edit && $edit->effective_until ? $edit->effective_until : '' ) . '"></td></tr>';
		echo '</tbody></table>';
		submit_button( $edit ? __( 'Entwurf speichern', 'wp-pass-captain' ) : __( 'Entwurf anlegen', 'wp-pass-captain' ) );
		echo '</form></div>';
	}
}

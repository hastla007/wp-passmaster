<?php
/**
 * Admin screen "Steuerprofile": versioned country/tax class profiles (draft → active → archived)
 * with validity dates. Every profile is labelled "fachlich zu prüfen": the plugin ships no tax rules.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Sales;

use PassCaptain\Admin\FeatureScreens;
use PassCaptain\Admin\Forms;
use PassCaptain\Billing;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class TaxProfilesPage {
	const SLUG = 'wpcap-tax-profiles';
	const ACTION = 'wpcap_tax_profile_action';
	const CAP = 'manage_options';

	/**
	 * Executes an admin action (testable without a request). do: save (id 0 = new draft),
	 * activate, archive.
	 *
	 * @return array|\WP_Error
	 */
	public static function admin_action( array $in ) {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		$id = Forms::int( $in, 'id' );
		switch ( sanitize_key( $in['do'] ?? '' ) ) {
			case 'save':
				$result = $id ? TaxProfiles::update( $id, $in ) : TaxProfiles::create( $in );
				return is_wp_error( $result ) ? $result : array( 'tax_profile_id' => $result );
			case 'activate':
				$result = TaxProfiles::activate( $id );
				return is_wp_error( $result ) ? $result : array( 'tax_profile_id' => $id, 'status' => 'active' );
			case 'archive':
				$result = TaxProfiles::archive( $id );
				return is_wp_error( $result ) ? $result : array( 'tax_profile_id' => $id, 'status' => 'archived' );
		}
		return Forms::error( 'wpcap_tax_profile_action', __( 'Unbekannte Aktion.', 'pass-captain' ), 404 );
	}

	public static function handle_action() {
		Forms::verify( self::ACTION );
		$in = Forms::post();
		$result = self::admin_action( $in );
		$do = sanitize_key( $in['do'] ?? '' );
		$messages = array( 'save' => __( 'Steuerprofil gespeichert (Entwurf).', 'pass-captain' ), 'activate' => __( 'Steuerprofil aktiviert. Es gilt für neue Bestellungen ab „gültig ab“.', 'pass-captain' ), 'archive' => __( 'Steuerprofil archiviert.', 'pass-captain' ) );
		$id = Forms::int( $in, 'id' );
		Forms::finish( $result, $messages[ $do ] ?? '', Forms::page_url( self::SLUG ), 'save' === $do ? Forms::page_url( self::SLUG, array( 'profile_id' => $id ? $id : 'new' ) ) : '' );
	}

	private static function status_label( $status ) {
		$labels = array( 'draft' => __( 'Entwurf', 'pass-captain' ), 'active' => __( 'Aktiv', 'pass-captain' ), 'archived' => __( 'Archiviert', 'pass-captain' ) );
		return $labels[ $status ] ?? $status;
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		echo '<div class="wrap wpcap-admin"><h1>' . esc_html__( 'Steuerprofile', 'pass-captain' ) . '</h1>';
		Forms::render_notices();
		echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Fachlich zu prüfen:', 'pass-captain' ) . '</strong> ' . esc_html__( 'Steuerprofile sind technische Vorlagen ohne Gewähr. Steuersätze, Steuerklassen, Leistungsort und Gültigkeitszeiträume müssen vor der Aktivierung von einer Steuerberatung geprüft werden.', 'pass-captain' ) . '</p></div>';
		$arg = Forms::query_arg( 'profile_id', '' );
		if ( 'new' === $arg || absint( $arg ) ) {
			$profile = absint( $arg ) ? TaxProfiles::get( absint( $arg ) ) : null;
			echo '<p><a href="' . esc_url( Forms::page_url( self::SLUG ) ) . '">&larr; ' . esc_html__( 'Alle Steuerprofile', 'pass-captain' ) . '</a></p>';
			if ( absint( $arg ) && ! $profile ) { echo '<p>' . esc_html__( 'Steuerprofil nicht gefunden.', 'pass-captain' ) . '</p></div>'; return; }
			self::render_form( $profile );
			echo '</div>';
			return;
		}
		/* translators: %s: seller country code */
		echo '<p>' . esc_html( sprintf( __( 'Je Bestellposition gilt die aktive Version für das Land des Events (sonst das Verkäuferland %s) und die Steuerklasse der Ticketart am Bestelldatum. Steuersatz, Profil und Version werden in der Bestellposition gespeichert; Rechnungen, Gutschriften und Erstattungen verwenden immer diesen Stand. Ohne passendes aktives Profil gilt der Steuersatz der Ticketart.', 'pass-captain' ), TaxProfiles::seller_country() ) ) . ' <a class="button" href="' . esc_url( Forms::page_url( self::SLUG, array( 'profile_id' => 'new' ) ) ) . '">' . esc_html__( 'Neue Version', 'pass-captain' ) . '</a></p>';
		$rows = TaxProfiles::all();
		if ( ! $rows ) { echo '<p>' . esc_html__( 'Noch keine Steuerprofile.', 'pass-captain' ) . '</p></div>'; return; }
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Land', 'pass-captain' ), __( 'Steuerklasse', 'pass-captain' ), __( 'Bezeichnung', 'pass-captain' ), __( 'Version', 'pass-captain' ), __( 'Satz', 'pass-captain' ), __( 'Gültig', 'pass-captain' ), __( 'Status', 'pass-captain' ), __( 'Prüfung', 'pass-captain' ), __( 'Aktionen', 'pass-captain' ) ) as $head ) {
			echo '<th scope="col">' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$valid = $r->effective_from . ' – ' . ( $r->effective_until ? $r->effective_until : _x( 'offen', 'open-ended validity period of a tax rate', 'pass-captain' ) );
			echo '<tr><td>' . esc_html( $r->country_code ) . '</td><td><code>' . esc_html( $r->tax_class ) . '</code></td><td>' . esc_html( $r->label ) . '</td><td>' . esc_html( $r->version ) . '</td><td>' . esc_html( Forms::percent_input( (int) $r->rate_basis_points ) . ' %' ) . '</td><td>' . esc_html( $valid ) . '</td><td>' . esc_html( self::status_label( $r->status ) ) . '</td><td>' . esc_html__( 'fachlich zu prüfen', 'pass-captain' ) . '</td><td>';
			if ( 'draft' === $r->status ) {
				echo '<a class="button button-small" href="' . esc_url( Forms::page_url( self::SLUG, array( 'profile_id' => (int) $r->tax_profile_id ) ) ) . '">' . esc_html__( 'Bearbeiten', 'pass-captain' ) . '</a> ';
				FeatureScreens::button( self::ACTION, array( 'do' => 'activate', 'id' => (int) $r->tax_profile_id ), __( 'Aktivieren', 'pass-captain' ), __( 'Steuerprofil aktivieren? Es gilt danach für neue Bestellungen im Gültigkeitszeitraum. Wurde es fachlich geprüft?', 'pass-captain' ) );
			}
			if ( 'archived' !== $r->status ) {
				FeatureScreens::button( self::ACTION, array( 'do' => 'archive', 'id' => (int) $r->tax_profile_id ), __( 'Archivieren', 'pass-captain' ) );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function render_form( $p ) {
		echo '<h2>' . esc_html( $p ? __( 'Entwurf bearbeiten', 'pass-captain' ) : __( 'Neue Version (Entwurf)', 'pass-captain' ) ) . '</h2>';
		if ( $p && 'draft' !== $p->status ) { echo '<p>' . esc_html__( 'Aktive und archivierte Versionen sind unveränderlich. Für einen neuen Steuersatz bitte eine neue Version mit eigenem „gültig ab“ anlegen.', 'pass-captain' ) . '</p>'; return; }
		Forms::open_form( self::ACTION );
		Forms::hidden( 'do', 'save' );
		Forms::hidden( 'id', $p ? (int) $p->tax_profile_id : 0 );
		echo '<table class="form-table"><tbody>';
		$country = $p ? (string) $p->country_code : TaxProfiles::seller_country();
		FeatureScreens::row( 'wpcap-tp-country', __( 'Land', 'pass-captain' ), static function () use ( $country ) { Forms::select( 'country_code', Billing::countries(), $country, 'wpcap-tp-country' ); } );
		FeatureScreens::input_row( 'wpcap-tp-class', 'tax_class', __( 'Steuerklasse', 'pass-captain' ), $p ? $p->tax_class : 'standard', 'text', 'maxlength="64" pattern="[a-z0-9_\-]+" required', __( 'Technischer Name, z. B. standard, reduced, culture. Ticketarten wählen ihre Steuerklasse.', 'pass-captain' ) );
		FeatureScreens::input_row( 'wpcap-tp-label', 'label', __( 'Bezeichnung', 'pass-captain' ), $p ? $p->label : '', 'text', 'class="regular-text" maxlength="190"' );
		FeatureScreens::input_row( 'wpcap-tp-rate', 'rate', __( 'Steuersatz in %', 'pass-captain' ), $p ? Forms::percent_input( (int) $p->rate_basis_points ) : '', 'text', 'inputmode="decimal" style="width:6em" required', __( 'z. B. 20, 19, 13, 10, 8,1 oder 0', 'pass-captain' ) );
		FeatureScreens::input_row( 'wpcap-tp-version', 'version', __( 'Version', 'pass-captain' ), $p ? $p->version : '', 'text', 'maxlength="32" pattern="[A-Za-z0-9._\-]{1,32}" required', __( 'z. B. 2027-01 oder v2', 'pass-captain' ) );
		FeatureScreens::input_row( 'wpcap-tp-from', 'effective_from', __( 'Gültig ab', 'pass-captain' ), $p ? $p->effective_from : '', 'date', 'required', __( 'Maßgeblich ist das Bestelldatum (UTC).', 'pass-captain' ) );
		FeatureScreens::input_row( 'wpcap-tp-until', 'effective_until', __( 'Gültig bis (optional)', 'pass-captain' ), $p ? (string) $p->effective_until : '', 'date' );
		$note = $p ? (string) $p->place_of_supply_note : '';
		FeatureScreens::row( 'wpcap-tp-note', __( 'Hinweis auf der Rechnung (optional)', 'pass-captain' ), static function () use ( $note ) { echo '<textarea id="wpcap-tp-note" name="place_of_supply_note" class="large-text" rows="2" maxlength="1000" aria-describedby="wpcap-tp-note-desc">' . esc_textarea( $note ) . '</textarea>'; }, __( 'Z. B. ein geprüfter Hinweis zum Leistungsort. Ohne Text wird bei abweichendem Veranstaltungsland nur „Leistungsort: Veranstaltungsort (Land)“ gedruckt.', 'pass-captain' ) );
		echo '</tbody></table>';
		submit_button( __( 'Entwurf speichern', 'pass-captain' ) );
		echo '</form>';
	}
}

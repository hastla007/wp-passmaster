<?php
/**
 * Organizer tools on the event and occurrence screens: issue complimentary tickets / guest list
 * (Sales\Comps), duplicate an event or an occurrence (TicketTypeCopier) and the CSV import of events,
 * occurrences and ticket types (Admin\Import).
 *
 * Rendered through the hooks wp_pass_captain_admin_occurrence_sections_v1 / wpcap_admin_event_sections; the
 * admin-post handlers are part of Menu::handlers().
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Access;
use PassCaptain\Sales\Comps;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class OrganizerTools {
	public static function register() {
		add_action( 'wp_pass_captain_admin_occurrence_sections_v1', array( __CLASS__, 'render_occurrence_tools' ), 20, 2 );
		add_action( 'wpcap_admin_event_sections', array( __CLASS__, 'render_event_tools' ), 30, 2 );
	}

	/** admin-post action => handler. */
	public static function handlers() {
		return array(
			'wpcap_issue_comps' => array( __CLASS__, 'handle_issue_comps' ),
			'wpcap_duplicate_event' => array( __CLASS__, 'handle_duplicate_event' ),
			'wpcap_duplicate_occurrence' => array( __CLASS__, 'handle_duplicate_occurrence' ),
			'wpcap_import_events' => array( __CLASS__, 'handle_import' ),
		);
	}

	private static function occurrence_url( $occurrence_id ) {
		return Forms::page_url( EventsPage::SLUG, array( 'view' => 'occurrence', 'occurrence_id' => absint( $occurrence_id ) ) );
	}

	private static function event_url( $event_id ) {
		return Forms::page_url( EventsPage::SLUG, array( 'view' => 'event', 'event_id' => absint( $event_id ) ) );
	}

	/* ------------------------------------------------------------------------------------------ */

	public static function render_occurrence_tools( $occ, $editable ) {
		$types = Queries::ticket_types_for_occurrence( (int) $occ->occurrence_id );
		if ( $types && 'scheduled' === $occ->status && current_user_can( Comps::CAP ) ) {
			$options = array();
			foreach ( $types as $type ) { $options[ (int) $type->ticket_type_id ] = $type->name; }
			?>
			<h2 id="wpcap-comps"><?php esc_html_e( 'Tickets ausstellen (Freikarten, Gästeliste)', 'pass-captain' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Stellt kostenlose Tickets ohne Kasse aus: 0 €, Zahlart „Freikarte“, kein Kassenbon und kein Fiskalbeleg. Das Kontingent gilt wie beim Verkauf; jede Person erhält eine eigene Bestellung mit Ticket-Mail. Pflichtfelder können Gäste im Bestellportal nachtragen.', 'pass-captain' ); ?></p>
			<?php Forms::open_form( 'wpcap_issue_comps' ); Forms::hidden( 'occurrence_id', $occ->occurrence_id ); Forms::hidden( 'batch_key', wp_generate_uuid4() ); ?>
			<table class="form-table"><tbody>
				<tr><th><label for="wpcap-comp-type"><?php esc_html_e( 'Ticketart', 'pass-captain' ); ?></label></th><td><?php Forms::select( 'ticket_type_id', $options, (int) array_key_first( $options ), 'wpcap-comp-type' ); ?></td></tr>
				<tr><th><label for="wpcap-comp-email"><?php esc_html_e( 'Empfänger (E-Mail)', 'pass-captain' ); ?></label></th><td><input id="wpcap-comp-email" name="recipient_email" type="email" class="regular-text" maxlength="190"> <label><?php esc_html_e( 'Name', 'pass-captain' ); ?> <input name="recipient_name" type="text" maxlength="190"></label> <label><?php esc_html_e( 'Menge', 'pass-captain' ); ?> <input name="quantity" type="number" min="1" max="100" value="1"></label></td></tr>
				<tr><th><label for="wpcap-comp-list"><?php esc_html_e( 'oder Gästeliste (CSV)', 'pass-captain' ); ?></label></th><td><textarea id="wpcap-comp-list" name="guest_list" class="large-text code" rows="5" aria-describedby="wpcap-comp-list-desc"></textarea><p class="description" id="wpcap-comp-list-desc"><?php esc_html_e( 'Eine Zeile pro Gast: Name,E-Mail[,Menge] (auch ; oder Tab). Höchstens 500 Zeilen. Ist die Liste ausgefüllt, wird der Einzelempfänger ignoriert.', 'pass-captain' ); ?></p></td></tr>
				<tr><th><label for="wpcap-comp-note"><?php esc_html_e( 'Notiz (Protokoll)', 'pass-captain' ); ?></label></th><td><input id="wpcap-comp-note" name="note" type="text" class="regular-text" maxlength="190"></td></tr>
			</tbody></table>
			<?php submit_button( __( 'Tickets ausstellen', 'pass-captain' ), 'secondary' ); ?>
			</form>
			<?php
		}
		if ( $editable && Access::can_access_event( (int) $occ->event_id, EventsPage::CAP ) ) {
			?>
			<h2><?php esc_html_e( 'Termin duplizieren', 'pass-captain' ); ?></h2>
			<?php Forms::open_form( 'wpcap_duplicate_occurrence' ); Forms::hidden( 'occurrence_id', $occ->occurrence_id ); ?>
			<p><label for="wpcap-dup-start"><?php esc_html_e( 'Beginn des neuen Termins (Ortszeit)', 'pass-captain' ); ?></label> <input id="wpcap-dup-start" name="starts_at" type="datetime-local" required>
			<button class="button" type="submit"><?php esc_html_e( 'Duplizieren', 'pass-captain' ); ?></button></p>
			<p class="description"><?php esc_html_e( 'Übernimmt Ort, Kontingent, Ticketarten mit Teilnehmerfeldern, Verkäufer, Einlassregeln, Preisregeln und Käuferlimits. Verkaufszeiträume werden relativ zum Beginn verschoben.', 'pass-captain' ); ?></p>
			</form>
			<?php
		}
	}

	public static function render_event_tools( $event, $editable ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- hook signature (wpcap_admin_event_sections); duplicating a cancelled event is allowed.
		if ( ! Access::can_access_event( (int) $event->event_id, EventsPage::CAP ) ) { return; }
		?>
		<h2><?php esc_html_e( 'Event duplizieren', 'pass-captain' ); ?></h2>
		<?php Forms::open_form( 'wpcap_duplicate_event' ); Forms::hidden( 'event_id', $event->event_id ); ?>
		<p><label><input type="checkbox" name="with_occurrences" value="1" checked> <?php esc_html_e( 'Kommende Termine mit Ticketarten, Feldern und Preisregeln übernehmen', 'pass-captain' ); ?></label>
		<button class="button" type="submit"><?php esc_html_e( 'Als Entwurf duplizieren', 'pass-captain' ); ?></button></p>
		</form>
		<?php
	}

	/** CSV import form (events list, Admin\Import). */
	public static function render_import() {
		if ( ! current_user_can( EventsPage::CAP ) ) { return; }
		$columns = implode( ', ', Import::COLUMNS );
		?>
		<h2><?php esc_html_e( 'Events aus CSV importieren', 'pass-captain' ); ?></h2>
		<p class="description"><?php echo esc_html( sprintf( /* translators: %s: column names */ __( 'Eine Zeile pro Ticketart; gleiche Eventnamen werden zu einem Event, gleiche Beginnzeiten zu einem Termin zusammengefasst. Spalten: %s. Pflicht: event, starts_at (JJJJ-MM-TT HH:MM, Ortszeit), ticket, price. Importierte Events sind Entwürfe.', 'pass-captain' ), $columns ) ); ?> <?php echo esc_html( EventsPage::net_entry() ? __( 'price ist der Nettopreis ohne Steuer (Einstellung „Preiseingabe“: netto); gespeichert wird der Bruttopreis mit dem Steuersatz, den der Checkout anwendet (aktives Steuerprofil, sonst tax_rate).', 'pass-captain' ) : __( 'price ist der Bruttopreis inklusive Steuer.', 'pass-captain' ) ); ?></p>
		<?php Forms::open_form( 'wpcap_import_events', null, 'enctype="multipart/form-data"' ); ?>
		<p><label for="wpcap-import-file"><?php esc_html_e( 'CSV-Datei', 'pass-captain' ); ?></label> <input id="wpcap-import-file" type="file" name="import_file" accept=".csv,text/csv" required>
		<label><input type="checkbox" name="dry_run" value="1" checked> <?php esc_html_e( 'Nur prüfen (nichts speichern)', 'pass-captain' ); ?></label>
		<button class="button" type="submit"><?php esc_html_e( 'Importieren', 'pass-captain' ); ?></button></p>
		</form>
		<?php
	}

	public static function handle_import() {
		Forms::verify( 'wpcap_import_events' );
		$back = Forms::page_url( EventsPage::SLUG );
		if ( ! current_user_can( EventsPage::CAP ) ) { Forms::finish( Forms::forbidden(), '', $back ); }
		$file = $_FILES['import_file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing -- nonce verified above (Forms::verify); only tmp_name/size are used, the content is parsed and validated.
		$path = is_array( $file ) && isset( $file['tmp_name'] ) && is_uploaded_file( (string) $file['tmp_name'] ) && (int) $file['size'] <= 2 * 1024 * 1024 ? (string) $file['tmp_name'] : '';
		if ( '' === $path ) { Forms::finish( Forms::error( 'wpcap_import_file', __( 'Bitte eine CSV-Datei bis 2 MB hochladen.', 'pass-captain' ) ), '', $back ); }
		$plan = Import::plan( (string) file_get_contents( $path ), wp_timezone_string() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- uploaded temp file.
		if ( $plan['errors'] ) {
			Forms::finish( Forms::error( 'wpcap_import_invalid', implode( ' ', array_slice( $plan['errors'], 0, 10 ) ) ), '', $back );
		}
		$occurrences = array_sum( array_map( static function ( $e ) { return count( $e['occurrences'] ); }, $plan['events'] ) );
		if ( Forms::flag( Forms::post(), 'dry_run' ) ) {
			/* translators: 1: events, 2: occurrences, 3: rows */
			Forms::finish( array(), sprintf( __( 'Prüfung ohne Fehler: %1$d Events, %2$d Termine, %3$d Ticketarten. Zum Importieren „Nur prüfen“ abwählen.', 'pass-captain' ), count( $plan['events'] ), $occurrences, (int) $plan['rows'] ), $back );
		}
		$result = Import::import( $plan );
		/* translators: 1: events, 2: occurrences, 3: ticket types */
		Forms::finish( $result, is_wp_error( $result ) ? '' : sprintf( __( 'Importiert: %1$d Events (Entwurf), %2$d Termine, %3$d Ticketarten.', 'pass-captain' ), $result['events'], $result['occurrences'], $result['ticket_types'] ), $back );
	}

	/* ------------------------------------------------------------------------------------------ */

	public static function handle_issue_comps() {
		$in = Forms::post();
		Forms::verify( 'wpcap_issue_comps' );
		$occurrence_id = Forms::int( $in, 'occurrence_id' );
		$result = Comps::issue( array(
			'ticket_type_id' => Forms::int( $in, 'ticket_type_id' ),
			'recipient_email' => Forms::text( $in, 'recipient_email' ),
			'recipient_name' => Forms::text( $in, 'recipient_name' ),
			'quantity' => Forms::int( $in, 'quantity', 1 ),
			'guest_list' => Forms::textarea( $in, 'guest_list', 100000 ),
			'note' => Forms::text( $in, 'note' ),
			'batch_key' => Forms::text( $in, 'batch_key', 64 ),
		) );
		if ( ! is_wp_error( $result ) && $result['failed'] ) {
			$first = $result['failed'][0];
			/* translators: 1: number of failed rows, 2: e-mail, 3: reason */
			$result['warning'] = sprintf( __( '%1$d Zeilen wurden nicht ausgestellt, z. B. %2$s: %3$s', 'pass-captain' ), count( $result['failed'] ), $first['email'], $first['message'] );
		}
		/* translators: %d: number of tickets */
		$message = is_wp_error( $result ) ? '' : sprintf( _n( '%d Ticket ausgestellt.', '%d Tickets ausgestellt.', (int) $result['issued'], 'pass-captain' ), (int) $result['issued'] );
		Forms::finish( $result, $message, self::occurrence_url( $occurrence_id ) );
	}

	public static function handle_duplicate_event() {
		$in = Forms::post();
		Forms::verify( 'wpcap_duplicate_event' );
		$result = Duplicator::duplicate_event( Forms::int( $in, 'event_id' ), Forms::flag( $in, 'with_occurrences' ) );
		Forms::finish( $result, __( 'Event dupliziert (Entwurf).', 'pass-captain' ), is_wp_error( $result ) ? self::event_url( Forms::int( $in, 'event_id' ) ) : self::event_url( $result['event_id'] ) );
	}

	public static function handle_duplicate_occurrence() {
		$in = Forms::post();
		Forms::verify( 'wpcap_duplicate_occurrence' );
		$occ = Queries::occurrence( Forms::int( $in, 'occurrence_id' ) );
		$starts = $occ ? Forms::local_to_utc( Forms::text( $in, 'starts_at', 32 ), $occ->timezone ) : '';
		$result = Duplicator::duplicate_occurrence( Forms::int( $in, 'occurrence_id' ), (string) $starts );
		Forms::finish( $result, __( 'Termin dupliziert.', 'pass-captain' ), is_wp_error( $result ) ? self::occurrence_url( Forms::int( $in, 'occurrence_id' ) ) : self::occurrence_url( $result['occurrence_id'] ) );
	}
}

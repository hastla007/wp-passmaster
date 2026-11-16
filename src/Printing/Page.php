<?php
/** Admin screen "Druck" (print templates, batch print, attendee lists) and its admin-post handlers. */
namespace PassCaptain\Printing;

use PassCaptain\Admin\Forms;
use PassCaptain\Admin\Queries;
use PassCaptain\Audit;
use PassCaptain\Reports\Scope;
use PassCaptain\Security\StepUp;
use PassCaptain\Settings;
use PassCaptain\TempFiles;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Page {
	const SLUG = 'wpcap-print';
	const CAPS = array( 'wpcap_manage_events', 'wpcap_manage_orders', 'wpcap_checkin' );

	public static function menu_cap() {
		foreach ( self::CAPS as $cap ) {
			if ( current_user_can( $cap ) ) { return $cap; }
		}
		return self::CAPS[0];
	}

	/** Occurrences (last 30 days and upcoming) of events reachable with $caps. */
	public static function occurrence_options( $caps ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$where = '1=1' . Queries::scope_sql( Scope::event_ids( $caps ), 'o.event_id' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT o.occurrence_id,o.starts_at,o.timezone,e.name FROM %i o INNER JOIN %i e ON e.event_id=o.event_id WHERE o.starts_at >= %s AND {$where} ORDER BY o.starts_at LIMIT 300", $p . 'wpcap_occurrences', $p . 'wpcap_events', gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where: integer scope only.
		$out = array();
		foreach ( $rows as $r ) { $out[ (int) $r->occurrence_id ] = $r->name . ' – ' . Scope::local( $r->starts_at, $r->timezone ); }
		return $out;
	}

	/**
	 * Permission and generation of a batch PDF.
	 *
	 * Without codes: event or order managers (no QR code, codes unchanged). With new codes (QR) only
	 * event managers/administrators of the event, behind the 2FA step-up of bulk actions and exports
	 * (audit R2 #3: the box office role could otherwise collect every valid code of an occurrence and
	 * invalidate all tickets sent to customers). By default only tickets whose code never reached a
	 * customer are reprinted (box office sales without e-mail); customer tickets need
	 * $options['include_delivered'] plus $options['confirm'] = their exact number, and their holders
	 * receive the new links by e-mail. At most Templates::batch_limit() tickets per job
	 * ($options['offset'] selects the part).
	 *
	 * @param array $options include_delivered (bool), confirm (int), offset (int, 0-based).
	 * @return array{pdf:string,filename:string,count?:int,total?:int,notified?:int}|\WP_Error
	 */
	public static function batch_request( $occurrence_id, $kind, $with_codes, array $options = array() ) {
		$event_id = Scope::occurrence_event( $occurrence_id );
		if ( ! in_array( $kind, Templates::KINDS, true ) ) { return Forms::error( 'wpcap_print_kind', __( 'Unbekanntes Layout.', 'pass-captain' ) ); }
		if ( ! $event_id || ! Scope::can_event( $event_id, array( 'wpcap_manage_events', 'wpcap_manage_orders' ) ) ) { return Forms::forbidden(); }
		if ( ! $with_codes ) {
			$pdf = Templates::batch_pdf( $occurrence_id, $kind );
			Audit::record( 'print_batch', 'occurrence', $occurrence_id, array( 'kind' => $kind, 'codes' => false ) );
			return array( 'pdf' => $pdf, 'filename' => $kind . '-' . $occurrence_id . '.pdf' );
		}
		// New ticket codes invalidate the old ones and hand every printed code to the operator.
		if ( ! Scope::can_event( $event_id, array( 'wpcap_manage_events' ) ) ) { return Forms::forbidden( __( 'Neue Ticketcodes für den Stapeldruck dürfen nur Personen mit Eventverwaltung erzeugen.', 'pass-captain' ) ); }
		$step_up = StepUp::check( array( 'bulk', 'exports' ), 'print_batch_codes' );
		if ( is_wp_error( $step_up ) ) { return $step_up; }
		$include_delivered = ! empty( $options['include_delivered'] );
		$offset = max( 0, (int) ( $options['offset'] ?? 0 ) );
		if ( $include_delivered ) {
			$customer = count( Templates::code_candidates( $occurrence_id )['delivered'] );
			if ( $customer && (int) ( $options['confirm'] ?? -1 ) !== $customer ) {
				return Forms::error(
					'wpcap_print_confirm_required',
					/* translators: %d: number of customer tickets */
					sprintf( _n( 'Bitte bestätigen: %d Kundenticket wird ungültig. Der Inhaber erhält den neuen Link per E-Mail. Gib die Zahl zur Bestätigung ein.', 'Bitte bestätigen: %d Kundentickets werden ungültig. Die Inhaber erhalten die neuen Links per E-Mail. Gib die Zahl zur Bestätigung ein.', $customer, 'pass-captain' ), $customer ),
					409
				);
			}
		}
		$dir = TempFiles::private_dir();
		if ( ! $dir ) { return Forms::error( 'wpcap_print_temp', __( 'Temporäre Datei konnte nicht angelegt werden.', 'pass-captain' ), 500 ); }
		$file = $dir . '/' . $kind . '-' . absint( $occurrence_id ) . '.pdf';
		TempFiles::track( $file, $dir );
		$result = Templates::batch_pdf_with_codes( $occurrence_id, $kind, $file, $include_delivered, $offset );
		$pdf = is_wp_error( $result ) ? '' : TempFiles::get( $file );
		TempFiles::remove_with_dir( $file, $dir );
		if ( is_wp_error( $result ) ) { return $result; }
		Audit::record( 'print_batch', 'occurrence', $occurrence_id, array( 'kind' => $kind, 'codes' => true, 'count' => $result['count'], 'customer_tickets' => $result['delivered'], 'offset' => $offset ) );
		$part = $offset || $result['total'] > $result['count'] ? '-' . ( $offset + 1 ) . '-' . ( $offset + $result['count'] ) : '';
		return array( 'pdf' => $pdf, 'filename' => $kind . '-' . $occurrence_id . $part . '.pdf' ) + $result;
	}

	public static function render() {
		if ( ! array_filter( self::CAPS, 'current_user_can' ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		$occurrences = self::occurrence_options( self::CAPS );
		$print_occ = self::occurrence_options( array( 'wpcap_manage_events', 'wpcap_manage_orders' ) );
		$labels = Templates::kind_labels();
		$edit = Templates::get_template( absint( $_GET['template_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $edit && ! Templates::can_edit( (int) $edit->owner_event_id, (int) $edit->template_id ) ) { $edit = null; }
		?>
		<div class="wrap wpcap-admin">
		<h1><?php esc_html_e( 'Druck: Tickets, Badges, Listen', 'pass-captain' ); ?></h1>
		<?php Forms::render_notices(); ?>

		<?php if ( $print_occ ) : ?>
		<h2><?php esc_html_e( 'Stapeldruck für einen Termin', 'pass-captain' ); ?></h2>
		<?php Forms::open_form( 'wpcap_print_batch', null, 'target="_blank"' ); ?>
		<p><label><?php esc_html_e( 'Termin', 'pass-captain' ); ?> <?php Forms::select( 'occurrence_id', $print_occ, '' ); ?></label>
		<label><?php esc_html_e( 'Layout', 'pass-captain' ); ?> <?php Forms::select( 'kind', $labels, 'badge' ); ?></label></p>
		<?php if ( current_user_can( 'wpcap_manage_events' ) ) : ?>
		<fieldset><legend><?php esc_html_e( 'QR-Codes (nur Eventverwaltung)', 'pass-captain' ); ?></legend>
		<p><label><input type="checkbox" name="with_codes" value="1"> <?php esc_html_e( 'Mit QR-Code drucken: erzeugt neue Ticketcodes. Standardmäßig nur für Kassentickets ohne E-Mail-Zustellung.', 'pass-captain' ); ?></label></p>
		<p><label><input type="checkbox" name="include_delivered" value="1"> <?php esc_html_e( 'Auch Kundentickets neu drucken: deren bisherige Codes werden ungültig, die Inhaber erhalten neue Links per E-Mail.', 'pass-captain' ); ?></label>
		<label><?php esc_html_e( 'Bestätigung (Anzahl Kundentickets)', 'pass-captain' ); ?> <input type="number" name="confirm" min="0" step="1" class="small-text"></label></p>
		<p><label><?php esc_html_e( 'Ab Ticket Nr.', 'pass-captain' ); ?> <input type="number" name="offset_from" min="1" step="1" value="1" class="small-text"></label>
		<span class="description"><?php echo esc_html( sprintf( /* translators: %d: maximum tickets per print job */ __( 'Höchstens %d Tickets je Druckauftrag; größere Termine in Teilen drucken.', 'pass-captain' ), Templates::batch_limit() ) ); ?></span></p>
		</fieldset>
		<?php endif; ?>
		<p class="description"><?php esc_html_e( 'Zeichen, die die PDF-Schrift nicht enthält (z. B. Kyrillisch, Chinesisch, Ł, ć), werden in lateinische Buchstaben umgeschrieben (Привет → Privet).', 'pass-captain' ); ?></p>
		<p><button class="button button-primary"><?php esc_html_e( 'PDF erzeugen', 'pass-captain' ); ?></button></p>
		</form>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Teilnehmerliste', 'pass-captain' ); ?></h2>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" target="_blank">
		<input type="hidden" name="action" value="wpcap_attendee_list"><?php wp_nonce_field( 'wpcap_attendee_list' ); ?>
		<p><?php Forms::select( 'occurrence_id', $occurrences, '', '', Forms::aria_label( __( 'Termin', 'pass-captain' ) ) ); ?>
		<button class="button" name="format" value="html"><?php esc_html_e( 'Druckansicht', 'pass-captain' ); ?></button>
		<button class="button" name="format" value="csv"><?php esc_html_e( 'CSV-Export', 'pass-captain' ); ?></button></p>
		<p class="description"><?php esc_html_e( 'Einlasspersonal erhält nur Name, Ticketart und Status; E-Mail-Adressen und Antworten sehen nur Personen mit Eventverwaltung.', 'pass-captain' ); ?></p>
		</form>

		<?php if ( current_user_can( 'wpcap_manage_events' ) ) : ?>
		<h2><?php esc_html_e( 'Vorlagen', 'pass-captain' ); ?></h2>
		<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Name', 'pass-captain' ); ?></th><th><?php esc_html_e( 'Layout', 'pass-captain' ); ?></th><th><?php esc_html_e( 'Gilt für', 'pass-captain' ); ?></th><th><span class="screen-reader-text"><?php esc_html_e( 'Aktionen', 'pass-captain' ); ?></span></th></tr></thead><tbody>
		<?php foreach ( Templates::all_templates() as $t ) : ?>
			<tr><td><?php echo esc_html( $t->name ); ?></td><td><?php echo esc_html( $labels[ $t->kind ] ?? $t->kind ); ?></td><td><?php echo esc_html( (int) $t->owner_event_id ? ( Queries::event( (int) $t->owner_event_id )->name ?? '#' . (int) $t->owner_event_id ) : __( 'Global', 'pass-captain' ) ); ?></td><td><?php if ( Templates::can_edit( (int) $t->owner_event_id, (int) $t->template_id ) ) : ?><a href="<?php echo esc_url( Forms::page_url( self::SLUG, array( 'template_id' => (int) $t->template_id ) ) ); ?>"><?php esc_html_e( 'Bearbeiten', 'pass-captain' ); ?></a><?php endif; ?></td></tr>
		<?php endforeach; ?>
		</tbody></table>
		<h3><?php echo esc_html( $edit ? __( 'Vorlage bearbeiten', 'pass-captain' ) : __( 'Neue Vorlage', 'pass-captain' ) ); ?></h3>
		<?php Forms::open_form( 'wpcap_print_save_template' ); Forms::hidden( 'template_id', $edit ? (int) $edit->template_id : 0 ); ?>
		<p><label><?php esc_html_e( 'Name', 'pass-captain' ); ?> <input type="text" name="name" required value="<?php echo esc_attr( $edit ? $edit->name : '' ); ?>"></label>
		<label><?php esc_html_e( 'Layout', 'pass-captain' ); ?> <?php Forms::select( 'kind', $labels, $edit ? $edit->kind : 'badge' ); ?></label>
		<?php if ( ! $edit ) : ?><label><?php esc_html_e( 'Gilt für', 'pass-captain' ); ?> <?php Forms::select( 'owner_event_id', ( current_user_can( 'manage_options' ) ? array( 0 => __( 'Alle Events (global)', 'pass-captain' ) ) : array() ) + Queries::event_options( Scope::event_ids( 'wpcap_manage_events' ) ), '' ); ?></label><?php endif; ?></p>
		<p><textarea name="body" rows="8" cols="70" class="code" aria-label="<?php esc_attr_e( 'Vorlagentext', 'pass-captain' ); ?>" aria-describedby="wpcap-print-body-help"><?php echo esc_html( $edit ? $edit->body : Templates::default_body( 'badge' ) ); ?></textarea></p>
		<p class="description" id="wpcap-print-body-help"><?php echo esc_html( __( 'Eine Zeile pro Element: „Größe|Text“, fett mit „*Größe|Text“, „{qr}“ allein für den QR-Code. Platzhalter:', 'pass-captain' ) . ' ' . implode( ' ', Templates::PLACEHOLDERS ) ); ?></p>
		<p><button class="button button-primary"><?php esc_html_e( 'Vorlage speichern', 'pass-captain' ); ?></button></p></form>

		<h3><?php esc_html_e( 'Vorlage pro Event', 'pass-captain' ); ?></h3>
		<?php Forms::open_form( 'wpcap_print_assign' ); ?>
		<p><?php Forms::select( 'event_id', Queries::event_options( Scope::event_ids( 'wpcap_manage_events' ) ), '', '', Forms::aria_label( __( 'Event', 'pass-captain' ) ) ); ?>
		<?php Forms::select( 'kind', $labels, 'ticket', '', Forms::aria_label( __( 'Layout', 'pass-captain' ) ) ); ?>
		<?php
		$options = array( 0 => __( 'Standardlayout', 'pass-captain' ) );
		foreach ( Templates::all_templates() as $t ) { $options[ (int) $t->template_id ] = $t->name . ' (' . ( $labels[ $t->kind ] ?? $t->kind ) . ')'; }
		Forms::select( 'template_id', $options, 0, '', Forms::aria_label( __( 'Vorlage', 'pass-captain' ) ) );
		?>
		<button class="button"><?php esc_html_e( 'Zuordnen', 'pass-captain' ); ?></button></p></form>
		<?php endif; ?>

		<?php if ( current_user_can( 'manage_options' ) ) : ?>
		<h2><?php esc_html_e( 'Rechnungs-PDF', 'pass-captain' ); ?></h2>
		<?php Forms::open_form( 'wpcap_print_settings' ); ?>
		<p><label><input type="checkbox" name="print_attach_invoice_pdf" value="1" <?php checked( (bool) Settings::get( 'print_attach_invoice_pdf' ) ); ?>> <?php esc_html_e( 'Rechnung als PDF an die Ticket-E-Mail anhängen (wenn eine Rechnung existiert oder automatische Rechnungen aktiv sind)', 'pass-captain' ); ?></label></p>
		<p class="description"><?php esc_html_e( 'Download im Backend: admin-post.php?action=wpcap_invoice_pdf&order_id=…; für Käufer über den persönlichen Bestelllink mit ?wpcap_invoice_pdf=<Bestell-ID>&wpcap_access=<Token>.', 'pass-captain' ); ?></p>
		<p><button class="button"><?php esc_html_e( 'Speichern', 'pass-captain' ); ?></button></p></form>
		<?php endif; ?>
		</div>
		<?php
	}

	public static function handle_save_template() {
		if ( ! current_user_can( 'wpcap_manage_events' ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		Forms::verify( 'wpcap_print_save_template' );
		$in = Forms::post();
		$result = Templates::save_template( Forms::int( $in, 'template_id' ), Forms::text( $in, 'name' ), Forms::choice( $in, 'kind', Templates::KINDS, 'ticket' ), is_scalar( $in['body'] ?? null ) ? (string) $in['body'] : '', Forms::int( $in, 'owner_event_id' ) );
		Forms::finish( $result, __( 'Vorlage gespeichert.', 'pass-captain' ), Forms::page_url( self::SLUG ) );
	}

	public static function handle_assign() {
		Forms::verify( 'wpcap_print_assign' );
		$in = Forms::post();
		$event_id = Forms::int( $in, 'event_id' );
		$result = Templates::assign( $event_id, Forms::choice( $in, 'kind', Templates::KINDS, 'ticket' ), Forms::int( $in, 'template_id' ) );
		Forms::finish( $result, __( 'Vorlage zugeordnet.', 'pass-captain' ), Forms::page_url( self::SLUG ) );
	}

	public static function handle_batch() {
		Forms::verify( 'wpcap_print_batch' );
		$in = Forms::post();
		$options = array(
			'include_delivered' => Forms::flag( $in, 'include_delivered' ),
			'confirm' => isset( $in['confirm'] ) && is_scalar( $in['confirm'] ) && '' !== trim( (string) $in['confirm'] ) ? Forms::int( $in, 'confirm' ) : -1,
			'offset' => max( 0, Forms::int( $in, 'offset_from' ) - 1 ),
		);
		$result = self::batch_request( Forms::int( $in, 'occurrence_id' ), Forms::choice( $in, 'kind', Templates::KINDS, 'ticket' ), Forms::flag( $in, 'with_codes' ), $options );
		if ( is_wp_error( $result ) ) {
			$data = (array) $result->get_error_data();
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => (int) ( $data['status'] ?? 403 ), 'back_link' => true ) );
		}
		Scope::send_download( $result['pdf'], $result['filename'], 'application/pdf' );
	}

	public static function handle_attendee_list() {
		check_admin_referer( 'wpcap_attendee_list' );
		$occurrence_id = absint( $_GET['occurrence_id'] ?? 0 );
		$level = AttendeeList::access_level( $occurrence_id );
		if ( '' === $level ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		$format = 'csv' === sanitize_key( wp_unslash( $_GET['format'] ?? '' ) ) ? 'csv' : 'html';
		Audit::record( 'attendee_list_' . $format, 'occurrence', $occurrence_id, array( 'level' => $level ) );
		if ( 'csv' === $format ) {
			Scope::send_download( Scope::csv( AttendeeList::rows( $occurrence_id, $level ) ), 'teilnehmer-' . $occurrence_id . '.csv', 'text/csv; charset=utf-8' );
		}
		nocache_headers();
		echo AttendeeList::html( $occurrence_id, $level ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in html().
		exit;
	}

	public static function handle_settings() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		Forms::verify( 'wpcap_print_settings' );
		Settings::update( array( 'print_attach_invoice_pdf' => Forms::flag( Forms::post(), 'print_attach_invoice_pdf' ) ? 1 : 0 ) );
		Forms::finish( true, __( 'Einstellungen gespeichert.', 'pass-captain' ), Forms::page_url( self::SLUG ) );
	}
}

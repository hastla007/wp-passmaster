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

	/**
	 * Scheduled occurrences of published events reachable with $caps: running/upcoming first (the
	 * first key is the default), then the last 30 days marked "vergangen" (Queries::occurrence_choices()).
	 *
	 * @param bool $include_past False for the box office (sales only for running/upcoming dates).
	 */
	public static function occurrence_options( $caps, $include_past = true ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$scope = Queries::scope_sql( Scope::event_ids( $caps ), 'o.event_id' ) . \PassCaptain\Access::occurrence_scope_sql( 'o' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT o.occurrence_id,o.starts_at,o.ends_at,o.timezone,o.status,e.status AS event_status,e.name FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE o.starts_at >= %s AND o.status='scheduled' AND e.status='published' $scope ORDER BY o.starts_at LIMIT 300", gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
		foreach ( $rows as $r ) { $r->label = $r->name . ' – ' . Scope::local( $r->starts_at, $r->timezone ); }
		return Queries::occurrence_choices( $rows, $include_past );
	}

	/**
	 * Permission and generation of a batch PDF. Without new codes and up to BatchJob::sync_limit()
	 * tickets the PDF is rendered at once ({pdf, filename}); otherwise a background batch is created
	 * ({batch, status}) whose file is downloaded from the print page when it is ready (A427). New codes
	 * are activated only by that download.
	 *
	 * Without codes: event or order managers (no QR code, codes unchanged); large batches run as a
	 * background job (BatchJob). With new codes (QR) only event managers/administrators of the event,
	 * behind the 2FA step-up of bulk actions and exports (audit R2 #3: the box office role could otherwise
	 * collect every valid code of an occurrence and invalidate all tickets sent to customers). With the
	 * batch tables (BatchJob::ready()) the job renders first and activates the new codes only with the
	 * first download (A427); tickets passed on to someone else keep their code. Without them the codes
	 * are committed together with the complete file (Templates::batch_pdf_with_codes()): by default only
	 * tickets whose code never reached a customer; customer tickets need $options['include_delivered']
	 * plus $options['confirm'] = their exact number, and their holders receive the new links by e-mail;
	 * at most Templates::batch_limit() tickets per job ($options['offset'] selects the part).
	 *
	 * @param array $options include_delivered (bool), confirm (int), offset (int, 0-based).
	 * @return array{pdf:string,filename:string,count?:int,total?:int,notified?:int}|array{batch:int,status:string}|\WP_Error
	 */
	public static function batch_request( $occurrence_id, $kind, $with_codes, array $options = array() ) {
		$event_id = Scope::occurrence_event( $occurrence_id );
		if ( ! in_array( $kind, Templates::KINDS, true ) ) { return Forms::error( 'wpcap_print_kind', __( 'Unbekanntes Layout.', 'pass-captain' ) ); }
		if ( ! $event_id || ! Scope::can_occurrence( $occurrence_id, array( 'wpcap_manage_events', 'wpcap_manage_orders' ) ) ) { return Forms::forbidden(); }
		if ( $with_codes ) {
			// New ticket codes invalidate the old ones and hand every printed code to the operator.
			if ( ! Scope::can_event( $event_id, array( 'wpcap_manage_events' ) ) ) { return Forms::forbidden( __( 'Neue Ticketcodes für den Stapeldruck dürfen nur Personen mit Eventverwaltung erzeugen.', 'pass-captain' ) ); }
			$step_up = StepUp::check( array( 'bulk', 'exports' ), 'print_batch_codes' );
			if ( is_wp_error( $step_up ) ) { return $step_up; }
		}
		if ( ! $with_codes && ( ! BatchJob::ready() || count( Templates::ticket_refs_for_occurrence( $occurrence_id, Templates::batch_statuses( $kind ) ) ) <= BatchJob::sync_limit() ) ) {
			$pdf = Templates::batch_pdf( $occurrence_id, $kind );
			Audit::record( 'print_batch', 'occurrence', $occurrence_id, array( 'kind' => $kind, 'codes' => false ) );
			return array( 'pdf' => $pdf, 'filename' => $kind . '-' . $occurrence_id . '.pdf' );
		}
		if ( BatchJob::ready() ) {
			$batch_id = BatchJob::create( $occurrence_id, $kind, $with_codes );
			if ( is_wp_error( $batch_id ) ) { return $batch_id; }
			$result = BatchJob::work( $batch_id, (float) apply_filters( 'wpcap_print_batch_inline_budget', 3.0 ) );
			return array( 'batch' => $batch_id, 'status' => 'done' === $result ? 'ready' : 'rendering' );
		}
		// Without the batch tables: new codes are committed together with the complete file.
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
		<?php Forms::open_form( 'wpcap_print_batch' ); ?>
		<p><label><?php esc_html_e( 'Termin', 'pass-captain' ); ?> <?php Forms::select( 'occurrence_id', $print_occ, '' ); ?></label>
		<label><?php esc_html_e( 'Layout', 'pass-captain' ); ?> <?php Forms::select( 'kind', $labels, 'badge' ); ?></label></p>
		<?php if ( current_user_can( 'wpcap_manage_events' ) ) : ?>
		<fieldset><legend><?php esc_html_e( 'QR-Codes (nur Eventverwaltung)', 'pass-captain' ); ?></legend>
		<?php if ( BatchJob::ready() ) : ?>
		<p><label><input type="checkbox" name="with_codes" value="1"> <?php esc_html_e( 'Mit QR-Code drucken: erzeugt neue Ticketcodes für die gültigen Tickets der Käufer (weitergegebene oder anderen Teilnehmenden zugeordnete Tickets werden ohne QR-Code gedruckt). Mit dem ersten Herunterladen werden bereits versendete oder gedruckte Codes dieser Tickets ungültig.', 'pass-captain' ); ?></label></p>
		<?php else : ?>
		<p><label><input type="checkbox" name="with_codes" value="1"> <?php esc_html_e( 'Mit QR-Code drucken: erzeugt neue Ticketcodes. Standardmäßig nur für Kassentickets ohne E-Mail-Zustellung.', 'pass-captain' ); ?></label></p>
		<p><label><input type="checkbox" name="include_delivered" value="1"> <?php esc_html_e( 'Auch Kundentickets neu drucken: deren bisherige Codes werden ungültig, die Inhaber erhalten neue Links per E-Mail.', 'pass-captain' ); ?></label>
		<label><?php esc_html_e( 'Bestätigung (Anzahl Kundentickets)', 'pass-captain' ); ?> <input type="number" name="confirm" min="0" step="1" class="small-text"></label></p>
		<p><label><?php esc_html_e( 'Ab Ticket Nr.', 'pass-captain' ); ?> <input type="number" name="offset_from" min="1" step="1" value="1" class="small-text"></label>
		<span class="description"><?php echo esc_html( sprintf( /* translators: %d: maximum tickets per print job */ __( 'Höchstens %d Tickets je Druckauftrag; größere Termine in Teilen drucken.', 'pass-captain' ), Templates::batch_limit() ) ); ?></span></p>
		<?php endif; ?>
		</fieldset>
		<?php endif; ?>
		<?php if ( ! PdfWriter::unicode_available() ) : ?><p class="description"><?php esc_html_e( 'Zeichen, die die PDF-Schrift nicht enthält (z. B. Kyrillisch, Chinesisch, Ł, ć), werden in lateinische Buchstaben umgeschrieben (Привет → Privet).', 'pass-captain' ); ?></p><?php endif; ?>
		<p><button class="button button-primary"><?php esc_html_e( 'PDF erzeugen', 'pass-captain' ); ?></button></p>
		</form>
		<?php self::render_batches(); ?>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Teilnehmerliste', 'pass-captain' ); ?></h2>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" target="_blank">
		<input type="hidden" name="action" value="wpcap_attendee_list"><?php wp_nonce_field( 'wpcap_attendee_list' ); ?>
		<p><?php Forms::select( 'occurrence_id', $occurrences, '', '', Forms::aria_label( __( 'Termin', 'pass-captain' ) ) ); ?>
		<button class="button" name="format" value="html"><?php esc_html_e( 'Druckansicht', 'pass-captain' ); ?></button>
		<button class="button" name="format" value="csv"><?php esc_html_e( 'CSV-Export', 'pass-captain' ); ?></button>
		<button class="button" name="format" value="noshow_html"><?php esc_html_e( 'No-Show-Liste', 'pass-captain' ); ?></button>
		<button class="button" name="format" value="noshow_csv"><?php esc_html_e( 'No-Shows als CSV', 'pass-captain' ); ?></button></p>
		<p class="description"><?php esc_html_e( 'Einlasspersonal erhält nur Name, Ticketart und Status; E-Mail-Adressen und Antworten sehen nur Personen mit Eventverwaltung.', 'pass-captain' ); ?> <?php esc_html_e( 'Die No-Show-Liste enthält gültige Tickets des Termins, die nie eingelassen wurden (widerrufene und erstattete Tickets nicht).', 'pass-captain' ); ?></p>
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
		<?php if ( ! $edit ) : ?><label><?php esc_html_e( 'Gilt für', 'pass-captain' ); ?> <?php Forms::select( 'owner_event_id', ( current_user_can( 'manage_options' ) ? array( 0 => __( 'Alle Events (global)', 'pass-captain' ) ) : array() ) + Queries::event_options( Scope::event_ids( 'wpcap_manage_events' ), array( absint( Forms::query_arg( 'event_id', 0 ) ) ) ), (string) absint( Forms::query_arg( 'event_id', 0 ) ) ); ?></label><?php echo Queries::event_options_note(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped note. ?><?php endif; ?></p>
		<p><textarea name="body" rows="8" cols="70" class="code" aria-label="<?php esc_attr_e( 'Vorlagentext', 'pass-captain' ); ?>" aria-describedby="wpcap-print-body-help"><?php echo esc_html( $edit ? $edit->body : Templates::default_body( 'badge' ) ); ?></textarea></p>
		<p class="description" id="wpcap-print-body-help"><?php echo esc_html( __( 'Eine Zeile pro Element: „Größe|Text“, fett mit „*Größe|Text“, „{qr}“ allein für den QR-Code. Platzhalter:', 'pass-captain' ) . ' ' . implode( ' ', Templates::PLACEHOLDERS ) ); ?></p>
		<p><button class="button button-primary"><?php esc_html_e( 'Vorlage speichern', 'pass-captain' ); ?></button></p></form>

		<h3><?php esc_html_e( 'Vorlage pro Event', 'pass-captain' ); ?></h3>
		<?php Forms::open_form( 'wpcap_print_assign' ); ?>
		<p><?php Forms::select( 'event_id', Queries::event_options( Scope::event_ids( 'wpcap_manage_events' ), array( absint( Forms::query_arg( 'event_id', 0 ) ) ) ), (string) absint( Forms::query_arg( 'event_id', 0 ) ), '', method_exists( Forms::class, 'aria_label' ) ? Forms::aria_label( __( 'Event', 'pass-captain' ) ) : '' ); ?><?php echo Queries::event_options_note(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped note. ?>
		<?php Forms::select( 'kind', $labels, 'ticket', '', method_exists( Forms::class, 'aria_label' ) ? Forms::aria_label( __( 'Layout', 'pass-captain' ) ) : '' ); ?>
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
		$result = Templates::save_template( Forms::int( $in, 'template_id' ), Forms::text( $in, 'name' ), Forms::choice( $in, 'kind', Templates::KINDS, 'ticket' ), is_scalar( $in['body'] ?? '' ) ? (string) ( $in['body'] ?? '' ) : '', Forms::int( $in, 'owner_event_id' ) );
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
			$data = $result->get_error_data();
			if ( is_array( $data ) && in_array( (int) ( $data['status'] ?? 0 ), array( 401, 403 ), true ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => (int) $data['status'], 'back_link' => true ) ); }
			Forms::finish( $result, '', Forms::page_url( self::SLUG ) );
		}
		if ( isset( $result['pdf'] ) ) { Scope::send_download( $result['pdf'], $result['filename'], 'application/pdf' ); }
		Forms::finish(
			true,
			'ready' === $result['status']
				? __( 'Die Druckdatei ist fertig und steht unten unter „Stapeldrucke“ zum Herunterladen bereit.', 'pass-captain' )
				: __( 'Die Druckdatei wird im Hintergrund erstellt; sie erscheint unten unter „Stapeldrucke“, sobald sie fertig ist.', 'pass-captain' ),
			Forms::page_url( self::SLUG ) . '#wpcap-print-batches'
		);
	}

	/** Download of a finished batch; the first download activates its new codes (BatchJob::deliver()). */
	public static function handle_batch_download() {
		$batch_id = absint( $_GET['batch'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified next.
		check_admin_referer( 'wpcap_print_batch_download_' . $batch_id );
		if ( ! BatchJob::can_access( BatchJob::get( $batch_id ) ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		$file = BatchJob::deliver( $batch_id );
		if ( is_wp_error( $file ) ) { Forms::finish( $file, '', Forms::page_url( self::SLUG ) ); }
		BatchJob::send( $file );
	}

	/** Cancels a batch that was not downloaded yet (no ticket changes). */
	public static function handle_batch_cancel() {
		Forms::verify( 'wpcap_print_batch_cancel' );
		$batch_id = Forms::int( Forms::post(), 'batch' );
		if ( ! BatchJob::can_access( BatchJob::get( $batch_id ) ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		Forms::finish( BatchJob::cancel( $batch_id ), __( 'Stapeldruck abgebrochen; an den Tickets wurde nichts geändert.', 'pass-captain' ), Forms::page_url( self::SLUG ) . '#wpcap-print-batches' );
	}

	/** List of recent background batches with progress, download and cancel. */
	private static function render_batches() {
		$batches = BatchJob::recent();
		if ( ! $batches ) { return; }
		$labels = Templates::kind_labels();
		$states = array(
			'rendering' => __( 'wird erstellt', 'pass-captain' ),
			'ready'     => __( 'fertig', 'pass-captain' ),
			'delivered' => __( 'heruntergeladen', 'pass-captain' ),
			'failed'    => __( 'fehlgeschlagen', 'pass-captain' ),
			'cancelled' => __( 'abgebrochen', 'pass-captain' ),
		);
		?>
		<h3 id="wpcap-print-batches"><?php esc_html_e( 'Stapeldrucke', 'pass-captain' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Große Stapel und Stapel mit neuen Ticketcodes werden im Hintergrund erstellt. Neue Codes gelten erst ab dem ersten Herunterladen; bis dahin bleiben die bisherigen Codes gültig. Abgebrochene oder nicht heruntergeladene Stapel ändern keine Tickets. Dateien werden nach einigen Tagen gelöscht.', 'pass-captain' ); ?></p>
		<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Termin', 'pass-captain' ); ?></th><th><?php esc_html_e( 'Layout', 'pass-captain' ); ?></th><th><?php esc_html_e( 'Tickets', 'pass-captain' ); ?></th><th><?php esc_html_e( 'Status', 'pass-captain' ); ?></th><th></th></tr></thead><tbody>
		<?php foreach ( $batches as $b ) : ?>
			<tr><td>#<?php echo (int) $b->occurrence_id; ?> · <?php echo esc_html( Scope::local( $b->created_at, 'UTC', 'd.m.Y H:i' ) ); ?></td>
			<td><?php echo esc_html( ( $labels[ $b->kind ] ?? $b->kind ) . ( (int) $b->with_codes ? ' · ' . __( 'neue Codes', 'pass-captain' ) : '' ) ); ?></td>
			<td><?php echo (int) $b->done . ' / ' . (int) $b->total; ?>
			<?php if ( (int) $b->held_by_other ) : ?><br><small>
				<?php
				/* translators: %d: number of tickets */
				echo esc_html( sprintf( _n( '%d weitergegebenes oder zugeordnetes Ticket ohne neuen Code (Inhaber behält den gültigen Code)', '%d weitergegebene oder zugeordnete Tickets ohne neuen Code (Inhaber behalten den gültigen Code)', (int) $b->held_by_other, 'pass-captain' ), (int) $b->held_by_other ) );
				?>
			</small><?php endif; ?>
			<?php if ( (int) $b->skipped ) : ?><br><small>
				<?php
				/* translators: %d: number of tickets */
				echo esc_html( sprintf( _n( '%d Ticket wurde seit dem Erstellen geändert; sein gedruckter Code gilt nicht.', '%d Tickets wurden seit dem Erstellen geändert; ihre gedruckten Codes gelten nicht.', (int) $b->skipped, 'pass-captain' ), (int) $b->skipped ) );
				?>
			</small><?php endif; ?></td>
			<td><?php echo esc_html( $states[ $b->status ] ?? $b->status ); ?></td>
			<td>
			<?php if ( in_array( $b->status, array( 'ready', 'delivered' ), true ) ) : ?>
				<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'wpcap_print_batch_download', 'batch' => (int) $b->batch_id ), admin_url( 'admin-post.php' ) ), 'wpcap_print_batch_download_' . (int) $b->batch_id ) ); ?>"><?php echo esc_html( 'ready' === $b->status && (int) $b->with_codes ? __( 'Herunterladen und Codes aktivieren', 'pass-captain' ) : __( 'Herunterladen', 'pass-captain' ) ); ?></a>
			<?php endif; ?>
			<?php if ( in_array( $b->status, array( 'rendering', 'ready' ), true ) ) : ?>
				<?php Forms::open_form( 'wpcap_print_batch_cancel', null, 'style="display:inline"' ); Forms::hidden( 'batch', (int) $b->batch_id ); ?>
				<button class="button"><?php esc_html_e( 'Abbrechen', 'pass-captain' ); ?></button></form>
			<?php endif; ?>
			</td></tr>
		<?php endforeach; ?>
		</tbody></table>
		<?php
	}

	public static function handle_attendee_list() {
		check_admin_referer( 'wpcap_attendee_list' );
		$occurrence_id = absint( $_GET['occurrence_id'] ?? 0 );
		$level = AttendeeList::access_level( $occurrence_id );
		if ( '' === $level ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		$format = sanitize_key( wp_unslash( $_GET['format'] ?? '' ) );
		if ( in_array( $format, array( 'noshow_html', 'noshow_csv' ), true ) ) {
			Audit::record( 'no_show_list_' . substr( $format, 7 ), 'occurrence', $occurrence_id, array( 'level' => $level ) );
			// A477: both lists stream block by block (no full list in memory).
			if ( 'noshow_csv' === $format ) {
				Scope::stream_csv( self::with_header( NoShowList::header( $level ), NoShowList::row_stream( $occurrence_id, $level ) ), 'no-shows-' . $occurrence_id . '.csv', AttendeeList::chunk_size() );
			}
			Scope::start_stream( '', 'text/html; charset=utf-8' );
			NoShowList::stream_html( $occurrence_id, $level );
			\PassCaptain\Http::end();
		}
		$format = 'csv' === $format ? 'csv' : 'html';
		Audit::record( 'attendee_list_' . $format, 'occurrence', $occurrence_id, array( 'level' => $level ) );
		if ( 'csv' === $format ) {
			Scope::stream_csv( self::with_header( AttendeeList::header( $occurrence_id, $level ), AttendeeList::row_stream( $occurrence_id, $level ) ), 'teilnehmer-' . $occurrence_id . '.csv', AttendeeList::chunk_size() );
		}
		Scope::start_stream( '', 'text/html; charset=utf-8' );
		AttendeeList::stream_html( $occurrence_id, $level );
		\PassCaptain\Http::end();
	}

	/**
	 * Header row followed by the rows of a stream.
	 *
	 * @return \Generator<int, array>
	 */
	public static function with_header( array $header, $rows ) {
		yield $header;
		foreach ( $rows as $row ) { yield $row; }
	}

	public static function handle_settings() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		Forms::verify( 'wpcap_print_settings' );
		Settings::update( array( 'print_attach_invoice_pdf' => Forms::flag( Forms::post(), 'print_attach_invoice_pdf' ) ? 1 : 0 ) );
		Forms::finish( true, __( 'Einstellungen gespeichert.', 'pass-captain' ), Forms::page_url( self::SLUG ) );
	}
}

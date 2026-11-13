<?php
/** Admin screen "Druck" (print templates, batch print, attendee lists) and its admin-post handlers. */
namespace PassCaptain\Printing;

use PassCaptain\Admin\Forms;
use PassCaptain\Admin\Queries;
use PassCaptain\Audit;
use PassCaptain\Reports\Scope;
use PassCaptain\Settings;

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
		$scope = Queries::scope_sql( Scope::event_ids( $caps ), 'o.event_id' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT o.occurrence_id,o.starts_at,o.ends_at,o.timezone,o.status,e.status AS event_status,e.name FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE o.starts_at >= %s AND o.status='scheduled' AND e.status='published' $scope ORDER BY o.starts_at LIMIT 300", gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
		foreach ( $rows as $r ) { $r->label = $r->name . ' – ' . Scope::local( $r->starts_at, $r->timezone ); }
		return Queries::occurrence_choices( $rows, $include_past );
	}

	/**
	 * Permission and generation of a batch PDF.
	 *
	 * @return array{pdf:string,filename:string}|\WP_Error
	 */
	public static function batch_request( $occurrence_id, $kind, $with_codes ) {
		$event_id = Scope::occurrence_event( $occurrence_id );
		if ( ! in_array( $kind, Templates::KINDS, true ) ) { return Forms::error( 'wpcap_print_kind', __( 'Unbekanntes Layout.', 'wp-pass-captain' ) ); }
		if ( ! $event_id || ! Scope::can_event( $event_id, array( 'wpcap_manage_events', 'wpcap_manage_orders' ) ) ) { return Forms::forbidden(); }
		// New ticket codes invalidate the old ones: only order managers may do that.
		if ( $with_codes && ! Scope::can_event( $event_id, array( 'wpcap_manage_orders' ) ) ) { return Forms::forbidden( __( 'Neue Ticketcodes dürfen nur Personen mit Bestellverwaltung erzeugen.', 'wp-pass-captain' ) ); }
		$pdf = Templates::batch_pdf( $occurrence_id, $kind, $with_codes );
		Audit::record( 'print_batch', 'occurrence', $occurrence_id, array( 'kind' => $kind, 'codes' => (bool) $with_codes ) );
		return array( 'pdf' => $pdf, 'filename' => $kind . '-' . $occurrence_id . '.pdf' );
	}

	public static function render() {
		if ( ! array_filter( self::CAPS, 'current_user_can' ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 ); }
		$occurrences = self::occurrence_options( self::CAPS );
		$print_occ = self::occurrence_options( array( 'wpcap_manage_events', 'wpcap_manage_orders' ) );
		$labels = Templates::kind_labels();
		$edit = Templates::get_template( absint( $_GET['template_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $edit && ! Templates::can_edit( (int) $edit->owner_event_id, (int) $edit->template_id ) ) { $edit = null; }
		?>
		<div class="wrap wpcap-admin">
		<h1><?php esc_html_e( 'Druck: Tickets, Badges, Listen', 'wp-pass-captain' ); ?></h1>
		<?php Forms::render_notices(); ?>

		<?php if ( $print_occ ) : ?>
		<h2><?php esc_html_e( 'Stapeldruck für einen Termin', 'wp-pass-captain' ); ?></h2>
		<?php Forms::open_form( 'wpcap_print_batch', null, 'target="_blank"' ); ?>
		<p><label><?php esc_html_e( 'Termin', 'wp-pass-captain' ); ?> <?php Forms::select( 'occurrence_id', $print_occ, '' ); ?></label>
		<label><?php esc_html_e( 'Layout', 'wp-pass-captain' ); ?> <?php Forms::select( 'kind', $labels, 'badge' ); ?></label></p>
		<p><label><input type="checkbox" name="with_codes" value="1"> <?php esc_html_e( 'Mit QR-Code drucken: erzeugt neue Ticketcodes für alle gültigen Tickets. Bereits versendete oder gedruckte Codes werden ungültig.', 'wp-pass-captain' ); ?></label></p>
		<p><button class="button button-primary"><?php esc_html_e( 'PDF erzeugen', 'wp-pass-captain' ); ?></button></p>
		</form>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Teilnehmerliste', 'wp-pass-captain' ); ?></h2>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" target="_blank">
		<input type="hidden" name="action" value="wpcap_attendee_list"><?php wp_nonce_field( 'wpcap_attendee_list' ); ?>
		<p><?php Forms::select( 'occurrence_id', $occurrences, '' ); ?>
		<button class="button" name="format" value="html"><?php esc_html_e( 'Druckansicht', 'wp-pass-captain' ); ?></button>
		<button class="button" name="format" value="csv"><?php esc_html_e( 'CSV-Export', 'wp-pass-captain' ); ?></button></p>
		<p class="description"><?php esc_html_e( 'Einlasspersonal erhält nur Name, Ticketart und Status; E-Mail-Adressen und Antworten sehen nur Personen mit Eventverwaltung.', 'wp-pass-captain' ); ?></p>
		</form>

		<?php if ( current_user_can( 'wpcap_manage_events' ) ) : ?>
		<h2><?php esc_html_e( 'Vorlagen', 'wp-pass-captain' ); ?></h2>
		<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Name', 'wp-pass-captain' ); ?></th><th><?php esc_html_e( 'Layout', 'wp-pass-captain' ); ?></th><th><?php esc_html_e( 'Gilt für', 'wp-pass-captain' ); ?></th><th></th></tr></thead><tbody>
		<?php foreach ( Templates::all_templates() as $t ) : ?>
			<tr><td><?php echo esc_html( $t->name ); ?></td><td><?php echo esc_html( $labels[ $t->kind ] ?? $t->kind ); ?></td><td><?php echo esc_html( (int) $t->owner_event_id ? ( Queries::event( (int) $t->owner_event_id )->name ?? '#' . (int) $t->owner_event_id ) : __( 'Global', 'wp-pass-captain' ) ); ?></td><td><?php if ( Templates::can_edit( (int) $t->owner_event_id, (int) $t->template_id ) ) : ?><a href="<?php echo esc_url( Forms::page_url( self::SLUG, array( 'template_id' => (int) $t->template_id ) ) ); ?>"><?php esc_html_e( 'Bearbeiten', 'wp-pass-captain' ); ?></a><?php endif; ?></td></tr>
		<?php endforeach; ?>
		</tbody></table>
		<h3><?php echo esc_html( $edit ? __( 'Vorlage bearbeiten', 'wp-pass-captain' ) : __( 'Neue Vorlage', 'wp-pass-captain' ) ); ?></h3>
		<?php Forms::open_form( 'wpcap_print_save_template' ); Forms::hidden( 'template_id', $edit ? (int) $edit->template_id : 0 ); ?>
		<p><label><?php esc_html_e( 'Name', 'wp-pass-captain' ); ?> <input type="text" name="name" required value="<?php echo esc_attr( $edit ? $edit->name : '' ); ?>"></label>
		<label><?php esc_html_e( 'Layout', 'wp-pass-captain' ); ?> <?php Forms::select( 'kind', $labels, $edit ? $edit->kind : 'badge' ); ?></label>
		<?php if ( ! $edit ) : ?><label><?php esc_html_e( 'Gilt für', 'wp-pass-captain' ); ?> <?php Forms::select( 'owner_event_id', ( current_user_can( 'manage_options' ) ? array( 0 => __( 'Alle Events (global)', 'wp-pass-captain' ) ) : array() ) + Queries::event_options( Scope::event_ids( 'wpcap_manage_events' ) ), '' ); ?></label><?php endif; ?></p>
		<p><textarea name="body" rows="8" cols="70" class="code"><?php echo esc_html( $edit ? $edit->body : Templates::default_body( 'badge' ) ); ?></textarea></p>
		<p class="description"><?php echo esc_html( __( 'Eine Zeile pro Element: „Größe|Text“, fett mit „*Größe|Text“, „{qr}“ allein für den QR-Code. Platzhalter:', 'wp-pass-captain' ) . ' ' . implode( ' ', Templates::PLACEHOLDERS ) ); ?></p>
		<p><button class="button button-primary"><?php esc_html_e( 'Vorlage speichern', 'wp-pass-captain' ); ?></button></p></form>

		<h3><?php esc_html_e( 'Vorlage pro Event', 'wp-pass-captain' ); ?></h3>
		<?php Forms::open_form( 'wpcap_print_assign' ); ?>
		<p><?php Forms::select( 'event_id', Queries::event_options( Scope::event_ids( 'wpcap_manage_events' ) ), '' ); ?>
		<?php Forms::select( 'kind', $labels, 'ticket' ); ?>
		<?php
		$options = array( 0 => __( 'Standardlayout', 'wp-pass-captain' ) );
		foreach ( Templates::all_templates() as $t ) { $options[ (int) $t->template_id ] = $t->name . ' (' . ( $labels[ $t->kind ] ?? $t->kind ) . ')'; }
		Forms::select( 'template_id', $options, 0 );
		?>
		<button class="button"><?php esc_html_e( 'Zuordnen', 'wp-pass-captain' ); ?></button></p></form>
		<?php endif; ?>

		<?php if ( current_user_can( 'manage_options' ) ) : ?>
		<h2><?php esc_html_e( 'Rechnungs-PDF', 'wp-pass-captain' ); ?></h2>
		<?php Forms::open_form( 'wpcap_print_settings' ); ?>
		<p><label><input type="checkbox" name="print_attach_invoice_pdf" value="1" <?php checked( (bool) Settings::get( 'print_attach_invoice_pdf' ) ); ?>> <?php esc_html_e( 'Rechnung als PDF an die Ticket-E-Mail anhängen (wenn eine Rechnung existiert oder automatische Rechnungen aktiv sind)', 'wp-pass-captain' ); ?></label></p>
		<p class="description"><?php esc_html_e( 'Download im Backend: admin-post.php?action=wpcap_invoice_pdf&order_id=…; für Käufer über den persönlichen Bestelllink mit ?wpcap_invoice_pdf=<Bestell-ID>&wpcap_access=<Token>.', 'wp-pass-captain' ); ?></p>
		<p><button class="button"><?php esc_html_e( 'Speichern', 'wp-pass-captain' ); ?></button></p></form>
		<?php endif; ?>
		</div>
		<?php
	}

	public static function handle_save_template() {
		if ( ! current_user_can( 'wpcap_manage_events' ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 ); }
		Forms::verify( 'wpcap_print_save_template' );
		$in = Forms::post();
		$result = Templates::save_template( Forms::int( $in, 'template_id' ), Forms::text( $in, 'name' ), Forms::choice( $in, 'kind', Templates::KINDS, 'ticket' ), is_scalar( $in['body'] ?? '' ) ? (string) $in['body'] : '', Forms::int( $in, 'owner_event_id' ) );
		Forms::finish( $result, __( 'Vorlage gespeichert.', 'wp-pass-captain' ), Forms::page_url( self::SLUG ) );
	}

	public static function handle_assign() {
		Forms::verify( 'wpcap_print_assign' );
		$in = Forms::post();
		$event_id = Forms::int( $in, 'event_id' );
		$result = Templates::assign( $event_id, Forms::choice( $in, 'kind', Templates::KINDS, 'ticket' ), Forms::int( $in, 'template_id' ) );
		Forms::finish( $result, __( 'Vorlage zugeordnet.', 'wp-pass-captain' ), Forms::page_url( self::SLUG ) );
	}

	public static function handle_batch() {
		Forms::verify( 'wpcap_print_batch' );
		$in = Forms::post();
		$result = self::batch_request( Forms::int( $in, 'occurrence_id' ), Forms::choice( $in, 'kind', Templates::KINDS, 'ticket' ), Forms::flag( $in, 'with_codes' ) );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), 403 ); }
		Scope::send_download( $result['pdf'], $result['filename'], 'application/pdf' );
	}

	public static function handle_attendee_list() {
		check_admin_referer( 'wpcap_attendee_list' );
		$occurrence_id = absint( $_GET['occurrence_id'] ?? 0 );
		$level = AttendeeList::access_level( $occurrence_id );
		if ( '' === $level ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 ); }
		$format = 'csv' === ( $_GET['format'] ?? '' ) ? 'csv' : 'html';
		Audit::record( 'attendee_list_' . $format, 'occurrence', $occurrence_id, array( 'level' => $level ) );
		if ( 'csv' === $format ) {
			Scope::send_download( Scope::csv( AttendeeList::rows( $occurrence_id, $level ) ), 'teilnehmer-' . $occurrence_id . '.csv', 'text/csv; charset=utf-8' );
		}
		nocache_headers();
		echo AttendeeList::html( $occurrence_id, $level ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in html().
		exit;
	}

	public static function handle_settings() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 ); }
		Forms::verify( 'wpcap_print_settings' );
		Settings::update( array( 'print_attach_invoice_pdf' => Forms::flag( Forms::post(), 'print_attach_invoice_pdf' ) ? 1 : 0 ) );
		Forms::finish( true, __( 'Einstellungen gespeichert.', 'wp-pass-captain' ), Forms::page_url( self::SLUG ) );
	}
}

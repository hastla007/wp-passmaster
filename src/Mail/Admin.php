<?php
/**
 * Admin page "E-Mail-Vorlagen" (module MailTemplates): overview, editor per type and locale,
 * placeholder reference, live preview, test mail, reset, layout settings.
 *
 * All actions require manage_options and the nonce `wpcap_mail_templates`. Subjects are stored as
 * plain text, text bodies via sanitize_textarea_field and HTML bodies via wp_kses_post.
 */
namespace PassCaptain\Mail;

use PassCaptain\Admin\Forms;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Admin {
	const CAP = 'manage_options';
	const PAGE = 'wpcap-mail-templates';
	const NONCE = 'wpcap_mail_templates';
	const ACTION_SAVE = 'wpcap_mail_template_save';
	const ACTION_RESET = 'wpcap_mail_template_reset';
	const ACTION_TEST = 'wpcap_mail_template_test';
	const ACTION_LAYOUT = 'wpcap_mail_layout_save';
	const ACTION_PREVIEW = 'wpcap_mail_template_preview';
	const MAX_BODY = 20000;

	/* ------------------------------------------------------------------ Logic (testable) */

	/** Locales offered in the editor: site default, installed languages, locales with overrides. */
	public static function locales() {
		$locales = array( Mailer::site_locale() );
		if ( function_exists( 'get_available_languages' ) ) { $locales = array_merge( $locales, (array) get_available_languages() ); }
		$locales[] = 'en_US';
		foreach ( Templates::all() as $per_locale ) { $locales = array_merge( $locales, array_keys( (array) $per_locale ) ); }
		return array_values( array_unique( array_filter( array_map( array( Mailer::class, 'normalize_locale' ), $locales ) ) ) );
	}

	/**
	 * Sanitised template from form input.
	 *
	 * @return array|\WP_Error type, locale, subject, body, format.
	 */
	public static function draft( array $in ) {
		$type = sanitize_key( Forms::text( $in, 'type', 64 ) );
		if ( ! Mailer::type( $type ) ) {
			return Forms::error( 'wpcap_mail_type', __( 'Unbekannter E-Mail-Typ.', 'wp-pass-captain' ), 404 );
		}
		$locale = Mailer::normalize_locale( Forms::text( $in, 'locale', 20 ) );
		$format = Forms::choice( $in, 'format', array( 'text', 'html' ), 'text' );
		$raw = isset( $in['body'] ) && is_scalar( $in['body'] ) ? mb_substr( (string) $in['body'], 0, self::MAX_BODY ) : '';
		return array(
			'type' => $type,
			'locale' => '' !== $locale ? $locale : Mailer::site_locale(),
			'subject' => Forms::text( $in, 'subject', 250 ),
			'body' => 'html' === $format ? wp_kses_post( $raw ) : sanitize_textarea_field( $raw ),
			'format' => $format,
		);
	}

	/** Saves an override. */
	public static function save( array $in ) {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		$d = self::draft( $in );
		if ( is_wp_error( $d ) ) { return $d; }
		if ( '' === trim( $d['subject'] ) || '' === trim( $d['body'] ) ) {
			return Forms::error( 'wpcap_mail_template_empty', __( 'Bitte Betreff und Text angeben.', 'wp-pass-captain' ) );
		}
		Templates::save( $d['type'], $d['locale'], $d );
		return array( 'saved' => $d );
	}

	/** Deletes the override of a type and locale. */
	public static function reset( array $in ) {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		$d = self::draft( $in );
		if ( is_wp_error( $d ) ) { return $d; }
		Templates::reset( $d['type'], $d['locale'] );
		return array( 'reset' => $d['type'], 'locale' => $d['locale'] );
	}

	/**
	 * Renders the submitted (unsaved) template with the type's sample data.
	 *
	 * @return array|\WP_Error subject, html (full document for the preview frame), text, format, message.
	 */
	public static function preview( array $in ) {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		$d = self::draft( $in );
		if ( is_wp_error( $d ) ) { return $d; }
		$def = Mailer::type( $d['type'] );
		return Mailer::with_locale( $d['locale'], static function () use ( $d, $def ) {
			$message = Mailer::render_template( $d['type'], $d, (array) call_user_func( $def['sample'] ), $d['locale'] );
			$prepared = Mailer::prepare( '', $message );
			$html = 'html' === $message['format']
				? $prepared['body']
				: '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:16px;background:#fff"><pre style="white-space:pre-wrap;word-break:break-word;font:14px/1.5 Menlo,Consolas,monospace;margin:0">' . esc_html( $message['body'] ) . '</pre></body></html>';
			return array(
				'subject' => $message['subject'],
				'html' => $html,
				'text' => null !== $prepared['alt_body'] ? $prepared['alt_body'] : $message['body'],
				'format' => $message['format'],
				'message' => $message,
			);
		} );
	}

	/** Sends the submitted template with sample data to the current user. */
	public static function send_test( array $in ) {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		$user = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
		$to = (string) apply_filters( 'wpcap_mail_test_recipient', $user && ! empty( $user->user_email ) ? sanitize_email( $user->user_email ) : '', $user );
		if ( ! is_email( $to ) ) {
			return Forms::error( 'wpcap_mail_test_recipient', __( 'Dein Benutzerkonto hat keine gültige E-Mail-Adresse.', 'wp-pass-captain' ) );
		}
		$preview = self::preview( $in );
		if ( is_wp_error( $preview ) ) { return $preview; }
		$message = $preview['message'];
		$message['subject'] = '[' . __( 'Test', 'wp-pass-captain' ) . '] ' . $message['subject'];
		if ( ! Mailer::deliver( $to, $message ) ) {
			return Forms::error( 'wpcap_mail_test_failed', __( 'Die Testmail konnte nicht gesendet werden.', 'wp-pass-captain' ), 502 );
		}
		return array( 'sent' => true, 'to' => $to );
	}

	/** Saves logo, accent colour and footer of the HTML layout. */
	public static function save_layout( array $in ) {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		$logo = Forms::text( $in, 'logo_url', 500 );
		$logo = '' !== $logo && preg_match( '#^https?://#i', $logo ) ? esc_url_raw( $logo ) : '';
		$values = array(
			'logo_url' => $logo,
			'color' => Layout::sanitize_color( Forms::text( $in, 'color', 7 ) ),
			'footer' => Forms::textarea( $in, 'footer', 1000 ),
		);
		Settings::update( array( 'mail_layout' => $values ) );
		return array( 'saved' => $values );
	}

	/* ------------------------------------------------------------------ Request handlers */

	public static function handle_save() {
		Forms::verify( self::NONCE );
		$in = Forms::post();
		Forms::finish( self::save( $in ), __( 'Vorlage gespeichert.', 'wp-pass-captain' ), self::edit_url( $in ) );
	}

	public static function handle_reset() {
		Forms::verify( self::NONCE );
		$in = Forms::post();
		Forms::finish( self::reset( $in ), __( 'Vorlage auf den Standard zurückgesetzt.', 'wp-pass-captain' ), self::edit_url( $in ) );
	}

	public static function handle_test() {
		Forms::verify( self::NONCE );
		$in = Forms::post();
		$result = self::send_test( $in );
		/* translators: %s: e-mail address */
		Forms::finish( $result, is_array( $result ) ? sprintf( __( 'Testmail an %s gesendet.', 'wp-pass-captain' ), $result['to'] ) : '', self::edit_url( $in ) );
	}

	public static function handle_layout() {
		Forms::verify( self::NONCE );
		Forms::finish( self::save_layout( Forms::post() ), __( 'Layout gespeichert.', 'wp-pass-captain' ), Forms::page_url( self::PAGE ) );
	}

	/** admin-ajax: live preview as JSON. */
	public static function handle_preview_ajax() {
		check_ajax_referer( self::NONCE );
		$result = self::preview( Forms::post() );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			wp_send_json_error( array( 'message' => $result->get_error_message() ), is_array( $data ) ? (int) ( $data['status'] ?? 400 ) : 400 );
		}
		unset( $result['message'] );
		wp_send_json_success( $result );
	}

	private static function edit_url( array $in ) {
		$type = sanitize_key( Forms::text( $in, 'type', 64 ) );
		$args = Mailer::type( $type ) ? array( 'type' => $type, 'locale' => Mailer::normalize_locale( Forms::text( $in, 'locale', 20 ) ) ) : array();
		return Forms::page_url( self::PAGE, array_filter( $args ) );
	}

	/* ------------------------------------------------------------------ Rendering */

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 ); }
		$type = sanitize_key( (string) Forms::query_arg( 'type' ) );
		echo '<div class="wrap wpcap-admin">';
		if ( '' !== $type && Mailer::type( $type ) ) {
			self::render_editor( $type, Mailer::normalize_locale( (string) Forms::query_arg( 'locale' ) ) ?: Mailer::site_locale() );
		} else {
			self::render_overview();
		}
		echo '</div>';
	}

	private static function render_overview() {
		echo '<h1>' . esc_html__( 'E-Mail-Vorlagen', 'wp-pass-captain' ) . '</h1>';
		Forms::render_notices();
		echo '<p>' . esc_html__( 'Betreff und Text der E-Mails an Kund:innen anpassen – je Sprache. Ohne eigene Vorlage wird der eingebaute Text in der Sprache der Bestellung gesendet.', 'wp-pass-captain' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'E-Mail', 'wp-pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Beschreibung', 'wp-pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Eigene Vorlagen', 'wp-pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( Mailer::types() as $key => $def ) {
			$custom = Templates::locales( $key );
			echo '<tr><td><a href="' . esc_url( Forms::page_url( self::PAGE, array( 'type' => $key ) ) ) . '"><strong>' . esc_html( $def['label'] ) . '</strong></a></td><td>' . esc_html( $def['description'] ) . '</td><td>' . ( $custom ? esc_html( implode( ', ', $custom ) ) : '<span class="description">' . esc_html__( 'Standard', 'wp-pass-captain' ) . '</span>' ) . '</td></tr>';
		}
		if ( class_exists( '\\PassCaptain\\Modules\\Reminders' ) ) {
			echo '<tr><td><a href="' . esc_url( Forms::page_url( \PassCaptain\Modules\Reminders::PAGE ) ) . '"><strong>' . esc_html__( 'Erinnerung', 'wp-pass-captain' ) . '</strong></a></td><td colspan="2">' . esc_html__( 'Wird unter „Erinnerungen“ bearbeitet.', 'wp-pass-captain' ) . '</td></tr>';
		}
		echo '</tbody></table>';

		$layout = Layout::settings();
		echo '<h2>' . esc_html__( 'HTML-Layout', 'wp-pass-captain' ) . '</h2><p>' . esc_html__( 'Gilt für Vorlagen im HTML-Modus. Eine Textversion wird automatisch mitgesendet.', 'wp-pass-captain' ) . '</p>';
		Forms::open_form( self::ACTION_LAYOUT, self::NONCE );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th scope="row"><label for="wpcap-mail-logo">' . esc_html__( 'Logo-URL', 'wp-pass-captain' ) . '</label></th><td><input type="url" id="wpcap-mail-logo" name="logo_url" class="large-text" value="' . esc_attr( $layout['logo_url'] ) . '"><p class="description">' . esc_html__( 'Leer: Logo des Themes bzw. Name der Website.', 'wp-pass-captain' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="wpcap-mail-color">' . esc_html__( 'Akzentfarbe', 'wp-pass-captain' ) . '</label></th><td><input type="color" id="wpcap-mail-color" name="color" value="' . esc_attr( $layout['color'] ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="wpcap-mail-footer">' . esc_html__( 'Fußzeile', 'wp-pass-captain' ) . '</label></th><td><textarea id="wpcap-mail-footer" name="footer" class="large-text" rows="3">' . esc_textarea( $layout['footer'] ) . '</textarea><p class="description">' . esc_html__( 'Z. B. Impressum oder Kontakt. Leer: Name der Website.', 'wp-pass-captain' ) . '</p></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Layout speichern', 'wp-pass-captain' ) );
		echo '</form>';
	}

	private static function render_editor( $type, $locale ) {
		$def = Mailer::type( $type );
		$own = Templates::get( $type, $locale );
		$effective = Mailer::with_locale( $locale, static function () use ( $type, $locale ) { return Templates::resolve( $type, $locale ); } );
		$template = $own ? $own : $effective;
		echo '<h1>' . esc_html( $def['label'] ) . '</h1>';
		echo '<p><a href="' . esc_url( Forms::page_url( self::PAGE ) ) . '">&larr; ' . esc_html__( 'Alle E-Mail-Vorlagen', 'wp-pass-captain' ) . '</a></p>';
		Forms::render_notices();
		echo '<p>' . esc_html( $def['description'] ) . '</p>';

		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Sprache', 'wp-pass-captain' ) . '">';
		foreach ( self::locales() as $code ) {
			$label = $code . ( Mailer::site_locale() === $code ? ' (' . __( 'Standard', 'wp-pass-captain' ) . ')' : '' ) . ( Templates::get( $type, $code ) ? ' ✓' : '' );
			echo '<a class="nav-tab' . ( $code === $locale ? ' nav-tab-active' : '' ) . '"' . ( $code === $locale ? ' aria-current="page"' : '' ) . ' href="' . esc_url( Forms::page_url( self::PAGE, array( 'type' => $type, 'locale' => $code ) ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		if ( $own ) {
			$status = __( 'Eigene Vorlage für diese Sprache.', 'wp-pass-captain' );
		} elseif ( 'custom' === $effective['source'] ) {
			/* translators: %s: locale */
			$status = sprintf( __( 'Keine eigene Vorlage für diese Sprache – es gilt die Vorlage der Standardsprache (%s).', 'wp-pass-captain' ), $effective['locale'] );
		} else {
			$status = __( 'Eingebaute Standardvorlage (übersetzt in die Sprache der Empfänger:in).', 'wp-pass-captain' );
		}
		echo '<p><em>' . esc_html( $status ) . '</em></p>';

		echo '<div style="display:flex;flex-wrap:wrap;gap:24px;align-items:flex-start">';
		echo '<div style="flex:1 1 420px;min-width:300px">';
		Forms::open_form( self::ACTION_SAVE, self::NONCE, 'id="wpcap-mail-form"' );
		Forms::hidden( 'type', $type );
		Forms::hidden( 'locale', $locale );
		echo '<p><label for="wpcap-mail-subject"><strong>' . esc_html__( 'Betreff', 'wp-pass-captain' ) . '</strong></label><br><input type="text" id="wpcap-mail-subject" name="subject" class="large-text" maxlength="250" value="' . esc_attr( $template['subject'] ) . '"></p>';
		echo '<p><label for="wpcap-mail-format"><strong>' . esc_html__( 'Format', 'wp-pass-captain' ) . '</strong></label><br>';
		Forms::select( 'format', array( 'text' => __( 'Nur Text', 'wp-pass-captain' ), 'html' => __( 'HTML (mit Layout und automatischer Textversion)', 'wp-pass-captain' ) ), $template['format'], 'wpcap-mail-format' );
		echo '</p>';
		echo '<p><label for="wpcap-mail-body"><strong>' . esc_html__( 'Text', 'wp-pass-captain' ) . '</strong></label><br><textarea id="wpcap-mail-body" name="body" class="large-text code" rows="16">' . esc_textarea( $template['body'] ) . '</textarea></p>';
		echo '<p class="description">' . esc_html__( 'Im HTML-Modus sind die üblichen Beitrags-Tags erlaubt (Absätze, Links, Listen, Fettdruck …); Text ohne Tags wird automatisch umbrochen. Platzhalterwerte werden immer maskiert.', 'wp-pass-captain' ) . '</p>';
		echo '<p class="submit">';
		submit_button( __( 'Speichern', 'wp-pass-captain' ), 'primary', 'submit', false );
		echo ' <button type="submit" class="button" name="action" value="' . esc_attr( self::ACTION_TEST ) . '">' . esc_html__( 'Testmail senden', 'wp-pass-captain' ) . '</button>';
		if ( $own ) {
			echo ' <button type="submit" class="button button-link-delete" name="action" value="' . esc_attr( self::ACTION_RESET ) . '" onclick="return confirm(' . esc_attr( wp_json_encode( __( 'Eigene Vorlage für diese Sprache löschen?', 'wp-pass-captain' ) ) ) . ')">' . esc_html__( 'Auf Standard zurücksetzen', 'wp-pass-captain' ) . '</button>';
		}
		echo '</p><p class="description">' . esc_html__( 'Die Testmail nutzt den aktuellen Formularinhalt mit Beispieldaten und geht an deine eigene Adresse.', 'wp-pass-captain' ) . '</p>';
		echo '</form>';

		echo '<h2>' . esc_html__( 'Platzhalter', 'wp-pass-captain' ) . '</h2><table class="widefat striped"><tbody>';
		foreach ( Types::global_placeholders() + (array) $def['placeholders'] as $key => $label ) {
			echo '<tr><td><code>{' . esc_html( $key ) . '}</code></td><td>' . esc_html( $label ) . '</td></tr>';
		}
		echo '</tbody></table><p class="description">' . esc_html__( 'Unbekannte Platzhalter werden durch einen leeren Text ersetzt.', 'wp-pass-captain' ) . '</p>';
		echo '</div>';

		$preview = self::preview( array( 'type' => $type, 'locale' => $locale, 'subject' => $template['subject'], 'body' => $template['body'], 'format' => $template['format'] ) );
		echo '<div style="flex:1 1 420px;min-width:300px"><h2>' . esc_html__( 'Vorschau (Beispieldaten)', 'wp-pass-captain' ) . '</h2>';
		echo '<p><strong>' . esc_html__( 'Betreff:', 'wp-pass-captain' ) . '</strong> <span id="wpcap-mail-preview-subject">' . esc_html( is_array( $preview ) ? $preview['subject'] : '' ) . '</span></p>';
		echo '<iframe id="wpcap-mail-preview" title="' . esc_attr__( 'Vorschau der E-Mail', 'wp-pass-captain' ) . '" sandbox="" style="width:100%;height:600px;border:1px solid #c3c4c7;background:#fff" srcdoc="' . esc_attr( is_array( $preview ) ? $preview['html'] : '' ) . '"></iframe>';
		echo '</div></div>';
		self::print_preview_script();
	}

	/** Debounced live preview through admin-ajax (uses the form's own nonce). */
	private static function print_preview_script() {
		$js = "(function(){var f=document.getElementById('wpcap-mail-form'),fr=document.getElementById('wpcap-mail-preview'),sj=document.getElementById('wpcap-mail-preview-subject'),t;if(!f||!fr||!window.fetch||typeof ajaxurl==='undefined'){return;}"
			. "function up(){var d=new FormData(f);d.set('action'," . wp_json_encode( self::ACTION_PREVIEW ) . ");fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:d}).then(function(r){return r.json();}).then(function(j){if(j&&j.success){fr.srcdoc=j.data.html;sj.textContent=j.data.subject;}}).catch(function(){});}"
			. "f.addEventListener('input',function(){clearTimeout(t);t=setTimeout(up,400);});f.addEventListener('change',function(){clearTimeout(t);t=setTimeout(up,50);});})();";
		echo '<script>' . $js . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static script, JSON-encoded constant.
	}
}

<?php
/**
 * Settings tab "Werkzeuge": overview of all settings with search (SET-PC-32), reset per field, section
 * or everything (SET-PC-11), JSON export and import with preview (SET-PC-10) and the change history with
 * old/new values (SET-PC-13). All actions need manage_options and a nonce; the import preview is kept per
 * user for 15 minutes (the export never contains secrets).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Settings;
use PassCaptain\SettingsManager;
use PassCaptain\SettingsSchema;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class SettingsTools {
	const TAB = 'tools';
	const EXPORT_ACTION = 'wpcap_settings_export';
	const IMPORT_ACTION = 'wpcap_settings_import';
	const RESET_ACTION = 'wpcap_settings_reset';
	const PRESET_ACTION = 'wpcap_settings_preset';
	/** Transient prefix of the import preview (per user). */
	const PREVIEW_KEY = 'wpcap_settings_import_';
	/** Largest accepted import file. */
	const MAX_BYTES = 2097152;

	/** admin-post handlers (registered by Menu). */
	public static function handlers() {
		return array(
			self::EXPORT_ACTION => array( __CLASS__, 'handle_export' ),
			self::IMPORT_ACTION => array( __CLASS__, 'handle_import' ),
			self::RESET_ACTION => array( __CLASS__, 'handle_reset' ),
			self::PRESET_ACTION => array( __CLASS__, 'handle_preset' ),
		);
	}

	/** URL of the tab. */
	public static function url( array $args = array() ) {
		return Forms::page_url( SettingsPage::SLUG, array_merge( array( 'tab' => self::TAB ), $args ) );
	}

	/* ------------------------------------------------------------------ Handlers */

	public static function handle_export() {
		if ( ! current_user_can( SettingsPage::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		Forms::verify( self::EXPORT_ACTION );
		\PassCaptain\Audit::record( 'settings_exported', 'settings', 0, array( 'keys' => count( SettingsManager::exportable_keys() ) ) );
		$host = preg_replace( '/[^a-z0-9.-]/', '', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		\PassCaptain\Reports\Scope::send_download( (string) wp_json_encode( SettingsManager::export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), 'pass-captain-settings-' . $host . '-' . gmdate( 'Ymd' ) . '.json', 'application/json; charset=utf-8' );
	}

	/** Step 1 (file upload) stores a preview; step 2 (confirm) imports the previewed file. */
	public static function handle_import() {
		if ( ! current_user_can( SettingsPage::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		Forms::verify( self::IMPORT_ACTION );
		$in = Forms::post();
		Forms::finish( self::import_step( $in, $_FILES['settings_file'] ?? null ), '', self::url( array( 'view' => 'import' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- nonce verified above; the upload is read and parsed as JSON only.
	}

	/**
	 * One import step (testable without HTTP).
	 *
	 * @param array      $in   Form data: do=preview|confirm|cancel.
	 * @param array|null $file Uploaded file (step preview) or null; tests may pass array( 'content' => json ).
	 * @return array|\WP_Error
	 */
	public static function import_step( array $in, $file = null ) {
		if ( ! current_user_can( SettingsPage::CAP ) ) { return Forms::forbidden(); }
		$key = self::PREVIEW_KEY . get_current_user_id();
		$do = Forms::choice( $in, 'do', array( 'preview', 'confirm', 'cancel' ), 'preview' );
		if ( 'cancel' === $do ) {
			delete_transient( $key );
			return array( 'message' => __( 'Import abgebrochen.', 'pass-captain' ) );
		}
		if ( 'confirm' === $do ) {
			$content = get_transient( $key );
			if ( ! is_string( $content ) || '' === $content ) {
				return Forms::error( 'wpcap_settings_import_expired', __( 'Die Vorschau ist abgelaufen. Bitte lade die Datei erneut hoch.', 'pass-captain' ) );
			}
			$result = SettingsManager::import( $content, Forms::flag( $in, 'mail_templates' ) );
			if ( is_wp_error( $result ) ) { return $result; }
			delete_transient( $key );
			/* translators: 1: number of changed settings, 2: number of mail templates */
			Forms::flash( 'success', sprintf( __( 'Import abgeschlossen: %1$d Einstellungen geändert, %2$d E-Mail-Vorlagen übernommen.', 'pass-captain' ), count( $result['changed'] ), $result['mail_templates'] ) );
			return $result;
		}
		$content = '';
		if ( is_array( $file ) && isset( $file['content'] ) ) {
			$content = (string) $file['content'];
		} elseif ( is_array( $file ) && isset( $file['tmp_name'] ) && UPLOAD_ERR_OK === (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) && is_uploaded_file( (string) $file['tmp_name'] ) ) {
			if ( (int) ( $file['size'] ?? 0 ) > self::MAX_BYTES ) {
				return Forms::error( 'wpcap_settings_import_size', __( 'Die Datei ist zu groß (höchstens 2 MB).', 'pass-captain' ) );
			}
			$content = (string) file_get_contents( (string) $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local upload.
		}
		if ( '' === $content ) { return Forms::error( 'wpcap_settings_import_file', __( 'Bitte eine Exportdatei auswählen.', 'pass-captain' ) ); }
		$preview = SettingsManager::preview( $content );
		if ( is_wp_error( $preview ) ) { return $preview; }
		set_transient( $key, $content, 15 * MINUTE_IN_SECONDS );
		return $preview;
	}

	public static function handle_reset() {
		if ( ! current_user_can( SettingsPage::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		Forms::verify( self::RESET_ACTION );
		$in = Forms::post();
		$result = self::reset_step( $in );
		$message = is_array( $result ) ? sprintf( /* translators: %d: number of settings */ _n( '%d Einstellung auf den Standard zurückgesetzt.', '%d Einstellungen auf den Standard zurückgesetzt.', count( $result ), 'pass-captain' ), count( $result ) ) : '';
		Forms::finish( $result, $message, self::url() );
	}

	/**
	 * Reset (testable without HTTP): scope = all | key:<key> | section:<id> | page:<slug>; "all" and
	 * sections need the confirmation checkbox.
	 *
	 * @return string[]|\WP_Error Changed keys.
	 */
	public static function reset_step( array $in ) {
		if ( ! current_user_can( SettingsPage::CAP ) ) { return Forms::forbidden(); }
		$scope = is_scalar( $in['scope'] ?? null ) ? sanitize_text_field( (string) $in['scope'] ) : '';
		$keys = SettingsManager::scope_keys( $scope );
		if ( ! $keys ) { return Forms::error( 'wpcap_settings_reset_scope', __( 'Keine Einstellung zum Zurücksetzen ausgewählt.', 'pass-captain' ) ); }
		if ( 0 !== strpos( $scope, 'key:' ) && ! Forms::flag( $in, 'confirm' ) ) {
			return Forms::error( 'wpcap_settings_reset_confirm', __( 'Bitte bestätige, dass die Werte auf den Standard zurückgesetzt werden sollen.', 'pass-captain' ) );
		}
		return SettingsManager::reset( $keys );
	}

	public static function handle_preset() {
		if ( ! current_user_can( SettingsPage::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		Forms::verify( self::PRESET_ACTION );
		$in = Forms::post();
		$result = self::preset_step( $in );
		$message = is_array( $result ) ? sprintf( /* translators: %d: number of settings */ _n( '%d Wert aus dem Länderprofil übernommen. Bitte die mit „Rechtsprüfung“ markierten Werte prüfen lassen.', '%d Werte aus dem Länderprofil übernommen. Bitte die mit „Rechtsprüfung“ markierten Werte prüfen lassen.', count( $result ), 'pass-captain' ), count( $result ) ) : '';
		Forms::finish( $result, $message, self::url( array( 'view' => 'preset' ) ) );
	}

	/**
	 * Applies the selected values of a country preset (testable without HTTP).
	 *
	 * @param array $in preset (code), keys[] (selected rows).
	 * @return string[]|\WP_Error Applied keys.
	 */
	public static function preset_step( array $in ) {
		if ( ! current_user_can( SettingsPage::CAP ) ) { return Forms::forbidden(); }
		$code = strtoupper( is_scalar( $in['preset'] ?? null ) ? sanitize_key( (string) $in['preset'] ) : '' );
		$keys = array_values( array_filter( array_map( 'sanitize_key', (array) ( is_array( $in['keys'] ?? null ) ? $in['keys'] : array() ) ) ) );
		if ( ! $keys ) { return Forms::error( 'wpcap_preset_none', __( 'Bitte mindestens einen Wert zum Übernehmen auswählen.', 'pass-captain' ) ); }
		return \PassCaptain\CountryPresets::apply( $code, $keys );
	}

	/* ------------------------------------------------------------------ Rendering */

	/** Renders the tab content (inside the settings wrap). */
	public static function render() {
		if ( ! current_user_can( SettingsPage::CAP ) ) { echo '<p>' . esc_html__( 'Keine Berechtigung.', 'pass-captain' ) . '</p>'; return; }
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view and search.
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( (string) $_GET['view'] ) ) : 'overview';
		$query = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : '';
		$history_key = isset( $_GET['key'] ) ? sanitize_key( wp_unslash( (string) $_GET['key'] ) ) : '';
		// phpcs:enable
		$views = array(
			'overview' => __( 'Alle Einstellungen', 'pass-captain' ),
			'preset' => __( 'Länderprofil', 'pass-captain' ),
			'import' => __( 'Export und Import', 'pass-captain' ),
			'reset' => __( 'Zurücksetzen', 'pass-captain' ),
			'history' => __( 'Verlauf', 'pass-captain' ),
		);
		$view = isset( $views[ $view ] ) ? $view : 'overview';
		echo '<ul class="subsubsub">';
		$i = 0;
		foreach ( $views as $id => $title ) {
			echo '<li><a href="' . esc_url( self::url( array( 'view' => $id ) ) ) . '"' . ( $id === $view ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html( $title ) . '</a>' . ( ++$i < count( $views ) ? ' | ' : '' ) . '</li>';
		}
		echo '</ul><div class="clear"></div>';
		switch ( $view ) {
			case 'preset':
				self::render_preset( isset( $_GET['preset'] ) ? strtoupper( sanitize_key( wp_unslash( (string) $_GET['preset'] ) ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only preview.
				break;
			case 'import':
				self::render_import();
				break;
			case 'reset':
				self::render_reset();
				break;
			case 'history':
				self::render_history( $history_key );
				break;
			default:
				self::render_overview( $query );
		}
	}

	/** Value for display: secrets as status, lists as JSON, long texts shortened. */
	public static function value_text( $key, $value ) {
		$entry = SettingsSchema::get( $key );
		if ( $entry && $entry['secret'] ) {
			return '' !== (string) \PassCaptain\Settings::secret( $key ) ? __( 'gesetzt (geheim)', 'pass-captain' ) : __( 'nicht gesetzt', 'pass-captain' );
		}
		if ( is_array( $value ) ) { $value = (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); }
		if ( is_bool( $value ) ) { $value = (int) $value; }
		if ( $entry && 'bool' === $entry['type'] ) { return empty( $value ) ? __( 'aus', 'pass-captain' ) : __( 'ein', 'pass-captain' ); }
		if ( $entry && 'choice' === $entry['type'] && isset( $entry['options'][ (string) $value ] ) ) { return (string) $entry['options'][ (string) $value ]; }
		$value = wp_strip_all_tags( (string) $value );
		return '' === $value ? '—' : \PassCaptain\Text::cut( $value, 80 );
	}

	/**
	 * Rows of the overview, filtered by a search text (key, label, help text or value).
	 *
	 * @return array[] key, label, help, value, default, source, changed, legal, tab
	 */
	public static function overview_rows( $query = '' ) {
		$all = Settings::all();
		$query = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( (string) $query ) ) : strtolower( trim( (string) $query ) );
		$sources = array( 'core' => __( 'Kern', 'pass-captain' ), 'module' => __( 'Modul', 'pass-captain' ), 'addon' => __( 'Erweiterung', 'pass-captain' ), 'unknown' => __( 'unbeschrieben', 'pass-captain' ) );
		$rows = array();
		foreach ( SettingsSchema::all() as $key => $entry ) {
			if ( 'client_ip_header' === $key ) { continue; }
			$value = self::value_text( $key, $all[ $key ] ?? null );
			$row = array(
				'key' => (string) $key,
				'label' => '' !== $entry['label'] ? $entry['label'] : (string) $key,
				'help' => $entry['help'],
				'value' => $value,
				'default' => $entry['secret'] ? '—' : self::value_text( $key, $entry['default'] ),
				'source' => $sources[ $entry['source'] ] ?? $entry['source'],
				'override' => (string) Settings::override_source( $key ),
				'changed' => ! $entry['secret'] && wp_json_encode( $all[ $key ] ?? null ) !== wp_json_encode( $entry['default'] ),
				'legal' => $entry['legal'],
				'resettable' => in_array( $key, SettingsManager::resettable_keys(), true ),
				'link' => self::field_link( $entry ),
			);
			if ( '' !== $query ) {
				$haystack = strtolower( $row['key'] . ' ' . $row['label'] . ' ' . $row['help'] . ' ' . $row['value'] );
				if ( false === strpos( function_exists( 'mb_strtolower' ) ? mb_strtolower( $haystack ) : $haystack, $query ) ) { continue; }
			}
			$rows[] = $row;
		}
		usort( $rows, static function ( $a, $b ) { return strcasecmp( $a['label'], $b['label'] ); } );
		return $rows;
	}

	/** Where the field is edited. */
	private static function field_link( array $entry ) {
		if ( 'settings' === $entry['page'] && '' !== $entry['tab'] ) { return Forms::page_url( SettingsPage::SLUG, array( 'tab' => $entry['tab'] ) ); }
		if ( '' !== $entry['page'] && 'settings' !== $entry['page'] ) { return Forms::page_url( $entry['page'] ); }
		return '';
	}

	private static function render_overview( $query ) {
		$rows = self::overview_rows( $query );
		echo '<h2>' . esc_html__( 'Alle Einstellungen', 'pass-captain' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Jeder Wert mit Standard und Herkunft. Geänderte Werte sind fett; „Rechtsprüfung“ kennzeichnet Werte mit rechtlicher Bedeutung, die du prüfen lassen solltest.', 'pass-captain' ) . '</p>';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" role="search"><input type="hidden" name="page" value="' . esc_attr( SettingsPage::SLUG ) . '"><input type="hidden" name="tab" value="' . esc_attr( self::TAB ) . '">';
		echo '<p class="search-box"><label for="wpcap-settings-search">' . esc_html__( 'Einstellungen durchsuchen', 'pass-captain' ) . '</label> <input type="search" id="wpcap-settings-search" name="q" value="' . esc_attr( $query ) . '"> <button type="submit" class="button">' . esc_html__( 'Suchen', 'pass-captain' ) . '</button></p></form>';
		/* translators: %d: number of settings */
		echo '<p>' . esc_html( sprintf( _n( '%d Einstellung', '%d Einstellungen', count( $rows ), 'pass-captain' ), count( $rows ) ) ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Einstellung', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Wert', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Standard', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Herkunft', 'pass-captain' ) . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__( 'Aktionen', 'pass-captain' ) . '</span></th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$label = '' !== $row['link'] ? '<a href="' . esc_url( $row['link'] ) . '">' . esc_html( $row['label'] ) . '</a>' : esc_html( $row['label'] );
			echo '<tr><td>' . $label . '<br><code>' . esc_html( $row['key'] ) . '</code>' . ( $row['legal'] ? ' <span class="wpcap-badge">' . esc_html__( 'Rechtsprüfung', 'pass-captain' ) . '</span>' : '' ) . ( '' !== $row['help'] ? '<br><span class="description">' . esc_html( $row['help'] ) . '</span>' : '' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $label is escaped above.
			echo '<td>' . ( $row['changed'] ? '<strong>' . esc_html( $row['value'] ) . '</strong>' : esc_html( $row['value'] ) ) . ( '' !== $row['override'] ? '<br><span class="description">' . esc_html__( 'festgelegt in wp-config.php oder Umgebung', 'pass-captain' ) . '</span>' : '' ) . '</td>';
			echo '<td>' . esc_html( $row['default'] ) . '</td><td>' . esc_html( $row['source'] ) . '</td><td>';
			if ( $row['changed'] && $row['resettable'] ) {
				Forms::open_form( self::RESET_ACTION );
				Forms::hidden( 'scope', 'key:' . $row['key'] );
				/* translators: %s: setting label */
				echo '<button type="submit" class="button button-small" aria-label="' . esc_attr( sprintf( __( '„%s“ auf den Standard zurücksetzen', 'pass-captain' ), $row['label'] ) ) . '">' . esc_html__( 'Standard', 'pass-captain' ) . '</button></form>';
			}
			echo ' <a href="' . esc_url( self::url( array( 'view' => 'history', 'key' => $row['key'] ) ) ) . '">' . esc_html__( 'Verlauf', 'pass-captain' ) . '</a></td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_preset( $code ) {
		$presets = \PassCaptain\CountryPresets::all();
		$reviews = \PassCaptain\CountryPresets::review_labels();
		echo '<h2>' . esc_html__( 'Länderprofil', 'pass-captain' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Ein Länderprofil schlägt passende Werte für dein Land vor. Du siehst jeden Wert vorher und nachher und wählst aus, was übernommen wird; nichts wird ungefragt geändert. Rechtlich bedeutsame Werte sind markiert und müssen vor dem Verkaufsstart geprüft werden.', 'pass-captain' ) . '</p>';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( SettingsPage::SLUG ) . '"><input type="hidden" name="tab" value="' . esc_attr( self::TAB ) . '"><input type="hidden" name="view" value="preset">';
		$options = array( '' => __( '— Land wählen —', 'pass-captain' ) );
		foreach ( $presets as $id => $preset ) { $options[ $id ] = $preset['label'] . ' (' . ( $reviews[ $preset['review'] ] ?? '' ) . ')'; }
		echo '<p><label for="wpcap-preset">' . esc_html__( 'Land', 'pass-captain' ) . '</label> ';
		Forms::select( 'preset', $options, $code, 'wpcap-preset' );
		echo ' <button type="submit" class="button">' . esc_html__( 'Vorschlag anzeigen', 'pass-captain' ) . '</button></p></form>';
		if ( '' === $code || ! isset( $presets[ $code ] ) ) { return; }
		$preset = $presets[ $code ];
		echo '<div class="notice notice-warning inline"><p><strong>' . esc_html( $reviews[ $preset['review'] ] ?? '' ) . ':</strong> ' . esc_html( $preset['note'] ) . '</p></div>';
		$diff = \PassCaptain\CountryPresets::diff( $code );
		if ( is_wp_error( $diff ) || ! $diff ) { echo '<p>' . esc_html__( 'Deine Einstellungen entsprechen bereits diesem Profil.', 'pass-captain' ) . '</p>'; return; }
		Forms::open_form( self::PRESET_ACTION );
		Forms::hidden( 'preset', $code );
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Übernehmen', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Einstellung', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Bisher', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Vorschlag', 'pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $diff as $row ) {
			$id = 'wpcap-preset-' . $row['key'];
			echo '<tr><td><input type="checkbox" id="' . esc_attr( $id ) . '" name="keys[]" value="' . esc_attr( $row['key'] ) . '"' . ( '' === $row['error'] ? ' checked' : ' disabled' ) . '></td><td><label for="' . esc_attr( $id ) . '">' . esc_html( $row['label'] ) . '</label>' . ( $row['legal'] ? ' <span class="wpcap-badge">' . esc_html__( 'Rechtsprüfung', 'pass-captain' ) . '</span>' : '' ) . '</td><td>' . esc_html( self::value_text( $row['key'], $row['old'] ) ) . '</td><td>' . ( '' !== $row['error'] ? esc_html( $row['error'] ) : esc_html( self::value_text( $row['key'], $row['new'] ) ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		submit_button( __( 'Ausgewählte Werte übernehmen', 'pass-captain' ) );
		echo '</form>';
	}

	private static function render_import() {
		echo '<h2>' . esc_html__( 'Export', 'pass-captain' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Lädt alle Einstellungen und E-Mail-Vorlagen als JSON-Datei herunter, z. B. um eine zweite Website gleich einzurichten. Zugangsdaten und Schlüssel sowie die Wahl „Daten beim Löschen entfernen“ sind nie enthalten.', 'pass-captain' ) . '</p>';
		Forms::open_form( self::EXPORT_ACTION );
		submit_button( __( 'Einstellungen exportieren', 'pass-captain' ), 'secondary', 'submit', false );
		echo '</form>';

		echo '<h2>' . esc_html__( 'Import', 'pass-captain' ) . '</h2>';
		$preview = get_transient( self::PREVIEW_KEY . get_current_user_id() );
		$data = is_string( $preview ) ? SettingsManager::preview( $preview ) : null;
		if ( is_array( $data ) ) {
			/* translators: 1: number of changes, 2: number of errors */
			echo '<p>' . esc_html( sprintf( __( 'Vorschau: %1$d Änderungen, %2$d Fehler. Es wird erst nach deiner Bestätigung etwas gespeichert.', 'pass-captain' ), $data['changes'], $data['errors'] ) ) . '</p>';
			echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Einstellung', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Bisher', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Neu', 'pass-captain' ) . '</th></tr></thead><tbody>';
			foreach ( $data['rows'] as $row ) {
				if ( ! $row['changed'] && '' === $row['error'] ) { continue; }
				echo '<tr><td>' . esc_html( $row['label'] ) . ( $row['legal'] ? ' <span class="wpcap-badge">' . esc_html__( 'Rechtsprüfung', 'pass-captain' ) . '</span>' : '' ) . '</td><td>' . esc_html( self::value_text( $row['key'], $row['old'] ) ) . '</td><td>' . ( '' !== $row['error'] ? '<span class="wpcap-error">' . esc_html( $row['error'] ) . '</span>' : esc_html( self::value_text( $row['key'], $row['new'] ) ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
			if ( $data['skipped'] ) {
				echo '<p class="description">' . esc_html__( 'Übersprungen (unbekannt oder geschützt):', 'pass-captain' ) . ' <code>' . esc_html( implode( ', ', array_keys( $data['skipped'] ) ) ) . '</code></p>';
			}
			Forms::open_form( self::IMPORT_ACTION );
			Forms::hidden( 'do', 'confirm' );
			if ( $data['mail_templates'] > 0 ) {
				/* translators: %d: number of mail templates */
				echo '<p><label><input type="checkbox" name="mail_templates" value="1" checked> ' . esc_html( sprintf( __( 'Auch %d E-Mail-Vorlagen übernehmen', 'pass-captain' ), $data['mail_templates'] ) ) . '</label></p>';
			}
			if ( 0 === $data['errors'] ) { submit_button( __( 'Import bestätigen', 'pass-captain' ), 'primary', 'submit', false ); }
			echo '</form>';
			Forms::open_form( self::IMPORT_ACTION );
			Forms::hidden( 'do', 'cancel' );
			submit_button( __( 'Abbrechen', 'pass-captain' ), 'secondary', 'submit', false );
			echo '</form>';
			return;
		}
		echo '<p class="description">' . esc_html__( 'Lade eine Exportdatei hoch. Du siehst zuerst eine Vorschau mit allen Änderungen; jeder Wert wird wie auf der Einstellungsseite geprüft.', 'pass-captain' ) . '</p>';
		Forms::open_form( self::IMPORT_ACTION, null, 'enctype="multipart/form-data"' );
		Forms::hidden( 'do', 'preview' );
		echo '<p><label for="wpcap-settings-file">' . esc_html__( 'Exportdatei (JSON)', 'pass-captain' ) . '</label> <input type="file" id="wpcap-settings-file" name="settings_file" accept="application/json,.json" required></p>';
		submit_button( __( 'Vorschau anzeigen', 'pass-captain' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	private static function render_reset() {
		echo '<h2>' . esc_html__( 'Auf Standard zurücksetzen', 'pass-captain' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Setzt die gewählten Einstellungen auf ihre Standardwerte zurück. Zugangsdaten, Schlüssel und die Wahl „Daten beim Löschen entfernen“ bleiben unverändert. Die alten Werte stehen im Verlauf.', 'pass-captain' ) . '</p>';
		$scopes = array( 'all' => __( 'Alle Einstellungen', 'pass-captain' ) );
		foreach ( SettingsPage::tabs() as $tab => $title ) {
			foreach ( SettingsPage::all_sections() as $id => $section ) {
				if ( ( $section['tab'] ?? 'general' ) === $tab && SettingsManager::scope_keys( 'section:' . $id ) ) {
					$scopes[ 'section:' . $id ] = $title . ' › ' . (string) ( $section['title'] ?? $id );
				}
			}
		}
		$pages = array();
		foreach ( SettingsSchema::all() as $entry ) {
			if ( '' !== $entry['page'] && 'settings' !== $entry['page'] ) { $pages[ $entry['page'] ] = $entry['label']; }
		}
		foreach ( $pages as $page => $label ) {
			/* translators: %s: module page title */
			$scopes[ 'page:' . $page ] = sprintf( __( 'Modulseite: %s', 'pass-captain' ), $label );
		}
		Forms::open_form( self::RESET_ACTION );
		echo '<p><label for="wpcap-reset-scope">' . esc_html__( 'Bereich', 'pass-captain' ) . '</label> ';
		Forms::select( 'scope', $scopes, 'all', 'wpcap-reset-scope' );
		echo '</p><p><label><input type="checkbox" name="confirm" value="1" required> ' . esc_html__( 'Ja, die Werte dieses Bereichs auf den Standard zurücksetzen.', 'pass-captain' ) . '</label></p>';
		submit_button( __( 'Zurücksetzen', 'pass-captain' ), 'delete', 'submit', false );
		echo '</form>';
	}

	private static function render_history( $key ) {
		$entries = SettingsManager::history( array( 'key' => $key, 'limit' => 100 ) );
		$labels = SettingsManager::source_labels();
		echo '<h2>' . esc_html__( 'Verlauf der Einstellungen', 'pass-captain' ) . '</h2>';
		if ( '' !== $key ) {
			$entry = SettingsSchema::get( $key );
			/* translators: %s: setting label */
			echo '<p>' . esc_html( sprintf( __( 'Nur Änderungen an „%s“.', 'pass-captain' ), $entry && '' !== $entry['label'] ? $entry['label'] : $key ) ) . ' <a href="' . esc_url( self::url( array( 'view' => 'history' ) ) ) . '">' . esc_html__( 'Alle anzeigen', 'pass-captain' ) . '</a></p>';
		}
		echo '<p class="description">' . esc_html__( 'Geheime Werte werden nie protokolliert; lange Texte erscheinen als Prüfsumme.', 'pass-captain' ) . '</p>';
		if ( ! $entries ) { echo '<p>' . esc_html__( 'Noch keine Änderungen protokolliert.', 'pass-captain' ) . '</p>'; return; }
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Zeitpunkt', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Person', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Quelle', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Einstellung', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Bisher', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Neu', 'pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $entries as $entry ) {
			foreach ( $entry['changes'] as $change ) {
				$schema = SettingsSchema::get( (string) ( $change['key'] ?? '' ) );
				echo '<tr><td>' . esc_html( get_date_from_gmt( $entry['created_at'], 'd.m.Y H:i' ) ) . '</td><td>' . esc_html( $entry['user_id'] ? Forms::user_label( $entry['user_id'] ) : '—' ) . '</td><td>' . esc_html( $labels[ $entry['source'] ] ?? $entry['source'] ) . '</td><td>' . esc_html( $schema && '' !== $schema['label'] ? $schema['label'] : (string) ( $change['key'] ?? '' ) ) . '</td><td>' . esc_html( SettingsManager::display_value( $change['old'] ?? null ) ) . '</td><td>' . esc_html( SettingsManager::display_value( $change['new'] ?? null ) ) . '</td></tr>';
			}
		}
		echo '</tbody></table>';
	}
}

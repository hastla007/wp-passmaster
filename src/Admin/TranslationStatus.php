<?php
/**
 * Translation status in the system status (TODO "Übersetzungsverwaltung im Admin", first part): for every
 * bundled language of languages/ the share of the template's (pass-captain.pot) strings that are translated,
 * fuzzy or missing, strings of the translation that the template no longer has, and which file WordPress
 * loads for the language (a language pack in WP_LANG_DIR/plugins wins over the bundled file). Read-only: it
 * changes no file and no setting.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class TranslationStatus {
	const DOMAIN = 'pass-captain';
	/** Cache of the parsed counts, keyed by the files' paths, sizes and modification times. */
	const CACHE = 'wpcap_translation_status';

	/** Directory of the bundled translations. */
	public static function dir() {
		return WPCAP_PATH . 'languages';
	}

	/**
	 * Parses a PO/POT file into its entries (without the header).
	 *
	 * @return array<string,array{translated:bool,fuzzy:bool}> key = msgctxt "\x04" msgid (like gettext).
	 */
	public static function parse( $file ) {
		$entries = array();
		$text = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file.
		if ( '' === $text ) { return $entries; }
		$blocks = preg_split( '/\R\s*\R/', str_replace( "\r\n", "\n", $text ) );
		foreach ( (array) $blocks as $block ) {
			$fields = array();
			$fuzzy = false;
			$current = null;
			$obsolete = false;
			foreach ( explode( "\n", (string) $block ) as $line ) {
				$line = trim( $line );
				if ( '' === $line ) { continue; }
				if ( 0 === strpos( $line, '#~' ) ) { $obsolete = true; continue; }
				if ( 0 === strpos( $line, '#,' ) ) { $fuzzy = $fuzzy || false !== strpos( $line, 'fuzzy' ); continue; }
				if ( '#' === $line[0] ) { continue; }
				if ( preg_match( '/^(msgctxt|msgid_plural|msgid|msgstr(?:\[\d+\])?)\s+"(.*)"$/s', $line, $m ) ) {
					$current = $m[1];
					$fields[ $current ] = stripcslashes( $m[2] );
				} elseif ( null !== $current && preg_match( '/^"(.*)"$/s', $line, $m ) ) {
					$fields[ $current ] .= stripcslashes( $m[1] );
				}
			}
			if ( $obsolete || ! isset( $fields['msgid'] ) || '' === $fields['msgid'] ) { continue; } // Header or obsolete entry.
			$strings = array();
			foreach ( $fields as $name => $value ) {
				if ( 0 === strpos( $name, 'msgstr' ) ) { $strings[] = $value; }
			}
			$key = ( isset( $fields['msgctxt'] ) ? $fields['msgctxt'] . "\x04" : '' ) . $fields['msgid'];
			$entries[ $key ] = array(
				'translated' => $strings && ! in_array( '', $strings, true ),
				'fuzzy' => $fuzzy,
			);
		}
		return $entries;
	}

	/**
	 * Counts per bundled language against the template ($dir: another languages directory, for tests).
	 *
	 * @return array{template:int,languages:array<string,array{locale:string,file:string,total:int,translated:int,fuzzy:int,missing:int,obsolete:int,percent:float}>}
	 */
	public static function coverage( $dir = null ) {
		$dir = rtrim( null === $dir ? self::dir() : (string) $dir, '/\\' );
		$pot = $dir . '/' . self::DOMAIN . '.pot';
		$files = glob( $dir . '/' . self::DOMAIN . '-*.po' );
		$files = is_array( $files ) ? $files : array();
		sort( $files );
		$stamp = array();
		foreach ( array_merge( array( $pot ), $files ) as $file ) { $stamp[] = $file . ':' . ( is_file( $file ) ? filesize( $file ) . ':' . filemtime( $file ) : '-' ); }
		$stamp = md5( implode( '|', $stamp ) );
		$cached = get_transient( self::CACHE );
		if ( is_array( $cached ) && ( $cached['stamp'] ?? '' ) === $stamp && isset( $cached['data'] ) ) { return $cached['data']; }

		$template = self::parse( $pot );
		$data = array( 'template' => count( $template ), 'languages' => array() );
		foreach ( $files as $file ) {
			$locale = substr( basename( $file, '.po' ), strlen( self::DOMAIN ) + 1 );
			$po = self::parse( $file );
			$translated = 0;
			$fuzzy = 0;
			foreach ( $template as $key => $unused ) {
				if ( ! isset( $po[ $key ] ) ) { continue; }
				if ( $po[ $key ]['fuzzy'] ) { ++$fuzzy; } elseif ( $po[ $key ]['translated'] ) { ++$translated; }
			}
			$total = count( $template );
			$data['languages'][ $locale ] = array(
				'locale' => $locale,
				'file' => basename( $file ),
				'total' => $total,
				'translated' => $translated,
				'fuzzy' => $fuzzy,
				'missing' => $total - $translated - $fuzzy,
				'obsolete' => count( array_diff_key( $po, $template ) ),
				'percent' => $total ? floor( $translated * 1000 / $total ) / 10 : 0.0,
			);
		}
		set_transient( self::CACHE, array( 'stamp' => $stamp, 'data' => $data ), DAY_IN_SECONDS );
		return $data;
	}

	/**
	 * Which translation file WordPress uses for $locale: a language pack (WP_LANG_DIR/plugins) wins over the
	 * bundled file; '' when the language has neither (the German source texts are shown).
	 *
	 * @return array{source:string,path:string} source: pack|bundled|none.
	 */
	public static function active_file( $locale, $dir = null ) {
		$locale = preg_replace( '/[^A-Za-z0-9_@-]/', '', (string) $locale );
		$name = self::DOMAIN . '-' . $locale;
		$lang_dir = defined( 'WP_LANG_DIR' ) ? rtrim( (string) WP_LANG_DIR, '/\\' ) . '/plugins/' : '';
		foreach ( array( '.l10n.php', '.mo' ) as $ext ) {
			if ( '' !== $lang_dir && is_file( $lang_dir . $name . $ext ) ) { return array( 'source' => 'pack', 'path' => $lang_dir . $name . $ext ); }
		}
		$bundled = rtrim( null === $dir ? self::dir() : (string) $dir, '/\\' ) . '/' . $name . '.mo';
		if ( is_file( $bundled ) ) { return array( 'source' => 'bundled', 'path' => $bundled ); }
		return array( 'source' => 'none', 'path' => '' );
	}

	/** Section of the system status (tab "Übersicht"). */
	public static function render() {
		$data = self::coverage();
		$site = function_exists( 'get_locale' ) ? (string) get_locale() : 'de_DE';
		echo '<h2 id="wpcap-translations">' . esc_html__( 'Übersetzungen', 'pass-captain' ) . '</h2>';
		/* translators: %d: number of translatable texts */
		echo '<p class="description">' . esc_html( sprintf( __( 'Die Quelltexte sind Deutsch (du). Die Vorlage enthält %d übersetzbare Texte; Käufer:innen sehen fehlende Übersetzungen auf Deutsch.', 'pass-captain' ), (int) $data['template'] ) ) . '</p>';
		if ( ! $data['languages'] ) {
			echo '<p>' . esc_html__( 'Keine mitgelieferten Übersetzungen gefunden.', 'pass-captain' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Sprache', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Übersetzt', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Ungeprüft (fuzzy)', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Fehlend', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Nicht mehr verwendet', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Geladene Datei', 'pass-captain' ) . '</th></tr></thead><tbody>';
		$sources = array(
			'pack' => __( 'Sprachpaket (WordPress)', 'pass-captain' ),
			'bundled' => __( 'mitgeliefert', 'pass-captain' ),
			'none' => __( 'keine (.mo fehlt)', 'pass-captain' ),
		);
		foreach ( $data['languages'] as $row ) {
			$active = self::active_file( $row['locale'] );
			$label = '<code>' . esc_html( $row['locale'] ) . '</code>' . ( $site === $row['locale'] ? ' ' . esc_html__( '(Website-Sprache)', 'pass-captain' ) : '' );
			$state = 'none' === $active['source'] || $row['missing'] > 0 || $row['fuzzy'] > 0 ? 'warning' : 'good';
			/* translators: 1: translated texts, 2: all texts, 3: percent */
			$share = sprintf( __( '%1$d von %2$d (%3$s %%)', 'pass-captain' ), (int) $row['translated'], (int) $row['total'], number_format_i18n( (float) $row['percent'], 1 ) );
			echo '<tr data-locale="' . esc_attr( $row['locale'] ) . '" data-state="' . esc_attr( $state ) . '"><td>' . $label . '</td><td>' . esc_html( $share ) . '</td><td>' . (int) $row['fuzzy'] . '</td><td>' . (int) $row['missing'] . '</td><td>' . (int) $row['obsolete'] . '</td><td>' . esc_html( $sources[ $active['source'] ] ) . ( '' !== $active['path'] ? '<br><code>' . esc_html( basename( $active['path'] ) ) . '</code>' : '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- $label escaped above.
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Ein Sprachpaket von WordPress hat Vorrang vor der mitgelieferten Datei. Fehlende und ungeprüfte Texte ergänzt du in der .po-Datei der Sprache (z. B. mit Poedit) oder über ein Sprachpaket.', 'pass-captain' ) . '</p>';
	}
}

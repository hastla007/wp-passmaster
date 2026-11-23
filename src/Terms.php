<?php
/**
 * A690: configurable terms – what the plugin calls the people a ticket is for ("Teilnehmende" by default,
 * "Gäste", "Besucher:innen" or own words).
 *
 * Settings: `attendee_terms` (preset: '' = unchanged, guests, visitors, custom) and `term_replacements`
 * (own lines "Alt = Neu", used with "custom" and added after a preset). The replacements are applied to the
 * translated texts of the text domain (gettext filters, like Brand) for whole words, longest first and in a
 * single pass (a replacement is never replaced again), on the pages and in the mails buyers and attendees see:
 * front end requests (shortcodes, blocks, REST and AJAX of the public pages) and while a customer mail is
 * rendered (Mailer). Admin screens keep the plugin's terms (filter `wpcap_terms_in_admin` to change that).
 *
 * The presets are German word forms (source language and German translations); for other languages own lines
 * or the filter `wpcap_terms_map` supply the words of that language.
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Terms {
	/** Longest accepted list of own replacement lines. */
	const MAX_LINES = 60;

	/** @var array<string,string>|null Map of this request (null = not built yet). */
	private static $map = null;

	/** @var int Nesting depth of customer mail rendering (terms apply there in admin requests, too). */
	private static $mail = 0;

	/** @var string|null Pattern of the current map. */
	private static $pattern = null;

	/** @var bool Guards against recursion: reading the settings may translate texts of the domain. */
	private static $building = false;

	/** Hooks the gettext filters of the text domain (called from Plugin::run()). */
	public static function register() {
		add_filter( 'gettext_pass-captain', array( __CLASS__, 'filter_gettext' ), 15 );
		add_filter( 'ngettext_pass-captain', array( __CLASS__, 'filter_gettext' ), 15 );
		add_filter( 'gettext_with_context_pass-captain', array( __CLASS__, 'filter_gettext' ), 15 );
	}

	/** Labels of the presets for the settings page. */
	public static function preset_labels() {
		return array(
			''         => __( 'Teilnehmende (Standard)', 'pass-captain' ),
			'guests'   => __( 'Gäste', 'pass-captain' ),
			'visitors' => __( 'Besucher:innen', 'pass-captain' ),
			'custom'   => __( 'Nur eigene Ersetzungen', 'pass-captain' ),
		);
	}

	/**
	 * Word forms of a preset (German), old => new. Phrases with articles come first where the case changes
	 * ("den Teilnehmenden" → "den Gästen").
	 *
	 * @return array<string,string>
	 */
	public static function preset( $key ) {
		$presets = array(
			'guests'   => array(
				'den Teilnehmenden'   => 'den Gästen',
				'der Teilnehmer:in'   => 'des Gastes',
				'die Teilnehmer:in'   => 'den Gast',
				'Teilnehmenden'       => 'Gäste',
				'Teilnehmende'        => 'Gäste',
				'Teilnehmer:innen'    => 'Gäste',
				'Teilnehmer:in'       => 'Gast',
				'Teilnehmers'         => 'Gastes',
				'Teilnehmer-E-Mail'   => 'Gast-E-Mail',
				'Teilnehmerdaten'     => 'Gästedaten',
				'Teilnehmerangaben'   => 'Gästeangaben',
				'Teilnehmerangabe'    => 'Gästeangabe',
				'Teilnehmername'      => 'Gastname',
				'Teilnehmernamen'     => 'Gastnamen',
				'Teilnehmerliste'     => 'Gästeliste',
				'Teilnehmerzuweisung' => 'Gästezuweisung',
				'Teilnehmer'          => 'Gast',
			),
			'visitors' => array(
				'den Teilnehmenden'   => 'den Besucher:innen',
				'Teilnehmenden'       => 'Besucher:innen',
				'Teilnehmende'        => 'Besucher:innen',
				'Teilnehmer:innen'    => 'Besucher:innen',
				'Teilnehmer:in'       => 'Besucher:in',
				'Teilnehmers'         => 'Besuchers',
				'Teilnehmer-E-Mail'   => 'Besucher-E-Mail',
				'Teilnehmerdaten'     => 'Besucherdaten',
				'Teilnehmerangaben'   => 'Besucherangaben',
				'Teilnehmerangabe'    => 'Besucherangabe',
				'Teilnehmername'      => 'Besuchername',
				'Teilnehmernamen'     => 'Besuchernamen',
				'Teilnehmerliste'     => 'Besucherliste',
				'Teilnehmerzuweisung' => 'Besucherzuweisung',
				'Teilnehmer'          => 'Besucher',
			),
		);
		return $presets[ $key ] ?? array();
	}

	/**
	 * Parses own replacement lines ("Alt = Neu", one per line, at most MAX_LINES; blank lines and lines
	 * starting with # are ignored).
	 *
	 * @return array<string,string>
	 */
	public static function parse( $text ) {
		$map = array();
		foreach ( array_slice( preg_split( '/\r\n|\r|\n/', (string) $text ), 0, self::MAX_LINES * 2 ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] || false === strpos( $line, '=' ) ) { continue; }
			list( $old, $new ) = array_map( 'trim', explode( '=', $line, 2 ) );
			$old = Text::cut( sanitize_text_field( $old ), 60 );
			$new = Text::cut( sanitize_text_field( $new ), 60 );
			if ( '' === $old || $old === $new ) { continue; }
			$map[ $old ] = $new;
			if ( count( $map ) >= self::MAX_LINES ) { break; }
		}
		return $map;
	}

	/** Settings sanitizer of `term_replacements`: normalised lines "Alt = Neu". */
	public static function sanitize_lines( $value ) {
		$out = array();
		foreach ( self::parse( (string) $value ) as $old => $new ) { $out[] = $old . ' = ' . $new; }
		return implode( "\n", $out );
	}

	/**
	 * The active map: preset words plus own lines (own lines win), filter `wpcap_terms_map`.
	 *
	 * @return array<string,string>
	 */
	public static function map() {
		if ( null === self::$map ) {
			if ( self::$building ) { return array(); }
			self::$building = true;
			try {
				self::build();
			} finally {
				self::$building = false;
			}
		}
		return (array) self::$map;
	}

	/** Builds the map and its pattern (map()). */
	private static function build() {
		$preset = (string) Settings::get( 'attendee_terms' );
		$map = array_merge( self::preset( $preset ), self::parse( (string) Settings::get( 'term_replacements' ) ) );
		/**
		 * A690: words replaced in the texts buyers and attendees see (old => new, whole words).
		 *
		 * @param array  $map    Replacements.
		 * @param string $preset Preset key ('' = none).
		 */
		$map = apply_filters( 'wpcap_terms_map', $map, $preset );
		$clean = array();
		foreach ( is_array( $map ) ? $map : array() as $old => $new ) {
			if ( is_string( $old ) && '' !== $old && is_scalar( $new ) && $old !== (string) $new ) { $clean[ $old ] = (string) $new; }
		}
		uksort( $clean, static function ( $a, $b ) { return mb_strlen( $b ) <=> mb_strlen( $a ) ?: strcmp( $a, $b ); } );
		self::$map = $clean;
		self::$pattern = $clean ? '/(?<![\p{L}\p{N}])(?:' . implode( '|', array_map( static function ( $w ) { return preg_quote( $w, '/' ); }, array_keys( $clean ) ) ) . ')(?![\p{L}\p{N}])/u' : null;
	}

	/** Forgets the map (settings changed, tests). */
	public static function flush() {
		self::$map = null;
		self::$pattern = null;
	}

	/** Replaces the configured words in a text (whole words, one pass). */
	public static function apply( $text ) {
		$text = (string) $text;
		$map = self::map();
		if ( ! $map || null === self::$pattern ) { return $text; }
		$out = preg_replace_callback( self::$pattern, static function ( $m ) use ( $map ) { return $map[ $m[0] ] ?? $m[0]; }, $text );
		return is_string( $out ) ? $out : $text;
	}

	/** Whether texts are rewritten in this request: front end, or a customer mail being rendered. */
	public static function active() {
		if ( self::$mail > 0 ) { return true; }
		$admin_screen = function_exists( 'is_admin' ) && is_admin() && ! ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() );
		return ! $admin_screen || (bool) apply_filters( 'wpcap_terms_in_admin', false );
	}

	/**
	 * Runs $callback with the terms applied (customer mails rendered in admin requests or jobs).
	 *
	 * @param callable $callback Renders the mail.
	 * @return mixed Its result.
	 */
	public static function in_mail( callable $callback ) {
		++self::$mail;
		try {
			return $callback();
		} finally {
			--self::$mail;
		}
	}

	/** gettext filter of the text domain. */
	public static function filter_gettext( $translation ) {
		if ( ! is_string( $translation ) || '' === $translation || ! self::active() ) { return $translation; }
		return self::apply( $translation );
	}
}

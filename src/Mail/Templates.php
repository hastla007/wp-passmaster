<?php
/**
 * Stored template overrides: option `wpcap_mail_templates` = [ type => [ locale => [subject, body, format, updated_at] ] ].
 *
 * Lookup order for a recipient locale: override for that locale, override for the site's default
 * locale, built-in default (translated into the recipient locale by the caller's locale switch).
 */
namespace PassCaptain\Mail;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Templates {
	const OPTION = 'wpcap_mail_templates';

	/** All stored overrides. */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/** Stored override for exactly this type and locale, or null. */
	public static function get( $type, $locale ) {
		$all = self::all();
		$row = $all[ (string) $type ][ (string) $locale ] ?? null;
		return is_array( $row ) ? self::normalize( $row ) : null;
	}

	/** Locales with an override for the type. */
	public static function locales( $type ) {
		$all = self::all();
		return array_keys( (array) ( $all[ (string) $type ] ?? array() ) );
	}

	/** Built-in default of a type in the current locale (subject, body, format). */
	public static function builtin( $type ) {
		$def = Mailer::type( $type );
		if ( ! $def ) { return null; }
		return array(
			'subject' => (string) call_user_func( $def['subject'] ),
			'body' => (string) call_user_func( $def['body'] ),
			'format' => 'text',
		);
	}

	/**
	 * Effective template for a recipient locale; must be called inside that locale so the built-in
	 * text is translated.
	 *
	 * @return array subject, body, format, source (custom|builtin), locale (of the override).
	 */
	public static function resolve( $type, $locale = '' ) {
		$all = self::all();
		$chain = array_values( array_unique( array_filter( array( Mailer::normalize_locale( $locale ), Mailer::site_locale() ) ) ) );
		foreach ( $chain as $candidate ) {
			$row = $all[ (string) $type ][ $candidate ] ?? null;
			if ( is_array( $row ) && '' !== trim( (string) ( $row['subject'] ?? '' ) ) && '' !== trim( (string) ( $row['body'] ?? '' ) ) ) {
				return self::normalize( $row ) + array( 'source' => 'custom', 'locale' => $candidate );
			}
		}
		return (array) self::builtin( $type ) + array( 'source' => 'builtin', 'locale' => '' );
	}

	/** Stores an override (values must already be sanitised). */
	public static function save( $type, $locale, array $template ) {
		$all = self::all();
		$all[ (string) $type ][ (string) $locale ] = self::normalize( $template ) + array( 'updated_at' => gmdate( 'Y-m-d H:i:s' ) );
		update_option( self::OPTION, $all, false );
	}

	/** Removes an override (back to the built-in default / fallback). */
	public static function reset( $type, $locale ) {
		$all = self::all();
		unset( $all[ (string) $type ][ (string) $locale ] );
		if ( empty( $all[ (string) $type ] ) ) { unset( $all[ (string) $type ] ); }
		update_option( self::OPTION, $all, false );
	}

	private static function normalize( array $row ) {
		return array(
			'subject' => (string) ( $row['subject'] ?? '' ),
			'body' => (string) ( $row['body'] ?? '' ),
			'format' => 'html' === ( $row['format'] ?? '' ) ? 'html' : 'text',
		);
	}
}

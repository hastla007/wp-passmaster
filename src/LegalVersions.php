<?php
/**
 * Archive of the legal texts (terms + consumer information) per version (A568).
 *
 * An order stores the version hash of the texts the buyer was shown (Settings::terms_version()).
 * This archive keeps the full texts of every version that was ever in force, so the operator can
 * show later which wording an order was concluded under, and the confirmation mail can quote the
 * texts of exactly that version ({contract_texts}). The hash is content-addressed (same texts =
 * same version, whatever the language), so one row per version is enough; `locale` notes the
 * language it was first archived for.
 *
 * Append-only: rows are inserted (INSERT IGNORE on the version) and never updated or deleted by
 * the plugin. Versions are archived when the settings are saved, at the schema upgrade and – as a
 * safety net for texts changed outside the settings page – with every order that stores a version.
 */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LegalVersions {
	/** Versions known to be archived in this request. */
	private static $known = array();

	/** Whether the archive table exists (schema 3.10.21; deferred upgrades). */
	public static function ready() {
		return Privacy::has_table( 'wpcap_legal_versions' );
	}

	/**
	 * Archives the texts in force for a buyer locale (idempotent).
	 *
	 * @param string|null $locale Buyer locale; null = current locale.
	 * @return string Version ('' without texts).
	 */
	public static function archive( $locale = null ) {
		global $wpdb;
		$version = Settings::terms_version( $locale );
		if ( '' === $version || isset( self::$known[ $version ] ) || ! self::ready() ) { return $version; }
		$table = $wpdb->prefix . 'wpcap_legal_versions';
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT legal_version_id FROM $table WHERE version=%s", $version ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
		if ( null === $exists && '' === $wpdb->last_error ) {
			$wpdb->query( $wpdb->prepare(
				"INSERT IGNORE INTO $table (version,locale,terms_text,consumer_info_text,created_at) VALUES (%s,%s,%s,%s,%s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
				$version,
				substr( Mail\Mailer::normalize_locale( null === $locale ? Mail\Mailer::current_locale() : (string) $locale ), 0, 20 ),
				Settings::legal_text( 'terms_text', $locale ),
				Settings::legal_text( 'consumer_info_text', $locale ),
				Clock::mysql()
			) );
			if ( '' !== $wpdb->last_error ) { return $version; }
			Audit::record( 'legal_version_archived', 'settings', 0, array( 'version' => $version ) );
		}
		self::$known[ $version ] = true;
		return $version;
	}

	/** Archives the current texts of every offered language (settings saved, upgrade). */
	public static function archive_all() {
		if ( ! self::ready() ) { return array(); }
		$versions = array();
		foreach ( Settings::legal_locales() as $locale ) {
			$version = self::archive( $locale );
			if ( '' !== $version ) { $versions[ $locale ] = $version; }
		}
		return $versions;
	}

	/**
	 * Texts of a version: the archived row, else the texts in force when they still have this
	 * version (installs before the archive). Null when the version is unknown.
	 *
	 * @param string      $version Version hash of an order.
	 * @param string|null $locale  Order locale (only for the fallback to the current texts).
	 * @return array{version:string,terms_text:string,consumer_info_text:string,created_at:string,archived:bool}|null
	 */
	public static function texts( $version, $locale = null ) {
		global $wpdb;
		$version = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $version ) );
		if ( '' === $version ) { return null; }
		if ( self::ready() ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT version,terms_text,consumer_info_text,created_at FROM {$wpdb->prefix}wpcap_legal_versions WHERE version=%s", $version ) );
			if ( $row ) {
				return array( 'version' => (string) $row->version, 'terms_text' => (string) $row->terms_text, 'consumer_info_text' => (string) $row->consumer_info_text, 'created_at' => (string) $row->created_at, 'archived' => true );
			}
		}
		if ( Settings::terms_version( $locale ) === $version ) {
			return array( 'version' => $version, 'terms_text' => Settings::legal_text( 'terms_text', $locale ), 'consumer_info_text' => Settings::legal_text( 'consumer_info_text', $locale ), 'created_at' => '', 'archived' => false );
		}
		return null;
	}

	/**
	 * Archived versions, newest first (admin overview).
	 *
	 * @return object[] version, locale, created_at.
	 */
	public static function recent( $limit = 20 ) {
		global $wpdb;
		if ( ! self::ready() ) { return array(); }
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT version,locale,created_at FROM {$wpdb->prefix}wpcap_legal_versions ORDER BY legal_version_id DESC LIMIT %d", max( 1, absint( $limit ) ) ) );
	}

	/**
	 * Mail placeholder {contract_texts}: terms and consumer information of the order's version with
	 * the version line, as text and HTML. '' when the order has no version or the version is unknown.
	 * Call inside the recipient locale (headings).
	 *
	 * @param object|array $order Order row (terms_version, locale).
	 */
	public static function mail_block( $order ) {
		$order = (object) $order;
		$texts = self::texts( (string) ( $order->terms_version ?? '' ), (string) ( $order->locale ?? '' ) );
		if ( ! $texts ) { return ''; }
		$text = '';
		$html = '';
		$labels = array(
			'terms_text' => __( 'Bedingungen', 'pass-captain' ),
			'consumer_info_text' => __( 'Verbraucherinformationen', 'pass-captain' ),
		);
		foreach ( $labels as $key => $label ) {
			$body = trim( (string) $texts[ $key ] );
			if ( '' === $body ) { continue; }
			$text .= $label . "\n" . Mail\Layout::to_text( function_exists( 'wpautop' ) ? wpautop( $body ) : $body ) . "\n\n";
			$html .= '<h3>' . esc_html( $label ) . '</h3>' . wp_kses_post( function_exists( 'wpautop' ) ? wpautop( $body ) : $body );
		}
		if ( '' === $text ) { return ''; }
		return self::block( $texts['version'], $text, $html );
	}

	/** Example value of {contract_texts} for the template preview. */
	public static function sample_block() {
		return self::block( '3f2a9c1e5b7d4a6f', __( 'Bedingungen', 'pass-captain' ) . "\n…\n\n", '<h3>' . esc_html__( 'Bedingungen', 'pass-captain' ) . '</h3><p>…</p>' );
	}

	/** {contract_texts} value: version line and texts, starting with a blank line (follows the ticket list). */
	private static function block( $version, $text, $html ) {
		/* translators: %s: version identifier of the legal texts */
		$line = sprintf( __( 'Diese Texte galten bei deiner Bestellung (Fassung %s).', 'pass-captain' ), $version );
		return Mail\Mailer::block( "\n\n" . $line . "\n\n" . rtrim( $text ) . "\n", '<p>' . esc_html( $line ) . '</p>' . $html );
	}
}

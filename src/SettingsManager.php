<?php
/**
 * Settings tools on top of the register (SettingsSchema): change log with old/new values (SET-PC-13),
 * reset to defaults (SET-PC-11) and JSON export/import with preview (SET-PC-10). WP-CLI uses the same
 * functions (SettingsCli, SET-PC-12).
 *
 * Change log: every Settings::update() that changes a value writes one audit entry "settings_changed"
 * with the source (page, module, cli, import, preset, reset, api, code) and old/new per key. Secrets are
 * never logged (only "changed"/"removed"); long texts and lists are logged as a short SHA-256 checksum
 * with their length, so the log proves a change without copying whole legal texts.
 *
 * Export: plugin and schema version, every setting except secrets, the uninstall choice
 * (delete_data_on_uninstall is never exported, suite standard S10) and derived values; plus the mail
 * templates. Import: checked through the register, preview first, all or nothing.
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class SettingsManager {
	/** Format name and version of the export file. */
	const FORMAT = 'pass-captain-settings';
	const FORMAT_VERSION = 1;

	/** Sources of a change, shown in the history. */
	const SOURCES = array( 'page', 'module', 'cli', 'import', 'preset', 'reset', 'api', 'code' );

	/** Keys that are never exported, imported or reset (derived or protected by a confirmation). */
	const PROTECTED_KEYS = array( 'delete_data_on_uninstall', 'client_ip_header' );

	/** Longer texts are logged as a checksum. */
	const LOG_TEXT_MAX = 120;

	/** @var string Source of the changes being saved (see with_source()). */
	private static $source = '';

	/**
	 * Runs $callback with $source as the origin of every settings change it makes.
	 *
	 * @param string   $source   One of SOURCES.
	 * @param callable $callback Work that saves settings.
	 * @return mixed The callback's result.
	 */
	public static function with_source( $source, callable $callback ) {
		$previous = self::$source;
		self::$source = in_array( $source, self::SOURCES, true ) ? $source : 'code';
		try {
			return $callback();
		} finally {
			self::$source = $previous;
		}
	}

	/** Source of the change being saved: explicit, else the admin screen or code. */
	public static function current_source() {
		if ( '' !== self::$source ) { return self::$source; }
		if ( defined( 'WP_CLI' ) && WP_CLI ) { return 'cli'; }
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) { return 'api'; }
		if ( function_exists( 'is_admin' ) && is_admin() ) {
			$page = isset( $_REQUEST['page'] ) && is_scalar( $_REQUEST['page'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only labels the source of an already verified save.
			return 'pass-captain-settings' === $page || '' === $page ? 'page' : 'module';
		}
		return 'code';
	}

	/* ------------------------------------------------------------------ Change log (SET-PC-13) */

	/**
	 * Writes the change log entry for one Settings::update() (called by Settings).
	 *
	 * @param array $before  Stored values before the update (no provider secrets).
	 * @param array $values  Values that were saved (secrets included as submitted).
	 * @param array $secrets Secret keys that were set ('' = removed): key => bool removed.
	 */
	public static function log_update( array $before, array $values, array $secrets = array() ) {
		$changes = array();
		foreach ( $values as $key => $new ) {
			$key = (string) $key;
			if ( 'client_ip_header' === $key ) { continue; } // Derived from trusted_proxy_header.
			if ( Settings::is_secret( $key ) ) { continue; }
			$schema_entry = SettingsSchema::get( $key );
			if ( $schema_entry && $schema_entry['secret'] ) { $secrets[ $key ] = '' === (string) ( is_scalar( $new ) ? $new : '' ); continue; } // Logged without value.
			$old = $before[ $key ] ?? null;
			if ( self::same( $old, $new ) ) { continue; }
			$changes[] = array( 'key' => $key, 'old' => self::log_value( $key, $old ), 'new' => self::log_value( $key, $new ) );
		}
		foreach ( $secrets as $key => $removed ) {
			$changes[] = array( 'key' => (string) $key, 'secret' => true, 'new' => $removed ? 'removed' : 'changed' );
		}
		if ( ! $changes ) { return; }
		Audit::record( 'settings_changed', 'settings', 0, array( 'source' => self::current_source(), 'changes' => $changes ) );
	}

	/** Loose comparison that treats 1/'1'/true alike and compares lists structurally. */
	private static function same( $a, $b ) {
		if ( is_array( $a ) || is_array( $b ) ) { return wp_json_encode( $a ) === wp_json_encode( $b ); }
		return (string) ( is_bool( $a ) ? (int) $a : $a ) === (string) ( is_bool( $b ) ? (int) $b : $b );
	}

	/** Value as written to the log: short scalars as they are, longer texts and lists as a checksum. */
	public static function log_value( $key, $value ) {
		if ( null === $value ) { return null; }
		if ( is_bool( $value ) ) { return (int) $value; }
		if ( is_scalar( $value ) && strlen( (string) $value ) <= self::LOG_TEXT_MAX && false === strpos( (string) $value, "\n" ) ) { return $value; }
		$text = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
		return array( 'sha256' => substr( hash( 'sha256', $text ), 0, 16 ), 'length' => strlen( $text ) );
	}

	/**
	 * History of settings changes, newest first.
	 *
	 * @param array $args key (only changes of this key), limit, offset.
	 * @return array[] {log_id, created_at, user_id, source, changes[]}
	 */
	public static function history( array $args = array() ) {
		global $wpdb;
		$limit = max( 1, min( 500, (int) ( $args['limit'] ?? 50 ) ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$key = isset( $args['key'] ) ? sanitize_key( (string) $args['key'] ) : '';
		$sql = "SELECT audit_id,user_id,details,created_at FROM {$wpdb->prefix}wpcap_audit_logs WHERE action='settings_changed'";
		$params = array();
		if ( '' !== $key ) {
			$sql .= ' AND details LIKE %s';
			$params[] = '%' . $wpdb->esc_like( '"key":"' . $key . '"' ) . '%';
		}
		$sql .= ' ORDER BY audit_id DESC LIMIT %d OFFSET %d';
		$params[] = $limit;
		$params[] = $offset;
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from fixed parts and placeholders.
		$out = array();
		foreach ( (array) $rows as $row ) {
			$details = json_decode( (string) $row->details, true );
			$details = is_array( $details ) ? $details : array();
			$changes = array_values( array_filter( (array) ( $details['changes'] ?? array() ), static function ( $c ) use ( $key ) {
				return is_array( $c ) && ( '' === $key || ( $c['key'] ?? '' ) === $key );
			} ) );
			$out[] = array( 'log_id' => (int) $row->audit_id, 'created_at' => (string) $row->created_at, 'user_id' => (int) $row->user_id, 'source' => (string) ( $details['source'] ?? '' ), 'changes' => $changes );
		}
		return $out;
	}

	/** Readable value of a log entry (checksum, secret marker, flags). */
	public static function display_value( $value ) {
		if ( null === $value ) { return '—'; }
		if ( is_array( $value ) && isset( $value['sha256'] ) ) {
			/* translators: 1: number of characters, 2: short checksum */
			return sprintf( __( 'Text mit %1$d Zeichen (Prüfsumme %2$s)', 'pass-captain' ), (int) ( $value['length'] ?? 0 ), (string) $value['sha256'] );
		}
		if ( is_array( $value ) ) { return (string) wp_json_encode( $value ); }
		if ( 'changed' === $value ) { return __( 'geändert (geheim)', 'pass-captain' ); }
		if ( 'removed' === $value ) { return __( 'entfernt (geheim)', 'pass-captain' ); }
		return (string) $value;
	}

	/** Labels of the sources. */
	public static function source_labels() {
		return array(
			'page' => __( 'Einstellungsseite', 'pass-captain' ),
			'module' => __( 'Modulseite', 'pass-captain' ),
			'cli' => __( 'WP-CLI', 'pass-captain' ),
			'import' => __( 'Import', 'pass-captain' ),
			'preset' => __( 'Länderprofil', 'pass-captain' ),
			'reset' => __( 'Zurücksetzen', 'pass-captain' ),
			'api' => __( 'REST-API', 'pass-captain' ),
			'code' => __( 'Programmcode', 'pass-captain' ),
		);
	}

	/* ------------------------------------------------------------------ Reset (SET-PC-11) */

	/** Keys that can be reset: everything described except secrets and protected keys. */
	public static function resettable_keys() {
		$keys = array();
		foreach ( SettingsSchema::all() as $key => $entry ) {
			if ( $entry['secret'] || in_array( $key, self::PROTECTED_KEYS, true ) ) { continue; }
			$keys[] = (string) $key;
		}
		return $keys;
	}

	/**
	 * Keys of a reset scope.
	 *
	 * @param string $scope 'all', 'key:<key>', 'section:<section id>' or 'page:<page slug>'.
	 * @return string[]
	 */
	public static function scope_keys( $scope ) {
		$scope = (string) $scope;
		$resettable = self::resettable_keys();
		if ( 'all' === $scope ) { return $resettable; }
		$parts = explode( ':', $scope, 2 );
		if ( 2 !== count( $parts ) ) { return array(); }
		list( $kind, $value ) = $parts;
		$all = SettingsSchema::all();
		$out = array();
		foreach ( $resettable as $key ) {
			$entry = $all[ $key ];
			if ( ( 'key' === $kind && $key === $value ) || ( 'section' === $kind && $entry['section'] === $value ) || ( 'page' === $kind && $entry['page'] === $value ) ) { $out[] = $key; }
		}
		return $out;
	}

	/**
	 * Resets settings to their defaults: the stored value is removed, so a later change of the default
	 * applies. Secrets and the uninstall choice are never touched.
	 *
	 * @param string[] $keys Keys (others are ignored).
	 * @return string[]|\WP_Error Keys whose value changed.
	 */
	public static function reset( array $keys ) {
		$keys = array_values( array_intersect( array_map( 'strval', $keys ), self::resettable_keys() ) );
		if ( ! $keys ) { return new \WP_Error( 'wpcap_settings_reset_empty', __( 'Keine Einstellung zum Zurücksetzen ausgewählt.', 'pass-captain' ) ); }
		$current = Settings::all();
		$defaults = Settings::defaults();
		$changed = array();
		foreach ( $keys as $key ) {
			if ( ! self::same( $current[ $key ] ?? null, $defaults[ $key ] ?? null ) ) { $changed[] = $key; }
		}
		self::with_source( 'reset', static function () use ( $keys, $defaults ) {
			Settings::forget( $keys, array_intersect_key( $defaults, array_flip( $keys ) ) );
		} );
		return $changed;
	}

	/* ------------------------------------------------------------------ Export / import (SET-PC-10) */

	/** Keys that go into an export. */
	public static function exportable_keys() {
		return self::resettable_keys();
	}

	/**
	 * Export document (array; encode with wp_json_encode for a file).
	 *
	 * @return array
	 */
	public static function export() {
		$all = Settings::all();
		$settings = array();
		foreach ( self::exportable_keys() as $key ) {
			if ( array_key_exists( $key, $all ) ) { $settings[ $key ] = $all[ $key ]; }
		}
		ksort( $settings );
		return array(
			'format' => self::FORMAT,
			'format_version' => self::FORMAT_VERSION,
			'plugin_version' => defined( 'WPCAP_VERSION' ) ? WPCAP_VERSION : '',
			'db_version' => (string) get_option( 'wpcap_db_version', '' ),
			'exported_at' => gmdate( 'c', Clock::now() ),
			'settings' => $settings,
			'mail_templates' => Mail\Templates::all(),
		);
	}

	/**
	 * Reads an export file (JSON string or decoded array).
	 *
	 * @param string|array $data File content.
	 * @return array|\WP_Error The document.
	 */
	public static function parse( $data ) {
		if ( is_string( $data ) ) {
			$data = json_decode( $data, true );
		}
		if ( ! is_array( $data ) || self::FORMAT !== ( $data['format'] ?? '' ) || ! is_array( $data['settings'] ?? null ) ) {
			return new \WP_Error( 'wpcap_settings_import_format', __( 'Die Datei ist kein Export der Pass-Captain-Einstellungen.', 'pass-captain' ) );
		}
		if ( (int) ( $data['format_version'] ?? 0 ) > self::FORMAT_VERSION ) {
			return new \WP_Error( 'wpcap_settings_import_version', __( 'Die Datei stammt aus einer neueren Version von Pass Captain. Bitte aktualisiere das Plugin vor dem Import.', 'pass-captain' ) );
		}
		return $data;
	}

	/**
	 * Preview of an import: per key the current and the new value, whether it changes, and any error.
	 * Nothing is stored.
	 *
	 * @param string|array $data File content.
	 * @return array|\WP_Error {rows: [{key,label,old,new,changed,legal,error}], skipped: [key => reason], errors: int, changes: int, mail_templates: int}
	 */
	public static function preview( $data ) {
		$doc = self::parse( $data );
		if ( is_wp_error( $doc ) ) { return $doc; }
		$current = Settings::all();
		$allowed = array_flip( self::exportable_keys() );
		$rows = array();
		$skipped = array();
		$errors = 0;
		$changes = 0;
		foreach ( $doc['settings'] as $key => $value ) {
			$key = (string) $key;
			if ( ! isset( $allowed[ $key ] ) ) {
				$skipped[ $key ] = Settings::is_secret( $key ) || in_array( $key, self::PROTECTED_KEYS, true ) ? 'protected' : 'unknown';
				continue;
			}
			$entry = SettingsSchema::get( $key );
			$checked = self::check_import_value( $key, $value, $current );
			$error = is_wp_error( $checked ) ? $checked->get_error_message() : '';
			$new = is_wp_error( $checked ) ? $value : $checked;
			$changed = '' === $error && ! self::same( $current[ $key ] ?? null, $new );
			if ( $changed && null !== ( $current[ $key ] ?? null ) ) { // A stored value that only differs by normalisation (e.g. a lower-case BIC) is no change.
				$normalised = self::check_import_value( $key, $current[ $key ], $current );
				$changed = is_wp_error( $normalised ) || ! self::same( $normalised, $new );
			}
			if ( '' !== $error ) { ++$errors; }
			if ( $changed ) { ++$changes; }
			$rows[] = array(
				'key' => $key,
				'label' => '' !== $entry['label'] ? $entry['label'] : $key,
				'old' => $current[ $key ] ?? null,
				'new' => $new,
				'changed' => $changed,
				'legal' => (bool) $entry['legal'],
				'error' => $error,
			);
		}
		return array( 'rows' => $rows, 'skipped' => $skipped, 'errors' => $errors, 'changes' => $changes, 'mail_templates' => is_array( $doc['mail_templates'] ?? null ) ? count( $doc['mail_templates'] ) : 0 );
	}

	/** Checks one imported value; a group is merged into the current group. */
	private static function check_import_value( $key, $value, array $current ) {
		$entry = SettingsSchema::get( $key );
		if ( null !== $entry && 'group' === $entry['type'] ) {
			if ( ! is_array( $value ) ) { return SettingsSchema::validate( $key, $value ); }
			$checked = SettingsSchema::check_group( $key, $value );
			return is_wp_error( $checked ) ? $checked : array_merge( is_array( $current[ $key ] ?? null ) ? $current[ $key ] : array(), $checked );
		}
		if ( null !== $entry && in_array( $entry['type'], array( 'providers', 'modules', 'list' ), true ) && is_array( $value ) ) {
			return SettingsSchema::validate_complete( $key, array_values( array_filter( $value, 'is_scalar' ) ) );
		}
		if ( null !== $entry && 'bool' === $entry['type'] ) { return empty( $value ) ? 0 : 1; }
		// Money is exported in minor units; a digit-only string (older stored values) is minor units too.
		if ( null !== $entry && 'money' === $entry['type'] && is_string( $value ) && preg_match( '/^\d+$/', trim( $value ) ) ) { $value = (int) trim( $value ); }
		return SettingsSchema::validate_complete( $key, $value );
	}

	/**
	 * Imports an export file: all values are checked first; with any error nothing is stored.
	 *
	 * @param string|array $data      File content.
	 * @param bool         $templates Also import the mail templates.
	 * @return array|\WP_Error {changed: string[], mail_templates: int}
	 */
	public static function import( $data, $templates = true ) {
		$preview = self::preview( $data );
		if ( is_wp_error( $preview ) ) { return $preview; }
		if ( $preview['errors'] > 0 ) {
			$messages = array_values( array_filter( array_column( $preview['rows'], 'error' ) ) );
			return new \WP_Error( 'wpcap_settings_import_invalid', implode( ' ', $messages ), array( 'messages' => $messages ) );
		}
		$values = array();
		foreach ( $preview['rows'] as $row ) {
			if ( $row['changed'] ) { $values[ $row['key'] ] = $row['new']; }
		}
		$doc = self::parse( $data );
		$template_count = 0;
		self::with_source( 'import', static function () use ( $values, $templates, $doc, &$template_count ) {
			if ( $values ) { Settings::update( $values ); }
			if ( $templates && is_array( $doc['mail_templates'] ?? null ) ) {
				$template_count = Mail\Templates::import( $doc['mail_templates'] );
			}
		} );
		Audit::record( 'settings_imported', 'settings', 0, array( 'keys' => array_keys( $values ), 'plugin_version' => (string) ( $doc['plugin_version'] ?? '' ), 'mail_templates' => $template_count ) );
		return array( 'changed' => array_keys( $values ), 'mail_templates' => $template_count );
	}
}

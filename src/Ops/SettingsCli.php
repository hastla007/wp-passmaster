<?php
/**
 * WP-CLI for the settings register (SET-PC-12):
 *
 *   wp pass-captain settings list [--search=<text>] [--changed] [--format=<table|json|csv>]
 *   wp pass-captain settings get <key> [--format=<plain|json>]
 *   wp pass-captain settings set <key> <value> [--json] [--confirm=<word>]
 *   wp pass-captain settings reset (<key>... | --section=<id> | --page=<slug> | --all) [--yes]
 *   wp pass-captain settings export [--file=<path>]
 *   wp pass-captain settings import <file> [--dry-run] [--no-mail-templates]
 *   wp pass-captain settings preset <DE|AT|CH|EU|OTHER> [--apply] [--yes]
 *
 * Every value goes through SettingsSchema (reported, never clamped); changes are logged with source "cli".
 * Secrets can only be set, never read. delete_data_on_uninstall is switched on only with
 * --confirm=LÖSCHEN (also LOESCHEN or DELETE), switched off without a word (suite standard S10).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Ops;

use PassCaptain\Settings;
use PassCaptain\SettingsManager;
use PassCaptain\SettingsSchema;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class SettingsCli {
	/** Words that confirm switching on data deletion at uninstall. */
	const DELETE_WORDS = array( 'LÖSCHEN', 'LOESCHEN', 'DELETE' );

	public static function register() {
		if ( ! class_exists( '\WP_CLI' ) ) { return; }
		\WP_CLI::add_command( 'pass-captain settings', __CLASS__, array( 'shortdesc' => 'Lists, reads, changes, resets, exports and imports Pass Captain settings.' ) );
	}

	/* ------------------------------------------------------------------ Testable core */

	/**
	 * Rows for "list".
	 *
	 * @return array[] key, label, value, default, source, changed, legal
	 */
	public static function list_rows( $search = '', $only_changed = false ) {
		$rows = array();
		foreach ( \PassCaptain\Admin\SettingsTools::overview_rows( $search ) as $row ) {
			if ( $only_changed && ! $row['changed'] ) { continue; }
			$rows[] = array( 'key' => $row['key'], 'label' => $row['label'], 'value' => $row['value'], 'default' => $row['default'], 'source' => $row['source'], 'changed' => $row['changed'] ? 'yes' : '', 'legal' => $row['legal'] ? 'yes' : '' );
		}
		return $rows;
	}

	/**
	 * Value of one setting (secrets only as status).
	 *
	 * @return mixed|\WP_Error
	 */
	public static function get_value( $key ) {
		$entry = SettingsSchema::get( $key );
		if ( null === $entry ) { return SettingsSchema::validate( $key, null ); }
		if ( $entry['secret'] ) { return '' !== (string) Settings::secret( $key ) ? 'set' : 'not set'; }
		return Settings::get( $key );
	}

	/**
	 * Sets one setting after checking it through the register.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $value   Raw value (string from the shell or decoded JSON).
	 * @param string $confirm Confirmation word (only for delete_data_on_uninstall).
	 * @return mixed|\WP_Error Stored value.
	 */
	public static function set_value( $key, $value, $confirm = '' ) {
		$entry = SettingsSchema::get( $key );
		if ( null === $entry ) { return SettingsSchema::validate( $key, $value ); }
		if ( 'client_ip_header' === $key ) {
			return new \WP_Error( 'wpcap_settings_derived', __( 'Dieser Wert wird aus „Header mit der Client-Adresse“ abgeleitet und kann nicht direkt gesetzt werden.', 'pass-captain' ) );
		}
		if ( 'delete_data_on_uninstall' === $key ) {
			$on = ! empty( $value ) && ! in_array( strtolower( (string) $value ), array( 'no', 'off', 'false', '0' ), true );
			if ( $on && ! in_array( strtoupper( trim( (string) $confirm ) ), self::DELETE_WORDS, true ) ) {
				return new \WP_Error( 'wpcap_settings_confirm', __( 'Zum Einschalten bitte mit --confirm=LÖSCHEN bestätigen: Beim Löschen des Plugins werden dann alle Daten entfernt.', 'pass-captain' ) );
			}
			$value = $on ? 1 : 0;
		}
		if ( 'bool' === $entry['type'] && is_string( $value ) ) {
			$value = in_array( strtolower( $value ), array( '1', 'yes', 'on', 'true', 'ja' ), true ) ? 1 : 0;
		}
		// Money values are read and written in minor units on the CLI (as "get" prints them).
		if ( 'money' === $entry['type'] && is_string( $value ) && preg_match( '/^\d+$/', trim( $value ) ) ) { $value = (int) trim( $value ); }
		$checked = 'group' === $entry['type'] && is_array( $value ) ? SettingsSchema::check_group( $key, $value ) : SettingsSchema::validate_complete( $key, $value );
		if ( is_wp_error( $checked ) ) { return $checked; }
		if ( 'group' === $entry['type'] ) {
			$current = Settings::get( $key );
			$checked = array_merge( is_array( $current ) ? $current : array(), $checked );
		}
		SettingsManager::with_source( 'cli', static function () use ( $key, $checked ) { Settings::update( array( $key => $checked ) ); } );
		return $entry['secret'] ? 'set' : Settings::get( $key );
	}

	/* ------------------------------------------------------------------ Commands */

	/**
	 * Lists all settings with value, default and source.
	 *
	 * ## OPTIONS
	 *
	 * [--search=<text>]
	 * : Only settings whose key, label, help text or value contains the text.
	 *
	 * [--changed]
	 * : Only settings that differ from their default.
	 *
	 * [--format=<format>]
	 * : table, json or csv.
	 * ---
	 * default: table
	 * ---
	 *
	 * @subcommand list
	 */
	public function list_( $args, $assoc ) {
		$rows = self::list_rows( (string) ( $assoc['search'] ?? '' ), ! empty( $assoc['changed'] ) );
		\WP_CLI\Utils\format_items( (string) ( $assoc['format'] ?? 'table' ), $rows, array( 'key', 'value', 'default', 'source', 'changed', 'legal' ) );
	}

	/**
	 * Shows one setting.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Setting key.
	 *
	 * [--format=<format>]
	 * : plain or json.
	 * ---
	 * default: plain
	 * ---
	 */
	public function get( $args, $assoc ) {
		$value = self::get_value( (string) ( $args[0] ?? '' ) );
		if ( is_wp_error( $value ) ) { \WP_CLI::error( $value->get_error_message() ); }
		\WP_CLI::line( 'json' === ( $assoc['format'] ?? '' ) || is_array( $value ) ? (string) wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) : (string) $value );
	}

	/**
	 * Changes one setting (checked like on the settings screen).
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Setting key.
	 *
	 * <value>
	 * : New value.
	 *
	 * [--json]
	 * : Read the value as JSON (lists and grouped settings).
	 *
	 * [--confirm=<word>]
	 * : LÖSCHEN to switch on data deletion at uninstall.
	 */
	public function set( $args, $assoc ) {
		$value = (string) ( $args[1] ?? '' );
		if ( ! empty( $assoc['json'] ) ) {
			$value = json_decode( $value, true );
			if ( null === $value ) { \WP_CLI::error( 'Invalid JSON.' ); }
		}
		$result = self::set_value( (string) ( $args[0] ?? '' ), $value, (string) ( $assoc['confirm'] ?? '' ) );
		if ( is_wp_error( $result ) ) { \WP_CLI::error( $result->get_error_message() ); }
		\WP_CLI::success( is_array( $result ) ? (string) wp_json_encode( $result, JSON_UNESCAPED_UNICODE ) : (string) $result );
	}

	/**
	 * Resets settings to their defaults (secrets and the uninstall choice are never reset).
	 *
	 * ## OPTIONS
	 *
	 * [<key>...]
	 * : Keys to reset.
	 *
	 * [--section=<id>]
	 * : Every setting of a section of the settings screen.
	 *
	 * [--page=<slug>]
	 * : Every setting of a module page.
	 *
	 * [--all]
	 * : Every setting.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 */
	public function reset( $args, $assoc ) {
		$keys = array();
		if ( ! empty( $assoc['all'] ) ) {
			$keys = SettingsManager::scope_keys( 'all' );
		} elseif ( ! empty( $assoc['section'] ) ) {
			$keys = SettingsManager::scope_keys( 'section:' . sanitize_key( (string) $assoc['section'] ) );
		} elseif ( ! empty( $assoc['page'] ) ) {
			$keys = SettingsManager::scope_keys( 'page:' . sanitize_key( (string) $assoc['page'] ) );
		} else {
			foreach ( $args as $key ) { $keys = array_merge( $keys, SettingsManager::scope_keys( 'key:' . (string) $key ) ); }
		}
		if ( ! $keys ) { \WP_CLI::error( 'No setting to reset.' ); }
		\WP_CLI::confirm( sprintf( 'Reset %d setting(s) to their defaults?', count( $keys ) ), $assoc );
		$result = SettingsManager::with_source( 'cli', static function () use ( $keys ) { return SettingsManager::reset( $keys ); } );
		if ( is_wp_error( $result ) ) { \WP_CLI::error( $result->get_error_message() ); }
		\WP_CLI::success( sprintf( '%d setting(s) changed.', count( $result ) ) );
	}

	/**
	 * Exports the settings as JSON (without secrets).
	 *
	 * ## OPTIONS
	 *
	 * [--file=<path>]
	 * : Write to this file instead of the output.
	 */
	public function export( $args, $assoc ) {
		$json = (string) wp_json_encode( SettingsManager::export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		\PassCaptain\Audit::record( 'settings_exported', 'settings', 0, array( 'source' => 'cli' ) );
		if ( empty( $assoc['file'] ) ) { \WP_CLI::line( $json ); return; }
		if ( false === file_put_contents( (string) $assoc['file'], $json ) ) { \WP_CLI::error( 'Could not write the file.' ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI output file.
		\WP_CLI::success( 'Exported to ' . $assoc['file'] );
	}

	/**
	 * Imports settings from an export file; shows the changes first.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Export file.
	 *
	 * [--dry-run]
	 * : Only show the changes.
	 *
	 * [--no-mail-templates]
	 * : Keep the current mail templates.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 */
	public function import( $args, $assoc ) {
		$content = is_readable( (string) ( $args[0] ?? '' ) ) ? (string) file_get_contents( (string) $args[0] ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local CLI input.
		$preview = SettingsManager::preview( $content );
		if ( is_wp_error( $preview ) ) { \WP_CLI::error( $preview->get_error_message() ); }
		$rows = array();
		foreach ( $preview['rows'] as $row ) {
			if ( ! $row['changed'] && '' === $row['error'] ) { continue; }
			$rows[] = array( 'key' => $row['key'], 'old' => \PassCaptain\Admin\SettingsTools::value_text( $row['key'], $row['old'] ), 'new' => '' !== $row['error'] ? 'ERROR: ' . $row['error'] : \PassCaptain\Admin\SettingsTools::value_text( $row['key'], $row['new'] ), 'legal' => $row['legal'] ? 'yes' : '' );
		}
		if ( $rows ) { \WP_CLI\Utils\format_items( 'table', $rows, array( 'key', 'old', 'new', 'legal' ) ); }
		if ( $preview['errors'] ) { \WP_CLI::error( sprintf( '%d invalid value(s); nothing imported.', $preview['errors'] ) ); }
		if ( ! empty( $assoc['dry-run'] ) ) { \WP_CLI::success( sprintf( '%d change(s) would be imported.', $preview['changes'] ) ); return; }
		\WP_CLI::confirm( sprintf( 'Import %d change(s)?', $preview['changes'] ), $assoc );
		$result = SettingsManager::import( $content, empty( $assoc['no-mail-templates'] ) );
		if ( is_wp_error( $result ) ) { \WP_CLI::error( $result->get_error_message() ); }
		\WP_CLI::success( sprintf( '%d setting(s) changed, %d mail template(s) imported.', count( $result['changed'] ), $result['mail_templates'] ) );
	}

	/**
	 * Shows (and with --apply applies) a country preset as a proposal with before/after.
	 *
	 * ## OPTIONS
	 *
	 * <country>
	 * : DE, AT, CH, EU or OTHER.
	 *
	 * [--apply]
	 * : Store the proposed values.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 */
	public function preset( $args, $assoc ) {
		if ( ! class_exists( \PassCaptain\CountryPresets::class ) ) { \WP_CLI::error( 'Country presets are not available.' ); }
		$country = strtoupper( (string) ( $args[0] ?? '' ) );
		$diff = \PassCaptain\CountryPresets::diff( $country );
		if ( is_wp_error( $diff ) ) { \WP_CLI::error( $diff->get_error_message() ); }
		$rows = array();
		foreach ( $diff as $row ) {
			$rows[] = array( 'key' => $row['key'], 'old' => \PassCaptain\Admin\SettingsTools::value_text( $row['key'], $row['old'] ), 'new' => \PassCaptain\Admin\SettingsTools::value_text( $row['key'], $row['new'] ), 'legal' => $row['legal'] ? 'yes' : '' );
		}
		if ( ! $rows ) { \WP_CLI::success( 'Nothing to change.' ); return; }
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'key', 'old', 'new', 'legal' ) );
		if ( empty( $assoc['apply'] ) ) { \WP_CLI::line( 'Proposal only; run again with --apply to store it.' ); return; }
		\WP_CLI::confirm( sprintf( 'Apply %d value(s) of preset %s?', count( $rows ), $country ), $assoc );
		$result = SettingsManager::with_source( 'cli', static function () use ( $country ) { return \PassCaptain\CountryPresets::apply( $country ); } );
		if ( is_wp_error( $result ) ) { \WP_CLI::error( $result->get_error_message() ); }
		\WP_CLI::success( sprintf( '%d value(s) applied. Values marked "legal" need a legal review.', count( $result ) ) );
	}
}

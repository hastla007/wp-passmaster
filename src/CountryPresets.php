<?php
/**
 * Country presets DE, AT, CH, EU and OTHER (SET-PC-02, owner decision E2/S8 of 23.11.2026).
 *
 * A preset is always a proposal: diff() shows before/after per value, apply() stores only the values the
 * person selected, with the source "preset" in the change log (SET-PC-13). Nothing is ever applied
 * automatically. Every value is checked through the settings register.
 *
 * Legal status: no preset has been legally reviewed yet. DE, AT and CH are marked "Rechts- und
 * Steuerprüfung vor dem Verkaufsstart", EU and OTHER carry neutral values marked "nicht geprüft".
 * Legally relevant values are flagged per row (SettingsSchema::LEGAL). The values are starting points
 * from the plugin's own documentation, not legal advice.
 *
 * Filter wp_pass_captain_country_presets_v1 (array code => preset) changes or adds presets; keys
 * outside Settings::defaults() are ignored.
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class CountryPresets {
	/** Preset codes in display order. */
	const CODES = array( 'DE', 'AT', 'CH', 'EU', 'OTHER' );

	/**
	 * All presets: code => {label, review (needs_review|unreviewed), note, values}.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all() {
		$presets = array(
			'DE' => array(
				'label' => __( 'Deutschland', 'pass-captain' ),
				'review' => 'needs_review',
				'note' => __( 'Werte nach deutschem Recht (BGB, UStG, GoBD) als Ausgangspunkt. Vor dem Verkaufsstart rechtlich und steuerlich prüfen lassen.', 'pass-captain' ),
				'values' => array(
					'seller_country' => 'DE',
					'default_currency' => 'EUR',
					'currencies' => 'EUR',
					'billing_required_threshold_minor' => 25000,
					'retention_financial_years' => 10,
					'retention_order_years' => 10,
					'attendee_retention_days' => 365,
					'datev_chart' => 'skr03',
					'withdrawal_notice' => 1,
					'withdrawal_notice_text' => __( 'Hinweis: Bei Tickets für Veranstaltungen mit festem Termin besteht in der Regel kein Widerrufsrecht (§ 312g Abs. 2 Nr. 9 BGB). Jede Buchung ist nach Zahlungseingang verbindlich.', 'pass-captain' ),
					'sms' => array( 'default_country_code' => '+49' ),
				),
			),
			'AT' => array(
				'label' => __( 'Österreich', 'pass-captain' ),
				'review' => 'needs_review',
				'note' => __( 'Werte nach österreichischem Recht (FAGG, UStG, BAO) als Ausgangspunkt. Vor dem Verkaufsstart rechtlich und steuerlich prüfen lassen.', 'pass-captain' ),
				'values' => array(
					'seller_country' => 'AT',
					'default_currency' => 'EUR',
					'currencies' => 'EUR',
					'billing_required_threshold_minor' => 40000,
					'retention_financial_years' => 7,
					'retention_order_years' => 7,
					'attendee_retention_days' => 365,
					'withdrawal_notice' => 1,
					'withdrawal_notice_text' => __( 'Hinweis: Bei Tickets für Freizeitveranstaltungen mit festem Termin besteht kein Rücktrittsrecht (§ 18 Abs. 1 Z 10 FAGG). Jede Buchung ist nach Zahlungseingang verbindlich.', 'pass-captain' ),
					'sms' => array( 'default_country_code' => '+43' ),
				),
			),
			'CH' => array(
				'label' => __( 'Schweiz', 'pass-captain' ),
				'review' => 'needs_review',
				'note' => __( 'Werte nach Schweizer Recht (OR, MWSTG) als Ausgangspunkt; für Online-Käufe gibt es kein allgemeines Widerrufsrecht. Vor dem Verkaufsstart rechtlich und steuerlich prüfen lassen.', 'pass-captain' ),
				'values' => array(
					'seller_country' => 'CH',
					// CHF as default; EUR stays offered (many Swiss organisers also sell in EUR).
					'default_currency' => 'CHF',
					'currencies' => 'CHF,EUR',
					// Swiss VAT: simplified invoices (without the buyer's name and address) up to CHF 400
					// (Art. 26 para. 3 MWSTG, MWSTV). Legal value (SettingsSchema::LEGAL), review flag in the preview.
					'billing_required_threshold_minor' => 40000,
					'retention_financial_years' => 10,
					'retention_order_years' => 10,
					'attendee_retention_days' => 365,
					'withdrawal_notice' => 1,
					'withdrawal_notice_text' => __( 'Hinweis: Gekaufte Tickets können nicht zurückgegeben werden, ausser die Veranstaltung wird abgesagt oder verschoben.', 'pass-captain' ),
					'sms' => array( 'default_country_code' => '+41' ),
				),
			),
			'EU' => array(
				'label' => __( 'EU allgemein', 'pass-captain' ),
				'review' => 'unreviewed',
				'note' => __( 'Neutrale Werte für ein EU-Land ohne eigenes Profil (Verbraucherrechte-Richtlinie 2011/83/EU). Nicht geprüft: Landesrecht kann abweichen.', 'pass-captain' ),
				'values' => array(
					'retention_financial_years' => 10,
					'retention_order_years' => 10,
					'attendee_retention_days' => 365,
					'withdrawal_notice' => 1,
					'withdrawal_notice_text' => __( 'Hinweis: Für Tickets zu Freizeitveranstaltungen mit festem Termin besteht in der EU in der Regel kein Widerrufsrecht (Art. 16 Buchst. l der Richtlinie 2011/83/EU).', 'pass-captain' ),
				),
			),
			'OTHER' => array(
				'label' => __( 'Anderes Land', 'pass-captain' ),
				'review' => 'unreviewed',
				'note' => __( 'Neutrale Werte ohne Bezug auf ein bestimmtes Recht. Nicht geprüft: Pflichtangaben, Fristen und Texte selbst festlegen.', 'pass-captain' ),
				'values' => array(
					'attendee_retention_days' => 365,
					'withdrawal_notice' => 1,
					'withdrawal_notice_text' => __( 'Hinweis: Bitte prüfe vor dem Kauf Datum und Ort der Veranstaltung. Die Bedingungen für Rückgabe und Erstattung findest du in den AGB.', 'pass-captain' ),
				),
			),
		);
		/**
		 * Country presets (SET-PC-02): change values of a preset or add a preset.
		 *
		 * @param array $presets code => {label, review, note, values}.
		 */
		$filtered = function_exists( 'apply_filters' ) ? apply_filters( 'wp_pass_captain_country_presets_v1', $presets ) : $presets;
		$defaults = Settings::defaults();
		$out = array();
		foreach ( is_array( $filtered ) ? $filtered : $presets as $code => $preset ) {
			$code = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $code ) );
			if ( '' === $code || ! is_array( $preset ) || ! is_array( $preset['values'] ?? null ) ) { continue; }
			$out[ $code ] = array(
				'label' => (string) ( $preset['label'] ?? $code ),
				'review' => in_array( $preset['review'] ?? '', array( 'reviewed', 'needs_review' ), true ) ? $preset['review'] : 'unreviewed',
				'note' => (string) ( $preset['note'] ?? '' ),
				'values' => array_intersect_key( $preset['values'], $defaults ),
			);
		}
		return $out;
	}

	/** Labels of the review states. */
	public static function review_labels() {
		return array(
			'reviewed' => __( 'geprüft', 'pass-captain' ),
			'needs_review' => __( 'Rechts- und Steuerprüfung vor dem Verkaufsstart', 'pass-captain' ),
			'unreviewed' => __( 'nicht geprüft', 'pass-captain' ),
		);
	}

	/**
	 * Before/after of a preset: only values that would change.
	 *
	 * @param string $code Preset code.
	 * @return array[]|\WP_Error key, label, old, new, legal, error
	 */
	public static function diff( $code ) {
		$all = self::all();
		$code = strtoupper( (string) $code );
		if ( ! isset( $all[ $code ] ) ) {
			return new \WP_Error( 'wpcap_preset_unknown', __( 'Unbekanntes Länderprofil.', 'pass-captain' ) );
		}
		$current = Settings::all();
		$rows = array();
		foreach ( $all[ $code ]['values'] as $key => $value ) {
			$entry = SettingsSchema::get( $key );
			if ( null === $entry || $entry['secret'] ) { continue; }
			if ( 'group' === $entry['type'] && is_array( $value ) ) {
				$checked = SettingsSchema::check_group( $key, $value );
				$new = is_wp_error( $checked ) ? $value : array_merge( is_array( $current[ $key ] ?? null ) ? $current[ $key ] : array(), $checked );
			} else {
				$checked = SettingsSchema::validate_complete( $key, $value );
				$new = is_wp_error( $checked ) ? $value : $checked;
			}
			if ( ! is_wp_error( $checked ) && wp_json_encode( $current[ $key ] ?? null ) === wp_json_encode( $new ) ) { continue; }
			if ( ! is_wp_error( $checked ) && is_scalar( $new ) && (string) ( $current[ $key ] ?? '' ) === (string) $new ) { continue; }
			$rows[] = array(
				'key' => (string) $key,
				'label' => '' !== $entry['label'] ? $entry['label'] : (string) $key,
				'old' => $current[ $key ] ?? null,
				'new' => $new,
				'legal' => (bool) $entry['legal'],
				'error' => is_wp_error( $checked ) ? $checked->get_error_message() : '',
			);
		}
		return $rows;
	}

	/**
	 * The default currency must be one of the offered currencies after applying (both stored in one
	 * Settings::update(), so their order does not matter). An empty currency list means the built-in list.
	 *
	 * @param array $values Values about to be applied.
	 * @return true|\WP_Error
	 */
	public static function currency_conflict( array $values ) {
		if ( ! isset( $values['default_currency'] ) && ! isset( $values['currencies'] ) ) { return true; }
		$default = strtoupper( (string) ( $values['default_currency'] ?? Settings::get( 'default_currency', 'EUR' ) ) );
		$list = array_values( array_filter( array_map( 'trim', explode( ',', strtoupper( (string) ( $values['currencies'] ?? Settings::get( 'currencies', '' ) ) ) ) ) ) );
		if ( ! $list || in_array( $default, $list, true ) ) { return true; }
		return new \WP_Error(
			'wpcap_preset_currency',
			/* translators: 1: default currency code, 2: offered currency codes */
			sprintf( __( 'Die Standardwährung %1$s gehört nicht zu den angebotenen Währungen (%2$s). Übernimm Standardwährung und angebotene Währungen zusammen.', 'pass-captain' ), $default, implode( ', ', $list ) )
		);
	}

	/**
	 * Applies the selected values of a preset (all when $keys is null). Values with an error are skipped.
	 *
	 * @param string        $code Preset code.
	 * @param string[]|null $keys Keys the person selected.
	 * @return string[]|\WP_Error Applied keys.
	 */
	public static function apply( $code, $keys = null ) {
		$diff = self::diff( $code );
		if ( is_wp_error( $diff ) ) { return $diff; }
		$values = array();
		foreach ( $diff as $row ) {
			if ( '' !== $row['error'] || ( is_array( $keys ) && ! in_array( $row['key'], $keys, true ) ) ) { continue; }
			$values[ $row['key'] ] = $row['new'];
		}
		if ( ! $values ) { return array(); }
		$currency_error = self::currency_conflict( $values );
		if ( is_wp_error( $currency_error ) ) { return $currency_error; }
		SettingsManager::with_source( 'preset', static function () use ( $values ) { Settings::update( $values ); } );
		Audit::record( 'country_preset_applied', 'settings', 0, array( 'preset' => strtoupper( (string) $code ), 'keys' => array_keys( $values ) ) );
		return array_keys( $values );
	}
}

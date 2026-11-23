<?php
/**
 * Pro hint lines (SET-PC-06, owner decisions E1 = (a), S1, E8/S5 of 23.11.2026).
 *
 * A grey plain-text line at the end of a settings tab names a sold Pass Captain Pro function. It has no
 * field, no switch, no function code and no licence check; every person can hide it. When an add-on
 * registers a settings field with the same id (filter wp_pass_captain_settings_sections_v1 or the settings
 * register), the field replaces the line.
 *
 * The list is a static text list in this file. A line is only added once the function is actually sold
 * (E8/S5); until then the list stays empty and nothing is shown. Free/Pro rule 4 (docs/FREE-PREMIUM.md)
 * allows exactly this list: text only, no feature or licence code.
 *
 * Entry: id (settings key the add-on will use), tab, title, text, link.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ProHints {
	/** User meta with the hidden hint ids. */
	const META = 'wpcap_hidden_pro_hints';
	const ACTION = 'wpcap_pro_hint_hide';

	/**
	 * Hint lines of sold Pro functions (empty until a function is sold, E8/S5).
	 *
	 * @return array<int, array{id:string,tab:string,title:string,text:string,link:string}>
	 */
	public static function all() {
		return array();
	}

	/**
	 * Lines to show on a tab for the current person: not hidden and not replaced by an add-on field.
	 *
	 * @param string     $tab   Settings tab.
	 * @param array|null $hints Hint list (tests; default all()).
	 * @return array[]
	 */
	public static function for_tab( $tab, $hints = null ) {
		if ( ! current_user_can( 'manage_options' ) ) { return array(); }
		$hidden = self::hidden();
		$fields = array();
		foreach ( SettingsPage::all_sections() as $section ) {
			foreach ( array_keys( (array) ( $section['fields'] ?? array() ) ) as $key ) { $fields[ (string) $key ] = true; }
		}
		$out = array();
		foreach ( null === $hints ? self::all() : $hints as $hint ) {
			$id = (string) ( $hint['id'] ?? '' );
			if ( '' === $id || ( $hint['tab'] ?? '' ) !== $tab || in_array( $id, $hidden, true ) ) { continue; }
			if ( isset( $fields[ $id ] ) || null !== \PassCaptain\SettingsSchema::get( $id ) ) { continue; } // The add-on's field replaces the line.
			$out[] = $hint;
		}
		return $out;
	}

	/** Ids the current person has hidden. */
	public static function hidden() {
		$hidden = get_user_option( self::META );
		return is_array( $hidden ) ? array_values( array_map( 'strval', $hidden ) ) : array();
	}

	/** Hides one line for the current person. */
	public static function hide( $id ) {
		$id = sanitize_key( (string) $id );
		if ( '' === $id || ! current_user_can( 'manage_options' ) ) { return false; }
		$hidden = self::hidden();
		if ( ! in_array( $id, $hidden, true ) ) { $hidden[] = $id; }
		update_user_option( get_current_user_id(), self::META, $hidden );
		return true;
	}

	public static function handle_hide() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		Forms::verify( self::ACTION );
		$in = Forms::post();
		self::hide( (string) ( $in['hint'] ?? '' ) );
		Forms::redirect( Forms::page_url( SettingsPage::SLUG, array( 'tab' => sanitize_key( (string) ( $in['tab'] ?? '' ) ) ) ) );
	}

	/**
	 * Block "Erweiterungen" at the end of a tab (plain text, grey, hideable).
	 *
	 * @param string     $tab   Settings tab.
	 * @param array|null $hints Hint list (tests).
	 */
	public static function render( $tab, $hints = null ) {
		$lines = self::for_tab( $tab, $hints );
		if ( ! $lines ) { return; }
		echo '<div class="wpcap-pro-hints"><h2 class="screen-reader-text">' . esc_html__( 'Erweiterungen', 'pass-captain' ) . '</h2>';
		foreach ( $lines as $hint ) {
			echo '<p class="wpcap-pro-hint description">' . esc_html( (string) $hint['title'] . ': ' . (string) $hint['text'] );
			if ( '' !== (string) ( $hint['link'] ?? '' ) ) { echo ' <a href="' . esc_url( (string) $hint['link'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Mehr erfahren', 'pass-captain' ) . '</a>'; }
			echo '</p>';
			Forms::open_form( self::ACTION, null, 'class="wpcap-pro-hint-hide"' );
			Forms::hidden( 'hint', (string) $hint['id'] );
			Forms::hidden( 'tab', $tab );
			/* translators: %s: title of the hint */
			echo '<button type="submit" class="button-link" aria-label="' . esc_attr( sprintf( __( 'Hinweis „%s“ ausblenden', 'pass-captain' ), (string) $hint['title'] ) ) . '">' . esc_html__( 'Ausblenden', 'pass-captain' ) . '</button></form>';
		}
		echo '</div>';
	}
}

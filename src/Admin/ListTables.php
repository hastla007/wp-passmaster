<?php
/**
 * Shared parts of the plugin's WP_List_Table screens (A411): availability of the core class, the
 * "Einträge pro Seite" screen option, the selected bulk action, view links and the page-number field
 * of lists that cannot sit inside one form (their rows carry their own action forms).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class ListTables {
	/** Largest page size a screen option may store. */
	const PER_PAGE_MAX = 200;

	/** Whether the core list table class can be used (else the screens print a plain table with the same cells). */
	public static function available() {
		if ( class_exists( '\WP_List_Table', false ) ) { return true; }
		return defined( 'ABSPATH' ) && is_readable( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
	}

	/** Page size of a screen option value: 1 to PER_PAGE_MAX. */
	public static function clamp_per_page( $value ) {
		return max( 1, min( self::PER_PAGE_MAX, absint( $value ) ) );
	}

	/** Registers the "per_page" screen option of the current screen (load-{hook}). */
	public static function add_per_page_option( $label, $option, $default_value ) {
		if ( function_exists( 'add_screen_option' ) ) {
			add_screen_option( 'per_page', array( 'label' => $label, 'default' => (int) $default_value, 'option' => $option ) );
		}
	}

	/** Bulk action chosen in the upper or lower selector of a posted list ('' when none). */
	public static function posted_bulk_action() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- only selects the action; the caller checks capability and nonce.
		$action = isset( $_POST['action'] ) && is_string( $_POST['action'] ) && '-1' !== $_POST['action'] ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
		if ( '' === $action && isset( $_POST['action2'] ) && is_string( $_POST['action2'] ) && '-1' !== $_POST['action2'] ) { $action = sanitize_key( wp_unslash( $_POST['action2'] ) ); }
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		return $action;
	}

	/** Posted ids of the selected rows (checkbox name). */
	public static function posted_ids( $name ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read after the caller's capability and nonce check; cast to ids below.
		$raw = isset( $_POST[ $name ] ) && is_array( $_POST[ $name ] ) ? wp_unslash( $_POST[ $name ] ) : array();
		return array_values( array_unique( array_filter( array_map( 'absint', array_filter( (array) $raw, 'is_scalar' ) ) ) ) );
	}

	/**
	 * View links for WP_List_Table::get_views() (key => HTML) from [label, url, current] entries.
	 *
	 * @param array<string,array{label:string,url:string,current:bool}> $views Views.
	 * @return array<string,string>
	 */
	public static function view_links( array $views ) {
		$out = array();
		foreach ( $views as $key => $view ) {
			$out[ $key ] = '<a href="' . esc_url( $view['url'] ) . '"' . ( $view['current'] ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html( $view['label'] ) . '</a>';
		}
		return $out;
	}

	/** Views as the plain list of the fallback table (same markup as WP_List_Table::views()). */
	public static function render_views( array $views ) {
		$links = self::view_links( $views );
		if ( ! $links ) { return; }
		echo '<ul class="subsubsub">';
		$last = array_key_last( $links );
		foreach ( $links as $key => $link ) {
			echo '<li class="' . esc_attr( (string) $key ) . '">' . $link . ( $key === $last ? '' : ' |' ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in view_links().
		}
		echo '</ul><br class="clear">';
	}

	/**
	 * The core page-number field belongs to the list's form. A list without one (its rows hold their own
	 * forms) points the field at a small GET form with the list state instead (see paging_form()).
	 */
	public static function link_paging_input( $html, $form_id ) {
		return str_replace( "name='paged'", "name='paged' form='" . esc_attr( $form_id ) . "'", (string) $html );
	}

	/** GET form the page-number field submits: the page slug and the list state as hidden fields. */
	public static function paging_form( $form_id, $slug, array $args ) {
		echo '<form method="get" id="' . esc_attr( $form_id ) . '" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		Forms::hidden( 'page', $slug );
		foreach ( $args as $key => $value ) {
			if ( is_scalar( $value ) && '' !== (string) $value ) { Forms::hidden( (string) $key, (string) $value ); }
		}
		echo '</form>';
	}
}

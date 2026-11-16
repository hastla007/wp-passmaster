<?php
/**
 * "Web-Scanner" (A338): makes the browser scanner findable from the admin. The scanner itself is the
 * shortcode [wpcap_scanner] on a front-end page (login with wpcap_checkin required, never cached);
 * this page links to every page that contains it and – for users who may publish pages – creates one.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Audit;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ScannerPage {
	const SLUG = 'wpcap-scanner';
	const CAP = 'wpcap_checkin';
	/** Capability for creating the scanner page (a published WordPress page). */
	const PAGE_CAP = 'publish_pages';
	const ACTION_CREATE = 'wpcap_create_scanner_page';
	const SHORTCODES = array( 'wpcap_scanner', 'wp_pass_captain_scanner' );

	/**
	 * Published or private pages that contain the scanner shortcode.
	 *
	 * @return array[] {id, title, url}
	 */
	public static function pages() {
		global $wpdb;
		$pages = array();
		if ( ! empty( $wpdb->posts ) ) {
			$likes = array();
			foreach ( self::SHORTCODES as $shortcode ) { $likes[] = '%[' . $wpdb->esc_like( $shortcode ) . '%'; }
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID,post_title FROM {$wpdb->posts} WHERE post_type='page' AND post_status IN ('publish','private') AND (post_content LIKE %s OR post_content LIKE %s) ORDER BY ID LIMIT 10", $likes[0], $likes[1] ) );
			foreach ( (array) $rows as $row ) {
				$pages[] = array( 'id' => (int) $row->ID, 'title' => (string) $row->post_title, 'url' => function_exists( 'get_permalink' ) ? (string) get_permalink( (int) $row->ID ) : '' );
			}
		}
		/**
		 * Pages that offer the web scanner (shortcode [wpcap_scanner]); e.g. for a scanner in a custom template.
		 *
		 * @param array[] $pages {id, title, url}
		 */
		return array_values( array_filter( (array) apply_filters( 'wpcap_scanner_pages', $pages ), static function ( $page ) { return is_array( $page ) && '' !== (string) ( $page['url'] ?? '' ); } ) );
	}

	/**
	 * Creates a published page with the scanner shortcode (unless one exists). The page only works for
	 * logged-in users with wpcap_checkin; everybody else sees a login hint.
	 *
	 * @return array|\WP_Error {page_id, url, created}
	 */
	public static function create_page() {
		if ( ! current_user_can( self::PAGE_CAP ) ) { return Forms::forbidden( __( 'Keine Berechtigung, Seiten anzulegen.', 'pass-captain' ) ); }
		$existing = self::pages();
		if ( $existing ) { return array( 'page_id' => (int) $existing[0]['id'], 'url' => (string) $existing[0]['url'], 'created' => false ); }
		$page_id = wp_insert_post(
			array(
				'post_type' => 'page',
				'post_status' => 'publish',
				'post_title' => __( 'Einlass-Scanner', 'pass-captain' ),
				'post_content' => '[wpcap_scanner]',
				'comment_status' => 'closed',
				'ping_status' => 'closed',
			),
			true
		);
		if ( is_wp_error( $page_id ) || ! $page_id ) {
			return Forms::error( 'wpcap_scanner_page_failed', __( 'Die Scanner-Seite konnte nicht angelegt werden.', 'pass-captain' ), 500 );
		}
		Audit::record( 'scanner_page_created', 'page', (int) $page_id, array() );
		return array( 'page_id' => (int) $page_id, 'url' => function_exists( 'get_permalink' ) ? (string) get_permalink( (int) $page_id ) : '', 'created' => true );
	}

	public static function handle_create() {
		Forms::require_any_cap( array( self::PAGE_CAP ) );
		Forms::verify( self::ACTION_CREATE );
		$result = self::create_page();
		$message = ! is_wp_error( $result ) && empty( $result['created'] ) ? __( 'Es gibt bereits eine Scanner-Seite.', 'pass-captain' ) : __( 'Scanner-Seite angelegt.', 'pass-captain' );
		Forms::finish( $result, $message, Forms::page_url( self::SLUG ) );
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		$pages = self::pages();
		echo '<div class="wrap wpcap-admin"><h1>' . esc_html__( 'Web-Scanner', 'pass-captain' ) . '</h1>';
		Forms::render_notices();
		echo '<p>' . esc_html__( 'Der Web-Scanner läuft im Browser eines Smartphones, Tablets oder Laptops mit Kamera: anmelden, Termin wählen, QR-Codes scannen oder Hilfscode eintippen. Er braucht eine Anmeldung mit Einlassberechtigung und eine Internetverbindung.', 'pass-captain' ) . '</p>';
		if ( $pages ) {
			echo '<p>';
			foreach ( $pages as $page ) {
				if ( count( $pages ) > 1 ) {
					/* translators: %s: page title */
					$label = sprintf( __( 'Web-Scanner öffnen: %s', 'pass-captain' ), $page['title'] );
				} else {
					$label = __( 'Web-Scanner öffnen', 'pass-captain' );
				}
				echo '<a class="button button-primary" href="' . esc_url( $page['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . '</a> ';
			}
			echo '</p><p class="description">' . esc_html__( 'Tipp: Die Adresse auf den Einlassgeräten als Lesezeichen oder auf dem Startbildschirm speichern.', 'pass-captain' ) . '</p>';
		} else {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Es gibt noch keine Seite mit dem Web-Scanner (Shortcode [wpcap_scanner]).', 'pass-captain' ) . '</p></div>';
			if ( current_user_can( self::PAGE_CAP ) ) {
				Forms::open_form( self::ACTION_CREATE );
				echo '<p><button class="button button-primary" type="submit">' . esc_html__( 'Scanner-Seite anlegen', 'pass-captain' ) . '</button> <span class="description">' . esc_html__( 'Legt die veröffentlichte Seite „Einlass-Scanner“ an. Ohne Anmeldung mit Einlassberechtigung zeigt sie nur einen Anmeldehinweis.', 'pass-captain' ) . '</span></p></form>';
			} else {
				echo '<p>' . esc_html__( 'Bitte eine Administratorin oder einen Administrator bitten, eine Seite mit dem Shortcode [wpcap_scanner] anzulegen.', 'pass-captain' ) . '</p>';
			}
		}
		echo '<h2>' . esc_html__( 'Weitere Wege zum Einlass', 'pass-captain' ) . '</h2><ul class="ul-disc">';
		echo '<li><a href="' . esc_url( Forms::page_url( DevicesPage::SLUG ) ) . '">' . esc_html__( 'Scanner-App koppeln (Scanner-Geräte)', 'pass-captain' ) . '</a></li>';
		echo '<li><a href="' . esc_url( Forms::page_url( TicketsPage::SLUG ) ) . '">' . esc_html__( 'Ticket suchen und von Hand einchecken (Tickets)', 'pass-captain' ) . '</a></li>';
		echo '</ul></div>';
	}
}

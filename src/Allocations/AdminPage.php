<?php
/**
 * Admin page "Kontingente": holds/kills per occurrence with used/total, release buttons,
 * auto-release, seat holds, per-buyer limits and a usage report.
 *
 * The action methods (create(), release(), …) take unslashed POST arrays and return a result or a
 * WP_Error; the handle_*() wrappers add nonce checks and redirects.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Allocations;

use PassCaptain\Access;
use PassCaptain\Admin\Forms;
use PassCaptain\Modules\Allocations as Module;
use PassCaptain\Orders;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class AdminPage {
	const SLUG = 'wpcap-allocations';
	const CAP = 'wpcap_manage_events';

	/* ---------------------------------------------------------------------------------------------
	 * Access helpers.
	 * ------------------------------------------------------------------------------------------- */

	private static function occurrence( $occurrence_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT o.*,e.name AS event_name FROM {$wpdb->prefix}wpcap_occurrences o INNER JOIN {$wpdb->prefix}wpcap_events e ON e.event_id=o.event_id WHERE o.occurrence_id=%d", absint( $occurrence_id ) ) );
	}

	private static function can_occurrence( $occurrence ) {
		return $occurrence && Access::can_access_event( (int) $occurrence->event_id, self::CAP );
	}

	/** Hold + occurrence after an access check, or WP_Error. */
	private static function hold_for_edit( $hold_id ) {
		$hold = Holds::get( $hold_id );
		$occurrence = $hold ? self::occurrence( $hold->occurrence_id ) : null;
		if ( ! $hold || ! $occurrence ) { return Forms::error( 'wpcap_alloc_missing', __( 'Kontingent nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		if ( ! self::can_occurrence( $occurrence ) ) { return Forms::forbidden(); }
		return $hold;
	}

	/* ---------------------------------------------------------------------------------------------
	 * Actions.
	 * ------------------------------------------------------------------------------------------- */

	public static function create( array $in ) {
		$occurrence = self::occurrence( Forms::int( $in, 'occurrence_id' ) );
		if ( ! $occurrence ) { return Forms::error( 'wpcap_alloc_occurrence', __( 'Termin nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		if ( ! self::can_occurrence( $occurrence ) ) { return Forms::forbidden(); }
		return Holds::create( array(
			'occurrence_id' => (int) $occurrence->occurrence_id,
			'ticket_type_id' => Forms::int( $in, 'ticket_type_id' ),
			'name' => Forms::text( $in, 'name', 100 ),
			'kind' => Forms::choice( $in, 'kind', Holds::KINDS, 'hold' ),
			'quantity' => Forms::int( $in, 'quantity' ),
			'release_hours' => isset( $in['release_hours'] ) && '' !== (string) $in['release_hours'] ? Forms::int( $in, 'release_hours' ) : null,
			'sources' => Forms::ids_or_keys( $in, 'sources' ),
			'note' => Forms::text( $in, 'note', 255 ),
		) );
	}

	public static function release( array $in ) {
		$hold = self::hold_for_edit( Forms::int( $in, 'hold_id' ) );
		if ( is_wp_error( $hold ) ) { return $hold; }
		$all = Forms::flag( $in, 'all' );
		return Holds::release( (int) $hold->hold_id, $all ? null : Forms::int( $in, 'units' ), 'manual' );
	}

	public static function update( array $in ) {
		$hold = self::hold_for_edit( Forms::int( $in, 'hold_id' ) );
		if ( is_wp_error( $hold ) ) { return $hold; }
		$data = array( 'add' => Forms::int( $in, 'add' ) );
		if ( isset( $in['release_hours'] ) ) { $data['release_hours'] = Forms::int( $in, 'release_hours' ); }
		if ( isset( $in['name'] ) ) { $data['name'] = Forms::text( $in, 'name', 100 ); }
		if ( isset( $in['sources_present'] ) ) { $data['sources'] = Forms::ids_or_keys( $in, 'sources' ); }
		return Holds::update( (int) $hold->hold_id, $data );
	}

	/** Seats: comma/space separated seat ids or seat keys; do = add|remove. */
	public static function seats( array $in ) {
		global $wpdb;
		$hold = self::hold_for_edit( Forms::int( $in, 'hold_id' ) );
		if ( is_wp_error( $hold ) ) { return $hold; }
		if ( ! class_exists( \PassCaptain\Seating\Layouts::class ) ) { return Forms::error( 'wpcap_alloc_seating', __( 'Das Saalplan-Modul ist nicht verfügbar.', 'wp-pass-captain' ) ); }
		$link = \PassCaptain\Seating\Layouts::link( (int) $hold->occurrence_id );
		if ( ! $link ) { return Forms::error( 'wpcap_alloc_seating', __( 'Dieser Termin hat keinen Saalplan.', 'wp-pass-captain' ) ); }
		$tokens = array_filter( preg_split( '/[\s,;]+/', Forms::text( $in, 'seats', 5000 ) ) );
		$ids = array();
		foreach ( $tokens as $token ) {
			if ( ctype_digit( $token ) ) { $ids[] = absint( $token ); continue; }
			$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT seat_id FROM ' . \PassCaptain\Seating\Layouts::table( 'seats' ) . ' WHERE version_id=%d AND seat_key=%s', (int) $link->version_id, $token ) );
			if ( ! $id ) {
				/* translators: %s: seat key */
				return Forms::error( 'wpcap_alloc_seats', sprintf( __( 'Sitzplatz „%s“ nicht gefunden.', 'wp-pass-captain' ), $token ) );
			}
			$ids[] = $id;
		}
		return 'remove' === Forms::choice( $in, 'do', array( 'add', 'remove' ), 'add' ) ? SeatHolds::remove( (int) $hold->hold_id, $ids ) : SeatHolds::add( (int) $hold->hold_id, $ids );
	}

	/** Limits: event_id, limit_event, limit_type[<type_id>]. */
	public static function limits( array $in ) {
		global $wpdb;
		$event_id = Forms::int( $in, 'event_id' );
		if ( ! $event_id || ! $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$wpdb->prefix}wpcap_events WHERE event_id=%d", $event_id ) ) ) { return Forms::error( 'wpcap_alloc_event', __( 'Event nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		if ( ! Access::can_access_event( $event_id, self::CAP ) ) { return Forms::forbidden(); }
		$result = BuyerLimits::set( $event_id, 0, Forms::int( $in, 'limit_event' ) );
		if ( is_wp_error( $result ) ) { return $result; }
		foreach ( (array) ( $in['limit_type'] ?? array() ) as $type_id => $max ) {
			if ( ! is_scalar( $max ) ) { continue; }
			$result = BuyerLimits::set( $event_id, absint( $type_id ), absint( $max ) );
			if ( is_wp_error( $result ) ) { return $result; }
		}
		return true;
	}

	public static function save_settings( array $in ) {
		if ( ! current_user_can( 'manage_options' ) ) { return Forms::forbidden(); }
		Settings::update( array( 'allocations' => array(
			'auto_release_hours' => min( 8760, Forms::int( $in, 'auto_release_hours' ) ),
			'buyer_limit_by_user' => Forms::flag( $in, 'buyer_limit_by_user' ) ? 1 : 0,
		) ) );
		return true;
	}

	/* ---------------------------------------------------------------------------------------------
	 * admin-post handlers.
	 * ------------------------------------------------------------------------------------------- */

	private static function back( array $in ) {
		$occurrence_id = Forms::int( $in, 'occurrence_id' );
		return Forms::page_url( self::SLUG, $occurrence_id ? array( 'occurrence_id' => $occurrence_id ) : array() );
	}

	public static function handle_create() {
		Forms::verify( 'wpcap_alloc_create' );
		$in = Forms::post();
		Forms::finish( self::create( $in ), __( 'Kontingent angelegt.', 'wp-pass-captain' ), self::back( $in ) );
	}

	public static function handle_release() {
		Forms::verify( 'wpcap_alloc_release' );
		$in = Forms::post();
		Forms::finish( self::release( $in ), __( 'Plätze für den freien Verkauf freigegeben.', 'wp-pass-captain' ), self::back( $in ) );
	}

	public static function handle_update() {
		Forms::verify( 'wpcap_alloc_update' );
		$in = Forms::post();
		Forms::finish( self::update( $in ), __( 'Kontingent gespeichert.', 'wp-pass-captain' ), self::back( $in ) );
	}

	public static function handle_seats() {
		Forms::verify( 'wpcap_alloc_seats' );
		$in = Forms::post();
		Forms::finish( self::seats( $in ), __( 'Sitzplätze aktualisiert.', 'wp-pass-captain' ), self::back( $in ) );
	}

	public static function handle_limits() {
		Forms::verify( 'wpcap_alloc_limits' );
		$in = Forms::post();
		Forms::finish( self::limits( $in ), __( 'Limits gespeichert.', 'wp-pass-captain' ), self::back( $in ) );
	}

	public static function handle_settings() {
		Forms::verify( 'wpcap_alloc_settings' );
		$in = Forms::post();
		Forms::finish( self::save_settings( $in ), __( 'Einstellungen gespeichert.', 'wp-pass-captain' ), self::back( $in ) );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Rendering.
	 * ------------------------------------------------------------------------------------------- */

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 ); }
		echo '<div class="wrap wpcap-admin"><h1>' . esc_html__( 'Kontingente', 'wp-pass-captain' ) . '</h1>';
		Forms::render_notices();
		$occurrence = self::occurrence( Forms::query_arg( 'occurrence_id', 0 ) );
		if ( $occurrence && self::can_occurrence( $occurrence ) ) {
			self::render_occurrence( $occurrence );
		} else {
			self::render_overview();
		}
		echo '</div>';
	}

	private static function render_overview() {
		global $wpdb;
		$p = $wpdb->prefix;
		echo '<p>' . esc_html__( 'Kontingente halten Plätze für Künstler, Sponsoren, Presse oder Technik zurück. Sie sind im freien Verkauf (Website, WooCommerce, Warteliste) nicht buchbar; Abendkasse, Gästeliste und Admin können sie nutzen. Sperren (z. B. Technik) blockieren Plätze dauerhaft.', 'wp-pass-captain' ) . '</p>';
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT o.occurrence_id,o.event_id,o.starts_at,o.timezone,o.capacity,e.name AS event_name,(SELECT COUNT(*) FROM " . Holds::table( 'holds' ) . " h WHERE h.occurrence_id=o.occurrence_id AND h.status='active') AS holds FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE o.status='scheduled' AND o.starts_at >= %s ORDER BY o.starts_at LIMIT 100",
			gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
		) );
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Event', 'wp-pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Beginn', 'wp-pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Kapazität', 'wp-pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Aktive Kontingente', 'wp-pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( (array) $rows as $row ) {
			if ( ! Access::can_access_event( (int) $row->event_id, self::CAP ) ) { continue; }
			$url = Forms::page_url( self::SLUG, array( 'occurrence_id' => (int) $row->occurrence_id ) );
			echo '<tr><td><a href="' . esc_url( $url ) . '">' . esc_html( $row->event_name ) . '</a></td><td>' . esc_html( Forms::datetime( $row->starts_at, $row->timezone ) ) . '</td><td>' . esc_html( (int) $row->capacity ? (string) (int) $row->capacity : __( 'unbegrenzt', 'wp-pass-captain' ) ) . '</td><td>' . esc_html( (string) (int) $row->holds ) . '</td></tr>';
		}
		if ( ! $rows ) { echo '<tr><td colspan="4">' . esc_html__( 'Keine kommenden Termine.', 'wp-pass-captain' ) . '</td></tr>'; }
		echo '</tbody></table>';
		if ( current_user_can( 'manage_options' ) ) {
			$c = Module::config();
			echo '<h2>' . esc_html__( 'Einstellungen', 'wp-pass-captain' ) . '</h2>';
			Forms::open_form( 'wpcap_alloc_settings' );
			echo '<table class="form-table"><tbody>';
			echo '<tr><th scope="row"><label for="wpcap-alloc-hours">' . esc_html__( 'Automatische Freigabe (Stunden vor Beginn)', 'wp-pass-captain' ) . '</label></th><td><input type="number" min="0" id="wpcap-alloc-hours" name="auto_release_hours" value="' . esc_attr( (int) $c['auto_release_hours'] ) . '"><p class="description">' . esc_html__( 'Vorgabe für neue Kontingente; 0 = keine automatische Freigabe. Sperren werden nie automatisch freigegeben.', 'wp-pass-captain' ) . '</p></td></tr>';
			echo '<tr><th scope="row">' . esc_html__( 'Käuferlimit', 'wp-pass-captain' ) . '</th><td><label><input type="checkbox" name="buyer_limit_by_user" value="1"' . checked( ! empty( $c['buyer_limit_by_user'] ), true, false ) . '> ' . esc_html__( 'Zusätzlich je angemeldetem WordPress-Konto zählen (nicht nur je E-Mail-Adresse)', 'wp-pass-captain' ) . '</label></td></tr>';
			echo '</tbody></table>';
			submit_button( __( 'Speichern', 'wp-pass-captain' ) );
			echo '</form>';
		}
	}

	private static function render_occurrence( $occurrence ) {
		global $wpdb;
		$occurrence_id = (int) $occurrence->occurrence_id;
		$types = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_ticket_types WHERE occurrence_id=%d ORDER BY ticket_type_id", $occurrence_id ) );
		$type_names = array( 0 => __( 'Alle Ticketarten', 'wp-pass-captain' ) );
		foreach ( (array) $types as $type ) { $type_names[ (int) $type->ticket_type_id ] = $type->name; }
		$seated = class_exists( \PassCaptain\Seating\Layouts::class ) && \PassCaptain\Seating\Layouts::link( $occurrence_id );
		$report = Holds::report( $occurrence_id );
		\PassCaptain\Privacy\AccessLog::view( 'allocation_occurrence', 'occurrence', $occurrence_id );
		$sources = array( 'pos' => __( 'Abendkasse', 'wp-pass-captain' ), 'guestlist' => __( 'Gästeliste', 'wp-pass-captain' ), 'admin' => __( 'Admin', 'wp-pass-captain' ) );
		foreach ( Holds::trusted_sources() as $source ) { $sources[ $source ] = $sources[ $source ] ?? $source; }

		echo '<p><a href="' . esc_url( Forms::page_url( self::SLUG ) ) . '">&larr; ' . esc_html__( 'Alle Termine', 'wp-pass-captain' ) . '</a></p>';
		echo '<h2>' . esc_html( $occurrence->event_name . ' – ' . Forms::datetime( $occurrence->starts_at, $occurrence->timezone ) ) . '</h2>';
		echo '<p>' . esc_html( sprintf( /* translators: %s: capacity */ __( 'Kapazität des Termins: %s', 'wp-pass-captain' ), (int) $occurrence->capacity ? (string) (int) $occurrence->capacity : __( 'unbegrenzt', 'wp-pass-captain' ) ) ) . '</p>';
		echo '<ul class="wpcap-alloc-availability">';
		foreach ( (array) $types as $type ) {
			$available = Orders::availability( (int) $type->ticket_type_id );
			echo '<li>' . esc_html( sprintf( /* translators: 1: ticket type, 2: available units */ __( '%1$s: %2$s im freien Verkauf', 'wp-pass-captain' ), $type->name, null === $available ? __( 'unbegrenzt', 'wp-pass-captain' ) : (string) $available ) ) . '</li>';
		}
		echo '</ul>';

		echo '<h3>' . esc_html__( 'Kontingente und Sperren', 'wp-pass-captain' ) . '</h3>';
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Name', 'wp-pass-captain' ), __( 'Art', 'wp-pass-captain' ), __( 'Ticketart', 'wp-pass-captain' ), __( 'Genutzt / Gesamt', 'wp-pass-captain' ), __( 'Offen', 'wp-pass-captain' ), __( 'Nutzung nach Kanal', 'wp-pass-captain' ), __( 'Auto-Freigabe', 'wp-pass-captain' ), __( 'Status', 'wp-pass-captain' ), __( 'Aktionen', 'wp-pass-captain' ) ) as $label ) {
			echo '<th scope="col">' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $report['holds'] as $entry ) {
			$hold = $entry['hold'];
			$usage = array();
			foreach ( $entry['by_source'] as $source => $units ) { $usage[] = ( $sources[ $source ] ?? $source ) . ': ' . $units; }
			$auto = 'kill' === $hold->kind || ! (int) $hold->release_hours ? __( 'nie', 'wp-pass-captain' ) : sprintf( /* translators: %d: hours */ __( '%d h vor Beginn', 'wp-pass-captain' ), (int) $hold->release_hours );
			echo '<tr><td>' . esc_html( $hold->name ) . ( $entry['seats'] ? '<br><small>' . esc_html( implode( ', ', $entry['seats'] ) ) . '</small>' : '' ) . '</td>';
			echo '<td>' . esc_html( 'kill' === $hold->kind ? __( 'Sperre', 'wp-pass-captain' ) : __( 'Kontingent', 'wp-pass-captain' ) ) . '</td>';
			echo '<td>' . esc_html( $type_names[ (int) $hold->ticket_type_id ] ?? '#' . (int) $hold->ticket_type_id ) . '</td>';
			echo '<td>' . esc_html( 'kill' === $hold->kind ? '–' : $entry['used'] . ' / ' . $entry['total'] ) . '</td>';
			echo '<td>' . esc_html( (string) $entry['open'] ) . '</td>';
			echo '<td>' . esc_html( $usage ? implode( ', ', $usage ) : '–' ) . '</td>';
			echo '<td>' . esc_html( $auto ) . '</td>';
			echo '<td>' . esc_html( 'active' === $hold->status ? __( 'Aktiv', 'wp-pass-captain' ) : __( 'Freigegeben', 'wp-pass-captain' ) ) . '</td><td>';
			if ( 'active' === $hold->status ) {
				Forms::open_form( 'wpcap_alloc_release', null, 'class="wpcap-inline"' );
				Forms::hidden( 'hold_id', (int) $hold->hold_id );
				Forms::hidden( 'occurrence_id', $occurrence_id );
				echo '<label class="screen-reader-text" for="wpcap-rel-' . (int) $hold->hold_id . '">' . esc_html__( 'Anzahl freigeben', 'wp-pass-captain' ) . '</label><input type="number" min="1" max="' . esc_attr( (int) $entry['open'] ) . '" id="wpcap-rel-' . (int) $hold->hold_id . '" name="units" class="small-text"> ';
				echo '<button class="button" type="submit">' . esc_html__( 'Teilweise freigeben', 'wp-pass-captain' ) . '</button> ';
				echo '<button class="button" type="submit" name="all" value="1">' . esc_html__( 'Komplett freigeben', 'wp-pass-captain' ) . '</button></form>';
				Forms::open_form( 'wpcap_alloc_update', null, 'class="wpcap-inline"' );
				Forms::hidden( 'hold_id', (int) $hold->hold_id );
				Forms::hidden( 'occurrence_id', $occurrence_id );
				echo '<label>' . esc_html__( 'Erhöhen um', 'wp-pass-captain' ) . ' <input type="number" min="0" name="add" class="small-text"></label> ';
				if ( 'hold' === $hold->kind ) {
					echo '<label>' . esc_html__( 'Auto-Freigabe (h)', 'wp-pass-captain' ) . ' <input type="number" min="0" name="release_hours" class="small-text" value="' . esc_attr( (int) $hold->release_hours ) . '"></label> ';
				}
				echo '<button class="button" type="submit">' . esc_html__( 'Speichern', 'wp-pass-captain' ) . '</button></form>';
				if ( $seated ) {
					Forms::open_form( 'wpcap_alloc_seats', null, 'class="wpcap-inline"' );
					Forms::hidden( 'hold_id', (int) $hold->hold_id );
					Forms::hidden( 'occurrence_id', $occurrence_id );
					echo '<label>' . esc_html__( 'Sitzplätze (IDs oder Schlüssel)', 'wp-pass-captain' ) . ' <input type="text" name="seats" class="regular-text"></label> ';
					echo '<button class="button" type="submit" name="do" value="add">' . esc_html__( 'Sperren', 'wp-pass-captain' ) . '</button> ';
					echo '<button class="button" type="submit" name="do" value="remove">' . esc_html__( 'Freigeben', 'wp-pass-captain' ) . '</button></form>';
				}
			}
			echo '</td></tr>';
		}
		if ( ! $report['holds'] ) { echo '<tr><td colspan="9">' . esc_html__( 'Noch keine Kontingente.', 'wp-pass-captain' ) . '</td></tr>'; }
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Neues Kontingent', 'wp-pass-captain' ) . '</h3>';
		Forms::open_form( 'wpcap_alloc_create' );
		Forms::hidden( 'occurrence_id', $occurrence_id );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th scope="row"><label for="wpcap-alloc-name">' . esc_html__( 'Name', 'wp-pass-captain' ) . '</label></th><td><input type="text" id="wpcap-alloc-name" name="name" required maxlength="100" placeholder="' . esc_attr__( 'z. B. Künstler, Sponsor, Presse', 'wp-pass-captain' ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="wpcap-alloc-kind">' . esc_html__( 'Art', 'wp-pass-captain' ) . '</label></th><td>';
		Forms::select( 'kind', array( 'hold' => __( 'Kontingent (nutzbar über Kasse/Gästeliste/Admin)', 'wp-pass-captain' ), 'kill' => __( 'Sperre (dauerhaft blockiert)', 'wp-pass-captain' ) ), 'hold', 'wpcap-alloc-kind' );
		echo '</td></tr><tr><th scope="row"><label for="wpcap-alloc-type">' . esc_html__( 'Ticketart', 'wp-pass-captain' ) . '</label></th><td>';
		Forms::select( 'ticket_type_id', $type_names, 0, 'wpcap-alloc-type' );
		echo '</td></tr>';
		echo '<tr><th scope="row"><label for="wpcap-alloc-qty">' . esc_html__( 'Anzahl', 'wp-pass-captain' ) . '</label></th><td><input type="number" min="1" id="wpcap-alloc-qty" name="quantity" required class="small-text"></td></tr>';
		echo '<tr><th scope="row"><label for="wpcap-alloc-rh">' . esc_html__( 'Automatisch freigeben (Stunden vor Beginn)', 'wp-pass-captain' ) . '</label></th><td><input type="number" min="0" id="wpcap-alloc-rh" name="release_hours" class="small-text" value="' . esc_attr( (int) Module::config()['auto_release_hours'] ) . '"><p class="description">' . esc_html__( '0 = nie. Nicht genutzte Plätze gehen dann in den freien Verkauf.', 'wp-pass-captain' ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Nutzbar über', 'wp-pass-captain' ) . '</th><td>';
		foreach ( $sources as $key => $label ) {
			echo '<label><input type="checkbox" name="sources[]" value="' . esc_attr( $key ) . '"> ' . esc_html( $label ) . '</label> ';
		}
		echo '<p class="description">' . esc_html__( 'Keine Auswahl = alle vertrauenswürdigen Kanäle.', 'wp-pass-captain' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="wpcap-alloc-note">' . esc_html__( 'Notiz', 'wp-pass-captain' ) . '</label></th><td><input type="text" id="wpcap-alloc-note" name="note" class="regular-text" maxlength="255"></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Kontingent anlegen', 'wp-pass-captain' ) );
		echo '</form>';

		$limits = BuyerLimits::for_event( (int) $occurrence->event_id );
		echo '<h3>' . esc_html__( 'Max. Tickets pro Käufer', 'wp-pass-captain' ) . '</h3>';
		echo '<p>' . esc_html__( 'Gilt über alle Bestellungen (bezahlt und aktiv reserviert) je E-Mail-Adresse bzw. Benutzerkonto, auch im WooCommerce-Checkout. Abendkasse, Gästeliste und Admin sind ausgenommen. 0 = kein Limit.', 'wp-pass-captain' ) . '</p>';
		Forms::open_form( 'wpcap_alloc_limits' );
		Forms::hidden( 'event_id', (int) $occurrence->event_id );
		Forms::hidden( 'occurrence_id', $occurrence_id );
		echo '<table class="form-table"><tbody><tr><th scope="row"><label for="wpcap-limit-event">' . esc_html__( 'Ganzes Event (alle Termine)', 'wp-pass-captain' ) . '</label></th><td><input type="number" min="0" id="wpcap-limit-event" name="limit_event" class="small-text" value="' . esc_attr( (int) ( $limits[0] ?? 0 ) ) . '"></td></tr>';
		foreach ( (array) $types as $type ) {
			$id = 'wpcap-limit-type-' . (int) $type->ticket_type_id;
			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $type->name ) . '</label></th><td><input type="number" min="0" id="' . esc_attr( $id ) . '" name="limit_type[' . (int) $type->ticket_type_id . ']" class="small-text" value="' . esc_attr( (int) ( $limits[ (int) $type->ticket_type_id ] ?? 0 ) ) . '"></td></tr>';
		}
		echo '</tbody></table>';
		submit_button( __( 'Limits speichern', 'wp-pass-captain' ) );
		echo '</form>';

		echo '<h3>' . esc_html__( 'Nutzung der Kontingente', 'wp-pass-captain' ) . '</h3>';
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Kontingent', 'wp-pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Bestellung', 'wp-pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Kanal', 'wp-pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Ticketart', 'wp-pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Plätze', 'wp-pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Zurückgegeben', 'wp-pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Datum', 'wp-pass-captain' ) . '</th></tr></thead><tbody>';
		$names = array();
		foreach ( $report['holds'] as $entry ) { $names[ (int) $entry['hold']->hold_id ] = $entry['hold']->name; }
		foreach ( $report['orders'] as $row ) {
			echo '<tr><td>' . esc_html( $names[ (int) $row->hold_id ] ?? '' ) . '</td><td>' . esc_html( '#' . (int) $row->order_id . ( $row->customer_email ? ' · ' . $row->customer_email : '' ) ) . '</td><td>' . esc_html( $sources[ $row->source ] ?? $row->source ) . '</td><td>' . esc_html( $type_names[ (int) $row->ticket_type_id ] ?? '' ) . '</td><td>' . esc_html( (string) (int) $row->quantity ) . '</td><td>' . esc_html( (string) (int) $row->released ) . '</td><td>' . esc_html( Forms::datetime( $row->created_at, $occurrence->timezone, false ) ) . '</td></tr>';
		}
		if ( ! $report['orders'] ) { echo '<tr><td colspan="7">' . esc_html__( 'Noch keine Nutzung.', 'wp-pass-captain' ) . '</td></tr>'; }
		echo '</tbody></table>';
	}
}

<?php
/**
 * Form Captain form per event: storage in Pass Captain's own table `wpcap_event_forms`, the admin
 * section on the event screen and its admin-post handler.
 *
 * Form Captain has no list function in its contract, so the form is chosen by its ID or public ID
 * and checked with `form_captain_get_form_v1()`; only published forms can be assigned. Form Captain
 * tables are never read or written.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Integrations\FormCaptain;

use PassCaptain\Access;
use PassCaptain\Admin\EventsPage;
use PassCaptain\Admin\Forms;
use PassCaptain\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Pass Captain's own tables; results are cached per request where repeated.

/**
 * Event ⇄ form assignment.
 *
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class EventForms {
	const TABLE  = 'wpcap_event_forms';
	const ACTION = 'wpcap_form_captain_event_form';

	/**
	 * Per-request cache: event ID => assignment row or null.
	 *
	 * @var array<int,array<string,mixed>|null>
	 */
	private static $by_event = array();

	/**
	 * Per-request cache: form ID => assigned to any event.
	 *
	 * @var array<int,bool>
	 */
	private static $by_form = array();

	/**
	 * CREATE TABLE statement (dbDelta format) for the module schema.
	 *
	 * @param string $prefix  Table prefix.
	 * @param string $charset Charset/collation clause.
	 * @return string[]
	 */
	public static function schema( $prefix, $charset ) {
		return array(
			"CREATE TABLE {$prefix}" . self::TABLE . " (
				event_id bigint(20) unsigned NOT NULL,
				form_id bigint(20) unsigned NOT NULL,
				form_public_id varchar(64) NOT NULL DEFAULT '',
				form_title varchar(190) NOT NULL DEFAULT '',
				updated_at datetime NOT NULL,
				PRIMARY KEY  (event_id),
				KEY form_id (form_id)
			) ENGINE=InnoDB $charset;",
		);
	}

	/**
	 * Assignment of an event or null.
	 *
	 * @param int $event_id Event ID.
	 * @return array{event_id:int,form_id:int,form_public_id:string,form_title:string}|null
	 */
	/**
	 * Loads the assignments of several events with one query (listing hook `wpcap_listing_occurrences`),
	 * so the cards of a listing do not query one by one.
	 *
	 * @param int[] $occurrence_ids Unused (hook signature).
	 * @param int[] $event_ids      Event IDs of the listing.
	 */
	public static function prime( $occurrence_ids = array(), $event_ids = array() ) {
		unset( $occurrence_ids );
		$ids = array_values( array_diff( array_unique( array_filter( array_map( 'absint', (array) $event_ids ) ) ), array_keys( self::$by_event ) ) );
		if ( ! $ids ) {
			return;
		}
		global $wpdb;
		foreach ( $ids as $id ) {
			self::$by_event[ $id ] = null;
		}
		$rows = $wpdb->get_results( "SELECT event_id,form_id,form_public_id,form_title FROM {$wpdb->prefix}wpcap_event_forms WHERE event_id IN (" . implode( ',', $ids ) . ')', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- integer list.
		foreach ( (array) $rows as $row ) {
			self::$by_event[ (int) $row['event_id'] ] = array(
				'event_id'       => (int) $row['event_id'],
				'form_id'        => (int) $row['form_id'],
				'form_public_id' => (string) $row['form_public_id'],
				'form_title'     => (string) $row['form_title'],
			);
		}
	}

	public static function get( $event_id ) {
		$event_id = absint( $event_id );
		if ( ! $event_id ) {
			return null;
		}
		if ( ! array_key_exists( $event_id, self::$by_event ) ) {
			global $wpdb;
			$row                         = $wpdb->get_row( $wpdb->prepare( "SELECT event_id,form_id,form_public_id,form_title FROM {$wpdb->prefix}wpcap_event_forms WHERE event_id=%d", $event_id ), ARRAY_A );
			self::$by_event[ $event_id ] = $row ? array(
				'event_id'       => (int) $row['event_id'],
				'form_id'        => (int) $row['form_id'],
				'form_public_id' => (string) $row['form_public_id'],
				'form_title'     => (string) $row['form_title'],
			) : null;
		}
		return self::$by_event[ $event_id ];
	}

	/**
	 * True if the form is assigned to at least one event.
	 *
	 * @param int $form_id Internal Form Captain form ID.
	 */
	public static function is_assigned( $form_id ) {
		$form_id = absint( $form_id );
		if ( ! $form_id ) {
			return false;
		}
		if ( ! isset( self::$by_form[ $form_id ] ) ) {
			global $wpdb;
			self::$by_form[ $form_id ] = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->prefix}wpcap_event_forms WHERE form_id=%d LIMIT 1", $form_id ) );
		}
		return self::$by_form[ $form_id ];
	}

	/** Clears the per-request caches. */
	public static function flush_cache() {
		self::$by_event = array();
		self::$by_form  = array();
	}

	/**
	 * Assigns a published Form Captain form to an event or removes the assignment (empty `form`).
	 * Capability and event scope are checked here.
	 *
	 * @param array<string,mixed> $in Unslashed input: event_id, form (ID or public ID).
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function save( array $in ) {
		$event_id = Forms::int( $in, 'event_id' );
		global $wpdb;
		$event = $wpdb->get_row( $wpdb->prepare( "SELECT event_id,status FROM {$wpdb->prefix}wpcap_events WHERE event_id=%d", $event_id ) );
		if ( ! $event ) {
			return Forms::error( 'wpcap_event_missing', __( 'Event nicht gefunden.', 'pass-captain' ), 404 );
		}
		if ( ! Access::can_access_event( $event_id, EventsPage::CAP ) ) {
			return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ) );
		}
		if ( 'cancelled' === $event->status ) {
			return Forms::error( 'wpcap_event_cancelled', __( 'Abgesagte Events können nicht mehr bearbeitet werden.', 'pass-captain' ), 409 );
		}
		$ref = Forms::text( $in, 'form', 64 );
		$old = self::get( $event_id );
		if ( '' === $ref ) {
			$wpdb->delete( $wpdb->prefix . self::TABLE, array( 'event_id' => $event_id ), array( '%d' ) );
			self::flush_cache();
			if ( $old ) {
				Audit::record( 'event_form_removed', 'event', $event_id, array( 'form_id' => $old['form_id'] ) );
			}
			return array(
				'event_id' => $event_id,
				'form_id'  => 0,
			);
		}
		if ( ! Adapter::compatible() || ! function_exists( 'form_captain_get_form_v1' ) ) {
			return Forms::error( 'wpcap_form_captain_unavailable', __( 'Form Captain ist nicht aktiv oder hat eine nicht unterstützte Vertragsversion.', 'pass-captain' ), 409 );
		}
		if ( ! preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $ref ) ) {
			return Forms::error( 'wpcap_form_invalid', __( 'Bitte gib die Formular-ID oder die öffentliche ID eines Form-Captain-Formulars an.', 'pass-captain' ) );
		}
		$form = form_captain_get_form_v1( ctype_digit( $ref ) ? (int) $ref : $ref );
		if ( is_wp_error( $form ) || ! is_array( $form ) || empty( $form['id'] ) ) {
			return Forms::error( 'wpcap_form_missing', __( 'Dieses Formular wurde nicht gefunden.', 'pass-captain' ), 404 );
		}
		if ( 'published' !== ( $form['status'] ?? '' ) ) {
			return Forms::error( 'wpcap_form_not_published', __( 'Nur veröffentlichte Formulare können einem Event zugeordnet werden.', 'pass-captain' ) );
		}
		$row = array(
			'event_id'       => $event_id,
			'form_id'        => absint( $form['id'] ),
			'form_public_id' => substr( sanitize_key( (string) ( $form['public_id'] ?? '' ) ), 0, 64 ),
			'form_title'     => mb_substr( sanitize_text_field( (string) ( $form['title'] ?? '' ) ), 0, 190 ),
			'updated_at'     => \PassCaptain\Clock::mysql(),
		);
		if ( $old ) {
			$wpdb->update( $wpdb->prefix . self::TABLE, $row, array( 'event_id' => $event_id ), array( '%d', '%d', '%s', '%s', '%s' ), array( '%d' ) );
		} else {
			$wpdb->insert( $wpdb->prefix . self::TABLE, $row, array( '%d', '%d', '%s', '%s', '%s' ) );
		}
		self::flush_cache();
		Audit::record(
			'event_form_assigned',
			'event',
			$event_id,
			array(
				'form_id'          => $row['form_id'],
				'previous_form_id' => $old ? $old['form_id'] : 0,
			)
		);
		return array(
			'event_id' => $event_id,
			'form_id'  => $row['form_id'],
			'title'    => $row['form_title'],
		);
	}

	/** Admin-post wrapper: nonce, save, redirect back to the event. */
	public static function handle_save() {
		$in       = Forms::post();
		$event_id = Forms::int( $in, 'event_id' );
		$back     = Forms::page_url(
			EventsPage::SLUG,
			array(
				'view'     => 'event',
				'event_id' => $event_id,
			)
		);
		if ( ! current_user_can( EventsPage::CAP ) ) { Forms::finish( Forms::forbidden(), '', $back ); }
		Forms::verify( self::ACTION );
		$result   = self::save( $in );
		$message  = ! is_wp_error( $result ) && empty( $result['form_id'] ) ? __( 'Formularzuordnung entfernt.', 'pass-captain' ) : __( 'Formular zugeordnet.', 'pass-captain' );
		Forms::finish( $result, $message, $back );
	}

	/**
	 * Section on the event screen (action `wpcap_admin_event_sections`). Shows nothing while Form
	 * Captain is inactive and no form is assigned; a notice when an assigned form cannot be shown.
	 *
	 * @param object $event    Event row.
	 * @param bool   $editable Event can still be edited (not cancelled).
	 */
	public static function render_section( $event, $editable = true ) {
		if ( ! is_object( $event ) || empty( $event->event_id ) || ! Access::can_access_event( (int) $event->event_id, EventsPage::CAP ) ) {
			return;
		}
		$assigned = self::get( (int) $event->event_id );
		$state    = Adapter::state();
		$requests = EventRequests::count_for_event( (int) $event->event_id );
		if ( 'missing' === $state && ! $assigned && ! $requests ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Formular (Form Captain)', 'pass-captain' ) . '</h2>';
		if ( 'missing' === $state && $assigned ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Form Captain ist nicht aktiv. Das zugeordnete Formular wird auf der Eventseite nicht angezeigt.', 'pass-captain' ) . '</p></div>';
		} elseif ( 'incompatible' === $state ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Die aktive Form-Captain-Version hat eine nicht unterstützte Vertragsversion. Formulare werden auf Eventseiten nicht angezeigt, bis Pass Captain oder Form Captain aktualisiert ist.', 'pass-captain' ) . '</p></div>';
		} elseif ( 'error' === $state ) {
			/* translators: %s: error code */
			echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( __( 'Form Captain hat den Kontextzweck für Eventformulare abgelehnt (%s). Formulare werden auf Eventseiten nicht angezeigt.', 'pass-captain' ), Adapter::error_code() ) ) . '</p></div>';
		}
		echo '<p class="description">' . esc_html__( 'Für Anfragen, Bewerbungen oder freiwillige Zusatzangaben. Eine Einsendung ist keine Buchung: Es entstehen keine Reservierung, keine Zahlung und kein Ticket.', 'pass-captain' ) . '</p>';
		if ( $assigned ) {
			$label = '' !== $assigned['form_title'] ? $assigned['form_title'] : '#' . $assigned['form_id'];
			/* translators: 1: form title, 2: form ID */
			echo '<p>' . esc_html( sprintf( __( 'Zugeordnet: %1$s (ID %2$d)', 'pass-captain' ), $label, $assigned['form_id'] ) ) . '</p>';
		}
		if ( $editable && Adapter::compatible() ) {
			self::render_assignment_form( $event, $assigned );
		}
		if ( $requests ) {
			EventRequests::render_list( $event, $editable );
		}
	}

	/**
	 * Field to assign or remove the form.
	 *
	 * @param object     $event    Event row.
	 * @param array|null $assigned Current assignment.
	 */
	private static function render_assignment_form( $event, $assigned ) {
		Forms::open_form( self::ACTION );
		Forms::hidden( 'event_id', (int) $event->event_id );
		echo '<p><label for="wpcap-ev-fc-form">' . esc_html__( 'Formular-ID oder öffentliche ID', 'pass-captain' ) . '</label> ';
		echo '<input id="wpcap-ev-fc-form" name="form" type="text" class="regular-text" maxlength="64" pattern="[A-Za-z0-9_\-]*" value="' . esc_attr( $assigned ? (string) $assigned['form_id'] : '' ) . '" aria-describedby="wpcap-ev-fc-form-hint"> ';
		echo '<button class="button" type="submit">' . esc_html__( 'Formular speichern', 'pass-captain' ) . '</button></p>';
		echo '<p class="description" id="wpcap-ev-fc-form-hint">' . esc_html__( 'Nur veröffentlichte Formulare. Leer lassen und speichern entfernt die Zuordnung.', 'pass-captain' ) . '</p>';
		echo '</form>';
	}
}

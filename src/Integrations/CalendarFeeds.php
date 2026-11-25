<?php
/**
 * Tokenized iCalendar subscription feeds (calendar connector): one feed per event, per organizer
 * (partner) or for all events, subscribable in Google Calendar, Outlook, Apple Calendar, CRM or
 * intranet calendars via a secret URL (?wpcap_ics=feed&token=…, webcal:// variant).
 *
 * - Token: 32 random bytes (64 hex), stored as SHA-256 only (wpcap_calendar_feeds); shown once on
 *   creation/rotation. Revoking or rotating a feed makes the old URL answer 404 at once.
 * - Content: occurrences of published and cancelled events from 30 days ago (max. 1000) as VEVENT
 *   (Calendar\Ics, UTC, stable UID per occurrence). Cancelled occurrences/events: STATUS:CANCELLED.
 *   No buyer or attendee data. Draft events are never included.
 * - Updates: every occurrence carries SEQUENCE and LAST-MODIFIED (wpcap_calendar_sequences). The
 *   sequence is raised on wpcap_occurrence_rescheduled / wpcap_occurrence_cancelled and whenever a feed
 *   render sees changed data (start, end, location, status, title), so clients replace the entry.
 *   The feed writes max(occurrence revision, own counter), so it is never lower than the SEQUENCE of
 *   the download and the mails for the same UID (Calendar\Revisions).
 */
namespace PassCaptain\Integrations;

use PassCaptain\Calendar\Ics;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class CalendarFeeds {
	const SCOPES = array( 'all', 'event', 'partner' );
	const MAX_ENTRIES = 1000;

	private static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'wpcap_' . $name;
	}

	public static function scope_labels() {
		return array(
			'all' => __( 'Alle Events', 'pass-captain' ),
			'event' => __( 'Ein Event', 'pass-captain' ),
			'partner' => __( 'Ein Veranstalter', 'pass-captain' ),
		);
	}

	/** Feed URL of a token (webcal:// for one-click subscription). */
	public static function url( $token, $webcal = false ) {
		$url = add_query_arg( array( 'wpcap_ics' => 'feed', 'token' => (string) $token ), home_url( '/' ) );
		return $webcal ? (string) preg_replace( '#^https?://#', 'webcal://', $url ) : $url;
	}

	public static function all() {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT feed_id,scope,scope_id,label,status,created_by,created_at,revoked_at,last_access_at FROM %i ORDER BY feed_id DESC LIMIT 200', self::table( 'calendar_feeds' ) ) );
		return '' === (string) $wpdb->last_error ? $rows : array();
	}

	public static function get( $feed_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE feed_id=%d', self::table( 'calendar_feeds' ), absint( $feed_id ) ) );
	}

	/**
	 * Creates a feed.
	 *
	 * @return array|\WP_Error ['feed_id', 'token', 'url'] – the token is returned only here.
	 */
	public static function create( $scope, $scope_id = 0, $label = '' ) {
		global $wpdb;
		$scope = in_array( $scope, self::SCOPES, true ) ? $scope : '';
		$scope_id = 'all' === $scope ? 0 : absint( $scope_id );
		if ( '' === $scope ) { return new \WP_Error( 'wpcap_feed_scope', __( 'Unbekannter Umfang des Kalender-Abos.', 'pass-captain' ), array( 'status' => 400 ) ); }
		if ( 'event' === $scope && ! $wpdb->get_var( $wpdb->prepare( 'SELECT event_id FROM %i WHERE event_id=%d', $wpdb->prefix . 'wpcap_events', $scope_id ) ) ) {
			return new \WP_Error( 'wpcap_event_missing', __( 'Event nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) );
		}
		if ( 'partner' === $scope && ( ! class_exists( '\\PassCaptain\\Partners\\Repository' ) || ! \PassCaptain\Partners\Repository::get( $scope_id ) ) ) {
			return new \WP_Error( 'wpcap_partner_missing', __( 'Veranstalter nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) );
		}
		$token = bin2hex( random_bytes( 32 ) );
		$ok = $wpdb->insert( self::table( 'calendar_feeds' ), array( 'scope' => $scope, 'scope_id' => $scope_id, 'label' => mb_substr( sanitize_text_field( (string) $label ), 0, 190 ), 'token_hash' => hash( 'sha256', $token ), 'status' => 'active', 'created_by' => get_current_user_id(), 'created_at' => \PassCaptain\Clock::mysql() ) );
		if ( ! $ok ) { return new \WP_Error( 'wpcap_feed_save', __( 'Kalender-Abo konnte nicht angelegt werden.', 'pass-captain' ), array( 'status' => 500 ) ); }
		$feed_id = (int) $wpdb->insert_id;
		\PassCaptain\Audit::record( 'calendar_feed_created', 'settings', $feed_id, array( 'scope' => $scope, 'scope_id' => $scope_id ) );
		return array( 'feed_id' => $feed_id, 'token' => $token, 'url' => self::url( $token ) );
	}

	/** Revokes a feed: its URL stops working immediately. */
	public static function revoke( $feed_id ) {
		global $wpdb;
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='revoked',revoked_at=%s WHERE feed_id=%d AND status='active'", self::table( 'calendar_feeds' ), \PassCaptain\Clock::mysql(), absint( $feed_id ) ) );
		if ( $changed ) { \PassCaptain\Audit::record( 'calendar_feed_revoked', 'settings', absint( $feed_id ), array() ); }
		return (bool) $changed;
	}

	/** New token for an active feed (old URL invalid). @return array|\WP_Error ['feed_id', 'token', 'url'] */
	public static function rotate( $feed_id ) {
		global $wpdb;
		$feed = self::get( $feed_id );
		if ( ! $feed || 'active' !== $feed->status ) { return new \WP_Error( 'wpcap_feed_missing', __( 'Kalender-Abo nicht gefunden oder widerrufen.', 'pass-captain' ), array( 'status' => 404 ) ); }
		$token = bin2hex( random_bytes( 32 ) );
		$wpdb->update( self::table( 'calendar_feeds' ), array( 'token_hash' => hash( 'sha256', $token ) ), array( 'feed_id' => (int) $feed->feed_id ) );
		\PassCaptain\Audit::record( 'calendar_feed_rotated', 'settings', (int) $feed->feed_id, array() );
		return array( 'feed_id' => (int) $feed->feed_id, 'token' => $token, 'url' => self::url( $token ) );
	}

	/** Active feed of a token, or null. */
	public static function find( $token ) {
		global $wpdb;
		$token = strtolower( trim( (string) $token ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) { return null; }
		$feed = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE token_hash=%s AND status='active'", self::table( 'calendar_feeds' ), hash( 'sha256', $token ) ) );
		return $feed ? $feed : null;
	}

	/** Occurrence rows of a feed (published and cancelled events, from 30 days ago). */
	private static function rows( $feed ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$revision = \PassCaptain\Calendar\Revisions::ready() ? 'o.revision,o.revised_at,' : '';
		$sql = "SELECT o.occurrence_id,o.event_id,o.starts_at,o.ends_at,o.timezone,o.location,o.status,{$revision}e.status AS event_status,e.name AS event_name,e.description AS event_description FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE e.status IN ('published','cancelled') AND o.starts_at>=%s";
		$args = array( gmdate( 'Y-m-d H:i:s', \PassCaptain\Clock::now() - 30 * DAY_IN_SECONDS ) );
		if ( 'event' === $feed->scope ) {
			$sql .= ' AND o.event_id=%d';
			$args[] = (int) $feed->scope_id;
		} elseif ( 'partner' === $feed->scope ) {
			$ids = class_exists( '\\PassCaptain\\Partners\\Repository' ) ? \PassCaptain\Partners\Repository::event_ids( (int) $feed->scope_id ) : array();
			if ( ! $ids ) { return array(); }
			$sql .= ' AND o.event_id IN (' . implode( ',', array_map( 'absint', $ids ) ) . ')'; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- integer list.
		}
		$sql .= ' ORDER BY o.starts_at,o.occurrence_id LIMIT ' . self::MAX_ENTRIES;
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from literals and integers.
	}

	private static function fingerprint( $row ) {
		$status = 'cancelled' === $row->status || 'cancelled' === ( $row->event_status ?? '' ) ? 'cancelled' : (string) $row->status;
		return md5( $row->starts_at . '|' . (string) $row->ends_at . '|' . $row->location . '|' . $status . '|' . $row->event_name );
	}

	/**
	 * Sequences of occurrences; raises the sequence of rows whose data changed since the last render.
	 *
	 * @return array<int,array{sequence:int,changed_at:int}>
	 */
	private static function sequences( array $rows ) {
		global $wpdb;
		$table = self::table( 'calendar_sequences' );
		if ( ! $rows ) { return array(); }
		$ids = array_map( static function ( $r ) { return (int) $r->occurrence_id; }, $rows );
		$known = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT occurrence_id,fingerprint,sequence,changed_at FROM %i WHERE occurrence_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( $table ), $ids ) ) ) as $s ) {
			$known[ (int) $s->occurrence_id ] = $s;
		}
		$out = array();
		$now = \PassCaptain\Clock::mysql();
		foreach ( $rows as $row ) {
			$id = (int) $row->occurrence_id;
			$fp = self::fingerprint( $row );
			$s = $known[ $id ] ?? null;
			if ( ! $s ) {
				$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (occurrence_id,fingerprint,sequence,changed_at) VALUES (%d,%s,0,%s)', $table, $id, $fp, $now ) );
				$out[ $id ] = array( 'sequence' => 0, 'changed_at' => strtotime( $now . ' UTC' ) );
				continue;
			}
			if ( $s->fingerprint !== $fp ) {
				$wpdb->query( $wpdb->prepare( 'UPDATE %i SET fingerprint=%s,sequence=sequence+1,changed_at=%s WHERE occurrence_id=%d AND fingerprint=%s', $table, $fp, $now, $id, (string) $s->fingerprint ) );
				$out[ $id ] = array( 'sequence' => (int) $s->sequence + 1, 'changed_at' => strtotime( $now . ' UTC' ) );
				continue;
			}
			$out[ $id ] = array( 'sequence' => (int) $s->sequence, 'changed_at' => (int) strtotime( $s->changed_at . ' UTC' ) );
		}
		return $out;
	}

	/**
	 * Raises the sequence of an occurrence (hooked to wpcap_occurrence_rescheduled/_cancelled) and stores
	 * its current fingerprint, so the next render does not count the same change twice.
	 */
	public static function bump( $occurrence_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT o.occurrence_id,o.starts_at,o.ends_at,o.location,o.status,e.status AS event_status,e.name AS event_name FROM %i o INNER JOIN %i e ON e.event_id=o.event_id WHERE o.occurrence_id=%d', $p . 'wpcap_occurrences', $p . 'wpcap_events', absint( $occurrence_id ) ) );
		if ( ! $row ) { return; }
		$table = self::table( 'calendar_sequences' );
		$fp = self::fingerprint( $row );
		$now = \PassCaptain\Clock::mysql();
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (occurrence_id,fingerprint,sequence,changed_at) VALUES (%d,%s,1,%s) ON DUPLICATE KEY UPDATE sequence=IF(fingerprint=VALUES(fingerprint),sequence,sequence+1),fingerprint=VALUES(fingerprint),changed_at=IF(fingerprint=VALUES(fingerprint),changed_at,VALUES(changed_at))', $table, (int) $row->occurrence_id, $fp, $now ) );
	}

	/** iCalendar body of a token, or null (unknown or revoked token). */
	public static function render( $token ) {
		global $wpdb;
		$feed = self::find( $token );
		if ( ! $feed ) { return null; }
		$rows = self::rows( $feed );
		$sequences = self::sequences( $rows );
		$entries = array();
		foreach ( $rows as $row ) {
			$entry = Ics::entry_from_row( $row );
			if ( 'cancelled' === ( $row->event_status ?? '' ) ) { $entry['status'] = 'cancelled'; }
			// SEQUENCE never lower than the occurrence revision the download and the mails carry (same UID).
			$entry['sequence'] = max( (int) ( $row->revision ?? 0 ), (int) ( $sequences[ (int) $row->occurrence_id ]['sequence'] ?? 0 ) );
			$entry['last_modified'] = max( (int) $entry['last_modified'], (int) ( $sequences[ (int) $row->occurrence_id ]['changed_at'] ?? \PassCaptain\Clock::now() ) );
			$entries[] = $entry;
		}
		$name = '' !== (string) $feed->label ? (string) $feed->label : ( 'event' === $feed->scope && $rows ? (string) $rows[0]->event_name : (string) get_bloginfo( 'name' ) );
		if ( ! $feed->last_access_at || strtotime( $feed->last_access_at . ' UTC' ) < \PassCaptain\Clock::now() - HOUR_IN_SECONDS ) {
			$wpdb->update( self::table( 'calendar_feeds' ), array( 'last_access_at' => \PassCaptain\Clock::mysql() ), array( 'feed_id' => (int) $feed->feed_id ) );
		}
		return Ics::build( $entries, array( 'name' => $name, 'refresh' => 60 ) );
	}

	/** template_redirect: serves ?wpcap_ics=feed&token=… (before the public calendar endpoints). */
	public static function maybe_serve() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- capability URL, read-only.
		if ( empty( $_GET['wpcap_ics'] ) || 'feed' !== sanitize_key( wp_unslash( $_GET['wpcap_ics'] ) ) ) { return; }
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		// phpcs:enable
		$body = self::render( $token );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		if ( null === $body ) {
			status_header( 404 );
			echo esc_html__( 'Kalender-Abo nicht gefunden oder widerrufen.', 'pass-captain' );
			\PassCaptain\Http::end();
		}
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: inline; filename="pass-captain.ics"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text/calendar body, values escaped per RFC 5545.
		\PassCaptain\Http::end();
	}
}

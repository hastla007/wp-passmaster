<?php
/**
 * Service providers ("Leistungserbringer", A133): people or partner companies that deliver a
 * service (instructor, skipper, technician). A provider can be assigned to several resources;
 * a booking of a slot then occupies the provider as well, so the same provider cannot be booked
 * for overlapping slots (buffers included) of different resources (ServiceBookings::blocking_slot()).
 * Optionally linked to a WordPress user and/or a partner (wpcap_partners).
 */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ServiceProviders {
	const CAP = 'wpcap_manage_events';

	/** All providers (optionally only one status), by name. */
	public static function all( $status = '' ) {
		global $wpdb;
		if ( ! ServiceBookings::providers_ready() ) { return array(); }
		$where = in_array( $status, array( 'active', 'inactive' ), true ) ? $wpdb->prepare( ' WHERE status=%s', $status ) : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is prepared above.
		return (array) $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wpcap_service_providers$where ORDER BY name,provider_id" );
	}

	/** Provider row or null. */
	public static function get( $provider_id ) {
		global $wpdb;
		if ( ! ServiceBookings::providers_ready() ) { return null; }
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_service_providers WHERE provider_id=%d", absint( $provider_id ) ) );
	}

	/** Providers assigned to a resource. */
	public static function for_resource( $resource_id ) {
		global $wpdb;
		if ( ! ServiceBookings::providers_ready() ) { return array(); }
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT p.* FROM {$wpdb->prefix}wpcap_service_providers p INNER JOIN {$wpdb->prefix}wpcap_resource_providers rp ON rp.provider_id=p.provider_id WHERE rp.resource_id=%d ORDER BY p.name,p.provider_id", absint( $resource_id ) ) );
	}

	/**
	 * Creates (no id) or updates a provider: name (required), email, user_id, partner_id, status.
	 *
	 * @return int|\WP_Error Provider id.
	 */
	public static function save( array $data, $provider_id = 0 ) {
		global $wpdb;
		if ( ! current_user_can( self::CAP ) ) { return new \WP_Error( 'wpcap_provider_forbidden', __( 'Keine Berechtigung.', 'pass-captain' ), array( 'status' => 403 ) ); }
		if ( ! ServiceBookings::providers_ready() ) { return new \WP_Error( 'wpcap_schema_pending', __( 'Das Datenbank-Update steht noch aus. Bitte später erneut versuchen.', 'pass-captain' ), array( 'status' => 503 ) ); }
		$provider_id = absint( $provider_id );
		$current = $provider_id ? self::get( $provider_id ) : null;
		if ( $provider_id && ! $current ) { return new \WP_Error( 'wpcap_provider_not_found', __( 'Leistungserbringer nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
		$name = mb_substr( sanitize_text_field( (string) ( $data['name'] ?? '' ) ), 0, 190 );
		$email = sanitize_email( (string) ( $data['email'] ?? '' ) );
		if ( '' === $name ) { return new \WP_Error( 'wpcap_provider_invalid', __( 'Name des Leistungserbringers fehlt.', 'pass-captain' ), array( 'status' => 400 ) ); }
		if ( '' !== $email && ! is_email( $email ) ) { return new \WP_Error( 'wpcap_provider_invalid', __( 'Die E-Mail-Adresse des Leistungserbringers ist ungültig.', 'pass-captain' ), array( 'status' => 400 ) ); }
		$row = array(
			'name' => $name,
			'email' => $email,
			'user_id' => absint( $data['user_id'] ?? ( $current->user_id ?? 0 ) ),
			'partner_id' => absint( $data['partner_id'] ?? ( $current->partner_id ?? 0 ) ),
			'status' => in_array( $data['status'] ?? '', array( 'active', 'inactive' ), true ) ? $data['status'] : ( $current->status ?? 'active' ),
		);
		$table = $wpdb->prefix . 'wpcap_service_providers';
		if ( $current ) {
			$wpdb->update( $table, $row, array( 'provider_id' => $provider_id ) );
			Audit::record( 'service_provider_updated', 'service_provider', $provider_id, array( 'before' => array( 'name' => $current->name, 'status' => $current->status ), 'after' => array( 'name' => $name, 'status' => $row['status'], 'user_id' => $row['user_id'], 'partner_id' => $row['partner_id'] ) ) );
			return $provider_id;
		}
		$wpdb->insert( $table, $row + array( 'created_at' => current_time( 'mysql', true ) ) );
		if ( ! $wpdb->insert_id ) { return new \WP_Error( 'wpcap_provider_save', __( 'Leistungserbringer konnte nicht gespeichert werden.', 'pass-captain' ), array( 'status' => 500 ) ); }
		$provider_id = (int) $wpdb->insert_id;
		Audit::record( 'service_provider_created', 'service_provider', $provider_id, array( 'name' => $name, 'user_id' => $row['user_id'], 'partner_id' => $row['partner_id'] ) );
		return $provider_id;
	}

	/**
	 * Sets the providers of a resource. A provider that would then be booked twice – a booked slot of
	 * this resource overlapping (buffers included) a booked slot of another resource of the provider –
	 * is refused (409) and nothing changes. Locks the resource, then the providers ascending (the
	 * order of ServiceBookings::lock()), so a parallel reservation cannot slip in between.
	 *
	 * @param int   $resource_id  Resource.
	 * @param int[] $provider_ids Complete new assignment (empty = none).
	 * @return true|\WP_Error
	 */
	public static function assign( $resource_id, array $provider_ids ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$resource = ServiceBookings::resource( $resource_id );
		if ( ! $resource ) { return new \WP_Error( 'wpcap_service_not_found', __( 'Ressource oder Zeitfenster nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
		if ( ! Access::can_access_event( (int) $resource->event_id, self::CAP ) ) { return new \WP_Error( 'wpcap_resource_forbidden', __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ), array( 'status' => 403 ) ); }
		if ( ! ServiceBookings::providers_ready() ) { return new \WP_Error( 'wpcap_schema_pending', __( 'Das Datenbank-Update steht noch aus. Bitte später erneut versuchen.', 'pass-captain' ), array( 'status' => 503 ) ); }
		$provider_ids = array_values( array_unique( array_filter( array_map( 'absint', $provider_ids ) ) ) );
		sort( $provider_ids, SORT_NUMERIC );
		$known = $provider_ids ? array_map( 'intval', (array) $wpdb->get_col( 'SELECT provider_id FROM ' . $p . 'wpcap_service_providers WHERE provider_id IN (' . implode( ',', $provider_ids ) . ')' ) ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- integer list.
		if ( count( $known ) !== count( $provider_ids ) ) { return new \WP_Error( 'wpcap_provider_not_found', __( 'Leistungserbringer nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
		$wpdb->query( 'START TRANSACTION' );
		// Same order as ServiceBookings::lock(): resource, then all providers involved ascending.
		$wpdb->get_var( $wpdb->prepare( "SELECT resource_id FROM {$p}wpcap_resources WHERE resource_id=%d FOR UPDATE", (int) $resource->resource_id ) );
		$before = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT provider_id FROM {$p}wpcap_resource_providers WHERE resource_id=%d FOR UPDATE", (int) $resource->resource_id ) ) );
		if ( '' !== (string) $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); return Errors::busy(); }
		$involved = array_values( array_unique( array_merge( $before, $provider_ids ) ) );
		sort( $involved, SORT_NUMERIC );
		foreach ( $involved as $provider_id ) {
			$wpdb->get_var( $wpdb->prepare( "SELECT provider_id FROM {$p}wpcap_service_providers WHERE provider_id=%d FOR UPDATE", $provider_id ) );
			if ( '' !== (string) $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); return Errors::busy(); }
		}
		sort( $before, SORT_NUMERIC );
		foreach ( array_diff( $provider_ids, $before ) as $provider_id ) {
			$clash = self::double_booking( (int) $resource->resource_id, $provider_id );
			if ( $clash ) {
				$wpdb->query( 'ROLLBACK' );
				$provider = self::get( $provider_id );
				return new \WP_Error(
					'wpcap_provider_double_booked',
					/* translators: %s: provider name */
					sprintf( __( '%s ist bereits zu einer Zeit eingeteilt, zu der diese Ressource gebucht ist. Bitte zuerst die Buchungen umbuchen.', 'pass-captain' ), $provider ? $provider->name : '#' . $provider_id ),
					array( 'status' => 409, 'slot_id' => $clash )
				);
			}
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_resource_providers WHERE resource_id=%d", (int) $resource->resource_id ) );
		foreach ( $provider_ids as $provider_id ) {
			$wpdb->insert( $p . 'wpcap_resource_providers', array( 'resource_id' => (int) $resource->resource_id, 'provider_id' => $provider_id ), array( '%d', '%d' ) );
		}
		if ( '' !== (string) $wpdb->last_error || false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return Errors::busy(); }
		Audit::record( 'service_resource_providers_set', 'resource', (int) $resource->resource_id, array( 'event_id' => (int) $resource->event_id, 'before' => $before, 'after' => $provider_ids ) );
		return true;
	}

	/**
	 * A booked, not yet ended slot of $resource_id that overlaps (buffers of both resources included)
	 * a booked slot of another resource assigned to $provider_id; slot id or 0.
	 */
	public static function double_booking( $resource_id, $provider_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = current_time( 'mysql', true );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- ACTIVE_SQL is a constant with placeholders.
		$active1 = str_replace( 'b.', 'b1.', ServiceBookings::ACTIVE_SQL );
		$active2 = str_replace( 'b.', 'b2.', ServiceBookings::ACTIVE_SQL );
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT s1.slot_id FROM {$p}wpcap_service_slots s1 INNER JOIN {$p}wpcap_resources r1 ON r1.resource_id=s1.resource_id
			INNER JOIN {$p}wpcap_resource_providers rp ON rp.provider_id=%d AND rp.resource_id<>s1.resource_id
			INNER JOIN {$p}wpcap_service_slots s2 ON s2.resource_id=rp.resource_id INNER JOIN {$p}wpcap_resources r2 ON r2.resource_id=s2.resource_id
			WHERE s1.resource_id=%d AND s1.ends_at>%s
			AND DATE_SUB(s1.starts_at, INTERVAL r1.buffer_before MINUTE) < DATE_ADD(s2.ends_at, INTERVAL r2.buffer_after MINUTE)
			AND DATE_SUB(s2.starts_at, INTERVAL r2.buffer_before MINUTE) < DATE_ADD(s1.ends_at, INTERVAL r1.buffer_after MINUTE)
			AND EXISTS (SELECT 1 FROM {$p}wpcap_service_bookings b1 WHERE b1.slot_id=s1.slot_id AND $active1)
			AND EXISTS (SELECT 1 FROM {$p}wpcap_service_bookings b2 WHERE b2.slot_id=s2.slot_id AND $active2) LIMIT 1",
			absint( $provider_id ),
			absint( $resource_id ),
			$now,
			$now,
			$now
		) );
		// phpcs:enable
	}

	/** Display label: name, plus e-mail when set. */
	public static function label( $provider ) {
		return (string) $provider->name . ( '' !== (string) $provider->email ? ' <' . $provider->email . '>' : '' );
	}
}

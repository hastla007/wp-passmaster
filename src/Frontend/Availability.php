<?php
/**
 * Live availability for cached event pages (audit R2, expert #7).
 *
 * Event pages are cached by page caches/CDNs: the remaining quantity, "Ausverkauft" and the sales
 * window in the HTML can be minutes old. The checkout script therefore asks
 * GET /wp-pass-captain/v1/availability?types=1,2,3 when a sales start is reached and every 15–30 s
 * while the page is visible, and updates the maximum, the labels and the sold-out state.
 *
 * The answer is public (no personal data), computed with one DisplayBatch (a fixed number of grouped
 * queries for all requested types), cacheable for 5 seconds by shared caches (max-age=5,
 * stale-while-revalidate) so a drop with thousands of open pages costs the origin about one request
 * per type list every 5 seconds, and rate limited per client network.
 *
 * The action wp_pass_captain_availability_changed_v1 tells purge adapters (page cache, CDN) which
 * ticket types changed: after a new reservation through the public checkout and whenever capacity
 * is released (expired hold, cancellation, refund, revoked tickets).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Frontend;

use PassCaptain\DisplayBatch;
use PassCaptain\Orders;
use PassCaptain\RateLimit;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Availability {
	/** Versioned action for purge adapters: ( int[] $ticket_type_ids, string $reason ). */
	const CHANGED_HOOK = 'wp_pass_captain_availability_changed_v1';

	/** Most ticket types per request (one event page). */
	const MAX_TYPES = 60;

	public static function register() {
		add_action( 'wpcap_capacity_released', array( __CLASS__, 'on_capacity_released' ), 50, 2 );
		add_action( 'wpcap_refund_completed', array( __CLASS__, 'on_refund_completed' ), 50, 2 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'cache_headers' ), 10, 3 );
	}

	/** rest_post_dispatch: Cache-Control (and Retry-After on 429) for GET /availability. */
	public static function cache_headers( $response, $server = null, $request = null ) {
		if ( ! $response instanceof \WP_REST_Response || ! $request instanceof \WP_REST_Request || '/wp-pass-captain/v1/availability' !== $request->get_route() ) { return $response; }
		if ( 429 === $response->get_status() ) {
			$response->header( 'Retry-After', '30' );
			$response->header( 'Cache-Control', 'no-store' );
		} elseif ( 200 === $response->get_status() ) {
			// Public data: shared caches may serve it for 5 s (and briefly stale while refreshing).
			$response->header( 'Cache-Control', self::cache_control() );
		}
		return $response;
	}

	/** Ticket type ids from the "types" parameter (comma list or array), unique, at most MAX_TYPES. */
	public static function parse_ids( $raw ) {
		$list = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $list ) ) ) );
		sort( $ids );
		return array_slice( $ids, 0, self::MAX_TYPES );
	}

	/** Requests per minute and client network (filter wpcap_availability_rate_limit). */
	public static function rate_limit() {
		return max( 1, (int) apply_filters( 'wpcap_availability_rate_limit', 120 ) );
	}

	/**
	 * State of each ticket type: status available|soldout|upcoming|closed, remaining (null = unlimited),
	 * max (selectable maximum for one order), sales_start (ISO 8601 UTC or null).
	 *
	 * @param int[] $type_ids Ticket type ids.
	 * @return array<int,array>
	 */
	public static function snapshot( array $type_ids ) {
		global $wpdb;
		$type_ids = self::parse_ids( $type_ids );
		if ( ! $type_ids ) { return array(); }
		$p = $wpdb->prefix;
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- generated placeholders.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT tt.ticket_type_id,tt.status,tt.max_per_order,tt.sales_start,tt.sales_end,o.status AS occurrence_status,o.starts_at,e.status AS event_status FROM %i tt INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id INNER JOIN %i e ON e.event_id=o.event_id WHERE tt.ticket_type_id IN (" . implode( ',', array_fill( 0, count( $type_ids ), '%d' ) ) . ')', array_merge( array( $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $p . 'wpcap_events' ), $type_ids ) ) );
		$out = array();
		$open = array();
		foreach ( $rows as $row ) {
			$id = (int) $row->ticket_type_id;
			$state = array(
				'status' => 'closed',
				'remaining' => 0,
				'max' => 0,
				'sales_start' => $row->sales_start ? gmdate( 'c', (int) strtotime( $row->sales_start . ' UTC' ) ) : null,
			);
			$public = 'active' === $row->status && 'scheduled' === $row->occurrence_status && 'published' === $row->event_status && $row->starts_at > $now;
			if ( $public && ( ! $row->sales_end || $row->sales_end >= $now ) ) {
				if ( $row->sales_start && $row->sales_start > $now ) {
					$state['status'] = 'upcoming';
				} else {
					$open[ $id ] = (int) $row->max_per_order;
				}
			}
			$out[ $id ] = $state;
		}
		if ( $open ) {
			DisplayBatch::run( array_keys( $open ), static function () use ( $open, &$out ) {
				foreach ( $open as $id => $max_per_order ) {
					$remaining = Orders::availability( $id );
					$max = null === $remaining ? $max_per_order : min( $max_per_order, (int) $remaining );
					$out[ $id ]['remaining'] = null === $remaining ? null : max( 0, (int) $remaining );
					$out[ $id ]['max'] = max( 0, $max );
					$out[ $id ]['status'] = $max > 0 ? 'available' : 'soldout';
				}
			} );
		}
		return $out;
	}

	/** GET /wp-pass-captain/v1/availability?types=… */
	public static function rest( \WP_REST_Request $request ) {
		if ( ! RateLimit::allow( 'wpcap_availability|' . RateLimit::client_network(), self::rate_limit(), MINUTE_IN_SECONDS ) ) {
			return new \WP_REST_Response( array( 'code' => 'wpcap_rate_limited', 'message' => __( 'Bitte warte kurz und versuche es erneut.', 'pass-captain' ), 'data' => array( 'status' => 429 ) ), 429 );
		}
		$types = array();
		foreach ( self::snapshot( self::parse_ids( $request->get_param( 'types' ) ) ) as $id => $state ) {
			$types[ (string) $id ] = $state;
		}
		return new \WP_REST_Response( array( 'now' => gmdate( 'c' ), 'types' => (object) $types ), 200 );
	}

	/** Cache-Control of the availability answer (filter wpcap_availability_cache_control, e.g. "no-store"). */
	public static function cache_control() {
		return (string) apply_filters( 'wpcap_availability_cache_control', 'public, max-age=5, s-maxage=5, stale-while-revalidate=10' );
	}

	/** Fires the purge hook for the given ticket types. */
	public static function changed( array $type_ids, $reason ) {
		$type_ids = array_values( array_unique( array_filter( array_map( 'absint', $type_ids ) ) ) );
		if ( ! $type_ids ) { return; }
		/**
		 * Availability of ticket types changed (purge adapters: event pages, CDN).
		 *
		 * @param int[]  $ticket_type_ids Ticket types.
		 * @param string $reason          reserved | expired | cancelled | refunded | tickets_revoked | … .
		 */
		do_action( 'wp_pass_captain_availability_changed_v1', $type_ids, (string) $reason );
	}

	/** Ticket types of an order. */
	public static function types_of_order( $order_id ) {
		global $wpdb;
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT ticket_type_id FROM %i WHERE order_id=%d', $wpdb->prefix . 'wpcap_order_items', absint( $order_id ) ) ) );
	}

	/** wpcap_capacity_released action. */
	public static function on_capacity_released( $order_id, $reason = '' ) {
		self::changed( self::types_of_order( $order_id ), (string) $reason );
	}

	/** wpcap_refund_completed action. */
	public static function on_refund_completed( $refund_id, $order_id = 0 ) {
		self::changed( self::types_of_order( $order_id ), 'refunded' );
	}
}

<?php
/**
 * Partner REST API under pass-captain/v1/partner/…, authenticated with
 * "Authorization: Bearer wpcap_pk_…" (see Keys). Read endpoints (this class) are limited to the key's
 * effective events; e-mail addresses are only included with the attendees:read scope. Write endpoints
 * for selling (orders:write, orders:cancel, tickets:issue) live in SalesApi. Reference:
 * docs/PARTNER-API.md.
 */
namespace PassCaptain\Partners;

use PassCaptain\Reports\Data;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Api {
	const NS = 'pass-captain/v1';
	const VERSION = '1';
	const MAX_PER_PAGE = 100;

	/** Authenticated context of the current request (set by the permission callback). */
	private static $context = null;

	public static function register_routes() {
		$routes = array(
			'/partner/me' => array( 'me', null ),
			'/partner/events' => array( 'events', 'events:read' ),
			'/partner/events/(?P<event_id>\d+)' => array( 'event', 'events:read' ),
			'/partner/orders' => array( 'orders', 'orders:read' ),
			'/partner/tickets' => array( 'tickets', 'tickets:read' ),
			'/partner/checkins' => array( 'checkins', 'checkins:read' ),
			'/partner/reports' => array( 'reports', 'reports:read' ),
			'/partner/settlements' => array( 'settlements', 'settlements:read' ),
			'/partner/settlements/(?P<settlement_id>\d+)' => array( 'settlement', 'settlements:read' ),
		);
		$endpoints = array();
		foreach ( $routes as $route => $def ) {
			$endpoints[ $route ][] = array(
				'methods' => 'GET',
				'callback' => array( __CLASS__, $def[0] ),
				'permission_callback' => self::permission( $def[1] ),
			);
		}
		// Selling (write scopes): see SalesApi.
		foreach ( SalesApi::routes() as $route => $defs ) {
			foreach ( $defs as $def ) {
				$endpoints[ $route ][] = array(
					'methods' => $def[0],
					'callback' => array( SalesApi::class, $def[1] ),
					'permission_callback' => $def[3] ? SalesApi::write_permission( $def[2] ) : self::permission( $def[2] ),
				);
			}
		}
		foreach ( $endpoints as $route => $list ) {
			register_rest_route( self::NS, $route, 1 === count( $list ) ? $list[0] : $list );
		}
	}

	/** Permission callback for a scope (null = any valid key). */
	public static function permission( $scope ) {
		return static function ( $request ) use ( $scope ) {
			self::$context = null;
			$ctx = Keys::authenticate( $request, $scope );
			if ( is_wp_error( $ctx ) ) { return $ctx; }
			self::$context = $ctx;
			return true;
		};
	}

	public static function context() {
		return self::$context;
	}

	/**
	 * SQL restriction for resellers: only the orders they sold themselves (wpcap_partner_orders).
	 * Organizers see all orders of their events. '' when not restricted.
	 *
	 * @param string $order_column Column holding the order id (e.g. ord.order_id, t.order_id).
	 */
	private static function own_orders_sql( $order_column ) {
		global $wpdb;
		$partner = self::$context['partner'] ?? null;
		if ( ! $partner || Repository::ROLE_ORGANIZER === Repository::role_of( $partner ) ) { return ''; }
		return $wpdb->prepare( " AND $order_column IN (SELECT po.order_id FROM {$wpdb->prefix}wpcap_partner_orders po WHERE po.partner_id=%d)", (int) $partner->partner_id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- column name literal.
	}

	private static function has_scope( $scope ) {
		return self::$context && in_array( $scope, self::$context['scopes'], true );
	}

	private static function respond( $data ) {
		$response = new \WP_REST_Response( $data, 200 );
		if ( method_exists( $response, 'header' ) ) {
			$response->header( 'Cache-Control', 'no-store' );
			$response->header( 'X-WPCAP-Partner-API', self::VERSION );
		}
		return $response;
	}

	private static function iso( $utc ) {
		return $utc ? gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $utc . ' UTC' ) ) : null;
	}

	/**
	 * Event ids for the request: all effective events, or the requested event_id if allowed.
	 *
	 * @return int[]|\WP_Error
	 */
	private static function events_for( $request ) {
		$allowed = self::$context ? self::$context['event_ids'] : array();
		$requested = absint( $request->get_param( 'event_id' ) );
		if ( ! $requested ) { return $allowed; }
		if ( ! in_array( $requested, $allowed, true ) ) {
			Keys::audit_failure( 'event_not_allowed', self::$context['key'] ?? null, Keys::client_ip(), method_exists( $request, 'get_route' ) ? $request->get_route() : '', array( 'event_id' => $requested ) );
			return new \WP_Error( 'wpcap_partner_event_forbidden', __( 'Dieser API-Key hat keinen Zugriff auf dieses Event.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		return array( $requested );
	}

	/** [page, per_page, offset] from the request. */
	private static function paging( $request ) {
		$per_page = max( 1, min( self::MAX_PER_PAGE, absint( $request->get_param( 'per_page' ) ) ?: 50 ) );
		$page = max( 1, absint( $request->get_param( 'page' ) ) ?: 1 );
		return array( $page, $per_page, ( $page - 1 ) * $per_page );
	}

	/** UTC datetime from an ISO 8601 "since" parameter ('' if absent/invalid). */
	private static function since( $request ) {
		$since = (string) $request->get_param( 'since' );
		if ( '' === $since ) { return ''; }
		$ts = strtotime( $since );
		return false === $ts ? '' : gmdate( 'Y-m-d H:i:s', $ts );
	}

	private static function in_list( array $ids ) {
		return implode( ',', array_map( 'absint', $ids ) );
	}

	/* ------------------------------------------------------------------ Endpoints */

	public static function me( $request ) {
		$ctx = self::$context;
		return self::respond( array(
			'api_version' => self::VERSION,
			'partner' => array( 'id' => (int) $ctx['partner']->partner_id, 'name' => $ctx['partner']->name, 'currency' => $ctx['partner']->currency, 'role' => Repository::role_of( $ctx['partner'] ), 'sales_channel' => (string) ( $ctx['partner']->sales_channel ?? '' ) ),
			'key' => array( 'prefix' => $ctx['key']->public_prefix, 'label' => $ctx['key']->label, 'scopes' => $ctx['scopes'], 'expires_at' => self::iso( $ctx['key']->expires_at ) ),
			'event_ids' => $ctx['event_ids'],
			'ticket_type_ids' => Keys::restricted_types( $ctx['key'] ),
		) );
	}

	public static function events( $request ) {
		$ids = self::events_for( $request );
		if ( is_wp_error( $ids ) ) { return $ids; }
		return self::respond( array( 'events' => self::event_payloads( $ids ) ) );
	}

	public static function event( $request ) {
		$id = absint( $request->get_param( 'event_id' ) );
		if ( ! in_array( $id, self::$context['event_ids'], true ) ) {
			Keys::audit_failure( 'event_not_allowed', self::$context['key'], Keys::client_ip(), method_exists( $request, 'get_route' ) ? $request->get_route() : '', array( 'event_id' => $id ) );
			return new \WP_Error( 'wpcap_partner_event_forbidden', __( 'Dieser API-Key hat keinen Zugriff auf dieses Event.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		$events = self::event_payloads( array( $id ) );
		return $events ? self::respond( $events[0] ) : new \WP_Error( 'wpcap_partner_event_missing', __( 'Event nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) );
	}

	private static function event_payloads( array $ids ) {
		global $wpdb;
		$p = $wpdb->prefix;
		if ( ! $ids ) { return array(); }
		$in = self::in_list( $ids );
		$events = $wpdb->get_results( "SELECT event_id,name,slug,status FROM {$p}wpcap_events WHERE event_id IN ($in) ORDER BY event_id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		$occ = $wpdb->get_results( "SELECT occurrence_id,event_id,starts_at,ends_at,timezone,location,capacity,status FROM {$p}wpcap_occurrences WHERE event_id IN ($in) ORDER BY starts_at" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$types = $wpdb->get_results( "SELECT tt.ticket_type_id,tt.occurrence_id,tt.name,tt.price_minor,tt.currency,tt.capacity,tt.tax_rate_bp,tt.status,COALESCE((SELECT SUM(oi.quantity-oi.refunded_quantity) FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_orders ord ON ord.order_id=oi.order_id WHERE oi.ticket_type_id=tt.ticket_type_id AND ord.payment_status='paid'),0) AS sold FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE o.event_id IN ($in) ORDER BY tt.ticket_type_id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out = array();
		foreach ( $events as $e ) {
			$occurrences = array();
			foreach ( $occ as $o ) {
				if ( (int) $o->event_id !== (int) $e->event_id ) { continue; }
				$tt = array();
				foreach ( $types as $t ) {
					if ( (int) $t->occurrence_id !== (int) $o->occurrence_id ) { continue; }
					$tt[] = array( 'id' => (int) $t->ticket_type_id, 'name' => $t->name, 'price_minor' => (int) $t->price_minor, 'currency' => $t->currency, 'tax_rate_bp' => (int) $t->tax_rate_bp, 'capacity' => (int) $t->capacity, 'sold' => (int) $t->sold, 'status' => $t->status );
				}
				$occurrences[] = array( 'id' => (int) $o->occurrence_id, 'starts_at' => self::iso( $o->starts_at ), 'ends_at' => self::iso( $o->ends_at ), 'timezone' => $o->timezone, 'location' => $o->location, 'capacity' => (int) $o->capacity, 'status' => $o->status, 'ticket_types' => $tt );
			}
			$out[] = array( 'id' => (int) $e->event_id, 'name' => $e->name, 'slug' => $e->slug, 'status' => $e->status, 'occurrences' => $occurrences );
		}
		return $out;
	}

	public static function orders( $request ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$ids = self::events_for( $request );
		if ( is_wp_error( $ids ) ) { return $ids; }
		list( $page, $per_page, $offset ) = self::paging( $request );
		if ( ! $ids ) { return self::respond( array( 'orders' => array(), 'page' => $page, 'per_page' => $per_page, 'total' => 0 ) ); }
		$in = self::in_list( $ids );
		$where = "EXISTS (SELECT 1 FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE oi.order_id=ord.order_id AND o.event_id IN ($in))";
		$since = self::since( $request );
		if ( '' !== $since ) { $where .= $wpdb->prepare( ' AND ord.updated_at >= %s', $since ); }
		$status = sanitize_key( (string) $request->get_param( 'payment_status' ) );
		if ( '' !== $status ) { $where .= $wpdb->prepare( ' AND ord.payment_status = %s', $status ); }
		$where .= self::own_orders_sql( 'ord.order_id' ); // Resellers: own sales only.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_orders ord WHERE $where" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- built from prepared fragments.
		$orders = $wpdb->get_results( "SELECT ord.order_id,ord.public_id,ord.customer_email,ord.status,ord.payment_status,ord.currency,ord.payment_provider,ord.created_at,ord.updated_at FROM {$p}wpcap_orders ord WHERE $where ORDER BY ord.order_id LIMIT " . (int) $offset . ',' . (int) $per_page ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$lines = array();
		if ( $orders ) {
			$order_in = self::in_list( array_map( static function ( $o ) { return (int) $o->order_id; }, $orders ) );
			foreach ( $wpdb->get_results( "SELECT oi.order_id,oi.ticket_type_id,oi.quantity,oi.refunded_quantity,oi.unit_price_minor,oi.line_total_minor,oi.tax_rate_bp,oi.tax_minor,tt.occurrence_id,o.event_id FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE oi.order_id IN ($order_in) AND o.event_id IN ($in) ORDER BY oi.item_id" ) as $l ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$lines[ (int) $l->order_id ][] = array( 'event_id' => (int) $l->event_id, 'occurrence_id' => (int) $l->occurrence_id, 'ticket_type_id' => (int) $l->ticket_type_id, 'quantity' => (int) $l->quantity, 'refunded_quantity' => (int) $l->refunded_quantity, 'unit_price_minor' => (int) $l->unit_price_minor, 'line_total_minor' => (int) $l->line_total_minor, 'tax_rate_bp' => (int) $l->tax_rate_bp, 'tax_minor' => (int) $l->tax_minor );
			}
		}
		$pii = self::has_scope( 'attendees:read' );
		$out = array();
		foreach ( $orders as $o ) {
			$row = array( 'id' => $o->public_id, 'status' => $o->status, 'payment_status' => $o->payment_status, 'currency' => $o->currency, 'payment_provider' => $o->payment_provider, 'created_at' => self::iso( $o->created_at ), 'updated_at' => self::iso( $o->updated_at ), 'lines' => $lines[ (int) $o->order_id ] ?? array() );
			if ( $pii ) { $row['customer_email'] = \PassCaptain\Privacy::public_email( $o->customer_email ); }
			$out[] = $row;
		}
		return self::respond( array( 'orders' => $out, 'page' => $page, 'per_page' => $per_page, 'total' => $total ) );
	}

	public static function tickets( $request ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$ids = self::events_for( $request );
		if ( is_wp_error( $ids ) ) { return $ids; }
		list( $page, $per_page, $offset ) = self::paging( $request );
		if ( ! $ids ) { return self::respond( array( 'tickets' => array(), 'page' => $page, 'per_page' => $per_page, 'total' => 0 ) ); }
		$where = 'o.event_id IN (' . self::in_list( $ids ) . ')';
		$occurrence = absint( $request->get_param( 'occurrence_id' ) );
		if ( $occurrence ) { $where .= $wpdb->prepare( ' AND tt.occurrence_id = %d', $occurrence ); }
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		if ( '' !== $status ) { $where .= $wpdb->prepare( ' AND t.status = %s', $status ); }
		$since = self::since( $request );
		if ( '' !== $since ) { $where .= $wpdb->prepare( ' AND t.issued_at >= %s', $since ); }
		$where .= self::own_orders_sql( 't.order_id' ); // Resellers: tickets of their own sales only.
		$from = "{$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id";
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $from WHERE $where" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT t.public_id,t.status,t.refunded,t.attendee_email,t.issued_at,t.ticket_type_id,tt.name AS ticket_type,tt.occurrence_id,o.event_id,ord.public_id AS order_public_id FROM $from WHERE $where ORDER BY t.ticket_id LIMIT " . (int) $offset . ',' . (int) $per_page ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$pii = self::has_scope( 'attendees:read' );
		$out = array();
		foreach ( $rows as $t ) {
			$row = array( 'id' => $t->public_id, 'order_id' => $t->order_public_id, 'event_id' => (int) $t->event_id, 'occurrence_id' => (int) $t->occurrence_id, 'ticket_type_id' => (int) $t->ticket_type_id, 'ticket_type' => $t->ticket_type, 'status' => $t->status, 'refunded' => (bool) $t->refunded, 'issued_at' => self::iso( $t->issued_at ) );
			if ( $pii ) { $row['attendee_email'] = \PassCaptain\Privacy::public_email( $t->attendee_email ); }
			$out[] = $row;
		}
		return self::respond( array( 'tickets' => $out, 'page' => $page, 'per_page' => $per_page, 'total' => $total ) );
	}

	public static function checkins( $request ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$ids = self::events_for( $request );
		if ( is_wp_error( $ids ) ) { return $ids; }
		list( $page, $per_page, $offset ) = self::paging( $request );
		if ( ! $ids ) { return self::respond( array( 'checkins' => array(), 'page' => $page, 'per_page' => $per_page, 'total' => 0 ) ); }
		$where = 'c.event_id IN (' . self::in_list( $ids ) . ')';
		$occurrence = absint( $request->get_param( 'occurrence_id' ) );
		if ( $occurrence ) { $where .= $wpdb->prepare( ' AND tt.occurrence_id = %d', $occurrence ); }
		$since = self::since( $request );
		if ( '' !== $since ) { $where .= $wpdb->prepare( ' AND c.created_at >= %s', $since ); }
		$where .= self::own_orders_sql( 't.order_id' ); // Resellers: check-ins of their own tickets only.
		$from = "{$p}wpcap_checkins c INNER JOIN {$p}wpcap_tickets t ON t.ticket_id=c.ticket_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id";
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $from WHERE $where" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT c.checkin_id,c.event_id,c.result,c.source,c.scanned_at,c.created_at,t.public_id AS ticket_public_id,tt.occurrence_id FROM $from WHERE $where ORDER BY c.checkin_id LIMIT " . (int) $offset . ',' . (int) $per_page ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out = array();
		foreach ( $rows as $c ) {
			$out[] = array( 'id' => (int) $c->checkin_id, 'ticket_id' => $c->ticket_public_id, 'event_id' => (int) $c->event_id, 'occurrence_id' => (int) $c->occurrence_id, 'result' => $c->result, 'source' => $c->source, 'scanned_at' => self::iso( $c->scanned_at ?: $c->created_at ), 'recorded_at' => self::iso( $c->created_at ) );
		}
		return self::respond( array( 'checkins' => $out, 'page' => $page, 'per_page' => $per_page, 'total' => $total ) );
	}

	public static function reports( $request ) {
		$ids = self::events_for( $request );
		if ( is_wp_error( $ids ) ) { return $ids; }
		$report = Data::build( array( 'from' => (string) $request->get_param( 'from' ), 'to' => (string) $request->get_param( 'to' ) ), $ids );
		$rates = array();
		foreach ( $report['rates'] as $bp => $b ) { $rates[] = array_merge( array( 'tax_rate_bp' => (int) $bp ), self::bucket( $b ) ); }
		$events = array();
		foreach ( $report['dimensions']['event'] as $id => $b ) { $events[] = array_merge( array( 'event_id' => (int) $id, 'name' => $b['label'] ), self::bucket( $b ) ); }
		$occurrences = array();
		foreach ( $report['occurrences'] as $o ) {
			$occurrences[] = array( 'occurrence_id' => $o['occurrence_id'], 'capacity' => $o['capacity'], 'sold' => $o['sold'], 'utilisation' => $o['utilisation'], 'issued' => $o['issued'], 'checked_in' => $o['checked_in'], 'checkin_rate' => $o['checkin_rate'] );
		}
		return self::respond( array( 'from' => $report['from'], 'to' => $report['to'], 'timezone' => $report['timezone'], 'currency' => $report['currency'], 'total' => self::bucket( $report['total'] ), 'rates' => $rates, 'events' => $events, 'occurrences' => $occurrences ) );
	}

	private static function bucket( array $b ) {
		return array( 'tickets' => $b['tickets'], 'gross_minor' => $b['gross'], 'tax_minor' => $b['tax'], 'net_minor' => $b['net'], 'refunded_tickets' => $b['refunded_tickets'], 'refunded_minor' => $b['refunded'], 'revenue_minor' => $b['revenue'], 'revenue_tax_minor' => $b['revenue_tax'] );
	}

	private static function settlement_payload( $s, $with_lines ) {
		$t = Settlements::decoded_totals( $s );
		$out = array(
			'id' => (int) $s->settlement_id,
			'number' => $s->document_number,
			'document_type' => Settlements::document_type( $s ),
			'sales_model' => (string) ( $s->sales_model ?? '' ),
			'self_billing_number' => $s->self_billing_number ?? null,
			'period' => $s->period,
			'status' => $s->status,
			'currency' => $s->currency,
			'tickets_sold' => (int) $s->tickets_sold,
			'tickets_refunded' => (int) $s->tickets_refunded,
			'gross_minor' => (int) $s->gross_minor,
			'tax_minor' => (int) $s->tax_minor,
			'net_minor' => (int) $s->net_minor,
			'refunded_minor' => (int) $s->refunded_minor,
			'corrections_minor' => (int) $s->corrections_minor,
			'commission_minor' => (int) $s->commission_minor,
			'fee_minor' => (int) $s->fee_minor,
			'platform_fee_net_minor' => (int) $s->platform_fee_net_minor,
			'platform_fee_tax_minor' => (int) $s->platform_fee_tax_minor,
			'platform_fee_gross_minor' => (int) $s->platform_fee_gross_minor,
			'payout_minor' => (int) $s->payout_minor,
			'rates' => (array) ( $t['rates'] ?? array() ),
			'finalized_at' => self::iso( $s->finalized_at ),
			'paid_at' => self::iso( $s->paid_at ),
		);
		if ( $with_lines ) {
			$out['lines'] = array_map( static function ( $l ) {
				return array( 'kind' => $l->kind, 'event_id' => (int) $l->event_id, 'origin_period' => $l->origin_period, 'occurred_at' => gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $l->occurred_at . ' UTC' ) ), 'tax_rate_bp' => (int) $l->tax_rate_bp, 'tickets' => (int) $l->tickets, 'gross_minor' => (int) $l->gross_minor, 'tax_minor' => (int) $l->tax_minor, 'net_minor' => (int) $l->net_minor, 'commission_minor' => (int) $l->commission_minor, 'fee_minor' => (int) $l->fee_minor );
			}, Settlements::lines( $s->settlement_id ) );
		}
		return $out;
	}

	/** Settlements cover all partner events, so event-restricted keys cannot read them. */
	private static function settlement_guard( $request ) {
		if ( '' === (string) self::$context['key']->event_ids ) { return true; }
		Keys::audit_failure( 'event_restricted_settlements', self::$context['key'], Keys::client_ip(), method_exists( $request, 'get_route' ) ? $request->get_route() : '' );
		return new \WP_Error( 'wpcap_partner_settlements_restricted', __( 'Abrechnungen sind nur mit API-Keys ohne Event-Einschränkung abrufbar.', 'pass-captain' ), array( 'status' => 403 ) );
	}

	/** Finalized and paid settlements of the partner (drafts are internal). */
	public static function settlements( $request ) {
		$guard = self::settlement_guard( $request );
		if ( is_wp_error( $guard ) ) { return $guard; }
		$list = Settlements::query( array( (int) self::$context['partner']->partner_id ), array( 'finalized', 'paid' ), 120 );
		return self::respond( array( 'settlements' => array_map( static function ( $s ) { return self::settlement_payload( $s, false ); }, $list ) ) );
	}

	public static function settlement( $request ) {
		$guard = self::settlement_guard( $request );
		if ( is_wp_error( $guard ) ) { return $guard; }
		$s = Settlements::get( absint( $request->get_param( 'settlement_id' ) ) );
		if ( ! $s || (int) $s->partner_id !== (int) self::$context['partner']->partner_id || 'draft' === $s->status ) {
			return new \WP_Error( 'wpcap_partner_settlement_missing', __( 'Abrechnung nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) );
		}
		return self::respond( self::settlement_payload( $s, true ) );
	}
}

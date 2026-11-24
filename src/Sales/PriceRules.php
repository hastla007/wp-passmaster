<?php
/**
 * Automatic price rules for every occurrence and ticket type: early bird, last minute, demand steps
 * and weekday/time-slot prices. Generalises the series price tiers (Series\Pricing) and reuses its
 * pure engine (normalize/matches/select/apply), so boundaries and precedence are identical:
 *
 * - early bird applies while now < until (UTC), last minute from starts_at − hours (inclusive),
 *   demand from sold % ≥ threshold (paid units + active holds of other orders against the type
 *   capacity, else the occurrence capacity; for an order of n units the position of its last unit
 *   counts, so a large order cannot buy past a step at the lower price, A617), weekday/time slot on the occurrence's local start
 *   (from inclusive, to exclusive, from > to wraps midnight);
 * - of all matching rules the highest priority wins (ties: higher demand threshold, older rule);
 *   event rules and the series rules of the occurrence compete in one selection.
 *
 * Event rules are scoped to an event and optionally to one occurrence, one ticket type or a
 * ticket type name. Prices are applied server-side through `wpcap_ticket_unit_price`; the winning
 * rule of each line is frozen in the order line snapshot (line_snapshot.price) together with a
 * buyer-facing explanation (explain()).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Sales;

use PassCaptain\Access;
use PassCaptain\Audit;
use PassCaptain\Series\Pricing as Engine;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class PriceRules {
	const CAP = 'wpcap_manage_events';

	private static $registered = false;

	/** Per-request cache of active rules by event id. */
	private static $cache = array();

	/** Winning rule per ticket type of the last price calculation (read by Orders::create). */
	private static $applied = array();

	/** Registers the price filters once (also called by the series module). */
	public static function register() {
		if ( self::$registered ) { return; }
		self::$registered = true;
		add_filter( 'wpcap_ticket_unit_price', array( __CLASS__, 'filter_unit_price' ), 10, 3 );
		add_filter( 'wpcap_ticket_price_label', array( __CLASS__, 'filter_price_label' ), 10, 3 );
	}

	public static function flush_cache() {
		self::$cache = array();
		self::$applied = array();
		if ( class_exists( Engine::class ) ) { Engine::flush_cache(); }
	}

	private static function error( $code, $message, $status = 400 ) {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Storage.
	 * ------------------------------------------------------------------------------------------- */

	/** Active rules of an event (cached per request). */
	public static function for_event( $event_id ) {
		global $wpdb;
		$event_id = absint( $event_id );
		if ( ! isset( self::$cache[ $event_id ] ) && \PassCaptain\DisplayBatch::active() ) {
			// Display batch: the rules of every event of the page in one query.
			$ids = array();
			foreach ( \PassCaptain\DisplayBatch::occurrences() as $occurrence ) {
				if ( ! isset( self::$cache[ (int) $occurrence->event_id ] ) ) { $ids[ (int) $occurrence->event_id ] = (int) $occurrence->event_id; }
			}
			if ( isset( $ids[ $event_id ] ) ) {
				$ids = array_values( $ids );
				$grouped = array_fill_keys( $ids, array() );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- generated placeholders.
				foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE event_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ") AND status='active' ORDER BY rule_id", array_merge( array( $wpdb->prefix . 'wpcap_price_rules' ), $ids ) ) ) as $row ) {
					$row->source = 'event';
					$grouped[ (int) $row->event_id ][] = $row;
				}
				foreach ( $grouped as $id => $rows ) { self::$cache[ $id ] = $rows; }
			}
		}
		if ( ! isset( self::$cache[ $event_id ] ) ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE event_id=%d AND status='active' ORDER BY rule_id", $wpdb->prefix . 'wpcap_price_rules', $event_id ) );
			foreach ( $rows as $row ) { $row->source = 'event'; }
			self::$cache[ $event_id ] = $rows;
		}
		return self::$cache[ $event_id ];
	}

	/** Rules of an event for the admin list (active only). */
	public static function list_for_event( $event_id ) {
		return self::for_event( $event_id );
	}

	/**
	 * Creates an event rule.
	 *
	 * Input as Series\Pricing::normalize() plus occurrence_id and ticket_type_id (0 = all). The
	 * early bird date is local time of the event's (first) occurrence timezone.
	 *
	 * @return int|\WP_Error rule id
	 */
	public static function create( $event_id, array $in ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$event_id = absint( $event_id );
		$event = $wpdb->get_row( $wpdb->prepare( 'SELECT event_id FROM %i WHERE event_id=%d', $p . 'wpcap_events', $event_id ) );
		if ( ! $event ) { return self::error( 'wpcap_event_missing', __( 'Event nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! Access::can_access_event( $event_id, self::CAP ) ) { return self::error( 'wpcap_admin_forbidden', __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ), 403 ); }
		$occurrence_id = absint( $in['occurrence_id'] ?? 0 );
		$type_id = absint( $in['ticket_type_id'] ?? 0 );
		if ( $occurrence_id && (int) $wpdb->get_var( $wpdb->prepare( 'SELECT event_id FROM %i WHERE occurrence_id=%d', $p . 'wpcap_occurrences', $occurrence_id ) ) !== $event_id ) {
			return self::error( 'wpcap_price_rule_scope', __( 'Der Termin gehört nicht zu diesem Event.', 'pass-captain' ) );
		}
		$type = null;
		if ( $type_id ) {
			$type = $wpdb->get_row( $wpdb->prepare( 'SELECT tt.ticket_type_id,tt.occurrence_id,tt.currency,o.event_id,o.timezone FROM %i tt INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=%d', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $type_id ) );
			if ( ! $type || (int) $type->event_id !== $event_id || ( $occurrence_id && (int) $type->occurrence_id !== $occurrence_id ) ) {
				return self::error( 'wpcap_price_rule_scope', __( 'Die Ticketart gehört nicht zu diesem Event bzw. Termin.', 'pass-captain' ) );
			}
		}
		$ref = $wpdb->get_row( $wpdb->prepare( "SELECT o.timezone,tt.currency FROM {$p}wpcap_occurrences o LEFT JOIN {$p}wpcap_ticket_types tt ON tt.occurrence_id=o.occurrence_id WHERE o.event_id=%d" . ( $occurrence_id ? ' AND o.occurrence_id=' . $occurrence_id : '' ) . ' ORDER BY o.starts_at,tt.ticket_type_id LIMIT 1', $event_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- integer appended.
		$timezone = $type ? (string) $type->timezone : (string) ( $ref->timezone ?? 'UTC' );
		$currency = $type ? (string) $type->currency : (string) ( $ref->currency ?? \PassCaptain\Localization::default_currency() );
		$rule = Engine::normalize( $in, $timezone ?: 'UTC', $currency ?: \PassCaptain\Localization::default_currency() );
		if ( is_wp_error( $rule ) ) { return $rule; }
		$now = \PassCaptain\Clock::mysql();
		$ok = $wpdb->insert( "{$p}wpcap_price_rules", $rule + array( 'event_id' => $event_id, 'occurrence_id' => $occurrence_id, 'ticket_type_id' => $type_id, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) );
		if ( ! $ok ) { return self::error( 'wpcap_price_rule_save', __( 'Die Preisregel konnte nicht gespeichert werden.', 'pass-captain' ), 500 ); }
		$rule_id = (int) $wpdb->insert_id;
		self::flush_cache();
		Audit::record( 'price_rule_created', 'event', $event_id, array( 'rule_id' => $rule_id, 'kind' => $rule['kind'], 'occurrence_id' => $occurrence_id, 'ticket_type_id' => $type_id ) );
		return $rule_id;
	}

	/** Deactivates an event rule (kept for the audit trail and order snapshots). */
	public static function delete( $rule_id ) {
		global $wpdb;
		$rule = $wpdb->get_row( $wpdb->prepare( 'SELECT rule_id,event_id FROM %i WHERE rule_id=%d', $wpdb->prefix . 'wpcap_price_rules', absint( $rule_id ) ) );
		if ( ! $rule ) { return self::error( 'wpcap_price_rule_missing', __( 'Preisregel nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! Access::can_access_event( (int) $rule->event_id, self::CAP ) ) { return self::error( 'wpcap_admin_forbidden', __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ), 403 ); }
		$wpdb->update( "{$wpdb->prefix}wpcap_price_rules", array( 'status' => 'deleted', 'updated_at' => \PassCaptain\Clock::mysql() ), array( 'rule_id' => (int) $rule->rule_id ) );
		self::flush_cache();
		Audit::record( 'price_rule_deleted', 'event', (int) $rule->event_id, array( 'rule_id' => (int) $rule->rule_id ) );
		return true;
	}

	/* ---------------------------------------------------------------------------------------------
	 * Selection and application.
	 * ------------------------------------------------------------------------------------------- */

	/** Candidate rules for a ticket type: event rules in scope plus the series rules of the occurrence. */
	public static function candidates( $type, $occurrence ) {
		if ( ! $occurrence ) { return array(); }
		$out = array();
		foreach ( self::for_event( (int) $occurrence->event_id ) as $rule ) {
			if ( (int) $rule->occurrence_id && (int) $rule->occurrence_id !== (int) $occurrence->occurrence_id ) { continue; }
			if ( (int) $rule->ticket_type_id && (int) $rule->ticket_type_id !== (int) ( $type->ticket_type_id ?? 0 ) ) { continue; }
			$out[] = $rule;
		}
		$series_id = Engine::series_of( $occurrence->occurrence_id );
		if ( $series_id ) {
			foreach ( Engine::for_series( $series_id ) as $rule ) {
				$rule->source = 'series';
				$out[] = $rule;
			}
		}
		return $out;
	}

	/** Winning rule for a ticket type in context (null = stored price). */
	public static function rule_for( $type, array $context ) {
		$occurrence = $context['occurrence'] ?? null;
		if ( ! $occurrence || ! isset( $occurrence->event_id ) ) { return null; }
		$rules = self::candidates( $type, $occurrence );
		if ( ! $rules ) { return null; }
		$now = (string) ( $context['now'] ?? \PassCaptain\Clock::mysql() );
		return Engine::select( $rules, array(
			'now' => $now,
			'starts_at' => $occurrence->starts_at,
			'timezone' => $occurrence->timezone ?? 'UTC',
			'type_name' => $type->name ?? '',
			'sold_pct' => static function () use ( $type, $occurrence, $now, $context ) {
				$pct = Engine::sold_pct( $type, $occurrence, $now );
				// A617: one large order must not buy past a demand step at the lower price. The step applies when
				// the order's last unit lands in it (units before it ≥ threshold); one unit = the stored sold %.
				$extra = max( 0, absint( $context['quantity'] ?? 1 ) - 1 );
				$capacity = (int) ( $type->capacity ?? 0 ) > 0 ? (int) $type->capacity : (int) ( $occurrence->capacity ?? 0 );
				return null === $pct || ! $extra || $capacity < 1 ? $pct : $pct + 100 * $extra / $capacity;
			},
		) );
	}

	/** Filter callback for `wpcap_ticket_unit_price`. */
	public static function filter_unit_price( $price, $type, $context = array() ) {
		$context = (array) $context;
		$type_id = (int) ( $type->ticket_type_id ?? 0 );
		$rule = self::rule_for( $type, $context );
		unset( self::$applied[ $type_id ] );
		if ( ! $rule ) { return $price; }
		$new = Engine::apply( (int) $price, $rule );
		self::$applied[ $type_id ] = array(
			'rule_id' => (int) ( $rule->rule_id ?? 0 ),
			'source' => (string) ( $rule->source ?? 'event' ),
			'kind' => (string) $rule->kind,
			'label' => self::label( $rule ),
			'price_mode' => (string) $rule->price_mode,
			'amount' => (int) $rule->amount,
			'priority' => (int) $rule->priority,
			'base_minor' => (int) $price,
			'unit_minor' => (int) $new,
			'explanation' => self::explain( $rule, $context['occurrence'] ?? null ),
			'evaluated_at' => (string) ( $context['now'] ?? \PassCaptain\Clock::mysql() ),
		);
		return $new;
	}

	/**
	 * Rule applied to a ticket type by the last `wpcap_ticket_unit_price` run in this request, or
	 * null. Orders::create() reads it right after Orders::unit_price() for the line snapshot.
	 */
	public static function applied( $ticket_type_id ) {
		return self::$applied[ absint( $ticket_type_id ) ] ?? null;
	}

	/** Filter callback for `wpcap_ticket_price_label` (public label of the active rule). */
	public static function filter_price_label( $label, $type, $context = array() ) {
		$rule = self::rule_for( $type, (array) $context );
		return $rule ? self::label( $rule ) : $label;
	}

	/** Public name of a rule (own label or the kind). */
	public static function label( $rule ) {
		$rule = (object) $rule;
		if ( '' !== (string) ( $rule->label ?? '' ) ) { return (string) $rule->label; }
		return Engine::kind_labels()[ $rule->kind ] ?? '';
	}

	/** Buyer-facing explanation of why this price applies, e.g. "Frühbucherpreis bis 01.12.2026 18:00". */
	public static function explain( $rule, $occurrence = null ) {
		$rule = (object) $rule;
		$timezone = $occurrence && ! empty( $occurrence->timezone ) ? (string) $occurrence->timezone : 'UTC';
		$label = self::label( $rule );
		switch ( $rule->kind ) {
			case 'early_bird':
				/* translators: 1: rule name, 2: local date/time */
				return sprintf( __( '%1$s: gilt bei Buchung bis %2$s', 'pass-captain' ), $label, \PassCaptain\Admin\Forms::datetime( $rule->until_at, $timezone, false ) );
			case 'last_minute':
				/* translators: 1: rule name, 2: hours */
				return sprintf( __( '%1$s: gilt ab %2$d Stunden vor Beginn', 'pass-captain' ), $label, (int) $rule->hours_before );
			case 'demand':
				/* translators: 1: rule name, 2: percentage */
				return sprintf( __( '%1$s: gilt, sobald %2$d %% der Tickets verkauft oder reserviert sind', 'pass-captain' ), $label, (int) $rule->sold_pct );
			case 'weekday':
				$names = array( 1 => __( 'Mo', 'pass-captain' ), 2 => __( 'Di', 'pass-captain' ), 3 => __( 'Mi', 'pass-captain' ), 4 => __( 'Do', 'pass-captain' ), 5 => __( 'Fr', 'pass-captain' ), 6 => __( 'Sa', 'pass-captain' ), 7 => __( 'So', 'pass-captain' ) );
				$days = '' === (string) $rule->weekdays ? array() : array_map( static function ( $d ) use ( $names ) { return $names[ (int) $d ] ?? ''; }, explode( ',', (string) $rule->weekdays ) );
				$parts = array();
				if ( $days ) { $parts[] = implode( ', ', $days ); }
				if ( '' !== (string) $rule->time_from ) { $parts[] = $rule->time_from . '–' . $rule->time_to; }
				/* translators: 1: rule name, 2: weekdays and/or time slot */
				return sprintf( __( '%1$s: gilt für Termine %2$s', 'pass-captain' ), $label, implode( ' ', $parts ) );
		}
		return $label;
	}

	/** Explanation of the rule currently active for a ticket type ('' = stored price). */
	public static function explanation_for( $type, $occurrence, array $context = array() ) {
		$rule = self::rule_for( $type, array_merge( $context, array( 'occurrence' => $occurrence ) ) );
		return $rule ? self::explain( $rule, $occurrence ) : '';
	}
}

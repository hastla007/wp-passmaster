<?php
/**
 * Presentation helpers for the event card and the calendar (A100): occurrence badges ("Nächster
 * Termin", "Abgesagt", "Verschoben", "Ausverkauft", "Wenige Plätze", "Einlass ab …"), seats left per
 * occurrence, "price from", the next bookable date of each event and the quantity limits the card's
 * stepper enforces. Thresholds: filter `wpcap_event_card_thresholds`.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Frontend;

use PassCaptain\DoorsOpen;
use PassCaptain\Orders;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class EventCard {
	/**
	 * few_left: seats left at or below which "Wenige Plätze" is shown (0 = never);
	 * rescheduled_days: how long after a reschedule "Verschoben" is shown (0 = until the date);
	 * visible_types: ticket types shown before the rest is collapsed.
	 *
	 * @return array{few_left:int, rescheduled_days:int, visible_types:int}
	 */
	public static function thresholds() {
		$t = (array) apply_filters( 'wpcap_event_card_thresholds', array( 'few_left' => 10, 'rescheduled_days' => 0, 'visible_types' => 4 ) );
		return array(
			'few_left' => max( 0, (int) ( $t['few_left'] ?? 10 ) ),
			'rescheduled_days' => max( 0, (int) ( $t['rescheduled_days'] ?? 0 ) ),
			'visible_types' => max( 1, (int) ( $t['visible_types'] ?? 4 ) ),
		);
	}

	/** SQL expression: time of the last reschedule of occurrence alias $alias (NULL if never). */
	public static function rescheduled_sql( $alias = 'o' ) {
		global $wpdb;
		$alias = preg_replace( '/[^a-z_]/', '', (string) $alias );
		return "(SELECT MAX(a.created_at) FROM {$wpdb->prefix}wpcap_audit_logs a WHERE a.object_type='occurrence' AND a.object_id={$alias}.occurrence_id AND a.action='occurrence_rescheduled')";
	}

	/**
	 * Seats left for an occurrence from its ticket types (->available: int, null = unlimited), capped
	 * by the occurrence's own capacity when its counters are loaded.
	 *
	 * @return int|null null = unlimited.
	 */
	public static function occurrence_available( array $types, $occurrence = null ) {
		$sum = 0;
		$unlimited = false;
		foreach ( $types as $type ) {
			if ( null === $type->available ) { $unlimited = true; } else { $sum += max( 0, (int) $type->available ); }
		}
		$left = $unlimited ? null : $sum;
		if ( is_object( $occurrence ) && (int) ( $occurrence->capacity ?? 0 ) > 0 && isset( $occurrence->sold_qty ) ) {
			$rest = max( 0, (int) $occurrence->capacity - (int) $occurrence->sold_qty - (int) ( $occurrence->held_qty ?? 0 ) );
			$left = null === $left ? $rest : min( $left, $rest );
		}
		return $left;
	}

	/**
	 * Seats left for many occurrences (calendar): one query for the ticket types on sale plus the
	 * availability. Occurrences without a ticket type on sale are missing from the result.
	 *
	 * @param object[] $rows Occurrence rows (occurrence_id; capacity, sold_qty, held_qty, status when loaded).
	 * @return array occurrence_id => int|null
	 */
	public static function availability_for( array $rows ) {
		global $wpdb;
		$ids = array();
		$occurrences = array();
		foreach ( $rows as $row ) {
			if ( 'cancelled' === ( $row->status ?? '' ) ) { continue; }
			$ids[] = (int) $row->occurrence_id;
			if ( isset( $row->status, $row->capacity ) ) { $occurrences[ (int) $row->occurrence_id ] = $row; }
		}
		if ( ! $ids ) { return array(); }
		$now = current_time( 'mysql', true );
		$by_occurrence = array();
		$types = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_ticket_types WHERE occurrence_id IN (" . implode( ',', array_unique( $ids ) ) . ") AND status='active' AND (sales_start IS NULL OR sales_start <= %s) AND (sales_end IS NULL OR sales_end >= %s)", $now, $now ) ) as $type ) {
			$types[ (int) $type->ticket_type_id ] = $type;
			$by_occurrence[ (int) $type->occurrence_id ][] = $type;
		}
		if ( ! $types ) { return array(); }
		$available = Orders::availability_many( array_keys( $types ), array( 'types' => $types, 'occurrences' => $occurrences ) );
		$out = array();
		foreach ( $by_occurrence as $occ_id => $list ) {
			$prepared = array();
			foreach ( $list as $type ) {
				$prepared[] = (object) array( 'available' => array_key_exists( (int) $type->ticket_type_id, $available ) ? $available[ (int) $type->ticket_type_id ] : 0 );
			}
			$out[ $occ_id ] = self::occurrence_available( $prepared, $occurrences[ $occ_id ] ?? null );
		}
		return $out;
	}

	/**
	 * Next bookable occurrence per event, among events with at least two upcoming dates in $rows
	 * (rows sorted by start). Bookable: scheduled, tickets on sale and not sold out.
	 *
	 * @param object[] $rows      Occurrence rows (occurrence_id, event_id, status optional).
	 * @param array    $available occurrence_id => int|null (missing = nothing on sale).
	 * @return array occurrence_id => true
	 */
	public static function next_occurrences( array $rows, array $available ) {
		$count = array();
		foreach ( $rows as $row ) {
			if ( 'cancelled' !== ( $row->status ?? 'scheduled' ) ) { $count[ (int) $row->event_id ] = ( $count[ (int) $row->event_id ] ?? 0 ) + 1; }
		}
		$next = array();
		$done = array();
		foreach ( $rows as $row ) {
			$event_id = (int) $row->event_id;
			$occ_id = (int) $row->occurrence_id;
			if ( isset( $done[ $event_id ] ) || ( $count[ $event_id ] ?? 0 ) < 2 || 'cancelled' === ( $row->status ?? 'scheduled' ) ) { continue; }
			if ( ! array_key_exists( $occ_id, $available ) || ( null !== $available[ $occ_id ] && (int) $available[ $occ_id ] < 1 ) ) { continue; }
			$next[ $occ_id ] = true;
			$done[ $event_id ] = true;
		}
		return $next;
	}

	/**
	 * Badges of an occurrence, in display order.
	 *
	 * @param object         $occurrence Row with status, starts_at, timezone; doors_open_at, rescheduled_at when loaded.
	 * @param int|null|false $available  Seats left (null = unlimited, false = unknown / not on sale).
	 * @param array          $context    next (bool), doors (bool, default true), view ('card', 'dates', 'calendar').
	 * @return array[] {key, label}
	 */
	public static function badges( $occurrence, $available, array $context = array() ) {
		$th = self::thresholds();
		$badges = array();
		if ( ! empty( $context['next'] ) ) {
			$badges[] = array( 'key' => 'next', 'label' => __( 'Nächster Termin', 'pass-captain' ) );
		}
		if ( 'cancelled' === ( $occurrence->status ?? '' ) ) {
			$badges[] = array( 'key' => 'cancelled', 'label' => __( 'Abgesagt', 'pass-captain' ) );
		} else {
			$moved = (string) ( $occurrence->rescheduled_at ?? '' );
			if ( '' !== $moved && ( ! $th['rescheduled_days'] || strtotime( $moved . ' UTC' ) >= time() - $th['rescheduled_days'] * DAY_IN_SECONDS ) ) {
				$badges[] = array( 'key' => 'rescheduled', 'label' => __( 'Verschoben', 'pass-captain' ) );
			}
			if ( false !== $available && null !== $available ) {
				if ( (int) $available < 1 ) {
					$badges[] = array( 'key' => 'soldout', 'label' => __( 'Ausverkauft', 'pass-captain' ) );
				} elseif ( $th['few_left'] && (int) $available <= $th['few_left'] ) {
					$badges[] = array( 'key' => 'few_left', 'label' => __( 'Wenige Plätze', 'pass-captain' ) );
				}
			}
			if ( ( $context['doors'] ?? true ) && ! empty( $occurrence->doors_open_at ) ) {
				$label = DoorsOpen::label( $occurrence->doors_open_at, (string) ( $occurrence->timezone ?? 'UTC' ), (string) ( $occurrence->starts_at ?? '' ) );
				if ( '' !== $label ) { $badges[] = array( 'key' => 'doors', 'label' => $label ); }
			}
		}
		$badges = (array) apply_filters( 'wpcap_occurrence_badges', $badges, $occurrence, $available, $context );
		return array_values( array_filter( $badges, static function ( $badge ) { return is_array( $badge ) && '' !== (string) ( $badge['label'] ?? '' ); } ) );
	}

	/** Badge list markup ('' without badges). */
	public static function render_badges( array $badges ) {
		if ( ! $badges ) { return ''; }
		$out = '<ul class="wpcap-badges">';
		foreach ( $badges as $badge ) {
			$key = preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) ( $badge['key'] ?? '' ) ) );
			$out .= '<li class="wpcap-badge' . ( '' !== $key ? ' wpcap-badge-' . $key : '' ) . '">' . esc_html( (string) $badge['label'] ) . '</li>';
		}
		return $out . '</ul>';
	}

	/** "Tickets verfügbar", "Nur noch 3 Plätze frei" (at or below few_left) or "Ausverkauft". */
	public static function availability_text( $available ) {
		if ( null === $available ) { return __( 'Tickets verfügbar', 'pass-captain' ); }
		$available = (int) $available;
		if ( $available < 1 ) { return __( 'Ausverkauft', 'pass-captain' ); }
		$th = self::thresholds();
		if ( $th['few_left'] && $available <= $th['few_left'] ) {
			/* translators: %d: seats left */
			return sprintf( _n( 'Nur noch %d Platz frei', 'Nur noch %d Plätze frei', $available, 'pass-captain' ), $available );
		}
		return __( 'Tickets verfügbar', 'pass-captain' );
	}

	/** "19,90 €" when all prices are equal, else "ab 9,90 €" (lowest price of the types still on sale). */
	public static function price_from( array $types ) {
		if ( ! $types ) { return ''; }
		$open = array_filter( $types, static function ( $type ) { return null === $type->available || (int) $type->available > 0; } );
		$prices = array_map( static function ( $type ) { return (int) $type->price_minor; }, $open ? $open : $types );
		$min = min( $prices );
		$money = Format::money( $min, $types[0]->currency );
		/* translators: %s: lowest price */
		return min( $prices ) === max( $prices ) ? $money : sprintf( __( 'ab %s', 'pass-captain' ), $money );
	}

	/** Most tickets of a type one order may hold on this card (max per order, seats left, buyer limits). */
	public static function quantity_max( $type, $event = null ) {
		$max = null === $type->available ? (int) $type->max_per_order : min( (int) $type->max_per_order, (int) $type->available );
		return max( 0, min( $max, (int) apply_filters( 'wpcap_event_card_quantity_max', $max, $type, $event ) ) );
	}

	/** Most tickets of all types together on this card (0 = no limit), e.g. a buyer limit per event. */
	public static function max_total( $event, array $types ) {
		return max( 0, (int) apply_filters( 'wpcap_event_card_max_total', 0, $event, $types ) );
	}

	/** Machine-readable start (ISO 8601, UTC) for <time datetime>. */
	public static function iso( $utc ) {
		$ts = strtotime( (string) $utc . ' UTC' );
		return false === $ts ? '' : gmdate( 'Y-m-d\TH:i:s\Z', $ts );
	}

	/**
	 * Everything the card shows above the form: badges, seats left, price, other dates of the event.
	 *
	 * @param object   $event   Listing row.
	 * @param object[] $types   Ticket types of the occurrence (prepared by PublicEvents::listing_data()).
	 * @param array    $listing next (occurrence_id => true), occurrences (id => row), dates (event_id => rows), available (occurrence_id => int|null).
	 */
	public static function context( $event, array $types, array $listing = array() ) {
		$occ_id = (int) $event->occurrence_id;
		$occurrence = $listing['occurrences'][ $occ_id ] ?? null;
		$available = array_key_exists( $occ_id, $listing['available'] ?? array() ) ? $listing['available'][ $occ_id ] : self::occurrence_available( $types, $occurrence );
		$row = clone $event;
		$row->status = 'scheduled';
		$row->rescheduled_at = $occurrence->rescheduled_at ?? null;
		$next = ! empty( $listing['next'][ $occ_id ] );
		$dates = array();
		foreach ( (array) ( $listing['dates'][ (int) $event->event_id ] ?? array() ) as $other ) {
			if ( (int) $other->occurrence_id === $occ_id ) { continue; }
			$other_id = (int) $other->occurrence_id;
			$other_available = array_key_exists( $other_id, $listing['available'] ?? array() ) ? $listing['available'][ $other_id ] : false;
			$dates[] = array(
				'occurrence_id' => $other_id,
				'iso' => self::iso( $other->starts_at ),
				'label' => Format::event_start( $other->starts_at, $other->timezone ),
				'linked' => 'cancelled' !== ( $other->status ?? '' ) && ! empty( $other->listed ),
				'badges' => self::badges( $other, $other_available, array( 'next' => ! empty( $listing['next'][ $other_id ] ), 'view' => 'dates' ) ),
			);
		}
		return array(
			'next' => $next,
			'available' => $available,
			'availability' => self::availability_text( $available ),
			'price' => self::price_from( $types ),
			'badges' => self::badges( $row, $available, array( 'next' => $next, 'doors' => false, 'view' => 'card' ) ),
			'dates' => $dates,
			'visible_types' => self::thresholds()['visible_types'],
			'max_total' => self::max_total( $event, $types ),
		);
	}
}

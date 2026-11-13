<?php
/**
 * Price tiers for series occurrences: early bird (until a date), last minute (from X hours before
 * start), demand steps (from X % sold) and weekday/time-slot prices.
 *
 * Rules belong to a series and apply to its occurrences' ticket types, optionally only to types
 * with a given name (generated ticket types are copies, so the name identifies them across dates).
 * A rule either sets a fixed gross price or changes the stored price by a percentage.
 *
 * Selection: of all matching rules the one with the highest priority wins; equal priority →
 * the higher demand threshold, then the older rule. Default priorities: last minute 40, demand 30,
 * early bird 20, weekday/time slot 10. No match → stored price.
 *
 * Boundaries (all compared in UTC): early bird applies while now < until; last minute applies from
 * starts_at − hours (inclusive); demand applies from sold % ≥ threshold, where sold counts paid
 * units and active holds of other orders against the type capacity (else the occurrence capacity).
 * Weekday/time slots use the occurrence's local start time; a slot "from" is inclusive, "to"
 * exclusive, and a slot with from > to wraps midnight.
 *
 * Prices are applied server-side through the core filter `wpcap_ticket_unit_price`.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Series;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Pricing {
	const KINDS = array( 'early_bird', 'last_minute', 'demand', 'weekday' );
	const DEFAULT_PRIORITY = array( 'last_minute' => 40, 'demand' => 30, 'early_bird' => 20, 'weekday' => 10 );

	/** Per-request cache of rules by series id. */
	private static $cache = array();

	public static function schema( $prefix, $charset ) {
		return array(
			"CREATE TABLE {$prefix}wpcap_series_price_rules (
				rule_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				series_id bigint(20) unsigned NOT NULL,
				label varchar(190) NOT NULL DEFAULT '',
				type_name varchar(190) NOT NULL DEFAULT '',
				kind varchar(20) NOT NULL,
				priority int(11) NOT NULL DEFAULT 0,
				until_at datetime NULL DEFAULT NULL,
				hours_before int(10) unsigned NOT NULL DEFAULT 0,
				sold_pct smallint(5) unsigned NOT NULL DEFAULT 0,
				weekdays varchar(20) NOT NULL DEFAULT '',
				time_from char(5) NOT NULL DEFAULT '',
				time_to char(5) NOT NULL DEFAULT '',
				price_mode varchar(10) NOT NULL DEFAULT 'fixed',
				amount bigint(20) NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (rule_id),
				KEY series_status (series_id,status)
			) ENGINE=InnoDB $charset;",
		);
	}

	public static function register() {
		add_filter( 'wpcap_ticket_unit_price', array( __CLASS__, 'filter_unit_price' ), 10, 3 );
		add_filter( 'wpcap_ticket_price_label', array( __CLASS__, 'filter_price_label' ), 10, 3 );
	}

	public static function flush_cache() {
		self::$cache = array();
	}

	private static function error( $code, $message, $status = 400 ) {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Rule storage.
	 * ------------------------------------------------------------------------------------------- */

	/** Active rules of a series (cached per request). */
	public static function for_series( $series_id ) {
		global $wpdb;
		$series_id = absint( $series_id );
		if ( ! isset( self::$cache[ $series_id ] ) ) {
			self::$cache[ $series_id ] = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_series_price_rules WHERE series_id=%d AND status='active' ORDER BY rule_id", $series_id ) );
		}
		return self::$cache[ $series_id ];
	}

	/** Series row with its timezone and currency (from the generated ticket types). */
	public static function series( $series_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_series WHERE series_id=%d", absint( $series_id ) ) );
		if ( ! $row ) { return null; }
		$definition = json_decode( (string) $row->definition, true );
		$row->timezone = (string) ( $definition['rule']['timezone'] ?? 'UTC' );
		$row->currency = (string) $wpdb->get_var( $wpdb->prepare( "SELECT tt.currency FROM {$wpdb->prefix}wpcap_ticket_types tt INNER JOIN {$wpdb->prefix}wpcap_series_occurrences so ON so.occurrence_id=tt.occurrence_id WHERE so.series_id=%d ORDER BY tt.ticket_type_id LIMIT 1", $row->series_id ) );
		if ( '' === $row->currency ) { $row->currency = 'EUR'; }
		return $row;
	}

	/** Distinct ticket type names of a series (for the admin scope select). */
	public static function type_names( $series_id ) {
		global $wpdb;
		return $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT tt.name FROM {$wpdb->prefix}wpcap_ticket_types tt INNER JOIN {$wpdb->prefix}wpcap_series_occurrences so ON so.occurrence_id=tt.occurrence_id WHERE so.series_id=%d ORDER BY tt.name", absint( $series_id ) ) );
	}

	/** "HH:MM" or '' (null when invalid). */
	private static function clock( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) { return ''; }
		return preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $value, $m ) ? sprintf( '%02d:%02d', $m[1], $m[2] ) : null;
	}

	/**
	 * Validates admin input into a rule row.
	 *
	 * Input: label, type_name, kind, priority ('' = default), price_mode (fixed|percent),
	 * amount (fixed: money string; percent: signed percentage like "-20" or "15,5"),
	 * until (local datetime in the series timezone), hours_before, sold_pct, weekdays[], time_from, time_to.
	 *
	 * @return array|\WP_Error
	 */
	public static function normalize( array $in, $timezone, $currency = 'EUR' ) {
		$kind = in_array( $in['kind'] ?? '', self::KINDS, true ) ? $in['kind'] : '';
		if ( '' === $kind ) { return self::error( 'wpcap_series_rule_kind', __( 'Bitte eine Art der Preisregel wählen.', 'wp-pass-captain' ) ); }
		$mode = 'percent' === ( $in['price_mode'] ?? '' ) ? 'percent' : 'fixed';
		$raw_amount = is_scalar( $in['amount'] ?? null ) ? trim( (string) $in['amount'] ) : '';
		if ( 'fixed' === $mode ) {
			$amount = \PassCaptain\Admin\Forms::parse_money( $raw_amount, \PassCaptain\Admin\Forms::currency_decimals( $currency ) );
			if ( null === $amount ) { return self::error( 'wpcap_series_rule_amount', __( 'Bitte einen gültigen Preis angeben.', 'wp-pass-captain' ) ); }
		} else {
			$raw_amount = str_replace( '−', '-', $raw_amount );
			$negative = 0 === strpos( $raw_amount, '-' );
			$bp = \PassCaptain\Admin\Forms::parse_percent_bp( ltrim( $raw_amount, '+-' ) );
			if ( null === $bp || '' === $raw_amount || 0 === $bp ) { return self::error( 'wpcap_series_rule_amount', __( 'Bitte eine gültige prozentuale Änderung angeben (z. B. -20 oder 15).', 'wp-pass-captain' ) ); }
			$amount = $negative ? -$bp : $bp;
		}
		$priority_raw = is_scalar( $in['priority'] ?? null ) ? trim( (string) $in['priority'] ) : '';
		if ( '' !== $priority_raw && ! preg_match( '/^-?\d{1,4}$/', $priority_raw ) ) { return self::error( 'wpcap_series_rule_priority', __( 'Die Priorität muss eine ganze Zahl sein.', 'wp-pass-captain' ) ); }
		$rule = array(
			'label' => mb_substr( sanitize_text_field( (string) ( $in['label'] ?? '' ) ), 0, 190 ),
			'type_name' => mb_substr( sanitize_text_field( (string) ( $in['type_name'] ?? '' ) ), 0, 190 ),
			'kind' => $kind,
			'priority' => '' === $priority_raw ? self::DEFAULT_PRIORITY[ $kind ] : (int) $priority_raw,
			'until_at' => null,
			'hours_before' => 0,
			'sold_pct' => 0,
			'weekdays' => '',
			'time_from' => '',
			'time_to' => '',
			'price_mode' => $mode,
			'amount' => (int) $amount,
		);
		if ( 'early_bird' === $kind ) {
			$rule['until_at'] = \PassCaptain\Admin\Forms::local_to_utc( $in['until'] ?? '', $timezone );
			if ( null === $rule['until_at'] ) { return self::error( 'wpcap_series_rule_until', __( 'Bitte ein gültiges Datum „Frühbucher bis“ angeben.', 'wp-pass-captain' ) ); }
		} elseif ( 'last_minute' === $kind ) {
			$rule['hours_before'] = absint( $in['hours_before'] ?? 0 );
			if ( $rule['hours_before'] < 1 || $rule['hours_before'] > 24 * 60 ) { return self::error( 'wpcap_series_rule_hours', __( 'Last-Minute: Stunden vor Beginn zwischen 1 und 1440 angeben.', 'wp-pass-captain' ) ); }
		} elseif ( 'demand' === $kind ) {
			$rule['sold_pct'] = absint( $in['sold_pct'] ?? 0 );
			if ( $rule['sold_pct'] < 1 || $rule['sold_pct'] > 100 ) { return self::error( 'wpcap_series_rule_pct', __( 'Nachfragestufe: Verkaufsanteil zwischen 1 und 100 % angeben.', 'wp-pass-captain' ) ); }
		} else {
			$days = array_values( array_unique( array_filter( array_map( 'absint', array_filter( (array) ( $in['weekdays'] ?? array() ), 'is_scalar' ) ), static function ( $d ) { return $d >= 1 && $d <= 7; } ) ) );
			sort( $days );
			$from = self::clock( $in['time_from'] ?? '' );
			$to = self::clock( $in['time_to'] ?? '' );
			if ( null === $from || null === $to || ( '' === $from ) !== ( '' === $to ) || ( '' !== $from && $from === $to ) ) { return self::error( 'wpcap_series_rule_slot', __( 'Zeitfenster: Beginn und Ende (HH:MM) beide angeben oder beide leer lassen.', 'wp-pass-captain' ) ); }
			if ( ! $days && '' === $from ) { return self::error( 'wpcap_series_rule_slot', __( 'Bitte Wochentage und/oder ein Zeitfenster angeben.', 'wp-pass-captain' ) ); }
			$rule['weekdays'] = implode( ',', $days );
			$rule['time_from'] = $from;
			$rule['time_to'] = $to;
		}
		return $rule;
	}

	/** Creates a rule for a series. @return int|\WP_Error rule id */
	public static function create( $series_id, array $in ) {
		global $wpdb;
		$series = self::series( $series_id );
		if ( ! $series ) { return self::error( 'wpcap_series_missing', __( 'Serie nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		if ( ! \PassCaptain\Access::can_access_event( (int) $series->event_id, Generator::CAP ) ) { return self::error( 'wpcap_admin_forbidden', __( 'Keine Berechtigung für dieses Event.', 'wp-pass-captain' ), 403 ); }
		$rule = self::normalize( $in, $series->timezone, $series->currency );
		if ( is_wp_error( $rule ) ) { return $rule; }
		$now = current_time( 'mysql', true );
		$ok = $wpdb->insert( "{$wpdb->prefix}wpcap_series_price_rules", $rule + array( 'series_id' => (int) $series->series_id, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) );
		if ( ! $ok ) { return self::error( 'wpcap_series_rule_save', __( 'Die Preisregel konnte nicht gespeichert werden.', 'wp-pass-captain' ), 500 ); }
		$rule_id = (int) $wpdb->insert_id;
		self::flush_cache();
		// A114: series-scoped entry with the full rule (change history of the series).
		\PassCaptain\Audit::record( 'series_price_rule_created', 'series', (int) $series->series_id, array( 'event_id' => (int) $series->event_id, 'series_id' => (int) $series->series_id, 'rule_id' => $rule_id, 'kind' => $rule['kind'], 'currency' => $series->currency, 'rule' => $rule ) );
		return $rule_id;
	}

	/** Fields of a rule row that describe it (for histories and diffs). */
	const RULE_FIELDS = array( 'label', 'type_name', 'kind', 'priority', 'until_at', 'hours_before', 'sold_pct', 'weekdays', 'time_from', 'time_to', 'price_mode', 'amount' );

	/**
	 * A128: changes an active rule (same input as create()). The series history gets the diff
	 * (old → new); the rule id stays, so the price change applies to every occurrence of the series.
	 *
	 * @return int|\WP_Error rule id
	 */
	public static function update( $rule_id, array $in ) {
		global $wpdb;
		$old = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_series_price_rules WHERE rule_id=%d AND status='active'", absint( $rule_id ) ) );
		if ( ! $old ) { return self::error( 'wpcap_series_rule_missing', __( 'Preisregel nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		$series = self::series( $old->series_id );
		if ( ! $series ) { return self::error( 'wpcap_series_missing', __( 'Serie nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		if ( ! \PassCaptain\Access::can_access_event( (int) $series->event_id, Generator::CAP ) ) { return self::error( 'wpcap_admin_forbidden', __( 'Keine Berechtigung für dieses Event.', 'wp-pass-captain' ), 403 ); }
		$rule = self::normalize( $in, $series->timezone, $series->currency );
		if ( is_wp_error( $rule ) ) { return $rule; }
		$changes = \PassCaptain\Admin\ChangeHistory::diff( array_intersect_key( (array) $old, array_flip( self::RULE_FIELDS ) ), $rule );
		if ( ! $changes ) { return (int) $old->rule_id; }
		$wpdb->update( "{$wpdb->prefix}wpcap_series_price_rules", $rule + array( 'updated_at' => current_time( 'mysql', true ) ), array( 'rule_id' => (int) $old->rule_id ) );
		self::flush_cache();
		\PassCaptain\Audit::record( 'series_price_rule_updated', 'series', (int) $series->series_id, array( 'event_id' => (int) $series->event_id, 'series_id' => (int) $series->series_id, 'rule_id' => (int) $old->rule_id, 'kind' => $rule['kind'], 'currency' => $series->currency, 'price_mode' => $rule['price_mode'], 'changes' => $changes ) );
		return (int) $old->rule_id;
	}

	/** One active rule of a series (for the edit form). */
	public static function rule( $rule_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_series_price_rules WHERE rule_id=%d AND status='active'", absint( $rule_id ) ) );
	}

	/** Deactivates a rule (kept for the audit trail). */
	public static function delete( $rule_id ) {
		global $wpdb;
		$rule = $wpdb->get_row( $wpdb->prepare( "SELECT r.*,s.event_id FROM {$wpdb->prefix}wpcap_series_price_rules r INNER JOIN {$wpdb->prefix}wpcap_series s ON s.series_id=r.series_id WHERE r.rule_id=%d", absint( $rule_id ) ) );
		if ( ! $rule ) { return self::error( 'wpcap_series_rule_missing', __( 'Preisregel nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		if ( ! \PassCaptain\Access::can_access_event( (int) $rule->event_id, Generator::CAP ) ) { return self::error( 'wpcap_admin_forbidden', __( 'Keine Berechtigung für dieses Event.', 'wp-pass-captain' ), 403 ); }
		$wpdb->update( "{$wpdb->prefix}wpcap_series_price_rules", array( 'status' => 'deleted', 'updated_at' => current_time( 'mysql', true ) ), array( 'rule_id' => (int) $rule->rule_id ) );
		self::flush_cache();
		$series = self::series( $rule->series_id );
		\PassCaptain\Audit::record( 'series_price_rule_deleted', 'series', (int) $rule->series_id, array( 'event_id' => (int) $rule->event_id, 'series_id' => (int) $rule->series_id, 'rule_id' => (int) $rule->rule_id, 'currency' => $series ? $series->currency : 'EUR', 'rule' => array_intersect_key( (array) $rule, array_flip( self::RULE_FIELDS ) ) ) );
		return true;
	}

	/* ---------------------------------------------------------------------------------------------
	 * Selection (pure) and application.
	 * ------------------------------------------------------------------------------------------- */

	/** Case-insensitive ticket type name match ('' = all types). */
	public static function name_matches( $scope, $name ) {
		$scope = trim( (string) $scope );
		return '' === $scope || mb_strtolower( $scope ) === mb_strtolower( trim( (string) $name ) );
	}

	/**
	 * Whether a rule matches the facts. Pure.
	 *
	 * @param object|array $rule  Rule row.
	 * @param array        $facts now (UTC Y-m-d H:i:s), starts_at (UTC), timezone, type_name,
	 *                            sold_pct (float|null).
	 */
	public static function matches( $rule, array $facts ) {
		$rule = (object) $rule;
		if ( ! self::name_matches( $rule->type_name ?? '', $facts['type_name'] ?? '' ) ) { return false; }
		$now = strtotime( $facts['now'] . ' UTC' );
		$start = strtotime( $facts['starts_at'] . ' UTC' );
		switch ( $rule->kind ) {
			case 'early_bird':
				return ! empty( $rule->until_at ) && $now < strtotime( $rule->until_at . ' UTC' );
			case 'last_minute':
				return (int) $rule->hours_before > 0 && $now >= $start - (int) $rule->hours_before * HOUR_IN_SECONDS;
			case 'demand':
				return null !== ( $facts['sold_pct'] ?? null ) && (float) $facts['sold_pct'] >= (int) $rule->sold_pct;
			case 'weekday':
				try { $zone = new \DateTimeZone( (string) ( $facts['timezone'] ?? '' ) ?: 'UTC' ); } catch ( \Exception $e ) { $zone = new \DateTimeZone( 'UTC' ); }
				$local = ( new \DateTimeImmutable( '@' . $start ) )->setTimezone( $zone );
				$days = '' === (string) $rule->weekdays ? array() : array_map( 'intval', explode( ',', (string) $rule->weekdays ) );
				if ( $days && ! in_array( (int) $local->format( 'N' ), $days, true ) ) { return false; }
				if ( '' !== (string) $rule->time_from ) {
					$time = $local->format( 'H:i' );
					$from = (string) $rule->time_from;
					$to = (string) $rule->time_to;
					return $from < $to ? ( $time >= $from && $time < $to ) : ( $time >= $from || $time < $to );
				}
				return true;
		}
		return false;
	}

	/**
	 * The winning rule for the facts, or null. Pure.
	 *
	 * @param array $rules Rule rows.
	 * @param array $facts See matches(). sold_pct may be a callable (evaluated lazily, once).
	 */
	public static function select( array $rules, array $facts ) {
		$rules = array_values( array_filter( $rules, static function ( $rule ) { return in_array( ( (object) $rule )->kind ?? '', self::KINDS, true ); } ) );
		if ( ! $rules ) { return null; }
		if ( isset( $facts['sold_pct'] ) && is_callable( $facts['sold_pct'] ) ) {
			$needs_demand = (bool) array_filter( $rules, static function ( $rule ) use ( $facts ) { $rule = (object) $rule; return 'demand' === $rule->kind && self::name_matches( $rule->type_name ?? '', $facts['type_name'] ?? '' ); } );
			$facts['sold_pct'] = $needs_demand ? call_user_func( $facts['sold_pct'] ) : null;
		}
		usort( $rules, static function ( $a, $b ) {
			$a = (object) $a;
			$b = (object) $b;
			return array( -(int) $a->priority, -(int) ( 'demand' === $a->kind ? $a->sold_pct : 0 ), (int) ( $a->rule_id ?? 0 ) ) <=> array( -(int) $b->priority, -(int) ( 'demand' === $b->kind ? $b->sold_pct : 0 ), (int) ( $b->rule_id ?? 0 ) );
		} );
		foreach ( $rules as $rule ) {
			if ( self::matches( $rule, $facts ) ) { return (object) $rule; }
		}
		return null;
	}

	/** Price after a rule (fixed price or percentage of the stored price, rounded half up). Pure. */
	public static function apply( $base_minor, $rule ) {
		$rule = (object) $rule;
		if ( 'percent' === $rule->price_mode ) {
			$delta = (int) $rule->amount * (int) $base_minor;
			$adjust = $delta >= 0 ? intdiv( $delta + 5000, 10000 ) : -intdiv( -$delta + 5000, 10000 );
			return max( 0, (int) $base_minor + $adjust );
		}
		return max( 0, (int) $rule->amount );
	}

	/** Series id of an occurrence (0 when not generated by a series). */
	public static function series_of( $occurrence_id ) {
		global $wpdb;
		static $map = array();
		$occurrence_id = absint( $occurrence_id );
		if ( ! isset( $map[ $occurrence_id ] ) ) {
			$map[ $occurrence_id ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT series_id FROM {$wpdb->prefix}wpcap_series_occurrences WHERE occurrence_id=%d", $occurrence_id ) );
		}
		return $map[ $occurrence_id ];
	}

	/** Sold share (0–100) of a type: paid units + active holds against type, else occurrence capacity. */
	public static function sold_pct( $type, $occurrence, $now, $exclude_order_id = 0 ) {
		global $wpdb;
		if ( (int) $type->capacity > 0 ) {
			return 100 * Pools::used( array( (int) $type->ticket_type_id ), $now, $exclude_order_id ) / (int) $type->capacity;
		}
		if ( $occurrence && (int) $occurrence->capacity > 0 ) {
			$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ticket_type_id FROM {$wpdb->prefix}wpcap_ticket_types WHERE occurrence_id=%d", $occurrence->occurrence_id ) ) );
			return 100 * Pools::used( $ids, $now, $exclude_order_id ) / (int) $occurrence->capacity;
		}
		return null;
	}

	/** Winning rule for a ticket type in context (null = stored price). */
	public static function rule_for( $type, array $context ) {
		$occurrence = $context['occurrence'] ?? null;
		if ( ! $occurrence ) { return null; }
		$series_id = self::series_of( $occurrence->occurrence_id );
		if ( ! $series_id ) { return null; }
		$rules = self::for_series( $series_id );
		if ( ! $rules ) { return null; }
		$now = (string) ( $context['now'] ?? current_time( 'mysql', true ) );
		return self::select( $rules, array(
			'now' => $now,
			'starts_at' => $occurrence->starts_at,
			'timezone' => $occurrence->timezone ?? 'UTC',
			'type_name' => $type->name ?? '',
			'sold_pct' => static function () use ( $type, $occurrence, $now ) { return self::sold_pct( $type, $occurrence, $now ); },
		) );
	}

	/** Filter callback for `wpcap_ticket_unit_price`. */
	public static function filter_unit_price( $price, $type, $context = array() ) {
		$rule = self::rule_for( $type, (array) $context );
		return $rule ? self::apply( (int) $price, $rule ) : $price;
	}

	/** Filter callback for `wpcap_ticket_price_label` (public label of the active tier). */
	public static function filter_price_label( $label, $type, $context = array() ) {
		$rule = self::rule_for( $type, (array) $context );
		if ( ! $rule ) { return $label; }
		if ( '' !== (string) $rule->label ) { return $rule->label; }
		return self::kind_labels()[ $rule->kind ] ?? $label;
	}

	public static function kind_labels() {
		return array(
			'early_bird' => __( 'Frühbucherpreis', 'wp-pass-captain' ),
			'last_minute' => __( 'Last-Minute-Preis', 'wp-pass-captain' ),
			'demand' => __( 'Nachfragepreis', 'wp-pass-captain' ),
			'weekday' => __( 'Wochentags-/Zeitfensterpreis', 'wp-pass-captain' ),
		);
	}
}

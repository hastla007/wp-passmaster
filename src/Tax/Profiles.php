<?php
/**
 * Country tax profiles (A115, schema 3.9.7): VAT rate per country and tax class, versioned and
 * reviewed before use.
 *
 * Place of supply: admission to events is taxed where the event takes place (EU VAT directive
 * Art. 53/54; DE § 3a Abs. 3 Nr. 5 UStG). The venue country of an occurrence
 * (wpcap_occurrences.venue_country, empty = seller country from the settings) selects the profile.
 *
 * Rate resolution per order line (Orders::create(), ticket exchanges, partner/box office catalogues):
 * a ticket type with a tax class uses the ACTIVE profile for (venue country, tax class) whose
 * effective_from/effective_until (inclusive, site-local dates) contains the sale date; otherwise the
 * ticket type's own tax_rate_bp (behaviour before 3.9.7). The line stores the source, profile,
 * version and country used, so invoices, credit notes and reports stay reproducible.
 *
 * Review workflow ("Steuerprüfung"): profiles are created/edited as drafts; only a user with
 * finance rights (wpcap_view_finance or manage_options) activates them, never the person who created
 * or last edited the draft while the setting tax_profile_four_eyes is on (administrators exempt, like
 * guestlist_four_eyes). Overlapping active profiles for the same country/class are rejected.
 * Active profiles are retired (not edited); every action is audited.
 *
 * WooCommerce orders take WooCommerce's own taxes: profiles apply to the native checkout, box office,
 * partner API, waitlist offers and voucher redemptions (filter wpcap_tax_profiles_apply).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Tax;

use PassCaptain\Audit;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Profiles {
	const REVIEW_CAP = 'wpcap_view_finance';
	const SOURCE_TYPE = 'ticket_type';
	const SOURCE_PROFILE = 'profile';
	const SOURCE_EXTERNAL = 'external';

	/** Per-request caches. */
	private static $ready = null;
	private static $occurrence_country = array();
	private static $active = array();
	/** Table prefix the caches belong to (multisite: switch_to_blog() changes it within a request). */
	private static $prefix = null;

	/** Drops the caches when the site (table prefix) changed since they were filled. */
	private static function same_site() {
		global $wpdb;
		$prefix = isset( $wpdb->prefix ) ? (string) $wpdb->prefix : '';
		if ( self::$prefix !== $prefix ) {
			self::flush();
			self::$prefix = $prefix;
		}
	}

	/** Whether the 3.9.7 columns exist (venue country, line tax source, review fields). */
	public static function ready() {
		self::same_site();
		if ( null === self::$ready ) {
			$version = strtok( (string) get_option( 'wpcap_db_version', '' ), '+' );
			self::$ready = false !== $version && '' !== $version && version_compare( $version, '3.9.7', '>=' );
		}
		return self::$ready;
	}

	/** Resets the caches (after a profile change, an upgrade in the same request, tests). */
	public static function flush() {
		self::$ready = null;
		self::$occurrence_country = array();
		self::$active = array();
	}

	/** Seller country from the settings (ISO code, fallback DE). */
	public static function seller_country() {
		$country = strtoupper( (string) Settings::get( 'seller_country' ) );
		return preg_match( '/^[A-Z]{2}$/', $country ) ? $country : 'DE';
	}

	/** Normalises an ISO country code ('' when invalid). */
	public static function country( $code ) {
		$code = strtoupper( trim( (string) $code ) );
		return preg_match( '/^[A-Z]{2}$/', $code ) ? $code : '';
	}

	/**
	 * Venue country of an occurrence (place of supply): its own field, else the seller country.
	 *
	 * @param object|int $occurrence Occurrence row (with venue_country) or ID.
	 */
	public static function venue_country( $occurrence ) {
		if ( is_object( $occurrence ) && property_exists( $occurrence, 'venue_country' ) ) {
			$own = self::country( $occurrence->venue_country );
			return '' !== $own ? $own : self::seller_country();
		}
		$id = is_object( $occurrence ) ? (int) ( $occurrence->occurrence_id ?? 0 ) : absint( $occurrence );
		if ( ! $id || ! self::ready() ) { return self::seller_country(); } // ready() also checks the site.
		if ( ! array_key_exists( $id, self::$occurrence_country ) ) {
			global $wpdb;
			self::$occurrence_country[ $id ] = self::country( (string) $wpdb->get_var( $wpdb->prepare( "SELECT venue_country FROM {$wpdb->prefix}wpcap_occurrences WHERE occurrence_id=%d", $id ) ) );
		}
		return '' !== self::$occurrence_country[ $id ] ? self::$occurrence_country[ $id ] : self::seller_country();
	}

	/** Site-local date (Y-m-d) of a UTC time ('' = now): the sale date compared with the validity. */
	public static function local_date( $utc = '' ) {
		$ts = '' !== (string) $utc ? strtotime( $utc . ' UTC' ) : time();
		$tz = \PassCaptain\Reports\Scope::site_timezone();
		return ( new \DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( new \DateTimeZone( $tz ) )->format( 'Y-m-d' );
	}

	/** Active profile for country/class/date, or null. */
	public static function active_profile( $country, $tax_class, $date ) {
		$country = self::country( $country );
		$tax_class = sanitize_key( (string) $tax_class );
		if ( '' === $country || '' === $tax_class ) { return null; }
		$key = $country . '|' . $tax_class . '|' . $date;
		self::same_site();
		if ( ! array_key_exists( $key, self::$active ) ) {
			global $wpdb;
			self::$active[ $key ] = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_tax_profiles WHERE country_code=%s AND tax_class=%s AND status='active' AND effective_from<=%s AND (effective_until IS NULL OR effective_until>=%s) ORDER BY effective_from DESC,tax_profile_id DESC LIMIT 1", $country, $tax_class, $date, $date ) );
		}
		return self::$active[ $key ];
	}

	/**
	 * Tax rate of a ticket type for a sale.
	 *
	 * @param object      $type       Ticket type row (tax_rate_bp, tax_class, occurrence_id).
	 * @param object|null $occurrence Occurrence row (venue_country), or null to look it up.
	 * @param string      $now_utc    Sale time (UTC "Y-m-d H:i:s", '' = now).
	 * @param string      $source     Sales channel (Orders::create() source) for wpcap_tax_profiles_apply.
	 * @return array rate_bp, source (ticket_type|profile), profile_id, version, country.
	 */
	public static function resolve( $type, $occurrence = null, $now_utc = '', $source = '' ) {
		$own = (int) ( $type->tax_rate_bp ?? 0 );
		$country = self::venue_country( $occurrence ? $occurrence : (int) ( $type->occurrence_id ?? 0 ) );
		$result = array( 'rate_bp' => $own, 'source' => self::SOURCE_TYPE, 'profile_id' => 0, 'version' => '', 'country' => $country );
		$class = sanitize_key( (string) ( $type->tax_class ?? '' ) );
		if ( '' === $class || ! self::ready() ) { return $result; }
		/**
		 * Whether country tax profiles apply to a sale of this channel. Default: everything except
		 * WooCommerce (whose own tax settings decide there).
		 *
		 * @param bool   $apply  Default decision.
		 * @param string $source Sales channel (native, pos, partner_api, woocommerce, …).
		 * @param object $type   Ticket type row.
		 */
		if ( ! apply_filters( 'wpcap_tax_profiles_apply', 'woocommerce' !== $source, (string) $source, $type ) ) { return $result; }
		$profile = self::active_profile( $country, $class, self::local_date( $now_utc ) );
		if ( ! $profile ) { return $result; }
		return array( 'rate_bp' => (int) $profile->rate_basis_points, 'source' => self::SOURCE_PROFILE, 'profile_id' => (int) $profile->tax_profile_id, 'version' => (string) $profile->version, 'country' => $country );
	}

	/** Order line columns (schema 3.9.7) for a resolve() result; empty before the upgrade. */
	public static function line_columns( array $resolved ) {
		if ( ! self::ready() ) { return array(); }
		return array(
			'tax_source' => (string) $resolved['source'],
			'tax_profile_id' => (int) $resolved['profile_id'],
			'tax_profile_version' => substr( (string) $resolved['version'], 0, 32 ),
			'tax_country' => (string) $resolved['country'],
		);
	}

	/** Label of the rate source stored on an order line (order admin view). */
	public static function source_label( $item ) {
		$source = (string) ( $item->tax_source ?? '' );
		$country = (string) ( $item->tax_country ?? '' );
		if ( self::SOURCE_PROFILE === $source ) {
			/* translators: 1: country code, 2: profile version, 3: profile ID */
			return sprintf( __( 'Steuerprofil %1$s, Version %2$s (#%3$d)', 'pass-captain' ), $country, (string) $item->tax_profile_version, (int) $item->tax_profile_id );
		}
		if ( self::SOURCE_EXTERNAL === $source ) { return __( 'vom Verkaufskanal vorgegeben', 'pass-captain' ); }
		if ( self::SOURCE_TYPE === $source ) {
			if ( '' === $country ) { return __( 'Satz der Ticketart', 'pass-captain' ); }
			/* translators: %s: country code */
			return sprintf( __( 'Satz der Ticketart (Leistungsort %s)', 'pass-captain' ), $country );
		}
		return __( 'Satz der Ticketart (vor Einführung der Steuerprofile erfasst)', 'pass-captain' );
	}

	/* ------------------------------------------------------------------ review workflow */

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_tax_profiles WHERE tax_profile_id=%d", absint( $id ) ) );
	}

	/** All profiles, newest first per country/class. */
	public static function all() {
		global $wpdb;
		return (array) $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wpcap_tax_profiles ORDER BY country_code,tax_class,effective_from DESC,tax_profile_id DESC" );
	}

	/** Whether the current user may create/edit drafts and review profiles (finance rights). */
	public static function can_manage() {
		return current_user_can( self::REVIEW_CAP ) || current_user_can( 'manage_options' );
	}

	/**
	 * Whether the current user created or last edited this draft and may therefore not activate it
	 * (setting tax_profile_four_eyes; administrators exempt, like guestlist_four_eyes).
	 */
	public static function self_review_blocked( $profile ) {
		if ( ! (int) Settings::get( 'tax_profile_four_eyes' ) || current_user_can( 'manage_options' ) ) { return false; }
		$me = (int) get_current_user_id();
		return $me && ( (int) ( $profile->created_by ?? 0 ) === $me || (int) ( $profile->updated_by ?? 0 ) === $me );
	}

	private static function error( $code, $message, $status = 400 ) {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	private static function valid_date( $date ) {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', (string) $date, $m ) ) { return false; }
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * Validates profile input.
	 *
	 * @param array $in country_code, tax_class, rate_basis_points, version, effective_from, effective_until ('' = open).
	 * @return array|\WP_Error Clean values.
	 */
	public static function validate( array $in ) {
		$country = self::country( $in['country_code'] ?? '' );
		$class = sanitize_key( (string) ( $in['tax_class'] ?? 'standard' ) );
		$rate = $in['rate_basis_points'] ?? null;
		$from = trim( (string) ( $in['effective_from'] ?? '' ) );
		$until = trim( (string) ( $in['effective_until'] ?? '' ) );
		$version = trim( (string) ( $in['version'] ?? '' ) );
		if ( '' === $country ) { return self::error( 'wpcap_tax_profile_invalid', __( 'Bitte gib einen gültigen Ländercode an (z. B. DE oder AT).', 'pass-captain' ) ); }
		if ( '' === $class || strlen( $class ) > 64 ) { return self::error( 'wpcap_tax_profile_invalid', __( 'Bitte gib eine Steuerklasse an (Kleinbuchstaben, Ziffern, - und _).', 'pass-captain' ) ); }
		if ( ! is_scalar( $rate ) || ! preg_match( '/^\d{1,5}$/', (string) $rate ) || (int) $rate > 10000 ) { return self::error( 'wpcap_tax_profile_invalid', __( 'Der Steuersatz muss zwischen 0 und 100 % liegen.', 'pass-captain' ) ); }
		if ( ! preg_match( '/^[A-Za-z0-9._-]{1,32}$/', $version ) ) { return self::error( 'wpcap_tax_profile_invalid', __( 'Die Version darf 1–32 Zeichen aus Buchstaben, Ziffern, Punkt, - und _ enthalten.', 'pass-captain' ) ); }
		if ( ! self::valid_date( $from ) || ( '' !== $until && ( ! self::valid_date( $until ) || $until < $from ) ) ) { return self::error( 'wpcap_tax_profile_invalid', __( 'Der Gültigkeitszeitraum ist ungültig (gültig ab ist Pflicht, gültig bis darf nicht davor liegen).', 'pass-captain' ) ); }
		return array( 'country_code' => $country, 'tax_class' => $class, 'rate_basis_points' => (int) $rate, 'version' => $version, 'effective_from' => $from, 'effective_until' => '' !== $until ? $until : null );
	}

	/**
	 * Creates a draft, or updates one (tax_profile_id given; only drafts can be edited).
	 *
	 * @return int|\WP_Error Profile ID.
	 */
	public static function save_draft( array $in ) {
		global $wpdb;
		if ( ! self::can_manage() ) { return self::error( 'wpcap_forbidden', __( 'Für Steuerprofile brauchst du Finanzrechte.', 'pass-captain' ), 403 ); }
		$clean = self::validate( $in );
		if ( is_wp_error( $clean ) ) { return $clean; }
		$table = $wpdb->prefix . 'wpcap_tax_profiles';
		$id = absint( $in['tax_profile_id'] ?? 0 );
		$now = current_time( 'mysql', true );
		$me = (int) get_current_user_id();
		if ( $id ) {
			$old = self::get( $id );
			if ( ! $old ) { return self::error( 'wpcap_tax_profile_missing', __( 'Steuerprofil nicht gefunden.', 'pass-captain' ), 404 ); }
			if ( 'draft' !== $old->status ) { return self::error( 'wpcap_tax_profile_locked', __( 'Nur Entwürfe können bearbeitet werden. Lege für eine Änderung eine neue Version an und setze die alte außer Kraft.', 'pass-captain' ), 409 ); }
			$data = $clean + ( self::ready() ? array( 'updated_by' => $me, 'updated_at' => $now ) : array() );
			if ( false === $wpdb->update( $table, $data, array( 'tax_profile_id' => $id, 'status' => 'draft' ) ) ) { return self::error( 'wpcap_tax_profile_save', __( 'Steuerprofil konnte nicht gespeichert werden.', 'pass-captain' ), 500 ); }
			Audit::record( 'tax_profile_updated', 'tax_profile', $id, array( 'before' => self::snapshot( $old ), 'after' => $clean ) );
		} else {
			$data = $clean + array( 'status' => 'draft', 'created_at' => $now ) + ( self::ready() ? array( 'created_by' => $me, 'updated_by' => $me, 'updated_at' => $now ) : array() );
			if ( ! $wpdb->insert( $table, $data ) ) { return self::error( 'wpcap_tax_profile_save', __( 'Steuerprofil konnte nicht gespeichert werden.', 'pass-captain' ), 500 ); }
			$id = (int) $wpdb->insert_id;
			Audit::record( 'tax_profile_created', 'tax_profile', $id, $clean );
		}
		return $id;
	}

	private static function snapshot( $profile ) {
		return array( 'country_code' => (string) $profile->country_code, 'tax_class' => (string) $profile->tax_class, 'rate_basis_points' => (int) $profile->rate_basis_points, 'version' => (string) $profile->version, 'effective_from' => (string) $profile->effective_from, 'effective_until' => $profile->effective_until ? (string) $profile->effective_until : null, 'status' => (string) $profile->status );
	}

	/** Active profiles of the same country/class whose validity overlaps (excluding $exclude_id). */
	public static function overlapping( $profile, $exclude_id = 0, $lock = false ) {
		global $wpdb;
		$until = $profile->effective_until ? (string) $profile->effective_until : '9999-12-31';
		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}wpcap_tax_profiles WHERE country_code=%s AND tax_class=%s AND status='active' AND tax_profile_id<>%d AND effective_from<=%s AND (effective_until IS NULL OR effective_until>=%s) ORDER BY effective_from" . ( $lock ? ' FOR UPDATE' : '' ),
			(string) $profile->country_code,
			(string) $profile->tax_class,
			absint( $exclude_id ),
			$until,
			(string) $profile->effective_from
		) );
	}

	/**
	 * Review step: activates a draft (finance rights, four-eyes, no overlap).
	 *
	 * @param string $note Optional review note (audited).
	 * @return true|\WP_Error
	 */
	public static function activate( $id, $note = '' ) {
		global $wpdb;
		if ( ! self::can_manage() ) { return self::error( 'wpcap_forbidden', __( 'Steuerprofile darf nur die Finanzbuchhaltung freigeben.', 'pass-captain' ), 403 ); }
		$profile = self::get( $id );
		if ( ! $profile ) { return self::error( 'wpcap_tax_profile_missing', __( 'Steuerprofil nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( 'draft' !== $profile->status ) { return self::error( 'wpcap_tax_profile_state', __( 'Nur Entwürfe können geprüft und aktiviert werden.', 'pass-captain' ), 409 ); }
		if ( self::self_review_blocked( $profile ) ) {
			Audit::record( 'tax_profile_review_denied', 'tax_profile', (int) $id, array( 'reason' => 'four_eyes' ) );
			return self::error( 'wpcap_tax_profile_four_eyes', __( 'Vier-Augen-Prinzip: Ein Steuerprofil kann nicht von der Person aktiviert werden, die es angelegt oder zuletzt bearbeitet hat.', 'pass-captain' ), 403 );
		}
		$table = $wpdb->prefix . 'wpcap_tax_profiles';
		$wpdb->query( 'START TRANSACTION' );
		// Serialises activations per country/class: every row of the pair is locked before the overlap check.
		$wpdb->query( $wpdb->prepare( "SELECT tax_profile_id FROM $table WHERE country_code=%s AND tax_class=%s FOR UPDATE", (string) $profile->country_code, (string) $profile->tax_class ) );
		$profile = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE tax_profile_id=%d FOR UPDATE", absint( $id ) ) );
		if ( ! $profile || 'draft' !== $profile->status ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error( 'wpcap_tax_profile_state', __( 'Nur Entwürfe können geprüft und aktiviert werden.', 'pass-captain' ), 409 );
		}
		$overlap = self::overlapping( $profile, (int) $id, true );
		if ( $overlap ) {
			$wpdb->query( 'ROLLBACK' );
			$ids = array_map( static function ( $row ) { return '#' . (int) $row->tax_profile_id . ' (' . $row->version . ')'; }, $overlap );
			Audit::record( 'tax_profile_review_denied', 'tax_profile', (int) $id, array( 'reason' => 'overlap', 'overlapping' => array_map( static function ( $row ) { return (int) $row->tax_profile_id; }, $overlap ) ) );
			/* translators: %s: list of profiles */
			return self::error( 'wpcap_tax_profile_overlap', sprintf( __( 'Für dieses Land und diese Steuerklasse ist im Zeitraum schon ein Profil aktiv: %s. Setze es zuerst außer Kraft oder begrenze die Gültigkeit.', 'pass-captain' ), implode( ', ', $ids ) ), 409 );
		}
		$data = array( 'status' => 'active' ) + ( self::ready() ? array( 'reviewed_by' => (int) get_current_user_id(), 'reviewed_at' => current_time( 'mysql', true ), 'review_note' => mb_substr( sanitize_text_field( (string) $note ), 0, 190 ) ) : array() );
		if ( false === $wpdb->update( $table, $data, array( 'tax_profile_id' => absint( $id ) ) ) || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error( 'wpcap_tax_profile_save', __( 'Steuerprofil konnte nicht gespeichert werden.', 'pass-captain' ), 500 );
		}
		self::flush();
		Audit::record( 'tax_profile_activated', 'tax_profile', (int) $id, self::snapshot( $profile ) + array( 'note' => $data['review_note'] ?? '' ) );
		return true;
	}

	/**
	 * Retires a profile (active: no longer used for new orders; draft: discarded). Orders keep the
	 * rate they were sold with.
	 *
	 * @return true|\WP_Error
	 */
	public static function retire( $id, $note = '' ) {
		global $wpdb;
		if ( ! self::can_manage() ) { return self::error( 'wpcap_forbidden', __( 'Steuerprofile darf nur die Finanzbuchhaltung außer Kraft setzen.', 'pass-captain' ), 403 ); }
		$profile = self::get( $id );
		if ( ! $profile ) { return self::error( 'wpcap_tax_profile_missing', __( 'Steuerprofil nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( 'retired' === $profile->status ) { return self::error( 'wpcap_tax_profile_state', __( 'Das Steuerprofil ist bereits außer Kraft.', 'pass-captain' ), 409 ); }
		$data = array( 'status' => 'retired' ) + ( self::ready() ? array( 'retired_by' => (int) get_current_user_id(), 'retired_at' => current_time( 'mysql', true ) ) : array() );
		if ( false === $wpdb->update( $wpdb->prefix . 'wpcap_tax_profiles', $data, array( 'tax_profile_id' => absint( $id ), 'status' => (string) $profile->status ) ) ) {
			return self::error( 'wpcap_tax_profile_save', __( 'Steuerprofil konnte nicht gespeichert werden.', 'pass-captain' ), 500 );
		}
		self::flush();
		Audit::record( 'tax_profile_retired', 'tax_profile', (int) $id, self::snapshot( $profile ) + array( 'previous_status' => (string) $profile->status, 'note' => mb_substr( sanitize_text_field( (string) $note ), 0, 190 ) ) );
		return true;
	}

	/**
	 * Review overview: tax classes of active ticket types with upcoming occurrences, per venue country,
	 * and whether an active profile covers today (otherwise the ticket type's own rate is used).
	 *
	 * @return array[] country, tax_class, ticket_types, profile (row|null).
	 */
	public static function coverage() {
		global $wpdb;
		$p = $wpdb->prefix;
		$country_col = self::ready() ? 'o.venue_country' : "''";
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT tt.tax_class,$country_col AS venue_country,COUNT(*) AS types FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.tax_class<>'' AND tt.status='active' AND o.status='scheduled' AND o.starts_at>=%s GROUP BY tt.tax_class,$country_col", gmdate( 'Y-m-d H:i:s' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out = array();
		$today = self::local_date();
		foreach ( $rows as $row ) {
			$country = '' !== self::country( $row->venue_country ) ? self::country( $row->venue_country ) : self::seller_country();
			$key = $country . '|' . $row->tax_class;
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = array( 'country' => $country, 'tax_class' => (string) $row->tax_class, 'ticket_types' => 0, 'profile' => self::active_profile( $country, $row->tax_class, $today ) );
			}
			$out[ $key ]['ticket_types'] += (int) $row->types;
		}
		ksort( $out );
		return array_values( $out );
	}
}

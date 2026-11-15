<?php
/**
 * Versioned country/tax profiles (wpcap_tax_profiles) and their use at checkout.
 *
 * A profile is identified by country + tax class and carries effective-dated versions
 * (effective_from .. effective_until, inclusive dates). Only "active" versions are applied; drafts
 * are prepared and reviewed first. The plugin ships NO legal or tax rules: every profile is a
 * technical configuration that has to be checked by tax advisors ("fachlich zu prüfen").
 *
 * Selection per order line (Orders::create):
 *   country = event's tax_country (place of supply for admission to events: where the event takes
 *             place), else the seller country setting;
 *   class   = ticket type tax_class, else "standard";
 *   date    = order date (UTC, filter `wpcap_tax_profile_date`).
 * The applied profile id, version and rate are frozen on the order line (tax_profile_id,
 * tax_rate_bp, line_snapshot.tax). Invoices, credit notes and refunds only use these snapshots, so
 * a later rate change never affects existing orders. Without an active matching version the ticket
 * type's own tax_rate_bp is used (previous behaviour).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Sales;

use PassCaptain\Audit;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TaxProfiles {
	const STATUSES = array( 'draft', 'active', 'archived' );

	/** Per-request cache: event_id => tax_country. */
	private static $event_countries = array();

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wpcap_tax_profiles';
	}

	public static function flush_cache() {
		self::$event_countries = array();
	}

	private static function error( $code, $message, $status = 400 ) {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	private static function valid_date( $date ) {
		return is_string( $date ) && preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	public static function get( $profile_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE tax_profile_id=%d', self::table(), absint( $profile_id ) ) );
	}

	/** All profiles (admin list), newest version first per country/class. */
	public static function all() {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY country_code,tax_class,effective_from DESC,tax_profile_id DESC LIMIT 1000', self::table() ) );
	}

	/** Distinct tax classes of all profiles plus "standard" (ticket type select). */
	public static function classes() {
		global $wpdb;
		$classes = array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT tax_class FROM %i WHERE status<>'archived' ORDER BY tax_class", self::table() ) ) );
		return array_values( array_unique( array_merge( array( 'standard' ), array_filter( $classes ) ) ) );
	}

	/**
	 * Validates input into a profile row.
	 *
	 * Input: country_code, tax_class, rate (percent "19" / "7,7") or rate_basis_points, version,
	 * effective_from, effective_until (Y-m-d, optional), label, place_of_supply_note.
	 *
	 * @return array|\WP_Error
	 */
	public static function normalize( array $in ) {
		$country = strtoupper( sanitize_text_field( (string) ( $in['country_code'] ?? '' ) ) );
		$class = sanitize_key( (string) ( $in['tax_class'] ?? '' ) ) ?: 'standard';
		if ( isset( $in['rate_basis_points'] ) && '' !== (string) $in['rate_basis_points'] ) {
			$rate = is_numeric( $in['rate_basis_points'] ) ? (int) $in['rate_basis_points'] : -1;
		} else {
			$raw_rate = is_scalar( $in['rate'] ?? null ) ? trim( (string) $in['rate'] ) : '';
			$parsed = '' === $raw_rate ? null : \PassCaptain\Admin\Forms::parse_percent_bp( $raw_rate );
			$rate = null === $parsed ? -1 : (int) $parsed;
		}
		$version = sanitize_text_field( (string) ( $in['version'] ?? '' ) );
		$from = sanitize_text_field( (string) ( $in['effective_from'] ?? '' ) );
		$until = sanitize_text_field( (string) ( $in['effective_until'] ?? '' ) );
		if ( ! preg_match( '/^[A-Z]{2}$/', $country ) ) { return self::error( 'wpcap_tax_profile_country', __( 'Bitte einen zweistelligen Ländercode angeben (z. B. AT, DE, CH).', 'pass-captain' ) ); }
		if ( $rate < 0 || $rate > 10000 ) { return self::error( 'wpcap_tax_profile_rate', __( 'Der Steuersatz muss zwischen 0 und 100 % liegen.', 'pass-captain' ) ); }
		if ( ! preg_match( '/^[A-Za-z0-9._-]{1,32}$/', $version ) ) { return self::error( 'wpcap_tax_profile_version', __( 'Bitte eine Versionsbezeichnung angeben (Buchstaben, Ziffern, Punkt, Bindestrich; max. 32 Zeichen).', 'pass-captain' ) ); }
		if ( ! self::valid_date( $from ) ) { return self::error( 'wpcap_tax_profile_from', __( 'Bitte ein gültiges Datum „gültig ab“ angeben.', 'pass-captain' ) ); }
		if ( '' !== $until && ( ! self::valid_date( $until ) || $until < $from ) ) { return self::error( 'wpcap_tax_profile_until', __( 'Das Datum „gültig bis“ ist ungültig oder liegt vor „gültig ab“.', 'pass-captain' ) ); }
		return array(
			'country_code' => $country,
			'tax_class' => mb_substr( $class, 0, 64 ),
			'rate_basis_points' => $rate,
			'version' => $version,
			'effective_from' => $from,
			'effective_until' => '' === $until ? null : $until,
			'label' => mb_substr( sanitize_text_field( (string) ( $in['label'] ?? '' ) ), 0, 190 ),
			'place_of_supply_note' => mb_substr( sanitize_textarea_field( (string) ( $in['place_of_supply_note'] ?? '' ) ), 0, 1000 ),
		);
	}

	/** Creates a draft version. @return int|\WP_Error profile id */
	public static function create( array $in ) {
		global $wpdb;
		$row = self::normalize( $in );
		if ( is_wp_error( $row ) ) { return $row; }
		$duplicate = $wpdb->get_var( $wpdb->prepare( 'SELECT tax_profile_id FROM %i WHERE country_code=%s AND tax_class=%s AND version=%s', self::table(), $row['country_code'], $row['tax_class'], $row['version'] ) );
		if ( $duplicate ) { return self::error( 'wpcap_tax_profile_duplicate', __( 'Diese Version existiert für Land und Steuerklasse bereits.', 'pass-captain' ), 409 ); }
		$ok = $wpdb->insert( self::table(), $row + array( 'status' => 'draft', 'created_at' => current_time( 'mysql', true ) ) );
		if ( ! $ok ) { return self::error( 'wpcap_tax_profile_save', __( 'Steuerprofil konnte nicht gespeichert werden.', 'pass-captain' ), 500 ); }
		$id = (int) $wpdb->insert_id;
		Audit::record( 'tax_profile_created', 'tax_profile', $id, array( 'country' => $row['country_code'], 'class' => $row['tax_class'], 'version' => $row['version'], 'rate_bp' => $row['rate_basis_points'] ) );
		return $id;
	}

	/** Updates a draft (active/archived versions are frozen: create a new version instead). */
	public static function update( $profile_id, array $in ) {
		global $wpdb;
		$profile = self::get( $profile_id );
		if ( ! $profile ) { return self::error( 'wpcap_tax_profile_missing', __( 'Steuerprofil nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( 'draft' !== $profile->status ) { return self::error( 'wpcap_tax_profile_frozen', __( 'Aktive oder archivierte Versionen können nicht geändert werden. Bitte eine neue Version anlegen.', 'pass-captain' ), 409 ); }
		$row = self::normalize( $in );
		if ( is_wp_error( $row ) ) { return $row; }
		$duplicate = $wpdb->get_var( $wpdb->prepare( 'SELECT tax_profile_id FROM %i WHERE country_code=%s AND tax_class=%s AND version=%s AND tax_profile_id<>%d', self::table(), $row['country_code'], $row['tax_class'], $row['version'], (int) $profile->tax_profile_id ) );
		if ( $duplicate ) { return self::error( 'wpcap_tax_profile_duplicate', __( 'Diese Version existiert für Land und Steuerklasse bereits.', 'pass-captain' ), 409 ); }
		$wpdb->update( self::table(), $row, array( 'tax_profile_id' => (int) $profile->tax_profile_id ) );
		Audit::record( 'tax_profile_updated', 'tax_profile', (int) $profile->tax_profile_id, array( 'version' => $row['version'], 'rate_bp' => $row['rate_basis_points'] ) );
		return (int) $profile->tax_profile_id;
	}

	/**
	 * Activates a draft. Active versions of the same country/class must not overlap in time.
	 *
	 * @return true|\WP_Error
	 */
	public static function activate( $profile_id ) {
		global $wpdb;
		$profile = self::get( $profile_id );
		if ( ! $profile ) { return self::error( 'wpcap_tax_profile_missing', __( 'Steuerprofil nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( 'draft' !== $profile->status ) { return self::error( 'wpcap_tax_profile_state', __( 'Nur Entwürfe können aktiviert werden.', 'pass-captain' ), 409 ); }
		$until = $profile->effective_until ? $profile->effective_until : '9999-12-31';
		$overlap = $wpdb->get_row( $wpdb->prepare( "SELECT tax_profile_id,version FROM %i WHERE country_code=%s AND tax_class=%s AND status='active' AND effective_from<=%s AND COALESCE(effective_until,'9999-12-31')>=%s LIMIT 1", self::table(), $profile->country_code, $profile->tax_class, $until, $profile->effective_from ) );
		if ( $overlap ) {
			/* translators: %s: version of the overlapping profile */
			return self::error( 'wpcap_tax_profile_overlap', sprintf( __( 'Der Gültigkeitszeitraum überschneidet sich mit der aktiven Version %s. Bitte deren „gültig bis“ zuerst über eine neue Version begrenzen oder sie archivieren.', 'pass-captain' ), $overlap->version ), 409 );
		}
		$wpdb->update( self::table(), array( 'status' => 'active', 'activated_at' => current_time( 'mysql', true ) ), array( 'tax_profile_id' => (int) $profile->tax_profile_id ) );
		Audit::record( 'tax_profile_activated', 'tax_profile', (int) $profile->tax_profile_id, array( 'country' => $profile->country_code, 'class' => $profile->tax_class, 'version' => $profile->version, 'rate_bp' => (int) $profile->rate_basis_points ) );
		return true;
	}

	/** Archives a version (no longer applied to new orders; existing snapshots are untouched). */
	public static function archive( $profile_id ) {
		global $wpdb;
		$profile = self::get( $profile_id );
		if ( ! $profile ) { return self::error( 'wpcap_tax_profile_missing', __( 'Steuerprofil nicht gefunden.', 'pass-captain' ), 404 ); }
		$wpdb->update( self::table(), array( 'status' => 'archived' ), array( 'tax_profile_id' => (int) $profile->tax_profile_id ) );
		Audit::record( 'tax_profile_archived', 'tax_profile', (int) $profile->tax_profile_id, array( 'version' => $profile->version ) );
		return true;
	}

	/** Active version for country/class on a date (Y-m-d), or null. */
	public static function resolve( $country, $class, $date ) {
		global $wpdb;
		$country = strtoupper( (string) $country );
		$class = '' !== (string) $class ? (string) $class : 'standard';
		if ( ! preg_match( '/^[A-Z]{2}$/', $country ) || ! self::valid_date( (string) $date ) ) { return null; }
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE country_code=%s AND tax_class=%s AND status='active' AND effective_from<=%s AND (effective_until IS NULL OR effective_until>=%s) ORDER BY effective_from DESC,tax_profile_id DESC LIMIT 1", self::table(), $country, $class, $date, $date ) );
		return $row ?: null;
	}

	/** Seller country setting (two letters, default DE). */
	public static function seller_country() {
		$country = strtoupper( (string) Settings::get( 'seller_country' ) );
		return preg_match( '/^[A-Z]{2}$/', $country ) ? $country : 'DE';
	}

	/** Tax country of an event ('' = not set). */
	public static function event_country( $event_id ) {
		global $wpdb;
		$event_id = absint( $event_id );
		if ( ! array_key_exists( $event_id, self::$event_countries ) ) {
			$value = $wpdb->get_var( $wpdb->prepare( 'SELECT tax_country FROM %i WHERE event_id=%d', $wpdb->prefix . 'wpcap_events', $event_id ) );
			self::$event_countries[ $event_id ] = preg_match( '/^[A-Z]{2}$/', (string) $value ) ? (string) $value : '';
		}
		return self::$event_countries[ $event_id ];
	}

	/** Saves the tax country (place of supply) of an event; '' = seller country. */
	public static function set_event_country( $event_id, $country ) {
		global $wpdb;
		$country = strtoupper( trim( (string) $country ) );
		if ( '' !== $country && ! preg_match( '/^[A-Z]{2}$/', $country ) ) { return self::error( 'wpcap_tax_country', __( 'Bitte einen zweistelligen Ländercode angeben.', 'pass-captain' ) ); }
		$wpdb->update( "{$wpdb->prefix}wpcap_events", array( 'tax_country' => $country ), array( 'event_id' => absint( $event_id ) ) );
		self::$event_countries[ absint( $event_id ) ] = $country;
		Audit::record( 'event_tax_country', 'event', absint( $event_id ), array( 'country' => $country ) );
		return true;
	}

	/**
	 * Profile to apply to an order line, or null (ticket type rate is used).
	 *
	 * @param object $type       Ticket type row (tax_class).
	 * @param object $occurrence Occurrence row (event_id).
	 * @param string $now        UTC Y-m-d H:i:s of the order.
	 * @return array|null {tax_profile_id, rate_bp, snapshot}
	 */
	public static function for_line( $type, $occurrence, $now ) {
		if ( ! $occurrence ) { return null; }
		$event_country = self::event_country( (int) $occurrence->event_id );
		$country = '' !== $event_country ? $event_country : self::seller_country();
		$class = '' !== (string) ( $type->tax_class ?? '' ) ? (string) $type->tax_class : 'standard';
		$date = (string) apply_filters( 'wpcap_tax_profile_date', substr( (string) $now, 0, 10 ), $type, $occurrence );
		$profile = self::resolve( $country, $class, $date );
		if ( ! $profile ) { return null; }
		return array(
			'tax_profile_id' => (int) $profile->tax_profile_id,
			'rate_bp' => (int) $profile->rate_basis_points,
			'snapshot' => array(
				'profile_id' => (int) $profile->tax_profile_id,
				'country' => (string) $profile->country_code,
				'tax_class' => (string) $profile->tax_class,
				'version' => (string) $profile->version,
				'label' => (string) $profile->label,
				'rate_bp' => (int) $profile->rate_basis_points,
				'effective_from' => (string) $profile->effective_from,
				'effective_until' => (string) $profile->effective_until,
				'place_of_supply' => '' !== $event_country ? 'event_country' : 'seller_country',
				'note' => (string) $profile->place_of_supply_note,
				'date' => $date,
			),
		);
	}

	/**
	 * Notes for an invoice from the line snapshots (place of supply). Only texts the operator entered
	 * in the profile, plus a neutral hint when the event country differs from the seller country.
	 *
	 * @param object[] $items Order items (line_snapshot).
	 * @return string[]
	 */
	public static function invoice_notes( array $items ) {
		$notes = array();
		$seller = self::seller_country();
		foreach ( $items as $item ) {
			$snapshot = json_decode( (string) ( $item->line_snapshot ?? '' ), true );
			$tax = is_array( $snapshot ) ? ( $snapshot['tax'] ?? null ) : null;
			if ( ! is_array( $tax ) ) { continue; }
			if ( '' !== (string) ( $tax['note'] ?? '' ) ) {
				$notes[] = (string) $tax['note'];
			} elseif ( 'event_country' === ( $tax['place_of_supply'] ?? '' ) && (string) ( $tax['country'] ?? '' ) !== $seller ) {
				/* translators: %s: country code */
				$notes[] = sprintf( __( 'Leistungsort: Veranstaltungsort (%s).', 'pass-captain' ), (string) $tax['country'] );
			}
		}
		return array_values( array_unique( $notes ) );
	}
}

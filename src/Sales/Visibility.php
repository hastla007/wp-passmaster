<?php
/**
 * Ticket type visibility: public (listed and sold to everyone), code (listed and sold only with an
 * unlock code – presale, members, press) and hidden (never listed or sold in the public checkout; box
 * office, complimentary tickets, waitlist offers and trusted adapters still can).
 *
 * A type with visibility "code" is unlocked by
 *  - a code from its own code list (ticket_types.access_codes, one per line; pure unlock codes that
 *    give no discount), or
 *  - an active campaign code scoped to exactly this ticket type (unlock plus discount).
 * Buyers enter either one in the discount code field. The server checks the unlock inside
 * Orders::create() for every public checkout (a hidden or locked type in the request is refused as
 * unavailable); listings only show unlocked types (PublicEvents with ?wpcap_code=…, REST /events).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Sales;

use PassCaptain\Campaigns;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Visibility {
	const VALUES = array( 'public', 'code', 'hidden' );
	/** Codes per ticket type list. */
	const MAX_CODES = 500;

	/** Visibility of a type row ('public' when the column does not exist yet). */
	public static function of( $type ) {
		$value = is_object( $type ) ? (string) ( $type->visibility ?? 'public' ) : 'public';
		return in_array( $value, self::VALUES, true ) ? $value : 'public';
	}

	/** Normalised code list from admin input (one per line or comma separated). */
	public static function parse_codes( $input ) {
		$codes = array();
		foreach ( preg_split( '/[\s,;]+/', (string) $input ) as $code ) {
			$code = Campaigns::normalize_code( $code );
			if ( strlen( $code ) >= 3 && strlen( $code ) <= 32 ) { $codes[ $code ] = true; }
		}
		return array_slice( array_keys( $codes ), 0, self::MAX_CODES );
	}

	/** Code list of a type. */
	public static function codes( $type ) {
		return self::parse_codes( is_object( $type ) ? (string) ( $type->access_codes ?? '' ) : '' );
	}

	/** Normalised, unique codes from buyer input. */
	public static function input_codes( $codes ) {
		$out = array();
		foreach ( (array) $codes as $code ) {
			if ( ! is_scalar( $code ) ) { continue; }
			$code = Campaigns::normalize_code( (string) $code );
			if ( '' !== $code ) { $out[ $code ] = true; }
		}
		return array_keys( $out );
	}

	/**
	 * Whether a type may be bought in the public checkout with the given codes.
	 *
	 * @param string[] $codes Normalised codes (input_codes()).
	 */
	public static function allows( $type, array $codes ) {
		$visibility = self::of( $type );
		if ( 'public' === $visibility ) { return true; }
		if ( 'hidden' === $visibility || ! $codes ) { return false; }
		if ( array_intersect( $codes, self::codes( $type ) ) ) { return true; }
		return (bool) self::campaign_unlocks( (int) $type->ticket_type_id, $codes );
	}

	/** Active campaign codes (now valid) scoped to the type among $codes. */
	private static function campaign_unlocks( $type_id, array $codes ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$in = implode( ',', array_fill( 0, count( $codes ), '%s' ) );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE ticket_type_id=%d AND status='active' AND (starts_at IS NULL OR starts_at<=%s) AND (ends_at IS NULL OR ends_at>=%s) AND code IN ($in)", array_merge( array( $wpdb->prefix . 'wpcap_campaigns', $type_id, $now, $now ), $codes ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- generated placeholders, replacements as one array.
	}

	/**
	 * Codes that only unlock types (in the code list of a code-only type of the given occurrences) –
	 * removed from the campaign codes so they do not fail as unknown discount codes, also when the buyer
	 * finally orders only public types of that date.
	 *
	 * @param int[]    $occurrence_ids Occurrences of the order.
	 * @param string[] $codes          Normalised codes.
	 * @return string[]
	 */
	public static function unlock_only_codes( array $occurrence_ids, array $codes ) {
		global $wpdb;
		$occurrence_ids = array_values( array_filter( array_map( 'absint', $occurrence_ids ) ) );
		if ( ! $codes || ! $occurrence_ids ) { return array(); }
		$lists = $wpdb->get_col( $wpdb->prepare( "SELECT access_codes FROM %i WHERE visibility='code' AND occurrence_id IN (" . implode( ',', array_fill( 0, count( $occurrence_ids ), '%d' ) ) . ')', array_merge( array( $wpdb->prefix . 'wpcap_ticket_types' ), $occurrence_ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders.
		if ( '' !== (string) $wpdb->last_error ) { return array(); } // Before the schema upgrade.
		$known = array();
		foreach ( (array) $lists as $list ) { $known = array_merge( $known, self::parse_codes( (string) $list ) ); }
		return array_values( array_intersect( $codes, $known ) );
	}

	/**
	 * Ids of code-visibility types among $type_rows unlocked by $code (listing).
	 *
	 * @param object[] $type_rows Rows with ticket_type_id, visibility, access_codes.
	 * @return int[]
	 */
	public static function unlocked_ids( array $type_rows, $code ) {
		$codes = self::input_codes( array( $code ) );
		$out = array();
		foreach ( $type_rows as $type ) {
			if ( 'code' === self::of( $type ) && $codes && self::allows( $type, $codes ) ) { $out[] = (int) $type->ticket_type_id; }
		}
		return $out;
	}

	/**
	 * Filters listing rows: public types stay, code types only when $code unlocks them, hidden ones go.
	 *
	 * @param object[] $type_rows
	 * @return object[]
	 */
	public static function filter_listing( array $type_rows, $code = '' ) {
		$unlocked = '' !== (string) $code ? array_flip( self::unlocked_ids( $type_rows, $code ) ) : array();
		return array_values( array_filter( $type_rows, static function ( $type ) use ( $unlocked ) {
			$visibility = self::of( $type );
			return 'public' === $visibility || ( 'code' === $visibility && isset( $unlocked[ (int) $type->ticket_type_id ] ) );
		} ) );
	}

	/** Unlock code of the current request (?wpcap_code=…), normalised; '' if none. */
	public static function request_code() {
		$raw = isset( $_GET['wpcap_code'] ) && is_scalar( $_GET['wpcap_code'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['wpcap_code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display filter.
		return mb_substr( Campaigns::normalize_code( $raw ), 0, 32 );
	}
}

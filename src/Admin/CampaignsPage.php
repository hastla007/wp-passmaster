<?php
/**
 * Admin screen "Kampagnen" (Rabattcodes): list, create, edit, activate/deactivate discount codes with the
 * existing rules of Campaigns (percent/fixed value, currency, event/ticket type scope, time window,
 * usage limit, priority, stacking), single-use code batches with CSV export. Event-scoped staff with
 * wpcap_manage_events manage the codes of their events; codes for all events need manage_options.
 * The simple create/status API of the former "Rabattcodes" page (create(), set_status(), admin-post
 * actions wpcap_campaign_create / wpcap_campaign_status) is kept.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Access;
use PassCaptain\Campaigns;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class CampaignsPage {
	const SLUG = 'wpcap-campaigns';
	const ACTION = 'wpcap_campaign_action';
	const CSV_ACTION = 'wpcap_campaign_codes_csv';
	const CAP = 'wpcap_manage_events';

	/** Global campaigns (no event) need manage_options; event campaigns the event capability. */
	public static function can_scope( $event_id ) {
		$event_id = absint( $event_id );
		return $event_id ? Access::can_access_event( $event_id, self::CAP ) : current_user_can( 'manage_options' );
	}

	/** Alias of can_scope(): whether the current user may manage codes of an event (0 = all events). */
	public static function can_manage( $event_id ) {
		return self::can_scope( $event_id );
	}

	public static function url( array $args = array() ) {
		return Forms::page_url( self::SLUG, $args );
	}

	/**
	 * Parses the campaign form into the Campaigns data format.
	 *
	 * @return array|\WP_Error
	 */
	public static function parse( array $in ) {
		$tz = FeatureScreens::site_timezone();
		$kind = Forms::choice( $in, 'kind', array( 'percent', 'fixed' ), 'percent' );
		$currency = strtoupper( Forms::text( $in, 'currency', 3 ) ?: \PassCaptain\Localization::default_currency() ); // SET-PC-08
		$value = 'percent' === $kind ? Forms::parse_percent_bp( $in['value'] ?? '' ) : Forms::parse_money( $in['value'] ?? '', Forms::currency_decimals( $currency ) );
		if ( null === $value || $value < 1 ) { return Forms::error( 'wpcap_campaign_value', __( 'Bitte einen gültigen Rabattwert angeben (Prozent bis 100 oder Betrag).', 'pass-captain' ) ); }
		$starts = FeatureScreens::optional_local( $in, 'starts_at', $tz );
		$ends = FeatureScreens::optional_local( $in, 'ends_at', $tz );
		if ( null === $starts || null === $ends ) { return Forms::error( 'wpcap_campaign_window', __( 'Ungültiger Gültigkeitszeitraum.', 'pass-captain' ) ); }
		if ( '' !== $starts && '' !== $ends && $ends <= $starts ) { return Forms::error( 'wpcap_campaign_window', __( 'Das Ende des Gültigkeitszeitraums muss nach dem Beginn liegen.', 'pass-captain' ) ); }
		$event_id = Forms::int( $in, 'event_id' );
		$type_id = Forms::int( $in, 'ticket_type_id' );
		if ( $type_id && FeatureScreens::event_of_ticket_type( $type_id ) !== $event_id ) { return Forms::error( 'wpcap_campaign_scope', __( 'Die Ticketart gehört nicht zu diesem Event.', 'pass-captain' ) ); }
		$max_raw = is_scalar( $in['max_discount'] ?? null ) ? trim( (string) $in['max_discount'] ) : '';
		$max_discount = '' === $max_raw ? 0 : Forms::parse_money( $max_raw, Forms::currency_decimals( $currency ) );
		if ( null === $max_discount ) { return Forms::error( 'wpcap_campaign_max', __( 'Der Höchstrabatt ist ungültig. Beispiel: 20,00', 'pass-captain' ) ); }
		// "exclusive" is the field of the former simple form; "combinable" the one of this form.
		$combinable = array_key_exists( 'exclusive', $in ) ? ( Forms::flag( $in, 'exclusive' ) ? 0 : 1 ) : ( Forms::flag( $in, 'combinable' ) ? 1 : 0 );
		return array(
			// Stacking rules (Campaigns::calculate()).
			'combinable' => $combinable, 'stacking' => $combinable ? 'combinable' : 'exclusive', 'with_price_rules' => Forms::flag( $in, 'with_price_rules' ) ? 1 : 0, 'max_discount_minor' => (int) $max_discount,
			// Group discount, automatic discount without code, uses per buyer.
			'min_quantity' => Forms::int( $in, 'min_quantity' ), 'auto_apply' => Forms::flag( $in, 'auto_apply' ) ? 1 : 0, 'per_buyer_limit' => Forms::int( $in, 'per_buyer_limit' ),
			'code' => Forms::text( $in, 'code', 64 ), 'name' => Forms::text( $in, 'name' ), 'kind' => $kind, 'value' => $value, 'currency' => $currency,
			'event_id' => $event_id, 'ticket_type_id' => $type_id, 'usage_limit' => Forms::int( $in, 'usage_limit' ), 'priority' => Forms::int( $in, 'priority', 100 ),
			'starts_at' => $starts, 'ends_at' => $ends,
		);
	}

	/**
	 * Executes an admin action (testable without a request).
	 *
	 * @return array|\WP_Error
	 */
	public static function admin_action( array $in ) {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		$do = sanitize_key( $in['do'] ?? '' );
		$id = Forms::int( $in, 'id' );
		$existing = $id ? Campaigns::get( $id ) : null;
		if ( $id && ! $existing ) { return Forms::error( 'wpcap_campaign_missing', __( 'Kampagne nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( $existing && ! self::can_scope( (int) $existing->event_id ) ) { return Forms::forbidden(); }
		switch ( $do ) {
			case 'stacking':
				if ( ! current_user_can( 'manage_options' ) ) { return Forms::forbidden(); }
				$bp = Forms::parse_percent_bp( $in['max_total_discount'] ?? '' );
				if ( null === $bp ) { return Forms::error( 'wpcap_campaign_max_total', __( 'Bitte einen Prozentwert zwischen 0 und 100 angeben.', 'pass-captain' ) ); }
				\PassCaptain\Settings::update( array( 'max_total_discount_bp' => $bp ) );
				\PassCaptain\Audit::record( 'campaign_stacking_settings', 'campaign', 0, array( 'max_total_discount_bp' => $bp ) );
				return array( 'max_total_discount_bp' => $bp );
			case 'save':
				$data = self::parse( $in );
				if ( is_wp_error( $data ) ) { return $data; }
				if ( ! self::can_scope( $data['event_id'] ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event. Kampagnen ohne Event dürfen nur Administratoren anlegen.', 'pass-captain' ) ); }
				if ( $existing ) {
					$data['status'] = Forms::choice( $in, 'status', array( 'active', 'inactive' ), (string) $existing->status );
					return Campaigns::update( $id, $data );
				}
				return Campaigns::create( $data );
			case 'generate':
				if ( ! $existing ) { return Forms::error( 'wpcap_campaign_missing', __( 'Kampagne nicht gefunden.', 'pass-captain' ), 404 ); }
				return Campaigns::generate_codes( $id, Forms::int( $in, 'count' ), Forms::text( $in, 'prefix', 16 ) );
			case 'status':
				if ( ! $existing ) { return Forms::error( 'wpcap_campaign_missing', __( 'Kampagne nicht gefunden.', 'pass-captain' ), 404 ); }
				return Campaigns::set_status( $id, Forms::choice( $in, 'status', array( 'active', 'inactive' ), 'inactive' ) );
		}
		return Forms::error( 'wpcap_campaign_action', __( 'Unbekannte Aktion.', 'pass-captain' ), 404 );
	}

	public static function handle_action() {
		Forms::verify( self::ACTION );
		$in = Forms::post();
		$result = self::admin_action( $in );
		$do = sanitize_key( $in['do'] ?? '' );
		$id = Forms::int( $in, 'id' );
		$error_url = Forms::page_url( self::SLUG, array( 'campaign_id' => $id ? $id : 'new' ) );
		if ( 'generate' === $do && is_array( $result ) ) {
			/* translators: %d: number of codes */
			Forms::finish( $result, sprintf( __( '%d Einzelcodes erzeugt. Export über „Codes als CSV“.', 'pass-captain' ), (int) $result['created'] ), Forms::page_url( self::SLUG, array( 'campaign_id' => $id ) ) );
		}
		$message = 'status' === $do && is_array( $result ) ? ( 'active' === $result['status'] ? __( 'Kampagne aktiviert.', 'pass-captain' ) : __( 'Kampagne deaktiviert.', 'pass-captain' ) ) : ( 'stacking' === $do ? __( 'Stapelregeln gespeichert.', 'pass-captain' ) : __( 'Kampagne gespeichert.', 'pass-captain' ) );
		Forms::finish( $result, $message, Forms::page_url( self::SLUG ), 'save' === $do ? $error_url : '' );
	}

	/**
	 * Creates a code from the simple form (former "Rabattcodes" page; testable without a request).
	 *
	 * @param array $in code, name, kind (percent|fixed), value (percent or money), currency, event_id,
	 *                  ticket_type_id, usage_limit, starts_at, ends_at (local, site time zone), priority, exclusive.
	 * @return array|\WP_Error
	 */
	public static function create( array $in ) {
		$event_id = Forms::int( $in, 'event_id' );
		if ( ! self::can_scope( $event_id ) ) {
			return Forms::forbidden( $event_id ? __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ) : __( 'Codes für alle Events kann nur die Administration anlegen.', 'pass-captain' ) );
		}
		$in['do'] = 'save';
		$in['id'] = 0;
		if ( ! array_key_exists( 'exclusive', $in ) && ! array_key_exists( 'combinable', $in ) ) { $in['combinable'] = 1; }
		return self::admin_action( $in );
	}

	/** Activates/deactivates a code (former simple form: campaign_id, status). @return array|true|\WP_Error */
	public static function set_status( array $in ) {
		$id = Forms::int( $in, 'campaign_id' ) ? Forms::int( $in, 'campaign_id' ) : Forms::int( $in, 'id' );
		return self::admin_action( array( 'do' => 'status', 'id' => $id, 'status' => Forms::choice( $in, 'status', array( 'active', 'inactive' ), 'inactive' ) ) );
	}

	/** admin-post wpcap_campaign_create (former simple form). */
	public static function handle_create() {
		Forms::verify( 'wpcap_campaign_create' );
		Forms::finish( self::create( Forms::post() ), __( 'Rabattcode angelegt.', 'pass-captain' ), self::url() );
	}

	/** admin-post wpcap_campaign_status (former simple form). */
	public static function handle_status() {
		$in = Forms::post();
		Forms::verify( 'wpcap_campaign_status_' . Forms::int( $in, 'campaign_id' ) );
		Forms::finish( self::set_status( $in ), __( 'Status gespeichert.', 'pass-captain' ), self::url() );
	}

	/** CSV download of the generated single-use codes of a template campaign (GET, nonce). */
	public static function handle_codes_csv() {
		check_admin_referer( self::CSV_ACTION );
		$id = absint( $_GET['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked above.
		$campaign = $id ? Campaigns::get( $id ) : null;
		if ( ! current_user_can( self::CAP ) || ! $campaign || ! self::can_scope( (int) $campaign->event_id ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), '', array( 'response' => 403 ) ); }
		\PassCaptain\Audit::record( 'campaign_codes_exported', 'campaign', $id, array() );
		\PassCaptain\Reports\Scope::send_csv( Campaigns::batch_rows( $id ), 'codes-' . sanitize_file_name( strtolower( (string) $campaign->code ) ) . '.csv' );
	}

	/** Campaigns visible to the current user: event campaigns in scope, global ones for administrators. */
	public static function campaigns() {
		global $wpdb;
		$scope = Queries::event_ids_for( self::CAP );
		$where = null === $scope ? '' : ( $scope ? ' AND c.event_id IN (' . implode( ',', array_map( 'absint', $scope ) ) . ')' : ' AND 1=0' );
		// Generated single-use codes (batch_id) are listed with their template campaign, not one by one.
		return $wpdb->get_results( "SELECT c.*,e.name AS event_name,tt.name AS ticket_name,(SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_campaigns b WHERE b.batch_id=c.campaign_id) AS batch_codes FROM {$wpdb->prefix}wpcap_campaigns c LEFT JOIN {$wpdb->prefix}wpcap_events e ON e.event_id=c.event_id LEFT JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.ticket_type_id=c.ticket_type_id WHERE c.batch_id=0{$where} ORDER BY c.status='active' DESC,c.created_at DESC LIMIT 500" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- scope from absint ids.
	}

	/** Stacking summary for the list. */
	public static function stacking_label( $c ) {
		$parts = array( (int) ( $c->combinable ?? 1 ) ? __( 'ja', 'pass-captain' ) : __( 'nein (nur allein)', 'pass-captain' ) );
		if ( ! (int) ( $c->with_price_rules ?? 1 ) ) { $parts[] = __( 'nicht auf Preisregeln', 'pass-captain' ); }
		if ( (int) ( $c->max_discount_minor ?? 0 ) ) { $parts[] = sprintf( /* translators: %s: amount */ __( 'max. %s', 'pass-captain' ), Forms::money( (int) $c->max_discount_minor, $c->currency ) ); }
		if ( (int) ( $c->auto_apply ?? 0 ) ) { $parts[] = __( 'automatisch (ohne Code)', 'pass-captain' ); }
		if ( (int) ( $c->min_quantity ?? 0 ) > 1 ) { $parts[] = sprintf( /* translators: %d: tickets */ _n( 'ab %d Ticket', 'ab %d Tickets', (int) $c->min_quantity, 'pass-captain' ), (int) $c->min_quantity ); }
		if ( (int) ( $c->per_buyer_limit ?? 0 ) ) { $parts[] = sprintf( /* translators: %d: uses */ __( 'max. %d je Käufer', 'pass-captain' ), (int) $c->per_buyer_limit ); }
		if ( (int) ( $c->batch_codes ?? 0 ) ) { $parts[] = sprintf( /* translators: %d: codes */ __( '%d Einzelcodes', 'pass-captain' ), (int) $c->batch_codes ); }
		return implode( ', ', $parts );
	}

	/** Global stacking settings (administrators): cap of the total reduction per order. */
	private static function render_stacking() {
		$bp = (int) \PassCaptain\Settings::get( 'max_total_discount_bp' );
		echo '<h2>' . esc_html__( 'Stapelregeln', 'pass-captain' ) . '</h2><p class="description">' . esc_html__( 'Codes werden nach Priorität nacheinander angewendet. Nicht kombinierbare Codes müssen allein eingelöst werden. Der Gesamtrabatt begrenzt die Summe aus automatischen Preisregeln und Codes je Bestellung (bezogen auf die regulären Preise); spätere Codes werden zuerst gekürzt.', 'pass-captain' ) . '</p>';
		if ( ! current_user_can( 'manage_options' ) ) {
			/* translators: %s: percentage */
			echo '<p>' . esc_html( $bp ? sprintf( __( 'Maximaler Gesamtrabatt: %s %%', 'pass-captain' ), Forms::percent_input( $bp ) ) : __( 'Maximaler Gesamtrabatt: unbegrenzt', 'pass-captain' ) ) . '</p>';
			return;
		}
		Forms::open_form( self::ACTION );
		Forms::hidden( 'do', 'stacking' );
		echo '<p><label for="wpcap-cp-maxtotal">' . esc_html__( 'Maximaler Gesamtrabatt je Bestellung in %', 'pass-captain' ) . '</label> <input id="wpcap-cp-maxtotal" name="max_total_discount" type="text" inputmode="decimal" style="width:6em" value="' . esc_attr( $bp ? Forms::percent_input( $bp ) : '0' ) . '" aria-describedby="wpcap-cp-maxtotal-desc"> <button type="submit" class="button">' . esc_html__( 'Speichern', 'pass-captain' ) . '</button></p><p class="description" id="wpcap-cp-maxtotal-desc">' . esc_html__( '0 = unbegrenzt.', 'pass-captain' ) . '</p></form>';
	}

	/** Display of the discount, e.g. "10 %" or "5,00 EUR". */
	public static function value_label( $campaign ) {
		return 'percent' === $campaign->kind ? Forms::percent_input( $campaign->discount_value ) . ' %' : Forms::money( $campaign->discount_value, $campaign->currency );
	}

	/** Whether the campaign can currently be redeemed, as a label. */
	public static function state_label( $c ) {
		$now = \PassCaptain\Clock::mysql();
		if ( 'active' !== $c->status ) { return __( 'Deaktiviert', 'pass-captain' ); }
		if ( $c->starts_at && $c->starts_at > $now ) { return __( 'Geplant', 'pass-captain' ); }
		if ( $c->ends_at && $c->ends_at < $now ) { return __( 'Abgelaufen', 'pass-captain' ); }
		if ( (int) $c->usage_limit > 0 && (int) $c->used_count >= (int) $c->usage_limit ) { return __( 'Limit erreicht', 'pass-captain' ); }
		return __( 'Aktiv', 'pass-captain' );
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		echo '<div class="wrap wpcap-admin"><h1>' . esc_html__( 'Kampagnen', 'pass-captain' ) . '</h1>';
		Forms::render_notices();
		$arg = Forms::query_arg( 'campaign_id', '' );
		if ( 'new' === $arg || absint( $arg ) ) {
			$campaign = absint( $arg ) ? Campaigns::get( absint( $arg ) ) : null;
			echo '<p><a href="' . esc_url( Forms::page_url( self::SLUG ) ) . '">&larr; ' . esc_html__( 'Alle Kampagnen', 'pass-captain' ) . '</a></p>';
			if ( absint( $arg ) && ( ! $campaign || ! self::can_scope( (int) $campaign->event_id ) ) ) { echo '<p>' . esc_html__( 'Kampagne nicht gefunden.', 'pass-captain' ) . '</p></div>'; return; }
			self::render_form( $campaign );
			echo '</div>';
			return;
		}
		$tz = FeatureScreens::site_timezone();
		echo '<p>' . esc_html__( 'Rabattcodes für den Checkout. Pro Bestellung sind höchstens drei Codes möglich; sie werden nach Priorität angewendet.', 'pass-captain' ) . ' <a class="button" href="' . esc_url( Forms::page_url( self::SLUG, array( 'campaign_id' => 'new' ) ) ) . '">' . esc_html__( 'Neue Kampagne', 'pass-captain' ) . '</a></p>';
		self::render_stacking();
		$rows = self::campaigns();
		if ( ! $rows ) { echo '<p>' . esc_html__( 'Noch keine Kampagnen.', 'pass-captain' ) . '</p></div>'; return; }
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Code', 'pass-captain' ), __( 'Name', 'pass-captain' ), __( 'Rabatt', 'pass-captain' ), __( 'Gilt für', 'pass-captain' ), __( 'Zeitraum', 'pass-captain' ), __( 'Einlösungen', 'pass-captain' ), __( 'Priorität', 'pass-captain' ), __( 'Kombinierbar', 'pass-captain' ), __( 'Status', 'pass-captain' ), __( 'Aktionen', 'pass-captain' ) ) as $head ) {
			echo '<th scope="col">' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $c ) {
			$scope = (int) $c->event_id ? (string) $c->event_name . ( (int) $c->ticket_type_id ? ' – ' . (string) $c->ticket_name : '' ) : __( 'Alle Events', 'pass-captain' );
			$window = ( $c->starts_at ? Forms::datetime( $c->starts_at, $tz, false ) : __( 'sofort', 'pass-captain' ) ) . ' – ' . ( $c->ends_at ? Forms::datetime( $c->ends_at, $tz, false ) : __( 'unbegrenzt', 'pass-captain' ) );
			$uses = (int) $c->used_count . ' / ' . ( (int) $c->usage_limit ? (int) $c->usage_limit : '∞' );
			echo '<tr><td><a href="' . esc_url( Forms::page_url( self::SLUG, array( 'campaign_id' => (int) $c->campaign_id ) ) ) . '"><code>' . esc_html( $c->code ) . '</code></a></td><td>' . esc_html( $c->name ) . '</td><td>' . esc_html( self::value_label( $c ) ) . '</td><td>' . esc_html( $scope ) . '</td><td>' . esc_html( $window ) . '</td><td>' . esc_html( $uses ) . '</td><td>' . (int) $c->priority . '</td><td>' . esc_html( self::stacking_label( $c ) ) . '</td><td>' . esc_html( self::state_label( $c ) ) . '</td><td>';
			if ( 'active' === $c->status ) {
				FeatureScreens::button( self::ACTION, array( 'do' => 'status', 'id' => (int) $c->campaign_id, 'status' => 'inactive' ), __( 'Deaktivieren', 'pass-captain' ) );
			} else {
				FeatureScreens::button( self::ACTION, array( 'do' => 'status', 'id' => (int) $c->campaign_id, 'status' => 'active' ), __( 'Aktivieren', 'pass-captain' ) );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function render_form( $c ) {
		$tz = FeatureScreens::site_timezone();
		$scope = Queries::event_ids_for( self::CAP );
		$events = Queries::event_options( $scope );
		$types = FeatureScreens::ticket_type_options( $scope );
		$used = $c && (int) $c->used_count > 0;
		echo '<h2>' . esc_html( $c ? sprintf( /* translators: %s: code */ __( 'Kampagne %s bearbeiten', 'pass-captain' ), $c->code ) : __( 'Neue Kampagne', 'pass-captain' ) ) . '</h2>';
		Forms::open_form( self::ACTION );
		Forms::hidden( 'do', 'save' );
		Forms::hidden( 'id', $c ? (int) $c->campaign_id : 0 );
		echo '<table class="form-table"><tbody>';
		$fixed = $used ? ' readonly aria-readonly="true"' : '';
		FeatureScreens::input_row( 'wpcap-cp-code', 'code', __( 'Code', 'pass-captain' ), $c ? $c->code : '', 'text', 'class="regular-text code" minlength="3" maxlength="32" pattern="[A-Za-z0-9_\-]{3,32}" required' . $fixed, __( '3–32 Zeichen: Buchstaben, Ziffern, Bindestrich, Unterstrich. Wird in Großbuchstaben gespeichert.', 'pass-captain' ) );
		FeatureScreens::input_row( 'wpcap-cp-name', 'name', __( 'Interner Name', 'pass-captain' ), $c ? $c->name : '', 'text', 'class="regular-text" maxlength="190"' );
		$kind = $c ? (string) $c->kind : 'percent';
		FeatureScreens::row( 'wpcap-cp-kind', __( 'Rabattart', 'pass-captain' ), static function () use ( $kind, $used ) { Forms::select( 'kind', array( 'percent' => __( 'Prozent', 'pass-captain' ), 'fixed' => __( 'Fester Betrag', 'pass-captain' ) ), $kind, 'wpcap-cp-kind', $used ? 'disabled' : '' ); if ( $used ) { Forms::hidden( 'kind', $kind ); } } );
		$value = $c ? ( 'percent' === $c->kind ? Forms::percent_input( $c->discount_value ) : Forms::money_input( $c->discount_value, Forms::currency_decimals( $c->currency ) ) ) : '';
		FeatureScreens::input_row( 'wpcap-cp-value', 'value', __( 'Rabattwert', 'pass-captain' ), $value, 'text', 'inputmode="decimal" style="width:8em" required', __( 'Prozent (z. B. 10 oder 7,5) oder Betrag (z. B. 5,00) je nach Rabattart.', 'pass-captain' ) );
		FeatureScreens::input_row( 'wpcap-cp-currency', 'currency', __( 'Währung', 'pass-captain' ), $c ? $c->currency : \PassCaptain\Localization::default_currency(), 'text', 'maxlength="3" pattern="[A-Za-z]{3}" style="width:5em" required' . $fixed );
		$event_id = $c ? (int) $c->event_id : 0;
		$type_id = $c ? (int) $c->ticket_type_id : 0;
		$event_choices = ( current_user_can( 'manage_options' ) ? array( '0' => __( 'Alle Events', 'pass-captain' ) ) : array() ) + $events;
		FeatureScreens::row( 'wpcap-cp-event', __( 'Event', 'pass-captain' ), static function () use ( $event_choices, $event_id ) { Forms::select( 'event_id', $event_choices, $event_id, 'wpcap-cp-event' ); } );
		FeatureScreens::row( 'wpcap-cp-type', __( 'Ticketart (optional)', 'pass-captain' ), static function () use ( $types, $type_id ) { Forms::select( 'ticket_type_id', array( '0' => __( 'Alle Ticketarten des Events', 'pass-captain' ) ) + $types, $type_id, 'wpcap-cp-type', 'aria-describedby="wpcap-cp-type-desc"' ); }, __( 'Die Ticketart muss zum gewählten Event gehören.', 'pass-captain' ) );
		/* translators: %s: timezone */
		$tz_note = sprintf( __( 'Optional. Zeitzone: %s', 'pass-captain' ), $tz );
		FeatureScreens::input_row( 'wpcap-cp-starts', 'starts_at', __( 'Gültig ab', 'pass-captain' ), $c ? Forms::utc_to_local( $c->starts_at, $tz ) : '', 'datetime-local', '', $tz_note );
		FeatureScreens::input_row( 'wpcap-cp-ends', 'ends_at', __( 'Gültig bis', 'pass-captain' ), $c ? Forms::utc_to_local( $c->ends_at, $tz ) : '', 'datetime-local' );
		FeatureScreens::input_row( 'wpcap-cp-limit', 'usage_limit', __( 'Maximale Einlösungen', 'pass-captain' ), $c ? (int) $c->usage_limit : 0, 'number', 'min="0"', $c ? sprintf( /* translators: %d: uses so far */ __( '0 = unbegrenzt. Bisher eingelöst: %d', 'pass-captain' ), (int) $c->used_count ) : __( '0 = unbegrenzt.', 'pass-captain' ) );
		FeatureScreens::input_row( 'wpcap-cp-priority', 'priority', __( 'Priorität', 'pass-captain' ), $c ? (int) $c->priority : 100, 'number', 'min="0" max="1000"', __( 'Kleinere Zahl wird zuerst angewendet.', 'pass-captain' ) );
		$combinable = $c ? (int) ( $c->combinable ?? 1 ) : 1;
		$with_rules = $c ? (int) ( $c->with_price_rules ?? 1 ) : 1;
		echo '<tr><th scope="row">' . esc_html__( 'Stapelregeln', 'pass-captain' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Stapelregeln', 'pass-captain' ) . '</legend>';
		echo '<label><input type="checkbox" name="combinable" value="1"' . checked( 1, $combinable, false ) . '> ' . esc_html__( 'Mit anderen Codes kombinierbar', 'pass-captain' ) . '</label><br>';
		echo '<label><input type="checkbox" name="with_price_rules" value="1"' . checked( 1, $with_rules, false ) . '> ' . esc_html__( 'Auch auf Tickets mit automatischem Preis (Frühbucher, Last-Minute, Nachfrage, Wochentag) anwenden', 'pass-captain' ) . '</label></fieldset></td></tr>';
		FeatureScreens::input_row( 'wpcap-cp-max', 'max_discount', __( 'Höchstrabatt je Bestellung (optional)', 'pass-captain' ), $c && (int) ( $c->max_discount_minor ?? 0 ) ? Forms::money_input( (int) $c->max_discount_minor, Forms::currency_decimals( $c->currency ) ) : '', 'text', 'inputmode="decimal" style="width:8em"', __( 'Betrag in der Kampagnenwährung; leer = ohne Obergrenze. Sinnvoll für Prozentrabatte.', 'pass-captain' ) );
		FeatureScreens::input_row( 'wpcap-cp-minqty', 'min_quantity', __( 'Mindestmenge (Gruppenrabatt)', 'pass-captain' ), $c ? (int) ( $c->min_quantity ?? 0 ) : 0, 'number', 'min="0" max="1000"', __( 'Gilt erst ab so vielen passenden Tickets in der Bestellung; 0 = immer.', 'pass-captain' ) );
		FeatureScreens::input_row( 'wpcap-cp-buyer', 'per_buyer_limit', __( 'Einlösungen je Käufer', 'pass-captain' ), $c ? (int) ( $c->per_buyer_limit ?? 0 ) : 0, 'number', 'min="0" max="1000"', __( 'Je E-Mail-Adresse; 0 = unbegrenzt.', 'pass-captain' ) );
		$auto = $c ? (int) ( $c->auto_apply ?? 0 ) : 0;
		echo '<tr><th scope="row">' . esc_html__( 'Automatisch', 'pass-captain' ) . '</th><td><label><input type="checkbox" name="auto_apply" value="1"' . checked( 1, $auto, false ) . '> ' . esc_html__( 'Ohne Code automatisch anwenden, wenn die Bestellung passt (z. B. Gruppenrabatt)', 'pass-captain' ) . '</label></td></tr>';
		if ( $c ) {
			$status = (string) $c->status;
			FeatureScreens::row( 'wpcap-cp-status', __( 'Status', 'pass-captain' ), static function () use ( $status ) { Forms::select( 'status', array( 'active' => __( 'Aktiv', 'pass-captain' ), 'inactive' => __( 'Deaktiviert', 'pass-captain' ) ), $status, 'wpcap-cp-status' ); } );
		}
		echo '</tbody></table>';
		if ( $used ) { echo '<p class="description">' . esc_html__( 'Dieser Code wurde bereits eingelöst: Code, Rabattart und Währung sind gesperrt.', 'pass-captain' ) . '</p>'; }
		submit_button( $c ? __( 'Kampagne speichern', 'pass-captain' ) : __( 'Kampagne anlegen', 'pass-captain' ) );
		echo '</form>';
		if ( $c && ! (int) ( $c->batch_id ?? 0 ) ) { self::render_generator( $c ); }
	}

	/** Code batch: n single-use codes with the settings of this campaign, CSV export. */
	private static function render_generator( $c ) {
		echo '<h2>' . esc_html__( 'Einzelcodes erzeugen', 'pass-captain' ) . '</h2><p class="description">' . esc_html__( 'Erzeugt einmal einlösbare Codes mit den Einstellungen dieser Kampagne (z. B. für Mailings oder Partner).', 'pass-captain' ) . '</p>';
		Forms::open_form( self::ACTION );
		Forms::hidden( 'do', 'generate' );
		Forms::hidden( 'id', (int) $c->campaign_id );
		echo '<p><label for="wpcap-cp-count">' . esc_html__( 'Anzahl', 'pass-captain' ) . '</label> <input id="wpcap-cp-count" name="count" type="number" min="1" max="' . (int) Campaigns::MAX_BATCH . '" value="100" style="width:7em"> ';
		echo '<label for="wpcap-cp-prefix">' . esc_html__( 'Präfix (optional)', 'pass-captain' ) . '</label> <input id="wpcap-cp-prefix" name="prefix" type="text" maxlength="12" pattern="[A-Za-z0-9_\-]{0,12}" style="width:8em"> ';
		echo '<button type="submit" class="button">' . esc_html__( 'Codes erzeugen', 'pass-captain' ) . '</button> ';
		$url = wp_nonce_url( add_query_arg( array( 'action' => self::CSV_ACTION, 'id' => (int) $c->campaign_id ), admin_url( 'admin-post.php' ) ), self::CSV_ACTION );
		echo '<a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Codes als CSV', 'pass-captain' ) . '</a></p></form>';
	}
}

<?php
/**
 * Admin screen "Mitgliedspässe": list member/season passes with their rules, issue a pass,
 * change its validity and rule quotas, add rules and revoke passes (Memberships). Passes requested by
 * the team (REST POST /member-passes, A481) are listed as open requests: a second person with the
 * right for complimentary tickets (wpcap_approve_comps, never the requester) approves or rejects them;
 * see docs/PASSES.md.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Access;
use PassCaptain\Memberships;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class MembershipsPage {
	const SLUG = 'wpcap-memberships';
	const ACTION = 'wpcap_membership_action';
	const CAP = 'wpcap_manage_orders';

	public static function url( array $args = array() ) {
		return Forms::page_url( self::SLUG, $args );
	}

	/** Approve, reject or revoke a pass from the request list (A481). @return mixed|\WP_Error */
	public static function decide( array $in ) {
		$pass_id = Forms::int( $in, 'pass_id' );
		$do = Forms::choice( $in, 'do', array( 'approve', 'reject', 'revoke' ), '' );
		if ( 'approve' === $do ) { return Memberships::approve( $pass_id ); }
		if ( 'reject' === $do ) { return Memberships::reject( $pass_id ); }
		if ( 'revoke' === $do ) {
			$pass = Memberships::get( $pass_id );
			if ( ! $pass ) { return Forms::error( 'wpcap_pass_not_found', __( 'Pass nicht gefunden.', 'pass-captain' ), 404 ); }
			$events = Memberships::event_ids( $pass_id );
			if ( ! Memberships::in_scope( $events, self::CAP ) && ! Memberships::in_scope( $events, Memberships::APPROVE_CAP ) ) { return Forms::forbidden(); }
			return Memberships::revoke( $pass_id );
		}
		return Forms::error( 'wpcap_pass_action', __( 'Unbekannte Aktion.', 'pass-captain' ) );
	}

	/** admin-post wpcap_member_pass. */
	public static function handle_decide() {
		Forms::require_any_cap( array( self::CAP, Memberships::APPROVE_CAP ) );
		$in = Forms::post();
		Forms::verify( 'wpcap_member_pass_' . Forms::int( $in, 'pass_id' ) );
		$labels = array( 'approve' => __( 'Mitgliedspass freigegeben und versendet.', 'pass-captain' ), 'reject' => __( 'Antrag abgelehnt.', 'pass-captain' ), 'revoke' => __( 'Mitgliedspass widerrufen.', 'pass-captain' ) );
		Forms::finish( self::decide( $in ), $labels[ Forms::choice( $in, 'do', array_keys( $labels ), 'revoke' ) ], self::url() );
	}

	public static function status_label( $status ) {
		$labels = array( 'pending' => __( 'beantragt', 'pass-captain' ), 'active' => __( 'aktiv', 'pass-captain' ), 'revoked' => __( 'widerrufen', 'pass-captain' ), 'rejected' => __( 'abgelehnt', 'pass-captain' ) );
		return $labels[ $status ] ?? $status;
	}

	private static function user_label( $user_id ) {
		if ( ! (int) $user_id ) { return '—'; }
		$user = function_exists( 'get_userdata' ) ? get_userdata( (int) $user_id ) : null;
		return $user && ! empty( $user->display_name ) ? (string) $user->display_name : '#' . (int) $user_id;
	}

	public static function rule_label( $rule ) {
		$scope = (int) $rule->event_id ? '#' . (int) $rule->event_id . ( (int) $rule->ticket_type_id ? ' / ' . __( 'Ticketart', 'pass-captain' ) . ' #' . (int) $rule->ticket_type_id : '' ) : __( 'alle Events', 'pass-captain' );
		/* translators: 1: scope (event/ticket type), 2: uses per day (∞ = unlimited), 3: total uses (∞ = unlimited) */
		return sprintf( __( '%1$s: %2$s/Tag, gesamt %3$s', 'pass-captain' ), $scope, (int) $rule->uses_per_day ? (string) (int) $rule->uses_per_day : '∞', (int) $rule->uses_total ? (string) (int) $rule->uses_total : '∞' ); // A524: 0 per day = unlimited.
	}

	/** Open requests (pending passes in the user's scope) with approve/reject/revoke buttons (A481). */
	private static function render_requests() {
		if ( ! method_exists( Memberships::class, 'list_passes' ) ) { return; }
		$passes = Memberships::list_passes( 'pending' );
		if ( ! $passes ) { return; }
		$tz = wp_timezone_string();
		echo '<h2>' . esc_html__( 'Offene Anträge', 'pass-captain' ) . '</h2><p class="description">' . esc_html__( 'Ein Mitgliedspass ist freier Eintritt. Anträge des Teams gibt eine zweite Person mit dem Recht für Freikarten frei; erst dann wird der Passcode erzeugt und per E-Mail versendet.', 'pass-captain' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'E-Mail', 'pass-captain' ) . '</th><th>' . esc_html__( 'Status', 'pass-captain' ) . '</th><th>' . esc_html__( 'Gültig', 'pass-captain' ) . '</th><th>' . esc_html__( 'Regeln', 'pass-captain' ) . '</th><th>' . esc_html__( 'Beantragt von', 'pass-captain' ) . '</th><th>' . esc_html__( 'Aktionen', 'pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $passes as $pass ) {
			echo '<tr><td>' . esc_html( $pass->member_email ) . '</td><td>' . esc_html( self::status_label( $pass->status ) ) . '</td><td>' . esc_html( Forms::datetime( $pass->valid_from, $tz, false ) . ' – ' . Forms::datetime( $pass->valid_until, $tz, false ) ) . '</td><td>' . esc_html( implode( '; ', array_map( array( self::class, 'rule_label' ), (array) ( $pass->rules ?? array() ) ) ) ) . '</td><td>' . esc_html( self::user_label( $pass->requested_by ?? 0 ) ) . '</td><td>';
			$actions = array();
			if ( Memberships::can_decide( $pass ) ) { $actions['approve'] = __( 'Freigeben', 'pass-captain' ); }
			if ( current_user_can( Memberships::APPROVE_CAP ) ) { $actions['reject'] = __( 'Ablehnen', 'pass-captain' ); }
			$actions['revoke'] = __( 'Widerrufen', 'pass-captain' );
			if ( Memberships::self_approval( $pass ) ) { echo '<p class="description">' . esc_html__( 'Selbst beantragt – Freigabe durch eine andere Person.', 'pass-captain' ) . '</p>'; }
			foreach ( $actions as $do => $label ) {
				Forms::open_form( 'wpcap_member_pass', 'wpcap_member_pass_' . (int) $pass->pass_id, 'style="display:inline"' );
				Forms::hidden( 'pass_id', (int) $pass->pass_id );
				Forms::hidden( 'do', $do );
				echo '<button type="submit" class="button' . ( 'approve' === $do ? ' button-primary' : '' ) . '">' . esc_html( $label ) . '</button></form> ';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** Rules without event need manage_options, event rules the order capability for that event. */
	public static function can_rule_event( $event_id ) {
		return (int) $event_id ? Access::can_access_event( (int) $event_id, self::CAP ) : current_user_can( 'manage_options' );
	}

	/** Whether the current user may manage a pass: every rule must be in their scope (shared with REST). */
	public static function can_manage_pass( $pass_id ) {
		return Memberships::current_user_can_manage_pass( $pass_id );
	}

	/** Rule input (scope, event/ticket type or resource, quotas, expiry) → array or WP_Error (scope check: Memberships). */
	private static function rule_from( array $in ) {
		$scope = Forms::choice( $in, 'scope', Memberships::SCOPES, 'event' );
		$expires = FeatureScreens::optional_local( $in, 'expires_at', FeatureScreens::site_timezone() );
		if ( null === $expires ) { return Forms::error( 'wpcap_pass_rule_expiry', __( 'Ungültiges Ablaufdatum der Regel.', 'pass-captain' ) ); }
		$rule = Memberships::validate_rule_for_current_user( array(
			'scope' => $scope,
			'event_id' => Forms::int( $in, 'event_id' ),
			'ticket_type_id' => Forms::int( $in, 'ticket_type_id' ),
			'resource_id' => Forms::int( $in, 'resource_id' ),
		) );
		if ( is_wp_error( $rule ) ) { return $rule; }
		return array( 'event_id' => (int) $rule['event_id'], 'ticket_type_id' => (int) $rule['ticket_type_id'], 'resource_id' => (int) $rule['resource_id'], 'uses_total' => Forms::int( $in, 'uses_total' ), 'uses_per_day' => Forms::int( $in, 'uses_per_day', 1 ), 'scope' => $scope, 'slot_mode' => Forms::choice( $in, 'slot_mode', Memberships::SLOT_MODES, 'slot_free' ), 'expires_at' => (string) $expires );
	}

	/** Label of a service/any rule: scope, resource, slot binding, expiry. */
	private static function service_rule_label( $rule ) {
		$resource = (int) $rule->resource_id ? \PassCaptain\ServiceResources::resource( (int) $rule->resource_id ) : null;
		$label = ( 'any' === $rule->scope ? __( 'Events und Ressourcen', 'pass-captain' ) : __( 'Service', 'pass-captain' ) ) . ': ' . ( $resource ? $resource->name : __( 'Alle Ressourcen', 'pass-captain' ) );
		$label .= ' · ' . ( 'slot_bound' === $rule->slot_mode ? __( 'terminpflichtig', 'pass-captain' ) : __( 'terminfrei', 'pass-captain' ) );
		if ( ! empty( $rule->expires_at ) ) { $label .= ' · ' . sprintf( /* translators: %s: date */ __( 'bis %s', 'pass-captain' ), Forms::datetime( $rule->expires_at, FeatureScreens::site_timezone(), false ) ); }
		return $label;
	}

	/** Validity inputs (local site time) → [from, until] in UTC or WP_Error. */
	private static function validity( array $in ) {
		$tz = FeatureScreens::site_timezone();
		$from = Forms::local_to_utc( $in['valid_from'] ?? '', $tz );
		$until_in = trim( (string) ( $in['valid_until'] ?? '' ) );
		// A524: a date without time means the end of that day (local time), not its start.
		$until = Forms::local_to_utc( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $until_in ) ? $until_in . ' 23:59' : $until_in, $tz );
		if ( null === $from || null === $until || $until <= $from ) { return Forms::error( 'wpcap_pass_validity', __( 'Bitte einen gültigen Zeitraum angeben: „Gültig bis“ muss nach „Gültig ab“ liegen.', 'pass-captain' ) ); }
		return array( $from, $until );
	}

	/**
	 * Executes an admin action (testable without a request).
	 *
	 * @return mixed|\WP_Error
	 */
	public static function admin_action( array $in ) {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		$do = sanitize_key( $in['do'] ?? '' );
		$id = Forms::int( $in, 'id' );
		if ( 'issue' !== $do ) {
			if ( ! $id || ! Memberships::get( $id ) ) { return Forms::error( 'wpcap_pass_not_found', __( 'Pass nicht gefunden.', 'pass-captain' ), 404 ); }
			if ( ! self::can_manage_pass( $id ) ) { return Forms::forbidden( __( 'Keine Berechtigung für diesen Pass.', 'pass-captain' ) ); }
		}
		switch ( $do ) {
			case 'issue':
				$rule = self::rule_from( $in );
				if ( is_wp_error( $rule ) ) { return $rule; }
				$range = self::validity( $in );
				if ( is_wp_error( $range ) ) { return $range; }
				$email = Forms::text( $in, 'email' );
				if ( ! is_email( $email ) ) { return Forms::error( 'wpcap_pass_email', __( 'Bitte eine gültige E-Mail-Adresse angeben.', 'pass-captain' ) ); }
				$result = Memberships::issue( $email, $range[0], $range[1], array( $rule ) );
				return is_wp_error( $result ) ? $result : array( 'pass_id' => (int) $result['pass_id'] );
			case 'update':
				$range = self::validity( $in );
				if ( is_wp_error( $range ) ) { return $range; }
				$r = Memberships::update_validity( $id, $range[0], $range[1] );
				return is_wp_error( $r ) ? $r : array( 'pass_id' => $id );
			case 'save_rule':
				$rule_id = Forms::int( $in, 'rule_id' );
				$rule = $rule_id ? array( 'uses_total' => Forms::int( $in, 'uses_total' ), 'uses_per_day' => Forms::int( $in, 'uses_per_day', 1 ) ) : self::rule_from( $in );
				if ( is_wp_error( $rule ) ) { return $rule; }
				$r = Memberships::save_rule( $id, $rule_id, $rule );
				return is_wp_error( $r ) ? $r : array( 'pass_id' => $id, 'rule_id' => $r );
			case 'revoke':
				$pass = Memberships::get( $id );
				if ( 'active' !== $pass->status ) { return Forms::error( 'wpcap_pass_not_active', __( 'Der Pass ist nicht mehr aktiv.', 'pass-captain' ), 409 ); }
				$r = Memberships::revoke( $id );
				return is_wp_error( $r ) ? $r : array( 'pass_id' => $id );
		}
		return Forms::error( 'wpcap_pass_action', __( 'Unbekannte Aktion.', 'pass-captain' ), 404 );
	}

	public static function handle_action() {
		Forms::verify( self::ACTION );
		$in = Forms::post();
		$result = self::admin_action( $in );
		$do = sanitize_key( $in['do'] ?? '' );
		$pass_id = is_array( $result ) && ! empty( $result['pass_id'] ) ? (int) $result['pass_id'] : Forms::int( $in, 'id' );
		$messages = array( 'issue' => __( 'Pass ausgestellt. Der Passcode wurde per E-Mail an das Mitglied gesendet.', 'pass-captain' ), 'update' => __( 'Gültigkeit gespeichert.', 'pass-captain' ), 'save_rule' => __( 'Passregel gespeichert.', 'pass-captain' ), 'revoke' => __( 'Pass widerrufen.', 'pass-captain' ) );
		Forms::finish( $result, $messages[ $do ] ?? '', Forms::page_url( self::SLUG, $pass_id ? array( 'pass_id' => $pass_id ) : array() ), Forms::page_url( self::SLUG, $pass_id ? array( 'pass_id' => $pass_id ) : array() ) );
	}

	/** Passes with at least one rule in the user's scope (administrators: all), optional e-mail search. */
	public static function passes( $search = '' ) {
		global $wpdb;
		$scope = Queries::event_ids_for( self::CAP );
		$where = '';
		if ( null !== $scope ) {
			$where .= $scope ? ' AND p.pass_id IN (SELECT r.pass_id FROM ' . $wpdb->prefix . 'wpcap_pass_rules r WHERE r.event_id IN (' . implode( ',', array_map( 'absint', $scope ) ) . '))' : ' AND 1=0';
		}
		if ( '' !== $search ) { $where .= $wpdb->prepare( ' AND p.member_email LIKE %s', '%' . Queries::like( $search ) . '%' ); }
		return $wpdb->get_results( "SELECT p.pass_id,p.public_id,p.member_email,p.valid_from,p.valid_until,p.status,p.created_at,(SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_pass_rules r2 WHERE r2.pass_id=p.pass_id) AS rule_count,(SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_pass_uses u WHERE u.pass_id=p.pass_id) AS use_count FROM {$wpdb->prefix}wpcap_member_passes p WHERE 1=1{$where} ORDER BY p.created_at DESC,p.pass_id DESC LIMIT 300" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- scope from absint ids, search prepared.
	}

	public static function state_label( $pass ) {
		$now = \PassCaptain\Clock::mysql();
		if ( 'active' !== $pass->status ) { return Forms::status_label( $pass->status, 'order' ); }
		if ( $pass->valid_until < $now ) { return __( 'Abgelaufen', 'pass-captain' ); }
		if ( $pass->valid_from > $now ) { return __( 'Noch nicht gültig', 'pass-captain' ); }
		return __( 'Aktiv', 'pass-captain' );
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) && ! current_user_can( Memberships::APPROVE_CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		echo '<div class="wrap wpcap-admin"><h1>' . esc_html__( 'Mitgliedspässe', 'pass-captain' ) . '</h1>';
		Forms::render_notices();
		self::render_requests();
		if ( ! current_user_can( self::CAP ) ) { echo '</div>'; return; }
		$pass_id = absint( Forms::query_arg( 'pass_id', 0 ) );
		if ( $pass_id ) {
			self::render_pass( $pass_id );
			echo '</div>';
			return;
		}
		$tz = FeatureScreens::site_timezone();
		$search = Forms::query_arg( 'q', '' );
		echo '<p>' . esc_html__( 'Mitglieds- und Saisonpässe berechtigen innerhalb ihrer Laufzeit zum Einlösen von Tickets nach den Passregeln (Event, Ticketart, Kontingent gesamt und pro Tag).', 'pass-captain' ) . '</p>';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><label for="wpcap-mp-search">' . esc_html__( 'E-Mail suchen', 'pass-captain' ) . '</label> <input type="search" id="wpcap-mp-search" name="q" value="' . esc_attr( $search ) . '"> <button type="submit" class="button">' . esc_html__( 'Suchen', 'pass-captain' ) . '</button></form>';
		$passes = self::passes( $search );
		if ( ! $passes ) {
			echo '<p>' . esc_html__( 'Keine Pässe gefunden.', 'pass-captain' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr>';
			foreach ( array( __( 'Pass', 'pass-captain' ), __( 'Mitglied', 'pass-captain' ), __( 'Gültig ab', 'pass-captain' ), __( 'Gültig bis', 'pass-captain' ), __( 'Regeln', 'pass-captain' ), __( 'Einlösungen', 'pass-captain' ), __( 'Status', 'pass-captain' ) ) as $head ) {
				echo '<th scope="col">' . esc_html( $head ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $passes as $p ) {
				echo '<tr><td><a href="' . esc_url( Forms::page_url( self::SLUG, array( 'pass_id' => (int) $p->pass_id ) ) ) . '">#' . (int) $p->pass_id . '</a></td><td>' . esc_html( $p->member_email ) . '</td><td>' . esc_html( Forms::datetime( $p->valid_from, $tz, false ) ) . '</td><td>' . esc_html( Forms::datetime( $p->valid_until, $tz, false ) ) . '</td><td>' . (int) $p->rule_count . '</td><td>' . (int) $p->use_count . '</td><td>' . esc_html( self::state_label( $p ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		self::render_issue_form( $tz );
		echo '</div>';
	}

	/** Event + ticket type controls of a rule form. */
	private static function render_rule_scope_rows( $prefix ) {
		$scope = Queries::event_ids_for( self::CAP );
		$events = ( current_user_can( 'manage_options' ) ? array( '0' => __( 'Alle Events', 'pass-captain' ) ) : array() ) + Queries::event_options( $scope );
		$types = FeatureScreens::ticket_type_options( $scope );
		FeatureScreens::row( $prefix . '-event', __( 'Event', 'pass-captain' ), static function () use ( $events, $prefix ) { Forms::select( 'event_id', $events, '', $prefix . '-event' ); } );
		FeatureScreens::row( $prefix . '-type', __( 'Ticketart (optional)', 'pass-captain' ), static function () use ( $types, $prefix ) { Forms::select( 'ticket_type_id', array( '0' => __( 'Alle Ticketarten', 'pass-captain' ) ) + $types, '', $prefix . '-type' ); } );
		FeatureScreens::row( $prefix . '-scope', __( 'Geltungsbereich', 'pass-captain' ), static function () use ( $prefix ) { Forms::select( 'scope', array( 'event' => __( 'Eventeinlass (Ticketregeln)', 'pass-captain' ), 'service' => __( 'Service-Ressourcen (Termine/Verleih)', 'pass-captain' ), 'any' => __( 'Beides', 'pass-captain' ) ), 'event', $prefix . '-scope', 'aria-describedby="' . esc_attr( $prefix ) . '-scope-desc"' ); }, __( 'Service-Pässe gelten nicht für den Eventeinlass und umgekehrt – außer bei „Beides“.', 'pass-captain' ) );
		$resources = array( '0' => __( 'Alle Ressourcen', 'pass-captain' ) );
		foreach ( \PassCaptain\ServiceResources::resources( $scope, 0, \PassCaptain\ServiceResources::user_partner_ids() ) as $resource ) { $resources[ (string) (int) $resource->resource_id ] = $resource->event_name . ' – ' . $resource->name; }
		FeatureScreens::row( $prefix . '-resource', __( 'Ressource (nur Service)', 'pass-captain' ), static function () use ( $resources, $prefix ) { Forms::select( 'resource_id', $resources, '', $prefix . '-resource' ); } );
		FeatureScreens::row( $prefix . '-slot', __( 'Terminbindung (nur Service)', 'pass-captain' ), static function () use ( $prefix ) { Forms::select( 'slot_mode', array( 'slot_free' => __( 'Terminfrei (direkt an der Ressource)', 'pass-captain' ), 'slot_bound' => __( 'Terminpflichtig (bucht ein Zeitfenster)', 'pass-captain' ) ), 'slot_free', $prefix . '-slot' ); } );
		FeatureScreens::input_row( $prefix . '-expires', 'expires_at', __( 'Regel gültig bis (optional)', 'pass-captain' ), '', 'datetime-local', '', __( 'Leer = bis zum Ende der Passlaufzeit.', 'pass-captain' ) );
		FeatureScreens::input_row( $prefix . '-total', 'uses_total', __( 'Einlösungen gesamt', 'pass-captain' ), '0', 'number', 'min="0" max="65535"', __( '0 = unbegrenzt, 1 = Einmalnutzung.', 'pass-captain' ) );
		FeatureScreens::input_row( $prefix . '-day', 'uses_per_day', __( 'Einlösungen pro Tag (0 = unbegrenzt)', 'pass-captain' ), '1', 'number', 'min="0" max="65535"' );
	}

	private static function render_issue_form( $tz ) {
		echo '<h2>' . esc_html__( 'Neuen Pass ausstellen', 'pass-captain' ) . '</h2>';
		Forms::open_form( self::ACTION );
		Forms::hidden( 'do', 'issue' );
		echo '<table class="form-table"><tbody>';
		FeatureScreens::input_row( 'wpcap-mp-email', 'email', __( 'E-Mail des Mitglieds', 'pass-captain' ), '', 'email', 'class="regular-text" required', __( 'Der Passcode wird an diese Adresse gesendet und nicht gespeichert.', 'pass-captain' ) );
		/* translators: %s: timezone */
		FeatureScreens::input_row( 'wpcap-mp-from', 'valid_from', __( 'Gültig ab', 'pass-captain' ), '', 'datetime-local', 'required', sprintf( __( 'Zeitzone: %s', 'pass-captain' ), $tz ) );
		FeatureScreens::input_row( 'wpcap-mp-until', 'valid_until', __( 'Gültig bis', 'pass-captain' ), '', 'datetime-local', 'required' );
		self::render_rule_scope_rows( 'wpcap-mp-rule' );
		echo '</tbody></table>';
		submit_button( __( 'Pass ausstellen', 'pass-captain' ) );
		echo '</form>';
	}

	private static function render_pass( $pass_id ) {
		$tz = FeatureScreens::site_timezone();
		echo '<p><a href="' . esc_url( Forms::page_url( self::SLUG ) ) . '">&larr; ' . esc_html__( 'Alle Pässe', 'pass-captain' ) . '</a></p>';
		$pass = Memberships::get( $pass_id );
		if ( ! $pass || ! self::can_manage_pass( $pass_id ) ) { echo '<p>' . esc_html__( 'Pass nicht gefunden.', 'pass-captain' ) . '</p>'; return; }
		echo '<h2>' . esc_html( sprintf( /* translators: 1: pass id, 2: e-mail */ __( 'Pass #%1$d – %2$s', 'pass-captain' ), (int) $pass->pass_id, $pass->member_email ) ) . '</h2><p>' . esc_html( sprintf( /* translators: %s: status */ __( 'Status: %s', 'pass-captain' ), self::state_label( $pass ) ) ) . '</p>';
		$active = 'active' === $pass->status;
		if ( $active ) {
			echo '<h3>' . esc_html__( 'Gültigkeit', 'pass-captain' ) . '</h3>';
			Forms::open_form( self::ACTION );
			Forms::hidden( 'do', 'update' );
			Forms::hidden( 'id', (int) $pass->pass_id );
			echo '<table class="form-table"><tbody>';
			/* translators: %s: timezone */
			FeatureScreens::input_row( 'wpcap-mp-edit-from', 'valid_from', __( 'Gültig ab', 'pass-captain' ), Forms::utc_to_local( $pass->valid_from, $tz ), 'datetime-local', 'required', sprintf( __( 'Zeitzone: %s', 'pass-captain' ), $tz ) );
			FeatureScreens::input_row( 'wpcap-mp-edit-until', 'valid_until', __( 'Gültig bis', 'pass-captain' ), Forms::utc_to_local( $pass->valid_until, $tz ), 'datetime-local', 'required' );
			echo '</tbody></table>';
			submit_button( __( 'Gültigkeit speichern', 'pass-captain' ), 'secondary' );
			echo '</form>';
		}
		$types = FeatureScreens::ticket_type_options( null );
		$events = Queries::event_options( null );
		echo '<h3>' . esc_html__( 'Passregeln', 'pass-captain' ) . '</h3><table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Event', 'pass-captain' ), __( 'Ticketart', 'pass-captain' ), __( 'Eingelöst', 'pass-captain' ), __( 'Einlösungen gesamt', 'pass-captain' ), __( 'Einlösungen pro Tag', 'pass-captain' ) ) as $head ) {
			echo '<th scope="col">' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( Memberships::rules( $pass->pass_id ) as $rule ) {
			$rid = (int) $rule->rule_id;
			$form = 'wpcap-mp-rule-form-' . $rid;
			$scope_label = 'event' === (string) ( $rule->scope ?? 'event' ) ? ( (int) $rule->event_id ? ( $events[ (int) $rule->event_id ] ?? '#' . (int) $rule->event_id ) : __( 'Alle Events', 'pass-captain' ) ) : self::service_rule_label( $rule );
			echo '<tr><td>' . esc_html( $scope_label ) . '</td><td>' . esc_html( (int) $rule->ticket_type_id ? ( $types[ (int) $rule->ticket_type_id ] ?? '#' . (int) $rule->ticket_type_id ) : __( 'Alle Ticketarten', 'pass-captain' ) ) . '</td><td>' . (int) $rule->used . '</td>';
			if ( $active ) {
				echo '<td><label class="screen-reader-text" for="wpcap-mp-r' . esc_attr( (string) $rid ) . '-total">' . esc_html__( 'Einlösungen gesamt', 'pass-captain' ) . '</label><input form="' . esc_attr( $form ) . '" type="number" id="wpcap-mp-r' . esc_attr( (string) $rid ) . '-total" name="uses_total" min="0" max="65535" value="' . (int) $rule->uses_total . '" style="width:6em"></td><td><label class="screen-reader-text" for="wpcap-mp-r' . esc_attr( (string) $rid ) . '-day">' . esc_html__( 'Einlösungen pro Tag', 'pass-captain' ) . '</label><input form="' . esc_attr( $form ) . '" type="number" id="wpcap-mp-r' . esc_attr( (string) $rid ) . '-day" name="uses_per_day" min="0" max="65535" value="' . (int) $rule->uses_per_day . '" style="width:6em"> ';
				Forms::open_form( self::ACTION, null, 'id="' . esc_attr( $form ) . '" style="display:inline"' );
				Forms::hidden( 'do', 'save_rule' );
				Forms::hidden( 'id', (int) $pass->pass_id );
				Forms::hidden( 'rule_id', $rid );
				echo '<button type="submit" class="button button-small">' . esc_html__( 'Speichern', 'pass-captain' ) . '</button></form></td>';
			} else {
				echo '<td>' . ( (int) $rule->uses_total ? (int) $rule->uses_total : '∞' ) . '</td><td>' . ( (int) $rule->uses_per_day ? (int) $rule->uses_per_day : '∞' ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		if ( ! $active ) { return; }
		echo '<h3>' . esc_html__( 'Regel hinzufügen', 'pass-captain' ) . '</h3>';
		Forms::open_form( self::ACTION );
		Forms::hidden( 'do', 'save_rule' );
		Forms::hidden( 'id', (int) $pass->pass_id );
		echo '<table class="form-table"><tbody>';
		self::render_rule_scope_rows( 'wpcap-mp-add' );
		echo '</tbody></table>';
		submit_button( __( 'Regel hinzufügen', 'pass-captain' ), 'secondary' );
		echo '</form>';
		echo '<h3>' . esc_html__( 'Pass widerrufen', 'pass-captain' ) . '</h3><p>' . esc_html__( 'Ein widerrufener Pass kann nicht mehr eingelöst werden. Das lässt sich nicht rückgängig machen.', 'pass-captain' ) . '</p>';
		FeatureScreens::button( self::ACTION, array( 'do' => 'revoke', 'id' => (int) $pass->pass_id ), __( 'Pass widerrufen', 'pass-captain' ), __( 'Diesen Pass endgültig widerrufen?', 'pass-captain' ), 'button button-link-delete' );
	}
}

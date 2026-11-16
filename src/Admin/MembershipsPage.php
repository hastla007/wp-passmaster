<?php
/**
 * Admin page "Mitgliedspässe" (A481): lists issued and requested membership passes (scope of the
 * current user), approves or rejects requests (wpcap_approve_comps, never the requester) and revokes
 * passes. Passes are requested via REST (POST /member-passes); see docs/PASSES.md.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Memberships;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MembershipsPage {
	const SLUG = 'wpcap-memberships';
	const CAP = 'wpcap_manage_orders';

	public static function url( array $args = array() ) {
		return Forms::page_url( self::SLUG, $args );
	}

	/** Approve, reject or revoke a pass (admin form). */
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

	public static function handle_decide() {
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
		/* translators: 1: scope (event/ticket type), 2: uses per day, 3: total uses (0 = unlimited) */
		return sprintf( __( '%1$s: %2$d/Tag, gesamt %3$s', 'pass-captain' ), $scope, (int) $rule->uses_per_day, (int) $rule->uses_total ? (string) (int) $rule->uses_total : '∞' );
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) && ! current_user_can( Memberships::APPROVE_CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list filter only.
		$passes = Memberships::list_passes( $status );
		echo '<div class="wrap wpcap-admin"><h1>' . esc_html__( 'Mitgliedspässe', 'pass-captain' ) . '</h1>';
		Forms::render_notices();
		echo '<p>' . esc_html__( 'Ein Mitgliedspass ist freier Eintritt. Anträge des Teams gibt eine zweite Person mit dem Recht für Freikarten frei; erst dann wird der Passcode erzeugt und per E-Mail versendet.', 'pass-captain' ) . '</p>';
		echo '<ul class="subsubsub">';
		foreach ( array( '' => __( 'Alle', 'pass-captain' ), 'pending' => __( 'Beantragt', 'pass-captain' ), 'active' => __( 'Aktiv', 'pass-captain' ), 'revoked' => __( 'Widerrufen', 'pass-captain' ) ) as $key => $label ) {
			echo '<li><a href="' . esc_url( self::url( '' === $key ? array() : array( 'status' => $key ) ) ) . '"' . ( $key === $status ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a> </li>';
		}
		echo '</ul><table class="widefat striped"><thead><tr><th>' . esc_html__( 'E-Mail', 'pass-captain' ) . '</th><th>' . esc_html__( 'Status', 'pass-captain' ) . '</th><th>' . esc_html__( 'Gültig', 'pass-captain' ) . '</th><th>' . esc_html__( 'Regeln', 'pass-captain' ) . '</th><th>' . esc_html__( 'Einlösungen', 'pass-captain' ) . '</th><th>' . esc_html__( 'Beantragt von', 'pass-captain' ) . '</th><th>' . esc_html__( 'Freigegeben von', 'pass-captain' ) . '</th><th>' . esc_html__( 'Aktionen', 'pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $passes as $pass ) {
			echo '<tr><td>' . esc_html( $pass->member_email ) . '</td><td>' . esc_html( self::status_label( $pass->status ) ) . '</td><td>' . esc_html( Forms::datetime( $pass->valid_from, wp_timezone_string(), false ) . ' – ' . Forms::datetime( $pass->valid_until, wp_timezone_string(), false ) ) . '</td><td>' . esc_html( implode( '; ', array_map( array( self::class, 'rule_label' ), $pass->rules ) ) ) . '</td><td>' . esc_html( (string) (int) $pass->uses ) . '</td><td>' . esc_html( self::user_label( $pass->requested_by ?? 0 ) ) . '</td><td>' . esc_html( self::user_label( $pass->approved_by ?? 0 ) ) . '</td><td>';
			$actions = array();
			if ( 'pending' === $pass->status && Memberships::can_decide( $pass ) ) { $actions['approve'] = __( 'Freigeben', 'pass-captain' ); }
			if ( 'pending' === $pass->status && current_user_can( Memberships::APPROVE_CAP ) ) { $actions['reject'] = __( 'Ablehnen', 'pass-captain' ); }
			if ( in_array( $pass->status, array( 'active', 'pending' ), true ) ) { $actions['revoke'] = __( 'Widerrufen', 'pass-captain' ); }
			if ( 'pending' === $pass->status && Memberships::self_approval( $pass ) ) { echo '<p class="description">' . esc_html__( 'Selbst beantragt – Freigabe durch eine andere Person.', 'pass-captain' ) . '</p>'; }
			foreach ( $actions as $do => $label ) {
				Forms::open_form( 'wpcap_member_pass', 'wpcap_member_pass_' . (int) $pass->pass_id, 'style="display:inline"' );
				Forms::hidden( 'pass_id', (int) $pass->pass_id );
				Forms::hidden( 'do', $do );
				echo '<button type="submit" class="button' . ( 'approve' === $do ? ' button-primary' : '' ) . '">' . esc_html( $label ) . '</button></form> ';
			}
			echo '</td></tr>';
		}
		if ( ! $passes ) { echo '<tr><td colspan="8">' . esc_html__( 'Keine Mitgliedspässe.', 'pass-captain' ) . '</td></tr>'; }
		echo '</tbody></table></div>';
	}
}

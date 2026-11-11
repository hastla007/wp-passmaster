<?php
/**
 * Admin screens "Partner" (partners, linked events/users, API keys; administrators) and
 * "Partner-Abrechnungen" (settlements; finance manages all, linked partner users read their own
 * finalized settlements).
 */
namespace PassCaptain\Partners;

use PassCaptain\Admin\Forms;
use PassCaptain\Admin\Queries;
use PassCaptain\Reports\Scope;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class AdminPage {
	const CAP = 'manage_options';
	const PAGE = 'wpcap-partners';
	const SETTLEMENTS_PAGE = 'wpcap-partner-settlements';
	const ACTION = 'wpcap_partner_action';
	const DOC_ACTION = 'wpcap_partner_settlement_doc';

	/** Finance/admins manage settlements; linked partner users only need to be logged in. */
	public static function settlements_menu_cap() {
		if ( Settlements::can_manage() ) { return current_user_can( 'manage_options' ) ? 'manage_options' : Settlements::MANAGE_CAP; }
		return Repository::partners_of_user( get_current_user_id() ) ? 'read' : Settlements::MANAGE_CAP;
	}

	private static function new_key_transient() {
		return 'wpcap_partner_new_key_' . get_current_user_id();
	}

	/* ------------------------------------------------------------------ Actions */

	/**
	 * Executes an admin action (testable without a request).
	 *
	 * @return array|\WP_Error
	 */
	public static function admin_action( array $in ) {
		$do = sanitize_key( $in['do'] ?? '' );
		$partner_actions = array( 'save_partner', 'issue_key', 'revoke_key', 'settings' );
		if ( in_array( $do, $partner_actions, true ) && ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		if ( ! in_array( $do, $partner_actions, true ) && ! Settlements::can_manage() ) { return Forms::forbidden(); }
		$id = absint( $in['id'] ?? 0 );
		switch ( $do ) {
			case 'save_partner':
				$commission = Forms::parse_percent_bp( $in['commission'] ?? '' );
				$fee_tax = Forms::parse_percent_bp( $in['fee_tax_rate'] ?? '' );
				$currency = strtoupper( Forms::text( $in, 'currency', 3 ) ?: 'EUR' );
				$fee = Forms::parse_money( '' === trim( (string) ( $in['fee_per_ticket'] ?? '' ) ) ? '0' : $in['fee_per_ticket'], Forms::currency_decimals( $currency ) );
				if ( null === $commission || null === $fee_tax || null === $fee ) {
					return Forms::error( 'wpcap_partner_input', __( 'Bitte gültige Prozentwerte und eine gültige Ticketgebühr angeben.', 'wp-pass-captain' ) );
				}
				$data = array(
					'name' => Forms::text( $in, 'name' ),
					'contact_name' => Forms::text( $in, 'contact_name' ),
					'contact_email' => Forms::text( $in, 'contact_email' ),
					'contact_phone' => Forms::text( $in, 'contact_phone', 64 ),
					'billing_address' => Forms::textarea( $in, 'billing_address', 2000 ),
					'vat_id' => Forms::text( $in, 'vat_id', 64 ),
					'payout_details' => Forms::textarea( $in, 'payout_details', 2000 ),
					'currency' => $currency,
					'commission_bp' => $commission,
					'fee_per_ticket_minor' => $fee,
					'fee_tax_rate_bp' => $fee_tax,
					'refund_policy' => Forms::choice( $in, 'refund_policy', Repository::REFUND_POLICIES, 'reverse_all' ),
					'settlement_start' => Forms::text( $in, 'settlement_start', 10 ),
					'status' => Forms::choice( $in, 'status', Repository::STATUSES, 'active' ),
					'notes' => Forms::textarea( $in, 'notes' ),
				);
				$partner_id = Repository::save( $data, $id );
				if ( is_wp_error( $partner_id ) ) { return $partner_id; }
				$events = Repository::set_events( $partner_id, Forms::ids( $in, 'event_ids' ) );
				Repository::set_users( $partner_id, Repository::resolve_users( $in['users'] ?? '' ) );
				$result = array( 'partner_id' => $partner_id );
				if ( ! is_wp_error( $events ) && $events['skipped'] ) {
					/* translators: %s: event ids */
					$result['warning'] = sprintf( __( 'Folgende Events gehören bereits einem anderen Partner und wurden nicht verknüpft: %s', 'wp-pass-captain' ), implode( ', ', $events['skipped'] ) );
				}
				return $result;
			case 'issue_key':
				$expires = '';
				if ( '' !== trim( (string) ( $in['expires_at'] ?? '' ) ) ) {
					$expires = Forms::local_to_utc( $in['expires_at'], Scope::site_timezone() );
					if ( null === $expires ) { return Forms::error( 'wpcap_partner_key_expiry', __( 'Ungültiges Ablaufdatum.', 'wp-pass-captain' ) ); }
				}
				$scopes = array_map( 'strval', array_filter( (array) ( $in['scopes'] ?? array() ), 'is_scalar' ) );
				$result = Keys::issue( $id, $scopes, array( 'label' => Forms::text( $in, 'label' ), 'event_ids' => Forms::ids( $in, 'event_ids' ), 'ip_allowlist' => Forms::textarea( $in, 'ip_allowlist', 2000 ), 'expires_at' => $expires, 'rate_limit' => Forms::int( $in, 'rate_limit' ) ) );
				if ( ! is_wp_error( $result ) ) { set_transient( self::new_key_transient(), $result['key'], 5 * MINUTE_IN_SECONDS ); }
				return $result;
			case 'revoke_key':
				return Keys::revoke( $id );
			case 'settings':
				$prefix = preg_replace( '/[^A-Za-z0-9_-]/', '', Forms::text( $in, 'settlement_prefix', 10 ) );
				$values = array( 'settlement_prefix' => '' !== $prefix ? $prefix : 'PA', 'rate_limit' => max( 1, min( 100000, Forms::int( $in, 'rate_limit', 120 ) ) ) );
				Settings::update( array( 'partners' => $values ) );
				return $values;
			case 'create_settlement':
				$r = Settlements::create_draft( absint( $in['partner_id'] ?? 0 ), Forms::text( $in, 'period', 7 ) );
				return is_wp_error( $r ) ? $r : array( 'settlement_id' => $r );
			case 'recompute':
				$r = Settlements::recompute( $id );
				return is_wp_error( $r ) ? $r : array( 'settlement_id' => $id );
			case 'finalize':
				$r = Settlements::finalize( $id );
				return is_wp_error( $r ) ? $r : array( 'settlement_id' => $id, 'number' => $r->document_number );
			case 'mark_paid':
				$r = Settlements::mark_paid( $id, Forms::text( $in, 'reference' ) );
				return is_wp_error( $r ) ? $r : array( 'settlement_id' => $id );
			case 'delete_draft':
				return Settlements::delete_draft( $id );
		}
		return Forms::error( 'wpcap_partner_action', __( 'Unbekannte Aktion.', 'wp-pass-captain' ), 404 );
	}

	public static function handle_action() {
		Forms::verify( self::ACTION );
		$in = Forms::post();
		$result = self::admin_action( $in );
		$do = sanitize_key( $in['do'] ?? '' );
		if ( in_array( $do, array( 'save_partner', 'issue_key', 'revoke_key' ), true ) ) {
			$partner_id = is_array( $result ) && ! empty( $result['partner_id'] ) ? (int) $result['partner_id'] : absint( $in['partner_id'] ?? $in['id'] ?? 0 );
			if ( 'issue_key' === $do ) { $partner_id = absint( $in['id'] ?? 0 ); }
			$url = Forms::page_url( self::PAGE, $partner_id ? array( 'partner_id' => $partner_id ) : array() );
		} elseif ( 'settings' === $do ) {
			$url = Forms::page_url( self::PAGE );
		} else {
			$url = Forms::page_url( self::SETTLEMENTS_PAGE );
		}
		Forms::finish( $result, __( 'Gespeichert.', 'wp-pass-captain' ), $url );
	}

	/** Print view (HTML) or CSV of a settlement. */
	public static function handle_document() {
		check_admin_referer( self::DOC_ACTION );
		$s = Settlements::get( absint( $_GET['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked above.
		if ( ! Settlements::can_view( $s ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 ); }
		$format = sanitize_key( $_GET['format'] ?? 'html' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$name = 'abrechnung-' . ( $s->document_number ?: 'entwurf-' . $s->period );
		if ( 'csv' === $format ) {
			Scope::send_download( Scope::csv( Settlements::csv_rows( $s ) ), $name . '.csv', 'text/csv; charset=utf-8' );
		}
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		echo Settlements::render_html( $s ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while building.
		exit;
	}

	public static function document_url( $settlement_id, $format ) {
		return wp_nonce_url( add_query_arg( array( 'action' => self::DOC_ACTION, 'id' => (int) $settlement_id, 'format' => $format ), admin_url( 'admin-post.php' ) ), self::DOC_ACTION );
	}

	private static function button( $do, array $fields, $label, $confirm = '', $extra_html = '' ) {
		Forms::open_form( self::ACTION, null, 'style="display:inline"' );
		Forms::hidden( 'do', $do );
		foreach ( $fields as $k => $v ) { Forms::hidden( $k, $v ); }
		echo $extra_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal markup from this class.
		echo '<button type="submit" class="button button-small"' . ( '' !== $confirm ? ' onclick="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ')"' : '' ) . '>' . esc_html( $label ) . '</button></form> ';
	}

	/* ------------------------------------------------------------------ Partner screen */

	public static function render_partners() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 ); }
		$partner_id = absint( Forms::query_arg( 'partner_id', 0 ) );
		$is_new = 'new' === Forms::query_arg( 'partner_id', '' );
		echo '<div class="wrap wpcap-admin"><h1>' . esc_html__( 'Partner', 'wp-pass-captain' ) . '</h1>';
		Forms::render_notices();
		if ( $partner_id || $is_new ) {
			$partner = $partner_id ? Repository::get( $partner_id ) : null;
			if ( $partner_id && ! $partner ) { echo '<p>' . esc_html__( 'Partner nicht gefunden.', 'wp-pass-captain' ) . '</p></div>'; return; }
			echo '<p><a href="' . esc_url( Forms::page_url( self::PAGE ) ) . '">&larr; ' . esc_html__( 'Alle Partner', 'wp-pass-captain' ) . '</a></p>';
			self::render_partner_form( $partner );
			if ( $partner ) { self::render_keys( $partner ); }
			echo '</div>';
			return;
		}
		echo '<p>' . esc_html__( 'Partner sind Veranstalter oder Wiederverkäufer, denen Events zugeordnet werden. Sie erhalten monatliche Abrechnungen und optional API-Keys mit Lesezugriff auf ihre Events.', 'wp-pass-captain' ) . ' <a class="button" href="' . esc_url( Forms::page_url( self::PAGE, array( 'partner_id' => 'new' ) ) ) . '">' . esc_html__( 'Neuer Partner', 'wp-pass-captain' ) . '</a></p>';
		$partners = Repository::all();
		if ( ! $partners ) {
			echo '<p>' . esc_html__( 'Noch keine Partner.', 'wp-pass-captain' ) . '</p>';
		} else {
			$policies = Repository::refund_policy_labels();
			echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Name', 'wp-pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Kontakt', 'wp-pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Konditionen', 'wp-pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Events', 'wp-pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Status', 'wp-pass-captain' ) . '</th></tr></thead><tbody>';
			foreach ( $partners as $pa ) {
				$terms = sprintf( /* translators: 1: percent, 2: fee */ __( '%1$s %% Provision, %2$s je Ticket', 'wp-pass-captain' ), Forms::percent_input( $pa->commission_bp ), Forms::money( $pa->fee_per_ticket_minor, $pa->currency ) );
				echo '<tr><td><a href="' . esc_url( Forms::page_url( self::PAGE, array( 'partner_id' => (int) $pa->partner_id ) ) ) . '"><strong>' . esc_html( $pa->name ) . '</strong></a></td><td>' . esc_html( trim( $pa->contact_name . ' ' . $pa->contact_email ) ) . '</td><td>' . esc_html( $terms ) . '<br><small>' . esc_html( $policies[ $pa->refund_policy ] ?? $pa->refund_policy ) . '</small></td><td>' . count( Repository::event_ids( $pa->partner_id ) ) . '</td><td>' . esc_html( 'active' === $pa->status ? __( 'aktiv', 'wp-pass-captain' ) : __( 'inaktiv', 'wp-pass-captain' ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		$settings = (array) Settings::get( 'partners' );
		echo '<h2>' . esc_html__( 'Einstellungen', 'wp-pass-captain' ) . '</h2>';
		Forms::open_form( self::ACTION );
		Forms::hidden( 'do', 'settings' );
		echo '<table class="form-table"><tbody><tr><th scope="row"><label for="wpcap-pa-prefix">' . esc_html__( 'Präfix der Abrechnungsnummern', 'wp-pass-captain' ) . '</label></th><td><input id="wpcap-pa-prefix" name="settlement_prefix" maxlength="10" value="' . esc_attr( (string) ( $settings['settlement_prefix'] ?? 'PA' ) ) . '"><p class="description">' . esc_html__( 'Fortlaufend und lückenlos je Jahr, z. B. PA-2026-000001.', 'wp-pass-captain' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="wpcap-pa-rate">' . esc_html__( 'Standard-Ratenlimit (Anfragen pro Minute und Key)', 'wp-pass-captain' ) . '</label></th><td><input type="number" id="wpcap-pa-rate" name="rate_limit" min="1" max="100000" value="' . (int) ( $settings['rate_limit'] ?? 120 ) . '"></td></tr></tbody></table>';
		submit_button( __( 'Einstellungen speichern', 'wp-pass-captain' ), 'secondary' );
		echo '</form></div>';
	}

	private static function render_partner_form( $partner ) {
		$v = static function ( $key, $default = '' ) use ( $partner ) { return $partner ? (string) $partner->$key : $default; };
		echo '<h2>' . esc_html( $partner ? $partner->name : __( 'Neuer Partner', 'wp-pass-captain' ) ) . '</h2>';
		Forms::open_form( self::ACTION );
		Forms::hidden( 'do', 'save_partner' );
		Forms::hidden( 'id', $partner ? (int) $partner->partner_id : 0 );
		$field = static function ( $name, $label, $value, $type = 'text', $attrs = '' ) {
			echo '<tr><th scope="row"><label for="wpcap-pa-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><input type="' . esc_attr( $type ) . '" id="wpcap-pa-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" class="regular-text" value="' . esc_attr( $value ) . '" ' . $attrs . '></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $attrs literal.
		};
		$area = static function ( $name, $label, $value ) {
			echo '<tr><th scope="row"><label for="wpcap-pa-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><textarea id="wpcap-pa-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" rows="3" class="large-text">' . esc_html( $value ) . '</textarea></td></tr>';
		};
		echo '<h3>' . esc_html__( 'Stammdaten', 'wp-pass-captain' ) . '</h3><table class="form-table"><tbody>';
		$field( 'name', __( 'Name der Organisation', 'wp-pass-captain' ), $v( 'name' ), 'text', 'required maxlength="190"' );
		$field( 'contact_name', __( 'Ansprechperson', 'wp-pass-captain' ), $v( 'contact_name' ) );
		$field( 'contact_email', __( 'Kontakt-E-Mail', 'wp-pass-captain' ), $v( 'contact_email' ), 'email' );
		$field( 'contact_phone', __( 'Telefon', 'wp-pass-captain' ), $v( 'contact_phone' ) );
		$area( 'billing_address', __( 'Rechnungsanschrift', 'wp-pass-captain' ), $v( 'billing_address' ) );
		$field( 'vat_id', __( 'USt-IdNr.', 'wp-pass-captain' ), $v( 'vat_id' ) );
		$area( 'payout_details', __( 'Auszahlung (z. B. IBAN, BIC, Kontoinhaber)', 'wp-pass-captain' ), $v( 'payout_details' ) );
		echo '<tr><th scope="row"><label for="wpcap-pa-status">' . esc_html__( 'Status', 'wp-pass-captain' ) . '</label></th><td>';
		Forms::select( 'status', array( 'active' => __( 'aktiv', 'wp-pass-captain' ), 'inactive' => __( 'inaktiv (API-Keys gesperrt)', 'wp-pass-captain' ) ), $v( 'status', 'active' ), 'wpcap-pa-status' );
		echo '</td></tr></tbody></table>';
		echo '<h3>' . esc_html__( 'Provisionsmodell', 'wp-pass-captain' ) . '</h3><table class="form-table"><tbody>';
		$field( 'currency', __( 'Währung', 'wp-pass-captain' ), $v( 'currency', 'EUR' ), 'text', 'maxlength="3" pattern="[A-Za-z]{3}" style="width:5em"' );
		$field( 'commission', __( 'Provision in % vom Netto-Ticketumsatz', 'wp-pass-captain' ), $partner ? Forms::percent_input( $partner->commission_bp ) : '0', 'text', 'inputmode="decimal" style="width:7em"' );
		$field( 'fee_per_ticket', __( 'Feste Gebühr pro Ticket (netto)', 'wp-pass-captain' ), $partner ? Forms::money_input( $partner->fee_per_ticket_minor ) : '0,00', 'text', 'inputmode="decimal" style="width:7em"' );
		$field( 'fee_tax_rate', __( 'USt-Satz auf die Plattformgebühr in %', 'wp-pass-captain' ), $partner ? Forms::percent_input( $partner->fee_tax_rate_bp ) : '19', 'text', 'inputmode="decimal" style="width:7em"' );
		echo '<tr><th scope="row"><label for="wpcap-pa-refund">' . esc_html__( 'Bei Erstattungen', 'wp-pass-captain' ) . '</label></th><td>';
		Forms::select( 'refund_policy', Repository::refund_policy_labels(), $v( 'refund_policy', 'reverse_all' ), 'wpcap-pa-refund' );
		echo '</td></tr>';
		$field( 'settlement_start', __( 'Abrechnung ab (Bestelldatum)', 'wp-pass-captain' ), $partner && $partner->settlement_start ? substr( $partner->settlement_start, 0, 10 ) : gmdate( 'Y-m-01' ), 'date' );
		$area( 'notes', __( 'Interne Notizen', 'wp-pass-captain' ), $v( 'notes' ) );
		echo '</tbody></table>';
		echo '<h3>' . esc_html__( 'Events und Zugänge', 'wp-pass-captain' ) . '</h3><table class="form-table"><tbody><tr><th scope="row">' . esc_html__( 'Events dieses Partners', 'wp-pass-captain' ) . '</th><td><fieldset style="max-height:16rem;overflow:auto"><legend class="screen-reader-text">' . esc_html__( 'Events', 'wp-pass-captain' ) . '</legend>';
		$linked = $partner ? Repository::event_ids( $partner->partner_id ) : array();
		foreach ( Queries::event_options( null ) as $event_id => $name ) {
			$owner = Repository::partner_of_event( $event_id );
			$foreign = $owner && ( ! $partner || $owner !== (int) $partner->partner_id );
			echo '<label style="display:block"><input type="checkbox" name="event_ids[]" value="' . (int) $event_id . '"' . checked( in_array( (int) $event_id, $linked, true ), true, false ) . disabled( $foreign, true, false ) . '> ' . esc_html( $name ) . ( $foreign ? ' <em>(' . esc_html__( 'anderer Partner', 'wp-pass-captain' ) . ')</em>' : '' ) . '</label>';
		}
		echo '</fieldset></td></tr>';
		$users = $partner ? implode( ', ', Repository::user_ids( $partner->partner_id ) ) : '';
		echo '<tr><th scope="row"><label for="wpcap-pa-users">' . esc_html__( 'WordPress-Benutzer des Partners', 'wp-pass-captain' ) . '</label></th><td><input id="wpcap-pa-users" name="users" class="regular-text" value="' . esc_attr( $users ) . '"><p class="description">' . esc_html__( 'Benutzer-IDs, Logins oder E-Mail-Adressen (kommagetrennt). Diese Benutzer sehen die abgeschlossenen Abrechnungen des Partners (nur lesend), z. B. mit der Rolle „Pass Captain Partner“.', 'wp-pass-captain' ) . '</p></td></tr></tbody></table>';
		submit_button( __( 'Partner speichern', 'wp-pass-captain' ) );
		echo '</form>';
	}

	private static function render_keys( $partner ) {
		$new_key = get_transient( self::new_key_transient() );
		echo '<h2>' . esc_html__( 'API-Keys', 'wp-pass-captain' ) . '</h2>';
		if ( $new_key ) {
			delete_transient( self::new_key_transient() );
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Neuer API-Key – wird nur jetzt einmal angezeigt:', 'wp-pass-captain' ) . '</strong><br><code style="user-select:all">' . esc_html( $new_key ) . '</code></p><p>' . esc_html__( 'Bitte sicher an den Partner übermitteln. Gespeichert wird nur ein Hash.', 'wp-pass-captain' ) . '</p></div>';
		}
		$keys = Keys::for_partner( $partner->partner_id );
		if ( $keys ) {
			echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Key', 'wp-pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Scopes', 'wp-pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Einschränkungen', 'wp-pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Zuletzt genutzt', 'wp-pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Status', 'wp-pass-captain' ) . '</th><th scope="col"></th></tr></thead><tbody>';
			$tz = Scope::site_timezone();
			foreach ( $keys as $k ) {
				$events = array_map( 'absint', (array) json_decode( (string) $k->event_ids, true ) );
				$limits = array();
				$limits[] = $events ? __( 'Events:', 'wp-pass-captain' ) . ' ' . implode( ', ', $events ) : __( 'alle Partner-Events', 'wp-pass-captain' );
				if ( $k->ip_allowlist ) { $limits[] = __( 'IP:', 'wp-pass-captain' ) . ' ' . str_replace( "\n", ', ', $k->ip_allowlist ); }
				if ( $k->expires_at ) { $limits[] = __( 'gültig bis', 'wp-pass-captain' ) . ' ' . Forms::datetime( $k->expires_at, $tz, false ); }
				if ( (int) $k->rate_limit ) { $limits[] = sprintf( /* translators: %d: limit */ __( '%d Anfragen/Min.', 'wp-pass-captain' ), (int) $k->rate_limit ); }
				$expired = $k->expires_at && $k->expires_at <= current_time( 'mysql', true );
				$status = $k->revoked_at ? __( 'widerrufen', 'wp-pass-captain' ) : ( $expired ? __( 'abgelaufen', 'wp-pass-captain' ) : __( 'aktiv', 'wp-pass-captain' ) );
				echo '<tr><td><code>' . esc_html( Keys::TOKEN_PREFIX . $k->public_prefix . '_…' ) . '</code>' . ( '' !== $k->label ? '<br>' . esc_html( $k->label ) : '' ) . '</td><td>' . esc_html( str_replace( ',', ', ', $k->scopes ) ) . '</td><td>' . esc_html( implode( ' · ', $limits ) ) . '</td><td>' . esc_html( Forms::datetime( $k->last_used_at, $tz, false ) ) . '</td><td>' . esc_html( $status ) . '</td><td>';
				if ( ! $k->revoked_at ) { self::button( 'revoke_key', array( 'id' => (int) $k->key_id, 'partner_id' => (int) $partner->partner_id ), __( 'Widerrufen', 'wp-pass-captain' ), __( 'Key sofort widerrufen?', 'wp-pass-captain' ) ); }
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '<h3>' . esc_html__( 'Neuer API-Key', 'wp-pass-captain' ) . '</h3>';
		Forms::open_form( self::ACTION );
		Forms::hidden( 'do', 'issue_key' );
		Forms::hidden( 'id', (int) $partner->partner_id );
		echo '<table class="form-table"><tbody><tr><th scope="row"><label for="wpcap-pk-label">' . esc_html__( 'Bezeichnung', 'wp-pass-captain' ) . '</label></th><td><input id="wpcap-pk-label" name="label" class="regular-text" maxlength="190"></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Scopes', 'wp-pass-captain' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Scopes', 'wp-pass-captain' ) . '</legend>';
		foreach ( Keys::scope_labels() as $scope => $label ) {
			echo '<label style="display:block"><input type="checkbox" name="scopes[]" value="' . esc_attr( $scope ) . '"' . checked( 'events:read' === $scope, true, false ) . '> <code>' . esc_html( $scope ) . '</code> – ' . esc_html( $label ) . '</label>';
		}
		echo '</fieldset></td></tr><tr><th scope="row">' . esc_html__( 'Auf Events beschränken', 'wp-pass-captain' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Events', 'wp-pass-captain' ) . '</legend>';
		$names = Queries::event_options( Repository::event_ids( $partner->partner_id ) );
		foreach ( $names as $event_id => $name ) {
			echo '<label style="display:block"><input type="checkbox" name="event_ids[]" value="' . (int) $event_id . '"> ' . esc_html( $name ) . '</label>';
		}
		echo '<p class="description">' . esc_html__( 'Keine Auswahl = alle aktuellen und künftigen Events des Partners. Event-beschränkte Keys können keine Abrechnungen abrufen.', 'wp-pass-captain' ) . '</p></fieldset></td></tr>';
		echo '<tr><th scope="row"><label for="wpcap-pk-ip">' . esc_html__( 'IP-Allowlist (optional)', 'wp-pass-captain' ) . '</label></th><td><textarea id="wpcap-pk-ip" name="ip_allowlist" rows="2" class="regular-text" placeholder="203.0.113.10&#10;198.51.100.0/24"></textarea></td></tr>';
		echo '<tr><th scope="row"><label for="wpcap-pk-exp">' . esc_html__( 'Gültig bis (optional)', 'wp-pass-captain' ) . '</label></th><td><input type="datetime-local" id="wpcap-pk-exp" name="expires_at"></td></tr>';
		echo '<tr><th scope="row"><label for="wpcap-pk-rate">' . esc_html__( 'Ratenlimit pro Minute (0 = Standard)', 'wp-pass-captain' ) . '</label></th><td><input type="number" id="wpcap-pk-rate" name="rate_limit" min="0" max="100000" value="0"></td></tr></tbody></table>';
		submit_button( __( 'API-Key erzeugen', 'wp-pass-captain' ), 'secondary' );
		echo '</form>';
	}

	/* ------------------------------------------------------------------ Settlement screen */

	public static function render_settlements() {
		$manage = Settlements::can_manage();
		if ( ! $manage && ! Repository::partners_of_user( get_current_user_id() ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 ); }
		echo '<div class="wrap wpcap-admin"><h1>' . esc_html__( 'Partner-Abrechnungen', 'wp-pass-captain' ) . '</h1>';
		Forms::render_notices();
		if ( $manage ) {
			$partners = array();
			foreach ( Repository::all() as $pa ) { $partners[ (int) $pa->partner_id ] = $pa->name; }
			if ( $partners ) {
				Forms::open_form( self::ACTION );
				Forms::hidden( 'do', 'create_settlement' );
				echo '<p><label for="wpcap-st-partner">' . esc_html__( 'Partner', 'wp-pass-captain' ) . '</label> ';
				Forms::select( 'partner_id', $partners, 0, 'wpcap-st-partner' );
				echo ' <label for="wpcap-st-period">' . esc_html__( 'Monat', 'wp-pass-captain' ) . '</label> <input type="month" id="wpcap-st-period" name="period" required value="' . esc_attr( gmdate( 'Y-m', strtotime( 'first day of last month' ) ) ) . '"> ';
				submit_button( __( 'Entwurf erstellen', 'wp-pass-captain' ), 'secondary', 'submit', false );
				echo '</p></form>';
				echo '<p class="description">' . esc_html__( 'Entwürfe werden beim Abschließen neu berechnet. Abgeschlossene Abrechnungen sind unveränderlich; spätere Erstattungen oder Nachzahlungen erscheinen in der nächsten Periode als Korrektur.', 'wp-pass-captain' ) . '</p>';
			} else {
				echo '<p>' . esc_html__( 'Bitte zuerst einen Partner anlegen.', 'wp-pass-captain' ) . '</p>';
			}
		}
		$list = Settlements::visible();
		if ( ! $list ) {
			echo '<p>' . esc_html__( 'Noch keine Abrechnungen.', 'wp-pass-captain' ) . '</p></div>';
			return;
		}
		$labels = Settlements::status_labels();
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Nummer', 'wp-pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Partner', 'wp-pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Monat', 'wp-pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Status', 'wp-pass-captain' ) . '</th><th scope="col" class="num">' . esc_html__( 'Brutto', 'wp-pass-captain' ) . '</th><th scope="col" class="num">' . esc_html__( 'Plattformgebühr', 'wp-pass-captain' ) . '</th><th scope="col" class="num">' . esc_html__( 'Auszahlung', 'wp-pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Aktionen', 'wp-pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $list as $s ) {
			echo '<tr><td>' . esc_html( $s->document_number ?: '—' ) . '</td><td>' . esc_html( $s->partner_name ) . '</td><td>' . esc_html( $s->period ) . '</td><td>' . esc_html( $labels[ $s->status ] ?? $s->status ) . ( 'paid' === $s->status && '' !== $s->payment_reference ? '<br><small>' . esc_html( $s->payment_reference ) . '</small>' : '' ) . '</td>';
			echo '<td class="num">' . esc_html( Forms::money( $s->gross_minor, $s->currency ) ) . '</td><td class="num">' . esc_html( Forms::money( $s->platform_fee_gross_minor, $s->currency ) ) . '</td><td class="num">' . esc_html( ( (int) $s->payout_minor < 0 ? '-' : '' ) . Forms::money( abs( (int) $s->payout_minor ), $s->currency ) ) . '</td><td>';
			echo '<a class="button button-small" target="_blank" rel="noopener" href="' . esc_url( self::document_url( $s->settlement_id, 'html' ) ) . '">' . esc_html__( 'Ansehen/Drucken', 'wp-pass-captain' ) . '</a> <a class="button button-small" href="' . esc_url( self::document_url( $s->settlement_id, 'csv' ) ) . '">CSV</a> ';
			if ( $manage && 'draft' === $s->status ) {
				self::button( 'recompute', array( 'id' => (int) $s->settlement_id ), __( 'Neu berechnen', 'wp-pass-captain' ) );
				self::button( 'finalize', array( 'id' => (int) $s->settlement_id ), __( 'Abschließen', 'wp-pass-captain' ), __( 'Abrechnung abschließen? Danach ist sie unveränderlich und erhält eine Belegnummer.', 'wp-pass-captain' ) );
				self::button( 'delete_draft', array( 'id' => (int) $s->settlement_id ), __( 'Löschen', 'wp-pass-captain' ), __( 'Entwurf löschen?', 'wp-pass-captain' ) );
			} elseif ( $manage && 'finalized' === $s->status ) {
				self::button( 'mark_paid', array( 'id' => (int) $s->settlement_id ), __( 'Als ausgezahlt markieren', 'wp-pass-captain' ), '', '<input type="text" name="reference" placeholder="' . esc_attr__( 'Zahlungsreferenz', 'wp-pass-captain' ) . '" maxlength="190" style="width:10em"> ' );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}

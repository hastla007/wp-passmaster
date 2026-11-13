<?php
/**
 * Admin screens "Partner" (partners, linked events/users, API keys, API sales: role, price list,
 * contingents, reseller statement; administrators) and
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
	const STATEMENT_ACTION = 'wpcap_partner_statement';

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
		$partner_actions = array( 'save_partner', 'issue_key', 'revoke_key', 'settings', 'save_price', 'delete_price', 'save_contingent', 'delete_contingent' );
		if ( in_array( $do, $partner_actions, true ) && ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		if ( ! in_array( $do, $partner_actions, true ) && ! Settlements::can_manage() ) { return Forms::forbidden(); }
		$id = absint( $in['id'] ?? 0 );
		switch ( $do ) {
			case 'save_partner':
				$commission = Forms::parse_percent_bp( $in['commission'] ?? '' );
				$fee_tax = Forms::parse_percent_bp( $in['fee_tax_rate'] ?? '' );
				$currency = strtoupper( Forms::text( $in, 'currency', 3 ) ?: 'EUR' );
				$fee = Forms::parse_money( '' === trim( (string) ( $in['fee_per_ticket'] ?? '' ) ) ? '0' : $in['fee_per_ticket'], Forms::currency_decimals( $currency ) );
				$reseller_commission = Forms::parse_percent_bp( '' === trim( (string) ( $in['reseller_commission'] ?? '' ) ) ? '0' : $in['reseller_commission'] );
				$reseller_fee = Forms::parse_money( '' === trim( (string) ( $in['reseller_fee'] ?? '' ) ) ? '0' : $in['reseller_fee'], Forms::currency_decimals( $currency ) );
				if ( null === $commission || null === $fee_tax || null === $fee || null === $reseller_commission || null === $reseller_fee ) {
					return Forms::error( 'wpcap_partner_input', __( 'Bitte gültige Prozentwerte und eine gültige Ticketgebühr angeben.', 'pass-captain' ) );
				}
				$data = array(
					'name' => Forms::text( $in, 'name' ),
					'contact_name' => Forms::text( $in, 'contact_name' ),
					'contact_email' => Forms::text( $in, 'contact_email' ),
					'contact_phone' => Forms::text( $in, 'contact_phone', 64 ),
					'billing_address' => Forms::textarea( $in, 'billing_address', 2000 ),
					'vat_id' => Forms::text( $in, 'vat_id', 64 ),
					'payout_details' => self::payout_input( $in, $id ),
					'currency' => $currency,
					'commission_bp' => $commission,
					'fee_per_ticket_minor' => $fee,
					'fee_tax_rate_bp' => $fee_tax,
					'refund_policy' => Forms::choice( $in, 'refund_policy', Repository::REFUND_POLICIES, 'reverse_all' ),
					'settlement_start' => Forms::text( $in, 'settlement_start', 10 ),
					'status' => Forms::choice( $in, 'status', Repository::STATUSES, 'active' ),
					'notes' => Forms::textarea( $in, 'notes' ),
					'sales_model' => Forms::choice( $in, 'sales_model', SalesModel::MODELS, SalesModel::OWN_NAME ),
					'legal_name' => Forms::text( $in, 'legal_name' ),
					'country' => Forms::text( $in, 'country', 2 ),
					'tax_number' => Forms::text( $in, 'tax_number', 64 ),
					'vat_id_checked_at' => Forms::text( $in, 'vat_id_checked_at', 10 ),
					'invoice_prefix' => Forms::text( $in, 'invoice_prefix', 10 ),
					'small_business' => Forms::flag( $in, 'small_business' ) ? 1 : 0,
					'small_business_note' => Forms::text( $in, 'small_business_note', 255 ),
					'role' => Forms::choice( $in, 'role', Repository::ROLES, Repository::ROLE_ORGANIZER ),
					'sales_channel' => Forms::text( $in, 'sales_channel', 64 ),
					'hold_minutes' => Forms::int( $in, 'hold_minutes', 15 ),
					'cancel_window_hours' => Forms::int( $in, 'cancel_window_hours', 0 ),
					'anonymous_buyer' => Forms::flag( $in, 'anonymous_buyer' ) ? 1 : 0,
					'reseller_commission_bp' => $reseller_commission,
					'reseller_fee_minor' => $reseller_fee,
				);
				$partner_id = Repository::save( $data, $id );
				if ( is_wp_error( $partner_id ) ) { return $partner_id; }
				// A447: only explicit changes – events that are not on the form are never unlinked.
				$events = self::apply_event_changes( $partner_id, $in );
				Repository::set_users( $partner_id, Repository::resolve_users( $in['users'] ?? '' ) );
				$result = array( 'partner_id' => $partner_id );
				// Missing seller data is shown inline on the form (render_sales_model_fields), not a second time here.
				$warnings = array();
				if ( is_wp_error( $events ) ) { return $events; }
				if ( $events['skipped'] ) {
					/* translators: %s: event ids */
					$warnings[] = sprintf( __( 'Folgende Events gehören bereits einem anderen Veranstalter und wurden nicht verknüpft: %s', 'pass-captain' ), implode( ', ', $events['skipped'] ) );
				}
				if ( $warnings ) { $result['warning'] = implode( ' ', $warnings ); }
				return $result;
			case 'issue_key':
				$expires = '';
				if ( '' !== trim( (string) ( $in['expires_at'] ?? '' ) ) ) {
					$expires = Forms::local_to_utc( $in['expires_at'], Scope::site_timezone() );
					if ( null === $expires ) { return Forms::error( 'wpcap_partner_key_expiry', __( 'Ungültiges Ablaufdatum.', 'pass-captain' ) ); }
				}
				$scopes = array_map( 'strval', array_filter( (array) ( $in['scopes'] ?? array() ), 'is_scalar' ) );
				$types = is_array( $in['ticket_type_ids'] ?? null ) ? Forms::ids( $in, 'ticket_type_ids' ) : array_values( array_filter( array_map( 'absint', preg_split( '/[\s,;]+/', Forms::text( $in, 'ticket_type_ids', 2000 ), -1, PREG_SPLIT_NO_EMPTY ) ) ) );
				$key_events = array_values( array_unique( array_filter( array_merge( Forms::ids( $in, 'event_ids' ), array_map( 'absint', preg_split( '/[\s,;]+/', Forms::text( $in, 'event_ids_text', 2000 ), -1, PREG_SPLIT_NO_EMPTY ) ) ) ) ) );
				$result = Keys::issue( $id, $scopes, array( 'label' => Forms::text( $in, 'label' ), 'event_ids' => $key_events, 'ticket_type_ids' => $types, 'ip_allowlist' => Forms::textarea( $in, 'ip_allowlist', 2000 ), 'expires_at' => $expires, 'rate_limit' => Forms::int( $in, 'rate_limit' ), 'write_rate_limit' => Forms::int( $in, 'write_rate_limit' ) ) );
				if ( ! is_wp_error( $result ) ) { set_transient( self::new_key_transient(), $result['key'], 5 * MINUTE_IN_SECONDS ); }
				return $result;
			case 'revoke_key':
				return Keys::revoke( $id );
			case 'settings':
				$prefix = preg_replace( '/[^A-Za-z0-9_-]/', '', Forms::text( $in, 'settlement_prefix', 10 ) );
				$self_billing = preg_replace( '/[^A-Za-z0-9_-]/', '', Forms::text( $in, 'self_billing_prefix', 10 ) );
				$values = array( 'settlement_prefix' => '' !== $prefix ? $prefix : 'PA', 'self_billing_prefix' => '' !== $self_billing ? $self_billing : 'GU', 'rate_limit' => max( 1, min( 100000, Forms::int( $in, 'rate_limit', 120 ) ) ), 'write_rate_limit' => max( 1, min( 100000, Forms::int( $in, 'write_rate_limit', 30 ) ) ) );
				Settings::update( array( 'partners' => $values, 'partner_negative_balance' => Forms::choice( $in, 'partner_negative_balance', array( 'carry', 'invoice' ), 'carry' ) ) );
				return $values;
			case 'save_price':
				$raw = trim( (string) ( is_scalar( $in['price'] ?? '' ) ? $in['price'] : '' ) );
				$price = '' === $raw ? null : Forms::parse_money( $raw, 2 );
				if ( '' !== $raw && null === $price ) { return Forms::error( 'wpcap_partner_price', __( 'Bitte einen gültigen Preis angeben (leer = Standardpreis).', 'pass-captain' ) ); }
				$r = Sales::set_price( absint( $in['partner_id'] ?? 0 ), Forms::int( $in, 'ticket_type_id' ), $price, 'net' === Forms::choice( $in, 'price_basis', array( 'gross', 'net' ), 'gross' ) );
				return is_wp_error( $r ) ? $r : array( 'partner_id' => absint( $in['partner_id'] ?? 0 ) );
			case 'delete_price':
				Sales::delete_price( absint( $in['partner_id'] ?? 0 ), Forms::int( $in, 'ticket_type_id' ) );
				return array( 'partner_id' => absint( $in['partner_id'] ?? 0 ) );
			case 'save_contingent':
				$r = Sales::save_contingent( absint( $in['partner_id'] ?? 0 ), array( 'occurrence_id' => Forms::int( $in, 'occurrence_id' ), 'ticket_type_id' => Forms::int( $in, 'ticket_type_id' ), 'quantity' => Forms::int( $in, 'quantity' ), 'allocation_id' => Forms::int( $in, 'allocation_id' ) ) );
				return is_wp_error( $r ) ? $r : array( 'partner_id' => absint( $in['partner_id'] ?? 0 ), 'contingent_id' => $r );
			case 'delete_contingent':
				$r = Sales::delete_contingent( $id );
				return is_wp_error( $r ) ? $r : array( 'partner_id' => absint( $in['partner_id'] ?? 0 ) );
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
		return Forms::error( 'wpcap_partner_action', __( 'Unbekannte Aktion.', 'pass-captain' ), 404 );
	}

	/**
	 * Event link changes of the partner form (A447): add_event_ids[] (search results), add_event_ids_text
	 * (IDs, comma separated) and the legacy event_ids[] are linked, remove_event_ids[] are unlinked.
	 * Nothing else changes, whatever part of the events the form showed.
	 *
	 * @return array|\WP_Error Repository::update_events() result.
	 */
	public static function apply_event_changes( $partner_id, array $in ) {
		$text = preg_split( '/[\s,;]+/', Forms::text( $in, 'add_event_ids_text', 2000 ), -1, PREG_SPLIT_NO_EMPTY );
		$add = array_merge( Forms::ids( $in, 'add_event_ids' ), Forms::ids( $in, 'event_ids' ), array_map( 'absint', $text ) );
		return Repository::update_events( $partner_id, $add, Forms::ids( $in, 'remove_event_ids' ) );
	}

	public static function handle_action() {
		Forms::verify( self::ACTION );
		$in = Forms::post();
		$result = self::admin_action( $in );
		$do = sanitize_key( $in['do'] ?? '' );
		if ( in_array( $do, array( 'save_partner', 'issue_key', 'revoke_key', 'save_price', 'delete_price', 'save_contingent', 'delete_contingent' ), true ) ) {
			$partner_id = is_array( $result ) && ! empty( $result['partner_id'] ) ? (int) $result['partner_id'] : absint( $in['partner_id'] ?? $in['id'] ?? 0 );
			if ( 'issue_key' === $do ) { $partner_id = absint( $in['id'] ?? 0 ); }
			$url = Forms::page_url( self::PAGE, $partner_id ? array( 'partner_id' => $partner_id ) : array() );
		} elseif ( 'settings' === $do ) {
			$url = Forms::page_url( self::PAGE );
		} else {
			$url = Forms::page_url( self::SETTLEMENTS_PAGE );
		}
		Forms::finish( $result, __( 'Gespeichert.', 'pass-captain' ), $url );
	}

	/** Print view (HTML) or CSV of a settlement. */
	public static function handle_document() {
		check_admin_referer( self::DOC_ACTION );
		$s = Settlements::get( absint( $_GET['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked above.
		if ( ! Settlements::can_view( $s ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		$format = sanitize_key( $_GET['format'] ?? 'html' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$name = 'abrechnung-' . ( $s->document_number ?: 'entwurf-' . $s->period );
		\PassCaptain\Privacy\AccessLog::export( 'csv' === $format ? 'partner_settlement_csv' : 'partner_settlement_html', 'partner', (int) $s->partner_id, array( 'settlement_id' => (int) $s->settlement_id, 'period' => (string) $s->period ) );
		if ( 'csv' === $format ) {
			Scope::send_download( Scope::csv( Settlements::csv_rows( $s ) ), $name . '.csv', 'text/csv; charset=utf-8' );
		}
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		echo Settlements::render_html( $s ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while building.
		exit;
	}

	/** CSV of the reseller statement ("Wiederverkäufer-Abrechnung") of a partner and month. */
	public static function handle_statement() {
		check_admin_referer( self::STATEMENT_ACTION );
		if ( ! current_user_can( self::CAP ) && ! Settlements::can_manage() ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		$partner = Repository::get( absint( $_GET['partner_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked above.
		$period = sanitize_text_field( wp_unslash( $_GET['period'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $partner || ! Settlements::valid_period( $period ) ) { wp_die( esc_html__( 'Ungültige Anfrage.', 'pass-captain' ), 400 ); }
		\PassCaptain\Privacy\AccessLog::export( 'partner_statement_csv', 'partner', (int) $partner->partner_id, array( 'period' => $period ) );
		Scope::send_download( Scope::csv( Statement::csv_rows( $partner, $period ) ), 'wiederverkaeufer-abrechnung-' . (int) $partner->partner_id . '-' . $period . '.csv', 'text/csv; charset=utf-8' );
	}

	public static function statement_url( $partner_id, $period ) {
		return wp_nonce_url( add_query_arg( array( 'action' => self::STATEMENT_ACTION, 'partner_id' => (int) $partner_id, 'period' => $period ), admin_url( 'admin-post.php' ) ), self::STATEMENT_ACTION );
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
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		$partner_id = absint( Forms::query_arg( 'partner_id', 0 ) );
		$is_new = 'new' === Forms::query_arg( 'partner_id', '' );
		echo '<div class="wrap wpcap-admin"><h1>' . esc_html__( 'Veranstalter', 'pass-captain' ) . '</h1>';
		Forms::render_notices();
		if ( $partner_id || $is_new ) {
			$partner = $partner_id ? Repository::get( $partner_id ) : null;
			if ( $partner_id && ! $partner ) { echo '<p>' . esc_html__( 'Veranstalter nicht gefunden.', 'pass-captain' ) . '</p></div>'; return; }
			echo '<p><a href="' . esc_url( Forms::page_url( self::PAGE ) ) . '">&larr; ' . esc_html__( 'Alle Veranstalter', 'pass-captain' ) . '</a></p>';
			self::render_partner_form( $partner );
			if ( $partner ) {
				\PassCaptain\Privacy\AccessLog::view( 'partner_detail', 'partner', (int) $partner->partner_id );
				self::render_keys( $partner );
				self::render_sales( $partner );
			}
			echo '</div>';
			return;
		}
		echo '<p>' . esc_html__( 'Veranstalter sind Organisatoren oder Wiederverkäufer, denen Events zugeordnet werden. Sie erhalten monatliche Abrechnungen und optional API-Keys mit Lesezugriff auf ihre Events.', 'pass-captain' ) . ' <a class="button" href="' . esc_url( Forms::page_url( self::PAGE, array( 'partner_id' => 'new' ) ) ) . '">' . esc_html__( 'Neuer Veranstalter', 'pass-captain' ) . '</a></p>';
		$partners = Repository::all();
		if ( ! $partners ) {
			echo '<p>' . esc_html__( 'Noch keine Veranstalter.', 'pass-captain' ) . '</p>';
		} else {
			$policies = Repository::refund_policy_labels();
			$event_counts = Repository::event_counts();
			\PassCaptain\Privacy\AccessLog::list_view( 'partner_list', array(), count( $partners ) );
			echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Name', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Kontakt', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Konditionen', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Verkaufsmodell', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Events', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Status', 'pass-captain' ) . '</th></tr></thead><tbody>';
			foreach ( $partners as $pa ) {
				$terms = sprintf( /* translators: 1: percent, 2: fee */ __( '%1$s %% Provision, %2$s je Ticket', 'pass-captain' ), Forms::percent_input( $pa->commission_bp ), Forms::money( $pa->fee_per_ticket_minor, $pa->currency ) );
				echo '<tr><td><a href="' . esc_url( Forms::page_url( self::PAGE, array( 'partner_id' => (int) $pa->partner_id ) ) ) . '"><strong>' . esc_html( $pa->name ) . '</strong></a></td><td>' . esc_html( trim( $pa->contact_name . ' ' . $pa->contact_email ) ) . '</td><td>' . esc_html( $terms ) . '<br><small>' . esc_html( $policies[ $pa->refund_policy ] ?? $pa->refund_policy ) . '</small></td><td>' . esc_html( SalesModel::short_labels()[ SalesModel::model_of( $pa ) ] ) . ( self::seller_warnings( $pa ) ? '<br><small class="wpcap-warning">' . esc_html__( 'Rechnungsangaben unvollständig', 'pass-captain' ) . '</small>' : '' ) . '</td><td>' . (int) ( $event_counts[ (int) $pa->partner_id ] ?? 0 ) . '</td><td>' . esc_html( 'active' === $pa->status ? __( 'aktiv', 'pass-captain' ) : __( 'inaktiv', 'pass-captain' ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		$settings = (array) Settings::get( 'partners' );
		echo '<h2>' . esc_html__( 'Einstellungen', 'pass-captain' ) . '</h2>';
		Forms::open_form( self::ACTION );
		Forms::hidden( 'do', 'settings' );
		echo '<table class="form-table"><tbody><tr><th scope="row"><label for="wpcap-pa-prefix">' . esc_html__( 'Präfix der Abrechnungsnummern', 'pass-captain' ) . '</label></th><td><input id="wpcap-pa-prefix" name="settlement_prefix" maxlength="10" value="' . esc_attr( (string) ( $settings['settlement_prefix'] ?? 'PA' ) ) . '"><p class="description">' . esc_html__( 'Fortlaufend und lückenlos je Jahr, z. B. PA-2026-000001. Gilt für Provisionsrechnungen (Vermittler) und Rechnungen über Plattformgebühren (Eigenhändler).', 'pass-captain' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="wpcap-pa-sb-prefix">' . esc_html__( 'Präfix der Gutschriftsnummern (Eigenhändler)', 'pass-captain' ) . '</label></th><td><input id="wpcap-pa-sb-prefix" name="self_billing_prefix" maxlength="10" value="' . esc_attr( (string) ( $settings['self_billing_prefix'] ?? 'GU' ) ) . '"><p class="description">' . esc_html__( 'Eigener lückenloser Nummernkreis für Gutschriften im Sinne von § 14 Abs. 2 Satz 2 UStG, z. B. GU-2026-000001.', 'pass-captain' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="wpcap-pa-rate">' . esc_html__( 'Standard-Ratenlimit (Anfragen pro Minute und Key)', 'pass-captain' ) . '</label></th><td><input type="number" id="wpcap-pa-rate" name="rate_limit" min="1" max="100000" value="' . (int) ( $settings['rate_limit'] ?? 120 ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="wpcap-pa-wrate">' . esc_html__( 'Standard-Ratenlimit für Verkäufe (schreibende Anfragen pro Minute und Key)', 'pass-captain' ) . '</label></th><td><input type="number" id="wpcap-pa-wrate" name="write_rate_limit" min="1" max="100000" value="' . (int) ( $settings['write_rate_limit'] ?? 30 ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="wpcap-pa-negative">' . esc_html__( 'Negativer Saldo in der Veranstalter-Abrechnung', 'pass-captain' ) . '</label></th><td>';
		Forms::select( 'partner_negative_balance', array( 'carry' => __( 'in die nächste Abrechnung vortragen (Standard)', 'pass-captain' ), 'invoice' => __( 'sofort als Forderung an den Veranstalter ausweisen', 'pass-captain' ) ), Settlements::negative_balance_mode(), 'wpcap-pa-negative' );
		echo '<p class="description">' . esc_html__( 'Selbst vereinnahmte Erlöse aus eigenen API-Verkäufen werden automatisch von der Auszahlung abgezogen (die Gebühren fallen trotzdem an). Ergibt sich ein negativer Betrag, wird nichts ausgezahlt.', 'pass-captain' ) . '</p></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Einstellungen speichern', 'pass-captain' ), 'secondary' );
		echo '</form></div>';
	}

	private static function render_partner_form( $partner ) {
		$v = static function ( $key, $default = '' ) use ( $partner ) { return $partner ? (string) $partner->$key : $default; };
		echo '<h2>' . esc_html( $partner ? $partner->name : __( 'Neuer Veranstalter', 'pass-captain' ) ) . '</h2>';
		Forms::open_form( self::ACTION );
		Forms::hidden( 'do', 'save_partner' );
		Forms::hidden( 'id', $partner ? (int) $partner->partner_id : 0 );
		$field = static function ( $name, $label, $value, $type = 'text', $attrs = '' ) {
			echo '<tr><th scope="row"><label for="wpcap-pa-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><input type="' . esc_attr( $type ) . '" id="wpcap-pa-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" class="regular-text" value="' . esc_attr( $value ) . '" ' . $attrs . '></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $attrs literal.
		};
		$area = static function ( $name, $label, $value ) {
			echo '<tr><th scope="row"><label for="wpcap-pa-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><textarea id="wpcap-pa-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" rows="3" class="large-text">' . esc_html( $value ) . '</textarea></td></tr>';
		};
		echo '<h3>' . esc_html__( 'Stammdaten', 'pass-captain' ) . '</h3><table class="form-table"><tbody>';
		$field( 'name', __( 'Name der Organisation', 'pass-captain' ), $v( 'name' ), 'text', 'required maxlength="190"' );
		$field( 'contact_name', __( 'Ansprechperson', 'pass-captain' ), $v( 'contact_name' ) );
		$field( 'contact_email', __( 'Kontakt-E-Mail', 'pass-captain' ), $v( 'contact_email' ), 'email' );
		$field( 'contact_phone', __( 'Telefon', 'pass-captain' ), $v( 'contact_phone' ) );
		$area( 'billing_address', __( 'Rechnungsanschrift', 'pass-captain' ), $v( 'billing_address' ) );
		$field( 'vat_id', __( 'USt-IdNr.', 'pass-captain' ), $v( 'vat_id' ) );
		// Stored payout details are shown masked only; an empty field keeps them, the checkbox deletes them.
		$stored_payout = $v( 'payout_details' );
		echo '<tr><th scope="row"><label for="wpcap-pa-payout_details">' . esc_html__( 'Auszahlung (z. B. IBAN, BIC, Kontoinhaber)', 'pass-captain' ) . '</label></th><td><textarea id="wpcap-pa-payout_details" name="payout_details" rows="3" class="large-text" autocomplete="off"></textarea>';
		if ( '' !== $stored_payout ) {
			echo '<p class="description">' . esc_html__( 'Gespeichert:', 'pass-captain' ) . ' <code>' . esc_html( Repository::masked_payout( $stored_payout ) ) . '</code> · ' . esc_html__( 'Leer lassen, um die gespeicherten Angaben zu behalten.', 'pass-captain' ) . ' <label><input type="checkbox" name="payout_details_clear" value="1"> ' . esc_html__( 'Auszahlungsdaten löschen', 'pass-captain' ) . '</label></p>';
		}
		echo '</td></tr>';
		echo '<tr><th scope="row"><label for="wpcap-pa-status">' . esc_html__( 'Status', 'pass-captain' ) . '</label></th><td>';
		Forms::select( 'status', array( 'active' => __( 'aktiv', 'pass-captain' ), 'inactive' => __( 'inaktiv (API-Keys gesperrt)', 'pass-captain' ) ), $v( 'status', 'active' ), 'wpcap-pa-status' );
		echo '</td></tr></tbody></table>';
		self::render_sales_model_fields( $partner, $field );
		echo '<h3>' . esc_html__( 'Provisionsmodell', 'pass-captain' ) . '</h3><table class="form-table"><tbody>';
		$field( 'currency', __( 'Währung', 'pass-captain' ), $v( 'currency', 'EUR' ), 'text', 'maxlength="3" pattern="[A-Za-z]{3}" style="width:5em"' );
		$field( 'commission', __( 'Provision in % vom Netto-Ticketumsatz', 'pass-captain' ), $partner ? Forms::percent_input( $partner->commission_bp ) : '0', 'text', 'inputmode="decimal" style="width:7em"' );
		$field( 'fee_per_ticket', __( 'Feste Gebühr pro Ticket (netto)', 'pass-captain' ), $partner ? Forms::money_input( $partner->fee_per_ticket_minor ) : '0,00', 'text', 'inputmode="decimal" style="width:7em"' );
		$field( 'fee_tax_rate', __( 'USt-Satz auf die Plattformgebühr in %', 'pass-captain' ), $partner ? Forms::percent_input( $partner->fee_tax_rate_bp ) : '19', 'text', 'inputmode="decimal" style="width:7em"' );
		echo '<tr><th scope="row"><label for="wpcap-pa-refund">' . esc_html__( 'Bei Erstattungen', 'pass-captain' ) . '</label></th><td>';
		Forms::select( 'refund_policy', Repository::refund_policy_labels(), $v( 'refund_policy', 'reverse_all' ), 'wpcap-pa-refund' );
		echo '</td></tr>';
		$field( 'settlement_start', __( 'Abrechnung ab (Bestelldatum)', 'pass-captain' ), $partner && $partner->settlement_start ? substr( $partner->settlement_start, 0, 10 ) : gmdate( 'Y-m-01' ), 'date' );
		$area( 'notes', __( 'Interne Notizen', 'pass-captain' ), $v( 'notes' ) );
		echo '</tbody></table>';
		self::render_sales_fields( $partner, $field );
		echo '<h3>' . esc_html__( 'Events und Zugänge', 'pass-captain' ) . '</h3><table class="form-table"><tbody>';
		self::render_event_picker( $partner );
		$users = $partner ? implode( ', ', Repository::user_ids( $partner->partner_id ) ) : '';
		echo '<tr><th scope="row"><label for="wpcap-pa-users">' . esc_html__( 'WordPress-Benutzer des Veranstalters', 'pass-captain' ) . '</label></th><td><input id="wpcap-pa-users" name="users" class="regular-text" value="' . esc_attr( $users ) . '"><p class="description">' . esc_html__( 'Benutzer-IDs, Logins oder E-Mail-Adressen (kommagetrennt). Diese Benutzer sehen die abgeschlossenen Abrechnungen des Veranstalters (nur lesend), z. B. mit der Rolle „Pass Captain Veranstalter“.', 'pass-captain' ) . '</p></td></tr></tbody></table>';
		submit_button( __( 'Veranstalter speichern', 'pass-captain' ) );
		echo '</form>';
		// Event search (GET) – its fields sit in the event section above via the form attribute.
		echo '<form id="wpcap-pa-event-search" method="get"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><input type="hidden" name="partner_id" value="' . esc_attr( $partner ? (string) (int) $partner->partner_id : 'new' ) . '"></form>';
		if ( $partner ) { self::render_event_history( $partner ); }
	}

	/** Events shown per page in the partner's event list and per search. */
	const EVENTS_PER_PAGE = 50;

	/**
	 * Event section of the partner form (A447): the linked events page by page with "remove"
	 * checkboxes, a search for events to add and a field for event IDs. The form submits only these
	 * changes (apply_event_changes()), so events on other pages or outside the search stay linked.
	 */
	private static function render_event_picker( $partner ) {
		$partner_id = $partner ? (int) $partner->partner_id : 0;
		echo '<tr><th scope="row">' . esc_html__( 'Events dieses Veranstalters', 'pass-captain' ) . '</th><td>';
		if ( $partner_id ) {
			$page = max( 1, absint( Forms::query_arg( 'events_page', 1 ) ) );
			$linked = Repository::linked_events_page( $partner_id, $page, self::EVENTS_PER_PAGE );
			if ( ! $linked['total'] ) {
				echo '<p>' . esc_html__( 'Noch keine Events verknüpft.', 'pass-captain' ) . '</p>';
			} else {
				/* translators: %d: number of linked events */
				echo '<p>' . esc_html( sprintf( _n( '%d verknüpftes Event. Zum Lösen anhaken und speichern.', '%d verknüpfte Events. Zum Lösen anhaken und speichern.', $linked['total'], 'pass-captain' ), $linked['total'] ) ) . '</p>';
				echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Verknüpfte Events', 'pass-captain' ) . '</legend>';
				foreach ( $linked['rows'] as $row ) {
					echo '<label style="display:block"><input type="checkbox" name="remove_event_ids[]" value="' . (int) $row->event_id . '"> ' . esc_html__( 'lösen:', 'pass-captain' ) . ' ' . esc_html( '' !== (string) $row->name ? $row->name : '#' . (int) $row->event_id ) . ' <small>#' . (int) $row->event_id . '</small></label>';
				}
				echo '</fieldset>';
				$pages = (int) ceil( $linked['total'] / self::EVENTS_PER_PAGE );
				if ( $pages > 1 ) {
					echo '<p class="wpcap-pagination">';
					for ( $i = 1; $i <= $pages; $i++ ) {
						echo $i === $page ? '<strong>' . (int) $i . '</strong> ' : '<a href="' . esc_url( Forms::page_url( self::PAGE, array( 'partner_id' => $partner_id, 'events_page' => $i ) ) ) . '">' . (int) $i . '</a> ';
					}
					echo '</p>';
				}
			}
		}
		echo '</td></tr><tr><th scope="row"><label for="wpcap-pa-event-q">' . esc_html__( 'Events hinzufügen', 'pass-captain' ) . '</label></th><td>';
		$term = Forms::query_arg( 'event_search', '' );
		echo '<p><input type="search" id="wpcap-pa-event-q" name="event_search" form="wpcap-pa-event-search" value="' . esc_attr( $term ) . '" placeholder="' . esc_attr__( 'Eventname oder Event-ID', 'pass-captain' ) . '"> <button type="submit" class="button" form="wpcap-pa-event-search">' . esc_html__( 'Events suchen', 'pass-captain' ) . '</button></p>';
		echo '<p class="description">' . esc_html__( 'Die Suche lädt die Seite neu – nicht gespeicherte Änderungen im Formular gehen dabei verloren.', 'pass-captain' ) . '</p>';
		$found = Repository::search_events( $term, self::EVENTS_PER_PAGE );
		$shown = 0;
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Events hinzufügen', 'pass-captain' ) . '</legend>';
		foreach ( $found['rows'] as $row ) {
			$owner = (int) $row->owner_id;
			if ( $partner_id && $owner === $partner_id ) { continue; }
			++$shown;
			$foreign = $owner > 0;
			echo '<label style="display:block"><input type="checkbox" name="add_event_ids[]" value="' . (int) $row->event_id . '"' . disabled( $foreign, true, false ) . '> ' . esc_html( $row->name ) . ' <small>#' . (int) $row->event_id . '</small>' . ( $foreign ? ' <em>(' . esc_html__( 'anderer Veranstalter', 'pass-captain' ) . ')</em>' : '' ) . '</label>';
		}
		echo '</fieldset>';
		if ( ! $shown ) { echo '<p>' . esc_html__( 'Keine weiteren Events gefunden.', 'pass-captain' ) . '</p>'; }
		if ( $found['more'] ) {
			/* translators: %d: number of search results shown */
			echo '<p class="description wpcap-truncated">' . esc_html( sprintf( __( 'Es werden nur die ersten %d Treffer angezeigt – bitte die Suche eingrenzen oder Event-IDs eingeben.', 'pass-captain' ), self::EVENTS_PER_PAGE ) ) . '</p>';
		}
		echo '<p><label for="wpcap-pa-event-ids">' . esc_html__( 'Event-IDs hinzufügen (kommagetrennt)', 'pass-captain' ) . '</label><br><input id="wpcap-pa-event-ids" name="add_event_ids_text" class="regular-text" inputmode="numeric" placeholder="12, 15"></p>';
		echo '<p class="description">' . esc_html__( 'Ein Event gehört immer nur einem Veranstalter. Zum Umhängen das Event erst beim bisherigen Veranstalter lösen: Verkäufe bis dahin und ihre späteren Erstattungen bleiben beim bisherigen Veranstalter, neue Verkäufe gehen an den neuen.', 'pass-captain' ) . '</p>';
		echo '</td></tr>';
	}

	/** Assignment history of the partner's events (A448). */
	private static function render_event_history( $partner ) {
		$rows = Repository::history_of_partner( (int) $partner->partner_id, 50 );
		if ( ! $rows ) { return; }
		$tz = Scope::site_timezone();
		echo '<h3>' . esc_html__( 'Zuordnungsverlauf der Events', 'pass-captain' ) . '</h3><p class="description">' . esc_html__( 'Verkäufe werden dem Veranstalter zugerechnet, dem das Event beim Bestelldatum zugeordnet war; Erstattungen folgen dem Verkauf.', 'pass-captain' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Event', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Gültig ab', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Gültig bis', 'pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$from = $row->valid_from ? Scope::local( $row->valid_from, $tz, 'd.m.Y H:i' ) : __( 'von Beginn an', 'pass-captain' );
			$to = $row->valid_to ? Scope::local( $row->valid_to, $tz, 'd.m.Y H:i' ) : __( 'aktuell', 'pass-captain' );
			echo '<tr><td>' . esc_html( (string) ( $row->event_name ?? '' ) ) . ' <small>#' . (int) $row->event_id . '</small></td><td>' . esc_html( $from ) . '</td><td>' . esc_html( $to ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** Form section "Verkauf über die API" (role, channel, hold, cancellation window, reseller terms). */
	private static function render_sales_fields( $partner, $field ) {
		$v = static function ( $key, $default = '' ) use ( $partner ) { return $partner && isset( $partner->$key ) ? (string) $partner->$key : $default; };
		echo '<h3>' . esc_html__( 'Verkauf über die API', 'pass-captain' ) . '</h3><table class="form-table"><tbody><tr><th scope="row"><label for="wpcap-pa-role">' . esc_html__( 'Rolle', 'pass-captain' ) . '</label></th><td>';
		Forms::select( 'role', Repository::role_labels(), Repository::role_of( $partner ), 'wpcap-pa-role' );
		echo '<p class="description">' . esc_html__( 'Veranstalter verkaufen Ticketarten ihrer eigenen Events; Wiederverkäufer verkaufen die Ticketarten ihrer Preisliste (auch Events anderer Veranstalter). Die Veranstalter-Abrechnung enthält immer alle Verkäufe der eigenen Events.', 'pass-captain' ) . '</p></td></tr>';
		$field( 'sales_channel', __( 'Name des Verkaufskanals', 'pass-captain' ), $v( 'sales_channel' ), 'text', 'maxlength="64"' );
		$field( 'hold_minutes', __( 'Reservierungsdauer in Minuten (höchstens 60)', 'pass-captain' ), $v( 'hold_minutes', '15' ), 'number', 'min="1" max="60" style="width:6em"' );
		$field( 'cancel_window_hours', __( 'Stornofenster für bezahlte Bestellungen in Stunden (0 = keine Stornierung über die API)', 'pass-captain' ), $v( 'cancel_window_hours', '0' ), 'number', 'min="0" max="8760" style="width:6em"' );
		echo '<tr><th scope="row">' . esc_html__( 'Ohne Käufer-E-Mail', 'pass-captain' ) . '</th><td><label><input type="checkbox" name="anonymous_buyer" value="1"' . checked( '1' === $v( 'anonymous_buyer', '0' ), true, false ) . '> ' . esc_html__( 'Verkäufe ohne Käufer-E-Mail erlauben (Tickets werden nur dem Veranstalter/Wiederverkäufer übergeben; Key braucht tickets:issue)', 'pass-captain' ) . '</label></td></tr>';
		$field( 'reseller_commission', __( 'Wiederverkäufer-Provision in % vom Netto-Umsatz', 'pass-captain' ), $partner ? Forms::percent_input( (int) ( $partner->reseller_commission_bp ?? 0 ) ) : '0', 'text', 'inputmode="decimal" style="width:7em"' );
		$field( 'reseller_fee', __( 'Wiederverkäufer-Gebühr pro Ticket (netto)', 'pass-captain' ), $partner ? Forms::money_input( (int) ( $partner->reseller_fee_minor ?? 0 ) ) : '0,00', 'text', 'inputmode="decimal" style="width:7em"' );
		echo '</tbody></table>';
	}

	/**
	 * Choices for the price list and contingent forms (labels instead of raw IDs): upcoming dates,
	 * their ticket types and the active holds (module "Kontingente").
	 *
	 * @return array{occurrences: array<int,string>, types: array<int,string>, holds: array<int,string>}
	 */
	public static function sales_choices() {
		global $wpdb;
		$p = $wpdb->prefix;
		$out = array( 'occurrences' => array(), 'types' => array(), 'holds' => array() );
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT o.occurrence_id,o.starts_at,o.timezone,e.name,tt.ticket_type_id,tt.name AS type_name FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id LEFT JOIN {$p}wpcap_ticket_types tt ON tt.occurrence_id=o.occurrence_id WHERE o.status='scheduled' AND o.starts_at >= %s ORDER BY o.starts_at,e.name,tt.ticket_type_id LIMIT 1000", $since ) );
		foreach ( (array) $rows as $row ) {
			$occ = $row->name . ' – ' . \PassCaptain\Orders::format_start( $row->starts_at, $row->timezone );
			$out['occurrences'][ (int) $row->occurrence_id ] = $occ . ' (#' . (int) $row->occurrence_id . ')';
			if ( $row->ticket_type_id ) { $out['types'][ (int) $row->ticket_type_id ] = $occ . ' – ' . $row->type_name . ' (#' . (int) $row->ticket_type_id . ')'; }
		}
		if ( \PassCaptain\Privacy::has_table( 'wpcap_alloc_holds' ) ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT h.hold_id,h.name,h.occurrence_id FROM {$p}wpcap_alloc_holds h INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=h.occurrence_id WHERE h.status='active' AND o.starts_at >= %s ORDER BY o.starts_at,h.hold_id LIMIT 500", $since ) ) as $hold ) {
				$out['holds'][ (int) $hold->hold_id ] = ( '' !== (string) $hold->name ? $hold->name : __( 'Kontingent', 'pass-captain' ) ) . ' – ' . ( $out['occurrences'][ (int) $hold->occurrence_id ] ?? '#' . (int) $hold->occurrence_id ) . ' (Hold #' . (int) $hold->hold_id . ')';
			}
		}
		return $out;
	}

	/** Label of an ID from a choice list, else "#ID" (e.g. past dates). */
	private static function choice_label( array $choices, $id ) {
		return $choices[ (int) $id ] ?? '#' . (int) $id;
	}

	/** Price list, contingents and reseller statement of a partner. */
	private static function render_sales( $partner ) {
		$choices = self::sales_choices();
		$pid = (int) $partner->partner_id;
		echo '<h2>' . esc_html__( 'Preisliste und Sortiment', 'pass-captain' ) . '</h2>';
		echo '<p class="description">' . esc_html( Repository::ROLE_RESELLER === Repository::role_of( $partner ) ? __( 'Wiederverkäufer dürfen nur die hier eingetragenen Ticketarten verkaufen. Ohne Preis gilt der Standardpreis der Ticketart.', 'pass-captain' ) : __( 'Veranstalter dürfen alle Ticketarten ihrer Events verkaufen; hier eingetragene Preise ersetzen den Standardpreis.', 'pass-captain' ) ) . '</p>';
		$prices = Sales::prices( $pid );
		if ( $prices ) {
			echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Ticketart', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Preis', 'pass-captain' ) . '</th><th scope="col"></th></tr></thead><tbody>';
			foreach ( $prices as $type_id => $row ) {
				$label = null === $row->price_minor ? __( 'Standardpreis', 'pass-captain' ) : Forms::money_input( (int) $row->price_minor ) . ' ' . ( $row->price_is_net ? __( 'netto', 'pass-captain' ) : __( 'brutto', 'pass-captain' ) );
				echo '<tr><td>' . esc_html( self::choice_label( $choices['types'], $type_id ) ) . '</td><td>' . esc_html( $label ) . '</td><td>';
				self::button( 'delete_price', array( 'partner_id' => $pid, 'ticket_type_id' => (int) $type_id ), __( 'Entfernen', 'pass-captain' ) );
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		Forms::open_form( self::ACTION );
		Forms::hidden( 'do', 'save_price' );
		Forms::hidden( 'partner_id', $pid );
		echo '<p><label>' . esc_html__( 'Ticketart', 'pass-captain' ) . ' ';
		Forms::select( 'ticket_type_id', $choices['types'], '' );
		echo '</label> <label>' . esc_html__( 'Preis (leer = Standardpreis)', 'pass-captain' ) . ' <input type="text" name="price" inputmode="decimal" style="width:7em"></label> ';
		Forms::select( 'price_basis', array( 'gross' => __( 'brutto', 'pass-captain' ), 'net' => __( 'netto (zzgl. USt der Ticketart)', 'pass-captain' ) ), 'gross' );
		echo ' ';
		submit_button( __( 'Hinzufügen/Ändern', 'pass-captain' ), 'secondary', 'submit', false );
		echo '</p></form>';

		echo '<h2>' . esc_html__( 'Kontingente', 'pass-captain' ) . '</h2><p class="description">' . esc_html__( 'Höchstmenge, die dieser Veranstalter/Wiederverkäufer je Termin (optional je Ticketart) über die API verkaufen darf. Optional wird aus einem Kontingent des Moduls „Kontingente“ verkauft (Hold-ID; dessen Quellen leer lassen oder „partner“ eintragen).', 'pass-captain' ) . '</p>';
		$contingents = Sales::contingents( $pid );
		if ( $contingents ) {
			echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Termin', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Ticketart', 'pass-captain' ) . '</th><th scope="col" class="num">' . esc_html__( 'Menge', 'pass-captain' ) . '</th><th scope="col" class="num">' . esc_html__( 'Genutzt', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Hold', 'pass-captain' ) . '</th><th scope="col"></th></tr></thead><tbody>';
			foreach ( $contingents as $c ) {
				echo '<tr><td>' . esc_html( self::choice_label( $choices['occurrences'], $c->occurrence_id ) ) . '</td><td>' . ( (int) $c->ticket_type_id ? esc_html( self::choice_label( $choices['types'], $c->ticket_type_id ) ) : esc_html__( 'alle', 'pass-captain' ) ) . '</td><td class="num">' . ( (int) $c->quantity ? (int) $c->quantity : '—' ) . '</td><td class="num">' . (int) Sales::contingent_used( $c ) . '</td><td>' . ( (int) $c->allocation_id ? esc_html( self::choice_label( $choices['holds'], $c->allocation_id ) ) : '—' ) . '</td><td>';
				self::button( 'delete_contingent', array( 'id' => (int) $c->contingent_id, 'partner_id' => $pid ), __( 'Löschen', 'pass-captain' ), __( 'Kontingent löschen?', 'pass-captain' ) );
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		Forms::open_form( self::ACTION );
		Forms::hidden( 'do', 'save_contingent' );
		Forms::hidden( 'partner_id', $pid );
		echo '<p><label>' . esc_html__( 'Termin', 'pass-captain' ) . ' ';
		Forms::select( 'occurrence_id', $choices['occurrences'], '' );
		echo '</label> <label>' . esc_html__( 'Ticketart', 'pass-captain' ) . ' ';
		Forms::select( 'ticket_type_id', array( 0 => __( 'alle Ticketarten des Termins', 'pass-captain' ) ) + $choices['types'], 0 );
		echo '</label> <label>' . esc_html__( 'Menge (0 = ohne eigene Grenze)', 'pass-captain' ) . ' <input type="number" name="quantity" min="0" value="0" style="width:7em"></label> <label>' . esc_html__( 'Aus Kontingent (optional)', 'pass-captain' ) . ' ';
		Forms::select( 'allocation_id', array( 0 => __( '— keins —', 'pass-captain' ) ) + $choices['holds'], 0 );
		echo '</label> ';
		submit_button( __( 'Speichern', 'pass-captain' ), 'secondary', 'submit', false );
		echo '</p></form>';

		$period = (string) Forms::query_arg( 'statement_period', gmdate( 'Y-m', strtotime( 'first day of last month' ) ) );
		if ( ! Settlements::valid_period( $period ) ) { $period = gmdate( 'Y-m' ); }
		$t = Statement::compute( $partner, $period )['totals'];
		echo '<h2>' . esc_html__( 'Wiederverkäufer-Abrechnung (API-Verkäufe)', 'pass-captain' ) . '</h2>';
		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><input type="hidden" name="partner_id" value="' . (int) $pid . '"><label>' . esc_html__( 'Monat', 'pass-captain' ) . ' <input type="month" name="statement_period" value="' . esc_attr( $period ) . '"></label> ';
		submit_button( __( 'Anzeigen', 'pass-captain' ), 'secondary', '', false );
		echo ' <a class="button" href="' . esc_url( self::statement_url( $pid, $period ) ) . '">CSV</a></form>';
		$rows = array(
			__( 'Verkaufte / erstattete Tickets', 'pass-captain' ) => $t['tickets_sold'] . ' / ' . $t['tickets_refunded'],
			__( 'Vereinnahmt brutto nach Erstattungen', 'pass-captain' ) => Forms::money( $t['gross'], $partner->currency ),
			__( 'Vergütung (Provision + Gebühren) inkl. USt', 'pass-captain' ) => Forms::money( $t['remuneration_gross'], $partner->currency ),
			( ! empty( $t['netted_in_settlement'] ) ? __( 'Wird mit der Veranstalter-Abrechnung verrechnet', 'pass-captain' ) : __( 'An die Plattform abzuführen', 'pass-captain' ) ) => ( $t['due_to_platform'] < 0 ? '-' : '' ) . Forms::money( abs( $t['due_to_platform'] ), $partner->currency ),
		);
		echo '<table class="widefat striped" style="max-width:40rem"><tbody>';
		foreach ( $rows as $label => $value ) { echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td class="num">' . esc_html( $value ) . '</td></tr>'; }
		echo '</tbody></table><p class="description">' . esc_html__( 'Die Veranstalter der verkauften Events erhalten diese Verkäufe in ihrer Veranstalter-Abrechnung. Diese Aufstellung zeigt, was der Verkäufer selbst vereinnahmt hat und an die Plattform abführt.', 'pass-captain' ) . '</p>';
	}

	/** Payout details from the form: empty keeps the stored value (shown masked only), the checkbox clears it. */
	private static function payout_input( array $in, $partner_id ) {
		if ( Forms::flag( $in, 'payout_details_clear' ) ) { return ''; }
		$given = Forms::textarea( $in, 'payout_details', 2000 );
		if ( '' !== trim( $given ) || ! $partner_id ) { return $given; }
		$stored = Repository::get( $partner_id );
		return $stored ? (string) $stored->payout_details : '';
	}

	/** Admin warnings for incomplete seller data (agent invoices / Gutschriften need them). */
	public static function seller_warnings( $partner ) {
		if ( ! $partner ) { return array(); }
		$warnings = array();
		$missing = SalesModel::missing_supplier_fields( $partner );
		if ( $missing ) {
			/* translators: %s: missing fields */
			$warnings[] = sprintf( __( 'Für Rechnungen im Namen des Veranstalters (Vermittler) bzw. Gutschriften (Eigenhändler) fehlen: %s.', 'pass-captain' ), implode( ', ', $missing ) );
		}
		if ( '' !== trim( (string) $partner->vat_id ) && SalesModel::partner_country( $partner ) !== SalesModel::platform_seller()['country'] ) {
			if ( ! SalesModel::vat_id_plausible( $partner->vat_id, SalesModel::partner_country( $partner ) ) ) {
				$warnings[] = __( 'Die USt-IdNr. passt formal nicht zum Land des Veranstalters – Reverse Charge wird nicht angewendet.', 'pass-captain' );
			} elseif ( empty( $partner->vat_id_checked_at ) ) {
				$warnings[] = __( 'Reverse Charge setzt eine gültige USt-IdNr. voraus: bitte qualifiziert (BZSt/VIES) bestätigen und das Prüfdatum eintragen.', 'pass-captain' );
			}
		}
		return $warnings;
	}

	/** Form section "Verkaufsmodell und Rechnungsangaben". */
	private static function render_sales_model_fields( $partner, $field ) {
		$v = static function ( $key, $default = '' ) use ( $partner ) { return $partner && isset( $partner->$key ) ? (string) $partner->$key : $default; };
		echo '<h3>' . esc_html__( 'Verkaufsmodell und Rechnungsangaben', 'pass-captain' ) . '</h3>';
		foreach ( self::seller_warnings( $partner ) as $warning ) { echo '<div class="notice notice-warning inline"><p>' . esc_html( $warning ) . '</p></div>'; }
		echo '<table class="form-table"><tbody><tr><th scope="row"><label for="wpcap-pa-sales-model">' . esc_html__( 'Verkaufsmodell', 'pass-captain' ) . '</label></th><td>';
		Forms::select( 'sales_model', SalesModel::labels(), $v( 'sales_model', SalesModel::OWN_NAME ), 'wpcap-pa-sales-model' );
		echo '<p class="description">' . esc_html__( 'Eigenhändler: Die Plattform verkauft im eigenen Namen und stellt die Kundenrechnungen aus; der Veranstalter erhält monatlich eine Gutschrift und eine Rechnung über die Plattformgebühren. Vermittler: Kundenrechnungen werden im Namen und Nummernkreis des Veranstalters erstellt; die Plattform stellt nur ihre Provision in Rechnung. Ein Wechsel gilt für künftige Rechnungen und noch nicht abgeschlossene Abrechnungen. Siehe docs/SALES-MODELS.md.', 'pass-captain' ) . '</p></td></tr>';
		$field( 'legal_name', __( 'Rechtlicher Name (Firma laut Register)', 'pass-captain' ), $v( 'legal_name' ), 'text', 'maxlength="190"' );
		$field( 'country', __( 'Land (ISO-Code, z. B. DE, AT)', 'pass-captain' ), $v( 'country' ), 'text', 'maxlength="2" pattern="[A-Za-z]{2}" style="width:4em"' );
		$field( 'tax_number', __( 'Steuernummer', 'pass-captain' ), $v( 'tax_number' ), 'text', 'maxlength="64"' );
		$field( 'vat_id_checked_at', __( 'USt-IdNr. qualifiziert bestätigt am', 'pass-captain' ), $v( 'vat_id_checked_at' ), 'date' );
		$field( 'invoice_prefix', __( 'Präfix für Rechnungsnummern des Veranstalters', 'pass-captain' ), $v( 'invoice_prefix' ), 'text', 'maxlength="10" pattern="[A-Za-z0-9]{0,10}" style="width:8em"' );
		echo '<tr><th scope="row">' . esc_html__( 'Kleinunternehmer', 'pass-captain' ) . '</th><td><label><input type="checkbox" name="small_business" value="1"' . checked( '1' === $v( 'small_business', '0' ), true, false ) . '> ' . esc_html__( 'Veranstalter ist Kleinunternehmer (keine Umsatzsteuer)', 'pass-captain' ) . '</label></td></tr>';
		$field( 'small_business_note', __( 'Hinweistext Kleinunternehmer', 'pass-captain' ), $v( 'small_business_note' ), 'text', 'maxlength="255" placeholder="' . esc_attr( SalesModel::default_small_business_note() ) . '"' );
		echo '</tbody></table><p class="description">' . esc_html__( 'Anschrift und USt-IdNr. werden aus den Stammdaten übernommen. Ohne eigenes Präfix wird V{ID} verwendet (z. B. V7-2026-000001); jeder Veranstalter hat einen eigenen lückenlosen Nummernkreis.', 'pass-captain' ) . '</p>';
	}

	private static function render_keys( $partner ) {
		$new_key = get_transient( self::new_key_transient() );
		echo '<h2>' . esc_html__( 'API-Keys', 'pass-captain' ) . '</h2>';
		if ( $new_key ) {
			delete_transient( self::new_key_transient() );
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Neuer API-Key – wird nur jetzt einmal angezeigt:', 'pass-captain' ) . '</strong><br><code style="user-select:all">' . esc_html( $new_key ) . '</code></p><p>' . esc_html__( 'Bitte sicher an den Veranstalter übermitteln. Gespeichert wird nur ein Hash.', 'pass-captain' ) . '</p></div>';
		}
		$keys = Keys::for_partner( $partner->partner_id );
		if ( $keys ) {
			echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Key', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Scopes', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Einschränkungen', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Zuletzt genutzt', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Status', 'pass-captain' ) . '</th><th scope="col"></th></tr></thead><tbody>';
			$tz = Scope::site_timezone();
			foreach ( $keys as $k ) {
				$events = array_map( 'absint', (array) json_decode( (string) $k->event_ids, true ) );
				$limits = array();
				$limits[] = $events ? __( 'Events:', 'pass-captain' ) . ' ' . implode( ', ', $events ) : __( 'alle Veranstalter-Events', 'pass-captain' );
				if ( $k->ip_allowlist ) { $limits[] = __( 'IP:', 'pass-captain' ) . ' ' . str_replace( "\n", ', ', $k->ip_allowlist ); }
				if ( $k->expires_at ) { $limits[] = __( 'gültig bis', 'pass-captain' ) . ' ' . Forms::datetime( $k->expires_at, $tz, false ); }
				if ( (int) $k->rate_limit ) { $limits[] = sprintf( /* translators: %d: limit */ __( '%d Anfragen/Min.', 'pass-captain' ), (int) $k->rate_limit ); }
				$key_types = Keys::restricted_types( $k );
				if ( $key_types ) { $limits[] = __( 'Ticketarten:', 'pass-captain' ) . ' ' . implode( ', ', $key_types ); }
				if ( (int) ( $k->write_rate_limit ?? 0 ) ) { $limits[] = sprintf( /* translators: %d: limit */ __( '%d Verkäufe/Min.', 'pass-captain' ), (int) $k->write_rate_limit ); }
				$expired = $k->expires_at && $k->expires_at <= current_time( 'mysql', true );
				$status = $k->revoked_at ? __( 'widerrufen', 'pass-captain' ) : ( $expired ? __( 'abgelaufen', 'pass-captain' ) : __( 'aktiv', 'pass-captain' ) );
				echo '<tr><td><code>' . esc_html( Keys::TOKEN_PREFIX . $k->public_prefix . '_…' ) . '</code>' . ( '' !== $k->label ? '<br>' . esc_html( $k->label ) : '' ) . '</td><td>' . esc_html( str_replace( ',', ', ', $k->scopes ) ) . '</td><td>' . esc_html( implode( ' · ', $limits ) ) . '</td><td>' . esc_html( Forms::datetime( $k->last_used_at, $tz, false ) ) . '</td><td>' . esc_html( $status ) . '</td><td>';
				if ( ! $k->revoked_at ) { self::button( 'revoke_key', array( 'id' => (int) $k->key_id, 'partner_id' => (int) $partner->partner_id ), __( 'Widerrufen', 'pass-captain' ), __( 'Key sofort widerrufen?', 'pass-captain' ) ); }
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '<h3>' . esc_html__( 'Neuer API-Key', 'pass-captain' ) . '</h3>';
		Forms::open_form( self::ACTION );
		Forms::hidden( 'do', 'issue_key' );
		Forms::hidden( 'id', (int) $partner->partner_id );
		echo '<table class="form-table"><tbody><tr><th scope="row"><label for="wpcap-pk-label">' . esc_html__( 'Bezeichnung', 'pass-captain' ) . '</label></th><td><input id="wpcap-pk-label" name="label" class="regular-text" maxlength="190"></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Scopes', 'pass-captain' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Scopes', 'pass-captain' ) . '</legend>';
		foreach ( Keys::scope_labels() as $scope => $label ) {
			echo '<label style="display:block"><input type="checkbox" name="scopes[]" value="' . esc_attr( $scope ) . '"' . checked( 'events:read' === $scope, true, false ) . '> <code>' . esc_html( $scope ) . '</code> – ' . esc_html( $label ) . ( in_array( $scope, Keys::WRITE_SCOPES, true ) ? ' <em>(' . esc_html__( 'schreibend', 'pass-captain' ) . ')</em>' : '' ) . '</label>';
		}
		echo '</fieldset></td></tr><tr><th scope="row">' . esc_html__( 'Auf Events beschränken', 'pass-captain' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Events', 'pass-captain' ) . '</legend>';
		$names = Queries::event_options( array_values( array_unique( array_merge( Repository::event_ids( $partner->partner_id ), Sales::assortment_event_ids( $partner ) ) ) ) );
		foreach ( $names as $event_id => $name ) {
			echo '<label style="display:block"><input type="checkbox" name="event_ids[]" value="' . (int) $event_id . '"> ' . esc_html( $name ) . '</label>';
		}
		if ( Queries::event_options_truncated() ) {
			/* translators: %d: number of events shown */
			echo '<p class="description wpcap-truncated">' . esc_html( sprintf( __( 'Es werden nur die ersten %d Events angezeigt; weitere Events über ihre ID im Feld darunter angeben.', 'pass-captain' ), Queries::EVENT_OPTIONS_LIMIT ) ) . '</p>';
		}
		echo '<p><label for="wpcap-pk-event-ids">' . esc_html__( 'Weitere Event-IDs (kommagetrennt, optional)', 'pass-captain' ) . '</label><br><input id="wpcap-pk-event-ids" name="event_ids_text" class="regular-text" inputmode="numeric"></p>';
		echo '<p class="description">' . esc_html__( 'Keine Auswahl = alle aktuellen und künftigen Events des Veranstalters. Event-beschränkte Keys können keine Abrechnungen abrufen.', 'pass-captain' ) . '</p></fieldset></td></tr>';
		echo '<tr><th scope="row"><label for="wpcap-pk-ip">' . esc_html__( 'IP-Allowlist (optional)', 'pass-captain' ) . '</label></th><td><textarea id="wpcap-pk-ip" name="ip_allowlist" rows="2" class="regular-text" placeholder="203.0.113.10&#10;198.51.100.0/24"></textarea></td></tr>';
		echo '<tr><th scope="row"><label for="wpcap-pk-exp">' . esc_html__( 'Gültig bis (optional)', 'pass-captain' ) . '</label></th><td><input type="datetime-local" id="wpcap-pk-exp" name="expires_at"></td></tr>';
		echo '<tr><th scope="row"><label for="wpcap-pk-types">' . esc_html__( 'Auf Ticketarten beschränken (nur Verkauf, optional)', 'pass-captain' ) . '</label></th><td><input id="wpcap-pk-types" name="ticket_type_ids" class="regular-text" placeholder="81, 82"><p class="description">' . esc_html__( 'Ticketart-IDs, kommagetrennt. Leer = alle Ticketarten, die der Veranstalter verkaufen darf.', 'pass-captain' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="wpcap-pk-rate">' . esc_html__( 'Ratenlimit pro Minute (0 = Standard)', 'pass-captain' ) . '</label></th><td><input type="number" id="wpcap-pk-rate" name="rate_limit" min="0" max="100000" value="0"></td></tr>';
		echo '<tr><th scope="row"><label for="wpcap-pk-wrate">' . esc_html__( 'Ratenlimit für Verkäufe pro Minute (0 = Standard)', 'pass-captain' ) . '</label></th><td><input type="number" id="wpcap-pk-wrate" name="write_rate_limit" min="0" max="100000" value="0"></td></tr></tbody></table>';
		submit_button( __( 'API-Key erzeugen', 'pass-captain' ), 'secondary' );
		echo '</form>';
	}

	/* ------------------------------------------------------------------ Settlement screen */

	public static function render_settlements() {
		$manage = Settlements::can_manage();
		if ( ! $manage && ! Repository::partners_of_user( get_current_user_id() ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		echo '<div class="wrap wpcap-admin"><h1>' . esc_html__( 'Veranstalter-Abrechnungen', 'pass-captain' ) . '</h1>';
		Forms::render_notices();
		if ( $manage ) {
			$partners = array();
			foreach ( Repository::all() as $pa ) { $partners[ (int) $pa->partner_id ] = $pa->name; }
			if ( $partners ) {
				Forms::open_form( self::ACTION );
				Forms::hidden( 'do', 'create_settlement' );
				echo '<p><label for="wpcap-st-partner">' . esc_html__( 'Veranstalter', 'pass-captain' ) . '</label> ';
				Forms::select( 'partner_id', $partners, 0, 'wpcap-st-partner' );
				echo ' <label for="wpcap-st-period">' . esc_html__( 'Monat', 'pass-captain' ) . '</label> <input type="month" id="wpcap-st-period" name="period" required value="' . esc_attr( gmdate( 'Y-m', strtotime( 'first day of last month' ) ) ) . '"> ';
				submit_button( __( 'Entwurf erstellen', 'pass-captain' ), 'secondary', 'submit', false );
				echo '</p></form>';
				echo '<p class="description">' . esc_html__( 'Entwürfe werden beim Abschließen neu berechnet. Abgeschlossene Abrechnungen sind unveränderlich; spätere Erstattungen oder Nachzahlungen erscheinen in der nächsten Periode als Korrektur.', 'pass-captain' ) . '</p>';
			} else {
				echo '<p>' . esc_html__( 'Bitte zuerst einen Veranstalter anlegen.', 'pass-captain' ) . '</p>';
			}
		}
		$list = Settlements::visible();
		if ( ! $list ) {
			echo '<p>' . esc_html__( 'Noch keine Abrechnungen.', 'pass-captain' ) . '</p></div>';
			return;
		}
		$labels = Settlements::status_labels();
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Nummer', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Veranstalter', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Monat', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Status', 'pass-captain' ) . '</th><th scope="col" class="num">' . esc_html__( 'Brutto', 'pass-captain' ) . '</th><th scope="col" class="num">' . esc_html__( 'Plattformgebühr', 'pass-captain' ) . '</th><th scope="col" class="num">' . esc_html__( 'Auszahlung', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Aktionen', 'pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $list as $s ) {
			echo '<tr><td>' . esc_html( $s->document_number ?: '—' ) . ( ! empty( $s->self_billing_number ) ? '<br><small>' . esc_html( $s->self_billing_number ) . '</small>' : '' ) . '<br><small>' . esc_html( Settlements::document_label( $s ) ) . '</small></td><td>' . esc_html( $s->partner_name ) . '</td><td>' . esc_html( $s->period ) . '</td><td>' . esc_html( $labels[ $s->status ] ?? $s->status ) . ( 'paid' === $s->status && '' !== $s->payment_reference ? '<br><small>' . esc_html( $s->payment_reference ) . '</small>' : '' ) . '</td>';
			echo '<td class="num">' . esc_html( Forms::money( $s->gross_minor, $s->currency ) ) . '</td><td class="num">' . esc_html( Forms::money( $s->platform_fee_gross_minor, $s->currency ) ) . '</td><td class="num">' . esc_html( ( (int) $s->payout_minor < 0 ? '-' : '' ) . Forms::money( abs( (int) $s->payout_minor ), $s->currency ) ) . '</td><td>';
			echo '<a class="button button-small" target="_blank" rel="noopener" href="' . esc_url( self::document_url( $s->settlement_id, 'html' ) ) . '">' . esc_html__( 'Ansehen/Drucken', 'pass-captain' ) . '</a> <a class="button button-small" href="' . esc_url( self::document_url( $s->settlement_id, 'csv' ) ) . '">CSV</a> ';
			if ( $manage && 'draft' === $s->status ) {
				self::button( 'recompute', array( 'id' => (int) $s->settlement_id ), __( 'Neu berechnen', 'pass-captain' ) );
				self::button( 'finalize', array( 'id' => (int) $s->settlement_id ), __( 'Abschließen', 'pass-captain' ), __( 'Abrechnung abschließen? Danach ist sie unveränderlich und erhält eine Belegnummer.', 'pass-captain' ) );
				self::button( 'delete_draft', array( 'id' => (int) $s->settlement_id ), __( 'Löschen', 'pass-captain' ), __( 'Entwurf löschen?', 'pass-captain' ) );
			} elseif ( $manage && 'finalized' === $s->status ) {
				self::button( 'mark_paid', array( 'id' => (int) $s->settlement_id ), __( 'Als ausgezahlt markieren', 'pass-captain' ), '', '<input type="text" name="reference" placeholder="' . esc_attr__( 'Zahlungsreferenz', 'pass-captain' ) . '" maxlength="190" style="width:10em"> ' );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}

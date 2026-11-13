<?php
/**
 * SMS notifications with explicit opt-in: phone field + consent text in the checkout, consent record
 * (order, number, purpose, text version, timestamp), provider adapters (Twilio, generic HTTP),
 * delivery status callback (Twilio, signature-validated), messages for reminders, reschedules and
 * cancellations – only for consenting buyers.
 */
namespace PassCaptain\Modules;

use PassCaptain\Admin\Forms;
use PassCaptain\Reminders\Placeholders;
use PassCaptain\Settings;
use PassCaptain\Sms\HttpProvider;
use PassCaptain\Sms\Service;
use PassCaptain\Sms\TwilioProvider;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Sms {
	const CAP = 'manage_options';
	const PAGE = 'wpcap-sms';

	public static function register() {
		add_filter( 'wpcap_checkout_payload_keys', array( __CLASS__, 'payload_keys' ) );
		add_action( 'wpcap_checkout_form_fields', array( __CLASS__, 'render_checkout_fields' ), 20, 2 );
		add_action( 'wpcap_order_creating', array( __CLASS__, 'store_consent' ), 10, 4 );
		add_filter( 'wpcap_checkout_error_messages', array( __CLASS__, 'error_messages' ) );
		// Priority 5: before core revokes the tickets (OccurrenceCancellation runs at 10).
		add_action( 'wpcap_occurrence_cancelled', array( __CLASS__, 'occurrence_cancelled' ), 5, 1 );
		add_action( 'wpcap_occurrence_rescheduled', array( __CLASS__, 'occurrence_rescheduled' ), 10, 3 );
		add_action( 'wpcap_process_messages', array( Service::class, 'process_due' ), 20, 0 );
		add_filter( 'wpcap_sms_skip_reason', array( __CLASS__, 'skip_reason' ), 10, 2 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		// Priority 5 + prepended: runs before the core eraser anonymises the buyer e-mail.
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ), 5 );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ), 5 );
		// Consent ends with the order: no further messages after cancellation, refund or revocation.
		add_action( 'wpcap_order_cancelled', array( Service::class, 'retire' ), 20, 1 );
		add_action( 'wpcap_ticket_revoked', array( __CLASS__, 'ticket_revoked' ), 20, 2 );
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'serve_twiml' ), 10, 4 );
	}

	public static function schema( $prefix, $charset ) {
		return array(
			"CREATE TABLE {$prefix}wpcap_sms_consents (
				consent_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				order_id bigint(20) unsigned NOT NULL,
				phone varchar(20) NOT NULL DEFAULT '',
				purpose varchar(32) NOT NULL,
				text_version char(16) NOT NULL,
				consented_at datetime NOT NULL,
				withdrawn_at datetime NULL,
				retired_at datetime NULL,
				PRIMARY KEY  (consent_id),
				UNIQUE KEY order_purpose (order_id,purpose),
				KEY phone (phone,consented_at)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}wpcap_sms_messages (
				message_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				dedupe_key char(64) NOT NULL,
				consent_id bigint(20) unsigned NOT NULL,
				order_id bigint(20) unsigned NOT NULL,
				occurrence_id bigint(20) unsigned NOT NULL DEFAULT 0,
				purpose varchar(32) NOT NULL,
				body text NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'queued',
				provider varchar(32) NOT NULL DEFAULT '',
				provider_ref varchar(64) NOT NULL DEFAULT '',
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				send_at datetime NOT NULL,
				locked_at datetime NULL,
				last_error varchar(190) NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (message_id),
				UNIQUE KEY dedupe_key (dedupe_key),
				KEY due (status,send_at),
				KEY provider_ref (provider,provider_ref),
				KEY order_id (order_id)
			) ENGINE=InnoDB $charset;",
		);
	}

	public static function settings_defaults() {
		return array(
			'sms' => array(
				'provider' => '',
				'consent_text' => __( 'Ja, ich möchte Erinnerungen und Informationen zu Terminänderungen und Absagen dieser Bestellung per SMS erhalten. Die Einwilligung kann jederzeit widerrufen werden.', 'wp-pass-captain' ),
				'default_country_code' => '+49',
				// Only numbers of these countries (and, for AT/DE/CH, only mobile ranges) are accepted.
				'allowed_prefixes' => '+43,+49,+41',
				'twilio_from' => '',
				'http_endpoint' => '',
				'http_auth_header' => 'Authorization',
				'http_content_type' => 'json',
				'http_body_template' => HttpProvider::DEFAULT_TEMPLATE,
				'cancel_body' => __( 'Abgesagt: {event} am {date} um {time}. Dein Ticket ist ungültig; Infos zur Erstattung per E-Mail.', 'wp-pass-captain' ),
				'reschedule_body' => __( 'Neuer Termin: {event} findet am {date} um {time} ({timezone}) statt (bisher {old_date}). Dein Ticket bleibt gültig.', 'wp-pass-captain' ),
			),
			// Scalar secrets so that constants/env vars (WPCAP_TWILIO_AUTH_TOKEN …) work; the values are kept in the encrypted store (Secrets).
			'twilio_account_sid' => '',
			'twilio_auth_token' => '',
			'sms_http_secret' => '',
		);
	}

	public static function admin_pages() {
		return array( array( self::PAGE, __( 'SMS', 'wp-pass-captain' ), self::CAP, array( __CLASS__, 'render_admin' ) ) );
	}

	public static function admin_handlers() {
		return array( 'wpcap_sms_save' => array( __CLASS__, 'handle_save' ) );
	}

	public static function register_routes() {
		register_rest_route( 'wp-pass-captain/v1', '/sms/twilio/status', array(
			'methods' => 'POST',
			'callback' => array( Service::class, 'twilio_status' ),
			// Authenticity: X-Twilio-Signature is validated in the callback.
			'permission_callback' => '__return_true',
		) );
		register_rest_route( 'wp-pass-captain/v1', '/sms/twilio/inbound', array(
			'methods' => 'POST',
			'callback' => array( Service::class, 'twilio_inbound' ),
			// Authenticity: X-Twilio-Signature is validated in the callback.
			'permission_callback' => '__return_true',
		) );
	}

	/** Twilio expects TwiML from the inbound webhook: answer with an empty <Response/> (no auto-reply). */
	public static function serve_twiml( $served, $result = null, $request = null, $server = null ) {
		if ( $served || ! is_object( $request ) || ! method_exists( $request, 'get_route' ) || '/wp-pass-captain/v1/sms/twilio/inbound' !== $request->get_route() ) { return $served; }
		if ( is_object( $result ) && method_exists( $result, 'get_status' ) && $result->get_status() >= 400 ) { return $served; }
		if ( ! headers_sent() ) { header( 'Content-Type: text/xml; charset=utf-8' ); }
		echo '<?xml version="1.0" encoding="UTF-8"?><Response></Response>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- constant TwiML.
		return true;
	}

	/** Retires the consent when an order has no valid tickets left (refund, revocation, cancellation). */
	public static function ticket_revoked( $ticket_id, $reason = '' ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM {$p}wpcap_tickets WHERE ticket_id=%d", absint( $ticket_id ) ) );
		if ( ! $order_id || ! $wpdb->get_var( $wpdb->prepare( "SELECT consent_id FROM {$p}wpcap_sms_consents WHERE order_id=%d AND retired_at IS NULL AND withdrawn_at IS NULL", $order_id ) ) ) { return false; }
		$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE order_id=%d AND status IN ('valid','checked_in')", $order_id ) );
		return $left ? false : Service::retire( $order_id );
	}

	/* ------------------------------------------------------------------ Checkout */

	public static function payload_keys( $keys ) {
		return array_merge( (array) $keys, array( 'sms_phone', 'sms_consent' ) );
	}

	public static function error_messages( $messages ) {
		$messages['sms_phone_not_allowed'] = __( 'SMS-Benachrichtigungen sind nur für Mobilnummern aus den unterstützten Ländern möglich. Bitte deaktiviere die SMS-Benachrichtigung oder nutze eine andere Nummer.', 'wp-pass-captain' );
		$messages['sms_rate_limited'] = __( 'Für diese Mobilnummer wurden zu viele SMS-Anmeldungen erstellt. Bitte versuche es später erneut oder bestelle ohne SMS-Benachrichtigung.', 'wp-pass-captain' );
		$messages['sms_phone_invalid'] = __( 'Bitte gib eine gültige Mobilnummer an (z. B. +49 151 23456789) oder deaktiviere die SMS-Benachrichtigung.', 'wp-pass-captain' );
		return $messages;
	}

	public static function render_checkout_fields( $event = null, $types = null ) {
		if ( ! Service::enabled() || '' === Service::consent_text() ) { return; }
		static $enqueued = false;
		if ( ! $enqueued && function_exists( 'wp_enqueue_script' ) ) {
			wp_enqueue_script( 'wpcap-sms-checkout', WPCAP_URL . 'assets/sms/checkout-sms.js', array(), WPCAP_VERSION, true );
			wp_localize_script( 'wpcap-sms-checkout', 'WPCAP_SMS', array( 'phoneRequired' => __( 'Bitte gib für SMS-Benachrichtigungen eine Mobilnummer an.', 'wp-pass-captain' ) ) );
			$enqueued = true;
		}
		$id = 'wpcap-sms-' . ( is_object( $event ) ? (int) $event->occurrence_id : 0 );
		echo '<fieldset class="wpcap-sms"><legend>' . esc_html__( 'SMS-Benachrichtigung (optional)', 'wp-pass-captain' ) . '</legend>';
		echo '<label for="' . esc_attr( $id ) . '-consent" class="wpcap-terms"><input type="checkbox" id="' . esc_attr( $id ) . '-consent" name="sms_consent" value="1"> ' . esc_html( Service::consent_text() ) . '</label>';
		echo '<label for="' . esc_attr( $id ) . '-phone">' . esc_html__( 'Mobilnummer', 'wp-pass-captain' ) . ' <input type="tel" id="' . esc_attr( $id ) . '-phone" name="sms_phone" autocomplete="tel" inputmode="tel" maxlength="20"></label>';
		echo '</fieldset>';
	}

	/** wpcap_order_creating: store consent; a number without consent is discarded. */
	public static function store_consent( $order_id, $selected = array(), $payload = array(), $internal = array() ) {
		if ( empty( $payload['sms_consent'] ) || ! empty( $internal['external'] ) || ! Service::enabled() ) { return; }
		$phone = Service::normalize_phone( is_scalar( $payload['sms_phone'] ?? null ) ? $payload['sms_phone'] : '' );
		if ( '' === $phone ) { throw new \RuntimeException( 'sms_phone_invalid' ); }
		if ( ! Service::number_allowed( $phone ) ) { throw new \RuntimeException( 'sms_phone_not_allowed' ); }
		// Abuse protection: third-party numbers and cost pumping (no OTP verification, see docs).
		if ( Service::recent_consents_for_number( $phone ) >= (int) apply_filters( 'wpcap_sms_number_daily_limit', 3 ) ) { throw new \RuntimeException( 'sms_rate_limited' ); }
		if ( ! \PassCaptain\RateLimit::allow( 'sms_consent|' . \PassCaptain\RateLimit::client(), (int) apply_filters( 'wpcap_sms_ip_hourly_limit', 10 ), HOUR_IN_SECONDS ) ) { throw new \RuntimeException( 'sms_rate_limited' ); }
		Service::record_consent( $order_id, $phone );
	}

	/* ------------------------------------------------------------------ Change messages */

	/** Orders with valid tickets for an occurrence. */
	private static function orders_for_occurrence( $occurrence_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT t.order_id FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_sms_consents c ON c.order_id=t.order_id AND c.withdrawn_at IS NULL WHERE tt.occurrence_id=%d AND t.status='valid' AND t.refunded=0", absint( $occurrence_id ) ) ) );
	}

	private static function occurrence( $occurrence_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_row( $wpdb->prepare( "SELECT o.*,e.name AS event_name FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE o.occurrence_id=%d", absint( $occurrence_id ) ) );
	}

	public static function occurrence_cancelled( $occurrence_id ) {
		$occ = self::occurrence( $occurrence_id );
		$s = (array) Settings::get( 'sms' );
		if ( ! $occ || '' === trim( (string) ( $s['cancel_body'] ?? '' ) ) ) { return 0; }
		$body = Placeholders::render( $s['cancel_body'], Placeholders::occurrence_vars( $occ ) );
		$count = 0;
		foreach ( self::orders_for_occurrence( $occurrence_id ) as $order_id ) {
			if ( true === Service::enqueue( 'sms|cancel|' . (int) $occurrence_id . '|' . $order_id, $order_id, $occurrence_id, 'cancellation', $body ) ) { ++$count; }
		}
		return $count;
	}

	public static function occurrence_rescheduled( $occurrence_id, $old_starts_at = '', $new_starts_at = '' ) {
		$occ = self::occurrence( $occurrence_id );
		$s = (array) Settings::get( 'sms' );
		if ( ! $occ || $old_starts_at === $new_starts_at || '' === trim( (string) ( $s['reschedule_body'] ?? '' ) ) ) { return 0; }
		$vars = Placeholders::occurrence_vars( $occ );
		$old = (object) array( 'starts_at' => $old_starts_at, 'timezone' => $occ->timezone, 'location' => '', 'event_name' => '', 'occurrence_id' => $occ->occurrence_id );
		$old_vars = Placeholders::occurrence_vars( $old );
		$vars['old_date'] = $old_vars['date'] . ' ' . $old_vars['time'];
		$body = Placeholders::render( $s['reschedule_body'], $vars );
		$count = 0;
		foreach ( self::orders_for_occurrence( $occurrence_id ) as $order_id ) {
			if ( true === Service::enqueue( 'sms|reschedule|' . (int) $occurrence_id . '|' . $new_starts_at . '|' . $order_id, $order_id, $occurrence_id, 'reschedule', $body ) ) { ++$count; }
		}
		return $count;
	}

	/** Reminders are dropped at send time when tickets or the occurrence are no longer valid. */
	public static function skip_reason( $reason, $row = null ) {
		if ( '' !== $reason || ! is_object( $row ) || 'reminder' !== $row->purpose ) { return $reason; }
		global $wpdb;
		$p = $wpdb->prefix;
		$valid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE t.order_id=%d AND tt.occurrence_id=%d AND t.status='valid' AND t.refunded=0 AND o.status='scheduled' AND o.starts_at>%s", (int) $row->order_id, (int) $row->occurrence_id, current_time( 'mysql', true ) ) );
		return $valid ? '' : 'not_valid_anymore';
	}

	/* ------------------------------------------------------------------ Privacy */

	/** Listed first (WordPress runs erasers in array order), so it sees the original buyer e-mail. */
	public static function register_eraser( $erasers ) {
		return array_merge( array( 'wpcap-sms' => array( 'eraser_friendly_name' => __( 'WP Pass Captain SMS-Einwilligungen', 'wp-pass-captain' ), 'callback' => array( __CLASS__, 'erase' ) ) ), (array) $erasers );
	}

	public static function register_exporter( $exporters ) {
		$exporters['wpcap-sms'] = array( 'exporter_friendly_name' => __( 'WP Pass Captain SMS-Einwilligungen', 'wp-pass-captain' ), 'callback' => array( __CLASS__, 'export' ) );
		return $exporters;
	}

	/**
	 * Consents of orders bought with this e-mail. Also matches orders the core eraser already
	 * anonymised (deterministic replacement deleted+{sha256(email|order_id)[0..24]}@invalid.example),
	 * so the result does not depend on the eraser order.
	 */
	private static function consents_for_email( $email ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$email = sanitize_email( $email );
		if ( '' === $email ) { return array(); }
		return $wpdb->get_results( $wpdb->prepare( "SELECT c.* FROM {$p}wpcap_sms_consents c INNER JOIN {$p}wpcap_orders o ON o.order_id=c.order_id WHERE o.customer_email=%s OR o.customer_email=CONCAT('deleted+',LEFT(SHA2(CONCAT(%s,'|',o.order_id),256),24),'@invalid.example') ORDER BY c.consent_id", $email, $email ) );
	}

	public static function erase( $email, $page = 1 ) {
		$removed = 0;
		foreach ( self::consents_for_email( $email ) as $consent ) {
			if ( Service::withdraw( (int) $consent->order_id ) ) { ++$removed; }
		}
		return array( 'items_removed' => $removed > 0, 'items_retained' => false, 'messages' => array(), 'done' => true );
	}

	public static function export( $email, $page = 1 ) {
		$data = array();
		foreach ( self::consents_for_email( $email ) as $c ) {
			$data[] = array( 'group_id' => 'wpcap-sms', 'group_label' => __( 'WP Pass Captain SMS-Einwilligungen', 'wp-pass-captain' ), 'item_id' => 'sms-consent-' . (int) $c->consent_id, 'data' => array(
				array( 'name' => __( 'Mobilnummer', 'wp-pass-captain' ), 'value' => '' !== $c->phone ? $c->phone : __( '(gelöscht)', 'wp-pass-captain' ) ),
				array( 'name' => __( 'Zweck', 'wp-pass-captain' ), 'value' => $c->purpose ),
				array( 'name' => __( 'Version des Einwilligungstexts', 'wp-pass-captain' ), 'value' => $c->text_version ),
				array( 'name' => __( 'Eingewilligt (UTC)', 'wp-pass-captain' ), 'value' => $c->consented_at ),
				array( 'name' => __( 'Widerrufen / beendet (UTC)', 'wp-pass-captain' ), 'value' => (string) ( $c->withdrawn_at ?? '' ) . ' / ' . (string) ( $c->retired_at ?? '' ) ),
			) );
		}
		return array( 'data' => $data, 'done' => true );
	}

	/* ------------------------------------------------------------------ Admin */

	public static function save( array $in ) {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		$endpoint = trim( (string) ( $in['http_endpoint'] ?? '' ) );
		if ( '' !== $endpoint && 0 !== stripos( $endpoint, 'https://' ) ) {
			return Forms::error( 'wpcap_sms_endpoint', __( 'Der SMS-Endpunkt muss HTTPS verwenden.', 'wp-pass-captain' ) );
		}
		$prefix = trim( (string) ( $in['default_country_code'] ?? '' ) );
		$values = array(
			'provider' => Forms::choice( $in, 'provider', array_merge( array( '' ), array_keys( Service::providers() ) ), '' ),
			'consent_text' => Forms::textarea( $in, 'consent_text', 1000 ),
			'default_country_code' => preg_match( '/^\+[1-9]\d{0,3}$/', $prefix ) ? $prefix : '',
			'allowed_prefixes' => implode( ',', array_filter( preg_split( '/[\s,;]+/', (string) ( $in['allowed_prefixes'] ?? '' ) ), static function ( $v ) { return 1 === preg_match( '/^\+[1-9]\d{0,3}$/', $v ); } ) ),
			'twilio_from' => preg_replace( '/[^A-Za-z0-9+ ]/', '', Forms::text( $in, 'twilio_from', 34 ) ),
			'http_endpoint' => esc_url_raw( $endpoint ),
			'http_auth_header' => preg_replace( '/[^A-Za-z0-9\-]/', '', Forms::text( $in, 'http_auth_header', 64 ) ),
			'http_content_type' => Forms::choice( $in, 'http_content_type', array( 'json', 'form' ), 'json' ),
			'http_body_template' => mb_substr( is_scalar( $in['http_body_template'] ?? null ) ? trim( (string) $in['http_body_template'] ) : '', 0, 2000 ),
			'cancel_body' => Forms::textarea( $in, 'cancel_body', 640 ),
			'reschedule_body' => Forms::textarea( $in, 'reschedule_body', 640 ),
		);
		$update = array( 'sms' => $values );
		foreach ( array( 'twilio_auth_token', 'sms_http_secret' ) as $secret ) {
			if ( Forms::flag( $in, $secret . '__clear' ) ) { $update[ $secret ] = ''; } elseif ( '' !== trim( (string) ( $in[ $secret ] ?? '' ) ) ) { $update[ $secret ] = trim( sanitize_text_field( (string) $in[ $secret ] ) ); }
		}
		if ( isset( $in['twilio_account_sid'] ) ) { $update['twilio_account_sid'] = preg_replace( '/[^A-Za-z0-9]/', '', (string) $in['twilio_account_sid'] ); }
		Settings::update( $update );
		return array( 'saved' => array_keys( $update ) );
	}

	public static function handle_save() {
		Forms::verify( 'wpcap_sms_save' );
		Forms::finish( self::save( Forms::post() ), __( 'Einstellungen gespeichert.', 'wp-pass-captain' ), Forms::page_url( self::PAGE ) );
	}

	public static function render_admin() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 ); }
		global $wpdb;
		$s = (array) Settings::get( 'sms' );
		$row = static function ( $label, $html, $desc = '' ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $html . ( '' !== $desc ? '<p class="description">' . esc_html( $desc ) . '</p>' : '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $html is built from escaped parts below.
		};
		$text = static function ( $name, $value, $type = 'text' ) {
			return '<input type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" class="regular-text" value="' . esc_attr( (string) $value ) . '"' . ( 'password' === $type ? ' autocomplete="new-password"' : '' ) . '>';
		};
		$area = static function ( $name, $value ) {
			return '<textarea name="' . esc_attr( $name ) . '" class="large-text" rows="3">' . esc_textarea( (string) $value ) . '</textarea>';
		};
		$secret = static function ( $key ) {
			ob_start();
			\PassCaptain\Secrets::render_field( $key, 'wpcap-sms-' . $key );
			return ob_get_clean();
		};
		$providers = array( '' => __( 'Deaktiviert', 'wp-pass-captain' ) );
		foreach ( Service::providers() as $id => $provider ) { $providers[ $id ] = $provider->title() . ( $provider->is_configured() ? '' : ' ' . __( '(nicht konfiguriert)', 'wp-pass-captain' ) ); }
		echo '<div class="wrap wpcap-admin"><h1>' . esc_html__( 'SMS-Benachrichtigungen', 'wp-pass-captain' ) . '</h1>';
		Forms::render_notices();
		echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Kostenhinweis:', 'wp-pass-captain' ) . '</strong> ' . esc_html__( 'Jede SMS wird vom Anbieter berechnet (je Segment von 160 GSM- bzw. 70 Unicode-Zeichen, Preise je Zielland). Erinnerungen, Verschiebungen und Absagen erzeugen pro einwilligender Bestellung je eine SMS. Prüfe die Preise deines Anbieters, bevor du SMS aktivierst.', 'wp-pass-captain' ) . '</p></div>';
		echo '<p>' . esc_html__( 'SMS werden nur an Käufer:innen gesendet, die im Checkout ausdrücklich eingewilligt haben (Nummer, Zweck, Textversion und Zeitpunkt werden gespeichert).', 'wp-pass-captain' ) . '</p>';
		Forms::open_form( 'wpcap_sms_save' );
		echo '<table class="form-table"><tbody>';
		ob_start(); Forms::select( 'provider', $providers, (string) ( $s['provider'] ?? '' ) ); $row( __( 'Anbieter', 'wp-pass-captain' ), ob_get_clean() );
		$row( __( 'Einwilligungstext im Checkout', 'wp-pass-captain' ), $area( 'consent_text', $s['consent_text'] ?? '' ), sprintf( /* translators: %s: version */ __( 'Aktuelle Textversion: %s – Änderungen erzeugen eine neue Version.', 'wp-pass-captain' ), Service::consent_version() ) );
		$row( __( 'Standard-Ländervorwahl', 'wp-pass-captain' ), $text( 'default_country_code', $s['default_country_code'] ?? '' ), __( 'Für Nummern mit führender 0, z. B. +49.', 'wp-pass-captain' ) );
		$row( __( 'Erlaubte Ländervorwahlen', 'wp-pass-captain' ), $text( 'allowed_prefixes', $s['allowed_prefixes'] ?? '+43,+49,+41' ), __( 'Kommagetrennt. Für +43, +49 und +41 werden nur Mobilfunkbereiche akzeptiert (keine Premium-/Sonderrufnummern). Je Nummer höchstens 3 Einwilligungen pro Tag, je IP 10 pro Stunde. Eine Bestätigung per SMS-Code (OTP) ist nicht implementiert.', 'wp-pass-captain' ) );
		$row( __( 'Text bei Absage', 'wp-pass-captain' ), $area( 'cancel_body', $s['cancel_body'] ?? '' ) );
		$row( __( 'Text bei Verschiebung', 'wp-pass-captain' ), $area( 'reschedule_body', $s['reschedule_body'] ?? '' ), __( 'Platzhalter: ', 'wp-pass-captain' ) . implode( ' ', array_keys( Placeholders::available() ) ) );
		echo '</tbody></table><h2>Twilio</h2><table class="form-table"><tbody>';
		$row( __( 'Account SID', 'wp-pass-captain' ), $text( 'twilio_account_sid', Settings::get( 'twilio_account_sid' ) ), __( 'Alternativ Konstante WPCAP_TWILIO_ACCOUNT_SID.', 'wp-pass-captain' ) );
		$row( __( 'Auth Token', 'wp-pass-captain' ), $secret( 'twilio_auth_token' ) );
		$row( __( 'Absender (Nummer, Name oder Messaging Service SID)', 'wp-pass-captain' ), $text( 'twilio_from', $s['twilio_from'] ?? '' ) );
		$row( __( 'Eingehende SMS (Abmeldung mit STOP)', 'wp-pass-captain' ), '<code>' . esc_html( TwilioProvider::inbound_url() ) . '</code>', __( 'Bei der Twilio-Nummer als Webhook für „A message comes in“ eintragen; STOP, STOPP oder ABMELDEN widerruft alle Einwilligungen der Nummer.', 'wp-pass-captain' ) );
		$row( __( 'Status-Callback', 'wp-pass-captain' ), '<code>' . esc_html( TwilioProvider::status_callback_url() ) . '</code>', __( 'Wird automatisch pro Nachricht übergeben; Signatur (X-Twilio-Signature) wird geprüft.', 'wp-pass-captain' ) );
		echo '</tbody></table><h2>' . esc_html__( 'Generischer HTTP-Anbieter', 'wp-pass-captain' ) . '</h2><table class="form-table"><tbody>';
		$row( __( 'Endpunkt (HTTPS)', 'wp-pass-captain' ), $text( 'http_endpoint', $s['http_endpoint'] ?? '', 'url' ) );
		$row( __( 'Auth-Header-Name', 'wp-pass-captain' ), $text( 'http_auth_header', $s['http_auth_header'] ?? '' ) );
		$row( __( 'Auth-Header-Wert', 'wp-pass-captain' ), $secret( 'sms_http_secret' ) );
		ob_start(); Forms::select( 'http_content_type', array( 'json' => 'application/json', 'form' => 'application/x-www-form-urlencoded' ), (string) ( $s['http_content_type'] ?? 'json' ) ); $row( __( 'Content-Type', 'wp-pass-captain' ), ob_get_clean() );
		$row( __( 'Body-Vorlage', 'wp-pass-captain' ), $area( 'http_body_template', $s['http_body_template'] ?? '' ), __( 'Platzhalter {to}, {body}, {reference} werden passend zum Content-Type maskiert.', 'wp-pass-captain' ) );
		echo '</tbody></table>';
		submit_button( __( 'Speichern', 'wp-pass-captain' ) );
		echo '</form>';
		$stats = $wpdb->get_results( "SELECT status,COUNT(*) AS n FROM {$wpdb->prefix}wpcap_sms_messages GROUP BY status" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $stats ) {
			echo '<h2>' . esc_html__( 'Versandstatus', 'wp-pass-captain' ) . '</h2><ul>';
			foreach ( $stats as $stat ) { echo '<li>' . esc_html( $stat->status . ': ' . (int) $stat->n ) . '</li>'; }
			echo '</ul>';
		}
		echo '</div>';
	}
}

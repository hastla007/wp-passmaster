<?php
/**
 * Outgoing webhooks module: maps plugin actions to webhook events, owns the subscription and
 * delivery tables, processes the queue on wpcap_process_jobs (and a best-effort single event right
 * after insert) and provides the "Webhooks" admin page. Payload reference: docs/WEBHOOKS.md.
 */
namespace PassCaptain\Modules;

use PassCaptain\Admin\Forms;
use PassCaptain\Webhooks\Dispatcher;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Webhooks {
	const CAP = 'manage_options';
	const PAGE = 'wpcap-webhooks';

	public static function register() {
		add_action( 'wpcap_order_paid', array( __CLASS__, 'order_paid' ), 50, 1 );
		add_action( 'wpcap_order_cancelled', array( __CLASS__, 'order_cancelled' ), 50, 1 );
		add_action( 'wpcap_refund_completed', array( __CLASS__, 'refund_completed' ), 50, 2 );
		add_action( 'wpcap_order_refunded', array( __CLASS__, 'order_refunded' ), 50, 2 );
		add_action( 'wpcap_tickets_issued', array( __CLASS__, 'tickets_issued' ), 50, 2 );
		add_action( 'wpcap_ticket_checked_in', array( __CLASS__, 'ticket_checked_in' ), 50, 2 );
		add_action( 'wpcap_ticket_revoked', array( __CLASS__, 'ticket_revoked' ), 50, 2 );
		add_action( 'wpcap_ticket_exchanged', array( __CLASS__, 'ticket_exchanged' ), 50, 4 );
		add_action( 'wpcap_occurrence_rescheduled', array( __CLASS__, 'occurrence_rescheduled' ), 50, 3 );
		add_action( 'wpcap_occurrence_cancelled', array( __CLASS__, 'occurrence_cancelled' ), 50, 2 );
		add_action( 'wpcap_process_jobs', array( Dispatcher::class, 'process' ), 20, 0 );
		add_action( 'wpcap_webhooks_dispatch', array( Dispatcher::class, 'process' ), 10, 0 );
		add_action( 'wpcap_process_jobs', array( Dispatcher::class, 'purge_old' ), 25, 0 );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ), 5 );
	}

	public static function settings_defaults() {
		return array( 'webhooks' => array( 'retention_days' => 30 ) );
	}

	public static function register_eraser( $erasers ) {
		return array_merge( array( 'wpcap-webhooks' => array( 'eraser_friendly_name' => __( 'WP Pass Captain Webhook-Protokoll', 'wp-pass-captain' ), 'callback' => array( __CLASS__, 'erase' ) ) ), (array) $erasers );
	}

	public static function erase( $email, $page = 1 ) {
		$count = Dispatcher::erase_email( $email );
		return array( 'items_removed' => $count > 0, 'items_retained' => false, 'messages' => array(), 'done' => true );
	}

	public static function schema( $prefix, $charset ) {
		return array(
			"CREATE TABLE {$prefix}wpcap_webhook_subscriptions (
				subscription_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				url varchar(500) NOT NULL,
				description varchar(190) NOT NULL DEFAULT '',
				events varchar(500) NOT NULL,
				secret varchar(80) NOT NULL,
				include_pii tinyint(1) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'active',
				disabled_reason varchar(32) NOT NULL DEFAULT '',
				consecutive_failures int(10) unsigned NOT NULL DEFAULT 0,
				last_success_at datetime NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (subscription_id),
				KEY status (status)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}wpcap_webhook_deliveries (
				delivery_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				subscription_id bigint(20) unsigned NOT NULL,
				event_id varchar(40) NOT NULL,
				event_type varchar(40) NOT NULL,
				payload longtext NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'pending',
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				response_code smallint(5) unsigned NOT NULL DEFAULT 0,
				last_error varchar(190) NOT NULL DEFAULT '',
				next_attempt_at datetime NOT NULL,
				locked_at datetime NULL,
				delivered_at datetime NULL,
				pii_purged tinyint(1) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (delivery_id),
				UNIQUE KEY subscription_event (subscription_id,event_id),
				KEY due (status,next_attempt_at)
			) ENGINE=InnoDB $charset;",
		);
	}

	public static function admin_pages() {
		return array( array( self::PAGE, __( 'Webhooks', 'wp-pass-captain' ), self::CAP, array( __CLASS__, 'render_admin' ) ) );
	}

	public static function admin_handlers() {
		return array( 'wpcap_webhook_action' => array( __CLASS__, 'handle_action' ) );
	}

	/* ------------------------------------------------------------------ Payload objects */

	/** Order object without PII; [object, pii]. */
	public static function order_object( $order_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$o = $wpdb->get_row( $wpdb->prepare( "SELECT order_id,public_id,customer_email,status,payment_status,currency,total_minor,refunded_minor,payment_provider FROM {$p}wpcap_orders WHERE order_id=%d", absint( $order_id ) ) );
		if ( ! $o ) { return null; }
		// Tickets of the order: issued ones, at least the ordered quantity (order.paid fires before the
		// issuance, see docs/HOOKS.md).
		$count = max(
			(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE order_id=%d", (int) $o->order_id ) ),
			(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(' . \PassCaptain\OrderLines::paid( 'quantity', '' ) . "),0) FROM {$p}wpcap_order_items WHERE order_id=%d", (int) $o->order_id ) )
		);
		return array(
			array( 'object' => 'order', 'id' => $o->public_id, 'status' => $o->status, 'payment_status' => $o->payment_status, 'currency' => $o->currency, 'total_minor' => (int) $o->total_minor, 'refunded_minor' => (int) $o->refunded_minor, 'payment_provider' => $o->payment_provider, 'ticket_count' => $count ),
			array( 'customer_email' => $o->customer_email ),
		);
	}

	/** Ticket object without PII; [object, pii]. */
	public static function ticket_object( $ticket_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$t = $wpdb->get_row( $wpdb->prepare( "SELECT t.public_id,t.status,t.refunded,t.attendee_email,t.ticket_type_id,ord.public_id AS order_public_id,tt.occurrence_id,o.event_id FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE t.ticket_id=%d", absint( $ticket_id ) ) );
		if ( ! $t ) { return null; }
		return array(
			array( 'object' => 'ticket', 'id' => $t->public_id, 'order_id' => $t->order_public_id, 'ticket_type_id' => (int) $t->ticket_type_id, 'occurrence_id' => (int) $t->occurrence_id, 'event_id' => (int) $t->event_id, 'status' => $t->status, 'refunded' => (bool) $t->refunded ),
			'' !== (string) $t->attendee_email ? array( 'attendee_email' => $t->attendee_email ) : array(),
		);
	}

	public static function occurrence_object( $occurrence_id ) {
		global $wpdb;
		$o = $wpdb->get_row( $wpdb->prepare( "SELECT occurrence_id,event_id,starts_at,ends_at,timezone,status FROM {$wpdb->prefix}wpcap_occurrences WHERE occurrence_id=%d", absint( $occurrence_id ) ) );
		if ( ! $o ) { return null; }
		return array( 'object' => 'occurrence', 'id' => (int) $o->occurrence_id, 'event_id' => (int) $o->event_id, 'starts_at' => self::iso( $o->starts_at ), 'ends_at' => $o->ends_at ? self::iso( $o->ends_at ) : null, 'timezone' => $o->timezone, 'status' => $o->status );
	}

	private static function iso( $utc ) {
		return gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $utc . ' UTC' ) );
	}

	/* ------------------------------------------------------------------ Hook handlers */

	public static function order_paid( $order_id ) {
		$o = self::order_object( $order_id );
		return $o ? Dispatcher::emit( 'order.paid', 'order|' . (int) $order_id, $o[0], $o[1] ) : 0;
	}

	public static function order_cancelled( $order_id ) {
		$o = self::order_object( $order_id );
		return $o ? Dispatcher::emit( 'order.cancelled', 'order|' . (int) $order_id, $o[0], $o[1] ) : 0;
	}

	public static function refund_completed( $refund_id, $order_id = 0 ) {
		global $wpdb;
		$o = self::order_object( $order_id );
		if ( ! $o ) { return 0; }
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT refund_id,amount_minor,currency,ticket_ids FROM {$wpdb->prefix}wpcap_refunds WHERE refund_id=%d", absint( $refund_id ) ) );
		$ids = $r ? array_map( 'absint', (array) json_decode( (string) $r->ticket_ids, true ) ) : array();
		$public = $ids ? $wpdb->get_col( 'SELECT public_id FROM ' . $wpdb->prefix . 'wpcap_tickets WHERE ticket_id IN (' . implode( ',', $ids ) . ')' ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- integer list.
		$o[0]['refund'] = array( 'id' => (int) $refund_id, 'amount_minor' => $r ? (int) $r->amount_minor : 0, 'currency' => $r ? $r->currency : $o[0]['currency'], 'ticket_ids' => array_values( $public ) );
		return Dispatcher::emit( 'order.refunded', 'refund|' . (int) $refund_id, $o[0], $o[1] );
	}

	/**
	 * Full refunds outside the native refund flow (WooCommerce, provider dashboard). Native refunds
	 * already emit order.refunded per refund via wpcap_refund_completed, so they are skipped here.
	 */
	public static function order_refunded( $order_id, $reason = '' ) {
		if ( 0 === strpos( (string) $reason, 'native_refund_' ) ) { return 0; }
		$o = self::order_object( $order_id );
		if ( ! $o ) { return 0; }
		$o[0]['refund'] = array( 'id' => null, 'source' => sanitize_key( (string) $reason ), 'full' => true );
		return Dispatcher::emit( 'order.refunded', 'order-full|' . (int) $order_id, $o[0], $o[1] );
	}

	public static function tickets_issued( $order_id, $count = 0 ) {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM {$wpdb->prefix}wpcap_tickets WHERE order_id=%d ORDER BY ticket_id", absint( $order_id ) ) );
		$queued = 0;
		foreach ( $ids as $ticket_id ) {
			$t = self::ticket_object( $ticket_id );
			if ( $t ) { $queued += Dispatcher::emit( 'ticket.issued', 'ticket|' . (int) $ticket_id, $t[0], $t[1] ); }
		}
		return $queued;
	}

	public static function ticket_checked_in( $ticket_id, $context = array() ) {
		$t = self::ticket_object( $ticket_id );
		if ( ! $t ) { return 0; }
		$t[0]['checked_in_at'] = gmdate( 'Y-m-d\TH:i:s\Z' );
		$t[0]['source'] = sanitize_key( is_array( $context ) ? ( $context['source'] ?? 'web' ) : 'web' );
		// Multi-entry tickets can be checked in repeatedly: each check-in is its own event.
		return Dispatcher::emit( 'ticket.checked_in', 'checkin|' . (int) $ticket_id . '|' . wp_generate_uuid4(), $t[0], $t[1] );
	}

	public static function ticket_revoked( $ticket_id, $reason = '' ) {
		$t = self::ticket_object( $ticket_id );
		if ( ! $t ) { return 0; }
		$t[0]['reason'] = sanitize_key( (string) $reason );
		return Dispatcher::emit( 'ticket.revoked', 'revoked|' . (int) $ticket_id, $t[0], $t[1] );
	}

	/** ticket.exchanged: the new ticket with the previous ticket and the money of the exchange. */
	public static function ticket_exchanged( $old_ticket_id, $new_ticket_id, $exchange_id = 0, $exchange = array() ) {
		global $wpdb;
		$t = self::ticket_object( $new_ticket_id );
		if ( ! $t ) { return 0; }
		$exchange = (array) $exchange;
		$t[0]['previous_ticket_id'] = (string) $wpdb->get_var( $wpdb->prepare( "SELECT public_id FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_id=%d", absint( $old_ticket_id ) ) );
		$t[0]['exchange'] = array(
			'from_ticket_type_id' => (int) ( $exchange['from_type_id'] ?? 0 ),
			'to_ticket_type_id' => (int) ( $exchange['to_type_id'] ?? 0 ),
			'channel' => sanitize_key( (string) ( $exchange['channel'] ?? '' ) ),
			'currency' => (string) ( $exchange['currency'] ?? '' ),
			'difference_minor' => (int) ( $exchange['difference_minor'] ?? 0 ),
			'fee_minor' => (int) ( $exchange['fee_minor'] ?? 0 ),
			'refund_minor' => (int) ( $exchange['refund_minor'] ?? 0 ),
			'due_minor' => (int) ( $exchange['due_minor'] ?? 0 ),
		);
		return Dispatcher::emit( 'ticket.exchanged', 'exchanged|' . absint( $exchange_id ), $t[0], $t[1] );
	}

	public static function occurrence_rescheduled( $occurrence_id, $old = '', $new = '' ) {
		$o = self::occurrence_object( $occurrence_id );
		if ( ! $o ) { return 0; }
		$o['previous_starts_at'] = $old ? self::iso( $old ) : null;
		return Dispatcher::emit( 'occurrence.rescheduled', 'rescheduled|' . (int) $occurrence_id . '|' . $old . '|' . $new, $o );
	}

	public static function occurrence_cancelled( $occurrence_id, $issued = 0 ) {
		$o = self::occurrence_object( $occurrence_id );
		if ( ! $o ) { return 0; }
		$o['issued_tickets'] = (int) $issued;
		return Dispatcher::emit( 'occurrence.cancelled', 'cancelled|' . (int) $occurrence_id, $o );
	}

	/* ------------------------------------------------------------------ Admin */

	/**
	 * Executes an admin action.
	 *
	 * @return array|\WP_Error
	 */
	public static function admin_action( array $in ) {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		$do = sanitize_key( $in['do'] ?? '' );
		$id = absint( $in['id'] ?? 0 );
		switch ( $do ) {
			case 'create':
				return Dispatcher::create_subscription( (string) ( $in['url'] ?? '' ), array_map( 'strval', (array) ( $in['events'] ?? array() ) ), Forms::flag( $in, 'include_pii' ), (string) ( $in['description'] ?? '' ) );
			case 'enable':
			case 'disable':
				if ( ! Dispatcher::subscription( $id ) ) { break; }
				Dispatcher::set_status( $id, 'enable' === $do );
				return array( 'subscription_id' => $id );
			case 'rotate':
				if ( ! Dispatcher::subscription( $id ) ) { break; }
				return array( 'subscription_id' => $id, 'secret' => Dispatcher::rotate_secret( $id ) );
			case 'delete':
				if ( ! Dispatcher::subscription( $id ) ) { break; }
				Dispatcher::delete_subscription( $id );
				return array( 'deleted' => $id );
			case 'settings':
				$days = max( 1, min( 365, absint( $in['retention_days'] ?? 30 ) ) );
				\PassCaptain\Settings::update( array( 'webhooks' => array( 'retention_days' => $days ) ) );
				return array( 'retention_days' => $days );
			case 'redeliver':
				$r = Dispatcher::redeliver( $id );
				return is_wp_error( $r ) ? $r : array( 'delivery_id' => $id );
		}
		return Forms::error( 'wpcap_webhook_action', __( 'Unbekannte Aktion oder Webhook nicht gefunden.', 'wp-pass-captain' ), 404 );
	}

	public static function handle_action() {
		Forms::verify( 'wpcap_webhook_action' );
		$result = self::admin_action( Forms::post() );
		Forms::finish( $result, __( 'Gespeichert.', 'wp-pass-captain' ), Forms::page_url( self::PAGE ) );
	}

	private static function action_button( $do, $id, $label, $confirm = '' ) {
		Forms::open_form( 'wpcap_webhook_action', null, 'style="display:inline"' );
		Forms::hidden( 'do', $do );
		Forms::hidden( 'id', $id );
		echo '<button type="submit" class="button button-small"' . ( '' !== $confirm ? ' onclick="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ')"' : '' ) . '>' . esc_html( $label ) . '</button></form> ';
	}

	public static function render_admin() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 ); }
		echo '<div class="wrap wpcap-admin"><h1>' . esc_html__( 'Webhooks', 'wp-pass-captain' ) . '</h1>';
		Forms::render_notices();
		echo '<p>' . esc_html( sprintf( /* translators: 1: api version, 2: signature header format (not translated) */ __( 'Ereignisse werden als signierte JSON-POSTs (API-Version %1$s) an HTTPS-Endpunkte gesendet. Signatur: Header X-WPCAP-Signature = %2$s. Fehlgeschlagene Zustellungen werden mit wachsendem Abstand wiederholt; nach wiederholten Fehlern wird der Webhook deaktiviert.', 'wp-pass-captain' ), Dispatcher::API_VERSION, sprintf( 't={%1$s},v1=HMAC-SHA256(Secret, "{t}.{%2$s}")', /* translators: placeholder name for the Unix timestamp in the signature format */ __( 'Zeit', 'wp-pass-captain' ), /* translators: placeholder name for the raw request body in the signature format */ __( 'Body', 'wp-pass-captain' ) ) ) ) . '</p>';
		$subs = Dispatcher::subscriptions();
		echo '<h2>' . esc_html__( 'Abonnements', 'wp-pass-captain' ) . '</h2>';
		if ( ! $subs ) {
			echo '<p>' . esc_html__( 'Noch keine Webhooks.', 'wp-pass-captain' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th scope="col">ID</th><th scope="col">' . esc_html__( 'URL', 'wp-pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Ereignisse', 'wp-pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Status', 'wp-pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Secret', 'wp-pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Aktionen', 'wp-pass-captain' ) . '</th></tr></thead><tbody>';
			foreach ( $subs as $sub ) {
				$status = 'active' === $sub->status ? __( 'aktiv', 'wp-pass-captain' ) : ( 'failures' === $sub->disabled_reason ? __( 'deaktiviert (Fehler)', 'wp-pass-captain' ) : __( 'deaktiviert', 'wp-pass-captain' ) );
				echo '<tr><td>' . (int) $sub->subscription_id . '</td><td><code>' . esc_html( $sub->url ) . '</code>' . ( '' !== $sub->description ? '<br>' . esc_html( $sub->description ) : '' ) . ( (int) $sub->include_pii ? '<br><em>' . esc_html__( 'mit personenbezogenen Daten (E-Mail)', 'wp-pass-captain' ) . '</em>' : '' ) . '</td><td>' . esc_html( str_replace( ',', ', ', $sub->events ) ) . '</td><td>' . esc_html( $status ) . ( (int) $sub->consecutive_failures ? '<br>' . esc_html( sprintf( /* translators: %d: count */ __( '%d Fehler in Folge', 'wp-pass-captain' ), (int) $sub->consecutive_failures ) ) : '' ) . '</td>';
				echo '<td><details><summary>' . esc_html__( 'Anzeigen', 'wp-pass-captain' ) . '</summary><code>' . esc_html( $sub->secret ) . '</code></details></td><td>';
				self::action_button( 'active' === $sub->status ? 'disable' : 'enable', $sub->subscription_id, 'active' === $sub->status ? __( 'Deaktivieren', 'wp-pass-captain' ) : __( 'Aktivieren', 'wp-pass-captain' ) );
				self::action_button( 'rotate', $sub->subscription_id, __( 'Secret erneuern', 'wp-pass-captain' ), __( 'Neues Secret erzeugen? Der Empfänger muss es übernehmen.', 'wp-pass-captain' ) );
				self::action_button( 'delete', $sub->subscription_id, __( 'Löschen', 'wp-pass-captain' ), __( 'Webhook und Zustellprotokoll löschen?', 'wp-pass-captain' ) );
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '<h2>' . esc_html__( 'Neuer Webhook', 'wp-pass-captain' ) . '</h2>';
		Forms::open_form( 'wpcap_webhook_action' );
		Forms::hidden( 'do', 'create' );
		echo '<table class="form-table"><tbody><tr><th scope="row"><label for="wpcap-wh-url">' . esc_html__( 'Endpunkt-URL (HTTPS)', 'wp-pass-captain' ) . '</label></th><td><input type="url" id="wpcap-wh-url" name="url" class="large-text" required pattern="https://.+"></td></tr>';
		echo '<tr><th scope="row"><label for="wpcap-wh-desc">' . esc_html__( 'Beschreibung', 'wp-pass-captain' ) . '</label></th><td><input type="text" id="wpcap-wh-desc" name="description" class="regular-text" maxlength="190"></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Ereignisse', 'wp-pass-captain' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Ereignisse', 'wp-pass-captain' ) . '</legend>';
		foreach ( Dispatcher::EVENTS as $event ) { echo '<label style="display:block"><input type="checkbox" name="events[]" value="' . esc_attr( $event ) . '"> <code>' . esc_html( $event ) . '</code></label>'; }
		echo '</fieldset></td></tr><tr><th scope="row">' . esc_html__( 'Personenbezogene Daten', 'wp-pass-captain' ) . '</th><td><label><input type="checkbox" name="include_pii" value="1"> ' . esc_html__( 'E-Mail-Adressen (Käufer:in, Teilnehmer:in) mitsenden – nur mit Rechtsgrundlage und Auftragsverarbeitungsvertrag', 'wp-pass-captain' ) . '</label></td></tr></tbody></table>';
		submit_button( __( 'Webhook anlegen', 'wp-pass-captain' ) );
		echo '</form>';
		echo '<h2>' . esc_html__( 'Aufbewahrung', 'wp-pass-captain' ) . '</h2>';
		Forms::open_form( 'wpcap_webhook_action' );
		Forms::hidden( 'do', 'settings' );
		echo '<p><label for="wpcap-wh-retention">' . esc_html__( 'Abgeschlossene Zustellungen löschen nach (Tagen)', 'wp-pass-captain' ) . '</label> <input type="number" id="wpcap-wh-retention" name="retention_days" min="1" max="365" value="' . (int) Dispatcher::retention_days() . '"> ';
		submit_button( __( 'Speichern', 'wp-pass-captain' ), 'secondary', 'submit', false );
		echo '</p><p class="description">' . esc_html__( 'Personenbezogene Daten (opt-in) werden nach der letzten Zustellung aus dem Protokoll entfernt; „Erneut senden“ verwendet dann die aktuellen Daten.', 'wp-pass-captain' ) . '</p></form>';
		$deliveries = Dispatcher::deliveries( 0, 50 );
		echo '<h2>' . esc_html__( 'Zustellprotokoll (letzte 50)', 'wp-pass-captain' ) . '</h2>';
		if ( ! $deliveries ) {
			echo '<p>' . esc_html__( 'Noch keine Zustellungen.', 'wp-pass-captain' ) . '</p></div>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th scope="col">#</th><th scope="col">Webhook</th><th scope="col">' . esc_html__( 'Ereignis', 'wp-pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Status', 'wp-pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Versuche', 'wp-pass-captain' ) . '</th><th scope="col">' . esc_html__( 'HTTP-Code', 'wp-pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Nächster Versuch / Zugestellt', 'wp-pass-captain' ) . '</th><th scope="col"></th></tr></thead><tbody>';
		foreach ( $deliveries as $d ) {
			echo '<tr><td>' . (int) $d->delivery_id . '</td><td>' . (int) $d->subscription_id . '</td><td><code>' . esc_html( $d->event_type ) . '</code><br><small>' . esc_html( $d->event_id ) . '</small></td><td>' . esc_html( $d->status ) . ( '' !== $d->last_error ? '<br><small>' . esc_html( $d->last_error ) . '</small>' : '' ) . '</td><td>' . (int) $d->attempts . '</td><td>' . ( (int) $d->response_code ? (int) $d->response_code : '–' ) . '</td><td>' . esc_html( 'succeeded' === $d->status ? (string) $d->delivered_at : ( 'pending' === $d->status ? (string) $d->next_attempt_at : '' ) ) . ' UTC</td><td>';
			if ( 'delivering' !== $d->status ) { self::action_button( 'redeliver', $d->delivery_id, __( 'Erneut senden', 'wp-pass-captain' ) ); }
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}

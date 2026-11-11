<?php
/** Event-scoped waitlist with ordered entries and expiring invitations. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Waitlists {
	public static function join( $event_id, $occurrence_id, $ticket_type_id, $email ) {
		global $wpdb;
		$event_id = absint( $event_id );
		$occurrence_id = absint( $occurrence_id );
		$ticket_type_id = absint( $ticket_type_id );
		$email = sanitize_email( $email );
		if ( ! $event_id || ! is_email( $email ) ) { return new \WP_Error( 'wpcap_waitlist_invalid', __( 'Veranstaltung oder E-Mail-Adresse ist ungültig.', 'wp-pass-captain' ), array( 'status' => 400 ) ); }
		$rate_key = 'wpcap_waitlist_' . hash( 'sha256', $email . '|' . $event_id );
		$attempts = (int) get_transient( $rate_key );
		if ( $attempts >= 5 ) { return new \WP_Error( 'wpcap_waitlist_rate', __( 'Zu viele Wartelistenanfragen. Bitte versuche es später erneut.', 'wp-pass-captain' ), array( 'status' => 429 ) ); }
		set_transient( $rate_key, $attempts + 1, HOUR_IN_SECONDS );
		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$ip_key = 'wpcap_waitlist_ip_' . hash( 'sha256', $ip . '|' . $event_id );
		$ip_attempts = (int) get_transient( $ip_key );
		if ( $ip_attempts >= 20 ) { return new \WP_Error( 'wpcap_waitlist_rate', __( 'Zu viele Wartelistenanfragen. Bitte versuche es später erneut.', 'wp-pass-captain' ), array( 'status' => 429 ) ); }
		set_transient( $ip_key, $ip_attempts + 1, HOUR_IN_SECONDS );
		$valid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_events e INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.event_id=e.event_id WHERE e.event_id=%d AND e.status='published' AND o.occurrence_id=%d AND o.status='scheduled'", $event_id, $occurrence_id ) );
		if ( ! $valid ) { return new \WP_Error( 'wpcap_waitlist_event', __( 'Diese Veranstaltung ist nicht verfügbar.', 'wp-pass-captain' ), array( 'status' => 404 ) ); }
		$type = $wpdb->get_row( $wpdb->prepare( "SELECT ticket_type_id FROM {$wpdb->prefix}wpcap_ticket_types WHERE ticket_type_id=%d AND occurrence_id=%d AND status='active'", $ticket_type_id, $occurrence_id ) );
		if ( ! $type ) { return new \WP_Error( 'wpcap_waitlist_ticket', __( 'Diese Ticketart ist nicht verfügbar.', 'wp-pass-captain' ), array( 'status' => 404 ) ); }
		$table = $wpdb->prefix . 'wpcap_waitlist';
		$wpdb->query( $wpdb->prepare( "UPDATE $table SET status='expired',offer_token_hash='',updated_at=%s WHERE event_id=%d AND ticket_type_id=%d AND status='offered' AND offer_expires_at<=%s", current_time( 'mysql', true ), absint( $event_id ), absint( $ticket_type_id ), current_time( 'mysql', true ) ) );
		$email_hash = hash( 'sha256', strtolower( $email ) );
		$wpdb->query( 'START TRANSACTION' );
		$wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$wpdb->prefix}wpcap_events WHERE event_id=%d FOR UPDATE", $event_id ) );
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT waitlist_id,status,position FROM $table WHERE event_id=%d AND ticket_type_id=%d AND email_hash=%s", $event_id, $ticket_type_id, $email_hash ) );
		if ( $existing ) {
			$wpdb->query( 'COMMIT' );
			// Same response as a new entry so the endpoint does not reveal who is on a waitlist.
			return self::join_response();
		}
		$position = 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(position),0) FROM $table WHERE event_id=%d AND ticket_type_id=%d", $event_id, $ticket_type_id ) );
		$wpdb->insert( $table, array( 'event_id' => $event_id, 'occurrence_id' => $occurrence_id, 'ticket_type_id' => $ticket_type_id, 'email_hash' => $email_hash, 'email' => $email, 'position' => $position, 'status' => 'waiting', 'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ), array( '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s' ) );
		if ( ! $wpdb->insert_id ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_waitlist_join', __( 'Der Wartelistenplatz konnte nicht gespeichert werden.', 'wp-pass-captain' ), array( 'status' => 500 ) ); }
		$wpdb->query( 'COMMIT' );
		/* translators: %d: waitlist position */
		wp_mail( $email, __( 'Du stehst auf der Warteliste', 'wp-pass-captain' ), sprintf( __( 'Du bist auf der Warteliste eingetragen (Position %d). Wir schreiben dir, sobald ein Platz frei wird.', 'wp-pass-captain' ), $position ) );
		return self::join_response();
	}

	/** Uniform public response for waitlist sign-ups. */
	private static function join_response() {
		return array( 'status' => 'received', 'message' => __( 'Danke! Falls die Adresse noch nicht eingetragen war, bekommst du eine Bestätigung per E-Mail.', 'wp-pass-captain' ) );
	}

	/** Mark the next entry as offered. This invitation does not reserve inventory. */
	public static function offer_next( $event_id, $ticket_type_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcap_waitlist';
		$now = current_time( 'mysql', true );
		$wpdb->query( $wpdb->prepare( "UPDATE $table SET status='expired',offer_token_hash='',updated_at=%s WHERE event_id=%d AND ticket_type_id=%d AND status='offered' AND offer_expires_at<=%s", $now, absint( $event_id ), absint( $ticket_type_id ), $now ) );
		$wpdb->query( 'START TRANSACTION' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT waitlist_id,email,position FROM $table WHERE event_id=%d AND ticket_type_id=%d AND status='waiting' ORDER BY position,waitlist_id LIMIT 1 FOR UPDATE", absint( $event_id ), absint( $ticket_type_id ) ) );
		if ( ! $row ) { $wpdb->query( 'COMMIT' ); return new \WP_Error( 'wpcap_waitlist_empty', __( 'Für diese Ticketart gibt es keinen wartenden Eintrag.', 'wp-pass-captain' ), array( 'status' => 404 ) ); }
		$token = bin2hex( random_bytes( 32 ) );
		$expires = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
		$wpdb->update( $table, array( 'status' => 'offered', 'offer_token_hash' => hash( 'sha256', $token ), 'offer_expires_at' => $expires, 'updated_at' => current_time( 'mysql', true ) ), array( 'waitlist_id' => (int) $row->waitlist_id, 'status' => 'waiting' ), array( '%s', '%s', '%s', '%s' ), array( '%d', '%s' ) );
		if ( 1 !== (int) $wpdb->rows_affected ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_waitlist_offer', __( 'Das Angebot konnte nicht erstellt werden.', 'wp-pass-captain' ), array( 'status' => 409 ) ); }
		$wpdb->query( 'COMMIT' );
		$accept_url = add_query_arg( array( 'wpcap_waitlist_accept' => (int) $row->waitlist_id, 'token' => $token ), home_url( '/' ) );
		$sent = wp_mail( $row->email, __( 'Ein Platz könnte verfügbar sein', 'wp-pass-captain' ), sprintf( __( 'Dein Wartelistenangebot läuft am %1$s ab. Mit der Annahme wird das Ticket für dich reserviert, sofern es noch verfügbar ist: %2$s', 'wp-pass-captain' ), wp_date( 'd.m.Y H:i T', strtotime( $expires . ' UTC' ) ), esc_url_raw( $accept_url ) ) );
		if ( ! $sent ) {
			$wpdb->update( $table, array( 'status' => 'waiting', 'offer_token_hash' => '', 'offer_expires_at' => null, 'updated_at' => current_time( 'mysql', true ) ), array( 'waitlist_id' => (int) $row->waitlist_id, 'status' => 'offered', 'offer_token_hash' => hash( 'sha256', $token ) ), array( '%s', '%s', '%s', '%s' ), array( '%d', '%s', '%s' ) );
			return new \WP_Error( 'wpcap_waitlist_mail', __( 'Das Angebot wurde gespeichert, aber die E-Mail konnte nicht gesendet werden.', 'wp-pass-captain' ), array( 'status' => 502 ) );
		}
		return array( 'waitlist_id' => (int) $row->waitlist_id, 'position' => (int) $row->position, 'expires_at' => $expires, 'inventory_reserved' => false );
	}

	public static function maybe_render_offer_page() {
		$waitlist_id = absint( $_GET['wpcap_waitlist_accept'] ?? 0 );
		$token = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );
		if ( ! $waitlist_id || 64 !== strlen( $token ) || ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) { return; }
		nocache_headers();
		if ( ! headers_sent() ) { header( 'Referrer-Policy: no-referrer' ); }
		status_header( 200 );
		?><!doctype html><html lang="<?php echo esc_attr( determine_locale() ); ?>"><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php esc_html_e( 'Wartelistenangebot annehmen', 'wp-pass-captain' ); ?></title></head><body><main><h1><?php esc_html_e( 'Wartelistenangebot annehmen', 'wp-pass-captain' ); ?></h1><p><?php esc_html_e( 'Mit der Annahme wird eine Bestellung angelegt und das Ticket reserviert, sofern es noch verfügbar ist.', 'wp-pass-captain' ); ?></p><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'wpcap_accept_waitlist_' . $waitlist_id ); ?><input type="hidden" name="action" value="wpcap_accept_waitlist"><input type="hidden" name="waitlist_id" value="<?php echo esc_attr( $waitlist_id ); ?>"><input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>"><button type="submit"><?php esc_html_e( 'Angebot annehmen', 'wp-pass-captain' ); ?></button></form></main></body></html><?php
		exit;
	}

	public static function handle_accept_request() {
		$waitlist_id = absint( $_POST['waitlist_id'] ?? 0 );
		check_admin_referer( 'wpcap_accept_waitlist_' . $waitlist_id );
		$result = self::accept_offer( $waitlist_id, wp_unslash( $_POST['token'] ?? '' ) );
		status_header( is_wp_error( $result ) ? 409 : 200 );
		?><!doctype html><html lang="<?php echo esc_attr( determine_locale() ); ?>"><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php esc_html_e( 'WP Pass Captain Warteliste', 'wp-pass-captain' ); ?></title></head><body><main><?php if ( is_wp_error( $result ) ) : ?><h1><?php esc_html_e( 'Angebot nicht verfügbar', 'wp-pass-captain' ); ?></h1><p><?php echo esc_html( $result->get_error_message() ); ?></p><?php else : ?><h1><?php esc_html_e( 'Angebot angenommen', 'wp-pass-captain' ); ?></h1><p><?php echo esc_html( 'complete' === ( $result['order']['status'] ?? '' ) ? __( 'Deine Bestellung ist abgeschlossen. Die Tickets wurden an deine E-Mail-Adresse gesendet.', 'wp-pass-captain' ) : __( 'Dein Ticket ist reserviert und die Bestellung wartet auf die Zahlung. Den Link zu deiner Bestellung und die Zahlungsinformationen haben wir dir per E-Mail gesendet.', 'wp-pass-captain' ) ); ?></p><?php endif; ?></main></body></html><?php
		exit;
	}

	public static function accept_offer( $waitlist_id, $token ) {
		global $wpdb;
		$token = strtolower( preg_replace( '/[^a-f0-9]/', '', (string) $token ) );
		if ( 64 !== strlen( $token ) ) { return new \WP_Error( 'wpcap_waitlist_offer_invalid', __( 'Wartelistenangebot ist ungültig oder abgelaufen.', 'wp-pass-captain' ), array( 'status' => 403 ) ); }
		$table = $wpdb->prefix . 'wpcap_waitlist';
		$wpdb->query( 'START TRANSACTION' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT waitlist_id,email,ticket_type_id,offer_token_hash,offer_expires_at,status FROM $table WHERE waitlist_id=%d FOR UPDATE", absint( $waitlist_id ) ) );
		if ( ! $row || 'offered' !== $row->status || $row->offer_expires_at <= current_time( 'mysql', true ) || ! hash_equals( $row->offer_token_hash, hash( 'sha256', $token ) ) ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_waitlist_offer_invalid', __( 'Wartelistenangebot ist ungültig oder abgelaufen.', 'wp-pass-captain' ), array( 'status' => 403 ) ); }
		$updated = $wpdb->update( $table, array( 'status' => 'accepted', 'offer_token_hash' => '', 'updated_at' => current_time( 'mysql', true ) ), array( 'waitlist_id' => (int) $row->waitlist_id, 'status' => 'offered' ), array( '%s', '%s', '%s' ), array( '%d', '%s' ) );
		if ( 1 !== $updated ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_waitlist_offer_invalid', __( 'Wartelistenangebot konnte nicht angenommen werden.', 'wp-pass-captain' ), array( 'status' => 409 ) ); }
		$wpdb->query( 'COMMIT' );
		// Turn the accepted offer into a real order with an inventory hold.
		$order = Orders::create(
			array( 'email' => $row->email, 'items' => array( array( 'ticket_type_id' => (int) $row->ticket_type_id, 'quantity' => 1 ) ), 'idempotency_key' => (string) (int) $row->waitlist_id ),
			array( 'key_namespace' => 'waitlist', 'skip_rate_limit' => true, 'source' => 'waitlist' )
		);
		if ( is_wp_error( $order ) ) {
			// Keep the offer open until it expires so the person can try again.
			$wpdb->update( $table, array( 'status' => 'offered', 'offer_token_hash' => hash( 'sha256', $token ), 'updated_at' => current_time( 'mysql', true ) ), array( 'waitlist_id' => (int) $row->waitlist_id, 'status' => 'accepted' ), array( '%s', '%s', '%s' ), array( '%d', '%s' ) );
			return new \WP_Error( 'wpcap_waitlist_sold_out', $order->get_error_message(), array( 'status' => 409 ) );
		}
		return array( 'status' => 'accepted', 'order' => $order, 'inventory_reserved' => 'pending' === ( $order['payment_status'] ?? '' ) );
	}
}

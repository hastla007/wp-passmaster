<?php
/** Initial event and order administration screens. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Admin {
	public static function render_page() {
		global $wpdb;
		$events = $wpdb->get_results( "SELECT e.*,o.starts_at,o.timezone,o.location,o.capacity FROM {$wpdb->prefix}wpcap_events e LEFT JOIN {$wpdb->prefix}wpcap_occurrences o ON o.event_id=e.event_id ORDER BY e.created_at DESC LIMIT 100" );
		$orders = current_user_can( 'wpcap_manage_orders' ) ? $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wpcap_orders ORDER BY created_at DESC LIMIT 50" ) : array();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'WP Pass Captain', 'wp-pass-captain' ); ?></h1>
			<p><?php echo esc_html__( 'Verwalte Events, Termine und Ticketkontingente. Neue Events starten als Entwurf.', 'wp-pass-captain' ); ?></p>
			<h2><?php echo esc_html__( 'Event anlegen', 'wp-pass-captain' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wpcap_create_event">
				<?php wp_nonce_field( 'wpcap_create_event' ); ?>
				<table class="form-table"><tbody>
				<tr><th><label for="wpcap-name"><?php esc_html_e( 'Eventname', 'wp-pass-captain' ); ?></label></th><td><input id="wpcap-name" name="name" type="text" class="regular-text" required maxlength="190"></td></tr>
				<tr><th><label for="wpcap-description"><?php esc_html_e( 'Beschreibung', 'wp-pass-captain' ); ?></label></th><td><textarea id="wpcap-description" name="description" class="large-text" rows="4"></textarea></td></tr>
				<tr><th><label for="wpcap-start"><?php esc_html_e( 'Beginn', 'wp-pass-captain' ); ?></label></th><td><input id="wpcap-start" name="starts_at" type="datetime-local" required> <select name="timezone"><?php foreach ( timezone_identifiers_list() as $timezone ) : ?><option value="<?php echo esc_attr( $timezone ); ?>" <?php selected( $timezone, wp_timezone_string() ); ?>><?php echo esc_html( $timezone ); ?></option><?php endforeach; ?></select></td></tr>
				<tr><th><label for="wpcap-end"><?php esc_html_e( 'Ende (optional)', 'wp-pass-captain' ); ?></label></th><td><input id="wpcap-end" name="ends_at" type="datetime-local"></td></tr>
				<tr><th><label for="wpcap-location"><?php esc_html_e( 'Ort', 'wp-pass-captain' ); ?></label></th><td><input id="wpcap-location" name="location" type="text" class="regular-text" maxlength="190"></td></tr>
				<tr><th><label for="wpcap-event-capacity"><?php esc_html_e( 'Gesamtkontingent', 'wp-pass-captain' ); ?></label></th><td><input id="wpcap-event-capacity" name="event_capacity" type="number" min="0" value="0"> <span class="description"><?php esc_html_e( '0 bedeutet unbegrenzt.', 'wp-pass-captain' ); ?></span></td></tr>
				<tr><th><label for="wpcap-type-name"><?php esc_html_e( 'Ticketkategorie', 'wp-pass-captain' ); ?></label></th><td><input id="wpcap-type-name" name="ticket_name" type="text" value="Standard" required maxlength="190"></td></tr>
				<tr><th><label for="wpcap-price"><?php esc_html_e( 'Preis', 'wp-pass-captain' ); ?></label></th><td><input id="wpcap-price" name="price" type="number" min="0" step="0.01" value="0.00" required> <select name="currency"><option value="EUR">EUR</option><option value="USD">USD</option><option value="CHF">CHF</option></select></td></tr>
				<tr><th><label for="wpcap-type-capacity"><?php esc_html_e( 'Ticketkontingent', 'wp-pass-captain' ); ?></label></th><td><input id="wpcap-type-capacity" name="ticket_capacity" type="number" min="0" value="0"></td></tr>
				<tr><th><label for="wpcap-status"><?php esc_html_e( 'Status', 'wp-pass-captain' ); ?></label></th><td><select id="wpcap-status" name="status"><option value="draft"><?php esc_html_e( 'Entwurf', 'wp-pass-captain' ); ?></option><option value="published"><?php esc_html_e( 'Veröffentlicht', 'wp-pass-captain' ); ?></option></select></td></tr>
				</tbody></table>
				<?php submit_button( __( 'Event speichern', 'wp-pass-captain' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Events', 'wp-pass-captain' ); ?></h2>
			<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Event', 'wp-pass-captain' ); ?></th><th><?php esc_html_e( 'Termin', 'wp-pass-captain' ); ?></th><th><?php esc_html_e( 'Ort', 'wp-pass-captain' ); ?></th><th><?php esc_html_e( 'Status', 'wp-pass-captain' ); ?></th></tr></thead><tbody>
			<?php if ( $events ) : foreach ( $events as $event ) : ?><tr><td><?php echo esc_html( $event->name ); ?></td><td><?php echo esc_html( $event->starts_at ? $event->starts_at . ' ' . $event->timezone : '—' ); ?></td><td><?php echo esc_html( $event->location ); ?></td><td><?php echo esc_html( $event->status ); ?></td></tr><?php endforeach; else : ?><tr><td colspan="4"><?php esc_html_e( 'Noch keine Events angelegt.', 'wp-pass-captain' ); ?></td></tr><?php endif; ?>
			</tbody></table>
			<?php if ( current_user_can( 'wpcap_manage_events' ) && $events ) : ?>
			<h2><?php esc_html_e( 'Einlasspersonal zuweisen', 'wp-pass-captain' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="wpcap_assign_staff"><?php wp_nonce_field( 'wpcap_assign_staff' ); ?><label><?php esc_html_e( 'Event', 'wp-pass-captain' ); ?> <select name="event_id" required><?php foreach ( $events as $event ) : ?><option value="<?php echo esc_attr( $event->event_id ); ?>"><?php echo esc_html( $event->name ); ?></option><?php endforeach; ?></select></label> <label><?php esc_html_e( 'WordPress-Benutzer-ID', 'wp-pass-captain' ); ?> <input type="number" name="user_id" min="1" required></label> <button class="button" type="submit"><?php esc_html_e( 'Scannerzugriff zuweisen', 'wp-pass-captain' ); ?></button></form>
			<?php endif; ?>

			<?php if ( current_user_can( 'wpcap_manage_orders' ) ) : ?>
			<h2><?php esc_html_e( 'Bestellungen', 'wp-pass-captain' ); ?></h2>
			<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Bestellung', 'wp-pass-captain' ); ?></th><th><?php esc_html_e( 'E-Mail', 'wp-pass-captain' ); ?></th><th><?php esc_html_e( 'Summe', 'wp-pass-captain' ); ?></th><th><?php esc_html_e( 'Status', 'wp-pass-captain' ); ?></th><th><?php esc_html_e( 'Aktion', 'wp-pass-captain' ); ?></th></tr></thead><tbody>
			<?php if ( $orders ) : foreach ( $orders as $order ) : ?><tr><td><?php echo esc_html( $order->public_id ); ?></td><td><?php echo esc_html( $order->customer_email ); ?></td><td><?php echo esc_html( number_format_i18n( $order->total_minor / 100, 2 ) . ' ' . $order->currency ); ?></td><td><?php echo esc_html( $order->status . ' / ' . $order->payment_status ); ?></td><td><?php if ( 'pending' === $order->payment_status ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="wpcap_confirm_payment"><input type="hidden" name="order_id" value="<?php echo esc_attr( $order->order_id ); ?>"><?php wp_nonce_field( 'wpcap_confirm_payment_' . $order->order_id ); ?><button class="button" type="submit"><?php esc_html_e( 'Manuell als bezahlt markieren', 'wp-pass-captain' ); ?></button></form><?php elseif ( 'paid' === $order->payment_status ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="wpcap_resend_tickets"><input type="hidden" name="order_id" value="<?php echo esc_attr( $order->order_id ); ?>"><?php wp_nonce_field( 'wpcap_resend_tickets_' . $order->order_id ); ?><button class="button" type="submit"><?php esc_html_e( 'Tickets erneut senden', 'wp-pass-captain' ); ?></button></form><?php endif; ?></td></tr><?php endforeach; else : ?><tr><td colspan="5"><?php esc_html_e( 'Noch keine Bestellungen.', 'wp-pass-captain' ); ?></td></tr><?php endif; ?>
			</tbody></table>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function create_event() {
		if ( ! current_user_can( 'wpcap_manage_events' ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 ); }
		check_admin_referer( 'wpcap_create_event' );
		$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$starts = sanitize_text_field( wp_unslash( $_POST['starts_at'] ?? '' ) );
		$timezone = sanitize_text_field( wp_unslash( $_POST['timezone'] ?? wp_timezone_string() ) );
		$ticket_name = sanitize_text_field( wp_unslash( $_POST['ticket_name'] ?? '' ) );
		$currency = strtoupper( sanitize_text_field( wp_unslash( $_POST['currency'] ?? 'EUR' ) ) );
		if ( '' === $name || '' === $starts || '' === $ticket_name || ! in_array( $currency, array( 'EUR', 'USD', 'CHF' ), true ) || ! in_array( $timezone, timezone_identifiers_list(), true ) ) {
			wp_die( esc_html__( 'Bitte prüfe die Eventdaten.', 'wp-pass-captain' ), 400 );
		}
		try {
			$start_date = new \DateTimeImmutable( $starts, new \DateTimeZone( $timezone ) );
			$start_utc = $start_date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			$ends = sanitize_text_field( wp_unslash( $_POST['ends_at'] ?? '' ) );
			$end_utc = '';
			if ( '' !== $ends ) {
				$end_date = new \DateTimeImmutable( $ends, new \DateTimeZone( $timezone ) );
				if ( $end_date <= $start_date ) { throw new \RuntimeException( 'invalid_end' ); }
				$end_utc = $end_date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			}
		} catch ( \Throwable $error ) {
			wp_die( esc_html__( 'Beginn oder Ende des Events ist ungültig.', 'wp-pass-captain' ), 400 );
		}
		$price = (float) str_replace( ',', '.', sanitize_text_field( wp_unslash( $_POST['price'] ?? '0' ) ) );
		if ( $price < 0 || $price > 99999999 ) { wp_die( esc_html__( 'Der Preis ist ungültig.', 'wp-pass-captain' ), 400 ); }
		$now = current_time( 'mysql', true );
		$slug = sanitize_title( $name ) . '-' . strtolower( wp_generate_password( 6, false, false ) );
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		$wpdb->insert( $wpdb->prefix . 'wpcap_events', array(
			'name' => $name,
			'slug' => $slug,
			'description' => wp_kses_post( wp_unslash( $_POST['description'] ?? '' ) ),
			'status' => in_array( $_POST['status'] ?? 'draft', array( 'draft', 'published' ), true ) ? sanitize_key( $_POST['status'] ) : 'draft',
			'created_at' => $now,
			'updated_at' => $now,
		), array( '%s', '%s', '%s', '%s', '%s', '%s' ) );
		$event_id = (int) $wpdb->insert_id;
		if ( ! $event_id ) { $wpdb->query( 'ROLLBACK' ); wp_die( esc_html__( 'Das Event konnte nicht gespeichert werden.', 'wp-pass-captain' ), 500 ); }
		$wpdb->insert( $wpdb->prefix . 'wpcap_occurrences', array(
			'event_id' => $event_id,
			'starts_at' => $start_utc,
			'ends_at' => $end_utc ? $end_utc : null,
			'timezone' => $timezone,
			'location' => sanitize_text_field( wp_unslash( $_POST['location'] ?? '' ) ),
			'capacity' => absint( $_POST['event_capacity'] ?? 0 ),
			'status' => 'scheduled',
		), array( '%d', '%s', '%s', '%s', '%s', '%d', '%s' ) );
		$occurrence_id = (int) $wpdb->insert_id;
		$wpdb->insert( $wpdb->prefix . 'wpcap_ticket_types', array(
			'occurrence_id' => $occurrence_id,
			'name' => $ticket_name,
			'price_minor' => (int) round( $price * 100 ),
			'currency' => $currency,
			'capacity' => absint( $_POST['ticket_capacity'] ?? 0 ),
			'max_per_order' => 10,
			'status' => 'active',
		), array( '%d', '%s', '%d', '%s', '%d', '%d', '%s' ) );
		if ( ! $wpdb->insert_id ) { $wpdb->query( 'ROLLBACK' ); wp_die( esc_html__( 'Die Ticketkategorie konnte nicht gespeichert werden.', 'wp-pass-captain' ), 500 ); }
		$wpdb->query( 'COMMIT' );
		Audit::record( 'event_created', 'event', $event_id, array( 'status' => sanitize_key( $_POST['status'] ?? 'draft' ) ) );
		wp_safe_redirect( add_query_arg( array( 'page' => 'wp-pass-captain', 'created' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function assign_staff() {
		if ( ! current_user_can( 'wpcap_manage_events' ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 ); }
		check_admin_referer( 'wpcap_assign_staff' );
		$event_id = absint( $_POST['event_id'] ?? 0 );
		$user_id = absint( $_POST['user_id'] ?? 0 );
		$capability = sanitize_key( $_POST['capability'] ?? 'wpcap_checkin' );
		$staff_user = get_user_by( 'id', $user_id );
		if ( ! $event_id || ! $staff_user || ! in_array( $capability, array( 'wpcap_checkin' ), true ) ) { wp_die( esc_html__( 'Zuweisung ungültig.', 'wp-pass-captain' ), 400 ); }
		$staff_user->add_cap( 'wpcap_checkin' );
		global $wpdb;
		$wpdb->replace( $wpdb->prefix . 'wpcap_event_staff', array( 'event_id' => $event_id, 'user_id' => $user_id, 'capability' => $capability ), array( '%d', '%d', '%s' ) );
		Audit::record( 'staff_assigned', 'event', $event_id, array( 'user_id' => $user_id, 'capability' => $capability ) );
		wp_safe_redirect( add_query_arg( array( 'page' => 'wp-pass-captain', 'assigned' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function confirm_payment() {
		$order_id = absint( $_POST['order_id'] ?? 0 );
		if ( ! current_user_can( 'wpcap_manage_orders' ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 ); }
		check_admin_referer( 'wpcap_confirm_payment_' . $order_id );
		$result = Orders::confirm_manual_payment( $order_id );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), 409 ); }
		wp_safe_redirect( add_query_arg( array( 'page' => 'wp-pass-captain', 'paid' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function resend_tickets() {
		$order_id = absint( $_POST['order_id'] ?? 0 );
		if ( ! current_user_can( 'wpcap_manage_orders' ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 ); }
		check_admin_referer( 'wpcap_resend_tickets_' . $order_id );
		$result = Orders::resend_tickets( $order_id );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), 409 ); }
		wp_safe_redirect( add_query_arg( array( 'page' => 'wp-pass-captain', 'resent' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}
}

<?php
/** Public event listing and checkout embed. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class PublicEvents {
	public static function register_assets() {
		wp_register_script( 'wpcap-checkout', WPCAP_URL . 'assets/checkout.js', array(), WPCAP_VERSION, true );
		wp_localize_script( 'wpcap-checkout', 'WPCAP_CHECKOUT', array( 'url' => esc_url_raw( rest_url( 'wp-pass-captain/v1/checkout' ) ) ) );
		wp_register_style( 'wpcap-public', WPCAP_URL . 'assets/public.css', array(), WPCAP_VERSION );
		wp_register_script( 'wpcap-scanner', WPCAP_URL . 'assets/scanner.js', array(), WPCAP_VERSION, true );
		wp_localize_script( 'wpcap-scanner', 'WPCAP_SCANNER', array( 'validateUrl' => esc_url_raw( rest_url( 'wp-pass-captain/v1/tickets/validate' ) ), 'checkinUrl' => esc_url_raw( rest_url( 'wp-pass-captain/v1/tickets/check-in' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
	}

	public static function render_shortcode() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT e.name,e.description,o.occurrence_id,o.starts_at,o.timezone,o.location FROM {$wpdb->prefix}wpcap_events e INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.event_id=e.event_id WHERE e.status='published' AND o.status='scheduled' AND o.starts_at >= %s ORDER BY o.starts_at ASC LIMIT 50",
			current_time( 'mysql', true )
		) );
		wp_enqueue_script( 'wpcap-checkout' );
		wp_enqueue_style( 'wpcap-public' );
		ob_start();
		?>
		<div class="wpcap-events">
			<?php if ( ! $rows ) : ?><p><?php esc_html_e( 'Aktuell sind keine Veranstaltungen verfügbar.', 'wp-pass-captain' ); ?></p><?php endif; ?>
			<?php foreach ( $rows as $event ) :
				$types = $wpdb->get_results( $wpdb->prepare( "SELECT ticket_type_id,name,price_minor,currency,max_per_order FROM {$wpdb->prefix}wpcap_ticket_types WHERE occurrence_id=%d AND status='active' ORDER BY ticket_type_id", $event->occurrence_id ) );
				if ( ! $types ) { continue; }
				?>
				<article class="wpcap-event">
					<h3><?php echo esc_html( $event->name ); ?></h3>
					<?php if ( $event->description ) : ?><div class="wpcap-description"><?php echo wp_kses_post( wpautop( $event->description ) ); ?></div><?php endif; ?>
					<p><?php echo esc_html( wp_date( 'd.m.Y H:i', strtotime( $event->starts_at . ' UTC' ), new \DateTimeZone( $event->timezone ) ) . ' ' . $event->timezone ); ?><?php if ( $event->location ) : ?> · <?php echo esc_html( $event->location ); ?><?php endif; ?></p>
					<form class="wpcap-checkout-form">
						<label><?php esc_html_e( 'Ticket', 'wp-pass-captain' ); ?> <select name="ticket_type_id" required><?php foreach ( $types as $type ) : ?><option value="<?php echo esc_attr( $type->ticket_type_id ); ?>" data-max="<?php echo esc_attr( $type->max_per_order ); ?>"><?php echo esc_html( $type->name . ' — ' . number_format_i18n( $type->price_minor / 100, 2 ) . ' ' . $type->currency ); ?></option><?php endforeach; ?></select></label>
						<label><?php esc_html_e( 'Anzahl', 'wp-pass-captain' ); ?> <input name="quantity" type="number" min="1" max="10" value="1" required></label>
						<label><?php esc_html_e( 'E-Mail', 'wp-pass-captain' ); ?> <input name="email" type="email" autocomplete="email" required></label>
						<button type="submit"><?php esc_html_e( 'Ticket bestellen', 'wp-pass-captain' ); ?></button>
						<p class="wpcap-message" role="status" aria-live="polite"></p>
					</form>
				</article>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function render_scanner_shortcode() {
		if ( ! is_user_logged_in() || ! current_user_can( 'wpcap_checkin' ) ) {
			return '<p>' . esc_html__( 'Für den Scanner ist eine Anmeldung mit Einlassberechtigung erforderlich.', 'wp-pass-captain' ) . '</p>';
		}
		wp_enqueue_script( 'wpcap-scanner' );
		wp_enqueue_style( 'wpcap-public' );
		return '<section class="wpcap-scanner"><h2>' . esc_html__( 'Pass Captain Check-in', 'wp-pass-captain' ) . '</h2><p>' . esc_html__( 'Scanne den Ticketcode oder gib ihn manuell ein.', 'wp-pass-captain' ) . '</p><video class="wpcap-camera" playsinline hidden></video><button type="button" class="wpcap-camera-start">' . esc_html__( 'Kamera starten', 'wp-pass-captain' ) . '</button><form class="wpcap-scan-form"><label>' . esc_html__( 'Ticket-Code', 'wp-pass-captain' ) . ' <input name="token" type="text" inputmode="text" autocomplete="off" required></label><button type="submit">' . esc_html__( 'Ticket prüfen und einchecken', 'wp-pass-captain' ) . '</button></form><p class="wpcap-scan-result" role="status" aria-live="polite"></p></section>';
	}
}

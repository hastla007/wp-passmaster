<?php
/** Private ticket display page keyed by a high-entropy ticket secret. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TicketPage {
	public static function register() {
		add_filter( 'query_vars', static function ( $vars ) { $vars[] = 'wpcap_ticket'; return $vars; } );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ) );
		OrderPortal::register();
	}

	public static function maybe_render() {
		$token = get_query_var( 'wpcap_ticket' );
		if ( ! $token ) { return; }
		$token = strtolower( preg_replace( '/[^a-f0-9]/', '', (string) $token ) );
		if ( 64 !== strlen( $token ) ) { status_header( 404 ); exit; }
		global $wpdb;
		$ticket = $wpdb->get_row( $wpdb->prepare(
			"SELECT t.ticket_id,t.status,tt.name AS ticket_name,o.starts_at,o.timezone,o.location,e.name AS event_name FROM {$wpdb->prefix}wpcap_tickets t INNER JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$wpdb->prefix}wpcap_events e ON e.event_id=o.event_id WHERE t.token_hash=%s LIMIT 1",
			hash( 'sha256', $token )
		) );
		if ( ! $ticket ) { status_header( 404 ); exit; }
		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Referrer-Policy: no-referrer' );
		$qr = QrCode::data_uri( $token );
		$seat = (string) apply_filters( 'wpcap_ticket_seat_label', '', (int) $ticket->ticket_id, (array) $ticket );
		?><!doctype html><html lang="<?php echo esc_attr( str_replace( '_', '-', determine_locale() ) ); ?>"><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html( $ticket->event_name ); ?> — <?php esc_html_e( 'Ticket', 'pass-captain' ); ?></title><style>body{font:16px/1.5 system-ui,sans-serif;max-width:42rem;margin:2rem auto;padding:0 1rem;color:#1d2327}.ticket{border:1px solid #c3c4c7;border-radius:12px;padding:1.5rem}.qr{display:block;width:min(75vw,320px);height:auto;margin:1.5rem auto}.token{overflow-wrap:anywhere;font-family:monospace}.print{padding:.6rem 1rem}@media print{.print{display:none}.ticket{border:0}}</style></head><body><main class="ticket"><h1><?php echo esc_html( $ticket->event_name ); ?></h1><h2><?php echo esc_html( $ticket->ticket_name ); ?></h2><p><?php echo esc_html( Orders::format_start( $ticket->starts_at, $ticket->timezone ) ); ?></p><?php if ( '' !== $seat ) : ?><p><strong><?php echo esc_html( $seat ); ?></strong></p><?php endif; ?><?php if ( $ticket->location ) : ?><p><?php echo esc_html( $ticket->location ); ?></p><?php endif; ?><p><?php echo esc_html( __( 'Status:', 'pass-captain' ) . ' ' . Admin\Forms::status_label( $ticket->status ) ); ?></p><?php if ( $qr ) : ?><img class="qr" src="<?php echo esc_attr( $qr ); ?>" alt="<?php esc_attr_e( 'Ticket QR-Code', 'pass-captain' ); ?>"><?php endif; ?><p class="token"><?php echo esc_html( $token ); ?></p><button class="print" onclick="window.print()"><?php esc_html_e( 'Drucken oder als PDF speichern', 'pass-captain' ); ?></button></main></body></html><?php
		exit;
	}
}

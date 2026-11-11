<?php
/** Theme-overridable event card. Copy to your-theme/wp-pass-captain/event-card.php. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<article class="wpcap-event">
	<h3><?php echo esc_html( $event->name ); ?></h3>
	<?php if ( $event->description ) : ?><div class="wpcap-description"><?php echo wp_kses_post( wpautop( $event->description ) ); ?></div><?php endif; ?>
	<p><?php echo esc_html( wp_date( 'd.m.Y H:i', strtotime( $event->starts_at . ' UTC' ), new \DateTimeZone( $event->timezone ) ) . ' ' . $event->timezone ); ?><?php if ( $event->location ) : ?> · <?php echo esc_html( $event->location ); ?><?php endif; ?></p>
	<form class="wpcap-checkout-form">
		<label><?php esc_html_e( 'Ticket', 'wp-pass-captain' ); ?> <select name="ticket_type_id" required><?php foreach ( $types as $type ) : ?><option value="<?php echo esc_attr( $type->ticket_type_id ); ?>" data-max="<?php echo esc_attr( $type->max_per_order ); ?>"><?php echo esc_html( $type->name . ' — ' . number_format_i18n( $type->price_minor / 100, 2 ) . ' ' . $type->currency ); ?></option><?php endforeach; ?></select></label>
		<label><?php esc_html_e( 'Anzahl', 'wp-pass-captain' ); ?> <input name="quantity" type="number" min="1" max="10" value="1" required></label>
		<label><?php esc_html_e( 'E-Mail', 'wp-pass-captain' ); ?> <input name="email" type="email" autocomplete="email" required></label>
		<?php if ( apply_filters( 'wpcap_checkout_partner_field', false ) ) : ?>
		<label><?php esc_html_e( 'Partnercode (optional)', 'wp-pass-captain' ); ?> <input name="partner_code" type="text" autocomplete="off" maxlength="64" pattern="[A-Za-z0-9_\-]*"></label>
		<?php endif; ?>
		<button type="submit"><?php esc_html_e( 'Ticket bestellen', 'wp-pass-captain' ); ?></button>
		<p class="wpcap-message" role="status" aria-live="polite"></p>
	</form>
</article>

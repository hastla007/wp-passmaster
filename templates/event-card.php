<?php
/**
 * Theme-overridable event card. Copy to your-theme/wp-pass-captain/event-card.php.
 *
 * Available: $event, $types (with ->available, ->fields), $providers (id => gateway), $legal.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$form_id = 'wpcap-' . (int) $event->occurrence_id;
?>
<article class="wpcap-event">
	<h3><?php echo esc_html( $event->name ); ?></h3>
	<?php if ( $event->description ) : ?><div class="wpcap-description"><?php echo wp_kses_post( wpautop( $event->description ) ); ?></div><?php endif; ?>
	<p><?php echo esc_html( wp_date( 'd.m.Y H:i', strtotime( $event->starts_at . ' UTC' ), new \DateTimeZone( $event->timezone ) ) . ' ' . $event->timezone ); ?><?php if ( $event->location ) : ?> · <?php echo esc_html( $event->location ); ?><?php endif; ?></p>
	<form class="wpcap-checkout-form" novalidate>
		<fieldset class="wpcap-types">
			<legend><?php esc_html_e( 'Tickets', 'wp-pass-captain' ); ?></legend>
			<?php foreach ( $types as $type ) :
				$max = null === $type->available ? (int) $type->max_per_order : min( (int) $type->max_per_order, (int) $type->available );
				$price = number_format_i18n( $type->price_minor / 100, 2 ) . ' ' . $type->currency;
				$base_price = isset( $type->base_price_minor ) && (int) $type->base_price_minor !== (int) $type->price_minor ? number_format_i18n( $type->base_price_minor / 100, 2 ) . ' ' . $type->currency : '';
				?>
				<div class="wpcap-type">
					<label for="<?php echo esc_attr( $form_id . '-' . $type->ticket_type_id ); ?>"><?php echo esc_html( $type->name ); ?> — <?php echo esc_html( $price ); ?><?php if ( ! empty( $type->price_label ) ) : ?> <span class="wpcap-price-label"><?php echo esc_html( $type->price_label ); ?></span><?php endif; ?><?php if ( '' !== $base_price ) : ?> <small class="wpcap-base-price"><?php echo esc_html( sprintf( /* translators: %s: regular price */ __( 'statt %s', 'wp-pass-captain' ), $base_price ) ); ?></small><?php endif; ?><?php if ( (int) $type->tax_rate_bp ) : ?> <small><?php echo esc_html( sprintf( /* translators: %s: VAT rate */ __( 'inkl. %s %% USt.', 'wp-pass-captain' ), number_format_i18n( $type->tax_rate_bp / 100, 2 ) ) ); ?></small><?php endif; ?></label>
					<?php if ( $max < 1 ) : ?>
						<span class="wpcap-soldout"><?php esc_html_e( 'Ausverkauft', 'wp-pass-captain' ); ?></span>
					<?php else : ?>
						<input id="<?php echo esc_attr( $form_id . '-' . $type->ticket_type_id ); ?>" class="wpcap-qty" type="number" min="0" max="<?php echo esc_attr( $max ); ?>" value="0" inputmode="numeric" data-type="<?php echo esc_attr( $type->ticket_type_id ); ?>" data-price="<?php echo esc_attr( (int) $type->price_minor ); ?>" data-currency="<?php echo esc_attr( $type->currency ); ?>" data-name="<?php echo esc_attr( $type->name ); ?>" data-fields="<?php echo esc_attr( wp_json_encode( $type->fields ) ); ?>">
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</fieldset>
		<p class="wpcap-estimate" aria-live="polite"></p>
		<div class="wpcap-attendees" aria-live="polite"></div>
		<?php do_action( 'wpcap_checkout_form_fields', $event, $types ); ?>
		<label><?php esc_html_e( 'E-Mail', 'wp-pass-captain' ); ?> <input name="email" type="email" autocomplete="email" required></label>
		<label><?php esc_html_e( 'Rabattcode (optional)', 'wp-pass-captain' ); ?> <input name="campaign_code" type="text" autocomplete="off" maxlength="40"></label>
		<?php if ( \PassCaptain\Integrations\Vouchers\Bridge::available() ) : ?>
			<label><?php esc_html_e( 'Gutscheincode (optional)', 'wp-pass-captain' ); ?> <input name="voucher_code" type="text" autocomplete="off" maxlength="64"></label>
		<?php endif; ?>
		<?php if ( count( $providers ) > 1 ) : ?>
			<fieldset class="wpcap-providers"><legend><?php esc_html_e( 'Zahlungsart', 'wp-pass-captain' ); ?></legend>
				<?php $first = true; foreach ( $providers as $provider_id => $gateway ) : ?>
					<label><input type="radio" name="provider" value="<?php echo esc_attr( $provider_id ); ?>" <?php checked( $first ); ?>> <?php echo esc_html( $gateway->title() ); ?></label>
				<?php $first = false; endforeach; ?>
			</fieldset>
		<?php elseif ( $providers ) : ?>
			<input type="hidden" name="provider" value="<?php echo esc_attr( array_key_first( $providers ) ); ?>">
		<?php endif; ?>
		<?php if ( '' !== trim( $legal['consumer_info_text'] ) ) : ?>
			<details class="wpcap-legal"><summary><?php esc_html_e( 'Verbraucherinformationen', 'wp-pass-captain' ); ?></summary><?php echo wp_kses_post( wpautop( $legal['consumer_info_text'] ) ); ?></details>
		<?php endif; ?>
		<?php if ( $legal['require_terms'] ) : ?>
			<?php if ( '' !== trim( $legal['terms_text'] ) ) : ?><details class="wpcap-legal"><summary><?php esc_html_e( 'Bedingungen', 'wp-pass-captain' ); ?></summary><?php echo wp_kses_post( wpautop( $legal['terms_text'] ) ); ?></details><?php endif; ?>
			<label class="wpcap-terms"><input type="checkbox" name="accept_terms" value="1" required> <?php esc_html_e( 'Ich akzeptiere die Bedingungen und habe die Verbraucherinformationen gelesen.', 'wp-pass-captain' ); ?></label>
		<?php endif; ?>
		<button type="submit"><?php esc_html_e( 'Zahlungspflichtig bestellen', 'wp-pass-captain' ); ?></button>
		<p class="wpcap-message" role="status" aria-live="polite"></p>
	</form>
</article>

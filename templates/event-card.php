<?php
/**
 * Theme-overridable event card. Copy to your-theme/wp-pass-captain/event-card.php.
 *
 * Available: $event, $types (with ->available, ->fields), $providers (id => gateway), $legal.
 * The checkout script relies on the class names, the name attributes and the field wrappers
 * (.wpcap-field) for inline error messages.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$form_id = 'wpcap-' . (int) $event->occurrence_id;
$wpcap_level = min( 6, max( 2, (int) apply_filters( 'wpcap_event_card_heading_level', 2 ) ) );
$wpcap_heading = 'h' . $wpcap_level;
$wpcap_has_consumer_info = '' !== trim( $legal['consumer_info_text'] );
?>
<article class="wpcap-event">
	<<?php echo $wpcap_heading; // phpcs:ignore WordPress.Security.EscapeOutput -- fixed tag name. ?>><?php echo esc_html( $event->name ); ?></<?php echo $wpcap_heading; // phpcs:ignore WordPress.Security.EscapeOutput -- fixed tag name. ?>>
	<?php if ( $event->description ) : ?><div class="wpcap-description"><?php echo wp_kses_post( wpautop( $event->description ) ); ?></div><?php endif; ?>
	<p><?php echo esc_html( \PassCaptain\Frontend\Format::event_start( $event->starts_at, $event->timezone ) ); ?><?php if ( $event->location ) : ?> · <?php echo esc_html( $event->location ); ?><?php endif; ?></p>
	<form class="wpcap-checkout-form" id="<?php echo esc_attr( $form_id ); ?>" data-heading-level="<?php echo esc_attr( min( 6, $wpcap_level + 1 ) ); ?>" novalidate>
		<fieldset class="wpcap-types">
			<legend><?php esc_html_e( 'Tickets', 'wp-pass-captain' ); ?></legend>
			<?php foreach ( $types as $type ) :
				$max = null === $type->available ? (int) $type->max_per_order : min( (int) $type->max_per_order, (int) $type->available );
				$price = \PassCaptain\Frontend\Format::money( $type->price_minor, $type->currency );
				$base_price = isset( $type->base_price_minor ) && (int) $type->base_price_minor !== (int) $type->price_minor ? \PassCaptain\Frontend\Format::money( $type->base_price_minor, $type->currency ) : '';
				?>
				<div class="wpcap-type wpcap-field">
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
		<div class="wpcap-field">
			<label for="<?php echo esc_attr( $form_id . '-email' ); ?>"><?php esc_html_e( 'Deine E-Mail-Adresse (für Tickets und Bestätigung)', 'wp-pass-captain' ); ?> *</label>
			<input id="<?php echo esc_attr( $form_id . '-email' ); ?>" name="email" type="email" autocomplete="email" required>
		</div>
		<div class="wpcap-attendees" aria-live="polite"></div>
		<?php do_action( 'wpcap_checkout_form_fields', $event, $types ); ?>
		<div class="wpcap-field">
			<label for="<?php echo esc_attr( $form_id . '-campaign' ); ?>"><?php esc_html_e( 'Rabattcode (optional)', 'wp-pass-captain' ); ?></label>
			<input id="<?php echo esc_attr( $form_id . '-campaign' ); ?>" name="campaign_code" type="text" autocomplete="off" maxlength="40">
		</div>
		<?php if ( \PassCaptain\Integrations\Vouchers\Bridge::available() ) : ?>
			<div class="wpcap-field">
				<label for="<?php echo esc_attr( $form_id . '-voucher' ); ?>"><?php esc_html_e( 'Gutscheincode (optional)', 'wp-pass-captain' ); ?></label>
				<input id="<?php echo esc_attr( $form_id . '-voucher' ); ?>" name="voucher_code" type="text" autocomplete="off" maxlength="64">
			</div>
		<?php endif; ?>
		<?php if ( count( $providers ) > 1 ) : ?>
			<fieldset class="wpcap-providers wpcap-field"><legend><?php esc_html_e( 'Zahlungsart', 'wp-pass-captain' ); ?></legend>
				<?php $first = true; foreach ( $providers as $provider_id => $gateway ) : ?>
					<label><input type="radio" name="provider" value="<?php echo esc_attr( $provider_id ); ?>" <?php checked( $first ); ?>> <?php echo esc_html( $gateway->title() ); ?></label>
				<?php $first = false; endforeach; ?>
			</fieldset>
		<?php elseif ( $providers ) : ?>
			<input type="hidden" name="provider" value="<?php echo esc_attr( array_key_first( $providers ) ); ?>">
		<?php endif; ?>
		<?php if ( $wpcap_has_consumer_info ) : ?>
			<details class="wpcap-legal"><summary><?php esc_html_e( 'Verbraucherinformationen', 'wp-pass-captain' ); ?></summary><?php echo wp_kses_post( wpautop( $legal['consumer_info_text'] ) ); ?></details>
		<?php endif; ?>
		<?php if ( $legal['require_terms'] ) : ?>
			<?php if ( '' !== trim( $legal['terms_text'] ) ) : ?><details class="wpcap-legal"><summary><?php esc_html_e( 'Bedingungen', 'wp-pass-captain' ); ?></summary><?php echo wp_kses_post( wpautop( $legal['terms_text'] ) ); ?></details><?php endif; ?>
			<div class="wpcap-field wpcap-terms">
				<input id="<?php echo esc_attr( $form_id . '-terms' ); ?>" type="checkbox" name="accept_terms" value="1" required>
				<label for="<?php echo esc_attr( $form_id . '-terms' ); ?>"><?php echo esc_html( $wpcap_has_consumer_info ? __( 'Ich akzeptiere die Bedingungen und habe die Verbraucherinformationen gelesen.', 'wp-pass-captain' ) : __( 'Ich akzeptiere die Bedingungen.', 'wp-pass-captain' ) ); ?></label>
			</div>
		<?php endif; ?>
		<button type="submit" class="wp-element-button wpcap-submit"><?php esc_html_e( 'Zahlungspflichtig bestellen', 'wp-pass-captain' ); ?></button>
		<p class="wpcap-message" role="status" aria-live="polite"></p>
	</form>
</article>

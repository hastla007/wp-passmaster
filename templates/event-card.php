<?php
/**
 * Theme-overridable event card. Copy to your-theme/pass-captain/event-card.php.
 *
 * Available: $event, $types (with ->available, ->fields), $providers (id => gateway), $legal
 * (require_terms, terms_text, consumer_info_text, locale, terms_version; texts in the page language) and
 * $card (Frontend\EventCard::context(): badges, seats left, price, other dates, next date, limits).
 * The checkout script relies on the class names, the name attributes and the field wrappers
 * (.wpcap-field) for inline error messages.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$form_id = 'wpcap-' . (int) $event->occurrence_id;
$wpcap_level = min( 6, max( 2, (int) apply_filters( 'wpcap_event_card_heading_level', 2 ) ) );
$wpcap_heading = 'h' . $wpcap_level;
$wpcap_has_consumer_info = '' !== trim( $legal['consumer_info_text'] );
// Sold-out ticket types (offered on the waitlist) and whether nothing is left to buy.
$wpcap_soldout = array();
foreach ( $types as $wpcap_type ) {
	if ( null !== $wpcap_type->available && (int) $wpcap_type->available < 1 ) { $wpcap_soldout[] = $wpcap_type; }
}
$wpcap_all_soldout = $types && count( $wpcap_soldout ) === count( $types );
$wpcap_waitlist = $wpcap_soldout && (bool) apply_filters( 'wpcap_waitlist_public_enabled', true, $event );
if ( ! isset( $card ) || ! is_array( $card ) ) { $card = \PassCaptain\Frontend\EventCard::context( $event, $types ); }
$wpcap_doors = ! empty( $event->doors_open_at ) ? \PassCaptain\DoorsOpen::label( $event->doors_open_at, $event->timezone, $event->starts_at ) : '';
$wpcap_visible = (int) $card['visible_types'];
$wpcap_collapse = count( $types ) > $wpcap_visible + 1; // Never collapse a single remaining type.
?>
<article class="wpcap-event">
	<header class="wpcap-event-header<?php echo $card['next'] ? ' wpcap-is-next' : ''; ?>">
		<<?php echo $wpcap_heading; // phpcs:ignore WordPress.Security.EscapeOutput -- fixed tag name. ?> class="wpcap-event-title"><?php echo esc_html( $event->name ); ?></<?php echo $wpcap_heading; // phpcs:ignore WordPress.Security.EscapeOutput -- fixed tag name. ?>>
		<?php echo \PassCaptain\Frontend\EventCard::render_badges( $card['badges'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_badges(). ?>
		<dl class="wpcap-event-facts">
			<div class="wpcap-fact-date"><dt class="wpcap-sr-only"><?php esc_html_e( 'Termin', 'pass-captain' ); ?></dt><dd><time datetime="<?php echo esc_attr( \PassCaptain\Frontend\EventCard::iso( $event->starts_at ) ); ?>"><?php echo esc_html( \PassCaptain\Frontend\Format::event_start( $event->starts_at, $event->timezone ) ); ?></time></dd></div>
			<?php if ( $event->location ) : ?><div class="wpcap-fact-location"><dt class="wpcap-sr-only"><?php esc_html_e( 'Ort', 'pass-captain' ); ?></dt><dd><?php echo esc_html( $event->location ); ?></dd></div><?php endif; ?>
			<?php if ( '' !== $wpcap_doors ) : ?><div class="wpcap-fact-doors"><dt class="wpcap-sr-only"><?php esc_html_e( 'Einlass', 'pass-captain' ); ?></dt><dd><?php echo esc_html( $wpcap_doors ); ?></dd></div><?php endif; ?>
			<?php if ( '' !== $card['price'] ) : ?><div class="wpcap-fact-price"><dt class="wpcap-sr-only"><?php esc_html_e( 'Preis', 'pass-captain' ); ?></dt><dd><?php echo esc_html( $card['price'] ); ?></dd></div><?php endif; ?>
			<div class="wpcap-fact-availability"><dt class="wpcap-sr-only"><?php esc_html_e( 'Verfügbarkeit', 'pass-captain' ); ?></dt><dd><?php echo esc_html( $card['availability'] ); ?></dd></div>
		</dl>
	</header>
	<?php if ( $card['dates'] ) : ?>
		<details class="wpcap-dates">
			<summary><?php echo esc_html( sprintf( /* translators: %d: number of other dates */ __( 'Weitere Termine (%d)', 'pass-captain' ), count( $card['dates'] ) ) ); ?></summary>
			<ul>
				<?php foreach ( $card['dates'] as $wpcap_date ) : ?>
					<li><?php if ( $wpcap_date['linked'] ) : ?><a href="#wpcap-occ-<?php echo esc_attr( $wpcap_date['occurrence_id'] ); ?>"><time datetime="<?php echo esc_attr( $wpcap_date['iso'] ); ?>"><?php echo esc_html( $wpcap_date['label'] ); ?></time></a><?php else : ?><time datetime="<?php echo esc_attr( $wpcap_date['iso'] ); ?>"><?php echo esc_html( $wpcap_date['label'] ); ?></time><?php endif; ?> <?php echo \PassCaptain\Frontend\EventCard::render_badges( $wpcap_date['badges'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_badges(). ?></li>
				<?php endforeach; ?>
			</ul>
		</details>
	<?php endif; ?>
	<?php if ( $event->description ) : ?><div class="wpcap-description"><?php echo wp_kses_post( wpautop( $event->description ) ); ?></div><?php endif; ?>
	<form class="wpcap-checkout-form" id="<?php echo esc_attr( $form_id ); ?>" data-heading-level="<?php echo esc_attr( min( 6, $wpcap_level + 1 ) ); ?>" novalidate>
		<fieldset class="wpcap-types"<?php echo $card['max_total'] ? ' data-max-total="' . esc_attr( $card['max_total'] ) . '"' : ''; ?>>
			<legend><?php esc_html_e( 'Tickets', 'pass-captain' ); ?></legend>
			<?php foreach ( array_values( $types ) as $wpcap_index => $type ) :
				$max = \PassCaptain\Frontend\EventCard::quantity_max( $type, $event );
				if ( $wpcap_collapse && $wpcap_index === $wpcap_visible ) :
					?>
					<details class="wpcap-more-types"><summary><?php echo esc_html( sprintf( /* translators: %d: number of further ticket types */ __( 'Weitere Ticketarten (%d)', 'pass-captain' ), count( $types ) - $wpcap_visible ) ); ?></summary>
				<?php endif;
				$price = \PassCaptain\Frontend\Format::money( $type->price_minor, $type->currency );
				$base_price = isset( $type->base_price_minor ) && (int) $type->base_price_minor !== (int) $type->price_minor ? \PassCaptain\Frontend\Format::money( $type->base_price_minor, $type->currency ) : '';
				?>
				<div class="wpcap-type wpcap-field">
					<label for="<?php echo esc_attr( $form_id . '-' . $type->ticket_type_id ); ?>"><?php echo esc_html( $type->name ); ?> — <?php echo esc_html( $price ); ?><?php if ( ! empty( $type->price_label ) ) : ?> <span class="wpcap-price-label"><?php echo esc_html( $type->price_label ); ?></span><?php endif; ?><?php if ( '' !== $base_price ) : ?> <small class="wpcap-base-price"><?php echo esc_html( sprintf( /* translators: %s: regular price */ __( 'statt %s', 'pass-captain' ), $base_price ) ); ?></small><?php endif; ?><?php if ( (int) $type->tax_rate_bp ) : ?> <small><?php echo esc_html( sprintf( /* translators: %s: VAT rate */ __( 'inkl. %s %% USt.', 'pass-captain' ), number_format_i18n( $type->tax_rate_bp / 100, 2 ) ) ); ?></small><?php endif; ?><?php $wpcap_notice = \PassCaptain\AdmissionChecks::buyer_notice( \PassCaptain\AdmissionChecks::for_type( $type ) ); ?><?php if ( '' !== $wpcap_notice ) : ?> <small class="wpcap-age-notice"><?php echo esc_html( $wpcap_notice ); ?></small><?php endif; ?></label>
					<?php if ( $max < 1 ) : ?>
						<span class="wpcap-soldout"><?php esc_html_e( 'Ausverkauft', 'pass-captain' ); ?></span>
					<?php else : ?>
						<input id="<?php echo esc_attr( $form_id . '-' . $type->ticket_type_id ); ?>" class="wpcap-qty" type="number" min="0" max="<?php echo esc_attr( $max ); ?>" value="0" inputmode="numeric" data-type="<?php echo esc_attr( $type->ticket_type_id ); ?>" data-price="<?php echo esc_attr( (int) $type->price_minor ); ?>" data-currency="<?php echo esc_attr( $type->currency ); ?>" data-name="<?php echo esc_attr( $type->name ); ?>" data-fields="<?php echo esc_attr( wp_json_encode( $type->fields ) ); ?>">
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
			<?php if ( $wpcap_collapse ) : ?></details><?php endif; ?>
		</fieldset>
		<p class="wpcap-qty-live wpcap-sr-only" aria-live="polite" aria-atomic="true"></p>
		<?php if ( $wpcap_all_soldout ) : ?>
			<p class="wpcap-soldout-note"><?php esc_html_e( 'Dieser Termin ist ausverkauft.', 'pass-captain' ); ?></p>
		<?php else : ?>
		<p class="wpcap-estimate" aria-live="polite"></p>
		<div class="wpcap-field">
			<label for="<?php echo esc_attr( $form_id . '-email' ); ?>"><?php esc_html_e( 'Deine E-Mail-Adresse (für Tickets und Bestätigung)', 'pass-captain' ); ?> *</label>
			<input id="<?php echo esc_attr( $form_id . '-email' ); ?>" name="email" type="email" autocomplete="email" required>
		</div>
		<div class="wpcap-attendees" aria-live="polite"></div>
		<?php do_action( 'wpcap_checkout_form_fields', $event, $types ); ?>
		<?php if ( apply_filters( 'wpcap_checkout_billing_enabled', true, $event ) ) :
			$wpcap_billing_id = $form_id . '-billing';
			$wpcap_billing_labels = \PassCaptain\Billing::labels();
			$wpcap_billing_country = \PassCaptain\Billing::default_country();
			$wpcap_billing_threshold = \PassCaptain\Billing::required_threshold( $types ? $types[0]->currency : 'EUR' );
			$wpcap_billing_text = array(
				'company' => array( 'organization', false ),
				'first_name' => array( 'given-name', true ),
				'last_name' => array( 'family-name', true ),
				'street' => array( 'street-address', true ),
				'postcode' => array( 'postal-code', true ),
				'city' => array( 'address-level2', true ),
			);
			?>
			<fieldset class="wpcap-billing">
				<legend><?php esc_html_e( 'Rechnung mit Firmen-/Adressdaten', 'pass-captain' ); ?></legend>
				<div class="wpcap-field wpcap-billing-toggle">
					<input id="<?php echo esc_attr( $wpcap_billing_id . '-enabled' ); ?>" type="checkbox" name="billing_enabled" value="1" aria-controls="<?php echo esc_attr( $wpcap_billing_id . '-fields' ); ?>"<?php echo $wpcap_billing_threshold ? ' aria-describedby="' . esc_attr( $wpcap_billing_id . '-hint' ) . '"' : ''; ?>>
					<label for="<?php echo esc_attr( $wpcap_billing_id . '-enabled' ); ?>"><?php echo esc_html( $wpcap_billing_labels['enabled'] ); ?></label>
				</div>
				<?php if ( $wpcap_billing_threshold ) : ?>
					<p class="wpcap-billing-hint" id="<?php echo esc_attr( $wpcap_billing_id . '-hint' ); ?>"><small><?php echo esc_html( sprintf( /* translators: %s: amount */ __( 'Ab einem Bestellbetrag über %s brauchen wir für die Rechnung Name und Anschrift.', 'pass-captain' ), \PassCaptain\Frontend\Format::money( $wpcap_billing_threshold, $types ? $types[0]->currency : 'EUR' ) ) ); ?></small></p>
				<?php endif; ?>
				<div class="wpcap-billing-fields" id="<?php echo esc_attr( $wpcap_billing_id . '-fields' ); ?>" hidden>
					<?php foreach ( $wpcap_billing_text as $wpcap_key => $wpcap_def ) : ?>
						<div class="wpcap-field">
							<label for="<?php echo esc_attr( $wpcap_billing_id . '-' . $wpcap_key ); ?>"><?php echo esc_html( $wpcap_billing_labels[ $wpcap_key ] . ( $wpcap_def[1] ? ' *' : ' ' . __( '(optional)', 'pass-captain' ) ) ); ?></label>
							<input id="<?php echo esc_attr( $wpcap_billing_id . '-' . $wpcap_key ); ?>" name="<?php echo esc_attr( 'billing_' . $wpcap_key ); ?>" type="text" autocomplete="<?php echo esc_attr( $wpcap_def[0] ); ?>" maxlength="<?php echo esc_attr( \PassCaptain\Billing::MAX[ $wpcap_key ] ); ?>"<?php echo $wpcap_def[1] ? ' required' : ''; ?>>
						</div>
					<?php endforeach; ?>
					<div class="wpcap-field">
						<label for="<?php echo esc_attr( $wpcap_billing_id . '-country' ); ?>"><?php echo esc_html( $wpcap_billing_labels['country'] ); ?> *</label>
						<select id="<?php echo esc_attr( $wpcap_billing_id . '-country' ); ?>" name="billing_country" autocomplete="country" required>
							<?php foreach ( \PassCaptain\Billing::countries() as $wpcap_code => $wpcap_name ) : ?>
								<option value="<?php echo esc_attr( $wpcap_code ); ?>"<?php echo $wpcap_code === $wpcap_billing_country ? ' selected' : ''; ?>><?php echo esc_html( $wpcap_name ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="wpcap-field">
						<label for="<?php echo esc_attr( $wpcap_billing_id . '-vat_id' ); ?>"><?php echo esc_html( $wpcap_billing_labels['vat_id'] . ' ' . __( '(optional)', 'pass-captain' ) ); ?></label>
						<input id="<?php echo esc_attr( $wpcap_billing_id . '-vat_id' ); ?>" name="billing_vat_id" type="text" autocomplete="off" spellcheck="false" maxlength="24" aria-describedby="<?php echo esc_attr( $wpcap_billing_id . '-vat_id-hint' ); ?>">
						<small id="<?php echo esc_attr( $wpcap_billing_id . '-vat_id-hint' ); ?>"><?php esc_html_e( 'Mit Länderkennzeichen, z. B. ATU12345678 oder DE123456789.', 'pass-captain' ); ?></small>
					</div>
					<div class="wpcap-field">
						<label for="<?php echo esc_attr( $wpcap_billing_id . '-po_number' ); ?>"><?php echo esc_html( $wpcap_billing_labels['po_number'] . ' ' . __( '(optional)', 'pass-captain' ) ); ?></label>
						<input id="<?php echo esc_attr( $wpcap_billing_id . '-po_number' ); ?>" name="billing_po_number" type="text" autocomplete="off" maxlength="<?php echo esc_attr( \PassCaptain\Billing::MAX['po_number'] ); ?>">
					</div>
				</div>
			</fieldset>
		<?php endif; ?>
		<?php if ( \PassCaptain\Integrations\Vouchers\Bridge::available() ) : ?>
			<?php /* One field for both: the server tells discount codes and vouchers apart. */ ?>
			<div class="wpcap-field">
				<label for="<?php echo esc_attr( $form_id . '-code' ); ?>"><?php esc_html_e( 'Gutschein- oder Rabattcode (optional)', 'pass-captain' ); ?></label>
				<input id="<?php echo esc_attr( $form_id . '-code' ); ?>" name="code" type="text" autocomplete="off" maxlength="64">
			</div>
		<?php elseif ( $types && \PassCaptain\Campaigns::has_active( (int) $event->event_id, $types[0]->currency ) ) : ?>
			<?php /* Discount code field only when a code is active for this event (or all events). */ ?>
			<div class="wpcap-field">
				<label for="<?php echo esc_attr( $form_id . '-campaign' ); ?>"><?php esc_html_e( 'Rabattcode (optional)', 'pass-captain' ); ?></label>
				<input id="<?php echo esc_attr( $form_id . '-campaign' ); ?>" name="campaign_code" type="text" autocomplete="off" maxlength="40">
			</div>
		<?php endif; ?>
		<?php if ( count( $providers ) > 1 ) : ?>
			<fieldset class="wpcap-providers wpcap-field"><legend><?php esc_html_e( 'Zahlungsart', 'pass-captain' ); ?></legend>
				<?php $first = true; foreach ( $providers as $provider_id => $gateway ) : ?>
					<label><input type="radio" name="provider" value="<?php echo esc_attr( $provider_id ); ?>" <?php checked( $first ); ?>> <?php echo esc_html( $gateway->title() ); ?></label>
				<?php $first = false; endforeach; ?>
			</fieldset>
		<?php elseif ( $providers ) : ?>
			<input type="hidden" name="provider" value="<?php echo esc_attr( array_key_first( $providers ) ); ?>">
		<?php endif; ?>
		<?php if ( $wpcap_has_consumer_info ) : ?>
			<details class="wpcap-legal"><summary><?php esc_html_e( 'Verbraucherinformationen', 'pass-captain' ); ?></summary><?php echo wp_kses_post( wpautop( $legal['consumer_info_text'] ) ); ?></details>
		<?php endif; ?>
		<?php if ( ! empty( $legal['locale'] ) ) : ?><input type="hidden" name="locale" value="<?php echo esc_attr( $legal['locale'] ); ?>"><?php endif; ?>
		<?php if ( $legal['require_terms'] ) : ?>
			<?php if ( ! empty( $legal['terms_version'] ) ) : ?><input type="hidden" name="terms_version" value="<?php echo esc_attr( $legal['terms_version'] ); ?>"><?php endif; ?>
			<?php if ( '' !== trim( $legal['terms_text'] ) ) : ?><details class="wpcap-legal"><summary><?php esc_html_e( 'Bedingungen', 'pass-captain' ); ?></summary><?php echo wp_kses_post( wpautop( $legal['terms_text'] ) ); ?></details><?php endif; ?>
			<div class="wpcap-field wpcap-terms">
				<input id="<?php echo esc_attr( $form_id . '-terms' ); ?>" type="checkbox" name="accept_terms" value="1" required>
				<label for="<?php echo esc_attr( $form_id . '-terms' ); ?>"><?php echo esc_html( $wpcap_has_consumer_info ? __( 'Ich akzeptiere die Bedingungen und habe die Verbraucherinformationen gelesen.', 'pass-captain' ) : __( 'Ich akzeptiere die Bedingungen.', 'pass-captain' ) ); ?></label>
			</div>
		<?php endif; ?>
		<button type="submit" class="wp-element-button wpcap-submit"><?php esc_html_e( 'Zahlungspflichtig bestellen', 'pass-captain' ); ?></button>
		<p class="wpcap-message" role="status" aria-live="polite"></p>
		<?php endif; ?>
	</form>
	<?php if ( $wpcap_waitlist ) : $wpcap_wl_id = $form_id . '-waitlist'; ?>
		<form class="wpcap-waitlist-form" id="<?php echo esc_attr( $wpcap_wl_id ); ?>" data-event="<?php echo esc_attr( (int) $event->event_id ); ?>" data-occurrence="<?php echo esc_attr( (int) $event->occurrence_id ); ?>" novalidate>
			<fieldset>
				<legend><?php esc_html_e( 'Warteliste', 'pass-captain' ); ?></legend>
				<p><small><?php esc_html_e( 'Wird ein Platz frei, schicken wir dir ein Angebot per E-Mail, das du innerhalb der genannten Frist annehmen kannst.', 'pass-captain' ); ?></small></p>
				<?php if ( count( $wpcap_soldout ) > 1 ) : ?>
					<div class="wpcap-field">
						<label for="<?php echo esc_attr( $wpcap_wl_id . '-type' ); ?>"><?php esc_html_e( 'Ticketart', 'pass-captain' ); ?></label>
						<select id="<?php echo esc_attr( $wpcap_wl_id . '-type' ); ?>" name="ticket_type_id">
							<?php foreach ( $wpcap_soldout as $wpcap_type ) : ?><option value="<?php echo esc_attr( (int) $wpcap_type->ticket_type_id ); ?>"><?php echo esc_html( $wpcap_type->name ); ?></option><?php endforeach; ?>
						</select>
					</div>
				<?php else : ?>
					<input type="hidden" name="ticket_type_id" value="<?php echo esc_attr( (int) $wpcap_soldout[0]->ticket_type_id ); ?>">
				<?php endif; ?>
				<div class="wpcap-field">
					<label for="<?php echo esc_attr( $wpcap_wl_id . '-qty' ); ?>"><?php esc_html_e( 'Anzahl', 'pass-captain' ); ?></label>
					<input id="<?php echo esc_attr( $wpcap_wl_id . '-qty' ); ?>" name="quantity" type="number" min="1" max="<?php echo esc_attr( max( 1, (int) $wpcap_soldout[0]->max_per_order ) ); ?>" value="1" inputmode="numeric">
				</div>
				<div class="wpcap-field">
					<label for="<?php echo esc_attr( $wpcap_wl_id . '-email' ); ?>"><?php esc_html_e( 'E-Mail-Adresse', 'pass-captain' ); ?> *</label>
					<input id="<?php echo esc_attr( $wpcap_wl_id . '-email' ); ?>" name="email" type="email" autocomplete="email" required>
				</div>
				<p><small><?php esc_html_e( 'Wir verwenden deine E-Mail-Adresse nur für die Warteliste dieses Termins. Ein Angebot kannst du annehmen oder ablehnen.', 'pass-captain' ); ?></small></p>
				<button type="submit" class="wp-element-button"><?php esc_html_e( 'Auf die Warteliste setzen', 'pass-captain' ); ?></button>
				<p class="wpcap-message" role="status" aria-live="polite"></p>
			</fieldset>
		</form>
	<?php endif; ?>
</article>

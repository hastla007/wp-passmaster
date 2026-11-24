<?php
/**
 * Theme-overridable event card. Copy to your-theme/pass-captain/event-card.php.
 *
 * Available: $event, $types (with ->available, ->fields, ->name_required, ->description, ->attendee_name
 * off|optional|required, ->price_explanation, ->max, ->waitlist, ->kind ticket|addon and ->addon_max_per_ticket;
 * add-on products are rendered in their own fieldset .wpcap-addons with data-kind="addon"), $providers (id => gateway; empty = no
 * payment method available, the card then says "Online-Verkauf derzeit nicht möglich"), $legal
 * (require_terms, terms_text, consumer_info_text, locale, terms_version; texts in the page language) and
 * $card (Frontend\EventCard::context(): badges, seats left, price, other dates with their link "url", next date, limits).
 * The checkout script relies on the class names, the name attributes and the field wrappers
 * (.wpcap-field) for inline error messages.
 *
 * A456: the page may come from a page cache or CDN. The checkout script loads the live state of the
 * date (no-store endpoint /checkout/live: availability, prices, badges, payment methods, waitlist) and
 * switches the parts below, so the card always contains the complete form (.wpcap-buy), the quantity
 * field of every type (.wpcap-qty-box / .wpcap-soldout), both notes and the waitlist form, hidden
 * where they do not apply. Keep these wrappers in a theme copy; without them the card still works,
 * but shows the state of the cached page.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
// Unique per rendered card (A308): two cards of the same date on one page must not share ids.
$wpcap_form_id = \PassCaptain\Frontend\EventCard::form_id( $event->occurrence_id );
$wpcap_level = min( 6, max( 2, (int) apply_filters( 'wpcap_event_card_heading_level', 2 ) ) );
$wpcap_heading = 'h' . $wpcap_level;
$wpcap_has_consumer_info = '' !== trim( $legal['consumer_info_text'] );
// Add-on products (parking, merchandise …, kind "addon") are listed in their own fieldset under the
// tickets; they are only sold together with a ticket of this date and never offered on the waitlist.
$wpcap_ticket_types = array();
$wpcap_addon_types = array();
foreach ( $types as $wpcap_type ) {
	if ( 'addon' === ( $wpcap_type->kind ?? 'ticket' ) ) { $wpcap_addon_types[] = $wpcap_type; } else { $wpcap_ticket_types[] = $wpcap_type; }
}
// Sold-out ticket types (offered on the waitlist) and whether nothing is left to buy.
$wpcap_soldout = array();
foreach ( $wpcap_ticket_types as $wpcap_type ) {
	if ( null !== $wpcap_type->available && (int) $wpcap_type->available < 1 ) { $wpcap_soldout[] = $wpcap_type; }
}
$wpcap_all_soldout = $wpcap_ticket_types && count( $wpcap_soldout ) === count( $wpcap_ticket_types );
// The waitlist form is always part of the card (A456) and only shown while a type is sold out.
$wpcap_waitlist_enabled = $types && (bool) apply_filters( 'wpcap_waitlist_public_enabled', true, $event );
$wpcap_waitlist = $wpcap_soldout && $wpcap_waitlist_enabled;
if ( ! isset( $card ) || ! is_array( $card ) ) { $card = \PassCaptain\Frontend\EventCard::context( $event, $types ); }
$wpcap_doors = ! empty( $event->doors_open_at ) ? \PassCaptain\DoorsOpen::label( $event->doors_open_at, $event->timezone, $event->starts_at ) : '';
$wpcap_visible = (int) $card['visible_types'];
$wpcap_collapse = count( $wpcap_ticket_types ) > $wpcap_visible + 1; // Never collapse a single remaining type.
// A294: without an available payment method nothing can be ordered – say so instead of failing on submit.
$wpcap_no_payment = ! $providers;
// Free ticket types need no payment method: they stay orderable (the note then only concerns paid types).
$wpcap_free_open = false;
$wpcap_paid_open = false;
foreach ( $types as $wpcap_type ) {
	if ( \PassCaptain\Frontend\EventCard::quantity_max( $wpcap_type, $event ) < 1 ) { continue; }
	if ( (int) $wpcap_type->price_minor > 0 ) { $wpcap_paid_open = true; } else { $wpcap_free_open = true; }
}
// The waitlist offer can also be switched off with wpcap_waitlist_frontend ($type null: setting of the whole card).
$wpcap_waitlist_enabled = $wpcap_waitlist_enabled && (bool) apply_filters( 'wpcap_waitlist_frontend', true, $event, null );
$wpcap_waitlist = $wpcap_soldout && $wpcap_waitlist_enabled;
// Unlock code of the page (?wpcap_code=, Sales\Visibility): prefilled in the code field, so the order unlocks the same types.
$wpcap_unlock_code = class_exists( '\PassCaptain\Sales\Visibility' ) ? \PassCaptain\Sales\Visibility::request_code() : '';
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
			<?php if ( '' !== ( $card['order_fee'] ?? '' ) ) : // A425: per-order fee next to the price. ?><div class="wpcap-fact-order-fee"><dt class="wpcap-sr-only"><?php esc_html_e( 'Bearbeitungsgebühr', 'pass-captain' ); ?></dt><dd><?php echo esc_html( $card['order_fee'] ); ?></dd></div><?php endif; ?>
			<div class="wpcap-fact-availability"><dt class="wpcap-sr-only"><?php esc_html_e( 'Verfügbarkeit', 'pass-captain' ); ?></dt><dd><?php echo esc_html( $card['availability'] ); ?></dd></div>
		</dl>
	</header>
	<?php if ( $card['dates'] ) : ?>
		<details class="wpcap-dates">
			<summary><?php echo esc_html( sprintf( /* translators: %d: number of other dates */ __( 'Weitere Termine (%d)', 'pass-captain' ), count( $card['dates'] ) ) ); ?></summary>
			<ul>
				<?php foreach ( $card['dates'] as $wpcap_date ) : ?>
					<li><?php if ( $wpcap_date['linked'] ) : ?><a href="<?php echo esc_url( $wpcap_date['url'] ?? '#wpcap-occ-' . (int) $wpcap_date['occurrence_id'] ); ?>"><time datetime="<?php echo esc_attr( $wpcap_date['iso'] ); ?>"><?php echo esc_html( $wpcap_date['label'] ); ?></time></a><?php else : ?><time datetime="<?php echo esc_attr( $wpcap_date['iso'] ); ?>"><?php echo esc_html( $wpcap_date['label'] ); ?></time><?php endif; ?> <?php echo \PassCaptain\Frontend\EventCard::render_badges( $wpcap_date['badges'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_badges(). ?></li>
				<?php endforeach; ?>
			</ul>
		</details>
	<?php endif; ?>
	<?php if ( $event->description ) : ?><div class="wpcap-description"><?php echo wp_kses_post( wpautop( $event->description ) ); ?></div><?php endif; ?>
	<form class="wpcap-checkout-form" id="<?php echo esc_attr( $wpcap_form_id ); ?>" method="post" action="#" data-heading-level="<?php echo esc_attr( min( 6, $wpcap_level + 1 ) ); ?>" data-event="<?php echo esc_attr( (int) $event->event_id ); ?>" data-occurrence="<?php echo esc_attr( (int) $event->occurrence_id ); ?>" data-waitlist="<?php echo $wpcap_waitlist_enabled ? '1' : '0'; ?>"<?php echo isset( $event->revision ) ? ' data-revision="' . esc_attr( (int) $event->revision ) . '"' : ''; // A560: the state shown; the checkout refuses a changed one. ?> novalidate>
		<?php $wpcap_order_fee = \PassCaptain\ServiceFee::shown()['order_minor']; // A425: display only, the server computes the fee. ?>
		<?php foreach ( array( 'tickets' => $wpcap_ticket_types, 'addons' => $wpcap_addon_types ) as $wpcap_group => $wpcap_group_types ) :
			$wpcap_is_addon = 'addons' === $wpcap_group;
			if ( $wpcap_is_addon && ! $wpcap_group_types ) { continue; }
			?>
		<?php if ( $wpcap_is_addon ) : ?>
		<fieldset class="wpcap-addons" aria-describedby="<?php echo esc_attr( $wpcap_form_id . '-addons-note' ); ?>">
			<legend><?php esc_html_e( 'Zusatzprodukte', 'pass-captain' ); ?></legend>
			<p class="wpcap-addons-note" id="<?php echo esc_attr( $wpcap_form_id . '-addons-note' ); ?>"><small><?php esc_html_e( 'Nur zusammen mit mindestens einem Ticket für diesen Termin.', 'pass-captain' ); ?></small></p>
		<?php else : ?>
		<fieldset class="wpcap-types"<?php echo $card['max_total'] ? ' data-max-total="' . esc_attr( $card['max_total'] ) . '"' : ''; ?><?php echo $wpcap_order_fee ? ' data-order-fee="' . esc_attr( (string) $wpcap_order_fee ) . '"' : ''; ?>>
			<legend><?php esc_html_e( 'Tickets', 'pass-captain' ); ?></legend>
			<?php if ( $wpcap_order_fee && $wpcap_ticket_types && ! \PassCaptain\Frontend\Format::all_free( $wpcap_ticket_types ) ) : // A425: the per-order fee next to the prices, before any total. ?>
				<p class="wpcap-order-fee"><?php echo esc_html( \PassCaptain\ServiceFee::order_note( $wpcap_ticket_types[0]->currency, $wpcap_order_fee ) ); ?></p>
			<?php endif; ?>
		<?php endif; ?>
			<?php foreach ( $wpcap_group_types as $wpcap_index => $type ) :
				$wpcap_max = \PassCaptain\Frontend\EventCard::quantity_max( $type, $event );
				if ( ! $wpcap_is_addon && $wpcap_collapse && $wpcap_index === $wpcap_visible ) :
					?>
					<details class="wpcap-more-types"><summary><?php echo esc_html( sprintf( /* translators: %d: number of further ticket types */ __( 'Weitere Ticketarten (%d)', 'pass-captain' ), count( $wpcap_ticket_types ) - $wpcap_visible ) ); ?></summary>
				<?php endif;
				// A425 (PAngV/PrAG): the shown price includes the per-ticket service fee; data-price stays the ticket price.
				$wpcap_fee = (int) $type->price_minor > 0 ? \PassCaptain\ServiceFee::shown( $type )['ticket_minor'] : 0;
				$wpcap_price = \PassCaptain\Frontend\Format::price( (int) $type->price_minor + $wpcap_fee, $type->currency ); // A409: "kostenlos" for 0.
				$wpcap_base_price = isset( $type->base_price_minor ) && (int) $type->base_price_minor !== (int) $type->price_minor ? \PassCaptain\Frontend\Format::money( \PassCaptain\ServiceFee::shown_unit( $type, (int) $type->base_price_minor ), $type->currency ) : '';
				?>
				<div class="wpcap-type wpcap-field" data-type="<?php echo esc_attr( (int) $type->ticket_type_id ); ?>">
					<label for="<?php echo esc_attr( $wpcap_form_id . '-' . $type->ticket_type_id ); ?>"><?php echo esc_html( $type->name ); ?> — <span class="wpcap-type-price"><?php echo esc_html( $wpcap_price ); ?></span> <span class="wpcap-price-label"<?php echo empty( $type->price_label ) ? ' hidden' : ''; ?>><?php echo esc_html( (string) ( $type->price_label ?? '' ) ); ?></span> <small class="wpcap-base-price"<?php echo '' === $wpcap_base_price ? ' hidden' : ''; ?>><?php echo '' === $wpcap_base_price ? '' : esc_html( sprintf( /* translators: %s: regular price */ __( 'statt %s', 'pass-captain' ), $wpcap_base_price ) ); ?></small><?php if ( ! empty( $type->price_explanation ) ) : ?> <small class="wpcap-price-explanation"><?php echo esc_html( $type->price_explanation ); ?></small><?php endif; ?><?php if ( (int) $type->tax_rate_bp && (int) $type->price_minor ) : ?> <small><?php echo esc_html( sprintf( /* translators: %s: VAT rate */ __( 'inkl. %s %% USt.', 'pass-captain' ), number_format_i18n( $type->tax_rate_bp / 100, 2 ) ) ); ?></small><?php endif; ?><?php if ( $wpcap_fee ) : ?> <small class="wpcap-type-fee"><?php echo esc_html( \PassCaptain\ServiceFee::included_note( $wpcap_fee, $type->currency ) ); ?></small><?php endif; ?><?php $wpcap_notice = \PassCaptain\AdmissionChecks::buyer_notice( \PassCaptain\AdmissionChecks::for_type( $type ) ); ?><?php if ( '' !== $wpcap_notice ) : ?> <small class="wpcap-age-notice"><?php echo esc_html( $wpcap_notice ); ?></small><?php endif; ?></label>
					<?php $wpcap_description = (string) ( $type->description ?? '' ); $wpcap_qty_id = $wpcap_form_id . '-' . $type->ticket_type_id; ?>
					<?php if ( $wpcap_is_addon && (int) ( $type->addon_max_per_ticket ?? 0 ) > 0 ) { $wpcap_description = trim( $wpcap_description . ' ' . sprintf( /* translators: %d: units of the add-on product per ticket */ __( '(höchstens %d je Ticket)', 'pass-captain' ), (int) $type->addon_max_per_ticket ) ); } ?>
					<?php if ( '' !== $wpcap_description ) : ?><small class="wpcap-type-description" id="<?php echo esc_attr( $wpcap_qty_id . '-desc' ); ?>"><?php echo esc_html( $wpcap_description ); ?></small><?php endif; ?>
					<span class="wpcap-soldout"<?php echo $wpcap_max < 1 ? '' : ' hidden'; ?>><?php esc_html_e( 'Ausverkauft', 'pass-captain' ); ?></span>
					<span class="wpcap-qty-box"<?php echo $wpcap_max < 1 ? ' hidden' : ''; ?>><input id="<?php echo esc_attr( $wpcap_form_id . '-' . $type->ticket_type_id ); ?>" class="wpcap-qty" type="number" min="0" max="<?php echo esc_attr( max( 0, $wpcap_max ) ); ?>" value="0" inputmode="numeric" data-type="<?php echo esc_attr( $type->ticket_type_id ); ?>" data-price="<?php echo esc_attr( (int) $type->price_minor ); ?>"<?php echo $wpcap_fee ? ' data-fee="' . esc_attr( (string) $wpcap_fee ) . '"' : ''; ?> data-currency="<?php echo esc_attr( $type->currency ); ?>" data-name="<?php echo esc_attr( $type->name ); ?>" data-fields="<?php echo esc_attr( wp_json_encode( $wpcap_is_addon ? array() : $type->fields ) ); ?>" data-name-required="<?php echo ! $wpcap_is_addon && ! empty( $type->name_required ) ? '1' : '0'; ?>" data-attendee-name="<?php echo esc_attr( $wpcap_is_addon ? 'off' : (string) ( $type->attendee_name ?? 'optional' ) ); ?>"<?php echo $wpcap_is_addon ? ' data-kind="addon"' : ''; ?> data-max-per-order="<?php echo esc_attr( (int) $type->max_per_order ); ?>"<?php echo '' !== $wpcap_description ? ' aria-describedby="' . esc_attr( $wpcap_qty_id . '-desc' ) . '"' : ''; ?><?php echo ( $wpcap_no_payment && (int) $type->price_minor > 0 ) || $wpcap_max < 1 ? ' disabled' : ''; ?>></span>
				</div>
			<?php endforeach; ?>
			<?php if ( ! $wpcap_is_addon && $wpcap_collapse ) : ?></details><?php endif; ?>
		</fieldset>
		<?php endforeach; ?>
		<p class="wpcap-qty-live wpcap-sr-only" aria-live="polite" aria-atomic="true"></p>
		<p class="wpcap-soldout-note"<?php echo $wpcap_all_soldout ? '' : ' hidden'; ?>><?php esc_html_e( 'Dieser Termin ist ausverkauft.', 'pass-captain' ); ?></p>
		<p class="wpcap-no-payment" role="status"<?php echo ! $wpcap_all_soldout && $wpcap_no_payment && $wpcap_paid_open ? '' : ' hidden'; ?>><?php esc_html_e( 'Online-Verkauf derzeit nicht möglich. Bitte versuche es später erneut oder wende dich an den Veranstalter.', 'pass-captain' ); ?></p>
		<div class="wpcap-buy"<?php echo $wpcap_all_soldout || ( $wpcap_no_payment && ! $wpcap_free_open ) ? ' hidden' : ''; ?>>
		<p class="wpcap-estimate" aria-live="polite"></p>
		<div class="wpcap-field">
			<label for="<?php echo esc_attr( $wpcap_form_id . '-email' ); ?>"><?php esc_html_e( 'Deine E-Mail-Adresse (für Tickets und Bestätigung)', 'pass-captain' ); ?> *</label>
			<input id="<?php echo esc_attr( $wpcap_form_id . '-email' ); ?>" name="email" type="email" autocomplete="email" required>
		</div>
		<?php echo \PassCaptain\Security\FormGuard::field( $wpcap_form_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- bot trap field, escaped in FormGuard::field(). ?>
		<?php if ( apply_filters( 'wpcap_checkout_buyer_name', true, $event ) ) : ?>
			<div class="wpcap-field">
				<label for="<?php echo esc_attr( $wpcap_form_id . '-buyer-name' ); ?>"><?php esc_html_e( 'Dein Name', 'pass-captain' ); ?> <?php esc_html_e( '(optional)', 'pass-captain' ); ?></label>
				<input id="<?php echo esc_attr( $wpcap_form_id . '-buyer-name' ); ?>" name="buyer_name" type="text" autocomplete="name" maxlength="190">
			</div>
		<?php endif; ?>
		<?php echo class_exists( '\PassCaptain\Frontend\Language' ) ? \PassCaptain\Frontend\Language::checkout_field( $wpcap_form_id ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in checkout_field. ?>
		<div class="wpcap-attendees"></div>
		<?php do_action( 'wpcap_checkout_form_fields', $event, $types ); ?>
		<?php if ( apply_filters( 'wpcap_checkout_billing_enabled', true, $event ) ) :
			$wpcap_billing_id = $wpcap_form_id . '-billing';
			$wpcap_billing_labels = \PassCaptain\Billing::labels();
			$wpcap_billing_country = \PassCaptain\Billing::default_country();
			$wpcap_billing_threshold = \PassCaptain\Billing::required_threshold( $types ? $types[0]->currency : 'EUR' );
			$wpcap_billing_text = array(
				'company' => array( 'organization', false ),
				'first_name' => array( 'given-name', true ),
				'last_name' => array( 'family-name', true ),
				'street' => array( 'address-line1', true ), // A602: single-line field (street-address is multi-line).
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
				<label for="<?php echo esc_attr( $wpcap_form_id . '-code' ); ?>"><?php esc_html_e( 'Gutschein- oder Rabattcode (optional)', 'pass-captain' ); ?></label>
				<input id="<?php echo esc_attr( $wpcap_form_id . '-code' ); ?>" name="code" type="text" autocomplete="off" maxlength="64" value="<?php echo esc_attr( $wpcap_unlock_code ); ?>">
			</div>
		<?php elseif ( $types && ( '' !== $wpcap_unlock_code || \PassCaptain\Campaigns::has_active( (int) $event->event_id, $types[0]->currency ) ) ) : ?>
			<?php /* Discount code field only when a code is active for this event (or all events). */ ?>
			<div class="wpcap-field">
				<label for="<?php echo esc_attr( $wpcap_form_id . '-campaign' ); ?>"><?php esc_html_e( 'Rabattcode (optional)', 'pass-captain' ); ?></label>
				<input id="<?php echo esc_attr( $wpcap_form_id . '-campaign' ); ?>" name="campaign_code" type="text" autocomplete="off" maxlength="40" value="<?php echo esc_attr( $wpcap_unlock_code ); ?>">
			</div>
		<?php endif; ?>
		<div class="wpcap-provider-slot">
		<?php if ( count( $providers ) > 1 ) : ?>
			<fieldset class="wpcap-providers wpcap-field"><legend><?php esc_html_e( 'Zahlungsart', 'pass-captain' ); ?></legend>
				<?php $first = true; foreach ( $providers as $provider_id => $gateway ) : ?>
					<label><input type="radio" name="provider" value="<?php echo esc_attr( $provider_id ); ?>" <?php checked( $first ); ?>> <?php echo esc_html( $gateway->title() ); ?></label>
				<?php $first = false; endforeach; ?>
			</fieldset>
		<?php elseif ( $providers ) : ?>
			<input type="hidden" name="provider" value="<?php echo esc_attr( array_key_first( $providers ) ); ?>">
		<?php endif; ?>
		</div>
		<?php /* A457: the checkout script replaces .wpcap-legal-text when the texts changed since the page was cached. */ ?>
		<details class="wpcap-legal" data-legal="consumer_info"<?php echo $wpcap_has_consumer_info ? '' : ' hidden'; ?>><summary><?php esc_html_e( 'Verbraucherinformationen', 'pass-captain' ); ?></summary><div class="wpcap-legal-text"><?php echo $wpcap_has_consumer_info ? wp_kses_post( wpautop( $legal['consumer_info_text'] ) ) : ''; ?></div></details>
		<?php if ( ! empty( $legal['locale'] ) ) : ?><input type="hidden" name="locale" value="<?php echo esc_attr( $legal['locale'] ); ?>"><?php endif; ?>
		<?php if ( $legal['require_terms'] ) : ?>
			<?php if ( ! empty( $legal['terms_version'] ) ) : ?><input type="hidden" name="terms_version" value="<?php echo esc_attr( $legal['terms_version'] ); ?>"><?php endif; ?>
			<details class="wpcap-legal" data-legal="terms"<?php echo '' !== trim( $legal['terms_text'] ) ? '' : ' hidden'; ?>><summary><?php esc_html_e( 'Bedingungen', 'pass-captain' ); ?></summary><div class="wpcap-legal-text"><?php echo '' !== trim( $legal['terms_text'] ) ? wp_kses_post( wpautop( $legal['terms_text'] ) ) : ''; ?></div></details>
			<div class="wpcap-field wpcap-terms">
				<input id="<?php echo esc_attr( $wpcap_form_id . '-terms' ); ?>" type="checkbox" name="accept_terms" value="1" required>
				<label for="<?php echo esc_attr( $wpcap_form_id . '-terms' ); ?>"><?php echo esc_html( $wpcap_has_consumer_info ? __( 'Ich akzeptiere die Bedingungen und habe die Verbraucherinformationen gelesen.', 'pass-captain' ) : __( 'Ich akzeptiere die Bedingungen.', 'pass-captain' ) ); ?></label>
			</div>
		<?php endif; ?>
		<?php
		// SET-PC-18: age confirmation when a ticket type of the card has a minimum age (setting min_age_checkbox);
		// required in the form only when every type has one – the server checks the lines actually ordered.
		$wpcap_min_age = \PassCaptain\AdmissionChecks::confirmation_age( array_map( static function ( $t ) { return (int) $t->ticket_type_id; }, $types ) );
		if ( $wpcap_min_age > 0 ) :
			$wpcap_age_all = ! array_filter( $types, static function ( $t ) { return (int) ( $t->min_age ?? 0 ) < 1; } );
			?>
			<div class="wpcap-field wpcap-age-confirm">
				<input id="<?php echo esc_attr( $wpcap_form_id . '-age' ); ?>" type="checkbox" name="confirm_age" value="1"<?php echo $wpcap_age_all ? ' required' : ''; ?>>
				<label for="<?php echo esc_attr( $wpcap_form_id . '-age' ); ?>"><?php echo esc_html( \PassCaptain\Settings::min_age_notice( $wpcap_min_age, ! empty( $legal['locale'] ) ? (string) $legal['locale'] : null ) ); ?></label>
			</div>
		<?php endif; ?>
		<?php /* A450: enabled by checkout.js once its handlers are attached; without JavaScript nothing is sent. A409: "Kostenlos anmelden" while only free tickets are offered (or selected); checkout.js switches with the selection and live prices. */ ?>
		<?php $wpcap_label_paid = __( 'Zahlungspflichtig bestellen', 'pass-captain' ); $wpcap_label_free = __( 'Kostenlos anmelden', 'pass-captain' ); ?>
		<?php /* A602: total next to the button, sticky at the bottom on narrow screens (the live estimate above stays for screen readers). */ ?>
		<div class="wpcap-submit-bar"><p class="wpcap-submit-total" aria-hidden="true"></p>
		<button type="submit" class="wp-element-button wpcap-submit" disabled data-wpcap-requires-js data-label-paid="<?php echo esc_attr( $wpcap_label_paid ); ?>" data-label-free="<?php echo esc_attr( $wpcap_label_free ); ?>"><?php echo esc_html( \PassCaptain\Frontend\Format::all_free( $types ) ? $wpcap_label_free : $wpcap_label_paid ); ?></button>
		</div>
		<?php echo class_exists( '\PassCaptain\Frontend\LegalNotice' ) ? \PassCaptain\Frontend\LegalNotice::checkout_html( $event ?? null, $types ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- withdrawal and privacy notices, escaped in checkout_html(). ?>
		<noscript><p class="wpcap-noscript"><?php esc_html_e( 'Zum Bestellen wird JavaScript benötigt. Bitte aktiviere JavaScript in deinem Browser.', 'pass-captain' ); ?></p></noscript>
		<p class="wpcap-message" role="status" aria-live="polite"></p>
		</div>
	</form>
	<?php if ( $wpcap_waitlist_enabled ) :
		$wpcap_wl_id = $wpcap_form_id . '-waitlist';
		$wpcap_wl_ids = array_map( static function ( $t ) { return (int) $t->ticket_type_id; }, $wpcap_soldout );
		$wpcap_wl_first = $wpcap_wl_ids ? $wpcap_wl_ids[0] : 0;
		$wpcap_wl_max = $wpcap_soldout ? (int) $wpcap_soldout[0]->max_per_order : (int) $types[0]->max_per_order;
		?>
		<form class="wpcap-waitlist-form" id="<?php echo esc_attr( $wpcap_wl_id ); ?>" method="post" action="#" data-event="<?php echo esc_attr( (int) $event->event_id ); ?>" data-occurrence="<?php echo esc_attr( (int) $event->occurrence_id ); ?>" novalidate<?php echo $wpcap_waitlist ? '' : ' hidden'; ?>>
			<fieldset>
				<legend><?php esc_html_e( 'Warteliste', 'pass-captain' ); ?></legend>
				<?php $wpcap_offer_hours = (int) \PassCaptain\Waitlists::config()['offer_hours']; // A410: the real offer period. ?>
				<p><small><?php echo esc_html( sprintf( /* translators: %d: hours an offer can be accepted */ _n( 'Wird ein Platz frei, schicken wir dir ein Angebot per E-Mail. Du kannst es %d Stunde lang annehmen, höchstens bis zum Beginn des Termins.', 'Wird ein Platz frei, schicken wir dir ein Angebot per E-Mail. Du kannst es %d Stunden lang annehmen, höchstens bis zum Beginn des Termins.', $wpcap_offer_hours, 'pass-captain' ), $wpcap_offer_hours ) ); ?></small></p>
				<?php /* Every type is listed; only sold-out ones are selectable (the checkout script updates this, A456). */ ?>
				<div class="wpcap-field wpcap-waitlist-type"<?php echo count( $wpcap_soldout ) > 1 ? '' : ' hidden'; ?>>
					<label for="<?php echo esc_attr( $wpcap_wl_id . '-type' ); ?>"><?php esc_html_e( 'Ticketart', 'pass-captain' ); ?></label>
					<select id="<?php echo esc_attr( $wpcap_wl_id . '-type' ); ?>" name="ticket_type_id">
						<?php foreach ( $wpcap_ticket_types as $wpcap_type ) : $wpcap_wl_on = in_array( (int) $wpcap_type->ticket_type_id, $wpcap_wl_ids, true ); ?><option value="<?php echo esc_attr( (int) $wpcap_type->ticket_type_id ); ?>" data-max="<?php echo esc_attr( max( 1, (int) $wpcap_type->max_per_order ) ); ?>"<?php echo $wpcap_wl_on ? '' : ' disabled hidden'; ?><?php echo (int) $wpcap_type->ticket_type_id === $wpcap_wl_first ? ' selected' : ''; ?>><?php echo esc_html( $wpcap_type->name ); ?></option><?php endforeach; ?>
					</select>
				</div>
				<div class="wpcap-field">
					<label for="<?php echo esc_attr( $wpcap_wl_id . '-qty' ); ?>"><?php esc_html_e( 'Anzahl', 'pass-captain' ); ?></label>
					<input id="<?php echo esc_attr( $wpcap_wl_id . '-qty' ); ?>" name="quantity" type="number" min="1" max="<?php echo esc_attr( max( 1, $wpcap_wl_max ) ); ?>" value="1" inputmode="numeric">
				</div>
				<div class="wpcap-field">
					<label for="<?php echo esc_attr( $wpcap_wl_id . '-email' ); ?>"><?php esc_html_e( 'E-Mail-Adresse', 'pass-captain' ); ?> *</label>
					<input id="<?php echo esc_attr( $wpcap_wl_id . '-email' ); ?>" name="email" type="email" autocomplete="email" required>
				</div>
				<p><small><?php esc_html_e( 'Wir verwenden deine E-Mail-Adresse nur für die Warteliste dieses Termins. Ein Angebot kannst du annehmen oder ablehnen.', 'pass-captain' ); ?></small></p>
				<button type="submit" class="wp-element-button" disabled data-wpcap-requires-js><?php esc_html_e( 'Auf die Warteliste setzen', 'pass-captain' ); ?></button>
				<noscript><p class="wpcap-noscript"><?php esc_html_e( 'Für die Warteliste wird JavaScript benötigt. Bitte aktiviere JavaScript in deinem Browser.', 'pass-captain' ); ?></p></noscript>
				<p class="wpcap-message" role="status" aria-live="polite"></p>
			</fieldset>
		</form>
	<?php endif; ?>
</article>

<?php
/** Buyer order portal served through a high-entropy emailed capability link. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class OrderPortal {
	public static function register() {
		add_filter( 'query_vars', static function ( $vars ) { $vars[] = 'wpcap_order'; $vars[] = 'wpcap_access'; return $vars; } );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ) );
	}

	public static function maybe_render() {
		$public_id = sanitize_text_field( get_query_var( 'wpcap_order' ) );
		$token = strtolower( preg_replace( '/[^a-f0-9]/', '', (string) get_query_var( 'wpcap_access' ) ) );
		if ( '' === $public_id || '' === $token ) { return; }
		global $wpdb;
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_orders WHERE public_id=%s", $public_id ) );
		if ( ! $order || ! $order->access_expires_at || $order->access_expires_at <= current_time( 'mysql', true ) || 64 !== strlen( $token ) || ! hash_equals( (string) $order->access_token_hash, hash( 'sha256', $token ) ) ) { status_header( 404 ); exit; }
		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Referrer-Policy: no-referrer' );
		echo self::render_page( $order, $token ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_page.
		exit;
	}

	/** Portal HTML for an order whose access token was verified by the caller. */
	public static function render_page( $order, $token ) {
		global $wpdb;
		$public_id = (string) $order->public_id;
		$tickets = $wpdb->get_results( $wpdb->prepare(
			"SELECT t.ticket_id,t.ticket_type_id,t.public_id,t.status,t.transfer_count,t.attendee_email,ta.attendee_name,tt.name AS ticket_name,e.name AS event_name,o.starts_at,o.timezone FROM {$wpdb->prefix}wpcap_tickets t LEFT JOIN {$wpdb->prefix}wpcap_ticket_assignments ta ON ta.ticket_id=t.ticket_id INNER JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$wpdb->prefix}wpcap_events e ON e.event_id=o.event_id WHERE t.order_id=%d ORDER BY t.ticket_id",
			$order->order_id
		) );
		$edit_hours = max( 0, (int) Settings::get( 'attendee_edit_hours' ) );
		$fields = array();
		$answers = array();
		foreach ( $tickets as $ticket ) {
			if ( ! isset( $fields[ $ticket->ticket_type_id ] ) ) {
				$fields[ $ticket->ticket_type_id ] = $wpdb->get_results( $wpdb->prepare( "SELECT field_id,field_key,label,field_type,required FROM {$wpdb->prefix}wpcap_participant_fields WHERE ticket_type_id=%d AND status='active' ORDER BY position,field_id", $ticket->ticket_type_id ) );
			}
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT field_id,answer FROM {$wpdb->prefix}wpcap_participant_answers WHERE ticket_id=%d", $ticket->ticket_id ) ) as $row ) {
				$answers[ $ticket->ticket_id ][ (int) $row->field_id ] = $row->answer;
			}
		}
		$has_invoice = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT invoice_id FROM {$wpdb->prefix}wpcap_invoices WHERE order_id=%d", $order->order_id ) );
		$base = 'wp-pass-captain/v1/orders/' . rawurlencode( $public_id );
		$reference = Frontend\Format::order_reference( $order->public_id );
		$can_resend = 'paid' === $order->payment_status && (bool) array_filter( $tickets, static function ( $ticket ) { return in_array( $ticket->status, array( 'valid', 'checked_in' ), true ); } );
		$bank = Payments\BankTransfer::applies( $order ) ? Payments\BankTransfer::details( $order ) : null;
		$strings = array(
			'cancel' => __( 'Abbrechen', 'wp-pass-captain' ),
			'transferFailed' => __( 'Übertragung fehlgeschlagen.', 'wp-pass-captain' ),
			'transferred' => __( 'Ticket wurde übertragen. Der neue Code wurde per E-Mail versendet.', 'wp-pass-captain' ),
			/* translators: %s: recipient e-mail address */
			'transferConfirm' => __( 'Ticket wirklich an %s übertragen? Das ist endgültig und nur einmal möglich: Der bisherige Ticketcode wird sofort ungültig und die neue Person erhält einen neuen Code per E-Mail.', 'wp-pass-captain' ),
			'transferConfirmButton' => __( 'Ja, endgültig übertragen', 'wp-pass-captain' ),
			'assignFailed' => __( 'Zuweisung fehlgeschlagen.', 'wp-pass-captain' ),
			'assignedRotated' => __( 'Teilnehmer wurde zugewiesen. Ein neuer Ticketcode wurde an die Teilnehmer-E-Mail gesendet; der bisherige Code ist ungültig.', 'wp-pass-captain' ),
			'assignedKept' => __( 'Teilnehmerdaten gespeichert. Der Ticketcode bleibt unverändert.', 'wp-pass-captain' ),
			'resendConfirm' => __( 'Tickets jetzt erneut senden? Dabei werden neue Ticketcodes erstellt; die bisher gesendeten Codes und Ausdrucke werden ungültig.', 'wp-pass-captain' ),
			'resendConfirmButton' => __( 'Ja, erneut senden', 'wp-pass-captain' ),
			'resendFailed' => __( 'Erneuter Versand fehlgeschlagen.', 'wp-pass-captain' ),
			/* translators: %s: masked e-mail address */
			'resent' => __( 'Die Tickets wurden erneut an %s gesendet. Bitte verwende nur noch die neuen Codes.', 'wp-pass-captain' ),
			'sending' => __( 'Wird gesendet …', 'wp-pass-captain' ),
			'billingSaved' => __( 'Rechnungsdaten gespeichert. Sie werden auf der Rechnung verwendet.', 'wp-pass-captain' ),
			'billingCleared' => __( 'Rechnungsdaten entfernt.', 'wp-pass-captain' ),
			'billingFailed' => __( 'Die Rechnungsdaten konnten nicht gespeichert werden.', 'wp-pass-captain' ),
		);
		$billing = Billing::for_order( $order );
		ob_start();
		?><!doctype html><html lang="<?php echo esc_attr( str_replace( '_', '-', determine_locale() ) ); ?>"><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html( sprintf( /* translators: %s: short order number */ __( 'Bestellung %s', 'wp-pass-captain' ), $reference ) ); ?></title>
<style>body{font:16px/1.5 system-ui,sans-serif;max-width:48rem;margin:2rem auto;padding:0 1rem;color:#1d2327}.card{border:1px solid #c3c4c7;border-radius:10px;padding:1rem;margin:1rem 0}label{display:grid;gap:.25rem;margin:.5rem 0}input{max-width:28rem;padding:.5rem}button{padding:.55rem 1rem;min-height:2.75rem}.status{font-weight:700}.muted{color:#50575e}.bank{display:grid;grid-template-columns:max-content 1fr;gap:.25rem 1rem}.bank dd{margin:0;font-weight:700;overflow-wrap:anywhere}dialog{max-width:32rem;border:1px solid #c3c4c7;border-radius:10px;padding:1.25rem}dialog::backdrop{background:rgba(0,0,0,.45)}.actions{display:flex;gap:.75rem;flex-wrap:wrap;justify-content:flex-end}.danger{background:#b32d2e;color:#fff;border:1px solid #8a1f1f;border-radius:4px}:focus-visible{outline:3px solid #135e96;outline-offset:3px}</style></head><body><main>
<h1><?php echo esc_html( sprintf( /* translators: %s: short order number */ __( 'Bestellung %s', 'wp-pass-captain' ), $reference ) ); ?></h1>
<p class="muted"><?php echo esc_html( sprintf( /* translators: %s: order date */ __( 'Bestellt am %s', 'wp-pass-captain' ), Frontend\Format::site_datetime( $order->created_at ) ) ); ?></p>
<p class="status"><?php echo esc_html( Admin\Forms::status_label( $order->status ) . ( $order->status !== $order->payment_status ? ' · ' . Admin\Forms::status_label( $order->payment_status ) : '' ) ); ?></p>
<?php $voucher_paid = class_exists( Integrations\Vouchers\Checkout::class ) ? Integrations\Vouchers\Checkout::voucher_payment_minor( $order ) : 0; ?>
<?php $summary = Orders::summary_lines( (int) $order->order_id ); if ( $summary ) : ?><section class="card" aria-labelledby="wpcap-items-title"><h2 id="wpcap-items-title"><?php esc_html_e( 'Bestellte Tickets', 'wp-pass-captain' ); ?></h2><table class="items"><tbody><?php foreach ( $summary as $line ) : ?><tr><td><?php echo esc_html( sprintf( '%d × %s — %s', $line['quantity'], $line['event'], $line['ticket'] ) ); ?><br><small class="muted"><?php echo esc_html( $line['when'] ); ?></small></td><td style="text-align:right;white-space:nowrap"><?php echo esc_html( Frontend\Format::money( $line['gross_minor'], $line['currency'] ) ); ?></td></tr><?php endforeach; ?></tbody></table></section><?php endif; ?>
<p><?php echo esc_html( sprintf( /* translators: %s: formatted amount */ __( 'Gesamtbetrag: %s', 'wp-pass-captain' ), Frontend\Format::money( (int) $order->total_minor + $voucher_paid, $order->currency ) ) ); ?><?php if ( $voucher_paid ) : ?><br><?php echo esc_html( sprintf( /* translators: 1: voucher amount, 2: amount to pay */ __( 'davon mit Gutschein bezahlt: %1$s · Zahlbetrag: %2$s', 'wp-pass-captain' ), Frontend\Format::money( $voucher_paid, $order->currency ), Frontend\Format::money( $order->total_minor, $order->currency ) ) ); ?><?php endif; ?></p>
<?php if ( $bank ) : ?><section class="card" aria-labelledby="wpcap-pay-title"><h2 id="wpcap-pay-title"><?php esc_html_e( 'Zahlungsinformationen', 'wp-pass-captain' ); ?></h2><dl class="bank"><?php foreach ( Payments\BankTransfer::rows( $bank ) as $label => $value ) : ?><dt><?php echo esc_html( $label ); ?></dt><dd><?php echo esc_html( $value ); ?></dd><?php endforeach; ?></dl><?php if ( '' !== $bank['instructions'] ) : ?><p><?php echo nl2br( esc_html( $bank['instructions'] ) ); ?></p><?php endif; ?></section><?php endif; ?>
<?php if ( $tickets ) : ?><h2><?php esc_html_e( 'Tickets', 'wp-pass-captain' ); ?></h2><p><?php esc_html_e( 'Die Ticketlinks und QR-Codes findest du in der Ticket-E-Mail (aus Sicherheitsgründen werden Ticketcodes nicht gespeichert und hier nicht angezeigt).', 'wp-pass-captain' ); ?></p>
<?php if ( $can_resend ) : ?><form class="wpcap-resend-form card"><p><button type="submit"><?php echo esc_html( sprintf( /* translators: %s: masked e-mail address */ __( 'Tickets erneut an %s senden', 'wp-pass-captain' ), Frontend\Format::mask_email( $order->customer_email ) ) ); ?></button></p><p class="muted"><small><?php esc_html_e( 'Dabei werden neue Ticketcodes erstellt; bisher gesendete Codes werden ungültig. Tickets mit eigener Teilnehmer-E-Mail gehen an diese Adresse. Höchstens dreimal pro Stunde.', 'wp-pass-captain' ); ?></small></p><p class="message" role="status" aria-live="polite"></p></form><?php endif; ?>
<?php foreach ( $tickets as $ticket ) : ?><section class="card"><h3><?php echo esc_html( $ticket->event_name . ' — ' . $ticket->ticket_name ); ?></h3><p><?php echo esc_html( Frontend\Format::event_start( $ticket->starts_at, $ticket->timezone ) ); ?></p><?php $seat = (string) apply_filters( 'wpcap_ticket_seat_label', '', (int) $ticket->ticket_id, (array) $ticket ); if ( '' !== $seat ) : ?><p><strong><?php echo esc_html( $seat ); ?></strong></p><?php endif; ?><p><?php echo esc_html( __( 'Status:', 'wp-pass-captain' ) . ' ' . Admin\Forms::status_label( $ticket->status ) ); ?></p>
<?php if ( 'valid' === $ticket->status && strtotime( $ticket->starts_at . ' UTC' ) - $edit_hours * HOUR_IN_SECONDS > time() ) : ?><form class="wpcap-assignment-form"><input type="hidden" name="ticket_public_id" value="<?php echo esc_attr( $ticket->public_id ); ?>"><label><?php esc_html_e( 'Teilnehmername', 'wp-pass-captain' ); ?><input name="attendee_name" type="text" maxlength="190" value="<?php echo esc_attr( $ticket->attendee_name ?? '' ); ?>" required></label><label><?php esc_html_e( 'Teilnehmer-E-Mail (optional)', 'wp-pass-captain' ); ?><input name="attendee_email" type="email" autocomplete="email" value="<?php echo esc_attr( $ticket->attendee_email ); ?>"></label><?php foreach ( $fields[ $ticket->ticket_type_id ] as $field ) : $value = $answers[ $ticket->ticket_id ][ (int) $field->field_id ] ?? ''; ?><label><?php echo esc_html( $field->label . ( $field->required ? ' *' : '' ) ); ?><?php if ( 'textarea' === $field->field_type ) : ?><textarea data-answer="<?php echo esc_attr( $field->field_key ); ?>" <?php echo $field->required ? 'required' : ''; ?>><?php echo esc_textarea( $value ); ?></textarea><?php elseif ( 'checkbox' === $field->field_type ) : ?><input type="checkbox" value="1" data-answer="<?php echo esc_attr( $field->field_key ); ?>" <?php checked( '1', $value ); ?>><?php else : ?><input type="<?php echo 'email' === $field->field_type ? 'email' : 'text'; ?>" data-answer="<?php echo esc_attr( $field->field_key ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php echo $field->required ? 'required' : ''; ?>><?php endif; ?></label><?php endforeach; ?><p><small><?php esc_html_e( 'Wenn du eine andere E-Mail-Adresse einträgst, erhält diese Person einen neuen Ticketcode und der bisherige Code wird ungültig. Namens- und Angabenkorrekturen behalten den Code.', 'wp-pass-captain' ); ?></small></p><?php if ( $edit_hours ) : ?><p><small><?php echo esc_html( sprintf( /* translators: %d: hours */ __( 'Änderungen sind bis %d Stunden vor Beginn möglich.', 'wp-pass-captain' ), $edit_hours ) ); ?></small></p><?php endif; ?><button type="submit"><?php esc_html_e( 'Teilnehmer zuweisen', 'wp-pass-captain' ); ?></button><p class="message" aria-live="polite"></p></form><?php endif; ?>
<?php if ( 'valid' === $ticket->status && 0 === (int) $ticket->transfer_count && strtotime( $ticket->starts_at . ' UTC' ) > time() ) : ?><form class="wpcap-transfer-form"><input type="hidden" name="ticket_public_id" value="<?php echo esc_attr( $ticket->public_id ); ?>"><label><?php esc_html_e( 'An E-Mail übertragen', 'wp-pass-captain' ); ?><input name="recipient_email" type="email" autocomplete="email" required></label><button type="submit"><?php esc_html_e( 'Ticket einmalig übertragen', 'wp-pass-captain' ); ?></button><p class="message" aria-live="polite"></p></form><?php endif; ?><?php echo (string) apply_filters( 'wpcap_order_portal_ticket_html', '', $ticket, $order, $token ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- modules escape their own markup (e.g. Exchange\Portal). ?></section><?php endforeach; endif; ?>
<?php echo self::billing_section( $billing, $has_invoice ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in billing_section. ?>
<?php if ( $has_invoice ) : ?><p><a href="<?php echo esc_url( add_query_arg( array( 'wpcap_invoice' => $public_id, 'wpcap_access' => $token ), home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Rechnung anzeigen', 'wp-pass-captain' ); ?></a></p><?php endif; ?>
<p><?php esc_html_e( 'Diesen persönlichen Link sicher aufbewahren und nicht teilen.', 'wp-pass-captain' ); ?></p>
<p class="muted"><small><?php echo esc_html( sprintf( /* translators: %s: full order id */ __( 'Vollständige Bestell-ID: %s', 'wp-pass-captain' ), $order->public_id ) ); ?></small></p></main>
<dialog id="wpcap-confirm" aria-labelledby="wpcap-confirm-text"><form method="dialog"><p id="wpcap-confirm-text"></p><div class="actions"><button value="cancel"><?php echo esc_html( $strings['cancel'] ); ?></button><button value="ok" class="danger" id="wpcap-confirm-ok"></button></div></form></dialog>
<script>(function(){
var S=<?php echo wp_json_encode( $strings ); ?>,TOKEN=<?php echo wp_json_encode( $token ); ?>,URL={transfer:<?php echo wp_json_encode( rest_url( $base . '/transfer' ) ); ?>,assign:<?php echo wp_json_encode( rest_url( $base . '/assign' ) ); ?>,resend:<?php echo wp_json_encode( rest_url( $base . '/resend' ) ); ?>,billing:<?php echo wp_json_encode( rest_url( $base . '/billing' ) ); ?>};
function confirmAction(text,label){var d=document.getElementById('wpcap-confirm');if(!d||typeof d.showModal!=='function'){return Promise.resolve(window.confirm(text));}document.getElementById('wpcap-confirm-text').textContent=text;document.getElementById('wpcap-confirm-ok').textContent=label;var opener=document.activeElement;return new Promise(function(resolve){d.addEventListener('close',function(){if(opener&&opener.focus){opener.focus();}resolve(d.returnValue==='ok');},{once:true});d.returnValue='';d.showModal();d.querySelector('button[value="cancel"]').focus();});}
async function post(url,body){var r=await fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(Object.assign({access_token:TOKEN},body))});var data={};try{data=await r.json();}catch(e){}return {ok:r.ok,data:data};}
document.querySelectorAll('.wpcap-transfer-form').forEach(function(form){form.addEventListener('submit',async function(event){event.preventDefault();var message=form.querySelector('.message');var button=form.querySelector('button');if(!form.reportValidity()){return;}if(!(await confirmAction(S.transferConfirm.replace('%s',form.recipient_email.value),S.transferConfirmButton))){return;}button.disabled=true;try{var res=await post(URL.transfer,{ticket_public_id:form.ticket_public_id.value,recipient_email:form.recipient_email.value});if(!res.ok){throw new Error(res.data.message||S.transferFailed);}message.textContent=S.transferred;form.querySelectorAll('label,button').forEach(function(n){n.remove();});}catch(error){message.textContent=error.message;button.disabled=false;}});});
document.querySelectorAll('.wpcap-assignment-form').forEach(function(form){form.addEventListener('submit',async function(event){event.preventDefault();var message=form.querySelector('.message');try{var res=await post(URL.assign,{ticket_public_id:form.ticket_public_id.value,attendee_name:form.attendee_name.value,attendee_email:form.attendee_email.value,answers:Array.from(form.querySelectorAll('[data-answer]')).reduce(function(a,i){a[i.dataset.answer]=i.type==='checkbox'?(i.checked?'1':''):i.value;return a;},{})});if(!res.ok){throw new Error(res.data.message||S.assignFailed);}message.textContent=res.data.code_rotated?S.assignedRotated:S.assignedKept;}catch(error){message.textContent=error.message;}});});
document.querySelectorAll('.wpcap-resend-form').forEach(function(form){form.addEventListener('submit',async function(event){event.preventDefault();var message=form.querySelector('.message');var button=form.querySelector('button');if(!(await confirmAction(S.resendConfirm,S.resendConfirmButton))){return;}button.disabled=true;message.textContent=S.sending;try{var res=await post(URL.resend,{});if(!res.ok){throw new Error(res.data.message||S.resendFailed);}message.textContent=S.resent.replace('%s',res.data.email||'');}catch(error){message.textContent=error.message;}finally{button.disabled=false;}});});
document.querySelectorAll('.wpcap-billing-form').forEach(function(form){var toggle=form.querySelector('[name="enabled"]'),box=form.querySelector('.billing-fields');function sync(){box.hidden=!toggle.checked;}toggle.addEventListener('change',sync);sync();form.addEventListener('submit',async function(event){event.preventDefault();var message=form.querySelector('.message');var button=form.querySelector('button[type="submit"]');form.querySelectorAll('[aria-invalid]').forEach(function(i){i.removeAttribute('aria-invalid');});if(toggle.checked&&!form.reportValidity()){return;}var billing={enabled:toggle.checked};form.querySelectorAll('[data-billing]').forEach(function(i){billing[i.dataset.billing]=i.value.trim();});button.disabled=true;try{var res=await post(URL.billing,{billing:billing});if(!res.ok){var field=res.data&&res.data.data&&res.data.data.field?form.querySelector('[data-billing="'+String(res.data.data.field).replace('billing_','')+'"]'):null;if(field){field.setAttribute('aria-invalid','true');field.focus();}throw new Error(res.data.message||S.billingFailed);}message.textContent=toggle.checked?S.billingSaved:S.billingCleared;}catch(error){message.textContent=error.message;}finally{button.disabled=false;}});});
})();</script></body></html><?php
		return (string) ob_get_clean();
	}

	/** Billing data card: editable until the invoice is issued, read-only afterwards. */
	private static function billing_section( array $billing, $has_invoice ) {
		$labels = Billing::labels();
		$html = '<section class="card" aria-labelledby="wpcap-billing-title"><h2 id="wpcap-billing-title">' . esc_html__( 'Rechnungsdaten', 'wp-pass-captain' ) . '</h2>';
		if ( $has_invoice ) {
			$lines = array_merge( Billing::address_lines( $billing ), Billing::detail_lines( $billing ) );
			$html .= $lines ? '<p>' . implode( '<br>', array_map( 'esc_html', $lines ) ) . '</p>' : '';
			$html .= '<p class="muted"><small>' . esc_html__( 'Die Rechnung wurde bereits ausgestellt. Für eine Korrektur wende dich bitte an den Veranstalter.', 'wp-pass-captain' ) . '</small></p></section>';
			return $html;
		}
		$html .= '<form class="wpcap-billing-form"><p class="muted"><small>' . esc_html__( 'Bis zur Ausstellung der Rechnung kannst du hier Firma, Name, Anschrift und UID-Nummer für die Rechnung angeben oder korrigieren.', 'wp-pass-captain' ) . '</small></p>';
		$html .= '<label style="display:flex;gap:.5rem;align-items:center"><input type="checkbox" name="enabled" value="1"' . ( $billing ? ' checked' : '' ) . '> ' . esc_html( $labels['enabled'] ) . '</label><div class="billing-fields">';
		$autocomplete = array( 'company' => 'organization', 'first_name' => 'given-name', 'last_name' => 'family-name', 'street' => 'street-address', 'postcode' => 'postal-code', 'city' => 'address-level2', 'vat_id' => 'off', 'po_number' => 'off' );
		$country = $billing['country'] ?? Billing::default_country();
		foreach ( Billing::FIELDS as $field ) {
			$required = in_array( $field, Billing::REQUIRED, true );
			$label = $labels[ $field ] . ( $required ? ' *' : ' ' . __( '(optional)', 'wp-pass-captain' ) );
			if ( 'country' === $field ) {
				$html .= '<label>' . esc_html( $label ) . '<select data-billing="country" autocomplete="country" required>';
				foreach ( Billing::countries() as $code => $name ) {
					$html .= '<option value="' . esc_attr( $code ) . '"' . ( $code === $country ? ' selected' : '' ) . '>' . esc_html( $name ) . '</option>';
				}
				$html .= '</select></label>';
				continue;
			}
			$html .= '<label>' . esc_html( $label ) . '<input type="text" data-billing="' . esc_attr( $field ) . '" autocomplete="' . esc_attr( $autocomplete[ $field ] ) . '" maxlength="' . (int) Billing::MAX[ $field ] . '" value="' . esc_attr( $billing[ $field ] ?? '' ) . '"' . ( $required ? ' required' : '' ) . '></label>';
		}
		$html .= '</div><button type="submit">' . esc_html__( 'Rechnungsdaten speichern', 'wp-pass-captain' ) . '</button><p class="message" role="status" aria-live="polite"></p></form></section>';
		return $html;
	}
}

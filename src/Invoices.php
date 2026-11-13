<?php
/**
 * Invoices, credit notes, print view and accounting export.
 *
 * Numbers are allocated inside the document transaction (gapless per series and year). VAT comes from
 * the order line snapshots (rate configured per ticket type by the operator). The texts and the
 * legal sufficiency of the documents must be reviewed for the operator's country before use.
 */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Invoices {
	const FINANCE_CAP = 'wpcap_view_finance';

	public static function register() {
		add_action( 'wpcap_order_paid', array( __CLASS__, 'maybe_auto_issue' ) );
		add_action( 'wpcap_refund_completed', array( __CLASS__, 'credit_note_for_refund' ) );
		add_action( 'admin_post_wpcap_print_invoice', array( __CLASS__, 'handle_admin_print' ) );
		add_action( 'admin_post_wpcap_export_accounting', array( __CLASS__, 'handle_export' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render_customer_invoice' ) );
	}

	/** Issues an invoice automatically after payment when enabled in the settings. */
	public static function maybe_auto_issue( $order_id ) {
		if ( Settings::get( 'auto_invoice' ) ) {
			self::issue_for_order( $order_id );
		}
	}

	public static function issue_for_order( $order_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order_id = absint( $order_id );
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_orders WHERE order_id=%d", $order_id ) );
		if ( ! $order || 'paid' !== $order->payment_status ) { return new \WP_Error( 'wpcap_invoice_order_unpaid', __( 'Eine Rechnung kann erst für bezahlte Bestellungen erstellt werden.', 'wp-pass-captain' ), array( 'status' => 409 ) ); }
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT invoice_id,invoice_number FROM {$p}wpcap_invoices WHERE order_id=%d LIMIT 1", $order_id ) );
		if ( $existing ) { return array( 'invoice_id' => (int) $existing->invoice_id, 'invoice_number' => $existing->invoice_number, 'reused' => true ); }
		$items = $wpdb->get_results( $wpdb->prepare( "SELECT oi.*," . OrderLines::paid_columns() . ",tt.name,e.name AS event_name,o.starts_at,o.timezone FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE oi.order_id=%d ORDER BY oi.item_id", $order_id ) );
		// Lines created by a ticket exchange carry no paid amount (OrderLines): not invoiced here.
		$items = array_values( array_filter( $items, static function ( $item ) { return ! OrderLines::is_moved_in( $item ); } ) );
		/** Invoice lines of an order (item rows with name, event_name, starts_at, timezone); e.g. exchange fee lines. */
		$items = (array) apply_filters( 'wpcap_invoice_items', $items, $order );
		$order_subtotal = (int) $order->subtotal_minor ?: (int) $order->total_minor;
		// Seller side (sales models, docs/SALES-MODELS.md): platform or organizer, refused for mixed sellers.
		$seller_context = self::seller_context( $order_id, $items );
		if ( is_wp_error( $seller_context ) ) { return $seller_context; }
		$wpdb->query( 'START TRANSACTION' );
		$locked_order = $wpdb->get_row( $wpdb->prepare( "SELECT payment_status FROM {$p}wpcap_orders WHERE order_id=%d FOR UPDATE", $order_id ) );
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT invoice_id,invoice_number FROM {$p}wpcap_invoices WHERE order_id=%d", $order_id ) );
		if ( $existing ) { $wpdb->query( 'COMMIT' ); return array( 'invoice_id' => (int) $existing->invoice_id, 'invoice_number' => $existing->invoice_number, 'reused' => true ); }
		if ( ! $locked_order || 'paid' !== $locked_order->payment_status ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_invoice_order_unpaid', __( 'Die Bestellung ist nicht mehr als bezahlt markiert.', 'wp-pass-captain' ), array( 'status' => 409 ) ); }
		// Reserve the number in the same transaction so a rollback never leaves a gap.
		$number = self::next_invoice_number( $seller_context );
		if ( is_wp_error( $number ) ) { $wpdb->query( 'ROLLBACK' ); return $number; }
		// Payments/vouchers: a multi-purpose voucher pays part of the full gross amount (docs/VOUCHERS.md).
		$invoice_total = Integrations\Vouchers\Checkout::gross_minor( $order );
		$snapshot = array(
			'email' => $order->customer_email,
			'currency' => $order->currency,
			'subtotal_minor' => $order_subtotal,
			'discount_minor' => (int) $order->discount_minor,
			'total_minor' => $invoice_total,
			'terms_version' => $order->terms_version,
			'seller' => self::seller(),
		);
		$snapshot = self::with_seller_context( $snapshot, $seller_context );
		// Buyer billing data (company, name, address, VAT ID, buyer reference) frozen with the invoice.
		$snapshot = array_merge( $snapshot, Billing::snapshot( $order ) );
		$payments = Integrations\Vouchers\Checkout::invoice_payments( $order );
		if ( $payments ) { $snapshot['payments'] = $payments; }
		$saved = $wpdb->insert( $p . 'wpcap_invoices', array( 'order_id' => $order_id, 'invoice_number' => $number, 'currency' => $order->currency, 'subtotal_minor' => $order_subtotal, 'tax_minor' => (int) $order->tax_minor, 'total_minor' => $invoice_total, 'buyer_snapshot' => wp_json_encode( $snapshot ), 'issued_at' => current_time( 'mysql', true ), 'status' => 'issued' ) );
		$invoice_id = (int) $wpdb->insert_id;
		if ( false === $saved || ! $invoice_id ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_invoice_create', __( 'Rechnung konnte nicht erstellt werden.', 'wp-pass-captain' ), array( 'status' => 500 ) ); }
		$discount_left = (int) $order->discount_minor;
		$subtotal = max( 1, $order_subtotal );
		$gross_before = 0;
		$discount_allocated = 0;
		foreach ( $items as $index => $item ) {
			$gross_before += (int) $item->line_total_minor;
			$should_be_allocated = (int) floor( (int) $order->discount_minor * $gross_before / $subtotal );
			$line_discount = $index === count( $items ) - 1 ? min( (int) $item->line_total_minor, max( 0, $discount_left ) ) : max( 0, min( (int) $item->line_total_minor, $should_be_allocated - $discount_allocated ) );
			$discount_allocated += $line_discount;
			$discount_left -= $line_discount;
			$line_total = max( 0, (int) $item->line_total_minor - $line_discount );
			$description = $item->event_name . ' – ' . $item->name . ' (' . wp_date( 'd.m.Y H:i', strtotime( $item->starts_at . ' UTC' ), new \DateTimeZone( $item->timezone ) ) . ')';
			$line_saved = $wpdb->insert( $p . 'wpcap_invoice_lines', array( 'invoice_id' => $invoice_id, 'description' => mb_substr( $description, 0, 190 ), 'quantity' => $item->quantity, 'unit_minor' => $item->unit_price_minor, 'discount_minor' => $line_discount, 'tax_rate' => (int) $item->tax_rate_bp / 100, 'tax_minor' => (int) $item->tax_minor, 'total_minor' => $line_total ) );
			if ( false === $line_saved ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_invoice_line', __( 'Rechnungsposition konnte nicht gespeichert werden.', 'wp-pass-captain' ), array( 'status' => 500 ) ); }
		}
		$wpdb->query( 'COMMIT' );
		Audit::record( 'invoice_issued', 'invoice', $invoice_id, array( 'order_id' => $order_id, 'invoice_number' => $number, 'sales_model' => $seller_context['model'], 'partner_id' => $seller_context['partner_id'] ) );
		return array( 'invoice_id' => $invoice_id, 'invoice_number' => $number );
	}

	/**
	 * Creates the credit note for a completed refund (only if the order has an invoice). Idempotent per refund.
	 *
	 * @return array|null|\WP_Error
	 */
	public static function credit_note_for_refund( $refund_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$refund = Refunds::get( $refund_id );
		if ( ! $refund || 'completed' !== $refund['status'] ) { return null; }
		$invoice = $wpdb->get_row( $wpdb->prepare( "SELECT invoice_id,buyer_snapshot FROM {$p}wpcap_invoices WHERE order_id=%d", (int) $refund['order_id'] ) );
		if ( ! $invoice ) { return null; }
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_credit_notes WHERE refund_id=%d", absint( $refund_id ) ), ARRAY_A );
		if ( $existing ) { return $existing; }
		$wpdb->query( 'START TRANSACTION' );
		// Corrections follow the seller (and number range owner) of the corrected invoice.
		list( $credit_series, $credit_prefix ) = self::credit_series_for( (array) json_decode( (string) $invoice->buyer_snapshot, true ) );
		$number = self::next_document_number( $credit_series, $credit_prefix, true );
		if ( is_wp_error( $number ) ) { $wpdb->query( 'ROLLBACK' ); return $number; }
		// Payments/vouchers: with a multi-purpose voucher the correction covers cash + voucher part.
		$credit_amounts = Integrations\Vouchers\Checkout::credit_note_amounts( $refund_id ) ?? array( 'total_minor' => (int) $refund['amount_minor'], 'tax_minor' => (int) $refund['tax_minor'] );
		$saved = $wpdb->insert( "{$p}wpcap_credit_notes", array(
			'invoice_id' => (int) $invoice->invoice_id,
			'refund_id' => absint( $refund_id ),
			'credit_note_number' => $number,
			'currency' => $refund['currency'],
			'net_minor' => (int) $credit_amounts['total_minor'] - (int) $credit_amounts['tax_minor'],
			'tax_minor' => (int) $credit_amounts['tax_minor'],
			'total_minor' => (int) $credit_amounts['total_minor'],
			'issued_at' => current_time( 'mysql', true ),
		) );
		if ( false === $saved ) {
			$wpdb->query( 'ROLLBACK' );
			// A concurrent call created it first.
			return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_credit_notes WHERE refund_id=%d", absint( $refund_id ) ), ARRAY_A );
		}
		$wpdb->update( "{$p}wpcap_refunds", array( 'credit_note_number' => $number ), array( 'refund_id' => absint( $refund_id ) ) );
		$wpdb->query( 'COMMIT' );
		Audit::record( 'credit_note_issued', 'invoice', (int) $invoice->invoice_id, array( 'refund_id' => absint( $refund_id ), 'number' => $number ) );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_credit_notes WHERE refund_id=%d", absint( $refund_id ) ), ARRAY_A );
	}

	/** Platform seller data for documents (settings "Rechnungssteller"). */
	public static function seller() {
		return array(
			'name' => (string) Settings::get( 'seller_name' ),
			'address' => (string) Settings::get( 'seller_address' ),
			'vat_id' => (string) Settings::get( 'seller_vat_id' ),
			'tax_number' => (string) Settings::get( 'seller_tax_number' ),
			'country' => Partners\SalesModel::country( Settings::get( 'seller_country' ) ),
		);
	}

	/* ------------------------------------------------------------------ Seller side / sales models */

	/**
	 * Who issues the customer invoice of an order (platform = own_name; organizer = agent model).
	 *
	 * @param object[] $items Order items of the order.
	 * @return array|\WP_Error See Partners\SalesModel::order_context().
	 */
	public static function seller_context( $order_id, array $items = array() ) {
		$context = Partners\SalesModel::order_context( $order_id );
		if ( ! is_wp_error( $context ) ) {
			$valid = Partners\SalesModel::validate_agent_invoice( $context, $items );
			if ( is_wp_error( $valid ) ) { $context = $valid; }
		}
		if ( is_wp_error( $context ) ) {
			Audit::record( 'invoice_refused', 'order', absint( $order_id ), array( 'code' => $context->get_error_code() ) );
		}
		return $context;
	}

	/** Reserves the invoice number in the seller's range (caller owns the transaction). */
	private static function next_invoice_number( array $context ) {
		if ( Partners\SalesModel::AGENT === $context['model'] ) {
			return self::next_document_number( $context['series'], $context['prefix'], true );
		}
		return self::next_number( 0, true );
	}

	/** Adds the seller side to an invoice snapshot. */
	private static function with_seller_context( array $snapshot, array $context ) {
		$snapshot['seller'] = $context['seller'];
		$snapshot['sales_model'] = $context['model'];
		$snapshot['partner_id'] = (int) $context['partner_id'];
		$snapshot['credit_series'] = $context['credit_series'];
		$snapshot['credit_prefix'] = $context['credit_prefix'];
		if ( $context['agent'] ) { $snapshot['agent'] = $context['agent']; }
		return $snapshot;
	}

	/** [series, prefix] for corrections of an invoice (snapshot of the invoice). */
	private static function credit_series_for( array $snapshot ) {
		if ( Partners\SalesModel::AGENT === ( $snapshot['sales_model'] ?? '' ) && ! empty( $snapshot['credit_series'] ) ) {
			return array( (string) $snapshot['credit_series'], (string) $snapshot['credit_prefix'] );
		}
		return array( 'credit', (string) Settings::get( 'credit_note_prefix' ) ?: 'GS' );
	}

	/** Seller block lines: name first, then address lines, VAT ID, tax number. */
	public static function seller_lines( array $seller ) {
		$lines = array( (string) ( $seller['name'] ?? '' ) );
		foreach ( preg_split( '/\r\n|\r|\n/', (string) ( $seller['address'] ?? '' ) ) as $line ) {
			if ( '' !== trim( $line ) ) { $lines[] = trim( $line ); }
		}
		if ( ! empty( $seller['vat_id'] ) ) { $lines[] = __( 'USt-IdNr.:', 'wp-pass-captain' ) . ' ' . $seller['vat_id']; }
		if ( ! empty( $seller['tax_number'] ) ) { $lines[] = __( 'Steuernummer:', 'wp-pass-captain' ) . ' ' . $seller['tax_number']; }
		return $lines;
	}

	/** Seller-side notes printed below the totals (agent, small business). */
	public static function seller_notes( array $snapshot ) {
		$notes = array();
		$seller = (array) ( $snapshot['seller'] ?? array() );
		if ( ! empty( $seller['small_business_note'] ) ) { $notes[] = (string) $seller['small_business_note']; }
		if ( ! empty( $snapshot['agent'] ) ) {
			$agent = (array) $snapshot['agent'];
			$address = trim( preg_replace( '/\s*[\r\n]+\s*/', ', ', (string) ( $agent['address'] ?? '' ) ) );
			/* translators: %s: platform name */
			$notes[] = sprintf( __( 'Vermittelt durch %s', 'wp-pass-captain' ), (string) ( $agent['name'] ?? '' ) ) . ( '' !== $address ? ', ' . $address : '' ) . ( ! empty( $agent['vat_id'] ) ? ' (' . __( 'USt-IdNr.:', 'wp-pass-captain' ) . ' ' . $agent['vat_id'] . ')' : '' );
			/* translators: %s: organizer name */
			$notes[] = sprintf( __( 'Die Leistung wird von %s erbracht. Die Plattform handelt im Namen und für Rechnung des Veranstalters.', 'wp-pass-captain' ), (string) ( $seller['name'] ?? '' ) );
		}
		return $notes;
	}

	/** Sales model label of an issued invoice (admin views). */
	public static function seller_label( array $snapshot ) {
		$seller = (array) ( $snapshot['seller'] ?? array() );
		if ( Partners\SalesModel::AGENT === ( $snapshot['sales_model'] ?? '' ) ) {
			/* translators: %s: organizer */
			return sprintf( __( 'Vermittler – Rechnungssteller: %s', 'wp-pass-captain' ), (string) ( $seller['name'] ?? '' ) );
		}
		return __( 'Eigenhändler – Rechnungssteller: Plattform', 'wp-pass-captain' );
	}

	/**
	 * Reserves the next invoice number. With $in_transaction the caller owns the transaction, so
	 * the increment is rolled back together with a failed invoice.
	 */
	public static function next_number( $year = 0, $in_transaction = false ) {
		global $wpdb;
		// Year of the invoice date in the site timezone (an invoice issued on 1 January 00:30 local time belongs to the new year).
		$year = $year ? absint( $year ) : self::local_year();
		$table = $wpdb->prefix . 'wpcap_invoice_sequences';
		if ( ! $in_transaction ) { $wpdb->query( 'START TRANSACTION' ); }
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $table (year,next_number) VALUES (%d,1)", $year ) );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT next_number FROM $table WHERE year=%d FOR UPDATE", $year ) );
		if ( ! $row ) { if ( ! $in_transaction ) { $wpdb->query( 'ROLLBACK' ); } return new \WP_Error( 'wpcap_invoice_sequence', __( 'Rechnungsnummer konnte nicht reserviert werden.', 'wp-pass-captain' ) ); }
		$number = (int) $row->next_number;
		$updated = $wpdb->update( $table, array( 'next_number' => $number + 1 ), array( 'year' => $year ), array( '%d' ), array( '%d' ) );
		if ( false === $updated ) { if ( ! $in_transaction ) { $wpdb->query( 'ROLLBACK' ); } return new \WP_Error( 'wpcap_invoice_sequence', __( 'Rechnungsnummer konnte nicht reserviert werden.', 'wp-pass-captain' ) ); }
		if ( ! $in_transaction ) { $wpdb->query( 'COMMIT' ); }
		$prefix = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) Settings::get( 'invoice_prefix' ) ) ?: 'WPC';
		return sprintf( '%s-%d-%06d', $prefix, $year, $number );
	}

	/** Current year in the site timezone. */
	public static function local_year( $timestamp = null ) {
		return (int) wp_date( 'Y', null === $timestamp ? time() : (int) $timestamp, Frontend\Format::site_timezone() );
	}

	/**
	 * Net, tax and gross per VAT rate (rate as float string => array{net,tax,gross} in minor units).
	 *
	 * @param object[] $lines Invoice lines (tax_rate, tax_minor, total_minor = gross after discount).
	 */
	public static function rate_summary( array $lines ) {
		$rates = array();
		foreach ( $lines as $line ) {
			$key = (string) (float) $line->tax_rate;
			$rates[ $key ] = $rates[ $key ] ?? array( 'net' => 0, 'tax' => 0, 'gross' => 0 );
			$rates[ $key ]['tax'] += (int) $line->tax_minor;
			$rates[ $key ]['gross'] += (int) $line->total_minor;
			$rates[ $key ]['net'] = $rates[ $key ]['gross'] - $rates[ $key ]['tax'];
		}
		ksort( $rates, SORT_NUMERIC );
		return $rates;
	}

	/** Gapless numbers for further document series (e.g. credit notes); caller may own the transaction. */
	public static function next_document_number( $series, $prefix, $in_transaction = false ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcap_document_sequences';
		$series = sanitize_key( $series );
		$year = self::local_year();
		if ( ! $in_transaction ) { $wpdb->query( 'START TRANSACTION' ); }
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $table (series,year,next_number) VALUES (%s,%d,1)", $series, $year ) );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT next_number FROM $table WHERE series=%s AND year=%d FOR UPDATE", $series, $year ) );
		if ( ! $row ) { if ( ! $in_transaction ) { $wpdb->query( 'ROLLBACK' ); } return new \WP_Error( 'wpcap_document_sequence', __( 'Belegnummer konnte nicht reserviert werden.', 'wp-pass-captain' ) ); }
		$number = (int) $row->next_number;
		$wpdb->query( $wpdb->prepare( "UPDATE $table SET next_number=%d WHERE series=%s AND year=%d", $number + 1, $series, $year ) );
		if ( ! $in_transaction ) { $wpdb->query( 'COMMIT' ); }
		return sprintf( '%s-%d-%06d', preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $prefix ) ?: 'DOC', $year, $number );
	}

	/** Renders an invoice and its credit notes as a printable HTML document. */
	public static function render_html( $invoice_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$invoice = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_invoices WHERE invoice_id=%d", absint( $invoice_id ) ) );
		if ( ! $invoice ) { return ''; }
		$lines = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}wpcap_invoice_lines WHERE invoice_id=%d ORDER BY invoice_line_id", $invoice->invoice_id ) );
		$credits = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}wpcap_credit_notes WHERE invoice_id=%d ORDER BY credit_note_id", $invoice->invoice_id ) );
		$snapshot = (array) json_decode( (string) $invoice->buyer_snapshot, true );
		$seller = (array) ( $snapshot['seller'] ?? self::seller() );
		$money = static function ( $minor ) use ( $invoice ) { return number_format_i18n( $minor / 100, 2 ) . ' ' . $invoice->currency; };
		$rates = self::rate_summary( $lines );
		ob_start();
		?><!doctype html><html lang="<?php echo esc_attr( str_replace( '_', '-', determine_locale() ) ); ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html( $invoice->invoice_number ); ?></title><style>body{font:14px/1.45 system-ui,sans-serif;max-width:52rem;margin:2rem auto;padding:0 1rem;color:#1d2327}table{width:100%;border-collapse:collapse;margin:1rem 0}th,td{border-bottom:1px solid #dcdcde;padding:.4rem;text-align:left}td.n,th.n{text-align:right}caption{text-align:left;font-weight:600;padding:.25rem 0}.seller,.buyer{white-space:pre-line}.buyer{margin:1rem 0}@media print{button{display:none}}</style></head><body>
		<?php $seller_lines = self::seller_lines( $seller ); ?>
		<header><div class="seller"><strong><?php echo esc_html( array_shift( $seller_lines ) ); ?></strong>
<?php echo esc_html( implode( "\n", $seller_lines ) ); ?></div></header>
		<h1><?php echo esc_html( __( 'Rechnung', 'wp-pass-captain' ) . ' ' . $invoice->invoice_number ); ?></h1>
		<p><?php echo esc_html( __( 'Rechnungsdatum:', 'wp-pass-captain' ) . ' ' . wp_date( 'd.m.Y', strtotime( $invoice->issued_at . ' UTC' ) ) ); ?><?php if ( ! Billing::address_lines( $snapshot ) ) : ?><br><?php echo esc_html( __( 'Rechnungsempfänger:', 'wp-pass-captain' ) . ' ' . ( $snapshot['email'] ?? '' ) ); ?><?php endif; ?></p>
		<?php if ( Billing::address_lines( $snapshot ) ) : ?><div class="buyer"><strong><?php esc_html_e( 'Rechnungsempfänger:', 'wp-pass-captain' ); ?></strong>
<?php echo esc_html( implode( "\n", array_merge( Billing::address_lines( $snapshot ), Billing::detail_lines( $snapshot ), array( (string) ( $snapshot['email'] ?? '' ) ) ) ) ); ?></div><?php endif; ?>
		<table><thead><tr><th><?php esc_html_e( 'Leistung', 'wp-pass-captain' ); ?></th><th class="n"><?php esc_html_e( 'Menge', 'wp-pass-captain' ); ?></th><th class="n"><?php esc_html_e( 'Einzelpreis', 'wp-pass-captain' ); ?></th><th class="n"><?php esc_html_e( 'Rabatt', 'wp-pass-captain' ); ?></th><th class="n"><?php esc_html_e( 'USt.', 'wp-pass-captain' ); ?></th><th class="n"><?php esc_html_e( 'Gesamt (brutto)', 'wp-pass-captain' ); ?></th></tr></thead><tbody>
		<?php foreach ( $lines as $line ) : ?><tr><td><?php echo esc_html( $line->description ); ?></td><td class="n"><?php echo esc_html( $line->quantity ); ?></td><td class="n"><?php echo esc_html( $money( (int) $line->unit_minor ) ); ?></td><td class="n"><?php echo esc_html( $money( (int) $line->discount_minor ) ); ?></td><td class="n"><?php echo esc_html( number_format_i18n( (float) $line->tax_rate, 2 ) . ' %' ); ?></td><td class="n"><?php echo esc_html( $money( (int) $line->total_minor ) ); ?></td></tr><?php endforeach; ?>
		</tbody></table>
		<table class="wpcap-vat-summary"><caption><?php esc_html_e( 'Aufstellung nach Steuersätzen', 'wp-pass-captain' ); ?></caption><thead><tr><th><?php esc_html_e( 'USt-Satz', 'wp-pass-captain' ); ?></th><th class="n"><?php esc_html_e( 'Netto', 'wp-pass-captain' ); ?></th><th class="n"><?php esc_html_e( 'USt.', 'wp-pass-captain' ); ?></th><th class="n"><?php esc_html_e( 'Brutto', 'wp-pass-captain' ); ?></th></tr></thead><tbody>
		<?php foreach ( $rates as $rate => $sum ) : ?><tr><td><?php echo esc_html( number_format_i18n( (float) $rate, 2 ) . ' %' ); ?></td><td class="n"><?php echo esc_html( $money( $sum['net'] ) ); ?></td><td class="n"><?php echo esc_html( $money( $sum['tax'] ) ); ?></td><td class="n"><?php echo esc_html( $money( $sum['gross'] ) ); ?></td></tr><?php endforeach; ?>
		</tbody></table>
		<table><tbody>
		<tr><td><?php esc_html_e( 'Nettobetrag', 'wp-pass-captain' ); ?></td><td class="n"><?php echo esc_html( $money( (int) $invoice->total_minor - (int) $invoice->tax_minor ) ); ?></td></tr>
		<?php foreach ( $rates as $rate => $sum ) : ?><tr><td><?php echo esc_html( sprintf( /* translators: %s: rate */ __( 'Umsatzsteuer %s %%', 'wp-pass-captain' ), number_format_i18n( (float) $rate, 2 ) ) ); ?></td><td class="n"><?php echo esc_html( $money( $sum['tax'] ) ); ?></td></tr><?php endforeach; ?>
		<tr><th><?php esc_html_e( 'Rechnungsbetrag', 'wp-pass-captain' ); ?></th><th class="n"><?php echo esc_html( $money( (int) $invoice->total_minor ) ); ?></th></tr>
		</tbody></table>
		<?php echo self::payments_html( $invoice, $snapshot, $money ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in payments_html(). ?>
		<?php foreach ( self::seller_notes( $snapshot ) as $note ) : ?><p class="wpcap-seller-note"><?php echo esc_html( $note ); ?></p><?php endforeach; ?>
		<?php foreach ( $credits as $credit ) : ?><h2><?php echo esc_html( __( 'Rechnungskorrektur', 'wp-pass-captain' ) . ' ' . $credit->credit_note_number ); ?></h2><p><?php echo esc_html( sprintf( /* translators: 1: invoice number, 2: date */ __( 'Korrektur zu Rechnung %1$s vom %2$s', 'wp-pass-captain' ), $invoice->invoice_number, wp_date( 'd.m.Y', strtotime( $credit->issued_at . ' UTC' ) ) ) ); ?></p><table><tbody><tr><td><?php esc_html_e( 'Nettobetrag', 'wp-pass-captain' ); ?></td><td class="n">-<?php echo esc_html( $money( (int) $credit->net_minor ) ); ?></td></tr><tr><td><?php esc_html_e( 'Umsatzsteuer', 'wp-pass-captain' ); ?></td><td class="n">-<?php echo esc_html( $money( (int) $credit->tax_minor ) ); ?></td></tr><tr><th><?php esc_html_e( 'Korrekturbetrag', 'wp-pass-captain' ); ?></th><th class="n">-<?php echo esc_html( $money( (int) $credit->total_minor ) ); ?></th></tr></tbody></table><?php endforeach; ?>
		<button onclick="window.print()"><?php esc_html_e( 'Drucken oder als PDF speichern', 'wp-pass-captain' ); ?></button>
		</body></html><?php
		return (string) ob_get_clean();
	}

	/** Admin print view: admin-post.php?action=wpcap_print_invoice&order_id=… */
	public static function handle_admin_print() {
		$order_id = absint( $_GET['order_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view, capability and scope checked.
		if ( ! self::can_view_order_finance( $order_id ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 ); }
		global $wpdb;
		$exists = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT invoice_id FROM {$wpdb->prefix}wpcap_invoices WHERE order_id=%d LIMIT 1", $order_id ) );
		// Viewing is read-only; creating a new invoice number is a state change and needs a nonce.
		if ( ! $exists ) { check_admin_referer( 'wpcap_issue_invoice_' . $order_id ); }
		$result = self::issue_for_order( $order_id );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), 409 ); }
		nocache_headers();
		echo self::render_html( $result['invoice_id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_html.
		exit;
	}

	/** Buyer view through the personal order link: ?wpcap_invoice=<public_id>&wpcap_access=<token> */
	public static function maybe_render_customer_invoice() {
		$public_id = sanitize_text_field( wp_unslash( $_GET['wpcap_invoice'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = strtolower( preg_replace( '/[^a-f0-9]/', '', (string) wp_unslash( $_GET['wpcap_access'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $public_id || 64 !== strlen( $token ) ) { return; }
		global $wpdb;
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_orders WHERE public_id=%s", $public_id ) );
		if ( ! $order || ! $order->access_expires_at || $order->access_expires_at <= current_time( 'mysql', true ) || ! hash_equals( (string) $order->access_token_hash, hash( 'sha256', $token ) ) ) { status_header( 404 ); exit; }
		$invoice_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT invoice_id FROM {$wpdb->prefix}wpcap_invoices WHERE order_id=%d", $order->order_id ) );
		if ( ! $invoice_id ) { status_header( 404 ); exit; }
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		echo self::render_html( $invoice_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_html.
		exit;
	}

	/**
	 * Accounting export (CSV, one row per invoice line and credit note) for a date range.
	 * admin-post.php?action=wpcap_export_accounting&from=YYYY-MM-DD&to=YYYY-MM-DD&_wpnonce=…
	 */
	public static function handle_export() {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( self::FINANCE_CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 ); }
		check_admin_referer( 'wpcap_export_accounting' );
		$from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $_REQUEST['from'] ?? '' ) ) ? sanitize_text_field( wp_unslash( $_REQUEST['from'] ) ) : gmdate( 'Y-m-01' );
		$to = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $_REQUEST['to'] ?? '' ) ) ? sanitize_text_field( wp_unslash( $_REQUEST['to'] ) ) : gmdate( 'Y-m-d' );
		$rows = self::export_rows( $from, $to );
		Audit::record( 'accounting_exported', 'invoice', 0, array( 'from' => $from, 'to' => $to, 'rows' => count( $rows ) ) );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="wp-pass-captain-buchhaltung-' . $from . '-' . $to . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		foreach ( $rows as $row ) { fputcsv( $out, $row, ';', '"', '' ); } // Explicit escape (PHP 8.4 deprecates the default); '' = RFC 4180 quoting only.
		fclose( $out );
		exit;
	}

	/** Builds export rows (first row = header). Amounts are decimal strings; text cells are CSV-injection safe. */
	public static function export_rows( $from, $to ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$from_utc = $from . ' 00:00:00';
		$to_utc = gmdate( 'Y-m-d H:i:s', strtotime( $to . ' 00:00:00 UTC' ) + DAY_IN_SECONDS );
		$rows = array( array( 'Belegart', 'Belegnummer', 'Belegdatum', 'Bestellung', 'Bezug', 'Beschreibung', 'Menge', 'Netto', 'USt-Satz', 'USt', 'Brutto', 'Waehrung', 'Zahlungsart', 'Verkaufsmodell', 'Rechnungssteller' ) );
		// Seller columns: agent-model documents are issued in the organizer's name (not platform revenue).
		$seller_cols = static function ( $snapshot_json ) use ( &$safe ) {
			$snapshot = (array) json_decode( (string) $snapshot_json, true );
			$agent = Partners\SalesModel::AGENT === ( $snapshot['sales_model'] ?? '' );
			return array( $agent ? 'Vermittler' : 'Eigenhaendler', $safe( (string) ( $snapshot['seller']['name'] ?? '' ) ) );
		};
		$money = static function ( $minor ) { return number_format( $minor / 100, 2, '.', '' ); };
		$safe = static function ( $text ) { $text = (string) $text; return preg_match( '/^[=+\-@\t\r]/', $text ) ? "'" . $text : $text; };
		$lines = $wpdb->get_results( $wpdb->prepare( "SELECT i.invoice_number,i.issued_at,i.currency,i.buyer_snapshot,o.public_id,o.payment_provider,l.description,l.quantity,l.tax_rate,l.tax_minor,l.total_minor FROM {$p}wpcap_invoices i INNER JOIN {$p}wpcap_orders o ON o.order_id=i.order_id INNER JOIN {$p}wpcap_invoice_lines l ON l.invoice_id=i.invoice_id WHERE i.issued_at >= %s AND i.issued_at < %s ORDER BY i.invoice_id,l.invoice_line_id", $from_utc, $to_utc ) );
		foreach ( $lines as $l ) {
			$rows[] = array( 'Rechnung', $l->invoice_number, substr( $l->issued_at, 0, 10 ), $l->public_id, '', $safe( $l->description ), (int) $l->quantity, $money( (int) $l->total_minor - (int) $l->tax_minor ), number_format( (float) $l->tax_rate, 2, '.', '' ), $money( (int) $l->tax_minor ), $money( (int) $l->total_minor ), $l->currency, $safe( $l->payment_provider ), ...$seller_cols( $l->buyer_snapshot ) );
		}
		$credits = $wpdb->get_results( $wpdb->prepare( "SELECT c.*,i.invoice_number,i.buyer_snapshot,o.public_id,o.payment_provider FROM {$p}wpcap_credit_notes c INNER JOIN {$p}wpcap_invoices i ON i.invoice_id=c.invoice_id INNER JOIN {$p}wpcap_orders o ON o.order_id=i.order_id WHERE c.issued_at >= %s AND c.issued_at < %s ORDER BY c.credit_note_id", $from_utc, $to_utc ) );
		foreach ( $credits as $c ) {
			$rows[] = array( 'Gutschrift', $c->credit_note_number, substr( $c->issued_at, 0, 10 ), $c->public_id, $c->invoice_number, 'Erstattung', '', '-' . $money( (int) $c->net_minor ), '', '-' . $money( (int) $c->tax_minor ), '-' . $money( (int) $c->total_minor ), $c->currency, $safe( $c->payment_provider ), ...$seller_cols( $c->buyer_snapshot ) );
		}
		return $rows;
	}

	/** Finance view permission for one order (global finance roles or event-scoped order managers). */
	private static function can_view_order_finance( $order_id ) {
		if ( current_user_can( 'manage_options' ) || current_user_can( self::FINANCE_CAP ) ) { return true; }
		global $wpdb;
		$event_ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT o.event_id FROM {$wpdb->prefix}wpcap_order_items oi INNER JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE oi.order_id=%d", absint( $order_id ) ) );
		if ( ! $event_ids ) { return false; }
		foreach ( $event_ids as $event_id ) {
			if ( ! Access::can_access_event( (int) $event_id, 'wpcap_manage_orders' ) ) { return false; }
		}
		return true;
	}

	/* ------------------------------------------------------------------ Payments / vouchers (docs/VOUCHERS.md) */

	/**
	 * Section "Zahlungen" of an invoice with a voucher: multi-purpose vouchers are listed as payments (code
	 * masked) followed by the remaining amount paid; the VAT breakdown above stays on the full amounts.
	 * Single-purpose vouchers were a price reduction and only get a note. Empty for invoices without voucher.
	 *
	 * @return array{rows: array<int, array{0:string,1:int,2:bool}>, notes: string[]} Rows: label, amount (minor), bold.
	 */
	public static function payment_summary( $invoice, array $snapshot ) {
		$out = array( 'rows' => array(), 'notes' => array() );
		$payments = isset( $snapshot['payments'] ) ? (array) $snapshot['payments'] : Integrations\Vouchers\Checkout::invoice_payments( (int) $invoice->order_id );
		if ( ! $payments || empty( $payments['vouchers'] ) ) { return $out; }
		$voucher_total = 0;
		foreach ( (array) $payments['vouchers'] as $voucher ) {
			$code = Integrations\Vouchers\Checkout::masked_code( $voucher['code_hint'] ?? '' );
			if ( Integrations\Vouchers\Checkout::PURPOSE_MULTI === ( $voucher['purpose'] ?? '' ) ) {
				$amount = (int) ( $voucher['amount_minor'] ?? 0 );
				$voucher_total += $amount;
				/* translators: %s: masked voucher code */
				$out['rows'][] = array( sprintf( __( 'Bezahlt mit Gutschein %s (Mehrzweckgutschein)', 'wp-pass-captain' ), $code ), -$amount, false );
			} else {
				/* translators: %s: masked voucher code */
				$out['notes'][] = sprintf( __( 'Eingelöster Einzweckgutschein %s: Die Umsatzsteuer wurde bereits beim Verkauf des Gutscheins abgerechnet; der Gutscheinwert ist als Preisnachlass berücksichtigt.', 'wp-pass-captain' ), $code );
			}
		}
		if ( $out['rows'] ) {
			$out['rows'][] = array( __( 'Restbetrag (bezahlt)', 'wp-pass-captain' ), max( 0, (int) $invoice->total_minor - $voucher_total ), true );
			$out['notes'][] = __( 'Der Gutschein ist ein Zahlungsmittel (Mehrzweckgutschein); die Umsatzsteuer ist auf den vollen Rechnungsbetrag ausgewiesen.', 'wp-pass-captain' );
		}
		return $out;
	}

	/** HTML of the "Zahlungen" section for render_html() (empty string without voucher). */
	private static function payments_html( $invoice, array $snapshot, callable $money ) {
		$summary = self::payment_summary( $invoice, $snapshot );
		if ( ! $summary['rows'] && ! $summary['notes'] ) { return ''; }
		$html = '<section class="wpcap-payments"><h2>' . esc_html__( 'Zahlungen', 'wp-pass-captain' ) . '</h2>';
		if ( $summary['rows'] ) {
			$html .= '<table><tbody>';
			foreach ( $summary['rows'] as $row ) {
				$cell = $row[2] ? 'th' : 'td';
				$html .= '<tr><' . $cell . '>' . esc_html( $row[0] ) . '</' . $cell . '><' . $cell . ' class="n">' . esc_html( ( $row[1] < 0 ? '-' : '' ) . $money( abs( (int) $row[1] ) ) ) . '</' . $cell . '></tr>';
			}
			$html .= '</tbody></table>';
		}
		foreach ( $summary['notes'] as $note ) { $html .= '<p class="wpcap-payment-note">' . esc_html( $note ) . '</p>'; }
		return $html . '</section>';
	}
}

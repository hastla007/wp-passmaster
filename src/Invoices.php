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

	/**
	 * Default prefix of refund corrections ("Rechnungskorrektur"). "Gutschrift" is reserved for self-billing
	 * (§ 14 Abs. 2 Satz 2 UStG, partner settlements, prefix GU); installs from before schema 3.8.3 keep the
	 * former default 'GS' (see Installer::pin_legacy_credit_prefix()).
	 */
	const CREDIT_PREFIX_DEFAULT = 'RK';

	/** Former default correction prefix (kept for existing installs). */
	const CREDIT_PREFIX_LEGACY = 'GS';

	public static function register() {
		add_action( 'wpcap_order_paid', array( __CLASS__, 'maybe_auto_issue' ) );
		add_action( 'wpcap_refund_completed', array( __CLASS__, 'credit_note_for_refund' ) );
		add_action( 'admin_post_wpcap_print_invoice', array( __CLASS__, 'handle_admin_print' ) );
		add_action( 'admin_post_wpcap_export_accounting', array( __CLASS__, 'handle_export' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render_customer_invoice' ) );
		// Add-on attachments (e.g. e-invoice XML) for the ticket mail of an order with invoice.
		add_filter( 'wpcap_ticket_mail', array( Api\Invoices::class, 'filter_ticket_mail' ), 30, 2 );
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
		$order = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d', $p . 'wpcap_orders', $order_id ) );
		if ( ! $order || 'paid' !== $order->payment_status ) { return new \WP_Error( 'wpcap_invoice_order_unpaid', __( 'Eine Rechnung kann erst für bezahlte Bestellungen erstellt werden.', 'pass-captain' ), array( 'status' => 409 ) ); }
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT invoice_id,invoice_number FROM %i WHERE order_id=%d LIMIT 1', $p . 'wpcap_invoices', $order_id ) );
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
		$locked_order = $wpdb->get_row( $wpdb->prepare( 'SELECT payment_status FROM %i WHERE order_id=%d FOR UPDATE', $p . 'wpcap_orders', $order_id ) );
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT invoice_id,invoice_number FROM %i WHERE order_id=%d', $p . 'wpcap_invoices', $order_id ) );
		if ( $existing ) { $wpdb->query( 'COMMIT' ); return array( 'invoice_id' => (int) $existing->invoice_id, 'invoice_number' => $existing->invoice_number, 'reused' => true ); }
		if ( ! $locked_order || 'paid' !== $locked_order->payment_status ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_invoice_order_unpaid', __( 'Die Bestellung ist nicht mehr als bezahlt markiert.', 'pass-captain' ), array( 'status' => 409 ) ); }
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
		// Place-of-supply notes from the tax profile snapshots of the order lines (Sales\TaxProfiles).
		$tax_notes = Sales\TaxProfiles::invoice_notes( $items );
		if ( $tax_notes ) { $snapshot['tax_notes'] = $tax_notes; }
		$saved = $wpdb->insert( $p . 'wpcap_invoices', array( 'order_id' => $order_id, 'invoice_number' => $number, 'currency' => $order->currency, 'subtotal_minor' => $order_subtotal, 'tax_minor' => (int) $order->tax_minor, 'total_minor' => $invoice_total, 'buyer_snapshot' => wp_json_encode( $snapshot ), 'issued_at' => current_time( 'mysql', true ), 'status' => 'issued' ) );
		$invoice_id = (int) $wpdb->insert_id;
		if ( false === $saved || ! $invoice_id ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_invoice_create', __( 'Rechnung konnte nicht erstellt werden.', 'pass-captain' ), array( 'status' => 500 ) ); }
		// Same allocation as orders, reports and settlements (stored line discounts, OrderLines). Orders
		// that only carry the split in their price snapshot (line_discounts) use that split.
		$items = array_values( $items );
		$after = OrderLines::after_discount( $items, (int) $order->discount_minor );
		$stored_discounts = Orders::snapshot_line_discounts( $order );
		$line_discount_sum = array_sum( array_map( static function ( $item ) { return (int) ( $item->discount_minor ?? 0 ); }, $items ) );
		if ( null !== $stored_discounts && 0 === $line_discount_sum && (int) $order->discount_minor > 0 ) {
			foreach ( $items as $index => $item ) {
				if ( isset( $stored_discounts[ (int) $item->ticket_type_id ] ) ) { $after[ $index ] = max( 0, (int) $item->line_total_minor - min( (int) $item->line_total_minor, (int) $stored_discounts[ (int) $item->ticket_type_id ] ) ); }
			}
		}
		foreach ( $items as $index => $item ) {
			$line_total = (int) $after[ $index ];
			$line_discount = max( 0, (int) $item->line_total_minor - $line_total );
			$description = $item->event_name . ' – ' . $item->name . ' (' . Frontend\Format::local( $item->starts_at, (string) $item->timezone ) . ')';
			$line_saved = $wpdb->insert( $p . 'wpcap_invoice_lines', array( 'invoice_id' => $invoice_id, 'description' => mb_substr( $description, 0, 190 ), 'quantity' => $item->quantity, 'unit_minor' => $item->unit_price_minor, 'discount_minor' => $line_discount, 'tax_rate' => (int) $item->tax_rate_bp / 100, 'tax_minor' => (int) $item->tax_minor, 'total_minor' => $line_total ) );
			if ( false === $line_saved ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_invoice_line', __( 'Rechnungsposition konnte nicht gespeichert werden.', 'pass-captain' ), array( 'status' => 500 ) ); }
		}
		$wpdb->query( 'COMMIT' );
		Audit::record( 'invoice_issued', 'invoice', $invoice_id, array( 'order_id' => $order_id, 'invoice_number' => $number, 'sales_model' => $seller_context['model'], 'partner_id' => $seller_context['partner_id'] ) );
		Api\Invoices::created( $invoice_id, 'invoice', array( 'order_id' => $order_id, 'number' => $number ) );
		// Refunds completed before the invoice existed: the invoice shows the full consideration, so
		// each of them gets its correction document now (same as a refund after the invoice, PAY-7).
		foreach ( array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT r.refund_id FROM %i r LEFT JOIN %i c ON c.refund_id=r.refund_id WHERE r.order_id=%d AND r.status=%s AND c.credit_note_id IS NULL ORDER BY r.refund_id', $p . 'wpcap_refunds', $p . 'wpcap_credit_notes', $order_id, 'completed' ) ) ) as $refund_id ) {
			self::credit_note_for_refund( $refund_id );
		}
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
		$invoice = $wpdb->get_row( $wpdb->prepare( 'SELECT invoice_id,buyer_snapshot FROM %i WHERE order_id=%d', $p . 'wpcap_invoices', (int) $refund['order_id'] ) );
		if ( ! $invoice ) { return null; }
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE refund_id=%d', $p . 'wpcap_credit_notes', absint( $refund_id ) ), ARRAY_A );
		if ( $existing ) { return $existing; }
		$wpdb->query( 'START TRANSACTION' );
		// Corrections follow the seller (and number range owner) of the corrected invoice.
		list( $credit_series, $credit_prefix ) = self::credit_series_for( (array) json_decode( (string) $invoice->buyer_snapshot, true ) );
		$number = self::next_document_number( $credit_series, $credit_prefix, true );
		if ( is_wp_error( $number ) ) { $wpdb->query( 'ROLLBACK' ); return $number; }
		// Payments/vouchers: with a multi-purpose voucher the correction covers cash + voucher part.
		$credit_amounts = Integrations\Vouchers\Checkout::credit_note_amounts( $refund_id ) ?? array( 'total_minor' => (int) $refund['amount_minor'], 'tax_minor' => (int) $refund['tax_minor'] );
		$row = array(
			'invoice_id' => (int) $invoice->invoice_id,
			'refund_id' => absint( $refund_id ),
			'credit_note_number' => $number,
			'currency' => $refund['currency'],
			'net_minor' => (int) $credit_amounts['total_minor'] - (int) $credit_amounts['tax_minor'],
			'tax_minor' => (int) $credit_amounts['tax_minor'],
			'total_minor' => (int) $credit_amounts['total_minor'],
			'issued_at' => current_time( 'mysql', true ),
		);
		// Net/VAT/gross per tax rate, frozen with the document (schema 3.8.3; older rows are computed on display).
		if ( self::credit_rates_ready() ) {
			$row['rates'] = wp_json_encode( self::credit_note_rates( $refund, (int) $credit_amounts['total_minor'], (int) $credit_amounts['tax_minor'] ) );
		}
		$saved = $wpdb->insert( "{$p}wpcap_credit_notes", $row );
		if ( false === $saved ) {
			$wpdb->query( 'ROLLBACK' );
			// A concurrent call created it first.
			return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE refund_id=%d', $p . 'wpcap_credit_notes', absint( $refund_id ) ), ARRAY_A );
		}
		$credit_note_id = (int) $wpdb->insert_id;
		// Split per VAT rate (§ 14 Abs. 4 Nr. 8 UStG / § 11 UStG AT): one line per rate of the refunded positions.
		foreach ( self::credit_note_split( $refund, (int) $credit_amounts['total_minor'], (int) $credit_amounts['tax_minor'] ) as $line ) {
			$line_saved = $wpdb->insert( "{$p}wpcap_credit_note_lines", array( 'credit_note_id' => $credit_note_id, 'tax_rate_bp' => $line['tax_rate_bp'], 'net_minor' => $line['net_minor'], 'tax_minor' => $line['tax_minor'], 'total_minor' => $line['total_minor'] ) );
			if ( false === $line_saved ) {
				$wpdb->query( 'ROLLBACK' );
				return new \WP_Error( 'wpcap_credit_note_line', __( 'Die Rechnungskorrektur konnte nicht gespeichert werden.', 'pass-captain' ), array( 'status' => 500 ) );
			}
		}
		$wpdb->update( "{$p}wpcap_refunds", array( 'credit_note_number' => $number ), array( 'refund_id' => absint( $refund_id ) ) );
		$wpdb->query( 'COMMIT' );
		Audit::record( 'credit_note_issued', 'invoice', (int) $invoice->invoice_id, array( 'refund_id' => absint( $refund_id ), 'number' => $number ) );
		$credit_note = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE refund_id=%d', $p . 'wpcap_credit_notes', absint( $refund_id ) ), ARRAY_A );
		if ( $credit_note ) {
			Api\Invoices::created( (int) $credit_note['credit_note_id'], 'correction', array( 'invoice_id' => (int) $invoice->invoice_id, 'refund_id' => absint( $refund_id ), 'order_id' => (int) $refund['order_id'], 'number' => $number ) );
		}
		return $credit_note;
	}

	/**
	 * Voids the credit note of a refund that failed at the provider after it was booked (status
	 * `voided`, voided_at). The document and its number stay; print view and PDF mark it as voided and
	 * the accounting export adds a reversal row ("Storno Gutschrift") in the period of the voiding.
	 */
	public static function void_credit_note_for_refund( $refund_id ) {
		global $wpdb;
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='voided',voided_at=%s WHERE refund_id=%d AND status='issued'", $wpdb->prefix . 'wpcap_credit_notes', current_time( 'mysql', true ), absint( $refund_id ) ) );
		if ( $updated ) {
			Audit::record( 'credit_note_voided', 'invoice', 0, array( 'refund_id' => absint( $refund_id ), 'reason' => 'provider_refund_failed' ) );
		}
		return (bool) $updated;
	}

	/**
	 * Splits a credit note amount per VAT rate. The weights come from the refunded tickets (their
	 * discounted order line value) or, for an amount-only refund, from all order lines. The gross is
	 * distributed exactly (Orders::allocate) and the credit note's VAT in proportion to the VAT each
	 * rate's share contains, so the lines always add up to the credit note header.
	 *
	 * @param array $refund Refund row (order_id, ticket_ids).
	 * @return array[] [{tax_rate_bp, net_minor, tax_minor, total_minor}]
	 */
	public static function credit_note_split( array $refund, $total, $tax ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$total = max( 0, (int) $total );
		$tax = max( 0, min( (int) $tax, $total ) );
		$order = Orders::get( (int) ( $refund['order_id'] ?? 0 ) );
		$items = $order ? (array) $wpdb->get_results( $wpdb->prepare( 'SELECT ticket_type_id,quantity,line_total_minor,tax_rate_bp FROM %i WHERE order_id=%d ORDER BY item_id', $p . 'wpcap_order_items', (int) $order->order_id ) ) : array();
		if ( ! $items ) {
			return array( array( 'tax_rate_bp' => 0, 'net_minor' => $total - $tax, 'tax_minor' => $tax, 'total_minor' => $total ) );
		}
		$stored = Orders::snapshot_line_discounts( $order );
		$amounts = array();
		foreach ( $items as $index => $item ) { $amounts[ $index ] = (int) $item->line_total_minor; }
		$split = Orders::allocate( $amounts, (int) $order->discount_minor );
		$ticket_ids = array_values( array_filter( array_map( 'absint', (array) json_decode( (string) ( $refund['ticket_ids'] ?? '' ), true ) ) ) );
		$counts = array();
		if ( $ticket_ids ) {
			$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT ticket_type_id, COUNT(*) AS n FROM %i WHERE order_id=%d AND ticket_id IN (' . implode( ',', array_fill( 0, count( $ticket_ids ), '%d' ) ) . ') GROUP BY ticket_type_id', array_merge( array( $p . 'wpcap_tickets', (int) $order->order_id ), $ticket_ids ) ) );
			foreach ( $rows as $row ) { $counts[ (int) $row->ticket_type_id ] = (int) $row->n; }
		}
		$weights = array();
		foreach ( $items as $index => $item ) {
			$type = (int) $item->ticket_type_id;
			$discount = null !== $stored && isset( $stored[ $type ] ) ? min( (int) $item->line_total_minor, $stored[ $type ] ) : $split[ $index ];
			$line = max( 0, (int) $item->line_total_minor - $discount );
			if ( $counts ) {
				$used = min( (int) $item->quantity, $counts[ $type ] ?? 0 );
				$counts[ $type ] = max( 0, ( $counts[ $type ] ?? 0 ) - $used );
				$line = (int) round( $line * $used / max( 1, (int) $item->quantity ) );
			}
			$rate = max( 0, (int) $item->tax_rate_bp );
			$weights[ $rate ] = ( $weights[ $rate ] ?? 0 ) + $line;
		}
		$weights = array_filter( $weights );
		if ( count( $weights ) <= 1 ) {
			$rate = $weights ? (int) array_key_first( $weights ) : (int) $items[0]->tax_rate_bp;
			return array( array( 'tax_rate_bp' => $rate, 'net_minor' => $total - $tax, 'tax_minor' => $tax, 'total_minor' => $total ) );
		}
		ksort( $weights, SORT_NUMERIC );
		$gross = Orders::allocate( $weights, $total );
		$expected = array();
		foreach ( $gross as $rate => $amount ) { $expected[ $rate ] = Orders::gross_tax( $amount, (int) $rate ); }
		$taxes = array_sum( $expected ) > 0 ? Orders::allocate( $expected, $tax ) : array_fill_keys( array_keys( $gross ), 0 );
		$lines = array();
		foreach ( $gross as $rate => $amount ) {
			$line_tax = min( (int) $taxes[ $rate ], (int) $amount );
			$lines[] = array( 'tax_rate_bp' => (int) $rate, 'net_minor' => (int) $amount - $line_tax, 'tax_minor' => $line_tax, 'total_minor' => (int) $amount );
		}
		return $lines;
	}

	/** Per-rate lines of credit notes (credit_note_id => lines); notes issued before 3.17.4 have none. */
	public static function credit_note_lines( array $credit_note_ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', $credit_note_ids ) ) );
		if ( ! $ids ) { return array(); }
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE credit_note_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY credit_note_id,tax_rate_bp,credit_note_line_id', array_merge( array( $wpdb->prefix . 'wpcap_credit_note_lines' ), $ids ) ) );
		$by_note = array();
		foreach ( $rows as $row ) { $by_note[ (int) $row->credit_note_id ][] = $row; }
		return $by_note;
	}

	/** Whether wpcap_credit_notes.reversal_number exists (schema 3.10.11; not yet during a deferred upgrade). */
	public static function credit_reversal_ready() {
		static $ready = array();
		global $wpdb;
		$key = (string) $wpdb->prefix;
		if ( empty( $ready[ $key ] ) ) {
			$ready[ $key ] = null !== $wpdb->get_row( "SHOW COLUMNS FROM {$wpdb->prefix}wpcap_credit_notes LIKE 'reversal_number'" );
		}
		return $ready[ $key ];
	}

	/**
	 * Reverses the credit note of a refund that failed at the provider after it was booked (A348): the
	 * correction gets a reversal document with its own number from the correction number range
	 * (reversal_number, reversed_at). The credit note itself is never changed otherwise. Idempotent.
	 *
	 * @return array|null|\WP_Error Credit note row, null when the refund has none.
	 */
	public static function reverse_credit_note( $refund_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		if ( ! self::credit_reversal_ready() ) { return null; }
		$credit = $wpdb->get_row( $wpdb->prepare( "SELECT c.*,i.buyer_snapshot FROM {$p}wpcap_credit_notes c INNER JOIN {$p}wpcap_invoices i ON i.invoice_id=c.invoice_id WHERE c.refund_id=%d", absint( $refund_id ) ), ARRAY_A );
		if ( ! $credit ) { return null; }
		if ( '' !== (string) $credit['reversal_number'] ) { return $credit; }
		$wpdb->query( 'START TRANSACTION' );
		list( $series, $prefix ) = self::credit_series_for( (array) json_decode( (string) $credit['buyer_snapshot'], true ) );
		$number = self::next_document_number( $series, $prefix, true );
		if ( is_wp_error( $number ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $number;
		}
		$set = $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_credit_notes SET reversal_number=%s,reversed_at=%s WHERE credit_note_id=%d AND reversal_number=''", $number, current_time( 'mysql', true ), (int) $credit['credit_note_id'] ) );
		if ( 1 !== (int) $set || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_credit_reversal', __( 'Die Rechnungskorrektur konnte nicht storniert werden.', 'pass-captain' ), array( 'status' => 503 ) );
		}
		Audit::record( 'credit_note_reversed', 'invoice', (int) $credit['invoice_id'], array( 'refund_id' => absint( $refund_id ), 'number' => (string) $credit['credit_note_number'], 'reversal_number' => $number ) );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_credit_notes WHERE credit_note_id=%d", (int) $credit['credit_note_id'] ), ARRAY_A );
	}

	/* ------------------------------------------------------------------ Corrections per tax rate */

	/** Whether wpcap_credit_notes.rates exists (schema 3.8.3; not yet during a deferred upgrade). */
	public static function credit_rates_ready() {
		$version = strtok( (string) get_option( 'wpcap_db_version', '' ), '+' );
		return false !== $version && 1 === preg_match( '/^\d+\.\d+/', $version ) && version_compare( $version, '3.8.3', '>=' );
	}

	/**
	 * Breakdown of a correction document per VAT rate (like the rate summary of an invoice).
	 *
	 * The gross amount and the VAT of the correction are allocated to the order lines the refund
	 * belongs to: ticket refunds weight each refunded ticket with its line's paid gross after the
	 * order discount (and its line VAT), amount-only refunds weight all paid lines. Lines are grouped
	 * by rate; gross and VAT are apportioned with the largest-remainder method, so the rows always
	 * add up exactly to the document totals and the result depends only on stored data.
	 *
	 * @param array      $refund   Refund row (order_id, ticket_ids).
	 * @param int        $total    Gross amount of the correction (minor units).
	 * @param int        $tax      VAT of the correction (minor units).
	 * @param array|null $items    Paid lines of the order in item order, already read (exports, A434).
	 * @param int|null   $discount Order discount, already read.
	 * @return array[] List of ['rate_bp' => int, 'net' => int, 'tax' => int, 'gross' => int], ascending by rate.
	 */
	public static function credit_note_rates( array $refund, $total, $tax, $items = null, $discount = null ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$total = (int) $total;
		$tax = (int) $tax;
		$order_id = (int) ( $refund['order_id'] ?? 0 );
		if ( null === $items ) {
			$items = $order_id ? (array) $wpdb->get_results( $wpdb->prepare( 'SELECT ticket_type_id,tax_rate_bp,' . OrderLines::paid_columns( '' ) . " FROM {$p}wpcap_order_items WHERE order_id=%d ORDER BY item_id", $order_id ) ) : array();
		}
		if ( null === $discount ) {
			$discount = $order_id ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT discount_minor FROM {$p}wpcap_orders WHERE order_id=%d", $order_id ) ) : 0;
		}
		$parts = self::refund_line_parts( $refund, $items, $discount, $total, $tax );
		if ( ! $parts ) {
			// No usable lines: one row, rate derived from the amounts.
			$net = $total - $tax;
			$rate = $tax > 0 && $net > 0 ? (int) round( $tax * 10000 / $net ) : 0;
			return array( array( 'rate_bp' => $rate, 'net' => $net, 'tax' => $tax, 'gross' => $total ) );
		}
		$groups = array();
		foreach ( $parts as $part ) {
			$rate = (int) $part['rate_bp'];
			$groups[ $rate ] = array( ( $groups[ $rate ][0] ?? 0 ) + (int) $part['gross'], ( $groups[ $rate ][1] ?? 0 ) + (int) $part['tax'] );
		}
		ksort( $groups, SORT_NUMERIC );
		$out = array();
		foreach ( $groups as $rate => $g ) {
			if ( 0 === $g[0] && 0 === $g[1] ) { continue; }
			$out[] = array( 'rate_bp' => (int) $rate, 'net' => $g[0] - $g[1], 'tax' => $g[1], 'gross' => $g[0] );
		}
		if ( ! $out ) {
			$out[] = array( 'rate_bp' => (int) array_key_last( $groups ), 'net' => 0, 'tax' => 0, 'gross' => 0 );
		}
		return $out;
	}

	/**
	 * Splits a refund/correction onto the paid order lines (shared by correction documents, the sales
	 * report and the settlement sheet, so their figures per tax rate are identical).
	 *
	 * Weights: ticket refunds weight each refunded ticket with its line's paid gross after the order
	 * discount (and its line VAT), filling the lines of the ticket type in item order; amount-only
	 * refunds weight all paid lines. Gross and VAT are first apportioned per tax rate (largest
	 * remainder; VAT by line VAT, so 0 % lines get none) and then onto the lines of each rate. The
	 * parts add up exactly to $total / $tax.
	 *
	 * Earlier completed refunds of the order (lower refund_id) are split first: a line never receives
	 * more gross or VAT over all refunds than it was paid (after discount) or carried as VAT, amount-only
	 * refunds are weighted by what is left of each line, and a final refund of the rest empties every
	 * line exactly (A200). Without refund_id/order_id the refund is split on its own.
	 *
	 * @param array|object $refund   Refund row (order_id, ticket_ids).
	 * @param object[]     $items    Paid order lines in item order (ticket_type_id, tax_rate_bp, quantity,
	 *                               line_total_minor, tax_minor, discount_minor; OrderLines::paid_columns()).
	 * @param int          $discount Order discount (orders.discount_minor).
	 * @param int          $total    Gross amount of the refund (minor units).
	 * @param int          $tax      VAT of the refund (minor units).
	 * @param array[]|null $history  Completed refunds of the order (rows with refund_id, order_id, amount_minor,
	 *                               tax_minor, ticket_ids), already read for many orders (reports, A434);
	 *                               null = read the earlier ones here.
	 * @return array[] List of ['index' => line index, 'rate_bp' => int, 'tickets' => int, 'gross' => int, 'tax' => int];
	 *                 empty when no line carries a paid value.
	 */
	public static function refund_line_parts( $refund, array $items, $discount, $total, $tax, $history = null ) {
		global $wpdb;
		$refund = (array) $refund;
		$items = array_values( $items );
		if ( ! $items ) { return array(); }
		$used_gross = array_fill( 0, count( $items ), 0 );
		$used_tax = $used_gross;
		$order_id = (int) ( $refund['order_id'] ?? 0 );
		$refund_id = (int) ( $refund['refund_id'] ?? 0 );
		if ( $order_id && $refund_id ) {
			$earlier = null !== $history
				? array_values( array_filter( array_map( static function ( $r ) { return (array) $r; }, (array) $history ), static function ( $r ) use ( $refund_id ) { return (int) $r['refund_id'] < $refund_id; } ) )
				: (array) $wpdb->get_results( $wpdb->prepare( "SELECT refund_id,order_id,amount_minor,tax_minor,ticket_ids FROM {$wpdb->prefix}wpcap_refunds WHERE order_id=%d AND refund_id<%d AND status='completed' ORDER BY refund_id", $order_id, $refund_id ), ARRAY_A );
			foreach ( $earlier as $previous ) {
				$amounts = Integrations\Vouchers\Checkout::refund_amounts( $previous );
				foreach ( self::line_parts( $previous, $items, $discount, (int) $amounts['amount_minor'], (int) $amounts['tax_minor'], $used_gross, $used_tax ) as $part ) {
					$used_gross[ $part['index'] ] += $part['gross'];
					$used_tax[ $part['index'] ] += $part['tax'];
				}
			}
		}
		return self::line_parts( $refund, $items, $discount, (int) $total, (int) $tax, $used_gross, $used_tax );
	}

	/**
	 * Completed refunds of many orders for refund_line_parts() (one query per 500 orders, A434).
	 *
	 * @param int[] $order_ids Order ids.
	 * @return array<int, array[]> Order id => completed refunds in refund order (orders without refunds: []).
	 */
	public static function refund_history( array $order_ids ) {
		global $wpdb;
		$out = array();
		foreach ( array_chunk( array_values( array_unique( array_filter( array_map( 'absint', $order_ids ) ) ) ), 500 ) as $chunk ) {
			$out += array_fill_keys( $chunk, array() );
			foreach ( (array) $wpdb->get_results( "SELECT refund_id,order_id,amount_minor,tax_minor,ticket_ids FROM {$wpdb->prefix}wpcap_refunds WHERE order_id IN (" . implode( ',', $chunk ) . ") AND status='completed' ORDER BY refund_id", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
				$out[ (int) $row['order_id'] ][] = $row;
			}
		}
		return $out;
	}

	/**
	 * refund_line_parts() for one refund, given what earlier refunds already took per line.
	 *
	 * @param int[] $used_gross Line index => gross already refunded.
	 * @param int[] $used_tax   Line index => VAT already refunded.
	 */
	private static function line_parts( array $refund, array $items, $discount, $total, $tax, array $used_gross, array $used_tax ) {
		global $wpdb;
		$after_discount = OrderLines::after_discount( $items, (int) $discount );
		// What is left of each line (caps of this refund's parts).
		$left_gross = array();
		$left_tax = array();
		foreach ( $items as $i => $item ) {
			$left_gross[ $i ] = max( 0, (int) $after_discount[ $i ] - (int) ( $used_gross[ $i ] ?? 0 ) );
			$left_tax[ $i ] = max( 0, (int) $item->tax_minor - (int) ( $used_tax[ $i ] ?? 0 ) );
		}
		// Line weights: [tickets, gross after discount, VAT] of the refunded part of each line.
		$weights = array();
		$ids = array_filter( array_map( 'absint', (array) json_decode( (string) ( $refund['ticket_ids'] ?? '' ), true ) ) );
		if ( $ids ) {
			$counts = array();
			$order_id = (int) ( $refund['order_id'] ?? 0 );
			$in = implode( ',', $ids );
			$type_ids = $order_id
				? $wpdb->get_col( $wpdb->prepare( "SELECT ticket_type_id FROM {$wpdb->prefix}wpcap_tickets WHERE order_id=%d AND ticket_id IN ($in)", $order_id ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
				: $wpdb->get_col( "SELECT ticket_type_id FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_id IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
			foreach ( $type_ids as $type_id ) {
				$counts[ (int) $type_id ] = ( $counts[ (int) $type_id ] ?? 0 ) + 1;
			}
			foreach ( $counts as $type_id => $n ) {
				// Paid lines of the type in item order (lines an exchange created carry no paid value).
				foreach ( $items as $i => $item ) {
					if ( $n <= 0 ) { break; }
					if ( (int) $item->ticket_type_id !== $type_id || (int) $item->quantity < 1 ) { continue; }
					$q = (int) $item->quantity;
					$share = min( $n, $q );
					$weights[ $i ] = array(
						$share,
						$share >= $q ? (int) $after_discount[ $i ] : intdiv( (int) $after_discount[ $i ] * $share, $q ),
						$share >= $q ? (int) $item->tax_minor : intdiv( (int) $item->tax_minor * $share, $q ),
					);
					$n -= $share;
				}
			}
		}
		if ( ! array_filter( array_column( $weights, 1 ) ) ) {
			// Amount-only refund: weighted by what is left of each line (all paid lines when nothing is left).
			$weights = array();
			$any_left = (bool) array_filter( $left_gross );
			foreach ( $items as $i => $item ) { $weights[ $i ] = $any_left ? array( 0, $left_gross[ $i ], $left_tax[ $i ] ) : array( 0, (int) $after_discount[ $i ], (int) $item->tax_minor ); }
		}
		// Lines without weight take part with 0, so a rounding rest that the weighted lines have no room
		// for (e.g. the VAT residue a final ticket refund takes) lands on a line that still has it.
		foreach ( array_keys( $items ) as $i ) {
			if ( ! isset( $weights[ $i ] ) ) { $weights[ $i ] = array( 0, 0, 0 ); }
		}
		ksort( $weights, SORT_NUMERIC );
		$groups = array(); // rate => [line index => [gross weight, VAT weight]]
		foreach ( $weights as $i => $w ) {
			$groups[ (int) $items[ $i ]->tax_rate_bp ][ $i ] = array( max( 0, $w[1] ), max( 0, $w[2] ) );
		}
		ksort( $groups, SORT_NUMERIC );
		$gross_weights = array_map( static function ( $lines ) { return array_sum( array_column( $lines, 0 ) ); }, $groups );
		if ( ! array_filter( $gross_weights ) ) { return array(); }
		$rate_cap = static function ( array $caps ) use ( $groups ) {
			$out = array();
			foreach ( $groups as $rate => $lines ) { $out[ $rate ] = array_sum( array_intersect_key( $caps, $lines ) ); }
			return $out;
		};
		$gross_by_rate = self::apportion_capped( $total, $gross_weights, $rate_cap( $left_gross ) );
		// VAT follows the line VAT (0 % lines get none); without any line VAT it follows the gross of rates > 0.
		$tax_weights = array_map( static function ( $lines ) { return array_sum( array_column( $lines, 1 ) ); }, $groups );
		if ( ! array_filter( $tax_weights ) ) {
			foreach ( $gross_weights as $rate => $g ) { $tax_weights[ $rate ] = $rate > 0 ? $g : 0; }
			if ( ! array_filter( $tax_weights ) ) { $tax_weights = $gross_weights; }
		}
		$tax_by_rate = self::apportion_capped( $tax, $tax_weights, $rate_cap( $left_tax ) );
		$out = array();
		foreach ( $groups as $rate => $lines ) {
			$line_gross = array_map( static function ( $w ) { return $w[0]; }, $lines );
			$line_tax = array_map( static function ( $w ) { return $w[1]; }, $lines );
			if ( ! array_filter( $line_tax ) ) { $line_tax = $line_gross; }
			$gross_parts = self::apportion_capped( (int) $gross_by_rate[ $rate ], $line_gross, array_intersect_key( $left_gross, $lines ) );
			$tax_parts = self::apportion_capped( (int) $tax_by_rate[ $rate ], $line_tax, array_intersect_key( $left_tax, $lines ) );
			foreach ( array_keys( $lines ) as $i ) {
				$out[] = array( 'index' => (int) $i, 'rate_bp' => (int) $rate, 'tickets' => (int) $weights[ $i ][0], 'gross' => (int) $gross_parts[ $i ], 'tax' => (int) $tax_parts[ $i ] );
			}
		}
		return $out;
	}

	/**
	 * apportion() with an upper bound per key: parts above their cap give the excess to keys with room
	 * (largest weight first, ties to the earlier key). Only an amount above all caps together exceeds
	 * them (on the largest weight), so the parts always add up to $amount.
	 *
	 * @param int   $amount  Amount in minor units (≥ 0).
	 * @param int[] $weights Key => weight (≥ 0).
	 * @param int[] $caps    Key => maximum part.
	 * @return int[] Key => part.
	 */
	public static function apportion_capped( $amount, array $weights, array $caps ) {
		$parts = self::apportion( $amount, $weights );
		if ( ! $parts ) { return $parts; }
		$over = 0;
		foreach ( $parts as $key => $part ) {
			$cap = max( 0, (int) ( $caps[ $key ] ?? $part ) );
			if ( $part > $cap ) {
				$over += $part - $cap;
				$parts[ $key ] = $cap;
			}
		}
		if ( $over < 1 ) { return $parts; }
		$order = array_keys( $weights );
		$position = array_flip( $order );
		usort( $order, static function ( $a, $b ) use ( $weights, $position ) { return (int) $weights[ $b ] <=> (int) $weights[ $a ] ?: $position[ $a ] <=> $position[ $b ]; } );
		foreach ( $order as $key ) {
			$room = max( 0, (int) ( $caps[ $key ] ?? 0 ) ) - $parts[ $key ];
			if ( $room < 1 ) { continue; }
			$take = min( $room, $over );
			$parts[ $key ] += $take;
			$over -= $take;
			if ( $over < 1 ) { return $parts; }
		}
		$parts[ $order[0] ] += $over;
		return $parts;
	}

	/**
	 * Splits an amount by weights (largest remainder; ties go to the earlier key). The parts add up to $amount.
	 *
	 * @param int   $amount  Amount in minor units (≥ 0).
	 * @param int[] $weights Key => weight (≥ 0).
	 * @return int[] Key => part.
	 */
	public static function apportion( $amount, array $weights ) {
		$amount = (int) $amount;
		$sum = array_sum( $weights );
		$out = array_fill_keys( array_keys( $weights ), 0 );
		if ( ! $weights ) { return $out; }
		if ( $sum <= 0 ) {
			$out[ array_key_first( $weights ) ] = $amount;
			return $out;
		}
		$rest = array();
		$left = $amount;
		foreach ( $weights as $key => $w ) {
			$product = $amount * (int) $w;
			$out[ $key ] = intdiv( $product, $sum );
			$rest[ $key ] = $product - $out[ $key ] * $sum;
			$left -= $out[ $key ];
		}
		$order = array_keys( $weights );
		usort(
			$order,
			static function ( $a, $b ) use ( $rest, $order ) {
				return $rest[ $b ] <=> $rest[ $a ] ?: array_search( $a, $order, true ) <=> array_search( $b, $order, true );
			}
		);
		for ( $n = 0; $left > 0; $n++ ) {
			$out[ $order[ $n % count( $order ) ] ]++;
			$left--;
		}
		return $out;
	}

	/**
	 * Rows per VAT rate of a stored correction document: the breakdown frozen at issue, or for documents
	 * from before schema 3.8.3 the same computation from the refund (rows always add up to the totals).
	 *
	 * @param object|array $credit Row of wpcap_credit_notes.
	 * @return array[] See credit_note_rates().
	 */
	public static function credit_rates( $credit ) {
		$credit = (array) $credit;
		$total = (int) ( $credit['total_minor'] ?? 0 );
		$tax = (int) ( $credit['tax_minor'] ?? 0 );
		$stored = json_decode( (string) ( $credit['rates'] ?? '' ), true );
		if ( is_array( $stored ) && $stored ) {
			$rows = array();
			foreach ( $stored as $r ) {
				if ( ! is_array( $r ) ) { $rows = array(); break; }
				$rows[] = array( 'rate_bp' => (int) ( $r['rate_bp'] ?? 0 ), 'net' => (int) ( $r['net'] ?? 0 ), 'tax' => (int) ( $r['tax'] ?? 0 ), 'gross' => (int) ( $r['gross'] ?? 0 ) );
			}
			if ( $rows && array_sum( array_column( $rows, 'gross' ) ) === $total && array_sum( array_column( $rows, 'tax' ) ) === $tax ) { return $rows; }
		}
		$refund = Refunds::get( (int) ( $credit['refund_id'] ?? 0 ) );
		return self::credit_note_rates( $refund ? $refund : array(), $total, $tax );
	}

	/** Rate of a breakdown row as a percentage label ("19,00 %"). */
	public static function rate_label( $rate_bp ) {
		return number_format_i18n( (int) $rate_bp / 100, 2 ) . ' %';
	}

	/** Platform seller data for documents (settings "Rechnungssteller"). */
	public static function seller() {
		$country = Partners\SalesModel::country( Settings::get( 'seller_country' ) );
		$small = ! empty( Settings::get( 'seller_small_business' ) );
		$note = trim( (string) Settings::get( 'seller_small_business_note' ) );
		return array(
			'name' => (string) Settings::get( 'seller_name' ),
			'address' => (string) Settings::get( 'seller_address' ),
			'vat_id' => (string) Settings::get( 'seller_vat_id' ),
			'tax_number' => (string) Settings::get( 'seller_tax_number' ),
			'country' => $country,
			'small_business' => $small,
			'small_business_note' => $small ? ( '' !== $note ? $note : Partners\SalesModel::default_small_business_note( $country ) ) : '',
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
		return array( 'credit', (string) Settings::get( 'credit_note_prefix' ) ?: self::CREDIT_PREFIX_DEFAULT );
	}

	/** Seller block lines: name first, then address lines, VAT ID, tax number. */
	public static function seller_lines( array $seller ) {
		$lines = array( (string) ( $seller['name'] ?? '' ) );
		foreach ( preg_split( '/\r\n|\r|\n/', (string) ( $seller['address'] ?? '' ) ) as $line ) {
			if ( '' !== trim( $line ) ) { $lines[] = trim( $line ); }
		}
		if ( ! empty( $seller['vat_id'] ) ) { $lines[] = __( 'USt-IdNr.:', 'pass-captain' ) . ' ' . $seller['vat_id']; }
		if ( ! empty( $seller['tax_number'] ) ) { $lines[] = __( 'Steuernummer:', 'pass-captain' ) . ' ' . $seller['tax_number']; }
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
			$notes[] = sprintf( __( 'Vermittelt durch %s', 'pass-captain' ), (string) ( $agent['name'] ?? '' ) ) . ( '' !== $address ? ', ' . $address : '' ) . ( ! empty( $agent['vat_id'] ) ? ' (' . __( 'USt-IdNr.:', 'pass-captain' ) . ' ' . $agent['vat_id'] . ')' : '' );
			/* translators: %s: organizer name */
			$notes[] = sprintf( __( 'Die Leistung wird von %s erbracht. Die Plattform handelt im Namen und für Rechnung des Veranstalters.', 'pass-captain' ), (string) ( $seller['name'] ?? '' ) );
		}
		foreach ( (array) ( $snapshot['tax_notes'] ?? array() ) as $note ) { $notes[] = (string) $note; }
		return $notes;
	}

	/** Sales model label of an issued invoice (admin views). */
	public static function seller_label( array $snapshot ) {
		$seller = (array) ( $snapshot['seller'] ?? array() );
		if ( Partners\SalesModel::AGENT === ( $snapshot['sales_model'] ?? '' ) ) {
			/* translators: %s: organizer */
			return sprintf( __( 'Vermittler – Rechnungssteller: %s', 'pass-captain' ), (string) ( $seller['name'] ?? '' ) );
		}
		return __( 'Eigenhändler – Rechnungssteller: Plattform', 'pass-captain' );
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
		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (year,next_number) VALUES (%d,1)', $table, $year ) );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT next_number FROM %i WHERE year=%d FOR UPDATE', $table, $year ) );
		if ( ! $row ) { if ( ! $in_transaction ) { $wpdb->query( 'ROLLBACK' ); } return new \WP_Error( 'wpcap_invoice_sequence', __( 'Rechnungsnummer konnte nicht reserviert werden.', 'pass-captain' ) ); }
		$number = (int) $row->next_number;
		$updated = $wpdb->update( $table, array( 'next_number' => $number + 1 ), array( 'year' => $year ), array( '%d' ), array( '%d' ) );
		if ( false === $updated ) { if ( ! $in_transaction ) { $wpdb->query( 'ROLLBACK' ); } return new \WP_Error( 'wpcap_invoice_sequence', __( 'Rechnungsnummer konnte nicht reserviert werden.', 'pass-captain' ) ); }
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
		// Every step is checked: a number handed out without advancing the sequence (e.g. the UPDATE hit
		// a deadlock, which also rolls back the caller's transaction) would be issued twice; the unique
		// receipt/invoice number then blocks every later document of the series.
		$failed = static function () use ( $wpdb, $in_transaction ) {
			if ( ! $in_transaction ) { $wpdb->query( 'ROLLBACK' ); }
			return new \WP_Error( 'wpcap_document_sequence', __( 'Belegnummer konnte nicht reserviert werden.', 'pass-captain' ), array( 'status' => 503, 'retryable' => true ) );
		};
		if ( ! $in_transaction && false === $wpdb->query( 'START TRANSACTION' ) ) { return $failed(); }
		if ( false === $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (series,year,next_number) VALUES (%s,%d,1)', $table, $series, $year ) ) ) { return $failed(); }
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT next_number FROM %i WHERE series=%s AND year=%d FOR UPDATE', $table, $series, $year ) );
		if ( ! $row ) { return $failed(); }
		$number = (int) $row->next_number;
		if ( 1 !== (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET next_number=%d WHERE series=%s AND year=%d AND next_number=%d', $table, $number + 1, $series, $year, $number ) ) ) { return $failed(); }
		if ( ! $in_transaction && false === $wpdb->query( 'COMMIT' ) ) { return $failed(); }
		return sprintf( '%s-%d-%06d', preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $prefix ) ?: 'DOC', $year, $number );
	}

	/**
	 * Renders an invoice and its credit notes as a printable HTML document.
	 *
	 * @param array $link_context Context for add-on links (wp_pass_captain_invoice_links_v1): audience, location, access_token.
	 */
	public static function render_html( $invoice_id, array $link_context = array() ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$invoice = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE invoice_id=%d', $p . 'wpcap_invoices', absint( $invoice_id ) ) );
		if ( ! $invoice ) { return ''; }
		$lines = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE invoice_id=%d ORDER BY invoice_line_id', $p . 'wpcap_invoice_lines', $invoice->invoice_id ) );
		$credits = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE invoice_id=%d ORDER BY credit_note_id', $p . 'wpcap_credit_notes', $invoice->invoice_id ) );
		$snapshot = (array) json_decode( (string) $invoice->buyer_snapshot, true );
		$seller = (array) ( $snapshot['seller'] ?? self::seller() );
		$money = static function ( $minor ) use ( $invoice ) { return Money::format( $minor, $invoice->currency ); };
		$rates = self::rate_summary( $lines );
		ob_start();
		?><!doctype html><html <?php echo \PassCaptain\Frontend\Format::html_lang_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Format. ?>><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html( $invoice->invoice_number ); ?></title><style>body{font:14px/1.45 system-ui,sans-serif;max-width:52rem;margin:2rem auto;padding:0 1rem;color:#1d2327}table{width:100%;border-collapse:collapse;margin:1rem 0}th,td{border-bottom:1px solid #dcdcde;padding:.4rem;text-align:left}td.n,th.n{text-align:right}caption{text-align:left;font-weight:600;padding:.25rem 0}.seller,.buyer{white-space:pre-line}.buyer{margin:1rem 0}@media print{button{display:none}}</style></head><body>
		<?php $seller_lines = self::seller_lines( $seller ); ?>
		<header><div class="seller"><strong><?php echo esc_html( array_shift( $seller_lines ) ); ?></strong>
<?php echo esc_html( implode( "\n", $seller_lines ) ); ?></div></header>
		<h1><?php echo esc_html( __( 'Rechnung', 'pass-captain' ) . ' ' . $invoice->invoice_number ); ?></h1>
		<p><?php echo esc_html( __( 'Rechnungsdatum:', 'pass-captain' ) . ' ' . Frontend\Format::local( $invoice->issued_at, null, 'date' ) ); ?><?php if ( ! Billing::address_lines( $snapshot ) ) : ?><br><?php echo esc_html( __( 'Rechnungsempfänger:', 'pass-captain' ) . ' ' . ( $snapshot['email'] ?? '' ) ); ?><?php endif; ?></p>
		<?php if ( Billing::address_lines( $snapshot ) ) : ?><div class="buyer"><strong><?php esc_html_e( 'Rechnungsempfänger:', 'pass-captain' ); ?></strong>
<?php echo esc_html( implode( "\n", array_merge( Billing::address_lines( $snapshot ), Billing::detail_lines( $snapshot ), array( (string) ( $snapshot['email'] ?? '' ) ) ) ) ); ?></div><?php endif; ?>
		<table><thead><tr><th><?php esc_html_e( 'Leistung', 'pass-captain' ); ?></th><th class="n"><?php esc_html_e( 'Menge', 'pass-captain' ); ?></th><th class="n"><?php esc_html_e( 'Einzelpreis', 'pass-captain' ); ?></th><th class="n"><?php esc_html_e( 'Rabatt', 'pass-captain' ); ?></th><th class="n"><?php esc_html_e( 'USt.', 'pass-captain' ); ?></th><th class="n"><?php esc_html_e( 'Gesamt (brutto)', 'pass-captain' ); ?></th></tr></thead><tbody>
		<?php foreach ( $lines as $line ) : ?><tr><td><?php echo esc_html( $line->description ); ?></td><td class="n"><?php echo esc_html( $line->quantity ); ?></td><td class="n"><?php echo esc_html( $money( (int) $line->unit_minor ) ); ?></td><td class="n"><?php echo esc_html( $money( (int) $line->discount_minor ) ); ?></td><td class="n"><?php echo esc_html( number_format_i18n( (float) $line->tax_rate, 2 ) . ' %' ); ?></td><td class="n"><?php echo esc_html( $money( (int) $line->total_minor ) ); ?></td></tr><?php endforeach; ?>
		</tbody></table>
		<table class="wpcap-vat-summary"><caption><?php esc_html_e( 'Aufstellung nach Steuersätzen', 'pass-captain' ); ?></caption><thead><tr><th><?php esc_html_e( 'USt-Satz', 'pass-captain' ); ?></th><th class="n"><?php esc_html_e( 'Netto', 'pass-captain' ); ?></th><th class="n"><?php esc_html_e( 'USt.', 'pass-captain' ); ?></th><th class="n"><?php esc_html_e( 'Brutto', 'pass-captain' ); ?></th></tr></thead><tbody>
		<?php foreach ( $rates as $rate => $sum ) : ?><tr><td><?php echo esc_html( number_format_i18n( (float) $rate, 2 ) . ' %' ); ?></td><td class="n"><?php echo esc_html( $money( $sum['net'] ) ); ?></td><td class="n"><?php echo esc_html( $money( $sum['tax'] ) ); ?></td><td class="n"><?php echo esc_html( $money( $sum['gross'] ) ); ?></td></tr><?php endforeach; ?>
		</tbody></table>
		<table><tbody>
		<tr><td><?php esc_html_e( 'Nettobetrag', 'pass-captain' ); ?></td><td class="n"><?php echo esc_html( $money( (int) $invoice->total_minor - (int) $invoice->tax_minor ) ); ?></td></tr>
		<?php foreach ( $rates as $rate => $sum ) : ?><tr><td><?php echo esc_html( sprintf( /* translators: %s: rate */ __( 'Umsatzsteuer %s %%', 'pass-captain' ), number_format_i18n( (float) $rate, 2 ) ) ); ?></td><td class="n"><?php echo esc_html( $money( $sum['tax'] ) ); ?></td></tr><?php endforeach; ?>
		<tr><th><?php esc_html_e( 'Rechnungsbetrag', 'pass-captain' ); ?></th><th class="n"><?php echo esc_html( $money( (int) $invoice->total_minor ) ); ?></th></tr>
		</tbody></table>
		<?php echo self::payments_html( $invoice, $snapshot, $money ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in payments_html(). ?>
		<?php foreach ( self::seller_notes( $snapshot ) as $note ) : ?><p class="wpcap-seller-note"><?php echo esc_html( $note ); ?></p><?php endforeach; ?>
		<?php foreach ( $credits as $credit ) : ?><h2><?php echo esc_html( __( 'Rechnungskorrektur', 'pass-captain' ) . ' ' . $credit->credit_note_number ); ?></h2><p><?php echo esc_html( sprintf( /* translators: 1: invoice number, 2: date */ __( 'Korrektur zu Rechnung %1$s vom %2$s', 'pass-captain' ), $invoice->invoice_number, Frontend\Format::local( $credit->issued_at, null, 'date' ) ) ); ?></p><?php $credit_rates = self::credit_rates( $credit ); ?>
		<table class="wpcap-vat-summary"><caption><?php esc_html_e( 'Aufstellung nach Steuersätzen', 'pass-captain' ); ?></caption><thead><tr><th><?php esc_html_e( 'USt-Satz', 'pass-captain' ); ?></th><th class="n"><?php esc_html_e( 'Netto', 'pass-captain' ); ?></th><th class="n"><?php esc_html_e( 'USt.', 'pass-captain' ); ?></th><th class="n"><?php esc_html_e( 'Brutto', 'pass-captain' ); ?></th></tr></thead><tbody>
		<?php foreach ( $credit_rates as $r ) : ?><tr><td><?php echo esc_html( self::rate_label( $r['rate_bp'] ) ); ?></td><td class="n">-<?php echo esc_html( $money( $r['net'] ) ); ?></td><td class="n">-<?php echo esc_html( $money( $r['tax'] ) ); ?></td><td class="n">-<?php echo esc_html( $money( $r['gross'] ) ); ?></td></tr><?php endforeach; ?>
		</tbody></table>
		<table><tbody><tr><td><?php esc_html_e( 'Nettobetrag', 'pass-captain' ); ?></td><td class="n">-<?php echo esc_html( $money( (int) $credit->net_minor ) ); ?></td></tr>
		<?php foreach ( $credit_rates as $r ) : ?><tr><td><?php echo esc_html( sprintf( /* translators: %s: rate */ __( 'Umsatzsteuer %s %%', 'pass-captain' ), number_format_i18n( $r['rate_bp'] / 100, 2 ) ) ); ?></td><td class="n">-<?php echo esc_html( $money( $r['tax'] ) ); ?></td></tr><?php endforeach; ?>
		<tr><th><?php esc_html_e( 'Korrekturbetrag', 'pass-captain' ); ?></th><th class="n">-<?php echo esc_html( $money( (int) $credit->total_minor ) ); ?></th></tr></tbody></table>
		<?php if ( 'voided' === ( $credit->status ?? '' ) ) : ?><p class="wpcap-credit-voided"><strong><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Storniert am %s: Die Erstattung ist beim Zahlungsanbieter fehlgeschlagen; der Betrag wurde nicht zurückgezahlt.', 'pass-captain' ), wp_date( 'd.m.Y', strtotime( (string) $credit->voided_at . ' UTC' ) ) ) ); ?></strong></p><?php endif; ?>
		<?php if ( '' !== (string) ( $credit->reversal_number ?? '' ) ) : ?><p><strong><?php echo esc_html( sprintf( /* translators: 1: reversal document number, 2: date */ __( 'Storniert durch Beleg %1$s vom %2$s (Erstattung beim Zahlungsanbieter gescheitert)', 'pass-captain' ), $credit->reversal_number, Frontend\Format::local( $credit->reversed_at, null, 'date' ) ) ); ?></strong></p><?php endif; ?><?php endforeach; ?>
		<?php $extra_links = Api\Invoices::links_html( (int) $invoice->invoice_id, 'invoice', array_merge( array( 'audience' => 'admin', 'order_id' => (int) $invoice->order_id ), $link_context, array( 'location' => 'invoice_view' ) ) ); ?>
		<?php if ( '' !== $extra_links ) : ?><p class="wpcap-invoice-extra"><?php echo $extra_links; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in links_html(). ?></p><?php endif; ?>
		<?php echo Frontend\PrintButton::html( __( 'Drucken oder als PDF speichern', 'pass-captain' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in html(). ?>
		</body></html><?php
		return (string) ob_get_clean();
	}

	/** Admin print view: admin-post.php?action=wpcap_print_invoice&order_id=… */
	public static function handle_admin_print() {
		$order_id = absint( $_GET['order_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view, capability and scope checked.
		if ( ! self::can_view_order_finance( $order_id ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		global $wpdb;
		$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT invoice_id FROM %i WHERE order_id=%d LIMIT 1', $wpdb->prefix . 'wpcap_invoices', $order_id ) );
		// Viewing is read-only; creating a new invoice number is a state change and needs a nonce.
		if ( ! $exists ) { check_admin_referer( 'wpcap_issue_invoice_' . $order_id ); }
		$result = self::issue_for_order( $order_id );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), 409 ); }
		Privacy\AccessLog::export( 'invoice_html', 'order', $order_id, array( 'invoice_id' => (int) $result['invoice_id'] ) );
		Security\PrivatePage::send( true ); // Standalone document: strict CSP, no cache.
		echo self::render_html( $result['invoice_id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_html.
		\PassCaptain\Http::end();
	}

	/** Buyer view through the personal order link: ?wpcap_invoice=<public_id>&wpcap_access=<token> */
	public static function maybe_render_customer_invoice() {
		$public_id = sanitize_text_field( wp_unslash( $_GET['wpcap_invoice'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$from_url = Security\Input::hex( wp_unslash( $_GET['wpcap_access'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- reduced to hex characters.
		if ( '' === $public_id || ! is_string( $public_id ) ) { return; }
		// Without token in the URL: the order's short-lived access cookie (Security\AccessCookie, SEC-7).
		$token = '' !== $from_url ? $from_url : Security\AccessCookie::resolve( 'order', $public_id );
		if ( 64 !== strlen( $token ) ) {
			if ( '' === $from_url && Security\AccessCookie::marker_present() ) { Security\AccessCookie::blocked_page(); }
			return;
		}
		// A283: personal page, also its 404 answers – never stored by a page cache or CDN.
		PageCache::no_store( 'invoice' );
		global $wpdb;
		$order = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE public_id=%s', $wpdb->prefix . 'wpcap_orders', $public_id ) );
		if ( ! Orders::access_matches( $order, $token ) ) { status_header( 404 ); \PassCaptain\Http::end(); }
		$invoice_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT invoice_id FROM %i WHERE order_id=%d', $wpdb->prefix . 'wpcap_invoices', $order->order_id ) );
		if ( ! $invoice_id ) { status_header( 404 ); \PassCaptain\Http::end(); }
		if ( '' !== $from_url ) { Security\AccessCookie::exchange( 'order', (string) $order->public_id, $token, add_query_arg( 'wpcap_invoice', (string) $order->public_id, home_url( '/' ) ) ); }
		Security\PrivatePage::send( true ); // No page cache, no framing, no indexing (secret link); strict CSP (standalone document).
		header( 'Referrer-Policy: no-referrer' );
		echo self::render_html( $invoice_id, array( 'audience' => 'customer', 'order_public_id' => (string) $order->public_id, 'access_token' => $token ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_html.
		\PassCaptain\Http::end();
	}

	/**
	 * Accounting export (CSV) for a date range in the site timezone: one row per invoice line, one row per
	 * VAT rate of each correction document, plus sales and refunds of orders without invoice (columns and
	 * reconciliation with the sales report: docs/OPERATIONS.md "Buchhaltungsexport").
	 * admin-post.php?action=wpcap_export_accounting&from=YYYY-MM-DD&to=YYYY-MM-DD&_wpnonce=…
	 * With &format=datev the DATEV-style booking batch (datev_rows()) is sent instead; with &mode=ledger
	 * payments, refunds and chargebacks from order lines and the refund ledger, independent of invoices
	 * (ledger_export_rows()).
	 */
	public static function handle_export() {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( self::FINANCE_CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		check_admin_referer( 'wpcap_export_accounting' );
		// Defaults: current month up to today, both in the site timezone.
		$today = Reports\Scope::local( gmdate( 'Y-m-d H:i:s' ), Reports\Scope::site_timezone(), 'Y-m-d' );
		$from_in = sanitize_text_field( wp_unslash( (string) ( $_REQUEST['from'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked by the export handler.
		$to_in = sanitize_text_field( wp_unslash( (string) ( $_REQUEST['to'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$from = Reports\Data::valid_date( $from_in ) ? $from_in : substr( $today, 0, 8 ) . '01';
		$to = Reports\Data::valid_date( $to_in ) ? $to_in : $today;
		if ( $to < $from ) { list( $from, $to ) = array( $to, $from ); }
		if ( 'ledger' === sanitize_key( wp_unslash( (string) ( $_REQUEST['mode'] ?? '' ) ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$rows = self::ledger_export_rows( $from, $to );
			Audit::record( 'accounting_exported', 'invoice', 0, array( 'from' => $from, 'to' => $to, 'rows' => count( $rows ), 'mode' => 'ledger' ) );
			Reports\Scope::send_csv( $rows, 'pass-captain-buchhaltung-zahlungen-' . $from . '-' . $to . '.csv' ); // Ends the request unless wpcap_pre_send_download took over.
			return;
		}
		$format = sanitize_key( wp_unslash( (string) ( $_REQUEST['format'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$extension = self::export_formats()[ $format ] ?? null;
		if ( null !== $extension ) {
			$rows = self::extension_rows( $extension, $from, $to );
			Audit::record( 'accounting_exported', 'invoice', 0, array( 'from' => $from, 'to' => $to, 'rows' => count( $rows ), 'format' => $format ) );
			nocache_headers();
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="pass-captain-' . $format . '-' . $from . '-' . $to . '.csv"' );
			$out = fopen( 'php://output', 'w' );
			Reports\Scope::write_csv( $out, $rows ); // Formula guard and RFC 4180 quoting as the built-in export (A495).
			fclose( $out );
			\PassCaptain\Http::end();
		}
		$datev = 'datev' === $format;
		nocache_headers();
		header( 'Content-Type: text/csv; charset=' . ( $datev ? 'windows-1252' : 'utf-8' ) );
		header( 'Content-Disposition: attachment; filename="pass-captain-' . ( $datev ? 'buchungsstapel' : 'buchhaltung' ) . '-' . $from . '-' . $to . '.csv"' );
		// Streamed row by row (A434) through the shared CSV writer: formula guard and RFC 4180 quoting (A495).
		$out = fopen( 'php://output', 'w' );
		$count = 0;
		$rows = ( static function () use ( $from, $to, $datev, &$count ) {
			foreach ( $datev ? self::datev_iter( $from, $to ) : self::export_iter( $from, $to ) as $row ) {
				++$count;
				yield $row;
			}
		} )();
		Reports\Scope::write_csv( $out, $rows, 0, $datev ? array( 'encoding' => 'Windows-1252', 'bom' => false ) : array() );
		fclose( $out );
		Audit::record( 'accounting_exported', 'invoice', 0, array( 'from' => $from, 'to' => $to, 'rows' => $count, 'format' => $datev ? 'datev' : 'csv' ) );
		\PassCaptain\Http::end();
	}

	/** Download URL of the accounting export (mode invoices|ledger; format datev or an extension format). */
	public static function export_url( $from, $to, $mode = 'invoices', $format = '' ) {
		$args = array( 'action' => 'wpcap_export_accounting', 'from' => $from, 'to' => $to, 'mode' => 'ledger' === $mode ? 'ledger' : 'invoices', '_wpnonce' => wp_create_nonce( 'wpcap_export_accounting' ) );
		if ( '' !== (string) $format ) { $args['format'] = sanitize_key( (string) $format ); }
		return add_query_arg( $args, admin_url( 'admin-post.php' ) );
	}

	/**
	 * Streams the accounting export (or with $datev the booking batch, cells in Windows-1252) row by
	 * row to $emit (A434: the download no longer builds every row in memory first). Returns the rows
	 * sent, header included.
	 *
	 * @param callable $emit Receives each row (array of cells).
	 */
	public static function export_stream( $from, $to, $datev, callable $emit ) {
		$count = 0;
		foreach ( $datev ? self::datev_iter( $from, $to ) : self::export_iter( $from, $to ) as $row ) {
			if ( $datev ) {
				// DATEV import expects ANSI (Windows-1252).
				$row = array_map( static function ( $cell ) { return mb_convert_encoding( (string) $cell, 'Windows-1252', 'UTF-8' ); }, $row );
			}
			$emit( $row );
			++$count;
		}
		return $count;
	}

	/**
	 * CSV text of the accounting export (export_rows()) or the DATEV batch (datev_rows()) through the
	 * shared writer Reports\Scope::csv(): formula guard per cell, RFC 4180 quoting (A495). The DATEV
	 * import expects ANSI (Windows-1252) without byte order mark.
	 *
	 * @param array[] $rows  Rows.
	 * @param bool    $datev DATEV format.
	 */
	public static function export_csv( array $rows, $datev = false ) {
		return Reports\Scope::csv( $rows, $datev ? array( 'encoding' => 'Windows-1252', 'bom' => false ) : array() );
	}

	/**
	 * Filter wpcap_export_formats (A663, public add-on API): further formats of the accounting export,
	 * id => ['label' => string, 'rows' => callable( string $from, string $to ): array[] (first row =
	 * header)]. Shown as buttons on the reports page, sent as UTF-8 CSV (semicolon) under the same
	 * permission, nonce and audit entry as the built-in export; cells are protected against spreadsheet
	 * formulas. The ids csv and datev are reserved.
	 *
	 * @return array<string,array{label:string,rows:callable}>
	 */
	public static function export_formats() {
		$out = array();
		foreach ( (array) apply_filters( 'wpcap_export_formats', array() ) as $id => $def ) {
			$id = sanitize_key( (string) $id );
			if ( '' === $id || in_array( $id, array( 'csv', 'datev' ), true ) || ! is_array( $def ) || ! is_callable( $def['rows'] ?? null ) ) { continue; }
			$label = trim( sanitize_text_field( (string) ( $def['label'] ?? '' ) ) );
			$out[ $id ] = array( 'label' => '' !== $label ? $label : $id, 'rows' => $def['rows'] );
		}
		return $out;
	}

	/** Rows of an add-on export format: scalar cells only, formula characters neutralised. */
	public static function extension_rows( array $format, $from, $to ) {
		$rows = call_user_func( $format['rows'], (string) $from, (string) $to );
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$out[] = array_map( static function ( $cell ) {
				$cell = is_scalar( $cell ) || null === $cell ? (string) $cell : '';
				return preg_match( '/^[=+\-@\t\r]/', $cell ) && ! is_numeric( $cell ) ? "'" . $cell : $cell;
			}, array_values( $row ) );
		}
		return $out;
	}

	/**
	 * Columns of the accounting export, in this order (stable interface: new columns are only ever
	 * appended; documented in docs/OPERATIONS.md "Buchhaltungsexport").
	 */
	const EXPORT_COLUMNS = array( 'Belegart', 'Belegnummer', 'Belegdatum', 'Bestellung', 'Bezug', 'Beschreibung', 'Menge', 'Netto', 'USt-Satz', 'USt', 'Brutto', 'Waehrung', 'Zahlungsart', 'Verkaufsmodell', 'Rechnungssteller' );

	/** Document types of the accounting export (column Belegart). */
	const EXPORT_KIND_INVOICE = 'Rechnung';
	const EXPORT_KIND_CREDIT = 'Rechnungskorrektur';
	const EXPORT_KIND_SALE_UNINVOICED = 'Verkauf ohne Rechnung';
	const EXPORT_KIND_REFUND_UNINVOICED = 'Erstattung ohne Rechnung';

	/**
	 * Builds export rows (first row = header). Amounts are decimal strings; text cells are CSV-injection safe.
	 *
	 * $from/$to are local dates of the site timezone (Reports\Scope::site_timezone()); a day runs from local
	 * midnight to midnight and Belegdatum is the local date. Besides invoices and correction documents the
	 * export lists sales of paid orders without invoice (auto_invoice off, box office receipts, partner API,
	 * WooCommerce) per order and VAT rate on the order day, and completed refunds of such orders per VAT rate
	 * on the completion day, so the totals reconcile with the sales report (Reports\Data::build()).
	 * Complimentary orders (Reports\Data::comp_providers()) are not sales and never listed.
	 */
	public static function export_rows( $from, $to ) {
		$rows = iterator_to_array( self::export_iter( $from, $to ), false );
		// Add-on extension point for the accounting rows (wp_pass_captain_export_rows_v1).
		return class_exists( Reports\Exports::class ) ? Reports\Exports::filter_rows( $rows, 'accounting', array( 'from' => $from, 'to' => $to ) ) : $rows;
	}

	/**
	 * export_rows() as a generator: invoices and corrections from one query each, orders without
	 * invoice in blocks (uninvoiced_rows()), so a long period never sits in memory as a whole (A434).
	 *
	 * @return \Generator<int, array>
	 */
	private static function export_iter( $from, $to ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$tz = Reports\Scope::site_timezone();
		list( $from_utc, $to_utc ) = Reports\Data::bounds( $from, $to, $tz );
		$local_day = static function ( $utc ) use ( $tz ) { return Reports\Scope::local( $utc, $tz, 'Y-m-d' ); };
		yield self::EXPORT_COLUMNS;
		$money = static function ( $minor, $currency ) { return Money::to_decimal( $minor, $currency ); };
		$safe = static function ( $text ) { $text = (string) $text; return preg_match( '/^[=+\-@\t\r]/', $text ) ? "'" . $text : $text; };
		// Seller columns: agent-model documents are issued in the organizer's name (not platform revenue).
		$seller_cols = static function ( $snapshot_json ) use ( $safe ) {
			$snapshot = (array) json_decode( (string) $snapshot_json, true );
			$agent = Partners\SalesModel::AGENT === ( $snapshot['sales_model'] ?? '' );
			return array( $agent ? 'Vermittler' : 'Eigenhaendler', $safe( (string) ( $snapshot['seller']['name'] ?? '' ) ) );
		};
		$lines = $wpdb->get_results( $wpdb->prepare( "SELECT i.invoice_number,i.issued_at,i.currency,i.buyer_snapshot,o.public_id,o.payment_provider,l.description,l.quantity,l.tax_rate,l.tax_minor,l.total_minor FROM {$p}wpcap_invoices i INNER JOIN {$p}wpcap_orders o ON o.order_id=i.order_id INNER JOIN {$p}wpcap_invoice_lines l ON l.invoice_id=i.invoice_id WHERE i.issued_at >= %s AND i.issued_at < %s ORDER BY i.invoice_id,l.invoice_line_id", $from_utc, $to_utc ) );
		foreach ( $lines as $l ) {
			yield array( self::EXPORT_KIND_INVOICE, $l->invoice_number, $local_day( $l->issued_at ), $l->public_id, '', $safe( $l->description ), (int) $l->quantity, $money( (int) $l->total_minor - (int) $l->tax_minor, $l->currency ), number_format( (float) $l->tax_rate, 2, '.', '' ), $money( (int) $l->tax_minor, $l->currency ), $money( (int) $l->total_minor, $l->currency ), $l->currency, $safe( $l->payment_provider ), ...$seller_cols( $l->buyer_snapshot ) );
		}
		$credits = $wpdb->get_results( $wpdb->prepare( "SELECT c.*,i.invoice_number,i.buyer_snapshot,o.public_id,o.payment_provider FROM {$p}wpcap_credit_notes c INNER JOIN {$p}wpcap_invoices i ON i.invoice_id=c.invoice_id INNER JOIN {$p}wpcap_orders o ON o.order_id=i.order_id WHERE c.issued_at >= %s AND c.issued_at < %s ORDER BY c.credit_note_id", $from_utc, $to_utc ) );
		// Corrections ("Rechnungskorrektur", not "Gutschrift": that term means self-billing): one row per VAT rate.
		foreach ( $credits as $c ) {
			foreach ( self::credit_rates( $c ) as $r ) {
				yield array( self::EXPORT_KIND_CREDIT, $c->credit_note_number, $local_day( $c->issued_at ), $c->public_id, $c->invoice_number, 'Erstattung', '', '-' . $money( $r['net'], $c->currency ), number_format( $r['rate_bp'] / 100, 2, '.', '' ), '-' . $money( $r['tax'], $c->currency ), '-' . $money( $r['gross'], $c->currency ), $c->currency, $safe( $c->payment_provider ), ...$seller_cols( $c->buyer_snapshot ) );
			}
		}
		if ( self::credit_reversal_ready() ) {
			// A348: reversal of a correction whose refund failed at the provider, on its own date and number.
			$reversals = $wpdb->get_results( $wpdb->prepare( "SELECT c.*,i.invoice_number,i.buyer_snapshot,o.public_id,o.payment_provider FROM {$p}wpcap_credit_notes c INNER JOIN {$p}wpcap_invoices i ON i.invoice_id=c.invoice_id INNER JOIN {$p}wpcap_orders o ON o.order_id=i.order_id WHERE c.reversal_number<>'' AND c.reversed_at >= %s AND c.reversed_at < %s ORDER BY c.credit_note_id", $from_utc, $to_utc ) );
			foreach ( $reversals as $c ) {
				foreach ( self::credit_rates( $c ) as $r ) {
					yield array( self::EXPORT_KIND_CREDIT, $c->reversal_number, $local_day( $c->reversed_at ), $c->public_id, $c->credit_note_number, 'Storno Erstattung', '', $money( $r['net'], $c->currency ), number_format( $r['rate_bp'] / 100, 2, '.', '' ), $money( $r['tax'], $c->currency ), $money( $r['gross'], $c->currency ), $c->currency, $safe( $c->payment_provider ), ...$seller_cols( $c->buyer_snapshot ) );
				}
			}
		}
		yield from self::uninvoiced_rows( $from_utc, $to_utc, $local_day, $money, $safe );
	}

	/**
	 * Accounting export independent of invoices (GoBD / § 131 BAO): one row per order line for every
	 * payment and per VAT rate for every completed refund or chargeback in the period – also for orders
	 * without an invoice (setting auto_invoice off). Tax country from the line snapshot (place of supply,
	 * OSS), document number = invoice/credit note number when one exists, else the order or refund id.
	 *
	 * Payment date: the confirmation recorded in the audit log, else the order date. Refund date: when
	 * the refund was completed (updated_at). Amounts are decimal strings, refunds negative.
	 *
	 * @return array[] Rows, first row = header.
	 */
	public static function ledger_export_rows( $from, $to ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$from_utc = $from . ' 00:00:00';
		$to_utc = gmdate( 'Y-m-d H:i:s', strtotime( $to . ' 00:00:00 UTC' ) + DAY_IN_SECONDS );
		$rows = array( array( 'Buchungsart', 'Buchungsdatum', 'Belegnummer', 'Bestellung', 'Bestell-ID', 'Erstattungs-ID', 'Beschreibung', 'Menge', 'Netto', 'USt-Satz', 'USt', 'Brutto', 'Waehrung', 'Zahlungsart', 'Zahlungsreferenz', 'Steuerland' ) );
		$money = static function ( $minor, $currency ) { return Localization::money_decimal( (int) $minor, $currency ); };
		$rate = static function ( $bp ) { return number_format( (int) $bp / 100, 2, '.', '' ); };
		// Payments: delayed methods can confirm days after the order, so candidates start 60 days earlier.
		$orders = (array) $wpdb->get_results( $wpdb->prepare( "SELECT order_id,public_id,currency,total_minor,discount_minor,price_snapshot,payment_provider,provider_reference,created_at FROM %i WHERE payment_status IN ('paid','refunded') AND total_minor>0 AND created_at >= %s AND created_at < %s ORDER BY order_id", $p . 'wpcap_orders', gmdate( 'Y-m-d H:i:s', strtotime( $from_utc . ' UTC' ) - 60 * DAY_IN_SECONDS ), $to_utc ) );
		$order_ids = array_map( static function ( $o ) { return (int) $o->order_id; }, $orders );
		$paid_at = array();
		foreach ( array_chunk( $order_ids, 500 ) as $chunk ) {
			$confirmations = (array) $wpdb->get_results( $wpdb->prepare( "SELECT object_id, MIN(created_at) AS paid_at FROM %i WHERE object_type='order' AND action IN ('external_payment_confirmed','manual_payment_confirmed','pos_device_payment_confirmed') AND object_id IN (" . implode( ',', array_fill( 0, count( $chunk ), '%d' ) ) . ') GROUP BY object_id', array_merge( array( $p . 'wpcap_audit_logs' ), $chunk ) ) );
			foreach ( $confirmations as $row ) { $paid_at[ (int) $row->object_id ] = (string) $row->paid_at; }
		}
		$in_range = array();
		foreach ( $orders as $order ) {
			$date = $paid_at[ (int) $order->order_id ] ?? (string) $order->created_at;
			if ( $date >= $from_utc && $date < $to_utc ) { $in_range[] = array( $order, $date ); }
		}
		// A refund reversed later (A348) stays booked on its day; the reversal is booked back below on its own day.
		$reversal_ready = Refunds::provider_status_ready();
		$refunds = (array) $wpdb->get_results( $wpdb->prepare( "SELECT r.*,o.public_id,o.payment_provider,o.provider_reference AS order_reference FROM %i r INNER JOIN %i o ON o.order_id=r.order_id WHERE r.status IN ('completed'" . ( $reversal_ready ? ",'reversed'" : '' ) . ") AND r.updated_at >= %s AND r.updated_at < %s ORDER BY r.refund_id", $p . 'wpcap_refunds', $p . 'wpcap_orders', $from_utc, $to_utc ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed status list.
		$all_ids = array_merge( array_map( static function ( $x ) { return (int) $x[0]->order_id; }, $in_range ), array_map( static function ( $r ) { return (int) $r->order_id; }, $refunds ) );
		$countries = self::item_tax_countries( $all_ids );
		$numbers = array();
		foreach ( array_chunk( array_values( array_unique( $all_ids ) ), 500 ) as $chunk ) {
			$invoice_rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT order_id,invoice_number FROM %i WHERE order_id IN (' . implode( ',', array_fill( 0, count( $chunk ), '%d' ) ) . ')', array_merge( array( $p . 'wpcap_invoices' ), $chunk ) ) );
			foreach ( $invoice_rows as $row ) { $numbers[ (int) $row->order_id ] = (string) $row->invoice_number; }
		}
		foreach ( $in_range as $entry ) {
			list( $order, $date ) = $entry;
			$items = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT oi.item_id,oi.ticket_type_id,oi.quantity,oi.line_total_minor,oi.tax_minor,oi.tax_rate_bp,tt.name,e.name AS event_name FROM %i oi INNER JOIN %i tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN %i oc ON oc.occurrence_id=tt.occurrence_id INNER JOIN %i e ON e.event_id=oc.event_id WHERE oi.order_id=%d ORDER BY oi.item_id', $p . 'wpcap_order_items', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $p . 'wpcap_events', (int) $order->order_id ) );
			$stored = Orders::snapshot_line_discounts( $order );
			$amounts = array();
			foreach ( $items as $index => $item ) { $amounts[ $index ] = (int) $item->line_total_minor; }
			$split = Orders::allocate( $amounts, (int) $order->discount_minor );
			$item_countries = array_column( $countries[ (int) $order->order_id ] ?? array(), 'country', 'item_id' );
			foreach ( $items as $index => $item ) {
				$discount = null !== $stored && isset( $stored[ (int) $item->ticket_type_id ] ) ? min( (int) $item->line_total_minor, $stored[ (int) $item->ticket_type_id ] ) : $split[ $index ];
				$gross = max( 0, (int) $item->line_total_minor - $discount );
				$rows[] = array( 'Zahlung', substr( $date, 0, 10 ), $numbers[ (int) $order->order_id ] ?? '', (string) $order->public_id, (int) $order->order_id, '', $item->event_name . ' – ' . $item->name, (int) $item->quantity, $money( $gross - (int) $item->tax_minor, $order->currency ), $rate( $item->tax_rate_bp ), $money( (int) $item->tax_minor, $order->currency ), $money( $gross, $order->currency ), (string) $order->currency, (string) $order->payment_provider, (string) $order->provider_reference, $item_countries[ (int) $item->item_id ] ?? '' );
			}
		}
		foreach ( $refunds as $refund ) {
			$items = $countries[ (int) $refund->order_id ] ?? array();
			$chargeback = false !== strpos( (string) $refund->reason, 'chargeback' );
			$number = '' !== (string) $refund->credit_note_number ? (string) $refund->credit_note_number : '';
			foreach ( self::credit_note_split( (array) $refund, (int) $refund->amount_minor, (int) $refund->tax_minor ) as $part ) {
				$of_rate = array_filter( $items, static function ( $item ) use ( $part ) { return (int) $item['rate_bp'] === (int) $part['tax_rate_bp']; } );
				$reference = '' !== (string) $refund->provider_reference ? (string) $refund->provider_reference : (string) $refund->order_reference;
				$rows[] = array( $chargeback ? 'Chargeback' : 'Erstattung', substr( (string) $refund->updated_at, 0, 10 ), $number, (string) $refund->public_id, (int) $refund->order_id, (int) $refund->refund_id, (string) $refund->reason, '', '-' . $money( $part['net_minor'], $refund->currency ), $rate( $part['tax_rate_bp'] ), '-' . $money( $part['tax_minor'], $refund->currency ), '-' . $money( $part['total_minor'], $refund->currency ), (string) $refund->currency, (string) $refund->payment_provider, $reference, self::countries_label( $of_rate ? $of_rate : $items ) );
			}
		}
		// Refunds that failed at the provider after booking (money stayed with the seller): reversal rows.
		$failed = (array) $wpdb->get_results( $wpdb->prepare( "SELECT r.*,o.public_id,o.payment_provider,o.provider_reference AS order_reference FROM %i r INNER JOIN %i o ON o.order_id=r.order_id WHERE r.status='failed' AND r.error_message='provider_refund_failed' AND r.updated_at >= %s AND r.updated_at < %s ORDER BY r.refund_id", $p . 'wpcap_refunds', $p . 'wpcap_orders', $from_utc, $to_utc ) );
		if ( $reversal_ready ) {
			// Booked refunds reversed by the provider (A348, status reversed): booked back on the reversal day.
			$reversed = (array) $wpdb->get_results( $wpdb->prepare( "SELECT r.*,r.reversed_at AS updated_at,o.public_id,o.payment_provider,o.provider_reference AS order_reference FROM %i r INNER JOIN %i o ON o.order_id=r.order_id WHERE r.status='reversed' AND r.reversed_at >= %s AND r.reversed_at < %s ORDER BY r.refund_id", $p . 'wpcap_refunds', $p . 'wpcap_orders', $from_utc, $to_utc ) );
			$failed = array_merge( $failed, $reversed );
		}
		$failed_countries = self::item_tax_countries( array_map( static function ( $r ) { return (int) $r->order_id; }, $failed ) );
		foreach ( $failed as $refund ) {
			$items = $failed_countries[ (int) $refund->order_id ] ?? array();
			foreach ( self::credit_note_split( (array) $refund, (int) $refund->amount_minor, (int) $refund->tax_minor ) as $part ) {
				$of_rate = array_filter( $items, static function ( $item ) use ( $part ) { return (int) $item['rate_bp'] === (int) $part['tax_rate_bp']; } );
				$rows[] = array( 'Erstattung fehlgeschlagen', substr( (string) $refund->updated_at, 0, 10 ), (string) $refund->credit_note_number, (string) $refund->public_id, (int) $refund->order_id, (int) $refund->refund_id, (string) $refund->reason, '', $money( $part['net_minor'], $refund->currency ), $rate( $part['tax_rate_bp'] ), $money( $part['tax_minor'], $refund->currency ), $money( $part['total_minor'], $refund->currency ), (string) $refund->currency, (string) $refund->payment_provider, (string) $refund->provider_reference, self::countries_label( $of_rate ? $of_rate : $items ) );
			}
		}
		return Reports\Exports::filter_rows( $rows, 'accounting_ledger', array( 'from' => $from, 'to' => $to ) );
	}

	/**
	 * Tax country (place of supply) of every order item, in item order: the country frozen in the line
	 * snapshot (Sales\TaxProfiles), else the event's tax country, else the seller country setting.
	 *
	 * @param int[] $order_ids Orders.
	 * @return array order_id => [ {item_id, rate_bp, country} ]
	 */
	public static function item_tax_countries( array $order_ids ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $order_ids ) ) ) );
		$result = array();
		$seller = Sales\TaxProfiles::seller_country();
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT oi.order_id,oi.item_id,oi.tax_rate_bp,oi.line_snapshot,e.tax_country FROM %i oi INNER JOIN %i tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN %i oc ON oc.occurrence_id=tt.occurrence_id INNER JOIN %i e ON e.event_id=oc.event_id WHERE oi.order_id IN (' . implode( ',', array_fill( 0, count( $chunk ), '%d' ) ) . ') ORDER BY oi.order_id,oi.item_id', array_merge( array( $p . 'wpcap_order_items', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $p . 'wpcap_events' ), $chunk ) ) );
			foreach ( $rows as $row ) {
				$snapshot = json_decode( (string) $row->line_snapshot, true );
				$country = is_array( $snapshot ) ? (string) ( $snapshot['tax']['country'] ?? '' ) : '';
				if ( ! preg_match( '/^[A-Z]{2}$/', $country ) ) {
					$country = preg_match( '/^[A-Z]{2}$/', (string) $row->tax_country ) ? (string) $row->tax_country : $seller;
				}
				$result[ (int) $row->order_id ][] = array( 'item_id' => (int) $row->item_id, 'rate_bp' => (int) $row->tax_rate_bp, 'country' => $country );
			}
		}
		return $result;
	}

	/** Distinct countries of item rows, joined with "/" (usually one). */
	public static function countries_label( array $items ) {
		return implode( '/', array_values( array_unique( array_column( $items, 'country' ) ) ) );
	}

	/**
	 * Export rows for sales and refunds of orders without invoice (see export_rows()).
	 *
	 * @param string   $from_utc  UTC start (inclusive).
	 * @param string   $to_utc    UTC end (exclusive).
	 * @param callable $local_day UTC datetime => local Y-m-d.
	 */
	private static function uninvoiced_rows( $from_utc, $to_utc, callable $local_day, callable $money, callable $safe ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$comp = Reports\Data::comp_providers();
		$channels = Reports\Data::channel_labels();
		// Seller side of an order without invoice (the seller an invoice would have; empty for mixed sellers),
		// read for a whole block at once (A434).
		$seller_cols = static function ( $context ) use ( $safe ) {
			return ! is_array( $context ) ? array( '', '' ) : array( Partners\SalesModel::AGENT === $context['model'] ? 'Vermittler' : 'Eigenhaendler', $safe( (string) ( $context['seller']['name'] ?? '' ) ) );
		};
		// Ids first (index paid_created), then blocks of BLOCK orders with their lines, seller and exchange flag.
		$order_ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT o.order_id FROM {$p}wpcap_orders o WHERE o.payment_status IN ('paid','refunded') AND o.created_at >= %s AND o.created_at < %s AND NOT EXISTS (SELECT 1 FROM {$p}wpcap_invoices i WHERE i.order_id=o.order_id) ORDER BY o.order_id", $from_utc, $to_utc ) ) );
		foreach ( array_chunk( $order_ids, self::EXPORT_BLOCK ) as $block ) {
			$orders = $wpdb->get_results( "SELECT o.order_id,o.public_id,o.created_at,o.currency,o.payment_provider,o.discount_minor FROM {$p}wpcap_orders o WHERE o.order_id IN (" . implode( ',', $block ) . ') ORDER BY o.order_id' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
			$orders = array_values( array_filter( $orders, static function ( $o ) use ( $comp ) { return ! in_array( (string) $o->payment_provider, $comp, true ); } ) );
			$ids = array_map( static function ( $o ) { return (int) $o->order_id; }, $orders );
			$lines_by_order = Reports\Data::lines_by_order( $ids );
			$contexts = Partners\SalesModel::order_contexts( $ids );
			Exchange\Service::preload_supplements( $ids );
			foreach ( $orders as $o ) {
				$lines = $lines_by_order[ (int) $o->order_id ] ?? array();
				$gross_lines = Reports\Data::allocate_discount( $lines, (int) $o->discount_minor );
				$supplement = Exchange\Service::is_supplement( (int) $o->order_id );
				$by_rate = array();
				foreach ( $lines as $i => $line ) {
					if ( OrderLines::is_moved_in( $line ) ) { continue; }
					$rate = (int) $line->tax_rate_bp;
					$by_rate[ $rate ] = $by_rate[ $rate ] ?? array( 'quantity' => 0, 'gross' => 0, 'tax' => 0 );
					$by_rate[ $rate ]['quantity'] += $supplement ? 0 : (int) $line->quantity;
					$by_rate[ $rate ]['gross'] += (int) $gross_lines[ $i ];
					$by_rate[ $rate ]['tax'] += (int) $line->tax_minor;
				}
				ksort( $by_rate, SORT_NUMERIC );
				$description = 'Tickets ohne Rechnung (' . $channels[ Reports\Data::channel_of( $o->payment_provider ) ] . ')';
				foreach ( $by_rate as $rate => $r ) {
					yield array( self::EXPORT_KIND_SALE_UNINVOICED, '', $local_day( $o->created_at ), $o->public_id, '', $safe( $description ), $r['quantity'], $money( $r['gross'] - $r['tax'], $o->currency ), number_format( $rate / 100, 2, '.', '' ), $money( $r['tax'], $o->currency ), $money( $r['gross'], $o->currency ), $o->currency, $safe( $o->payment_provider ), ...$seller_cols( $contexts[ (int) $o->order_id ] ?? null ) );
				}
			}
		}
		$reversal_ready = Refunds::provider_status_ready();
		// A refund reversed later (A348) stays on its booking day; its reversal follows on its own day below.
		$refunds = $wpdb->get_results( $wpdb->prepare( "SELECT r.refund_id,r.order_id,r.amount_minor,r.tax_minor,r.ticket_ids,r.updated_at,o.currency,o.public_id,o.payment_provider,o.discount_minor,(o.voucher_snapshot IS NOT NULL AND o.voucher_snapshot<>'') AS has_voucher FROM {$p}wpcap_refunds r INNER JOIN {$p}wpcap_orders o ON o.order_id=r.order_id WHERE r.status IN ('completed'" . ( $reversal_ready ? ",'reversed'" : '' ) . ") AND r.updated_at >= %s AND r.updated_at < %s AND NOT EXISTS (SELECT 1 FROM {$p}wpcap_invoices i WHERE i.order_id=r.order_id) ORDER BY r.refund_id", $from_utc, $to_utc ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed status list.
		if ( $reversal_ready ) {
			$reversed = $wpdb->get_results( $wpdb->prepare( "SELECT r.refund_id,r.order_id,r.amount_minor,r.tax_minor,r.ticket_ids,r.reversed_at AS updated_at,1 AS is_reversal,o.currency,o.public_id,o.payment_provider,o.discount_minor,(o.voucher_snapshot IS NOT NULL AND o.voucher_snapshot<>'') AS has_voucher FROM {$p}wpcap_refunds r INNER JOIN {$p}wpcap_orders o ON o.order_id=r.order_id WHERE r.status='reversed' AND r.reversed_at >= %s AND r.reversed_at < %s AND NOT EXISTS (SELECT 1 FROM {$p}wpcap_invoices i WHERE i.order_id=r.order_id) ORDER BY r.refund_id", $from_utc, $to_utc ) );
			$refunds = array_merge( $refunds, $reversed );
		}
		$refunds = array_values( array_filter( $refunds, static function ( $r ) use ( $comp ) { return ! in_array( (string) $r->payment_provider, $comp, true ); } ) );
		foreach ( array_chunk( $refunds, self::EXPORT_BLOCK ) as $block ) {
			$ids = array_map( static function ( $r ) { return (int) $r->order_id; }, $block );
			$items_by_order = self::paid_items_by_order( $ids );
			$contexts = Partners\SalesModel::order_contexts( $ids );
			foreach ( $block as $r ) {
				// Same amounts as the sales report (multi-purpose vouchers: cash + voucher share, full VAT).
				$amounts = Integrations\Vouchers\Checkout::refund_amounts( $r );
				$description = 'Erstattung ohne Rechnung (' . $channels[ Reports\Data::channel_of( $r->payment_provider ) ] . ')';
				$sign = empty( $r->is_reversal ) ? '-' : '';
				if ( ! empty( $r->is_reversal ) ) { $description = 'Storno ' . $description; }
				foreach ( self::credit_note_rates( array( 'order_id' => (int) $r->order_id, 'ticket_ids' => $r->ticket_ids ), (int) $amounts['amount_minor'], (int) $amounts['tax_minor'], $items_by_order[ (int) $r->order_id ] ?? array(), (int) $r->discount_minor ) as $rate ) {
					yield array( self::EXPORT_KIND_REFUND_UNINVOICED, '', $local_day( $r->updated_at ), $r->public_id, '', $safe( $description ), '', $sign . $money( $rate['net'], $r->currency ), number_format( $rate['rate_bp'] / 100, 2, '.', '' ), $sign . $money( $rate['tax'], $r->currency ), $sign . $money( $rate['gross'], $r->currency ), $r->currency, $safe( $r->payment_provider ), ...$seller_cols( $contexts[ (int) $r->order_id ] ?? null ) );
				}
			}
		}
	}

	/** Orders per block of the accounting export (uninvoiced_rows()). */
	const EXPORT_BLOCK = 1000;

	/** Paid lines of many orders as credit_note_rates() reads them (order id => lines in item order). */
	private static function paid_items_by_order( array $order_ids ) {
		global $wpdb;
		$out = array();
		foreach ( array_chunk( array_values( array_unique( array_filter( array_map( 'absint', $order_ids ) ) ) ), 500 ) as $chunk ) {
			$out += array_fill_keys( $chunk, array() );
			foreach ( (array) $wpdb->get_results( 'SELECT order_id,ticket_type_id,tax_rate_bp,' . OrderLines::paid_columns( '' ) . " FROM {$wpdb->prefix}wpcap_order_items WHERE order_id IN (" . implode( ',', $chunk ) . ') ORDER BY order_id,item_id' ) as $item ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
				$out[ (int) $item->order_id ][] = $item;
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ Journal totals, DATEV booking batch, number gaps */

	/** Converts a decimal string of the export ("-12.34", "1500" JPY, "1.250" KWD) into minor units (exact, no float). */
	public static function decimal_to_minor( $value, $currency = 'EUR' ) {
		return (int) Money::to_minor( $value, $currency );
	}

	/**
	 * Totals of accounting export rows per currency and VAT rate (minor units, signed).
	 *
	 * @param array[] $rows export_rows() (header row included or not).
	 * @return array Currency => ['rates' => [rate "19.00" => [net, tax, gross]], 'total' => [net, tax, gross]].
	 */
	public static function journal_totals( array $rows ) {
		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || count( $row ) < 12 || self::EXPORT_COLUMNS[0] === $row[0] ) { continue; }
			$cur = (string) $row[11];
			$rate = (string) $row[8];
			$amounts = array( 'net' => self::decimal_to_minor( $row[7], $cur ), 'tax' => self::decimal_to_minor( $row[9], $cur ), 'gross' => self::decimal_to_minor( $row[10], $cur ) );
			$out[ $cur ] = $out[ $cur ] ?? array( 'rates' => array(), 'total' => array( 'net' => 0, 'tax' => 0, 'gross' => 0 ) );
			$out[ $cur ]['rates'][ $rate ] = $out[ $cur ]['rates'][ $rate ] ?? array( 'net' => 0, 'tax' => 0, 'gross' => 0 );
			foreach ( $amounts as $k => $v ) {
				$out[ $cur ]['rates'][ $rate ][ $k ] += $v;
				$out[ $cur ]['total'][ $k ] += $v;
			}
		}
		ksort( $out );
		foreach ( $out as $cur => $c ) { ksort( $out[ $cur ]['rates'], SORT_NUMERIC ); }
		return $out;
	}

	/** Columns of the DATEV-style booking batch (names of the DATEV booking batch fields). */
	const DATEV_COLUMNS = array( 'Umsatz (ohne Soll/Haben-Kz)', 'Soll/Haben-Kennzeichen', 'WKZ Umsatz', 'Konto', 'Gegenkonto (ohne BU-Schlüssel)', 'BU-Schlüssel', 'Belegdatum', 'Belegfeld 1', 'Belegfeld 2', 'Buchungstext' );

	/**
	 * Account mapping of the booking batch. Defaults follow SKR03 (revenue accounts with automatic VAT:
	 * 8400 = 19 %, 8300 = 7 %; other rates 8200; money account 1360 "Geldtransit"). Filter
	 * `wpcap_datev_accounts` (SKR04, own accounts, BU keys, one money account per payment provider).
	 *
	 * @return array{revenue: array<int,string>, revenue_default: string, bu_keys: array<int,string>, contra: string, contra_by_provider: array<string,string>}
	 */
	public static function datev_accounts() {
		$defaults = array(
			'revenue' => array( 1900 => '8400', 700 => '8300' ),
			'revenue_default' => '8200',
			'bu_keys' => array(),
			'contra' => '1360',
			'contra_by_provider' => array(),
		);
		/**
		 * Accounts of the DATEV-style booking batch (docs/OPERATIONS.md "Buchungsstapel").
		 *
		 * @param array $accounts revenue (rate in basis points => account), revenue_default, bu_keys (rate in basis
		 *                        points => BU key), contra (money account), contra_by_provider (payment provider => account).
		 */
		$accounts = (array) apply_filters( 'wpcap_datev_accounts', $defaults );
		return array_merge( $defaults, array_intersect_key( $accounts, $defaults ) );
	}

	/**
	 * DATEV-style booking batch (first row = header): one booking per document (or order without invoice),
	 * VAT rate and currency, gross amount on the revenue account of the rate against the money account.
	 * Sales are "S" (money account debit), corrections and refunds "H"; the amount is always positive.
	 * Belegfeld 1 = document number (order number without invoice), Belegfeld 2 = corrected invoice.
	 * Documents of the agent model (Vermittler) are the organizer's revenue and are not booked here.
	 * Built from export_rows(), so both exports always agree.
	 */
	public static function datev_rows( $from, $to ) {
		return iterator_to_array( self::datev_iter( $from, $to ), false );
	}

	/**
	 * datev_rows() as a generator over export_iter() (A434). The rows of one document follow each
	 * other in the export, so its bookings are complete when the next document starts and are sent
	 * then; only refunds of orders without invoice (the last part of the export, keyed by order and
	 * day) are collected until the end. Same rows in the same order as before.
	 *
	 * @return \Generator<int, array>
	 */
	private static function datev_iter( $from, $to ) {
		$accounts = self::datev_accounts();
		$bookings = array();
		$current = null;
		$first = true;
		yield self::DATEV_COLUMNS;
		foreach ( self::export_iter( $from, $to ) as $row ) {
			if ( $first ) { $first = false; continue; } // Header of the export.
			if ( 'Vermittler' === $row[13] ) { continue; }
			$doc = '' !== (string) $row[1] ? (string) $row[1] : (string) $row[3];
			$document = $row[0] . '|' . $doc;
			if ( null !== $current && $document !== $current && self::EXPORT_KIND_REFUND_UNINVOICED !== $row[0] ) {
				yield from self::datev_bookings( $bookings );
				$bookings = array();
			}
			$current = $document;
			$key = implode( '|', array( $row[0], $doc, $row[2], $row[8], $row[11], $row[12] ) );
			if ( ! isset( $bookings[ $key ] ) ) {
				$rate_bp = (int) round( (float) $row[8] * 100 );
				$bookings[ $key ] = array(
					'gross' => 0,
					'currency' => (string) $row[11],
					'account' => (string) ( $accounts['revenue'][ $rate_bp ] ?? $accounts['revenue_default'] ),
					'contra' => (string) ( $accounts['contra_by_provider'][ ltrim( (string) $row[12], "'" ) ] ?? $accounts['contra'] ),
					'bu' => (string) ( $accounts['bu_keys'][ $rate_bp ] ?? '' ),
					'date' => (string) $row[2],
					'doc' => $doc,
					'ref' => (string) $row[4],
					'text' => $row[0] . ' ' . str_replace( '.', ',', (string) $row[8] ) . ' %',
				);
			}
			$bookings[ $key ]['gross'] += self::decimal_to_minor( $row[10], (string) $row[11] );
		}
		yield from self::datev_bookings( $bookings );
	}

	/** Booking batch rows of collected bookings (datev_iter()). */
	private static function datev_bookings( array $bookings ) {
		foreach ( $bookings as $b ) {
			if ( 0 === $b['gross'] ) { continue; }
			yield array(
				Money::to_decimal( abs( $b['gross'] ), $b['currency'], ',' ),
				$b['gross'] > 0 ? 'S' : 'H',
				$b['currency'],
				$b['contra'],
				$b['account'],
				$b['bu'],
				substr( $b['date'], 8, 2 ) . substr( $b['date'], 5, 2 ),
				substr( preg_replace( '/[^A-Za-z0-9$&%*+\-\/._]/', '', $b['doc'] ), 0, 36 ),
				substr( preg_replace( '/[^A-Za-z0-9$&%*+\-\/._]/', '', $b['ref'] ), 0, 36 ),
				mb_substr( $b['text'], 0, 60 ),
			);
		}
	}

	/**
	 * Missing numbers per number range (prefix and year) in a list of document numbers
	 * ("PREFIX-YYYY-000123"); a range is checked from its lowest to its highest number.
	 *
	 * @param string[] $numbers Document numbers.
	 * @return array<string,string[]> "PREFIX-YYYY" => missing numbers (formatted like the documents).
	 */
	public static function number_gaps( array $numbers ) {
		$ranges = array();
		foreach ( $numbers as $number ) {
			if ( preg_match( '/^(.+)-(\d{4})-(\d{6,})$/', (string) $number, $m ) ) {
				$ranges[ $m[1] . '-' . $m[2] ][] = (int) $m[3];
			}
		}
		ksort( $ranges );
		$out = array();
		foreach ( $ranges as $range => $seq ) {
			$present = array_flip( $seq );
			$missing = array();
			for ( $n = min( $seq ), $max = max( $seq ); $n <= $max && count( $missing ) < 1000; $n++ ) {
				if ( ! isset( $present[ $n ] ) ) { $missing[] = sprintf( '%s-%06d', $range, $n ); }
			}
			if ( $missing ) { $out[ $range ] = $missing; }
		}
		return $out;
	}

	/**
	 * Gaps in the invoice and correction number ranges of the years a period touches (local dates).
	 *
	 * @return array<string,string[]> See number_gaps().
	 */
	public static function document_gaps( $from, $to ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$first = (int) substr( (string) $from, 0, 4 );
		$last = (int) substr( (string) $to, 0, 4 );
		if ( $first < 1970 || $last < $first ) { return array(); }
		// Issue dates one day around the years (UTC vs. local year), numbers filtered by their year.
		$start = ( $first - 1 ) . '-12-31 00:00:00';
		$end = ( $last + 1 ) . '-01-02 00:00:00';
		$numbers = array_merge(
			(array) $wpdb->get_col( $wpdb->prepare( "SELECT invoice_number FROM {$p}wpcap_invoices WHERE issued_at >= %s AND issued_at < %s", $start, $end ) ),
			(array) $wpdb->get_col( $wpdb->prepare( "SELECT credit_note_number FROM {$p}wpcap_credit_notes WHERE issued_at >= %s AND issued_at < %s", $start, $end ) )
		);
		$numbers = array_filter(
			$numbers,
			static function ( $n ) use ( $first, $last ) {
				return preg_match( '/-(\d{4})-\d{6,}$/', (string) $n, $m ) && (int) $m[1] >= $first && (int) $m[1] <= $last;
			}
		);
		return self::number_gaps( $numbers );
	}

	/** Finance view permission for one order (global finance roles or event-scoped order managers). */
	private static function can_view_order_finance( $order_id ) {
		if ( current_user_can( 'manage_options' ) || ( current_user_can( self::FINANCE_CAP ) && Access::order_in_scope( $order_id ) ) ) { return true; }
		global $wpdb;
		$event_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT o.event_id FROM %i oi INNER JOIN %i tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id WHERE oi.order_id=%d', $wpdb->prefix . 'wpcap_order_items', $wpdb->prefix . 'wpcap_ticket_types', $wpdb->prefix . 'wpcap_occurrences', absint( $order_id ) ) );
		if ( ! $event_ids ) { return false; }
		foreach ( $event_ids as $event_id ) {
			if ( ! Access::can_access_event( (int) $event_id, 'wpcap_manage_orders' ) ) { return false; }
		}
		return Access::order_in_scope( $order_id );
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
				$out['rows'][] = array( sprintf( __( 'Bezahlt mit Gutschein %s (Mehrzweckgutschein)', 'pass-captain' ), $code ), -$amount, false );
			} else {
				/* translators: %s: masked voucher code */
				$out['notes'][] = sprintf( __( 'Eingelöster Einzweckgutschein %s: Die Umsatzsteuer wurde bereits beim Verkauf des Gutscheins abgerechnet; der Gutscheinwert ist als Preisnachlass berücksichtigt.', 'pass-captain' ), $code );
			}
		}
		if ( $out['rows'] ) {
			$out['rows'][] = array( __( 'Restbetrag (bezahlt)', 'pass-captain' ), max( 0, (int) $invoice->total_minor - $voucher_total ), true );
			$out['notes'][] = __( 'Der Gutschein ist ein Zahlungsmittel (Mehrzweckgutschein); die Umsatzsteuer ist auf den vollen Rechnungsbetrag ausgewiesen.', 'pass-captain' );
		}
		return $out;
	}

	/** HTML of the "Zahlungen" section for render_html() (empty string without voucher). */
	private static function payments_html( $invoice, array $snapshot, callable $money ) {
		$summary = self::payment_summary( $invoice, $snapshot );
		if ( ! $summary['rows'] && ! $summary['notes'] ) { return ''; }
		$html = '<section class="wpcap-payments"><h2>' . esc_html__( 'Zahlungen', 'pass-captain' ) . '</h2>';
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

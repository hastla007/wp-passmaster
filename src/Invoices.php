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
		$items = $wpdb->get_results( $wpdb->prepare( "SELECT oi.*,tt.name,e.name AS event_name,o.starts_at,o.timezone FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE oi.order_id=%d ORDER BY oi.item_id", $order_id ) );
		$order_subtotal = (int) $order->subtotal_minor ?: (int) $order->total_minor;
		$wpdb->query( 'START TRANSACTION' );
		$locked_order = $wpdb->get_row( $wpdb->prepare( "SELECT payment_status FROM {$p}wpcap_orders WHERE order_id=%d FOR UPDATE", $order_id ) );
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT invoice_id,invoice_number FROM {$p}wpcap_invoices WHERE order_id=%d", $order_id ) );
		if ( $existing ) { $wpdb->query( 'COMMIT' ); return array( 'invoice_id' => (int) $existing->invoice_id, 'invoice_number' => $existing->invoice_number, 'reused' => true ); }
		if ( ! $locked_order || 'paid' !== $locked_order->payment_status ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_invoice_order_unpaid', __( 'Die Bestellung ist nicht mehr als bezahlt markiert.', 'wp-pass-captain' ), array( 'status' => 409 ) ); }
		// Reserve the number in the same transaction so a rollback never leaves a gap.
		$number = self::next_number( 0, true );
		if ( is_wp_error( $number ) ) { $wpdb->query( 'ROLLBACK' ); return $number; }
		$snapshot = array(
			'email' => $order->customer_email,
			'currency' => $order->currency,
			'subtotal_minor' => $order_subtotal,
			'discount_minor' => (int) $order->discount_minor,
			'total_minor' => (int) $order->total_minor,
			'terms_version' => $order->terms_version,
			'seller' => self::seller(),
		);
		$saved = $wpdb->insert( $p . 'wpcap_invoices', array( 'order_id' => $order_id, 'invoice_number' => $number, 'currency' => $order->currency, 'subtotal_minor' => $order_subtotal, 'tax_minor' => (int) $order->tax_minor, 'total_minor' => (int) $order->total_minor, 'buyer_snapshot' => wp_json_encode( $snapshot ), 'issued_at' => current_time( 'mysql', true ), 'status' => 'issued' ) );
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
		Audit::record( 'invoice_issued', 'invoice', $invoice_id, array( 'order_id' => $order_id, 'invoice_number' => $number ) );
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
		$invoice = $wpdb->get_row( $wpdb->prepare( "SELECT invoice_id FROM {$p}wpcap_invoices WHERE order_id=%d", (int) $refund['order_id'] ) );
		if ( ! $invoice ) { return null; }
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_credit_notes WHERE refund_id=%d", absint( $refund_id ) ), ARRAY_A );
		if ( $existing ) { return $existing; }
		$wpdb->query( 'START TRANSACTION' );
		$number = self::next_document_number( 'credit', (string) Settings::get( 'credit_note_prefix' ) ?: 'GS', true );
		if ( is_wp_error( $number ) ) { $wpdb->query( 'ROLLBACK' ); return $number; }
		$saved = $wpdb->insert( "{$p}wpcap_credit_notes", array(
			'invoice_id' => (int) $invoice->invoice_id,
			'refund_id' => absint( $refund_id ),
			'credit_note_number' => $number,
			'currency' => $refund['currency'],
			'net_minor' => (int) $refund['amount_minor'] - (int) $refund['tax_minor'],
			'tax_minor' => (int) $refund['tax_minor'],
			'total_minor' => (int) $refund['amount_minor'],
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

	/** Seller data for documents. */
	private static function seller() {
		return array(
			'name' => (string) Settings::get( 'seller_name' ),
			'address' => (string) Settings::get( 'seller_address' ),
			'vat_id' => (string) Settings::get( 'seller_vat_id' ),
		);
	}

	/**
	 * Reserves the next invoice number. With $in_transaction the caller owns the transaction, so
	 * the increment is rolled back together with a failed invoice.
	 */
	public static function next_number( $year = 0, $in_transaction = false ) {
		global $wpdb;
		$year = $year ? absint( $year ) : (int) gmdate( 'Y' );
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

	/** Gapless numbers for further document series (e.g. credit notes); caller may own the transaction. */
	public static function next_document_number( $series, $prefix, $in_transaction = false ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcap_document_sequences';
		$series = sanitize_key( $series );
		$year = (int) gmdate( 'Y' );
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
		$rates = array();
		foreach ( $lines as $line ) {
			$key = (string) (float) $line->tax_rate;
			$rates[ $key ] = ( $rates[ $key ] ?? 0 ) + (int) $line->tax_minor;
		}
		ob_start();
		?><!doctype html><html lang="<?php echo esc_attr( determine_locale() ); ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html( $invoice->invoice_number ); ?></title><style>body{font:14px/1.45 system-ui,sans-serif;max-width:52rem;margin:2rem auto;padding:0 1rem;color:#1d2327}table{width:100%;border-collapse:collapse;margin:1rem 0}th,td{border-bottom:1px solid #dcdcde;padding:.4rem;text-align:left}td.n,th.n{text-align:right}.seller{white-space:pre-line}@media print{button{display:none}}</style></head><body>
		<header><div class="seller"><strong><?php echo esc_html( $seller['name'] ?? '' ); ?></strong>
<?php echo esc_html( $seller['address'] ?? '' ); ?><?php if ( ! empty( $seller['vat_id'] ) ) : ?>
<?php echo esc_html( __( 'USt-IdNr.:', 'wp-pass-captain' ) . ' ' . $seller['vat_id'] ); ?><?php endif; ?></div></header>
		<h1><?php echo esc_html( __( 'Rechnung', 'wp-pass-captain' ) . ' ' . $invoice->invoice_number ); ?></h1>
		<p><?php echo esc_html( __( 'Rechnungsdatum:', 'wp-pass-captain' ) . ' ' . wp_date( 'd.m.Y', strtotime( $invoice->issued_at . ' UTC' ) ) ); ?><br><?php echo esc_html( __( 'Rechnungsempfänger:', 'wp-pass-captain' ) . ' ' . ( $snapshot['email'] ?? '' ) ); ?></p>
		<table><thead><tr><th><?php esc_html_e( 'Leistung', 'wp-pass-captain' ); ?></th><th class="n"><?php esc_html_e( 'Menge', 'wp-pass-captain' ); ?></th><th class="n"><?php esc_html_e( 'Einzelpreis', 'wp-pass-captain' ); ?></th><th class="n"><?php esc_html_e( 'Rabatt', 'wp-pass-captain' ); ?></th><th class="n"><?php esc_html_e( 'USt.', 'wp-pass-captain' ); ?></th><th class="n"><?php esc_html_e( 'Gesamt (brutto)', 'wp-pass-captain' ); ?></th></tr></thead><tbody>
		<?php foreach ( $lines as $line ) : ?><tr><td><?php echo esc_html( $line->description ); ?></td><td class="n"><?php echo esc_html( $line->quantity ); ?></td><td class="n"><?php echo esc_html( $money( (int) $line->unit_minor ) ); ?></td><td class="n"><?php echo esc_html( $money( (int) $line->discount_minor ) ); ?></td><td class="n"><?php echo esc_html( number_format_i18n( (float) $line->tax_rate, 2 ) . ' %' ); ?></td><td class="n"><?php echo esc_html( $money( (int) $line->total_minor ) ); ?></td></tr><?php endforeach; ?>
		</tbody></table>
		<table><tbody>
		<tr><td><?php esc_html_e( 'Nettobetrag', 'wp-pass-captain' ); ?></td><td class="n"><?php echo esc_html( $money( (int) $invoice->total_minor - (int) $invoice->tax_minor ) ); ?></td></tr>
		<?php foreach ( $rates as $rate => $tax ) : ?><tr><td><?php echo esc_html( sprintf( /* translators: %s: rate */ __( 'Umsatzsteuer %s %%', 'wp-pass-captain' ), number_format_i18n( (float) $rate, 2 ) ) ); ?></td><td class="n"><?php echo esc_html( $money( $tax ) ); ?></td></tr><?php endforeach; ?>
		<tr><th><?php esc_html_e( 'Rechnungsbetrag', 'wp-pass-captain' ); ?></th><th class="n"><?php echo esc_html( $money( (int) $invoice->total_minor ) ); ?></th></tr>
		</tbody></table>
		<?php foreach ( $credits as $credit ) : ?><h2><?php echo esc_html( __( 'Gutschrift', 'wp-pass-captain' ) . ' ' . $credit->credit_note_number ); ?></h2><p><?php echo esc_html( sprintf( /* translators: 1: invoice number, 2: date */ __( 'Korrektur zu Rechnung %1$s vom %2$s', 'wp-pass-captain' ), $invoice->invoice_number, wp_date( 'd.m.Y', strtotime( $credit->issued_at . ' UTC' ) ) ) ); ?></p><table><tbody><tr><td><?php esc_html_e( 'Nettobetrag', 'wp-pass-captain' ); ?></td><td class="n">-<?php echo esc_html( $money( (int) $credit->net_minor ) ); ?></td></tr><tr><td><?php esc_html_e( 'Umsatzsteuer', 'wp-pass-captain' ); ?></td><td class="n">-<?php echo esc_html( $money( (int) $credit->tax_minor ) ); ?></td></tr><tr><th><?php esc_html_e( 'Gutschriftsbetrag', 'wp-pass-captain' ); ?></th><th class="n">-<?php echo esc_html( $money( (int) $credit->total_minor ) ); ?></th></tr></tbody></table><?php endforeach; ?>
		<button onclick="window.print()"><?php esc_html_e( 'Drucken oder als PDF speichern', 'wp-pass-captain' ); ?></button>
		</body></html><?php
		return (string) ob_get_clean();
	}

	/** Admin print view: admin-post.php?action=wpcap_print_invoice&order_id=… */
	public static function handle_admin_print() {
		$order_id = absint( $_GET['order_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view, capability and scope checked.
		if ( ! self::can_view_order_finance( $order_id ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 ); }
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
		foreach ( $rows as $row ) { fputcsv( $out, $row, ';' ); }
		fclose( $out );
		exit;
	}

	/** Builds export rows (first row = header). Amounts are decimal strings; text cells are CSV-injection safe. */
	public static function export_rows( $from, $to ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$from_utc = $from . ' 00:00:00';
		$to_utc = gmdate( 'Y-m-d H:i:s', strtotime( $to . ' 00:00:00 UTC' ) + DAY_IN_SECONDS );
		$rows = array( array( 'Belegart', 'Belegnummer', 'Belegdatum', 'Bestellung', 'Bezug', 'Beschreibung', 'Menge', 'Netto', 'USt-Satz', 'USt', 'Brutto', 'Waehrung', 'Zahlungsart' ) );
		$money = static function ( $minor ) { return number_format( $minor / 100, 2, '.', '' ); };
		$safe = static function ( $text ) { $text = (string) $text; return preg_match( '/^[=+\-@\t\r]/', $text ) ? "'" . $text : $text; };
		$lines = $wpdb->get_results( $wpdb->prepare( "SELECT i.invoice_number,i.issued_at,i.currency,o.public_id,o.payment_provider,l.description,l.quantity,l.tax_rate,l.tax_minor,l.total_minor FROM {$p}wpcap_invoices i INNER JOIN {$p}wpcap_orders o ON o.order_id=i.order_id INNER JOIN {$p}wpcap_invoice_lines l ON l.invoice_id=i.invoice_id WHERE i.issued_at >= %s AND i.issued_at < %s ORDER BY i.invoice_id,l.invoice_line_id", $from_utc, $to_utc ) );
		foreach ( $lines as $l ) {
			$rows[] = array( 'Rechnung', $l->invoice_number, substr( $l->issued_at, 0, 10 ), $l->public_id, '', $safe( $l->description ), (int) $l->quantity, $money( (int) $l->total_minor - (int) $l->tax_minor ), number_format( (float) $l->tax_rate, 2, '.', '' ), $money( (int) $l->tax_minor ), $money( (int) $l->total_minor ), $l->currency, $safe( $l->payment_provider ) );
		}
		$credits = $wpdb->get_results( $wpdb->prepare( "SELECT c.*,i.invoice_number,o.public_id,o.payment_provider FROM {$p}wpcap_credit_notes c INNER JOIN {$p}wpcap_invoices i ON i.invoice_id=c.invoice_id INNER JOIN {$p}wpcap_orders o ON o.order_id=i.order_id WHERE c.issued_at >= %s AND c.issued_at < %s ORDER BY c.credit_note_id", $from_utc, $to_utc ) );
		foreach ( $credits as $c ) {
			$rows[] = array( 'Gutschrift', $c->credit_note_number, substr( $c->issued_at, 0, 10 ), $c->public_id, $c->invoice_number, 'Erstattung', '', '-' . $money( (int) $c->net_minor ), '', '-' . $money( (int) $c->tax_minor ), '-' . $money( (int) $c->total_minor ), $c->currency, $safe( $c->payment_provider ) );
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
}

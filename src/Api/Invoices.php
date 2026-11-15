<?php
/**
 * Public, versioned read API for invoice documents (contract version 1) plus the add-on hooks around them.
 *
 * Document types:
 *  - invoice     customer invoice of an order (table wpcap_invoices; id = invoice_id),
 *  - correction  invoice correction / credit note for a refund (wpcap_credit_notes; id = credit_note_id),
 *  - settlement  finalized organizer settlement: platform fee invoice, for own-name organizers with the
 *                self-billing part (wpcap_partner_settlements; id = settlement_id; drafts are no documents).
 *
 * Hooks (docs/HOOKS.md, docs/EXTENSIONS.md "Pass Captain Pro / Add-on-Schnittstellen"):
 *  - action `wp_pass_captain_invoice_created_v1( int $id, string $type, array $context )` after a document
 *    was committed (fired once per document; exceptions of callbacks are logged, never break the core),
 *  - filter `wp_pass_captain_invoice_mail_attachments_v1( string[] $files, int $id, string $type, array $context )`
 *    for the mail that carries the document (ticket mail for invoices, refund confirmation for corrections),
 *  - filter `wp_pass_captain_invoice_links_v1( array $links, int $id, string $type, array $context )` for extra
 *    links/downloads next to the document (admin order page, order portal, HTML document views).
 *
 * The arrays returned by get() only change compatibly within version 1 (new keys may be added).
 * Amounts are integers in minor units of `currency`; `currency_decimals` says how many decimals that is.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Api;

use PassCaptain\Billing;
use PassCaptain\Frontend\Format;
use PassCaptain\Localization;
use PassCaptain\Log;
use PassCaptain\Partners\SalesModel;
use PassCaptain\Partners\Settlements;
use PassCaptain\TempFiles;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Invoices {
	const VERSION = 1;
	const TYPES = array( 'invoice', 'correction', 'settlement' );
	const CREATED_ACTION = 'wp_pass_captain_invoice_created_v1';
	const MAIL_ATTACHMENTS_FILTER = 'wp_pass_captain_invoice_mail_attachments_v1';
	const LINKS_FILTER = 'wp_pass_captain_invoice_links_v1';

	/**
	 * Document as a stable array, or null when it does not exist (or is a settlement draft).
	 *
	 * @param int    $id   Document id of the type (invoice_id, credit_note_id, settlement_id).
	 * @param string $type invoice|correction|settlement.
	 * @return array<string,mixed>|null
	 */
	public static function get( $id, $type = 'invoice' ) {
		$id = absint( $id );
		if ( ! $id ) { return null; }
		switch ( (string) $type ) {
			case 'invoice':
				return self::invoice( $id );
			case 'correction':
				return self::correction( $id );
			case 'settlement':
				return self::settlement( $id );
		}
		return null;
	}

	/** Invoice of an order (or null). */
	public static function for_order( $order_id ) {
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT invoice_id FROM %i WHERE order_id=%d LIMIT 1', $wpdb->prefix . 'wpcap_invoices', absint( $order_id ) ) );
		return $id ? self::invoice( $id ) : null;
	}

	/**
	 * Invoice id for the buyer's personal order link (same check as the core buyer views: public order id,
	 * unexpired access token). 0 when the link is invalid or the order has no invoice.
	 */
	public static function for_order_access( $public_id, $token ) {
		global $wpdb;
		$public_id = sanitize_text_field( (string) $public_id );
		$token = strtolower( (string) preg_replace( '/[^a-fA-F0-9]/', '', (string) $token ) );
		if ( '' === $public_id || 64 !== strlen( $token ) ) { return 0; }
		$order = $wpdb->get_row( $wpdb->prepare( 'SELECT order_id,access_token_hash,access_expires_at FROM %i WHERE public_id=%s', $wpdb->prefix . 'wpcap_orders', $public_id ) );
		if ( ! $order || ! $order->access_expires_at || $order->access_expires_at <= current_time( 'mysql', true ) || ! hash_equals( (string) $order->access_token_hash, hash( 'sha256', $token ) ) ) { return 0; }
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT invoice_id FROM %i WHERE order_id=%d', $wpdb->prefix . 'wpcap_invoices', (int) $order->order_id ) );
	}

	/**
	 * Whether the current user may see a document in the admin (finance roles; order managers of every
	 * event of the order; settlements: finance or the organizer's linked users for finalized ones).
	 */
	public static function current_user_can_view( $id, $type = 'invoice' ) {
		global $wpdb;
		$id = absint( $id );
		if ( 'settlement' === $type ) {
			return Settlements::can_view( Settlements::get( $id ) );
		}
		$order_id = 'correction' === $type
			? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT i.order_id FROM %i c INNER JOIN %i i ON i.invoice_id=c.invoice_id WHERE c.credit_note_id=%d', $wpdb->prefix . 'wpcap_credit_notes', $wpdb->prefix . 'wpcap_invoices', $id ) )
			: (int) $wpdb->get_var( $wpdb->prepare( 'SELECT order_id FROM %i WHERE invoice_id=%d', $wpdb->prefix . 'wpcap_invoices', $id ) );
		return $order_id && \PassCaptain\Printing\InvoicePdf::can_view_order_finance( $order_id );
	}

	/* ------------------------------------------------------------------ Hooks fired by the core */

	/** Fires wp_pass_captain_invoice_created_v1 (after the document's transaction was committed). */
	public static function created( $id, $type, array $context = array() ) {
		try {
			do_action( 'wp_pass_captain_invoice_created_v1', absint( $id ), (string) $type, $context );
		} catch ( \Throwable $e ) {
			Log::warning( 'invoice_created_hook_failed', 'A callback of wp_pass_captain_invoice_created_v1 failed.', array( 'id' => absint( $id ), 'type' => (string) $type, 'error' => get_class( $e ) ) );
		}
	}

	/**
	 * Extra attachments for the mail that carries a document. Files are temporary: the core deletes them
	 * after sending (use temp_file()). Only readable files are kept.
	 *
	 * @return string[]
	 */
	public static function mail_attachments( $id, $type, array $context = array() ) {
		$files = apply_filters( 'wp_pass_captain_invoice_mail_attachments_v1', array(), absint( $id ), (string) $type, $context );
		$out = array();
		foreach ( is_array( $files ) ? $files : array() as $file ) {
			if ( is_string( $file ) && '' !== $file && is_file( $file ) && is_readable( $file ) ) { $out[] = $file; }
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * wpcap_ticket_mail filter (priority 30): attachments of add-ons for the order's invoice, if the order has
	 * one when the ticket mail is sent (paid orders with automatic invoices always do).
	 */
	public static function filter_ticket_mail( $mail, $order = null ) {
		if ( ! is_array( $mail ) || ! is_object( $order ) || empty( $order->order_id ) ) { return $mail; }
		global $wpdb;
		$invoice_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT invoice_id FROM %i WHERE order_id=%d', $wpdb->prefix . 'wpcap_invoices', (int) $order->order_id ) );
		if ( ! $invoice_id ) { return $mail; }
		$files = self::mail_attachments( $invoice_id, 'invoice', array( 'mail' => 'tickets', 'order_id' => (int) $order->order_id ) );
		if ( $files ) { $mail['attachments'] = array_values( array_unique( array_merge( (array) ( $mail['attachments'] ?? array() ), $files ) ) ); }
		return $mail;
	}

	/** Attachments for the refund confirmation of a refund with a correction document. */
	public static function correction_mail_attachments( $refund_id, $order_id ) {
		global $wpdb;
		$credit_note_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT credit_note_id FROM %i WHERE refund_id=%d', $wpdb->prefix . 'wpcap_credit_notes', absint( $refund_id ) ) );
		return $credit_note_id ? self::mail_attachments( $credit_note_id, 'correction', array( 'mail' => 'refund_confirmation', 'order_id' => absint( $order_id ), 'refund_id' => absint( $refund_id ) ) ) : array();
	}

	/**
	 * Writes a temporary file (unguessable private directory, removed at shutdown at the latest) for a mail
	 * attachment, e.g. an XML e-invoice. Returns the path or '' on failure.
	 */
	public static function temp_file( $filename, $contents ) {
		$filename = sanitize_file_name( (string) $filename );
		$dir = TempFiles::private_dir();
		if ( '' === $filename || ! $dir ) { return ''; }
		$file = $dir . '/' . $filename;
		TempFiles::track( $file, $dir );
		return TempFiles::put( $file, (string) $contents ) ? $file : '';
	}

	/**
	 * Extra links of add-ons for a document. Each link: label (text), url (http(s) or site-relative), optional
	 * download (bool). Invalid entries are dropped.
	 *
	 * @param array $context audience (admin|customer), location (admin_order|portal|invoice_view|settlement_view),
	 *                       order_id, order_public_id, access_token (customer audience only).
	 * @return array<int,array{label:string,url:string,download:bool}>
	 */
	public static function links( $id, $type, array $context ) {
		$links = apply_filters( 'wp_pass_captain_invoice_links_v1', array(), absint( $id ), (string) $type, $context );
		$out = array();
		foreach ( is_array( $links ) ? $links : array() as $link ) {
			if ( ! is_array( $link ) ) { continue; }
			$label = sanitize_text_field( (string) ( $link['label'] ?? '' ) );
			$url = trim( (string) ( $link['url'] ?? '' ) );
			if ( '' === $label || ! preg_match( '#^(https?://|/(?!/))#i', $url ) ) { continue; }
			$out[] = array( 'label' => $label, 'url' => esc_url_raw( $url ), 'download' => ! empty( $link['download'] ) );
		}
		return $out;
	}

	/** Escaped HTML list of the extra links ('' without links). */
	public static function links_html( $id, $type, array $context, $separator = '<br>' ) {
		$parts = array();
		foreach ( self::links( $id, $type, $context ) as $link ) {
			$parts[] = '<a class="wpcap-invoice-link" href="' . esc_attr( $link['url'] ) . '"' . ( $link['download'] ? ' download' : '' ) . ' rel="noopener">' . esc_html( $link['label'] ) . '</a>';
		}
		return $parts ? '<span class="wpcap-invoice-links">' . implode( $separator, $parts ) . '</span>' : '';
	}

	/* ------------------------------------------------------------------ Builders */

	private static function invoice( $id ) {
		global $wpdb;
		$invoice = $wpdb->get_row( $wpdb->prepare( 'SELECT i.*,o.public_id AS order_public_id FROM %i i LEFT JOIN %i o ON o.order_id=i.order_id WHERE i.invoice_id=%d', $wpdb->prefix . 'wpcap_invoices', $wpdb->prefix . 'wpcap_orders', $id ) );
		if ( ! $invoice ) { return null; }
		$snapshot = (array) json_decode( (string) $invoice->buyer_snapshot, true );
		$currency = (string) $invoice->currency;
		$lines = array();
		$position = 0;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE invoice_id=%d ORDER BY invoice_line_id', $wpdb->prefix . 'wpcap_invoice_lines', $id ) );
		foreach ( $rows as $row ) {
			$gross = (int) $row->total_minor;
			$vat = (int) $row->tax_minor;
			$lines[] = array(
				'position' => ++$position,
				'description' => (string) $row->description,
				'quantity' => (int) $row->quantity,
				'unit_gross_minor' => (int) $row->unit_minor,
				'discount_minor' => (int) $row->discount_minor,
				'net_minor' => $gross - $vat,
				'vat_rate' => (float) $row->tax_rate,
				'vat_minor' => $vat,
				'gross_minor' => $gross,
			);
		}
		$voucher_minor = 0;
		foreach ( (array) ( $snapshot['payments']['vouchers'] ?? array() ) as $voucher ) {
			if ( \PassCaptain\Integrations\Vouchers\Checkout::PURPOSE_MULTI === ( $voucher['purpose'] ?? '' ) ) { $voucher_minor += (int) ( $voucher['amount_minor'] ?? 0 ); }
		}
		$gross = (int) $invoice->total_minor;
		$vat = (int) $invoice->tax_minor;
		return array(
			'version' => self::VERSION,
			'id' => (int) $invoice->invoice_id,
			'type' => 'invoice',
			'number' => (string) $invoice->invoice_number,
			'date' => self::local_date( $invoice->issued_at ),
			'issued_at' => self::iso( $invoice->issued_at ),
			'status' => (string) $invoice->status,
			'currency' => $currency,
			'currency_decimals' => Localization::currency_decimals( $currency ),
			'sales_model' => (string) ( $snapshot['sales_model'] ?? '' ),
			'seller' => self::seller_party( (array) ( $snapshot['seller'] ?? \PassCaptain\Invoices::seller() ) ),
			'buyer' => self::buyer_party( $snapshot ),
			'agent' => empty( $snapshot['agent'] ) ? null : self::seller_party( (array) $snapshot['agent'] ),
			'lines' => $lines,
			'vat_breakdown' => self::breakdown( $lines ),
			'totals' => array( 'net_minor' => $gross - $vat, 'vat_minor' => $vat, 'gross_minor' => $gross, 'discount_minor' => (int) ( $snapshot['discount_minor'] ?? 0 ), 'voucher_payments_minor' => $voucher_minor, 'paid_minor' => $gross, 'due_minor' => 0 ),
			'references' => array( 'order_id' => (int) $invoice->order_id, 'order_public_id' => (string) $invoice->order_public_id, 'buyer_reference' => (string) ( $snapshot['po_number'] ?? '' ), 'terms_version' => (string) ( $snapshot['terms_version'] ?? '' ) ),
			'notes' => array_values( array_map( 'strval', \PassCaptain\Invoices::seller_notes( $snapshot ) ) ),
			'corrections' => array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT credit_note_id FROM %i WHERE invoice_id=%d ORDER BY credit_note_id', $wpdb->prefix . 'wpcap_credit_notes', $id ) ) ),
		);
	}

	private static function correction( $id ) {
		global $wpdb;
		$credit = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE credit_note_id=%d', $wpdb->prefix . 'wpcap_credit_notes', $id ) );
		if ( ! $credit ) { return null; }
		$invoice = self::invoice( (int) $credit->invoice_id );
		if ( ! $invoice ) { return null; }
		$refund = $wpdb->get_row( $wpdb->prepare( 'SELECT refund_id,ticket_ids,reason FROM %i WHERE refund_id=%d', $wpdb->prefix . 'wpcap_refunds', (int) $credit->refund_id ) );
		$gross = (int) $credit->total_minor;
		$vat = (int) $credit->tax_minor;
		// Per VAT rate: weights = value of the refunded tickets (order lines), else the corrected invoice lines.
		$weights = array();
		$ticket_ids = $refund ? array_filter( array_map( 'absint', (array) json_decode( (string) $refund->ticket_ids, true ) ) ) : array();
		if ( $ticket_ids ) {
			$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT oi.tax_rate_bp,oi.line_total_minor,oi.quantity FROM %i t INNER JOIN %i oi ON oi.order_id=t.order_id AND oi.ticket_type_id=t.ticket_type_id WHERE t.order_id=%d AND t.ticket_id IN (' . implode( ',', array_fill( 0, count( array_map( 'intval', $ticket_ids ) ), '%d' ) ) . ')', $wpdb->prefix . 'wpcap_tickets', $wpdb->prefix . 'wpcap_order_items', (int) $invoice['references']['order_id'], ...array_map( 'intval', $ticket_ids ) ) );
			foreach ( $rows as $row ) {
				$key = (string) ( (int) $row->tax_rate_bp / 100 );
				$weights[ $key ] = ( $weights[ $key ] ?? 0 ) + intdiv( (int) $row->line_total_minor, max( 1, (int) $row->quantity ) );
			}
		}
		if ( ! $weights ) {
			foreach ( $invoice['lines'] as $line ) {
				$key = (string) $line['vat_rate'];
				$weights[ $key ] = ( $weights[ $key ] ?? 0 ) + (int) $line['gross_minor'];
			}
		}
		$weights = $weights ? $weights : array( '0' => 1 );
		$gross_parts = self::allocate( $weights, $gross );
		$vat_parts = self::allocate( $weights, $vat );
		$lines = array();
		$position = 0;
		foreach ( $weights as $rate => $weight ) {
			$lines[] = array(
				'position' => ++$position,
				/* translators: %s: invoice number */
				'description' => sprintf( __( 'Korrektur zu Rechnung %s', 'pass-captain' ), $invoice['number'] ),
				'quantity' => 1,
				'unit_gross_minor' => -$gross_parts[ $rate ],
				'discount_minor' => 0,
				'net_minor' => -( $gross_parts[ $rate ] - $vat_parts[ $rate ] ),
				'vat_rate' => (float) $rate,
				'vat_minor' => -$vat_parts[ $rate ],
				'gross_minor' => -$gross_parts[ $rate ],
			);
		}
		return array(
			'version' => self::VERSION,
			'id' => (int) $credit->credit_note_id,
			'type' => 'correction',
			'number' => (string) $credit->credit_note_number,
			'date' => self::local_date( $credit->issued_at ),
			'issued_at' => self::iso( $credit->issued_at ),
			'status' => 'issued',
			'currency' => (string) $credit->currency,
			'currency_decimals' => Localization::currency_decimals( (string) $credit->currency ),
			'sales_model' => $invoice['sales_model'],
			'seller' => $invoice['seller'],
			'buyer' => $invoice['buyer'],
			'agent' => $invoice['agent'],
			'lines' => $lines,
			'vat_breakdown' => self::breakdown( $lines ),
			'totals' => array( 'net_minor' => -( $gross - $vat ), 'vat_minor' => -$vat, 'gross_minor' => -$gross, 'discount_minor' => 0, 'voucher_payments_minor' => 0, 'paid_minor' => 0, 'due_minor' => 0 ),
			'references' => array( 'order_id' => $invoice['references']['order_id'], 'order_public_id' => $invoice['references']['order_public_id'], 'buyer_reference' => $invoice['references']['buyer_reference'], 'invoice_id' => $invoice['id'], 'invoice_number' => $invoice['number'], 'invoice_date' => $invoice['date'], 'refund_id' => (int) $credit->refund_id, 'reason' => $refund ? (string) $refund->reason : '' ),
			'notes' => $invoice['notes'],
			'lines_allocated' => count( $lines ) > 1,
		);
	}

	private static function settlement( $id ) {
		$s = Settlements::get( $id );
		if ( ! $s || 'draft' === $s->status ) { return null; }
		$snapshot = (array) json_decode( (string) $s->partner_snapshot, true );
		$totals = Settlements::decoded_totals( $s );
		$terms = (array) ( $totals['terms'] ?? array() );
		$currency = (string) $s->currency;
		$reverse_charge = ! empty( $totals['fee_reverse_charge'] );
		$rate = $reverse_charge ? 0.0 : (float) ( (int) ( $terms['fee_tax_rate_bp'] ?? 0 ) / 100 );
		$seller = (array) ( $snapshot['seller'] ?? SalesModel::platform_seller() );
		$supplier = (array) ( $snapshot['supplier'] ?? array( 'name' => (string) ( $snapshot['partner']['name'] ?? '' ), 'address' => (string) ( $snapshot['partner']['billing_address'] ?? '' ), 'vat_id' => (string) ( $snapshot['partner']['vat_id'] ?? '' ) ) );
		$fee_net = array( 'commission' => (int) $s->commission_minor, 'fee' => (int) $s->fee_minor );
		$fee_vat = self::allocate( array_map( 'abs', $fee_net ), (int) $s->platform_fee_tax_minor );
		$labels = array(
			/* translators: %s: commission percent */
			'commission' => sprintf( __( 'Provision %s vom Nettoumsatz', 'pass-captain' ), number_format_i18n( (int) ( $terms['commission_bp'] ?? 0 ) / 100, 2 ) . ' %' ),
			'fee' => __( 'Ticketgebühren', 'pass-captain' ),
		);
		$lines = array();
		$position = 0;
		foreach ( $fee_net as $key => $net ) {
			if ( 0 === $net ) { continue; }
			$vat = $fee_vat[ $key ] * ( $net < 0 ? -1 : 1 );
			$lines[] = array( 'position' => ++$position, 'description' => $labels[ $key ], 'quantity' => 1, 'unit_gross_minor' => $net + $vat, 'discount_minor' => 0, 'net_minor' => $net, 'vat_rate' => $rate, 'vat_minor' => $vat, 'gross_minor' => $net + $vat );
		}
		$self_billing = null;
		if ( ! empty( $s->self_billing_number ) && is_array( $totals['self_billing'] ?? null ) ) {
			$sb = $totals['self_billing'];
			$sb_rates = array();
			foreach ( (array) ( $sb['rates'] ?? array() ) as $bp => $r ) {
				$sb_rates[] = array( 'vat_rate' => (float) ( (int) $bp / 100 ), 'net_minor' => (int) $r['net'], 'vat_minor' => (int) $r['tax'], 'gross_minor' => (int) $r['gross'] );
			}
			$self_billing = array(
				'number' => (string) $s->self_billing_number,
				'seller' => self::seller_party( $supplier ),
				'buyer' => self::seller_party( $seller ),
				'vat_breakdown' => $sb_rates,
				'totals' => array( 'net_minor' => (int) ( $sb['net'] ?? 0 ), 'vat_minor' => (int) ( $sb['tax'] ?? 0 ), 'gross_minor' => (int) ( $sb['gross'] ?? 0 ) ),
				'reverse_charge' => ! empty( $sb['reverse_charge'] ),
				'small_business' => ! empty( $sb['small_business'] ),
			);
		}
		return array(
			'version' => self::VERSION,
			'id' => (int) $s->settlement_id,
			'type' => 'settlement',
			'number' => (string) $s->document_number,
			'date' => self::local_date( $s->finalized_at ? $s->finalized_at : $s->updated_at ),
			'issued_at' => self::iso( $s->finalized_at ? $s->finalized_at : $s->updated_at ),
			'status' => (string) $s->status,
			'currency' => $currency,
			'currency_decimals' => Localization::currency_decimals( $currency ),
			'sales_model' => (string) ( $snapshot['sales_model'] ?? ( $totals['sales_model'] ?? '' ) ),
			'seller' => self::seller_party( $seller ),
			'buyer' => self::seller_party( $supplier ),
			'agent' => null,
			'lines' => $lines,
			'vat_breakdown' => self::breakdown( $lines ),
			'totals' => array( 'net_minor' => (int) $s->platform_fee_net_minor, 'vat_minor' => (int) $s->platform_fee_tax_minor, 'gross_minor' => (int) $s->platform_fee_gross_minor, 'discount_minor' => 0, 'voucher_payments_minor' => 0, 'paid_minor' => 'paid' === $s->status ? (int) $s->platform_fee_gross_minor : 0, 'due_minor' => 0 ),
			'references' => array( 'settlement_id' => (int) $s->settlement_id, 'partner_id' => (int) $s->partner_id, 'period' => (string) $s->period, 'payment_reference' => (string) ( $s->payment_reference ?? '' ) ),
			'notes' => $reverse_charge ? array( SalesModel::reverse_charge_note() ) : array(),
			'settlement' => array(
				'document_type' => Settlements::document_type( $s ),
				'reverse_charge' => $reverse_charge,
				'ticket_revenue_gross_minor' => (int) $s->gross_minor,
				'ticket_revenue_vat_minor' => (int) $s->tax_minor,
				'payout_minor' => (int) $s->payout_minor,
				'self_billing' => $self_billing,
			),
		);
	}

	/* ------------------------------------------------------------------ Helpers */

	/** Seller-like party (free-text address split into lines). */
	private static function seller_party( array $seller ) {
		$lines = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) ( $seller['address'] ?? '' ) ) as $line ) {
			if ( '' !== trim( $line ) ) { $lines[] = trim( $line ); }
		}
		return array(
			'name' => (string) ( $seller['name'] ?? '' ),
			'company' => '',
			'address_lines' => $lines,
			'street' => '',
			'postcode' => '',
			'city' => '',
			'country' => (string) ( $seller['country'] ?? '' ),
			'vat_id' => (string) ( $seller['vat_id'] ?? '' ),
			'tax_number' => (string) ( $seller['tax_number'] ?? '' ),
			'email' => '',
			'small_business' => ! empty( $seller['small_business'] ),
		);
	}

	/** Buyer from the invoice snapshot (structured billing data when given, else only the e-mail). */
	private static function buyer_party( array $snapshot ) {
		$name = trim( (string) ( $snapshot['name'] ?? trim( ( $snapshot['first_name'] ?? '' ) . ' ' . ( $snapshot['last_name'] ?? '' ) ) ) );
		return array(
			'name' => $name,
			'company' => (string) ( $snapshot['company'] ?? '' ),
			'address_lines' => Billing::address_lines( $snapshot ),
			'street' => (string) ( $snapshot['street'] ?? '' ),
			'postcode' => (string) ( $snapshot['postcode'] ?? '' ),
			'city' => (string) ( $snapshot['city'] ?? '' ),
			'country' => (string) ( $snapshot['country'] ?? '' ),
			'vat_id' => (string) ( $snapshot['vat_id'] ?? '' ),
			'tax_number' => '',
			'email' => (string) ( $snapshot['email'] ?? '' ),
			'small_business' => false,
		);
	}

	/** Net/VAT/gross per VAT rate of document lines (ascending rate). */
	private static function breakdown( array $lines ) {
		$rates = array();
		foreach ( $lines as $line ) {
			$key = (string) $line['vat_rate'];
			$rates[ $key ] = $rates[ $key ] ?? array( 'vat_rate' => (float) $line['vat_rate'], 'net_minor' => 0, 'vat_minor' => 0, 'gross_minor' => 0 );
			$rates[ $key ]['net_minor'] += (int) $line['net_minor'];
			$rates[ $key ]['vat_minor'] += (int) $line['vat_minor'];
			$rates[ $key ]['gross_minor'] += (int) $line['gross_minor'];
		}
		ksort( $rates, SORT_NUMERIC );
		return array_values( $rates );
	}

	/**
	 * Splits $total proportionally to $weights (largest remainder; parts sum up to $total exactly).
	 *
	 * @param array<string|int,int> $weights Non-negative weights.
	 * @return array<string|int,int>
	 */
	private static function allocate( array $weights, $total ) {
		$total = (int) $total;
		$sum = array_sum( array_map( 'intval', $weights ) );
		$parts = array_fill_keys( array_keys( $weights ), 0 );
		if ( ! $weights ) { return $parts; }
		if ( $sum <= 0 ) {
			$parts[ array_key_first( $parts ) ] = $total;
			return $parts;
		}
		$remainders = array();
		$assigned = 0;
		foreach ( $weights as $key => $weight ) {
			$exact = $total * (int) $weight / $sum;
			$parts[ $key ] = (int) floor( $exact );
			$remainders[ $key ] = $exact - $parts[ $key ];
			$assigned += $parts[ $key ];
		}
		arsort( $remainders );
		foreach ( array_keys( $remainders ) as $key ) {
			if ( $assigned >= $total ) { break; }
			++$parts[ $key ];
			++$assigned;
		}
		return $parts;
	}

	private static function local_date( $utc ) {
		return '' !== (string) $utc ? wp_date( 'Y-m-d', strtotime( $utc . ' UTC' ), Format::site_timezone() ) : '';
	}

	private static function iso( $utc ) {
		return '' !== (string) $utc ? gmdate( 'c', strtotime( $utc . ' UTC' ) ) : '';
	}
}

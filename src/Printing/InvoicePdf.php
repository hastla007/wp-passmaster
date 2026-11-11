<?php
/**
 * Invoice and credit note PDFs built from the Invoices tables (same content as Invoices::render_html).
 * Admin download (admin-post wpcap_invoice_pdf), buyer download via the order access token
 * (?wpcap_invoice_pdf=<public_id>&wpcap_access=<token>) and optional ticket mail attachment.
 */
namespace PassCaptain\Printing;

use PassCaptain\Access;
use PassCaptain\Invoices;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class InvoicePdf {
	/** Same rule as Invoices::can_view_order_finance(): finance roles globally, order managers per event. */
	public static function can_view_order_finance( $order_id ) {
		if ( current_user_can( 'manage_options' ) || current_user_can( Invoices::FINANCE_CAP ) ) { return true; }
		global $wpdb;
		$event_ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT o.event_id FROM {$wpdb->prefix}wpcap_order_items oi INNER JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE oi.order_id=%d", absint( $order_id ) ) );
		if ( ! $event_ids ) { return false; }
		foreach ( $event_ids as $event_id ) {
			if ( ! Access::can_access_event( (int) $event_id, 'wpcap_manage_orders' ) ) { return false; }
		}
		return true;
	}

	private static function money( $minor, $currency ) {
		return number_format_i18n( (int) $minor / 100, 2 ) . ' ' . $currency;
	}

	private static function date( $utc ) {
		return wp_date( 'd.m.Y', strtotime( $utc . ' UTC' ) );
	}

	/** Header block with seller data; returns the y position below it. */
	private static function header( PdfWriter $pdf, array $seller ) {
		$y = 790;
		$pdf->text( 50, $y, 12, (string) ( $seller['name'] ?? '' ), true );
		foreach ( preg_split( '/\r\n|\r|\n/', (string) ( $seller['address'] ?? '' ) ) as $line ) {
			if ( '' === trim( $line ) ) { continue; }
			$y -= 13;
			$pdf->text( 50, $y, 9, trim( $line ) );
		}
		if ( ! empty( $seller['vat_id'] ) ) {
			$y -= 13;
			$pdf->text( 50, $y, 9, __( 'USt-IdNr.:', 'wp-pass-captain' ) . ' ' . $seller['vat_id'] );
		}
		return $y - 36;
	}

	/**
	 * PDF for an invoice incl. its credit notes (each on its own page), or a single credit note.
	 *
	 * @return string PDF bytes or '' when not found.
	 */
	public static function render( $invoice_id, $credit_note_id = 0 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$invoice = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_invoices WHERE invoice_id=%d", absint( $invoice_id ) ) );
		if ( ! $invoice ) { return ''; }
		$lines = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}wpcap_invoice_lines WHERE invoice_id=%d ORDER BY invoice_line_id", $invoice->invoice_id ) );
		$credits = $credit_note_id
			? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}wpcap_credit_notes WHERE invoice_id=%d AND credit_note_id=%d", $invoice->invoice_id, absint( $credit_note_id ) ) )
			: $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}wpcap_credit_notes WHERE invoice_id=%d ORDER BY credit_note_id", $invoice->invoice_id ) );
		if ( $credit_note_id && ! $credits ) { return ''; }
		$snapshot = (array) json_decode( (string) $invoice->buyer_snapshot, true );
		$seller = (array) ( $snapshot['seller'] ?? array( 'name' => Settings::get( 'seller_name' ), 'address' => Settings::get( 'seller_address' ), 'vat_id' => Settings::get( 'seller_vat_id' ) ) );
		$cur = $invoice->currency;
		$pdf = new PdfWriter();
		$footer = static function ( PdfWriter $pdf, $page_label ) {
			$pdf->line( 50, 50, 545, 50 );
			$pdf->text( 545, 38, 8, $page_label, false, 'right' );
		};

		if ( ! $credit_note_id ) {
			$pdf->add_page( 595.28, 841.89 );
			$y = self::header( $pdf, $seller );
			$pdf->text( 50, $y, 18, __( 'Rechnung', 'wp-pass-captain' ) . ' ' . $invoice->invoice_number, true );
			$y -= 22;
			$pdf->text( 50, $y, 10, __( 'Rechnungsdatum:', 'wp-pass-captain' ) . ' ' . self::date( $invoice->issued_at ) );
			$y -= 14;
			$pdf->text( 50, $y, 10, __( 'Rechnungsempfänger:', 'wp-pass-captain' ) . ' ' . ( $snapshot['email'] ?? '' ) );
			$y -= 28;
			$cols = array( array( 50, 'left', __( 'Leistung', 'wp-pass-captain' ) ), array( 330, 'right', __( 'Menge', 'wp-pass-captain' ) ), array( 400, 'right', __( 'Einzelpreis', 'wp-pass-captain' ) ), array( 455, 'right', __( 'Rabatt', 'wp-pass-captain' ) ), array( 490, 'right', __( 'USt.', 'wp-pass-captain' ) ), array( 545, 'right', __( 'Gesamt', 'wp-pass-captain' ) ) );
			$table_header = static function ( $y ) use ( $pdf, $cols ) {
				foreach ( $cols as $c ) { $pdf->text( $c[0], $y, 9, $c[2], true, $c[1] ); }
				$pdf->line( 50, $y - 5, 545, $y - 5 );
				return $y - 18;
			};
			$y = $table_header( $y );
			$rates = array();
			$page = 1;
			foreach ( $lines as $line ) {
				$desc = PdfWriter::wrap( $line->description, 9, 250 );
				if ( $y - 12 * count( $desc ) < 90 ) {
					$footer( $pdf, sprintf( __( 'Seite %d', 'wp-pass-captain' ), $page++ ) );
					$pdf->add_page( 595.28, 841.89 );
					$y = $table_header( 790 );
				}
				$pdf->text( 330, $y, 9, (string) (int) $line->quantity, false, 'right' );
				$pdf->text( 400, $y, 9, self::money( $line->unit_minor, $cur ), false, 'right' );
				$pdf->text( 455, $y, 9, self::money( $line->discount_minor, $cur ), false, 'right' );
				$pdf->text( 490, $y, 9, number_format_i18n( (float) $line->tax_rate, 2 ) . ' %', false, 'right' );
				$pdf->text( 545, $y, 9, self::money( $line->total_minor, $cur ), false, 'right' );
				foreach ( $desc as $d ) { $pdf->text( 50, $y, 9, $d ); $y -= 12; }
				$y -= 4;
				$key = (string) (float) $line->tax_rate;
				$rates[ $key ] = ( $rates[ $key ] ?? 0 ) + (int) $line->tax_minor;
			}
			if ( $y < 160 ) {
				$footer( $pdf, sprintf( __( 'Seite %d', 'wp-pass-captain' ), $page++ ) );
				$pdf->add_page( 595.28, 841.89 );
				$y = 790;
			}
			$pdf->line( 300, $y + 6, 545, $y + 6 );
			$y -= 10;
			$pdf->text( 300, $y, 10, __( 'Nettobetrag', 'wp-pass-captain' ) );
			$pdf->text( 545, $y, 10, self::money( (int) $invoice->total_minor - (int) $invoice->tax_minor, $cur ), false, 'right' );
			foreach ( $rates as $rate => $tax ) {
				$y -= 14;
				$pdf->text( 300, $y, 10, sprintf( __( 'Umsatzsteuer %s %%', 'wp-pass-captain' ), number_format_i18n( (float) $rate, 2 ) ) );
				$pdf->text( 545, $y, 10, self::money( $tax, $cur ), false, 'right' );
			}
			$y -= 18;
			$pdf->text( 300, $y, 11, __( 'Rechnungsbetrag', 'wp-pass-captain' ), true );
			$pdf->text( 545, $y, 11, self::money( $invoice->total_minor, $cur ), true, 'right' );
			$footer( $pdf, sprintf( __( 'Seite %d', 'wp-pass-captain' ), $page ) );
		}

		foreach ( $credits as $credit ) {
			$pdf->add_page( 595.28, 841.89 );
			$y = self::header( $pdf, $seller );
			$pdf->text( 50, $y, 18, __( 'Gutschrift', 'wp-pass-captain' ) . ' ' . $credit->credit_note_number, true );
			$y -= 22;
			$pdf->text( 50, $y, 10, sprintf( __( 'Korrektur zu Rechnung %1$s vom %2$s', 'wp-pass-captain' ), $invoice->invoice_number, self::date( $invoice->issued_at ) ) );
			$y -= 14;
			$pdf->text( 50, $y, 10, __( 'Datum:', 'wp-pass-captain' ) . ' ' . self::date( $credit->issued_at ) . ' · ' . __( 'Empfänger:', 'wp-pass-captain' ) . ' ' . ( $snapshot['email'] ?? '' ) );
			$y -= 36;
			$rows = array(
				array( __( 'Nettobetrag', 'wp-pass-captain' ), '-' . self::money( $credit->net_minor, $credit->currency ), false ),
				array( __( 'Umsatzsteuer', 'wp-pass-captain' ), '-' . self::money( $credit->tax_minor, $credit->currency ), false ),
				array( __( 'Gutschriftsbetrag', 'wp-pass-captain' ), '-' . self::money( $credit->total_minor, $credit->currency ), true ),
			);
			foreach ( $rows as $r ) {
				$pdf->text( 300, $y, 10, $r[0], $r[2] );
				$pdf->text( 545, $y, 10, $r[1], $r[2], 'right' );
				$y -= 16;
			}
			$footer( $pdf, $credit->credit_note_number );
		}
		return $pdf->output( $credit_note_id ? $credits[0]->credit_note_number : $invoice->invoice_number );
	}

	/** Invoice id of an order (issuing it when the order is paid but has none yet, like the core print view). */
	public static function invoice_for_order( $order_id, $issue = true ) {
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT invoice_id FROM {$wpdb->prefix}wpcap_invoices WHERE order_id=%d", absint( $order_id ) ) );
		if ( $id || ! $issue ) { return $id; }
		$result = Invoices::issue_for_order( $order_id );
		return is_wp_error( $result ) ? $result : (int) $result['invoice_id'];
	}

	/** Admin download: admin-post.php?action=wpcap_invoice_pdf&order_id=…[&credit_note_id=…] */
	public static function handle_admin_download() {
		$order_id = absint( $_GET['order_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only download, capability and scope checked.
		$credit_note_id = absint( $_GET['credit_note_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$result = self::admin_pdf( $order_id, $credit_note_id );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), (int) ( $result->get_error_data()['status'] ?? 400 ) );
		}
		\PassCaptain\Reports\Scope::send_download( $result['pdf'], $result['filename'], 'application/pdf' );
	}

	/** @return array{pdf:string,filename:string}|\WP_Error */
	public static function admin_pdf( $order_id, $credit_note_id = 0 ) {
		if ( ! $order_id || ! self::can_view_order_finance( $order_id ) ) {
			return new \WP_Error( 'wpcap_forbidden', __( 'Keine Berechtigung.', 'wp-pass-captain' ), array( 'status' => 403 ) );
		}
		$invoice_id = self::invoice_for_order( $order_id, ! $credit_note_id );
		if ( is_wp_error( $invoice_id ) ) { return new \WP_Error( $invoice_id->get_error_code(), $invoice_id->get_error_message(), array( 'status' => 409 ) ); }
		$pdf = $invoice_id ? self::render( $invoice_id, $credit_note_id ) : '';
		if ( '' === $pdf ) { return new \WP_Error( 'wpcap_invoice_missing', __( 'Beleg nicht gefunden.', 'wp-pass-captain' ), array( 'status' => 404 ) ); }
		return array( 'pdf' => $pdf, 'filename' => self::filename( $invoice_id, $credit_note_id ) );
	}

	private static function filename( $invoice_id, $credit_note_id ) {
		global $wpdb;
		$number = $credit_note_id
			? $wpdb->get_var( $wpdb->prepare( "SELECT credit_note_number FROM {$wpdb->prefix}wpcap_credit_notes WHERE credit_note_id=%d", $credit_note_id ) )
			: $wpdb->get_var( $wpdb->prepare( "SELECT invoice_number FROM {$wpdb->prefix}wpcap_invoices WHERE invoice_id=%d", $invoice_id ) );
		return ( $credit_note_id ? 'gutschrift-' : 'rechnung-' ) . preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $number ) . '.pdf';
	}

	/**
	 * Buyer download with the personal order link (same check as Invoices::maybe_render_customer_invoice).
	 *
	 * @return array{pdf:string,filename:string}|\WP_Error
	 */
	public static function customer_pdf( $public_id, $token ) {
		global $wpdb;
		$public_id = sanitize_text_field( (string) $public_id );
		$token = strtolower( preg_replace( '/[^a-f0-9]/', '', (string) $token ) );
		$denied = new \WP_Error( 'wpcap_not_found', __( 'Nicht gefunden.', 'wp-pass-captain' ), array( 'status' => 404 ) );
		if ( '' === $public_id || 64 !== strlen( $token ) ) { return $denied; }
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_orders WHERE public_id=%s", $public_id ) );
		if ( ! $order || ! $order->access_expires_at || $order->access_expires_at <= current_time( 'mysql', true ) || ! hash_equals( (string) $order->access_token_hash, hash( 'sha256', $token ) ) ) { return $denied; }
		$invoice_id = self::invoice_for_order( $order->order_id, false );
		if ( ! $invoice_id ) { return $denied; }
		return array( 'pdf' => self::render( $invoice_id ), 'filename' => self::filename( $invoice_id, 0 ) );
	}

	/** template_redirect: ?wpcap_invoice_pdf=<public_id>&wpcap_access=<token> */
	public static function maybe_customer_download() {
		if ( ! isset( $_GET['wpcap_invoice_pdf'] ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$result = self::customer_pdf( wp_unslash( $_GET['wpcap_invoice_pdf'] ), wp_unslash( $_GET['wpcap_access'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput
		if ( is_wp_error( $result ) ) { status_header( 404 ); exit; }
		if ( ! headers_sent() ) { header( 'Referrer-Policy: no-referrer' ); }
		\PassCaptain\Reports\Scope::send_download( $result['pdf'], $result['filename'], 'application/pdf' );
	}

	/** wpcap_ticket_mail filter: attaches the invoice PDF when enabled and an invoice exists (or auto_invoice is on). */
	public static function attach_to_ticket_mail( $mail, $order = null, $issued = array() ) {
		if ( ! is_array( $mail ) || ! $order || ! Settings::get( 'print_attach_invoice_pdf' ) ) { return $mail; }
		$invoice_id = self::invoice_for_order( (int) $order->order_id, (bool) Settings::get( 'auto_invoice' ) );
		if ( ! $invoice_id || is_wp_error( $invoice_id ) ) { return $mail; }
		$file = trailingslashit( get_temp_dir() ) . self::filename( $invoice_id, 0 );
		if ( false !== file_put_contents( $file, self::render( $invoice_id ) ) ) {
			$mail['attachments'] = array_merge( (array) ( $mail['attachments'] ?? array() ), array( $file ) );
		}
		return $mail;
	}
}

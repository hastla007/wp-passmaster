<?php
/**
 * Invoice and credit note PDFs built from the Invoices tables (same content as Invoices::render_html).
 * Admin download (admin-post wpcap_invoice_pdf), buyer download via the order access token
 * (?wpcap_invoice_pdf=<public_id>&wpcap_access=<token>) and optional ticket mail attachment.
 */
namespace PassCaptain\Printing;

use PassCaptain\Access;
use PassCaptain\Billing;
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
		// Seller = platform (own_name) or organizer (agent model), see Invoices::seller_lines().
		$lines = Invoices::seller_lines( $seller );
		$pdf->text( 50, $y, 12, (string) array_shift( $lines ), true );
		foreach ( $lines as $line ) {
			$y -= 13;
			$pdf->text( 50, $y, 9, $line );
		}
		return $y - 36;
	}

	/** Seller-side notes (agent "Vermittelt durch …", small business); returns the y position below them. */
	private static function seller_notes( PdfWriter $pdf, $y, array $snapshot ) {
		foreach ( Invoices::seller_notes( $snapshot ) as $note ) {
			foreach ( PdfWriter::wrap( $note, 9, 495 ) as $part ) {
				$pdf->text( 50, max( 60, $y ), 9, $part );
				$y -= 12;
			}
		}
		return $y;
	}

	/**
	 * Recipient block from the buyer snapshot (company, name, address, VAT ID, buyer reference,
	 * optionally the e-mail address); returns the y position of the last line.
	 */
	private static function recipient( PdfWriter $pdf, $y, array $snapshot, $with_email ) {
		$pdf->text( 50, $y, 10, __( 'Rechnungsempfänger:', 'wp-pass-captain' ), true );
		$lines = array_merge( Billing::address_lines( $snapshot ), Billing::detail_lines( $snapshot ) );
		if ( $with_email && '' !== (string) ( $snapshot['email'] ?? '' ) ) { $lines[] = (string) $snapshot['email']; }
		foreach ( $lines as $line ) {
			foreach ( PdfWriter::wrap( $line, 10, 300 ) as $part ) {
				$y -= 13;
				$pdf->text( 50, $y, 10, $part );
			}
		}
		return $y;
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
		$seller = (array) ( $snapshot['seller'] ?? Invoices::seller() );
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
			if ( Billing::address_lines( $snapshot ) ) {
				$y = self::recipient( $pdf, $y - 6, $snapshot, true );
			} else {
				$pdf->text( 50, $y, 10, __( 'Rechnungsempfänger:', 'wp-pass-captain' ) . ' ' . ( $snapshot['email'] ?? '' ) );
			}
			$y -= 28;
			$cols = array( array( 50, 'left', __( 'Leistung', 'wp-pass-captain' ) ), array( 330, 'right', __( 'Menge', 'wp-pass-captain' ) ), array( 400, 'right', __( 'Einzelpreis', 'wp-pass-captain' ) ), array( 455, 'right', __( 'Rabatt', 'wp-pass-captain' ) ), array( 490, 'right', __( 'USt.', 'wp-pass-captain' ) ), array( 545, 'right', __( 'Gesamt', 'wp-pass-captain' ) ) );
			$table_header = static function ( $y ) use ( $pdf, $cols ) {
				foreach ( $cols as $c ) { $pdf->text( $c[0], $y, 9, $c[2], true, $c[1] ); }
				$pdf->line( 50, $y - 5, 545, $y - 5 );
				return $y - 18;
			};
			$y = $table_header( $y );
			$rates = Invoices::rate_summary( $lines );
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
			}
			// Totals block: VAT breakdown (net/tax/gross per rate) plus the sums.
			if ( $y < 130 + 28 * count( $rates ) ) {
				$footer( $pdf, sprintf( __( 'Seite %d', 'wp-pass-captain' ), $page++ ) );
				$pdf->add_page( 595.28, 841.89 );
				$y = 790;
			}
			$pdf->line( 50, $y + 6, 545, $y + 6 );
			$y -= 10;
			$pdf->text( 50, $y, 9, __( 'Aufstellung nach Steuersätzen', 'wp-pass-captain' ), true );
			$pdf->text( 330, $y, 9, __( 'Netto', 'wp-pass-captain' ), true, 'right' );
			$pdf->text( 440, $y, 9, __( 'USt.', 'wp-pass-captain' ), true, 'right' );
			$pdf->text( 545, $y, 9, __( 'Brutto', 'wp-pass-captain' ), true, 'right' );
			foreach ( $rates as $rate => $sum ) {
				$y -= 13;
				$pdf->text( 50, $y, 9, __( 'USt-Satz', 'wp-pass-captain' ) . ' ' . number_format_i18n( (float) $rate, 2 ) . ' %' );
				$pdf->text( 330, $y, 9, self::money( $sum['net'], $cur ), false, 'right' );
				$pdf->text( 440, $y, 9, self::money( $sum['tax'], $cur ), false, 'right' );
				$pdf->text( 545, $y, 9, self::money( $sum['gross'], $cur ), false, 'right' );
			}
			$y -= 22;
			$pdf->line( 300, $y + 10, 545, $y + 10 );
			$pdf->text( 300, $y, 10, __( 'Nettobetrag', 'wp-pass-captain' ) );
			$pdf->text( 545, $y, 10, self::money( (int) $invoice->total_minor - (int) $invoice->tax_minor, $cur ), false, 'right' );
			foreach ( $rates as $rate => $sum ) {
				$y -= 14;
				$pdf->text( 300, $y, 10, sprintf( __( 'Umsatzsteuer %s %%', 'wp-pass-captain' ), number_format_i18n( (float) $rate, 2 ) ) );
				$pdf->text( 545, $y, 10, self::money( $sum['tax'], $cur ), false, 'right' );
			}
			$y -= 18;
			$pdf->text( 300, $y, 11, __( 'Rechnungsbetrag', 'wp-pass-captain' ), true );
			$pdf->text( 545, $y, 11, self::money( $invoice->total_minor, $cur ), true, 'right' );
			$y = self::payments( $pdf, $y, $invoice, $snapshot, $page, $footer );
			if ( Invoices::seller_notes( $snapshot ) && $y - 30 - 24 * count( Invoices::seller_notes( $snapshot ) ) < 60 ) {
				$footer( $pdf, sprintf( __( 'Seite %d', 'wp-pass-captain' ), $page++ ) );
				$pdf->add_page( 595.28, 841.89 );
				$y = 820;
			}
			$y = self::seller_notes( $pdf, $y - 30, $snapshot );
			$footer( $pdf, sprintf( __( 'Seite %d', 'wp-pass-captain' ), $page ) );
		}

		foreach ( $credits as $credit ) {
			$pdf->add_page( 595.28, 841.89 );
			$y = self::header( $pdf, $seller );
			$pdf->text( 50, $y, 18, __( 'Rechnungskorrektur', 'wp-pass-captain' ) . ' ' . $credit->credit_note_number, true );
			$y -= 22;
			$pdf->text( 50, $y, 10, sprintf( __( 'Korrektur zu Rechnung %1$s vom %2$s', 'wp-pass-captain' ), $invoice->invoice_number, self::date( $invoice->issued_at ) ) );
			$y -= 14;
			$pdf->text( 50, $y, 10, __( 'Datum:', 'wp-pass-captain' ) . ' ' . self::date( $credit->issued_at ) . ' · ' . __( 'Empfänger:', 'wp-pass-captain' ) . ' ' . ( $snapshot['email'] ?? '' ) );
			if ( Billing::address_lines( $snapshot ) ) { $y = self::recipient( $pdf, $y - 20, $snapshot, false ); }
			$y -= 36;
			// VAT breakdown per rate (net/tax/gross), like the invoice.
			$credit_rates = Invoices::credit_rates( $credit );
			$pdf->line( 50, $y + 6, 545, $y + 6 );
			$y -= 10;
			$pdf->text( 50, $y, 9, __( 'Aufstellung nach Steuersätzen', 'wp-pass-captain' ), true );
			$pdf->text( 330, $y, 9, __( 'Netto', 'wp-pass-captain' ), true, 'right' );
			$pdf->text( 440, $y, 9, __( 'USt.', 'wp-pass-captain' ), true, 'right' );
			$pdf->text( 545, $y, 9, __( 'Brutto', 'wp-pass-captain' ), true, 'right' );
			foreach ( $credit_rates as $r ) {
				$y -= 13;
				$pdf->text( 50, $y, 9, __( 'USt-Satz', 'wp-pass-captain' ) . ' ' . Invoices::rate_label( $r['rate_bp'] ) );
				$pdf->text( 330, $y, 9, '-' . self::money( $r['net'], $credit->currency ), false, 'right' );
				$pdf->text( 440, $y, 9, '-' . self::money( $r['tax'], $credit->currency ), false, 'right' );
				$pdf->text( 545, $y, 9, '-' . self::money( $r['gross'], $credit->currency ), false, 'right' );
			}
			$y -= 30;
			$rows = array( array( __( 'Nettobetrag', 'wp-pass-captain' ), '-' . self::money( $credit->net_minor, $credit->currency ), false ) );
			foreach ( $credit_rates as $r ) {
				$rows[] = array( sprintf( __( 'Umsatzsteuer %s %%', 'wp-pass-captain' ), number_format_i18n( $r['rate_bp'] / 100, 2 ) ), '-' . self::money( $r['tax'], $credit->currency ), false );
			}
			$rows[] = array( __( 'Korrekturbetrag', 'wp-pass-captain' ), '-' . self::money( $credit->total_minor, $credit->currency ), true );
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
		if ( ! $order_id || ! self::can_view_order_finance( $order_id ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 ); }
		// Downloading an existing invoice is read-only; issuing a new invoice number is a state change
		// and needs a nonce (same as Invoices::handle_admin_print). Link: wp_nonce_url( …, 'wpcap_issue_invoice_' . $order_id ).
		if ( ! $credit_note_id && ! self::invoice_for_order( $order_id, false ) ) { check_admin_referer( 'wpcap_issue_invoice_' . $order_id ); }
		$result = self::admin_pdf( $order_id, $credit_note_id );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), (int) ( $result->get_error_data()['status'] ?? 400 ) );
		}
		\PassCaptain\Privacy\AccessLog::export( 'invoice_pdf', 'order', $order_id, array( 'credit_note_id' => $credit_note_id ) );
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
		return ( $credit_note_id ? 'rechnungskorrektur-' : 'rechnung-' ) . preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $number ) . '.pdf';
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
		// Unguessable private directory per mail (the attachment keeps its readable file name). The core
		// deletes the attachment after wp_mail(); the directory (and the file, should sending abort) is
		// removed at shutdown at the latest.
		$dir = trailingslashit( get_temp_dir() ) . 'wpcap-' . bin2hex( random_bytes( 16 ) );
		if ( ! @mkdir( $dir, 0700 ) ) { return $mail; } // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		$file = $dir . '/' . self::filename( $invoice_id, 0 );
		self::$temp_files[] = array( $file, $dir );
		if ( 1 === count( self::$temp_files ) ) { register_shutdown_function( array( __CLASS__, 'cleanup_temp_files' ) ); }
		if ( false !== file_put_contents( $file, self::render( $invoice_id ) ) ) {
			@chmod( $file, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$mail['attachments'] = array_merge( (array) ( $mail['attachments'] ?? array() ), array( $file ) );
		} else {
			self::cleanup_temp_files();
		}
		return $mail;
	}

	/** @var array<int,array{0:string,1:string}> Temporary attachment files and their directories. */
	private static $temp_files = array();

	/** Removes temporary invoice attachments and their directories (idempotent). */
	public static function cleanup_temp_files() {
		foreach ( self::$temp_files as $entry ) {
			list( $file, $dir ) = $entry;
			if ( is_file( $file ) ) { @unlink( $file ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
			if ( is_dir( $dir ) ) { @rmdir( $dir ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		}
		self::$temp_files = array();
	}



	/**
	 * Section "Zahlungen" (vouchers as payment, remaining amount paid, notes) below the invoice totals; see
	 * Invoices::payment_summary(). Breaks the page when needed and returns the y position below it.
	 */
	private static function payments( PdfWriter $pdf, $y, $invoice, array $snapshot, &$page, callable $footer ) {
		$summary = Invoices::payment_summary( $invoice, $snapshot );
		if ( ! $summary['rows'] && ! $summary['notes'] ) { return $y; }
		$notes = array();
		foreach ( $summary['notes'] as $note ) { $notes = array_merge( $notes, PdfWriter::wrap( $note, 9, 495 ) ); }
		if ( $y - 40 - 15 * count( $summary['rows'] ) - 12 * count( $notes ) < 70 ) {
			$footer( $pdf, sprintf( /* translators: %d: page number */ __( 'Seite %d', 'wp-pass-captain' ), $page++ ) );
			$pdf->add_page( 595.28, 841.89 );
			$y = 820;
		}
		$y -= 28;
		$pdf->text( 50, $y, 10, __( 'Zahlungen', 'wp-pass-captain' ), true );
		$pdf->line( 50, $y - 5, 545, $y - 5 );
		$y -= 18;
		foreach ( $summary['rows'] as $row ) {
			$pdf->text( 50, $y, 10, $row[0], $row[2] );
			$pdf->text( 545, $y, 10, ( $row[1] < 0 ? '-' : '' ) . self::money( abs( (int) $row[1] ), $invoice->currency ), $row[2], 'right' );
			$y -= 15;
		}
		foreach ( $notes as $part ) {
			$pdf->text( 50, $y, 9, $part );
			$y -= 12;
		}
		return $y;
	}
}

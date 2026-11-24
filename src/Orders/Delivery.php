<?php
/**
 * Building blocks of the ticket mail: placeholders, ticket PDF attachments, attachment budget, delivery status.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Orders;

use PassCaptain\Frontend;
use PassCaptain\LegalVersions;
use PassCaptain\Mail;
use PassCaptain\Orders;
use PassCaptain\Partners;
use PassCaptain\Sales;
use PassCaptain\TicketPdf;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Implementation behind Orders::discard_attachments(), Orders::addon_free_join(), Orders::missing_tickets(),
 * Orders::delivery_status(), Orders::ticket_mail_vars(), Orders::code_mail_section(),
 * Orders::ticket_pdf_chunk_size(), Orders::attachment_budget(), Orders::pdf_ticket_entry(),
 * Orders::ticket_pdf_attachments(), Orders::format_start(), Orders::site_name(). Moved out of Orders without
 * behaviour change (TODO "Gott-Klasse Orders").
 *
 * @internal Not part of the add-on API: only Orders calls it; use the Orders facade (same signatures).
 */
final class Delivery {
	/**
	 * Hands the attachment files of a sent mail to the cleanup: A626 keeps them until the hourly
	 * sweep (Mail\TempFiles, mail queue plugins send later); with a TTL of 0 they go at once.
	 *
	 * @param string[] $files Attachment paths.
	 * @param string   $dir   Private directory of the ticket PDFs ('' = none).
	 */
	public static function discard_attachments( array $files, $dir = '' ) {
		Mail\TempFiles::release( $files, '' !== (string) $dir ? array( (string) $dir ) : array() );
	}

	/** Creates a fresh directory (0700, random name) in the temp dir; null on failure. */
	private static function private_temp_dir() {
		$base = rtrim( (string) get_temp_dir(), '/\\' );
		for ( $i = 0; $i < 3; $i++ ) {
			$dir = $base . '/wpcap-' . bin2hex( random_bytes( 12 ) );
			$old_umask = umask( 0077 );
			$made = @mkdir( $dir, 0700 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an existing name is retried.
			umask( $old_umask );
			if ( $made ) {
				@chmod( $dir, 0700 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return $dir;
			}
		}
		return null;
	}

	/**
	 * SQL join for queries on order lines (alias oi) that compare them with issued tickets: leaves out
	 * lines of add-on products (Sales\Addons), which never get tickets. '' before schema 3.18.5.
	 */
	public static function addon_free_join() {
		global $wpdb;
		return Sales\Addons::ready() ? " INNER JOIN {$wpdb->prefix}wpcap_ticket_types att ON att.ticket_type_id=oi.ticket_type_id AND att.kind<>'" . Sales\Addons::ADDON . "'" : '';
	}

	/**
	 * Number of tickets a paid order should have but does not (issuance failed or is still pending).
	 *
	 * @return int|null Null on a database error.
	 */
	public static function missing_tickets( $order_id ) {
		// Lines of add-on products (Sales\Addons) get no tickets and are left out (addon_free_join()).
		global $wpdb;
		$p = $wpdb->prefix;
		$missing = $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(GREATEST(0, oi.qty - COALESCE(t.n,0))),0) FROM (SELECT oi.ticket_type_id, SUM(oi.quantity) AS qty FROM %i oi' . self::addon_free_join() . ' WHERE oi.order_id=%d GROUP BY oi.ticket_type_id) oi LEFT JOIN (SELECT ticket_type_id, COUNT(*) AS n FROM %i WHERE order_id=%d GROUP BY ticket_type_id) t ON t.ticket_type_id=oi.ticket_type_id', $p . 'wpcap_order_items', absint( $order_id ), $p . 'wpcap_tickets', absint( $order_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal join (Orders::addon_free_join()).
		return '' !== $wpdb->last_error || null === $missing ? null : (int) $missing;
	}

	/**
	 * Delivery status of the latest ticket mail of an order, from its outbox job.
	 *
	 * @return array|null {status: sent|pending|retrying|failed, attempts, last_error, next_attempt}; null when unknown.
	 */
	public static function delivery_status( $order_id ) {
		global $wpdb;
		$like = '{"order_id":' . absint( $order_id ) . ',%';
		$job = $wpdb->get_row( $wpdb->prepare( 'SELECT status,attempts,last_error,run_after FROM %i WHERE job_type=%s AND payload LIKE %s ORDER BY job_id DESC LIMIT 1', $wpdb->prefix . 'wpcap_jobs', Orders::DELIVERY_JOB, $like ) );
		if ( ! $job ) { return null; }
		$map = array( 'complete' => 'sent', 'dead_letter' => 'failed' );
		$status = $map[ $job->status ] ?? ( (int) $job->attempts > 0 ? 'retrying' : 'pending' );
		return array( 'status' => $status, 'attempts' => (int) $job->attempts, 'last_error' => (string) $job->last_error, 'next_attempt' => 'retrying' === $status ? (string) $job->run_after : '' );
	}

	/**
	 * Placeholder values shared by the ticket and resend mails.
	 *
	 * @param object   $order   Order row.
	 * @param array    $entries Ticket entries (event, ticket, when, location, url).
	 * @param callable $list    Builds the {ticket_list} block (runs in the recipient locale).
	 */
	public static function ticket_mail_vars( $order, array $entries, $list ) {
		$first = $entries[0] ?? array();
		return array(
			'order_id' => (string) $order->public_id,
			'customer_email' => (string) $order->customer_email,
			'event_name' => (string) ( $first['event'] ?? '' ),
			'event_date' => (string) ( $first['when'] ?? '' ),
			'event_location' => (string) ( $first['location'] ?? '' ),
			'ticket_count' => count( $entries ),
			'ticket_list' => $list,
			'ticket_links' => Mail\Blocks::links( array_column( $entries, 'url' ) ),
			// A568: the legal texts of the version this order was concluded under (built only when used).
			'contract_texts' => static function () use ( $order ) { return LegalVersions::mail_block( $order ); },
			// Agent model: the organizer as contract partner (empty otherwise).
			'contract_partner' => static function () use ( $order ) { return Mail\Blocks::contract_partner( Partners\SalesModel::contract_partner_for_order( (int) $order->order_id ) ); },
		);
	}

	/**
	 * Extra mail section of a ticket with a new code (filter wpcap_ticket_code_mail_section, e.g. wallet
	 * links), rendered in the mail's locale (A474).
	 */
	public static function code_mail_section( $ticket_id, $locale = '' ) {
		return (string) Mail\Mailer::with_locale( (string) $locale, static function () use ( $ticket_id ) {
			return apply_filters( 'wpcap_ticket_code_mail_section', '', (int) $ticket_id );
		} );
	}

	/** Tickets per attached ticket PDF (A580). */
	public static function ticket_pdf_chunk_size() {
		/**
		 * Tickets per PDF attachment of the ticket mail; larger orders get several PDFs (A580).
		 *
		 * @param int $size Default 50 (about 1 MB).
		 */
		return max( 1, (int) apply_filters( 'wpcap_ticket_pdf_chunk_size', 50 ) );
	}

	/**
	 * Attachment budget of a ticket mail in bytes (A580). Measured: ~21 KB PDF and ~10–20 KB .pkpass
	 * per ticket, base64 adds a third; 7 MB raw stays below the 10 MB limit of many mailboxes.
	 */
	public static function attachment_budget( $order = null ) {
		/**
		 * Maximum total size (bytes, before encoding) of the ticket mail's attachments (A580).
		 *
		 * @param int         $bytes Default 7 MB.
		 * @param object|null $order Order row.
		 */
		return max( 0, (int) apply_filters( 'wpcap_ticket_mail_attachment_budget', 7 * 1024 * 1024, $order ) );
	}

	/**
	 * A621: entry of the ticket PDF for one ticket (array with event, ticket, starts_at, timezone,
	 * location, ticket_id, occurrence_id, ticket_type_id, token, help_code, attendee).
	 */
	public static function pdf_ticket_entry( array $ticket ) {
		return array( 'event' => $ticket['event'], 'ticket' => $ticket['ticket'], 'when' => self::format_start( $ticket['starts_at'], $ticket['timezone'] ), 'location' => $ticket['location'], 'seat' => (string) apply_filters( 'wpcap_ticket_seat_label', '', (int) $ticket['ticket_id'], $ticket ), 'attendee' => (string) ( $ticket['attendee'] ?? '' ), 'token' => $ticket['token'], 'help_code' => (string) ( $ticket['help_code'] ?? '' ), 'ticket_id' => (int) $ticket['ticket_id'], 'occurrence_id' => (int) $ticket['occurrence_id'], 'ticket_type_id' => (int) $ticket['ticket_type_id'], 'starts_at' => $ticket['starts_at'], 'timezone' => $ticket['timezone'] );
	}

	/**
	 * A621: the ticket PDF attachments of a mail – the same builder for the ticket mail, the resend
	 * and its retry. The PDF holds plaintext ticket codes: private directory (0700) and files (0600)
	 * with random names in the shared temp dir. A580: large orders are split into several PDFs,
	 * attached while they fit the mail's attachment budget. Nothing for a placeholder address or when
	 * `wpcap_attach_ticket_pdf` is false.
	 *
	 * @param object $order       Order row.
	 * @param string $recipient   Mail recipient.
	 * @param array  $pdf_tickets pdf_ticket_entry() rows.
	 * @return array{files: string[], covered: int, dir: string}
	 */
	public static function ticket_pdf_attachments( $order, $recipient, array $pdf_tickets ) {
		$out = array( 'files' => array(), 'covered' => 0, 'dir' => '' );
		if ( ! $pdf_tickets || Mail\Mailer::is_placeholder( (string) $recipient ) || ! apply_filters( 'wpcap_attach_ticket_pdf', true, (int) $order->order_id ) ) { return $out; }
		$pdf_dir = self::private_temp_dir();
		if ( ! $pdf_dir ) { return $out; }
		$out['dir'] = $pdf_dir;
		$chunks = array_chunk( $pdf_tickets, self::ticket_pdf_chunk_size() );
		$budget = self::attachment_budget( $order );
		$used = 0;
		foreach ( $chunks as $index => $chunk ) {
			// A474: the PDF speaks the buyer's language, like the mail itself.
			$bytes = Mail\Mailer::with_locale( (string) $order->locale, static function () use ( $chunk ) {
				return TicketPdf::render( $chunk, array( 'title' => get_bloginfo( 'name' ), 'footer' => TicketPdf::default_footer() ) );
			} );
			if ( $index > 0 && $used + strlen( $bytes ) > $budget ) { break; }
			$file = $pdf_dir . '/tickets-' . Frontend\Format::order_reference( (string) $order->public_id ) . ( count( $chunks ) > 1 ? '-' . ( $index + 1 ) : '' ) . '.pdf'; // A091: readable order number.
			if ( false === file_put_contents( $file, $bytes ) ) { break; }
			@chmod( $file, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$out['files'][] = $file;
			$used += strlen( $bytes );
			$out['covered'] += count( $chunk );
		}
		return $out;
	}

	/** Event start in the event's own timezone; starts_at is stored in UTC. */
	public static function format_start( $starts_at, $timezone ) {
		$tz = (string) $timezone;
		try { $zone = new \DateTimeZone( '' !== $tz ? $tz : 'UTC' ); } catch ( \Exception $e ) { $zone = new \DateTimeZone( 'UTC' ); $tz = 'UTC'; }
		$ts = strtotime( $starts_at . ' UTC' );
		return false === $ts ? (string) $starts_at : wp_date( Frontend\Format::datetime_format(), $ts, $zone ) . ( '' !== $tz ? ' ' . $tz : '' );
	}

	/** Name used in customer communication (site title, falls back to the host name). */
	public static function site_name() {
		$name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		return (string) apply_filters( 'wpcap_sender_name', '' !== trim( $name ) ? $name : (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}
}

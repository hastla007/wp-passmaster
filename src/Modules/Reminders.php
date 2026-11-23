<?php
/**
 * Reminder messages X hours before an occurrence starts (several offsets possible).
 *
 * Scheduling runs on the wpcap_process_messages cron right before the core message queue is
 * processed. For each upcoming occurrence only the current stage (the smallest offset whose time has
 * come) is queued, so a late buyer gets one reminder rather than all of them. E-mails go through
 * the core queue (Communications::enqueue) with the dedupe key
 * reminder|{occurrence}|{starts_at}|{offset}|{recipient}: one message per ticket holder,
 * occurrence, start time and offset. Revoked/refunded tickets, unpaid orders and cancelled
 * occurrences are skipped, and queued reminders that are no longer valid are cancelled before sending.
 * Optional SMS reminders go to consenting buyers (one per order).
 *
 * A587: each reminder is rendered in the language of the holder's order (payload "locale"): texts
 * per language (setting reminders.translations[locale] = subject/body), otherwise the built-in
 * default translated into that language (as long as the site text was not customised), otherwise
 * the site text. {ticket_links} lists the holder's tickets for the occurrence with their personal
 * ticket page (code from the encrypted store, TicketCodes); the ticket page also offers the order link.
 */
namespace PassCaptain\Modules;

use PassCaptain\Admin\Forms;
use PassCaptain\Communications;
use PassCaptain\Reminders\Placeholders;
use PassCaptain\Settings;
use PassCaptain\Sms\Service as SmsService;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Reminders {
	const CAP = 'manage_options';
	const PAGE = 'wpcap-reminders';
	const TEMPLATE = 'wpcap_reminder';

	public static function register() {
		add_action( 'wpcap_process_messages', array( __CLASS__, 'schedule_due' ), 1, 0 );
		add_action( 'wpcap_process_messages', array( __CLASS__, 'purge_stale' ), 5, 0 );
		// The legacy name wp_pass_captain_email_template_v1 reaches this callback through Hooks::legacy_filter() in the queue.
		add_filter( 'wpcap_email_template', array( __CLASS__, 'render_template' ), 10, 4 );
		add_action( 'wpcap_occurrence_cancelled', array( __CLASS__, 'purge_stale' ), 20, 0 );
		add_action( 'wpcap_occurrence_rescheduled', array( __CLASS__, 'purge_stale' ), 20, 0 );
		// A689: opt-out link and RFC 8058 one-click unsubscribe (List-Unsubscribe-Post).
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle_optout' ), 1, 0 );
	}

	/* ---------------------------------------------------------------- Opt-out (A623, A689) */

	/*
	 * Reminder opt-out (A689) is one mechanism with the mail suppression list (Mail\Suppression, scope
	 * "reminders"): link in every reminder, List-Unsubscribe with RFC 8058 one-click, spam complaints
	 * of the bounce webhook. The methods below are the reminder-facing API; links of the former
	 * option-based opt-out (?wpcap_reminder_optout=…) keep working.
	 */

	/** Legacy option with opted-out recipients (keyed e-mail hash => time); read, no longer written. */
	const OPTOUT_OPTION = 'wpcap_reminder_optouts';

	/** Query argument of the opt-out link (the suppression list's unsubscribe link). */
	const OPTOUT_QUERY = 'wpcap_unsubscribe';

	/** Query argument of opt-out links sent before both mechanisms were unified. */
	const LEGACY_OPTOUT_QUERY = 'wpcap_reminder_optout';

	/** Pseudonymous reference of a recipient (keyed hash, no address in links or tables; '' when invalid). */
	public static function optout_ref( $email ) {
		return \PassCaptain\Consents::subject_hash( (string) $email );
	}

	/** Signature of a legacy opt-out link for $ref. */
	public static function optout_sig( $ref ) {
		return hash_hmac( 'sha256', 'reminder-optout|' . (string) $ref, wp_salt( 'auth' ) );
	}

	/** Personal opt-out link of a recipient ('' for invalid addresses). */
	public static function optout_url( $email ) {
		return '' === self::optout_ref( $email ) ? '' : \PassCaptain\Mail\Suppression::unsubscribe_url( $email );
	}

	/** Whether the recipient opted out of reminder mails (unsubscribe link, one-click or spam complaint). */
	public static function is_opted_out( $email ) {
		return \PassCaptain\Mail\Suppression::suppressed( (string) $email );
	}

	/** Whether $sig signs $ref: the current unsubscribe link or a legacy opt-out link. */
	private static function optout_valid( $ref, $sig ) {
		$ref = strtolower( (string) $ref );
		return (bool) preg_match( '/^[a-f0-9]{64}$/', $ref ) && ( \PassCaptain\Mail\Suppression::signature_valid( $ref, (string) $sig ) || hash_equals( self::optout_sig( $ref ), (string) $sig ) );
	}

	/**
	 * Records an opt-out from a signed link. Queued reminders of that recipient are cancelled when they
	 * are due (render_template()).
	 *
	 * @return true|\WP_Error
	 */
	public static function opt_out( $ref, $sig ) {
		if ( ! self::optout_valid( $ref, $sig ) ) {
			return new \WP_Error( 'wpcap_optout_invalid', __( 'Der Abmeldelink ist ungültig.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$ref = strtolower( (string) $ref );
		if ( ! \PassCaptain\Mail\Suppression::suppressed_hash( $ref ) ) {
			\PassCaptain\Mail\Suppression::suppress_hash( $ref, \PassCaptain\Mail\Suppression::SCOPE_REMINDERS, 'unsubscribe' );
			\PassCaptain\Audit::record( 'reminder_opt_out', 'system', 0, array( 'ref' => substr( $ref, 0, 12 ) ) );
		}
		return true;
	}

	/** List-Unsubscribe headers of a reminder to $email (RFC 2369 / RFC 8058 one-click). */
	public static function optout_headers( $email ) {
		return '' === self::optout_ref( $email ) ? array() : \PassCaptain\Mail\Suppression::headers( $email );
	}

	/**
	 * template_redirect: legacy opt-out links (?wpcap_reminder_optout=…). GET shows a confirmation
	 * button (link scanners must not unsubscribe anybody); POST – the button or a mail client's
	 * one-click request – records it. Current links are served by Mail\Suppression.
	 */
	public static function maybe_handle_optout() {
		// phpcs:disable WordPress.Security.NonceVerification -- capability link (signed reference), no state change on GET.
		if ( ! isset( $_GET[ self::LEGACY_OPTOUT_QUERY ] ) ) { return; }
		$ref = sanitize_key( wp_unslash( (string) $_GET[ self::LEGACY_OPTOUT_QUERY ] ) );
		$sig = sanitize_key( wp_unslash( (string) ( $_GET['sig'] ?? '' ) ) );
		// phpcs:enable WordPress.Security.NonceVerification
		\PassCaptain\PageCache::no_store( 'reminder_optout' );
		if ( ! headers_sent() ) { header( 'Referrer-Policy: no-referrer' ); }
		list( $code, $html ) = self::optout_page( $ref, $sig, \PassCaptain\Http::is_post(), self::LEGACY_OPTOUT_QUERY );
		status_header( $code );
		echo '<!doctype html><html ' . \PassCaptain\Frontend\Format::html_lang_attributes() . '><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . esc_html__( 'Erinnerungen abbestellen', 'pass-captain' ) . '</title></head><body><main style="max-width:36rem;margin:2rem auto;padding:0 1rem;font:16px/1.5 system-ui,sans-serif">' . $html . '</main></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in optout_page().
		\PassCaptain\Http::end();
	}

	/**
	 * Status and HTML (escaped) of the opt-out page.
	 *
	 * @param string $query Query argument of the form action (current or legacy link).
	 * @return array{0:int,1:string}
	 */
	public static function optout_page( $ref, $sig, $post, $query = self::OPTOUT_QUERY ) {
		if ( ! self::optout_valid( $ref, $sig ) ) {
			return array( 400, '<h1>' . esc_html__( 'Link ungültig', 'pass-captain' ) . '</h1><p>' . esc_html__( 'Der Abmeldelink ist ungültig.', 'pass-captain' ) . '</p>' );
		}
		if ( $post ) {
			self::opt_out( $ref, $sig );
			return array( 200, '<h1>' . esc_html__( 'Erinnerungen abbestellt', 'pass-captain' ) . '</h1><p>' . esc_html__( 'Du erhältst keine Erinnerungs-E-Mails zu deinen Terminen mehr. Ticketmails und wichtige Mitteilungen wie Absagen oder Terminänderungen bekommst du weiterhin.', 'pass-captain' ) . '</p>' );
		}
		$action = add_query_arg( array( ( self::LEGACY_OPTOUT_QUERY === $query ? self::LEGACY_OPTOUT_QUERY : self::OPTOUT_QUERY ) => $ref, 'sig' => $sig ), home_url( '/' ) );
		return array( 200, '<h1>' . esc_html__( 'Erinnerungen abbestellen', 'pass-captain' ) . '</h1><p>' . esc_html__( 'Möchtest du keine Erinnerungs-E-Mails zu deinen Terminen mehr erhalten? Ticketmails und Mitteilungen zu Absagen oder Terminänderungen bekommst du weiterhin.', 'pass-captain' ) . '</p><form method="post" action="' . esc_attr( esc_url_raw( $action ) ) . '"><input type="hidden" name="List-Unsubscribe" value="One-Click"><button type="submit" style="padding:.6rem 1rem">' . esc_html__( 'Erinnerungen abbestellen', 'pass-captain' ) . '</button></form>' );
	}

	public static function settings_defaults() {
		return array(
			'reminders' => array(
				'enabled' => 0,
				'offsets' => array( 24 ),
				'subject' => __( 'Erinnerung: {event} am {date}', 'pass-captain' ),
				'body' => __( "Hallo,\n\nin {hours} Stunden geht es los: „{event}“ am {date} um {time} Uhr ({timezone}).\nOrt: {location}\nTickets: {tickets}\n\n{ticket_links}\n\nTermin in den Kalender übernehmen: {ics_url}\n\nBitte bring dein Ticket (ausgedruckt oder auf dem Smartphone) mit.\n\n{site}", 'pass-captain' ),
				'sms' => 0,
				'sms_body' => __( 'Erinnerung: {event} am {date} um {time}, {location}.', 'pass-captain' ),
				'translations' => array(),
			),
		);
	}

	/** Settings register entries of this module (SET-PC-01, SettingsSchema). */
	public static function settings_schema() {
		return array(
			'reminders' => array( 'type' => 'group', 'label' => __( 'Erinnerungen', 'pass-captain' ), 'help' => __( 'Erinnerungsmails und -SMS vor dem Termin: Zeitpunkte, Betreff und Text je Sprache.', 'pass-captain' ), 'page' => 'wpcap-reminders', 'fields' => array( 'offsets' => array( 'type' => 'list', 'item' => array( 'type' => 'int', 'min' => 1, 'max' => 720, 'label' => __( 'Zeitpunkte (Stunden vor Beginn)', 'pass-captain' ) ), 'max_items' => 5, 'label' => __( 'Zeitpunkte (Stunden vor Beginn)', 'pass-captain' ) ) ) ),
		);
	}

	public static function admin_pages() {
		return array( array( self::PAGE, __( 'Erinnerungen', 'pass-captain' ), self::CAP, array( __CLASS__, 'render_admin' ) ) );
	}

	public static function admin_handlers() {
		return array( 'wpcap_reminders_save' => array( __CLASS__, 'handle_save' ) );
	}

	public static function config() {
		$defaults = self::settings_defaults()['reminders'];
		$c = array_merge( $defaults, (array) Settings::get( 'reminders' ) );
		// A587: '' (or the unchanged site-language default of older installs) means "built-in text",
		// which is translated into the recipient's language when rendered.
		$site = \PassCaptain\Mail\Mailer::with_locale( \PassCaptain\Mail\Mailer::site_locale(), static function () { return self::settings_defaults()['reminders']; } );
		foreach ( array( 'subject', 'body' ) as $key ) {
			if ( '' === trim( (string) $c[ $key ] ) || (string) $c[ $key ] === (string) $site[ $key ] ) { $c[ $key ] = $defaults[ $key ]; }
		}
		$c['translations'] = is_array( $c['translations'] ?? null ) ? $c['translations'] : array();
		$offsets = array_values( array_unique( array_filter( array_map( 'absint', (array) $c['offsets'] ), static function ( $h ) { return $h >= 1 && $h <= 720; } ) ) );
		sort( $offsets );
		$c['offsets'] = $offsets;
		return $c;
	}

	/**
	 * Current stage of an occurrence: smallest offset whose send time has passed, or 0.
	 *
	 * @param int   $starts  Start timestamp (UTC).
	 * @param int[] $offsets Ascending offsets in hours.
	 */
	public static function stage( $starts, array $offsets, $now = null ) {
		$now = $now ?? \PassCaptain\Clock::now();
		if ( $starts <= $now ) { return 0; }
		foreach ( $offsets as $hours ) {
			if ( $starts - $hours * HOUR_IN_SECONDS <= $now ) { return (int) $hours; }
		}
		return 0;
	}

	/**
	 * Hours until the start as named in {hours} (A558): the real remaining time when the message is
	 * rendered (a late buyer queued at the 24 h stage 5 hours before the start reads "5"), rounded,
	 * at least 1.
	 */
	public static function hours_left( $starts_at, $now = null ) {
		$now = $now ?? \PassCaptain\Clock::now();
		$left = (int) strtotime( (string) $starts_at . ' UTC' ) - (int) $now;
		return max( 1, (int) round( $left / HOUR_IN_SECONDS ) );
	}

	/** Occurrences read per page while scheduling. */
	const PAGE_SIZE = 200;

	/** Alias of PAGE_SIZE (keyset batches, A558). */
	const BATCH = self::PAGE_SIZE;

	/** Option: where the last run stopped on the time budget ("starts_at|occurrence_id"). */
	const CURSOR_OPTION = 'wpcap_reminder_cursor';

	/** Alias of CURSOR_OPTION. */
	const CURSOR = self::CURSOR_OPTION;


	/**
	 * Queues due reminders. Returns the number of newly queued e-mails and SMS.
	 *
	 * Every occurrence in the reminder window is visited (pages of PAGE_SIZE, no fixed cap, so early
	 * stages of later occurrences are not skipped when more than 200 occurrences are upcoming). Per
	 * occurrence the holders are read once and the existing reminders checked with one IN() query
	 * (no SELECT per holder). The run stops on the shared cron time budget; the next run continues
	 * after the last occurrence handled and then wraps around.
	 * A558: every occurrence within the largest offset is visited (not only the first 200), so a busy
	 * window no longer delays later dates.
	 */
	public static function schedule_due( $now = null ) {
		global $wpdb;
		$c = self::config();
		if ( empty( $c['enabled'] ) || ! $c['offsets'] ) { return 0; }
		$p = $wpdb->prefix;
		$now = $now ?? \PassCaptain\Clock::now();
		$deadline = \PassCaptain\Jobs\TimeBudget::deadline();
		$from = gmdate( 'Y-m-d H:i:s', $now );
		$to = gmdate( 'Y-m-d H:i:s', $now + max( $c['offsets'] ) * HOUR_IN_SECONDS );
		$saved = (string) get_option( self::CURSOR_OPTION, '' );
		// Start cursor: a valid DATETIME literal (MySQL 8 rejects comparing a DATETIME with '' – error 1525).
		$origin = array( '1970-01-01 00:00:00', '0' );
		$start = '' !== $saved ? explode( '|', $saved, 2 ) + $origin : $origin;
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $start[0] ) ) { $start = $origin; }
		// Pass 1: from the saved cursor to the end; pass 2 (only after a saved cursor): from the start up to it.
		$passes = '' !== $saved ? array( array( $start, null ), array( $origin, $start ) ) : array( array( $start, null ) );
		$queued = 0;
		foreach ( $passes as list( $cursor, $until ) ) {
			while ( true ) {
				$sql = "SELECT o.occurrence_id,o.starts_at,o.timezone,o.location,e.name AS event_name FROM %i o INNER JOIN %i e ON e.event_id=o.event_id WHERE o.status='scheduled' AND e.status='published' AND o.starts_at>%s AND o.starts_at<=%s AND (o.starts_at>%s OR (o.starts_at=%s AND o.occurrence_id>%d))";
				$args = array( $p . 'wpcap_occurrences', $p . 'wpcap_events', $from, $to, $cursor[0], $cursor[0], (int) $cursor[1] );
				if ( $until ) {
					$sql .= ' AND (o.starts_at<%s OR (o.starts_at=%s AND o.occurrence_id<=%d))';
					array_push( $args, $until[0], $until[0], (int) $until[1] );
				}
				$sql .= ' ORDER BY o.starts_at,o.occurrence_id LIMIT %d';
				$args[] = self::PAGE_SIZE;
				$occurrences = $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders only.
				foreach ( $occurrences as $occ ) {
					if ( \PassCaptain\Jobs\TimeBudget::exhausted( $deadline ) ) {
						// Budget used up: remember where to continue and ask for a follow-up run.
						update_option( self::CURSOR_OPTION, $cursor[0] . '|' . (int) $cursor[1], false );
						\PassCaptain\Jobs\TimeBudget::continue_later( 'wpcap_process_messages' );
						return $queued;
					}
					$queued += self::schedule_occurrence( $occ, $c, $now );
					$cursor = array( (string) $occ->starts_at, (string) $occ->occurrence_id );
				}
				if ( count( $occurrences ) < self::PAGE_SIZE ) { break; }
			}
		}
		if ( '' !== $saved ) { delete_option( self::CURSOR_OPTION ); }
		return $queued;
	}

	/** Queues the reminders of the current stage of one occurrence; returns the number newly queued. */
	private static function schedule_occurrence( $occ, array $c, $now ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$stage = self::stage( (int) strtotime( $occ->starts_at . ' UTC' ), $c['offsets'], $now );
		if ( ! $stage ) { return 0; }
		$queued = 0;
		$holders = $wpdb->get_results( $wpdb->prepare( 'SELECT LOWER(' . \PassCaptain\TicketCodes::holder_email_sql() . ') AS email,COUNT(*) AS tickets,' . \PassCaptain\Notifications::LATEST_LOCALE_SQL . " AS locale FROM %i t INNER JOIN %i ord ON ord.order_id=t.order_id INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=%d AND t.status='valid' AND t.refunded=0 AND ord.payment_status='paid' GROUP BY email", $p . 'wpcap_tickets', $p . 'wpcap_orders', $p . 'wpcap_ticket_types', (int) $occ->occurrence_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal SQL fragments.
		$keys = array();
		foreach ( $holders as $holder ) {
			if ( ! is_email( $holder->email ) ) { continue; }
			$keys[ hash( 'sha256', 'reminder|' . (int) $occ->occurrence_id . '|' . $occ->starts_at . '|' . $stage . '|' . $holder->email ) ] = $holder;
		}
		$existing = self::existing_keys( array_keys( $keys ) );
		foreach ( $keys as $hash => $holder ) {
			// Already queued, or unsubscribed (link in every reminder, A689) / spam complaint: nothing to queue.
			if ( isset( $existing[ $hash ] ) || self::is_opted_out( $holder->email ) ) { continue; }
			$key = 'reminder|' . (int) $occ->occurrence_id . '|' . $occ->starts_at . '|' . $stage . '|' . $holder->email;
			$payload = array( 'occurrence_id' => (int) $occ->occurrence_id, 'starts_at' => $occ->starts_at, 'hours' => $stage, 'tickets' => (int) $holder->tickets );
			$locale = \PassCaptain\Mail\Mailer::normalize_locale( (string) ( $holder->locale ?? '' ) );
			if ( '' !== $locale ) { $payload['locale'] = $locale; } // A587: language of the holder's order.
			$result = Communications::enqueue( $key, $holder->email, self::TEMPLATE, $payload );
			if ( true === $result ) { ++$queued; }
		}
		if ( ! empty( $c['sms'] ) && '' !== trim( (string) $c['sms_body'] ) && class_exists( SmsService::class ) ) {
			$orders = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT t.order_id FROM %i t INNER JOIN %i ord ON ord.order_id=t.order_id INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=%d AND t.status='valid' AND t.refunded=0 AND ord.payment_status='paid'", $p . 'wpcap_tickets', $p . 'wpcap_orders', $p . 'wpcap_ticket_types', (int) $occ->occurrence_id ) );
			$body = Placeholders::render( $c['sms_body'], array_merge( Placeholders::occurrence_vars( $occ ), array( 'hours' => self::hours_left( $occ->starts_at, $now ) ) ) );
			foreach ( $orders as $order_id ) {
				if ( true === SmsService::enqueue( 'sms|reminder|' . (int) $occ->occurrence_id . '|' . $occ->starts_at . '|' . $stage . '|' . (int) $order_id, (int) $order_id, (int) $occ->occurrence_id, 'reminder', $body ) ) { ++$queued; }
			}
		}
		return $queued;
	}

	/** Dedupe keys (sha256) that already exist in the message queue, as a set; one query per 500 keys. */
	private static function existing_keys( array $hashes ) {
		global $wpdb;
		$out = array();
		foreach ( array_chunk( $hashes, 500 ) as $chunk ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- generated placeholders.
			foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT dedupe_key FROM %i WHERE dedupe_key IN (' . implode( ',', array_fill( 0, count( $chunk ), '%s' ) ) . ')', array_merge( array( $wpdb->prefix . 'wpcap_message_queue' ), $chunk ) ) ) as $hash ) {
				$out[ (string) $hash ] = true;
			}
		}
		return $out;
	}

	/** Whether a queued reminder is still valid (occurrence scheduled at the same time, holder has valid tickets). */
	public static function still_valid( $recipient, array $payload ) {
		global $wpdb;
		if ( self::is_opted_out( (string) $recipient ) ) { return false; }
		$p = $wpdb->prefix;
		$occ = $wpdb->get_row( $wpdb->prepare( 'SELECT o.starts_at,o.status,e.status AS event_status FROM %i o INNER JOIN %i e ON e.event_id=o.event_id WHERE o.occurrence_id=%d', $p . 'wpcap_occurrences', $p . 'wpcap_events', (int) ( $payload['occurrence_id'] ?? 0 ) ) );
		if ( ! $occ || 'scheduled' !== $occ->status || 'published' !== $occ->event_status || $occ->starts_at !== ( $payload['starts_at'] ?? '' ) || strtotime( $occ->starts_at . ' UTC' ) <= \PassCaptain\Clock::now() ) { return false; }
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i t INNER JOIN %i ord ON ord.order_id=t.order_id INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=%d AND t.status='valid' AND t.refunded=0 AND ord.payment_status='paid' AND LOWER(" . \PassCaptain\TicketCodes::holder_email_sql() . ")=%s", $p . 'wpcap_tickets', $p . 'wpcap_orders', $p . 'wpcap_ticket_types', (int) $payload['occurrence_id'], strtolower( (string) $recipient ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- TicketCodes::holder_email_sql() returns one of two fixed column expressions.
	}

	/**
	 * Cancels queued reminders that are no longer valid. Returns the number cancelled.
	 *
	 * Rows are checked in the order the message queue sends them (send_at, message_id) and at least as
	 * many as one queue run can send, so a reminder is always checked before it can go out, also with a
	 * backlog larger than one batch or a raised `wpcap_message_batch_size`.
	 */
	public static function purge_stale() {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcap_message_queue';
		$limit = max( 500, (int) apply_filters( 'wpcap_message_batch_size', Communications::BATCH_SIZE ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT message_id,recipient,payload FROM %i WHERE status='queued' AND template_key=%s ORDER BY send_at,message_id LIMIT %d", $table, self::TEMPLATE, $limit ) );
		$cancelled = 0;
		foreach ( $rows as $row ) {
			$payload = json_decode( (string) $row->payload, true );
			if ( is_array( $payload ) && self::still_valid( $row->recipient, $payload ) ) { continue; }
			$cancelled += (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='cancelled',last_error='reminder_not_valid' WHERE message_id=%d AND status='queued'", $table, (int) $row->message_id ) );
		}
		return $cancelled;
	}

	/**
	 * wpcap_email_template: renders the reminder with the current texts and occurrence data, in the
	 * recipient's language (payload locale, A587).
	 *
	 * @param string $recipient Recipient address (4th filter argument, for {ticket_links}); '' renders without links.
	 */
	public static function render_template( $template, $key = '', $payload = array(), $recipient = '' ) {
		if ( self::TEMPLATE !== $key || ! is_array( $payload ) ) { return $template; }
		// A689: opted out after the reminder was queued – it is cancelled, not sent.
		if ( '' !== (string) $recipient && self::is_opted_out( (string) $recipient ) ) { return array( 'skip' => 'opted_out' ); }
		global $wpdb;
		$p = $wpdb->prefix;
		$occ = $wpdb->get_row( $wpdb->prepare( 'SELECT o.occurrence_id,o.starts_at,o.timezone,o.location,e.name AS event_name FROM %i o INNER JOIN %i e ON e.event_id=o.event_id WHERE o.occurrence_id=%d', $p . 'wpcap_occurrences', $p . 'wpcap_events', (int) ( $payload['occurrence_id'] ?? 0 ) ) );
		if ( ! $occ ) { return $template; }
		$locale = \PassCaptain\Mail\Mailer::normalize_locale( (string) ( $payload['locale'] ?? '' ) );
		return \PassCaptain\Mail\Mailer::with_locale( $locale, static function () use ( $occ, $payload, $locale, $recipient ) {
			$c = self::config();
			$texts = self::texts( $c, $locale );
			// A558: {hours} is the remaining time when the mail goes out, not the stage it was queued for.
			$vars = array_merge( Placeholders::occurrence_vars( $occ ), array( 'hours' => self::hours_left( $occ->starts_at ), 'tickets' => (int) ( $payload['tickets'] ?? 0 ), 'ticket_links' => self::ticket_links( (int) $occ->occurrence_id, (string) $recipient ) ) );
			$body = Placeholders::render( $texts['body'], $vars );
			$optout = '' !== (string) $recipient ? self::optout_url( (string) $recipient ) : '';
			if ( '' !== $optout ) {
				/* translators: %s: personal opt-out link */
				$body .= "\n\n" . sprintf( __( 'Keine Erinnerungen mehr per E-Mail erhalten? Hier abbestellen %s', 'pass-captain' ), $optout );
			}
			// The reminder editor is plain text: in HTML mode the rendered text is escaped as a whole
			// (values included), URLs become links and the layout adds the plain-text alternative.
			$html = 'html' === \PassCaptain\Mail\Templates::default_format( 'reminder' );
			return array( 'subject' => Placeholders::render( $texts['subject'], $vars ), 'body' => $html ? \PassCaptain\Mail\Mailer::text_to_html( $body ) : $body, 'format' => $html ? 'html' : 'text', 'locale' => $locale, 'headers' => '' !== (string) $recipient ? self::optout_headers( (string) $recipient ) : array() );
		} );
	}

	/** Subject and body for a locale: own texts of that language, else the configured (or built-in) text. */
	public static function texts( array $c, $locale ) {
		$own = (array) ( $c['translations'][ $locale ] ?? array() );
		return array(
			'subject' => '' !== trim( (string) ( $own['subject'] ?? '' ) ) ? (string) $own['subject'] : (string) $c['subject'],
			'body' => '' !== trim( (string) ( $own['body'] ?? '' ) ) ? (string) $own['body'] : (string) $c['body'],
		);
	}

	/**
	 * {ticket_links}: the recipient's valid tickets of the occurrence with their personal ticket page
	 * (only tickets whose current code is stored – nothing is rotated); otherwise a hint to the ticket mail.
	 */
	public static function ticket_links( $occurrence_id, $recipient ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$lines = array();
		if ( '' !== (string) $recipient ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT t.ticket_id FROM %i t INNER JOIN %i ord ON ord.order_id=t.order_id INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=%d AND t.status='valid' AND t.refunded=0 AND ord.payment_status='paid' AND LOWER(" . \PassCaptain\TicketCodes::holder_email_sql() . ")=%s ORDER BY t.ticket_id LIMIT 50", $p . 'wpcap_tickets', $p . 'wpcap_orders', $p . 'wpcap_ticket_types', absint( $occurrence_id ), strtolower( (string) $recipient ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- TicketCodes::holder_email_sql() returns one of two fixed column expressions.
			foreach ( $ids as $id ) {
				$code = \PassCaptain\PortalSelfService::current_code( (int) $id );
				if ( '' !== $code ) { $lines[] = __( 'Dein Ticket:', 'pass-captain' ) . ' ' . add_query_arg( 'wpcap_ticket', $code, home_url( '/' ) ); }
			}
		}
		return $lines ? implode( "\n", $lines ) : __( 'Dein Ticket findest du in der E-Mail mit deinen Tickets.', 'pass-captain' );
	}

	/* ------------------------------------------------------------------ Admin */

	public static function save( array $in ) {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		// SET-PC-01: every point in time is checked by the settings register (reported, not dropped).
		$offsets = array_values( array_unique( preg_split( '/[\s,;]+/', trim( (string) ( is_scalar( $in['offsets'] ?? null ) ? $in['offsets'] : '' ) ), -1, PREG_SPLIT_NO_EMPTY ) ) );
		$checked = \PassCaptain\SettingsSchema::check_group( 'reminders', array( 'offsets' => $offsets ) );
		if ( is_wp_error( $checked ) ) { return $checked; }
		$offsets = array_values( array_unique( $checked['offsets'] ) );
		sort( $offsets );
		$values = array(
			'enabled' => Forms::flag( $in, 'enabled' ) ? 1 : 0,
			'offsets' => array_slice( $offsets, 0, 5 ),
			'subject' => Forms::text( $in, 'subject', 190 ),
			'body' => Forms::textarea( $in, 'body', 5000 ),
			'sms' => Forms::flag( $in, 'sms' ) ? 1 : 0,
			'sms_body' => Forms::textarea( $in, 'sms_body', 640 ),
			'translations' => array(),
		);
		if ( $values['enabled'] && ( ! $values['offsets'] || '' === $values['subject'] || '' === $values['body'] ) ) {
			return Forms::error( 'wpcap_reminders_invalid', __( 'Bitte mindestens einen Zeitpunkt (1–720 Stunden), einen Betreff und einen Text angeben.', 'pass-captain' ) );
		}
		// A587: the unchanged built-in text is stored as '' so every recipient gets it in their language.
		$defaults = self::settings_defaults()['reminders'];
		foreach ( array( 'subject', 'body' ) as $key ) {
			if ( str_replace( "\r\n", "\n", $values[ $key ] ) === str_replace( "\r\n", "\n", (string) $defaults[ $key ] ) ) { $values[ $key ] = ''; }
		}
		foreach ( (array) ( is_array( $in['translations'] ?? null ) ? $in['translations'] : array() ) as $locale => $text ) {
			$locale = \PassCaptain\Mail\Mailer::normalize_locale( (string) $locale );
			if ( '' === $locale || ! is_array( $text ) ) { continue; }
			$own = array( 'subject' => Forms::text( $text, 'subject', 190 ), 'body' => Forms::textarea( $text, 'body', 5000 ) );
			if ( '' !== $own['subject'] || '' !== $own['body'] ) { $values['translations'][ $locale ] = $own; }
		}
		Settings::update( array( 'reminders' => $values ) );
		return array( 'saved' => $values );
	}

	public static function handle_save() {
		Forms::verify( 'wpcap_reminders_save' );
		Forms::finish( self::save( Forms::post() ), __( 'Einstellungen gespeichert.', 'pass-captain' ), Forms::page_url( self::PAGE ) );
	}

	public static function render_admin() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		$c = self::config();
		echo '<div class="wrap wpcap-admin"><h1>' . esc_html__( 'Erinnerungen', 'pass-captain' ) . '</h1>';
		Forms::render_notices();
		Forms::open_form( 'wpcap_reminders_save' );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Aktiv', 'pass-captain' ) . '</th><td><label><input type="checkbox" name="enabled" value="1"' . checked( ! empty( $c['enabled'] ), true, false ) . '> ' . esc_html__( 'Erinnerungs-E-Mails an Ticketinhaber:innen senden', 'pass-captain' ) . '</label></td></tr>';
		echo '<tr><th scope="row"><label for="wpcap-rem-offsets">' . esc_html__( 'Zeitpunkte (Stunden vor Beginn)', 'pass-captain' ) . '</label></th><td><input type="text" id="wpcap-rem-offsets" name="offsets" value="' . esc_attr( implode( ', ', $c['offsets'] ) ) . '"><p class="description">' . esc_html__( 'Kommagetrennt, z. B. 48, 2 (max. 5 Werte). Pro Termin und Zeitpunkt erhält jede Person höchstens eine Nachricht.', 'pass-captain' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="wpcap-rem-subject">' . esc_html__( 'Betreff', 'pass-captain' ) . '</label></th><td><input type="text" id="wpcap-rem-subject" name="subject" class="large-text" value="' . esc_attr( $c['subject'] ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="wpcap-rem-body">' . esc_html__( 'Text', 'pass-captain' ) . '</label></th><td><textarea id="wpcap-rem-body" name="body" class="large-text" rows="10">' . esc_textarea( $c['body'] ) . '</textarea></td></tr>';
		foreach ( \PassCaptain\Mail\Admin::locales() as $locale ) {
			if ( \PassCaptain\Mail\Mailer::site_locale() === $locale ) { continue; }
			$own = (array) ( $c['translations'][ $locale ] ?? array() );
			/* translators: %s: locale code, e.g. en_US */
			echo '<tr><th scope="row">' . esc_html( sprintf( __( 'Text für %s (optional)', 'pass-captain' ), $locale ) ) . '</th><td>';
			echo '<input type="text" name="translations[' . esc_attr( $locale ) . '][subject]" class="large-text" value="' . esc_attr( (string) ( $own['subject'] ?? '' ) ) . '" aria-label="' . esc_attr( $locale . ' – ' . __( 'Betreff', 'pass-captain' ) ) . '">';
			echo '<textarea name="translations[' . esc_attr( $locale ) . '][body]" class="large-text" rows="6" aria-label="' . esc_attr( $locale . ' – ' . __( 'Text', 'pass-captain' ) ) . '">' . esc_textarea( (string) ( $own['body'] ?? '' ) ) . '</textarea>';
			echo '<p class="description">' . esc_html__( 'Leer: der eingebaute Text in dieser Sprache bzw. der Text oben. Erinnerungen gehen in der Sprache der Bestellung.', 'pass-captain' ) . '</p></td></tr>';
		}
		echo '<tr><th scope="row">' . esc_html__( 'SMS', 'pass-captain' ) . '</th><td><label><input type="checkbox" name="sms" value="1"' . checked( ! empty( $c['sms'] ), true, false ) . '> ' . esc_html__( 'Zusätzlich per SMS erinnern (nur mit Einwilligung, siehe SMS-Einstellungen; kostenpflichtig)', 'pass-captain' ) . '</label><textarea name="sms_body" class="large-text" rows="2" aria-label="' . esc_attr__( 'SMS-Text', 'pass-captain' ) . '">' . esc_textarea( $c['sms_body'] ) . '</textarea></td></tr>';
		echo '</tbody></table><h2>' . esc_html__( 'Platzhalter', 'pass-captain' ) . '</h2><ul>';
		foreach ( Placeholders::available() as $placeholder => $label ) { echo '<li><code>' . esc_html( $placeholder ) . '</code> – ' . esc_html( $label ) . '</li>'; }
		echo '</ul>';
		submit_button( __( 'Speichern', 'pass-captain' ) );
		echo '</form></div>';
	}
}

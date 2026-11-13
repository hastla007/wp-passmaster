<?php
/**
 * Daily enforcement of the retention settings (storage limitation, Art. 5(1)(e) GDPR).
 *
 * After the end of an occurrence (ends_at, otherwise starts_at + 1 day) plus N days:
 * - participant answers are deleted (N = setting attendee_retention_days, or the field's own
 *   retention_days when the setting is 0),
 * - attendee name/e-mail in ticket assignments, tickets.attendee_email and the names in offline
 *   packages are cleared (N = attendee_retention_days, or filter wpcap_attendee_default_retention_days,
 *   default 365, when the setting is 0),
 * - Apple Wallet device registrations of these tickets are deleted.
 * Further: attendee_data of unpaid orders is cleared, buyer e-mails of never-paid orders are
 * pseudonymised after 90 days, sent/failed queue messages lose recipient and content after 30 days
 * (rows deleted after a year), check-ins lose staff user/device after 365 days and audit log
 * entries are deleted after 730 days. All periods are settings (Einstellungen → Allgemein → Datenschutz,
 * A123) and filterable; 0 disables a step.
 *
 * Financial records (A123, setting retention_financial_years, default 0 = keep): invoices with their
 * lines and correction documents are deleted once their calendar year plus N years has ended (the
 * statutory period starts at the end of the year of issue); the buyer data of the orders concerned is
 * pseudonymised at the same time (amounts stay for the statistics). Modules add their own documents
 * (box office Z reports) via wpcap_privacy_retention_steps and financial_cutoff().
 *
 * Work runs in batches of 500 rows within a time budget (~20 s); the next run continues. Only
 * counts are written to the audit log.
 */
namespace PassCaptain\Privacy;

use PassCaptain\Audit;
use PassCaptain\Privacy;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Retention {
	const HOOK = 'wpcap_privacy_retention';
	const BATCH = 500;

	/** Registers the daily event (activation, upgrades and the self-healing check on init, Plugin::ensure_events()). */
	public static function schedule() {
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/** Step => setting key with the configurable period in days (A123). */
	const SETTINGS = array(
		'unpaid_customer_email' => 'retention_unpaid_customer_email_days',
		'message_content' => 'retention_message_content_days',
		'message_rows' => 'retention_message_rows_days',
		'checkin_staff' => 'retention_checkin_staff_days',
		'audit_logs' => 'retention_audit_logs_days',
		'waitlist' => 'retention_waitlist_days',
		'partner_ticket_codes' => 'retention_partner_codes_days',
	);

	/** Days of one step: setting (A123), then filter (wpcap_privacy_retention_days, $days, $step); 0 = off. */
	public static function days( $step ) {
		$defaults = array(
			'unpaid_attendee_data' => 1,
			'unpaid_customer_email' => 90,
			'message_content' => 30,
			'message_rows' => 365,
			'checkin_staff' => 365,
			'audit_logs' => 730,
			'waitlist' => 30,
			'partner_ticket_codes' => 30,
		);
		$days = $defaults[ $step ] ?? 0;
		if ( isset( self::SETTINGS[ $step ] ) ) {
			$configured = Settings::get( self::SETTINGS[ $step ], null );
			if ( null !== $configured && '' !== $configured ) { $days = (int) $configured; }
		}
		return max( 0, (int) apply_filters( 'wpcap_privacy_retention_days', $days, $step ) );
	}

	/** Years financial records are kept (setting retention_financial_years, filterable); 0 = forever. */
	public static function financial_years() {
		return max( 0, min( 30, (int) apply_filters( 'wpcap_financial_retention_years', (int) Settings::get( 'retention_financial_years', 0 ) ) ) );
	}

	/**
	 * Documents issued before this UTC time may be deleted ('' = keep everything). With N years, a
	 * document from year Y may go once Y + N has ended: cutoff = 1 January (current year - N).
	 *
	 * @param int $now Reference time (UNIX).
	 */
	public static function financial_cutoff( $now ) {
		$years = self::financial_years();
		if ( ! $years ) { return ''; }
		return sprintf( '%04d-01-01 00:00:00', (int) gmdate( 'Y', (int) $now ) - $years );
	}

	/** Global attendee retention (setting); 0 = per-field setting for answers, default for names. */
	public static function attendee_days() {
		return max( 0, min( 3650, (int) Settings::get( 'attendee_retention_days' ) ) );
	}

	/** Attendee name/e-mail retention when the setting is 0. */
	public static function default_attendee_days() {
		return max( 0, (int) apply_filters( 'wpcap_attendee_default_retention_days', 365 ) );
	}

	/**
	 * Runs all steps. Returns the counts per step.
	 *
	 * @param int|null $now    Reference time (UNIX, tests).
	 * @param float    $budget Seconds before the run stops (filter wpcap_privacy_retention_budget).
	 */
	public static function run( $now = null, $budget = null ) {
		$now = null === $now || '' === $now ? time() : (int) $now;
		$budget = null === $budget ? (float) apply_filters( 'wpcap_privacy_retention_budget', 20.0 ) : (float) $budget;
		$deadline = microtime( true ) + max( 0.0, $budget );
		$counts = array();
		foreach ( array( 'answers', 'attendees', 'unpaid_attendee_data', 'unpaid_customer_email', 'messages', 'checkin_staff', 'audit_logs', 'financial_records' ) as $step ) {
			$counts[ $step ] = microtime( true ) < $deadline ? (int) call_user_func( array( __CLASS__, 'step_' . $step ), $now, $deadline ) : 0;
		}
		/**
		 * Module retention steps (guest lists, waitlist, buyer limits, …): add counts per step name.
		 *
		 * @param array $counts   Counts so far.
		 * @param int   $now      Reference time.
		 * @param float $deadline microtime() limit.
		 */
		$counts = (array) apply_filters( 'wpcap_privacy_retention_steps', $counts, $now, $deadline );
		$counts['complete'] = microtime( true ) < $deadline;
		if ( array_sum( array_map( 'intval', array_diff_key( $counts, array( 'complete' => 1 ) ) ) ) > 0 ) {
			Audit::record( 'privacy_retention', 'system', 0, $counts );
		}
		return $counts;
	}

	private static function utc( $ts ) { return gmdate( 'Y-m-d H:i:s', (int) $ts ); }

	/** SQL for the end of an occurrence (alias o). */
	private static function end_sql() { return 'COALESCE(o.ends_at, DATE_ADD(o.starts_at, INTERVAL 1 DAY))'; }

	/** Repeats $select (returns ids) / $apply (ids) until done or out of time. */
	private static function loop( $deadline, callable $select, callable $apply ) {
		$total = 0;
		while ( microtime( true ) < $deadline ) {
			$ids = array_values( array_filter( array_map( 'absint', (array) $select() ) ) );
			if ( ! $ids ) { break; }
			$changed = (int) $apply( $ids );
			$total += $changed;
			// A batch that changed nothing would be selected again: stop instead of spinning.
			if ( count( $ids ) < self::BATCH || ! $changed ) { break; }
		}
		return $total;
	}

	private static function in_list( array $ids ) { return implode( ',', array_map( 'absint', $ids ) ) ?: '0'; }

	private static function step_answers( $now, $deadline ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$global = self::attendee_days();
		$end = self::end_sql();
		$now_sql = self::utc( $now );
		$days = $global > 0 ? (string) (int) $global : 'COALESCE(f.retention_days, ' . (int) self::default_attendee_days() . ')';
		// Index-friendly pre-filter: an occurrence can only be due if it started before now - min(N).
		$min = $global > 0 ? $global : (int) $wpdb->get_var( "SELECT COALESCE(MIN(retention_days),0) FROM {$p}wpcap_participant_fields" );
		$min = min( $min, $global > 0 ? $global : self::default_attendee_days() );
		$start_before = self::utc( $now - $min * DAY_IN_SECONDS );
		return self::loop(
			$deadline,
			static function () use ( $wpdb, $p, $end, $days, $now_sql, $start_before ) {
				return $wpdb->get_col( $wpdb->prepare( "SELECT a.answer_id FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_ticket_types tt ON tt.occurrence_id=o.occurrence_id INNER JOIN {$p}wpcap_tickets t ON t.ticket_type_id=tt.ticket_type_id INNER JOIN {$p}wpcap_participant_answers a ON a.ticket_id=t.ticket_id LEFT JOIN {$p}wpcap_participant_fields f ON f.field_id=a.field_id WHERE o.starts_at<%s AND DATE_ADD($end, INTERVAL $days DAY)<%s LIMIT %d", $start_before, $now_sql, self::BATCH ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer/static SQL.
			},
			static function ( $ids ) use ( $wpdb, $p ) {
				return (int) $wpdb->query( "DELETE FROM {$p}wpcap_participant_answers WHERE answer_id IN (" . self::in_list( $ids ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		);
	}

	private static function step_attendees( $now, $deadline ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$days = self::attendee_days() > 0 ? self::attendee_days() : self::default_attendee_days();
		$end = self::end_sql();
		$cutoff = self::utc( $now - $days * DAY_IN_SECONDS );
		$from = "{$p}wpcap_occurrences o INNER JOIN {$p}wpcap_ticket_types tt ON tt.occurrence_id=o.occurrence_id INNER JOIN {$p}wpcap_tickets t ON t.ticket_type_id=tt.ticket_type_id";
		$count = self::loop(
			$deadline,
			static function () use ( $wpdb, $p, $from, $end, $cutoff ) {
				return $wpdb->get_col( $wpdb->prepare( "SELECT ta.assignment_id FROM $from INNER JOIN {$p}wpcap_ticket_assignments ta ON ta.ticket_id=t.ticket_id WHERE o.starts_at<%s AND $end<%s AND (ta.attendee_name<>'' OR ta.attendee_email<>'') LIMIT %d", $cutoff, $cutoff, self::BATCH ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			},
			static function ( $ids ) use ( $wpdb, $p ) {
				return (int) $wpdb->query( "UPDATE {$p}wpcap_ticket_assignments SET attendee_name='',attendee_email='' WHERE assignment_id IN (" . self::in_list( $ids ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		);
		$count += self::loop(
			$deadline,
			static function () use ( $wpdb, $from, $end, $cutoff ) {
				return $wpdb->get_col( $wpdb->prepare( "SELECT t.ticket_id FROM $from WHERE o.starts_at<%s AND $end<%s AND t.attendee_email<>'' LIMIT %d", $cutoff, $cutoff, self::BATCH ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			},
			static function ( $ids ) use ( $wpdb, $p ) {
				return (int) $wpdb->query( "UPDATE {$p}wpcap_tickets SET attendee_email='' WHERE ticket_id IN (" . self::in_list( $ids ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		);
		$occurrences = $wpdb->get_col( $wpdb->prepare( "SELECT o.occurrence_id FROM {$p}wpcap_occurrences o WHERE o.starts_at<%s AND $end<%s", $cutoff, $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $occurrences && Privacy::has_table( 'wpcap_offline_entries' ) ) {
			foreach ( array_chunk( $occurrences, self::BATCH ) as $chunk ) {
				if ( microtime( true ) >= $deadline ) { break; }
				$count += (int) $wpdb->query( "UPDATE {$p}wpcap_offline_entries SET attendee_name='' WHERE attendee_name<>'' AND occurrence_id IN (" . self::in_list( $chunk ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
		if ( Privacy::has_table( 'wpcap_wallet_registrations' ) ) {
			$count += self::loop(
				$deadline,
				static function () use ( $wpdb, $p, $from, $end, $cutoff ) {
					return $wpdb->get_col( $wpdb->prepare( "SELECT r.registration_id FROM $from INNER JOIN {$p}wpcap_wallet_passes w ON w.ticket_id=t.ticket_id INNER JOIN {$p}wpcap_wallet_registrations r ON r.serial_number=w.serial_number WHERE o.starts_at<%s AND $end<%s LIMIT %d", $cutoff, $cutoff, self::BATCH ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				},
				static function ( $ids ) use ( $wpdb, $p ) {
					return (int) $wpdb->query( "DELETE FROM {$p}wpcap_wallet_registrations WHERE registration_id IN (" . self::in_list( $ids ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
			);
		}
		return $count;
	}

	/** Pending attendee details of orders that will never issue tickets. */
	private static function step_unpaid_attendee_data( $now, $deadline ) {
		$days = self::days( 'unpaid_attendee_data' );
		if ( ! $days ) { return 0; }
		global $wpdb;
		$p = $wpdb->prefix;
		$cutoff = self::utc( $now - $days * DAY_IN_SECONDS );
		return self::loop(
			$deadline,
			static function () use ( $wpdb, $p, $cutoff ) {
				return $wpdb->get_col( $wpdb->prepare( "SELECT order_id FROM {$p}wpcap_orders WHERE attendee_data IS NOT NULL AND payment_status IN ('expired','cancelled','failed') AND updated_at<%s LIMIT %d", $cutoff, self::BATCH ) );
			},
			static function ( $ids ) use ( $wpdb, $p ) {
				return (int) $wpdb->query( "UPDATE {$p}wpcap_orders SET attendee_data=NULL WHERE order_id IN (" . self::in_list( $ids ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		);
	}

	/** Never-paid orders (no ticket, no invoice): buyer e-mail replaced by the erasure placeholder. */
	/**
	 * Financial records past the statutory period (A123): invoice lines, invoices and correction
	 * documents; the orders' buyer data (e-mail, billing address, access link) is pseudonymised.
	 */
	private static function step_financial_records( $now, $deadline ) {
		$cutoff = self::financial_cutoff( $now );
		if ( '' === $cutoff ) { return 0; }
		global $wpdb;
		$p = $wpdb->prefix;
		$credit = self::loop(
			$deadline,
			static function () use ( $wpdb, $p, $cutoff ) {
				return $wpdb->get_col( $wpdb->prepare( "SELECT credit_note_id FROM {$p}wpcap_credit_notes WHERE issued_at<%s LIMIT %d", $cutoff, self::BATCH ) );
			},
			static function ( $ids ) use ( $wpdb, $p ) {
				return (int) $wpdb->query( "DELETE FROM {$p}wpcap_credit_notes WHERE credit_note_id IN (" . self::in_list( $ids ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		);
		$invoices = self::loop(
			$deadline,
			static function () use ( $wpdb, $p, $cutoff ) {
				// Only invoices whose corrections are gone too (a later correction keeps its invoice).
				return $wpdb->get_col( $wpdb->prepare( "SELECT i.invoice_id FROM {$p}wpcap_invoices i WHERE i.issued_at<%s AND NOT EXISTS (SELECT 1 FROM {$p}wpcap_credit_notes c WHERE c.invoice_id=i.invoice_id) LIMIT %d", $cutoff, self::BATCH ) );
			},
			static function ( $ids ) use ( $wpdb, $p ) {
				$list = self::in_list( $ids );
				$orders = array_map( 'absint', (array) $wpdb->get_col( "SELECT order_id FROM {$p}wpcap_invoices WHERE invoice_id IN ($list)" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "DELETE FROM {$p}wpcap_invoice_lines WHERE invoice_id IN ($list)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$deleted = (int) $wpdb->query( "DELETE FROM {$p}wpcap_invoices WHERE invoice_id IN ($list)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT order_id,customer_email FROM ' . $p . 'wpcap_orders WHERE order_id IN (' . self::in_list( $orders ) . ') AND customer_email NOT LIKE %s', '%' . Privacy::PLACEHOLDER_DOMAIN ) ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
					$wpdb->update( $p . 'wpcap_orders', array( 'customer_email' => Privacy::replacement_email( (string) $row->customer_email, (int) $row->order_id ), 'billing' => null, 'access_token_hash' => hash( 'sha256', wp_generate_password( 64, true, true ) ), 'access_expires_at' => null ), array( 'order_id' => (int) $row->order_id ), array( '%s', '%s', '%s', '%s' ), array( '%d' ) );
				}
				return $deleted;
			}
		);
		return $credit + $invoices;
	}

	private static function step_unpaid_customer_email( $now, $deadline ) {
		$days = self::days( 'unpaid_customer_email' );
		if ( ! $days ) { return 0; }
		global $wpdb;
		$p = $wpdb->prefix;
		$cutoff = self::utc( $now - $days * DAY_IN_SECONDS );
		$like = '%' . Privacy::PLACEHOLDER_DOMAIN; // No LIKE wildcards in the domain.
		return self::loop(
			$deadline,
			static function () use ( $wpdb, $p, $cutoff, $like ) {
				return $wpdb->get_col( $wpdb->prepare( "SELECT ord.order_id FROM {$p}wpcap_orders ord WHERE ord.payment_status IN ('expired','cancelled','failed') AND ord.updated_at<%s AND ord.created_at<%s AND ord.customer_email NOT LIKE %s AND NOT EXISTS (SELECT 1 FROM {$p}wpcap_tickets t WHERE t.order_id=ord.order_id) AND NOT EXISTS (SELECT 1 FROM {$p}wpcap_invoices i WHERE i.order_id=ord.order_id) LIMIT %d", $cutoff, $cutoff, $like, self::BATCH ) );
			},
			static function ( $ids ) use ( $wpdb, $p ) {
				$done = 0;
				foreach ( $wpdb->get_results( 'SELECT order_id,customer_email FROM ' . $p . 'wpcap_orders WHERE order_id IN (' . self::in_list( $ids ) . ')' ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$done += (int) $wpdb->update( $p . 'wpcap_orders', array( 'customer_email' => Privacy::replacement_email( (string) $row->customer_email, (int) $row->order_id ), 'access_token_hash' => hash( 'sha256', wp_generate_password( 64, true, true ) ), 'access_expires_at' => null, 'attendee_data' => null ), array( 'order_id' => (int) $row->order_id ), array( '%s', '%s', '%s', '%s' ), array( '%d' ) );
				}
				return $done;
			}
		);
	}

	/** Finished queue messages: content after 30 days, rows after a year (dedupe keys stay until then). */
	private static function step_messages( $now, $deadline ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$count = 0;
		$content = self::days( 'message_content' );
		if ( $content ) {
			$cutoff = self::utc( $now - $content * DAY_IN_SECONDS );
			$count += self::loop(
				$deadline,
				static function () use ( $wpdb, $p, $cutoff ) {
					return $wpdb->get_col( $wpdb->prepare( "SELECT message_id FROM {$p}wpcap_message_queue WHERE status IN ('sent','failed','cancelled') AND send_at<%s AND created_at<%s AND (recipient<>'' OR payload<>'{}') LIMIT %d", $cutoff, $cutoff, self::BATCH ) );
				},
				static function ( $ids ) use ( $wpdb, $p ) {
					return (int) $wpdb->query( "UPDATE {$p}wpcap_message_queue SET recipient='',payload='{}',last_error=NULL WHERE message_id IN (" . self::in_list( $ids ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
			);
		}
		$rows = self::days( 'message_rows' );
		if ( $rows ) {
			$cutoff = self::utc( $now - $rows * DAY_IN_SECONDS );
			$count += self::loop(
				$deadline,
				static function () use ( $wpdb, $p, $cutoff ) {
					return $wpdb->get_col( $wpdb->prepare( "SELECT message_id FROM {$p}wpcap_message_queue WHERE status IN ('sent','failed','cancelled') AND send_at<%s AND created_at<%s LIMIT %d", $cutoff, $cutoff, self::BATCH ) );
				},
				static function ( $ids ) use ( $wpdb, $p ) {
					return (int) $wpdb->query( "DELETE FROM {$p}wpcap_message_queue WHERE message_id IN (" . self::in_list( $ids ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
			);
		}
		return $count;
	}

	/** Old check-ins keep ticket, time and result but lose the scanning staff user and device. */
	private static function step_checkin_staff( $now, $deadline ) {
		$days = self::days( 'checkin_staff' );
		if ( ! $days ) { return 0; }
		global $wpdb;
		$p = $wpdb->prefix;
		$cutoff = self::utc( $now - $days * DAY_IN_SECONDS );
		return self::loop(
			$deadline,
			static function () use ( $wpdb, $p, $cutoff ) {
				return $wpdb->get_col( $wpdb->prepare( "SELECT checkin_id FROM {$p}wpcap_checkins WHERE created_at<%s AND (user_id<>0 OR device_id<>0) LIMIT %d", $cutoff, self::BATCH ) );
			},
			static function ( $ids ) use ( $wpdb, $p ) {
				return (int) $wpdb->query( "UPDATE {$p}wpcap_checkins SET user_id=0,device_id=0 WHERE checkin_id IN (" . self::in_list( $ids ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		);
	}

	private static function step_audit_logs( $now, $deadline ) {
		$days = self::days( 'audit_logs' );
		if ( ! $days ) { return 0; }
		global $wpdb;
		$p = $wpdb->prefix;
		$cutoff = self::utc( $now - $days * DAY_IN_SECONDS );
		return self::loop(
			$deadline,
			static function () use ( $wpdb, $p, $cutoff ) {
				return $wpdb->get_col( $wpdb->prepare( "SELECT audit_id FROM {$p}wpcap_audit_logs WHERE created_at<%s ORDER BY audit_id LIMIT %d", $cutoff, self::BATCH ) );
			},
			static function ( $ids ) use ( $wpdb, $p ) {
				return (int) $wpdb->query( "DELETE FROM {$p}wpcap_audit_logs WHERE audit_id IN (" . self::in_list( $ids ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		);
	}
}

<?php
/**
 * Module: box office ("Kasse") with registers, shifts (cash sessions), handover, Z reports, fast
 * sales, receipts and storno. Code lives in src/BoxOffice/.
 *
 * Tables: wpcap_boxoffice_registers (Kassen/Schubladen), wpcap_boxoffice_sessions (shifts; the
 * historic table name is kept), wpcap_boxoffice_shift_cashiers, wpcap_boxoffice_counts (insert-only
 * cash counts with denominations), wpcap_boxoffice_movements (insert-only cash in/out, refund
 * payouts, corrections), wpcap_boxoffice_handovers and wpcap_boxoffice_zreports (insert-only,
 * hash-chained Z reports with a gapless number per register).
 */
namespace PassCaptain\Modules;

use PassCaptain\BoxOffice\Page;
use PassCaptain\BoxOffice\PosGateway;
use PassCaptain\BoxOffice\Registers;
use PassCaptain\BoxOffice\Service;
use PassCaptain\BoxOffice\ShiftPage;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class BoxOffice {
	public static function register() {
		add_filter( 'wpcap_payment_gateways', array( PosGateway::class, 'register_gateways' ) );
		add_action( 'wpcap_refund_completed', array( Service::class, 'on_refund_completed' ), 10, 2 );
		add_action( 'wpcap_refund_rejected', array( Service::class, 'on_refund_rejected' ), 10, 2 );
		add_filter( 'pre_wp_mail', array( Service::class, 'suppress_placeholder_mail' ), 10, 2 );
		add_action( 'wpcap_boxoffice_shift_opened', array( \PassCaptain\BoxOffice\Shifts::class, 'book_deferred' ), 10, 1 );
		// Schema fixes dbDelta cannot apply: after every schema install/upgrade and once on existing installs.
		add_action( 'add_option_wpcap_db_version', array( __CLASS__, 'migrate' ), 10, 0 );
		add_action( 'update_option_wpcap_db_version', array( __CLASS__, 'migrate' ), 10, 0 );
		add_action( 'init', array( __CLASS__, 'maybe_migrate' ), 5, 0 );
		// Card-terminal payments (only used when an add-on registers a device): status poll and cancel.
		add_action( 'wp_ajax_wpcap_pos_device_status', array( Page::class, 'ajax_device_status' ) );
		add_action( 'wp_ajax_wpcap_pos_device_cancel', array( Page::class, 'ajax_device_cancel' ) );
		add_filter( 'wpcap_privacy_retention_steps', array( __CLASS__, 'retention_step' ), 10, 3 );
	}

	/**
	 * Financial retention (A123): closed shifts whose closing year plus the configured number of years
	 * (setting retention_financial_years, 0 = keep) has ended are deleted with their Z report, counts,
	 * cash movements, handovers, cashier list and sale receipts. Orders stay (core retention).
	 *
	 * @param array      $counts   Counts so far.
	 * @param int        $now      Reference time.
	 * @param float|null $deadline microtime() limit.
	 */
	public static function retention_step( $counts, $now = null, $deadline = null ) {
		$counts = (array) $counts;
		$cutoff = \PassCaptain\Privacy\Retention::financial_cutoff( null === $now ? \PassCaptain\Clock::now() : (int) $now );
		if ( '' === $cutoff || ! \PassCaptain\Privacy::has_table( 'wpcap_boxoffice_sessions' ) ) { return $counts; }
		global $wpdb;
		$p = $wpdb->prefix;
		$done = 0;
		while ( null === $deadline || microtime( true ) < $deadline ) {
			$ids = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( "SELECT session_id FROM {$p}wpcap_boxoffice_sessions WHERE status<>'open' AND closed_at IS NOT NULL AND closed_at<%s LIMIT 200", $cutoff ) ) );
			if ( ! $ids ) { break; }
			$list = implode( ',', $ids );
			$tables = array( 'zreports' => 'shift_id', 'counts' => 'shift_id', 'movements' => 'shift_id', 'shift_cashiers' => 'shift_id', 'handovers' => 'from_shift_id', 'sales' => 'session_id' );
			if ( \PassCaptain\BoxOffice\Shifts::payouts_ready() ) { $tables['payouts'] = 'shift_id'; } // Paid payouts (A488).
			if ( \PassCaptain\Privacy::has_table( 'wpcap_boxoffice_refunds' ) ) { $tables['refunds'] = 'session_id'; } // Earlier refund bookings per session.
			foreach ( $tables as $table => $column ) {
				$wpdb->query( "DELETE FROM {$p}wpcap_boxoffice_{$table} WHERE {$column} IN ($list)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ints, fixed names.
			}
			$done += (int) $wpdb->query( "DELETE FROM {$p}wpcap_boxoffice_sessions WHERE session_id IN ($list)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( count( $ids ) < 200 ) { break; }
		}
		$counts['boxoffice_shifts'] = (int) ( $counts['boxoffice_shifts'] ?? 0 ) + $done;
		return $counts;
	}

	/** Version of the box office data migrations (option wpcap_boxoffice_migration). */
	const MIGRATION_VERSION = 4;

	/** Runs migrate() once per MIGRATION_VERSION (cheap autoloaded option check). */
	public static function maybe_migrate() {
		if ( (int) get_option( 'wpcap_boxoffice_migration', 0 ) >= self::MIGRATION_VERSION ) { return false; }
		// Under the installer's upgrade lock: parallel first requests after an update must not migrate
		// the same legacy sessions twice. Without the lock another request is migrating – try later.
		$result = false;
		\PassCaptain\Installer::run_locked( static function () use ( &$result ) {
			$result = (int) get_option( 'wpcap_boxoffice_migration', 0 ) >= self::MIGRATION_VERSION ? false : self::migrate();
		} );
		return $result;
	}

	/**
	 * Fixes for existing tables that dbDelta does not apply (it never changes NULL/NOT NULL):
	 * wpcap_boxoffice_sales.order_id must be NULL while a sale has no order yet. Older installs had
	 * NOT NULL, so an unfinished sale stored order_id=0 and blocked every later sale through the
	 * unique key. Legacy 0 values become NULL; pending rows without an order (claims of failed
	 * sales) older than the order hold are deleted.
	 *
	 * Version 2: cash sessions from before registers existed (register_id 0) are mapped to a default
	 * register; closed ones get Z reports (source "migration", numbered in closing order), open ones
	 * keep running as the open shift of a register (see Registers::migrate_legacy_sessions()).
	 * Version 3: location and fiscal register of a session (TerminalRegistry) and the state of
	 * asynchronous card-terminal payments.
	 * Version 4 (merge of both development lines): the string register of the fiscal scope is stored
	 * as fiscal_register_id; register_id is the numeric register (wpcap_boxoffice_registers). Installs
	 * that stored the string in register_id are converted by rename_legacy_register_column(), which
	 * also runs before dbDelta (see schema()).
	 * Idempotent.
	 *
	 * @return bool True when the table exists and is up to date.
	 */
	public static function migrate() {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcap_boxoffice_sales';
		$column = $wpdb->get_row( "SHOW COLUMNS FROM `{$table}` LIKE 'order_id'" );
		if ( ! $column ) { return false; } // Table not installed yet.
		$column = (array) $column;
		if ( 'YES' !== strtoupper( (string) ( $column['Null'] ?? '' ) ) ) {
			if ( false === $wpdb->query( "ALTER TABLE `{$table}` MODIFY order_id bigint(20) unsigned NULL" ) ) { return false; }
		}
		$wpdb->query( "UPDATE `{$table}` SET order_id=NULL WHERE order_id=0" );
		$session_table = $wpdb->prefix . 'wpcap_boxoffice_sessions';
		if ( false === self::rename_legacy_register_column() ) { return false; }
		foreach ( array(
			'register_id' => 'bigint(20) unsigned NOT NULL DEFAULT 0 AFTER session_id',
			'location_id' => 'bigint(20) unsigned NOT NULL DEFAULT 0 AFTER currency',
			'fiscal_register_id' => "varchar(64) NOT NULL DEFAULT '' AFTER location_id",
		) as $name => $definition ) {
			if ( ! $wpdb->get_row( "SHOW COLUMNS FROM `{$session_table}` LIKE '{$name}'" ) && false === $wpdb->query( "ALTER TABLE `{$session_table}` ADD COLUMN {$name} {$definition}" ) ) { return false; } // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- own table, constant column names/definitions from the array above.
		}
		$register_table = $wpdb->prefix . 'wpcap_boxoffice_registers';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $register_table ) ) ) {
			foreach ( array(
				'location_id' => 'bigint(20) unsigned NOT NULL DEFAULT 0 AFTER location',
				'fiscal_register_id' => "varchar(64) NOT NULL DEFAULT '' AFTER location_id",
			) as $name => $definition ) {
				if ( ! $wpdb->get_row( "SHOW COLUMNS FROM `{$register_table}` LIKE '{$name}'" ) && false === $wpdb->query( "ALTER TABLE `{$register_table}` ADD COLUMN {$name} {$definition}" ) ) { return false; } // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- own table, constant column names/definitions from the array above.
			}
		}
		// Version 3: state of asynchronous card-terminal payments.
		foreach ( array(
			'device_id' => "varchar(40) NOT NULL DEFAULT '' AFTER payment_method",
			'device_reference' => "varchar(191) NOT NULL DEFAULT '' AFTER device_id",
			'device_status' => "varchar(20) NOT NULL DEFAULT '' AFTER device_reference",
			'device_message' => "varchar(255) NOT NULL DEFAULT '' AFTER device_status",
			'device_started_at' => 'datetime NULL AFTER device_message',
		) as $name => $definition ) {
			if ( ! $wpdb->get_row( "SHOW COLUMNS FROM `{$table}` LIKE '{$name}'" ) && false === $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN {$name} {$definition}" ) ) { return false; } // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- own table, constant column names/definitions from the array above.
		}

		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE status='pending' AND order_id IS NULL AND created_at < %s", gmdate( 'Y-m-d H:i:s', \PassCaptain\Clock::now() - 30 * 60 ) ) );
		if ( ! Registers::migrate_legacy_sessions() ) { return false; }
		update_option( 'wpcap_boxoffice_migration', self::MIGRATION_VERSION );
		return true;
	}

	/**
	 * One development line stored the fiscal register (string, TerminalRegistry) in
	 * wpcap_boxoffice_sessions.register_id, the other uses register_id as numeric register id. Before
	 * dbDelta would convert such a varchar column to bigint (losing the values), it is renamed to
	 * fiscal_register_id. Idempotent and cheap after the first run.
	 *
	 * @return bool|null True when renamed, null when nothing to do, false on failure.
	 */
	public static function rename_legacy_register_column() {
		global $wpdb;
		static $done = false;
		if ( $done || ! isset( $wpdb ) || ! is_object( $wpdb ) ) { return null; }
		$table = $wpdb->prefix . 'wpcap_boxoffice_sessions';
		$suppress = $wpdb->suppress_errors( true );
		$column = $wpdb->get_row( "SHOW COLUMNS FROM `{$table}` LIKE 'register_id'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
		$wpdb->suppress_errors( $suppress );
		if ( ! $column || 0 !== stripos( (string) ( ( (array) $column )['Type'] ?? '' ), 'varchar' ) ) { $done = true; return null; }
		if ( $wpdb->get_row( "SHOW COLUMNS FROM `{$table}` LIKE 'fiscal_register_id'" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
			// Both exist (interrupted earlier run): keep the values, drop the string column so dbDelta adds the numeric one.
			if ( false === $wpdb->query( "UPDATE `{$table}` SET fiscal_register_id=register_id WHERE fiscal_register_id='' AND register_id<>''" ) || false === $wpdb->query( "ALTER TABLE `{$table}` DROP COLUMN register_id" ) ) { return false; } // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- own table.
		} elseif ( false === $wpdb->query( "ALTER TABLE `{$table}` CHANGE COLUMN register_id fiscal_register_id varchar(64) NOT NULL DEFAULT ''" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- own table.
			return false;
		}
		$done = true;
		return true;
	}

	public static function schema( $prefix, $charset ) {
		// Must run before dbDelta sees register_id as bigint (Installer builds the statements first).
		self::rename_legacy_register_column();
		return array(
			"CREATE TABLE {$prefix}wpcap_boxoffice_registers (
				register_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(100) NOT NULL,
				location varchar(190) NOT NULL DEFAULT '',
				location_id bigint(20) unsigned NOT NULL DEFAULT 0,
				fiscal_register_id varchar(64) NOT NULL DEFAULT '',
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				currency char(3) NOT NULL DEFAULT 'EUR',
				blind_count tinyint(1) unsigned NOT NULL DEFAULT 1,
				status varchar(20) NOT NULL DEFAULT 'active',
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (register_id),
				KEY status (status)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}wpcap_boxoffice_sessions (
				session_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				register_id bigint(20) unsigned NOT NULL DEFAULT 0,
				user_id bigint(20) unsigned NOT NULL,
				open_key bigint(20) unsigned NULL,
				open_register bigint(20) unsigned NULL,
				currency char(3) NOT NULL DEFAULT 'EUR',
				location_id bigint(20) unsigned NOT NULL DEFAULT 0,
				fiscal_register_id varchar(64) NOT NULL DEFAULT '',
				opening_float_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				blind_count tinyint(1) unsigned NOT NULL DEFAULT 0,
				handover_id bigint(20) unsigned NOT NULL DEFAULT 0,
				closing_count_minor bigint(20) unsigned NULL,
				expected_cash_minor bigint(20) NULL,
				difference_minor bigint(20) NULL,
				counted_card_minor bigint(20) NULL,
				expected_card_minor bigint(20) NULL,
				card_difference_minor bigint(20) NULL,
				totals longtext NULL,
				note varchar(255) NOT NULL DEFAULT '',
				closing_note varchar(255) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'open',
				opened_at datetime NOT NULL,
				closed_at datetime NULL,
				closed_by bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (session_id),
				UNIQUE KEY open_key (open_key),
				UNIQUE KEY open_register (open_register),
				KEY user_status (user_id,status),
				KEY register_status (register_id,status)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}wpcap_boxoffice_shift_cashiers (
				shift_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				role varchar(20) NOT NULL DEFAULT 'cashier',
				added_by bigint(20) unsigned NOT NULL DEFAULT 0,
				added_at datetime NOT NULL,
				PRIMARY KEY  (shift_id,user_id),
				KEY user_id (user_id)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}wpcap_boxoffice_counts (
				count_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				shift_id bigint(20) unsigned NOT NULL,
				register_id bigint(20) unsigned NOT NULL DEFAULT 0,
				kind varchar(20) NOT NULL,
				cash_minor bigint(20) NOT NULL DEFAULT 0,
				denominations text NULL,
				card_minor bigint(20) NULL,
				expected_cash_minor bigint(20) NULL,
				expected_card_minor bigint(20) NULL,
				counted_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (count_id),
				KEY shift_kind (shift_id,kind)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}wpcap_boxoffice_movements (
				movement_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				shift_id bigint(20) unsigned NOT NULL,
				register_id bigint(20) unsigned NOT NULL DEFAULT 0,
				kind varchar(20) NOT NULL,
				method varchar(20) NOT NULL DEFAULT 'cash',
				currency char(3) NOT NULL DEFAULT 'EUR',
				amount_minor bigint(20) NOT NULL DEFAULT 0,
				tax_minor bigint(20) NOT NULL DEFAULT 0,
				vat text NULL,
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				sale_id bigint(20) unsigned NOT NULL DEFAULT 0,
				refund_id bigint(20) unsigned NULL,
				z_id bigint(20) unsigned NOT NULL DEFAULT 0,
				receipt_number varchar(40) NULL,
				ticket_ids text NULL,
				reason varchar(255) NOT NULL DEFAULT '',
				override_reason varchar(255) NOT NULL DEFAULT '',
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (movement_id),
				UNIQUE KEY refund_id (refund_id),
				UNIQUE KEY receipt_number (receipt_number),
				KEY shift_kind (shift_id,kind),
				KEY sale_id (sale_id)
			) ENGINE=InnoDB $charset;",
			// A488: refunds of cash/card sales from the back office, paid out later at a till.
			"CREATE TABLE {$prefix}wpcap_boxoffice_payouts (
				payout_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				refund_id bigint(20) unsigned NOT NULL,
				order_id bigint(20) unsigned NOT NULL,
				sale_id bigint(20) unsigned NOT NULL DEFAULT 0,
				register_id bigint(20) unsigned NOT NULL DEFAULT 0,
				currency char(3) NOT NULL DEFAULT 'EUR',
				method varchar(20) NOT NULL DEFAULT 'cash',
				amount_minor bigint(20) NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'open',
				shift_id bigint(20) unsigned NOT NULL DEFAULT 0,
				movement_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				paid_by bigint(20) unsigned NOT NULL DEFAULT 0,
				paid_at datetime NULL,
				PRIMARY KEY  (payout_id),
				UNIQUE KEY refund_id (refund_id),
				KEY status (status,currency)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}wpcap_boxoffice_handovers (
				handover_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				register_id bigint(20) unsigned NOT NULL,
				from_shift_id bigint(20) unsigned NOT NULL,
				from_user bigint(20) unsigned NOT NULL,
				to_user bigint(20) unsigned NOT NULL,
				amount_minor bigint(20) NOT NULL DEFAULT 0,
				denominations text NULL,
				status varchar(20) NOT NULL DEFAULT 'pending',
				to_shift_id bigint(20) unsigned NOT NULL DEFAULT 0,
				received_minor bigint(20) NULL,
				difference_minor bigint(20) NULL,
				reason varchar(255) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				decided_at datetime NULL,
				decided_by bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (handover_id),
				UNIQUE KEY from_shift_id (from_shift_id),
				KEY register_status (register_id,status),
				KEY to_user_status (to_user,status)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}wpcap_boxoffice_zreports (
				z_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				register_id bigint(20) unsigned NOT NULL,
				z_number bigint(20) unsigned NOT NULL,
				shift_id bigint(20) unsigned NOT NULL,
				source varchar(20) NOT NULL DEFAULT 'close',
				currency char(3) NOT NULL DEFAULT 'EUR',
				opened_at datetime NOT NULL,
				closed_at datetime NOT NULL,
				opened_by bigint(20) unsigned NOT NULL DEFAULT 0,
				closed_by bigint(20) unsigned NOT NULL DEFAULT 0,
				net_total_minor bigint(20) NOT NULL DEFAULT 0,
				grand_total_minor bigint(20) NOT NULL DEFAULT 0,
				counted_cash_minor bigint(20) NULL,
				expected_cash_minor bigint(20) NULL,
				cash_difference_minor bigint(20) NULL,
				counted_card_minor bigint(20) NULL,
				expected_card_minor bigint(20) NULL,
				card_difference_minor bigint(20) NULL,
				difference_reason varchar(255) NOT NULL DEFAULT '',
				totals longtext NOT NULL,
				prev_hash char(64) NOT NULL DEFAULT '',
				hash char(64) NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (z_id),
				UNIQUE KEY register_number (register_id,z_number),
				UNIQUE KEY shift_id (shift_id)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}wpcap_boxoffice_sales (
				sale_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				session_id bigint(20) unsigned NOT NULL,
				order_id bigint(20) unsigned NULL,
				form_key_hash char(64) NOT NULL,
				receipt_number varchar(40) NULL,
				payment_method varchar(20) NOT NULL,
				device_id varchar(40) NOT NULL DEFAULT '',
				device_reference varchar(191) NOT NULL DEFAULT '',
				device_status varchar(20) NOT NULL DEFAULT '',
				device_message varchar(255) NOT NULL DEFAULT '',
				device_started_at datetime NULL,
				currency char(3) NOT NULL,
				total_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				tax_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				tendered_minor bigint(20) unsigned NULL,
				occurrence_id bigint(20) unsigned NOT NULL DEFAULT 0,
				cashier_id bigint(20) unsigned NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'completed',
				storno_refund_id bigint(20) unsigned NOT NULL DEFAULT 0,
				storno_receipt_number varchar(40) NULL,
				storno_attempt smallint(5) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				voided_at datetime NULL,
				voided_by bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (sale_id),
				UNIQUE KEY order_id (order_id),
				UNIQUE KEY form_key_hash (form_key_hash),
				UNIQUE KEY receipt_number (receipt_number),
				UNIQUE KEY storno_receipt_number (storno_receipt_number),
				KEY session_status (session_id,status),
				KEY created_at (created_at)
			) ENGINE=InnoDB $charset;",
			// Refunds of box office orders other than stornos, booked into a register session (PAY-V4,
			// Service::book_refund()); session_id 0 = refunded after the session closed, no open session.
			"CREATE TABLE {$prefix}wpcap_boxoffice_refunds (
				refund_id bigint(20) unsigned NOT NULL,
				sale_id bigint(20) unsigned NOT NULL,
				session_id bigint(20) unsigned NOT NULL DEFAULT 0,
				payment_method varchar(20) NOT NULL,
				amount_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				booked_at datetime NOT NULL,
				PRIMARY KEY  (refund_id),
				KEY session_method (session_id,payment_method),
				KEY sale_id (sale_id)
			) ENGINE=InnoDB $charset;",
		);
	}

	public static function settings_defaults() {
		return array(
			'boxoffice_receipt_prefix' => 'BON',
			'boxoffice_receipt_footer' => '',
			// Admin-managed fiscal/terminal registers "location_id|register_id|name" (TerminalRegistry).
			'boxoffice_terminals' => '',
			// How customer receipts name the cashier: operator number (default), initials or full name.
			'boxoffice_receipt_cashier' => 'id',
			// Cash/card without a certified fiscal solution (DE KassenSichV/TSE, AT RKSV) only after this explicit
			// confirmation (e.g. the sales are recorded in a separate certified cash register).
			'boxoffice_cash_confirmed' => 0,
			// SET-PC-39 shift rules: differences up to this amount (minor units) need no reason (0 = every
			// difference); 'amount' or 'denominations' count; receipt language ('' = language of the screen);
			// blind count preselected for new registers.
			'boxoffice_difference_tolerance_minor' => 0,
			'boxoffice_count_mode' => 'amount',
			'boxoffice_receipt_locale' => '',
			'boxoffice_blind_default' => 1,
			// With the fiscalization module: closing a shift also closes it at the fiscal provider (adapters with
			// ClosingAdapterInterface only). Off by default: no provider call that was not configured.
			'boxoffice_fiscal_closing' => 0,
		);
	}

	/** Settings register entries of this module (SET-PC-01, SettingsSchema). */
	public static function settings_schema() {
		return array(
			'boxoffice_receipt_prefix' => array( 'type' => 'prefix', 'label' => __( 'Präfix der Kassenbon-Nummern', 'pass-captain' ), 'help' => __( 'Vorangestellt vor die fortlaufende Bonnummer der Kasse.', 'pass-captain' ), 'page' => 'wpcap-boxoffice' ),
			'boxoffice_receipt_footer' => array( 'type' => 'textarea', 'label' => __( 'Fußzeile des Kassenbons', 'pass-captain' ), 'help' => __( 'Text unter jedem Kassenbon, z. B. Steuer- oder Rückgabehinweise.', 'pass-captain' ), 'page' => 'wpcap-boxoffice' ),
			'boxoffice_terminals' => array( 'type' => 'textarea', 'label' => __( 'Kassenterminals', 'pass-captain' ), 'help' => __( 'Kartenterminals der Kasse, eines je Zeile.', 'pass-captain' ), 'page' => 'wpcap-boxoffice' ),
			'boxoffice_receipt_cashier' => array( 'type' => 'choice', 'options' => array( 'id' => __( 'Bedienernummer', 'pass-captain' ), 'initials' => __( 'Initialen', 'pass-captain' ), 'name' => __( 'Vollständiger Name', 'pass-captain' ) ), 'label' => __( 'Kassierer auf dem Bon', 'pass-captain' ), 'help' => __( 'Ob und wie die Person an der Kasse auf dem Bon erscheint.', 'pass-captain' ), 'page' => 'wpcap-boxoffice' ),
			'boxoffice_cash_confirmed' => array( 'type' => 'bool', 'label' => __( 'Barzahlung ohne Fiskalisierung bestätigt', 'pass-captain' ), 'help' => __( 'Bestätigung, dass die Kasse ohne angeschlossene Fiskalisierung bar kassieren darf.', 'pass-captain' ), 'page' => 'wpcap-boxoffice' ),
			'boxoffice_difference_tolerance_minor' => array( 'type' => 'money', 'label' => __( 'Kassendifferenz ohne Begründung bis', 'pass-captain' ), 'help' => __( 'Beim Schichtabschluss und bei der Übergabe brauchen Differenzen bis zu diesem Betrag (in Kassenwährung, z. B. 0,50) keinen Grund; sie stehen trotzdem im Z-Bericht. 0 = jede Differenz braucht einen Grund.', 'pass-captain' ), 'page' => 'wpcap-boxoffice' ),
			'boxoffice_count_mode' => array( 'type' => 'choice', 'options' => array( 'amount' => __( 'Betrag oder Stückelung', 'pass-captain' ), 'denominations' => __( 'Stückelung Pflicht', 'pass-captain' ) ), 'label' => __( 'Zählpflicht', 'pass-captain' ), 'help' => __( '„Stückelung Pflicht“: Anfangsbestand, Abschlusszählung und Übergabe müssen nach Scheinen und Münzen gezählt werden (außer bei leerer Kasse).', 'pass-captain' ), 'page' => 'wpcap-boxoffice' ),
			'boxoffice_receipt_locale' => array( 'type' => 'text', 'label' => __( 'Sprache der Kassenbons', 'pass-captain' ), 'help' => __( 'Sprachcode wie de_DE oder en_US. Leer = Sprache der Kassenoberfläche.', 'pass-captain' ), 'page' => 'wpcap-boxoffice' ),
			'boxoffice_blind_default' => array( 'type' => 'bool', 'label' => __( 'Blindzählung als Standard für neue Kassen', 'pass-captain' ), 'help' => __( 'Neue Kassen werden mit Blindzählung vorgeschlagen; je Kasse änderbar.', 'pass-captain' ), 'page' => 'wpcap-boxoffice' ),
			'boxoffice_fiscal_closing' => array( 'type' => 'bool', 'label' => __( 'Schichtabschluss an den Fiskal-Anbieter melden', 'pass-captain' ), 'help' => __( 'Beim Kassenabschluss wird der Abschluss je Rechtsträger an den Fiskal-Anbieter übermittelt, sofern dessen Adapter das unterstützt. Ergebnis, Referenz und Signatur stehen im Z-Bericht und im Fiskal-Ereignisprotokoll. Nur mit aktivem Modul Fiskalisierung.', 'pass-captain' ), 'page' => 'wpcap-boxoffice' ),
		);
	}

	public static function admin_pages() {
		return array( array( Page::SLUG, __( 'Kasse', 'pass-captain' ), Service::CAP, array( Page::class, 'render' ) ) );
	}

	public static function admin_handlers() {
		return array(
			'wpcap_pos_open'         => array( ShiftPage::class, 'handle_open' ),
			'wpcap_pos_sell'         => array( Page::class, 'handle_sell' ),
			'wpcap_pos_storno'       => array( Page::class, 'handle_storno' ),
			'wpcap_pos_storno_reject' => array( Page::class, 'handle_storno_reject' ),
			'wpcap_pos_finalize'     => array( Page::class, 'handle_finalize' ),
			'wpcap_pos_close'        => array( ShiftPage::class, 'handle_close' ),
			'wpcap_pos_count'        => array( ShiftPage::class, 'handle_count' ),
			'wpcap_pos_movement'     => array( ShiftPage::class, 'handle_movement' ),
			'wpcap_pos_cashier'      => array( ShiftPage::class, 'handle_cashier' ),
			'wpcap_pos_handover'     => array( ShiftPage::class, 'handle_handover' ),
			'wpcap_pos_handover_cancel' => array( ShiftPage::class, 'handle_handover_cancel' ),
			'wpcap_pos_register'     => array( ShiftPage::class, 'handle_register' ),
			'wpcap_pos_correction'   => array( ShiftPage::class, 'handle_correction' ),
			'wpcap_pos_zreport'      => array( ShiftPage::class, 'handle_zreport' ),
			'wpcap_pos_zreport_csv'  => array( ShiftPage::class, 'handle_zreport_csv' ),
			'wpcap_pos_payout_receipt' => array( ShiftPage::class, 'handle_payout_receipt' ),
			'wpcap_pos_pay_out'      => array( ShiftPage::class, 'handle_pay_out' ),
			'wpcap_pos_receipt'      => array( Page::class, 'handle_receipt' ),
			'wpcap_pos_tickets'      => array( Page::class, 'handle_tickets' ),
			'wpcap_pos_availability' => array( Page::class, 'handle_availability' ),
			'wpcap_pos_settings'     => array( Page::class, 'handle_settings' ),
			'wpcap_pos_device_cancel' => array( Page::class, 'handle_device_cancel' ),
		);
	}
}

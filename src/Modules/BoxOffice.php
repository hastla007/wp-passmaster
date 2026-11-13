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
		// Schema fixes dbDelta cannot apply: after every schema install/upgrade and once on existing installs.
		add_action( 'add_option_wpcap_db_version', array( __CLASS__, 'migrate' ), 10, 0 );
		add_action( 'update_option_wpcap_db_version', array( __CLASS__, 'migrate' ), 10, 0 );
		add_action( 'init', array( __CLASS__, 'maybe_migrate' ), 5, 0 );
	}

	/** Version of the box office data migrations (option wpcap_boxoffice_migration). */
	const MIGRATION_VERSION = 2;

	/** Runs migrate() once per MIGRATION_VERSION (cheap autoloaded option check). */
	public static function maybe_migrate() {
		if ( (int) get_option( 'wpcap_boxoffice_migration', 0 ) >= self::MIGRATION_VERSION ) { return false; }
		return self::migrate();
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
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE status='pending' AND order_id IS NULL AND created_at < %s", gmdate( 'Y-m-d H:i:s', time() - 30 * 60 ) ) );
		if ( ! Registers::migrate_legacy_sessions() ) { return false; }
		update_option( 'wpcap_boxoffice_migration', self::MIGRATION_VERSION );
		return true;
	}

	public static function schema( $prefix, $charset ) {
		return array(
			"CREATE TABLE {$prefix}wpcap_boxoffice_registers (
				register_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(100) NOT NULL,
				location varchar(190) NOT NULL DEFAULT '',
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
		);
	}

	public static function settings_defaults() {
		return array(
			'boxoffice_receipt_prefix' => 'BON',
			'boxoffice_receipt_footer' => '',
			// Cash/card without a certified fiscal solution (DE KassenSichV/TSE, AT RKSV) only after this explicit
			// confirmation (e.g. the sales are recorded in a separate certified cash register).
			'boxoffice_cash_confirmed' => 0,
		);
	}

	public static function admin_pages() {
		return array( array( Page::SLUG, __( 'Kasse', 'wp-pass-captain' ), Service::CAP, array( Page::class, 'render' ) ) );
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
			'wpcap_pos_receipt'      => array( Page::class, 'handle_receipt' ),
			'wpcap_pos_tickets'      => array( Page::class, 'handle_tickets' ),
			'wpcap_pos_availability' => array( Page::class, 'handle_availability' ),
			'wpcap_pos_settings'     => array( Page::class, 'handle_settings' ),
		);
	}
}

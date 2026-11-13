<?php
/**
 * Module: partners ("Partner") – organisers/resellers linked to events, scoped partner API keys
 * (REST under pass-captain/v1/partner/…, Bearer wpcap_pk_…) and monthly partner settlements
 * ("Abrechnungen") with a gapless number series. Code lives in src/Partners/. API: docs/PARTNER-API.md.
 * Organizers and resellers can sell through the API (scopes orders:write, orders:cancel, tickets:issue;
 * src/Partners/Sales.php, SalesApi.php, Statement.php): price list, contingents, holds, cancellation window.
 * Sales model per organizer (own_name / agent) decides who issues customer invoices and whether the
 * settlement is a self-billing "Gutschrift" plus fee invoice or a "Provisionsrechnung": docs/SALES-MODELS.md.
 */
namespace PassCaptain\Modules;

use PassCaptain\Partners\AdminPage;
use PassCaptain\Partners\Api;
use PassCaptain\Partners\Sales;
use PassCaptain\Partners\SalesModel;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Partners {
	public static function register() {
		add_action( 'rest_api_init', array( Api::class, 'register_routes' ) );
		// Selling via the partner API (orders:write): order hook, code capture, placeholder mails.
		Sales::register();
		// Sales models (docs/SALES-MODELS.md): one order = one seller.
		add_action( 'wpcap_order_creating', array( SalesModel::class, 'guard_checkout' ), 10, 1 );
		// Usage rows of finished orders no longer count: removed, so the contingent check reads only
		// rows that may still count (open holds and paid sales).
		add_action( 'wpcap_capacity_released', array( \PassCaptain\Partners\Sales::class, 'prune_usage' ), 20, 1 );
		add_filter( 'wpcap_checkout_error_messages', array( SalesModel::class, 'checkout_messages' ) );
		// Stored partner ticket codes: gone with the ticket, the order's privacy erasure and after retention.
		add_action( 'wpcap_ticket_revoked', array( \PassCaptain\Partners\Sales::class, 'forget_code' ), 10, 1 );
		// Contact persons of partners: WordPress personal data export and erasure.
		add_filter( 'wp_privacy_personal_data_exporters', static function ( $exporters ) {
			$exporters['pass-captain-partners'] = array( 'exporter_friendly_name' => __( 'Pass Captain Veranstalter-Kontakte', 'pass-captain' ), 'callback' => array( \PassCaptain\Partners\Repository::class, 'privacy_export' ) );
			return $exporters;
		} );
		add_filter( 'wp_privacy_personal_data_erasers', static function ( $erasers ) {
			$erasers['pass-captain-partners'] = array( 'eraser_friendly_name' => __( 'Pass Captain Veranstalter-Kontakte', 'pass-captain' ), 'callback' => array( \PassCaptain\Partners\Repository::class, 'privacy_erase' ) );
			return $erasers;
		} );
		add_action( 'wpcap_privacy_order_anonymized', array( \PassCaptain\Partners\Sales::class, 'forget_order_codes' ), 10, 1 );
		add_filter( 'wpcap_privacy_retention_steps', array( \PassCaptain\Partners\Sales::class, 'retention_step' ), 10, 2 );
		// Data migration after every schema install/upgrade and once on existing installs (A448).
		add_action( 'add_option_wpcap_db_version', array( __CLASS__, 'migrate' ), 10, 0 );
		add_action( 'update_option_wpcap_db_version', array( __CLASS__, 'migrate' ), 10, 0 );
		add_action( 'init', array( __CLASS__, 'maybe_migrate' ), 5, 0 );
	}

	const MIGRATION_VERSION = 1;
	const MIGRATION_OPTION = 'wpcap_partners_migration';

	/** Runs migrate() once per MIGRATION_VERSION (cheap autoloaded option check), under the upgrade lock. */
	public static function maybe_migrate() {
		if ( (int) get_option( self::MIGRATION_OPTION, 0 ) >= self::MIGRATION_VERSION ) { return false; }
		$result = false;
		\PassCaptain\Installer::run_locked( static function () use ( &$result ) {
			$result = (int) get_option( self::MIGRATION_OPTION, 0 ) >= self::MIGRATION_VERSION ? false : self::migrate();
		} );
		return $result;
	}

	/**
	 * Version 1 (A448): settled source keys become globally unique, assignments get a history.
	 *  - every current event assignment without history gets an open history row since always,
	 *  - finalized voucher lines get the partner in their source key (a voucher claim is split per
	 *    organizer, every other item can be settled only once across all organizers),
	 *  - items already settled twice by two organizers (event moved before this fix) keep the first
	 *    settlement; the later lines get the source key "dup:<line id>" (audit entry
	 *    partner_settlement_duplicates lists them so the overpayment can be reclaimed),
	 *  - the unique index (partner_id, source_key) is replaced by a unique index on source_key.
	 * Idempotent.
	 *
	 * @return bool True when the tables exist and are up to date.
	 */
	public static function migrate() {
		global $wpdb;
		$p = $wpdb->prefix;
		$lines = "{$p}wpcap_partner_settlement_lines";
		$history = "{$p}wpcap_partner_event_history";
		if ( $history !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $history ) ) || $lines !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lines ) ) ) { return false; }
		$now = current_time( 'mysql', true );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$history} (event_id,partner_id,valid_from,valid_to,created_by,created_at) SELECT pe.event_id,pe.partner_id,NULL,NULL,0,%s FROM {$p}wpcap_partner_events pe WHERE NOT EXISTS (SELECT 1 FROM {$history} h WHERE h.event_id=pe.event_id)", $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names.
		$wpdb->query( "UPDATE {$lines} SET source_key=CONCAT(source_key,':',partner_id) WHERE kind IN ('voucher','voucher_refund') AND source_key IS NOT NULL AND source_key NOT LIKE '%:%:%' AND source_key NOT LIKE 'dup:%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
		$duplicates = array();
		foreach ( (array) $wpdb->get_results( "SELECT source_key, MIN(line_id) AS keep_id FROM {$lines} WHERE source_key IS NOT NULL GROUP BY source_key HAVING COUNT(*) > 1" ) as $dup ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT line_id,settlement_id,partner_id,item_key FROM {$lines} WHERE source_key=%s AND line_id<>%d", $dup->source_key, (int) $dup->keep_id ) ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
				$wpdb->update( $lines, array( 'source_key' => 'dup:' . (int) $row->line_id ), array( 'line_id' => (int) $row->line_id ) );
				$duplicates[] = array( 'line_id' => (int) $row->line_id, 'settlement_id' => (int) $row->settlement_id, 'partner_id' => (int) $row->partner_id, 'item_key' => (string) $row->item_key, 'first_line_id' => (int) $dup->keep_id );
			}
		}
		if ( $duplicates ) {
			\PassCaptain\Audit::record( 'partner_settlement_duplicates', 'system', 0, array( 'count' => count( $duplicates ), 'lines' => array_slice( $duplicates, 0, 200 ) ) );
		}
		$indexes = array();
		foreach ( (array) $wpdb->get_results( "SHOW INDEX FROM {$lines}" ) as $row ) { $indexes[ (string) ( (array) $row )['Key_name'] ] = true; } // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
		if ( ! isset( $indexes['source_key'] ) && false === $wpdb->query( "ALTER TABLE {$lines} ADD UNIQUE KEY source_key (source_key)" ) ) { return false; } // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
		if ( isset( $indexes['partner_source'] ) ) { $wpdb->query( "ALTER TABLE {$lines} DROP INDEX partner_source" ); } // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
		update_option( self::MIGRATION_OPTION, self::MIGRATION_VERSION );
		return true;
	}

	public static function settings_defaults() {
		// partner_negative_balance: negative statement balance (self-collected API sales, fees) is carried
		// to the next statement ('carry', default) or due from the organizer at once ('invoice').
		return array( 'partners' => array( 'settlement_prefix' => 'PA', 'self_billing_prefix' => 'GU', 'rate_limit' => 120, 'write_rate_limit' => 30 ), 'partner_negative_balance' => 'carry' );
	}

	public static function schema( $prefix, $charset ) {
		return array(
			"CREATE TABLE {$prefix}wpcap_partners (
				partner_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(190) NOT NULL,
				contact_name varchar(190) NOT NULL DEFAULT '',
				contact_email varchar(190) NOT NULL DEFAULT '',
				contact_phone varchar(64) NOT NULL DEFAULT '',
				billing_address longtext NOT NULL,
				vat_id varchar(64) NOT NULL DEFAULT '',
				payout_details longtext NOT NULL,
				currency char(3) NOT NULL DEFAULT 'EUR',
				commission_bp int(10) unsigned NOT NULL DEFAULT 0,
				fee_per_ticket_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				fee_tax_rate_bp int(10) unsigned NOT NULL DEFAULT 0,
				refund_policy varchar(20) NOT NULL DEFAULT 'reverse_all',
				sales_model varchar(16) NOT NULL DEFAULT 'own_name',
				legal_name varchar(190) NOT NULL DEFAULT '',
				country char(2) NOT NULL DEFAULT '',
				tax_number varchar(64) NOT NULL DEFAULT '',
				vat_id_checked_at date NULL,
				invoice_prefix varchar(10) NOT NULL DEFAULT '',
				small_business tinyint(1) NOT NULL DEFAULT 0,
				small_business_note varchar(255) NOT NULL DEFAULT '',
				settlement_start date NULL,
				role varchar(16) NOT NULL DEFAULT 'organizer',
				sales_channel varchar(64) NOT NULL DEFAULT '',
				hold_minutes int(10) unsigned NOT NULL DEFAULT 15,
				cancel_window_hours int(10) unsigned NOT NULL DEFAULT 0,
				anonymous_buyer tinyint(1) NOT NULL DEFAULT 0,
				reseller_commission_bp int(10) unsigned NOT NULL DEFAULT 0,
				reseller_fee_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'active',
				notes longtext NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (partner_id),
				KEY status (status)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}wpcap_partner_events (
				event_id bigint(20) unsigned NOT NULL,
				partner_id bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (event_id),
				KEY partner_id (partner_id)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}wpcap_partner_users (
				partner_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (partner_id,user_id),
				KEY user_id (user_id)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}wpcap_partner_keys (
				key_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				partner_id bigint(20) unsigned NOT NULL,
				label varchar(190) NOT NULL DEFAULT '',
				public_prefix char(12) NOT NULL,
				secret_hash char(64) NOT NULL,
				scopes varchar(255) NOT NULL,
				event_ids longtext NULL,
				ticket_type_ids longtext NULL,
				ip_allowlist longtext NULL,
				rate_limit int(10) unsigned NOT NULL DEFAULT 0,
				write_rate_limit int(10) unsigned NOT NULL DEFAULT 0,
				expires_at datetime NULL,
				last_used_at datetime NULL,
				revoked_at datetime NULL,
				revoked_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (key_id),
				UNIQUE KEY public_prefix (public_prefix),
				KEY partner_id (partner_id)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}wpcap_partner_settlements (
				settlement_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				partner_id bigint(20) unsigned NOT NULL,
				period char(7) NOT NULL,
				period_start datetime NOT NULL,
				period_end datetime NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'draft',
				document_type varchar(24) NOT NULL DEFAULT '',
				sales_model varchar(16) NOT NULL DEFAULT '',
				document_number varchar(40) NULL,
				self_billing_number varchar(40) NULL,
				currency char(3) NOT NULL DEFAULT 'EUR',
				tickets_sold int(11) NOT NULL DEFAULT 0,
				tickets_refunded int(11) NOT NULL DEFAULT 0,
				gross_minor bigint(20) NOT NULL DEFAULT 0,
				tax_minor bigint(20) NOT NULL DEFAULT 0,
				net_minor bigint(20) NOT NULL DEFAULT 0,
				refunded_minor bigint(20) NOT NULL DEFAULT 0,
				corrections_minor bigint(20) NOT NULL DEFAULT 0,
				commission_minor bigint(20) NOT NULL DEFAULT 0,
				fee_minor bigint(20) NOT NULL DEFAULT 0,
				platform_fee_net_minor bigint(20) NOT NULL DEFAULT 0,
				platform_fee_tax_minor bigint(20) NOT NULL DEFAULT 0,
				platform_fee_gross_minor bigint(20) NOT NULL DEFAULT 0,
				payout_minor bigint(20) NOT NULL DEFAULT 0,
				totals longtext NOT NULL,
				partner_snapshot longtext NULL,
				payment_reference varchar(190) NOT NULL DEFAULT '',
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				finalized_by bigint(20) unsigned NOT NULL DEFAULT 0,
				finalized_at datetime NULL,
				paid_at datetime NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (settlement_id),
				UNIQUE KEY partner_period (partner_id,period),
				UNIQUE KEY document_number (document_number),
				UNIQUE KEY self_billing_number (self_billing_number),
				KEY status (status)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}wpcap_partner_settlement_lines (
				line_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				settlement_id bigint(20) unsigned NOT NULL,
				partner_id bigint(20) unsigned NOT NULL,
				kind varchar(16) NOT NULL,
				item_key varchar(64) NOT NULL,
				source_key varchar(64) NULL,
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				refund_id bigint(20) unsigned NOT NULL DEFAULT 0,
				item_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event_name varchar(190) NOT NULL DEFAULT '',
				origin_period char(7) NOT NULL DEFAULT '',
				occurred_at datetime NOT NULL,
				tax_rate_bp int(10) unsigned NOT NULL DEFAULT 0,
				tickets int(11) NOT NULL DEFAULT 0,
				gross_minor bigint(20) NOT NULL DEFAULT 0,
				tax_minor bigint(20) NOT NULL DEFAULT 0,
				net_minor bigint(20) NOT NULL DEFAULT 0,
				commission_minor bigint(20) NOT NULL DEFAULT 0,
				fee_minor bigint(20) NOT NULL DEFAULT 0,
				PRIMARY KEY  (line_id),
				UNIQUE KEY source_key (source_key),
				KEY settlement_id (settlement_id),
				KEY order_id (order_id),
				KEY partner_event (partner_id,event_id)
			) ENGINE=InnoDB $charset;",
			// Assignment history event -> organizer with validity period (A448/A491): sales and their
			// refunds belong to the organizer assigned when the order was created. valid_from NULL =
			// since always (first assignment, migrated rows), valid_to NULL = current assignment.
			"CREATE TABLE {$prefix}wpcap_partner_event_history (
				history_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL,
				partner_id bigint(20) unsigned NOT NULL,
				valid_from datetime NULL,
				valid_to datetime NULL,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (history_id),
				KEY event_id (event_id,valid_from),
				KEY partner_id (partner_id)
			) ENGINE=InnoDB $charset;",
			// Selling via the partner API (src/Partners/Sales.php): price list / assortment per ticket type.
			"CREATE TABLE {$prefix}wpcap_partner_prices (
				partner_id bigint(20) unsigned NOT NULL,
				ticket_type_id bigint(20) unsigned NOT NULL,
				price_minor bigint(20) NULL,
				price_is_net tinyint(1) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (partner_id,ticket_type_id),
				KEY ticket_type_id (ticket_type_id)
			) ENGINE=InnoDB $charset;",
			// Partner contingents: max. units this partner may sell per occurrence (optionally per type),
			// optionally drawn from an Allocations hold. The row is the lock for race-free counting.
			"CREATE TABLE {$prefix}wpcap_partner_contingents (
				contingent_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				partner_id bigint(20) unsigned NOT NULL,
				occurrence_id bigint(20) unsigned NOT NULL,
				ticket_type_id bigint(20) unsigned NOT NULL DEFAULT 0,
				quantity int(10) unsigned NOT NULL DEFAULT 0,
				allocation_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (contingent_id),
				UNIQUE KEY partner_scope (partner_id,occurrence_id,ticket_type_id),
				KEY occurrence_id (occurrence_id)
			) ENGINE=InnoDB $charset;",
			// Orders created through the partner API (seller of record = partner).
			"CREATE TABLE {$prefix}wpcap_partner_orders (
				order_id bigint(20) unsigned NOT NULL,
				partner_id bigint(20) unsigned NOT NULL,
				key_id bigint(20) unsigned NOT NULL DEFAULT 0,
				mode varchar(10) NOT NULL DEFAULT 'reserve',
				channel varchar(64) NOT NULL DEFAULT '',
				external_reference varchar(100) NOT NULL DEFAULT '',
				anonymous tinyint(1) NOT NULL DEFAULT 0,
				request_hash char(64) NOT NULL DEFAULT '',
				confirmed_at datetime NULL,
				cancelled_at datetime NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (order_id),
				KEY partner_created (partner_id,created_at),
				KEY external_reference (partner_id,external_reference)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}wpcap_partner_contingent_usage (
				order_id bigint(20) unsigned NOT NULL,
				contingent_id bigint(20) unsigned NOT NULL,
				quantity int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (order_id,contingent_id),
				KEY contingent_id (contingent_id)
			) ENGINE=InnoDB $charset;",
			// Ticket codes of partner orders, encrypted (secretbox) for re-download by the holder of record.
			"CREATE TABLE {$prefix}wpcap_partner_ticket_codes (
				ticket_id bigint(20) unsigned NOT NULL,
				order_id bigint(20) unsigned NOT NULL,
				partner_id bigint(20) unsigned NOT NULL,
				code_box varchar(255) NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (ticket_id),
				KEY order_id (order_id)
			) ENGINE=InnoDB $charset;",
		);
	}

	public static function admin_pages() {
		return array(
			array( AdminPage::PAGE, __( 'Veranstalter', 'pass-captain' ), AdminPage::CAP, array( AdminPage::class, 'render_partners' ) ),
			array( AdminPage::SETTLEMENTS_PAGE, __( 'Veranstalter-Abrechnungen', 'pass-captain' ), AdminPage::settlements_menu_cap(), array( AdminPage::class, 'render_settlements' ) ),
		);
	}

	public static function admin_handlers() {
		return array(
			'wpcap_partner_action' => array( AdminPage::class, 'handle_action' ),
			'wpcap_partner_settlement_doc' => array( AdminPage::class, 'handle_document' ),
			'wpcap_partner_statement' => array( AdminPage::class, 'handle_statement' ),
		);
	}
}

<?php
/**
 * Module: partners ("Partner") – organisers/resellers linked to events, scoped partner API keys
 * (REST under wp-pass-captain/v1/partner/…, Bearer wpcap_pk_…) and monthly partner settlements
 * ("Abrechnungen") with a gapless number series. Code lives in src/Partners/. API: docs/PARTNER-API.md.
 * Sales model per organizer (own_name / agent) decides who issues customer invoices and whether the
 * settlement is a self-billing "Gutschrift" plus fee invoice or a "Provisionsrechnung": docs/SALES-MODELS.md.
 */
namespace PassCaptain\Modules;

use PassCaptain\Partners\AdminPage;
use PassCaptain\Partners\Api;
use PassCaptain\Partners\SalesModel;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Partners {
	public static function register() {
		add_action( 'rest_api_init', array( Api::class, 'register_routes' ) );
		// Sales models (docs/SALES-MODELS.md): one order = one seller.
		add_action( 'wpcap_order_creating', array( SalesModel::class, 'guard_checkout' ), 10, 1 );
		add_filter( 'wpcap_checkout_error_messages', array( SalesModel::class, 'checkout_messages' ) );
	}

	public static function settings_defaults() {
		return array( 'partners' => array( 'settlement_prefix' => 'PA', 'self_billing_prefix' => 'GU', 'rate_limit' => 120 ) );
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
				ip_allowlist longtext NULL,
				rate_limit int(10) unsigned NOT NULL DEFAULT 0,
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
				UNIQUE KEY partner_source (partner_id,source_key),
				KEY settlement_id (settlement_id)
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
		);
	}
}

<?php
/**
 * Module: box office ("Kasse") with cash sessions, fast sales, receipts and storno.
 * Code lives in src/BoxOffice/.
 */
namespace PassCaptain\Modules;

use PassCaptain\BoxOffice\Page;
use PassCaptain\BoxOffice\PosGateway;
use PassCaptain\BoxOffice\Service;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class BoxOffice {
	public static function register() {
		add_filter( 'wpcap_payment_gateways', array( PosGateway::class, 'register_gateways' ) );
		add_action( 'wpcap_refund_completed', array( Service::class, 'on_refund_completed' ), 10, 2 );
		add_filter( 'pre_wp_mail', array( Service::class, 'suppress_placeholder_mail' ), 10, 2 );
	}

	public static function schema( $prefix, $charset ) {
		return array(
			"CREATE TABLE {$prefix}wpcap_boxoffice_sessions (
				session_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				open_key bigint(20) unsigned NULL,
				currency char(3) NOT NULL DEFAULT 'EUR',
				opening_float_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				closing_count_minor bigint(20) unsigned NULL,
				expected_cash_minor bigint(20) NULL,
				difference_minor bigint(20) NULL,
				totals longtext NULL,
				note varchar(255) NOT NULL DEFAULT '',
				closing_note varchar(255) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'open',
				opened_at datetime NOT NULL,
				closed_at datetime NULL,
				closed_by bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (session_id),
				UNIQUE KEY open_key (open_key),
				KEY user_status (user_id,status)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}wpcap_boxoffice_sales (
				sale_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				session_id bigint(20) unsigned NOT NULL,
				order_id bigint(20) unsigned NOT NULL,
				form_key_hash char(64) NOT NULL,
				receipt_number varchar(40) NOT NULL,
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
		);
	}

	public static function admin_pages() {
		return array( array( Page::SLUG, __( 'Kasse', 'wp-pass-captain' ), Service::CAP, array( Page::class, 'render' ) ) );
	}

	public static function admin_handlers() {
		return array(
			'wpcap_pos_open'         => array( Page::class, 'handle_open' ),
			'wpcap_pos_sell'         => array( Page::class, 'handle_sell' ),
			'wpcap_pos_storno'       => array( Page::class, 'handle_storno' ),
			'wpcap_pos_close'        => array( Page::class, 'handle_close' ),
			'wpcap_pos_receipt'      => array( Page::class, 'handle_receipt' ),
			'wpcap_pos_tickets'      => array( Page::class, 'handle_tickets' ),
			'wpcap_pos_availability' => array( Page::class, 'handle_availability' ),
			'wpcap_pos_settings'     => array( Page::class, 'handle_settings' ),
		);
	}
}

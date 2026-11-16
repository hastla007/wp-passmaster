<?php
/**
 * Module "Umtausch": exchange a valid ticket into another occurrence and/or ticket type of the same
 * event (or series) – admin, order portal (self-service) and box office. See docs/EXCHANGE.md.
 */
namespace PassCaptain\Modules;

use PassCaptain\Exchange\AdminPage;
use PassCaptain\Exchange\Policy;
use PassCaptain\Exchange\Portal;
use PassCaptain\Exchange\Service;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Exchange {
	private static $registered = false;

	public static function register() {
		if ( self::$registered ) { return; }
		self::$registered = true;
		Service::register();
		Portal::register();
		AdminPage::register();
	}

	public static function settings_defaults() {
		return array( 'exchange' => Policy::defaults() );
	}

	public static function schema( $prefix, $charset ) {
		return array(
			"CREATE TABLE {$prefix}wpcap_ticket_exchanges (
				exchange_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				idempotency_key char(64) NULL,
				order_id bigint(20) unsigned NOT NULL,
				old_ticket_id bigint(20) unsigned NOT NULL,
				new_ticket_id bigint(20) unsigned NOT NULL DEFAULT 0,
				root_ticket_id bigint(20) unsigned NOT NULL,
				pending_ticket_id bigint(20) unsigned NULL,
				from_type_id bigint(20) unsigned NOT NULL,
				to_type_id bigint(20) unsigned NOT NULL,
				channel varchar(16) NOT NULL DEFAULT 'admin',
				status varchar(24) NOT NULL,
				currency char(3) NOT NULL DEFAULT 'EUR',
				old_value_minor bigint(20) NOT NULL DEFAULT 0,
				new_price_minor bigint(20) NOT NULL DEFAULT 0,
				difference_minor bigint(20) NOT NULL DEFAULT 0,
				fee_minor bigint(20) NOT NULL DEFAULT 0,
				refund_minor bigint(20) NOT NULL DEFAULT 0,
				due_minor bigint(20) NOT NULL DEFAULT 0,
				new_value_minor bigint(20) NOT NULL DEFAULT 0,
				waived tinyint(1) unsigned NOT NULL DEFAULT 0,
				refund_policy varchar(16) NOT NULL DEFAULT 'refund',
				refund_id bigint(20) unsigned NOT NULL DEFAULT 0,
				refund_status varchar(24) NOT NULL DEFAULT '',
				supplementary_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				seat_id bigint(20) unsigned NOT NULL DEFAULT 0,
				note varchar(255) NOT NULL DEFAULT '',
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				completed_at datetime NULL,
				PRIMARY KEY  (exchange_id),
				UNIQUE KEY idempotency_key (idempotency_key),
				UNIQUE KEY pending_ticket (pending_ticket_id),
				KEY order_id (order_id),
				KEY old_ticket (old_ticket_id,status),
				KEY new_ticket (new_ticket_id),
				KEY root_ticket (root_ticket_id,status),
				KEY supplementary_order (supplementary_order_id),
				KEY created_at (created_at)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}wpcap_exchange_policies (
				event_id bigint(20) unsigned NOT NULL,
				enabled tinyint(1) unsigned NULL,
				portal_enabled tinyint(1) unsigned NULL,
				portal_hours int(10) unsigned NULL,
				portal_max_per_ticket int(10) unsigned NULL,
				refund_policy varchar(16) NULL,
				fee_minor bigint(20) unsigned NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (event_id)
			) ENGINE=InnoDB $charset;",
		);
	}

	public static function admin_pages() {
		return array( array( AdminPage::SLUG, __( 'Umtausch', 'pass-captain' ), AdminPage::CAP, array( AdminPage::class, 'render' ) ) );
	}

	public static function admin_handlers() {
		return array(
			'wpcap_exchange_ticket' => array( AdminPage::class, 'handle_exchange' ),
			'wpcap_exchange_settings' => array( AdminPage::class, 'handle_settings' ),
			'wpcap_exchange_event_policy' => array( AdminPage::class, 'handle_event_policy' ),
		);
	}
}

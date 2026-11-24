<?php
/**
 * Tables of the sales rules: automatic price rules for any occurrence/ticket type, buyer limits
 * across orders and logged participant consents. Part of the core schema (Installer::DB_VERSION).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Sales;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class Schema {
	/** @return string[] dbDelta statements. */
	public static function statements( $prefix, $charset ) {
		return array(
			"CREATE TABLE {$prefix}wpcap_price_rules (
				rule_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL,
				occurrence_id bigint(20) unsigned NOT NULL DEFAULT 0,
				ticket_type_id bigint(20) unsigned NOT NULL DEFAULT 0,
				label varchar(190) NOT NULL DEFAULT '',
				type_name varchar(190) NOT NULL DEFAULT '',
				kind varchar(20) NOT NULL,
				priority int(11) NOT NULL DEFAULT 0,
				until_at datetime NULL DEFAULT NULL,
				hours_before int(10) unsigned NOT NULL DEFAULT 0,
				sold_pct smallint(5) unsigned NOT NULL DEFAULT 0,
				weekdays varchar(20) NOT NULL DEFAULT '',
				time_from char(5) NOT NULL DEFAULT '',
				time_to char(5) NOT NULL DEFAULT '',
				price_mode varchar(10) NOT NULL DEFAULT 'fixed',
				amount bigint(20) NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (rule_id),
				KEY event_status (event_id,status)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}wpcap_buyer_limits (
				limit_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL,
				occurrence_id bigint(20) unsigned NOT NULL DEFAULT 0,
				ticket_type_id bigint(20) unsigned NOT NULL DEFAULT 0,
				max_quantity int(10) unsigned NOT NULL,
				match_by varchar(16) NOT NULL DEFAULT 'both',
				status varchar(20) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (limit_id),
				KEY event_status (event_id,status)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}wpcap_consents (
				consent_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				ticket_id bigint(20) unsigned NOT NULL,
				order_id bigint(20) unsigned NOT NULL,
				field_id bigint(20) unsigned NOT NULL,
				subject_email varchar(190) NOT NULL DEFAULT '',
				consent_label varchar(190) NOT NULL DEFAULT '',
				consent_text text NOT NULL,
				text_version varchar(32) NOT NULL DEFAULT '',
				text_hash char(64) NOT NULL DEFAULT '',
				source varchar(32) NOT NULL DEFAULT 'checkout',
				granted_at datetime NOT NULL,
				withdrawn_at datetime NULL,
				withdrawn_source varchar(32) NOT NULL DEFAULT '',
				PRIMARY KEY  (consent_id),
				KEY ticket_field (ticket_id,field_id),
				KEY order_id (order_id),
				KEY subject_email (subject_email),
				KEY field_id (field_id)
			) ENGINE=InnoDB $charset;",
		);
	}
}

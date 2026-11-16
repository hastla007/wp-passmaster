<?php
/**
 * Connectors module: concrete adapters on the versioned connector contract (Integrations\ConnectorInterface).
 *
 * - HTTP/JSON connector (Integrations\HttpJson): any number of HTTPS endpoints (CRM, Zapier, Make,
 *   n8n …) with event selection, field mapping incl. PII opt-in, Vault-encrypted auth header, test
 *   send and delivery log with retry status. Deliveries run through the core job queue.
 * - Calendar subscription feeds (Integrations\CalendarFeeds): tokenized iCalendar URLs per event,
 *   organizer or for all events, updated on reschedule/cancel (SEQUENCE).
 * - Admin page "Konnektoren" (manage_options). See docs/EXTENSIONS.md.
 */
namespace PassCaptain\Modules;

use PassCaptain\Integrations\CalendarFeeds;
use PassCaptain\Integrations\ConnectorsPage;
use PassCaptain\Integrations\HttpJson\Connector;
use PassCaptain\Integrations\HttpJson\Endpoints;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Connectors {
	public static function register() {
		add_filter( 'wp_pass_captain_connectors_v1', array( Connector::class, 'register_all' ), 20, 1 );
		add_action( 'template_redirect', array( CalendarFeeds::class, 'maybe_serve' ), 0 );
		add_action( 'wpcap_occurrence_rescheduled', array( CalendarFeeds::class, 'bump' ), 40, 1 );
		add_action( 'wpcap_occurrence_cancelled', array( CalendarFeeds::class, 'bump' ), 40, 1 );
		add_action( 'wpcap_process_jobs', array( Endpoints::class, 'purge_old' ), 30, 0 );
	}

	public static function schema( $prefix, $charset ) {
		return array(
			"CREATE TABLE {$prefix}wpcap_connector_endpoints (
				endpoint_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(190) NOT NULL,
				url varchar(500) NOT NULL,
				auth_header varchar(64) NOT NULL DEFAULT 'Authorization',
				secret text NULL,
				events varchar(500) NOT NULL DEFAULT '',
				mapping longtext NULL,
				include_pii tinyint(1) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (endpoint_id),
				KEY status (status)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}wpcap_connector_deliveries (
				delivery_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				endpoint_id bigint(20) unsigned NOT NULL,
				event varchar(40) NOT NULL,
				idempotency_key char(64) NOT NULL,
				payload longtext NULL,
				status varchar(20) NOT NULL DEFAULT 'pending',
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				response_code smallint(5) unsigned NOT NULL DEFAULT 0,
				last_error varchar(190) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				delivered_at datetime NULL,
				PRIMARY KEY  (delivery_id),
				UNIQUE KEY endpoint_key (endpoint_id,idempotency_key),
				KEY status_updated (status,updated_at)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}wpcap_calendar_feeds (
				feed_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				scope varchar(20) NOT NULL,
				scope_id bigint(20) unsigned NOT NULL DEFAULT 0,
				label varchar(190) NOT NULL DEFAULT '',
				token_hash char(64) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'active',
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				revoked_at datetime NULL,
				last_access_at datetime NULL,
				PRIMARY KEY  (feed_id),
				UNIQUE KEY token_hash (token_hash),
				KEY status (status)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}wpcap_calendar_sequences (
				occurrence_id bigint(20) unsigned NOT NULL,
				fingerprint char(32) NOT NULL,
				sequence int(10) unsigned NOT NULL DEFAULT 0,
				changed_at datetime NOT NULL,
				PRIMARY KEY  (occurrence_id)
			) ENGINE=InnoDB $charset;",
		);
	}

	public static function admin_pages() {
		return array( array( ConnectorsPage::SLUG, __( 'Konnektoren', 'pass-captain' ), ConnectorsPage::CAP, array( ConnectorsPage::class, 'render' ) ) );
	}

	public static function admin_handlers() {
		return array( 'wpcap_connector_action' => array( ConnectorsPage::class, 'handle_action' ) );
	}
}

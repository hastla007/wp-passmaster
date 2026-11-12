<?php
/**
 * Seller-aware fiscalization core for WooCommerce and the box office.
 *
 * Provider adapters are opt-in. This module does not select a vendor or claim country
 * certification; an enabled seller mapping fails closed until a compatible adapter returns
 * a successful, idempotent receipt result.
 */
namespace PassCaptain\Modules;

use PassCaptain\Audit;
use PassCaptain\Fiscalization\Registry;
use PassCaptain\Orders;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Fiscalization {
	public static function schema( $prefix, $charset ) {
		return array(
			"CREATE TABLE {$prefix}wpcap_legal_sellers (
				seller_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				legal_name varchar(190) NOT NULL,
				country_code char(2) NOT NULL,
				tax_number varchar(64) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (seller_id),
				KEY country_status (country_code,status)
			) ENGINE=InnoDB {$charset};",
			"CREATE TABLE {$prefix}wpcap_fiscal_configs (
				seller_id bigint(20) unsigned NOT NULL,
				country_code char(2) NOT NULL,
				provider_id varchar(64) NOT NULL DEFAULT '',
				configuration longtext NULL,
				enabled tinyint(1) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (seller_id),
				KEY provider_country (provider_id,country_code)
			) ENGINE=InnoDB {$charset};",
			"CREATE TABLE {$prefix}wpcap_ticket_sellers (
				ticket_type_id bigint(20) unsigned NOT NULL,
				seller_id bigint(20) unsigned NOT NULL,
				seller_mode varchar(24) NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (ticket_type_id),
				KEY seller_id (seller_id)
			) ENGINE=InnoDB {$charset};",
			"CREATE TABLE {$prefix}wpcap_fiscal_documents (
				document_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				order_id bigint(20) unsigned NOT NULL,
				seller_id bigint(20) unsigned NOT NULL,
				document_key char(64) NOT NULL,
				country_code char(2) NOT NULL,
				provider_id varchar(64) NOT NULL DEFAULT '',
				status varchar(24) NOT NULL DEFAULT 'pending',
				currency char(3) NOT NULL,
				gross_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				tax_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				snapshot longtext NOT NULL,
				provider_result longtext NULL,
				last_error varchar(191) NOT NULL DEFAULT '',
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (document_id),
				UNIQUE KEY document_key (document_key),
				KEY order_seller (order_id,seller_id),
				KEY status_updated (status,updated_at)
			) ENGINE=InnoDB {$charset};",
			"CREATE TABLE {$prefix}wpcap_fiscal_document_items (
				document_id bigint(20) unsigned NOT NULL,
				ticket_type_id bigint(20) unsigned NOT NULL,
				seller_mode varchar(24) NOT NULL,
				quantity smallint(5) unsigned NOT NULL,
				gross_minor bigint(20) unsigned NOT NULL,
				tax_rate_bp int(10) unsigned NOT NULL,
				tax_minor bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (document_id,ticket_type_id),
				KEY ticket_type_id (ticket_type_id)
			) ENGINE=InnoDB {$charset};",
		);
	}

	public static function register() {
		add_action( 'wpcap_order_creating', array( __CLASS__, 'snapshot_order' ), 20, 4 );
		add_action( 'wpcap_order_paid', array( __CLASS__, 'process_order' ), 1 );
		add_action( 'wpcap_process_fiscal_order', array( __CLASS__, 'process_scheduled_order' ) );
		add_filter( 'wpcap_order_fiscal_ready', array( __CLASS__, 'fiscal_ready' ), 10, 2 );
		add_action( 'wpcap_order_refunded', array( __CLASS__, 'mark_refunded' ), 10, 2 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Trusted configuration API for the admin UI or an integration plugin.
	 * $mode is organizer_seller or platform_seller; it is snapshotted with each receipt line.
	 */
	public static function assign_ticket_type( $ticket_type_id, $seller_id, $mode ) {
		global $wpdb;
		$ticket_type_id = absint( $ticket_type_id );
		$seller_id = absint( $seller_id );
		$mode = sanitize_key( $mode );
		if ( ! $ticket_type_id || ! $seller_id || ! in_array( $mode, array( 'organizer_seller', 'platform_seller' ), true ) ) {
			return new \WP_Error( 'wpcap_fiscal_mapping_invalid', __( 'Verkäuferzuordnung ist ungültig.', 'wp-pass-captain' ) );
		}
		$p = $wpdb->prefix;
		$seller = $wpdb->get_row( $wpdb->prepare( "SELECT seller_id,status FROM {$p}wpcap_legal_sellers WHERE seller_id=%d", $seller_id ) );
		$type = $wpdb->get_var( $wpdb->prepare( "SELECT ticket_type_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d", $ticket_type_id ) );
		if ( ! $seller || 'active' !== $seller->status || ! $type ) {
			return new \WP_Error( 'wpcap_fiscal_mapping_missing', __( 'Ticketart oder aktiver Rechtsträger wurde nicht gefunden.', 'wp-pass-captain' ) );
		}
		$ok = $wpdb->replace( "{$p}wpcap_ticket_sellers", array(
			'ticket_type_id' => $ticket_type_id,
			'seller_id' => $seller_id,
			'seller_mode' => $mode,
			'updated_at' => current_time( 'mysql', true ),
		), array( '%d', '%d', '%s', '%s' ) );
		return false === $ok ? new \WP_Error( 'wpcap_fiscal_mapping_save', __( 'Verkäuferzuordnung konnte nicht gespeichert werden.', 'wp-pass-captain' ) ) : true;
	}

	/** Create immutable seller-specific fiscal documents in the order transaction. */
	public static function snapshot_order( $order_id, $selected, $payload, $internal ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$groups = array();
		foreach ( (array) $selected as $line ) {
			$type_id = absint( $line['type']->ticket_type_id ?? 0 );
			$mapping = $wpdb->get_row( $wpdb->prepare( "SELECT ts.seller_id,ts.seller_mode,s.legal_name,s.country_code,s.tax_number,s.status AS seller_status,c.provider_id,c.configuration,c.enabled FROM {$p}wpcap_ticket_sellers ts INNER JOIN {$p}wpcap_legal_sellers s ON s.seller_id=ts.seller_id LEFT JOIN {$p}wpcap_fiscal_configs c ON c.seller_id=s.seller_id WHERE ts.ticket_type_id=%d", $type_id ) );
			if ( ! $mapping ) { continue; }
			if ( 'active' !== $mapping->seller_status || ! in_array( $mapping->seller_mode, array( 'organizer_seller', 'platform_seller' ), true ) ) {
				throw new \RuntimeException( 'fiscal_seller_invalid' );
			}
			$seller_id = (int) $mapping->seller_id;
			if ( ! isset( $groups[ $seller_id ] ) ) {
				$groups[ $seller_id ] = array(
					'seller' => array( 'seller_id' => $seller_id, 'legal_name' => $mapping->legal_name, 'country_code' => strtoupper( $mapping->country_code ), 'tax_number' => $mapping->tax_number ),
					'provider_id' => (int) $mapping->enabled ? sanitize_key( $mapping->provider_id ) : '',
					'configuration' => $mapping->configuration ? (array) json_decode( $mapping->configuration, true ) : array(),
					'items' => array(), 'gross_minor' => 0, 'tax_minor' => 0,
				);
			}
			$gross = max( 0, (int) $line['line_total'] );
			$tax = max( 0, (int) $line['tax_minor'] );
			$groups[ $seller_id ]['items'][] = array(
				'ticket_type_id' => $type_id,
				'seller_mode' => $mapping->seller_mode,
				'quantity' => absint( $line['quantity'] ),
				'gross_minor' => $gross,
				'tax_rate_bp' => absint( $line['tax_rate_bp'] ),
				'tax_minor' => $tax,
			);
			$groups[ $seller_id ]['gross_minor'] += $gross;
			$groups[ $seller_id ]['tax_minor'] += $tax;
		}
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT currency FROM {$p}wpcap_orders WHERE order_id=%d", absint( $order_id ) ) );
		foreach ( $groups as $seller_id => $group ) {
			$key = hash( 'sha256', 'wpcap-fiscal-v1:' . absint( $order_id ) . ':' . $seller_id );
			$snapshot = array( 'schema_version' => 1, 'order_id' => absint( $order_id ), 'seller' => $group['seller'], 'seller_mode' => $group['items'][0]['seller_mode'], 'currency' => $order->currency ?? 'EUR', 'gross_minor' => $group['gross_minor'], 'tax_minor' => $group['tax_minor'], 'items' => $group['items'] );
			$wpdb->insert( "{$p}wpcap_fiscal_documents", array(
				'order_id' => absint( $order_id ), 'seller_id' => (int) $seller_id, 'document_key' => $key,
				'country_code' => $group['seller']['country_code'], 'provider_id' => $group['provider_id'],
				'status' => $group['provider_id'] ? 'pending' : 'configuration_required', 'currency' => $snapshot['currency'],
				'gross_minor' => $group['gross_minor'], 'tax_minor' => $group['tax_minor'], 'snapshot' => wp_json_encode( $snapshot ),
				'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ),
			) );
			if ( '' !== $wpdb->last_error ) { throw new \RuntimeException( 'fiscal_snapshot_failed' ); }
			$document_id = (int) $wpdb->insert_id;
			foreach ( $group['items'] as $item ) {
				$wpdb->insert( "{$p}wpcap_fiscal_document_items", array_merge( array( 'document_id' => $document_id ), $item ) );
				if ( '' !== $wpdb->last_error ) { throw new \RuntimeException( 'fiscal_snapshot_failed' ); }
			}
		}
	}

	/** Submit all fiscal documents before the core may issue tickets. */
	public static function process_order( $order_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$docs = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}wpcap_fiscal_documents WHERE order_id=%d AND status IN ('pending','retry') ORDER BY seller_id", absint( $order_id ) ) );
		foreach ( $docs as $doc ) {
			$adapter = Registry::get( $doc->provider_id );
			if ( ! $adapter ) {
				self::fail( $doc, __( 'Kein Adapter für den konfigurierten Anbieter registriert.', 'wp-pass-captain' ) );
				continue;
			}
			$seller = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_legal_sellers WHERE seller_id=%d", $doc->seller_id ), ARRAY_A );
			$config_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_fiscal_configs WHERE seller_id=%d", $doc->seller_id ), ARRAY_A );
			$configuration = $config_row && $config_row['configuration'] ? (array) json_decode( $config_row['configuration'], true ) : array();
			$document = json_decode( $doc->snapshot, true );
			$document['document_id'] = (int) $doc->document_id;
			$document['idempotency_key'] = $doc->document_key;
			$document['attempt'] = (int) $doc->attempts + 1;
			$countries = array_map( 'strtoupper', (array) $adapter->countries() );
			if ( ! in_array( strtoupper( $doc->country_code ), $countries, true ) ) {
				self::fail( $doc, __( 'Der ausgewählte Fiskaladapter unterstützt das Land dieses Verkäufers nicht.', 'wp-pass-captain' ) );
				continue;
			}
			$result = $adapter->submit( $document, (array) $seller, $configuration );
			if ( is_wp_error( $result ) || ! is_array( $result ) || empty( $result['reference'] ) ) {
				self::fail( $doc, is_wp_error( $result ) ? $result->get_error_message() : __( 'Der Fiskaladapter lieferte kein gültiges Ergebnis.', 'wp-pass-captain' ) );
				continue;
			}
			$wpdb->update( "{$p}wpcap_fiscal_documents", array(
				'status' => 'submitted', 'provider_result' => wp_json_encode( $result ), 'last_error' => '',
				'attempts' => (int) $doc->attempts + 1, 'updated_at' => current_time( 'mysql', true ),
			), array( 'document_id' => (int) $doc->document_id ) );
			Audit::record( 'fiscal_document_submitted', 'order', (int) $order_id, array( 'document_id' => (int) $doc->document_id, 'seller_id' => (int) $doc->seller_id, 'provider_id' => $doc->provider_id ) );
		}
	}

	/** Scheduled retry callback; adapters receive a stable idempotency key for every retry. */
	public static function process_scheduled_order( $order_id ) {
		self::process_order( absint( $order_id ) );
		Orders::issue_and_email( absint( $order_id ) );
	}

	public static function rest_retry_order( \\WP_REST_Request $request ) {
		global $wpdb;
		$order_id = absint( $request['order_id'] );
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_fiscal_documents SET status='retry',last_error='',updated_at=%s WHERE order_id=%d AND status='failed'", current_time( 'mysql', true ), $order_id ) );
		self::process_order( $order_id );
		Orders::issue_and_email( $order_id );
		$blocked = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_fiscal_documents WHERE order_id=%d AND status <> 'submitted'", $order_id ) );
		return rest_ensure_response( array( 'order_id' => $order_id, 'tickets_issued' => ! $blocked && (bool) Orders::tickets_for_order( $order_id ), 'fiscal_pending' => (bool) $blocked ) );
	}

	private static function fail( $doc, $message ) {
		global $wpdb;
		$attempts = (int) $doc->attempts + 1;
		$terminal = $attempts >= 8;
		$wpdb->update( $wpdb->prefix . 'wpcap_fiscal_documents', array(
			'status' => $terminal ? 'failed' : 'retry', 'last_error' => sanitize_text_field( $message ),
			'attempts' => $attempts, 'updated_at' => current_time( 'mysql', true ),
		), array( 'document_id' => (int) $doc->document_id ) );
		if ( ! $terminal && ! wp_next_scheduled( 'wpcap_process_fiscal_order', array( (int) $doc->order_id ) ) ) {
			$delay = min( HOUR_IN_SECONDS, 60 * ( 2 ** min( 10, $attempts ) ) );
			wp_schedule_single_event( time() + $delay, 'wpcap_process_fiscal_order', array( (int) $doc->order_id ) );
		}
	}

	/** Runs submission again on payment callback retries; ticket issuance is blocked until success. */
	public static function fiscal_ready( $ready, $order_id ) {
		global $wpdb;
		self::process_order( $order_id );
		$blocked = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_fiscal_documents WHERE order_id=%d AND status <> 'submitted'", absint( $order_id ) ) );
		return $blocked ? false : $ready;
	}

	public static function mark_refunded( $order_id, $reason ) {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'wpcap_fiscal_documents', array( 'status' => 'refund_pending', 'updated_at' => current_time( 'mysql', true ) ), array( 'order_id' => absint( $order_id ) ), array( '%s', '%s' ), array( '%d' ) );
	}

	/** Admin-only REST endpoints for managing seller records, provider selection, and ticket mapping. */
	public static function register_routes() {
		register_rest_route( 'wp-pass-captain/v1', '/fiscal/sellers', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_sellers' ), 'permission_callback' => array( __CLASS__, 'rest_permission' ) ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_save_seller' ), 'permission_callback' => array( __CLASS__, 'rest_permission' ) ),
		) );
		register_rest_route( 'wp-pass-captain/v1', '/fiscal/sellers/(?P<seller_id>\d+)/provider', array(
			'methods' => 'PUT', 'callback' => array( __CLASS__, 'rest_save_provider' ), 'permission_callback' => array( __CLASS__, 'rest_permission' ),
		) );
		register_rest_route( 'wp-pass-captain/v1', '/fiscal/orders/(?P<order_id>\\d+)/retry', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_retry_order' ), 'permission_callback' => array( __CLASS__, 'rest_permission' ),
		) );
		register_rest_route( 'wp-pass-captain/v1', '/fiscal/ticket-types/(?P<ticket_type_id>\d+)/seller', array(
			'methods' => 'PUT', 'callback' => array( __CLASS__, 'rest_assign_seller' ), 'permission_callback' => array( __CLASS__, 'rest_permission' ),
		) );
	}

	public static function rest_permission() { return current_user_can( 'manage_options' ); }

	public static function rest_sellers() {
		global $wpdb;
		return rest_ensure_response( $wpdb->get_results( "SELECT s.seller_id,s.legal_name,s.country_code,s.tax_number,s.status,c.provider_id,c.enabled FROM {$wpdb->prefix}wpcap_legal_sellers s LEFT JOIN {$wpdb->prefix}wpcap_fiscal_configs c ON c.seller_id=s.seller_id ORDER BY s.legal_name", ARRAY_A ) );
	}

	public static function rest_save_seller( \WP_REST_Request $request ) {
		global $wpdb;
		$body = (array) $request->get_json_params();
		$name = sanitize_text_field( $body['legal_name'] ?? '' );
		$country = strtoupper( sanitize_text_field( $body['country_code'] ?? '' ) );
		if ( '' === $name || ! in_array( $country, array( 'AT', 'DE', 'HU' ), true ) ) {
			return new \WP_Error( 'wpcap_fiscal_seller_invalid', __( 'Name und eines der unterstützten Länder AT, DE oder HU sind erforderlich.', 'wp-pass-captain' ), array( 'status' => 400 ) );
		}
		$now = current_time( 'mysql', true );
		$wpdb->insert( $wpdb->prefix . 'wpcap_legal_sellers', array( 'legal_name' => $name, 'country_code' => $country, 'tax_number' => sanitize_text_field( $body['tax_number'] ?? '' ), 'created_at' => $now, 'updated_at' => $now ), array( '%s', '%s', '%s', '%s', '%s' ) );
		if ( ! $wpdb->insert_id ) { return new \WP_Error( 'wpcap_fiscal_seller_save', __( 'Rechtsträger konnte nicht gespeichert werden.', 'wp-pass-captain' ), array( 'status' => 500 ) ); }
		return rest_ensure_response( array( 'seller_id' => (int) $wpdb->insert_id ) );
	}

	public static function rest_save_provider( \WP_REST_Request $request ) {
		global $wpdb;
		$seller_id = absint( $request['seller_id'] );
		$body = (array) $request->get_json_params();
		$provider_id = sanitize_key( $body['provider_id'] ?? '' );
		$enabled = ! empty( $body['enabled'] ) ? 1 : 0;
		$adapter = Registry::get( $provider_id );
		$seller = $wpdb->get_row( $wpdb->prepare( "SELECT country_code FROM {$wpdb->prefix}wpcap_legal_sellers WHERE seller_id=%d", $seller_id ) );
		if ( ! $seller || ( $enabled && ( ! $adapter || ! in_array( $seller->country_code, array_map( 'strtoupper', (array) $adapter->countries() ), true ) ) ) ) {
			return new \WP_Error( 'wpcap_fiscal_provider_invalid', __( 'Anbieter oder Länderunterstützung ist ungültig.', 'wp-pass-captain' ), array( 'status' => 400 ) );
		}
		$now = current_time( 'mysql', true );
		$config = isset( $body['configuration'] ) && is_array( $body['configuration'] ) ? $body['configuration'] : array();
		$result = $wpdb->replace( $wpdb->prefix . 'wpcap_fiscal_configs', array( 'seller_id' => $seller_id, 'country_code' => $seller->country_code, 'provider_id' => $provider_id, 'configuration' => wp_json_encode( $config ), 'enabled' => $enabled, 'created_at' => $now, 'updated_at' => $now ), array( '%d', '%s', '%s', '%s', '%d', '%s', '%s' ) );
		return false === $result ? new \WP_Error( 'wpcap_fiscal_provider_save', __( 'Anbieter-Konfiguration konnte nicht gespeichert werden.', 'wp-pass-captain' ), array( 'status' => 500 ) ) : rest_ensure_response( array( 'seller_id' => $seller_id, 'provider_id' => $provider_id, 'enabled' => (bool) $enabled ) );
	}

	public static function rest_assign_seller( \WP_REST_Request $request ) {
		$body = (array) $request->get_json_params();
		$result = self::assign_ticket_type( absint( $request['ticket_type_id'] ), absint( $body['seller_id'] ?? 0 ), sanitize_key( $body['seller_mode'] ?? '' ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'ticket_type_id' => absint( $request['ticket_type_id'] ), 'seller_id' => absint( $body['seller_id'] ), 'seller_mode' => sanitize_key( $body['seller_mode'] ) ) );
	}
}

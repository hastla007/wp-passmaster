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
use PassCaptain\Fiscalization\ArchiveExporter;
use PassCaptain\Fiscalization\CountryPolicy;
use PassCaptain\Fiscalization\Providers\ProviderAdapters;
use PassCaptain\Fiscalization\Credentials;
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
				vat_id varchar(64) NOT NULL DEFAULT '',
				business_address longtext NULL,
				contact_email varchar(190) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (seller_id),
				KEY country_status (country_code,status)
			) ENGINE=InnoDB {$charset};",
			"CREATE TABLE {$prefix}wpcap_fiscal_profiles (
				profile_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				seller_id bigint(20) unsigned NOT NULL,
				country_code char(2) NOT NULL,
				channel varchar(24) NOT NULL,
				location_id bigint(20) unsigned NOT NULL DEFAULT 0,
				register_id varchar(64) NOT NULL DEFAULT '',
				environment varchar(16) NOT NULL DEFAULT 'test',
				status varchar(16) NOT NULL DEFAULT 'draft',
				profile_version int(10) unsigned NOT NULL DEFAULT 1,
				ruleset_key varchar(64) NOT NULL DEFAULT '',
				ruleset_version varchar(64) NOT NULL DEFAULT '',
				legal_reference varchar(191) NOT NULL DEFAULT '',
				profile_data longtext NULL,
				reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
				reviewed_at datetime NULL,
				effective_from datetime NULL,
				effective_until datetime NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (profile_id),
				UNIQUE KEY profile_scope (seller_id,country_code,channel,location_id,register_id,environment),
				KEY status_country (status,country_code)
			) ENGINE=InnoDB {$charset};",
			"CREATE TABLE {$prefix}wpcap_fiscal_order_state (
				order_id bigint(20) unsigned NOT NULL,
				fiscal_status varchar(24) NOT NULL DEFAULT 'pending',
				fulfillment_status varchar(24) NOT NULL DEFAULT 'blocked',
				updated_at datetime NOT NULL,
				PRIMARY KEY  (order_id),
				KEY fiscal_status (fiscal_status,updated_at),
				KEY fulfillment_status (fulfillment_status,updated_at)
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
			"CREATE TABLE {$prefix}wpcap_order_seller_snapshots (
				order_id bigint(20) unsigned NOT NULL,
				ticket_type_id bigint(20) unsigned NOT NULL,
				seller_id bigint(20) unsigned NOT NULL,
				seller_mode varchar(24) NOT NULL,
				channel varchar(24) NOT NULL,
				country_code char(2) NOT NULL,
				profile_id bigint(20) unsigned NOT NULL,
				profile_version int(10) unsigned NOT NULL,
				fiscal_applicability varchar(24) NOT NULL,
				seller_snapshot longtext NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (order_id,ticket_type_id),
				KEY seller_orders (seller_id,order_id),
				KEY profile_id (profile_id)
			) ENGINE=InnoDB {$charset};",
			"CREATE TABLE {$prefix}wpcap_fiscal_events (
				event_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				document_id bigint(20) unsigned NOT NULL,
				order_id bigint(20) unsigned NOT NULL,
				provider_id varchar(64) NOT NULL DEFAULT '',
				event_type varchar(32) NOT NULL,
				event_status varchar(24) NOT NULL,
				metadata longtext NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (event_id),
				KEY document_events (document_id,event_id),
				KEY order_events (order_id,event_id)
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
		add_filter( 'wpcap_fiscal_adapters', array( ProviderAdapters::class, 'register' ), 5, 1 );
		add_action( 'wpcap_order_creating', array( __CLASS__, 'snapshot_order' ), 20, 4 );
		add_action( 'wpcap_process_fiscal_order', array( __CLASS__, 'process_scheduled_order' ) );
		add_filter( 'wpcap_order_fiscal_ready', array( __CLASS__, 'fiscal_ready' ), 10, 2 );
		add_action( 'wpcap_tickets_issued', array( __CLASS__, 'mark_fulfilled' ), 10, 2 );
		add_action( 'wpcap_refund_completed', array( __CLASS__, 'record_refund_adjustment' ), 10, 2 );
		add_action( 'wpcap_woocommerce_non_ticket_refund', array( __CLASS__, 'record_non_ticket_refund' ), 10, 4 );
		add_action( 'wpcap_order_cancelled', array( __CLASS__, 'record_order_cancellation' ), 10, 3 );
		add_action( 'wpcap_event_cancelled', array( __CLASS__, 'record_event_cancellation' ), 10, 1 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'admin_post_wpcap_fiscal_manual_resolution', array( __CLASS__, 'admin_record_manual_resolution' ) );
		add_action( 'admin_post_wpcap_fiscal_submit_efsta_void', array( __CLASS__, 'admin_submit_efsta_void' ) );
	}

	/**
	 * Persist a seller/country/channel profile. Production activation requires an explicit recorded review.
	 */
	public static function save_profile( $seller_id, array $input ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$seller_id = absint( $seller_id );
		$seller = $wpdb->get_row( $wpdb->prepare( "SELECT country_code,status FROM {$p}wpcap_legal_sellers WHERE seller_id=%d", $seller_id ) );
		$channel = sanitize_key( $input['channel'] ?? '' );
		$environment = sanitize_key( $input['environment'] ?? 'test' );
		$status = sanitize_key( $input['status'] ?? 'draft' );
		$ruleset = sanitize_key( $input['ruleset_key'] ?? '' );
		$ruleset_version = sanitize_text_field( $input['ruleset_version'] ?? '' );
		$legal_reference = sanitize_text_field( $input['legal_reference'] ?? '' );
		$location_id = absint( $input['location_id'] ?? 0 );
		$register_id = sanitize_text_field( $input['register_id'] ?? '' );
		if ( strlen( $register_id ) > 64 || ! preg_match( '/^[A-Za-z0-9_.:-]*$/', $register_id ) ) { return new \WP_Error( 'wpcap_fiscal_profile_invalid', __( 'Kassen-ID ist ungültig.', 'wp-pass-captain' ), array( 'status' => 400 ) ); }
		$data = is_array( $input['profile_data'] ?? null ) ? $input['profile_data'] : array();
		$offline_policy = self::normalize_offline_policy( $input['offline_policy'] ?? ( $data['offline_policy'] ?? 'stop_sale' ) );
		if ( is_wp_error( $offline_policy ) ) { return $offline_policy; }
		$data['offline_policy'] = $offline_policy;
		$required = is_array( $input['required_capabilities'] ?? null ) ? $input['required_capabilities'] : preg_split( '/[,\\s]+/', (string) ( $input['required_capabilities'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY );
		$required = array_map( 'sanitize_key', (array) $required );
		if ( 'provider_buffered' === $offline_policy ) { $required[] = 'offline_issue_queue'; }
		$data['required_capabilities'] = array_values( array_unique( array_filter( $required ) ) );
		if ( ! $seller || 'active' !== $seller->status || ! in_array( $channel, array( 'pos', 'woocommerce', 'native' ), true ) || ! in_array( $environment, array( 'test', 'production' ), true ) || ! in_array( $status, array( 'draft', 'test', 'active', 'not_required', 'suspended' ), true ) || self::contains_secret_key( $data ) ) {
			return new \WP_Error( 'wpcap_fiscal_profile_invalid', __( 'Fiskalprofil, Verkäufer oder nicht geheime Konfiguration ist ungültig.', 'wp-pass-captain' ), array( 'status' => 400 ) );
		}
		if ( in_array( $status, array( 'active', 'not_required' ), true ) && ( 'production' !== $environment || empty( $input['review_attested'] ) || '' === $ruleset || '' === $ruleset_version || '' === $legal_reference ) ) {
			return new \WP_Error( 'wpcap_fiscal_profile_review_required', __( 'Produktivfreigabe erfordert dokumentierte Länderprüfung, Regelwerksversion und ausdrückliche Bestätigung.', 'wp-pass-captain' ), array( 'status' => 400 ) );
		}
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT profile_id,profile_version,created_at FROM {$p}wpcap_fiscal_profiles WHERE seller_id=%d AND country_code=%s AND channel=%s AND location_id=%d AND register_id=%s AND environment=%s", $seller_id, $seller->country_code, $channel, $location_id, $register_id, $environment ) );
		$now = current_time( 'mysql', true );
		$profile_version = $existing ? (int) $existing->profile_version + 1 : 1;
		$row = array(
			'seller_id' => $seller_id, 'country_code' => $seller->country_code, 'channel' => $channel,
			'location_id' => $location_id, 'register_id' => $register_id, 'environment' => $environment,
			'status' => $status, 'profile_version' => $profile_version, 'ruleset_key' => $ruleset,
			'ruleset_version' => $ruleset_version, 'legal_reference' => $legal_reference,
			'profile_data' => wp_json_encode( $data ), 'reviewed_by' => in_array( $status, array( 'active', 'not_required' ), true ) ? get_current_user_id() : 0,
			'reviewed_at' => in_array( $status, array( 'active', 'not_required' ), true ) ? $now : null,
			'effective_from' => ! empty( $input['effective_from'] ) ? sanitize_text_field( $input['effective_from'] ) : null,
			'effective_until' => ! empty( $input['effective_until'] ) ? sanitize_text_field( $input['effective_until'] ) : null,
			'created_at' => $existing ? $existing->created_at : $now, 'updated_at' => $now,
		);
		if ( $existing ) { $row['profile_id'] = (int) $existing->profile_id; }
		$result = $wpdb->replace( "{$p}wpcap_fiscal_profiles", $row );
		if ( false === $result ) { return new \WP_Error( 'wpcap_fiscal_profile_save', __( 'Fiskalprofil konnte nicht gespeichert werden.', 'wp-pass-captain' ), array( 'status' => 500 ) ); }
		Audit::record( 'fiscal_profile_saved', 'seller', $seller_id, array( 'country_code' => $seller->country_code, 'channel' => $channel, 'environment' => $environment, 'status' => $status, 'profile_version' => $profile_version ) );
		return array( 'profile_id' => $existing ? (int) $existing->profile_id : (int) $wpdb->insert_id, 'profile_version' => $profile_version, 'status' => $status );
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

	/**
	 * Resolve a trusted physical location/register scope for this order.
	 *
	 * POS or deployment integrations may map their configured terminal to a reviewed profile.
	 * Customer checkout data is not used as a profile selector.
	 */
	public static function profile_scope( $channel, array $internal, $order_id ) {
		$scope = apply_filters( 'wpcap_fiscal_profile_scope', array( 'location_id' => absint( $internal['fiscal_location_id'] ?? 0 ), 'register_id' => (string) ( $internal['fiscal_register_id'] ?? '' ) ), sanitize_key( $channel ), $internal, absint( $order_id ) );
		if ( ! is_array( $scope ) ) { return new \WP_Error( 'wpcap_fiscal_profile_scope_invalid', __( 'Kassenstellen-Zuordnung ist ungültig.', 'wp-pass-captain' ) ); }
		$register_id = (string) ( $scope['register_id'] ?? '' );
		if ( strlen( $register_id ) > 64 || ! preg_match( '/^[A-Za-z0-9_.:-]*$/', $register_id ) ) {
			return new \WP_Error( 'wpcap_fiscal_profile_scope_invalid', __( 'Kassen-ID darf nur Buchstaben, Ziffern sowie . _ : - enthalten und maximal 64 Zeichen lang sein.', 'wp-pass-captain' ) );
		}
		return array( 'location_id' => absint( $scope['location_id'] ?? 0 ), 'register_id' => $register_id );
	}

	/** Create immutable seller-specific fiscal documents in the order transaction. */
	public static function snapshot_order( $order_id, $selected, $payload, $internal ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$groups = array();
		$has_mapping = false;
		$source = sanitize_key( $internal['source'] ?? 'native' );
		$channel = 'woocommerce' === $source ? 'woocommerce' : ( 'pos' === $source ? 'pos' : 'native' );
		$scope = self::profile_scope( $channel, (array) $internal, $order_id );
		$scope_error = is_wp_error( $scope ) ? $scope : null;
		if ( $scope_error ) { $scope = array( 'location_id' => 0, 'register_id' => '' ); }
		$now = current_time( 'mysql', true );
		foreach ( (array) $selected as $line ) {
			$type_id = absint( $line['type']->ticket_type_id ?? 0 );
			$mapping = $wpdb->get_row( $wpdb->prepare( "SELECT ts.seller_id,ts.seller_mode,s.legal_name,s.country_code,s.tax_number,s.vat_id,s.business_address,s.contact_email,s.status AS seller_status,c.provider_id,c.configuration,c.enabled,fp.profile_id,fp.profile_version,fp.status AS profile_status,fp.ruleset_key,fp.ruleset_version,fp.legal_reference,fp.profile_data FROM {$p}wpcap_ticket_sellers ts INNER JOIN {$p}wpcap_legal_sellers s ON s.seller_id=ts.seller_id LEFT JOIN {$p}wpcap_fiscal_configs c ON c.seller_id=s.seller_id LEFT JOIN {$p}wpcap_fiscal_profiles fp ON fp.seller_id=s.seller_id AND fp.country_code=s.country_code AND fp.channel=%s AND fp.location_id=%d AND fp.register_id=%s AND fp.environment='production' AND fp.status IN ('active','not_required') AND (fp.effective_from IS NULL OR fp.effective_from<=%s) AND (fp.effective_until IS NULL OR fp.effective_until>=%s) WHERE ts.ticket_type_id=%d", $channel, (int) $scope['location_id'], $scope['register_id'], $now, $now, $type_id ) );
			if ( ! $mapping ) { continue; }
			if ( $scope_error ) { throw new \RuntimeException( 'fiscal_profile_scope_invalid' ); }
			$has_mapping = true;
			if ( 'active' !== $mapping->seller_status || ! $mapping->profile_id || ! in_array( $mapping->profile_status, array( 'active', 'not_required' ), true ) ) {
				throw new \RuntimeException( 'fiscal_profile_unavailable' );
			}
			if ( ! in_array( $mapping->seller_mode, array( 'organizer_seller', 'platform_seller' ), true ) ) {
				throw new \RuntimeException( 'fiscal_seller_invalid' );
			}
			$seller_id = (int) $mapping->seller_id;
			$seller_snapshot = array(
				'seller_id' => $seller_id, 'legal_name' => $mapping->legal_name, 'country_code' => strtoupper( $mapping->country_code ),
				'tax_number' => $mapping->tax_number, 'vat_id' => $mapping->vat_id,
				'business_address' => $mapping->business_address ? (array) json_decode( $mapping->business_address, true ) : array(),
				'contact_email' => $mapping->contact_email,
			);
			$profile_snapshot = array(
				'profile_id' => (int) $mapping->profile_id, 'version' => (int) $mapping->profile_version,
				'ruleset_key' => $mapping->ruleset_key, 'ruleset_version' => $mapping->ruleset_version,
				'legal_reference' => $mapping->legal_reference,
				'location_id' => (int) $scope['location_id'], 'register_id' => $scope['register_id'],
			);
			$wpdb->insert( "{$p}wpcap_order_seller_snapshots", array(
				'order_id' => absint( $order_id ), 'ticket_type_id' => $type_id, 'seller_id' => $seller_id,
				'seller_mode' => $mapping->seller_mode, 'channel' => $channel, 'country_code' => strtoupper( $mapping->country_code ),
				'profile_id' => (int) $mapping->profile_id, 'profile_version' => (int) $mapping->profile_version,
				'fiscal_applicability' => $mapping->profile_status, 'seller_snapshot' => wp_json_encode( $seller_snapshot ),
				'created_at' => $now,
			) );
			if ( '' !== $wpdb->last_error ) { throw new \RuntimeException( 'fiscal_snapshot_failed' ); }
			if ( 'not_required' === $mapping->profile_status ) { continue; }
			$adapter = Registry::get( $mapping->provider_id );
			if ( ! (int) $mapping->enabled || ! $adapter || ! in_array( strtoupper( $mapping->country_code ), array_map( 'strtoupper', (array) $adapter->countries() ), true ) ) {
				throw new \RuntimeException( 'fiscal_profile_unavailable' );
			}
			$profile_data = $mapping->profile_data ? (array) json_decode( $mapping->profile_data, true ) : array();
			$missing_capabilities = CountryPolicy::missing_capabilities( $adapter, $profile_data );
			if ( $missing_capabilities ) { throw new \RuntimeException( 'fiscal_adapter_capability_missing' ); }
			if ( ! isset( $groups[ $seller_id ] ) ) {
				$groups[ $seller_id ] = array(
					'seller' => $seller_snapshot,
					'profile' => array_merge( $profile_snapshot, array( 'data' => $profile_data ) ),
					'provider_id' => sanitize_key( $mapping->provider_id ),
					'configuration' => $mapping->configuration ? (array) json_decode( $mapping->configuration, true ) : array(),
					'items' => array(), 'gross_minor' => 0, 'tax_minor' => 0,
				);
			}
			$gross = max( 0, (int) $line['line_total'] );
			$tax = max( 0, (int) $line['tax_minor'] );
			$groups[ $seller_id ]['items'][] = array(
				'ticket_type_id' => $type_id,
				'description' => sanitize_text_field( (string) ( $line['type']->name ?? ( 'Ticket ' . $type_id ) ) ),
				'seller_mode' => $mapping->seller_mode,
				'quantity' => absint( $line['quantity'] ),
				'gross_minor' => $gross,
				'tax_rate_bp' => absint( $line['tax_rate_bp'] ),
				'tax_minor' => $tax,
			);
			$groups[ $seller_id ]['gross_minor'] += $gross;
			$groups[ $seller_id ]['tax_minor'] += $tax;
		}
		foreach ( (array) ( $internal['fiscal_extra_items'] ?? array() ) as $extra ) {
			$seller_id = absint( $extra['seller_id'] ?? 0 );
			$seller_mode = sanitize_key( $extra['seller_mode'] ?? '' );
			if ( ! $seller_id || ! in_array( $seller_mode, array( 'organizer_seller', 'platform_seller' ), true ) ) { throw new \RuntimeException( 'fiscal_extra_seller_invalid' ); }
			$mapping = $wpdb->get_row( $wpdb->prepare(
				"SELECT s.seller_id,s.legal_name,s.country_code,s.tax_number,s.vat_id,s.business_address,s.contact_email,s.status AS seller_status,c.provider_id,c.configuration,c.enabled,fp.profile_id,fp.profile_version,fp.status AS profile_status,fp.ruleset_key,fp.ruleset_version,fp.legal_reference,fp.profile_data FROM {$p}wpcap_legal_sellers s LEFT JOIN {$p}wpcap_fiscal_configs c ON c.seller_id=s.seller_id LEFT JOIN {$p}wpcap_fiscal_profiles fp ON fp.seller_id=s.seller_id AND fp.country_code=s.country_code AND fp.channel=%s AND fp.location_id=%d AND fp.register_id=%s AND fp.environment='production' AND fp.status IN ('active','not_required') AND (fp.effective_from IS NULL OR fp.effective_from<=%s) AND (fp.effective_until IS NULL OR fp.effective_until>=%s) WHERE s.seller_id=%d",
				$channel, (int) $scope['location_id'], $scope['register_id'], $now, $now, $seller_id
			) );
			if ( ! $mapping || 'active' !== $mapping->seller_status || ! $mapping->profile_id || ! in_array( $mapping->profile_status, array( 'active', 'not_required' ), true ) ) { throw new \RuntimeException( 'fiscal_extra_seller_profile_unavailable' ); }
			$has_mapping = true;
			if ( 'not_required' === $mapping->profile_status ) { continue; }
			$adapter = Registry::get( $mapping->provider_id );
			if ( ! (int) $mapping->enabled || ! $adapter || ! in_array( strtoupper( $mapping->country_code ), array_map( 'strtoupper', (array) $adapter->countries() ), true ) ) { throw new \RuntimeException( 'fiscal_extra_provider_unavailable' ); }
			$profile_data = $mapping->profile_data ? (array) json_decode( $mapping->profile_data, true ) : array();
			if ( CountryPolicy::missing_capabilities( $adapter, $profile_data ) ) { throw new \RuntimeException( 'fiscal_adapter_capability_missing' ); }
			$seller_snapshot = array(
				'seller_id' => $seller_id, 'legal_name' => $mapping->legal_name, 'country_code' => strtoupper( $mapping->country_code ),
				'tax_number' => $mapping->tax_number, 'vat_id' => $mapping->vat_id,
				'business_address' => $mapping->business_address ? (array) json_decode( $mapping->business_address, true ) : array(),
				'contact_email' => $mapping->contact_email,
			);
			$profile_snapshot = array(
				'profile_id' => (int) $mapping->profile_id, 'version' => (int) $mapping->profile_version,
				'ruleset_key' => $mapping->ruleset_key, 'ruleset_version' => $mapping->ruleset_version,
				'legal_reference' => $mapping->legal_reference, 'location_id' => (int) $scope['location_id'], 'register_id' => $scope['register_id'],
			);
			if ( ! isset( $groups[$seller_id] ) ) {
				$groups[$seller_id] = array(
					'seller' => $seller_snapshot,
					'profile' => array_merge( $profile_snapshot, array( 'data' => $profile_data ) ),
					'provider_id' => sanitize_key( $mapping->provider_id ),
					'configuration' => $mapping->configuration ? (array) json_decode( $mapping->configuration, true ) : array(),
					'items' => array(), 'gross_minor' => 0, 'tax_minor' => 0,
				);
			}
			$gross = max( 0, (int) ( $extra['gross_minor'] ?? 0 ) );
			$tax = min( $gross, max( 0, (int) ( $extra['tax_minor'] ?? 0 ) ) );
			$groups[$seller_id]['items'][] = array(
				'line_key' => sanitize_key( $extra['line_key'] ?? '' ),
				'product_id' => absint( $extra['product_id'] ?? 0 ),
				'description' => sanitize_text_field( (string) ( $extra['description'] ?? __( 'WooCommerce-Position', 'wp-pass-captain' ) ) ),
				'seller_mode' => $seller_mode,
				'quantity' => max( 1, absint( $extra['quantity'] ?? 1 ) ),
				'gross_minor' => $gross,
				'tax_rate_bp' => absint( $extra['tax_rate_bp'] ?? 0 ),
				'tax_minor' => $tax,
			);
			$groups[$seller_id]['gross_minor'] += $gross;
			$groups[$seller_id]['tax_minor'] += $tax;
		}
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT currency,payment_provider FROM {$p}wpcap_orders WHERE order_id=%d", absint( $order_id ) ) );
		if ( $has_mapping ) { $wpdb->replace( "{$p}wpcap_fiscal_order_state", array( 'order_id' => absint( $order_id ), 'fiscal_status' => $groups ? 'pending' : 'not_required', 'fulfillment_status' => $groups ? 'blocked' : 'awaiting_payment', 'updated_at' => $now ), array( '%d', '%s', '%s', '%s' ) ); }
		foreach ( $groups as $seller_id => $group ) {
			$key = hash( 'sha256', 'wpcap-fiscal-v1:' . absint( $order_id ) . ':' . $seller_id );
			if ( self::contains_secret_key( $group['configuration'] ) ) { throw new \RuntimeException( 'fiscal_configuration_contains_secret' ); }
			$snapshot = array( 'schema_version' => 1, 'order_id' => absint( $order_id ), 'seller' => $group['seller'], 'seller_mode' => $group['items'][0]['seller_mode'], 'profile' => $group['profile'], 'provider_id' => $group['provider_id'], 'provider_configuration' => $group['configuration'], 'currency' => $order->currency ?? 'EUR', 'payment_method' => sanitize_key( $order->payment_provider ?? '' ), 'created_at' => current_time( 'mysql', true ), 'gross_minor' => $group['gross_minor'], 'tax_minor' => $group['tax_minor'], 'items' => $group['items'] );
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
				if ( empty( $item['ticket_type_id'] ) ) { continue; }
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
			if ( ! $adapter ) { self::fail( $doc, 'adapter_unavailable' ); continue; }
			$config_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_fiscal_configs WHERE seller_id=%d", $doc->seller_id ), ARRAY_A );
			$document = json_decode( $doc->snapshot, true );
			if ( ! is_array( $document ) ) { self::fail( $doc, 'snapshot_invalid' ); continue; }
			$seller = is_array( $document['seller'] ?? null ) ? $document['seller'] : $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_legal_sellers WHERE seller_id=%d", $doc->seller_id ), ARRAY_A );
			if ( ! is_array( $seller ) || empty( $seller['seller_id'] ) ) { self::fail( $doc, 'seller_snapshot_invalid' ); continue; }
			$configuration = is_array( $document['provider_configuration'] ?? null ) ? $document['provider_configuration'] : ( $config_row && $config_row['configuration'] ? (array) json_decode( $config_row['configuration'], true ) : array() );
			$configuration = Credentials::with_credentials( $configuration, (int) $doc->seller_id, $doc->provider_id );
			$document['document_id'] = (int) $doc->document_id;
			$document['idempotency_key'] = $doc->document_key;
			$document['attempt'] = (int) $doc->attempts + 1;
			$country_codes = array_map( 'strtoupper', (array) $adapter->countries() );
			if ( ! in_array( strtoupper( $doc->country_code ), $country_codes, true ) ) { self::fail( $doc, 'country_unsupported' ); continue; }
			$profile_data = (array) ( $document['profile']['data'] ?? array() );
			$missing = CountryPolicy::missing_capabilities( $adapter, $profile_data );
			if ( $missing ) { self::fail( $doc, 'capability_missing:' . implode( ',', $missing ) ); continue; }
			$result = null;
			if ( (int) $doc->attempts > 0 ) {
				self::record_event( $doc, 'status_query', 'started', array( 'attempt' => (int) $doc->attempts + 1 ) );
				$lookup = $adapter->query_status( $document, (array) $seller, $configuration );
				if ( is_wp_error( $lookup ) ) {
					self::record_event( $doc, 'status_query', 'unknown', array( 'error_code' => sanitize_key( $lookup->get_error_code() ) ) );
					self::fail( $doc, 'status_unknown:' . sanitize_key( $lookup->get_error_code() ) );
					continue;
				}
				if ( is_array( $lookup ) && 'submitted' === ( $lookup['status'] ?? '' ) && ! empty( $lookup['reference'] ) && is_scalar( $lookup['reference'] ) ) {
					$result = $lookup;
					self::record_event( $doc, 'status_query', 'submitted', array( 'reference' => sanitize_text_field( $lookup['reference'] ) ) );
				} elseif ( ! is_array( $lookup ) || 'not_found' !== ( $lookup['status'] ?? '' ) ) {
					self::record_event( $doc, 'status_query', 'unknown', array( 'error_code' => 'invalid_status_result' ) );
					self::fail( $doc, 'status_unknown:invalid_status_result' );
					continue;
				} else {
					self::record_event( $doc, 'status_query', 'not_found', array( 'attempt' => (int) $doc->attempts + 1 ) );
				}
			}
			if ( null === $result ) {
				self::record_event( $doc, 'submission', 'started', array( 'attempt' => (int) $doc->attempts + 1 ) );
				$result = $adapter->submit( $document, (array) $seller, $configuration );
			}
			if ( is_wp_error( $result ) || ! is_array( $result ) || empty( $result['reference'] ) || ! is_scalar( $result['reference'] ) ) {
				$error_code = is_wp_error( $result ) ? sanitize_key( $result->get_error_code() ) : 'invalid_result';
				self::record_event( $doc, 'submission', 'failed', array( 'error_code' => $error_code ) );
				self::fail( $doc, 'provider_error:' . $error_code );
				continue;
			}
			$receipt = is_array( $result['receipt'] ?? null ) ? $result['receipt'] : array();
			if ( in_array( 'receipt_qr_payload', $missing, true ) || in_array( 'receipt_qr_payload', CountryPolicy::required_capabilities( $profile_data ), true ) ) {
				if ( empty( $receipt['qr_payload'] ) || ! is_string( $receipt['qr_payload'] ) || strlen( $receipt['qr_payload'] ) > 4096 ) {
					self::record_event( $doc, 'submission', 'invalid_result', array( 'error_code' => 'qr_payload_missing' ) );
					self::fail( $doc, 'provider_result_missing_qr_payload' );
					continue;
				}
			}
			$wpdb->update( "{$p}wpcap_fiscal_documents", array(
				'status' => 'submitted', 'provider_result' => wp_json_encode( self::normalize_provider_result( $result ) ), 'last_error' => '',
				'attempts' => (int) $doc->attempts + 1, 'updated_at' => current_time( 'mysql', true ),
			), array( 'document_id' => (int) $doc->document_id ) );
			self::record_event( $doc, 'submission', 'submitted', array( 'reference' => sanitize_text_field( $result['reference'] ) ) );
			Audit::record( 'fiscal_document_submitted', 'order', (int) $order_id, array( 'document_id' => (int) $doc->document_id, 'seller_id' => (int) $doc->seller_id, 'provider_id' => $doc->provider_id ) );
		}
	}

	/** Persist only normalized, printable receipt fields from an adapter response. */
	private static function normalize_provider_result( array $result ) {
		$reference = is_scalar( $result['reference'] ?? null ) ? substr( sanitize_text_field( (string) $result['reference'] ), 0, 191 ) : '';
		$normalized = array( 'reference' => $reference );
		$receipt = is_array( $result['receipt'] ?? null ) ? $result['receipt'] : array();
		$out = array();
		if ( is_string( $receipt['qr_payload'] ?? null ) && strlen( $receipt['qr_payload'] ) <= 4096 ) {
			$out['qr_payload'] = $receipt['qr_payload'];
		}
		$fields = is_array( $receipt['fields'] ?? null ) ? array_slice( $receipt['fields'], 0, 32, true ) : array();
		foreach ( $fields as $label => $value ) {
			if ( ! is_scalar( $value ) ) { continue; }
			$label = substr( sanitize_text_field( (string) $label ), 0, 64 );
			if ( '' === $label ) { continue; }
			$out['fields'][ $label ] = substr( sanitize_text_field( (string) $value ), 0, 512 );
		}
		if ( $out ) { $normalized['receipt'] = $out; }
		return $normalized;
	}

	/** Append-only operation event; caller passes metadata with no credentials or raw provider body. */
	/** Offline behavior is conservative unless a provider explicitly advertises buffered issuance. */
	public static function normalize_offline_policy( $policy ) {
		$policy = sanitize_key( $policy );
		return in_array( $policy, array( 'stop_sale', 'provider_buffered' ), true )
			? $policy
			: new \WP_Error( 'wpcap_fiscal_offline_policy_invalid', __( 'Unbekannte Fiskal-Ausfallregel.', 'wp-pass-captain' ), array( 'status' => 400 ) );
	}

	/** Record a completed refund as a seller/provider correction task; no adapter currently executes credit notes. */
	public static function record_refund_adjustment( $refund_id, $order_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$refund = $wpdb->get_row( $wpdb->prepare( "SELECT refund_id,amount_minor,currency,ticket_ids FROM {$p}wpcap_refunds WHERE refund_id=%d AND order_id=%d", absint( $refund_id ), absint( $order_id ) ) );
		if ( ! $refund ) { return; }
		$ticket_ids = array_values( array_filter( array_map( 'absint', (array) json_decode( $refund->ticket_ids, true ) ) ) );
		$ticket_types = array();
		$used_by_type = array();
		if ( $ticket_ids ) {
			$ids = implode( ',', $ticket_ids );
			foreach ( $wpdb->get_results( "SELECT ticket_type_id,status FROM {$p}wpcap_tickets WHERE order_id=" . absint( $order_id ) . " AND ticket_id IN ({$ids})" ) as $ticket ) { // phpcs:ignore WordPress.DB
				$type_id = absint( $ticket->ticket_type_id );
				$ticket_types[] = $type_id;
				if ( 'checked_in' === $ticket->status ) { $used_by_type[$type_id] = ( $used_by_type[$type_id] ?? 0 ) + 1; }
			}
		}
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT total_minor,refunded_minor FROM {$p}wpcap_orders WHERE order_id=%d", absint( $order_id ) ) );
		$metadata = array(
			'refund_id' => absint( $refund_id ),
			'adjustment_kind' => 'refund_credit_note_or_correction',
			'refund_amount_minor' => absint( $refund->amount_minor ),
			'currency' => sanitize_text_field( $refund->currency ),
			'ticket_ids' => $ticket_ids,
			'used_ticket_count' => array_sum( $used_by_type ),
			'used_ticket_count_by_type' => $used_by_type,
			'order_fully_refunded' => $order && (int) $order->total_minor > 0 && (int) $order->refunded_minor >= (int) $order->total_minor,
			'seller_allocation_required' => empty( $ticket_types ),
			'provider_action' => 'manual_review_required',
		);
		self::record_adjustment_events( absint( $order_id ), 'refund_adjustment', $metadata, array_values( array_unique( $ticket_types ) ) );
	}

	/** Record refunded non-ticket WooCommerce lines against the seller snapshots used at sale time. */
	public static function record_non_ticket_refund( $order_id, $refund_id, $currency, array $lines ) {
		global $wpdb;
		$order_id = absint( $order_id );
		if ( ! $order_id || ! $lines ) { return; }
		$docs = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}wpcap_fiscal_documents WHERE order_id=%d AND status='submitted' ORDER BY seller_id",
			$order_id
		) );
		foreach ( $docs as $doc ) {
			$snapshot = json_decode( $doc->snapshot, true );
			$snapshot_items = is_array( $snapshot['items'] ?? null ) ? $snapshot['items'] : array();
			$by_key = array();
			foreach ( $snapshot_items as $snapshot_item ) {
				$key = sanitize_key( $snapshot_item['line_key'] ?? '' );
				if ( '' !== $key ) { $by_key[ $key ] = $snapshot_item; }
			}
			$allocated = array();
			$unmatched = array();
			foreach ( $lines as $line ) {
				$key = sanitize_key( $line['line_key'] ?? '' );
				if ( '' === $key || ! isset( $by_key[ $key ] ) ) {
					$unmatched[] = array(
						'line_key' => $key,
						'amount_minor' => absint( $line['amount_minor'] ?? 0 ),
					);
					continue;
				}
				$allocated[] = array(
					'line_key' => $key,
					'product_id' => absint( $by_key[ $key ]['product_id'] ?? 0 ),
					'description' => sanitize_text_field( $by_key[ $key ]['description'] ?? '' ),
					'quantity' => absint( $line['quantity'] ?? 0 ),
					'amount_minor' => absint( $line['amount_minor'] ?? 0 ),
					'tax_minor' => absint( $line['tax_minor'] ?? 0 ),
				);
			}
			if ( ! $allocated && ! $unmatched ) { continue; }
			self::record_event( $doc, 'refund_adjustment', 'manual_required', array(
				'adjustment_kind' => 'refund_credit_note_or_correction',
				'refund_id' => absint( $refund_id ),
				'currency' => sanitize_text_field( $currency ),
				'allocated_items' => $allocated,
				'unmatched_items' => $unmatched,
				'seller_allocation_required' => (bool) $unmatched,
				'provider_action' => 'manual_review_required',
			) );
		}
	}

	/** Submit a one-time efsta full void only when a queued order was cancelled before payment. */
	public static function submit_efsta_void( $event_id ) {
		global $wpdb;
		$event_id = absint( $event_id );
		$source = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_fiscal_events WHERE event_id=%d AND event_status='manual_required'", $event_id ) );
		if ( ! $source || 'order_cancellation' !== $source->event_type || 'efsta_efr' !== $source->provider_id ) {
			return new \WP_Error( 'wpcap_fiscal_void_unavailable', __( 'Für diesen Vorgang ist kein automatisches efsta-Storno verfügbar.', 'wp-pass-captain' ) );
		}
		$metadata = json_decode( $source->metadata, true );
		if ( ! is_array( $metadata ) || 'void' !== sanitize_key( $metadata['adjustment_kind'] ?? '' ) ) {
			return new \WP_Error( 'wpcap_fiscal_void_payment_exchanged', __( 'Ein bereits bezahlter Vorgang darf nicht als Storno automatisiert werden.', 'wp-pass-captain' ) );
		}
		$doc = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_fiscal_documents WHERE document_id=%d", absint( $source->document_id ) ) );
		$document = $doc ? json_decode( $doc->snapshot, true ) : null;
		if ( ! $doc || ! is_array( $document ) || 'efsta_efr' !== $doc->provider_id || empty( $document['seller']['seller_id'] ) || ! is_array( $document['provider_configuration'] ?? null ) ) {
			return new \WP_Error( 'wpcap_fiscal_void_snapshot_missing', __( 'Der unveränderbare Originalbeleg oder seine Anbieterkonfiguration fehlt.', 'wp-pass-captain' ) );
		}
		$adapter = Registry::get( 'efsta_efr' );
		if ( ! $adapter || ! is_callable( array( $adapter, 'supports_correction' ) ) || ! $adapter->supports_correction( $doc->country_code, 'void' ) || ! is_callable( array( $adapter, 'prepare_void' ) ) || ! is_callable( array( $adapter, 'submit_void' ) ) ) {
			return new \WP_Error( 'wpcap_fiscal_void_country_unsupported', __( 'Automatisches efsta-Storno ist für dieses Land nicht freigeschaltet.', 'wp-pass-captain' ) );
		}
		$seller = (array) $document['seller'];
		$configuration = Credentials::with_credentials( (array) $document['provider_configuration'], absint( $seller['seller_id'] ), 'efsta_efr' );
		$prepared = $adapter->prepare_void( $document, $seller, $configuration, $event_id );
		if ( is_wp_error( $prepared ) ) { return $prepared; }
		$lock = 'wpcap_fiscal_void_attempt_' . $event_id;
		if ( ! add_option( $lock, current_time( 'mysql', true ), '', false ) ) {
			return new \WP_Error( 'wpcap_fiscal_void_already_attempted', __( 'Für dieses Storno gab es bereits einen Sendeversuch. Anbieterstatus prüfen und nicht erneut senden.', 'wp-pass-captain' ) );
		}
		self::record_event( $source, 'provider_void_attempted', 'submitting', array(
			'source_event_id' => $event_id,
			'adjustment_kind' => 'void',
			'idempotency_key' => hash( 'sha256', $doc->document_key . ':void:' . $event_id ),
			'provider_action' => 'one_time_submission',
		) );
		$result = $adapter->submit_void( $document, $seller, $configuration, $event_id, $prepared );
		if ( is_wp_error( $result ) ) {
			$code = sanitize_key( $result->get_error_code() );
			self::record_event( $source, 'provider_void_failed', 'manual_required', array( 'source_event_id' => $event_id, 'error_code' => $code, 'provider_action' => 'status_reconciliation_required' ) );
			Audit::record( 'fiscal_provider_void_failed', 'fiscal_event', $event_id, array( 'provider_id' => 'efsta_efr', 'error_code' => $code ) );
			return new \WP_Error( 'wpcap_fiscal_void_failed', __( 'efsta-Storno konnte nicht bestätigt werden. Anbieterstatus prüfen und keine automatische Wiederholung auslösen.', 'wp-pass-captain' ), array( 'status' => 409, 'provider_error_code' => $code ) );
		}
		$reference = sanitize_text_field( $result['reference'] ?? '' );
		self::record_event( $source, 'manual_correction_recorded', 'submitted_by_adapter', array(
			'resolves_event_id' => $event_id,
			'resolution' => 'void',
			'provider_reference' => $reference,
			'provider_action' => 'submitted_by_adapter',
			'recorded_by' => get_current_user_id(),
		) );
		Audit::record( 'fiscal_provider_void_submitted', 'fiscal_event', $event_id, array( 'provider_id' => 'efsta_efr', 'reference' => $reference ) );
		return array( 'event_id' => $event_id, 'status' => 'submitted', 'provider_reference' => $reference );
	}

	public static function admin_submit_efsta_void() {
		$event_id = absint( $_POST['event_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		check_admin_referer( 'wpcap_fiscal_submit_efsta_void_' . $event_id );
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Nicht berechtigt.', 'wp-pass-captain' ), '', array( 'response' => 403 ) ); }
		self::submit_efsta_void( $event_id );
		wp_safe_redirect( add_query_arg( 'page', 'wpcap-fiscalization', admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Append an operator-confirmed record that a correction was completed in the provider's own system. */
	public static function record_manual_resolution( $event_id, $resolution, $provider_reference, $note = '' ) {
		global $wpdb;
		$event_id = absint( $event_id );
		$resolution = sanitize_key( $resolution );
		$provider_reference = sanitize_text_field( $provider_reference );
		$note = sanitize_textarea_field( $note );
		if ( ! $event_id || ! in_array( $resolution, array( 'void', 'refund', 'credit_note', 'correction' ), true ) || '' === $provider_reference || strlen( $provider_reference ) > 191 ) {
			return new \WP_Error( 'wpcap_fiscal_resolution_invalid', __( 'Korrekturart und Anbieter-Referenz sind erforderlich.', 'wp-pass-captain' ) );
		}
		$source = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_fiscal_events WHERE event_id=%d AND event_status='manual_required'", $event_id ) );
		if ( ! $source || ! in_array( $source->event_type, array( 'refund_adjustment', 'order_cancellation', 'event_cancellation' ), true ) ) {
			return new \WP_Error( 'wpcap_fiscal_resolution_source', __( 'Offener Fiskalkorrekturvorgang wurde nicht gefunden.', 'wp-pass-captain' ) );
		}
		$existing = $wpdb->get_results( $wpdb->prepare(
			"SELECT metadata FROM {$wpdb->prefix}wpcap_fiscal_events WHERE document_id=%d AND event_type='manual_correction_recorded'",
			(int) $source->document_id
		) );
		foreach ( $existing as $row ) {
			$metadata = json_decode( $row->metadata, true );
			if ( is_array( $metadata ) && (int) ( $metadata['resolves_event_id'] ?? 0 ) === $event_id ) {
				return new \WP_Error( 'wpcap_fiscal_resolution_exists', __( 'Für diesen Vorgang wurde bereits eine Anbieter-Referenz dokumentiert.', 'wp-pass-captain' ) );
			}
		}
		self::record_event( $source, 'manual_correction_recorded', 'recorded_externally', array(
			'resolves_event_id' => $event_id,
			'resolution' => $resolution,
			'provider_reference' => $provider_reference,
			'note' => $note,
			'recorded_by' => get_current_user_id(),
			'provider_action' => 'completed_outside_plugin',
		) );
		Audit::record( 'fiscal_manual_correction_recorded', 'fiscal_event', $event_id, array( 'provider_id' => sanitize_key( $source->provider_id ), 'resolution' => $resolution ) );
		return array( 'event_id' => $event_id, 'status' => 'recorded_externally', 'provider_reference' => $provider_reference );
	}

	public static function rest_record_manual_resolution( \WP_REST_Request $request ) {
		$result = self::record_manual_resolution(
			absint( $request['event_id'] ),
			sanitize_key( $request->get_param( 'resolution' ) ),
			sanitize_text_field( $request->get_param( 'provider_reference' ) ),
			sanitize_textarea_field( $request->get_param( 'note' ) )
		);
		return is_wp_error( $result ) ? new \WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 409 ) ) : rest_ensure_response( $result );
	}

	public static function admin_record_manual_resolution() {
		$event_id = absint( $_POST['event_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		check_admin_referer( 'wpcap_fiscal_manual_resolution_' . $event_id );
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Nicht berechtigt.', 'wp-pass-captain' ), '', array( 'response' => 403 ) ); }
		$result = self::record_manual_resolution(
			$event_id,
			sanitize_key( wp_unslash( $_POST['resolution'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			sanitize_text_field( wp_unslash( $_POST['provider_reference'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
		);
		$redirect = add_query_arg( 'wpcap_fiscal_resolution', is_wp_error( $result ) ? 'error' : 'recorded', admin_url( 'admin.php?page=wpcap-fiscalization' ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	/** Record the required provider action when a completed fiscal document's order is cancelled. */
	public static function record_order_cancellation( $order_id, $reason = '', $previous_payment_status = '' ) {
		$kind = in_array( sanitize_key( $previous_payment_status ), array( 'paid', 'paid_unallocated', 'refunded' ), true ) ? 'refund_or_correction' : 'void';
		self::record_adjustment_events( absint( $order_id ), 'order_cancellation', array(
			'adjustment_kind' => $kind,
			'reason' => sanitize_text_field( $reason ),
			'previous_payment_status' => sanitize_key( $previous_payment_status ),
			'provider_action' => 'manual_review_required',
		) );
	}

	/** Record event-cancellation corrections for any already submitted seller receipt containing that event. */
	public static function record_event_cancellation( $event_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$event_id = absint( $event_id );
		if ( ! $event_id ) { return; }
		$docs = $wpdb->get_results( $wpdb->prepare(
			"SELECT DISTINCT d.* FROM {$p}wpcap_fiscal_documents d INNER JOIN {$p}wpcap_fiscal_document_items i ON i.document_id=d.document_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=i.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE o.event_id=%d AND d.status='submitted'",
			$event_id
		) );
		foreach ( $docs as $doc ) {
			self::record_event( $doc, 'event_cancellation', 'manual_required', array(
				'adjustment_kind' => 'refund_or_correction',
				'event_id' => $event_id,
				'provider_action' => 'manual_review_required',
				'reason' => 'event_cancelled',
			) );
		}
	}

	/** Append a correction task to each affected seller receipt; leave originals immutable. */
	private static function record_adjustment_events( $order_id, $event_type, array $metadata, array $ticket_types = array() ) {
		global $wpdb;
		$docs = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_fiscal_documents WHERE order_id=%d AND status='submitted' ORDER BY seller_id", absint( $order_id ) ) );
		$matching = array();
		foreach ( $docs as $doc ) {
			$snapshot = json_decode( $doc->snapshot, true );
			$doc_types = array_values( array_unique( array_map( 'absint', array_column( (array) ( $snapshot['items'] ?? array() ), 'ticket_type_id' ) ) ) );
			$intersection = $ticket_types ? array_values( array_intersect( $ticket_types, $doc_types ) ) : array();
			if ( $ticket_types && ! $intersection ) { continue; }
			$matching[] = array( 'doc' => $doc, 'ticket_types' => $intersection );
		}
		$shared = $metadata;
		$shared['seller_allocation_required'] = ! $ticket_types || count( $matching ) > 1;
		foreach ( $matching as $entry ) {
			$per_seller = $shared;
			$per_seller['ticket_types'] = $entry['ticket_types'];
			self::record_event( $entry['doc'], $event_type, 'manual_required', $per_seller );
		}
	}

	private static function record_event( $doc, $type, $status, array $metadata = array() ) {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'wpcap_fiscal_events', array(
			'document_id' => (int) $doc->document_id, 'order_id' => (int) $doc->order_id, 'provider_id' => sanitize_key( $doc->provider_id ),
			'event_type' => sanitize_key( $type ), 'event_status' => sanitize_key( $status ),
			'metadata' => wp_json_encode( $metadata ), 'created_at' => current_time( 'mysql', true ),
		), array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' ) );
	}


	/** Scheduled retry callback; adapters receive a stable idempotency key for every retry. */
	/** Records ticket fulfillment separately from payment and fiscal submission status. */
	public static function mark_fulfilled( $order_id, $count ) {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'wpcap_fiscal_order_state', array(
			'fulfillment_status' => 'tickets_issued', 'updated_at' => current_time( 'mysql', true ),
		), array( 'order_id' => absint( $order_id ) ), array( '%s', '%s' ), array( '%d' ) );
	}

	public static function process_scheduled_order( $order_id ) {
		// issue_and_email runs the fiscal readiness filter before issuing any ticket.
		Orders::issue_and_email( absint( $order_id ) );
	}

	public static function rest_reconcile_document( \WP_REST_Request $request ) {
		global $wpdb;
		$doc = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_fiscal_documents WHERE document_id=%d", absint( $request['document_id'] ) ) );
		if ( ! $doc ) { return new \WP_Error( 'wpcap_fiscal_document_missing', __( 'Fiskalvorgang wurde nicht gefunden.', 'wp-pass-captain' ), array( 'status' => 404 ) ); }
		$adapter = Registry::get( $doc->provider_id );
		$config = $wpdb->get_row( $wpdb->prepare( "SELECT configuration FROM {$wpdb->prefix}wpcap_fiscal_configs WHERE seller_id=%d", $doc->seller_id ), ARRAY_A );
		$decoded = json_decode( $doc->snapshot, true );
		$document = is_array( $decoded ) ? $decoded : array();
		$seller = is_array( $document['seller'] ?? null ) ? $document['seller'] : $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_legal_sellers WHERE seller_id=%d", $doc->seller_id ), ARRAY_A );
		$has_snapshot_config = is_array( $document['provider_configuration'] ?? null );
		if ( ! $adapter || ! is_array( $seller ) || empty( $seller['seller_id'] ) || ( ! $config && ! $has_snapshot_config ) ) { return new \WP_Error( 'wpcap_fiscal_reconcile_unavailable', __( 'Anbieter oder Verkäuferdaten fehlen.', 'wp-pass-captain' ), array( 'status' => 409 ) ); }
		$configuration = $has_snapshot_config ? $document['provider_configuration'] : ( $config && $config['configuration'] ? (array) json_decode( $config['configuration'], true ) : array() );
		$configuration = Credentials::with_credentials( $configuration, (int) $doc->seller_id, $doc->provider_id );
		$document['document_id'] = (int) $doc->document_id;
		$document['idempotency_key'] = $doc->document_key;
		$result = $adapter->query_status( $document, $seller, $configuration );
		if ( is_wp_error( $result ) ) {
			self::record_event( $doc, 'manual_reconcile', 'unknown', array( 'error_code' => sanitize_key( $result->get_error_code() ) ) );
			return new \WP_Error( 'wpcap_fiscal_status_unknown', __( 'Der Anbieterstatus ist weiterhin unklar; es wurde keine neue Beleganlage ausgelöst.', 'wp-pass-captain' ), array( 'status' => 503 ) );
		}
		if ( is_array( $result ) && 'submitted' === ( $result['status'] ?? '' ) && ! empty( $result['reference'] ) && is_scalar( $result['reference'] ) ) {
			$snapshot = json_decode( $doc->snapshot, true );
			$profile_data = is_array( $snapshot['profile']['data'] ?? null ) ? $snapshot['profile']['data'] : array();
			$receipt = is_array( $result['receipt'] ?? null ) ? $result['receipt'] : array();
			if ( in_array( 'receipt_qr_payload', CountryPolicy::required_capabilities( $profile_data ), true ) && ( ! is_string( $receipt['qr_payload'] ?? null ) || '' === $receipt['qr_payload'] || strlen( $receipt['qr_payload'] ) > 4096 ) ) {
				self::record_event( $doc, 'manual_reconcile', 'invalid_result', array( 'error_code' => 'qr_payload_missing' ) );
				return new \WP_Error( 'wpcap_fiscal_receipt_incomplete', __( 'Der Anbieter meldet den Beleg als erstellt, aber die erforderlichen QR-Daten fehlen. Es wurde kein zweiter Beleg erstellt.', 'wp-pass-captain' ), array( 'status' => 502 ) );
			}
			$wpdb->update( $wpdb->prefix . 'wpcap_fiscal_documents', array( 'status' => 'submitted', 'provider_result' => wp_json_encode( self::normalize_provider_result( $result ) ), 'last_error' => '', 'updated_at' => current_time( 'mysql', true ) ), array( 'document_id' => (int) $doc->document_id ) );
			self::record_event( $doc, 'manual_reconcile', 'submitted', array( 'reference' => sanitize_text_field( $result['reference'] ) ) );
		} elseif ( is_array( $result ) && 'not_found' === ( $result['status'] ?? '' ) ) {
			$wpdb->update( $wpdb->prefix . 'wpcap_fiscal_documents', array( 'status' => 'retry', 'last_error' => '', 'updated_at' => current_time( 'mysql', true ) ), array( 'document_id' => (int) $doc->document_id ) );
			self::record_event( $doc, 'manual_reconcile', 'not_found', array() );
			self::process_order( (int) $doc->order_id );
		} else {
			self::record_event( $doc, 'manual_reconcile', 'unknown', array( 'error_code' => 'invalid_status_result' ) );
			return new \WP_Error( 'wpcap_fiscal_status_unknown', __( 'Der Anbieter hat keinen verwertbaren Status geliefert.', 'wp-pass-captain' ), array( 'status' => 502 ) );
		}
		Orders::issue_and_email( (int) $doc->order_id );
		return rest_ensure_response( array( 'document_id' => (int) $doc->document_id, 'status' => $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}wpcap_fiscal_documents WHERE document_id=%d", $doc->document_id ) ) ) );
	}

	public static function rest_retry_order( \WP_REST_Request $request ) {
		global $wpdb;
		$order_id = absint( $request['order_id'] );
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_fiscal_documents SET status='retry',last_error='',updated_at=%s WHERE order_id=%d AND status='failed'", current_time( 'mysql', true ), $order_id ) );
		self::process_order( $order_id );
		Orders::issue_and_email( $order_id );
		$blocked = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_fiscal_documents WHERE order_id=%d AND status <> 'submitted'", $order_id ) );
		return rest_ensure_response( array( 'order_id' => $order_id, 'tickets_issued' => ! $blocked && (bool) Orders::tickets_for_order( $order_id ), 'fiscal_pending' => (bool) $blocked ) );
	}

	private static function failure_is_permanent( $message ) {
		$permanent = array( 'adapter_unavailable', 'snapshot_invalid', 'country_unsupported', 'fiscal_profile_scope_invalid' );
		foreach ( $permanent as $code ) {
			if ( 0 === strpos( (string) $message, $code ) ) { return true; }
		}
		return 0 === strpos( (string) $message, 'capability_missing:' );
	}

	private static function fail( $doc, $message ) {
		global $wpdb;
		$attempts = (int) $doc->attempts + 1;
		$terminal = self::failure_is_permanent( $message ) || $attempts >= 8;
		$wpdb->update( $wpdb->prefix . 'wpcap_fiscal_documents', array(
			'status' => $terminal ? 'failed' : 'retry', 'last_error' => sanitize_text_field( $message ),
			'attempts' => $attempts, 'updated_at' => current_time( 'mysql', true ),
		), array( 'document_id' => (int) $doc->document_id ) );
		if ( ! $terminal && ! wp_next_scheduled( 'wpcap_process_fiscal_order', array( (int) $doc->order_id ) ) ) {
			$delay = min( HOUR_IN_SECONDS, 60 * ( 2 ** min( 10, $attempts ) ) );
			wp_schedule_single_event( time() + $delay, 'wpcap_process_fiscal_order', array( (int) $doc->order_id ) );
			return;
		}
		if ( $terminal ) {
			$seller_name = (string) $wpdb->get_var( $wpdb->prepare( "SELECT legal_name FROM {$wpdb->prefix}wpcap_legal_sellers WHERE seller_id=%d", (int) $doc->seller_id ) );
			$details = array(
				'document_id' => (int) $doc->document_id,
				'order_id' => (int) $doc->order_id,
				'seller_id' => (int) $doc->seller_id,
				'provider_id' => sanitize_key( $doc->provider_id ),
				'error_code' => sanitize_key( $message ),
				'permanent' => self::failure_is_permanent( $message ),
			);
			Audit::record( 'fiscal_document_terminal_failure', 'order', (int) $doc->order_id, $details );
			do_action( 'wpcap_fiscal_terminal_failure', $details );
			$recipient = get_option( 'admin_email', '' );
			if ( is_email( $recipient ) ) {
				$subject = __( 'WP Pass Captain: Fiskalvorgang erfordert Eingriff', 'wp-pass-captain' );
				$body = sprintf(
					/* translators: 1: document ID, 2: order ID, 3: seller, 4: provider ID, 5: error code */
					__( 'Der Fiskalvorgang %1$d für Bestellung %2$d ist fehlgeschlagen. Verkäufer: %3$s. Anbieter: %4$s. Fehlercode: %5$s. Ticket-Ausgabe bleibt gesperrt, bis der Vorgang geprüft und behoben wurde.', 'wp-pass-captain' ),
					(int) $doc->document_id,
					(int) $doc->order_id,
					$seller_name,
					sanitize_key( $doc->provider_id ),
					sanitize_text_field( $message )
				);
				wp_mail( $recipient, $subject, $body );
			}
		}
	}

	/** Runs submission again on payment callback retries; ticket issuance is blocked until success. */
	public static function fiscal_ready( $ready, $order_id ) {
		global $wpdb;
		self::process_order( $order_id );
		$blocked = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_fiscal_documents WHERE order_id=%d AND status <> 'submitted'", absint( $order_id ) ) );
		$failed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_fiscal_documents WHERE order_id=%d AND status='failed'", absint( $order_id ) ) );
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_fiscal_documents WHERE order_id=%d", absint( $order_id ) ) );
		$wpdb->update( $wpdb->prefix . 'wpcap_fiscal_order_state', array(
			'fiscal_status' => ! $total ? 'not_required' : ( $failed ? 'failed' : ( $blocked ? 'pending' : 'submitted' ) ),
			'fulfillment_status' => $blocked ? 'blocked' : 'ready',
			'updated_at' => current_time( 'mysql', true ),
		), array( 'order_id' => absint( $order_id ) ), array( '%s', '%s', '%s' ), array( '%d' ) );
		return $blocked ? false : $ready;
	}


	public static function admin_pages() {
		return array( array( 'wpcap-fiscalization', __( 'Fiskalisierung', 'wp-pass-captain' ), 'manage_options', array( __CLASS__, 'render_admin' ) ) );
	}

	public static function admin_handlers() {
		return array(
			'wpcap_fiscal_save_seller' => array( __CLASS__, 'handle_admin_save_seller' ),
			'wpcap_fiscal_save_provider' => array( __CLASS__, 'handle_admin_save_provider' ),
			'wpcap_fiscal_save_credentials' => array( __CLASS__, 'handle_admin_save_credentials' ),
			'wpcap_fiscal_save_profile' => array( __CLASS__, 'handle_admin_save_profile' ),
			'wpcap_fiscal_assign_seller' => array( __CLASS__, 'handle_admin_assign_seller' ),
			'wpcap_fiscal_retry_order' => array( __CLASS__, 'handle_admin_retry_order' ),
			'wpcap_fiscal_health_check' => array( __CLASS__, 'handle_admin_health_check' ),
			'wpcap_fiscal_reconcile' => array( __CLASS__, 'handle_admin_reconcile' ),
			'wpcap_fiscal_export' => array( ArchiveExporter::class, 'handle_admin_export' ),
		);
	}

	private static function admin_redirect( $result = 'saved' ) {
		wp_safe_redirect( add_query_arg( array( 'page' => 'wpcap-fiscalization', 'fiscal_result' => sanitize_key( $result ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_admin_save_seller() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ) ); }
		check_admin_referer( 'wpcap_fiscal_save_seller' );
		$request = new \WP_REST_Request( 'POST' );
		$request->set_json_params( array(
			'legal_name' => sanitize_text_field( wp_unslash( $_POST['legal_name'] ?? '' ) ),
			'country_code' => strtoupper( sanitize_text_field( wp_unslash( $_POST['country_code'] ?? '' ) ) ),
			'tax_number' => sanitize_text_field( wp_unslash( $_POST['tax_number'] ?? '' ) ),
			'vat_id' => sanitize_text_field( wp_unslash( $_POST['vat_id'] ?? '' ) ),
			'contact_email' => sanitize_email( wp_unslash( $_POST['contact_email'] ?? '' ) ),
			'business_address' => array( 'street' => sanitize_text_field( wp_unslash( $_POST['address_street'] ?? '' ) ), 'postal_code' => sanitize_text_field( wp_unslash( $_POST['address_postal_code'] ?? '' ) ), 'city' => sanitize_text_field( wp_unslash( $_POST['address_city'] ?? '' ) ), 'region' => sanitize_text_field( wp_unslash( $_POST['address_region'] ?? '' ) ), 'country' => sanitize_text_field( wp_unslash( $_POST['address_country'] ?? '' ) ) ),
		) );
		$result = self::rest_save_seller( $request );
		self::admin_redirect( is_wp_error( $result ) ? 'error' : 'saved' );
	}

	public static function handle_admin_save_profile() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ) ); }
		$seller_id = absint( $_POST['seller_id'] ?? 0 );
		check_admin_referer( 'wpcap_fiscal_save_profile' );
		$input = array(
			'channel' => sanitize_key( wp_unslash( $_POST['channel'] ?? '' ) ),
			'environment' => sanitize_key( wp_unslash( $_POST['environment'] ?? 'test' ) ),
			'status' => sanitize_key( wp_unslash( $_POST['status'] ?? 'draft' ) ),
			'ruleset_key' => sanitize_key( wp_unslash( $_POST['ruleset_key'] ?? '' ) ),
			'ruleset_version' => sanitize_text_field( wp_unslash( $_POST['ruleset_version'] ?? '' ) ),
			'legal_reference' => sanitize_text_field( wp_unslash( $_POST['legal_reference'] ?? '' ) ),
			'required_capabilities' => sanitize_text_field( wp_unslash( $_POST['required_capabilities'] ?? '' ) ),
			'offline_policy' => sanitize_key( wp_unslash( $_POST['offline_policy'] ?? 'stop_sale' ) ),
			'location_id' => absint( $_POST['location_id'] ?? 0 ),
			'register_id' => sanitize_text_field( wp_unslash( $_POST['register_id'] ?? '' ) ),
			'review_attested' => ! empty( $_POST['review_attested'] ),
		);
		$result = self::save_profile( $seller_id, $input );
		self::admin_redirect( is_wp_error( $result ) ? 'error' : 'saved' );
	}

	public static function handle_admin_save_provider() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ) ); }
		$seller_id = absint( $_POST['seller_id'] ?? 0 );
		check_admin_referer( 'wpcap_fiscal_save_provider_' . $seller_id );
		$raw = wp_unslash( $_POST['configuration'] ?? '{}' );
		$config = json_decode( $raw, true );
		if ( ! is_array( $config ) ) { self::admin_redirect( 'invalid_json' ); }
		$request = new \WP_REST_Request( 'PUT' );
		$request->set_param( 'seller_id', $seller_id );
		$request->set_json_params( array(
			'provider_id' => sanitize_key( wp_unslash( $_POST['provider_id'] ?? '' ) ),
			'enabled' => ! empty( $_POST['enabled'] ),
			'configuration' => $config,
		) );
		$result = self::rest_save_provider( $request );
		self::admin_redirect( is_wp_error( $result ) ? 'error' : 'saved' );
	}

	public static function handle_admin_save_credentials() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ) ); }
		$seller_id = absint( $_POST['seller_id'] ?? 0 );
		check_admin_referer( 'wpcap_fiscal_save_credentials_' . $seller_id );
		$provider_id = sanitize_key( wp_unslash( $_POST['provider_id'] ?? '' ) );
		global $wpdb;
		$config = $wpdb->get_row( $wpdb->prepare( "SELECT provider_id FROM {$wpdb->prefix}wpcap_fiscal_configs WHERE seller_id=%d", $seller_id ), ARRAY_A );
		if ( ! $config || $provider_id !== $config['provider_id'] || ! Registry::get( $provider_id ) ) { self::admin_redirect( 'credentials_error' ); }
		$names = is_array( $_POST['credential_name'] ?? null ) ? wp_unslash( $_POST['credential_name'] ) : array();
		$values = is_array( $_POST['credential_value'] ?? null ) ? wp_unslash( $_POST['credential_value'] ) : array();
		$credentials = array();
		for ( $i = 0; $i < min( 4, max( count( $names ), count( $values ) ) ); $i++ ) {
			$name = sanitize_key( is_scalar( $names[ $i ] ?? null ) ? (string) $names[ $i ] : '' );
			$value = is_scalar( $values[ $i ] ?? null ) ? (string) $values[ $i ] : '';
			if ( '' === $name && '' === $value ) { continue; }
			if ( '' === $name || '' === $value || isset( $credentials[ $name ] ) ) { self::admin_redirect( 'credentials_error' ); }
			$credentials[ $name ] = $value;
		}
		$remove = is_array( $_POST['remove_credentials'] ?? null ) ? array_map( 'sanitize_key', wp_unslash( $_POST['remove_credentials'] ) ) : array();
		$result = Credentials::save( $seller_id, $provider_id, $credentials, $remove );
		if ( ! is_wp_error( $result ) ) { Audit::record( 'fiscal_credentials_updated', 'seller', $seller_id, array( 'provider_id' => $provider_id, 'credential_names' => array_values( array_unique( array_merge( array_keys( $credentials ), $remove ) ) ) ) ); }
		self::admin_redirect( is_wp_error( $result ) ? 'credentials_error' : 'credentials_saved' );
	}

	public static function handle_admin_health_check() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ) ); }
		$seller_id = absint( $_POST['seller_id'] ?? 0 );
		check_admin_referer( 'wpcap_fiscal_health_check_' . $seller_id );
		$request = new \WP_REST_Request( 'POST' );
		$request->set_param( 'seller_id', $seller_id );
		$result = self::rest_health_check( $request );
		self::admin_redirect( is_wp_error( $result ) ? 'health_error' : 'health_ok' );
	}

	public static function handle_admin_reconcile() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ) ); }
		$document_id = absint( $_POST['document_id'] ?? 0 );
		check_admin_referer( 'wpcap_fiscal_reconcile_' . $document_id );
		$request = new \WP_REST_Request( 'POST' );
		$request->set_param( 'document_id', $document_id );
		$result = self::rest_reconcile_document( $request );
		self::admin_redirect( is_wp_error( $result ) ? 'reconcile_error' : 'reconciled' );
	}

	public static function handle_admin_retry_order() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ) ); }
		$order_id = absint( $_POST['order_id'] ?? 0 );
		check_admin_referer( 'wpcap_fiscal_retry_order_' . $order_id );
		$request = new \WP_REST_Request( 'POST' );
		$request->set_param( 'order_id', $order_id );
		$result = self::rest_retry_order( $request );
		self::admin_redirect( is_wp_error( $result ) ? 'error' : 'retried' );
	}

	public static function handle_admin_assign_seller() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ) ); }
		$type_id = absint( $_POST['ticket_type_id'] ?? 0 );
		check_admin_referer( 'wpcap_fiscal_assign_seller_' . $type_id );
		$seller_id = absint( $_POST['seller_id'] ?? 0 );
		if ( ! $seller_id ) {
			global $wpdb;
			$wpdb->delete( $wpdb->prefix . 'wpcap_ticket_sellers', array( 'ticket_type_id' => $type_id ), array( '%d' ) );
			self::admin_redirect( 'saved' );
		}
		$result = self::assign_ticket_type( $type_id, $seller_id, sanitize_key( wp_unslash( $_POST['seller_mode'] ?? '' ) ) );
		self::admin_redirect( is_wp_error( $result ) ? 'error' : 'saved' );
	}

	public static function render_admin() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ) ); }
		global $wpdb;
		$p = $wpdb->prefix;
		$sellers = $wpdb->get_results( "SELECT s.*,c.provider_id,c.configuration,c.enabled FROM {$p}wpcap_legal_sellers s LEFT JOIN {$p}wpcap_fiscal_configs c ON c.seller_id=s.seller_id ORDER BY s.legal_name" );
		$profiles = $wpdb->get_results( "SELECT fp.*,s.legal_name FROM {$p}wpcap_fiscal_profiles fp INNER JOIN {$p}wpcap_legal_sellers s ON s.seller_id=fp.seller_id ORDER BY fp.updated_at DESC" );
		$types = $wpdb->get_results( "SELECT tt.ticket_type_id,tt.name AS ticket_name,e.name AS event_name,ts.seller_id,ts.seller_mode FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id LEFT JOIN {$p}wpcap_ticket_sellers ts ON ts.ticket_type_id=tt.ticket_type_id ORDER BY e.name,tt.name" );
		$adapters = Registry::all();
		$result = sanitize_key( $_GET['fiscal_result'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only status.
		echo '<div class="wrap"><h1>' . esc_html__( 'Fiskalisierung', 'wp-pass-captain' ) . '</h1>';
		if ( in_array( $result, array( 'saved', 'retried', 'health_ok', 'reconciled', 'credentials_saved' ), true ) ) { echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Änderung gespeichert.', 'wp-pass-captain' ) . '</p></div>'; }
		if ( in_array( $result, array( 'error', 'invalid_json', 'health_error', 'reconcile_error', 'credentials_error' ), true ) ) { echo '<div class="notice notice-error"><p>' . esc_html__( 'Änderung fehlgeschlagen. Eingaben, Adapter und Länderunterstützung prüfen.', 'wp-pass-captain' ) . '</p></div>'; }
		echo '<p>' . esc_html__( 'Fiskalbelege werden je Rechtsträger erstellt. Ein zugeordnetes Ticket wird erst ausgegeben, wenn der konfigurierte Adapter den Beleg bestätigt.', 'wp-pass-captain' ) . '</p>';
		echo '<h2>' . esc_html__( 'Rechtsträger anlegen', 'wp-pass-captain' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'wpcap_fiscal_save_seller' );
		echo '<input type="hidden" name="action" value="wpcap_fiscal_save_seller"><table class="form-table"><tr><th><label for="fiscal-legal-name">' . esc_html__( 'Rechtlicher Name', 'wp-pass-captain' ) . '</label></th><td><input required id="fiscal-legal-name" name="legal_name" class="regular-text"></td></tr><tr><th><label for="fiscal-country">' . esc_html__( 'Land', 'wp-pass-captain' ) . '</label></th><td><select id="fiscal-country" name="country_code"><option value="AT">AT</option><option value="DE">DE</option><option value="HU">HU</option></select></td></tr><tr><th><label for="fiscal-tax">' . esc_html__( 'Steuernummer / VAT-ID', 'wp-pass-captain' ) . '</label></th><td><input id="fiscal-tax" name="tax_number" class="regular-text"></td></tr></table>';
		echo '<p><label>' . esc_html__( 'VAT-ID', 'wp-pass-captain' ) . ' <input name="vat_id" class="regular-text"></label> <label>' . esc_html__( 'Kontakt-E-Mail', 'wp-pass-captain' ) . ' <input name="contact_email" type="email"></label></p>';
		echo '<p><label>' . esc_html__( 'Geschäftsadresse', 'wp-pass-captain' ) . ' <input name="address_street" placeholder="Straße" class="regular-text"></label> <input name="address_postal_code" placeholder="PLZ"> <input name="address_city" placeholder="Ort"> <input name="address_region" placeholder="Region"> <input name="address_country" placeholder="Land"></p>';
		submit_button( __( 'Rechtsträger speichern', 'wp-pass-captain' ) );
		echo '</form><hr><h2>' . esc_html__( 'Länder- und Verkaufskanalprofil', 'wp-pass-captain' ) . '</h2>';
		echo '<p>' . esc_html__( 'Ein Produktivprofil erfordert dokumentierte fachliche Prüfung. Die Plugin-Freigabe ersetzt keine rechtliche oder steuerliche Prüfung.', 'wp-pass-captain' ) . '</p>';
		if ( $sellers ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'wpcap_fiscal_save_profile' );
			echo '<input type="hidden" name="action" value="wpcap_fiscal_save_profile"><label>' . esc_html__( 'Verkäufer', 'wp-pass-captain' ) . ' <select name="seller_id">';
			foreach ( $sellers as $seller ) { echo '<option value="' . (int) $seller->seller_id . '">' . esc_html( $seller->legal_name . ' (' . $seller->country_code . ')' ) . '</option>'; }
			echo '</select></label> <label>' . esc_html__( 'Standort-ID (0 = Standard)', 'wp-pass-captain' ) . ' <input type="number" min="0" name="location_id" value="0"></label> <label>' . esc_html__( 'Kassen-ID (leer = Standard)', 'wp-pass-captain' ) . ' <input name="register_id" maxlength="64" pattern="[A-Za-z0-9_.:-]*" placeholder="KASSE-01"></label> <label>' . esc_html__( 'Kanal', 'wp-pass-captain' ) . ' <select name="channel"><option value="pos">POS/Abendkasse</option><option value="woocommerce">WooCommerce</option><option value="native">Native Kasse</option></select></label> <label>' . esc_html__( 'Umgebung', 'wp-pass-captain' ) . ' <select name="environment"><option value="test">Test</option><option value="production">Produktion</option></select></label> <label>' . esc_html__( 'Status', 'wp-pass-captain' ) . ' <select name="status"><option value="draft">Entwurf</option><option value="test">Test</option><option value="active">Produktiv mit Adapter</option><option value="not_required">Geprüft: nicht erforderlich</option><option value="suspended">Gesperrt</option></select></label><p><label>' . esc_html__( 'Regelwerk/Profil', 'wp-pass-captain' ) . ' <input name="ruleset_key" class="regular-text" placeholder="AT_RKSV / DE_KASSENSICHV / HU_NAV"></label> <label>' . esc_html__( 'Regelwerkversion', 'wp-pass-captain' ) . ' <input name="ruleset_version"></label></p><p><label>' . esc_html__( 'Rechts-/Prüfreferenz', 'wp-pass-captain' ) . ' <input name="legal_reference" class="large-text"></label></p><label><input type="checkbox" name="review_attested" value="1"> ' . esc_html__( 'Ich bestätige die fachliche Prüfung und Freigabe dieses Profils.', 'wp-pass-captain' ) . '</label>';
			echo '<p><label>' . esc_html__( 'Ausfallverhalten', 'wp-pass-captain' ) . ' <select name="offline_policy"><option value="stop_sale">' . esc_html__( 'Verkauf stoppen, bis Anbieter wieder verfügbar ist', 'wp-pass-captain' ) . '</option><option value="provider_buffered">' . esc_html__( 'Anbieter-gepufferter Notbetrieb (Adapterfähigkeit erforderlich)', 'wp-pass-captain' ) . '</option></select></label></p>';
			echo '<p><label>' . esc_html__( 'Zusätzliche Adapterfähigkeiten (Komma-getrennt)', 'wp-pass-captain' ) . ' <input name="required_capabilities" class="large-text" placeholder="receipt_qr_payload, transaction_begin, transaction_finish, daily_report"></label></p>';
			submit_button( __( 'Profil speichern', 'wp-pass-captain' ) );
			echo '</form>';
		}
		echo '<h3>' . esc_html__( 'Gespeicherte Profile', 'wp-pass-captain' ) . '</h3>';
		if ( ! $profiles ) { echo '<p>' . esc_html__( 'Keine Länderprofile gespeichert.', 'wp-pass-captain' ) . '</p>'; }
		else {
			echo '<table class="widefat striped"><thead><tr><th>Verkäufer</th><th>Land/Kanal/Kasse</th><th>Umgebung</th><th>Status</th><th>Regelwerk</th><th>Version</th></tr></thead><tbody>';
			foreach ( $profiles as $profile ) { echo '<tr><td>' . esc_html( $profile->legal_name ) . '</td><td>' . esc_html( $profile->country_code . ' / ' . $profile->channel . ' / ' . (int) $profile->location_id . ':' . $profile->register_id ) . '</td><td>' . esc_html( $profile->environment ) . '</td><td>' . esc_html( $profile->status ) . '</td><td>' . esc_html( $profile->ruleset_key . ' ' . $profile->ruleset_version ) . '</td><td>' . (int) $profile->profile_version . '</td></tr>'; }
			echo '</tbody></table>';
		}
		echo '<hr><h2>' . esc_html__( 'Anbieter konfigurieren', 'wp-pass-captain' ) . '</h2>';
		if ( ! $adapters ) { echo '<p>' . esc_html__( 'Noch kein Fiskaladapter installiert. Anbieter bleiben deaktiviert, bis ein Adapter registriert wurde.', 'wp-pass-captain' ) . '</p>'; }
		foreach ( $sellers as $seller ) {
			$config = $seller->configuration ? (array) json_decode( $seller->configuration, true ) : array();
			$available = array_filter( $adapters, static function ( $adapter ) use ( $seller ) { return in_array( strtoupper( $seller->country_code ), array_map( 'strtoupper', (array) $adapter->countries() ), true ); } );
			echo '<h3>' . esc_html( $seller->legal_name . ' (' . $seller->country_code . ')' ) . '</h3><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'wpcap_fiscal_save_provider_' . (int) $seller->seller_id );
			echo '<input type="hidden" name="action" value="wpcap_fiscal_save_provider"><input type="hidden" name="seller_id" value="' . (int) $seller->seller_id . '"><label>' . esc_html__( 'Anbieter', 'wp-pass-captain' ) . ' <select name="provider_id"><option value="">' . esc_html__( 'Keiner', 'wp-pass-captain' ) . '</option>';
			foreach ( $available as $adapter ) { echo '<option value="' . esc_attr( $adapter->id() ) . '" ' . selected( $seller->provider_id, $adapter->id(), false ) . '>' . esc_html( $adapter->id() ) . '</option>'; }
			echo '</select></label> <label><input type="checkbox" name="enabled" value="1" ' . checked( (int) $seller->enabled, 1, false ) . '> ' . esc_html__( 'Aktiv', 'wp-pass-captain' ) . '</label><p><label>' . esc_html__( 'Nicht geheime Adapter-Konfiguration (JSON)', 'wp-pass-captain' ) . '<br><textarea name="configuration" rows="4" class="large-text code">' . esc_textarea( wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) . '</textarea></label></p>';
			submit_button( __( 'Anbieter speichern', 'wp-pass-captain' ), 'secondary' );
			echo '</form><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="wpcap_fiscal_health_check"><input type="hidden" name="seller_id" value="' . (int) $seller->seller_id . '">';
			wp_nonce_field( 'wpcap_fiscal_health_check_' . (int) $seller->seller_id );
			submit_button( __( 'Verbindung prüfen (keinen Beleg erstellen)', 'wp-pass-captain' ), 'secondary' );
			echo '</form>';
			$credential_names = Credentials::stored_names( (int) $seller->seller_id, $seller->provider_id );
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wpcap-fiscal-credentials">';
			wp_nonce_field( 'wpcap_fiscal_save_credentials_' . (int) $seller->seller_id );
			echo '<input type="hidden" name="action" value="wpcap_fiscal_save_credentials"><input type="hidden" name="seller_id" value="' . (int) $seller->seller_id . '"><label>' . esc_html__( 'Zugangsdaten für Anbieter', 'wp-pass-captain' ) . ' <select name="provider_id" required>';
			foreach ( $available as $adapter ) { echo '<option value="' . esc_attr( $adapter->id() ) . '" ' . selected( $seller->provider_id, $adapter->id(), false ) . '>' . esc_html( $adapter->id() ) . '</option>'; }
			echo '</select></label>';
			if ( $credential_names ) {
				echo '<p>' . esc_html__( 'Gespeicherte Zugangsdaten (Werte werden nicht angezeigt):', 'wp-pass-captain' ) . ' <code>' . esc_html( implode( ', ', $credential_names ) ) . '</code></p><p>';
				foreach ( $credential_names as $credential_name ) { echo '<label><input type="checkbox" name="remove_credentials[]" value="' . esc_attr( $credential_name ) . '"> ' . esc_html( sprintf( __( '%s löschen', 'wp-pass-captain' ), $credential_name ) ) . '</label> '; }
				echo '</p>';
			}
			for ( $credential_row = 0; $credential_row < 4; $credential_row++ ) {
				echo '<p><label>' . esc_html__( 'Feldname', 'wp-pass-captain' ) . ' <input name="credential_name[]" maxlength="64" pattern="[A-Za-z0-9_]+" autocomplete="off"></label> <label>' . esc_html__( 'Geheimer Wert', 'wp-pass-captain' ) . ' <input type="password" name="credential_value[]" maxlength="8192" autocomplete="new-password"></label></p>';
			}
			echo '<p class="description">' . esc_html__( 'Neue Werte werden verschlüsselt gespeichert. Vorhandene Werte bleiben erhalten, wenn Sie nur einzelne Felder ergänzen oder ersetzen. Die Verschlüsselung verwendet die WordPress-Salts aus wp-config.php; nach einer Salt-Rotation Zugangsdaten erneut eintragen.', 'wp-pass-captain' ) . '</p>';
			submit_button( __( 'Zugangsdaten speichern', 'wp-pass-captain' ), 'secondary' );
			echo '</form>';
		}
		echo '<hr><h2>' . esc_html__( 'Verkäufer je Ticketart', 'wp-pass-captain' ) . '</h2>';
		if ( ! $sellers ) { echo '<p>' . esc_html__( 'Lege zuerst mindestens einen Rechtsträger an.', 'wp-pass-captain' ) . '</p>'; }
		foreach ( $types as $type ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:flex;gap:8px;align-items:center;margin:8px 0">';
			wp_nonce_field( 'wpcap_fiscal_assign_seller_' . (int) $type->ticket_type_id );
			echo '<input type="hidden" name="action" value="wpcap_fiscal_assign_seller"><input type="hidden" name="ticket_type_id" value="' . (int) $type->ticket_type_id . '"><strong>' . esc_html( $type->event_name . ' — ' . $type->ticket_name ) . '</strong><select name="seller_id"><option value="0">' . esc_html__( 'Nicht fiskal zuordnen', 'wp-pass-captain' ) . '</option>';
			foreach ( $sellers as $seller ) { echo '<option value="' . (int) $seller->seller_id . '" ' . selected( (int) $type->seller_id, (int) $seller->seller_id, false ) . '>' . esc_html( $seller->legal_name . ' (' . $seller->country_code . ')' ) . '</option>'; }
			echo '</select><select name="seller_mode"><option value="organizer_seller" ' . selected( $type->seller_mode, 'organizer_seller', false ) . '>' . esc_html__( 'Veranstalter ist Verkäufer', 'wp-pass-captain' ) . '</option><option value="platform_seller" ' . selected( $type->seller_mode, 'platform_seller', false ) . '>' . esc_html__( 'Betreibergesellschaft ist Verkäufer', 'wp-pass-captain' ) . '</option></select>';
			submit_button( __( 'Zuordnung speichern', 'wp-pass-captain' ), 'secondary', 'submit', false );
			echo '</form>';
		}
		echo '<hr><h2>' . esc_html__( 'Offene Fiskalvorgänge', 'wp-pass-captain' ) . '</h2>';
		$pending = $wpdb->get_results( "SELECT d.document_id,d.order_id,d.status,d.last_error,d.attempts,d.updated_at,s.legal_name FROM {$p}wpcap_fiscal_documents d INNER JOIN {$p}wpcap_legal_sellers s ON s.seller_id=d.seller_id WHERE d.status <> 'submitted' ORDER BY d.updated_at DESC LIMIT 100" );
		if ( ! $pending ) { echo '<p>' . esc_html__( 'Keine offenen Fiskalvorgänge.', 'wp-pass-captain' ) . '</p>'; }
		else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Dokument', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Bestellung', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Verkäufer', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Status', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Versuche / letzter Fehler', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Aktion', 'wp-pass-captain' ) . '</th></tr></thead><tbody>';
			foreach ( $pending as $doc ) {
				echo '<tr><td>' . (int) $doc->document_id . '</td><td>' . (int) $doc->order_id . '</td><td>' . esc_html( $doc->legal_name ) . '</td><td>' . esc_html( $doc->status ) . '</td><td>' . (int) $doc->attempts . ' / ' . esc_html( $doc->last_error ) . '</td><td>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wpcap_fiscal_reconcile"><input type="hidden" name="document_id" value="' . (int) $doc->document_id . '">';
				wp_nonce_field( 'wpcap_fiscal_reconcile_' . (int) $doc->document_id );
				submit_button( __( 'Status beim Anbieter abgleichen', 'wp-pass-captain' ), 'secondary', 'submit', false );
				echo '</form>';
				if ( 'failed' === $doc->status ) {
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wpcap_fiscal_retry_order"><input type="hidden" name="order_id" value="' . (int) $doc->order_id . '">';
					wp_nonce_field( 'wpcap_fiscal_retry_order_' . (int) $doc->order_id );
					submit_button( __( 'Erneut versuchen', 'wp-pass-captain' ), 'secondary', 'submit', false );
					echo '</form>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		$export_until = gmdate( 'Y-m-d' );
		$export_from = gmdate( 'Y-m-d', time() - 29 * DAY_IN_SECONDS );
		echo '<hr><h2>' . esc_html__( 'Fiskaldaten exportieren', 'wp-pass-captain' ) . '</h2><p>' . esc_html__( 'Normalisierter CSV-Export für Archiv- und Betriebszwecke; kein länderspezifisches Behördenformat. Zeitraum in UTC, maximal 50.000 Dokumente.', 'wp-pass-captain' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'wpcap_fiscal_export' );
		echo '<input type="hidden" name="action" value="wpcap_fiscal_export"><label>' . esc_html__( 'Von (UTC)', 'wp-pass-captain' ) . ' <input type="date" name="from" value="' . esc_attr( $export_from ) . '" required></label> <label>' . esc_html__( 'Bis (UTC)', 'wp-pass-captain' ) . ' <input type="date" name="until" value="' . esc_attr( $export_until ) . '" required></label>';
		submit_button( __( 'CSV herunterladen', 'wp-pass-captain' ), 'secondary', 'submit', false );
		echo '</form>';
		echo '<hr><h2>' . esc_html__( 'Fiskal-Ereignisprotokoll', 'wp-pass-captain' ) . '</h2>';
		$events = $wpdb->get_results( "SELECT event_id,document_id,order_id,provider_id,event_type,event_status,metadata,created_at FROM {$p}wpcap_fiscal_events ORDER BY event_id DESC LIMIT 200" );
		$resolved_events = array();
		$resolutions = $wpdb->get_results( "SELECT metadata FROM {$p}wpcap_fiscal_events WHERE event_type='manual_correction_recorded' ORDER BY event_id DESC LIMIT 5000" );
		foreach ( $resolutions as $resolution_row ) {
			$resolution_data = json_decode( $resolution_row->metadata, true );
			if ( is_array( $resolution_data ) && ! empty( $resolution_data['resolves_event_id'] ) ) {
				$resolved_events[ absint( $resolution_data['resolves_event_id'] ) ] = sanitize_text_field( $resolution_data['provider_reference'] ?? '' );
			}
		}
		if ( ! $events ) { echo '<p>' . esc_html__( 'Noch keine Fiskalereignisse protokolliert.', 'wp-pass-captain' ) . '</p>'; }
		else {
			echo '<table class="widefat striped"><thead><tr><th>Ereignis</th><th>Dokument/Bestellung</th><th>Anbieter</th><th>Status</th><th>Details</th><th>Bearbeitung</th><th>Zeitpunkt</th></tr></thead><tbody>';
			foreach ( $events as $event ) {
				$metadata = json_decode( $event->metadata, true );
				$event_id = (int) $event->event_id;
				echo '<tr><td>' . esc_html( $event->event_type ) . '</td><td>' . (int) $event->document_id . ' / ' . (int) $event->order_id . '</td><td>' . esc_html( $event->provider_id ) . '</td><td>' . esc_html( $event->event_status ) . '</td><td><code>' . esc_html( wp_json_encode( is_array( $metadata ) ? $metadata : array() ) ) . '</code></td><td>';
				if ( isset( $resolved_events[ $event_id ] ) ) {
					echo esc_html( sprintf( __( 'Extern erledigt · Referenz %s', 'wp-pass-captain' ), $resolved_events[ $event_id ] ) );
				} elseif ( 'manual_required' === $event->event_status && in_array( $event->event_type, array( 'refund_adjustment', 'order_cancellation', 'event_cancellation' ), true ) ) {
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wpcap_fiscal_manual_resolution"><input type="hidden" name="event_id" value="' . $event_id . '">';
					wp_nonce_field( 'wpcap_fiscal_manual_resolution_' . $event_id );
					echo '<select name="resolution"><option value="refund">' . esc_html__( 'Erstattung', 'wp-pass-captain' ) . '</option><option value="credit_note">' . esc_html__( 'Gutschrift', 'wp-pass-captain' ) . '</option><option value="void">' . esc_html__( 'Storno', 'wp-pass-captain' ) . '</option><option value="correction">' . esc_html__( 'Korrektur', 'wp-pass-captain' ) . '</option></select><input name="provider_reference" type="text" maxlength="191" required placeholder="' . esc_attr__( 'Beleg-/Anbieterreferenz', 'wp-pass-captain' ) . '">';
					submit_button( __( 'Extern erledigt dokumentieren', 'wp-pass-captain' ), 'secondary', 'submit', false );
					echo '</form><small>' . esc_html__( 'Zuerst im Anbieterportal durchführen; dies dokumentiert nur die Referenz.', 'wp-pass-captain' ) . '</small>';
				}
				echo '</td><td>' . esc_html( $event->created_at ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	/** Admin-only REST endpoints for managing seller records, provider selection, and ticket mapping. */
	public static function register_routes() {
		register_rest_route( 'wp-pass-captain/v1', '/fiscal/sellers', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_sellers' ), 'permission_callback' => array( __CLASS__, 'rest_permission' ) ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_save_seller' ), 'permission_callback' => array( __CLASS__, 'rest_permission' ) ),
		) );
		register_rest_route( 'wp-pass-captain/v1', '/fiscal/sellers/(?P<seller_id>\\d+)/profiles', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_save_profile' ), 'permission_callback' => array( __CLASS__, 'rest_permission' ),
		) );
		register_rest_route( 'wp-pass-captain/v1', '/fiscal/sellers/(?P<seller_id>\\d+)/health-check', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_health_check' ), 'permission_callback' => array( __CLASS__, 'rest_permission' ),
		) );
		register_rest_route( 'wp-pass-captain/v1', '/fiscal/sellers/(?P<seller_id>\d+)/provider', array(
			'methods' => 'PUT', 'callback' => array( __CLASS__, 'rest_save_provider' ), 'permission_callback' => array( __CLASS__, 'rest_permission' ),
		) );
		register_rest_route( 'wp-pass-captain/v1', '/fiscal/documents/(?P<document_id>\\d+)/reconcile', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_reconcile_document' ), 'permission_callback' => array( __CLASS__, 'rest_permission' ),
		) );
		register_rest_route( 'wp-pass-captain/v1', '/fiscal/events/(?P<event_id>\\d+)/manual-resolution', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_record_manual_resolution' ), 'permission_callback' => array( __CLASS__, 'rest_permission' ),
		) );
		register_rest_route( 'wp-pass-captain/v1', '/fiscal/orders/(?P<order_id>\\d+)/retry', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_retry_order' ), 'permission_callback' => array( __CLASS__, 'rest_permission' ),
		) );
		register_rest_route( 'wp-pass-captain/v1', '/fiscal/ticket-types/(?P<ticket_type_id>\d+)/seller', array(
			'methods' => 'PUT', 'callback' => array( __CLASS__, 'rest_assign_seller' ), 'permission_callback' => array( __CLASS__, 'rest_permission' ),
		) );
	}

	public static function rest_permission() { return current_user_can( 'manage_options' ); }

	/** Prevent adapter credentials from being written to the general configuration table. */
	private static function contains_secret_key( $values ) {
		foreach ( (array) $values as $key => $value ) {
			if ( preg_match( '/(secret|password|token|credential|private|api[_-]?key|certificate)/i', (string) $key ) ) { return true; }
			if ( is_array( $value ) && self::contains_secret_key( $value ) ) { return true; }
		}
		return false;
	}

	public static function rest_sellers() {
		global $wpdb;
		return rest_ensure_response( $wpdb->get_results( "SELECT s.seller_id,s.legal_name,s.country_code,s.tax_number,s.status,c.provider_id,c.enabled FROM {$wpdb->prefix}wpcap_legal_sellers s LEFT JOIN {$wpdb->prefix}wpcap_fiscal_configs c ON c.seller_id=s.seller_id ORDER BY s.legal_name", ARRAY_A ) );
	}

	public static function rest_save_seller( \WP_REST_Request $request ) {
		global $wpdb;
		$body = (array) $request->get_json_params();
		$name = sanitize_text_field( $body['legal_name'] ?? '' );
		$country = strtoupper( sanitize_text_field( $body['country_code'] ?? '' ) );
		if ( '' === $name || ! in_array( $country, array( 'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK' ), true ) ) {
			return new \WP_Error( 'wpcap_fiscal_seller_invalid', __( 'Name und eines der unterstützten Länder AT, DE, HU oder ein weiteres EU-Land sind erforderlich.', 'wp-pass-captain' ), array( 'status' => 400 ) );
		}
		$now = current_time( 'mysql', true );
		$address_in = is_array( $body['business_address'] ?? null ) ? $body['business_address'] : array();
		$address = array();
		foreach ( array( 'street', 'postal_code', 'city', 'region', 'country' ) as $field ) { $address[ $field ] = sanitize_text_field( $address_in[ $field ] ?? '' ); }
		$wpdb->insert( $wpdb->prefix . 'wpcap_legal_sellers', array(
			'legal_name' => $name, 'country_code' => $country, 'tax_number' => sanitize_text_field( $body['tax_number'] ?? '' ),
			'vat_id' => sanitize_text_field( $body['vat_id'] ?? '' ), 'business_address' => wp_json_encode( $address ),
			'contact_email' => sanitize_email( $body['contact_email'] ?? '' ), 'created_at' => $now, 'updated_at' => $now,
		), array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
		if ( ! $wpdb->insert_id ) { return new \WP_Error( 'wpcap_fiscal_seller_save', __( 'Rechtsträger konnte nicht gespeichert werden.', 'wp-pass-captain' ), array( 'status' => 500 ) ); }
		return rest_ensure_response( array( 'seller_id' => (int) $wpdb->insert_id ) );
	}

	public static function rest_save_profile( \WP_REST_Request $request ) {
		$result = self::save_profile( absint( $request['seller_id'] ), (array) $request->get_json_params() );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function rest_health_check( \WP_REST_Request $request ) {
		global $wpdb;
		$seller_id = absint( $request['seller_id'] );
		$seller = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_legal_sellers WHERE seller_id=%d AND status='active'", $seller_id ), ARRAY_A );
		$config = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_fiscal_configs WHERE seller_id=%d AND enabled=1", $seller_id ), ARRAY_A );
		$adapter = $config ? Registry::get( $config['provider_id'] ) : null;
		if ( ! $seller || ! $config || ! $adapter || ! in_array( strtoupper( $seller['country_code'] ), array_map( 'strtoupper', (array) $adapter->countries() ), true ) ) {
			return new \WP_Error( 'wpcap_fiscal_health_unavailable', __( 'Aktiver Anbieter oder Länderunterstützung fehlt.', 'wp-pass-captain' ), array( 'status' => 409 ) );
		}
		$configuration = $config['configuration'] ? (array) json_decode( $config['configuration'], true ) : array();
		$configuration = Credentials::with_credentials( $configuration, $seller_id, $config['provider_id'] );
		$result = $adapter->health_check( $seller, $configuration );
		if ( is_wp_error( $result ) || ( true !== $result && ( ! is_array( $result ) || empty( $result['ok'] ) ) ) ) {
			$code = is_wp_error( $result ) ? sanitize_key( $result->get_error_code() ) : 'health_check_failed';
			Audit::record( 'fiscal_provider_health_failed', 'seller', $seller_id, array( 'provider_id' => $config['provider_id'], 'error_code' => $code ) );
			return new \WP_Error( 'wpcap_fiscal_health_failed', __( 'Der Anbieter-Verbindungstest ist fehlgeschlagen.', 'wp-pass-captain' ), array( 'status' => 502, 'provider_error_code' => $code ) );
		}
		Audit::record( 'fiscal_provider_health_ok', 'seller', $seller_id, array( 'provider_id' => $config['provider_id'] ) );
		return rest_ensure_response( array( 'ok' => true, 'provider_id' => $config['provider_id'], 'checked_at' => current_time( 'mysql', true ) ) );
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
		if ( self::contains_secret_key( $config ) ) { return new \WP_Error( 'wpcap_fiscal_secret_rejected', __( 'API-Schlüssel und Zugangsdaten dürfen nicht in der allgemeinen Anbieter-Konfiguration gespeichert werden.', 'wp-pass-captain' ), array( 'status' => 400 ) ); }
		$result = $wpdb->replace( $wpdb->prefix . 'wpcap_fiscal_configs', array( 'seller_id' => $seller_id, 'country_code' => $seller->country_code, 'provider_id' => $provider_id, 'configuration' => wp_json_encode( $config ), 'enabled' => $enabled, 'created_at' => $now, 'updated_at' => $now ), array( '%d', '%s', '%s', '%s', '%d', '%s', '%s' ) );
		return false === $result ? new \WP_Error( 'wpcap_fiscal_provider_save', __( 'Anbieter-Konfiguration konnte nicht gespeichert werden.', 'wp-pass-captain' ), array( 'status' => 500 ) ) : rest_ensure_response( array( 'seller_id' => $seller_id, 'provider_id' => $provider_id, 'enabled' => (bool) $enabled ) );
	}

	public static function rest_assign_seller( \WP_REST_Request $request ) {
		$body = (array) $request->get_json_params();
		$result = self::assign_ticket_type( absint( $request['ticket_type_id'] ), absint( $body['seller_id'] ?? 0 ), sanitize_key( $body['seller_mode'] ?? '' ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'ticket_type_id' => absint( $request['ticket_type_id'] ), 'seller_id' => absint( $body['seller_id'] ), 'seller_mode' => sanitize_key( $body['seller_mode'] ) ) );
	}
}

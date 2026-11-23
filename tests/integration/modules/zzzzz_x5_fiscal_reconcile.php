<?php
/**
 * X5: integration of PR #14 (fiscalization) with the newer branch behaviour.
 *
 * - SSRF and transport hardening of the single fiscal transport (ProviderHttp) and of provider
 *   configurations; currency decimals in adapter payloads.
 * - Fiscal credentials in the plugin vault: legacy draft entries migrated, key rotation, redaction.
 * - Seller model: legal seller vs. SalesModel, WooCommerce split (W10) with fiscal documents per seller
 *   order, non-ticket lines, negative fees (blocked only while a receipt is required), refunds.
 * - Box office fiscal gating (stop_sale) with the W6 fault matrix and the W6 resume path.
 *
 * Included from run.php after the W6 and WooCommerce tests (uses their helpers and fakes).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

require_once dirname( __DIR__ ) . '/lib/fixtures_shared.php';
require_once dirname( __DIR__ ) . '/lib/fixtures_fiscal.php';

use PassCaptain\BoxOffice\Service as X5Pos;
use PassCaptain\Fiscalization\AdapterInterface;
use PassCaptain\Fiscalization\Credentials;
use PassCaptain\Fiscalization\ProviderHttp;
use PassCaptain\Fiscalization\Providers\EfstaEfrAdapter;
use PassCaptain\Integrations\WooCommerce\OrderAdapter as Woo;
use PassCaptain\Integrations\WooCommerce\SellerSplit;
use PassCaptain\Modules\Fiscalization as Fiscal;
use PassCaptain\Orders;
use PassCaptain\Partners\Repository as X5Partners;
use PassCaptain\Security\Redact;
use PassCaptain\Security\Vault;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;


/* ------------------------------------------------------------------------------------------ */
echo "X5 fiscal: one hardened transport (SSRF, redirects, local services, logging)\n";
$GLOBALS['wpcap_test_dns'] = array(
	'public.example' => array( '93.184.215.14' ),
	'private.example' => array( '10.1.2.3' ),
	'metadata.example' => array( '169.254.169.254' ),
	'v6mapped.example' => array( '::ffff:127.0.0.1' ),
);
Settings::update( array( 'fiscal_allow_local_service' => 0 ) );
http_reset();
$x5_meta = ProviderHttp::request( 'https://metadata.example/latest/meta-data', 'GET' );
$x5_priv = ProviderHttp::request( 'https://private.example/sign', 'POST', array(), array( 'a' => 1 ) );
$x5_v6 = ProviderHttp::request( 'https://v6mapped.example/sign', 'POST' );
$x5_cred = ProviderHttp::request( 'https://user:pw@public.example/sign', 'POST' );
check( 'fiscal transport refuses metadata, private, IPv4-mapped loopback and credential URLs without any request', is_wp_error( $x5_meta ) && is_wp_error( $x5_priv ) && is_wp_error( $x5_v6 ) && is_wp_error( $x5_cred ) && 'provider_endpoint_invalid' === $x5_meta->get_error_code() && 0 === count( http_requests() ) );
$x5_local = ProviderHttp::request( 'http://127.0.0.1:5618/state', 'GET' );
Settings::update( array( 'fiscal_allow_local_service' => 1 ) );
http_push( array( 'name' => 'EFR', 'version' => '2' ), 200, '127.0.0.1' );
$x5_efsta = ( new EfstaEfrAdapter() )->health_check( array( 'seller_id' => 1 ), array( 'endpoint_url' => 'http://127.0.0.1:5618' ) );
$x5_remote_http = ProviderHttp::request( 'http://public.example/state', 'GET' );
Settings::update( array( 'fiscal_allow_local_service' => 0 ) );
check( 'local fiscal service (efsta EFR on loopback) only after opt-in; plain http to the internet never', is_wp_error( $x5_local ) && ! is_wp_error( $x5_efsta ) && ! empty( $x5_efsta['ok'] ) && is_wp_error( $x5_remote_http ) );
http_reset();
$GLOBALS['wpcap_test_http_queue'][] = array( 'body' => '', 'code' => 302, 'match' => 'public.example', 'headers' => array( 'location' => 'http://169.254.169.254/' ) );
$x5_redirect = ProviderHttp::request( 'https://public.example/sign?token=secret-in-query', 'POST', array(), array( 'x' => 1 ) );
check( 'provider redirects are not followed (one request, provider_redirect)', is_wp_error( $x5_redirect ) && 'provider_redirect' === $x5_redirect->get_error_code() && 1 === count( http_requests() ) );
http_push( array( 'error_code' => 'busy' ), 503, 'public.example' );
$x5_503 = ProviderHttp::request( 'https://public.example/sign?token=secret-in-query', 'POST', array(), array( 'x' => 1 ) );
$x5_log = $wpdb->get_row( "SELECT details FROM {$p}wpcap_audit_logs WHERE action='fiscal_provider_http_failed' ORDER BY audit_id DESC LIMIT 1" );
check( 'provider 5xx: WP_Error with status, logged via Log with host and code only (no path, query or body)', is_wp_error( $x5_503 ) && 503 === ProviderHttp::status( $x5_503 ) && 'busy' === ( $x5_503->get_error_data()['provider_code'] ?? '' ) && $x5_log && false !== strpos( $x5_log->details, 'public.example' ) && false === strpos( $x5_log->details, 'secret-in-query' ) && false === strpos( $x5_log->details, '/sign' ) );
http_push( array( 'ok' => true ), 200, 'public.example' );
ProviderHttp::request( 'https://public.example/sign', 'POST', array(), array( 'x' => 1 ), 99 );
$x5_last = http_requests( 'public.example' );
check( 'timeout is clamped to 15 s and JSON bodies are sent with a JSON content type', 15 === (int) end( $x5_last )['timeout'] && 'application/json' === ( end( $x5_last )['headers']['Content-Type'] ?? '' ) );
check( 'no other fiscal transport class is left', ! class_exists( 'PassCaptain\\Fiscalization\\Providers\\HttpClient' ) );
check( 'fiscal amounts follow the currency decimals (JPY 0, KWD 3, EUR 2)', '1500' === ProviderHttp::decimal( 1500, 'JPY' ) && '12.345' === ProviderHttp::decimal( 12345, 'KWD' ) && '12.50' === ProviderHttp::decimal( 1250, 'EUR' ) );
$x5_jpy = ( new EfstaEfrAdapter() )->prepare_void(
	array( 'country_code' => 'AT', 'order_id' => 77, 'gross_minor' => 1500, 'currency' => 'JPY', 'created_at' => '2026-01-01T12:00:00Z', 'payment_method' => 'cash', 'items' => array( array( 'ticket_type_id' => 1, 'description' => 'T', 'quantity' => 1, 'gross_minor' => 1500, 'tax_rate_bp' => 1000, 'tax_minor' => 136 ) ) ),
	array( 'country_code' => 'AT', 'tax_number' => 'ATU1' ),
	array( 'endpoint_url' => 'https://public.example', 'register_number' => 'r1', 'terminal_id' => 't1', 'tax_groups' => array( '1000' => 'A' ), 'payment_groups' => array( 'cash' => 'CASH' ) ),
	5
);
check( 'efsta payload amounts use the document currency (JPY without decimals)', ! is_wp_error( $x5_jpy ) && '1500' === $x5_jpy['payload']['Tra']['ESR']['T'] && '1500' === $x5_jpy['payload']['Tra']['ESR']['PosA'][0]['Amt'] );

$x5_s_cfg = x5_seller( 'X5 Konfig GmbH' );
$x5_bad_cfg = Fiscal::rest_save_provider( x5_rest( 'PUT', array( 'seller_id' => $x5_s_cfg ), array( 'provider_id' => 'efsta_efr', 'enabled' => true, 'configuration' => array( 'endpoint_url' => 'https://metadata.example/efr' ) ) ) );
$x5_ok_cfg = Fiscal::rest_save_provider( x5_rest( 'PUT', array( 'seller_id' => $x5_s_cfg ), array( 'provider_id' => 'efsta_efr', 'enabled' => true, 'configuration' => array( 'endpoint_url' => 'https://public.example/efr' ) ) ) );
$x5_secret_cfg = Fiscal::rest_save_provider( x5_rest( 'PUT', array( 'seller_id' => $x5_s_cfg ), array( 'provider_id' => 'efsta_efr', 'enabled' => true, 'configuration' => array( 'api_token' => 'x' ) ) ) );
check( 'provider configuration: SSRF endpoints and secrets are refused when saved, public https accepted', is_wp_error( $x5_bad_cfg ) && 'wpcap_fiscal_endpoint_invalid' === $x5_bad_cfg->get_error_code() && ! is_wp_error( $x5_ok_cfg ) && is_wp_error( $x5_secret_cfg ) );

/* ------------------------------------------------------------------------------------------ */
echo "X5 fiscal: credentials in the plugin vault\n";
Vault::reset();
$x5_k1 = random_bytes( 32 );
$x5_k2 = random_bytes( 32 );
Vault::set_keys_for_tests( $x5_k1 );
// Legacy draft entry (AES-256-GCM, key from the auth salts) is migrated on first use.
$x5_nonce = random_bytes( 12 );
$x5_tag = '';
$x5_cipher = openssl_encrypt( json_encode( array( 'api_key' => 'legacy-key-123', 'api_secret' => 'legacy-secret-456' ) ), 'aes-256-gcm', hash( 'sha256', wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ), true ), OPENSSL_RAW_DATA, $x5_nonce, $x5_tag );
update_option( Credentials::OPTION, array( 501 => array( 'x5_test' => array( 'keys' => array( 'api_key', 'api_secret' ), 'nonce' => base64_encode( $x5_nonce ), 'tag' => base64_encode( $x5_tag ), 'ciphertext' => base64_encode( $x5_cipher ) ) ) ) );
$x5_migrated = Credentials::with_credentials( array(), 501, 'x5_test' );
$x5_stored = get_option( Credentials::OPTION )[501]['x5_test'] ?? array();
check( 'draft-format credentials are migrated once into the vault (pcv1, no AES-GCM fields left)', 'legacy-secret-456' === ( $x5_migrated['credentials']['api_secret'] ?? '' ) && Vault::is_encrypted( $x5_stored['value'] ?? '' ) && ! isset( $x5_stored['ciphertext'] ) && array( 'api_key', 'api_secret' ) === Credentials::stored_names( 501, 'x5_test' ) );
// Key rotation: entry written with K1, read with K2 (+ K1 as previous) is re-encrypted with K2.
Vault::set_keys_for_tests( $x5_k2, $x5_k1 );
$x5_rot = Credentials::with_credentials( array(), 501, 'x5_test' );
Vault::set_keys_for_tests( $x5_k2 );
$x5_after = Credentials::with_credentials( array(), 501, 'x5_test' );
check( 'key rotation: readable with the previous key, re-encrypted with the current key on use', 'legacy-key-123' === ( $x5_rot['credentials']['api_key'] ?? '' ) && 'legacy-key-123' === ( $x5_after['credentials']['api_key'] ?? '' ) );
Vault::set_keys_for_tests( $x5_k1 );
check( 'after rotation the old key alone no longer decrypts (fails closed)', empty( Credentials::with_credentials( array(), 501, 'x5_test' )['credentials'] ) );
Vault::set_keys_for_tests( $x5_k2 );
Credentials::with_credentials( array(), 501, 'x5_test' );
check( 'decrypted credential values are redacted from later log/audit output of the request', false === strpos( Redact::text( 'provider said legacy-secret-456 is wrong' ), 'legacy-secret-456' ) );
PassCaptain\Audit::record( 'x5_probe', 'seller', 501, array( 'message' => 'echo legacy-secret-456' ) );
check( 'audit details never contain a decrypted credential', false === strpos( (string) $wpdb->get_var( "SELECT details FROM {$p}wpcap_audit_logs WHERE action='x5_probe' ORDER BY audit_id DESC LIMIT 1" ), 'legacy-secret-456' ) );
check( 'REST seller listing exposes no credentials', false === strpos( json_encode( Fiscal::rest_sellers()->get_data() ), 'legacy' ) );
$x5_rot_count = Credentials::migrate();
check( 'Credentials::migrate() is idempotent (nothing left to re-encrypt)', 0 === $x5_rot_count );
Credentials::delete( 501, 'x5_test' );
Credentials::cache_token( 'wpcap_x5_token', 'bearer-token-789', 60 );
check( 'provider tokens are cached encrypted, not in plaintext', false === strpos( (string) get_transient( 'wpcap_x5_token' ), 'bearer-token-789' ) && 'bearer-token-789' === Credentials::cached_token( 'wpcap_x5_token' ) );
Vault::reset();

/* ------------------------------------------------------------------------------------------ */
echo "X5 fiscal: seller model (legal seller vs. sales model)\n";
Settings::update( array( 'refund_approval_threshold' => 0 ) );
$x5_platform = x5_seller( 'X5 Plattform GmbH' );
$x5_org = x5_seller( 'X5 Agentur GmbH' );
foreach ( array( $x5_platform, $x5_org ) as $x5_s ) {
	x5_profile( $x5_s, 'woocommerce' );
	Fiscal::rest_save_provider( x5_rest( 'PUT', array( 'seller_id' => $x5_s ), array( 'provider_id' => 'x5_test', 'enabled' => true, 'configuration' => array( 'region' => 'eu' ) ) ) );
}
$x5_tp = make_type( 50, 10, 1000 );
$x5_ta = make_type( 50, 10, 2000 );
$x5_partner = X5Partners::save( array( 'name' => 'X5 Agentur', 'legal_name' => 'X5 Agentur GmbH', 'billing_address' => "Weg 1\n1010 Wien", 'vat_id' => 'ATU22222222', 'sales_model' => 'agent', 'invoice_prefix' => 'X5A' ) );
X5Partners::set_events( $x5_partner, array( (int) $wpdb->get_var( "SELECT o.event_id FROM {$p}wpcap_ticket_types tt JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=$x5_ta" ) ) );
$x5_wrong = Fiscal::assign_ticket_type( $x5_ta, $x5_platform, 'platform_seller' );
check( 'legal seller role must match the sales model (agent organizer = organizer_seller)', is_wp_error( $x5_wrong ) && 'wpcap_fiscal_seller_mode_mismatch' === $x5_wrong->get_error_code() );
check( 'matching roles are accepted', true === Fiscal::assign_ticket_type( $x5_tp, $x5_platform, 'platform_seller' ) && true === Fiscal::assign_ticket_type( $x5_ta, $x5_org, 'organizer_seller' ) );
$x5_tp2 = make_type( 50, 10, 500 );
$wpdb->update( "{$p}wpcap_ticket_types", array( 'occurrence_id' => (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$x5_tp" ) ), array( 'ticket_type_id' => $x5_tp2 ) );
$x5_mixed = Orders::create( array( 'email' => 'x5-mixed@example.com', 'items' => array( array( 'ticket_type_id' => $x5_tp, 'quantity' => 1 ), array( 'ticket_type_id' => $x5_tp2, 'quantity' => 1 ) ), 'idempotency_key' => 'x5-mixed-' . uniqid() ), array( 'external' => true, 'source' => 'woocommerce', 'key_namespace' => 'x5' ) );
check( 'an order mixing fiscally mapped and unmapped tickets is refused (receipt would omit paid lines)', is_wp_error( $x5_mixed ) );
check( 'checkout messages exist for the fiscal refusal codes', isset( Fiscal::checkout_messages( array() )['fiscal_ticket_unmapped'], Fiscal::checkout_messages( array() )['fiscal_profile_unavailable'] ) );

/* ------------------------------------------------------------------------------------------ */
echo "X5 fiscal: WooCommerce split per seller with fiscal documents\n";
class X5_Item {
	use Test_Meta;
	public function __construct( public int $id, public string $kind, public string $name, public string $total, public string $tax = '0', public ?WC_Product $product = null, public int $qty = 1 ) { $GLOBALS['wpcap_test_items'][ $id ] = $this; }
	public function get_id() { return $this->id; }
	public function get_type() { return $this->kind; }
	public function get_name() { return $this->name; }
	public function get_product() { return $this->product; }
	public function get_quantity() { return $this->qty; }
	public function get_total() { return $this->total; }
	public function get_total_tax() { return $this->tax; }
	public function get_taxes() { return array( 'total' => array() ); }
}
class X5_Order extends Wpcap_Test_Woo_Order {
	public function get_items( $type = 'line_item' ) {
		$types = (array) $type;
		return array_filter( $this->items, static fn( $i ) => in_array( is_callable( array( $i, 'get_type' ) ) ? $i->get_type() : 'line_item', $types, true ) );
	}
	public function add_meta_data( $k, $v, $unique = false ) { $this->meta[ $k ] = array_merge( (array) ( $this->meta[ $k ] ?? array() ), array( $v ) ); }
}
/** Lines: [type_id, qty, total] tickets; ['product', name, total, seller]; ['fee'|'shipping', name, total, seller]. */
function x5_order( $id, array $lines ) {
	static $item_id = 31000, $product_id = 31500;
	$order = new X5_Order( $id );
	$order->payment_method = 'bacs';
	$total = 0.0;
	foreach ( $lines as $line ) {
		if ( is_int( $line[0] ) ) {
			$product = new WC_Product( ++$product_id );
			$product->update_meta_data( Woo::PRODUCT_META, $line[0] );
			$GLOBALS['wpcap_test_products'][ $product_id ] = $product;
			$order->items[] = new X5_Item( ++$item_id, 'line_item', 'Ticket', $line[2], '0', $product, $line[1] );
			$total += (float) $line[2];
			continue;
		}
		$product = 'product' === $line[0] ? new WC_Product( ++$product_id ) : null;
		$item = new X5_Item( ++$item_id, 'product' === $line[0] ? 'line_item' : $line[0], $line[1], $line[2], '0', $product );
		if ( ! empty( $line[3] ) ) { $item->update_meta_data( Woo::FISCAL_SELLER_META, $line[3] ); $item->update_meta_data( Woo::FISCAL_MODE_META, 'platform_seller' ); }
		$order->items[] = $item;
		$total += (float) $line[2];
	}
	$order->total = number_format( $total, 2, '.', '' );
	return $order;
}
update_option( SellerSplit::OPTION, SellerSplit::SPLIT );
$x5_unassigned = x5_order( 9501, array( array( $x5_tp, 1, '10.00' ), array( $x5_ta, 1, '20.00' ), array( 'product', 'Programmheft', '5.00', $x5_platform ), array( 'fee', 'Servicegebühr', '2.00', $x5_platform ), array( 'shipping', 'Versand', '3.00' ) ) );
$x5_r = Woo::reserve( $x5_unassigned );
check( 'mixed cart with an unassigned shipping line is refused before payment', is_wp_error( $x5_r ) && 'wpcap_fiscal_mixed_allocation_required' === $x5_r->get_error_code() && ! Woo::core_order_ids( $x5_unassigned ) );
$x5_wc = x5_order( 9502, array( array( $x5_tp, 1, '10.00' ), array( $x5_ta, 1, '20.00' ), array( 'product', 'Programmheft', '5.00', $x5_platform ), array( 'fee', 'Servicegebühr', '2.00', $x5_platform ), array( 'shipping', 'Versand', '3.00', $x5_platform ) ) );
$x5_first = Woo::reserve( $x5_wc );
$x5_orders = Woo::seller_orders( $x5_wc );
$x5_core_p = (int) ( $x5_orders['platform'] ?? 0 );
$x5_core_o = (int) ( $x5_orders[ 'org-' . $x5_partner ] ?? 0 );
$x5_docs_p = x5_docs( $x5_core_p );
$x5_docs_o = x5_docs( $x5_core_o );
$x5_snap_p = $x5_docs_p ? json_decode( $x5_docs_p[0]->snapshot, true ) : array();
check( 'split order: one Pass Captain order per invoice issuer, one fiscal document per seller order', ! is_wp_error( $x5_first ) && $x5_core_p && $x5_core_o && 1 === count( $x5_docs_p ) && 1 === count( $x5_docs_o ) && $x5_platform === (int) $x5_docs_p[0]->seller_id && $x5_org === (int) $x5_docs_o[0]->seller_id );
check( 'platform document carries its ticket plus the assigned product, fee and shipping (WooCommerce final totals)', 2000 === (int) $x5_docs_p[0]->gross_minor && 4 === count( $x5_snap_p['items'] ?? array() ) && 1000 === (int) Orders::get( $x5_core_p )->total_minor );
check( 'organizer document carries only the organizer ticket; payment method snapshot is the gateway ID', 2000 === (int) $x5_docs_o[0]->gross_minor && 'bacs' === ( json_decode( $x5_docs_o[0]->snapshot, true )['payment_method'] ?? '' ) && $x5_docs_p[0]->document_key !== $x5_docs_o[0]->document_key );
$GLOBALS['x5_fiscal']['fail'][ $x5_org ] = true;
$x5_wc->paid = true;
Woo::sync_paid_order( 9502 );
$x5_doc_o = x5_docs( $x5_core_o )[0];
check( 'payment: the platform receipt is submitted and its tickets issued; the failing seller keeps its tickets blocked', 'submitted' === x5_docs( $x5_core_p )[0]->status && 1 === count( tickets( $x5_core_p ) ) && 0 === count( tickets( $x5_core_o ) ) && 'retry' === $x5_doc_o->status && null !== $x5_doc_o->next_attempt_at );
check( 'order note explains that tickets wait for fiscalization', (bool) array_filter( $x5_wc->notes, static fn( $n ) => false !== strpos( $n, 'warten noch auf die Fiskalisierung' ) ) );
$x5_before = count( x5_calls() );
Orders::issue_and_email( $x5_core_o );
check( 'retries honour the backoff: an issuance attempt before next_attempt_at does not call the provider', $x5_before === count( x5_calls() ) && 0 === count( tickets( $x5_core_o ) ) );
$GLOBALS['x5_fiscal']['fail'][ $x5_org ] = false;
$x5_retry = Fiscal::rest_retry_order( x5_rest( 'POST', array( 'order_id' => $x5_core_o ) ) );
$x5_keys = array_unique( array_map( static fn( $c ) => $c[1], array_filter( x5_calls(), static fn( $c ) => $c[2] === $x5_org ) ) );
check( 'operator retry (forced): status lookup first, then submit with the same idempotency key; tickets issued', ! is_wp_error( $x5_retry ) && 1 === count( tickets( $x5_core_o ) ) && 'submitted' === x5_docs( $x5_core_o )[0]->status && 1 === count( $x5_keys ) && 'query' === x5_calls()[ count( x5_calls() ) - 2 ][0] );

echo "X5 fiscal: WooCommerce refunds of non-ticket lines per seller order\n";
$x5_refund = new Wpcap_Test_Woo_Refund( 9602 );
$x5_refund->amount = '5.00';
$x5_ritem = new X5_Item( 31999, 'line_item', 'Programmheft', '-5.00' );
$x5_ritem->update_meta_data( '_refunded_item_id', $x5_wc->items[2]->get_id() );
$x5_refund->items[] = $x5_ritem;
$x5_wc->refund_list[] = $x5_refund;
Woo::sync_refund( 9502, 9602 );
$x5_ev = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_fiscal_events WHERE event_type='refund_adjustment' AND order_id IN (%d,%d) ORDER BY event_id DESC LIMIT 1", $x5_core_p, $x5_core_o ) );
$x5_ev_data = $x5_ev ? json_decode( $x5_ev->metadata, true ) : array();
check( 'refund of the product line is recorded once on the platform seller document (manual_required, allocated, no manual seller allocation)', $x5_ev && $x5_core_p === (int) $x5_ev->order_id && 'manual_required' === $x5_ev->event_status && 500 === (int) ( $x5_ev_data['allocated_items'][0]['amount_minor'] ?? 0 ) && empty( $x5_ev_data['seller_allocation_required'] ) && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_fiscal_events WHERE event_type='refund_adjustment' AND order_id IN ($x5_core_p,$x5_core_o)" ) );
Orders::cancel_order( $x5_core_o, 'x5_test' );
$x5_cancel = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_fiscal_events WHERE event_type='order_cancellation' AND order_id=%d", $x5_core_o ) );
check( 'cancelling a paid seller order appends a refund_or_correction task to its submitted receipt (original stays immutable)', $x5_cancel && 'refund_or_correction' === ( json_decode( $x5_cancel->metadata, true )['adjustment_kind'] ?? '' ) && 'submitted' === x5_docs( $x5_core_o )[0]->status );

echo "X5 fiscal: negative fees with and without required receipts\n";
$x5_neg = x5_order( 9503, array( array( $x5_tp, 1, '10.00' ), array( 'product', 'Programmheft', '10.00', $x5_platform ), array( 'fee', 'Rabatt', '-4.00' ) ) );
$x5_neg_r = Woo::reserve( $x5_neg );
check( 'fiscal receipt required + negative fee that also falls on a product: checkout refused with a clear message', is_wp_error( $x5_neg_r ) && 'wpcap_fiscal_negative_fee' === $x5_neg_r->get_error_code() && false !== strpos( $x5_neg_r->get_error_message(), 'Gutschein' ) );
$x5_neg2 = x5_order( 9504, array( array( $x5_tp, 1, '10.00' ), array( 'fee', 'Rabatt', '-4.00' ) ) );
$x5_neg2_r = Woo::reserve( $x5_neg2 );
$x5_neg2_doc = ! is_wp_error( $x5_neg2_r ) ? x5_docs( (int) $x5_neg2_r ) : array();
check( 'negative fee on a ticket-only cart is represented as reduced ticket line (W10 allocation): receipt = paid amount', ! is_wp_error( $x5_neg2_r ) && 1 === count( $x5_neg2_doc ) && 600 === (int) $x5_neg2_doc[0]->gross_minor && 600 === (int) Orders::get( (int) $x5_neg2_r )->total_minor );
x5_profile( $x5_platform, 'woocommerce', 'not_required' );
$x5_neg3 = x5_order( 9505, array( array( $x5_tp, 1, '10.00' ), array( 'product', 'Programmheft', '10.00', $x5_platform ), array( 'fee', 'Rabatt', '-4.00' ) ) );
$x5_neg3_r = Woo::reserve( $x5_neg3 );
$x5_plan = $x5_neg3->get_meta( SellerSplit::PLAN_META, true );
check( 'reviewed not_required profile: W10 behaviour kept (fee allocated to ticket and shop, no fiscal document)', ! is_wp_error( $x5_neg3_r ) && 200 === (int) ( $x5_plan['shop']['fee_discount'] ?? 0 ) && 800 === (int) Orders::get( (int) $x5_neg3_r )->total_minor && ! x5_docs( (int) $x5_neg3_r ) );
x5_profile( $x5_platform, 'woocommerce', 'active' );
$x5_tn = make_type( 50, 10, 1000 );
$x5_neg4 = x5_order( 9506, array( array( $x5_tn, 1, '10.00' ), array( 'product', 'Programmheft', '10.00' ), array( 'fee', 'Rabatt', '-4.00' ) ) );
$x5_neg4_r = Woo::reserve( $x5_neg4 );
check( 'tickets without fiscal mapping: unchanged W10 behaviour (no seller assignment required, fee allocated)', ! is_wp_error( $x5_neg4_r ) && 800 === (int) Orders::get( (int) $x5_neg4_r )->total_minor );
delete_option( SellerSplit::OPTION );

/* ------------------------------------------------------------------------------------------ */
echo "X5 fiscal: box office gating (stop_sale) with terminal registry and W6 faults\n";
$GLOBALS['wpcap_test_user_id'] = 4343;
$x5_pos_type = make_type( 0, 10, 1500 );
$x5_pos_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$x5_pos_type" );
Fiscal::assign_ticket_type( $x5_pos_type, $x5_platform, 'platform_seller' );
x5_profile( $x5_platform, 'pos', 'active', 23, 'BOX-X5' );
Settings::update( array( 'boxoffice_terminals' => "23|BOX-X5|Kasse X5" ) );
$x5_unlisted = X5Pos::open_session( 0, 'EUR', '', 23, 'OTHER' );
$x5_session = X5Pos::open_session( 0, 'EUR', '', 23, 'BOX-X5' );
check( 'terminal registry: only listed registers open a session; the session keeps location/register', is_wp_error( $x5_unlisted ) && ! is_wp_error( $x5_session ) && 'BOX-X5' === $x5_session->fiscal_register_id && 23 === (int) $x5_session->location_id ); // merged: register_id is the numeric register (shift), the terminal string is fiscal_register_id
$GLOBALS['x5_fiscal']['fail'][ $x5_platform ] = true;
$x5_key = 'x5pos-' . bin2hex( random_bytes( 8 ) );
$x5_sale = X5Pos::sell( array( 'occurrence_id' => $x5_pos_occ, 'quantities' => array( $x5_pos_type => 1 ), 'method' => 'card', 'form_key' => $x5_key ) );
$x5_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_boxoffice_sales WHERE form_key_hash=%s", hash( 'sha256', 'pos-sale|' . $x5_key ) ) );
check( 'provider down: no tickets, no receipt number, the sale stays pending with a fiscal message', is_wp_error( $x5_sale ) && 'wpcap_pos_fiscal_pending' === $x5_sale->get_error_code() && $x5_row && 'pending' === $x5_row->status && '' === (string) $x5_row->receipt_number && 0 === count( tickets( (int) $x5_row->order_id ) ) );
check( 'POS snapshot: payment method from the box office method and the session register as fiscal scope', 'card' === ( json_decode( x5_docs( (int) $x5_row->order_id )[0]->snapshot, true )['payment_method'] ?? '' ) && 'BOX-X5' === ( json_decode( x5_docs( (int) $x5_row->order_id )[0]->snapshot, true )['profile']['register_id'] ?? '' ) );
$x5_close = X5Pos::close_session( (int) $x5_session->session_id, 0 );
check( 'W6: the session cannot be closed while the paid sale is unbooked (fiscal pending)', is_wp_error( $x5_close ) && 'wpcap_pos_unbooked' === $x5_close->get_error_code() );
$GLOBALS['x5_fiscal']['fail'][ $x5_platform ] = false;
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_fiscal_documents SET next_attempt_at=%s WHERE order_id=%d", gmdate( 'Y-m-d H:i:s', time() - 60 ), (int) $x5_row->order_id ) );
$x5_resumed = X5Pos::finalize( (int) $x5_row->sale_id );
check( 'W6 resume ("Nachbuchen") after the provider is back: booked with receipt number and tickets', is_array( $x5_resumed ) && 'completed' === $x5_resumed['sale']->status && '' !== (string) $x5_resumed['sale']->receipt_number && 1 === count( tickets( (int) $x5_row->order_id ) ) );
check( 'POS receipt shows the fiscal block of the seller document', false !== strpos( X5Pos::receipt_html( (int) $x5_row->sale_id ), 'Fiskalbelege' ) );
// Fault matrix: every statement of a fiscal box office sale fails once (4 kinds); invariants hold.
$GLOBALS['x5_fiscal']['calls'] = array();
w6_matrix(
	'fiscal box office sale',
	static function () use ( $x5_pos_type, $x5_pos_occ ) {
		$GLOBALS['wpcap_test_user_id'] = 4343;
		return array( 'type' => $x5_pos_type, 'occ' => $x5_pos_occ, 'key' => 'x5w6-' . bin2hex( random_bytes( 8 ) ) );
	},
	static function ( $ctx ) {
		$GLOBALS['wpcap_test_user_id'] = 4343;
		return X5Pos::sell( array( 'occurrence_id' => $ctx['occ'], 'quantities' => array( $ctx['type'] => 1 ), 'method' => 'cash', 'form_key' => $ctx['key'] ) );
	},
	static function ( $ctx, $r, $phase ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$sale = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_boxoffice_sales WHERE form_key_hash=%s", hash( 'sha256', 'pos-sale|' . $ctx['key'] ) ) );
		$order_id = $sale ? (int) $sale->order_id : 0;
		$tickets = $order_id ? count( Orders::tickets_for_order( $order_id ) ) : 0;
		$docs = $order_id ? $wpdb->get_results( $wpdb->prepare( "SELECT status,document_key FROM {$p}wpcap_fiscal_documents WHERE order_id=%d", $order_id ) ) : array();
		$submitted = $docs && ! array_filter( $docs, static fn( $d ) => 'submitted' !== $d->status );
		if ( $order_id && count( $docs ) > 1 ) { return 'more than one fiscal document for one seller'; }
		if ( $tickets && ! $submitted ) { return 'tickets issued without confirmed fiscal receipt'; }
		if ( $sale && 'completed' === $sale->status && ( ! $submitted || 1 !== $tickets || '' === (string) $sale->receipt_number ) ) { return 'booked sale without receipt/tickets/fiscal document'; }
		if ( $docs ) {
			$keys = array_unique( array_map( static fn( $c ) => $c[1], array_filter( $GLOBALS['x5_fiscal']['calls'], static fn( $c ) => 'submit' === $c[0] && $c[1] === $docs[0]->document_key ) ) );
			if ( count( $keys ) > 1 ) { return 'different idempotency keys for one document'; }
		}
		if ( 'final' === $phase && ( ! is_array( $r ) || ! $sale || 'completed' !== $sale->status ) ) { return 'not booked after recovery: ' . ( is_wp_error( $r ) ? $r->get_error_code() : 'no result' ); }
		return '';
	},
	static function ( $ctx ) {
		global $wpdb;
		w6_recover();
		// Operator recovery for fiscal documents: due now (a fault may have left a retry with backoff).
		$wpdb->query( "UPDATE {$wpdb->prefix}wpcap_fiscal_documents SET next_attempt_at=NULL, status=IF(status='failed','retry',status) WHERE status IN ('retry','failed','pending')" );
	},
	24
);
$GLOBALS['wpcap_test_user_id'] = 1;
Settings::update( array( 'boxoffice_terminals' => '' ) );
$wpdb->query( "UPDATE {$p}wpcap_jobs SET status='complete' WHERE job_type IN ('ticket_issue','ticket_delivery') AND status='queued'" );
// Leave no fiscal mapping behind for later tests.
$wpdb->query( "DELETE FROM {$p}wpcap_ticket_sellers WHERE ticket_type_id IN ($x5_tp,$x5_ta,$x5_pos_type)" );
$GLOBALS['wpcap_test_dns'] = array();

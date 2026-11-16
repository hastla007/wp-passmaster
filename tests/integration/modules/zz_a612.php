<?php
/**
 * A612: consent events in outgoing webhooks (consent.granted / consent.withdrawn) for both consent
 * mechanisms; personal data only for subscriptions with include_pii.
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

use PassCaptain\Webhooks\Dispatcher;

echo "A612: consent webhooks\n";
if ( false === has_action( 'wpcap_consent_granted', array( PassCaptain\Modules\Webhooks::class, 'consent_granted' ) ) ) { PassCaptain\Modules\Webhooks::register(); }
global $wpdb;
$p = $wpdb->prefix;
check( 'A612: consent.granted and consent.withdrawn are subscribable webhook types', in_array( 'consent.granted', Dispatcher::EVENTS, true ) && in_array( 'consent.withdrawn', Dispatcher::EVENTS, true ) && '1.0' === Dispatcher::SCHEMA_VERSIONS['consent.granted'] );
$GLOBALS['wpcap_test_dns']['consent-a612.example'] = array( '93.184.216.34' );
$a612_sub = Dispatcher::create_subscription( 'https://consent-a612.example/in', array( 'consent.granted', 'consent.withdrawn' ) );
check( 'A612: fixture subscription', ! is_wp_error( $a612_sub ) );
if ( ! is_wp_error( $a612_sub ) ) {
	$a612_sid = (int) $a612_sub['subscription_id'];
	$wpdb->query( "UPDATE {$p}wpcap_webhook_subscriptions SET include_pii=0 WHERE subscription_id=$a612_sid" );
	$a612_log = 900000 + mt_rand( 1, 99999 );
	do_action( 'wpcap_consent_granted', array( 'log_id' => $a612_log, 'ticket_id' => 0, 'ticket_public_id' => 'tkt-a612', 'order_id' => 0, 'field_id' => 7, 'field_key' => 'photo', 'text_version' => 'v2', 'source' => 'checkout', 'locale' => 'de_DE', 'created_at' => '2026-11-16 10:00:00', 'email' => 'person-a612@example.com' ) );
	do_action( 'wpcap_consent_granted', array( 'log_id' => $a612_log, 'ticket_public_id' => 'tkt-a612', 'field_id' => 7, 'source' => 'checkout', 'email' => 'person-a612@example.com' ) );
	$a612_rows = $wpdb->get_col( "SELECT payload FROM {$p}wpcap_webhook_deliveries WHERE subscription_id=$a612_sid AND event_type='consent.granted'" );
	$a612_data = json_decode( (string) ( $a612_rows[0] ?? '' ), true );
	$a612_obj = $a612_data['data']['object'] ?? array();
	check( 'A612: a granted consent becomes one consent.granted delivery (the same record twice stays one)', 1 === count( $a612_rows ) && 'consent' === ( $a612_obj['object'] ?? '' ) && 'granted' === ( $a612_obj['state'] ?? '' ) && 'tkt-a612' === ( $a612_obj['ticket_public_id'] ?? '' ) && 'v2' === ( $a612_obj['text_version'] ?? '' ) );
	check( 'A612: without include_pii the payload has no e-mail address', false === strpos( (string) $a612_rows[0], '@' ) );
	$wpdb->query( "UPDATE {$p}wpcap_webhook_subscriptions SET include_pii=1 WHERE subscription_id=$a612_sid" );
	do_action( 'wpcap_consent_withdrawn', 4242, (object) array( 'consent_id' => 4242, 'ticket_id' => 0, 'order_id' => 0, 'field_id' => 9, 'text_version' => 'v1', 'withdrawn_source' => 'portal', 'withdrawn_at' => '2026-11-16 11:00:00', 'subject_email' => 'person-a612@example.com' ) );
	$a612_w = json_decode( (string) $wpdb->get_var( "SELECT payload FROM {$p}wpcap_webhook_deliveries WHERE subscription_id=$a612_sid AND event_type='consent.withdrawn' ORDER BY delivery_id DESC LIMIT 1" ), true );
	$a612_wo = $a612_w['data']['object'] ?? array();
	check( 'A612: Sales\Consents withdrawals (id, row) become consent.withdrawn; the e-mail only with include_pii', 'withdrawn' === ( $a612_wo['state'] ?? '' ) && 'consent:4242' === ( $a612_wo['consent_id'] ?? '' ) && 'portal' === ( $a612_wo['source'] ?? '' ) && 'person-a612@example.com' === ( $a612_wo['attendee_email'] ?? '' ) );
	$wpdb->query( "DELETE FROM {$p}wpcap_webhook_deliveries WHERE subscription_id=$a612_sid" );
	$wpdb->query( "DELETE FROM {$p}wpcap_webhook_subscriptions WHERE subscription_id=$a612_sid" );
}

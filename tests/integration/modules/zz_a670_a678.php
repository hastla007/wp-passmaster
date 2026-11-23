<?php
/**
 * A670/A678: admin status of the Captain-Suite contracts on the "Betrieb" page – contract available,
 * incompatible or without provider, last accepted delivery, pending and failed events, last error and
 * the native fallback; no licence or entitlement check in the core. Since PC-7 only the review contract
 * is listed (no workflow contract, no workflow commands, no mail handoff). Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\SuiteStatus;
use PassCaptain\Review\Contract as ReviewContract;
use PassCaptain\Suite\Outbox;

global $wpdb;
$x6s_filters_before = $GLOBALS['wpcap_test_filters'];
if ( ! function_exists( 'wp_timezone_string' ) ) { function wp_timezone_string() { return 'Europe/Vienna'; } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce" value="n">'; } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'submit_button' ) ) { function submit_button( $t = '', $c = '' ) { echo '<button type="submit">' . esc_html( $t ) . '</button>'; } }
unset( $GLOBALS['wpcap_test_filters'][ ReviewContract::FILTER ] );

final class X6s_Provider {
	public $version; public $answer = true; public $got = 0;
	public function __construct( $version ) { $this->version = $version; }
	public function contract_version() { return $this->version; }
	public function receive( array $e ) { ++$this->got; return $this->answer; }
}

echo "A670/A678: suite contract status in the admin\n";
$x6s_d = SuiteStatus::data();
check( 'A670/PC-7: only the review contract is listed, with its core version and filter (no workflow contract)', array( 'review' ) === array_keys( $x6s_d ) && '1.0.0' === $x6s_d['review']['version'] && ReviewContract::FILTER === $x6s_d['review']['filter'] );
check( 'A670: without a plugin the state is "missing" (contract available, no provider)', 'missing' === $x6s_d['review']['state'] && false !== strpos( SuiteStatus::state_label( $x6s_d['review'] ), 'kein Plugin' ) );
$GLOBALS['wpcap_test_filters'][ ReviewContract::FILTER ] = new X6s_Provider( '2.1.0' );
$x6s_d = SuiteStatus::data();
check( 'A670: a provider of another major version is shown as incompatible, with both versions', 'incompatible' === $x6s_d['review']['state'] && '2.1.0' === $x6s_d['review']['provider_version'] && false !== strpos( SuiteStatus::state_label( $x6s_d['review'] ), '2.1.0' ) && false !== strpos( SuiteStatus::state_label( $x6s_d['review'] ), '1.0.0' ) );

// A678: last accepted delivery, errors, pending/failed.
$x6s_rv = new X6s_Provider( '1.4.0' );
$x6s_rv->answer = new WP_Error( 'wpcap_review_temporary', 'later' );
$GLOBALS['wpcap_test_filters'][ ReviewContract::FILTER ] = $x6s_rv;
check( 'A670: a compatible provider is shown as available and active', 'ready' === SuiteStatus::data()['review']['state'] );
$x6s_type = make_type( 5 );
$x6s_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$wpdb->prefix}wpcap_ticket_types WHERE ticket_type_id=$x6s_type" );
$x6s_event = (int) $wpdb->get_var( "SELECT event_id FROM {$wpdb->prefix}wpcap_occurrences WHERE occurrence_id=$x6s_occ" );
$x6s_id = Outbox::record( 'experience_cancelled', 'x6s|' . uniqid(), 'occurrence:' . $x6s_occ, array( 'experience_id' => 'occurrence:' . $x6s_occ, 'scope' => 'occurrence', 'event_id' => $x6s_event, 'occurrence_ids' => array( $x6s_occ ), 'outcome' => 'cancelled', 'cancelled_at' => gmdate( 'Y-m-d\TH:i:s\Z' ) ) );
$wpdb->query( $wpdb->prepare( 'UPDATE ' . Outbox::table() . " SET status='delivered' WHERE status='pending' AND event_id<>%s", (string) $x6s_id ) );
Outbox::deliver();
$x6s_d = SuiteStatus::data();
check( 'A678: a temporary provider error shows as last error with the event still pending', $x6s_id && 'wpcap_review_temporary' === $x6s_d['review']['last_error'] && $x6s_d['review']['pending'] >= 1 && 'ready' === $x6s_d['review']['state'] );
$wpdb->query( $wpdb->prepare( 'UPDATE ' . Outbox::table() . ' SET next_attempt_at=%s WHERE event_id=%s', gmdate( 'Y-m-d H:i:s', time() - 60 ), (string) $x6s_id ) );
$x6s_rv->answer = true;
Outbox::deliver();
$x6s_d = SuiteStatus::data();
check( 'A678: after the provider took the event, the last accepted delivery has a time and nothing is pending', '' !== $x6s_d['review']['last_delivered_at'] && 0 === $x6s_d['review']['pending'] && 'delivered' === $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . Outbox::table() . ' WHERE event_id=%s', (string) $x6s_id ) ) );

ob_start();
SuiteStatus::render();
$x6s_html = ob_get_clean();
check( 'A670/A678: the "Betrieb" page section shows contract, state, last delivery, pending/failed, last error and the fallback', false !== strpos( $x6s_html, 'id="wpcap-suite"' ) && false !== strpos( $x6s_html, 'data-contract="review" data-state="ready"' ) && false !== strpos( $x6s_html, 'Ohne Provider' ) && false !== strpos( $x6s_html, 'Letzter Fehler' ) );
check( 'PC-7: the section has no workflow row, no workflow commands and no mail handoff form', false === strpos( $x6s_html, 'data-contract="workflow"' ) && false === stripos( $x6s_html, 'Workflow-Commands' ) && false === strpos( $x6s_html, 'name="types[]"' ) && false === strpos( $x6s_html, 'wpcap_workflow_mail_handoff' ) );
$x6s_src = (string) file_get_contents( WPCAP_PATH . 'src/Admin/SuiteStatus.php' ) . (string) file_get_contents( WPCAP_PATH . 'src/Suite/Contract.php' ) . (string) file_get_contents( WPCAP_PATH . 'src/Review/Contract.php' );
check( 'A670: no licence or entitlement check in the core status (diagnosis only)', ! preg_match( '/LicenseClient|allows_entitlement|wplc\/v1|license_key/i', $x6s_src ) );
$GLOBALS['wpcap_test_filters'] = $x6s_filters_before;

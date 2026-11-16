<?php
/**
 * A670/A678: admin status of the Captain-Suite contracts on the "Betrieb" page – contract available,
 * incompatible or without provider, last accepted delivery, pending and failed events, last error,
 * workflow commands, mail handoffs and the native fallback; no licence or entitlement check in the
 * core. Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\SuiteStatus;
use PassCaptain\Review\Contract as ReviewContract;
use PassCaptain\Workflow\Contract as WorkflowContract;
use PassCaptain\Workflow\Outbox;

global $wpdb;
$x6s_filters_before = $GLOBALS['wpcap_test_filters'];
if ( ! function_exists( 'wp_timezone_string' ) ) { function wp_timezone_string() { return 'Europe/Vienna'; } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce" value="n">'; } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'submit_button' ) ) { function submit_button( $t = '', $c = '' ) { echo '<button type="submit">' . esc_html( $t ) . '</button>'; } }
unset( $GLOBALS['wpcap_test_filters'][ WorkflowContract::FILTER ], $GLOBALS['wpcap_test_filters'][ ReviewContract::FILTER ] );

final class X6s_Provider {
	public $version; public $answer = true; public $got = 0;
	public function __construct( $version ) { $this->version = $version; }
	public function contract_version() { return $this->version; }
	public function receive( array $e ) { ++$this->got; return $this->answer; }
}

echo "A670/A678: suite contract status in the admin\n";
$x6s_d = SuiteStatus::data();
check( 'A670: both suite contracts are listed with their core version and filter', array( 'workflow', 'review' ) === array_keys( $x6s_d ) && '1.0.0' === $x6s_d['review']['version'] && ReviewContract::FILTER === $x6s_d['review']['filter'] );
check( 'A670: without a plugin the state is "missing" (contract available, no provider)', 'missing' === $x6s_d['workflow']['state'] && 'missing' === $x6s_d['review']['state'] && false !== strpos( SuiteStatus::state_label( $x6s_d['review'] ), 'kein Plugin' ) );
$GLOBALS['wpcap_test_filters'][ ReviewContract::FILTER ] = new X6s_Provider( '2.1.0' );
$x6s_d = SuiteStatus::data();
check( 'A670: a provider of another major version is shown as incompatible, with both versions', 'incompatible' === $x6s_d['review']['state'] && '2.1.0' === $x6s_d['review']['provider_version'] && false !== strpos( SuiteStatus::state_label( $x6s_d['review'] ), '2.1.0' ) && false !== strpos( SuiteStatus::state_label( $x6s_d['review'] ), '1.0.0' ) );
$GLOBALS['wpcap_test_filters'][ ReviewContract::FILTER ] = new X6s_Provider( '1.4.0' );
check( 'A670: a compatible provider is shown as available and active', 'ready' === SuiteStatus::data()['review']['state'] );

// A678: last accepted delivery, errors, pending/failed per contract.
$x6s_wf = new X6s_Provider( '1.0.0' );
$x6s_wf->answer = new WP_Error( 'wpcap_workflow_temporary', 'later' );
$GLOBALS['wpcap_test_filters'][ WorkflowContract::FILTER ] = $x6s_wf;
$x6s_type = make_type( 5 );
$x6s_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$wpdb->prefix}wpcap_ticket_types WHERE ticket_type_id=$x6s_type" );
$x6s_id = Outbox::record( 'occurrence.changed', 'x6s|' . uniqid(), (string) $x6s_occ, array( 'occurrence_id' => $x6s_occ, 'changed_fields' => array( 'location' ) ) );
$wpdb->query( $wpdb->prepare( 'UPDATE ' . Outbox::table() . " SET status='delivered' WHERE contract='workflow' AND status='pending' AND event_id<>%s", (string) $x6s_id ) );
Outbox::deliver();
$x6s_d = SuiteStatus::data();
check( 'A678: a temporary provider error shows as last error with the event still pending', $x6s_id && 'wpcap_workflow_temporary' === $x6s_d['workflow']['last_error'] && $x6s_d['workflow']['pending'] >= 1 && 'ready' === $x6s_d['workflow']['state'] );
$wpdb->query( $wpdb->prepare( 'UPDATE ' . Outbox::table() . ' SET next_attempt_at=%s WHERE event_id=%s', gmdate( 'Y-m-d H:i:s', time() - 60 ), (string) $x6s_id ) );
$x6s_wf->answer = true;
Outbox::deliver();
$x6s_d = SuiteStatus::data();
check( 'A678: after the provider took the event, the last accepted delivery has a time and nothing is pending', '' !== $x6s_d['workflow']['last_delivered_at'] && 0 === $x6s_d['workflow']['pending'] && 'delivered' === $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . Outbox::table() . ' WHERE event_id=%s', (string) $x6s_id ) ) );
check( 'A678: review events are counted separately from workflow events', $x6s_d['review']['delivered'] !== $x6s_d['workflow']['delivered'] || 0 === $x6s_d['review']['delivered'] );

ob_start();
SuiteStatus::render();
$x6s_html = ob_get_clean();
check( 'A670/A678: the "Betrieb" page section shows contract, state, last delivery, pending/failed, last error and the fallback', false !== strpos( $x6s_html, 'id="wpcap-suite"' ) && false !== strpos( $x6s_html, 'data-contract="review" data-state="ready"' ) && false !== strpos( $x6s_html, 'data-contract="workflow" data-state="ready"' ) && false !== strpos( $x6s_html, 'Ohne Provider' ) && false !== strpos( $x6s_html, 'Letzter Fehler' ) );
check( 'A677/A678: the section offers the mail handoff choice with its counters', false !== strpos( $x6s_html, 'name="types[]" value="queue_wpcap_reminder"' ) && false !== strpos( $x6s_html, 'Übergeben:' ) && false !== strpos( $x6s_html, SuiteStatus::HANDOFF_ACTION ) );
$x6s_src = (string) file_get_contents( WPCAP_PATH . 'src/Admin/SuiteStatus.php' ) . (string) file_get_contents( WPCAP_PATH . 'src/Suite/Contract.php' ) . (string) file_get_contents( WPCAP_PATH . 'src/Review/Contract.php' );
check( 'A670: no licence or entitlement check in the core status (diagnosis only)', ! preg_match( '/LicenseClient|allows_entitlement|wplc\/v1|license_key/i', $x6s_src ) );

$_POST = array( 'action' => SuiteStatus::HANDOFF_ACTION, '_wpnonce' => 'n', 'types' => array( 'queue_wpcap_reminder' ) );
try { SuiteStatus::handle_handoff(); } catch ( Wpcap_Test_Redirect $e ) { $x6s_redirect = $e->getMessage(); }
$_POST = array();
check( 'A677/A678: the handoff form saves the choice and returns to the section', array( 'queue_wpcap_reminder' ) === PassCaptain\Workflow\MailHandoff::enabled() && false !== strpos( (string) ( $x6s_redirect ?? '' ), '#wpcap-suite' ) );
delete_option( PassCaptain\Workflow\MailHandoff::OPTION );
$GLOBALS['wpcap_test_filters'] = $x6s_filters_before;

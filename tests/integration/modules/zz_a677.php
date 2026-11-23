<?php
/**
 * A677: optional mails (date reminder) can be handed to the workflow provider per mail type – an
 * explicit opt-in, logged and counted, with the native send as fallback whenever the provider is
 * missing, incompatible, does not support mails, refuses or fails. Transactional mails never leave
 * Pass Captain. Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Mail\Mailer;
use PassCaptain\Workflow\Contract;
use PassCaptain\Workflow\MailHandoff;

$x6h_filters_before = $GLOBALS['wpcap_test_filters'];
unset( $GLOBALS['wpcap_test_filters'][ Contract::FILTER ] );
delete_option( MailHandoff::OPTION );
delete_option( MailHandoff::STATS_OPTION );

final class X6h_Mail_Provider {
	public $mails = array();
	public $answer = true;
	public function contract_version() { return '1.0.0'; }
	public function receive( array $envelope ) { return true; }
	public function handle_mail( array $handoff ) {
		if ( 'throw' === $this->answer ) { throw new RuntimeException( 'down' ); }
		$this->mails[] = $handoff;
		return $this->answer;
	}
}
final class X6h_Plain_Provider { public function receive( array $envelope ) { return true; } }

$x6h_reminder = array( 'type' => 'queue_wpcap_reminder', 'subject' => 'Morgen: Konzert', 'body' => 'Bis morgen!', 'format' => 'text', 'locale' => 'de_DE', 'attachments' => array() );
$x6h_send = static function ( array $message = null ) use ( $x6h_reminder ) {
	$before = count( $GLOBALS['wpcap_test_mails'] );
	$ok = Mailer::deliver( 'gast@example.com', $message ? $message : $x6h_reminder );
	return array( $ok, count( $GLOBALS['wpcap_test_mails'] ) - $before );
};

echo "A677: optional mails handed to the workflow provider, native fallback\n";
check( 'A677: the date reminder can be handed off; transactional types never (even if a filter adds them)', isset( MailHandoff::types()['queue_wpcap_reminder'] ) && ! isset( MailHandoff::types()['tickets'] ) );
$GLOBALS['wpcap_test_filters']['wpcap_workflow_mail_handoff_types'] = array( 'tickets' => 'Tickets', 'queue_wpcap_reminder' => 'Erinnerung', 'refund_confirmation' => 'X' );
check( 'A677: a filter cannot release ticket or refund mails', array( 'queue_wpcap_reminder' ) === array_keys( MailHandoff::types() ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_workflow_mail_handoff_types'] );

list( $x6h_ok, $x6h_n ) = $x6h_send();
check( 'A677: without an opt-in the reminder is sent natively', $x6h_ok && 1 === $x6h_n );

$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
check( 'A677: only administrators choose the handed-off types', is_wp_error( MailHandoff::save( array( 'queue_wpcap_reminder' ) ) ) && array() === MailHandoff::enabled() );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'A677: the choice is saved (unknown and transactional types are dropped)', array( 'queue_wpcap_reminder' ) === MailHandoff::save( array( 'queue_wpcap_reminder', 'tickets', 'nonsense' ) ) && array( 'queue_wpcap_reminder' ) === MailHandoff::enabled() );

list( $x6h_ok, $x6h_n ) = $x6h_send();
check( 'A677: opted in but no provider: native fallback (counted as provider_missing)', $x6h_ok && 1 === $x6h_n && 'provider_missing' === ( MailHandoff::stats()['last']['reason'] ?? '' ) );
$GLOBALS['wpcap_test_filters'][ Contract::FILTER ] = new X6h_Plain_Provider();
list( $x6h_ok, $x6h_n ) = $x6h_send();
check( 'A677: a provider without handle_mail(): native fallback (not_supported)', 1 === $x6h_n && 'not_supported' === MailHandoff::stats()['last']['reason'] );

$x6h_p = new X6h_Mail_Provider();
$GLOBALS['wpcap_test_filters'][ Contract::FILTER ] = $x6h_p;
list( $x6h_ok, $x6h_n ) = $x6h_send();
check( 'A677: the provider takes the reminder – Pass Captain does not send it as well', $x6h_ok && 0 === $x6h_n && 1 === count( $x6h_p->mails ) );
check( 'A677: the handoff carries what the mail needs (type, recipient, language, subject, body, format)', 'queue_wpcap_reminder' === $x6h_p->mails[0]['type'] && 'gast@example.com' === $x6h_p->mails[0]['to'] && 'de_DE' === $x6h_p->mails[0]['locale'] && 'Morgen: Konzert' === $x6h_p->mails[0]['subject'] && 'text' === $x6h_p->mails[0]['format'] && '1.0.0' === $x6h_p->mails[0]['handoff_version'] );
check( 'A677: accepted handoffs are counted', 1 === MailHandoff::stats()['accepted'] && 'accepted' === MailHandoff::stats()['last']['result'] );
$x6h_p->answer = new WP_Error( 'wpcap_workflow_rejected', 'no' );
list( , $x6h_n ) = $x6h_send();
check( 'A677: a refusal falls back to the native send', 1 === $x6h_n && 'rejected' === MailHandoff::stats()['last']['reason'] );
$x6h_p->answer = 'throw';
list( , $x6h_n ) = $x6h_send();
check( 'A677: a failing provider falls back to the native send', 1 === $x6h_n && 'provider_error' === MailHandoff::stats()['last']['reason'] );
$x6h_p->answer = true;
list( , $x6h_n ) = $x6h_send( array( 'attachments' => array( __FILE__ ) ) + $x6h_reminder );
check( 'A677: mails with attachments are never handed off', 1 === $x6h_n );
update_option( MailHandoff::OPTION, array( 'queue_wpcap_reminder', 'tickets' ) );
$x6h_before = count( $x6h_p->mails );
list( , $x6h_n ) = $x6h_send( array( 'type' => 'tickets' ) + $x6h_reminder );
check( 'A677: a ticket mail is sent natively even if the option was edited to include it', 1 === $x6h_n && $x6h_before === count( $x6h_p->mails ) );
$GLOBALS['wpcap_test_filters'][ Contract::FILTER ] = new class() { public function contract_version() { return '2.0.0'; } public function receive( array $e ) { return true; } public function handle_mail( array $h ) { return true; } };
list( , $x6h_n ) = $x6h_send();
check( 'A677: a provider of another major version is not used (native send)', 1 === $x6h_n && 'provider_incompatible' === MailHandoff::stats()['last']['reason'] );

delete_option( MailHandoff::OPTION );
delete_option( MailHandoff::STATS_OPTION );
$GLOBALS['wpcap_test_filters'] = $x6h_filters_before;

<?php
/**
 * PC-7 guard (owner decision 23.11.2026, suite decisions D13/D10, like the guards of Voucher and
 * Affiliate Captain): the core has no workflow provider contract, no workflow outbox, no mail handoff
 * (`wpcap_workflow_mail_handoff`) and never hands a mail to another plugin – Mailer sends every mail
 * itself, whatever a plugin registers. Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Mail\Mailer;

echo "PC-7 guard: no workflow contract and no mail handoff in the core\n";
$pc7g_src = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WPCAP_PATH . 'src', FilesystemIterator::SKIP_DOTS ) ) as $pc7g_file ) {
	if ( 'php' === $pc7g_file->getExtension() ) { $pc7g_src[ substr( $pc7g_file->getPathname(), strlen( WPCAP_PATH ) ) ] = (string) file_get_contents( $pc7g_file->getPathname() ); }
}
$pc7g_hits = static function ( $pattern ) use ( $pc7g_src ) { return array_keys( array_filter( $pc7g_src, static fn( $code ) => (bool) preg_match( $pattern, $code ) ) ); };

check( 'PC-7 guard: no src/Workflow/ directory and no class in the namespace PassCaptain\\Workflow', ! is_dir( WPCAP_PATH . 'src/Workflow' ) && ! $pc7g_hits( '/^namespace\s+PassCaptain\\\\Workflow\b/m' ) && ! class_exists( 'PassCaptain\\Workflow\\Contract' ) && ! class_exists( 'PassCaptain\\Workflow\\MailHandoff' ) && ! class_exists( 'PassCaptain\\Workflow\\Commands' ) );
$pc7g_provider = $pc7g_hits( '/wp_pass_captain_workflow_provider_v\d/' );
check( 'PC-7 guard: no workflow provider filter wp_pass_captain_workflow_provider_v* in src/' . ( $pc7g_provider ? ' – ' . implode( ', ', $pc7g_provider ) : '' ), ! $pc7g_provider );
$pc7g_handoff = $pc7g_hits( '/wpcap_workflow_mail_handoff|handle_mail|hand_off\s*\(/' );
check( 'PC-7 guard: no mail handoff (wpcap_workflow_mail_handoff, handle_mail, hand_off) in src/' . ( $pc7g_handoff ? ' – ' . implode( ', ', $pc7g_handoff ) : '' ), ! $pc7g_handoff );
$pc7g_hooks = $pc7g_hits( "/(?:apply_filters|do_action|has_filter)\(\s*'wpcap_workflow_/" );
check( 'PC-7 guard: the core fires no wpcap_workflow_* hook' . ( $pc7g_hooks ? ' – ' . implode( ', ', $pc7g_hooks ) : '' ), ! $pc7g_hooks );
$pc7g_versions = PassCaptain\Contracts::versions();
check( 'PC-7 guard: no workflow contract version (workflow, workflow_commands) is offered', ! isset( $pc7g_versions['workflow'] ) && ! isset( $pc7g_versions['workflow_commands'] ) && ! array_key_exists( 'workflow', PassCaptain\Suite\Outbox::CONTRACTS ) );
check( 'PC-7 guard: tests/contracts has no workflow fixture', ! file_exists( dirname( __DIR__, 2 ) . '/contracts/workflow.json' ) && ! file_exists( dirname( __DIR__, 2 ) . '/contracts/workflow_commands.json' ) );

// The mailer itself: no plugin lookup, no handoff – every mail goes to wp_mail().
$pc7g_mail_src = implode( "\n", array_intersect_key( $pc7g_src, array_flip( preg_grep( '#^src/Mail/#', array_keys( $pc7g_src ) ) ) ) );
check( 'PC-7 guard: src/Mail/ looks up no provider of a suite contract', ! preg_match( '/Contract::provider|->provider\(|Workflow\\\\|workflow/i', $pc7g_mail_src ) );

final class Pc7g_Mail_Taker {
	public $mails = array();
	public function contract_version() { return '1.0.0'; }
	public function receive( array $envelope ) { return true; }
	public function handle_mail( array $handoff ) { $this->mails[] = $handoff; return true; }
}
$pc7g_filters_before = $GLOBALS['wpcap_test_filters'];
$pc7g_taker = new Pc7g_Mail_Taker();
$GLOBALS['wpcap_test_filters']['wp_pass_captain_workflow_provider_v1'] = $pc7g_taker;
$GLOBALS['wpcap_test_filters']['wpcap_workflow_mail_handoff_types'] = array( 'queue_wpcap_reminder' => 'Reminder' );
update_option( 'wpcap_workflow_mail_handoff', array( 'queue_wpcap_reminder' ) );
$pc7g_before = count( $GLOBALS['wpcap_test_mails'] );
$pc7g_sent = Mailer::deliver( 'pc7-guard@example.com', array( 'type' => 'queue_wpcap_reminder', 'subject' => 'Erinnerung', 'body' => 'Bis morgen', 'format' => 'text', 'locale' => '', 'attachments' => array() ) );
$pc7g_mine = array_filter( array_slice( $GLOBALS['wpcap_test_mails'], $pc7g_before ), static fn( $m ) => 'pc7-guard@example.com' === $m['to'] );
check( 'PC-7 guard: even with a plugin that offers to take mails and the former opt-in set, the reminder is sent by Pass Captain itself', true === $pc7g_sent && 1 === count( $pc7g_mine ) && array() === $pc7g_taker->mails );
delete_option( 'wpcap_workflow_mail_handoff' );
$GLOBALS['wpcap_test_filters'] = $pc7g_filters_before;

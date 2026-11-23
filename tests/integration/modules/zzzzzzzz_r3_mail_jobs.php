<?php
/**
 * R3 (audit round 2, experts #8, #11, #3): e-mail, background jobs and logging.
 *
 * - Throwing job/mail/SMS handlers no longer stall their queue (try/catch, backoff, last_error, log).
 * - Sender settings: From, Reply-To and envelope sender for the plugin's own mails; status hint.
 * - Reschedule and event cancellation mails are queued per recipient (dedupe), not sent in the request.
 * - Occurrence cancellation: mail per recipient through the queue, refund batches of 5 with deadline.
 */

// phpcs:ignoreFile

use PassCaptain\Communications;
use PassCaptain\Jobs;
use PassCaptain\Jobs\TimeBudget;
use PassCaptain\Mail\Mailer;
use PassCaptain\Mail\Suppression;
use PassCaptain\OccurrenceCancellation;
use PassCaptain\Orders;
use PassCaptain\Settings;

if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }

global $wpdb;
$p = $wpdb->prefix;
$r3_jobs = "{$p}wpcap_jobs";
$r3_mq = "{$p}wpcap_message_queue";
$r3_logs = array();
add_action( 'wpcap_log', static function ( $level, $event, $message, $context ) use ( &$r3_logs ) { $r3_logs[] = array( 'event' => $event, 'message' => $message, 'context' => $context ); }, 10, 4 );
$r3_job = static fn( $key ) => $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE idempotency_key=%s', $r3_jobs, hash( 'sha256', $key ) ) );
$r3_msg = static fn( $to ) => $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE recipient=%s ORDER BY message_id DESC LIMIT 1', $r3_mq, $to ) );
// Older suites leave due reminders behind: run the mail worker until the given progress ref is sent.
$r3_drain = static function ( $ref ) { for ( $i = 0; $i < 10 && Communications::progress( $ref )['queued'] > 0; $i++ ) { Communications::process_due(); } };
$r3_future = static fn( $datetime, $min = 30 ) => strtotime( (string) $datetime . ' UTC' ) > time() + $min;

echo "R3 #11: a throwing job handler does not stall the job queue\n";
$r3_poison_key = 'r3-poison-' . uniqid();
$r3_ok_key = 'r3-ok-' . uniqid();
Jobs::enqueue( $r3_poison_key, 'r3_poison', array(), gmdate( 'Y-m-d H:i:s', time() - 120 ) );
Jobs::enqueue( $r3_ok_key, 'r3_ok', array(), gmdate( 'Y-m-d H:i:s', time() - 60 ) );
$r3_handler = static function ( $handled, $type ) {
	if ( 'r3_poison' === $type ) { throw new RuntimeException( 'connector exploded for buyer anna@example.com' ); }
	if ( 'r3_ok' === $type ) { return true; }
	return $handled;
};
add_filter( 'wp_pass_captain_process_job_v1', $r3_handler, 10, 2 );
$r3_threw = false;
try { Jobs::process_due( 200 ); } catch ( \Throwable $e ) { $r3_threw = true; }
$r3_poison = $r3_job( $r3_poison_key );
check( 'process_due() survives the exception and runs the job queued behind it', ! $r3_threw && 'complete' === $r3_job( $r3_ok_key )->status );
check( 'poison job: queued again with backoff (run_after in the future), attempt counted, last_error kept', 'queued' === $r3_poison->status && 1 === (int) $r3_poison->attempts && $r3_future( $r3_poison->run_after ) && false !== strpos( (string) $r3_poison->last_error, 'connector exploded' ) );
check( 'last_error and log entry are redacted (no plain e-mail address)', false === strpos( (string) $r3_poison->last_error, 'anna@example.com' ) && (bool) array_filter( $r3_logs, static fn( $l ) => in_array( $l['event'], array( 'queue_handler_exception', 'job' ), true ) && false !== strpos( $l['message'], 'connector exploded' ) && false === strpos( $l['message'], 'anna@example.com' ) ) ); // Merged Log: channel "job".
check( 'no claim left open after the exception', 0 === TimeBudget::open_claims() );
Jobs::process_due( 200 );
check( 'the poison job is not claimed again before its backoff ends', 1 === (int) $r3_job( $r3_poison_key )->attempts );
remove_filter( 'wp_pass_captain_process_job_v1', $r3_handler, 10 );
$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE job_type IN ('r3_poison','r3_ok')", $r3_jobs ) );

echo "R3 #11: claims released on shutdown wait a backoff and carry a reason\n";
Jobs::enqueue( 'r3-release', 'r3_release', array(), '2000-01-01 00:00:00' );
$r3_rel = $r3_job( 'r3-release' );
$wpdb->query( $wpdb->prepare( "UPDATE %i SET status='running',attempts=2,locked_at=UTC_TIMESTAMP() WHERE job_id=%d", $r3_jobs, (int) $r3_rel->job_id ) );
TimeBudget::claimed( $r3_jobs, 'job_id', (int) $r3_rel->job_id, 'running', 'queued', 'dead_letter', Jobs::MAX_ATTEMPTS, array( 'due' => 'run_after', 'error' => 'last_error' ) );
TimeBudget::release_claims( 'Allowed memory size exhausted while mailing bob@example.com' );
$r3_rel = $r3_job( 'r3-release' );
check( 'released claim: queued, due after a backoff (2 min for attempt 2), redacted reason as last_error', 'queued' === $r3_rel->status && $r3_future( $r3_rel->run_after, 90 ) && false !== strpos( (string) $r3_rel->last_error, 'memory size' ) && false === strpos( (string) $r3_rel->last_error, 'bob@example.com' ) );
check( 'backoff curve: 1, 2, 4 … minutes, capped at one hour', 60 === TimeBudget::backoff( 1 ) && 120 === TimeBudget::backoff( 2 ) && 240 === TimeBudget::backoff( 3 ) && 3600 === TimeBudget::backoff( 20 ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE job_type='r3_release'", $r3_jobs ) );

echo "R3 #11: a throwing mail template does not stall the mail queue\n";
$r3_boom_to = 'r3-boom-' . uniqid() . '@example.com';
$r3_fine_to = 'r3-fine-' . uniqid() . '@example.com';
Communications::enqueue( 'r3-' . $r3_boom_to, $r3_boom_to, 'r3_boom', array( 'subject' => 'S', 'body' => 'B' ), gmdate( 'Y-m-d H:i:s', time() - 120 ) );
Communications::enqueue( 'r3-' . $r3_fine_to, $r3_fine_to, 'r3_fine', array( 'subject' => 'Hallo', 'body' => 'Welt' ), gmdate( 'Y-m-d H:i:s', time() - 60 ) );
// r3_fine is a real (test) template: the merged queue never sends the payload itself as subject/body (A652).
$r3_tpl = static function ( $template, $key, $payload = array() ) { if ( 'r3_boom' === $key ) { throw new LogicException( 'template broken' ); } if ( 'r3_fine' === $key ) { return array( 'subject' => (string) ( $payload['subject'] ?? '' ), 'body' => (string) ( $payload['body'] ?? '' ) ); } return $template; };
add_filter( 'wp_pass_captain_email_template_v1', $r3_tpl, 10, 3 );
$r3_threw = false;
// Earlier modules may leave more due messages than one batch: park them while this check runs.
$r3_parked = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT message_id FROM %i WHERE status='queued' AND recipient NOT IN (%s,%s)", $r3_mq, $r3_boom_to, $r3_fine_to ) ) );
if ( $r3_parked ) { $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='r3_parked' WHERE message_id IN (" . implode( ',', $r3_parked ) . ')', $r3_mq ) ); }
try { Communications::process_due(); } catch ( \Throwable $e ) { $r3_threw = true; }
if ( $r3_parked ) { $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='queued' WHERE message_id IN (" . implode( ',', $r3_parked ) . ')', $r3_mq ) ); }
remove_filter( 'wp_pass_captain_email_template_v1', $r3_tpl, 10 );
check( 'mail queue survives the exception and sends the next message', ! $r3_threw && 'sent' === $r3_msg( $r3_fine_to )->status );
check( 'failing message: queued with backoff and the exception as last_error', 'queued' === $r3_msg( $r3_boom_to )->status && $r3_future( $r3_msg( $r3_boom_to )->send_at ) && false !== strpos( (string) $r3_msg( $r3_boom_to )->last_error, 'template broken' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE template_key IN ('r3_boom','r3_fine')", $r3_mq ) );

echo "R3 #11: a throwing SMS provider does not stall the SMS queue\n";
if ( ! class_exists( 'R3_Boom_Sms_Provider' ) ) {
	class R3_Boom_Sms_Provider implements PassCaptain\Sms\ProviderInterface {
		public function id() { return 'r3boom'; }
		public function title() { return 'R3 boom'; }
		public function is_configured() { return true; }
		public function send( $to, $body, $reference ) { throw new RuntimeException( 'sms gateway exploded' ); }
	}
}
$r3_sms_providers = static function ( $list ) { $list[] = new R3_Boom_Sms_Provider(); return $list; };
add_filter( 'wpcap_sms_providers', $r3_sms_providers );
$r3_sms_before = Settings::get( 'sms' );
Settings::update( array( 'sms' => array_merge( (array) $r3_sms_before, array( 'provider' => 'r3boom', 'verify_phone' => 0 ) ) ) );
$r3_sms_type = make_type( 5, 10, 0 );
$r3_sms_order = Orders::create( array( 'email' => 'r3-sms@example.com', 'items' => array( array( 'ticket_type_id' => $r3_sms_type, 'quantity' => 1 ) ), 'idempotency_key' => 'r3-sms-' . uniqid() ), array( 'skip_rate_limit' => true ) );
PassCaptain\Sms\Service::record_consent( (int) $r3_sms_order['order_id'], '+4915100000777' );
$r3_sms_occ = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT occurrence_id FROM %i WHERE ticket_type_id=%d', "{$p}wpcap_ticket_types", $r3_sms_type ) );
PassCaptain\Sms\Service::enqueue( 'r3-sms-' . uniqid(), (int) $r3_sms_order['order_id'], $r3_sms_occ, 'reminder', 'Hallo', '2000-01-01 00:00:00' );
$r3_threw = false;
try { PassCaptain\Sms\Service::process_due(); } catch ( \Throwable $e ) { $r3_threw = true; }
$r3_sms = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d ORDER BY message_id DESC LIMIT 1', "{$p}wpcap_sms_messages", (int) $r3_sms_order['order_id'] ) );
check( 'SMS worker survives the exception; message queued again with backoff and last_error', ! $r3_threw && $r3_sms && 'queued' === $r3_sms->status && 1 === (int) $r3_sms->attempts && $r3_future( $r3_sms->send_at ) && false !== strpos( (string) $r3_sms->last_error, 'sms gateway exploded' ) );
Settings::update( array( 'sms' => $r3_sms_before ) );
remove_filter( 'wpcap_sms_providers', $r3_sms_providers, 10 );

echo "R3 #8: sender (From, Reply-To, envelope sender)\n";
Settings::update( array( 'mail_from_email' => '', 'mail_from_name' => '', 'mail_reply_to' => '' ) );
$r3_prep = Mailer::prepare( 'x@example.com', array( 'subject' => 'S', 'body' => 'B', 'format' => 'text' ) );
// Merged sender (A296): no From is forced (WordPress or the SMTP plugin decides, SPF/DKIM match); replies go to the
// admin e-mail while no own From address is configured (replies to wordpress@… would be lost).
$r3_admin_reply = '' !== (string) get_option( 'admin_email' ) ? array( 'Reply-To: ' . get_option( 'admin_email' ) ) : array();
check( 'without configuration no From header is forced (WordPress or SMTP plugin decides), Reply-To is the admin e-mail', ! array_filter( $r3_prep['headers'], static fn( $h ) => 0 === stripos( $h, 'From:' ) ) && array_values( array_filter( $r3_prep['headers'], static fn( $h ) => 0 === stripos( $h, 'Reply-To:' ) ) ) === $r3_admin_reply );
check( 'status page warns about the missing sender address', (bool) array_filter( Mailer::sender_issues(), static fn( $i ) => 'warning' === $i['severity'] && false !== strpos( $i['message'], 'Absenderadresse' ) ) );
check( 'settings defaults exist (mail_from_email, mail_from_name, mail_reply_to)', array_key_exists( 'mail_from_email', Settings::defaults() ) && array_key_exists( 'mail_from_name', Settings::defaults() ) && array_key_exists( 'mail_reply_to', Settings::defaults() ) );
check( 'settings page validates the addresses', is_wp_error( PassCaptain\Admin\SettingsPage::sanitize_mail_address( 'kein-mail' ) ) && 'tickets@example.test' === PassCaptain\Admin\SettingsPage::sanitize_mail_address( ' tickets@example.test ' ) && '' === PassCaptain\Admin\SettingsPage::sanitize_mail_address( '' ) );
Settings::update( array( 'mail_from_email' => 'tickets@example.test', 'mail_from_name' => 'Konzerthaus Wien', 'mail_reply_to' => 'support@example.test' ) );
$r3_prep = Mailer::prepare( 'x@example.com', array( 'subject' => 'S', 'body' => '<p>B</p>', 'format' => 'html' ) );
check( 'From and Reply-To headers from the settings', in_array( 'From: Konzerthaus Wien <tickets@example.test>', $r3_prep['headers'], true ) && in_array( 'Reply-To: support@example.test', $r3_prep['headers'], true ) );
$r3_own = Mailer::prepare( 'x@example.com', array( 'subject' => 'S', 'body' => 'B', 'format' => 'text', 'headers' => array( 'From: Other <o@example.test>' ) ) );
check( 'a From header of the message itself is kept (no second From)', 1 === count( array_filter( $r3_own['headers'], static fn( $h ) => 0 === stripos( $h, 'From:' ) ) ) );
$r3_seen = array();
$r3_spy = static function () use ( &$r3_seen ) {
	$mailer = new stdClass(); $mailer->Sender = '';
	Mailer::apply_sender( $mailer );
	$smtp = new stdClass(); $smtp->Sender = 'bounce@smtp-service.example';
	Mailer::apply_sender( $smtp );
	$r3_seen = array( 'sender' => $mailer->Sender, 'smtp' => $smtp->Sender, 'hooked' => ! empty( $GLOBALS['wpcap_test_hooks']['phpmailer_init'][5] ), 'name' => apply_filters( 'wp_mail_from_name', 'WordPress' ) );
};
add_action( 'wpcap_mail_before_send', $r3_spy, 10, 0 );
Mailer::deliver( 'x@example.com', array( 'subject' => 'S', 'body' => 'B', 'format' => 'text' ) );
remove_action( 'wpcap_mail_before_send', $r3_spy, 10 );
check( 'while the plugin sends: envelope sender = From address (phpmailer_init priority 5), From name instead of "WordPress"', 'tickets@example.test' === ( $r3_seen['sender'] ?? '' ) && true === ( $r3_seen['hooked'] ?? false ) && 'Konzerthaus Wien' === ( $r3_seen['name'] ?? '' ) );
check( 'an envelope sender set by an SMTP plugin is not overwritten', 'bounce@smtp-service.example' === ( $r3_seen['smtp'] ?? '' ) );
check( 'after sending the hooks are removed again (no effect on other plugins\' mails)', empty( $GLOBALS['wpcap_test_hooks']['phpmailer_init'][5] ) && 'WordPress' === apply_filters( 'wp_mail_from_name', 'WordPress' ) );
check( 'matching domain: no status warning', array() === Mailer::sender_issues() );
Settings::update( array( 'mail_from_email' => 'tickets@other-domain.org' ) );
check( 'other domain than the site: DMARC/SPF warning', (bool) array_filter( Mailer::sender_issues(), static fn( $i ) => false !== strpos( $i['message'], 'other-domain.org' ) && false !== strpos( $i['message'], 'DKIM' ) ) );
check( 'status issues include the sender hint', (bool) array_filter( PassCaptain\Ops\Status::issues(), static fn( $i ) => false !== strpos( $i['message'], 'other-domain.org' ) ) );
Settings::update( array( 'mail_from_email' => '', 'mail_from_name' => '', 'mail_reply_to' => '' ) );

echo "R3 #8: reschedule mails are queued per recipient\n";
$r3_type = make_type( 20, 10, 0 );
$r3_occ = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT occurrence_id FROM %i WHERE ticket_type_id=%d', "{$p}wpcap_ticket_types", $r3_type ) );
$r3_event = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT event_id FROM %i WHERE occurrence_id=%d', "{$p}wpcap_occurrences", $r3_occ ) );
foreach ( array( 'a', 'b', 'c' ) as $r3_i ) {
	Orders::create( array( 'email' => "r3-res-$r3_i@example.com", 'items' => array( array( 'ticket_type_id' => $r3_type, 'quantity' => 1 ) ), 'idempotency_key' => "r3-res-$r3_i-" . uniqid() ), array( 'skip_rate_limit' => true ) );
}
// Merged line (A428): a reschedule notice is a resumable run (BulkRuns, kind notify): one queued mail per
// holder (template wpcap_bulk_{run}, dedupe per run and recipient, rendered at send time), sent within the
// request's time budget, the rest by the queue worker.
$r3_mails = count( $GLOBALS['wpcap_test_mails'] );
$r3_n = PassCaptain\Notifications::occurrence_rescheduled( $r3_occ, '2031-01-01 18:00:00', '2031-01-08 18:00:00' );
$r3_runs = PassCaptain\BulkRuns::for_object( 'notify', $r3_occ );
$r3_run = $r3_runs[0] ?? null;
$r3_rows = $r3_run ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE template_key=%s', $r3_mq, PassCaptain\BulkRuns::MAIL_PREFIX . (int) $r3_run->run_id ) ) : 0;
check( 'reschedule queues one message per holder and sends nothing in the request', 3 === $r3_n && 1 === count( $r3_runs ) && 3 === $r3_rows );
check( 'dedupe key resched|occ|new start|e-mail: a repeated hook queues nothing twice', 3 === PassCaptain\Notifications::occurrence_rescheduled( $r3_occ, '2031-01-01 18:00:00', '2031-01-08 18:00:00' ) && 1 === count( PassCaptain\BulkRuns::for_object( 'notify', $r3_occ ) ) && 3 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE template_key LIKE %s AND recipient LIKE 'r3-res-%%'", $r3_mq, PassCaptain\BulkRuns::MAIL_PREFIX . '%' ) ) );
check( 'queued row is a typed mail (rendered at send time)', 0 === strpos( (string) $r3_msg( 'r3-res-a@example.com' )->template_key, PassCaptain\BulkRuns::MAIL_PREFIX ) );
$r3_new = array_slice( $GLOBALS['wpcap_test_mails'], $r3_mails );
check( 'the worker sends them: rendered template with the new date', 3 === count( array_filter( $r3_new, static fn( $m ) => str_starts_with( $m['subject'], 'Terminänderung' ) && false !== strpos( $m['body'], '08.01.2031' ) ) ) && $r3_run && 3 === PassCaptain\BulkRuns::mail_counts( $r3_run )['sent'] );
Communications::$inline_seconds = 10;
$r3_mails = count( $GLOBALS['wpcap_test_mails'] );
PassCaptain\Notifications::occurrence_rescheduled( $r3_occ, '2031-01-08 18:00:00', '2031-01-15 18:00:00' );
check( 'with time left the first mails go out right away (inline budget)', 3 === count( array_slice( $GLOBALS['wpcap_test_mails'], $r3_mails ) ) );
$r3_hooked = ! empty( $GLOBALS['wpcap_test_hooks']['wpcap_occurrence_rescheduled'][10] ) && in_array( array( array( PassCaptain\Notifications::class, 'occurrence_rescheduled' ), 3 ), $GLOBALS['wpcap_test_hooks']['wpcap_occurrence_rescheduled'][10], true );
if ( ! $r3_hooked ) { add_action( 'wpcap_occurrence_rescheduled', array( PassCaptain\Notifications::class, 'occurrence_rescheduled' ), 10, 3 ); }
$r3_save = PassCaptain\Admin\EventsPage::save_occurrence( array( 'event_id' => $r3_event, 'occurrence_id' => $r3_occ, 'starts_at' => '2031-02-01T20:00', 'timezone' => 'UTC', 'location' => 'Wien', 'capacity' => 0, 'status' => 'scheduled' ) );
if ( ! $r3_hooked ) { remove_action( 'wpcap_occurrence_rescheduled', array( PassCaptain\Notifications::class, 'occurrence_rescheduled' ), 10 ); }
// Merged admin flow: the notice shows the progress of the occurrence's latest notify run (EventsPage::handle_save_occurrence()).
$r3_last = PassCaptain\BulkRuns::for_object( 'notify', $r3_occ, 1 )[0] ?? null;
check( 'admin result carries the notification progress of the reschedule', ! is_wp_error( $r3_save ) && ! empty( $r3_save['rescheduled'] ) && $r3_last && 3 === PassCaptain\BulkRuns::mail_counts( $r3_last )['total'] );

echo "R3 #8: event cancellation mails are queued per recipient\n";
Communications::$inline_seconds = 0;
$r3_mails = count( $GLOBALS['wpcap_test_mails'] );
$r3_cancel = Orders::cancel_event( $r3_event );
// Merged line (A428): the event cancellation is a resumable run; its event mails are queued per recipient
// first (template wpcap_bulk_{run}, dedupe per run and recipient) and sent by the run / the queue worker.
$r3_crun = ! is_wp_error( $r3_cancel ) ? PassCaptain\BulkRuns::get( (int) ( $r3_cancel['run_id'] ?? 0 ) ) : null;
$r3_ckey = $r3_crun ? PassCaptain\BulkRuns::MAIL_PREFIX . (int) $r3_crun->run_id : '';
$r3_crows = $r3_crun ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE template_key=%s', $r3_mq, $r3_ckey ) ) : 0;
check( 'cancel_event queues one mail per recipient (no SMTP in the request)', ! is_wp_error( $r3_cancel ) && 3 === $r3_cancel['notified_count'] && 3 === $r3_crows );
check( 'result reports the queue progress for the admin notice', $r3_crun && 3 === PassCaptain\BulkRuns::mail_counts( $r3_crun )['total'] && ! empty( $r3_cancel['complete'] ) );
$r3_before_again = count( $GLOBALS['wpcap_test_mails'] );
if ( $r3_crun ) { PassCaptain\BulkRuns::handle_job( false, 'bulk_run', array( 'run_id' => (int) $r3_crun->run_id ), 0 ); }
check( 'dedupe key event-cancel|event|e-mail', 3 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE template_key=%s', $r3_mq, $r3_ckey ) ) && $r3_before_again === count( $GLOBALS['wpcap_test_mails'] ) );
check( 'worker sends the event cancellation mails', 3 === count( array_filter( array_slice( $GLOBALS['wpcap_test_mails'], $r3_mails ), static fn( $m ) => str_starts_with( $m['subject'], 'Eventabsage' ) ) ) && $r3_crun && 3 === PassCaptain\BulkRuns::mail_counts( $r3_crun )['sent'] );
Communications::$inline_seconds = 10;

echo "R3 #8/#11: occurrence cancellation mails per recipient, small refund batches with deadline\n";
check( 'refund batches hold 5 orders', 5 === OccurrenceCancellation::REFUND_BATCH );
$r3_nt = make_type( 20, 10, 0 );
$r3_nocc = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT occurrence_id FROM %i WHERE ticket_type_id=%d', "{$p}wpcap_ticket_types", $r3_nt ) );
$r3_payload = array( 'occurrence_id' => $r3_nocc, 'run' => 'r3run', 'auto_refund' => true, 'recipients' => array( array( 'email' => 'r3-n1@example.com', 'locale' => '' ), array( 'email' => 'r3-n2@example.com', 'locale' => '' ) ) );
$r3_mails = count( $GLOBALS['wpcap_test_mails'] );
check( 'notify batch queues and sends', true === OccurrenceCancellation::handle_job( false, OccurrenceCancellation::JOB_NOTIFY, $r3_payload ) );
check( 'job retry (e.g. after a worker crash) sends nobody twice', true === OccurrenceCancellation::handle_job( false, OccurrenceCancellation::JOB_NOTIFY, $r3_payload ) && 2 === count( array_slice( $GLOBALS['wpcap_test_mails'], $r3_mails ) ) && 2 === Communications::progress( 'occ-cancel|' . $r3_nocc . '|r3run' )['sent'] );
$r3_last = end( $GLOBALS['wpcap_test_mails'] );
check( 'refund note derived at render time from the queued flag', false !== strpos( $r3_last['body'], 'automatisch erstattet' ) );
// Refund batch whose time budget is already used up: one order, the rest becomes a new job.
$r3_ref_prop = new ReflectionProperty( TimeBudget::class, 'deadline' );
$r3_ref_prop->setAccessible( true );
$r3_ref_prop->setValue( null, microtime( true ) - 1 );
$r3_wc = static fn( $id ) => array( 'order_id' => $id, 'provider' => 'woocommerce', 'ticket_ids' => array() );
$r3_ok = OccurrenceCancellation::handle_job( false, OccurrenceCancellation::JOB_REFUNDS, array( 'occurrence_id' => $r3_nocc, 'run' => 'r3run', 'key' => 'r3k', 'orders' => array( $r3_wc( 9100001 ), $r3_wc( 9100002 ), $r3_wc( 9100003 ) ) ), 4242 );
$r3_ref_prop->setValue( null, null );
$r3_rest = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE job_type=%s AND payload LIKE %s ORDER BY job_id DESC LIMIT 1', $r3_jobs, OccurrenceCancellation::JOB_REFUNDS, '%9100002%' ) );
$r3_rest_orders = $r3_rest ? array_column( (array) json_decode( $r3_rest->payload, true )['orders'], 'order_id' ) : array();
check( 'deadline inside the refund job: first order handled, the remaining two queued as a new job', true === $r3_ok && array( 9100002, 9100003 ) === $r3_rest_orders && 'queued' === $r3_rest->status );
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE job_id=%d', $r3_jobs, $r3_rest ? (int) $r3_rest->job_id : 0 ) );

echo "R3 #8: large orders and payment webhooks: ticket mail as an immediate job\n";
require_once dirname( __DIR__ ) . '/lib/fault_wpdb.php';
$r3_delivery_job = static fn( $order_id ) => $GLOBALS['wpdb']->get_row( $GLOBALS['wpdb']->prepare( 'SELECT * FROM %i WHERE job_type=%s AND payload LIKE %s ORDER BY job_id DESC LIMIT 1', $GLOBALS['wpdb']->prefix . 'wpcap_jobs', Orders::DELIVERY_JOB, '{"order_id":' . (int) $order_id . ',%' ) );
$r3_ticket_mails = static fn( $since, $to ) => array_values( array_filter( array_slice( $GLOBALS['wpcap_test_mails'], $since ), static fn( $m ) => $m['to'] === $to && str_starts_with( $m['subject'], 'Deine Tickets' ) ) );
$r3_hashes = static fn( $order_id ) => $GLOBALS['wpdb']->get_col( $GLOBALS['wpdb']->prepare( 'SELECT token_hash FROM %i WHERE order_id=%d ORDER BY ticket_id', $GLOBALS['wpdb']->prefix . 'wpcap_tickets', (int) $order_id ) );
$r3_bt = make_type( 100, 50, 0 );
$r3_mails = count( $GLOBALS['wpcap_test_mails'] );
$r3_big = Orders::create( array( 'email' => 'r3-big@example.com', 'items' => array( array( 'ticket_type_id' => $r3_bt, 'quantity' => 30 ) ), 'idempotency_key' => 'r3-big-' . uniqid() ), array( 'skip_rate_limit' => true ) );
$r3_bjob = $r3_delivery_job( $r3_big['order_id'] );
$r3_bpayload = json_decode( (string) $r3_bjob->payload, true );
$r3_issued_hashes = $r3_hashes( $r3_big['order_id'] );
check( 'order above the threshold (30 > 10 tickets): no ticket mail inside the checkout request', 30 === count( tickets( $r3_big['order_id'] ) ) && array() === $r3_ticket_mails( $r3_mails, 'r3-big@example.com' ) );
check( 'outbox job due right away, marked initial, with the code hashes (no plaintext codes in the payload)', 'queued' === $r3_bjob->status && strtotime( $r3_bjob->run_after . ' UTC' ) <= time() + 1 && 1 === (int) $r3_bpayload['initial'] && 30 === count( $r3_bpayload['hashes'] ) && array_values( $r3_bpayload['hashes'] ) === $r3_issued_hashes );
$r3_list_max = static fn() => 5;
add_filter( 'wp_pass_captain_ticket_mail_list_max_v1', $r3_list_max );
Jobs::process_due( 200 );
remove_filter( 'wp_pass_captain_ticket_mail_list_max_v1', $r3_list_max );
$r3_bmail = $r3_ticket_mails( $r3_mails, 'r3-big@example.com' );
$r3_now_hashes = $r3_hashes( $r3_big['order_id'] );
preg_match_all( '/wpcap_ticket=([a-f0-9]{64})/', $r3_bmail[0]['body'] ?? '', $r3_codes );
check( 'the job sends one ticket mail with all tickets in the PDF attachment', 1 === count( $r3_bmail ) && 1 === count( $r3_bmail[0]['files'] ) && str_starts_with( (string) array_values( $r3_bmail[0]['files'] )[0], '%PDF' ) && 'complete' === $r3_delivery_job( $r3_big['order_id'] )->status );
check( 'mailed codes are new (rotated in the job) and valid; the never-shown issuance codes are dead', ! array_intersect( $r3_issued_hashes, $r3_now_hashes ) && $r3_codes[1] && ! array_diff( array_map( static fn( $c ) => hash( 'sha256', $c ), $r3_codes[1] ), $r3_now_hashes ) );
check( 'ticket list shortened to 5 entries plus a note (PDF holds all 30; Gmail clips ~102 KB)', 5 === substr_count( $r3_bmail[0]['body'] ?? '', 'Ticket-Code:' ) && false !== strpos( $r3_bmail[0]['body'] ?? '', '25 weitere Tickets' ) );
$r3_no_pdf = static fn() => false;
add_filter( 'wpcap_attach_ticket_pdf', $r3_no_pdf );
$r3_mails = count( $GLOBALS['wpcap_test_mails'] );
Orders::create( array( 'email' => 'r3-big2@example.com', 'items' => array( array( 'ticket_type_id' => $r3_bt, 'quantity' => 30 ) ), 'idempotency_key' => 'r3-big2-' . uniqid() ), array( 'skip_rate_limit' => true ) );
Jobs::process_due( 200 );
remove_filter( 'wpcap_attach_ticket_pdf', $r3_no_pdf );
$r3_b2mail = $r3_ticket_mails( $r3_mails, 'r3-big2@example.com' );
check( 'without PDF the list stays complete (the links are the only copy of the codes)', 1 === count( $r3_b2mail ) && 30 === substr_count( $r3_b2mail[0]['body'], 'Ticket-Code:' ) && array() === $r3_b2mail[0]['files'] );
check( 'small orders keep the inline mail (threshold filter wp_pass_captain_ticket_mail_inline_max_v1)', 10 === Orders::INLINE_DELIVERY_MAX );
// Payment webhook context: always deferred, even for one ticket.
$r3_pt = make_type( 10, 10, 1500 );
wpcap_test_reset_transients();
$r3_wo = Orders::create( array( 'email' => 'r3-webhook@example.com', 'items' => array( array( 'ticket_type_id' => $r3_pt, 'quantity' => 1 ) ), 'idempotency_key' => 'r3-wh-' . uniqid() ), array( 'skip_rate_limit' => true ) );
$r3_mails = count( $GLOBALS['wpcap_test_mails'] );
Orders::defer_ticket_mail( static fn() => Orders::confirm_external_payment( $r3_wo['order_id'], 'r3:webhook' ) );
$r3_wjob = $r3_delivery_job( $r3_wo['order_id'] );
check( 'webhook context: tickets issued, mail deferred to a job due now', 1 === count( tickets( $r3_wo['order_id'] ) ) && array() === $r3_ticket_mails( $r3_mails, 'r3-webhook@example.com' ) && $r3_wjob && 'queued' === $r3_wjob->status && 1 === (int) json_decode( $r3_wjob->payload, true )['initial'] );
check( 'the payment webhook handler wraps the event in defer_ticket_mail()', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Payments/Webhooks.php' ), 'Orders::defer_ticket_mail(' ) );
Jobs::process_due( 200 );
check( 'the job delivers the webhook order\'s ticket', 1 === count( $r3_ticket_mails( $r3_mails, 'r3-webhook@example.com' ) ) && 'complete' === $r3_delivery_job( $r3_wo['order_id'] )->status );

echo "R3 #3: delivery retry skips transferred/reassigned tickets\n";
$r3_rt = make_type( 10, 10, 0 );
$GLOBALS['wpcap_test_filters']['wpcap_mail'] = false; // The inline ticket mail fails: the outbox job retries.
$r3_ro = Orders::create( array( 'email' => 'r3-retry@example.com', 'items' => array( array( 'ticket_type_id' => $r3_rt, 'quantity' => 2 ) ), 'idempotency_key' => 'r3-retry-' . uniqid() ), array( 'skip_rate_limit' => true ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_mail'] );
$r3_rjob = $r3_delivery_job( $r3_ro['order_id'] );
$r3_rids = array_map( static fn( $t ) => (int) $t->ticket_id, tickets( $r3_ro['order_id'] ) );
// Ticket 2 was transferred meanwhile: new holder, new code (mailed to the recipient by the transfer).
$r3_transfer_hash = hash( 'sha256', 'r3-transfer-' . uniqid() );
$wpdb->update( "{$p}wpcap_tickets", array( 'attendee_email' => 'r3-friend@example.com', 'token_hash' => $r3_transfer_hash, 'transfer_count' => 1 ), array( 'ticket_id' => $r3_rids[1] ) );
$r3_mails = count( $GLOBALS['wpcap_test_mails'] );
check( 'retry job completes', true === Orders::handle_job( false, Orders::DELIVERY_JOB, json_decode( $r3_rjob->payload, true ) ) );
$r3_rmail = $r3_ticket_mails( $r3_mails, 'r3-retry@example.com' );
check( 'only the untouched ticket is rotated and mailed to the buyer (with PDF); the transferred one keeps the recipient\'s code', 1 === count( $r3_rmail ) && 1 === substr_count( $r3_rmail[0]['body'], 'Ticket-Code:' ) && 1 === count( $r3_rmail[0]['files'] ) && $r3_transfer_hash === $r3_hashes( $r3_ro['order_id'] )[1] );
// Legacy job without hashes: transferred tickets are skipped as well.
$r3_before = $r3_hashes( $r3_ro['order_id'] );
Orders::handle_job( false, Orders::DELIVERY_JOB, array( 'order_id' => (int) $r3_ro['order_id'], 'ticket_ids' => $r3_rids ) );
check( 'legacy retry job (no hashes): transferred ticket skipped, the other one delivered again', $r3_transfer_hash === $r3_hashes( $r3_ro['order_id'] )[1] && $r3_before[0] !== $r3_hashes( $r3_ro['order_id'] )[0] );

echo "R3 #8: resend without row locks during SMTP/wallet; PDF attached; only own tickets\n";
$r3_st = make_type( 10, 10, 0 );
$r3_so = Orders::create( array( 'email' => 'r3-resend@example.com', 'items' => array( array( 'ticket_type_id' => $r3_st, 'quantity' => 3 ) ), 'idempotency_key' => 'r3-resend-' . uniqid() ), array( 'skip_rate_limit' => true ) );
$r3_sids = array_map( static fn( $t ) => (int) $t->ticket_id, tickets( $r3_so['order_id'] ) );
$wpdb->update( "{$p}wpcap_tickets", array( 'attendee_email' => 'r3-guest@example.com' ), array( 'ticket_id' => $r3_sids[2] ) );
$r3_f = wpcap_faults();
$r3_at_mail = array();
$r3_mark = static function () use ( &$r3_at_mail, $r3_f ) { $r3_at_mail[] = count( $r3_f->log ); };
add_action( 'wpcap_mail_before_send', $r3_mark, 10, 0 );
$r3_before = $r3_hashes( $r3_so['order_id'] );
$r3_mails = count( $GLOBALS['wpcap_test_mails'] );
$r3_res = Orders::resend_tickets( $r3_so['order_id'] );
remove_action( 'wpcap_mail_before_send', $r3_mark, 10 );
$r3_log = $r3_f->log;
wpcap_faults_restore();
$wpdb = $GLOBALS['wpdb'];
$r3_first_tx = null;
foreach ( $r3_log as $i => $sql ) { if ( 0 === stripos( ltrim( $sql ), 'START TRANSACTION' ) ) { $r3_first_tx = $i; break; } }
$r3_locks = array_filter( $r3_log, static fn( $sql ) => false !== stripos( $sql, 'wpcap_tickets' ) && false !== stripos( $sql, 'FOR UPDATE' ) );
check( 'resend takes no ticket row lock (no SELECT … FOR UPDATE)', true === $r3_res && array() === $r3_locks );
check( 'the first mail goes out before any transaction starts (prepare → send → short compare-and-set)', $r3_at_mail && null !== $r3_first_tx && $r3_at_mail[0] <= $r3_first_tx );
// Merged line: the code also records whether it was issued with the attendee (code_with_attendee).
check( 'compare-and-set on the previous hash', (bool) array_filter( $r3_log, static fn( $sql ) => (bool) preg_match( "/^UPDATE \\S*wpcap_tickets\\S* SET token_hash='[a-f0-9]{64}'(,[a-z_]+=[0-9]+)* WHERE ticket_id=\\d+ AND status='valid' AND token_hash='[a-f0-9]{64}'/", $sql ) ) );
$r3_smails = array_slice( $GLOBALS['wpcap_test_mails'], $r3_mails );
check( 'resend mails carry the ticket PDF (buyer: 2 tickets, guest: 1)', 2 === count( $r3_smails ) && ! array_filter( $r3_smails, static fn( $m ) => 1 !== count( $m['files'] ) ) );
check( 'every code changed', ! array_intersect( $r3_before, $r3_hashes( $r3_so['order_id'] ) ) );
$r3_before = $r3_hashes( $r3_so['order_id'] );
$r3_mails = count( $GLOBALS['wpcap_test_mails'] );
check( 'option only_unassigned: resend succeeds', true === Orders::resend_tickets( $r3_so['order_id'], array( 'only_unassigned' => true ) ) );
$r3_after = $r3_hashes( $r3_so['order_id'] );
check( 'only the buyer\'s own tickets get new codes; the guest\'s ticket and printout stay valid, no mail to the guest', $r3_before[2] === $r3_after[2] && $r3_before[0] !== $r3_after[0] && $r3_before[1] !== $r3_after[1] && ! array_filter( array_slice( $GLOBALS['wpcap_test_mails'], $r3_mails ), static fn( $m ) => 'r3-guest@example.com' === $m['to'] ) );
$GLOBALS['wpcap_test_filters']['wpcap_mail'] = false;
$r3_before = $r3_hashes( $r3_so['order_id'] );
$r3_fail = Orders::resend_tickets( $r3_so['order_id'] );
unset( $GLOBALS['wpcap_test_filters']['wpcap_mail'] );
check( 'failed mail: nothing stored, every old code stays valid (commit only after the mail)', is_wp_error( $r3_fail ) && 'wpcap_mail_failed' === $r3_fail->get_error_code() && $r3_before === $r3_hashes( $r3_so['order_id'] ) );
$r3_reissue = Orders::reissue_ticket( $r3_sids[0], array( 'reason' => 'r3' ) );
$r3_rm = end( $GLOBALS['wpcap_test_mails'] );
check( 'reissue mails the ticket with its PDF', ! is_wp_error( $r3_reissue ) && $r3_reissue['mailed'] && 1 === count( $r3_rm['files'] ) );
if ( function_exists( 'bloginfo' ) && function_exists( 'esc_url' ) ) { // WordPress stubs of the portal tests (full suite only).
$r3_portal = PassCaptain\OrderPortal::render_page( Orders::get( $r3_so['order_id'] ), str_repeat( 'a', 64 ) );
check( 'portal: warning about tickets passed on and the "only my own tickets" option (checked)', false !== strpos( $r3_portal, 'data-passed-on="1"' ) && false !== strpos( $r3_portal, 'name="only_own" value="1" checked' ) && false !== strpos( $r3_portal, 'an eine andere Person weitergegeben' ) );
}

echo "R3 #11: operational errors reach the log; context redacted; fallback when the audit insert fails\n";
$r3_logs = array();
PassCaptain\Log::error( 'r3_probe_failed', 'probe for anna@example.com', array( 'details' => array( 'token' => 'abc123secret', 'error' => "Duplicate entry 'anna@example.com' for key 'x'" ) ) );
$r3_ctx = end( $r3_logs )['context'] ?? array();
check( 'wpcap_log context is redacted (secret keys masked, e-mail addresses in values removed)', 'abc123secret' !== ( $r3_ctx['details']['token'] ?? '' ) && false === strpos( (string) ( $r3_ctx['details']['error'] ?? '' ), 'anna@example.com' ) && false === strpos( end( $r3_logs )['message'], 'anna@example.com' ) );
$r3_lines = array();
$r3_errlog = static function ( $write, $line ) use ( &$r3_lines ) { $r3_lines[] = $line; return false; };
add_filter( 'wpcap_error_log', $r3_errlog, 10, 2 );
$r3_f = wpcap_faults();
$r3_f->fail( '/^INSERT INTO \S*wpcap_audit_logs/', 0, 1 );
PassCaptain\Log::error( 'r3_db_down_failed', 'probe while the database refuses writes' );
wpcap_faults_restore();
$wpdb = $GLOBALS['wpdb'];
remove_filter( 'wpcap_error_log', $r3_errlog, 10 );
// Merged Log: error-level entries always reach the PHP error log (redacted); the support log table keeps it, too.
check( 'audit insert failed: the entry is written to the PHP error log (redacted) instead of being lost', (bool) array_filter( $r3_lines, static fn( $l ) => false !== strpos( $l, 'r3_db_down_failed' ) && false !== strpos( $l, 'probe while the database refuses writes' ) ) );
$r3_logs = array();
$r3_io = Orders::create( array( 'email' => 'r3-issue@example.com', 'items' => array( array( 'ticket_type_id' => make_type( 5, 5, 0 ), 'quantity' => 1 ) ), 'idempotency_key' => 'r3-issue-' . uniqid() ), array( 'skip_rate_limit' => true ) );
$r3_f = wpcap_faults();
$r3_f->fail( '/^(START TRANSACTION|SET autocommit)/i', 0, 1 );
$r3_issue = Orders::issue_and_email( $r3_io['order_id'] );
wpcap_faults_restore();
$wpdb = $GLOBALS['wpdb'];
check( 'failed ticket issuance goes through Log::error (wpcap_log), not only the audit table', is_wp_error( $r3_issue ) && (bool) array_filter( $r3_logs, static fn( $l ) => 'ticket_issue_failed' === $l['event'] ) );
$r3_src = '';
foreach ( array( 'src/Orders.php', 'src/Payments/Webhooks.php', 'src/Refunds.php', 'src/Integrations/Vouchers/Checkout.php', 'src/Integrations/WooCommerce/OrderAdapter.php' ) as $r3_file ) { $r3_src .= (string) file_get_contents( WPCAP_PATH . $r3_file ); }
check( 'no *_failed / *_db_error event is written with Audit::record alone in orders, webhooks, refunds, vouchers, WooCommerce', ! preg_match( "/Audit::record\\( '[a-z_]*(_failed|_db_error)'/", $r3_src ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE job_type='ticket_issue' AND payload LIKE %s", $r3_jobs, '{"order_id":' . (int) $r3_io['order_id'] . '%' ) );

echo "R3 #11: Action Scheduler continuation only counts pending actions\n";
if ( ! function_exists( 'as_enqueue_async_action' ) ) {
	function as_enqueue_async_action( $hook, $args = array(), $group = '' ) { $GLOBALS['r3_as'][] = array( 'hook' => $hook, 'args' => $args, 'status' => 'pending' ); return count( $GLOBALS['r3_as'] ); }
	// Groups matter: the merged code also looks for actions left in the pre-rename group (LegacySlug).
	function as_has_scheduled_action( $hook, $args = null, $group = '' ) { foreach ( $GLOBALS['r3_as'] ?? array() as $a ) { if ( $a['hook'] === $hook && ( '' === $group || ( $a['group'] ?? 'pass-captain' ) === $group ) && in_array( $a['status'], array( 'pending', 'in-progress' ), true ) ) { return true; } } return false; }
	function as_get_scheduled_actions( $q = array(), $return = 'objects' ) { $ids = array(); foreach ( $GLOBALS['r3_as'] ?? array() as $i => $a ) { if ( $a['hook'] === ( $q['hook'] ?? '' ) && $a['args'] === ( $q['args'] ?? array() ) && $a['status'] === ( $q['status'] ?? '' ) ) { $ids[] = $i + 1; } } return $ids; }
}
$GLOBALS['r3_as'] = array( array( 'hook' => 'wpcap_process_jobs', 'args' => array( 'continue' ), 'status' => 'in-progress' ) );
TimeBudget::continue_later( 'wpcap_process_jobs' );
check( 'the continuation that is running right now does not block queuing the next one', 2 === count( $GLOBALS['r3_as'] ) && 'pending' === $GLOBALS['r3_as'][1]['status'] );
TimeBudget::continue_later( 'wpcap_process_jobs' );
check( 'an already pending continuation is not queued twice', 2 === count( $GLOBALS['r3_as'] ) );

echo "R3 #11: retention continues, rotates its start step and reports a heartbeat\n";
$GLOBALS['r3_as'] = array();
$r3_audit_step = array_search( 'audit_logs', PassCaptain\Privacy\Retention::STEPS, true );
update_option( PassCaptain\Privacy\Retention::START_OPTION, $r3_audit_step );
$r3_ret = PassCaptain\Privacy\Retention::run( null, 0.000001 );
check( 'budget used up: run incomplete, a continuation is queued at once', false === $r3_ret['complete'] && (bool) array_filter( $GLOBALS['r3_as'], static fn( $a ) => PassCaptain\Privacy\Retention::HOOK === $a['hook'] ) );
check( 'the next run starts where this one stopped (step audit_logs), not at the first step again', $r3_audit_step === (int) get_option( PassCaptain\Privacy\Retention::START_OPTION ) );
$r3_ret = PassCaptain\Privacy\Retention::run( 'continue' );
check( 'continuation run (argument "continue", not a timestamp) completes and resets the start step', true === $r3_ret['complete'] && 0 === (int) get_option( PassCaptain\Privacy\Retention::START_OPTION ) );
check( 'heartbeat "retention" recorded and part of the cron health', null !== PassCaptain\Ops\Heartbeat::last( 'retention' ) && 'retention' === PassCaptain\Ops\Status::cron_hooks()[ PassCaptain\Privacy\Retention::HOOK ][2] );

echo "R3 #8: reminder unsubscribe (link + List-Unsubscribe one-click), bounce/complaint webhook\n";
check( 'schema: wpcap_mail_suppressions exists (DB 3.17.2)', version_compare( PassCaptain\Installer::DB_VERSION, '3.17.2', '>=' ) && null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $p . 'wpcap_mail_suppressions' ) ) );
$r3_rem_type = make_type( 10, 10, 0 );
$r3_rem_occ = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT occurrence_id FROM %i WHERE ticket_type_id=%d', "{$p}wpcap_ticket_types", $r3_rem_type ) );
Orders::create( array( 'email' => 'r3-rem@example.com', 'items' => array( array( 'ticket_type_id' => $r3_rem_type, 'quantity' => 1 ) ), 'idempotency_key' => 'r3-rem-' . uniqid() ), array( 'skip_rate_limit' => true ) );
$r3_rem_starts = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT starts_at FROM %i WHERE occurrence_id=%d', "{$p}wpcap_occurrences", $r3_rem_occ ) );
$r3_tpl = PassCaptain\Modules\Reminders::render_template( null, PassCaptain\Modules\Reminders::TEMPLATE, array( 'occurrence_id' => $r3_rem_occ, 'starts_at' => $r3_rem_starts, 'hours' => 24, 'tickets' => 1 ), 'r3-rem@example.com' );
check( 'reminder carries a personal unsubscribe link and List-Unsubscribe + List-Unsubscribe-Post (one-click)', false !== strpos( $r3_tpl['body'], 'wpcap_unsubscribe=' . Suppression::hash( 'r3-rem@example.com' ) ) && in_array( 'List-Unsubscribe-Post: List-Unsubscribe=One-Click', $r3_tpl['headers'], true ) && (bool) preg_grep( '/^List-Unsubscribe: <https:\/\/example\.test\/\?wpcap_unsubscribe=[a-f0-9]{64}&sig=[a-f0-9]{64}>$/', $r3_tpl['headers'] ) );
$r3_hooked = ! empty( array_filter( $GLOBALS['wpcap_test_hooks']['wp_pass_captain_email_template_v1'][10] ?? array(), static fn( $e ) => array( PassCaptain\Modules\Reminders::class, 'render_template' ) === $e[0] ) );
if ( ! $r3_hooked ) { add_filter( 'wp_pass_captain_email_template_v1', array( PassCaptain\Modules\Reminders::class, 'render_template' ), 10, 4 ); }
$r3_hdrs = array();
$r3_cap = static function ( $prepared ) use ( &$r3_hdrs ) { $r3_hdrs = $prepared['headers']; };
add_action( 'wpcap_mail_before_send', $r3_cap, 10, 1 );
$r3_rkey = 'reminder|' . $r3_rem_occ . '|' . $r3_rem_starts . '|24|r3-rem@example.com';
Communications::enqueue( $r3_rkey, 'r3-rem@example.com', PassCaptain\Modules\Reminders::TEMPLATE, array( 'occurrence_id' => $r3_rem_occ, 'starts_at' => $r3_rem_starts, 'hours' => 24, 'tickets' => 1 ) );
Communications::run_now( array( $r3_rkey ), microtime( true ) + 10 );
remove_action( 'wpcap_mail_before_send', $r3_cap, 10 );
check( 'the queue passes the recipient to the template filter and sends the List-Unsubscribe headers', in_array( 'List-Unsubscribe-Post: List-Unsubscribe=One-Click', $r3_hdrs, true ) );
$r3_hash = Suppression::hash( 'r3-rem@example.com' );
check( 'unsubscribe link with a wrong signature is rejected', 404 === Suppression::handle_unsubscribe( $r3_hash, str_repeat( '0', 64 ), true )['status'] && ! Suppression::suppressed( 'r3-rem@example.com' ) );
$r3_url = Suppression::unsubscribe_url( 'r3-rem@example.com' );
parse_str( (string) parse_url( $r3_url, PHP_URL_QUERY ), $r3_q );
$r3_get = Suppression::handle_unsubscribe( $r3_q['wpcap_unsubscribe'], $r3_q['sig'], false );
check( 'GET only shows the confirmation (link scanners do not unsubscribe)', 200 === $r3_get['status'] && ! $r3_get['done'] && ! Suppression::suppressed( 'r3-rem@example.com' ) && false !== strpos( Suppression::page( $r3_q['wpcap_unsubscribe'], $r3_q['sig'], $r3_get ), 'method="post"' ) );
check( 'POST (button or one-click) unsubscribes the address from reminders', Suppression::handle_unsubscribe( $r3_q['wpcap_unsubscribe'], $r3_q['sig'], true )['done'] && Suppression::suppressed( 'r3-rem@example.com' ) && ! PassCaptain\Modules\Reminders::still_valid( 'r3-rem@example.com', array( 'occurrence_id' => $r3_rem_occ, 'starts_at' => $r3_rem_starts ) ) );
check( 'only address hashes are stored', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE email_hash LIKE %s", $p . 'wpcap_mail_suppressions', '%@%' ) ) );
if ( ! $r3_hooked ) { remove_filter( 'wp_pass_captain_email_template_v1', array( PassCaptain\Modules\Reminders::class, 'render_template' ), 10 ); }
// Bounce/complaint webhook.
$r3_breq = static function ( $body, $token = '' ) { $r = new WP_REST_Request( 'POST', '/wp-pass-captain/v1/mail/bounce' ); $r->set_body( wp_json_encode( $body ) ); if ( '' !== $token ) { $r->set_header( 'X-WPCAP-Bounce-Token', $token ); } return $r; };
$r3_bo = Orders::create( array( 'email' => 'r3-bounce@example.com', 'items' => array( array( 'ticket_type_id' => make_type( 5, 5, 0 ), 'quantity' => 1 ) ), 'idempotency_key' => 'r3-bounce-' . uniqid() ), array( 'skip_rate_limit' => true ) );
Communications::enqueue( 'r3-bounce-msg', 'r3-bounce@example.com', 'r3_fine', array( 'subject' => 'S', 'body' => 'B' ) );
Communications::run_now( array( 'r3-bounce-msg' ), microtime( true ) + 10 );
check( 'webhook is off by default', is_wp_error( Suppression::rest_bounce( $r3_breq( array( 'email' => 'r3-bounce@example.com' ), Suppression::token() ) ) ) );
Settings::update( array( 'mail_bounce_webhook' => 1 ) );
$r3_bad = Suppression::rest_bounce( $r3_breq( array( 'email' => 'r3-bounce@example.com' ), 'wrong' ) );
check( 'wrong token: 403', is_wp_error( $r3_bad ) && 403 === $r3_bad->get_error_data()['status'] );
$r3_ok = Suppression::rest_bounce( $r3_breq( array( 'RecordType' => 'Bounce', 'Type' => 'HardBounce', 'Email' => 'r3-bounce@example.com' ), Suppression::token() ) );
$r3_bounce = Suppression::bounce_for_order( $r3_bo['order_id'] );
check( 'Postmark hard bounce: handled, the order shows the bounce, recent queue mails to the address are failed', ! is_wp_error( $r3_ok ) && $r3_bounce && 'bounce' === $r3_bounce['type'] && 'failed' === $r3_msg( 'r3-bounce@example.com' )->status );
check( 'soft bounces and unknown events are ignored', array() === Suppression::parse( array( 'RecordType' => 'Bounce', 'Type' => 'SoftBounce', 'Email' => 'x@example.com' ) ) && array() === Suppression::parse( array( 'event-data' => array( 'event' => 'failed', 'severity' => 'temporary', 'recipient' => 'x@example.com' ) ) ) );
Suppression::rest_bounce( $r3_breq( array( array( 'event' => 'spamreport', 'email' => 'r3-bounce@example.com' ) ), Suppression::token() ) );
check( 'SendGrid spam report: complaint recorded, reminders to the address stop', Suppression::suppressed( 'r3-bounce@example.com' ) && 'complaint' === Suppression::bounce_for_order( $r3_bo['order_id'] )['type'] );
check( 'SES (SNS) and Mailgun payloads are understood', array( array( 'email' => 'a@example.com', 'type' => 'bounce' ) ) === Suppression::parse( array( 'Type' => 'Notification', 'Message' => wp_json_encode( array( 'notificationType' => 'Bounce', 'bounce' => array( 'bounceType' => 'Permanent', 'bouncedRecipients' => array( array( 'emailAddress' => 'a@example.com' ) ) ) ) ) ) ) && array( array( 'email' => 'b@example.com', 'type' => 'complaint' ) ) === Suppression::parse( array( 'event-data' => array( 'event' => 'complained', 'recipient' => 'b@example.com' ) ) ) );
Settings::update( array( 'mail_bounce_webhook' => 0 ) );

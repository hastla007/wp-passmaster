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
use PassCaptain\OccurrenceCancellation;
use PassCaptain\Orders;
use PassCaptain\Settings;

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
check( 'last_error and log entry are redacted (no plain e-mail address)', false === strpos( (string) $r3_poison->last_error, 'anna@example.com' ) && (bool) array_filter( $r3_logs, static fn( $l ) => 'queue_handler_exception' === $l['event'] && false === strpos( $l['message'], 'anna@example.com' ) ) );
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
$r3_tpl = static function ( $template, $key ) { if ( 'r3_boom' === $key ) { throw new LogicException( 'template broken' ); } return $template; };
add_filter( 'wp_pass_captain_email_template_v1', $r3_tpl, 10, 2 );
$r3_threw = false;
try { Communications::process_due(); } catch ( \Throwable $e ) { $r3_threw = true; }
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
check( 'without configuration no From/Reply-To header is forced (WordPress or SMTP plugin decides)', ! array_filter( $r3_prep['headers'], static fn( $h ) => 0 === stripos( $h, 'From:' ) || 0 === stripos( $h, 'Reply-To:' ) ) );
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
Communications::$inline_seconds = 0; // A request without time left: everything stays queued.
$r3_mails = count( $GLOBALS['wpcap_test_mails'] );
$r3_n = PassCaptain\Notifications::occurrence_rescheduled( $r3_occ, '2031-01-01 18:00:00', '2031-01-08 18:00:00' );
$r3_ref = 'resched|' . $r3_occ . '|2031-01-08 18:00:00';
check( 'reschedule queues one message per holder and sends nothing in the request', 3 === $r3_n && $r3_mails === count( $GLOBALS['wpcap_test_mails'] ) && 3 === Communications::progress( $r3_ref )['queued'] );
check( 'dedupe key resched|occ|new start|e-mail: a repeated hook queues nothing twice', 3 === PassCaptain\Notifications::occurrence_rescheduled( $r3_occ, '2031-01-01 18:00:00', '2031-01-08 18:00:00' ) && 3 === array_sum( Communications::progress( $r3_ref ) ) );
check( 'queued row is a typed mail (rendered at send time)', 'mail_occurrence_rescheduled' === $r3_msg( 'r3-res-a@example.com' )->template_key );
$r3_drain( $r3_ref );
$r3_new = array_slice( $GLOBALS['wpcap_test_mails'], $r3_mails );
check( 'the worker sends them: rendered template with the new date', 3 === count( array_filter( $r3_new, static fn( $m ) => str_starts_with( $m['subject'], 'Terminänderung' ) && false !== strpos( $m['body'], '08.01.2031' ) ) ) && 3 === Communications::progress( $r3_ref )['sent'] );
Communications::$inline_seconds = 10;
$r3_mails = count( $GLOBALS['wpcap_test_mails'] );
PassCaptain\Notifications::occurrence_rescheduled( $r3_occ, '2031-01-08 18:00:00', '2031-01-15 18:00:00' );
check( 'with time left the first mails go out right away (inline budget)', 3 === count( array_slice( $GLOBALS['wpcap_test_mails'], $r3_mails ) ) );
$r3_hooked = ! empty( $GLOBALS['wpcap_test_hooks']['wpcap_occurrence_rescheduled'][10] ) && in_array( array( array( PassCaptain\Notifications::class, 'occurrence_rescheduled' ), 3 ), $GLOBALS['wpcap_test_hooks']['wpcap_occurrence_rescheduled'][10], true );
if ( ! $r3_hooked ) { add_action( 'wpcap_occurrence_rescheduled', array( PassCaptain\Notifications::class, 'occurrence_rescheduled' ), 10, 3 ); }
$r3_save = PassCaptain\Admin\EventsPage::save_occurrence( array( 'event_id' => $r3_event, 'occurrence_id' => $r3_occ, 'starts_at' => '2031-02-01T20:00', 'timezone' => 'UTC', 'location' => 'Wien', 'capacity' => 0, 'status' => 'scheduled' ) );
if ( ! $r3_hooked ) { remove_action( 'wpcap_occurrence_rescheduled', array( PassCaptain\Notifications::class, 'occurrence_rescheduled' ), 10 ); }
check( 'admin result carries the notification progress of the reschedule', ! is_wp_error( $r3_save ) && ! empty( $r3_save['rescheduled'] ) && 3 === array_sum( (array) ( $r3_save['notifications'] ?? array() ) ) );

echo "R3 #8: event cancellation mails are queued per recipient\n";
Communications::$inline_seconds = 0;
$r3_mails = count( $GLOBALS['wpcap_test_mails'] );
$r3_cancel = Orders::cancel_event( $r3_event );
$r3_cref = 'event-cancel|' . $r3_event;
check( 'cancel_event queues one mail per recipient (no SMTP in the request)', ! is_wp_error( $r3_cancel ) && 3 === $r3_cancel['notified_count'] && 3 === Communications::progress( $r3_cref )['queued'] && ! array_filter( array_slice( $GLOBALS['wpcap_test_mails'], $r3_mails ), static fn( $m ) => str_starts_with( $m['subject'], 'Eventabsage' ) ) );
check( 'result reports the queue progress for the admin notice', 3 === (int) ( $r3_cancel['notifications']['queued'] ?? 0 ) );
check( 'dedupe key event-cancel|event|e-mail', true === Communications::enqueue_mail( $r3_cref . '|r3-res-a@example.com', 'r3-res-a@example.com', 'event_cancelled', array( 'event_name' => 'X' ), '', $r3_cref ) && 3 === array_sum( Communications::progress( $r3_cref ) ) );
$r3_drain( $r3_cref );
check( 'worker sends the event cancellation mails', 3 === count( array_filter( array_slice( $GLOBALS['wpcap_test_mails'], $r3_mails ), static fn( $m ) => str_starts_with( $m['subject'], 'Eventabsage' ) ) ) && 3 === Communications::progress( $r3_cref )['sent'] );
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
add_filter( 'wpcap_ticket_mail_list_max', $r3_list_max );
Jobs::process_due( 200 );
remove_filter( 'wpcap_ticket_mail_list_max', $r3_list_max );
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
check( 'small orders keep the inline mail (threshold filter wpcap_ticket_mail_inline_max)', 10 === Orders::INLINE_DELIVERY_MAX );
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
check( 'compare-and-set on the previous hash', (bool) array_filter( $r3_log, static fn( $sql ) => (bool) preg_match( "/^UPDATE \\S*wpcap_tickets\\S* SET token_hash='[a-f0-9]{64}' WHERE ticket_id=\\d+ AND status='valid' AND token_hash='[a-f0-9]{64}'/", $sql ) ) );
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

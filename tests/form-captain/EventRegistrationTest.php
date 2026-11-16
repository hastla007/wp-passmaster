<?php
/**
 * PASS-FC-01..04 against the real Form Captain: purpose registration, signing only for the form
 * assigned to a published event, submission with verified context, tampered event ID
 * (context_signature_invalid), cancelled event (context_ref_rejected), event card output, and no
 * reservation, payment or ticket from a submission.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use FormCaptain\Plugin as FcPlugin;
use FormCaptain\Repository\FormRepository;
use FormCaptain\Security\SignedContext;
use PassCaptain\Integrations\FormCaptain\Adapter;
use PassCaptain\Integrations\FormCaptain\EventForms;
use PassCaptain\Integrations\FormCaptain\Connector;
use PassCaptain\Integrations\FormCaptain\EventRequests;
use FormCaptain\Integrations\ConnectorRegistry;
use FormCaptain\Jobs\JobRunner;
use FormCaptain\Repository\JobRepository;

final class EventRegistrationTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		EventForms::flush_cache();
		delete_option( ConnectorRegistry::OPTION );
		$this->registry()->reset();
		$this->registry()->reload();
	}

	private function registry(): ConnectorRegistry {
		return FcPlugin::instance()->get( ConnectorRegistry::class );
	}

	/** Form with the hand-over action, event with that form, submission with verified context; returns [form, event, entry, job]. */
	private function handover( bool $activate ): array {
		if ( $activate ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
			$this->assertTrue( $this->registry()->activate( Connector::KEY ) );
			wp_set_current_user( 0 );
		}
		$form  = $this->form( 'published', array( 'actions' => array( array( 'instance_id' => 'a_0123456789', 'action_key' => Connector::ACTION, 'enabled' => true, 'settings' => array(), 'mapping' => array( 'name' => 'f_name01', 'email' => 'f_mail01' ) ) ) ) );
		$event = $this->event();
		$this->assign( $event, $form );
		$signed = form_captain_sign_context_v1( Adapter::PURPOSE, (int) $form['id'], 'event', (string) $event );
		$result = self::submit( $form, $signed['context'] );
		$this->assertTrue( $result['success'], (string) wp_json_encode( $result ) );
		$job = FcPlugin::instance()->get( JobRepository::class )->find_by_key( sprintf( 'form-captain:%d:%d:%s:%s', get_current_blog_id(), $result['entry_id'], Connector::ACTION, 'a_0123456789' ) );
		$this->assertNotNull( $job, 'Form Captain planned the action job' );
		return array( $form, $event, (int) $result['entry_id'], $job );
	}

	private function form( string $status = 'published', array $settings = array() ): array {
		$repo   = FcPlugin::instance()->get( FormRepository::class );
		$schema = array(
			'schema_version' => 1,
			'fields'         => array(
				array( 'id' => 'f_name01', 'type' => 'text', 'key' => 'name', 'label' => 'Name', 'required' => true ),
				array( 'id' => 'f_mail01', 'type' => 'email', 'key' => 'email', 'label' => 'E-Mail', 'required' => true ),
			),
		);
		$id = $repo->create( 'Eventanfrage', $schema, array( 'confirmation' => array( 'type' => 'message', 'message' => 'Danke' ) ) + $settings );
		if ( 'published' === $status ) {
			$repo->publish_version( $id, $schema );
		}
		return $repo->find( $id );
	}

	private function event( string $status = 'published' ): int {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert( "{$wpdb->prefix}wpcap_events", array( 'name' => 'Konzert', 'slug' => 'fc-' . uniqid(), 'description' => '', 'status' => $status, 'created_at' => $now, 'updated_at' => $now ) );
		$event = (int) $wpdb->insert_id;
		$wpdb->insert( "{$wpdb->prefix}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => 0, 'status' => 'scheduled' ) );
		return $event;
	}

	private function assign( int $event_id, array $form ): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$result = EventForms::save( array( 'event_id' => $event_id, 'form' => (string) $form['public_id'] ) );
		wp_set_current_user( 0 );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
	}

	private static function submit( array $form, string $ctx ): array {
		return form_captain_submit_v1( $form['id'], array( 'name' => 'Ada', 'email' => 'ada@example.org' ), array( 'source' => 'pass-test', 'idempotency_key' => uniqid( 'fc', true ), 'signed_context' => $ctx ) );
	}

	private static function counts(): array {
		global $wpdb;
		$out = array();
		foreach ( array( 'wpcap_orders', 'wpcap_reservations', 'wpcap_tickets' ) as $table ) {
			$out[ $table ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}{$table}" );
		}
		return $out;
	}

	public function test_purpose_is_registered_after_form_captain_loaded(): void {
		$this->assertTrue( Adapter::ready(), 'state: ' . Adapter::state() . ' ' . Adapter::error_code() );
		$purposes = FcPlugin::instance()->get( SignedContext::class )->purposes();
		$this->assertArrayHasKey( Adapter::PURPOSE, $purposes );
		$this->assertSame( array( 'public_endpoint' ), $purposes[ Adapter::PURPOSE ]['issuance'] );
		$this->assertSame( array( 'event' ), $purposes[ Adapter::PURPOSE ]['ref_types'] );
		$this->assertArrayNotHasKey( Adapter::PURPOSE, FcPlugin::instance()->get( SignedContext::class )->diagnostics() );
	}

	public function test_only_published_forms_can_be_assigned(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$result = EventForms::save( array( 'event_id' => $this->event(), 'form' => (string) $this->form( 'draft' )['id'] ) );
		$this->assertWPError( $result );
		$this->assertSame( 'wpcap_form_not_published', $result->get_error_code() );
	}

	public function test_submission_with_verified_event_context_books_nothing(): void {
		$form  = $this->form();
		$event = $this->event();
		$this->assertSame( 'context_purpose_not_allowed', form_captain_sign_context_v1( Adapter::PURPOSE, (int) $form['id'], 'event', (string) $event )['code'], 'Unassigned form does not accept the purpose' );
		$this->assign( $event, $form );

		$signed = form_captain_sign_context_v1( Adapter::PURPOSE, (int) $form['id'], 'event', (string) $event );
		$this->assertTrue( $signed['success'], (string) wp_json_encode( $signed ) );

		$entry_events = array();
		$listener     = static function ( $payload ) use ( &$entry_events ) { $entry_events[] = $payload; };
		add_action( 'form_captain_entry_created_v1', $listener );
		$before = self::counts();
		$result = self::submit( $form, $signed['context'] );
		remove_action( 'form_captain_entry_created_v1', $listener );

		$this->assertTrue( $result['success'], (string) wp_json_encode( $result ) );
		$this->assertSame( 'created', $result['code'] );
		$this->assertSame( (string) $event, $entry_events[0]['context']['ref_id'] ?? null );
		$this->assertSame( 'event', $entry_events[0]['context']['ref_type'] ?? null );
		$this->assertSame( $before, self::counts(), 'No reservation, order/payment or ticket from a form submission' );
	}

	public function test_tampered_event_id_is_rejected(): void {
		$form   = $this->form();
		$event  = $this->event();
		$victim = $this->event();
		$this->assign( $event, $form );
		$this->assign( $victim, $form );
		$signed = form_captain_sign_context_v1( Adapter::PURPOSE, (int) $form['id'], 'event', (string) $event );
		$this->assertTrue( $signed['success'] );

		list( $segment, $sig ) = explode( '.', $signed['context'] );
		$payload               = json_decode( base64_decode( strtr( $segment, '-_', '+/' ) ), true );
		$payload['ref_id']     = (string) $victim;
		$forged                = rtrim( strtr( base64_encode( wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ), '+/', '-_' ), '=' ) . '.' . $sig;

		$result = self::submit( $form, $forged );
		$this->assertFalse( $result['success'] );
		$this->assertSame( 'invalid_context', $result['code'] );
		$this->assertSame( 'context_signature_invalid', $result['detail_code'] ?? null );
	}

	public function test_cancelled_event_is_rejected(): void {
		global $wpdb;
		$form  = $this->form();
		$event = $this->event();
		$this->assign( $event, $form );
		$signed = form_captain_sign_context_v1( Adapter::PURPOSE, (int) $form['id'], 'event', (string) $event );
		$this->assertTrue( $signed['success'] );

		$wpdb->update( "{$wpdb->prefix}wpcap_events", array( 'status' => 'cancelled' ), array( 'event_id' => $event ) );
		$result = self::submit( $form, $signed['context'] );
		$this->assertFalse( $result['success'] );
		$this->assertSame( 'context_ref_rejected', $result['detail_code'] ?? null );
		$this->assertSame( 'context_ref_rejected', form_captain_sign_context_v1( Adapter::PURPOSE, (int) $form['id'], 'event', (string) $event )['code'] );
		$this->assertSame( '', Adapter::render_for_event( $event ) );
	}

	public function test_event_card_shows_the_form_with_context(): void {
		$form  = $this->form();
		$event = $this->event();
		$plain = $this->event();
		$this->assign( $event, $form );
		$card = '<article class="wpcap-event"><form class="wpcap-checkout-form"></form></article>';

		$html = apply_filters( 'wp_pass_captain_event_card_html', $card, (object) array( 'event_id' => $event, 'occurrence_id' => 1 ), array() );
		$this->assertStringContainsString( 'data-fc-form="' . $form['public_id'] . '"', $html );
		$this->assertStringContainsString( 'name="_fc_ctx"', $html );
		$this->assertStringEndsWith( '</section></article>', $html );
		$this->assertStringNotContainsString( 'wpcap-event-form', apply_filters( 'wp_pass_captain_event_card_html', $card, (object) array( 'event_id' => $plain, 'occurrence_id' => 2 ), array() ) );

		preg_match( '/name="_fc_ctx" value="([^"]+)"/', $html, $m );
		$this->assertTrue( self::submit( $form, html_entity_decode( $m[1] ) )['success'], 'Context embedded in the page is accepted' );
	}

	public function test_connector_is_registered_disabled_until_opt_in(): void {
		$this->assertSame( ConnectorRegistry::STATE_DETECTED, $this->registry()->state( Connector::KEY ) );
		$this->assertArrayHasKey( Connector::ACTION, $this->registry()->actions() );
		list( , $event, , $job ) = $this->handover( false );
		$this->assertSame( 'paused', FcPlugin::instance()->get( JobRunner::class )->run_one( (int) $job['id'] ) );
		$this->assertSame( 0, EventRequests::count_for_event( $event ) );
	}

	public function test_handover_creates_one_request_to_review_and_books_nothing(): void {
		$before                         = self::counts();
		list( , $event, $entry, $job ) = $this->handover( true );
		$this->assertSame( 'succeeded', FcPlugin::instance()->get( JobRunner::class )->run_one( (int) $job['id'] ) );
		$jobs   = FcPlugin::instance()->get( JobRepository::class );
		$remote = $jobs->find( (int) $job['id'] )['result']['remote_id'];
		$rows   = EventRequests::for_event( $event );
		$this->assertCount( 1, $rows );
		$this->assertSame( EventRequests::remote_id( (int) $rows[0]->request_id ), $remote );
		$this->assertSame( 'review', $rows[0]->status );
		$this->assertSame( 'Ada', $rows[0]->name );
		$this->assertSame( 'ada@example.org', $rows[0]->email );
		$this->assertSame( $entry, (int) $rows[0]->entry_id );
		$this->assertSame( $before, self::counts(), 'No reservation, order/payment or ticket from the hand-over' );

		// Repeated delivery of the same job: same request, nothing new.
		$again = $this->registry()->handle_job( $jobs->find( (int) $job['id'] ) );
		$this->assertSame( 'success', $again->status );
		$this->assertSame( $remote, $again->data['remote_id'] );
		$this->assertSame( 1, EventRequests::count_for_event( $event ) );
	}

	public function test_consent_withdrawal_removes_the_open_request(): void {
		list( , $event, $entry, $job ) = $this->handover( true );
		$this->assertSame( 'succeeded', FcPlugin::instance()->get( JobRunner::class )->run_one( (int) $job['id'] ) );
		FcPlugin::instance()->get( \FormCaptain\Service\ConsentService::class )->withdraw( $entry, 'admin', 'keep' );
		$this->assertSame( 0, EventRequests::count_for_event( $event ) );
	}

	public function test_entry_deletion_removes_the_open_request(): void {
		list( , $event, $entry, $job ) = $this->handover( true );
		$this->assertSame( 'succeeded', FcPlugin::instance()->get( JobRunner::class )->run_one( (int) $job['id'] ) );
		$this->assertTrue( FcPlugin::instance()->get( \FormCaptain\Service\EntryDeletionService::class )->delete( $entry, 'erasure' ) );
		$this->assertSame( 0, EventRequests::count_for_event( $event ) );
	}
}

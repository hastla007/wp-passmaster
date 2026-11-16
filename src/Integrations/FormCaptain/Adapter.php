<?php
/**
 * Optional adapter for Form Captain (public contract v1, signed suite context).
 *
 * Lets an event show a Form Captain form (event enquiries, applications, voluntary extra details)
 * with a server-verified reference to the event:
 *
 * - registers the context purpose `pass.event_registration` (ref type `event`, issuance
 *   `public_endpoint` because event pages are usually cached) once `form_captain_loaded_v1` fired,
 * - accepts that purpose for the forms assigned to an event (filter
 *   `form_captain_accepted_context_purposes_v1`), so nobody has to edit the form settings,
 * - renders the assigned form below the event card through `form_captain_get_render_v1()`.
 *
 * A submission is never a booking: this adapter registers no form action and no listener on
 * Form Captain entry events, so it never creates a reservation, payment or ticket. Uses only the
 * public Form Captain functions and hooks; never reads Form Captain tables. Without Form Captain or
 * with another contract major version the adapter stays inactive.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Integrations\FormCaptain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Pass Captain's own tables; results are cached per request where repeated.

/**
 * Context purpose registration, reference checks and event page output.
 */
final class Adapter {
	const PURPOSE        = 'pass.event_registration';
	const OWNER          = 'pass';
	const REF_TYPE       = 'event';
	const CONTRACT       = '^1.0';
	const CONTRACT_MAJOR = '1';
	const LOADED_HOOK    = 'form_captain_loaded_v1';
	const DEFAULT_TTL    = 12 * HOUR_IN_SECONDS;
	const MAX_TTL        = DAY_IN_SECONDS;

	/**
	 * Adapter state: 'missing' (Form Captain inactive or not loaded yet), 'incompatible',
	 * 'error' (registration refused) or 'ready'.
	 *
	 * @var string
	 */
	private static $state = 'missing';

	/**
	 * Error code of a refused purpose registration (diagnostics only).
	 *
	 * @var string
	 */
	private static $error = '';

	/**
	 * Event IDs whose form was already rendered in this request (one form per event and page).
	 *
	 * @var array<int,bool>
	 */
	private static $rendered = array();

	/** Hooks; cheap, safe without Form Captain. */
	public static function register() {
		if ( did_action( self::LOADED_HOOK ) ) {
			self::on_loaded();
		} else {
			add_action( self::LOADED_HOOK, array( __CLASS__, 'on_loaded' ), 10, 0 );
		}
		add_filter( 'form_captain_accepted_context_purposes_v1', array( __CLASS__, 'accepted_purposes' ), 10, 2 );
		add_filter( 'wp_pass_captain_event_card_html', array( __CLASS__, 'append_to_card' ), 20, 3 );
		add_action( 'wpcap_admin_event_sections', array( EventForms::class, 'render_section' ), 10, 2 );
	}

	/** Form Captain contract functions are present (plugin active). */
	public static function present() {
		return function_exists( 'form_captain_contract_version' ) && function_exists( 'form_captain_register_context_purpose_v1' );
	}

	/** Form Captain is present with a contract of the supported major version. */
	public static function compatible() {
		return self::present() && strtok( (string) form_captain_contract_version(), '.' ) === self::CONTRACT_MAJOR;
	}

	/** True once the purpose is registered and forms can be rendered with context. */
	public static function ready() {
		return 'ready' === self::$state;
	}

	/** Current state for admin diagnostics: missing, incompatible, error or ready. */
	public static function state() {
		if ( 'ready' !== self::$state && 'error' !== self::$state ) {
			if ( ! self::present() ) {
				return 'missing';
			}
			if ( ! self::compatible() ) {
				return 'incompatible';
			}
		}
		return self::$state;
	}

	/** Error code of a refused registration. */
	public static function error_code() {
		return self::$error;
	}

	/** Runs on `form_captain_loaded_v1`: registers the context purpose when the contract fits. */
	public static function on_loaded() {
		if ( 'ready' === self::$state ) {
			return;
		}
		if ( ! self::present() ) {
			self::$state = 'missing';
			return;
		}
		if ( ! self::compatible() ) {
			self::$state = 'incompatible';
			return;
		}
		// The translated label must not load translations before init (form_captain_loaded_v1 fires
		// on plugins_loaded); registering later than the loaded hook is allowed by the contract.
		if ( ! did_action( 'init' ) ) {
			add_action( 'init', array( __CLASS__, 'register_purpose' ), 1, 0 );
			return;
		}
		self::register_purpose();
	}

	/** Registers the context purpose with Form Captain (once). */
	public static function register_purpose() {
		if ( 'ready' === self::$state || ! self::compatible() ) {
			return;
		}
		$result = form_captain_register_context_purpose_v1( self::PURPOSE, self::definition() );
		if ( is_wp_error( $result ) ) {
			self::$state = 'error';
			self::$error = (string) $result->get_error_code();
			return;
		}
		self::$state = 'ready';
		self::$error = '';
	}

	/**
	 * Purpose definition (Form Captain spec suite-kontext.md 3.1/3.2). Not single-use, because the
	 * public_endpoint issuance needs reusable contexts; marks_verified is allowed because the same
	 * check runs again on submission.
	 *
	 * @return array<string,mixed>
	 */
	public static function definition() {
		return array(
			'owner'          => self::OWNER,
			'contract'       => self::CONTRACT,
			'label'          => __( 'Pass Captain: Anfrage/Anmeldung zu einem Event', 'pass-captain' ),
			'ref_types'      => array( self::REF_TYPE ),
			'default_ttl'    => self::DEFAULT_TTL,
			'max_ttl'        => self::MAX_TTL,
			'single_use'     => false,
			'issuance'       => 'public_endpoint',
			'issue_check'    => array( __CLASS__, 'check_reference' ),
			'submit_check'   => array( __CLASS__, 'check_reference' ),
			'marks_verified' => true,
		);
	}

	/**
	 * Issue and submit check: the event exists, is published (not a draft, not cancelled), still has
	 * a date that is not cancelled, and the form is the one assigned to this event.
	 *
	 * @param string $ref_type Reference type from the context.
	 * @param string $ref_id   Event ID as string.
	 * @param int    $form_id  Internal Form Captain form ID.
	 */
	public static function check_reference( $ref_type, $ref_id, $form_id ) {
		if ( self::REF_TYPE !== $ref_type || ! is_string( $ref_id ) || ! ctype_digit( $ref_id ) ) {
			return false;
		}
		$event_id = absint( $ref_id );
		if ( ! $event_id || ! self::event_open( $event_id ) ) {
			return false;
		}
		$assigned = EventForms::get( $event_id );
		return null !== $assigned && (int) $assigned['form_id'] === (int) $form_id;
	}

	/**
	 * Event is published and has at least one date that is not cancelled.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function event_open( $event_id ) {
		global $wpdb;
		$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}wpcap_events WHERE event_id=%d", absint( $event_id ) ) );
		if ( 'published' !== $status ) {
			return false;
		}
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->prefix}wpcap_occurrences WHERE event_id=%d AND status<>'cancelled' LIMIT 1", absint( $event_id ) ) );
	}

	/**
	 * Accepts the purpose for forms that are assigned to at least one event. Only the purpose owner
	 * adds its own purpose; other purposes stay untouched.
	 *
	 * @param mixed $purposes Purposes from the form settings.
	 * @param mixed $form_id  Internal Form Captain form ID.
	 * @return mixed
	 */
	public static function accepted_purposes( $purposes, $form_id = 0 ) {
		if ( ! is_array( $purposes ) || ! self::ready() || in_array( self::PURPOSE, $purposes, true ) ) {
			return $purposes;
		}
		if ( EventForms::is_assigned( absint( $form_id ) ) ) {
			$purposes[] = self::PURPOSE;
		}
		return $purposes;
	}

	/**
	 * Adds the assigned form to the event card (inside the card, after the checkout form, never
	 * nested in it). Once per event and page, even if the event has several dates.
	 *
	 * @param mixed  $html  Card HTML.
	 * @param object $event Event row of the card (event_id, occurrence_id, …).
	 * @param mixed  $types Ticket types (unused).
	 * @return mixed
	 */
	public static function append_to_card( $html, $event = null, $types = null ) {
		unset( $types );
		if ( ! is_string( $html ) || ! is_object( $event ) || empty( $event->event_id ) ) {
			return $html;
		}
		$form = self::render_for_event( (int) $event->event_id );
		if ( '' === $form ) {
			return $html;
		}
		$pos = strripos( $html, '</article>' );
		return false === $pos ? $html . $form : substr( $html, 0, $pos ) . $form . substr( $html, $pos );
	}

	/**
	 * HTML of the form assigned to an event, or '' (no assignment, Form Captain inactive, event not
	 * open, already rendered in this request).
	 *
	 * The form gets the reference `event:<id>` as `context_ref` (Form Captain fetches a fresh
	 * context through its no-store token route, so cached pages stay valid) and, as fallback for
	 * renderers without `context_ref` and for visitors without JavaScript, a context signed now with
	 * the maximum lifetime. The signed value is not personal and not single-use, so a cached copy
	 * may be shared; after expiry Form Captain asks the visitor to send the form again.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function render_for_event( $event_id ) {
		$event_id = absint( $event_id );
		if ( ! $event_id || isset( self::$rendered[ $event_id ] ) || ! self::ready() || ! function_exists( 'form_captain_get_render_v1' ) ) {
			return '';
		}
		$assigned = EventForms::get( $event_id );
		if ( null === $assigned || ! self::event_open( $event_id ) ) {
			return '';
		}
		$ref    = (string) $event_id;
		$args   = array(
			'context_ref' => array(
				'purpose'  => self::PURPOSE,
				'ref_type' => self::REF_TYPE,
				'ref_id'   => $ref,
			),
		);
		$signed = function_exists( 'form_captain_sign_context_v1' ) ? form_captain_sign_context_v1( self::PURPOSE, (int) $assigned['form_id'], self::REF_TYPE, $ref, self::MAX_TTL ) : array();
		if ( is_array( $signed ) && ! empty( $signed['success'] ) && is_string( $signed['context'] ?? null ) ) {
			$args['context'] = $signed['context'];
		}
		$html = (string) form_captain_get_render_v1( (int) $assigned['form_id'], $args );
		if ( '' === trim( $html ) ) {
			return '';
		}
		self::$rendered[ $event_id ] = true;
		// Form Captain escapes its own markup (contract: HTML for embedding).
		return '<section class="wpcap-event-form" aria-label="' . esc_attr__( 'Formular zum Event', 'pass-captain' ) . '">' . $html . '</section>';
	}

	/** Test helper: forgets request state (rendered events, registration). */
	public static function reset() {
		self::$state    = 'missing';
		self::$error    = '';
		self::$rendered = array();
		EventForms::flush_cache();
	}
}

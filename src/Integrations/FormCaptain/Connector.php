<?php
/**
 * Form Captain connector "pass_captain" with the form action `pass_captain.event_registration`
 * (PASS-FC-05). Loaded only when Form Captain's ConnectorInterface exists.
 *
 * Opt-in twice: Form Captain lists the connector as disabled until an administrator enables it
 * (Integrations), and the action runs only for forms where it was added in the form editor with an
 * explicit field mapping. Only non-sensitive fields can be mapped. Form Captain runs the action in
 * its worker after the entry was saved, with the idempotency key
 * `form-captain:{site}:{entry}:{action}:{instance}`; the callback stores one request per key.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Integrations\FormCaptain;

use FormCaptain\Integrations\ConnectorInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pass Captain connector for Form Captain.
 */
final class Connector implements ConnectorInterface {
	const KEY    = 'pass_captain';
	const ACTION = 'pass_captain.event_registration';

	/**
	 * Filter `form_captain_connectors_v1`: adds this connector without replacing existing keys.
	 *
	 * @param mixed $connectors Registered connectors.
	 * @return mixed
	 */
	public static function add( $connectors ) {
		if ( ! is_array( $connectors ) ) {
			return $connectors;
		}
		return $connectors + array( self::KEY => new self() );
	}

	/** Stable connector key. */
	public function key(): string {
		return self::KEY;
	}

	/** Label in Form Captain → Integrations. */
	public function label(): string {
		return __( 'Pass Captain (Event-Anfragen)', 'pass-captain' );
	}

	/** Plugin version. */
	public function version(): string {
		return defined( 'WPCAP_VERSION' ) ? (string) WPCAP_VERSION : '';
	}

	/** Supported contract. */
	public function contract(): string {
		return '1.0';
	}

	/**
	 * Action keys.
	 *
	 * @return string[]
	 */
	public function capabilities(): array {
		return array( self::ACTION );
	}

	/**
	 * Requirements: the context purpose must be registered.
	 *
	 * @return array{ok:bool,messages:string[]}
	 */
	public function requirements(): array {
		$ok = Adapter::ready();
		return array(
			'ok'       => $ok,
			'messages' => array(
				$ok
					? __( 'Legt zu jeder Einsendung mit geprüftem Eventbezug eine Anfrage „zu prüfen“ im Event an. Es entstehen keine Reservierung, keine Zahlung und kein Ticket.', 'pass-captain' )
					: __( 'Der Kontextzweck für Eventformulare ist nicht registriert.', 'pass-captain' ),
			),
		);
	}

	/**
	 * Global settings (none).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function settings_schema(): array {
		return array();
	}

	/** Action definition (see Form Captain docs/CONNECTORS.md). */
	public static function definition() {
		return array(
			'label'     => __( 'Anfrage an Pass Captain übergeben', 'pass-captain' ),
			'version'   => '1.0.0',
			'contract'  => '^1.0',
			'connector' => self::KEY,
			'settings'  => array(),
			'mapping'   => array(
				array(
					'key'      => 'name',
					'label'    => __( 'Name', 'pass-captain' ),
					'types'    => array( 'text' ),
					'required' => true,
				),
				array(
					'key'   => 'email',
					'label' => __( 'E-Mail', 'pass-captain' ),
					'types' => array( 'email' ),
				),
				array(
					'key'   => 'phone',
					'label' => __( 'Telefon', 'pass-captain' ),
					'types' => array( 'tel', 'text' ),
				),
				array(
					'key'   => 'message',
					'label' => __( 'Nachricht', 'pass-captain' ),
					'types' => array( 'textarea', 'text' ),
				),
			),
			'events'    => array( 'entry_created' ),
		);
	}

	/** Registers the form action. */
	public function register_actions(): void {
		form_captain_register_action_v1( self::ACTION, self::definition(), array( EventRequests::class, 'handle_job' ) );
	}
}

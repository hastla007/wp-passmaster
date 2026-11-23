<?php
/**
 * PHPStan stubs for the optional Form Captain plugin (contract 1.x). Only for static analysis; the
 * adapter checks function_exists()/interface_exists() at runtime (src/Integrations/FormCaptain).
 */

namespace FormCaptain\Integrations {
	interface ConnectorInterface {
		public function key(): string;
		public function label(): string;
		public function version(): string;
		public function contract(): string;
		/** @return array<int|string,mixed> */
		public function capabilities(): array;
		/** @return array<int|string,mixed> */
		public function requirements(): array;
		/** @return array<int|string,mixed> */
		public function settings_schema(): array;
		public function register_actions(): void;
	}
}

namespace {
	/** @return string */
	function form_captain_contract_version() { return ''; }
	/**
	 * @param int|string $form Form ID or public ID.
	 * @return array<string,mixed>|null|\WP_Error
	 */
	function form_captain_get_form_v1( $form ) { return null; }
	/**
	 * @param int                 $form_id
	 * @param array<string,mixed> $args
	 * @return string|\WP_Error
	 */
	function form_captain_get_render_v1( $form_id, $args = array() ) { return ''; }
	/**
	 * @param string              $action
	 * @param array<string,mixed> $definition
	 * @param callable            $handler
	 * @return bool|\WP_Error
	 */
	function form_captain_register_action_v1( $action, $definition, $handler ) { return true; }
	/**
	 * @param string              $purpose
	 * @param array<string,mixed> $definition
	 * @return bool|\WP_Error
	 */
	function form_captain_register_context_purpose_v1( $purpose, $definition ) { return true; }
	/**
	 * @param string $purpose
	 * @param int    $form_id
	 * @param string $ref_type
	 * @param mixed  ...$args
	 * @return string|\WP_Error
	 */
	function form_captain_sign_context_v1( $purpose, $form_id, $ref_type, ...$args ) { return ''; }
}

<?php
/** Stand-in for Form Captain's public connector interface (docs/CONNECTORS.md of Form Captain). */

// phpcs:ignoreFile

namespace FormCaptain\Integrations;

interface ConnectorInterface {
	public function key(): string;
	public function label(): string;
	public function version(): string;
	public function contract(): string;
	public function capabilities(): array;
	public function requirements(): array;
	public function settings_schema(): array;
	public function register_actions(): void;
}

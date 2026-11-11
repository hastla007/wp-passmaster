<?php
/**
 * Extended provider contract used by this module on top of PassCaptain\Wallet\ProviderInterface.
 *
 * $ticket arrays come from Service::ticket() (ticket_id, public_id, event_name, ticket_name,
 * starts_at (UTC), ends_at, timezone, location, seat, attendee_name, voided, code, auth_token,
 * generation, …). Providers registered through Bridge that only implement ProviderInterface are
 * treated as configured.
 */
namespace PassCaptain\WalletPasses;

use PassCaptain\Wallet\ProviderInterface;

if ( ! defined( 'ABSPATH' ) ) { exit; }

interface WalletProvider extends ProviderInterface {
	/** Short provider key ("apple", "google"). */
	public function id();

	/** True when enabled and all credentials are present and valid. */
	public function is_configured();
}

<?php
/** Optional pass-provider interface; signing keys remain provider-owned. */
namespace PassCaptain\Wallet;

if ( ! defined( 'ABSPATH' ) ) { exit; }

interface ProviderInterface {
	public function create_pass( array $ticket );
	public function update_pass( array $ticket, array $provider_pass );
	public function revoke_pass( array $provider_pass );
}

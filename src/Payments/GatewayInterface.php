<?php
/** Payment provider contract for idempotent adapter implementations. */
namespace PassCaptain\Payments;

if ( ! defined( 'ABSPATH' ) ) { exit; }

interface GatewayInterface {
	/** Start a provider payment and return a safe redirect/instructions payload. */
	public function create_payment( array $order );

	/** Verify a provider webhook and return a normalized payment event or WP_Error. */
	public function verify_webhook( \WP_REST_Request $request );
}

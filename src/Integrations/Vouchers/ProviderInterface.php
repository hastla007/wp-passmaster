<?php
/** Version 1 contract implemented by an optional voucher provider plugin. */
namespace PassCaptain\Integrations\Vouchers;

if ( ! defined( 'ABSPATH' ) ) { exit; }

interface ProviderInterface {
	/** Return an immutable quote or WP_Error for the supplied cart context. */
	public function quote( array $context );
	/** Reserve a quoted voucher application for an idempotency key. */
	public function reserve( array $quote, $idempotency_key );
	/** Commit a reservation after the related payment succeeds. */
	public function commit( array $reservation, $idempotency_key );
	/** Release an unused reservation. */
	public function release( array $reservation, $idempotency_key );
	/** Refund a committed redemption, optionally for a partial amount in minor units. */
	public function refund( array $redemption, $amount_minor, $idempotency_key );
}


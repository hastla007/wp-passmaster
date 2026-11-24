<?php
/**
 * State of one checkout attempt, shared by the phases of Orders::create_attempt().
 *
 * @package PassCaptain
 */

namespace PassCaptain\Orders;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Values of one checkout attempt (Orders::create()), carried between the phases of
 * Orders::create_attempt() (A401: the former 580-line method was split without behaviour change).
 *
 * @internal Not part of the add-on API: only Orders creates and reads it; the properties follow the phases.
 */
final class CheckoutState {
	// Request and channel, see Orders::checkout_state().
	/** @var array Checkout payload (customer input). */
	public $payload = array();
	/** @var array Trusted channel options (see Orders::create()). */
	public $internal = array();
	/** @var int 0, or the number of the internal retry after a busy database. */
	public $attempt = 0;
	/** @var string */
	public $email = '';
	/** @var mixed Raw ticket lines of the payload. */
	public $lines = array();
	/** @var string Idempotency key as given (or generated). */
	public $key = '';
	/** @var string Channel namespace of the key ('' = public checkout). */
	public $namespace = '';
	/** @var string Stored (hashed) idempotency key. */
	public $key_hash = '';
	/** @var bool */
	public $external = false;
	/** @var bool */
	public $comp = false;
	/** @var int */
	public $hold_minutes = 0;
	/** @var array */
	public $line_taxes = array();
	/** @var string */
	public $payment_provider = '';
	/** @var string */
	public $legal_locale = '';
	/** @var string */
	public $order_locale = '';
	/** @var string */
	public $terms_version = '';
	/** @var array */
	public $unit_prices = array();
	/** @var array */
	public $line_totals = array();
	/** @var string */
	public $external_currency = '';
	/** @var bool */
	public $skip_rate_limit = false;
	/** @var array */
	public $expected_prices = array();
	/** @var array */
	public $changed_prices = array();
	/** @var string */
	public $request_hash = '';

	// Lines, buyer and limits (before the transaction).
	/** @var array<int,int> Ticket type id => quantity. */
	public $clean_lines = array();
	/** @var array */
	public $shown_state = array();
	/** @var mixed */
	public $attendees = array();
	/** @var mixed */
	public $billing = array();
	/** @var mixed */
	public $throttle = null;
	/** @var string */
	public $now = '';
	/** @var bool */
	public $door_sale = false;
	/** @var \WP_Error|null Error with its own message for the failed attempt. */
	public $custom_error = null;
	/** @var int */
	public $customer_user_id = 0;
	/** @var bool */
	public $check_buyer_limits = false;
	/** @var mixed */
	public $buyer_locks = array();
	/** @var array */
	public $entered_codes = array();
	/** @var bool */
	public $public_checkout = false;
	/** @var bool */
	public $fee_channel = false;
	/** @var array|null */
	public $service_fee = null;
	/** @var bool */
	public $commit_unknown = false;

	// Transaction.
	/** @var array */
	public $types = array();
	/** @var array */
	public $locked_types = array();
	/** @var int[]|null Null until the ticket types are locked. */
	public $occurrence_ids = null;
	/** @var array */
	public $occurrences = array();
	/** @var string */
	public $currency = '';
	/** @var array */
	public $capacity_context = array();
	/** @var array */
	public $selected = array();
	/** @var int */
	public $total = 0;
	/** @var array */
	public $price_rules = array();
	/** @var int */
	public $subtotal = 0;
	/** @var mixed */
	public $adjustments = array();
	/** @var int */
	public $discount = 0;
	/** @var array */
	public $line_discounts = array();
	/** @var int */
	public $tax_total = 0;
	/** @var int|null Null until the order row is inserted. */
	public $order_id = null;
	/** @var string */
	public $public_id = '';
	/** @var string */
	public $access_token = '';
}

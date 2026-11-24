<?php
/** Internal exception carrying a WP_Error out of a transaction. */
namespace PassCaptain\Seating;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class SeatingException extends \Exception {
	public $error;
	public function __construct( \WP_Error $error ) {
		parent::__construct( (string) $error->get_error_code() );
		$this->error = $error;
	}
}

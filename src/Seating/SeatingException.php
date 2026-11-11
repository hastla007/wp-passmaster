<?php
/** Internal exception carrying a WP_Error out of a transaction. */
namespace PassCaptain\Seating;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class SeatingException extends \Exception {
	public $error;
	public function __construct( \WP_Error $error ) {
		parent::__construct( (string) $error->get_error_code() );
		$this->error = $error;
	}
}

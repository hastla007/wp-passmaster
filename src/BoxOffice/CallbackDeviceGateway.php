<?php
/** Adapter: a device registered as an array of callables (label, start, poll, cancel). */
namespace PassCaptain\BoxOffice;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class CallbackDeviceGateway implements DeviceGatewayInterface {
	/** @var string */
	private $label;
	/** @var callable */
	private $start;
	/** @var callable */
	private $poll;
	/** @var callable */
	private $cancel;

	public function __construct( string $label, callable $start, callable $poll, callable $cancel ) {
		$this->label = $label;
		$this->start = $start;
		$this->poll = $poll;
		$this->cancel = $cancel;
	}

	public function label(): string { return $this->label; }

	public function start( array $sale ) { return call_user_func( $this->start, $sale ); }

	public function poll( string $reference ) { return call_user_func( $this->poll, $reference ); }

	public function cancel( string $reference ) { return call_user_func( $this->cancel, $reference ); }
}

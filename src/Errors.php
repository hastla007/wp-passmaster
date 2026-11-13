<?php
/**
 * Shared error factory for transient database conditions (deadlock, lock wait timeout, lost
 * transaction): always HTTP 503 with `retryable: true`, so REST clients, payment providers and the
 * internal callers (Orders::is_retryable_error()) treat them the same way.
 */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Errors {
	/**
	 * @param string $message Optional user-facing text (default: "busy, try again").
	 * @param string $code    Error code; 'wpcap_db_busy' (default) or a module's legacy 'db_busy'.
	 * @param array  $data    Extra error data (status/retryable cannot be overridden).
	 */
	public static function busy( $message = '', $code = 'wpcap_db_busy', array $data = array() ) {
		$message = '' !== (string) $message ? (string) $message : __( 'Gerade ist sehr viel los. Bitte versuche es in einem Moment erneut.', 'wp-pass-captain' );
		return new \WP_Error( (string) $code, $message, array_merge( $data, array( 'status' => 503, 'retryable' => true ) ) );
	}

	/** Whether an error is such a transient condition. */
	public static function is_busy( $error ) {
		if ( ! $error instanceof \WP_Error ) { return false; }
		$data = (array) $error->get_error_data();
		return in_array( $error->get_error_code(), array( 'db_busy', 'wpcap_db_busy' ), true ) || ! empty( $data['retryable'] ) || 503 === (int) ( $data['status'] ?? 0 );
	}
}

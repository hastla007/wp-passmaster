<?php
/**
 * Shared scanner (CONCEPT 15.7): another plugin's scanner (e.g. Voucher Captain's) may recognise Pass Captain
 * ticket codes and hand them to this service, so one scanner serves vouchers and event tickets. Voucher
 * redemption and ticket check-in stay separate actions: this service only previews and checks in Pass Captain
 * tickets, with the same rules, locks and audit as Pass Captain's own web scanner. Callers need the capability
 * `wpcap_checkin`; the service checks it itself.
 *
 * Versioned filter: `wp_pass_captain_checkin_service_v1` (contract version 1).
 */
namespace PassCaptain\Integrations\Vouchers;

use PassCaptain\Checkin;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class CheckinService {
	const FILTER = 'wp_pass_captain_checkin_service_v1';
	const VERSION = 1;
	const CAP = 'wpcap_checkin';

	public static function register() {
		add_filter( self::FILTER, array( __CLASS__, 'service' ), 10, 2 );
	}

	public static function service( $service = null, $version = self::VERSION ) {
		if ( null !== $service || self::VERSION !== (int) $version ) { return $service; }
		return new self();
	}

	/** Whether a scanned value looks like a Pass Captain ticket (64-hex code or ticket URL). Cheap, no lookup. */
	public function recognizes( $value ) {
		return '' !== Checkin::normalize_code( (string) $value );
	}

	/** Whether the current user may check tickets in. */
	public function can_check_in() {
		return current_user_can( self::CAP ); // Logged-out visitors have no capabilities.
	}

	/**
	 * Read-only preview: status (valid, checked_in, revoked, … or a policy result such as not_valid_today),
	 * event, ticket, starts_at, timezone, optional attendee_name.
	 *
	 * @return array|\WP_Error
	 */
	public function preview( $value ) {
		if ( ! $this->can_check_in() ) { return self::forbidden(); }
		return Checkin::preview( (string) $value );
	}

	/**
	 * Checks the ticket in (atomic; repeated with the same idempotency key it never counts twice).
	 *
	 * @return array|\WP_Error Array with status "checked_in" on success.
	 */
	public function check_in( $value, $idempotency_key = '' ) {
		if ( ! $this->can_check_in() ) { return self::forbidden(); }
		return Checkin::perform( (string) $value, array( 'source' => 'web', 'idempotency_key' => sanitize_text_field( (string) $idempotency_key ) ) );
	}

	/** Admin URL of Pass Captain's own check-in list (for links from the other scanner). */
	public function admin_url() {
		return admin_url( 'admin.php?page=' . \PassCaptain\Admin\CheckinsPage::SLUG );
	}

	private static function forbidden() {
		return new \WP_Error( 'wpcap_checkin_forbidden', __( 'Du darfst keine Pass-Captain-Tickets einchecken.', 'pass-captain' ), array( 'status' => 403 ) );
	}
}

<?php
/**
 * Every capability the plugin defines, in one place (A372).
 *
 * CORE is granted by Installer::register_roles() (administrator and role packages); MODULE are
 * granted by modules (Guest lists: wpcap_approve_comps) or by operators by hand
 * (wpcap_boxoffice_override). uninstall.php removes all() from every role and user – it loads this
 * file directly (no autoloader there), so it must stay free of other plugin classes.
 */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class Capabilities {
	/** Capabilities of the role packages (Installer::CAPABILITIES). */
	const CORE = array( 'wpcap_manage_events', 'wpcap_manage_orders', 'wpcap_checkin', 'wpcap_view_reports', 'wpcap_refund', 'wpcap_approve_refunds', 'wpcap_view_finance', 'wpcap_service_bookings', self::UNDO_CHECKIN, self::REDEEM_PASSES );

	/**
	 * Taking back a check-in (admin ticket list, web scanner, app). Split off wpcap_checkin: every role
	 * that held wpcap_checkin when the split arrived received it once (Installer::grant_split_capabilities()),
	 * so operators can now take it away from door staff without taking away admission.
	 */
	const UNDO_CHECKIN = 'wpcap_undo_checkin';

	/** Redeeming member/season passes at the door (POST /passes/redeem). Split off wpcap_checkin like UNDO_CHECKIN. */
	const REDEEM_PASSES = 'wpcap_redeem_passes';

	/** Capabilities split off wpcap_checkin: granted once to every role that held wpcap_checkin. */
	const SPLIT_FROM_CHECKIN = array( self::UNDO_CHECKIN, self::REDEEM_PASSES );

	/** Capabilities added by modules or by operators (GuestLists\Service::APPROVE_CAP, BoxOffice\Shifts::OVERRIDE_CAP, Admin\AuditLogPage::CAP granted to administrators by the Installer, Api\TicketDetails::EMAIL_CAP granted by operators only). */
	const MODULE = array( 'wpcap_approve_comps', 'wpcap_boxoffice_override', 'wpcap_view_audit', 'wpcap_read_holder_email' );

	/** @return string[] All plugin capabilities (removed on uninstall). */
	public static function all() {
		return array_values( array_unique( array_merge( self::CORE, self::MODULE ) ) );
	}
}

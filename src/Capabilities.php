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

final class Capabilities {
	/** Capabilities of the role packages (Installer::CAPABILITIES). */
	const CORE = array( 'wpcap_manage_events', 'wpcap_manage_orders', 'wpcap_checkin', 'wpcap_view_reports', 'wpcap_refund', 'wpcap_approve_refunds', 'wpcap_view_finance', 'wpcap_service_bookings' );

	/** Capabilities added by modules or by operators (GuestLists\Service::APPROVE_CAP, BoxOffice\Shifts::OVERRIDE_CAP). */
	const MODULE = array( 'wpcap_approve_comps', 'wpcap_boxoffice_override' );

	/** @return string[] All plugin capabilities (removed on uninstall). */
	public static function all() {
		return array_values( array_unique( array_merge( self::CORE, self::MODULE ) ) );
	}
}

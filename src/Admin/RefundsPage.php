<?php
/**
 * Queue of refunds waiting for a second approval across all events the user may access.
 * Approve/reject actions are handled by OrdersPage.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Refunds;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class RefundsPage {
	const SLUG = 'wpcap-refunds';

	public static function menu_cap() {
		return current_user_can( Refunds::APPROVE_CAP ) ? Refunds::APPROVE_CAP : OrdersPage::CAP;
	}

	public static function render() {
		if ( ! current_user_can( Refunds::APPROVE_CAP ) && ! current_user_can( OrdersPage::CAP ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 );
		}
		// Books refunds the provider already paid out but whose local booking failed (no provider call).
		Refunds::reconcile();
		$unbooked = Refunds::unbooked_count();
		$refunds = Queries::pending_refunds( Queries::event_ids_for( OrdersPage::CAP ) );
		?>
		<div class="wrap wpcap-admin">
		<h1><?php esc_html_e( 'Erstattungen zur Freigabe', 'pass-captain' ); ?></h1>
		<?php Forms::render_notices(); ?>
		<?php if ( $unbooked > 0 ) : ?>
			<div class="notice notice-warning inline"><p><?php echo esc_html( sprintf( /* translators: %d: number of refunds */ _n( '%d Erstattung wurde beim Zahlungsanbieter ausgeführt, konnte aber noch nicht verbucht werden. Die Verbuchung wird automatisch wiederholt.', '%d Erstattungen wurden beim Zahlungsanbieter ausgeführt, konnten aber noch nicht verbucht werden. Die Verbuchung wird automatisch wiederholt.', $unbooked, 'pass-captain' ), $unbooked ) ); ?></p></div>
		<?php endif; ?>
		<p><?php esc_html_e( 'Erstattungen oberhalb des Freigabe-Schwellenwerts müssen von einer zweiten berechtigten Person freigegeben werden.', 'pass-captain' ); ?></p>
		<?php if ( ! current_user_can( Refunds::APPROVE_CAP ) ) : ?>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'Du kannst Erstattungen sehen, aber nicht freigeben.', 'pass-captain' ); ?></p></div>
		<?php endif; ?>
		<?php OrdersPage::render_refund_table( $refunds, 'EUR', true ); ?>
		</div>
		<?php
	}
}

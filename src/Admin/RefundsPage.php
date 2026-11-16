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
		// Fully refunded orders whose status update failed (still open after the reconcile run above).
		$state_pending = count( Refunds::orders_pending_refund_state( 100 ) );
		$refunds = Queries::pending_refunds( Queries::event_ids_for( OrdersPage::CAP ) );
		if ( $refunds ) { \PassCaptain\Privacy\AccessLog::list_view( 'refund_queue', array(), count( $refunds ) ); }
		?>
		<div class="wrap wpcap-admin">
		<h1><?php esc_html_e( 'Erstattungen zur Freigabe', 'pass-captain' ); ?></h1>
		<?php Forms::render_notices(); ?>
		<?php if ( $unbooked > 0 ) : ?>
			<div class="notice notice-warning inline"><p><?php echo esc_html( sprintf( /* translators: %d: number of refunds */ _n( '%d Erstattung wurde beim Zahlungsanbieter ausgeführt, konnte aber noch nicht verbucht werden. Die Verbuchung wird automatisch wiederholt.', '%d Erstattungen wurden beim Zahlungsanbieter ausgeführt, konnten aber noch nicht verbucht werden. Die Verbuchung wird automatisch wiederholt.', $unbooked, 'pass-captain' ), $unbooked ) ); ?></p></div>
		<?php endif; ?>
		<?php if ( $state_pending > 0 ) : ?>
			<div class="notice notice-warning inline"><p><?php echo esc_html( sprintf( /* translators: %d: number of orders */ _n( '%d vollständig erstattete Bestellung konnte wegen eines Datenbankfehlers noch nicht als erstattet markiert werden. Der Vorgang wird automatisch wiederholt; Details stehen im Audit-Log.', '%d vollständig erstattete Bestellungen konnten wegen eines Datenbankfehlers noch nicht als erstattet markiert werden. Der Vorgang wird automatisch wiederholt; Details stehen im Audit-Log.', $state_pending, 'pass-captain' ), $state_pending ) ); ?></p></div>
		<?php endif; ?>
		<p><?php esc_html_e( 'Erstattungen oberhalb des Freigabe-Schwellenwerts müssen von einer zweiten berechtigten Person freigegeben werden.', 'pass-captain' ); ?></p>
		<p><?php esc_html_e( 'Stornierungsanfragen, die Käufer:innen im Bestellportal stellen, erscheinen hier ebenfalls und werden erst nach der Freigabe ausgezahlt.', 'pass-captain' ); ?></p>
		<?php if ( ! current_user_can( Refunds::APPROVE_CAP ) ) : ?>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'Du kannst Erstattungen sehen, aber nicht freigeben.', 'pass-captain' ); ?></p></div>
		<?php endif; ?>
		<?php OrdersPage::render_refund_table( $refunds, 'EUR', true ); ?>
		</div>
		<?php
	}
}

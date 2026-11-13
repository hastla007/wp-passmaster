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
		$refunds = Queries::pending_refunds( Queries::event_ids_for( OrdersPage::CAP ) );
		if ( $refunds ) { \PassCaptain\Privacy\AccessLog::list_view( 'refund_queue', array(), count( $refunds ) ); }
		?>
		<div class="wrap wpcap-admin">
		<h1><?php esc_html_e( 'Erstattungen zur Freigabe', 'pass-captain' ); ?></h1>
		<?php Forms::render_notices(); ?>
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

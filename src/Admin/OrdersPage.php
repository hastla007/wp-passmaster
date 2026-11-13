<?php
/**
 * Orders admin: filtered list, order detail and order actions (manual payment, cancellation,
 * ticket resend, refunds incl. second approval).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Orders;
use PassCaptain\Refunds;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class OrdersPage {
	const SLUG = 'wpcap-orders';
	const CAP = 'wpcap_manage_orders';
	const ORDER_STATUSES = array( 'pending_payment', 'complete', 'cancelled', 'refunded', 'expired', 'needs_review' );
	const PAYMENT_STATUSES = array( 'pending', 'paid', 'expired', 'cancelled', 'refunded', 'paid_unallocated' );

	/** Capability that allows requesting refunds (Refunds::REFUND_CAP when defined). */
	public static function refund_cap() {
		return defined( Refunds::class . '::REFUND_CAP' ) ? constant( Refunds::class . '::REFUND_CAP' ) : self::CAP;
	}

	/** Whether the current user may request refunds at all. */
	public static function can_request_refunds() {
		return current_user_can( self::refund_cap() ) || current_user_can( Refunds::APPROVE_CAP );
	}

	/** Loads an order the current user may act on. */
	private static function order_for_action( array $in ) {
		$order = Orders::get( Forms::int( $in, 'order_id' ) );
		if ( ! $order ) { return Forms::error( 'wpcap_order_missing', __( 'Bestellung nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		if ( ! Queries::can_access_order( $order->order_id, self::CAP ) ) { return Forms::forbidden( __( 'Keine Berechtigung für diese Bestellung.', 'wp-pass-captain' ) ); }
		return $order;
	}

	/* =============================================================================================
	 * Processing
	 * =========================================================================================== */

	/** Confirms a manual payment (manual provider only). */
	public static function confirm_payment( array $in ) {
		$order = self::order_for_action( $in );
		if ( is_wp_error( $order ) ) { return $order; }
		if ( 'manual' !== $order->payment_provider ) {
			return Forms::error( 'wpcap_not_manual', __( 'Nur Bestellungen mit manueller Zahlart können hier als bezahlt markiert werden.', 'wp-pass-captain' ), 409 );
		}
		$result = Orders::confirm_manual_payment( $order->order_id );
		if ( is_wp_error( $result ) ) { return Forms::error( $result->get_error_code(), $result->get_error_message(), 409 ); }
		return array( 'order_id' => (int) $order->order_id );
	}

	/**
	 * Retries the ticket allocation of a paid order flagged `needs_review` (late payment without
	 * free capacity at that moment), e.g. after capacity was freed or a transient error. Any
	 * provider except WooCommerce (handled by its own order action).
	 */
	public static function retry_allocation( array $in ) {
		$order = self::order_for_action( $in );
		if ( is_wp_error( $order ) ) { return $order; }
		if ( 'needs_review' !== $order->status || 'paid_unallocated' !== $order->payment_status || 'woocommerce' === $order->payment_provider ) {
			return Forms::error( 'wpcap_order_state', __( 'Der Bestellstatus erlaubt diese Aktion nicht.', 'wp-pass-captain' ), 409 );
		}
		$result = Orders::confirm_external_payment( $order->order_id, 'retry:user-' . get_current_user_id() );
		if ( is_wp_error( $result ) ) { return Forms::error( $result->get_error_code(), $result->get_error_message(), 409 ); }
		\PassCaptain\Audit::record( 'allocation_retried', 'order', (int) $order->order_id );
		return array( 'order_id' => (int) $order->order_id );
	}

	/** Cancels an unpaid order. */
	public static function cancel_order( array $in ) {
		$order = self::order_for_action( $in );
		if ( is_wp_error( $order ) ) { return $order; }
		if ( 'woocommerce' === $order->payment_provider ) {
			return Forms::error( 'wpcap_order_woocommerce', __( 'WooCommerce-Bestellungen bitte in WooCommerce stornieren.', 'wp-pass-captain' ), 409 );
		}
		if ( 'pending' !== $order->payment_status || 'pending_payment' !== $order->status ) {
			return Forms::error( 'wpcap_order_not_unpaid', __( 'Nur unbezahlte, offene Bestellungen können storniert werden. Bezahlte Bestellungen bitte erstatten.', 'wp-pass-captain' ), 409 );
		}
		Orders::cancel_order( $order->order_id, Forms::text( $in, 'reason', 255 ) ?: 'admin_cancel' );
		return array( 'order_id' => (int) $order->order_id );
	}

	/** Resends tickets with fresh codes. */
	public static function resend_tickets( array $in ) {
		$order = self::order_for_action( $in );
		if ( is_wp_error( $order ) ) { return $order; }
		$result = Orders::resend_tickets( $order->order_id );
		if ( is_wp_error( $result ) ) { return Forms::error( $result->get_error_code(), $result->get_error_message(), 409 ); }
		return array( 'order_id' => (int) $order->order_id );
	}

	/**
	 * Requests a refund for selected tickets or the whole order. The idempotency key is generated
	 * when the form is rendered, so a double submit returns the same refund.
	 */
	public static function refund( array $in ) {
		$order = self::order_for_action( $in );
		if ( is_wp_error( $order ) ) { return $order; }
		if ( ! self::can_request_refunds() ) { return Forms::forbidden( __( 'Keine Berechtigung für Erstattungen.', 'wp-pass-captain' ) ); }
		if ( 'woocommerce' === $order->payment_provider ) {
			return Forms::error( 'wpcap_refund_woocommerce', __( 'WooCommerce-Bestellungen bitte in WooCommerce erstatten; die Tickets werden automatisch widerrufen.', 'wp-pass-captain' ), 409 );
		}
		$reason = Forms::text( $in, 'reason', 255 );
		if ( '' === $reason ) { return Forms::error( 'wpcap_refund_reason', __( 'Bitte einen Grund für die Erstattung angeben.', 'wp-pass-captain' ) ); }
		$key = preg_replace( '/[^A-Za-z0-9\-]/', '', (string) ( $in['idempotency_key'] ?? '' ) );
		if ( strlen( $key ) < 16 || strlen( $key ) > 64 ) {
			return Forms::error( 'wpcap_refund_key', __( 'Das Formular ist abgelaufen. Bitte die Seite neu laden.', 'wp-pass-captain' ) );
		}
		$all = Forms::flag( $in, 'refund_all' );
		$ticket_ids = $all ? array() : Forms::ids( $in, 'ticket_ids' );
		if ( ! $all && ! $ticket_ids ) {
			return Forms::error( 'wpcap_refund_tickets', __( 'Bitte Tickets auswählen oder „Alle offenen Tickets“ wählen.', 'wp-pass-captain' ) );
		}
		$result = Refunds::request( $order->order_id, $ticket_ids, $reason, $key );
		if ( is_wp_error( $result ) ) {
			$status = (int) ( ( $result->get_error_data()['status'] ?? 409 ) );
			return Forms::error( $result->get_error_code(), $result->get_error_message(), 403 === $status ? 403 : 409 );
		}
		return array( 'order_id' => (int) $order->order_id, 'refund' => $result );
	}

	/** Loads a pending refund the current user may decide on. */
	private static function refund_for_decision( array $in ) {
		if ( ! current_user_can( Refunds::APPROVE_CAP ) ) { return Forms::forbidden( __( 'Keine Berechtigung für Erstattungsfreigaben.', 'wp-pass-captain' ) ); }
		$refund = Refunds::get( Forms::int( $in, 'refund_id' ) );
		if ( ! $refund ) { return Forms::error( 'wpcap_refund_missing', __( 'Erstattung nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		if ( ! Queries::can_access_order( $refund['order_id'], self::CAP ) ) { return Forms::forbidden( __( 'Keine Berechtigung für diese Bestellung.', 'wp-pass-captain' ) ); }
		if ( 'pending_approval' !== $refund['status'] ) {
			return Forms::error( 'wpcap_refund_not_pending', __( 'Diese Erstattung wartet nicht auf Freigabe.', 'wp-pass-captain' ), 409 );
		}
		return $refund;
	}

	/** Second approval of a pending refund (must be another person than the requester). */
	public static function approve_refund( array $in ) {
		$refund = self::refund_for_decision( $in );
		if ( is_wp_error( $refund ) ) { return $refund; }
		if ( (int) $refund['requested_by'] === get_current_user_id() ) {
			return Forms::error( 'wpcap_refund_same_person', __( 'Die Freigabe muss durch eine andere berechtigte Person erfolgen.', 'wp-pass-captain' ), 409 );
		}
		$result = Refunds::approve( $refund['refund_id'] );
		if ( is_wp_error( $result ) ) { return Forms::error( $result->get_error_code(), $result->get_error_message(), 409 ); }
		return array( 'order_id' => (int) $refund['order_id'], 'refund' => $result );
	}

	/** Rejects a pending refund (reason required). */
	public static function reject_refund( array $in ) {
		$refund = self::refund_for_decision( $in );
		if ( is_wp_error( $refund ) ) { return $refund; }
		$reason = Forms::text( $in, 'reason', 255 );
		if ( '' === $reason ) { return Forms::error( 'wpcap_refund_reason', __( 'Bitte einen Grund für die Ablehnung angeben.', 'wp-pass-captain' ) ); }
		$result = Refunds::reject( $refund['refund_id'], $reason );
		if ( is_wp_error( $result ) ) { return Forms::error( $result->get_error_code(), $result->get_error_message(), 409 ); }
		\PassCaptain\Audit::record( 'refund_rejected', 'order', (int) $refund['order_id'], array( 'refund_id' => (int) $refund['refund_id'], 'reason' => $reason ) );
		return array( 'order_id' => (int) $refund['order_id'], 'refund' => $result );
	}

	/* =============================================================================================
	 * admin-post wrappers
	 * =========================================================================================== */

	public static function order_url( $order_id ) {
		return Forms::page_url( self::SLUG, array( 'order_id' => absint( $order_id ) ) );
	}

	/** Redirect target after a refund decision (refund queue or order detail). */
	private static function decision_url( array $in, $order_id ) {
		return 'queue' === ( $in['return'] ?? '' ) ? Forms::page_url( RefundsPage::SLUG ) : self::order_url( $order_id );
	}

	public static function handle_confirm_payment() {
		$in = Forms::post();
		$order_id = Forms::int( $in, 'order_id' );
		Forms::verify( 'wpcap_confirm_payment_' . $order_id );
		Forms::finish( self::confirm_payment( $in ), __( 'Zahlung bestätigt; Tickets wurden ausgestellt.', 'wp-pass-captain' ), self::order_url( $order_id ) );
	}

	public static function handle_retry_allocation() {
		$in = Forms::post();
		$order_id = Forms::int( $in, 'order_id' );
		Forms::verify( 'wpcap_retry_allocation_' . $order_id );
		Forms::finish( self::retry_allocation( $in ), __( 'Kontingent zugeteilt; Tickets wurden ausgestellt.', 'wp-pass-captain' ), self::order_url( $order_id ) );
	}

	public static function handle_cancel_order() {
		$in = Forms::post();
		$order_id = Forms::int( $in, 'order_id' );
		Forms::verify( 'wpcap_cancel_order_' . $order_id );
		Forms::finish( self::cancel_order( $in ), __( 'Bestellung storniert.', 'wp-pass-captain' ), self::order_url( $order_id ) );
	}

	public static function handle_resend_tickets() {
		$in = Forms::post();
		$order_id = Forms::int( $in, 'order_id' );
		Forms::verify( 'wpcap_resend_tickets_' . $order_id );
		Forms::finish( self::resend_tickets( $in ), __( 'Tickets wurden mit neuen Codes erneut gesendet.', 'wp-pass-captain' ), self::order_url( $order_id ) );
	}

	public static function handle_refund() {
		$in = Forms::post();
		$order_id = Forms::int( $in, 'order_id' );
		Forms::verify( 'wpcap_refund_order_' . $order_id );
		$result = self::refund( $in );
		$message = '';
		if ( ! is_wp_error( $result ) ) {
			$status = $result['refund']['status'] ?? '';
			$message = 'pending_approval' === $status ? __( 'Erstattung angefordert. Sie wartet auf die Freigabe durch eine zweite Person.', 'wp-pass-captain' ) : sprintf( /* translators: %s: status */ __( 'Erstattung verarbeitet (Status: %s).', 'wp-pass-captain' ), Forms::status_label( $status ) );
		}
		Forms::finish( $result, $message, self::order_url( $order_id ) );
	}

	public static function handle_approve_refund() {
		$in = Forms::post();
		Forms::verify( 'wpcap_approve_refund_' . Forms::int( $in, 'refund_id' ) );
		$result = self::approve_refund( $in );
		$order_id = is_wp_error( $result ) ? Forms::int( $in, 'order_id' ) : $result['order_id'];
		Forms::finish( $result, __( 'Erstattung freigegeben.', 'wp-pass-captain' ), self::decision_url( $in, $order_id ) );
	}

	public static function handle_reject_refund() {
		$in = Forms::post();
		Forms::verify( 'wpcap_reject_refund_' . Forms::int( $in, 'refund_id' ) );
		$result = self::reject_refund( $in );
		$order_id = is_wp_error( $result ) ? Forms::int( $in, 'order_id' ) : $result['order_id'];
		Forms::finish( $result, __( 'Erstattung abgelehnt.', 'wp-pass-captain' ), self::decision_url( $in, $order_id ) );
	}

	/* =============================================================================================
	 * Rendering
	 * =========================================================================================== */

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 ); }
		echo '<div class="wrap wpcap-admin">';
		$order_id = absint( $_GET['order_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $order_id ) {
			self::render_detail( $order_id );
		} else {
			self::render_list();
		}
		echo '</div>';
	}

	private static function render_list() {
		$scope = Queries::event_ids_for( self::CAP );
		$filters = array(
			'status' => in_array( Forms::query_arg( 'status' ), self::ORDER_STATUSES, true ) ? Forms::query_arg( 'status' ) : '',
			'payment_status' => in_array( Forms::query_arg( 'payment_status' ), self::PAYMENT_STATUSES, true ) ? Forms::query_arg( 'payment_status' ) : '',
			'search' => Forms::query_arg( 's' ),
			'event_id' => absint( Forms::query_arg( 'event_id', 0 ) ),
		);
		$page = Forms::current_page();
		$per_page = 20;
		$data = Queries::search_orders( $filters, $scope, $page, $per_page );
		$events = Queries::event_options( $scope );
		$base = Forms::page_url( self::SLUG, array_filter( array( 'status' => $filters['status'], 'payment_status' => $filters['payment_status'], 's' => $filters['search'], 'event_id' => $filters['event_id'] ) ) );
		$status_options = array( '' => __( 'Alle Bestellstatus', 'wp-pass-captain' ) );
		foreach ( self::ORDER_STATUSES as $s ) { $status_options[ $s ] = Forms::status_label( $s ); }
		$payment_options = array( '' => __( 'Alle Zahlungsstatus', 'wp-pass-captain' ) );
		foreach ( self::PAYMENT_STATUSES as $s ) { $payment_options[ $s ] = Forms::status_label( $s ); }
		?>
		<h1><?php esc_html_e( 'Bestellungen', 'wp-pass-captain' ); ?></h1>
		<?php Forms::render_notices(); ?>
		<form method="get" class="wpcap-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
			<?php Forms::select( 'status', $status_options, $filters['status'], '', 'aria-label="' . esc_attr__( 'Bestellstatus', 'wp-pass-captain' ) . '"' ); ?>
			<?php Forms::select( 'payment_status', $payment_options, $filters['payment_status'], '', 'aria-label="' . esc_attr__( 'Zahlungsstatus', 'wp-pass-captain' ) . '"' ); ?>
			<?php Forms::select( 'event_id', array( 0 => __( 'Alle Events', 'wp-pass-captain' ) ) + $events, $filters['event_id'], '', 'aria-label="' . esc_attr__( 'Event', 'wp-pass-captain' ) . '"' ); ?>
			<input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Bestellnummer oder E-Mail', 'wp-pass-captain' ); ?>" aria-label="<?php esc_attr_e( 'Bestellnummer oder E-Mail', 'wp-pass-captain' ); ?>">
			<button class="button" type="submit"><?php esc_html_e( 'Filtern', 'wp-pass-captain' ); ?></button>
		</form>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Bestellung', 'wp-pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Datum', 'wp-pass-captain' ); ?></th>
				<th><?php esc_html_e( 'E-Mail', 'wp-pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Event', 'wp-pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Summe', 'wp-pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Status', 'wp-pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Zahlart', 'wp-pass-captain' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $data['rows'] ) : ?><tr><td colspan="7"><?php esc_html_e( 'Keine Bestellungen gefunden.', 'wp-pass-captain' ); ?></td></tr><?php endif; ?>
			<?php foreach ( $data['rows'] as $order ) : ?>
				<tr>
					<td><a href="<?php echo esc_url( self::order_url( $order->order_id ) ); ?>"><code><?php echo esc_html( substr( $order->public_id, 0, 12 ) ); ?>…</code></a></td>
					<td><?php echo esc_html( Forms::datetime( $order->created_at, wp_timezone_string(), false ) ); ?></td>
					<td><?php echo esc_html( $order->customer_email ); ?></td>
					<td><?php echo esc_html( (string) $order->event_names ); ?></td>
					<td><?php echo esc_html( Forms::money( $order->total_minor, $order->currency ) ); ?><?php if ( (int) $order->refunded_minor ) : ?><br><small><?php echo esc_html( sprintf( /* translators: %s: amount */ __( 'erstattet: %s', 'wp-pass-captain' ), Forms::money( $order->refunded_minor, $order->currency ) ) ); ?></small><?php endif; ?></td>
					<td><?php echo esc_html( Forms::status_label( $order->status ) . ' / ' . Forms::status_label( $order->payment_status ) ); ?></td>
					<td><?php echo esc_html( $order->payment_provider ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		Forms::pagination( $data['total'], $page, $per_page, $base );
	}

	/** Finds the WooCommerce order id that references a core order (read-only hint). */
	private static function woocommerce_order_id( $order_id ) {
		if ( ! function_exists( 'wc_get_orders' ) ) { return 0; }
		$ids = wc_get_orders( array( 'limit' => 1, 'return' => 'ids', 'meta_key' => '_wpcap_core_order_id', 'meta_value' => absint( $order_id ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		return $ids ? absint( $ids[0] ) : 0;
	}

	private static function render_detail( $order_id ) {
		$order = Orders::get( $order_id );
		$list_url = Forms::page_url( self::SLUG );
		if ( ! $order || ! Queries::can_access_order( $order_id, self::CAP ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Bestellung nicht gefunden oder keine Berechtigung.', 'wp-pass-captain' ) . '</p></div>';
			echo '<p><a href="' . esc_url( $list_url ) . '">' . esc_html__( '← Zur Bestellliste', 'wp-pass-captain' ) . '</a></p>';
			return;
		}
		$items = Queries::order_items( $order_id );
		$is_supplement = \PassCaptain\Exchange\Service::is_supplement( (int) $order_id );
		$has_moves = $is_supplement;
		foreach ( $items as $item ) {
			if ( null !== ( $item->orig_quantity ?? null ) && (int) $item->orig_quantity !== (int) $item->quantity ) { $has_moves = true; }
		}
		$tickets = Queries::order_tickets( $order_id );
		$refunds = Refunds::for_order( $order_id );
		$is_woo = 'woocommerce' === $order->payment_provider;
		$currency = $order->currency;
		$tz = wp_timezone_string();
		?>
		<p><a href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( '← Zur Bestellliste', 'wp-pass-captain' ); ?></a></p>
		<h1><?php echo esc_html( sprintf( /* translators: %s: order id */ __( 'Bestellung %s', 'wp-pass-captain' ), \PassCaptain\Frontend\Format::order_reference( $order->public_id ) ) ); ?> <small style="font-size:.6em;font-weight:normal;color:#646970"><?php echo esc_html( $order->public_id ); ?></small></h1>
		<?php Forms::render_notices(); ?>
		<?php if ( $is_woo ) :
			$wc_id = self::woocommerce_order_id( $order_id );
			?>
			<div class="notice notice-info inline"><p>
				<?php esc_html_e( 'Diese Bestellung wurde über WooCommerce abgewickelt und ist hier nur lesbar. Zahlungen, Stornos und Erstattungen bitte in WooCommerce durchführen; Tickets werden automatisch widerrufen.', 'wp-pass-captain' ); ?>
				<?php if ( $wc_id ) : ?> <a href="<?php echo esc_url( admin_url( 'post.php?post=' . $wc_id . '&action=edit' ) ); ?>"><?php echo esc_html( sprintf( /* translators: %d: WooCommerce order id */ __( 'WooCommerce-Bestellung #%d öffnen', 'wp-pass-captain' ), $wc_id ) ); ?></a><?php endif; ?>
			</p></div>
		<?php endif; ?>
		<?php $voucher = \PassCaptain\Integrations\Vouchers\Checkout::voucher_state( $order_id ); ?>
		<?php if ( $voucher && 'commit_failed' === ( $voucher['status'] ?? '' ) ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( sprintf( /* translators: 1: amount, 2: error code */ __( 'Bezahlt, aber der Gutschein über %1$s konnte nicht belastet werden (Fehler: %2$s). Die Tickets sind gültig. Bitte den Betrag nachfordern oder den Gutschein in WP Voucher Captain manuell belasten.', 'wp-pass-captain' ), Forms::money( (int) $voucher['amount_minor'], $order->currency ), (string) ( $voucher['error'] ?? '' ) ) ); ?></p></div>
		<?php endif; ?>
		<?php if ( $is_supplement ) :
			$exchange_row = \PassCaptain\Exchange\Service::for_supplement( (int) $order_id );
			?>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'Zusatzbestellung zu einem Ticketumtausch (Aufpreis/Gebühr). Das Ticket wird in der ursprünglichen Bestellung geführt.', 'wp-pass-captain' ); ?>
			<?php if ( $exchange_row ) : ?> <a href="<?php echo esc_url( self::order_url( (int) $exchange_row['order_id'] ) ); ?>"><?php esc_html_e( 'Ursprüngliche Bestellung öffnen', 'wp-pass-captain' ); ?></a><?php endif; ?></p></div>
		<?php endif; ?>
		<?php if ( 'needs_review' === $order->status ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'Die Zahlung ist nach Ablauf der Reservierung eingegangen und es war kein Kontingent mehr frei. Bitte Platz freigeben und die Zuteilung erneut versuchen oder die Zahlung außerhalb des Plugins zurückerstatten.', 'wp-pass-captain' ); ?></p>
			<?php if ( 'paid_unallocated' === $order->payment_status && ! $is_woo ) : ?><p><?php Forms::open_form( 'wpcap_retry_allocation', 'wpcap_retry_allocation_' . $order_id, 'style="display:inline"' ); Forms::hidden( 'order_id', $order_id ); ?><button class="button" type="submit"><?php esc_html_e( 'Zuteilung erneut versuchen', 'wp-pass-captain' ); ?></button></form></p><?php endif; ?></div>
		<?php endif; ?>

		<table class="form-table"><tbody>
			<tr><th><?php esc_html_e( 'Status', 'wp-pass-captain' ); ?></th><td><?php echo esc_html( Forms::status_label( $order->status ) . ' / ' . Forms::status_label( $order->payment_status ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Käufer', 'wp-pass-captain' ); ?></th><td><?php echo esc_html( $order->customer_email ); ?></td></tr>
			<?php $billing = \PassCaptain\Billing::for_order( $order ); ?>
			<tr><th><?php esc_html_e( 'Rechnungsdaten', 'wp-pass-captain' ); ?></th><td class="wpcap-order-billing"><?php echo $billing ? implode( '<br>', array_map( 'esc_html', array_merge( \PassCaptain\Billing::address_lines( $billing ), \PassCaptain\Billing::detail_lines( $billing ) ) ) ) : esc_html__( 'Keine (Rechnung nur mit E-Mail-Adresse)', 'wp-pass-captain' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each line escaped. ?></td></tr>
			<tr><th><?php esc_html_e( 'Angelegt', 'wp-pass-captain' ); ?></th><td><?php echo esc_html( Forms::datetime( $order->created_at, $tz ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Zahlart / Referenz', 'wp-pass-captain' ); ?></th><td><?php echo esc_html( $order->payment_provider . ( '' !== (string) $order->provider_reference ? ' / ' . $order->provider_reference : '' ) ); ?></td></tr>
			<?php if ( \PassCaptain\PayoutAccount::applies( $order ) ) : $payout = \PassCaptain\PayoutAccount::get( $order ); ?>
			<tr><th><?php esc_html_e( 'Bankverbindung für Erstattungen', 'wp-pass-captain' ); ?></th><td><?php echo $payout ? esc_html( \PassCaptain\Payments\BankTransfer::format_iban( $payout['iban'] ) . ' · ' . $payout['holder'] ) : esc_html__( 'Noch nicht angegeben (Käufer:in kann sie in der Bestellübersicht eintragen).', 'wp-pass-captain' ); ?></td></tr>
			<?php endif; ?>
			<?php
			global $wpdb;
			$invoice = $wpdb->get_row( $wpdb->prepare( "SELECT invoice_id,invoice_number FROM {$wpdb->prefix}wpcap_invoices WHERE order_id=%d LIMIT 1", $order_id ) );
			$credit_notes = $invoice ? $wpdb->get_col( $wpdb->prepare( "SELECT credit_note_number FROM {$wpdb->prefix}wpcap_credit_notes WHERE invoice_id=%d ORDER BY credit_note_id", $invoice->invoice_id ) ) : array();
			$invoice_url = add_query_arg( array( 'action' => 'wpcap_print_invoice', 'order_id' => (int) $order_id ), admin_url( 'admin-post.php' ) );
			?>
			<tr><th><?php esc_html_e( 'Rechnung', 'wp-pass-captain' ); ?></th><td>
				<?php if ( $invoice ) : ?>
					<a href="<?php echo esc_url( $invoice_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( sprintf( /* translators: %s: invoice number */ __( 'Rechnung %s anzeigen', 'wp-pass-captain' ), $invoice->invoice_number ) ); ?></a>
					<?php if ( $credit_notes ) : ?><br><?php echo esc_html( sprintf( /* translators: %s: credit note numbers */ __( 'Gutschriften (in der Rechnungsansicht): %s', 'wp-pass-captain' ), implode( ', ', $credit_notes ) ) ); ?><?php endif; ?>
				<?php elseif ( 'paid' === $order->payment_status && current_user_can( 'wpcap_view_finance' ) ) : ?>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( $invoice_url, 'wpcap_issue_invoice_' . (int) $order_id ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Rechnung erstellen', 'wp-pass-captain' ); ?></a>
				<?php else : ?>—<?php endif; ?>
			</td></tr>
			<?php
			// Sales model / seller of the customer invoice (docs/SALES-MODELS.md).
			if ( $invoice ) {
				$sales_label = \PassCaptain\Invoices::seller_label( (array) json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT buyer_snapshot FROM {$wpdb->prefix}wpcap_invoices WHERE invoice_id=%d", $invoice->invoice_id ) ), true ) );
			} else {
				$sales_context = \PassCaptain\Partners\SalesModel::order_context( $order_id );
				$sales_label = is_wp_error( $sales_context ) ? '' : \PassCaptain\Invoices::seller_label( array( 'sales_model' => $sales_context['model'], 'seller' => $sales_context['seller'] ) );
			}
			?>
			<tr><th><?php esc_html_e( 'Verkaufsmodell', 'wp-pass-captain' ); ?></th><td>
				<?php if ( ! $invoice && isset( $sales_context ) && is_wp_error( $sales_context ) ) : ?>
					<div class="notice notice-error inline"><p><?php echo esc_html( $sales_context->get_error_message() ); ?></p></div>
				<?php else : ?><?php echo esc_html( $sales_label ); ?><?php endif; ?>
			</td></tr>
			<tr><th><?php esc_html_e( 'Version AGB/Verbraucherinfo', 'wp-pass-captain' ); ?></th><td><?php echo esc_html( '' !== (string) $order->terms_version ? $order->terms_version : '—' ); ?></td></tr>
		</tbody></table>

		<h2><?php esc_html_e( 'Positionen', 'wp-pass-captain' ); ?></h2>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Ticketart', 'wp-pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Termin', 'wp-pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Menge', 'wp-pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Einzelpreis', 'wp-pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Steuersatz', 'wp-pass-captain' ); ?></th>
				<th><?php esc_html_e( 'enth. Steuer', 'wp-pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Summe', 'wp-pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Erstattet (Stk.)', 'wp-pass-captain' ); ?></th>
				<?php if ( $has_moves ) : ?><th><?php esc_html_e( 'Umgebucht (Stk.)', 'wp-pass-captain' ); ?></th><?php endif; ?>
			</tr></thead>
			<tbody>
			<?php foreach ( $items as $item ) :
				// Quantity, VAT and total as paid (OrderLines); units moved by a ticket exchange are shown
				// separately, so the lines match the invoice.
				$paid_qty = null !== ( $item->orig_quantity ?? null ) ? (int) $item->orig_quantity : (int) $item->quantity;
				$paid_tax = null !== ( $item->orig_tax_minor ?? null ) ? (int) $item->orig_tax_minor : (int) $item->tax_minor;
				$paid_total = null !== ( $item->orig_line_total_minor ?? null ) ? (int) $item->orig_line_total_minor : (int) $item->line_total_minor;
				$moved = $is_supplement ? -(int) $item->quantity : (int) $item->quantity - $paid_qty;
				$refunded_units = $is_supplement ? 0 : (int) $item->refunded_quantity;
				?>
				<tr>
					<td><?php echo esc_html( $item->event_name . ' — ' . $item->name ); ?></td>
					<td><?php echo esc_html( Forms::datetime( $item->starts_at, $item->timezone ) ); ?></td>
					<td><?php echo esc_html( (string) $paid_qty ); ?></td>
					<td><?php echo esc_html( Forms::money( $item->unit_price_minor, $currency ) ); ?></td>
					<td><?php echo esc_html( Forms::percent_input( $item->tax_rate_bp ) . ' %' ); ?></td>
					<td><?php echo esc_html( Forms::money( $paid_tax, $currency ) ); ?></td>
					<td><?php echo esc_html( Forms::money( $paid_total, $currency ) ); ?></td>
					<td><?php echo esc_html( (string) $refunded_units ); ?></td>
					<?php if ( $has_moves ) : ?><td><?php echo esc_html( 0 === $moved ? '—' : sprintf( '%+d', $moved ) ); ?></td><?php endif; ?>
				</tr>
			<?php endforeach; ?>
			</tbody>
			<tfoot>
				<tr><th colspan="6"><?php esc_html_e( 'Zwischensumme', 'wp-pass-captain' ); ?></th><td colspan="<?php echo $has_moves ? 3 : 2; ?>"><?php echo esc_html( Forms::money( $order->subtotal_minor, $currency ) ); ?></td></tr>
				<?php if ( (int) $order->discount_minor ) : ?><tr><th colspan="6"><?php esc_html_e( 'Rabatt', 'wp-pass-captain' ); ?></th><td colspan="<?php echo $has_moves ? 3 : 2; ?>"><?php echo esc_html( '− ' . Forms::money( $order->discount_minor, $currency ) ); ?></td></tr><?php endif; ?>
				<?php $voucher_paid = class_exists( '\\PassCaptain\\Integrations\\Vouchers\\Checkout' ) ? \PassCaptain\Integrations\Vouchers\Checkout::voucher_payment_minor( $order ) : 0; ?>
				<tr><th colspan="6"><?php esc_html_e( 'Gesamt (brutto)', 'wp-pass-captain' ); ?></th><td colspan="<?php echo $has_moves ? 3 : 2; ?>"><strong><?php echo esc_html( Forms::money( (int) $order->total_minor + $voucher_paid, $currency ) ); ?></strong></td></tr>
				<?php if ( $voucher_paid ) : ?>
					<tr><th colspan="6"><?php esc_html_e( 'davon bezahlt mit Mehrzweckgutschein', 'wp-pass-captain' ); ?></th><td colspan="<?php echo $has_moves ? 3 : 2; ?>"><?php echo esc_html( Forms::money( $voucher_paid, $currency ) ); ?></td></tr>
					<tr><th colspan="6"><?php esc_html_e( 'Zahlbetrag', 'wp-pass-captain' ); ?></th><td colspan="<?php echo $has_moves ? 3 : 2; ?>"><?php echo esc_html( Forms::money( $order->total_minor, $currency ) ); ?></td></tr>
				<?php endif; ?>
				<tr><th colspan="6"><?php esc_html_e( 'darin enthaltene Steuer', 'wp-pass-captain' ); ?></th><td colspan="<?php echo $has_moves ? 3 : 2; ?>"><?php echo esc_html( Forms::money( $order->tax_minor, $currency ) ); ?></td></tr>
				<tr><th colspan="6"><?php esc_html_e( 'Erstattet', 'wp-pass-captain' ); ?></th><td colspan="<?php echo $has_moves ? 3 : 2; ?>"><?php echo esc_html( Forms::money( $order->refunded_minor, $currency ) ); ?></td></tr>
			</tfoot>
		</table>

		<?php if ( ! $is_woo ) { self::render_actions( $order ); } ?>

		<h2><?php esc_html_e( 'Tickets', 'wp-pass-captain' ); ?></h2>
		<?php
		$in_progress = array();
		foreach ( $refunds as $refund ) {
			if ( in_array( $refund['status'], array( 'pending_approval', 'approved', 'processing' ), true ) ) {
				$in_progress = array_merge( $in_progress, array_map( 'absint', (array) json_decode( (string) $refund['ticket_ids'], true ) ) );
			}
		}
		$refundable = array_filter( $tickets, static function ( $t ) use ( $in_progress ) { return ! (int) $t->refunded && in_array( $t->status, array( 'valid', 'checked_in' ), true ) && ! in_array( (int) $t->ticket_id, $in_progress, true ); } );
		$can_refund = ! $is_woo && 'paid' === $order->payment_status && $refundable && self::can_request_refunds();
		if ( $can_refund ) {
			Forms::open_form( 'wpcap_refund_order', 'wpcap_refund_order_' . $order->order_id );
			Forms::hidden( 'order_id', $order->order_id );
			Forms::hidden( 'idempotency_key', wp_generate_uuid4() );
		}
		?>
		<table class="widefat striped">
			<thead><tr>
				<?php if ( $can_refund ) : ?><td class="check-column"></td><?php endif; ?>
				<th><?php esc_html_e( 'Ticket', 'wp-pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Art', 'wp-pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Status', 'wp-pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Teilnehmer', 'wp-pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Angaben', 'wp-pass-captain' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $tickets ) : ?><tr><td colspan="6"><?php esc_html_e( 'Noch keine Tickets ausgestellt.', 'wp-pass-captain' ); ?></td></tr><?php endif; ?>
			<?php foreach ( $tickets as $index => $ticket ) :
				$email = '' !== (string) $ticket->assigned_email ? $ticket->assigned_email : $ticket->attendee_email;
				?>
				<tr>
					<?php if ( $can_refund ) : ?>
						<th class="check-column"><?php if ( isset( $refundable[ $index ] ) ) : ?><input type="checkbox" name="ticket_ids[]" value="<?php echo esc_attr( $ticket->ticket_id ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: ticket id */ __( 'Ticket %s erstatten', 'wp-pass-captain' ), $ticket->public_id ) ); ?>"><?php endif; ?></th>
					<?php endif; ?>
					<td><a href="<?php echo esc_url( Forms::page_url( TicketsPage::SLUG, array( 's' => $ticket->public_id ) ) ); ?>"><code><?php echo esc_html( $ticket->public_id ); ?></code></a></td>
					<td><?php echo esc_html( $ticket->event_name . ' — ' . $ticket->ticket_name ); ?></td>
					<td><?php echo esc_html( Forms::status_label( $ticket->status ) . ( (int) $ticket->refunded ? ' (' . __( 'erstattet', 'wp-pass-captain' ) . ')' : ( in_array( (int) $ticket->ticket_id, $in_progress, true ) ? ' (' . __( 'Erstattung läuft', 'wp-pass-captain' ) . ')' : '' ) ) ); ?></td>
					<td><?php echo esc_html( trim( (string) $ticket->attendee_name . ' ' . ( $email ? '<' . $email . '>' : '' ) ) ?: '—' ); ?></td>
					<td>
						<?php foreach ( $ticket->answers as $label => $answer ) : ?>
							<div><strong><?php echo esc_html( $label ); ?>:</strong> <?php echo esc_html( $answer ); ?></div>
						<?php endforeach; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $can_refund ) : ?>
			<h3><?php esc_html_e( 'Erstatten', 'wp-pass-captain' ); ?></h3>
			<p>
				<label><input type="checkbox" name="refund_all" value="1"> <?php esc_html_e( 'Alle offenen Tickets erstatten (Restbetrag der Bestellung)', 'wp-pass-captain' ); ?></label><br>
				<label><?php esc_html_e( 'Grund', 'wp-pass-captain' ); ?> <input type="text" name="reason" class="regular-text" required maxlength="255"></label>
			</p>
			<?php if ( 'manual' === $order->payment_provider ) : ?>
				<p class="description"><?php esc_html_e( 'Manuelle Zahlungen: Der Betrag muss außerhalb des Plugins zurücküberwiesen werden. Die Tickets werden widerrufen.', 'wp-pass-captain' ); ?></p>
			<?php endif; ?>
			<?php if ( (int) \PassCaptain\Settings::get( 'refund_approval_threshold' ) > 0 && ! current_user_can( Refunds::APPROVE_CAP ) ) : ?>
				<p class="description"><?php echo esc_html( sprintf( /* translators: %s: amount */ __( 'Übersteigt die Summe aller Erstattungen dieser Bestellung (inklusive laufender und wartender) %s, ist eine zweite Freigabe nötig.', 'wp-pass-captain' ), Forms::money( (int) \PassCaptain\Settings::get( 'refund_approval_threshold' ), $currency ) ) ); ?></p>
			<?php endif; ?>
			<button class="button button-primary" type="submit" onclick="return window.confirm('<?php echo esc_js( __( 'Ausgewählte Tickets wirklich erstatten?', 'wp-pass-captain' ) ); ?>')"><?php esc_html_e( 'Erstattung auslösen', 'wp-pass-captain' ); ?></button>
			</form>
		<?php endif; ?>

		<?php /** Extra sections on the order detail page (e.g. ticket exchange). */ do_action( 'wpcap_admin_order_detail', $order, $tickets ); ?>
		<h2><?php esc_html_e( 'Erstattungen', 'wp-pass-captain' ); ?></h2>
		<?php self::render_refund_table( $refunds, $currency, false ); ?>
		<?php
	}

	private static function render_actions( $order ) {
		$can_mark_paid = 'manual' === $order->payment_provider && in_array( $order->payment_status, array( 'pending', 'expired' ), true ) && ! in_array( $order->status, array( 'cancelled', 'refunded' ), true );
		$can_cancel = 'pending' === $order->payment_status && 'pending_payment' === $order->status;
		$can_resend = 'paid' === $order->payment_status;
		if ( ! $can_mark_paid && ! $can_cancel && ! $can_resend ) { return; }
		echo '<h2>' . esc_html__( 'Aktionen', 'wp-pass-captain' ) . '</h2><p>';
		if ( $can_mark_paid ) {
			Forms::open_form( 'wpcap_confirm_payment', 'wpcap_confirm_payment_' . $order->order_id, 'style="display:inline"' );
			Forms::hidden( 'order_id', $order->order_id );
			echo '<button class="button button-primary" type="submit" onclick="return window.confirm(\'' . esc_js( __( 'Zahlungseingang bestätigen und Tickets ausstellen?', 'wp-pass-captain' ) ) . '\')">' . esc_html__( 'Als bezahlt markieren', 'wp-pass-captain' ) . '</button></form> ';
		}
		if ( $can_cancel ) {
			Forms::open_form( 'wpcap_cancel_order', 'wpcap_cancel_order_' . $order->order_id, 'style="display:inline"' );
			Forms::hidden( 'order_id', $order->order_id );
			echo '<input type="text" name="reason" placeholder="' . esc_attr__( 'Grund (optional)', 'wp-pass-captain' ) . '" maxlength="255"> ';
			echo '<button class="button" type="submit" onclick="return window.confirm(\'' . esc_js( __( 'Unbezahlte Bestellung stornieren und Reservierung freigeben?', 'wp-pass-captain' ) ) . '\')">' . esc_html__( 'Stornieren', 'wp-pass-captain' ) . '</button></form> ';
		}
		if ( $can_resend ) {
			Forms::open_form( 'wpcap_resend_tickets', 'wpcap_resend_tickets_' . $order->order_id, 'style="display:inline"' );
			Forms::hidden( 'order_id', $order->order_id );
			echo '<button class="button" type="submit" onclick="return window.confirm(\'' . esc_js( __( 'Tickets erneut senden? Die bisherigen Ticketcodes werden dabei ungültig.', 'wp-pass-captain' ) ) . '\')">' . esc_html__( 'Tickets erneut senden', 'wp-pass-captain' ) . '</button></form>';
		}
		echo '</p>';
	}

	/**
	 * Refund table with approve/reject controls for pending refunds.
	 *
	 * @param array[] $refunds  Refund rows (arrays; may contain order_public_id/event_names).
	 * @param string  $currency Fallback currency.
	 * @param bool    $queue    Whether rendered in the refund queue (adds order columns).
	 */
	public static function render_refund_table( array $refunds, $currency, $queue ) {
		$can_approve = current_user_can( Refunds::APPROVE_CAP );
		$cols = $queue ? 9 : 7;
		?>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Datum', 'wp-pass-captain' ); ?></th>
				<?php if ( $queue ) : ?><th><?php esc_html_e( 'Bestellung', 'wp-pass-captain' ); ?></th><th><?php esc_html_e( 'Event', 'wp-pass-captain' ); ?></th><?php endif; ?>
				<th><?php esc_html_e( 'Betrag', 'wp-pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Tickets', 'wp-pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Grund', 'wp-pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Status', 'wp-pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Angefordert / freigegeben von', 'wp-pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Aktion', 'wp-pass-captain' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $refunds ) : ?><tr><td colspan="<?php echo esc_attr( $cols ); ?>"><?php esc_html_e( 'Keine Erstattungen.', 'wp-pass-captain' ); ?></td></tr><?php endif; ?>
			<?php foreach ( $refunds as $refund ) :
				$refund = (array) $refund;
				$ticket_count = count( (array) json_decode( (string) $refund['ticket_ids'], true ) );
				?>
				<tr>
					<td><?php echo esc_html( Forms::datetime( $refund['created_at'], wp_timezone_string(), false ) ); ?></td>
					<?php if ( $queue ) : ?>
						<td><a href="<?php echo esc_url( self::order_url( $refund['order_id'] ) ); ?>"><code><?php echo esc_html( substr( (string) ( $refund['order_public_id'] ?? '' ), 0, 12 ) ); ?>…</code></a><br><small><?php echo esc_html( (string) ( $refund['customer_email'] ?? '' ) ); ?></small></td>
						<td><?php echo esc_html( (string) ( $refund['event_names'] ?? '' ) ); ?></td>
					<?php endif; ?>
					<td><?php echo esc_html( Forms::money( $refund['amount_minor'], $refund['currency'] ?: $currency ) ); ?><br><small><?php echo esc_html( sprintf( /* translators: %s: tax amount */ __( 'davon Steuer %s', 'wp-pass-captain' ), Forms::money( $refund['tax_minor'], $refund['currency'] ?: $currency ) ) ); ?></small></td>
					<td><?php echo esc_html( (string) $ticket_count ); ?></td>
					<td><?php echo esc_html( $refund['reason'] ); ?></td>
					<td><?php echo esc_html( Forms::status_label( $refund['status'] ) ); ?><?php if ( '' !== (string) $refund['error_message'] ) : ?><br><small><?php echo esc_html( $refund['error_message'] ); ?></small><?php endif; ?><?php if ( '' !== (string) $refund['provider_reference'] ) : ?><br><small><?php echo esc_html( $refund['provider_reference'] ); ?></small><?php endif; ?></td>
					<td><?php echo esc_html( Forms::user_label( $refund['requested_by'] ) . ' / ' . Forms::user_label( $refund['approved_by'] ) ); ?></td>
					<td>
						<?php if ( 'pending_approval' === $refund['status'] ) : ?>
							<?php if ( ! $can_approve ) : ?>
								<?php esc_html_e( 'Wartet auf Freigabe', 'wp-pass-captain' ); ?>
							<?php elseif ( (int) $refund['requested_by'] === get_current_user_id() ) : ?>
								<em><?php esc_html_e( 'Freigabe durch eine andere Person erforderlich', 'wp-pass-captain' ); ?></em>
							<?php else : ?>
								<?php Forms::open_form( 'wpcap_approve_refund', 'wpcap_approve_refund_' . $refund['refund_id'], 'style="display:inline"' ); Forms::hidden( 'refund_id', $refund['refund_id'] ); Forms::hidden( 'order_id', $refund['order_id'] ); if ( $queue ) { Forms::hidden( 'return', 'queue' ); } ?>
								<button class="button button-primary" type="submit" onclick="return window.confirm('<?php echo esc_js( __( 'Erstattung freigeben und auszahlen?', 'wp-pass-captain' ) ); ?>')"><?php esc_html_e( 'Freigeben', 'wp-pass-captain' ); ?></button>
								</form>
								<?php Forms::open_form( 'wpcap_reject_refund', 'wpcap_reject_refund_' . $refund['refund_id'], 'style="display:inline"' ); Forms::hidden( 'refund_id', $refund['refund_id'] ); Forms::hidden( 'order_id', $refund['order_id'] ); if ( $queue ) { Forms::hidden( 'return', 'queue' ); } ?>
								<input type="text" name="reason" required maxlength="255" placeholder="<?php esc_attr_e( 'Grund der Ablehnung', 'wp-pass-captain' ); ?>">
								<button class="button" type="submit"><?php esc_html_e( 'Ablehnen', 'wp-pass-captain' ); ?></button>
								</form>
							<?php endif; ?>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}

<?php
/**
 * A638: fail closed for empty event sets – issue_invoice refuses an order without ticket lines for
 * everyone but administrators; a device restriction without events never records conflicts.
 */
// phpcs:ignoreFile
use PassCaptain\Rest\Controller;

global $wpdb;
$p = $wpdb->prefix;
echo "A638: empty event sets fail closed\n";
$x1_now = gmdate( 'Y-m-d H:i:s' );
$x1_public = bin2hex( random_bytes( 16 ) );
$wpdb->insert( "{$p}wpcap_orders", array( 'public_id' => $x1_public, 'idempotency_key' => hash( 'sha256', 'x1-a638-' . uniqid() ), 'customer_email' => 'x1-a638@example.com', 'status' => 'complete', 'payment_status' => 'paid', 'currency' => 'EUR', 'total_minor' => 0, 'price_snapshot' => '[]', 'created_at' => $x1_now, 'updated_at' => $x1_now ) );
$x1_req = new WP_REST_Request( 'POST', '/pass-captain/v1/orders/' . $x1_public . '/invoice' );
$x1_req['public_id'] = $x1_public;
$GLOBALS['wpcap_test_user_id'] = 638;
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$x1_staff = Controller::issue_invoice( $x1_req );
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 1;
check( 'A638: staff cannot issue an invoice for an order without ticket lines (403)', is_wp_error( $x1_staff ) && 'wpcap_invoice_forbidden' === $x1_staff->get_error_code() && 403 === $x1_staff->get_error_data()['status'] );
check( 'A638: nothing was issued', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_invoices i INNER JOIN {$p}wpcap_orders o ON o.order_id=i.order_id WHERE o.public_id=%s", $x1_public ) ) );
$x1_admin = Controller::issue_invoice( $x1_req );
check( 'A638: an administrator is not refused by the empty-set rule', ! ( is_wp_error( $x1_admin ) && 'wpcap_invoice_forbidden' === $x1_admin->get_error_code() ) );
$x1_src = (string) file_get_contents( WPCAP_PATH . 'src/Checkin.php' );
check( 'A638: offline conflicts of a device without events are not recorded (no "$context[\'event_ids\'] &&" shortcut)', false === strpos( $x1_src, "is_array( \$context['event_ids'] ) && \$context['event_ids'] && ! in_array(" ) );

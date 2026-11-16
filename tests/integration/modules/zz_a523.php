<?php
/**
 * A523: sales model and fee VAT rate are versioned with the organizer's terms; a settlement uses the version
 * valid at the end of its period, so a later change does not rewrite open settlements.
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

use PassCaptain\Partners\Repository;
use PassCaptain\Partners\Settlements;

echo "A523: versioned sales model on settlements\n";
global $wpdb;
$p = $wpdb->prefix;
check( 'A523: schema 3.18.2 versions sales model and fee VAT rate', Repository::model_terms_ready() );
$a523_pid = 900000 + random_int( 1, 99999 );
$a523_end = '2026-10-31 22:59:59';
$wpdb->insert( "{$p}wpcap_partner_terms_history", array( 'partner_id' => $a523_pid, 'valid_from' => null, 'commission_bp' => 1000, 'fee_per_ticket_minor' => 50, 'refund_policy' => 'reverse_all', 'sales_model' => 'agent', 'fee_tax_rate_bp' => 2000, 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$wpdb->insert( "{$p}wpcap_partner_terms_history", array( 'partner_id' => $a523_pid, 'valid_from' => '2026-11-10 08:00:00', 'commission_bp' => 1500, 'fee_per_ticket_minor' => 50, 'refund_policy' => 'reverse_all', 'sales_model' => 'own_name', 'fee_tax_rate_bp' => 1000, 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
// Current partner row: already the new terms.
$a523_partner = (object) array( 'partner_id' => $a523_pid, 'commission_bp' => 1500, 'fee_per_ticket_minor' => 50, 'refund_policy' => 'reverse_all', 'sales_model' => 'own_name', 'fee_tax_rate_bp' => 1000, 'vat_id' => '', 'country' => 'AT', 'currency' => 'EUR', 'settlement_start' => '' );
$a523_old = Repository::terms_at( $a523_partner, $a523_end );
$a523_new = Repository::terms_at( $a523_partner, '2026-11-20 00:00:00' );
check( 'A523: the version valid at the period end keeps the old sales model and fee VAT', 'agent' === $a523_old['sales_model'] && 2000 === $a523_old['fee_tax_rate_bp'] && 1000 === $a523_old['commission_bp'] );
check( 'A523: later versions apply after the change', 'own_name' === $a523_new['sales_model'] && 1000 === $a523_new['fee_tax_rate_bp'] );
$a523_lines = array( array( 'kind' => 'sale', 'item_key' => 'x', 'event_id' => 1, 'event_name' => 'E', 'tax_rate_bp' => 2000, 'tickets' => 1, 'gross_minor' => 12000, 'tax_minor' => 2000, 'net_minor' => 10000, 'commission_minor' => 1000, 'fee_minor' => 50, 'origin_period' => '2026-10', 'occurred_at' => '2026-10-15 10:00:00', 'order_id' => 1, 'refund_id' => 0, 'item_id' => 1 ) );
$a523_t_old = Settlements::totals( $a523_lines, $a523_partner, array( 'terms_at' => $a523_end ) );
$a523_t_now = Settlements::totals( $a523_lines, $a523_partner, array() );
check( 'A523: the October settlement uses the October model and VAT rate', 'agent' === $a523_t_old['sales_model'] && 210 === (int) $a523_t_old['platform_fee_tax'] || ( ! empty( $a523_t_old['fee_reverse_charge'] ) || ! empty( $a523_t_old['fee_small_business'] ) ) );
check( 'A523: without a period the current terms apply (as before)', 'own_name' === $a523_t_now['sales_model'] );
check( 'A523: compute() passes the period end', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Partners/Settlements.php' ), "'terms_at' => \$end" ) );
$wpdb->delete( "{$p}wpcap_partner_terms_history", array( 'partner_id' => $a523_pid ) );

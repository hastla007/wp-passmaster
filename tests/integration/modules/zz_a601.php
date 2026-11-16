<?php
/**
 * A601: GiroCode (EPC069-12 QR) for SEPA transfers in EUR next to the bank details (checkout answer, order
 * portal) plus copy buttons in the checkout.
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

use PassCaptain\Payments\BankTransfer;

echo "A601: GiroCode and copy buttons\n";
$a601 = array( 'currency' => 'EUR', 'amount_minor' => 4250, 'iban' => 'DE89 3704 0044 0532 0130 00', 'bic' => 'COBADEFFXXX', 'recipient' => 'Theater Muster GmbH', 'remittance' => 'Bestellung 3F2A-9C1E' );
$a601_p = explode( "\n", BankTransfer::epc_payload( $a601 ) );
check( 'A601: EPC payload: service tag, version 002, UTF-8, SCT, BIC, name, IBAN, amount, remittance', array( 'BCD', '002', '1', 'SCT', 'COBADEFFXXX', 'Theater Muster GmbH', 'DE89370400440532013000', 'EUR42.50', '', '', 'Bestellung 3F2A-9C1E' ) === $a601_p );
check( 'A601: no GiroCode for other currencies or without IBAN/amount', '' === BankTransfer::epc_payload( array( 'currency' => 'CHF' ) + $a601 ) && '' === BankTransfer::epc_payload( array( 'iban' => '' ) + $a601 ) && '' === BankTransfer::epc_payload( array( 'amount_minor' => 0 ) + $a601 ) );
check( 'A601: name and remittance are cut to the EPC limits (70/140)', 70 === mb_strlen( explode( "\n", BankTransfer::epc_payload( array( 'recipient' => str_repeat( 'x', 90 ) ) + $a601 ) )[5] ) );
$a601_saved = array( \PassCaptain\Settings::get( 'bank_iban' ), \PassCaptain\Settings::get( 'bank_recipient' ) );
\PassCaptain\Settings::update( array( 'bank_iban' => 'DE89370400440532013000', 'bank_recipient' => 'Theater Muster GmbH' ) );
$a601_d = BankTransfer::details( (object) array( 'order_id' => 0, 'public_id' => str_repeat( 'a', 32 ), 'total_minor' => 1500, 'currency' => 'EUR' ) );
check( 'A601: the bank details carry the GiroCode as SVG data URI', 0 === strpos( (string) ( $a601_d['girocode'] ?? '' ), 'data:image/svg+xml;base64,' ) && false !== strpos( (string) base64_decode( substr( $a601_d['girocode'], 26 ) ), '<svg' ) );
\PassCaptain\Settings::update( array( 'bank_iban' => (string) $a601_saved[0], 'bank_recipient' => (string) $a601_saved[1] ) );
check( 'A601: portal and checkout show it, the checkout has copy buttons', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/OrderPortal.php' ), 'wpcap-girocode' ) && false !== strpos( (string) file_get_contents( WPCAP_PATH . 'assets/checkout.js' ), 'navigator.clipboard.writeText' ) );

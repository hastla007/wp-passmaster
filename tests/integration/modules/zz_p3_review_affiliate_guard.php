<?php
/**
 * TODO "Affiliate Captain nur für spätere aggregierte Qualitätsberichte vorsehen; Review-Klicks/-Bewertungen
 * erzeugen keine Partnerprovision": guard that the Affiliate Captain adapter reports only sales and their
 * reversal (order paid, refund, full refund, cancellation) and listens to no attendance, check-in or review
 * hook, and that the review contract code never calls Affiliate Captain. Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Integrations\AffiliateCaptain\Adapter;

echo "Review/Affiliate guard: no partner commission from attendance or reviews\n";
$p3r_before = $GLOBALS['wpcap_test_hooks'];
$GLOBALS['wpcap_test_hooks'] = array();
Adapter::register();
$p3r_hooks = array();
foreach ( $GLOBALS['wpcap_test_hooks'] as $p3r_hook => $p3r_prios ) {
	foreach ( $p3r_prios as $p3r_cbs ) {
		foreach ( $p3r_cbs as $p3r_cb ) {
			if ( is_array( $p3r_cb[0] ) && Adapter::class === $p3r_cb[0][0] ) { $p3r_hooks[ $p3r_hook ] = true; }
		}
	}
}
$GLOBALS['wpcap_test_hooks'] = $p3r_before;
$p3r_business = array_values( array_intersect( array_keys( $p3r_hooks ), array( 'wpcap_order_paid', 'wpcap_refund_completed', 'wpcap_order_refunded', 'wpcap_order_cancelled' ) ) );
sort( $p3r_business );
check( 'Review/Affiliate guard: the adapter reports sales and their reversal (paid, refund, full refund, cancellation)', array( 'wpcap_order_cancelled', 'wpcap_order_paid', 'wpcap_order_refunded', 'wpcap_refund_completed' ) === $p3r_business );
$p3r_forbidden = preg_grep( '/check(ed)?_?in|checkout_done|attendance|review|experience|no_show|ticket_(revoked|transferred)|occurrence|event_cancelled/', array_keys( $p3r_hooks ) );
check( 'Review/Affiliate guard: the adapter listens to no check-in, attendance, review or event hook' . ( $p3r_forbidden ? ' – ' . implode( ', ', $p3r_forbidden ) : '' ), ! $p3r_forbidden );

$p3r_review_src = '';
foreach ( glob( WPCAP_PATH . 'src/Review/*.php' ) as $p3r_file ) { $p3r_review_src .= (string) file_get_contents( $p3r_file ); }
check( 'Review/Affiliate guard: the review contract code calls no Affiliate Captain function or adapter', '' !== $p3r_review_src && ! preg_match( '/wpac_|AffiliateCaptain/', $p3r_review_src ) );
$p3r_adapter_src = (string) file_get_contents( WPCAP_PATH . 'src/Integrations/AffiliateCaptain/Adapter.php' );
preg_match_all( "/self::call\(\s*'(wpac_[a-z_]+)'/", $p3r_adapter_src, $p3r_m );
$p3r_calls = array_values( array_unique( $p3r_m[1] ) );
sort( $p3r_calls );
check( 'Review/Affiliate guard: the only commission calls are wpac_record_sale and wpac_record_refund', array( 'wpac_record_refund', 'wpac_record_sale' ) === $p3r_calls );
check( 'Review/Affiliate guard: docs/EXTENSIONS.md states that attendance and reviews create no commission', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'docs/EXTENSIONS.md' ), 'Einlass, Teilnahmebestätigung und Bewertungen erzeugen keine Partnerprovision' ) );

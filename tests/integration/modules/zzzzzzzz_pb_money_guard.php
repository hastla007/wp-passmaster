<?php
/**
 * Guard for TODO L892 ("Erstattungs-/Freigabe-Mails ohne fest codiertes /100") and L915 ("eine einheitliche
 * ISO-4217-Nachkommastellen-Tabelle für Forms, OrderAdapter, CartPricing und Rechnungen"):
 * - no shipped PHP or JS file converts a stored minor amount with a fixed "/ 100" or "* 100" (the two
 *   documented exceptions are listed below with their reason);
 * - every place that needs the minor unit asks PassCaptain\Money (the one ISO 4217 table), and the
 *   browser copy in assets/tracking.js carries the same lists;
 * - refund mail amounts are formatted per currency (JPY without, KWD with three decimals).
 * Included from run.php (uses check()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Money;

echo "Money guard: no fixed /100, one ISO 4217 minor-unit table\n";

$pbm_root = rtrim( WPCAP_PATH, '/' ) . '/';
$pbm_files = array();
foreach ( array( 'src', 'templates', 'assets' ) as $pbm_dir ) {
	$pbm_it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $pbm_root . $pbm_dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $pbm_it as $pbm_file ) {
		$pbm_path = str_replace( '\\', '/', substr( (string) $pbm_file, strlen( $pbm_root ) ) );
		if ( ! preg_match( '/\.(php|js)$/', $pbm_path ) || false !== strpos( $pbm_path, '/vendor/' ) || preg_match( '/\.min\.js$/', $pbm_path ) ) { continue; }
		$pbm_files[] = $pbm_path;
	}
}
sort( $pbm_files );

// A minor amount (variable/property/data attribute named *minor*, *price*, *amount*, *total*) divided or
// multiplied by a literal 100. Basis points ("_bp", rates, shares, percentages) are not money and excluded.
$pbm_pattern = '/(?:minor|price|amount|total)[\w\]\)\(\'"\s.>|-]{0,40}[\/*]\s*100\b/i';
$pbm_allowed = array(
	// EPC/GiroCode payload: returns '' for every currency but EUR, and EPC demands "EUR12.34".
	'src/Payments/BankTransfer.php' => "'EUR' . number_format( \$minor / 100, 2, '.', '' )",
	// Stripe's own unit for ISK/UGX ("zero-decimal in display, two decimals in the API"), SCALED_ZERO_DECIMAL only.
	'src/Payments/StripeGateway.php' => 'return (int) $minor * 100;',
);
$pbm_hits = array();
$pbm_allowed_seen = array();
foreach ( $pbm_files as $pbm_path ) {
	foreach ( preg_split( '/\R/', (string) file_get_contents( $pbm_root . $pbm_path ) ) as $pbm_no => $pbm_line ) {
		if ( ! preg_match( $pbm_pattern, $pbm_line ) || preg_match( '/_bp\b|\bbp\b|rate|percent|share|pct/i', $pbm_line ) ) { continue; }
		if ( isset( $pbm_allowed[ $pbm_path ] ) && false !== strpos( $pbm_line, $pbm_allowed[ $pbm_path ] ) ) { $pbm_allowed_seen[ $pbm_path ] = true; continue; }
		$pbm_hits[] = $pbm_path . ':' . ( $pbm_no + 1 );
	}
}
check( 'PB money guard: scanned the shipped PHP/JS files (src, templates, assets)', count( $pbm_files ) > 200 && in_array( 'assets/tracking.js', $pbm_files, true ) && in_array( 'src/Refunds.php', $pbm_files, true ) );
check( 'PB money guard: no minor amount is converted with a fixed /100 or *100' . ( $pbm_hits ? ' – found: ' . implode( ', ', $pbm_hits ) : '' ), array() === $pbm_hits );
check( 'PB money guard: the two documented exceptions still exist (allowlist not stale)', count( $pbm_allowed ) === count( $pbm_allowed_seen ) );
check( 'PB money guard: the EPC exception is limited to EUR', '' === PassCaptain\Payments\BankTransfer::epc_payload( array( 'currency' => 'JPY', 'amount_minor' => 1500, 'iban' => 'AT611904300234573201', 'recipient' => 'Testverein' ) ) );

// One table: Money is the source; Forms, the WooCommerce adapter, CartPricing, invoices and the checkout
// configuration read it.
check( 'PB ISO 4217: KWD, BHD, OMR (and IQD, JOD, LYD, TND) have three decimals', array( 3, 3, 3, 3, 3, 3, 3 ) === array_map( array( Money::class, 'decimals' ), array( 'KWD', 'BHD', 'OMR', 'IQD', 'JOD', 'LYD', 'TND' ) ) );
check( 'PB ISO 4217: JPY/KRW/ISK without, EUR/CHF with two decimals; codes are case-insensitive', 0 === Money::decimals( 'JPY' ) && 0 === Money::decimals( 'krw' ) && 0 === Money::decimals( 'ISK' ) && 2 === Money::decimals( 'EUR' ) && 2 === Money::decimals( 'chf' ) );
check( 'PB ISO 4217: the WooCommerce adapter (and so CartPricing) asks Money', 3 === PassCaptain\Integrations\WooCommerce\OrderAdapter::currency_decimals( 'KWD' ) && 0 === PassCaptain\Integrations\WooCommerce\OrderAdapter::currency_decimals( 'JPY' ) && false !== strpos( (string) file_get_contents( $pbm_root . 'src/Integrations/WooCommerce/CartPricing.php' ), 'OrderAdapter::currency_decimals(' ) );
check( 'PB ISO 4217: admin money inputs (Forms) delegate to Money', 3 === PassCaptain\Admin\Forms::currency_decimals( 'OMR' ) && 0 === PassCaptain\Admin\Forms::currency_decimals( 'JPY' ) );
$pbm_js_cfg = PassCaptain\Frontend\Format::currency_decimals();
check( 'PB ISO 4217: the checkout configuration lists every non-two-decimal currency with Money\'s value', 3 === ( $pbm_js_cfg['KWD'] ?? null ) && 3 === ( $pbm_js_cfg['BHD'] ?? null ) && 0 === ( $pbm_js_cfg['JPY'] ?? null ) && 2 === ( $pbm_js_cfg['EUR'] ?? null ) );
check( 'PB ISO 4217: invoices and the invoice PDF format through Money', false !== strpos( (string) file_get_contents( $pbm_root . 'src/Invoices.php' ), 'Money::format(' ) && false !== strpos( (string) file_get_contents( $pbm_root . 'src/Printing/InvoicePdf.php' ), 'Money::format(' ) );

// The browser copy of the table (tracking events) must not drift from Money.
$pbm_js = (string) file_get_contents( $pbm_root . 'assets/tracking.js' );
$pbm_js_list = static function ( $name ) use ( $pbm_js ) {
	if ( ! preg_match( '/const ' . $name . ' = \[([^\]]*)\]/', $pbm_js, $m ) ) { return null; }
	preg_match_all( "/'([A-Z]{3})'/", $m[1], $codes );
	return $codes[1];
};
check( 'PB ISO 4217: assets/tracking.js carries the same zero- and three-decimal lists as Money', Money::ZERO_DECIMALS === $pbm_js_list( 'ZERO_DECIMALS' ) && Money::THREE_DECIMALS === $pbm_js_list( 'THREE_DECIMALS' ) );

// Refund and cancellation-request mails: the {refund_amount} placeholder is Money::format() of the minor amount.
foreach ( array( 'src/Refunds.php', 'src/PortalSelfService.php' ) as $pbm_mail_src ) {
	check( "PB refund mails: $pbm_mail_src fills {refund_amount} with Money::format()", 1 === preg_match( "/'refund_amount' => static function \(\) use \( \\\$amount_minor, \\\$currency \) \{ return Money::format\( \\\$amount_minor, \\\$currency \); \}/", (string) file_get_contents( $pbm_root . $pbm_mail_src ) ) );
}
$pbm_jpy = Money::format( 1500, 'JPY' );
$pbm_kwd = Money::format( 12345, 'KWD' );
check( 'PB refund mails: 1500 JPY reads "1.500 JPY"-like (no cents), 12345 fils read 12,345 KWD (three decimals)', 1 === preg_match( '/^1\D?500 JPY$/', $pbm_jpy ) && 1 === preg_match( '/^12\D345 KWD$/', $pbm_kwd ) && 1 === preg_match( '/^25\D00 EUR$/', Money::format( 2500, 'EUR' ) ) );

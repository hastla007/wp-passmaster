<?php
/**
 * SET-PC-03 (number patterns of the document sequences), SET-PC-15 (service date of invoice lines without
 * an occurrence), SET-PC-18 (minimum age rule) and SET-PC-37 (DATEV accounts as a table). Every new
 * setting defaults to the former behaviour.
 */
use PassCaptain\Admin\SettingsPage;
use PassCaptain\AdmissionChecks;
use PassCaptain\DatevAccounts;
use PassCaptain\DocumentNumbers;
use PassCaptain\Invoices;
use PassCaptain\Ops\SettingsCli;
use PassCaptain\Orders;
use PassCaptain\Settings;
use PassCaptain\SettingsManager;
use PassCaptain\SettingsSchema;

echo "SET-PC-03/-15/-18/-37: number patterns, service date fallback, minimum age, DATEV table\n";
global $wpdb;
$p = $wpdb->prefix;

$spx_option = get_option( Settings::OPTION, array() );
$spx_new_keys = array( 'datev_accounts', 'invoice_service_date_fallback', 'default_min_age', 'min_age_checkbox', 'min_age_notice_text' );
foreach ( array_keys( DocumentNumbers::KINDS ) as $spx_kind ) {
	foreach ( array( 'pattern', 'digits', 'start', 'yearly' ) as $spx_part ) { $spx_new_keys[] = $spx_kind . '_number_' . $spx_part; }
}
// Start from the defaults of the new keys.
update_option( Settings::OPTION, is_array( $spx_option ) ? array_diff_key( $spx_option, array_flip( $spx_new_keys ) ) : array() );
Settings::flush();
delete_option( DocumentNumbers::SKIPS_OPTION );
delete_option( DocumentNumbers::PATTERNS_OPTION );
wp_set_current_user( 1 );

/* ------------------------------------------------------------------ Register */

$spx_defaults = Settings::defaults();
$spx_wrong = array();
foreach ( $spx_new_keys as $spx_key ) {
	$spx_entry = SettingsSchema::get( $spx_key );
	if ( ! array_key_exists( $spx_key, $spx_defaults ) || ! $spx_entry || empty( $spx_entry['described'] ) || '' === trim( (string) $spx_entry['help'] ) || empty( $spx_entry['legal'] ) || 'settings' !== $spx_entry['page'] ) { $spx_wrong[] = $spx_key; }
}
check( 'SET-PC-03/-15/-18/-37: every new setting is registered with label, help, legal flag and a field on the settings screen' . ( $spx_wrong ? ' – ' . implode( ', ', $spx_wrong ) : '' ), array() === $spx_wrong );
check( 'SET-PC-03/-15/-18/-37: defaults are the former behaviour',
	'none' === $spx_defaults['invoice_service_date_fallback'] && 0 === $spx_defaults['default_min_age'] && 0 === $spx_defaults['min_age_checkbox'] && '' === $spx_defaults['min_age_notice_text'] && array() === $spx_defaults['datev_accounts']
	&& '{prefix}-{year}-{number}' === $spx_defaults['invoice_number_pattern'] && 6 === $spx_defaults['credit_number_digits'] && 0 === $spx_defaults['receipt_number_start'] && 1 === $spx_defaults['self_billing_number_yearly'] );
$spx_sections = SettingsPage::sections();
check( 'SET-PC-03/-37: number patterns and DATEV table are sections of the invoices tab, the minimum age one of the legal tab',
	'invoices' === $spx_sections['numbers']['tab'] && 'invoices' === $spx_sections['datev']['tab'] && 'legal' === $spx_sections['min_age']['tab'] && isset( $spx_sections['seller']['fields']['invoice_service_date_fallback'], $spx_sections['datev']['fields']['datev_contra_account'], $spx_sections['numbers']['fields']['settlement_number_start'] ) );

/* ------------------------------------------------------------------ SET-PC-03: number patterns */

$spx_year = 2091; // A far year: its counter rows belong to this module only.
// Continuous counters of other modules (e.g. box office receipts) stay: removing them would hand out their numbers again.
$spx_keep_series = array_map( 'strval', (array) $wpdb->get_col( "SELECT series FROM {$p}wpcap_document_sequences WHERE year=0" ) );
$spx_clean_rows = static function () use ( $wpdb, $p, $spx_year, $spx_keep_series ) {
	$wpdb->query( "DELETE FROM {$p}wpcap_invoice_sequences WHERE year IN (0," . $spx_year . ',' . ( $spx_year + 1 ) . ',' . ( $spx_year + 2 ) . ')' );
	$keep = $spx_keep_series ? ' AND series NOT IN (' . implode( ',', array_map( static function ( $series ) use ( $wpdb ) { return $wpdb->prepare( '%s', $series ); }, $spx_keep_series ) ) . ')' : '';
	$wpdb->query( "DELETE FROM {$p}wpcap_document_sequences WHERE year=0" . $keep ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prepared list.
};
$spx_clean_rows();
$spx_set = static function ( array $values ) { Settings::update( $values ); Settings::flush(); };
// Default settings: earlier modules may have saved the settings screen without these checkboxes (= 0).
$spx_set( array_fill_keys( array( 'invoice_number_yearly', 'credit_number_yearly', 'receipt_number_yearly', 'settlement_number_yearly', 'self_billing_number_yearly' ), 1 ) );

// Default settings: the next number is exactly the former format (old formula next to the new code).
$spx_old_invoice = static function ( $year ) use ( $wpdb, $p ) {
	$next = (int) $wpdb->get_var( $wpdb->prepare( "SELECT next_number FROM {$p}wpcap_invoice_sequences WHERE year=%d", $year ) );
	return sprintf( '%s-%d-%06d', preg_replace( '/[^A-Za-z0-9_-]/', '', (string) Settings::get( 'invoice_prefix' ) ) ?: 'WPC', $year, max( 1, $next ) );
};
$spx_old_document = static function ( $series, $prefix ) use ( $wpdb, $p ) {
	// A continuous counter of an earlier module is continued above (DocumentNumbers::reserve()).
	$next = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(next_number) FROM {$p}wpcap_document_sequences WHERE series=%s AND year IN (0,%d)", $series, Invoices::local_year() ) );
	return sprintf( '%s-%d-%06d', preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $prefix ) ?: 'DOC', Invoices::local_year(), max( 1, $next ) );
};
$spx_expected = array( 'invoice' => $spx_old_invoice( Invoices::local_year() ) );
$wpdb->query( 'START TRANSACTION' );
$spx_got = array( 'invoice' => Invoices::next_number( 0, true ) );
foreach ( array( 'credit' => 'RK', 'receipt' => 'BON', 'partner' => 'PA', 'credit-self' => 'GU', 'invoice-org-77' => 'ORG77' ) as $spx_series => $spx_prefix ) {
	$spx_expected[ $spx_series ] = $spx_old_document( $spx_series, $spx_prefix );
	$spx_got[ $spx_series ] = Invoices::next_document_number( $spx_series, $spx_prefix, true );
}
$wpdb->query( 'ROLLBACK' );
check( 'SET-PC-03: with default settings the next number of every sequence is identical to the former format (' . $spx_got['invoice'] . ', ' . $spx_got['credit'] . ')', $spx_expected === $spx_got );
check( 'SET-PC-03: the preview on the settings screen shows that number', $spx_expected['invoice'] === DocumentNumbers::preview( 'invoice' ) && $spx_expected['receipt'] === DocumentNumbers::preview( 'receipt' ) );

// Year change: every year starts at 1; the counter is yearly by default.
$spx_a = Invoices::next_number( $spx_year );
$spx_b = Invoices::next_number( $spx_year );
$spx_c = Invoices::next_number( $spx_year + 1 );
check( 'SET-PC-03: year change starts a new counter (' . $spx_a . ', ' . $spx_b . ', ' . $spx_c . ')', str_ends_with( $spx_a, '-2091-000001' ) && str_ends_with( $spx_b, '-2091-000002' ) && str_ends_with( $spx_c, '-2092-000001' ) );

// Pattern change mid-year: applies to the next number only, the counter goes on (no number twice).
$spx_set( array( 'invoice_number_pattern' => '{prefix}{yy}{number}', 'invoice_number_digits' => 4 ) );
$spx_d = Invoices::next_number( $spx_year );
check( 'SET-PC-03: a pattern change applies to the next number and continues the counter (' . $spx_d . ')', 'WPC910003' === $spx_d || str_ends_with( $spx_d, '910003' ) );
check( 'SET-PC-03: numbers of both patterns are read by the gap check without a false gap', array() === Invoices::number_gaps( array( $spx_a, $spx_b, $spx_d ) ) );
check( 'SET-PC-03: a real gap in the new pattern is still reported', array( 'WPC91#' => array( 'WPC910004' ) ) === Invoices::number_gaps( array( 'WPC910003', 'WPC910005' ) ) );

// Continuous counter: starts above every counter value the sequence ever reached.
$spx_set( array( 'invoice_number_yearly' => 0 ) );
$spx_highest = (int) $wpdb->get_var( "SELECT MAX(next_number) FROM {$p}wpcap_invoice_sequences" );
$spx_e = Invoices::next_number( $spx_year );
$spx_e_counter = (int) substr( $spx_e, -4 );
check( 'SET-PC-03: switching to a continuous counter never reuses a counter value', $spx_e_counter >= $spx_highest && $spx_e_counter >= 4 && null !== $wpdb->get_var( "SELECT next_number FROM {$p}wpcap_invoice_sequences WHERE year=0" ) );
// Back to yearly: continues above the continuous counter in that year and drops it.
$spx_set( array( 'invoice_number_yearly' => 1 ) );
$spx_f = Invoices::next_number( $spx_year );
check( 'SET-PC-03: switching back to yearly continues above the continuous counter and removes it', (int) substr( $spx_f, -4 ) === $spx_e_counter + 1 && null === $wpdb->get_var( "SELECT next_number FROM {$p}wpcap_invoice_sequences WHERE year=0" ) );
// A pattern without year never restarts (it would repeat numbers).
$spx_set( array( 'invoice_number_pattern' => '{prefix}-{number}' ) );
check( 'SET-PC-03: without {year}/{yy} the counter is continuous even with "jedes Jahr neu"', false === DocumentNumbers::config( 'invoice' )['yearly'] );
$spx_set( array( 'invoice_number_pattern' => '{prefix}-{year}-{number}', 'invoice_number_digits' => 6 ) );

// Start number: first number of a new counter and lower bound of the running one (skip recorded).
$spx_set( array( 'invoice_number_start' => 500 ) );
$spx_g = Invoices::next_number( $spx_year + 2 );
$spx_h = Invoices::next_number( $spx_year + 1 ); // Running counter at 2 → 500, 2..499 skipped on purpose.
check( 'SET-PC-03: start number begins a new counter (' . $spx_g . ') and raises a running one (' . $spx_h . ')', str_ends_with( $spx_g, '-2093-000500' ) && str_ends_with( $spx_h, '-2092-000500' ) );
check( 'SET-PC-03: the skipped numbers are recorded and not reported by the gap check', array() === Invoices::number_gaps( array( $spx_c, $spx_h ) ) && array( 'WPC-2092' => array( 'WPC-2092-000002' ) ) !== Invoices::number_gaps( array( $spx_c, $spx_h ) ) );
check( 'SET-PC-03: other gaps of that range are still reported', array( 'WPC-2092' => array( 'WPC-2092-000501' ) ) === Invoices::number_gaps( array( $spx_c, $spx_h, 'WPC-2092-000502' ) ) );
$spx_set( array( 'invoice_number_start' => 0 ) );
// Only upwards: a start number at or below the last issued number of the running counter is refused.
$wpdb->query( 'START TRANSACTION' );
Invoices::next_number( 0, true );
Invoices::next_number( 0, true );
$spx_last = DocumentNumbers::last_issued( 'invoice' );
$spx_low = SettingsSchema::validate_complete( 'invoice_number_start', $spx_last );
$spx_high = SettingsSchema::validate_complete( 'invoice_number_start', $spx_last + 10 );
$wpdb->query( 'ROLLBACK' );
check( 'SET-PC-03: the start number can only be raised above the last issued number (' . $spx_last . ')', $spx_last >= 2 && is_wp_error( $spx_low ) && 'wpcap_number_start' === $spx_low->get_error_code() && $spx_last + 10 === $spx_high && 0 === SettingsSchema::validate_complete( 'invoice_number_start', 0 ) );

// Pattern validation through the register (form, CLI, import).
$spx_bad = array( '{prefix}-{year}', '{number}-{number}', '{prefix}-{month}-{number}', 'R E{number}', 'ABCDEFGHIJKLM{number}' );
$spx_bad_ok = true;
foreach ( $spx_bad as $spx_pattern ) { $spx_bad_ok = $spx_bad_ok && is_wp_error( SettingsSchema::validate_complete( 'invoice_number_pattern', $spx_pattern ) ); }
check( 'SET-PC-03: invalid patterns are reported (no {number}, twice, unknown placeholder, other characters, too long)', $spx_bad_ok );
check( 'SET-PC-03: valid patterns pass, empty means the default', 'RE.{yy}_{number}' === SettingsSchema::validate_complete( 'invoice_number_pattern', 'RE.{yy}_{number}' ) && '{prefix}-{year}-{number}' === SettingsSchema::validate_complete( 'invoice_number_pattern', '' ) );
check( 'SET-PC-03: digits outside 1–10 are reported, not clamped', is_wp_error( SettingsSchema::validate( 'receipt_number_digits', 0 ) ) && is_wp_error( SettingsSchema::validate( 'receipt_number_digits', 11 ) ) && 8 === SettingsSchema::validate( 'receipt_number_digits', '8' ) );

// Other kinds: box office receipts, corrections, settlements follow their own pattern.
$spx_set( array( 'receipt_number_pattern' => '{prefix}{year}/{number}' ) );
check( 'SET-PC-03: a pattern with "/" is refused', '{prefix}-{year}-{number}' === DocumentNumbers::config( 'receipt' )['pattern'] );
$spx_set( array( 'receipt_number_pattern' => 'K{yy}-{number}', 'receipt_number_digits' => 5, 'credit_number_pattern' => '{prefix}{year}{number}', 'settlement_number_digits' => 3 ) );
$wpdb->query( 'START TRANSACTION' );
$spx_receipt = Invoices::next_document_number( 'receipt', 'BON', true );
$spx_credit = Invoices::next_document_number( 'credit', 'RK', true );
$spx_settlement = Invoices::next_document_number( 'partner', 'PA', true );
$spx_org = Invoices::next_document_number( 'credit-org-77', 'ORG77-K', true );
$wpdb->query( 'ROLLBACK' );
$spx_yy = sprintf( '%02d', Invoices::local_year() % 100 );
check( 'SET-PC-03: receipts, corrections and settlements use their own pattern, organizer ranges keep the fixed format (' . $spx_receipt . ', ' . $spx_credit . ', ' . $spx_settlement . ', ' . $spx_org . ')',
	(bool) preg_match( '/^K' . $spx_yy . '-\d{5,}$/', $spx_receipt ) && (bool) preg_match( '/^RK' . Invoices::local_year() . '\d{6,}$/', $spx_credit ) && (bool) preg_match( '/^PA-' . Invoices::local_year() . '-\d{3,}$/', $spx_settlement ) && (bool) preg_match( '/^ORG77-K-' . Invoices::local_year() . '-\d{6}$/', $spx_org ) );
$spx_set( array( 'receipt_number_pattern' => '{prefix}-{year}-{number}', 'receipt_number_digits' => 6, 'credit_number_pattern' => '{prefix}-{year}-{number}', 'settlement_number_digits' => 6 ) );

// Filter wp_pass_captain_invoice_number_v1.
$spx_ctx = null;
$spx_filter = static function ( $number, $context ) use ( &$spx_ctx ) { $spx_ctx = $context; return 'X' . $number . '<b>'; };
add_filter( 'wp_pass_captain_invoice_number_v1', $spx_filter, 10, 2 );
$spx_i = Invoices::next_number( $spx_year + 2 );
remove_filter( 'wp_pass_captain_invoice_number_v1', $spx_filter, 10 );
check( 'SET-PC-03: the filter wp_pass_captain_invoice_number_v1 gets the number with its context and its result is cleaned', 'XWPC-2093-000501b' === $spx_i && 'invoice' === ( $spx_ctx['kind'] ?? '' ) && 501 === ( $spx_ctx['number'] ?? 0 ) && 2093 === ( $spx_ctx['year'] ?? 0 ) );

// CLI and export/import go through the register.
$spx_cli_bad = SettingsCli::set_value( 'credit_number_pattern', '{prefix}' );
$spx_cli_ok = SettingsCli::set_value( 'credit_number_pattern', '{prefix}-{yy}-{number}' );
check( 'SET-PC-03: WP-CLI checks the pattern through the register', is_wp_error( $spx_cli_bad ) && '{prefix}-{yy}-{number}' === $spx_cli_ok );
$spx_export = SettingsManager::export();
$spx_export['settings']['credit_number_pattern'] = '{prefix}{number}{number}';
$spx_import_bad = SettingsManager::import( $spx_export, false );
$spx_export['settings']['credit_number_pattern'] = '{prefix}-{year}-{number}';
$spx_import_ok = SettingsManager::import( $spx_export, false );
check( 'SET-PC-03: export contains the patterns, an invalid import is refused, a valid one applied', isset( $spx_export['settings']['invoice_number_digits'] ) && is_wp_error( $spx_import_bad ) && ! is_wp_error( $spx_import_ok ) && '{prefix}-{year}-{number}' === Settings::get( 'credit_number_pattern' ) );

// Settings screen: table with the next number, errors reported.
ob_start();
SettingsPage::render( 'invoices' );
$spx_html = (string) ob_get_clean();
check( 'SET-PC-03/-37: the invoices tab shows the number table and the DATEV table', false !== strpos( $spx_html, 'wpcap-number-table' ) && false !== strpos( $spx_html, 'name="self_billing_number_pattern"' ) && false !== strpos( $spx_html, 'wpcap-datev-table' ) && false !== strpos( $spx_html, 'name="datev_accounts[rates][0][account]"' ) && false !== strpos( $spx_html, 'id="wpcap-set-datev_contra_account"' ) );
$spx_saved = SettingsPage::save( array( 'wpcap_tab' => 'invoices', 'invoice_number_pattern' => '{prefix}', 'invoice_number_digits' => '6', 'invoice_number_start' => '0', 'invoice_number_yearly' => '1' ) );
Settings::flush();
check( 'SET-PC-03: the settings screen reports an invalid pattern and keeps the stored one', ! is_wp_error( $spx_saved ) && isset( $spx_saved['field_errors']['invoice_number_pattern'] ) && '{prefix}-{year}-{number}' === Settings::get( 'invoice_number_pattern' ) );
check( 'SET-PC-03: the checkbox of the table is saved like any other flag', 1 === (int) Settings::get( 'invoice_number_yearly' ) && 0 === (int) Settings::get( 'credit_number_yearly' ) );
$spx_set( array( 'credit_number_yearly' => 1, 'receipt_number_yearly' => 1, 'settlement_number_yearly' => 1, 'self_billing_number_yearly' => 1 ) );
$spx_clean_rows();
delete_option( DocumentNumbers::SKIPS_OPTION );
delete_option( DocumentNumbers::PATTERNS_OPTION );

// Other years with numbers issued under the continuous counter continue above it too (no duplicate).
$wpdb->query( "DELETE FROM {$p}wpcap_invoice_sequences WHERE year IN (0," . ( $spx_year + 5 ) . ',' . ( $spx_year + 6 ) . ')' );
$wpdb->insert( "{$p}wpcap_invoice_sequences", array( 'year' => $spx_year + 5, 'next_number' => 2 ) );
$spx_set( array( 'invoice_number_yearly' => 0 ) );
$spx_c1 = Invoices::next_number( $spx_year + 5 ); // Continuous counter, labelled with year +5.
$spx_set( array( 'invoice_number_yearly' => 1 ) );
Invoices::next_number( $spx_year + 6 ); // Switch back while reserving another year.
$spx_c2 = Invoices::next_number( $spx_year + 5 );
check( 'SET-PC-03: a continuous number advances the yearly counter of its year, so switching back never repeats it (' . $spx_c1 . ' then ' . $spx_c2 . ')', (int) substr( $spx_c2, -6 ) > (int) substr( $spx_c1, -6 ) );
$wpdb->query( "DELETE FROM {$p}wpcap_invoice_sequences WHERE year IN (" . ( $spx_year + 5 ) . ',' . ( $spx_year + 6 ) . ')' );
$spx_set( array( 'invoice_number_pattern' => '{prefix}-{year}-{number}', 'invoice_number_digits' => 6, 'invoice_number_start' => 0, 'invoice_number_yearly' => 1 ) );

/* ------------------------------------------------------------------ SET-PC-15: service date fallback */

$spx_order = (object) array( 'paid_at' => '2026-03-14 23:30:00' ); // 15 March in Vienna/Berlin.
$spx_dated = (object) array( 'starts_at' => '2026-05-01 18:00:00', 'ends_at' => '', 'timezone' => 'Europe/Vienna', 'valid_from' => '2026-01-01', 'valid_until' => '2026-12-31' );
$spx_pass = (object) array( 'starts_at' => '', 'timezone' => 'Europe/Vienna', 'valid_from' => '2026-04-01 22:30:00', 'valid_until' => '2027-03-31' );
$spx_fee = (object) array( 'starts_at' => '', 'timezone' => '' );
$spx_case = static function ( $mode ) use ( $spx_set, $spx_order, $spx_dated, $spx_pass, $spx_fee ) {
	$spx_set( array( 'invoice_service_date_fallback' => $mode ) );
	return array( Invoices::line_service_dates( $spx_dated, $spx_order ), Invoices::line_service_dates( $spx_pass, $spx_order ), Invoices::line_service_dates( $spx_fee, $spx_order ) );
};
$spx_site_day = wp_date( 'Y-m-d', strtotime( '2026-03-14 23:30:00 UTC' ), PassCaptain\Frontend\Format::site_timezone() );
list( $spx_d1, $spx_p1, $spx_f1 ) = $spx_case( 'none' );
check( 'SET-PC-15: default "none" keeps the former behaviour (no date without occurrence, occurrence date otherwise)', array( 'start' => '2026-05-01', 'end' => '2026-05-01' ) === $spx_d1 && array( 'start' => '', 'end' => '' ) === $spx_p1 && array( 'start' => '', 'end' => '' ) === $spx_f1 );
list( $spx_d2, $spx_p2, $spx_f2 ) = $spx_case( 'payment' );
check( 'SET-PC-15: "Zahlungsdatum" uses the payment date (site timezone); dated lines keep their occurrence', array( 'start' => '2026-05-01', 'end' => '2026-05-01' ) === $spx_d2 && array( 'start' => $spx_site_day, 'end' => $spx_site_day ) === $spx_p2 && $spx_p2 === $spx_f2 );
list( $spx_d3, $spx_p3, $spx_f3 ) = $spx_case( 'valid_from' );
check( 'SET-PC-15: "Gültigkeitsbeginn" uses the start of validity (local date), else the payment date', array( 'start' => '2026-05-01', 'end' => '2026-05-01' ) === $spx_d3 && array( 'start' => '2026-04-02', 'end' => '2026-04-02' ) === $spx_p3 && array( 'start' => $spx_site_day, 'end' => $spx_site_day ) === $spx_f3 );
list( $spx_d4, $spx_p4, $spx_f4 ) = $spx_case( 'period' );
check( 'SET-PC-15: "Zeitraum" uses the validity period, else the payment date', array( 'start' => '2026-05-01', 'end' => '2026-05-01' ) === $spx_d4 && array( 'start' => '2026-04-02', 'end' => '2027-03-31' ) === $spx_p4 && array( 'start' => $spx_site_day, 'end' => $spx_site_day ) === $spx_f4 );
check( 'SET-PC-15: an unknown choice is reported by the register', is_wp_error( SettingsSchema::validate( 'invoice_service_date_fallback', 'issue' ) ) && 'period' === SettingsSchema::validate( 'invoice_service_date_fallback', 'period' ) );

// Issued invoice: a line without occurrence (add-on line through wpcap_invoice_items) gets the date of the setting.
// The settings screen save above posted the invoices tab without seller data; L908 needs it for invoices.
$spx_set( array( 'seller_name' => 'Leistungsdatum GmbH', 'seller_address' => "Ring 1\n1010 Wien", 'seller_tax_number' => '12/345/67890' ) );
$spx_type = ex_type( ex_occ( ex_event( 'Leistungsdatum' ) ), 'Pass', 3000 );
$spx_items_filter = static function ( $items ) {
	foreach ( $items as $i => $item ) { $item = clone $item; $item->starts_at = ''; $item->ends_at = ''; $item->valid_from = '2026-06-01'; $item->valid_until = '2026-08-31'; $items[ $i ] = $item; }
	return $items;
};
$spx_issue = static function ( $mode ) use ( $spx_set, $spx_type, $spx_items_filter, $wpdb, $p ) {
	$spx_set( array( 'invoice_service_date_fallback' => $mode ) );
	list( $order_id ) = ex_buy( $spx_type, 1, 'spx-' . $mode . '@example.com' );
	add_filter( 'wpcap_invoice_items', $spx_items_filter, 99 );
	$invoice = Invoices::issue_for_order( $order_id );
	remove_filter( 'wpcap_invoice_items', $spx_items_filter, 99 );
	$snapshot = json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT buyer_snapshot FROM {$p}wpcap_invoices WHERE invoice_id=%d", (int) $invoice['invoice_id'] ) ), true );
	return array( $snapshot['service_dates'][0] ?? null, PassCaptain\Api\Invoices::get( (int) $invoice['invoice_id'] ) );
};
list( $spx_none_dates, $spx_none_api ) = $spx_issue( 'none' );
list( $spx_period_dates, $spx_period_api ) = $spx_issue( 'period' );
// "none": the line has no date as before (the invoice-level period keeps its former fallback to the order's occurrences).
check( 'SET-PC-15: issued invoices store the line date of the setting (none: empty, period: validity) and the invoice API returns it',
	array( 'start' => '', 'end' => '' ) === $spx_none_dates && '' === ( $spx_none_api['lines'][0]['service_date'] ?? 'x' )
	&& array( 'start' => '2026-06-01', 'end' => '2026-08-31' ) === $spx_period_dates && '2026-06-01' === ( $spx_period_api['lines'][0]['service_date'] ?? '' ) && '2026-08-31' === ( $spx_period_api['service_period']['end'] ?? '' ) );
$spx_set( array( 'invoice_service_date_fallback' => 'none' ) );

/* ------------------------------------------------------------------ SET-PC-18: minimum age */

$spx_occ = ex_occ( ex_event( 'Mindestalter' ) );
$spx_adult = ex_type( $spx_occ, 'Ab 18', 2000 );
$spx_kids = ex_type( $spx_occ, 'Familie', 1000 );
$wpdb->update( "{$p}wpcap_ticket_types", array( 'min_age' => 18 ), array( 'ticket_type_id' => $spx_adult ) );
$spx_buy = static function ( $type, array $extra = array(), array $internal = array() ) {
	$GLOBALS['wpcap_test_transients'] = array();
	return Orders::create( array( 'email' => 'age-' . uniqid() . '@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => 'age-' . uniqid( '', true ) ) + $extra, $internal + array( 'skip_rate_limit' => true ) );
};
check( 'SET-PC-18: switched off (default) an order with a minimum age needs no confirmation', ! is_wp_error( $spx_buy( $spx_adult ) ) );
$spx_set( array( 'min_age_checkbox' => 1 ) );
$spx_refused = $spx_buy( $spx_adult );
check( 'SET-PC-18: switched on the checkout refuses an order with a minimum age without confirmation', is_wp_error( $spx_refused ) && 'wpcap_age_confirmation_required' === $spx_refused->get_error_code() && 18 === ( $spx_refused->get_error_data()['min_age'] ?? 0 ) && false !== strpos( $spx_refused->get_error_message(), '18' ) );
check( 'SET-PC-18: with the confirmation the order goes through; lines without a minimum age need none', ! is_wp_error( $spx_buy( $spx_adult, array( 'confirm_age' => true ) ) ) && ! is_wp_error( $spx_buy( $spx_kids ) ) );
check( 'SET-PC-18: channels with their own namespace (box office) are not asked', ! is_wp_error( $spx_buy( $spx_adult, array(), array( 'external' => true, 'key_namespace' => 'pos', 'payment_provider' => 'pos_cash', 'source' => 'pos' ) ) ) );
$spx_field = PassCaptain\Rest\Controller::with_error_field( $spx_refused );
check( 'SET-PC-18: the REST answer names the form field confirm_age', 'confirm_age' === ( $spx_field->get_error_data()['field'] ?? '' ) );
check( 'SET-PC-18: highest minimum age of the lines', 18 === AdmissionChecks::max_min_age( array( $spx_kids, $spx_adult ) ) && 0 === AdmissionChecks::max_min_age( array( $spx_kids ) ) && 18 === AdmissionChecks::confirmation_age( array( $spx_adult ) ) );

// Notice text: built-in, own text of the default language, translation per language (legal_texts).
$spx_terms_version = Settings::terms_version( 'en_US' );
check( 'SET-PC-18: built-in confirmation text names the age', false !== strpos( Settings::min_age_notice( 18 ), '18' ) && false === strpos( Settings::min_age_notice( 18 ), '{min_age}' ) );
$spx_set( array( 'min_age_notice_text' => 'Alle sind mindestens {min_age}.' ) );
check( 'SET-PC-18: own text with placeholder', 'Alle sind mindestens 16.' === Settings::min_age_notice( 16, Settings::legal_default_locale() ) );
$spx_texts = SettingsPage::sanitize_legal_texts( array( 'en_US' => array( 'min_age_notice_text' => 'Everyone is <b>{min_age}+</b>.' ) ) );
$spx_set( array( 'legal_texts' => $spx_texts ) );
check( 'SET-PC-18: translation per language (plain text), other languages fall back to the default language', 'Everyone is {min_age}+.' === ( $spx_texts['en_US']['min_age_notice_text'] ?? '' ) && 'Everyone is 18+.' === Settings::min_age_notice( 18, 'en_US' ) && 'Alle sind mindestens 18.' === Settings::min_age_notice( 18, 'fr_FR' ) );
check( 'SET-PC-18: the translation does not change the version of the legal texts', $spx_terms_version === Settings::terms_version( 'en_US' ) );
ob_start();
try {
	SettingsPage::render( 'legal' );
} catch ( \Throwable $spx_e ) {
	unset( $spx_e ); // The list of archived versions needs wp_timezone_string(), missing in the stub environment; the parts checked here are rendered before it.
}
$spx_legal_html = (string) ob_get_clean();
check( 'SET-PC-18: the legal tab shows the minimum age settings and the text per language', false !== strpos( $spx_legal_html, 'name="min_age_checkbox"' ) && false !== strpos( $spx_legal_html, 'name="default_min_age"' ) && false !== strpos( $spx_legal_html, 'name="min_age_notice_text"' ) && false !== strpos( $spx_legal_html, 'legal_texts[en_US][min_age_notice_text]' ) );
check( 'SET-PC-18: default_min_age is reported outside 0–99', is_wp_error( SettingsSchema::validate( 'default_min_age', 100 ) ) && 18 === SettingsSchema::validate( 'default_min_age', '18' ) );
$spx_events_src = (string) file_get_contents( WPCAP_PATH . 'src/Admin/EventsPage.php' );
$spx_card_src = (string) file_get_contents( WPCAP_PATH . 'templates/event-card.php' );
$spx_js_src = (string) file_get_contents( WPCAP_PATH . 'assets/checkout.js' );
check( 'SET-PC-18: new ticket types are pre-filled with default_min_age; the event card and checkout script send the confirmation', false !== strpos( $spx_events_src, "Settings::get( 'default_min_age', 0 )" ) && false !== strpos( $spx_card_src, 'name="confirm_age"' ) && false !== strpos( $spx_js_src, 'confirm_age:' ) );
$spx_set( array( 'min_age_checkbox' => 0, 'min_age_notice_text' => '', 'legal_texts' => is_array( $spx_option['legal_texts'] ?? null ) ? $spx_option['legal_texts'] : array() ) );

/* ------------------------------------------------------------------ SET-PC-37: DATEV accounts */

$spx_set( array( 'datev_chart' => 'skr03', 'datev_contra_account' => '' ) );
check( 'SET-PC-37: without own accounts the booking batch uses the former chart accounts', array( 'revenue' => array( 1900 => '8400', 700 => '8300' ), 'revenue_default' => '8200', 'bu_keys' => array(), 'contra' => '1360', 'contra_by_provider' => array() ) === Invoices::datev_accounts() );
$spx_form = array(
	'rates' => array(
		array( 'rate' => '19', 'account' => '8401', 'bu' => '9' ),
		array( 'rate' => '7', 'account' => '', 'bu' => '' ),
		array( 'rate' => '0', 'account' => '8100', 'bu' => '' ),
		array( 'rate' => '', 'account' => '', 'bu' => '' ),
	),
	'revenue_default' => '8199',
	'contra_by_provider' => array( 'manual' => '1200', 'stripe' => '' ),
);
$spx_canonical = DatevAccounts::sanitize( $spx_form );
check( 'SET-PC-37: the table is stored in the canonical form (rate in basis points)', array( 'revenue' => array( 0 => '8100', 1900 => '8401' ), 'bu_keys' => array( 1900 => '9' ), 'revenue_default' => '8199', 'contra_by_provider' => array( 'manual' => '1200' ) ) === $spx_canonical );
$spx_set( array( 'datev_accounts' => $spx_canonical, 'datev_contra_account' => '1365' ) );
$spx_acc = Invoices::datev_accounts();
check( 'SET-PC-37: own accounts replace the chart accounts, empty cells keep them', array( 0 => '8100', 700 => '8300', 1900 => '8401' ) === ( static function ( $r ) { ksort( $r ); return $r; } )( $spx_acc['revenue'] ) && '8199' === $spx_acc['revenue_default'] && array( 1900 => '9' ) === $spx_acc['bu_keys'] && '1365' === $spx_acc['contra'] && array( 'manual' => '1200' ) === $spx_acc['contra_by_provider'] );
$spx_datev_filter = static function ( $accounts ) { $accounts['revenue'][1900] = '9999'; return $accounts; };
add_filter( 'wpcap_datev_accounts', $spx_datev_filter );
$spx_filtered = Invoices::datev_accounts();
remove_filter( 'wpcap_datev_accounts', $spx_datev_filter );
check( 'SET-PC-37: the filter wpcap_datev_accounts keeps the last word', '9999' === $spx_filtered['revenue'][1900] && '8199' === $spx_filtered['revenue_default'] );
check( 'SET-PC-37: invalid cells are reported (account, BU key, rate, money account)',
	is_wp_error( DatevAccounts::sanitize( array( 'rates' => array( array( 'rate' => '19', 'account' => '84' ) ) ) ) )
	&& is_wp_error( DatevAccounts::sanitize( array( 'rates' => array( array( 'rate' => '19', 'bu' => '12345' ) ) ) ) )
	&& is_wp_error( DatevAccounts::sanitize( array( 'rates' => array( array( 'rate' => 'abc', 'account' => '8400' ) ) ) ) )
	&& is_wp_error( DatevAccounts::sanitize( array( 'contra_by_provider' => array( 'manual' => '1' ) ) ) )
	&& is_wp_error( SettingsSchema::validate_complete( 'datev_contra_account', '12' ) ) && '1360' === SettingsSchema::validate_complete( 'datev_contra_account', '1360' ) );
// Settings screen: the table saves to the same keys; an emptied table stores nothing of its own.
$spx_page = SettingsPage::save( array( 'wpcap_tab' => 'invoices', 'datev_chart' => 'skr04', 'datev_contra_account' => '', 'datev_accounts' => array( 'rates' => array( array( 'rate' => '19', 'account' => '4401', 'bu' => '' ) ), 'revenue_default' => '', 'contra_by_provider' => array( 'manual' => '' ) ) ) + array_fill_keys( array( 'invoice_number_yearly', 'credit_number_yearly', 'receipt_number_yearly', 'settlement_number_yearly', 'self_billing_number_yearly' ), '1' ) );
Settings::flush();
check( 'SET-PC-37: the settings screen saves chart, money account and table', ! is_wp_error( $spx_page ) && array() === $spx_page['errors'] && 'skr04' === Settings::get( 'datev_chart' ) && '' === Settings::get( 'datev_contra_account' ) && array( 'revenue' => array( 1900 => '4401' ), 'bu_keys' => array(), 'revenue_default' => '', 'contra_by_provider' => array() ) === Settings::get( 'datev_accounts' ) && '4300' === Invoices::datev_accounts()['revenue'][700] && '1460' === Invoices::datev_accounts()['contra'] );
$spx_page_bad = SettingsPage::save( array( 'wpcap_tab' => 'invoices', 'datev_contra_account' => '12', 'datev_accounts' => array( 'rates' => array( array( 'rate' => '7', 'account' => 'x' ) ) ) ) + array_fill_keys( array( 'invoice_number_yearly', 'credit_number_yearly', 'receipt_number_yearly', 'settlement_number_yearly', 'self_billing_number_yearly' ), '1' ) );
Settings::flush();
check( 'SET-PC-37: invalid table cells are reported per field and the stored accounts stay', ! is_wp_error( $spx_page_bad ) && isset( $spx_page_bad['field_errors']['datev_contra_account'], $spx_page_bad['field_errors']['datev_accounts'] ) && array( 1900 => '4401' ) === Settings::get( 'datev_accounts' )['revenue'] );
SettingsPage::save( array( 'wpcap_tab' => 'invoices', 'datev_chart' => 'skr03', 'datev_accounts' => array( 'rates' => array( array( 'rate' => '19', 'account' => '' ) ), 'revenue_default' => '' ) ) + array_fill_keys( array( 'invoice_number_yearly', 'credit_number_yearly', 'receipt_number_yearly', 'settlement_number_yearly', 'self_billing_number_yearly' ), '1' ) );
Settings::flush();
check( 'SET-PC-37: an emptied table stores no own accounts (former behaviour again)', array() === Settings::get( 'datev_accounts' ) && '8400' === Invoices::datev_accounts()['revenue'][1900] );
// WP-CLI and import: a part of the table is merged into the stored value.
$spx_cli = SettingsCli::set_value( 'datev_accounts', array( 'bu_keys' => array( 700 => '2' ) ) );
$spx_cli_bad = SettingsCli::set_value( 'datev_accounts', array( 'revenue' => array( 700 => 'abc' ) ) );
check( 'SET-PC-37: WP-CLI sets a part of the accounts through the register', is_array( $spx_cli ) && array( 700 => '2' ) === ( $spx_cli['bu_keys'] ?? null ) && is_wp_error( $spx_cli_bad ) );
$spx_export = SettingsManager::export();
$spx_export['settings']['datev_accounts'] = array( 'revenue' => array( 1900 => '84' ) );
check( 'SET-PC-37: an import with an invalid account is refused', is_wp_error( SettingsManager::import( $spx_export, false ) ) );

// Restore.
update_option( Settings::OPTION, $spx_option );
Settings::flush();
delete_option( DocumentNumbers::SKIPS_OPTION );
delete_option( DocumentNumbers::PATTERNS_OPTION );

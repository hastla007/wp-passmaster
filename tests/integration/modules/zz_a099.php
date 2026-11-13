<?php
/**
 * A099: settings in tabs (saving one tab keeps the others), payment providers on their own tab
 * with one section per provider, legal texts per language (fallback to the default language,
 * terms version per language in checkout, REST options, event card and mails).
 * Included from run.php (uses check(), make_type(); Wpcap_Test_Redirect from admin.php).
 */
// phpcs:ignoreFile
use PassCaptain\Admin\SettingsPage;
use PassCaptain\Mail\Mailer;
use PassCaptain\Orders;
use PassCaptain\Payments\Registry;
use PassCaptain\PublicEvents;
use PassCaptain\Rest\Controller;
use PassCaptain\Settings;

if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce" value="n">'; } }
if ( ! function_exists( 'submit_button' ) ) { function submit_button( $t = '', $type = 'primary', $name = 'submit', $wrap = true ) { echo '<button>' . esc_html( $t ) . '</button>'; } }
if ( ! function_exists( 'esc_attr_e' ) ) { function esc_attr_e( $t, $d = null ) { echo esc_attr( $t ); } }
if ( ! function_exists( 'esc_textarea' ) ) { function esc_textarea( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'checked' ) ) { function checked( $a, $b = true, $echo = true ) { $r = $a == $b ? ' checked="checked"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . ltrim( $p, '/' ); } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . ltrim( $p, '/' ); } }
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $t ) { return '<p>' . $t . '</p>'; } }
if ( ! function_exists( 'locate_template' ) ) { function locate_template( $t ) { return ''; } }

global $wpdb;
$GLOBALS['wpcap_test_denied_caps'] = array();
$a099_original = Settings::all();

echo "A099: settings tabs\n";
$tabs = SettingsPage::tabs();
check( 'five tabs: general, admission, payments, legal, invoices', array( 'general', 'admission', 'payments', 'legal', 'invoices' ) === array_keys( $tabs ) );
$per_key = array();
foreach ( SettingsPage::field_definitions() as $section_id => $section ) {
	foreach ( array_keys( $section['fields'] ) as $key ) { $per_key[ $key ][] = $section['tab'] ?? '?'; }
}
$bad = array_filter( $per_key, static fn( $t ) => 1 !== count( $t ) || ! isset( $tabs[ $t[0] ] ) );
check( 'every settings field belongs to exactly one valid tab', ! $bad && 'legal' === $per_key['terms_text'][0] && 'payments' === $per_key['stripe_secret_key'][0] && 'payments' === $per_key['bank_iban'][0] && 'admission' === $per_key['scanner_show_attendee'][0] && 'invoices' === $per_key['seller_name'][0] && 'general' === $per_key['hold_minutes'][0] );
$general = SettingsPage::scoped_sections( 'general' );
$general_keys = array();
foreach ( $general as $s ) { $general_keys += $s['fields']; }
check( 'payment settings are not mixed into the general tab', ! array_intersect( array_keys( $general_keys ), array( 'enabled_providers', 'manual_instructions', 'bank_iban', 'manual_hold_hours', 'manual_cutoff_hours', 'stripe_mode', 'paypal_mode' ) ) );
check( 'payments tab shows one section at a time (overview by default, unknown -> overview)', array( 'stripe' ) === array_keys( SettingsPage::scoped_sections( 'payments', 'stripe' ) ) && array( 'payments' ) === array_keys( SettingsPage::scoped_sections( 'payments', '' ) ) && array( 'payments' ) === array_keys( SettingsPage::scoped_sections( 'payments', 'scanner' ) ) && array( 'manual' ) === array_keys( SettingsPage::scoped_sections( 'payments', 'manual' ) ) );
$providers_with_section = array();
foreach ( SettingsPage::field_definitions() as $s ) { if ( ! empty( $s['provider'] ) ) { $providers_with_section[] = $s['provider']; } }
check( 'manual transfer, Stripe and PayPal have their own section', array( 'manual', 'stripe', 'paypal' ) === $providers_with_section );
check( 'unknown tab saves nothing, missing tab means the full legacy form', array() === SettingsPage::scoped_sections( 'bogus' ) && count( SettingsPage::scoped_sections( null ) ) === count( SettingsPage::field_definitions() ) );

echo "A099: saving one tab keeps the other tabs\n";
Settings::update( array(
	'require_terms' => 1, 'auto_invoice' => 1, 'scanner_show_attendee' => 1, 'delete_data_on_uninstall' => 1, 'auto_refund_cancelled' => 1,
	'stripe_mode' => 'live', 'stripe_secret_key' => 'sk_keep', 'bank_iban' => 'DE02120300000000202051', 'manual_hold_hours' => 48,
	'enabled_providers' => array( 'manual' ), 'seller_name' => 'Verein', 'hold_minutes' => 10, 'terms_text' => 'AGB DE', 'consumer_info_text' => 'VI DE', 'legal_texts' => array(),
) );
$r = SettingsPage::save( array( 'wpcap_tab' => 'general', 'hold_minutes' => '20' ) );
$all = Settings::all();
check( 'general tab: its own unticked checkboxes are saved as off', ! is_wp_error( $r ) && 20 === $all['hold_minutes'] && 0 === $all['auto_refund_cancelled'] && 0 === $all['delete_data_on_uninstall'] );
check( 'general tab: checkboxes and values of other tabs stay untouched', 1 === (int) $all['require_terms'] && 1 === (int) $all['auto_invoice'] && 1 === (int) $all['scanner_show_attendee'] && 'live' === $all['stripe_mode'] && 'sk_keep' === PassCaptain\Secrets::get( 'stripe_secret_key' ) && 'DE02120300000000202051' === $all['bank_iban'] && 48 === (int) $all['manual_hold_hours'] && array( 'manual' ) === $all['enabled_providers'] && 'Verein' === $all['seller_name'] && 'AGB DE' === $all['terms_text'] );
check( 'only keys of the submitted tab are written', ! array_diff( array_keys( $r['saved'] ), array_keys( $general_keys ), array( 'secrets_updated' ) ) );
SettingsPage::save( array( 'wpcap_tab' => 'payments', 'wpcap_section' => 'stripe', 'stripe_mode' => 'test', 'wpcap_provider_toggle' => '1', 'wpcap_provider_enabled' => '1' ) );
$all = Settings::all();
check( 'Stripe section: mode saved and Stripe switched on without touching other providers', 'test' === $all['stripe_mode'] && array( 'manual', 'stripe' ) === $all['enabled_providers'] && 'sk_keep' === PassCaptain\Secrets::get( 'stripe_secret_key' ) && 'DE02120300000000202051' === $all['bank_iban'] && 20 === $all['hold_minutes'] && 1 === (int) $all['require_terms'] );
SettingsPage::save( array( 'wpcap_tab' => 'payments', 'wpcap_section' => 'stripe', 'stripe_mode' => 'test', 'wpcap_provider_toggle' => '1' ) );
check( 'Stripe section: unticking switches only Stripe off', array( 'manual' ) === Settings::get( 'enabled_providers' ) );
SettingsPage::save( array( 'wpcap_tab' => 'payments', 'wpcap_section' => 'manual', 'bank_iban' => 'AT611904300234573201', 'manual_hold_hours' => '24', 'manual_cutoff_hours' => '0', 'manual_instructions' => 'Bitte überweisen', 'bank_recipient' => '', 'bank_bic' => '' ) );
$all = Settings::all();
check( 'transfer section: bank data saved, enabled providers unchanged without the switch', 'AT611904300234573201' === $all['bank_iban'] && 24 === (int) $all['manual_hold_hours'] && array( 'manual' ) === $all['enabled_providers'] && 'test' === $all['stripe_mode'] );
SettingsPage::save( array( 'wpcap_tab' => 'payments', 'enabled_providers' => array( 'manual', 'paypal' ) ) );
$all = Settings::all();
check( 'payments overview: only the provider list is written', array( 'manual', 'paypal' ) === $all['enabled_providers'] && 'AT611904300234573201' === $all['bank_iban'] && 'test' === $all['stripe_mode'] );
SettingsPage::save( array( 'wpcap_tab' => 'bogus', 'hold_minutes' => '99' ) );
$all = Settings::all();
check( 'a tampered tab id changes nothing (no mass reset of checkboxes)', 20 === $all['hold_minutes'] && 1 === (int) $all['auto_invoice'] && 1 === (int) $all['require_terms'] && 1 === (int) $all['scanner_show_attendee'] );
SettingsPage::save( array( 'wpcap_tab' => 'admission', 'device_max_hours' => '12' ) );
$all = Settings::all();
check( 'admission tab: own checkbox off, legal/invoice checkboxes kept', 0 === (int) $all['scanner_show_attendee'] && 12 === (int) $all['device_max_hours'] && 1 === (int) $all['auto_invoice'] && 1 === (int) $all['require_terms'] );
$_POST = array( 'action' => 'wpcap_save_settings', 'wpcap_tab' => 'payments', 'wpcap_section' => 'paypal', 'paypal_mode' => 'sandbox' );
$redirect = '';
try { SettingsPage::handle_save(); } catch ( Wpcap_Test_Redirect $e ) { $redirect = $e->getMessage(); }
$_POST = array();
$GLOBALS['wpcap_test_transients'] = array();
check( 'after saving, the redirect returns to the same tab and provider section', false !== strpos( $redirect, 'page=wpcap-settings' ) && false !== strpos( $redirect, 'tab=payments' ) && false !== strpos( $redirect, 'section=paypal' ) );
check( 'redirect args: legal tab without section, unknown tab to the default page', array( 'tab' => 'legal' ) === SettingsPage::url_args( 'legal', 'stripe' ) && array() === SettingsPage::url_args( 'nope' ) && array( 'tab' => 'payments' ) === SettingsPage::url_args( 'payments', 'xyz' ) );

echo "A099: rendering\n";
ob_start(); SettingsPage::render( 'payments', 'stripe' ); $html = ob_get_clean();
check( 'tab navigation with the active tab marked, every tab its own URL', false !== strpos( $html, 'nav-tab-wrapper' ) && 1 === substr_count( $html, 'nav-tab-active' ) && false !== strpos( $html, 'tab=legal' ) && false !== strpos( $html, 'tab=invoices' ) && false !== strpos( $html, 'aria-current="page"' ) );
check( 'Stripe page: only Stripe fields plus tab/section markers and its on/off switch', false !== strpos( $html, 'name="stripe_secret_key"' ) && false === strpos( $html, 'name="bank_iban"' ) && false === strpos( $html, 'name="hold_minutes"' ) && false === strpos( $html, 'name="paypal_mode"' ) && false !== strpos( $html, 'name="wpcap_tab" value="payments"' ) && false !== strpos( $html, 'name="wpcap_section" value="stripe"' ) && false !== strpos( $html, 'name="wpcap_provider_enabled"' ) && false !== strpos( $html, 'payments/stripe/webhook' ) );
check( 'payments sub-navigation lists overview and each provider', false !== strpos( $html, 'subsubsub' ) && false !== strpos( $html, 'section=manual' ) && false !== strpos( $html, 'section=paypal' ) );
ob_start(); SettingsPage::render( 'payments' ); $overview = ob_get_clean();
check( 'payments overview: provider list with links to each provider section, no provider fields', false !== strpos( $overview, 'name="enabled_providers[]"' ) && false !== strpos( $overview, 'section=stripe' ) && false === strpos( $overview, 'name="stripe_mode"' ) && false === strpos( $overview, 'name="wpcap_provider_toggle"' ) );
$a099_url_filter = static fn( $url, $id ) => 'mollie_like' === $id ? 'https://example.test/wp-admin/admin.php?page=wpcap-mollie' : $url;
add_filter( 'wpcap_payment_settings_url', $a099_url_filter, 10, 2 );
check( 'providers without own section use wpcap_payment_settings_url (e.g. Mollie page)', false !== strpos( SettingsPage::provider_url( 'mollie_like' ), 'page=wpcap-mollie' ) && false !== strpos( SettingsPage::provider_url( 'stripe' ), 'section=stripe' ) && false !== strpos( PassCaptain\Modules\Mollie::settings_url( '', 'mollie' ), 'page=wpcap-mollie' ) && '' === PassCaptain\Modules\Mollie::settings_url( '', 'stripe' ) );
remove_filter( 'wpcap_payment_settings_url', $a099_url_filter, 10 );
ob_start(); SettingsPage::render( 'admission' ); $adm = ob_get_clean();
check( 'admission tab renders only its fields', false !== strpos( $adm, 'name="scanner_show_attendee"' ) && false === strpos( $adm, 'name="terms_text"' ) && false === strpos( $adm, 'name="enabled_providers[]"' ) && false === strpos( $adm, 'name="wpcap_section"' ) );

echo "A099: legal texts per language\n";
Settings::update( array( 'terms_text' => 'AGB DE', 'consumer_info_text' => 'VI DE', 'require_terms' => 1, 'legal_texts' => array() ) );
$legacy = substr( hash( 'sha256', "AGB DE\n--\nVI DE" ), 0, 16 );
check( 'default locale is the site locale (de_DE in tests)', 'de_DE' === Settings::legal_default_locale() && 'de_DE' === Settings::legal_locales()[0] );
check( 'existing single texts are the default-language texts, version hash unchanged', $legacy === Settings::terms_version() && $legacy === Settings::terms_version( 'de_DE' ) && 'AGB DE' === Settings::legal_text( 'terms_text', 'de_DE' ) );
check( 'without translations every language falls back to the default texts and version', 'AGB DE' === Settings::legal_text( 'terms_text', 'en_US' ) && $legacy === Settings::terms_version( 'en_US' ) );
$r = SettingsPage::save( array(
	'wpcap_tab' => 'legal', 'require_terms' => '1', 'terms_text' => 'AGB DE', 'consumer_info_text' => 'VI DE',
	'legal_texts' => array( 'en_US' => array( 'terms_text' => ' <p>Terms EN</p> ', 'consumer_info_text' => '' ), 'fr_FR' => array( 'terms_text' => '', 'consumer_info_text' => '' ), 'de_DE' => array( 'terms_text' => 'ignored' ), '../x' => array( 'terms_text' => 'bad' ), 0 => '' ),
	'legal_new_locale' => 'it_IT',
) );
$stored = Settings::get( 'legal_texts' );
check( 'legal tab: per-language texts stored, empty languages dropped, default/invalid locales ignored, new locale added', ! is_wp_error( $r ) && array( 'en_US', 'it_IT' ) === array_keys( $stored ) && array( 'terms_text' => '<p>Terms EN</p>' ) === $stored['en_US'] && 'AGB DE' === Settings::get( 'terms_text' ) && 1 === (int) Settings::get( 'require_terms' ) );
check( 'locales offered: default first, then languages with texts', array_slice( Settings::legal_locales(), 0, 1 ) === array( 'de_DE' ) && in_array( 'en_US', Settings::legal_locales(), true ) && in_array( 'it_IT', Settings::legal_locales(), true ) );
check( 'buyer locale picks its text, missing texts fall back per text to the default language', '<p>Terms EN</p>' === Settings::legal_text( 'terms_text', 'en_US' ) && 'VI DE' === Settings::legal_text( 'consumer_info_text', 'en_US' ) && 'AGB DE' === Settings::legal_text( 'terms_text', 'it_IT' ) && 'AGB DE' === Settings::legal_text( 'terms_text', 'fr_FR' ) );
check( 'same language uses the regional variant (en_GB -> en_US), de_AT the default German text', '<p>Terms EN</p>' === Settings::legal_text( 'terms_text', 'en_GB' ) && 'AGB DE' === Settings::legal_text( 'terms_text', 'de_AT' ) && 'en_US' === Settings::legal_source_locale( 'terms_text', 'en_GB' ) );
$v_en = Settings::terms_version( 'en_US' );
check( 'terms version per language: English differs, German unchanged, fallback languages share the default version', '' !== $v_en && $v_en !== $legacy && $legacy === Settings::terms_version( 'de_DE' ) && $v_en === Settings::terms_version( 'en_GB' ) && $legacy === Settings::terms_version( 'it_IT' ) && $v_en === substr( hash( 'sha256', "<p>Terms EN</p>\n--\nVI DE" ), 0, 16 ) );
SettingsPage::save( array( 'wpcap_tab' => 'general', 'hold_minutes' => '10' ) );
check( 'saving another tab keeps the per-language texts', '<p>Terms EN</p>' === Settings::legal_text( 'terms_text', 'en_US' ) );
SettingsPage::save( array( 'wpcap_tab' => 'legal', 'require_terms' => '1', 'terms_text' => 'AGB DE', 'consumer_info_text' => 'VI DE' ) );
check( 'legal form without the language block (old form) keeps stored translations', '<p>Terms EN</p>' === Settings::legal_text( 'terms_text', 'en_US' ) );
ob_start(); SettingsPage::render( 'legal' ); $legal_html = ob_get_clean();
check( 'legal tab renders one block per language with its own version', false !== strpos( $legal_html, 'name="legal_texts[en_US][terms_text]"' ) && false !== strpos( $legal_html, 'name="legal_texts[it_IT][consumer_info_text]"' ) && false !== strpos( $legal_html, $v_en ) && false !== strpos( $legal_html, $legacy ) && false !== strpos( $legal_html, 'name="legal_new_locale"' ) && false === strpos( $legal_html, 'name="legal_texts[de_DE]' ) );
// WPML: default language and active languages are detected via its public filters.
$wpml_default = static fn( $v ) => 'en';
$wpml_active = static fn( $v ) => array( 'en' => array( 'code' => 'en', 'default_locale' => 'en_US' ), 'nl' => array( 'code' => 'nl', 'default_locale' => 'nl_NL' ) );
add_filter( 'wpml_default_language', $wpml_default );
add_filter( 'wpml_active_languages', $wpml_active );
$wpml_locales = Settings::legal_locales();
check( 'WPML: default language and active languages are detected', 'en_US' === Settings::legal_default_locale() && 'en_US' === $wpml_locales[0] && in_array( 'nl_NL', $wpml_locales, true ) );
remove_filter( 'wpml_default_language', $wpml_default );
remove_filter( 'wpml_active_languages', $wpml_active );
$filter_default = static fn( $l ) => 'fr_FR';
add_filter( 'wpcap_legal_default_locale', $filter_default );
check( 'wpcap_legal_default_locale overrides the default language', 'fr_FR' === Settings::legal_default_locale() );
remove_filter( 'wpcap_legal_default_locale', $filter_default );
check( 'filters removed: default back to de_DE', 'de_DE' === Settings::legal_default_locale() );

echo "A099: checkout uses the buyer's language\n";
$a099_type = make_type( 50, 10, 1500 );
$items = array( array( 'ticket_type_id' => $a099_type, 'quantity' => 1 ) );
$en = Orders::create( array( 'email' => 'a099-en@example.com', 'items' => $items, 'idempotency_key' => 'a099-en', 'accept_terms' => true, 'locale' => 'en_US', 'terms_version' => $v_en ) );
$en_row = ! is_wp_error( $en ) ? Orders::get( $en['order_id'] ) : null;
check( 'order in English stores locale en_US and the English terms version', $en_row && 'en_US' === $en_row->locale && $v_en === $en_row->terms_version );
$de = Orders::create( array( 'email' => 'a099-de@example.com', 'items' => $items, 'idempotency_key' => 'a099-de', 'accept_terms' => true ) );
$de_row = ! is_wp_error( $de ) ? Orders::get( $de['order_id'] ) : null;
check( 'order without locale uses the request locale and the default version (as before)', $de_row && 'de_DE' === $de_row->locale && $legacy === $de_row->terms_version );
$stale = Orders::create( array( 'email' => 'a099-stale@example.com', 'items' => $items, 'idempotency_key' => 'a099-stale', 'accept_terms' => true, 'locale' => 'en_US', 'terms_version' => $legacy ) );
check( 'consent to a version other than the current one of that language is rejected', is_wp_error( $stale ) && 'wpcap_terms_changed' === $stale->get_error_code() && 'accept_terms' === ( Controller::with_error_field( $stale )->get_error_data()['field'] ?? '' ) );
$retry = Orders::create( array( 'email' => 'a099-en@example.com', 'items' => $items, 'idempotency_key' => 'a099-en', 'accept_terms' => true, 'locale' => 'en_US', 'terms_version' => 'outdated' ) );
check( 'idempotent retry still returns the existing order', ! is_wp_error( $retry ) && ! empty( $retry['reused'] ) && (int) $retry['order_id'] === (int) $en['order_id'] );
$bad_locale = Orders::create( array( 'email' => 'a099-bad@example.com', 'items' => $items, 'idempotency_key' => 'a099-bad', 'accept_terms' => true, 'locale' => '../../etc' ) );
check( 'invalid locale falls back to the request locale', ! is_wp_error( $bad_locale ) && 'de_DE' === Orders::get( $bad_locale['order_id'] )->locale && $legacy === Orders::get( $bad_locale['order_id'] )->terms_version );
$req = new WP_REST_Request( 'POST' );
$req->set_body( wp_json_encode( array( 'email' => 'a099-rest@example.com', 'items' => $items, 'idempotency_key' => 'a099-rest', 'accept_terms' => true, 'provider' => 'manual', 'locale' => 'en_GB', 'terms_version' => $v_en ) ) );
$rest = Controller::checkout( $req );
$rest_data = $rest instanceof WP_REST_Response ? $rest->get_data() : $rest;
$rest_row = is_array( $rest_data ) && isset( $rest_data['order_id'] ) ? Orders::get( $rest_data['order_id'] ) : null;
check( 'REST checkout passes locale and shown version through (en_GB uses the en_US text)', $rest_row && 'en_GB' === $rest_row->locale && $v_en === $rest_row->terms_version );
$opt_req = new WP_REST_Request( 'GET' );
$opt_req->set_param( 'locale', 'en_US' );
$opt = Controller::checkout_options( $opt_req );
$opt = $opt instanceof WP_REST_Response ? $opt->get_data() : $opt;
check( 'checkout options return texts and version of the requested language', 'en_US' === ( $opt['locale'] ?? '' ) && '<p>Terms EN</p>' === ( $opt['terms_text'] ?? '' ) && 'VI DE' === ( $opt['consumer_info_text'] ?? '' ) && $v_en === ( $opt['terms_version'] ?? '' ) );
$ctx = PublicEvents::legal_context( 'en_US' );
check( 'event card context: texts, locale and version of the page language', '<p>Terms EN</p>' === $ctx['terms_text'] && 'en_US' === $ctx['locale'] && $v_en === $ctx['terms_version'] && true === $ctx['require_terms'] );
$event = (object) array( 'occurrence_id' => 99099, 'event_id' => 1, 'name' => 'A099', 'description' => '', 'starts_at' => '2030-01-01 18:00:00', 'timezone' => 'Europe/Berlin', 'location' => '' );
$types = array();
$providers = array();
$legal = $ctx;
ob_start(); include WPCAP_PATH . 'templates/event-card.php'; $card = ob_get_clean();
check( 'event card sends locale and shown terms version with the form', false !== strpos( $card, 'name="locale" value="en_US"' ) && false !== strpos( $card, 'name="terms_version" value="' . $v_en . '"' ) && false !== strpos( $card, 'Terms EN' ) );
$js = (string) file_get_contents( WPCAP_PATH . 'assets/checkout.js' );
check( 'checkout script posts locale and terms_version', false !== strpos( $js, 'terms_version: termsVersion' ) && false !== strpos( $js, 'locale: formLocale' ) );

echo "A099: mails use the recipient's language\n";
$tpl = array( 'subject' => 'S', 'body' => "A {terms_text} B {consumer_info_text}", 'format' => 'text' );
$m_en = Mailer::render_template( 'order_access', $tpl, array( 'order_id' => 'X' ), 'en_US' );
$m_de = Mailer::render_template( 'order_access', $tpl, array( 'order_id' => 'X' ), 'de_DE' );
$m_html = Mailer::render_template( 'order_access', array( 'subject' => 'S', 'body' => '<div>{terms_text}</div>', 'format' => 'html' ), array(), 'en_US' );
check( '{terms_text}/{consumer_info_text} in the mail language with fallback', false !== strpos( $m_en['body'], 'A Terms EN B VI DE' ) && false !== strpos( $m_de['body'], 'A AGB DE B VI DE' ) && false !== strpos( $m_html['body'], '<p>Terms EN</p>' ) );
check( 'legal placeholders are listed for every mail type', isset( PassCaptain\Mail\Types::global_placeholders()['terms_text'], PassCaptain\Mail\Types::global_placeholders()['consumer_info_text'] ) );

// Restore.
Settings::update( array_intersect_key( $a099_original, Settings::defaults() ) );
Settings::update( array( 'legal_texts' => array() ) );
check( 'settings restored', array() === Settings::get( 'legal_texts' ) && (int) $a099_original['require_terms'] === (int) Settings::get( 'require_terms' ) );

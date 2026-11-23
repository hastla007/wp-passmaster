<?php
/**
 * A298: legal texts in the settings use the WordPress editor (with a matching label); the manual gateway is
 * offered online as bank transfer only.
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

echo "A298: legal text editor\n";
$a298_src = (string) file_get_contents( WPCAP_PATH . 'src/Admin/SettingsPage.php' );
check( 'A298: HTML settings render wp_editor without media buttons', false !== strpos( $a298_src, "wp_editor( (string) \$value, self::editor_id( \$key )" ) && false !== strpos( $a298_src, "'media_buttons' => false" ) );
$a298_m = new ReflectionMethod( PassCaptain\Admin\SettingsPage::class, 'editor_id' );
$a298_m->setAccessible( true );
check( 'A298: editor ids are valid for wp_editor (lowercase, underscores)', 'wpcap_set_terms_text' === $a298_m->invoke( null, 'terms_text' ) && 1 === preg_match( '/^[a-z0-9_]+$/', $a298_m->invoke( null, 'Withdrawal-Notice_Text' ) ) );
$a298_f = new ReflectionMethod( PassCaptain\Admin\SettingsPage::class, 'field_input_id' );
$a298_f->setAccessible( true );
$a298_expect = function_exists( 'wp_editor' ) ? 'wpcap_set_terms_text' : 'wpcap-set-terms_text';
check( 'A298: the label points at the editor (or the textarea without editor)', $a298_expect === $a298_f->invoke( null, 'terms_text', array( 'type' => 'html' ) ) && 'wpcap-set-currency' === $a298_f->invoke( null, 'currency', array( 'type' => 'text' ) ) );
check( 'A298: the manual gateway is called bank transfer online (no "Zahlung vor Ort")', 'Überweisung' === ( new PassCaptain\Payments\ManualGateway() )->title() );

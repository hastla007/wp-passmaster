<?php
/**
 * A567: the suggested privacy policy text names the retention periods as configured (Einstellungen
 * → Datenschutz) instead of fixed numbers, with its own wording when a step is off (0).
 * Included from run.php.
 */
// phpcs:ignoreFile
use PassCaptain\Privacy\PolicyText;
use PassCaptain\Settings;

echo "A567: privacy policy suggestion uses the configured retention periods\n";
$a567_keys = array( 'retention_waitlist_days', 'retention_unpaid_customer_email_days', 'retention_message_content_days', 'retention_checkin_staff_days', 'retention_audit_logs_days', 'retention_financial_years' );
$a567_saved = array_intersect_key( Settings::all(), array_flip( $a567_keys ) );
$a567_text = static function () { return html_entity_decode( wp_strip_all_tags( PolicyText::content() ), ENT_QUOTES ); };

$a567_default = $a567_text();
check( 'A567: defaults read as before (30/90/30 days, two years now 730 days)', false !== strpos( $a567_default, 'löschen wir 30 Tage nach dem Termin' ) && false !== strpos( $a567_default, 'nach 90 Tagen' ) && false !== strpos( $a567_default, 'inhaltlich nach 30 Tagen' ) && false !== strpos( $a567_default, 'Verwaltungsvorgänge bewahren wir 730 Tage auf' ) && false !== strpos( $a567_default, 'Geräten entfernen wir nach 365 Tagen' ) );
check( 'A567: without a financial retention setting there is no claim of automatic deletion', false !== strpos( $a567_default, 'Eine automatische Löschung nach Ablauf der Aufbewahrungsfrist ist nicht eingestellt.' ) );

Settings::update( array( 'retention_waitlist_days' => 14, 'retention_unpaid_customer_email_days' => 45, 'retention_message_content_days' => 7, 'retention_checkin_staff_days' => 1, 'retention_audit_logs_days' => 400, 'retention_financial_years' => 7 ) );
$a567_custom = $a567_text();
check( 'A567: configured periods appear in the text', false !== strpos( $a567_custom, 'löschen wir 14 Tage nach dem Termin' ) && false !== strpos( $a567_custom, 'nach 45 Tagen' ) && false !== strpos( $a567_custom, 'inhaltlich nach 7 Tagen' ) && false !== strpos( $a567_custom, 'Verwaltungsvorgänge bewahren wir 400 Tage auf' ) && false !== strpos( $a567_custom, 'Geräten entfernen wir nach 1 Tag.' ) );
check( 'A567: fixed numbers of the old text are gone', false === strpos( $a567_custom, '30 Tage nach dem Termin' ) && false === strpos( $a567_custom, 'nach 90 Tagen' ) && false === strpos( $a567_custom, 'zwei Jahre' ) && false === strpos( $a567_custom, 'nach einem Jahr' ) );
check( 'A567: financial records with N years name the automatic deletion', false !== strpos( $a567_custom, 'Ausstellung und 7 weiterer Jahre' ) );

Settings::update( array( 'retention_waitlist_days' => 0, 'retention_unpaid_customer_email_days' => 0, 'retention_message_content_days' => 0, 'retention_checkin_staff_days' => 0, 'retention_audit_logs_days' => 0, 'retention_financial_years' => 0 ) );
$a567_off = $a567_text();
check( 'A567: steps switched off (0) get their own wording instead of "nach 0 Tagen"', false === strpos( $a567_off, ' 0 Tag' ) && false !== strpos( $a567_off, 'Für die E-Mail-Adresse eines Wartelisteneintrags ist keine automatische Löschfrist eingestellt.' ) && false !== strpos( $a567_off, 'Für nicht bezahlte Bestellungen ist keine automatische Anonymisierung eingestellt.' ) && false !== strpos( $a567_off, 'Für Protokolle über Verwaltungsvorgänge ist keine automatische Löschfrist eingestellt.' ) );
add_filter( 'wpcap_privacy_retention_days', $a567_filter = static function ( $days, $step ) { return 'waitlist' === $step ? 21 : $days; }, 10, 2 );
check( 'A567: the retention filter is respected as well', false !== strpos( $a567_text(), 'löschen wir 21 Tage nach dem Termin' ) );
remove_filter( 'wpcap_privacy_retention_days', $a567_filter );
Settings::update( $a567_saved );

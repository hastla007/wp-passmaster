<?php
/**
 * SET-PC-45: net price entry (gross stays the default; stored and shown prices stay gross).
 */
use PassCaptain\Admin\EventsPage;
use PassCaptain\Settings;

echo "SET-PC-45: net price entry\n";
$sp45_saved = get_option( Settings::OPTION, array() );
check( 'SET-PC-45: gross entry is the default and unchanged', ! EventsPage::net_entry() && 1250 === EventsPage::entry_to_gross( 1250, 1900 ) && 'Preis (brutto)' === EventsPage::price_label() );
Settings::update( array( 'price_entry' => 'net' ) );
check( 'SET-PC-45: a net price is stored gross with the tax rate of the type', 1190 === EventsPage::entry_to_gross( 1000, 1900 ) && 1070 === EventsPage::entry_to_gross( 1000, 700 ) && 1000 === EventsPage::entry_to_gross( 1000, 0 ) );
check( 'SET-PC-45: the form shows the stored gross price as net value again', 1000 === EventsPage::gross_to_entry( 1190, 1900 ) && 0 !== strpos( EventsPage::price_label(), 'Preis (brutto)' ) );
check( 'SET-PC-45: the choice is checked', is_wp_error( PassCaptain\SettingsSchema::validate( 'price_entry', 'netto' ) ) );
update_option( Settings::OPTION, $sp45_saved );
Settings::flush();

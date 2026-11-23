<?php
// phpcs:ignoreFile
/**
 * A604: the trunk prefix in brackets after the country code ("+49 (0)151 …", "+49 (0151) …") is
 * dropped when normalizing an SMS number to E.164 instead of becoming part of it (+490151…).
 */
use PassCaptain\Sms\Service;

echo "A604: SMS numbers with (0) after the country code\n";
$a604_cases = array(
	'+49 (0)151 23456789' => '+4915123456789',
	'+49(0)15123456789' => '+4915123456789',
	'0049 (0) 151 2345 6789' => '+4915123456789',
	'+43 (0)664 123456' => '+43664123456',
	'+41 (0) 79 123 45 67' => '+41791234567',
	'+49 (0151) 23456789' => '+4915123456789',
	'+49 (151) 23456789' => '+4915123456789',
	'0151 2345-6789' => '+4915123456789',
	'+39 06 1234 5678' => '+390612345678',
);
$a604_wrong = array();
foreach ( $a604_cases as $a604_in => $a604_want ) {
	$a604_got = Service::normalize_phone( $a604_in );
	if ( $a604_got !== $a604_want ) { $a604_wrong[] = "$a604_in → $a604_got (expected $a604_want)"; }
}
check( 'A604 numbers with (0) or (0…) after the country code normalize to the right E.164 number' . ( $a604_wrong ? ' – ' . implode( '; ', $a604_wrong ) : '' ), ! $a604_wrong );
check( 'A604 invalid input stays invalid', '' === Service::normalize_phone( '+49 (0)' ) && '' === Service::normalize_phone( '(0)' ) );

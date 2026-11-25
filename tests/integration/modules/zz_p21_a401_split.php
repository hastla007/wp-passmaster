<?php
// phpcs:ignoreFile
/**
 * A401 (Pass wave 5, item 7): the longest methods outside Orders are split into private helpers without
 * behaviour change; the public surface stays the same.
 * - Rest\Controller::checkout(): code field, hold, order creation, reuse checks, voucher, payment start, response.
 * - BoxOffice\Service::sell(): scope, payment method/terminal, lines, buyer, sale claim, order, shift lock.
 * - PublicEvents::render_rows_primed(): listing data/early returns, card contract, card context, card filters.
 * Behaviour is covered by the existing modules (A639/A265/manual holds/billing for checkout; boxoffice,
 * A488/A489, W6 faults, POS devices for sell; A342/A376/A293/R6 and the query budgets of p3_performance and
 * zzzzzz_y5_perf for the listing); this module guards the structure.
 */

use PassCaptain\BoxOffice\Service;
use PassCaptain\PublicEvents;
use PassCaptain\Rest\Controller;

echo "A401 (p21): large methods outside Orders split\n";
$p21_len = static function ( $class, $method ) { $r = new ReflectionMethod( $class, $method ); return $r->getEndLine() - $r->getStartLine(); };
$p21_sig = static function ( $class, $method ) {
	$r = new ReflectionMethod( $class, $method );
	return array( $r->isPublic(), $r->isStatic(), array_map( static fn( $p ) => $p->getName() . ( $p->isOptional() ? '?' : '' ), $r->getParameters() ) );
};

check( 'A401 p21: Controller::checkout() keeps its public signature (request, optional context)', array( true, true, array( 'request', 'context?' ) ) === $p21_sig( Controller::class, 'checkout' ) );
check( 'A401 p21: Service::sell() keeps its public signature (input)', array( true, true, array( 'input' ) ) === $p21_sig( Service::class, 'sell' ) );
check( 'A401 p21: PublicEvents::render_rows_primed() stays private with the same parameters', array( false, true, array( 'rows', 'atts', 'wrapper', 'requested' ) ) === $p21_sig( PublicEvents::class, 'render_rows_primed' ) );

$p21_limits = array(
	array( Controller::class, 'checkout', 60 ),
	array( Service::class, 'sell', 60 ),
	array( PublicEvents::class, 'render_rows_primed', 50 ),
);
foreach ( $p21_limits as $p21_l ) {
	check( sprintf( 'A401 p21: %s::%s() is at most %d lines (now %d)', substr( strrchr( $p21_l[0], '\\' ), 1 ), $p21_l[1], $p21_l[2], $p21_len( $p21_l[0], $p21_l[1] ) ), $p21_len( $p21_l[0], $p21_l[1] ) <= $p21_l[2] );
}

$p21_helpers = array(
	Controller::class => array( 'checkout_single_code', 'checkout_hold', 'checkout_create_order', 'checkout_reuse_error', 'checkout_apply_voucher', 'checkout_start_payment', 'checkout_response' ),
	Service::class => array( 'sell_occurrence_error', 'sell_device', 'sell_lines', 'sell_buyer', 'sell_claim', 'sell_order_options', 'sell_order', 'sell_lock_shift' ),
	PublicEvents::class => array( 'listing_cards', 'card_contract_types', 'listing_card_context', 'filter_card_html' ),
);
$p21_bad = array();
foreach ( $p21_helpers as $p21_class => $p21_methods ) {
	foreach ( $p21_methods as $p21_m ) {
		if ( ! method_exists( $p21_class, $p21_m ) || ! ( new ReflectionMethod( $p21_class, $p21_m ) )->isPrivate() ) { $p21_bad[] = $p21_m; }
		elseif ( $p21_len( $p21_class, $p21_m ) > 60 ) { $p21_bad[] = $p21_m . ' (too long)'; }
	}
}
check( 'A401 p21: the new helpers exist, are private and at most 60 lines' . ( $p21_bad ? ' – ' . implode( ', ', $p21_bad ) : '' ), ! $p21_bad );

// The order of the steps is part of the behaviour: refusals before any order or sale row exists.
$p21_body = static function ( $class, $method ) {
	$r = new ReflectionMethod( $class, $method );
	return implode( '', array_slice( file( $r->getFileName() ), $r->getStartLine() - 1, $r->getEndLine() - $r->getStartLine() + 1 ) );
};
$p21_order = static function ( $body, array $needles ) {
	$last = -1;
	foreach ( $needles as $n ) {
		$pos = strpos( $body, $n );
		if ( false === $pos || $pos < $last ) { return false; }
		$last = $pos;
	}
	return true;
};
check( 'A401 p21: checkout() runs guard, code field, provider check, key check, hold, order, reuse check, voucher, payment, response in that order', $p21_order( $p21_body( Controller::class, 'checkout' ), array( 'FormGuard::check', 'self::checkout_single_code(', 'wpcap_provider_unavailable', 'MIN_CHECKOUT_KEY_LENGTH', 'self::checkout_hold(', 'self::checkout_create_order(', 'self::checkout_reuse_error(', "unset( \$result['request_fingerprint']", 'self::checkout_apply_voucher(', 'self::checkout_start_payment(', 'self::checkout_response(' ) ) );
check( 'A401 p21: sell() validates everything before the sale row, then order, terminal start, shift lock, confirmation', $p21_order( $p21_body( Service::class, 'sell' ), array( 'self::existing_result(', 'self::open_session_for(', 'TerminalRegistry::allows', 'self::sell_occurrence_error(', 'self::sell_device(', 'self::sell_lines(', 'self::sell_buyer(', 'self::sell_claim(', 'self::sell_order(', 'self::start_device_payment(', 'self::sell_lock_shift(', 'Orders::confirm_external_payment(', 'self::finalize(' ) ) );
check( 'A401 p21: the card template is still included in render_rows_primed() (theme copies see $event, $types, $card, $providers, $legal)', $p21_order( $p21_body( PublicEvents::class, 'render_rows_primed' ), array( '$providers = self::card_providers(', '$legal = self::legal_context();', 'self::card_contract_types(', '$card = self::listing_card_context(', 'include $template;', 'self::filter_card_html(' ) ) );

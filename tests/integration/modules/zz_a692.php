<?php
// phpcs:ignoreFile
/**
 * A692: docs/WEBHOOKS.md matches the dispatcher (every event type in the event table, the outdated
 * "no order.refunded for dashboard refunds" note is gone, api_version and the schema versions are named)
 * and the webhook version is part of the contract table in docs/CONTRACTS.md.
 */

use PassCaptain\Webhooks\Dispatcher;

$a692_doc = (string) file_get_contents( WPCAP_PATH . 'docs/WEBHOOKS.md' );
$a692_contracts = (string) file_get_contents( WPCAP_PATH . 'docs/CONTRACTS.md' );
preg_match_all( '/^\|\s*`([a-z]+\.[a-z_]+)`\s*\|/m', (string) strstr( (string) strstr( $a692_doc, '## Ereignisse' ), '## Payload', true ), $a692_m );
$a692_missing = array_diff( Dispatcher::EVENTS, $a692_m[1] );
$a692_extra = array_diff( $a692_m[1], Dispatcher::EVENTS );
check( 'A692: the event table of WEBHOOKS.md lists exactly the dispatcher events' . ( $a692_missing ? ' – missing ' . implode( ', ', $a692_missing ) : '' ) . ( $a692_extra ? ' – unknown ' . implode( ', ', $a692_extra ) : '' ), ! $a692_missing && ! $a692_extra );
check( 'A692: the outdated note "no order.refunded for dashboard refunds" is gone; mark_refunded is documented', false === strpos( $a692_doc, 'derzeit kein' ) && false !== strpos( $a692_doc, '`refund.kind = "full"`' ) );
check( 'A692: WEBHOOKS.md names the api_version and the schema version of every type that is not 1.0', false !== strpos( $a692_doc, 'API-Version `' . Dispatcher::API_VERSION . '`' ) && ! array_filter( Dispatcher::SCHEMA_VERSIONS, static fn( $v, $t ) => '1.0' !== $v && false === strpos( $a692_doc, '`' . $t . '` `' . $v . '`' ), ARRAY_FILTER_USE_BOTH ) );
check( 'A692: every dispatcher event has a schema version', array() === array_diff( Dispatcher::EVENTS, array_keys( Dispatcher::SCHEMA_VERSIONS ) ) );
check( 'A692: the contract table of CONTRACTS.md has the webhook version', (bool) preg_match( '/^\|[^|]*Webhooks[^|]*\|\s*`webhooks`\s*\|[^|]*' . preg_quote( Dispatcher::API_VERSION, '/' ) . '/m', $a692_contracts ) );

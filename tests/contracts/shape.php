<?php
/**
 * Shape checker for the contract fixtures in tests/contracts/*.json (A203, format: docs/CONTRACTS.md).
 *
 * A shape describes JSON as clients see it:
 * - a string is a type: int, float, number (int or float), string, bool, null, array (any list),
 *   object (any JSON object), any; unions with "|" ("string|null");
 * - a JSON array [shape] is a list whose elements all match shape ([] = any list);
 * - a JSON object lists the fields: "name" is required, "?name" optional, "*" applies to every
 *   further key (maps). Extra fields of the value are allowed (adding fields is compatible).
 *
 * Values are compared after a JSON round trip, so the PHP types the client actually receives count.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

/** JSON round trip (objects → arrays, as a client decodes them). */
function wpcap_contract_json( $value ) {
	return json_decode( (string) json_encode( $value ), true );
}

/** Whether $value is a JSON list (an empty array counts as list and as object). */
function wpcap_contract_is_list( $value ) {
	return is_array( $value ) && ( array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 ) );
}

function wpcap_contract_type_ok( $type, $value ) {
	switch ( $type ) {
		case 'any': return true;
		case 'int': return is_int( $value );
		case 'float': return is_float( $value );
		case 'number': return is_int( $value ) || is_float( $value );
		case 'string': return is_string( $value );
		case 'bool': return is_bool( $value );
		case 'null': return null === $value;
		case 'array': return wpcap_contract_is_list( $value );
		case 'object': return is_array( $value ) && ( array() === $value || ! wpcap_contract_is_list( $value ) );
	}
	return false;
}

/** Name of the JSON type of a value (for messages). */
function wpcap_contract_type_name( $value ) {
	if ( null === $value ) { return 'null'; }
	if ( is_bool( $value ) ) { return 'bool'; }
	if ( is_int( $value ) ) { return 'int'; }
	if ( is_float( $value ) ) { return 'float'; }
	if ( is_string( $value ) ) { return 'string'; }
	return wpcap_contract_is_list( $value ) ? 'array' : 'object';
}

/**
 * Violations of $value against $shape: removed/renamed fields ("missing"), type changes ("type").
 *
 * @return string[] Empty when the value fulfils the contract.
 */
function wpcap_contract_violations( $shape, $value, $path = '$', $json = true ) {
	if ( $json ) { $value = wpcap_contract_json( $value ); }
	$out = array();
	if ( is_string( $shape ) ) {
		foreach ( explode( '|', $shape ) as $type ) {
			if ( wpcap_contract_type_ok( trim( $type ), $value ) ) { return array(); }
		}
		return array( "$path: type $shape expected, got " . wpcap_contract_type_name( $value ) );
	}
	if ( is_array( $shape ) && wpcap_contract_is_list( $shape ) && array() !== $shape ) {
		if ( ! wpcap_contract_is_list( $value ) ) { return array( "$path: list expected, got " . wpcap_contract_type_name( $value ) ); }
		foreach ( $value as $i => $item ) { $out = array_merge( $out, wpcap_contract_violations( $shape[0], $item, "{$path}[$i]", false ) ); }
		return $out;
	}
	if ( array() === $shape ) { return wpcap_contract_is_list( $value ) ? array() : array( "$path: list expected, got " . wpcap_contract_type_name( $value ) ); }
	if ( ! is_array( $value ) || ( array() !== $value && wpcap_contract_is_list( $value ) ) ) { return array( "$path: object expected, got " . wpcap_contract_type_name( $value ) ); }
	$named = array();
	foreach ( $shape as $key => $sub ) {
		if ( '*' === $key ) { continue; }
		$optional = '?' === $key[0];
		$name = $optional ? substr( $key, 1 ) : $key;
		$named[ $name ] = true;
		if ( ! array_key_exists( $name, $value ) ) {
			if ( ! $optional ) { $out[] = "$path.$name: missing"; }
			continue;
		}
		$out = array_merge( $out, wpcap_contract_violations( $sub, $value[ $name ], "$path.$name", false ) );
	}
	if ( isset( $shape['*'] ) ) {
		foreach ( $value as $key => $item ) {
			if ( ! isset( $named[ (string) $key ] ) ) { $out = array_merge( $out, wpcap_contract_violations( $shape['*'], $item, "$path.$key", false ) ); }
		}
	}
	return $out;
}

/** Shape of a sample value (used to record a new fixture; review nullable/optional fields by hand). */
function wpcap_contract_shape_of( $value, $json = true ) {
	if ( $json ) { $value = wpcap_contract_json( $value ); }
	if ( ! is_array( $value ) ) { return wpcap_contract_type_name( $value ); }
	if ( array() === $value ) { return array(); }
	if ( wpcap_contract_is_list( $value ) ) {
		$merged = null;
		foreach ( $value as $item ) { $merged = null === $merged ? wpcap_contract_shape_of( $item, false ) : wpcap_contract_merge( $merged, wpcap_contract_shape_of( $item, false ) ); }
		return array( $merged );
	}
	$out = array();
	foreach ( $value as $key => $item ) { $out[ (string) $key ] = wpcap_contract_shape_of( $item, false ); }
	return $out;
}

/** Union of two recorded shapes (list elements of different form). */
function wpcap_contract_merge( $a, $b ) {
	if ( is_string( $a ) && is_string( $b ) ) {
		$types = array_unique( array_merge( explode( '|', $a ), explode( '|', $b ) ) );
		sort( $types );
		return implode( '|', $types );
	}
	if ( is_array( $a ) && is_array( $b ) && ! wpcap_contract_is_list( $a ) && ! wpcap_contract_is_list( $b ) ) {
		$out = array();
		$keys = array_unique( array_merge( array_map( static fn( $k ) => ltrim( $k, '?' ), array_keys( $a ) ), array_map( static fn( $k ) => ltrim( $k, '?' ), array_keys( $b ) ) ) );
		foreach ( $keys as $key ) {
			$in_a = array_key_exists( $key, $a ) ? $a[ $key ] : ( $a[ '?' . $key ] ?? null );
			$in_b = array_key_exists( $key, $b ) ? $b[ $key ] : ( $b[ '?' . $key ] ?? null );
			$required = array_key_exists( $key, $a ) && array_key_exists( $key, $b );
			$shape = null === $in_a ? $in_b : ( null === $in_b ? $in_a : wpcap_contract_merge( $in_a, $in_b ) );
			$out[ ( $required ? '' : '?' ) . $key ] = $shape;
		}
		return $out;
	}
	if ( is_array( $a ) && is_array( $b ) && wpcap_contract_is_list( $a ) && wpcap_contract_is_list( $b ) ) {
		if ( ! $a ) { return $b; }
		if ( ! $b ) { return $a; }
		return array( wpcap_contract_merge( $a[0], $b[0] ) );
	}
	return 'any';
}

/** Loads a fixture file (tests/contracts/<name>.json). */
function wpcap_contract_fixture( $name ) {
	$file = __DIR__ . '/' . $name . '.json';
	$data = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
	return is_array( $data ) ? $data : array();
}

/**
 * Records a missing endpoint shape when WPCAP_CONTRACTS_RECORD=1 (bootstrapping a new fixture);
 * never overwrites an existing entry.
 */
function wpcap_contract_record( $name, $endpoint, $part, $value ) {
	if ( '1' !== getenv( 'WPCAP_CONTRACTS_RECORD' ) ) { return false; }
	$file = __DIR__ . '/' . $name . '.json';
	$data = wpcap_contract_fixture( $name );
	if ( isset( $data['endpoints'][ $endpoint ][ $part ] ) ) { return false; }
	$data['endpoints'][ $endpoint ][ $part ] = 'response' === $part || 'request' === $part ? wpcap_contract_shape_of( $value ) : $value;
	file_put_contents( $file, json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
	return true;
}

/** Replaces "{{name}}" placeholders of a request example (whole-string placeholders keep the value's type). */
function wpcap_contract_fill( $example, array $vars ) {
	if ( is_array( $example ) ) {
		$out = array();
		foreach ( $example as $k => $v ) { $out[ $k ] = wpcap_contract_fill( $v, $vars ); }
		return $out;
	}
	if ( is_string( $example ) && preg_match( '/^\{\{([a-z0-9_]+)\}\}$/', $example, $m ) && array_key_exists( $m[1], $vars ) ) { return $vars[ $m[1] ]; }
	if ( is_string( $example ) ) {
		return preg_replace_callback( '/\{\{([a-z0-9_]+)\}\}/', static fn( $m ) => array_key_exists( $m[1], $vars ) ? (string) $vars[ $m[1] ] : $m[0], $example );
	}
	return $example;
}

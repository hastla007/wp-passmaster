<?php
/**
 * Contract test for the versioned public hooks: every `*_v1` hook documented in docs/HOOKS.md (as a
 * table row) appears in the plugin code, and every `*_v1` hook name used in the code is documented.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

echo "Hooks contract: versioned *_v1 hooks in docs/HOOKS.md match the code\n";
$hc_doc = (string) file_get_contents( WPCAP_PATH . 'docs/HOOKS.md' );
preg_match_all( '/^\|\s*`((?:wp_pass_captain|wpcap)_[a-z0-9_]+_v1)`\s*\|/m', $hc_doc, $hc_m );
$hc_documented = array_values( array_unique( $hc_m[1] ) );
sort( $hc_documented );

$hc_code = array();
$hc_files = array();
foreach ( array( 'src', 'templates' ) as $hc_dir ) {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WPCAP_PATH . $hc_dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		if ( 'php' !== $file->getExtension() ) { continue; }
		$src = (string) file_get_contents( $file->getPathname() );
		// String literals only (calls, add_filter, class constants) – mentions in comments do not count.
		foreach ( token_get_all( $src ) as $token ) {
			if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] && preg_match( '/^[\'"]((?:wp_pass_captain|wpcap)_[a-z0-9_]+_v1)[\'"]$/', $token[1], $mm ) ) {
				$hc_code[ $mm[1] ] = true;
				$hc_files[ $mm[1] ][] = substr( $file->getPathname(), strlen( WPCAP_PATH ) );
			}
		}
	}
}
$hc_code = array_keys( $hc_code );
sort( $hc_code );

$hc_undocumented = array_values( array_diff( $hc_code, $hc_documented ) );
$hc_missing = array_values( array_diff( $hc_documented, $hc_code ) );
check( 'HOOKS.md documents at least the known contracts (' . count( $hc_documented ) . ' *_v1 hooks)', count( $hc_documented ) >= 18 && in_array( 'wp_pass_captain_process_job_v1', $hc_documented, true ) && in_array( 'wp_pass_captain_settings_sections_v1', $hc_documented, true ) );
check( 'every *_v1 hook used in the code is documented in HOOKS.md' . ( $hc_undocumented ? ' – missing: ' . implode( ', ', $hc_undocumented ) : '' ), array() === $hc_undocumented );
check( 'every *_v1 hook documented in HOOKS.md exists in the code' . ( $hc_missing ? ' – not found: ' . implode( ', ', $hc_missing ) : '' ), array() === $hc_missing );

// Each documented hook is actually fired (apply_filters/do_action with the literal or via a class constant).
$hc_fired = array();
$hc_all_src = '';
foreach ( array( 'src', 'templates' ) as $hc_dir ) {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WPCAP_PATH . $hc_dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) { if ( 'php' === $file->getExtension() ) { $hc_all_src .= file_get_contents( $file->getPathname() ) . "\n"; } }
}
foreach ( $hc_documented as $hook ) {
	$direct = preg_match( '/(apply_filters|do_action)\(\s*[\'"]' . preg_quote( $hook, '/' ) . '[\'"]/', $hc_all_src );
	$via_const = preg_match( '/const\s+([A-Z_]+)\s*=\s*[\'"]' . preg_quote( $hook, '/' ) . '[\'"]/', $hc_all_src, $cm ) && preg_match( '/(apply_filters|do_action)\(\s*(self|static|[A-Za-z\\\\]+)::' . $cm[1] . '\b/', $hc_all_src );
	if ( ! $direct && ! $via_const ) { $hc_fired[] = $hook; }
}
check( 'every documented *_v1 hook is fired by the core' . ( $hc_fired ? ' – never fired: ' . implode( ', ', $hc_fired ) : '' ), array() === $hc_fired );

/*
 * R8 (audit round 2, expert #1): signatures and unversioned hooks. Every hook with the plugin prefix that the code
 * fires (apply_filters, do_action, their _deprecated and _ref_array variants – literal names or class constants),
 * schedules as cron event or only provides for third parties (add_filter without a core call) must be a table row
 * in docs/HOOKS.md, and the number of documented arguments (the `$name`s in the "Argumente" column) must match:
 * no call passes more, the longest passes exactly as many. Public `wp_pass_captain_*` hooks carry `_v1` except the
 * documented deprecated names, which may only fire through apply_filters_deprecated()/do_action_deprecated().
 */
echo "Hooks contract: every wpcap_/wp_pass_captain_ hook is documented with its arity\n";
$hc_ws = static function ( $t ) { return ! is_array( $t ) || ! in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ); };
$hc_php = array( WPCAP_PATH . 'pass-captain.php' );
foreach ( array( 'src', 'templates' ) as $hc_dir ) {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WPCAP_PATH . $hc_dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) { if ( 'php' === $file->getExtension() ) { $hc_php[] = $file->getPathname(); } }
}
sort( $hc_php );
$hc_tokens = array();
$hc_consts = array(); // file => const => value.
$hc_classes = array(); // class short name => file.
foreach ( $hc_php as $file ) {
	$t = array_values( array_filter( token_get_all( (string) file_get_contents( $file ) ), $hc_ws ) );
	$hc_tokens[ $file ] = $t;
	foreach ( $t as $i => $tok ) {
		if ( is_array( $tok ) && T_CONST === $tok[0] && is_array( $t[ $i + 1 ] ?? null ) && '=' === ( $t[ $i + 2 ] ?? null ) && is_array( $t[ $i + 3 ] ?? null ) && T_CONSTANT_ENCAPSED_STRING === $t[ $i + 3 ][0] ) {
			$hc_consts[ $file ][ $t[ $i + 1 ][1] ] = substr( $t[ $i + 3 ][1], 1, -1 );
		}
		if ( is_array( $tok ) && in_array( $tok[0], array( T_CLASS, T_INTERFACE, T_TRAIT ), true ) && is_array( $t[ $i + 1 ] ?? null ) && T_STRING === $t[ $i + 1 ][0] ) { $hc_classes[ $t[ $i + 1 ][1] ] = $file; }
	}
}
/** Top-level arguments of the call whose "(" is at $open: list of token lists. */
$hc_args = static function ( array $t, $open ) {
	$depth = 0; $args = array(); $cur = array();
	for ( $j = $open, $n = count( $t ); $j < $n; $j++ ) {
		$x = $t[ $j ];
		$opens = in_array( $x, array( '(', '[', '{' ), true ) || ( is_array( $x ) && in_array( $x[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) );
		if ( $opens ) { ++$depth; if ( 1 === $depth ) { continue; } }
		if ( in_array( $x, array( ')', ']', '}' ), true ) ) { --$depth; if ( 0 === $depth ) { if ( $cur ) { $args[] = $cur; } break; } }
		if ( 1 === $depth && ',' === $x ) { $args[] = $cur; $cur = array(); continue; }
		$cur[] = $x;
	}
	return $args;
};
/** Hook name of an argument (string literal or class constant), or null when dynamic. */
$hc_name = static function ( array $arg, $file ) use ( &$hc_consts, &$hc_classes ) {
	if ( 1 === count( $arg ) && is_array( $arg[0] ) && T_CONSTANT_ENCAPSED_STRING === $arg[0][0] ) { return substr( $arg[0][1], 1, -1 ); }
	if ( 3 === count( $arg ) && is_array( $arg[1] ) && T_DOUBLE_COLON === $arg[1][0] && is_array( $arg[2] ) ) {
		$class = is_array( $arg[0] ) ? $arg[0][1] : '';
		$short = substr( (string) strrchr( '\\' . $class, '\\' ), 1 );
		$source = in_array( strtolower( $class ), array( 'self', 'static' ), true ) ? $file : ( $hc_classes[ $short ] ?? '' );
		return $hc_consts[ $source ][ $arg[2][1] ] ?? null;
	}
	return null;
};
/** Number of elements of an array( … ) / [ … ] literal argument. */
$hc_array_count = static function ( array $arg ) {
	$depth = 0; $count = 0; $any = false;
	foreach ( $arg as $x ) {
		if ( in_array( $x, array( '(', '[' ), true ) ) { ++$depth; if ( 1 === $depth ) { continue; } }
		if ( in_array( $x, array( ')', ']' ), true ) ) { --$depth; continue; }
		if ( 1 === $depth && ',' === $x ) { ++$count; } elseif ( $depth >= 1 ) { $any = true; }
	}
	return $any ? $count + 1 : 0;
};
$hc_prefixed = static function ( $hook ) { return (bool) preg_match( '/^(wpcap|wp_pass_captain)_[a-z0-9_]+$/', (string) $hook ); };
$hc_fire = array(); $hc_provide = array(); $hc_dynamic = array();
$hc_fire_fns = array( 'apply_filters', 'do_action', 'apply_filters_deprecated', 'do_action_deprecated', 'apply_filters_ref_array', 'do_action_ref_array' );
foreach ( $hc_tokens as $file => $t ) {
	$rel = substr( $file, strlen( WPCAP_PATH ) );
	foreach ( $t as $i => $tok ) {
		if ( ! is_array( $tok ) || T_STRING !== $tok[0] || '(' !== ( $t[ $i + 1 ] ?? null ) ) { continue; }
		$prev = $t[ $i - 1 ] ?? null;
		if ( is_array( $prev ) && in_array( $prev[0], array( T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NULLSAFE_OBJECT_OPERATOR ), true ) ) { continue; }
		$fn = $tok[1];
		$is_fire = in_array( $fn, $hc_fire_fns, true );
		$is_cron = in_array( $fn, array( 'wp_schedule_single_event', 'wp_schedule_event' ), true );
		$is_add = in_array( $fn, array( 'add_filter', 'add_action' ), true );
		if ( ! $is_fire && ! $is_cron && ! $is_add ) { continue; }
		$args = $hc_args( $t, $i + 1 );
		$name_arg = $is_cron ? ( 'wp_schedule_event' === $fn ? ( $args[2] ?? array() ) : ( $args[1] ?? array() ) ) : ( $args[0] ?? array() );
		$hook = $hc_name( $name_arg, $file );
		if ( null === $hook ) {
			$src = implode( '', array_map( static function ( $x ) { return is_array( $x ) ? $x[1] : $x; }, $name_arg ) );
			if ( $is_fire && preg_match( '/[\'"](wpcap|wp_pass_captain)_/', $src ) ) { $hc_dynamic[] = "$rel:{$tok[2]}"; }
			continue;
		}
		if ( ! $hc_prefixed( $hook ) ) { continue; }
		if ( $is_fire ) {
			$deprecated = false !== strpos( $fn, '_deprecated' );
			$arity = ( $deprecated || false !== strpos( $fn, '_ref_array' ) ) ? $hc_array_count( $args[1] ?? array() ) : count( $args ) - 1;
			$hc_fire[ $hook ][] = array( "$rel:{$tok[2]}", $arity, $deprecated );
		} elseif ( $is_cron ) {
			$cron_args = 'wp_schedule_event' === $fn ? ( $args[3] ?? null ) : ( $args[2] ?? null );
			$hc_fire[ $hook ][] = array( "$rel:{$tok[2]} (cron)", null === $cron_args ? 0 : $hc_array_count( $cron_args ), false );
		} else {
			$accepted = isset( $args[3] ) && 1 === count( $args[3] ) && is_array( $args[3][0] ) && T_LNUMBER === $args[3][0][0] ? (int) $args[3][0][1] : 1;
			$hc_provide[ $hook ][] = array( "$rel:{$tok[2]} (add_filter)", $accepted, false );
		}
	}
}
// Hooks only registered with add_filter/add_action and never fired or scheduled by the core are provided for
// third parties (e.g. the voucher services): their documented arity is the accepted argument count.
foreach ( $hc_provide as $hook => $rows ) {
	if ( ! isset( $hc_fire[ $hook ] ) ) { $hc_fire[ $hook ] = array( array( $rows[0][0], max( array_column( $rows, 1 ) ), false ) ); }
}
// Documented hooks (table rows; a "|" inside backticks belongs to the cell, e.g. `true|WP_Error $result`).
$hc_doc_args = array();
foreach ( preg_split( '/\R/', $hc_doc ) as $line ) {
	if ( '|' !== substr( ltrim( $line ), 0, 1 ) ) { continue; }
	$cells = array(); $cell = ''; $tick = false;
	foreach ( str_split( trim( $line ) ) as $ch ) {
		if ( '`' === $ch ) { $tick = ! $tick; }
		if ( '|' === $ch && ! $tick ) { $cells[] = $cell; $cell = ''; continue; }
		$cell .= $ch;
	}
	if ( count( $cells ) < 3 || ! preg_match( '/^\s*`([a-z0-9_]+)`\s*$/', $cells[1], $hm ) ) { continue; }
	preg_match_all( '/`([^`]*)`/', $cells[2], $am );
	$hc_doc_args[ $hm[1] ] = preg_match_all( '/\$[A-Za-z_][A-Za-z0-9_]*/', implode( ' ', $am[1] ) );
}
$hc_deprecated_doc = array();
if ( preg_match( '/^### Veraltete Namen.*?(?=^##|\z)/ms', $hc_doc, $dm ) && preg_match_all( '/^\|\s*`([a-z0-9_]+)`\s*\|/m', $dm[0], $dmm ) ) { $hc_deprecated_doc = $dmm[1]; }

$hc_not_documented = array(); $hc_arity = array(); $hc_unfired = array(); $hc_unversioned = array();
foreach ( $hc_fire as $hook => $rows ) {
	if ( ! array_key_exists( $hook, $hc_doc_args ) ) { $hc_not_documented[] = $hook . ' (' . $rows[0][0] . ')'; continue; }
	$counts = array_column( $rows, 1 );
	if ( max( $counts ) !== $hc_doc_args[ $hook ] ) {
		$hc_arity[] = $hook . ': documented ' . $hc_doc_args[ $hook ] . ', code ' . implode( ', ', array_map( static function ( $r ) { return $r[0] . '=' . $r[1]; }, $rows ) );
	}
	if ( 0 === strpos( $hook, 'wp_pass_captain_' ) && '_v1' !== substr( $hook, -3 ) ) {
		$only_deprecated = ! in_array( false, array_column( $rows, 2 ), true );
		if ( ! in_array( $hook, $hc_deprecated_doc, true ) || ! $only_deprecated ) { $hc_unversioned[] = $hook; }
	}
}
foreach ( array_keys( $hc_doc_args ) as $hook ) {
	if ( $hc_prefixed( $hook ) && ! isset( $hc_fire[ $hook ] ) ) { $hc_unfired[] = $hook; }
}
check( 'R8: the tokenizer finds the hook calls (sanity: ' . count( $hc_fire ) . ' prefixed hooks, constants resolved)', count( $hc_fire ) > 150 && isset( $hc_fire['wp_pass_captain_voucher_provider_v1'], $hc_fire['wp_pass_captain_ticket_service_v1'], $hc_fire['wpcap_process_fiscal_order'] ) );
check( 'R8: no prefixed hook with a dynamic name' . ( $hc_dynamic ? ' – ' . implode( ', ', $hc_dynamic ) : '' ), array() === $hc_dynamic );
check( 'R8: every prefixed hook of the code (also unversioned) is documented in HOOKS.md' . ( $hc_not_documented ? ' – missing: ' . implode( ', ', $hc_not_documented ) : '' ), array() === $hc_not_documented );
check( 'R8: every prefixed hook documented in HOOKS.md exists in the code' . ( $hc_unfired ? ' – not found: ' . implode( ', ', $hc_unfired ) : '' ), array() === $hc_unfired );
check( 'R8: documented argument count matches the call sites' . ( $hc_arity ? ' – ' . implode( '; ', $hc_arity ) : '' ), array() === $hc_arity );
check( 'R8: wp_pass_captain_* hooks are versioned (_v1), old names only via *_deprecated' . ( $hc_unversioned ? ' – ' . implode( ', ', $hc_unversioned ) : '' ), array() === $hc_unversioned );
check( 'R8: the five previously undocumented hooks are documented', 5 === count( array_intersect( array( 'wpcap_buyer_locales', 'wpcap_checkout_user_roles', 'wpcap_data_profile', 'wpcap_db_tracks_in_transaction', 'wpcap_webhook_claim_wait_ms' ), array_keys( $hc_doc_args ) ) ) );

echo "Hooks contract: renamed hooks keep firing under the old name\n";
$GLOBALS['wpcap_test_deprecated_hooks'] = array();
$hc_old = static function ( $list ) { $list[] = 'SEK'; return $list; };
$hc_new = static function ( $list ) { $list[] = 'NOK'; return $list; };
add_filter( 'wp_pass_captain_currencies', $hc_old );
add_filter( 'wp_pass_captain_currencies_v1', $hc_new );
$hc_cur = PassCaptain\Localization::currencies();
remove_filter( 'wp_pass_captain_currencies', $hc_old );
remove_filter( 'wp_pass_captain_currencies_v1', $hc_new );
check( 'R8: wp_pass_captain_currencies_v1 and the deprecated wp_pass_captain_currencies both apply', in_array( 'SEK', $hc_cur, true ) && in_array( 'NOK', $hc_cur, true ) && in_array( 'wp_pass_captain_currencies', $GLOBALS['wpcap_test_deprecated_hooks'], true ) );
$GLOBALS['wpcap_test_deprecated_hooks'] = array();
PassCaptain\Localization::currencies();
check( 'R8: without a callback on the old name no deprecation is reported', array() === $GLOBALS['wpcap_test_deprecated_hooks'] );
$hc_cal_src = (string) file_get_contents( WPCAP_PATH . 'src/Modules/Calendar.php' );
$hc_pe_src = (string) file_get_contents( WPCAP_PATH . 'src/PublicEvents.php' );
check( 'R8: the event card filter fires as _v1 (calendar anchor uses it) and still under the old name', false !== strpos( $hc_cal_src, "'wp_pass_captain_event_card_html_v1'" ) && false !== strpos( $hc_pe_src, "apply_filters_deprecated( 'wp_pass_captain_event_card_html'" ) && false !== strpos( $hc_pe_src, "apply_filters( 'wp_pass_captain_event_card_html_v1'" ) );

echo "Hooks contract: wp_pass_captain_http_connector_data_v1 passes the endpoint id as int\n";
$hc_seen = null;
$hc_cb = static function ( $data, $endpoint_id ) use ( &$hc_seen ) { $hc_seen = $endpoint_id; return $data; };
add_filter( 'wp_pass_captain_http_connector_data_v1', $hc_cb, 10, 2 );
PassCaptain\Integrations\HttpJson\Endpoints::map( (object) array( 'endpoint_id' => '7', 'include_pii' => 0, 'mapping' => array( array( 'key' => 'event', 'source' => 'event.id' ) ) ), 'order_paid', array() );
remove_filter( 'wp_pass_captain_http_connector_data_v1', $hc_cb, 10 );
check( 'R8: endpoint_id is an int as documented (rows from $wpdb carry strings)', 7 === $hc_seen );

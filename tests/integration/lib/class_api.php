<?php
/**
 * A402: public add-on API at class level and a tokenizer scan of the class docblocks in src/.
 *
 * Public classes are the ones docs/EXTENSIONS.md, docs/CONTRACTS.md, src/Api/* and PublicApi offer to
 * add-ons (to call, implement or read a contract version from). Every other class carries `@internal`
 * in its class docblock (checked by modules/zz_a402_internal.php), so a new class has to decide.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

if ( ! defined( 'WPCAP_PUBLIC_CLASSES' ) ) {
	define(
		'WPCAP_PUBLIC_CLASSES',
		array(
			// src/Api/* (versioned PHP API, docs/EXTENSIONS.md "Öffentliche PHP-API").
			'PassCaptain\\Api\\Actions',
			'PassCaptain\\Api\\Auth',
			'PassCaptain\\Api\\Invoices',
			'PassCaptain\\Api\\ReadFacade',
			'PassCaptain\\Api\\TicketDetails',
			'PassCaptain\\Api\\Tickets',
			// Add-on API version and contract versions (docs/CONTRACTS.md).
			'PassCaptain\\PublicApi',
			'PassCaptain\\OfflineScanner\\Package',
			'PassCaptain\\Review\\Contract',
			'PassCaptain\\Review\\ContactResolver',
			'PassCaptain\\Integrations\\ConnectorEvents',
			// Methods named in the version table of docs/EXTENSIONS.md.
			'PassCaptain\\Orders',
			'PassCaptain\\Access',
			'PassCaptain\\Jobs',
			'PassCaptain\\BoxOffice\\Service',
			'PassCaptain\\Integrations\\ConnectorAudit',
			'PassCaptain\\Integrations\\ConnectorRegistry',
			'PassCaptain\\Integrations\\Vouchers\\Reconciliation',
			'PassCaptain\\SettingsSchema',
			'PassCaptain\\Settings', // Settings::get() (EXTENSIONS.md, extension point "Einstellungen").
			'PassCaptain\\Admin\\Menu',
			// Interfaces add-ons implement (docs/EXTENSIONS.md; fiscal adapters: docs/HOOKS.md wpcap_fiscal_adapters).
			'PassCaptain\\Payments\\GatewayInterface',
			'PassCaptain\\Payments\\PaymentLookupInterface',
			'PassCaptain\\Payments\\RefundLookupInterface',
			'PassCaptain\\Sms\\ProviderInterface',
			'PassCaptain\\Wallet\\ProviderInterface',
			'PassCaptain\\Integrations\\Vouchers\\ProviderInterface',
			'PassCaptain\\Integrations\\ConnectorInterface',
			'PassCaptain\\BoxOffice\\DeviceGatewayInterface',
			'PassCaptain\\Fiscalization\\AdapterInterface',
			'PassCaptain\\Fiscalization\\ClosingAdapterInterface',
		)
	);
	/**
	 * Classes the docs name although they are no public API (implementation or storage named for
	 * orientation, or explicitly called internal there). They carry @internal like every other class.
	 */
	define(
		'WPCAP_NAMED_INTERNAL_CLASSES',
		array(
			'PassCaptain\\Audit',                          // EXTENSIONS.md: "die interne Klasse PassCaptain\Audit".
			'PassCaptain\\Reports\\Data',                  // EXTENSIONS.md: "bleiben interne Klassen ohne Versionszusage".
			'PassCaptain\\Reports\\Scope',
			'PassCaptain\\Suite\\Outbox',                  // CONTRACTS.md: storage of the review events.
			'PassCaptain\\Integrations\\CalendarFeeds',    // EXTENSIONS.md: implementation of the ICS feeds.
			'PassCaptain\\Integrations\\Vouchers\\Bridge', // CONTRACTS.md: the core side calling the provider.
			'PassCaptain\\Workflow',                       // CONTRACTS.md: named as removed (PC-7).
		)
	);
}

if ( ! function_exists( 'wpcap_test_class_docblocks' ) ) {
	/**
	 * Every class, interface, trait and enum declared in $dir: FQCN => [file, line, docblock or ''].
	 *
	 * @return array<string, array{0: string, 1: int, 2: string}>
	 */
	function wpcap_test_class_docblocks( $dir ) {
		$out = array();
		$it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( 'php' !== $file->getExtension() ) { continue; }
			$tokens    = token_get_all( (string) file_get_contents( $file->getPathname() ) );
			$namespace = '';
			$doc       = '';
			$count     = count( $tokens );
			for ( $i = 0; $i < $count; $i++ ) {
				$t = $tokens[ $i ];
				if ( ! is_array( $t ) ) {
					if ( ';' === $t || '{' === $t || '}' === $t ) { $doc = ''; }
					continue;
				}
				if ( T_DOC_COMMENT === $t[0] ) { $doc = $t[1]; continue; }
				if ( in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_FINAL, T_ABSTRACT, T_READONLY, T_ATTRIBUTE ), true ) ) { continue; }
				if ( T_NAMESPACE === $t[0] ) {
					$namespace = '';
					for ( $j = $i + 1; $j < $count && ';' !== $tokens[ $j ] && '{' !== $tokens[ $j ]; $j++ ) {
						if ( is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_NAME_QUALIFIED, T_STRING ), true ) ) { $namespace .= $tokens[ $j ][1]; }
					}
					$doc = '';
					continue;
				}
				if ( in_array( $t[0], array( T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM ), true ) ) {
					// Skip "::class", "new class" and "enum" used as an identifier.
					$prev = $i - 1;
					while ( $prev >= 0 && is_array( $tokens[ $prev ] ) && T_WHITESPACE === $tokens[ $prev ][0] ) { $prev--; }
					$next = $i + 1;
					while ( $next < $count && is_array( $tokens[ $next ] ) && T_WHITESPACE === $tokens[ $next ][0] ) { $next++; }
					$is_decl = $next < $count && is_array( $tokens[ $next ] ) && T_STRING === $tokens[ $next ][0]
						&& ! ( $prev >= 0 && is_array( $tokens[ $prev ] ) && in_array( $tokens[ $prev ][0], array( T_DOUBLE_COLON, T_NEW ), true ) );
					if ( $is_decl ) {
						$name         = ( '' !== $namespace ? $namespace . '\\' : '' ) . $tokens[ $next ][1];
						$out[ $name ] = array( $file->getPathname(), (int) $t[2], $doc );
					}
				}
				$doc = '';
			}
		}
		ksort( $out );
		return $out;
	}
}

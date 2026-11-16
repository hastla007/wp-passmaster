<?php
/**
 * Serves the offline scanner PWA from the site's own origin (A286).
 *
 * The app only talks to a same-origin API (the device token must never go to another host) and its
 * service worker only works for files on its own origin. When the plugin's assets are delivered from
 * a CDN domain (WPCAP_URL / plugins_url rewritten, or a CDN plugin rewriting /wp-content/ URLs), the
 * app loaded from the asset URL could reach neither. The shell is therefore served under
 * home_url( '/wpcap-offline-scanner/app/' ) (plain permalinks: '/index.php/wpcap-offline-scanner/app/'),
 * with the vendored scripts under '…/vendor/', so the relative paths of index.html and sw.js stay valid.
 *
 * @package PassCaptain
 */

namespace PassCaptain\OfflineScanner;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Pwa {
	const PREFIX = 'wpcap-offline-scanner/';

	/** Route path (below the prefix) => file below the plugin directory. Nothing else is served. */
	const FILES = array(
		'app/'                             => 'assets/offline-scanner/index.html',
		'app/index.html'                   => 'assets/offline-scanner/index.html',
		'app/app.js'                       => 'assets/offline-scanner/app.js',
		'app/app.css'                      => 'assets/offline-scanner/app.css',
		'app/i18n.js'                      => 'assets/offline-scanner/i18n.js',
		'app/sw.js'                        => 'assets/offline-scanner/sw.js',
		'app/manifest.json'                => 'assets/offline-scanner/manifest.json',
		'app/icon.svg'                     => 'assets/offline-scanner/icon.svg',
		'app/icon-192.png'                 => 'assets/offline-scanner/icon-192.png',
		'app/icon-512.png'                 => 'assets/offline-scanner/icon-512.png',
		'vendor/tweetnacl/nacl-fast.min.js' => 'assets/vendor/tweetnacl/nacl-fast.min.js',
		'vendor/jsqr/jsQR.js'              => 'assets/vendor/jsqr/jsQR.js',
	);

	const TYPES = array(
		'html' => 'text/html; charset=utf-8',
		'js'   => 'text/javascript; charset=utf-8',
		'css'  => 'text/css; charset=utf-8',
		'json' => 'application/manifest+json; charset=utf-8',
		'svg'  => 'image/svg+xml',
		'png'  => 'image/png',
	);

	public static function register() {
		add_action( 'init', array( __CLASS__, 'maybe_serve' ), 1, 0 );
	}

	/** Base URL of the app shell on the site's own origin (trailing slash). */
	public static function base_url() {
		$pretty = function_exists( 'get_option' ) ? '' !== (string) get_option( 'permalink_structure' ) : true;
		$url = home_url( '/' . ( $pretty ? '' : 'index.php/' ) . self::PREFIX . 'app/' );
		/**
		 * Filters the URL the offline scanner PWA is served from (must be on the origin of the REST API).
		 *
		 * @param string $url    URL ending in "/app/".
		 * @param bool   $pretty Whether pretty permalinks are active.
		 */
		return (string) apply_filters( 'wpcap_offline_scanner_app_base', $url, $pretty );
	}

	/**
	 * Maps a request URI to the file to serve, or null when the request is not for the app.
	 *
	 * @param string $request_uri REQUEST_URI.
	 * @param string $home_path   Path of home_url() (e.g. "/" or "/blog/").
	 * @return string|null Absolute path, '' for an unknown file under the prefix (404).
	 */
	public static function resolve( $request_uri, $home_path ) {
		$path = (string) wp_parse_url( (string) $request_uri, PHP_URL_PATH );
		$home = '/' . trim( (string) $home_path, '/' );
		$home = '/' === $home ? '/' : $home . '/';
		if ( 0 !== strpos( $path, $home ) ) { return null; }
		$rest = substr( $path, strlen( $home ) );
		if ( 0 === strpos( $rest, 'index.php/' ) ) { $rest = substr( $rest, strlen( 'index.php/' ) ); }
		if ( 0 !== strpos( $rest, self::PREFIX ) ) { return null; }
		$file = substr( $rest, strlen( self::PREFIX ) );
		if ( '' === $file || 'app' === $file ) { $file = 'app/'; }
		return isset( self::FILES[ $file ] ) ? WPCAP_PATH . self::FILES[ $file ] : '';
	}

	/** init: serves a shell file (or a 404) and ends the request. */
	public static function maybe_serve() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $uri || false === strpos( $uri, self::PREFIX ) ) { return; }
		$file = self::resolve( $uri, (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
		if ( null === $file ) { return; }
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		if ( '' === $file || ! is_readable( $file ) ) {
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'Not found';
			\PassCaptain\Http::end();
		}
		$headers = self::headers( $file );
		status_header( 200 );
		foreach ( $headers as $name => $value ) { header( $name . ': ' . $value ); }
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- static shell file of the plugin.
		\PassCaptain\Http::end();
	}

	/** Response headers for a shell file. */
	public static function headers( $file ) {
		$ext = strtolower( pathinfo( (string) $file, PATHINFO_EXTENSION ) );
		return array(
			'Content-Type'           => self::TYPES[ $ext ] ?? 'application/octet-stream',
			// The service worker refreshes the shell itself; caches must revalidate (sw.js above all).
			'Cache-Control'          => 'no-cache',
			'X-Content-Type-Options' => 'nosniff',
			'X-Robots-Tag'           => 'noindex, nofollow',
			'Referrer-Policy'        => 'no-referrer',
		);
	}
}

<?php
/**
 * Hook names (A104).
 *
 * Every internal hook uses the prefix `wpcap_`. Versioned cross-plugin contracts keep the long
 * `wp_pass_captain_*_v1` name on purpose (other plugins call them; the version suffix is part of the
 * contract): voucher provider, ticket service, check-in service, voucher tickets revoked, wallet
 * provider, connectors and connector error. Older internal hooks were renamed; their old names still
 * work for existing code and are reported as deprecated via apply_filters_deprecated().
 *
 * @package PassCaptain
 */

namespace PassCaptain;

defined( 'ABSPATH' ) || exit;

final class Hooks {
	/** Old name => new name. Callbacks on the old name keep running after the new hook. */
	const RENAMED = array(
		'wp_pass_captain_process_job_v1'    => 'wpcap_process_job',
		'wp_pass_captain_email_template_v1' => 'wpcap_email_template',
		'wp_pass_captain_event_card_html'   => 'wpcap_event_card_html',
		'wp_pass_captain_currencies'        => 'wpcap_currencies',
	);

	/** Version in which the old names were deprecated. */
	const DEPRECATED_SINCE = '1.5.0';

	/**
	 * Runs callbacks still registered on the deprecated name of `$new` (after the new hook ran) and
	 * returns the filtered value. Without such callbacks it returns `$value` unchanged and emits no notice.
	 *
	 * @param string $new   New hook name (a value of RENAMED).
	 * @param mixed  $value Value already filtered by the new hook.
	 * @param mixed  ...$args Further hook arguments.
	 * @return mixed
	 */
	public static function legacy_filter( $new, $value, ...$args ) {
		$old = array_search( $new, self::RENAMED, true );
		if ( false === $old || ( function_exists( 'has_filter' ) && ! has_filter( $old ) ) ) {
			return $value;
		}
		if ( function_exists( 'apply_filters_deprecated' ) ) {
			return apply_filters_deprecated( $old, array_merge( array( $value ), $args ), self::DEPRECATED_SINCE, $new );
		}
		return apply_filters( $old, $value, ...$args ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- deprecated alias from RENAMED.
	}
}

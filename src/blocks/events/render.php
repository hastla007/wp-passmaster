<?php
/** Dynamic block server renderer (same output as [wp_pass_captain_events event="" occurrence="" limit="" layout=""]). */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$wpcap_attributes = isset( $attributes ) && is_array( $attributes ) ? $attributes : array();
$wpcap_html = \PassCaptain\PublicEvents::render_shortcode(
	array(
		'event' => absint( $wpcap_attributes['eventId'] ?? 0 ),
		'occurrence' => absint( $wpcap_attributes['occurrenceId'] ?? 0 ),
		'limit' => absint( $wpcap_attributes['limit'] ?? 50 ),
		'layout' => sanitize_key( (string) ( $wpcap_attributes['layout'] ?? 'auto' ) ),
	)
);
echo $wpcap_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer escapes all output.

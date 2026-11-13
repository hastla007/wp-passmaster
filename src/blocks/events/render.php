<?php
/** Dynamic block server renderer. Attributes: event, occurrence (0 = all). */
if ( ! defined( 'ABSPATH' ) ) { exit; }
echo \PassCaptain\PublicEvents::render_shortcode( array( 'event' => absint( $attributes['event'] ?? 0 ), 'occurrence' => absint( $attributes['occurrence'] ?? 0 ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

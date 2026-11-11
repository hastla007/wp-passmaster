<?php
/** Dynamic block server renderer for the event calendar. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$wpcap_view = ( isset( $attributes['view'] ) && 'list' === $attributes['view'] ) ? 'list' : 'month';
$wpcap_event = isset( $attributes['eventId'] ) ? absint( $attributes['eventId'] ) : 0;
echo \PassCaptain\Modules\Calendar::render_shortcode( array( 'view' => $wpcap_view, 'event' => $wpcap_event ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer escapes all output.

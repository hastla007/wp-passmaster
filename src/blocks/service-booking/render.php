<?php
/**
 * Dynamic block server renderer (A268). Attributes: eventId, resourceId (0 = all resources of the
 * event). The block wrapper attributes (alignment, spacing, custom class) go on the root element.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
echo \PassCaptain\Frontend\ServiceBooking::render_block( (array) $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer escapes all output.

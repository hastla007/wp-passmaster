<?php
/**
 * Dynamic block server renderer. Attributes: event, occurrence (0 = all). The block wrapper
 * attributes (block class, custom class, alignment, spacing) go on the root element (A449).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
echo \PassCaptain\PublicEvents::render_block( (array) $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer escapes all output.

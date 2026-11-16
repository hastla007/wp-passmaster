<?php
/**
 * Dynamic block server renderer for the event calendar. Attributes: view (month|list), eventId.
 * The block wrapper attributes (block class, custom class, alignment, spacing) go on the root
 * element (A449).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
echo \PassCaptain\Modules\Calendar::render_block( (array) $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer escapes all output.

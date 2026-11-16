<?php
/**
 * Dynamic block server renderer (same output as [wp_pass_captain_events event="" occurrence="" limit="" layout="" dates=""]).
 * Attributes: event, occurrence (0 = all), perDate, limit, 'layout' (auto|list|cards); eventId/occurrenceId of
 * older blocks are read as event/occurrence. The block wrapper attributes (block class, custom class,
 * alignment, spacing) go on the root element (A449).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
echo \PassCaptain\PublicEvents::render_block( (array) $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer escapes all output.

<?php
/**
 * A311, A549, A550: attendee blocks in the buyer checkout. The behaviour (entries kept per slot,
 * renumbered legends, short note in .wpcap-qty-live) is covered by tests/js/checkout.test.js; here
 * the markup and texts the script relies on. Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

echo "A311/A549/A550: attendee blocks\n";

$a311_template = (string) file_get_contents( WPCAP_PATH . 'templates/event-card.php' );
check( 'A311 attendee area is no live region (a quantity change must not read out every field)', false !== strpos( $a311_template, '<div class="wpcap-attendees"></div>' ) && 1 !== preg_match( '#class="wpcap-attendees"[^>]*aria-live#', $a311_template ) );
check( 'A311 quantity live region stays (short attendee note goes there)', false !== strpos( $a311_template, '<p class="wpcap-qty-live wpcap-sr-only" aria-live="polite" aria-atomic="true"></p>' ) );

$a311_events = (string) file_get_contents( WPCAP_PATH . 'src/PublicEvents.php' );
check( 'A311 attendee note texts (singular and plural) are translatable', false !== strpos( $a311_events, "'attendeesOne' => __( 'Weiter unten: Angaben für 1 Teilnehmer:in.', 'pass-captain' )" ) && false !== strpos( $a311_events, "'attendeesMany' => __( 'Weiter unten: Angaben für %d Teilnehmende.', 'pass-captain' )" ) );

$a311_js = (string) file_get_contents( WPCAP_PATH . 'assets/checkout.js' );
check( 'A549 attendee blocks are kept per form and slot (WeakMap cache), not only during one render', false !== strpos( $a311_js, 'const attendeeSlots = new WeakMap();' ) && false !== strpos( $a311_js, 'cache[slot] = fs;' ) );
check( 'A550 legends of reused blocks are renumbered', false !== strpos( $a311_js, "const label = attendeeLegend(count, qty.dataset.name);" ) && false !== strpos( $a311_js, "legend.textContent = label;" ) );
check( 'A311/A549/A550 node tests exist', file_exists( WPCAP_PATH . 'tests/js/checkout.test.js' ) && file_exists( WPCAP_PATH . 'tests/js/fake-dom.js' ) );

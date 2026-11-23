<?php
/**
 * A090: seat map on mobile – the picker gets the texts of the new controls (view switch, seat size, fit,
 * legend marks); behaviour is covered by tests/js/seating-picker-a090.test.js.
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

echo "A090: seat map on mobile (texts and assets)\n";
$z090_t = PassCaptain\Seating\Picker::i18n();
check( 'A090: texts for map/list switch, seat size, fit and the companion mark', '' !== ( $z090_t['viewMap'] ?? '' ) && '' !== ( $z090_t['viewList'] ?? '' ) && '' !== ( $z090_t['seatLarge'] ?? '' ) && false !== strpos( $z090_t['seatLargeOn'] ?? '', '%d' ) && '' !== ( $z090_t['fit'] ?? '' ) && '' !== ( $z090_t['companionShort'] ?? '' ) );
$z090_js = (string) file_get_contents( WPCAP_PATH . 'assets/seating/picker.js' );
$z090_css = (string) file_get_contents( WPCAP_PATH . 'assets/seating/picker.css' );
check( 'A090: picker draws category shapes, marks states, handles pinch and offers the list view', false !== strpos( $z090_js, "const SHAPES = ['circle', 'square', 'diamond', 'triangle']" ) && false !== strpos( $z090_js, "'pointermove'" ) && false !== strpos( $z090_js, 'Picker.prototype.setView' ) && false !== strpos( $z090_js, 'TARGET_LARGE = 44' ) );
check( 'A090: CSS lets one finger pan natively and hides the inactive view', false !== strpos( $z090_css, 'touch-action: pan-x pan-y' ) && false !== strpos( $z090_css, '.wpcap-seating-list[hidden]' ) );

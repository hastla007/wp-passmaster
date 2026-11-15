<?php
/**
 * Event detail page (/{base}/{slug}/). Override in the theme as wp-pass-captain/event-single.php.
 *
 * Available: PassCaptain\Frontend\EventPage::current() (event row: name, description, image_url,
 * category, status), EventPage::tickets_html( $event ) (dates and ticket selection).
 *
 * @package PassCaptain
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$wpcap_event = \PassCaptain\Frontend\EventPage::current();
get_header();
?>
<main id="primary" class="site-main wpcap-event-page">
	<article class="wpcap-event-single">
		<header class="entry-header">
			<?php if ( ! empty( $wpcap_event->category ) ) : ?>
				<p class="wpcap-event-category"><?php echo esc_html( $wpcap_event->category ); ?></p>
			<?php endif; ?>
			<h1 class="entry-title"><?php echo esc_html( $wpcap_event->name ); ?></h1>
			<?php if ( 'cancelled' === $wpcap_event->status ) : ?>
				<p class="wpcap-event-cancelled"><strong><?php esc_html_e( 'Diese Veranstaltung wurde abgesagt.', 'pass-captain' ); ?></strong></p>
			<?php endif; ?>
		</header>
		<?php if ( ! empty( $wpcap_event->image_url ) ) : ?>
			<figure class="wpcap-event-image"><img src="<?php echo esc_url( $wpcap_event->image_url ); ?>" alt="<?php echo esc_attr( $wpcap_event->name ); ?>" loading="eager" decoding="async"></figure>
		<?php endif; ?>
		<div class="entry-content">
			<?php echo wp_kses_post( wpautop( (string) $wpcap_event->description ) ); ?>
			<?php if ( 'cancelled' !== $wpcap_event->status ) : ?>
				<section class="wpcap-event-tickets" aria-label="<?php esc_attr_e( 'Termine und Tickets', 'pass-captain' ); ?>">
					<h2><?php esc_html_e( 'Termine und Tickets', 'pass-captain' ); ?></h2>
					<?php echo \PassCaptain\Frontend\EventPage::tickets_html( $wpcap_event ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered and escaped by the shortcode/event cards. ?>
				</section>
			<?php endif; ?>
		</div>
	</article>
</main>
<?php
get_footer();

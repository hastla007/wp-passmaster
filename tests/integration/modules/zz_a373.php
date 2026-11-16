<?php
/**
 * A373: no synchronous provider-side mail work in the payment webhook (ticket mail deferred to the
 * outbox job after COMMIT) and service booking mails only through the plugin mailer (sender name,
 * Reply-To), never a bare wp_mail().
 */

echo "A373: async ticket delivery, service booking mails via Mailer\n";
$a373_flow = (string) file_get_contents( WPCAP_PATH . 'src/ServiceBookingFlow.php' );
check( 'A373: ServiceBookingFlow sends no bare wp_mail()', ! preg_match( '/(?<![A-Za-z_:>])wp_mail\s*\(/', $a373_flow ) && 2 <= substr_count( $a373_flow, 'Mailer::send_plain(' ) );
check( 'A373: the payment webhook defers the ticket mail to the job queue', str_contains( (string) file_get_contents( WPCAP_PATH . 'src/Payments/Webhooks.php' ), 'Orders::defer_ticket_mail(' ) );

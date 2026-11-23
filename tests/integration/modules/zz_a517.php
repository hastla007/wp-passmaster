<?php
/**
 * A517: request caches of site data that are not keyed by table prefix (staff assignments, provider
 * resources, active discount codes, …) are emptied on switch_blog, so switch_to_blog() never answers
 * from the previous site's cache.
 * Included from run.php (uses check(), make_type()).
 */
// phpcs:ignoreFile
use PassCaptain\Campaigns;
use PassCaptain\Plugin;

global $wpdb;
$p = $wpdb->prefix;
echo "A517: site request caches are flushed on switch_blog\n";

// Real case: a discount code of site A must not count on site B after switch_to_blog().
$x2_a517_type = make_type( 5 );
$x2_a517_event = (int) $wpdb->get_var( "SELECT o.event_id FROM {$p}wpcap_ticket_types tt JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=$x2_a517_type" );
Campaigns::flush();
$x2_a517_code = Campaigns::create( array( 'code' => 'X2A517' . substr( md5( uniqid() ), 0, 4 ), 'kind' => 'percent', 'value' => 1000, 'currency' => 'XTS', 'event_id' => $x2_a517_event ) );
$x2_a517_site_a = Campaigns::has_active( $x2_a517_event, 'XTS' );
// "Site B": same tables but without the code (as if switch_to_blog() changed the prefix).
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_campaigns SET status='inactive' WHERE campaign_id=%d", is_array( $x2_a517_code ) ? (int) ( $x2_a517_code['campaign_id'] ?? 0 ) : (int) $x2_a517_code ) );
$x2_a517_hooks = $GLOBALS['wpcap_test_hooks']['switch_blog'] ?? null;
unset( $GLOBALS['wpcap_test_hooks']['switch_blog'] );
foreach ( Plugin::site_cache_flushers() as $x2_a517_flush ) { add_action( 'switch_blog', $x2_a517_flush, 10, 0 ); }
do_action( 'switch_blog', 2, 1 );
check( 'A517: after switch_blog the discount-code cache of the previous site is gone', $x2_a517_site_a && false === Campaigns::has_active( $x2_a517_event, 'XTS' ) );

// Every listed cache is really emptied.
$x2_a517_props = array(
	array( PassCaptain\StaffAssignments::class, 'cache' ), array( PassCaptain\StaffAssignments::class, 'team' ),
	array( PassCaptain\ServiceProviders::class, 'user_resources' ), array( Campaigns::class, 'active' ),
	array( PassCaptain\HelpCode::class, 'assigned' ), array( PassCaptain\LegalVersions::class, 'known' ),
	array( PassCaptain\Series\Pricing::class, 'cache' ), array( PassCaptain\Partners\Repository::class, 'terms' ),
	array( PassCaptain\Exchange\Service::class, 'supplement_cache' ),
);
foreach ( $x2_a517_props as $x2_a517_prop ) {
	$x2_a517_r = new ReflectionProperty( $x2_a517_prop[0], $x2_a517_prop[1] );
	$x2_a517_r->setAccessible( true );
	$x2_a517_r->setValue( null, array( 1 => array( 'stale' ) ) );
}
do_action( 'switch_blog', 1, 2 );
$x2_a517_stale = array();
foreach ( $x2_a517_props as $x2_a517_prop ) {
	$x2_a517_r = new ReflectionProperty( $x2_a517_prop[0], $x2_a517_prop[1] );
	$x2_a517_r->setAccessible( true );
	if ( array() !== $x2_a517_r->getValue() ) { $x2_a517_stale[] = $x2_a517_prop[0] . '::$' . $x2_a517_prop[1]; }
}
check( 'A517: every site cache is emptied on switch_blog' . ( $x2_a517_stale ? ' – ' . implode( ', ', $x2_a517_stale ) : '' ), array() === $x2_a517_stale );
if ( null === $x2_a517_hooks ) { unset( $GLOBALS['wpcap_test_hooks']['switch_blog'] ); } else { $GLOBALS['wpcap_test_hooks']['switch_blog'] = $x2_a517_hooks; }

check( 'A517: Plugin registers the flushers on switch_blog', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Plugin.php' ), "foreach ( self::site_cache_flushers() as \$flush ) { add_action( 'switch_blog', \$flush, 10, 0 ); }" ) );
Campaigns::flush();

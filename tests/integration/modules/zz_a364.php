<?php
/**
 * A364: the waitlist queue option is changed under a lock and run_due() removes only the types it
 * took, from the queue as stored (a type queued in between is not lost); named GET_LOCK locks are
 * unique per installation (hash of prefix + DB_NAME), not just per table prefix.
 * Included from run.php (uses check()).
 */
// phpcs:ignoreFile
use PassCaptain\DbLock;
use PassCaptain\Waitlists;

global $wpdb;
echo "A364: waitlist queue lock and installation-scoped lock names\n";

// Option store that simulates a concurrent queue() right after run_due() read the queue.
final class X2_A364_Store {
	public array $data;
	public int $reads = 0;
	public $inject = null;
	public function __construct( array $data ) { $this->data = $data; }
	public function get( $k, $d = false ) {
		if ( Waitlists::QUEUE_OPTION === $k ) {
			++$this->reads;
			$value = $this->data[ $k ] ?? $d;
			if ( 1 === $this->reads && null !== $this->inject ) { $this->data[ $k ] = (array) ( $this->data[ $k ] ?? array() ) + $this->inject; }
			return $value;
		}
		return $this->data[ $k ] ?? $d;
	}
	public function update( $k, $v ) { $this->data[ $k ] = $v; return true; }
	public function delete( $k ) { unset( $this->data[ $k ] ); return true; }
}

$x2_a364_now = PassCaptain\Clock::now();
$x2_a364_store = new X2_A364_Store( $GLOBALS['wpcap_test_options'] );
// Type 990001 is due; 990002 gets queued by a "parallel request" after the first read.
$x2_a364_store->data[ Waitlists::QUEUE_OPTION ] = array( 990001 => $x2_a364_now - 10 );
$x2_a364_store->inject = array( 990002 => $x2_a364_now + 600 );
$GLOBALS['wpcap_test_option_store'] = $x2_a364_store;
Waitlists::run_due();
$x2_a364_after = (array) ( $x2_a364_store->data[ Waitlists::QUEUE_OPTION ] ?? array() );
unset( $GLOBALS['wpcap_test_option_store'] );
check( 'A364: run_due() removes the type it processed', ! isset( $x2_a364_after[990001] ) );
check( 'A364: a type queued while run_due() was running is kept', isset( $x2_a364_after[990002] ) );

// queue() waits for the queue lock held by another session (bounded wait), then still writes.
$x2_a364_other = new mysqli( getenv( 'WPCAP_TEST_DB_HOST' ) ?: '127.0.0.1', getenv( 'WPCAP_TEST_DB_USER' ) ?: 'wp', getenv( 'WPCAP_TEST_DB_PASS' ) ?: 'wp', getenv( 'WPCAP_TEST_DB_NAME' ) ?: 'wp' );
$x2_a364_held = '1' === (string) $x2_a364_other->query( "SELECT GET_LOCK('" . DbLock::name( Waitlists::QUEUE_LOCK ) . "',0)" )->fetch_row()[0];
check( 'A364: run_due() does not touch the queue while another session holds the queue lock', $x2_a364_held && 0 === Waitlists::run_due( true ) );
$x2_a364_other->query( "SELECT RELEASE_LOCK('" . DbLock::name( Waitlists::QUEUE_LOCK ) . "')" );

// Ticket type lock: process_type() refuses while the installation-scoped lock is held elsewhere.
$x2_a364_type = make_type( 5 );
$x2_a364_held = '1' === (string) $x2_a364_other->query( "SELECT GET_LOCK('" . DbLock::name( 'wl_' . $x2_a364_type ) . "',0)" )->fetch_row()[0];
$x2_a364_old_name_free = '1' === (string) $x2_a364_other->query( "SELECT IS_FREE_LOCK('" . substr( $wpdb->prefix . 'wpcap_wl_' . $x2_a364_type, 0, 64 ) . "')" )->fetch_row()[0];
$x2_a364_locked_out = Waitlists::process_type( $x2_a364_type, true );
$x2_a364_other->query( "SELECT RELEASE_LOCK('" . DbLock::name( 'wl_' . $x2_a364_type ) . "')" );
$x2_a364_other->close();
check( 'A364: process_type() uses the installation-scoped lock name', $x2_a364_held && $x2_a364_old_name_free && 0 === $x2_a364_locked_out );

// Names: same prefix in another database gives another name; prefix differences too; ≤ 64 chars.
$x2_a364_prefix = $wpdb->prefix;
$x2_a364_a = DbLock::name( 'wl_1' );
$wpdb->prefix = 'wp_';
$x2_a364_wp = DbLock::name( 'wl_1' );
$wpdb->prefix = 'wp2_';
$x2_a364_wp2 = DbLock::name( 'wl_1' );
$wpdb->prefix = $x2_a364_prefix;
check( 'A364: lock names differ per installation and stay within 64 characters', $x2_a364_wp !== $x2_a364_wp2 && $x2_a364_a !== $x2_a364_wp && 64 >= strlen( DbLock::name( str_repeat( 'x', 100 ) ) ) && 0 === strpos( $x2_a364_a, 'wpcap_' ) );
check( 'A364: the hash covers DB_NAME as well (source)', false !== strpos( file_get_contents( WPCAP_PATH . 'src/DbLock.php' ), "defined( 'DB_NAME' ) ? DB_NAME" ) );

// No raw GET_LOCK with a hand-made name left in src/ (only DbLock and Installer::run_locked()).
$x2_a364_raw = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WPCAP_PATH . 'src' ) ) as $x2_a364_f ) {
	if ( '.php' !== substr( (string) $x2_a364_f, -4 ) ) { continue; }
	$x2_a364_rel = substr( (string) $x2_a364_f, strlen( WPCAP_PATH . 'src/' ) );
	if ( in_array( $x2_a364_rel, array( 'DbLock.php', 'Installer.php', 'DbReplication.php' ), true ) ) { continue; }
	if ( preg_match( '/GET_LOCK\s*\(/', file_get_contents( (string) $x2_a364_f ) ) ) { $x2_a364_raw[] = $x2_a364_rel; }
}
check( 'A364: every other named lock goes through DbLock' . ( $x2_a364_raw ? ' – ' . implode( ', ', $x2_a364_raw ) : '' ), array() === $x2_a364_raw );

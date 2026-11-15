<?php
/**
 * Shared fault-injecting $wpdb proxy for integration tests.
 *
 * Forwards everything to the real test connection (Test_WPDB) but lets a test fail selected
 * statements the way MySQL/MariaDB really fail:
 *
 *  - 'error'     generic statement error (last_error = "Injected fault"), nothing else happens
 *  - 'deadlock'  errno 1213: the server rolls the WHOLE transaction back (InnoDB deadlock victim)
 *  - 'lock_wait' errno 1205: only the statement fails, the transaction stays open
 *                (innodb_rollback_on_timeout=OFF, the server default)
 *  - 'gone'      lost connection (2013 / 2006): the open transaction is lost and every following
 *                statement fails as well until the test calls restore()/heal()
 *  - 'silent'    statement "succeeds" with 0 affected rows / empty result (lost race, filtered row)
 *
 * Rules: pattern (regex on the SQL; insert/update/delete are matched against a synthetic
 * "INSERT INTO t (cols)" / "UPDATE t SET k='v' WHERE ..." / "DELETE FROM t WHERE ..."), skip
 * (matches let through first), times (failures), kind.
 *
 * Usage:
 *     $f = wpcap_faults();                       // installs the proxy as $GLOBALS['wpdb']
 *     $f->fail( '/^COMMIT/', 0, 1, 'gone' );
 *     ... run the flow ...
 *     wpcap_faults_restore();                    // real $wpdb back, open transaction rolled back
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

if ( ! class_exists( 'Wpcap_Fault_WPDB' ) ) {
	final class Wpcap_Fault_WPDB {
		const MESSAGES = array(
			'error'     => 'Injected fault',
			'deadlock'  => 'Deadlock found when trying to get lock; try restarting transaction',
			'lock_wait' => 'Lock wait timeout exceeded; try restarting transaction',
			'gone'      => 'Lost connection to MySQL server during query',
		);
		const ERRNO = array( 'error' => 1105, 'deadlock' => 1213, 'lock_wait' => 1205, 'gone' => 2013 );

		public array $rules = array();
		/** Statements seen (for assertions on what a flow executed). */
		public array $log = array();
		/** Number of injected failures. */
		public int $injected = 0;
		/** True after a 'gone' fault: everything fails until heal(). */
		public bool $down = false;
		public int $last_errno = 0;
		/** Statement index => kind: fail exactly the n-th statement (0-based) the proxy sees. */
		public array $nth = array();
		/** Statements seen so far. */
		public int $count = 0;

		public function __construct( public object $inner ) {}

		/**
		 * @param string     $pattern Regex on the SQL.
		 * @param int        $skip    Matches to let through first.
		 * @param int        $times   Number of failures.
		 * @param string|bool $kind   error|deadlock|lock_wait|gone|silent (true = silent, false = error; legacy).
		 */
		public function fail( $pattern, $skip = 0, $times = 1, $kind = 'error' ) {
			if ( true === $kind ) { $kind = 'silent'; }
			if ( false === $kind || null === $kind ) { $kind = 'error'; }
			$this->rules[] = compact( 'pattern', 'skip', 'times', 'kind' );
			return $this;
		}

		/** Fails exactly the n-th statement (0-based) with $kind (systematic fault matrices). */
		public function fail_nth( $n, $kind = 'error' ) { $this->nth[ (int) $n ] = $kind; return $this; }

		/** Ends the "connection lost" state (a reconnect). */
		public function heal() { $this->down = false; return $this; }

		/** Remaining (not yet triggered) failures. */
		public function pending() { return array_sum( array_map( static fn( $r ) => max( 0, $r['times'] ), $this->rules ) ); }

		public function __get( $name ) { return $this->inner->$name ?? null; }
		public function __set( $name, $value ) { $this->inner->$name = $value; }
		public function __isset( $name ) { return isset( $this->inner->$name ); }
		public function __call( $name, $args ) { return $this->inner->$name( ...$args ); }

		/** null = run it, false = injected error, 0 = injected silent no-op. */
		private function intercept( $sql ) {
			$sql = (string) $sql;
			$this->log[] = $sql;
			$index = $this->count++;
			if ( $this->down ) { return $this->inject( 'gone', $sql ); }
			if ( isset( $this->nth[ $index ] ) ) { return $this->inject( $this->nth[ $index ], $sql ); }
			foreach ( $this->rules as $i => $rule ) {
				if ( $rule['times'] <= 0 || ! preg_match( $rule['pattern'], $sql ) ) { continue; }
				if ( $rule['skip'] > 0 ) { $this->rules[ $i ]['skip']--; return null; }
				$this->rules[ $i ]['times']--;
				return $this->inject( $rule['kind'], $sql );
			}
			return null;
		}

		/** Statements that take row locks (and can therefore deadlock or wait for a lock), plus COMMIT (Galera certification). */
		public static function locks( $sql ) {
			return (bool) preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|COMMIT)\b|\bFOR\s+UPDATE\b|\bLOCK\s+IN\s+SHARE\s+MODE\b/i', (string) $sql );
		}

		private function inject( $kind, $sql = '' ) {
			++$this->injected;
			// A plain consistent read never deadlocks or waits for a lock: it can only fail outright.
			if ( in_array( $kind, array( 'deadlock', 'lock_wait' ), true ) && ! self::locks( $sql ) ) { $kind = 'error'; }
			if ( 'silent' === $kind ) { $this->inner->last_error = ''; $this->inner->rows_affected = 0; return 0; }
			if ( 'deadlock' === $kind || 'gone' === $kind ) {
				// The server rolls the transaction back (deadlock victim) or the session is lost.
				$this->inner->query( 'ROLLBACK' );
			}
			if ( 'gone' === $kind ) { $this->down = true; }
			$this->last_errno = self::ERRNO[ $kind ] ?? 1105;
			$this->inner->last_error = self::MESSAGES[ $kind ] ?? self::MESSAGES['error'];
			return false;
		}

		public function prepare( $query, ...$args ) { return $this->inner->prepare( $query, ...$args ); }
		public function query( $sql ) { $r = $this->intercept( $sql ); return null === $r ? $this->inner->query( $sql ) : $r; }
		public function get_results( $sql, $output = OBJECT ) { return null === $this->intercept( $sql ) ? $this->inner->get_results( $sql, $output ) : array(); }
		public function get_row( $sql, $output = OBJECT ) { return null === $this->intercept( $sql ) ? $this->inner->get_row( $sql, $output ) : null; }
		public function get_col( $sql ) { return null === $this->intercept( $sql ) ? $this->inner->get_col( $sql ) : array(); }
		public function get_var( $sql ) { return null === $this->intercept( $sql ) ? $this->inner->get_var( $sql ) : null; }
		private static function pairs( array $data, $glue ) { $o = array(); foreach ( $data as $k => $v ) { $o[] = null === $v ? "$k=NULL" : "$k='$v'"; } return implode( $glue, $o ); }
		public function insert( $table, $data, $format = null ) {
			$r = $this->intercept( "INSERT INTO $table (" . implode( ',', array_keys( $data ) ) . ')' );
			return null === $r ? $this->inner->insert( $table, $data, $format ) : $r;
		}
		public function replace( $table, $data, $format = null ) {
			$r = $this->intercept( "REPLACE INTO $table (" . implode( ',', array_keys( $data ) ) . ')' );
			return null === $r ? $this->inner->replace( $table, $data, $format ) : $r;
		}
		public function update( $table, $data, $where, $format = null, $where_format = null ) {
			$r = $this->intercept( "UPDATE $table SET " . self::pairs( $data, ',' ) . ' WHERE ' . self::pairs( $where, ' AND ' ) );
			return null === $r ? $this->inner->update( $table, $data, $where, $format, $where_format ) : $r;
		}
		public function delete( $table, $where, $format = null ) {
			$r = $this->intercept( "DELETE FROM $table WHERE " . self::pairs( $where, ' AND ' ) );
			return null === $r ? $this->inner->delete( $table, $where, $format ) : $r;
		}
	}
}

if ( ! function_exists( 'wpcap_faults' ) ) {
	/** Installs a fresh fault proxy around the real connection as $GLOBALS['wpdb'] and returns it. */
	function wpcap_faults() {
		$real = $GLOBALS['wpcap_real_wpdb'] ?? null;
		if ( ! $real ) {
			$real = $GLOBALS['wpdb'] instanceof Wpcap_Fault_WPDB ? $GLOBALS['wpdb']->inner : $GLOBALS['wpdb'];
			$GLOBALS['wpcap_real_wpdb'] = $real;
		}
		$GLOBALS['wpdb'] = new Wpcap_Fault_WPDB( $real );
		return $GLOBALS['wpdb'];
	}

	/** Puts the real connection back and rolls back whatever transaction a failed flow left open. */
	function wpcap_faults_restore() {
		if ( $GLOBALS['wpdb'] instanceof Wpcap_Fault_WPDB ) { $GLOBALS['wpdb'] = $GLOBALS['wpdb']->inner; }
		$GLOBALS['wpdb']->query( 'ROLLBACK' );
		$GLOBALS['wpdb']->last_error = '';
	}

	/** Every injected kind of database failure, for fault matrices. */
	function wpcap_fault_kinds() { return array( 'error', 'deadlock', 'lock_wait', 'gone' ); }
}

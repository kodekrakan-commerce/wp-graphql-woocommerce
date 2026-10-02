<?php
/** Explicit SQL/transaction substitute; no database or application bootstrap. */
final class Transfer_Contract_Database implements \WLCommerce\Database\Owned_Scope_Driver {
	public $prefix = 'wp_';
	public $options = 'wp_options';
	public $users = 'wp_users';
	public $last_error = '';
	public $database = DB_NAME;
	public $engines;
	public $rows = [];
	public $ledger = [];
	public $pending;
	public $handle;
	public $grants = [];
	public $commands = [];
	public $abort_handles = [];
	public $faults = [];
	public $on_dual;
	public $on_command;
	private $states;
	private $prepared = [];

	public function __construct() {
		$this->states = new SplObjectStorage();
		$this->engines = [ (object) [ 'TABLE_NAME' => 'wp_woocommerce_sessions', 'ENGINE' => 'InnoDB' ],
			(object) [ 'TABLE_NAME' => 'wp_options', 'ENGINE' => 'InnoDB' ] ];
	}
	public function begin_owned_scope( array $lock_names, int $timeout_seconds ): object {
		if ( $this->handle && ! in_array( $this->states[$this->handle]['state'], [ 'released', 'aborted' ], true ) ) {
			throw new RuntimeException( 'Nested synthetic grant refused.' );
		}
		if ( 2 === count( $lock_names ) && isset( $this->faults['dual-begin'] ) ) {
			throw new RuntimeException( 'Synthetic acquisition failure.' );
		}
		$this->handle = new stdClass();
		$this->states[$this->handle] = [ 'state' => 'active', 'failed' => false ];
		$this->grants[] = [ 'handle' => $this->handle, 'locks' => $lock_names, 'timeout' => $timeout_seconds ];
		if ( 2 === count( $lock_names ) && $this->on_dual ) { ( $this->on_dual )( $this ); }
		return $this->handle;
	}
	public function assert_owned( object $handle ): void {
		$status = $this->get_failure_state( $handle );
		if ( $handle !== $this->handle || $status['failed'] || ! in_array( $status['state'], [ 'active', 'sealed' ], true ) ) {
			throw new RuntimeException( 'Synthetic ownership unavailable.' );
		}
	}
	public function seal_owned_scope( object $handle ): void {
		$this->assert_owned( $handle );
		if ( isset( $this->faults['seal'] ) ) { throw new RuntimeException( 'Synthetic seal failure.' ); }
		if ( null !== $this->pending ) { throw new RuntimeException( 'Synthetic open transaction.' ); }
		$this->states[$handle] = [ 'state' => 'sealed', 'failed' => false ];
	}
	public function release_owned_scope( object $handle ): void {
		$this->assert_owned( $handle );
		if ( isset( $this->faults['release'] ) ) { throw new RuntimeException( 'Synthetic release failure.' ); }
		if ( 'sealed' !== $this->states[$handle]['state'] ) { throw new RuntimeException( 'Synthetic unsealed grant.' ); }
		$this->states[$handle] = [ 'state' => 'released', 'failed' => false ];
	}
	public function abort_owned_scope( object $handle ): void {
		$this->abort_handles[] = $handle;
		if ( ! $this->states->contains( $handle ) ) { throw new RuntimeException( 'Unknown captured handle.' ); }
		if ( $handle !== $this->handle || 'released' === $this->states[$handle]['state'] ) {
			throw new RuntimeException( 'Retired cleanup authority.' );
		}
		$this->pending = null; // Best effort model only; no confirmed rollback receipt.
		$this->states[$handle] = [ 'state' => 'aborted', 'failed' => true ];
		if ( isset( $this->faults['abort'] ) ) { throw new RuntimeException( 'Synthetic cleanup failure.' ); }
	}
	public function get_failure_state( object $handle ): array {
		if ( ! $this->states->contains( $handle ) ) { throw new RuntimeException( 'Unknown captured handle.' ); }
		return $this->states[$handle];
	}
	public function prepare( $query, ...$arguments ) {
		$this->assert_owned( $this->handle );
		storage_expect( [] !== $arguments && str_contains( $query, '%' ) );
		$key = 'private-prepared-' . count( $this->prepared );
		$this->prepared[$key] = [ $query, $arguments ];
		return $key;
	}
	private function decode( $query ) { return $this->prepared[$query] ?? [ $query, [] ]; }
	private function fault( $label, $result ) {
		$fault = $this->faults[$label] ?? [];
		if ( isset( $fault['post_fail'] ) ) { $this->states[$this->handle] = [ 'state' => 'active', 'failed' => true ]; }
		if ( isset( $fault['replace_global'] ) ) { $GLOBALS['wpdb'] = new stdClass(); }
		if ( isset( $fault['sql_error'] ) ) { $this->last_error = 'Synthetic SQL failure.'; }
		if ( isset( $fault['throw'] ) ) { throw new RuntimeException( 'Synthetic command failure.' ); }
		return array_key_exists( 'result', $fault ) ? $fault['result'] : $result;
	}
	public function get_var( $query ) {
		$this->assert_owned( $this->handle ); [ $sql, $args ] = $this->decode( $query );
		if ( 'SELECT DATABASE()' === $sql ) { return $this->fault( 'database-read', $this->database ); }
		if ( str_contains( $sql, 'option_value' ) ) {
			storage_expect( 'SELECT option_value FROM %i WHERE option_name = %s' === $sql && 'wp_options' === $args[0] );
			return $this->fault( 'ledger-read', $this->ledger[$args[1]]['value'] ?? null );
		}
		storage_expect( 'SELECT session_value FROM %i WHERE session_key = %s' === $sql && 'wp_woocommerce_sessions' === $args[0] );
		return $this->fault( 'session-read', $this->rows[$args[1]]['bytes'] ?? null );
	}
	public function get_row( $query ) {
		$this->assert_owned( $this->handle ); [ $sql, $args ] = $this->decode( $query );
		storage_expect( 'SELECT session_value, session_expiry FROM %i WHERE session_key = %s' === $sql && 'wp_woocommerce_sessions' === $args[0] );
		$row = $this->rows[$args[1]] ?? null;
		return $this->fault( 'snapshot-read', $row ? (object) [ 'session_value' => $row['bytes'], 'session_expiry' => $row['expiry'] ] : null );
	}
	public function get_results( $query ) {
		$this->assert_owned( $this->handle );
		[ $sql, $args ] = $this->decode( $query );
		storage_expect( 'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME IN (%s, %s)' === $sql
			&& [ DB_NAME, 'wp_woocommerce_sessions', 'wp_options' ] === $args );
		return $this->fault( 'engine-read', $this->engines );
	}
	public function query( $query ) {
		$this->assert_owned( $this->handle ); [ $sql, $args ] = $this->decode( $query );
		$label = match ( true ) {
			'START TRANSACTION' === $sql => 'start', 'COMMIT' === $sql => 'commit', 'ROLLBACK' === $sql => 'rollback',
			str_contains( $sql, 'ON DUPLICATE KEY UPDATE' ) => 'guest-persist',
			str_contains( $sql, 'option_name' ) => 'ledger-insert',
			str_starts_with( $sql, 'INSERT' ) => 'destination-insert',
			default => throw new RuntimeException( 'Unqualified synthetic SQL.' ),
		};
		if ( 'destination-insert' === $label ) {
			storage_expect( 'INSERT INTO %i (`session_key`, `session_value`, `session_expiry`) VALUES (%s, %s, %d)' === $sql
				&& 'wp_woocommerce_sessions' === $args[0] && is_string( $args[1] ) && is_string( $args[2] ) && is_int( $args[3] ) );
		}
		if ( 'ledger-insert' === $label ) {
			storage_expect( 'INSERT INTO %i (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, %s)' === $sql && 'wp_options' === $args[0] );
		}
		$this->commands[] = $label;
		if ( $this->on_command ) { ( $this->on_command )( $label, $this ); }
		$fault = $this->faults[$label] ?? [];
		$result = in_array( $label, [ 'start', 'commit', 'rollback' ], true ) ? 0 : 1;
		$apply = [] === $fault || isset( $fault['effect_before_fault'] );
		if ( $apply ) {
			switch ( $label ) {
				case 'guest-persist': $this->rows[$args[1]] = [ 'bytes' => $args[2], 'expiry' => $args[3] ]; break;
				case 'start': $this->pending = [ 'rows' => $this->rows, 'ledger' => $this->ledger ]; break;
				case 'destination-insert':
					if ( isset( $this->pending['rows'][$args[1]] ) ) { $result = false; break; }
					$this->pending['rows'][$args[1]] = [ 'bytes' => $args[2], 'expiry' => $args[3] ]; break;
				case 'ledger-insert':
					if ( isset( $this->pending['ledger'][$args[1]] ) ) { $result = false; break; }
					$this->pending['ledger'][$args[1]] = [ 'value' => $args[2], 'autoload' => $args[3] ]; break;
				case 'commit': $this->rows = $this->pending['rows']; $this->ledger = $this->pending['ledger']; $this->pending = null; break;
				case 'rollback': $this->pending = null; break;
			}
		}
		return $this->fault( $label, $result );
	}
}

function transfer_fixture( $guest = null ) {
	storage_fixture();
	$db = new Transfer_Contract_Database(); $GLOBALS['wpdb'] = $db;
	$guest = $guest ?? str_repeat( 'a', 32 );
	$storage = new \WPGraphQL\WooCommerce\Utils\Cart_Session_Storage( $guest, 'wp_woocommerce_sessions' );
	$storage->acquire();
	return [ $storage, $db, $guest ];
}
function transfer_frozen( $guest = null ) {
	[ $storage, $db, $guest ] = transfer_fixture( $guest );
	$uuid = $storage->freeze_for_checkout_transfer( [ 'cart' => [ 'quantity' => 3 ] ], 123456789 );
	return [ $storage, $db, $guest, $uuid ];
}
function transfer_hash( $identity ) {
	$input = '';
	foreach ( [ DB_NAME, 'wp_woocommerce_sessions', $identity ] as $part ) { $input .= strlen( $part ) . ':' . $part; }
	return hash( 'sha256', $input );
}
function transfer_marker( $guest, $uuid ) {
	return json_encode( [ 'schema' => 1, 'kind' => 'checkout_guest_retirement',
		'source_tuple_sha256' => transfer_hash( $guest ), 'destination_tuple_sha256' => transfer_hash( '17' ),
		'operation_uuid' => $uuid ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
}

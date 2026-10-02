<?php
/**
 * Authoritative storage for one independently admitted cart identity.
 *
 * Scope/transaction management belongs to the early database driver. This class
 * never activates that driver or publishes session caches. Dormant checkout
 * transfer primitives require future server-only caller authority; they are not
 * wired to credential admission, checkout, authentication or account creation.
 *
 * @package WPGraphQL\WooCommerce\Utils
 */

namespace WPGraphQL\WooCommerce\Utils;

use WLCommerce\Database\Owned_Scope_Driver;

final class Cart_Session_Storage {

	private $driver;
	private $handle;
	private $customer_id;
	private $table;
	private $user_id;
	private $state = 'new';
	private $failed = false;
	private $frozen_snapshot;
	private $transfer_record;
	private $transfer_source_id;
	private $options_table;

	/** The caller must already have verified the credential and account binding. */
	public function __construct( $customer_id, $session_table, $bound_user_id = 0 ) {
		$this->driver = $GLOBALS['wpdb'] ?? null;
		if ( ! interface_exists( Owned_Scope_Driver::class )
			|| ! $this->driver instanceof Owned_Scope_Driver
			|| 1 !== Owned_Scope_Driver::CAPABILITY_VERSION
			|| 1 !== $this->driver::CAPABILITY_VERSION
			|| ! is_string( $customer_id ) || ! is_int( $bound_user_id ) || $bound_user_id < 0
			|| ! is_string( $session_table ) || ! $this->valid_identifier( $session_table )
			|| ! isset( $this->driver->prefix ) || $session_table !== $this->driver->prefix . 'woocommerce_sessions'
			|| ! defined( 'DB_NAME' ) || ! is_string( DB_NAME ) || '' === DB_NAME
			|| ( $bound_user_id > 0 ? (string) $bound_user_id !== $customer_id
				: ! preg_match( '/\A(?:[a-f0-9]{32}|t_[a-f0-9]{30})\z/D', $customer_id ) ) ) {
			$this->fail();
		}
		$this->customer_id = $customer_id;
		$this->table       = $session_table;
		$this->user_id     = $bound_user_id;
	}

	private function valid_identifier( $identifier ) {
		return strlen( $identifier ) <= 64 && 1 === preg_match( '/\A[a-zA-Z0-9_]+\z/D', $identifier );
	}

	private function fail() {
		$this->failed = true;
		throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE );
	}

	private function assert_driver() {
		if ( ( $GLOBALS['wpdb'] ?? null ) !== $this->driver
			|| $this->table !== $this->driver->prefix . 'woocommerce_sessions'
			|| ( null !== $this->options_table && ( $this->options_table !== ( $this->driver->options ?? null )
				|| $this->options_table !== $this->driver->prefix . 'options' ) ) ) {
			$this->fail();
		}
	}

	/** Acquire BEFORE reading any persisted session or account data. */
	public function acquire( $timeout_seconds = 0 ) {
		if ( $this->failed || 'new' !== $this->state || ! is_int( $timeout_seconds )
			|| $timeout_seconds < 0 || $timeout_seconds > 5 ) {
			$this->fail();
		}
		try {
			$this->assert_driver();
			// Length-prefixing prevents ambiguous DB/table/identity tuple collisions.
			$parts = [ DB_NAME, $this->table, $this->customer_id ];
			$input = '';
			foreach ( $parts as $part ) {
				$input .= strlen( $part ) . ':' . $part;
			}
			$this->handle = $this->driver->begin_owned_scope( [ hash( 'sha256', $input ) ], $timeout_seconds );
			$this->state  = 'active';
			$this->assert_owned();
			$this->evict_session_cache();
		} catch ( \Throwable $error ) {
			$this->fail();
		}
	}

	/** Check the same driver, opaque handle and underlying ownership every time. */
	public function assert_owned() {
		if ( $this->failed || ! is_object( $this->handle )
			|| ! in_array( $this->state, [ 'active', 'sealed' ], true ) ) {
			$this->fail();
		}
		try {
			$this->assert_driver();
			$this->driver->assert_owned( $this->handle );
			$failure = $this->driver->get_failure_state( $this->handle );
			if ( ! isset( $failure['state'], $failure['failed'] ) || false !== $failure['failed']
				|| $this->state !== $failure['state'] ) {
				$this->fail();
			}
			$this->assert_driver();
		} catch ( \Throwable $error ) {
			$this->fail();
		}
	}

	private function assert_active() {
		$this->assert_owned();
		if ( 'active' !== $this->state ) {
			$this->fail();
		}
	}

	private function assert_data_access() {
		if ( null !== $this->transfer_source_id && 'committed' !== $this->transfer_record->phase ) {
			$this->fail();
		}
	}

	/** Cache deletion may return false for an absent key; the found flag decides. */
	private function evict_key( $key, $group ) {
		$this->assert_owned();
		wp_cache_delete( $key, $group );
		$this->assert_owned();
		$found = null;
		wp_cache_get( $key, $group, true, $found );
		$this->assert_owned();
		if ( false !== $found ) {
			$this->fail();
		}
	}

	private function evict_session_cache() {
		$this->assert_owned();
		if ( ! defined( 'WC_SESSION_CACHE_GROUP' ) || ! class_exists( '\WC_Cache_Helper' ) ) {
			$this->fail();
		}
		$key = \WC_Cache_Helper::get_cache_prefix( WC_SESSION_CACHE_GROUP ) . $this->customer_id;
		$this->assert_owned();
		$this->evict_key( $key, WC_SESSION_CACHE_GROUP );
	}

	/** Never consult or populate the WooCommerce session cache for a read. */
	public function read( $default_value = false ) {
		$this->assert_data_access();
		$this->assert_active();
		try {
			$sql = $this->driver->prepare( 'SELECT session_value FROM %i WHERE session_key = %s', $this->table, $this->customer_id );
			$this->assert_owned();
			if ( ! is_string( $sql ) || '' === $sql ) {
				$this->fail();
			}
			$value = $this->driver->get_var( $sql );
			$this->assert_owned();
			if ( ! empty( $this->driver->last_error ) || false === $value ) {
				$this->fail();
			}
			if ( null === $value ) {
				return $default_value;
			}
			if ( ! is_string( $value ) ) {
				$this->fail();
			}
			$data = @unserialize( $value, [ 'allowed_classes' => false ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
			if ( ! is_array( $data ) ) {
				$this->fail();
			}
			return $data;
		} catch ( \Throwable $error ) {
			$this->fail();
		}
	}

	private function checked_write( $query, array $arguments ) {
		$this->assert_data_access();
		$this->assert_active();
		try {
			$sql = $this->driver->prepare( $query, ...$arguments );
			$this->assert_owned();
			if ( ! is_string( $sql ) || '' === $sql ) {
				$this->fail();
			}
			$result = $this->driver->query( $sql );
			$this->assert_owned();
			if ( ! is_int( $result ) || $result < 0 || ! empty( $this->driver->last_error ) ) {
				$this->fail();
			}
			$this->evict_session_cache();
			return $result;
		} catch ( \Throwable $error ) {
			$this->fail();
		}
	}

	/** Returns affected rows only after persistence AND targeted eviction succeed. */
	public function write( array $data, $expiry ) {
		$this->assert_data_access();
		$this->assert_active();
		if ( ! is_int( $expiry ) || $expiry <= 0 ) {
			$this->fail();
		}
		try {
			$serialized = serialize( $data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
			return $this->checked_write(
				'INSERT INTO %i (`session_key`, `session_value`, `session_expiry`) VALUES (%s, %s, %d) ON DUPLICATE KEY UPDATE `session_value` = VALUES(`session_value`), `session_expiry` = VALUES(`session_expiry`)',
				[ $this->table, $this->customer_id, $serialized, $expiry ]
			);
		} catch ( \Throwable $error ) {
			$this->fail();
		}
	}

	public function delete() {
		return $this->checked_write( 'DELETE FROM %i WHERE session_key = %s', [ $this->table, $this->customer_id ] );
	}

	public function update_timestamp( $timestamp ) {
		$this->assert_data_access();
		$this->assert_active();
		if ( ! is_int( $timestamp ) || $timestamp <= 0 ) {
			$this->fail();
		}
		return $this->checked_write( 'UPDATE %i SET session_expiry = %d WHERE session_key = %s', [ $this->table, $timestamp, $this->customer_id ] );
	}

	/**
	 * Call before NEW WC_Customer hydration. Qualified core caches: users, user_meta,
	 * userlogins, useremail, userslugs. WC_Customer has an empty WC_Data cache_group
	 * in the qualified source; there is no additional WC customer object cache.
	 * Existing customer objects and request-local GraphQL loaders must be discarded
	 * by the lifecycle owner. No group prefix or users-last-changed is invalidated.
	 */
	public function invalidate_account_caches() {
		$this->assert_data_access();
		$this->assert_active();
		if ( 0 === $this->user_id ) {
			return;
		}
		try {
			$users_table = $this->driver->users ?? null;
			if ( ! is_string( $users_table ) || ! $this->valid_identifier( $users_table ) ) {
				$this->fail();
			}
			// Capture only old alias names, never hydrate an account from this value.
			$old = wp_cache_get( $this->user_id, 'users' );
			$this->assert_owned();
			$sql = $this->driver->prepare( 'SELECT ID, user_login, user_email, user_nicename FROM %i WHERE ID = %d', $users_table, $this->user_id );
			$this->assert_owned();
			if ( ! is_string( $sql ) || '' === $sql ) {
				$this->fail();
			}
			$fresh = $this->driver->get_row( $sql );
			$this->assert_owned();
			if ( ! empty( $this->driver->last_error ) || ! is_object( $fresh )
				|| ! isset( $fresh->ID ) || (string) $this->user_id !== (string) $fresh->ID ) {
				$this->fail();
			}
			foreach ( [ 'user_login' => 'userlogins', 'user_email' => 'useremail', 'user_nicename' => 'userslugs' ] as $property => $group ) {
				if ( ! isset( $fresh->$property ) || ! is_string( $fresh->$property ) ) {
					$this->fail();
				}
				if ( '' !== $fresh->$property ) {
					$this->evict_key( $fresh->$property, $group );
				}
				if ( is_object( $old ) && isset( $old->ID, $old->$property )
					&& (string) $old->ID === (string) $this->user_id && is_string( $old->$property )
					&& '' !== $old->$property && $old->$property !== $fresh->$property ) {
					$alias_id = wp_cache_get( $old->$property, $group );
					$this->assert_owned();
					if ( (string) $alias_id === (string) $this->user_id ) {
						$this->evict_key( $old->$property, $group );
					}
				}
			}
			$this->evict_key( $this->user_id, 'users' );
			$this->evict_key( $this->user_id, 'user_meta' );
		} catch ( \Throwable $error ) {
			$this->fail();
		}
	}

	public function seal() {
		if ( null !== $this->transfer_source_id && 'pending' === $this->transfer_record->phase ) {
			$this->fail();
		}
		$this->assert_active();
		try {
			$this->driver->seal_owned_scope( $this->handle );
			$this->state = 'sealed';
			$this->assert_owned();
		} catch ( \Throwable $error ) {
			$this->fail();
		}
	}

	public function release() {
		$this->assert_owned();
		if ( 'sealed' !== $this->state ) {
			$this->fail();
		}
		try {
			$this->driver->release_owned_scope( $this->handle );
			$this->assert_driver();
			$this->state = 'released';
		} catch ( \Throwable $error ) {
			$this->fail();
		}
	}

	/** Pure delivery check: no probes, SQL or reacquisition after a clean release. */
	public function assert_response_available() {
		if ( $this->failed || ! is_object( $this->handle )
			|| ! in_array( $this->state, [ 'active', 'sealed', 'released' ], true ) ) {
			$this->fail();
		}
		try {
			$this->assert_driver();
			$failure = $this->driver->get_failure_state( $this->handle );
			$this->assert_driver();
			if ( ! isset( $failure['state'], $failure['failed'] ) || false !== $failure['failed']
				|| $this->state !== $failure['state'] ) {
				$this->fail();
			}
		} catch ( \Throwable $error ) {
			$this->fail();
		}
	}

	/** Failure cleanup uses only the captured opaque handle, never another scope. */
	public function abort() {
		if ( in_array( $this->state, [ 'released', 'aborted', 'retired' ], true ) ) {
			return;
		}
		try {
			if ( ! is_object( $this->handle ) ) {
				$this->fail();
			}
			// Cleanup authority is the captured driver + opaque handle, even if
			// another global wpdb displaced it. Never call the replacement driver
			// or demand active ownership to clean up the original failed scope.
			$this->driver->abort_owned_scope( $this->handle );
			$this->failed = true;
			$this->state  = 'aborted';
		} catch ( \Throwable $error ) {
			$this->fail();
		}
	}

	/** Trusted-caller storage primitive, dormant until T2 supplies one-use authority. */
	private function tuple_hash( $identity ) {
		$input = '';
		foreach ( [ DB_NAME, $this->table, $identity ] as $part ) {
			$input .= strlen( $part ) . ':' . $part;
		}
		return hash( 'sha256', $input );
	}

	private function pin_options_table( $table ) {
		$this->assert_active();
		if ( ! is_string( $table ) || ! $this->valid_identifier( $table )
			|| $table !== ( $this->driver->options ?? null ) || $table !== $this->driver->prefix . 'options' ) {
			$this->fail();
		}
		$this->options_table = $table;
		$this->assert_driver();
	}

	private function transfer_read( $format, array $arguments, $method ) {
		$this->assert_active();
		$sql = $arguments ? $this->driver->prepare( $format, ...$arguments ) : $format;
		$this->assert_owned();
		if ( ! is_string( $sql ) || '' === $sql ) {
			$this->fail();
		}
		$result = $this->driver->$method( $sql );
		$this->assert_owned();
		if ( false === $result || ! empty( $this->driver->last_error ) ) {
			$this->fail();
		}
		return $result;
	}

	private function raw_session_snapshot( $identity ) {
		$row = $this->transfer_read( 'SELECT session_value, session_expiry FROM %i WHERE session_key = %s',
			[ $this->table, $identity ], 'get_row' );
		if ( null === $row ) {
			return null;
		}
		if ( ! is_object( $row ) || ! isset( $row->session_value, $row->session_expiry ) || ! is_string( $row->session_value )
			|| ! ( is_int( $row->session_expiry ) || is_string( $row->session_expiry ) )
			|| ! preg_match( '/\A[1-9][0-9]*\z/D', (string) $row->session_expiry )
			|| (string) (int) $row->session_expiry !== (string) $row->session_expiry ) {
			$this->fail();
		}
		return [ 'bytes' => $row->session_value, 'expiry' => (int) $row->session_expiry ];
	}

	/** Direct ledger read under the admitted guest lock; does not change ordinary read(). */
	public function read_retirement_marker( $options_table ) {
		try {
			$this->assert_active();
			if ( 0 !== $this->user_id ) {
				$this->fail();
			}
			$this->pin_options_table( $options_table );
			$source = $this->tuple_hash( $this->customer_id );
			$value = $this->transfer_read( 'SELECT option_value FROM %i WHERE option_name = %s',
				[ $this->options_table, 'wl_cart_retired_v1_' . $source ], 'get_var' );
			if ( null === $value ) {
				return false;
			}
			if ( ! is_string( $value ) ) {
				$this->fail();
			}
			$marker = json_decode( $value, true, 8, JSON_THROW_ON_ERROR );
			if ( ! is_array( $marker ) || array_keys( $marker ) !== [ 'schema', 'kind', 'source_tuple_sha256', 'destination_tuple_sha256', 'operation_uuid' ]
				|| 1 !== $marker['schema'] || 'checkout_guest_retirement' !== $marker['kind']
				|| $source !== $marker['source_tuple_sha256'] || ! is_string( $marker['destination_tuple_sha256'] )
				|| ! preg_match( '/\A[a-f0-9]{64}\z/D', $marker['destination_tuple_sha256'] )
				|| ! is_string( $marker['operation_uuid'] ) || ! preg_match( '/\A[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/D', $marker['operation_uuid'] )
				|| json_encode( $marker, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) !== $value ) {
				$this->fail();
			}
			return true; // Presence denies future guest admission; never authenticates anyone.
		} catch ( \Throwable $error ) {
			$this->transfer_failure();
		}
	}

	/** Persist/fingerprint, then freeze. Caller must separately close captured writers. */
	public function freeze_for_checkout_transfer( array $data, $expiry ) {
		try {
			$this->assert_active();
			if ( 0 !== $this->user_id || null !== $this->transfer_record ) {
				$this->fail();
			}
			$this->write( $data, $expiry );
			$snapshot = $this->raw_session_snapshot( $this->customer_id );
			if ( null === $snapshot || $snapshot['bytes'] !== serialize( $data ) || $snapshot['expiry'] !== $expiry ) {
				$this->fail();
			}
			$bytes = random_bytes( 16 );
			$bytes[6] = chr( ( ord( $bytes[6] ) & 15 ) | 64 );
			$bytes[8] = chr( ( ord( $bytes[8] ) & 63 ) | 128 );
			$hex = bin2hex( $bytes );
			$uuid = substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20 );
			$this->frozen_snapshot = $snapshot;
			$this->transfer_record = (object) [ 'uuid' => $uuid, 'phase' => 'frozen', 'attempted' => false,
				'commit_attempted' => false, 'commit_acknowledged' => false, 'rollback_acknowledged' => false ];
			$this->state = 'frozen';
			return $uuid;
		} catch ( \Throwable $error ) {
			$this->transfer_failure();
		}
	}

	private function assert_frozen_handle( $expected ) {
		if ( $this->failed || 'frozen' !== $this->state || ! is_object( $this->handle ) ) {
			$this->fail();
		}
		$this->assert_driver();
		$this->driver->assert_owned( $this->handle );
		$status = $this->driver->get_failure_state( $this->handle );
		$this->assert_driver();
		if ( [ 'state' => $expected, 'failed' => false ] !== $status ) {
			$this->fail();
		}
	}

	/**
	 * Dormant staged transaction; pending facade permits scope checks/abort only.
	 * The caller supplies an independently authorized fresh account. This storage
	 * primitive does not establish that authorization or enable checkout creation.
	 */
	public function stage_checkout_transfer( $new_user_id, $options_table, $timeout_seconds = 5 ) {
		$destination = null;
		try {
			$this->assert_frozen_handle( 'active' );
			if ( ! is_int( $new_user_id ) || $new_user_id <= 0 || ! is_int( $timeout_seconds ) || $timeout_seconds < 0 || $timeout_seconds > 5
				|| $this->transfer_record->attempted || ! is_string( $options_table ) || ! $this->valid_identifier( $options_table )
				|| $options_table !== ( $this->driver->options ?? null ) || $options_table !== $this->driver->prefix . 'options' ) {
				$this->fail();
			}
			$this->transfer_record->attempted = true; // No repeat handoff, even after a refusal/failure.
			$this->options_table = $options_table;
			$this->driver->seal_owned_scope( $this->handle );
			$this->assert_frozen_handle( 'sealed' );
			$this->driver->release_owned_scope( $this->handle );
			$this->assert_driver();
			$status = $this->driver->get_failure_state( $this->handle );
			if ( [ 'state' => 'released', 'failed' => false ] !== $status ) {
				$this->fail();
			}
			$this->state = 'retired'; // Old handle cleanup must never abort the later dual grant.
			$destination = new self( (string) $new_user_id, $this->table, $new_user_id );
			$destination->driver = $this->driver;
			$destination->options_table = $options_table;
			$destination->transfer_source_id = $this->customer_id;
			$destination->transfer_record = $this->transfer_record;
			$locks = [ $this->tuple_hash( $this->customer_id ), $this->tuple_hash( (string) $new_user_id ) ];
			sort( $locks, SORT_STRING );
			$destination->handle = $this->driver->begin_owned_scope( $locks, $timeout_seconds );
			$destination->state = 'active';
			$destination->assert_owned();
			$destination->qualify_transfer_tables();
			if ( $destination->raw_session_snapshot( $this->customer_id ) !== $this->frozen_snapshot
				|| null !== $destination->raw_session_snapshot( (string) $new_user_id )
				|| null !== $destination->transfer_read( 'SELECT option_value FROM %i WHERE option_name = %s',
					[ $options_table, 'wl_cart_retired_v1_' . $this->tuple_hash( $this->customer_id ) ], 'get_var' ) ) {
				$this->fail();
			}
			$destination->transfer_command( 'START TRANSACTION', [], 0 );
			$destination->transfer_record->phase = 'pending';
			$destination->transfer_command( 'INSERT INTO %i (`session_key`, `session_value`, `session_expiry`) VALUES (%s, %s, %d)',
				[ $this->table, (string) $new_user_id, $this->frozen_snapshot['bytes'], $this->frozen_snapshot['expiry'] ], 1 );
			$marker = [ 'schema' => 1, 'kind' => 'checkout_guest_retirement', 'source_tuple_sha256' => $this->tuple_hash( $this->customer_id ),
				'destination_tuple_sha256' => $this->tuple_hash( (string) $new_user_id ), 'operation_uuid' => $this->transfer_record->uuid ];
			$destination->transfer_command( 'INSERT INTO %i (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, %s)',
				[ $options_table, 'wl_cart_retired_v1_' . $marker['source_tuple_sha256'], json_encode( $marker, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ), 'no' ], 1 );
			return $destination;
		} catch ( \Throwable $error ) {
			if ( $destination ) {
				$destination->transfer_failure();
			}
			if ( 'retired' !== $this->state ) {
				try {
					$this->abort();
				} catch ( \Throwable $ignored ) {
					// Captured cleanup is best effort; do not replace the fixed error.
				}
			}
			$this->fail();
		}
	}

	private function qualify_transfer_tables() {
		if ( DB_NAME !== $this->transfer_read( 'SELECT DATABASE()', [], 'get_var' ) ) {
			$this->fail();
		}
		$rows = $this->transfer_read( 'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME IN (%s, %s)',
			[ DB_NAME, $this->table, $this->options_table ], 'get_results' );
		if ( ! is_array( $rows ) || 2 !== count( $rows ) ) {
			$this->fail();
		}
		$names = [];
		foreach ( $rows as $row ) {
			if ( ! is_object( $row ) || ! isset( $row->TABLE_NAME, $row->ENGINE ) || 'InnoDB' !== $row->ENGINE
				|| ! in_array( $row->TABLE_NAME, [ $this->table, $this->options_table ], true ) || in_array( $row->TABLE_NAME, $names, true ) ) {
				$this->fail();
			}
			$names[] = $row->TABLE_NAME;
		}
	}

	private function transfer_command( $query, array $arguments, $expected ) {
		$this->assert_active();
		$sql = $arguments ? $this->driver->prepare( $query, ...$arguments ) : $query;
		$this->assert_owned();
		if ( ! is_string( $sql ) || '' === $sql ) {
			$this->fail();
		}
		$result = $this->driver->query( $sql );
		$this->assert_owned();
		if ( $expected !== $result || ! empty( $this->driver->last_error ) ) {
			$this->fail();
		}
	}

	private function transfer_failure() {
		$this->failed = true;
		try {
			$this->abort();
		} catch ( \Throwable $ignored ) {
			// Best effort only; never confirmed compensation.
		}
		$this->fail();
	}

	public function commit_checkout_transfer() {
		try {
			$this->assert_active();
			if ( null === $this->transfer_source_id || 'pending' !== $this->transfer_record->phase || $this->transfer_record->commit_attempted ) {
				$this->fail();
			}
			// A lost reply or failed post-query ownership check is an unknown outcome.
			$this->transfer_record->commit_attempted = true;
			$this->transfer_record->phase = 'commit_unknown';
			$this->transfer_command( 'COMMIT', [], 0 );
			$this->transfer_record->commit_acknowledged = true;
			$this->transfer_record->phase = 'committed';
			$this->evict_session_cache();
			$key = \WC_Cache_Helper::get_cache_prefix( WC_SESSION_CACHE_GROUP ) . $this->transfer_source_id;
			$this->assert_owned();
			$this->evict_key( $key, WC_SESSION_CACHE_GROUP );
		} catch ( \Throwable $error ) {
			$this->transfer_failure();
		}
	}

	/** Healthy policy refusal only; query failure must use captured best-effort abort. */
	public function rollback_checkout_transfer() {
		try {
			$this->assert_active();
			if ( null === $this->transfer_source_id || 'pending' !== $this->transfer_record->phase || $this->transfer_record->commit_attempted ) {
				$this->fail();
			}
			$this->transfer_command( 'ROLLBACK', [], 0 );
			$this->transfer_record->rollback_acknowledged = true;
			$this->transfer_record->phase = 'rolled_back';
		} catch ( \Throwable $error ) {
			$this->transfer_failure();
		}
	}

	public function transfer_to_fresh_account( $new_user_id, $options_table, $timeout_seconds = 5 ) {
		$destination = $this->stage_checkout_transfer( $new_user_id, $options_table, $timeout_seconds );
		$destination->commit_checkout_transfer();
		return $destination;
	}

	/** Sanitized storage outcome only; conveys no authentication/transfer authority. */
	public function checkout_transfer_outcome() {
		return [ 'attempted' => (bool) ( $this->transfer_record->attempted ?? false ),
			'commit_attempted' => (bool) ( $this->transfer_record->commit_attempted ?? false ),
			'commit_acknowledged' => (bool) ( $this->transfer_record->commit_acknowledged ?? false ),
			'rollback_acknowledged' => (bool) ( $this->transfer_record->rollback_acknowledged ?? false ) ];
	}

}

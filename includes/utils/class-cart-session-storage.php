<?php
/**
 * Authoritative storage for one independently admitted cart identity.
 *
 * Scope/transaction management belongs to the early database driver. This class
 * never activates that driver, transfers sessions, or publishes session caches.
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
			|| $this->table !== $this->driver->prefix . 'woocommerce_sessions' ) {
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
		if ( in_array( $this->state, [ 'released', 'aborted' ], true ) ) {
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
}

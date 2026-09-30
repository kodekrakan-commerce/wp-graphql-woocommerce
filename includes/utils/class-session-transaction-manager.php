<?php
/**
 * Manages concurrent requests that executes mutations on the session data.
 *
 * @package WPGraphQL\WooCommerce\Utils
 * @since 0.7.1
 */

namespace WPGraphQL\WooCommerce\Utils;

/**
 * Class - Session_Transaction_Manager
 */
class Session_Transaction_Manager {
	/**
	 * The request's transaction ID. Shared across all mutations in the same HTTP request.
	 *
	 * @var null|string
	 */
	public $transaction_id = null;

	/**
	 * Whether the transaction has been queued (added to the transaction queue).
	 *
	 * @var bool
	 */
	private $is_queued = false;

	/** @var bool Whether this request owns the session execution lock. */
	private $is_active = false;

	/** @var bool Admission failed; later fields in the batch must also fail. */
	private $has_failed = false;

	/** @var string|null Session key captured before checkout/authentication can change it. */
	private $customer_id = null;

	/** @var bool Whether the short queue lock is held by this manager. */
	private $queue_locked = false;

	/**
	 * Instance of parent session handler
	 *
	 * @var \WPGraphQL\WooCommerce\Utils\QL_Session_Handler
	 */
	private $session_handler = null;

	/**
	 * Singleton instance of class.
	 *
	 * @var \WPGraphQL\WooCommerce\Utils\Session_Transaction_Manager
	 */
	private static $instance = null;

	/**
	 * Singleton retriever and cleaner.
	 * Should not be called anywhere but in the session handler init function.
	 *
	 * @param \WPGraphQL\WooCommerce\Utils\QL_Session_Handler $session_handler  WooCommerce Session Handler instance.
	 *
	 * @return \WPGraphQL\WooCommerce\Utils\Session_Transaction_Manager
	 */
	public static function get( &$session_handler ) {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self( $session_handler );
		}

		return self::$instance;
	}

	/**
	 * Session_Transaction_Manager constructor
	 *
	 * @param \WPGraphQL\WooCommerce\Utils\QL_Session_Handler $session_handler  Reference back to session handler.
	 */
	public function __construct( &$session_handler ) {
		$this->session_handler = $session_handler;

		add_action( 'graphql_before_resolve_field', [ $this, 'update_transaction_queue' ], 10, 4 );
		add_action( 'graphql_mutation_response', [ $this, 'complete_mutation' ], 20, 6 );

		add_action( 'woographql_session_transaction_complete', [ $this->session_handler, 'save_if_dirty' ], 10 );

		add_action( 'woocommerce_add_to_cart', [ $this->session_handler, 'mark_dirty' ] );
		add_action( 'woocommerce_cart_item_removed', [ $this->session_handler, 'mark_dirty' ] );
		add_action( 'woocommerce_cart_item_restored', [ $this->session_handler, 'mark_dirty' ] );
		add_action( 'woocommerce_cart_item_set_quantity', [ $this->session_handler, 'mark_dirty' ] );
		add_action( 'woocommerce_cart_emptied', [ $this->session_handler, 'mark_dirty' ] );

		// Pop the transaction at the end of the request so all mutations in a batch
		// execute under the same queue entry without interleaving from other requests.
		add_action( 'shutdown', [ $this, 'guard_shutdown_saves' ], -1 );
		register_shutdown_function( [ $this, 'pop_transaction_id' ] );
	}

	/**
	 * Pass all member call upstream to the session handler.
	 *
	 * @param string $name  Name of class member.
	 *
	 * @return mixed
	 */
	public function __get( $name ) {
		return $this->session_handler->{$name};
	}

	/**
	 * Return array of all mutations that alter the session data.
	 * a.k.a. Session Mutations
	 *
	 * @return array
	 */
	public static function get_session_mutations() {
		/**
		 * All session altering mutations should be passed to the array.
		 */
		return \apply_filters(
			'woographql_session_mutations',
			[
				'checkout',
				'addToCart',
				'addCartItems',
				'fillCart',
				'updateItemQuantities',
				'addFee',
				'applyCoupon',
				'removeCoupons',
				'emptyCart',
				'removeItemsFromCart',
				'restoreCartItems',
				'updateItemQuantities',
				'updateShippingMethod',
				'updateCustomer',
				'updateSession',
				'forgetSession',
			]
		);
	}

	/**
	 * Returns the MySQL advisory lock name for the session's transaction queue.
	 *
	 * @return string
	 */
	private function get_lock_name() {
		if ( null === $this->customer_id ) {
			$this->customer_id = (string) $this->session_handler->get_customer_id();
		}
		return 'woo_stq_' . substr( md5( $this->customer_id ), 0, 20 );
	}

	/** @return string Name of the lock held for the whole HTTP request. */
	private function get_execution_lock_name() {
		return $this->get_lock_name() . '_execution';
	}

	/**
	 * Acquire the short queue mutex. Never read/change the queue after failure.
	 *
	 * @param int $timeout Maximum wait in seconds.
	 * @return bool
	 */
	private function acquire_lock( $timeout = 1 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $this->get_lock_name(), $timeout ) );
		$this->queue_locked = '1' === (string) $result;
		return $this->queue_locked;
	}

	/** @return void */
	private function release_lock() {
		global $wpdb;
		if ( $this->queue_locked ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->get_lock_name() ) );
			$this->queue_locked = false;
		}
	}

	/** @return bool Whether execution is exclusively owned on this DB connection. */
	private function acquire_execution_lock() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $this->get_execution_lock_name(), 0 ) );
	}

	/** @return bool Detect connection loss before another mutation or session save. */
	private function owns_execution_lock() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $this->get_execution_lock_name() ) );
	}

	/** @return void */
	private function release_execution_lock() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->get_execution_lock_name() ) );
	}

	/** @return string The captured session's transient key. */
	private function get_queue_key() {
		$this->get_lock_name();
		return "graphql_woocommerce_session_transactions_queue_{$this->customer_id}";
	}

	/**
	 * Read under the queue mutex, bypassing request-local option caches.
	 *
	 * @return array
	 */
	private function read_transaction_queue() {
		$key = $this->get_queue_key();
		if ( wp_using_ext_object_cache() ) {
			// Force refresh: a drop-in may otherwise return this request's stale local value.
			$queue = wp_cache_get( $key, 'transient', true );
		} else {
			$option_keys = [ '_transient_' . $key, '_transient_timeout_' . $key ];
			$notoptions  = wp_cache_get( 'notoptions', 'options' );
			foreach ( $option_keys as $option_key ) {
				wp_cache_delete( $option_key, 'options' );
				if ( is_array( $notoptions ) ) {
					unset( $notoptions[ $option_key ] );
				}
			}
			if ( is_array( $notoptions ) ) {
				wp_cache_set( 'notoptions', $notoptions, 'options' );
			}
			$queue = get_transient( $key );
		}
		return is_array( $queue ) ? array_values( $queue ) : [];
	}

	/**
	 * Timestamp sorting plus random entropy: equal clocks cannot alias requests.
	 *
	 * @return string
	 */
	private static function generate_transaction_id() {
		list( $usec, $sec ) = explode( ' ', microtime() );
		return sprintf( '%010d_%06d_%s', $sec, (int) ( (float) $usec * 1000000 ), bin2hex( random_bytes( 16 ) ) );
	}

	/**
	 * Prevent a rejected request from saving its pre-admission session at shutdown.
	 *
	 * @throws \GraphQL\Error\UserError Always.
	 * @return never
	 */
	private function fail_transaction() {
		$this->disable_session_saves();
		throw new \GraphQL\Error\UserError( __( 'The cart is busy. Please retry your request.', 'graphql-for-ecommerce' ) );
	}

	/** @return void Disable stale persistence when admission/ownership fails. */
	private function disable_session_saves() {
		$this->has_failed = true;
		// QL init registers HTTP saves at 10 and native/non-HTTP saves at 20.
		remove_action( 'shutdown', [ $this->session_handler, 'save_data' ], 10 );
		remove_action( 'shutdown', [ $this->session_handler, 'save_data' ], 20 );
		if ( function_exists( 'WC' ) && \WC()->customer ) {
			remove_action( 'shutdown', [ \WC()->customer, 'save' ], 10 );
		}
		remove_action( 'woographql_session_transaction_complete', [ $this->session_handler, 'save_if_dirty' ], 10 );
	}

	/** @return void Run before WordPress/WooCommerce shutdown persistence callbacks. */
	public function guard_shutdown_saves() {
		if ( $this->has_failed || ( $this->is_queued && ( ! $this->is_active || ! $this->owns_execution_lock() ) ) ) {
			$this->disable_session_saves();
		}
	}

	/**
	 * Transaction queue workhorse.
	 *
	 * Creates a transaction ID if executing mutations that alter the session data, and stalls
	 * execution until the transaction ID is at the top of the queue.
	 *
	 * @param mixed                                $source   Operation root object.
	 * @param array                                $args     Operation arguments.
	 * @param \WPGraphQL\AppContext                $context  AppContext instance.
	 * @param \GraphQL\Type\Definition\ResolveInfo $info     Operation ResolveInfo object.
	 *
	 * @return void
	 */
	public function update_transaction_queue( $source, $args, $context, $info ) {
		if ( ! in_array( $info->fieldName, self::get_session_mutations(), true ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			return;
		}
		if ( $this->has_failed ) {
			$this->fail_transaction();
		}
		// Batched mutations keep both ownership and their current in-memory cart.
		if ( $this->is_active ) {
			if ( ! $this->owns_execution_lock() ) {
				$this->fail_transaction();
			}
			return;
		}
		if ( null === $this->transaction_id ) {
			$this->transaction_id = self::generate_transaction_id();
		}

		$deadline = hrtime( true ) + max( 1, (float) $this->get_timestamp_threshold() ) * 1000000000;
		try {
			while ( ! $this->next_transaction() ) {
				if ( hrtime( true ) >= $deadline ) {
					$this->fail_transaction();
				}
				usleep( 100000 );
			}
		} catch ( \Throwable $error ) {
			$this->fail_transaction();
		}

		// The session/cart were initialized before waiting. Refresh once, after admission.
		try {
			$this->session_handler->reload_data();
			if ( function_exists( 'WC' ) ) {
				// Replace the pre-wait customer too; its shutdown save otherwise restores stale addresses.
				if ( \WC()->customer ) {
					remove_action( 'shutdown', [ \WC()->customer, 'save' ], 10 );
					\WC()->customer = new \WC_Customer( get_current_user_id(), true );
					add_action( 'shutdown', [ \WC()->customer, 'save' ], 10 );
				}
				if ( \WC()->cart ) {
					\WC()->cart->set_cart_contents( [] );
					\WC()->cart->get_cart_from_session();
				}
			}
		} catch ( \Throwable $error ) {
			$this->fail_transaction();
		}
	}

	/**
	 * Admit a head atomically. Stale time alone never authorizes evicting a live owner.
	 *
	 * @return bool
	 */
	public function next_transaction() {
		if ( $this->is_active ) {
			return true;
		}
		if ( ! $this->acquire_lock() ) {
			$this->fail_transaction();
		}
		try {
			$queue = $this->get_transaction_queue();
			$head  = $queue[0] ?? [];
			if ( $this->transaction_id === ( $head['transaction_id'] ?? null ) ) {
				if ( ! $this->acquire_execution_lock() ) {
					return false;
				}
				$this->is_active       = true;
				$queue[0]['active']    = true;
				$queue[0]['timestamp'] = time();
				$this->save_transaction_queue( $queue );
				return true;
			}

			// The head is read and checked *inside* the same queue mutex as its removal.
			// GET_LOCK(0) proves there is no live cooperating owner before recovery.
			if ( $this->did_transaction_expire( $queue ) && $this->acquire_execution_lock() ) {
				try {
					array_shift( $queue );
					$this->save_transaction_queue( $queue );
				} finally {
					$this->release_execution_lock();
				}
			}
			return false;
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * Add this request under the queue mutex. An active head cannot be displaced.
	 *
	 * @return array
	 */
	public function get_transaction_queue() {
		$already_locked = $this->queue_locked;
		if ( ! $already_locked && ! $this->acquire_lock() ) {
			$this->fail_transaction();
		}
		try {
			if ( null === $this->transaction_id ) {
				$this->transaction_id = self::generate_transaction_id();
			}
			$queue = $this->read_transaction_queue();
			if ( ! in_array( $this->transaction_id, array_column( $queue, 'transaction_id' ), true ) ) {
				$entry = [ 'transaction_id' => $this->transaction_id, 'timestamp' => time() ];
				$start = ! empty( $queue[0]['active'] ) ? 1 : 0;
				$index = $start;
				while ( isset( $queue[ $index ] ) && strcmp( $this->transaction_id, $queue[ $index ]['transaction_id'] ?? '' ) >= 0 ) {
					++$index;
				}
				array_splice( $queue, $index, 0, [ $entry ] );
				$this->save_transaction_queue( $queue );
			}
			$this->is_queued = true;
			return $queue;
		} finally {
			if ( ! $already_locked ) {
				$this->release_lock();
			}
		}
	}

	/**
	 * Called after each mutation completes. Saves session data but does NOT pop
	 * the transaction from the queue. The queue entry stays at position [0] to
	 * block other requests until the entire HTTP request completes.
	 *
	 * @param array                                $payload          The Payload returned from the mutation.
	 * @param array                                $input            The mutation input args, after being filtered by 'graphql_mutation_input'.
	 * @param array                                $unfiltered_input The unfiltered input args of the mutation
	 * @param \WPGraphQL\AppContext                $context          The AppContext object.
	 * @param \GraphQL\Type\Definition\ResolveInfo $info             The ResolveInfo object.
	 * @param string                               $mutation         The name of the mutation field.
	 *
	 * @return void
	 */
	public function complete_mutation( $payload, $input, $unfiltered_input, $context, $info, $mutation ) {
		// Bail if transaction not started.
		if ( is_null( $this->transaction_id ) || ! $this->is_active || $this->has_failed ) {
			return;
		}

		// Bail if not a session mutation.
		if ( ! in_array( $mutation, self::get_session_mutations(), true ) ) {
			return;
		}

		if ( ! $this->owns_execution_lock() ) {
			$this->fail_transaction();
		}

		/**
		 * Mark mutation completion and save session data.
		 *
		 * @param string|null $transition_id     Current transaction ID.
		 * @param array       $transaction_queue Transaction Queue (not re-read here for performance).
		 */
		do_action( 'woographql_session_transaction_complete', $this->transaction_id, [] );
	}

	/**
	 * Pop transaction ID off the top of the queue, ending the transaction.
	 *
	 * Called via register_shutdown_function at the end of the HTTP request, ensuring
	 * all mutations in a batch complete before the queue position is released to
	 * other requests.
	 *
	 * @return void
	 */
	public function pop_transaction_id() {
		if ( null === $this->transaction_id || ! $this->is_queued ) {
			return;
		}
		try {
			$this->guard_shutdown_saves();
			// WP shutdown saves normally run first. Also cover explicit cleanup/error paths.
			if ( $this->is_active && ! $this->has_failed ) {
				$this->session_handler->save_if_dirty();
			}
			if ( ! $this->acquire_lock() ) {
				return; // Keep the orphan entry for guarded recovery; never mutate unlocked.
			}
			try {
				$queue = $this->read_transaction_queue();
				foreach ( $queue as $index => $entry ) {
					if ( $this->transaction_id === ( $entry['transaction_id'] ?? null ) ) {
						array_splice( $queue, $index, 1 );
						$this->save_transaction_queue( $queue );
						break;
					}
				}
			} finally {
				$this->release_lock();
			}
		} finally {
			if ( $this->is_active ) {
				$this->release_execution_lock();
			}
			$this->transaction_id = null;
			$this->is_queued      = false;
			$this->is_active      = false;
		}
	}

	/**
	 * Persist only under a successfully acquired queue mutex.
	 *
	 * @param array $queue Transaction queue.
	 * @return void
	 */
	public function save_transaction_queue( $queue = [] ) {
		$already_locked = $this->queue_locked;
		if ( ! $already_locked && ! $this->acquire_lock() ) {
			$this->fail_transaction();
		}
		try {
			if ( empty( $queue ) ) {
				delete_transient( $this->get_queue_key() );
				if ( $this->read_transaction_queue() ) {
					$this->fail_transaction();
				}
			} elseif ( ! set_transient( $this->get_queue_key(), $queue, 5 * MINUTE_IN_SECONDS ) && $queue !== $this->read_transaction_queue() ) {
				$this->fail_transaction();
			}
		} finally {
			if ( ! $already_locked ) {
				$this->release_lock();
			}
		}
	}

	/** @return void Refresh only this request's owned head. */
	public function set_timestamp() {
		if ( ! $this->is_active || ! $this->acquire_lock() ) {
			$this->fail_transaction();
		}
		try {
			$queue = $this->read_transaction_queue();
			if ( $this->transaction_id !== ( $queue[0]['transaction_id'] ?? null ) ) {
				$this->fail_transaction();
			}
			$queue[0]['timestamp'] = time();
			$this->save_transaction_queue( $queue );
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * The length of time in seconds a transaction should stay in the queue
	 *
	 * @return mixed|void
	 */
	public function get_timestamp_threshold() {
		return apply_filters( 'woographql_session_transaction_timeout', 30 );
	}

	/**
	 * Whether the transaction has expired. This helps prevent infinite loops while searching through the transaction
	 * queue.
	 *
	 * @param array $transaction_queue  Transaction queue.
	 *
	 * @return bool
	 */
	public function did_transaction_expire( $transaction_queue ) {
		// Guard against empty transaction queue. We assume that it is invalid since we cannot calculate.
		if ( empty( $transaction_queue ) ) {
			return true;
		}

		// Guard against empty timestamp. We assume that it is invalid since we cannot calculate.
		if ( empty( $transaction_queue[0] ) || empty( $transaction_queue[0]['timestamp'] ) ) {
			return true;
		}

		$now        = time();
		$stamp      = $transaction_queue[0]['timestamp'];
		$threshold  = $this->get_timestamp_threshold();
		$difference = $now - $stamp;

		return $difference > $threshold;
	}
}

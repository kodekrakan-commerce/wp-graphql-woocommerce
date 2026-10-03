<?php
/**
 * Offline contract regressions against the owning repository's actual manager.
 * Run directly with PHP; intentionally exits nonzero while regressions remain.
 */

namespace GraphQL\Error {

	class UserError extends \RuntimeException {}
}

namespace WPGraphQL\WooCommerce\Utils {

	/** Replace only the wait boundary, never the manager or its clock/queue logic. */
	function usleep( $microseconds ) {
		\SessionContractFramework::$sleep_requests[] = $microseconds;
		if ( null === \SessionContractFramework::$scheduler ) {
			throw new \RuntimeException( 'Unexpected wait without a bounded scheduler.' );
		}
		( \SessionContractFramework::$scheduler )( $microseconds );
	}
}

namespace {

	use WPGraphQL\WooCommerce\Utils\Session_Transaction_Manager;

	define( 'MINUTE_IN_SECONDS', 60 );

	final class HeldHeadDeadline extends RuntimeException {}

	final class SessionContractFramework {
		public static array $transients = [];
		public static array $actions = [];
		public static array $events = [];
		public static array $sleep_requests = [];
		public static $scheduler = null;
		public static array $observations = [];

		public static function reset(): void {
			self::$transients = self::$actions = self::$events = self::$sleep_requests = self::$observations = [];
			self::$scheduler = null;
		}
	}

	function get_transient( $key ) {
		return SessionContractFramework::$transients[ $key ] ?? false;
	}

	function set_transient( $key, $value, $expiration = 0 ) {
		SessionContractFramework::$transients[ $key ] = $value;
		return true;
	}

	function delete_transient( $key ) {
		$existed = array_key_exists( $key, SessionContractFramework::$transients );
		unset( SessionContractFramework::$transients[ $key ] );
		return $existed;
	}

	function apply_filters( $hook, $value, ...$arguments ) {
		return $value;
	}

	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		SessionContractFramework::$actions[ $hook ][ $priority ][] = [ $callback, $accepted_args ];
		return true;
	}

	function do_action( $hook, ...$arguments ) {
		SessionContractFramework::$events[] = [ $hook, $arguments ];
		$priorities = SessionContractFramework::$actions[ $hook ] ?? [];
		ksort( $priorities );
		foreach ( $priorities as $callbacks ) {
			foreach ( $callbacks as [ $callback, $accepted_args ] ) {
				$callback( ...array_slice( $arguments, 0, $accepted_args ) );
			}
		}
	}

	function __( $message, $domain = null ) {
		return $message;
	}

	/** Separate synthetic request handlers share only the framework transient store. */
	final class SessionContractHandler {
		public int $reloads = 0;
		public int $resolver_calls = 0;
		public int $save_calls = 0;
		public int $dirty_saves = 0;
		public bool $dirty = false;

		public function get_customer_id(): string {
			return 'synthetic-shared-customer';
		}

		public function get_session_data(): array {
			return [ 'synthetic' => 'snapshot' ];
		}

		public function reload_data(): void {
			++$this->reloads;
		}

		public function mark_dirty(): void {
			$this->dirty = true;
		}

		public function save_if_dirty(): void {
			++$this->save_calls;
			if ( $this->dirty ) {
				++$this->dirty_saves;
				$this->dirty = false;
			}
		}
	}

	require dirname( __DIR__, 2 ) . '/includes/utils/class-session-transaction-manager.php';

	function require_contract( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new RuntimeException( $message );
		}
	}

	function queue_key(): string {
		return 'woo_session_transactions_queue_synthetic-shared-customer';
	}

	function resolve_field( string $mutation ): void {
		do_action( 'graphql_before_resolve_field', null, [], new stdClass(), (object) [ 'fieldName' => $mutation ] );
	}

	function mutation_response( string $mutation ): void {
		do_action( 'graphql_mutation_response', [], [], [], new stdClass(), (object) [ 'fieldName' => $mutation ], $mutation );
	}

	function completion_events(): array {
		return array_values( array_filter( SessionContractFramework::$events, static fn( $event ) => 'woographql_session_transaction_complete' === $event[0] ) );
	}

	/** Start A through the constructor-registered real GraphQL field hook. */
	function held_head(): array {
		$handler = new SessionContractHandler();
		$manager = new Session_Transaction_Manager( $handler );
		resolve_field( 'addToCart' );
		$queue = get_transient( queue_key() );
		require_contract( 1 === $handler->reloads, 'Fixture A must acquire and reload through the original manager.' );
		require_contract( $manager->transaction_id === $queue[0]['transaction_id'], 'Fixture A must own the actual queue head.' );
		require_contract( isset( $queue[0]['timestamp'] ) && ! $manager->did_transaction_expire( $queue ), 'Fixture A must have a valid, nonexpired manager-generated timestamp.' );
		return [ $manager, $handler, $queue ];
	}

	/** Isolate B's request hooks; append via the original queue method. */
	function append_waiter(): string {
		$actions = SessionContractFramework::$actions;
		SessionContractFramework::$actions = [];
		try {
			$handler = new SessionContractHandler();
			$waiter = new Session_Transaction_Manager( $handler );
			$waiter->transaction_id = 'wooSession_addToCart_synthetic_later_waiter';
			$waiter->get_transaction_queue();
			return $waiter->transaction_id;
		} finally {
			SessionContractFramework::$actions = $actions;
		}
	}

	$tests = [];
	$tests['B cannot leave its admission hook while valid A remains queue head'] = static function (): void {
		[ $a, , $initial_queue ] = held_head();
		SessionContractFramework::$actions = []; // The next request has its own WordPress hook registry.
		$b_handler = new SessionContractHandler();
		$b = new Session_Transaction_Manager( $b_handler );
		SessionContractFramework::$scheduler = static function ( $microseconds ) use ( $a, $b, $initial_queue ): void {
			$queue = get_transient( queue_key() );
			require_contract( 500000 === $microseconds, 'The original manager must request its documented 500ms wait.' );
			require_contract( 2 === count( $queue ) && $queue[0] === $initial_queue[0] && $queue[1]['transaction_id'] === $b->transaction_id, 'B must queue behind unchanged A without duplicate waiters.' );
			require_contract( ! $a->did_transaction_expire( $queue ), 'A must remain nonexpired throughout the bounded scheduler.' );
			if ( count( SessionContractFramework::$sleep_requests ) >= 3 ) {
				throw new HeldHeadDeadline( 'Synthetic deadline: A deliberately remains held.' );
			}
		};
		$deadline = false;
		$returned = false;
		try {
			resolve_field( 'addToCart' );
			$returned = true;
			++$b_handler->resolver_calls; // What a resolver can do once its admission hook returns.
		} catch ( HeldHeadDeadline $error ) {
			$deadline = true;
		}
		$queue = get_transient( queue_key() );
		SessionContractFramework::$observations = [
			'b_hook_returned' => $returned,
			'b_resolver_calls' => $b_handler->resolver_calls,
			'b_reloads' => $b_handler->reloads,
			'sleep_requests_us' => SessionContractFramework::$sleep_requests,
			'a_still_head' => $queue[0] === $initial_queue[0],
			'a_timestamp_valid' => ! $a->did_transaction_expire( $queue ),
			'b_still_second' => 2 === count( $queue ) && $queue[1]['transaction_id'] === $b->transaction_id,
		];
		require_contract( $deadline && ! $returned && 0 === $b_handler->resolver_calls, 'B returned and admitted its resolver before A completed; queue membership alone cannot authorize execution.' );
		require_contract( 0 === $b_handler->reloads && $queue[0] === $initial_queue[0], 'B must neither reload nor remove active A while waiting.' );
	};

	$tests['B eventually acquires and completes after A releases on the second wait'] = static function (): void {
		[ $a, $a_handler, $initial_queue ] = held_head();
		$a_id = $a->transaction_id;
		do_action( 'woocommerce_add_to_cart' );
		$a_actions = SessionContractFramework::$actions;
		SessionContractFramework::$actions = [];
		$b_handler = new SessionContractHandler();
		$b = new Session_Transaction_Manager( $b_handler );
		$release_attempted = false;
		$released = false;
		$returned = false;
		SessionContractFramework::$scheduler = static function ( $microseconds ) use ( $a, $a_handler, $a_actions, $b, $b_handler, $initial_queue, &$release_attempted, &$released ): void {
			$waits = count( SessionContractFramework::$sleep_requests );
			if ( $waits > 2 ) {
				throw new HeldHeadDeadline( 'B requested another wait after the scheduled A release; eventual admission did not occur.' );
			}
			$queue = get_transient( queue_key() );
			require_contract( 500000 === $microseconds, 'Eventual admission must retain the original 500ms wait boundary.' );
			require_contract( 2 === count( $queue ) && $queue[0] === $initial_queue[0] && $queue[1]['transaction_id'] === $b->transaction_id && ! $a->did_transaction_expire( $queue ), 'A must stay valid head and B must stay second until the scheduled release.' );
			require_contract( 0 === $b_handler->reloads && 0 === $b_handler->resolver_calls, 'B cannot reload or enter its resolver while A is held.' );
			if ( 2 === $waits ) {
				$release_attempted = true;
				$b_actions = SessionContractFramework::$actions;
				SessionContractFramework::$actions = $a_actions;
				try {
					mutation_response( 'addToCart' ); // A's actual registered completion callback.
				} finally {
					SessionContractFramework::$actions = $b_actions;
				}
				$after_release = get_transient( queue_key() );
				require_contract( null === $a->transaction_id && $after_release === [ $queue[1] ], 'A completion must release only A and preserve B before admission.' );
				require_contract( 1 === count( completion_events() ) && 1 === $a_handler->save_calls && 1 === $a_handler->dirty_saves, 'The scheduled release must complete and save A exactly once in A\'s request registry.' );
				$released = true;
			}
		};
		try {
			resolve_field( 'addToCart' );
			$returned = true;
			++$b_handler->resolver_calls;
		} finally {
			$queue = get_transient( queue_key() );
			SessionContractFramework::$observations = [
				'a_release_attempted' => $release_attempted,
				'a_completed_before_b_return' => $released,
				'b_hook_returned' => $returned,
				'b_resolver_calls' => $b_handler->resolver_calls,
				'b_reloads' => $b_handler->reloads,
				'sleep_requests_us' => SessionContractFramework::$sleep_requests,
				'a_still_head' => false !== $queue && $queue[0]['transaction_id'] === $a_id,
				'b_is_head' => false !== $queue && $queue[0]['transaction_id'] === $b->transaction_id,
				'b_head_timestamp_valid' => false !== $queue && $queue[0]['transaction_id'] === $b->transaction_id && isset( $queue[0]['timestamp'] ) && ! $b->did_transaction_expire( $queue ),
				'completion_events' => count( completion_events() ),
				'a_save_calls' => $a_handler->save_calls,
			];
		}
		require_contract( $released && $returned && [ 500000, 500000 ] === SessionContractFramework::$sleep_requests, 'B must return only after A\'s actual scheduled completion, without an extra wait after release.' );
		require_contract( 1 === $b_handler->reloads && 1 === $b_handler->resolver_calls && 1 === count( $queue ) && $queue[0]['transaction_id'] === $b->transaction_id && isset( $queue[0]['timestamp'] ) && ! $b->did_transaction_expire( $queue ), 'B must acquire the actual head, reload exactly once and timestamp its admission.' );

		$b_id = $b->transaction_id;
		$c_id = append_waiter();
		$before_completion = get_transient( queue_key() );
		require_contract( 2 === count( $before_completion ) && $before_completion[1]['transaction_id'] === $c_id, 'Fixture C must queue behind admitted B through the original insertion method.' );
		do_action( 'woocommerce_add_to_cart' );
		mutation_response( 'addToCart' );
		mutation_response( 'addToCart' );
		$after_completion = get_transient( queue_key() );
		$events = completion_events();
		SessionContractFramework::$observations += [
			'b_transaction_cleared' => null === $b->transaction_id,
			'c_preserved_as_head' => $after_completion === [ $before_completion[1] ],
			'final_completion_events' => count( $events ),
			'b_save_calls' => $b_handler->save_calls,
			'b_dirty_saves' => $b_handler->dirty_saves,
		];
		require_contract( null === $b->transaction_id && $after_completion === [ $before_completion[1] ], 'B\'s eventual completion must remove only B and preserve the exact later waiter C.' );
		require_contract( 2 === count( $events ) && $events[0][1][0] === $a_id && $events[1][1][0] === $b_id && $events[1][1][1] === [ $before_completion[1] ], 'A and B must each emit one completion with their own ID and the remaining queue.' );
		require_contract( 1 === $a_handler->save_calls && 1 === $b_handler->save_calls && 1 === $b_handler->dirty_saves, 'A and B must each save once in their own request registry, including B\'s duplicate response callback.' );
	};

	foreach ( [ false, true ] as $with_waiter ) {
		$tests[ $with_waiter ? 'matching completion removes only A, preserves B and saves exactly once' : 'matching completion deletes the empty queue and saves exactly once' ] = static function () use ( $with_waiter ): void {
			[ $a, $handler ] = held_head();
			$a_id = $a->transaction_id;
			$b_id = $with_waiter ? append_waiter() : null;
			$before = get_transient( queue_key() );
			require_contract( ! $with_waiter || ( 2 === count( $before ) && $before[1]['transaction_id'] === $b_id ), 'Fixture must append the later waiter behind A using the original manager.' );
			do_action( 'woocommerce_add_to_cart' );
			mutation_response( 'addToCart' );
			mutation_response( 'addToCart' ); // A repeated callback must not complete/save twice.
			$after = get_transient( queue_key() );
			$events = completion_events();
			SessionContractFramework::$observations = [
				'a_still_head' => false !== $after && $after[0]['transaction_id'] === $a_id,
				'queue_deleted' => false === $after,
				'queue_length' => false === $after ? 0 : count( $after ),
				'later_waiter_preserved' => ! $with_waiter || ( false !== $after && 1 === count( $after ) && $after[0] === $before[1] ),
				'a_transaction_cleared' => null === $a->transaction_id,
				'completion_events' => count( $events ),
				'save_calls' => $handler->save_calls,
				'dirty_saves' => $handler->dirty_saves,
			];
			$expected_queue = $with_waiter ? [ $before[1] ] : false;
			require_contract( $after === $expected_queue, 'Matching addToCart callback left A in the queue instead of removing only its head transaction.' );
			require_contract( null === $a->transaction_id, 'Matching completion must clear the current transaction ID.' );
			require_contract( 1 === count( $events ) && 1 === $handler->save_calls && 1 === $handler->dirty_saves, 'Completion and dirty-session save must occur exactly once, including a duplicate response callback.' );
			require_contract( $events[0][1][0] === $a_id && $events[0][1][1] === ( $with_waiter ? [ $before[1] ] : [] ), 'Completion must publish A and the remaining queue.' );
		};
	}

	$tests['wrong mutation response cannot pop the active addToCart transaction'] = static function (): void {
		[ $a, $handler, $before ] = held_head();
		$id = $a->transaction_id;
		mutation_response( 'applyCoupon' );
		$after = get_transient( queue_key() );
		SessionContractFramework::$observations = [ 'queue_unchanged' => $after === $before, 'transaction_unchanged' => $a->transaction_id === $id, 'completion_events' => count( completion_events() ), 'save_calls' => $handler->save_calls ];
		require_contract( $after === $before && $a->transaction_id === $id && [] === completion_events() && 0 === $handler->save_calls, 'A response for a different mutation removed/completed the active addToCart transaction.' );
	};

	$tests['completion exception resets local admission and propagates before a later field'] = static function (): void {
		[ $manager, $handler ] = held_head();
		$first_id = $manager->transaction_id;
		add_action( 'woographql_session_transaction_complete', static function (): void {
			throw new RuntimeException( 'Synthetic completion callback failure.' );
		}, 11, 0 );
		$caught = false;
		try {
			mutation_response( 'addToCart' );
		} catch ( RuntimeException $error ) {
			$caught = 'Synthetic completion callback failure.' === $error->getMessage();
		}
		SessionContractFramework::$observations = [ 'exception_propagated' => $caught, 'queue_deleted' => false === get_transient( queue_key() ), 'transaction_cleared' => null === $manager->transaction_id ];
		require_contract( $caught, 'The completion callback failure must propagate unchanged.' );
		require_contract( false === get_transient( queue_key() ) && null === $manager->transaction_id, 'Local admission remains armed after its queue entry is removed and a completion callback throws.' );
		unset( SessionContractFramework::$actions['woographql_session_transaction_complete'][11] );
		resolve_field( 'addToCart' );
		$queue = get_transient( queue_key() );
		require_contract( 2 === $handler->reloads && null !== $manager->transaction_id && $manager->transaction_id !== $first_id && $queue[0]['transaction_id'] === $manager->transaction_id, 'A later field must acquire a fresh transaction instead of bypassing admission.' );
		mutation_response( 'addToCart' );
		require_contract( null === $manager->transaction_id && false === get_transient( queue_key() ), 'The fresh transaction must complete normally.' );
	};

	$tests['unrelated field and response with no transaction are no-ops'] = static function (): void {
		$handler = new SessionContractHandler();
		$manager = new Session_Transaction_Manager( $handler );
		resolve_field( 'products' );
		mutation_response( 'products' );
		require_contract( false === get_transient( queue_key() ) && null === $manager->transaction_id && 0 === $handler->reloads && 0 === $handler->save_calls && [] === completion_events() && [] === SessionContractFramework::$sleep_requests, 'An unrelated field or unstarted transaction must not modify session state.' );
	};

	printf( "PHP %s; original manager SHA-256 %s\n", PHP_VERSION, hash_file( 'sha256', dirname( __DIR__, 2 ) . '/includes/utils/class-session-transaction-manager.php' ) );
	$failed = 0;
	foreach ( $tests as $name => $test ) {
		SessionContractFramework::reset();
		try {
			$test();
			printf( "PASS %s\n", $name );
		} catch ( Throwable $error ) {
			++$failed;
			printf( "FAIL %s\n  %s: %s\n  observations: %s\n", $name, get_class( $error ), $error->getMessage(), json_encode( SessionContractFramework::$observations, JSON_UNESCAPED_SLASHES ) );
		} finally {
			SessionContractFramework::reset();
		}
	}
	printf( "%d passed; %d failed; %d total\n", count( $tests ) - $failed, $failed, count( $tests ) );
	exit( $failed ? 1 : 0 );
}

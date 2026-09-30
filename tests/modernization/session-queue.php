<?php
/** Standalone regression tests of the production queue and QL session handler. */
namespace GraphQL\Error {
	class UserError extends \RuntimeException {}
}
namespace WPGraphQL {
	class Router {
		public static function is_graphql_http_request() { return false; }
	}
}
namespace WPGraphQL\WooCommerce\Utils {
	function microtime() { return $GLOBALS['clock_microtime']; }
	function time() { return (int) $GLOBALS['clock_seconds']; }
	function hrtime( $as_number = false ) { return $GLOBALS['clock_monotonic']; }
	function usleep( $microseconds ) {
		$GLOBALS['clock_monotonic'] += $microseconds * 1000;
		if ( $GLOBALS['on_sleep'] ) {
			$callback = $GLOBALS['on_sleep'];
			$GLOBALS['on_sleep'] = null;
			$callback();
		}
	}
}
namespace {
	const MINUTE_IN_SECONDS = 60;
	const WC_SESSION_CACHE_GROUP = 'woocommerce_sessions';
	$GLOBALS['locks'] = [];
	$GLOBALS['queues'] = [];
	$GLOBALS['sessions'] = [];
	$GLOBALS['contexts'] = [];
	$GLOBALS['clock_microtime'] = '0.12345600 1700000000';
	$GLOBALS['clock_seconds'] = 1700000000;
	$GLOBALS['clock_monotonic'] = 0;
	$GLOBALS['on_sleep'] = null;
	$GLOBALS['cache_deletions'] = [];

	class FakeDB {
		public $connection;
		public $fail_queue_lock = false;
		public $on_queue_lock = null;
		public $writes = 0;
		public $fail_write_after = null;
		public function __construct( $connection ) { $this->connection = $connection; }
		public function prepare( $sql, ...$args ) { return [ $sql, $args ]; }
		public function get_var( $prepared ) {
			[ $sql, $args ] = $prepared;
			$key = $args[0];
			if ( str_contains( $sql, 'IS_USED_LOCK' ) ) {
				return ( $GLOBALS['locks'][$key] ?? null ) === $this->connection ? '1' : '0';
			}
			if ( str_contains( $sql, 'GET_LOCK' ) ) {
				if ( ! str_ends_with( $key, '_execution' ) ) {
					if ( $this->fail_queue_lock ) { return '0'; }
					if ( $this->on_queue_lock ) {
						$callback = $this->on_queue_lock;
						$this->on_queue_lock = null;
						$callback();
					}
				}
				if ( isset( $GLOBALS['locks'][$key] ) && $GLOBALS['locks'][$key] !== $this->connection ) { return '0'; }
				$GLOBALS['locks'][$key] = $this->connection;
				return '1';
			}
			if ( str_contains( $sql, 'RELEASE_LOCK' ) ) {
				if ( ( $GLOBALS['locks'][$key] ?? null ) !== $this->connection ) { return '0'; }
				unset( $GLOBALS['locks'][$key] );
				return '1';
			}
			throw new \RuntimeException( 'Unexpected SQL: ' . $sql );
		}
	}
	function add_action( $name, $callback, $priority = 10, $args = 1 ) {
		$GLOBALS['contexts'][$GLOBALS['request']]['hooks'][$name][$priority][] = $callback;
	}
	function remove_action( $name, $callback, $priority = 10 ) {
		$hooks =& $GLOBALS['contexts'][$GLOBALS['request']]['hooks'][$name][$priority];
		if ( ! $hooks ) { return; }
		foreach ( $hooks as $key => $hook ) { if ( $hook === $callback ) { unset( $hooks[$key] ); } }
	}
	function do_action( $name, ...$args ) {
		$hooks = $GLOBALS['contexts'][$GLOBALS['request']]['hooks'][$name] ?? [];
		ksort( $hooks );
		foreach ( array_keys( $hooks ) as $priority ) {
			foreach ( $GLOBALS['contexts'][$GLOBALS['request']]['hooks'][$name][$priority] ?? [] as $callback ) { $callback( ...$args ); }
		}
	}
	function add_filter( $name, $callback, $priority = 10, $args = 1 ) { add_action( $name, $callback, $priority, $args ); }
	function apply_filters( $name, $value ) { return 'woographql_session_transaction_timeout' === $name ? 1 : $value; }
	function __( $text, $domain ) { return $text; }
	function is_user_logged_in() { return false; }
	function get_current_user_id() { return 0; }
	function wp_using_ext_object_cache() { return $GLOBALS['external_cache'] ?? false; }
	function wp_cache_get( $key, $group, $force = false ) {
		if ( 'transient' === $group ) {
			check( $force, 'Persistent queue cache read must force authoritative refresh' );
			return $GLOBALS['queues'][$key] ?? false;
		}
		return $GLOBALS['option_cache'][$key] ?? false;
	}
	function wp_cache_set( $key, $value, $group ) { $GLOBALS['option_cache'][$key] = $value; }
	function wp_cache_delete( $key, $group ) { $GLOBALS['cache_deletions'][] = [ $key, $group ]; }
	function get_transient( $key ) { return $GLOBALS['queues'][$key] ?? false; }
	function check_queue_write_lock( $key ) {
		$customer = substr( $key, strlen( 'graphql_woocommerce_session_transactions_queue_' ) );
		$lock = 'woo_stq_' . substr( md5( $customer ), 0, 20 );
		check( ( $GLOBALS['locks'][$lock] ?? null ) === $GLOBALS['wpdb']->connection, 'Every queue write owns the queue mutex' );
		++$GLOBALS['wpdb']->writes;
	}
	function set_transient( $key, $value, $ttl ) {
		check_queue_write_lock( $key );
		if ( null !== $GLOBALS['wpdb']->fail_write_after && $GLOBALS['wpdb']->writes >= $GLOBALS['wpdb']->fail_write_after ) { return false; }
		$GLOBALS['queues'][$key] = $value; return true;
	}
	function delete_transient( $key ) { check_queue_write_lock( $key ); unset( $GLOBALS['queues'][$key] ); return true; }
	function WC() { return $GLOBALS['contexts'][$GLOBALS['request']]['wc']; }
	class WC_Cache_Helper {
		public static function invalidate_cache_group( $group ) {}
	}
	// WooCommerce persistence boundary double; QL reload_data/save_if_dirty are real.
	class WC_Session_Handler {
		protected $_customer_id;
		protected $_data = [];
		protected $_dirty = false;
		public $saves = 0;
		public function __construct() {}
		public function get_customer_id() { return $this->_customer_id; }
		public function get_session_data() { return $this->_data; }
		public function get_session( $customer ) { return $GLOBALS['sessions'][$customer] ?? []; }
		public function get( $key, $default = null ) { return $this->_data[$key] ?? $default; }
		public function set( $key, $value ) { $this->_data[$key] = $value; $this->_dirty = true; }
		public function save_data() {
			if ( $this->_dirty ) { $GLOBALS['sessions'][$this->_customer_id] = $this->_data; $this->_dirty = false; ++$this->saves; }
		}
	}
	require __DIR__ . '/../../includes/utils/class-ql-session-handler.php';
	require __DIR__ . '/../../includes/utils/class-session-transaction-manager.php';
	class TestSession extends \WPGraphQL\WooCommerce\Utils\QL_Session_Handler {
		public $reloads = 0;
		public function __construct( $customer ) { $this->_customer_id = $customer; $this->_data = $this->get_session( $customer ); }
		public function reload_data() { ++$this->reloads; parent::reload_data(); }
		// Keep token initialization at the external boundary; init() hook registration is real.
		public function init_session_token() {}
	}
	class FakeCart {
		public $contents;
		public $loads = 0;
		public function __construct( $contents ) { $this->contents = $contents; }
		public function set_cart_contents( $contents ) { $this->contents = $contents; }
		public function get_cart_from_session() {
			++$this->loads;
			$cart = WC()->session->get( 'cart', [] );
			// WC 10.6 only sets contents if its restored cart is nonempty.
			if ( $cart ) { $this->contents = $cart; }
		}
		public function add( $item ) { $this->contents[] = $item; WC()->session->set( 'cart', $this->contents ); do_action( 'woocommerce_add_to_cart' ); }
	}
	class WC_Customer {
		public $data;
		public function __construct( $id, $is_session ) { $this->data = WC()->session->get( 'customer', [] ); }
		public function save() { WC()->session->set( 'customer', $this->data ); }
	}
	function check( $condition, $message ) { if ( ! $condition ) { throw new \RuntimeException( $message ); } }
	function select_request( $id ) { $GLOBALS['request'] = $id; $GLOBALS['wpdb'] = $GLOBALS['contexts'][$id]['db']; }
	function request( $id, $customer, $transaction = null, $native_init = false ) {
		$GLOBALS['contexts'][$id] = [ 'db' => new FakeDB( $id ), 'hooks' => [] ];
		select_request( $id );
		$session = new TestSession( $customer );
		$GLOBALS['contexts'][$id]['wc'] = (object) [ 'session' => $session, 'cart' => new FakeCart( $session->get( 'cart', [] ) ), 'customer' => null ];
		WC()->customer = new WC_Customer( 0, true );
		add_action( 'shutdown', [ WC()->customer, 'save' ], 10 );
		if ( $native_init ) {
			// Each fake request is a separate PHP process for singleton lifetime purposes.
			$instance = new \ReflectionProperty( \WPGraphQL\WooCommerce\Utils\Session_Transaction_Manager::class, 'instance' );
			$instance->setValue( null, null );
			$session->init();
			$manager = \WPGraphQL\WooCommerce\Utils\Session_Transaction_Manager::get( $session );
		} else {
			add_action( 'shutdown', [ $session, 'save_data' ] );
			$manager = new \WPGraphQL\WooCommerce\Utils\Session_Transaction_Manager( $session );
		}
		$manager->transaction_id = $transaction;
		$GLOBALS['contexts'][$id]['manager'] = $manager;
		return $manager;
	}
	function resolve( $manager, $field = 'addToCart' ) { $manager->update_transaction_queue( null, [], null, (object) [ 'fieldName' => $field ] ); }
	function complete( $manager, $field = 'addToCart' ) { $manager->complete_mutation( [], [], [], null, null, $field ); }
	function key_for( $customer ) { return 'graphql_woocommerce_session_transactions_queue_' . $customer; }
	function busy( $callback ) {
		try { $callback(); } catch ( \GraphQL\Error\UserError $error ) { check( str_contains( $error->getMessage(), 'busy' ), 'Busy error is retryable' ); return; }
		throw new \RuntimeException( 'Expected admission failure' );
	}
	$tests = [];
	$tests['IDs retain fractions, are unique at equal clocks, and sort by timestamp'] = function () {
		$m = request( 'ids', 'ids' );
		$method = new \ReflectionMethod( $m, 'generate_transaction_id' );
		$ids = [];
		for ( $i = 0; $i < 100; ++$i ) { $ids[] = $method->invoke( null ); }
		check( 100 === count( array_unique( $ids ) ), 'Equal clock IDs are unique' );
		check( str_starts_with( $ids[0], '1700000000_123456_' ), 'Fraction preserved' );
		$GLOBALS['clock_microtime'] = '0.12345700 1700000000';
		check( strcmp( $ids[0], $method->invoke( null ) ) < 0, 'Timestamp sort retained' );
		$GLOBALS['clock_microtime'] = '0.12345600 1700000000';
	};
	$tests['interleaved requests preserve whole batch and reload final cart/customer'] = function () {
		$GLOBALS['sessions']['batch'] = [ 'cart' => ['initial'], 'customer' => ['address' => 'old'] ];
		$a = request( 'a', 'batch', 'a' );
		$b = request( 'b', 'batch', 'b' );
		select_request( 'a' ); resolve( $a ); WC()->cart->add( 'a1' ); complete( $a );
		check( ['initial', 'a1'] === $GLOBALS['sessions']['batch']['cart'], 'Per-mutation dirty save persists' );
		select_request( 'b' );
		$GLOBALS['on_sleep'] = function () use ( $a ) {
			select_request( 'a' ); resolve( $a ); WC()->cart->add( 'a2' ); WC()->customer->data = ['address' => 'new']; WC()->customer->save(); complete( $a );
			check( 1 === WC()->session->reloads, 'Owned batch retains in-memory state' );
			$a->pop_transaction_id(); select_request( 'b' );
		};
		resolve( $b );
		check( ['initial', 'a1', 'a2'] === WC()->cart->contents, 'Waiter hydrates final cart' );
		check( ['address' => 'new'] === WC()->customer->data, 'Waiter hydrates final customer' );
		WC()->cart->add( 'b1' ); complete( $b ); $b->pop_transaction_id();
		check( ['initial', 'a1', 'a2', 'b1'] === $GLOBALS['sessions']['batch']['cart'], 'No lost cart updates' );
	};
	$tests['alive stale owner blocks execution; timeout and nonowner shutdown preserve head'] = function () {
		$a = request( 'live-a', 'live', 'a' ); resolve( $a );
		$GLOBALS['queues'][key_for('live')][0]['timestamp'] -= 100;
		$b = request( 'live-b', 'live', 'b' );
		busy( function () use ( $b ) { resolve( $b ); } );
		check( 0 === WC()->session->reloads, 'Waiting request never executes' );
		check( 'a' === $GLOBALS['queues'][key_for('live')][0]['transaction_id'], 'Live owner not evicted by age' );
		busy( function () use ( $b ) { resolve( $b, 'checkout' ); } );
		$b->pop_transaction_id();
		check( 1 === count( $GLOBALS['queues'][key_for('live')] ), 'Only nonowner entry removed' );
		WC()->session->set( 'cart', ['stale'] ); do_action( 'shutdown' );
		check( ! isset( $GLOBALS['sessions']['live'] ), 'Rejected request cannot save stale session at shutdown' );
		select_request( 'live-a' ); $a->pop_transaction_id();
	};
	$tests['older late arrival cannot displace active head'] = function () {
		$a = request( 'late-a', 'late', 'z' ); resolve( $a );
		$b = request( 'late-b', 'late', 'a' ); $queue = $b->get_transaction_queue();
		check( ['z', 'a'] === array_column( $queue, 'transaction_id' ), 'Active head stays first' );
		check( false === $b->next_transaction(), 'Late request waits' ); $b->pop_transaction_id();
		select_request( 'late-a' ); $a->pop_transaction_id();
	};
	$tests['lock timeout fails closed without unlocked writes or execution'] = function () {
		$m = request( 'lock-fail', 'lock-fail' ); $GLOBALS['wpdb']->fail_queue_lock = true;
		busy( function () use ( $m ) { resolve( $m ); } );
		check( 0 === $GLOBALS['wpdb']->writes && 0 === WC()->session->reloads, 'No writes/reload after failed lock' );
		check( ! isset( $GLOBALS['queues'][key_for('lock-fail')] ), 'Nothing queued without lock' );
		$GLOBALS['wpdb']->fail_queue_lock = false; $m->pop_transaction_id();
	};
	$tests['stale recovery rechecks changed head after lock acquisition'] = function () {
		$m = request( 'race', 'race', 'z' );
		$GLOBALS['queues'][key_for('race')] = [ ['transaction_id' => 'a', 'timestamp' => 1, 'active' => true] ];
		$GLOBALS['wpdb']->on_queue_lock = function () {
			$GLOBALS['queues'][key_for('race')] = [ ['transaction_id' => 'b', 'timestamp' => $GLOBALS['clock_seconds']] ];
		};
		check( false === $m->next_transaction(), 'New head still blocks' );
		check( 'b' === $GLOBALS['queues'][key_for('race')][0]['transaction_id'], 'Fresh replacement head not evicted' );
		$m->pop_transaction_id(); unset( $GLOBALS['queues'][key_for('race')] );
	};
	$tests['orphan stale head recovers; latest empty cart clears preloaded cart'] = function () {
		$GLOBALS['sessions']['orphan'] = [ 'cart' => ['old'] ];
		$m = request( 'orphan', 'orphan', 'z' );
		$GLOBALS['sessions']['orphan']['cart'] = [];
		$GLOBALS['queues'][key_for('orphan')] = [ ['transaction_id' => 'a', 'timestamp' => 1, 'active' => true] ];
		resolve( $m, 'checkout' );
		check( [] === WC()->cart->contents, 'Empty persisted cart clears cached contents' );
		check( 'z' === $GLOBALS['queues'][key_for('orphan')][0]['transaction_id'], 'Orphan replaced by actual owner' );
		$m->pop_transaction_id();
	};
	$tests['shutdown cannot change queue after mutex acquisition fails'] = function () {
		$m = request( 'cleanup-fail', 'cleanup-fail' ); resolve( $m );
		$before = $GLOBALS['queues'][key_for('cleanup-fail')];
		$GLOBALS['wpdb']->fail_queue_lock = true; $m->pop_transaction_id();
		check( $before === $GLOBALS['queues'][key_for('cleanup-fail')], 'Failed cleanup retains orphan for recovery' );
		check( ! in_array( 'cleanup-fail', $GLOBALS['locks'], true ), 'Execution ownership released' );
		$GLOBALS['wpdb']->fail_queue_lock = false; unset( $GLOBALS['queues'][key_for('cleanup-fail')] );
	};
	$tests['connection loss rejects later mutations and guards shutdown persistence'] = function () {
		$m = request( 'connection-loss', 'connection-loss' ); resolve( $m );
		WC()->session->set( 'cart', ['unsaved-stale'] );
		foreach ( $GLOBALS['locks'] as $key => $owner ) { if ( 'connection-loss' === $owner ) { unset( $GLOBALS['locks'][$key] ); } }
		do_action( 'shutdown' );
		check( ! isset( $GLOBALS['sessions']['connection-loss'] ), 'Ownership guard runs before Woo shutdown writes' );
		busy( function () use ( $m ) { resolve( $m ); } );
		complete( $m ); $m->pop_transaction_id();
		check( ! isset( $GLOBALS['sessions']['connection-loss'] ), 'Lost connection never flushes stale data' );
	};
	$tests['persistent cache refresh and targeted missing-option cache repair'] = function () {
		$GLOBALS['external_cache'] = true;
		$m = request( 'external', 'external' ); resolve( $m ); $m->pop_transaction_id();
		$GLOBALS['external_cache'] = false;
		$GLOBALS['option_cache']['notoptions'] = [ '_transient_' . key_for('cache') => true, '_transient_timeout_' . key_for('cache') => true, 'unrelated' => true ];
		$m = request( 'cache', 'cache' ); resolve( $m ); $m->pop_transaction_id();
		check( ['unrelated' => true] === $GLOBALS['option_cache']['notoptions'], 'Repair only affected missing-option keys' );
	};
	$tests['new candidate cart mutation names participate in admission'] = function () {
		foreach ( ['checkout', 'addCartItems', 'fillCart'] as $field ) {
			$m = request( 'field-' . $field, 'field-' . $field ); resolve( $m, $field );
			check( 1 === WC()->session->reloads, $field . ' is admitted' ); $m->pop_transaction_id();
		}
	};
	$tests['failed queue persistence rejects execution and does not flush stale data'] = function () {
		foreach ( [1, 2] as $after ) {
			$id = 'persist-fail-' . $after;
			$m = request( $id, $id ); WC()->session->set( 'cart', ['stale'] );
			$GLOBALS['wpdb']->fail_write_after = $after;
			busy( function () use ( $m ) { resolve( $m ); } );
			check( 0 === WC()->session->reloads, 'Failed enqueue/admission persistence prevents execution' );
			do_action( 'shutdown' ); complete( $m ); $m->pop_transaction_id();
			check( ! isset( $GLOBALS['sessions'][$id] ), 'Failed admission never saves preloaded dirty session' );
		}
	};
	$tests['production non-HTTP init priority-20 save is suppressed after admission or ownership failure'] = function () {
		foreach ( ['admission', 'ownership'] as $failure ) {
			$id = 'native-' . $failure;
			$m = request( $id, $id, null, true );
			$save_callback = [ WC()->session, 'save_data' ];
			check( in_array( $save_callback, $GLOBALS['contexts'][$id]['hooks']['shutdown'][20] ?? [], true ), 'Production QL init registers priority-20 save' );
			if ( 'admission' === $failure ) {
				WC()->session->set( 'cart', ['stale'] );
				$GLOBALS['wpdb']->fail_queue_lock = true;
				busy( function () use ( $m ) { resolve( $m ); } );
			} else {
				resolve( $m ); WC()->session->set( 'cart', ['stale'] );
				foreach ( $GLOBALS['locks'] as $key => $owner ) { if ( $id === $owner ) { unset( $GLOBALS['locks'][$key] ); } }
				$m->guard_shutdown_saves();
			}
			check( ! in_array( $save_callback, $GLOBALS['contexts'][$id]['hooks']['shutdown'][20] ?? [], true ), 'Rejected native priority-20 callback is removed' );
			do_action( 'shutdown' );
			check( ! isset( $GLOBALS['sessions'][$id] ) && 0 === WC()->session->saves, 'Native shutdown cannot persist rejected dirty cart' );
			$GLOBALS['wpdb']->fail_queue_lock = false; $m->pop_transaction_id();
		}
	};
	foreach ( $tests as $name => $test ) { $test(); echo "PASS $name\n"; }
	check( ! $GLOBALS['locks'], 'All locks released by cleanup' );
	check( count( $GLOBALS['cache_deletions'] ) > 0, 'Request-local option caches are invalidated' );
	echo count( $tests ) . " session queue regression tests passed.\n";
}

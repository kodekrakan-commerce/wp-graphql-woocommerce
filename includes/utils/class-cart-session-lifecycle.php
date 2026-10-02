<?php
/**
 * Captured GraphQL HTTP cart lifecycle. Activation requires an explicitly reviewed
 * source/callback cohort; unknown callbacks, streaming and object replacement are
 * refused. This is not an arbitrary-plugin sandbox or a payment rollback layer.
 */
namespace WPGraphQL\WooCommerce\Utils;

final class Cart_Session_Lifecycle {

	private const SOURCE_COHORT = [
		'WPGraphQL\\WooCommerce\\Utils\\QL_Session_Handler' => '20f9551ef34bf3b9ff3ee91b89f54fa71c8282ac1c62d714348c6e39ef5a9447',
		'WP_Hook' => 'b839c0e5672246bca8db1ab781ec8835f7732f253c375a237cbf6ec536e8d12e',
		'WPGraphQL\\Router' => '4c85426fdc7223c69358ed70e68ba45e4c5f632a4234f860ebba41d68ec32ea7',
		'WC_Customer' => '14ca0da46d63445e72053cba79fad368393490e7bf416e53936eeb17c579a452',
		'WC_Cart' => 'fd1ca75de053a52c7032822ee865da2ae4f3d299afd5f50ac362d888b2703824',
		'WC_Cart_Session' => '5e871b805ec488e7b1497e33eb83d43334ebd33c1f5feaa670deb2dfa12dd25e',
	];
	private const OWN_CALLBACKS = [
		'graphql_process_http_request_response' => 'send_response',
		'graphql_response_headers_to_send' => 'send_early_auth_response',
		'graphql_authentication_error_status_code' => 'capture_auth_error',
		'woocommerce_cart_session_initialize' => 'capture_cart_session',
	];
	private const COHORT_HOOKS = [
		'all', 'graphql_process_http_request_response', 'graphql_response_headers_to_send',
		'graphql_authentication_error_status_code', 'graphql_response_set_headers',
		'woocommerce_cart_session_initialize',
	];
	private $handler;
	private $boundary;
	private $installed = false;
	private $terminal = false;
	private $writers_closed = false;
	private $customer;
	private $cart;
	private $cart_session;
	private $auth_error;
	private $auth_status;
	private $manifest = [];
	private $sources;
	private $receivers = [];
	private $frozen;

	public function __construct( QL_Session_Handler $handler, string $credential_header, array $owned_cookie_names ) {
		$this->handler = $handler;
		$this->sources = defined( 'WOOGRAPHQL_CART_SESSION_SOURCE_COHORT' )
			? WOOGRAPHQL_CART_SESSION_SOURCE_COHORT : self::SOURCE_COHORT;
		$this->manifest = defined( 'WOOGRAPHQL_CART_SESSION_CALLBACK_COHORT' )
			? WOOGRAPHQL_CART_SESSION_CALLBACK_COHORT : [];
		$this->boundary = new Cart_Session_HTTP_Boundary( [ $this, 'cleanup' ], [ $credential_header ], $owned_cookie_names );
	}

	private function reject(): never {
		throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE );
	}

	private function qualified_source( string $class ): void {
		try {
			$expected = is_array( $this->sources ) ? ( $this->sources[ $class ] ?? null ) : null;
			$file = ( new \ReflectionClass( $class ) )->getFileName();
			if ( ! is_string( $expected ) || ! preg_match( '/\A[a-f0-9]{64}\z/D', $expected )
				|| ! $file || ! hash_equals( $expected, hash_file( 'sha256', $file ) ) ) {
				$this->reject();
			}
		} catch ( \Throwable $error ) {
			$this->reject();
		}
	}

	/** Install the native output boundary before any owned storage construction. */
	public function install(): void {
		if ( $this->installed ) { $this->reject(); }
		$this->boundary->install();
		$this->installed = true;
		foreach ( self::SOURCE_COHORT as $class => $unused ) { $this->qualified_source( $class ); }
		if ( ! is_array( $this->manifest ) ) { $this->reject(); }
		foreach ( self::COHORT_HOOKS as $hook ) {
			if ( 'all' !== $hook ) { add_filter( $hook, [ $this, 'guard_cohort_entry' ], PHP_INT_MIN, 1 ); }
		}
		add_filter( 'woocommerce_cart_session_initialize', [ $this, 'capture_cart_session' ], PHP_INT_MAX, 2 );
		add_action( 'do_graphql_request', [ $this, 'guard_request' ], PHP_INT_MIN, 0 );
		add_action( 'wp_loaded', [ $this, 'guard_created_objects' ], PHP_INT_MIN, 0 );
		add_action( 'woocommerce_init', [ $this, 'guard_created_objects' ], PHP_INT_MAX, 0 );
		add_filter( 'graphql_authentication_error_status_code', [ $this, 'capture_auth_error' ], PHP_INT_MAX, 2 );
		$this->arm_terminals();
		add_action( 'shutdown', [ $this, 'abort_at_shutdown' ], PHP_INT_MIN, 0 );
		$this->cohort( false );
	}

	/** Reappend only owned terminals before dispatch, then bind the ordered cohort. */
	private function arm_terminals(): void {
		foreach ( [ 'graphql_process_http_request_response' => 'send_response', 'graphql_response_headers_to_send' => 'send_early_auth_response' ] as $hook => $method ) {
			remove_filter( $hook, [ $this, $method ], PHP_INT_MAX );
			add_filter( $hook, [ $this, $method ], PHP_INT_MAX, 'send_response' === $method ? 6 : 1 );
		}
	}

	/** Exact known owner callbacks are not a file-wide whitelist. */
	private function owned_callback( string $hook, $callback, int $priority, int $arguments ): bool {
		if ( 'all' !== $hook && [ $this, 'guard_cohort_entry' ] === $callback ) {
			return PHP_INT_MIN === $priority && 1 === $arguments;
		}
		if ( isset( self::OWN_CALLBACKS[ $hook ] ) && [ $this, self::OWN_CALLBACKS[ $hook ] ] === $callback ) {
			$expected = 'graphql_process_http_request_response' === $hook ? 6
				: ( in_array( $hook, [ 'graphql_authentication_error_status_code', 'woocommerce_cart_session_initialize' ], true ) ? 2 : 1 );
			return PHP_INT_MAX === $priority && $expected === $arguments;
		}
		if ( 'graphql_response_headers_to_send' === $hook
			&& [ $this->handler, 'add_prepared_session_header' ] === $callback ) {
			$this->qualified_source( QL_Session_Handler::class );
			return 10 === $priority && 1 === $arguments;
		}
		return false;
	}

	/** Explicit installation records pin location, signature, effects and receiver. */
	private function qualified_callback( string $hook, $callback, int $priority, int $arguments ): bool {
		foreach ( $this->manifest as $index => $record ) {
			if ( ! is_array( $record ) || ( $record['hook'] ?? null ) !== $hook
				|| ( $record['priority'] ?? null ) !== $priority || ( $record['accepted_args'] ?? null ) !== $arguments
				|| true !== ( $record['stable_registry'] ?? null ) || true !== ( $record['nonstreaming'] ?? null ) ) { continue; }
			try {
				$receiver = null;
				if ( 'closure' === ( $record['kind'] ?? null ) && $callback instanceof \Closure ) {
					$reflection = new \ReflectionFunction( $callback );
					$receiver = $reflection->getClosureThis();
					if ( $reflection->getStartLine() !== ( $record['start'] ?? null ) || $reflection->getEndLine() !== ( $record['end'] ?? null )
						|| ( $reflection->getClosureScopeClass() ? $reflection->getClosureScopeClass()->getName() : null ) !== ( $record['scope'] ?? null )
						|| ( $receiver ? get_class( $receiver ) : null ) !== ( $record['receiver_class'] ?? null ) ) { continue; }
				} elseif ( 'method' === ( $record['kind'] ?? null ) && is_array( $callback ) && 2 === count( $callback ) ) {
					$class = is_object( $callback[0] ) ? get_class( $callback[0] ) : $callback[0];
					if ( $class !== ( $record['class'] ?? null ) || $callback[1] !== ( $record['method'] ?? null )
						|| is_object( $callback[0] ) !== ( $record['instance'] ?? null ) ) { continue; }
					$reflection = new \ReflectionMethod( $class, $callback[1] );
					$receiver = is_object( $callback[0] ) ? $callback[0] : null;
				} elseif ( 'function' === ( $record['kind'] ?? null ) && is_string( $callback ) && $callback === ( $record['function'] ?? null ) ) {
					$reflection = new \ReflectionFunction( $callback );
				} else { continue; }
				$file = $reflection->getFileName(); $hash = $record['sha256'] ?? null;
				if ( ! $file || ! is_string( $hash ) || ! preg_match( '/\A[a-f0-9]{64}\z/D', $hash )
					|| ! hash_equals( $hash, hash_file( 'sha256', $file ) ) ) { continue; }
				$binding = [ $callback, $receiver ];
				if ( isset( $this->receivers[ $index ] ) && $this->receivers[ $index ] !== $binding ) { continue; }
				$this->receivers[ $index ] = $binding;
				return true;
			} catch ( \Throwable $error ) { $this->reject(); }
		}
		return false;
	}

	/** Compare registry objects AND their ordered callback/priority/argument entries. */
	private function cohort( bool $freeze ): void {
		$this->qualified_source( 'WP_Hook' );
		$current = [];
		foreach ( self::COHORT_HOOKS as $hook ) {
			$registry = $GLOBALS['wp_filter'][ $hook ] ?? null;
			$entries = [];
			if ( null !== $registry ) {
				if ( ! $registry instanceof \WP_Hook || \WP_Hook::class !== get_class( $registry ) ) { $this->reject(); }
				foreach ( $registry->callbacks as $priority => $callbacks ) {
					foreach ( $callbacks as $id => $entry ) {
						$callback = $entry['function'] ?? null; $arguments = $entry['accepted_args'] ?? null;
						if ( ! is_int( $arguments ) || ( ! $this->owned_callback( $hook, $callback, (int) $priority, $arguments )
							&& ! $this->qualified_callback( $hook, $callback, (int) $priority, $arguments ) ) ) { $this->reject(); }
						$entries[] = [ (int) $priority, $id, $arguments, $callback ];
					}
				}
			}
			$current[ $hook ] = [ $registry, $entries ];
		}
		if ( null !== $this->frozen && $this->frozen !== $current ) { $this->reject(); }
		if ( $freeze ) { $this->frozen = $current; }
	}

	private function require_tail( string $hook, string $method ): void {
		$this->cohort( false );
		$registry = $GLOBALS['wp_filter'][ $hook ] ?? null;
		$entries = $registry instanceof \WP_Hook ? ( $registry->callbacks[ PHP_INT_MAX ] ?? [] ) : [];
		$last = $entries ? end( $entries ) : null;
		if ( ! is_array( $last ) || ( $last['function'] ?? null ) !== [ $this, $method ] ) { $this->reject(); }
	}

	/** This entry runs before resolvers, but all-hook stability is qualified separately. */
	public function guard_request(): void {
		$this->cohort( false );
		if ( null === $this->frozen ) { $this->arm_terminals(); $this->cohort( true ); }
		if ( $this->handler->has_owned_scope() ) { $this->assert_objects(); }
	}

	/** Reject a changed specific-hook cohort before its non-owner callbacks run. */
	public function guard_cohort_entry( $value ) {
		$this->cohort( false );
		return $value;
	}

	public function guard_created_objects(): void {
		if ( null !== $this->cart_session && ! $this->terminal && ! $this->handler->is_auth_detached() ) { $this->assert_objects(); }
	}

	/** Core calls this before assigning WC()->cart; read only the pinned back-reference. */
	public function capture_cart_session( $enabled, $session ) {
		$this->require_tail( 'woocommerce_cart_session_initialize', 'capture_cart_session' );
		if ( ! $enabled ) { return $enabled; } // Do not adopt disabled secondary carts.
		if ( ! $this->handler->has_owned_scope() ) { return false; }
		$this->handler->assert_owned_scope();
		if ( null !== $this->cart_session || ! $session instanceof \WC_Cart_Session || get_class( $session ) !== 'WC_Cart_Session' ) { $this->reject(); }
		$this->qualified_source( 'WC_Cart_Session' );
		$property = new \ReflectionProperty( \WC_Cart_Session::class, 'cart' );
		$cart = $property->getValue( $session ); $wc = \WC(); $customer = $wc->customer ?? null;
		if ( ( $wc->session ?? null ) !== $this->handler || ! $cart instanceof \WC_Cart || get_class( $cart ) !== 'WC_Cart'
			|| ! $customer instanceof \WC_Customer || get_class( $customer ) !== 'WC_Customer' ) { $this->reject(); }
		$this->customer = $customer; $this->cart = $cart; $this->cart_session = $session;
		return $enabled;
	}

	private function assert_objects(): void {
		$this->cohort( false );
		$this->handler->assert_session_ready(); $this->handler->assert_owned_scope();
		$wc = \WC();
		if ( null === $this->customer || null === $this->cart || null === $this->cart_session
			|| ( $wc->session ?? null ) !== $this->handler || ( $wc->customer ?? null ) !== $this->customer || ( $wc->cart ?? null ) !== $this->cart
			|| (int) $this->customer->get_id() !== (int) get_current_user_id() ) { $this->reject(); }
		foreach ( [ 'WC_Customer', 'WC_Cart', 'WC_Cart_Session' ] as $class ) { $this->qualified_source( $class ); }
		$property = new \ReflectionProperty( \WC_Cart_Session::class, 'cart' );
		if ( $property->getValue( $this->cart_session ) !== $this->cart ) { $this->reject(); }
	}

	/** Exact known writers only; preserve every unrelated object and callback. */
	public function close_writers(): void {
		$writers = [ [ $this->handler, 'save_data', 'shutdown' ] ];
		if ( null !== $this->customer ) { $writers[] = [ $this->customer, 'save', 'shutdown' ]; }
		if ( null !== $this->cart_session ) {
			foreach ( [ 'wp_loaded' => [ 'get_cart_from_session' ], 'woocommerce_cart_emptied' => [ 'destroy_cart_session' ],
				'woocommerce_after_calculate_totals' => [ 'set_session' ], 'woocommerce_removed_coupon' => [ 'set_session' ],
				'woocommerce_add_to_cart' => [ 'persistent_cart_update', 'maybe_set_cart_cookies' ],
				'woocommerce_cart_item_removed' => [ 'persistent_cart_update' ], 'woocommerce_cart_item_restored' => [ 'persistent_cart_update' ],
				'woocommerce_cart_item_set_quantity' => [ 'persistent_cart_update' ], 'wp' => [ 'maybe_set_cart_cookies' ],
				'shutdown' => [ 'maybe_set_cart_cookies' ], 'template_redirect' => [ 'clean_up_removed_cart_contents' ] ] as $hook => $methods ) {
				foreach ( $methods as $method ) { $writers[] = [ $this->cart_session, $method, $hook ]; }
			}
		}
		if ( null !== $this->cart ) {
			foreach ( [ 'woocommerce_add_to_cart', 'woocommerce_applied_coupon', 'woocommerce_removed_coupon', 'woocommerce_cart_item_removed', 'woocommerce_cart_item_restored' ] as $hook ) {
				$writers[] = [ $this->cart, 'calculate_totals', $hook ];
			}
		}
		foreach ( $writers as [ $object, $method, $hook ] ) {
			$registry = $GLOBALS['wp_filter'][ $hook ] ?? null;
			if ( ! $registry instanceof \WP_Hook ) { continue; }
			foreach ( $registry->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $entry ) {
					if ( ( $entry['function'] ?? null ) === [ $object, $method ] ) { remove_action( $hook, [ $object, $method ], (int) $priority ); }
				}
			}
		}
		$this->writers_closed = true;
	}

	public function is_terminal(): bool { return $this->terminal; }

	/** Must run before any accepted account callback; old authority is discarded. */
	public function cleanup(): void {
		$this->close_writers();
		try { $this->handler->discard_owned_scope(); }
		finally { $this->terminal = true; }
	}

	/** Shutdown only aborts unfinished work; it never declares a successful save. */
	public function abort_at_shutdown(): void {
		if ( ! $this->terminal ) { $this->cleanup(); }
	}

	public function capture_auth_error( $status, $error ) {
		$this->require_tail( 'graphql_authentication_error_status_code', 'capture_auth_error' );
		if ( ! $error instanceof \WP_Error || ! is_int( $status ) || $status < 100 || $status > 599 || null !== $this->auth_error ) { $this->reject(); }
		$this->auth_error = $error; $this->auth_status = $status;
		return $status;
	}

	/** Router calls this before setting headers; send exactly the validated map. */
	public function send_early_auth_response( $headers ) {
		$this->require_tail( 'graphql_response_headers_to_send', 'send_early_auth_response' );
		if ( null === $this->auth_error ) { return $headers; }
		$this->handler->assert_response_available();
		if ( ! is_array( $headers ) ) { $this->reject(); }
		foreach ( $this->manifest as $record ) {
			if ( is_array( $record ) && 'graphql_response_set_headers' === ( $record['hook'] ?? null )
				&& true !== ( $record['skip_after_early_auth'] ?? null ) ) { $this->reject(); }
		}
		foreach ( $headers as $name => $value ) {
			if ( ! is_string( $name ) || ! preg_match( '/\A[!#$%&\'*+.^_`|~0-9A-Za-z-]+\z/D', $name )
				|| ! is_string( $value ) || preg_match( '/[\r\n\x00]/', $value ) ) { $this->reject(); }
			\header( $name . ': ' . $value, true );
		}
		$response = [ 'errors' => [ [ 'message' => $this->auth_error->get_error_message() ] ] ];
		$this->boundary->discard( $response, $this->auth_status );
	}

	/** Final response serialization stays inside ownership; raw emit follows release. */
	public function send_response( $response, $deprecated = null, $operation_name = null, $query = null, $variables = null, $status = null ): never {
		$this->require_tail( 'graphql_process_http_request_response', 'send_response' );
		if ( $this->terminal || null !== $this->auth_error || ! is_int( $status ) ) { $this->reject(); }
		$this->handler->assert_response_available();
		if ( $this->handler->has_session_rejection() ) {
			// Discard healthy but rejected identity work without publishing partial
			// data or any token already serialized by an earlier sibling field.
			if ( ! is_array( $response ) || empty( $response['errors'] ) || ! is_array( $response['errors'] ) ) { $this->reject(); }
			$this->boundary->discard( [ 'errors' => $response['errors'] ], $status );
		}
		$this->boundary->complete( $response, function (): void {
			if ( $this->handler->is_auth_detached() ) {
				$this->close_writers(); $this->terminal = true; return;
			}
			if ( ! $this->handler->has_owned_scope() ) {
				$this->close_writers(); $this->terminal = true; return;
			}
			$this->cohort( false ); $this->assert_objects(); $this->close_writers();
			$this->customer->save(); $this->assert_objects();
			$this->cart_session->set_session(); $this->assert_objects();
			$this->cart_session->persistent_cart_update(); $this->assert_objects();
			$this->cart_session->maybe_set_cart_cookies(); $this->assert_objects();
			$this->close_writers(); // Recheck any exact captured writers readded by a flush callback.
			$this->handler->complete_owned_scope();
			$this->handler->assert_response_available();
			$this->terminal = true;
		}, $status );
	}
}

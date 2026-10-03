<?php
/**
 * Captured GraphQL HTTP cart lifecycle. Activation requires an explicitly reviewed
 * source/callback cohort; unknown callbacks, streaming and object replacement are
 * refused. This is not an arbitrary-plugin sandbox or a payment rollback layer.
 */
namespace WPGraphQL\WooCommerce\Utils;

final class Cart_Session_Lifecycle {

	private const SOURCE_COHORT = [
		'WPGraphQL\\WooCommerce\\Utils\\QL_Session_Handler' => 'ca4176be3b115927124f254b0ac36f005d30cf9f2e9a8d3e1f04ea411878af80',
		'WP_Hook' => 'b839c0e5672246bca8db1ab781ec8835f7732f253c375a237cbf6ec536e8d12e',
		'WPGraphQL\\Router' => '4c85426fdc7223c69358ed70e68ba45e4c5f632a4234f860ebba41d68ec32ea7',
		'WC_Customer' => '14ca0da46d63445e72053cba79fad368393490e7bf416e53936eeb17c579a452',
		'WC_Cart' => 'fd1ca75de053a52c7032822ee865da2ae4f3d299afd5f50ac362d888b2703824',
		'WC_Cart_Session' => '5e871b805ec488e7b1497e33eb83d43334ebd33c1f5feaa670deb2dfa12dd25e',
		'WC_Payment_Gateways' => 'd474b55ac2bfc8cc6ee5d47ef8c836c9447dba66d5a76b7c9db70e73d13ae758',
	];
	private const ORIGIN_SOURCE_COHORT = [
		'WPGraphQL\\WooCommerce\\Utils\\Cart_Session_Operation' => '0a1f6e9b48e46db49032568b1baf5de17e887b508362e446454968dbfe0834a2',
		'WPGraphQL\\WooCommerce\\Mutation\\Checkout' => '013c4c0aa8bf172fa80f05d5b512e7f8a74b1a51ac2abbb4f8d44bdd601ac8ef',
		'WPGraphQL\\WooCommerce\\Data\\Mutation\\Checkout_Mutation' => '920c0de6b40db4a1b543750ce839e2896b69360934b0b1714d68519eac2c1af7',
		'WPGraphQL\\Type\\WPMutationType' => '33bfcaeec264a56c94367a9d49dfa61d1e9f21d24acfe903adca9fc4295207e7',
		'WPGraphQL\\Utils\\InstrumentSchema' => '6f3bf9d2bd1b49798a0adc22aa843b8f5b74e89f73ebcb91916ea12957ba529c',
	];
	private const RESPONSE_SOURCE_COHORT = [
		'GraphQL\\Executor\\ExecutionResult' => '899f37cf608b43eddba734604ba301e178b02f7bf12edf0b8b920b4053435fd9',
	];
	private const CREATION_SOURCE_COHORT = [
		'WP_User' => 'a6330b51ec04dbfe0b968a1130148aedb63e28b64fc533f24c1da563e11d5fe2',
		'function:wp_validate_auth_cookie' => '3a2482a65b50d62ebae75a728be98b78985d3a3f5a3e007cb2d0c1338bf05777',
		'function:wp_insert_user' => 'c837321fbf722f89a080324f3c742b0c44e5853c9bd6d305cb894b5f664fc6cc',
		'function:wp_set_current_user' => '3a2482a65b50d62ebae75a728be98b78985d3a3f5a3e007cb2d0c1338bf05777',
		'function:wp_set_auth_cookie' => '3a2482a65b50d62ebae75a728be98b78985d3a3f5a3e007cb2d0c1338bf05777',
		'function:wp_generate_auth_cookie' => '3a2482a65b50d62ebae75a728be98b78985d3a3f5a3e007cb2d0c1338bf05777',
		'function:wc_create_new_customer' => '3443eb7b280e7c1806592b8574e0a4d64aba962c21c7306480a3751b46c1387d',
		'function:wc_set_customer_auth_cookie' => '3443eb7b280e7c1806592b8574e0a4d64aba962c21c7306480a3751b46c1387d',
		'WPGraphQL\\AppContext' => 'ddf6a8696e7e134452e6de07f11f8168dd45da70d81529c5829ccce7a43480b3',
		'WPGraphQL\\Data\\Loader\\AbstractDataLoader' => '2608a64e9a4d41c0ffd5883fb49636b2301204ab133e4aa6ac5f175a4a09c93c',
		'WP_Session_Tokens' => '0708de7171949675139eda1e476935763f65e8a3a0cf40fc534dfffa00fb04cb',
		'WP_User_Meta_Session_Tokens' => 'bd32717507322f0e15234d546c4938c8e2d092d7166d4055f8ec6815ed09daa8',
		'WC_Checkout' => 'be61337296f7e69544acbb61bb6ffa0f895b52b896ed7565bb12f91b117b5642',
		'WC_Order' => 'b00e1aef43fca2f4c173c59ceaaac2aa1954cf666836e4fc3deed837434a7d41',
		'WC_Abstract_Order' => '59a07e58b30a198491977da76cbff3c01f497fc09f5fdac818c6806fdc3b2ff6',
		'WC_Data_Store' => 'e7b9c236bb0d879c5388ba7bfe0ff0afb7775c085422d33e6206481a32178f43',
		'WC_Order_Data_Store_CPT' => '1f1b0e4523c53a13c0b04be74300f76e8c79180d5d23fc123ddbaf95ed180192',
	];
	private const DEFERRED_SOURCE_COHORT = [
		'function:wc_get_is_paid_statuses' => '4b1132af35887f07a18c99520325323bd2eeca16f135b5d8cb6780f9124ad2dd',
		'function:woonuxt_defer_stripe_checkout' => 'ee2232b4c2fa54f6ed017fd99d3d501512960ea1212cf6259cdb5ca53268be58',
		'WC_Data' => 'a62ab1ea96c7aee3413110d0e5895abe27e9031ae6eca16940635ee60d5ec4d7',
		'WC_Meta_Data' => '185aa239222c773932e5f1f23b71079d1157d682764a3fecdf77060b7d8321e8',
		'WC_Data_Store_WP' => '8b0d25372e19517bb8d7ccab613c303aef62be78c0751c1403c9448c2dbac2ca',
		'function:add_metadata' => 'b1b49d1d3ddbf10d0f17286f53c30ce9582c636a4679f9a0ef92ee5ebb020a3e',
		'function:update_metadata_by_mid' => 'b1b49d1d3ddbf10d0f17286f53c30ce9582c636a4679f9a0ef92ee5ebb020a3e',
		'function:delete_metadata_by_mid' => 'b1b49d1d3ddbf10d0f17286f53c30ce9582c636a4679f9a0ef92ee5ebb020a3e',
	];
	private const OWN_CALLBACKS = [
		'graphql_process_http_request_response' => 'send_response',
		'graphql_response_headers_to_send' => 'send_early_auth_response',
		'graphql_authentication_error_status_code' => 'capture_auth_error',
		'woocommerce_cart_session_initialize' => 'capture_cart_session',
		'woocommerce_new_customer_data' => 'guard_checkout_customer_data',
		'secure_auth_cookie' => 'capture_secure_auth', 'secure_logged_in_cookie' => 'capture_secure_logged_in',
		'set_auth_cookie' => 'capture_auth_cookie', 'set_logged_in_cookie' => 'capture_logged_in_cookie',
		'send_auth_cookies' => 'capture_send_auth_cookies', 'session_token_manager' => 'guard_session_token_manager',
		'woocommerce_create_order' => 'guard_checkout_create_order',
		'woocommerce_resume_order' => 'guard_checkout_resume_order',
		'woocommerce_checkout_create_order' => 'capture_checkout_order',
		'woocommerce_new_order' => 'bind_checkout_order',
		'woocommerce_before_order_object_save' => 'checkout_order_saving',
		'woocommerce_after_order_object_save' => 'checkout_order_saved',
	];
	private const COHORT_HOOKS = [
		'all', 'graphql_process_http_request_response', 'graphql_response_headers_to_send',
		'graphql_authentication_error_status_code', 'graphql_response_set_headers',
		'woocommerce_cart_session_initialize',
		'graphql_mutation_input', 'graphql_pre_mutate_and_get_payload',
		'graphql_mutation_payload', 'graphql_mutation_response',
		'woocommerce_new_customer_data', 'woocommerce_register_post', 'woocommerce_registration_errors',
		'woocommerce_created_customer', 'wp_pre_insert_user_data', 'insert_user_meta', 'insert_custom_user_meta',
		'user_register', 'set_user_role', 'add_user_role', 'added_user_meta', 'updated_user_meta',
		'auth_cookie_expiration', 'secure_auth_cookie', 'secure_logged_in_cookie', 'auth_cookie',
		'set_auth_cookie', 'set_logged_in_cookie', 'send_auth_cookies', 'set_current_user', 'session_token_manager',
		'auth_cookie_valid', 'auth_cookie_malformed', 'auth_cookie_bad_username', 'auth_cookie_bad_hash', 'auth_cookie_bad_session_token', 'auth_cookie_expired',
		'woocommerce_payment_gateways', 'wc_payment_gateways_initialized',
		'woocommerce_create_order', 'woocommerce_resume_order', 'woocommerce_checkout_customer_id',
		'woocommerce_checkout_create_order', 'woocommerce_new_order', 'woocommerce_before_order_object_save', 'woocommerce_after_order_object_save',
		'woocommerce_checkout_order_exception', 'woocommerce_checkout_order_created', 'woocommerce_checkout_update_order_meta',
		'woocommerce_pre_payment_complete', 'woocommerce_valid_order_statuses_for_payment_complete',
		'woocommerce_payment_complete', 'woocommerce_payment_complete_order_status',
		'woocommerce_checkout_order_processed', 'graphql_woocommerce_after_checkout',
		'graphql_woocommerce_before_checkout_meta_save', 'graphql_woocommerce_checkout_payment_result',
		'woocommerce_order_is_paid', 'woocommerce_order_needs_payment', 'woocommerce_valid_order_statuses_for_payment', 'woocommerce_order_is_paid_statuses',
		'woocommerce_order_get_payment_method', 'woocommerce_order_get_status', 'woocommerce_order_get_transaction_id',
		'woocommerce_order_get_total', 'woocommerce_order_get_currency', 'woocommerce_order_get_customer_id', 'woocommerce_order_get_order_key',
		'woocommerce_order_get__woonuxt_deferred_payment', 'woocommerce_order_get__stripe_source_id', 'woocommerce_order_get__stripe_intent_id',
		'added_order_meta', 'updated_order_meta', 'deleted_order_meta', 'woocommerce_data_store_wp_post_read_meta',
		'add_post_metadata', 'update_post_metadata_by_mid', 'delete_post_metadata_by_mid', 'get_post_metadata_by_mid',
		'add_post_meta', 'added_post_meta', 'update_post_meta', 'updated_post_meta', 'update_postmeta', 'updated_postmeta',
		'delete_post_meta', 'deleted_post_meta', 'delete_postmeta', 'deleted_postmeta',
		'get_object_subtype_post', 'sanitize_post_meta__woonuxt_deferred_payment', 'sanitize_post_meta__wl_checkout_operation_uuid',
		'sanitize_post_meta__woonuxt_deferred_payment_for_shop_order', 'sanitize_post_meta__wl_checkout_operation_uuid_for_shop_order',
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
	private $creation_context;
	private $creation_loaders;
	private $cookie_capsule;

	public function __construct( QL_Session_Handler $handler, string $credential_header, array $owned_cookie_names ) {
		$this->handler = $handler;
		$this->sources = defined( 'WOOGRAPHQL_CART_SESSION_SOURCE_COHORT' )
			? WOOGRAPHQL_CART_SESSION_SOURCE_COHORT : array_merge( self::SOURCE_COHORT, self::ORIGIN_SOURCE_COHORT, self::RESPONSE_SOURCE_COHORT, self::CREATION_SOURCE_COHORT, self::DEFERRED_SOURCE_COHORT );
		$this->manifest = defined( 'WOOGRAPHQL_CART_SESSION_CALLBACK_COHORT' )
			? WOOGRAPHQL_CART_SESSION_CALLBACK_COHORT : [];
		$this->boundary = new Cart_Session_HTTP_Boundary( [ $this, 'cleanup' ], [ $credential_header ], $owned_cookie_names );
	}

	private function reject(): never {
		if ( $this->handler->protects_checkout_order() ) { $this->handler->fail_checkout_order(); }
		throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE );
	}

	private function qualified_source( string $class ): void {
		try {
			$expected = is_array( $this->sources ) ? ( $this->sources[ $class ] ?? null ) : null;
			$file = str_starts_with( $class, 'function:' )
				? ( new \ReflectionFunction( substr( $class, 9 ) ) )->getFileName() : ( new \ReflectionClass( $class ) )->getFileName();
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
			// Core sanitize_meta uses has_filter(subtype) to select subtype over generic.
			// An owned specific guard would invent a subtype and skip the real generic callback.
			if ( 'all' !== $hook && ! str_starts_with( $hook, 'sanitize_post_meta_' ) ) { add_filter( $hook, [ $this, 'guard_cohort_entry' ], PHP_INT_MIN, 1 ); }
		}
		add_filter( 'all', [ $this, 'guard_sanitizer_dispatch' ], PHP_INT_MIN, 1 );
		add_filter( 'woocommerce_cart_session_initialize', [ $this, 'capture_cart_session' ], PHP_INT_MAX, 2 );
		add_action( 'do_graphql_request', [ $this, 'guard_request' ], PHP_INT_MIN, 0 );
		add_action( 'wp_loaded', [ $this, 'guard_created_objects' ], PHP_INT_MIN, 0 );
		add_action( 'woocommerce_init', [ $this, 'guard_created_objects' ], PHP_INT_MAX, 0 );
		add_filter( 'graphql_authentication_error_status_code', [ $this, 'capture_auth_error' ], PHP_INT_MAX, 2 );
		foreach ( [ 'woocommerce_new_customer_data' => [ 'guard_checkout_customer_data', 1 ],
			'secure_auth_cookie' => [ 'capture_secure_auth', 2 ], 'secure_logged_in_cookie' => [ 'capture_secure_logged_in', 3 ],
			'set_auth_cookie' => [ 'capture_auth_cookie', 6 ], 'set_logged_in_cookie' => [ 'capture_logged_in_cookie', 6 ],
			'send_auth_cookies' => [ 'capture_send_auth_cookies', 6 ], 'session_token_manager' => [ 'guard_session_token_manager', 1 ],
			'woocommerce_create_order' => [ 'guard_checkout_create_order', 2 ], 'woocommerce_resume_order' => [ 'guard_checkout_resume_order', 1 ],
			'woocommerce_checkout_create_order' => [ 'capture_checkout_order', 2 ], 'woocommerce_new_order' => [ 'bind_checkout_order', 2 ],
			'woocommerce_after_order_object_save' => [ 'checkout_order_saved', 2 ] ] as $hook => [ $method, $arguments ] ) {
			add_filter( $hook, [ $this, $method ], PHP_INT_MAX, $arguments );
		}
		add_action( 'woocommerce_before_order_object_save', [ $this, 'checkout_order_saving' ], PHP_INT_MIN, 2 );
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
		if ( 'all' === $hook && [ $this, 'guard_sanitizer_dispatch' ] === $callback ) {
			return PHP_INT_MIN === $priority && 1 === $arguments;
		}
		if ( 'all' !== $hook && ! str_starts_with( $hook, 'sanitize_post_meta_' ) && [ $this, 'guard_cohort_entry' ] === $callback ) {
			return PHP_INT_MIN === $priority && 1 === $arguments;
		}
		if ( 'woocommerce_before_order_object_save' === $hook && [ $this, 'checkout_order_saving' ] === $callback ) {
			return PHP_INT_MIN === $priority && 2 === $arguments;
		}
		if ( isset( self::OWN_CALLBACKS[ $hook ] ) && [ $this, self::OWN_CALLBACKS[ $hook ] ] === $callback ) {
			$expected = in_array( $hook, [ 'set_auth_cookie', 'set_logged_in_cookie', 'send_auth_cookies' ], true ) ? 6
				: ( 'secure_logged_in_cookie' === $hook ? 3 : ( in_array( $hook, [ 'secure_auth_cookie', 'woocommerce_create_order', 'woocommerce_checkout_create_order', 'woocommerce_new_order', 'woocommerce_after_order_object_save' ], true ) ? 2 : ( 'graphql_process_http_request_response' === $hook ? 6
				: ( in_array( $hook, [ 'graphql_authentication_error_status_code', 'woocommerce_cart_session_initialize' ], true ) ? 2 : 1 ) ) ) );
			return PHP_INT_MAX === $priority && $expected === $arguments;
		}
		if ( 'graphql_response_headers_to_send' === $hook
			&& [ $this->handler, 'add_prepared_session_header' ] === $callback ) {
			$this->qualified_source( QL_Session_Handler::class );
			return 10 === $priority && 1 === $arguments;
		}
		if ( $this->handler->is_cart_operation_callback( $hook, $callback, $priority, $arguments ) ) {
			$this->qualified_source( Cart_Session_Operation::class );
			return true;
		}
		return false;
	}

	/** Explicit installation records pin location, signature, effects and receiver. */
	private function qualified_callback( string $hook, $callback, int $priority, int $arguments ): bool {
		if ( 'woocommerce_before_order_object_save' === $hook && PHP_INT_MIN === $priority ) { return false; }
		foreach ( $this->manifest as $index => $record ) {
			if ( ! is_array( $record ) || ( $record['hook'] ?? null ) !== $hook
				|| ( $record['priority'] ?? null ) !== $priority || ( $record['accepted_args'] ?? null ) !== $arguments
				|| ( 'woocommerce_checkout_order_exception' === $hook && true !== ( $record['preserves_created_order'] ?? null ) )
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
		$hooks = self::COHORT_HOOKS;
		foreach ( array_keys( $GLOBALS['wp_filter'] ?? [] ) as $hook ) {
			if ( is_string( $hook ) && ( str_starts_with( $hook, 'sanitize_post_meta_' ) || str_starts_with( $hook, 'woocommerce_order_get_' ) ) && ! in_array( $hook, $hooks, true ) ) { $hooks[] = $hook; }
		}
		foreach ( $hooks as $hook ) {
			$registry = $GLOBALS['wp_filter'][ $hook ] ?? null;
			$entries = [];
			if ( null !== $registry ) {
				if ( ! $registry instanceof \WP_Hook || \WP_Hook::class !== get_class( $registry ) ) { $this->reject(); }
				foreach ( $registry->callbacks as $priority => $callbacks ) {
					if ( ! is_int( $priority ) ) { $this->reject(); }
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
		if ( null === $this->frozen ) {
			$this->qualified_source( 'WC_Payment_Gateways' );
			// Native gateway constructors register callbacks; load them before freezing the registry.
			$gateways = \WC_Payment_Gateways::instance();
			if ( ! $gateways instanceof \WC_Payment_Gateways || 'WC_Payment_Gateways' !== get_class( $gateways ) ) { $this->reject(); }
			$this->arm_terminals();
			$this->cohort( true );
		}
		if ( $this->handler->has_owned_scope() ) { $this->assert_objects(); }
	}

	/** The participating mutation hooks must already be frozen before any input. */
	public function assert_checkout_origin_boundary(): void {
		if ( null === $this->frozen || $this->terminal ) { $this->reject(); }
		$this->cohort( false );
		foreach ( [ Cart_Session_Operation::class, \WPGraphQL\WooCommerce\Mutation\Checkout::class,
			\WPGraphQL\WooCommerce\Data\Mutation\Checkout_Mutation::class,
			\WPGraphQL\Type\WPMutationType::class, \WPGraphQL\Utils\InstrumentSchema::class ] as $class ) {
			$this->qualified_source( $class );
		}
		$registry = $GLOBALS['wp_filter']['graphql_pre_mutate_and_get_payload'] ?? null;
		$entries = $registry instanceof \WP_Hook ? ( $registry->callbacks[ PHP_INT_MAX ] ?? [] ) : [];
		$last = $entries ? end( $entries ) : null;
		if ( ! is_array( $last ) || ! $this->handler->is_cart_operation_callback(
			'graphql_pre_mutate_and_get_payload', $last['function'] ?? null, PHP_INT_MAX, $last['accepted_args'] ?? null ) ) { $this->reject(); }
	}

	/** Reject a changed specific-hook cohort before its non-owner callbacks run. */
	public function guard_cohort_entry( $value ) {
		$this->cohort( false );
		return $value;
	}

	/** Native all dispatch precedes sanitizer callbacks without changing has_filter. */
	public function guard_sanitizer_dispatch( $hook ): void {
		if ( is_string( $hook ) && str_starts_with( $hook, 'sanitize_post_meta_' ) ) {
			$this->cohort( false );
		}
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

	/** No pending loader work may retain the guest across the identity replacement. */
	private function checked_checkout_loaders( $context ): array {
		if ( ! $context instanceof \WPGraphQL\AppContext || get_class( $context ) !== \WPGraphQL\AppContext::class ) { $this->reject(); }
		$this->qualified_source( \WPGraphQL\AppContext::class );
		$this->qualified_source( \WPGraphQL\Data\Loader\AbstractDataLoader::class );
		$loaders = $context->loaders;
		if ( ! is_array( $loaders ) ) { $this->reject(); }
		$buffer = new \ReflectionProperty( \WPGraphQL\Data\Loader\AbstractDataLoader::class, 'buffer' );
		foreach ( $loaders as $loader ) {
			if ( ! $loader instanceof \WPGraphQL\Data\Loader\AbstractDataLoader || [] !== $buffer->getValue( $loader ) ) { $this->reject(); }
			$this->qualified_source( get_class( $loader ) );
		}
		return $loaders;
	}

	public function qualify_checkout_creation( $context ): void {
		$this->assert_objects(); $this->assert_checkout_origin_boundary();
		if ( null !== $this->creation_context || ! function_exists( 'is_multisite' ) || is_multisite() ) { $this->reject(); }
		foreach ( [ 'wc_create_new_customer', 'wp_insert_user', 'wc_set_customer_auth_cookie', 'wp_set_current_user',
			'wp_set_auth_cookie', 'wp_generate_auth_cookie', 'wp_validate_auth_cookie' ] as $function ) { $this->qualified_source( 'function:' . $function ); }
		foreach ( [ 'WP_User', 'WP_Session_Tokens', 'WP_User_Meta_Session_Tokens', 'WC_Checkout', 'WC_Order', 'WC_Abstract_Order', 'WC_Data_Store', 'WC_Order_Data_Store_CPT', 'WC_Data', 'WC_Meta_Data', 'WC_Data_Store_WP', 'function:add_metadata', 'function:update_metadata_by_mid', 'function:delete_metadata_by_mid' ] as $class ) { $this->qualified_source( $class ); }
		$this->creation_loaders = $this->checked_checkout_loaders( $context ); $this->creation_context = $context;
	}

	public function guard_checkout_customer_data( $data ) {
		$this->require_tail( 'woocommerce_new_customer_data', 'guard_checkout_customer_data' );
		if ( ! $this->handler->has_checkout_creation() ) { return $data; }
		if ( ! is_array( $data ) || 'customer' !== ( $data['role'] ?? null )
			|| array_diff( array_keys( $data ), [ 'first_name', 'last_name', 'source', 'user_login', 'user_pass', 'user_email', 'role' ] ) ) { $this->reject(); }
		foreach ( $data as $value ) { if ( ! is_string( $value ) ) { $this->reject(); } }
		return $data;
	}

	public function arm_checkout_cookie_capsule( int $user_id ): void {
		$this->cohort( false );
		if ( null !== $this->cookie_capsule || null === $this->creation_context || $user_id <= 0 ) { $this->reject(); }
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User || [ 'customer' ] !== array_values( $user->roles ) || [ 'customer' => true ] !== $user->caps ) { $this->reject(); }
		$this->cookie_capsule = (object) [ 'user_id' => $user_id, 'secure_auth' => null, 'secure_logged_in' => null,
			'auth' => null, 'logged_in' => null, 'send' => null, 'consumed' => false ];
	}

	public function guard_session_token_manager( $class ) {
		$this->require_tail( 'session_token_manager', 'guard_session_token_manager' );
		if ( $this->handler->has_checkout_creation() && 'WP_User_Meta_Session_Tokens' !== $class ) { $this->reject(); }
		return $class;
	}

	public function capture_secure_auth( $secure, $id ) {
		$this->require_tail( 'secure_auth_cookie', 'capture_secure_auth' );
		if ( ! $this->cookie_capsule ) { return $secure; }
		if ( $id !== $this->cookie_capsule->user_id || ! is_bool( $secure ) || null !== $this->cookie_capsule->secure_auth ) { $this->reject(); }
		$this->cookie_capsule->secure_auth = $secure; return $secure;
	}
	public function capture_secure_logged_in( $secure, $id, $auth_secure ) {
		$this->require_tail( 'secure_logged_in_cookie', 'capture_secure_logged_in' );
		if ( ! $this->cookie_capsule ) { return $secure; }
		if ( $id !== $this->cookie_capsule->user_id || ! is_bool( $secure ) || null !== $this->cookie_capsule->secure_logged_in
			|| $auth_secure !== $this->cookie_capsule->secure_auth ) { $this->reject(); }
		$this->cookie_capsule->secure_logged_in = $secure; return $secure;
	}
	private function capture_cookie( string $slot, $cookie, $expire, $expiration, $id, $scheme, $token ): void {
		$c = $this->cookie_capsule;
		if ( ! $c ) { return; }
		if ( $c->consumed || null !== $c->$slot || $id !== $c->user_id || ! is_string( $cookie ) || '' === $cookie
			|| preg_match( '/[\r\n\x00]/', $cookie ) || ! is_int( $expire ) || ! is_int( $expiration )
			|| $expiration <= time() || $expire <= $expiration || ! is_string( $token ) || '' === $token
			|| ( 'auth' === $slot ? ( $c->secure_auth ? 'secure_auth' : 'auth' ) : 'logged_in' ) !== $scheme ) { $this->reject(); }
		$c->$slot = [ $cookie, $expire, $expiration, $id, $scheme, $token ];
		if ( 'logged_in' === $slot && ( ! $c->auth || array_slice( $c->auth, 1, 3 ) !== [ $expire, $expiration, $id ] || $c->auth[5] !== $token ) ) { $this->reject(); }
	}
	public function capture_auth_cookie( $cookie, $expire, $expiration, $id, $scheme, $token ): void {
		$this->require_tail( 'set_auth_cookie', 'capture_auth_cookie' ); $this->capture_cookie( 'auth', $cookie, $expire, $expiration, $id, $scheme, $token );
	}
	public function capture_logged_in_cookie( $cookie, $expire, $expiration, $id, $scheme, $token ): void {
		$this->require_tail( 'set_logged_in_cookie', 'capture_logged_in_cookie' ); $this->capture_cookie( 'logged_in', $cookie, $expire, $expiration, $id, $scheme, $token );
	}
	public function capture_send_auth_cookies( $send, $expire = null, $expiration = null, $id = null, $scheme = null, $token = null ) {
		$this->require_tail( 'send_auth_cookies', 'capture_send_auth_cookies' );
		$c = $this->cookie_capsule; if ( ! $c ) { return $send; }
		if ( 6 !== func_num_args() || null !== $c->send || ! is_bool( $send ) || ! $c->auth || ! $c->logged_in
			|| array_slice( $c->auth, 1 ) !== [ $expire, $expiration, $id, $scheme, $token ] ) { $this->reject(); }
		$c->send = $send; return false;
	}
	public function consume_checkout_cookie_capsule(): bool {
		$this->cohort( false ); $c = $this->cookie_capsule;
		if ( ! $c || $c->consumed || true !== $c->send || ! is_bool( $c->secure_auth ) || ! is_bool( $c->secure_logged_in ) ) { $this->reject(); }
		if ( $c->user_id !== wp_validate_auth_cookie( $c->auth[0], $c->auth[4] )
			|| $c->user_id !== wp_validate_auth_cookie( $c->logged_in[0], 'logged_in' ) ) { $this->reject(); }
		$this->cohort( false );
		$c->consumed = true; return true;
	}
	public function adopt_checkout_customer( $context ): void {
		if ( $context !== $this->creation_context || $this->checked_checkout_loaders( $context ) !== $this->creation_loaders
			|| ! $this->cookie_capsule || ! $this->cookie_capsule->consumed ) { $this->reject(); }
		$this->cohort( false );
		$customer = new \WC_Customer( $this->cookie_capsule->user_id, true );
		$this->copy_checkout_customer_addresses( $this->customer, $customer );
		if ( $customer->get_id() !== $this->cookie_capsule->user_id ) { $this->reject(); }
		\WC()->customer = $customer; $this->customer = $customer;
		$context->viewer = wp_get_current_user();
		foreach ( $this->creation_loaders as $loader ) { $loader->clear_all(); }
		$this->assert_objects();
	}
	/** Only checkout addresses survive the guest/account session identity boundary. */
	private function copy_checkout_customer_addresses( \WC_Customer $guest, \WC_Customer $customer ): void {
		// Native session read intentionally rejects the old guest ID. Carry only the
		// validated address values on the captured guest customer, never its identity.
		$addresses = [ 'billing' => $guest->get_billing( 'edit' ), 'shipping' => $guest->get_shipping( 'edit' ) ];
		foreach ( $addresses as $type => $address ) {
			foreach ( [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' ] as $field ) {
				$setter = "set_{$type}_{$field}";
				if ( array_key_exists( $field, $address ) && is_callable( [ $customer, $setter ] ) ) { $customer->$setter( $address[ $field ] ); }
			}
		}
	}

	public function guard_checkout_create_order( $id, $checkout ) {
		$this->require_tail( 'woocommerce_create_order', 'guard_checkout_create_order' );
		if ( $this->handler->protects_checkout_order() && ( null !== $id || $checkout !== \WC()->checkout ) ) { $this->reject(); }
		return $id;
	}
	public function guard_checkout_resume_order( $id ): void {
		$this->require_tail( 'woocommerce_resume_order', 'guard_checkout_resume_order' );
		if ( $this->handler->protects_checkout_order() ) { $this->reject(); }
	}
	public function capture_checkout_order( $order, $data ): void {
		$this->require_tail( 'woocommerce_checkout_create_order', 'capture_checkout_order' );
		if ( $this->handler->protects_checkout_order() ) {
			$store = $order instanceof \WC_Order ? $order->get_data_store() : null;
			if ( ! $store instanceof \WC_Data_Store || get_class( $store ) !== 'WC_Data_Store' || 'WC_Order_Data_Store_CPT' !== $store->get_current_class_name() ) { $this->reject(); }
			$this->qualified_source( 'WC_Data_Store' ); $this->qualified_source( 'WC_Order_Data_Store_CPT' );
		}
		$this->handler->capture_checkout_order( $order, $data );
	}
	public function bind_checkout_order( $id, $order ): void {
		$this->require_tail( 'woocommerce_new_order', 'bind_checkout_order' ); $this->handler->bind_checkout_order( $id, $order );
	}
	public function checkout_order_saving( $order, $store ): void {
		$this->cohort( false ); $this->handler->checkout_order_saving( $order, $store );
	}
	public function checkout_order_saved( $order, $store ): void {
		$this->require_tail( 'woocommerce_after_order_object_save', 'checkout_order_saved' ); $this->handler->checkout_order_saved( $order, $store );
	}
	/** Exact Settings writer, explicit fourth receiver argument, frozen ordered registry. */
	public function qualify_checkout_deferred_payment(): void {
		$this->assert_objects(); $this->cohort( false );
		foreach ( self::DEFERRED_SOURCE_COHORT as $class => $unused ) { $this->qualified_source( $class ); }
		$this->qualified_source( 'function:woonuxt_defer_stripe_checkout' );
		$registry = $GLOBALS['wp_filter']['graphql_woocommerce_checkout_payment_result'] ?? null;
		$writers = [];
		if ( $registry instanceof \WP_Hook ) {
			foreach ( $registry->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $entry ) {
					if ( [ $this, 'guard_cohort_entry' ] === ( $entry['function'] ?? null ) ) { continue; }
					$writers[] = [ $priority, $entry['function'] ?? null, $entry['accepted_args'] ?? null ];
				}
			}
		}
		if ( [ [ 10, 'woonuxt_defer_stripe_checkout', 4 ] ] !== $writers ) { $this->reject(); }
		$this->cohort( true );
	}
	public function assert_checkout_deferred_cohort(): void {
		$this->assert_objects(); $this->cohort( false );
		foreach ( self::DEFERRED_SOURCE_COHORT as $class => $unused ) { $this->qualified_source( $class ); }
		$this->qualified_source( 'function:woonuxt_defer_stripe_checkout' );
	}

	public function flush_empty_checkout_cart(): void {
		$this->assert_objects();
		if ( ! $this->handler->protects_checkout_order() || ! $this->cart->is_empty() ) { $this->reject(); }
		$this->cart_session->destroy_cart_session(); $this->assert_objects();
	}
	private function checkout_cookie_batch(): array {
		$c = $this->cookie_capsule;
		if ( ! $c || ! $c->consumed || true !== $c->send ) { $this->reject(); }
		foreach ( [ 'AUTH_COOKIE', 'SECURE_AUTH_COOKIE', 'LOGGED_IN_COOKIE', 'PLUGINS_COOKIE_PATH', 'ADMIN_COOKIE_PATH', 'COOKIEPATH', 'SITECOOKIEPATH', 'COOKIE_DOMAIN' ] as $constant ) {
			if ( ! defined( $constant ) || ! is_string( constant( $constant ) ) ) { $this->reject(); }
		}
		$auth_name = $c->secure_auth ? SECURE_AUTH_COOKIE : AUTH_COOKIE;
		$batch = [ [ $auth_name, $c->auth[0], $c->auth[1], PLUGINS_COOKIE_PATH, COOKIE_DOMAIN, $c->secure_auth, true ],
			[ $auth_name, $c->auth[0], $c->auth[1], ADMIN_COOKIE_PATH, COOKIE_DOMAIN, $c->secure_auth, true ],
			[ LOGGED_IN_COOKIE, $c->logged_in[0], $c->logged_in[1], COOKIEPATH, COOKIE_DOMAIN, $c->secure_logged_in, true ] ];
		if ( COOKIEPATH !== SITECOOKIEPATH ) { $batch[] = [ LOGGED_IN_COOKIE, $c->logged_in[0], $c->logged_in[1], SITECOOKIEPATH, COOKIE_DOMAIN, $c->secure_logged_in, true ]; }
		return $batch;
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

	/** Reject objects in formatted errors before native JSON can invoke callbacks. */
	private function assert_plain_error_value( $value, int $depth = 0 ): void {
		if ( $depth > 32 || ( ! is_array( $value ) && ! is_scalar( $value ) && null !== $value ) ) { $this->reject(); }
		if ( is_array( $value ) ) {
			foreach ( $value as $child ) { $this->assert_plain_error_value( $child, $depth + 1 ); }
		}
	}

	/** Native HTTP keeps ExecutionResult; Router's thrown-error catch uses arrays. */
	private function rejected_response_errors( $response ): array {
		if ( is_object( $response ) ) {
			if ( get_class( $response ) !== \GraphQL\Executor\ExecutionResult::class ) { $this->reject(); }
			$this->qualified_source( \GraphQL\Executor\ExecutionResult::class );
			try {
				// The native jsonSerialize path runs once before terminal cleanup.
				// Intentional auth detachment already fenced/released its old grant;
				// response health/cohort still apply and old cart access stays closed.
				// Only errors survive; data/extensions are never serialized.
				$response = $response->toArray();
			} catch ( \Throwable $error ) { $this->reject(); }
			$this->handler->assert_response_available();
			$this->require_tail( 'graphql_process_http_request_response', 'send_response' );
		}
		if ( ! is_array( $response ) || empty( $response['errors'] ) || ! is_array( $response['errors'] )
			|| ! array_is_list( $response['errors'] ) ) { $this->reject(); }
		foreach ( $response['errors'] as $error ) {
			if ( ! is_array( $error ) || ! isset( $error['message'] ) || ! is_string( $error['message'] ) ) { $this->reject(); }
		}
		$this->assert_plain_error_value( $response['errors'] );
		return $response['errors'];
	}

	/** Final response serialization stays inside ownership; raw emit follows release. */
	public function send_response( $response, $deprecated = null, $operation_name = null, $query = null, $variables = null, $status = null ): never {
		$this->require_tail( 'graphql_process_http_request_response', 'send_response' );
		if ( $this->terminal || null !== $this->auth_error || ! is_int( $status ) ) { $this->reject(); }
		$this->handler->assert_response_available();
		if ( $this->handler->has_session_rejection() ) {
			// Discard healthy but rejected identity work without publishing partial
			// data or any token already serialized by an earlier sibling field.
			$this->boundary->discard( [ 'errors' => $this->rejected_response_errors( $response ) ], $status );
		}
		if ( $this->handler->has_checkout_creation() ) {
			$plain = $response;
			if ( is_object( $plain ) ) {
				if ( get_class( $plain ) !== \GraphQL\Executor\ExecutionResult::class ) { $this->reject(); }
				$this->qualified_source( \GraphQL\Executor\ExecutionResult::class );
				try { $plain = $plain->toArray(); } catch ( \Throwable $error ) { $this->reject(); }
				$this->handler->assert_response_available();
				$this->require_tail( 'graphql_process_http_request_response', 'send_response' );
			}
			if ( ! is_array( $plain ) || ! empty( $plain['errors'] ) ) { $this->reject(); }
			$response = $plain;
		}
		$this->boundary->complete( $response, function () {
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
			return $this->handler->has_checkout_creation() ? $this->checkout_cookie_batch() : null;
		}, $status );
	}
}

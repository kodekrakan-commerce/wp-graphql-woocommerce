<?php
/**
 * Actual handler/operation/lifecycle/HTTP + native WP_Hook + graphql-php Executor,
 * InstrumentSchema/WPMutationType. WC cart/customer, SQL/cache/auth and Router
 * are explicitly controlled. This file and shared adapter are pinned by runner.
 * No site bootstrap, native SQL, durable orders, payment or installed-state proof.
 */
namespace WPGraphQL\WooCommerce\Data\Mutation {
	final class Checkout_Mutation { public static function is_registration_required() { return false; } }
}
namespace {
	error_reporting( E_ALL ); ini_set( 'display_errors', '0' ); ini_set( 'log_errors', '0' );
	function integration_translate( $text ) { $GLOBALS['integration_translations']++; if ( $GLOBALS['integration_forbid_translation'] ) { throw new \RuntimeException( 'Synthetic translation boundary forbidden.' ); } return $text; }
	function __( $text, $domain = null ) { return integration_translate( $text ); }
	function esc_html( $value ) { return $value; }
	function integration_event( $event ) { HandlerContractBoundary::event( $event ); }
	function integration_trusted_input( $input, $context, $info, $name ) {
		$case=$GLOBALS['integration_current_case'];$db=$GLOBALS['integration_db'];
		if ( 'checkout' === $name ) { $input['account'] = [ 'username' => 'synthetic' ]; integration_event( 'trusted.checkout.input' ); }
		if ( 'Login' === $name ) {
			$input['provider'] = 'sitetoken'; integration_event( 'trusted.login.input' );
			if ( 'detached-unavailable-dominates' === $case ) { $db->report_failed = true; $GLOBALS['integration_forbid_translation'] = true; $GLOBALS['integration_translations'] = 0; }
		}
		return $input;
	}
	final class WC_Customer {
		public function __construct() { add_action( 'shutdown', [ $this, 'save' ], 10, 0 ); }
		public function get_id() { return HandlerContractBoundary::$user; }
		public function save() {
			integration_event( 'customer.save' );
			// Native guest customer session datastore delegates its save to set().
			WC()->session->set( 'customer', [ 'synthetic' => true ] );
			if ( $GLOBALS['integration_marker_case'] ?? false ) {
				$GLOBALS['integration_customer_save_inert'] = 'unchanged' === WC()->session->get( 'customer', 'unchanged' );
			}
		}
	}
	final class WC_Cart {
		public $session;
		public function __construct() { $this->session = new WC_Cart_Session( $this ); add_action( 'woocommerce_add_to_cart', [ $this, 'calculate_totals' ], 20, 0 ); }
		public function calculate_totals() { integration_event( 'cart.calculate' ); }
	}
	final class WC_Cart_Session {
		protected $cart;
		public function __construct( $cart ) {
			$this->cart = $cart;
			$GLOBALS['integration_creation_order'] = null === WC()->cart && null !== WC()->customer && false !== has_action( 'shutdown', [ WC()->customer, 'save' ] );
			$enabled = apply_filters( 'woocommerce_cart_session_initialize', true, $this );
			$GLOBALS['integration_cart_session_enabled'] = $enabled;
			if ( ! $enabled ) { return; }
			add_action( 'shutdown', [ $this, 'maybe_set_cart_cookies' ], 20, 0 );
			add_action( 'woocommerce_after_calculate_totals', [ $this, 'set_session' ], 10, 0 );
		}
		public function set_session() { integration_event( 'cart.set_session' ); WC()->session->set( 'cart', 'synthetic-final-cart' ); }
		public function persistent_cart_update() { integration_event( 'cart.persistent' ); }
		public function maybe_set_cart_cookies() {
			integration_event( 'cart.cookies' ); header( 'Set-Cookie: woocommerce_cart_hash=synthetic; Path=/', false );
			// Controlled cart/cookie implementation, genuine native hook dispatcher:
			// actual WC_Cart_Session::set_cart_cookies() ends with this action.
			do_action( 'woocommerce_set_cart_cookies', true );
		}
	}
	$owner = dirname( __DIR__, 2 );
	$wp = rtrim( getenv( 'WL_WORDPRESS_SOURCE' ) ?: '', '/' );
	$wc = rtrim( getenv( 'WL_WOOCOMMERCE_SOURCE' ) ?: '', '/' );
	$gql = rtrim( getenv( 'WL_WPGRAPHQL_SOURCE' ) ?: '', '/' );
	$mu = rtrim( getenv( 'WL_MU_PLUGINS_SOURCE' ) ?: '', '/' );
	define( 'WOOGRAPHQL_ACTUAL_LIFECYCLE_CONTRACT', true );
	require $wp . '/wp-includes/plugin.php';
	require $mu . '/database/interface-owned-scope-driver.php';
	require $mu . '/database/class-owned-scope-error.php';
	require __DIR__ . '/cart-session-owned-handler-fixtures.php';
	define( 'ABSPATH', __DIR__ . '/' ); define( 'COOKIEHASH', 'synthetic-owned-contract' ); define( 'DB_NAME', 'offline_synthetic_contract' );
	define( 'WC_SESSION_CACHE_GROUP', 'woocommerce_sessions' ); define( 'MINUTE_IN_SECONDS', 60 );
	define( 'GRAPHQL_WOOCOMMERCE_SECRET_KEY', str_repeat( 'k', 32 ) );
	foreach ( [ '/vendor/autoload.php', '/includes/abstracts/abstract-wc-session.php', '/includes/class-wc-session-handler.php', '/src/Caching/CacheNameSpaceTrait.php', '/includes/class-wc-cache-helper.php' ] as $file ) { require $wc . $file; }
	require $gql . '/vendor/autoload.php';
	require $gql . '/src/AppContext.php';
	require $gql . '/src/Utils/InstrumentSchema.php';
	require $gql . '/src/Type/WPMutationType.php';
	require $owner . '/vendor/autoload.php';
	foreach ( [ 'class-cart-session-error.php', 'class-cart-session-operation.php', 'class-cart-session-storage.php', 'class-session-transaction-manager.php', 'class-cart-session-http-boundary.php', 'class-cart-session-lifecycle.php', 'class-ql-session-handler.php' ] as $file ) { require $owner . '/includes/utils/' . $file; }

	use WPGraphQL\WooCommerce\Utils\QL_Session_Handler;
	use WPGraphQL\WooCommerce\Vendor\Firebase\JWT\JWT;
	use GraphQL\Utils\BuildSchema;
	use GraphQL\GraphQL;
	use GraphQL\Type\Definition\ObjectType;
	use WPGraphQL\Utils\InstrumentSchema;
	final class Integration_Mutation extends \WPGraphQL\Type\WPMutationType {
		/** Only the WP type registry/constructor boundary is bypassed. Real resolver runs. */
		public function __construct( $name, $callback ) { $this->mutation_name = 'login' === $name ? 'Login' : $name; $this->config = [ 'mutateAndGetPayload' => $callback ]; }
		public function resolver() { return $this->get_resolver(); }
	}
	$case = 'cli' === PHP_SAPI ? ( $argv[1] ?? '' ) : ( $_GET['case'] ?? '' );
	$native_result_case = str_starts_with( $case, 'native-rejection' );
	$ledger = 'cli' === PHP_SAPI ? ( $argv[2] ?? '' ) : getenv( 'WL_INTEGRATION_HTTP_LEDGER' );
	$GLOBALS['integration_translations'] = 0; $GLOBALS['integration_forbid_translation'] = false;
	$GLOBALS['integration_creation_order'] = false;
	$GLOBALS['integration_format_calls'] = 0; $GLOBALS['integration_format_owned'] = false;
	$GLOBALS['integration_discarded_serialize_calls'] = 0;
	HandlerContractBoundary::$user = 0; HandlerContractBoundary::$graphql = true;
	HandlerContractBoundary::$cache = [ WC_SESSION_CACHE_GROUP . ':wc_' . WC_SESSION_CACHE_GROUP . '_cache_prefix' => 'fixed-prefix' ];
	HandlerContractBoundary::$woocommerce = (object) [ 'session' => null, 'customer' => null, 'cart' => null ];
	$GLOBALS['wpdb'] = $db = new HandlerContractDatabase(); $_SERVER['REQUEST_METHOD'] = 'POST';
	$id = str_repeat( 'a', 32 ); HandlerContractBoundary::$rows[ $id ] = [ 'cart' => 'authoritative-synthetic' ];
	$marker_case = 1 === preg_match( '/\Amarker-(retirement|creation)-(present|missing|replacement|uncertain)\z/D', $case, $marker_parts );
	$GLOBALS['integration_marker_case'] = $marker_case;
	if ( $marker_case ) {
		$tuple = ''; foreach ( [ DB_NAME, 'contract_woocommerce_sessions', $id ] as $part ) { $tuple .= strlen( $part ) . ':' . $part; } $hash = hash( 'sha256', $tuple );
		$value = 'retirement' === $marker_parts[1]
			? [ 'schema'=>1, 'kind'=>'checkout_guest_retirement', 'source_tuple_sha256'=>$hash, 'destination_tuple_sha256'=>str_repeat('b',64), 'operation_uuid'=>'11111111-1111-4111-8111-111111111111' ]
			: [ 'schema'=>1, 'kind'=>'checkout_creation_attempt', 'source_tuple_sha256'=>$hash, 'operation_uuid'=>'11111111-1111-4111-8111-111111111111' ];
		HandlerContractBoundary::$markers[ ( 'retirement' === $marker_parts[1] ? 'wl_cart_retired_v1_' : 'wl_checkout_creation_v1_' ) . $hash ] = json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
		if ( 'missing' === $marker_parts[2] ) { unset( HandlerContractBoundary::$rows[$id] ); }
	}
	$original_row = HandlerContractBoundary::$rows[$id] ?? null; $original_markers = HandlerContractBoundary::$markers;
	$token = JWT::encode( [ 'iss' => get_bloginfo( 'url' ), 'iat' => time()-2, 'nbf' => time()-2, 'exp' => time()+172800, 'data' => [ 'customer_id' => $id ] ], GRAPHQL_WOOCOMMERCE_SECRET_KEY, 'HS256' );
	$_SERVER['HTTP_WOOCOMMERCE_SESSION'] = 'Session ' . $token;
	$fixture_hash = getenv( 'WL_INTEGRATION_FIXTURE_SHA' );
	define( 'WOOGRAPHQL_CART_SESSION_SOURCE_COHORT', [
		'WP_Hook' => 'b839c0e5672246bca8db1ab781ec8835f7732f253c375a237cbf6ec536e8d12e',
		'WPGraphQL\\Router' => getenv( 'WL_INTEGRATION_ADAPTER_SHA' ),
		'WC_Customer' => $fixture_hash, 'WC_Cart' => $fixture_hash, 'WC_Cart_Session' => $fixture_hash,
		QL_Session_Handler::class => getenv( 'WL_INTEGRATION_HANDLER_SHA' ),
		\WPGraphQL\WooCommerce\Utils\Cart_Session_Operation::class => getenv( 'WL_INTEGRATION_OPERATION_SHA' ),
		\GraphQL\Executor\ExecutionResult::class => 'native-rejection-source' === $case ? str_repeat( '0', 64 ) : getenv( 'WL_INTEGRATION_RESULT_SHA' ),
	] );
	define( 'WOOGRAPHQL_CART_SESSION_CALLBACK_COHORT', [[ 'hook'=>'graphql_mutation_input','kind'=>'function','function'=>'integration_trusted_input','priority'=>10,'accepted_args'=>4,'stable_registry'=>true,'nonstreaming'=>true,'sha256'=>$fixture_hash ]] );
	$handler = new QL_Session_Handler(); WC()->session = $handler;
	register_shutdown_function( static function () use ( $ledger, $handler, $db, $id, $original_row, $original_markers ) {
		do_action( 'shutdown' );
		$state = [ 'creation_order' => $GLOBALS['integration_creation_order'], 'rejection' => $handler->has_session_rejection(), 'detached' => $handler->is_auth_detached(), 'terminal' => $handler->get_owned_lifecycle()->is_terminal(),
			'events' => array_column( HandlerContractBoundary::$events, 'kind' ), 'writes' => $db->writes, 'calls' => $db->calls, 'row_preserved' => $original_row === ( HandlerContractBoundary::$rows[ $id ] ?? null ),
			'markers_preserved' => $original_markers === HandlerContractBoundary::$markers, 'marker_reads' => count($db->marker_reads), 'session_reads' => $db->reads,
			'owned_scope' => $handler->has_owned_scope(), 'cart_session_enabled' => $GLOBALS['integration_cart_session_enabled'] ?? null,
			'replacement_driver_untouched' => ( $GLOBALS['wpdb'] ?? null ) === $db || [] === $GLOBALS['wpdb']->calls,
			'customer_save_inert' => $GLOBALS['integration_customer_save_inert'] ?? null, 'request_rejection_code' => $GLOBALS['integration_request_rejection_code'] ?? null,
			'request_status' => $GLOBALS['integration_request_status'] ?? null, 'emitted_status' => http_response_code(),
			'translations' => $GLOBALS['integration_translations'], 'partial_data_before_terminal' => $GLOBALS['integration_partial'] ?? false, 'partial_token_before_terminal' => $GLOBALS['integration_partial_token'] ?? false,
			'cookie_registry_stable' => $GLOBALS['integration_cookie_registry_stable'] ?? null,
			'result_class' => $GLOBALS['integration_result_class'] ?? null,
			'format_calls' => $GLOBALS['integration_format_calls'], 'format_owned' => $GLOBALS['integration_format_owned'],
			'discarded_serialize_calls' => $GLOBALS['integration_discarded_serialize_calls'],
			'cookie_issuance_preserved' => $GLOBALS['integration_cookie_issuance_preserved'] ?? null,
			'cookie_token_policy' => $GLOBALS['integration_cookie_token_policy'] ?? null ];
		file_put_contents( $ledger, json_encode( $state ), LOCK_EX ); chmod( $ledger, 0600 );
	} );
	$handler->init(); if ( ! $marker_case ) { $handler->assert_session_ready(); }
	WC()->customer = new WC_Customer(); $cart = new WC_Cart(); WC()->cart = $cart;
	$handler->set( 'cart', 'synthetic-pending-cart' );
	if ( $marker_case && 'replacement' === $marker_parts[2] ) { $GLOBALS['wpdb'] = new HandlerContractDatabase(); }
	if ( $marker_case && 'uncertain' === $marker_parts[2] ) { $db->report_failed = true; }
	$GLOBALS['integration_current_case']=$case;$GLOBALS['integration_db']=$db;
	if ( $native_result_case || in_array( $case, [ 'later-filtered-input', 'detached-input-rejection', 'detached-unavailable-dominates' ], true ) ) { add_filter('graphql_mutation_input','integration_trusted_input',10,4); }
	$status = 200;
	try { do_action( 'do_graphql_request' ); }
	catch ( \WPGraphQL\WooCommerce\Utils\Cart_Session_Error $error ) {
		if ( ! $marker_case || ['code'=>'WL_CART_SESSION_INVALID'] !== $error->getExtensions() ) { throw $error; }
		// Request's surrounding catch is a controlled boundary. Also exercise the
		// genuine Executor field guard independently with the same rejected handler.
		// Actual WPGraphQL Router::process_http_request catch sets status 500 for
		// this thrown request error. Native installed transport remains pending.
		$status = 500;
		$GLOBALS['integration_request_status'] = $status;
		$GLOBALS['integration_request_rejection_code'] = 'WL_CART_SESSION_INVALID';
	}
	$schema = BuildSchema::build( <<<'SDL'
enum Provider { PASSWORD SITETOKEN }
input LoginInput { provider: Provider! }
input EmptyInput { clientMutationId: String }
input AccountInput { username: String }
input CheckoutInput { account: AccountInput }
type Customer { sessionToken: String }
type LoginPayload { authToken: String }
type CartPayload { success: Boolean, customer: Customer }
type Query { ok: Boolean }
type Mutation { login(input: LoginInput!): LoginPayload, addToCart(input: EmptyInput!): CartPayload, checkout(input: CheckoutInput!): CartPayload }
SDL
	);
	$schema->getType( 'Provider' )->getValue( 'PASSWORD' )->value = 'password';
	$schema->getType( 'Provider' )->getValue( 'SITETOKEN' )->value = 'sitetoken';
	foreach ( $schema->getMutationType()->getFields() as $name => $field ) {
		$mutation = new Integration_Mutation( $name, static function ( $input ) use ( $name, $handler, $case ) {
			integration_event( 'callback.' . $name );
			if ( 'login' === $name ) { HandlerContractBoundary::$user = 23; return [ 'id' => 23, 'user' => (object) [ 'ID' => 23 ], 'authToken' => 'synthetic-auth-token' ]; }
			if ( 'existing-token-cart-cookie' === $case ) {
				$registry = $GLOBALS['wp_filter']['graphql_response_headers_to_send']; $before = $registry->callbacks;
				$issued = new ReflectionProperty( $handler, '_issuing_new_token' );
				$expiration = new ReflectionProperty( $handler, '_session_expiration' );
				$timestamp = new ReflectionProperty( $handler, '_session_issued' );
				$claims_before = [ $timestamp->getValue( $handler ), $expiration->getValue( $handler ) ];
				$unissued = false === (bool) $issued->getValue( $handler );
				$no_header_before = ! isset( apply_filters( 'graphql_response_headers_to_send', [] )['woocommerce-session'] );
				WC()->cart->session->maybe_set_cart_cookies();
				$headers = apply_filters( 'graphql_response_headers_to_send', [] );
				$GLOBALS['integration_cookie_registry_stable'] = $registry === $GLOBALS['wp_filter']['graphql_response_headers_to_send'] && $before === $registry->callbacks;
				$GLOBALS['integration_cookie_issuance_preserved'] = $unissued && true === (bool) $issued->getValue( $handler ) && $claims_before === [ $timestamp->getValue( $handler ), $expiration->getValue( $handler ) ];
				$GLOBALS['integration_cookie_token_policy'] = $no_header_before && is_string( $headers['woocommerce-session'] ?? null ) && $headers['woocommerce-session'] === $handler->build_token();
			}
			$handler->set( 'cart', 'synthetic-callback-cart' ); return [ 'success' => true, 'customer' => [] ];
		} );
		$field->resolveFn = $mutation->resolver();
	}
	$schema->getType( 'Customer' )->getField( 'sessionToken' )->resolveFn = static fn() => $handler->build_customer_token();
	foreach ( $schema->getTypeMap() as $type ) { if ( $type instanceof ObjectType && 0 !== strpos( $type->name, '__' ) ) { InstrumentSchema::instrument_resolvers( $type, $type->name ); } }
	if ( 'unavailable-dominates' === $case ) { $db->report_failed = true; $GLOBALS['integration_forbid_translation'] = true; $GLOBALS['integration_translations'] = 0; }
	$query = $marker_case ? 'mutation { addToCart(input:{}) { success customer { sessionToken } } }' : match ( $native_result_case ? 'later-filtered-input' : $case ) {
		'ordinary-success', 'existing-token-cart-cookie' => 'mutation { addToCart(input:{}) { success customer { sessionToken } } }',
		'mixed-cart-first' => 'mutation { addToCart(input:{}) { success } login(input:{provider:PASSWORD}) { authToken } }',
		'later-filtered-input' => 'mutation { addToCart(input:{}) { success customer { sessionToken } } checkout(input:{}) { success } }',
		'detached-input-rejection', 'detached-unavailable-dominates' => 'mutation { login(input:{provider:PASSWORD}) { authToken } }',
		default => 'mutation { login(input:{provider:PASSWORD}) { authToken } addToCart(input:{}) { success } }',
	};
	$context = ( new \ReflectionClass( \WPGraphQL\AppContext::class ) )->newInstanceWithoutConstructor();
	// Preserve the genuine single-HTTP ExecutionResult through the terminal hook.
	// Only the controlled Router catch for a thrown bootstrap error uses an array.
	$response = GraphQL::executeQuery( $schema, $query, null, $context );
	$GLOBALS['integration_partial'] = true === ( $response->data['addToCart']['success'] ?? false );
	$GLOBALS['integration_partial_token'] = is_string( $response->data['addToCart']['customer']['sessionToken'] ?? null );
	if ( $marker_case ) { $response = $response->toArray(); }
	if ( $native_result_case ) {
		$response->data['discarded'] = new class implements \JsonSerializable {
			public function jsonSerialize(): mixed { $GLOBALS['integration_discarded_serialize_calls']++; return 'must-not-publish'; }
		};
		$response->setErrorsHandler( static function ( $errors, $formatter ) use ( $case, $handler, $db ) {
			$GLOBALS['integration_format_calls']++; integration_event( 'response.format' );
			$GLOBALS['integration_format_owned'] = $handler->has_owned_scope() && ! $handler->get_owned_lifecycle()->is_terminal()
				&& ! in_array( 'abort', $db->calls, true ) && ! in_array( 'release', $db->calls, true );
			if ( 'native-rejection-throw' === $case ) { throw new \RuntimeException( 'Controlled formatter failure.' ); }
			if ( 'native-rejection-health' === $case ) { $db->report_failed = true; }
			if ( 'native-rejection-cohort' === $case ) { $GLOBALS['wp_filter']['graphql_mutation_input'] = clone $GLOBALS['wp_filter']['graphql_mutation_input']; }
			if ( 'native-rejection-empty' === $case ) { return []; }
			if ( 'native-rejection-malformed' === $case ) { return [ [ 'message' => 7 ] ]; }
			if ( 'native-rejection-raw-errors' === $case ) { return $errors; }
			$formatted = array_map( $formatter, $errors );
			if ( 'native-rejection-error-object' === $case ) { $formatted[0]['extensions']['unsafe'] = new \stdClass(); }
			return $formatted;
		} );
		if ( 'native-rejection-subclass' === $case ) {
			$response = new class( $response->data, $response->errors ) extends \GraphQL\Executor\ExecutionResult {
				public function toArray( int $debug = 0 ): array { $GLOBALS['integration_format_calls']++; return parent::toArray( $debug ); }
			};
		}
		if ( 'native-rejection-json-object' === $case ) {
			$response = new class implements \JsonSerializable {
				public function jsonSerialize(): mixed { $GLOBALS['integration_format_calls']++; return [ 'errors' => [ [ 'message' => 'must-not-run' ] ] ]; }
			};
		}
	}
	$GLOBALS['integration_result_class'] = is_object( $response ) ? get_class( $response ) : null;
	if ( 'existing-token-cart-cookie' === $case ) {
		$headers = apply_filters( 'graphql_response_headers_to_send', [] );
		if ( isset( $headers['woocommerce-session'] ) ) { header( 'woocommerce-session: ' . $headers['woocommerce-session'] ); }
	} else { header( 'woocommerce-session: synthetic-queued-cart' ); }
	header( 'Authorization: synthetic-auth' );
	header( 'Set-Cookie: woocommerce_cart_hash=synthetic; Path=/', false ); header( 'Set-Cookie: unrelated=preserved; Path=/', false );
	apply_filters( 'graphql_process_http_request_response', $response, null, null, null, null, $status );
	exit( 3 );
}

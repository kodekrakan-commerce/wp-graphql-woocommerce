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
	final class WC_Customer {
		public function __construct() { add_action( 'shutdown', [ $this, 'save' ], 10, 0 ); }
		public function get_id() { return HandlerContractBoundary::$user; }
		public function save() { integration_event( 'customer.save' ); }
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
			if ( ! apply_filters( 'woocommerce_cart_session_initialize', true, $this ) ) { return; }
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
		public function __construct( $name, $callback ) { $this->mutation_name = ucfirst( $name ); $this->config = [ 'mutateAndGetPayload' => $callback ]; }
		public function resolver() { return $this->get_resolver(); }
	}
	$case = 'cli' === PHP_SAPI ? ( $argv[1] ?? '' ) : ( $_GET['case'] ?? '' );
	$ledger = 'cli' === PHP_SAPI ? ( $argv[2] ?? '' ) : getenv( 'WL_INTEGRATION_HTTP_LEDGER' );
	$GLOBALS['integration_translations'] = 0; $GLOBALS['integration_forbid_translation'] = false;
	$GLOBALS['integration_creation_order'] = false;
	HandlerContractBoundary::$user = 0; HandlerContractBoundary::$graphql = true;
	HandlerContractBoundary::$cache = [ WC_SESSION_CACHE_GROUP . ':wc_' . WC_SESSION_CACHE_GROUP . '_cache_prefix' => 'fixed-prefix' ];
	HandlerContractBoundary::$woocommerce = (object) [ 'session' => null, 'customer' => null, 'cart' => null ];
	$GLOBALS['wpdb'] = $db = new HandlerContractDatabase(); $_SERVER['REQUEST_METHOD'] = 'POST';
	$id = str_repeat( 'a', 32 ); HandlerContractBoundary::$rows[ $id ] = [ 'cart' => 'authoritative-synthetic' ];
	$token = JWT::encode( [ 'iss' => get_bloginfo( 'url' ), 'iat' => time()-2, 'nbf' => time()-2, 'exp' => time()+172800, 'data' => [ 'customer_id' => $id ] ], GRAPHQL_WOOCOMMERCE_SECRET_KEY, 'HS256' );
	$_SERVER['HTTP_WOOCOMMERCE_SESSION'] = 'Session ' . $token;
	$fixture_hash = getenv( 'WL_INTEGRATION_FIXTURE_SHA' );
	define( 'WOOGRAPHQL_CART_SESSION_SOURCE_COHORT', [
		'WP_Hook' => 'b839c0e5672246bca8db1ab781ec8835f7732f253c375a237cbf6ec536e8d12e',
		'WPGraphQL\\Router' => getenv( 'WL_INTEGRATION_ADAPTER_SHA' ),
		'WC_Customer' => $fixture_hash, 'WC_Cart' => $fixture_hash, 'WC_Cart_Session' => $fixture_hash,
		QL_Session_Handler::class => getenv( 'WL_INTEGRATION_HANDLER_SHA' ),
	] );
	define( 'WOOGRAPHQL_CART_SESSION_CALLBACK_COHORT', [] );
	$handler = new QL_Session_Handler(); WC()->session = $handler;
	register_shutdown_function( static function () use ( $ledger, $handler, $db, $id ) {
		do_action( 'shutdown' );
		$state = [ 'creation_order' => $GLOBALS['integration_creation_order'], 'rejection' => $handler->has_session_rejection(), 'detached' => $handler->is_auth_detached(), 'terminal' => $handler->get_owned_lifecycle()->is_terminal(),
			'events' => array_column( HandlerContractBoundary::$events, 'kind' ), 'writes' => $db->writes, 'calls' => $db->calls, 'row_preserved' => [ 'cart' => 'authoritative-synthetic' ] === HandlerContractBoundary::$rows[ $id ],
			'translations' => $GLOBALS['integration_translations'], 'partial_data_before_terminal' => $GLOBALS['integration_partial'] ?? false, 'partial_token_before_terminal' => $GLOBALS['integration_partial_token'] ?? false,
			'cookie_registry_stable' => $GLOBALS['integration_cookie_registry_stable'] ?? null,
			'cookie_issuance_preserved' => $GLOBALS['integration_cookie_issuance_preserved'] ?? null,
			'cookie_token_policy' => $GLOBALS['integration_cookie_token_policy'] ?? null ];
		file_put_contents( $ledger, json_encode( $state ), LOCK_EX ); chmod( $ledger, 0600 );
	} );
	$handler->init(); $handler->assert_session_ready();
	WC()->customer = new WC_Customer(); $cart = new WC_Cart(); WC()->cart = $cart;
	$handler->set( 'cart', 'synthetic-pending-cart' );
	do_action( 'do_graphql_request' );
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
	if ( in_array( $case, [ 'later-filtered-input', 'detached-input-rejection', 'detached-unavailable-dominates' ], true ) ) {
		add_filter( 'graphql_mutation_input', static function ( $input, $context, $info, $name ) use ( $case, $db ) {
			if ( 'Checkout' === $name ) { $input['account'] = [ 'username' => 'synthetic' ]; integration_event( 'trusted.checkout.input' ); }
			if ( 'Login' === $name ) {
				$input['provider'] = 'sitetoken'; integration_event( 'trusted.login.input' );
				if ( 'detached-unavailable-dominates' === $case ) { $db->report_failed = true; $GLOBALS['integration_forbid_translation'] = true; $GLOBALS['integration_translations'] = 0; }
			}
			return $input;
		}, 10, 4 );
	}
	if ( 'unavailable-dominates' === $case ) { $db->report_failed = true; $GLOBALS['integration_forbid_translation'] = true; $GLOBALS['integration_translations'] = 0; }
	$query = match ( $case ) {
		'ordinary-success', 'existing-token-cart-cookie' => 'mutation { addToCart(input:{}) { success customer { sessionToken } } }',
		'mixed-cart-first' => 'mutation { addToCart(input:{}) { success } login(input:{provider:PASSWORD}) { authToken } }',
		'later-filtered-input' => 'mutation { addToCart(input:{}) { success customer { sessionToken } } checkout(input:{}) { success } }',
		'detached-input-rejection', 'detached-unavailable-dominates' => 'mutation { login(input:{provider:PASSWORD}) { authToken } }',
		default => 'mutation { login(input:{provider:PASSWORD}) { authToken } addToCart(input:{}) { success } }',
	};
	$context = ( new \ReflectionClass( \WPGraphQL\AppContext::class ) )->newInstanceWithoutConstructor();
	$response = GraphQL::executeQuery( $schema, $query, null, $context )->toArray();
	$GLOBALS['integration_partial'] = true === ( $response['data']['addToCart']['success'] ?? false );
	$GLOBALS['integration_partial_token'] = is_string( $response['data']['addToCart']['customer']['sessionToken'] ?? null );
	if ( 'existing-token-cart-cookie' === $case ) {
		$headers = apply_filters( 'graphql_response_headers_to_send', [] );
		if ( isset( $headers['woocommerce-session'] ) ) { header( 'woocommerce-session: ' . $headers['woocommerce-session'] ); }
	} else { header( 'woocommerce-session: synthetic-queued-cart' ); }
	header( 'Authorization: synthetic-auth' );
	header( 'Set-Cookie: woocommerce_cart_hash=synthetic; Path=/', false ); header( 'Set-Cookie: unrelated=preserved; Path=/', false );
	apply_filters( 'graphql_process_http_request_response', $response, null, null, null, null, 200 );
	exit( 3 );
}

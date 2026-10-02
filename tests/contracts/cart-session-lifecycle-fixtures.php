<?php
/**
 * Controlled WooCommerce/session objects, not a site/DB/order qualification.
 * Creation order matches the core boundary: customer + its shutdown writer,
 * protected cart back-reference, initialize filter, then WC()->cart assignment.
 * Actual plugin.php/WP_Hook, typed errors, GraphQL formatter and lifecycle run.
 * This complete file is pinned by the runner; it cannot qualify production code.
 */
namespace WPGraphQL\WooCommerce\Utils {
	final class QL_Session_Handler {
		public $owned = true;
		public $detached = false;
		public $broken = false;
		public $rejected = false;
		public function has_session_rejection() { return $this->rejected; }
		public function reject_cart_operation() { $this->assert_response_available(); $this->rejected = true; }
		public function has_owned_scope() { return $this->owned; }
		public function assert_owned_scope() { if ( ! $this->owned || $this->broken ) { throw new \WLCommerce\Database\Owned_Scope_Error(); } }
		public function assert_response_available() { if ( $this->broken ) { throw new \WLCommerce\Database\Owned_Scope_Error(); } }
		public function assert_session_ready() { if ( $this->detached || $this->broken ) { throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE ); } }
		public function is_auth_detached() { return $this->detached; }
		public function complete_owned_scope() {
			\lifecycle_event( 'scope.complete' );
			if ( 'complete-failure' === $GLOBALS['lifecycle_case'] ) { throw new \WLCommerce\Database\Owned_Scope_Error(); }
			$this->owned = false;
			if ( 'post-release-rejection' === $GLOBALS['lifecycle_case'] ) { $this->broken = true; }
		}
		public function discard_owned_scope() { \lifecycle_event( 'scope.discard' ); $this->owned = false; }
		public function save_data() { \lifecycle_event( 'handler.shutdown.save' ); }
		public function add_prepared_session_header( $headers ) { \lifecycle_event( 'header.prepared' ); $headers['woocommerce-session'] = 'synthetic-cart'; return $headers; }
	}
}
namespace WPGraphQL { final class Router {} }
namespace {
	error_reporting( E_ALL ); ini_set( 'display_errors', '0' ); ini_set( 'log_errors', '0' );
	$owner = dirname( __DIR__, 2 );
	$wp = rtrim( getenv( 'WL_WORDPRESS_SOURCE' ) ?: '', '/' );
	$gql = rtrim( getenv( 'WL_WPGRAPHQL_SOURCE' ) ?: '', '/' );
	$mu = rtrim( getenv( 'WL_MU_PLUGINS_SOURCE' ) ?: '', '/' );
	require $wp . '/wp-includes/plugin.php';
	require $wp . '/wp-includes/class-wp-error.php';
	require $gql . '/vendor/autoload.php';
	require $mu . '/database/class-owned-scope-error.php';
	require $owner . '/includes/utils/class-cart-session-error.php';
	require $owner . '/includes/utils/class-cart-session-operation.php';
	require $owner . '/includes/utils/class-cart-session-http-boundary.php';
	require $owner . '/includes/utils/class-cart-session-lifecycle.php';

	use WPGraphQL\WooCommerce\Utils\Cart_Session_Error;
	use WPGraphQL\WooCommerce\Utils\Cart_Session_Lifecycle;
	use WPGraphQL\WooCommerce\Utils\QL_Session_Handler;
	$GLOBALS['lifecycle_case'] = 'cli' === PHP_SAPI ? ( $argv[1] ?? '' ) : ( $_GET['case'] ?? '' );
	$GLOBALS['lifecycle_events'] = [];
	$GLOBALS['lifecycle_user'] = 7;
	$GLOBALS['lifecycle_wc'] = (object) [ 'session' => null, 'customer' => null, 'cart' => null ];
	$GLOBALS['lifecycle_state'] = [ 'created_before_cart_assignment' => false, 'cleanup_terminal' => false ];
	function lifecycle_event( $event ) { $GLOBALS['lifecycle_events'][] = $event; }
	function WC() { return $GLOBALS['lifecycle_wc']; }
	function get_current_user_id() { return $GLOBALS['lifecycle_user']; }
	function __( $text, $domain = null ) { return $text; }
	function lifecycle_unrelated() { lifecycle_event( 'unrelated.callback' ); }
	function lifecycle_known_response( $response ) { lifecycle_event( 'known.response' ); return $response; }
	function lifecycle_known_header( $headers ) { lifecycle_event( 'known.header' ); return $headers; }
	function lifecycle_known_auth( $status ) { lifecycle_event( 'known.auth' ); return $status; }
	function lifecycle_known_initialize( $enabled ) { lifecycle_event( 'known.initialize' ); return 'disabled-secondary' === $GLOBALS['lifecycle_case'] && WC()->cart ? false : $enabled; }
	function lifecycle_known_set_headers() { lifecycle_event( 'known.set_headers' ); }
	function lifecycle_all( $hook ) { lifecycle_event( 'known.all.' . $hook ); }
	// Unknown all-hook shutdown side effects remain explicitly unqualified. The
	// ledger distinguishes them from callbacks reached during request dispatch.
	function lifecycle_unknown( $value = null ) { lifecycle_event( 'shutdown' === $value ? 'unknown.callback.shutdown' : 'unknown.callback' ); return $value; }
	final class Lifecycle_Reviewed_Receiver {
		public function response( $value ) { lifecycle_event( 'known.receiver' ); return $value; }
	}
	final class WC_Customer {
		private $id;
		public function __construct( $id ) { $this->id = $id; add_action( 'shutdown', [ $this, 'save' ], 10, 0 ); }
		public function get_id() { return $this->id; }
		public function save() {
			lifecycle_event( 'customer.save' );
			if ( 'customer-failure' === $GLOBALS['lifecycle_case'] ) { throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE ); }
			if ( 'customer-replaces-cart' === $GLOBALS['lifecycle_case'] ) { WC()->cart = new \stdClass(); }
			if ( 'writer-readded-at-flush' === $GLOBALS['lifecycle_case'] ) { add_action( 'shutdown', [ $this, 'save' ], 11, 0 ); }
			if ( 'cohort-changed-at-flush' === $GLOBALS['lifecycle_case'] ) { add_filter( 'graphql_process_http_request_response', 'lifecycle_unknown', 5, 1 ); }
		}
	}
	final class WC_Cart {
		public $session;
		public function __construct() {
			$this->session = new WC_Cart_Session( $this );
			foreach ( [ 'woocommerce_add_to_cart', 'woocommerce_applied_coupon', 'woocommerce_removed_coupon', 'woocommerce_cart_item_removed', 'woocommerce_cart_item_restored' ] as $hook ) {
				add_action( $hook, [ $this, 'calculate_totals' ], 20, 0 );
			}
		}
		public function calculate_totals() { lifecycle_event( 'cart.calculate_totals' ); }
	}
	final class WC_Cart_Session {
		protected $cart;
		public $enabled;
		public function __construct( $cart ) {
			$this->cart = $cart;
			if ( null === WC()->cart ) { $GLOBALS['lifecycle_state']['created_before_cart_assignment'] = null !== WC()->customer && false !== has_action( 'shutdown', [ WC()->customer, 'save' ] ); }
			$this->enabled = apply_filters( 'woocommerce_cart_session_initialize', true, $this );
			if ( ! $this->enabled ) { return; }
			foreach ( [ 'wp_loaded' => [ 'get_cart_from_session' ], 'woocommerce_cart_emptied' => [ 'destroy_cart_session' ],
				'woocommerce_after_calculate_totals' => [ 'set_session' ], 'woocommerce_removed_coupon' => [ 'set_session' ],
				'woocommerce_add_to_cart' => [ 'persistent_cart_update', 'maybe_set_cart_cookies' ],
				'woocommerce_cart_item_removed' => [ 'persistent_cart_update' ], 'woocommerce_cart_item_restored' => [ 'persistent_cart_update' ],
				'woocommerce_cart_item_set_quantity' => [ 'persistent_cart_update' ], 'wp' => [ 'maybe_set_cart_cookies' ],
				'shutdown' => [ 'maybe_set_cart_cookies' ], 'template_redirect' => [ 'clean_up_removed_cart_contents' ] ] as $hook => $methods ) {
				foreach ( $methods as $method ) { add_action( $hook, [ $this, $method ], 20, 0 ); }
			}
		}
		public function replace_cart( $cart ) { $this->cart = $cart; }
		public function get_cart_from_session() { lifecycle_event( 'cart.load' ); }
		public function destroy_cart_session() { lifecycle_event( 'cart.destroy' ); }
		public function set_session() {
			lifecycle_event( 'cart.set_session' );
			if ( 'cart-failure' === $GLOBALS['lifecycle_case'] ) { throw new \WLCommerce\Database\Owned_Scope_Error(); }
		}
		public function persistent_cart_update() { lifecycle_event( 'cart.persistent' ); }
		public function maybe_set_cart_cookies() { lifecycle_event( 'cart.cookies' ); header( 'Set-Cookie: cart-owned=synthetic; Path=/', false ); }
		public function clean_up_removed_cart_contents() { lifecycle_event( 'cart.clean_removed' ); }
	}
	final class Lifecycle_Response implements \JsonSerializable {
		public function jsonSerialize(): mixed {
			lifecycle_event( 'serialize' );
			$case = $GLOBALS['lifecycle_case'];
			if ( 'serialize-throw' === $case ) { throw new \RuntimeException( 'Synthetic encoding failure.' ); }
			if ( 'serialize-invalid' === $case ) { return NAN; }
			if ( 'replacement-customer' === $case ) { WC()->customer = clone WC()->customer; }
			if ( 'replacement-cart' === $case ) { WC()->cart = new \stdClass(); }
			if ( 'replacement-handler' === $case ) { WC()->session = new QL_Session_Handler(); }
			if ( 'replacement-user' === $case ) { $GLOBALS['lifecycle_user'] = 8; }
			if ( 'replacement-backref' === $case ) { WC()->cart->session->replace_cart( new \stdClass() ); }
			if ( 'ownership-failure' === $case ) { WC()->session->broken = true; }
			return [ 'data' => [ 'synthetic' => true, 'serialized_before_writers' => ! in_array( 'customer.save', $GLOBALS['lifecycle_events'], true ) ] ];
		}
	}
	function lifecycle_record( $hook, $callback, $priority, $arguments ) {
		$record = [ 'hook' => $hook, 'priority' => $priority, 'accepted_args' => $arguments, 'stable_registry' => true, 'nonstreaming' => true, 'sha256' => getenv( 'WL_LIFECYCLE_FIXTURE_SHA' ) ];
		if ( $callback instanceof \Closure ) {
			$r = new \ReflectionFunction( $callback );
			$record += [ 'kind' => 'closure', 'start' => $r->getStartLine(), 'end' => $r->getEndLine(), 'scope' => $r->getClosureScopeClass() ? $r->getClosureScopeClass()->getName() : null, 'receiver_class' => $r->getClosureThis() ? get_class( $r->getClosureThis() ) : null ];
		} elseif ( is_array( $callback ) ) {
			$record += [ 'kind' => 'method', 'class' => get_class( $callback[0] ), 'method' => $callback[1], 'instance' => true ];
		} else { $record += [ 'kind' => 'function', 'function' => $callback ]; }
		return $record;
	}

	$case = $GLOBALS['lifecycle_case'];
	$ledger = 'cli' === PHP_SAPI ? ( $argv[2] ?? '' ) : ( getenv( 'WL_LIFECYCLE_HTTP_LEDGER' ) ?: '' );
	$handler = new QL_Session_Handler();
	WC()->session = $handler;
	add_action( 'shutdown', [ $handler, 'save_data' ], 10, 0 );
	add_action( 'shutdown', 'lifecycle_unrelated', 50, 0 );
	add_action( 'woocommerce_add_to_cart', 'lifecycle_unrelated', 50, 0 );
	$manifest = [];
	$register = function ( $hook, $callback, $priority = 10, $arguments = 1 ) use ( &$manifest ) {
		add_filter( $hook, $callback, $priority, $arguments ); $manifest[] = lifecycle_record( $hook, $callback, $priority, $arguments );
	};
	$register( 'graphql_process_http_request_response', 'lifecycle_known_response' );
	$register( 'graphql_response_headers_to_send', 'lifecycle_known_header' );
	$register( 'graphql_authentication_error_status_code', 'lifecycle_known_auth' );
	$register( 'woocommerce_cart_session_initialize', 'lifecycle_known_initialize' );
	$register( 'graphql_response_set_headers', 'lifecycle_known_set_headers', 10, 0 );
	$manifest[ count( $manifest ) - 1 ]['skip_after_early_auth'] = 'early-auth-unsafe-skip' !== $case;
	$receiver = new Lifecycle_Reviewed_Receiver();
	if ( in_array( $case, [ 'qualified-method', 'receiver-changed' ], true ) ) { $register( 'graphql_process_http_request_response', [ $receiver, 'response' ], 20 ); }
	$closure = static function ( $value ) { lifecycle_event( 'known.closure' ); return $value; };
	if ( 'qualified-closure' === $case ) { $register( 'graphql_process_http_request_response', $closure, 20 ); }
	if ( 'qualified-all' === $case ) { $register( 'all', 'lifecycle_all', 10, 1 ); }
	if ( 'unknown-all' === $case ) { add_action( 'all', 'lifecycle_unknown' ); }
	if ( 'unknown-before-install' === $case ) { add_filter( 'graphql_response_headers_to_send', 'lifecycle_unknown' ); }
	if ( 'manifest-wrong-source' === $case ) { $manifest[0]['sha256'] = str_repeat( '0', 64 ); }
	if ( 'manifest-unstable' === $case ) { $manifest[0]['stable_registry'] = false; }
	if ( 'manifest-streaming' === $case ) { $manifest[0]['nonstreaming'] = false; }
	if ( 'manifest-wrong-signature' === $case ) { $manifest[0]['accepted_args'] = 2; }
	// Permit this reviewed callback to be installed after install, before dispatch.
	if ( 'rearmed-max' === $case ) { $manifest[] = lifecycle_record( 'graphql_process_http_request_response', 'lifecycle_known_response', PHP_INT_MAX, 1 ); }
	define( 'WOOGRAPHQL_CART_SESSION_CALLBACK_COHORT', $manifest );
	$fixture_hash = getenv( 'WL_LIFECYCLE_FIXTURE_SHA' );
	$sources = [ 'WP_Hook' => 'b839c0e5672246bca8db1ab781ec8835f7732f253c375a237cbf6ec536e8d12e' ];
	foreach ( [ 'WPGraphQL\\Router', 'WC_Customer', 'WC_Cart', 'WC_Cart_Session', QL_Session_Handler::class ] as $class ) { $sources[ $class ] = $fixture_hash; }
	if ( 'source-wrong' === $case ) { $sources['WC_Customer'] = str_repeat( '0', 64 ); }
	if ( 'source-missing' === $case ) { unset( $sources['WC_Cart'] ); }
	define( 'WOOGRAPHQL_CART_SESSION_SOURCE_COHORT', $sources );
	$lifecycle = new Cart_Session_Lifecycle( $handler, 'woocommerce-session', [ 'cart-owned' ] );
	register_shutdown_function( static function () use ( $ledger, $lifecycle, $handler ) {
		do_action( 'shutdown' );
		$GLOBALS['lifecycle_state']['cleanup_terminal'] = $lifecycle->is_terminal();
		$GLOBALS['lifecycle_state']['events'] = $GLOBALS['lifecycle_events'];
		$GLOBALS['lifecycle_state']['owned_after'] = $handler->owned;
		$GLOBALS['lifecycle_state']['retained_unrelated_cart_callback'] = false !== has_action( 'woocommerce_add_to_cart', 'lifecycle_unrelated' );
		if ( $ledger ) { file_put_contents( $ledger, json_encode( $GLOBALS['lifecycle_state'] ), LOCK_EX ); chmod( $ledger, 0600 ); }
	} );
	$lifecycle->install();
	add_filter( 'graphql_response_headers_to_send', [ $handler, 'add_prepared_session_header' ], 10, 1 );
	if ( 'initial-invalid-no-scope' === $case ) { $handler->owned = false; }
	else {
		WC()->customer = new WC_Customer( 7 );
		$cart = new WC_Cart(); WC()->cart = $cart;
		$GLOBALS['lifecycle_state']['initial_cart_enabled'] = true === $cart->session->enabled;
	}
	if ( 'disabled-secondary' === $case ) {
		$secondary = new WC_Cart();
		$GLOBALS['lifecycle_state']['secondary_disabled'] = false === $secondary->session->enabled;
	}
	if ( 'rearmed-max' === $case ) { add_filter( 'graphql_process_http_request_response', 'lifecycle_known_response', PHP_INT_MAX, 1 ); }
	if ( 'auth-detached' === $case ) { $handler->detached = true; $handler->owned = false; }
	do_action( 'do_graphql_request' );
	if ( 'unknown-after-freeze' === $case ) { add_filter( 'graphql_process_http_request_response', 'lifecycle_unknown', 5, 1 ); }
	if ( 'registry-changed' === $case ) { $GLOBALS['wp_filter']['graphql_process_http_request_response'] = clone $GLOBALS['wp_filter']['graphql_process_http_request_response']; }
	if ( 'callback-arguments-changed' === $case ) { add_filter( 'graphql_process_http_request_response', 'lifecycle_known_response', 10, 2 ); }
	if ( 'receiver-changed' === $case ) {
		remove_filter( 'graphql_process_http_request_response', [ $receiver, 'response' ], 20 );
		add_filter( 'graphql_process_http_request_response', [ new Lifecycle_Reviewed_Receiver(), 'response' ], 20, 1 );
	}
	if ( 'all-after-freeze' === $case ) { add_action( 'all', 'lifecycle_unknown', 10, 1 ); $lifecycle->guard_request(); }
	if ( 'header-map-preserved' === $case ) {
		$headers = apply_filters( 'graphql_response_headers_to_send', [ 'X-Synthetic' => 'unchanged' ] );
		$GLOBALS['lifecycle_state']['header_map_preserved'] = [ 'X-Synthetic' => 'unchanged', 'woocommerce-session' => 'synthetic-cart' ] === $headers;
	}
	if ( 'unfinished-shutdown' === $case ) { exit; }
	if ( 'exact-writers' === $case ) {
		$other = new WC_Customer( 99 );
		$lifecycle->close_writers();
		$GLOBALS['lifecycle_state']['other_customer_writer_retained'] = false !== has_action( 'shutdown', [ $other, 'save' ] );
		$GLOBALS['lifecycle_state']['owned_customer_writer_removed'] = false === has_action( 'shutdown', [ WC()->customer, 'save' ] );
		do_action( 'woocommerce_add_to_cart' ); $lifecycle->cleanup(); echo '{"data":{"exact_writers":true}}'; exit;
	}
	header( 'woocommerce-session: synthetic-cart' );
	header( 'Authorization: synthetic-auth' );
	header( 'Set-Cookie: cart-owned=synthetic; Path=/', false );
	header( 'Set-Cookie: wordpress_logged_in_synthetic=preserved; Path=/; HttpOnly', false );
	if ( str_starts_with( $case, 'early-auth' ) ) {
		$error = new \WP_Error( 'synthetic_auth', 'Synthetic authentication required.' );
		$status = apply_filters( 'graphql_authentication_error_status_code', 403, $error );
		$GLOBALS['lifecycle_state']['auth_status_preserved'] = 403 === $status;
		apply_filters( 'graphql_response_headers_to_send', [ 'Access-Control-Allow-Origin' => 'https://validated.example.invalid', 'Access-Control-Allow-Credentials' => 'true', 'Authorization' => 'synthetic-auth' ] );
		exit( 3 );
	}
	if ( 'header-cohort-changed' === $case ) { add_filter( 'graphql_response_headers_to_send', 'lifecycle_unknown', 5, 1 ); apply_filters( 'graphql_response_headers_to_send', [] ); exit( 3 ); }
	if ( 'auth-cohort-changed' === $case ) { add_filter( 'graphql_authentication_error_status_code', 'lifecycle_unknown', 5, 1 ); apply_filters( 'graphql_authentication_error_status_code', 403, new \WP_Error( 'synthetic', 'Synthetic' ) ); exit( 3 ); }
	$response = new Lifecycle_Response();
	if ( in_array( $case, [ 'transition-partial-response', 'invalid-partial-response', 'initial-invalid-no-scope' ], true ) ) {
		$schema = new \GraphQL\Type\Schema( [ 'query' => new \GraphQL\Type\Definition\ObjectType( [
			'name' => 'LifecycleSyntheticQuery', 'fields' => [
				'earlier' => [ 'type' => \GraphQL\Type\Definition\Type::string(), 'resolve' => static function () { lifecycle_event( 'synthetic.earlier.resolver' ); return 'synthetic-private-cart-data'; } ],
				'blocked' => [ 'type' => \GraphQL\Type\Definition\Type::string(), 'resolve' => static function () use ( $handler, $case ) {
					$handler->rejected = true;
					throw 'transition-partial-response' === $case ? new \WPGraphQL\WooCommerce\Utils\Cart_Session_Transition_Error() : new Cart_Session_Error( Cart_Session_Error::INVALID );
				} ],
			] ] ) ] );
		$response = \GraphQL\GraphQL::executeQuery( $schema, 'initial-invalid-no-scope' === $case ? '{ blocked }' : '{ earlier blocked }' )->toArray();
	}
	apply_filters( 'graphql_process_http_request_response', $response, null, null, null, null, 200 );
	exit( 3 );
}

<?php
/**
 * Actual Checkout closure + native GraphQL execution/error formatting.
 * External checkout/order helpers, factory and hooks are controlled substitutes.
 * This proves closure control flow, not actual WP orders/datastore/payment behavior.
 */
error_reporting( E_ALL ); ini_set( 'display_errors', '0' ); ini_set( 'log_errors', '0' );
$graphql_root = rtrim( getenv( 'WL_WPGRAPHQL_SOURCE' ) ?: '', '/' );
$plugin_root = rtrim( getenv( 'WL_WOOGRAPHQL_SOURCE' ) ?: dirname( __DIR__, 2 ), '/' );
$required = [
	$graphql_root . '/vendor/autoload.php',
	$graphql_root . '/src/AppContext.php',
	$plugin_root . '/includes/mutation/class-checkout.php',
	$plugin_root . '/includes/utils/class-cart-session-error.php',
	$plugin_root . '/includes/utils/class-cart-session-operation.php',
];
foreach ( $required as $file ) {
	if ( ! is_file( $file ) ) { fwrite( STDERR, "Required source unavailable; set WL_WPGRAPHQL_SOURCE and WL_WOOGRAPHQL_SOURCE.\n" ); exit( 2 ); }
}
require __DIR__ . '/checkout-session-error/boundaries.php';
require $graphql_root . '/vendor/autoload.php';
require $graphql_root . '/src/AppContext.php';
require $plugin_root . '/includes/utils/class-cart-session-error.php';
require $plugin_root . '/includes/utils/class-cart-session-operation.php';
require $plugin_root . '/includes/mutation/class-checkout.php';

function checkout_contract_assert( $condition ) {
	if ( ! $condition ) { throw new RuntimeException( 'Checkout closure contract failed.' ); }
}
function checkout_contract_execute( $phase, $error = null ) {
	$GLOBALS['checkout_contract'] = [ 'phase' => $phase, 'error' => $error, 'created' => 0, 'retrieved' => 0, 'purged' => 0, 'order' => null, 'hooks' => [] ];
	$schema = \GraphQL\Utils\BuildSchema::build( 'input CheckoutInput { marker: String } type CheckoutPayload { id: Int, result: String, redirect: String } type Query { sentinel: Boolean } type Mutation { checkout(input: CheckoutInput!): CheckoutPayload }' );
	$closure = \WPGraphQL\WooCommerce\Mutation\Checkout::mutate_and_get_payload();
	$schema->getMutationType()->getField( 'checkout' )->resolveFn = static function ( $source, $args, $context, $info ) use ( $closure ) {
		return $closure( $args['input'], $context, $info );
	};
	// Source passes context through to external helpers; WP bootstrap/loaders are unnecessary here.
	$context = ( new ReflectionClass( \WPGraphQL\AppContext::class ) )->newInstanceWithoutConstructor();
	$result = \GraphQL\GraphQL::executeQuery( $schema, \GraphQL\Language\Parser::parse( 'mutation{checkout(input:{marker:"synthetic"}){id result redirect}}' ), null, $context );
	checkout_contract_assert( $GLOBALS['checkout_contract']['context'] === $context );
	checkout_contract_assert( $GLOBALS['checkout_contract']['info'] instanceof \GraphQL\Type\Definition\ResolveInfo );
	checkout_contract_assert( [ 'marker' => 'synthetic' ] === $GLOBALS['checkout_contract']['input'] );
	return $result;
}
function checkout_contract_error( $result, $error, $code ) {
	$formatted = $result->toArray();
	checkout_contract_assert( 1 === count( $result->errors ) );
	checkout_contract_assert( $result->errors[0]->getPrevious() === $error );
	checkout_contract_assert( $code === ( $formatted['errors'][0]['extensions']['code'] ?? null ) );
	checkout_contract_assert( $error->getMessage() === $formatted['errors'][0]['message'] );
	checkout_contract_assert( [ 'checkout' => null ] === $formatted['data'] );
	checkout_contract_assert( [ 'checkout' ] === $formatted['errors'][0]['path'] );
	checkout_contract_assert( 0 === $GLOBALS['checkout_contract']['purged'] );
}

$cases = [
	'during-completion-error-preserves-durable-order-before-helper-returns' => static function () {
		$error = new \GraphQL\Error\UserError( 'Controlled completion failure.' );
		$result = checkout_contract_execute( 'completion', $error );
		$formatted = $result->toArray();
		checkout_contract_assert( 1 === count( $result->errors ) && [ 'checkout' => null ] === $formatted['data'] );
		checkout_contract_assert( $error->getMessage() === $formatted['errors'][0]['message'] );
		checkout_contract_assert( 1 === $GLOBALS['checkout_contract']['created'] && 0 === $GLOBALS['checkout_contract']['retrieved'] );
		checkout_contract_assert( 0 === $GLOBALS['checkout_contract']['purged'] && $GLOBALS['checkout_contract']['order']->durable && $GLOBALS['checkout_contract']['order']->preserved );
	},
	'before-order-unavailable-preserves-typed-error' => static function () {
		$error = new \WPGraphQL\WooCommerce\Utils\Cart_Session_Error( \WPGraphQL\WooCommerce\Utils\Cart_Session_Error::UNAVAILABLE );
		$result = checkout_contract_execute( 'before', $error );
		checkout_contract_error( $result, $error, 'WL_CART_SESSION_UNAVAILABLE' );
		checkout_contract_assert( 0 === $GLOBALS['checkout_contract']['created'] && 0 === $GLOBALS['checkout_contract']['retrieved'] && null === $GLOBALS['checkout_contract']['order'] );
		checkout_contract_assert( [] === $GLOBALS['checkout_contract']['hooks'] );
	},
	'success-payload-unchanged' => static function () {
		$result = checkout_contract_execute( 'success' )->toArray();
		checkout_contract_assert( empty( $result['errors'] ) );
		checkout_contract_assert( [ 'checkout' => [ 'id' => 77, 'result' => 'synthetic-success', 'redirect' => '/synthetic-order' ] ] === $result['data'] );
		checkout_contract_assert( 1 === $GLOBALS['checkout_contract']['created'] && 1 === $GLOBALS['checkout_contract']['retrieved'] && 0 === $GLOBALS['checkout_contract']['purged'] );
		checkout_contract_assert( $GLOBALS['checkout_contract']['order']->preserved );
	},
];
foreach ( [ 'WL_CART_SESSION_UNAVAILABLE', 'WL_CART_SESSION_INVALID', 'WL_CART_SESSION_TRANSITION_INVALID' ] as $code ) {
	$cases['after-order-' . strtolower( $code ) . '-keeps-order-and-classification'] = static function () use ( $code ) {
		$error = 'WL_CART_SESSION_TRANSITION_INVALID' === $code
			? new \WPGraphQL\WooCommerce\Utils\Cart_Session_Transition_Error()
			: new \WPGraphQL\WooCommerce\Utils\Cart_Session_Error( $code );
		$result = checkout_contract_execute( 'after', $error );
		checkout_contract_error( $result, $error, $code );
		$state = $GLOBALS['checkout_contract'];
		checkout_contract_assert( 1 === $state['created'] && 1 === $state['retrieved'] );
		checkout_contract_assert( $state['retrieved_order'] === $state['order'] );
		checkout_contract_assert( 77 === $state['order']->id && $state['order']->durable && $state['order']->preserved );
		checkout_contract_assert( [ 'result' => 'synthetic-success', 'redirect' => '/synthetic-order' ] === $state['results'] );
		checkout_contract_assert( [ 'graphql_woocommerce_before_checkout', 'graphql_woocommerce_after_checkout' ] === $state['hooks'] );
	};
}
$failures = 0;
foreach ( $cases as $name => $callback ) {
	ob_start();
	try { $callback(); $passed = true; } catch ( Throwable $error ) { $passed = false; }
	ob_end_clean();
	echo ( $passed ? 'PASS ' : 'FAIL ' ) . $name . PHP_EOL;
	if ( ! $passed ) { ++$failures; }
}
echo 'Cases: ' . count( $cases ) . '; failures: ' . $failures . PHP_EOL;
exit( $failures ? 1 : 0 );

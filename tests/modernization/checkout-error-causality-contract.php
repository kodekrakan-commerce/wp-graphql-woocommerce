<?php
/**
 * Actual handler failure latch, both Checkout catch paths and default GraphQL
 * formatting. Entry/adoption/order/gateway/hook boundaries are controlled;
 * no WordPress bootstrap, database, native checkout or provider is exercised.
 */
error_reporting( E_ALL ); ini_set( 'display_errors', '0' ); ini_set( 'log_errors', '0' );
$graphql_root = rtrim( getenv( 'WL_WPGRAPHQL_SOURCE' ) ?: '', '/' );
if ( ! is_file( $graphql_root . '/vendor/autoload.php' ) || ! is_file( $graphql_root . '/src/AppContext.php' ) ) {
	fwrite( STDERR, "Required source unavailable; set WL_WPGRAPHQL_SOURCE.\n" ); exit( 2 );
}
require $graphql_root . '/vendor/autoload.php';
require $graphql_root . '/src/AppContext.php';
class WC_Session_Handler {}
function __( $text, $domain = '' ) { return $text; }
function WC() { return $GLOBALS['causality_wc']; }
function apply_filters( $name, ...$args ) {
	causality_assert( 'graphql_woocommerce_checkout_payment_result' === $name );
	++$GLOBALS['causality_filter_calls'];
	throw $GLOBALS['causality_primary'];
}
$owner = dirname( __DIR__, 2 );
require $owner . '/includes/utils/class-cart-session-error.php';
require $owner . '/includes/utils/class-ql-session-handler.php';
require $owner . '/includes/data/mutation/class-checkout-mutation.php';
require $owner . '/includes/mutation/class-checkout.php';

use WPGraphQL\WooCommerce\Utils\Cart_Session_Error;
use WPGraphQL\WooCommerce\Utils\QL_Session_Handler;

final class CausalityHandlerBoundary extends QL_Session_Handler {
	public $entry_error;
	public $order;
	public $ended = 0;
	public $deferred = 0;
	public $awaiting;
	public function is_graphql_session() { return true; }
	public function begin_checkout( $entry, $input, $context, $info ): void { throw $this->entry_error; }
	public function end_checkout( $context, $info ): void { ++$this->ended; }
	public function created_checkout_order( $id ) { causality_assert( 77 === $id ); return $this->order; }
	public function begin_checkout_deferred_payment( $order ): void { causality_assert( $order === $this->order ); ++$this->deferred; }
	public function set( $key, $value ) { causality_assert( 'order_awaiting_payment' === $key ); $this->awaiting = $value; }
}
function causality_assert( $condition ) {
	if ( ! $condition ) { throw new RuntimeException( 'Checkout causality contract failed.' ); }
}
function causality_property( $handler, $name, $value = null, $write = false ) {
	$property = new ReflectionProperty( QL_Session_Handler::class, $name );
	if ( $write ) { $property->setValue( $handler, $value ); }
	return $property->getValue( $handler );
}
function causality_fixture( $class = QL_Session_Handler::class ) {
	$handler = ( new ReflectionClass( $class ) )->newInstanceWithoutConstructor();
	$attempt = (object) [ 'protected' => true, 'save_failed' => false, 'saved' => true, 'success' => true ];
	causality_property( $handler, 'checkout_attempt', $attempt, true );
	causality_property( $handler, 'prepared_token', 'synthetic-prepared-token', true );
	causality_property( $handler, 'prepared_customer_token', 'synthetic-customer-token', true );
	return [ $handler, $attempt ];
}
function causality_latched( $handler, $attempt ) {
	causality_assert( $attempt->save_failed && ! $attempt->saved && ! $attempt->success );
	causality_assert( $handler->protects_checkout_order() );
	causality_assert( Cart_Session_Error::UNAVAILABLE === causality_property( $handler, 'session_failure' ) );
	causality_assert( false === causality_property( $handler, 'prepared_token' ) && false === causality_property( $handler, 'prepared_customer_token' ) );
}
function causality_public( $error, $previous, $code = Cart_Session_Error::UNAVAILABLE ) {
	causality_assert( $error instanceof Cart_Session_Error && $error->getPrevious() === $previous && 0 === $error->getCode() );
	$expected = [ 'message' => Cart_Session_Error::INVALID === $code ? 'The cart session is invalid.' : 'The cart session is temporarily unavailable.', 'extensions' => [ 'code' => $code ] ];
	causality_assert( $expected === \GraphQL\Error\FormattedError::createFromException( $error ) );
	causality_assert( ! str_contains( json_encode( $expected, JSON_THROW_ON_ERROR ), 'synthetic-private-cause' ) );
}
function causality_outer( $handler ) {
	$GLOBALS['causality_wc'] = (object) [ 'session' => $handler ];
	$schema = \GraphQL\Utils\BuildSchema::build( 'type Query { sentinel: Boolean } type Mutation { checkout: String }' );
	$closure = \WPGraphQL\WooCommerce\Mutation\Checkout::mutate_and_get_payload();
	$schema->getMutationType()->getField( 'checkout' )->resolveFn = static function ( $source, $args, $context, $info ) use ( $closure ) { return $closure( [], $context, $info ); };
	$context = ( new ReflectionClass( \WPGraphQL\AppContext::class ) )->newInstanceWithoutConstructor();
	$result = \GraphQL\GraphQL::executeQuery( $schema, 'mutation { checkout }', null, $context );
	$public = $result->toArray(); // Native default formatter; no debug flags.
	causality_assert( 1 === count( $result->errors ) && [ 'checkout' => null ] === $public['data'] );
	causality_assert( 'The cart session is temporarily unavailable.' === $public['errors'][0]['message'] );
	causality_assert( [ 'code' => Cart_Session_Error::UNAVAILABLE ] === $public['errors'][0]['extensions'] );
	causality_assert( [ 'checkout' ] === $public['errors'][0]['path'] );
	causality_assert( ! str_contains( json_encode( $public, JSON_THROW_ON_ERROR ), 'synthetic-private-cause' ) );
	causality_assert( ! isset( $public['errors'][0]['debugMessage'] ) && ! isset( $public['errors'][0]['trace'] ) );
	causality_assert( 1 === $handler->ended );
	return $result->errors[0]->getPrevious();
}
$primary = new RuntimeException( 'synthetic-private-cause key/sql/arguments must stay internal' );
$cases = [
	'legacy-no-cause-constructor' => static function () { causality_public( new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE ), null ); },
	'invalid-classification-keeps-safe-message-and-cause' => static function () use ( $primary ) { causality_public( new Cart_Session_Error( Cart_Session_Error::INVALID, $primary ), $primary, Cart_Session_Error::INVALID ); },
	'legacy-no-cause-handler-retains-latch-and-protection' => static function () {
		[ $handler, $attempt ] = causality_fixture();
		try { $handler->fail_checkout_order(); } catch ( Cart_Session_Error $error ) { causality_public( $error, null ); causality_latched( $handler, $attempt ); return; }
		causality_assert( false );
	},
	'handler-keeps-original-cause-and-latch' => static function () use ( $primary ) {
		[ $handler, $attempt ] = causality_fixture();
		try { $handler->fail_checkout_order( $primary ); } catch ( Cart_Session_Error $error ) { causality_public( $error, $primary ); causality_latched( $handler, $attempt ); return; }
		causality_assert( false );
	},
	'protected-outer-catch-keeps-cause-with-default-public-json' => static function () use ( $primary ) {
		[ $handler, $attempt ] = causality_fixture( CausalityHandlerBoundary::class ); $handler->entry_error = $primary;
		causality_public( causality_outer( $handler ), $primary ); causality_latched( $handler, $attempt );
	},
	'deferred-inner-and-outer-catches-keep-complete-chain' => static function () use ( $primary ) {
		[ $handler, $attempt ] = causality_fixture( CausalityHandlerBoundary::class ); $handler->order = (object) [ 'id' => 77 ];
		$GLOBALS['causality_primary'] = $primary; $GLOBALS['causality_filter_calls'] = 0;
		$GLOBALS['causality_wc'] = (object) [ 'session' => $handler, 'payment_gateways' => new class { public function get_available_payment_gateways() { return [ 'stripe' => (object) [] ]; } } ];
		$method = new ReflectionMethod( \WPGraphQL\WooCommerce\Data\Mutation\Checkout_Mutation::class, 'process_order_payment' );
		try { $method->invoke( null, 77, 'stripe', $handler->order ); } catch ( Cart_Session_Error $inner ) {
			causality_public( $inner, $primary ); causality_latched( $handler, $attempt );
			causality_assert( 1 === $GLOBALS['causality_filter_calls'] && 1 === $handler->deferred && 77 === $handler->awaiting );
			$handler->entry_error = $inner; $outer = causality_outer( $handler );
			causality_public( $outer, $inner ); causality_assert( $outer->getPrevious()->getPrevious() === $primary ); return;
		}
		causality_assert( false );
	},
];
$failed = 0;
foreach ( $cases as $name => $case ) {
	try { $case(); echo 'PASS ' . $name . PHP_EOL; } catch ( Throwable $error ) { ++$failed; echo 'FAIL ' . $name . PHP_EOL; }
}
echo 'Cases: ' . count( $cases ) . '; failures: ' . $failed . PHP_EOL;
exit( $failed ? 1 : 0 );

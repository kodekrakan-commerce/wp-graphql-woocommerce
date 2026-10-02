<?php
use GraphQL\Type\Definition\Type;
use GraphQL\Utils\BuildSchema;
use GraphQL\GraphQL;
use WPGraphQL\Utils\InstrumentSchema;
use WPGraphQL\WooCommerce\Utils\Cart_Session_Operation;
use WPGraphQL\WooCommerce\Utils\Cart_Session_Error;

/** Recording lifecycle substitute; actual WC handler is exercised in its separate cohort. */
final class OperationRecordingHandler {
	public $detached = false;
	public $calls = [];
	public $mode = true;
	public $failure;
	public $rejected = false;
	public function is_graphql_session() { return $this->mode; }
	public function assert_session_ready() { $this->calls[] = 'assert'; if ( $this->failure ) { throw new Cart_Session_Error( $this->failure ); } }
	public function reject_cart_operation() { $this->calls[] = 'reject'; if ( 'WL_CART_SESSION_UNAVAILABLE' === $this->failure ) { throw new Cart_Session_Error( $this->failure ); } $this->rejected = true; }
	public function prepare_session_token() { $this->calls[] = 'session'; }
	public function prepare_customer_session_token() { $this->calls[] = 'customer'; }
	public function complete_session_preparation() { $this->calls[] = 'complete'; }
	public function detach_for_auth() { $this->calls[] = 'detach'; $this->detached = true; }
	public function is_auth_detached() { return $this->detached; }
}

/** Use the actual WPGraphQL callback/payload hook lifecycle without its WP type registry.
 * Checkout deliberately retains its synthetic lowercase mutation name and callback:
 * these cases qualify operation preflight/account holds, not the canonical Checkout
 * terminal origin binding. cart-session-checkout-origin-contract.php pairs the
 * genuine canonical mutation with the actual retained self-bound Checkout closure.
 */
final class OperationMutation extends \WPGraphQL\Type\WPMutationType {
	public function __construct( $name, $callback ) { $this->mutation_name = in_array( $name, [ 'login', 'logout' ], true ) ? ucfirst( $name ) : $name; $this->config = [ 'mutateAndGetPayload' => $callback ]; }
	public function resolver() { return $this->get_resolver(); }
}

function operation_fixture( array $options = [] ) {
	$GLOBALS['op_hooks'] = []; $GLOBALS['op_user'] = $options['user'] ?? 0;
	$GLOBALS['op_guest_checkout'] = $options['guest_checkout'] ?? true;
	$GLOBALS['op_effects'] = []; $GLOBALS['op_infos'] = [];
	$handler = new OperationRecordingHandler(); $handler->mode = $options['mode'] ?? true; $handler->failure = $options['failure'] ?? null;
	$guard = new Cart_Session_Operation( $handler );
	$schema = BuildSchema::build( <<<'SDL'
enum Provider { PASSWORD SITETOKEN }
input Credentials { accepted: Boolean }
input LoginInput { provider: Provider!, credentials: Credentials, clientMutationId: String }
input AccountInput { username: String }
input CheckoutInput { account: AccountInput, clientMutationId: String }
input EmptyInput { clientMutationId: String }
interface Person { id: ID }
type User implements Person { id: ID, databaseId: Int, name: String, username: String, wooSessionToken: String, email: String, customer: Customer, orders: String }
type Customer { id: ID, databaseId: Int, username: String, firstName: String, lastName: String, sessionToken: String, orders: String, billing: String }
type LoginPayload { authToken: String, authTokenExpiration: String, refreshToken: String, refreshTokenExpiration: String, user: User, customer: Customer, sessionToken: String, clientMutationId: String, dangerous: String }
type LogoutPayload { success: Boolean, clientMutationId: String, customer: Customer }
type CartPayload { customer: Customer, success: Boolean }
type Query { cart: CartPayload, customer: Customer, user: User }
type Mutation { login(input: LoginInput!): LoginPayload, logout(input: EmptyInput!): LogoutPayload, addToCart(input: EmptyInput!): CartPayload, checkout(input: CheckoutInput!): CartPayload, registerCustomer(input: EmptyInput!): LoginPayload, registerUser(input: EmptyInput!): LoginPayload, createAccount(input: EmptyInput!): LoginPayload, forgetSession(input: EmptyInput!): CartPayload }
SDL
	);
	$schema->getType( 'Provider' )->getValue( 'PASSWORD' )->value = 'password';
	$schema->getType( 'Provider' )->getValue( 'SITETOKEN' )->value = 'sitetoken';
	foreach ( $schema->getMutationType()->getFields() as $name => $field ) {
		$mutation = new OperationMutation( $name, static function ( $input ) use ( $name, $options ) {
			$GLOBALS['op_effects'][] = $name;
			if ( 'login' === $name ) {
				if ( ! ( $input['credentials']['accepted'] ?? true ) ) { throw new \GraphQL\Error\UserError( 'Synthetic credentials rejected.' ); }
				$GLOBALS['op_user'] = 23;
				return [ 'id' => $options['payload_id'] ?? 23, 'user' => (object) [ 'ID' => $options['user_id'] ?? 23 ], 'authToken' => 'synthetic-result' ];
			}
			if ( 'logout' === $name ) { $GLOBALS['op_user'] = $options['logout_user'] ?? 0; return [ 'success' => true ]; }
			return [ 'success' => true ];
		} );
		$field->resolveFn = $mutation->resolver();
	}
	foreach ( $schema->getQueryType()->getFields() as $name => $field ) {
		$field->resolveFn = static function () use ( $name ) { $GLOBALS['op_effects'][] = $name; return []; };
	}
	foreach ( [ 'LoginPayload', 'LogoutPayload', 'User', 'Customer', 'CartPayload' ] as $type_name ) {
		foreach ( $schema->getType( $type_name )->getFields() as $name => $field ) {
			$field->resolveFn = static function ( $source ) use ( $name, $type_name, $options ) {
				$GLOBALS['op_effects'][] = $type_name . '.' . $name;
				if ( isset( $options['drift_at'] ) && $options['drift_at'] === $type_name . '.' . $name ) { $GLOBALS['op_user'] = 77; }
				if ( in_array( $name, [ 'sessionToken', 'wooSessionToken' ], true ) ) { return apply_filters( 'graphql_customer_session_token', 'synthetic-token' ); }
				if ( in_array( $name, [ 'user', 'customer' ], true ) ) { return (object) [ 'ID' => 23 ]; }
				if ( 'databaseId' === $name ) { return 23; }
				if ( 'success' === $name ) { return true; }
				return 'synthetic-value';
			};
		}
	}
	add_filter( 'graphql_customer_session_token', static function ( $value ) { $GLOBALS['op_effects'][] = 'token-filter'; return $value; } );
	add_action( 'graphql_before_resolve_field', static function ( $source, $args, $context, $info ) { $GLOBALS['op_infos'][] = $info; }, 10, 4 );
	foreach ( $schema->getTypeMap() as $type ) { if ( $type instanceof \GraphQL\Type\Definition\ObjectType && 0 !== strpos( $type->name, '__' ) ) { InstrumentSchema::instrument_resolvers( $type, $type->name ); } }
	return [ $schema, $handler, $guard ];
}

function operation_execute( $query, array $options = [], array $variables = [], $operation_name = null ) {
	[ $schema, $handler, $guard ] = operation_fixture( $options );
	$result = GraphQL::executeQuery( $schema, \GraphQL\Language\Parser::parse( $query ), null, new \WPGraphQL\AppContext(), $variables, $operation_name )->toArray();
	return [ $result, $handler, $guard, $schema ];
}

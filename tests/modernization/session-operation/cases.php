<?php
function op_assert( $condition ) { if ( ! $condition ) { throw new RuntimeException( 'Contract failed.' ); } }
function op_no_errors( $result ) { op_assert( empty( $result['errors'] ) ); }
function op_transition( $result ) {
	op_assert( ! empty( $result['errors'] ) );
	foreach ( $result['errors'] as $error ) { op_assert( 'WL_CART_SESSION_TRANSITION_INVALID' === ( $error['extensions']['code'] ?? null ) ); }
}
function op_no_callbacks() { op_assert( [] === $GLOBALS['op_effects'] ); }
function op_detached_clean( $handler ) {
	op_assert( $handler->detached && 1 === count( array_filter( $handler->calls, static fn( $value ) => 'detach' === $value ) ) );
	op_assert( ! array_intersect( [ 'session', 'customer', 'complete' ], $handler->calls ) );
	op_assert( ! in_array( 'token-filter', $GLOBALS['op_effects'], true ) );
}
function operation_cases() {
	$cases = [];
	$login = 'login(input:{provider:PASSWORD})';
	foreach ( [ "$login {user{id}} addToCart(input:{}){success}", "addToCart(input:{}){success} $login {user{id}}", "$login {user{id}} logout(input:{}){success}", "a:$login {user{id}} b:$login {user{id}}" ] as $index => $body ) {
		$cases['mixed-or-repeated-root-' . $index] = static function () use ( $body ) { [ $result, $handler ] = operation_execute( 'mutation {' . $body . '}' ); op_transition( $result ); op_no_callbacks(); op_assert( ! $handler->detached && $handler->rejected && in_array( 'reject', $handler->calls, true ) ); };
	}
	$cases['merged-root-fragments-alias-and-coerced-enum'] = static function () {
		[ $result, $handler ] = operation_execute( 'mutation Actual($p:Provider!){ ...M auth:login(input:{provider:$p}){ ...P } __typename } fragment M on Mutation { auth:login(input:{provider:$p}){user{id}} } fragment P on LoginPayload { authToken user{db:databaseId ... on Person{id}} customer{firstName lastName} legacy:sessionToken }', [], [ 'p' => 'PASSWORD' ], 'Actual' );
		op_no_errors( $result ); op_detached_clean( $handler ); op_assert( 1 === count( array_filter( $GLOBALS['op_effects'], static fn( $v ) => 'login' === $v ) ) ); op_assert( null === $result['data']['auth']['legacy'] );
	};
	$cases['directives-remove-mixed-root-and-disallowed-payload'] = static function () {
		[ $result, $handler ] = operation_execute( 'mutation($hide:Boolean!,$show:Boolean!){login(input:{provider:PASSWORD}){user{id email @skip(if:$hide)} dangerous @include(if:$show)} ...Extra @include(if:$show)} fragment Extra on Mutation{checkout(input:{account:{username:"synthetic"}}){success}}', [], [ 'hide' => true, 'show' => false ] );
		op_no_errors( $result ); op_detached_clean( $handler );
	};
	$cases['directive-defaults-coerced'] = static function () {
		[ $result, $handler ] = operation_execute( 'mutation($hide:Boolean!=true){login(input:{provider:PASSWORD}){user{id email @skip(if:$hide)}} addToCart(input:{}) @skip(if:$hide){success}}' ); op_no_errors( $result ); op_detached_clean( $handler );
	};
	$cases['directive-included-mixed-root-rejected-before-callback'] = static function () {
		[ $result ] = operation_execute( 'mutation($show:Boolean!){login(input:{provider:PASSWORD}){user{id}} ...Extra @include(if:$show)} fragment Extra on Mutation{addToCart(input:{}){success}}', [], [ 'show' => true ] ); op_transition( $result ); op_no_callbacks();
	};
	$cases['directive-included-payload-rejected-before-callback'] = static function () {
		[ $result ] = operation_execute( 'mutation($show:Boolean!){login(input:{provider:PASSWORD}){user{id ...Extra @include(if:$show)}}} fragment Extra on User{email}', [], [ 'show' => true ] ); op_transition( $result ); op_no_callbacks();
	};
	$cases['operation-label-does-not-grant-auth-permit'] = static function () {
		[ $result, $handler ] = operation_execute( 'mutation login{addToCart(input:{}){success}}', [], [], 'login' ); op_no_errors( $result ); op_assert( ! $handler->detached && in_array( 'session', $handler->calls, true ) );
	};
	$cases['actual-selected-operation-not-client-label-heuristic'] = static function () {
		[ $result, $handler ] = operation_execute( 'mutation Ignore{addToCart(input:{}){success}} mutation CartLabel{auth:login(input:{provider:PASSWORD}){authToken}}', [], [], 'CartLabel' ); op_no_errors( $result ); op_detached_clean( $handler ); op_assert( ! in_array( 'addToCart', $GLOBALS['op_effects'], true ) );
	};
	foreach ( [ 'user{email}', 'user{alias:orders}', 'customer{sessionToken}', 'customer{billing}', 'customer{orders}', 'user{customer{id}}', 'dangerous', 'user{...Secret}', 'customer{alias:sessionToken}' ] as $index => $payload ) {
		$cases['payload-rejected-before-auth-' . $index] = static function () use ( $login, $payload ) { [ $result ] = operation_execute( "mutation { $login { $payload } }" . ( false !== strpos( $payload, 'Secret' ) ? ' fragment Secret on User { email }' : '' ) ); op_transition( $result ); op_no_callbacks(); };
	}
	foreach ( [ 'registerCustomer', 'registerUser', 'createAccount', 'forgetSession' ] as $name ) {
		$cases['interim-hold-' . $name] = static function () use ( $name ) { [ $result ] = operation_execute( 'mutation { addToCart(input:{}){success} ' . $name . '(input:{}){__typename} }' ); op_transition( $result ); op_no_callbacks(); };
	}
	$cases['unqualified-provider-rejected'] = static function () { [ $result ] = operation_execute( 'mutation { login(input:{provider:SITETOKEN}){authToken} }' ); op_transition( $result ); op_no_callbacks(); };
	$cases['wrong-credentials-original-error-and-detached'] = static function () { [ $result, $handler ] = operation_execute( 'mutation { login(input:{provider:PASSWORD,credentials:{accepted:false}}){authToken user{id}} }' ); op_assert( 1 === count( $result['errors'] ) && 'Synthetic credentials rejected.' === $result['errors'][0]['message'] ); op_detached_clean( $handler ); op_assert( [ 'login' ] === $GLOBALS['op_effects'] ); };
	foreach ( [ [ 'payload_id' => 24 ], [ 'user_id' => 24 ], [ 'payload_id' => 0 ] ] as $index => $options ) {
		$cases['payload-identity-mismatch-' . $index] = static function () use ( $login, $options ) { [ $result, $handler ] = operation_execute( "mutation { $login {authToken user{id}} }", $options ); op_transition( $result ); op_detached_clean( $handler ); op_assert( [ 'login' ] === $GLOBALS['op_effects'] ); };
	}
	$cases['successful-logout'] = static function () { [ $result, $handler ] = operation_execute( 'mutation { bye:logout(input:{clientMutationId:"synthetic"}){success clientMutationId __typename} __typename }', [ 'user' => 23 ] ); op_no_errors( $result ); op_detached_clean( $handler ); op_assert( 0 === get_current_user_id() ); };
	$cases['logout-must-complete-anonymous'] = static function () { [ $result, $handler ] = operation_execute( 'mutation {logout(input:{}){success}}', [ 'user' => 23, 'logout_user' => 23 ] ); op_transition( $result ); op_detached_clean( $handler ); op_assert( [ 'logout' ] === $GLOBALS['op_effects'] ); };
	$cases['logout-payload-confined'] = static function () { [ $result ] = operation_execute( 'mutation {logout(input:{}){customer{id}}}', [ 'user' => 23 ] ); op_transition( $result ); op_no_callbacks(); };
	$cases['identity-drift-blocks-later-payload'] = static function () { [ $result, $handler ] = operation_execute( 'mutation{login(input:{provider:PASSWORD}){authToken user{id}}}', [ 'drift_at' => 'LoginPayload.authToken' ] ); op_transition( $result ); op_detached_clean( $handler ); op_assert( ! in_array( 'LoginPayload.user', $GLOBALS['op_effects'], true ) ); };
	$cases['ordinary-selected-customer-token-prepared-before-mutation'] = static function () {
		[ $schema, $handler ] = operation_fixture();
		add_filter( 'graphql_pre_mutate_and_get_payload', static function ( $value ) use ( $handler ) { op_assert( [ 'assert', 'session', 'customer', 'complete', 'assert', 'assert' ] === $handler->calls ); return $value; }, 10, 1 );
		$result = \GraphQL\GraphQL::executeQuery( $schema, 'mutation{addToCart(input:{}){customer{sessionToken}}}', null, new \WPGraphQL\AppContext() )->toArray(); op_no_errors( $result ); op_assert( ! $handler->detached && in_array( 'token-filter', $GLOBALS['op_effects'], true ) );
	};
	$cases['ordinary-user-token-route'] = static function () { [ $result, $handler ] = operation_execute( '{user{wooSessionToken}}' ); op_no_errors( $result ); op_assert( [ 'assert', 'session', 'customer', 'complete' ] === array_slice( $handler->calls, 0, 4 ) ); };
	$cases['unselected-token-has-no-extra-preparation'] = static function () { [ $result, $handler ] = operation_execute( '{customer{sessionToken @skip(if:true) username}}' ); op_no_errors( $result ); op_assert( ! in_array( 'customer', $handler->calls, true ) ); };
	foreach ( [ [ 'guest_checkout' => true ], [ 'guest_checkout' => false ] ] as $index => $options ) {
		$cases['guest-account-checkout-held-' . $index] = static function () use ( $options ) { [ $result ] = operation_execute( 'mutation { addToCart(input:{}){success} checkout(input:{account:{username:"synthetic"}}){success} }', $options ); op_transition( $result ); op_no_callbacks(); };
	}
	$cases['required-registration-checkout-held'] = static function () { [ $result ] = operation_execute( 'mutation{checkout(input:{}){success}}', [ 'guest_checkout' => false ] ); op_transition( $result ); op_no_callbacks(); };
	$cases['server-filter-required-registration-held'] = static function () {
		[ $schema ] = operation_fixture(); add_filter( 'woocommerce_checkout_registration_required', static fn() => true );
		$result = \GraphQL\GraphQL::executeQuery( $schema, 'mutation{checkout(input:{}){success}}', null, new \WPGraphQL\AppContext() )->toArray(); op_transition( $result ); op_no_callbacks();
	};
	$cases['ordinary-guest-synthetic-checkout-origin-rejected'] = static function () { [ $result, $handler ] = operation_execute( 'mutation{checkout(input:{}){success}}' ); op_transition( $result ); op_no_callbacks(); op_assert( ! $handler->detached && $handler->rejected && in_array( 'checkout-boundary', $handler->calls, true ) ); };
	$cases['same-account-synthetic-checkout-origin-rejected'] = static function () { [ $result, $handler ] = operation_execute( 'mutation{checkout(input:{account:{username:"synthetic"}}){success}}', [ 'user' => 23, 'guest_checkout' => false ] ); op_transition( $result ); op_no_callbacks(); op_assert( $handler->rejected && in_array( 'checkout-boundary', $handler->calls, true ) ); };
	$cases['session-invalid-blocks-login-before-detach'] = static function () { [ $result, $handler ] = operation_execute( 'mutation{login(input:{provider:PASSWORD}){authToken}}', [ 'failure' => 'WL_CART_SESSION_INVALID' ] ); op_assert( 'WL_CART_SESSION_INVALID' === $result['errors'][0]['extensions']['code'] ); op_no_callbacks(); op_assert( ! $handler->detached ); };
	$cases['batches-rejected-before-dispatch-and-latched'] = static function () {
		[ $schema, $handler ] = operation_fixture(); $dispatch = 0;
		try { do_action( 'graphql_execute_batch_queries', [ [ 'query' => '{customer{id}}' ], [ 'query' => 'mutation{login(input:{provider:PASSWORD}){authToken}}' ] ] ); ++$dispatch; } catch ( \WPGraphQL\WooCommerce\Utils\Cart_Session_Transition_Error $error ) {}
		op_assert( 0 === $dispatch ); op_no_callbacks();
		$result = \GraphQL\GraphQL::executeQuery( $schema, '{customer{id}}', null, new \WPGraphQL\AppContext() )->toArray(); op_transition( $result ); op_no_callbacks(); op_assert( $handler->rejected && 2 === count( $handler->calls ) && [ 'reject', 'reject' ] === $handler->calls );
	};
	$cases['native-mode-not-affected'] = static function () {
		[ $schema, $handler ] = operation_fixture( [ 'mode' => false ] ); do_action( 'graphql_execute_batch_queries', [ [], [] ] );
		$result = \GraphQL\GraphQL::executeQuery( $schema, 'mutation{registerCustomer(input:{}){__typename}}', null, new \WPGraphQL\AppContext() )->toArray(); op_no_errors( $result ); op_assert( [] === $handler->calls && ! $handler->detached );
	};
	$cases['later-other-operation-is-transition-error'] = static function () {
		[ $result, $handler, $guard, $schema ] = operation_execute( 'mutation{login(input:{provider:PASSWORD}){authToken}}' ); op_no_errors( $result );
		$GLOBALS['op_effects'] = [];
		$later = \GraphQL\GraphQL::executeQuery( $schema, '{customer{sessionToken}}', null, new \WPGraphQL\AppContext() )->toArray(); op_transition( $later ); op_no_callbacks(); op_detached_clean( $handler );
	};
	$cases['legacy-token-null-bypasses-other-pre-resolve-and-token-filters'] = static function () {
		[ $schema, $handler ] = operation_fixture(); add_filter( 'graphql_pre_resolve_field', static function ( $value, $source, $args, $context, $info ) { return 'sessionToken' === $info->fieldName ? 'synthetic-override' : $value; }, 10, 5 );
		$result = \GraphQL\GraphQL::executeQuery( $schema, 'mutation{login(input:{provider:PASSWORD}){aliased:sessionToken}}', null, new \WPGraphQL\AppContext() )->toArray(); op_no_errors( $result ); op_assert( null === $result['data']['login']['aliased'] ); op_detached_clean( $handler );
	};
	foreach ( [ 'sitetoken', null ] as $index => $provider ) {
		$cases['final-input-provider-held-before-pre-mutation-' . $index] = static function () use ( $provider ) {
			[ $schema, $handler ] = operation_fixture();
			add_filter( 'graphql_mutation_input', static function ( $input ) use ( $provider ) { $input['provider'] = $provider; return $input; }, PHP_INT_MAX, 1 );
			add_filter( 'graphql_pre_mutate_and_get_payload', static function () { $GLOBALS['op_effects'][] = 'provider-exchange'; return null; }, 0, 1 );
			$result = \GraphQL\GraphQL::executeQuery( $schema, 'mutation{login(input:{provider:PASSWORD}){authToken user{id}}}', null, new \WPGraphQL\AppContext() )->toArray();
			op_transition( $result ); op_no_callbacks(); op_detached_clean( $handler ); op_assert( 0 === get_current_user_id() );
		};
	}
	$cases['final-input-account-held-before-pre-mutation'] = static function () {
		[ $schema, $handler ] = operation_fixture();
		add_filter( 'graphql_mutation_input', static function ( $input ) { $input['account'] = [ 'username' => 'synthetic' ]; return $input; }, PHP_INT_MAX, 1 );
		add_filter( 'graphql_pre_mutate_and_get_payload', static function () { $GLOBALS['op_effects'][] = 'created-account'; $GLOBALS['op_effects'][] = 'order'; return null; }, 0, 1 );
		$result = \GraphQL\GraphQL::executeQuery( $schema, 'mutation{checkout(input:{}){success}}', null, new \WPGraphQL\AppContext() )->toArray();
		op_transition( $result ); op_no_callbacks(); op_assert( ! $handler->detached && 0 === get_current_user_id() );
	};
	$cases['dynamic-registration-policy-held-before-pre-mutation'] = static function () {
		[ $schema ] = operation_fixture();
		add_filter( 'graphql_mutation_input', static function ( $input ) { add_filter( 'woocommerce_checkout_registration_required', static fn() => true ); return $input; }, PHP_INT_MAX, 1 );
		add_filter( 'graphql_pre_mutate_and_get_payload', static function () { $GLOBALS['op_effects'][] = 'created-account'; $GLOBALS['op_effects'][] = 'order'; return null; }, 0, 1 );
		$result = \GraphQL\GraphQL::executeQuery( $schema, 'mutation{checkout(input:{}){success}}', null, new \WPGraphQL\AppContext() )->toArray();
		op_transition( $result ); op_no_callbacks();
	};
	foreach ( [ 'mutation{login(input:{provider:PASSWORD}){authToken}}', 'mutation{checkout(input:{}){success}}' ] as $index => $query ) {
		$cases['input-filter-identity-change-held-' . $index] = static function () use ( $query ) {
			[ $schema ] = operation_fixture();
			add_filter( 'graphql_mutation_input', static function ( $input ) { $GLOBALS['op_user'] = 77; return $input; }, 10, 1 );
			add_filter( 'graphql_pre_mutate_and_get_payload', static function () { $GLOBALS['op_effects'][] = 'pre-mutation'; return null; }, 0, 1 );
			$result = \GraphQL\GraphQL::executeQuery( $schema, $query, null, new \WPGraphQL\AppContext() )->toArray(); op_transition( $result ); op_no_callbacks();
		};
	}
	foreach ( [ 'mutation{login(input:{provider:PASSWORD}){clientMutationId}}', 'mutation{checkout(input:{}){success}}' ] as $index => $query ) {
		$cases[ 0 === $index ? 'safe-input-filter-still-allowed-0' : 'safe-checkout-input-passes-preflight-but-synthetic-origin-rejected' ] = static function () use ( $query, $index ) {
			[ $schema, $handler ] = operation_fixture();
			add_filter( 'graphql_mutation_input', static function ( $input ) { $input['clientMutationId'] = 'synthetic-filter-value'; return $input; }, PHP_INT_MAX, 1 );
			add_filter( 'graphql_pre_mutate_and_get_payload', static function ( $pre, $name, $callback, $input ) use ( $handler, $index ) { op_assert( 'synthetic-filter-value' === $input['clientMutationId'] ); if ( 0 === $index ) { $GLOBALS['op_effects'][] = 'safe-pre-mutation'; } else { $handler->calls[] = 'safe-pre-input'; } return $pre; }, 0, 4 );
			$result = \GraphQL\GraphQL::executeQuery( $schema, $query, null, new \WPGraphQL\AppContext() )->toArray();
			if ( 0 === $index ) { op_no_errors( $result ); op_assert( in_array( 'safe-pre-mutation', $GLOBALS['op_effects'], true ) && in_array( 'login', $GLOBALS['op_effects'], true ) ); op_detached_clean( $handler ); }
			else { op_transition( $result ); op_no_callbacks(); op_assert( ! $handler->detached && $handler->rejected && in_array( 'safe-pre-input', $handler->calls, true ) && in_array( 'checkout-boundary', $handler->calls, true ) ); }
		};
	}
	$cases['same-account-filtered-input-passes-preflight-but-synthetic-origin-rejected'] = static function () {
		[ $schema, $handler ] = operation_fixture( [ 'user' => 23, 'guest_checkout' => false ] );
		add_filter( 'graphql_mutation_input', static function ( $input ) { $input['account'] = [ 'username' => 'synthetic' ]; return $input; }, 10, 1 );
		$result = \GraphQL\GraphQL::executeQuery( $schema, 'mutation{checkout(input:{}){success}}', null, new \WPGraphQL\AppContext() )->toArray(); op_transition( $result ); op_no_callbacks(); op_assert( ! $handler->detached && $handler->rejected && in_array( 'checkout-boundary', $handler->calls, true ) );
	};
	return $cases;
}

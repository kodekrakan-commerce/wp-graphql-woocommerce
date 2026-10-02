<?php
/** Contract data uses fixed synthetic keys only. Never print keys, JWTs or exception messages. */

use WPGraphQL\WooCommerce\Utils\QL_Session_Handler;
use WPGraphQL\WooCommerce\Vendor\Firebase\JWT\JWT;
use WPGraphQL\WooCommerce\Vendor\Firebase\JWT\Key;

function handler_contract_cases(): array {
	$strong = str_repeat( 'a', 32 );
	$other = str_repeat( 'b', 32 );
	$good = [ 'key' => $strong ];
	$cases = [
		'absent-key-absent-header' => [ 'expect' => 'unavailable' ],
		'absent-key-malformed-header' => [ 'header' => 'malformed', 'expect' => 'unavailable' ],
		'empty-key-malformed-header' => [ 'key' => '', 'header' => 'malformed', 'expect' => 'unavailable' ],
		'key-31-bytes' => [ 'key' => str_repeat( 'a', 31 ), 'expect' => 'unavailable' ],
		'key-32-bytes-fresh-guest' => $good + [ 'expect' => 'success' ],
		'key-32-binary-exact' => [ 'key' => "\0" . str_repeat( 'c', 30 ) . "\xff", 'expect' => 'success' ],
		'key-whitespace-exact' => [ 'key' => ' ' . str_repeat( 'd', 32 ) . ' ', 'token' => 'guest', 'expect' => 'success' ],
		'key-multibyte-32-bytes' => [ 'key' => str_repeat( "\xc3\xa9", 16 ), 'expect' => 'success' ],
		'key-multibyte-30-bytes' => [ 'key' => str_repeat( "\xc3\xa9", 15 ), 'expect' => 'unavailable' ],
		'filter-only-strong-key' => [ 'filter_key' => $strong, 'expect' => 'success' ],
		'constant-filter-override-exact' => $good + [ 'filter_key' => $other, 'token_key' => $other, 'token' => 'guest', 'expect' => 'success' ],
		'effective-key-cached-once' => $good + [ 'changing_filter' => true, 'token' => 'guest', 'expect' => 'success' ],
		'prepared-signing-output-cached-once' => $good + [ 'sign_cache' => true, 'token' => 'guest', 'expect' => 'success' ],
		'signing-filter-throws-before-resolver' => $good + [ 'sign_filter' => 'before-throws', 'token' => 'guest', 'expect' => 'signing-unavailable' ],
		'signed-token-filter-badtype-before-resolver' => $good + [ 'sign_filter' => 'signed-badtype', 'token' => 'guest', 'expect' => 'signing-unavailable' ],
		'signed-token-filter-padding-before-resolver' => $good + [ 'sign_filter' => 'signed-padding', 'token' => 'guest', 'expect' => 'signing-unavailable' ],
		'before-sign-boolean-account-claim-before-resolver' => $good + [ 'sign_filter' => 'before-bool-account', 'user' => 1, 'expect' => 'signing-unavailable' ],
		'weak-constant-strong-filter' => [ 'key' => 'weak', 'filter_key' => $strong, 'expect' => 'success' ],
		'strong-constant-weak-filter' => $good + [ 'filter_key' => 'weak', 'expect' => 'unavailable' ],
		'throwing-filter-malformed-header' => $good + [ 'throw_filter' => true, 'header' => 'malformed', 'expect' => 'unavailable' ],
		'throwing-header-name-filter' => $good + [ 'header_name_failure' => 'throw', 'expect' => 'unavailable' ],
		'wrong-type-header-name-filter' => $good + [ 'header_name_failure' => 'array', 'expect' => 'unavailable' ],
		'late-expiration-filter-quarantines-init' => $good + [ 'late_expiration_failure' => true, 'expect' => 'unavailable' ],
		'valid-same-account' => $good + [ 'user' => 17, 'token' => 'account', 'expect' => 'success' ],
		'fresh-authenticated-account' => $good + [ 'user' => 17, 'expect' => 'success' ],
		'valid-returning-guest' => $good + [ 'token' => 'guest', 'expect' => 'success' ],
		'cross-account-before-persisted-read' => $good + [ 'user' => 23, 'token' => 'account', 'expect' => 'invalid' ],
		'anonymous-account-before-persisted-read' => $good + [ 'token' => 'account', 'expect' => 'invalid' ],
		'guest-to-auth-before-persisted-read' => $good + [ 'user' => 17, 'token' => 'guest', 'expect' => 'invalid' ],
		'invalid-signature-no-fallback' => $good + [ 'token' => 'guest', 'token_key' => $other, 'expect' => 'invalid' ],
		'failure-cannot-revive-by-header-replacement' => $good + [ 'header' => 'malformed', 'replace_after_failure' => true, 'expect' => 'invalid' ],
		'malformed-json-payload-invalid' => $good + [ 'token' => 'guest', 'wire_payload' => '{', 'expect' => 'invalid' ],
		'malformed-json-header-invalid' => $good + [ 'token' => 'guest', 'wire_header' => '{', 'expect' => 'invalid' ],
		'expired-no-fallback' => $good + [ 'token' => 'expired', 'expect' => 'invalid' ],
		'wrong-issuer-no-fallback' => $good + [ 'token' => 'wrong-issuer', 'expect' => 'invalid' ],
		'missing-customer-no-fallback' => $good + [ 'token' => 'missing-customer', 'expect' => 'invalid' ],
		'array-customer-no-fallback' => $good + [ 'token' => 'array-customer', 'expect' => 'invalid' ],
		'object-customer-no-fallback' => $good + [ 'token' => 'object-customer', 'expect' => 'invalid' ],
		'numeric-same-account' => $good + [ 'token' => 'numeric-customer', 'user' => 17, 'expect' => 'success' ],
		'numeric-anonymous-account-no-fallback' => $good + [ 'token' => 'numeric-customer', 'expect' => 'invalid' ],
		'float-customer-no-fallback' => $good + [ 'token' => 'float-customer', 'user' => 17, 'expect' => 'invalid' ],
		'leading-zero-account-no-fallback' => $good + [ 'token' => 'leading-zero-customer', 'user' => 17, 'expect' => 'invalid' ],
		'native-invalid-header-no-cookie-fallback' => $good + [ 'graphql' => false, 'header' => 'malformed', 'expect' => 'invalid' ],
		'native-config-failure-no-cookie-fallback' => [ 'graphql' => false, 'header' => 'malformed', 'expect' => 'unavailable' ],
		'native-absent-jwt-no-key-fresh-cookie' => [ 'graphql' => false, 'expect' => 'native-success' ],
		'native-absent-jwt-no-key-returning-cookie' => [ 'graphql' => false, 'native_cookie' => true, 'expect' => 'native-success' ],
		'detached-memory-native-output-persistence-fences' => $good + [ 'token' => 'guest', 'next_user' => 17, 'expect' => 'detach' ],
		'detached-captured-customer-shutdown-and-proxy' => $good + [ 'token' => 'guest', 'next_user' => 17, 'expect' => 'captured-customer' ],
		'detached-captured-customer-displaced-global-retained' => $good + [ 'token' => 'guest', 'next_user' => 17, 'expect' => 'captured-customer', 'displace_customer' => true ],
		'quarantined-real-persistent-cart-no-user-meta' => $good + [ 'user' => 17, 'token' => 'account', 'token_key' => $other, 'expect' => 'persistent-quarantine' ],
		'accepted-real-persistent-cart-destroy-control' => $good + [ 'user' => 17, 'token' => 'account', 'expect' => 'persistent-control' ],
		'checkout-posted-createaccount-held-at-customer-step' => $good + [ 'token' => 'guest', 'checkout_probe' => 'createaccount', 'expect' => 'checkout-hold' ],
		'checkout-registration-policy-changed-after-preflight' => $good + [ 'token' => 'guest', 'checkout_probe' => 'policy-change', 'expect' => 'checkout-hold' ],
		'checkout-stateful-policy-single-decision' => $good + [ 'token' => 'guest', 'checkout_probe' => 'stateful-once', 'expect' => 'checkout-policy-once' ],
		'native-checkout-stateful-policy-single-decision' => $good + [ 'token' => 'guest', 'graphql' => false, 'checkout_probe' => 'stateful-once', 'expect' => 'checkout-policy-once' ],
	];
	foreach ( [ 'login', 'addToCart' ] as $first ) {
		foreach ( [ false, true ] as $driver_failure ) {
			$cases['actual-handler-operation-mixed-' . $first . ( $driver_failure ? '-failed-driver-literal-unavailable' : '-rejection-latched' )] = $good + [ 'token' => 'guest', 'expect' => 'operation-rejection', 'mixed_first' => $first, 'driver_failure' => $driver_failure ];
		}
	}
	foreach ( [ 'null' => null, 'false' => false, 'true' => true, 'integer' => 32, 'array' => [ $strong ], 'object' => (object) [ 'key' => $strong ] ] as $name => $key ) {
		$cases['wrong-type-filter-' . $name] = $good + [ 'filter_key' => $key, 'header' => 'malformed', 'expect' => 'unavailable' ];
	}
	foreach ( [ 'empty' => '', 'zero' => '0', 'null' => null, 'false' => false, 'array' => [], 'object' => (object) [], 'bare-scheme' => 'Session', 'scheme-space' => 'Session ', 'wrong-scheme' => 'Bearer invalid', 'garbage' => 'Session invalid', 'leading-space' => ' Session invalid', 'newline' => "Session invalid\n" ] as $name => $header ) {
		$cases['present-header-' . $name] = $good + [ 'header' => $header, 'expect' => 'invalid' ];
	}
	foreach ( [ 'trailing-text', 'lowercase-scheme', 'tab-delimiter', 'double-space', 'leading-space' ] as $name ) {
		$cases['valid-token-header-' . $name] = $good + [ 'token' => 'guest', 'header_transform' => $name, 'expect' => 'invalid' ];
	}
	foreach ( [ 'guest-to-auth' => [ 0, 17, 'guest' ], 'account-to-other' => [ 17, 23, 'account' ], 'account-to-anonymous' => [ 17, 0, 'account' ] ] as $name => [ $before, $after, $token ] ) {
		foreach ( [ 'graphql' => true, 'native' => false ] as $mode => $graphql ) {
			$cases['mid-auth-' . $mode . '-' . $name] = $good + [ 'user' => $before, 'next_user' => $after, 'token' => $token, 'graphql' => $graphql, 'expect' => 'transition' ];
		}
	}
	if ( getenv( 'WL_WOOGRAPHQL_BASELINE_SOURCE' ) ) {
		$cases['old-library-token-new-handler-and-rollback'] = $good + [ 'token' => 'guest', 'legacy_token' => 'mint-strong', 'rollback' => true, 'expect' => 'success' ];
		$cases['old-fallback-signed-token-rejected'] = $good + [ 'token' => 'guest', 'legacy_token' => 'mint-fallback', 'expect' => 'invalid' ];
		$cases['old-fallback-token-absent-key-unavailable'] = [ 'token' => 'guest', 'legacy_token' => 'mint-fallback', 'expect' => 'unavailable' ];
	}
	return $cases;
}

function contract_legacy_jwt( string $mode, ?string $token = null ): array {
	$file = tempnam( sys_get_temp_dir(), 'wl-offline-jwt-' );
	if ( false === $file ) { throw new RuntimeException( 'Unable to allocate private compatibility fixture.' ); }
	chmod( $file, 0600 );
	try {
		if ( null !== $token ) { file_put_contents( $file, $token ); }
		$pipes = [];
		$process = proc_open( [ PHP_BINARY, '-d', 'memory_limit=64M', __DIR__ . '/legacy-jwt.php', $mode, $file ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
		if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Compatibility subprocess failed.' ); }
		fclose( $pipes[0] );
		$result = json_decode( stream_get_contents( $pipes[1] ), true );
		stream_get_contents( $pipes[2] );
		fclose( $pipes[1] ); fclose( $pipes[2] );
		$status = proc_close( $process );
		return [ 'ok' => 0 === $status && true === ( $result['ok'] ?? false ), 'token' => str_starts_with( $mode, 'mint-' ) ? file_get_contents( $file ) : null ];
	} finally { unlink( $file ); }
}

function contract_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { $GLOBALS['handler_contract_failures'][] = $message; }
}

function contract_try( callable $callback ): ?Throwable {
	try { $callback(); return null; } catch ( Throwable $error ) { return $error; }
}

function contract_error_is( ?Throwable $error, string $code ): bool {
	$typed = $error instanceof \GraphQL\Error\UserError
		&& $error instanceof \GraphQL\Error\ProvidesExtensions
		&& $error->isClientSafe()
		&& $code === ( $error->getExtensions()['code'] ?? null );
	if ( ! $typed ) { return false; }
	$formatted = \GraphQL\Error\FormattedError::createFromException( $error );
	return $code === ( $formatted['extensions']['code'] ?? null );
}

function contract_field_hook( string $name ): void {
	// Each child models one execution. Reuse its genuine operation/schema identity.
	static $info = null;
	if ( null === $info ) {
		$schema = \GraphQL\Utils\BuildSchema::build( 'type Cart { isEmpty: Boolean } type Query { cart: Cart } input CheckoutInput { clientMutationId: String } type CheckoutPayload { result: Boolean } type Mutation { addToCart: Boolean checkout(input: CheckoutInput): CheckoutPayload }' );
		$is_mutation = in_array( $name, [ 'addToCart', 'checkout' ], true );
		$operation_text = 'checkout' === $name ? 'mutation Contract { checkout(input: {clientMutationId: "synthetic"}) { result } }' : ( $is_mutation ? 'mutation Contract { addToCart }' : 'query Contract { cart { isEmpty } }' );
		$document = \GraphQL\Language\Parser::parse( $operation_text );
		if ( [] !== \GraphQL\Validator\DocumentValidator::validate( $schema, $document ) ) { throw new RuntimeException( 'Synthetic GraphQL operation must validate.' ); }
		$operation = $document->definitions[0];
		$field_node = $operation->selectionSet->selections[0];
		$parent = $is_mutation ? $schema->getMutationType() : $schema->getQueryType();
		$field = $parent->getField( $name );
		$info = new \GraphQL\Type\Definition\ResolveInfo( $field, new ArrayObject( [ $field_node ] ), $parent, [ $name ], $schema, [], null, $operation, [], [ $name ] );
	}
	do_action( 'graphql_before_resolve_field', null, [], new stdClass(), $info, static fn() => null, $info->parentType->name, $info->fieldName, $info->fieldDefinition );
}

/** Genuine validated mixed mutation and ResolveInfo; no resolver/provider callback runs. */
function contract_mixed_field_hook( string $first ): void {
	$schema = \GraphQL\Utils\BuildSchema::build( 'enum Provider { PASSWORD } input LoginInput { provider: Provider! } type LoginPayload { authToken: String } type Query { placeholder: Boolean } type Mutation { login(input: LoginInput!): LoginPayload addToCart: Boolean }' );
	$schema->getType( 'Provider' )->getValue( 'PASSWORD' )->value = 'password';
	$roots = 'login' === $first ? 'login(input:{provider:PASSWORD}){authToken} addToCart' : 'addToCart login(input:{provider:PASSWORD}){authToken}';
	$document = \GraphQL\Language\Parser::parse( 'mutation Mixed { ' . $roots . ' }' );
	if ( [] !== \GraphQL\Validator\DocumentValidator::validate( $schema, $document ) ) { throw new RuntimeException( 'Mixed fixture must be a valid GraphQL document.' ); }
	$operation = $document->definitions[0]; $node = $operation->selectionSet->selections[0]; $type = $schema->getMutationType(); $field = $type->getField( $first );
	$info = new \GraphQL\Type\Definition\ResolveInfo( $field, new ArrayObject( [ $node ] ), $type, [ $first ], $schema, [], null, $operation, [], [ $first ] );
	do_action( 'graphql_before_resolve_field', null, [], new stdClass(), $info, static function () { HandlerContractBoundary::event( 'forbidden-resolver' ); }, $type->name, $first, $field );
}

function contract_protected_hooks( string $code ): void {
	$error = contract_try( static fn() => do_action( 'do_graphql_request', 'query Contract { cart { isEmpty } }', null, null, null ) );
	contract_assert( contract_error_is( $error, $code ), 'operation hook must throw a client-safe semantic error with expected code' );
	if ( HandlerContractBoundary::$graphql ) {
		$error = contract_try( static fn() => contract_field_hook( 'addToCart' ) );
		contract_assert( contract_error_is( $error, $code ), 'field hook must throw expected semantic code before transaction admission' );
	}
}

function contract_run_case( array $case ): array {
	$GLOBALS['handler_contract_failures'] = [];
	HandlerContractBoundary::$user = $case['user'] ?? 0;
	HandlerContractBoundary::$graphql = $case['graphql'] ?? true;
	$key_filter_calls = 0;
	$expiration_filter_calls = 0;
	$sign_filter_calls = [ 'before' => 0, 'signed' => 0 ];
	$_SERVER = $_COOKIE = $_GET = [];
	if ( $case['native_cookie'] ?? false ) {
		$guest = 't_' . str_repeat( 'e', 30 );
		$expiration = time() + 3600;
		$_COOKIE['wp_woocommerce_session_offline-contract'] = $guest . '|' . $expiration . '|' . ( time() + 1800 ) . '|' . wp_fast_hash( $guest . '|' . $expiration );
		HandlerContractBoundary::$rows[$guest] = [ 'cart' => 'synthetic-original-cart' ];
	}
	if ( array_key_exists( 'key', $case ) ) { define( 'GRAPHQL_WOOCOMMERCE_SECRET_KEY', $case['key'] ); }
	if ( array_key_exists( 'filter_key', $case ) ) {
		add_filter( 'graphql_woocommerce_secret_key', static fn( $unused ) => $case['filter_key'] );
	}
	if ( $case['changing_filter'] ?? false ) {
		add_filter( 'graphql_woocommerce_secret_key', static function () use ( &$key_filter_calls ) {
			++$key_filter_calls;
			return str_repeat( 1 === $key_filter_calls ? 'a' : 'b', 32 );
		} );
	}
	if ( isset( $case['sign_filter'] ) || ( $case['sign_cache'] ?? false ) ) {
		add_filter( 'graphql_woocommerce_cart_session_before_token_sign', static function ( $value ) use ( $case, &$sign_filter_calls ) {
			++$sign_filter_calls['before'];
			if ( 'before-throws' === ( $case['sign_filter'] ?? '' ) ) { throw new RuntimeException( 'Synthetic signing filter failure.' ); }
			if ( 'before-bool-account' === ( $case['sign_filter'] ?? '' ) ) { $value['data']['customer_id'] = true; }
			return $value;
		} );
		add_filter( 'graphql_woocommerce_cart_session_signed_token', static function ( $value ) use ( $case, &$sign_filter_calls ) {
			++$sign_filter_calls['signed'];
			if ( 'signed-padding' === ( $case['sign_filter'] ?? '' ) ) { return $value . '='; }
			return 'signed-badtype' === ( $case['sign_filter'] ?? '' ) ? [ 'synthetic-invalid-result' ] : $value;
		} );
	}
	if ( $case['throw_filter'] ?? false ) {
		add_filter( 'graphql_woocommerce_secret_key', static function () { throw new RuntimeException( 'Synthetic filter failure.' ); } );
	}
	if ( isset( $case['header_name_failure'] ) ) {
		add_filter( 'graphql_woocommerce_cart_session_http_header', static function () use ( $case ) {
			if ( 'throw' === $case['header_name_failure'] ) { throw new RuntimeException( 'Synthetic header-name configuration failure.' ); }
			return [ 'synthetic-invalid-header-name' ];
		} );
	}
	if ( $case['late_expiration_failure'] ?? false ) {
		add_filter( 'wc_session_expiration', static function ( $value ) use ( &$expiration_filter_calls ) {
			if ( 2 === ++$expiration_filter_calls ) { throw new RuntimeException( 'Synthetic late expiration failure.' ); }
			return $value;
		} );
	}
	$customer = 'guest' === ( $case['token'] ?? '' ) ? 't_' . str_repeat( 'e', 30 ) : '17';
	if ( isset( $case['token'] ) ) {
		$now = time();
		$claims = [ 'iss' => get_bloginfo( 'url' ), 'iat' => $now - 120, 'nbf' => $now - 120, 'exp' => $now + 3600, 'data' => [ 'customer_id' => $customer ] ];
		switch ( $case['token'] ) {
			case 'expired': $claims['exp'] = $now - 300; break;
			case 'wrong-issuer': $claims['iss'] = 'https://other.example.invalid'; break;
			case 'missing-customer': $claims['data'] = []; break;
			case 'array-customer': $claims['data']['customer_id'] = [ $customer ]; break;
			case 'object-customer': $claims['data']['customer_id'] = (object) [ 'id' => $customer ]; break;
			case 'numeric-customer': $claims['data']['customer_id'] = 17; break;
			case 'float-customer': $claims['data']['customer_id'] = 17.5; break;
			case 'leading-zero-customer': $claims['data']['customer_id'] = '017'; break;
		}
		$effective_key = $case['token_key'] ?? $case['filter_key'] ?? $case['key'] ?? str_repeat( 'a', 32 );
		$jwt = JWT::encode( $claims, $effective_key, 'HS256' );
		if ( isset( $case['wire_header'] ) || isset( $case['wire_payload'] ) ) {
			$segments = JWT::urlsafeB64Encode( $case['wire_header'] ?? '{"typ":"JWT","alg":"HS256"}' ) . '.'
				. JWT::urlsafeB64Encode( $case['wire_payload'] ?? json_encode( $claims, JSON_THROW_ON_ERROR ) );
			$jwt = $segments . '.' . JWT::urlsafeB64Encode( hash_hmac( 'sha256', $segments, $effective_key, true ) );
		}
		if ( isset( $case['legacy_token'] ) ) {
			$legacy = contract_legacy_jwt( $case['legacy_token'] );
			contract_assert( $legacy['ok'], 'actual legacy bundled JWT library must mint synthetic compatibility fixture' );
			$jwt = $legacy['token'];
		}
		$header = 'Session ' . $jwt;
		switch ( $case['header_transform'] ?? '' ) {
			case 'trailing-text': $header .= ' ignored'; break;
			case 'lowercase-scheme': $header = 'session ' . $jwt; break;
			case 'tab-delimiter': $header = "Session\t" . $jwt; break;
			case 'double-space': $header = 'Session  ' . $jwt; break;
			case 'leading-space': $header = ' ' . $header; break;
		}
		$_SERVER['HTTP_WOOCOMMERCE_SESSION'] = $header;
		HandlerContractBoundary::$rows[$customer] = [ 'cart' => 'synthetic-original-cart' ];
	}
	if ( array_key_exists( 'header', $case ) ) { $_SERVER['HTTP_WOOCOMMERCE_SESSION'] = $case['header']; }
	$handler = null;
	$construct_error = contract_try( static function () use ( &$handler ) { $handler = new QL_Session_Handler(); } );
	contract_assert( null === $construct_error, 'constructor must not throw before GraphQL error formatting boundary' );
	if ( null === $handler ) { return contract_result(); }
	HandlerContractBoundary::$woocommerce = (object) [ 'session' => $handler, 'customer' => null, 'cart' => null ];
	$start = count( HandlerContractBoundary::$events );
	$init_error = contract_try( static fn() => $handler->init() );
	contract_assert( null === $init_error, 'init must quarantine failure without throwing before GraphQL formatting boundary' );
	$after_init = count( HandlerContractBoundary::$events );
	if ( in_array( $case['expect'], [ 'persistent-control', 'persistent-quarantine' ], true ) ) {
		$start = count( HandlerContractBoundary::$events );
		$cart_session = ( new ReflectionClass( WC_Cart_Session::class ) )->newInstanceWithoutConstructor();
		if ( 'persistent-control' === $case['expect'] ) {
			$cart_session->persistent_cart_destroy();
			contract_assert( 1 === HandlerContractBoundary::count( [ 'user-meta-delete' ], $start ), 'accepted account positive control must reach actual persistent_cart_destroy user-meta boundary' );
		} else {
			$cart_session->persistent_cart_update(); $cart_session->persistent_cart_destroy();
			contract_assert( 2 === HandlerContractBoundary::count( [ 'persistent-cart-policy' ], $start ), 'actual persistent cart methods must each consult session-scoped policy' );
			contract_assert( 0 === HandlerContractBoundary::count( [ 'user-meta-write', 'user-meta-delete' ], $start ), 'quarantined authenticated handler must suppress actual persistent cart user-meta effects' );
			contract_protected_hooks( 'WL_CART_SESSION_INVALID' );
		}
		return contract_result();
	}
	if ( in_array( $case['expect'], [ 'invalid', 'unavailable' ], true ) ) {
		if ( $case['late_expiration_failure'] ?? false ) { contract_assert( 2 === $expiration_filter_calls, 'late expiration fixture must throw during actual init after successful constructor invocation' ); }
		$code = 'invalid' === $case['expect'] ? 'WL_CART_SESSION_INVALID' : 'WL_CART_SESSION_UNAVAILABLE';
		if ( $case['replace_after_failure'] ?? false ) {
			$now = time();
			$replacement = JWT::encode( [ 'iss' => get_bloginfo( 'url' ), 'iat' => $now - 120, 'nbf' => $now - 120, 'exp' => $now + 3600, 'data' => [ 'customer_id' => 't_' . str_repeat( 'e', 30 ) ] ], $case['key'], 'HS256' );
			$_SERVER['HTTP_WOOCOMMERCE_SESSION'] = 'Session ' . $replacement;
			$error = contract_try( static fn() => $handler->init_session_token() );
			contract_assert( null === $error && is_wp_error( $handler->get_session_token() ), 'failed handler must remain quarantined after replacing header with a valid token' );
		}
		contract_assert( '' === $handler->get_customer_id(), 'rejected initialization must not bind or generate a customer identity' );
		contract_assert( ! $handler->sending_token() && ! $handler->sending_cookie(), 'rejected initialization must not mark token or cookie for emission' );
		contract_protected_hooks( $code );
		$handler->set( 'contract_probe', 'synthetic-dirty-data' );
		foreach ( [ static fn() => $handler->save_data(), static fn() => $handler->save_if_dirty(), static fn() => $handler->reload_data(), static fn() => $handler->build_token(), static fn() => do_action( 'shutdown' ) ] as $probe ) { contract_try( $probe ); }
		$headers = [];
		contract_try( static function () use ( &$headers ) { $headers = apply_filters( 'graphql_response_headers_to_send', [] ); } );
		contract_assert( ! array_key_exists( 'woocommerce-session', $headers ), 'rejected request must not emit a cart session response header' );
		contract_assert( 0 === HandlerContractBoundary::count( [ 'cache-read', 'account-read', 'db-read', 'db-write', 'db-delete', 'cache-write', 'cache-delete', 'timestamp-write', 'transient-read', 'transient-write', 'transient-delete' ], $start ), 'rejection and later probes must perform no persistence read, write, delete, timestamp update or queue access' );
		contract_assert( 0 === HandlerContractBoundary::count( [ 'expiration-change', 'identity-generated', 'token-built', 'cookie-emitted' ], ( $case['late_expiration_failure'] ?? false ) ? $after_init : $start ), 'rejection must not refresh expiration, generate identity, build token or emit cookie' );
		return contract_result();
	}
	contract_assert( null === $init_error, 'accepted fixture initialization must succeed' );
	if ( HandlerContractBoundary::$graphql ) {
		contract_assert( $handler->has_owned_scope() && 'active' === $GLOBALS['wpdb']->state, 'accepted GraphQL fixture must hold the actual checked storage opaque scope' );
		contract_assert( 1 === HandlerContractBoundary::count( [ 'lifecycle-install' ] ) && 1 === HandlerContractBoundary::count( [ 'scope-begin' ] ), 'accepted GraphQL fixture must install lifecycle and acquire exactly once' );
	}
	if ( 'operation-rejection' === $case['expect'] ) {
		$handler->set( 'contract_probe', 'discarded-dirty-data' ); $rows_before = HandlerContractBoundary::$rows;
		$start = count( HandlerContractBoundary::$events ); $translations_before = HandlerContractBoundary::$translation_calls;
		if ( $case['driver_failure'] ) { $GLOBALS['wpdb']->state = 'failed'; HandlerContractBoundary::$throw_translation = true; }
		$error = contract_try( static fn() => contract_mixed_field_hook( $case['mixed_first'] ) );
		$code = $case['driver_failure'] ? 'WL_CART_SESSION_UNAVAILABLE' : 'WL_CART_SESSION_TRANSITION_INVALID';
		contract_assert( contract_error_is( $error, $code ), 'actual coordinator rejection must use actual handler semantic classification' );
		contract_assert( $handler->has_owned_scope(), 'mixed operation rejection must retain captured abortable scope until explicit cleanup' );
		contract_assert( $case['driver_failure'] ? ! $handler->has_session_rejection() : $handler->has_session_rejection(), 'actual coordinator must latch healthy rejection and preserve unavailable driver classification' );
		if ( $case['driver_failure'] ) {
			contract_assert( $error && 'The cart session is temporarily unavailable.' === $error->getMessage() && $translations_before === HandlerContractBoundary::$translation_calls, 'driver failure must produce literal unavailable before any throwing translation/transition formatter' );
		} else {
			contract_assert( null === contract_try( static fn() => $handler->assert_response_available() ), 'healthy mixed rejection may deliver existing GraphQL errors through discard' );
		}
		contract_assert( contract_error_is( contract_try( static fn() => $handler->complete_owned_scope() ), $code ), 'mixed operation cannot run successful checked completion or flush old dirty state' );
		$handler->discard_owned_scope();
		contract_assert( ! $handler->has_owned_scope() && 1 === HandlerContractBoundary::count( [ 'scope-abort' ], $start ), 'rejected mixed operation cleanup must abort exact captured scope once' );
		contract_assert( $rows_before === HandlerContractBoundary::$rows && 0 === HandlerContractBoundary::count( [ 'db-read', 'account-read', 'db-write', 'db-delete', 'cache-read', 'cache-write', 'cache-delete', 'timestamp-write', 'token-built', 'forbidden-resolver', 'account-created', 'auth-cookie-emitted' ], $start ), 'mixed rejection and cleanup must have no successful persistence, credential or resolver effects' );
		return contract_result();
	}

	if ( in_array( $case['expect'], [ 'checkout-hold', 'checkout-policy-once' ], true ) ) {
		HandlerContractBoundary::$woocommerce = (object) [ 'session' => $handler ];
		contract_assert( false === \WPGraphQL\WooCommerce\Data\Mutation\Checkout_Mutation::is_registration_required(), 'checkout policy must initially permit guest preflight' );
		if ( HandlerContractBoundary::$graphql ) {
			$error = contract_try( static fn() => contract_field_hook( 'checkout' ) );
			contract_assert( null === $error, 'original ordinary guest checkout operation must pass actual coordinator preflight' );
		}
		$rows_before = HandlerContractBoundary::$rows;
		$start = count( HandlerContractBoundary::$events );
		$data = [ 'createaccount' => 'createaccount' === $case['checkout_probe'], 'billing_email' => 'offline-customer@example.invalid' ];
		if ( 'policy-change' === $case['checkout_probe'] ) { HandlerContractBoundary::$registration_required = true; }
		$policy_calls = 0;
		if ( 'stateful-once' === $case['checkout_probe'] ) {
			// After ordinary preflight, false at the creation decision and true on
			// every later call. A separate hold check must never invite a second read.
			add_filter( 'woocommerce_checkout_registration_required', static function ( $unused ) use ( &$policy_calls ) { return 1 !== ++$policy_calls; } );
		}
		$method = new ReflectionMethod( \WPGraphQL\WooCommerce\Data\Mutation\Checkout_Mutation::class, 'process_customer' );
		$error = contract_try( static fn() => $method->invoke( null, $data ) );
		if ( 'checkout-policy-once' === $case['expect'] ) {
			contract_assert( null === $error && 1 === $policy_calls, 'actual checkout customer step must use one creation-policy decision despite a filter changing its next result' );
		} else {
			contract_assert( contract_error_is( $error, 'WL_CART_SESSION_TRANSITION_INVALID' ), 'actual checkout customer step must reject new registration intent/policy after original preflight' );
		}
		contract_assert( $rows_before === HandlerContractBoundary::$rows && 0 === HandlerContractBoundary::$user, 'held checkout customer step must preserve rows and anonymous identity' );
		contract_assert( 0 === HandlerContractBoundary::count( [ 'account-created', 'auth-cookie-emitted', 'order-created', 'db-read', 'db-write', 'db-delete', 'cache-read', 'cache-write', 'cache-delete', 'timestamp-write', 'user-meta-write', 'user-meta-delete', 'cookie-emitted' ], $start ), 'held checkout customer step must precede account/auth-cookie/order and persistence boundaries' );
		return contract_result();
	}
	if ( in_array( $case['expect'], [ 'detach', 'captured-customer' ], true ) ) {
		$rows_before = HandlerContractBoundary::$rows;
		$original_id = $handler->get_customer_id();
		$proxy = null;
		$displaced = null;
		$unrelated_calls = 0;
		if ( 'captured-customer' === $case['expect'] ) {
			$proxy = new HandlerContractCustomerProxy( $handler );
			HandlerContractBoundary::$woocommerce->customer = $proxy;
			$handler->get_owned_lifecycle()->capture_customer( $proxy );
			add_action( 'shutdown', [ $proxy, 'save' ], 10 );
			add_action( 'shutdown', static function () use ( &$unrelated_calls ) { ++$unrelated_calls; }, 10 );
			if ( $case['displace_customer'] ?? false ) {
				$displaced = new HandlerContractCustomerProxy( $handler );
				HandlerContractBoundary::$woocommerce->customer = $displaced;
				add_action( 'shutdown', [ $displaced, 'save' ], 10 );
			}
		}
		$start = count( HandlerContractBoundary::$events );
		$handler->detach_for_auth();
		contract_assert( 'synthetic-default' === $handler->get( 'cart', 'synthetic-default' ) && null === $handler->cart, 'detachment must gate direct and magic reads before current identity changes' );
		$handler->set( 'cart', 'synthetic-replacement' ); $handler->cart = 'synthetic-magic-replacement'; unset( $handler->cart );
		contract_assert( ! isset( $handler->cart ) && 'synthetic-default' === $handler->get( 'cart', 'synthetic-default' ), 'detachment must make direct and magic setters/unset inert' );
		HandlerContractBoundary::$user = $case['next_user'];
		$handler->init_session_cookie();
		contract_assert( 'synthetic-default' === $handler->get_session( $original_id, 'synthetic-default' ), 'detached handler must not read persistence through inherited/native entrypoints' );
		$handler->reload_data(); $handler->update_session_timestamp( $original_id, time() + 3600 ); $handler->delete_session( $original_id );
		$handler->save_data(); $handler->save_if_dirty();
		contract_assert( false === $handler->build_token() && null === $handler->build_customer_token(), 'detached handler must expose no header/body/customer token' );
		contract_assert( ! isset( apply_filters( 'graphql_response_headers_to_send', [] )['woocommerce-session'] ), 'detached header callback must expose no token' );
		do_action( 'shutdown' );
		if ( null !== $proxy ) {
			contract_assert( 0 === $proxy->calls && 1 === $unrelated_calls, 'detachment must remove only captured customer shutdown callback and retain unrelated callback at same priority' );
			if ( $displaced ) {
				contract_assert( 1 === $displaced->calls && $displaced->blocked_read, 'GraphQL detachment must leave displaced global customer callback registered while actual handler keeps its writes inert' );
			}
			$proxy->save();
			contract_assert( 1 === $proxy->calls && $proxy->blocked_read, 'direct captured customer proxy save must hit actual handler guarded get/set while remaining inert' );
		} else {
			$cart_session = ( new ReflectionClass( WC_Cart_Session::class ) )->newInstanceWithoutConstructor();
			$cart_session->persistent_cart_update(); $cart_session->persistent_cart_destroy();
			contract_assert( 2 === HandlerContractBoundary::count( [ 'persistent-cart-policy' ], $start ), 'actual detached persistent cart methods must consult scoped policy without accessing cart' );
		}
		contract_protected_hooks( 'WL_CART_SESSION_TRANSITION_INVALID' );
		contract_assert( $rows_before === HandlerContractBoundary::$rows, 'detachment and captured callbacks must preserve original persisted rows' );
		contract_assert( 0 === HandlerContractBoundary::count( [ 'account-read', 'db-read', 'db-write', 'db-delete', 'cache-read', 'cache-write', 'cache-delete', 'timestamp-write', 'user-meta-write', 'user-meta-delete', 'cookie-emitted', 'token-built', 'transient-read', 'transient-write', 'transient-delete' ], $start ), 'detachment probes must have no persistence, metadata, queue or emission effects' );
		return contract_result();
	}
	if ( 'signing-unavailable' === $case['expect'] ) {
		contract_assert( 0 === HandlerContractBoundary::count( [ 'timestamp-write', 'db-write', 'db-delete' ], $start ), 'initialization must defer expiry and DB writes until signing preparation succeeds' );
		$handler->set( 'contract_probe', 'synthetic-dirty-data' );
		$start = count( HandlerContractBoundary::$events );
		$error = contract_try( static fn() => do_action( 'do_graphql_request', 'query Contract { cart { isEmpty } }', null, null, null ) );
		contract_assert( null === $error || contract_error_is( $error, 'WL_CART_SESSION_UNAVAILABLE' ), 'first operation boundary may defer signing to first validated field or report semantic unavailable' );
		$error = contract_try( static fn() => contract_field_hook( 'addToCart' ) );
		contract_assert( contract_error_is( $error, 'WL_CART_SESSION_UNAVAILABLE' ), 'signing must fail semantically at first field boundary before queue or resolver work' );
		contract_protected_hooks( 'WL_CART_SESSION_UNAVAILABLE' );
		foreach ( [ static fn() => $handler->save_data(), static fn() => $handler->save_if_dirty(), static fn() => $handler->reload_data(), static fn() => do_action( 'shutdown' ) ] as $probe ) { contract_try( $probe ); }
		$token = false;
		$error = contract_try( static function () use ( $handler, &$token ) { $token = $handler->build_token(); } );
		contract_assert( null === $error || contract_error_is( $error, 'WL_CART_SESSION_UNAVAILABLE' ), 'failed body token getter must return false or a semantic unavailable error' );
		$headers = apply_filters( 'graphql_response_headers_to_send', [] );
		contract_assert( false === $token && ! isset( $headers['woocommerce-session'] ), 'signing failure must produce no body token or response header' );
		contract_assert( '' === $handler->get_customer_id(), 'signing failure must quarantine bound session before resolver can run' );
		contract_assert( 1 === $sign_filter_calls['before'] && ( 'before-throws' === $case['sign_filter'] ? 0 : 1 ) === $sign_filter_calls['signed'], 'signing failure must not retry filters on field/body/header/shutdown paths' );
		contract_assert( 0 === HandlerContractBoundary::count( [ 'cache-read', 'account-read', 'db-read', 'db-write', 'db-delete', 'cache-write', 'cache-delete', 'timestamp-write', 'cookie-emitted', 'transient-read', 'transient-write', 'transient-delete' ], $start ), 'signing rejection must precede queue access and all resolver persistence effects' );
		return contract_result();
	}
	if ( 'native-success' === $case['expect'] ) {
		$expected = 't_' . str_repeat( ( $case['native_cookie'] ?? false ) ? 'e' : 'f', 30 );
		contract_assert( $expected === $handler->get_customer_id(), 'native absent-JWT path must retain independently generated or cookie-bound identity' );
		if ( $case['native_cookie'] ?? false ) { contract_assert( 'synthetic-original-cart' === $handler->get( 'cart' ), 'native returning cookie must restore via genuine WooCommerce session path' ); }
		$handler->set_customer_session_cookie( true );
		$handler->set( 'contract_probe', 'synthetic-dirty-data' );
		$error = contract_try( static fn() => do_action( 'shutdown' ) );
		contract_assert( null === $error && 'synthetic-dirty-data' === ( HandlerContractBoundary::$rows[$expected]['contract_probe'] ?? null ), 'native cookie shutdown must save through real WooCommerce without requiring JWT key' );
		contract_assert( false === $handler->build_token() && ! isset( apply_filters( 'graphql_response_headers_to_send', [] )['woocommerce-session'] ), 'native absent-JWT path must emit no JWT' );
		return contract_result();
	}
	if ( 'transition' === $case['expect'] ) {
		contract_try( static fn() => do_action( 'do_graphql_request', 'query Contract { cart { isEmpty } }', null, null, null ) );
		if ( HandlerContractBoundary::$graphql ) { contract_try( static fn() => contract_field_hook( 'cart' ) ); }
		$handler->set( 'contract_probe', 'synthetic-dirty-data' );
		$before_id = $handler->get_customer_id();
		$start = count( HandlerContractBoundary::$events );
		HandlerContractBoundary::$user = $case['next_user'];
		foreach ( [ static fn() => do_action( 'shutdown' ), static fn() => $handler->save_data(), static fn() => $handler->save_if_dirty(), static fn() => $handler->reload_data() ] as $probe ) {
			$error = contract_try( $probe );
			contract_assert( null === $error, 'mid-auth shutdown/save/reload must quietly suppress effects' );
		}
		$token = null;
		$error = contract_try( static function () use ( $handler, &$token ) { $token = $handler->build_token(); } );
		contract_assert( null === $error && false === $token, 'mid-auth build_token must quietly refuse a token' );
		$headers = [];
		contract_try( static function () use ( &$headers ) { $headers = apply_filters( 'graphql_response_headers_to_send', [] ); } );
		contract_assert( ! isset( $headers['woocommerce-session'] ), 'mid-auth response header must contain no token' );
		contract_assert( $before_id === $handler->get_customer_id() || '' === $handler->get_customer_id(), 'mid-auth handler must never relabel cart data to new principal' );
		contract_protected_hooks( 'WL_CART_SESSION_TRANSITION_INVALID' );
		contract_assert( 0 === HandlerContractBoundary::count( [ 'cache-read', 'account-read', 'db-read', 'db-write', 'db-delete', 'cache-write', 'cache-delete', 'timestamp-write', 'token-built', 'cookie-emitted', 'transient-read', 'transient-write', 'transient-delete' ], $start ), 'mid-auth probes and protected hooks must have no persistence, queue or emission effects' );
		return contract_result();
	}
	$expected = HandlerContractBoundary::$user > 0 ? (string) HandlerContractBoundary::$user : ( isset( $case['token'] ) ? $customer : 't_' . str_repeat( 'f', 30 ) );
	contract_assert( $expected === (string) $handler->get_customer_id(), 'successful request must bind only expected synthetic identity' );
	if ( isset( $case['token'] ) ) {
		contract_assert( 'synthetic-original-cart' === $handler->get( 'cart' ), 'valid bearer must restore original synthetic session data' );
		contract_assert( HandlerContractBoundary::count( [ 'db-read' ], $start ) > 0, 'valid returning bearer must reach the actual checked storage or native WC persistence path' );
	}
	$error = contract_try( static fn() => do_action( 'do_graphql_request', 'query Contract { cart { isEmpty } }', null, null, null ) );
	contract_assert( null === $error, 'valid session must pass real registered operation guard before token output' );
	$error = contract_try( static fn() => contract_field_hook( 'cart' ) );
	contract_assert( null === $error, 'valid session must pass registered cart field guard before token output' );
	$handler->set_customer_session_token( true );
	$headers = apply_filters( 'graphql_response_headers_to_send', [] );
	$effective_key = $case['filter_key'] ?? $case['key'] ?? null;
	$decoded = null;
	$error = contract_try( static function () use ( &$decoded, $headers, $effective_key ) {
		$decoded = JWT::decode( $headers['woocommerce-session'] ?? '', new Key( $effective_key, 'HS256' ) );
	} );
	contract_assert( null === $error && $expected === (string) ( $decoded->data->customer_id ?? '' ), 'successful token output must verify using exact effective key bytes and bound identity' );
	if ( $case['rollback'] ?? false ) {
		$rollback = contract_legacy_jwt( 'decode-strong', $headers['woocommerce-session'] ?? '' );
		contract_assert( $rollback['ok'], 'new handler token must verify under actual legacy bundled JWT library with same strong key' );
	}
	$handler->set( 'contract_probe', 'synthetic-dirty-data' );
	$handler->save_if_dirty();
	contract_assert( 'synthetic-dirty-data' === ( HandlerContractBoundary::$rows[$expected]['contract_probe'] ?? null ), 'accepted dirty save must persist only expected identity through actual checked storage or native WC save_data' );
	if ( $case['changing_filter'] ?? false ) { contract_assert( 1 === $key_filter_calls, 'effective key filter must resolve once per handler and remain stable through verification and issuance' ); }
	if ( $case['sign_cache'] ?? false ) {
		$handler->build_token(); $handler->build_token();
		apply_filters( 'graphql_response_headers_to_send', [] );
		contract_assert( 1 === $sign_filter_calls['before'] && 1 === $sign_filter_calls['signed'], 'guard must prepare signed token once; body/header reads must reuse it without signing or filters' );
	}
	return contract_result();
}

function contract_result(): array {
	$counts = [];
	foreach ( HandlerContractBoundary::$events as $event ) { $counts[$event['kind']] = ( $counts[$event['kind']] ?? 0 ) + 1; }
	return [ 'failures' => $GLOBALS['handler_contract_failures'], 'effects' => $counts ];
}

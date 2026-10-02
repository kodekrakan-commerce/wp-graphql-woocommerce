<?php
/**
 * Native PHP lifecycle contracts using actual external plugin.php/WP_Hook and
 * actual retained lifecycle/HTTP/errors + GraphQL formatter. WC objects, session
 * storage and Router are explicitly controlled, source-pinned substitutes.
 * No WordPress bootstrap, DB, payment, durable order or installed-cohort proof.
 * Set WL_WORDPRESS_SOURCE, WL_WPGRAPHQL_SOURCE, WL_MU_PLUGINS_SOURCE. PHP8.2+.
 */
error_reporting( E_ALL ); ini_set( 'display_errors', '0' ); ini_set( 'log_errors', '0' );
const LIFECYCLE_FIXTURE_SHA = 'fb5bfe50abdd203db83c322ec1bb8fcb1f17fac4efa090f751521dbe46de1205';
const LIFECYCLE_WP_HOOK_SHA = 'b839c0e5672246bca8db1ab781ec8835f7732f253c375a237cbf6ec536e8d12e';
$endpoint = __DIR__ . '/cart-session-lifecycle-fixtures.php';
$owner = dirname( __DIR__, 2 );
$wp = rtrim( getenv( 'WL_WORDPRESS_SOURCE' ) ?: '', '/' );
$gql = rtrim( getenv( 'WL_WPGRAPHQL_SOURCE' ) ?: '', '/' );
$mu = rtrim( getenv( 'WL_MU_PLUGINS_SOURCE' ) ?: '', '/' );
if ( ! is_file( $wp . '/wp-includes/plugin.php' ) || ! is_file( $gql . '/vendor/autoload.php' ) || ! is_file( $mu . '/database/class-owned-scope-error.php' )
	|| ! hash_equals( LIFECYCLE_FIXTURE_SHA, hash_file( 'sha256', $endpoint ) )
	|| ! hash_equals( LIFECYCLE_WP_HOOK_SHA, hash_file( 'sha256', $wp . '/wp-includes/class-wp-hook.php' ) ) ) {
	fwrite( STDERR, "Configure genuine source inputs; the reviewed fixture/WP_Hook hashes must match.\n" ); exit( 2 );
}
putenv( 'WL_LIFECYCLE_FIXTURE_SHA=' . LIFECYCLE_FIXTURE_SHA );
$failure_body = '{"errors":[{"message":"The cart session is temporarily unavailable.","extensions":{"code":"WL_CART_SESSION_UNAVAILABLE"}}]}';
$total = 0; $failures = 0;
$source_files = [ 'lifecycle' => $owner . '/includes/utils/class-cart-session-lifecycle.php', 'http' => $owner . '/includes/utils/class-cart-session-http-boundary.php', 'cart_error' => $owner . '/includes/utils/class-cart-session-error.php', 'operation' => $owner . '/includes/utils/class-cart-session-operation.php', 'plugin.php' => $wp . '/wp-includes/plugin.php' ];
$source_before = array_map( fn( $file ) => hash_file( 'sha256', $file ), $source_files );
function lifecycle_expect( $condition ) { if ( ! $condition ) { throw new RuntimeException( 'Sanitized lifecycle assertion failed.' ); } }
function lifecycle_case( $name, callable $action ) {
	global $total, $failures; $total++;
	try { $action(); echo 'PASS ' . $name . PHP_EOL; }
	catch ( Throwable $ignored ) { $failures++; echo 'FAIL ' . $name . ' [sanitized assertion or controlled boundary failure]' . PHP_EOL; }
}
function lifecycle_child( $case ) {
	global $endpoint;
	$ledger = tempnam( sys_get_temp_dir(), 'wl-lifecycle-' ); chmod( $ledger, 0600 );
	try {
		$process = proc_open( [ PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', '-d', 'output_buffering=0', $endpoint, $case, $ledger ],
			[ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
		lifecycle_expect( is_resource( $process ) ); fclose( $pipes[0] );
		$body = stream_get_contents( $pipes[1] ); $stderr = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] );
		$code = proc_close( $process ); $state = json_decode( file_get_contents( $ledger ), true );
		lifecycle_expect( 0 === $code && '' === $stderr && is_array( $state ) );
		return [ $body, $state ];
	} finally { unlink( $ledger ); }
}
function lifecycle_events( $state, $names ) { return array_values( array_filter( $state['events'], fn( $event ) => in_array( $event, $names, true ) ) ); }
function lifecycle_success( $case ) {
	[ $body, $state ] = lifecycle_child( $case );
	lifecycle_expect( [ 'data' => [ 'synthetic' => true, 'serialized_before_writers' => true ] ] === json_decode( $body, true ) );
	lifecycle_expect( true === $state['created_before_cart_assignment'] && true === $state['initial_cart_enabled'] && true === $state['cleanup_terminal'] && false === $state['owned_after'] );
	lifecycle_expect( [ 'serialize', 'customer.save', 'cart.set_session', 'cart.persistent', 'cart.cookies', 'scope.complete' ] === lifecycle_events( $state, [ 'serialize', 'customer.save', 'cart.set_session', 'cart.persistent', 'cart.cookies', 'scope.complete', 'scope.discard', 'handler.shutdown.save' ] ) );
	lifecycle_expect( 1 === count( array_filter( $state['events'], fn( $event ) => 'unrelated.callback' === $event ) ) && true === $state['retained_unrelated_cart_callback'] );
	return $state;
}
foreach ( [ 'success', 'qualified-method', 'qualified-closure', 'qualified-all', 'rearmed-max', 'writer-readded-at-flush' ] as $case ) {
	lifecycle_case( $case . ' actual filter preservation and preencode/write/release order', function () use ( $case ) {
		$state = lifecycle_success( $case );
		if ( 'qualified-method' === $case ) { lifecycle_expect( in_array( 'known.receiver', $state['events'], true ) ); }
		if ( 'qualified-closure' === $case ) { lifecycle_expect( in_array( 'known.closure', $state['events'], true ) ); }
		if ( 'qualified-all' === $case ) { lifecycle_expect( in_array( 'known.all.graphql_process_http_request_response', $state['events'], true ) ); }
		if ( 'rearmed-max' === $case ) { lifecycle_expect( 2 === count( array_filter( $state['events'], fn( $event ) => 'known.response' === $event ) ) ); }
	} );
}
lifecycle_case( 'filtered header map is preserved by entry guard and prepared header callback', function () {
	$state = lifecycle_success( 'header-map-preserved' ); lifecycle_expect( true === $state['header_map_preserved'] );
} );
lifecycle_case( 'disabled secondary cart returns false without adopting it', function () {
	$state = lifecycle_success( 'disabled-secondary' ); lifecycle_expect( true === $state['secondary_disabled'] );
} );
foreach ( [ 'serialize-throw', 'serialize-invalid', 'replacement-customer', 'replacement-cart', 'replacement-handler', 'replacement-user', 'replacement-backref', 'ownership-failure' ] as $case ) {
	lifecycle_case( $case . ' withholds response and skips successful writes', function () use ( $case, $failure_body ) {
		[ $body, $state ] = lifecycle_child( $case ); lifecycle_expect( $failure_body === $body );
		lifecycle_expect( [ 'scope.discard' ] === lifecycle_events( $state, [ 'customer.save', 'cart.set_session', 'cart.persistent', 'cart.cookies', 'scope.complete', 'scope.discard', 'handler.shutdown.save' ] ) );
		lifecycle_expect( true === $state['cleanup_terminal'] && false === $state['owned_after'] );
	} );
}
foreach ( [ 'customer-failure', 'customer-replaces-cart', 'cart-failure', 'complete-failure', 'cohort-changed-at-flush', 'post-release-rejection' ] as $case ) {
	lifecycle_case( $case . ' checked finalization failure cleans once without shutdown replay', function () use ( $case, $failure_body ) {
		[ $body, $state ] = lifecycle_child( $case ); lifecycle_expect( $failure_body === $body );
		lifecycle_expect( 1 === count( array_filter( $state['events'], fn( $event ) => 'scope.discard' === $event ) ) );
		lifecycle_expect( ! in_array( 'handler.shutdown.save', $state['events'], true ) && 1 === count( array_filter( $state['events'], fn( $event ) => 'customer.save' === $event ) ) );
		if ( in_array( $case, [ 'customer-failure', 'customer-replaces-cart', 'cohort-changed-at-flush' ], true ) ) { lifecycle_expect( ! in_array( 'cart.set_session', $state['events'], true ) ); }
	} );
}
foreach ( [ 'unknown-before-install', 'unknown-all', 'manifest-wrong-source', 'manifest-unstable', 'manifest-streaming', 'manifest-wrong-signature', 'source-wrong', 'source-missing', 'unknown-after-freeze', 'registry-changed', 'callback-arguments-changed', 'receiver-changed', 'all-after-freeze', 'header-cohort-changed', 'auth-cohort-changed' ] as $case ) {
	lifecycle_case( $case . ' refused before relevant callback and write effects', function () use ( $case, $failure_body ) {
		[ $body, $state ] = lifecycle_child( $case ); lifecycle_expect( $failure_body === $body );
		lifecycle_expect( ! in_array( 'unknown.callback', $state['events'], true ) && ! in_array( 'known.response', $state['events'], true ) && ! in_array( 'customer.save', $state['events'], true ) && ! in_array( 'scope.complete', $state['events'], true ) );
		if ( 'header-cohort-changed' === $case ) { lifecycle_expect( ! in_array( 'header.prepared', $state['events'], true ) && ! in_array( 'known.header', $state['events'], true ) ); }
		if ( 'auth-cohort-changed' === $case ) { lifecycle_expect( ! in_array( 'known.auth', $state['events'], true ) ); }
	} );
}
lifecycle_case( 'exact writer removal preserves unrelated receiver and callbacks', function () {
	[ $body, $state ] = lifecycle_child( 'exact-writers' );
	lifecycle_expect( [ 'data' => [ 'exact_writers' => true ] ] === json_decode( $body, true ) );
	lifecycle_expect( true === $state['other_customer_writer_retained'] && true === $state['owned_customer_writer_removed'] && true === $state['retained_unrelated_cart_callback'] );
	lifecycle_expect( ! in_array( 'cart.calculate_totals', $state['events'], true ) && ! in_array( 'cart.persistent', $state['events'], true ) && ! in_array( 'cart.cookies', $state['events'], true ) && ! in_array( 'handler.shutdown.save', $state['events'], true ) );
	lifecycle_expect( 2 === count( array_filter( $state['events'], fn( $event ) => 'unrelated.callback' === $event ) ) && 1 === count( array_filter( $state['events'], fn( $event ) => 'customer.save' === $event ) ) );
} );
lifecycle_case( 'unfinished shutdown aborts rather than saving successfully', function () {
	[ $body, $state ] = lifecycle_child( 'unfinished-shutdown' ); lifecycle_expect( '' === $body );
	lifecycle_expect( [ 'scope.discard' ] === lifecycle_events( $state, [ 'scope.discard', 'scope.complete', 'customer.save', 'cart.cookies', 'handler.shutdown.save' ] ) && true === $state['cleanup_terminal'] );
} );
lifecycle_case( 'detached auth response cannot flush old customer/cart/scope writers', function () {
	[ $body, $state ] = lifecycle_child( 'auth-detached' ); lifecycle_expect( true === json_decode( $body, true )['data']['synthetic'] );
	lifecycle_expect( [] === lifecycle_events( $state, [ 'customer.save', 'cart.set_session', 'cart.persistent', 'cart.cookies', 'scope.complete', 'scope.discard', 'handler.shutdown.save' ] ) && true === $state['cleanup_terminal'] );
} );
lifecycle_case( 'early auth status remains integer 403 and original message survives discard', function () {
	[ $body, $state ] = lifecycle_child( 'early-auth' );
	lifecycle_expect( [ 'errors' => [ [ 'message' => 'Synthetic authentication required.' ] ] ] === json_decode( $body, true ) && true === $state['auth_status_preserved'] );
	lifecycle_expect( [ 'scope.discard' ] === lifecycle_events( $state, [ 'scope.discard', 'scope.complete', 'customer.save', 'cart.cookies', 'handler.shutdown.save' ] ) && ! in_array( 'known.set_headers', $state['events'], true ) );
} );
lifecycle_case( 'early auth rejects unreviewed skipped header setter before publication', function () use ( $failure_body ) {
	[ $body, $state ] = lifecycle_child( 'early-auth-unsafe-skip' ); lifecycle_expect( $failure_body === $body && ! in_array( 'known.set_headers', $state['events'], true ) );
} );

foreach ( [ 'transition-partial-response' => 'WL_CART_SESSION_TRANSITION_INVALID', 'invalid-partial-response' => 'WL_CART_SESSION_INVALID', 'initial-invalid-no-scope' => 'WL_CART_SESSION_INVALID' ] as $case => $expected_code ) {
	lifecycle_case( $case . ' actual Executor error response cannot flush the old cart', function () use ( $case, $expected_code ) {
		[ $body, $state ] = lifecycle_child( $case ); $response = json_decode( $body, true );
		lifecycle_expect( is_array( $response ) && ! isset( $response['data'] ) && $expected_code === $response['errors'][0]['extensions']['code'] );
		lifecycle_expect( ( 'initial-invalid-no-scope' === $case ? ! in_array( 'synthetic.earlier.resolver', $state['events'], true ) : in_array( 'synthetic.earlier.resolver', $state['events'], true ) ) && [ 'scope.discard' ] === lifecycle_events( $state, [ 'scope.discard', 'scope.complete', 'customer.save', 'cart.set_session', 'cart.cookies', 'handler.shutdown.save' ] ) );
	} );
}

// Native HTTP is necessary to observe queued credentials/cookies and status.
$socket = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $error );
if ( false === $socket ) { lifecycle_case( 'localhost HTTP fixture available', fn() => lifecycle_expect( false ) ); }
else {
	$address = stream_socket_get_name( $socket, false ); fclose( $socket );
	$ledger = tempnam( sys_get_temp_dir(), 'wl-lifecycle-http-' ); chmod( $ledger, 0600 ); putenv( 'WL_LIFECYCLE_HTTP_LEDGER=' . $ledger );
	$server = proc_open( [ PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', '-d', 'output_buffering=0', '-S', $address, $endpoint ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'file', '/dev/null', 'a' ], 2 => [ 'file', '/dev/null', 'a' ] ], $pipes );
	try {
		lifecycle_expect( is_resource( $server ) ); fclose( $pipes[0] ); $ready = false;
		for ( $attempt = 0; $attempt < 100; $attempt++ ) {
			$connection = @stream_socket_client( 'tcp://' . $address, $errno, $error, 0.1 );
			if ( $connection ) { fclose( $connection ); $ready = true; break; } usleep( 20000 );
		}
		lifecycle_expect( $ready );
		foreach ( [ 'success' => 200, 'customer-failure' => 503, 'replacement-cart' => 503, 'early-auth' => 403 ] as $case => $expected ) {
			lifecycle_case( $case . ' actual HTTP status and credential withholding', function () use ( $address, $case, $expected, $ledger, $failure_body ) {
				$body = file_get_contents( 'http://' . $address . '/?case=' . $case, false, stream_context_create( [ 'http' => [ 'ignore_errors' => true, 'timeout' => 5 ] ] ) );
				$headers = $http_response_header ?? []; $map = [];
				lifecycle_expect( str_contains( $headers[0] ?? '', ' ' . $expected . ' ' ) );
				foreach ( array_slice( $headers, 1 ) as $header ) { $parts = explode( ':', $header, 2 ); if ( 2 === count( $parts ) ) { $map[ strtolower( $parts[0] ) ][] = trim( $parts[1] ); } }
				lifecycle_expect( [ 'synthetic-auth' ] === ( $map['authorization'] ?? [] ) && [ 'no-store' ] === ( $map['cache-control'] ?? [] ) );
				$cookies = $map['set-cookie'] ?? []; lifecycle_expect( in_array( 'wordpress_logged_in_synthetic=preserved; Path=/; HttpOnly', $cookies, true ) );
				if ( 200 === $expected ) { lifecycle_expect( [ 'synthetic-cart' ] === ( $map['woocommerce-session'] ?? [] ) && true === json_decode( $body, true )['data']['serialized_before_writers'] ); }
				else {
					lifecycle_expect( ! isset( $map['woocommerce-session'] ) && 1 === count( $cookies ) );
					if ( 503 === $expected ) { lifecycle_expect( $failure_body === $body ); }
					else { lifecycle_expect( [ 'https://validated.example.invalid' ] === ( $map['access-control-allow-origin'] ?? [] ) && [ 'true' ] === ( $map['access-control-allow-credentials'] ?? [] ) && 'Synthetic authentication required.' === json_decode( $body, true )['errors'][0]['message'] ); }
				}
			} );
		}
	} catch ( Throwable $ignored ) { lifecycle_case( 'localhost fixture startup', fn() => lifecycle_expect( false ) ); }
	finally { if ( is_resource( $server ) ) { proc_terminate( $server ); proc_close( $server ); } unlink( $ledger ); }
}
$source_after = array_map( fn( $file ) => hash_file( 'sha256', $file ), $source_files );
lifecycle_case( 'actual source cohort remains stable across native subprocesses', fn() => lifecycle_expect( $source_before === $source_after ) );
echo 'RESULT ' . $total . ' cases, ' . $failures . ' failures' . PHP_EOL;
echo 'PHP ' . PHP_VERSION . PHP_EOL;
foreach ( $source_after as $name => $hash ) { echo 'SOURCE ' . $name . '-sha256=' . $hash . PHP_EOL; }
echo 'SOURCE fixture-sha256=' . LIFECYCLE_FIXTURE_SHA . PHP_EOL;
echo 'SOURCE WP_Hook-sha256=' . LIFECYCLE_WP_HOOK_SHA . PHP_EOL;
exit( $failures ? 1 : 0 );

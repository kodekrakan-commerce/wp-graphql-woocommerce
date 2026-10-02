<?php
/**
 * Focused composed regression: ACTUAL handler + operation + lifecycle + HTTP;
 * genuine plugin.php/WP_Hook, InstrumentSchema/WPMutationType and Executor.
 * Pinned controlled WC/Router/SQL/cache/auth adapter, no site/DB/payment proof.
 * Configure WL_WORDPRESS_SOURCE, WL_WOOCOMMERCE_SOURCE, WL_WPGRAPHQL_SOURCE,
 * WL_MU_PLUGINS_SOURCE. Native subprocesses permit real emit/exit/shutdown.
 */
error_reporting( E_ALL ); ini_set( 'display_errors', '0' ); ini_set( 'log_errors', '0' );
const INTEGRATION_FIXTURE_SHA = '58909fc798a088f79aa9ba192a7224860916c9a0b5a238318ab525c1d51b7b56';
const INTEGRATION_ADAPTER_SHA = 'a9b39f56e7f2805fa066741816c7644eba3055d6a9f743f6d773f7af309ea20c';
const INTEGRATION_HANDLER_SHA = 'cc18d37a98981204458394237993d7fb05c1d09ada3234bd3a0e52d3e0c72f5d';
$owner = dirname( __DIR__, 2 ); $endpoint = __DIR__ . '/cart-session-lifecycle-integration-fixture.php';
$source_files = [ 'handler' => $owner . '/includes/utils/class-ql-session-handler.php', 'fixture' => $endpoint, 'adapter' => __DIR__ . '/cart-session-owned-handler-fixtures.php' ];
foreach ( [ 'handler' => INTEGRATION_HANDLER_SHA, 'fixture' => INTEGRATION_FIXTURE_SHA, 'adapter' => INTEGRATION_ADAPTER_SHA ] as $name => $hash ) {
	if ( ! is_file( $source_files[$name] ) || ! hash_equals( $hash, hash_file( 'sha256', $source_files[$name] ) ) ) { fwrite( STDERR, "Reviewed integration source/fixture hash mismatch.\n" ); exit( 2 ); }
}
foreach ( [ 'WL_WORDPRESS_SOURCE' => '/wp-includes/plugin.php', 'WL_WOOCOMMERCE_SOURCE' => '/includes/class-wc-session-handler.php', 'WL_WPGRAPHQL_SOURCE' => '/vendor/autoload.php', 'WL_MU_PLUGINS_SOURCE' => '/database/interface-owned-scope-driver.php' ] as $input => $file ) {
	if ( ! is_file( rtrim( getenv( $input ) ?: '', '/' ) . $file ) ) { fwrite( STDERR, "Configure genuine integration source environment inputs.\n" ); exit( 2 ); }
}
foreach ( [ 'operation' => 'class-cart-session-operation.php', 'lifecycle' => 'class-cart-session-lifecycle.php', 'HTTP' => 'class-cart-session-http-boundary.php', 'storage' => 'class-cart-session-storage.php' ] as $name => $file ) { $source_files[$name] = $owner . '/includes/utils/' . $file; }
$wp = rtrim( getenv( 'WL_WORDPRESS_SOURCE' ), '/' ); $gql = rtrim( getenv( 'WL_WPGRAPHQL_SOURCE' ), '/' );
$source_files['WP_Hook'] = $wp . '/wp-includes/class-wp-hook.php'; $source_files['plugin.php'] = $wp . '/wp-includes/plugin.php';
$source_files['InstrumentSchema'] = $gql . '/src/Utils/InstrumentSchema.php'; $source_files['WPMutationType'] = $gql . '/src/Type/WPMutationType.php'; $source_files['AppContext'] = $gql . '/src/AppContext.php';
$source_before = array_map( fn( $file ) => hash_file( 'sha256', $file ), $source_files );
putenv( 'WL_INTEGRATION_FIXTURE_SHA=' . INTEGRATION_FIXTURE_SHA ); putenv( 'WL_INTEGRATION_ADAPTER_SHA=' . INTEGRATION_ADAPTER_SHA ); putenv( 'WL_INTEGRATION_HANDLER_SHA=' . INTEGRATION_HANDLER_SHA );
$total = 0; $failures = 0;
function integration_expect( $condition ) { if ( ! $condition ) { throw new RuntimeException( 'Sanitized composed integration assertion failed.' ); } }
function integration_case( $name, callable $action ) {
	global $total, $failures; $total++;
	try { $action(); echo 'PASS ' . $name . PHP_EOL; }
	catch ( Throwable $ignored ) { $failures++; echo 'FAIL ' . $name . ' [sanitized composed assertion or boundary failure]' . PHP_EOL; }
}
function integration_child( $case ) {
	global $endpoint;
	$ledger = tempnam( sys_get_temp_dir(), 'wl-integrated-' ); chmod( $ledger, 0600 );
	try {
		$process = proc_open( [ PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', '-d', 'output_buffering=0', $endpoint, $case, $ledger ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
		integration_expect( is_resource( $process ) ); fclose( $pipes[0] );
		$body = stream_get_contents( $pipes[1] ); $err = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); $code = proc_close( $process );
		$state = json_decode( file_get_contents( $ledger ), true ); integration_expect( 0 === $code && '' === $err && is_array( $state ) );
		return [ json_decode( $body, true ), $state ];
	} finally { unlink( $ledger ); }
}
function integration_no_flush( $state ) {
	integration_expect( true === $state['creation_order'] && true === $state['terminal'] && 0 === $state['writes'] && true === $state['row_preserved'] );
	foreach ( [ 'customer.save', 'cart.set_session', 'cart.persistent', 'cart.cookies', 'cart.calculate', 'db-write', 'timestamp-write', 'cache-write', 'cookie-emitted' ] as $event ) { integration_expect( ! in_array( $event, $state['events'], true ) ); }
}
function integration_assert_rejection( $response, $state, $code = 'WL_CART_SESSION_TRANSITION_INVALID' ) {
	integration_no_flush( $state ); integration_expect( is_array( $response ) && [ 'errors' ] === array_keys( $response ) && ! empty( $response['errors'] ) );
	foreach ( $response['errors'] as $error ) { integration_expect( $code === ( $error['extensions']['code'] ?? null ) ); }
}
integration_case( 'ordinary composed success exercises actual prepared token, flush, SQL boundary, seal and release', function () {
	[ $response, $state ] = integration_child( 'ordinary-success' );
	integration_expect( true === $response['data']['addToCart']['success'] && is_string( $response['data']['addToCart']['customer']['sessionToken'] ) && true === $state['creation_order'] && true === $state['terminal'] && false === $state['rejection'] );
	integration_expect( 1 === $state['writes'] && 1 === count( array_filter( $state['calls'], fn( $call ) => 'release' === $call ) ) && ! in_array( 'abort', $state['calls'], true ) );
	$events = array_values( array_filter( $state['events'], fn( $event ) => in_array( $event, [ 'callback.addToCart', 'customer.save', 'cart.set_session', 'cart.persistent', 'cart.cookies', 'db-write', 'scope-seal', 'scope-release' ], true ) ) );
	integration_expect( [ 'callback.addToCart', 'customer.save', 'cart.set_session', 'cart.persistent', 'cart.cookies', 'db-write', 'scope-seal', 'scope-release' ] === $events );
} );
integration_case( 'existing valid token survives frozen registry then native cart-cookie hook and checked flush', function () {
	[ $response, $state ] = integration_child( 'existing-token-cart-cookie' );
	integration_expect( true === ( $response['data']['addToCart']['success'] ?? null ) && is_string( $response['data']['addToCart']['customer']['sessionToken'] ?? null ) );
	integration_expect( true === $state['cookie_registry_stable'] && true === $state['cookie_issuance_preserved'] && true === $state['cookie_token_policy'] );
	integration_expect( 0 === $state['translations'] && false === $state['rejection'] && true === $state['terminal'] && 1 === $state['writes'] );
	integration_expect( ! in_array( 'abort', $state['calls'], true ) && 1 === count( array_filter( $state['calls'], fn( $call ) => 'release' === $call ) ) );
} );
foreach ( [ 'mixed-login-first', 'mixed-cart-first' ] as $case ) {
	integration_case( $case . ' actual operation rejection latches handler then discards with zero callbacks or flush', function () use ( $case ) {
		[ $response, $state ] = integration_child( $case ); integration_assert_rejection( $response, $state );
		integration_expect( true === $state['rejection'] && false === $state['detached'] && 1 === count( array_filter( $state['calls'], fn( $call ) => 'abort' === $call ) ) );
		foreach ( [ 'callback.login', 'callback.addToCart' ] as $event ) { integration_expect( ! in_array( $event, $state['events'], true ) ); }
	} );
}
integration_case( 'trusted later final-input hold drops actual earlier sibling data and token without flush', function () {
	[ $response, $state ] = integration_child( 'later-filtered-input' ); integration_assert_rejection( $response, $state );
	integration_expect( true === $state['rejection'] && true === $state['partial_data_before_terminal'] && true === $state['partial_token_before_terminal'] );
	integration_expect( in_array( 'callback.addToCart', $state['events'], true ) && in_array( 'trusted.checkout.input', $state['events'], true ) && ! in_array( 'callback.checkout', $state['events'], true ) && 1 === count( array_filter( $state['calls'], fn( $call ) => 'abort' === $call ) ) );
} );
integration_case( 'detached final-input rejection remains errors-only after clean old-scope release', function () {
	[ $response, $state ] = integration_child( 'detached-input-rejection' ); integration_assert_rejection( $response, $state );
	integration_expect( true === $state['rejection'] && true === $state['detached'] && ! in_array( 'callback.login', $state['events'], true ) );
	integration_expect( 1 === count( array_filter( $state['calls'], fn( $call ) => 'release' === $call ) ) && ! in_array( 'abort', $state['calls'], true ) );
} );
foreach ( [ 'unavailable-dominates', 'detached-unavailable-dominates' ] as $case ) {
	integration_case( $case . ' failed owned storage dominates before translated transition formatting', function () use ( $case ) {
		[ $response, $state ] = integration_child( $case ); integration_assert_rejection( $response, $state, 'WL_CART_SESSION_UNAVAILABLE' );
		integration_expect( 0 === $state['translations'] && false === $state['rejection'] && ! in_array( 'callback.login', $state['events'], true ) );
	} );
}

// Native queued-header status is observable only through localhost HTTP.
$socket = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $error );
if ( false === $socket ) { integration_case( 'localhost native HTTP available', fn() => integration_expect( false ) ); }
else {
	$address = stream_socket_get_name( $socket, false ); fclose( $socket );
	$ledger = tempnam( sys_get_temp_dir(), 'wl-integrated-http-' ); chmod( $ledger, 0600 ); putenv( 'WL_INTEGRATION_HTTP_LEDGER=' . $ledger );
	$server = proc_open( [ PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', '-d', 'output_buffering=0', '-S', $address, $endpoint ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'file', '/dev/null', 'a' ], 2 => [ 'file', '/dev/null', 'a' ] ], $pipes );
	try {
		integration_expect( is_resource( $server ) ); fclose( $pipes[0] ); $ready = false;
		for ( $attempt = 0; $attempt < 100; $attempt++ ) { $connection = @stream_socket_client( 'tcp://' . $address, $errno, $error, 0.1 ); if ( $connection ) { fclose( $connection ); $ready = true; break; } usleep( 20000 ); }
		integration_expect( $ready );
		foreach ( [ 'mixed-login-first' => 200, 'later-filtered-input' => 200, 'detached-input-rejection' => 200, 'unavailable-dominates' => 503 ] as $case => $status ) {
			integration_case( $case . ' actual HTTP withholds queued cart credentials and partial data', function () use ( $address, $case, $status ) {
				$body = file_get_contents( 'http://' . $address . '/?case=' . $case, false, stream_context_create( [ 'http' => [ 'ignore_errors' => true, 'timeout' => 5 ] ] ) );
				$headers = $http_response_header ?? []; $map = []; integration_expect( str_contains( $headers[0] ?? '', ' ' . $status . ' ' ) );
				foreach ( array_slice( $headers, 1 ) as $header ) { $parts = explode( ':', $header, 2 ); if ( 2 === count( $parts ) ) { $map[strtolower( $parts[0] )][] = trim( $parts[1] ); } }
				integration_expect( ! isset( $map['woocommerce-session'] ) && [ 'synthetic-auth' ] === ( $map['authorization'] ?? [] ) && [ 'unrelated=preserved; Path=/' ] === ( $map['set-cookie'] ?? [] ) );
				$response = json_decode( $body, true ); integration_expect( is_array( $response ) && [ 'errors' ] === array_keys( $response ) );
				integration_expect( ( 503 === $status ? 'WL_CART_SESSION_UNAVAILABLE' : 'WL_CART_SESSION_TRANSITION_INVALID' ) === $response['errors'][0]['extensions']['code'] );
			} );
		}
		integration_case( 'existing-token cart-cookie actual HTTP returns prepared credential and body token after checked release', function () use ( $address ) {
			$body = file_get_contents( 'http://' . $address . '/?case=existing-token-cart-cookie', false, stream_context_create( [ 'http' => [ 'ignore_errors' => true, 'timeout' => 5 ] ] ) );
			$headers = $http_response_header ?? []; $map = []; integration_expect( str_contains( $headers[0] ?? '', ' 200 ' ) );
			foreach ( array_slice( $headers, 1 ) as $header ) { $parts = explode( ':', $header, 2 ); if ( 2 === count( $parts ) ) { $map[strtolower( $parts[0] )][] = trim( $parts[1] ); } }
			$response = json_decode( $body, true ); $token = $response['data']['addToCart']['customer']['sessionToken'] ?? null;
			integration_expect( true === ( $response['data']['addToCart']['success'] ?? null ) && is_string( $token ) && [ $token ] === ( $map['woocommerce-session'] ?? [] ) );
			integration_expect( [ 'synthetic-auth' ] === ( $map['authorization'] ?? [] ) && ! isset( $response['errors'] ) );
		} );
	} catch ( Throwable $ignored ) { integration_case( 'native localhost fixture startup', fn() => integration_expect( false ) ); }
	finally { if ( is_resource( $server ) ) { proc_terminate( $server ); proc_close( $server ); } unlink( $ledger ); }
}
$source_after = array_map( fn( $file ) => hash_file( 'sha256', $file ), $source_files );
integration_case( 'composed actual source cohort stays stable across subprocesses', fn() => integration_expect( $source_before === $source_after ) );
echo 'RESULT ' . $total . ' cases, ' . $failures . ' failures' . PHP_EOL;
echo 'PHP ' . PHP_VERSION . PHP_EOL;
foreach ( $source_after as $name => $hash ) { echo 'SOURCE ' . $name . '-sha256=' . $hash . PHP_EOL; }
exit( $failures ? 1 : 0 );

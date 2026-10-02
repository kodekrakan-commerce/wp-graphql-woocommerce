<?php
/**
 * Genuine native PHP subprocess and localhost HTTP contracts. The helper, typed
 * errors and GraphQL formatter are actual source. Storage/finalization callbacks
 * are controlled substitutes; this does not qualify WordPress/router/DB effects.
 * Run with PHP8.2+ and WL_MU_PLUGINS_SOURCE + WL_WPGRAPHQL_SOURCE configured.
 */
error_reporting( E_ALL ); ini_set( 'display_errors', '0' ); ini_set( 'log_errors', '0' );
$mu = rtrim( getenv( 'WL_MU_PLUGINS_SOURCE' ) ?: '', '/' );
$gql = rtrim( getenv( 'WL_WPGRAPHQL_SOURCE' ) ?: '', '/' );
if ( ! is_file( $mu . '/database/class-owned-scope-error.php' ) || ! is_file( $gql . '/vendor/autoload.php' ) ) {
	fwrite( STDERR, "Configure genuine source environment inputs for the HTTP boundary contracts.\n" ); exit( 2 );
}
$endpoint = __DIR__ . '/cart-session-http-boundary-fixtures.php';
$failure_body = '{"errors":[{"message":"The cart session is temporarily unavailable.","extensions":{"code":"WL_CART_SESSION_UNAVAILABLE"}}]}';
$failures = 0; $total = 0;
function http_contract_expect( $condition ) { if ( ! $condition ) { throw new RuntimeException( 'Sanitized HTTP contract assertion failed.' ); } }
function http_contract_case( $name, callable $action ) {
	global $failures, $total; $total++;
	try { $action(); echo 'PASS ' . $name . PHP_EOL; }
	catch ( Throwable $ignored ) { $failures++; echo 'FAIL ' . $name . ' [sanitized assertion or boundary failure]' . PHP_EOL; }
}
function http_contract_child( $case ) {
	global $endpoint;
	$process = proc_open( [ PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', $endpoint, $case ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
	if ( ! is_resource( $process ) ) { throw new RuntimeException( 'PHP subprocess unavailable.' ); }
	fclose( $pipes[0] ); $out = stream_get_contents( $pipes[1] ); $err = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] ); fclose( $pipes[2] ); $code = proc_close( $process );
	http_contract_expect( 0 === $code && '' === $err );
	return $out;
}
foreach ( [ 'explicit-fail', 'owned-uncaught', 'cart-unavailable-uncaught', 'preencode-failure', 'owned-buffer-removed', 'json-encoding-failure', 'finalize-failure', 'seal-failure', 'release-failure', 'cleanup-throws', 'discard-cleanup-throws', 'cleanup-cannot-revive', 'later-handler-at-cleanup' ] as $case ) {
	http_contract_case( $case . ' native fixed payload', fn() => http_contract_expect( $failure_body === http_contract_child( $case ) ) );
}
foreach ( [ 'success', 'baseline-default-buffer', 'status-preserved' ] as $case ) {
	http_contract_case( $case . ' preencoded before finalize without output leaks', function () use ( $case ) {
		http_contract_expect( [ 'data' => [ 'encodedWhileOwned' => true, 'serializationCount' => 1 ] ] === json_decode( http_contract_child( $case ), true ) );
	} );
}
foreach ( [ 'echo', 'destructor', 'warning', 'nested', 'flush-removal', 'wp-buffer-flush' ] as $variant ) {
	foreach ( [ 'fail', 'success' ] as $outcome ) {
		http_contract_case( $outcome . ' suppresses native shutdown suffix ' . $variant, function () use ( $variant, $outcome, $failure_body ) {
			$bytes = http_contract_child( 'shutdown-' . $outcome . '-' . $variant );
			if ( 'fail' === $outcome ) { http_contract_expect( $failure_body === $bytes ); }
			else { http_contract_expect( [ 'data' => [ 'encodedWhileOwned' => true, 'serializationCount' => 1 ] ] === json_decode( $bytes, true ) ); }
		} );
	}
}
foreach ( [ 'previous-same-object', 'invalid-cart-delegated', 'lookalike-delegated', 'cleanup-then-delegate', 'restore-previous', 'restore-later', 'default-rethrow-same-object' ] as $case ) {
	http_contract_case( $case . ' native handler contract', function () use ( $case ) {
		$result = json_decode( http_contract_child( $case ), true ); http_contract_expect( is_array( $result ) );
		foreach ( $result as $value ) { http_contract_expect( true === $value ); }
	} );
}
http_contract_case( 'literal unavailable constructor and actual GraphQL formatting use zero translation callbacks', function () {
	http_contract_expect( [ 'literal' => true, 'code' => true, 'translationCalls' => 0 ] === json_decode( http_contract_child( 'literal-constructor-formatting' ), true ) );
} );
http_contract_case( 'later handler installed by finalizer remains current at shutdown', function () {
	http_contract_expect( [ 'data' => [ 'ok' => true ] ] === json_decode( http_contract_child( 'later-handler-at-finalize' ), true ) );
} );
http_contract_case( 'discard preserves preencoded early-auth response', function () {
	$result = json_decode( http_contract_child( 'discard' ), true );
	http_contract_expect( 'SYNTHETIC_AUTH_REQUIRED' === $result['errors'][0]['extensions']['code'] );
} );
http_contract_case( 'unknown buffer explicitly unqualified and not explicitly closed', function () {
	http_contract_expect( 'UNQUALIFIED-UNKNOWN-CALLBACK' === http_contract_child( 'unknown-buffer' ) );
} );
http_contract_case( 'already flushed output explicitly cannot guarantee fixed body', function () use ( $failure_body ) {
	http_contract_expect( 'UNQUALIFIED-EARLY-OUTPUT' . $failure_body === http_contract_child( 'early-output' ) );
} );

// Native queued header/cookie evidence requires HTTP; bind only localhost.
$socket = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $error );
if ( false === $socket ) { http_contract_case( 'localhost HTTP fixture available', fn() => http_contract_expect( false ) ); }
else {
	$address = stream_socket_get_name( $socket, false ); fclose( $socket );
	// php.ini may install a streaming output_buffering=4096 cohort for HTTP.
	// This fixture explicitly qualifies a closed nonstreaming cohort instead.
	$server = proc_open( [ PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', '-d', 'output_buffering=0', '-S', $address, $endpoint ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'file', '/dev/null', 'a' ], 2 => [ 'file', '/dev/null', 'a' ] ], $pipes );
	try {
		http_contract_expect( is_resource( $server ) ); fclose( $pipes[0] );
		$ready = false;
		for ( $attempt = 0; $attempt < 100; $attempt++ ) {
			$connection = @stream_socket_client( 'tcp://' . $address, $errno, $error, 0.1 );
			if ( $connection ) { fclose( $connection ); $ready = true; break; }
			usleep( 20000 );
		}
		http_contract_expect( $ready );
		foreach ( [ 'explicit-fail' => 503, 'owned-uncaught' => 503, 'cart-unavailable-uncaught' => 503,
			'discard' => 403, 'success' => 200, 'status-preserved' => 202,
			'shutdown-fail-echo' => 503, 'shutdown-success-wp-buffer-flush' => 200,
			'shutdown-fail-flush-removal' => 503, 'shutdown-success-nested' => 200 ] as $case => $expected_status ) {
			http_contract_case( $case . ' actual localhost HTTP header and cookie preservation', function () use ( $address, $case, $expected_status, $failure_body ) {
				$context = stream_context_create( [ 'http' => [ 'ignore_errors' => true, 'timeout' => 5 ] ] );
				$body = file_get_contents( 'http://' . $address . '/?case=' . $case, false, $context );
				$headers = $http_response_header ?? []; $map = [];
				http_contract_expect( str_contains( $headers[0] ?? '', ' ' . $expected_status . ' ' ) );
				foreach ( array_slice( $headers, 1 ) as $header ) {
					$parts = explode( ':', $header, 2 ); if ( 2 === count( $parts ) ) { $map[ strtolower( $parts[0] ) ][] = trim( $parts[1] ); }
				}
				http_contract_expect( [ 'https://validated.example.invalid' ] === ( $map['access-control-allow-origin'] ?? [] ) && [ 'true' ] === ( $map['access-control-allow-credentials'] ?? [] ) );
				http_contract_expect( [ 'Origin' ] === ( $map['vary'] ?? [] ) && [ 'preserved' ] === ( $map['x-unrelated'] ?? [] ) && [ 'preserved-synthetic-auth' ] === ( $map['authorization'] ?? [] ) );
				http_contract_expect( [ 'no-store, no-cache' ] === ( $map['cache-control'] ?? [] ) && [ 'no-cache' ] === ( $map['pragma'] ?? [] ) && ! isset( $map['etag'] ) && ! isset( $map['expires'] ) && ! isset( $map['content-encoding'] ) );
				http_contract_expect( ! isset( $map['content-length'] ) || [ (string) strlen( $body ) ] === $map['content-length'] );
				$cookies = $map['set-cookie'] ?? [];
				http_contract_expect( in_array( 'wordpress_logged_in_synthetic=preserved; Path=/; HttpOnly', $cookies, true ) && in_array( 'unrelated=preserved; Path=/', $cookies, true ) );
				if ( $expected_status < 300 ) {
					http_contract_expect( [ 'queued-synthetic-cart' ] === ( $map['woocommerce-session'] ?? [] ) && [ 'queued-synthetic-cart' ] === ( $map['x-cart-credential'] ?? [] ) && 3 === count( $cookies ) );
					http_contract_expect( [ '1' ] === ( $map['x-contract-finalized'] ?? [] ) && ! isset( $map['x-contract-cleaned'] ) );
					http_contract_expect( [ 'data' => [ 'encodedWhileOwned' => true, 'serializationCount' => 1 ] ] === json_decode( $body, true ) );
				} else {
					http_contract_expect( ! isset( $map['woocommerce-session'] ) && ! isset( $map['x-cart-credential'] ) && 2 === count( $cookies ) && [ '1' ] === ( $map['x-contract-cleaned'] ?? [] ) );
					if ( 503 === $expected_status ) { http_contract_expect( $failure_body === $body ); }
					else { http_contract_expect( 'SYNTHETIC_AUTH_REQUIRED' === json_decode( $body, true )['errors'][0]['extensions']['code'] ); }
				}
			} );
		}
	} catch ( Throwable $ignored ) {
		http_contract_case( 'localhost HTTP fixture startup', fn() => http_contract_expect( false ) );
	} finally {
		if ( is_resource( $server ) ) { proc_terminate( $server ); proc_close( $server ); }
	}
}
echo 'RESULT ' . $total . ' cases, ' . $failures . ' failures' . PHP_EOL;
echo 'PHP ' . PHP_VERSION . PHP_EOL;
echo 'SOURCE helper-sha256=' . hash_file( 'sha256', dirname( __DIR__, 2 ) . '/includes/utils/class-cart-session-http-boundary.php' ) . PHP_EOL;
exit( $failures ? 1 : 0 );

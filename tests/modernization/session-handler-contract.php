<?php
/** Isolated-process contract with actual handler/storage/interface and controlled lifecycle/WP/SQL/cache boundaries. No site/driver activation/HTTP acceptance. */

error_reporting( E_ALL );
ini_set( 'display_errors', '0' );

$plugin_root = rtrim( getenv( 'WL_WOOGRAPHQL_SOURCE' ) ?: dirname( __DIR__, 2 ), '/' );
$wc_root = rtrim( getenv( 'WL_WOOCOMMERCE_SOURCE' ) ?: '', '/' );
$mu_root = rtrim( getenv( 'WL_MU_PLUGINS_SOURCE' ) ?: '', '/' );
$graphql_root = rtrim( getenv( 'WL_WPGRAPHQL_SOURCE' ) ?: '', '/' );
$required = [
	'fixture_bootstrap' => __DIR__ . '/session-handler/bootstrap.php',
	'fixture_boundaries' => __DIR__ . '/session-handler/boundaries.php',
	'fixture_cases' => __DIR__ . '/session-handler/cases.php',
	'Owned_Scope_Driver' => $mu_root . '/database/interface-owned-scope-driver.php',
	'handler' => $plugin_root . '/includes/utils/class-ql-session-handler.php',
	'Checkout_Mutation' => $plugin_root . '/includes/data/mutation/class-checkout-mutation.php',
	'JWT' => $plugin_root . '/vendor-prefixed/firebase/php-jwt/src/JWT.php',
	'WC_Session' => $wc_root . '/includes/abstracts/abstract-wc-session.php',
	'WC_Session_Handler' => $wc_root . '/includes/class-wc-session-handler.php',
	'WC_Cart_Session' => $wc_root . '/includes/class-wc-cart-session.php',
	'GraphQL_UserError' => $graphql_root . '/vendor/webonyx/graphql-php/src/Error/UserError.php',
	'GraphQL_ProvidesExtensions' => $graphql_root . '/vendor/webonyx/graphql-php/src/Error/ProvidesExtensions.php',
];
if ( getenv( 'WL_WOOGRAPHQL_BASELINE_SOURCE' ) ) {
	$required['baseline_JWT'] = rtrim( getenv( 'WL_WOOGRAPHQL_BASELINE_SOURCE' ), '/' ) . '/vendor-prefixed/firebase/php-jwt/src/JWT.php';
}
foreach ( [ 'Cart_Session_Error' => 'class-cart-session-error.php', 'Cart_Session_Operation' => 'class-cart-session-operation.php', 'Cart_Session_Storage' => 'class-cart-session-storage.php' ] as $label => $name ) {
	$file = $plugin_root . '/includes/utils/' . $name;
	if ( is_file( $file ) ) { $required[$label] = $file; }
}
foreach ( $required as $label => $file ) {
	if ( ! is_file( $file ) ) {
		fwrite( STDERR, 'Missing required source: ' . $label . '. Set WL_MU_PLUGINS_SOURCE, WL_WOOCOMMERCE_SOURCE and WL_WPGRAPHQL_SOURCE to unpacked plugin roots.' . PHP_EOL );
		exit( 2 );
	}
}
$source_hashes = array_map( static fn( $file ) => hash_file( 'sha256', $file ), $required );

if ( '--case' === ( $argv[1] ?? '' ) ) {
	// Catch every warning/error and report class/contract booleans only, never raw messages or traces.
	set_error_handler( static function ( $severity ) { throw new ErrorException( 'Synthetic boundary runtime warning.', 0, $severity ); } );
	ob_start();
	try {
		require __DIR__ . '/session-handler/bootstrap.php';
		require __DIR__ . '/session-handler/cases.php';
		$cases = handler_contract_cases();
		if ( ! isset( $cases[$argv[2] ?? ''] ) ) { throw new RuntimeException( 'Unknown contract case.' ); }
		$result = contract_run_case( $cases[$argv[2]] );
	} catch ( Throwable $error ) {
		$result = [ 'failures' => [ 'unexpected runtime exception class: ' . get_class( $error ) ], 'effects' => [] ];
	}
	$result['source_hashes'] = array_map( static fn( $file ) => hash_file( 'sha256', $file ), $required );
	if ( $source_hashes !== $result['source_hashes'] ) { $result['failures'][] = 'source files changed during isolated execution; rerun against stable source'; }
	ob_end_clean();
	echo json_encode( $result, JSON_THROW_ON_ERROR ) . PHP_EOL;
	exit( empty( $result['failures'] ) ? 0 : 1 );
}

require __DIR__ . '/session-handler/cases.php';
echo 'PHP ' . PHP_VERSION . '; offline retained handler contract' . PHP_EOL;
foreach ( $source_hashes as $label => $hash ) { echo $label . ' SHA256 ' . $hash . PHP_EOL; }
$passes = $failures = 0;
foreach ( handler_contract_cases() as $name => $unused ) {
	$pipes = [];
	$process = proc_open( [ PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', '-d', 'memory_limit=64M', __FILE__, '--case', $name ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
	if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Unable to start isolated contract process.' ); }
	fclose( $pipes[0] );
	stream_set_blocking( $pipes[1], false ); stream_set_blocking( $pipes[2], false );
	$stdout = $stderr = '';
	$deadline = hrtime( true ) + 5_000_000_000;
	$timed_out = false;
	do {
		$state = proc_get_status( $process );
		$read = [ $pipes[1], $pipes[2] ]; $write = $except = null;
		stream_select( $read, $write, $except, 0, 100000 );
		foreach ( $read as $pipe ) {
			$chunk = stream_get_contents( $pipe );
			if ( $pipe === $pipes[1] ) { $stdout .= $chunk; } else { $stderr .= $chunk; }
		}
		if ( strlen( $stdout ) + strlen( $stderr ) > 262144 || hrtime( true ) > $deadline ) {
			$timed_out = true; proc_terminate( $process, 9 ); break;
		}
	} while ( $state['running'] );
	$stdout .= stream_get_contents( $pipes[1] );
	// Never forward raw stderr; a dependency's fatal error could include fixture values.
	$stderr .= stream_get_contents( $pipes[2] );
	fclose( $pipes[1] ); fclose( $pipes[2] );
	$closed_status = proc_close( $process );
	$status = $timed_out ? 124 : ( $state['exitcode'] >= 0 ? $state['exitcode'] : $closed_status );
	$result = json_decode( $stdout, true );
	if ( is_array( $result ) && $source_hashes !== ( $result['source_hashes'] ?? null ) ) {
		$result['failures'][] = 'loaded source cohort differs from parent snapshot; rerun against stable source';
	}
	$ok = 0 === $status && is_array( $result ) && empty( $result['failures'] );
	$ok ? ++$passes : ++$failures;
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . PHP_EOL;
	if ( ! is_array( $result ) ) { echo '  no sanitized child result; status=' . $status . '; stderr_present=' . ( '' !== $stderr ? 'true' : 'false' ) . PHP_EOL; continue; }
	foreach ( $result['failures'] as $failure ) { echo '  ' . $failure . PHP_EOL; }
	if ( ! $ok ) { echo '  effect counts: ' . json_encode( $result['effects'], JSON_THROW_ON_ERROR ) . PHP_EOL; }
}
echo $passes . ' passed; ' . $failures . ' failed; ' . ( $passes + $failures ) . ' isolated cases.' . PHP_EOL;
exit( $failures > 0 ? 1 : 0 );

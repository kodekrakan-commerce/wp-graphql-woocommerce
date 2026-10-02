<?php
/** Real graphql-php execution + actual WPGraphQL instrumentation/mutation lifecycle. */
error_reporting( E_ALL ); ini_set( 'display_errors', '0' ); ini_set( 'log_errors', '0' );
$graphql_root = rtrim( getenv( 'WL_WPGRAPHQL_SOURCE' ) ?: '', '/' );
$plugin_root = rtrim( getenv( 'WL_WOOGRAPHQL_SOURCE' ) ?: dirname( __DIR__, 2 ), '/' );
foreach ( [ $graphql_root . '/vendor/autoload.php', $graphql_root . '/src/Utils/InstrumentSchema.php', $graphql_root . '/src/Type/WPMutationType.php', $plugin_root . '/includes/utils/class-cart-session-operation.php' ] as $file ) {
	if ( ! is_file( $file ) ) { fwrite( STDERR, "Required source unavailable; set WL_WPGRAPHQL_SOURCE and WL_WOOGRAPHQL_SOURCE.\n" ); exit( 2 ); }
}
require __DIR__ . '/session-operation/bootstrap.php';
require $graphql_root . '/vendor/autoload.php';
require $graphql_root . '/src/Utils/InstrumentSchema.php';
require $graphql_root . '/src/Type/WPMutationType.php';
require $plugin_root . '/includes/data/mutation/class-checkout-mutation.php';
require $plugin_root . '/includes/utils/class-cart-session-error.php';
require $plugin_root . '/includes/utils/class-cart-session-operation.php';
require __DIR__ . '/session-operation/fixture.php';
require __DIR__ . '/session-operation/cases.php';
$failures = 0;
foreach ( operation_cases() as $name => $callback ) {
	ob_start();
	try { $callback(); $ok = true; } catch ( Throwable $error ) { $ok = false; }
	ob_end_clean();
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . PHP_EOL;
	if ( ! $ok ) { ++$failures; }
}
echo 'Failures: ' . $failures . PHP_EOL;
exit( $failures ? 1 : 0 );

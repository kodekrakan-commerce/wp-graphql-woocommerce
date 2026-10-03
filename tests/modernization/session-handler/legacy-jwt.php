<?php
/** Internal subprocess fixture; token material passes only through a private temporary file. */
ini_set( 'display_errors', '0' );
ini_set( 'log_errors', '0' );
try {
	$source = rtrim( getenv( 'WL_WOOGRAPHQL_BASELINE_SOURCE' ) ?: '', '/' );
	$file = $argv[2] ?? '';
	if ( '' === $source || ! is_file( $file ) || 0600 !== ( fileperms( $file ) & 0777 ) ) {
		throw new RuntimeException( 'Missing private compatibility fixture.' );
	}
	require $source . '/vendor/autoload.php';
	$key = str_repeat( 'a', 32 );
	$mode = $argv[1] ?? '';
	if ( in_array( $mode, [ 'mint-strong', 'mint-fallback' ], true ) ) {
		$now = time();
		$claims = [ 'iss' => 'https://offline.example.invalid', 'iat' => $now - 120, 'nbf' => $now - 120, 'exp' => $now + 3600, 'data' => [ 'customer_id' => 't_' . str_repeat( 'e', 30 ) ] ];
		$token = \WPGraphQL\WooCommerce\Vendor\Firebase\JWT\JWT::encode( $claims, 'mint-fallback' === $mode ? 'graphql-woo-cart-session' : $key, 'HS256' );
		$ok = false !== file_put_contents( $file, $token );
	} elseif ( 'decode-strong' === $mode ) {
		$decoded = \WPGraphQL\WooCommerce\Vendor\Firebase\JWT\JWT::decode( file_get_contents( $file ), new \WPGraphQL\WooCommerce\Vendor\Firebase\JWT\Key( $key, 'HS256' ) );
		$ok = 't_' . str_repeat( 'e', 30 ) === ( $decoded->data->customer_id ?? null );
	} else { $ok = false; }
	echo json_encode( [ 'ok' => $ok ], JSON_THROW_ON_ERROR ) . PHP_EOL;
	exit( $ok ? 0 : 1 );
} catch ( Throwable $error ) {
	echo '{"ok":false}' . PHP_EOL;
	exit( 1 );
}

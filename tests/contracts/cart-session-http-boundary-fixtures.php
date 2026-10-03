<?php
/** Actual subprocess endpoint; database/lifecycle callbacks are controlled substitutes. */
error_reporting( E_ALL ); ini_set( 'display_errors', '0' ); ini_set( 'log_errors', '0' );
$owner = dirname( __DIR__, 2 );
$mu = rtrim( getenv( 'WL_MU_PLUGINS_SOURCE' ) ?: '', '/' );
$gql = rtrim( getenv( 'WL_WPGRAPHQL_SOURCE' ) ?: '', '/' );
require $mu . '/database/class-owned-scope-error.php';
require $gql . '/vendor/autoload.php';
require $owner . '/includes/utils/class-cart-session-error.php';
require $owner . '/includes/utils/class-cart-session-http-boundary.php';

use WLCommerce\Database\Owned_Scope_Error;
use WPGraphQL\WooCommerce\Utils\Cart_Session_Error;
use WPGraphQL\WooCommerce\Utils\Cart_Session_HTTP_Boundary;

$GLOBALS['http_translation_calls'] = 0;
$GLOBALS['http_translation_fail'] = true;
function __( $text, $domain = null ) {
	$GLOBALS['http_translation_calls']++;
	if ( $GLOBALS['http_translation_fail'] ) { throw new RuntimeException( 'Translation boundary must not run.' ); }
	return $text;
}
function http_expect( $condition ) { if ( ! $condition ) { throw new RuntimeException( 'Synthetic HTTP contract failed.' ); } }
function http_current_handler() {
	$probe = static function ( Throwable $ignored ) {};
	$current = set_exception_handler( $probe ); restore_exception_handler(); return $current;
}
$case = 'cli' === PHP_SAPI ? ( $argv[1] ?? '' ) : ( $_GET['case'] ?? '' );
if ( preg_match( '/\Ashutdown-(fail|success)-(echo|destructor|warning|nested|flush-removal|wp-buffer-flush)\z/D', $case, $shutdown_case ) ) {
	$variant = $shutdown_case[2];
	if ( 'destructor' === $variant ) {
		$GLOBALS['http_shutdown_destructor'] = new class {
			public function __destruct() { echo 'SYNTHETIC-DESTRUCTOR-SUFFIX'; }
		};
	} elseif ( 'wp-buffer-flush' === $variant ) {
		$wp = rtrim( getenv( 'WL_WORDPRESS_SOURCE' ) ?: '', '/' );
		if ( ! is_file( $wp . '/wp-includes/functions.php' ) ) { exit( 41 ); }
		define( 'ABSPATH', $wp . '/' ); define( 'WPINC', 'wp-includes' );
		require $wp . '/wp-includes/functions.php';
		register_shutdown_function( static function () {
			ini_set( 'display_errors', '1' ); wp_ob_end_flush_all(); echo 'SYNTHETIC-WP-FLUSH-SUFFIX';
		} );
	} else {
		register_shutdown_function( static function () use ( $variant ) {
			ini_set( 'display_errors', '1' );
			if ( 'warning' === $variant ) { trigger_error( 'SYNTHETIC-DISPLAYED-SHUTDOWN-WARNING', E_USER_WARNING ); }
			elseif ( 'nested' === $variant ) {
				ob_start( static function ( $bytes ) { return '[' . $bytes . ']'; } );
				echo 'SYNTHETIC-NESTED-SUFFIX';
			} elseif ( 'flush-removal' === $variant ) {
				if ( false !== ob_end_flush() || false !== ob_flush() || false !== ob_end_clean() ) { exit( 42 ); }
				echo 'SYNTHETIC-REMOVAL-SUFFIX';
			} else { echo str_repeat( 'SYNTHETIC-SHUTDOWN-SUFFIX', 16384 ); }
		} );
	}
	$case = 'fail' === $shutdown_case[1] ? 'explicit-fail' : 'success';
}
$cleanup_calls = 0;
$owned = true;
$cleanup = function () use ( &$cleanup_calls, &$owned, $case ) {
	$cleanup_calls++; $owned = false;
	http_expect( 1 === $cleanup_calls );
	if ( 'cleanup-throws' === $case || 'discard-cleanup-throws' === $case ) { throw new Owned_Scope_Error(); }
	header( 'X-Contract-Cleaned: 1' );
};
if ( 'cleanup-cannot-revive' === $case ) {
	$cleanup = function () use ( &$cleanup_calls, &$boundary ) {
		$cleanup_calls++;
		$boundary->complete( [ 'data' => [ 'mustNotRevive' => true ] ], static function () {} );
	};
}
if ( 'later-handler-at-cleanup' === $case ) {
	$cleanup = function () use ( &$cleanup_calls ) {
		$cleanup_calls++;
		$later = static function ( Throwable $ignored ) {};
		set_exception_handler( $later );
		register_shutdown_function( function () use ( $later ) {
			if ( http_current_handler() !== $later ) { fwrite( STDERR, "Cleanup-installed handler was replaced.\n" ); }
		} );
	};
}

if ( 'literal-constructor-formatting' === $case ) {
	$error = new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE );
	$formatted = \GraphQL\Error\FormattedError::createFromException( $error );
	echo json_encode( [ 'literal' => 'The cart session is temporarily unavailable.' === $formatted['message'], 'code' => 'WL_CART_SESSION_UNAVAILABLE' === $formatted['extensions']['code'], 'translationCalls' => $GLOBALS['http_translation_calls'] ] ); exit;
}

if ( in_array( $case, [ 'previous-same-object', 'invalid-cart-delegated', 'lookalike-delegated', 'cleanup-then-delegate' ], true ) ) {
	if ( 'invalid-cart-delegated' === $case ) { $GLOBALS['http_translation_fail'] = false; $original = new Cart_Session_Error( Cart_Session_Error::INVALID ); }
	elseif ( 'lookalike-delegated' === $case ) {
		$original = new class( 'WL_CART_SESSION_UNAVAILABLE' ) extends RuntimeException {
			public function getExtensions() { return [ 'code' => 'WL_CART_SESSION_UNAVAILABLE' ]; }
		};
	} else { $original = new RuntimeException( 'Synthetic unrelated exception.' ); }
	$previous = function ( Throwable $received ) use ( $original, &$cleanup_calls ) {
		echo json_encode( [ 'sameObject' => $received === $original, 'cleanupOnce' => 1 === $cleanup_calls ] );
	};
	set_exception_handler( $previous );
	if ( 'cleanup-then-delegate' === $case ) { $cleanup = function () use ( &$cleanup_calls ) { $cleanup_calls++; throw new Owned_Scope_Error(); }; }
	$boundary = new Cart_Session_HTTP_Boundary( $cleanup, [ 'woocommerce-session' ], [ 'woocommerce-session' ] );
	$boundary->install();
	throw $original;
}

if ( in_array( $case, [ 'restore-previous', 'restore-later', 'later-handler-at-finalize', 'default-rethrow-same-object' ], true ) ) {
	$previous = static function ( Throwable $error ) {};
	if ( 'default-rethrow-same-object' !== $case ) { set_exception_handler( $previous ); }
	$boundary = new Cart_Session_HTTP_Boundary( $cleanup, [] ); $boundary->install();
	if ( 'default-rethrow-same-object' === $case ) {
		$installed = http_current_handler(); $original = new RuntimeException( 'Synthetic unrelated exception.' );
		try { $installed( $original ); } catch ( Throwable $received ) {
			ob_end_clean(); echo json_encode( [ 'sameObject' => $received === $original, 'cleanupOnce' => 1 === $cleanup_calls, 'defaultRestored' => null === http_current_handler() ] ); exit;
		}
		exit( 2 );
	}
	$later = static function ( Throwable $error ) {};
	if ( 'restore-previous' === $case ) {
		$boundary->restore(); $current = http_current_handler(); ob_end_clean();
		echo json_encode( [ 'previousCurrent' => $current === $previous, 'cleanupNotInvoked' => 0 === $cleanup_calls ] ); exit;
	}
	if ( 'restore-later' === $case ) {
		set_exception_handler( $later ); $boundary->restore(); $current = http_current_handler();
		$boundary->restore(); $again = http_current_handler(); ob_end_clean();
		echo json_encode( [ 'laterCurrent' => $current === $later && $again === $later, 'cleanupNotInvoked' => 0 === $cleanup_calls ] ); exit;
	}
	$boundary->complete( [ 'data' => [ 'ok' => true ] ], function () use ( $later ) {
		set_exception_handler( $later );
		register_shutdown_function( function () use ( $later ) {
			if ( http_current_handler() !== $later ) { fwrite( STDERR, "Later handler was replaced.\n" ); }
		} );
	} );
}

// Queued headers remain native PHP state; no framework header functions are mocked.
header( 'Access-Control-Allow-Origin: https://validated.example.invalid' );
header( 'Access-Control-Allow-Credentials: true' );
header( 'Vary: Origin' );
header( 'X-Unrelated: preserved' );
header( 'Authorization: preserved-synthetic-auth' );
header( 'woocommerce-session: queued-synthetic-cart' );
header( 'X-Cart-Credential: queued-synthetic-cart' );
header( 'Set-Cookie: woocommerce-session=queued-synthetic-cart; Path=/; HttpOnly', false );
header( 'Set-Cookie: wordpress_logged_in_synthetic=preserved; Path=/; HttpOnly', false );
header( 'Set-Cookie: unrelated=preserved; Path=/', false );
header( 'Cache-Control: public, max-age=900' ); header( 'Expires: Wed, 01 Jan 2031 00:00:00 GMT' );
header( 'ETag: synthetic-stale' ); header( 'Content-Length: 9999' );
// A prior queued representation encoding does not describe the new raw JSON.
header( 'Content-Encoding: gzip' );

if ( 'unknown-buffer' === $case ) {
	ob_start( static fn( $buffer ) => 'UNQUALIFIED-UNKNOWN-CALLBACK' );
}
if ( 'early-output' === $case ) { echo 'UNQUALIFIED-EARLY-OUTPUT'; flush(); }
if ( 'baseline-default-buffer' === $case ) { ob_start(); echo 'discarded-baseline-synthetic'; }
$boundary = new Cart_Session_HTTP_Boundary( $cleanup, [ 'woocommerce-session', 'X-Cart-Credential' ], [ 'woocommerce-session' ] );
$boundary->install();
echo 'discarded-synthetic-output-and-SQL';

if ( 'owned-uncaught' === $case ) { throw new Owned_Scope_Error(); }
if ( 'cart-unavailable-uncaught' === $case ) { throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE ); }
if ( in_array( $case, [ 'explicit-fail', 'cleanup-throws', 'cleanup-cannot-revive', 'later-handler-at-cleanup' ], true ) ) { $boundary->fail(); }
if ( in_array( $case, [ 'discard', 'discard-cleanup-throws' ], true ) ) {
	$boundary->discard( [ 'errors' => [ [ 'message' => 'Synthetic authentication required.', 'extensions' => [ 'code' => 'SYNTHETIC_AUTH_REQUIRED' ] ] ] ], 403 );
}
if ( in_array( $case, [ 'preencode-failure', 'owned-buffer-removed' ], true ) ) {
	$finalize_calls = 0;
	register_shutdown_function( function () use ( &$finalize_calls ) {
		if ( 0 !== $finalize_calls ) { fwrite( STDERR, "Finalizer ran before preencoding/admission succeeded.\n" ); }
	} );
	$response = new class implements JsonSerializable {
		public function jsonSerialize(): mixed { throw new Owned_Scope_Error(); }
	};
	if ( 'owned-buffer-removed' === $case ) { ob_end_clean(); $response = [ 'data' => [ 'ok' => true ] ]; }
	$boundary->complete( $response, function () use ( &$finalize_calls ) { $finalize_calls++; } );
}
if ( 'json-encoding-failure' === $case ) { $boundary->complete( [ 'data' => NAN ], static function () {} ); }
if ( in_array( $case, [ 'finalize-failure', 'seal-failure', 'release-failure' ], true ) ) {
	$phases = [];
	$expected = 'finalize-failure' === $case ? [ 'finalize' ] : ( 'seal-failure' === $case ? [ 'finalize', 'save', 'seal' ] : [ 'finalize', 'save', 'seal', 'release' ] );
	register_shutdown_function( function () use ( &$phases, $expected ) {
		if ( $phases !== $expected ) { fwrite( STDERR, "Controlled finalization phase order differed.\n" ); }
	} );
	$boundary->complete( [ 'data' => [ 'ok' => true ] ], function () use ( &$phases, $case ) {
		$phases[] = 'finalize';
		if ( 'finalize-failure' === $case ) { throw new Owned_Scope_Error(); }
		$phases[] = 'save'; $phases[] = 'seal';
		if ( 'seal-failure' === $case ) { throw new Owned_Scope_Error(); }
		$phases[] = 'release'; throw new Owned_Scope_Error();
	} );
}
if ( in_array( $case, [ 'success', 'baseline-default-buffer', 'status-preserved' ], true ) ) {
	$encoding_calls = 0;
	$response = new class( $owned, $encoding_calls ) implements JsonSerializable {
		private $owned; private $calls;
		public function __construct( &$owned, &$calls ) { $this->owned =& $owned; $this->calls =& $calls; }
		public function jsonSerialize(): mixed {
			$this->calls++; http_expect( $this->owned && 1 === $this->calls );
			return [ 'data' => [ 'encodedWhileOwned' => true, 'serializationCount' => $this->calls ] ];
		}
	};
	if ( 'status-preserved' === $case ) { http_response_code( 202 ); }
	$boundary->complete( $response, function () use ( &$owned, &$encoding_calls, &$cleanup_calls ) {
		http_expect( $owned && 1 === $encoding_calls && 0 === $cleanup_calls );
		$owned = false; header( 'X-Contract-Finalized: 1' );
		echo 'discarded-after-finalize-synthetic';
	} );
}
$boundary->fail();

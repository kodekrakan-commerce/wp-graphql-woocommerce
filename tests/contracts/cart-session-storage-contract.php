<?php
/**
 * Offline component contract: actual storage helper, capability interface,
 * WC cache-prefix helper and GraphQL errors. SQL, cache and hydration are explicit
 * controlled boundaries. No actual transaction/MySQL/WordPress request acceptance.
 *
 * Inputs: WL_MU_PLUGINS_SOURCE, WL_WOOCOMMERCE_SOURCE, WL_WPGRAPHQL_SOURCE.
 */
error_reporting( E_ALL ); ini_set( 'display_errors', '0' ); ini_set( 'log_errors', '0' );
$mu = rtrim( getenv( 'WL_MU_PLUGINS_SOURCE' ) ?: '', '/' );
$wc = rtrim( getenv( 'WL_WOOCOMMERCE_SOURCE' ) ?: '', '/' );
$gql = rtrim( getenv( 'WL_WPGRAPHQL_SOURCE' ) ?: '', '/' );
$owner = dirname( __DIR__, 2 );
$required = [ $mu . '/database/interface-owned-scope-driver.php', $wc . '/src/Caching/CacheNameSpaceTrait.php', $wc . '/includes/class-wc-cache-helper.php', $gql . '/vendor/autoload.php' ];
foreach ( $required as $file ) {
	if ( ! is_file( $file ) ) { fwrite( STDERR, "Required genuine component source unavailable; configure source environment inputs.\n" ); exit( 2 ); }
}
define( 'ABSPATH', __DIR__ . '/' ); define( 'DB_NAME', 'offline_synthetic_contract' );
define( 'WC_SESSION_CACHE_GROUP', 'woocommerce_sessions' );
require $required[0]; require $required[3];
require __DIR__ . '/cart-session-storage-fixtures.php';
require $required[1]; require $required[2];
require $owner . '/includes/utils/class-cart-session-error.php';
require $owner . '/includes/utils/class-cart-session-storage.php';

use WPGraphQL\WooCommerce\Utils\Cart_Session_Storage;

$cases = [];
$cases['ownership precedes every authoritative read'] = function () {
	[ $storage, $db ] = storage_fixture();
	storage_unavailable( fn() => $storage->read() ); storage_expect( 0 === $db->reads );
	[ $storage, $db ] = storage_fixture(); $storage->acquire();
	storage_expect( [ 'cart' => 'authoritative-synthetic' ] === $storage->read() );
	storage_expect( array_search( 'begin', $GLOBALS['storage_trace'], true ) < array_search( 'session-read', $GLOBALS['storage_trace'], true ) );
};
$cases['missing driver fails without persistence or cache effects'] = function () {
	storage_fixture(); $GLOBALS['wpdb'] = new stdClass();
	storage_unavailable( fn() => new Cart_Session_Storage( str_repeat( 'a', 32 ), 'wp_woocommerce_sessions' ) );
	storage_expect( [] === $GLOBALS['storage_deletions'] && [] === $GLOBALS['storage_publications'] );
};
foreach ( [ 'foreign_table', 'wp_woocommerce_sessions;DROP', 'db.wp_woocommerce_sessions', '`wp_woocommerce_sessions`', '', str_repeat( 'a', 65 ) ] as $index => $table ) {
	$cases[ 'malformed or unrelated table ' . $index ] = function () use ( $table ) {
		[ , $db ] = storage_fixture(); storage_unavailable( fn() => new Cart_Session_Storage( str_repeat( 'a', 32 ), $table ) );
		storage_expect( 0 === $db->reads && 0 === $db->writes && [] === $GLOBALS['storage_trace'] );
	};
}
foreach ( [ [ '17', 0 ], [ '18', 17 ], [ '017', 17 ], [ true, 0 ], [ [], 0 ], [ str_repeat( 'a', 32 ), '0' ] ] as $index => $arguments ) {
	$cases[ 'invalid admitted binding ' . $index ] = function () use ( $arguments ) {
		[ , $db ] = storage_fixture(); storage_unavailable( fn() => new Cart_Session_Storage( $arguments[0], 'wp_woocommerce_sessions', $arguments[1] ) );
		storage_expect( 0 === $db->reads && 0 === $db->writes );
	};
}
$cases['lock tuple deterministic scoped and at most 64 bytes'] = function () {
	[ $first, $db ] = storage_fixture(); $first->acquire( 5 ); $lock = $db->locks;
	storage_expect( 1 === count( $lock ) && 64 === strlen( $lock[0] ) && 5 === $db->timeout );
	[ $second, $other ] = storage_fixture(); $second->acquire(); storage_expect( $lock === $other->locks && 0 === $other->timeout );
	$third = new Cart_Session_Storage( str_repeat( 'b', 32 ), 'wp_woocommerce_sessions' );
	$third->acquire(); storage_expect( $lock !== $other->locks );
};
foreach ( [ -1, 6, '0', true ] as $index => $timeout ) {
	$cases[ 'invalid bounded timeout ' . $index ] = function () use ( $timeout ) {
		[ $storage, $db ] = storage_fixture(); storage_unavailable( fn() => $storage->acquire( $timeout ) );
		storage_expect( [] === $GLOBALS['storage_trace'] && 0 === $db->reads );
	};
}
$cases['driver acquisition failure does not evict or read'] = function () {
	[ $storage, $db ] = storage_fixture(); $db->throw_on_begin = true; storage_unavailable( fn() => $storage->acquire() );
	storage_expect( 0 === $db->reads && [] === $GLOBALS['storage_deletions'] );
};
$cases['replacement global before acquisition fails'] = function () {
	[ $storage, $db ] = storage_fixture(); $GLOBALS['wpdb'] = new Storage_Contract_Database();
	storage_unavailable( fn() => $storage->acquire() ); storage_expect( [] === $db->locks );
};
$cases['replacement global between preparation and read fails'] = function () {
	[ $storage, $db ] = storage_fixture(); $storage->acquire(); $db->change_on_prepare = true;
	storage_unavailable( fn() => $storage->read() ); storage_expect( 0 === $db->reads );
};
foreach ( [ 'read', 'write', 'account' ] as $operation ) {
	$cases[ 'invalid SQL preparation ' . $operation . ' cannot reach persistence' ] = function () use ( $operation ) {
		[ $storage, $db ] = storage_fixture( 'account' === $operation ); $storage->acquire(); $db->invalid_prepare = true;
		storage_unavailable( fn() => 'read' === $operation ? $storage->read() : ( 'write' === $operation ? $storage->write( [], 12345 ) : $storage->invalidate_account_caches() ) );
		storage_expect( 0 === $db->reads && 0 === $db->writes );
	};
}
$cases['lost ownership before read fails without query'] = function () {
	[ $storage, $db ] = storage_fixture(); $storage->acquire(); $db->failed = true;
	storage_unavailable( fn() => $storage->read() ); storage_expect( 0 === $db->reads );
};
$cases['lost ownership during read cannot return data'] = function () {
	[ $storage, $db ] = storage_fixture(); $storage->acquire(); $db->lose_on_read = true;
	storage_unavailable( fn() => $storage->read() ); storage_expect( 1 === $db->reads );
};
$cases['driver failure report cannot be ignored'] = function () {
	[ $storage, $db ] = storage_fixture(); $storage->acquire(); $db->report_failed = true;
	storage_unavailable( fn() => $storage->read() ); storage_expect( 0 === $db->reads );
};
$cases['read bypasses stale session cache and evicts only target'] = function () {
	[ $storage, $db ] = storage_fixture();
	$group = WC_SESSION_CACHE_GROUP; $key = storage_session_key(); $neighbor = storage_session_key( str_repeat( 'b', 32 ) );
	$GLOBALS['storage_cache'][ $group ][ $key ] = [ 'cart' => 'stale-synthetic' ];
	$GLOBALS['storage_cache'][ $group ][ $neighbor ] = [ 'cart' => 'neighbor-synthetic' ];
	$storage->acquire(); storage_expect( [ [ $key, $group ] ] === $GLOBALS['storage_deletions'] );
	// Repopulate after eviction: authoritative reads must still ignore it.
	$GLOBALS['storage_cache'][ $group ][ $key ] = [ 'cart' => 'repopulated-synthetic' ];
	storage_expect( [ 'cart' => 'authoritative-synthetic' ] === $storage->read() );
	storage_expect( isset( $GLOBALS['storage_cache'][ $group ][ $neighbor ] ) && [] === $GLOBALS['storage_publications'] );
	storage_expect( 'fixed-prefix' === $GLOBALS['storage_cache'][ $group ][ 'wc_' . $group . '_cache_prefix' ] );
};
$cases['cache delete false with confirmed absence is valid'] = function () {
	[ $storage ] = storage_fixture(); $GLOBALS['storage_delete_false'] = true; $storage->acquire();
	storage_expect( is_array( $storage->read() ) );
};
$cases['cache delete success but remaining found key fails'] = function () {
	[ $storage, $db ] = storage_fixture(); $GLOBALS['storage_cache'][ WC_SESSION_CACHE_GROUP ][ storage_session_key() ] = false;
	$GLOBALS['storage_cache_sticky'] = true; storage_unavailable( fn() => $storage->acquire() ); storage_expect( 0 === $db->reads );
};
$cases['absent SQL row returns explicit default'] = function () {
	[ $storage, $db ] = storage_fixture(); $storage->acquire(); $db->value = null;
	storage_expect( [] === $storage->read( [] ) );
};
foreach ( [ false, 'corrupt-synthetic', serialize( 'not-array' ) ] as $index => $value ) {
	$cases[ 'invalid SQL result ' . $index ] = function () use ( $value ) {
		[ $storage, $db ] = storage_fixture(); $storage->acquire(); $db->value = $value; storage_unavailable( fn() => $storage->read() );
	};
}
$cases['null SQL result with error is unavailable not missing'] = function () {
	[ $storage, $db ] = storage_fixture(); $storage->acquire(); $db->value = null; $db->read_error = true;
	storage_unavailable( fn() => $storage->read() );
};
foreach ( [ 'write', 'delete', 'update_timestamp' ] as $method ) {
	$invoke = fn( $storage ) => 'write' === $method ? $storage->write( [ 'cart' => 'synthetic' ], 12345 ) : ( 'delete' === $method ? $storage->delete() : $storage->update_timestamp( 12345 ) );
	$cases[ $method . ' zero affected rows valid and exact cache eviction' ] = function () use ( $invoke ) {
		[ $storage, $db ] = storage_fixture(); $storage->acquire(); $db->write_result = 0;
		storage_expect( 0 === $invoke( $storage ) && 1 === $db->writes && 2 === count( $GLOBALS['storage_deletions'] ) && [] === $GLOBALS['storage_publications'] );
	};
	$cases[ $method . ' false query no publication or success signal' ] = function () use ( $invoke ) {
		[ $storage, $db ] = storage_fixture(); $storage->acquire(); $db->write_result = false; $dirty = true;
		storage_unavailable( function () use ( $invoke, $storage, &$dirty ) { $invoke( $storage ); $dirty = false; } );
		storage_expect( $dirty && 1 === $db->writes && 1 === count( $GLOBALS['storage_deletions'] ) && [] === $GLOBALS['storage_publications'] );
		storage_unavailable( fn() => $storage->read() ); storage_expect( 0 === $db->reads );
	};
}
$cases['upsert serializes exactly the admitted array and expiry'] = function () {
	[ $storage, $db ] = storage_fixture(); $storage->acquire(); $data = [ 'cart' => [ 'line' => 2 ], 'nested-serialized' => serialize( [ 1 ] ) ];
	storage_expect( 1 === $storage->write( $data, 12345 ) ); $query = end( $db->queries );
	storage_expect( str_contains( $query[0], 'ON DUPLICATE KEY UPDATE' ) && [ 'wp_woocommerce_sessions', str_repeat( 'a', 32 ), serialize( $data ), 12345 ] === $query[1] );
};
$cases['ownership lost during successful write cannot signal success'] = function () {
	[ $storage, $db ] = storage_fixture(); $storage->acquire(); $db->lose_on_write = true;
	storage_unavailable( fn() => $storage->write( [], 12345 ) ); storage_expect( [] === $GLOBALS['storage_publications'] );
};
$cases['successful SQL with failed postwrite eviction unavailable'] = function () {
	[ $storage, $db ] = storage_fixture(); $storage->acquire(); $db->repopulate_on_write = true; $GLOBALS['storage_cache_sticky'] = true;
	storage_unavailable( fn() => $storage->write( [], 12345 ) ); storage_expect( 1 === $db->writes && [] === $GLOBALS['storage_publications'] );
};
$cases['account targeted caches invalidated before controlled hydration'] = function () {
	[ $storage, $db ] = storage_fixture( true );
	$old = (object) [ 'ID' => 17, 'user_login' => 'old-synthetic-login', 'user_email' => 'old@example.invalid', 'user_nicename' => 'old-synthetic-slug' ];
	$GLOBALS['storage_cache']['users'][17] = $old; $GLOBALS['storage_cache']['user_meta'][17] = [ 'billing_city' => [ 'stale-synthetic' ] ];
	$GLOBALS['storage_cache']['users'][18] = (object) [ 'ID' => 18 ];
	foreach ( [ 'user_login' => 'userlogins', 'user_email' => 'useremail', 'user_nicename' => 'userslugs' ] as $property => $group ) {
		$GLOBALS['storage_cache'][ $group ][ $old->$property ] = 17; $GLOBALS['storage_cache'][ $group ][ $db->row->$property ] = 17;
	}
	$storage->acquire(); $storage->invalidate_account_caches();
	// Controlled hydration boundary checks cache absence; no claim actual WC datastore hydration.
	$GLOBALS['storage_trace'][] = 'hydrate';
	storage_expect( ! array_key_exists( 17, $GLOBALS['storage_cache']['users'] ) && ! array_key_exists( 17, $GLOBALS['storage_cache']['user_meta'] ) && isset( $GLOBALS['storage_cache']['users'][18] ) );
	storage_expect( 1 === $db->reads && array_search( 'begin', $GLOBALS['storage_trace'], true ) < array_search( 'account-read', $GLOBALS['storage_trace'], true ) );
	foreach ( [ 'user_login' => 'userlogins', 'user_email' => 'useremail', 'user_nicename' => 'userslugs' ] as $property => $group ) {
		storage_expect( ! array_key_exists( $old->$property, $GLOBALS['storage_cache'][ $group ] ) && ! array_key_exists( $db->row->$property, $GLOBALS['storage_cache'][ $group ] ) );
	}
	storage_expect( [] === $GLOBALS['storage_publications'] );
};
$cases['old alias belonging to another account is preserved'] = function () {
	[ $storage, $db ] = storage_fixture( true ); $GLOBALS['storage_cache']['users'][17] = (object) [ 'ID' => 17, 'user_login' => 'reused-synthetic' ];
	$GLOBALS['storage_cache']['userlogins']['reused-synthetic'] = 18; $storage->acquire(); $storage->invalidate_account_caches();
	storage_expect( 18 === $GLOBALS['storage_cache']['userlogins']['reused-synthetic'] );
};
$cases['guest cache invalidation does not inspect account storage'] = function () {
	[ $storage, $db ] = storage_fixture(); $storage->acquire(); $storage->invalidate_account_caches(); storage_expect( 0 === $db->reads );
};
$cases['account cache retained by dropin fails closed before hydration'] = function () {
	[ $storage, $db ] = storage_fixture( true ); $storage->acquire(); $GLOBALS['storage_cache']['user_meta'][17] = false; $GLOBALS['storage_cache_sticky'] = true;
	storage_unavailable( fn() => $storage->invalidate_account_caches() );
};
$cases['account SQL failure cannot proceed to hydration'] = function () {
	[ $storage, $db ] = storage_fixture( true ); $storage->acquire(); $db->read_error = true;
	storage_unavailable( fn() => $storage->invalidate_account_caches() );
};
$cases['seal release ends storage access'] = function () {
	[ $storage, $db ] = storage_fixture(); $storage->acquire(); $storage->seal(); $storage->assert_owned(); $storage->release();
	storage_expect( 'released' === $db->state ); storage_unavailable( fn() => $storage->read() );
};
$cases['sealed scope cannot perform further writes'] = function () {
	[ $storage, $db ] = storage_fixture(); $storage->acquire(); $storage->seal(); storage_unavailable( fn() => $storage->write( [], 12345 ) ); storage_expect( 0 === $db->writes );
};
$cases['failure abort uses captured handle and prevents retry'] = function () {
	[ $storage, $db ] = storage_fixture(); $storage->acquire(); $db->failed = true;
	storage_unavailable( fn() => $storage->read() ); $storage->abort(); storage_expect( 'failed' === $db->state && in_array( 'abort', $GLOBALS['storage_trace'], true ) );
	storage_unavailable( fn() => $storage->acquire() );
};
$cases['global replacement abort cleans captured driver exactly once'] = function () {
	[ $storage, $old ] = storage_fixture(); $storage->acquire(); $handle = $old->handle;
	$replacement = new Storage_Contract_Database(); $GLOBALS['wpdb'] = $replacement;
	storage_unavailable( fn() => $storage->read() );
	$storage->abort(); $storage->abort();
	storage_expect( 1 === $old->abort_calls && [ $handle ] === $old->abort_handles );
	storage_expect( 0 === $old->reads && 0 === $old->writes && 0 === $replacement->abort_calls );
	storage_expect( [] === $replacement->locks && [] === $replacement->queries && 0 === $replacement->reads && 0 === $replacement->writes );
	storage_expect( [] === $replacement->calls );
	storage_expect( [] === $GLOBALS['storage_publications'] );
	storage_unavailable( fn() => $storage->acquire() );
};
$cases['clean release makes later abort inert even with replacement global'] = function () {
	[ $storage, $old ] = storage_fixture(); $storage->acquire(); $storage->seal(); $storage->release();
	$replacement = new Storage_Contract_Database(); $GLOBALS['wpdb'] = $replacement;
	$storage->abort(); $storage->abort();
	storage_expect( 0 === $old->abort_calls && 0 === $replacement->abort_calls && 'released' === $old->state );
	storage_unavailable( fn() => $storage->acquire() );
	storage_expect( [] === $replacement->locks && [] === $replacement->queries );
	storage_expect( [] === $replacement->calls );
};
$cases['captured cleanup failure stays typed unavailable'] = function () {
	[ $storage, $old ] = storage_fixture(); $storage->acquire(); $old->throw_on_abort = true;
	$replacement = new Storage_Contract_Database(); $GLOBALS['wpdb'] = $replacement;
	storage_unavailable( fn() => $storage->abort() );
	storage_expect( 1 === $old->abort_calls && [ $old->handle ] === $old->abort_handles && 0 === $replacement->abort_calls );
	storage_unavailable( fn() => $storage->read() );
	storage_expect( 0 === $old->reads && 0 === $old->writes && [] === $replacement->queries && [] === $GLOBALS['storage_publications'] );
	storage_expect( [] === $replacement->calls );
};

foreach ( [ 'active', 'sealed', 'released' ] as $state ) {
	$cases[ 'response observation performs no SQL probes or reacquisition ' . $state ] = function () use ( $state ) {
		[ $storage, $db ] = storage_fixture(); $storage->acquire();
		if ( 'active' !== $state ) { $storage->seal(); }
		if ( 'released' === $state ) { $storage->release(); }
		$db->calls = []; $storage->assert_response_available();
		storage_expect( [ 'failure-state' ] === $db->calls && 0 === $db->reads && 0 === $db->writes && $state === $db->state );
	};
}
$cases['released response observes sticky driver failure without SQL'] = function () {
	[ $storage, $db ] = storage_fixture(); $storage->acquire(); $storage->seal(); $storage->release();
	$db->report_failed = true; $db->calls = [];
	storage_unavailable( fn() => $storage->assert_response_available() );
	storage_expect( [ 'failure-state' ] === $db->calls && 0 === $db->reads && 0 === $db->writes );
};
$cases['released response refuses global driver replacement without calling replacement'] = function () {
	[ $storage, $db ] = storage_fixture(); $storage->acquire(); $storage->seal(); $storage->release();
	$replacement = new Storage_Contract_Database(); $GLOBALS['wpdb'] = $replacement; $db->calls = [];
	storage_unavailable( fn() => $storage->assert_response_available() );
	storage_expect( [] === $db->calls && [] === $replacement->calls );
};
$cases['response observer refuses unacquired or aborted authority'] = function () {
	[ $storage, $db ] = storage_fixture(); storage_unavailable( fn() => $storage->assert_response_available() );
	storage_expect( [] === $db->calls );
	[ $storage, $db ] = storage_fixture(); $storage->acquire(); $storage->abort(); $db->calls = [];
	storage_unavailable( fn() => $storage->assert_response_available() ); storage_expect( [] === $db->calls );
};
$cases['response observer rejects driver state divergence without SQL'] = function () {
	[ $storage, $db ] = storage_fixture(); $storage->acquire(); $db->state = 'released'; $db->calls = [];
	storage_unavailable( fn() => $storage->assert_response_available() ); storage_expect( [ 'failure-state' ] === $db->calls );
};

$failed = 0;
foreach ( $cases as $name => $case ) {
	try { $case(); echo 'PASS ' . $name . PHP_EOL; }
	catch ( Throwable $error ) { $failed++; echo 'FAIL ' . $name . ' [sanitized assertion or boundary failure]' . PHP_EOL; }
}
echo 'RESULT ' . count( $cases ) . ' cases, ' . $failed . ' failures' . PHP_EOL;
echo 'SOURCE helper-sha256=' . hash_file( 'sha256', $owner . '/includes/utils/class-cart-session-storage.php' ) . PHP_EOL;
echo 'SOURCE interface-sha256=' . hash_file( 'sha256', $required[0] ) . PHP_EOL;
echo 'SOURCE wc-cache-helper-sha256=' . hash_file( 'sha256', $required[2] ) . PHP_EOL;
exit( $failed ? 1 : 0 );

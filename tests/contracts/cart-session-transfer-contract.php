<?php
/**
 * Dormant T1 storage contracts: actual helper/interface/WC cache prefix/errors.
 * Driver, SQL rows/transactions and caches are controlled substitutes. This does
 * not authorize an account, run checkout or prove MySQL/WordPress acceptance.
 * Inputs: WL_MU_PLUGINS_SOURCE, WL_WOOCOMMERCE_SOURCE, WL_WPGRAPHQL_SOURCE.
 */
error_reporting( E_ALL ); ini_set( 'display_errors', '0' ); ini_set( 'log_errors', '0' );
$mu = rtrim( getenv( 'WL_MU_PLUGINS_SOURCE' ) ?: '', '/' );
$wc = rtrim( getenv( 'WL_WOOCOMMERCE_SOURCE' ) ?: '', '/' );
$gql = rtrim( getenv( 'WL_WPGRAPHQL_SOURCE' ) ?: '', '/' );
$required = [ $mu . '/database/interface-owned-scope-driver.php', $wc . '/src/Caching/CacheNameSpaceTrait.php',
	$wc . '/includes/class-wc-cache-helper.php', $gql . '/vendor/autoload.php' ];
foreach ( $required as $file ) {
	if ( ! is_file( $file ) ) { fwrite( STDERR, "Required genuine component unavailable; configure source environment inputs.\n" ); exit( 2 ); }
}
define( 'ABSPATH', __DIR__ . '/' ); define( 'DB_NAME', 'offline_synthetic_contract' ); define( 'WC_SESSION_CACHE_GROUP', 'woocommerce_sessions' );
require $required[0]; require $required[3];
require __DIR__ . '/cart-session-storage-fixtures.php';
require $required[1]; require $required[2];
require dirname( __DIR__, 2 ) . '/includes/utils/class-cart-session-error.php';
require dirname( __DIR__, 2 ) . '/includes/utils/class-cart-session-storage.php';
require __DIR__ . '/cart-session-transfer-fixtures.php';

use WPGraphQL\WooCommerce\Utils\Cart_Session_Storage;

$cases = [];
$cases['freeze persists exact bytes and expiry before handoff'] = function () {
	[ $guest, $db, $id, $uuid ] = transfer_frozen();
	storage_expect( [ 'guest-persist' ] === $db->commands && 1 === count( $db->grants ) );
	storage_expect( [ 'bytes' => serialize( [ 'cart' => [ 'quantity' => 3 ] ] ), 'expiry' => 123456789 ] === $db->rows[$id] );
	storage_expect( 1 === preg_match( '/\A[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/D', $uuid ) );
	$guest->abort(); storage_expect( $db->abort_handles === [ $db->grants[0]['handle'] ] );
};
foreach ( [ 'read', 'write', 'delete', 'timestamp', 'hydrate', 'seal' ] as $operation ) {
	$cases['frozen guest blocks ordinary ' . $operation] = function () use ( $operation ) {
		[ $guest, $db ] = transfer_frozen(); $before = $db->commands;
		storage_unavailable( fn() => match ( $operation ) {
			'read' => $guest->read(), 'write' => $guest->write( [], 123 ), 'delete' => $guest->delete(),
			'timestamp' => $guest->update_timestamp( 123 ), 'hydrate' => $guest->invalidate_account_caches(), 'seal' => $guest->seal(),
		} );
		storage_expect( $before === $db->commands && 1 === count( $db->grants ) );
		storage_unavailable( fn() => $guest->stage_checkout_transfer( 17, 'wp_options' ) );
		storage_expect( 1 === count( $db->grants ) );
	};
}
$cases['duplicate freeze never produces a second handoff'] = function () {
	[ $guest, $db ] = transfer_frozen();
	storage_unavailable( fn() => $guest->freeze_for_checkout_transfer( [], 123 ) );
	storage_unavailable( fn() => $guest->stage_checkout_transfer( 17, 'wp_options' ) );
	storage_expect( 1 === count( $db->grants ) && [ 'guest-persist' ] === $db->commands );
};
$cases['account storage cannot freeze as guest'] = function () {
	[ , $db ] = transfer_fixture(); $db->seal_owned_scope( $db->handle ); $db->release_owned_scope( $db->handle );
	$account = new Cart_Session_Storage( '17', 'wp_woocommerce_sessions', 17 ); $account->acquire();
	storage_unavailable( fn() => $account->freeze_for_checkout_transfer( [], 123 ) );
	storage_expect( [] === $db->commands );
};
$cases['dual locks sorted in either tuple order and remain owned after commit'] = function () {
	$orders = [];
	for ( $i = 0; $i < 80 && count( $orders ) < 2; $i++ ) {
		$id = str_pad( dechex( $i ), 32, '0', STR_PAD_LEFT );
		$order = strcmp( transfer_hash( $id ), transfer_hash( '17' ) ) < 0 ? 'source-first' : 'destination-first';
		if ( isset( $orders[$order] ) ) { continue; }
		[ $guest, $db, , $uuid ] = transfer_frozen( $id );
		$account = $guest->transfer_to_fresh_account( 17, 'wp_options' );
		$expected = [ transfer_hash( $id ), transfer_hash( '17' ) ]; sort( $expected, SORT_STRING );
		storage_expect( $expected === $db->grants[1]['locks'] && 5 === $db->grants[1]['timeout'] );
		storage_expect( $db->grants[0]['handle'] !== $db->grants[1]['handle'] ); $account->assert_owned();
		storage_expect( $db->rows[$id] === $db->rows['17'] && null === $db->pending );
		storage_expect( [ 'value' => transfer_marker( $id, $uuid ), 'autoload' => 'no' ] === $db->ledger['wl_cart_retired_v1_' . transfer_hash( $id )] );
		storage_expect( [ 'guest-persist', 'start', 'destination-insert', 'ledger-insert', 'commit' ] === $db->commands );
		$orders[$order] = true;
	}
	storage_expect( 2 === count( $orders ) );
};
$cases['pending transfer never evicts source destination or neighboring caches'] = function () {
	[ $guest, $db, $id ] = transfer_frozen();
	$before = count( $GLOBALS['storage_deletions'] );
	foreach ( [ $id, '17', '18' ] as $target ) { $GLOBALS['storage_cache'][WC_SESSION_CACHE_GROUP][storage_session_key( $target )] = 'synthetic-stale'; }
	$account = $guest->stage_checkout_transfer( 17, 'wp_options' );
	storage_expect( count( $GLOBALS['storage_deletions'] ) === $before && ! isset( $db->rows['17'] ) && [] === $db->ledger );
	$account->commit_checkout_transfer();
	storage_expect( array_slice( $GLOBALS['storage_deletions'], $before ) === [ [ storage_session_key( '17' ), WC_SESSION_CACHE_GROUP ], [ storage_session_key( $id ), WC_SESSION_CACHE_GROUP ] ] );
	storage_expect( isset( $GLOBALS['storage_cache'][WC_SESSION_CACHE_GROUP][storage_session_key( '18' )] ) && [] === $GLOBALS['storage_publications'] );
	storage_expect( [ 'cart' => [ 'quantity' => 3 ] ] === $account->read() );
};
$cases['retired guest cleanup cannot abort later dual grant'] = function () {
	[ $guest, $db ] = transfer_frozen(); $account = $guest->stage_checkout_transfer( 17, 'wp_options' );
	$guest->abort(); $guest->abort(); $account->assert_owned();
	storage_expect( [] === $db->abort_handles );
	storage_unavailable( fn() => $guest->read() ); $account->assert_owned();
	storage_unavailable( fn() => $guest->stage_checkout_transfer( 17, 'wp_options' ) );
	storage_expect( 2 === count( $db->grants ) && [] === $db->abort_handles );
	$account->commit_checkout_transfer();
};
$cases['committed destination can flush under retained dual ownership without rewriting ledger'] = function () {
	[ $guest, $db, $id ] = transfer_frozen(); $account = $guest->transfer_to_fresh_account( 17, 'wp_options' );
	$ledger = $db->ledger; $source = $db->rows[$id];
	$account->write( [ 'cart' => [ 'quantity' => 4 ] ], 123456790 );
	storage_expect( $source === $db->rows[$id] && $ledger === $db->ledger && 2 === count( $db->grants ) );
	storage_expect( [ 'cart' => [ 'quantity' => 4 ] ] === $account->read() );
	$account->seal(); $account->release(); $account->assert_response_available(); $guest->abort();
	storage_expect( [] === $db->abort_handles );
};
foreach ( [ 'read', 'write', 'hydrate', 'seal' ] as $operation ) {
	$cases['pending account blocks application ' . $operation] = function () use ( $operation ) {
		[ $guest, $db ] = transfer_frozen(); $account = $guest->stage_checkout_transfer( 17, 'wp_options' ); $before = $db->commands;
		storage_unavailable( fn() => match ( $operation ) { 'read' => $account->read(), 'write' => $account->write( [], 123 ),
			'hydrate' => $account->invalidate_account_caches(), 'seal' => $account->seal() } );
		storage_expect( $before === $db->commands ); $account->abort();
		storage_expect( [ $db->grants[1]['handle'] ] === $db->abort_handles && [] === $db->ledger );
	};
}
foreach ( [ 'bytes', 'expiry', 'missing', 'destination', 'ledger' ] as $collision ) {
	$cases['dual authoritative recheck refuses ' . $collision] = function () use ( $collision ) {
		[ $guest, $db, $id ] = transfer_frozen();
		$db->on_dual = function ( $driver ) use ( $collision, $id ) {
			switch ( $collision ) {
				case 'bytes': $driver->rows[$id]['bytes'] = serialize( [] ); break;
				case 'expiry': $driver->rows[$id]['expiry']++; break;
				case 'missing': unset( $driver->rows[$id] ); break;
				case 'destination': $driver->rows['17'] = [ 'bytes' => serialize( [] ), 'expiry' => 123 ]; break;
				case 'ledger': $driver->ledger['wl_cart_retired_v1_' . transfer_hash( $id )] = [ 'value' => 'malformed-existing', 'autoload' => 'no' ]; break;
			}
		};
		storage_unavailable( fn() => $guest->stage_checkout_transfer( 17, 'wp_options' ) );
		storage_expect( [ 'guest-persist' ] === $db->commands && [ $db->grants[1]['handle'] ] === $db->abort_handles );
		storage_unavailable( fn() => $guest->stage_checkout_transfer( 17, 'wp_options' ) ); storage_expect( 2 === count( $db->grants ) );
	};
}
foreach ( [ 'other_options', 'wp_options;DROP', 'db.wp_options' ] as $index => $table ) {
	$cases['unqualified options table refused before handoff ' . $index] = function () use ( $table ) {
		[ $guest, $db ] = transfer_frozen(); storage_unavailable( fn() => $guest->stage_checkout_transfer( 17, $table ) );
		storage_expect( 1 === count( $db->grants ) && [ 'guest-persist' ] === $db->commands );
	};
}
foreach ( [ [ '17', 5 ], [ 0, 5 ], [ 17, 6 ], [ 17, '5' ] ] as $index => $arguments ) {
	$cases['invalid transfer binding or budget refused ' . $index] = function () use ( $arguments ) {
		[ $guest, $db ] = transfer_frozen(); storage_unavailable( fn() => $guest->stage_checkout_transfer( $arguments[0], 'wp_options', $arguments[1] ) );
		storage_expect( 1 === count( $db->grants ) );
	};
}
foreach ( [ 'database', 'session-engine', 'options-engine', 'missing-table', 'duplicate-table', 'options-pointer', 'prefix' ] as $failure ) {
	$cases['pinned database and transactional table qualification ' . $failure] = function () use ( $failure ) {
		[ $guest, $db ] = transfer_frozen();
		$db->on_dual = function ( $driver ) use ( $failure ) {
			switch ( $failure ) {
				case 'database': $driver->database = 'other_synthetic_database'; break;
				case 'session-engine': $driver->engines[0]->ENGINE = 'MyISAM'; break;
				case 'options-engine': $driver->engines[1]->ENGINE = 'MyISAM'; break;
				case 'missing-table': array_pop( $driver->engines ); break;
				case 'duplicate-table': $driver->engines[1] = clone $driver->engines[0]; break;
				case 'options-pointer': $driver->options = 'foreign_options'; break;
				case 'prefix': $driver->prefix = 'foreign_'; break;
			}
		};
		storage_unavailable( fn() => $guest->stage_checkout_transfer( 17, 'wp_options' ) );
		storage_expect( [ 'guest-persist' ] === $db->commands && [ $db->grants[1]['handle'] ] === $db->abort_handles );
	};
}
foreach ( [ 'start', 'destination-insert', 'ledger-insert' ] as $command ) {
	foreach ( [ false, true, 0, 2 ] as $index => $result ) {
		if ( 'start' === $command && 0 === $result ) { continue; }
		$cases['checked SQL refuses wrong result ' . $command . ' ' . $index] = function () use ( $command, $result ) {
			[ $guest, $db ] = transfer_frozen(); $before = count( $GLOBALS['storage_deletions'] ); $db->faults[$command] = [ 'result' => $result ];
			storage_unavailable( fn() => $guest->stage_checkout_transfer( 17, 'wp_options' ) );
			storage_expect( ! isset( $db->rows['17'] ) && [] === $db->ledger && null === $db->pending );
			storage_expect( count( $GLOBALS['storage_deletions'] ) === $before && ! in_array( 'rollback', $db->commands, true ) );
			storage_expect( [ $db->grants[1]['handle'] ] === $db->abort_handles );
		};
	}
}
foreach ( [ 'snapshot-read', 'engine-read', 'ledger-read', 'destination-insert', 'ledger-insert' ] as $operation ) {
	$cases['query failure captures dual abort ' . $operation] = function () use ( $operation ) {
		[ $guest, $db ] = transfer_frozen(); $db->faults[$operation] = [ 'throw' => true ];
		storage_unavailable( fn() => $guest->stage_checkout_transfer( 17, 'wp_options' ) );
		storage_expect( [ $db->grants[1]['handle'] ] === $db->abort_handles && ! in_array( 'rollback', $db->commands, true ) );
	};
}
foreach ( [ 'false', 'throw', 'post-probe', 'replacement' ] as $failure ) {
	$cases['commit uncertainty remains premarked and cannot retry ' . $failure] = function () use ( $failure ) {
		[ $guest, $db ] = transfer_frozen(); $account = $guest->stage_checkout_transfer( 17, 'wp_options' );
		$fault = match ( $failure ) { 'false' => [ 'result' => false ], 'throw' => [ 'throw' => true ],
			'post-probe' => [ 'post_fail' => true ], 'replacement' => [ 'replace_global' => true ] };
		$db->faults['commit'] = $fault + [ 'effect_before_fault' => true ];
		$db->on_command = function ( $label ) use ( $account ) {
			if ( 'commit' === $label ) { storage_expect( $account->checkout_transfer_outcome()['commit_attempted'] && ! $account->checkout_transfer_outcome()['commit_acknowledged'] ); }
		};
		storage_unavailable( fn() => $account->commit_checkout_transfer() );
		storage_expect( $account->checkout_transfer_outcome() === [ 'attempted' => true, 'commit_attempted' => true, 'commit_acknowledged' => false, 'rollback_acknowledged' => false ] );
		storage_expect( isset( $db->rows['17'] ) && 1 === count( $db->ledger ) && ! in_array( 'rollback', $db->commands, true ) );
		storage_unavailable( fn() => $account->commit_checkout_transfer() );
		storage_expect( 1 === count( array_filter( $db->commands, fn( $label ) => 'commit' === $label ) ) );
	};
}
$cases['postcommit eviction failure retains durable retirement and never compensates'] = function () {
	[ $guest, $db, $id ] = transfer_frozen(); $account = $guest->stage_checkout_transfer( 17, 'wp_options' );
	$GLOBALS['storage_cache'][WC_SESSION_CACHE_GROUP][storage_session_key( '17' )] = 'synthetic-stale'; $GLOBALS['storage_cache_sticky'] = true;
	storage_unavailable( fn() => $account->commit_checkout_transfer() );
	storage_expect( $account->checkout_transfer_outcome()['commit_acknowledged'] && isset( $db->rows[$id], $db->rows['17'] ) && 1 === count( $db->ledger ) );
	storage_expect( ! in_array( 'rollback', $db->commands, true ) && [] === $GLOBALS['storage_publications'] );
	$guest->abort(); storage_expect( 1 === count( $db->abort_handles ) );
};
$cases['healthy precommit rollback has checked receipt and permits clean release only'] = function () {
	[ $guest, $db ] = transfer_frozen(); $account = $guest->stage_checkout_transfer( 17, 'wp_options' ); $before = count( $GLOBALS['storage_deletions'] );
	$account->rollback_checkout_transfer(); storage_expect( $account->checkout_transfer_outcome()['rollback_acknowledged'] );
	storage_expect( ! $account->checkout_transfer_outcome()['commit_attempted'] && ! isset( $db->rows['17'] ) && [] === $db->ledger );
	storage_expect( count( $GLOBALS['storage_deletions'] ) === $before ); $account->seal(); $account->release(); $account->assert_response_available();
	$guest->abort(); storage_expect( [] === $db->abort_handles );
};
$cases['rolled back facade cannot expose destination cart'] = function () {
	[ $guest, $db ] = transfer_frozen(); $account = $guest->stage_checkout_transfer( 17, 'wp_options' ); $account->rollback_checkout_transfer();
	storage_unavailable( fn() => $account->read() ); storage_expect( ! isset( $db->rows['17'] ) );
};
foreach ( [ [ 'result' => false ], [ 'result' => true ], [ 'post_fail' => true ], [ 'throw' => true ] ] as $index => $fault ) {
	$cases['rollback failure has no confirmed rollback receipt ' . $index] = function () use ( $fault ) {
		[ $guest, $db ] = transfer_frozen(); $account = $guest->stage_checkout_transfer( 17, 'wp_options' ); $db->faults['rollback'] = $fault;
		storage_unavailable( fn() => $account->rollback_checkout_transfer() ); storage_expect( ! $account->checkout_transfer_outcome()['rollback_acknowledged'] );
		storage_expect( [ $db->grants[1]['handle'] ] === $db->abort_handles );
	};
}
$cases['retirement read remains under guest lock after source physical deletion'] = function () {
	[ $guest, $db, $id, $uuid ] = transfer_frozen(); $account = $guest->transfer_to_fresh_account( 17, 'wp_options' ); $account->seal(); $account->release();
	unset( $db->rows[$id] ); $fresh = new Cart_Session_Storage( $id, 'wp_woocommerce_sessions' ); $fresh->acquire();
	storage_expect( true === $fresh->read_retirement_marker( 'wp_options' ) && false === $fresh->read() );
	storage_expect( $db->ledger['wl_cart_retired_v1_' . transfer_hash( $id )]['value'] === transfer_marker( $id, $uuid ) );
};
$cases['retirement absence does not use options or session cache APIs'] = function () {
	[ $guest, $db ] = transfer_fixture(); $before = count( $GLOBALS['storage_deletions'] );
	storage_expect( false === $guest->read_retirement_marker( 'wp_options' ) && [] === $db->commands );
	storage_expect( count( $GLOBALS['storage_deletions'] ) === $before && [] === $GLOBALS['storage_publications'] );
};
foreach ( [ 'json', 'extra', 'source', 'uuid', 'schema', 'whitespace', 'order' ] as $failure ) {
	$cases['malformed immutable retirement marker fails closed ' . $failure] = function () use ( $failure ) {
		[ $guest, $db, $id ] = transfer_fixture();
		$value = transfer_marker( $id, '00000000-0000-4000-8000-000000000000' ); $marker = json_decode( $value, true );
		switch ( $failure ) {
			case 'json': $value = '{'; break;
			case 'extra': $marker['raw_identity'] = 'synthetic'; break;
			case 'source': $marker['source_tuple_sha256'] = str_repeat( '0', 64 ); break;
			case 'uuid': $marker['operation_uuid'] = 'caller-id'; break;
			case 'schema': $marker['schema'] = '1'; break;
			case 'whitespace': $value .= ' '; break;
			case 'order': $marker = array_reverse( $marker, true ); break;
		}
		if ( ! in_array( $failure, [ 'json', 'whitespace' ], true ) ) { $value = json_encode( $marker ); }
		$db->ledger['wl_cart_retired_v1_' . transfer_hash( $id )] = [ 'value' => $value, 'autoload' => 'no' ];
		storage_unavailable( fn() => $guest->read_retirement_marker( 'wp_options' ) ); storage_expect( [] === $db->commands );
	};
}
$cases['replacement after freeze aborts only captured original guest grant'] = function () {
	[ $guest, $db ] = transfer_frozen(); $replacement = new Transfer_Contract_Database(); $GLOBALS['wpdb'] = $replacement;
	storage_unavailable( fn() => $guest->stage_checkout_transfer( 17, 'wp_options' ) );
	storage_expect( [ $db->grants[0]['handle'] ] === $db->abort_handles && [] === $replacement->grants && [] === $replacement->commands );
};
$cases['freeze mismatch never reaches clean handoff'] = function () {
	[ $guest, $db ] = transfer_fixture(); $db->faults['snapshot-read'] = [ 'result' => null ];
	storage_unavailable( fn() => $guest->freeze_for_checkout_transfer( [], 123 ) );
	storage_unavailable( fn() => $guest->stage_checkout_transfer( 17, 'wp_options' ) ); storage_expect( 1 === count( $db->grants ) );
};
foreach ( [ 'guest-persist', 'snapshot-read' ] as $operation ) {
	$cases['initial freeze query failure aborts exact guest handle ' . $operation] = function () use ( $operation ) {
		[ $guest, $db ] = transfer_fixture(); $db->faults[$operation] = [ 'throw' => true ];
		storage_unavailable( fn() => $guest->freeze_for_checkout_transfer( [], 123 ) );
		storage_expect( [ $db->grants[0]['handle'] ] === $db->abort_handles && 1 === count( $db->grants ) );
	};
}
foreach ( [ 'seal', 'release', 'dual-begin' ] as $operation ) {
	$cases['failed healthy handoff cannot retry ' . $operation] = function () use ( $operation ) {
		[ $guest, $db ] = transfer_frozen(); $db->faults[$operation] = [];
		storage_unavailable( fn() => $guest->stage_checkout_transfer( 17, 'wp_options' ) );
		storage_unavailable( fn() => $guest->stage_checkout_transfer( 17, 'wp_options' ) );
		storage_expect( 1 === count( $db->grants ) && [ 'guest-persist' ] === $db->commands );
		storage_expect( 'dual-begin' === $operation ? [] === $db->abort_handles : [ $db->grants[0]['handle'] ] === $db->abort_handles );
	};
}
$cases['checked SQL last error cannot masquerade as acknowledged commit'] = function () {
	[ $guest, $db ] = transfer_frozen(); $account = $guest->stage_checkout_transfer( 17, 'wp_options' );
	$db->faults['commit'] = [ 'sql_error' => true, 'effect_before_fault' => true ];
	storage_unavailable( fn() => $account->commit_checkout_transfer() );
	storage_expect( $account->checkout_transfer_outcome()['commit_attempted'] && ! $account->checkout_transfer_outcome()['commit_acknowledged'] );
	storage_expect( isset( $db->rows['17'] ) && 1 === count( $db->ledger ) && ! in_array( 'rollback', $db->commands, true ) );
};
$cases['retirement primitive requires actual acquired guest ownership'] = function () {
	[ $guest, $db ] = transfer_fixture(); $guest->seal(); $guest->release();
	$unacquired = new Cart_Session_Storage( str_repeat( 'b', 32 ), 'wp_woocommerce_sessions' );
	storage_unavailable( fn() => $unacquired->read_retirement_marker( 'wp_options' ) );
	storage_expect( [] === $db->commands && [] === $db->ledger );
};

$failures = 0;
foreach ( $cases as $name => $case ) {
	try { $case(); echo 'PASS ' . $name . "\n"; }
	catch ( Throwable $error ) { $failures++; echo 'FAIL ' . $name . "\n"; }
}
echo count( $cases ) . ' cases; ' . $failures . " failures.\n";
exit( $failures ? 1 : 0 );

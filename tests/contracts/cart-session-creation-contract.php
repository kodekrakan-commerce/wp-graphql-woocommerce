<?php
/**
 * Dormant creation reservation: actual storage/interface/errors/native WC cache helper.
 * SQL, transactions, ownership and caches are controlled recording boundaries.
 * No native customer/auth/order, WordPress bootstrap, database or runtime proof.
 */
error_reporting( E_ALL ); ini_set( 'display_errors', '0' ); ini_set( 'log_errors', '0' );
$mu = rtrim( getenv( 'WL_MU_PLUGINS_SOURCE' ) ?: '', '/' );
$wc = rtrim( getenv( 'WL_WOOCOMMERCE_SOURCE' ) ?: '', '/' );
$gql = rtrim( getenv( 'WL_WPGRAPHQL_SOURCE' ) ?: '', '/' );
$required = [ $mu . '/database/interface-owned-scope-driver.php', $wc . '/src/Caching/CacheNameSpaceTrait.php',
	$wc . '/includes/class-wc-cache-helper.php', $gql . '/vendor/autoload.php' ];
foreach ( $required as $file ) { if ( ! is_file( $file ) ) { fwrite( STDERR, "Required genuine component unavailable; configure source environment inputs.\n" ); exit( 2 ); } }
define( 'ABSPATH', __DIR__ . '/' ); define( 'DB_NAME', 'offline_creation_contract' ); define( 'WC_SESSION_CACHE_GROUP', 'woocommerce_sessions' );
require $required[0]; require $required[3];
require __DIR__ . '/cart-session-storage-fixtures.php'; require $required[1]; require $required[2];
require dirname( __DIR__, 2 ) . '/includes/utils/class-cart-session-error.php';
require dirname( __DIR__, 2 ) . '/includes/utils/class-cart-session-storage.php';
require __DIR__ . '/cart-session-transfer-fixtures.php'; require __DIR__ . '/cart-session-creation-fixtures.php';
set_error_handler( static function ( $severity, $message, $file, $line ) {
	throw new \ErrorException( 'Creation contract warning.', 0, $severity, $file, $line );
} );

use WPGraphQL\WooCommerce\Utils\Cart_Session_Storage;

$cases = [];
$cases['unattempted marker read is direct and sanitized'] = function () {
	[ $guest, $db ] = creation_fixture();
	storage_expect( [ 'attempted'=>false, 'commit_attempted'=>false, 'commit_acknowledged'=>false ] === $guest->checkout_creation_outcome() );
	storage_expect( false === $guest->read_creation_marker( 'wp_options' ) && [] === $db->commands && [] === $GLOBALS['storage_publications'] );
};
$cases['marker-only commit acknowledged before freeze without session or cache publication'] = function () {
	[ $guest, $db, $id ] = creation_fixture(); $deletions = $GLOBALS['storage_deletions']; $observed = [];
	$db->on_creation_command = function ( $label ) use ( $guest, &$observed ) { $observed[$label] = $guest->checkout_creation_outcome(); };
	storage_expect( true === $guest->reserve_checkout_creation( 'wp_options' ) );
	storage_expect( [ 'attempted'=>true, 'commit_attempted'=>false, 'commit_acknowledged'=>false ] === $observed['creation-insert']
		&& [ 'attempted'=>true, 'commit_attempted'=>true, 'commit_acknowledged'=>false ] === $observed['creation-commit']
		&& [ 'attempted'=>true, 'commit_attempted'=>true, 'commit_acknowledged'=>true ] === $guest->checkout_creation_outcome() );
	$marker = $db->ledger[creation_key( $id )]; $decoded = json_decode( $marker['value'], true, 8, JSON_THROW_ON_ERROR );
	storage_expect( array_keys( $decoded ) === [ 'schema', 'kind', 'source_tuple_sha256', 'operation_uuid' ]
		&& $marker === [ 'value'=>creation_marker( $id, $decoded['operation_uuid'] ), 'autoload'=>'no' ]
		&& 1 === preg_match( '/\A[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/D', $decoded['operation_uuid'] ) );
	storage_expect( [ 'start', 'ledger-insert', 'commit' ] === $db->commands && [] === $db->rows && null === $db->pending
		&& $deletions === $GLOBALS['storage_deletions'] && [] === $GLOBALS['storage_publications'] );
	storage_expect( $db->queries[1] === [ 'INSERT INTO %i (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, %s)',
		[ 'wp_options', creation_key( $id ), $marker['value'], 'no' ] ] );
};
$cases['freeze and retirement reuse internally acknowledged creation UUID'] = function () {
	[ $guest, $db, $id, $uuid, $marker ] = creation_reserved();
	storage_expect( $uuid === $guest->freeze_for_checkout_transfer( [ 'cart'=>[ 'quantity'=>3 ] ], 123456789 ) );
	$account = $guest->transfer_to_fresh_account( 17, 'wp_options' );
	storage_expect( $db->ledger[creation_key( $id )] === $marker
		&& $db->ledger['wl_cart_retired_v1_' . transfer_hash( $id )]['value'] === transfer_marker( $id, $uuid )
		&& $account->checkout_creation_outcome() === $guest->checkout_creation_outcome() );
	storage_expect( $db->rows[$id] === $db->rows['17'] && [ 'cart'=>[ 'quantity'=>3 ] ] === $account->read() );
	$account->seal(); $account->release(); $account->assert_response_available();
};
$cases['healthy transfer rollback retains creation marker and burns next request'] = function () {
	[ $guest, $db, $id, , $marker ] = creation_reserved(); $guest->freeze_for_checkout_transfer( [], 123456789 );
	$account = $guest->stage_checkout_transfer( 17, 'wp_options' ); $account->rollback_checkout_transfer();
	storage_expect( [ creation_key( $id )=>$marker ] === $db->ledger && ! isset( $db->rows['17'] ) && isset( $db->rows[$id] ) );
	$account->seal(); $account->release();
	$fresh = new Cart_Session_Storage( $id, 'wp_woocommerce_sessions' ); $fresh->acquire();
	storage_expect( true === $fresh->read_creation_marker( 'wp_options' ) ); $before = $db->commands;
	storage_unavailable( fn() => $fresh->reserve_checkout_creation( 'wp_options' ) );
	storage_expect( $before === $db->commands && [ creation_key( $id )=>$marker ] === $db->ledger );
};
$cases['source expiry and deletion do not remove creation marker'] = function () {
	[ $guest, $db, $id, , $marker ] = creation_reserved(); $guest->seal(); $guest->release();
	$db->rows[$id] = [ 'bytes'=>serialize( [] ), 'expiry'=>1 ];
	$fresh = new Cart_Session_Storage( $id, 'wp_woocommerce_sessions' ); $fresh->acquire();
	storage_expect( true === $fresh->read_creation_marker( 'wp_options' ) ); $fresh->seal(); $fresh->release(); unset( $db->rows[$id] );
	$fresh = new Cart_Session_Storage( $id, 'wp_woocommerce_sessions' ); $fresh->acquire();
	storage_expect( true === $fresh->read_creation_marker( 'wp_options' ) && $marker === $db->ledger[creation_key( $id )] );
};
$cases['duplicate reservation cannot issue another transaction or clear acknowledged marker'] = function () {
	[ $guest, $db, $id, , $marker ] = creation_reserved(); $before = $db->commands;
	storage_unavailable( fn() => $guest->reserve_checkout_creation( 'wp_options' ) );
	storage_unavailable( fn() => $guest->freeze_for_checkout_transfer( [], 123 ) );
	storage_expect( $before === $db->commands && [ creation_key( $id )=>$marker ] === $db->ledger && 1 === count( $db->grants ) );
};
$cases['account facade cannot reserve a guest creation'] = function () {
	[ $account, $db ] = creation_fixture( true ); storage_unavailable( fn() => $account->reserve_checkout_creation( 'wp_options' ) );
	storage_expect( [] === $db->commands && [] === $db->ledger );
};
foreach ( [ 'creation', 'retirement' ] as $kind ) {
	$cases['existing ' . $kind . ' marker refuses before START'] = function () use ( $kind ) {
		[ $guest, $db, $id ] = creation_fixture(); $uuid = '11111111-1111-4111-8111-111111111111';
		$key = 'creation' === $kind ? creation_key( $id ) : 'wl_cart_retired_v1_' . transfer_hash( $id );
		$value = 'creation' === $kind ? creation_marker( $id, $uuid ) : transfer_marker( $id, $uuid );
		$db->ledger[$key] = [ 'value'=>$value, 'autoload'=>'no' ];
		storage_unavailable( fn() => $guest->reserve_checkout_creation( 'wp_options' ) );
		storage_expect( [] === $db->commands && [ $key=>[ 'value'=>$value, 'autoload'=>'no' ] ] === $db->ledger );
	};
}
$canonical = creation_marker( str_repeat( 'a', 32 ), '11111111-1111-4111-8111-111111111111' );
$malformed = [ 'nonstrings'=>1, 'empty'=>'', 'primitive'=>'true', 'bad-json'=>'{', 'trailing-space'=>$canonical . ' ',
	'wrong-kind'=>str_replace( 'checkout_creation_attempt', 'checkout_guest_retirement', $canonical ),
	'wrong-source'=>str_replace( transfer_hash( str_repeat( 'a', 32 ) ), str_repeat( '0', 64 ), $canonical ),
	'bad-uuid'=>str_replace( '4111', '3111', $canonical ), 'wrong-schema'=>str_replace( '"schema":1', '"schema":"1"', $canonical ),
	'extra'=>substr( $canonical, 0, -1 ) . ',"extra":true}', 'wrong-key-order'=>json_encode( array_reverse( json_decode( $canonical, true ), true ) ) ];
foreach ( $malformed as $name=>$value ) {
	$cases['direct creation read rejects ' . $name] = function () use ( $value ) {
		[ $guest, $db, $id ] = creation_fixture(); $db->ledger[creation_key( $id )] = [ 'value'=>$value, 'autoload'=>'no' ];
		storage_unavailable( fn() => $guest->read_creation_marker( 'wp_options' ) );
		storage_expect( [] === $db->commands && [ $db->grants[0]['handle'] ] === $db->abort_handles );
	};
}
foreach ( [ 'wrong-options', 'changed-database', 'nontransactional-options', 'missing-engine-row' ] as $fault ) {
	$cases['reservation qualification rejects ' . $fault] = function () use ( $fault ) {
		[ $guest, $db ] = creation_fixture();
		if ( 'changed-database' === $fault ) { $db->database = 'different_synthetic_database'; }
		if ( 'nontransactional-options' === $fault ) { $db->inner->engines[1]->ENGINE = 'MyISAM'; }
		if ( 'missing-engine-row' === $fault ) { $db->engines = [ $db->inner->engines[0] ]; }
		storage_unavailable( fn() => $guest->reserve_checkout_creation( 'wrong-options' === $fault ? 'another_options' : 'wp_options' ) );
		storage_expect( [] === $db->commands && [] === $db->ledger );
	};
}
$cases['preexisting transaction START refusal never commits prior work'] = function () {
	[ $guest, $db ] = creation_fixture(); $db->nested_transaction = true;
	$db->pending = [ 'rows'=>[ 'prior'=>[ 'bytes'=>serialize( [] ), 'expiry'=>123 ] ], 'ledger'=>[] ];
	storage_unavailable( fn() => $guest->reserve_checkout_creation( 'wp_options' ) );
	storage_expect( [] === $db->commands && [] === $db->ledger && [] === $db->rows
		&& false === $guest->checkout_creation_outcome()['commit_attempted'] && [ $db->grants[0]['handle'] ] === $db->abort_handles );
};
foreach ( [ 'creation-start'=>[ false, 1, '0' ], 'creation-insert'=>[ false, 0, 2, '1' ], 'creation-commit'=>[ false, 1, '0' ] ] as $label=>$values ) {
	foreach ( $values as $index=>$value ) {
		$cases[$label . ' rejects strict result ' . $index] = function () use ( $label, $value ) {
			[ $guest, $db ] = creation_fixture(); $db->creation_faults[$label] = [ 'result'=>$value ];
			storage_unavailable( fn() => $guest->reserve_checkout_creation( 'wp_options' ) );
			$outcome = $guest->checkout_creation_outcome();
			storage_expect( true === $outcome['attempted'] && false === $outcome['commit_acknowledged']
				&& ( 'creation-commit' === $label ) === $outcome['commit_attempted'] && [] === $db->ledger );
			$commands = $db->commands; storage_unavailable( fn() => $guest->reserve_checkout_creation( 'wp_options' ) );
			storage_expect( $commands === $db->commands && 1 === count( $db->grants ) );
		};
	}
}
foreach ( [ 'creation-read', 'creation-start', 'creation-insert', 'creation-commit' ] as $label ) {
	foreach ( [ 'sql_error', 'post_fail', 'throw', 'replace_global' ] as $fault ) {
		$cases[$label . ' captured failure ' . $fault] = function () use ( $label, $fault ) {
			[ $guest, $db ] = creation_fixture(); $db->creation_faults[$label] = [ $fault=>true ];
			storage_unavailable( fn() => $guest->reserve_checkout_creation( 'wp_options' ) );
			storage_expect( false === $guest->checkout_creation_outcome()['commit_acknowledged']
				&& [ $db->grants[0]['handle'] ] === $db->abort_handles && [] === $db->ledger );
		};
	}
}
foreach ( [ 'throw', 'post_fail', 'replace_global' ] as $fault ) {
	$cases['reservation committed but reply uncertain ' . $fault] = function () use ( $fault ) {
		[ $guest, $db, $id ] = creation_fixture(); $db->creation_faults['creation-commit'] = [ $fault=>true, 'effect_before_fault'=>true ];
		storage_unavailable( fn() => $guest->reserve_checkout_creation( 'wp_options' ) );
		storage_expect( [ 'attempted'=>true, 'commit_attempted'=>true, 'commit_acknowledged'=>false ] === $guest->checkout_creation_outcome()
			&& isset( $db->ledger[creation_key( $id )] ) && [] === $db->rows );
		$before = $db->commands; storage_unavailable( fn() => $guest->freeze_for_checkout_transfer( [], 123 ) );
		storage_expect( $before === $db->commands && 1 === count( $db->grants ) );
	};
}
foreach ( [ 'creation-start', 'creation-insert', 'creation-commit' ] as $target ) {
	$cases['reservation reentry stopped at ' . $target] = function () use ( $target ) {
		[ $guest, $db ] = creation_fixture(); $entered = 0;
		$db->on_creation_command = function ( $label ) use ( $target, $guest, &$entered ) {
			if ( $label !== $target ) { return; } $entered++; storage_unavailable( fn() => $guest->reserve_checkout_creation( 'wp_options' ) );
		};
		storage_unavailable( fn() => $guest->reserve_checkout_creation( 'wp_options' ) );
		storage_expect( 1 === $entered && false === $guest->checkout_creation_outcome()['commit_acknowledged'] && [] === $db->ledger && 1 === count( $db->grants ) );
	};
}
foreach ( [ 'read', 'write', 'delete', 'timestamp', 'hydrate' ] as $operation ) {
	$cases['marker-only reservation blocks reentrant data ' . $operation] = function () use ( $operation ) {
		[ $guest, $db ] = creation_fixture(); $before = $GLOBALS['storage_deletions']; $entered = 0;
		$db->on_creation_command = function ( $label ) use ( $guest, $operation, &$entered ) {
			if ( 'creation-insert' !== $label ) { return; } $entered++;
			storage_unavailable( fn() => match ( $operation ) { 'read'=>$guest->read(), 'write'=>$guest->write( [], 123 ),
				'delete'=>$guest->delete(), 'timestamp'=>$guest->update_timestamp( 123 ), 'hydrate'=>$guest->invalidate_account_caches() } );
		};
		storage_unavailable( fn() => $guest->reserve_checkout_creation( 'wp_options' ) );
		storage_expect( 1 === $entered && [] === $db->ledger && [] === $db->rows
			&& $before === $GLOBALS['storage_deletions'] && [] === $GLOBALS['storage_publications'] );
	};
}
foreach ( [ 'missing', 'changed-uuid', 'malformed' ] as $change ) {
	$cases['freeze checks reservation before persisting source ' . $change] = function () use ( $change ) {
		[ $guest, $db, $id, , $marker ] = creation_reserved();
		if ( 'missing' === $change ) { unset( $db->inner->ledger[creation_key( $id )] ); }
		else { $db->inner->ledger[creation_key( $id )]['value'] = 'malformed' === $change ? '{' : creation_marker( $id, '22222222-2222-4222-8222-222222222222' ); }
		$before = $db->commands; storage_unavailable( fn() => $guest->freeze_for_checkout_transfer( [], 123 ) );
		storage_expect( $before === $db->commands && [] === $db->rows && 1 === count( $db->grants ) );
	};
	$cases['dual grant rejects reservation ' . $change . ' before transfer transaction'] = function () use ( $change ) {
		[ $guest, $db, $id ] = creation_reserved(); $guest->freeze_for_checkout_transfer( [], 123 );
		$db->on_dual = function ( $inner ) use ( $id, $change ) {
			if ( 'missing' === $change ) { unset( $inner->ledger[creation_key( $id )] ); }
			else { $inner->ledger[creation_key( $id )]['value'] = 'malformed' === $change ? '{' : creation_marker( $id, '22222222-2222-4222-8222-222222222222' ); }
		};
		$before = $db->commands; storage_unavailable( fn() => $guest->stage_checkout_transfer( 17, 'wp_options' ) );
		storage_expect( $before === $db->commands && ! isset( $db->rows['17'] ) && [ $db->grants[1]['handle'] ] === $db->abort_handles );
		$guest->abort(); storage_expect( [ $db->grants[1]['handle'] ] === $db->abort_handles );
	};
}
$cases['unreserved facade cannot adopt another attempt marker'] = function () {
	[ $guest, $db, $id ] = transfer_frozen(); $marker = [ 'value'=>creation_marker( $id, '11111111-1111-4111-8111-111111111111' ), 'autoload'=>'no' ];
	$db->ledger[creation_key( $id )] = $marker; $before = $db->commands;
	storage_unavailable( fn() => $guest->stage_checkout_transfer( 17, 'wp_options' ) );
	storage_expect( $before === $db->commands && $marker === $db->ledger[creation_key( $id )] );
};
foreach ( [ 'read', 'write', 'creation-read', 'reserve' ] as $operation ) {
	$cases['reserved frozen facade blocks ordinary ' . $operation] = function () use ( $operation ) {
		[ $guest, $db ] = creation_reserved(); $guest->freeze_for_checkout_transfer( [], 123 ); $before = $db->commands;
		storage_unavailable( fn() => match ( $operation ) { 'read'=>$guest->read(), 'write'=>$guest->write( [], 123 ),
			'creation-read'=>$guest->read_creation_marker( 'wp_options' ), 'reserve'=>$guest->reserve_checkout_creation( 'wp_options' ) } );
		storage_expect( $before === $db->commands && 1 === count( $db->grants ) );
	};
}
$cases['old reserved facade cannot abort destination grant or reserve again'] = function () {
	[ $guest, $db ] = creation_reserved(); $guest->freeze_for_checkout_transfer( [], 123 ); $account = $guest->stage_checkout_transfer( 17, 'wp_options' );
	$guest->abort(); storage_unavailable( fn() => $guest->reserve_checkout_creation( 'wp_options' ) ); $account->assert_owned();
	storage_expect( [] === $db->abort_handles && 2 === count( $db->grants ) ); $account->rollback_checkout_transfer();
};
$cases['unknown transfer commit preserves creation reservation and durable destination ledger'] = function () {
	[ $guest, $db, $id, , $marker ] = creation_reserved(); $guest->freeze_for_checkout_transfer( [], 123 );
	$account = $guest->stage_checkout_transfer( 17, 'wp_options' ); $db->inner->faults['commit'] = [ 'throw'=>true, 'effect_before_fault'=>true ];
	storage_unavailable( fn() => $account->commit_checkout_transfer() );
	storage_expect( $marker === $db->ledger[creation_key( $id )] && isset( $db->rows['17'], $db->ledger['wl_cart_retired_v1_' . transfer_hash( $id )] )
		&& [ 'attempted'=>true, 'commit_attempted'=>true, 'commit_acknowledged'=>true ] === $account->checkout_creation_outcome() );
};

$failed = [];
foreach ( $cases as $name=>$test ) { try { $test(); } catch ( \Throwable $error ) { $failed[] = $name; } }
restore_error_handler();
echo json_encode( [ 'suite'=>'cart-session-creation', 'cases'=>count( $cases ), 'passed'=>count( $cases )-count( $failed ), 'failed'=>$failed,
	'php'=>PHP_VERSION, 'storage_sha256'=>hash_file( 'sha256', dirname( __DIR__, 2 ) . '/includes/utils/class-cart-session-storage.php' ),
	'limits'=>'Actual storage/interface/errors/native WC cache helper; controlled driver/SQL/transactions/cache. No native creation/auth/order/database or runtime proof.' ], JSON_THROW_ON_ERROR ) . "\n";
exit( [] === $failed ? 0 : 1 );

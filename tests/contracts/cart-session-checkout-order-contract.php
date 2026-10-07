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
function order_fixture() {
 [ $guest, $db, $source, $uuid ] = creation_reserved();
 $guest->freeze_for_checkout_transfer( [ 'cart'=>[ 'item'=>[ 'quantity'=>1 ] ] ], 123456789 );
 $account = $guest->transfer_to_fresh_account( 17, 'wp_options' );
 return [ $account, $db, $source, $uuid ];
}
function order_key() { return 'wl_checkout_order_v1_' . transfer_hash( '17' ); }
function order_marker( $uuid, $state = 'pending', $id = 0 ) {
 return json_encode( [ 'schema'=>1, 'kind'=>'checkout_order_attempt', 'destination_tuple_sha256'=>transfer_hash( '17' ),
  'operation_uuid'=>$uuid, 'state'=>$state, 'order_id'=>$id ], JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES );
}
$cases['transfer atomically writes pending zero fence with original creation UUID'] = function () {
 [ $account, $db, $source, $uuid ] = order_fixture();
 storage_expect( $db->ledger[order_key()] === [ 'value'=>order_marker( $uuid ), 'autoload'=>'no' ] );
 storage_expect( array_slice( $db->commands, -5 ) === [ 'start', 'destination-insert', 'ledger-insert', 'ledger-insert', 'commit' ] );
 storage_expect( $account->read_checkout_order_attempt( 'wp_options' )['order_id'] === 0 );
};
$cases['bare T1 transfer keeps its exact prior contract and no order fence'] = function () {
 [ $guest, $db ] = transfer_frozen(); $account = $guest->transfer_to_fresh_account( 17, 'wp_options' );
 storage_expect( false === $account->read_checkout_order_attempt( 'wp_options' ) );
 storage_unavailable( fn()=>$account->bind_checkout_order( 91 ) );
};
$cases['native order bind acknowledges exact CAS then final session and complete CAS share one commit'] = function () {
 [ $account, $db, , $uuid ] = order_fixture(); $account->bind_checkout_order( 91 );
 storage_expect( $db->ledger[order_key()]['value'] === order_marker( $uuid, 'pending', 91 ) );
 storage_expect( array_slice( $db->commands, -3 ) === [ 'start', 'order-cas', 'commit' ] );
 $account->complete_checkout_order( [ 'customer'=>[ 'id'=>17 ] ], 123456790 );
 storage_expect( $db->ledger[order_key()]['value'] === order_marker( $uuid, 'complete', 91 )
  && $db->rows['17'] === [ 'bytes'=>serialize( [ 'customer'=>[ 'id'=>17 ] ] ), 'expiry'=>123456790 ] );
 storage_expect( array_slice( $db->commands, -4 ) === [ 'start', 'final-session', 'order-cas', 'commit' ] );
 $account->seal(); $account->release(); $account->assert_response_available();
 $fresh = new Cart_Session_Storage( '17', 'wp_woocommerce_sessions', 17 ); $fresh->acquire();
 storage_expect( $fresh->read_checkout_order_attempt( 'wp_options' )['state'] === 'complete' );
 storage_unavailable( fn()=>$fresh->bind_checkout_order( 92 ) );
};
foreach ( [ 'pending', 'complete' ] as $state ) {
 $cases['fresh authenticated facade can observe '.$state.' but never acquire old attempt'] = function () use ($state) {
  [ $account, $db, , $uuid ]=order_fixture(); if ('complete'===$state) { $account->bind_checkout_order(91); $account->complete_checkout_order([],123456790); }
  $account->seal(); $account->release();
  $fresh=new Cart_Session_Storage('17','wp_woocommerce_sessions',17); $fresh->acquire();
  storage_expect($state===$fresh->read_checkout_order_attempt('wp_options')['state']);
  $before=$db->commands; storage_unavailable(fn()=>$fresh->complete_checkout_order([],123456791)); storage_expect($before===$db->commands);
 };
}
foreach ([0,-1] as $id) { $cases['invalid order ID '.$id.' never writes'] = function()use($id){[$a,$db]=order_fixture();$before=$db->commands;storage_unavailable(fn()=>$a->bind_checkout_order($id));storage_expect($before===$db->commands);}; }
foreach ([['cart'=>['item'=>1]], ['order_awaiting_payment'=>91], ['reload_checkout'=>true]] as $i=>$data) {
 $cases['nonfinal session '.$i.' cannot complete fence']=function()use($data){[$a,$db]=order_fixture();$a->bind_checkout_order(91);$before=$db->commands;storage_unavailable(fn()=>$a->complete_checkout_order($data,123456790));storage_expect($before===$db->commands);};
}
$cases['assigned zero fence cannot complete without positively bound order']=function(){[$a,$db]=order_fixture();$before=$db->commands;storage_unavailable(fn()=>$a->complete_checkout_order([],123456790));storage_expect($before===$db->commands);};
foreach (['order-cas','final-session','commit'] as $phase) {
 foreach (['false','zero','string','throw','post_fail','replace_global','sql_error'] as $fault) {
  $cases['final atomic failure '.$phase.' '.$fault]=function()use($phase,$fault){
   [$a,$db,,$uuid]=order_fixture();$a->bind_checkout_order(91);$before=$db->rows['17'];
   $db->inner->faults[$phase]=match($fault){'false'=>['result'=>false],'zero'=>['result'=>0],'string'=>['result'=>'1'],default=>[$fault=>true]};
   // COMMIT must return literal 0; a mocked 0 is acknowledged only when it applies.
   if('commit'===$phase&&'zero'===$fault){$db->inner->faults[$phase]=['result'=>1];}
   storage_unavailable(fn()=>$a->complete_checkout_order([],123456790));
   storage_expect($db->rows['17']===$before&&$db->ledger[order_key()]['value']===order_marker($uuid,'pending',91));
   $commands=$db->commands;storage_unavailable(fn()=>$a->complete_checkout_order([],123456790));storage_expect($commands===$db->commands);
  };
 }
}
foreach(['throw','post_fail','replace_global'] as $fault) {
 $cases['lost final COMMIT reply '.$fault.' preserves complete durable outcome and forbids replay']=function()use($fault){
  [$a,$db,,$uuid]=order_fixture();$a->bind_checkout_order(91);$db->inner->faults['commit']=[$fault=>true,'effect_before_fault'=>true];
  storage_unavailable(fn()=>$a->complete_checkout_order([],123456790));
  storage_expect($db->ledger[order_key()]['value']===order_marker($uuid,'complete',91)&&$db->rows['17']['bytes']===serialize([]));
  $commands=$db->commands;storage_unavailable(fn()=>$a->complete_checkout_order([],123456790));storage_expect($commands===$db->commands);
 };
}
foreach(['order-read','start','order-cas','commit'] as $target) {
 $cases['reentrant bind is sticky closed at '.$target]=function()use($target){
  [$a,$db]=order_fixture();$entered=0;
  if('order-read'===$target){$db->inner->faults['order-read']=['post_fail'=>true];}
  else{$db->inner->on_command=function($label)use($target,$a,&$entered){if($label===$target){$entered++;storage_unavailable(fn()=>$a->bind_checkout_order(92));}};}
  storage_unavailable(fn()=>$a->bind_checkout_order(91));storage_expect('order-read'===$target||1===$entered);
 };
}
$canonical=order_marker('11111111-1111-4111-8111-111111111111');
foreach(['bad-json'=>'{','whitespace'=>$canonical.' ','schema'=>str_replace('"schema":1','"schema":"1"',$canonical),
 'kind'=>str_replace('checkout_order_attempt','checkout_creation_attempt',$canonical),'negative'=>str_replace('"order_id":0','"order_id":-1',$canonical),
 'complete-zero'=>str_replace('"state":"pending"','"state":"complete"',$canonical),'extra'=>substr($canonical,0,-1).',"extra":true}',
 'wrong-destination'=>str_replace(transfer_hash('17'),str_repeat('a',64),$canonical)] as $name=>$value){
 $cases['canonical reader rejects '.$name]=function()use($value){[$a,$db]=creation_fixture(true);$db->ledger[order_key()]=['value'=>$value,'autoload'=>'no'];storage_unavailable(fn()=>$a->read_checkout_order_attempt('wp_options'));};
}
$cases['autoload substitution fails canonical read']=function(){[$a,$db]=creation_fixture(true);$db->ledger[order_key()]=['value'=>order_marker('11111111-1111-4111-8111-111111111111'),'autoload'=>'yes'];storage_unavailable(fn()=>$a->read_checkout_order_attempt('wp_options'));};
$failed=[];foreach($cases as $name=>$test){try{$test();}catch(\Throwable $e){$failed[]=$name;}}
restore_error_handler(); echo json_encode(['suite'=>'cart-session-checkout-order','cases'=>count($cases),'passed'=>count($cases)-count($failed),'failed'=>$failed,'php'=>PHP_VERSION,
 'limits'=>'Actual storage and owning scope interface; controlled SQL, transactions and cache. No native account, authentication, datastore, database or HTTP acceptance.'],JSON_THROW_ON_ERROR)."\n";
exit([]===$failed?0:1);

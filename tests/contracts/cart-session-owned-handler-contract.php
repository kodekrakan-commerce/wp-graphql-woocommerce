<?php
/**
 * Offline handler/storage integration component contract. Actual retained handler,
 * JWT, storage, operation guard, Woo session classes/cache helper and driver
 * capability are loaded. Lifecycle, auth, hooks, cache and SQL are controlled
 * boundaries. No site, MySQL, driver activation or HTTP acceptance is claimed.
 * Inputs: WL_MU_PLUGINS_SOURCE, WL_WOOCOMMERCE_SOURCE, WL_WPGRAPHQL_SOURCE.
 */
error_reporting( E_ALL ); ini_set( 'display_errors', '0' ); ini_set( 'log_errors', '0' );
$owner = dirname( __DIR__, 2 );
$mu = rtrim( getenv( 'WL_MU_PLUGINS_SOURCE' ) ?: '', '/' );
$wc = rtrim( getenv( 'WL_WOOCOMMERCE_SOURCE' ) ?: '', '/' );
$gql = rtrim( getenv( 'WL_WPGRAPHQL_SOURCE' ) ?: '', '/' );
$files = [ $mu . '/database/interface-owned-scope-driver.php', $wc . '/vendor/autoload.php', $wc . '/includes/abstracts/abstract-wc-session.php', $wc . '/includes/class-wc-session-handler.php', $wc . '/src/Caching/CacheNameSpaceTrait.php', $wc . '/includes/class-wc-cache-helper.php', $gql . '/vendor/autoload.php' ];
foreach ( $files as $file ) { if ( ! is_file( $file ) ) { fwrite( STDERR, "Required genuine source unavailable; configure source environment inputs.\n" ); exit( 2 ); } }
require $files[0]; require __DIR__ . '/cart-session-owned-handler-fixtures.php';
define( 'ABSPATH', __DIR__ . '/' ); define( 'COOKIEHASH', 'synthetic-owned-contract' ); define( 'DB_NAME', 'offline_synthetic_contract' );
define( 'WC_SESSION_CACHE_GROUP', 'woocommerce_sessions' ); define( 'MINUTE_IN_SECONDS', 60 );
define( 'GRAPHQL_WOOCOMMERCE_SECRET_KEY', str_repeat( 'k', 32 ) );
foreach ( array_slice( $files, 1 ) as $file ) { require $file; }
require $owner . '/vendor/autoload.php';
foreach ( [ 'class-cart-session-error.php', 'class-cart-session-operation.php', 'class-cart-session-storage.php', 'class-session-transaction-manager.php', 'class-ql-session-handler.php' ] as $name ) { require $owner . '/includes/utils/' . $name; }

use WPGraphQL\WooCommerce\Utils\QL_Session_Handler;
use WPGraphQL\WooCommerce\Utils\Cart_Session_Error;
use WPGraphQL\WooCommerce\Vendor\Firebase\JWT\JWT;

function owned_expect( $condition ): void { if ( ! $condition ) { throw new RuntimeException( 'Owned handler assertion failed.' ); } }
function owned_error( callable $action, string $code = 'WL_CART_SESSION_UNAVAILABLE' ): void {
	try { $action(); } catch ( \GraphQL\Error\UserError $error ) { owned_expect( $error instanceof \GraphQL\Error\ProvidesExtensions && [ 'code' => $code ] === $error->getExtensions() ); return; }
	throw new RuntimeException( 'Owned handler did not reject an operation.' );
}
function owned_fixture( int $user = 0, $credential = 'valid', bool $graphql = true ): array {
	HandlerContractBoundary::$hooks = []; HandlerContractBoundary::$events = []; HandlerContractBoundary::$rows = []; HandlerContractBoundary::$user = $user; HandlerContractBoundary::$graphql = $graphql; HandlerContractBoundary::$sticky_cache = false;
	HandlerContractBoundary::$cache = [ WC_SESSION_CACHE_GROUP . ':wc_' . WC_SESSION_CACHE_GROUP . '_cache_prefix' => 'fixed-prefix' ];
	$GLOBALS['wpdb'] = $db = new HandlerContractDatabase(); $_SERVER['REQUEST_METHOD'] = 'POST'; unset( $_SERVER['HTTP_WOOCOMMERCE_SESSION'] );
	$id = $user ? (string) $user : str_repeat( 'a', 32 ); HandlerContractBoundary::$rows[$id] = [ 'cart' => 'authoritative-synthetic' ];
	if ( 'valid' === $credential || 'cross' === $credential ) {
		$claim = 'cross' === $credential ? '18' : $id;
		$token = JWT::encode( [ 'iss' => get_bloginfo('url'), 'iat' => time()-2, 'nbf' => time()-2, 'exp' => time()+172800, 'data' => [ 'customer_id' => $claim ] ], GRAPHQL_WOOCOMMERCE_SECRET_KEY, 'HS256' );
		$_SERVER['HTTP_WOOCOMMERCE_SESSION'] = 'Session ' . $token;
	} elseif ( 'malformed' === $credential ) { $_SERVER['HTTP_WOOCOMMERCE_SESSION'] = 'Session malformed'; }
	HandlerContractBoundary::$woocommerce = (object) [ 'session' => null, 'customer' => null, 'cart' => null ];
	$handler = new QL_Session_Handler(); WC()->session = $handler;
	return [ $handler, $db, $id ];
}
function owned_start( int $user = 0 ): array { $fixture = owned_fixture( $user ); $fixture[0]->init(); $fixture[0]->assert_session_ready(); return $fixture; }
function owned_prepare( $handler ): void { $handler->prepare_session_token(); $handler->complete_session_preparation(); }
function owned_dirty( $handler ): bool { $property = new ReflectionProperty( WC_Session::class, '_dirty' ); return $property->getValue( $handler ); }
function owned_count( string $kind, int $since = 0 ): int { return HandlerContractBoundary::count( [ $kind ], $since ); }
function owned_inert( $handler ): void {
	$handler->set('cart','ignored'); $handler->cart='ignored'; unset($handler->cart); $handler->mark_dirty(); $handler->save_if_dirty(); $handler->save_data(); $handler->reload_data(); $handler->init_session_cookie(); $handler->init_session_token(); $handler->set_session_expiration(); $handler->init();
	owned_expect( 'default' === $handler->get('cart','default') && false === $handler->build_token() && [] === $handler->add_prepared_session_header([]) );
}
$cases = [];
$cases['lifecycle installs before key filter acquire and authoritative read'] = function () {
	[ $h, $db ] = owned_start(); $events=array_column(HandlerContractBoundary::$events,'kind');
	owned_expect( array_search('lifecycle-install',$events,true) < array_search('filter:graphql_woocommerce_secret_key',$events,true) && array_search('scope-begin',$events,true) < array_search('db-read',$events,true) );
	owned_expect( 5 === $db->timeout && 1 === $db->reads && 'authoritative-synthetic' === $h->get('cart') && $h->has_owned_scope() );
};
foreach ( ['malformed','cross'] as $credential ) { $cases['rejected '.$credential.' before lock and persisted reads'] = function () use($credential) {
	[ $h,$db ]=owned_fixture('cross'===$credential?17:0,$credential); $h->init(); owned_error(fn()=>$h->assert_session_ready(),'WL_CART_SESSION_INVALID'); owned_expect(0===$db->reads && 0===$db->writes && !in_array('begin',$db->calls,true));
}; }
$cases['missing capability unavailable without lock read or output'] = function () {
	[ $h,$db ]=owned_fixture(); $GLOBALS['wpdb']=(object)['prefix'=>'contract_']; $h->init(); owned_error(fn()=>$h->assert_session_ready()); owned_error(fn()=>$h->assert_response_available()); owned_expect(0===$db->reads && 0===$db->writes);
};
foreach ( ['customer','cart','session'] as $object ) { $cases['existing or foreign WC '.$object.' rejected before scope'] = function () use($object) {
	[ $h,$db ]=owned_fixture(); WC()->{$object}=new stdClass(); $h->init(); owned_error(fn()=>$h->assert_session_ready()); owned_expect(0===$db->reads && !in_array('begin',$db->calls,true));
}; }
$cases['account caches invalidated before controlled customer hydration'] = function () {
	[ $h,$db ]=owned_fixture(17); HandlerContractBoundary::$cache['user_meta:17']=['billing_city'=>'stale']; HandlerContractBoundary::$cache['users:17']=(object)['ID'=>17,'user_login'=>'old','user_email'=>'old@example.invalid','user_nicename'=>'old']; $h->init(); $h->assert_session_ready();
	owned_expect( !isset(HandlerContractBoundary::$cache['user_meta:17']) && !isset(HandlerContractBoundary::$cache['users:17']) && 1===owned_count('account-read') && 1===owned_count('db-read') );
	HandlerContractBoundary::event('controlled-hydration'); owned_expect( count(HandlerContractBoundary::$events)-1 > array_search('account-read',array_column(HandlerContractBoundary::$events,'kind'),true) );
};
$cases['session cache never authoritative and reload never invalidates prefix'] = function () {
	[ $h,$db,$id ]=owned_fixture(); $prefixKey=WC_SESSION_CACHE_GROUP.':wc_'.WC_SESSION_CACHE_GROUP.'_cache_prefix'; HandlerContractBoundary::$cache[WC_SESSION_CACHE_GROUP.':wc_cache_fixed-prefix_'.$id]=['cart'=>'stale']; $h->init();
	HandlerContractBoundary::$cache[WC_SESSION_CACHE_GROUP.':wc_cache_fixed-prefix_'.$id]=['cart'=>'repopulated']; $h->reload_data(); owned_expect('authoritative-synthetic'===$h->get('cart') && 2===$db->reads && 'fixed-prefix'===HandlerContractBoundary::$cache[$prefixKey] && 0===owned_count('cache-write'));
};
$cases['GraphQL omits transaction manager and successful shutdown saver'] = function () {
	[ $h ]=owned_start(); foreach(HandlerContractBoundary::$hooks as $priorities) { foreach($priorities as $callbacks) { foreach($callbacks as [$callback]) { owned_expect(!(is_array($callback)&&$callback[0] instanceof \WPGraphQL\WooCommerce\Utils\Session_Transaction_Manager)); } } }
	owned_expect(empty(HandlerContractBoundary::$hooks['shutdown']) && 0===owned_count('transient-read') && 0===owned_count('transient-write'));
};
foreach ( [0,1,false] as $result ) { $cases['checked write result '.var_export($result,true)] = function () use($result) {
	[ $h,$db ]=owned_start(); $h->set('cart','changed-synthetic'); $db->write_result=$result;
	if(false===$result) { owned_error(fn()=>$h->save_data()); owned_expect(owned_dirty($h)); owned_error(fn()=>$h->assert_response_available()); } else { $h->save_data(); owned_expect(!owned_dirty($h)); }
	owned_expect(1===$db->writes && 0===owned_count('cache-write'));
}; }
foreach ( ['get_session','save_data','delete_session'] as $method ) { $cases['unrelated old key rejected '.$method] = function () use($method) {
	[ $h,$db ]=owned_start(); $h->set('cart','dirty'); $reads=$db->reads; owned_error(fn()=>$h->{$method}(str_repeat('b',32)),'WL_CART_SESSION_TRANSITION_INVALID'); owned_expect($reads===$db->reads && 0===$db->writes);
}; }
foreach ( ['delete','timestamp'] as $operation ) { $cases['checked '.$operation.' zero rows valid'] = function () use($operation) { [ $h,$db,$id ]=owned_start(); $db->write_result=0; 'delete'===$operation ? $h->delete_session($id) : $h->update_session_timestamp($id,time()+50); owned_expect(1===$db->writes&&0===owned_count('cache-write')); }; }
$cases['replacement driver rejects read then captured abort once'] = function () {
	[ $h,$db,$id ]=owned_start(); $replacement=new HandlerContractDatabase(); $GLOBALS['wpdb']=$replacement; owned_error(fn()=>$h->get_session($id)); $h->discard_owned_scope(); $h->discard_owned_scope(); owned_expect(1===count(array_filter($db->calls,fn($c)=>'abort'===$c))&&[]===$replacement->calls&&0===$db->writes);
};
$cases['sticky driver failure prevents response without extra persistence'] = function () { [ $h,$db ]=owned_start(); $db->report_failed=true; $reads=$db->reads; owned_error(fn()=>$h->assert_response_available()); owned_expect($reads===$db->reads&&0===$db->writes); };
$cases['complete writes seals releases once and all later callbacks inert'] = function () {
	[ $h,$db ]=owned_start(); owned_prepare($h); $h->set('cart','changed'); $h->complete_owned_scope(); owned_expect('released'===$db->state&&!$h->has_owned_scope()&&!owned_dirty($h)); $events=count(HandlerContractBoundary::$events); $calls=count($db->calls); owned_inert($h); $h->complete_owned_scope(); owned_expect($events===count(HandlerContractBoundary::$events)&&$calls===count($db->calls)); owned_error(fn()=>$h->assert_session_ready()); $h->assert_response_available();
};
$cases['auth detach closes writers seals releases without saving old dirty state'] = function () {
	[ $h,$db,$id ]=owned_start(); $h->set('cart','discarded'); $h->detach_for_auth(); owned_expect('released'===$db->state&&0===$db->writes&&$h->is_auth_detached()&&'authoritative-synthetic'===HandlerContractBoundary::$rows[$id]['cart']);
	$events=array_column(HandlerContractBoundary::$events,'kind'); owned_expect(array_search('writers-close',$events,true)<array_search('scope-seal',$events,true)); HandlerContractBoundary::$user=17; $since=count(HandlerContractBoundary::$events); owned_inert($h); owned_expect($since===count(HandlerContractBoundary::$events)); owned_error(fn()=>$h->assert_session_ready(),'WL_CART_SESSION_TRANSITION_INVALID'); $h->assert_response_available();
};
$cases['released driver failure cannot revive deliverable auth outcome'] = function () { [ $h,$db ]=owned_start(); $h->detach_for_auth(); $db->report_failed=true; owned_error(fn()=>$h->assert_response_available()); owned_expect(0===$db->writes); };
$cases['explicit discard clears memory aborts once without saving'] = function () { [ $h,$db ]=owned_start(); $h->set('cart','discard'); $h->discard_owned_scope(); $h->discard_owned_scope(); owned_expect(1===owned_count('scope-abort')&&0===$db->writes&&!owned_dirty($h)); owned_error(fn()=>$h->assert_response_available()); };
$cases['lifecycle terminal prevents later side effects'] = function () { [ $h,$db ]=owned_start(); owned_prepare($h); $h->get_owned_lifecycle()->terminal=true; $since=count(HandlerContractBoundary::$events); $calls=count($db->calls); owned_inert($h); owned_expect($since===count(HandlerContractBoundary::$events)&&$calls===count($db->calls)); owned_error(fn()=>$h->assert_session_ready()); };
$cases['one named header callback cached output needs no driver calls'] = function () { [ $h,$db ]=owned_start(); owned_prepare($h); $h->set_customer_session_token(true); $h->set_customer_session_token(true); $callbacks=HandlerContractBoundary::$hooks['graphql_response_headers_to_send'][10]??[]; owned_expect(1===count($callbacks)&&[$h,'add_prepared_session_header']===$callbacks[0][0]); $calls=count($db->calls); $events=count(HandlerContractBoundary::$events); owned_expect(is_string($h->build_token())&&isset($h->add_prepared_session_header([])['woocommerce-session'])); owned_expect($calls===count($db->calls)&&$events===count(HandlerContractBoundary::$events)); };
$cases['OPTIONS constructor and init are inert even configured filters throw'] = function () { owned_fixture(); $_SERVER['REQUEST_METHOD']='OPTIONS'; HandlerContractBoundary::$hooks=[]; HandlerContractBoundary::$events=[]; add_filter('woocommerce_cookie',fn()=>throw new RuntimeException('Must not execute')); $h=new QL_Session_Handler(); WC()->session=$h; $h->init(); owned_expect([]===HandlerContractBoundary::$events&&!$h->has_owned_scope()&&!$h->is_graphql_session()&&false===$h->get_session_token()); };
$cases['native cookie absent JWT preserves legacy manager and shutdown path'] = function () { [ $h,$db ]=owned_fixture(0,'absent',false); add_filter('graphql_woocommerce_secret_key',fn()=>throw new RuntimeException('Native must not need key')); $h->init(); owned_expect(!$h->has_owned_scope()&&[]===$db->calls&&!empty(HandlerContractBoundary::$hooks['shutdown'][20])&&false===$h->is_graphql_session()); };

$cases['fresh guest acquires before authoritative absent-row read'] = function () { [ $h,$db ]=owned_fixture(0,'absent'); $h->init(); $h->assert_session_ready(); owned_expect(1===$db->reads && 1===owned_count('scope-begin') && []===$h->get_session_data()); };
$cases['invalid effective key blocks acquire and response'] = function () { [ $h,$db ]=owned_fixture(); add_filter('graphql_woocommerce_secret_key',fn()=>str_repeat('k',31)); $h->init(); owned_error(fn()=>$h->assert_session_ready()); owned_error(fn()=>$h->assert_response_available()); owned_expect(0===$db->reads&&!in_array('begin',$db->calls,true)); };
$cases['driver acquisition failure stays unavailable before reads'] = function () { [ $h,$db ]=owned_fixture(); $db->throw_begin=true; $h->init(); owned_error(fn()=>$h->assert_response_available()); owned_expect(0===$db->reads&&0===$db->writes); };
$cases['lost ownership blocks dirty save before any SQL'] = function () { [ $h,$db ]=owned_start(); $h->set('cart','dirty'); $db->failed=true; owned_error(fn()=>$h->save_data()); owned_expect(owned_dirty($h)&&0===$db->writes); };
foreach(['delete','timestamp'] as $operation) { $cases['false checked '.$operation.' cannot deliver credentials'] = function () use($operation) { [ $h,$db,$id ]=owned_start(); owned_prepare($h); $db->write_result=false; owned_error(fn()=> 'delete'===$operation ? $h->delete_session($id) : $h->update_session_timestamp($id,time()+50)); owned_error(fn()=>$h->assert_response_available()); owned_expect(1===$db->writes&&[]===$h->add_prepared_session_header([])&&0===owned_count('cache-write')); }; }
foreach(['throw_seal','throw_release'] as $flag) { $cases['completion '.$flag.' cannot finalize or publish and remains abortable'] = function () use($flag) { [ $h,$db ]=owned_start(); owned_prepare($h); $db->{$flag}=true; owned_error(fn()=>$h->complete_owned_scope()); owned_error(fn()=>$h->assert_response_available()); owned_expect($h->has_owned_scope()&&[]===$h->add_prepared_session_header([])); $h->discard_owned_scope(); owned_expect(1===owned_count('scope-abort')&&0===$db->writes); }; }
$cases['initial invalid response remains deliverable without scope'] = function () { [ $h,$db ]=owned_fixture(0,'malformed'); $h->init(); owned_error(fn()=>$h->assert_session_ready(),'WL_CART_SESSION_INVALID'); $h->assert_response_available(); owned_expect(!$h->has_owned_scope()&&0===$db->reads&&0===$db->writes); };
$cases['ordinary identity transition response remains deliverable while storage healthy'] = function () { [ $h,$db ]=owned_start(); HandlerContractBoundary::$user=17; owned_error(fn()=>$h->assert_session_ready(),'WL_CART_SESSION_TRANSITION_INVALID'); $h->assert_response_available(); owned_expect(0===$db->writes); };

$cases['successful SQL with failed eviction keeps dirty and blocks response'] = function () { [ $h,$db ]=owned_start(); $h->set('cart','dirty'); $db->repopulate_on_write=true; HandlerContractBoundary::$sticky_cache=true; owned_error(fn()=>$h->save_data()); owned_expect(owned_dirty($h)&&1===$db->writes&&0===owned_count('cache-write')); owned_error(fn()=>$h->assert_response_available()); };

$cases['invalid rejection flag pure without scope and original code retained'] = function () { [ $h,$db ]=owned_fixture(0,'malformed'); $h->init(); $calls=count($db->calls); $events=count(HandlerContractBoundary::$events); owned_expect($h->has_session_rejection()&&!$h->has_owned_scope()); owned_expect($calls===count($db->calls)&&$events===count(HandlerContractBoundary::$events)); owned_error(fn()=>$h->assert_session_ready(),'WL_CART_SESSION_INVALID'); $h->assert_response_available(); };
$cases['transition rejection flag retains abortable scope until explicit discard'] = function () { [ $h,$db ]=owned_start(); $h->set('cart','discard'); HandlerContractBoundary::$user=17; owned_error(fn()=>$h->assert_session_ready(),'WL_CART_SESSION_TRANSITION_INVALID'); $calls=count($db->calls); owned_expect($h->has_session_rejection()&&$h->has_owned_scope()&&$calls===count($db->calls)); $h->assert_response_available(); $h->discard_owned_scope(); owned_expect($h->has_session_rejection()&&!$h->has_owned_scope()&&1===owned_count('scope-abort')&&0===$db->writes); };
$cases['unavailable and clean detached outcome never masquerade as ordinary rejection'] = function () { [ $h,$db ]=owned_start(); owned_expect(!$h->has_session_rejection()); $h->detach_for_auth(); owned_expect(!$h->has_session_rejection()); $db->report_failed=true; owned_error(fn()=>$h->assert_response_available()); $calls=count($db->calls); owned_expect(!$h->has_session_rejection()&&$calls===count($db->calls)); };

if ( isset($argv[1]) ) {
	$index=(int)$argv[1]; $case=array_values($cases)[$index]??null; if(!$case){exit(2);} try{$case(); fwrite(STDOUT,"PASS\n");exit(0);}catch(Throwable $error){fwrite(STDERR,"FAIL: controlled component assertion or boundary failure.\n");exit(1);}
}
$failed=0; foreach(array_keys($cases) as $index=>$name) {
	$process=proc_open([PHP_BINARY,__FILE__,(string)$index],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
	$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($process);
	$pass=0===$status&&"PASS\n"===$out&&''===$err; if(!$pass){$failed++;} fwrite(STDOUT,($pass?'PASS ':'FAIL ').$name."\n");
}
fwrite(STDOUT,sprintf("Owned handler contracts: %d cases, %d failures.\n",count($cases),$failed));
foreach(['handler'=>'includes/utils/class-ql-session-handler.php','loader'=>'includes/class-wp-graphql-woocommerce.php','storage'=>'includes/utils/class-cart-session-storage.php','fixture'=>'tests/contracts/cart-session-owned-handler-fixtures.php'] as $label=>$path){fwrite(STDOUT,$label.' SHA256 '.hash_file('sha256',$owner.'/'.$path)."\n");}
exit($failed?1:0);

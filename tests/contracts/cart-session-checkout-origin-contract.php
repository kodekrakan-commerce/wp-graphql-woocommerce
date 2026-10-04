<?php
/** Actual default closure/prepare/customer branch with a declared substituted
 * before-checkout caller; natural validation/order path deliberately omitted.
 * Actual handler/operation/lifecycle/WP_Hook/Executor/WPMutationType run. WC, SQL,
 * source-pinned Router and auth boundaries remain controlled; no native account,
 * reservation, checkout admission, cookie/adoption or order proof is claimed. */
error_reporting(E_ALL); ini_set('display_errors','0'); ini_set('log_errors','0');
$owner=dirname(__DIR__,2);$fixture=__DIR__.'/cart-session-checkout-origin-fixtures.php';
// SOURCE_PINS_BEGIN
const ORIGIN_PINS = [
 'WPGraphQL\\WooCommerce\\Utils\\Cart_Session_Lifecycle'=>'d5f2c190f910a8429bd2a3b33f29643708a3407cb192151c6ef0b47a0317d88a',
 'WPGraphQL\\WooCommerce\\Utils\\QL_Session_Handler'=>'a2948b26008924716e89a043bc629b140769384f33046d5b119c7b8afe0b1dae',
 'WPGraphQL\\WooCommerce\\Utils\\Cart_Session_Operation'=>'0a1f6e9b48e46db49032568b1baf5de17e887b508362e446454968dbfe0834a2',
 'WPGraphQL\\WooCommerce\\Mutation\\Checkout'=>'013c4c0aa8bf172fa80f05d5b512e7f8a74b1a51ac2abbb4f8d44bdd601ac8ef',
 'WPGraphQL\\WooCommerce\\Data\\Mutation\\Checkout_Mutation'=>'920c0de6b40db4a1b543750ce839e2896b69360934b0b1714d68519eac2c1af7',
 'WPGraphQL\\Registry\\TypeRegistry'=>'f8c3f6af8596a01faa88f09a51637bf0b435ebc86fd3946dcaccdadf45b059cc',
 'WPGraphQL\\Type\\WPMutationType'=>'33bfcaeec264a56c94367a9d49dfa61d1e9f21d24acfe903adca9fc4295207e7',
 'WPGraphQL\\Utils\\InstrumentSchema'=>'6f3bf9d2bd1b49798a0adc22aa843b8f5b74e89f73ebcb91916ea12957ba529c',
 'WP_Hook'=>'b839c0e5672246bca8db1ab781ec8835f7732f253c375a237cbf6ec536e8d12e',
 'WPGraphQL\\Router'=>'cc2ef1ce86d5e3bba0cf3c037530675764e29ca62199c28dca2ce2a48ce8a048',
 'WC_Customer'=>'4e1334926e8601dde64e7b875b6098a148ed75f806faec572b9f7ea1f486d0f7',
 'WC_Cart'=>'4e1334926e8601dde64e7b875b6098a148ed75f806faec572b9f7ea1f486d0f7',
 'WC_Cart_Session'=>'4e1334926e8601dde64e7b875b6098a148ed75f806faec572b9f7ea1f486d0f7',
 'WC_Payment_Gateways'=>'4e1334926e8601dde64e7b875b6098a148ed75f806faec572b9f7ea1f486d0f7',
];
// SOURCE_PINS_END
$wp=rtrim(getenv('WL_WORDPRESS_SOURCE')?:'','/');$gql=rtrim(getenv('WL_WPGRAPHQL_SOURCE')?:'','/');
$paths=[
 'WPGraphQL\\WooCommerce\\Utils\\Cart_Session_Lifecycle'=>$owner.'/includes/utils/class-cart-session-lifecycle.php',
 'WPGraphQL\\WooCommerce\\Utils\\QL_Session_Handler'=>$owner.'/includes/utils/class-ql-session-handler.php',
 'WPGraphQL\\WooCommerce\\Utils\\Cart_Session_Operation'=>$owner.'/includes/utils/class-cart-session-operation.php',
 'WPGraphQL\\WooCommerce\\Mutation\\Checkout'=>$owner.'/includes/mutation/class-checkout.php',
 'WPGraphQL\\WooCommerce\\Data\\Mutation\\Checkout_Mutation'=>$owner.'/includes/data/mutation/class-checkout-mutation.php',
 'WPGraphQL\\Registry\\TypeRegistry'=>$gql.'/src/Registry/TypeRegistry.php',
 'WPGraphQL\\Type\\WPMutationType'=>$gql.'/src/Type/WPMutationType.php','WPGraphQL\\Utils\\InstrumentSchema'=>$gql.'/src/Utils/InstrumentSchema.php',
 'WP_Hook'=>$wp.'/wp-includes/class-wp-hook.php','WPGraphQL\\Router'=>__DIR__.'/cart-session-owned-handler-fixtures.php',
 'WC_Customer'=>$fixture,'WC_Cart'=>$fixture,'WC_Cart_Session'=>$fixture,'WC_Payment_Gateways'=>$fixture];
foreach($paths as $class=>$path){if(!is_file($path)||!hash_equals(ORIGIN_PINS[$class]??'',hash_file('sha256',$path))){fwrite(STDERR,"Fixed origin source cohort differs.\n");exit(2);}}
putenv('WL_ORIGIN_SOURCE_PINS='.json_encode(ORIGIN_PINS));putenv('WL_ORIGIN_FIXTURE_SHA='.ORIGIN_PINS['WC_Customer']);
$cases=[
 'native-registration'=>['ordinary',1,1,1],
 'ordinary'=>['ordinary',1,1,1],'ordinary-cart'=>[null,0,0,0],'filtered-input'=>['ordinary',1,1,1],
 'authenticated'=>['ordinary',1,1,0],'mixed'=>['ordinary',1,1,1],'distinct-roots'=>['ordinary',2,2,2],
 'merged'=>['ordinary',1,1,1],'directives'=>['ordinary',1,1,1],
 'late-posted'=>['WL_CART_SESSION_UNAVAILABLE',1,1,1],'late-policy'=>['WL_CART_SESSION_UNAVAILABLE',1,1,1],
 'mixed-create'=>['WL_CART_SESSION_TRANSITION_INVALID',1,1,1],'double-consume'=>['WL_CART_SESSION_TRANSITION_INVALID',1,1,0],
 'prepare-throw'=>['ordinary',0,1,0],'recursive-entry'=>['WL_CART_SESSION_TRANSITION_INVALID',0,1,0],
 'input-drift'=>['WL_CART_SESSION_TRANSITION_INVALID',0,0,0],'context-drift'=>['WL_CART_SESSION_TRANSITION_INVALID',0,0,0],
 'info-drift'=>['WL_CART_SESSION_TRANSITION_INVALID',0,0,0],'same-source-replacement'=>['WL_CART_SESSION_TRANSITION_INVALID',0,0,0],
 'wrong-closure'=>['WL_CART_SESSION_TRANSITION_INVALID',0,0,0],'nonnull-pre'=>['WL_CART_SESSION_TRANSITION_INVALID',0,0,0],
 'nonnull-pre-callback'=>['WL_CART_SESSION_TRANSITION_INVALID',0,0,0],
 'schema-drift'=>['WL_CART_SESSION_TRANSITION_INVALID',0,0,0],'operation-drift'=>['WL_CART_SESSION_TRANSITION_INVALID',0,0,0],
 'entry-registry-swap'=>['WL_CART_SESSION_UNAVAILABLE',0,0,0],'consume-registry-swap'=>['WL_CART_SESSION_UNAVAILABLE',1,1,0],
 'posted-object'=>['WL_CART_SESSION_TRANSITION_INVALID',1,1,1],
 'one-owner'=>['ordinary',1,1,1],'recursive-capture'=>['WL_CART_SESSION_TRANSITION_INVALID',0,1,0],
 'path-drift'=>['WL_CART_SESSION_TRANSITION_INVALID',0,0,0],
 'registry-swap'=>['WL_CART_SESSION_UNAVAILABLE',0,0,0],'tail-remove'=>['WL_CART_SESSION_UNAVAILABLE',0,0,0],
 'tail-append'=>['WL_CART_SESSION_UNAVAILABLE',0,0,0],
 'direct-no-origin'=>['WL_CART_SESSION_TRANSITION_INVALID',0,0,0],'replay-direct'=>['WL_CART_SESSION_TRANSITION_INVALID',1,1,1],
];
$failures=0;
foreach($cases as $name=>[$code,$entries,$prepare,$policy]){
 $process=proc_open([PHP_BINARY,'-d','display_errors=0','-d','log_errors=0',$fixture,$name],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 fclose($pipes[0]);$raw=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);$result=json_decode($raw,true);
 $ok=$exit===0&&$err===''&&is_array($result)&&$result['entries']===$entries&&$result['prepare']===$prepare&&$result['final_policy']===$policy
  &&$result['wiped']===true&&$result['effects_zero']===true&&$result['writes']===0;
 $ok=$ok&&($code===null?$result['codes']===[]:in_array($code,$result['codes'],true));
 if(!$ok){$failures++;}echo($ok?'PASS ':'FAIL ').$name." [controlled closure/final-branch contract]\n";
}
// Verify the natural production caller forwards exact context/info despite the
// fixture's declared bypass of earlier native validation/session/order work.
$source=file_get_contents($owner.'/includes/data/mutation/class-checkout-mutation.php');
$call_ok=str_contains($source,'self::process_customer( $data, $context, $info );')&&str_contains($source,'$session->create_checkout_customer( $data, $context, $info );');
if(!$call_ok){$failures++;}echo($call_ok?'PASS ':'FAIL ')."actual natural caller context/info forwarding\n";
echo 'RESULT '.(count($cases)+1).' cases, '.$failures." failures\n";
foreach($paths as $name=>$path){echo 'SOURCE '.$name.' '.hash_file('sha256',$path)."\n";}
exit($failures?1:0);

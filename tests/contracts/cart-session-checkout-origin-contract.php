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
 'WPGraphQL\\WooCommerce\\Utils\\QL_Session_Handler'=>'38d929e5c2aaac50e753f8d5f7ff097c70816d8a09660cbe0d77118c58308fc3',
 'WPGraphQL\\WooCommerce\\Utils\\Cart_Session_Operation'=>'e289f01f82a4865e488ca30e9d230fb1791727c3248002e4d712b8d9a9d1e2fa',
 'WPGraphQL\\WooCommerce\\Mutation\\Checkout'=>'a242fc8acb7f53b7939e24eff7f8fa6a32758ee8f68a3547756b9bc5d97a1708',
 'WPGraphQL\\WooCommerce\\Data\\Mutation\\Checkout_Mutation'=>'501f228900e4a356c6cc64b476f614dc43f19c42a3480805a0b65a2f1c022a09',
 'WPGraphQL\\Type\\WPMutationType'=>'33bfcaeec264a56c94367a9d49dfa61d1e9f21d24acfe903adca9fc4295207e7',
 'WPGraphQL\\Utils\\InstrumentSchema'=>'6f3bf9d2bd1b49798a0adc22aa843b8f5b74e89f73ebcb91916ea12957ba529c',
 'WP_Hook'=>'b839c0e5672246bca8db1ab781ec8835f7732f253c375a237cbf6ec536e8d12e',
 'WPGraphQL\\Router'=>'9566dc98d41d8894d316340a85f56b752f3d9b1fafd3e604cbcdca0b13d39617',
 'WC_Customer'=>'2eea8d0cadd123219379810e457068319174ddd5826577f45bad062c8871cd36',
 'WC_Cart'=>'2eea8d0cadd123219379810e457068319174ddd5826577f45bad062c8871cd36',
 'WC_Cart_Session'=>'2eea8d0cadd123219379810e457068319174ddd5826577f45bad062c8871cd36',
];
// SOURCE_PINS_END
$wp=rtrim(getenv('WL_WORDPRESS_SOURCE')?:'','/');$gql=rtrim(getenv('WL_WPGRAPHQL_SOURCE')?:'','/');
$paths=[
 'WPGraphQL\\WooCommerce\\Utils\\QL_Session_Handler'=>$owner.'/includes/utils/class-ql-session-handler.php',
 'WPGraphQL\\WooCommerce\\Utils\\Cart_Session_Operation'=>$owner.'/includes/utils/class-cart-session-operation.php',
 'WPGraphQL\\WooCommerce\\Mutation\\Checkout'=>$owner.'/includes/mutation/class-checkout.php',
 'WPGraphQL\\WooCommerce\\Data\\Mutation\\Checkout_Mutation'=>$owner.'/includes/data/mutation/class-checkout-mutation.php',
 'WPGraphQL\\Type\\WPMutationType'=>$gql.'/src/Type/WPMutationType.php','WPGraphQL\\Utils\\InstrumentSchema'=>$gql.'/src/Utils/InstrumentSchema.php',
 'WP_Hook'=>$wp.'/wp-includes/class-wp-hook.php','WPGraphQL\\Router'=>__DIR__.'/cart-session-owned-handler-fixtures.php',
 'WC_Customer'=>$fixture,'WC_Cart'=>$fixture,'WC_Cart_Session'=>$fixture];
foreach($paths as $class=>$path){if(!is_file($path)||!hash_equals(ORIGIN_PINS[$class]??'',hash_file('sha256',$path))){fwrite(STDERR,"Fixed origin source cohort differs.\n");exit(2);}}
putenv('WL_ORIGIN_SOURCE_PINS='.json_encode(ORIGIN_PINS));putenv('WL_ORIGIN_FIXTURE_SHA='.ORIGIN_PINS['WC_Customer']);
$cases=[
 'ordinary'=>['ordinary',1,1,1],'ordinary-cart'=>[null,0,0,0],'filtered-input'=>['ordinary',1,1,1],
 'authenticated'=>['ordinary',1,1,0],'mixed'=>['ordinary',1,1,1],'distinct-roots'=>['ordinary',2,2,2],
 'merged'=>['ordinary',1,1,1],'directives'=>['ordinary',1,1,1],
 'late-posted'=>['WL_CART_SESSION_TRANSITION_INVALID',1,1,1],'late-policy'=>['WL_CART_SESSION_TRANSITION_INVALID',1,1,1],
 'mixed-create'=>['WL_CART_SESSION_TRANSITION_INVALID',1,1,1],'double-consume'=>['WL_CART_SESSION_TRANSITION_INVALID',1,1,0],
 'prepare-throw'=>['ordinary',0,1,0],'recursive-entry'=>['WL_CART_SESSION_TRANSITION_INVALID',0,1,0],
 'input-drift'=>['WL_CART_SESSION_TRANSITION_INVALID',0,0,0],'context-drift'=>['WL_CART_SESSION_TRANSITION_INVALID',0,0,0],
 'info-drift'=>['WL_CART_SESSION_TRANSITION_INVALID',0,0,0],'same-source-replacement'=>['WL_CART_SESSION_TRANSITION_INVALID',0,0,0],
 'wrong-closure'=>['WL_CART_SESSION_TRANSITION_INVALID',0,0,0],'nonnull-pre'=>['WL_CART_SESSION_TRANSITION_INVALID',0,0,0],
 'nonnull-pre-callback'=>['WL_CART_SESSION_TRANSITION_INVALID',0,0,0],
 'schema-drift'=>['WL_CART_SESSION_TRANSITION_INVALID',0,0,0],'operation-drift'=>['WL_CART_SESSION_TRANSITION_INVALID',0,0,0],
 'entry-registry-swap'=>['WL_CART_SESSION_UNAVAILABLE',0,0,0],'consume-registry-swap'=>['WL_CART_SESSION_UNAVAILABLE',1,1,1],
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
$call_ok=str_contains($source,'self::process_customer( $data, $context, $info );')&&str_contains($source,'$session->hold_checkout_customer_creation( $data, $context, $info );');
if(!$call_ok){$failures++;}echo($call_ok?'PASS ':'FAIL ')."actual natural caller context/info forwarding\n";
echo 'RESULT '.(count($cases)+1).' cases, '.$failures." failures\n";
foreach($paths as $name=>$path){echo 'SOURCE '.$name.' '.hash_file('sha256',$path)."\n";}
exit($failures?1:0);

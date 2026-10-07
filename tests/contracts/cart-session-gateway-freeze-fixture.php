<?php
/** Actual native WP dispatcher, WC_Payment_Gateways/COD and retained guard_request.
 * No site bootstrap, settings, identity, SQL, order/payment or output boundary.
 * Controlled WC_Payment_Gateway settings base, translation/options, handler
 * callback classifier/scope. A named factory selects native COD only.
 */
namespace GraphQL\Error {
 interface ProvidesExtensions { public function getExtensions(): ?array; }
 class UserError extends \Exception {}
}
namespace {
 error_reporting(E_ALL);ini_set('display_errors','0');ini_set('log_errors','0');
 define('ABSPATH','/not-a-site/');
 $wp=rtrim(getenv('WL_WORDPRESS_SOURCE'),'/');$woo=rtrim(getenv('WL_WOOCOMMERCE_SOURCE'),'/');
 require $wp.'/wp-includes/plugin.php';
 require dirname(__DIR__,2).'/includes/utils/class-cart-session-error.php';
 require (getenv('WL_GATEWAY_LIFECYCLE_SOURCE')?:dirname(__DIR__,2).'/includes/utils/class-cart-session-lifecycle.php');
 function __($s,$domain=null){return $s;}
 function is_admin(){return false;}
 function get_option($key,$default=false){return $key==='woocommerce_gateway_order'?[]:$default;}
 #[\AllowDynamicProperties]
 class WC_Payment_Gateway {
  public function init_settings(){}
  public function get_option($key,$default=''){return $default;}
  public function get_option_key(){return 'woocommerce_'.$this->id.'_settings';}
 }
 class GatewayFenceHandler {
  // This gateway-only fixture never enters protected checkout creation.
  public function protects_checkout_order(){return false;}
  public function is_cart_operation_callback($h,$c,$p,$a){return false;}
  public function has_owned_scope(){return false;}
 }
 class UnqualifiedGateway extends WC_Payment_Gateway {
  public $id='unknown';
  public function __construct(){if($GLOBALS['case']==='unknown-completion'){add_action('wc_payment_gateways_initialized','gateway_unknown_completion',11,1);}else{add_filter('woocommerce_payment_complete_order_status','gateway_unknown_status',11,3);}}
 }
 function gateway_unknown_status($v){return $v;}
 function gateway_unknown_completion($manager){$GLOBALS['unknown_completion_calls']++;}
 function gateway_unknown_factory($v){$GLOBALS['unknown_factory_calls']++;return $v;}
 function gateway_selected_factory($v){$GLOBALS['factory_calls']++;return in_array($GLOBALS['case'],['unknown-created-callback','unknown-completion'],true)?['WC_Gateway_COD','UnqualifiedGateway']:['WC_Gateway_COD'];}
 require $woo.'/vendor/automattic/jetpack-constants/src/class-constants.php';
 require $woo.'/includes/gateways/cod/class-wc-gateway-cod.php';
 require $woo.'/includes/class-wc-payment-gateways.php';
 $GLOBALS['case']=$argv[1]??'';$GLOBALS['factory_calls']=0;$GLOBALS['unknown_factory_calls']=0;$GLOBALS['unknown_completion_calls']=0;
 function ensure($b){if(!$b){throw new \RuntimeException('Controlled gateway assertion failed');}}
 function singleton(){return (new \ReflectionProperty('WC_Payment_Gateways','_instance'))->getValue();}
 try {
  ensure(hash_file('sha256',$woo.'/includes/class-wc-payment-gateways.php')==='d474b55ac2bfc8cc6ee5d47ef8c836c9447dba66d5a76b7c9db70e73d13ae758');
  ensure(hash_file('sha256',$wp.'/wp-includes/class-wp-hook.php')==='b839c0e5672246bca8db1ab781ec8835f7732f253c375a237cbf6ec536e8d12e');
  ensure(hash_file('sha256',$woo.'/includes/gateways/cod/class-wc-gateway-cod.php')==='a01506c8dac799f6babbe2696325c7fddad92dc17020fa184808949f8224359d');
  $reflection=new \ReflectionClass(\WPGraphQL\WooCommerce\Utils\Cart_Session_Lifecycle::class);$life=$reflection->newInstanceWithoutConstructor();
  $manifest=[['hook'=>'woocommerce_payment_gateways','priority'=>10,'accepted_args'=>1,'kind'=>'function','function'=>'gateway_selected_factory','sha256'=>hash_file('sha256',__FILE__),'stable_registry'=>true,'nonstreaming'=>true],['hook'=>'woocommerce_payment_complete_order_status','priority'=>10,'accepted_args'=>3,'kind'=>'method','class'=>'WC_Gateway_COD','method'=>'change_payment_complete_order_status','instance'=>true,'sha256'=>hash_file('sha256',$woo.'/includes/gateways/cod/class-wc-gateway-cod.php'),'stable_registry'=>true,'nonstreaming'=>true]];
  $manifest[]=['hook'=>'wc_payment_gateways_initialized','priority'=>10,'accepted_args'=>1,'kind'=>'method','class'=>'WC_Payment_Gateways','method'=>'on_payment_gateways_initialized','instance'=>true,'sha256'=>'d474b55ac2bfc8cc6ee5d47ef8c836c9447dba66d5a76b7c9db70e73d13ae758','stable_registry'=>true,'nonstreaming'=>true];
  $sources=['WP_Hook'=>'b839c0e5672246bca8db1ab781ec8835f7732f253c375a237cbf6ec536e8d12e','WC_Payment_Gateways'=>'d474b55ac2bfc8cc6ee5d47ef8c836c9447dba66d5a76b7c9db70e73d13ae758'];
  if($GLOBALS['case']==='wrong-source'){$sources['WC_Payment_Gateways']=str_repeat('0',64);}
  foreach(['handler'=>new GatewayFenceHandler(),'sources'=>$sources,'manifest'=>$manifest] as $n=>$v){$reflection->getProperty($n)->setValue($life,$v);}
  add_filter('woocommerce_payment_gateways','gateway_selected_factory',10,1);
  add_filter('woocommerce_payment_gateways',[$life,'guard_cohort_entry'],PHP_INT_MIN,1);
  add_filter('wc_payment_gateways_initialized',[$life,'guard_cohort_entry'],PHP_INT_MIN,1);
  if($GLOBALS['case']==='unknown-factory'){add_filter('woocommerce_payment_gateways','gateway_unknown_factory',11,1);}
  $denied=false;try{$life->guard_request();}catch(\WPGraphQL\WooCommerce\Utils\Cart_Session_Error $e){ensure($e->getExtensions()['code']==='WL_CART_SESSION_UNAVAILABLE');$denied=true;}
  if(in_array($GLOBALS['case'],['unknown-factory','wrong-source'],true)){
   ensure($denied && $GLOBALS['factory_calls']===0 && $GLOBALS['unknown_factory_calls']===0 && singleton()===null);
  }elseif($GLOBALS['case']==='unknown-completion'){
   ensure($denied && $GLOBALS['factory_calls']===1 && $GLOBALS['unknown_completion_calls']===0 && singleton()===null && $reflection->getProperty('frozen')->getValue($life)===null);
  }elseif($GLOBALS['case']==='unknown-created-callback'){
   ensure($denied && $GLOBALS['factory_calls']===1 && singleton()===null && $reflection->getProperty('frozen')->getValue($life)===null);
  }else{
   ensure(!$denied && $GLOBALS['factory_calls']===1 && get_class(singleton())==='WC_Payment_Gateways');
   $manager=singleton();ensure(count($manager->payment_gateways)===1 && get_class(reset($manager->payment_gateways))==='WC_Gateway_COD');
   $before=$reflection->getProperty('frozen')->getValue($life);ensure(is_array($before));
   $life->guard_request();ensure(singleton()===$manager && $GLOBALS['factory_calls']===1 && $reflection->getProperty('frozen')->getValue($life)===$before);
   if($GLOBALS['case']==='late-change'){
    add_filter('woocommerce_payment_complete_order_status','gateway_unknown_status',11,3);
    $later_denied=false;try{$life->guard_request();}catch(\WPGraphQL\WooCommerce\Utils\Cart_Session_Error $e){$later_denied=true;}
    ensure($later_denied && $GLOBALS['factory_calls']===1 && $reflection->getProperty('frozen')->getValue($life)===$before);
   }
  }
  echo json_encode(['case'=>$GLOBALS['case'],'passed'=>true,'factory_calls'=>$GLOBALS['factory_calls'],'unknown_factory_calls'=>$GLOBALS['unknown_factory_calls'],'unknown_completion_calls'=>$GLOBALS['unknown_completion_calls'],'denied'=>$denied])."\n";
 }catch(\Throwable $e){echo json_encode(['case'=>$GLOBALS['case'],'passed'=>false,'type'=>get_class($e),'unknown_completion_calls'=>$GLOBALS['unknown_completion_calls']])."\n";exit(1);}
}

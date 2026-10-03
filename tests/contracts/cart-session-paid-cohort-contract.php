<?php
/** Actual retained lifecycle install and native sanitize_meta/plugin.php/WP_Hook controls. Order/customer/
 * metadata source classes and handler ownership are explicit controlled seams;
 * actual Settings callback source/descriptor is qualified but not executed. */
namespace WPGraphQL\WooCommerce\Utils {
 final class QL_Session_Handler {
  public bool $failed=false;
  function protects_checkout_order(){return true;}
  function fail_checkout_order():never{$this->failed=true;throw new Cart_Session_Error(Cart_Session_Error::UNAVAILABLE);}
  function assert_session_ready(){if($this->failed){$this->fail_checkout_order();}}
  function assert_owned_scope(){$this->assert_session_ready();}
  function is_cart_operation_callback(...$args){return false;}
  function has_owned_scope(){return true;}
 }
}
namespace WPGraphQL { class Router {} }
namespace {
$wp=rtrim(getenv('WL_WORDPRESS_SOURCE')?:'','/');$settings=rtrim(getenv('WL_SETTINGS_SOURCE')?:'','/');$gql=rtrim(getenv('WL_WPGRAPHQL_SOURCE')?:'','/');
define('ABSPATH',$wp.'/');define('WPINC','wp-includes');
require $wp.'/wp-includes/plugin.php';require $wp.'/wp-includes/meta.php';require $gql.'/vendor/autoload.php';
require dirname(__DIR__,2).'/includes/utils/class-cart-session-http-boundary.php';require dirname(__DIR__,2).'/includes/utils/class-cart-session-error.php';require dirname(__DIR__,2).'/includes/utils/class-cart-session-lifecycle.php';
require $settings.'/includes/deferred-checkout.php';
class WC_Payment_Gateways{} class WC_Data{} class WC_Meta_Data{} class WC_Data_Store_WP{} class WC_Customer{function get_id(){return 17;}} class WC_Cart{} class WC_Cart_Session{protected $cart;function __construct($cart){$this->cart=$cart;}}
function wc_get_is_paid_statuses(){}
function WC(){return $GLOBALS['paid_cohort_wc'];}function get_current_user_id(){return 17;}function __($s,$domain=''){return $s;}
function paid_cohort_known($value){return $value;} function paid_cohort_unknown($value){++$GLOBALS['paid_cohort_effect'];return $value;}
function paid_cohort_second($value){return $value;}
function paid_cohort_generic($value){$GLOBALS['paid_cohort_calls'][]='generic';return 'generic:'.$value;}
function paid_cohort_subtype($value){$GLOBALS['paid_cohort_calls'][]='subtype';return 'subtype:'.$value;}
function cohort_set($o,$k,$v){(new \ReflectionProperty($o,$k))->setValue($o,$v);}
function cohort_record($hook,$function,$priority=10,$arity=1){return ['hook'=>$hook,'kind'=>'function','function'=>$function,'priority'=>$priority,'accepted_args'=>$arity,'sha256'=>hash_file('sha256',(new ReflectionFunction($function))->getFileName()),'stable_registry'=>true,'nonstreaming'=>true];}
function cohort_fixture($variant='valid',$sanitizers=[]){
 $GLOBALS['wp_filter']=[];$GLOBALS['wp_actions']=[];$GLOBALS['wp_current_filter']=[];$GLOBALS['paid_cohort_effect']=0;$GLOBALS['paid_cohort_calls']=[];
 $h=new \WPGraphQL\WooCommerce\Utils\QL_Session_Handler();$GLOBALS['paid_cohort_handler']=$h;$l=new \WPGraphQL\WooCommerce\Utils\Cart_Session_Lifecycle($h,'woocommerce-session',[]);
 $c=new WC_Cart();$cs=new WC_Cart_Session($c);$customer=new WC_Customer();$GLOBALS['paid_cohort_wc']=(object)['session'=>$h,'customer'=>$customer,'cart'=>$c];
 foreach(['cart'=>$c,'cart_session'=>$cs,'customer'=>$customer]as $k=>$v){cohort_set($l,$k,$v);}
 $sources=[];foreach(['WPGraphQL\\Router','WPGraphQL\\WooCommerce\\Utils\\QL_Session_Handler','WC_Payment_Gateways','WP_Hook','WC_Data','WC_Meta_Data','WC_Data_Store_WP','WC_Customer','WC_Cart','WC_Cart_Session']as $class){$sources[$class]=hash_file('sha256',(new ReflectionClass($class))->getFileName());}
 foreach(['wc_get_is_paid_statuses','add_metadata','update_metadata_by_mid','delete_metadata_by_mid','woonuxt_defer_stripe_checkout'] as $fn){$sources['function:'.$fn]=hash_file('sha256',(new ReflectionFunction($fn))->getFileName());}
 if($variant==='wrong-source'){$sources['function:woonuxt_defer_stripe_checkout']=str_repeat('0',64);}cohort_set($l,'sources',$sources);
 $hook='graphql_woocommerce_checkout_payment_result';$manifest=[];
 if($variant!=='missing'){$priority=$variant==='wrong-priority'?11:10;$arity=$variant==='wrong-arity'?3:4;$fn=$variant==='wrong-function'?'paid_cohort_known':'woonuxt_defer_stripe_checkout';add_filter($hook,$fn,$priority,$arity);$manifest[]=cohort_record($hook,$fn,$priority,$arity);}
 if($variant==='extra-before'||$variant==='extra-after'){ $priority=$variant==='extra-before'?5:20;add_filter($hook,'paid_cohort_known',$priority,4);$manifest[]=cohort_record($hook,'paid_cohort_known',$priority,4);}
 if($variant==='unknown-getter'){add_filter('woocommerce_order_get__stripe_intent_id','paid_cohort_unknown',10,1);}
 if($variant==='read-reorder'){foreach(['paid_cohort_known','paid_cohort_second']as $fn){add_filter('woocommerce_data_store_wp_post_read_meta',$fn,10,1);$manifest[]=cohort_record('woocommerce_data_store_wp_post_read_meta',$fn);}}
 foreach($sanitizers as [$sanitizer,$fn,$qualified]){add_filter($sanitizer,$fn,10,1);if($qualified){$manifest[]=cohort_record($sanitizer,$fn);}}
 if($variant==='bad-descriptor'){$manifest[0]['sha256']=str_repeat('0',64);}cohort_set($l,'manifest',$manifest);
 $GLOBALS['paid_cohort_lifecycle']=$l;
 $l->install();
 return [$l,$h];
}
function cohort_expect($ok){if(!$ok){throw new RuntimeException('Cohort assertion failed.');}}
function cohort_denied($action,$handler){try{$action();}catch(\WPGraphQL\WooCommerce\Utils\Cart_Session_Error $e){cohort_expect($handler->failed&&$GLOBALS['paid_cohort_effect']===0);return;}throw new RuntimeException('Cohort did not reject.');}
function cohort_setup_denied($action){try{$action();}catch(\WPGraphQL\WooCommerce\Utils\Cart_Session_Error $e){cohort_expect($GLOBALS['paid_cohort_handler']->failed&&$GLOBALS['paid_cohort_effect']===0&&$GLOBALS['paid_cohort_calls']===[]);return;}throw new RuntimeException('Cohort setup did not reject.');}
$cases=[];
$cases['exact reviewed Settings function 10/4 qualifies and revalidates']=function(){[$l,$h]=cohort_fixture();$l->qualify_checkout_deferred_payment();$l->assert_checkout_deferred_cohort();cohort_expect(!$h->failed);};
foreach(['missing','wrong-arity','wrong-priority','wrong-function','wrong-source','bad-descriptor','extra-before','extra-after','unknown-getter']as $variant){$cases[$variant.' rejected before writer effects']=function()use($variant){cohort_setup_denied(function()use($variant){[$l,$h]=cohort_fixture($variant);$l->qualify_checkout_deferred_payment();});};}
foreach(['graphql_woocommerce_checkout_payment_result','woocommerce_data_store_wp_post_read_meta','added_order_meta','woocommerce_order_is_paid','woocommerce_order_get__stripe_intent_id','sanitize_post_meta__woonuxt_deferred_payment']as $hook){$cases['late '.$hook.' denied before callback']=function()use($hook){[$l,$h]=cohort_fixture();$l->qualify_checkout_deferred_payment();add_filter($hook,'paid_cohort_unknown',10,1);cohort_denied(fn()=>apply_filters($hook,null),$h);};}
$cases['participating read callbacks cannot reorder after frozen qualification']=function(){[$l,$h]=cohort_fixture('read-reorder');$l->qualify_checkout_deferred_payment();remove_filter('woocommerce_data_store_wp_post_read_meta','paid_cohort_known',10);add_filter('woocommerce_data_store_wp_post_read_meta','paid_cohort_known',10,1);cohort_denied(fn()=>$l->assert_checkout_deferred_cohort(),$h);};
foreach(['_woonuxt_deferred_payment','_wl_checkout_operation_uuid']as $key){
 $generic='sanitize_post_meta_'.$key;$subtype=$generic.'_for_shop_order';
 $cases['native generic-only transformed output '.$key]=function()use($key,$generic,$subtype){
  [$l,$h]=cohort_fixture('valid',[[$generic,'paid_cohort_generic',true]]);$l->qualify_checkout_deferred_payment();
   $result=sanitize_meta($key,'raw','post','shop_order');if($result!=='generic:raw'){throw new RuntimeException('Native generic sanitizer expected generic:raw, got '.var_export($result,true));}cohort_expect(!has_filter($subtype));cohort_expect($GLOBALS['paid_cohort_calls']===['generic']&&!$h->failed);
 };
 $cases['native genuine subtype precedence '.$key]=function()use($key,$generic,$subtype){
  [$l,$h]=cohort_fixture('valid',[[$generic,'paid_cohort_generic',true],[$subtype,'paid_cohort_subtype',true]]);$l->qualify_checkout_deferred_payment();
  cohort_expect(sanitize_meta($key,'raw','post','shop_order')==='subtype:raw');cohort_expect($GLOBALS['paid_cohort_calls']===['subtype']&&!$h->failed);
 };
 $cases['native declared dynamic subtype transformed output '.$key]=function()use($key,$generic){
  [$l,$h]=cohort_fixture('valid',[[$generic,'paid_cohort_generic',true],[$generic.'_for_custom_order','paid_cohort_subtype',true]]);$l->qualify_checkout_deferred_payment();
  cohort_expect(sanitize_meta($key,'raw','post','custom_order')==='subtype:raw');cohort_expect($GLOBALS['paid_cohort_calls']===['subtype']&&!$h->failed);
 };
 $cases['native absent sanitizers unchanged '.$key]=function()use($key,$generic,$subtype){
  [$l,$h]=cohort_fixture();$l->qualify_checkout_deferred_payment();cohort_expect(!has_filter($generic)&&!has_filter($subtype));
  cohort_expect(sanitize_meta($key,'raw','post','shop_order')==='raw'&&$GLOBALS['paid_cohort_calls']===[]&&!$h->failed);
 };
 foreach([$generic,$subtype,$generic.'_for_custom_order']as $hook){
  $cases['native preexisting unknown sanitizer '.$hook]=function()use($hook){cohort_setup_denied(fn()=>cohort_fixture('valid',[[$hook,'paid_cohort_unknown',false]]));};
  $cases['native late unknown sanitizer '.$hook]=function()use($key,$hook){[$l,$h]=cohort_fixture();$l->qualify_checkout_deferred_payment();add_filter($hook,'paid_cohort_unknown',10,1);cohort_denied(fn()=>sanitize_meta($key,'raw','post',str_ends_with($hook,'custom_order')?'custom_order':'shop_order'),$h);};
  $cases['native late declared sanitizer violates freeze '.$hook]=function()use($key,$hook){[$l,$h]=cohort_fixture();$manifest=(new ReflectionProperty($l,'manifest'))->getValue($l);$manifest[]=cohort_record($hook,'paid_cohort_unknown');cohort_set($l,'manifest',$manifest);$l->qualify_checkout_deferred_payment();add_filter($hook,'paid_cohort_unknown',10,1);cohort_denied(fn()=>sanitize_meta($key,'raw','post',str_ends_with($hook,'custom_order')?'custom_order':'shop_order'),$h);};
 }
 $cases['native global late all denied before effect '.$key]=function()use($key){[$l,$h]=cohort_fixture();$l->qualify_checkout_deferred_payment();add_filter('all','paid_cohort_unknown',PHP_INT_MIN,1);cohort_denied(fn()=>sanitize_meta($key,'raw','post','shop_order'),$h);};
 $cases['native declared late all violates freeze '.$key]=function()use($key){[$l,$h]=cohort_fixture();$manifest=(new ReflectionProperty($l,'manifest'))->getValue($l);$manifest[]=cohort_record('all','paid_cohort_unknown',PHP_INT_MIN,1);cohort_set($l,'manifest',$manifest);$l->qualify_checkout_deferred_payment();add_filter('all','paid_cohort_unknown',PHP_INT_MIN,1);cohort_denied(fn()=>sanitize_meta($key,'raw','post','shop_order'),$h);};
}
$cases['native unknown preexisting global all rejected at install']=function(){cohort_setup_denied(fn()=>cohort_fixture('valid',[['all','paid_cohort_unknown',false]]));};
$cases['native exact owned all descriptor rejects arity drift']=function(){[$l,$h]=cohort_fixture();$l->qualify_checkout_deferred_payment();add_filter('all',[$l,'guard_sanitizer_dispatch'],PHP_INT_MIN,2);cohort_denied(fn()=>sanitize_meta('_woonuxt_deferred_payment','raw','post','shop_order'),$h);};
$failed=[];$total=0;foreach($cases as $name=>$test){if(getenv('WL_SANITIZER_ONLY')&&!str_contains($name,getenv('WL_SANITIZER_ONLY'))){continue;}$total++;$level=ob_get_level();try{$test();}catch(Throwable $e){$failed[]=$name;fwrite(STDERR,'FAIL '.$name.': '.get_class($e).' '.$e->getMessage()."\n");}finally{if(isset($GLOBALS['paid_cohort_lifecycle'])){(new ReflectionProperty($GLOBALS['paid_cohort_lifecycle'],'boundary'))->getValue($GLOBALS['paid_cohort_lifecycle'])->restore();unset($GLOBALS['paid_cohort_lifecycle']);}while(ob_get_level()>$level){ob_end_clean();}}}
echo json_encode(['suite'=>'paid-checkout-callback-cohort','cases'=>$total,'passed'=>$total-count($failed),'failed'=>$failed,'php'=>PHP_VERSION,'limits'=>'Actual lifecycle install + native sanitize_meta/plugin.php/WP_Hook; ownership/source classes substituted; actual Settings descriptor/source only, no writer/native persistence/HTTP acceptance.'],JSON_THROW_ON_ERROR)."\n";exit($failed?1:0);
}

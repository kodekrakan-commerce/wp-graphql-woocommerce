<?php
/** Source-only component controls: actual plugin.php/WP_Hook, retained lifecycle and pinned MU.
 * Reuses paid-cohort ownership/customer/order/source-class seams; Settings is qualified, not executed.
 * get_post/cache/provider/storage and MU invalidator are counted substitutes. No WP bootstrap/SQL/HTTP.
 */
const EMPTY_CORE_PINS = [
 'plugin.php'=>'d70b9e18d34ab3fe46e15548a5e8e089ea5920832f06a4d89a9d64b2f7938b0d',
 'class-wp-hook.php'=>'b839c0e5672246bca8db1ab781ec8835f7732f253c375a237cbf6ec536e8d12e',
];
$core_source=rtrim(getenv('WL_WORDPRESS_SOURCE')?:'','/');
foreach(EMPTY_CORE_PINS as $file=>$sha){$path=$core_source.'/wp-includes/'.$file;if(!is_file($path)||!hash_equals($sha,hash_file('sha256',$path))){fwrite(STDERR,"Pinned WordPress dispatcher source required.\n");exit(2);}}
const WL_PAID_COHORT_BOOTSTRAP_ONLY = true;
require __DIR__.'/cart-session-paid-cohort-contract.php';
const EMPTY_HOOK = 'wl_commerce_cache_invalidation_meta_keys';
const EMPTY_MU_SHA = '4553986ed9d299d965876625433317ffc7033f2ccd72ebcf4002af2f37955ef6';
$mu_source=rtrim(getenv('WL_MU_SOURCE')?:'','/').'/wl-commerce-cache-invalidation.php';
if(!is_file($mu_source)||!hash_equals(EMPTY_MU_SHA,hash_file('sha256',$mu_source))){fwrite(STDERR,"Pinned MU source required.\n");exit(2);}
require $mu_source;
function get_post(...$args){++$GLOBALS['empty_effects']['get_post'];return (object)['ID'=>91,'post_type'=>'shop_order','post_parent'=>0];}
function empty_foreign_all(...$args){++$GLOBALS['empty_effects']['foreign_all'];}
function empty_adverse($keys){
 ++$GLOBALS['empty_effects']['nested'];echo 'forbidden nested output';
 ++$GLOBALS['empty_effects']['provider'];++$GLOBALS['empty_effects']['storage'];++$GLOBALS['empty_effects']['cache'];
 add_filter('forbidden_nested_registration','paid_cohort_known');
 if($GLOBALS['empty_self_remove']){remove_filter(EMPTY_HOOK,'empty_adverse',10);}
 return array_merge($keys,['_woonuxt_deferred_payment']);
}
function empty_outer_mutation(...$args){
 ++$GLOBALS['empty_mutations'];
 $hook=$GLOBALS['empty_dynamic']?implode('_',['wl','commerce','cache','invalidation','meta','keys']):EMPTY_HOOK;
 add_filter($hook,'empty_adverse',10,1);
}
function empty_get($o,$property){return (new ReflectionProperty($o,$property))->getValue($o);}
function empty_call($l,$method,...$args){return (new ReflectionMethod($l,$method))->invoke($l,...$args);}
function empty_defaults(){return ['_price','_regular_price','_sale_price','_sale_price_dates_from','_sale_price_dates_to','_stock','_stock_status','_manage_stock','_backorders','total_sales','_wc_average_rating','_wc_rating_count','_wc_review_count','_children'];}
function empty_mu_record($hook,$field=[EMPTY_HOOK]){
 return ['hook'=>$hook,'priority'=>PHP_INT_MAX,'accepted_args'=>4,'kind'=>'method','class'=>'WL_Commerce_Cache_Invalidation','method'=>'post_meta_written','instance'=>true,'sha256'=>EMPTY_MU_SHA,'stable_registry'=>true,'nonstreaming'=>true,'requires_empty_hooks'=>$field];
}
function empty_fixture($before=null,$field=[EMPTY_HOOK],$outer=true,$all=false,$touched=false){
 [$l,$h]=cohort_fixture('valid',[],[],false);
 $GLOBALS['empty_handler']=$h;
 $GLOBALS['empty_effects']=array_fill_keys(['nested','get_post','cache','provider','storage','foreign_all'],0);
 $GLOBALS['empty_mutations']=0;$GLOBALS['empty_dynamic']=false;$GLOBALS['empty_self_remove']=false;
 $mu=(new ReflectionClass(WL_Commerce_Cache_Invalidation::class))->newInstanceWithoutConstructor();
 $invalidator=new class {
  public $collection;
  function __construct(){$this->collection=new class {function store_content(...$args){++$GLOBALS['empty_effects']['storage'];}};}
  function purge_nodes(...$args){++$GLOBALS['empty_effects']['cache'];}
  function purge(...$args){++$GLOBALS['empty_effects']['cache'];}
 };
 cohort_set($mu,'invalidator',$invalidator);if($touched){cohort_set($mu,'touched',[91=>true]);}
 $manifest=[cohort_record('graphql_woocommerce_checkout_payment_result','woonuxt_defer_stripe_checkout',10,4)];
 add_filter('graphql_woocommerce_checkout_payment_result','woonuxt_defer_stripe_checkout',10,4);
 foreach(['added_post_meta','updated_post_meta','deleted_post_meta']as $hook){
  if($outer){add_action($hook,[$mu,'post_meta_written'],PHP_INT_MAX,4);}
  $manifest[]=empty_mu_record($hook,$field);
 }
 if($all){add_filter('all','paid_cohort_known',10,1);$manifest[]=cohort_record('all','paid_cohort_known');}
 if($before){$before($l,$mu,$manifest);}
 cohort_set($l,'manifest',$manifest);$l->install();return [$l,$h,$mu];
}
function empty_expect($ok,$message='Empty-hook assertion failed.'){if(!$ok){throw new RuntimeException($message);}}
function empty_no_effects(){empty_expect(array_sum($GLOBALS['empty_effects'])===0&&$GLOBALS['paid_cohort_effect']===0&&!isset($GLOBALS['wp_filter']['forbidden_nested_registration']));}
function empty_denied($action,$h=null){
 try{$action();}catch(\WPGraphQL\WooCommerce\Utils\Cart_Session_Error $e){
  $h=$h?:$GLOBALS['empty_handler'];empty_expect($h->failed&&$e->getMessage()==='The cart session is temporarily unavailable.','Sanitized protected failure required.');empty_no_effects();return;
 }
 throw new RuntimeException('Expected pre-effect rejection.');
}
function empty_freeze($l){$l->qualify_checkout_deferred_payment();$l->assert_checkout_deferred_cohort();}
$cases=[];
$cases['pinned WP_Hook is final: subclass registry cannot be constructed']=function(){empty_expect((new ReflectionClass(WP_Hook::class))->isFinal());};
$cases['missing field retains original watch universe and qualified all']=function(){
 [$l,$h]=cohort_fixture('valid',[['all','paid_cohort_known',true]]);empty_expect(!in_array(EMPTY_HOOK,empty_call($l,'cohort_hooks'),true));
 $l->qualify_checkout_deferred_payment();$l->assert_checkout_deferred_cohort();empty_expect(!$h->failed);
};
foreach(['absent','empty']as $state){
 $cases['canonical '.$state.' baseline survives nested/repeated freeze/terminal']=function()use($state){
  [$l,$h]=empty_fixture($state==='empty'?function(){$GLOBALS['wp_filter'][EMPTY_HOOK]=new WP_Hook();}:null);
  $object=$GLOBALS['wp_filter'][EMPTY_HOOK]??null;$baseline=empty_get($l,'empty_hook_baseline');
  empty_expect(!has_filter(EMPTY_HOOK)&&in_array(EMPTY_HOOK,empty_call($l,'cohort_hooks'),true));
  foreach([false,true,true]as $freeze){empty_call($l,'cohort',$freeze);empty_expect(apply_filters(EMPTY_HOOK,empty_defaults())===empty_defaults());}
  empty_freeze($l);cohort_set($l,'terminal',true);empty_expect(apply_filters(EMPTY_HOOK,empty_defaults())===empty_defaults());
  empty_expect(empty_get($l,'empty_hook_baseline')===$baseline&&($GLOBALS['wp_filter'][EMPTY_HOOK]??null)===$object&&!has_filter(EMPTY_HOOK));empty_no_effects();
 };
}
$malformed=[null,false,[],new stdClass()];
foreach($malformed as $i=>$value){$cases['present malformed nested registry '.$i]=function()use($value){empty_denied(fn()=>empty_fixture(function()use($value){$GLOBALS['wp_filter'][EMPTY_HOOK]=$value;}));};}
foreach([[],[10=>[]],[PHP_INT_MIN=>[]]]as $i=>$callbacks){if($callbacks===[]){continue;}$cases['noncanonical empty buckets '.$i]=function()use($callbacks){empty_denied(fn()=>empty_fixture(function()use($callbacks){$r=new WP_Hook();$r->callbacks=$callbacks;$GLOBALS['wp_filter'][EMPTY_HOOK]=$r;}));};}
foreach([null,false,'hook',[],[1=>EMPTY_HOOK],[EMPTY_HOOK,EMPTY_HOOK],[''],[1],["bad\0hook"],['all'],[new stdClass()]]as $i=>$field){$cases['malformed declaration '.$i]=function()use($field){empty_denied(fn()=>empty_fixture(null,$field));};}
$cases['literal names retain exact bytes and deterministic union']=function(){
 [$l,$h]=empty_fixture(function($l,$mu,&$manifest){$manifest[1]['requires_empty_hooks']=[' hook* ',EMPTY_HOOK,'0'];$manifest[2]['requires_empty_hooks']=[EMPTY_HOOK,'another'];});
 empty_expect(empty_get($l,'empty_hooks')===[' hook* ',EMPTY_HOOK,'0','another']);empty_freeze($l);empty_no_effects();
};
foreach([PHP_INT_MIN,10,PHP_INT_MAX]as $priority){foreach([false,true]as $described){foreach([false,true]as $outer){
 $cases['preinstalled nested '.$priority.' described'.(int)$described.' outer'.(int)$outer]=function()use($priority,$described,$outer){
  empty_denied(fn()=>empty_fixture(function($l,$mu,&$manifest)use($priority,$described){add_filter(EMPTY_HOOK,'empty_adverse',$priority,1);if($described){$manifest[]=cohort_record(EMPTY_HOOK,'empty_adverse',$priority);}},[EMPTY_HOOK],$outer));
 };
}}}
foreach(['absent','empty']as $state){foreach([false,true]as $frozen){foreach(['create','remove','replace','callback']as $drift){
 if(($state==='absent'&&in_array($drift,['remove','replace'],true))||($state==='empty'&&$drift==='create')){continue;}
 foreach(['metadata','nested','deferred','freeze']as $fence){
  $cases[$state.' drift '.$drift.' frozen'.(int)$frozen.' '.$fence]=function()use($state,$frozen,$drift,$fence){
   [$l,$h]=empty_fixture($state==='empty'?function(){$GLOBALS['wp_filter'][EMPTY_HOOK]=new WP_Hook();}:null);$baseline=empty_get($l,'empty_hook_baseline');if($frozen){empty_freeze($l);}
   if($drift==='remove'){unset($GLOBALS['wp_filter'][EMPTY_HOOK]);}elseif($drift==='callback'){add_filter(EMPTY_HOOK,'empty_adverse',10,1);}else{$GLOBALS['wp_filter'][EMPTY_HOOK]=new WP_Hook();}
   empty_denied(function()use($l,$fence){if($fence==='metadata'){do_action('added_post_meta',1,91,'_woonuxt_deferred_payment','value');}elseif($fence==='nested'){apply_filters(EMPTY_HOOK,empty_defaults());}elseif($fence==='freeze'){$l->qualify_checkout_deferred_payment();}else{$l->assert_checkout_deferred_cohort();}},$h);
   empty_expect(empty_get($l,'empty_hook_baseline')===$baseline);
  };
 }
}}}
foreach(['added_post_meta','updated_post_meta','deleted_post_meta']as $hook){foreach([false,true]as $dynamic){foreach([false,true]as $frozen){
 $cases['active '.$hook.' dynamic'.(int)$dynamic.' frozen'.(int)$frozen.' nested self-removal never entered']=function()use($hook,$dynamic,$frozen){
  [$l,$h]=empty_fixture(function($l,$mu,&$manifest)use($hook){add_action($hook,'empty_outer_mutation',10,4);$manifest[]=cohort_record($hook,'empty_outer_mutation',10,4);});
  if($frozen){empty_freeze($l);}$GLOBALS['empty_dynamic']=$dynamic;$GLOBALS['empty_self_remove']=true;
  empty_denied(fn()=>do_action($hook,1,91,'_woonuxt_deferred_payment','value'),$h);empty_expect($GLOBALS['empty_mutations']===1&&has_filter(EMPTY_HOOK,'empty_adverse')===10);
 };
}}}
foreach([PHP_INT_MIN,10,PHP_INT_MAX]as $priority){
 $cases['preinstalled qualified foreign all refused '.$priority]=function()use($priority){empty_denied(fn()=>empty_fixture(function($l,$mu,&$manifest)use($priority){add_filter('all','empty_foreign_all',$priority,1);$manifest[]=cohort_record('all','empty_foreign_all',$priority);}));};
 foreach([false,true]as $freeze){$cases['late foreign all '.$priority.' frozen'.(int)$freeze]=function()use($priority,$freeze){[$l,$h]=empty_fixture();if($freeze){empty_freeze($l);}add_filter('all','empty_foreign_all',$priority,1);empty_denied(fn()=>apply_filters(EMPTY_HOOK,empty_defaults()),$h);};}
}
foreach(['missing','arity','priority','replacement','malformed-entry','duplicate','reordered']as $drift){foreach([false,true]as $freeze){
 $cases['owned all '.$drift.' frozen'.(int)$freeze]=function()use($drift,$freeze){
  [$l,$h]=empty_fixture();if($freeze){empty_freeze($l);}$r=$GLOBALS['wp_filter']['all'];$cb=[$l,'guard_sanitizer_dispatch'];
  if($drift==='missing'){unset($GLOBALS['wp_filter']['all']);}elseif($drift==='replacement'){$GLOBALS['wp_filter']['all']=clone $r;}
  elseif($drift==='arity'){add_filter('all',$cb,PHP_INT_MIN,2);}elseif($drift==='priority'){remove_filter('all',$cb,PHP_INT_MIN);add_filter('all',$cb,10,1);}
  elseif($drift==='malformed-entry'){$id=array_key_first($r->callbacks[PHP_INT_MIN]);$r->callbacks[PHP_INT_MIN][$id]['extra']=true;}
  elseif($drift==='duplicate'){$r->callbacks[PHP_INT_MIN]['duplicate']=reset($r->callbacks[PHP_INT_MIN]);}
  else{$r->callbacks[10]=[];$r->callbacks=array_reverse($r->callbacks,true);}
  // Missing all cannot observe a native dispatch; the next explicit cohort is the required fence.
  empty_denied(fn()=>$l->assert_checkout_deferred_cohort(),$h);
 };
}}
foreach(['hash','class','method','instance','priority','arity','receiver']as $variant){
 $cases['MU descriptor '.$variant.' retains existing refusal']=function()use($variant){
  if($variant==='receiver'){[$l,$h,$mu]=empty_fixture();empty_freeze($l);remove_action('added_post_meta',[$mu,'post_meta_written'],PHP_INT_MAX);$other=(new ReflectionClass(WL_Commerce_Cache_Invalidation::class))->newInstanceWithoutConstructor();add_action('added_post_meta',[$other,'post_meta_written'],PHP_INT_MAX,4);empty_denied(fn()=>do_action('added_post_meta',1,91,'_woonuxt_deferred_payment','value'),$h);return;}
  empty_denied(fn()=>empty_fixture(function($l,$mu,&$manifest)use($variant){$field=['hash'=>'sha256','class'=>'class','method'=>'method','instance'=>'instance','priority'=>'priority','arity'=>'accepted_args'][$variant];$value=['hash'=>str_repeat('0',64),'class'=>'stdClass','method'=>'post_updated','instance'=>false,'priority'=>10,'arity'=>3][$variant];$manifest[1][$field]=$value;}));
 };
}
foreach(['absent','empty']as $state){foreach([false,true]as $touched){foreach(['added_post_meta','updated_post_meta','deleted_post_meta']as $hook){foreach(['_wl_checkout_operation_uuid','_woonuxt_deferred_payment','_stripe_source_id','_stripe_intent_id']as $key){
 $cases['exact MU '.$hook.' '.$key.' touched'.(int)$touched.' '.$state]=function()use($hook,$key,$touched,$state){
  [$l,$h,$mu]=empty_fixture($state==='empty'?function(){$GLOBALS['wp_filter'][EMPTY_HOOK]=new WP_Hook();}:null,[EMPTY_HOOK],true,false,$touched);empty_freeze($l);
  $mu_state=[];foreach(['touched','lists_dirty','old_parents','has_product_connection','dependencies']as $p){$mu_state[$p]=empty_get($mu,$p);}
  $registries=$GLOBALS['wp_filter'];$entries=[];foreach($registries as $name=>$registry){$entries[$name]=$registry->callbacks;}
  empty_expect(array_key_exists(EMPTY_HOOK,$GLOBALS['wp_filter'])===($state==='empty')&&!has_filter(EMPTY_HOOK));
  $before=ob_get_contents();do_action($hook,1,91,$key,'value');empty_expect(ob_get_contents()===$before);
  empty_expect(apply_filters(EMPTY_HOOK,empty_defaults())===empty_defaults()&&!$h->failed);
  foreach($mu_state as $p=>$value){empty_expect(empty_get($mu,$p)===$value);}
  empty_expect($GLOBALS['wp_filter']===$registries);foreach($entries as $name=>$callbacks){empty_expect($GLOBALS['wp_filter'][$name]->callbacks===$callbacks);}
  empty_no_effects();$l->assert_checkout_deferred_cohort();
 };
}}}}
$cases['mode preserves unused unrelated sanitizer replacement and native footnotes init']=function(){
 [$l,$h]=empty_fixture(function(){add_filter('sanitize_post_meta_unrelated','paid_cohort_unknown');add_filter('sanitize_post_meta_footnotes','_wp_filter_post_meta_footnotes');});empty_freeze($l);
 remove_filter('sanitize_post_meta_unrelated','paid_cohort_unknown');add_filter('sanitize_post_meta_unrelated','paid_cohort_unknown');_wp_footnotes_kses_init();$l->assert_checkout_deferred_cohort();
 empty_expect(sanitize_meta('_woonuxt_deferred_payment','raw','post','shop_order')==='raw'&&!$h->failed);empty_no_effects();
};
foreach(['_woonuxt_deferred_payment','_wl_checkout_operation_uuid']as $key){foreach(['generic','subtype','dynamic']as $shape){
 $cases['mode native sanitizer '.$shape.' '.$key]=function()use($key,$shape){
  $generic='sanitize_post_meta_'.$key;$subtype=$generic.'_for_'.($shape==='dynamic'?'custom_order':'shop_order');
  [$l,$h]=empty_fixture(function($l,$mu,&$manifest)use($generic,$subtype,$shape){add_filter($generic,'paid_cohort_generic');$manifest[]=cohort_record($generic,'paid_cohort_generic');if($shape!=='generic'){add_filter($subtype,'paid_cohort_subtype');$manifest[]=cohort_record($subtype,'paid_cohort_subtype');}});empty_freeze($l);
  empty_expect(sanitize_meta($key,'raw','post',$shape==='dynamic'?'custom_order':'shop_order')===($shape==='generic'?'generic:raw':'subtype:raw'));
  empty_expect($GLOBALS['paid_cohort_calls']===[($shape==='generic'?'generic':'subtype')]&&!$h->failed);empty_no_effects();
 };
}}
$cases['mode dynamic order getter drift still refuses at deferred fence']=function(){[$l,$h]=empty_fixture();empty_freeze($l);add_filter('woocommerce_order_get_dynamic_contract','paid_cohort_unknown');empty_denied(fn()=>$l->assert_checkout_deferred_cohort(),$h);};
foreach(['replacement','arity','priority','duplicate']as $drift){$cases['native nested all owner '.$drift.' refuses before effects']=function()use($drift){
 [$l,$h]=empty_fixture();$cb=[$l,'guard_sanitizer_dispatch'];$r=$GLOBALS['wp_filter']['all'];
 if($drift==='replacement'){$GLOBALS['wp_filter']['all']=clone $r;}elseif($drift==='arity'){add_filter('all',$cb,PHP_INT_MIN,2);}elseif($drift==='priority'){remove_filter('all',$cb,PHP_INT_MIN);add_filter('all',$cb,10,1);}else{$r->callbacks[PHP_INT_MIN]['duplicate']=reset($r->callbacks[PHP_INT_MIN]);}
 empty_denied(fn()=>apply_filters(EMPTY_HOOK,empty_defaults()),$h);
};}
$failed=[];$receipts=[];
foreach($cases as $name=>$test){$level=ob_get_level();try{$test();$receipts[]=['case'=>$name,'passed'=>true];}catch(Throwable $e){$failed[]=$name;$receipts[]=['case'=>$name,'passed'=>false,'error'=>get_class($e).' '.$e->getMessage()];fwrite(STDERR,'FAIL '.$name.': '.get_class($e).' '.$e->getMessage().' '.$e->getFile().':'.$e->getLine()."\n");}finally{if(isset($GLOBALS['paid_cohort_lifecycle'])){empty_get($GLOBALS['paid_cohort_lifecycle'],'boundary')->restore();unset($GLOBALS['paid_cohort_lifecycle']);}while(ob_get_level()>$level){ob_end_clean();}}}
echo json_encode(['suite'=>'empty-hook-callback-cohort','cases'=>count($cases),'passed'=>count($cases)-count($failed),'failed'=>$failed,'receipts'=>$receipts,'php'=>PHP_VERSION,'pins'=>['MU'=>EMPTY_MU_SHA,'plugin.php'=>hash_file('sha256',$wp.'/wp-includes/plugin.php'),'WP_Hook'=>hash_file('sha256',(new ReflectionClass(WP_Hook::class))->getFileName())],'limits'=>'Source component evidence only. Actual plugin.php/WP_Hook/lifecycle/MU; handler/customer/order/source classes, get_post/cache/provider/storage substituted. Native current_filter is not instrumented; pinned MU default-key early return plus zero get_post/cache and unchanged touched state cover its unreachable touch branch. Same-request split/duplicate receivers are separate profile assertions, not generic cohort API. No bootstrap/HTTP/SQL/installed-runtime/native journey acceptance.'],JSON_THROW_ON_ERROR)."\n";exit($failed?1:0);

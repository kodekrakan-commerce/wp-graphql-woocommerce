<?php
/** Actual retained lifecycle + native WP_Hook/plugin.php status cohort guards.
 * Native order/source classes and ownership remain explicit qualification seams;
 * actual constructor/CPT behavior is tested by native-pending-contract.php. */
const WL_PAID_COHORT_BOOTSTRAP_ONLY = true;
require __DIR__ . '/cart-session-paid-cohort-contract.php';
const DRAFT_STATUS_SOURCE = '2d8b83747317a7f51011cb80ef597f3b8d89cc2030c5691767aad5ac3ad78b3e';
$draft_source=rtrim(getenv('WL_WOOCOMMERCE_SOURCE')?:'','/').'/src/Blocks/Domain/Services/DraftOrders.php';
if(!is_file($draft_source)||!hash_equals(DRAFT_STATUS_SOURCE,hash_file('sha256',$draft_source))){fwrite(STDERR,"Pinned native DraftOrders source required.\n");exit(2);}
require $draft_source;
function _x($s,...$args){return $s;}
class PendingDraftSubclass extends Automattic\WooCommerce\Blocks\Domain\Services\DraftOrders {}
class PendingOtherDraft {function register_draft_order_status($statuses){++$GLOBALS['paid_cohort_effect'];return $statuses;}}
class WC_Order {} class WC_Abstract_Order {} class WC_Data_Store {}
class WC_Order_Data_Store_CPT {} class Abstract_WC_Order_Data_Store_CPT {}
function wc_get_order_statuses(){}
function pending_cohort_fixture($hook=null,$qualified=true){
 [$l,$h]=cohort_fixture('valid',$hook ? [[$hook,'paid_cohort_unknown',$qualified]] : []);
 $sources=(new ReflectionProperty($l,'sources'))->getValue($l);
 foreach(['WC_Order','WC_Abstract_Order','WC_Data_Store','WC_Order_Data_Store_CPT','Abstract_WC_Order_Data_Store_CPT'] as $class){$sources[$class]=hash_file('sha256',(new ReflectionClass($class))->getFileName());}
 $sources['function:wc_get_order_statuses']=hash_file('sha256',(new ReflectionFunction('wc_get_order_statuses'))->getFileName());
 cohort_set($l,'sources',$sources);return [$l,$h];
}
function pending_draft_record($callback,$hook='wc_order_statuses',$priority=10,$arity=1){
 return ['hook'=>$hook,'kind'=>'method','class'=>get_class($callback[0]),'method'=>$callback[1],'instance'=>true,'priority'=>$priority,'accepted_args'=>$arity,'sha256'=>hash_file('sha256',(new ReflectionMethod($callback[0],$callback[1]))->getFileName()),'stable_registry'=>true,'nonstreaming'=>true];
}
function pending_draft_fixture($variant='valid'){
 [$l,$h]=pending_cohort_fixture();$class=Automattic\WooCommerce\Blocks\Domain\Services\DraftOrders::class;
 if($variant==='subclass'){$class=PendingDraftSubclass::class;}elseif($variant==='wrong-class'){$class=PendingOtherDraft::class;}
 $receiver=(new ReflectionClass($class))->newInstanceWithoutConstructor();
 $callback=[$receiver,$variant==='wrong-method'?'register_draft_order_post_status':'register_draft_order_status'];
 $hook=$variant==='wrong-hook'?'woocommerce_default_order_status':'wc_order_statuses';$priority=$variant==='wrong-priority'?11:10;$arity=$variant==='wrong-arity'?2:1;
 add_filter($hook,$callback,$priority,$arity);
 $manifest=(new ReflectionProperty($l,'manifest'))->getValue($l);$record=pending_draft_record($callback,$hook,$priority,$arity);
 if($variant==='wrong-hash'){$record['sha256']=str_repeat('0',64);}
 if($variant!=='missing-manifest'){$manifest[]=$record;}
 if($variant==='duplicate'){$second=(new ReflectionClass($class))->newInstanceWithoutConstructor();$cb=[$second,'register_draft_order_status'];add_filter('wc_order_statuses',$cb,10,1);$manifest[]=pending_draft_record($cb);}
 cohort_set($l,'manifest',$manifest);return [$l,$h,$callback];
}
$cases=[];
$cases['native owned status guards allow empty non-owner callbacks and freeze all three']=function(){
 [$l,$h]=pending_cohort_fixture();$l->qualify_checkout_pending_status();
 foreach(['woocommerce_default_order_status','wc_order_statuses','woocommerce_order_get_status'] as $hook){cohort_expect(apply_filters($hook,'pending')==='pending');}
 $l->assert_checkout_pending_status_cohort();cohort_expect(!$h->failed && $GLOBALS['paid_cohort_effect']===0);
};
$cases['exact pinned native DraftOrders callback appends only draft preserving canonical pending']=function(){
 [$l,$h,$callback]=pending_draft_fixture();$l->qualify_checkout_pending_status();
 $before=['wc-pending'=>'Pending','wc-processing'=>'Processing','wc-on-hold'=>'On hold'];$after=apply_filters('wc_order_statuses',$before);
 cohort_expect($after===$before+['wc-checkout-draft'=>'Draft'] && $before===array_diff_key($after,['wc-checkout-draft'=>true]));
 cohort_expect((new ReflectionMethod($callback[0],$callback[1]))->getDeclaringClass()->getName()===get_class($callback[0]));
 $l->assert_checkout_pending_status_cohort();cohort_expect(!$h->failed && $GLOBALS['paid_cohort_effect']===0);
};
foreach(['missing-manifest','wrong-class','subclass','wrong-method','wrong-hash','wrong-hook','wrong-priority','wrong-arity','duplicate'] as $variant){$cases['native DraftOrders admission rejects '.$variant]=function()use($variant){
 [$l,$h]=pending_draft_fixture($variant);cohort_denied(fn()=>$l->qualify_checkout_pending_status(),$h);
};}
foreach(['receiver','registry','priority','arity','descriptor-hash'] as $drift){$cases['native DraftOrders frozen identity rejects '.$drift]=function()use($drift){
 [$l,$h,$callback]=pending_draft_fixture();$l->qualify_checkout_pending_status();
 if($drift==='registry'){$GLOBALS['wp_filter']['wc_order_statuses']=clone $GLOBALS['wp_filter']['wc_order_statuses'];}
 elseif($drift==='descriptor-hash'){$manifest=(new ReflectionProperty($l,'manifest'))->getValue($l);$manifest[array_key_last($manifest)]['sha256']=str_repeat('0',64);cohort_set($l,'manifest',$manifest);}
 else{remove_filter('wc_order_statuses',$callback,10);$replacement=$drift==='receiver'?[(new ReflectionClass(get_class($callback[0])))->newInstanceWithoutConstructor(),$callback[1]]:$callback;add_filter('wc_order_statuses',$replacement,$drift==='priority'?11:10,$drift==='arity'?2:1);}
 cohort_denied(fn()=>$l->assert_checkout_pending_status_cohort(),$h);
};}
foreach(['woocommerce_default_order_status','wc_order_statuses','woocommerce_order_get_status'] as $hook){
 $cases['even explicitly reviewed status callback outside pending boundary '.$hook]=function()use($hook){
  [$l,$h]=pending_cohort_fixture($hook);cohort_denied(fn()=>$l->qualify_checkout_pending_status(),$h);
 };
 foreach(['unknown','reviewed','registry-replacement','owner-arity'] as $drift){$cases['frozen pending cohort rejects '.$drift.' before callback '.$hook]=function()use($hook,$drift){
  [$l,$h]=pending_cohort_fixture();$l->qualify_checkout_pending_status();
  if($drift==='registry-replacement'){$GLOBALS['wp_filter'][$hook]=clone $GLOBALS['wp_filter'][$hook];}
  elseif($drift==='owner-arity'){add_filter($hook,[$l,'guard_cohort_entry'],PHP_INT_MIN,2);}
  else{add_filter($hook,'paid_cohort_unknown',10,1);if($drift==='reviewed'){ $manifest=(new ReflectionProperty($l,'manifest'))->getValue($l);$manifest[]=cohort_record($hook,'paid_cohort_unknown');cohort_set($l,'manifest',$manifest);}}
  cohort_denied(fn()=>$l->assert_checkout_pending_status_cohort(),$h);
 };}
}
foreach(['WC_Order','WC_Abstract_Order','WC_Data','WC_Data_Store','WC_Order_Data_Store_CPT','Abstract_WC_Order_Data_Store_CPT','function:wc_get_order_statuses'] as $source){$cases['pending source pin required '.$source]=function()use($source){
 [$l,$h]=pending_cohort_fixture();$sources=(new ReflectionProperty($l,'sources'))->getValue($l);$sources[$source]=str_repeat('0',64);cohort_set($l,'sources',$sources);
 cohort_denied(fn()=>$l->qualify_checkout_pending_status(),$h);
};}
$failed=[];foreach($cases as $name=>$test){$level=ob_get_level();try{$test();}catch(Throwable $e){$failed[]=$name;fwrite(STDERR,'FAIL '.$name.': '.get_class($e).' '.$e->getMessage()."\n");}finally{if(isset($GLOBALS['paid_cohort_lifecycle'])){(new ReflectionProperty($GLOBALS['paid_cohort_lifecycle'],'boundary'))->getValue($GLOBALS['paid_cohort_lifecycle'])->restore();unset($GLOBALS['paid_cohort_lifecycle']);}while(ob_get_level()>$level){ob_end_clean();}}}
echo json_encode(['suite'=>'native-pending-callback-cohort','cases'=>count($cases),'passed'=>count($cases)-count($failed),'failed'=>$failed,'php'=>PHP_VERSION,'draft_source'=>DRAFT_STATUS_SOURCE,'limits'=>'Actual lifecycle, native WP_Hook/plugin.php and pinned DraftOrders append callback only. Receiver constructed without package initialization; other class/function pins, ownership and translation are synthetic seams. No native order, SQL, site, HTTP, scheduler or provider acceptance.'],JSON_THROW_ON_ERROR)."\n";exit($failed?1:0);

<?php
/** Native WC_Order/WC_Data/WC_Meta_Data save/read components and actual Settings
 * deferral through retained protected helper. Hook registry, datastore, SQL,
 * adoption and source/cohort handshake are explicit substitutes; no site boot,
 * account/HTTP/payment acceptance. */
const PAID_NATIVE_PINS = [
 'includes/abstracts/abstract-wc-data.php'=>'a62ab1ea96c7aee3413110d0e5895abe27e9031ae6eca16940635ee60d5ec4d7',
 'includes/class-wc-meta-data.php'=>'185aa239222c773932e5f1f23b71079d1157d682764a3fecdf77060b7d8321e8',
 'includes/abstracts/abstract-wc-order.php'=>'59a07e58b30a198491977da76cbff3c01f497fc09f5fdac818c6806fdc3b2ff6',
 'includes/class-wc-order.php'=>'b00e1aef43fca2f4c173c59ceaaac2aa1954cf666836e4fc3deed837434a7d41',
];
$native_source=rtrim(getenv('WL_WOOCOMMERCE_SOURCE')?:'','/');
foreach(PAID_NATIVE_PINS as $path=>$hash){if(!is_file($native_source.'/'.$path)||!hash_equals($hash,hash_file('sha256',$native_source.'/'.$path))){fwrite(STDERR,"Reviewed native paid source differs.\n");exit(2);}}
const WL_NATIVE_ORDER_BOOTSTRAP_ONLY = true;
require __DIR__ . '/cart-session-native-order-save-contract.php';
require dirname(__DIR__, 2) . '/includes/data/mutation/class-checkout-mutation.php';
$settings = rtrim(getenv('WL_SETTINGS_SOURCE') ?: '', '/');
if (!is_file($settings.'/includes/deferred-checkout.php')) { fwrite(STDERR, "Qualified Settings source required.\n"); exit(2); }
require $settings.'/includes/deferred-checkout.php';
function wc_get_price_decimals(){return 2;} function get_woocommerce_currencies(){return ['EUR'=>'Euro','USD'=>'Dollar'];}
final class DeferredCohortSeam {
 public $effect; public bool $terminal=false;
 function is_terminal(){return false;}
 function qualify_checkout_deferred_payment(){if($this->effect){($this->effect)('qualify');}}
 function assert_checkout_deferred_cohort(){if($this->effect){($this->effect)('cohort');}}
}
final class DeferredMetadataStore {
 function get_internal_meta_keys(){return [];}
 public array $rows=[]; public int $writes=0; public int $reads=0; public bool $persist=true; public $on_read; public $on_add;
 function update(&$order){++$this->writes;$order->save_meta_data();}
 function add_meta(&$order,$meta){ if($this->on_add){($this->on_add)($order);} $id=count($this->rows)+1; if($this->persist){$this->rows[]=(object)['meta_id'=>$id,'meta_key'=>$meta->key,'meta_value'=>$meta->value];}return $id; }
 function update_meta(&$order,$meta){foreach($this->rows as $r){if($r->meta_id===$meta->id){$r->meta_value=$meta->value;}}}
 function delete_meta(&$order,$meta){}
 function read_meta(&$order){++$this->reads;if($this->on_read){($this->on_read)($order,$this);}return $this->rows;}
 function get_order_item_type(...$args){return 'line_item';} function read_items(...$args){return [];}
 function get_payment_token_ids(...$args){return [];}
}
function deferred_fixture($first_save_failure=false){
 [$h,$db]=owned_start(17);$o=(new ReflectionClass(WC_Order::class))->newInstanceWithoutConstructor();
 $o->set_id(91); $o->set_status('pending');$o->set_object_read(true);$o->set_customer_id(17);$o->set_payment_method('stripe');$o->set_total('10.00');$o->set_currency('EUR');
 save_property($o,'meta_data',[]);$o->add_meta_data('_wl_checkout_operation_uuid','11111111-1111-4111-8111-111111111111',true);
 $store=new DeferredMetadataStore();save_property($o,'data_store',$store);
 save_property($o,'items',['line_items'=>[],'tax_lines'=>[],'shipping_lines'=>[],'fee_lines'=>[],'coupon_lines'=>[]]);
 $a=(object)['uuid'=>'11111111-1111-4111-8111-111111111111','adopted'=>true,'protected'=>true,'order'=>$o,'store'=>$store,'saved'=>false,'save_active'=>false,'save_failed'=>false,'save_started'=>0,'save_completed'=>0,'payment_started'=>false,'phase'=>'creation','outcome'=>null,'deferred_checked'=>false,'success'=>false];
 save_property($h,'checkout_attempt',$a);$cohort=new DeferredCohortSeam();save_property($h,'owned_lifecycle',$cohort);
 [$key,$marker]=owned_order_marker('pending',91); HandlerContractBoundary::$markers[$key]=$marker;
 WC()->cart=new class {function is_empty(){return true;}};
 $gateway=new class {public int $calls=0;function process_payment(...$args){++$this->calls;return ['result'=>'success','redirect'=>'bad'];}};
 WC()->payment_gateways=new class($gateway){function __construct(public $gateway){}function get_available_payment_gateways(){return ['stripe'=>$this->gateway];}};
 add_action('woocommerce_before_order_object_save',[$h,'checkout_order_saving'],PHP_INT_MIN,2);
 add_action('woocommerce_after_order_object_save',[$h,'checkout_order_saved'],PHP_INT_MAX,2);
 if($first_save_failure){add_action('woocommerce_before_order_object_save',fn()=>throw new Exception('Controlled first save failure.'),10,1);}
 owned_expect(91===$o->save());
 if($first_save_failure){owned_expect(!$a->saved&&$a->save_active&&0===$store->writes);return [$h,$o,$store,$a,$key,$marker,$gateway,$cohort];}
 owned_expect($a->saved&&1===$store->writes);
 $h->verify_checkout_order_return(91,$o);add_filter('graphql_woocommerce_checkout_payment_result','woonuxt_defer_stripe_checkout',10,4);
 return [$h,$o,$store,$a,$key,$marker,$gateway,$cohort];
}
function deferred_process($o){return (new ReflectionMethod(\WPGraphQL\WooCommerce\Data\Mutation\Checkout_Mutation::class,'process_order_payment'))->invoke(null,91,'stripe',$o);}
function deferred_failed($h,$a,$key,$marker,$gateway){owned_expect(!$a->success&&!$a->deferred_checked&&0===$gateway->calls&&HandlerContractBoundary::$markers[$key]===$marker);owned_error(fn()=>$h->assert_response_available());}
$cases=[];
$cases['swallowed initial native save cannot reuse assigned positive ID']=function(){
 [$h,$o,$s,$a,$key,$marker,$g]=deferred_fixture(true);
 owned_error(fn()=>deferred_process($o));deferred_failed($h,$a,$key,$marker,$g);
};
$cases['ordinary three-argument filter path retains its normal gateway fallback']=function(){
 [$h,$o,$s,$a,$key,$marker,$g]=deferred_fixture();remove_action('graphql_woocommerce_checkout_payment_result','woonuxt_defer_stripe_checkout',10);
 $seen=0;add_filter('graphql_woocommerce_checkout_payment_result',function($result,$id,$method)use(&$seen){$seen=func_num_args();return null;},10,3);
 $result=(new ReflectionMethod(\WPGraphQL\WooCommerce\Data\Mutation\Checkout_Mutation::class,'process_order_payment'))->invoke(null,91,'stripe');
 owned_expect(3===$seen&&1===$g->calls&&'success'===$result['result']&&!$a->payment_started);
};
$cases['actual native metadata save and forced read distinguish preparation from payment']=function(){
 [$h,$o,$s,$a,$key,$marker,$gateway]=deferred_fixture();$result=deferred_process($o);
 owned_expect(['result'=>'pending','redirect'=>'']===$result&&$a->deferred_checked&&1===$s->reads&&1===$s->writes&&1===$a->save_started&&1===$a->save_completed);
 $h->checkout_deferred_order_succeeded($o);owned_expect($a->success&&!$o->is_paid()&&'pending'===$o->get_status()&&0===$gateway->calls&&HandlerContractBoundary::$markers[$key]===$marker);
 owned_error(fn()=>$h->begin_checkout_deferred_payment($o));
};
foreach([null,[],['result'=>'success','redirect'=>''],['result'=>'pending','redirect'=>'x'],['redirect'=>'','result'=>'pending'],['result'=>'pending','redirect'=>'','extra'=>true],false] as $i=>$result){
 $cases['protected bad result '.$i.' never falls back']=function()use($result){[$h,$o,$s,$a,$key,$marker,$g]=deferred_fixture();add_filter('graphql_woocommerce_checkout_payment_result',fn()=>$result,20,4);owned_error(fn()=>deferred_process($o));deferred_failed($h,$a,$key,$marker,$g);};
}
$cases['void native metadata save and in-memory yes without persistence fail forced read']=function(){[$h,$o,$s,$a,$key,$marker,$g]=deferred_fixture();$s->persist=false;owned_error(fn()=>deferred_process($o));deferred_failed($h,$a,$key,$marker,$g);owned_expect(1===$s->reads);};
foreach(['missing-uuid','duplicate-deferred','nonpositive-id','wrong-deferred','uuid','total','currency','customer','provider','transaction','paid','status','store'] as $drift){
 $cases['forced-read drift '.$drift.' preserves pending fence and withholds credentials']=function()use($drift){
  [$h,$o,$s,$a,$key,$marker,$g]=deferred_fixture();$s->on_read=function($o,$s)use($drift){
   if($drift==='missing-uuid'){$s->rows=array_values(array_filter($s->rows,fn($r)=>$r->meta_key!=='_wl_checkout_operation_uuid'));}
   elseif($drift==='duplicate-deferred'){$s->rows[]=(object)['meta_id'=>99,'meta_key'=>'_woonuxt_deferred_payment','meta_value'=>'yes'];}
   elseif($drift==='nonpositive-id'){$s->rows[1]->meta_id=0;}
   elseif($drift==='wrong-deferred'){$s->rows[1]->meta_value='no';}
   elseif($drift==='uuid'){$s->rows[0]->meta_value='changed';}
   elseif($drift==='total'){$o->set_total('11.00');}elseif($drift==='currency'){$o->set_currency('USD');}elseif($drift==='customer'){$o->set_customer_id(18);}
   elseif($drift==='provider'){$s->rows[]=(object)['meta_id'=>99,'meta_key'=>'_stripe_intent_id','meta_value'=>'pi_changed'];}
   elseif($drift==='transaction'){$o->set_transaction_id('changed');}elseif($drift==='paid'){$o->set_date_paid(time());}elseif($drift==='status'){$o->set_status('failed');}elseif($drift==='store'){save_property($o,'data_store',new DeferredMetadataStore());}
  };owned_error(fn()=>deferred_process($o));deferred_failed($h,$a,$key,$marker,$g);
 };
}
foreach(['deferred','readback'] as $phase){foreach(['captured','substitute','caught-reentrant'] as $kind){
 $cases[$phase.' '.$kind.' native swallowed full-save denial is sticky']=function()use($phase,$kind){
  [$h,$o,$s,$a,$key,$marker,$g]=deferred_fixture();$effect=function($o)use($kind,$h,$s){$target=$kind==='substitute'?clone $o:$o;if($kind==='caught-reentrant'){try{$h->checkout_order_saving($target,$s);}catch(Throwable $e){}}else{$target->save();}};
  if($phase==='readback'){$s->on_read=$effect;}else{$s->on_add=$effect;}
  owned_error(fn()=>deferred_process($o));deferred_failed($h,$a,$key,$marker,$g);owned_expect($a->save_failed&&1===$s->writes);
 };
}}
foreach(['swallowed-before-save','reentrant-save','wrong-store'] as $failure){
 $cases['checkout metadata explicit fresh full-save '.$failure]=function()use($failure){
  [$h,$o,$s,$a,$key,$marker,$g]=deferred_fixture();
  add_action('woocommerce_before_order_object_save',function($o)use($failure,$h,$s){if($failure==='swallowed-before-save'){throw new Exception('Controlled save failure.');}if($failure==='reentrant-save'){$o->save();}else{$h->checkout_order_saving($o,new DeferredMetadataStore());}},10,1);
  owned_error(fn()=>\WPGraphQL\WooCommerce\Data\Mutation\Checkout_Mutation::update_order_meta(91,[['key'=>'order_via','value'=>'web']],[],null,null,$o));deferred_failed($h,$a,$key,$marker,$g);
 };
}
$cases['checkout metadata captured receiver gets its own completed native full save']=function(){[$h,$o,$s,$a]=deferred_fixture();\WPGraphQL\WooCommerce\Data\Mutation\Checkout_Mutation::update_order_meta(91,[['key'=>'order_via','value'=>'web']],[],null,null,$o);owned_expect(2===$a->save_started&&2===$a->save_completed&&'creation'===$a->phase);owned_expect(['result'=>'pending','redirect'=>'']===deferred_process($o));};
$cases['late full save after deferred qualification withholds success']=function(){[$h,$o,$s,$a,$key,$marker,$g]=deferred_fixture();deferred_process($o);$o->save();owned_expect($a->save_failed&&!$a->success&&1===$s->writes);owned_error(fn()=>$h->checkout_deferred_order_succeeded($o));owned_expect(HandlerContractBoundary::$markers[$key]===$marker);};
$cases['same-ID substitute receiver cannot be supplied to protected helper']=function(){[$h,$o,$s,$a,$key,$marker,$g]=deferred_fixture();owned_error(fn()=>deferred_process(clone $o));deferred_failed($h,$a,$key,$marker,$g);};
$cases['store changed before deferral fails common native ownership proof']=function(){[$h,$o,$s,$a,$key,$marker,$g]=deferred_fixture();save_property($o,'data_store',new DeferredMetadataStore());owned_error(fn()=>deferred_process($o));deferred_failed($h,$a,$key,$marker,$g);};
$cases['after qualification hook metadata drift fails selected success revalidation']=function(){[$h,$o,$s,$a,$key,$marker,$g]=deferred_fixture();deferred_process($o);$o->update_meta_data('_wl_checkout_operation_uuid','changed');owned_error(fn()=>$h->checkout_deferred_order_succeeded($o));owned_expect(!$a->success&&HandlerContractBoundary::$markers[$key]===$marker);owned_error(fn()=>$h->assert_response_available());};
$cases['finish readback phase is one use']=function(){[$h,$o,$s,$a,$key,$marker,$g]=deferred_fixture();$r=deferred_process($o);owned_error(fn()=>$h->finish_checkout_deferred_payment($o,$r));owned_expect(1===$s->reads&&!$a->success);};
$failed=[];foreach($cases as $name=>$test){if(getenv('WL_PAID_ONLY')&& !str_contains($name,getenv('WL_PAID_ONLY'))){unset($cases[$name]);continue;}try{$test();}catch(Throwable $e){$failed[]=$name;fwrite(STDERR,'FAIL '.$name.': '.get_class($e).' '.$e->getMessage().' '.$e->getFile().':'.$e->getLine()."\n");}}
echo json_encode(['suite'=>'paid-checkout-native-components','cases'=>count($cases),'passed'=>count($cases)-count($failed),'failed'=>$failed,'php'=>PHP_VERSION,'limits'=>'Actual WC_Order/WC_Data/WC_Meta_Data and Settings writer; controlled metadata datastore, hooks, SQL/adoption and cohort seam. No native installed cohort, DB, HTTP, account or payment acceptance.'],JSON_THROW_ON_ERROR)."\n";exit($failed?1:0);

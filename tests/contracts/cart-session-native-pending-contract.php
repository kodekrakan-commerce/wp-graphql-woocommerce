<?php
/** Actual native fresh WC_Order constructor, setters, WC_Data_Store wrapper and
 * CPT create/get_post_status/apply_changes. SQL, metadata, cache and lifecycle
 * qualification are recording seams; no bootstrap, database or HTTP acceptance. */
const WL_NATIVE_PENDING_SOURCE_FUNCTIONS = true;
const WL_NATIVE_ORDER_BOOTSTRAP_ONLY = true;
require __DIR__ . '/cart-session-native-order-save-contract.php';
const PENDING_NATIVE_PINS = [
 'includes/abstracts/abstract-wc-data.php'=>'a62ab1ea96c7aee3413110d0e5895abe27e9031ae6eca16940635ee60d5ec4d7',
 'includes/abstracts/abstract-wc-order.php'=>'59a07e58b30a198491977da76cbff3c01f497fc09f5fdac818c6806fdc3b2ff6',
 'includes/class-wc-order.php'=>'b00e1aef43fca2f4c173c59ceaaac2aa1954cf666836e4fc3deed837434a7d41',
 'includes/class-wc-data-store.php'=>'e7b9c236bb0d879c5388ba7bfe0ff0afb7775c085422d33e6206481a32178f43',
 'includes/data-stores/class-wc-data-store-wp.php'=>'8b0d25372e19517bb8d7ccab613c303aef62be78c0751c1403c9448c2dbac2ca',
 'includes/data-stores/abstract-wc-order-data-store-cpt.php'=>'24ef4dc8f6234360f9a1d06b2f6b5adbd4dfdf1e12b0a987ab19fc8a2840e662',
 'includes/data-stores/class-wc-order-data-store-cpt.php'=>'1f1b0e4523c53a13c0b04be74300f76e8c79180d5d23fc123ddbaf95ed180192',
 'includes/wc-order-functions.php'=>'4b1132af35887f07a18c99520325323bd2eeca16f135b5d8cb6780f9124ad2dd',
 'includes/interfaces/class-wc-object-data-store-interface.php'=>'45d0deab9d4dfb14b51a0fff10869a2a8fb782830bd97086e4764323a4b44177',
 'includes/interfaces/class-wc-abstract-order-data-store-interface.php'=>'a9273cbb47ccc25c0314a93e72c01873ceb6d443e60290bd1aa6382b4b8c8d21',
 'includes/interfaces/class-wc-order-data-store-interface.php'=>'f4c8eb29e4fc55c799f4e6f0bbf5c7375bd8b1cf7ad337aa9b9cdf37770a0092',
 'src/Blocks/Domain/Services/DraftOrders.php'=>'2d8b83747317a7f51011cb80ef597f3b8d89cc2030c5691767aad5ac3ad78b3e',
];
foreach ( PENDING_NATIVE_PINS as $path=>$hash ) {
 if (!is_file($wc.'/'.$path) || !hash_equals($hash,hash_file('sha256',$wc.'/'.$path))) { fwrite(STDERR,"Pinned native pending source differs.\n");exit(2); }
}
foreach ( ['includes/interfaces/class-wc-object-data-store-interface.php', 'includes/interfaces/class-wc-abstract-order-data-store-interface.php', 'includes/interfaces/class-wc-order-data-store-interface.php', 'includes/class-wc-data-store.php', 'includes/data-stores/class-wc-data-store-wp.php', 'includes/data-stores/abstract-wc-order-data-store-cpt.php', 'includes/data-stores/class-wc-order-data-store-cpt.php', 'includes/wc-order-functions.php'] as $path ) { require $wc.'/'.$path; }
define('WC_VERSION','10.6.0');
function _x($s,...$args){return $s;}
function wc_get_price_decimals(){return 2;}
function get_woocommerce_currencies(){return ['EUR'=>'Euro'];}
function get_woocommerce_currency(){return 'EUR';}
function get_post_stati(){return array_keys(wc_get_order_statuses());}
function wp_insert_post($data,$error=false){$GLOBALS['pending_posts'][]=$data;return 91;}

/** Only persistence/cache methods are replaced. Inherited native create and
 * get_post_status remain untouched; wp_insert_post records their exact output. */
final class PendingCPTSink extends WC_Order_Data_Store_CPT {
 public int $creates=0; public int $updates=0; public array $rows=[];
 public function create(&$order){++$this->creates;parent::create($order);}
 public function update(&$order){++$this->updates;throw new RuntimeException('Unexpected extra full save.');}
 protected function update_post_meta(&$order){}
 protected function clear_caches(&$order){}
 public function get_internal_meta_keys(){return [];}
 public function read_meta(&$order){return $this->rows;}
 public function add_meta(&$order,$meta){$id=count($this->rows)+1;$this->rows[]=(object)['meta_id'=>$id,'meta_key'=>$meta->key,'meta_value'=>$meta->value];return $id;}
 public function update_meta(&$order,$meta){throw new RuntimeException('Unexpected metadata update.');}
 public function delete_meta(&$order,$meta){throw new RuntimeException('Unexpected metadata delete.');}
}
final class PendingQualificationSeam {
 public array $calls=[]; public $on_qualify; public $on_assert;
 public function is_terminal(){return false;}
 public function qualify_checkout_pending_status(){ $this->calls[]='qualify';if($this->on_qualify){($this->on_qualify)();} }
 public function assert_checkout_pending_status_cohort(){ $this->calls[]='assert';if($this->on_assert){($this->on_assert)();} }
 public function qualify_checkout_deferred_payment(){}
}
function pending_fixture($protected=true){
 [$h,$db]=owned_start(17);$GLOBALS['pending_posts']=[];$GLOBALS['notes']=[];$GLOBALS['pending_effects']=[];
 $sink=new PendingCPTSink();add_filter('woocommerce_order_data_store',static fn()=>$sink);
 // Genuine constructor; no direct status setter and no fabricated data property.
 $o=new WC_Order();$o->set_customer_id(17);$o->set_payment_method('stripe');$o->set_total('1.00');$o->set_currency('EUR');$o->set_order_key('synthetic-pending-key');
 save_property($o,'items',['line_items'=>[],'tax_lines'=>[],'shipping_lines'=>[],'fee_lines'=>[],'coupon_lines'=>[]]);
 $a=(object)['uuid'=>'11111111-1111-4111-8111-111111111111','adopted'=>true,'protected'=>$protected,'order'=>null,'store'=>null,'saved'=>false,'save_active'=>false,'save_failed'=>false,'save_started'=>0,'save_completed'=>0,'payment_started'=>false,'phase'=>'creation','success'=>false];
 save_property($h,'checkout_attempt',$a);$q=new PendingQualificationSeam();save_property($h,'owned_lifecycle',$q);
 foreach(['woocommerce_order_status_pending','woocommerce_order_status_changed','woocommerce_order_status_completed','woocommerce_payment_complete','woocommerce_order_edit_status','woocommerce_email_order_details'] as $hook){add_action($hook,static function()use($hook){$GLOBALS['pending_effects'][]=$hook;});}
 return [$h,$o,$sink,$a,$q];
}
function pending_save($h,$o,$a){
 // Account/order fence is explicitly controlled. Native constructor/create and
 // retained capture/save evidence are the components under test.
 [$key,$marker]=owned_order_marker('pending',91);HandlerContractBoundary::$markers[$key]=$marker;
 add_action('woocommerce_before_order_object_save',[$h,'checkout_order_saving'],PHP_INT_MIN,2);
 add_action('woocommerce_after_order_object_save',[$h,'checkout_order_saved'],PHP_INT_MAX,2);
 owned_expect(91===$o->save() && $a->saved && 1===$a->save_started && 1===$a->save_completed);
}
$cases=[];
$cases['unmodified native constructor/CPT seam reproduces raw empty despite persisted pending']=function(){
 [$h,$o,$sink,$a,$q]=pending_fixture(false);owned_expect(''===$o->get_status('edit') && 'pending'===$o->get_status());
 owned_expect(91===$o->save() && ''===$o->get_status('edit') && 'wc-pending'===$GLOBALS['pending_posts'][0]['post_status']);
 owned_expect(1===$sink->creates && 0===$sink->updates && []===$q->calls && '10.6.0'===$o->get_version('edit'));
};
$cases['captured fresh positive Stripe materializes before sole native save with no transition']=function(){
 [$h,$o,$sink,$a,$q]=pending_fixture();owned_expect(''===$o->get_status('edit'));
 $h->capture_checkout_order($o,[]);
 owned_expect('pending'===$o->get_status('edit') && ['qualify','assert']===$q->calls && []===$GLOBALS['pending_posts']);
 owned_expect(false===(new ReflectionProperty($o,'status_transition'))->getValue($o));
 pending_save($h,$o,$a);$h->begin_checkout_deferred_payment($o);
 owned_expect('pending'===$o->get_status('edit') && 'wc-pending'===$GLOBALS['pending_posts'][0]['post_status'] && count($GLOBALS['pending_posts'])===1);
 owned_expect(1===$sink->creates && 0===$sink->updates && '10.6.0'===$o->get_version('edit') && null===$o->get_date_paid('edit') && ''===$o->get_transaction_id('edit') && !$o->is_paid());
 owned_expect([]===$GLOBALS['notes'] && []===$GLOBALS['pending_effects']);
};
$cases['actual native DraftOrders append preserves materialization and sole pending CPT save']=function(){
 [$h,$o,$sink,$a,$q]=pending_fixture();$class=Automattic\WooCommerce\Blocks\Domain\Services\DraftOrders::class;
 $draft=(new ReflectionClass($class))->newInstanceWithoutConstructor();add_filter('wc_order_statuses',[$draft,'register_draft_order_status'],10,1);
 $statuses=wc_get_order_statuses();owned_expect(isset($statuses['wc-pending'],$statuses['wc-checkout-draft']) && ''===$o->get_status('edit'));
 $h->capture_checkout_order($o,[]);pending_save($h,$o,$a);$h->begin_checkout_deferred_payment($o);
 owned_expect(['qualify','assert']===$q->calls && 'pending'===$o->get_status('edit') && 'wc-pending'===$GLOBALS['pending_posts'][0]['post_status'] && count($GLOBALS['pending_posts'])===1 && 1===$sink->creates && 0===$sink->updates);
 owned_expect(false===(new ReflectionProperty($o,'status_transition'))->getValue($o) && !$o->is_paid() && null===$o->get_date_paid('edit') && ''===$o->get_transaction_id('edit') && []===$GLOBALS['notes'] && []===$GLOBALS['pending_effects']);
};
foreach(['on-hold','wc-pending',false,null] as $default){$cases['noncanonical default rejected '.var_export($default,true)]=function()use($default){
 [$h,$o,$sink]=pending_fixture();add_filter('woocommerce_default_order_status',static fn()=>$default);
 owned_error(fn()=>$h->capture_checkout_order($o,[]));owned_expect(''===$o->get_status('edit') && 0===$sink->creates && []===$GLOBALS['pending_posts']);
};}
foreach(['paid','transaction','source','intent'] as $fault){$cases['preexisting unsafe field rejected before status materialization '.$fault]=function()use($fault){
 [$h,$o,$sink]=pending_fixture();match($fault){'paid'=>$o->set_date_paid(time()),'transaction'=>$o->set_transaction_id('synthetic-transaction'),'source'=>$o->add_meta_data('_stripe_source_id','synthetic-source',true),'intent'=>$o->add_meta_data('_stripe_intent_id','synthetic-intent',true)};
 owned_error(fn()=>$h->capture_checkout_order($o,[]));owned_expect(''===$o->get_status('edit') && 0===$sink->creates);
};}
$cases['qualified getter changing old status is rejected without a native save']=function(){
 [$h,$o,$sink]=pending_fixture();add_filter('woocommerce_order_get_status',static fn()=>'on-hold');
 owned_error(fn()=>$h->capture_checkout_order($o,[]));owned_expect(0===$sink->creates);
};
$cases['cohort drift aborts before sole native save']=function(){
 [$h,$o,$sink,$a,$q]=pending_fixture();$q->on_assert=static fn()=>throw new RuntimeException('Controlled status cohort drift.');
 owned_error(fn()=>$h->capture_checkout_order($o,[]));owned_expect(0===$sink->creates && $a->save_failed);
};
foreach(['total','currency','customer','gateway','uuid','paid','transaction','source','intent'] as $fault){$cases['post-materialization field drift rejected before native save '.$fault]=function()use($fault){
 [$h,$o,$sink,$a,$q]=pending_fixture();$q->on_assert=static function()use($fault,$o){match($fault){'total'=>$o->set_total('2.00'),'currency'=>save_property($o,'changes',array_merge($o->get_changes(),['currency'=>'USD'])),'customer'=>$o->set_customer_id(18),'gateway'=>$o->set_payment_method('stripeCreditDebitCard'),'uuid'=>$o->update_meta_data('_wl_checkout_operation_uuid','other'),'paid'=>$o->set_date_paid(time()),'transaction'=>$o->set_transaction_id('synthetic-transaction'),'source'=>$o->add_meta_data('_stripe_source_id','synthetic-source',true),'intent'=>$o->add_meta_data('_stripe_intent_id','synthetic-intent',true)};};
 owned_error(fn()=>$h->capture_checkout_order($o,[]));owned_expect(0===$sink->creates && []===$GLOBALS['pending_posts'] && $a->save_failed);
};}
foreach(['free','other-gateway','explicit-status'] as $kind){$cases['materialization scope excludes '.$kind]=function()use($kind){
 [$h,$o,$sink,$a,$q]=pending_fixture();if($kind==='free'){$o->set_total('0');}elseif($kind==='other-gateway'){$o->set_payment_method('cod');}else{$o->set_status('on-hold');}
 $before=$o->get_status('edit');$h->capture_checkout_order($o,[]);owned_expect($before===$o->get_status('edit') && []===$q->calls && 0===$sink->creates);
};}
$failed=[];foreach($cases as $name=>$test){try{$test();echo "PASS $name\n";}catch(Throwable $e){$failed[]=$name;fwrite(STDERR,'FAIL '.$name.': '.get_class($e).' '.$e->getMessage().' '.$e->getFile().':'.$e->getLine()."\n");}}
echo json_encode(['suite'=>'native-fresh-pending','cases'=>count($cases),'passed'=>count($cases)-count($failed),'failed'=>$failed,'php'=>PHP_VERSION,'limits'=>'Actual native constructor/setters/getters/save and inherited CPT creation/post status. SQL/metadata/cache/cohort/account fence are recording seams; no bootstrap/database/provider/mail/HTTP or complete checkout acceptance.'],JSON_THROW_ON_ERROR)."\n";exit($failed?1:0);

<?php
/** Actual WC_Order::payment_complete and WC_Abstract_Order::save + retained
 * handler evidence gates. Account/adoption identity is component setup only;
 * SQL/auth/cache/notes/datastore/hooks are declared controlled boundaries.
 * No native database, bootstrap, HTTP or complete creation acceptance. */
const WL_NATIVE_SAVE_BOOTSTRAP_ONLY = true;
require __DIR__.'/cart-session-owned-handler-contract.php';
define('WC_ABSPATH', $wc.'/'); define('DAY_IN_SECONDS',86400);
require $wc.'/includes/abstracts/abstract-wc-data.php';
require $wc.'/includes/class-wc-meta-data.php';
require $wc.'/includes/class-wc-datetime.php';
require $wc.'/includes/traits/trait-wc-item-totals.php';
require $wc.'/includes/abstracts/abstract-wc-order.php';
require $wc.'/includes/class-wc-order.php';
function wp_list_pluck($values,$field,$index=null) { $out=[]; foreach($values as $key=>$v) {$out[$index===null?$key:(is_object($v)?$v->$index:$v[$index])] = is_object($v)?$v->$field:$v[$field];} return $out; }
function wc_get_order_statuses(){return ['wc-pending'=>'Pending','wc-failed'=>'Failed','wc-on-hold'=>'On hold','wc-processing'=>'Processing','wc-completed'=>'Completed'];}
function wc_get_is_paid_statuses(){return ['processing','completed'];}
function wc_get_logger(){return new class {function error(...$args){}};}
function wp_kses_post($s){return $s;} function sanitize_email($s){return $s;}
function sanitize_text_field($s){return $s;} function current_user_can(...$args){return false;}
function wp_insert_comment($data){$GLOBALS['notes'][]=$data;return count($GLOBALS['notes']);}
function update_comment_meta(...$args){} function wc_timezone_string(){return 'UTC';}
function get_user_by(...$args){return false;} function wc_timezone_offset(){return 0;}
function get_option($key,$default=false){return $default;}
function wc_get_container(){return new class {function get($name){return new class {function custom_orders_table_usage_is_enabled(){return false;}};}};}
function get_current_blog_id(){return 1;} function get_post_meta(...$args){return '';}
function wc_format_decimal($value,...$args){return (string)$value;}
function wp_parse_url($url,$component=-1){return parse_url($url,$component);}
final class NativeSaveRecorder {
 function get_internal_meta_keys(){return [];}
 public array $durable=[]; public int $writes=0;
 function update(&$order){++$this->writes;$this->durable=['id'=>$order->get_id(),'status'=>$order->get_status('edit'),'paid'=>$order->get_date_paid('edit')];}
 function get_order_item_type(...$args){return 'line_item';} function read_items(...$args){return [];}
 function get_payment_token_ids(...$args){return [];}
}
function save_property($o,$name,$value){(new ReflectionProperty($o,$name))->setValue($o,$value);}
function native_save_fixture(){
 [$h,$db]=owned_start(17);$order=(new ReflectionClass(WC_Order::class))->newInstanceWithoutConstructor();
 $order->set_id(91); $order->set_object_read(true); save_property($order,'meta_data',[]); $order->init_meta_data([(object)['meta_id'=>1,'meta_key'=>'_wl_checkout_operation_uuid','meta_value'=>'11111111-1111-4111-8111-111111111111']]); $order->set_status('pending'); $order->set_object_read(true);$order->set_customer_id(17);
 $store=new NativeSaveRecorder();save_property($order,'data_store',$store);
 save_property($order,'items',['line_items'=>[],'tax_lines'=>[],'shipping_lines'=>[],'fee_lines'=>[],'coupon_lines'=>[]]);
 $attempt=(object)['uuid'=>'11111111-1111-4111-8111-111111111111','adopted'=>true,'protected'=>true,'order'=>$order,'store'=>$store,'saved'=>false,'save_active'=>false,'save_failed'=>false,'save_started'=>0,'save_completed'=>0,'payment_started'=>false,'success'=>false];
 save_property($h,'checkout_attempt',$attempt);
 $input='';foreach([DB_NAME,'contract_woocommerce_sessions','17'] as $part){$input.=strlen($part).':'.$part;}$hash=hash('sha256',$input);
 $key='wl_checkout_order_v1_'.$hash; $marker=json_encode(['schema'=>1,'kind'=>'checkout_order_attempt','destination_tuple_sha256'=>$hash,'operation_uuid'=>$attempt->uuid,'state'=>'pending','order_id'=>91],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES); HandlerContractBoundary::$markers[$key]=$marker;
 WC()->cart=new class {function is_empty(){return true;}};
 add_action('woocommerce_before_order_object_save',[$h,'checkout_order_saving'],PHP_INT_MIN,2);
 add_action('woocommerce_after_order_object_save',[$h,'checkout_order_saved'],PHP_INT_MAX,2);
 owned_expect(91===$order->save() && $attempt->saved && 'pending'===$store->durable['status']);
 $h->verify_checkout_order_return(91,$order);$h->begin_checkout_free_payment($order);
 return [$h,$order,$store,$attempt,$key,$marker];
}
if ( defined('WL_NATIVE_ORDER_BOOTSTRAP_ONLY') && true === WL_NATIVE_ORDER_BOOTSTRAP_ONLY ) { return; }
$cases=[];
$cases['native swallowed payment save cannot reuse initial saved latch']=function(){
 [$h,$order,$store,$a,$key,$marker]=native_save_fixture();
 add_action('woocommerce_before_order_object_save',static function($order){if($order->get_id()>0){throw new Exception('Controlled before payment-save failure.');}},10,1);
 owned_expect(true===$order->payment_complete());
 owned_expect('pending'===$store->durable['status']&&null===$store->durable['paid']&&$order->is_paid());
 owned_error(fn()=>$h->checkout_free_order_succeeded($order));
 owned_expect(!$a->saved&&!$a->success&&HandlerContractBoundary::$markers[$key]===$marker&&$h->protects_checkout_order());
 owned_error(fn()=>$h->begin_checkout_free_payment($order));
};
$cases['actual successful payment save supplies fresh matching evidence']=function(){
 [$h,$order,$store,$a,$key,$marker]=native_save_fixture();
 owned_expect(true===$order->payment_complete());$h->checkout_free_order_succeeded($order);
 owned_expect($a->success&&$a->saved&&1===$a->save_started&&1===$a->save_completed&&'completed'===$store->durable['status']&&null!==$store->durable['paid']);
 owned_error(fn()=>$h->begin_checkout_free_payment($order));
};
$failed=0;foreach($cases as $name=>$case){try{$case();echo "PASS $name\n";}catch(Throwable $e){++$failed;fwrite(STDERR,"FAIL $name: ".get_class($e).' '.$e->getMessage().' '.$e->getFile().':'.$e->getLine().' '.$e->getTraceAsString()."\n");}}
echo count($cases)." actual native order-save component cases; $failed failed.\n";exit($failed?1:0);

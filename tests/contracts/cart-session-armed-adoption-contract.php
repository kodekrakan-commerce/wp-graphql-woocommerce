<?php
/** Real retained private adoption -> native Woo cookie-init caller -> armed
 * handler init -> signing/preparation. Storage/reservation/transfer/JWT are real.
 * Native auth/user persistence and lifecycle permit/cohort are controlled seams;
 * this component does not qualify cookie authenticity, the native cohort or HTTP.
 * Reflection arranges an already-reserved one-use creation attempt; no new public
 * account adoption API or production authorization rule is introduced. */
namespace WPGraphQL\WooCommerce\Utils {
 final class Cart_Session_Lifecycle {
  public $handler; public $context; public int $armed=0; public int $consumed=0; public int $adopted=0;
  public bool $deny_consume=false; public bool $fail_adoption=false;
  public function is_terminal(){return false;}
  public function arm_checkout_cookie_capsule(int $id):void { if($this->armed||$id!==17){throw new \RuntimeException('Controlled capsule arm differs');}$this->armed=$id; }
  public function consume_checkout_cookie_capsule():bool { if($this->deny_consume){return false;}if($this->consumed||$this->armed!==\get_current_user_id()){throw new \RuntimeException('Controlled capsule consumption differs');}++$this->consumed;return true; }
  public function adopt_checkout_customer($context):void { if($context!==$this->context||$this->consumed!==1||$this->fail_adoption){throw new \RuntimeException('Controlled checked lifecycle adoption failed');}++$this->adopted;\HandlerContractBoundary::event('checked-destination-adoption'); }
 }
}
namespace {
const WOOGRAPHQL_ACTUAL_LIFECYCLE_CONTRACT=true; // Suppress the shared dormant mock; seam above is explicit.
const WL_NATIVE_SAVE_BOOTSTRAP_ONLY=true;
require __DIR__.'/cart-session-owned-handler-contract.php';
require $wc.'/includes/wc-user-functions.php'; // Genuine wc_set_customer_auth_cookie caller.
require __DIR__.'/cart-session-transfer-fixtures.php';
require __DIR__.'/cart-session-creation-fixtures.php';
function storage_expect($condition){owned_expect($condition);}
function storage_fixture($account=false){owned_fixture();}
function storage_unavailable($action){owned_error($action);}
function wp_set_current_user($id){HandlerContractBoundary::$user=$id;}
function wp_set_auth_cookie($id,$remember=false){owned_expect($id===17&&$remember===true);HandlerContractBoundary::event('native-auth-seam');}
/** Delegate exact transfer SQL to the existing recorder; account hydration only
 * is a declared controlled row boundary. No native SQL is executed. */
final class AdoptionContractDatabase implements \WLCommerce\Database\Owned_Scope_Driver {
 public $inner; private array $account_queries=[];
 function __construct(){ $this->inner=new Creation_Contract_Database(); }
 function &__get($name){return $this->inner->$name;} function __isset($name){return isset($this->inner->$name);}
 function begin_owned_scope(array $names,int $timeout):object{return $this->inner->begin_owned_scope($names,$timeout);}
 function assert_owned(object $h):void{$this->inner->assert_owned($h);} function seal_owned_scope(object $h):void{$this->inner->seal_owned_scope($h);}
 function release_owned_scope(object $h):void{$this->inner->release_owned_scope($h);} function abort_owned_scope(object $h):void{$this->inner->abort_owned_scope($h);}
 function get_failure_state(object $h):array{return $this->inner->get_failure_state($h);}
 function prepare($sql,...$args){if($sql==='SELECT ID, user_login, user_email, user_nicename FROM %i WHERE ID = %d'){$key='account-'.count($this->account_queries);$this->account_queries[$key]=$args;return $key;}return $this->inner->prepare($sql,...$args);}
 function get_row($query){if(isset($this->account_queries[$query])){owned_expect($this->account_queries[$query]===['wp_users',17]);return (object)['ID'=>17,'user_login'=>'component-account','user_email'=>'component@example.invalid','user_nicename'=>'component-account'];}return $this->inner->get_row($query);}
 function __call($name,$args){return $this->inner->$name(...$args);}
}
function adoption_property($o,$name,$value){(new \ReflectionProperty($o,$name))->setValue($o,$value);}
function armed_fixture(bool $selected=true):array {
 owned_fixture(); $db=new AdoptionContractDatabase();$GLOBALS['wpdb']=$db;
 $guest=str_repeat('a',32);$expiry=time()+172800;$data=['cart'=>['component'=>['quantity'=>1]]];
 $storage=new \WPGraphQL\WooCommerce\Utils\Cart_Session_Storage($guest,'wp_woocommerce_sessions',0);$storage->acquire();$storage->reserve_checkout_creation('wp_options');
 $handler=(new \ReflectionClass(\WPGraphQL\WooCommerce\Utils\QL_Session_Handler::class))->newInstanceWithoutConstructor();
 foreach(['graphql_mode'=>true,'session_admitted'=>true,'admitted_user_id'=>0,'admitted_customer_id'=>$guest,'owned_storage'=>$storage,'_customer_id'=>$guest,'_data'=>$data,'_session_issued'=>time()-2,'_session_expiration'=>$expiry,'_session_expiring'=>$expiry-3600,'_token'=>'woocommerce-session','_has_token'=>true,'secret_key_resolved'=>true,'secret_key'=>GRAPHQL_WOOCOMMERCE_SECRET_KEY] as $key=>$value){adoption_property($handler,$key,$value);}
 $context=new \stdClass();$lifecycle=new \WPGraphQL\WooCommerce\Utils\Cart_Session_Lifecycle();$lifecycle->handler=$handler;$lifecycle->context=$context;adoption_property($handler,'owned_lifecycle',$lifecycle);WC()->session=$handler;
 $handler->set_customer_session_token(true);$handler->prepare_session_token();if($selected){$handler->prepare_customer_session_token();}$old=$handler->build_token(); $GLOBALS['armed_sign_fault_calls']=0;
 $uuid=$storage->freeze_for_checkout_transfer($data,$expiry);
 $attempt=(object)['uuid'=>$uuid,'user_id'=>0,'auth_expected'=>false,'adopted'=>false,'protected'=>false,'selected_customer_token'=>$selected,'success'=>false];adoption_property($handler,'checkout_attempt',$attempt);
 return [$handler,$db,$lifecycle,$context,$attempt,$old,$guest];
}
function armed_adopt($h,$context):void {(new \ReflectionMethod($h,'adopt_checkout_customer'))->invoke($h,17,$context);}
function armed_pending($db):bool { $row=$db->ledger['wl_checkout_order_v1_'.transfer_hash('17')]??null;return $row&&json_decode($row['value'],true)['state']==='pending'&&json_decode($row['value'],true)['order_id']===0; }
$cases=[];
foreach([false,true] as $selected){$cases['checked native-init adoption prepares destination token selected='.($selected?'yes':'no')]=function()use($selected){
 [$h,$db,$life,$ctx,$attempt,$old,$guest]=armed_fixture($selected);armed_adopt($h,$ctx);
 $token=$h->build_token();owned_expect(is_string($token)&&$token!==$old);
 $decoded=\WPGraphQL\WooCommerce\Vendor\Firebase\JWT\JWT::decode($token,new \WPGraphQL\WooCommerce\Vendor\Firebase\JWT\Key(GRAPHQL_WOOCOMMERCE_SECRET_KEY,'HS256'));
 owned_expect($decoded->data->customer_id==='17'&&$life->consumed===1&&$life->adopted===1&&$attempt->adopted&&!$attempt->auth_expected&&armed_pending($db));
 owned_expect($h->add_prepared_session_header([])===['woocommerce-session'=>$token]);
 if($selected){owned_expect($h->build_customer_token()===$token);}else{owned_error(fn()=>$h->build_customer_token());}
 $h->init_session_cookie();owned_expect($h->build_token()===$token&&$life->consumed===1);
 owned_error(fn()=>armed_adopt($h,$ctx));owned_expect($h->build_token()===$token);
};}
foreach(['consume','adopt','sign'] as $fault){$cases['failed '.$fault.' withholds guest and destination credentials; pending retained']=function()use($fault){
 [$h,$db,$life,$ctx,$attempt,$old,$guest]=armed_fixture();
 if($fault==='consume'){$life->deny_consume=true;}elseif($fault==='adopt'){$life->fail_adoption=true;}else{add_filter('graphql_woocommerce_cart_session_signed_token',static function(){++$GLOBALS['armed_sign_fault_calls'];throw new \RuntimeException('Controlled signing failure');});}
 try{armed_adopt($h,$ctx);throw new \RuntimeException('Adoption failure expected');}catch(\Throwable $error){owned_expect($error->getMessage()!=='Adoption failure expected');}
 owned_error(fn()=>$h->assert_session_ready());owned_error(fn()=>$h->build_token());owned_expect($h->add_prepared_session_header([])===[]&&armed_pending($db));
 if($fault==='sign'){owned_expect($GLOBALS['armed_sign_fault_calls']===1&&$life->consumed===1&&$life->adopted===1);}elseif($fault==='consume'){owned_expect($life->consumed===0&&$life->adopted===0);}else{owned_expect($life->consumed===1&&$life->adopted===0);}
};}
$failed=0;foreach($cases as $name=>$case){try{$case();echo 'PASS '.$name."\n";}catch(\Throwable $e){++$failed;fwrite(STDERR,'FAIL '.$name.': '.get_class($e).' '.$e->getMessage().' '.$e->getTraceAsString()."\n");}}
echo count($cases)." armed adoption component cases; $failed failed.\n";exit($failed?1:0);
}

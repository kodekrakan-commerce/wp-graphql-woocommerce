<?php
/** Explicit fixed-settings/user-row/metadata seams; methods under test remain genuine. */
function modern_event($kind,$value=null){HandlerContractBoundary::event($kind,$value);}
function modern_expect($value,$message='component assertion'){if(!$value){throw new RuntimeException($message);}}
function untrailingslashit($s){return rtrim($s,'/');}function trailingslashit($s){return rtrim($s,'/').'/';}

function esc_html__($s,$domain=null){return $s;}function esc_html($s){return $s;}function esc_attr($s){return $s;}function esc_url($s){return $s;}
function _x($s,$context,$domain=null){return $s;}function esc_attr__($s,$domain=null){return $s;}
function wp_slash($value){return is_array($value)?array_map('wp_slash',$value):(is_string($value)?addslashes($value):$value);}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
function sanitize_text_field($value){return trim((string)$value);}function sanitize_email($s){return $s;}function is_email($s){return filter_var($s,FILTER_VALIDATE_EMAIL);}
function wp_parse_args($args,$defaults=[]){if(is_object($args)){$args=get_object_vars($args);}elseif(!is_array($args)){$parsed=[];parse_str((string)$args,$parsed);$args=$parsed;}return array_merge($defaults,$args);}
function wp_list_pluck($list,$field){return array_map(fn($x)=>is_object($x)?$x->$field:$x[$field],$list);}
function wp_prime_option_caches($keys){
 modern_event('option-prime',$keys);$case=$GLOBALS['modern_case']??'';
 if(in_array($case,['gateway-prime-owned-throw','gateway-prime-generic-throw'],true)){
  $trace=debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);$native=array_values(array_filter($trace,fn($frame)=>($frame['class']??null)==='WC_Payment_Gateways'&&($frame['function']??null)==='init'));
  if(!$native){throw new RuntimeException('Failure injection did not reach genuine gateway init');}
  modern_event('option-prime-backend-throw',['kind'=>'gateway-prime-owned-throw'===$case?'owned':'generic','caller_class'=>$native[0]['class'],'caller_method'=>$native[0]['function']]);
  if('gateway-prime-owned-throw'===$case){throw new \WLCommerce\Database\Owned_Scope_Error();}throw new RuntimeException('Controlled generic option backend failure');
 }
 foreach($keys as$key){get_option($key);}
}
function get_option($key,$default=false){
 modern_event('option-read',$key);$options=['admin_email'=>'synthetic@example.invalid','timezone_string'=>'UTC','gmt_offset'=>0,'woocommerce_bacs_settings'=>['enabled'=>'no'],'woocommerce_bacs_accounts'=>[],'woocommerce_cheque_settings'=>['enabled'=>'no'],'woocommerce_cod_settings'=>['enabled'=>'no'],'woocommerce_paypal_settings'=>['enabled'=>'no','_should_load'=>'yes'],'woocommerce_gateway_order'=>[], 'woocommerce_calc_taxes'=>'no','woocommerce_tax_round_at_subtotal'=>'no','woocommerce_currency'=>'EUR','woocommerce_prices_include_tax'=>'no','woocommerce_default_country'=>'PT','woocommerce_default_customer_address'=>'base','woocommerce_tax_based_on'=>'billing','woocommerce_enable_guest_checkout'=>'yes','woocommerce_enable_signup_and_login_from_checkout'=>'yes','woocommerce_registration_generate_username'=>'yes','woocommerce_registration_generate_password'=>'yes','woocommerce_price_num_decimals'=>2,'woocommerce_price_decimal_sep'=>'.','woocommerce_price_thousand_sep'=>',','woocommerce_currency_pos'=>'left','woocommerce_store_address'=>'','woocommerce_store_address_2'=>'','woocommerce_store_city'=>'','woocommerce_store_postcode'=>'','woocommerce_hold_stock_minutes'=>0,'woocommerce_allow_tracking'=>'no'];
 if(array_key_exists($key,$options)){return $options[$key];}throw new RuntimeException('Undeclared fixture option '.$key);
}
function wc_get_customer_default_location(){return ['country'=>'PT','state'=>''];}

function wc_doing_it_wrong(...$args){throw new RuntimeException('Unexpected Woo warning '.json_encode($args));}
function wc_get_rounding_precision(){return 4;}function wc_prices_include_tax(){return false;}
function wc_get_base_location(){return ['country'=>'PT','state'=>''];}function wc_get_cart_url(){return 'https://offline.example.invalid/cart';}
function get_current_blog_id(){return 1;}function home_url($path=''){return 'https://offline.example.invalid'.$path;}function site_url($path=''){return home_url($path);}function admin_url($path=''){return home_url('/admin/'.$path);}
function get_locale(){return 'en_US';}function get_user_locale($id=0){return 'en_US';}function wp_generate_uuid4(){return '11111111-1111-4111-8111-111111111111';}
function get_user_meta($id,$key='',$single=false){modern_event('meta-read',$key);$rows=$GLOBALS['modern_meta'][(int)$id]??[];if(''===$key){return array_map(fn($v)=>[$v],$rows);}return $rows[$key]??($single?'':[]);}
function modern_metadata_permission(int $id,string $key):void{
 $case=$GLOBALS['modern_case']??'';$keys=[];
 if('seam-meta'===$case){$keys=['fixture'];}
 elseif('customer-store'===$case){$keys=['billing_address_1','billing_city'];}
 elseif(in_array($case,['ordinary-flush','fresh-viewer'],true)){$keys=['_woocommerce_persistent_cart_1'];}
 elseif(str_starts_with($case,'activity-')){$keys=['wc_last_active'];if('activity-login'===$case){$keys[]='_woocommerce_load_saved_cart_after_login';}}
 elseif(in_array($case,['refresh-success','refresh-guest-success','refresh-alias-fragment','refresh-variable-directives','refresh-contradictory','refresh-payload-drift'],true)){$keys=['graphql_login_token_expiration'];}
 if(17!==$id||!in_array($key,$keys,true)){throw new RuntimeException('Unexpected metadata actor/key');}
}
function modern_metadata_checkpoint():void{$GLOBALS['modern_meta_before']=$GLOBALS['modern_meta'];}
function modern_metadata_opponent(int $id,string $key,$value):void{
 $old=$GLOBALS['modern_meta'][$id][$key]??null;modern_event('meta-external',['id'=>$id,'key'=>$key,'previous'=>$old,'value'=>$value,'reason'=>'controlled-CAS-opponent']);$GLOBALS['modern_meta'][$id][$key]=$value;
}
function update_user_meta($id,$key,$value,$previous=''){
 $id=(int)$id;modern_metadata_permission($id,$key);modern_event('meta-attempt',['id'=>$id,'key'=>$key,'submitted'=>$value,'previous'=>$previous]);
 if($GLOBALS['modern_race']??false){modern_metadata_opponent($id,$key,'race-winner');$GLOBALS['modern_race']=false;}
 if(''!==$previous && ($GLOBALS['modern_meta'][$id][$key]??'')!==$previous){modern_event('meta-cas-refused',['id'=>$id,'key'=>$key,'submitted'=>$value,'previous'=>$previous,'current'=>$GLOBALS['modern_meta'][$id][$key]??null]);return false;}
 $exists=array_key_exists($key,$GLOBALS['modern_meta'][$id]??[]);$old=$GLOBALS['modern_meta'][$id][$key]??null;$stored=wp_unslash($value);$GLOBALS['modern_meta'][$id][$key]=$stored;
 modern_event('meta-write',['id'=>$id,'key'=>$key,'value'=>$stored,'previous'=>$previous,'old_exists'=>$exists,'old_value'=>$old]);do_action('updated_user_meta',1,$id,$key,$value);return true;
}
function delete_user_meta($id,$key){modern_event('meta-delete',['id'=>(int)$id,'key'=>$key,'old_exists'=>array_key_exists($key,$GLOBALS['modern_meta'][(int)$id]??[]),'old_value'=>$GLOBALS['modern_meta'][(int)$id][$key]??null]);throw new RuntimeException('Unexpected metadata delete');}
function wp_update_user($args){modern_event('user-write',$args);return $args['ID'];}
function get_user_by($field,$id){return in_array((int)$id,[17,23,24],true)?new WP_User($id):false;}
class WP_User {public int $ID;public $user_email='synthetic@example.invalid';public $user_login='synthetic';public $display_name='Synthetic';public $user_registered='2026-01-01 00:00:00';public $roles=['customer'];public $data;public function __construct($id){$this->ID=(int)$id;$this->data=(object)['ID'=>$this->ID,'user_email'=>$this->user_email,'user_login'=>$this->user_login,'display_name'=>$this->display_name,'user_registered'=>$this->user_registered];}public function exists(){return $this->ID>0;}}
function wp_get_current_user(){return new WP_User(get_current_user_id());}function current_user_can($capability){return false;}
function wp_set_current_user($id){modern_event('native.identity-change');$GLOBALS['modern_detached_before_identity']=WC()->session->is_auth_detached()&&!WC()->session->has_owned_scope();HandlerContractBoundary::$user=(int)$id;do_action('set_current_user');return new WP_User($id);}
function graphql_debug($s){modern_event('native.debug');}
function get_gmt_from_date($date){return $date;}function wp_timezone(){return new DateTimeZone('UTC');}function wp_timezone_string(){return 'UTC';}

function _get_meta_table($type){if('user'!==$type){throw new RuntimeException('Unsupported metadata table');}return 'contract_usermeta';}
/** Fixed process-local container handle; every resolved service is the genuine official class. */
function wc_get_container(){static $container;return $container??=new \Automattic\WooCommerce\Container();}
function add_query_arg($args,$url=''){return $url.'?'.http_build_query($args);}function is_multisite(){return false;}function get_blog_prefix($id=null){return 'contract_';}
function get_woocommerce_currency(){return 'EUR';}function wc_get_page_permalink($page){return home_url('/'.$page);}function wp_parse_url($url,$component=-1){return parse_url($url,$component);}

<?php
/** Actual new WC_Customer(17,true), native session datastore read/write and
 * retained lifecycle address handoff. Account datastore, session, totals probe,
 * hooks/cache/auth are controlled. No native DB/bootstrap/full journey proof. */
const WOOGRAPHQL_ACTUAL_LIFECYCLE_CONTRACT=true;
const WL_NATIVE_ORDER_BOOTSTRAP_ONLY=true;
require __DIR__.'/cart-session-native-order-save-contract.php';
require $wc.'/includes/interfaces/class-wc-object-data-store-interface.php';
require $wc.'/includes/interfaces/class-wc-customer-data-store-interface.php';
require $wc.'/includes/data-stores/class-wc-data-store-wp.php';
require $wc.'/includes/data-stores/class-wc-customer-data-store-session.php';
require $wc.'/includes/class-wc-data-store.php';
require $wc.'/includes/class-wc-customer.php';
require $owner.'/includes/utils/class-cart-session-lifecycle.php';
function wc_get_customer_default_location(){return ['country'=>'PT','state'=>''];}
function is_email($value){return filter_var($value,FILTER_VALIDATE_EMAIL);}
function wc_is_valid_email($value){return (bool)is_email($value);}
final class AdoptionAccountRecorder extends WC_Customer_Data_Store_Session {
 function read(&$customer){$customer->set_username('fresh-account');$customer->set_email('fresh@example.invalid');$customer->set_role('customer');$customer->set_object_read(true);}
}
add_filter('woocommerce_data_stores',static function($stores){$stores['customer']='AdoptionAccountRecorder';return $stores;});
function adoption_case(){
 HandlerContractBoundary::$woocommerce=(object)['session'=>new class {public array $data=[];function get($key,$default=null){return $this->data[$key]??$default;}function set($key,$value){$this->data[$key]=$value;}},'countries'=>new class {function get_base_country(){return 'PT';}function get_base_state(){return '';}function get_states($country){return [];}},'cart'=>null,'customer'=>null];
 $guest=new WC_Customer(0,true);
 foreach(['billing'=>'Lisboa','shipping'=>'Porto'] as $type=>$city){$guest->{'set_'.$type.'_first_name'}('Test');$guest->{'set_'.$type.'_city'}($city);$guest->{'set_'.$type.'_address_1'}('Synthetic street');$guest->{'set_'.$type.'_postcode'}('1000-001');}
 $guest->set_billing_email('address@example.invalid');$guest->set_billing_phone('000000000');$guest->set_shipping_phone('111111111');
 $guest->set_username('guest-identity');$guest->set_role('administrator');$guest->save();
 owned_expect('0'===WC()->session->get('customer')['id']&&'Lisboa'===WC()->session->get('customer')['city']);
 $account=new WC_Customer(17,true); save_property($account,'meta_data',[]);
 owned_expect(''===$account->get_billing_city('edit')&&''===$account->get_shipping_city('edit'));
 $lifecycle=(new ReflectionClass(WPGraphQL\WooCommerce\Utils\Cart_Session_Lifecycle::class))->newInstanceWithoutConstructor();
 (new ReflectionMethod($lifecycle,'copy_checkout_customer_addresses'))->invoke($lifecycle,$guest,$account);
 WC()->customer=$account;
 WC()->cart=new class {public $observed;function calculate_totals(){$this->observed=[WC()->customer->get_id(),WC()->customer->get_billing_city('edit'),WC()->customer->get_shipping_city('edit')];}};
 WC()->cart->calculate_totals();owned_expect([17,'Lisboa','Porto']===WC()->cart->observed);
 owned_expect('fresh-account'===$account->get_username('edit')&&'customer'===$account->get_role('edit')&&'fresh@example.invalid'===$account->get_email('edit'));
 $account->save();$final=WC()->session->get('customer');
 owned_expect('17'===$final['id']&&'Lisboa'===$final['city']&&'Porto'===$final['shipping_city']&&'address@example.invalid'===$final['email']&&'111111111'===$final['shipping_phone']);
 $again=new WC_Customer(17,true);owned_expect('Lisboa'===$again->get_billing_city('edit')&&'Porto'===$again->get_shipping_city('edit'));
}
try{adoption_case();echo "PASS native new-ID session rejects guest addresses; retained handoff preserves totals and final native session\n1 native customer adoption component case; 0 failed.\n";}catch(Throwable $e){fwrite(STDERR,'FAIL native customer adoption: '.get_class($e).' '.$e->getMessage().' '.$e->getTraceAsString()."\n");exit(1);}

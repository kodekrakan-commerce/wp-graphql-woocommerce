<?php
/** Independent, complete metadata expectation policy shared by runner assertion controls. */
function modern_metadata_check(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function modern_metadata_assert(string $case, array $state, ?array $native_token = null): array {
 $before=$state['meta_before'];$configured=$state['meta_initial'];
 if('refresh-revoked'===$case){$configured[17]['graphql_login_secret_revoked']=true;}
 elseif('refresh-wrong-secret'===$case){$configured[17]['graphql_login_secret']='wrong';}
 elseif(str_starts_with($case,'activity-')){$configured[17]['wc_last_active']=(string)($state['detail']['seed_time']-$state['detail']['seed_ago']);}
 modern_metadata_check($configured===$before,'Metadata setup changed an undeclared actor/key/value');
 $expected=$before;$attempts=[];$writes=[];$refused=[];$external=[];
 $add=function(int $id,string $key,$submitted,$stored,$previous='')use(&$attempts,&$writes,&$expected):void{
  $attempts[]=['id'=>$id,'key'=>$key,'submitted'=>$submitted,'previous'=>$previous];
  $exists=array_key_exists($key,$expected[$id]??[]);$old=$expected[$id][$key]??null;
  $writes[]=['id'=>$id,'key'=>$key,'value'=>$stored,'previous'=>$previous,'old_exists'=>$exists,'old_value'=>$old];$expected[$id][$key]=$stored;
 };
 $literal="O'Reilly\\lane";
 if('seam-meta'===$case){
  $add(17,'fixture',addslashes($literal),$literal);$external[]=['id'=>17,'key'=>'fixture','previous'=>$literal,'value'=>'new','reason'=>'controlled-CAS-opponent'];$expected[17]['fixture']='new';
  $attempts[]=['id'=>17,'key'=>'fixture','submitted'=>'stale','previous'=>'old'];$refused[]=['id'=>17,'key'=>'fixture','submitted'=>'stale','previous'=>'old','current'=>'new'];
 }elseif('customer-store'===$case){$add(17,'billing_address_1',addslashes($literal),$literal);$add(17,'billing_city',addslashes($literal),$literal);}
 elseif(in_array($case,['ordinary-flush','fresh-viewer'],true)){$add(17,'_woocommerce_persistent_cart_1',['cart'=>[]],['cart'=>[]]);}
 elseif(str_starts_with($case,'activity-')){
  $mode=substr($case,9);$old=$before[17]['wc_last_active'];
  if(in_array($mode,['default-write','login','wp-write','race'],true)){
   $value=$state['detail']['activity_value'];modern_metadata_check(is_string($value)&&ctype_digit($value)&&(int)$value>=$state['detail']['clock_before']&&(int)$value<=$state['detail']['clock_after'],'Activity timestamp outside genuine execution window');
   if('race'===$mode){$attempts[]=['id'=>17,'key'=>'wc_last_active','submitted'=>$value,'previous'=>$old];$external[]=['id'=>17,'key'=>'wc_last_active','previous'=>$old,'value'=>'race-winner','reason'=>'controlled-CAS-opponent'];$expected[17]['wc_last_active']='race-winner';$refused[]=['id'=>17,'key'=>'wc_last_active','submitted'=>$value,'previous'=>$old,'current'=>'race-winner'];}
   else{$add(17,'wc_last_active',$value,$value,$old);}
  }
  if('login'===$mode){$add(17,'_woocommerce_load_saved_cart_after_login',1,1);}
 }elseif(in_array($case,['refresh-success','refresh-guest-success','refresh-alias-fragment','refresh-variable-directives','refresh-contradictory','refresh-payload-drift'],true)){
  modern_metadata_check(is_array($native_token)&&17===($native_token['actor']??null)&&is_int($native_token['exp']??null),'Signed native token facts unavailable');$add(17,'graphql_login_token_expiration',$native_token['exp'],$native_token['exp']);
 }
 $journal=function(string $kind)use($state):array{return array_values(array_map(fn($e)=>$e['identity'],array_filter($state['events'],fn($e)=>$kind===$e['kind'])));};
 modern_metadata_check($attempts===$journal('meta-attempt'),'Metadata attempted actor/key/value/previous set differs');
 modern_metadata_check($writes===$journal('meta-write'),'Metadata written actor/key/value/previous set differs');
 modern_metadata_check($refused===$journal('meta-cas-refused'),'Metadata conditional refusal differs');
 modern_metadata_check($external===$journal('meta-external'),'Controlled competing metadata writer differs');
 modern_metadata_check([]===$journal('meta-delete'),'Unexpected metadata delete');
 modern_metadata_check($expected===$state['meta'],'Complete durable metadata differs, including unrelated actors/keys');
 return ['before'=>$before,'expected_after'=>$expected,'attempts'=>$attempts,'writes'=>$writes,'conditional_refusals'=>$refused,'controlled_external_writes'=>$external,'deletes'=>[]];
}

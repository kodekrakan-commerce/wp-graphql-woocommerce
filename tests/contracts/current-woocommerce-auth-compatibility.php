<?php
/** Explicit current-cohort component profile. Never changes product/default pins. */
error_reporting(E_ALL);ini_set('display_errors','0');ini_set('log_errors','0');
$fixture=__DIR__.'/current-woocommerce-auth';$owner=dirname(__DIR__,2);
$profileHash=hash_file('sha256',$fixture.'/profile.json');$profile=json_decode(file_get_contents($fixture.'/profile.json'),true,512,JSON_THROW_ON_ERROR);
$root=rtrim(getenv('WL_MODERN_AUTH_SOURCE_ROOT')?:'', '/');
$evidence=rtrim(getenv('WL_MODERN_AUTH_EVIDENCE')?:'', '/');$tamperedRoot=rtrim(getenv('WL_MODERN_AUTH_TAMPERED_ROOT')?:'', '/');
function current_auth_expect($value,$message){if(!$value){throw new RuntimeException($message);}}
function current_auth_file($file,$hash){current_auth_expect(is_file($file)&&hash_equals($hash,hash_file('sha256',$file)),'Exact profile file unavailable or changed: '.$file);}
try{
 foreach(['zip','openssl','mbstring','json']as$module){current_auth_expect(extension_loaded($module),'Required PHP module unavailable: '.$module);}
 current_auth_expect(''!==$root&&is_dir($root),'Configure WL_MODERN_AUTH_SOURCE_ROOT with the bound code-only materialization.');
 foreach($profile['archives']+['headless'=>$profile['headless_archive']]as$archive){current_auth_file($archive['path'],$archive['sha256']);}
 foreach($profile['files']as$relative=>$hash){current_auth_expect(!str_contains($relative,'..')&&str_ends_with($relative,'.php'),'Invalid bounded source member');current_auth_file($root.'/'.$relative,$hash);}
 foreach($profile['owned_files']as$file=>$hash){current_auth_file($file,$hash);}
 foreach($profile['fixture_files']as$relative=>$hash){current_auth_file($fixture.'/'.$relative,$hash);}
 current_auth_expect(''!==$tamperedRoot&&is_dir($tamperedRoot),'Configure the separate WL_MODERN_AUTH_TAMPERED_ROOT control.');
 $tamperedMember='woocommerce/includes/class-wc-customer.php';$original=file_get_contents($root.'/'.$tamperedMember);$altered=file_get_contents($tamperedRoot.'/'.$tamperedMember);$different=0;
 current_auth_expect(strlen($original)===strlen($altered),'Tamper control length differs');for($i=0;$i<strlen($original);$i++){if($original[$i]!==$altered[$i]){$different++;}}
 current_auth_expect(1===$different,'Tamper control must change exactly one byte');$tamperedHash=hash('sha256',$altered);
 foreach($profile['files']as$relative=>$hash){current_auth_file($tamperedRoot.'/'.$relative,$relative===$tamperedMember?$tamperedHash:$hash);}
 if($evidence){current_auth_expect(is_dir($evidence)&&is_writable($evidence),'Evidence directory must already exist.');}
}catch(Throwable$e){fwrite(STDERR,$e->getMessage()."\n");exit(2);}
require $fixture.'/metadata-assertions.php';
$parentDiagnostics=[];set_error_handler(static function($severity,$message,$file,$line)use(&$parentDiagnostics){if(E_DEPRECATED===$severity||E_USER_DEPRECATED===$severity){$parentDiagnostics[]=['severity'=>$severity,'message'=>$message,'file'=>$file,'line'=>$line];return true;}throw new ErrorException($message,0,$severity,$file,$line);});
require $root.'/vendor-prefixed/firebase/php-jwt/src/JWT.php';require $root.'/vendor-prefixed/firebase/php-jwt/src/Key.php';
function current_auth_token_facts(string $token):array{
 $claims=\WPGraphQL\Login\Vendor\Firebase\JWT\JWT::decode($token,new \WPGraphQL\Login\Vendor\Firebase\JWT\Key(str_repeat('fixture-native-key-',4),'HS256'));
 current_auth_expect(is_int($claims->exp??null)&&17===($claims->data->user->id??null),'Genuine signed token actor/expiration claims');return ['actor'=>$claims->data->user->id,'exp'=>$claims->exp,'token_sha256'=>hash('sha256',$token)];
}
function current_auth_expiration_assert(array $payload,array $state,array $facts):void{
 $returned=$payload['authTokenExpiration']??$payload['expires']??null;
 current_auth_expect(is_string($returned)&&ctype_digit($returned)&&$returned===(string)$facts['exp'],'Returned expiration differs from genuine signed JWT exp');
 current_auth_expect($facts['exp']===($state['meta'][17]['graphql_login_token_expiration']??null),'Stored actor17 expiration differs from genuine signed JWT exp');
}
$cases=['seam-meta','objects','ordinary-flush','detached','native-cookie','absent-cart-token','invalid-cart-token','retired-cart-token','customer-store','session-store','activity-default-skip','activity-default-write','activity-login','activity-wp-skip','activity-wp-write','activity-filtered','activity-race','source-wrong','source-missing','receiver-cart','receiver-customer','receiver-backreference','gateway-arity','gateway-priority','gateway-receiver','late-hook','unreviewed-gateway','gateway-prime-owned-throw','gateway-prime-generic-throw','source-tampered','expired-bearer','refresh-success','refresh-guest-success','refresh-alias-fragment','refresh-variable-directives','refresh-final-input-drift','refresh-final-input-missing','refresh-contradictory','refresh-payload-drift','refresh-invalid','refresh-expired','refresh-revoked','refresh-wrong-secret','refresh-wrong-source','refresh-wrong-factory','refresh-mixed-first','refresh-mixed-last','refresh-two-aliases','fresh-viewer'];
$results=[];$loaded=[];$diagnostics=[];$failures=0;$bearer=null;
foreach($cases as$case){
 $ledger=tempnam(sys_get_temp_dir(),'wl-modern-component-');chmod($ledger,0600);$status=null;$stdout='';$stderr='';$state=null;$failure=null;$metadataExpectation=null;$nativeFacts=null;$response=null;$caseRoot='source-tampered'===$case?$tamperedRoot:$root;
 try{
  if('fresh-viewer'===$case){current_auth_expect(is_string($bearer),'Successful renewal prerequisite unavailable.');putenv('WL_MODERN_AUTH_BEARER='.$bearer);}
  putenv('WL_MODERN_AUTH_SOURCE_ROOT='.$caseRoot);
  $process=proc_open([PHP_BINARY,'-d','display_errors=0','-d','log_errors=0',$fixture.'/endpoint.php',$case,$ledger],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
  current_auth_expect(is_resource($process),'Child process unavailable');fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$deadline=microtime(true)+15;
  do{$stdout.=stream_get_contents($pipes[1]);$stderr.=stream_get_contents($pipes[2]);$proc=proc_get_status($process);if(!$proc['running']){$status=$proc['exitcode'];break;}if(microtime(true)>$deadline){proc_terminate($process);throw new RuntimeException('Bounded child timeout');}usleep(10000);}while(true);
  $stdout.=stream_get_contents($pipes[1]);$stderr.=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);proc_close($process);
  $state=json_decode(file_get_contents($ledger),true,512,JSON_THROW_ON_ERROR);current_auth_expect(0===$status&&true===($state['success']??null),'Child assertion failed: '.($state['error']??$stderr?:'no completed receipt'));$response=json_decode($stdout,true,512,JSON_THROW_ON_ERROR);
  current_auth_expect(0===$status&&''===$stderr&&true===($state['success']??null)&&null===$state['shutdown_error'],'Child did not complete the named assertion');
  current_auth_expect(true===$state['markers_preserved'],'Protected markers changed');
  foreach($state['loaded']as$path=>$hash){current_auth_expect(isset($profile['files'][$path])&&hash_equals('source-tampered'===$case&&$path===$tamperedMember?$tamperedHash:$profile['files'][$path],$hash),'Unbound genuine dependency: '.$path);if('source-tampered'!==$case||$path!==$tamperedMember){$loaded[$path]=$hash;}}
  foreach($state['included']as$file=>$hash){if(!str_starts_with($file,$caseRoot.'/')){current_auth_expect(isset($profile['owned_files'][$file])&&hash_equals($profile['owned_files'][$file],$hash)||isset($profile['fixture_files'][basename($file)])&&str_starts_with($file,$fixture.'/')&&hash_equals($profile['fixture_files'][basename($file)],$hash),'Unbound included owner/seam dependency: '.$file);}}
  foreach($state['diagnostics']as$d){$diagnostics[$d['file'].':'.$d['line'].':'.$d['message']]=$d;}
  if(is_string($state['issued_token'])){$nativeFacts=current_auth_token_facts($state['issued_token']);}
  $metadataExpectation=modern_metadata_assert($case,$state,$nativeFacts);
  $events=array_column($state['events'],'kind');$cartEffects=['cart.token-built','customer.save','cart.set_session','cart.cookies','cookie-emitted','db-write','db-delete','timestamp-write','cache-write'];
  if(in_array($case,['gateway-prime-owned-throw','gateway-prime-generic-throw','source-tampered'],true)){
   current_auth_expect(['errors']===array_keys($response)&&0===$state['writes']&&true===$state['rows_preserved']&&true===$state['lifecycle_terminal']&&false===$state['owned_scope']&&true===$state['shutdown_dispatched'],'Native exception boundary cleanup/shutdown');foreach($cartEffects as$effect){current_auth_expect(!in_array($effect,$events,true),'Failed construction reached protected cart effect: '.$effect);}
   current_auth_expect(!in_array('token-built',$events,true)&&!isset($response['data']),'Failed construction published credentials/data');
   if('source-tampered'===$case){current_auth_expect(503===$state['response_status']&&'WL_CART_SESSION_UNAVAILABLE'===($response['errors'][0]['extensions']['code']??null)&&0===$state['reads']&&!in_array('begin',$state['calls'],true),'Actual source tamper escaped pre-acquisition fence');}
   else{$throws=array_values(array_filter($state['events'],fn($e)=>$e['kind']==='option-prime-backend-throw'));current_auth_expect(1===count($throws)&&'WC_Payment_Gateways'===$throws[0]['identity']['caller_class']&&'init'===$throws[0]['identity']['caller_method']&&1===count(array_filter($state['calls'],fn($call)=>$call==='abort'))&&'failed'===$state['driver_state'],'Genuine gateway priming failure reachability/abort');current_auth_expect(('gateway-prime-owned-throw'===$case?503:500)===$state['response_status']&&('gateway-prime-owned-throw'===$case?'WL_CART_SESSION_UNAVAILABLE':'COMPONENT_GENERIC_DELEGATED')===($response['errors'][0]['extensions']['code']??null)&&('gateway-prime-owned-throw'===$case?0:1)===$state['previous_handler_called'],'Owned versus generic installed handler routing');}
  }elseif('ordinary-flush'===$case){
   current_auth_expect(['data'=>['ok'=>true]]===$response&&1===$state['writes']&&!$state['rows_preserved'],'Ordinary owned flush positive control');
   $from=array_search('flush.start',$events,true);current_auth_expect(false!==$from,'Flush phase absent');$flush=array_slice($state['events'],$from+1);$kinds=array_column($flush,'kind');
   foreach(['cart.token-built'=>1,'customer.save'=>1,'cart.set_session'=>1,'cart.cookies'=>1,'cookie-emitted'=>2,'db-write'=>1,'scope-seal'=>1,'scope-release'=>1]as$kind=>$count){current_auth_expect($count===count(array_filter($kinds,fn($x)=>$x===$kind)),'Ordinary effect count: '.$kind);}
   $ordered=['customer.save','cart.set_session','meta-write','cart.cookies','db-write','scope-seal','scope-release'];$last=-1;foreach($ordered as$kind){$index=array_search($kind,$kinds,true);current_auth_expect(false!==$index&&$index>$last,'Ordinary flush owned order');$last=$index;}
   current_auth_expect(isset($state['rows']['17']['customer'])&&['cart'=>[]]===$state['meta'][17]['_woocommerce_persistent_cart_1'],'Genuine customer/session/persistent effects missing');
  }elseif('native-cookie'===$case){current_auth_expect(3===$state['writes']&&1===count(array_filter($events,fn($x)=>$x==='native-migration')),'Parent migration positive reachability');}
  elseif(in_array($case,['detached','absent-cart-token'],true)||str_starts_with($case,'refresh-')){
   current_auth_expect(0===$state['writes']&&true===$state['rows_preserved'],'Auth changed controlled session rows');foreach($cartEffects as$effect){current_auth_expect(!in_array($effect,$events,true),'Auth cart effect: '.$effect);}
   current_auth_expect(!in_array('callback.addToCart',$events,true)&&!in_array('replacement.refresh',$events,true),'Cart operation or replacement was replayed');
   if(in_array($case,['refresh-success','refresh-guest-success','refresh-alias-fragment','refresh-variable-directives'],true)){$payload=$response['data']['refreshToken']??$response['data']['renewed']??[];current_auth_expect(true===($payload['success']??$payload['ok']??null)&&is_string($payload['authToken']??$payload['token']??null)&&ctype_digit($payload['authTokenExpiration']??$payload['expires']??''),'Native refresh success payload');$bearer=$payload['authToken']??$payload['token'];$decoded=current_auth_token_facts($bearer);current_auth_expect($decoded===$nativeFacts,'Returned and issued native token differ');current_auth_expiration_assert($payload,$state,$decoded);current_auth_expect(1===count(array_filter($events,fn($x)=>$x==='native.identity-change'))&&true===$state['detail']['detached_before_identity'],'Native identity must follow owned detachment');current_auth_expect(17===$state['detail']['actor']&&('refresh-guest-success'===$case?0:17)===$state['detail']['starting_actor'],'Guest/same-actor renewal identity');}
   elseif(in_array($case,['refresh-invalid','refresh-expired','refresh-revoked','refresh-wrong-secret'],true)){current_auth_expect(false===($response['data']['refreshToken']['success']??null)&&null===($response['data']['refreshToken']['authToken']??null)&&!in_array('native.identity-change',$events,true),'Native credential negative control');}
   elseif(str_starts_with($case,'refresh-')){current_auth_expect(['errors']===array_keys($response)&&'WL_CART_SESSION_TRANSITION_INVALID'===($response['errors'][0]['extensions']['code']??null)&&(in_array($case,['refresh-contradictory','refresh-payload-drift'],true)?1===count(array_filter($events,fn($x)=>$x==='native.identity-change')):!in_array('native.identity-change',$events,true)),'Native transition negative control');}
   else{current_auth_expect(['data'=>['ok'=>true]]===$response,'Detached response');}

  }elseif('fresh-viewer'===$case){current_auth_expect(17===($response['data']['viewer']['databaseId']??null)&&false===$state['detail']['detached']&&false===$state['detail']['carried_cart_header']&&$state['rows']['t_'.str_repeat('a',30)]===['cart'=>['native-sentinel'=>true]],'Separate ordinary viewer native Bearer/cart exclusion');current_auth_expect(!in_array('native.identity-change',$events,true)&&1===$state['writes'],'Viewer owns its separate ordinary flush');}
  elseif(in_array($case,['invalid-cart-token','retired-cart-token','source-wrong','source-missing'],true)){current_auth_expect(0===$state['writes']&&0===$state['reads'],'Early refusal hydrated/wrote session');}
  current_auth_expect(!isset($response['errors'])||str_starts_with($case,'refresh-')||in_array($case,['gateway-prime-owned-throw','gateway-prime-generic-throw','source-tampered'],true),'Unexpected terminal refusal');
 }catch(Throwable$e){$failure=$e->getMessage();$failures++;}
 finally{putenv('WL_MODERN_AUTH_SOURCE_ROOT='.$root);if(is_file($ledger)){unlink($ledger);}if('fresh-viewer'===$case){putenv('WL_MODERN_AUTH_BEARER');}}
 $receipt=['metadata_expectation'=>$metadataExpectation,'verified_native_token_facts'=>$nativeFacts,'response'=>$response,'case'=>$case,'passed'=>null===$failure,'child_exit_status'=>$status,'failure'=>$failure,'stdout_sha256'=>hash('sha256',$stdout),'stderr'=>$stderr,'state'=>$state];if(is_array($receipt['state'])&&is_string($receipt['state']['issued_token']??null)){$receipt['state']['issued_token_sha256']=hash('sha256',$receipt['state']['issued_token']);unset($receipt['state']['issued_token']);}$results[]=$receipt;
 if($evidence){file_put_contents($evidence.'/'.$case.'.json',json_encode($receipt,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");chmod($evidence.'/'.$case.'.json',0600);}
 echo(null===$failure?'PASS ':'FAIL ').$case.(null===$failure?'':' ['.$failure.']')."\n";
}
$assertionControls=[];
$positive=[];foreach($results as$record){if($record['passed']){$positive[$record['case']]=$record;}}
$controlDefinitions=[
 'metadata-forged-actor'=>['customer-store',static function(&$state,&$payload){foreach($state['events']as&$event){if('meta-write'===$event['kind']){$event['identity']['id']=23;break;}}}],
 'metadata-forged-value'=>['customer-store',static function(&$state,&$payload){$state['meta'][17]['billing_city']='forged';foreach($state['events']as&$event){if('meta-write'===$event['kind']&&'billing_city'===$event['identity']['key']){$event['identity']['value']='forged';}}}],
 'metadata-forged-key'=>['customer-store',static function(&$state,&$payload){foreach($state['events']as&$event){if('meta-attempt'===$event['kind']){$event['identity']['key']='roles';break;}}}],
 'metadata-unrelated-actor-delta'=>['customer-store',static function(&$state,&$payload){$state['meta'][23]['roles']=['administrator'];}],
 'metadata-unexpected-delete'=>['customer-store',static function(&$state,&$payload){$state['events'][]=['kind'=>'meta-delete','identity'=>['id'=>23,'key'=>'graphql_login_secret','old_exists'=>true,'old_value'=>'fixture-user-secret']];}],
 'metadata-forged-previous'=>['activity-default-write',static function(&$state,&$payload){foreach($state['events']as&$event){if('meta-attempt'===$event['kind']){$event['identity']['previous']='forged';break;}}}],
 'expiration-forged-returned'=>['refresh-success',static function(&$state,&$payload){$payload['authTokenExpiration']=(string)((int)$payload['authTokenExpiration']+1);}],
 'expiration-forged-stored'=>['refresh-success',static function(&$state,&$payload){$state['meta'][17]['graphql_login_token_expiration']++;foreach($state['events']as&$event){if('meta-write'===$event['kind']){$event['identity']['value']++;}if('meta-attempt'===$event['kind']){$event['identity']['submitted']++;}}}],
];
foreach($controlDefinitions as$name=>[$sourceCase,$mutate]){
 $passed=false;$rejection=null;$inputHash=null;$positiveHash=null;
 if(isset($positive[$sourceCase])){
  $record=$positive[$sourceCase];$positiveHash=hash('sha256',json_encode($record,JSON_THROW_ON_ERROR));$state=$record['state'];$payload=$record['response']['data']['refreshToken']??[];$mutate($state,$payload);$inputHash=hash('sha256',json_encode([$state,$payload],JSON_THROW_ON_ERROR));
  try{if(str_starts_with($name,'expiration-')){current_auth_expiration_assert($payload,$state,$record['verified_native_token_facts']);}modern_metadata_assert($sourceCase,$state,$record['verified_native_token_facts']);}
  catch(RuntimeException$e){$passed=true;$rejection=$e->getMessage();}
 }
 if(!$passed){$failures++;}$control=['name'=>$name,'source_positive_case'=>$sourceCase,'passed'=>$passed,'source_positive_receipt_sha256'=>$positiveHash,'mutated_receipt_input_sha256'=>$inputHash,'rejection'=>$rejection];$assertionControls[]=$control;
 if($evidence){file_put_contents($evidence.'/'.$name.'.json',json_encode($control,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");}
 echo($passed?'PASS ':'FAIL ').$name."\n";
}
try{foreach($profile['files']as$file=>$hash){current_auth_file($root.'/'.$file,$hash);}foreach($profile['owned_files']as$file=>$hash){current_auth_file($file,$hash);}foreach($profile['fixture_files']as$file=>$hash){current_auth_file($fixture.'/'.$file,$hash);}foreach($profile['files']as$file=>$hash){current_auth_file($tamperedRoot.'/'.$file,$file===$tamperedMember?$tamperedHash:$hash);}current_auth_file($fixture.'/profile.json',$profileHash);}catch(Throwable$e){$failures++;echo 'FAIL source stability' ."\n";}
$summary=['schema'=>'wl-modern-auth-component-receipt/v2','php'=>PHP_VERSION,'cases'=>count($cases)+count($assertionControls),'child_cases'=>count($cases),'assertion_negative_controls'=>$assertionControls,'failures'=>$failures,'profile_sha256'=>hash_file('sha256',$fixture.'/profile.json'),'runner_sha256'=>hash_file('sha256',__FILE__),'source_root'=>$root,'materialized_php_bytes'=>$profile['materialized_bytes'],'loaded_dependencies'=>$loaded,'parent_native_decoder_source_hashes'=>['JWT'=>$profile['files']['vendor-prefixed/firebase/php-jwt/src/JWT.php'],'Key'=>$profile['files']['vendor-prefixed/firebase/php-jwt/src/Key.php']],'parent_diagnostics'=>$parentDiagnostics,'tamper_control'=>['root'=>$tamperedRoot,'member'=>$tamperedMember,'changed_bytes'=>1,'expected'=>$profile['files'][$tamperedMember],'actual'=>$tamperedHash],'diagnostics'=>array_values($diagnostics),'scope'=>'Finite early auth component with declared SQL/cache/user/settings/type-registry seams; ordinary control additionally dispatches native wp_loaded empty-cart hydration. No native callback/HTTP/site/installed-state/PHP8.2 or populated-cart admission.','native_admitted'=>false];
if($evidence){file_put_contents($evidence.'/summary.json',json_encode($summary,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");}
echo 'RESULT '.(count($cases)+count($assertionControls)).' cases ('.count($cases).' child, '.count($assertionControls).' assertion negatives), '.$failures.' failures; PHP '.PHP_VERSION.'; '.count($loaded).' genuine loaded files; '.count($diagnostics).' retained deprecation diagnostics'."\n";exit($failures?1:0);

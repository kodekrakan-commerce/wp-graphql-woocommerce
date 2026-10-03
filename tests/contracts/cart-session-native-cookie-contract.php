<?php
/** Genuine Headless Login AuthCookie::set_auth_cookie(7,false), genuine WP_Hook,
 * actual retained lifecycle registration and dormant callbacks. Auth/token/user,
 * settings and Woo objects are controlled substitutes. CLI only: no bootstrap,
 * SQL, HTTP or credential delivery proof. */
error_reporting(E_ALL); ini_set('display_errors','0'); ini_set('log_errors','0');
const COOKIE_CALLER_FIXTURE_SHA = '36d3676efebd96a57df5ac47b103c59d3e80920e7f64893e6c47e7312073ffa6';
const HEADLESS_COOKIE_SOURCE_SHA = '46e9dcd140183c147649804ad72900d335dd7c9c4af255a97c1827a80ad4a10a';
$fixture=__DIR__.'/cart-session-lifecycle-fixtures.php';
if(!hash_equals(COOKIE_CALLER_FIXTURE_SHA,hash_file('sha256',$fixture))){fwrite(STDERR,"Controlled native-caller fixture differs.\n");exit(2);}
putenv('WL_LIFECYCLE_FIXTURE_SHA='.COOKIE_CALLER_FIXTURE_SHA);putenv('WL_HEADLESS_COOKIE_SHA='.HEADLESS_COOKIE_SOURCE_SHA);
$failed=[];
foreach(['headless-cookie-dormant','headless-cookie-policy-denial']as $case){
 $ledger=tempnam(sys_get_temp_dir(),'wl-cookie-caller-'); chmod($ledger,0600);
 try{
  $proc=proc_open([PHP_BINARY,'-d','display_errors=0','-d','log_errors=0','-d','output_buffering=0',$fixture,$case,$ledger],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
  fclose($pipes[0]);$body=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($proc);
  $state=json_decode(file_get_contents($ledger),true);
  if(0!==$code||''!==$err||'{"data":{"nativeHeadlessCookieReturned":true}}'!==$body||1!==($state['headless_send_argument_count']??null)
   ||0!==($state['headless_expire']??null)||time()>=($state['headless_expiration']??0)||true!==($state['cleanup_terminal']??null)){throw new RuntimeException('Native caller compatibility failed.');}
 }catch(Throwable $e){$failed[]=$case;}finally{if(is_file($ledger)){unlink($ledger);}}
}
echo json_encode(['suite'=>'native-cookie-dormant','cases'=>2,'failed'=>$failed,'php'=>PHP_VERSION,'limits'=>'Genuine Headless Login call, genuine WP hooks, actual lifecycle; controlled auth/token/user/settings/Woo. CLI only, no credential delivery or complete authentication proof.'],JSON_THROW_ON_ERROR)."\n";
exit($failed?1:0);

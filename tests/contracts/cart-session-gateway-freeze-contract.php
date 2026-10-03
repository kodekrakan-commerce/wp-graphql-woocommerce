<?php
/** Native gateway factory/COD + real lifecycle guard_request, CLI isolated controls.
 * Declared settings/options/handler substitutes; no WP bootstrap/HTTP/SQL/order.
 */
error_reporting(E_ALL);ini_set('display_errors','0');ini_set('log_errors','0');
const GATEWAY_FIXTURE_SHA='1c84b1ba4bdc86320102cb56f6761aeee3b9be75c093163d8a2d71b26fb88037';
$fixture=__DIR__.'/cart-session-gateway-freeze-fixture.php';
if(!hash_equals(GATEWAY_FIXTURE_SHA,hash_file('sha256',$fixture))){fwrite(STDERR,"Gateway fixture source differs.\n");exit(2);}
$failed=[];$receipts=[];
foreach(['initial-and-repeat','unknown-factory','wrong-source','unknown-created-callback','late-change','unknown-completion'] as $case){
 $p=proc_open([PHP_BINARY,$fixture,$case],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);$receipt=json_decode($out,true);$receipts[]=$receipt;
 if($exit!==0||$err!==''||($receipt['passed']??null)!==true){$failed[]=$case;}
}
echo json_encode(['suite'=>'gateway-before-freeze','cases'=>6,'failed'=>$failed,'receipts'=>$receipts,'php'=>PHP_VERSION,'limits'=>'Actual native factory/COD/dispatcher and retained request guard; no site bootstrap, HTTP, SQL, account/order or installation proof'])."\n";exit($failed?1:0);

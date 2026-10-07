<?php
/** Source-only actual-class handoff/preflight regression. No DB or native journey
 * claim. PHP8.2+, WL_WORDPRESS_SOURCE, WL_MU_PLUGINS_SOURCE, WL_WPGRAPHQL_SOURCE.
 * Expected source pins are literal independent runner inputs, never runtime trust. */
error_reporting(E_ALL); ini_set('display_errors','0'); ini_set('log_errors','0');
const HANDOFF_LIFECYCLE_SHA='d5f2c190f910a8429bd2a3b33f29643708a3407cb192151c6ef0b47a0317d88a';
const HANDOFF_FIXTURE_SHA='ec61102328bd6c17cc6d64a4d8853b8b6d63729d74b3bd68d3192e4435dd013f';
const HANDOFF_PINS=[
    'wpdb'=>'e15403e90032dd0508811301505e491d4212de5152581a98f51b744a1a3903bd',
    'plugin'=>'d70b9e18d34ab3fe46e15548a5e8e089ea5920832f06a4d89a9d64b2f7938b0d',
    'WP_Hook'=>'b839c0e5672246bca8db1ab781ec8835f7732f253c375a237cbf6ec536e8d12e',
    'interface'=>'1bc7c915ec34b1ff16e8c9337241b5ff275a0600b16a16bdfaae127c88bdf1b8','driver'=>'d0392b5b040fed6c7bd790ef23ab1bba1a47c8b442772efb80b5787ebc8ca370','driver_error'=>'9605e536f838c170d0d0a179e63c5ed800236c514c55a6e2ec6ce007e94cd212',
    'boundary'=>'c3e929710f7394052025cde743c84496f4e452c5bfea8d71eb17452fd3464574','error'=>'2160de03b4bc14145523b5229808d7a34f941833502012f08586872706f649b3',
];
$owner=dirname(__DIR__,2);$wp=rtrim(getenv('WL_WORDPRESS_SOURCE')?:'','/');$mu=rtrim(getenv('WL_MU_PLUGINS_SOURCE')?:'','/');
$fixture=__DIR__.'/cart-session-owned-all-handoff-fixture.php';
$paths=['wpdb'=>$wp.'/wp-includes/class-wpdb.php','plugin'=>$wp.'/wp-includes/plugin.php','WP_Hook'=>$wp.'/wp-includes/class-wp-hook.php',
    'interface'=>$mu.'/database/interface-owned-scope-driver.php','driver'=>$mu.'/database/class-guarded-wpdb.php','driver_error'=>$mu.'/database/class-owned-scope-error.php',
    'boundary'=>$owner.'/includes/utils/class-cart-session-http-boundary.php','error'=>$owner.'/includes/utils/class-cart-session-error.php',
    'lifecycle'=>$owner.'/includes/utils/class-cart-session-lifecycle.php','fixture'=>$fixture];
$pins=HANDOFF_PINS+['lifecycle'=>HANDOFF_LIFECYCLE_SHA,'fixture'=>HANDOFF_FIXTURE_SHA];
foreach ($paths as $name=>$file) { if (!is_file($file)||!hash_equals($pins[$name],hash_file('sha256',$file))) { fwrite(STDERR,"Fixed actual-class handoff source differs: $name.\n"); exit(2); } }
if (!is_file(rtrim(getenv('WL_WPGRAPHQL_SOURCE')?:'','/').'/vendor/autoload.php')) { fwrite(STDERR,"Genuine GraphQL error dependency required.\n");exit(2); }
putenv('WL_HANDOFF_LIFECYCLE_SHA='.HANDOFF_LIFECYCLE_SHA);putenv('WL_HANDOFF_FIXTURE_SHA='.HANDOFF_FIXTURE_SHA);putenv('WL_HANDOFF_WP_HOOK_SHA='.HANDOFF_PINS['WP_Hook']);
$passing=['qualified','placeholder-query'];
$denied=['without-handoff','missing-pin','wrong-pin','malformed-pin','uninstalled','incomplete-baseline','terminal',
    'missing-all','missing-own','wrong-priority','wrong-arity','wrong-id','extra-entry-field','wrong-own-receiver',
    'missing-capability','magic-capability','private-capability','static-capability','wrong-version','noninterface-driver',
    'wrong-global','replaced-global','throwing-capability','active-driver','sealed-driver','failed-driver',
    'unknown-query','unknown-all','unknown-logging','manifest-foreign-all','later-callback','another-receiver','another-method','savequeries'];
$failures=0;
foreach ([...$passing,...$denied] as $case) {
    $process=proc_open([PHP_BINARY,'-d','display_errors=0','-d','log_errors=0','-d','output_buffering=0',$fixture,$case],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]);$raw=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);$result=json_decode($raw,true);
    $pass=in_array($case,$passing,true);
    $ok=$code===0 && $err==='' && is_array($result) && $result['case']===$case && $result['normalized']===true && $result['effects']===0
        && $result['preflight']===$pass && $result['rejected']===!$pass;
    if ($pass) { $ok=$ok && $result['only_exact_all_grant']===true && $result['baseline_stable_unfrozen']===true; }
    if (!$ok) { $failures++; } echo ($ok?'PASS ':'FAIL ').$case." [actual lifecycle/MU/core preflight; no DB]\n";
}
foreach ($paths as $name=>$file) { if (!hash_equals($pins[$name],hash_file('sha256',$file))) { $failures++; } }
echo 'RESULT '.(count($passing)+count($denied)).' cases, '.$failures." failures\n";
foreach($pins as $name=>$pin){echo 'SOURCE '.$name.' '.$pin."\n";}
exit($failures?1:0);

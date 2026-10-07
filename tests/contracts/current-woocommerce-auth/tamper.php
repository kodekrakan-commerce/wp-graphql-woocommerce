<?php
/** Explicit negative-input materialization; never modifies the accepted source root. */
$profile=json_decode(file_get_contents(__DIR__.'/profile.json'),true,512,JSON_THROW_ON_ERROR);
$source=rtrim($argv[1]??'','/');$destination=rtrim($argv[2]??'','/');
if(!$source||!$destination||$source===$destination||!is_dir($source)){fwrite(STDERR,"Supply distinct accepted-source and private negative-input directories.\n");exit(2);}
$member='woocommerce/includes/class-wc-customer.php';$bytes=0;$offset=null;$originalHash=null;$actualHash=null;
try{
 foreach($profile['files']as$relative=>$expected){
  if(str_contains($relative,'..')||!str_ends_with($relative,'.php')){throw new RuntimeException('Unsafe selected PHP member');}
  $file=$source.'/'.$relative;if(!is_file($file)||!hash_equals($expected,hash_file('sha256',$file))){throw new RuntimeException('Accepted source differs from fixed profile');}
  $data=file_get_contents($file);$bytes+=strlen($data);if($bytes>100*1024*1024){throw new RuntimeException('Selected-code bound exceeded');}
  if($relative===$member){$offset=strpos($data,'WooCommerce');if(false===$offset){throw new RuntimeException('Fixed comment sentinel unavailable');}$originalHash=hash('sha256',$data);$data[$offset]='w';$actualHash=hash('sha256',$data);}
  $target=$destination.'/'.$relative;$hash=hash('sha256',$data);
  if(is_file($target)){if(!hash_equals($hash,hash_file('sha256',$target))){throw new RuntimeException('Existing negative input differs; refusing overwrite');}continue;}
  if(!is_dir(dirname($target))&&!mkdir(dirname($target),0700,true)){throw new RuntimeException('Private negative directory unavailable');}
  if(false===file_put_contents($target,$data)){throw new RuntimeException('Negative-input write failed');}chmod($target,0600);
 }
 if($bytes!==$profile['materialized_bytes']||null===$actualHash||$actualHash===$originalHash){throw new RuntimeException('Negative-input bound/sentinel differs');}
 echo json_encode(['member'=>$member,'changed_bytes'=>1,'offset'=>$offset,'expected_sha256'=>$originalHash,'actual_sha256'=>$actualHash,'selected_php_bytes'=>$bytes,'fixed_profile_changed'=>false],JSON_THROW_ON_ERROR)."\n";
}catch(Throwable$e){fwrite(STDERR,$e->getMessage()."\n");exit(2);}

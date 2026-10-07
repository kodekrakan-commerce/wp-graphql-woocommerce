<?php
/** Materialize only the explicit, hash-bound PHP members; no installation/bootstrap. */
$profile=json_decode(file_get_contents(__DIR__.'/profile.json'),true,512,JSON_THROW_ON_ERROR);
$root=$argv[1]??'';if(!$root||file_exists($root)&&!is_dir($root)){fwrite(STDERR,"Supply a private code-only destination directory.\n");exit(2);}
$archives=[];$bytes=0;
try{
 foreach($profile['archives']+['headless'=>$profile['headless_archive']]as$key=>$input){if(!is_file($input['path'])||!hash_equals($input['sha256'],hash_file('sha256',$input['path']))){throw new RuntimeException('Artifact hash mismatch');}$zip=new ZipArchive();if(true!==$zip->open($input['path'])){throw new RuntimeException('Artifact unavailable');}$archives[$key]=$zip;}
 foreach($profile['files']as$path=>$hash){
  if(str_contains($path,'..')||str_starts_with($path,'/')||!str_ends_with($path,'.php')){throw new RuntimeException('Unsafe selected member');}
  $key=str_starts_with($path,'woocommerce/')?'woocommerce@11.1.2':(str_starts_with($path,'wordpress/')?'wordpress@7.1.3':(str_starts_with($path,'wp-graphql/')?'wp-graphql@2.23.1':'headless'));
  $data=$archives[$key]->getFromName($path);if(false===$data||!hash_equals($hash,hash('sha256',$data))){throw new RuntimeException('Selected member hash mismatch');}
  $bytes+=strlen($data);if($bytes>100*1024*1024){throw new RuntimeException('100 MiB code-only bound exceeded');}
  $target=rtrim($root,'/').'/'.$path;if(is_file($target)){if(!hash_equals($hash,hash_file('sha256',$target))){throw new RuntimeException('Existing target differs; refusing overwrite');}continue;}
  if(!is_dir(dirname($target))&&!mkdir(dirname($target),0700,true)){throw new RuntimeException('Private destination unavailable');}
  if(false===file_put_contents($target,$data)){throw new RuntimeException('Materialization write failed');}chmod($target,0600);
 }
 if($bytes!==$profile['materialized_bytes']){throw new RuntimeException('Materialized size differs from profile');}
 echo 'MATERIALIZED '.count($profile['files']).' exact PHP members, '.$bytes.' bytes; no plugin installed or entry point loaded.'."\n";
}catch(Throwable$e){fwrite(STDERR,$e->getMessage()."\n");exit(2);}finally{foreach($archives as$zip){$zip->close();}}

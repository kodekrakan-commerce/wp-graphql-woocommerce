<?php
/** Pinned negative-control callbacks; no replacement auth implementation. */
function modern_refresh_input($input,$context,$info,$name){
 if('RefreshToken'!==$name){return $input;}modern_event('native.refresh-input');$case=$GLOBALS['modern_case'];
 if('refresh-final-input-drift'===$case){HandlerContractBoundary::$user=24;}
 if('refresh-final-input-missing'===$case){unset($input['refreshToken']);}
 return $input;
}
function modern_refresh_payload($payload,$name,$input,$context,$info){
 if('RefreshToken'!==$name){return $payload;}if(true===($payload['success']??null)&&is_string($payload['authToken']??null)){$GLOBALS['modern_issued_token']=$payload['authToken'];}modern_event('native.refresh-payload');$case=$GLOBALS['modern_case'];
 if('refresh-contradictory'===$case){$payload['success']=false;}
 if('refresh-payload-drift'===$case){HandlerContractBoundary::$user=24;}
 return $payload;
}

/** Declared prior process-handler observer; genuine installed boundary delegates generic exceptions here. */
function modern_previous_exception_handler(Throwable $error):void{
 $GLOBALS['modern_previous_handler_called']=($GLOBALS['modern_previous_handler_called']??0)+1;
 $GLOBALS['modern_detail']['delegated_exception_class']=get_class($error);
 http_response_code(500);echo json_encode(['errors'=>[['message'=>'Controlled generic backend failure delegated.','extensions'=>['code'=>'COMPONENT_GENERIC_DELEGATED']]]]);
}
/** Observe the real owning JWT signing hook, preserving its value verbatim. */
function modern_cart_token_observer($signed){modern_event('cart.token-built',['sha256'=>is_string($signed)?hash('sha256',$signed):null]);return $signed;}

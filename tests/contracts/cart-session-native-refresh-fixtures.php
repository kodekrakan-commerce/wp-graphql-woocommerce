<?php
/** Fixture-only user/meta seams. Native RefreshToken/TokenManager/User/JWT remain genuine. */
final class WP_User {
    public int $ID;
    public function __construct($id) { $this->ID=(int)$id; }
}
function sanitize_text_field($value) { return trim((string)$value); }
function get_user_by($field,$id) { return in_array((int)$id,[17,23,24],true) ? new WP_User($id) : false; }
function current_user_can($capability) { return false; }
function wp_set_current_user($id) {
    integration_event('native.identity-change');
    $GLOBALS['refresh_detached_before_identity']=WC()->session->is_auth_detached() && !WC()->session->has_owned_scope() && 'released'===$GLOBALS['integration_db']->state;
    $handler=WC()->session;
    $GLOBALS['refresh_authority_cleared']=[]===(new ReflectionProperty($handler,'_data'))->getValue($handler);
    foreach (['_has_token','_has_cookie','_issuing_new_token','_issuing_new_cookie'] as $property) { $GLOBALS['refresh_authority_cleared']=$GLOBALS['refresh_authority_cleared'] && false===(new ReflectionProperty($handler,$property))->getValue($handler); }
    HandlerContractBoundary::$user=(int)$id;
    do_action('set_current_user');
    return new WP_User($id);
}
function get_user_meta($id,$key,$single=false) { return $GLOBALS['refresh_meta'][(int)$id][$key]??''; }
function update_user_meta($id,$key,$value) {
    integration_event('native.meta.'.$key);
    $GLOBALS['refresh_meta'][(int)$id][$key]=$value;
    do_action('updated_user_meta',1,(int)$id,$key,$value);
    return true;
}
function graphql_debug($message) { integration_event('native.debug'); }
function refresh_input($input,$context,$info,$name) {
    if ('RefreshToken'!==$name) { return $input; }
    integration_event('native.refresh-input');
    if ('refresh-final-input-token'===$GLOBALS['integration_current_case']) {unset($input['refreshToken']);}
    if ('refresh-final-input-drift'===$GLOBALS['integration_current_case']) { HandlerContractBoundary::$user=24; }
    return $input;
}
function refresh_pre($pre,$name,$callback,$input,$context,$info) {
    if ('RefreshToken'===$name && 'refresh-pre-substitution'===$GLOBALS['integration_current_case']) { return ['success'=>true,'authToken'=>'fixture-must-not-publish','authTokenExpiration'=>123]; }
    return $pre;
}
function refresh_payload($payload,$name,$input,$context,$info) {
    if ('RefreshToken'!==$name) { return $payload; }
    integration_event('native.refresh-payload');
    $case=$GLOBALS['integration_current_case'];
    if ('refresh-payload-drift'===$case) { HandlerContractBoundary::$user=24; }
    if ('refresh-storage-failure'===$case) { $GLOBALS['integration_db']->report_failed=true; }
    if ('refresh-cohort-drift'===$case) { $GLOBALS['wp_filter']['graphql_mutation_input']=clone $GLOBALS['wp_filter']['graphql_mutation_input']; }
    if ('refresh-no-actor'===$case) { HandlerContractBoundary::$user=0; }
    if ('refresh-string-success'===$case) { $payload['success']='true'; }
    if ('refresh-null-expiration'===$case) { unset($payload['authTokenExpiration']); }
    if ('refresh-missing-success'===$case) { unset($payload['success']); }
    if ('refresh-empty-token'===$case) { $payload['authToken']=''; }
    if ('refresh-string-expiration'===$case) { $payload['authTokenExpiration']=(string)$payload['authTokenExpiration']; }
    if ('refresh-zero-expiration'===$case) { $payload['authTokenExpiration']=0; }
    if ('refresh-false-with-token'===$case) { $payload['success']=false; }
    if ('refresh-false-with-expiration'===$case) { $payload=['success'=>false,'authTokenExpiration'=>123]; HandlerContractBoundary::$user=0; }
    return $payload;
}
function refresh_response_drift($payload,$input,$unfiltered,$context,$info,$name) {
    if ('RefreshToken'===$name && 'refresh-postcallback-drift'===$GLOBALS['integration_current_case']) { HandlerContractBoundary::$user=24; }
}
$native=rtrim(getenv('WL_HEADLESS_LOGIN_SOURCE')?:'','/');
spl_autoload_register(static function($class)use($native){
    $prefix='WPGraphQL\\Login\\'; if (!str_starts_with($class,$prefix)) { return; }
    $name=substr($class,strlen($prefix));
    $file=$native.(str_starts_with($name,'Vendor\\')?'/vendor-prefixed/'.substr($name,7):'/src/'.$name);
    if (str_starts_with($name,'Vendor\\Firebase\\JWT\\')) { $file=$native.'/vendor-prefixed/firebase/php-jwt/src/'.substr($name,strlen('Vendor\\Firebase\\JWT\\')); }
    elseif (str_starts_with($name,'Vendor\\AxeWP\\GraphQL\\')) { $file=$native.'/vendor-prefixed/axepress/wp-graphql-plugin-boilerplate/src/'.substr($name,strlen('Vendor\\AxeWP\\GraphQL\\')); }
    $file=str_replace('\\','/',$file).'.php'; if(is_file($file)){require $file;}
});
define('GRAPHQL_LOGIN_JWT_SECRET_KEY',str_repeat('fixture-native-key-',4));
define('DAY_IN_SECONDS',86400);
$GLOBALS['refresh_meta']=[23=>['graphql_login_secret'=>'fixture-user-secret','graphql_login_secret_revoked'=>false,'graphql_login_refresh_token_expiration'=>time()+86400,'persistent_cart'=>['unchanged'=>true]]];
$GLOBALS['refresh_meta'][17]=$GLOBALS['refresh_meta'][23];
$GLOBALS['refresh_meta_original']=$GLOBALS['refresh_meta'];
$GLOBALS['refresh_orders']=['fixture-order'=>['status'=>'pending','meta'=>['retirement'=>'preserved']]];
$GLOBALS['refresh_orders_original']=$GLOBALS['refresh_orders'];
$GLOBALS['refresh_detached_before_identity']=null;
// Load and hash actual classes before any owning lifecycle fence is installed.
$GLOBALS['refresh_sources']=[];
foreach ([\WPGraphQL\Login\Mutation\RefreshToken::class,\WPGraphQL\Login\Auth\TokenManager::class,\WPGraphQL\Login\Auth\User::class,\WPGraphQL\Login\Utils\Utils::class,\WPGraphQL\Login\Vendor\Firebase\JWT\JWT::class,\WPGraphQL\Login\Vendor\Firebase\JWT\Key::class] as $class) {
    new ReflectionClass($class);
    $GLOBALS['refresh_sources'][$class]=(json_decode(getenv('WL_REFRESH_NATIVE_SOURCES')?:'{}',true)[$class]??null);
}
function refresh_query($case) {
    $id='refresh-different-actor'===$case?24:(in_array($case,['refresh-same-actor','refresh-viewer-seed'],true)?17:23);
    $claims=['iss'=>get_bloginfo('url'),'iat'=>time()-5,'nbf'=>time()-5,'exp'=>'refresh-expired'===$case?time()-120:time()+86400,'data'=>['user'=>['id'=>$id,'user_secret'=>'fixture-user-secret']]];
    $token=\WPGraphQL\Login\Vendor\Firebase\JWT\JWT::encode($claims,GRAPHQL_LOGIN_JWT_SECRET_KEY,'HS256');
    if (in_array($case,['refresh-invalid','refresh-invalid-authenticated'],true)) { $token='invalid'; }
    if ('refresh-revoked'===$case) { $GLOBALS['refresh_meta'][23]['graphql_login_secret_revoked']=true; $GLOBALS['refresh_meta_original']=$GLOBALS['refresh_meta']; }
    if ('refresh-wrong-secret'===$case) { $GLOBALS['refresh_meta'][23]['graphql_login_secret']='different'; $GLOBALS['refresh_meta_original']=$GLOBALS['refresh_meta']; }
    if ('refresh-different-actor'===$case) { $GLOBALS['refresh_meta'][24]=$GLOBALS['refresh_meta'][23]; $GLOBALS['refresh_meta_original']=$GLOBALS['refresh_meta']; }
    $root='refreshToken(input:{refreshToken:'.json_encode($token).'})';
    return match($case) {
        'refresh-fresh-viewer','refresh-expired-bearer'=>'query { viewer { databaseId } }',
        'refresh-mixed-first'=>'mutation { '.$root.' { success } addToCart(input:{}) { success } }',
        'refresh-mixed-last'=>'mutation { addToCart(input:{}) { success } '.$root.' { success } }',
        'refresh-two-aliases'=>'mutation { a:'.$root.' { success } b:'.$root.' { success } }',
        'refresh-login'=>'mutation { '.$root.' { success } login(input:{provider:PASSWORD}) { authToken } }',
        'refresh-logout'=>'mutation { '.$root.' { success } logout(input:{}) { success } }',
        'refresh-forbidden-user'=>'mutation { '.$root.' { user { databaseId } } }',
        'refresh-forbidden-customer'=>'mutation { '.$root.' { customer { sessionToken } } }',
        'refresh-forbidden-cart'=>'mutation { '.$root.' { cart { success } } }',
        'refresh-forbidden-session'=>'mutation { '.$root.' { sessionToken } }',
        'refresh-variable-directives'=>'mutation($run:Boolean!,$skipCart:Boolean!) { renewed:'.$root.' @include(if:$run) { ok:success token:authToken expires:authTokenExpiration } addToCart(input:{}) @skip(if:$skipCart) { success } }',
        'refresh-alias-fragment'=>'mutation { renewed:'.$root.' { ...Result ... on RefreshTokenPayload { clientMutationId __typename } } addToCart(input:{}) @skip(if:true) { success } } fragment Result on RefreshTokenPayload { ok:success token:authToken expires:authTokenExpiration }',
        default=>'mutation { '.$root.' { success authToken authTokenExpiration clientMutationId __typename } }',
    };
}

function refresh_meta_preserved() {
    $meta=$GLOBALS['refresh_meta']; $original=$GLOBALS['refresh_meta_original'];
    foreach ($meta as &$values) { unset($values['graphql_login_token_expiration']); } unset($values);
    foreach ($original as &$values) { unset($values['graphql_login_token_expiration']); } unset($values);
    return $meta===$original;
}

function refresh_source_fault($case,array $sources) {
    if ('refresh-missing-source'===$case) { unset($sources[\WPGraphQL\Login\Mutation\RefreshToken::class]); }
    if ('refresh-wrong-source'===$case) { $sources[\WPGraphQL\Login\Auth\TokenManager::class]=str_repeat('0',64); }
    return $sources;
}

function refresh_expired_bearer() {
    return \WPGraphQL\Login\Vendor\Firebase\JWT\JWT::encode(['iss'=>get_bloginfo('url'),'iat'=>time()-600,'nbf'=>time()-600,'exp'=>time()-120,'data'=>['user'=>['id'=>17]]],GRAPHQL_LOGIN_JWT_SECRET_KEY,'HS256');
}

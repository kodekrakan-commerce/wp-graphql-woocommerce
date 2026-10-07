<?php
/** Type-registry constructor seam only; genuine WPMutationType resolver and native factory. */
final class Modern_Component_Mutation extends \WPGraphQL\Type\WPMutationType {
 public function __construct($name,$callback){$this->mutation_name='refreshToken'===$name?'RefreshToken':$name;$this->config=['mutateAndGetPayload'=>$callback];}
 public function resolver(){return $this->get_resolver();}
}
function modern_refresh_execute($case){
 $schema=\GraphQL\Utils\BuildSchema::build('input RefreshTokenInput {refreshToken:String! clientMutationId:String} input EmptyInput {clientMutationId:String} type RefreshTokenPayload {success:Boolean authToken:String authTokenExpiration:String clientMutationId:String} type CartPayload {success:Boolean} type User {databaseId:Int} type Query {viewer:User} type Mutation {refreshToken(input:RefreshTokenInput!):RefreshTokenPayload addToCart(input:EmptyInput!):CartPayload}');
 $native=\WPGraphQL\Login\Mutation\RefreshToken::mutate_and_get_payload();
 if('refresh-wrong-factory'===$case){$native=static function(){modern_event('replacement.refresh');return ['success'=>true];};}
 $schema->getMutationType()->getField('refreshToken')->resolveFn=(new Modern_Component_Mutation('refreshToken',$native))->resolver();
 $schema->getMutationType()->getField('addToCart')->resolveFn=(new Modern_Component_Mutation('addToCart',static function(){modern_event('callback.addToCart');return ['success'=>true];}))->resolver();
 $schema->getQueryType()->getField('viewer')->resolveFn=static fn()=>['databaseId'=>get_current_user_id()];
 foreach($schema->getTypeMap()as$type){if($type instanceof \GraphQL\Type\Definition\ObjectType && !str_starts_with($type->name,'__')){\WPGraphQL\Utils\InstrumentSchema::instrument_resolvers($type,$type->name);}}
 $claims=['iss'=>get_bloginfo('url'),'iat'=>time()-5,'nbf'=>time()-5,'exp'=>'refresh-expired'===$case?time()-120:time()+86400,'data'=>['user'=>['id'=>17,'user_secret'=>'fixture-user-secret']]];
 $token=\WPGraphQL\Login\Vendor\Firebase\JWT\JWT::encode($claims,GRAPHQL_LOGIN_JWT_SECRET_KEY,'HS256');
 if('refresh-invalid'===$case){$token='invalid';}if('refresh-revoked'===$case){$GLOBALS['modern_meta'][17]['graphql_login_secret_revoked']=true;}if('refresh-wrong-secret'===$case){$GLOBALS['modern_meta'][17]['graphql_login_secret']='wrong';}
 modern_metadata_checkpoint();
 $field='refreshToken(input:{refreshToken:'.json_encode($token).'})';
 $query=match($case){'refresh-mixed-first'=>'mutation {'.$field.'{success} addToCart(input:{}){success}}','refresh-mixed-last'=>'mutation {addToCart(input:{}){success} '.$field.'{success}}','refresh-two-aliases'=>'mutation {a:'.$field.'{success} b:'.$field.'{success}}','refresh-alias-fragment'=>'mutation {renewed:'.$field.' {...Result} addToCart(input:{}) @skip(if:true){success}} fragment Result on RefreshTokenPayload {ok:success token:authToken expires:authTokenExpiration}', 'refresh-variable-directives'=>'mutation($run:Boolean!,$skipCart:Boolean!) {renewed:'.$field.' @include(if:$run){success authToken authTokenExpiration} addToCart(input:{}) @skip(if:$skipCart){success}}','fresh-viewer'=>'query{viewer{databaseId}}',default=>'mutation {'.$field.'{success authToken authTokenExpiration clientMutationId}}'};
 return \GraphQL\GraphQL::executeQuery($schema,$query,null,(new ReflectionClass(\WPGraphQL\AppContext::class))->newInstanceWithoutConstructor(),'refresh-variable-directives'===$case?['run'=>true,'skipCart'=>true]:null);
}

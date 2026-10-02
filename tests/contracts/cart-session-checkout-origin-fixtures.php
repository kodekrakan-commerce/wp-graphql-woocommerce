<?php
/** Dormant checkout origin composition: actual retained closure/customer branch;
 * genuine WP_Hook/Executor/InstrumentSchema/WPMutationType. Controlled WC/SQL/auth
 * and a before-checkout reflection boundary stop before order/process_checkout. */
namespace {
	error_reporting( E_ALL ); ini_set( 'display_errors', '0' ); ini_set( 'log_errors', '0' );
	function integration_translate( $text ) { $GLOBALS['integration_translations']++; if ( $GLOBALS['integration_forbid_translation'] ) { throw new \RuntimeException( 'Synthetic translation boundary forbidden.' ); } return $text; }
	function __( $text, $domain = null ) { return integration_translate( $text ); }
	function esc_html( $value ) { return $value; }
	function integration_event( $event ) { HandlerContractBoundary::event( $event ); }
	final class WC_Customer {
		public function __construct() { add_action( 'shutdown', [ $this, 'save' ], 10, 0 ); }
		public function get_id() { return HandlerContractBoundary::$user; }
		public function get_billing_country() { return 'PT'; }
		public function get_shipping_country() { return 'PT'; }
		public function save() {
			integration_event( 'customer.save' );
			// Native guest customer session datastore delegates its save to set().
			WC()->session->set( 'customer', [ 'synthetic' => true ] );
			if ( $GLOBALS['integration_marker_case'] ?? false ) {
				$GLOBALS['integration_customer_save_inert'] = 'unchanged' === WC()->session->get( 'customer', 'unchanged' );
			}
		}
	}
	final class WC_Cart {
		public $session;
		public function __construct() { $this->session = new WC_Cart_Session( $this ); add_action( 'woocommerce_add_to_cart', [ $this, 'calculate_totals' ], 20, 0 ); }
		public function calculate_totals() { integration_event( 'cart.calculate' ); }
		public function needs_shipping_address() { return false; }
	}
	final class WC_Cart_Session {
		protected $cart;
		public function __construct( $cart ) {
			$this->cart = $cart;
			$GLOBALS['integration_creation_order'] = null === WC()->cart && null !== WC()->customer && false !== has_action( 'shutdown', [ WC()->customer, 'save' ] );
			$enabled = apply_filters( 'woocommerce_cart_session_initialize', true, $this );
			$GLOBALS['integration_cart_session_enabled'] = $enabled;
			if ( ! $enabled ) { return; }
			add_action( 'shutdown', [ $this, 'maybe_set_cart_cookies' ], 20, 0 );
			add_action( 'woocommerce_after_calculate_totals', [ $this, 'set_session' ], 10, 0 );
		}
		public function set_session() { integration_event( 'cart.set_session' ); WC()->session->set( 'cart', 'synthetic-final-cart' ); }
		public function persistent_cart_update() { integration_event( 'cart.persistent' ); }
		public function maybe_set_cart_cookies() {
			integration_event( 'cart.cookies' ); header( 'Set-Cookie: woocommerce_cart_hash=synthetic; Path=/', false );
			// Controlled cart/cookie implementation, genuine native hook dispatcher:
			// actual WC_Cart_Session::set_cart_cookies() ends with this action.
			do_action( 'woocommerce_set_cart_cookies', true );
		}
	}
	$owner = dirname( __DIR__, 2 );
	$wp = rtrim( getenv( 'WL_WORDPRESS_SOURCE' ) ?: '', '/' );
	$wc = rtrim( getenv( 'WL_WOOCOMMERCE_SOURCE' ) ?: '', '/' );
	$gql = rtrim( getenv( 'WL_WPGRAPHQL_SOURCE' ) ?: '', '/' );
	$mu = rtrim( getenv( 'WL_MU_PLUGINS_SOURCE' ) ?: '', '/' );
	define( 'WOOGRAPHQL_ACTUAL_LIFECYCLE_CONTRACT', true );
	require $wp . '/wp-includes/plugin.php';
	require $mu . '/database/interface-owned-scope-driver.php';
	require $mu . '/database/class-owned-scope-error.php';
	require __DIR__ . '/cart-session-owned-handler-fixtures.php';
	define( 'ABSPATH', __DIR__ . '/' ); define( 'COOKIEHASH', 'synthetic-owned-contract' ); define( 'DB_NAME', 'offline_synthetic_contract' );
	define( 'WC_SESSION_CACHE_GROUP', 'woocommerce_sessions' ); define( 'MINUTE_IN_SECONDS', 60 );
	define( 'GRAPHQL_WOOCOMMERCE_SECRET_KEY', str_repeat( 'k', 32 ) );
	foreach ( [ '/vendor/autoload.php', '/includes/abstracts/abstract-wc-session.php', '/includes/class-wc-session-handler.php', '/src/Caching/CacheNameSpaceTrait.php', '/includes/class-wc-cache-helper.php' ] as $file ) { require $wc . $file; }
	require $gql . '/vendor/autoload.php';
	require $gql . '/src/AppContext.php';
	require $gql . '/src/Utils/InstrumentSchema.php';
	require $gql . '/src/Type/WPMutationType.php';
	require $gql . '/src/Registry/TypeRegistry.php';
	require $owner . '/vendor/autoload.php';
	foreach ( [ 'class-cart-session-error.php', 'class-cart-session-operation.php', 'class-cart-session-storage.php', 'class-session-transaction-manager.php', 'class-cart-session-http-boundary.php', 'class-cart-session-lifecycle.php', 'class-ql-session-handler.php' ] as $file ) { require $owner . '/includes/utils/' . $file; }

	require $owner . '/includes/mutation/class-checkout.php';
	require $owner . '/includes/data/mutation/class-checkout-mutation.php';
	use WPGraphQL\WooCommerce\Utils\QL_Session_Handler;
	use WPGraphQL\WooCommerce\Vendor\Firebase\JWT\JWT;
	use GraphQL\Utils\BuildSchema;
	use GraphQL\GraphQL;
	use GraphQL\Type\Definition\ObjectType;
	use WPGraphQL\Utils\InstrumentSchema;
	final class Integration_Mutation extends \WPGraphQL\Type\WPMutationType {
		/** Constructor/materialization substitute preserves config.name exactly,
		 * matching actual TypeRegistry. Native-registration case uses both actual
		 * registry registration and actual WPMutationType constructor/resolver. */
		public function __construct( $name, $callback ) { $this->mutation_name = $name; $this->config = [ 'mutateAndGetPayload' => $callback ]; }
		public function resolver() { return $this->get_resolver(); }
		public function replace($entry) { $this->config['mutateAndGetPayload']=$entry; }
	}
	/** Native registration executes; only schema type materialization is recorded. */
	final class Origin_Native_Registry extends \WPGraphQL\Registry\TypeRegistry {
		public $fields = [];
		public function __construct() {}
		public function get_excluded_mutations(): array { return []; }
		public function has_type(string $name): bool { return false; }
		public function register_object_type(string $name,array $config): void {}
		public function register_input_type(string $name,array $config): void {}
		public function register_field(string $type,string $name,array $config): void { $this->fields[$name]=$config; }
	}
	function register_graphql_mutation($name,array $config) {
		$GLOBALS['origin_registration_name']=$name;
		$GLOBALS['origin_registry']->register_mutation($name,$config);
	}
	function get_option($name) { return 'yes'; }
	function wc_ship_to_billing_address_only() { return false; }
	function wc_create_new_customer(...$args) { $GLOBALS['origin_effects']['account']++; throw new \RuntimeException('Native creation forbidden.'); }
	function wc_set_customer_auth_cookie(...$args) { $GLOBALS['origin_effects']['auth']++; throw new \RuntimeException('Auth forbidden.'); }
	function is_multisite() { return false; }
	final class Origin_Woo { public $session; public $customer; public $cart; public function checkout(){return new \stdClass();} }
	function origin_expect($value) { if(!$value){throw new \RuntimeException('Origin contract assertion failed.');} }
	function origin_registration($required) { if($GLOBALS['origin_final']){$GLOBALS['origin_final_policy_calls']++;return $GLOBALS['origin_case']==='late-policy';}return false; }
	function origin_input($input,$context,$info,$name) {
		if($name==='checkout'&&$GLOBALS['origin_case']==='filtered-input'){$input['clientMutationId']='filtered';}
		return $input;
	}
	function origin_response($payload,$input,$unfiltered,$context,$info,$name) { $GLOBALS['origin_response_calls']++; }
	function origin_pre($pre,$name,$callback,$input,$context,$info) {
		if($name!=='checkout'){return $pre;} $case=$GLOBALS['origin_case'];$GLOBALS['origin_info']=$info;$GLOBALS['origin_context']=$context;
		if($case==='nonnull-pre'){return ['id'=>1];}
		if($case==='nonnull-pre-callback'){return static function(){ $GLOBALS['origin_effects']['account']++;return []; };}
		if($case==='same-source-replacement'){$GLOBALS['origin_mutation']->replace(\WPGraphQL\WooCommerce\Mutation\Checkout::mutate_and_get_payload());}
		if($case==='wrong-closure'){
			$wrong=static function($actual,$ctx,$ri)use(&$wrong){WC()->session->begin_checkout($wrong,$actual,$ctx,$ri);$GLOBALS['origin_effects']['replacement']++;return [];};
			$GLOBALS['origin_mutation']->replace($wrong);
		}
		if(in_array($case,['input-drift','context-drift','info-drift','schema-drift','operation-drift','path-drift','entry-registry-swap'],true)){
			$GLOBALS['origin_mutation']->replace(static function($actual,$ctx,$ri)use($callback,$case){
				if($case==='input-drift'){$actual['clientMutationId']='changed';}
				if($case==='context-drift'){$ctx=(new \ReflectionClass(\WPGraphQL\AppContext::class))->newInstanceWithoutConstructor();}
				if($case==='info-drift'){$ri=clone $ri;}
				if($case==='schema-drift'){$ri->schema=clone $ri->schema;}
				if($case==='operation-drift'){$ri->operation=clone $ri->operation;}
				if($case==='path-drift'){$ri->path=['different'];}
				if($case==='entry-registry-swap'){$GLOBALS['wp_filter']['graphql_mutation_payload']=clone $GLOBALS['wp_filter']['graphql_mutation_payload'];}
				return $callback($actual,$ctx,$ri);
			});
		}
		if($case==='registry-swap'){$GLOBALS['wp_filter']['graphql_mutation_payload']=clone $GLOBALS['wp_filter']['graphql_mutation_payload'];}
		if($case==='tail-remove'){
			foreach($GLOBALS['wp_filter']['graphql_pre_mutate_and_get_payload']->callbacks[PHP_INT_MAX] as $entry){remove_filter('graphql_pre_mutate_and_get_payload',$entry['function'],PHP_INT_MAX);}
		}
		if($case==='tail-append'){add_filter('graphql_pre_mutate_and_get_payload','origin_added_tail',PHP_INT_MAX,6);}
		return $pre;
	}
	function origin_added_tail($value,...$args) { return $value; }
	function origin_posted($data,$input,$context,$info) {
		$GLOBALS['origin_prepare_calls']++;
		if($GLOBALS['origin_case']==='prepare-throw'){throw new \RuntimeException('Controlled prepare failure.');}
		if($GLOBALS['origin_case']==='recursive-entry'){$GLOBALS['origin_entry']($input,$context,$info);}
		if($GLOBALS['origin_case']==='recursive-capture'){
			$op=(new \ReflectionProperty(QL_Session_Handler::class,'owned_operation'))->getValue(WC()->session);
			$op->capture_checkout_origin(null,'checkout',$GLOBALS['origin_entry'],$input,$context,$info);
		}
		if($GLOBALS['origin_case']==='late-posted'){$data['createaccount']=1;}
		if($GLOBALS['origin_case']==='consume-registry-swap'){$data['createaccount']=1;$GLOBALS['wp_filter']['graphql_mutation_response']=clone $GLOBALS['wp_filter']['graphql_mutation_response'];}
		if($GLOBALS['origin_case']==='posted-object'){$data['createaccount']=1;$data['billing_first_name']=new \stdClass();}
		return $data;
	}
	function origin_before_checkout($data,$input,$context,$info) {
		$GLOBALS['origin_entries']++;$GLOBALS['origin_final']=true;
		if($GLOBALS['origin_case']==='filtered-input'){origin_expect(($input['clientMutationId']??null)==='filtered');}
		if($GLOBALS['origin_case']==='double-consume'){
			$property=new \ReflectionProperty(QL_Session_Handler::class,'owned_operation');$operation=$property->getValue(WC()->session);
			$operation->consume_checkout_creation_origin($data,$context,$info);
			$operation->consume_checkout_creation_origin($data,$context,$info);
		}
		// Deliberately substituted caller: invoke the ACTUAL final customer branch
		// here, omitting natural update_session/checkout validation/order paths.
		$method=new \ReflectionMethod(\WPGraphQL\WooCommerce\Data\Mutation\Checkout_Mutation::class,'process_customer');
		$method->invoke(null,$data,$context,$info);
		$GLOBALS['origin_final']=false;
		throw new \GraphQL\Error\UserError('Controlled stop before orders.');
	}
	$case=$argv[1]??'';$GLOBALS['origin_case']=$case;$GLOBALS['integration_translations']=0;$GLOBALS['integration_forbid_translation']=false;
	$GLOBALS['origin_final']=false;$GLOBALS['origin_final_policy_calls']=0;$GLOBALS['origin_entries']=0;$GLOBALS['origin_prepare_calls']=0;$GLOBALS['origin_response_calls']=0;
	$GLOBALS['origin_effects']=['account'=>0,'auth'=>0,'replacement'=>0];
	HandlerContractBoundary::$user=$case==='authenticated'?17:0;HandlerContractBoundary::$graphql=true;
	HandlerContractBoundary::$woocommerce=new Origin_Woo();
	HandlerContractBoundary::$cache=[WC_SESSION_CACHE_GROUP.':wc_'.WC_SESSION_CACHE_GROUP.'_cache_prefix'=>'fixed-prefix'];
	$GLOBALS['wpdb']=$db=new HandlerContractDatabase();$_SERVER['REQUEST_METHOD']='POST';
	$id=HandlerContractBoundary::$user?(string)HandlerContractBoundary::$user:str_repeat('a',32);HandlerContractBoundary::$rows[$id]=['cart'=>'synthetic'];
	// This closure/final-branch fixture excludes renewal; allow a 60-second clock margin.
	$_SERVER['HTTP_WOOCOMMERCE_SESSION']='Session '.JWT::encode(['iss'=>get_bloginfo('url'),'iat'=>time()-2,'nbf'=>time()-2,'exp'=>time()+172860,'data'=>['customer_id'=>$id]],GRAPHQL_WOOCOMMERCE_SECRET_KEY,'HS256');
	// Runner supplies fixed reviewed hashes; never derive an allowlist from registry.
	$sources=json_decode(getenv('WL_ORIGIN_SOURCE_PINS'),true);define('WOOGRAPHQL_CART_SESSION_SOURCE_COHORT',$sources);
	$manifest=[];foreach([['graphql_mutation_input','origin_input',10,4],['graphql_pre_mutate_and_get_payload','origin_pre',10,6],['graphql_mutation_response','origin_response',10,6]] as [$hook,$function,$priority,$args]){
		$manifest[]=['hook'=>$hook,'kind'=>'function','function'=>$function,'priority'=>$priority,'accepted_args'=>$args,'stable_registry'=>true,'nonstreaming'=>true,'sha256'=>getenv('WL_ORIGIN_FIXTURE_SHA')];
	}
	define('WOOGRAPHQL_CART_SESSION_CALLBACK_COHORT',$manifest);
	$handler=new QL_Session_Handler();WC()->session=$handler;$handler->init();$handler->assert_session_ready();
	if($case==='one-owner'){
		$property=new \ReflectionProperty(QL_Session_Handler::class,'owned_operation');$receiver=$property->getValue($handler);$handler->init();origin_expect($property->getValue($handler)===$receiver);
		foreach([['graphql_pre_mutate_and_get_payload','before_mutation',PHP_INT_MIN],['graphql_pre_mutate_and_get_payload','capture_checkout_origin',PHP_INT_MAX],['graphql_mutation_response','mutation_response',0]] as [$hook,$method,$priority]){
			origin_expect($handler->is_cart_operation_callback($hook,[$receiver,$method],$priority,6));
			origin_expect(!$handler->is_cart_operation_callback($hook,[$receiver,$method],(string)$priority,6));
			origin_expect(!$handler->is_cart_operation_callback($hook,[$receiver,$method],$priority,'6'));
			$foreign=(new \ReflectionClass(\WPGraphQL\WooCommerce\Utils\Cart_Session_Operation::class))->newInstanceWithoutConstructor();
			origin_expect(!$handler->is_cart_operation_callback($hook,[$foreign,$method],$priority,6));
		}
	}
	WC()->customer=new WC_Customer();$cart=new WC_Cart();WC()->cart=$cart;
	// Reviewed registrations intentionally occur AFTER lifecycle install, BEFORE freeze.
	add_filter('graphql_mutation_input','origin_input',10,4);add_filter('graphql_pre_mutate_and_get_payload','origin_pre',10,6);add_action('graphql_mutation_response','origin_response',10,6);
	add_filter('woocommerce_checkout_registration_required','origin_registration',10,1);
	add_filter('woocommerce_checkout_update_customer_data',static fn()=>false);
	add_filter('woocommerce_checkout_posted_data','origin_posted',10,4);add_action('graphql_woocommerce_before_checkout','origin_before_checkout',10,4);
	do_action('do_graphql_request');
	$schema=BuildSchema::build('input EmptyInput { clientMutationId:String } type Payload { id:Int success:Boolean } type Query { ok:Boolean } type Mutation { checkout(input:EmptyInput!):Payload addToCart(input:EmptyInput!):Payload }');
	$entry=\WPGraphQL\WooCommerce\Mutation\Checkout::mutate_and_get_payload();$GLOBALS['origin_entry']=$entry;
	if($case==='native-registration'){
		$GLOBALS['origin_registry']=$registry=new Origin_Native_Registry();
		\WPGraphQL\WooCommerce\Mutation\Checkout::register_mutation();
		origin_expect($GLOBALS['origin_registration_name']==='checkout'&&$registry->fields['checkout']['name']==='checkout');
		$resolver=$registry->fields['checkout']['resolve'];
		$mutation=(new \ReflectionFunction($resolver))->getClosureThis();
		origin_expect(get_class($mutation)===\WPGraphQL\Type\WPMutationType::class&&
		 (new \ReflectionProperty(\WPGraphQL\Type\WPMutationType::class,'mutation_name'))->getValue($mutation)==='checkout');
		$schema->getMutationType()->getField('checkout')->resolveFn=$resolver;
	}else{
		$mutation=new Integration_Mutation('checkout',$entry);$schema->getMutationType()->getField('checkout')->resolveFn=$mutation->resolver();
	}
	$GLOBALS['origin_mutation']=$mutation;
	$cart_mutation=new Integration_Mutation('addToCart',static function($input,$ctx,$ri){$GLOBALS['origin_info']=$ri;$GLOBALS['origin_context']=$ctx;return ['success'=>true];});$schema->getMutationType()->getField('addToCart')->resolveFn=$cart_mutation->resolver();
	foreach($schema->getTypeMap() as $type){if($type instanceof ObjectType&&0!==strpos($type->name,'__')){InstrumentSchema::instrument_resolvers($type,$type->name);}}
	$query=match($case){
		'ordinary-cart','direct-no-origin'=>'mutation { addToCart(input:{}) { success } }',
		'mixed','mixed-create'=>'mutation { addToCart(input:{}) { success } checkout(input:{}) { id } }',
		'distinct-roots'=>'mutation { first:checkout(input:{}) { id } second:checkout(input:{}) { id } }',
		'merged'=>'mutation { selected:checkout(input:{}){id} ...More } fragment More on Mutation { selected:checkout(input:{}){id} }',
		'directives'=>'mutation { checkout(input:{}){id} addToCart(input:{}) @skip(if:true){success} __typename }',
		default=>'mutation { checkout(input:{}) { id } }'
	};
	if($case==='mixed-create'){ $GLOBALS['origin_case']='late-posted'; }
	$context=(new \ReflectionClass(\WPGraphQL\AppContext::class))->newInstanceWithoutConstructor();
	$response=GraphQL::executeQuery($schema,$query,null,$context)->toArray();
	if(in_array($case,['direct-no-origin','replay-direct'],true)){
		try{\WPGraphQL\WooCommerce\Mutation\Checkout::mutate_and_get_payload()([],$GLOBALS['origin_context'],$GLOBALS['origin_info']);origin_expect(false);}
		catch(\WPGraphQL\WooCommerce\Utils\Cart_Session_Transition_Error $error){$response['errors'][]=\GraphQL\Error\FormattedError::createFromException($error);}
	}
	$operation=(new \ReflectionProperty(QL_Session_Handler::class,'owned_operation'))->getValue($handler);
	$record=(new \ReflectionProperty(\WPGraphQL\WooCommerce\Utils\Cart_Session_Operation::class,'checkout_invocation'))->getValue($operation);
	origin_expect(null===$record&&$GLOBALS['origin_effects']===['account'=>0,'auth'=>0,'replacement'=>0]&&0===$db->writes);
	$codes=array_map(static fn($e)=>$e['extensions']['code']??'ordinary',$response['errors']??[]);
	$handler->discard_owned_scope();$handler->get_owned_lifecycle()->cleanup();
	fwrite(STDOUT,json_encode(['codes'=>$codes,'entries'=>$GLOBALS['origin_entries'],'prepare'=>$GLOBALS['origin_prepare_calls'],'final_policy'=>$GLOBALS['origin_final_policy_calls'],'cart_success'=>$response['data']['addToCart']['success']??false,'wiped'=>true,'effects_zero'=>true,'writes'=>0])."\n");
}

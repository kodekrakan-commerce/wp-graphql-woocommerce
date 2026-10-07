<?php
/** Actual lifecycle install/helper, MU driver preflight, plugin.php/WP_Hook/wpdb.
 * Handler/WC objects are declared source-pinned substitutes; no SQL/connection,
 * native storage admission or callback dispatch is performed by this component. */
namespace {
    error_reporting(E_ALL); ini_set('display_errors','0'); ini_set('log_errors','0');
    $wp=rtrim(getenv('WL_WORDPRESS_SOURCE')?:'','/');
    $mu=rtrim(getenv('WL_MU_PLUGINS_SOURCE')?:'','/');
    require $wp.'/wp-includes/class-wpdb.php';
    require $wp.'/wp-includes/plugin.php'; // Loads the actual WP_Hook once.
    require $mu.'/database/interface-owned-scope-driver.php';
    require $mu.'/database/class-owned-scope-error.php';
    require $mu.'/database/class-guarded-wpdb.php';
    require rtrim(getenv('WL_WPGRAPHQL_SOURCE')?:'','/').'/vendor/autoload.php';
    foreach (['class-cart-session-error.php','class-cart-session-http-boundary.php','class-cart-session-lifecycle.php'] as $name) { require dirname(__DIR__,2).'/includes/utils/'.$name; }
}
namespace WPGraphQL\WooCommerce\Utils {
    /** Install-only handler substitute; production helper and driver are genuine. */
    final class QL_Session_Handler {
        public function protects_checkout_order(): bool { return false; }
        public function is_cart_operation_callback($hook,$callback,$priority,$arguments): bool { return false; }
        public function discard_owned_scope(): void {}
        public function save_data(): void {}
    }
}
namespace WPGraphQL { final class Router {} }
namespace {
    final class WC_Customer {}
    final class WC_Cart {}
    final class WC_Cart_Session {}
    final class WC_Payment_Gateways {}
    function handoff_foreign($value=null) { $GLOBALS['handoff_effects']++; return $value; }
    abstract class HandoffDriverBase implements \WLCommerce\Database\Owned_Scope_Driver {
        public function begin_owned_scope(array $locks,int $timeout): object { $GLOBALS['handoff_effects']++; throw new \RuntimeException('No acquisition in this component.'); }
        public function assert_owned(object $handle): void {}
        public function seal_owned_scope(object $handle): void {}
        public function release_owned_scope(object $handle): void {}
        public function abort_owned_scope(object $handle): void {}
        public function get_failure_state(object $handle): array { return ['state'=>'inactive','failed'=>false]; }
    }
    final class HandoffMissingDriver extends HandoffDriverBase {}
    final class HandoffMagicDriver extends HandoffDriverBase { public function __call($method,$args) { $GLOBALS['handoff_effects']++; } }
    final class HandoffPrivateDriver extends HandoffDriverBase { private function qualify_stable_callback($hook,$callback,$pin): void {} }
    final class HandoffStaticDriver extends HandoffDriverBase { public static function qualify_stable_callback($hook,$callback,$pin): void { $GLOBALS['handoff_effects']++; } }
    final class HandoffWrongVersionDriver extends HandoffDriverBase {
        public const CAPABILITY_VERSION=2;
        public function qualify_stable_callback($hook,$callback,$pin): void { $GLOBALS['handoff_effects']++; }
    }
    final class HandoffReplacingDriver extends HandoffDriverBase {
        public function qualify_stable_callback($hook,$callback,$pin): void { $GLOBALS['wpdb']=new HandoffMissingDriver(); }
    }
    final class HandoffThrowingDriver extends HandoffDriverBase {
        public function qualify_stable_callback($hook,$callback,$pin): void { throw new \RuntimeException('Qualification rejected.'); }
    }
    function handoff_ensure($ok): void { if (!$ok) { throw new \RuntimeException('Handoff predicate failed.'); } }
    function handoff_property($object,$name,$value): void { (new \ReflectionProperty($object,$name))->setValue($object,$value); }
    $case=$argv[1]??''; $GLOBALS['handoff_effects']=0;
    $lifeClass=\WPGraphQL\WooCommerce\Utils\Cart_Session_Lifecycle::class;
    $driverClass=\WLCommerce\Database\Guarded_WPDB::class;
    $pin=getenv('WL_HANDOFF_LIFECYCLE_SHA'); $fixturePin=getenv('WL_HANDOFF_FIXTURE_SHA');
    $sources=['WP_Hook'=>getenv('WL_HANDOFF_WP_HOOK_SHA'),$lifeClass=>$pin];
    foreach (['WPGraphQL\\Router','WC_Customer','WC_Cart','WC_Cart_Session','WC_Payment_Gateways',\WPGraphQL\WooCommerce\Utils\QL_Session_Handler::class] as $name) { $sources[$name]=$fixturePin; }
    if ('missing-pin'===$case) { unset($sources[$lifeClass]); }
    if ('wrong-pin'===$case) { $sources[$lifeClass]=str_repeat('0',64); }
    if ('malformed-pin'===$case) { $sources[$lifeClass]='invalid'; }
    define('WOOGRAPHQL_CART_SESSION_SOURCE_COHORT',$sources);
    $manifest=[];
    if ('manifest-foreign-all'===$case) {
        $manifest[]=['hook'=>'all','priority'=>10,'accepted_args'=>1,'kind'=>'function','function'=>'handoff_foreign','sha256'=>$fixturePin,'stable_registry'=>true,'nonstreaming'=>true];
        add_filter('all','handoff_foreign',10,1);
    }
    define('WOOGRAPHQL_CART_SESSION_CALLBACK_COHORT',$manifest);
    if ('savequeries'===$case) { define('SAVEQUERIES',true); }
    $handler=new \WPGraphQL\WooCommerce\Utils\QL_Session_Handler();
    $life=new $lifeClass($handler,'woocommerce-session',[]);
    $driver=(new \ReflectionClass($driverClass))->newInstanceWithoutConstructor();
    $GLOBALS['wpdb']=$driver;
    $stage='helper'; $rejected=false; $preflight=false; $grants=false; $baselineStable=false; $normalized=true;
    try {
        if ('uninstalled'!==$case) { $life->install(); }
        $baseline=(new \ReflectionProperty($life,'empty_hook_baseline'))->getValue($life);
        $callback=[$life,'guard_sanitizer_dispatch'];
        $id=_wp_filter_build_unique_id('all',$callback,PHP_INT_MIN);
        switch ($case) {
            case 'incomplete-baseline': handoff_property($life,'empty_hook_baseline',null); break;
            case 'terminal': handoff_property($life,'terminal',true); break;
            case 'missing-all': unset($GLOBALS['wp_filter']['all']); break;
            case 'missing-own': remove_filter('all',$callback,PHP_INT_MIN); break;
            case 'wrong-priority': remove_filter('all',$callback,PHP_INT_MIN); add_filter('all',$callback,10,1); break;
            case 'wrong-arity': $GLOBALS['wp_filter']['all']->callbacks[PHP_INT_MIN][$id]['accepted_args']=2; break;
            case 'wrong-id': $entry=$GLOBALS['wp_filter']['all']->callbacks[PHP_INT_MIN][$id]; unset($GLOBALS['wp_filter']['all']->callbacks[PHP_INT_MIN][$id]); $GLOBALS['wp_filter']['all']->callbacks[PHP_INT_MIN]['invented-id']=$entry; break;
            case 'extra-entry-field': $GLOBALS['wp_filter']['all']->callbacks[PHP_INT_MIN][$id]['extra']=true; break;
            case 'wrong-own-receiver': $other=new $lifeClass($handler,'woocommerce-session',[]); remove_filter('all',$callback,PHP_INT_MIN); add_filter('all',[$other,'guard_sanitizer_dispatch'],PHP_INT_MIN,1); break;
            case 'missing-capability': $driver=new HandoffMissingDriver(); $GLOBALS['wpdb']=$driver; break;
            case 'magic-capability': $driver=new HandoffMagicDriver(); $GLOBALS['wpdb']=$driver; break;
            case 'private-capability': $driver=new HandoffPrivateDriver(); $GLOBALS['wpdb']=$driver; break;
            case 'static-capability': $driver=new HandoffStaticDriver(); $GLOBALS['wpdb']=$driver; break;
            case 'wrong-version': $driver=new HandoffWrongVersionDriver(); $GLOBALS['wpdb']=$driver; break;
            case 'noninterface-driver': $driver=new \stdClass(); $GLOBALS['wpdb']=$driver; break;
            case 'wrong-global': $GLOBALS['wpdb']=(new \ReflectionClass($driverClass))->newInstanceWithoutConstructor(); break;
            case 'replaced-global': $driver=new HandoffReplacingDriver(); $GLOBALS['wpdb']=$driver; break;
            case 'throwing-capability': $driver=new HandoffThrowingDriver(); $GLOBALS['wpdb']=$driver; break;
            case 'active-driver': handoff_property($driver,'owned_state','active'); break;
            case 'sealed-driver': handoff_property($driver,'owned_state','sealed'); break;
            case 'failed-driver': handoff_property($driver,'owned_failed',true); break;
            case 'unknown-query': add_filter('query','handoff_foreign',10,1); break;
            case 'unknown-all': add_filter('all','handoff_foreign',10,1); break;
            case 'unknown-logging': add_filter('log_query_custom_data','handoff_foreign',10,1); break;
        }
        if ('without-handoff'!==$case) { $life->qualify_owned_storage_driver($driver); }
        $baselineStable=$baseline===(new \ReflectionProperty($life,'empty_hook_baseline'))->getValue($life)
            && null===(new \ReflectionProperty($life,'frozen'))->getValue($life);
        if ($driver instanceof $driverClass) {
            $qualifications=(new \ReflectionProperty($driver,'owned_qualifications'))->getValue($driver);
            $grants=['all']===array_keys($qualifications) && 1===count($qualifications['all']) && $qualifications['all'][$id][0]===$callback && $qualifications['all'][$id][2]===$pin;
        }
        switch ($case) {
            case 'later-callback': add_filter('all','handoff_foreign',10,1); break;
            case 'another-receiver': $other=new $lifeClass($handler,'woocommerce-session',[]); remove_filter('all',$callback,PHP_INT_MIN); add_filter('all',[$other,'guard_sanitizer_dispatch'],PHP_INT_MIN,1); break;
            case 'another-method': remove_filter('all',$callback,PHP_INT_MIN); add_filter('all',[$life,'guard_cohort_entry'],PHP_INT_MIN,1); break;
            case 'placeholder-query': add_filter('query',[$driver,'remove_placeholder_escape'],10,1); break;
        }
        $stage='preflight'; (new \ReflectionMethod($driverClass,'preflight_hooks'))->invoke($driver); $preflight=true;
    } catch (\WPGraphQL\WooCommerce\Utils\Cart_Session_Error $error) {
        $rejected=true; $normalized='helper'===$stage && ['code'=>'WL_CART_SESSION_UNAVAILABLE']===$error->getExtensions();
    } catch (\WLCommerce\Database\Owned_Scope_Error $error) { $rejected=true; $normalized='preflight'===$stage; }
    catch (\Throwable $error) { $normalized=false; }
    finally { while (ob_get_level()) { ob_end_clean(); } if ('uninstalled'!==$case) { restore_exception_handler(); } }
    echo json_encode(['case'=>$case,'rejected'=>$rejected,'preflight'=>$preflight,'only_exact_all_grant'=>$grants,'baseline_stable_unfrozen'=>$baselineStable,'normalized'=>$normalized,'effects'=>$GLOBALS['handoff_effects']],JSON_THROW_ON_ERROR)."\n";
}

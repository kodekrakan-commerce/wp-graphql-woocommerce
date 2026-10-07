<?php
/** Code-only dependency loading. No plugin entry point, WP site bootstrap or service. */
error_reporting(E_ALL);ini_set('display_errors','0');ini_set('log_errors','0');
$GLOBALS['modern_diagnostics']=[];set_error_handler(static function($severity,$message,$file,$line){if(E_DEPRECATED===$severity || E_USER_DEPRECATED===$severity){$GLOBALS['modern_diagnostics'][]=['severity'=>$severity,'message'=>$message,'file'=>$file,'line'=>$line];return true;}throw new ErrorException($message,0,$severity,$file,$line);});
$owner=dirname(__DIR__,3);
$root=rtrim(getenv('WL_MODERN_AUTH_SOURCE_ROOT')?:'', '/');
if (!$root || !is_dir($root)) {throw new RuntimeException('Bound code-only source root required.');}
$wp=$root.'/wordpress';$wc=$root.'/woocommerce';$gql=$root.'/wp-graphql';$native=$root;
define('ABSPATH',__DIR__.'/'); define('WC_ABSPATH',$wc.'/');define('WC_PLUGIN_FILE',$wc.'/woocommerce.php');define('WC_VERSION','11.1.2');
define('COOKIEHASH','modern-component');define('COOKIEPATH','/');define('COOKIE_DOMAIN','');define('DB_NAME','offline_synthetic_contract');define('WC_SESSION_CACHE_GROUP','woocommerce_sessions');
define('MINUTE_IN_SECONDS',60);define('DAY_IN_SECONDS',86400);define('HOUR_IN_SECONDS',3600);define('YEAR_IN_SECONDS',31536000);define('WEEK_IN_SECONDS',604800);define('MONTH_IN_SECONDS',2592000);
define('GRAPHQL_WOOCOMMERCE_SECRET_KEY',str_repeat('k',32));define('WOOGRAPHQL_ACTUAL_LIFECYCLE_CONTRACT',true);
require $wp.'/wp-includes/plugin.php';
require '/Users/filipegarrido/wl-modernization/mu-plugins/database/interface-owned-scope-driver.php';
require '/Users/filipegarrido/wl-modernization/mu-plugins/database/class-owned-scope-error.php';
require __DIR__.'/boundaries.php';require __DIR__.'/wordpress-seams.php';
require $wc.'/vendor/autoload.php';
foreach (['abstracts/abstract-wc-data.php','legacy/class-wc-legacy-customer.php','legacy/class-wc-legacy-cart.php','abstracts/abstract-wc-session.php','abstracts/abstract-wc-settings-api.php','abstracts/abstract-wc-payment-gateway.php','interfaces/class-wc-object-data-store-interface.php','interfaces/class-wc-customer-data-store-interface.php','data-stores/class-wc-data-store-wp.php','class-wc-autoloader.php']as$file){require_once $wc.'/includes/'.$file;}
new WC_Autoloader();
require $wc.'/includes/data-stores/class-wc-customer-data-store.php';require $wc.'/includes/data-stores/class-wc-customer-data-store-session.php';
require $wc.'/includes/wc-formatting-functions.php';require $wc.'/includes/wc-user-functions.php';
require $gql.'/vendor/autoload.php';
foreach(['src/AppContext.php','src/Utils/InstrumentSchema.php','src/Type/WPMutationType.php']as$file){require $gql.'/'.$file;}
require $owner.'/vendor/autoload.php';
foreach(['class-cart-session-error.php','class-cart-session-operation.php','class-cart-session-storage.php','class-session-transaction-manager.php','class-cart-session-http-boundary.php','class-cart-session-lifecycle.php','class-ql-session-handler.php']as$file){require $owner.'/includes/utils/'.$file;}
spl_autoload_register(static function($class)use($native){
 $prefix='WPGraphQL\\Login\\';if(!str_starts_with($class,$prefix)){return;}$name=substr($class,strlen($prefix));
 $file=$native.'/src/'.$name;
 if(str_starts_with($name,'Vendor\\Firebase\\JWT\\')){$file=$native.'/vendor-prefixed/firebase/php-jwt/src/'.substr($name,strlen('Vendor\\Firebase\\JWT\\'));}
 elseif(str_starts_with($name,'Vendor\\AxeWP\\GraphQL\\')){$file=$native.'/vendor-prefixed/axepress/wp-graphql-plugin-boilerplate/src/'.substr($name,strlen('Vendor\\AxeWP\\GraphQL\\'));}
 $file=str_replace('\\','/',$file).'.php';if(is_file($file)){require $file;}
});
define('GRAPHQL_LOGIN_JWT_SECRET_KEY',str_repeat('fixture-native-key-',4));

<?php
/** Load genuine external classes without bootstrapping WordPress or a site. */
$mu_root = rtrim( getenv( 'WL_MU_PLUGINS_SOURCE' ) ?: '', '/' );
require $mu_root . '/database/interface-owned-scope-driver.php';
require __DIR__ . '/boundaries.php';

define( 'ABSPATH', __DIR__ . '/' );
define( 'COOKIEHASH', 'offline-contract' );
define( 'DB_NAME', 'offline_synthetic_contract' );
define( 'WC_SESSION_CACHE_GROUP', 'woocommerce_sessions' );
define( 'MINUTE_IN_SECONDS', 60 );
$GLOBALS['wpdb'] = new HandlerContractDatabase();

$plugin_root = rtrim( getenv( 'WL_WOOGRAPHQL_SOURCE' ) ?: dirname( __DIR__, 3 ), '/' );
$wc_root = rtrim( getenv( 'WL_WOOCOMMERCE_SOURCE' ) ?: '', '/' );
$graphql_root = rtrim( getenv( 'WL_WPGRAPHQL_SOURCE' ) ?: '', '/' );
require $plugin_root . '/vendor/autoload.php';
require $graphql_root . '/vendor/autoload.php';
require $wc_root . '/vendor/autoload.php';
require $wc_root . '/includes/abstracts/abstract-wc-session.php';
require $wc_root . '/includes/class-wc-session-handler.php';
require $wc_root . '/includes/class-wc-cart-session.php';
require $plugin_root . '/includes/utils/class-session-transaction-manager.php';
if ( is_file( $plugin_root . '/includes/utils/class-cart-session-error.php' ) ) {
	require $plugin_root . '/includes/utils/class-cart-session-error.php';
}
if ( is_file( $plugin_root . '/includes/utils/class-cart-session-operation.php' ) ) {
	require $plugin_root . '/includes/utils/class-cart-session-operation.php';
}
if ( is_file( $plugin_root . '/includes/utils/class-cart-session-storage.php' ) ) {
	require $plugin_root . '/includes/utils/class-cart-session-storage.php';
}
require $plugin_root . '/includes/utils/class-ql-session-handler.php';
require $plugin_root . '/includes/data/mutation/class-checkout-mutation.php';

// Resolution itself must use genuine dependency classes, not fixture lookalikes.
if ( ! is_subclass_of( \WPGraphQL\WooCommerce\Utils\QL_Session_Handler::class, WC_Session_Handler::class )
	|| ! is_subclass_of( WC_Session_Handler::class, WC_Session::class )
	|| ! class_exists( \GraphQL\Error\UserError::class )
	|| ! interface_exists( \GraphQL\Error\ProvidesExtensions::class )
	|| ! class_exists( \WPGraphQL\WooCommerce\Vendor\Firebase\JWT\JWT::class ) ) {
	throw new RuntimeException( 'Genuine source class loading failed.' );
}

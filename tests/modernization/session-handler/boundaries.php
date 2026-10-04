<?php
/** Synthetic WordPress/HTTP/auth/persistence boundaries; no session or JWT copies. */

namespace WPGraphQL {
	final class Router {
		public static function is_graphql_http_request() { return \HandlerContractBoundary::$graphql; }
	}
}

namespace WPGraphQL\WooCommerce\Utils {
	/** Recording lifecycle handshake only; genuine lifecycle has its own cohort. */
	final class Cart_Session_Lifecycle {
		private $customer;
		public function __construct( $handler, string $header, array $cookies ) { \HandlerContractBoundary::event( 'lifecycle-construct' ); }
		public function install(): void { \HandlerContractBoundary::event( 'lifecycle-install' ); }
		public function qualify_owned_storage_driver( $driver ): void { \HandlerContractBoundary::event( 'lifecycle-handoff' ); }
		/** Controlled stand-in for the separately qualified cart/customer capture. */
		public function capture_customer( $customer ): void { $this->customer = $customer; }
		public function close_writers(): void {
			\HandlerContractBoundary::event( 'writers-close' );
			if ( $this->customer ) { \remove_action( 'shutdown', [ $this->customer, 'save' ], 10 ); }
		}
		/** Recording boundary only; genuine registry/origin checks run in the origin contract. */
		public function assert_checkout_origin_boundary(): void { \HandlerContractBoundary::event( 'origin-boundary' ); }
		public function is_terminal(): bool { return false; }
	}
}
namespace {
	final class HandlerContractBoundary {
		public static array $hooks = [];
		public static array $events = [];
		public static array $rows = [];
		public static array $cache = [];
		public static array $transients = [];
		public static int $user = 0;
		public static bool $graphql = true;
		public static $woocommerce;
		public static bool $registration_required = false;
		public static bool $throw_translation = false;
		public static int $translation_calls = 0;
		public static function event( string $kind, $identity = null ): void {
			self::$events[] = [ 'kind' => $kind, 'identity' => $identity ];
		}
		public static function count( array $kinds, int $since = 0 ): int {
			return count( array_filter( array_slice( self::$events, $since ), static fn( $e ) => in_array( $e['kind'], $kinds, true ) ) );
		}
	}

	/** Controlled SQL and capability boundary; never activates the actual driver. */
	final class HandlerContractDatabase implements \WLCommerce\Database\Owned_Scope_Driver {
		public string $prefix = 'contract_';
		public string $options = 'contract_options';
		public string $users = 'contract_users';
		public string $last_error = '';
		public string $state = 'inactive';
		public $handle;
		private array $queries = [];
		public function begin_owned_scope( array $locks, int $timeout ): object {
			if ( 'inactive' !== $this->state || 5 !== $timeout || 1 !== count( $locks ) || 64 !== strlen( $locks[0] ) ) { throw new RuntimeException( 'Unexpected controlled scope admission.' ); }
			$this->handle = new stdClass(); $this->state = 'active'; HandlerContractBoundary::event( 'scope-begin' ); return $this->handle;
		}
		public function assert_owned( object $handle ): void {
			if ( $handle !== $this->handle || ! in_array( $this->state, [ 'active', 'sealed' ], true ) ) { throw new RuntimeException( 'Controlled ownership unavailable.' ); }
		}
		public function seal_owned_scope( object $handle ): void { $this->assert_owned( $handle ); $this->state = 'sealed'; HandlerContractBoundary::event( 'scope-seal' ); }
		public function release_owned_scope( object $handle ): void { $this->assert_owned( $handle ); $this->state = 'released'; HandlerContractBoundary::event( 'scope-release' ); }
		public function abort_owned_scope( object $handle ): void { if ( $handle !== $this->handle ) { throw new RuntimeException( 'Wrong controlled handle.' ); } $this->state = 'failed'; HandlerContractBoundary::event( 'scope-abort' ); }
		public function get_failure_state( object $handle ): array { if ( $handle !== $this->handle ) { throw new RuntimeException( 'Wrong controlled handle.' ); } return [ 'state' => $this->state, 'failed' => 'failed' === $this->state ]; }
		private function before_sql(): void {
			if ( HandlerContractBoundary::$graphql && class_exists( \WPGraphQL\WooCommerce\Utils\Cart_Session_Storage::class ) ) { $this->assert_owned( $this->handle ); }
		}
		/** SQL and arguments remain private; only an opaque query index crosses APIs. */
		public function prepare( $query, ...$args ) { $key = 'controlled-query-' . count( $this->queries ); $this->queries[$key] = [ $query, $args ]; return $key; }
		public function get_var( $key ) {
			$this->before_sql(); $prepared = $this->queries[$key];
            if ( 'SELECT option_value FROM %i WHERE option_name = %s' === $prepared[0] ) {
                if ( $prepared[1][0] !== $this->options || ! preg_match( '/\A(?:wl_cart_retired_v1_|wl_checkout_creation_v1_)[a-f0-9]{64}\z/D', $prepared[1][1] ) ) { throw new RuntimeException( 'Unexpected controlled marker read.' ); }
                HandlerContractBoundary::event( 'marker-read' ); return null;
            }
            $id = (string) $prepared[1][1];
			HandlerContractBoundary::event( 'db-read', $id ); return isset( HandlerContractBoundary::$rows[$id] ) ? serialize( HandlerContractBoundary::$rows[$id] ) : null;
		}
		public function get_row( $key ) {
			$this->before_sql(); $prepared = $this->queries[$key];
			if ( 'SELECT option_value, autoload FROM %i WHERE option_name = %s' === $prepared[0] ) {
				if ( $prepared[1][0] !== $this->options || ! preg_match( '/\Awl_checkout_order_v1_[a-f0-9]{64}\z/D', $prepared[1][1] ) ) { throw new RuntimeException( 'Unexpected controlled checkout fence read.' ); }
				HandlerContractBoundary::event( 'marker-read' ); return null;
			}
			$id = (int) $prepared[1][1]; HandlerContractBoundary::event( 'account-read', $id );
			return (object) [ 'ID' => $id, 'user_login' => 'synthetic-account', 'user_email' => 'synthetic@example.invalid', 'user_nicename' => 'synthetic-account' ];
		}
		public function query( $key ) {
			$this->before_sql(); $prepared = $this->queries[$key]; $sql = trim( $prepared[0] );
			if ( str_starts_with( $sql, 'INSERT' ) ) { $id = (string) $prepared[1][1]; HandlerContractBoundary::event( 'db-write', $id ); HandlerContractBoundary::$rows[$id] = maybe_unserialize( $prepared[1][2] ); }
			elseif ( str_starts_with( $sql, 'UPDATE' ) ) { HandlerContractBoundary::event( 'timestamp-write', (string) $prepared[1][2] ); }
			elseif ( str_starts_with( $sql, 'DELETE' ) ) { $id = (string) $prepared[1][1]; HandlerContractBoundary::event( 'db-delete', $id ); unset( HandlerContractBoundary::$rows[$id] ); }
			else { throw new RuntimeException( 'Unexpected controlled SQL shape.' ); }
			return 1;
		}
		public function update( $table, $data, $where, $formats = null ) { HandlerContractBoundary::event( 'timestamp-write', (string) $where['session_key'] ); return 1; }
		public function delete( $table, $where ) { $id = (string) $where['session_key']; HandlerContractBoundary::event( 'db-delete', $id ); unset( HandlerContractBoundary::$rows[$id] ); return 1; }
	}

	final class WP_Error {
		public function __construct( private $code, private $message ) {}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
	final class WC_Cache_Helper {
		public static function get_cache_prefix( $group ) { return 'contract-prefix-'; }
		public static function invalidate_cache_group( $group ) { HandlerContractBoundary::event( 'cache-invalidate' ); }
	}
	/** Captured customer proxy, explicitly not a genuine WC_Customer/data store. */
	final class HandlerContractCustomerProxy {
		public int $calls = 0;
		public bool $blocked_read = false;
		public function __construct( private $handler ) {}
		public function save(): void {
			++$this->calls;
			$this->blocked_read = 'synthetic-default' === $this->handler->get( 'cart', 'synthetic-default' );
			$this->handler->set( 'customer', 'synthetic-customer-write' );
			$this->handler->customer = 'synthetic-magic-customer-write';
			$this->handler->save_if_dirty();
		}
	}
	function add_filter( $hook, $callback, $priority = 10, $accepted = 1 ) {
		HandlerContractBoundary::$hooks[$hook][$priority][] = [ $callback, $accepted ];
		return true;
	}
	function add_action( $hook, $callback, $priority = 10, $accepted = 1 ) { return add_filter( $hook, $callback, $priority, $accepted ); }
	function remove_action( $hook, $callback, $priority = 10 ) {
		$removed = false;
		foreach ( HandlerContractBoundary::$hooks[$hook][$priority] ?? [] as $index => [ $registered, $accepted ] ) {
			if ( $registered === $callback ) { unset( HandlerContractBoundary::$hooks[$hook][$priority][$index] ); $removed = true; }
		}
		return $removed;
	}
	function apply_filters( $hook, $value, ...$args ) {
		if ( in_array( $hook, [ 'wc_session_expiring', 'wc_session_expiration' ], true ) ) {
			HandlerContractBoundary::event( 'expiration-change' );
		}
		if ( 'graphql_woocommerce_cart_session_signed_token' === $hook ) { HandlerContractBoundary::event( 'token-built' ); }
		if ( 'woocommerce_persistent_cart_enabled' === $hook ) { HandlerContractBoundary::event( 'persistent-cart-policy' ); }
		$priorities = HandlerContractBoundary::$hooks[$hook] ?? [];
		ksort( $priorities );
		foreach ( $priorities as $callbacks ) {
			foreach ( $callbacks as [ $callback, $accepted ] ) { $value = $callback( ...array_slice( [ $value, ...$args ], 0, $accepted ) ); }
		}
		return $value;
	}
	function apply_filters_deprecated( $hook, $args, ...$unused ) { return apply_filters( $hook, ...$args ); }
	function do_action( $hook, ...$args ) {
		$priorities = HandlerContractBoundary::$hooks[$hook] ?? [];
		ksort( $priorities );
		foreach ( $priorities as $callbacks ) {
			foreach ( $callbacks as [ $callback, $accepted ] ) { $callback( ...array_slice( $args, 0, $accepted ) ); }
		}
	}
	function is_user_logged_in() { return HandlerContractBoundary::$user > 0; }
	function WC() { return HandlerContractBoundary::$woocommerce ?? (object) []; }
	function get_current_user_id() { return HandlerContractBoundary::$user; }
	function get_current_blog_id() { return 1; }
	function get_option( $key, $default = false ) { return 'woocommerce_enable_guest_checkout' === $key ? ( HandlerContractBoundary::$registration_required ? 'no' : 'yes' ) : $default; }
	function wc_create_new_customer( ...$args ) { HandlerContractBoundary::event( 'account-created' ); return 17; }
	function wc_set_customer_auth_cookie( ...$args ) { HandlerContractBoundary::event( 'auth-cookie-emitted' ); }
	function wc_create_order( ...$args ) { HandlerContractBoundary::event( 'order-created' ); throw new RuntimeException( 'Order boundary must not be reached by bounded customer probes.' ); }
	function update_user_meta( $id, $key, $value ) { HandlerContractBoundary::event( 'user-meta-write' ); return true; }
	function delete_user_meta( $id, $key ) { HandlerContractBoundary::event( 'user-meta-delete' ); return true; }
	function get_user_by( $field, $id ) { return ctype_digit( (string) $id ) && (int) $id > 0 ? (object) [ 'ID' => (int) $id ] : false; }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function __( $message, $domain = null ) { ++HandlerContractBoundary::$translation_calls; if ( HandlerContractBoundary::$throw_translation ) { throw new RuntimeException( 'Synthetic translation callback forbidden.' ); } return $message; }
	function get_bloginfo( $name ) { return 'https://offline.example.invalid'; }
	function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
	function maybe_serialize( $value ) { return is_array( $value ) || is_object( $value ) ? serialize( $value ) : $value; }
	function maybe_unserialize( $value ) { return is_string( $value ) && preg_match( '/^(a|O|s|i|b|d):/', $value ) ? unserialize( $value, [ 'allowed_classes' => false ] ) : $value; }
	function absint( $value ) { return abs( (int) $value ); }
	function wc_rand_hash( $prefix = '', $length = 30 ) {
		HandlerContractBoundary::event( 'identity-generated' );
		return $prefix . substr( str_repeat( 'f', 32 ), 0, $length );
	}
	function wp_cache_get( $key, $group, $force = false, &$found = null ) {
		HandlerContractBoundary::event( 'cache-read', $key );
		$found = array_key_exists( $group . ':' . $key, HandlerContractBoundary::$cache );
		return $found ? HandlerContractBoundary::$cache[$group . ':' . $key] : false;
	}
	function wp_cache_set( $key, $value, $group, $expiration = 0 ) {
		HandlerContractBoundary::event( 'cache-write', $key );
		HandlerContractBoundary::$cache[$group . ':' . $key] = $value;
		return true;
	}
	function wp_cache_add( $key, $value, $group, $expiration = 0 ) { return wp_cache_set( $key, $value, $group, $expiration ); }
	function wp_cache_delete( $key, $group ) {
		HandlerContractBoundary::event( 'cache-delete', $key );
		unset( HandlerContractBoundary::$cache[$group . ':' . $key] );
		return true;
	}
	function wc_setcookie( ...$args ) { HandlerContractBoundary::event( 'cookie-emitted' ); }
	function wc_site_is_https() { return true; }
	function is_ssl() { return true; }
	function wp_hash( $message ) { return hash( 'sha256', 'synthetic-cookie-boundary' . $message ); }
	function wp_fast_hash( $message ) { return hash( 'sha256', 'synthetic-cookie-boundary' . $message ); }
	function wp_verify_fast_hash( $message, $hash ) { return hash_equals( wp_fast_hash( $message ), $hash ); }
	function wp_unslash( $value ) { return $value; }
	function wc_clean( $value ) { return $value; }
	function is_admin() { return true; }
	function get_transient( $key ) {
		HandlerContractBoundary::event( 'transient-read' );
		return HandlerContractBoundary::$transients[$key] ?? false;
	}
	function set_transient( $key, $value, $expiration = 0 ) {
		HandlerContractBoundary::event( 'transient-write' );
		HandlerContractBoundary::$transients[$key] = $value;
		return true;
	}
	function delete_transient( $key ) {
		HandlerContractBoundary::event( 'transient-delete' );
		unset( HandlerContractBoundary::$transients[$key] );
		return true;
	}
}

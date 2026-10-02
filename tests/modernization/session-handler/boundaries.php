<?php
/** Synthetic WordPress/HTTP/auth/persistence boundaries; no session or JWT copies. */

namespace WPGraphQL {
	final class Router {
		public static function is_graphql_http_request() { return \HandlerContractBoundary::$graphql; }
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
		public static function event( string $kind, $identity = null ): void {
			self::$events[] = [ 'kind' => $kind, 'identity' => $identity ];
		}
		public static function count( array $kinds, int $since = 0 ): int {
			return count( array_filter( array_slice( self::$events, $since ), static fn( $e ) => in_array( $e['kind'], $kinds, true ) ) );
		}
	}

	final class HandlerContractDatabase {
		public string $prefix = 'contract_';
		/** Carry query arguments internally; never interpolate/log session or credential values. */
		public function prepare( $query, ...$args ) { return [ $query, $args ]; }
		public function get_var( $prepared ) {
			$id = (string) $prepared[1][1];
			HandlerContractBoundary::event( 'db-read', $id );
			return isset( HandlerContractBoundary::$rows[$id] ) ? serialize( HandlerContractBoundary::$rows[$id] ) : null;
		}
		public function query( $prepared ) {
			if ( ! is_array( $prepared ) || ! str_starts_with( trim( $prepared[0] ), 'INSERT' ) ) {
				throw new RuntimeException( 'Unexpected synthetic DB query shape.' );
			}
			$id = (string) $prepared[1][1];
			HandlerContractBoundary::event( 'db-write', $id );
			HandlerContractBoundary::$rows[$id] = maybe_unserialize( $prepared[1][2] );
			return 1;
		}
		public function update( $table, $data, $where, $formats = null ) {
			HandlerContractBoundary::event( 'timestamp-write', (string) $where['session_key'] );
			return 1;
		}
		public function delete( $table, $where ) {
			$id = (string) $where['session_key'];
			HandlerContractBoundary::event( 'db-delete', $id );
			unset( HandlerContractBoundary::$rows[$id] );
			return 1;
		}
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
	function __( $message, $domain = null ) { return $message; }
	function get_bloginfo( $name ) { return 'https://offline.example.invalid'; }
	function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
	function maybe_serialize( $value ) { return is_array( $value ) || is_object( $value ) ? serialize( $value ) : $value; }
	function maybe_unserialize( $value ) { return is_string( $value ) && preg_match( '/^(a|O|s|i|b|d):/', $value ) ? unserialize( $value, [ 'allowed_classes' => false ] ) : $value; }
	function absint( $value ) { return abs( (int) $value ); }
	function wc_rand_hash( $prefix = '', $length = 30 ) {
		HandlerContractBoundary::event( 'identity-generated' );
		return $prefix . substr( str_repeat( 'f', 32 ), 0, $length );
	}
	function wp_cache_get( $key, $group ) {
		HandlerContractBoundary::event( 'cache-read', $key );
		return HandlerContractBoundary::$cache[$key] ?? false;
	}
	function wp_cache_set( $key, $value, $group, $expiration = 0 ) {
		HandlerContractBoundary::event( 'cache-write', $key );
		HandlerContractBoundary::$cache[$key] = $value;
		return true;
	}
	function wp_cache_add( $key, $value, $group, $expiration = 0 ) { return wp_cache_set( $key, $value, $group, $expiration ); }
	function wp_cache_delete( $key, $group ) {
		HandlerContractBoundary::event( 'cache-delete', $key );
		unset( HandlerContractBoundary::$cache[$key] );
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

<?php
/** Synthetic WordPress/HTTP/auth/persistence boundaries; no session or JWT copies. */

namespace WPGraphQL {
	final class Router {
		public static function is_graphql_http_request() { return \HandlerContractBoundary::$graphql; }
	}
}

namespace WPGraphQL\WooCommerce\Utils {
    /** Controlled lifecycle handshake only; genuine lifecycle is tested separately. */
    if ( ! defined( 'WOOGRAPHQL_ACTUAL_LIFECYCLE_CONTRACT' ) || true !== WOOGRAPHQL_ACTUAL_LIFECYCLE_CONTRACT ) {
    final class Cart_Session_Lifecycle {
        public bool $terminal = false;
        public function __construct($handler, string $header, array $cookies) { \HandlerContractBoundary::event('lifecycle-construct'); }
        public function install(): void { \HandlerContractBoundary::event('lifecycle-install'); }
        public function close_writers(): void { \HandlerContractBoundary::event('writers-close'); }
        public function is_terminal(): bool { return $this->terminal; }
    }
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
		public static bool $sticky_cache = false;
		public static $woocommerce;
		public static function event( string $kind, $identity = null ): void {
			self::$events[] = [ 'kind' => $kind, 'identity' => $identity ];
		}
		public static function count( array $kinds, int $since = 0 ): int {
			return count( array_filter( array_slice( self::$events, $since ), static fn( $e ) => in_array( $e['kind'], $kinds, true ) ) );
		}
	}

	final class HandlerContractDatabase implements \WLCommerce\Database\Owned_Scope_Driver {
        public string $prefix = 'contract_'; public string $users = 'contract_users'; public string $last_error = '';
        public $timeout; public $state = 'inactive'; public $failed = false; public $handle; public $write_result = 1;
        public $repopulate_on_write = false; public $report_failed = false; public $throw_begin = false; public $throw_seal = false; public $throw_release = false; public array $queries = []; public array $calls = []; public int $reads = 0; public int $writes = 0;
        public function begin_owned_scope(array $locks, int $timeout): object { $this->calls[]='begin'; $this->timeout=$timeout; if($this->throw_begin){throw new RuntimeException('Synthetic acquisition failure.');} HandlerContractBoundary::event('scope-begin'); $this->handle=new stdClass(); $this->state='active'; return $this->handle; }
        public function assert_owned(object $handle): void { $this->calls[]='assert'; if ($handle!==$this->handle || $this->failed || !in_array($this->state,['active','sealed'],true)) { throw new RuntimeException('Synthetic ownership unavailable.'); } }
        public function seal_owned_scope(object $handle): void { $this->assert_owned($handle); $this->calls[]='seal'; if($this->throw_seal){throw new RuntimeException('Synthetic seal failure.');} HandlerContractBoundary::event('scope-seal'); $this->state='sealed'; }
        public function release_owned_scope(object $handle): void { $this->assert_owned($handle); $this->calls[]='release'; if($this->throw_release){throw new RuntimeException('Synthetic release failure.');} HandlerContractBoundary::event('scope-release'); $this->state='released'; }
        public function abort_owned_scope(object $handle): void { if ($handle!==$this->handle) { throw new RuntimeException('Wrong synthetic handle.'); } $this->calls[]='abort'; HandlerContractBoundary::event('scope-abort'); $this->state='failed'; $this->failed=true; }
        public function get_failure_state(object $handle): array { $this->calls[]='state'; if($handle!==$this->handle) { throw new RuntimeException('Wrong synthetic handle.'); } return ['state'=>$this->state,'failed'=>$this->failed||$this->report_failed]; }
        public function prepare($query,...$args) { $key='synthetic-query-'.count($this->queries); $this->queries[$key]=[$query,$args]; return $key; }
        public function get_var($key) { $this->reads++; HandlerContractBoundary::event('db-read'); $q=$this->queries[$key]; $id=(string)$q[1][1]; return isset(HandlerContractBoundary::$rows[$id]) ? serialize(HandlerContractBoundary::$rows[$id]) : null; }
        public function get_row($key) { $this->reads++; HandlerContractBoundary::event('account-read'); return (object)['ID'=>17,'user_login'=>'synthetic','user_email'=>'synthetic@example.invalid','user_nicename'=>'synthetic']; }
        public function query($key) { $this->writes++; if($this->repopulate_on_write) { HandlerContractBoundary::$cache[WC_SESSION_CACHE_GROUP . ':wc_cache_fixed-prefix_' . str_repeat('a',32)]=['cart'=>'stale']; } $q=$this->queries[$key]; $kind=str_starts_with(trim($q[0]),'INSERT')?'db-write':(str_starts_with(trim($q[0]),'DELETE')?'db-delete':'timestamp-write'); HandlerContractBoundary::event($kind); if(false!==$this->write_result && 'db-write'===$kind) { HandlerContractBoundary::$rows[(string)$q[1][1]]=unserialize($q[1][2],['allowed_classes'=>false]); } return $this->write_result; }
    }

	final class WP_Error {
		public function __construct( private $code, private $message ) {}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}

	if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted = 1 ) {
		HandlerContractBoundary::$hooks[$hook][$priority][] = [ $callback, $accepted ];
		return true;
	}
	}
	if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted = 1 ) { return add_filter( $hook, $callback, $priority, $accepted ); }
	}
	if ( ! function_exists( 'remove_action' ) ) {
	function remove_action( $hook, $callback, $priority = 10 ) {
		$removed = false;
		foreach ( HandlerContractBoundary::$hooks[$hook][$priority] ?? [] as $index => [ $registered, $accepted ] ) {
			if ( $registered === $callback ) { unset( HandlerContractBoundary::$hooks[$hook][$priority][$index] ); $removed = true; }
		}
		return $removed;
	}
	}
	if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		if ( in_array( $hook, [ 'wc_session_expiring', 'wc_session_expiration' ], true ) ) {
			HandlerContractBoundary::event( 'expiration-change' );
		}
		HandlerContractBoundary::event( 'filter:' . $hook );
		if ( 'graphql_woocommerce_cart_session_signed_token' === $hook ) { HandlerContractBoundary::event( 'token-built' ); }
		if ( 'woocommerce_persistent_cart_enabled' === $hook ) { HandlerContractBoundary::event( 'persistent-cart-policy' ); }
		$priorities = HandlerContractBoundary::$hooks[$hook] ?? [];
		ksort( $priorities );
		foreach ( $priorities as $callbacks ) {
			foreach ( $callbacks as [ $callback, $accepted ] ) { $value = $callback( ...array_slice( [ $value, ...$args ], 0, $accepted ) ); }
		}
		return $value;
	}
	}
	if ( ! function_exists( 'apply_filters_deprecated' ) ) {
	function apply_filters_deprecated( $hook, $args, ...$unused ) { return apply_filters( $hook, ...$args ); }
	}
	if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		$priorities = HandlerContractBoundary::$hooks[$hook] ?? [];
		ksort( $priorities );
		foreach ( $priorities as $callbacks ) {
			foreach ( $callbacks as [ $callback, $accepted ] ) { $callback( ...array_slice( $args, 0, $accepted ) ); }
		}
	}
	}
	function is_user_logged_in() { return HandlerContractBoundary::$user > 0; }
	function WC() { return HandlerContractBoundary::$woocommerce ?? (object) []; }
	function get_current_user_id() { return HandlerContractBoundary::$user; }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	if ( ! function_exists( '__' ) ) {
		function __( $message, $domain = null ) { return $message; }
	}
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
		if ( ! HandlerContractBoundary::$sticky_cache ) { unset( HandlerContractBoundary::$cache[$group . ':' . $key] ); }
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

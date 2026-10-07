<?php
/** Controlled database/cache boundaries; this does not implement a database driver. */

final class Storage_Contract_Database implements \WLCommerce\Database\Owned_Scope_Driver {
	public $prefix = 'wp_';
	public $users = 'wp_users';
	public $last_error = '';
	public $state = 'inactive';
	public $failed = false;
	public $handle;
	public $locks = [];
	public $timeout;
	public $value;
	public $row;
	public $write_result = 1;
	public $queries = [];
	public $reads = 0;
	public $writes = 0;
	public $lose_on_read = false;
	public $lose_on_write = false;
	public $change_on_prepare = false;
	public $throw_on_begin = false;
	public $read_error = false;
	public $report_failed = false;
	public $repopulate_on_write = false;
	public $invalid_prepare = false;
	public $abort_calls = 0;
	public $abort_handles = [];
	public $throw_on_abort = false;
	public $calls = [];

	public function begin_owned_scope( array $lock_names, int $timeout_seconds ): object {
		$this->calls[] = 'begin';
		$GLOBALS['storage_trace'][] = 'begin';
		if ( $this->throw_on_begin ) { throw new RuntimeException( 'Synthetic driver failure.' ); }
		$this->handle = new stdClass(); $this->state = 'active';
		$this->locks = $lock_names; $this->timeout = $timeout_seconds;
		return $this->handle;
	}
	public function assert_owned( object $handle ): void {
		$this->calls[] = 'assert';
		$GLOBALS['storage_trace'][] = 'assert';
		if ( $this->failed || $handle !== $this->handle || ! in_array( $this->state, [ 'active', 'sealed' ], true ) ) {
			throw new RuntimeException( 'Synthetic ownership unavailable.' );
		}
	}
	public function seal_owned_scope( object $handle ): void { $this->calls[] = 'seal'; $this->assert_owned( $handle ); $this->state = 'sealed'; }
	public function release_owned_scope( object $handle ): void { $this->calls[] = 'release'; $this->assert_owned( $handle ); $this->state = 'released'; }
	public function abort_owned_scope( object $handle ): void {
		$this->calls[] = 'abort';
		$this->abort_calls++; $this->abort_handles[] = $handle;
		if ( $handle !== $this->handle ) { throw new RuntimeException( 'Wrong synthetic handle.' ); }
		if ( $this->throw_on_abort ) { throw new RuntimeException( 'Synthetic cleanup unavailable.' ); }
		$GLOBALS['storage_trace'][] = 'abort'; $this->failed = true; $this->state = 'failed';
	}
	public function get_failure_state( object $handle ): array {
		$this->calls[] = 'failure-state';
		if ( $handle !== $this->handle ) { throw new RuntimeException( 'Wrong synthetic handle.' ); }
		return [ 'state' => $this->state, 'failed' => $this->failed || $this->report_failed ];
	}
	public function prepare( $query, ...$arguments ) {
		$this->calls[] = 'prepare';
		$this->assert_owned( $this->handle );
		$key = 'synthetic-query-' . count( $this->queries );
		$this->queries[ $key ] = [ $query, $arguments ];
		if ( $this->change_on_prepare ) { $GLOBALS['wpdb'] = new stdClass(); }
		if ( $this->invalid_prepare ) { return null; }
		return $key;
	}
	public function get_var( $query ) {
		$this->calls[] = 'get-var';
		$this->assert_owned( $this->handle ); $this->reads++; $GLOBALS['storage_trace'][] = 'session-read';
		if ( $this->lose_on_read ) { $this->failed = true; }
		$this->last_error = $this->read_error ? 'Synthetic SQL unavailable.' : '';
		return $this->value;
	}
	public function get_row( $query ) {
		$this->calls[] = 'get-row';
		$this->assert_owned( $this->handle ); $this->reads++; $GLOBALS['storage_trace'][] = 'account-read';
		$this->last_error = $this->read_error ? 'Synthetic SQL unavailable.' : '';
		return $this->row;
	}
	public function query( $query ) {
		$this->calls[] = 'query';
		$this->assert_owned( $this->handle ); $this->writes++; $GLOBALS['storage_trace'][] = 'write';
		if ( $this->lose_on_write ) { $this->failed = true; }
		if ( $this->repopulate_on_write ) { $GLOBALS['storage_cache'][ WC_SESSION_CACHE_GROUP ][ storage_session_key() ] = [ 'stale' => true ]; }
		return $this->write_result;
	}
}

function __( $text, $domain = null ) { return $text; }
function add_action( ...$arguments ) {}
function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
	$GLOBALS['storage_trace'][] = 'cache-get:' . $group;
	$found = array_key_exists( $key, $GLOBALS['storage_cache'][ $group ] ?? [] );
	return $found ? $GLOBALS['storage_cache'][ $group ][ $key ] : false;
}
function wp_cache_set( $key, $value, $group = '', $expiry = 0 ) {
	$GLOBALS['storage_publications'][] = [ $key, $group ];
	$GLOBALS['storage_cache'][ $group ][ $key ] = $value;
	return true;
}
function wp_cache_delete( $key, $group = '' ) {
	$GLOBALS['storage_trace'][] = 'cache-delete:' . $group;
	$GLOBALS['storage_deletions'][] = [ $key, $group ];
	$existed = array_key_exists( $key, $GLOBALS['storage_cache'][ $group ] ?? [] );
	if ( ! $GLOBALS['storage_cache_sticky'] ) { unset( $GLOBALS['storage_cache'][ $group ][ $key ] ); }
	return $existed && ! $GLOBALS['storage_delete_false'];
}
function storage_session_key( $id = null ) { return 'wc_cache_fixed-prefix_' . ( $id ?? str_repeat( 'a', 32 ) ); }
function storage_fixture( $account = false ) {
	$GLOBALS['storage_trace'] = []; $GLOBALS['storage_deletions'] = []; $GLOBALS['storage_publications'] = [];
	$GLOBALS['storage_cache_sticky'] = false; $GLOBALS['storage_delete_false'] = false;
	$GLOBALS['storage_cache'] = [ WC_SESSION_CACHE_GROUP => [ 'wc_' . WC_SESSION_CACHE_GROUP . '_cache_prefix' => 'fixed-prefix' ] ];
	$db = new Storage_Contract_Database(); $GLOBALS['wpdb'] = $db;
	$db->value = serialize( [ 'cart' => 'authoritative-synthetic' ] );
	$db->row = (object) [ 'ID' => 17, 'user_login' => 'new-synthetic-login', 'user_email' => 'new@example.invalid', 'user_nicename' => 'new-synthetic-slug' ];
	$id = $account ? '17' : str_repeat( 'a', 32 );
	$storage = new \WPGraphQL\WooCommerce\Utils\Cart_Session_Storage( $id, 'wp_woocommerce_sessions', $account ? 17 : 0 );
	return [ $storage, $db ];
}
function storage_expect( $condition ) { if ( ! $condition ) { throw new RuntimeException( 'Storage contract assertion failed.' ); } }
function storage_unavailable( callable $action ) {
	try { $action(); } catch ( \WPGraphQL\WooCommerce\Utils\Cart_Session_Error $error ) {
		storage_expect( [ 'code' => 'WL_CART_SESSION_UNAVAILABLE' ] === $error->getExtensions() );
		storage_expect( null === $error->getPrevious() && 'The cart session is temporarily unavailable.' === $error->getMessage() );
		return;
	}
	throw new RuntimeException( 'Storage boundary did not reject an operation.' );
}

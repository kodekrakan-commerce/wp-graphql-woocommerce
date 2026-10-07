<?php
/** Reservation-specific recording adapter over the existing controlled transfer driver. */
final class Creation_Contract_Database implements \WLCommerce\Database\Owned_Scope_Driver {
	public $inner;
	public $creation_faults = [];
	public $on_creation_command;
	public $queries = [];
	public $nested_transaction = false;
	private $prepared = [];
	private $reservation_open = false;
	private $reservation_finished = false;

	public function __construct() { $this->inner = new Transfer_Contract_Database(); }
	public function &__get( $name ) { return $this->inner->$name; }
	public function __set( $name, $value ) { $this->inner->$name = $value; }
	public function __isset( $name ) { return isset( $this->inner->$name ); }
	public function begin_owned_scope( array $names, int $timeout ): object { return $this->inner->begin_owned_scope( $names, $timeout ); }
	public function assert_owned( object $handle ): void { $this->inner->assert_owned( $handle ); }
	public function seal_owned_scope( object $handle ): void { $this->inner->seal_owned_scope( $handle ); }
	public function release_owned_scope( object $handle ): void { $this->inner->release_owned_scope( $handle ); }
	public function abort_owned_scope( object $handle ): void { $this->inner->abort_owned_scope( $handle ); }
	public function get_failure_state( object $handle ): array { return $this->inner->get_failure_state( $handle ); }
	public function prepare( $query, ...$arguments ) {
		$key = $this->inner->prepare( $query, ...$arguments ); $this->prepared[$key] = [ $query, $arguments ]; return $key;
	}
	private function fault_call( $specific, $base, callable $call ) {
		$had = array_key_exists( $base, $this->inner->faults ); $old = $this->inner->faults[$base] ?? null;
		if ( isset( $this->creation_faults[$specific] ) ) { $this->inner->faults[$base] = $this->creation_faults[$specific]; }
		try { return $call(); }
		finally { if ( $had ) { $this->inner->faults[$base] = $old; } else { unset( $this->inner->faults[$base] ); } }
	}
	public function get_var( $query ) {
		[ $sql, $args ] = $this->prepared[$query] ?? [ $query, [] ];
		if ( isset( $args[1] ) && str_starts_with( $args[1], 'wl_checkout_creation_v1_' ) ) {
			return $this->fault_call( 'creation-read', 'ledger-read', fn() => $this->inner->get_var( $query ) );
		}
		return $this->inner->get_var( $query );
	}
	public function get_row( $query ) { return $this->inner->get_row( $query ); }
	public function get_results( $query ) { return $this->inner->get_results( $query ); }
	public function query( $query ) {
		[ $sql, $args ] = $this->prepared[$query] ?? [ $query, [] ];
		$this->queries[] = [ $sql, $args ];
		$label = null; $base = null;
		if ( 'START TRANSACTION' === $sql && ! $this->reservation_finished ) {
			$label = 'creation-start'; $base = 'start'; $this->reservation_open = true;
			if ( $this->nested_transaction || null !== $this->inner->pending ) { throw new RuntimeException( 'Synthetic nested START refused.' ); }
		} elseif ( isset( $args[1] ) && str_starts_with( $args[1], 'wl_checkout_creation_v1_' ) && str_starts_with( $sql, 'INSERT' ) ) {
			$label = 'creation-insert'; $base = 'ledger-insert';
		} elseif ( 'COMMIT' === $sql && $this->reservation_open ) {
			$label = 'creation-commit'; $base = 'commit';
		}
		if ( null === $label ) { return $this->inner->query( $query ); }
		if ( $this->on_creation_command ) { ( $this->on_creation_command )( $label, $this ); }
		$result = $this->fault_call( $label, $base, fn() => $this->inner->query( $query ) );
		if ( 'creation-commit' === $label ) { $this->reservation_open = false; $this->reservation_finished = true; }
		return $result;
	}
}

function creation_fixture( $account = false ) {
	storage_fixture(); $db = new Creation_Contract_Database(); $GLOBALS['wpdb'] = $db;
	$id = $account ? '17' : str_repeat( 'a', 32 );
	$storage = new \WPGraphQL\WooCommerce\Utils\Cart_Session_Storage( $id, 'wp_woocommerce_sessions', $account ? 17 : 0 );
	$storage->acquire(); return [ $storage, $db, $id ];
}
function creation_key( $id ) { return 'wl_checkout_creation_v1_' . transfer_hash( $id ); }
function creation_marker( $id, $uuid ) {
	return json_encode( [ 'schema' => 1, 'kind' => 'checkout_creation_attempt', 'source_tuple_sha256' => transfer_hash( $id ),
		'operation_uuid' => $uuid ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
}
function creation_reserved() {
	[ $guest, $db, $id ] = creation_fixture(); storage_expect( true === $guest->reserve_checkout_creation( 'wp_options' ) );
	$marker = $db->ledger[creation_key( $id )]; $uuid = json_decode( $marker['value'], true, 8, JSON_THROW_ON_ERROR )['operation_uuid'];
	return [ $guest, $db, $id, $uuid, $marker ];
}

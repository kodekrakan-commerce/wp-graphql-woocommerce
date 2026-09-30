<?php
/**
 * Native WooCommerce checkout contracts for the sealed modernization fixture.
 * Run only with wp eval-file after the candidate is staged; see the companion doc.
 * No provider credentials, external requests, or historical checkout imports.
 */

use WPGraphQL\WooCommerce\Data\Mutation\Checkout_Mutation;

function wl_checkout_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

// Fail closed before installing hooks or touching WooCommerce state.
wl_checkout_assert( defined( 'WP_CLI' ) && WP_CLI, 'Requires wp eval-file.' );
wl_checkout_assert( defined( 'WL_SANDBOX_TEST_MODE' ) && true === WL_SANDBOX_TEST_MODE, 'Sandbox mode must be exactly true.' );
wl_checkout_assert( defined( 'DB_NAME' ) && 'wl_sandbox' === DB_NAME, 'Unexpected configured database.' );
wl_checkout_assert( 'wl_sandbox' === $GLOBALS['wpdb']->get_var( 'SELECT DATABASE()' ), 'Unexpected connected database.' );
wl_checkout_assert( 'http://127.0.0.1:8106' === untrailingslashit( home_url() ), 'Unexpected home URL.' );
wl_checkout_assert( 'http://127.0.0.1:8106' === untrailingslashit( site_url() ), 'Unexpected site URL.' );
wl_checkout_assert( PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 2, 'Requires fixture PHP 8.2.' );
wl_checkout_assert( defined( 'WPGRAPHQL_WOOCOMMERCE_VERSION' ) && '1.0.3.1' === WPGRAPHQL_WOOCOMMERCE_VERSION, 'Candidate 1.0.3.1 is not active.' );
wl_checkout_assert( function_exists( 'graphql' ) && function_exists( 'WC' ), 'GraphQL and WooCommerce must be active.' );
wl_checkout_assert( function_exists( 'woonuxt_defer_stripe_checkout' ) && false !== has_filter( 'graphql_woocommerce_checkout_payment_result', 'woonuxt_defer_stripe_checkout' ), 'Released Settings deferred checkout hook is required.' );

$wl_snapshot = realpath( (string) getenv( 'WL_CHECKOUT_SNAPSHOT' ) );
$wl_snapshot_hash = (string) getenv( 'WL_CHECKOUT_SNAPSHOT_SHA256' );
wl_checkout_assert( $wl_snapshot && str_starts_with( $wl_snapshot, '/var/sandbox-private/' ) && str_ends_with( $wl_snapshot, '.sql' ), 'A private pre-run SQL snapshot is required.' );
wl_checkout_assert( is_readable( $wl_snapshot ) && filesize( $wl_snapshot ) > 1000, 'Snapshot is unreadable or empty.' );
wl_checkout_assert( preg_match( '/^[a-f0-9]{64}$/', $wl_snapshot_hash ) && hash_equals( $wl_snapshot_hash, hash_file( 'sha256', $wl_snapshot ) ), 'Snapshot checksum differs.' );
wl_checkout_assert( time() - filemtime( $wl_snapshot ) < 7200, 'Snapshot must be freshly exported before this run (under two hours).' );

$wl_checkout_source = ( new ReflectionClass( Checkout_Mutation::class ) )->getFileName();
$wl_checkout_hash = (string) getenv( 'WL_CHECKOUT_SOURCE_SHA256' );
wl_checkout_assert( preg_match( '/^[a-f0-9]{64}$/', $wl_checkout_hash ) && hash_equals( $wl_checkout_hash, hash_file( 'sha256', $wl_checkout_source ) ), 'Active checkout helper differs from the reviewed candidate.' );

// This replaces only Stripe's provider boundary. Calling it is an assertion failure
// in deferred cases; it never sends HTTP or uses stored Stripe configuration.
class WL_Checkout_Contract_Stripe extends WC_Payment_Gateway {
	public $calls = 0;
	public function __construct() {
		$this->id = 'stripe';
		$this->title = 'Sandbox Stripe boundary';
		$this->enabled = 'yes';
		$this->supports = [ 'products' ];
	}
	public function process_payment( $order_id ) {
		++$this->calls;
		return [ 'result' => 'failure', 'redirect' => '' ];
	}
}

class WL_Checkout_Contracts {
	private $stripe;
	private $bacs;
	private $products = [];
	private $product_baselines = [];
	private $orders = [];
	private $users = [];
	private $hooks = [];
	private $validation_count = 0;
	private $reject_validation = false;
	private $meta_hook = [];
	private $passed = [];
	private $run_id;

	public function __construct() {
		$this->run_id = 'checkout-' . bin2hex( random_bytes( 8 ) );
		$this->stripe = new WL_Checkout_Contract_Stripe();
		$this->bacs = new WC_Gateway_BACS();
		$this->bacs->enabled = 'yes'; // Object-only; no option writes.
	}

	private function hook( $name, $callback, $priority = 10, $args = 1 ) {
		add_filter( $name, $callback, $priority, $args );
		$this->hooks[] = [ $name, $callback, $priority ];
	}

	private function setup() {
		// Narrow process-local fixtures keep production-like settings untouched.
		$this->hook( 'woocommerce_available_payment_gateways', function () {
			return [ 'stripe' => $this->stripe, 'bacs' => $this->bacs ];
		}, PHP_INT_MAX );
		foreach ( [ 'woocommerce_enable_guest_checkout' => 'yes', 'woocommerce_enable_signup_and_login_from_checkout' => 'yes', 'woocommerce_registration_generate_username' => 'no', 'woocommerce_registration_generate_password' => 'no', 'woocommerce_manage_stock' => 'yes', 'woocommerce_hold_stock_minutes' => '60', 'woocommerce_ship_to_destination' => 'shipping' ] as $option => $value ) {
			$this->hook( 'pre_option_' . $option, static function () use ( $value ) { return $value; } );
		}
		$this->hook( 'woocommerce_package_rates', static function () {
			return [
				'flat_rate:9001' => new WC_Shipping_Rate( 'flat_rate:9001', 'Contract shipping A', 2, [], 'flat_rate', 9001 ),
				'flat_rate:9002' => new WC_Shipping_Rate( 'flat_rate:9002', 'Contract shipping B', 4, [], 'flat_rate', 9002 ),
			];
		}, PHP_INT_MAX );
		$this->hook( 'woocommerce_after_checkout_validation', function ( $data, $errors ) {
			++$this->validation_count;
			if ( $this->reject_validation ) {
				$errors->add( 'checkout_contract_changed', 'Contract changed checkout requires validation.' );
			}
		}, 1, 2 );
		$this->hook( 'woocommerce_new_order', function ( $id ) { $this->orders[] = (int) $id; }, 1 );
		$this->hook( 'woocommerce_created_customer', function ( $id ) { $this->users[] = (int) $id; }, 1 );
		$this->hook( 'graphql_woocommerce_before_checkout_meta_save', function ( $order, $meta ) { $this->meta_hook = $meta; }, PHP_INT_MAX, 2 );
		// The sealed fixture also blocks network/mail. These keep this harness
		// independently bounded if those process policies accidentally drift.
		$this->hook( 'pre_http_request', static function () { return new WP_Error( 'checkout_contract_network', 'Network is disabled for checkout contracts.' ); }, PHP_INT_MAX, 3 );
		$this->hook( 'pre_wp_mail', static function () { return false; }, PHP_INT_MAX, 2 );
		wp_set_current_user( 0 );
		WC()->session = new WC_Session_Handler();
		WC()->session->init();
		WC()->customer = new WC_Customer( 0, true );
		WC()->cart = new WC_Cart();
		foreach ( [ 'physical' => false, 'free' => true ] as $label => $virtual ) {
			$product = new WC_Product_Simple();
			$product->set_name( 'Synthetic ' . $this->run_id . ' ' . $label );
			$product->set_status( 'publish' );
			$product->set_catalog_visibility( 'hidden' );
			$product->set_virtual( $virtual );
			$product->set_regular_price( $virtual ? '0' : '3.50' );
			$product->set_price( $virtual ? '0' : '3.50' );
			$product->set_tax_status( 'none' );
			$product->set_manage_stock( true );
			$product->set_stock_quantity( 100 );
			$product->set_stock_status( 'instock' );
			$product->set_backorders( 'no' );
			$this->products[ $label ] = $product->save();
			$this->product_baselines[ $product->get_id() ] = [
				'regular_price' => $product->get_regular_price(), 'sale_price' => $product->get_sale_price(),
				'price' => $product->get_price(), 'manage_stock' => $product->get_manage_stock(),
				'stock_quantity' => $product->get_stock_quantity(), 'stock_status' => $product->get_stock_status(),
			];
		}
	}

	private function reset_guest( $clear_cart = true ) {
		wp_set_current_user( 0 );
		$preserved_cart = $clear_cart ? [] : WC()->cart->get_cart();
		if ( $clear_cart ) {
			WC()->cart->empty_cart();
		}
		WC()->session->destroy_session(); // Native handler creates a distinct guest ID.
		if ( ! $clear_cart ) {
			// Native destroy_session also empties the cart. Restore identical native
			// item objects into the foreign guest for the stock/validation probe.
			WC()->cart->set_cart_contents( $preserved_cart );
		}
		WC()->customer = new WC_Customer( 0, true );
		wc_clear_notices();
	}

	private function address() {
		return [ 'firstName' => 'Synthetic', 'lastName' => 'Buyer', 'company' => '',
			'address1' => 'Rua de Teste 1', 'address2' => '', 'city' => 'Lisboa',
			'postcode' => '1000-001', 'state' => '', 'country' => 'PT', 'phone' => '910000000' ];
	}

	private function input( $method = 'stripe' ) {
		$billing = $this->address();
		$billing['email'] = $this->run_id . '@example.invalid';
		return [ 'paymentMethod' => $method, 'shippingMethod' => [ 'flat_rate:9001' ],
			'shipToDifferentAddress' => true, 'billing' => $billing, 'shipping' => $this->address() ];
	}

	private function cart( $label = 'physical', $stock = 100 ) {
		$product = wc_get_product( $this->products[ $label ] );
		$product->set_stock_quantity( $stock );
		$product->set_stock_status( 'instock' );
		$product->save();
		WC()->cart->empty_cart();
		wl_checkout_assert( (bool) WC()->cart->add_to_cart( $product->get_id(), 1 ), 'Native add_to_cart failed.' );
		WC()->cart->calculate_totals();
	}

	private function checkout( $input, $expect_error = false ) {
		$result = graphql( [
			'query' => 'mutation ContractCheckout($input: CheckoutInput!) { checkout(input: $input) { result redirect order { databaseId status } } }',
			'variables' => [ 'input' => $input ],
		] );
		if ( $expect_error ) {
			wl_checkout_assert( ! empty( $result['errors'] ), 'Changed/foreign checkout unexpectedly succeeded.' );
			return $result;
		}
		wl_checkout_assert( empty( $result['errors'] ), 'Checkout failed: ' . wp_json_encode( $result['errors'] ?? [] ) );
		$payload = $result['data']['checkout'] ?? null;
		wl_checkout_assert( is_array( $payload ) && ! empty( $payload['order']['databaseId'] ), 'Checkout returned no order.' );
		return $payload;
	}

	private function order( $payload ) {
		$order = wc_get_order( (int) $payload['order']['databaseId'] );
		wl_checkout_assert( $order instanceof WC_Order, 'Checkout order is not persisted.' );
		return $order;
	}

	private function pending( $payload ) {
		$order = $this->order( $payload );
		wl_checkout_assert( 'pending' === $payload['result'] && 'PENDING' === $payload['order']['status'], 'Deferred GraphQL result/status differs.' );
		wl_checkout_assert( $order->has_status( 'pending' ) && ! $order->is_paid() && $order->needs_payment(), 'Chargeable deferred order was completed.' );
		wl_checkout_assert( '' === $order->get_transaction_id() && 'yes' === $order->get_meta( '_woonuxt_deferred_payment' ), 'Deferred state or transaction differs.' );
		wl_checkout_assert( ! WC()->cart->is_empty() && $order->get_id() === (int) WC()->session->get( 'order_awaiting_payment' ), 'Deferred cart/session was cleared.' );
		wl_checkout_assert( 0 === $this->stripe->calls, 'Native Stripe process_payment was invoked.' );
		return $order;
	}

	private function restore_products() {
		foreach ( $this->product_baselines as $id => $props ) {
			$product = wc_get_product( $id );
			if ( $product ) {
				$product->set_props( $props );
				$product->save();
			}
		}
	}

	private function delete_orders( $ids ) {
		foreach ( array_unique( $ids ) as $id ) {
			$order = wc_get_order( $id );
			if ( $order ) {
				wc_release_stock_for_order( $order );
				$order->delete( true );
			}
		}
	}

	private function test( $name, $callback ) {
		$this->reset_guest();
		$start = count( $this->orders );
		try {
			$callback();
			$this->passed[] = $name;
			WP_CLI::log( 'PASS ' . $name );
		} finally {
			$this->reject_validation = false;
			WC()->cart->empty_cart();
			$this->delete_orders( array_slice( $this->orders, $start ) );
			$this->restore_products();
			$this->reset_guest();
		}
	}

	public function run() {
		try {
			$this->setup();
			$this->test( 'deferred checkout and identical last-stock retry', function () {
				$this->cart( 'physical', 1 );
				$order = $this->pending( $this->checkout( $this->input() ) );
				wl_checkout_assert( 1 === (int) wc_get_held_stock_quantity( wc_get_product( $this->products['physical'] ) ), 'Last stock was not reserved by native WooCommerce.' );
				$count = count( $this->orders );
				$validation = $this->validation_count;
				$retry = $this->pending( $this->checkout( $this->input() ) );
				wl_checkout_assert( $retry->get_id() === $order->get_id() && count( $this->orders ) === $count, 'Identical retry created a second order.' );
				wl_checkout_assert( $validation === $this->validation_count, 'Identical retry incorrectly re-entered last-stock validation.' );
			} );
			foreach ( [ 'shipping phone', 'shipping address', 'shipping rate', 'cart', 'foreign session' ] as $change ) {
				$this->test( 'last-stock retry rejects changed ' . $change, function () use ( $change ) {
					$this->cart( 'physical', 1 );
					$order = $this->pending( $this->checkout( $this->input() ) );
					$input = $this->input();
					$validation = $this->validation_count;
					$count = count( $this->orders );
					if ( 'shipping phone' === $change ) { $input['shipping']['phone'] = '920000000'; }
					if ( 'shipping address' === $change ) { $input['shipping']['address1'] = 'Rua de Teste 2'; }
					if ( 'shipping rate' === $change ) { $input['shippingMethod'] = [ 'flat_rate:9002' ]; }
					if ( 'cart' === $change ) {
						$key = array_key_first( WC()->cart->get_cart() );
						WC()->cart->set_quantity( $key, 2 );
					}
					if ( 'foreign session' === $change ) {
						$guest = WC()->session->get_customer_id();
						$this->reset_guest( false );
						wl_checkout_assert( $guest !== WC()->session->get_customer_id() && ! WC()->session->get( 'order_awaiting_payment' ), 'Foreign native guest session was not isolated.' );
					}
					// Native WC legitimately excludes this session's awaiting order
					// from reserved stock and may resume it after fresh validation.
					// The sentinel makes a validation bypass observable for every
					// changed address/rate rather than assuming stock must reject it.
					$this->reject_validation = true;
					$this->checkout( $input, true );
					wl_checkout_assert( $this->validation_count > $validation, 'Changed/foreign retry bypassed native checkout validation.' );
					wl_checkout_assert( count( $this->orders ) === $count && wc_get_order( $order->get_id() )->has_status( 'pending' ), 'Rejected retry created or completed an order.' );
				} );
			}
			$this->test( 'forged payment flags, transaction and authoritative metadata', function () {
				$this->cart();
				$input = $this->input();
				$input['isPaid'] = true;
				$input['transactionId'] = 'forged_transaction';
				$input['metaData'] = array_map( static function ( $key ) { return [ 'key' => $key, 'value' => 'forged' ]; }, [ '_transaction_id', '_stripe_intent_id', '_stripe_source_id', '_order_total', '_paid_date', '_woonuxt_deferred_payment', '_customer_user' ] );
				$order = $this->pending( $this->checkout( $input ) );
				foreach ( [ '_stripe_intent_id', '_stripe_source_id', '_paid_date' ] as $key ) {
					wl_checkout_assert( '' === $order->get_meta( $key ), 'Authoritative metadata persisted: ' . $key );
				}
				wl_checkout_assert( $order->get_total() > 0 && 0 === $order->get_customer_id() && ! $order->get_date_paid(), 'Forged metadata altered authoritative order fields.' );
			} );
			$this->test( 'native BACS succeeds on hold and clears cart', function () {
				$this->cart();
				$payload = $this->checkout( $this->input( 'bacs' ) );
				$order = $this->order( $payload );
				wl_checkout_assert( 'success' === $payload['result'] && 'ON_HOLD' === $payload['order']['status'] && $order->has_status( 'on-hold' ), 'Native BACS did not finish on hold.' );
				wl_checkout_assert( 'bacs' === $order->get_payment_method() && WC()->cart->is_empty(), 'Native BACS method/cart differs.' );
			} );
			$this->test( 'actual zero-total completion ignores forged transaction', function () {
				$this->cart( 'free' );
				$input = $this->input();
				$input['isPaid'] = true;
				$input['transactionId'] = 'forged_free_transaction';
				$payload = $this->checkout( $input );
				$order = $this->order( $payload );
				wl_checkout_assert( 'success' === $payload['result'] && 0.0 === (float) $order->get_total() && $order->is_paid(), 'Native zero-total order was not completed.' );
				wl_checkout_assert( '' === $order->get_transaction_id() && WC()->cart->is_empty() && 0 === $this->stripe->calls, 'Free checkout used forged/provider transaction or retained cart.' );
			} );
			$this->test( 'summary bindings persist key hashes and retain last ten', function () {
				$ids = [];
				foreach ( range( 1, 12 ) as $unused ) {
					$this->cart();
					$order = $this->order( $this->checkout( $this->input( 'bacs' ) ) );
					$ids[] = $order->get_id();
					$bindings = WC()->session->get( 'wl_checkout_summary_orders' );
					wl_checkout_assert( hash( 'sha256', $order->get_order_key() ) === ( $bindings[ $order->get_id() ] ?? null ), 'Summary is not bound to SHA-256 of the order key.' );
					wl_checkout_assert( ! in_array( $order->get_order_key(), $bindings, true ), 'Summary stores a raw order key.' );
				}
				wl_checkout_assert( array_slice( $ids, -10 ) === array_keys( WC()->session->get( 'wl_checkout_summary_orders' ) ), 'Summary did not retain exactly the last ten order IDs.' );
				WC()->session->save_data();
				$persisted = WC()->session->get_session( WC()->session->get_customer_id() );
				wl_checkout_assert( WC()->session->get( 'wl_checkout_summary_orders' ) === maybe_unserialize( $persisted['wl_checkout_summary_orders'] ?? null ), 'Summary bindings did not persist in the native session store.' );
			} );
			$this->test( 'username/password account payload owns order and logs in for next operation', function () {
				$this->cart();
				$input = $this->input( 'bacs' );
				$username = 'wl_' . str_replace( '-', '_', $this->run_id );
				$password = bin2hex( random_bytes( 16 ) );
				$input['account'] = [ 'username' => $username, 'password' => $password ];
				$order = $this->order( $this->checkout( $input ) );
				$user = get_user_by( 'login', $username );
				wl_checkout_assert( $user && wp_check_password( $password, $user->user_pass, $user->ID ), 'Account credentials were not honored.' );
				wl_checkout_assert( $user->ID === $order->get_customer_id() && $user->ID === get_current_user_id(), 'Created customer does not own the order or is not logged in.' );
				$next = graphql( [ 'query' => '{ customer { databaseId username } }' ] );
				wl_checkout_assert( empty( $next['errors'] ) && $user->ID === $next['data']['customer']['databaseId'] && $username === $next['data']['customer']['username'], 'Next GraphQL operation lost the checkout login.' );
			} );
			$this->test( 'metadata allowlist, byte boundary, enum and sanitized hook persistence', function () {
				$this->cart();
				$input = $this->input();
				$input['metaData'] = [
					[ 'key' => '_pickup_location_name', 'value' => '<b>Pickup</b>\n desk' ],
					[ 'key' => '_analytics_event_id', 'value' => str_repeat( 'x', 500 ) ],
					[ 'key' => '_ga_client_id', 'value' => str_repeat( 'x', 501 ) ],
					[ 'key' => '_delivery_mode', 'value' => str_repeat( 'é', 251 ) ],
					[ 'key' => '_stripe_payment_method_type', 'value' => 'mb_way' ],
					[ 'key' => '_wc_order_attribution_utm_source', 'value' => 'contract' ],
					[ 'key' => '_unknown_client_key', 'value' => 'forged' ],
				];
				$order = $this->pending( $this->checkout( $input ) );
				$sanitized = sanitize_text_field( $input['metaData'][0]['value'] );
				wl_checkout_assert( $sanitized === $order->get_meta( '_pickup_location_name' ) && str_repeat( 'x', 500 ) === $order->get_meta( '_analytics_event_id' ), 'Allowed metadata or 500-byte boundary differs.' );
				foreach ( [ '_ga_client_id', '_delivery_mode', '_unknown_client_key' ] as $key ) {
					wl_checkout_assert( '' === $order->get_meta( $key ), 'Rejected metadata persisted: ' . $key );
				}
				wl_checkout_assert( 'mb_way' === $order->get_meta( '_stripe_payment_method_type' ) && 'contract' === $order->get_meta( '_wc_order_attribution_utm_source' ), 'Allowed enum/attribution metadata differs.' );
				$expected = array_values( array_filter( $input['metaData'], static function ( $meta ) { return in_array( $meta['key'], [ '_pickup_location_name', '_analytics_event_id', '_stripe_payment_method_type', '_wc_order_attribution_utm_source' ], true ); } ) );
				$expected[0]['value'] = $sanitized;
				wl_checkout_assert( $expected === $this->meta_hook, 'Metadata hook received rejected or unsanitized values.' );
				// The real GraphQL String scalar rejects arrays before this helper.
				// Probe the actual helper too, without replacing its persistence logic.
				Checkout_Mutation::update_order_meta( $order->get_id(), [
					[ 'key' => '_consent_marketing', 'value' => [ 'nested' => 'unsafe' ] ],
					[ 'key' => '_stripe_payment_method_type', 'value' => 'invalid_provider_method' ],
				], [], null, null );
				$order = wc_get_order( $order->get_id() );
				wl_checkout_assert( '' === $order->get_meta( '_consent_marketing' ) && 'mb_way' === $order->get_meta( '_stripe_payment_method_type' ) && [] === $this->meta_hook, 'Non-scalar/invalid enum was accepted by the actual helper.' );
			} );
			WP_CLI::log( wp_json_encode( [ 'passed' => $this->passed, 'stripe_process_payment_calls' => $this->stripe->calls, 'owned_order_ids' => array_values( array_unique( $this->orders ) ), 'owned_user_ids' => $this->users, 'owned_product_ids' => $this->products ] ) );
		} finally {
			// Exact owned objects only. SQL restoration remains the full rollback:
			// native hooks can also alter notes, scheduler rows, options and caches.
			if ( WC()->cart ) { WC()->cart->empty_cart(); }
			$this->delete_orders( $this->orders );
			$this->restore_products();
			foreach ( $this->products as $id ) {
				$product = wc_get_product( $id );
				if ( $product ) { $product->delete( true ); }
			}
			wp_set_current_user( 0 );
			if ( WC()->session ) { WC()->session->destroy_session(); }
			require_once ABSPATH . 'wp-admin/includes/user.php';
			foreach ( array_unique( $this->users ) as $id ) { wp_delete_user( $id ); }
			foreach ( $this->hooks as [ $name, $callback, $priority ] ) { remove_filter( $name, $callback, $priority ); }
		}
	}
}

WP_CLI::log( wp_json_encode( [
	'preconditions' => [ 'database' => DB_NAME, 'home' => home_url(), 'php' => PHP_VERSION,
		'wordpress' => get_bloginfo( 'version' ), 'woocommerce' => WC_VERSION,
		'woographql' => WPGRAPHQL_WOOCOMMERCE_VERSION, 'checkout_source' => $wl_checkout_source,
		'checkout_sha256' => $wl_checkout_hash, 'snapshot' => basename( $wl_snapshot ),
		'snapshot_sha256' => $wl_snapshot_hash, 'snapshot_mtime_utc' => gmdate( 'c', filemtime( $wl_snapshot ) ),
		'order_store' => WC_Data_Store::load( 'order' )->get_current_class_name() ],
] ) );

try {
	( new WL_Checkout_Contracts() )->run();
	WP_CLI::success( 'All native checkout contracts passed; restore the SQL snapshot for full fixture rollback.' );
} catch ( Throwable $error ) {
	WP_CLI::error( 'Checkout contract failed: ' . $error->getMessage() );
}

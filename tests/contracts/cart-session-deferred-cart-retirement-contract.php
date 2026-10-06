<?php
/** Actual checkout closure/process, handler and native WC cart/session retirement.
 * Order insertion/factory, origin handshake, totals/validation, hook registry,
 * SQL/auth/cache are recording seams. No installed/native HTTP/payment proof.
 */
namespace WPGraphQL\WooCommerce\Data\Mutation {
    final class Order_Mutation {
        public static int $purges = 0;
        public static function purge($order) { ++self::$purges; }
    }
}
namespace {
const WL_NATIVE_ORDER_BOOTSTRAP_ONLY = true;
require __DIR__ . '/cart-session-native-order-save-contract.php';
require $wc . '/includes/legacy/class-wc-legacy-cart.php';
require $wc . '/includes/class-wc-cart.php';
require getenv('WL_CHECKOUT_MUTATION_SOURCE') ?: dirname(__DIR__, 2) . '/includes/data/mutation/class-checkout-mutation.php';
require getenv('WL_CHECKOUT_ENTRY_SOURCE') ?: dirname(__DIR__, 2) . '/includes/mutation/class-checkout.php';

function wc_ship_to_billing_address_only() { return false; }
function wc_maybe_define_constant($name, $value) { if (!defined($name)) define($name, $value); }
function wc_set_time_limit($limit) {}
function wc_get_price_decimals() { return 2; }
function get_woocommerce_currencies() { return ['EUR' => 'Euro']; }
function wc_get_notices($kind) { return []; }
function is_multisite() { return false; }
function is_email($email) { return str_contains($email, '@'); }
function wp_json_encode($value) { return json_encode($value); }
function wp_parse_args($args, $defaults = []) { return array_merge($defaults, $args); }
function did_action($hook) { return 1; }
function apply_filters_deprecated($hook, $args, ...$unused) { return apply_filters($hook, ...$args); }
function delete_user_meta($id, $key) { $GLOBALS['retired_persistent'][] = [$id, $key]; return true; }
function wc_get_order($id) { return $id === 91 ? $GLOBALS['retirement_order'] : false; }
class WC_Order_Factory { static function get_order($id) { return wc_get_order($id); } }
class WC_Customer { function save() {} }
final class RetirementCart extends WC_Cart {
    // Checkout totals/validation are explicit seams; inherited retirement/hash run.
    function calculate_totals() {}
    function needs_shipping() { return false; }
    function needs_shipping_address() { return false; }
    function needs_payment() { return true; }
}
final class RetirementMetadataStore {
    public array $rows = []; public int $reads = 0; public bool $persist = true; public $on_read; public $on_write;
    function get_internal_meta_keys() { return []; }
    function update(&$order) { $order->save_meta_data(); }
    function add_meta(&$order, $meta) {
        if ($this->on_write) ($this->on_write)($order, $this);
        $id = count($this->rows) + 1;
        if ($this->persist) $this->rows[] = (object) ['meta_id' => $id, 'meta_key' => $meta->key, 'meta_value' => $meta->value];
        return $id;
    }
    function update_meta(&$order, $meta) {
        if ($this->on_write) ($this->on_write)($order, $this);
        if ($this->persist) foreach ($this->rows as $row) if ($row->meta_id === $meta->id) $row->meta_value = $meta->value;
    }
    function delete_meta(&$order, $meta) { $this->rows = array_values(array_filter($this->rows, fn($row) => $row->meta_id !== $meta->id)); }
    function read_meta(&$order) { ++$this->reads; if ($this->on_read) ($this->on_read)($order, $this); return $this->rows; }
    function get_order_item_type(...$args) { return 'line_item'; }
    function read_items(...$args) { return []; }
    function get_payment_token_ids(...$args) { return []; }
}
const RETIREMENT_NONCE = '11111111-1111-4111-8111-111111111111';
function retirement_acknowledged($order) {
    return 1 === preg_match('/\Av1:'.RETIREMENT_NONCE.':[a-f0-9]{32}\z/D', $order->get_meta('_wl_checkout_cart_retired'));
}
final class RetirementRuntime {
    public $session; public $cart; public $customer; public $checkout; public $payment_gateways;
    function checkout() { return $this->checkout; }
    function shipping() { return new class { function reset_shipping() {} }; }
}
final class RetirementOrigin {
    public int $entries = 0; public int $leaves = 0;
    function enter_checkout(...$args) { ++$this->entries; }
    function leave_checkout(...$args) { ++$this->leaves; }
}
function retirement_fixture($reuse = false, $user = 17) {
    [$handler, $db, $session_id] = owned_start($user);
    $runtime = new RetirementRuntime(); $runtime->session = $handler; $runtime->customer = new WC_Customer();
    HandlerContractBoundary::$woocommerce = $runtime;
    $cart = (new ReflectionClass(RetirementCart::class))->newInstanceWithoutConstructor();
    $cart_session = (new ReflectionClass(WC_Cart_Session::class))->newInstanceWithoutConstructor();
    save_property($cart_session, 'cart', $cart); save_property($cart, 'session', $cart_session);
    save_property($cart, 'fees_api', new WC_Cart_Fees());
    $cart->cart_contents = ['line' => ['product_id' => 130, 'quantity' => 1]];
    $cart->set_totals(['total' => 1]); $runtime->cart = $cart;
    $handler->set('cart', ['line' => ['product_id' => 130, 'quantity' => 1]]);
    $handler->set('shipping_for_package_0', ['rates' => []]);
    HandlerContractBoundary::$rows[$session_id]['shipping_for_package_0'] = ['rates' => []];
    $handler->set('unrelated', 'preserve');
    $order = (new ReflectionClass(WC_Order::class))->newInstanceWithoutConstructor();
    $order->set_id(91); $order->set_object_read(true); save_property($order, 'meta_data', []);
    $order->set_status('pending'); $order->set_customer_id($user); $order->set_payment_method('stripe');
    $order->set_total('1'); $order->set_currency('EUR'); $order->set_order_key('wc_synthetic');
    $order->set_billing_country('PT');
    $order->set_cart_hash($cart->get_cart_hash());
    $store = new RetirementMetadataStore();
    $store->rows = [(object) ['meta_id' => 1, 'meta_key' => '_woonuxt_deferred_payment', 'meta_value' => 'yes']];
    $order->init_meta_data($store->rows);
    save_property($order, 'data_store', $store);
    $GLOBALS['retirement_order'] = $order; $GLOBALS['retired_persistent'] = [];
    $origin = new RetirementOrigin(); save_property($handler, 'owned_operation', $origin);
    $runtime->checkout = new class {
        public int $creates = 0;
        function check_cart_items() {}
        function create_order($data) { ++$this->creates; return 91; }
    };
    $runtime->payment_gateways = new class {
        function get_available_payment_gateways() { return ['stripe' => new class { function validate_fields() {} }]; }
    };
    add_filter('woocommerce_checkout_update_customer_data', fn() => false, 10, 1);
    add_filter('woocommerce_checkout_registration_required', fn() => false, 10, 1);
    add_filter('graphql_woocommerce_checkout_payment_result', fn() => ['result' => 'pending', 'redirect' => ''], 10, 3);
    add_action('woocommerce_cart_emptied', [$cart_session, 'destroy_cart_session'], 10, 1);
    if ($reuse) {
        $handler->set('order_awaiting_payment', 91);
        $handler->set('wl_checkout_summary_orders', [91 => hash('sha256', $order->get_order_key())]);
    }
    \WPGraphQL\WooCommerce\Data\Mutation\Order_Mutation::$purges = 0;
    return [$handler, $db, $runtime, $order, $origin];
}
function retirement_checkout($meta = null) {
    $entry = \WPGraphQL\WooCommerce\Mutation\Checkout::mutate_and_get_payload();
    $context = (new ReflectionClass(\WPGraphQL\AppContext::class))->newInstanceWithoutConstructor();
    $info = (new ReflectionClass(\GraphQL\Type\Definition\ResolveInfo::class))->newInstanceWithoutConstructor();
    $meta ??= [['key' => '_wl_checkout_cart_retirement', 'value' => 'v1:'.RETIREMENT_NONCE]];
    return $entry(['paymentMethod' => 'stripe', 'billing' => ['country' => 'PT'], 'metaData' => $meta], $context, $info);
}
$cases = [];
foreach ([false, true] as $reuse) {
    $cases[$reuse ? 'verified reuse retires once without creating another order' : 'fresh acknowledged checkout retires its session and persistent cart'] = function() use ($reuse) {
        [$h, $db, $wc, $order, $origin] = retirement_fixture($reuse);
        $result = retirement_checkout();
        owned_expect(['id' => 91, 'result' => 'pending', 'redirect' => ''] === $result);
        owned_expect($wc->cart->is_empty() && !$h->get('cart') && !$h->get('order_awaiting_payment'));
        owned_expect(!$h->get('shipping_for_package_0') && 'preserve' === $h->get('unrelated'));
        owned_expect([[17, '_woocommerce_persistent_cart_1']] === $GLOBALS['retired_persistent']);
        owned_expect($h->has_ordinary_checkout_cart_retirement() && !$h->protects_checkout_order());
        owned_expect(($reuse ? 0 : 1) === $wc->checkout->creates && 1 === $origin->entries && 1 === $origin->leaves);
        owned_expect('pending' === $order->get_status() && !$order->is_paid());
        owned_expect(retirement_acknowledged($order) && '' === $order->get_meta('_wl_checkout_cart_retirement'));
        owned_expect($order->get_data_store()->reads > 0);
        $h->complete_owned_scope(); owned_expect('released' === $db->state);
    };
}
$cases['late after-checkout exception preserves retired order and withholds successful flush'] = function() {
    [$h, $db, $wc, $order] = retirement_fixture();
    $failure = new RuntimeException('Controlled late hook failure.');
    add_action('graphql_woocommerce_after_checkout', fn() => throw $failure, 10, 1);
    $error = null;
    try { retirement_checkout(); } catch (\GraphQL\Error\UserError $caught) { $error = $caught; }
    owned_expect(0 === \WPGraphQL\WooCommerce\Data\Mutation\Order_Mutation::$purges);
    owned_expect($error instanceof \GraphQL\Error\ProvidesExtensions && ['code' => 'WL_CART_SESSION_UNAVAILABLE'] === $error->getExtensions());
    owned_expect($error->getPrevious() === $failure);
    owned_expect(!str_contains(json_encode(\GraphQL\Error\FormattedError::createFromException($error)), $failure->getMessage()));
    owned_expect($wc->cart->is_empty() && $h->has_ordinary_checkout_cart_retirement());
    owned_expect(0 === \WPGraphQL\WooCommerce\Data\Mutation\Order_Mutation::$purges);
    owned_error(fn() => $h->complete_owned_scope()); $h->discard_owned_scope();
    owned_expect('failed' === $db->state && 0 === $db->writes && !$order->is_paid() && 91 === $order->get_id());
};
foreach ([false, true] as $reuse) {
$cases['ordinary guest '.($reuse ? 'reused' : 'fresh').' cart retires and preserves session order authority'] = function() use ($reuse) {
    [$h, $db, $wc, $order] = retirement_fixture($reuse, 0);
    $result = retirement_checkout();
    owned_expect(91 === $result['id'] && 'pending' === $result['result']);
    owned_expect($wc->cart->is_empty() && !$h->get('cart') && !$h->get('order_awaiting_payment'));
    owned_expect([] === $GLOBALS['retired_persistent'] && 0 === $order->get_customer_id());
    // Existing payment/summary session authority survives removal of awaiting/cart keys.
    $bindings = $h->get('wl_checkout_summary_orders', []);
    owned_expect(hash('sha256', $order->get_order_key()) === ($bindings[91] ?? null));
    owned_expect(retirement_acknowledged($order));
    owned_expect(!$h->protects_checkout_order() && $h->has_ordinary_checkout_cart_retirement());
    $h->complete_owned_scope(); owned_expect('released' === $db->state);
};
}
$cases['legacy reused guest carries awaiting authority into bounded order binding before retirement'] = function() {
    [$h, $db, $wc, $order] = retirement_fixture(true, 0);
    $h->set('wl_checkout_summary_orders', [90 => 'preserve-other-order']);
    retirement_checkout();
    $bindings = $h->get('wl_checkout_summary_orders', []);
    owned_expect(hash('sha256', $order->get_order_key()) === ($bindings[91] ?? null));
    owned_expect('preserve-other-order' === ($bindings[90] ?? null));
    owned_expect($wc->cart->is_empty() && !$h->get('order_awaiting_payment') && retirement_acknowledged($order));
};
$cases['current reused order binding is retained within the existing ten-order bound'] = function() {
    [$h, $db, $wc, $order] = retirement_fixture(true, 0);
    $bindings = array_fill_keys(range(1, 20), 'other'); $bindings[91] = 'stale';
    // Put the current ID first to require refreshing its bounded-map position.
    $h->set('wl_checkout_summary_orders', [91 => 'stale'] + $bindings);
    retirement_checkout(); $bindings = $h->get('wl_checkout_summary_orders', []);
    owned_expect(10 === count($bindings) && 91 === array_key_last($bindings));
    owned_expect(hash('sha256', $order->get_order_key()) === $bindings[91]);
    owned_expect(!isset($bindings[1]) && 'other' === $bindings[20]);
};
foreach (['owner', 'hash', 'paid', 'transaction', 'method', 'scope', 'key'] as $drift) {
    $cases['before-retirement '.$drift.' rejection has no cart or persistent effect'] = function() use ($drift) {
        [$h, $db, $wc, $order] = retirement_fixture();
        $entered = 0;
        add_action('woocommerce_checkout_order_processed', function() use ($drift, $h, $db, $order, &$entered) {
            ++$entered;
            if ($drift === 'owner') $order->set_customer_id(18);
            if ($drift === 'hash') $order->set_cart_hash('other-cart');
            if ($drift === 'paid') $order->set_date_paid(time());
            if ($drift === 'transaction') $order->set_transaction_id('payment');
            if ($drift === 'method') $order->set_payment_method('other');
            if ($drift === 'scope') $h->discard_owned_scope();
            if ($drift === 'key') $order->set_order_key('');
        }, 10, 3);
        try { retirement_checkout(); throw new RuntimeException('Missing rejection.'); }
        catch (\GraphQL\Error\UserError $expected) {}
        owned_expect(1 === $entered);
        owned_expect(!$wc->cart->is_empty() && [] === $GLOBALS['retired_persistent']);
        owned_expect(!$h->has_ordinary_checkout_cart_retirement());
    };
}
foreach ([false, true] as $reuse) {
    $cases['legacy caller preserves '.($reuse ? 'reused' : 'fresh').' submitted cart without acknowledgment'] = function() use ($reuse) {
        [$h, $db, $wc, $order] = retirement_fixture($reuse);
        $result = retirement_checkout([]);
        owned_expect(91 === $result['id'] && 'pending' === $result['result']);
        owned_expect(!$wc->cart->is_empty() && $h->get('cart') && 91 === $h->get('order_awaiting_payment'));
        owned_expect(!$h->has_ordinary_checkout_cart_retirement() && [] === $GLOBALS['retired_persistent']);
        owned_expect('' === $order->get_meta('_wl_checkout_cart_retired'));
    };
}
foreach (['duplicate', 'malformed', 'nonstring', 'wrong-version'] as $invalid) {
    $cases['invalid '.$invalid.' negotiation fails before order creation or retirement'] = function() use ($invalid) {
        [$h, $db, $wc, $order] = retirement_fixture();
        $meta = [['key' => '_wl_checkout_cart_retirement', 'value' => 'v1:'.RETIREMENT_NONCE]];
        if ($invalid === 'duplicate') $meta[] = $meta[0];
        if ($invalid === 'malformed') $meta[0]['value'] = 'v1:old';
        if ($invalid === 'nonstring') $meta[0]['value'] = 1;
        if ($invalid === 'wrong-version') $meta[0]['value'] = 'v2:'.RETIREMENT_NONCE;
        owned_error(fn() => retirement_checkout($meta), 'WL_CART_SESSION_TRANSITION_INVALID');
        owned_expect(0 === $wc->checkout->creates && !$wc->cart->is_empty());
        owned_expect(!$h->has_ordinary_checkout_cart_retirement() && [] === $GLOBALS['retired_persistent']);
    };
}
$cases['browser spoofed acknowledgment is dropped by actual metadata whitelist'] = function() {
    [$h, $db, $wc, $order] = retirement_fixture();
    retirement_checkout([['key' => '_wl_checkout_cart_retired', 'value' => 'v1:'.RETIREMENT_NONCE.':'.str_repeat('a',32)]]);
    owned_expect('' === $order->get_meta('_wl_checkout_cart_retired') && !$wc->cart->is_empty());
    owned_expect(!$h->has_ordinary_checkout_cart_retirement());
};
$cases['old acknowledgment cannot substitute for a new retirement and is freshly replaced'] = function() {
    [$h, $db, $wc, $order] = retirement_fixture(true);
    $old = 'v1:'.RETIREMENT_NONCE.':'.str_repeat('a',32);
    $store = $order->get_data_store();
    $store->rows[] = (object) ['meta_id' => 2, 'meta_key' => '_wl_checkout_cart_retired', 'meta_value' => $old];
    $order->init_meta_data($store->rows);
    retirement_checkout();
    owned_expect(retirement_acknowledged($order) && $old !== $order->get_meta('_wl_checkout_cart_retired'));
    owned_expect($wc->cart->is_empty() && $h->has_ordinary_checkout_cart_retirement());
};
foreach (['missing', 'duplicate', 'mismatch', 'write-throws', 'scope', 'owner', 'paid', 'transaction', 'store', 'cart', 'binding'] as $failure) {
    $cases['acknowledgment '.$failure.' failure preserves retired order and fails session'] = function() use ($failure) {
        [$h, $db, $wc, $order] = retirement_fixture();
        $store = $order->get_data_store(); $submitted_cart = $wc->cart;
        if ($failure === 'missing') $store->persist = false;
        if ($failure === 'write-throws') $store->on_write = fn() => throw new RuntimeException('Controlled metadata failure.');
        $store->on_read = function($order, $store) use ($failure, $h) {
            if ($failure === 'duplicate') $store->rows[] = (object) ['meta_id' => 3, 'meta_key' => '_wl_checkout_cart_retired', 'meta_value' => 'old'];
            if ($failure === 'mismatch') foreach ($store->rows as $row) if ($row->meta_key === '_wl_checkout_cart_retired') $row->meta_value = 'old';
            if ($failure === 'scope') $h->discard_owned_scope();
            if ($failure === 'owner') $order->set_customer_id(18);
            if ($failure === 'paid') $order->set_date_paid(time());
            if ($failure === 'transaction') $order->set_transaction_id('other');
            if ($failure === 'store') save_property($order, 'data_store', new RetirementMetadataStore());
            if ($failure === 'binding') $h->set('wl_checkout_summary_orders', []);
            if ($failure === 'cart') WC()->cart = (new ReflectionClass(RetirementCart::class))->newInstanceWithoutConstructor();
        };
        owned_error(fn() => retirement_checkout());
        owned_expect($submitted_cart->is_empty() && $h->has_ordinary_checkout_cart_retirement());
        owned_expect(0 === \WPGraphQL\WooCommerce\Data\Mutation\Order_Mutation::$purges && !$order->is_paid());
        owned_error(fn() => $h->complete_owned_scope()); $h->discard_owned_scope();
        owned_expect('failed' === $db->state && 0 === $db->writes);
    };
}
$failed = []; $executed = 0;
foreach ($cases as $name => $test) {
    if (getenv('WL_RETIREMENT_ONLY') && !str_contains($name, getenv('WL_RETIREMENT_ONLY'))) continue;
    ++$executed;
    try { $test(); } catch (Throwable $e) { $failed[] = $name; $caller = $e->getTrace()[0]['line'] ?? $e->getLine(); fwrite(STDERR, $name.': '.get_class($e).' '.$e->getMessage().' caller '.$caller."\n"); }
}
echo json_encode(['suite' => 'deferred-cart-retirement', 'cases' => $executed, 'failed' => $failed,
    'limits' => 'Actual checkout entry/process, owned handler and native cart/session methods; controlled origin/order insertion/factory/totals/SQL/hooks. No installed HTTP/provider proof.'], JSON_THROW_ON_ERROR)."\n";
exit($failed ? 1 : 0);
}

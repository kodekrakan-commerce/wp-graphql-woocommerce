<?php
/** Actual protected free-order helper; controlled order/save/callback boundary.
 * A recorded paid state followed by false models native Woo's post-save catch.
 * No WordPress bootstrap, native datastore, payment or callback execution is claimed.
 */
error_reporting( E_ALL ); ini_set( 'display_errors', '0' ); ini_set( 'log_errors', '0' );
$graphql = rtrim( getenv( 'WL_WPGRAPHQL_SOURCE' ) ?: '', '/' );
$owner = dirname( __DIR__, 2 );
if ( ! is_file( $graphql . '/vendor/autoload.php' ) ) { fwrite( STDERR, "Qualified GraphQL source required.\n" ); exit( 2 ); }
require $graphql . '/vendor/autoload.php';
require $owner . '/includes/data/mutation/class-checkout-mutation.php';

final class WC_Order {
	public bool $durable = false;
	public bool $paid = false;
	public bool $preserved = true;
	public int $calls = 0;
	public function __construct( public string $mode ) {}
	public function payment_complete( $transaction ) {
		++$this->calls;
		if ( '' !== $transaction ) { throw new RuntimeException( 'Unexpected transaction.' ); }
		$this->durable = $this->paid = true;
		if ( 'throw' === $this->mode ) { throw new RuntimeException( 'Controlled native exception.' ); }
		return 'true' === $this->mode;
	}
	public function get_checkout_order_received_url() { ++$GLOBALS['free_completion_redirects']; return '/synthetic-order'; }
}
function wc_get_order( $id ) { return 77 === $id ? $GLOBALS['free_completion_order'] : null; }
function __( $text, $domain = '' ) { return $text; }
function apply_filters( $hook, $value, ...$args ) { ++$GLOBALS['free_completion_filters']; return $value; }

$method = new ReflectionMethod( \WPGraphQL\WooCommerce\Data\Mutation\Checkout_Mutation::class, 'process_order_without_payment' );
$failures = 0;
foreach ( [ 'false-after-save', 'true', 'throw', 'missing-order' ] as $case ) {
	$GLOBALS['free_completion_order'] = new WC_Order( $case );
	$GLOBALS['free_completion_redirects'] = $GLOBALS['free_completion_filters'] = 0;
	$result = $error = null;
	try { $result = $method->invoke( null, 'missing-order' === $case ? 78 : 77 ); }
	catch ( Throwable $caught ) { $error = $caught; }
	$order = $GLOBALS['free_completion_order'];
	if ( 'true' === $case ) {
		$ok = null === $error && [ 'result' => 'success', 'redirect' => '/synthetic-order' ] === $result
			&& 1 === $order->calls && 1 === $GLOBALS['free_completion_redirects'] && 1 === $GLOBALS['free_completion_filters'];
	} else {
		$ok = null === $result && $error instanceof Exception && $order->preserved
			&& 0 === $GLOBALS['free_completion_redirects'] && 0 === $GLOBALS['free_completion_filters'];
		if ( 'false-after-save' === $case ) {
			$ok = $ok && $error instanceof \GraphQL\Error\UserError && $order->durable && $order->paid && 1 === $order->calls;
		}
		if ( 'throw' === $case ) { $ok = $ok && $error instanceof RuntimeException && 1 === $order->calls; }
		if ( 'missing-order' === $case ) { $ok = $ok && 0 === $order->calls; }
	}
	if ( ! $ok ) { ++$failures; }
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $case . " [controlled completion boundary]\n";
}
echo 'Cases: 4; failures: ' . $failures . "\n";
exit( $failures ? 1 : 0 );

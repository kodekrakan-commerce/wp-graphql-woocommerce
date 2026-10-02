<?php
/** Controlled external boundaries; the candidate Checkout closure is never copied. */
namespace WPGraphQL\WooCommerce\Data\Mutation {
	class Checkout_Mutation {
		public static function prepare_checkout_args( $input, $context, $info ) {
			$GLOBALS['checkout_contract']['context'] = $context;
			$GLOBALS['checkout_contract']['info'] = $info;
			$GLOBALS['checkout_contract']['input'] = $input;
			if ( 'before' === $GLOBALS['checkout_contract']['phase'] ) {
				throw $GLOBALS['checkout_contract']['error'];
			}
			return [ 'fixture' => true ];
		}
		public static function process_checkout( $args, $input, $context, $info, &$results ) {
			++$GLOBALS['checkout_contract']['created'];
			$results = [ 'result' => 'synthetic-success', 'redirect' => '/synthetic-order' ];
			$GLOBALS['checkout_contract']['results'] = $results;
			$GLOBALS['checkout_contract']['order'] = (object) [ 'id' => 77, 'durable' => true, 'preserved' => true ];
			return 77;
		}
	}
	class Order_Mutation {
		public static function purge( $order ) {
			++$GLOBALS['checkout_contract']['purged'];
			$order->preserved = false;
		}
	}
}
namespace {
	function __( $text, $domain = '' ) { return $text; }
	function do_action( $name, ...$args ) {
		$GLOBALS['checkout_contract']['hooks'][] = $name;
		if ( 'graphql_woocommerce_after_checkout' === $name ) {
			$GLOBALS['checkout_contract']['retrieved_order'] = $args[0];
			if ( 'after' === $GLOBALS['checkout_contract']['phase'] ) {
				throw $GLOBALS['checkout_contract']['error'];
			}
		}
	}
	class WC_Order_Factory {
		public static function get_order( $id ) {
			++$GLOBALS['checkout_contract']['retrieved'];
			return $GLOBALS['checkout_contract']['order'];
		}
	}
}

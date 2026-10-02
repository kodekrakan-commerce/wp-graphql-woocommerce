<?php
/**
 * Stable, non-sensitive GraphQL errors for the cart-session boundary.
 *
 * @package WPGraphQL\WooCommerce\Utils
 */

namespace WPGraphQL\WooCommerce\Utils;

use GraphQL\Error\ProvidesExtensions;
use GraphQL\Error\UserError;

/** Do not expose decoder, key/filter, database or credential details. */
final class Cart_Session_Error extends UserError implements ProvidesExtensions {

	const INVALID     = 'WL_CART_SESSION_INVALID';
	const UNAVAILABLE = 'WL_CART_SESSION_UNAVAILABLE';

	/** @var string */
	private $session_code;

	/** @param string $code Stable session classification. */
	public function __construct( $code ) {
		$this->session_code = self::INVALID === $code ? self::INVALID : self::UNAVAILABLE;
		parent::__construct(
			self::INVALID === $this->session_code
				? __( 'The cart session is invalid.', 'wp-graphql-woocommerce' )
				: __( 'The cart session is temporarily unavailable.', 'wp-graphql-woocommerce' )
		);
	}

	/** @return array<string,string> */
	public function getExtensions(): ?array {
		return [ 'code' => $this->session_code ];
	}
}

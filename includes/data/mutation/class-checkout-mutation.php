<?php
/**
 * Defines helper functions for user checkout.
 *
 * @package WPGraphQL\WooCommerce\Data\Mutation
 * @since 0.2.0
 */

namespace WPGraphQL\WooCommerce\Data\Mutation;

use GraphQL\Error\UserError;
use WP_Error;

use function WC;

/**
 * Class - Checkout_Mutation
 */
class Checkout_Mutation {
	/**
	 * Caches customer object. @see get_value.
	 *
	 * @var null|\WC_Customer
	 */
	private static $logged_in_customer = null;

	/**
	 * Is registration required to checkout?
	 *
	 * @since  3.0.0
	 * @return boolean
	 */
	public static function is_registration_required() {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		return apply_filters( 'woocommerce_checkout_registration_required', 'yes' !== get_option( 'woocommerce_enable_guest_checkout' ) );
	}

	/**
	 * See if a fieldset should be skipped.
	 *
	 * @since 3.0.0
	 * @param string $fieldset_key Fieldset key.
	 * @param array  $data         Posted data.
	 * @return bool
	 */
	protected static function maybe_skip_fieldset( $fieldset_key, $data ) {
		if ( 'shipping' === $fieldset_key && ( ! $data['ship_to_different_address'] && ! \WC()->cart->needs_shipping_address() ) ) {
			return true;
		}

		if ( 'account' === $fieldset_key && ( is_user_logged_in() || ( ! self::is_registration_required() && empty( $data['createaccount'] ) ) ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Returns order data for use when user checking out.
	 *
	 * @param array                                $input    Input data describing order.
	 * @param \WPGraphQL\AppContext                $context  AppContext instance.
	 * @param \GraphQL\Type\Definition\ResolveInfo $info     ResolveInfo instance.
	 *
	 * @return array
	 */
	public static function prepare_checkout_args( $input, $context, $info ) {
		$data = [
			'terms'                     => (int) isset( $input['terms'] ),
			'createaccount'             => (int) ! empty( $input['account'] ),
			'payment_method'            => isset( $input['paymentMethod'] ) ? $input['paymentMethod'] : '',
			'shipping_method'           => isset( $input['shippingMethod'] ) ? $input['shippingMethod'] : '',
			'ship_to_different_address' => ! empty( $input['shipToDifferentAddress'] ) && ! wc_ship_to_billing_address_only(),
		];

		$skipped = [];
		foreach ( self::get_checkout_fields() as $fieldset_key => $fieldset ) {
			if ( self::maybe_skip_fieldset( $fieldset_key, $data ) ) {
				$skipped[] = $fieldset_key;
				continue;
			}

			foreach ( $fieldset as $field => $input_key ) {
				$key = "{$fieldset_key}_{$field}";
				if ( 'order' === $fieldset_key ) {
					$value = ! empty( $input[ $input_key ] ) ? $input[ $input_key ] : null;
				} else {
					$value = ! empty( $input[ $fieldset_key ][ $input_key ] ) ? $input[ $fieldset_key ][ $input_key ] : null;
				}

				if ( $value ) {
					$data[ $key ] = $value;
				} elseif ( 'billing_country' === $key || 'shipping_country' === $key ) {
					$data[ $key ] = self::get_value( $key );
				}
			}
		}//end foreach

		if ( in_array( 'shipping', $skipped, true ) && ( \WC()->cart->needs_shipping_address() || \wc_ship_to_billing_address_only() ) ) {
			foreach ( self::get_checkout_fields( 'shipping' ) as $field => $input_key ) {
				$data[ "shipping_{$field}" ] = isset( $data[ "billing_{$field}" ] ) ? $data[ "billing_{$field}" ] : '';
			}
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		return apply_filters( 'woocommerce_checkout_posted_data', $data, $input, $context, $info );
	}

	/**
	 * Get an array of checkout fields.
	 *
	 * @param string  $fieldset Target fieldset.
	 * @param boolean $prefixed Prefixed field keys with fieldset name.
	 *
	 * @return array
	 */
	public static function get_checkout_fields( $fieldset = '', $prefixed = false ) {
		$fields = [
			'billing'  => [
				'first_name' => 'firstName',
				'last_name'  => 'lastName',
				'company'    => 'company',
				'address_1'  => 'address1',
				'address_2'  => 'address2',
				'city'       => 'city',
				'postcode'   => 'postcode',
				'state'      => 'state',
				'country'    => 'country',
				'phone'      => 'phone',
				'email'      => 'email',
			],
			'shipping' => [
				'first_name' => 'firstName',
				'last_name'  => 'lastName',
				'company'    => 'company',
				'address_1'  => 'address1',
				'address_2'  => 'address2',
				'city'       => 'city',
				'postcode'   => 'postcode',
				'state'      => 'state',
				'country'    => 'country',
			],
			'account'  => [
				'username' => 'username',
				'password' => 'password',
			],
			'order'    => [
				'comments' => 'customerNote',
			],
		];

		if ( $prefixed ) {
			foreach ( $fields as $prefix => $values ) {
				foreach ( $values as $index => $value ) {
					$fields[ $prefix ][ $index ] = "{$prefix}_{$value}";
				}
			}
		}

		if ( ! empty( $fieldset ) ) {
			return ! empty( $fields[ $fieldset ] ) ? $fields[ $fieldset ] : [];
		}

		return $fields;
	}

	/**
	 * Update customer and session data from the posted checkout data.
	 *
	 * @param array $data Order data.
	 *
	 * @return void
	 */
	protected static function update_session( $data ) {
		// Update both shipping and billing to the passed billing address first if set.
		$address_fields = [
			'first_name',
			'last_name',
			'company',
			'email',
			'phone',
			'address_1',
			'address_2',
			'city',
			'postcode',
			'state',
			'country',
		];

		foreach ( $address_fields as $field ) {
			self::set_customer_address_fields( $field, $data );
		}
		WC()->customer->save();

		// Update customer shipping and payment method to posted method.
		$chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods' );

		if ( is_array( $data['shipping_method'] ) ) {
			foreach ( $data['shipping_method'] as $i => $value ) {
				$chosen_shipping_methods[ $i ] = $value;
			}
		}

		WC()->session->set( 'chosen_shipping_methods', $chosen_shipping_methods );
		WC()->session->set( 'chosen_payment_method', $data['payment_method'] );

		// Update cart totals now we have customer address.
		WC()->cart->calculate_totals();
	}

	/**
	 * Clears customer address
	 *
	 * @param string $type  Address type.
	 *
	 * @return bool
	 */
	protected static function clear_customer_address( $type = 'billing' ) {
		if ( 'billing' !== $type && 'shipping' !== $type ) {
			return false;
		}

		$address = [
			'first_name' => '',
			'last_name'  => '',
			'company'    => '',
			'address_1'  => '',
			'address_2'  => '',
			'city'       => '',
			'state'      => '',
			'postcode'   => '',
			'country'    => '',
		];

		if ( 'billing' === $type ) {
			$address = array_merge(
				$address,
				[
					'email' => '',
					'phone' => '',
				]
			);
		}

		foreach ( $address as $prop => $value ) {
			$setter = "set_{$type}_{$prop}";
			WC()->customer->{$setter}( $value );
		}

		return true;
	}

	/**
	 * Create a new customer account if needed.
	 *
	 * @param array $data Checkout data.
	 *
	 * @throws \GraphQL\Error\UserError When not able to create customer.
	 *
	 * @return void
	 */
	protected static function process_customer( $data, $context, $info ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$customer_id = apply_filters( 'woocommerce_checkout_customer_id', get_current_user_id() );

		$session = WC()->session;
		$is_graphql_session = $session instanceof \WPGraphQL\WooCommerce\Utils\QL_Session_Handler && $session->is_graphql_session();
		if ( $is_graphql_session ) {
			$session->assert_session_ready();
			if ( (string) $customer_id !== (string) get_current_user_id() ) {
				throw new \WPGraphQL\WooCommerce\Utils\Cart_Session_Transition_Error();
			}
		}

		if ( ! is_user_logged_in() && ( self::is_registration_required() || ! empty( $data['createaccount'] ) ) ) {
			// Consume authority only in this final filtered branch, after its policy decision.
			if ( $is_graphql_session ) {
				$customer_id = $session->create_checkout_customer( $data, $context, $info );
			} else {
				$username = ! empty( $data['account_username'] ) ? $data['account_username'] : '';
				$password = ! empty( $data['account_password'] ) ? $data['account_password'] : '';
				$customer_id = wc_create_new_customer( $data['billing_email'], $username, $password,
					[ 'first_name' => $data['billing_first_name'] ?? '', 'last_name' => $data['billing_last_name'] ?? '' ] );
				if ( is_wp_error( $customer_id ) ) { throw new UserError( $customer_id->get_error_message() ); }
				wc_set_customer_auth_cookie( $customer_id );
			}

			// As we are now logged in, checkout will need to refresh to show logged in data.
			WC()->session->set( 'reload_checkout', true );

			// Also, recalculate cart totals to reveal any role-based discounts that were unavailable before registering.
			WC()->cart->calculate_totals();
		}//end if

		// On multisite, ensure user exists on current site, if not add them before allowing login.
		if ( $customer_id && is_multisite() && is_user_logged_in() && ! is_user_member_of_blog() ) {
			add_user_to_blog( get_current_blog_id(), $customer_id, 'customer' );
		}

		// Add customer info from other fields.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		if ( $customer_id && apply_filters( 'woocommerce_checkout_update_customer_data', true, WC()->checkout() ) ) {
			$customer = new \WC_Customer( $customer_id );

			if ( ! empty( $data['billing_first_name'] ) && '' === $customer->get_first_name() ) {
				$customer->set_first_name( $data['billing_first_name'] );
			}

			if ( ! empty( $data['billing_last_name'] ) && '' === $customer->get_last_name() ) {
				$customer->set_last_name( $data['billing_last_name'] );
			}

			// If the display name is an email, update to the user's full name.
			if ( is_email( $customer->get_display_name() ) ) {
				$customer->set_display_name( $customer->get_first_name() . ' ' . $customer->get_last_name() );
			}

			foreach ( $data as $key => $value ) {
				// Use setters where available.
				if ( is_callable( [ $customer, "set_{$key}" ] ) ) {
					$customer->{"set_{$key}"}( $value );

					// Store custom fields prefixed with wither shipping_ or billing_.
				} elseif ( 0 === stripos( $key, 'billing_' ) || 0 === stripos( $key, 'shipping_' ) ) {
					$customer->update_meta_data( $key, $value );
				}
			}

			// Action hook to adjust customer before save.
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			do_action( 'woocommerce_checkout_update_customer', $customer, $data );

			$customer->save();
		}//end if

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( 'woocommerce_checkout_update_user_meta', $customer_id, $data );
	}

	/**
	 * Set address field for customer.
	 *
	 * @param string $field String to update.
	 * @param array  $data  Array of data to get the value from.
	 *
	 * @return void
	 */
	protected static function set_customer_address_fields( $field, $data ) {
		$billing_value  = null;
		$shipping_value = null;

		if ( isset( $data[ "billing_{$field}" ] ) && is_callable( [ WC()->customer, "set_billing_{$field}" ] ) ) {
			$billing_value  = $data[ "billing_{$field}" ];
			$shipping_value = $data[ "billing_{$field}" ];
		}

		if ( isset( $data[ "shipping_{$field}" ] ) && is_callable( [ WC()->customer, "set_shipping_{$field}" ] ) ) {
			$shipping_value = $data[ "shipping_{$field}" ];
		}

		if ( ! is_null( $billing_value ) && is_callable( [ WC()->customer, "set_billing_{$field}" ] ) ) {
			WC()->customer->{"set_billing_{$field}"}( $billing_value );
		}

		if ( ! is_null( $shipping_value ) && is_callable( [ WC()->customer, "set_shipping_{$field}" ] ) ) {
			WC()->customer->{"set_shipping_{$field}"}( $shipping_value );
		}
	}

	/**
	 * Validates the posted checkout data based on field properties.
	 *
	 * @param array $data  Checkout data.
	 *
	 * @throws \GraphQL\Error\UserError Invalid input.
	 *
	 * @return void
	 */
	protected static function validate_data( &$data ) {
		foreach ( self::get_checkout_fields( '', true ) as $fieldset_key => $fieldset ) {
			$validate_fieldset = true;
			if ( self::maybe_skip_fieldset( $fieldset_key, $data ) ) {
				$validate_fieldset = false;
			}

			foreach ( $fieldset as $key => $field_label ) {
				if ( ! isset( $data[ $key ] ) ) {
					continue;
				}

				if ( \str_ends_with( $key, 'postcode' ) ) {
					$country      = isset( $data[ $fieldset_key . '_country' ] ) ? $data[ $fieldset_key . '_country' ] : WC()->customer->{"get_{$fieldset_key}_country"}();
					$data[ $key ] = \wc_format_postcode( $data[ $key ], $country );

					if ( $validate_fieldset && '' !== $data[ $key ] && ! \WC_Validation::is_postcode( $data[ $key ], $country ) ) {
						switch ( $country ) {
							case 'IE':
								/* translators: %1$s: field name, %2$s finder.eircode.ie URL */
								$postcode_validation_notice = sprintf( __( '%1$s is not valid. You can look up the correct Eircode. %2$s', 'wp-graphql-woocommerce' ), $field_label, 'https://finder.eircode.ie' );
								break;
							default:
								/* translators: %s: field name */
								$postcode_validation_notice = sprintf( __( '%s is not a valid postcode / ZIP.', 'wp-graphql-woocommerce' ), $field_label );
						}
						// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
						throw new UserError( apply_filters( 'woocommerce_checkout_postcode_validation_notice', $postcode_validation_notice, $country, $data[ $key ] ) );
					}
				}

				if ( \str_ends_with( $key, 'phone' ) ) {
					if ( $validate_fieldset && '' !== $data[ $key ] && ! \WC_Validation::is_phone( $data[ $key ] ) ) {
						/* translators: %s: phone number */
						throw new UserError( sprintf( __( '%s is not a valid phone number.', 'wp-graphql-woocommerce' ), $field_label ) );
					}
				}

				if ( \str_ends_with( $key, 'email' ) && '' !== $data[ $key ] ) {
					$email_is_valid = is_email( $data[ $key ] );
					$data[ $key ]   = sanitize_email( $data[ $key ] );

					if ( $validate_fieldset && ! $email_is_valid ) {
						/* translators: %s: email address */
						throw new UserError( sprintf( __( '%s is not a valid email address.', 'wp-graphql-woocommerce' ), $field_label ) );
					}
				}

				if ( \str_ends_with( $key, 'state' ) && '' !== $data[ $key ] ) {
					$country      = isset( $data[ $fieldset_key . '_country' ] ) ? $data[ $fieldset_key . '_country' ] : WC()->customer->{"get_{$fieldset_key}_country"}();
					$valid_states = WC()->countries->get_states( $country );

					if ( ! empty( $valid_states ) && is_array( $valid_states ) ) {
						$valid_state_values = array_map( 'wc_strtoupper', array_flip( array_map( 'wc_strtoupper', $valid_states ) ) );
						$data[ $key ]       = wc_strtoupper( $data[ $key ] );

						if ( isset( $valid_state_values[ $data[ $key ] ] ) ) {
							// With this part we consider state value to be valid as well, convert it to the state key for the valid_states check below.
							$data[ $key ] = $valid_state_values[ $data[ $key ] ];
						}

						if ( $validate_fieldset && ! in_array( $data[ $key ], $valid_state_values, true ) ) {
							/* translators: 1: state field 2: valid states */
							throw new UserError( sprintf( __( '%1$s is not valid. Please enter one of the following: %2$s', 'wp-graphql-woocommerce' ), $field_label, implode( ', ', $valid_states ) ) );
						}
					}
				}
			}//end foreach
		}//end foreach
	}

	/**
	 * Validates that the checkout has enough info to proceed.
	 *
	 * @param array $data  An array of posted data.
	 *
	 * @throws \GraphQL\Error\UserError Invalid input.
	 *
	 * @return void
	 */
	protected static function validate_checkout( &$data ) {
		self::validate_data( $data );
		WC()->checkout()->check_cart_items();

		// Throw cart validation errors stored in the session.
		$cart_item_errors = wc_get_notices( 'error' );

		if ( ! empty( $cart_item_errors ) ) {
			$cart_item_error_msgs = implode( ' ', array_column( $cart_item_errors, 'notice' ) );
			\wc_clear_notices();
			throw new UserError( $cart_item_error_msgs );
		}

		if ( WC()->cart->needs_shipping() ) {
			$shipping_country = WC()->customer->get_shipping_country();

			if ( empty( $shipping_country ) ) {
				throw new UserError( __( 'Please enter an address to continue.', 'wp-graphql-woocommerce' ) );
			} elseif ( ! in_array( WC()->customer->get_shipping_country(), array_keys( WC()->countries->get_shipping_countries() ), true ) ) {
				throw new UserError(
					sprintf(
						/* translators: %s: shipping location */
						__( 'Unfortunately, we do not ship %s. Please enter an alternative shipping address.', 'wp-graphql-woocommerce' ),
						WC()->countries->shipping_to_prefix() . ' ' . WC()->customer->get_shipping_country()
					)
				);
			} else {
				$chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods' );

				foreach ( WC()->shipping()->get_packages() as $i => $package ) {
					if ( ! isset( $chosen_shipping_methods[ $i ], $package['rates'][ $chosen_shipping_methods[ $i ] ] ) ) {
						throw new UserError( __( 'No shipping method has been selected. Please double check your address, or contact us if you need any help.', 'wp-graphql-woocommerce' ) );
					}
				}
			}
		}//end if

		if ( WC()->cart->needs_payment() ) {
			$available_gateways = WC()->payment_gateways->get_available_payment_gateways();
			if ( ! isset( $available_gateways[ $data['payment_method'] ] ) ) {
				throw new UserError( __( 'Invalid payment method.', 'wp-graphql-woocommerce' ) );
			} else {
				$available_gateways[ $data['payment_method'] ]->validate_fields();
			}
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( 'woocommerce_after_checkout_validation', $data, new WP_Error() );
	}

	/**
	 * Process an order that does require payment.
	 *
	 * @param int    $order_id       Order ID.
	 * @param string $payment_method Payment method.
	 *
	 * @throws \GraphQL\Error\UserError When payment method is invalid.
	 *
	 * @return array Processed payment results.
	 */
	protected static function process_order_payment( $order_id, $payment_method, $owned_order = null ) {
		$available_gateways = WC()->payment_gateways->get_available_payment_gateways();

		if ( ! isset( $available_gateways[ $payment_method ] ) ) {
			throw new UserError( __( 'Cannot process invalid payment method.', 'wp-graphql-woocommerce' ) );
		}

		// Store Order ID in session so it can be re-used after payment failure.
		WC()->session->set( 'order_awaiting_payment', $order_id );

		/**
		 * Allow an integration to defer payment after checkout validation and order creation.
		 *
		 * Return null for normal gateway processing, or a payment result array.
		 * Negotiated successful deferral retires the submitted cart before HTTP completion.
		 * Protected account creation separately verifies its destination lifecycle.
		 * This hook never applies to prepaid/free orders or native WooCommerce checkout.
		 *
		 * @param array|null $result         Payment result override.
		 * @param int        $order_id       Validated checkout order ID.
		 * @param string     $payment_method Available gateway ID.
		 * @param \WC_Order|null $owned_order Exact native order for protected callers.
		 */
		$handler = WC()->session instanceof \WPGraphQL\WooCommerce\Utils\QL_Session_Handler ? WC()->session : null;
		if ( null !== $owned_order ) {
			if ( ! $handler || ! $handler->protects_checkout_order() || $owned_order !== $handler->created_checkout_order( $order_id ) ) { if ( $handler && $handler->protects_checkout_order() ) { $handler->fail_checkout_order(); } throw new \WPGraphQL\WooCommerce\Utils\Cart_Session_Transition_Error(); }
			$handler->begin_checkout_deferred_payment( $owned_order );
			try { $deferred_result = apply_filters( 'graphql_woocommerce_checkout_payment_result', null, $order_id, $payment_method, $owned_order ); }
			catch ( \Throwable $error ) { $handler->fail_checkout_order( $error ); }
			$handler->finish_checkout_deferred_payment( $owned_order, $deferred_result );
			return $deferred_result;
		}
		$deferred_result = apply_filters( 'graphql_woocommerce_checkout_payment_result', null, $order_id, $payment_method );
		if ( null !== $deferred_result ) {
			if ( ! is_array( $deferred_result ) || ! isset( $deferred_result['result'], $deferred_result['redirect'] ) ) {
				throw new UserError( __( 'Invalid checkout payment result.', 'wp-graphql-woocommerce' ) );
			}
			return $deferred_result;
		}

		$process_payment_args = apply_filters(
			"graphql_{$payment_method}_process_payment_args",
			[ $order_id ],
			$payment_method
		);

		// Process Payment.
		return $available_gateways[ $payment_method ]->process_payment( ...$process_payment_args );
	}

	/**
	 * Process an order that doesn't require payment.
	 *
	 * @since 3.0.0
	 * @param int    $order_id        Order ID.
	 * @param string $transaction_id  Payment transaction ID.
	 *
	 * @throws \Exception Order cannot be retrieved.
	 *
	 * @return array
	 */
	protected static function process_order_without_payment( $order_id, $transaction_id = '', $owned_order = null ) {
		$order = null !== $owned_order ? $owned_order : wc_get_order( $order_id );
		if ( ! is_object( $order ) || ! is_a( $order, \WC_Order::class ) ) {
			throw new \Exception( __( 'Failed to retrieve order.', 'wp-graphql-woocommerce' ) );
		}

		// Native completion can save paid status before a later callback fails.
		// Its boolean result, not saved status alone, controls checkout success.
		if ( true !== $order->payment_complete( $transaction_id ) ) {
			throw new UserError( __( 'Unable to complete this order. Please contact the store before trying again.', 'wp-graphql-woocommerce' ) );
		}

		return [
			'result'   => 'success',
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			'redirect' => apply_filters( 'woocommerce_checkout_no_payment_needed_redirect', $order->get_checkout_order_received_url(), $order ),
		];
	}

	/**
	 * Process the checkout.
	 *
	 * @param array                                $data     Order data.
	 * @param array                                $input    Input data describing order.
	 * @param \WPGraphQL\AppContext                $context  AppContext instance.
	 * @param \GraphQL\Type\Definition\ResolveInfo $info     ResolveInfo instance.
	 * @param array                                $results  Order status.
	 *
	 * @throws \GraphQL\Error\UserError When validation fails.
	 *
	 * @return int Order ID.
	 */
	public static function process_checkout( $data, $input, $context, $info, &$results = null ) {
		$retirement_nonce = self::requested_cart_retirement( $input );
		wc_maybe_define_constant( 'WOOCOMMERCE_CHECKOUT', true );
		wc_set_time_limit( 0 );

		do_action( 'woocommerce_before_checkout_process' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		if ( WC()->cart->is_empty() ) {
			throw new UserError( __( 'Sorry, no session found.', 'wp-graphql-woocommerce' ) );
		}

		do_action( 'woocommerce_checkout_process', $data, $context, $info ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		if ( ! empty( $input['billing']['overwrite'] ) && true === $input['billing']['overwrite'] ) {
			self::clear_customer_address( 'billing' );
		}

		if ( ! empty( $input['shipping'] ) && ! empty( $input['shipping']['overwrite'] )
			&& true === $input['shipping']['overwrite'] ) {
			self::clear_customer_address( 'shipping' );
		}

		// Update session for customer and totals.
		self::update_session( $data );

		// WooCommerce's pending order can reserve the last available stock.
		// Its normal stock validation then rejects an identical checkout retry
		// before create_order() reaches its native cart-hash reuse branch. Only
		// reuse an already validated, unchanged order in this exact session.
		$retry_id = absint( WC()->session->get( 'order_awaiting_payment' ) );
		$retry_order = $retry_id ? wc_get_order( $retry_id ) : false;
		if ( empty( $data['createaccount'] ) && $retry_order instanceof \WC_Order
			&& $retry_order->get_meta( '_woonuxt_deferred_payment' ) === 'yes'
			&& $retry_order->get_payment_method() === 'stripe'
			&& $data['payment_method'] === 'stripe'
			&& $retry_order->has_status( [ 'pending', 'failed' ] )
			&& ! $retry_order->is_paid()
			&& $retry_order->has_cart_hash( WC()->cart->get_cart_hash() )
			&& (int) $retry_order->get_customer_id() === get_current_user_id()
			&& self::matches_retry_checkout_data( $retry_order, $data ) ) {
			$results = [ 'result' => 'pending', 'redirect' => '' ];
			if ( null !== $retirement_nonce ) { self::retire_deferred_checkout_cart( $retry_id, $retry_order, $results, $retirement_nonce ); }
			return $retry_id;
		}

		// Validate posted data and cart items before proceeding.
		self::validate_checkout( $data );

		self::process_customer( $data, $context, $info );
		$handler = WC()->session instanceof \WPGraphQL\WooCommerce\Utils\QL_Session_Handler ? WC()->session : null;
		if ( $handler && $handler->has_checkout_creation() ) {
			$handler->protect_checkout_order();
			if ( WC()->session->get( 'order_awaiting_payment' ) ) {
				throw new \WPGraphQL\WooCommerce\Utils\Cart_Session_Transition_Error();
			}
		}
		$order_id = WC()->checkout->create_order( $data );
		if ( is_wp_error( $order_id ) ) {
			throw new UserError( $order_id->get_error_message() );
		}

		$order = $handler && $handler->protects_checkout_order() ? $handler->created_checkout_order( $order_id ) : wc_get_order( $order_id );
		if ( $handler ) { $handler->verify_checkout_order_return( $order_id, $order ); }

		if ( ! is_object( $order ) || ! is_a( $order, \WC_Order::class ) ) {
			throw new UserError( __( 'Unable to create order.', 'wp-graphql-woocommerce' ) );
		}

		// A narrow guest summary can be reloaded after redirect or cart clearing.
		// Bind only the newly validated order to this server-side WC session.
		if ( WC()->session && $order->get_order_key() ) {
			$summary_orders = WC()->session->get( 'wl_checkout_summary_orders', [] );
			$summary_orders = is_array( $summary_orders ) ? $summary_orders : [];
			$summary_orders[ $order_id ] = hash( 'sha256', $order->get_order_key() );
			WC()->session->set( 'wl_checkout_summary_orders', array_slice( $summary_orders, -10, null, true ) );
		}

		// Add meta data.
		if ( ! empty( $input['metaData'] ) ) {
			self::update_order_meta( $order_id, $input['metaData'], $input, $context, $info, $handler && $handler->protects_checkout_order() ? $order : null );
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( 'woocommerce_checkout_order_processed', $order_id, $data, $order );

		// The browser cannot attest that an order has been paid. In particular,
		// isPaid, transactionId and checkout metadata are not provider evidence.
		// Use the newly created order (after validation/totals) as the authority.
		if ( $order->needs_payment() ) {
			$results = self::process_order_payment( $order_id, $data['payment_method'], $handler && $handler->protects_checkout_order() ? $order : null );
		} else {
			if ( $handler && $handler->protects_checkout_order() ) { $handler->begin_checkout_free_payment( $order ); }
			$results = self::process_order_without_payment( $order_id, '', $handler && $handler->protects_checkout_order() ? $order : null );
		}//end if

		if ( null !== $retirement_nonce && ! ( $handler && $handler->protects_checkout_order() ) && [ 'result' => 'pending', 'redirect' => '' ] === $results
			&& 'stripe' === $data['payment_method'] ) {
			self::retire_deferred_checkout_cart( $order_id, $order, $results, $retirement_nonce );
		}

		if ( 'success' === $results['result'] || ( $handler && $handler->protects_checkout_order() && 'pending' === $results['result'] ) ) {
			if ( $handler && $handler->protects_checkout_order() ) {
				// Captured guest writers are closed; make the actual empty-cart effect explicit.
				WC()->cart->empty_cart();
				$handler->get_owned_lifecycle()->flush_empty_checkout_cart();
			} else { wc_empty_cart(); }
		}

		return $order_id;
	}

	/** Read the optional versioned retirement request without persisting browser metadata. */
	private static function requested_cart_retirement( $input ): ?string {
		$values = [];
		foreach ( (array) ( $input['metaData'] ?? [] ) as $meta ) {
			if ( ( $meta['key'] ?? null ) === '_wl_checkout_cart_retirement' ) { $values[] = $meta['value'] ?? null; }
		}
		if ( ! $values ) { return null; }
		if ( 1 !== count( $values ) || ! is_string( $values[0] )
			|| ! preg_match( '/\Av1:([a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12})\z/D', $values[0], $match ) ) {
			throw new \WPGraphQL\WooCommerce\Utils\Cart_Session_Transition_Error();
		}
		return $match[1];
	}

	/** Retire the submitted cart within its held scope, then persist a fresh acknowledgment.
	 * The order receipt owns payment retries; an old receipt never clears a cart.
	 */
	private static function retire_deferred_checkout_cart( $order_id, $order, $result, string $request_nonce ): void {
		$wc = WC();
		$handler = $wc->session ?? null;
		$cart = $wc->cart ?? null;
		if ( ! $handler instanceof \WPGraphQL\WooCommerce\Utils\QL_Session_Handler
			|| $handler->protects_checkout_order() || ! $cart instanceof \WC_Cart
			|| ! is_int( $order_id ) || $order_id <= 0 || ! $order instanceof \WC_Order
			|| [ 'result' => 'pending', 'redirect' => '' ] !== $result ) {
			throw new \WPGraphQL\WooCommerce\Utils\Cart_Session_Transition_Error();
		}
		$handler->assert_session_ready();
		$handler->assert_owned_scope();
		if ( $order->get_id() !== $order_id
			|| 'stripe' !== $order->get_payment_method() || 'yes' !== $order->get_meta( '_woonuxt_deferred_payment' )
			|| ! $order->has_status( [ 'pending', 'failed' ] ) || $order->is_paid() || $order->get_date_paid() || $order->get_transaction_id()
			|| (int) $order->get_customer_id() !== get_current_user_id()
			|| (int) $handler->get( 'order_awaiting_payment' ) !== $order_id
			|| $cart->is_empty() || ! $order->has_cart_hash( $cart->get_cart_hash() ) ) {
			throw new \WPGraphQL\WooCommerce\Utils\Cart_Session_Transition_Error();
		}
		// Carry the exact awaiting-session authorization into the existing narrow
		// receipt binding before native cart clearing removes the awaiting ID.
		$order_key = $order->get_order_key();
		if ( ! is_string( $order_key ) || '' === $order_key ) {
			throw new \WPGraphQL\WooCommerce\Utils\Cart_Session_Transition_Error();
		}
		$binding = hash( 'sha256', $order_key );
		$summary_orders = $handler->get( 'wl_checkout_summary_orders', [] );
		$summary_orders = is_array( $summary_orders ) ? $summary_orders : [];
		unset( $summary_orders[ $order_id ] );
		$summary_orders[ $order_id ] = $binding;
		$handler->set( 'wl_checkout_summary_orders', array_slice( $summary_orders, -10, null, true ) );
		$handler->begin_ordinary_checkout_cart_retirement( $order );
		// Include the persistent cart, so a later sign-in cannot resurrect this order.
		$cart->empty_cart( true );
		$handler->assert_session_ready();
		$handler->assert_owned_scope();
		if ( WC()->session !== $handler || WC()->cart !== $cart || ! $cart->is_empty()
			|| $handler->get( 'cart' ) || $handler->get( 'order_awaiting_payment' )
			|| $binding !== ( $handler->get( 'wl_checkout_summary_orders', [] )[ $order_id ] ?? null ) ) {
			throw new \WPGraphQL\WooCommerce\Utils\Cart_Session_Transition_Error();
		}
		// Only this completed retirement issues a fresh acknowledgment. Request and
		// acknowledgment keys are excluded from browser-writable order metadata.
		$store = $order->get_data_store();
		$acknowledgment = 'v1:' . $request_nonce . ':' . bin2hex( random_bytes( 16 ) );
		$order->update_meta_data( '_wl_checkout_cart_retired', $acknowledgment );
		$order->save_meta_data();
		$order->read_meta_data( true );
		$handler->assert_session_ready();
		$handler->assert_owned_scope();
		if ( WC()->session !== $handler || WC()->cart !== $cart || $order->get_data_store() !== $store
			|| $order->get_id() !== $order_id || (int) $order->get_customer_id() !== get_current_user_id()
			|| ! $order->has_status( [ 'pending', 'failed' ] ) || $order->is_paid() || $order->get_date_paid() || $order->get_transaction_id()
			|| ! $cart->is_empty() || $handler->get( 'cart' ) || $handler->get( 'order_awaiting_payment' )
			|| $binding !== ( $handler->get( 'wl_checkout_summary_orders', [] )[ $order_id ] ?? null ) ) {
			throw new \WPGraphQL\WooCommerce\Utils\Cart_Session_Transition_Error();
		}
		$matches = [];
		foreach ( $order->get_meta_data() as $meta ) {
			if ( '_wl_checkout_cart_retired' === $meta->key ) { $matches[] = $meta; }
		}
		if ( 1 !== count( $matches ) || ! is_int( $matches[0]->id ) || $matches[0]->id <= 0
			|| $matches[0]->value !== $acknowledgment ) {
			throw new \WPGraphQL\WooCommerce\Utils\Cart_Session_Error( \WPGraphQL\WooCommerce\Utils\Cart_Session_Error::UNAVAILABLE );
		}
	}

	/** Check that a retry retains the validated addresses and shipping selection. */
	protected static function matches_retry_checkout_data( $order, $data ) {
		foreach ( [ 'billing', 'shipping' ] as $type ) {
			foreach ( [ 'first_name', 'last_name', 'company', 'address_1', 'address_2',
				'city', 'state', 'postcode', 'country' ] as $field ) {
				$getter = 'get_' . $type . '_' . $field;
				if ( (string) ( $data[ $type . '_' . $field ] ?? '' ) !== (string) $order->{$getter}() ) {
					return false;
				}
			}
		}
		foreach ( [ 'email', 'phone' ] as $field ) {
			$getter = 'get_billing_' . $field;
			if ( (string) ( $data[ 'billing_' . $field ] ?? '' ) !== (string) $order->{$getter}() ) {
				return false;
			}
		}
		$selected = array_values( array_filter( (array) ( $data['shipping_method'] ?? [] ) ) );
		$saved = [];
		foreach ( $order->get_shipping_methods() as $rate ) {
			$id = $rate->get_method_id();
			$instance = $rate->get_instance_id();
			$saved[] = $instance ? $id . ':' . $instance : $id;
		}
		return $selected === $saved;
	}

	/**
	 * Gets the value either from 3rd party logic or the customer object. Sets the default values in checkout fields.
	 *
	 * @param string $input Name of the input we want to grab data for. e.g. billing_country.
	 * @return string The default value.
	 */
	public static function get_value( $input ) {
		// Allow 3rd parties to short circuit the logic and return their own default value.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$value = apply_filters( 'woocommerce_checkout_get_value', null, $input );
		if ( ! is_null( $value ) ) {
			return $value;
		}

		/**
		 * For logged in customers, pull data from their account rather than the session which may contain incomplete data.
		 * Another reason is that WC sets shipping address to the billing address on the checkout updates unless the
		 * "shipToDifferentAddress" is set.
		 */
		$customer_object = false;
		if ( is_user_logged_in() ) {
			// Load customer object, but keep it cached to avoid reloading it multiple times.
			if ( is_null( self::$logged_in_customer ) ) {
				self::$logged_in_customer = new \WC_Customer( get_current_user_id(), true );
			}
			$customer_object = new \WC_Customer( get_current_user_id(), true );
		}

		if ( ! $customer_object ) {
			$customer_object = WC()->customer;
		}

		if ( is_callable( [ $customer_object, "get_$input" ] ) ) {
			$value = $customer_object->{"get_$input"}();
		} elseif ( $customer_object->meta_exists( $input ) ) {
			$value = $customer_object->get_meta( $input, true );
		}
		if ( '' === $value ) {
			$value = null;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		return apply_filters( 'default_checkout_' . $input, $value, $input );
	}

	/**
	 * Add or update meta data not set in WC_Checkout::create_order().
	 *
	 * @param int                                  $order_id   Order ID.
	 * @param array                                $meta_data  Order meta data.
	 * @param array                                $input      Order properties.
	 * @param \WPGraphQL\AppContext                $context    AppContext instance.
	 * @param \GraphQL\Type\Definition\ResolveInfo $info       ResolveInfo instance.
	 *
	 * @throws \Exception Order cannot be retrieved.
	 *
	 * @return void
	 */
	public static function update_order_meta( $order_id, $meta_data, $input, $context, $info, $owned_order = null ) {
		$order = null !== $owned_order ? $owned_order : \WC_Order_Factory::get_order( $order_id );
		$handler = WC()->session instanceof \WPGraphQL\WooCommerce\Utils\QL_Session_Handler ? WC()->session : null;
		if ( null !== $owned_order ) {
			if ( ! $handler || ! $handler->protects_checkout_order() || $order !== $handler->created_checkout_order( $order_id ) ) { if ( $handler && $handler->protects_checkout_order() ) { $handler->fail_checkout_order(); } throw new \WPGraphQL\WooCommerce\Utils\Cart_Session_Transition_Error(); }
			$handler->begin_checkout_meta_save( $order );
		}
		if ( ! is_object( $order ) ) {
			throw new \Exception( __( 'Failed to retrieve order.', 'wp-graphql-woocommerce' ) );
		}

		if ( $meta_data ) {
			// Checkout metadata is browser input. In particular, an underscore
			// prefix does not make Stripe/order internals safe to write. Keep only
			// non-authoritative presentation, consent and attribution keys used by
			// the headless checkout. Payment reference/status/total are server-owned.
			$allowed_keys = [
				'order_via', '_consent_terms_accepted', '_consent_terms_timestamp',
				'_consent_marketing', '_billing_nif', '_analytics_event_id',
				'_ga_client_id', '_express_checkout', '_delivery_mode',
				'_pickup_location_name', '_pickup_location_address',
				'_stripe_payment_method_type',
			];
			foreach ( [
				'source_type', 'origin', 'referrer', 'utm_source', 'utm_medium',
				'utm_campaign', 'utm_content', 'utm_term', 'utm_id',
				'utm_source_platform', 'utm_creative_format', 'marketing_tactic',
				'session_entry', 'session_start_time', 'session_pages',
				'user_agent', 'device_type',
			] as $suffix ) {
				$allowed_keys[] = '_wc_order_attribution_' . $suffix;
			}
			$allowed_meta = [];
			foreach ( $meta_data as $meta ) {
				$key = $meta['key'] ?? '';
				$value = $meta['value'] ?? null;
				if ( ! is_string( $key ) || ! in_array( $key, $allowed_keys, true )
					|| ! is_scalar( $value ) || strlen( (string) $value ) > 500 ) {
					continue;
				}
				if ( '_stripe_payment_method_type' === $key
					&& ! in_array( $value, [ 'card', 'link', 'klarna', 'multibanco', 'mb_way', 'boleto', 'oxxo' ], true ) ) {
					continue;
				}
				$order->update_meta_data( $key, sanitize_text_field( (string) $value ) );
				$allowed_meta[] = [ 'key' => $key, 'value' => (string) $value ];
			}
			$meta_data = $allowed_meta;
		}

		/**
		 * Action called before changes to order meta are saved.
		 *
		 * @param \WC_Order   $order      WC_Order instance.
		 * @param array       $meta_data  Order meta data.
		 * @param array       $props      Order props array.
		 * @param \WPGraphQL\AppContext  $context    Request AppContext instance.
		 * @param \GraphQL\Type\Definition\ResolveInfo $info       Request ResolveInfo instance.
		 */
		do_action( 'graphql_woocommerce_before_checkout_meta_save', $order, $meta_data, $input, $context, $info );

		$order->save();
		if ( null !== $owned_order ) { $handler->finish_checkout_meta_save( $order ); }
	}
}

<?php
/**
 * Handles data for the current customers session.
 *
 * @package WPGraphQL\WooCommerce\Utils
 * @since 0.1.2
 */

namespace WPGraphQL\WooCommerce\Utils;

use WC_Session_Handler;
use WPGraphQL\Router;
use WPGraphQL\WooCommerce\Vendor\Firebase\JWT\JWT;
use WPGraphQL\WooCommerce\Vendor\Firebase\JWT\Key;

/**
 * Class - QL_Session_Handler
 *
 * @property int $_session_expiring
 * @property int $_session_expiration
 * @property int|string $_customer_id
 */
class QL_Session_Handler extends WC_Session_Handler {
	/**
	 * Stores the name of the HTTP header used to pass the session token.
	 *
	 * @var string $_token
	 */
	protected $_token; // @codingStandardsIgnoreLine

	/**
	 * Stores Timestamp of when the session token was issued.
	 *
	 * @var float $_session_issued
	 */
	protected $_session_issued; // @codingStandardsIgnoreLine

	/**
	 * True when the token exists.
	 *
	 * @var bool $_has_token
	 */
	protected $_has_token = false; // @codingStandardsIgnoreLine

	/**
	 * True when a new session token has been issued.
	 *
	 * @var bool $_issuing_new_token
	 */
	protected $_issuing_new_token = false; // @codingStandardsIgnoreLine

	/**
	 * True when a new session cookie has been issued.
	 *
	 * @var bool $_issuing_new_cookie
	 */
	protected $_issuing_new_cookie = false; // @codingStandardsIgnoreLine

	/** @var bool Auth-only requests deliberately detach cart persistence. */
	private $auth_detached = false;

	/** @var int|null Refresh is deferred until signing has succeeded. */
	private $pending_expiration_update = null;

	/** @var bool */
	private $customer_token_prepared = false;

	/** @var false|string */
	private $prepared_customer_token = false;

	/** @var bool Signing/filter pipeline runs once before protected resolution. */
	private $token_prepared = false;

	/** @var false|string Exact prepared response credential. */
	private $prepared_token = false;

	/** @var bool Transport mode is frozen before bootstrap. */
	private $graphql_mode;

	/** @var bool Native-cookie requests retain WooCommerce validation/lifecycle. */
	private $native_cookie_mode = false;

	/** @var string|null Immutable failure classification for this handler. */
	private $session_failure = null;

	/** @var string|null Exact effective signing key; never normalized. */
	private $secret_key = null;

	/** @var bool Resolve the constant/filter once, including failures. */
	private $secret_key_resolved = false;

	/** @var bool No persisted access before credential/identity admission. */
	private $session_admitted = false;

	/** @var int Independent account identity at admission. */
	private $admitted_user_id = 0;
	private $guest_marker_rejected = false;
	private $guest_marker_rejected_closed = false;
	private $checkout_attempt;
	private $checkout_pending_rejected = false;

	/** @var string|null The admitted persisted session key. */
	private $admitted_customer_id = null;

	/** GraphQL preflight must not initialize cart credentials or callbacks. */
	private $graphql_options = false;
	/** @var Cart_Session_Storage|null */
	private $owned_storage;
	/** @var Cart_Session_Lifecycle|null */
	private $owned_lifecycle;
	/** Exactly one operation receiver owns request-local checkout provenance. */
	private $owned_operation;
	private $owned_finalized = false;
	private $owned_discarded = false;
	private $header_callback_registered = false;

	/**
	 * Constructor for the session class.
	 */
	public function __construct() {
		$this->graphql_mode = Router::is_graphql_http_request();
		$method = $_SERVER['REQUEST_METHOD'] ?? '';
		$this->graphql_options = $this->graphql_mode && is_string( $method ) && 0 === strcasecmp( 'OPTIONS', $method );
		if ( $this->graphql_options ) {
			$this->_token = 'woocommerce-session';
			return;
		}
		try {
			parent::__construct();
			$header = apply_filters( 'graphql_woocommerce_cart_session_http_header', 'woocommerce-session' );
			if ( ! is_string( $header ) || ! preg_match( '/\A[!#$%&\'*+.^_`|~0-9A-Za-z-]+\z/D', $header ) ) {
				throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE );
			}
			$this->_token = $header;
		} catch ( \Throwable $error ) {
			$this->_token = 'woocommerce-session';
			$this->quarantine( Cart_Session_Error::UNAVAILABLE );
		}
	}

	/**
	 * Returns formatted $_SERVER index from provided string.
	 *
	 * @param string $header String to be formatted.
	 *
	 * @return string
	 */
	private function get_server_key( $header = null ) {
		/**
		 * Server key.
		 *
		 * @var string $server_key
		 */
		$server_key = preg_replace( '#[^A-z0-9]#', '_', ! empty( $header ) ? $header : $this->_token );
		return null !== $server_key
			? 'HTTP_' . strtoupper( $server_key )
			: '';
	}

	/**
	 * This returns the secret key, using the defined constant if defined, and passing it through a filter to
	 * allow for the config to be able to be set via another method other than a defined constant, such as an
	 * admin UI that allows the key to be updated/changed/revoked at any time without touching server files
	 *
	 * @return mixed|null|string
	 */
	private function get_secret_key() {
		if ( ! $this->secret_key_resolved ) {
			$this->secret_key_resolved = true;
			try {
				$key = defined( 'GRAPHQL_WOOCOMMERCE_SECRET_KEY' ) ? GRAPHQL_WOOCOMMERCE_SECRET_KEY : null;
				$key = apply_filters( 'graphql_woocommerce_secret_key', $key );
				if ( ! is_string( $key ) || strlen( $key ) < 32 ) {
					throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE );
				}
				$this->secret_key = $key;
			} catch ( \Throwable $error ) {
				$this->quarantine( Cart_Session_Error::UNAVAILABLE );
			}
		}
		if ( null === $this->secret_key ) {
			throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE );
		}
		return $this->secret_key;
	}

	/** Fail closed without throwing during WooCommerce's early bootstrap. */
	private function quarantine( $code ) {
		if ( null === $this->session_failure || Cart_Session_Error::UNAVAILABLE === $code ) {
			$this->session_failure = $code;
		}
		$this->session_admitted  = false;
		$this->prepared_token    = false;
		$this->prepared_customer_token = false;
		$this->pending_expiration_update = null;
		$this->_customer_id      = '';
		$this->_data             = [];
		$this->_dirty            = false;
		$this->_has_token        = false;
		$this->_has_cookie       = false;
		$this->_issuing_new_token  = false;
		$this->_issuing_new_cookie = false;
		$this->_session_issued     = null;
	}

	/** No shutdown/native callback may silently relabel an admitted identity. */
	private function session_access_allowed() {
		if ( $this->auth_detached || $this->graphql_options || $this->owned_finalized || $this->owned_discarded
			|| ( $this->owned_lifecycle && $this->owned_lifecycle->is_terminal() ) ) {
			return false;
		}
		if ( ! $this->session_admitted || null !== $this->session_failure ) {
			return false;
		}
		if ( $this->native_cookie_mode ) {
			return true;
		}
		if ( $this->admitted_user_id !== (int) get_current_user_id()
			|| ( null !== $this->admitted_customer_id && (string) $this->_customer_id !== $this->admitted_customer_id ) ) {
			$this->quarantine( 'WL_CART_SESSION_TRANSITION_INVALID' );
			return false;
		}
		if ( $this->graphql_mode ) {
			try {
				$this->assert_owned_scope();
			} catch ( \Throwable $error ) {
				return false;
			}
		}
		return true;
	}

	/** Throw only within GraphQL's guarded operation/field execution boundary. */
	public function assert_session_ready() {
		if ( $this->auth_detached ) {
			throw new Cart_Session_Transition_Error();
		}
		if ( $this->graphql_options || $this->owned_finalized || $this->owned_discarded
			|| ( $this->owned_lifecycle && $this->owned_lifecycle->is_terminal() ) ) {
			throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE );
		}
		$this->session_access_allowed();
		if ( 'WL_CART_SESSION_TRANSITION_INVALID' === $this->session_failure ) {
			throw new Cart_Session_Transition_Error();
		}
		if ( null !== $this->session_failure || ! $this->session_admitted ) {
			throw new Cart_Session_Error( $this->session_failure ?: Cart_Session_Error::UNAVAILABLE );
		}
	}

	/**
	 * Init hooks and session data.
	 *
	 * @return void
	 */
	public function init() {
		if ( $this->graphql_options || $this->owned_finalized || $this->owned_discarded || $this->auth_detached
			|| ( $this->owned_lifecycle && $this->owned_lifecycle->is_terminal() ) ) {
			return;
		}
		if ( $this->graphql_mode && ! $this->owned_lifecycle ) {
			// Install the native failure boundary before storage or Woo hydration.
			$this->owned_lifecycle = new Cart_Session_Lifecycle(
				$this,
				$this->_token,
				array_values( array_filter( [ $this->_cookie, 'woocommerce_items_in_cart', 'woocommerce_cart_hash' ], 'strlen' ) )
			);
			$this->owned_lifecycle->install();
		}
		add_filter( 'woocommerce_persistent_cart_enabled', function ( $enabled ) {
			return $this->session_access_allowed() ? $enabled : false;
		} );
		// WC initializes outside Router's exception boundary: install guards first,
		// quarantine early failures, and report them during GraphQL execution.
		add_action( 'do_graphql_request', [ $this, 'assert_session_ready' ], PHP_INT_MIN, 0 );
		if ( null === $this->owned_operation ) {
			$this->owned_operation = new Cart_Session_Operation( $this );
		}
		try {
			if ( $this->graphql_mode && ( ! function_exists( 'WC' ) || ! is_object( \WC() ) || ( \WC()->session ?? null ) !== $this ) ) {
				throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE );
			}
			$this->init_session_token();
		} catch ( \Throwable $error ) {
			// Validated-session callbacks may fail after construction too. Early WC
			// bootstrap still must not escape Router's controlled error boundary.
			if ( $this->checkout_pending_rejected && $error instanceof Cart_Session_Transition_Error
				&& 'WL_CART_SESSION_TRANSITION_INVALID' === $this->session_failure ) {
				try { $this->assert_response_available(); } catch ( \Throwable $unavailable ) { $this->quarantine( Cart_Session_Error::UNAVAILABLE ); }
			} elseif ( $this->guest_marker_rejected && $error instanceof Cart_Session_Error
				&& [ 'code' => Cart_Session_Error::INVALID ] === $error->getExtensions()
				&& Cart_Session_Error::INVALID === $this->session_failure ) {
				// A canonical burned guest is a credential rejection while its
				// captured scope remains healthy; storage uncertainty dominates it.
				try {
					$this->assert_response_available();
				} catch ( \Throwable $unavailable ) {
					$this->quarantine( Cart_Session_Error::UNAVAILABLE );
				}
			} else {
				$this->quarantine( Cart_Session_Error::UNAVAILABLE );
			}
		}
		if ( ! $this->session_access_allowed() ) {
			return;
		}
		if ( $this->graphql_mode ) {
			// Bind the exact pure output callback before the lifecycle freezes its
			// registry. Native cart-cookie events must only change issuance intent.
			$this->register_prepared_session_header();
			add_action( 'woocommerce_set_cart_cookies', [ $this, 'set_customer_session_token' ], 10 );
			add_action( 'woographql_update_session', [ $this, 'set_customer_session_token' ], 10 );
		} else {
			Session_Transaction_Manager::get( $this );
			add_action( 'woocommerce_set_cart_cookies', [ $this, 'set_customer_session_cookie' ], 10 );
			add_action( 'shutdown', [ $this, 'save_data' ], 20 );
			add_action( 'wp_logout', [ $this, 'destroy_session' ] );
			if ( ! is_user_logged_in() ) {
				add_filter( 'nonce_user_logged_out', [ $this, 'maybe_update_nonce_user_logged_out' ], 10, 2 );
			}
		}
	}

	/**
	 * Mark the session as dirty.
	 *
	 * To trigger a save of the session data.
	 *
	 * @return void
	 */
	public function mark_dirty() {
		if ( $this->session_access_allowed() ) {
			$this->_dirty = true;
		}
	}

	/**
	 * Setup token and customer ID.
	 *
	 * @throws \GraphQL\Error\UserError Invalid token.
	 *
	 * @return void
	 */
	public function init_session_token() {
		if ( $this->graphql_options || $this->owned_finalized || $this->owned_discarded || $this->auth_detached
			|| ( $this->owned_lifecycle && $this->owned_lifecycle->is_terminal() ) ) {
			return;
		}
		if ( $this->graphql_mode && ! $this->owned_lifecycle ) {
			throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE );
		}
		if ( null !== $this->session_failure || $this->session_admitted ) {
			return;
		}
		$token = $this->get_session_token();
		if ( is_wp_error( $token ) ) {
			$this->quarantine( $token->get_error_code() );
			return;
		}
		$this->admitted_user_id = (int) get_current_user_id();
		$this->session_admitted = true;
		if ( false !== $token ) {
			$this->_customer_id          = (string) $token->data->customer_id;
			$this->admitted_customer_id   = $this->_customer_id;
			$this->_session_issued       = $token->iat;
			$this->_session_expiration   = $token->exp;
			$this->_session_expiring     = $token->exp - 3600;
			$this->_has_token            = true;
			$this->acquire_owned_storage();
			$this->_data                 = $this->get_session_data();
			$this->set_session_expiration();
			if ( $token->exp < $this->_session_expiration ) {
				$this->pending_expiration_update = $this->_session_expiration;
			}
		} elseif ( $this->graphql_mode ) {
			$this->set_session_expiration();
			$this->_customer_id         = (string) $this->generate_customer_id();
			$this->admitted_customer_id = $this->_customer_id;
			$this->acquire_owned_storage();
			$this->_data                = $this->get_session_data();
			$this->set_customer_session_token( true );
		} else {
			// The native cookie path validates its independently signed cookie.
			parent::init_session_cookie();
			$this->admitted_customer_id = (string) $this->_customer_id;
		}
	}

	/**
	 * Retrieve and decrypt the session data from session, if set. Otherwise return false.
	 *
	 * Session cookies without a customer ID are invalid.
	 *
	 * @throws \Exception  Invalid token.
	 * @return false|\WP_Error|object{ iat: int, exp: int, data: object{ customer_id: string } }
	 */
	public function get_session_token() {
		if ( $this->graphql_options || $this->owned_finalized || $this->owned_discarded || $this->auth_detached
			|| ( $this->owned_lifecycle && $this->owned_lifecycle->is_terminal() ) ) {
			return false;
		}
		if ( null !== $this->session_failure ) {
			return new \WP_Error( $this->session_failure, 'WL_CART_SESSION_TRANSITION_INVALID' === $this->session_failure
				? ( new Cart_Session_Transition_Error() )->getMessage()
				: ( new Cart_Session_Error( $this->session_failure ) )->getMessage() );
		}
		// Opt-in AJAX/REST with no JWT uses the independently signed native
		// cookie handler. JWT configuration governs JWT requests only.
		if ( ! $this->graphql_mode ) {
			try {
				$native_header = $this->get_session_header();
				if ( ! array_key_exists( $this->get_server_key(), $_SERVER ) && ( false === $native_header || null === $native_header ) ) {
					$this->native_cookie_mode = true;
					return false;
				}
			} catch ( \Throwable $error ) {
				return $this->token_failure( Cart_Session_Error::UNAVAILABLE );
			}
		}
		try {
			// Configuration dominates supplied credentials, including absent headers.
			$secret = $this->get_secret_key();
		} catch ( \Throwable $error ) {
			return $this->token_failure( Cart_Session_Error::UNAVAILABLE );
		}
		try {
			$header = $this->get_session_header();
		} catch ( \Throwable $error ) {
			return $this->token_failure( Cart_Session_Error::UNAVAILABLE );
		}
		if ( ! array_key_exists( $this->get_server_key(), $_SERVER ) && ( false === $header || null === $header ) ) {
			return false;
		}
		if ( ! is_string( $header ) || ! preg_match( '/\ASession ([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)\z/D', $header, $match ) ) {
			return $this->token_failure( Cart_Session_Error::INVALID );
		}
		// Validate wire shape before verification so malformed JSON is a credential
		// error, not a cryptographic/configuration DomainException.
		try {
			$segments = explode( '.', $match[1] );
			$jwt_header = JWT::jsonDecode( JWT::urlsafeB64Decode( $segments[0] ) );
			$payload = JWT::jsonDecode( JWT::urlsafeB64Decode( $segments[1] ) );
			if ( ! $jwt_header instanceof \stdClass || ! $payload instanceof \stdClass
				|| ! isset( $jwt_header->alg ) || 'HS256' !== $jwt_header->alg
				|| 32 !== strlen( JWT::urlsafeB64Decode( $segments[2] ) ) ) {
				return $this->token_failure( Cart_Session_Error::INVALID );
			}
		} catch ( \Throwable $error ) {
			return $this->token_failure( Cart_Session_Error::INVALID );
		}
		try {
			JWT::$leeway = 60;
			$token = JWT::decode( $match[1], new Key( $secret, 'HS256' ) );
		} catch ( \UnexpectedValueException $error ) {
			return $this->token_failure( Cart_Session_Error::INVALID );
		} catch ( \Throwable $error ) {
			return $this->token_failure( Cart_Session_Error::UNAVAILABLE );
		}
		if ( ! isset( $token->iss, $token->iat, $token->nbf, $token->exp, $token->data->customer_id )
			|| ! is_string( $token->iss ) || get_bloginfo( 'url' ) !== $token->iss
			|| ! is_int( $token->iat ) || ! is_int( $token->nbf ) || ! is_int( $token->exp )
			|| $token->iat <= 0 || $token->exp <= $token->iat || $token->nbf > $token->exp
			|| ( ! is_string( $token->data->customer_id ) && ! is_int( $token->data->customer_id ) ) ) {
			return $this->token_failure( Cart_Session_Error::INVALID );
		}
		$id = (string) $token->data->customer_id;
		$user_id = (int) get_current_user_id();
		if ( $user_id > 0 ) {
			if ( (string) $user_id !== $id ) {
				return $this->token_failure( Cart_Session_Error::INVALID );
			}
		} elseif ( ! preg_match( '/\A(?:[a-f0-9]{32}|t_[a-f0-9]{30})\z/D', $id ) ) {
			// Supported native random guest formats are shape checks only AFTER a
			// strong-key verification. Numeric account claims need account auth.
			return $this->token_failure( Cart_Session_Error::INVALID );
		}
		return $token;
	}

	/** @return \WP_Error */
	private function token_failure( $code ) {
		$this->quarantine( $code );
		$error = new Cart_Session_Error( $code );
		return new \WP_Error( $code, $error->getMessage() );
	}

	/**
	 * Get the value of the cart session header from the $_SERVER super global
	 *
	 * @return mixed|string
	 */
	public function get_session_header() {
		$session_header_key = $this->get_server_key();

		// Looking for the cart session header.
		$session_header = isset( $_SERVER[ $session_header_key ] )
			? $_SERVER[ $session_header_key ] //@codingStandardsIgnoreLine
			: false;

		/**
		 * Return the cart session header, passed through a filter
		 *
		 * @param string $session_header  The header used to identify a user's cart session token.
		 */
		return apply_filters( 'graphql_woocommerce_cart_session_header', $session_header );
	}

	/**
	 * Determine if a JWT is being sent in the page response.
	 *
	 * @return bool
	 */
	public function sending_token() {
		return $this->session_access_allowed() && ( $this->_has_token || $this->_issuing_new_token );
	}

	/**
	 * Determine if a HTTP cookie is being sent in the page response.
	 *
	 * @return bool
	 */
	public function sending_cookie() {
		return $this->session_access_allowed() && ( $this->_has_cookie || $this->_issuing_new_cookie );
	}

	/**
	 * Creates JSON Web Token for customer session.
	 *
	 * @return false|string
	 */
	public function build_token() {
		if ( $this->graphql_options || $this->owned_finalized || $this->owned_discarded
			|| ( $this->owned_lifecycle && $this->owned_lifecycle->is_terminal() ) ) {
			return false;
		}
		if ( ! $this->auth_detached && Cart_Session_Error::UNAVAILABLE === $this->session_failure ) {
			throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE );
		}
		return $this->session_output_allowed() && $this->token_prepared ? $this->prepared_token : false;
	}

	/** Prepare once inside GraphQL's guarded boundary, before queue/resolver work. */
	public function prepare_session_token() {
		$this->assert_session_ready();
		if ( $this->token_prepared || $this->native_cookie_mode ) {
			return;
		}
		$this->prepared_token = $this->sign_token();
		$this->token_prepared = true;
		if ( false === $this->prepared_token && ! $this->_has_token ) {
			$this->quarantine( Cart_Session_Error::UNAVAILABLE );
			throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE );
		}
	}

	/** Commit only after all selected credential pipelines have prepared. */
	public function complete_session_preparation() {
		$this->assert_session_ready();
		if ( ! $this->token_prepared && ! $this->native_cookie_mode ) {
			throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE );
		}
		if ( null !== $this->pending_expiration_update ) {
			$this->update_session_timestamp( $this->_customer_id, $this->pending_expiration_update );
			$this->pending_expiration_update = null;
		}
	}

	/** @return false|string */
	private function sign_token() {
		if ( ! $this->session_access_allowed() || empty( $this->_session_issued ) || ! $this->sending_token() ) {
			return false;
		}

		try {
			/**
			 * Determine the "not before" value for use in the token
			 *
			 * @param float      $issued        The timestamp of token was issued.
			 * @param int|string $customer_id   Customer ID.
			 * @param array      $session_data  Cart session data.
			 */
			$not_before = apply_filters(
				'graphql_woo_cart_session_not_before',
				$this->_session_issued,
				$this->_customer_id,
				$this->_data
			);

			// Configure the token array, which will be encoded.
			$token = [
				'iss'  => get_bloginfo( 'url' ),
				'iat'  => $this->_session_issued,
				'nbf'  => $not_before,
				'exp'  => $this->_session_expiration,
				'data' => [
					'customer_id' => $this->_customer_id,
				],
			];

			/**
			 * Filter the token, allowing for individual systems to configure the token as needed
			 *
			 * @param array      $token         The token array that will be encoded
			 * @param int|string $customer_id   ID of customer associated with token.
			 * @param array      $session_data  Session data associated with token.
			 */
			$token = apply_filters(
				'graphql_woocommerce_cart_session_before_token_sign',
				$token,
				$this->_customer_id,
				$this->_data
			);

			// Encode the token.
			JWT::$leeway = 60;
			$token       = JWT::encode( $token, $this->get_secret_key(), 'HS256' );

			/**
			 * Filter the token before returning it, allowing for individual systems to override what's returned.
			 *
			 * For example, if the user should not be granted a token for whatever reason, a filter could have the token return null.
			 *
			 * @param string     $token         The signed JWT token that will be returned
			 * @param int|string $customer_id   ID of customer associated with token.
			 * @param array      $session_data  Session data associated with token.
			 */
			$token = apply_filters(
				'graphql_woocommerce_cart_session_signed_token',
				$token,
				$this->_customer_id,
				$this->_data
			);
		} catch ( \Throwable $error ) {
			$this->quarantine( Cart_Session_Error::UNAVAILABLE );
			throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE );
		}

		if ( null === $token || false === $token ) {
			return false;
		}
		if ( ! is_string( $token ) || '' === $token ) {
			$this->quarantine( Cart_Session_Error::UNAVAILABLE );
			throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE );
		}
		$this->validate_prepared_token( $token );
		return $token;
	}

	/** A server token filter cannot replace a credential with another identity. */
	private function validate_prepared_token( $token ) {
		try {
			if ( ! is_string( $token ) || ! preg_match( '/\A[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\z/D', $token ) ) {
				throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE );
			}
			$decoded = JWT::decode( $token, new Key( $this->get_secret_key(), 'HS256' ) );
			if ( ! isset( $decoded->iss, $decoded->iat, $decoded->nbf, $decoded->exp, $decoded->data->customer_id )
				|| ( ! is_string( $decoded->data->customer_id ) && ! is_int( $decoded->data->customer_id ) )
				|| ! is_int( $decoded->iat ) || ! is_int( $decoded->nbf ) || ! is_int( $decoded->exp )
				|| $decoded->iat <= 0 || $decoded->exp <= $decoded->iat || $decoded->nbf > $decoded->exp
				|| get_bloginfo( 'url' ) !== $decoded->iss
				|| (string) $this->_customer_id !== (string) $decoded->data->customer_id ) {
				throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE );
			}
		} catch ( \Throwable $error ) {
			$this->quarantine( Cart_Session_Error::UNAVAILABLE );
			throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE );
		}
	}

	/** Prepare selected body-token filters before resolver side effects. */
	public function prepare_customer_session_token() {
		if ( $this->auth_detached || $this->customer_token_prepared ) {
			return;
		}
		$this->prepare_session_token();
		try {
			$token = apply_filters( 'graphql_customer_session_token', $this->prepared_token );
			if ( null !== $token && false !== $token ) {
				if ( ! is_string( $token ) || '' === $token ) {
					throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE );
				}
				$this->validate_prepared_token( $token );
			}
			$this->prepared_customer_token = $token ?: false;
			$this->customer_token_prepared = true;
		} catch ( \Throwable $error ) {
			$this->quarantine( Cart_Session_Error::UNAVAILABLE );
			throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE );
		}
	}

	/** @return string|null */
	public function build_customer_token() {
		if ( ! $this->session_output_allowed() ) {
			return null;
		}
		if ( ! $this->customer_token_prepared ) {
			// The operation coordinator must prepare selected token fields.
			throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE );
		}
		return $this->prepared_customer_token ?: null;
	}

	/**
	 * Sets the session header on-demand (usually after adding an item to the cart).
	 *
	 * Warning: Headers will only be set if this is called before the headers are sent.
	 *
	 * @param bool $set Should the session cookie be set.
	 *
	 * @return void
	 */
	public function set_customer_session_token( $set ) {
		if ( $this->session_access_allowed() && ! empty( $this->_session_issued ) && $set ) {
			$this->register_prepared_session_header();

			$this->_issuing_new_token = true;
		}
	}

	/** Registration is independent of signing, expiration and issuance intent. */
	private function register_prepared_session_header(): void {
		if ( ! $this->header_callback_registered ) {
			add_filter( 'graphql_response_headers_to_send', [ $this, 'add_prepared_session_header' ], 10 );
			$this->header_callback_registered = true;
		}
	}

	/** Pure cached output; the final owned boundary checks identity and scope again. */
	public function add_prepared_session_header( $headers ) {
		if ( $this->_issuing_new_token && $this->session_output_allowed() && $this->token_prepared && $this->prepared_token ) {
			$headers[ $this->_token ] = $this->prepared_token;
		}
		return $headers;
	}

	private function session_output_allowed() {
		if ( $this->graphql_options || $this->auth_detached || $this->owned_finalized || $this->owned_discarded
			|| ! $this->session_admitted || null !== $this->session_failure
			|| ( $this->owned_lifecycle && $this->owned_lifecycle->is_terminal() ) ) {
			return false;
		}
		if ( ! $this->native_cookie_mode && ( $this->admitted_user_id !== (int) get_current_user_id()
			|| (string) $this->_customer_id !== $this->admitted_customer_id ) ) {
			$this->quarantine( 'WL_CART_SESSION_TRANSITION_INVALID' );
			return false;
		}
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	public function set_customer_session_cookie( $set ) {
		if ( $this->graphql_mode || ! $this->session_access_allowed() ) {
			return;
		}
		parent::set_customer_session_cookie( $set );

		if ( $set ) {
			$this->_issuing_new_cookie = true;
		}
	}

	/**
	 * Return true if the current user has an active session, i.e. a cookie to retrieve values.
	 *
	 * @return bool
	 */
	public function has_session() {

		// @codingStandardsIgnoreLine.
		return $this->session_access_allowed() && ( $this->_issuing_new_token || $this->_has_token || parent::has_session() );
	}

	/**
	 * Set session expiration.
	 *
	 * @return void
	 */
	public function set_session_expiration() {
		if ( null !== $this->session_failure || $this->graphql_options || $this->owned_finalized || $this->owned_discarded || $this->auth_detached
			|| ( $this->owned_lifecycle && $this->owned_lifecycle->is_terminal() ) ) {
			return;
		}
		$this->_session_issued = time();
		// 47 hours.
		$this->_session_expiring = apply_filters( 'wc_session_expiring', $this->_session_issued + ( 60 * 60 * 47 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		// 48 hours.
		$this->_session_expiration = apply_filters( 'wc_session_expiration', $this->_session_issued + ( 60 * 60 * 48 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$this->_session_expiration = apply_filters_deprecated(
			'graphql_woocommerce_cart_session_expire',
			[ $this->_session_expiration ],
			'0.21.0',
			'wc_session_expiration'
		);
	}

	/**
	 * Save any changes to database after a session mutations has been run.
	 *
	 * @return void
	 */
	public function save_if_dirty() {
		if ( $this->session_access_allowed() && $this->_dirty ) {
			$this->save_data();
		}
	}

	/**
	 * For refreshing session data mid-request when changes occur in concurrent requests.
	 *
	 * @return void
	 */
	public function reload_data() {
		if ( ! $this->session_access_allowed() ) {
			return;
		}
		if ( ! $this->graphql_mode ) {
			\WC_Cache_Helper::invalidate_cache_group( WC_SESSION_CACHE_GROUP );
		}

		// Get session data.
		$data = $this->get_session( (string) $this->_customer_id );
		if ( is_array( $data ) ) {
			$this->_data = $data;
		}
	}

	/**
	 * Returns "client_session_id". "client_session_id_expiration" is used
	 * to keep "client_session_id" as fresh as possible.
	 *
	 * For the most strict level of security it's highly recommend these values
	 * be set client-side using the `updateSession` mutation.
	 * "client_session_id" in particular should be salted with some
	 * kind of client identifier like the end-user "IP" or "user-agent"
	 * then hashed parodying the tokens generated by
	 * WP's WP_Session_Tokens class.
	 *
	 * @return string
	 */
	public function get_client_session_id() {
		if ( ! $this->session_access_allowed() ) {
			return '';
		}
		// Get client session ID.
		$client_session_id            = $this->get( 'client_session_id', false );
		$client_session_id_expiration = absint( $this->get( 'client_session_id_expiration', 0 ) );

		// If client session ID valid return it.
		if ( false !== $client_session_id && time() < $client_session_id_expiration ) {
			// @phpstan-ignore-next-line
			return $client_session_id;
		}

		// Generate a new client session ID.
		$client_session_id            = uniqid();
		$client_session_id_expiration = time() + 3600;
		$this->set( 'client_session_id', $client_session_id );
		$this->set( 'client_session_id_expiration', $client_session_id_expiration );
		$this->save_data();

		// Return new client session ID.
		return $client_session_id;
	}
	/** Detach before a qualified auth callback; never reattach in this request. */
	public function detach_for_auth() {
		$this->assert_session_ready();
		if ( $this->graphql_mode ) {
			$this->assert_owned_scope();
			$this->owned_lifecycle->close_writers();
			try {
				$this->owned_storage->seal();
				$this->owned_storage->release();
				$this->owned_finalized = true;
			} catch ( \Throwable $error ) {
				$this->latch_owned_failure();
				throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE );
			}
		}
		$this->auth_detached = true;
		$this->_data = [];
		$this->_dirty = false;
		$this->_has_token = false;
		$this->_has_cookie = false;
		$this->_issuing_new_token = false;
		$this->_issuing_new_cookie = false;
		$this->prepared_token = false;
		$this->prepared_customer_token = false;
		$this->pending_expiration_update = null;
		if ( ! $this->graphql_mode && function_exists( 'WC' ) && isset( \WC()->customer ) ) {
			remove_action( 'shutdown', [ \WC()->customer, 'save' ], 10 );
		}
	}

	/** @return bool */
	public function is_graphql_session() {
		return $this->graphql_mode && ! $this->graphql_options;
	}

	/** Storage admission never precedes credential/account binding. */
	private function acquire_owned_storage() {
		if ( ! $this->graphql_mode ) {
			return;
		}
		$wc = function_exists( 'WC' ) ? \WC() : null;
		if ( ! is_object( $wc ) || ( $wc->session ?? null ) !== $this
			|| is_object( $wc->customer ?? null ) || is_object( $wc->cart ?? null ) || $this->owned_storage ) {
			throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE );
		}
		$driver = $GLOBALS['wpdb'] ?? null;
		$this->owned_lifecycle->qualify_owned_storage_driver( $driver );
		$this->owned_storage = new Cart_Session_Storage( $this->admitted_customer_id, $this->_table, $this->admitted_user_id );
		$this->owned_storage->acquire( 5 );
		if ( 0 === $this->admitted_user_id ) {
			// Direct checked reads under the original guest grant precede session
			// hydration. Neither marker authorizes authentication or continuation.
			$options_table = $driver->options ?? null;
			$retired = $this->owned_storage->read_retirement_marker( $options_table );
			$creation_attempted = $this->owned_storage->read_creation_marker( $options_table );
			if ( $retired || $creation_attempted ) {
				// Close healthy rejected storage before native WC cart construction.
				// Do not finalize/discard the handler: readiness must retain INVALID.
				$this->owned_storage->seal();
				$this->owned_storage->release();
				$this->owned_storage->assert_response_available();
				$this->guest_marker_rejected_closed = true;
				$this->guest_marker_rejected = true;
				$this->quarantine( Cart_Session_Error::INVALID );
				throw new Cart_Session_Error( Cart_Session_Error::INVALID );
			}
		}
		if ( $this->admitted_user_id > 0 ) {
			$fence = $this->owned_storage->read_checkout_order_attempt( $driver->options ?? null );
			if ( is_array( $fence ) && 'pending' === $fence['state'] ) {
				$this->owned_storage->seal(); $this->owned_storage->release();
				$this->owned_storage->assert_response_available();
				$this->guest_marker_rejected_closed = true; $this->checkout_pending_rejected = true;
				$this->quarantine( 'WL_CART_SESSION_TRANSITION_INVALID' );
				throw new Cart_Session_Transition_Error();
			}
		}
		$this->owned_storage->invalidate_account_caches();
	}

	/** Preserve dirty data until explicit discard; failure cannot publish credentials. */
	private function latch_owned_failure() {
		$this->session_failure = Cart_Session_Error::UNAVAILABLE;
		$this->prepared_token = false;
		$this->prepared_customer_token = false;
	}

	public function has_owned_scope(): bool {
		return null !== $this->owned_storage && ! $this->owned_finalized && ! $this->owned_discarded
			&& ! $this->guest_marker_rejected_closed;
	}

	public function assert_owned_scope(): void {
		if ( ! $this->graphql_mode || $this->graphql_options || ! $this->has_owned_scope()
			|| null !== $this->session_failure || $this->auth_detached ) {
			throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE );
		}
		try {
			$this->owned_storage->assert_owned();
		} catch ( \Throwable $error ) {
			$this->latch_owned_failure();
			throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE );
		}
	}

	/** A controlled GraphQL rejection discards its scope without a successful flush. */
	public function has_session_rejection(): bool {
		return in_array( $this->session_failure, [ Cart_Session_Error::INVALID, 'WL_CART_SESSION_TRANSITION_INVALID' ], true );
	}

	/** Share operation rejection with the terminal lifecycle before typed formatting. */
	public function reject_cart_operation(): void {
		// Failed storage/configuration dominates translated transition formatting.
		$this->assert_response_available();
		$this->quarantine( 'WL_CART_SESSION_TRANSITION_INVALID' );
	}

	/** Check sticky owned-storage failures even after a clean auth release. */
	public function assert_response_available(): void {
		if ( Cart_Session_Error::UNAVAILABLE === $this->session_failure ) {
			throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE );
		}
		if ( $this->owned_storage ) {
			try {
				$this->owned_storage->assert_response_available();
			} catch ( \Throwable $error ) {
				$this->latch_owned_failure();
				throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE );
			}
		} elseif ( $this->graphql_mode && ! $this->graphql_options && $this->session_admitted ) {
			$this->latch_owned_failure();
			throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE );
		}
	}

	/** Called only by the checked HTTP lifecycle, never by shutdown. */
	public function complete_owned_scope(): void {
		if ( $this->owned_finalized ) {
			return;
		}
		$this->assert_session_ready();
		$this->assert_owned_scope();
		try {
			if ( null !== $this->checkout_attempt ) {
				if ( ! $this->checkout_attempt->success || ! $this->checkout_attempt->saved || ! $this->checkout_attempt->order
					|| ! \WC()->cart->is_empty() ) { throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE ); }
				$this->revalidate_checkout_order_success( $this->checkout_attempt->order );
				$this->owned_storage->complete_checkout_order( $this->_data, $this->_session_expiration );
				$this->_dirty = false;
			} else { $this->save_data(); }
			$this->owned_storage->seal();
			$this->owned_storage->release();
			$this->owned_finalized = true;
		} catch ( \Throwable $error ) {
			$this->latch_owned_failure();
			throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE );
		}
	}

	/** Captured cleanup is idempotent and never flushes discarded state. */
	public function discard_owned_scope(): void {
		if ( $this->owned_discarded || $this->owned_finalized ) {
			return;
		}
		$this->owned_discarded = true;
		$this->quarantine( $this->session_failure ?: Cart_Session_Error::UNAVAILABLE );
		if ( $this->owned_storage ) {
			$this->owned_storage->abort();
		}
	}

	public function get_owned_lifecycle(): ?Cart_Session_Lifecycle {
		return $this->owned_lifecycle;
	}

	/** Pure recognition only; never reveal or replace the retained operation. */
	public function is_cart_operation_callback( $hook, $callback, $priority, $arguments ): bool {
		if ( null === $this->owned_operation || ! is_int( $priority ) || ! is_int( $arguments ) ) { return false; }
		return ( 'graphql_pre_mutate_and_get_payload' === $hook && 6 === $arguments
			&& ( ( PHP_INT_MIN === $priority && [ $this->owned_operation, 'before_mutation' ] === $callback )
				|| ( PHP_INT_MAX === $priority && [ $this->owned_operation, 'capture_checkout_origin' ] === $callback ) ) )
			|| ( 'graphql_mutation_response' === $hook && 0 === $priority && 6 === $arguments
				&& [ $this->owned_operation, 'mutation_response' ] === $callback );
	}

	/** Fixed frozen-cohort check, never a caller-selected callback or source. */
	public function assert_checkout_origin_boundary(): void {
		try {
			$this->assert_session_ready(); $this->assert_owned_scope();
			if ( ( \WC()->session ?? null ) !== $this || ! $this->owned_lifecycle ) {
				throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE );
			}
			$this->owned_lifecycle->assert_checkout_origin_boundary();
		} catch ( \Throwable $error ) {
			$this->latch_owned_failure();
			throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE );
		}
	}

	public function begin_checkout( $entry, $input, $context, $info ): void {
		$this->assert_session_ready(); $this->assert_owned_scope();
		if ( ! $this->owned_operation || ( \WC()->session ?? null ) !== $this ) {
			$this->reject_cart_operation(); throw new Cart_Session_Transition_Error();
		}
		$this->owned_operation->enter_checkout( $entry, $input, $context, $info );
	}

	/** One-use actual final creation branch; all uncertainty burns the guest reservation. */
	public function begin_checkout_customer_creation( $data, $context, $info ): void {
		$this->assert_session_ready(); $this->assert_owned_scope();
		if ( ! $this->owned_operation || null !== $this->checkout_attempt || 0 !== $this->admitted_user_id
			|| 0 !== (int) get_current_user_id() || ( \WC()->session ?? null ) !== $this
			|| (string) $this->_customer_id !== $this->admitted_customer_id ) {
			$this->reject_cart_operation(); throw new Cart_Session_Transition_Error();
		}
		$this->owned_operation->consume_checkout_creation_origin( $data, $context, $info );
		$this->owned_lifecycle->qualify_checkout_creation( $context );
		$this->checkout_attempt = (object) [ 'uuid' => null, 'user_id' => 0, 'auth_expected' => false,
			'adopted' => false, 'protected' => false, 'selected_customer_token' => $this->customer_token_prepared, 'order' => null, 'store' => null, 'saved' => false, 'save_active' => false, 'save_failed' => false, 'save_started' => 0, 'save_completed' => 0, 'payment_started' => false, 'phase' => 'creation', 'outcome' => null, 'deferred_checked' => false, 'checkout_meta_done' => false, 'success' => false ];
		try {
			$this->owned_storage->reserve_checkout_creation( $GLOBALS['wpdb']->options );
			$this->owned_lifecycle->close_writers();
			$this->checkout_attempt->uuid = $this->owned_storage->freeze_for_checkout_transfer( $this->_data, $this->_session_expiration );
		} catch ( \Throwable $error ) { $this->latch_owned_failure(); throw $error; }
	}

	/** Native insertion result never crosses a caller-supplied account adoption API. */
	public function create_checkout_customer( $data, $context, $info ): int {
		$this->begin_checkout_customer_creation( $data, $context, $info );
		try {
			$id = \wc_create_new_customer( $data['billing_email'], $data['account_username'] ?? '', $data['account_password'] ?? '',
				[ 'first_name' => $data['billing_first_name'] ?? '', 'last_name' => $data['billing_last_name'] ?? '' ] );
			if ( is_wp_error( $id ) ) { throw new \GraphQL\Error\UserError( $id->get_error_message() ); }
			if ( ! is_int( $id ) || $id <= 0 ) { throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE ); }
			$this->adopt_checkout_customer( $id, $context );
			return $id;
		} catch ( \Throwable $error ) {
			// The native insert may already be durable. Never infer an ID, purge or retry.
			$this->latch_owned_failure(); throw $error;
		}
	}

	private function adopt_checkout_customer( $customer_id, $context ): void {
		if ( null === $this->checkout_attempt || $this->checkout_attempt->adopted || ! is_int( $customer_id ) || $customer_id <= 0 ) {
			throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE );
		}
		try {
			$this->owned_storage = $this->owned_storage->stage_checkout_transfer( $customer_id, $GLOBALS['wpdb']->options );
			$this->owned_storage->commit_checkout_transfer();
			$this->owned_storage->invalidate_account_caches();
			$this->checkout_attempt->user_id = $customer_id;
			$this->checkout_attempt->auth_expected = true;
			$this->owned_lifecycle->arm_checkout_cookie_capsule( $customer_id );
			\wc_set_customer_auth_cookie( $customer_id );
			if ( $this->checkout_attempt->auth_expected || ! $this->checkout_attempt->adopted ) { throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE ); }
			$this->owned_lifecycle->adopt_checkout_customer( $context );
			// Issue only for the checked destination; armed init already cleared guest credentials.
			$this->set_customer_session_token( true );
			$this->prepare_session_token();
			if ( $this->checkout_attempt->selected_customer_token ) { $this->prepare_customer_session_token(); }
			$this->complete_session_preparation();
		} catch ( \Throwable $error ) { $this->latch_owned_failure(); throw $error; }
	}

	public function protect_checkout_order(): void {
		if ( null === $this->checkout_attempt ) { return; }
		if ( ! $this->checkout_attempt->adopted || $this->checkout_attempt->protected ) { throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE ); }
		$this->checkout_attempt->protected = true;
	}

	/** Sticky across origin cleanup and exceptions, including lost create_order return. */
	public function protects_checkout_order(): bool { return null !== $this->checkout_attempt && $this->checkout_attempt->protected; }
	public function has_checkout_creation(): bool { return null !== $this->checkout_attempt; }

	public function capture_checkout_order( $order, $data ): void {
		if ( ! $this->protects_checkout_order() ) { return; }
		$this->assert_session_ready(); $this->assert_owned_scope();
		if ( $this->checkout_attempt->order || ! $order instanceof \WC_Order || get_class( $order ) !== 'WC_Order'
			|| 0 !== $order->get_id() || $this->admitted_user_id !== (int) $order->get_customer_id( 'edit' ) ) { $this->fail_checkout_order(); }
		$order->add_meta_data( '_wl_checkout_operation_uuid', $this->checkout_attempt->uuid, true );
		$this->checkout_attempt->order = $order; $this->checkout_attempt->store = $order->get_data_store();
	}

	public function bind_checkout_order( $id, $order ): void {
		if ( ! $this->protects_checkout_order() ) { return; }
		$this->assert_session_ready(); $this->assert_owned_scope();
		if ( $order !== $this->checkout_attempt->order || ! is_int( $id ) || $id <= 0 || $id !== $order->get_id()
			|| $this->admitted_user_id !== (int) $order->get_customer_id( 'edit' )
			|| $this->checkout_attempt->uuid !== $order->get_meta( '_wl_checkout_operation_uuid', true, 'edit' ) ) { $this->fail_checkout_order(); }
		$this->owned_storage->bind_checkout_order( $id );
	}

	/** Failure is latched before throwing: native WC_Abstract_Order::save catches exceptions. */
	public function fail_checkout_order(): never {
		if ( $this->checkout_attempt ) { $this->checkout_attempt->save_failed = true; $this->checkout_attempt->saved = false; $this->checkout_attempt->success = false; }
		$this->latch_owned_failure();
		throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE );
	}

	/** Runs before any qualified external before-save callback can fail. */
	public function checkout_order_saving( $order, $store ): void {
		if ( ! $this->protects_checkout_order() ) { return; }
		$a = $this->checkout_attempt;
		if ( $order !== $a->order ) {
			if ( $order instanceof \WC_Order && $a->order && $order->get_id() === $a->order->get_id() ) { $this->fail_checkout_order(); }
			return;
		}
		$this->assert_session_ready(); $this->assert_owned_scope();
		if ( $store !== $a->store || $order->get_data_store() !== $a->store || $a->save_active || $a->save_failed
			|| ( 'checkout_meta' === ( $a->phase ?? null ) && $a->save_started !== $a->meta_save_before )
			|| $a->success || in_array( $a->phase ?? 'creation', [ 'deferred', 'readback', 'deferred_checked' ], true ) ) { $this->fail_checkout_order(); }
		$a->save_active = true; ++$a->save_started; $a->saved = false;
	}

	/** Explicit full-save role; an earlier native create/save cannot prove this write. */
	public function begin_checkout_meta_save( $order ): void {
		$this->verify_checkout_order_return( $order->get_id(), $order );
		$a = $this->checkout_attempt;
		if ( 'creation' !== ( $a->phase ?? 'creation' ) || $a->payment_started || ( $a->checkout_meta_done ?? false ) ) { $this->fail_checkout_order(); }
		$a->phase = 'checkout_meta'; $a->meta_save_before = $a->save_completed;
	}
	public function finish_checkout_meta_save( $order ): void {
		$this->verify_checkout_order_return( $order->get_id(), $order );
		$a = $this->checkout_attempt;
		if ( 'checkout_meta' !== ( $a->phase ?? null ) || $a->save_completed !== $a->meta_save_before + 1 ) { $this->fail_checkout_order(); }
		$a->phase = 'creation'; $a->checkout_meta_done = true;
	}

	/** A previous successful create/save cannot authorize the payment save. */
	public function begin_checkout_free_payment( $order ): void {
		if ( ! $this->protects_checkout_order() ) { return; }
		$this->verify_checkout_order_return( $order->get_id(), $order );
		$a = $this->checkout_attempt;
		if ( $a->payment_started || 'creation' !== ( $a->phase ?? 'creation' ) || $order->needs_payment() || 0.0 !== (float) $order->get_total( 'edit' ) ) { $this->fail_checkout_order(); }
		$a->payment_started = true; $a->outcome = 'free'; $a->phase = 'free_payment';
		$a->saved = false; $a->save_started = 0; $a->save_active = false; $a->save_completed = 0;
	}

	public function checkout_order_saved( $order, $store ): void {
		if ( ! $this->protects_checkout_order() ) { return; }
		$a = $this->checkout_attempt;
		if ( $order !== $a->order ) {
			if ( $order instanceof \WC_Order && $a->order && $order->get_id() === $a->order->get_id() ) { $this->fail_checkout_order(); }
			return;
		}
		$this->assert_session_ready(); $this->assert_owned_scope();
		$fence = $this->owned_storage->read_checkout_order_attempt( $GLOBALS['wpdb']->options );
		if ( ! $a->save_active || $a->save_failed || $store !== $a->store || $order->get_data_store() !== $a->store
			|| ! is_array( $fence ) || $order->get_id() <= 0 || $fence['order_id'] !== $order->get_id()
			|| $fence['operation_uuid'] !== $a->uuid ) { $this->fail_checkout_order(); }
		$a->save_active = false; $a->save_completed = $a->save_started; $a->saved = $a->save_started > 0;
	}

	public function created_checkout_order( $id ) {
		$order = $this->checkout_attempt->order ?? null;
		$this->verify_checkout_order_return( $id, $order );
		return $order;
	}

	/** Shared native creation proof; outcome-specific payment evidence lives below. */
	public function verify_checkout_order_return( $id, $order ): void {
		if ( ! $this->protects_checkout_order() ) { return; }
		$this->assert_session_ready(); $this->assert_owned_scope();
		$a = $this->checkout_attempt;
		$fence = $this->owned_storage->read_checkout_order_attempt( $GLOBALS['wpdb']->options );
		if ( ! is_int( $id ) || $id <= 0 || ! $a->saved || $a->save_active || $a->save_failed || $order !== $a->order
			|| $order->get_id() !== $id || $order->get_data_store() !== $a->store || $a->save_started < 1 || $a->save_completed !== $a->save_started
			|| $this->admitted_user_id !== (int) $order->get_customer_id( 'edit' ) || $a->uuid !== $order->get_meta( '_wl_checkout_operation_uuid', true, 'edit' )
			|| ! is_array( $fence ) || 'pending' !== $fence['state'] || $id !== $fence['order_id'] || $a->uuid !== $fence['operation_uuid'] ) { $this->fail_checkout_order(); }
	}

	private function assert_checkout_deferred_invariants( $order ): void {
		$a = $this->checkout_attempt;
		$this->verify_checkout_order_return( $order->get_id(), $order );
		if ( ! is_finite( (float) $order->get_total( 'edit' ) ) || (float) $order->get_total( 'edit' ) <= 0
			|| 'stripe' !== $order->get_payment_method( 'edit' ) || 'pending' !== $order->get_status( 'edit' ) || $order->is_paid()
			|| $order->get_date_paid( 'edit' ) || '' !== $order->get_transaction_id( 'edit' )
			|| $order->get_meta( '_stripe_source_id', true, 'edit' ) || $order->get_meta( '_stripe_intent_id', true, 'edit' )
			|| ( isset( $a->deferred_total ) && ( $a->deferred_total !== $order->get_total( 'edit' ) || $a->deferred_currency !== $order->get_currency( 'edit' ) ) ) ) { $this->fail_checkout_order(); }
	}

	public function begin_checkout_deferred_payment( $order ): void {
		$this->assert_checkout_deferred_invariants( $order );
		$a = $this->checkout_attempt;
		if ( $a->payment_started || 'creation' !== ( $a->phase ?? 'creation' ) || $order->get_meta( '_woonuxt_deferred_payment', true, 'edit' ) ) { $this->fail_checkout_order(); }
		$this->owned_lifecycle->qualify_checkout_deferred_payment();
		$a->payment_started = true; $a->outcome = 'deferred'; $a->phase = 'deferred'; $a->deferred_checked = false;
		$a->deferred_total = $order->get_total( 'edit' ); $a->deferred_currency = $order->get_currency( 'edit' );
	}

	/** Native forced read bypasses the WC metadata cache; void save_meta_data is not proof. */
	public function finish_checkout_deferred_payment( $order, $result ): void {
		$a = $this->checkout_attempt;
		if ( 'deferred' !== ( $a->phase ?? null ) || $a->deferred_checked || [ 'result' => 'pending', 'redirect' => '' ] !== $result ) { $this->fail_checkout_order(); }
		$this->assert_checkout_deferred_invariants( $order ); $this->owned_lifecycle->assert_checkout_deferred_cohort();
		$a->phase = 'readback';
		try { $order->read_meta_data( true ); } catch ( \Throwable $error ) { $this->fail_checkout_order(); }
		$this->owned_lifecycle->assert_checkout_deferred_cohort(); $this->assert_checkout_deferred_invariants( $order );
		$this->assert_checkout_persisted_deferred_meta( $order );
		$a->deferred_checked = true; $a->phase = 'deferred_checked';
	}

	private function assert_checkout_persisted_deferred_meta( $order ): void {
		$a = $this->checkout_attempt;
		foreach ( [ '_woonuxt_deferred_payment' => 'yes', '_wl_checkout_operation_uuid' => $a->uuid ] as $key => $value ) {
			$matches = [];
			foreach ( $order->get_meta_data() as $meta ) { if ( $meta->key === $key ) { $matches[] = $meta; } }
			if ( 1 !== count( $matches ) || ! is_int( $matches[0]->id ) || $matches[0]->id <= 0 || $matches[0]->value !== $value ) { $this->fail_checkout_order(); }
		}
	}

	public function checkout_deferred_order_succeeded( $order ): void {
		$this->assert_checkout_deferred_invariants( $order );
		$a = $this->checkout_attempt;
		if ( 'deferred_checked' !== ( $a->phase ?? null ) || ! $a->deferred_checked || ! \WC()->cart->is_empty()
			|| 'yes' !== $order->get_meta( '_woonuxt_deferred_payment', true, 'edit' ) ) { $this->fail_checkout_order(); }
		$this->owned_lifecycle->assert_checkout_deferred_cohort(); $this->assert_checkout_persisted_deferred_meta( $order );
		unset( $this->_data['order_awaiting_payment'], $this->_data['reload_checkout'] );
		$this->_dirty = true; $a->success = true;
	}

	public function checkout_free_order_succeeded( $order ): void {
		if ( ! $this->protects_checkout_order() ) { return; }
		$this->verify_checkout_order_return( $order->get_id(), $order );
		if ( ! $this->checkout_attempt->payment_started || 'free' !== ( $this->checkout_attempt->outcome ?? 'free' )
			|| $order->needs_payment() || 0.0 !== (float) $order->get_total( 'edit' )
			|| ! in_array( $order->get_status( 'edit' ), [ 'processing', 'completed' ], true )
			|| ! $order->get_date_paid( 'edit' ) || ! \WC()->cart->is_empty() ) { $this->fail_checkout_order(); }
		unset( $this->_data['order_awaiting_payment'], $this->_data['reload_checkout'] );
		$this->_dirty = true; $this->checkout_attempt->success = true;
	}

	public function revalidate_checkout_order_success( $order ): void {
		if ( 'deferred' === ( $this->checkout_attempt->outcome ?? null ) ) { $this->checkout_deferred_order_succeeded( $order ); }
		else { $this->checkout_free_order_succeeded( $order ); }
	}

	/** Always close on the original receiver; no SQL, signing or hook dispatch. */
	public function end_checkout( $context, $info ): void {
		if ( $this->owned_operation ) { $this->owned_operation->leave_checkout( $context, $info ); }
	}

	/** Terminal callbacks stay inert; a live failed storage operation is explicit. */
	private function persistence_access_allowed() {
		if ( $this->session_access_allowed() ) {
			return true;
		}
		if ( $this->graphql_mode && ! $this->graphql_options && ! $this->auth_detached
			&& ! $this->owned_finalized && ! $this->owned_discarded
			&& ! ( $this->owned_lifecycle && $this->owned_lifecycle->is_terminal() )
			&& Cart_Session_Error::UNAVAILABLE === $this->session_failure ) {
			throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE );
		}
		return false;
	}

	/** @return bool */
	public function is_auth_detached() {
		return $this->auth_detached;
	}

	/** @inheritDoc */
	public function get( $key, $default = null ) {
		return $this->session_access_allowed() ? parent::get( $key, $default ) : $default;
	}

	/** @inheritDoc */
	public function set( $key, $value ) {
		if ( $this->session_access_allowed() ) {
			parent::set( $key, $value );
		}
	}

	/** @inheritDoc */
	public function __unset( $key ) {
		if ( $this->session_access_allowed() ) {
			parent::__unset( $key );
		}
	}

	/** Native checkout must not reinitialize/migrate an admitted GraphQL cart. */
	public function init_session_cookie() {
		// Consume only the armed native event before the ordinary changed-identity guard.
		if ( null !== $this->checkout_attempt && $this->checkout_attempt->auth_expected ) {
			if ( ! $this->graphql_mode || $this->checkout_attempt->user_id !== (int) get_current_user_id()
				|| ! $this->owned_lifecycle->consume_checkout_cookie_capsule() ) { $this->latch_owned_failure(); throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE ); }
			$this->checkout_attempt->auth_expected = false;
			$this->admitted_user_id = $this->checkout_attempt->user_id;
			$this->admitted_customer_id = (string) $this->admitted_user_id; $this->_customer_id = $this->admitted_customer_id;
			$this->prepared_token = false; $this->prepared_customer_token = false;
			$this->token_prepared = false; $this->customer_token_prepared = false;
			$this->_has_token = false; $this->_issuing_new_token = false; $this->_has_cookie = false; $this->_issuing_new_cookie = false;
			$this->checkout_attempt->adopted = true;
			return;
		}
		if ( ! $this->session_access_allowed() ) {
			return;
		}
		if ( $this->graphql_mode ) {
			return;
		}
		parent::init_session_cookie();
	}

	/** @inheritDoc */
	public function get_session( $customer_id, $default_value = false ) {
		if ( ! $this->persistence_access_allowed() ) {
			return $default_value;
		}
		if ( ! $this->native_cookie_mode && null !== $this->admitted_customer_id && (string) $customer_id !== $this->admitted_customer_id ) {
			$this->quarantine( $this->graphql_mode ? 'WL_CART_SESSION_TRANSITION_INVALID' : Cart_Session_Error::INVALID );
			if ( $this->graphql_mode ) {
				throw new Cart_Session_Transition_Error();
			}
			return $default_value;
		}
		if ( $this->graphql_mode ) {
			try {
				return $this->owned_storage->read( $default_value );
			} catch ( \Throwable $error ) {
				$this->latch_owned_failure();
				throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE );
			}
		}
		return parent::get_session( $customer_id, $default_value );
	}

	public function get_session_data() {
		return $this->graphql_mode ? (array) $this->get_session( (string) $this->_customer_id, [] ) : parent::get_session_data();
	}

	/** @inheritDoc */
	public function save_data( $old_session_key = '' ) {
		if ( ! $this->persistence_access_allowed() ) {
			return;
		}
		if ( ! $this->native_cookie_mode && '' !== $old_session_key && (string) $old_session_key !== $this->admitted_customer_id ) {
			$this->quarantine( $this->graphql_mode ? 'WL_CART_SESSION_TRANSITION_INVALID' : Cart_Session_Error::INVALID );
			if ( $this->graphql_mode ) {
				throw new Cart_Session_Transition_Error();
			}
			return;
		}
		if ( $this->graphql_mode ) {
			if ( null !== $this->checkout_attempt ) { return; }
			if ( $this->_dirty ) {
				try {
					$this->owned_storage->write( $this->_data, $this->_session_expiration );
					$this->_dirty = false;
				} catch ( \Throwable $error ) {
					$this->latch_owned_failure();
					throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE );
				}
			}
			return;
		}
		parent::save_data( $old_session_key );
	}

	/** @inheritDoc */
	public function delete_session( $customer_id ) {
		if ( ! $this->persistence_access_allowed() ) {
			return;
		}
		if ( ! $this->native_cookie_mode && (string) $customer_id !== (string) $this->_customer_id ) {
			$this->quarantine( $this->graphql_mode ? 'WL_CART_SESSION_TRANSITION_INVALID' : Cart_Session_Error::INVALID );
			if ( $this->graphql_mode ) {
				throw new Cart_Session_Transition_Error();
			}
			return;
		}
		if ( $this->graphql_mode ) {
			try {
				$this->owned_storage->delete();
			} catch ( \Throwable $error ) {
				$this->latch_owned_failure();
				throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE );
			}
			return;
		}
		parent::delete_session( $customer_id );
	}

	/** @inheritDoc */
	public function update_session_timestamp( $customer_id, $timestamp ) {
		if ( ! $this->persistence_access_allowed() ) {
			return;
		}
		if ( $this->graphql_mode && (string) $customer_id !== $this->admitted_customer_id ) {
			$this->quarantine( 'WL_CART_SESSION_TRANSITION_INVALID' );
			throw new Cart_Session_Transition_Error();
		}
		if ( (string) $customer_id === (string) $this->_customer_id ) {
			if ( $this->graphql_mode ) {
				try {
					$this->owned_storage->update_timestamp( $timestamp );
				} catch ( \Throwable $error ) {
					$this->latch_owned_failure();
					throw new Cart_Session_Error( Cart_Session_Error::UNAVAILABLE );
				}
				return;
			}
			parent::update_session_timestamp( $customer_id, $timestamp );
		}
	}

	/** @inheritDoc */
	public function destroy_session() {
		if ( $this->session_access_allowed() ) {
			if ( $this->graphql_mode ) {
				$this->quarantine( 'WL_CART_SESSION_TRANSITION_INVALID' );
				throw new Cart_Session_Transition_Error();
			}
			parent::destroy_session();
		}
	}

	/** @inheritDoc */
	public function forget_session() {
		if ( ! $this->session_access_allowed() ) {
			return;
		}
		if ( $this->graphql_mode ) {
			$this->quarantine( 'WL_CART_SESSION_TRANSITION_INVALID' );
			throw new Cart_Session_Transition_Error();
		}
		parent::forget_session();
		$this->prepared_token = false;
		$this->token_prepared = false;
		$this->admitted_customer_id = (string) $this->_customer_id;
	}

}

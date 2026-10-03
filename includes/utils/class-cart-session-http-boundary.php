<?php
/**
 * Request-local native PHP response boundary for owned GraphQL HTTP requests.
 *
 * Qualification requires unsent headers and a closed cohort of removable,
 * cleanable default buffers with no streaming chunk size. Unknown callbacks and
 * already-flushed output are unqualified: cleanup is attempted, but an exact
 * status/body cannot be guaranteed. This helper never closes unknown callbacks.
 * No WordPress formatting, translation, database or logging APIs are used.
 *
 * @package WPGraphQL\WooCommerce\Utils
 */

namespace WPGraphQL\WooCommerce\Utils;

final class Cart_Session_HTTP_Boundary {
	private const FAILURE_BODY = '{"errors":[{"message":"The cart session is temporarily unavailable.","extensions":{"code":"WL_CART_SESSION_UNAVAILABLE"}}]}';

	private $cleanup;
	private $credential_headers = [];
	private $cookie_names = [];
	private $handler;
	private $previous_handler;
	private $installed = false;
	private $cleanup_attempted = false;
	private $cleanup_succeeded = false;
	private $owned_buffer_level;
	private $terminal_mode;
	private $emission_started = false;

	/** Only exact, previously validated owned cart names may be supplied. */
	public function __construct( callable $cleanup, array $credential_headers, array $owned_cookie_names = [] ) {
		$this->cleanup = $cleanup;
		$this->handler = function ( \Throwable $error ): void {
			$this->handle_exception( $error );
		};
		foreach ( $credential_headers as $name ) {
			if ( ! $this->valid_name( $name ) ) {
				$this->fail();
			}
			$this->credential_headers[] = strtolower( $name );
		}
		foreach ( $owned_cookie_names as $name ) {
			if ( ! $this->valid_name( $name ) ) {
				$this->fail();
			}
			$this->cookie_names[] = $name;
		}
	}

	private function valid_name( $name ): bool {
		return is_string( $name ) && 1 === preg_match( '/\A[!#$%&\'*+.^_`|~0-9A-Za-z-]+\z/D', $name );
	}

	/** Install before construction/acquisition of the owned storage scope. */
	public function install(): void {
		if ( $this->installed ) {
			$this->fail();
		}
		$this->previous_handler = \set_exception_handler( $this->handler );
		$this->installed = true;
		if ( ! $this->qualified_output() || ! \ob_start() ) {
			$this->fail();
		}
		$this->owned_buffer_level = \ob_get_level();
	}

	private function default_buffer( array $buffer ): bool {
		$required = PHP_OUTPUT_HANDLER_CLEANABLE | PHP_OUTPUT_HANDLER_REMOVABLE;
		return 'default output handler' === ( $buffer['name'] ?? null )
			&& 0 === ( $buffer['type'] ?? null ) && 0 === ( $buffer['chunk_size'] ?? null )
			&& $required === ( ( $buffer['flags'] ?? 0 ) & $required );
	}

	private function qualified_output(): bool {
		if ( \headers_sent() ) {
			return false;
		}
		if ( null !== $this->owned_buffer_level && \ob_get_level() < $this->owned_buffer_level ) {
			return false;
		}
		foreach ( \ob_get_status( true ) as $buffer ) {
			if ( ! $this->default_buffer( $buffer ) ) {
				return false;
			}
		}
		return true;
	}

	/** Preencode while owned, then finalize without post-release serialization. */
	public function complete( $response, callable $checked_finalize, ?int $status = null ): never {
		if ( null !== $this->terminal_mode ) {
			$this->fail();
		}
		$this->terminal_mode = 'complete';
		try {
			$this->require_qualified();
			$body = \json_encode( $response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
			$this->require_qualified();
			$cookie_batch = $checked_finalize();
			$this->require_qualified();
			$status = $status ?? ( \http_response_code() ?: 200 );
			if ( $status < 100 || $status > 599 ) {
				$this->fail();
			}
		} catch ( \Throwable $error ) {
			$this->fail();
		}
		$this->publish_native_cookie_batch( $cookie_batch );
		$this->restore();
		$this->emit( $body, $status, false );
	}

	/** Pure publisher: exact native argument batch only, never a post-release callback. */
	private function publish_native_cookie_batch( $batch ): void {
		if ( null === $batch ) { return; }
		$previous = [];
		foreach ( \headers_list() as $header ) { if ( 0 === stripos( $header, 'Set-Cookie:' ) ) { $previous[] = $header; } }
		try {
			$this->require_qualified();
			if ( ! is_array( $batch ) || ! array_is_list( $batch ) || count( $batch ) < 3 || count( $batch ) > 4 ) { throw new \UnexpectedValueException(); }
			foreach ( $batch as $cookie ) {
				if ( ! is_array( $cookie ) || ! array_is_list( $cookie ) || 7 !== count( $cookie ) || ! $this->valid_name( $cookie[0] )
					|| ! is_string( $cookie[1] ) || '' === $cookie[1] || preg_match( '/[\r\n\x00]/', $cookie[1] )
					|| ! is_int( $cookie[2] ) || $cookie[2] <= time() || ! is_string( $cookie[3] ) || ! is_string( $cookie[4] )
					|| preg_match( '/[\r\n\x00;]/', $cookie[3] . $cookie[4] ) || ! is_bool( $cookie[5] ) || true !== $cookie[6] ) { throw new \UnexpectedValueException(); }
			}
			foreach ( $batch as $cookie ) { if ( ! \setcookie( ...$cookie ) ) { throw new \UnexpectedValueException(); } }
			$this->require_qualified();
		} catch ( \Throwable $error ) {
			// Undo only the newly queued batch; prior and unrelated auth remain byte-exact.
			if ( ! \headers_sent() ) { \header_remove( 'Set-Cookie' ); foreach ( $previous as $header ) { \header( $header, false ); } }
			$this->fail();
		}
	}

	/** Early auth responses are preserved without a successful writer flush. */
	public function discard( $response, int $status ): never {
		if ( null !== $this->terminal_mode ) {
			$this->fail();
		}
		$this->terminal_mode = 'discard';
		try {
			$this->require_qualified();
			if ( $status < 100 || $status > 599 ) {
				$this->fail();
			}
			$body = \json_encode( $response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
			$this->require_qualified();
		} catch ( \Throwable $error ) {
			$this->fail();
		}
		if ( ! $this->cleanup_once() ) {
			$this->fail();
		}
		$this->restore();
		$this->emit( $body, $status, true );
	}

	private function require_qualified(): void {
		if ( ! $this->installed || ! $this->qualified_output() ) {
			$this->fail();
		}
	}

	private function cleanup_once(): bool {
		if ( ! $this->cleanup_attempted ) {
			$this->cleanup_attempted = true;
			try {
				( $this->cleanup )();
				$this->cleanup_succeeded = true;
			} catch ( \Throwable $ignored ) {
				$this->cleanup_succeeded = false;
			}
		}
		return $this->cleanup_succeeded;
	}

	/** Explicit failure needs no error construction or framework callbacks. */
	public function fail(): never {
		// Once bytes are emitted there is no second status/body or retry path.
		if ( $this->emission_started ) { exit; }
		$this->terminal_mode = 'failed';
		$this->cleanup_once();
		$this->restore();
		$this->emit( self::FAILURE_BODY, 503, true );
	}

	private function owned_failure( \Throwable $error ): bool {
		return $error instanceof \WLCommerce\Database\Owned_Scope_Error
			|| ( $error instanceof Cart_Session_Error
				&& Cart_Session_Error::UNAVAILABLE === ( $error->getExtensions()['code'] ?? null ) );
	}

	private function handle_exception( \Throwable $error ): void {
		if ( $this->installed && $this->owned_failure( $error ) ) {
			$this->fail();
		}
		$this->terminal_mode = 'failed';
		$this->cleanup_once();
		$this->restore();
		if ( null !== $this->previous_handler ) {
			( $this->previous_handler )( $error );
			return;
		}
		// PHP's default uncaught-exception handling receives the same object.
		throw $error;
	}

	/** A later-installed handler stays current; native stacks cannot unlink below it. */
	public function restore(): void {
		if ( ! $this->installed ) {
			return;
		}
		$current = \set_exception_handler( $this->handler );
		\restore_exception_handler(); // Remove only the temporary inspection frame.
		if ( $current === $this->handler ) {
			\restore_exception_handler();
		}
		$this->installed = false;
	}

	private function remove_owned_headers( bool $remove_credentials ): void {
		if ( $remove_credentials ) {
			foreach ( $this->credential_headers as $name ) {
				\header_remove( $name );
			}
		}
		foreach ( [ 'Content-Length', 'Content-Encoding', 'Cache-Control', 'Expires', 'Pragma', 'ETag', 'Last-Modified', 'Age' ] as $name ) {
			\header_remove( $name );
		}
		if ( $remove_credentials && $this->cookie_names ) {
			$kept = [];
			$removed = false;
			foreach ( \headers_list() as $header ) {
				if ( 0 !== stripos( $header, 'Set-Cookie:' ) ) {
					continue;
				}
				if ( preg_match( '/\ASet-Cookie:\s*([^=;\s]+)=/i', $header, $match )
					&& in_array( $match[1], $this->cookie_names, true ) ) {
					$removed = true;
				} else {
					$kept[] = $header;
				}
			}
			if ( $removed ) {
				\header_remove( 'Set-Cookie' );
				foreach ( $kept as $header ) {
					\header( $header, false );
				}
			}
		}
	}

	private function emit( string $body, int $status, bool $remove_credentials ): never {
		// Drop only known default nonstreaming buffers; never invoke an unknown
		// callback explicitly. Exact output is promised only for qualified cohorts.
		while ( \ob_get_level() > 0 ) {
			$buffer = \ob_get_status();
			if ( ! is_array( $buffer ) || ! $this->default_buffer( $buffer ) || ! \ob_end_clean() ) {
				break;
			}
		}
		if ( ! \headers_sent() ) {
			$this->remove_owned_headers( $remove_credentials );
			\http_response_code( $status );
			\header( 'Content-Type: application/json; charset=UTF-8', true );
			// Keep the established private HTTP policy on every terminal outcome.
			\header( 'Cache-Control: no-store, no-cache', true );
			\header( 'Pragma: no-cache', true );
		}
		$this->emission_started = true;
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- preencoded native JSON.
		// PHP runs shutdown callbacks/destructors after exit. Keep their suffixes
		// out of the already emitted response; flags=0 forbids userland removal
		// and flushing. Small chunks discard repeated output incrementally.
		// This pure sink does not qualify shutdown side effects or unknown SAPIs.
		if ( ! \ob_start( static function ( string $discarded ): string { return ''; }, 4096, 0 ) ) { exit; }
		exit;
	}
}

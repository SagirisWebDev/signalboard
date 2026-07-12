<?php
/**
 * Anonymous voter fingerprint value object.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Voting;

use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * A salted, one-way fingerprint of an anonymous voter.
 *
 * The fingerprint combines the client IP with a per-visitor token (sent as the
 * `X-Signalboard-Token` header or a `signalboard_vote_token` cookie) under a
 * site secret, so the raw IP is never stored and the same visitor produces a
 * stable value for dedup. It is a value object: immutable, compared by value.
 */
final class VoterFingerprint {

	private const HEADER_KEY = 'HTTP_X_SIGNALBOARD_TOKEN';
	private const COOKIE_KEY = 'signalboard_vote_token';

	/**
	 * The computed sha256 hash (64 lowercase hex chars).
	 *
	 * @var string
	 */
	private string $hash;

	/**
	 * Constructor.
	 *
	 * @param string $hash Precomputed hash.
	 */
	private function __construct( string $hash ) {
		$this->hash = $hash;
	}

	/**
	 * Build a fingerprint from raw parts. Deterministic and framework-free.
	 *
	 * @param string $ip    Client IP address.
	 * @param string $token Per-visitor token.
	 * @param string $salt  Site secret / salt.
	 * @return self
	 */
	public static function fromParts( string $ip, string $token, string $salt ): self { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Named-constructor idiom (value-object factory).
		return new self( hash( 'sha256', $salt . '|' . $ip . '|' . $token ) );
	}

	/**
	 * Build a fingerprint from a server/cookie array pair.
	 *
	 * @param array<string, mixed> $server Server variables (REMOTE_ADDR, token header).
	 * @param array<string, mixed> $cookie Request cookies.
	 * @param string|null          $salt   Optional salt override (defaults to a site secret).
	 * @return self
	 */
	public static function fromServer( array $server, array $cookie, ?string $salt = null ): self { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Named-constructor idiom (value-object factory).
		$ip = self::clean( $server['REMOTE_ADDR'] ?? '' );

		$token = $server[ self::HEADER_KEY ] ?? ( $cookie[ self::COOKIE_KEY ] ?? '' );
		$token = self::clean( $token );

		if ( null === $salt ) {
			$salt = function_exists( 'wp_salt' ) ? wp_salt( 'nonce' ) : 'signalboard';
		}

		return self::fromParts( $ip, $token, $salt );
	}

	/**
	 * Build a fingerprint from the current REST request and PHP superglobals.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return self
	 */
	public static function fromRequest( WP_REST_Request $request ): self { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Named-constructor idiom (value-object factory).
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Individual fields are sanitized in fromServer().
		$server = $_SERVER;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Individual fields are sanitized in fromServer().
		$cookie = $_COOKIE;

		$header = $request->get_header( 'X-Signalboard-Token' );
		if ( is_string( $header ) && '' !== $header ) {
			$server[ self::HEADER_KEY ] = $header;
		}

		return self::fromServer( $server, $cookie );
	}

	/**
	 * The fingerprint value (64 lowercase hex characters).
	 *
	 * @return string
	 */
	public function value(): string {
		return $this->hash;
	}

	/**
	 * Coerce a raw value to a trimmed, sanitized string.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function clean( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		if ( function_exists( 'sanitize_text_field' ) && function_exists( 'wp_unslash' ) ) {
			return sanitize_text_field( wp_unslash( $value ) );
		}

		return trim( $value );
	}
}

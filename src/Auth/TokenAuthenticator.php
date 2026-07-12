<?php
/**
 * Bearer-token request authenticator.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the current user from a bearer JWT for both REST and WPGraphQL.
 *
 * Hooks `determine_current_user` at a late priority and acts only when nobody
 * else has authenticated the request and an `Authorization: Bearer <jwt>` header
 * is present. Because it never overrides an already-resolved user, cookie auth
 * and core Application Passwords keep working untouched — JWT simply fills the
 * gap for headless clients.
 */
final class TokenAuthenticator {

	/**
	 * Token service used to verify bearer tokens.
	 *
	 * @var AuthTokenService
	 */
	private AuthTokenService $tokens;

	/**
	 * Constructor.
	 *
	 * @param AuthTokenService $tokens Token service.
	 */
	public function __construct( AuthTokenService $tokens ) {
		$this->tokens = $tokens;
	}

	/**
	 * Register the authentication filter.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'determine_current_user', array( $this, 'resolve' ), 30 );
	}

	/**
	 * Resolve the current user id from a bearer token when unauthenticated.
	 *
	 * @param int|false $user_id The user id determined so far (false = none yet).
	 * @return int|false
	 */
	public function resolve( $user_id ) {
		// Never clobber an already-authenticated request (cookie / App Password).
		if ( ! empty( $user_id ) ) {
			return $user_id;
		}

		$token = $this->bearer_token();
		if ( null === $token ) {
			return $user_id;
		}

		$claims = $this->tokens->verify( $token );
		if ( is_wp_error( $claims ) ) {
			return $user_id;
		}

		return (int) $claims['sub'];
	}

	/**
	 * Extract the bearer token from the Authorization header, if any.
	 *
	 * @return string|null
	 */
	private function bearer_token(): ?string {
		$header = '';

		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
		} elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$header = sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
		}

		if ( 0 !== stripos( $header, 'Bearer ' ) ) {
			return null;
		}

		$token = trim( substr( $header, 7 ) );

		return '' !== $token ? $token : null;
	}
}

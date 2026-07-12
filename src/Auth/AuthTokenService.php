<?php
/**
 * Self-contained JWT token service.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Auth;

use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Issues, verifies and refreshes signed JWTs, and authenticates credentials.
 *
 * A deep module: the REST controller and the WPGraphQL mutations both delegate
 * here, so the two surfaces mint and validate identical tokens. Tokens are
 * signed with HS256 using a per-site secret; decoding always pins the algorithm
 * via {@see Key} (never trusting the token header), which is the correct usage
 * that avoids the algorithm-confusion class of issues (CVE-2025-45769).
 */
final class AuthTokenService {

	/**
	 * Option name holding the per-site signing secret.
	 */
	public const SECRET_OPTION = 'signalboard_jwt_secret';

	/**
	 * Signing algorithm. HS256 (shared secret) — the token header is never trusted.
	 */
	private const ALGORITHM = 'HS256';

	/**
	 * Signing secret.
	 *
	 * @var string
	 */
	private string $secret;

	/**
	 * Access-token lifetime, in seconds.
	 *
	 * @var int
	 */
	private int $access_ttl;

	/**
	 * Refresh-token lifetime, in seconds.
	 *
	 * @var int
	 */
	private int $refresh_ttl;

	/**
	 * Constructor.
	 *
	 * @param string $secret      Signing secret.
	 * @param int    $access_ttl  Access-token lifetime in seconds (default 15 min).
	 * @param int    $refresh_ttl Refresh-token lifetime in seconds (default 14 days).
	 */
	public function __construct( string $secret, int $access_ttl = 900, int $refresh_ttl = 1209600 ) {
		$this->secret      = $secret;
		$this->access_ttl  = $access_ttl;
		$this->refresh_ttl = $refresh_ttl;
	}

	/**
	 * Build a service using the site secret and default lifetimes.
	 *
	 * @return self
	 */
	public static function create(): self {
		return new self( self::secret() );
	}

	/**
	 * The per-site signing secret, generated and stored on first use.
	 *
	 * @return string
	 */
	public static function secret(): string {
		$secret = get_option( self::SECRET_OPTION );

		if ( ! is_string( $secret ) || '' === $secret ) {
			$secret = wp_generate_password( 64, true, true );
			update_option( self::SECRET_OPTION, $secret, false );
		}

		/**
		 * Filter the JWT signing secret (e.g. to source it from wp-config).
		 *
		 * @param string $secret The signing secret.
		 */
		return (string) apply_filters( 'signalboard_jwt_secret', $secret );
	}

	/**
	 * Authenticate a username/password pair.
	 *
	 * @param string $username Username or email.
	 * @param string $password Plaintext password.
	 * @return int|WP_Error The user ID, or an error on bad credentials.
	 */
	public function authenticate_credentials( string $username, string $password ) {
		$user = wp_authenticate( $username, $password );

		if ( $user instanceof WP_User ) {
			return (int) $user->ID;
		}

		return new WP_Error(
			'signalboard_bad_credentials',
			__( 'Invalid username or password.', 'signalboard' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Issue an access + refresh token pair for a user.
	 *
	 * @param int $user_id User ID.
	 * @return array{accessToken: string, refreshToken: string, tokenType: string, expiresIn: int}
	 */
	public function issue_for( int $user_id ): array {
		$now = time();

		return array(
			'accessToken'  => $this->encode( $user_id, 'access', $now, $now + $this->access_ttl ),
			'refreshToken' => $this->encode( $user_id, 'refresh', $now, $now + $this->refresh_ttl ),
			'tokenType'    => 'Bearer',
			'expiresIn'    => $this->access_ttl,
		);
	}

	/**
	 * Verify an access token and return its claims.
	 *
	 * @param string $token Access token.
	 * @return array<string, mixed>|WP_Error Claims on success, error otherwise.
	 */
	public function verify( string $token ) {
		$claims = $this->decode( $token );

		if ( is_wp_error( $claims ) ) {
			return $claims;
		}

		if ( 'access' !== ( $claims['typ'] ?? '' ) ) {
			return $this->invalid_token();
		}

		return $claims;
	}

	/**
	 * Exchange a refresh token for a fresh token pair.
	 *
	 * @param string $refresh_token Refresh token.
	 * @return array{accessToken: string, refreshToken: string, tokenType: string, expiresIn: int}|WP_Error
	 */
	public function refresh( string $refresh_token ) {
		$claims = $this->decode( $refresh_token );

		if ( is_wp_error( $claims ) ) {
			return $claims;
		}

		if ( 'refresh' !== ( $claims['typ'] ?? '' ) ) {
			return $this->invalid_token();
		}

		return $this->issue_for( (int) $claims['sub'] );
	}

	/**
	 * Encode a signed token.
	 *
	 * @param int    $user_id Subject user ID.
	 * @param string $typ     Token type ('access' or 'refresh').
	 * @param int    $iat     Issued-at timestamp.
	 * @param int    $exp     Expiry timestamp.
	 * @return string
	 */
	private function encode( int $user_id, string $typ, int $iat, int $exp ): string {
		$payload = array(
			'iss' => home_url(),
			'iat' => $iat,
			'nbf' => $iat,
			'exp' => $exp,
			'sub' => (string) $user_id,
			'typ' => $typ,
		);

		return JWT::encode( $payload, $this->secret, self::ALGORITHM );
	}

	/**
	 * Decode and validate a token's signature and time claims.
	 *
	 * @param string $token Encoded token.
	 * @return array<string, mixed>|WP_Error Claims on success, error otherwise.
	 */
	private function decode( string $token ) {
		try {
			$decoded = JWT::decode( $token, new Key( $this->secret, self::ALGORITHM ) );

			return (array) $decoded;
		} catch ( ExpiredException $e ) {
			return new WP_Error(
				'signalboard_token_expired',
				__( 'Authentication token has expired.', 'signalboard' ),
				array( 'status' => 401 )
			);
		} catch ( \Throwable $e ) {
			return $this->invalid_token();
		}
	}

	/**
	 * Shared "invalid token" error.
	 *
	 * @return WP_Error
	 */
	private function invalid_token(): WP_Error {
		return new WP_Error(
			'signalboard_token_invalid',
			__( 'Authentication token is invalid.', 'signalboard' ),
			array( 'status' => 401 )
		);
	}
}

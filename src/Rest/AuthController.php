<?php
/**
 * REST controller for JWT authentication.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Rest;

use Sagiris\Signalboard\Auth\AuthTokenService;
use Sagiris\Signalboard\Voting\RateLimiter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes register / login / refresh under signalboard/v1/auth.
 *
 * All three routes are public (they mint credentials); login is rate-limited per
 * client IP to throttle brute-force attempts. Every route delegates token work
 * to the shared {@see AuthTokenService}, so REST and WPGraphQL stay in lockstep.
 */
final class AuthController {

	private const NAMESPACE = 'signalboard/v1';

	/**
	 * Default login attempts permitted per client IP within the window.
	 */
	private const LOGIN_LIMIT = 10;

	/**
	 * Default login rate-limit window, in seconds.
	 */
	private const LOGIN_WINDOW = 300;

	/**
	 * Minimum acceptable password length at registration.
	 */
	private const MIN_PASSWORD_LENGTH = 8;

	/**
	 * Token service.
	 *
	 * @var AuthTokenService
	 */
	private AuthTokenService $tokens;

	/**
	 * Per-IP login rate limiter.
	 *
	 * @var RateLimiter
	 */
	private RateLimiter $login_limiter;

	/**
	 * Constructor.
	 *
	 * @param AuthTokenService $tokens        Token service.
	 * @param RateLimiter|null $login_limiter Login limiter (defaults to per-IP 10/5min).
	 */
	public function __construct( AuthTokenService $tokens, ?RateLimiter $login_limiter = null ) {
		$this->tokens        = $tokens;
		$this->login_limiter = $login_limiter ?? new RateLimiter( self::LOGIN_LIMIT, self::LOGIN_WINDOW, 'signalboard_login' );
	}

	/**
	 * Register the auth routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/auth/register',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'register_user' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/auth/login',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'login' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/auth/refresh',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'refresh' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Register a new subscriber and issue tokens.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function register_user( WP_REST_Request $request ) {
		$username = sanitize_user( (string) $request->get_param( 'username' ) );
		$email    = sanitize_email( (string) $request->get_param( 'email' ) );
		$password = (string) $request->get_param( 'password' );

		if ( '' === $username || ! validate_username( $username ) ) {
			return $this->registration_error( __( 'Please provide a valid username.', 'signalboard' ) );
		}

		if ( ! is_email( $email ) ) {
			return $this->registration_error( __( 'Please provide a valid email address.', 'signalboard' ) );
		}

		if ( strlen( $password ) < self::MIN_PASSWORD_LENGTH ) {
			return $this->registration_error(
				/* translators: %d: minimum password length. */
				sprintf( __( 'Password must be at least %d characters.', 'signalboard' ), self::MIN_PASSWORD_LENGTH )
			);
		}

		if ( username_exists( $username ) || email_exists( $email ) ) {
			return $this->registration_error( __( 'That username or email is already registered.', 'signalboard' ) );
		}

		$user_id = wp_insert_user(
			array(
				'user_login' => $username,
				'user_email' => $email,
				'user_pass'  => $password,
				'role'       => 'subscriber',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $this->registration_error( $user_id->get_error_message() );
		}

		return new WP_REST_Response( $this->tokens->issue_for( (int) $user_id ), 201 );
	}

	/**
	 * Authenticate credentials and issue tokens.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function login( WP_REST_Request $request ) {
		if ( ! $this->login_limiter->allow( 'ip_' . $this->client_ip() ) ) {
			return new WP_Error(
				'signalboard_too_many_attempts',
				__( 'Too many login attempts. Please try again later.', 'signalboard' ),
				array( 'status' => 429 )
			);
		}

		$username = sanitize_user( (string) $request->get_param( 'username' ) );
		$password = (string) $request->get_param( 'password' );

		$user_id = $this->tokens->authenticate_credentials( $username, $password );

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		return new WP_REST_Response( $this->tokens->issue_for( (int) $user_id ), 200 );
	}

	/**
	 * Exchange a refresh token for fresh tokens.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function refresh( WP_REST_Request $request ) {
		$result = $this->tokens->refresh( (string) $request->get_param( 'refreshToken' ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Shared registration error.
	 *
	 * @param string $message Human-readable message.
	 * @return WP_Error
	 */
	private function registration_error( string $message ): WP_Error {
		return new WP_Error( 'signalboard_registration_failed', $message, array( 'status' => 400 ) );
	}

	/**
	 * Best-effort client IP for the login rate-limit bucket.
	 *
	 * @return string
	 */
	private function client_ip(): string {
		if ( ! isset( $_SERVER['REMOTE_ADDR'] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
	}
}

<?php
/**
 * Integration tests for the REST auth routes (register / login / refresh).
 *
 * Drives the real signalboard/v1/auth routes through rest_do_request, rebooting
 * the REST server in set_up like VoteRestTest. Covers the register->login happy
 * path, bad-credential 401, login throttle 429, refresh 200, and garbage-token
 * 401.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Tests;

use Sagiris\Signalboard\Auth\AuthTokenService;
use Sagiris\Signalboard\Rest\AuthController;
use Sagiris\Signalboard\Voting\RateLimiter;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @covers \Sagiris\Signalboard\Rest\AuthController
 */
final class AuthRestTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		// Deterministic client IP so the IP-keyed login limiter is stable.
		$_SERVER['REMOTE_ADDR'] = '203.0.113.42';

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	/**
	 * Register an AuthController with an injected login limiter, replacing the
	 * default route registration for the test (mirrors VoteRestTest).
	 *
	 * @param RateLimiter $limiter Injected login limiter.
	 */
	private function register_with_rate_limiter( RateLimiter $limiter ): void {
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();

		$controller = new AuthController( AuthTokenService::create(), $limiter );
		$controller->register_routes();
	}

	private function register_request( string $username, string $email, string $password ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/signalboard/v1/auth/register' );
		$request->set_body_params(
			array(
				'username' => $username,
				'email'    => $email,
				'password' => $password,
			)
		);

		return rest_do_request( $request );
	}

	private function login_request( string $username, string $password ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/signalboard/v1/auth/login' );
		$request->set_body_params(
			array(
				'username' => $username,
				'password' => $password,
			)
		);

		return rest_do_request( $request );
	}

	public function test_register_returns_201_with_tokens(): void {
		$response = $this->register_request( 'newuser1', 'newuser1@example.com', 'password-123' );

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'accessToken', $data );
		$this->assertArrayHasKey( 'refreshToken', $data );
	}

	public function test_register_then_login_returns_200_with_tokens(): void {
		$this->register_request( 'newuser2', 'newuser2@example.com', 'password-123' );

		$response = $this->login_request( 'newuser2', 'password-123' );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'accessToken', $data );
		$this->assertArrayHasKey( 'refreshToken', $data );
	}

	public function test_login_with_wrong_password_returns_401(): void {
		$this->register_request( 'newuser3', 'newuser3@example.com', 'password-123' );

		$response = $this->login_request( 'newuser3', 'wrong-password' );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'signalboard_bad_credentials', $response->get_data()['code'] );
	}

	public function test_repeated_logins_are_throttled_with_429(): void {
		$this->register_request( 'newuser4', 'newuser4@example.com', 'password-123' );

		// Limit of 1: the first login attempt returns its normal status, the
		// second is rejected by the IP-keyed limiter.
		$this->register_with_rate_limiter( new RateLimiter( 1, 300, 'signalboard_login_test' ) );

		$first = $this->login_request( 'newuser4', 'password-123' );
		$this->assertContains( $first->get_status(), array( 200, 401 ), 'first attempt gets its normal status' );

		$second = $this->login_request( 'newuser4', 'password-123' );
		$this->assertSame( 429, $second->get_status() );
		$this->assertSame( 'signalboard_too_many_attempts', $second->get_data()['code'] );
	}

	public function test_refresh_returns_200_with_a_new_access_token(): void {
		$registered    = $this->register_request( 'newuser5', 'newuser5@example.com', 'password-123' );
		$refresh_token = $registered->get_data()['refreshToken'];

		$request = new WP_REST_Request( 'POST', '/signalboard/v1/auth/refresh' );
		$request->set_body_params( array( 'refreshToken' => $refresh_token ) );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'accessToken', $response->get_data() );
	}

	public function test_refresh_with_garbage_token_returns_401(): void {
		$request = new WP_REST_Request( 'POST', '/signalboard/v1/auth/refresh' );
		$request->set_body_params( array( 'refreshToken' => 'garbage.token.value' ) );
		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}
}

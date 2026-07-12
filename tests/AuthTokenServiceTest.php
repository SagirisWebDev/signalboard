<?php
/**
 * Isolation tests for the AuthTokenService deep module.
 *
 * Exercises the JWT issue/verify/refresh lifecycle and credential
 * authentication in isolation, with no REST or GraphQL surface involved.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Tests;

use Sagiris\Signalboard\Auth\AuthTokenService;
use WP_Error;
use WP_UnitTestCase;

/**
 * @covers \Sagiris\Signalboard\Auth\AuthTokenService
 */
final class AuthTokenServiceTest extends WP_UnitTestCase {

	private function service( int $access_ttl = 900 ): AuthTokenService {
		return new AuthTokenService( 'test-secret', $access_ttl );
	}

	public function test_issue_then_verify_round_trips_access_claims(): void {
		$user_id = self::factory()->user->create();
		$service = $this->service();

		$tokens = $service->issue_for( $user_id );
		$claims = $service->verify( $tokens['accessToken'] );

		$this->assertIsArray( $claims, 'verify must return claims for a valid access token' );
		$this->assertSame( (string) $user_id, (string) $claims['sub'] );
		$this->assertSame( 'access', $claims['typ'] );
	}

	public function test_expired_access_token_is_rejected(): void {
		$user_id = self::factory()->user->create();

		// Negative TTL => the issued access token's exp is already in the past.
		$service = new AuthTokenService( 'secret', -10 );
		$tokens  = $service->issue_for( $user_id );

		$result = $service->verify( $tokens['accessToken'] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'signalboard_token_expired', $result->get_error_code() );
	}

	public function test_tampered_access_token_is_rejected(): void {
		$user_id = self::factory()->user->create();
		$service = $this->service();

		$tokens = $service->issue_for( $user_id );
		$access = $tokens['accessToken'];
		// Mutate a character in the token body to break the signature.
		$tampered = substr( $access, 0, 20 ) . ( 'a' === $access[20] ? 'b' : 'a' ) . substr( $access, 21 );

		$result = $service->verify( $tampered );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'signalboard_token_invalid', $result->get_error_code() );
	}

	public function test_refresh_token_rejected_when_used_as_access_token(): void {
		$user_id = self::factory()->user->create();
		$service = $this->service();

		$tokens = $service->issue_for( $user_id );

		// A refresh token has typ !== 'access', so verify() must reject it.
		$result = $service->verify( $tokens['refreshToken'] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'signalboard_token_invalid', $result->get_error_code() );
	}

	public function test_refresh_issues_a_fresh_verifiable_access_token(): void {
		$user_id = self::factory()->user->create();
		$service = $this->service();

		$tokens = $service->issue_for( $user_id );
		$fresh  = $service->refresh( $tokens['refreshToken'] );

		$this->assertIsArray( $fresh, 'refresh must return a fresh tokens array' );
		$this->assertArrayHasKey( 'accessToken', $fresh );
		$this->assertArrayHasKey( 'refreshToken', $fresh );

		$claims = $service->verify( $fresh['accessToken'] );
		$this->assertIsArray( $claims, 'the refreshed access token must verify cleanly' );
		$this->assertSame( (string) $user_id, (string) $claims['sub'] );
	}

	public function test_refresh_with_garbage_token_is_an_error(): void {
		$service = $this->service();

		$result = $service->refresh( 'not.a.valid.token' );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_authenticate_credentials_returns_user_id_for_correct_password(): void {
		$user_id = self::factory()->user->create( array( 'user_login' => 'alice' ) );
		wp_set_password( 'secret-pass-123', $user_id );
		$service = $this->service();

		$result = $service->authenticate_credentials( 'alice', 'secret-pass-123' );

		$this->assertSame( $user_id, $result );
	}

	public function test_authenticate_credentials_rejects_bad_password(): void {
		$user_id = self::factory()->user->create( array( 'user_login' => 'bob' ) );
		wp_set_password( 'correct-horse-battery', $user_id );
		$service = $this->service();

		$result = $service->authenticate_credentials( 'bob', 'wrong-password' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'signalboard_bad_credentials', $result->get_error_code() );
	}

	public function test_issue_for_returns_bearer_type_and_integer_expiry(): void {
		$user_id = self::factory()->user->create();
		$service = $this->service();

		$tokens = $service->issue_for( $user_id );

		$this->assertSame( 'Bearer', $tokens['tokenType'] );
		$this->assertIsInt( $tokens['expiresIn'] );
	}
}

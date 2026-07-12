<?php
/**
 * Isolation tests for the TokenAuthenticator request middleware.
 *
 * Proves the determine_current_user filter resolves a bearer JWT to its user,
 * passes through when no token is present, and — critically — never clobbers an
 * already-authenticated request (which is what keeps Application Passwords and
 * cookie auth working alongside JWT).
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Tests;

use Sagiris\Signalboard\Auth\AuthTokenService;
use Sagiris\Signalboard\Auth\TokenAuthenticator;
use WP_UnitTestCase;

/**
 * @covers \Sagiris\Signalboard\Auth\TokenAuthenticator
 */
final class TokenAuthenticatorTest extends WP_UnitTestCase {

	private AuthTokenService $tokens;

	public function set_up(): void {
		parent::set_up();
		$this->tokens = new AuthTokenService( 'test-secret', 900 );
	}

	public function tear_down(): void {
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		parent::tear_down();
	}

	private function authenticator(): TokenAuthenticator {
		return new TokenAuthenticator( $this->tokens );
	}

	public function test_valid_bearer_token_resolves_to_the_user_id(): void {
		$user_id = self::factory()->user->create();
		$access  = $this->tokens->issue_for( $user_id )['accessToken'];

		$_SERVER['HTTP_AUTHORIZATION'] = "Bearer {$access}";

		$resolved = $this->authenticator()->resolve( false );

		$this->assertSame( $user_id, $resolved );
	}

	public function test_no_authorization_header_passes_through_false(): void {
		unset( $_SERVER['HTTP_AUTHORIZATION'] );

		$this->assertFalse( $this->authenticator()->resolve( false ) );
	}

	public function test_pre_authenticated_user_is_preserved(): void {
		// A different user's bearer token is present, but the request is already
		// authenticated as user 7 (e.g. via an Application Password). The
		// middleware must NOT clobber that — it returns the incoming id verbatim.
		$other_id = self::factory()->user->create();
		$access   = $this->tokens->issue_for( $other_id )['accessToken'];

		$_SERVER['HTTP_AUTHORIZATION'] = "Bearer {$access}";

		$this->assertSame( 7, $this->authenticator()->resolve( 7 ) );
	}
}

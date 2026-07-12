<?php
/**
 * Isolation tests for the AuthorizationPolicy seam.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Tests;

use Sagiris\Signalboard\Auth\AuthorizationPolicy;
use WP_UnitTestCase;

/**
 * @covers \Sagiris\Signalboard\Auth\AuthorizationPolicy
 */
final class AuthorizationPolicyTest extends WP_UnitTestCase {

	private AuthorizationPolicy $policy;

	public function set_up(): void {
		parent::set_up();
		$this->policy = new AuthorizationPolicy();
	}

	public function test_upvoting_is_open_to_anonymous_visitors(): void {
		$this->assertTrue( $this->policy->can_upvote( null ) );
		$this->assertTrue( $this->policy->can_upvote( 0 ) );
		$this->assertTrue( $this->policy->can_upvote( 42 ) );
	}

	public function test_submission_requires_an_authenticated_user(): void {
		$this->assertTrue( $this->policy->can_submit( 42 ) );
	}

	public function test_anonymous_submission_is_rejected(): void {
		$this->assertFalse( $this->policy->can_submit( null ) );
		$this->assertFalse( $this->policy->can_submit( 0 ) );
		$this->assertFalse( $this->policy->can_submit( -1 ) );
	}
}

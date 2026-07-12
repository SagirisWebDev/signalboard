<?php
/**
 * Isolation tests for the moderation capability gate on AuthorizationPolicy.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Tests;

use Sagiris\Signalboard\Auth\AuthorizationPolicy;
use WP_UnitTestCase;

/**
 * @covers \Sagiris\Signalboard\Auth\AuthorizationPolicy::can_moderate
 */
final class ModerationAuthorizationTest extends WP_UnitTestCase {

	private AuthorizationPolicy $policy;

	public function set_up(): void {
		parent::set_up();
		$this->policy = new AuthorizationPolicy();
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_moderation_is_denied_to_anonymous_and_invalid_ids(): void {
		$this->assertFalse( $this->policy->can_moderate( null ) );
		$this->assertFalse( $this->policy->can_moderate( 0 ) );
		$this->assertFalse( $this->policy->can_moderate( -1 ) );
	}

	public function test_moderation_is_denied_to_a_subscriber(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->assertFalse( $this->policy->can_moderate( $subscriber ) );
	}

	public function test_moderation_is_allowed_for_an_editor(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );

		$this->assertTrue( $this->policy->can_moderate( $editor ) );
	}

	public function test_moderation_is_allowed_for_an_administrator(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->assertTrue( $this->policy->can_moderate( $admin ) );
	}

	public function test_moderation_capability_is_filterable(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		// By default a subscriber lacks edit_others_posts and cannot moderate.
		$this->assertFalse( $this->policy->can_moderate( $subscriber ) );

		// Lower the required capability to one a subscriber holds.
		$filter = static fn(): string => 'read';
		add_filter( 'signalboard_moderate_capability', $filter );

		$this->assertTrue( $this->policy->can_moderate( $subscriber ) );

		remove_filter( 'signalboard_moderate_capability', $filter );
	}
}

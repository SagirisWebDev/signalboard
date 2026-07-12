<?php
/**
 * Isolation tests for the transient-backed sliding-window RateLimiter.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Tests;

use Sagiris\Signalboard\Voting\RateLimiter;
use WP_UnitTestCase;

/**
 * @covers \Sagiris\Signalboard\Voting\RateLimiter
 */
final class RateLimiterTest extends WP_UnitTestCase {

	/**
	 * Current time for the injected clock; mutated by tests to advance the window.
	 *
	 * @var int
	 */
	private int $now = 1000;

	private function limiter( int $limit = 2, int $window = 60 ): RateLimiter {
		$clock = function () {
			return $this->now;
		};

		return new RateLimiter( $limit, $window, 'signalboard_test', $clock );
	}

	public function test_allows_while_under_the_limit(): void {
		$limiter = $this->limiter( 2, 60 );

		$this->assertTrue( $limiter->allow( 'k' ), 'first attempt allowed' );
		$this->assertTrue( $limiter->allow( 'k' ), 'second attempt allowed (still under limit of 2)' );
	}

	public function test_blocks_at_the_limit(): void {
		$limiter = $this->limiter( 2, 60 );

		$limiter->allow( 'k' );
		$limiter->allow( 'k' );

		$this->assertFalse( $limiter->allow( 'k' ), 'third attempt blocked at limit of 2' );
	}

	public function test_resets_after_the_window_passes(): void {
		$limiter = $this->limiter( 2, 60 );

		$limiter->allow( 'k' );
		$limiter->allow( 'k' );
		$this->assertFalse( $limiter->allow( 'k' ) );

		// Advance the clock past the trailing window so old attempts are pruned.
		$this->now += 61;

		$this->assertTrue( $limiter->allow( 'k' ), 'window elapsed, limiter must allow again' );
	}

	public function test_keys_are_isolated_from_each_other(): void {
		$limiter = $this->limiter( 1, 60 );

		$this->assertTrue( $limiter->allow( 'key-a' ) );
		$this->assertFalse( $limiter->allow( 'key-a' ) );

		// A different key has its own independent budget.
		$this->assertTrue( $limiter->allow( 'key-b' ) );
	}
}

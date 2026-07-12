<?php
/**
 * Isolation tests for CorsHeaders — the origin-allow decision for signalboard
 * REST routes. Only the allows() decision is unit-tested; header emission is
 * a thin wp-hook wrapper and out of scope here.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Tests;

use Sagiris\Signalboard\Webhook\CorsHeaders;
use Sagiris\Signalboard\Webhook\WebhookSettings;
use WP_UnitTestCase;

/**
 * @covers \Sagiris\Signalboard\Webhook\CorsHeaders
 */
final class CorsHeadersTest extends WP_UnitTestCase {

	private const ORIGIN = 'https://front.example.test';

	public function tear_down(): void {
		delete_option( WebhookSettings::OPTION );
		remove_all_filters( 'rest_pre_serve_request' );
		parent::tear_down();
	}

	private function with_origin( string $origin ): CorsHeaders {
		update_option(
			WebhookSettings::OPTION,
			array( 'cors_origin' => $origin )
		);
		return new CorsHeaders();
	}

	public function test_allows_configured_origin_on_a_signalboard_route(): void {
		$cors = $this->with_origin( self::ORIGIN );

		$this->assertTrue( $cors->allows( '/signalboard/v1/requests', self::ORIGIN ) );
	}

	public function test_allows_signalboard_route_without_leading_slash(): void {
		$cors = $this->with_origin( self::ORIGIN );

		$this->assertTrue( $cors->allows( 'signalboard/v1/requests', self::ORIGIN ) );
	}

	public function test_rejects_a_mismatched_origin(): void {
		$cors = $this->with_origin( self::ORIGIN );

		$this->assertFalse( $cors->allows( '/signalboard/v1/requests', 'https://evil.example.test' ) );
	}

	public function test_rejects_when_no_origin_is_configured(): void {
		$cors = $this->with_origin( '' );

		$this->assertFalse( $cors->allows( '/signalboard/v1/requests', self::ORIGIN ) );
	}

	public function test_rejects_a_non_signalboard_route(): void {
		$cors = $this->with_origin( self::ORIGIN );

		$this->assertFalse( $cors->allows( '/wp/v2/posts', self::ORIGIN ) );
	}
}

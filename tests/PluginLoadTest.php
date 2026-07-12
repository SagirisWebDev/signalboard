<?php
/**
 * Smoke tests for plugin bootstrapping without WPGraphQL present.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Tests;

use Sagiris\Signalboard\Domain\FeedbackRepository;
use Sagiris\Signalboard\GraphQL\RequestsGraphQL;
use WP_UnitTestCase;

/**
 * @covers \Sagiris\Signalboard\Plugin
 * @covers \Sagiris\Signalboard\GraphQL\RequestsGraphQL
 */
final class PluginLoadTest extends WP_UnitTestCase {

	public function test_plugin_singleton_is_available(): void {
		$this->assertInstanceOf(
			\Sagiris\Signalboard\Plugin::class,
			\Sagiris\Signalboard\signalboard()
		);
	}

	public function test_plugin_exposes_repository(): void {
		$this->assertInstanceOf(
			FeedbackRepository::class,
			\Sagiris\Signalboard\signalboard()->repository()
		);
	}

	public function test_graphql_registration_is_noop_without_wpgraphql(): void {
		// WPGraphQL is absent in the test env, so register_graphql_field is undefined.
		$this->assertFalse( function_exists( 'register_graphql_field' ) );

		// Calling register() must not throw or emit errors when WPGraphQL is inactive.
		( new RequestsGraphQL() )->register();

		$this->assertTrue( true );
	}
}

<?php
/**
 * Isolation tests for WebhookSettings — the Settings-API option value object.
 *
 * Exercises defaults, getters, event gating, and input sanitisation in
 * isolation, with no HTTP or REST surface involved.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Tests;

use Sagiris\Signalboard\Webhook\WebhookSettings;
use WP_UnitTestCase;

/**
 * @covers \Sagiris\Signalboard\Webhook\WebhookSettings
 */
final class WebhookSettingsTest extends WP_UnitTestCase {

	public function tear_down(): void {
		delete_option( WebhookSettings::OPTION );
		parent::tear_down();
	}

	public function test_option_constant_is_the_documented_key(): void {
		$this->assertSame( 'signalboard_webhook', WebhookSettings::OPTION );
	}

	public function test_defaults_match_the_documented_shape(): void {
		$defaults = WebhookSettings::defaults();

		$this->assertSame(
			array(
				'enabled'     => false,
				'endpoint'    => '',
				'secret'      => '',
				'events'      => array( 'status_changed', 'publish_state_changed' ),
				'cors_origin' => '',
			),
			$defaults
		);
	}

	public function test_getters_return_defaults_when_option_absent(): void {
		delete_option( WebhookSettings::OPTION );

		$settings = new WebhookSettings();

		$this->assertFalse( $settings->is_enabled() );
		$this->assertSame( '', $settings->endpoint() );
		$this->assertSame( '', $settings->secret() );
		$this->assertSame( array( 'status_changed', 'publish_state_changed' ), $settings->events() );
		$this->assertSame( '', $settings->cors_origin() );
	}

	public function test_getters_reflect_a_stored_option(): void {
		update_option(
			WebhookSettings::OPTION,
			array(
				'enabled'     => true,
				'endpoint'    => 'https://example.test/api/revalidate',
				'secret'      => 'top-secret-shared-key',
				'events'      => array( 'status_changed' ),
				'cors_origin' => 'https://front.example.test',
			)
		);

		$settings = new WebhookSettings();

		$this->assertTrue( $settings->is_enabled() );
		$this->assertSame( 'https://example.test/api/revalidate', $settings->endpoint() );
		$this->assertSame( 'top-secret-shared-key', $settings->secret() );
		$this->assertSame( array( 'status_changed' ), $settings->events() );
		$this->assertSame( 'https://front.example.test', $settings->cors_origin() );
	}

	public function test_stored_option_is_merged_over_defaults(): void {
		// Only endpoint stored — events must fall back to the default pair.
		update_option(
			WebhookSettings::OPTION,
			array( 'endpoint' => 'https://example.test/hook' )
		);

		$settings = new WebhookSettings();

		$this->assertSame( 'https://example.test/hook', $settings->endpoint() );
		$this->assertFalse( $settings->is_enabled() );
		$this->assertSame( array( 'status_changed', 'publish_state_changed' ), $settings->events() );
	}

	public function test_event_enabled_is_true_for_a_configured_event(): void {
		update_option(
			WebhookSettings::OPTION,
			array( 'events' => array( 'status_changed' ) )
		);

		$settings = new WebhookSettings();

		$this->assertTrue( $settings->event_enabled( 'status_changed' ) );
	}

	public function test_event_enabled_is_false_for_an_unconfigured_event(): void {
		update_option(
			WebhookSettings::OPTION,
			array( 'events' => array( 'status_changed' ) )
		);

		$settings = new WebhookSettings();

		$this->assertFalse( $settings->event_enabled( 'publish_state_changed' ) );
	}

	public function test_sanitize_coerces_enabled_to_bool(): void {
		$clean = WebhookSettings::sanitize( array( 'enabled' => '1' ) );
		$this->assertTrue( $clean['enabled'] );

		$off = WebhookSettings::sanitize( array() );
		$this->assertFalse( $off['enabled'] );
	}

	public function test_sanitize_url_cleans_endpoint_and_cors_origin(): void {
		$clean = WebhookSettings::sanitize(
			array(
				'endpoint'    => 'https://example.test/api/revalidate',
				'cors_origin' => 'https://front.example.test',
			)
		);

		$this->assertSame( 'https://example.test/api/revalidate', $clean['endpoint'] );
		$this->assertSame( 'https://front.example.test', $clean['cors_origin'] );
	}

	public function test_sanitize_strips_tags_from_secret(): void {
		$clean = WebhookSettings::sanitize( array( 'secret' => '  shh<script>alert(1)</script>  ' ) );

		$this->assertStringNotContainsString( '<script>', $clean['secret'] );
		$this->assertStringNotContainsString( '</script>', $clean['secret'] );
	}

	public function test_sanitize_drops_unknown_event_keys(): void {
		$clean = WebhookSettings::sanitize(
			array(
				'events' => array( 'status_changed', 'totally_made_up', 'publish_state_changed' ),
			)
		);

		$this->assertContains( 'status_changed', $clean['events'] );
		$this->assertContains( 'publish_state_changed', $clean['events'] );
		$this->assertNotContains( 'totally_made_up', $clean['events'] );
	}

	public function test_sanitize_output_round_trips_through_getters(): void {
		$clean = WebhookSettings::sanitize(
			array(
				'enabled'     => 'on',
				'endpoint'    => 'https://example.test/hook',
				'secret'      => 'k',
				'events'      => array( 'publish_state_changed' ),
				'cors_origin' => 'https://front.example.test',
			)
		);

		update_option( WebhookSettings::OPTION, $clean );
		$settings = new WebhookSettings();

		$this->assertTrue( $settings->is_enabled() );
		$this->assertTrue( $settings->event_enabled( 'publish_state_changed' ) );
		$this->assertFalse( $settings->event_enabled( 'status_changed' ) );
	}
}

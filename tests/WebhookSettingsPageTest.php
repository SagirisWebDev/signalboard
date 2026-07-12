<?php
/**
 * Light registration-surface tests for the webhook SettingsPage.
 *
 * The substantive sanitisation logic is covered by WebhookSettingsTest; here we
 * only assert that register_settings() registers the option under the expected
 * setting group/key via the Settings API.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Tests;

use Sagiris\Signalboard\Webhook\SettingsPage;
use Sagiris\Signalboard\Webhook\WebhookSettings;
use WP_UnitTestCase;

/**
 * @covers \Sagiris\Signalboard\Webhook\SettingsPage
 */
final class WebhookSettingsPageTest extends WP_UnitTestCase {

	public function tear_down(): void {
		unregister_setting( 'signalboard_webhook', WebhookSettings::OPTION );
		parent::tear_down();
	}

	public function test_register_settings_registers_the_webhook_option(): void {
		$page = new SettingsPage();
		$page->register_settings();

		$registered = get_registered_settings();

		$this->assertArrayHasKey(
			WebhookSettings::OPTION,
			$registered,
			'the webhook option must be registered via the Settings API'
		);
	}
}

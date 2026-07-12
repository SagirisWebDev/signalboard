<?php
/**
 * Isolation tests for WebhookDispatcher — signing + payload + outbound POST.
 *
 * Outbound HTTP is stubbed via the `pre_http_request` filter so the dispatcher
 * is exercised without touching the network. The signature is independently
 * recomputed with hash_hmac to prove standard HMAC-SHA256.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Tests;

use Sagiris\Signalboard\Content\RequestPostType;
use Sagiris\Signalboard\Webhook\WebhookDispatcher;
use Sagiris\Signalboard\Webhook\WebhookSettings;
use WP_UnitTestCase;

/**
 * @covers \Sagiris\Signalboard\Webhook\WebhookDispatcher
 */
final class WebhookDispatcherTest extends WP_UnitTestCase {

	private const SECRET   = 'shared-secret-key-123';
	private const ENDPOINT = 'https://example.test/api/revalidate';

	public function tear_down(): void {
		delete_option( WebhookSettings::OPTION );
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	/**
	 * Store an enabled webhook option covering both events.
	 */
	private function configure( bool $enabled = true, string $endpoint = self::ENDPOINT ): void {
		update_option(
			WebhookSettings::OPTION,
			array(
				'enabled'     => $enabled,
				'endpoint'    => $endpoint,
				'secret'      => self::SECRET,
				'events'      => array( 'status_changed', 'publish_state_changed' ),
				'cors_origin' => '',
			)
		);
	}

	/**
	 * Install a pre_http_request stub that records the request and 200s.
	 *
	 * @param array|null $captured Filled by reference with url + args.
	 * @param int        $calls    Incremented by reference on each hit.
	 */
	private function stub_http( ?array &$captured, int &$calls ): void {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$captured, &$calls ) {
				++$calls;
				$captured = array(
					'url'  => $url,
					'args' => $args,
				);
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => '{"ok":true}',
				);
			},
			10,
			3
		);
	}

	public function test_signature_header_constants_are_documented(): void {
		$this->assertSame( 'X-Signalboard-Signature', WebhookDispatcher::SIGNATURE_HEADER );
		$this->assertSame( 'X-Signalboard-Event', WebhookDispatcher::EVENT_HEADER );
	}

	public function test_sign_is_standard_hmac_sha256_over_the_body(): void {
		$this->configure();
		$dispatcher = new WebhookDispatcher();

		$body     = '{"event":"status_changed"}';
		$expected = hash_hmac( 'sha256', $body, self::SECRET );

		$this->assertSame( $expected, $dispatcher->sign( $body ) );
		// Hex, no prefix.
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $dispatcher->sign( $body ) );
	}

	public function test_build_payload_has_all_required_fields(): void {
		$this->configure();
		$post_id = self::factory()->post->create(
			array(
				'post_type' => RequestPostType::POST_TYPE,
				'post_name' => 'add-webhooks',
			)
		);

		$dispatcher = new WebhookDispatcher();
		$payload    = $dispatcher->build_payload( 'status_changed', $post_id, 'open', 'planned' );

		$this->assertSame( 'status_changed', $payload['event'] );
		$this->assertSame( $post_id, $payload['entity']['id'] );
		$this->assertSame( 'signalboard_request', $payload['entity']['type'] );
		$this->assertSame( 'add-webhooks', $payload['entity']['slug'] );
		$this->assertSame( 'open', $payload['oldStatus'] );
		$this->assertSame( 'planned', $payload['newStatus'] );
	}

	public function test_dispatch_posts_a_signed_request_when_enabled(): void {
		$this->configure();
		$post_id = self::factory()->post->create(
			array(
				'post_type' => RequestPostType::POST_TYPE,
				'post_name' => 'add-webhooks',
			)
		);

		$captured = null;
		$calls    = 0;
		$this->stub_http( $captured, $calls );

		$dispatcher = new WebhookDispatcher();
		$result     = $dispatcher->dispatch( 'status_changed', $post_id, 'open', 'planned' );

		// Exactly one POST to the configured endpoint.
		$this->assertSame( 1, $calls, 'exactly one HTTP request must be made' );
		$this->assertSame( self::ENDPOINT, $captured['url'] );
		$this->assertSame( 'POST', $captured['args']['method'] );

		// The captured body JSON-decodes to the full payload.
		$body    = $captured['args']['body'];
		$decoded = json_decode( $body, true );
		$this->assertSame( 'status_changed', $decoded['event'] );
		$this->assertSame( $post_id, $decoded['entity']['id'] );
		$this->assertSame( 'signalboard_request', $decoded['entity']['type'] );
		$this->assertSame( 'add-webhooks', $decoded['entity']['slug'] );
		$this->assertSame( 'open', $decoded['oldStatus'] );
		$this->assertSame( 'planned', $decoded['newStatus'] );

		// Headers: content type, event, and an independently-verifiable signature.
		$headers = $captured['args']['headers'];
		$this->assertSame( 'application/json', $headers['Content-Type'] );
		$this->assertSame( 'status_changed', $headers[ WebhookDispatcher::EVENT_HEADER ] );
		$this->assertSame(
			hash_hmac( 'sha256', $body, self::SECRET ),
			$headers[ WebhookDispatcher::SIGNATURE_HEADER ],
			'signature header must be HMAC-SHA256 of the exact captured body'
		);

		// The dispatch result is the wp_remote_post return (our stubbed 200).
		$this->assertSame( 200, wp_remote_retrieve_response_code( $result ) );
	}

	public function test_dispatch_returns_null_and_makes_no_request_when_disabled(): void {
		$this->configure( false );
		$post_id = self::factory()->post->create(
			array( 'post_type' => RequestPostType::POST_TYPE )
		);

		$captured = null;
		$calls    = 0;
		$this->stub_http( $captured, $calls );

		$dispatcher = new WebhookDispatcher();
		$result     = $dispatcher->dispatch( 'status_changed', $post_id, 'open', 'planned' );

		$this->assertNull( $result );
		$this->assertSame( 0, $calls, 'no HTTP request may be made when disabled' );
	}

	public function test_dispatch_returns_null_and_makes_no_request_when_endpoint_empty(): void {
		$this->configure( true, '' );
		$post_id = self::factory()->post->create(
			array( 'post_type' => RequestPostType::POST_TYPE )
		);

		$captured = null;
		$calls    = 0;
		$this->stub_http( $captured, $calls );

		$dispatcher = new WebhookDispatcher();
		$result     = $dispatcher->dispatch( 'status_changed', $post_id, 'open', 'planned' );

		$this->assertNull( $result );
		$this->assertSame( 0, $calls, 'no HTTP request may be made without an endpoint' );
	}

	public function test_dispatch_returns_null_when_event_not_enabled(): void {
		update_option(
			WebhookSettings::OPTION,
			array(
				'enabled'  => true,
				'endpoint' => self::ENDPOINT,
				'secret'   => self::SECRET,
				'events'   => array( 'publish_state_changed' ),
			)
		);
		$post_id = self::factory()->post->create(
			array( 'post_type' => RequestPostType::POST_TYPE )
		);

		$captured = null;
		$calls    = 0;
		$this->stub_http( $captured, $calls );

		$dispatcher = new WebhookDispatcher();
		// status_changed is NOT in the enabled events list.
		$result = $dispatcher->dispatch( 'status_changed', $post_id, 'open', 'planned' );

		$this->assertNull( $result );
		$this->assertSame( 0, $calls, 'no HTTP request may be made for a disabled event' );
	}

	public function test_dispatch_accepts_an_injected_settings_object(): void {
		// No option stored; settings injected directly.
		$settings = $this->settings_from(
			array(
				'enabled'  => true,
				'endpoint' => self::ENDPOINT,
				'secret'   => self::SECRET,
				'events'   => array( 'publish_state_changed' ),
			)
		);

		$post_id = self::factory()->post->create(
			array( 'post_type' => RequestPostType::POST_TYPE )
		);

		$captured = null;
		$calls    = 0;
		$this->stub_http( $captured, $calls );

		$dispatcher = new WebhookDispatcher( $settings );
		$dispatcher->dispatch( 'publish_state_changed', $post_id, null, 'publish' );

		$this->assertSame( 1, $calls );
		$this->assertSame(
			hash_hmac( 'sha256', $captured['args']['body'], self::SECRET ),
			$captured['args']['headers'][ WebhookDispatcher::SIGNATURE_HEADER ]
		);
	}

	/**
	 * Build a WebhookSettings from an array by round-tripping through the option.
	 *
	 * @param array $values Raw settings values.
	 */
	private function settings_from( array $values ): WebhookSettings {
		update_option( WebhookSettings::OPTION, $values );
		$settings = new WebhookSettings();
		delete_option( WebhookSettings::OPTION );
		return $settings;
	}
}

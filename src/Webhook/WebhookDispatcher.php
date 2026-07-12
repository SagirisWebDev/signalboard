<?php
/**
 * Signed webhook dispatcher.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Webhook;

use Sagiris\Signalboard\Content\RequestPostType;

defined( 'ABSPATH' ) || exit;

/**
 * Builds, signs and POSTs a webhook payload when a request changes.
 *
 * The deep module of the revalidation loop: given an event and a request, it
 * assembles the documented payload, signs the exact JSON body with HMAC-SHA256
 * using the shared secret, and delivers it via `wp_remote_post`. The receiver
 * (a Next.js route in the demo repo) recomputes the same HMAC over the raw body
 * to authenticate the call, then revalidates the affected pages on demand.
 *
 * Delivery is gated: nothing is sent unless webhooks are enabled, an endpoint
 * is configured, and the specific event is switched on.
 *
 * Not `final`: test doubles (and future delivery variants) subclass this to
 * override {@see self::dispatch()} without making real HTTP requests.
 */
class WebhookDispatcher {

	/**
	 * Header carrying the HMAC-SHA256 signature of the raw body (hex).
	 */
	public const SIGNATURE_HEADER = 'X-Signalboard-Signature';

	/**
	 * Header naming the event that triggered the delivery.
	 */
	public const EVENT_HEADER = 'X-Signalboard-Event';

	/**
	 * Webhook settings.
	 *
	 * @var WebhookSettings
	 */
	private WebhookSettings $settings;

	/**
	 * Constructor.
	 *
	 * @param WebhookSettings|null $settings Settings (defaults to a fresh read).
	 */
	public function __construct( ?WebhookSettings $settings = null ) {
		$this->settings = $settings ?? new WebhookSettings();
	}

	/**
	 * HMAC-SHA256 signature (hex) of a body using the shared secret.
	 *
	 * @param string $body Raw request body.
	 * @return string Lower-case hex digest.
	 */
	public function sign( string $body ): string {
		return hash_hmac( 'sha256', $body, $this->settings->secret() );
	}

	/**
	 * Build the webhook payload for a request change.
	 *
	 * @param string      $event      Event key.
	 * @param int         $post_id    Request post ID.
	 * @param string|null $old_status Previous status/state.
	 * @param string|null $new_status New status/state.
	 * @return array<string, mixed>
	 */
	public function build_payload( string $event, int $post_id, ?string $old_status, ?string $new_status ): array {
		$post = get_post( $post_id );

		return array(
			'event'     => $event,
			'entity'    => array(
				'id'   => $post_id,
				'type' => RequestPostType::POST_TYPE,
				'slug' => $post ? (string) $post->post_name : '',
			),
			'oldStatus' => $old_status,
			'newStatus' => $new_status,
		);
	}

	/**
	 * Deliver a signed webhook for a request change.
	 *
	 * Returns null (making no HTTP request) when delivery is gated off. Note:
	 * the return type is intentionally undeclared so test doubles may override
	 * this method with a narrower signature.
	 *
	 * @param string      $event      Event key.
	 * @param int         $post_id    Request post ID.
	 * @param string|null $old_status Previous status/state.
	 * @param string|null $new_status New status/state.
	 * @return array<string, mixed>|\WP_Error|null wp_remote_post result, or null when gated off.
	 */
	public function dispatch( string $event, int $post_id, ?string $old_status, ?string $new_status ) {
		if ( ! $this->settings->is_enabled() || '' === $this->settings->endpoint() || ! $this->settings->event_enabled( $event ) ) {
			return null;
		}

		$body = (string) wp_json_encode( $this->build_payload( $event, $post_id, $old_status, $new_status ) );

		return wp_remote_post(
			$this->settings->endpoint(),
			array(
				'headers' => array(
					'Content-Type'         => 'application/json',
					self::EVENT_HEADER     => $event,
					self::SIGNATURE_HEADER => $this->sign( $body ),
				),
				'body'    => $body,
				'timeout' => 5,
			)
		);
	}
}

<?php
/**
 * Webhook configuration value object.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Webhook;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and sanitises the webhook settings stored under a single option.
 *
 * A small value object shared by the dispatcher, the subscriber, the CORS
 * handler and the settings page, so every surface agrees on the destination
 * endpoint, the shared secret, which events fire, and the allowed CORS origin.
 */
final class WebhookSettings {

	/**
	 * Option name holding the webhook configuration array.
	 */
	public const OPTION = 'signalboard_webhook';

	/**
	 * The events that may fire a webhook.
	 */
	public const EVENTS = array( 'status_changed', 'publish_state_changed' );

	/**
	 * Resolved settings (stored option merged over defaults).
	 *
	 * @var array<string, mixed>
	 */
	private array $data;

	/**
	 * Constructor: read the stored option, merged over the defaults.
	 */
	public function __construct() {
		$stored     = get_option( self::OPTION, array() );
		$this->data = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Default settings shape.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'enabled'     => false,
			'endpoint'    => '',
			'secret'      => '',
			'events'      => self::EVENTS,
			'cors_origin' => '',
		);
	}

	/**
	 * Whether webhook delivery is enabled.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return (bool) $this->data['enabled'];
	}

	/**
	 * Destination endpoint URL.
	 *
	 * @return string
	 */
	public function endpoint(): string {
		return (string) $this->data['endpoint'];
	}

	/**
	 * Shared signing secret.
	 *
	 * @return string
	 */
	public function secret(): string {
		return (string) $this->data['secret'];
	}

	/**
	 * Enabled event keys.
	 *
	 * @return string[]
	 */
	public function events(): array {
		return (array) $this->data['events'];
	}

	/**
	 * Whether a given event is enabled.
	 *
	 * @param string $event Event key.
	 * @return bool
	 */
	public function event_enabled( string $event ): bool {
		return in_array( $event, $this->events(), true );
	}

	/**
	 * Allowed CORS origin (the headless frontend's URL).
	 *
	 * @return string
	 */
	public function cors_origin(): string {
		return (string) $this->data['cors_origin'];
	}

	/**
	 * Sanitise raw settings-page input into the stored shape.
	 *
	 * @param array<string, mixed> $input Raw form input.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input ): array {
		$events = isset( $input['events'] ) && is_array( $input['events'] ) ? $input['events'] : array();

		return array(
			'enabled'     => ! empty( $input['enabled'] ),
			'endpoint'    => esc_url_raw( (string) ( $input['endpoint'] ?? '' ) ),
			'secret'      => sanitize_text_field( (string) ( $input['secret'] ?? '' ) ),
			'events'      => array_values( array_intersect( self::EVENTS, $events ) ),
			'cors_origin' => esc_url_raw( (string) ( $input['cors_origin'] ?? '' ) ),
		);
	}
}

<?php
/**
 * CORS headers for the headless frontend origin.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Webhook;

defined( 'ABSPATH' ) || exit;

/**
 * Emits Access-Control-Allow-Origin for the configured frontend on signalboard
 * REST routes.
 *
 * The headless demo (deployed on Vercel) calls the signalboard REST API from the
 * browser, cross-origin. This grants exactly one configured origin access to the
 * plugin's own routes — never a wildcard, and never other REST namespaces.
 */
final class CorsHeaders {

	/**
	 * Webhook settings (carries the allowed origin).
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
	 * Register the CORS filter.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'rest_pre_serve_request', array( $this, 'send_headers' ), 10, 4 );
	}

	/**
	 * Whether the given origin may access the given REST route.
	 *
	 * @param string $route  REST route (with or without a leading slash).
	 * @param string $origin Request Origin header value.
	 * @return bool
	 */
	public function allows( string $route, string $origin ): bool {
		$allowed = $this->settings->cors_origin();

		if ( '' === $allowed || $origin !== $allowed ) {
			return false;
		}

		return str_starts_with( ltrim( $route, '/' ), 'signalboard/' );
	}

	/**
	 * Send CORS headers on allowed signalboard requests.
	 *
	 * @param bool              $served  Whether the request was already served.
	 * @param mixed             $result  Response (unused).
	 * @param \WP_REST_Request  $request Current request.
	 * @param \WP_REST_Server   $server  REST server (unused).
	 * @return bool The unchanged $served value.
	 */
	public function send_headers( $served, $result, $request, $server ): bool {
		unset( $result, $server );

		$origin = is_object( $request ) ? (string) $request->get_header( 'origin' ) : '';
		$route  = is_object( $request ) ? (string) $request->get_route() : '';

		if ( '' !== $origin && $this->allows( $route, $origin ) ) {
			header( 'Access-Control-Allow-Origin: ' . $origin );
			header( 'Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS' );
			header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-Signalboard-Token' );
			header( 'Vary: Origin' );
		}

		return (bool) $served;
	}
}

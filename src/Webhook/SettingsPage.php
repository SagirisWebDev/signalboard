<?php
/**
 * Webhook settings admin page.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Webhook;

use Sagiris\Signalboard\Content\RequestPostType;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Settings-API page for configuring webhook delivery.
 *
 * Lets a site owner set the destination endpoint, the shared secret, which
 * events fire, and the allowed CORS origin. All persisted values pass through
 * {@see WebhookSettings::sanitize()}, so the settings page owns presentation and
 * the value object owns validation.
 */
final class SettingsPage {

	/**
	 * Settings group / page slug.
	 */
	private const GROUP = 'signalboard_webhook';

	/**
	 * Admin menu slug.
	 */
	private const MENU_SLUG = 'signalboard-webhooks';

	/**
	 * Capability required to manage webhook settings.
	 */
	private const CAPABILITY = 'manage_options';

	/**
	 * `admin_menu` callback: add the settings submenu.
	 *
	 * @return void
	 */
	public function register_page(): void {
		add_submenu_page(
			'edit.php?post_type=' . RequestPostType::POST_TYPE,
			__( 'Webhooks', 'signalboard' ),
			__( 'Webhooks', 'signalboard' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * `admin_init` callback: register the option, section and fields.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			self::GROUP,
			WebhookSettings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( WebhookSettings::class, 'sanitize' ),
				'default'           => WebhookSettings::defaults(),
			)
		);

		add_settings_section(
			'signalboard_webhook_main',
			__( 'Webhook delivery', 'signalboard' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Send a signed POST to a headless frontend when a request changes, for on-demand revalidation.', 'signalboard' ) . '</p>';
			},
			self::GROUP
		);

		$fields = array(
			'enabled'     => __( 'Enable webhooks', 'signalboard' ),
			'endpoint'    => __( 'Endpoint URL', 'signalboard' ),
			'secret'      => __( 'Shared secret', 'signalboard' ),
			'events'      => __( 'Trigger events', 'signalboard' ),
			'cors_origin' => __( 'Allowed CORS origin', 'signalboard' ),
		);

		foreach ( $fields as $key => $label ) {
			add_settings_field(
				'signalboard_webhook_' . $key,
				$label,
				array( $this, 'render_field' ),
				self::GROUP,
				'signalboard_webhook_main',
				array( 'key' => $key )
			);
		}
	}

	/**
	 * Render a single settings field.
	 *
	 * @param array{key: string} $args Field args.
	 * @return void
	 */
	public function render_field( array $args ): void {
		$settings = new WebhookSettings();
		$name     = WebhookSettings::OPTION;
		$key      = $args['key'];

		switch ( $key ) {
			case 'enabled':
				printf(
					'<label><input type="checkbox" name="%1$s[enabled]" value="1" %2$s /> %3$s</label>',
					esc_attr( $name ),
					checked( $settings->is_enabled(), true, false ),
					esc_html__( 'Deliver webhooks on request changes', 'signalboard' )
				);
				break;

			case 'events':
				foreach ( WebhookSettings::EVENTS as $event ) {
					printf(
						'<label style="display:block"><input type="checkbox" name="%1$s[events][]" value="%2$s" %3$s /> %2$s</label>',
						esc_attr( $name ),
						esc_attr( $event ),
						checked( $settings->event_enabled( $event ), true, false )
					);
				}
				break;

			case 'secret':
				printf(
					'<input type="password" class="regular-text" name="%1$s[secret]" value="%2$s" autocomplete="off" />',
					esc_attr( $name ),
					esc_attr( $settings->secret() )
				);
				break;

			default:
				$value = 'endpoint' === $key ? $settings->endpoint() : $settings->cors_origin();
				printf(
					'<input type="url" class="regular-text" name="%1$s[%2$s]" value="%3$s" placeholder="https://" />',
					esc_attr( $name ),
					esc_attr( $key ),
					esc_url( $value )
				);
				break;
		}
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage these settings.', 'signalboard' ) );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Signalboard Webhooks', 'signalboard' ) . '</h1>';
		echo '<form method="post" action="options.php">';
		settings_fields( self::GROUP );
		do_settings_sections( self::GROUP );
		submit_button();
		echo '</form>';
		echo '</div>';
	}
}

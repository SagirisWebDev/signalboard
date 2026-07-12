<?php
/**
 * Admin moderation queue screen and action dispatcher.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Moderation;

use Sagiris\Signalboard\Auth\AuthorizationPolicy;
use Sagiris\Signalboard\Content\RequestPostType;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the wp-admin moderation page and processes its actions.
 *
 * The screen is a thin controller: it renders {@see ModerationListTable} and
 * dispatches submitted actions to {@see ModerationService}. All mutating paths
 * flow through {@see self::process_action()}, which enforces the capability
 * ({@see AuthorizationPolicy::can_moderate()}) and a nonce before touching any
 * data — the one gate the UI cannot skip.
 */
final class ModerationScreen {

	/**
	 * Admin page slug.
	 */
	public const MENU_SLUG = 'signalboard-moderation';

	/**
	 * Nonce action shared by bulk actions and per-row links.
	 */
	public const NONCE_ACTION = 'signalboard_moderate';

	/**
	 * Moderation service.
	 *
	 * @var ModerationService
	 */
	private ModerationService $service;

	/**
	 * Authorization policy.
	 *
	 * @var AuthorizationPolicy
	 */
	private AuthorizationPolicy $policy;

	/**
	 * Constructor.
	 *
	 * @param ModerationService|null   $service Moderation service (defaults to a new instance).
	 * @param AuthorizationPolicy|null $policy  Authorization policy (defaults to a new instance).
	 */
	public function __construct( ?ModerationService $service = null, ?AuthorizationPolicy $policy = null ) {
		$this->service = $service ?? new ModerationService();
		$this->policy  = $policy ?? new AuthorizationPolicy();
	}

	/**
	 * `admin_menu` callback: register the queue under the request post type.
	 *
	 * @return void
	 */
	public function register(): void {
		add_submenu_page(
			'edit.php?post_type=' . RequestPostType::POST_TYPE,
			__( 'Moderation Queue', 'signalboard' ),
			__( 'Moderation', 'signalboard' ),
			$this->capability(),
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Dispatch a moderation action after enforcing capability + nonce.
	 *
	 * The single mutating entry point, kept free of `wp_die()`/redirects so it
	 * is unit-testable and reusable by both bulk actions and row links.
	 *
	 * @param string   $action Action slug: approve | reject | trash.
	 * @param int[]    $ids    Request IDs to act on.
	 * @param string   $nonce  Nonce to verify against {@see self::NONCE_ACTION}.
	 * @return array<int, true|WP_Error>|WP_Error Per-ID results, or a gate error.
	 */
	public function process_action( string $action, array $ids, string $nonce ): array|WP_Error {
		$user_id = get_current_user_id();

		if ( ! $this->policy->can_moderate( $user_id > 0 ? $user_id : null ) ) {
			return new WP_Error(
				'signalboard_moderation_forbidden',
				__( 'You are not allowed to moderate requests.', 'signalboard' ),
				array( 'status' => 403 )
			);
		}

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return new WP_Error(
				'signalboard_invalid_nonce',
				__( 'Security check failed. Please try again.', 'signalboard' ),
				array( 'status' => 400 )
			);
		}

		$results = array();

		foreach ( $ids as $id ) {
			$id             = (int) $id;
			$results[ $id ] = $this->dispatch( $action, $id );
		}

		return $results;
	}

	/**
	 * Route a single action to the moderation service.
	 *
	 * @param string $action Action slug.
	 * @param int    $id     Request ID.
	 * @return true|WP_Error
	 */
	private function dispatch( string $action, int $id ): bool|WP_Error {
		switch ( $action ) {
			case 'approve':
				return $this->service->approve( $id );
			case 'reject':
				return $this->service->reject( $id );
			case 'trash':
				return $this->service->trash( $id );
			default:
				return new WP_Error(
					'signalboard_unknown_action',
					__( 'Unknown moderation action.', 'signalboard' ),
					array( 'status' => 400 )
				);
		}
	}

	/**
	 * Render the moderation page (processing any submitted action first).
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'signalboard' ) );
		}

		$notice = $this->handle_submission();

		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		$table = new ModerationListTable();
		$table->prepare_items();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Moderation Queue', 'signalboard' ) . '</h1>';

		if ( '' !== $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
		}

		echo '<form method="post">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::MENU_SLUG ) . '" />';
		wp_nonce_field( self::NONCE_ACTION );
		$table->display();
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Process a submitted bulk action, row link, or status change.
	 *
	 * The nonce is verified up front, so every subsequent form-data read below
	 * is already authenticated. {@see self::process_action()} verifies it again
	 * as the reusable, independently-tested gate — belt and braces.
	 *
	 * @return string A human-readable result notice ('' when nothing was done).
	 */
	private function handle_submission(): string {
		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return '';
		}

		$request_action = isset( $_REQUEST['sb_action'] ) ? sanitize_key( wp_unslash( $_REQUEST['sb_action'] ) ) : '';

		// A per-row status change carries its own action + target slug.
		if ( 'set_status' === $request_action ) {
			$id     = isset( $_REQUEST['request'] ) ? absint( wp_unslash( $_REQUEST['request'] ) ) : 0;
			$slug   = isset( $_REQUEST['status_slug'] ) ? sanitize_title( wp_unslash( $_REQUEST['status_slug'] ) ) : '';
			$result = $this->service->set_status( $id, $slug );

			return is_wp_error( $result ) ? $result->get_error_message() : __( 'Status updated.', 'signalboard' );
		}

		// Otherwise resolve a bulk action (top or bottom selector) or a single-row link.
		$bulk = '';
		foreach ( array( 'action', 'action2' ) as $field ) {
			$value = isset( $_REQUEST[ $field ] ) ? sanitize_key( wp_unslash( $_REQUEST[ $field ] ) ) : '';
			if ( '' !== $value && '-1' !== $value ) {
				$bulk = $value;
				break;
			}
		}

		if ( '' === $bulk ) {
			$bulk = $request_action; // Single row link (approve/reject/trash).
		}

		$raw = isset( $_REQUEST['request'] ) ? wp_unslash( $_REQUEST['request'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cast to ints below.
		$ids = array_values( array_filter( array_map( 'absint', (array) $raw ) ) );

		if ( '' === $bulk || empty( $ids ) ) {
			return '';
		}

		$result = $this->process_action( $bulk, $ids, $nonce );

		return is_wp_error( $result ) ? $result->get_error_message() : $this->summarise( $bulk, $result );
	}

	/**
	 * Summarise a bulk result into a human-readable notice.
	 *
	 * @param string                   $action  Action slug.
	 * @param array<int, true|WP_Error> $results Per-ID results.
	 * @return string
	 */
	private function summarise( string $action, array $results ): string {
		$done = count( array_filter( $results, static fn( $r ) => true === $r ) );

		/* translators: 1: action slug, 2: number of requests affected. */
		return sprintf( __( 'Action "%1$s" applied to %2$d request(s).', 'signalboard' ), $action, $done );
	}

	/**
	 * The capability required to view/act on the queue.
	 *
	 * @return string
	 */
	private function capability(): string {
		return (string) apply_filters( 'signalboard_moderate_capability', AuthorizationPolicy::MODERATE_CAP );
	}
}

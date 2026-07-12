<?php
/**
 * Turns request changes into webhook dispatches.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Webhook;

use Sagiris\Signalboard\Content\RequestPostType;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Wires WordPress events to {@see WebhookDispatcher}.
 *
 * Two triggers, mirroring the two ways a request's public state changes:
 *  - a post-status transition (e.g. pending → publish on approval), and
 *  - a roadmap status-term change (e.g. planned → in-progress).
 *
 * The dispatcher decides whether to actually send (gated on settings), so this
 * class stays a thin translation layer that keeps the plugin loosely coupled
 * from the webhook mechanics.
 */
final class WebhookSubscriber {

	/**
	 * Webhook dispatcher.
	 *
	 * @var WebhookDispatcher
	 */
	private WebhookDispatcher $dispatcher;

	/**
	 * Constructor.
	 *
	 * @param WebhookDispatcher|null $dispatcher Dispatcher (defaults to a new instance).
	 */
	public function __construct( ?WebhookDispatcher $dispatcher = null ) {
		$this->dispatcher = $dispatcher ?? new WebhookDispatcher();
	}

	/**
	 * Register the WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
		add_action( 'set_object_terms', array( $this, 'on_status_terms' ), 10, 6 );
	}

	/**
	 * Dispatch on a meaningful publish-state change for a request.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Previous post status.
	 * @param WP_Post $post       The post transitioning.
	 * @return void
	 */
	public function on_transition( string $new_status, string $old_status, WP_Post $post ): void {
		if ( RequestPostType::POST_TYPE !== $post->post_type ) {
			return;
		}

		// Skip brand-new inserts and no-op saves — only real transitions count.
		if ( $new_status === $old_status || in_array( $old_status, array( 'new', 'auto-draft' ), true ) ) {
			return;
		}

		$this->dispatcher->dispatch( 'publish_state_changed', (int) $post->ID, $old_status, $new_status );
	}

	/**
	 * Dispatch on a roadmap status-term change for a request.
	 *
	 * @param int      $object_id  Object (post) ID.
	 * @param array    $terms      Terms passed to wp_set_object_terms (unused).
	 * @param array    $tt_ids     New term-taxonomy IDs.
	 * @param string   $taxonomy   Taxonomy name.
	 * @param bool     $append     Whether terms were appended (unused).
	 * @param array    $old_tt_ids Previous term-taxonomy IDs.
	 * @return void
	 */
	public function on_status_terms( int $object_id, array $terms, array $tt_ids, string $taxonomy, bool $append, array $old_tt_ids ): void {
		unset( $terms, $append );

		if ( RequestPostType::TAX_STATUS !== $taxonomy || RequestPostType::POST_TYPE !== get_post_type( $object_id ) ) {
			return;
		}

		$old = $this->first_slug( $old_tt_ids );
		$new = $this->first_slug( $tt_ids );

		if ( $old === $new ) {
			return;
		}

		$this->dispatcher->dispatch( 'status_changed', $object_id, $old, $new );
	}

	/**
	 * Resolve the first term-taxonomy ID in a list to its term slug.
	 *
	 * @param array<int, int|string> $tt_ids Term-taxonomy IDs.
	 * @return string|null Slug, or null when the list is empty.
	 */
	private function first_slug( array $tt_ids ): ?string {
		if ( empty( $tt_ids ) ) {
			return null;
		}

		$term = get_term_by( 'term_taxonomy_id', (int) reset( $tt_ids ), RequestPostType::TAX_STATUS );

		return $term ? (string) $term->slug : null;
	}
}

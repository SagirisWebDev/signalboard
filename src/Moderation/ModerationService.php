<?php
/**
 * Moderation state transitions for feedback requests.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Moderation;

use Sagiris\Signalboard\Auth\AuthorizationPolicy;
use Sagiris\Signalboard\Content\RequestPostType;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * The deep module beneath the admin moderation queue.
 *
 * Every state transition — approve, reject, trash, re-status — funnels through
 * here, guarded by {@see AuthorizationPolicy::can_moderate()} so the capability
 * rule lives in one place and the queue (or any future moderation surface)
 * cannot bypass it. Each action returns `true` on success or a `WP_Error`
 * carrying an HTTP-style status, so callers get a uniform, testable result.
 */
final class ModerationService {

	/**
	 * Authorization policy.
	 *
	 * @var AuthorizationPolicy
	 */
	private AuthorizationPolicy $policy;

	/**
	 * Constructor.
	 *
	 * @param AuthorizationPolicy|null $policy Authorization policy (defaults to a new instance).
	 */
	public function __construct( ?AuthorizationPolicy $policy = null ) {
		$this->policy = $policy ?? new AuthorizationPolicy();
	}

	/**
	 * Approve a request — publish it to the public board.
	 *
	 * @param int $id Request post ID.
	 * @return true|WP_Error
	 */
	public function approve( int $id ): bool|WP_Error {
		$error = $this->guard() ?? $this->ensure_request( $id );

		return $error ?? $this->set_post_status( $id, 'publish' );
	}

	/**
	 * Reject a request — return it to an unpublished (draft) state.
	 *
	 * @param int $id Request post ID.
	 * @return true|WP_Error
	 */
	public function reject( int $id ): bool|WP_Error {
		$error = $this->guard() ?? $this->ensure_request( $id );

		return $error ?? $this->set_post_status( $id, 'draft' );
	}

	/**
	 * Trash a request.
	 *
	 * @param int $id Request post ID.
	 * @return true|WP_Error
	 */
	public function trash( int $id ): bool|WP_Error {
		$error = $this->guard() ?? $this->ensure_request( $id );

		if ( $error ) {
			return $error;
		}

		if ( ! wp_trash_post( $id ) ) {
			return $this->failure( __( 'The request could not be trashed.', 'signalboard' ) );
		}

		return true;
	}

	/**
	 * Assign a roadmap status term to a request.
	 *
	 * @param int    $id          Request post ID.
	 * @param string $status_slug Status term slug (must already exist).
	 * @return true|WP_Error
	 */
	public function set_status( int $id, string $status_slug ): bool|WP_Error {
		$error = $this->guard() ?? $this->ensure_request( $id );

		if ( $error ) {
			return $error;
		}

		$slug = sanitize_title( $status_slug );

		if ( '' === $slug || ! term_exists( $slug, RequestPostType::TAX_STATUS ) ) {
			return new WP_Error(
				'signalboard_invalid_status',
				__( 'Unknown status.', 'signalboard' ),
				array( 'status' => 400 )
			);
		}

		$result = wp_set_object_terms( $id, $slug, RequestPostType::TAX_STATUS );

		if ( is_wp_error( $result ) ) {
			return $this->failure( $result->get_error_message() );
		}

		return true;
	}

	/**
	 * Capability guard shared by every action.
	 *
	 * @return WP_Error|null Error when the current user may not moderate, else null.
	 */
	private function guard(): ?WP_Error {
		$user_id = get_current_user_id();

		if ( $this->policy->can_moderate( $user_id > 0 ? $user_id : null ) ) {
			return null;
		}

		return new WP_Error(
			'signalboard_moderation_forbidden',
			__( 'You are not allowed to moderate requests.', 'signalboard' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Ensure the ID resolves to a feedback request post.
	 *
	 * @param int $id Candidate post ID.
	 * @return WP_Error|null Error when not a request, else null.
	 */
	private function ensure_request( int $id ): ?WP_Error {
		$post = get_post( $id );

		if ( $post instanceof WP_Post && RequestPostType::POST_TYPE === $post->post_type ) {
			return null;
		}

		return new WP_Error(
			'signalboard_request_not_found',
			__( 'Feedback request not found.', 'signalboard' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Update a request's post status.
	 *
	 * @param int    $id     Request post ID.
	 * @param string $status Target post status.
	 * @return true|WP_Error
	 */
	private function set_post_status( int $id, string $status ): bool|WP_Error {
		$result = wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => $status,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $this->failure( $result->get_error_message() );
		}

		return true;
	}

	/**
	 * Shared internal-failure error.
	 *
	 * @param string $message Human-readable message.
	 * @return WP_Error
	 */
	private function failure( string $message ): WP_Error {
		return new WP_Error( 'signalboard_moderation_failed', $message, array( 'status' => 500 ) );
	}
}

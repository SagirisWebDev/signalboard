<?php
/**
 * Authorization policy for voting and (later) submission.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Central authorization seam shared by the REST and GraphQL surfaces.
 *
 * Keeping the decision here (rather than inline in each controller) means the
 * two APIs enforce identical rules and later slices — authenticated submission,
 * moderation — extend one place. In v1, upvoting is open to anonymous visitors
 * (dedup + rate-limiting provide the abuse controls).
 */
final class AuthorizationPolicy {

	/**
	 * Default capability required to moderate feedback requests.
	 *
	 * `edit_others_posts` maps to Editors and Administrators out of the box —
	 * the roles a site owner would trust to approve, reject and re-status
	 * community submissions. Filterable via `signalboard_moderate_capability`.
	 */
	public const MODERATE_CAP = 'edit_others_posts';

	/**
	 * Whether the current actor may upvote.
	 *
	 * @param int|null $user_id Optional user ID (null = anonymous visitor).
	 * @return bool
	 */
	public function can_upvote( ?int $user_id = null ): bool {
		unset( $user_id );

		return true;
	}

	/**
	 * Whether the current actor may submit a new feedback request.
	 *
	 * Submission requires an authenticated user — anonymous visitors are
	 * rejected here so the REST route and the GraphQL mutation enforce one rule.
	 *
	 * @param int|null $user_id Optional user ID (null = anonymous visitor).
	 * @return bool
	 */
	public function can_submit( ?int $user_id = null ): bool {
		return null !== $user_id && $user_id > 0;
	}

	/**
	 * Whether the given user may moderate feedback requests.
	 *
	 * Unlike upvoting (open) and submitting (any logged-in user), moderation is
	 * a capability check — the same rule the admin queue and any future
	 * moderation surface enforce. The required capability is filterable so a
	 * site can widen or narrow who moderates without touching this class.
	 *
	 * @param int|null $user_id Optional user ID (null = anonymous visitor).
	 * @return bool
	 */
	public function can_moderate( ?int $user_id = null ): bool {
		if ( null === $user_id || $user_id <= 0 ) {
			return false;
		}

		/**
		 * Filter the capability required to moderate feedback requests.
		 *
		 * @param string $capability Capability slug. Default 'edit_others_posts'.
		 */
		$capability = (string) apply_filters( 'signalboard_moderate_capability', self::MODERATE_CAP );

		return user_can( $user_id, $capability );
	}
}

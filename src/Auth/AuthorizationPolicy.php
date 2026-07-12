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
}

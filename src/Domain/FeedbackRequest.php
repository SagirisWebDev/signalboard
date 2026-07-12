<?php
/**
 * Feedback request data transfer object.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable, delivery-agnostic representation of a feedback request.
 *
 * Every surface (REST, GraphQL, block) consumes this same shape so the
 * representations cannot drift.
 */
final class FeedbackRequest {

	/**
	 * Constructor.
	 *
	 * @param int         $id          Post ID.
	 * @param string      $title       Request title.
	 * @param string      $slug        Post slug.
	 * @param string      $content     Request body (raw).
	 * @param string|null $status      Status term slug, if any.
	 * @param string|null $status_label Status term name, if any.
	 * @param string|null $board       Board term slug, if any.
	 * @param string|null $board_label Board term name, if any.
	 * @param int         $vote_count  Cached upvote count.
	 * @param string      $created_at  ISO-8601 (UTC) creation timestamp.
	 * @param string      $author_name Author display name.
	 * @param string      $moderation_status Publication state ('publish' when live on the
	 *                                       public board, 'pending' while awaiting moderation).
	 *                                       Defaults to 'publish' so every read path that only
	 *                                       ever surfaces live requests keeps its existing shape.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $title,
		public readonly string $slug,
		public readonly string $content,
		public readonly ?string $status,
		public readonly ?string $status_label,
		public readonly ?string $board,
		public readonly ?string $board_label,
		public readonly int $vote_count,
		public readonly string $created_at,
		public readonly string $author_name,
		public readonly string $moderation_status = 'publish'
	) {}

	/**
	 * Represent the request as a plain array for API responses.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'               => $this->id,
			'title'            => $this->title,
			'slug'             => $this->slug,
			'content'          => $this->content,
			'status'           => $this->status,
			'statusLabel'      => $this->status_label,
			'board'            => $this->board,
			'boardLabel'       => $this->board_label,
			'voteCount'        => $this->vote_count,
			'createdAt'        => $this->created_at,
			'authorName'       => $this->author_name,
			'moderationStatus' => $this->moderation_status,
		);
	}
}

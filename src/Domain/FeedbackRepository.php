<?php
/**
 * Feedback request read model.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Domain;

use Sagiris\Signalboard\Content\RequestPostType;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Reads feedback requests and maps them to {@see FeedbackRequest} objects.
 *
 * This is the single data layer beneath the REST controller, the GraphQL
 * resolvers, and the block, so all surfaces read identical data.
 */
final class FeedbackRepository {

	/**
	 * Query a page of published feedback requests.
	 *
	 * Recognised filter keys:
	 *  - status   (string) status term slug.
	 *  - board    (string) board term slug.
	 *  - search   (string) free-text search.
	 *  - orderby  (string) one of 'date', 'votes', 'title'. Default 'date'.
	 *  - order    (string) 'asc' or 'desc'. Default 'desc'.
	 *  - page     (int)    1-based page number. Default 1.
	 *  - per_page (int)    page size (1-100). Default 10.
	 *
	 * @param array<string, mixed> $filters Filter/sort/paging options.
	 * @return array{items: FeedbackRequest[], total: int, total_pages: int}
	 */
	public function list( array $filters = array() ): array {
		$page     = max( 1, (int) ( $filters['page'] ?? 1 ) );
		$per_page = (int) ( $filters['per_page'] ?? 10 );
		$per_page = max( 1, min( 100, $per_page ) );
		$order    = strtoupper( (string) ( $filters['order'] ?? 'desc' ) );
		$order    = in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : 'DESC';

		$query_args = array(
			'post_type'      => RequestPostType::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'order'          => $order,
			'no_found_rows'  => false,
		);

		$this->apply_ordering( $query_args, (string) ( $filters['orderby'] ?? 'date' ) );
		$this->apply_taxonomies( $query_args, $filters );

		if ( ! empty( $filters['search'] ) ) {
			$query_args['s'] = (string) $filters['search'];
		}

		$query = new WP_Query( $query_args );

		$items = array_map(
			array( $this, 'map_post' ),
			$query->posts
		);

		return array(
			'items'       => $items,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
		);
	}

	/**
	 * Fetch a single published feedback request by ID or slug.
	 *
	 * @param int|string $id_or_slug Numeric post ID or post slug.
	 * @return FeedbackRequest|null The request, or null when not found/unpublished.
	 */
	public function get( int|string $id_or_slug ): ?FeedbackRequest {
		$post = null;

		if ( is_numeric( $id_or_slug ) ) {
			$candidate = get_post( (int) $id_or_slug );
			if ( $candidate instanceof WP_Post && RequestPostType::POST_TYPE === $candidate->post_type ) {
				$post = $candidate;
			}
		} else {
			$query = new WP_Query(
				array(
					'post_type'      => RequestPostType::POST_TYPE,
					'name'           => sanitize_title( (string) $id_or_slug ),
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'no_found_rows'  => true,
				)
			);
			$post  = $query->posts[0] ?? null;
		}

		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return null;
		}

		return $this->map_post( $post );
	}

	/**
	 * Translate an "orderby" filter into WP_Query arguments.
	 *
	 * @param array<string, mixed> $query_args Query args, passed by reference.
	 * @param string               $orderby    Requested sort key.
	 * @return void
	 */
	private function apply_ordering( array &$query_args, string $orderby ): void {
		switch ( $orderby ) {
			case 'votes':
				$query_args['orderby']  = 'meta_value_num';
				$query_args['meta_key'] = RequestPostType::META_VOTE_COUNT; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				break;
			case 'title':
				$query_args['orderby'] = 'title';
				break;
			case 'date':
			default:
				$query_args['orderby'] = 'date';
				break;
		}
	}

	/**
	 * Apply status/board taxonomy filters to the query.
	 *
	 * @param array<string, mixed> $query_args Query args, passed by reference.
	 * @param array<string, mixed> $filters    Incoming filters.
	 * @return void
	 */
	private function apply_taxonomies( array &$query_args, array $filters ): void {
		$tax_query = array();

		if ( ! empty( $filters['status'] ) ) {
			$tax_query[] = array(
				'taxonomy' => RequestPostType::TAX_STATUS,
				'field'    => 'slug',
				'terms'    => sanitize_title( (string) $filters['status'] ),
			);
		}

		if ( ! empty( $filters['board'] ) ) {
			$tax_query[] = array(
				'taxonomy' => RequestPostType::TAX_BOARD,
				'field'    => 'slug',
				'terms'    => sanitize_title( (string) $filters['board'] ),
			);
		}

		if ( count( $tax_query ) > 1 ) {
			$tax_query['relation'] = 'AND';
		}

		if ( ! empty( $tax_query ) ) {
			$query_args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}
	}

	/**
	 * Map a WordPress post to a {@see FeedbackRequest}.
	 *
	 * @param WP_Post $post Source post.
	 * @return FeedbackRequest
	 */
	private function map_post( WP_Post $post ): FeedbackRequest {
		$status = $this->first_term( $post->ID, RequestPostType::TAX_STATUS );
		$board  = $this->first_term( $post->ID, RequestPostType::TAX_BOARD );

		return new FeedbackRequest(
			id: $post->ID,
			title: get_the_title( $post ),
			slug: $post->post_name,
			content: $post->post_content,
			status: $status['slug'],
			status_label: $status['name'],
			board: $board['slug'],
			board_label: $board['name'],
			vote_count: (int) get_post_meta( $post->ID, RequestPostType::META_VOTE_COUNT, true ),
			created_at: (string) get_post_time( 'c', true, $post ),
			author_name: (string) get_the_author_meta( 'display_name', (int) $post->post_author )
		);
	}

	/**
	 * Return the first assigned term's slug and name for a taxonomy.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return array{slug: string|null, name: string|null}
	 */
	private function first_term( int $post_id, string $taxonomy ): array {
		$terms = get_the_terms( $post_id, $taxonomy );

		if ( is_array( $terms ) && ! empty( $terms ) ) {
			return array(
				'slug' => $terms[0]->slug,
				'name' => $terms[0]->name,
			);
		}

		return array(
			'slug' => null,
			'name' => null,
		);
	}
}

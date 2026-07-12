<?php
/**
 * Feedback request content model.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the feedback request post type, its taxonomies, and meta.
 */
final class RequestPostType {

	public const POST_TYPE       = 'signalboard_request';
	public const TAX_STATUS      = 'signalboard_status';
	public const TAX_BOARD       = 'signalboard_board';
	public const META_VOTE_COUNT = '_signalboard_vote_count';

	/**
	 * Default status terms, seeded on activation.
	 *
	 * Keyed by slug, valued by human-readable label.
	 *
	 * @return array<string, string>
	 */
	public static function default_statuses(): array {
		return array(
			'open'         => 'Open',
			'under-review' => 'Under Review',
			'planned'      => 'Planned',
			'in-progress'  => 'In Progress',
			'complete'     => 'Complete',
			'declined'     => 'Declined',
		);
	}

	/**
	 * Register the post type, taxonomies, and meta.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->register_post_type();
		$this->register_taxonomies();
		$this->register_meta();
	}

	/**
	 * Register the feedback request post type.
	 *
	 * @return void
	 */
	private function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Feedback Requests', 'signalboard' ),
					'singular_name' => __( 'Feedback Request', 'signalboard' ),
					'menu_name'     => __( 'Signalboard', 'signalboard' ),
					'add_new_item'  => __( 'Add New Request', 'signalboard' ),
					'edit_item'     => __( 'Edit Request', 'signalboard' ),
					'search_items'  => __( 'Search Requests', 'signalboard' ),
				),
				'public'              => true,
				'show_in_rest'        => true,
				'menu_icon'           => 'dashicons-megaphone',
				'supports'            => array( 'title', 'editor', 'author', 'custom-fields' ),
				'has_archive'         => false,
				'rewrite'             => array( 'slug' => 'signalboard' ),
				'show_in_graphql'     => true,
				'graphql_single_name' => 'SignalboardRequest',
				'graphql_plural_name' => 'SignalboardRequests',
			)
		);
	}

	/**
	 * Register the status and board taxonomies.
	 *
	 * @return void
	 */
	private function register_taxonomies(): void {
		register_taxonomy(
			self::TAX_STATUS,
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Statuses', 'signalboard' ),
					'singular_name' => __( 'Status', 'signalboard' ),
				),
				'public'              => true,
				'hierarchical'        => false,
				'show_admin_column'   => true,
				'show_in_rest'        => true,
				'show_in_graphql'     => true,
				'graphql_single_name' => 'RequestStatus',
				'graphql_plural_name' => 'RequestStatuses',
			)
		);

		register_taxonomy(
			self::TAX_BOARD,
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Boards', 'signalboard' ),
					'singular_name' => __( 'Board', 'signalboard' ),
				),
				'public'              => true,
				'hierarchical'        => true,
				'show_admin_column'   => true,
				'show_in_rest'        => true,
				'show_in_graphql'     => true,
				'graphql_single_name' => 'RequestBoard',
				'graphql_plural_name' => 'RequestBoards',
			)
		);
	}

	/**
	 * Register post meta shared across delivery surfaces.
	 *
	 * The vote-count cache is maintained by the voting slice; it is registered
	 * here so ordering by popularity works from day one (defaulting to zero).
	 *
	 * @return void
	 */
	private function register_meta(): void {
		register_post_meta(
			self::POST_TYPE,
			self::META_VOTE_COUNT,
			array(
				'type'         => 'integer',
				'description'  => __( 'Cached upvote count.', 'signalboard' ),
				'single'       => true,
				'default'      => 0,
				'show_in_rest' => false,
			)
		);
	}

	/**
	 * Seed the default status terms if they do not yet exist.
	 *
	 * @return void
	 */
	public function seed_default_statuses(): void {
		foreach ( self::default_statuses() as $slug => $label ) {
			if ( term_exists( $slug, self::TAX_STATUS ) ) {
				continue;
			}

			wp_insert_term( $label, self::TAX_STATUS, array( 'slug' => $slug ) );
		}
	}
}

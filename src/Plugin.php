<?php
/**
 * Plugin orchestrator.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard;

use Sagiris\Signalboard\Content\RequestPostType;
use Sagiris\Signalboard\Domain\FeedbackRepository;
use Sagiris\Signalboard\GraphQL\RequestsGraphQL;
use Sagiris\Signalboard\Rest\RequestsController;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin's building blocks into WordPress.
 */
final class Plugin {

	/**
	 * Content model (custom post type, taxonomies, meta).
	 *
	 * @var RequestPostType
	 */
	private RequestPostType $post_type;

	/**
	 * Read model shared by every delivery surface.
	 *
	 * @var FeedbackRepository
	 */
	private FeedbackRepository $repository;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->post_type  = new RequestPostType();
		$this->repository = new FeedbackRepository();
	}

	/**
	 * Register all runtime hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this->post_type, 'register' ) );

		$rest = new RequestsController( $this->repository );
		add_action( 'rest_api_init', array( $rest, 'register_routes' ) );

		$graphql = new RequestsGraphQL();
		add_action( 'graphql_register_types', array( $graphql, 'register' ) );
	}

	/**
	 * Activation callback: register content types, seed statuses, flush rewrites.
	 *
	 * @return void
	 */
	public function activate(): void {
		$this->post_type->register();
		$this->post_type->seed_default_statuses();
		flush_rewrite_rules();
	}

	/**
	 * Deactivation callback.
	 *
	 * @return void
	 */
	public function deactivate(): void {
		flush_rewrite_rules();
	}

	/**
	 * Expose the read model (useful for tests and later slices).
	 *
	 * @return FeedbackRepository
	 */
	public function repository(): FeedbackRepository {
		return $this->repository;
	}
}

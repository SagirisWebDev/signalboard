<?php
/**
 * Plugin orchestrator.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard;

use Sagiris\Signalboard\Block\BoardBlock;
use Sagiris\Signalboard\Content\RequestPostType;
use Sagiris\Signalboard\Domain\FeedbackRepository;
use Sagiris\Signalboard\GraphQL\RequestsGraphQL;
use Sagiris\Signalboard\Rest\RequestsController;
use Sagiris\Signalboard\Voting\VotesTable;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin's building blocks into WordPress.
 */
final class Plugin {

	/**
	 * Option storing the installed custom-table schema version.
	 */
	private const DB_VERSION_OPTION = 'signalboard_db_version';

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
		add_action( 'init', array( $this, 'maybe_upgrade_database' ) );

		$rest = new RequestsController( $this->repository );
		add_action( 'rest_api_init', array( $rest, 'register_routes' ) );

		$graphql = new RequestsGraphQL();
		add_action( 'graphql_register_types', array( $graphql, 'register' ) );

		$board = new BoardBlock();
		add_action( 'init', array( $board, 'register' ) );
	}

	/**
	 * Activation callback: register content types, seed statuses, flush rewrites.
	 *
	 * @return void
	 */
	public function activate(): void {
		$this->post_type->register();
		$this->post_type->seed_default_statuses();
		VotesTable::install();
		update_option( self::DB_VERSION_OPTION, VotesTable::VERSION );
		flush_rewrite_rules();
	}

	/**
	 * Ensure the votes table exists and is current on already-active sites.
	 *
	 * The activation hook only fires on (re)activation, so a plugin updated in
	 * place needs a version-gated check to create/upgrade the custom table.
	 *
	 * @return void
	 */
	public function maybe_upgrade_database(): void {
		if ( get_option( self::DB_VERSION_OPTION ) === VotesTable::VERSION ) {
			return;
		}

		VotesTable::install();
		update_option( self::DB_VERSION_OPTION, VotesTable::VERSION );
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

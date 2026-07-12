<?php
/**
 * Plugin orchestrator.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard;

use Sagiris\Signalboard\Auth\AuthTokenService;
use Sagiris\Signalboard\Auth\TokenAuthenticator;
use Sagiris\Signalboard\Block\BoardBlock;
use Sagiris\Signalboard\Content\RequestPostType;
use Sagiris\Signalboard\Domain\FeedbackRepository;
use Sagiris\Signalboard\GraphQL\AuthGraphQL;
use Sagiris\Signalboard\GraphQL\RequestsGraphQL;
use Sagiris\Signalboard\Moderation\ModerationScreen;
use Sagiris\Signalboard\Rest\AuthController;
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

		// Resolve a bearer JWT to the current user for every surface (REST + GraphQL).
		// The token service is built lazily inside these callbacks — never here at
		// load time — because it may touch pluggable functions (wp_generate_password)
		// that WordPress does not define until after plugins load.
		add_filter( 'determine_current_user', array( $this, 'authenticate_bearer_token' ), 30 );

		$rest = new RequestsController( $this->repository );
		add_action( 'rest_api_init', array( $rest, 'register_routes' ) );
		add_action( 'rest_api_init', array( $this, 'register_auth_routes' ) );

		$graphql = new RequestsGraphQL();
		add_action( 'graphql_register_types', array( $graphql, 'register' ) );
		add_action( 'graphql_register_types', array( $this, 'register_auth_graphql' ) );

		$board = new BoardBlock();
		add_action( 'init', array( $board, 'register' ) );

		if ( is_admin() ) {
			$moderation = new ModerationScreen();
			add_action( 'admin_menu', array( $moderation, 'register' ) );
		}
	}

	/**
	 * `determine_current_user` callback: resolve a bearer JWT to its user.
	 *
	 * @param int|false $user_id The user id determined so far.
	 * @return int|false
	 */
	public function authenticate_bearer_token( $user_id ) {
		$authenticator = new TokenAuthenticator( AuthTokenService::create() );

		return $authenticator->resolve( $user_id );
	}

	/**
	 * `rest_api_init` callback: register the JWT auth routes.
	 *
	 * @return void
	 */
	public function register_auth_routes(): void {
		( new AuthController( AuthTokenService::create() ) )->register_routes();
	}

	/**
	 * `graphql_register_types` callback: register the JWT auth mutations.
	 *
	 * @return void
	 */
	public function register_auth_graphql(): void {
		( new AuthGraphQL() )->register();
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
		AuthTokenService::secret();
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

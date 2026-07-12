<?php
/**
 * REST controller for feedback requests.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Rest;

use Sagiris\Signalboard\Auth\AuthorizationPolicy;
use Sagiris\Signalboard\Domain\FeedbackRepository;
use Sagiris\Signalboard\Voting\RateLimiter;
use Sagiris\Signalboard\Voting\VoterFingerprint;
use Sagiris\Signalboard\Voting\VoteService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes read and voting routes for feedback requests under signalboard/v1.
 */
final class RequestsController {

	private const NAMESPACE = 'signalboard/v1';
	private const REST_BASE = 'requests';

	/**
	 * Default votes permitted per client IP within the rate-limit window.
	 */
	private const VOTE_LIMIT = 20;

	/**
	 * Default rate-limit window, in seconds.
	 */
	private const VOTE_WINDOW = 60;

	/**
	 * Read model.
	 *
	 * @var FeedbackRepository
	 */
	private FeedbackRepository $repository;

	/**
	 * Vote write/read service.
	 *
	 * @var VoteService
	 */
	private VoteService $votes;

	/**
	 * Per-IP rate limiter guarding the vote route.
	 *
	 * @var RateLimiter
	 */
	private RateLimiter $rate_limiter;

	/**
	 * Authorization policy.
	 *
	 * @var AuthorizationPolicy
	 */
	private AuthorizationPolicy $policy;

	/**
	 * Constructor.
	 *
	 * @param FeedbackRepository       $repository   Read model.
	 * @param VoteService|null         $votes        Vote service (defaults to a new instance).
	 * @param RateLimiter|null         $rate_limiter Vote rate limiter (defaults to per-IP 20/min).
	 * @param AuthorizationPolicy|null $policy       Authorization policy (defaults to a new instance).
	 */
	public function __construct(
		FeedbackRepository $repository,
		?VoteService $votes = null,
		?RateLimiter $rate_limiter = null,
		?AuthorizationPolicy $policy = null
	) {
		$this->repository   = $repository;
		$this->votes        = $votes ?? new VoteService();
		$this->rate_limiter = $rate_limiter ?? new RateLimiter( self::VOTE_LIMIT, self::VOTE_WINDOW, 'signalboard_vote' );
		$this->policy       = $policy ?? new AuthorizationPolicy();
	}

	/**
	 * Register the collection and single-item routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => '__return_true',
					'args'                => $this->collection_args(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'submit_permissions_check' ),
					'args'                => $this->submission_args(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/mine',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_my_items' ),
					'permission_callback' => array( $this, 'submit_permissions_check' ),
					'args'                => $this->mine_args(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'id' => $this->id_arg(),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/(?P<id>\d+)/vote',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_vote' ),
					'permission_callback' => array( $this, 'vote_permissions_check' ),
					'args'                => array(
						'id' => $this->id_arg(),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_vote' ),
					'permission_callback' => array( $this, 'vote_permissions_check' ),
					'args'                => array(
						'id' => $this->id_arg(),
					),
				),
			)
		);
	}

	/**
	 * Permission check for the vote routes.
	 *
	 * @return bool
	 */
	public function vote_permissions_check(): bool {
		$user_id = get_current_user_id();

		return $this->policy->can_upvote( $user_id > 0 ? $user_id : null );
	}

	/**
	 * Permission check for the authenticated submission routes.
	 *
	 * Delegates to the shared policy so REST and GraphQL enforce the same rule:
	 * a real, logged-in user is required. Anonymous callers fail here and core
	 * returns 401.
	 *
	 * @return bool
	 */
	public function submit_permissions_check(): bool {
		$user_id = get_current_user_id();

		return $this->policy->can_submit( $user_id > 0 ? $user_id : null );
	}

	/**
	 * Create a feedback request in the pending (moderation) state.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( WP_REST_Request $request ) {
		$result = $this->repository->create(
			array(
				'title'     => $request->get_param( 'title' ),
				'content'   => $request->get_param( 'content' ),
				'board'     => $request->get_param( 'board' ),
				'author_id' => get_current_user_id(),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result->to_array(), 201 );
	}

	/**
	 * List the current user's own submissions, across moderation states.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function get_my_items( WP_REST_Request $request ): WP_REST_Response {
		$result = $this->repository->list_by_author(
			get_current_user_id(),
			array(
				'page'     => $request->get_param( 'page' ),
				'per_page' => $request->get_param( 'per_page' ),
			)
		);

		$data = array_map(
			static fn( $item ) => $item->to_array(),
			$result['items']
		);

		$response = new WP_REST_Response( $data );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) $result['total_pages'] );

		return $response;
	}

	/**
	 * Cast a vote for a request.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_vote( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		if ( null === $this->repository->get( $id ) ) {
			return $this->not_found();
		}

		$fingerprint = VoterFingerprint::fromRequest( $request );

		if ( ! $this->rate_limiter->allow( 'ip_' . $this->client_ip() ) ) {
			return new WP_Error(
				'signalboard_rate_limited',
				__( 'Too many votes in a short time. Please slow down.', 'signalboard' ),
				array( 'status' => 429 )
			);
		}

		$result = $this->votes->cast_vote( $id, $fingerprint );

		return new WP_REST_Response(
			array(
				'id'        => $id,
				'voteCount' => $result['count'],
				'voted'     => true,
			)
		);
	}

	/**
	 * Retract a vote for a request.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_vote( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		if ( null === $this->repository->get( $id ) ) {
			return $this->not_found();
		}

		$fingerprint = VoterFingerprint::fromRequest( $request );
		$result      = $this->votes->retract_vote( $id, $fingerprint );

		return new WP_REST_Response(
			array(
				'id'        => $id,
				'voteCount' => $result['count'],
				'voted'     => false,
			)
		);
	}

	/**
	 * Shared "request not found" error.
	 *
	 * @return WP_Error
	 */
	private function not_found(): WP_Error {
		return new WP_Error(
			'signalboard_request_not_found',
			__( 'Feedback request not found.', 'signalboard' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Best-effort client IP for the rate-limit bucket.
	 *
	 * @return string
	 */
	private function client_ip(): string {
		if ( ! isset( $_SERVER['REMOTE_ADDR'] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
	}

	/**
	 * Shared argument schema for the `id` path parameter.
	 *
	 * @return array<string, mixed>
	 */
	private function id_arg(): array {
		return array(
			'description'       => __( 'Unique identifier for the request.', 'signalboard' ),
			'type'              => 'integer',
			'required'          => true,
			'sanitize_callback' => 'absint',
		);
	}

	/**
	 * Handle a collection request.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function get_items( WP_REST_Request $request ): WP_REST_Response {
		$result = $this->repository->list(
			array(
				'status'   => $request->get_param( 'status' ),
				'board'    => $request->get_param( 'board' ),
				'search'   => $request->get_param( 'search' ),
				'orderby'  => $request->get_param( 'orderby' ),
				'order'    => $request->get_param( 'order' ),
				'page'     => $request->get_param( 'page' ),
				'per_page' => $request->get_param( 'per_page' ),
			)
		);

		$data = array_map(
			static fn( $item ) => $item->to_array(),
			$result['items']
		);

		$response = new WP_REST_Response( $data );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) $result['total_pages'] );

		return $response;
	}

	/**
	 * Handle a single-item request.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( WP_REST_Request $request ) {
		$item = $this->repository->get( (int) $request->get_param( 'id' ) );

		if ( null === $item ) {
			return new WP_Error(
				'signalboard_request_not_found',
				__( 'Feedback request not found.', 'signalboard' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( $item->to_array() );
	}

	/**
	 * Argument schema for the authenticated submission (POST) route.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function submission_args(): array {
		return array(
			'title'   => array(
				'description'       => __( 'Title of the feedback request.', 'signalboard' ),
				'type'              => 'string',
				'required'          => true,
				'minLength'         => 1,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => static function ( $value ): bool {
					return is_string( $value ) && '' !== trim( $value );
				},
			),
			'content' => array(
				'description'       => __( 'Body of the feedback request.', 'signalboard' ),
				'type'              => 'string',
				'sanitize_callback' => 'wp_kses_post',
			),
			'board'   => array(
				'description'       => __( 'Board term slug to file the request under.', 'signalboard' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_title',
			),
		);
	}

	/**
	 * Argument schema for the "my submissions" route.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function mine_args(): array {
		return array(
			'page'     => array(
				'description'       => __( 'Current page of the collection.', 'signalboard' ),
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page' => array(
				'description'       => __( 'Maximum number of items per page.', 'signalboard' ),
				'type'              => 'integer',
				'default'           => 10,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * Argument schema for the collection route.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function collection_args(): array {
		return array(
			'status'   => array(
				'description'       => __( 'Limit results to a status slug.', 'signalboard' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_title',
			),
			'board'    => array(
				'description'       => __( 'Limit results to a board slug.', 'signalboard' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_title',
			),
			'search'   => array(
				'description'       => __( 'Free-text search query.', 'signalboard' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'orderby'  => array(
				'description' => __( 'Sort collection by attribute.', 'signalboard' ),
				'type'        => 'string',
				'default'     => 'date',
				'enum'        => array( 'date', 'votes', 'title' ),
			),
			'order'    => array(
				'description' => __( 'Sort direction.', 'signalboard' ),
				'type'        => 'string',
				'default'     => 'desc',
				'enum'        => array( 'asc', 'desc' ),
			),
			'page'     => array(
				'description'       => __( 'Current page of the collection.', 'signalboard' ),
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page' => array(
				'description'       => __( 'Maximum number of items per page.', 'signalboard' ),
				'type'              => 'integer',
				'default'           => 10,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			),
		);
	}
}

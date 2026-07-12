<?php
/**
 * REST controller for feedback requests.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Rest;

use Sagiris\Signalboard\Domain\FeedbackRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes read routes for feedback requests under the signalboard/v1 namespace.
 */
final class RequestsController {

	private const NAMESPACE = 'signalboard/v1';
	private const REST_BASE = 'requests';

	/**
	 * Read model.
	 *
	 * @var FeedbackRepository
	 */
	private FeedbackRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param FeedbackRepository $repository Read model.
	 */
	public function __construct( FeedbackRepository $repository ) {
		$this->repository = $repository;
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
						'id' => array(
							'description'       => __( 'Unique identifier for the request.', 'signalboard' ),
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
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

<?php
/**
 * Tests for the REST controller routes and responses.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Tests;

use Sagiris\Signalboard\Content\RequestPostType;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @covers \Sagiris\Signalboard\Rest\RequestsController
 */
final class RequestsControllerTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		// Ensure the REST server is booted and routes registered for this test.
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function make_request( string $status = 'publish', string $title = 'A request' ): int {
		return self::factory()->post->create(
			array(
				'post_type'   => RequestPostType::POST_TYPE,
				'post_status' => $status,
				'post_title'  => $title,
			)
		);
	}

	public function test_collection_route_is_registered(): void {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/signalboard/v1/requests', $routes );
	}

	public function test_single_item_route_is_registered(): void {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/signalboard/v1/requests/(?P<id>\d+)', $routes );
	}

	public function test_get_collection_returns_published_items_with_headers(): void {
		$this->make_request( 'publish', 'Live one' );
		$this->make_request( 'draft', 'Hidden one' );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/signalboard/v1/requests' ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertCount( 1, $data );
		$this->assertSame( 'Live one', $data[0]['title'] );

		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'X-WP-Total', $headers );
		$this->assertArrayHasKey( 'X-WP-TotalPages', $headers );
		$this->assertSame( '1', (string) $headers['X-WP-Total'] );
		$this->assertSame( '1', (string) $headers['X-WP-TotalPages'] );
	}

	public function test_get_single_valid_id_returns_item(): void {
		$id = $this->make_request( 'publish', 'Fetch single' );

		$response = rest_do_request( new WP_REST_Request( 'GET', "/signalboard/v1/requests/{$id}" ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( $id, $data['id'] );
		$this->assertSame( 'Fetch single', $data['title'] );
	}

	public function test_get_nonexistent_id_returns_404_with_code(): void {
		$response = rest_do_request( new WP_REST_Request( 'GET', '/signalboard/v1/requests/99999999' ) );

		$this->assertSame( 404, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'signalboard_request_not_found', $data['code'] );
	}
}

<?php
/**
 * Tests for the authenticated submission and my-submissions REST routes.
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
final class RequestSubmissionTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function post_request( array $body ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/signalboard/v1/requests' );
		foreach ( $body as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_do_request( $request );
	}

	public function test_submission_route_is_registered(): void {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/signalboard/v1/requests/mine', $routes );
	}

	public function test_anonymous_submission_is_rejected(): void {
		wp_set_current_user( 0 );

		$response = $this->post_request( array( 'title' => 'Sneaky anon' ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertCount(
			0,
			get_posts(
				array(
					'post_type'   => RequestPostType::POST_TYPE,
					'post_status' => 'any',
				)
			)
		);
	}

	public function test_authenticated_user_submits_a_pending_request(): void {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user );

		$response = $this->post_request(
			array(
				'title'   => 'Add webhooks',
				'content' => 'Please add outbound webhooks.',
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'Add webhooks', $data['title'] );
		$this->assertSame( 'pending', $data['moderationStatus'] );
		$this->assertSame( 'pending', get_post_status( $data['id'] ) );
		$this->assertSame( $user, (int) get_post( $data['id'] )->post_author );
	}

	public function test_submitted_request_does_not_appear_on_public_board(): void {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user );
		$this->post_request( array( 'title' => 'Pending item' ) );

		wp_set_current_user( 0 );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/signalboard/v1/requests' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 0, $response->get_data() );
	}

	public function test_blank_title_is_rejected_with_a_clear_error(): void {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user );

		$response = $this->post_request( array( 'title' => '   ' ) );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_my_submissions_lists_only_the_callers_requests(): void {
		$me   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$them = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		wp_set_current_user( $them );
		$this->post_request( array( 'title' => 'Theirs' ) );

		wp_set_current_user( $me );
		$this->post_request( array( 'title' => 'Mine A' ) );
		$this->post_request( array( 'title' => 'Mine B' ) );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/signalboard/v1/requests/mine' ) );

		$this->assertSame( 200, $response->get_status() );
		$titles = wp_list_pluck( $response->get_data(), 'title' );
		$this->assertContains( 'Mine A', $titles );
		$this->assertContains( 'Mine B', $titles );
		$this->assertNotContains( 'Theirs', $titles );
	}

	public function test_my_submissions_rejects_anonymous_callers(): void {
		wp_set_current_user( 0 );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/signalboard/v1/requests/mine' ) );

		$this->assertSame( 401, $response->get_status() );
	}
}

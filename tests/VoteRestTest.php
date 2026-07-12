<?php
/**
 * Integration tests for the REST vote/retract routes.
 *
 * Drives the real signalboard/v1 routes through rest_do_request, and asserts
 * REST/service parity at the VoteService seam (WPGraphQL is not installed in
 * the test env, so parity is proven where both surfaces converge: the table).
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Tests;

use Sagiris\Signalboard\Auth\AuthorizationPolicy;
use Sagiris\Signalboard\Content\RequestPostType;
use Sagiris\Signalboard\Domain\FeedbackRepository;
use Sagiris\Signalboard\Rest\RequestsController;
use Sagiris\Signalboard\Voting\RateLimiter;
use Sagiris\Signalboard\Voting\VoterFingerprint;
use Sagiris\Signalboard\Voting\VoteService;
use Sagiris\Signalboard\Voting\VotesTable;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @covers \Sagiris\Signalboard\Rest\RequestsController
 */
final class VoteRestTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		VotesTable::install();

		// Deterministic fingerprint: routes derive it from $_SERVER.
		$_SERVER['REMOTE_ADDR']              = '203.0.113.7';
		$_SERVER['HTTP_X_SIGNALBOARD_TOKEN'] = 'rest-token';

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		unset( $_SERVER['HTTP_X_SIGNALBOARD_TOKEN'] );
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function make_request(): int {
		return self::factory()->post->create(
			array(
				'post_type'   => RequestPostType::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Votable',
			)
		);
	}

	/**
	 * Register a controller with an injected rate limiter and re-boot the REST
	 * server so its routes replace the default registration for the test.
	 *
	 * @param RateLimiter $rate Injected limiter.
	 */
	private function register_with_rate_limiter( RateLimiter $rate ): void {
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();

		$controller = new RequestsController(
			new FeedbackRepository(),
			new VoteService(),
			$rate,
			new AuthorizationPolicy()
		);
		$controller->register_routes();
	}

	public function test_post_vote_returns_200_and_increments(): void {
		$id = $this->make_request();

		$response = rest_do_request( new WP_REST_Request( 'POST', "/signalboard/v1/requests/{$id}/vote" ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( $id, $data['id'] );
		$this->assertSame( 1, $data['voteCount'] );
		$this->assertTrue( $data['voted'] );
	}

	public function test_duplicate_post_same_fingerprint_does_not_double_count(): void {
		$id = $this->make_request();

		rest_do_request( new WP_REST_Request( 'POST', "/signalboard/v1/requests/{$id}/vote" ) );
		$response = rest_do_request( new WP_REST_Request( 'POST', "/signalboard/v1/requests/{$id}/vote" ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 1, $data['voteCount'], 'same fingerprint must not double-count' );
		$this->assertTrue( $data['voted'] );
	}

	public function test_delete_vote_returns_voted_false_and_decrements(): void {
		$id = $this->make_request();

		rest_do_request( new WP_REST_Request( 'POST', "/signalboard/v1/requests/{$id}/vote" ) );

		$response = rest_do_request( new WP_REST_Request( 'DELETE', "/signalboard/v1/requests/{$id}/vote" ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( $id, $data['id'] );
		$this->assertSame( 0, $data['voteCount'] );
		$this->assertFalse( $data['voted'] );
	}

	public function test_post_to_nonexistent_id_returns_404(): void {
		$response = rest_do_request( new WP_REST_Request( 'POST', '/signalboard/v1/requests/99999999/vote' ) );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'signalboard_request_not_found', $response->get_data()['code'] );
	}

	public function test_second_cast_is_rate_limited_with_429(): void {
		$id = $this->make_request();

		// Limit of 1: first cast allowed, second rejected by the limiter.
		$this->register_with_rate_limiter( new RateLimiter( 1, 60, 'signalboard_rest_test' ) );

		$first = rest_do_request( new WP_REST_Request( 'POST', "/signalboard/v1/requests/{$id}/vote" ) );
		$this->assertSame( 200, $first->get_status() );

		// Different fingerprint so the block is the rate limiter, not dedup.
		$_SERVER['HTTP_X_SIGNALBOARD_TOKEN'] = 'rest-token-2';
		$second                              = rest_do_request( new WP_REST_Request( 'POST', "/signalboard/v1/requests/{$id}/vote" ) );

		$this->assertSame( 429, $second->get_status() );
		$this->assertSame( 'signalboard_rate_limited', $second->get_data()['code'] );
	}

	public function test_rest_and_service_share_the_same_count(): void {
		$id = $this->make_request();

		// One vote via the REST route (fingerprint from $_SERVER).
		rest_do_request( new WP_REST_Request( 'POST', "/signalboard/v1/requests/{$id}/vote" ) );

		// One vote via the service directly, as the GraphQL mutation would.
		$service = new VoteService();
		$service->cast_vote( $id, VoterFingerprint::fromParts( '198.51.100.4', 'graphql-token', 'salt' ) );

		// Both surfaces operate on the same table/count.
		$this->assertSame( 2, $service->count_for( $id ) );

		$read = rest_do_request( new WP_REST_Request( 'GET', "/signalboard/v1/requests/{$id}" ) );
		$this->assertSame( 2, $read->get_data()['voteCount'] );
	}
}

<?php
/**
 * Isolation tests for the ModerationService deep module.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Tests;

use Sagiris\Signalboard\Content\RequestPostType;
use Sagiris\Signalboard\Domain\FeedbackRepository;
use Sagiris\Signalboard\Moderation\ModerationService;
use WP_UnitTestCase;

/**
 * @covers \Sagiris\Signalboard\Moderation\ModerationService
 */
final class ModerationServiceTest extends WP_UnitTestCase {

	private ModerationService $service;

	public function set_up(): void {
		parent::set_up();
		$this->service = new ModerationService();

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function make_request( string $status = 'pending' ): int {
		return self::factory()->post->create(
			array(
				'post_type'   => RequestPostType::POST_TYPE,
				'post_status' => $status,
				'post_title'  => 'A request',
			)
		);
	}

	private function ensure_status_term( string $label, string $slug ): void {
		if ( ! term_exists( $slug, RequestPostType::TAX_STATUS ) ) {
			wp_insert_term( $label, RequestPostType::TAX_STATUS, array( 'slug' => $slug ) );
		}
	}

	public function test_approve_transitions_a_pending_request_to_publish(): void {
		$id = $this->make_request( 'pending' );

		$result = $this->service->approve( $id );

		$this->assertTrue( $result );
		$this->assertSame( 'publish', get_post_status( $id ) );
	}

	public function test_approved_request_becomes_visible_on_the_public_board(): void {
		$id = $this->make_request( 'pending' );

		$repository = new FeedbackRepository();
		$this->assertSame( 0, $repository->list()['total'] );

		$this->service->approve( $id );

		$result = $repository->list();
		$ids    = array_map( static fn( $i ) => $i->id, $result['items'] );
		$this->assertSame( 1, $result['total'] );
		$this->assertContains( $id, $ids );
	}

	public function test_reject_sets_the_request_to_draft(): void {
		$id = $this->make_request( 'pending' );

		$result = $this->service->reject( $id );

		$this->assertTrue( $result );
		$this->assertSame( 'draft', get_post_status( $id ) );
	}

	public function test_trash_trashes_the_request(): void {
		$id = $this->make_request( 'publish' );

		$result = $this->service->trash( $id );

		$this->assertTrue( $result );
		$this->assertSame( 'trash', get_post_status( $id ) );
	}

	public function test_set_status_assigns_the_status_term(): void {
		$id = $this->make_request( 'publish' );
		$this->ensure_status_term( 'Planned', 'planned' );

		$result = $this->service->set_status( $id, 'planned' );

		$this->assertTrue( $result );
		$terms = wp_get_object_terms( $id, RequestPostType::TAX_STATUS, array( 'fields' => 'slugs' ) );
		$this->assertContains( 'planned', $terms );
	}

	public function test_set_status_rejects_an_unknown_status_slug(): void {
		$id = $this->make_request( 'publish' );

		$result = $this->service->set_status( $id, 'no-such-status' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'signalboard_invalid_status', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_approve_on_a_missing_request_returns_not_found(): void {
		$result = $this->service->approve( 99999999 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'signalboard_request_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_approve_on_a_non_request_post_returns_not_found(): void {
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);

		$result = $this->service->approve( $page );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'signalboard_request_not_found', $result->get_error_code() );
	}

	public function test_approve_is_forbidden_for_a_subscriber(): void {
		$id = $this->make_request( 'pending' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = $this->service->approve( $id );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'signalboard_moderation_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertSame( 'pending', get_post_status( $id ) );
	}

	public function test_reject_is_forbidden_for_a_subscriber(): void {
		$id = $this->make_request( 'pending' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = $this->service->reject( $id );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'signalboard_moderation_forbidden', $result->get_error_code() );
	}

	public function test_trash_is_forbidden_for_a_subscriber(): void {
		$id = $this->make_request( 'publish' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = $this->service->trash( $id );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'signalboard_moderation_forbidden', $result->get_error_code() );
		$this->assertSame( 'publish', get_post_status( $id ) );
	}

	public function test_set_status_is_forbidden_for_a_subscriber(): void {
		$id = $this->make_request( 'publish' );
		$this->ensure_status_term( 'Planned', 'planned' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = $this->service->set_status( $id, 'planned' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'signalboard_moderation_forbidden', $result->get_error_code() );
	}
}

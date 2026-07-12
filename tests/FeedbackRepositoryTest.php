<?php
/**
 * Isolation tests for the FeedbackRepository deep module.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Tests;

use Sagiris\Signalboard\Content\RequestPostType;
use Sagiris\Signalboard\Domain\FeedbackRepository;
use Sagiris\Signalboard\Domain\FeedbackRequest;
use WP_UnitTestCase;

/**
 * @covers \Sagiris\Signalboard\Domain\FeedbackRepository
 */
final class FeedbackRepositoryTest extends WP_UnitTestCase {

	private FeedbackRepository $repository;

	public function set_up(): void {
		parent::set_up();
		$this->repository = new FeedbackRepository();
	}

	/**
	 * Create a published feedback request with optional taxonomy terms and votes.
	 *
	 * @param array<string, mixed> $args Overrides.
	 * @return int Post ID.
	 */
	private function make_request( array $args = array() ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => RequestPostType::POST_TYPE,
				'post_status'  => $args['status'] ?? 'publish',
				'post_title'   => $args['title'] ?? 'A request',
				'post_content' => $args['content'] ?? 'Body',
			)
		);

		if ( isset( $args['status_term'] ) ) {
			$this->ensure_term( $args['status_term'], RequestPostType::TAX_STATUS );
			wp_set_object_terms( $post_id, $args['status_term'], RequestPostType::TAX_STATUS );
		}

		if ( isset( $args['board_term'] ) ) {
			$this->ensure_term( $args['board_term'], RequestPostType::TAX_BOARD );
			wp_set_object_terms( $post_id, $args['board_term'], RequestPostType::TAX_BOARD );
		}

		if ( isset( $args['votes'] ) ) {
			update_post_meta( $post_id, RequestPostType::META_VOTE_COUNT, (int) $args['votes'] );
		}

		return $post_id;
	}

	private function ensure_term( string $slug, string $taxonomy ): void {
		if ( ! term_exists( $slug, $taxonomy ) ) {
			wp_insert_term( ucfirst( $slug ), $taxonomy, array( 'slug' => $slug ) );
		}
	}

	public function test_list_returns_only_published_requests_as_dtos(): void {
		$published = $this->make_request( array( 'title' => 'Published one' ) );
		$this->make_request(
			array(
				'status' => 'draft',
				'title'  => 'Draft one',
			)
		);

		$result = $this->repository->list();

		$this->assertSame( 1, $result['total'] );
		$this->assertCount( 1, $result['items'] );
		$this->assertInstanceOf( FeedbackRequest::class, $result['items'][0] );
		$this->assertSame( $published, $result['items'][0]->id );
		$this->assertSame( 'Published one', $result['items'][0]->title );
	}

	public function test_list_excludes_non_published_statuses(): void {
		$this->make_request( array( 'status' => 'draft' ) );
		$this->make_request( array( 'status' => 'pending' ) );
		$this->make_request( array( 'status' => 'private' ) );

		$result = $this->repository->list();

		$this->assertSame( 0, $result['total'] );
		$this->assertCount( 0, $result['items'] );
	}

	public function test_list_filters_by_status_slug(): void {
		$open = $this->make_request(
			array(
				'status_term' => 'open',
				'title'       => 'Open req',
			)
		);
		$this->make_request(
			array(
				'status_term' => 'planned',
				'title'       => 'Planned req',
			)
		);

		$result = $this->repository->list( array( 'status' => 'open' ) );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( $open, $result['items'][0]->id );
		$this->assertSame( 'open', $result['items'][0]->status );
	}

	public function test_list_filters_by_board_slug(): void {
		$core = $this->make_request(
			array(
				'board_term' => 'core',
				'title'      => 'Core req',
			)
		);
		$this->make_request(
			array(
				'board_term' => 'mobile',
				'title'      => 'Mobile req',
			)
		);

		$result = $this->repository->list( array( 'board' => 'core' ) );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( $core, $result['items'][0]->id );
		$this->assertSame( 'core', $result['items'][0]->board );
	}

	public function test_list_orders_by_votes_descending(): void {
		$low  = $this->make_request(
			array(
				'title' => 'Low',
				'votes' => 2,
			)
		);
		$high = $this->make_request(
			array(
				'title' => 'High',
				'votes' => 50,
			)
		);
		$mid  = $this->make_request(
			array(
				'title' => 'Mid',
				'votes' => 10,
			)
		);

		$result = $this->repository->list(
			array(
				'orderby' => 'votes',
				'order'   => 'desc',
			)
		);

		$ids = array_map( static fn( $i ) => $i->id, $result['items'] );
		$this->assertSame( array( $high, $mid, $low ), $ids );
	}

	public function test_list_paginates_with_correct_totals(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->make_request( array( 'title' => "Req {$i}" ) );
		}

		$page1 = $this->repository->list(
			array(
				'per_page' => 2,
				'page'     => 1,
			)
		);
		$this->assertCount( 2, $page1['items'] );
		$this->assertSame( 5, $page1['total'] );
		$this->assertSame( 3, $page1['total_pages'] );

		$page3 = $this->repository->list(
			array(
				'per_page' => 2,
				'page'     => 3,
			)
		);
		$this->assertCount( 1, $page3['items'] );
		$this->assertSame( 5, $page3['total'] );
		$this->assertSame( 3, $page3['total_pages'] );
	}

	public function test_get_by_numeric_id_returns_dto(): void {
		$id = $this->make_request( array( 'title' => 'Fetch me' ) );

		$request = $this->repository->get( $id );

		$this->assertInstanceOf( FeedbackRequest::class, $request );
		$this->assertSame( $id, $request->id );
		$this->assertSame( 'Fetch me', $request->title );
	}

	public function test_get_by_slug_returns_dto(): void {
		$id   = $this->make_request( array( 'title' => 'Slug fetch' ) );
		$slug = get_post( $id )->post_name;

		$request = $this->repository->get( $slug );

		$this->assertInstanceOf( FeedbackRequest::class, $request );
		$this->assertSame( $id, $request->id );
	}

	public function test_get_unknown_id_returns_null(): void {
		$this->assertNull( $this->repository->get( 99999999 ) );
	}

	public function test_get_unknown_slug_returns_null(): void {
		$this->assertNull( $this->repository->get( 'no-such-slug-here' ) );
	}

	public function test_get_unpublished_post_returns_null(): void {
		$draft = $this->make_request(
			array(
				'status' => 'draft',
				'title'  => 'Hidden',
			)
		);

		$this->assertNull( $this->repository->get( $draft ) );
	}

	public function test_get_ignores_non_request_post_type(): void {
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);

		$this->assertNull( $this->repository->get( $page ) );
	}

	public function test_create_enters_pending_status(): void {
		$author = self::factory()->user->create();

		$request = $this->repository->create(
			array(
				'title'     => 'Dark mode please',
				'content'   => 'Would love a dark theme.',
				'author_id' => $author,
			)
		);

		$this->assertInstanceOf( FeedbackRequest::class, $request );
		$this->assertSame( 'pending', $request->moderation_status );
		$this->assertSame( 'Dark mode please', $request->title );
		$this->assertSame( 'pending', get_post_status( $request->id ) );
	}

	public function test_created_request_is_absent_from_the_public_list(): void {
		$author = self::factory()->user->create();

		$this->repository->create(
			array(
				'title'     => 'Hidden until approved',
				'author_id' => $author,
			)
		);

		$result = $this->repository->list();

		$this->assertSame( 0, $result['total'] );
		$this->assertCount( 0, $result['items'] );
	}

	public function test_create_assigns_the_submitting_author(): void {
		$author = self::factory()->user->create();

		$request = $this->repository->create(
			array(
				'title'     => 'Mine',
				'author_id' => $author,
			)
		);

		$this->assertSame( $author, (int) get_post( $request->id )->post_author );
	}

	public function test_create_rejects_a_blank_title(): void {
		$author = self::factory()->user->create();

		$result = $this->repository->create(
			array(
				'title'     => '   ',
				'author_id' => $author,
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'signalboard_invalid_submission', $result->get_error_code() );
	}

	public function test_create_rejects_a_missing_author(): void {
		$result = $this->repository->create( array( 'title' => 'Orphan' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'signalboard_invalid_submission', $result->get_error_code() );
	}

	public function test_create_files_under_an_existing_board_term(): void {
		$author = self::factory()->user->create();
		$this->ensure_term( 'core', RequestPostType::TAX_BOARD );

		$request = $this->repository->create(
			array(
				'title'     => 'Boarded',
				'board'     => 'core',
				'author_id' => $author,
			)
		);

		$this->assertSame( 'core', $request->board );
	}

	public function test_list_by_author_returns_own_requests_across_states(): void {
		$author = self::factory()->user->create();

		$pending   = $this->repository->create(
			array(
				'title'     => 'Pending one',
				'author_id' => $author,
			)
		);
		$published = self::factory()->post->create(
			array(
				'post_type'   => RequestPostType::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Published one',
				'post_author' => $author,
			)
		);

		$result = $this->repository->list_by_author( $author );

		$ids = array_map( static fn( $i ) => $i->id, $result['items'] );
		$this->assertSame( 2, $result['total'] );
		$this->assertContains( $pending->id, $ids );
		$this->assertContains( $published, $ids );
	}

	public function test_list_by_author_excludes_other_authors(): void {
		$mine   = self::factory()->user->create();
		$theirs = self::factory()->user->create();

		$this->repository->create(
			array(
				'title'     => 'Theirs',
				'author_id' => $theirs,
			)
		);
		$my_request = $this->repository->create(
			array(
				'title'     => 'Mine',
				'author_id' => $mine,
			)
		);

		$result = $this->repository->list_by_author( $mine );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( $my_request->id, $result['items'][0]->id );
	}

	public function test_list_by_author_is_empty_for_anonymous(): void {
		$result = $this->repository->list_by_author( 0 );

		$this->assertSame( 0, $result['total'] );
		$this->assertCount( 0, $result['items'] );
	}
}

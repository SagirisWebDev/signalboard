<?php
/**
 * Tests for the moderation queue WP_List_Table.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Tests;

use Sagiris\Signalboard\Content\RequestPostType;
use Sagiris\Signalboard\Moderation\ModerationListTable;
use WP_UnitTestCase;

/**
 * @covers \Sagiris\Signalboard\Moderation\ModerationListTable
 */
final class ModerationListTableTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

		set_current_screen( 'toplevel_page_signalboard-moderation' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function make_request( string $status, string $title ): int {
		return self::factory()->post->create(
			array(
				'post_type'   => RequestPostType::POST_TYPE,
				'post_status' => $status,
				'post_title'  => $title,
			)
		);
	}

	public function test_columns_include_status_board_and_votes(): void {
		$table   = new ModerationListTable();
		$columns = $table->get_columns();

		foreach ( array( 'cb', 'title', 'status', 'board', 'votes', 'date' ) as $key ) {
			$this->assertArrayHasKey( $key, $columns );
		}
	}

	public function test_status_board_votes_and_date_are_sortable(): void {
		$table    = new ModerationListTable();
		$sortable = $table->get_sortable_columns();

		foreach ( array( 'status', 'board', 'votes', 'date' ) as $key ) {
			$this->assertArrayHasKey( $key, $sortable );
		}
	}

	public function test_bulk_actions_offer_approve_reject_and_trash(): void {
		$table   = new ModerationListTable();
		$actions = $table->get_bulk_actions();

		foreach ( array( 'approve', 'reject', 'trash' ) as $key ) {
			$this->assertArrayHasKey( $key, $actions );
		}
	}

	public function test_prepare_items_lists_requests_across_moderation_states(): void {
		$pending   = $this->make_request( 'pending', 'Pending one' );
		$published = $this->make_request( 'publish', 'Published one' );
		$draft     = $this->make_request( 'draft', 'Draft one' );

		$table = new ModerationListTable();
		$table->prepare_items();

		$ids = array_map(
			static fn( $item ) => is_object( $item ) ? (int) $item->ID : (int) $item['ID'],
			$table->items
		);

		$this->assertContains( $pending, $ids );
		$this->assertContains( $published, $ids );
		$this->assertContains( $draft, $ids );
	}

	public function test_prepare_items_orders_newest_first(): void {
		// Distinct post_date values so newest-first ordering is deterministic.
		// edit_date => true is required for wp_update_post to honour an explicit post_date.
		$older = self::factory()->post->create(
			array(
				'post_type'     => RequestPostType::POST_TYPE,
				'post_status'   => 'pending',
				'post_title'    => 'Older',
				'post_date'     => '2020-01-01 00:00:00',
				'post_date_gmt' => '2020-01-01 00:00:00',
			)
		);
		$newer = self::factory()->post->create(
			array(
				'post_type'     => RequestPostType::POST_TYPE,
				'post_status'   => 'pending',
				'post_title'    => 'Newer',
				'post_date'     => '2030-01-01 00:00:00',
				'post_date_gmt' => '2030-01-01 00:00:00',
			)
		);

		$table = new ModerationListTable();
		$table->prepare_items();

		$ids = array_map(
			static fn( $item ) => is_object( $item ) ? (int) $item->ID : (int) $item['ID'],
			$table->items
		);

		$this->assertSame( $newer, $ids[0] );
		$this->assertSame( $older, $ids[ count( $ids ) - 1 ] );
	}

	public function test_prepare_items_excludes_unrelated_post_types(): void {
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);

		$table = new ModerationListTable();
		$table->prepare_items();

		$ids = array_map(
			static fn( $item ) => is_object( $item ) ? (int) $item->ID : (int) $item['ID'],
			$table->items
		);

		$this->assertNotContains( $page, $ids );
	}

	public function test_empty_queue_yields_zero_items_without_error(): void {
		$table = new ModerationListTable();
		$table->prepare_items();

		$this->assertIsArray( $table->items );
		$this->assertCount( 0, $table->items );
	}
}

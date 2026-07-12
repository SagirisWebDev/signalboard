<?php
/**
 * Tests for content-type registration and status seeding.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Tests;

use Sagiris\Signalboard\Content\RequestPostType;
use WP_UnitTestCase;

/**
 * @covers \Sagiris\Signalboard\Content\RequestPostType
 */
final class RequestPostTypeTest extends WP_UnitTestCase {

	public function test_post_type_is_registered_after_init(): void {
		$this->assertTrue( post_type_exists( RequestPostType::POST_TYPE ) );
	}

	public function test_status_taxonomy_is_registered_after_init(): void {
		$this->assertTrue( taxonomy_exists( RequestPostType::TAX_STATUS ) );
	}

	public function test_board_taxonomy_is_registered_after_init(): void {
		$this->assertTrue( taxonomy_exists( RequestPostType::TAX_BOARD ) );
	}

	public function test_seed_default_statuses_inserts_six_terms(): void {
		$post_type = new RequestPostType();
		$post_type->seed_default_statuses();

		foreach ( array_keys( RequestPostType::default_statuses() ) as $slug ) {
			$this->assertIsArray(
				term_exists( $slug, RequestPostType::TAX_STATUS ),
				"Expected status term '{$slug}' to exist."
			);
		}

		$this->assertCount( 6, RequestPostType::default_statuses() );
	}

	public function test_seed_default_statuses_is_idempotent(): void {
		$post_type = new RequestPostType();
		$post_type->seed_default_statuses();
		$post_type->seed_default_statuses();

		$terms = get_terms(
			array(
				'taxonomy'   => RequestPostType::TAX_STATUS,
				'hide_empty' => false,
			)
		);

		$this->assertCount( 6, $terms );
	}
}

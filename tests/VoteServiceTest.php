<?php
/**
 * Isolation tests for the VoteService deep module.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Tests;

use Sagiris\Signalboard\Content\RequestPostType;
use Sagiris\Signalboard\Voting\VoterFingerprint;
use Sagiris\Signalboard\Voting\VoteService;
use Sagiris\Signalboard\Voting\VotesTable;
use WP_UnitTestCase;

/**
 * @covers \Sagiris\Signalboard\Voting\VoteService
 */
final class VoteServiceTest extends WP_UnitTestCase {

	private VoteService $service;

	public function set_up(): void {
		parent::set_up();
		VotesTable::install();
		$this->service = new VoteService();
	}

	private function make_request(): int {
		return self::factory()->post->create(
			array(
				'post_type'   => RequestPostType::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Votable request',
			)
		);
	}

	private function fingerprint( string $ip = '10.0.0.1', string $token = 'token-a' ): VoterFingerprint {
		return VoterFingerprint::fromParts( $ip, $token, 'salt' );
	}

	public function test_first_cast_counts_and_returns_count_of_one(): void {
		$id = $this->make_request();

		$result = $this->service->cast_vote( $id, $this->fingerprint() );

		$this->assertTrue( $result['counted'] );
		$this->assertSame( 1, $result['count'] );
	}

	public function test_duplicate_cast_from_same_fingerprint_does_not_increment(): void {
		$id = $this->make_request();
		$fp = $this->fingerprint();

		$first = $this->service->cast_vote( $id, $fp );
		$this->assertTrue( $first['counted'] );
		$this->assertSame( 1, $first['count'] );

		$second = $this->service->cast_vote( $id, $fp );
		$this->assertFalse( $second['counted'], 'unique constraint must reject a duplicate' );
		$this->assertSame( 1, $second['count'], 'count must stay at 1 for a duplicate' );

		$this->assertSame( 1, $this->service->count_for( $id ) );
	}

	public function test_different_fingerprints_each_count(): void {
		$id = $this->make_request();

		$this->service->cast_vote( $id, $this->fingerprint( '10.0.0.1', 'token-a' ) );
		$result = $this->service->cast_vote( $id, $this->fingerprint( '10.0.0.2', 'token-b' ) );

		$this->assertTrue( $result['counted'] );
		$this->assertSame( 2, $result['count'] );
	}

	public function test_retract_removes_the_row_and_decrements(): void {
		$id = $this->make_request();
		$fp = $this->fingerprint();

		$this->service->cast_vote( $id, $fp );
		$this->assertSame( 1, $this->service->count_for( $id ) );

		$result = $this->service->retract_vote( $id, $fp );

		$this->assertTrue( $result['retracted'] );
		$this->assertSame( 0, $result['count'] );
		$this->assertSame( 0, $this->service->count_for( $id ) );
		$this->assertFalse( $this->service->has_voted( $id, $fp ) );
	}

	public function test_retract_when_no_vote_exists_is_a_noop(): void {
		$id = $this->make_request();

		$result = $this->service->retract_vote( $id, $this->fingerprint() );

		$this->assertFalse( $result['retracted'] );
		$this->assertSame( 0, $result['count'] );
	}

	public function test_has_voted_reflects_state(): void {
		$id = $this->make_request();
		$fp = $this->fingerprint();

		$this->assertFalse( $this->service->has_voted( $id, $fp ) );

		$this->service->cast_vote( $id, $fp );
		$this->assertTrue( $this->service->has_voted( $id, $fp ) );

		$this->service->retract_vote( $id, $fp );
		$this->assertFalse( $this->service->has_voted( $id, $fp ) );
	}

	public function test_count_for_matches_the_table(): void {
		global $wpdb;
		$id = $this->make_request();

		$this->service->cast_vote( $id, $this->fingerprint( '10.0.0.1', 'a' ) );
		$this->service->cast_vote( $id, $this->fingerprint( '10.0.0.2', 'b' ) );
		$this->service->cast_vote( $id, $this->fingerprint( '10.0.0.3', 'c' ) );

		$table  = VotesTable::table_name();
		$direct = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE request_id = %d", $id ) ); // phpcs:ignore

		$this->assertSame( 3, $direct );
		$this->assertSame( $direct, $this->service->count_for( $id ) );
	}

	public function test_post_meta_cache_is_synced_after_cast(): void {
		$id = $this->make_request();

		$this->service->cast_vote( $id, $this->fingerprint( '10.0.0.1', 'a' ) );
		$this->service->cast_vote( $id, $this->fingerprint( '10.0.0.2', 'b' ) );

		$meta = (int) get_post_meta( $id, RequestPostType::META_VOTE_COUNT, true );
		$this->assertSame( 2, $meta );
	}

	public function test_post_meta_cache_is_synced_after_retract(): void {
		$id = $this->make_request();
		$fp = $this->fingerprint();

		$this->service->cast_vote( $id, $fp );
		$this->service->cast_vote( $id, $this->fingerprint( '10.0.0.2', 'b' ) );
		$this->assertSame( 2, (int) get_post_meta( $id, RequestPostType::META_VOTE_COUNT, true ) );

		$this->service->retract_vote( $id, $fp );

		$meta = (int) get_post_meta( $id, RequestPostType::META_VOTE_COUNT, true );
		$this->assertSame( 1, $meta );
	}
}

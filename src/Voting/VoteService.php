<?php
/**
 * Vote service: cast, retract, count.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Voting;

use Sagiris\Signalboard\Content\RequestPostType;

defined( 'ABSPATH' ) || exit;

/**
 * The single write/read path for anonymous votes.
 *
 * Backed by the {@see VotesTable} custom table, whose unique constraint on
 * (request_id, voter_hash) enforces one vote per fingerprint. After every
 * mutation the cached `_signalboard_vote_count` post meta is resynced to the
 * live table count so ordering-by-votes and read surfaces stay accurate. Both
 * the REST routes and the WPGraphQL mutations delegate here, so the two APIs
 * can never drift.
 */
final class VoteService {

	/**
	 * Cast a vote for a request from a given voter.
	 *
	 * @param int              $request_id Request post ID.
	 * @param VoterFingerprint $voter      Voter fingerprint.
	 * @return array{counted: bool, count: int} Whether a new vote was recorded and the updated count.
	 */
	public function cast_vote( int $request_id, VoterFingerprint $voter ): array {
		global $wpdb;

		$table = VotesTable::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom votes table; INSERT IGNORE relies on the DB unique constraint for dedup. Table name derives from $wpdb->prefix; values are placeholder-bound.
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (request_id, voter_hash, created_at) VALUES (%d, %s, %s)", $request_id, $voter->value(), current_time( 'mysql', true ) ) );

		return array(
			'counted' => is_int( $inserted ) && $inserted > 0,
			'count'   => $this->resync( $request_id ),
		);
	}

	/**
	 * Retract a previously cast vote.
	 *
	 * @param int              $request_id Request post ID.
	 * @param VoterFingerprint $voter      Voter fingerprint.
	 * @return array{retracted: bool, count: int} Whether a vote was removed and the updated count.
	 */
	public function retract_vote( int $request_id, VoterFingerprint $voter ): array {
		global $wpdb;

		$table = VotesTable::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom votes table; no core API equivalent. Table name derives from $wpdb->prefix; values are placeholder-bound.
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE request_id = %d AND voter_hash = %s", $request_id, $voter->value() ) );

		return array(
			'retracted' => is_int( $deleted ) && $deleted > 0,
			'count'     => $this->resync( $request_id ),
		);
	}

	/**
	 * Whether the given voter has an active vote for the request.
	 *
	 * @param int              $request_id Request post ID.
	 * @param VoterFingerprint $voter      Voter fingerprint.
	 * @return bool
	 */
	public function has_voted( int $request_id, VoterFingerprint $voter ): bool {
		global $wpdb;

		$table = VotesTable::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom votes table; table name derives from $wpdb->prefix; value is placeholder-bound.
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE request_id = %d AND voter_hash = %s", $request_id, $voter->value() ) );

		return (int) $found > 0;
	}

	/**
	 * Live vote count for a request, straight from the votes table.
	 *
	 * @param int $request_id Request post ID.
	 * @return int
	 */
	public function count_for( int $request_id ): int {
		global $wpdb;

		$table = VotesTable::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom votes table; table name derives from $wpdb->prefix; value is placeholder-bound.
		$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE request_id = %d", $request_id ) );

		return (int) $count;
	}

	/**
	 * Recompute the live count and sync the cached post meta to it.
	 *
	 * @param int $request_id Request post ID.
	 * @return int The live count.
	 */
	private function resync( int $request_id ): int {
		$count = $this->count_for( $request_id );

		update_post_meta( $request_id, RequestPostType::META_VOTE_COUNT, $count );

		return $count;
	}
}

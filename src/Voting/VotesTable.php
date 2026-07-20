<?php
/**
 * Custom votes table schema.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Voting;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the bespoke votes table: one row per (request, voter fingerprint).
 *
 * A dedicated table (rather than post meta) is used so the unique constraint on
 * (request_id, voter_hash) enforces dedup at the database level and counting a
 * request's votes is a single indexed COUNT(*).
 */
final class VotesTable {

	/**
	 * Schema version. Bump to trigger a dbDelta upgrade.
	 */
	public const VERSION = '1';

	/**
	 * Fully-qualified table name for the current site.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'signalboard_votes';
	}

	/**
	 * Whether the table actually exists in the database.
	 *
	 * Used to self-heal sites where the version option was recorded but the
	 * dbDelta call it guarded never actually persisted (observed once via a
	 * WP-CLI-driven activation).
	 *
	 * @return bool
	 */
	public static function exists(): bool {
		global $wpdb;

		$table = self::table_name();

		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * Create or upgrade the votes table via dbDelta.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			request_id bigint(20) unsigned NOT NULL,
			voter_hash char(64) NOT NULL,
			created_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY request_voter (request_id, voter_hash),
			KEY request_id (request_id)
		) {$charset_collate};";

		dbDelta( $sql );
	}
}

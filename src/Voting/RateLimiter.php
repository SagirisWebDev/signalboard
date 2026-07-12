<?php
/**
 * Transient-backed sliding-window rate limiter.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Voting;

defined( 'ABSPATH' ) || exit;

/**
 * A small, reusable sliding-window rate limiter backed by transients.
 *
 * Each key tracks the timestamps of recent attempts; attempts older than the
 * window are pruned on read, so the limiter naturally "resets" once the window
 * elapses. The clock is injectable for deterministic testing.
 */
final class RateLimiter {

	/**
	 * Maximum attempts permitted within the window.
	 *
	 * @var int
	 */
	private int $limit;

	/**
	 * Trailing window length, in seconds.
	 *
	 * @var int
	 */
	private int $window;

	/**
	 * Transient key prefix (namespaces buckets to a caller).
	 *
	 * @var string
	 */
	private string $prefix;

	/**
	 * Clock returning the current unix timestamp.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param int           $limit  Maximum attempts within the window.
	 * @param int           $window Window length in seconds.
	 * @param string        $prefix Transient key prefix.
	 * @param callable|null $clock  Optional clock (returns a unix timestamp).
	 */
	public function __construct( int $limit, int $window, string $prefix = 'signalboard', ?callable $clock = null ) {
		$this->limit  = max( 1, $limit );
		$this->window = max( 1, $window );
		$this->prefix = $prefix;
		$this->clock  = $clock ?? static function (): int {
			return time();
		};
	}

	/**
	 * Record an attempt against a key and report whether it is permitted.
	 *
	 * @param string $key Caller-defined bucket key (e.g. a hashed IP).
	 * @return bool True while under the limit; false once the limit is reached.
	 */
	public function allow( string $key ): bool {
		$now          = (int) ( $this->clock )();
		$transient    = $this->transient_key( $key );
		$window_start = $now - $this->window;

		$timestamps = get_transient( $transient );
		if ( ! is_array( $timestamps ) ) {
			$timestamps = array();
		}

		// Drop attempts that have aged out of the trailing window.
		$timestamps = array_values(
			array_filter(
				$timestamps,
				static function ( $timestamp ) use ( $window_start ) {
					return (int) $timestamp > $window_start;
				}
			)
		);

		if ( count( $timestamps ) >= $this->limit ) {
			set_transient( $transient, $timestamps, $this->window );
			return false;
		}

		$timestamps[] = $now;
		set_transient( $transient, $timestamps, $this->window );

		return true;
	}

	/**
	 * Build the transient key for a bucket.
	 *
	 * @param string $key Bucket key.
	 * @return string
	 */
	private function transient_key( string $key ): string {
		return $this->prefix . '_' . md5( $key );
	}
}

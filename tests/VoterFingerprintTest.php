<?php
/**
 * Isolation tests for the VoterFingerprint value object.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Tests;

use Sagiris\Signalboard\Voting\VoterFingerprint;
use WP_UnitTestCase;

/**
 * @covers \Sagiris\Signalboard\Voting\VoterFingerprint
 */
final class VoterFingerprintTest extends WP_UnitTestCase {

	public function test_from_parts_is_deterministic_for_equal_inputs(): void {
		$a = VoterFingerprint::fromParts( '1.2.3.4', 'tok', 'salt' );
		$b = VoterFingerprint::fromParts( '1.2.3.4', 'tok', 'salt' );

		$this->assertSame( $a->value(), $b->value() );
	}

	public function test_differs_when_ip_differs(): void {
		$a = VoterFingerprint::fromParts( '1.2.3.4', 'tok', 'salt' );
		$b = VoterFingerprint::fromParts( '9.9.9.9', 'tok', 'salt' );

		$this->assertNotSame( $a->value(), $b->value() );
	}

	public function test_differs_when_token_differs(): void {
		$a = VoterFingerprint::fromParts( '1.2.3.4', 'tok-a', 'salt' );
		$b = VoterFingerprint::fromParts( '1.2.3.4', 'tok-b', 'salt' );

		$this->assertNotSame( $a->value(), $b->value() );
	}

	public function test_differs_when_salt_differs(): void {
		$a = VoterFingerprint::fromParts( '1.2.3.4', 'tok', 'salt-a' );
		$b = VoterFingerprint::fromParts( '1.2.3.4', 'tok', 'salt-b' );

		$this->assertNotSame( $a->value(), $b->value() );
	}

	public function test_value_is_64_hex_characters(): void {
		$value = VoterFingerprint::fromParts( '1.2.3.4', 'tok', 'salt' )->value();

		$this->assertSame( 64, strlen( $value ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $value );
	}

	public function test_from_server_reads_remote_addr_and_token_header(): void {
		$server = array(
			'REMOTE_ADDR'              => '5.6.7.8',
			'HTTP_X_SIGNALBOARD_TOKEN' => 'header-token',
		);

		$from_server = VoterFingerprint::fromServer( $server, array(), 'salt' );
		$expected    = VoterFingerprint::fromParts( '5.6.7.8', 'header-token', 'salt' );

		$this->assertSame( $expected->value(), $from_server->value() );
	}

	public function test_from_server_falls_back_to_cookie_token(): void {
		$server = array( 'REMOTE_ADDR' => '5.6.7.8' );
		$cookie = array( 'signalboard_vote_token' => 'cookie-token' );

		$from_server = VoterFingerprint::fromServer( $server, $cookie, 'salt' );
		$expected    = VoterFingerprint::fromParts( '5.6.7.8', 'cookie-token', 'salt' );

		$this->assertSame( $expected->value(), $from_server->value() );
	}

	public function test_from_server_prefers_header_over_cookie(): void {
		$server = array(
			'REMOTE_ADDR'              => '5.6.7.8',
			'HTTP_X_SIGNALBOARD_TOKEN' => 'header-token',
		);
		$cookie = array( 'signalboard_vote_token' => 'cookie-token' );

		$from_server = VoterFingerprint::fromServer( $server, $cookie, 'salt' );
		$expected    = VoterFingerprint::fromParts( '5.6.7.8', 'header-token', 'salt' );

		$this->assertSame( $expected->value(), $from_server->value() );
	}
}

<?php
/**
 * Tests for the ModerationScreen action dispatch (nonce + capability gate).
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Tests;

use Sagiris\Signalboard\Content\RequestPostType;
use Sagiris\Signalboard\Moderation\ModerationScreen;
use WP_UnitTestCase;

/**
 * @covers \Sagiris\Signalboard\Moderation\ModerationScreen
 */
final class ModerationScreenTest extends WP_UnitTestCase {

	private ModerationScreen $screen;

	public function set_up(): void {
		parent::set_up();
		$this->screen = new ModerationScreen();
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function make_pending(): int {
		return self::factory()->post->create(
			array(
				'post_type'   => RequestPostType::POST_TYPE,
				'post_status' => 'pending',
				'post_title'  => 'Pending',
			)
		);
	}

	private function login_editor(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
	}

	public function test_nonce_action_constant_is_a_non_empty_string(): void {
		$this->assertIsString( ModerationScreen::NONCE_ACTION );
		$this->assertNotSame( '', ModerationScreen::NONCE_ACTION );
	}

	public function test_process_action_is_forbidden_for_a_subscriber(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$id    = $this->make_pending();
		$nonce = wp_create_nonce( ModerationScreen::NONCE_ACTION );

		$result = $this->screen->process_action( 'approve', array( $id ), $nonce );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'signalboard_moderation_forbidden', $result->get_error_code() );
		$this->assertSame( 'pending', get_post_status( $id ) );
	}

	public function test_process_action_rejects_an_invalid_nonce(): void {
		$this->login_editor();
		$id = $this->make_pending();

		$result = $this->screen->process_action( 'approve', array( $id ), 'not-a-valid-nonce' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'signalboard_invalid_nonce', $result->get_error_code() );
		$this->assertSame( 'pending', get_post_status( $id ) );
	}

	public function test_bulk_approve_with_a_valid_nonce_publishes_every_selected_id(): void {
		$this->login_editor();
		$first  = $this->make_pending();
		$second = $this->make_pending();
		$nonce  = wp_create_nonce( ModerationScreen::NONCE_ACTION );

		$result = $this->screen->process_action( 'approve', array( $first, $second ), $nonce );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( $first, $result );
		$this->assertArrayHasKey( $second, $result );
		$this->assertSame( 'publish', get_post_status( $first ) );
		$this->assertSame( 'publish', get_post_status( $second ) );
	}

	public function test_bulk_reject_with_a_valid_nonce_drafts_every_selected_id(): void {
		$this->login_editor();
		$first  = $this->make_pending();
		$second = $this->make_pending();
		$nonce  = wp_create_nonce( ModerationScreen::NONCE_ACTION );

		$result = $this->screen->process_action( 'reject', array( $first, $second ), $nonce );

		$this->assertIsArray( $result );
		$this->assertSame( 'draft', get_post_status( $first ) );
		$this->assertSame( 'draft', get_post_status( $second ) );
	}

	public function test_bulk_trash_with_a_valid_nonce_trashes_every_selected_id(): void {
		$this->login_editor();
		$first  = $this->make_pending();
		$second = $this->make_pending();
		$nonce  = wp_create_nonce( ModerationScreen::NONCE_ACTION );

		$result = $this->screen->process_action( 'trash', array( $first, $second ), $nonce );

		$this->assertIsArray( $result );
		$this->assertSame( 'trash', get_post_status( $first ) );
		$this->assertSame( 'trash', get_post_status( $second ) );
	}
}

<?php
/**
 * Integration tests for WebhookSubscriber — the WP hook wiring that turns
 * post_status transitions and status-term assignments into webhook dispatches.
 *
 * A lightweight WebhookDispatcher subclass (RecordingWebhookDispatcher, in its
 * own file) records dispatch() calls instead of making HTTP requests, so the
 * wiring is verified without touching the network.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Tests;

use Sagiris\Signalboard\Content\RequestPostType;
use Sagiris\Signalboard\Webhook\WebhookSettings;
use Sagiris\Signalboard\Webhook\WebhookSubscriber;
use WP_UnitTestCase;

/**
 * @covers \Sagiris\Signalboard\Webhook\WebhookSubscriber
 */
final class WebhookSubscriberTest extends WP_UnitTestCase {

	private RecordingWebhookDispatcher $dispatcher;

	public function set_up(): void {
		parent::set_up();
		$this->dispatcher = new RecordingWebhookDispatcher();

		$subscriber = new WebhookSubscriber( $this->dispatcher );
		$subscriber->register();
	}

	public function tear_down(): void {
		delete_option( WebhookSettings::OPTION );
		remove_all_filters( 'transition_post_status' );
		remove_all_filters( 'set_object_terms' );
		parent::tear_down();
	}

	private function ensure_status_term( string $label, string $slug ): int {
		$existing = term_exists( $slug, RequestPostType::TAX_STATUS );
		if ( $existing ) {
			return (int) ( is_array( $existing ) ? $existing['term_id'] : $existing );
		}
		$term = wp_insert_term( $label, RequestPostType::TAX_STATUS, array( 'slug' => $slug ) );
		return (int) $term['term_id'];
	}

	/**
	 * Find the recorded call for a given event, or null.
	 */
	private function call_for( string $event ): ?array {
		foreach ( $this->dispatcher->calls as $call ) {
			if ( $event === $call['event'] ) {
				return $call;
			}
		}
		return null;
	}

	public function test_register_hooks_the_transition_and_terms_actions(): void {
		$this->assertNotFalse(
			has_action( 'transition_post_status' ),
			'transition_post_status must be hooked'
		);
		$this->assertNotFalse(
			has_action( 'set_object_terms' ),
			'set_object_terms must be hooked'
		);
	}

	public function test_publishing_a_request_dispatches_publish_state_changed(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => RequestPostType::POST_TYPE,
				'post_status' => 'pending',
			)
		);

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		$call = $this->call_for( 'publish_state_changed' );
		$this->assertNotNull( $call, 'publish_state_changed must be dispatched' );
		$this->assertSame( $post_id, $call['post_id'] );
		$this->assertSame( 'pending', $call['old'] );
		$this->assertSame( 'publish', $call['new'] );
	}

	public function test_status_transition_on_a_non_request_post_is_ignored(): void {
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
			)
		);

		wp_update_post(
			array(
				'ID'          => $page,
				'post_status' => 'publish',
			)
		);

		$this->assertNull(
			$this->call_for( 'publish_state_changed' ),
			'non-signalboard posts must not dispatch'
		);
	}

	public function test_unchanged_status_does_not_dispatch(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => RequestPostType::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		// Re-save with the same status; new === old, so no dispatch.
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Renamed, same status',
			)
		);

		$this->assertNull( $this->call_for( 'publish_state_changed' ) );
	}

	public function test_assigning_a_different_status_term_dispatches_status_changed(): void {
		$this->ensure_status_term( 'Open', 'open' );
		$this->ensure_status_term( 'Planned', 'planned' );

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => RequestPostType::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		wp_set_object_terms( $post_id, 'open', RequestPostType::TAX_STATUS );
		// Clear recorded calls from the initial assignment; interested in the change.
		$this->dispatcher->calls = array();

		wp_set_object_terms( $post_id, 'planned', RequestPostType::TAX_STATUS );

		$call = $this->call_for( 'status_changed' );
		$this->assertNotNull( $call, 'status_changed must be dispatched on a term change' );
		$this->assertSame( $post_id, $call['post_id'] );
		$this->assertSame( 'open', $call['old'] );
		$this->assertSame( 'planned', $call['new'] );
	}

	public function test_assigning_a_term_in_another_taxonomy_is_ignored(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => RequestPostType::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		wp_set_object_terms( $post_id, 'gadgets', 'category' );

		$this->assertNull(
			$this->call_for( 'status_changed' ),
			'non-signalboard_status taxonomies must not dispatch'
		);
	}
}

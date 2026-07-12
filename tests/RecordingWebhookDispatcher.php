<?php
/**
 * Test double for the webhook dispatcher.
 *
 * Extends the real WebhookDispatcher (satisfying WebhookSubscriber's typed
 * constructor) but overrides dispatch() to record invocations instead of
 * making HTTP requests.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Tests;

use Sagiris\Signalboard\Webhook\WebhookDispatcher;

/**
 * A recording double: extends the real dispatcher (so it satisfies the typed
 * constructor) but overrides dispatch() to capture calls, never making HTTP.
 */
final class RecordingWebhookDispatcher extends WebhookDispatcher {

	/**
	 * Recorded dispatch() invocations.
	 *
	 * @var array<int, array{event:string,post_id:int,old:?string,new:?string}>
	 */
	public array $calls = array();

	/**
	 * Record a dispatch call without making any HTTP request.
	 *
	 * @param string      $event      The webhook event name.
	 * @param int         $post_id    The request post ID.
	 * @param string|null $old_status The prior status/state.
	 * @param string|null $new_status The new status/state.
	 * @return null
	 */
	public function dispatch( string $event, int $post_id, ?string $old_status, ?string $new_status ) {
		$this->calls[] = array(
			'event'   => $event,
			'post_id' => $post_id,
			'old'     => $old_status,
			'new'     => $new_status,
		);
		return null;
	}
}

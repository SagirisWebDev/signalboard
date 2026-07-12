<?php
/**
 * Server-side renderer for the Signalboard board.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Block;

use Sagiris\Signalboard\Content\RequestPostType;
use Sagiris\Signalboard\Domain\FeedbackRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the board's server-rendered, Interactivity-API-enhanced markup.
 *
 * The initial page is rendered server-side (SEO / no-JS) from the shared
 * {@see FeedbackRepository}; the frontend view module then drives live filter,
 * sort and pagination against the signalboard/v1 REST route.
 */
final class BoardRenderer {

	private const PER_PAGE = 10;

	/**
	 * Read model.
	 *
	 * @var FeedbackRepository
	 */
	private FeedbackRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param FeedbackRepository|null $repository Optional read model (defaults to a new instance).
	 */
	public function __construct( ?FeedbackRepository $repository = null ) {
		$this->repository = $repository ?? new FeedbackRepository();
	}

	/**
	 * Render the board for the given block attributes.
	 *
	 * @param array<string, mixed> $attributes Block attributes (board, defaultSort).
	 * @return string Board HTML with Interactivity API directives.
	 */
	public function render( array $attributes ): string {
		$board = isset( $attributes['board'] ) ? sanitize_title( (string) $attributes['board'] ) : '';
		$sort  = isset( $attributes['defaultSort'] ) ? (string) $attributes['defaultSort'] : 'date';
		if ( ! in_array( $sort, array( 'date', 'votes', 'title' ), true ) ) {
			$sort = 'date';
		}

		$allow_submissions = ! empty( $attributes['allowSubmissions'] );

		$result = $this->repository->list(
			array(
				'board'    => $board,
				'orderby'  => $sort,
				'order'    => 'title' === $sort ? 'asc' : 'desc',
				'page'     => 1,
				'per_page' => self::PER_PAGE,
			)
		);

		$items = array_map(
			static function ( $request ) {
				return array(
					'id'          => $request->id,
					'title'       => $request->title,
					'voteCount'   => $request->vote_count,
					'status'      => $request->status,
					'statusLabel' => $request->status_label,
					'voted'       => false,
				);
			},
			$result['items']
		);

		$total_pages = (int) $result['total_pages'];

		if ( function_exists( 'wp_interactivity_state' ) ) {
			wp_interactivity_state(
				'signalboard/board',
				array( 'restUrl' => esc_url_raw( rest_url( 'signalboard/v1/requests' ) ) )
			);
		}

		$context = array(
			'board'      => $board,
			'sort'       => $sort,
			'status'     => '',
			'page'       => 1,
			'perPage'    => self::PER_PAGE,
			'totalPages' => $total_pages > 0 ? $total_pages : 1,
			'items'      => $items,
			'hasItems'   => ! empty( $items ),
			'singlePage' => $total_pages <= 1,
			'hasPrev'    => false,
			'hasNext'    => $total_pages > 1,
		);

		if ( $allow_submissions ) {
			// Submission/auth state is resolved client-side from the stored token
			// (see the view module's initAuth callback); seed neutral defaults.
			$context += array(
				'isAuthenticated' => false,
				'submitting'      => false,
				'submitted'       => false,
				'authError'       => '',
				'submitError'     => '',
				'loginUsername'   => '',
				'loginPassword'   => '',
				'newTitle'        => '',
				'newContent'      => '',
			);
		}

		$context_attr = function_exists( 'wp_interactivity_data_wp_context' )
			? wp_interactivity_data_wp_context( $context )
			: "data-wp-context='" . esc_attr( (string) wp_json_encode( $context ) ) . "'";

		$sort_options = array(
			'date'  => __( 'Newest', 'signalboard' ),
			'votes' => __( 'Most votes', 'signalboard' ),
			'title' => __( 'Title', 'signalboard' ),
		);

		ob_start();
		?>
		<div
			class="signalboard-board"
			data-wp-interactive="signalboard/board"
			<?php echo $context_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped by core. ?>
			data-wp-class--is-loading="context.loading"
		>
			<div class="signalboard-board__controls">
				<label>
					<?php esc_html_e( 'Status', 'signalboard' ); ?>
					<select class="signalboard-board__filter" data-sb-filter="status" data-wp-on--change="actions.setStatus">
						<option value=""><?php esc_html_e( 'All statuses', 'signalboard' ); ?></option>
						<?php foreach ( $this->statuses() as $slug => $name ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $name ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<?php esc_html_e( 'Sort', 'signalboard' ); ?>
					<select class="signalboard-board__filter" data-sb-filter="sort" data-wp-on--change="actions.setSort">
						<?php foreach ( $sort_options as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $sort, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</div>

			<ul class="signalboard-board__list">
				<template data-wp-each--item="context.items" data-wp-each-key="context.item.id">
					<li class="signalboard-board__item">
						<button
							type="button"
							class="signalboard-board__vote"
							data-wp-on--click="actions.upvote"
							data-wp-bind--aria-pressed="context.item.voted"
						>
							<span class="signalboard-board__votes" data-wp-text="context.item.voteCount"></span>
							<span class="screen-reader-text"><?php esc_html_e( 'Upvote', 'signalboard' ); ?></span>
						</button>
						<span class="signalboard-board__title" data-wp-text="context.item.title"></span>
						<span class="signalboard-board__status" data-wp-text="context.item.statusLabel"></span>
					</li>
				</template>
			</ul>

			<p class="signalboard-board__empty" data-wp-bind--hidden="context.hasItems">
				<?php esc_html_e( 'No requests yet.', 'signalboard' ); ?>
			</p>

			<div class="signalboard-board__pagination" data-wp-bind--hidden="context.singlePage">
				<button type="button" class="signalboard-board__prev" data-sb-page="prev" data-wp-on--click="actions.prevPage" data-wp-bind--disabled="!context.hasPrev">
					<?php esc_html_e( 'Previous', 'signalboard' ); ?>
				</button>
				<span class="signalboard-board__page" data-wp-text="context.page">1</span>
				<button type="button" class="signalboard-board__next" data-sb-page="next" data-wp-on--click="actions.nextPage" data-wp-bind--disabled="!context.hasNext">
					<?php esc_html_e( 'Next', 'signalboard' ); ?>
				</button>
			</div>

			<?php if ( $allow_submissions ) : ?>
			<div class="signalboard-board__submit" data-wp-init="callbacks.initAuth">
				<h3 class="signalboard-board__submit-heading"><?php esc_html_e( 'Submit a request', 'signalboard' ); ?></h3>

				<form class="signalboard-board__login" data-wp-bind--hidden="context.isAuthenticated" data-wp-on--submit="actions.login">
					<p class="signalboard-board__hint"><?php esc_html_e( 'Log in to submit a request.', 'signalboard' ); ?></p>
					<p class="signalboard-board__error" data-wp-bind--hidden="!context.authError" data-wp-text="context.authError"></p>
					<label>
						<?php esc_html_e( 'Username or email', 'signalboard' ); ?>
						<input type="text" autocomplete="username" data-sb-field="loginUsername" data-wp-on--input="actions.updateField" data-wp-bind--value="context.loginUsername" />
					</label>
					<label>
						<?php esc_html_e( 'Password', 'signalboard' ); ?>
						<input type="password" autocomplete="current-password" data-sb-field="loginPassword" data-wp-on--input="actions.updateField" data-wp-bind--value="context.loginPassword" />
					</label>
					<button type="submit" class="signalboard-board__login-submit"><?php esc_html_e( 'Log in', 'signalboard' ); ?></button>
				</form>

				<div class="signalboard-board__authed" data-wp-bind--hidden="!context.isAuthenticated">
					<p class="signalboard-board__confirmation" data-wp-bind--hidden="!context.submitted">
						<?php esc_html_e( 'Thanks! Your request has been submitted and is awaiting review.', 'signalboard' ); ?>
					</p>
					<form class="signalboard-board__form" data-wp-bind--hidden="context.submitted" data-wp-on--submit="actions.submit">
						<p class="signalboard-board__error" data-wp-bind--hidden="!context.submitError" data-wp-text="context.submitError"></p>
						<label>
							<?php esc_html_e( 'Title', 'signalboard' ); ?>
							<input type="text" required data-sb-field="newTitle" data-wp-on--input="actions.updateField" data-wp-bind--value="context.newTitle" />
						</label>
						<label>
							<?php esc_html_e( 'Details', 'signalboard' ); ?>
							<textarea data-sb-field="newContent" data-wp-on--input="actions.updateField" data-wp-bind--value="context.newContent"></textarea>
						</label>
						<div class="signalboard-board__form-actions">
							<button type="submit" class="signalboard-board__form-submit" data-wp-bind--disabled="context.submitting"><?php esc_html_e( 'Submit request', 'signalboard' ); ?></button>
							<button type="button" class="signalboard-board__logout" data-wp-on--click="actions.logout"><?php esc_html_e( 'Log out', 'signalboard' ); ?></button>
						</div>
					</form>
				</div>
			</div>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Return status terms as slug => name for the filter control.
	 *
	 * @return array<string, string>
	 */
	private function statuses(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => RequestPostType::TAX_STATUS,
				'hide_empty' => false,
			)
		);

		$out = array();
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$out[ $term->slug ] = $term->name;
			}
		}

		return $out;
	}
}

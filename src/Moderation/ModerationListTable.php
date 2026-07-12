<?php
/**
 * Admin moderation queue list table.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Moderation;

use Sagiris\Signalboard\Content\RequestPostType;
use WP_List_Table;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * The site-owner moderation queue, rendered as a native {@see WP_List_Table}.
 *
 * Lists feedback requests across their moderation states (pending / published /
 * draft) with sortable, filterable columns and bulk actions. It is presentation
 * only — every state change is dispatched to {@see ModerationService} via
 * {@see ModerationScreen}, so the capability + nonce rules live in one place.
 */
final class ModerationListTable extends WP_List_Table {

	/**
	 * Rows shown per page.
	 */
	private const PER_PAGE = 20;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'signalboard_request',
				'plural'   => 'signalboard_requests',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Column definitions.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'cb'     => '<input type="checkbox" />',
			'title'  => __( 'Request', 'signalboard' ),
			'status' => __( 'Status', 'signalboard' ),
			'board'  => __( 'Board', 'signalboard' ),
			'votes'  => __( 'Votes', 'signalboard' ),
			'date'   => __( 'Date', 'signalboard' ),
		);
	}

	/**
	 * Sortable columns, mapped to their query orderby key.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function get_sortable_columns(): array {
		return array(
			'title'  => array( 'title', false ),
			'status' => array( 'status', false ),
			'board'  => array( 'board', false ),
			'votes'  => array( 'votes', false ),
			'date'   => array( 'date', true ),
		);
	}

	/**
	 * Bulk actions offered above/below the table.
	 *
	 * @return array<string, string>
	 */
	public function get_bulk_actions(): array {
		return array(
			'approve' => __( 'Approve', 'signalboard' ),
			'reject'  => __( 'Reject', 'signalboard' ),
			'trash'   => __( 'Trash', 'signalboard' ),
		);
	}

	/**
	 * Query the requests and set up columns + pagination.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'title' );

		$paged = $this->get_pagenum();

		$args = array(
			'post_type'      => RequestPostType::POST_TYPE,
			'post_status'    => array( 'pending', 'publish', 'draft' ),
			'posts_per_page' => self::PER_PAGE,
			'paged'          => $paged,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		$this->apply_request_args( $args );

		$query = new WP_Query( $args );

		$this->items = $query->posts;

		$this->set_pagination_args(
			array(
				'total_items' => (int) $query->found_posts,
				'per_page'    => self::PER_PAGE,
				'total_pages' => (int) $query->max_num_pages,
			)
		);
	}

	/**
	 * Fold sort + filter parameters from the request into the query args.
	 *
	 * These are read-only list controls (a GET query), so no nonce is required;
	 * every value is sanitised before use.
	 *
	 * @param array<string, mixed> $args Query args, passed by reference.
	 * @return void
	 */
	private function apply_request_args( array &$args ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list sorting/filtering.
		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'date';
		$order   = isset( $_REQUEST['order'] ) ? strtoupper( sanitize_key( wp_unslash( $_REQUEST['order'] ) ) ) : 'DESC';
		$status  = isset( $_REQUEST['status'] ) ? sanitize_title( wp_unslash( $_REQUEST['status'] ) ) : '';
		$board   = isset( $_REQUEST['board'] ) ? sanitize_title( wp_unslash( $_REQUEST['board'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$args['order'] = in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : 'DESC';

		switch ( $orderby ) {
			case 'votes':
				$args['orderby']  = 'meta_value_num';
				$args['meta_key'] = RequestPostType::META_VOTE_COUNT; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				break;
			case 'title':
				$args['orderby'] = 'title';
				break;
			case 'status':
			case 'board':
			case 'date':
			default:
				$args['orderby'] = 'date';
				break;
		}

		$tax_query = array();

		if ( '' !== $status ) {
			$tax_query[] = array(
				'taxonomy' => RequestPostType::TAX_STATUS,
				'field'    => 'slug',
				'terms'    => $status,
			);
		}

		if ( '' !== $board ) {
			$tax_query[] = array(
				'taxonomy' => RequestPostType::TAX_BOARD,
				'field'    => 'slug',
				'terms'    => $board,
			);
		}

		if ( ! empty( $tax_query ) ) {
			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}
	}

	/**
	 * Message shown when the queue is empty.
	 *
	 * @return void
	 */
	public function no_items(): void {
		esc_html_e( 'No feedback requests to moderate.', 'signalboard' );
	}

	/**
	 * Row checkbox.
	 *
	 * @param WP_Post $item Current request.
	 * @return string
	 */
	public function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="request[]" value="%d" />', (int) $item->ID );
	}

	/**
	 * Title column with moderation row actions.
	 *
	 * @param WP_Post $item Current request.
	 * @return string
	 */
	public function column_title( $item ): string {
		$title = '<strong>' . esc_html( get_the_title( $item ) ) . '</strong>';

		$base = array(
			'page'     => ModerationScreen::MENU_SLUG,
			'request'  => (int) $item->ID,
			'_wpnonce' => wp_create_nonce( ModerationScreen::NONCE_ACTION ),
		);

		$actions = array(
			'approve' => $this->row_action_link( $base, 'approve', __( 'Approve', 'signalboard' ) ),
			'reject'  => $this->row_action_link( $base, 'reject', __( 'Reject', 'signalboard' ) ),
			'trash'   => $this->row_action_link( $base, 'trash', __( 'Trash', 'signalboard' ) ),
		);

		return $title . $this->row_actions( $actions );
	}

	/**
	 * Build a single row-action anchor.
	 *
	 * @param array<string, mixed> $base   Shared query args.
	 * @param string               $action Moderation action slug.
	 * @param string               $label  Link label.
	 * @return string
	 */
	private function row_action_link( array $base, string $action, string $label ): string {
		$url = add_query_arg( array( 'sb_action' => $action ) + $base, admin_url( 'edit.php?post_type=' . RequestPostType::POST_TYPE ) );

		return sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $label ) );
	}

	/**
	 * Status column — current roadmap status plus a quick-change control.
	 *
	 * @param WP_Post $item Current request.
	 * @return string
	 */
	public function column_status( $item ): string {
		$current = $this->first_term_slug( (int) $item->ID, RequestPostType::TAX_STATUS );

		$terms = get_terms(
			array(
				'taxonomy'   => RequestPostType::TAX_STATUS,
				'hide_empty' => false,
			)
		);

		if ( ! is_array( $terms ) || empty( $terms ) ) {
			return $current ? esc_html( $current ) : '&mdash;';
		}

		$options = '';
		foreach ( $terms as $term ) {
			$options .= sprintf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $term->slug ),
				selected( $current, $term->slug, false ),
				esc_html( $term->name )
			);
		}

		return sprintf(
			'<select name="status_slug">%1$s</select>
			<input type="hidden" name="sb_action" value="set_status" />
			<input type="hidden" name="request" value="%2$d" />
			%3$s
			<button type="submit" class="button button-small">%4$s</button>',
			$options, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Options escaped above.
			(int) $item->ID,
			wp_nonce_field( ModerationScreen::NONCE_ACTION, '_wpnonce', true, false ),
			esc_html__( 'Set', 'signalboard' )
		);
	}

	/**
	 * Board column.
	 *
	 * @param WP_Post $item Current request.
	 * @return string
	 */
	public function column_board( $item ): string {
		$slug = $this->first_term_slug( (int) $item->ID, RequestPostType::TAX_BOARD );

		return $slug ? esc_html( $slug ) : '&mdash;';
	}

	/**
	 * Votes column.
	 *
	 * @param WP_Post $item Current request.
	 * @return string
	 */
	public function column_votes( $item ): string {
		return esc_html( (string) (int) get_post_meta( (int) $item->ID, RequestPostType::META_VOTE_COUNT, true ) );
	}

	/**
	 * Date column.
	 *
	 * @param WP_Post $item Current request.
	 * @return string
	 */
	public function column_date( $item ): string {
		return esc_html( get_the_date( '', $item ) );
	}

	/**
	 * Fallback column renderer (post status badge etc.).
	 *
	 * @param WP_Post $item        Current request.
	 * @param string  $column_name Column key.
	 * @return string
	 */
	public function column_default( $item, $column_name ): string {
		return '';
	}

	/**
	 * First assigned term slug for a taxonomy.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return string
	 */
	private function first_term_slug( int $post_id, string $taxonomy ): string {
		$terms = get_the_terms( $post_id, $taxonomy );

		if ( is_array( $terms ) && ! empty( $terms ) ) {
			return (string) $terms[0]->slug;
		}

		return '';
	}
}

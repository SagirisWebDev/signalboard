<?php
/**
 * Board block and shortcode registration.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Block;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the signalboard/board block and the [signalboard] shortcode.
 *
 * The shortcode delegates to the block's render pipeline so both surfaces share
 * one renderer, one view module, and one set of styles.
 */
final class BoardBlock {

	/**
	 * Register the block and shortcode.
	 *
	 * @return void
	 */
	public function register(): void {
		register_block_type( SIGNALBOARD_PLUGIN_DIR . 'build/board' );
		add_shortcode( 'signalboard', array( $this, 'render_shortcode' ) );
	}

	/**
	 * Render the [signalboard board="" sort=""] shortcode via the block pipeline.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 * @return string Rendered board HTML.
	 */
	public function render_shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'board' => '',
				'sort'  => 'date',
			),
			$atts,
			'signalboard'
		);

		return render_block(
			array(
				'blockName'    => 'signalboard/board',
				'attrs'        => array(
					'board'       => (string) $atts['board'],
					'defaultSort' => (string) $atts['sort'],
				),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
	}
}

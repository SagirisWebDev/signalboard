<?php
/**
 * Server render for the signalboard/board block.
 *
 * @package Sagiris\Signalboard
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block default content.
 * @var WP_Block $block      Block instance.
 */

defined( 'ABSPATH' ) || exit;

$signalboard_wrapper = get_block_wrapper_attributes();
$signalboard_board   = new \Sagiris\Signalboard\Block\BoardRenderer();

printf(
	'<div %1$s>%2$s</div>',
	$signalboard_wrapper, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_block_wrapper_attributes().
	$signalboard_board->render( $attributes ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Contains vetted markup + Interactivity directives.
);

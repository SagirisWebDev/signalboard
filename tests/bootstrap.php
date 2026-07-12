<?php
/**
 * PHPUnit bootstrap for the WordPress integration test suite.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

$signalboard_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( false === $signalboard_tests_dir ) {
	$signalboard_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// Point the WordPress test suite at the PHPUnit Polyfills shipped via Composer.
if ( false === getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	putenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH=' . dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
}

$signalboard_functions = $signalboard_tests_dir . '/includes/functions.php';

if ( ! is_readable( $signalboard_functions ) ) {
	echo "Could not find {$signalboard_functions}, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once $signalboard_functions;

/**
 * Load the plugin under test.
 *
 * @return void
 */
function signalboard_manually_load_plugin(): void {
	require dirname( __DIR__ ) . '/signalboard.php';
}
tests_add_filter( 'muplugins_loaded', 'signalboard_manually_load_plugin' );

require $signalboard_tests_dir . '/includes/bootstrap.php';

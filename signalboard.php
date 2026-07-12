<?php
/**
 * Plugin Name:       Signalboard
 * Plugin URI:        https://github.com/SagirisWebDev/signalboard
 * Description:        Headless-ready feature-voting, feedback & roadmap board, exposed over the WP REST API and WPGraphQL.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Sagiris
 * Author URI:        https://sagirisdev.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       signalboard
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard;

defined( 'ABSPATH' ) || exit;

define( 'SIGNALBOARD_VERSION', '0.1.0' );
define( 'SIGNALBOARD_PLUGIN_FILE', __FILE__ );
define( 'SIGNALBOARD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

$signalboard_autoload = __DIR__ . '/vendor/autoload.php';
if ( is_readable( $signalboard_autoload ) ) {
	require $signalboard_autoload;
}

/**
 * Retrieve the shared plugin instance.
 *
 * @return Plugin
 */
function signalboard(): Plugin {
	static $instance = null;

	if ( null === $instance ) {
		$instance = new Plugin();
	}

	return $instance;
}

signalboard()->register();

register_activation_hook( __FILE__, array( signalboard(), 'activate' ) );
register_deactivation_hook( __FILE__, array( signalboard(), 'deactivate' ) );

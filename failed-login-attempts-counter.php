<?php
/**
 * Plugin Name:       Failed Login Attempts Counter
 * Description:       Counts successful and failed login attempts made through wp-login.php and shows them in a dashboard widget with a detail page.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Arjan Vlek
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       failed-login-attempts-counter
 *
 * @package FailedLoginAttemptsCounter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FLAC_VERSION', '1.2.0' );
define( 'FLAC_PLUGIN_FILE', __FILE__ );
define( 'FLAC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FLAC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once FLAC_PLUGIN_DIR . 'includes/class-flac-repository.php';
require_once FLAC_PLUGIN_DIR . 'includes/class-flac-listener.php';
require_once FLAC_PLUGIN_DIR . 'includes/class-flac-dashboard-widget.php';
require_once FLAC_PLUGIN_DIR . 'includes/class-flac-list-table.php';
require_once FLAC_PLUGIN_DIR . 'includes/class-flac-actions.php';
require_once FLAC_PLUGIN_DIR . 'includes/class-flac-admin-page.php';
require_once FLAC_PLUGIN_DIR . 'includes/class-flac-plugin.php';

register_activation_hook( __FILE__, array( 'FLAC_Plugin', 'activate' ) );

add_action( 'plugins_loaded', array( 'FLAC_Plugin', 'boot' ) );

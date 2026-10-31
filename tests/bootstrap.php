<?php
/**
 * PHPUnit bootstrap: loads the WordPress test suite and this plugin.
 *
 * @package FailedLoginAttemptsCounter
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// Composer autoloader, which also provides yoast/phpunit-polyfills.
if ( file_exists( dirname( __DIR__ ) . '/vendor/autoload.php' ) ) {
	require_once dirname( __DIR__ ) . '/vendor/autoload.php';
}

$_phpunit_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( false !== $_phpunit_polyfills_path ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_phpunit_polyfills_path );
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php. Run bin/install-wp-tests.sh first." . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

// Treat the directory that holds this plugin as the plugins directory, so
// plugin_basename() and load_plugin_textdomain() resolve paths like they do
// in a real install.
if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', dirname( dirname( __DIR__ ) ) );
}

require_once "{$_tests_dir}/includes/functions.php";

/**
 * Loads the plugin before WordPress boots.
 */
function _flac_manually_load_plugin() {
	require dirname( __DIR__ ) . '/failed-login-attempts-counter.php';
}
tests_add_filter( 'muplugins_loaded', '_flac_manually_load_plugin' );

require "{$_tests_dir}/includes/bootstrap.php";

// Admin functions used by the dashboard widget and admin page tests.
require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once ABSPATH . 'wp-admin/includes/dashboard.php';

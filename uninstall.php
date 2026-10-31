<?php
/**
 * Removes the plugin table and options when the plugin is deleted.
 *
 * @package FailedLoginAttemptsCounter
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-flac-repository.php';

FLAC_Repository::uninstall();
